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

// ========== UPDATE TABLE STRUCTURE ==========
// First check if items column exists, if not add it
$check_column = $conn->query("SHOW COLUMNS FROM stock_transactions LIKE 'items'");
if ($check_column->num_rows == 0) {
    // Add items column as TEXT to store JSON
    $conn->query("ALTER TABLE stock_transactions ADD COLUMN items TEXT NULL AFTER to_branch");
}

// Also add product_id, product_name, product_barcode, quantity columns for backward compatibility
$check_product_id = $conn->query("SHOW COLUMNS FROM stock_transactions LIKE 'product_id'");
if ($check_product_id->num_rows == 0) {
    $conn->query("ALTER TABLE stock_transactions ADD COLUMN product_id VARCHAR(50) NULL AFTER transaction_type");
    $conn->query("ALTER TABLE stock_transactions ADD COLUMN product_name VARCHAR(255) NULL AFTER product_id");
    $conn->query("ALTER TABLE stock_transactions ADD COLUMN product_barcode VARCHAR(100) NULL AFTER product_name");
    $conn->query("ALTER TABLE stock_transactions ADD COLUMN quantity INT NULL AFTER to_branch");
}

// ==================== PHP BACKEND (AJAX) ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';

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
                    WHEN t.from_branch = 'branch1' THEN 'Kids Berry' 
                    WHEN t.from_branch = 'branch2' THEN 'Mega Centre' 
                    ELSE t.from_branch 
                END as branch_name,
                CASE 
                    WHEN t.to_branch = 'branch1' THEN 'Kids Berry' 
                    WHEN t.to_branch = 'branch2' THEN 'Mega Centre' 
                    ELSE t.to_branch 
                END as dest_branch_name,
                t.created_at as raw_created_at
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
            // Decode items JSON if exists
            if (!empty($row['items'])) {
                $row['items'] = json_decode($row['items'], true);
            } else {
                // For backward compatibility, create items array from single product
                $row['items'] = [];
                if (!empty($row['product_id'])) {
                    $row['items'][] = [
                        'product_id' => $row['product_id'],
                        'product_name' => $row['product_name'],
                        'product_barcode' => $row['product_barcode'],
                        'quantity' => $row['quantity']
                    ];
                }
            }
            $transactions[] = $row;
        }
        
        echo json_encode(['success' => true, 'transactions' => $transactions]);
        exit;
    }
    
    // ========== MULTI-PRODUCT STOCK TRANSFER ==========
    if ($action === 'transfer_stock') {
        $items_json = $_POST['items'] ?? '[]';
        $items = json_decode($items_json, true);
        $from_branch = $conn->real_escape_string($_POST['from_branch']);
        $to_branch = $conn->real_escape_string($_POST['to_branch']);
        $notes = $conn->real_escape_string($_POST['notes'] ?? '');
        
        // Validate
        if (empty($items) || count($items) == 0) {
            echo json_encode(['success' => false, 'message' => 'No items selected for transfer']);
            exit;
        }
        
        if ($from_branch === $to_branch) {
            echo json_encode(['success' => false, 'message' => 'Source and destination branches cannot be the same']);
            exit;
        }
        
        // Determine source and destination tables
        $from_table = ($from_branch == 'branch1') ? 'products' : 'products2';
        $to_table = ($to_branch == 'branch1') ? 'products' : 'products2';
        
        // Begin transaction
        $conn->begin_transaction();
        
        try {
            $transfer_items = [];
            
            foreach ($items as $item) {
                $product_id = $conn->real_escape_string($item['product_id']);
                $product_name = $conn->real_escape_string($item['product_name']);
                $product_barcode = $conn->real_escape_string($item['product_barcode'] ?? '');
                $quantity = intval($item['quantity']);
                
                if ($quantity <= 0) {
                    throw new Exception("Quantity must be greater than zero for product: $product_name");
                }
                
                // Check if product exists in source branch
                $check_sql = "SELECT * FROM $from_table WHERE product_id = '$product_id'";
                $check_result = $conn->query($check_sql);
                
                if ($check_result->num_rows == 0) {
                    throw new Exception("Product not found in source branch: $product_name");
                }
                
                $source_product = $check_result->fetch_assoc();
                
                // Check if enough stock
                if ($source_product['stock'] < $quantity) {
                    throw new Exception("Insufficient stock for {$product_name}. Available: {$source_product['stock']}");
                }
                
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
                
                // Add to transfer items array for JSON storage
                $transfer_items[] = [
                    'product_id' => $product_id,
                    'product_name' => $product_name,
                    'product_barcode' => $product_barcode,
                    'quantity' => $quantity
                ];
            }
            
            // Record transaction in stock_transactions table with JSON items
            $items_json_encoded = $conn->real_escape_string(json_encode($transfer_items));
            
            // For first product, also store in individual columns for backward compatibility
            $first_product = $transfer_items[0];
            $product_id_col = $first_product['product_id'];
            $product_name_col = $first_product['product_name'];
            $product_barcode_col = $first_product['product_barcode'];
            $quantity_col = $first_product['quantity'];
            
            $transaction_sql = "INSERT INTO stock_transactions 
                (transaction_type, product_id, product_name, product_barcode, from_branch, to_branch, quantity, items, notes, created_by, created_at) 
                VALUES 
                ('transfer', '$product_id_col', '$product_name_col', '$product_barcode_col', '$from_branch', '$to_branch', $quantity_col, '$items_json_encoded', '$notes', '$stock_keeper_id', NOW())";
            $conn->query($transaction_sql);
            
            $transaction_id = $conn->insert_id;
            
            $conn->commit();
            
            // Prepare transaction data for receipt
            $transaction_data = [
                'id' => $transaction_id,
                'from_branch' => $from_branch,
                'to_branch' => $to_branch,
                'items' => $transfer_items,
                'notes' => $notes,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            echo json_encode([
                'success' => true, 
                'message' => "Stock transferred successfully! " . count($transfer_items) . " items moved from " . 
                            ($from_branch == 'branch1' ? 'Branch 1' : 'Branch 2') . " to " . 
                            ($to_branch == 'branch1' ? 'Branch 1' : 'Branch 2'),
                'transaction_id' => $transaction_id,
                'transaction' => $transaction_data
            ]);
            
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Transfer failed: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // ========== PRINT SINGLE TRANSACTION RECEIPT ==========
    if ($action === 'print_receipt') {
        $transaction_id = intval($_POST['transaction_id'] ?? 0);
        
        if ($transaction_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid transaction ID']);
            exit;
        }
        
        $sql = "SELECT * FROM stock_transactions WHERE id = $transaction_id";
        $result = $conn->query($sql);
        
        if (!$result || $result->num_rows == 0) {
            echo json_encode(['success' => false, 'message' => 'Transaction not found']);
            exit;
        }
        
        $transaction = $result->fetch_assoc();
        
        // Decode items JSON if exists
        if (!empty($transaction['items'])) {
            $transaction['items'] = json_decode($transaction['items'], true);
        } else {
            // For backward compatibility, create items array from single product
            $transaction['items'] = [];
            if (!empty($transaction['product_id'])) {
                $transaction['items'][] = [
                    'product_id' => $transaction['product_id'],
                    'product_name' => $transaction['product_name'],
                    'product_barcode' => $transaction['product_barcode'],
                    'quantity' => $transaction['quantity']
                ];
            }
        }
        
        echo json_encode([
            'success' => true, 
            'transaction' => $transaction
        ]);
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
    <title>📦 Stock Transfer · KidsBerry Mega Centre</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ----------------------------------------------------------------------
           DARK GREEN & WHITE · MODERN · TRENDY · FULLY RESPONSIVE
           - Exact same design system & colors as product1.php
        ----------------------------------------------------------------------- */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --dark-green: #1a3b2f;
            --forest-green: #2c5e2e;
            --sage: #8aae8c;
            --light-mint: #eaf7ea;
            --pure-white: #ffffff;
            --off-white: #f9fbf7;
            --charcoal: #2c3e2f;
            --soft-gray: #f0f2ee;
            --shadow-sm: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.02);
            --shadow-md: 0 20px 30px -12px rgba(26, 59, 47, 0.12);
            --radius-card: 24px;
            --radius-element: 16px;
            --transition-smooth: all 0.3s cubic-bezier(0.2, 0.9, 0.4, 1.1);
        }

        body {
            font-family: 'Inter', 'Outfit', system-ui, -apple-system, sans-serif;
            background: var(--off-white);
            color: #1e2a24;
            line-height: 1.5;
            min-height: 100vh;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px 24px;
        }

        /* ===== HEADER (identical to product1.php) ===== */
        header {
            background: var(--dark-green);
            color: white;
            padding: 0.7rem 0;
            position: sticky;
            top: 0;
            z-index: 100;
            backdrop-filter: blur(2px);
            border-bottom: 1px solid rgba(138, 174, 140, 0.3);
        }
        .menu {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .logo {
            font-size: 1.65rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 10px;
            font-family: 'Outfit', sans-serif;
        }
        .logo i {
            color: var(--sage);
            font-size: 1.7rem;
        }
        .logo span {
            font-size: 0.9rem;
            font-weight: 500;
            background: rgba(255,255,255,0.15);
            padding: 2px 10px;
            border-radius: 40px;
            margin-left: 8px;
        }
        .nav-links {
            display: flex;
            gap: 0.3rem;
            flex-wrap: wrap;
        }
        .nav-links a {
            color: white;
            text-decoration: none;
            padding: 8px 18px;
            border-radius: 40px;
            font-weight: 500;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.05);
            transition: var(--transition-smooth);
        }
        .nav-links a:hover, .nav-links a.active {
            background: white;
            color: var(--dark-green);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        .logout-btn {
            background: rgba(255,255,255,0.15) !important;
        }
        .logout-btn:hover {
            background: #ff5e4a !important;
            color: white !important;
        }

        /* ===== WELCOME BANNER (adapted to new theme) ===== */
        .welcome-banner {
            background: linear-gradient(105deg, var(--dark-green) 0%, var(--forest-green) 100%);
            color: white;
            border-radius: var(--radius-card);
            padding: 24px 32px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            box-shadow: var(--shadow-md);
        }
        .welcome-text {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .welcome-icon {
            width: 70px;
            height: 70px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
        }
        .welcome-title {
            font-size: 1.8rem;
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
        }
        .branch-indicator {
            background: rgba(255,255,255,0.15);
            padding: 12px 24px;
            border-radius: 60px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            border: 2px solid rgba(255,255,255,0.2);
        }

        /* ===== MAIN GRID ===== */
        .main-grid {
            display: grid;
            grid-template-columns: 1fr 1.2fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        /* CARD STYLES (identical to product1.php) */
        .card {
            background: var(--pure-white);
            border-radius: var(--radius-card);
            padding: 28px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition-smooth);
            border: 1px solid #ecf3e8;
            height: 100%;
        }
        .card:hover {
            box-shadow: var(--shadow-md);
            border-color: var(--sage);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            border-bottom: 3px solid var(--light-mint);
            padding-bottom: 15px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .card-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.7rem;
            font-weight: 700;
            color: var(--dark-green);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .card-title i {
            color: var(--forest-green);
        }

        /* FORM STYLES (identical to product1.php) */
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--charcoal);
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 14px 18px;
            border-radius: 50px;
            border: 2px solid rgba(44, 94, 46, 0.1);
            font-size: 0.95rem;
            background: var(--pure-white);
            transition: var(--transition-smooth);
            outline: none;
            color: var(--charcoal);
            font-weight: 500;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--forest-green);
            box-shadow: 0 0 0 4px rgba(44, 94, 46, 0.08);
        }
        .form-group textarea {
            border-radius: 24px;
            resize: vertical;
            min-height: 90px;
        }

        /* Branch Toggle (new theme) */
        .branch-toggle {
            display: flex;
            gap: 10px;
            margin: 15px 0;
            flex-wrap: wrap;
        }
        .branch-btn {
            flex: 1;
            padding: 14px 18px;
            border-radius: 50px;
            border: 2px solid rgba(44, 94, 46, 0.1);
            background: var(--pure-white);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition-smooth);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: var(--charcoal);
        }
        .branch-btn.active {
            background: var(--forest-green);
            color: white;
            border-color: var(--forest-green);
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
            background: var(--pure-white);
            border: 2px solid rgba(44, 94, 46, 0.1);
            border-radius: var(--radius-element);
            max-height: 300px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: var(--shadow-md);
            border-top: none;
            border-top-left-radius: 0;
            border-top-right-radius: 0;
        }
        .product-option {
            padding: 15px;
            cursor: pointer;
            border-bottom: 1px solid #ecf0f3;
            transition: 0.2s;
        }
        .product-option:hover {
            background: var(--light-mint);
        }
        .product-option strong {
            color: var(--dark-green);
        }

        /* Selected Items */
        .selected-items-container {
            margin-top: 15px;
            margin-bottom: 15px;
            border: 2px solid rgba(44, 94, 46, 0.1);
            border-radius: 20px;
            padding: 16px;
            background: var(--off-white);
        }
        .selected-items-title {
            font-weight: 700;
            margin-bottom: 12px;
            color: var(--dark-green);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-family: 'Outfit', sans-serif;
        }
        .selected-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            margin-bottom: 10px;
            background: var(--pure-white);
            border-radius: 16px;
            border-left: 4px solid var(--forest-green);
            box-shadow: var(--shadow-sm);
        }
        .selected-item-name {
            font-weight: 700;
            color: var(--dark-green);
        }
        .selected-item-quantity input {
            width: 70px;
            padding: 6px 10px;
            border-radius: 30px;
            text-align: center;
            border: 2px solid rgba(44, 94, 46, 0.1);
        }
        .remove-item-btn {
            background: #d9534f;
            color: white;
            border: none;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            cursor: pointer;
            font-size: 14px;
        }

        /* Buttons (identical to product1.php) */
        .btn {
            padding: 14px 24px;
            border-radius: 60px;
            font-size: 1rem;
            font-weight: 700;
            border: none;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            transition: var(--transition-smooth);
            cursor: pointer;
            box-shadow: var(--shadow-sm);
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--forest-green), #3e7c41);
        }
        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 20px rgba(44, 94, 46, 0.25);
        }

        /* Transactions Container */
        .transactions-container {
            max-height: 600px;
            overflow-y: auto;
            border-radius: var(--radius-card);
            background: var(--pure-white);
            padding: 8px;
        }
        .transaction-item {
            background: var(--light-mint);
            border-radius: var(--radius-element);
            padding: 20px;
            margin-bottom: 14px;
            border-left: 5px solid var(--forest-green);
            box-shadow: var(--shadow-sm);
            transition: var(--transition-smooth);
        }
        .transaction-item:hover {
            transform: translateY(-2px);
        }
        .transaction-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 14px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .transaction-type {
            font-weight: 700;
            padding: 6px 16px;
            border-radius: 50px;
            background: rgba(44, 94, 46, 0.1);
            color: var(--forest-green);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .transaction-time {
            font-size: 0.9rem;
            color: #6c7e70;
            font-weight: 500;
        }
        .transaction-items-list {
            margin: 12px 0;
            padding: 12px;
            background: var(--pure-white);
            border-radius: 16px;
        }
        .transaction-item-row {
            display: flex;
            justify-content: space-between;
            font-size: 0.9rem;
            padding: 6px 0;
            border-bottom: 1px dashed rgba(44, 94, 46, 0.15);
        }
        .transaction-meta {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin: 12px 0;
            padding: 12px 0;
            border-top: 1px dashed rgba(44, 94, 46, 0.2);
            border-bottom: 1px dashed rgba(44, 94, 46, 0.2);
        }
        .transaction-meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
            background: var(--pure-white);
            padding: 6px 16px;
            border-radius: 50px;
            box-shadow: var(--shadow-sm);
            font-size: 0.9rem;
        }
        .print-receipt-btn {
            background: linear-gradient(135deg, var(--forest-green), #3e7c41);
            padding: 10px 20px;
            border-radius: 50px;
            border: none;
            color: white;
            font-weight: 700;
            cursor: pointer;
            font-size: 0.95rem;
            width: 100%;
            margin-top: 12px;
            transition: var(--transition-smooth);
        }
        .print-receipt-btn:hover {
            transform: translateY(-2px);
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c7e70;
        }
        .empty-state i {
            font-size: 3rem;
            color: var(--sage);
            margin-bottom: 15px;
        }

        .info-box {
            background: var(--light-mint);
            padding: 16px 20px;
            border-radius: var(--radius-element);
            margin-bottom: 24px;
            border-left: 5px solid var(--forest-green);
            color: var(--charcoal);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .spinner {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 0.6s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        @media (max-width: 991px) {
            .main-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 576px) {
            .container { padding: 12px; }
            .card { padding: 20px; }
        }
    </style>
</head>
<body>

<header>
    <div class="container menu">
        <div class="logo">
            <i class="fas fa-seedling"></i> KidsBerry Mega Centre
            <span>Mega Centre</span>
        </div>
        <div class="nav-links">
            <a href="dashboard1.php"><i class="fas fa-chart-pie"></i> Dashboard</a>
            <a href="product1.php"><i class="fas fa-cubes"></i> Inventory</a>
            <a href="stock_transactions.php" class="active"><i class="fas fa-arrows-spin"></i> Movements</a>
            <a href="barcode1.php"><i class="fas fa-qrcode"></i> Barcode</a>
            <a href="suppliers1.php"><i class="fas fa-parachute-box"></i> Suppliers</a>
            <a href="?logout=true" class="logout-btn"><i class="fas fa-arrow-right-from-bracket"></i> Logout</a>
        </div>
    </div>
</header>

<div class="container">
    <div class="welcome-banner">
        <div class="welcome-text">
            <div class="welcome-icon">
                <i class="fas fa-arrow-right-arrow-left"></i>
            </div>
            <div>
                <div class="welcome-title">Stock Transfer Management</div>
                <div style="font-size:1.05rem; opacity:0.95;">Transfer multiple products between Branch 1 and Branch 2</div>
            </div>
        </div>
        <div class="branch-indicator">
            <i class="fas fa-store"></i>
            <span>Managing: <strong>Branch 1 &amp; Branch 2</strong></span>
        </div>
    </div>

    <div class="main-grid">
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-arrow-right-arrow-left"></i> New Stock Transfer
                </div>
            </div>

            <div class="info-box">
                <i class="fas fa-info-circle"></i> 
                Move multiple products from one branch to another. Add products below, then click "Transfer Stock".
            </div>

            <div class="form-group">
                <label><i class="fas fa-store"></i> Source Branch</label>
                <div class="branch-toggle">
                    <button type="button" class="branch-btn branch1 active" onclick="setSourceBranch('branch1')" id="source-branch1">🏪 Kids Berry</button>
                    <button type="button" class="branch-btn branch2" onclick="setSourceBranch('branch2')" id="source-branch2">🏪 Mega Centre </button>
                </div>
                <input type="hidden" id="from-branch" value="branch1">
            </div>

            <div class="form-group">
                <label><i class="fas fa-store"></i> Destination Branch</label>
                <div class="branch-toggle">
                    <button type="button" class="branch-btn branch1" onclick="setDestBranch('branch1')" id="dest-branch1">🏪 Kids Berry</button>
                    <button type="button" class="branch-btn branch2 active" onclick="setDestBranch('branch2')" id="dest-branch2">🏪 Mega Centre</button>
                </div>
                <input type="hidden" id="to-branch" value="branch2">
            </div>

            <div class="form-group">
                <label><i class="fas fa-search"></i> Add Product to Transfer</label>
                <div class="product-search-container">
                    <input type="text" id="product-search" class="form-control" placeholder="Search product by name, ID or barcode..." autocomplete="off">
                    <div class="product-dropdown" id="product-dropdown"></div>
                </div>
            </div>

            <div class="selected-items-container">
                <div class="selected-items-title">
                    <span><i class="fas fa-list"></i> Items to Transfer</span>
                    <span id="items-count">0 items</span>
                </div>
                <div id="selected-items-list"></div>
            </div>

            <div class="form-group">
                <label><i class="fas fa-pencil"></i> Notes (Optional)</label>
                <textarea id="transfer-notes" placeholder="Add any notes about this transfer..."></textarea>
            </div>

            <button type="button" class="btn btn-primary" id="transfer-submit">
                <i class="fas fa-arrow-right-arrow-left"></i> Transfer Stock
            </button>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-clock-rotate-left"></i> Recent Stock Transfers
                </div>
                <button onclick="loadTransactions()" class="btn" style="width:auto; padding:10px 20px; background:linear-gradient(135deg,var(--forest-green),#3e7c41);">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>

            <div class="transactions-container" id="transactions-list">
                <div class="empty-state">
                    <i class="fas fa-clock-rotate-left"></i>
                    <h3>Loading Transactions...</h3>
                    <p>Please wait while we fetch the stock transfers</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    let selectedItems = [];
    let currentSourceBranch = 'branch1';
    let currentDestBranch = 'branch2';
    
    function setSourceBranch(branch) {
        currentSourceBranch = branch;
        document.getElementById('from-branch').value = branch;
        document.querySelectorAll('#source-branch1, #source-branch2').forEach(btn => btn.classList.remove('active'));
        document.getElementById(`source-${branch}`).classList.add('active');
        
        // Clear selected items when source branch changes
        if (selectedItems.length > 0) {
            Swal.fire({
                title: 'Clear Items?',
                text: 'Changing source branch will clear selected items. Continue?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#2c5e2e',
                cancelButtonColor: '#d9534f',
                confirmButtonText: 'Yes, clear'
            }).then((result) => {
                if (result.isConfirmed) {
                    selectedItems = [];
                    updateSelectedItemsDisplay();
                } else {
                    // Revert branch selection
                    document.getElementById(`source-${currentSourceBranch}`).classList.add('active');
                    document.getElementById(`source-${branch}`).classList.remove('active');
                    document.getElementById('from-branch').value = currentSourceBranch;
                }
            });
        }
    }
    
    function setDestBranch(branch) {
        currentDestBranch = branch;
        document.getElementById('to-branch').value = branch;
        document.querySelectorAll('#dest-branch1, #dest-branch2').forEach(btn => btn.classList.remove('active'));
        document.getElementById(`dest-${branch}`).classList.add('active');
    }
    
    function setupProductSearch() {
        const input = document.getElementById('product-search');
        const dropdown = document.getElementById('product-dropdown');
        let timeout;
        
        input.addEventListener('input', function() {
            clearTimeout(timeout);
            const searchTerm = this.value.trim();
            
            if (searchTerm.length < 2) {
                dropdown.style.display = 'none';
                return;
            }
            
            timeout = setTimeout(() => {
                searchProductsInBranch(searchTerm, currentSourceBranch, (products) => {
                    if (products.length > 0) {
                        dropdown.innerHTML = '';
                        products.forEach(product => {
                            const div = document.createElement('div');
                            div.className = 'product-option';
                            div.innerHTML = `
                                <strong>${escapeHtml(product.name)}</strong> 
                                <small>(${product.product_id})</small><br>
                                <small>📦 Stock: ${product.stock} | 🔖 Barcode: ${product.barcode || 'N/A'}</small>
                            `;
                            div.onclick = () => {
                                addProductToTransfer(product);
                                dropdown.style.display = 'none';
                                input.value = '';
                                input.focus();
                            };
                            dropdown.appendChild(div);
                        });
                        dropdown.style.display = 'block';
                    } else {
                        dropdown.innerHTML = '<div class="product-option">❌ No products found in this branch</div>';
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
    
    function searchProductsInBranch(searchTerm, branch, callback) {
        const formData = new FormData();
        formData.append('action', 'search_products');
        formData.append('search', searchTerm);
        formData.append('branch', branch);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.products) {
                callback(data.products);
            } else {
                callback([]);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            callback([]);
        });
    }
    
    function addProductToTransfer(product) {
        // Check if product already in list
        const existingIndex = selectedItems.findIndex(item => item.product_id === product.product_id);
        
        if (existingIndex !== -1) {
            Swal.fire({
                title: 'Product Already Added',
                text: `${product.name} is already in the transfer list. Do you want to update the quantity?`,
                icon: 'info',
                showCancelButton: true,
                confirmButtonColor: '#2c5e2e',
                cancelButtonColor: '#d9534f',
                confirmButtonText: 'Update Quantity'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Enter Quantity',
                        input: 'number',
                        inputLabel: `Quantity for ${product.name}`,
                        inputValue: selectedItems[existingIndex].quantity,
                        inputAttributes: {
                            min: 1,
                            max: product.stock,
                            step: 1
                        },
                        showCancelButton: true,
                        confirmButtonText: 'Update'
                    }).then((result) => {
                        if (result.isConfirmed && result.value > 0) {
                            if (result.value > product.stock) {
                                Swal.fire('Error', `Maximum quantity is ${product.stock}`, 'error');
                                return;
                            }
                            selectedItems[existingIndex].quantity = parseInt(result.value);
                            updateSelectedItemsDisplay();
                        }
                    });
                }
            });
            return;
        }
        
        // Ask for quantity
        Swal.fire({
            title: 'Enter Quantity',
            input: 'number',
            inputLabel: `Quantity for ${product.name}`,
            inputValue: 1,
            inputAttributes: {
                min: 1,
                max: product.stock,
                step: 1
            },
            showCancelButton: true,
            confirmButtonText: 'Add to Transfer'
        }).then((result) => {
            if (result.isConfirmed && result.value > 0) {
                if (result.value > product.stock) {
                    Swal.fire('Error', `Maximum quantity is ${product.stock}`, 'error');
                    return;
                }
                
                selectedItems.push({
                    product_id: product.product_id,
                    product_name: product.name,
                    product_barcode: product.barcode || '',
                    quantity: parseInt(result.value),
                    stock: product.stock
                });
                updateSelectedItemsDisplay();
            }
        });
    }
    
    function updateSelectedItemsDisplay() {
        const container = document.getElementById('selected-items-list');
        const countSpan = document.getElementById('items-count');
        
        countSpan.textContent = `${selectedItems.length} item${selectedItems.length !== 1 ? 's' : ''}`;
        
        if (selectedItems.length === 0) {
            container.innerHTML = '<div style="text-align: center; padding: 20px; color: #999;">📦 No items added yet</div>';
            return;
        }
        
        let html = '';
        selectedItems.forEach((item, index) => {
            html += `
                <div class="selected-item">
                    <div class="selected-item-info">
                        <div class="selected-item-name">${escapeHtml(item.product_name)}</div>
                        <div class="selected-item-branch">🆔 ID: ${escapeHtml(item.product_id)}</div>
                        ${item.product_barcode ? `<div class="selected-item-branch">🔖 BC: ${escapeHtml(item.product_barcode)}</div>` : ''}
                    </div>
                    <div class="selected-item-quantity">
                        <input type="number" min="1" max="${item.stock}" value="${item.quantity}" onchange="updateQuantity(${index}, this.value)">
                        <button class="remove-item-btn" onclick="removeItem(${index})">✕</button>
                    </div>
                </div>
            `;
        });
        container.innerHTML = html;
    }
    
    function updateQuantity(index, newQuantity) {
        const quantity = parseInt(newQuantity);
        if (quantity > 0 && quantity <= selectedItems[index].stock) {
            selectedItems[index].quantity = quantity;
        } else {
            Swal.fire('Error', `Quantity must be between 1 and ${selectedItems[index].stock}`, 'error');
            updateSelectedItemsDisplay();
        }
    }
    
    function removeItem(index) {
        selectedItems.splice(index, 1);
        updateSelectedItemsDisplay();
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
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
                    const date = new Date(t.raw_created_at);
                    const formattedTime = date.toLocaleString('en-LK', {
                        year: 'numeric',
                        month: '2-digit',
                        day: '2-digit',
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit',
                        hour12: true,
                        timeZone: 'Asia/Colombo'
                    });
                    
                    let itemsHtml = '';
                    let totalQuantity = 0;
                    if (t.items && t.items.length > 0) {
                        itemsHtml = '<div class="transaction-items-list">';
                        t.items.forEach(item => {
                            totalQuantity += item.quantity;
                            itemsHtml += `
                                <div class="transaction-item-row">
                                    <span>📦 ${escapeHtml(item.product_name)}</span>
                                    <span>✕ ${item.quantity}</span>
                                </div>
                            `;
                        });
                        itemsHtml += '</div>';
                    } else {
                        totalQuantity = t.quantity || 0;
                    }
                    
                    html += `
                        <div class="transaction-item">
                            <div class="transaction-header">
                                <span class="transaction-type">
                                    <i class="fas fa-arrow-right-arrow-left"></i> Transfer #${String(t.id).padStart(6, '0')}
                                </span>
                                <span class="transaction-time">🕐 ${formattedTime}</span>
                            </div>
                            <div class="transaction-details">
                                <div class="transaction-meta">
                                    <span class="transaction-meta-item"><i class="fas fa-arrow-right"></i> From: <strong>${t.branch_name || t.from_branch}</strong></span>
                                    <span class="transaction-meta-item"><i class="fas fa-arrow-left"></i> To: <strong>${t.dest_branch_name || t.to_branch}</strong></span>
                                </div>
                                ${itemsHtml}
                                <div style="margin-top: 8px; font-weight: bold;">
                                    📊 Total: ${totalQuantity} unit${totalQuantity !== 1 ? 's' : ''}
                                </div>
                                ${t.notes ? `<div style="margin-top: 5px; padding: 8px; background: var(--pure-white); border-radius: 12px;"><small><i class="fas fa-pencil"></i> ${escapeHtml(t.notes)}</small></div>` : ''}
                                <button class="print-receipt-btn" onclick="printReceipt(${t.id})">
                                    <i class="fas fa-print"></i> Print Receipt
                                </button>
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
                    <i class="fas fa-exclamation-triangle"></i>
                    <h3>Error Loading</h3>
                    <p>Please refresh the page</p>
                </div>
            `;
        });
    }
    
    function printReceipt(transactionId) {
        Swal.fire({
            title: 'Loading Receipt...',
            text: 'Please wait',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        const formData = new FormData();
        formData.append('action', 'print_receipt');
        formData.append('transaction_id', transactionId);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.transaction) {
                const printForm = document.createElement('form');
                printForm.method = 'POST';
                printForm.action = 'print_stock_report.php';
                printForm.target = '_blank';
                
                const transactionField = document.createElement('input');
                transactionField.type = 'hidden';
                transactionField.name = 'transaction';
                transactionField.value = JSON.stringify(data.transaction);
                printForm.appendChild(transactionField);
                
                const idField = document.createElement('input');
                idField.type = 'hidden';
                idField.name = 'transaction_id';
                idField.value = transactionId;
                printForm.appendChild(idField);
                
                const printField = document.createElement('input');
                printField.type = 'hidden';
                printField.name = 'print_report';
                printField.value = '1';
                printForm.appendChild(printField);
                
                document.body.appendChild(printForm);
                printForm.submit();
                document.body.removeChild(printForm);
                
                Swal.close();
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: data.message || 'Failed to load transaction details',
                    confirmButtonColor: '#2c5e2e',
                    background: '#fff9f5'
                });
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Failed to generate receipt',
                confirmButtonColor: '#2c5e2e',
                background: '#fff9f5'
            });
        });
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        loadTransactions();
        setupProductSearch();
        
        setSourceBranch('branch1');
        setDestBranch('branch2');
        
        // Auto-refresh transactions every 30 seconds
        setInterval(loadTransactions, 30000);
    });
    
    document.getElementById('transfer-submit').addEventListener('click', function() {
        if (selectedItems.length === 0) {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Please add at least one product to transfer', background: '#fff9f5' });
            return;
        }
        
        const fromBranch = document.getElementById('from-branch').value;
        const toBranch = document.getElementById('to-branch').value;
        const notes = document.getElementById('transfer-notes').value;
        
        if (fromBranch === toBranch) {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Source and destination branches cannot be the same', background: '#fff9f5' });
            return;
        }
        
        const submitBtn = document.getElementById('transfer-submit');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<span class="spinner"></span> Processing...';
        submitBtn.disabled = true;
        
        const formData = new FormData();
        formData.append('action', 'transfer_stock');
        formData.append('from_branch', fromBranch);
        formData.append('to_branch', toBranch);
        formData.append('notes', notes);
        formData.append('items', JSON.stringify(selectedItems));
        
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
                
                // Clear form
                selectedItems = [];
                updateSelectedItemsDisplay();
                document.getElementById('transfer-notes').value = '';
                
                // Reload transactions
                loadTransactions();
                
                // Print receipt if transaction ID returned
                if (data.transaction_id) {
                    setTimeout(() => {
                        printReceipt(data.transaction_id);
                    }, 1500);
                }
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.message, background: '#fff9f5' });
            }
        })
        .catch(err => {
            console.error('Error:', err);
            Swal.fire({ icon: 'error', title: 'Error', text: 'Network error occurred', background: '#fff9f5' });
        })
        .finally(() => {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        });
    });
</script>

<?php $conn->close(); ?>
</body>
</html>