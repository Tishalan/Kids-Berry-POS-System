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

// First, check and modify returns_tracking table structure if needed
$check_columns = [
    'item_id',
    'original_quantity', 
    'original_price',
    'original_discount'
];

foreach ($check_columns as $column) {
    $check_sql = "SHOW COLUMNS FROM returns_tracking LIKE '$column'";
    $check_result = $conn->query($check_sql);
    
    if ($check_result->num_rows == 0) {
        // Determine column type
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
            $alter_sql = "ALTER TABLE returns_tracking ADD COLUMN $column $column_type";
            $conn->query($alter_sql);
        }
    }
}

// Create returns_tracking table if not exists (with updated structure)
$create_table_sql = "CREATE TABLE IF NOT EXISTS returns_tracking (
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

// IMPORTANT FIX: Add columns to bill_items table to track returns without affecting original data
// First check if the columns already exist
$check_has_returns = "SHOW COLUMNS FROM bill_items LIKE 'has_returns'";
$has_returns_result = $conn->query($check_has_returns);

$check_returned_qty = "SHOW COLUMNS FROM bill_items LIKE 'total_returned_quantity'";
$returned_qty_result = $conn->query($check_returned_qty);

$check_returned_amount = "SHOW COLUMNS FROM bill_items LIKE 'total_returned_amount'";
$returned_amount_result = $conn->query($check_returned_amount);

// Add missing columns one by one with proper error handling
if ($has_returns_result->num_rows == 0) {
    $alter1 = "ALTER TABLE bill_items ADD COLUMN has_returns TINYINT DEFAULT 0";
    if (!$conn->query($alter1)) {
        // Silent fail - column might already exist or table issue
    }
}

if ($returned_qty_result->num_rows == 0) {
    $alter2 = "ALTER TABLE bill_items ADD COLUMN total_returned_quantity INT DEFAULT 0";
    if (!$conn->query($alter2)) {
        // Silent fail
    }
}

if ($returned_amount_result->num_rows == 0) {
    $alter3 = "ALTER TABLE bill_items ADD COLUMN total_returned_amount DECIMAL(10,2) DEFAULT 0";
    if (!$conn->query($alter3)) {
        // Silent fail
    }
}

// Handle return submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['return_sale'])) {
    $bill_no = mysqli_real_escape_string($conn, $_POST['bill_no']);
    $item_id = intval($_POST['item_id']); // Get the specific item ID
    $imei = isset($_POST['imei']) ? mysqli_real_escape_string($conn, $_POST['imei']) : '';
    $return_reason = mysqli_real_escape_string($conn, $_POST['return_reason']);
    $return_quantity = intval($_POST['return_quantity']);
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // 1. Get specific sale item details using item_id
        $get_sale_sql = "SELECT bi.*, 
                         (bi.price * bi.quantity - bi.discount) as total_amount,
                         bi.discount as total_discount,
                         bi.quantity as original_quantity,
                         bi.price as original_price,
                         COALESCE(bi.total_returned_quantity, 0) as total_returned_quantity,
                         COALESCE(bi.total_returned_amount, 0) as total_returned_amount
                         FROM bill_items bi
                         WHERE bi.id = '$item_id' 
                         AND bi.bill_no = '$bill_no'";
        
        $sale_result = $conn->query($get_sale_sql);
        
        if ($sale_result->num_rows > 0) {
            $sale = $sale_result->fetch_assoc();
            
            // Calculate available quantity for return (original - already returned)
            $available_for_return = $sale['quantity'] - $sale['total_returned_quantity'];
            
            // Validate return quantity
            if ($return_quantity > $available_for_return || $return_quantity <= 0) {
                throw new Exception("Invalid return quantity! Available for return: " . $available_for_return);
            }
            
            // 2. Calculate return amounts
            $total_amount = $sale['total_amount'];
            $unit_total_amount = $sale['quantity'] > 0 ? ($total_amount / $sale['quantity']) : 0;
            $return_amount = $unit_total_amount * $return_quantity;
            
            // Calculate return discount proportionally
            $total_discount = $sale['total_discount'];
            $unit_discount = $sale['quantity'] > 0 ? ($total_discount / $sale['quantity']) : 0;
            $return_discount = $unit_discount * $return_quantity;
            
            // 3. IMPORTANT FIX: Instead of modifying original data, just track returns
            // Update the bill_items to track that some quantity has been returned
            $new_returned_quantity = $sale['total_returned_quantity'] + $return_quantity;
            $new_returned_amount = $sale['total_returned_amount'] + $return_amount;
            $has_returns = 1;
            
            $update_item_sql = "UPDATE bill_items 
                               SET has_returns = $has_returns,
                                   total_returned_quantity = $new_returned_quantity,
                                   total_returned_amount = $new_returned_amount
                               WHERE id = '$item_id'";
            
            if (!$conn->query($update_item_sql)) {
                throw new Exception("Failed to update return tracking: " . $conn->error);
            }
            
            // 4. Update product stock - return stock back
            $product_name = mysqli_real_escape_string($conn, $sale['product_name']);
            $get_product_sql = "SELECT product_id FROM products 
                                WHERE name = '$product_name' 
                                LIMIT 1";
            $product_result = $conn->query($get_product_sql);
            
            if ($product_result->num_rows > 0) {
                $product = $product_result->fetch_assoc();
                $product_id = $product['product_id'];
                
                // Add the returned quantity back to stock
                $product_update_sql = "UPDATE products 
                                       SET stock = stock + $return_quantity
                                       WHERE product_id = '$product_id'";
                                       
                if (!$conn->query($product_update_sql)) {
                    throw new Exception("Failed to update product stock: " . $conn->error);
                }
            } else {
                throw new Exception("Product not found in inventory!");
            }
            
            // 5. Insert into returns tracking table
            $tracking_columns = [];
            $tracking_values = [];
            
            // Basic columns that should always exist
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
            
            // Optional columns - add if they exist
            $check_optional = ['item_id', 'original_quantity', 'original_price', 'original_discount', 'branch_id'];
            
            foreach ($check_optional as $col) {
                $check_sql = "SHOW COLUMNS FROM returns_tracking LIKE '$col'";
                $check_result = $conn->query($check_sql);
                if ($check_result->num_rows > 0) {
                    $tracking_columns[] = $col;
                    switch($col) {
                        case 'item_id':
                            $tracking_values[] = "$item_id";
                            break;
                        case 'original_quantity':
                            $tracking_values[] = "'{$sale['original_quantity']}'";
                            break;
                        case 'original_price':
                            $tracking_values[] = "'{$sale['original_price']}'";
                            break;
                        case 'original_discount':
                            $tracking_values[] = "'{$sale['total_discount']}'";
                            break;
                        case 'branch_id':
                            $tracking_values[] = "1"; // Default or from session
                            break;
                    }
                }
            }
            
            $tracking_sql = "INSERT INTO returns_tracking (" . implode(', ', $tracking_columns) . ")
                VALUES (" . implode(', ', $tracking_values) . ")";
                
            if (!$conn->query($tracking_sql)) {
                throw new Exception("Failed to insert into returns tracking: " . $conn->error);
            }
            
            // 6. Update bill totals (optional - if you want to show net amounts in bill)
            // But this doesn't affect the original sales data for reporting
            $check_bill_column = "SHOW COLUMNS FROM bills LIKE 'total_returned_amount'";
            $bill_column_result = $conn->query($check_bill_column);
            
            if ($bill_column_result->num_rows == 0) {
                // Add column if it doesn't exist
                $add_bill_column = "ALTER TABLE bills ADD COLUMN total_returned_amount DECIMAL(10,2) DEFAULT 0";
                $conn->query($add_bill_column);
            }
            
            $update_bill_sql = "UPDATE bills 
                                SET total_returned_amount = COALESCE(total_returned_amount, 0) + $return_amount
                                WHERE bill_no = '$bill_no'";
            
            $conn->query($update_bill_sql);
            
            // Commit transaction
            $conn->commit();
            
            $_SESSION['return_message'] = "Return processed successfully! Return Amount: Rs" . number_format($return_amount, 2);
            $_SESSION['return_message_type'] = "success";
            
        } else {
            throw new Exception("Sale record not found!");
        }
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $_SESSION['return_message'] = "Error processing return: " . $e->getMessage();
        $_SESSION['return_message_type'] = "error";
    }
    
    header("Location: return_sale.php");
    exit();
}

// Get date filter from GET or POST
$date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : (isset($_POST['date_filter']) ? $_POST['date_filter'] : 'today');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIDS Berry - Return Sales</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #5a3d7e;
            --secondary: #28a745;
            --accent: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --success: #28a745;
            --light: #f8f9fa;
            --dark: #343a40;
            --text: #212529;
            --card-shadow: 0 6px 12px rgba(0,0,0,0.15), 0 2px 6px rgba(0,0,0,0.1);
            --transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            --radius: 15px;
            --gradient-primary: linear-gradient(135deg, #5a3d7e 0%, #8e44ad 100%);
            --gradient-secondary: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            --gradient-accent: linear-gradient(135deg, #dc3545 0%, #ff6b6b 100%);
            --gradient-warning: linear-gradient(135deg, #ffc107 0%, #ffda6a 100%);
            --gradient-info: linear-gradient(135deg, #17a2b8 0%, #5bc0de 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
            color: var(--text);
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--secondary), var(--primary), var(--accent));
            z-index: 1000;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        .header {
            display: flex;
            justify-content: center;
            align-items: center;
            background: var(--gradient-primary);
            color: white;
            padding: 18px 35px;
            border-radius: 0 0 var(--radius) var(--radius);
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
            animation: slideDown 0.7s ease;
            margin-bottom: 30px;
        }

        .header::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
            animation: shimmer 3s infinite;
        }

        .header-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex-grow: 1;
            z-index: 1;
        }

        .header h1 {
            margin: 0;
            font-size: 32px;
            font-weight: 800;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
            color: #ffffff;
            letter-spacing: 0.5px;
            animation: fadeIn 1s ease;
        }

        .header .back-btn {
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: white;
            text-decoration: none;
            background: rgba(255,255,255,0.15);
            padding: 12px 25px;
            border-radius: 50px;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            z-index: 1;
            animation: fadeInLeft 0.8s ease;
            border: 2px solid rgba(255,255,255,0.3);
            backdrop-filter: blur(10px);
        }

        .header .back-btn:hover {
            background: rgba(255,255,255,0.25);
            transform: translateY(-50%) translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .section {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--card-shadow);
            margin: 0 auto 30px;
            max-width: 1400px;
            animation: fadeInUp 0.8s ease;
            transition: var(--transition);
            border: 1px solid rgba(0,0,0,0.05);
            overflow: hidden;
        }

        .section:hover {
            box-shadow: 0 15px 30px rgba(0,0,0,0.15), 0 5px 15px rgba(0,0,0,0.07);
        }

        .section-header {
            background: var(--gradient-primary);
            color: white;
            padding: 25px 30px;
            border-bottom: 3px solid rgba(255,255,255,0.2);
            position: relative;
            overflow: hidden;
        }

        .section-header::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
            animation: shimmer 2s infinite;
        }

        .section-header h2 {
            color: white;
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 26px;
            position: relative;
            z-index: 1;
        }

        .section-header h2 i {
            background: rgba(255,255,255,0.2);
            padding: 12px;
            border-radius: 12px;
            backdrop-filter: blur(10px);
        }

        .section-content {
            padding: 30px;
        }

        .form-group {
            margin-bottom: 25px;
            animation: fadeIn 0.8s ease;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 12px;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
        }

        .form-group label i {
            color: var(--primary);
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 15px 20px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            transition: var(--transition);
            background: #fafafa;
            font-weight: 500;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            border-color: var(--secondary);
            box-shadow: 0 0 0 4px rgba(40, 167, 69, 0.15);
            outline: none;
            background: white;
            transform: translateY(-2px);
        }

        .btn {
            padding: 14px 28px;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            font-weight: 700;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
            animation: fadeInUp 0.8s ease;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: 0.5s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-info {
            background: var(--gradient-info);
            color: white;
        }

        .btn-info:hover {
            background: linear-gradient(135deg, #138496 0%, #0ba8cc 100%);
            transform: translateY(-3px);
            box-shadow: 0 8px 15px rgba(23, 162, 184, 0.3);
        }

        .btn-warning {
            background: var(--gradient-warning);
            color: var(--dark);
        }

        .btn-warning:hover {
            background: linear-gradient(135deg, #e0a800 0%, #ffc107 100%);
            transform: translateY(-3px);
            box-shadow: 0 8px 15px rgba(255, 193, 7, 0.3);
        }

        .btn-success {
            background: var(--gradient-secondary);
            color: white;
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #218838 0%, #34ce57 100%);
            transform: translateY(-3px);
            box-shadow: 0 8px 15px rgba(40, 167, 69, 0.3);
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #4a2d6b 0%, #6a4d8f 100%);
            transform: translateY(-3px);
            box-shadow: 0 8px 15px rgba(90, 61, 126, 0.3);
        }

        .date-filter-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 20px;
            animation: fadeIn 0.8s ease;
        }

        .date-filter-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .date-filter-btn {
            padding: 10px 20px;
            border: 2px solid #ddd;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }

        .date-filter-btn:hover {
            background: #f8f9fa;
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .date-filter-btn.active {
            background: var(--gradient-primary);
            color: white;
            border-color: var(--primary);
            box-shadow: 0 3px 6px rgba(90, 61, 126, 0.2);
        }

        .custom-date-form {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 20px;
            border-radius: var(--radius);
            border: 1px solid rgba(0,0,0,0.05);
        }

        .custom-date-input {
            padding: 10px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: var(--transition);
            font-weight: 500;
            background: white;
        }

        .custom-date-input:focus {
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
            outline: none;
        }

        .table-container {
            overflow-x: auto;
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            margin-bottom: 25px;
            animation: fadeIn 0.8s ease;
            border: 1px solid rgba(0,0,0,0.05);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            animation: fadeInUp 0.8s ease;
        }

        table th {
            background: var(--gradient-primary);
            color: white;
            padding: 18px;
            text-align: left;
            font-weight: 700;
            position: sticky;
            top: 0;
            font-size: 15px;
        }

        table tr {
            transition: var(--transition);
            animation: fadeIn 0.5s ease;
        }

        table tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        table tr:hover {
            background-color: #e8f4fc;
            transform: translateX(5px);
        }

        table td {
            padding: 16px;
            border-bottom: 1px solid #eee;
            font-size: 14px;
        }

        .return-form {
            display: contents;
        }

        .quantity-input {
            width: 90px;
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 8px;
            transition: var(--transition);
            font-size: 14px;
            font-weight: 500;
        }

        .quantity-input:focus {
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
            outline: none;
        }

        .alert {
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: slideInRight 0.5s ease;
            font-weight: 600;
            border-left: 5px solid;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border-left-color: var(--success);
        }

        .alert-error {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border-left-color: var(--accent);
        }

        .amount-preview {
            font-size: 13px;
            color: var(--success);
            margin-top: 8px;
            font-weight: 700;
            animation: fadeIn 0.3s ease;
            background: rgba(40, 167, 69, 0.1);
            padding: 5px 10px;
            border-radius: 6px;
            display: inline-block;
        }

        .no-data {
            text-align: center;
            padding: 60px 40px;
            color: #7f8c8d;
            animation: fadeIn 0.8s ease;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: var(--radius);
            border: 2px dashed #dee2e6;
        }

        .no-data i {
            font-size: 60px;
            margin-bottom: 20px;
            color: var(--primary);
            opacity: 0.7;
        }

        .no-data h3 {
            color: var(--dark);
            margin-bottom: 15px;
            font-size: 24px;
            font-weight: 700;
        }

        .search-form {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 25px;
            border-radius: var(--radius);
            margin-bottom: 25px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            animation: fadeInUp 0.8s ease;
            border: 1px solid rgba(0,0,0,0.05);
        }

        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-row .form-group {
            flex: 1;
            margin-bottom: 0;
        }

        .returns-summary {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--gradient-primary);
            padding: 25px 30px;
            border-radius: var(--radius);
            margin-bottom: 25px;
            animation: fadeIn 0.8s ease;
            color: white;
            box-shadow: 0 4px 8px rgba(90, 61, 126, 0.2);
            position: relative;
            overflow: hidden;
        }

        .returns-summary::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
            animation: shimmer 2s infinite;
        }

        .summary-item {
            text-align: center;
            flex: 1;
            position: relative;
            z-index: 1;
        }

        .summary-label {
            font-size: 15px;
            color: rgba(255,255,255,0.9);
            margin-bottom: 10px;
            font-weight: 600;
        }

        .summary-value {
            font-size: 28px;
            font-weight: 800;
            color: white;
        }

        .product-info {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .product-name {
            font-weight: 700;
            color: var(--primary);
        }

        .product-details {
            font-size: 12px;
            color: #666;
        }

        .imei-badge {
            display: inline-block;
            background: #e9ecef;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-family: monospace;
            margin-top: 3px;
        }

        /* Return status badge */
        .return-status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            margin-top: 5px;
        }
        
        .status-partial {
            background: #ffc107;
            color: #000;
        }
        
        .status-returned {
            background: var(--accent);
            color: white;
        }

        @keyframes slideDown {
            from { transform: translateY(-100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes fadeInUp {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @keyframes fadeInLeft {
            from { transform: translateX(-30px) translateY(-50%); opacity: 0; }
            to { transform: translateX(0) translateY(-50%); opacity: 1; }
        }

        @keyframes slideInRight {
            from { transform: translateX(100px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes shimmer {
            100% { transform: translateX(100%); }
        }

        .notification {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: var(--gradient-success);
            color: white;
            padding: 18px 30px;
            border-radius: 10px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
            display: none;
            z-index: 1000;
            animation: slideInRight 0.5s ease, fadeOut 0.5s ease 2.5s forwards;
            font-weight: 600;
            max-width: 350px;
            backdrop-filter: blur(10px);
        }

        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; visibility: hidden; }
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                text-align: center;
                padding: 20px;
            }

            .header .back-btn {
                position: relative;
                left: 0;
                top: 0;
                transform: none;
                margin-top: 15px;
                align-self: center;
            }

            .form-row {
                flex-direction: column;
                gap: 15px;
            }

            .date-filter-container {
                flex-direction: column;
                align-items: flex-start;
            }

            .date-filter-buttons {
                width: 100%;
                justify-content: center;
            }

            .custom-date-form {
                width: 100%;
                justify-content: center;
            }

            .returns-summary {
                flex-direction: column;
                gap: 25px;
                text-align: center;
            }

            table {
                font-size: 13px;
            }

            table th,
            table td {
                padding: 12px 8px;
            }

            .btn {
                padding: 12px 20px;
                font-size: 14px;
            }
        }

        @media (max-width: 480px) {
            .header h1 {
                font-size: 24px;
            }
            
            .section-header h2 {
                font-size: 20px;
            }
            
            .date-filter-btn {
                padding: 8px 12px;
                font-size: 12px;
            }
            
            .summary-value {
                font-size: 22px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <a href="bill_management.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Bill Management
        </a>
        <div class="header-content">
            <h1><i class="fas fa-exchange-alt"></i> Return Sales Management</h1>
        </div>
    </div>
    
    <div class="section">
        <div class="section-header">
            <h2><i class="fas fa-search"></i> Find Sale to Return</h2>
        </div>
        
        <div class="section-content">
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
                            <label for="search_bill"><i class="fas fa-receipt"></i> Bill Number:</label>
                            <input type="text" id="search_bill" name="search_bill" placeholder="Enter Bill No (e.g., KB20240115001)" 
                                   value="<?php echo isset($_POST['search_bill']) ? htmlspecialchars($_POST['search_bill']) : ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="search_product"><i class="fas fa-box"></i> Product Name:</label>
                            <input type="text" id="search_product" name="search_product" placeholder="Enter Product Name" 
                                   value="<?php echo isset($_POST['search_product']) ? htmlspecialchars($_POST['search_product']) : ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="search_imei"><i class="fas fa-barcode"></i> IMEI/Serial:</label>
                            <input type="text" id="search_imei" name="search_imei" placeholder="Enter IMEI or Serial" 
                                   value="<?php echo isset($_POST['search_imei']) ? htmlspecialchars($_POST['search_imei']) : ''; ?>">
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 15px;">
                        <button type="submit" name="search_sale" class="btn btn-info">
                            <i class="fas fa-search"></i> Search Sales
                        </button>
                        <button type="button" class="btn btn-warning" onclick="clearSearch()">
                            <i class="fas fa-redo"></i> Clear Search
                        </button>
                    </div>
                </form>
            </div>
            
            <?php
            // Search functionality
            if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['search_sale'])) {
                $search_bill = mysqli_real_escape_string($conn, $_POST['search_bill'] ?? '');
                $search_product = mysqli_real_escape_string($conn, $_POST['search_product'] ?? '');
                $search_imei = mysqli_real_escape_string($conn, $_POST['search_imei'] ?? '');
                
                // IMPORTANT FIX: Show items that haven't been fully returned
                $search_sql = "SELECT bi.*, 
                              (bi.price * bi.quantity - bi.discount) as total_amount,
                              b.date, 
                              c.name as cashier_name,
                              b.customer_name,
                              b.phone_no,
                              (bi.quantity - COALESCE(bi.total_returned_quantity, 0)) as available_quantity,
                              COALESCE(bi.total_returned_quantity, 0) as returned_quantity
                               FROM bill_items bi
                               JOIN bills b ON bi.bill_no = b.bill_no
                               JOIN cashier_users c ON b.cashier_id = c.cashier_id
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
            <div class="search-summary" style="background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px;">
                <h3 style="margin: 0; color: var(--primary);">
                    <i class="fas fa-clipboard-list"></i> Found <?php echo $search_result->num_rows; ?> sale items
                </h3>
            </div>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Bill Details</th>
                            <th>Product Details</th>
                            <th>Original Qty</th>
                            <th>Returned Qty</th>
                            <th>Available</th>
                            <th>Price & Amount</th>
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
                            
                            // Skip if no quantity available for return
                            if ($available_qty <= 0) continue;
                        ?>
                        <tr>
                            <td><?php echo $counter++; ?></td>
                            <td>
                                <div class="product-info">
                                    <strong style="color: var(--primary);"><?php echo $row['bill_no']; ?></strong>
                                    <div style="font-size: 12px; color: #666;">
                                        <?php echo date('Y-m-d', strtotime($row['date'])); ?>
                                        <?php if (!empty($row['customer_name'])): ?>
                                            <br>
                                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($row['customer_name']); ?>
                                            <?php if (!empty($row['phone_no'])): ?>
                                                <br><i class="fas fa-phone"></i> <?php echo htmlspecialchars($row['phone_no']); ?>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="product-info">
                                    <span class="product-name"><?php echo htmlspecialchars($row['product_name']); ?></span>
                                    <?php if (!empty($row['product_color'])): ?>
                                        <span class="product-details">Color: <?php echo htmlspecialchars($row['product_color']); ?></span>
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
                                    <span style="color: var(--accent); font-weight: 700;"><?php echo $returned_qty; ?></span>
                                <?php else: ?>
                                    <span style="color: #999;">0</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong style="color: <?php echo $available_qty > 0 ? 'var(--success)' : '#999'; ?>; font-size: 16px;">
                                    <?php echo $available_qty; ?>
                                </strong>
                            </td>
                            <td>
                                <div style="text-align: right;">
                                    <strong>Rs<?php echo number_format($row['total_amount'], 2); ?></strong><br>
                                    <small style="color:#666;">
                                        Unit: Rs<?php echo number_format($unit_total, 2); ?>
                                    </small>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($row['cashier_name']); ?></td>
                            <td>
                                <form method="post" class="return-form">
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
                                    <textarea name="return_reason" placeholder="Reason for return" rows="1" 
                                              style="width: 150px; padding: 8px; border-radius: 6px;" required></textarea>
                            </td>
                            <td>
                                    <button type="submit" name="return_sale" class="btn btn-warning"
                                            onclick="return confirmReturn(<?php echo $row['id']; ?>, '<?php echo addslashes($row['product_name']); ?>')">
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
                <p>Try different search criteria or all items might have been returned already</p>
            </div>
            <?php endif; } ?>
        </div>
    </div>
    
    <div class="section">
        <div class="section-header">
            <h2><i class="fas fa-history"></i> Recent Returns History</h2>
        </div>
        
        <div class="section-content">
            <div class="date-filter-container">
                <div class="date-filter-buttons">
                    <button class="date-filter-btn <?php echo $date_filter == 'today' ? 'active' : ''; ?>" onclick="setDateFilter('today')">
                        <i class="fas fa-calendar-day"></i> Today
                    </button>
                    <button class="date-filter-btn <?php echo $date_filter == 'yesterday' ? 'active' : ''; ?>" onclick="setDateFilter('yesterday')">
                        <i class="fas fa-calendar-minus"></i> Yesterday
                    </button>
                    <button class="date-filter-btn <?php echo $date_filter == 'this_week' ? 'active' : ''; ?>" onclick="setDateFilter('this_week')">
                        <i class="fas fa-calendar-week"></i> This Week
                    </button>
                    <button class="date-filter-btn <?php echo $date_filter == 'this_month' ? 'active' : ''; ?>" onclick="setDateFilter('this_month')">
                        <i class="fas fa-calendar-alt"></i> This Month
                    </button>
                    <button class="date-filter-btn <?php echo $date_filter == 'last_month' ? 'active' : ''; ?>" onclick="setDateFilter('last_month')">
                        <i class="fas fa-calendar-times"></i> Last Month
                    </button>
                    <button class="date-filter-btn <?php echo $date_filter == 'all' ? 'active' : ''; ?>" onclick="setDateFilter('all')">
                        <i class="fas fa-infinity"></i> All Time
                    </button>
                </div>
                
                <form method="get" action="" class="custom-date-form">
                    <input type="date" name="start_date" class="custom-date-input" 
                           value="<?php echo isset($_GET['start_date']) ? $_GET['start_date'] : ''; ?>" 
                           placeholder="Start Date">
                    <input type="date" name="end_date" class="custom-date-input" 
                           value="<?php echo isset($_GET['end_date']) ? $_GET['end_date'] : ''; ?>" 
                           placeholder="End Date">
                    <input type="hidden" name="date_filter" value="custom">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                </form>
            </div>
            
            <?php
            // Calculate date range based on filter
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
            
            // Get returns data with date filter
            $recent_returns_sql = "SELECT rt.*, cu.name as cashier_name 
                                   FROM returns_tracking rt
                                   LEFT JOIN cashier_users cu ON rt.cashier_id = cu.cashier_id";
            if (!empty($date_condition)) {
                $recent_returns_sql .= " WHERE $date_condition";
            }
            $recent_returns_sql .= " ORDER BY return_date DESC LIMIT 100";
            
            $recent_result = $conn->query($recent_returns_sql);
            
            // Get summary statistics
            $summary_sql = "SELECT 
                            COUNT(*) as total_returns,
                            SUM(return_quantity) as total_items,
                            SUM(return_amount) as total_return_amount
                            FROM returns_tracking";
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
                    <div class="summary-label">Selected Period</div>
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
                    <div class="summary-label">Total Returns</div>
                    <div class="summary-value"><?php echo $total_return_count; ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Items Returned</div>
                    <div class="summary-value"><?php echo $total_return_items; ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Total Return Amount</div>
                    <div class="summary-value">Rs<?php echo number_format($total_return_amount, 2); ?></div>
                </div>
            </div>
            
            <?php if ($recent_result->num_rows > 0): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Bill No</th>
                            <th>Product</th>
                            <th>Original Qty</th>
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
                                <small style="color: #666;"><?php echo date('H:i:s', strtotime($return['return_date'])); ?></small>
                            </td>
                            <td><strong><?php echo $return['bill_no']; ?></strong></td>
                            <td>
                                <div class="product-info">
                                    <span class="product-name"><?php echo htmlspecialchars($return['product_name']); ?></span>
                                    <?php if (!empty($return['imei'])): ?>
                                        <span class="imei-badge"><?php echo htmlspecialchars($return['imei']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?php echo $return['original_quantity'] ?? $return['return_quantity']; ?></td>
                            <td>
                                <span style="font-weight: 700; color: var(--accent);"><?php echo $return['return_quantity']; ?></span>
                            </td>
                            <td>
                                <strong style="color: var(--accent);">Rs<?php echo number_format($return['return_amount'], 2); ?></strong>
                                <br>
                                <small style="color: #666;">
                                    Unit: Rs<?php echo number_format($return['return_amount'] / $return['return_quantity'], 2); ?>
                                </small>
                            </td>
                            <td><?php echo htmlspecialchars($return['return_reason']); ?></td>
                            <td><?php echo htmlspecialchars($return['cashier_name']); ?></td>
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
    
    <div class="notification" id="notification">
        <i class="fas fa-check-circle"></i> Return processed successfully!
    </div>
    
    <script>
    function confirmReturn(itemId, productName) {
        const form = document.querySelector(`input[name="item_id"][value="${itemId}"]`).closest('form');
        const quantity = form.querySelector('input[name="return_quantity"]').value;
        const maxQuantity = form.querySelector('input[name="return_quantity"]').max;
        const reason = form.querySelector('textarea[name="return_reason"]').value.trim();
        const previewText = document.getElementById(`preview_${itemId}`).textContent;
        const returnAmount = previewText.match(/Rs([\d.,]+)/)[1];
        
        if (!reason) {
            alert('Please enter a reason for return.');
            form.querySelector('textarea[name="return_reason"]').focus();
            return false;
        }
        
        if (parseInt(quantity) > parseInt(maxQuantity)) {
            alert(`Cannot return more than ${maxQuantity} items.`);
            return false;
        }
        
        return confirm(`Process return for: ${productName}\n\nQuantity: ${quantity} of ${maxQuantity}\nReturn Amount: Rs${returnAmount}\nReason: ${reason}\n\nAre you sure you want to process this return?`);
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
        const preview = input.parentNode.querySelector('.amount-preview');
        if (preview) {
            preview.textContent = `Return: Rs${returnAmount}`;
            preview.style.display = 'block';
        }
    }

    // Set date filter
    function setDateFilter(filter) {
        const url = new URL(window.location.href);
        url.searchParams.set('date_filter', filter);
        // Remove custom date parameters
        url.searchParams.delete('start_date');
        url.searchParams.delete('end_date');
        window.location.href = url.toString();
    }

    function clearSearch() {
        document.getElementById('search_bill').value = '';
        document.getElementById('search_product').value = '';
        document.getElementById('search_imei').value = '';
        
        // If we're in a POST search, reload the page without search parameters
        if (window.location.search.includes('search_sale')) {
            window.location.href = window.location.pathname;
        }
    }

    // Show notification
    function showNotification(message, type = 'success') {
        const notification = document.getElementById('notification');
        if (notification) {
            const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle';
            notification.innerHTML = `<i class="fas ${icon}"></i> ${message}`;
            notification.style.display = 'block';
            notification.className = 'notification';
            if (type === 'error') {
                notification.style.background = 'linear-gradient(135deg, #dc3545 0%, #ff6b6b 100%)';
            } else {
                notification.style.background = 'linear-gradient(135deg, #28a745 0%, #20c997 100%)';
            }
            
            setTimeout(() => {
                notification.style.display = 'none';
            }, 3000);
        }
    }

    // Initialize quantity previews
    document.addEventListener('DOMContentLoaded', function() {
        const quantityInputs = document.querySelectorAll('.quantity-input');
        quantityInputs.forEach(input => {
            // Get unit total from the price cell
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