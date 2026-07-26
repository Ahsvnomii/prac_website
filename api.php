<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax'
  ]);
  session_start();
}

require_once __DIR__ . '/config.php';

function out(array $data, int $code = 200): void {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function body(): array {
  $raw = file_get_contents('php://input');
  $json = json_decode($raw ?: '', true);
  return is_array($json) ? $json : $_POST;
}

function app_debug(): bool {
  return !empty($GLOBALS['debug']);
}

function clean_str($value, int $max = 1000): string {
  $value = trim((string)$value);
  $value = strip_tags($value);
  $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
  if (function_exists('mb_substr')) return mb_substr($value, 0, $max, 'UTF-8');
  return substr($value, 0, $max);
}

function clean_money($value): float {
  $n = (float)$value;
  return max(0, round($n, 2));
}

function clean_int($value, int $min = 1, int $max = 99): int {
  $n = (int)$value;
  return max($min, min($max, $n));
}

function delivery_charge(string $area, array $items): float {
  if ($area === 'inside_dhaka') return 80.00;
  foreach ($items as $item) {
    if (in_array(($item['category'] ?? ''), ['terrariums', 'paludariums'], true)) return 150.00;
  }
  return 130.00;
}

function clean_emoji($value): string {
  // Keep DB setup safe on hosts that were created with a non-utf8mb4 default charset.
  // The storefront already falls back to a plant icon when this is blank.
  $value = clean_str($value, 16);
  if (preg_match('/[\x{10000}-\x{10FFFF}]/u', $value)) return '';
  return $value;
}

function clean_image_url($value): string {
  $value = trim((string)$value);
  if ($value === '') return '';
  if (preg_match('/^uploads\/[A-Za-z0-9._-]+$/', $value)) return $value;
  if (filter_var($value, FILTER_VALIDATE_URL)) {
    $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
    if (in_array($scheme, ['http', 'https'], true)) return $value;
  }
  return '';
}

function db(): PDO {
  global $db_host, $db_name, $db_user, $db_pass;
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;
  try {
    $pdo = new PDO(
      "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
      (string)$db_user,
      (string)$db_pass,
      [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
      ]
    );
    return $pdo;
  } catch (Throwable $e) {
    $res = ['ok' => false, 'error' => 'Database connection failed. Check config.php database details.'];
    if (app_debug()) $res['details'] = $e->getMessage();
    out($res, 500);
  }
}

function run_schema(bool $upgrade = false): void {
  static $done = false;
  if ($done) return;
  $done = true;

  $path = __DIR__ . '/schema.sql';
  if (!is_file($path)) out(['ok' => false, 'error' => 'schema.sql is missing. Upload schema.sql to the same folder as api.php.'], 500);

  try {
    db()->exec((string)file_get_contents($path));
  } catch (Throwable $e) {
    $res = ['ok' => false, 'error' => 'Database schema setup failed. Check database permissions and charset.'];
    if (app_debug()) $res['details'] = $e->getMessage();
    out($res, 500);
  }

  if (!$upgrade) return;

  $statements = [
    "ALTER TABLE products CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
    "ALTER TABLE orders CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
    "ALTER TABLE content_settings CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
    "ALTER TABLE products MODIFY emoji VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''",
    "ALTER TABLE orders ADD COLUMN bkash_from VARCHAR(60) NOT NULL DEFAULT '' AFTER pay_label",
    "ALTER TABLE orders ADD COLUMN delivery_area VARCHAR(40) NOT NULL DEFAULT 'inside_dhaka' AFTER note",
    "ALTER TABLE orders ADD COLUMN delivery_charge DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER delivery_area",
    "ALTER TABLE products ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    "ALTER TABLE orders ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    "ALTER TABLE content_settings ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER setting_value"
  ];

  foreach ($statements as $sql) {
    try { db()->exec($sql); } catch (Throwable $e) { /* duplicate/existing/permission errors are non-fatal */ }
  }
}


function ensure_delivery_columns(): void {
  $statements = [
    "ALTER TABLE orders ADD COLUMN delivery_area VARCHAR(40) NOT NULL DEFAULT 'inside_dhaka' AFTER note",
    "ALTER TABLE orders ADD COLUMN delivery_charge DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER delivery_area"
  ];
  foreach ($statements as $sql) {
    try { db()->exec($sql); } catch (Throwable $e) { /* already exists or insufficient permission; create_order will report real DB errors if critical */ }
  }
}

function is_admin(): bool { return !empty($_SESSION['tv_admin']); }
function require_admin(): void { if (!is_admin()) out(['ok' => false, 'error' => 'Not logged in'], 401); }

function default_products(): array {
  return [
    ['p1','Peacock Moss','plants',280,120,'Moss','','Dense, velvety moss perfect for terrarium floors and backgrounds.','Peacock Moss is a stunning variety known for its feather-like appearance. It thrives in high humidity and attaches beautifully to driftwood and stones.',['Ideal for closed terrariums','Thrives in high humidity','Easy to propagate'],'cod'],
    ['p2','Miniature Fern','plants',350,150,'Fern','','Compact fern with delicate fronds, ideal for humid terrariums.','A miniature fern selected for compact growth and excellent terrarium performance.',['Compact growth habit','High humidity lover','Bright indirect light'],'cod'],
    ['p3','Miniature Orchid','plants',650,300,'Orchid','','Tiny orchid, rare and stunning in closed setups.','A rare collector micro-orchid for humid terrariums.',['Micro-orchid variety','Rare collector plant','Thrives in closed terrariums'],'fifty'],
    ['t1','Jungle Cube (20cm)','terrariums',1800,850,'Closed','','Compact closed terrarium with tropical plants, moss, and driftwood.','A complete 20cm glass cube terrarium with drainage layer, substrate, plants, moss, and hardscape.',['Self-sustaining closed system','Drainage layer included','Ready to display'],'cod'],
    ['t3','Rainforest Box (30cm)','terrariums',3200,1400,'Bioactive','','Bioactive vivarium with drainage layer, live moss, and ferns.','A bioactive rainforest style glass enclosure with live planting and drainage.',['Bioactive substrate','Full drainage layer','Multiple plant species'],'fifty'],
    ['pa1','River Paludarium (40cm)','paludariums',5500,2800,'Complete','','Flowing water section with land area, moss, and ferns.','A complete paludarium with aquatic and terrestrial zones.',['Built-in water pump','Aquatic and terrestrial zones','Multiple plant species'],'fifty'],
    ['o1','ABG Terrarium Mix (5L)','other',650,300,'Substrate','','Professional substrate blend for bioactive terrariums.','A substrate mix for healthy terrarium drainage and bioactive setups.',['5 liter bag','Professional grade','Excellent drainage'],'cod'],
    ['o2','LED Grow Light','other',1200,600,'Lighting','','Full-spectrum plant LED panel, perfect for any enclosure.','A 20W full-spectrum LED grow light for terrariums and indoor plants.',['20W full spectrum','Low heat emission','Suitable for all enclosures'],'cod']
  ];
}

function seed_defaults(): void {
  $pdo = db();
  $count = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
  if ($count > 0) return;

  $stmt = $pdo->prepare('INSERT INTO products (id,name,category,price,cost_price,badge,emoji,short_desc,full_desc,features,images,payment_policy,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
  $i = 0;
  foreach (default_products() as $p) {
    $stmt->execute([$p[0], $p[1], $p[2], $p[3], $p[4], $p[5], $p[6], $p[7], $p[8], json_encode($p[9], JSON_UNESCAPED_UNICODE), json_encode([], JSON_UNESCAPED_UNICODE), $p[10], $i++]);
  }
}

function product_row(array $r): array {
  return [
    'id' => $r['id'],
    'name' => $r['name'],
    'category' => $r['category'],
    'price' => (float)$r['price'],
    'costPrice' => (float)$r['cost_price'],
    'badge' => $r['badge'],
    'emoji' => $r['emoji'],
    'desc' => $r['short_desc'],
    'fullDesc' => $r['full_desc'],
    'features' => json_decode($r['features'] ?: '[]', true) ?: [],
    'images' => json_decode($r['images'] ?: '[]', true) ?: [],
    'paymentPolicy' => $r['payment_policy']
  ];
}

function order_row(array $r): array {
  return [
    'id' => $r['id'],
    'name' => $r['customer_name'],
    'phone' => $r['phone'],
    'address' => $r['address'],
    'district' => $r['district'],
    'note' => $r['note'],
    'deliveryArea' => $r['delivery_area'] ?? 'inside_dhaka',
    'deliveryCharge' => (float)($r['delivery_charge'] ?? 0),
    'payment' => $r['payment'],
    'payLabel' => $r['pay_label'],
    'bkashFrom' => $r['bkash_from'] ?? '',
    'bkashTrx' => $r['bkash_trx'],
    'total' => (float)$r['total'],
    'items' => json_decode($r['items'] ?: '[]', true) ?: [],
    'status' => $r['status'],
    'date' => date('d/m/Y', strtotime($r['created_at'])),
    'month' => $r['order_month'],
    'createdAt' => $r['created_at']
  ];
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($action === 'install') {
  run_schema(true);
  seed_defaults();
  out(['ok' => true, 'message' => 'Database tables created/updated and default products checked. Delete setup.php now.']);
}

run_schema(false);
ensure_delivery_columns();

if ($action === 'login') {
  global $admin_password;
  $d = body();
  if (hash_equals((string)$admin_password, (string)($d['password'] ?? ''))) {
    session_regenerate_id(true);
    $_SESSION['tv_admin'] = true;
    out(['ok' => true]);
  }
  out(['ok' => false, 'error' => 'Wrong password'], 403);
}

if ($action === 'logout') { session_destroy(); out(['ok' => true]); }
if ($action === 'session') { out(['ok' => true, 'admin' => is_admin()]); }

if ($action === 'products') {
  seed_defaults();
  $rows = db()->query('SELECT * FROM products ORDER BY sort_order ASC, created_at DESC')->fetchAll();
  $group = ['plants' => [], 'terrariums' => [], 'paludariums' => [], 'other' => []];
  foreach ($rows as $r) {
    $p = product_row($r);
    if (!isset($group[$p['category']])) $p['category'] = 'other';
    $group[$p['category']][] = $p;
  }
  out(['ok' => true, 'products' => $group]);
}

if ($action === 'content') {
  $rows = db()->query('SELECT setting_key,setting_value FROM content_settings')->fetchAll();
  $c = [];
  foreach ($rows as $r) $c[$r['setting_key']] = $r['setting_value'];
  out(['ok' => true, 'content' => $c]);
}

if ($action === 'orders') {
  require_admin();
  $rows = db()->query('SELECT * FROM orders ORDER BY created_at DESC')->fetchAll();
  out(['ok' => true, 'orders' => array_map('order_row', $rows)]);
}

if ($action === 'stats') {
  require_admin();
  $orders = db()->query('SELECT * FROM orders ORDER BY created_at DESC')->fetchAll();
  $products = db()->query('SELECT COUNT(*) FROM products')->fetchColumn();
  $revenue = 0; $pending = 0;
  foreach ($orders as $o) {
    if ($o['status'] === 'Pending') $pending++;
    if ($o['status'] === 'Delivered') $revenue += (float)$o['total'];
  }
  out(['ok' => true, 'stats' => ['products' => (int)$products, 'orders' => count($orders), 'pending' => $pending, 'revenue' => $revenue]]);
}

if ($action === 'save_product') {
  require_admin();
  $d = body();
  $allowed_categories = ['plants','terrariums','paludariums','other'];
  $allowed_policies = ['cod','fifty'];

  $id = clean_str($d['id'] ?? '', 64);
  if ($id === '') $id = 'p' . time() . '_' . bin2hex(random_bytes(3));
  $name = clean_str($d['name'] ?? '', 255);
  $category = in_array(($d['category'] ?? ''), $allowed_categories, true) ? $d['category'] : 'other';
  $price = clean_money($d['price'] ?? 0);
  if ($name === '' || $price <= 0) out(['ok' => false, 'error' => 'Product name and price are required.'], 422);

  $features = [];
  foreach (($d['features'] ?? []) as $feature) {
    $f = clean_str($feature, 180);
    if ($f !== '') $features[] = $f;
  }
  $images = [];
  foreach (($d['images'] ?? []) as $img) {
    $u = clean_image_url($img);
    if ($u !== '') $images[] = $u;
  }
  $payment_policy = in_array(($d['paymentPolicy'] ?? 'cod'), $allowed_policies, true) ? $d['paymentPolicy'] : 'cod';

  $sql = 'INSERT INTO products (id,name,category,price,cost_price,badge,emoji,short_desc,full_desc,features,images,payment_policy,sort_order)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
          ON DUPLICATE KEY UPDATE name=VALUES(name), category=VALUES(category), price=VALUES(price), cost_price=VALUES(cost_price), badge=VALUES(badge), emoji=VALUES(emoji), short_desc=VALUES(short_desc), full_desc=VALUES(full_desc), features=VALUES(features), images=VALUES(images), payment_policy=VALUES(payment_policy)';
  $stmt = db()->prepare($sql);
  $stmt->execute([
    $id,
    $name,
    $category,
    $price,
    clean_money($d['costPrice'] ?? 0),
    clean_str($d['badge'] ?? '', 120),
    clean_emoji($d['emoji'] ?? ''),
    clean_str($d['desc'] ?? '', 1000),
    clean_str($d['fullDesc'] ?? '', 4000),
    json_encode($features, JSON_UNESCAPED_UNICODE),
    json_encode($images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    $payment_policy,
    0
  ]);
  out(['ok' => true, 'id' => $id]);
}

if ($action === 'delete_product') {
  require_admin();
  $d = body();
  db()->prepare('DELETE FROM products WHERE id=?')->execute([clean_str($d['id'] ?? '', 64)]);
  out(['ok' => true]);
}

if ($action === 'save_content') {
  require_admin();
  $d = body();
  $allowed = ['heroTitle','heroSub','about1','about2','phone','email','address','bkash'];
  $stmt = db()->prepare('REPLACE INTO content_settings (setting_key,setting_value) VALUES (?,?)');
  foreach (($d['content'] ?? []) as $k => $v) {
    if (!in_array($k, $allowed, true)) continue;
    $stmt->execute([$k, clean_str($v, 2500)]);
  }
  out(['ok' => true]);
}

if ($action === 'create_order') {
  $d = body();
  $incoming = is_array($d['items'] ?? null) ? $d['items'] : [];
  if (!$incoming) out(['ok' => false, 'error' => 'Cart is empty.'], 422);

  $qty_by_id = [];
  foreach ($incoming as $item) {
    $pid = clean_str($item['id'] ?? '', 64);
    if ($pid === '') continue;
    $qty_by_id[$pid] = ($qty_by_id[$pid] ?? 0) + clean_int($item['qty'] ?? 1, 1, 99);
  }
  if (!$qty_by_id) out(['ok' => false, 'error' => 'Cart is empty.'], 422);

  $placeholders = implode(',', array_fill(0, count($qty_by_id), '?'));
  $stmt = db()->prepare("SELECT * FROM products WHERE id IN ($placeholders)");
  $stmt->execute(array_keys($qty_by_id));
  $products = $stmt->fetchAll();
  if (!$products) out(['ok' => false, 'error' => 'Products not found. Refresh the page and try again.'], 422);

  $items = [];
  $total = 0;
  $needs_advance = false;
  foreach ($products as $p) {
    $qty = $qty_by_id[$p['id']] ?? 0;
    if ($qty <= 0) continue;
    $price = (float)$p['price'];
    $total += $price * $qty;
    if ($p['payment_policy'] === 'fifty') $needs_advance = true;
    $items[] = [
      'id' => $p['id'],
      'name' => $p['name'],
      'qty' => $qty,
      'price' => $price,
      'costPrice' => (float)$p['cost_price'],
      'category' => $p['category']
    ];
  }
  if (!$items || $total <= 0) out(['ok' => false, 'error' => 'Invalid cart.'], 422);

  $delivery_area = in_array(($d['deliveryArea'] ?? 'inside_dhaka'), ['inside_dhaka','outside_dhaka'], true) ? $d['deliveryArea'] : 'inside_dhaka';
  $delivery_fee = delivery_charge($delivery_area, $items);
  $total += $delivery_fee;

  $name = clean_str($d['name'] ?? '', 160);
  $phone = clean_str($d['phone'] ?? '', 60);
  $address = clean_str($d['address'] ?? '', 1000);
  if ($name === '' || $phone === '' || $address === '') out(['ok' => false, 'error' => 'Name, phone, and address are required.'], 422);

  $payment = $needs_advance ? 'fifty' : (in_array(($d['payment'] ?? 'cod'), ['cod','bkash'], true) ? $d['payment'] : 'cod');
  $pay_label = $payment === 'cod' ? 'Cash on Delivery' : '50% Bkash Advance';
  $bkash_from = clean_str($d['bkashFrom'] ?? '', 60);
  $bkash_trx = clean_str($d['bkashTrx'] ?? '', 120);
  if ($payment !== 'cod' && ($bkash_from === '' || $bkash_trx === '')) out(['ok' => false, 'error' => 'Bkash number and transaction ID are required.'], 422);

  $id = 'TV-' . substr((string)time(), -6) . random_int(10, 99);
  $month = date('F Y');
  $stmt = db()->prepare('INSERT INTO orders (id,customer_name,phone,address,district,note,delivery_area,delivery_charge,payment,pay_label,bkash_from,bkash_trx,total,items,status,order_month) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
  $stmt->execute([
    $id,
    $name,
    $phone,
    $address,
    clean_str($d['district'] ?? '', 120),
    clean_str($d['note'] ?? '', 1000),
    $delivery_area,
    $delivery_fee,
    $payment,
    $pay_label,
    $bkash_from,
    $bkash_trx,
    $total,
    json_encode($items, JSON_UNESCAPED_UNICODE),
    'Pending',
    $month
  ]);
  out(['ok' => true, 'id' => $id, 'total' => $total, 'deliveryCharge' => $delivery_fee, 'deliveryArea' => $delivery_area]);
}

if ($action === 'update_order_status') {
  require_admin();
  $d = body();
  $status = in_array(($d['status'] ?? 'Pending'), ['Pending','Confirmed','Delivered','Cancelled'], true) ? $d['status'] : 'Pending';
  db()->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$status, clean_str($d['id'] ?? '', 32)]);
  out(['ok' => true]);
}

if ($action === 'upload_image') {
  require_admin();
  if (empty($_FILES['image'])) out(['ok' => false, 'error' => 'No image uploaded. Choose an image first.'], 400);

  $f = $_FILES['image'];
  $upload_errors = [
    UPLOAD_ERR_INI_SIZE => 'Image is larger than the server upload limit.',
    UPLOAD_ERR_FORM_SIZE => 'Image is larger than the form upload limit.',
    UPLOAD_ERR_PARTIAL => 'Image uploaded only partially. Try again.',
    UPLOAD_ERR_NO_FILE => 'No image file was selected.',
    UPLOAD_ERR_NO_TMP_DIR => 'Server temporary upload folder is missing.',
    UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded file.',
    UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.'
  ];
  if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    out(['ok' => false, 'error' => $upload_errors[$f['error']] ?? 'Upload failed.'], 400);
  }
  if (($f['size'] ?? 0) <= 0) out(['ok' => false, 'error' => 'Uploaded image is empty.'], 400);
  if ($f['size'] > 5 * 1024 * 1024) out(['ok' => false, 'error' => 'Image too large. Max 5MB.'], 400);

  $original = (string)($f['name'] ?? 'image');
  $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
  $ext_map = ['jpg' => 'jpg', 'jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp', 'gif' => 'gif'];
  if (!isset($ext_map[$ext])) out(['ok' => false, 'error' => 'Only JPG, PNG, WEBP, and GIF images are allowed.'], 400);

  $tmp = (string)$f['tmp_name'];
  $is_image = false;
  if (function_exists('getimagesize')) {
    $info = @getimagesize($tmp);
    $is_image = is_array($info) && !empty($info[0]) && !empty($info[1]);
  }
  if (!$is_image && function_exists('finfo_open')) {
    $fi = @finfo_open(FILEINFO_MIME_TYPE);
    $mime = $fi ? @finfo_file($fi, $tmp) : '';
    if ($fi) @finfo_close($fi);
    $is_image = in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
  }
  if (!$is_image) out(['ok' => false, 'error' => 'The selected file is not a valid image. Try JPG or PNG.'], 400);

  $dir = __DIR__ . '/uploads';
  if (!is_dir($dir) && !mkdir($dir, 0755, true)) out(['ok' => false, 'error' => 'Could not create uploads folder.'], 500);
  if (!is_writable($dir)) @chmod($dir, 0755);
  if (!is_writable($dir)) out(['ok' => false, 'error' => 'uploads folder is not writable. Set public_html/uploads permission to 755 or 775.'], 500);

  $safe_ext = $ext_map[$ext];
  $name = 'uploads/' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $safe_ext;
  $dest = __DIR__ . '/' . $name;
  if (!move_uploaded_file($tmp, $dest)) out(['ok' => false, 'error' => 'Could not save uploaded image. Check uploads folder permission.'], 500);
  @chmod($dest, 0644);

  out(['ok' => true, 'url' => $name]);
}

out(['ok' => false, 'error' => 'Unknown action'], 404);
