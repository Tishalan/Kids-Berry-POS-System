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
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Use session branch_id instead of hardcoded value
$branch_id = isset($_SESSION['branch_id']) ? $_SESSION['branch_id'] : 1;

// Calculate total credit bills count from bill_summary
$total_credits_sql = "SELECT COUNT(*) as total_credits FROM bill_summary 
                      WHERE EXISTS (
                          SELECT 1 FROM bills 
                          WHERE bills.bill_no = bill_summary.bill_no 
                          AND bills.branch_id = $branch_id 
                          AND bills.payment_method = 'Credit'
                          AND bills.balance < 0
                      )";
$total_credits_result = $conn->query($total_credits_sql);
$total_credits = $total_credits_result->fetch_assoc()['total_credits'] ?? 0;

// Calculate total credit amount from bill_summary (only negative balances)
$total_credit_amount_sql = "SELECT SUM(ABS(balance)) as total_credit_amount FROM bill_summary 
                            WHERE EXISTS (
                                SELECT 1 FROM bills 
                                WHERE bills.bill_no = bill_summary.bill_no 
                                AND bills.branch_id = $branch_id 
                                AND bills.payment_method = 'Credit'
                                AND bills.balance < 0
                            )";
$total_credit_amount_result = $conn->query($total_credit_amount_sql);
$total_credit_amount = $total_credit_amount_result->fetch_assoc()['total_credit_amount'] ?? 0;

// Handle search
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$credit_bills_sql = "SELECT bs.*, b.payment_method, b.paid_amount, b.total as total_amount, b.date
                     FROM bill_summary bs 
                     JOIN bills b ON bs.bill_no = b.bill_no 
                     WHERE b.branch_id = $branch_id 
                     AND b.payment_method = 'Credit'
                     AND b.balance < 0";
if ($search) {
    $credit_bills_sql .= " AND (bs.customer_name LIKE '%$search%' OR bs.phone_no LIKE '%$search%' OR bs.nic_no LIKE '%$search%' OR bs.bill_no LIKE '%$search%')";
}
$credit_bills_sql .= " ORDER BY bs.date DESC";
$credit_bills_result = $conn->query($credit_bills_sql);

// Handle update payment
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_payment'])) {
    $bill_no = mysqli_real_escape_string($conn, $_POST['bill_no']);
    $payment_amount = floatval($_POST['payment_amount']);
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Verify this is a credit bill and get current balance
        $verify_sql = "SELECT b.*, bs.balance as summary_balance 
                       FROM bills b 
                       JOIN bill_summary bs ON b.bill_no = bs.bill_no 
                       WHERE b.bill_no = '$bill_no' 
                       AND b.payment_method = 'Credit' 
                       AND b.branch_id = $branch_id";
        $verify_result = $conn->query($verify_sql);
        
        if ($verify_result->num_rows == 0) {
            throw new Exception("This bill is not a credit bill or doesn't exist.");
        }
        
        $bill_data = $verify_result->fetch_assoc();
        $current_balance = floatval($bill_data['summary_balance']);
        
        // Validate payment amount
        if ($payment_amount <= 0) {
            throw new Exception("Payment amount must be greater than zero.");
        }
        if ($payment_amount > abs($current_balance)) {
            throw new Exception("Payment amount cannot exceed the outstanding balance of Rs" . number_format(abs($current_balance), 2));
        }
        
        // Update balances (since balance is negative, adding payment reduces the debt)
        $new_balance = $current_balance + $payment_amount;
        $new_paid_amount = floatval($bill_data['paid_amount']) + $payment_amount;
        
        // Update bills table
        $update_bills_sql = "UPDATE bills SET paid_amount = $new_paid_amount, balance = $new_balance WHERE bill_no = '$bill_no'";
        if (!$conn->query($update_bills_sql)) {
            throw new Exception("Error updating bills: " . $conn->error);
        }
        
        // Update bill_summary table
        $update_summary_sql = "UPDATE bill_summary SET balance = $new_balance WHERE bill_no = '$bill_no'";
        if (!$conn->query($update_summary_sql)) {
            throw new Exception("Error updating bill summary: " . $conn->error);
        }
        
        // Commit transaction
        $conn->commit();
        
        echo "<script>alert('Payment updated successfully! New balance: Rs" . number_format($new_balance, 2) . "'); window.location.href='credit_payments.php?search=" . urlencode($search) . "';</script>";
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        echo "<script>alert('Error updating payment: " . addslashes($e->getMessage()) . "');</script>";
    }
}

// Handle delete credit bill - FIXED: Do not restore stock
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_bill'])) {
    $bill_no = mysqli_real_escape_string($conn, $_POST['bill_no']);
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Verify the bill belongs to this branch and is a credit bill
        $verify_sql = "SELECT * FROM bills WHERE bill_no = '$bill_no' AND branch_id = $branch_id AND payment_method = 'Credit'";
        $verify_result = $conn->query($verify_sql);
        
        if ($verify_result->num_rows == 0) {
            throw new Exception("Credit bill not found or you don't have permission to delete it.");
        }
        
        // Delete from bill_summary
        $delete_summary_sql = "DELETE FROM bill_summary WHERE bill_no = '$bill_no'";
        if (!$conn->query($delete_summary_sql)) {
            throw new Exception("Error deleting from bill_summary: " . $conn->error);
        }
        
        // Delete from bill_items
        $delete_items_sql = "DELETE FROM bill_items WHERE bill_no = '$bill_no'";
        if (!$conn->query($delete_items_sql)) {
            throw new Exception("Error deleting from bill_items: " . $conn->error);
        }
        
        // Delete from bills
        $delete_bills_sql = "DELETE FROM bills WHERE bill_no = '$bill_no'";
        if (!$conn->query($delete_bills_sql)) {
            throw new Exception("Error deleting from bills: " . $conn->error);
        }
        
        // Commit transaction
        $conn->commit();
        
        echo "<script>alert('Credit bill deleted successfully! Stock remains unchanged as this was a valid sale.'); window.location.href='credit_payments.php?search=" . urlencode($search) . "';</script>";
        exit();
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        echo "<script>alert('Error deleting credit bill: " . addslashes($e->getMessage()) . "');</script>";
    }
}

// Handle logout
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['logout'])) {
    // Only destroy cashier related sessions
    unset($_SESSION['cashier_id']);
    unset($_SESSION['branch_id']);
    header("Location: ../index.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIDS Berry - Credit Customers</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #5a3d7e;
            --secondary: #28a745;
            --accent: #dc3545;
            --success: #28a745;
            --warning: #ffc107;
            --light: #f8f9fa;
            --dark: #343a40;
            --text: #212529;
            --card-shadow: 0 6px 12px rgba(0,0,0,0.15), 0 2px 6px rgba(0,0,0,0.1);
            --transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            --radius: 15px;
            --gradient-primary: linear-gradient(135deg, #5a3d7e 0%, #8e44ad 100%);
            --gradient-secondary: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            --gradient-accent: linear-gradient(135deg, #dc3545 0%, #ff6b6b 100%);
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
            justify-content: space-between;
            align-items: center;
            background: var(--gradient-primary);
            color: white;
            padding: 18px 35px;
            border-radius: 0 0 var(--radius) var(--radius);
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
            animation: slideDown 0.7s ease;
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
        }

        .header .date-time {
            font-size: 16px;
            text-align: center;
            margin-top: 5px;
            opacity: 0.9;
        }

        .logo-container img {
            width: 85px;
            height: 85px;
            border-radius: 50%;
            border: 3px solid white;
            box-shadow: 0 0 20px rgba(255,255,255,0.6);
            transition: var(--transition);
            z-index: 1;
        }

        .logo-container img:hover {
            transform: rotate(5deg) scale(1.05);
            box-shadow: 0 0 25px rgba(255,255,255,0.8);
        }

        .logout-btn {
            background: rgba(255,255,255,0.15);
            color: white;
            border: 2px solid rgba(255,255,255,0.3);
            padding: 12px 25px;
            border-radius: 50px;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
            font-size: 15px;
            backdrop-filter: blur(10px);
            z-index: 1;
        }

        .logout-btn:hover {
            background: rgba(255,255,255,0.25);
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .nav {
            margin: 25px auto;
            display: flex;
            justify-content: center;
            max-width: 95%;
            flex-wrap: wrap;
            gap: 10px;
        }

        .nav a {
            text-decoration: none;
            color: white;
            font-weight: 600;
            padding: 14px 28px;
            border-radius: 50px;
            background: var(--gradient-secondary);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }

        .nav a::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: 0.5s;
        }

        .nav a:hover::before {
            left: 100%;
        }

        .nav a.active {
            background: var(--gradient-accent);
            box-shadow: 0 6px 12px rgba(220, 53, 69, 0.3);
        }

        .nav a:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 15px rgba(0,0,0,0.2);
        }

        .main-container {
            max-width: 95%;
            margin: 0 auto 30px;
        }

        .card {
            background: white;
            border-radius: var(--radius);
            padding: 30px;
            box-shadow: var(--card-shadow);
            animation: fadeInUp 0.8s ease;
            border: 1px solid rgba(0,0,0,0.05);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .card:hover {
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: var(--gradient-primary);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 18px;
            border-bottom: 2px solid var(--light);
            position: relative;
        }

        .card-header::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 100px;
            height: 3px;
            background: var(--gradient-primary);
            border-radius: 3px;
        }

        .card-title {
            color: var(--primary);
            font-size: 26px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
        }

        .card-title i {
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 20px;
            border-radius: var(--radius);
            text-align: center;
            transition: var(--transition);
            border: 1px solid rgba(0,0,0,0.05);
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }

        .stat-label {
            color: var(--dark);
            font-size: 14px;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .stat-value {
            color: var(--primary);
            font-size: 28px;
            font-weight: 800;
        }

        .search-container {
            position: relative;
            margin-bottom: 25px;
        }

        .search-input {
            width: 100%;
            padding: 16px 20px;
            padding-left: 50px;
            border: 2px solid #e0e0e0;
            border-radius: var(--radius);
            font-size: 16px;
            transition: var(--transition);
            background: #fafafa;
            font-weight: 500;
        }

        .search-input:focus {
            border-color: var(--secondary);
            box-shadow: 0 0 0 4px rgba(40, 167, 69, 0.15);
            outline: none;
            background: white;
            transform: translateY(-2px);
        }

        .search-icon {
            position: absolute;
            left: 20px;
            top: 16px;
            color: var(--primary);
            font-size: 18px;
        }

        .table-container {
            overflow-x: auto;
            border-radius: var(--radius);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            border: 1px solid rgba(0,0,0,0.05);
            background: white;
        }

        .credit-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        .credit-table th {
            background: var(--gradient-primary);
            color: white;
            padding: 18px 15px;
            text-align: left;
            font-weight: 600;
            position: sticky;
            top: 0;
            font-size: 15px;
            border-bottom: 2px solid rgba(255,255,255,0.2);
        }

        .credit-table tr {
            transition: var(--transition);
            animation: fadeIn 0.5s ease;
        }

        .credit-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        .credit-table tr:hover {
            background-color: #e8f4fc;
            transform: translateX(8px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .credit-table td {
            padding: 18px 15px;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            font-size: 14px;
        }

        .bill-no {
            font-weight: 700;
            color: var(--primary);
            font-size: 15px;
        }

        .customer-name {
            font-weight: 700;
            color: var(--dark);
        }

        .phone-no {
            color: var(--secondary);
            font-weight: 600;
        }

        .nic-no {
            color: #7f8c8d;
            font-weight: 500;
        }

        .total-amount {
            color: var(--primary);
            font-weight: 800;
        }

        .paid-amount {
            color: var(--success);
            font-weight: 800;
        }

        .balance {
            color: var(--accent);
            font-weight: 800;
        }

        .action-btn {
            background: var(--gradient-secondary);
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 8px;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            margin-right: 8px;
            font-size: 14px;
            box-shadow: 0 3px 6px rgba(40, 167, 69, 0.2);
        }

        .action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
        }

        .delete-btn {
            background: var(--gradient-accent);
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 8px;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            font-size: 14px;
            box-shadow: 0 3px 6px rgba(220, 53, 69, 0.2);
        }

        .delete-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(220, 53, 69, 0.3);
        }

        .no-records {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }

        .no-records i {
            font-size: 70px;
            margin-bottom: 20px;
            color: #dee2e6;
        }

        .no-records h3 {
            font-size: 24px;
            margin-bottom: 10px;
            color: var(--dark);
        }

        .no-records p {
            font-size: 16px;
            max-width: 400px;
            margin: 0 auto;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background: white;
            padding: 30px;
            border-radius: var(--radius);
            box-shadow: var(--card-shadow);
            width: 90%;
            max-width: 500px;
            position: relative;
            animation: fadeInUp 0.3s ease;
            border: 1px solid rgba(0,0,0,0.05);
        }

        .modal-content h3 {
            margin-top: 0;
            color: var(--primary);
            font-size: 24px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light);
        }

        .modal-content .close {
            position: absolute;
            right: 20px;
            top: 20px;
            font-size: 28px;
            cursor: pointer;
            color: var(--dark);
            transition: var(--transition);
        }

        .modal-content .close:hover {
            color: var(--accent);
            transform: rotate(90deg);
        }

        .modal-content .form-group {
            margin-bottom: 20px;
        }

        .modal-content label {
            display: block;
            margin-bottom: 10px;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px;
        }

        .modal-content label i {
            color: var(--primary);
        }

        .modal-content input,
        .modal-content select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 500;
            transition: var(--transition);
            background: white;
        }

        .modal-content input:focus,
        .modal-content select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(90, 61, 126, 0.1);
            outline: none;
            transform: translateY(-2px);
        }

        .modal-content button {
            width: 100%;
            padding: 16px;
            background: var(--gradient-secondary);
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 700;
            font-size: 16px;
            transition: var(--transition);
            margin-top: 10px;
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.2);
        }

        .modal-content button:hover {
            background: var(--gradient-primary);
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(90, 61, 126, 0.3);
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
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 20px;
                text-align: center;
                padding: 20px;
            }
            
            .nav {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .nav a {
                padding: 12px 20px;
                font-size: 14px;
            }
            
            .main-container {
                padding: 0 15px;
            }
            
            .card {
                padding: 20px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .credit-table {
                font-size: 13px;
            }
            
            .credit-table th,
            .credit-table td {
                padding: 12px 8px;
            }
            
            .action-btn,
            .delete-btn {
                padding: 8px 12px;
                font-size: 13px;
            }
        }

        @media (max-width: 480px) {
            .header h1 {
                font-size: 24px;
            }
            
            .card-title {
                font-size: 20px;
            }
            
            .nav a {
                padding: 10px 15px;
                font-size: 13px;
            }
            
            .modal-content {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo-container">
            <img src="../logo.jpg" alt="Kids Berry Logo">
        </div>
        <div class="header-content">
            <h1><i class="fas fa-baby"></i> KIDS Berry - Credit Management</h1>
            <div class="date-time">
                <p id="current-date"><?php echo date('l, F j, Y'); ?></p>
                <p id="current-time"><?php echo date('g:i:s A'); ?></p>
            </div>
        </div>
        <form method="post">
            <button type="submit" name="logout" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </form>
    </div>

    <div class="nav">
        <a href="billing.php"><i class="fas fa-cash-register"></i> Billing</a>
        <a href="customer.php"><i class="fas fa-users"></i> Customers</a>
                <a href="bill_management.php" class=""><i class="fas fa-history"></i> Bill History</a>
        <a href="report.php"><i class="fas fa-chart-line"></i> Reports</a>
        <a href="credit_payments.php" class="active"><i class="fas fa-credit-card"></i> Credit Customers</a>
        <a href="return_sale.php"><i class="fas fa-undo-alt"></i> Manage Returns</a>
                <!--<a href="prediction_dashboard.php" class=""><i class="fas fa-bullseye"></i> Predictions</a>-->

    </div>

    <div class="main-container">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-credit-card"></i> Credit Bill Management</h2>
                <div style="display: flex; gap: 15px;">
                    <div class="stat-card">
                        <div class="stat-label">Total Credit Bills</div>
                        <div class="stat-value"><?php echo $total_credits; ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Total Credit Amount</div>
                        <div class="stat-value">Rs<?php echo number_format($total_credit_amount, 2); ?></div>
                    </div>
                </div>
            </div>
            
            <div class="search-container">
                <i class="fas fa-search search-icon"></i>
                <input type="text" class="search-input" id="search-input" placeholder="Search by Customer Name, Phone Number, NIC Number, or Bill No" value="<?php echo htmlspecialchars($search); ?>">
            </div>
            
            <div class="table-container">
                <?php if ($credit_bills_result->num_rows > 0): ?>
                    <table class="credit-table">
                        <thead>
                            <tr>
                                <th>Bill No</th>
                                <th>Product</th>
                                <th>Quantity</th>
                                <th>Date</th>
                                <th>Customer Name</th>
                                <th>Phone Number</th>
                                <th>NIC Number</th>
                                <th>Total Amount</th>
                                <th>Paid Amount</th>
                                <th>Balance</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $credit_bills_result->fetch_assoc()) { ?>
                                <tr>
                                    <td class="bill-no"><?php echo htmlspecialchars($row['bill_no']); ?></td>
                                    <td><?php echo htmlspecialchars($row['product_name'] ?? ''); ?></td>
                                    <td><?php echo $row['quantity']; ?></td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($row['date'])); ?></td>
                                    <td class="customer-name"><?php echo htmlspecialchars($row['customer_name'] ?? ''); ?></td>
                                    <td class="phone-no"><?php echo htmlspecialchars($row['phone_no'] ?? ''); ?></td>
                                    <td class="nic-no"><?php echo htmlspecialchars($row['nic_no'] ?? ''); ?></td>
                                    <td class="total-amount">Rs<?php echo number_format($row['total_amount'], 2); ?></td>
                                    <td class="paid-amount">Rs<?php echo number_format($row['paid_amount'], 2); ?></td>
                                    <td class="balance">Rs<?php echo number_format($row['balance'], 2); ?></td>
                                    <td>
                                        <button class="action-btn" onclick="showPaymentModal('<?php echo htmlspecialchars($row['bill_no']); ?>', <?php echo abs($row['balance']); ?>)">
                                            <i class="fas fa-money-bill-wave"></i> Pay
                                        </button>
                                        <form method="post" style="display: inline;" onsubmit="return confirmDelete('<?php echo htmlspecialchars($row['bill_no']); ?>')">
                                            <input type="hidden" name="bill_no" value="<?php echo htmlspecialchars($row['bill_no']); ?>">
                                            <button type="submit" name="delete_bill" class="delete-btn">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-records">
                        <i class="fas fa-search"></i>
                        <h3>No credit bills found</h3>
                        <p>No credit bills match your search criteria</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Payment Modal -->
    <div id="paymentModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closePaymentModal()">&times;</span>
            <h3>Update Credit Payment</h3>
            <form method="post" onsubmit="return validatePaymentForm()">
                <div class="form-group">
                    <label for="modal-bill-no"><i class="fas fa-receipt"></i> Bill No</label>
                    <input type="text" id="modal-bill-no" name="bill_no" readonly>
                </div>
                <div class="form-group">
                    <label for="modal-balance"><i class="fas fa-wallet"></i> Current Balance</label>
                    <input type="text" id="modal-balance" readonly>
                </div>
                <div class="form-group">
                    <label for="payment-amount"><i class="fas fa-money-bill-wave"></i> Payment Amount (Rs)</label>
                    <input type="number" id="payment-amount" name="payment_amount" step="0.01" min="0" required>
                </div>
                <button type="submit" name="update_payment">
                    <i class="fas fa-check-circle"></i> Update Payment
                </button>
            </form>
        </div>
    </div>

    <script>
        // Update time in real-time
        function updateClock() {
            const now = new Date();
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            document.getElementById('current-date').textContent = now.toLocaleDateString('en-US', options);
            let hours = now.getHours();
            let minutes = now.getMinutes();
            let seconds = now.getSeconds();
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12 || 12;
            minutes = minutes < 10 ? '0' + minutes : minutes;
            seconds = seconds < 10 ? '0' + seconds : seconds;
            const timeString = `${hours}:${minutes}:${seconds} ${ampm}`;
            document.getElementById('current-time').textContent = timeString;
            setTimeout(updateClock, 1000);
        }

        updateClock();

        // Search functionality
        document.getElementById('search-input').addEventListener('input', function() {
            window.location.href = '?search=' + encodeURIComponent(this.value);
        });

        document.getElementById('search-input').addEventListener('focus', function() {
            this.select();
        });

        // Confirm delete function - Updated message
        function confirmDelete(billNo) {
            return confirm(`Are you sure you want to delete credit bill ${billNo}? This action cannot be undone. Note: Product stock will NOT be restored as this was a valid sale.`);
        }

        // Modal functions
        function showPaymentModal(billNo, balance) {
            const modal = document.getElementById('paymentModal');
            document.getElementById('modal-bill-no').value = billNo;
            document.getElementById('modal-balance').value = 'Rs' + balance.toFixed(2);
            document.getElementById('payment-amount').setAttribute('max', balance.toFixed(2));
            document.getElementById('payment-amount').value = '';
            modal.style.display = 'flex';
        }

        function closePaymentModal() {
            const modal = document.getElementById('paymentModal');
            modal.style.display = 'none';
            document.getElementById('payment-amount').value = '';
        }

        function validatePaymentForm() {
            const paymentAmount = parseFloat(document.getElementById('payment-amount').value);
            const maxAmount = parseFloat(document.getElementById('payment-amount').getAttribute('max'));
            
            if (paymentAmount <= 0) {
                alert('Payment amount must be greater than zero.');
                return false;
            }
            if (paymentAmount > maxAmount) {
                alert('Payment amount cannot exceed the outstanding balance of Rs' + maxAmount.toFixed(2));
                return false;
            }
            return true;
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('paymentModal');
            if (event.target == modal) {
                closePaymentModal();
            }
        }

        // Add animations to table rows
        document.addEventListener('DOMContentLoaded', function() {
            const tableRows = document.querySelectorAll('.credit-table tbody tr');
            tableRows.forEach((row, index) => {
                row.style.animationDelay = `${index * 0.1}s`;
            });
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>