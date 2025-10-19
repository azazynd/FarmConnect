<?php
$host = 'localhost';
$user = 'root';
$password = '';
$db = 'project';

$conn = new mysqli($host, $user, $password, $db);
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
?>
