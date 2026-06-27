<?php
session_start();
if (!isset($_SESSION['cashiers_id'])) {
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

// BRANCH 2 SPECIFIC - Set correct branch_id (this was missing / hardcoded wrongly in old version)
$branch_id = 2;
$_SESSION['branch_id'] = $branch_id;

// Check and modify returns_tracking2 table structure (same as working Branch 1 logic)
$check_columns = [
    'item_id',
    'original_quantity', 
    'original_price',
    'original_discount'
];

foreach ($check_columns as $column) {
    $check_sql = "SHOW COLUMNS FROM returns_tracking2 LIKE '$column'";
    $check_result = $conn->query($check_sql);
    
    if ($check_result->num_rows == 0) {
        $column_type = '';
        switch($column) {
            case 'item_id':
                $column_type = 'INT AFTER bill_no';
                break;
            case 'original_quantity':
                $column_type = 'INT AFTER product_name';
                break;
            case 'original_price':
            case 'original_discount':
                $column_type = 'DECIMAL(10,2) AFTER original_quantity';
                break;
        }
        
        if ($column_type) {
            $alter_sql = "ALTER TABLE returns_tracking2 ADD COLUMN $column $column_type";
            $conn->query($alter_sql);
        }
    }
}

// Create returns_tracking2 table if not exists (exact same structure as working version)
$create_table_sql = "CREATE TABLE IF NOT EXISTS returns_tracking2 (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bill_no VARCHAR(50) NOT NULL,
    item_id INT,
    imei VARCHAR(50),
    product_name VARCHAR(255),
    original_quantity INT,
    original_price DECIMAL(10,2),
    original_discount DECIMAL(10,2),
    return_quantity INT NOT NULL,
    return_amount DECIMAL(10,2) NOT NULL,
    return_reason TEXT,
    return_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    cashier_id INT,
    branch_id INT,
    INDEX idx_return_date (return_date),
    INDEX idx_bill_no (bill_no),
    INDEX idx_item_id (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$conn->query($create_table_sql);

// Add columns to bill_items2 table (exact same safe checks as working Branch 1)
$check_has_returns = "SHOW COLUMNS FROM bill_items2 LIKE 'has_returns'";
$has_returns_result = $conn->query($check_has_returns);

$check_returned_qty = "SHOW COLUMNS FROM bill_items2 LIKE 'total_returned_quantity'";
$returned_qty_result = $conn->query($check_returned_qty);

$check_returned_amount = "SHOW COLUMNS FROM bill_items2 LIKE 'total_returned_amount'";
$returned_amount_result = $conn->query($check_returned_amount);

if ($has_returns_result->num_rows == 0) {
    $conn->query("ALTER TABLE bill_items2 ADD COLUMN has_returns TINYINT DEFAULT 0");
}
if ($returned_qty_result->num_rows == 0) {
    $conn->query("ALTER TABLE bill_items2 ADD COLUMN total_returned_quantity INT DEFAULT 0");
}
if ($returned_amount_result->num_rows == 0) {
    $conn->query("ALTER TABLE bill_items2 ADD COLUMN total_returned_amount DECIMAL(10,2) DEFAULT 0");
}

// ==================== FIXED RETURN SUBMISSION LOGIC (100% copied & adapted from working Branch 1) ====================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['return_sale'])) {
    $bill_no = mysqli_real_escape_string($conn, $_POST['bill_no']);
    $item_id = intval($_POST['item_id']);
    $imei = isset($_POST['imei']) ? mysqli_real_escape_string($conn, $_POST['imei']) : '';
    $return_reason = mysqli_real_escape_string($conn, $_POST['return_reason']);
    $return_quantity = intval($_POST['return_quantity']);
    
    $conn->begin_transaction();
    
    try {
        // Get sale record using item_id (exact same safe query as working version)
        $get_sale_sql = "SELECT bi.*, 
                         (bi.price * bi.quantity - bi.discount) as total_amount,
                         bi.discount as total_discount,
                         bi.quantity as original_quantity,
                         bi.price as original_price,
                         COALESCE(bi.total_returned_quantity, 0) as total_returned_quantity,
                         COALESCE(bi.total_returned_amount, 0) as total_returned_amount
                         FROM bill_items2 bi
                         WHERE bi.id = '$item_id' 
                         AND bi.bill_no = '$bill_no'";
        
        $sale_result = $conn->query($get_sale_sql);
        
        if ($sale_result->num_rows > 0) {
            $sale = $sale_result->fetch_assoc();
            $available_for_return = $sale['quantity'] - $sale['total_returned_quantity'];
            
            if ($return_quantity > $available_for_return || $return_quantity <= 0) {
                throw new Exception("Invalid return quantity! Available for return: " . $available_for_return);
            }
            
            $total_amount = $sale['total_amount'];
            $unit_total_amount = $sale['quantity'] > 0 ? ($total_amount / $sale['quantity']) : 0;
            $return_amount = $unit_total_amount * $return_quantity;
            
            $total_discount = $sale['total_discount'];
            $unit_discount = $sale['quantity'] > 0 ? ($total_discount / $sale['quantity']) : 0;
            $return_discount = $unit_discount * $return_quantity;
            
            $new_returned_quantity = $sale['total_returned_quantity'] + $return_quantity;
            $new_returned_amount = $sale['total_returned_amount'] + $return_amount;
            $has_returns = 1;
            
            // Update bill_items2 tracking
            $update_item_sql = "UPDATE bill_items2 
                               SET has_returns = $has_returns,
                                   total_returned_quantity = $new_returned_quantity,
                                   total_returned_amount = $new_returned_amount
                               WHERE id = '$item_id'";
            
            if (!$conn->query($update_item_sql)) {
                throw new Exception("Failed to update return tracking: " . $conn->error);
            }
            
            // Update product stock (Branch 2 uses products2)
            $product_name = mysqli_real_escape_string($conn, $sale['product_name']);
            $get_product_sql = "SELECT product_id FROM products2 
                                WHERE name = '$product_name' 
                                LIMIT 1";
            $product_result = $conn->query($get_product_sql);
            
            if ($product_result->num_rows > 0) {
                $product = $product_result->fetch_assoc();
                $product_id = $product['product_id'];
                
                $product_update_sql = "UPDATE products2 
                                       SET stock = stock + $return_quantity
                                       WHERE product_id = '$product_id'";
                                       
                if (!$conn->query($product_update_sql)) {
                    throw new Exception("Failed to update product stock: " . $conn->error);
                }
            } else {
                throw new Exception("Product not found in inventory!");
            }
            
            // ==================== FIXED TRACKING INSERT (dynamic + safe like working Branch 1) ====================
            $tracking_columns = [];
            $tracking_values = [];
            
            $tracking_columns[] = 'bill_no';
            $tracking_values[] = "'$bill_no'";
            $tracking_columns[] = 'imei';
            $tracking_values[] = "'$imei'";
            $tracking_columns[] = 'product_name';
            $tracking_values[] = "'{$sale['product_name']}'";
            $tracking_columns[] = 'return_quantity';
            $tracking_values[] = "$return_quantity";
            $tracking_columns[] = 'return_amount';
            $tracking_values[] = "$return_amount";
            $tracking_columns[] = 'return_reason';
            $tracking_values[] = "'$return_reason'";
            $tracking_columns[] = 'return_date';
            $tracking_values[] = "NOW()";
            $tracking_columns[] = 'cashier_id';
            $tracking_values[] = "'{$_SESSION['cashiers_id']}'";
            $tracking_columns[] = 'branch_id';
            $tracking_values[] = "$branch_id";
            $tracking_columns[] = 'item_id';
            $tracking_values[] = "$item_id";
            $tracking_columns[] = 'original_quantity';
            $tracking_values[] = "'{$sale['original_quantity']}'";
            $tracking_columns[] = 'original_price';
            $tracking_values[] = "'{$sale['original_price']}'";
            $tracking_columns[] = 'original_discount';
            $tracking_values[] = "'{$sale['total_discount']}'";
            
            $tracking_sql = "INSERT INTO returns_tracking2 (" . implode(', ', $tracking_columns) . ")
                VALUES (" . implode(', ', $tracking_values) . ")";
                
            if (!$conn->query($tracking_sql)) {
                throw new Exception("Failed to insert into returns tracking: " . $conn->error);
            }
            
            // Update bills2 total_returned_amount
            $check_bill_column = "SHOW COLUMNS FROM bills2 LIKE 'total_returned_amount'";
            $bill_column_result = $conn->query($check_bill_column);
            
            if ($bill_column_result->num_rows == 0) {
                $conn->query("ALTER TABLE bills2 ADD COLUMN total_returned_amount DECIMAL(10,2) DEFAULT 0");
            }
            
            $update_bill_sql = "UPDATE bills2 
                                SET total_returned_amount = COALESCE(total_returned_amount, 0) + $return_amount
                                WHERE bill_no = '$bill_no'";
            $conn->query($update_bill_sql);
            
            $conn->commit();
            
            $_SESSION['return_message'] = "Return processed successfully! Return Amount: Rs" . number_format($return_amount, 2);
            $_SESSION['return_message_type'] = "success";
            
        } else {
            throw new Exception("Sale record not found!");
        }
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['return_message'] = "Error processing return: " . $e->getMessage();
        $_SESSION['return_message_type'] = "error";
    }
    
    header("Location: return_sale1.php");
    exit();
}

// Handle logout
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['logout'])) {
    unset($_SESSION['cashiers_id']);
    unset($_SESSION['branch_id']);
    header("Location: ../index.php");
    exit();
}

// Get date filter
$date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : (isset($_POST['date_filter']) ? $_POST['date_filter'] : 'today');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>KIDS Berry - Return Sales Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1a472a;
            --primary-dark: #0e2a1a;
            --primary-light: #2c5e3c;
            --secondary: #2c5e3c;
            --secondary-dark: #1e3e2a;
            --secondary-light: #3e7e52;
            --accent: #d4a373;
            --accent-dark: #bc8f5a;
            --accent-light: #e9c46a;
            --success: #2e7d32;
            --warning: #f39c12;
            --danger: #e74c3c;
            --info: #3498db;
            --light: #f8f9fa;
            --dark: #2c3e2f;
            --gray: #6c757d;
            --white: #ffffff;
            --card-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --card-shadow-hover: 0 8px 24px rgba(0, 0, 0, 0.12);
            --transition: all 0.3s ease;
            --radius: 12px;
            --radius-sm: 8px;
            --radius-lg: 20px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #e8f0e8 0%, #d4e2d4 100%);
            color: var(--dark);
            min-height: 100vh;
            line-height: 1.5;
        }

        /* All other CSS remains exactly the same as your original Branch 2 design */
        .header { background: var(--primary-dark); color: white; padding: 16px 24px; box-shadow: var(--card-shadow); position: sticky; top: 0; z-index: 100; }
        .header-content { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; max-width: 1400px; margin: 0 auto; }
        .logo-container { display: flex; align-items: center; gap: 15px; }
        .logo-container img { width: 50px; height: 50px; border-radius: 50%; border: 2px solid var(--accent); object-fit: cover; }
        .brand-info h1 { font-size: 22px; font-weight: 700; margin-bottom: 2px; }
        .header-tagline { font-size: 12px; opacity: 0.8; }
        .date-time { background: rgba(255,255,255,0.1); padding: 8px 16px; border-radius: var(--radius); text-align: center; }
        .current-date { font-size: 14px; font-weight: 600; }
        .current-time { font-size: 12px; opacity: 0.9; }
        .logout-btn { background: rgba(255,255,255,0.15); color: white; border: none; padding: 8px 20px; border-radius: 50px; cursor: pointer; font-weight: 600; font-size: 14px; transition: var(--transition); display: flex; align-items: center; gap: 8px; }
        .logout-btn:hover { background: rgba(255,255,255,0.25); transform: translateY(-2px); }
        .nav-container { background: white; padding: 12px 24px; box-shadow: var(--card-shadow); position: sticky; top: 82px; z-index: 99; }
        .nav { display: flex; justify-content: center; gap: 8px; flex-wrap: wrap; max-width: 1400px; margin: 0 auto; }
        .nav a { text-decoration: none; color: var(--dark); font-weight: 600; padding: 10px 20px; border-radius: 50px; background: var(--light); transition: var(--transition); display: flex; align-items: center; gap: 8px; font-size: 13px; }
        .nav a:hover { background: var(--primary); color: white; }
        .nav a.active { background: var(--primary); color: white; }
        .main-container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .card { background: white; border-radius: var(--radius-lg); box-shadow: var(--card-shadow); overflow: hidden; margin-bottom: 20px; border: 1px solid rgba(26, 71, 42, 0.1); }
        .card-header { background: var(--primary); color: white; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .card-title { font-size: 18px; font-weight: 600; display: flex; align-items: center; gap: 10px; }
        .card-content { padding: 24px; }
        /* (All remaining CSS from your original file is kept unchanged - stats, forms, tables, responsive, etc.) */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: var(--light); border-radius: var(--radius); padding: 18px; border: 1px solid #e0e0e0; transition: var(--transition); position: relative; overflow: hidden; }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: var(--primary); }
        .stat-card:hover { transform: translateY(-3px); box-shadow: var(--card-shadow); }
        .stat-label { font-size: 12px; color: var(--gray); margin-bottom: 8px; font-weight: 600; display: flex; align-items: center; gap: 6px; }
        .stat-value { font-size: 24px; font-weight: 800; color: var(--primary); }
        .stat-sub { font-size: 11px; color: var(--gray); margin-top: 5px; }
        .search-form { background: var(--light); padding: 20px; border-radius: var(--radius); margin-bottom: 25px; border: 1px solid #e0e0e0; }
        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-weight: 600; font-size: 12px; margin-bottom: 6px; color: var(--dark); display: flex; align-items: center; gap: 6px; }
        .form-group label i { color: var(--primary); }
        .form-group input, .form-group textarea, .form-group select { padding: 10px 12px; border: 1px solid #e0e0e0; border-radius: var(--radius-sm); font-size: 13px; transition: var(--transition); background: white; font-family: inherit; }
        .form-group input:focus, .form-group textarea:focus, .form-group select:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px rgba(26, 71, 42, 0.1); }
        .btn-group { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn { padding: 10px 20px; border: none; border-radius: var(--radius-sm); cursor: pointer; font-weight: 600; transition: var(--transition); display: inline-flex; align-items: center; gap: 8px; font-size: 13px; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-2px); }
        .btn-secondary { background: var(--accent); color: white; }
        .btn-secondary:hover { background: var(--accent-dark); transform: translateY(-2px); }
        .btn-warning { background: var(--warning); color: white; }
        .btn-warning:hover { background: #e67e22; transform: translateY(-2px); }
        .alert { padding: 12px 16px; border-radius: var(--radius); margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-size: 13px; font-weight: 500; border-left: 4px solid; }
        .alert-success { background: #e8f5e9; color: var(--success); border-left-color: var(--success); }
        .alert-error { background: #ffebee; color: var(--danger); border-left-color: var(--danger); }
        .table-container { overflow-x: auto; border-radius: var(--radius); border: 1px solid #e0e0e0; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; background: white; font-size: 13px; }
        table th { background: var(--primary); color: white; padding: 12px; text-align: left; font-weight: 600; white-space: nowrap; }
        table tr { border-bottom: 1px solid #f0f0f0; transition: var(--transition); }
        table tr:hover { background: var(--light); }
        table td { padding: 10px 12px; }
        .product-info { display: flex; flex-direction: column; gap: 3px; }
        .product-name { font-weight: 600; color: var(--primary); }
        .imei-badge { display: inline-block; background: #f5f5f5; padding: 3px 8px; border-radius: 4px; font-size: 10px; font-family: monospace; color: var(--dark); border: 1px solid #e0e0e0; }
        .quantity-input { width: 70px; padding: 6px 8px; border: 1px solid #e0e0e0; border-radius: var(--radius-sm); font-size: 12px; text-align: center; }
        .amount-preview { font-size: 10px; color: var(--success); margin-top: 4px; font-weight: 600; }
        .date-filter-container { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 20px; background: var(--light); padding: 15px; border-radius: var(--radius); }
        .date-filter-buttons { display: flex; gap: 8px; flex-wrap: wrap; }
        .date-filter-btn { padding: 6px 14px; border: 1px solid #e0e0e0; background: white; border-radius: 20px; cursor: pointer; font-size: 12px; font-weight: 600; transition: var(--transition); }
        .date-filter-btn:hover { background: var(--primary); color: white; border-color: var(--primary); }
        .date-filter-btn.active { background: var(--primary); color: white; border-color: var(--primary); }
        .custom-date-form { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .custom-date-input { padding: 6px 12px; border: 1px solid #e0e0e0; border-radius: var(--radius-sm); font-size: 12px; }
        .returns-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .summary-item { background: var(--light); padding: 15px; border-radius: var(--radius); text-align: center; border: 1px solid #e0e0e0; }
        .summary-label { font-size: 11px; color: var(--gray); margin-bottom: 6px; font-weight: 600; }
        .summary-value { font-size: 20px; font-weight: 800; color: var(--primary); }
        .no-data { text-align: center; padding: 40px 20px; }
        .no-data i { font-size: 50px; color: #e0e0e0; margin-bottom: 15px; }
        .no-data h3 { color: var(--dark); margin-bottom: 8px; font-size: 16px; }
        .no-data p { color: var(--gray); font-size: 12px; }
        .notification { position: fixed; bottom: 20px; right: 20px; background: var(--primary); color: white; padding: 12px 20px; border-radius: var(--radius); display: none; z-index: 1000; font-size: 13px; align-items: center; gap: 8px; }
        @media (max-width: 992px) { .header-content { flex-direction: column; text-align: center; } .nav { flex-direction: column; } .nav a { justify-content: center; } .date-filter-container { flex-direction: column; align-items: stretch; } .custom-date-form { justify-content: center; } }
        @media (max-width: 768px) { .main-container { padding: 15px; } .card-content { padding: 16px; } .form-row { grid-template-columns: 1fr; } .stats-grid { grid-template-columns: 1fr; } .returns-summary { grid-template-columns: 1fr 1fr; } .btn-group { flex-direction: column; } .btn { width: 100%; justify-content: center; } }
        @media (max-width: 480px) { .returns-summary { grid-template-columns: 1fr; } .date-filter-buttons { justify-content: center; } }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <div class="logo-container">
                <img src="../logo.jpg" alt="Kids Berry Logo">
                <div class="brand-info">
                    <h1><i class="fas fa-exchange-alt"></i> Return Sales</h1>
                    <div class="header-tagline">Process Customer Returns & Track History</div>
                </div>
            </div>
            
            <div style="display: flex; align-items: center; gap: 15px;">
                <div class="date-time">
                    <div class="current-date"><?php echo date('d M Y'); ?></div>
                    <div class="current-time"><?php echo date('h:i A'); ?></div>
                </div>
                <form method="post">
                    <button type="submit" name="logout" class="logout-btn">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="nav-container">
        <div class="nav">
            <a href="billing1.php"><i class="fas fa-cash-register"></i> Billing</a>
            <a href="customer1.php"><i class="fas fa-users"></i> Customers</a>
            <a href="bill_management1.php"><i class="fas fa-history"></i> Bill History</a>
            <a href="report1.php"><i class="fas fa-chart-line"></i> Reports</a>
            <a href="return_sale1.php" class="active"><i class="fas fa-undo-alt"></i> Manage Returns</a>
            <!--<a href="prediction_dashboard1.php"><i class="fas fa-bullseye"></i> Predictions</a>-->
        </div>
    </div>

    <div class="main-container">
        <!-- Stats Section (unchanged) -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-chart-line"></i> Returns Overview
                </div>
            </div>
            <div class="card-content">
                <?php
                $today_stats_sql = "SELECT 
                                    COUNT(*) as today_returns,
                                    SUM(return_quantity) as today_items,
                                    SUM(return_amount) as today_amount
                                    FROM returns_tracking2
                                    WHERE DATE(return_date) = CURDATE()";
                $today_stats_result = $conn->query($today_stats_sql);
                $today_stats = $today_stats_result->fetch_assoc();
                ?>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-label"><i class="fas fa-calendar-day"></i> Today's Returns</div>
                        <div class="stat-value"><?php echo $today_stats['today_returns'] ?? 0; ?></div>
                        <div class="stat-sub">Return transactions</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label"><i class="fas fa-boxes"></i> Items Returned</div>
                        <div class="stat-value"><?php echo $today_stats['today_items'] ?? 0; ?></div>
                        <div class="stat-sub">Products returned today</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label"><i class="fas fa-money-bill-wave"></i> Return Amount</div>
                        <div class="stat-value">Rs<?php echo number_format($today_stats['today_amount'] ?? 0, 2); ?></div>
                        <div class="stat-sub">Total value returned</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Find Sale to Return Section -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-search"></i> Find Sale to Return
                </div>
            </div>
            <div class="card-content">
                <?php if (isset($_SESSION['return_message'])): ?>
                    <div class="alert alert-<?php echo $_SESSION['return_message_type']; ?>">
                        <i class="fas <?php echo $_SESSION['return_message_type'] == 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
                        <?php echo $_SESSION['return_message']; ?>
                    </div>
                    <?php 
                    unset($_SESSION['return_message']);
                    unset($_SESSION['return_message_type']);
                    ?>
                <?php endif; ?>
                
                <div class="search-form">
                    <form method="post" action="">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="search_bill"><i class="fas fa-receipt"></i> Bill Number</label>
                                <input type="text" id="search_bill" name="search_bill" placeholder="Enter Bill No" 
                                       value="<?php echo isset($_POST['search_bill']) ? htmlspecialchars($_POST['search_bill']) : ''; ?>">
                            </div>
                            <div class="form-group">
                                <label for="search_product"><i class="fas fa-box"></i> Product Name</label>
                                <input type="text" id="search_product" name="search_product" placeholder="Enter Product Name" 
                                       value="<?php echo isset($_POST['search_product']) ? htmlspecialchars($_POST['search_product']) : ''; ?>">
                            </div>
                            <div class="form-group">
                                <label for="search_imei"><i class="fas fa-barcode"></i> IMEI/Serial</label>
                                <input type="text" id="search_imei" name="search_imei" placeholder="Enter IMEI" 
                                       value="<?php echo isset($_POST['search_imei']) ? htmlspecialchars($_POST['search_imei']) : ''; ?>">
                            </div>
                        </div>
                        
                        <div class="btn-group">
                            <button type="submit" name="search_sale" class="btn btn-primary">
                                <i class="fas fa-search"></i> Search Sales
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="clearSearch()">
                                <i class="fas fa-redo"></i> Clear Search
                            </button>
                        </div>
                    </form>
                </div>
                
                <?php
                if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['search_sale'])) {
                    $search_bill = mysqli_real_escape_string($conn, $_POST['search_bill'] ?? '');
                    $search_product = mysqli_real_escape_string($conn, $_POST['search_product'] ?? '');
                    $search_imei = mysqli_real_escape_string($conn, $_POST['search_imei'] ?? '');
                    
                    // Updated search query - now includes PSN numbers (same as working Branch 1)
                    $search_sql = "SELECT bi.*, 
                                  (bi.price * bi.quantity - bi.discount) as total_amount,
                                  b.date, 
                                  c.name as cashier_name,
                                  b.customer_name,
                                  b.phone_no,
                                  (bi.quantity - COALESCE(bi.total_returned_quantity, 0)) as available_quantity,
                                  COALESCE(bi.total_returned_quantity, 0) as returned_quantity
                                   FROM bill_items2 bi
                                   JOIN bills2 b ON bi.bill_no = b.bill_no
                                   JOIN cashier_users2 c ON b.cashier_id = c.cashier_id
                                   WHERE 1=1";
                    
                    if (!empty($search_bill)) {
                        $search_sql .= " AND bi.bill_no LIKE '%$search_bill%'";
                    }
                    if (!empty($search_product)) {
                        $search_sql .= " AND bi.product_name LIKE '%$search_product%'";
                    }
                    if (!empty($search_imei)) {
                        $search_sql .= " AND (bi.imei LIKE '%$search_imei%' OR bi.psn_numbers LIKE '%$search_imei%')";
                    }
                    
                    $search_sql .= " ORDER BY b.date DESC, bi.id ASC LIMIT 100";
                    $search_result = $conn->query($search_sql);
                    
                    if ($search_result->num_rows > 0):
                ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Bill Details</th>
                                <th>Product Details</th>
                                <th>Original</th>
                                <th>Returned</th>
                                <th>Available</th>
                                <th>Amount</th>
                                <th>Sold By</th>
                                <th>Return Qty</th>
                                <th>Reason</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $counter = 1;
                            while($row = $search_result->fetch_assoc()): 
                                $unit_total = $row['total_amount'] / $row['quantity'];
                                $returned_qty = $row['returned_quantity'] ?? 0;
                                $available_qty = $row['quantity'] - $returned_qty;
                                
                                if ($available_qty <= 0) continue;
                            ?>
                            <tr>
                                <td><?php echo $counter++; ?></td>
                                <td>
                                    <div class="product-info">
                                        <strong style="color: var(--primary);"><?php echo $row['bill_no']; ?></strong>
                                        <div style="font-size: 11px; color: var(--gray);">
                                            <?php echo date('Y-m-d', strtotime($row['date'])); ?>
                                            <?php if (!empty($row['customer_name'])): ?>
                                                <br><i class="fas fa-user"></i> <?php echo htmlspecialchars($row['customer_name']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="product-info">
                                        <span class="product-name"><?php echo htmlspecialchars($row['product_name']); ?></span>
                                        <?php if (!empty($row['product_color'])): ?>
                                            <span style="font-size: 11px;">Color: <?php echo htmlspecialchars($row['product_color']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($row['imei'])): ?>
                                            <span class="imei-badge">IMEI: <?php echo htmlspecialchars($row['imei']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($row['psn_numbers'])): ?>
                                            <span class="imei-badge">PSN: <?php echo htmlspecialchars($row['psn_numbers']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?php echo $row['quantity']; ?></td>
                                <td>
                                    <?php if ($returned_qty > 0): ?>
                                        <span style="color: var(--danger); font-weight: 600;"><?php echo $returned_qty; ?></span>
                                    <?php else: ?>
                                        <span style="color: var(--gray);">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong style="color: <?php echo $available_qty > 0 ? 'var(--success)' : 'var(--gray)'; ?>;">
                                        <?php echo $available_qty; ?>
                                    </strong>
                                </td>
                                <td>
                                    <div>
                                        <strong>Rs<?php echo number_format($row['total_amount'], 2); ?></strong><br>
                                        <small style="color: var(--gray);">Unit: Rs<?php echo number_format($unit_total, 2); ?></small>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($row['cashier_name']); ?></td>
                                <td>
                                    <!-- Form now properly structured - all fields inside form tags (qty + reason + button) -->
                                    <form method="post" class="return-form" style="display: inline;">
                                        <input type="hidden" name="bill_no" value="<?php echo $row['bill_no']; ?>">
                                        <input type="hidden" name="item_id" value="<?php echo $row['id']; ?>">
                                        <input type="hidden" name="imei" value="<?php echo htmlspecialchars($row['imei']); ?>">
                                        <input type="number" name="return_quantity" class="quantity-input" 
                                               min="1" max="<?php echo $available_qty; ?>" 
                                               value="1" required
                                               onchange="calculateReturnAmount(this, <?php echo $unit_total; ?>)">
                                        <div class="amount-preview" id="preview_<?php echo $row['id']; ?>">
                                            Return: Rs<?php echo number_format($unit_total, 2); ?>
                                        </div>
                                </td>
                                <td>
                                        <textarea name="return_reason" placeholder="Reason" rows="1" 
                                                  style="width: 120px; padding: 6px; border-radius: 4px; border: 1px solid #e0e0e0; font-size: 11px;" required></textarea>
                                </td>
                                <td>
                                        <button type="submit" name="return_sale" class="btn btn-warning" style="padding: 6px 12px; font-size: 11px;"
                                                onclick="return confirmReturn(<?php echo $row['id']; ?>, '<?php echo addslashes($row['product_name']); ?>', this.form)">
                                            <i class="fas fa-undo"></i> Process
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-inbox"></i>
                    <h3>No sales available for return</h3>
                    <p>Try different search criteria</p>
                </div>
                <?php endif; } ?>
            </div>
        </div>

        <!-- Returns History Section (unchanged - already correct) -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-history"></i> Returns History
                </div>
            </div>
            <div class="card-content">
                <div class="date-filter-container">
                    <div class="date-filter-buttons">
                        <button class="date-filter-btn <?php echo $date_filter == 'today' ? 'active' : ''; ?>" onclick="setDateFilter('today')">Today</button>
                        <button class="date-filter-btn <?php echo $date_filter == 'yesterday' ? 'active' : ''; ?>" onclick="setDateFilter('yesterday')">Yesterday</button>
                        <button class="date-filter-btn <?php echo $date_filter == 'this_week' ? 'active' : ''; ?>" onclick="setDateFilter('this_week')">This Week</button>
                        <button class="date-filter-btn <?php echo $date_filter == 'this_month' ? 'active' : ''; ?>" onclick="setDateFilter('this_month')">This Month</button>
                        <button class="date-filter-btn <?php echo $date_filter == 'last_month' ? 'active' : ''; ?>" onclick="setDateFilter('last_month')">Last Month</button>
                        <button class="date-filter-btn <?php echo $date_filter == 'all' ? 'active' : ''; ?>" onclick="setDateFilter('all')">All Time</button>
                    </div>
                    
                    <form method="get" action="" class="custom-date-form">
                        <input type="date" name="start_date" class="custom-date-input" 
                               value="<?php echo isset($_GET['start_date']) ? $_GET['start_date'] : ''; ?>">
                        <input type="date" name="end_date" class="custom-date-input" 
                               value="<?php echo isset($_GET['end_date']) ? $_GET['end_date'] : ''; ?>">
                        <input type="hidden" name="date_filter" value="custom">
                        <button type="submit" class="btn btn-primary" style="padding: 6px 12px;">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                    </form>
                </div>
                
                <?php
                $date_condition = "";
                $total_return_amount = 0;
                $total_return_count = 0;
                $total_return_items = 0;
                
                if ($date_filter == 'today') {
                    $date_condition = "DATE(return_date) = CURDATE()";
                } elseif ($date_filter == 'yesterday') {
                    $date_condition = "DATE(return_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
                } elseif ($date_filter == 'this_week') {
                    $date_condition = "YEARWEEK(return_date, 1) = YEARWEEK(CURDATE(), 1)";
                } elseif ($date_filter == 'this_month') {
                    $date_condition = "MONTH(return_date) = MONTH(CURDATE()) AND YEAR(return_date) = YEAR(CURDATE())";
                } elseif ($date_filter == 'last_month') {
                    $date_condition = "MONTH(return_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) 
                                       AND YEAR(return_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
                } elseif ($date_filter == 'custom' && isset($_GET['start_date']) && isset($_GET['end_date'])) {
                    $start_date = mysqli_real_escape_string($conn, $_GET['start_date']);
                    $end_date = mysqli_real_escape_string($conn, $_GET['end_date']);
                    if (!empty($start_date) && !empty($end_date)) {
                        $date_condition = "DATE(return_date) BETWEEN '$start_date' AND '$end_date'";
                    }
                }
                
                $summary_sql = "SELECT 
                                COUNT(*) as total_returns,
                                SUM(return_quantity) as total_items,
                                SUM(return_amount) as total_return_amount
                                FROM returns_tracking2";
                if (!empty($date_condition)) {
                    $summary_sql .= " WHERE $date_condition";
                }
                
                $summary_result = $conn->query($summary_sql);
                $summary = $summary_result->fetch_assoc();
                $total_return_count = $summary['total_returns'] ?? 0;
                $total_return_amount = $summary['total_return_amount'] ?? 0;
                $total_return_items = $summary['total_items'] ?? 0;
                ?>
                
                <div class="returns-summary">
                    <div class="summary-item">
                        <div class="summary-label">Period</div>
                        <div class="summary-value">
                            <?php 
                            if ($date_filter == 'today') echo 'Today';
                            elseif ($date_filter == 'yesterday') echo 'Yesterday';
                            elseif ($date_filter == 'this_week') echo 'This Week';
                            elseif ($date_filter == 'this_month') echo 'This Month';
                            elseif ($date_filter == 'last_month') echo 'Last Month';
                            elseif ($date_filter == 'custom' && isset($_GET['start_date']) && isset($_GET['end_date'])) 
                                echo htmlspecialchars($_GET['start_date']) . ' to ' . htmlspecialchars($_GET['end_date']);
                            else echo 'All Time';
                            ?>
                        </div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Returns</div>
                        <div class="summary-value"><?php echo $total_return_count; ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Items</div>
                        <div class="summary-value"><?php echo $total_return_items; ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Amount</div>
                        <div class="summary-value">Rs<?php echo number_format($total_return_amount, 2); ?></div>
                    </div>
                </div>
                
                <?php
                $recent_returns_sql = "SELECT rt.*, cu.name as cashier_name 
                                       FROM returns_tracking2 rt
                                       LEFT JOIN cashier_users2 cu ON rt.cashier_id = cu.cashier_id";
                if (!empty($date_condition)) {
                    $recent_returns_sql .= " WHERE $date_condition";
                }
                $recent_returns_sql .= " ORDER BY return_date DESC LIMIT 100";
                
                $recent_result = $conn->query($recent_returns_sql);
                
                if ($recent_result->num_rows > 0):
                ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Bill No</th>
                                <th>Product</th>
                                <th>Return Qty</th>
                                <th>Return Amount</th>
                                <th>Reason</th>
                                <th>Processed By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($return = $recent_result->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <?php echo date('Y-m-d', strtotime($return['return_date'])); ?><br>
                                    <small style="color: var(--gray);"><?php echo date('H:i:s', strtotime($return['return_date'])); ?></small>
                                </td>
                                <td><strong style="color: var(--primary);"><?php echo $return['bill_no']; ?></strong></td>
                                <td>
                                    <div class="product-info">
                                        <span class="product-name"><?php echo htmlspecialchars($return['product_name']); ?></span>
                                        <?php if (!empty($return['imei'])): ?>
                                            <span class="imei-badge"><?php echo htmlspecialchars($return['imei']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><span style="font-weight: 600; color: var(--danger);"><?php echo $return['return_quantity']; ?></span></td>
                                <td>
                                    <strong style="color: var(--danger);">Rs<?php echo number_format($return['return_amount'], 2); ?></strong>
                                    <br><small>Unit: Rs<?php echo number_format($return['return_amount'] / $return['return_quantity'], 2); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($return['return_reason']); ?></td>
                                <td><?php echo htmlspecialchars($return['cashier_name'] ?? 'N/A'); ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-history"></i>
                    <h3>No returns found</h3>
                    <p>No returns processed for the selected period</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="notification" id="notification">
        <i class="fas fa-check-circle"></i> Return processed successfully!
    </div>

    <script>
        function updateClock() {
            const now = new Date();
            const options = { year: 'numeric', month: 'short', day: 'numeric' };
            document.querySelector('.current-date').textContent = now.toLocaleDateString('en-US', options);
            let hours = now.getHours();
            let minutes = now.getMinutes();
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12 || 12;
            minutes = minutes < 10 ? '0' + minutes : minutes;
            document.querySelector('.current-time').textContent = `${hours}:${minutes} ${ampm}`;
            setTimeout(updateClock, 60000);
        }
        updateClock();

        function confirmReturn(itemId, productName, form) {
            const quantityInput = form.querySelector('input[name="return_quantity"]');
            const quantity = quantityInput.value;
            const maxQuantity = quantityInput.max;
            const reasonInput = form.querySelector('textarea[name="return_reason"]');
            const reason = reasonInput.value.trim();
            const preview = document.getElementById(`preview_${itemId}`);
            const returnAmount = preview.textContent.match(/Rs([\d.,]+)/);
            
            if (!reason) {
                alert('Please enter a reason for return.');
                reasonInput.focus();
                return false;
            }
            
            if (parseInt(quantity) > parseInt(maxQuantity)) {
                alert(`Cannot return more than ${maxQuantity} items.`);
                return false;
            }
            
            return confirm(`Process return for: ${productName}\n\nQuantity: ${quantity}\nReturn Amount: ${returnAmount ? returnAmount[1] : '0'}\nReason: ${reason}\n\nAre you sure?`);
        }

        function calculateReturnAmount(input, unitTotal) {
            const quantity = parseInt(input.value) || 0;
            const max = parseInt(input.max);
            
            let validQuantity = quantity;
            if (validQuantity > max) {
                validQuantity = max;
                input.value = max;
            }
            if (validQuantity < 1) {
                validQuantity = 1;
                input.value = 1;
            }
            
            const returnAmount = (unitTotal * validQuantity).toFixed(2);
            const row = input.closest('tr');
            const previewSpan = row.querySelector('.amount-preview');
            if (previewSpan) {
                previewSpan.textContent = `Return: Rs${returnAmount}`;
            }
        }

        function setDateFilter(filter) {
            const url = new URL(window.location.href);
            url.searchParams.set('date_filter', filter);
            url.searchParams.delete('start_date');
            url.searchParams.delete('end_date');
            window.location.href = url.toString();
        }

        function clearSearch() {
            document.getElementById('search_bill').value = '';
            document.getElementById('search_product').value = '';
            document.getElementById('search_imei').value = '';
            if (window.location.search.includes('search_sale')) {
                window.location.href = window.location.pathname;
            }
        }

        function showNotification(message, type = 'success') {
            const notification = document.getElementById('notification');
            notification.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'}"></i> ${message}`;
            notification.style.display = 'flex';
            if (type === 'error') {
                notification.style.background = 'var(--danger)';
            } else {
                notification.style.background = 'var(--primary)';
            }
            setTimeout(() => {
                notification.style.display = 'none';
                notification.style.background = 'var(--primary)';
            }, 3000);
        }

        document.addEventListener('DOMContentLoaded', function() {
            const quantityInputs = document.querySelectorAll('.quantity-input');
            quantityInputs.forEach(input => {
                const row = input.closest('tr');
                const priceCell = row.querySelector('td:nth-child(7)');
                if (priceCell) {
                    const unitMatch = priceCell.textContent.match(/Unit: Rs([\d.,]+)/);
                    if (unitMatch) {
                        const unitTotal = parseFloat(unitMatch[1].replace(/,/g, ''));
                        calculateReturnAmount(input, unitTotal);
                    }
                }
                input.addEventListener('input', function() {
                    const row = this.closest('tr');
                    const priceCell = row.querySelector('td:nth-child(7)');
                    if (priceCell) {
                        const unitMatch = priceCell.textContent.match(/Unit: Rs([\d.,]+)/);
                        if (unitMatch) {
                            const unitTotal = parseFloat(unitMatch[1].replace(/,/g, ''));
                            calculateReturnAmount(this, unitTotal);
                        }
                    }
                });
            });
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>