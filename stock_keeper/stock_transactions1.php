<?php
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set timezone to Sri Lanka
date_default_timezone_set('Asia/Colombo');

// Redirect to login if not logged in
if (!isset($_SESSION['stock_keeper_id'])) {
    header("Location: ../index.php");
    exit();
}

// Handle logout
if (isset($_GET['logout'])) {
    unset($_SESSION['stock_keeper_id']);
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

// Get stock keeper ID from session
$stock_keeper_id = $_SESSION['stock_keeper_id'];

// ========== CREATE TABLES IF THEY DON'T EXIST ==========
// Create stock_transactions table (only for transfers)
$conn->query("
    CREATE TABLE IF NOT EXISTS stock_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transaction_type ENUM('transfer') NOT NULL DEFAULT 'transfer',
        product_id VARCHAR(50) NOT NULL,
        product_name VARCHAR(255) NOT NULL,
        product_barcode VARCHAR(100),
        from_branch VARCHAR(20),
        to_branch VARCHAR(20),
        quantity INT NOT NULL,
        notes TEXT NULL,
        created_by VARCHAR(50) NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX (product_id),
        INDEX (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ==================== PHP BACKEND (AJAX) ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';

    // ========== STOCK TRANSFER BETWEEN BRANCHES ==========
    if ($action === 'transfer_stock') {
        $product_id = $conn->real_escape_string($_POST['product_id']);
        $product_name = $conn->real_escape_string($_POST['product_name']);
        $product_barcode = $conn->real_escape_string($_POST['product_barcode'] ?? '');
        $from_branch = $conn->real_escape_string($_POST['from_branch']);
        $to_branch = $conn->real_escape_string($_POST['to_branch']);
        $quantity = intval($_POST['quantity']);
        $notes = $conn->real_escape_string($_POST['notes'] ?? '');
        
        // Validate
        if ($quantity <= 0) {
            echo json_encode(['success' => false, 'message' => 'Quantity must be greater than zero']);
            exit;
        }
        
        if ($from_branch === $to_branch) {
            echo json_encode(['success' => false, 'message' => 'Source and destination branches cannot be the same']);
            exit;
        }
        
        // Determine source and destination tables
        $from_table = ($from_branch == 'branch1') ? 'products' : 'products2';
        $to_table = ($to_branch == 'branch1') ? 'products' : 'products2';
        
        // Check if product exists in source branch
        $check_sql = "SELECT * FROM $from_table WHERE product_id = '$product_id'";
        $check_result = $conn->query($check_sql);
        
        if ($check_result->num_rows == 0) {
            echo json_encode(['success' => false, 'message' => 'Product not found in source branch']);
            exit;
        }
        
        $source_product = $check_result->fetch_assoc();
        
        // Check if enough stock
        if ($source_product['stock'] < $quantity) {
            echo json_encode(['success' => false, 'message' => "Insufficient stock. Available: {$source_product['stock']}"]);
            exit;
        }
        
        // Begin transaction
        $conn->begin_transaction();
        
        try {
            // Reduce stock from source branch
            $update_source_sql = "UPDATE $from_table SET stock = stock - $quantity WHERE product_id = '$product_id'";
            $conn->query($update_source_sql);
            
            // Check if product exists in destination branch
            $check_dest_sql = "SELECT * FROM $to_table WHERE product_id = '$product_id'";
            $check_dest_result = $conn->query($check_dest_sql);
            
            if ($check_dest_result->num_rows > 0) {
                // Update existing product in destination
                $update_dest_sql = "UPDATE $to_table SET stock = stock + $quantity WHERE product_id = '$product_id'";
                $conn->query($update_dest_sql);
            } else {
                // Insert new product in destination with same details
                $insert_sql = "INSERT INTO $to_table 
                    (product_id, name, category, supplier_id, original_price, sale_price, stock, barcode, color, photo, specific_letter, sold, last_sold) 
                    VALUES (
                        '$product_id', 
                        '{$source_product['name']}', 
                        '{$source_product['category']}', 
                        " . ($source_product['supplier_id'] ? "'{$source_product['supplier_id']}'" : "NULL") . ", 
                        {$source_product['original_price']}, 
                        {$source_product['sale_price']}, 
                        $quantity, 
                        '{$source_product['barcode']}', 
                        '{$source_product['color']}', 
                        '{$source_product['photo']}', 
                        '{$source_product['specific_letter']}', 
                        0, 
                        NULL
                    )";
                $conn->query($insert_sql);
            }
            
            // Record transaction in stock_transactions table
            $transaction_sql = "INSERT INTO stock_transactions 
                (transaction_type, product_id, product_name, product_barcode, from_branch, to_branch, quantity, notes, created_by, created_at) 
                VALUES 
                ('transfer', '$product_id', '$product_name', '$product_barcode', '$from_branch', '$to_branch', $quantity, '$notes', '$stock_keeper_id', NOW())";
            $conn->query($transaction_sql);
            
            $conn->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => "Stock transferred successfully! $quantity units moved from " . 
                            ($from_branch == 'branch1' ? 'Branch 1' : 'Branch 2') . " to " . 
                            ($to_branch == 'branch1' ? 'Branch 1' : 'Branch 2')
            ]);
            
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Transaction failed: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // ========== SEARCH PRODUCTS ==========
    if ($action === 'search_products') {
        $search = $conn->real_escape_string($_POST['search'] ?? '');
        $branch = $conn->real_escape_string($_POST['branch'] ?? 'branch1');
        
        $table = ($branch == 'branch1') ? 'products' : 'products2';
        
        $sql = "SELECT p.*, s.supplier_name 
                FROM $table p 
                LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id 
                WHERE p.name LIKE '%$search%' 
                   OR p.product_id LIKE '%$search%' 
                   OR p.barcode LIKE '%$search%'
                ORDER BY p.name LIMIT 20";
        
        $result = $conn->query($sql);
        
        $products = [];
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
        
        echo json_encode(['success' => true, 'products' => $products]);
        exit;
    }
    
    // ========== GET TRANSACTIONS ==========
    if ($action === 'get_transactions') {
        $limit = intval($_POST['limit'] ?? 50);
        
        $sql = "SELECT t.*, 
                CASE 
                    WHEN t.from_branch = 'branch1' THEN 'Branch 1' 
                    WHEN t.from_branch = 'branch2' THEN 'Branch 2' 
                    ELSE t.from_branch 
                END as branch_name,
                CASE 
                    WHEN t.to_branch = 'branch1' THEN 'Branch 1' 
                    WHEN t.to_branch = 'branch2' THEN 'Branch 2' 
                    ELSE t.to_branch 
                END as dest_branch_name,
                DATE_FORMAT(t.created_at, '%Y-%m-%d %H:%i:%s') as formatted_date,
                DATE_FORMAT(t.created_at, '%h:%i %p') as time_only,
                DATE_FORMAT(t.created_at, '%d/%m/%Y') as date_only
                FROM stock_transactions t
                ORDER BY t.created_at DESC 
                LIMIT $limit";
        
        $result = $conn->query($sql);
        
        if (!$result) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
            exit;
        }
        
        $transactions = [];
        while ($row = $result->fetch_assoc()) {
            $transactions[] = $row;
        }
        
        echo json_encode(['success' => true, 'transactions' => $transactions]);
        exit;
    }
    
    // ========== GENERATE REPORT ==========
    if ($action === 'generate_report') {
        $days = intval($_POST['days'] ?? 30);
        
        $sql = "SELECT t.*, 
                CASE 
                    WHEN t.from_branch = 'branch1' THEN 'Branch 1' 
                    WHEN t.from_branch = 'branch2' THEN 'Branch 2' 
                    ELSE t.from_branch 
                END as branch_name,
                CASE 
                    WHEN t.to_branch = 'branch1' THEN 'Branch 1' 
                    WHEN t.to_branch = 'branch2' THEN 'Branch 2' 
                    ELSE t.to_branch 
                END as dest_branch_name,
                DATE_FORMAT(t.created_at, '%Y-%m-%d %H:%i:%s') as formatted_date
                FROM stock_transactions t
                WHERE t.created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)
                ORDER BY t.created_at DESC";
        
        $result = $conn->query($sql);
        
        if (!$result) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
            exit;
        }
        
        $transactions = [];
        while ($row = $result->fetch_assoc()) {
            $transactions[] = $row;
        }
        
        echo json_encode(['success' => true, 'transactions' => $transactions]);
        exit;
    }
}

// Helper function to format date for Sri Lanka time
function formatSriLankaTime($datetime) {
    $timestamp = strtotime($datetime);
    return date('d/m/Y h:i A', $timestamp);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Stock Transfer - Kids Berry</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-purple: #8e44ad;
            --light-purple: #e8d4f7;
            --dark-purple: #6c3483;
            --primary-green: #27ae60;
            --light-green: #d5f5e3;
            --dark-green: #229954;
            --primary-blue: #3498db;
            --light-blue: #d6eaf8;
            --primary-orange: #e67e22;
            --light-orange: #fdebd0;
            --primary-red: #e74c3c;
            --light-red: #fadbd8;
            --primary-pink: #e84393;
            --light-pink: #fd79a8;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #495057;
            --shadow-light: 0 2px 8px rgba(0,0,0,0.08);
            --shadow-medium: 0 4px 15px rgba(0,0,0,0.12);
            --shadow-heavy: 0 8px 25px rgba(0,0,0,0.15);
            --border-radius: 12px;
            --border-radius-small: 8px;
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            --gradient-primary: linear-gradient(135deg, var(--primary-purple), var(--dark-purple));
            --gradient-secondary: linear-gradient(135deg, var(--primary-green), var(--dark-green));
            --gradient-blue: linear-gradient(135deg, var(--primary-blue), #2980b9);
            --gradient-orange: linear-gradient(135deg, var(--primary-orange), #d35400);
            --gradient-red: linear-gradient(135deg, var(--primary-red), #c0392b);
            --gradient-pink: linear-gradient(135deg, var(--primary-pink), #fd79a8);
            --gradient-light: linear-gradient(135deg, var(--light-purple), var(--light-green));
        }
        
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
            -webkit-tap-highlight-color: transparent;
        }
        
        html {
            height: 100%;
            overflow-x: hidden;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--gradient-light);
            min-height: 100vh;
            color: var(--dark-gray);
            line-height: 1.5;
            overflow-x: hidden;
            padding-bottom: env(safe-area-inset-bottom);
        }
        
        .container { 
            max-width: 100%; 
            margin: 0 auto; 
            padding: 10px; 
        }
        
        /* Header Styles - Mobile First */
        header {
            background: var(--gradient-primary);
            color: var(--white);
            padding: 0.8rem 0;
            box-shadow: var(--shadow-medium);
            position: sticky; 
            top: 0; 
            z-index: 1000;
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }
        
        .menu { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            flex-wrap: nowrap; 
            gap: 0.5rem; 
            overflow-x: auto;
            padding: 0 5px;
            -webkit-overflow-scrolling: touch;
        }
        
        .logo { 
            font-size: 1.4rem; 
            font-weight: 800; 
            display: flex; 
            align-items: center; 
            gap: 8px; 
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
            white-space: nowrap;
            flex-shrink: 0;
        }
        
        .nav-links {
            display: flex;
            flex-wrap: nowrap;
            gap: 0.3rem;
            align-items: center;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            padding: 5px 0;
        }
        
        .nav-links a {
            color: var(--white); 
            text-decoration: none; 
            padding: 8px 12px; 
            border-radius: 50px;
            transition: var(--transition); 
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255,255,255,0.2);
            white-space: nowrap;
            flex-shrink: 0;
        }
        
        .nav-links a:hover, 
        .nav-links a.active {
            background: var(--light-green); 
            color: var(--dark-purple); 
            transform: translateY(-2px);
            box-shadow: var(--shadow-light);
        }
        
        /* Logout Button Special Style */
        .logout-btn {
            background: var(--gradient-pink) !important;
            color: white !important;
            border: 1px solid rgba(255,255,255,0.3) !important;
            position: relative;
            overflow: hidden;
        }
        
        .logout-btn:hover {
            transform: translateY(-2px) scale(1.03);
            box-shadow: 0 5px 15px rgba(232, 67, 147, 0.4);
        }
        
        .logout-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s;
        }
        
        .logout-btn:hover::before {
            left: 100%;
        }
        
        /* Welcome Section */
        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 18px;
            box-shadow: var(--shadow-light);
            transition: var(--transition);
            border: 1px solid var(--medium-gray);
            animation: fadeIn 0.5s ease-out;
            position: relative;
            overflow: hidden;
        }
        
        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--gradient-primary);
        }
        
        .card:hover {
            box-shadow: var(--shadow-medium);
            transform: translateY(-3px);
        }
        
        .welcome-section {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-bottom: 10px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.1rem;
            box-shadow: var(--shadow-light);
            flex-shrink: 0;
        }
        
        h2 { 
            color: var(--primary-purple); 
            margin-bottom: 15px; 
            font-size: 1.4rem; 
            display: flex; 
            align-items: center; 
            gap: 10px; 
            font-weight: 700;
        }
        
        h3 {
            color: var(--dark-gray);
            margin-bottom: 12px;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* Main Grid */
        .main-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        @media (min-width: 992px) {
            .main-grid {
                grid-template-columns: 1fr 1.2fr;
            }
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark-purple);
            font-size: 0.9rem;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 14px 18px;
            border-radius: var(--border-radius-small);
            border: 2px solid var(--medium-gray);
            font-size: 0.95rem;
            background: var(--white);
            transition: var(--transition);
            outline: none;
            font-family: inherit;
        }
        
        .form-group textarea {
            border-radius: var(--border-radius-small);
            resize: vertical;
            min-height: 80px;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--primary-purple);
            box-shadow: 0 0 0 3px rgba(142, 68, 173, 0.1);
        }
        
        .form-group input[readonly] {
            background: var(--light-gray);
            cursor: not-allowed;
        }
        
        /* Branch Toggle */
        .branch-toggle {
            display: flex;
            gap: 10px;
            margin: 10px 0;
            flex-wrap: wrap;
        }
        
        .branch-btn {
            flex: 1;
            padding: 12px 15px;
            border-radius: var(--border-radius-small);
            border: 2px solid var(--medium-gray);
            background: var(--white);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: var(--dark-gray);
        }
        
        .branch-btn.active {
            background: var(--gradient-primary);
            color: var(--white);
            border-color: var(--primary-purple);
            transform: translateY(-2px);
            box-shadow: var(--shadow-light);
        }
        
        .branch-btn i {
            font-size: 1rem;
        }
        
        /* Product Search */
        .product-search-container {
            position: relative;
        }
        
        .product-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: var(--white);
            border: 2px solid var(--medium-gray);
            border-radius: var(--border-radius-small);
            max-height: 300px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: var(--shadow-medium);
            border-top: none;
            border-top-left-radius: 0;
            border-top-right-radius: 0;
        }
        
        .product-option {
            padding: 12px 15px;
            cursor: pointer;
            border-bottom: 1px solid var(--medium-gray);
            transition: var(--transition);
        }
        
        .product-option:hover {
            background: var(--light-purple);
        }
        
        .product-option:last-child {
            border-bottom: none;
        }
        
        .product-option strong {
            color: var(--primary-purple);
        }
        
        .product-option small {
            color: var(--dark-gray);
            font-size: 0.8rem;
        }
        
        /* Button Styles */
        .btn {
            padding: 16px 24px;
            border-radius: var(--border-radius-small);
            font-size: 1rem;
            font-weight: 700;
            border: none;
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            width: 100%;
            transition: var(--transition);
            cursor: pointer;
            background: var(--gradient-primary);
            box-shadow: var(--shadow-light);
        }
        
        .btn:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-medium);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .btn-report {
            background: var(--gradient-secondary);
        }
        
        .btn i {
            font-size: 1.1rem;
        }
        
        /* Info Box */
        .info-box {
            background: var(--light-blue);
            padding: 15px;
            border-radius: var(--border-radius-small);
            margin-bottom: 20px;
            border-left: 4px solid var(--primary-blue);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-box i {
            color: var(--primary-blue);
            font-size: 1.2rem;
        }
        
        /* Transactions Container */
        .transactions-container {
            max-height: 500px;
            overflow-y: auto;
            border-radius: var(--border-radius);
            padding: 5px;
        }
        
        .transaction-item {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 18px;
            margin-bottom: 12px;
            border-left: 5px solid var(--primary-purple);
            box-shadow: var(--shadow-light);
            transition: var(--transition);
            border: 1px solid var(--medium-gray);
            animation: fadeIn 0.5s ease-out;
        }
        
        .transaction-item:hover {
            box-shadow: var(--shadow-medium);
            transform: translateY(-2px);
        }
        
        .transaction-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 10px;
            padding-bottom: 8px;
            border-bottom: 1px dashed var(--medium-gray);
        }
        
        .transaction-type {
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 20px;
            background: var(--light-purple);
            color: var(--primary-purple);
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .transaction-time {
            font-size: 0.85rem;
            color: var(--dark-gray);
            font-weight: 500;
            background: var(--light-gray);
            padding: 4px 10px;
            border-radius: 20px;
        }
        
        .transaction-product {
            font-weight: 700;
            color: var(--primary-purple);
            font-size: 1.1rem;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .transaction-barcode {
            color: var(--dark-gray);
            font-size: 0.85rem;
            margin-bottom: 8px;
            padding: 4px 10px;
            background: var(--light-gray);
            border-radius: 20px;
            display: inline-block;
        }
        
        .transaction-quantity {
            font-weight: 700;
            color: var(--primary-green);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .transaction-meta {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin: 12px 0;
            padding: 8px 0;
        }
        
        .transaction-meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
            background: var(--light-gray);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
        }
        
        .transaction-meta-item i {
            color: var(--primary-purple);
        }
        
        .transaction-notes {
            margin-top: 8px;
            padding: 8px 12px;
            background: var(--light-gray);
            border-radius: var(--border-radius-small);
            font-size: 0.85rem;
            border-left: 3px solid var(--primary-orange);
        }
        
        /* Report Section */
        .report-section {
            margin-top: 25px;
            padding: 20px;
            background: var(--light-green);
            border-radius: var(--border-radius);
        }
        
        .report-controls {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .report-controls select {
            flex: 1;
            min-width: 150px;
            padding: 12px 15px;
            border-radius: var(--border-radius-small);
            border: 2px solid var(--medium-gray);
            font-size: 0.95rem;
            background: var(--white);
        }
        
        /* Spinner */
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 0.8s linear infinite;
        }
        
        @keyframes spin { 
            to { transform: rotate(360deg); } 
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 15px;
            color: var(--dark-gray);
        }
        
        .empty-state i {
            font-size: 3rem;
            color: var(--light-purple);
            margin-bottom: 12px;
        }
        
        .empty-state h3 {
            font-size: 1.1rem;
            margin-bottom: 8px;
            color: var(--primary-purple);
            justify-content: center;
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Card Header */
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--light-purple);
            padding-bottom: 12px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .card-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-purple);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card-title i {
            color: var(--primary-purple);
        }
        
        /* Touch-friendly improvements */
        @media (hover: none) and (pointer: coarse) {
            .card:hover {
                transform: none;
            }
            
            .transaction-item:hover {
                transform: none;
            }
            
            .btn:hover {
                transform: none;
            }
            
            .nav-links a:hover {
                transform: none;
            }
        }
        
        /* Tablet and Desktop Styles */
        @media (min-width: 768px) {
            .container {
                padding: 15px;
                max-width: 1400px;
            }
            
            header {
                padding: 1rem 0;
            }
            
            .menu {
                gap: 1rem;
                padding: 0;
                overflow-x: visible;
            }
            
            .logo {
                font-size: 1.8rem;
                gap: 12px;
            }
            
            .nav-links {
                gap: 0.5rem;
                overflow-x: visible;
                padding: 0;
            }
            
            .nav-links a {
                padding: 10px 18px;
                gap: 8px;
                font-size: 0.9rem;
            }
            
            .card {
                padding: 25px;
            }
            
            h2 {
                font-size: 1.6rem;
                margin-bottom: 20px;
            }
            
            .welcome-section {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
        }
    </style>
</head>
<body>

<header>
    <div class="container menu">
        <div class="logo"><i class="fas fa-baby"></i> Kids Berry</div>
        <div class="nav-links">
            <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="product.php"><i class="fas fa-box"></i> Inventory</a>
            <a href="barcode.php"><i class="fas fa-barcode"></i> Barcode</a>
            <a href="customershow.php"><i class="fas fa-users"></i> Customers</a>
            <a href="suppliers.php"><i class="fas fa-truck"></i> Suppliers</a>
            <a href="stock_transactions1.php" class="active"><i class="fas fa-arrows-spin"></i> Stock Transfer</a>
            <a href="report_keep.php"><i class="fas fa-chart-line"></i> Reports</a>
            <a href="?logout=true" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>
</header>

<div class="container">
    <!-- Welcome Section -->
    <div class="card" style="margin-bottom: 15px;">
        <div class="welcome-section">
            <div>
                <h2><i class="fas fa-arrows-spin"></i> Stock Transfer Management</h2>
                <p>Welcome back, <?php echo htmlspecialchars($_SESSION['stock_keeper_name'] ?? 'Stock Keeper'); ?>! Transfer stock between branches.</p>
            </div>
            <div class="user-info">
                <div class="user-avatar">
                    <i class="fas fa-user"></i>
                </div>
                <div>
                    <strong><?php echo htmlspecialchars($_SESSION['stock_keeper_name'] ?? 'Stock Keeper'); ?></strong>
                    <div style="font-size: 0.8rem; opacity: 0.7;">Stock Keeper</div>
                </div>
            </div>
        </div>
    </div>

    <div class="main-grid">
        <!-- New Stock Transfer Card -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-arrow-right-arrow-left"></i> New Stock Transfer
                </div>
            </div>

            <div class="info-box">
                <i class="fas fa-info-circle"></i> Move stock from one branch to another. Stock will be reduced from source and added to destination.
            </div>

            <form id="transferForm">
                <input type="hidden" name="action" value="transfer_stock">
                
                <div class="form-group">
                    <label><i class="fas fa-search"></i> Search Product</label>
                    <div class="product-search-container">
                        <input type="text" id="transfer-search" class="form-control" placeholder="Type product name, ID or barcode..." autocomplete="off">
                        <div class="product-dropdown" id="transfer-dropdown"></div>
                    </div>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-box"></i> Selected Product</label>
                    <input type="text" id="transfer-product-name" readonly placeholder="Product will appear here">
                    <input type="hidden" name="product_id" id="transfer-product-id">
                    <input type="hidden" name="product_name" id="transfer-product-name-hidden">
                    <input type="hidden" name="product_barcode" id="transfer-product-barcode">
                </div>

                <div class="form-group">
                    <label><i class="fas fa-store"></i> Source Branch</label>
                    <div class="branch-toggle">
                        <button type="button" class="branch-btn branch1 active" onclick="setSourceBranch('branch1')" id="source-branch1">
                            <i class="fas fa-store"></i> Branch 1
                        </button>
                        <button type="button" class="branch-btn branch2" onclick="setSourceBranch('branch2')" id="source-branch2">
                            <i class="fas fa-store"></i> Branch 2
                        </button>
                    </div>
                    <input type="hidden" name="from_branch" id="from-branch" value="branch1">
                </div>

                <div class="form-group">
                    <label><i class="fas fa-store"></i> Destination Branch</label>
                    <div class="branch-toggle">
                        <button type="button" class="branch-btn branch1" onclick="setDestBranch('branch1')" id="dest-branch1">
                            <i class="fas fa-store"></i> Branch 1
                        </button>
                        <button type="button" class="branch-btn branch2 active" onclick="setDestBranch('branch2')" id="dest-branch2">
                            <i class="fas fa-store"></i> Branch 2
                        </button>
                    </div>
                    <input type="hidden" name="to_branch" id="to-branch" value="branch2">
                </div>

                <div class="form-group">
                    <label><i class="fas fa-cubes"></i> Quantity to Transfer</label>
                    <input type="number" name="quantity" id="transfer-quantity" min="1" required placeholder="Enter quantity">
                </div>

                <div class="form-group">
                    <label><i class="fas fa-pencil"></i> Notes (Optional)</label>
                    <textarea name="notes" id="transfer-notes" placeholder="Add any notes about this transfer..."></textarea>
                </div>

                <button type="submit" class="btn" id="transfer-submit">
                    <i class="fas fa-arrow-right-arrow-left"></i> Transfer Stock
                </button>
            </form>
        </div>

        <!-- Recent Stock Transfers Card -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-clock-rotate-left"></i> Recent Stock Transfers
                </div>
            </div>

            <div class="transactions-container" id="transactions-list">
                <div class="empty-state">
                    <i class="fas fa-clock-rotate-left"></i>
                    <h3>Loading Transactions...</h3>
                    <p>Please wait while we fetch the stock transfers</p>
                </div>
            </div>

            <div class="report-section">
                <div class="card-title" style="font-size: 1.2rem; margin-bottom: 15px;">
                    <i class="fas fa-print"></i> Print Report
                </div>
                <div class="report-controls">
                    <select id="report-days">
                        <option value="7">Last 7 Days</option>
                        <option value="30" selected>Last 30 Days</option>
                        <option value="90">Last 90 Days</option>
                        <option value="365">Last Year</option>
                    </select>
                    <button class="btn btn-report" onclick="generateReport()" style="padding: 12px;">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function setSourceBranch(branch) {
        document.getElementById('from-branch').value = branch;
        document.querySelectorAll('#source-branch1, #source-branch2').forEach(btn => btn.classList.remove('active'));
        document.getElementById(`source-${branch}`).classList.add('active');
    }

    function setDestBranch(branch) {
        document.getElementById('to-branch').value = branch;
        document.querySelectorAll('#dest-branch1, #dest-branch2').forEach(btn => btn.classList.remove('active'));
        document.getElementById(`dest-${branch}`).classList.add('active');
    }

    function setupProductSearch(inputId, dropdownId, callback) {
        const input = document.getElementById(inputId);
        const dropdown = document.getElementById(dropdownId);
        let timeout;

        input.addEventListener('input', function() {
            clearTimeout(timeout);
            const searchTerm = this.value.trim();
            
            if (searchTerm.length < 2) {
                dropdown.style.display = 'none';
                return;
            }

            timeout = setTimeout(() => {
                searchBothBranches(searchTerm, (products) => {
                    if (products.length > 0) {
                        dropdown.innerHTML = '';
                        products.forEach(product => {
                            const div = document.createElement('div');
                            div.className = 'product-option';
                            div.innerHTML = `
                                <strong>${product.name}</strong><br>
                                <small>ID: ${product.product_id} | Stock: ${product.stock} | Barcode: ${product.barcode || 'N/A'}</small>
                            `;
                            div.onclick = () => {
                                callback(product);
                                dropdown.style.display = 'none';
                                input.value = '';
                            };
                            dropdown.appendChild(div);
                        });
                        dropdown.style.display = 'block';
                    } else {
                        dropdown.innerHTML = '<div class="product-option">No products found</div>';
                        dropdown.style.display = 'block';
                    }
                });
            }, 300);
        });

        document.addEventListener('click', function(e) {
            if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        });
    }

    function searchBothBranches(searchTerm, callback) {
        const formData1 = new FormData();
        formData1.append('action', 'search_products');
        formData1.append('search', searchTerm);
        formData1.append('branch', 'branch1');

        const formData2 = new FormData();
        formData2.append('action', 'search_products');
        formData2.append('search', searchTerm);
        formData2.append('branch', 'branch2');

        Promise.all([
            fetch('', { method: 'POST', body: formData1 }).then(res => res.json()),
            fetch('', { method: 'POST', body: formData2 }).then(res => res.json())
        ])
        .then(([data1, data2]) => {
            let allProducts = [];
            
            if (data1.success && data1.products) {
                data1.products.forEach(p => {
                    allProducts.push({...p, branch: 'branch1', branch_name: 'Branch 1'});
                });
            }
            
            if (data2.success && data2.products) {
                data2.products.forEach(p => {
                    allProducts.push({...p, branch: 'branch2', branch_name: 'Branch 2'});
                });
            }
            
            callback(allProducts);
        });
    }

    function formatSriLankaTime(dateString) {
        const date = new Date(dateString);
        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year = date.getFullYear();
        let hours = date.getHours();
        const minutes = String(date.getMinutes()).padStart(2, '0');
        const seconds = String(date.getSeconds()).padStart(2, '0');
        const ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12;
        hours = hours ? hours : 12; // the hour '0' should be '12'
        const strHours = String(hours).padStart(2, '0');
        
        return `${day}/${month}/${year} ${strHours}:${minutes}:${seconds} ${ampm}`;
    }

    function loadTransactions() {
        const formData = new FormData();
        formData.append('action', 'get_transactions');
        formData.append('limit', 50);

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            const container = document.getElementById('transactions-list');
            
            if (data.success && data.transactions && data.transactions.length > 0) {
                let html = '';
                data.transactions.forEach(t => {
                    const formattedTime = formatSriLankaTime(t.created_at);

                    html += `
                        <div class="transaction-item">
                            <div class="transaction-header">
                                <span class="transaction-type">
                                    <i class="fas fa-arrow-right-arrow-left"></i> Transfer
                                </span>
                                <span class="transaction-time">${formattedTime}</span>
                            </div>
                            <div class="transaction-details">
                                <div class="transaction-product">
                                    <i class="fas fa-box"></i> ${t.product_name || 'N/A'}
                                </div>
                                ${t.product_barcode ? `
                                <div class="transaction-barcode">
                                    <i class="fas fa-qrcode"></i> Barcode: ${t.product_barcode}
                                </div>
                                ` : ''}
                                <div class="transaction-meta">
                                    <span class="transaction-meta-item">
                                        <i class="fas fa-arrow-right"></i> From: <strong>${t.branch_name || t.from_branch}</strong>
                                    </span>
                                    <span class="transaction-meta-item">
                                        <i class="fas fa-arrow-left"></i> To: <strong>${t.dest_branch_name || t.to_branch}</strong>
                                    </span>
                                    <span class="transaction-meta-item">
                                        <i class="fas fa-cubes"></i> Qty: <strong>${t.quantity}</strong>
                                    </span>
                                </div>
                                ${t.notes ? `
                                <div class="transaction-notes">
                                    <i class="fas fa-pencil"></i> ${t.notes}
                                </div>
                                ` : ''}
                            </div>
                        </div>
                    `;
                });
                container.innerHTML = html;
            } else {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-clock-rotate-left"></i>
                        <h3>No Transfers Found</h3>
                        <p>Start by creating a stock transfer above</p>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error loading transactions:', error);
            document.getElementById('transactions-list').innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-exclamation-triangle" style="color: var(--primary-red);"></i>
                    <h3>Error Loading Transactions</h3>
                    <p>Please refresh the page</p>
                </div>
            `;
        });
    }

    function generateReport() {
        const days = document.getElementById('report-days').value;
        
        // Show loading
        Swal.fire({
            title: 'Generating Report...',
            text: 'Please wait',
            allowOutsideClick: false,
            background: '#fff9f5',
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        const formData = new FormData();
        formData.append('action', 'generate_report');
        formData.append('days', days);

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.transactions.length > 0) {
                // Create a form to submit to print page
                const printForm = document.createElement('form');
                printForm.method = 'POST';
                printForm.action = 'print_stock_report1.php';
                printForm.target = '_blank';
                
                // Add form fields
                const daysField = document.createElement('input');
                daysField.type = 'hidden';
                daysField.name = 'days';
                daysField.value = days;
                printForm.appendChild(daysField);
                
                const transactionsField = document.createElement('input');
                transactionsField.type = 'hidden';
                transactionsField.name = 'transactions';
                transactionsField.value = JSON.stringify(data.transactions);
                printForm.appendChild(transactionsField);
                
                const printField = document.createElement('input');
                printField.type = 'hidden';
                printField.name = 'print_report';
                printField.value = '1';
                printForm.appendChild(printField);
                
                // Submit form
                document.body.appendChild(printForm);
                printForm.submit();
                document.body.removeChild(printForm);
                
                Swal.close();
            } else {
                Swal.fire({
                    icon: 'info',
                    title: 'No Data',
                    text: 'No transactions found for the selected period',
                    confirmButtonColor: '#8e44ad',
                    background: '#fff9f5'
                });
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Failed to generate report',
                confirmButtonColor: '#8e44ad',
                background: '#fff9f5'
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Load transactions
        loadTransactions();

        // Setup product search
        setupProductSearch('transfer-search', 'transfer-dropdown', function(product) {
            document.getElementById('transfer-product-name').value = `${product.name} (${product.branch_name} - Stock: ${product.stock})`;
            document.getElementById('transfer-product-id').value = product.product_id;
            document.getElementById('transfer-product-name-hidden').value = product.name;
            document.getElementById('transfer-product-barcode').value = product.barcode || '';
            setSourceBranch(product.branch);
        });

        // Set default branches
        setSourceBranch('branch1');
        setDestBranch('branch2');

        // Add animation to cards
        const cards = document.querySelectorAll('.card');
        cards.forEach((card, index) => {
            card.style.animationDelay = `${index * 0.1}s`;
        });
    });

    // Transfer form submission
    document.getElementById('transferForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const productId = document.getElementById('transfer-product-id').value;
        const quantity = document.getElementById('transfer-quantity').value;
        
        if (!productId) {
            Swal.fire({ 
                icon: 'error', 
                title: 'Error', 
                text: 'Please select a product', 
                background: '#fff9f5',
                confirmButtonColor: '#8e44ad'
            });
            return;
        }
        
        if (!quantity || quantity <= 0) {
            Swal.fire({ 
                icon: 'error', 
                title: 'Error', 
                text: 'Please enter a valid quantity', 
                background: '#fff9f5',
                confirmButtonColor: '#8e44ad'
            });
            return;
        }

        const submitBtn = document.getElementById('transfer-submit');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<span class="spinner"></span> Processing...';
        submitBtn.disabled = true;

        const formData = new FormData(this);

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: data.message,
                    timer: 2000,
                    showConfirmButton: false,
                    background: '#fff9f5'
                });
                this.reset();
                document.getElementById('transfer-product-name').value = '';
                document.getElementById('transfer-product-id').value = '';
                document.getElementById('transfer-product-name-hidden').value = '';
                document.getElementById('transfer-product-barcode').value = '';
                setSourceBranch('branch1');
                setDestBranch('branch2');
                loadTransactions();
            } else {
                Swal.fire({ 
                    icon: 'error', 
                    title: 'Error', 
                    text: data.message, 
                    background: '#fff9f5',
                    confirmButtonColor: '#8e44ad'
                });
            }
        })
        .catch(err => {
            Swal.fire({ 
                icon: 'error', 
                title: 'Error', 
                text: 'Network error occurred', 
                background: '#fff9f5',
                confirmButtonColor: '#8e44ad'
            });
        })
        .finally(() => {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        });
    });

    // Auto-refresh transactions every 30 seconds
    setInterval(loadTransactions, 30000);

    // Prevent zoom on double tap (iOS)
    let lastTouchEnd = 0;
    document.addEventListener('touchend', function (event) {
        const now = (new Date()).getTime();
        if (now - lastTouchEnd <= 300) {
            event.preventDefault();
        }
        lastTouchEnd = now;
    }, false);
</script>

<?php $conn->close(); ?>
</body>
</html>