<?php
session_start();

date_default_timezone_set('Asia/Colombo');

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

$branch_id = 1; // Hardcoded for now, update to $_SESSION['branch_id'] later
$_SESSION['branch_id'] = $branch_id;

// Get all active cashiers for dropdown
$cashiers_sql = "SELECT cashier_id, name FROM cashier_users2 WHERE status = 'active' ORDER BY name";
$cashiers_result = $conn->query($cashiers_sql);

// Initialize variables
$report_type = '';
$from_date = '';
$to_date = '';
$cashier_id = '';
$results = [];
$total_sales = 0;
$total_profit = 0;
$total_discount = 0;
$total_paid_amount = 0;
$total_outstanding_balance = 0;
$total_cash_payments = 0;
$total_card_payments = 0;
$current_date = date('Y-m-d');

// NEW: Variables for credit amounts
$total_credits = 0;
$total_credit_amount = 0;

// Calculate TODAY'S TOTAL RETURNS
$today = date('Y-m-d');
$returns_sql = "SELECT 
                SUM(return_amount) as total_returns,
                COUNT(*) as return_count
                FROM returns_tracking2 
                WHERE DATE(return_date) = '$today'";
$returns_result = $conn->query($returns_sql);
$today_returns = 0;
$today_return_count = 0;

if ($returns_result->num_rows > 0) {
    $returns_data = $returns_result->fetch_assoc();
    $today_returns = $returns_data['total_returns'] ?? 0;
    $today_return_count = $returns_data['return_count'] ?? 0;
}

$bills_sql = "SELECT bill_no, date, payment_method, paid_amount, total, subtotal, total_discount FROM bills2 WHERE DATE(date) = '$current_date' ORDER BY date ASC";
$bills_result = $conn->query($bills_sql);

while ($bill = $bills_result->fetch_assoc()) {
    $bill_no = $bill['bill_no'];
    $payment_method = $bill['payment_method'];
    $paid_amount = floatval($bill['paid_amount']);
    $total_bill = floatval($bill['total']);
    $subtotal_bill = floatval($bill['subtotal']);
    $discount_bill = floatval($bill['total_discount']);

    
    $items_sql = "SELECT bi.*, p.original_price FROM bill_items2 bi JOIN products2 p ON bi.product_id = p.product_id WHERE bi.bill_no = '$bill_no'";
    $items_result = $conn->query($items_sql);
    
    while ($item = $items_result->fetch_assoc()) {
        $cost = $item['original_price'] * $item['quantity'];
        $revenue = $item['subtotal'] - $item['discount'];
        $profit = $revenue - $cost;
        $total_profit += $profit;
    }
    
}

// Handle report generation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['generate_report'])) {
    $report_type = mysqli_real_escape_string($conn, $_POST['report_type']);
    $from_date = mysqli_real_escape_string($conn, $_POST['from']);
    $to_date = isset($_POST['to']) && !empty($_POST['to']) ? mysqli_real_escape_string($conn, $_POST['to']) : $from_date;
    $cashier_id = isset($_POST['cashier_id']) ? mysqli_real_escape_string($conn, $_POST['cashier_id']) : '';
    
    // Calculate TOTAL SALES for the selected period
    $total_sales_sql = "SELECT SUM(total) as period_total_sales 
                        FROM bills2 
                        WHERE branch_id = $branch_id 
                        AND DATE(date) BETWEEN '$from_date' AND '$to_date'";
    
    if (!empty($cashier_id)) {
        $total_sales_sql .= " AND cashier_id = '$cashier_id'";
    }
    
    $total_sales_result = $conn->query($total_sales_sql);
    $period_total_sales = 0;
    if ($total_sales_result->num_rows > 0) {
        $total_sales_data = $total_sales_result->fetch_assoc();
        $period_total_sales = $total_sales_data['period_total_sales'] ?? 0;
    }
    
    // Calculate total credit amounts for the selected period
    $credits_sql = "SELECT COUNT(DISTINCT b.bill_no) as total_credits, 
                           SUM(ABS(b.balance)) as total_credit_amount 
                    FROM bills2 b 
                    WHERE b.branch_id = $branch_id 
                    AND b.payment_method = 'Credit' 
                    AND b.balance < 0 
                    AND DATE(b.date) BETWEEN '$from_date' AND '$to_date'";
    
    if (!empty($cashier_id)) {
        $credits_sql .= " AND b.cashier_id = '$cashier_id'";
    }
    
    $credits_result = $conn->query($credits_sql);
    if ($credits_result->num_rows > 0) {
        $credits_data = $credits_result->fetch_assoc();
        $total_credits = $credits_data['total_credits'] ?? 0;
        $total_credit_amount = $credits_data['total_credit_amount'] ?? 0;
    }
    
    // Build the base SQL query
    $sales_sql = "SELECT 
                    DATE(b.date) as date,
                    TIME(b.date) as time,
                    DATE_FORMAT(b.date, '%Y-%m') as month,
                    b.bill_no,
                    c.name as cashier_name,
                    COALESCE(bi.product_name, 'Product Not Found') as product_name,
                    bi.imei,
                    bi.quantity,
                    bi.discount,
                    bi.price as sale_price,
                    COALESCE(bi.original_price, 0) as original_price,
                    (bi.price * bi.quantity - bi.discount - COALESCE(bi.original_price, 0) * bi.quantity) as profit,
                    b.payment_method,
                    b.paid_amount,
                    b.total as total_amount,
                    b.balance as outstanding_balance
                  FROM bills2 b
                  JOIN bill_items2 bi ON b.bill_no = bi.bill_no
                  JOIN cashier_users2 c ON b.cashier_id = c.cashier_id
                  WHERE b.branch_id = $branch_id
                  AND DATE(b.date) BETWEEN '$from_date' AND '$to_date'
                  AND bi.return_status = 'sold'";
    
    // Add cashier filter if selected
    if (!empty($cashier_id)) {
        $sales_sql .= " AND b.cashier_id = '$cashier_id'";
    }
    
    if ($report_type == 'Daily Sales') {
        $sales_sql .= " ORDER BY b.date DESC";
        
        $sales_result = $conn->query($sales_sql);
        
        if ($sales_result->num_rows > 0) {
            while ($row = $sales_result->fetch_assoc()) {
                $results[] = $row;
                $total_sales += ($row['sale_price'] * $row['quantity'] - $row['discount']);
                $total_profit += $row['profit'];
                $total_discount += $row['discount'];
                $total_paid_amount += $row['paid_amount'];
                $total_outstanding_balance += $row['outstanding_balance'];
                
                $net_price = ($row['sale_price'] * $row['quantity']) - $row['discount'];
                
                if (strtolower($row['payment_method']) == 'cash') {
                    $total_cash_payments += $net_price;
                } elseif (strtolower($row['payment_method']) == 'card') {
                    $total_card_payments += $net_price;
                }
            }
        }
    } else {
        $sales_sql .= " ORDER BY b.date DESC";
        
        $sales_result = $conn->query($sales_sql);
        
        if ($sales_result->num_rows > 0) {
            while ($row = $sales_result->fetch_assoc()) {
                $month = $row['month'];
                if (!isset($results[$month])) {
                    $results[$month] = [
                        'items' => [],
                        'total_sales' => 0,
                        'total_profit' => 0,
                        'total_discount' => 0,
                        'total_paid_amount' => 0,
                        'total_outstanding_balance' => 0,
                        'total_cash_payments' => 0,
                        'total_card_payments' => 0
                    ];
                }
                
                $results[$month]['items'][] = $row;
                $results[$month]['total_sales'] += ($row['sale_price'] * $row['quantity'] - $row['discount']);
                $results[$month]['total_profit'] += $row['profit'];
                $results[$month]['total_discount'] += $row['discount'];
                $results[$month]['total_paid_amount'] += $row['paid_amount'];
                $results[$month]['total_outstanding_balance'] += $row['outstanding_balance'];
                
                $net_price = ($row['sale_price'] * $row['quantity']) - $row['discount'];
                
                if (strtolower($row['payment_method']) == 'cash') {
                    $results[$month]['total_cash_payments'] += $net_price;
                    $total_cash_payments += $net_price;
                } elseif (strtolower($row['payment_method']) == 'card') {
                    $results[$month]['total_card_payments'] += $net_price;
                    $total_card_payments += $net_price;
                }
                
                $total_sales += ($row['sale_price'] * $row['quantity'] - $row['discount']);
                $total_profit += $row['profit'];
                $total_discount += $row['discount'];
                $total_paid_amount += $row['paid_amount'];
                $total_outstanding_balance += $row['outstanding_balance'];
            }
        }
    }
    
    // Calculate returns for the selected period
    $period_returns_sql = "SELECT 
                          SUM(return_amount) as period_returns,
                          COUNT(*) as period_return_count
                          FROM returns_tracking2 
                          WHERE DATE(return_date) BETWEEN '$from_date' AND '$to_date'";
    $period_returns_result = $conn->query($period_returns_sql);
    $period_returns = 0;
    $period_return_count = 0;
    
    if ($period_returns_result->num_rows > 0) {
        $period_data = $period_returns_result->fetch_assoc();
        $period_returns = $period_data['period_returns'] ?? 0;
        $period_return_count = $period_data['period_return_count'] ?? 0;
    }
    
    $total_sales = $period_total_sales;
}

// Handle export to Excel
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['export_excel'])) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="sales_report_' . date('Y-m-d') . '.xls"');
    
    $export_report_type = mysqli_real_escape_string($conn, $_POST['report_type']);
    $export_from_date = mysqli_real_escape_string($conn, $_POST['from']);
    $export_to_date = mysqli_real_escape_string($conn, $_POST['to']);
    $export_cashier_id = mysqli_real_escape_string($conn, $_POST['cashier_id']);
    
    echo "Sales Report\n";
    echo "Report Type: " . $export_report_type . "\n";
    echo "Period: " . $export_from_date . " to " . $export_to_date . "\n";
    if (!empty($export_cashier_id)) {
        $cashier_name_sql = "SELECT name FROM cashier_users2 WHERE cashier_id = '$export_cashier_id'";
        $cashier_name_result = $conn->query($cashier_name_sql);
        if ($cashier_name_result->num_rows > 0) {
            $cashier_name = $cashier_name_result->fetch_assoc()['name'];
            echo "Cashier: " . $cashier_name . "\n";
        }
    }
    echo "\n";
    
    $export_sql = "SELECT 
                    DATE(b.date) as date,
                    TIME(b.date) as time,
                    DATE_FORMAT(b.date, '%Y-%m') as month,
                    b.bill_no,
                    c.name as cashier_name,
                    COALESCE(bi.product_name, 'Product Not Found') as product_name,
                    bi.imei,
                    bi.quantity,
                    bi.discount,
                    bi.price as sale_price,
                    COALESCE(bi.original_price, 0) as original_price,
                    (bi.price * bi.quantity - bi.discount - COALESCE(bi.original_price, 0) * bi.quantity) as profit,
                    b.payment_method,
                    b.paid_amount,
                    b.total as total_amount,
                    b.balance as outstanding_balance
                  FROM bills2 b
                  JOIN bill_items2 bi ON b.bill_no = bi.bill_no
                  JOIN cashier_users2 c ON b.cashier_id = c.cashier_id
                  WHERE b.branch_id = $branch_id
                  AND DATE(b.date) BETWEEN '$export_from_date' AND '$export_to_date'";
    
    if (!empty($export_cashier_id)) {
        $export_sql .= " AND b.cashier_id = '$export_cashier_id'";
    }
    
    if ($export_report_type == 'Daily Sales') {
        $export_sql .= " ORDER BY b.date DESC";
        
        echo "Date\tTime\tBill No\tCashier\tProduct\tIMEI\tQuantity\tDiscount\tSale Price\tNet Price\tPayment Method\tPaid Amount\tTotal Amount\n";
        
        $export_result = $conn->query($export_sql);
        
        while ($row = $export_result->fetch_assoc()) {
            $net_price = ($row['sale_price'] * $row['quantity']) - $row['discount'];
            echo $row['date'] . "\t";
            echo $row['time'] . "\t";
            echo $row['bill_no'] . "\t";
            echo $row['cashier_name'] . "\t";
            echo $row['product_name'] . "\t";
            echo ($row['imei'] ?? '') . "\t";
            echo $row['quantity'] . "\t";
            echo $row['discount'] . "\t";
            echo $row['sale_price'] . "\t";
            echo $net_price . "\t";
            echo $row['payment_method'] . "\t";
            echo $row['paid_amount'] . "\t";
            echo $row['total_amount'] . "\n";
        }
    } else {
        $export_sql .= " ORDER BY b.date DESC";
        
        echo "Month\tBill No\tCashier\tProduct\tIMEI\tQuantity\tDiscount\tSale Price\tNet Price\tPayment Method\tPaid Amount\tTotal Amount\n";
        
        $export_result = $conn->query($export_sql);
        
        while ($row = $export_result->fetch_assoc()) {
            $net_price = ($row['sale_price'] * $row['quantity']) - $row['discount'];
            echo $row['month'] . "\t";
            echo $row['bill_no'] . "\t";
            echo $row['cashier_name'] . "\t";
            echo $row['product_name'] . "\t";
            echo ($row['imei'] ?? '') . "\t";
            echo $row['quantity'] . "\t";
            echo $row['discount'] . "\t";
            echo $row['sale_price'] . "\t";
            echo $net_price . "\t";
            echo $row['payment_method'] . "\t";
            echo $row['paid_amount'] . "\t";
            echo $row['total_amount'] . "\n";
        }
    }
    
    exit();
}

// Handle logout
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['logout'])) {
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>KIDS Berry - Sales Reports</title>
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

        /* Header */
        .header {
            background: var(--primary-dark);
            color: white;
            padding: 16px 24px;
            box-shadow: var(--card-shadow);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .logo-container {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .logo-container img {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            border: 2px solid var(--accent);
            object-fit: cover;
        }

        .brand-info h1 {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 2px;
        }

        .header-tagline {
            font-size: 12px;
            opacity: 0.8;
        }

        .date-time {
            background: rgba(255,255,255,0.1);
            padding: 8px 16px;
            border-radius: var(--radius);
            text-align: center;
        }

        .current-date {
            font-size: 14px;
            font-weight: 600;
        }

        .current-time {
            font-size: 12px;
            opacity: 0.9;
        }

        .logout-btn {
            background: rgba(255,255,255,0.15);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 50px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .logout-btn:hover {
            background: rgba(255,255,255,0.25);
            transform: translateY(-2px);
        }

        /* Navigation */
        .nav-container {
            background: white;
            padding: 12px 24px;
            box-shadow: var(--card-shadow);
            position: sticky;
            top: 82px;
            z-index: 99;
        }

        .nav {
            display: flex;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
            max-width: 1400px;
            margin: 0 auto;
        }

        .nav a {
            text-decoration: none;
            color: var(--dark);
            font-weight: 600;
            padding: 10px 20px;
            border-radius: 50px;
            background: var(--light);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
        }

        .nav a:hover {
            background: var(--primary);
            color: white;
        }

        .nav a.active {
            background: var(--primary);
            color: white;
        }

        /* Main Container */
        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Card Styles */
        .card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--card-shadow);
            overflow: hidden;
            margin-bottom: 20px;
            border: 1px solid rgba(26, 71, 42, 0.1);
        }

        .card-header {
            background: var(--primary);
            color: white;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .card-title {
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-content {
            padding: 24px;
        }

        /* Returns Info */
        .returns-info {
            background: #fff8e7;
            border-left: 4px solid var(--warning);
            padding: 15px 20px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .returns-info a {
            background: var(--warning);
            color: var(--dark);
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
            transition: var(--transition);
        }

        .returns-info a:hover {
            background: #e67e22;
            transform: translateY(-2px);
        }

        /* Form Styles */
        .form-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 6px;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .form-group label i {
            color: var(--primary);
        }

        .form-group input,
        .form-group select {
            padding: 10px 12px;
            border: 1px solid #e0e0e0;
            border-radius: var(--radius-sm);
            font-size: 13px;
            transition: var(--transition);
            background: white;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(26, 71, 42, 0.1);
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-warning {
            background: var(--accent);
            color: var(--dark);
        }

        .btn-warning:hover {
            background: var(--accent-dark);
            transform: translateY(-2px);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: var(--light);
            border-radius: var(--radius);
            padding: 15px;
            border: 1px solid #e0e0e0;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary);
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--card-shadow);
        }

        .stat-card h3 {
            font-size: 12px;
            color: var(--gray);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card .value {
            font-size: 22px;
            font-weight: 800;
            color: var(--primary);
        }

        .stat-card .sub-value {
            font-size: 11px;
            color: var(--gray);
            margin-top: 5px;
        }

        .stat-card.today-returns::before { background: var(--danger); }
        .stat-card.net-sales::before { background: var(--info); }
        .stat-card.cash-payments::before { background: var(--success); }
        .stat-card.card-payments::before { background: #8e44ad; }
        .stat-card.credit-amounts::before { background: #9b59b6; }

        /* Table Styles */
        .table-container {
            overflow-x: auto;
            margin-bottom: 20px;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .report-table th {
            background: var(--primary);
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            white-space: nowrap;
        }

        .report-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #f0f0f0;
        }

        .report-table tr:hover {
            background: var(--light);
        }

        .monthly-total {
            background: #f5f5f5;
            font-weight: bold;
        }

        .monthly-total td {
            background: #f5f5f5;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .view-btn, .print-btn {
            padding: 6px 12px;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 11px;
            font-weight: 600;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .view-btn {
            background: var(--info);
            color: white;
        }

        .print-btn {
            background: var(--accent);
            color: white;
        }

        .view-btn:hover, .print-btn:hover {
            transform: translateY(-2px);
        }

        .hidden-column {
            display: none;
        }

        /* No Records */
        .no-records {
            text-align: center;
            padding: 50px 20px;
        }

        .no-records i {
            font-size: 60px;
            color: #e0e0e0;
            margin-bottom: 15px;
        }

        .no-records h3 {
            color: var(--dark);
            margin-bottom: 8px;
        }

        .no-records p {
            color: var(--gray);
            font-size: 13px;
        }

        /* Export Buttons */
        .export-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 20px;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 700px;
            max-height: 85vh;
            overflow: auto;
            position: relative;
            animation: slideDown 0.3s ease;
        }

        .close-modal {
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 28px;
            cursor: pointer;
            color: var(--gray);
            transition: var(--transition);
        }

        .close-modal:hover {
            color: var(--danger);
        }

        .modal-header {
            padding: 20px 24px;
            background: var(--primary);
            color: white;
        }

        .modal-header h3 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            padding: 20px;
        }

        .detail-item {
            background: var(--light);
            padding: 12px;
            border-radius: var(--radius-sm);
            border-left: 3px solid var(--primary);
        }

        .detail-label {
            font-size: 11px;
            color: var(--gray);
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .detail-value {
            font-size: 14px;
            font-weight: 600;
            color: var(--dark);
        }

        /* Notification */
        .notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--primary);
            color: white;
            padding: 12px 20px;
            border-radius: var(--radius);
            display: none;
            z-index: 1000;
            font-size: 13px;
            align-items: center;
            gap: 8px;
        }

        @keyframes slideDown {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Responsive */
        @media (max-width: 992px) {
            .header-content {
                flex-direction: column;
                text-align: center;
            }
            .nav {
                flex-direction: column;
            }
            .nav a {
                justify-content: center;
            }
            .form-container {
                grid-template-columns: 1fr;
            }
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .main-container {
                padding: 15px;
            }
            .card-content {
                padding: 16px;
            }
            .returns-info {
                flex-direction: column;
                text-align: center;
            }
            .export-buttons {
                flex-direction: column;
            }
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <div class="logo-container">
                <img src="../logo.jpg" alt="Kids Berry Logo">
                <div class="brand-info">
                    <h1><i class="fas fa-chart-line"></i> Sales Reports</h1>
                    <div class="header-tagline">Comprehensive Sales Analytics</div>
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
            <a href="report1.php" class="active"><i class="fas fa-chart-line"></i> Reports</a>
            <a href="return_sale1.php"><i class="fas fa-undo-alt"></i> Manage Returns</a>
            <!--<a href="prediction_dashboard1.php"><i class="fas fa-bullseye"></i> Predictions</a>-->
        </div>
    </div>

    <div class="main-container">
        <!-- Today's Returns Info -->
        <div class="returns-info">
            <div>
                <i class="fas fa-exchange-alt" style="color: var(--warning);"></i>
                <strong>Today's Returns:</strong> 
                Rs<?php echo number_format($today_returns, 2); ?> 
                (<?php echo $today_return_count; ?> items)
            </div>
            <a href="return_sale1.php">
                <i class="fas fa-external-link-alt"></i> Manage Returns
            </a>
        </div>

        <!-- Report Generation Card -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-chart-pie"></i> Generate Sales Report
                </div>
            </div>
            <div class="card-content">
                <form method="post" class="form-container">
                    <div class="form-group">
                        <label><i class="fas fa-chart-bar"></i> Report Type</label>
                        <select name="report_type" required>
                            <option value="Daily Sales" <?php echo $report_type == 'Daily Sales' ? 'selected' : ''; ?>>Daily Sales</option>
                            <option value="Monthly Sales" <?php echo $report_type == 'Monthly Sales' ? 'selected' : ''; ?>>Monthly Sales</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-calendar-start"></i> From Date</label>
                        <input type="date" name="from" value="<?php echo htmlspecialchars($from_date); ?>" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-calendar-end"></i> To Date</label>
                        <input type="date" name="to" value="<?php echo htmlspecialchars($to_date); ?>">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-user-tie"></i> Sales Officer</label>
                        <select name="cashier_id">
                            <option value="">All Officers</option>
                            <?php 
                            $cashiers_result->data_seek(0);
                            while ($cashier = $cashiers_result->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $cashier['cashier_id']; ?>" <?php echo $cashier_id == $cashier['cashier_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cashier['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="submit" name="generate_report" class="btn btn-primary">
                            <i class="fas fa-sync"></i> Generate Report
                        </button>
                    </div>
                </form>

                <?php if (!empty($results)) { ?>
                    <!-- Statistics Cards -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <h3>Total Sales Items</h3>
                            <div class="value">
                                <?php 
                                $total_items = 0;
                                if ($report_type == 'Daily Sales') {
                                    $total_items = count($results);
                                } else {
                                    foreach ($results as $group) {
                                        $total_items += count($group['items']);
                                    }
                                }
                                echo $total_items;
                                ?>
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <h3>Total Sales</h3>
                            <div class="value">Rs<?php 
                                if (isset($period_total_sales)) {
                                    echo number_format($period_total_sales, 2);
                                } else {
                                    $today_sales_sql = "SELECT SUM(total) as today_total FROM bills2 WHERE DATE(date) = '$current_date'";
                                    $today_sales_result = $conn->query($today_sales_sql);
                                    $today_total = 0;
                                    if ($today_sales_result->num_rows > 0) {
                                        $today_data = $today_sales_result->fetch_assoc();
                                        $today_total = $today_data['today_total'] ?? 0;
                                    }
                                    echo number_format($today_total, 2);
                                }
                            ?></div>
                        </div>
                        
                        <div class="stat-card today-returns">
                            <h3>Total Returns</h3>
                            <div class="value">Rs<?php echo number_format($period_returns ?? 0, 2); ?></div>
                            <div class="sub-value"><?php echo $period_return_count ?? 0; ?> items</div>
                        </div>
                        
                        <div class="stat-card net-sales">
                            <h3>Net Sales</h3>
                            <div class="value">Rs<?php 
                                if (isset($period_total_sales)) {
                                    $net_sales = $period_total_sales - ($period_returns ?? 0);
                                    echo number_format($net_sales, 2);
                                } else {
                                    echo number_format(0, 2);
                                }
                            ?></div>
                            <div class="sub-value">(Sales - Returns)</div>
                        </div>
                        
                        <div class="stat-card cash-payments">
                            <h3>Cash Payments</h3>
                            <div class="value">Rs<?php echo number_format($total_cash_payments, 2); ?></div>
                        </div>
                        
                        <div class="stat-card card-payments">
                            <h3>Card Payments</h3>
                            <div class="value">Rs<?php echo number_format($total_card_payments, 2); ?></div>
                        </div>

                        <div class="stat-card credit-amounts">
                            <h3>Credit Amounts</h3>
                            <div class="value">Rs<?php echo number_format($total_credit_amount, 2); ?></div>
                            <div class="sub-value"><?php echo $total_credits; ?> credit bills</div>
                        </div>
                        
                        <div class="stat-card">
                            <h3>Total Discount</h3>
                            <div class="value">Rs<?php echo number_format($total_discount, 2); ?></div>
                        </div>
                        
                        <div class="stat-card">
                            <h3>Report Period</h3>
                            <div class="value"><?php echo $from_date . ($to_date ? ' to ' . $to_date : ''); ?></div>
                        </div>
                    </div>

                    <!-- Report Table -->
                    <div class="table-container">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <?php if ($report_type == 'Daily Sales'): ?>
                                        <th>Date</th>
                                        <th>Time</th>
                                    <?php else: ?>
                                        <th>Month</th>
                                    <?php endif; ?>
                                    <th>Bill No</th>
                                    <th>Cashier</th>
                                    <th>Product</th>
                                    <th>IMEI</th>
                                    <th>Qty</th>
                                    <th>Discount</th>
                                    <th>Sale Price</th>
                                    <th>Net Price</th>
                                    <th>Payment</th>
                                    <th class="hidden-column">Paid Amount</th>
                                    <th class="hidden-column">Total</th>
                                    <th class="hidden-column">Balance</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                if ($report_type == 'Daily Sales') {
                                    foreach ($results as $row) {
                                        $net_price = ($row['sale_price'] * $row['quantity']) - $row['discount'];
                                        echo "<tr>";
                                        echo "<td>" . htmlspecialchars($row['date']) . "</td>";
                                        echo "<td>" . htmlspecialchars($row['time']) . "</td>";
                                        echo "<td>" . htmlspecialchars($row['bill_no']) . "</td>";
                                        echo "<td>" . htmlspecialchars($row['cashier_name']) . "</td>";
                                        echo "<td>" . htmlspecialchars($row['product_name'] ?? 'N/A') . "</td>";
                                        echo "<td>" . htmlspecialchars($row['imei'] ?? '') . "</td>";
                                        echo "<td>" . $row['quantity'] . "</td>";
                                        echo "<td>Rs" . number_format($row['discount'], 2) . "</td>";
                                        echo "<td>Rs" . number_format($row['sale_price'], 2) . "</td>";
                                        echo "<td>Rs" . number_format($net_price, 2) . "</td>";
                                        echo "<td><span class='badge badge-" . strtolower($row['payment_method']) . "'>" . htmlspecialchars($row['payment_method']) . "</span></td>";
                                        echo "<td class='hidden-column'>Rs" . number_format($row['paid_amount'], 2) . "</td>";
                                        echo "<td class='hidden-column'>Rs" . number_format($row['total_amount'], 2) . "</td>";
                                        echo "<td class='hidden-column'>Rs" . number_format($row['outstanding_balance'], 2) . "</td>";
                                        echo "<td><div class='action-buttons'>";
                                        echo "<button class='view-btn' onclick='viewSaleDetails(" . htmlspecialchars(json_encode($row)) . ")'>";
                                        echo "<i class='fas fa-eye'></i> View";
                                        echo "</button>";
                                        echo "<button class='print-btn' onclick='printBill(\"" . htmlspecialchars($row['bill_no']) . "\")'>";
                                        echo "<i class='fas fa-print'></i> Print";
                                        echo "</button>";
                                        echo "</div></td>";
                                        echo "</tr>";
                                    }
                                } else {
                                    foreach ($results as $month => $group) {
                                        foreach ($group['items'] as $row) {
                                            $net_price = ($row['sale_price'] * $row['quantity']) - $row['discount'];
                                            echo "<tr>";
                                            echo "<td>" . htmlspecialchars($month) . "</td>";
                                            echo "<td>" . htmlspecialchars($row['bill_no']) . "</td>";
                                            echo "<td>" . htmlspecialchars($row['cashier_name']) . "</td>";
                                            echo "<td>" . htmlspecialchars($row['product_name'] ?? 'N/A') . "</td>";
                                            echo "<td>" . htmlspecialchars($row['imei'] ?? '') . "</td>";
                                            echo "<td>" . $row['quantity'] . "</td>";
                                            echo "<td>Rs" . number_format($row['discount'], 2) . "</td>";
                                            echo "<td>Rs" . number_format($row['sale_price'], 2) . "</td>";
                                            echo "<td>Rs" . number_format($net_price, 2) . "</td>";
                                            echo "<td><span class='badge badge-" . strtolower($row['payment_method']) . "'>" . htmlspecialchars($row['payment_method']) . "</span></td>";
                                            echo "<td class='hidden-column'>Rs" . number_format($row['paid_amount'], 2) . "</td>";
                                            echo "<td class='hidden-column'>Rs" . number_format($row['total_amount'], 2) . "</td>";
                                            echo "<td class='hidden-column'>Rs" . number_format($row['outstanding_balance'], 2) . "</td>";
                                            echo "<td><div class='action-buttons'>";
                                            echo "<button class='view-btn' onclick='viewSaleDetails(" . htmlspecialchars(json_encode($row)) . ")'>";
                                            echo "<i class='fas fa-eye'></i> View";
                                            echo "</button>";
                                            echo "<button class='print-btn' onclick='printBill(\"" . htmlspecialchars($row['bill_no']) . "\")'>";
                                            echo "<i class='fas fa-print'></i> Print";
                                            echo "</button>";
                                            echo "</div></td>";
                                            echo "</tr>";
                                        }
                                        echo "<tr class='monthly-total'>";
                                        echo "<td colspan='9'><strong>Monthly Total - " . $month . "</strong></td>";
                                        echo "<td><strong>Rs" . number_format($group['total_sales'], 2) . "</strong></td>";
                                        echo "<td class='hidden-column'></td><td class='hidden-column'></td><td class='hidden-column'></td>";
                                        echo "<td></td>";
                                        echo "</tr>";
                                    }
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Export Buttons -->
                    <div class="export-buttons">
                        <form method="post">
                            <input type="hidden" name="report_type" value="<?php echo htmlspecialchars($report_type); ?>">
                            <input type="hidden" name="from" value="<?php echo htmlspecialchars($from_date); ?>">
                            <input type="hidden" name="to" value="<?php echo htmlspecialchars($to_date); ?>">
                            <input type="hidden" name="cashier_id" value="<?php echo htmlspecialchars($cashier_id); ?>">
                            <button type="submit" name="export_excel" class="btn btn-warning">
                                <i class="fas fa-file-excel"></i> Export to Excel
                            </button>
                        </form>
                        <form method="post" action="print_report1.php" target="_blank">
                            <input type="hidden" name="report_type" value="<?php echo htmlspecialchars($report_type); ?>">
                            <input type="hidden" name="from" value="<?php echo htmlspecialchars($from_date); ?>">
                            <input type="hidden" name="to" value="<?php echo htmlspecialchars($to_date); ?>">
                            <input type="hidden" name="cashier_id" value="<?php echo htmlspecialchars($cashier_id); ?>">
                            <input type="hidden" name="total_items" value="<?php echo $total_items ?? 0; ?>">
                            <input type="hidden" name="total_sales" value="<?php echo $period_total_sales ?? 0; ?>">
                            <input type="hidden" name="period_returns" value="<?php echo $period_returns ?? 0; ?>">
                            <input type="hidden" name="period_return_count" value="<?php echo $period_return_count ?? 0; ?>">
                            <input type="hidden" name="net_sales" value="<?php echo ($period_total_sales ?? 0) - ($period_returns ?? 0); ?>">
                            <input type="hidden" name="total_cash_payments" value="<?php echo $total_cash_payments; ?>">
                            <input type="hidden" name="total_card_payments" value="<?php echo $total_card_payments; ?>">
                            <input type="hidden" name="total_discount" value="<?php echo $total_discount; ?>">
                            <input type="hidden" name="total_credits" value="<?php echo $total_credits; ?>">
                            <input type="hidden" name="total_credit_amount" value="<?php echo $total_credit_amount; ?>">
                            <input type="hidden" name="logged_cashiers_id" value="<?php echo $_SESSION['cashiers_id']; ?>">
                            <button type="submit" name="print_report" class="btn btn-primary">
                                <i class="fas fa-print"></i> Print Summary
                            </button>
                        </form>
                    </div>
                <?php } else if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['generate_report'])) { ?>
                    <div class="no-records">
                        <i class="fas fa-inbox"></i>
                        <h3>No data found</h3>
                        <p>Try selecting a different date range or cashier</p>
                    </div>
                <?php } else { ?>
                    <div class="no-records">
                        <i class="fas fa-chart-line"></i>
                        <h3>Generate a report</h3>
                        <p>Select report type and date range to view sales data</p>
                    </div>
                <?php } ?>
            </div>
        </div>
    </div>

    <!-- View Details Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal()">&times;</span>
            <div class="modal-header">
                <h3><i class="fas fa-receipt"></i> Sale Details</h3>
            </div>
            <div id="modalContent"></div>
            <div style="padding: 20px; text-align: center; border-top: 1px solid #eee;">
                <button class="btn btn-primary" onclick="printModalDetails()">
                    <i class="fas fa-print"></i> Print Details
                </button>
                <button class="btn btn-warning" onclick="closeModal()" style="margin-left: 10px;">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <div class="notification" id="notification">
        <i class="fas fa-check-circle"></i> Report generated successfully!
    </div>

    <script>
        // Update time
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

        // Show notification if report was generated
        <?php if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['generate_report']) && !empty($from_date)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const notification = document.getElementById('notification');
            notification.style.display = 'flex';
            setTimeout(function() {
                notification.style.display = 'none';
            }, 3000);
        });
        <?php endif; ?>
        
        // Table row animation
        document.addEventListener('DOMContentLoaded', function() {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = 1;
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, { threshold: 0.1 });
            
            const tableRows = document.querySelectorAll('.report-table tr');
            tableRows.forEach(row => {
                row.style.opacity = 0;
                row.style.transform = 'translateY(15px)';
                row.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                observer.observe(row);
            });
        });
        
        // View sale details
        function viewSaleDetails(saleData) {
            const netPrice = (saleData.sale_price * saleData.quantity) - saleData.discount;
            const modalContent = document.getElementById('modalContent');
            
            let html = `
                <div class="details-grid">
                    <div class="detail-item">
                        <div class="detail-label">Bill Number</div>
                        <div class="detail-value">${saleData.bill_no}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Date & Time</div>
                        <div class="detail-value">${saleData.date} ${saleData.time}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Cashier</div>
                        <div class="detail-value">${saleData.cashier_name}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Payment Method</div>
                        <div class="detail-value">${saleData.payment_method}</div>
                    </div>
                </div>
                
                <div class="details-grid">
                    <div class="detail-item">
                        <div class="detail-label">Product</div>
                        <div class="detail-value">${saleData.product_name}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">IMEI/Serial</div>
                        <div class="detail-value">${saleData.imei || 'N/A'}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Quantity</div>
                        <div class="detail-value">${saleData.quantity}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Unit Price</div>
                        <div class="detail-value">Rs${parseFloat(saleData.sale_price).toFixed(2)}</div>
                    </div>
                </div>
                
                <div style="background: var(--light); padding: 15px; margin: 0 20px 20px; border-radius: var(--radius);">
                    <h4 style="color: var(--primary); margin-bottom: 12px;"><i class="fas fa-calculator"></i> Price Details</h4>
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px;">
                        <div>
                            <div class="detail-label">Total Price</div>
                            <div class="detail-value">Rs${(parseFloat(saleData.sale_price) * parseInt(saleData.quantity)).toFixed(2)}</div>
                        </div>
                        <div>
                            <div class="detail-label">Discount</div>
                            <div class="detail-value">- Rs${parseFloat(saleData.discount).toFixed(2)}</div>
                        </div>
                        <div>
                            <div class="detail-label">Net Price</div>
                            <div class="detail-value" style="color: var(--success);">Rs${netPrice.toFixed(2)}</div>
                        </div>
                    </div>
                </div>
                
                <div class="details-grid">
                    <div class="detail-item">
                        <div class="detail-label">Paid Amount</div>
                        <div class="detail-value">Rs${parseFloat(saleData.paid_amount).toFixed(2)}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Total Amount</div>
                        <div class="detail-value">Rs${parseFloat(saleData.total_amount).toFixed(2)}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Balance</div>
                        <div class="detail-value" style="color: ${saleData.outstanding_balance > 0 ? 'var(--danger)' : 'var(--success)'}">
                            Rs${parseFloat(saleData.outstanding_balance).toFixed(2)}
                        </div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Cost Price</div>
                        <div class="detail-value">Rs${parseFloat(saleData.original_price).toFixed(2)}</div>
                    </div>
                </div>
            `;
            
            modalContent.innerHTML = html;
            document.getElementById('viewModal').style.display = 'flex';
        }

        // Print bill
        function printBill(billNo) {
            if (confirm(`Print bill ${billNo}?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'print_bill1.php';
                form.target = '_blank';
                
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'bill_no';
                input.value = billNo;
                form.appendChild(input);
                
                document.body.appendChild(form);
                form.submit();
                document.body.removeChild(form);
            }
        }

        // Print modal details
        function printModalDetails() {
            const modalContent = document.getElementById('modalContent').innerHTML;
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Sale Details</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; }
                        h2 { color: #1a472a; }
                        .details-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-bottom: 20px; }
                        .detail-item { background: #f5f5f5; padding: 10px; border-left: 3px solid #1a472a; }
                        .detail-label { font-size: 11px; color: #666; }
                        .detail-value { font-size: 14px; font-weight: bold; }
                    </style>
                </head>
                <body>
                    <h2>Sale Details</h2>
                    ${modalContent}
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }

        // Close modal
        function closeModal() {
            document.getElementById('viewModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('viewModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>
<?php
$conn->close();
?>