<?php
// ============================================================
//  billing.php  –  Generate Bill, Print Bill, Payment
// ============================================================
require_once 'config.php';
requireLogin();
$db  = getDB();
$msg = '';
$err = '';

define('ESEWA_PRODUCT_CODE', 'EPAYTEST');
define('ESEWA_SECRET_KEY', '8gBm/:&EnhH.1/q');
define('ESEWA_FORM_URL', 'https://rc-epay.esewa.com.np/api/epay/main/v2/form');

function appBaseUrl(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  return $scheme . '://' . $_SERVER['HTTP_HOST'];
}

function appBasePath(): string {
  $path = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
  return ($path === '.' || $path === '/') ? '' : $path;
}

function formatAmount(float $amount): string {
  return number_format($amount, 2, '.', '');
}

function generateEsewaSignature(string $totalAmount, string $transactionUuid, string $productCode): string {
  $message = "total_amount={$totalAmount},transaction_uuid={$transactionUuid},product_code={$productCode}";
  return base64_encode(hash_hmac('sha256', $message, ESEWA_SECRET_KEY, true));
}

function verifyEsewaResponse(array $payload): bool {
  if (empty($payload['signed_field_names']) || empty($payload['signature'])) {
    return false;
  }

  $fields = array_map('trim', explode(',', (string)$payload['signed_field_names']));
  $parts = [];
  foreach ($fields as $field) {
    if (!array_key_exists($field, $payload)) {
      return false;
    }
    $parts[] = $field . '=' . $payload[$field];
  }

  $message = implode(',', $parts);
  $expected = base64_encode(hash_hmac('sha256', $message, ESEWA_SECRET_KEY, true));
  return hash_equals($expected, (string)$payload['signature']);
}

function finalizeOrderPayment(mysqli $db, int $orderId, string $paymentMethod, float $taxPercent = 13.00): bool {
  $res = $db->query("SELECT SUM(quantity * unit_price) AS sub FROM order_items WHERE order_id={$orderId}");
  $sub = (float)$res->fetch_assoc()['sub'];
  if ($sub <= 0) {
    return false;
  }

  $tax = round($sub * $taxPercent / 100, 2);
  $total = $sub + $tax;

  $chk = $db->prepare('SELECT id FROM bills WHERE order_id=?');
  $chk->bind_param('i', $orderId);
  $chk->execute();
  $chk->store_result();

  if ($chk->num_rows === 0) {
    $ins = $db->prepare('INSERT INTO bills (order_id, subtotal, tax_percent, tax_amount, total_amount, payment_method)
               VALUES (?,?,?,?,?,?)');
    $ins->bind_param('idddds', $orderId, $sub, $taxPercent, $tax, $total, $paymentMethod);
    $ins->execute();
    $ins->close();
  }
  $chk->close();

  $db->query("UPDATE orders SET status='paid' WHERE id={$orderId}");
  $db->query("UPDATE restaurant_tables t
        JOIN orders o ON o.table_id=t.id
        SET t.status='available' WHERE o.id={$orderId}");
  return true;
}

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['esewa_status'], $_GET['order_id'])) {
  $order_id = (int)$_GET['order_id'];
  $pending = $_SESSION['esewa_pending'][$order_id] ?? null;

  if (!$pending) {
    header('Location: billing.php?order_id=' . $order_id . '&err=' . urlencode('No pending eSewa payment found for this order.'));
    exit;
  }

  if ($_GET['esewa_status'] === 'success') {
    $encodedResponse = (string)($_GET['data'] ?? '');
    $encodedResponse = str_replace(' ', '+', $encodedResponse);
    $decodedResponse = base64_decode($encodedResponse, true);
    $payload = $decodedResponse ? json_decode($decodedResponse, true) : null;

    $isVerified = is_array($payload) && verifyEsewaResponse($payload);
    $isComplete = strtoupper((string)($payload['status'] ?? '')) === 'COMPLETE';
    $isTxnMatch = (string)($payload['transaction_uuid'] ?? '') === (string)$pending['transaction_uuid'];
    $isAmountMatch = isset($payload['total_amount']) && abs((float)$payload['total_amount'] - (float)$pending['total_amount']) < 0.01;
    $isProductCodeMatch = (string)($payload['product_code'] ?? '') === ESEWA_PRODUCT_CODE;

    if ($isVerified && $isComplete && $isTxnMatch && $isAmountMatch && $isProductCodeMatch && finalizeOrderPayment($db, $order_id, 'esewa')) {
      unset($_SESSION['esewa_pending'][$order_id]);
      header('Location: billing.php?print=' . $order_id);
      exit;
    }

    header('Location: billing.php?order_id=' . $order_id . '&esewa=1&err=' . urlencode('eSewa verification failed. Please retry payment.'));
    exit;
  }

  header('Location: billing.php?order_id=' . $order_id . '&esewa=1&err=' . urlencode('eSewa payment was cancelled or failed.'));
  exit;
}

// ── FINALIZE PAYMENT ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finalize'])) {
    $order_id      = (int)$_POST['order_id'];
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $allowed_payment_methods = ['cash', 'card', 'esewa'];

  if (!in_array($payment_method, $allowed_payment_methods, true)) {
    $err = 'Invalid payment method selected.';
  } elseif ($payment_method === 'esewa') {
    $tax_percent = 13.00;
    $res = $db->query("SELECT SUM(quantity * unit_price) AS sub FROM order_items WHERE order_id={$order_id}");
    $sub = (float)$res->fetch_assoc()['sub'];

    if ($sub <= 0) {
      $err = 'Cannot initiate eSewa payment because this order has no billable items.';
    } else {
      $tax = round($sub * $tax_percent / 100, 2);
      $total = $sub + $tax;
      $transaction_uuid = 'ORD-' . $order_id . '-' . date('YmdHis');

      $_SESSION['esewa_pending'][$order_id] = [
        'transaction_uuid' => $transaction_uuid,
        'total_amount' => formatAmount($total),
        'created_at' => time(),
      ];

      header('Location: billing.php?order_id=' . $order_id . '&esewa=1');
      exit;
    }
  } elseif (finalizeOrderPayment($db, $order_id, $payment_method)) {
    header('Location: billing.php?print=' . $order_id);
    exit;
  } else {
    $err = 'Unable to finalize payment for this order.';
  }
}

// ── LOAD ORDER FOR BILLING ───────────────────────────────
$order    = null;
$items    = [];
$bill     = null;
$order_id = (int)($_GET['order_id'] ?? $_GET['print'] ?? 0);
$print_mode = isset($_GET['print']);

if ($order_id > 0) {
    $stmt = $db->prepare("
        SELECT o.*, t.table_name, w.name AS waiter_name
        FROM orders o
        JOIN restaurant_tables t ON t.id=o.table_id
        LEFT JOIN waiters w ON w.id=o.waiter_id
        WHERE o.id=?");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($order) {
        $items = $db->query("
            SELECT oi.quantity, oi.unit_price, m.item_name
            FROM order_items oi JOIN menu_items m ON m.id=oi.menu_item_id
            WHERE oi.order_id=$order_id
        ")->fetch_all(MYSQLI_ASSOC);

        $bill_res = $db->query("SELECT * FROM bills WHERE order_id=$order_id");
        if ($bill_res->num_rows > 0) $bill = $bill_res->fetch_assoc();
    }
}

// Subtotal calc (for form display before payment)
$subtotal = 0;
foreach ($items as $it) $subtotal += $it['quantity'] * $it['unit_price'];
$tax_amount = round($subtotal * 0.13, 2);
$grand_total = $subtotal + $tax_amount;

// Fetch open orders list for sidebar selection
$open_orders = $db->query("
    SELECT o.id, t.table_name, SUM(oi.quantity*oi.unit_price) AS total
    FROM orders o
    JOIN restaurant_tables t ON t.id=o.table_id
    LEFT JOIN order_items oi ON oi.order_id=o.id
    WHERE o.status='open'
    GROUP BY o.id
")->fetch_all(MYSQLI_ASSOC);

if (isset($_GET['msg'])) {
  $msg = (string)$_GET['msg'];
}
if (isset($_GET['err'])) {
  $err = (string)$_GET['err'];
}

$esewa_mode = isset($_GET['esewa']) && $_GET['esewa'] === '1';
$esewa_pending = ($order_id > 0) ? ($_SESSION['esewa_pending'][$order_id] ?? null) : null;
$esewa_form_data = null;

if ($order && !$bill && $esewa_mode && $esewa_pending) {
  $callback_base = appBaseUrl() . appBasePath() . '/billing.php';
  $success_url = $callback_base . '?order_id=' . $order_id . '&esewa_status=success';
  $failure_url = $callback_base . '?order_id=' . $order_id . '&esewa_status=failure';

  $esewa_form_data = [
    'amount' => $esewa_pending['total_amount'],
    'tax_amount' => '0',
    'total_amount' => $esewa_pending['total_amount'],
    'transaction_uuid' => $esewa_pending['transaction_uuid'],
    'product_code' => ESEWA_PRODUCT_CODE,
    'product_service_charge' => '0',
    'product_delivery_charge' => '0',
    'success_url' => $success_url,
    'failure_url' => $failure_url,
    'signed_field_names' => 'total_amount,transaction_uuid,product_code',
    'signature' => generateEsewaSignature($esewa_pending['total_amount'], $esewa_pending['transaction_uuid'], ESEWA_PRODUCT_CODE),
  ];
}

$db->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <title>Billing – NepDine</title>
  <link rel="stylesheet" href="style.css"/>
  <style>
    @media print {
      .sidebar, .no-print { display: none !important; }
      .main { margin: 0 !important; padding: 1rem !important; }
      .bill-receipt { box-shadow: none; border: 1px solid #ccc; }
    }
  </style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="main">
  <h1>Billing</h1>

  <?php if ($msg): ?><div class="alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

  <!-- ── Select Open Order ── -->
  <?php if (!$order_id || !$order): ?>
  <div class="form-card no-print">
    <h3>Select Order to Bill</h3>
    <?php if (empty($open_orders)): ?>
      <p class="no-data">No open orders. <a href="orders.php">Place an order first.</a></p>
    <?php else: ?>
    <form method="GET" action="billing.php" class="inline-form">
      <select name="order_id" class="input-field" required>
        <option value="">-- Select Order --</option>
        <?php foreach ($open_orders as $oo): ?>
          <option value="<?= $oo['id'] ?>">
            Order #<?= $oo['id'] ?> – <?= htmlspecialchars($oo['table_name']) ?>
            (Rs. <?= number_format($oo['total'], 2) ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn-primary">Load Order</button>
    </form>
    <?php endif; ?>
  </div>

  <?php else: ?>

  <!-- ── Bill Receipt ── -->
  <div class="bill-receipt" id="billReceipt">
    <div class="receipt-header">
      <h2>🍽 NEPDINE Restaurant</h2>
      <p>Smart Dining, Great Taste</p>
      <hr/>
    </div>

    <div class="receipt-meta">
      <div><strong>Bill #:</strong> <?= $bill ? $bill['id'] : 'PREVIEW' ?></div>
      <div><strong>Order #:</strong> <?= $order['id'] ?></div>
      <div><strong>Table:</strong> <?= htmlspecialchars($order['table_name']) ?></div>
      <div><strong>Waiter:</strong> <?= htmlspecialchars($order['waiter_name'] ?: 'N/A') ?></div>
      <div><strong>Date:</strong> <?= date('d M Y, h:i A') ?></div>
    </div>
    <hr/>

    <table class="receipt-table">
      <thead>
        <tr><th>Item</th><th>Qty</th><th>Price</th><th>Amount</th></tr>
      </thead>
      <tbody>
        <?php foreach ($items as $it): $amt = $it['quantity'] * $it['unit_price']; ?>
        <tr>
          <td><?= htmlspecialchars($it['item_name']) ?></td>
          <td><?= $it['quantity'] ?></td>
          <td>Rs. <?= number_format($it['unit_price'], 2) ?></td>
          <td>Rs. <?= number_format($amt, 2) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <hr/>

    <div class="receipt-totals">
      <div><span>Subtotal:</span><span>Rs. <?= number_format($bill ? $bill['subtotal'] : $subtotal, 2) ?></span></div>
      <div><span>VAT (13%):</span><span>Rs. <?= number_format($bill ? $bill['tax_amount'] : $tax_amount, 2) ?></span></div>
      <div class="grand-total"><span>TOTAL:</span><span>Rs. <?= number_format($bill ? $bill['total_amount'] : $grand_total, 2) ?></span></div>
      <?php if ($bill): ?>
      <div><span>Payment:</span><span><?= ucfirst($bill['payment_method']) ?></span></div>
      <div><span>Status:</span><span class="badge badge-green">PAID</span></div>
      <?php endif; ?>
    </div>

    <div class="receipt-footer">
      <p>Thank you for dining at NepDine!</p>
      <p>Please visit again 😊</p>
    </div>
  </div>

  <!-- ── Payment Form (only if not yet paid) ── -->
  <?php if (!$bill): ?>
  <div class="form-card no-print" style="margin-top:1.5rem;">
    <h3>Finalize Payment</h3>
    <p style="margin-top:0;color:var(--muted);font-size:13px;">Selecting eSewa opens a QR + gateway payment step before this order is marked paid.</p>
    <form method="POST" action="billing.php" class="inline-form">
      <input type="hidden" name="order_id" value="<?= $order_id ?>"/>
      <select name="payment_method" class="input-field" required>
        <option value="cash">💵 Cash</option>
        <option value="card">💳 Card</option>
        <option value="esewa">📱 eSewa</option>
      </select>
      <button type="submit" name="finalize" class="btn-primary">Mark as Paid &amp; Generate Bill</button>
    </form>
  </div>

  <?php if ($esewa_mode): ?>
  <div class="form-card no-print esewa-pay-card">
    <h3>Pay with eSewa</h3>
    <?php if ($esewa_form_data): ?>
      <div class="esewa-pay-wrap">
        <div class="esewa-qr-block">
          <img src="qr-code.png" alt="eSewa QR Code" class="esewa-qr"/>
          <p>Scan this QR using eSewa app, or continue via gateway button.</p>
        </div>
        <div class="esewa-info-block">
          <div><strong>Order:</strong> #<?= $order_id ?></div>
          <div><strong>Amount:</strong> Rs. <?= htmlspecialchars($esewa_form_data['total_amount']) ?></div>
          <div><strong>Transaction UUID:</strong> <?= htmlspecialchars($esewa_form_data['transaction_uuid']) ?></div>
          <form method="POST" action="<?= ESEWA_FORM_URL ?>" style="margin-top:12px;">
            <?php foreach ($esewa_form_data as $key => $val): ?>
              <input type="hidden" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars($val) ?>"/>
            <?php endforeach; ?>
            <button type="submit" class="btn-primary">Proceed to eSewa Gateway</button>
            <a href="billing.php?order_id=<?= $order_id ?>" class="btn-secondary" style="margin-left:8px;">Cancel eSewa</a>
          </form>
        </div>
      </div>
    <?php else: ?>
      <p class="alert-error" style="margin:0;">No pending eSewa transaction found. Please choose eSewa from Finalize Payment again.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <!-- ── Print Button ── -->
  <div class="no-print" style="margin-top:1rem;">
    <button onclick="window.print()" class="btn-secondary">🖨 Print Bill</button>
    <a href="billing.php" class="btn-secondary">← Back to Billing</a>
    <?php if ($bill): ?>
    <a href="orders.php" class="btn-primary">View Orders</a>
    <?php endif; ?>
  </div>

  <?php endif; ?>
</div>
</body>
</html>
