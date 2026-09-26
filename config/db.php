<?php
// htdocs/config/db.php

$host = getenv('DB_HOST') ?: "localhost";
$username = getenv('DB_USER') ?: "root";
$password = getenv('DB_PASS') ?: "";
$dbname = getenv('DB_NAME') ?: "autocare";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    // Enable exceptions for errors and set fetch mode to associative arrays
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Include schema creation
    require_once __DIR__ . '/schema.php';
} catch (PDOException $e) {
    die("Database Connection failed. Please check your credentials.");
}
?>
