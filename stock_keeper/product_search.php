<?php
// File: product_search.php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['stock_keeper_id'])) {
    header("Location: ../index.php");
    exit();
}

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "kidsberry";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';

$sql = "SELECT * FROM products WHERE 1=1";
if (!empty($search)) {
    $sql .= " AND (name LIKE '%$search%' OR category LIKE '%$search%' OR barcode LIKE '%$search%' OR product_id LIKE '%$search%')";
}
$sql .= " ORDER BY name LIMIT 20";

$result = $conn->query($sql);

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo '<div class="product-item" onclick="selectProduct(\'' . $row['product_id'] . '\', \'' . htmlspecialchars($row['name']) . '\')">';
        if ($row['photo']) {
            echo '<img src="' . htmlspecialchars($row['photo']) . '" class="product-photo">';
        } else {
            echo '<div class="product-photo" style="background: #e8d4f7; display: flex; align-items: center; justify-content: center;">';
            echo '<i class="fas fa-image" style="font-size: 20px; color: #8e44ad;"></i>';
            echo '</div>';
        }
        echo '<div class="product-details">';
        echo '<div class="product-name">' . htmlspecialchars($row['name']) . '</div>';
        echo '<div class="product-id">ID: ' . $row['product_id'] . ' | Category: ' . htmlspecialchars($row['category']) . '</div>';
        echo '</div>';
        echo '<div class="product-price">Rs. ' . number_format($row['sale_price'], 2) . '</div>';
        echo '</div>';
    }
} else {
    echo '<div class="empty-state" style="padding: 20px;">';
    echo '<i class="fas fa-search"></i>';
    echo '<h3>No Products Found</h3>';
    echo '<p>Try a different search term</p>';
    echo '</div>';
}

$conn->close();
?>