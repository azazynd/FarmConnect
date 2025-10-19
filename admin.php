<?php
require 'db_connection.php';

// --- Ensure 'crop_prices' column exists in weather_market ---
$checkColumn = $conn->query("SHOW COLUMNS FROM `weather_market` LIKE 'crop_prices'");
if ($checkColumn->num_rows === 0) {
$conn->query("ALTER TABLE `weather_market` ADD COLUMN `crop_prices` TEXT NOT NULL DEFAULT '{}'");
}


session_start();

// --- ADMIN LOGIN ---
if (isset($_POST['action']) && $_POST['action'] === 'login') {
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
if ($username === 'admin' && $password === 'admin123') {
$_SESSION['admin_logged_in'] = true;
header("Location: admin.php");
exit;
} else {
$error = "Invalid admin credentials.";
}
}

// --- LOGOUT ---
if (isset($_GET['logout'])) {
session_destroy();
header("Location: admin.php");
exit;
}

// --- Admin login check ---
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Admin Login</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
</head>
<body class="bg-light d-flex align-items-center" style="height:100vh;">
<div class="container">
<div class="row justify-content-center">
<div class="col-md-4">
<div class="card shadow-sm">
<div class="card-body">
<h3 class="card-title mb-3 text-center">Admin Login</h3>
<?php if (!empty($error)): ?>
<div class="alert alert-danger"><?=htmlspecialchars($error)?></div>
<?php endif; ?>
<form method="post">
<input type="hidden" name="action" value="login"/>
<div class="mb-3">
<label>Username</label>
<input type="text" name="username" class="form-control" required autofocus>
</div>
<div class="mb-3">
<label>Password</label>
<input type="password" name="password" class="form-control" required>
</div>
<button type="submit" class="btn btn-primary w-100">Login</button>
</form>
</div>
</div>
</div>
</div>
</div>
</body>
</html>
<?php
exit;
}

// CSRF token
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

// --- DELETE RECORD ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
if (!hash_equals($csrf_token, $_POST['csrf_token'])) {
$msg = "Invalid CSRF token.";
} else {
$table = $_POST['table'];
$id = intval($_POST['id']);
$allowed_tables = ['users','posts','ratings','comments','contact_requests','orders','weather_market'];
if (!in_array($table, $allowed_tables)) $msg = "Invalid table.";
else {
$stmt = $conn->prepare("DELETE FROM `$table` WHERE id=?");
if ($stmt) {
$stmt->bind_param("i",$id);
$stmt->execute();
$stmt->close();
$msg = "Deleted record ID $id from $table.";
}
}
}
}

// --- UPDATE WEATHER & CROP PRICES ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_weather'])) {
if (!hash_equals($csrf_token, $_POST['csrf_token'])) {
$msg = "Invalid CSRF token.";
} else {
$weather_val = trim($_POST['weather'] ?? '0');
if ($weather_val === '') $weather_val = '0';

$prices_json = trim($_POST['prices'] ?? '{}');
$crop_prices = json_decode($prices_json, true) ?: [];

$check = $conn->query("SELECT id FROM weather_market ORDER BY id DESC LIMIT 1");
$prices_serialized = json_encode($crop_prices);
if ($check && $check->num_rows > 0) {
$row = $check->fetch_assoc();
$stmt = $conn->prepare("UPDATE weather_market
SET weather=?, crop_prices=?, updated_at=NOW()
WHERE id=?");
$stmt->bind_param("ssi", $weather_val, $prices_serialized, $row['id']);
$stmt->execute();
$stmt->close();
$msg = "Weather and crop prices updated successfully.";
} else {
$stmt = $conn->prepare("INSERT INTO weather_market (weather, crop_prices, updated_at)
VALUES (?, ?, NOW())");
$stmt->bind_param("ss", $weather_val, $prices_serialized);
$stmt->execute();
$stmt->close();
$msg = "Weather and crop prices inserted successfully.";
}
}
}

// --- Fetch summary counts ---
$tables = ['users','posts','orders','ratings','comments','contact_requests','weather_market'];
$counts = [];
$all_data = [];
foreach($tables as $table){
$res = $conn->query("SELECT COUNT(*) as cnt FROM `$table`");
$row = $res->fetch_assoc();
$counts[$table] = $row['cnt'] ?? 0;

$res2 = $conn->query("SELECT * FROM `$table` ORDER BY id DESC LIMIT 50");
$all_data[$table] = $res2 ? $res2->fetch_all(MYSQLI_ASSOC) : [];
}

// --- Fetch latest weather ---
$weather = $conn->query("SELECT * FROM `weather_market` ORDER BY id DESC LIMIT 1")->fetch_assoc();
$crop_prices = json_decode($weather['crop_prices'] ?? '{}', true);
if (!is_array($crop_prices)) $crop_prices = [];

$columns_map = [
'users'=>['id','username','email','role','phone','city','created_at'],
'posts'=>['id','farmer_id','title','crop_name','price','quantity','status','created_at'],
'orders'=>['id','post_id','buyer_id','farmer_id','price','quantity','status','created_at'],
'ratings'=>['id','buyer_id','farmer_id','post_id','rating','status','created_at'],
'comments'=>['id','post_id','user_id','comment','status','created_at'],
'contact_requests'=>['id','buyer_id','farmer_id','post_id','status','created_at'],
'weather_market'=>['id','weather','crop_prices','updated_at']
];

$all_crops = ["Banana","Bajra","Coffee","Cotton","Gram","Groundnut","Maize","Moong","Mustard","Onion","Potato","Ragi","Rice","Sugarcane","Sunflower","Tea","Tomato","Tur","Urad","Wheat"];
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Admin Dashboard - Farm Connect</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
<style>
body { background: #f0f2f5; }
.card { border-radius: 15px; }
.table-responsive { max-height: 400px; overflow-y:auto; }
</style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
<div class="container-fluid">
<a class="navbar-brand" href="#">Farm Connect Admin</a>
<div class="d-flex">
<a href="admin.php?logout=1" class="btn btn-outline-light">Logout</a>
</div>
</div>
</nav>

<div class="container my-4">
<?php if(!empty($msg)): ?>
<div class="alert alert-info"><?=htmlspecialchars($msg)?></div>
<?php endif; ?>

<h2 class="mb-4">Dashboard Overview</h2>
<div class="row g-3 mb-4">
<?php foreach(['users'=>'primary','posts'=>'success','orders'=>'warning','ratings'=>'danger'] as $t=>$color): ?>
<div class="col-md-3">
<div class="card text-white bg-<?=$color?>">
<div class="card-body">
<h5 class="card-title"><?=ucfirst($t)?></h5>
<p class="card-text fs-3"><?=$counts[$t]?></p>
</div>
</div>
</div>
<?php endforeach; ?>
</div>

<!-- Weather & Crop Prices Update -->
<div class="card shadow-sm mb-4">
<div class="card-header bg-info text-white">Latest Update</div>
<div class="card-body">
<p><strong>Weather:</strong> <?=htmlspecialchars($weather['weather'] ?? '0')?></p>
<p><strong>Updated At:</strong> <?=htmlspecialchars($weather['updated_at'] ?? '')?></p>

<hr>
<h5>Update Weather & Crop Prices</h5>
<div class="row g-2">
<input type="hidden" id="csrf_token" value="<?=htmlspecialchars($csrf_token)?>"/>
<div class="col-md-4">
<label for="weather">Weather:</label>
<select id="weather" class="form-select">
<option value="Sunny" <?=($weather['weather']??'')==='Sunny'?'selected':''?>>Sunny</option>
<option value="Rainy" <?=($weather['weather']??'')==='Rainy'?'selected':''?>>Rainy</option>
<option value="Cloudy" <?=($weather['weather']??'')==='Cloudy'?'selected':''?>>Cloudy</option>
</select>
</div>
<div class="col-md-4 mt-2">
<label for="crop">Select Crop:</label>
<select id="crop" class="form-select" onchange="loadPrice()">
<option value="">-- Select Crop --</option>
<?php foreach($all_crops as $c): ?>
<option value="<?=htmlspecialchars($c)?>"><?=htmlspecialchars($c)?></option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-4 mt-2">
<label for="price">Price per kg:</label>
<input type="number" id="price" class="form-control" placeholder="Enter Price" min="1" max="300">
</div>
<div class="col-md-4 mt-4">
<button type="button" class="btn btn-primary w-100" onclick="updateData()">Update</button>
</div>
</div>

<hr>
<h5>Current Crop Prices</h5>
<div class="table-responsive">
<table class="table table-bordered table-sm">
<thead class="table-dark">
<tr><th>Crop</th><th>Price per kg (â‚¹)</th></tr>
</thead>
<tbody>
<?php foreach($all_crops as $crop):
$price = $crop_prices[$crop] ?? '-';
?>
<tr>
<td><?=htmlspecialchars($crop)?></td>
<td><?=htmlspecialchars($price)?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
</div>

<!-- All Tables -->
<h3>Manage Records</h3>
<div class="accordion" id="adminAccordion">
<?php foreach($tables as $table): ?>
<div class="accordion-item">
<h2 class="accordion-header" id="heading-<?=$table?>">
<button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-<?=$table?>">
<?=ucfirst($table)?> (<?=count($all_data[$table])?>)
</button>
</h2>
<div id="collapse-<?=$table?>" class="accordion-collapse collapse" data-bs-parent="#adminAccordion">
<div class="accordion-body p-0">
<?php if(count($all_data[$table])===0): ?>
<p class="m-3 text-muted">No records found.</p>
<?php else: ?>
<div class="table-responsive">
<table class="table table-striped table-bordered table-sm mb-0">
<thead class="table-dark">
<tr>
<?php foreach($columns_map[$table] as $col): ?><th><?=htmlspecialchars($col)?></th><?php endforeach; ?>
<th>Action</th>
</tr>
</thead>
<tbody>
<?php foreach($all_data[$table] as $row): ?>
<tr>
<?php foreach($columns_map[$table] as $col): ?>
<td><?=htmlspecialchars($row[$col] ?? '')?></td>
<?php endforeach; ?>
<td>
<form method="post" style="display:inline;" onsubmit="return confirm('Delete record ID <?=$row['id']?>?');">
<input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf_token)?>"/>
<input type="hidden" name="table" value="<?=htmlspecialchars($table)?>"/>
<input type="hidden" name="id" value="<?=htmlspecialchars($row['id'])?>"/>
<button type="submit" name="delete" class="btn btn-sm btn-danger">Delete</button>
</form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</div>
</div>
</div>
<?php endforeach; ?>
</div>

<script>
// Initialize crop prices
let cropPrices = <?=json_encode($crop_prices)?>;

function loadPrice() {
const crop = document.getElementById("crop").value;
document.getElementById("price").value = cropPrices[crop] || '';
}

function updateData() {
const crop = document.getElementById("crop").value;
let price = parseFloat(document.getElementById("price").value);
const weather = document.getElementById("weather").value;
const csrf = document.getElementById("csrf_token").value;

if (crop) {
if (isNaN(price)) { alert("Please enter a valid price."); return; }
if (price > 300) price = 300;
cropPrices[crop] = price;
}

const xhr = new XMLHttpRequest();
xhr.open("POST", "admin.php", true);
xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
xhr.onreadystatechange = function() {
if(xhr.readyState === 4 && xhr.status === 200) {
alert("Updated successfully!");
location.reload();
}
};
xhr.send("update_weather=1&csrf_token=" + encodeURIComponent(csrf) +
"&weather=" + encodeURIComponent(weather) +
"&prices=" + encodeURIComponent(JSON.stringify(cropPrices)));
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>