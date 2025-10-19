<?php
// index.php - Combined code with “Buyers Ordered From Me” feature

// ===== Part 1: Setup, Utilities, DB etc. =====

require 'db_connection.php'; // you must have this to set up $conn

session_start();

// --- LOGOUT HANDLING ---
if (isset($_GET['logout'])) {
$_SESSION = [];
if (ini_get("session.use_cookies")) {
$params = session_get_cookie_params();
setcookie(session_name(), '', time() - 42000,
$params["path"], $params["domain"],
$params["secure"], $params["httponly"]
);
}
session_destroy();
session_start();
$_SESSION['flash'] = "Logged out successfully.";
header("Location: index.php");
exit;
}

// ----- CONFIG -----
define('MAX_UPLOAD_BYTES', 2 * 1024 * 1024); // 2MB
$ALLOWED_EXT = ['jpg','jpeg','png','gif','webp'];
$ALLOWED_MIME = ['image/jpeg','image/png','image/gif','image/webp'];

// ----- UTILS -----
function debug_log($msg) { error_log($msg); }
function redirect($url) { header("Location: $url"); exit; }
function flash($msg = null) {
if ($msg !== null) { $_SESSION['flash'] = $msg; return; }
if (isset($_SESSION['flash'])) { $m = $_SESSION['flash']; unset($_SESSION['flash']); return $m; }
return null;
}
function sanitize($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function current_user() {
global $conn;
if (isset($_SESSION['user_id'])) {
$id = intval($_SESSION['user_id']);
$stmt = $conn->prepare("SELECT id, username, email, role, phone, city, created_at FROM users WHERE id = ?");
if (!$stmt) { debug_log("current_user prepare fail: ".$conn->error); return null; }
$stmt->bind_param("i",$id);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $res->num_rows === 1) return $res->fetch_assoc();
}
return null;
}
function csrf_token() {
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
return $_SESSION['csrf_token'];
}
function csrf_check($token) {
return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
}

// ----- STORAGE / UPLOAD -----
function safe_random_name($ext) {
return bin2hex(random_bytes(16)) . '.' . $ext;
}
function handle_image_upload($file_input_name) {
global $ALLOWED_EXT, $ALLOWED_MIME;
if (!isset($_FILES[$file_input_name])) return [ 'error' => 'no_file' ];
$f = $_FILES[$file_input_name];
if ($f['error'] !== UPLOAD_ERR_OK) return [ 'error' => 'upload_error' ];
if ($f['size'] > MAX_UPLOAD_BYTES) return ['error' => 'size_exceeded'];
$ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $ALLOWED_EXT)) return ['error' => 'bad_ext'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $f['tmp_name']);
finfo_close($finfo);
if (!in_array($mime, $ALLOWED_MIME)) return ['error' => 'bad_mime'];
$newname = safe_random_name($ext);
$target_dir = __DIR__ . '/uploads';
if (!is_dir($target_dir)) {
mkdir($target_dir, 0755, true);
}
$target = $target_dir . '/' . $newname;
if (!move_uploaded_file($f['tmp_name'], $target)) return ['error' => 'move_failed'];
return ['success' => true, 'name' => $newname];
}

// ----- Ensure tables exist -----
function ensure_tables_exist($conn) {
$queries = [
"CREATE TABLE IF NOT EXISTS users (
id INT AUTO_INCREMENT PRIMARY KEY,
username VARCHAR(50) UNIQUE,
email VARCHAR(100) UNIQUE,
password VARCHAR(255),
role ENUM('buyer','farmer'),
phone VARCHAR(20) UNIQUE,
city VARCHAR(100),
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB",
"CREATE TABLE IF NOT EXISTS posts (
id INT AUTO_INCREMENT PRIMARY KEY,
farmer_id INT,
title VARCHAR(255),
content TEXT,
image VARCHAR(255),
crop_name VARCHAR(100),
price DOUBLE,
quantity DOUBLE,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
FOREIGN KEY (farmer_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB",
"CREATE TABLE IF NOT EXISTS ratings (
id INT AUTO_INCREMENT PRIMARY KEY,
buyer_id INT,
farmer_id INT,
post_id INT,
rating INT,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
FOREIGN KEY (farmer_id) REFERENCES users(id) ON DELETE CASCADE,
FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB",
"CREATE TABLE IF NOT EXISTS comments (
id INT AUTO_INCREMENT PRIMARY KEY,
post_id INT,
user_id INT,
comment TEXT,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB",
"CREATE TABLE IF NOT EXISTS contact_requests (
id INT AUTO_INCREMENT PRIMARY KEY,
buyer_id INT,
farmer_id INT,
post_id INT,
status ENUM('pending','accepted','rejected') DEFAULT 'pending',
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
FOREIGN KEY (farmer_id) REFERENCES users(id) ON DELETE CASCADE,
FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB",
"CREATE TABLE IF NOT EXISTS orders (
id INT AUTO_INCREMENT PRIMARY KEY,
post_id INT,
buyer_id INT,
farmer_id INT,
price DOUBLE,
quantity DOUBLE,
delivery_address TEXT,
status ENUM('pending','accepted','rejected','dispatched','delivered') DEFAULT 'pending',
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
FOREIGN KEY (farmer_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB",
"CREATE TABLE IF NOT EXISTS weather_market (
id INT AUTO_INCREMENT PRIMARY KEY,
weather VARCHAR(100),
crop_prices LONGTEXT,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB"
];
foreach ($queries as $q) {
if (!$conn->query($q)) {
debug_log("ensure_tables_exist SQL error: " . $conn->error . " (Query: $q)");
}
}
}
ensure_tables_exist($conn);

// ----- DB Helpers -----
function avg_rating($post_id) {
global $conn;
$stmt = $conn->prepare("SELECT AVG(rating) AS avg_rate, COUNT(*) AS cnt FROM ratings WHERE post_id = ?");
if (!$stmt) { debug_log("avg_rating prepare failed: ".$conn->error); return "N/A"; }
$stmt->bind_param("i", $post_id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
if ($res && $res['cnt'] > 0) {
return number_format($res['avg_rate'], 1) . " / 5 ({$res['cnt']})";
}
return "No ratings yet";
}
function user_rating($buyer_id, $post_id) {
global $conn;
$stmt = $conn->prepare("SELECT rating FROM ratings WHERE buyer_id=? AND post_id=? LIMIT 1");
if (!$stmt) { debug_log("user_rating prepare failed: ".$conn->error); return 0; }
$stmt->bind_param("ii", $buyer_id, $post_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $res->num_rows === 1) {
return (int)$res->fetch_assoc()['rating'];
}
return 0;
}
function comments_for_post($post_id) {
global $conn;
$stmt = $conn->prepare("SELECT c.comment, c.created_at, u.username FROM comments c JOIN users u ON c.user_id = u.id WHERE c.post_id = ? ORDER BY c.created_at DESC");
if (!$stmt) { debug_log("comments_for_post prepare failed: ".$conn->error); return null; }
$stmt->bind_param("i", $post_id);
$stmt->execute();
return $stmt->get_result();
}
function has_contact_request($buyer_id, $post_id) {
global $conn;
$stmt = $conn->prepare("SELECT status FROM contact_requests WHERE buyer_id=? AND post_id=? LIMIT 1");
if (!$stmt) { debug_log("has_contact_request prepare failed: ".$conn->error); return false; }
$stmt->bind_param("ii", $buyer_id, $post_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $res->num_rows === 1) return $res->fetch_assoc()['status'];
return false;
}
function contact_requests_for_farmer($farmer_id) {
global $conn;
$stmt = $conn->prepare("SELECT cr.id, u.username as buyer_name, p.title as post_title FROM contact_requests cr JOIN users u ON cr.buyer_id = u.id JOIN posts p ON cr.post_id = p.id WHERE cr.farmer_id = ? AND cr.status = 'pending'");
if (!$stmt) { debug_log("contact_requests_for_farmer prepare failed: ".$conn->error); return null; }
$stmt->bind_param("i", $farmer_id);
$stmt->execute();
return $stmt->get_result();
}
function get_weather_market() {
global $conn;
$res = $conn->query("SELECT weather, crop_prices FROM weather_market ORDER BY updated_at DESC LIMIT 1");
if (!$res) return ['weather' => 'N/A', 'crop_prices' => '{}']; // default empty JSON
return $res->fetch_assoc() ?? ['weather' => 'N/A', 'crop_prices' => '{}'];
}

// ----- AUTH functions -----
function register_user($username, $email, $password, $role, $phone, $city) {
global $conn;
$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $conn->prepare("INSERT INTO users (username, email, password, role, phone, city) VALUES (?, ?, ?, ?, ?, ?)");
if (!$stmt) { debug_log("register prepare fail: ".$conn->error); return [false, "DB error"]; }
$stmt->bind_param("ssssss", $username, $email, $hash, $role, $phone, $city);
if ($stmt->execute()) return [true, null];
return [false, $conn->error];
}
function login_user($identifier, $password) {
global $conn;
$stmt = $conn->prepare("SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1");
if (!$stmt) { debug_log("login prepare fail: ".$conn->error); return [false, "DB error"]; }
$stmt->bind_param("ss", $identifier, $identifier);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $res->num_rows === 1) {
$u = $res->fetch_assoc();
if (password_verify($password, $u['password'])) {
$_SESSION['user_id'] = $u['id'];
return [true, null];
}
}
return [false, "Invalid credentials"];
}

// ----- MAIN USER & CSRF & fetched data before handling POST ----
$user = current_user();
$msg = flash();
$csrf = csrf_token();
$weather_market = get_weather_market();

// Decode crop prices JSON into an associative array
$crop_prices = json_decode($weather_market['crop_prices'], true);
if (!is_array($crop_prices)) $crop_prices = [];
$all_crops = ["Banana","Bajra","Coffee","Cotton","Gram","Groundnut","Maize","Moong","Mustard",
"Onion","Potato","Ragi","Rice","Sugarcane","Sunflower","Tea","Tomato","Tur","Urad","Wheat"];



// Fetch buyer orders (for buyer role)
$buyer_orders = null;
if ($user && $user['role'] === 'buyer') {
$stmt = $conn->prepare("
SELECT o.*, p.crop_name, u.username AS farmer_name
FROM orders o
JOIN posts p ON o.post_id = p.id
JOIN users u ON o.farmer_id = u.id
WHERE o.buyer_id = ?
ORDER BY o.created_at DESC
");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$buyer_orders = $stmt->get_result();
$stmt->close();
}

// Fetch posts for display/search
$search = trim($_GET['search'] ?? '');
if ($search !== '') {
$search_sql = "%$search%";
$stmt = $conn->prepare("
SELECT p.*, u.username AS farmer_name
FROM posts p
JOIN users u ON p.farmer_id = u.id
WHERE p.title LIKE ? OR p.content LIKE ? OR p.crop_name LIKE ?
ORDER BY p.created_at DESC
");
$stmt->bind_param("sss", $search_sql, $search_sql, $search_sql);
$stmt->execute();
$posts = $stmt->get_result();
$stmt->close();
} else {
$posts = $conn->query("SELECT p.*, u.username AS farmer_name FROM posts p JOIN users u ON p.farmer_id = u.id ORDER BY p.created_at DESC");
}

// Fetch contact requests pending if farmer
$pending_contact_requests = null;
if ($user && $user['role'] === 'farmer') {
$pending_contact_requests = contact_requests_for_farmer($user['id']);
}

// *** NEW: Fetch farmer orders (orders placed by buyers to this farmer) for “Buyers Ordered From Me” ***
$farmer_orders = null;
if ($user && $user['role'] === 'farmer') {
$stmt = $conn->prepare("
SELECT o.*, u.username AS buyer_name, p.crop_name
FROM orders o
JOIN users u ON o.buyer_id = u.id
JOIN posts p ON o.post_id = p.id
WHERE o.farmer_id = ?
ORDER BY o.created_at DESC
");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$farmer_orders = $stmt->get_result();
$stmt->close();
}

// ===== Part 2: Handle POST actions (login, register, create post, review, contact, order, update order status) =====

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
// CSRF check
$token = $_POST['csrf_token'] ?? ($_POST['csrf'] ?? null);
if (!csrf_check($token)) {
flash("Invalid CSRF token. Try again.");
redirect("index.php");
}

// REGISTER
if (isset($_POST['action']) && $_POST['action'] === 'register') {
$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$role = $_POST['role'] ?? 'buyer';
$phone = trim($_POST['phone'] ?? '');
$city = trim($_POST['city'] ?? '');

if ($username === '' || $email === '' || $password === '' || $phone === '') {
flash("Please fill required fields.");
redirect("index.php");
}

// 🔍 Check if email or phone already exists
$checkQuery = "SELECT id FROM users WHERE email = ? OR phone = ?";
$stmt = $conn->prepare($checkQuery);
$stmt->bind_param("ss", $email, $phone);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
flash("Account already exists with this email or phone number.");
redirect("index.php");
}

// Proceed to register user
list($ok, $err) = register_user($username, $email, $password, $role, $phone, $city);

if ($ok) {
flash("Registered successfully. Please login.");
} else {
flash("Registration failed: " . ($err ?? 'unknown'));
}

redirect("index.php");
}

// LOGIN
if (isset($_POST['action']) && $_POST['action'] === 'login') {
$identifier = trim($_POST['username'] ?? $_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
if ($identifier === '' || $password === '') {
flash("Please provide credentials.");
redirect("index.php");
}
list($ok, $err) = login_user($identifier, $password);
if ($ok) {
flash("Logged in successfully.");
} else {
flash($err ?? 'Login failed.');
}
redirect("index.php");
}

// CREATE POST (Farmer)
if (isset($_POST['create_post']) && $user && $user['role'] === 'farmer') {
$crop_name = trim($_POST['crop_name'] ?? '');
$price = floatval($_POST['price'] ?? 0);
$quantity = floatval($_POST['quantity'] ?? 0);
$content = trim($_POST['content'] ?? '');
$title = $crop_name . " - " . substr($content,0,50);
$imgres = handle_image_upload('image');
if (isset($imgres['error'])) {
flash("Image upload failed: " . $imgres['error']);
redirect("index.php");
}
$imgname = $imgres['name'] ?? null;
$stmt = $conn->prepare("INSERT INTO posts (farmer_id, title, content, image, crop_name, price, quantity) VALUES (?, ?, ?, ?, ?, ?, ?)");
if (!$stmt) { flash("DB error: ".$conn->error); redirect("index.php"); }
$stmt->bind_param("issssdd", $user['id'], $title, $content, $imgname, $crop_name, $price, $quantity);
if ($stmt->execute()) {
flash("Post created successfully.");
} else {
flash("Failed to create post: " . $conn->error);
}
$stmt->close();
redirect("index.php");
}

// SUBMIT REVIEW (Buyer)
if (isset($_POST['submit_review']) && $user && $user['role'] === 'buyer') {
$post_id = intval($_POST['post_id'] ?? 0);
$rating = intval($_POST['post_rating'] ?? 0);
$comment = trim($_POST['comment'] ?? '');
if ($rating < 1 || $rating > 5) { flash("Invalid rating."); redirect("index.php"); }
// get farmer_id
$stmt = $conn->prepare("SELECT farmer_id FROM posts WHERE id = ?");
$stmt->bind_param("i", $post_id);
$stmt->execute();
$r = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$r) { flash("Post not found."); redirect("index.php"); }
$farmer_id = $r['farmer_id'];
// upsert rating
$stmt = $conn->prepare("SELECT id FROM ratings WHERE buyer_id=? AND post_id=? LIMIT 1");
$stmt->bind_param("ii", $user['id'], $post_id);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();
if ($res && $res->num_rows === 1) {
$stmt2 = $conn->prepare("UPDATE ratings SET rating=?, created_at=CURRENT_TIMESTAMP WHERE buyer_id=? AND post_id=?");
$stmt2->bind_param("iii", $rating, $user['id'], $post_id);
$stmt2->execute();
$stmt2->close();
} else {
$stmt2 = $conn->prepare("INSERT INTO ratings (buyer_id, farmer_id, post_id, rating) VALUES (?, ?, ?, ?)");
$stmt2->bind_param("iiii", $user['id'], $farmer_id, $post_id, $rating);
$stmt2->execute();
$stmt2->close();
}
if ($comment !== '') {
$stmt3 = $conn->prepare("INSERT INTO comments (post_id, user_id, comment) VALUES (?, ?, ?)");
$stmt3->bind_param("iis", $post_id, $user['id'], $comment);
$stmt3->execute();
$stmt3->close();
}
flash("Review submitted. Thanks!");
redirect("index.php");
}

// POST A COMMENT (any logged user)
if (isset($_POST['submit_comment']) && $user) {
$post_id = intval($_POST['post_id'] ?? 0);
$comment = trim($_POST['comment_text'] ?? '');
if ($comment === '') { flash("Comment cannot be empty."); redirect("index.php"); }
$stmt = $conn->prepare("INSERT INTO comments (post_id, user_id, comment) VALUES (?, ?, ?)");
$stmt->bind_param("iis", $post_id, $user['id'], $comment);
if ($stmt->execute()) flash("Comment added.");
else flash("Failed to add comment.");
$stmt->close();
redirect("index.php");
}

// REQUEST CONTACT (Buyer)
if (isset($_POST['contact_post']) && $user && $user['role'] === 'buyer') {
$post_id = intval($_POST['post_id'] ?? 0);
$stmt = $conn->prepare("SELECT farmer_id FROM posts WHERE id = ?");
$stmt->bind_param("i", $post_id);
$stmt->execute();
$p = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$p) { flash("Post not found."); redirect("index.php"); }
$farmer_id = $p['farmer_id'];
// check existing
$stmt = $conn->prepare("SELECT id FROM contact_requests WHERE buyer_id=? AND post_id=? LIMIT 1");
$stmt->bind_param("ii", $user['id'], $post_id);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();
if ($res && $res->num_rows > 0) {
flash("Contact request already exists.");
redirect("index.php");
}
$stmt = $conn->prepare("INSERT INTO contact_requests (buyer_id, farmer_id, post_id, status) VALUES (?, ?, ?, 'pending')");
$stmt->bind_param("iii", $user['id'], $farmer_id, $post_id);
if ($stmt->execute()) flash("Contact request sent.");
else flash("Failed to send contact request.");
$stmt->close();
redirect("index.php");
}

// FARMER handles contact requests (accept/reject)
if (isset($_POST['handle_contact']) && $user && $user['role'] === 'farmer') {
$cr_id = intval($_POST['cr_id'] ?? 0);
$decision = $_POST['handle_contact'] ?? 'rejected';
if (!in_array($decision, ['accepted','rejected'])) $decision = 'rejected';
$stmt = $conn->prepare("UPDATE contact_requests SET status=? WHERE id=? AND farmer_id=?");
$stmt->bind_param("sii", $decision, $cr_id, $user['id']);
if ($stmt->execute()) flash("Contact request updated.");
else flash("Failed to update contact request.");
$stmt->close();
redirect("index.php");
}

// PLACE ORDER (Buyer)
if (isset($_POST['place_order']) && $user && $user['role'] === 'buyer') {
$post_id = intval($_POST['post_id'] ?? 0);
$order_qty = floatval($_POST['order_quantity'] ?? 0);
$delivery_address = trim($_POST['delivery_address'] ?? '');
if ($order_qty <= 0 || $delivery_address === '') { flash("Provide valid quantity and delivery address."); redirect("index.php"); }
$stmt = $conn->prepare("SELECT p.id, p.quantity, p.price, p.farmer_id FROM posts p WHERE p.id = ? FOR UPDATE");
$conn->begin_transaction(MYSQLI_TRANS_START_READ_WRITE);
$stmt->bind_param("i", $post_id);
$stmt->execute();
$post = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$post) {
$conn->rollback();
flash("Post not found.");
redirect("index.php");
}
if ($post['quantity'] < $order_qty) {
$conn->rollback();
flash("Requested quantity exceeds available stock.");
redirect("index.php");
}
// insert order
$stmt2 = $conn->prepare("INSERT INTO orders (post_id, buyer_id, farmer_id, price, quantity, delivery_address, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
$total_price = floatval($post['price']);
$stmt2->bind_param("iiidds", $post_id, $user['id'], $post['farmer_id'], $total_price, $order_qty, $delivery_address);
if (!$stmt2->execute()) {
$conn->rollback();
flash("Failed to place order: " . $conn->error);
redirect("index.php");
}
$order_id = $stmt2->insert_id;
$stmt2->close();
// update stock
$stmt3 = $conn->prepare("UPDATE posts SET quantity = quantity - ? WHERE id = ?");
$stmt3->bind_param("di", $order_qty, $post_id);
if (!$stmt3->execute()) {
$conn->rollback();
flash("Failed to update stock: " . $conn->error);
redirect("index.php");
}
$stmt3->close();
$conn->commit();
flash("Order placed successfully. Order ID: $order_id");
redirect("index.php");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_order_status'])) {
$csrf_token = $_POST['csrf_token'] ?? '';
$order_id = intval($_POST['order_id'] ?? 0);
$new_status = $_POST['update_order_status'] ?? '';
if (empty($_SESSION['csrf_token'])) {
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// CSRF token verification function
function verify_csrf($token) {
return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}


if (!verify_csrf($csrf_token)) {
$msg = "Invalid CSRF token.";
} elseif (!in_array($new_status, ['pending','accepted','rejected','dispatched','delivered'])) {
$msg = "Invalid status selected.";
} else {
$stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ? AND farmer_id = ?");
$stmt->bind_param("sii", $new_status, $order_id, $user['id']);
if ($stmt->execute()) {
$msg = "Order status updated to '$new_status'.";
} else {
$msg = "Failed to update order status.";
}
$stmt->close();
}
}


} // end if POST

// ===== HTML + Part 2: Rendering UI =====

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Farm Connect</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
<style>
body { background: linear-gradient(135deg,#f3fbf1,#eaf7ff); min-height:100vh; display:flex; flex-direction:column; }
.star { color: gold; font-size:1.1rem; }
.star.inactive { color:#ccc; }
.fade-in { animation: fadeIn 0.6s ease forwards; }
@keyframes fadeIn { from {opacity:0; transform:translateY(8px);} to {opacity:1; transform:translateY(0);} }
.contact-block { background:#e9f7ef; color:#155724; border:2px solid #28a745; border-radius:8px; padding:1em; position:relative; }
.contact-block .close-contact { position:absolute; top:6px; right:8px; background:none; border:none; font-size:1.2em; cursor:pointer; color:#155724; }
.offcanvas-order .modal-content { border-radius: 8px; }
.uploads-thumb { width:3cm; height:3cm; object-fit:cover; border-radius:6px; }
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-success sticky-top shadow">
<div class="container">
<a class="navbar-brand" href="index.php">Farm Connect</a>
<div class="ms-auto d-flex align-items-center gap-3 text-light">
<?php if ($user): ?>
<div class="me-2">🌤️ <?=sanitize($weather_market['weather'])?> </div>

<div class="me-2">
<label for="farmer_crop">Select Crop:</label>
<select id="farmer_crop" class="form-select" onchange="updatePrice()">
<option value="">-- Select Crop --</option>
<?php
// Make sure $all_crops is defined somewhere above this block
foreach($all_crops as $crop):
// Get the price from the decoded crop_prices JSON
$price = $crop_prices[$crop] ?? '-';
?>
<option value="<?=htmlspecialchars($crop)?>" data-price="<?=htmlspecialchars($price)?>">
<?=htmlspecialchars($crop)?>
</option>
<?php endforeach; ?>
</select>
</div>

<div class="me-2 mt-2">
<label for="crop_price">Price (₹):</label>
<input type="text" id="crop_price" class="form-control" readonly>
</div>

<script>
// Ensure cropPrices object contains the latest prices from admin
const cropPrices = <?php echo json_encode($crop_prices); ?>;

function updatePrice() {
const cropSelect = document.getElementById("farmer_crop");
const selectedCrop = cropSelect.value;
const priceInput = document.getElementById("crop_price");

// Show the updated price or '-' if not set
priceInput.value = cropPrices[selectedCrop] ?? '-';
}

// Optional: Set the first crop price on page load if a crop is pre-selected
document.addEventListener('DOMContentLoaded', () => {
updatePrice();
});
</script>




<div>Welcome, <strong><?=sanitize($user['username'])?></strong> (<?=sanitize($user['role'])?>)</div>
<?php if ($user['role'] === 'buyer'): ?>
<button class="btn btn-outline-light btn-sm" data-bs-toggle="offcanvas" data-bs-target="#ordersPanel" aria-controls="ordersPanel">My Orders</button>
<?php else: ?>
<button class="btn btn-outline-light btn-sm" data-bs-toggle="modal" data-bs-target="#contactRequestsModal" aria-label="Pending Contact Requests">
Contact Requests
<?php if ($pending_contact_requests && $pending_contact_requests->num_rows > 0): ?>
<span class="badge bg-danger"><?= $pending_contact_requests->num_rows ?></span>
<?php endif; ?>
</button>
<!-- New button: Buyers Ordered from Me -->
<button class="btn btn-outline-light btn-sm" data-bs-toggle="offcanvas" data-bs-target="#farmerOrdersPanel">Orders From Buyers</button>
<?php endif; ?>
<a href="index.php?logout=1" class="btn btn-outline-light btn-sm" aria-label="Logout">Logout</a>
<?php else: ?>
<button class="btn btn-outline-light btn-sm" data-bs-toggle="modal" data-bs-target="#loginModal" aria-label="Login">Login</button>
<button class="btn btn-outline-light btn-sm" data-bs-toggle="modal" data-bs-target="#registerModal" aria-label="Register">Register</button>
<?php endif; ?>
<div id="google_translate_element" style="margin-left:auto;">
</div>
</div>
</div>
</nav>

<div class="container my-4 flex-grow-1">
<?php if ($msg): ?>
<div class="alert alert-info fade-in"><?=sanitize($msg)?></div>
<?php endif; ?>

<?php if (!$user): ?>
<div class="text-center my-5">
<h1 class="mb-3">Welcome to Farm Connect</h1>
<p class="lead">Find fresh produce from local farmers — or sell your harvest easily.</p>
<button class="btn btn-success btn-lg mx-2" data-bs-toggle="modal" data-bs-target="#registerModal">Register</button>
<button class="btn btn-outline-success btn-lg mx-2" data-bs-toggle="modal" data-bs-target="#loginModal">Login</button>
</div>
<?php else: ?>
<div class="row mb-4 align-items-center">
<div class="col-md-8 mb-2 mb-md-0">
<form method="get" class="d-flex" role="search" aria-label="Search posts">
<input type="search" name="search" class="form-control" placeholder="Search posts..." value="<?=sanitize($search)?>" />
<button class="btn btn-success ms-2" type="submit">Search</button>
<?php if ($search): ?><a href="index.php" class="btn btn-outline-secondary ms-2">Clear</a><?php endif; ?>
</form>
</div>
<?php if ($user['role'] === 'farmer'): ?>
<div class="col-md-4 text-md-end">
<button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addPostModal">Add New Post</button>
<button class="btn btn-outline-primary ms-2" data-bs-toggle="offcanvas" data-bs-target="#farmerOrdersPanel">Buyers Ordered from Me</button>
</div>
<?php endif; ?>
</div>

<div class="row gy-4">
<?php if ($posts && $posts->num_rows > 0): ?>
<?php while ($post = $posts->fetch_assoc()): ?>
<?php $pid = (int)$post['id']; ?>
<div class="col-md-6 col-lg-4 fade-in">
<div class="card h-100 shadow-sm">
<?php if (!empty($post['image']) && file_exists(__DIR__ . "/uploads/" . $post['image'])): ?>
<center><img src="uploads/<?=sanitize($post['image'])?>" class="uploads-thumb card-img-top" alt="Post image" /></center>
<?php else: ?>
<img src="https://via.placeholder.com/400x200?text=No+Image" class="card-img-top" alt="No image" />
<?php endif; ?>
<div class="card-body d-flex flex-column">
<h5 class="card-title"><?=sanitize($post['title'] ?? $post['crop_name'])?></h5>
<small class="text-muted">By <?=sanitize($post['farmer_name'])?> on <?=sanitize($post['created_at'])?></small>
<p class="card-text mt-2"><?=nl2br(sanitize($post['content']))?></p>
<div><strong>Average Rating:</strong> <?=avg_rating($post['id'])?></div>
<hr class="my-2" />
<div style="flex-grow:1; overflow-y:auto; max-height:140px;">
<h6>Comments:</h6>
<?php
$comments = comments_for_post($post['id']);
if (!$comments || $comments->num_rows === 0) {
echo "<p><em>No comments yet.</em></p>";
} else {
while ($cm = $comments->fetch_assoc()) {
echo "<p><strong>" . sanitize($cm['username']) . ":</strong> " . sanitize($cm['comment']) . "<br><small class='text-muted'>" . sanitize($cm['created_at']) . "</small></p>";
}
}
?>
</div>

<?php if ($user['role'] === 'buyer'): ?>
<!-- Review form -->
<form method="post" class="mt-3">
<input type="hidden" name="csrf_token" value="<?=sanitize($csrf)?>" />
<input type="hidden" name="post_id" value="<?=sanitize($pid)?>" />
<div class="mb-2">
<label for="post_rating_<?=$pid?>" class="form-label">Your Rating:</label>
<select name="post_rating" id="post_rating_<?=$pid?>" class="form-select form-select-sm" required>
<?php
$userRate = user_rating($user['id'], $post['id']);
for($i=5; $i>=1; $i--) {
$sel = ($userRate == $i) ? "selected" : "";
echo "<option value='$i' $sel>$i star" . ($i > 1 ? 's' : '') . "</option>";
}
?>
</select>
</div>
<div class="mb-2">
<label for="comment_<?=$pid?>" class="form-label">Comment:</label>
<textarea name="comment" id="comment_<?=$pid?>" class="form-control form-control-sm" rows="2" placeholder="Leave a comment..."></textarea>
</div>
<button type="submit" name="submit_review" class="btn btn-sm btn-outline-success">Submit Review</button>
</form>

<?php if ($post['farmer_id'] != $user['id']): ?>
<form method="post" class="mt-3 d-inline">
<input type="hidden" name="csrf_token" value="<?=sanitize($csrf)?>" />
<input type="hidden" name="post_id" value="<?=sanitize($pid)?>" />
<?php
$status = has_contact_request($user['id'], $post['id']);
if ($status === false) {
echo '<button type="submit" name="contact_post" class="btn btn-sm btn-success">Request Contact</button>';
} elseif ($status === 'pending') {
echo '<button disabled class="btn btn-sm btn-warning">Contact Request Pending</button>';
} elseif ($status === 'accepted') {
$stmt_farmer = $conn->prepare("SELECT username, phone, email, city FROM users WHERE id = ?");
$stmt_farmer->bind_param("i", $post['farmer_id']);
$stmt_farmer->execute();
$contact = $stmt_farmer->get_result()->fetch_assoc();
$stmt_farmer->close();
echo '<button type="button" class="btn btn-success btn-sm show-contact" data-post="'. $pid .'">Contact Accepted ✓ (Click to show)</button>';
echo '<div id="farmerContact-'. $pid .'" class="alert alert-info mt-2 contact-block" style="display:none;">';
echo '<button type="button" class="close-contact" aria-label="Close">&times;</button>';
echo '<strong>Farmer Info:</strong><br>';
echo 'Name: ' . sanitize($contact['username'] ?? 'N/A') . '<br>';
echo 'Phone: ' . sanitize($contact['phone'] ?? 'N/A') . '<br>';
echo 'Email: ' . sanitize($contact['email'] ?? 'N/A') . '<br>';
echo 'City: ' . sanitize($contact['city'] ?? 'N/A') . '<br>';
echo '</div>';
} elseif ($status === 'rejected') {
echo '<button disabled class="btn btn-sm btn-danger">Contact Request Rejected</button>';
}
?>
</form>

<?php if ($status === 'accepted'): ?>
<button class="btn btn-sm btn-primary ms-2" data-bs-toggle="offcanvas" data-bs-target="#orderPanel" aria-controls="orderPanel" data-post-id="<?= $pid ?>" data-post-title="<?= sanitize($post['title']) ?>" data-post-price="<?= $post['price'] ?>" data-post-quantity="<?= $post['quantity'] ?>">Place Order</button>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>

</div>
</div>
</div>
<?php endwhile; ?>
<?php else: ?>
<p class="text-center text-muted">No posts found.</p>
<?php endif; ?>
</div>
<?php endif; ?>
</div>

<!-- Offcanvas: Buyer’s Orders (for buyer role) -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="ordersPanel" aria-labelledby="ordersPanelLabel">
<div class="offcanvas-header">
<h5 id="ordersPanelLabel">My Orders</h5>
<button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
</div>
<div class="offcanvas-body">
<?php if ($buyer_orders && $buyer_orders->num_rows > 0): ?>
<ul class="list-group">
<?php while ($order = $buyer_orders->fetch_assoc()): ?>
<li class="list-group-item">
<strong><?=sanitize($order['crop_name'])?></strong> from <?=sanitize($order['farmer_name'])?><br>
Quantity: <?=sanitize($order['quantity'])?>, Price: <?=sanitize($order['price'])?><br>
Delivery: <?=nl2br(sanitize($order['delivery_address']))?><br>
Status: <span class="badge bg-info"><?=sanitize($order['status'])?></span><br>
Ordered on: <?=sanitize($order['created_at'])?>
</li>
<?php endwhile; ?>
</ul>
<?php else: ?>
<p>You have no orders yet.</p>
<?php endif; ?>
</div>
</div>

<!-- Offcanvas: Orders from Buyers (for farmer) -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="farmerOrdersPanel" aria-labelledby="farmerOrdersPanelLabel">
<div class="offcanvas-header">
<h5 id="farmerOrdersPanelLabel">Orders from Buyers</h5>
<button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
</div>
<div class="offcanvas-body">
<?php if ($farmer_orders && $farmer_orders->num_rows > 0): ?>
<ul class="list-group">
<?php while ($fo = $farmer_orders->fetch_assoc()): ?>
<li class="list-group-item">
<strong><?=sanitize($fo['crop_name'])?></strong> ordered by <?=sanitize($fo['buyer_name'])?><br>
Quantity: <?=sanitize($fo['quantity'])?>, Price: <?=sanitize($fo['price'])?><br>
Delivery Address: <?=nl2br(sanitize($fo['delivery_address']))?><br>
<!-- Status update dropdown for farmer -->
<form method="post" class="mt-2">
<input type="hidden" name="csrf_token" value="<?=sanitize($csrf)?>" />
<input type="hidden" name="order_id" value="<?=sanitize($fo['id'])?>" />
<label for="update_status_<?=$fo['id']?>" class="form-label">Status:</label>
<select name="update_order_status" id="update_status_<?=$fo['id']?>" class="form-select form-select-sm w-auto d-inline" onchange="this.form.submit()">
<?php
$statuses = ['pending','accepted','rejected','dispatched','delivered'];
foreach ($statuses as $st) {
$sel = ($st === $fo['status']) ? 'selected' : '';
echo "<option value='$st' $sel>" . ucfirst($st) . "</option>";
}
?>
</select>
</form>

<br><small class="text-muted">Ordered on: <?=sanitize($fo['created_at'])?></small>
</li>
<?php endwhile; ?>
</ul>
<?php else: ?>
<p>No orders from buyers yet.</p>
<?php endif; ?>
</div>
</div>

<!-- Offcanvas: Place Order (buyer places order) -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="orderPanel" aria-labelledby="orderPanelLabel">
<div class="offcanvas-header">
<h5 id="orderPanelLabel">Place Order</h5>
<button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
</div>
<div class="offcanvas-body">
<form method="post" id="placeOrderForm">
<input type="hidden" name="csrf_token" value="<?=sanitize($csrf)?>" />
<input type="hidden" name="post_id" id="orderPostId" value="" />
<div class="mb-3">
<label for="orderPostTitle" class="form-label">Product</label>
<input type="text" id="orderPostTitle" class="form-control" readonly />
</div>
<div class="mb-3">
<label for="orderPrice" class="form-label">Price per unit</label>
<input type="text" id="orderPrice" class="form-control" readonly />
</div>
<div class="mb-3">
<label for="orderQuantity" class="form-label">Quantity</label>
<input type="number" name="order_quantity" id="orderQuantity" class="form-control" required />
</div>
<div class="mb-3">
<label for="deliveryAddress" class="form-label">Delivery Address</label>
<textarea name="delivery_address" id="deliveryAddress" class="form-control" rows="3" required></textarea>
</div>
<button type="submit" name="place_order" class="btn btn-success">Place Order</button>
</form>
</div>
</div>

<!-- Modal: Login -->
<div class="modal fade" id="loginModal" tabindex="-1" aria-labelledby="loginModalLabel" aria-hidden="true">
<div class="modal-dialog">
<form method="post" class="modal-content needs-validation" novalidate>
<input type="hidden" name="csrf_token" value="<?=sanitize($csrf)?>" />
<input type="hidden" name="action" value="login" />
<div class="modal-header">
<h5 class="modal-title" id="loginModalLabel">Login</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<div class="modal-body">
<div class="mb-3">
<label for="loginIdentifier" class="form-label">Email or Username</label>
<input type="text" class="form-control" id="loginIdentifier" name="username" required />
<div class="invalid-feedback">Please enter your email or username.</div>
</div>
<div class="mb-3">
<label for="loginPassword" class="form-label">Password</label>
<input type="password" class="form-control" id="loginPassword" name="password" required />
<div class="invalid-feedback">Please enter your password.</div>
</div>
</div>
<div class="modal-footer">
<button type="submit" class="btn btn-success">Login</button>
</div>
</form>
</div>
</div>

<!-- Modal: Register -->
<div class="modal fade" id="registerModal" tabindex="-1" aria-labelledby="registerModalLabel" aria-hidden="true">
<div class="modal-dialog">
<form method="post" id="registerForm" class="modal-content needs-validation" novalidate>
<input type="hidden" name="csrf_token" value="<?=sanitize($csrf)?>" />
<input type="hidden" name="action" value="register" />
<div class="modal-header">
<h5 class="modal-title" id="registerModalLabel">Register</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<div class="modal-body">


<div class="mb-3">
<label for="regUsername" class="form-label">Username</label>
<input type="text" class="form-control" id="regUsername" name="username" pattern="^[A-Za-z0-9_]+$" required />
<div class="invalid-feedback">Username can only contain letters, numbers, or underscore (_).</div>
</div>


<div class="mb-3">
<label for="regEmail" class="form-label">Email</label>
<input type="email" class="form-control" id="regEmail" name="email" pattern="[a-zA-Z0-9._%+-]+@gmail\.com" required />
<div class="invalid-feedback">Please enter a valid Gmail address.</div>
</div>


<div class="mb-3">
<label for="regPassword" class="form-label">Password</label>
<input type="password" class="form-control" id="regPassword" name="password" minlength="6" maxlength="10" required />
<div class="invalid-feedback">Password must be between 6 and 10 characters.</div>
</div>


<div class="mb-3">
<label for="regRole" class="form-label">Role</label>
<select class="form-select" id="regRole" name="role" required>
<option value="buyer" selected>Buyer</option>
<option value="farmer">Farmer</option>
</select>
<div class="invalid-feedback">Please select a role.</div>
</div>



<div class="mb-3">
<label for="regPhone" class="form-label">Phone</label>
<input type="text" class="form-control" id="regPhone" name="phone" maxlength="10" required />
<div class="invalid-feedback">Phone number must be exactly 10 digits and start with 6-9.</div>
</div>


<div class="mb-3">
<label for="regDistrict" class="form-label">District</label>
<select id="regDistrict" name="district" class="form-select district-dropdown" required>
<option value="">-- Select District --</option>
<option value="Ariyalur">Ariyalur</option>
<option value="Chengalpattu">Chengalpattu</option>
<option value="Chennai">Chennai</option>
<option value="Coimbatore">Coimbatore</option>
<option value="Cuddalore">Cuddalore</option>
<option value="Dharmapuri">Dharmapuri</option>
<option value="Dindigul">Dindigul</option>
<option value="Erode">Erode</option>
<option value="Kallakurichi">Kallakurichi</option>
<option value="Kancheepuram">Kancheepuram</option>
<option value="Karur">Karur</option>
<option value="Krishnagiri">Krishnagiri</option>
<option value="Madurai">Madurai</option>
<option value="Mayiladuthurai">Mayiladuthurai</option>
<option value="Nagapattinam">Nagapattinam</option>
<option value="Namakkal">Namakkal</option>
<option value="Nilgiris">Nilgiris</option>
<option value="Perambalur">Perambalur</option>
<option value="Pudukkottai">Pudukkottai</option>
<option value="Ramanathapuram">Ramanathapuram</option>
<option value="Ranipet">Ranipet</option>
<option value="Salem">Salem</option>
<option value="Sivaganga">Sivaganga</option>
<option value="Tenkasi">Tenkasi</option>
<option value="Thanjavur">Thanjavur</option>
<option value="Theni">Theni</option>
<option value="Thoothukudi">Thoothukudi</option>
<option value="Tiruchirappalli">Tiruchirappalli</option>
<option value="Tirunelveli">Tirunelveli</option>
<option value="Tirupathur">Tirupathur</option>
<option value="Tiruppur">Tiruppur</option>
<option value="Tiruvallur">Tiruvallur</option>
<option value="Tiruvannamalai">Tiruvannamalai</option>
<option value="Tiruvarur">Tiruvarur</option>
<option value="Vellore">Vellore</option>
<option value="Viluppuram">Viluppuram</option>
<option value="Virudhunagar">Virudhunagar</option>
</select>
<div class="invalid-feedback">Please select your district.</div>
</div>
</div>
<div class="modal-footer">
<button type="submit" class="btn btn-success">Register</button>
</div>
</form>
</div>
</div>

<!-- Modal: Add Post (Farmer) -->
<?php if ($user && $user['role'] === 'farmer'): ?>
<div class="modal fade" id="addPostModal" tabindex="-1" aria-labelledby="addPostModalLabel" aria-hidden="true">
<div class="modal-dialog">
<form method="post" enctype="multipart/form-data" class="modal-content needs-validation" novalidate>
<input type="hidden" name="csrf_token" value="<?=sanitize($csrf)?>" />
<input type="hidden" name="create_post" value="1" />
<div class="modal-header">
<h5 class="modal-title" id="addPostModalLabel">Add New Post</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<div class="modal-body">

<div class="mb-3">
<label for="cropName" class="form-label">Crop Name</label>
<select class="form-select" id="cropName" name="crop_name" required>
<option value="">-- Select Crop --</option>
<option value="Banana">Banana</option>
<option value="Bajra">Bajra</option>
<option value="Coffee">Coffee</option>
<option value="Cotton">Cotton</option>
<option value="Gram">Gram</option>
<option value="Groundnut">Groundnut</option>
<option value="Maize">Maize</option>
<option value="Moong">Moong</option>
<option value="Mustard">Mustard</option>
<option value="Onion">Onion</option>
<option value="Potato">Potato</option>
<option value="Ragi">Ragi</option>
<option value="Rice">Rice</option>
<option value="Sugarcane">Sugarcane</option>
<option value="Sunflower">Sunflower</option>
<option value="Tea">Tea</option>
<option value="Tomato">Tomato</option>
<option value="Tur">Tur</option>
<option value="Urad">Urad</option>
<option value="Wheat">Wheat</option>
</select>
<div class="invalid-feedback">Please select a valid crop.</div>
</div>

<div class="mb-3">
<label for="pricePerUnit" class="form-label">Price per Unit</label>
<div class="input-group">
<span class="input-group-text">₹</span>
<input type="number" class="form-control" id="pricePerUnit" name="price" min="10" max="200" required />
</div>
<div class="invalid-feedback">Price must be between ₹10 and ₹200.</div>
</div>

<div class="mb-3">
<label for="quantity" class="form-label">Quantity</label>
<div class="input-group">
<input type="number" class="form-control" id="quantity" name="quantity" min="1" max="1000" required />
<span class="input-group-text">kg</span>
</div>
<div class="invalid-feedback">Quantity must be between 1 and 1000 kg.</div>
</div>


<div class="mb-3">
<label for="content" class="form-label">Description</label>
<textarea class="form-control" id="content" name="content" rows="3" required></textarea>
<div class="invalid-feedback">Please enter description.</div>
</div>
<div class="mb-3">
<label for="image" class="form-label">Image (jpg, png, gif, webp, max 2MB)</label>
<input type="file" class="form-control" id="image" name="image" accept=".jpg,.jpeg,.png,.gif,.webp" required />
<div class="invalid-feedback">Please upload an image.</div>
</div>
</div>
<div class="modal-footer">
<button type="submit" class="btn btn-success">Create Post</button>
</div>
</form>
</div>
</div>
<?php endif; ?>

<!-- Modal: Contact Requests (Farmer) -->
<?php if ($user && $user['role'] === 'farmer'): ?>
<div class="modal fade" id="contactRequestsModal" tabindex="-1" aria-labelledby="contactRequestsModalLabel" aria-hidden="true">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<div class="modal-header">
<h5 class="modal-title" id="contactRequestsModalLabel">Pending Contact Requests</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<div class="modal-body">
<?php if ($pending_contact_requests && $pending_contact_requests->num_rows > 0): ?>
<ul class="list-group">
<?php while ($r = $pending_contact_requests->fetch_assoc()): ?>
<li class="list-group-item d-flex justify-content-between align-items-center">
<div>
<strong><?=sanitize($r['buyer_name'])?></strong> requested contact for post: <em><?=sanitize($r['post_title'])?></em>
</div>
<form method="post" class="d-inline">
<input type="hidden" name="csrf_token" value="<?=sanitize($csrf)?>" />
<input type="hidden" name="cr_id" value="<?=intval($r['id'])?>" />
<button type="submit" name="handle_contact" value="accepted" class="btn btn-sm btn-success me-1">Accept</button>
<button type="submit" name="handle_contact" value="rejected" class="btn btn-sm btn-danger">Reject</button>
</form>
</li>
<?php endwhile; ?>
</ul>
<?php else: ?>
<p>No pending contact requests.</p>
<?php endif; ?>
</div>
</div>
</div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Show/hide farmer contact info
document.querySelectorAll('.show-contact').forEach(btn => {
btn.addEventListener('click', () => {
const pid = btn.getAttribute('data-post');
const block = document.getElementById('farmerContact-' + pid);
if (block.style.display === 'none' || block.style.display === '') {
block.style.display = 'block';
} else {
block.style.display = 'none';
}
});
});
document.querySelectorAll('.close-contact').forEach(btn => {
btn.addEventListener('click', () => {
btn.parentElement.style.display = 'none';
});
});

// Offcanvas order panel fill
const orderPanel = document.getElementById('orderPanel');
orderPanel.addEventListener('show.bs.offcanvas', event => {
const button = event.relatedTarget;
const postId = button.getAttribute('data-post-id');
const postTitle = button.getAttribute('data-post-title');
const postPrice = button.getAttribute('data-post-price');
const postQuantity = button.getAttribute('data-post-quantity');

document.getElementById('orderPostId').value = postId;
document.getElementById('orderPostTitle').value = postTitle;
document.getElementById('orderPrice').value = postPrice;
const qtyInput = document.getElementById('orderQuantity');
qtyInput.value = '';
qtyInput.max = postQuantity;
});

// Bootstrap form validation
(() => {
'use strict'
const forms = document.querySelectorAll('.needs-validation')
Array.from(forms).forEach(form => {
form.addEventListener('submit', event => {
if (!form.checkValidity()) {
event.preventDefault()
event.stopPropagation()
}
form.classList.add('was-validated')
}, false)
})
})();

// Auto open modals on login/register errors
<?php if(isset($_POST['action']) && ($_POST['action'] === 'login') && !$user && $msg): ?>
var loginModal = new bootstrap.Modal(document.getElementById('loginModal')); loginModal.show();
<?php endif; ?>
<?php if(isset($_POST['action']) && ($_POST['action'] === 'register') && !$user && $msg): ?>
var registerModal = new bootstrap.Modal(document.getElementById('registerModal')); registerModal.show();
<?php endif; ?>
</script>
<script type="text/javascript">
function googleTranslateElementInit() {
new google.translate.TranslateElement(
{pageLanguage: 'en'},
'google_translate_element'
);
}
</script>

<script type="text/javascript"
src="//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit">
</script>


<script>
const form = document.getElementById('registerForm');
const phoneInput = document.getElementById('regPhone');

// Real-time input: allow only digits, max 10
phoneInput.addEventListener('input', function() {
this.value = this.value.replace(/\D/g,''); // remove non-digits
if (this.value.length > 10) this.value = this.value.slice(0,10);
});

// On form submit: advanced validation
form.addEventListener('submit', function(event) {
const phone = phoneInput.value;

// Must be 10 digits and start with 6-9, not all same digits
if (!/^[6-9]\d{9}$/.test(phone) || /^(\d)\1{9}$/.test(phone) || /^1234567890$/.test(phone)) {
event.preventDefault();
event.stopPropagation();
alert("Enter a valid Indian phone number (10 digits, starts with 6-9, no repeating sequences).");
form.classList.add('was-validated');
return false;
}

if (!form.checkValidity()) {
event.preventDefault();
event.stopPropagation();
}

form.classList.add('was-validated');
});
</script>

<script>
document.addEventListener("DOMContentLoaded", function () {
const priceInput = document.getElementById("pricePerUnit");
const priceError = document.getElementById("priceError");

const quantityInput = document.getElementById("quantity");
const quantityError = document.getElementById("quantityError");

// Live validation for Price
priceInput.addEventListener("input", function () {
let value = parseInt(priceInput.value);
if (value < 10 || value > 200 || isNaN(value)) {
priceInput.classList.add("is-invalid");
priceError.style.display = "block";
} else {
priceInput.classList.remove("is-invalid");
priceError.style.display = "none";
}
});

// Live validation for Quantity
quantityInput.addEventListener("input", function () {
let value = parseInt(quantityInput.value);
if (value < 1 || value > 1000 || isNaN(value)) {
quantityInput.classList.add("is-invalid");
quantityError.style.display = "block";
} else {
quantityInput.classList.remove("is-invalid");
quantityError.style.display = "none";
}
});
});
</script>




</body>
</html>
