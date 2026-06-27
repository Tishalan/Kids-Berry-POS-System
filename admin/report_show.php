<?php
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
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

// Get selected branch from session or default to branch1
$selected_branch = isset($_SESSION['selected_branch_report']) ? $_SESSION['selected_branch_report'] : 'branch1';

// Handle branch selection - through AJAX, not page reload
if (isset($_POST['action']) && $_POST['action'] === 'change_branch') {
    header('Content-Type: application/json');
    $selected_branch = $_POST['selected_branch'];
    $_SESSION['selected_branch_report'] = $selected_branch;
    
    // Determine which tables to use based on selected branch
    $cashier_table = ($selected_branch == 'branch1') ? 'cashier_users' : 'cashier_users2';
    $bills_table = ($selected_branch == 'branch1') ? 'bills' : 'bills2';
    $bill_items_table = ($selected_branch == 'branch1') ? 'bill_items' : 'bill_items2';
    $returns_table = ($selected_branch == 'branch1') ? 'returns_tracking' : 'returns_tracking2';
    
    echo json_encode([
        'success' => true,
        'branch_name' => ($selected_branch == 'branch1') ? 'Branch 1' : 'Branch 2',
        'cashier_table' => $cashier_table,
        'bills_table' => $bills_table,
        'bill_items_table' => $bill_items_table,
        'returns_table' => $returns_table
    ]);
    exit;
}

// Determine which tables to use based on selected branch
$cashier_table = ($selected_branch == 'branch1') ? 'cashier_users' : 'cashier_users2';
$bills_table = ($selected_branch == 'branch1') ? 'bills' : 'bills2';
$bill_items_table = ($selected_branch == 'branch1') ? 'bill_items' : 'bill_items2';
$returns_table = ($selected_branch == 'branch1') ? 'returns_tracking' : 'returns_tracking2';

$branch_id = 1; // Hardcoded for now

// Get all active cashiers for dropdown from selected branch
$cashiers_sql = "SELECT cashier_id, name FROM $cashier_table WHERE status = 'active' ORDER BY name";
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
$period_total_sales = 0;
$period_returns = 0;
$period_return_count = 0;

// Calculate TODAY'S TOTAL RETURNS from selected branch
$today = date('Y-m-d');
$returns_sql = "SELECT 
                SUM(return_amount) as total_returns,
                COUNT(*) as return_count
                FROM $returns_table 
                WHERE DATE(return_date) = '$today'";
$returns_result = $conn->query($returns_sql);
$today_returns = 0;
$today_return_count = 0;

if ($returns_result && $returns_result->num_rows > 0) {
    $returns_data = $returns_result->fetch_assoc();
    $today_returns = $returns_data['total_returns'] ?? 0;
    $today_return_count = $returns_data['return_count'] ?? 0;
}

// Handle report generation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['generate_report'])) {
    $report_type = mysqli_real_escape_string($conn, $_POST['report_type']);
    $from_date = mysqli_real_escape_string($conn, $_POST['from']);
    $to_date = isset($_POST['to']) && !empty($_POST['to']) ? mysqli_real_escape_string($conn, $_POST['to']) : $from_date;
    $cashier_id = isset($_POST['cashier_id']) ? mysqli_real_escape_string($conn, $_POST['cashier_id']) : '';
    $branch = $_POST['branch'] ?? $selected_branch;
    
    // Determine which tables to use based on selected branch
    $bills_table = ($branch == 'branch1') ? 'bills' : 'bills2';
    $bill_items_table = ($branch == 'branch1') ? 'bill_items' : 'bill_items2';
    $cashier_table = ($branch == 'branch1') ? 'cashier_users' : 'cashier_users2';
    $returns_table = ($branch == 'branch1') ? 'returns_tracking' : 'returns_tracking2';
    
    // Calculate TOTAL SALES for the selected period
    $total_sales_sql = "SELECT SUM(total) as period_total_sales 
                        FROM $bills_table 
                        WHERE branch_id = $branch_id 
                        AND DATE(date) BETWEEN '$from_date' AND '$to_date'";
    
    if (!empty($cashier_id)) {
        $total_sales_sql .= " AND cashier_id = '$cashier_id'";
    }
    
    $total_sales_result = $conn->query($total_sales_sql);
    $period_total_sales = 0;
    if ($total_sales_result && $total_sales_result->num_rows > 0) {
        $total_sales_data = $total_sales_result->fetch_assoc();
        $period_total_sales = $total_sales_data['period_total_sales'] ?? 0;
    }
    
    // NEW: Calculate total credit amounts for the selected period
    $credits_sql = "SELECT COUNT(DISTINCT b.bill_no) as total_credits, 
                           SUM(ABS(b.balance)) as total_credit_amount 
                    FROM $bills_table b 
                    WHERE b.branch_id = $branch_id 
                    AND b.payment_method = 'Credit' 
                    AND b.balance < 0 
                    AND DATE(b.date) BETWEEN '$from_date' AND '$to_date'";
    
    if (!empty($cashier_id)) {
        $credits_sql .= " AND b.cashier_id = '$cashier_id'";
    }
    
    $credits_result = $conn->query($credits_sql);
    if ($credits_result && $credits_result->num_rows > 0) {
        $credits_data = $credits_result->fetch_assoc();
        $total_credits = $credits_data['total_credits'] ?? 0;
        $total_credit_amount = $credits_data['total_credit_amount'] ?? 0;
    }
    
    // Calculate returns for the selected period
    $period_returns_sql = "SELECT 
                          SUM(return_amount) as period_returns,
                          COUNT(*) as period_return_count
                          FROM $returns_table 
                          WHERE DATE(return_date) BETWEEN '$from_date' AND '$to_date'";
    $period_returns_result = $conn->query($period_returns_sql);
    $period_returns = 0;
    $period_return_count = 0;
    
    if ($period_returns_result && $period_returns_result->num_rows > 0) {
        $period_data = $period_returns_result->fetch_assoc();
        $period_returns = $period_data['period_returns'] ?? 0;
        $period_return_count = $period_data['period_return_count'] ?? 0;
    }
    
    // Build the base SQL query - Include payment details
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
                    (bi.price * bi.quantity - bi.discount) as net_price,
                    b.payment_method,
                    b.paid_amount,
                    b.total as total_amount,
                    b.balance as outstanding_balance
                  FROM $bills_table b
                  JOIN $bill_items_table bi ON b.bill_no = bi.bill_no
                  JOIN $cashier_table c ON b.cashier_id = c.cashier_id
                  WHERE b.branch_id = $branch_id
                  AND DATE(b.date) BETWEEN '$from_date' AND '$to_date'
                  AND bi.return_status = 'sold'";
    
    // Add cashier filter if selected
    if (!empty($cashier_id)) {
        $sales_sql .= " AND b.cashier_id = '$cashier_id'";
    }
    
    if ($report_type == 'Daily Sales') {
        // Daily Sales Report
        $sales_sql .= " ORDER BY b.date DESC";
        
        $sales_result = $conn->query($sales_sql);
        
        if ($sales_result && $sales_result->num_rows > 0) {
            while ($row = $sales_result->fetch_assoc()) {
                // Calculate profit for this row
                $row_profit = ($row['sale_price'] * $row['quantity'] - $row['discount']) - 
                             ($row['original_price'] * $row['quantity']);
                
                // Add profit to row data
                $row['profit'] = $row_profit;
                
                $results[] = $row;
                
                // Calculate net price for this item
                $item_net_price = ($row['sale_price'] * $row['quantity'] - $row['discount']);
                $total_sales += $item_net_price;
                $total_profit += $row_profit;
                $total_discount += $row['discount'];
                $total_paid_amount += $row['paid_amount'];
                $total_outstanding_balance += $row['outstanding_balance'];
                
                // Calculate payment method totals based on the bill's payment method
                if (strtolower($row['payment_method']) == 'cash') {
                    $total_cash_payments += $item_net_price;
                } elseif (strtolower($row['payment_method']) == 'card') {
                    $total_card_payments += $item_net_price;
                }
            }
        }
    } else {
        // Monthly Sales Report
        $sales_sql .= " ORDER BY b.date DESC";
        
        $sales_result = $conn->query($sales_sql);
        
        if ($sales_result && $sales_result->num_rows > 0) {
            while ($row = $sales_result->fetch_assoc()) {
                // Calculate profit for this row
                $row_profit = ($row['sale_price'] * $row['quantity'] - $row['discount']) - 
                             ($row['original_price'] * $row['quantity']);
                
                // Add profit to row data
                $row['profit'] = $row_profit;
                
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
                
                // Calculate net price for this item
                $item_net_price = ($row['sale_price'] * $row['quantity'] - $row['discount']);
                
                // Update monthly totals
                $results[$month]['total_sales'] += $item_net_price;
                $results[$month]['total_profit'] += $row_profit;
                $results[$month]['total_discount'] += $row['discount'];
                $results[$month]['total_paid_amount'] += $row['paid_amount'];
                $results[$month]['total_outstanding_balance'] += $row['outstanding_balance'];
                
                // Calculate payment method totals for monthly based on bill's payment method
                if (strtolower($row['payment_method']) == 'cash') {
                    $results[$month]['total_cash_payments'] += $item_net_price;
                    $total_cash_payments += $item_net_price;
                } elseif (strtolower($row['payment_method']) == 'card') {
                    $results[$month]['total_card_payments'] += $item_net_price;
                    $total_card_payments += $item_net_price;
                }
                
                // Update overall totals
                $total_sales += $item_net_price;
                $total_profit += $row_profit;
                $total_discount += $row['discount'];
                $total_paid_amount += $row['paid_amount'];
                $total_outstanding_balance += $row['outstanding_balance'];
            }
        }
    }
    
    // Use period total sales (corrected)
    $total_sales = $period_total_sales;
}

// Handle export to Excel
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['export_excel'])) {
    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="sales_report_' . date('Y-m-d') . '.xls"');
    
    $export_report_type = mysqli_real_escape_string($conn, $_POST['report_type']);
    $export_from_date = mysqli_real_escape_string($conn, $_POST['from']);
    $export_to_date = mysqli_real_escape_string($conn, $_POST['to']);
    $export_cashier_id = mysqli_real_escape_string($conn, $_POST['cashier_id']);
    $export_branch = $_POST['branch'] ?? $selected_branch;
    
    // Determine which tables to use based on selected branch
    $bills_table = ($export_branch == 'branch1') ? 'bills' : 'bills2';
    $bill_items_table = ($export_branch == 'branch1') ? 'bill_items' : 'bill_items2';
    $cashier_table = ($export_branch == 'branch1') ? 'cashier_users' : 'cashier_users2';
    
    echo "Sales Report\n";
    echo "Report Type: " . $export_report_type . "\n";
    echo "Branch: " . ($export_branch == 'branch1' ? 'Branch 1' : 'Branch 2') . "\n";
    echo "Period: " . $export_from_date . " to " . $export_to_date . "\n";
    if (!empty($export_cashier_id)) {
        $cashier_name_sql = "SELECT name FROM $cashier_table WHERE cashier_id = '$export_cashier_id'";
        $cashier_name_result = $conn->query($cashier_name_sql);
        if ($cashier_name_result && $cashier_name_result->num_rows > 0) {
            $cashier_name = $cashier_name_result->fetch_assoc()['name'];
            echo "Cashier: " . $cashier_name . "\n";
        }
    }
    echo "\n";
    
    // Build export SQL - Include payment details
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
                    b.payment_method,
                    b.paid_amount,
                    b.total as total_amount,
                    b.balance as outstanding_balance
                  FROM $bills_table b
                  JOIN $bill_items_table bi ON b.bill_no = bi.bill_no
                  JOIN $cashier_table c ON b.cashier_id = c.cashier_id
                  WHERE b.branch_id = $branch_id
                  AND DATE(b.date) BETWEEN '$export_from_date' AND '$export_to_date'";
    
    if (!empty($export_cashier_id)) {
        $export_sql .= " AND b.cashier_id = '$export_cashier_id'";
    }
    
    if ($export_report_type == 'Daily Sales') {
        $export_sql .= " ORDER BY b.date DESC";
        
        echo "Date\tTime\tBill No\tCashier\tProduct\tIMEI\tQuantity\tDiscount\tSale Price\tOriginal Price\tProfit\tNet Price\tPayment Method\tPaid Amount\tTotal Amount\n";
        
        $export_result = $conn->query($export_sql);
        
        if ($export_result) {
            while ($row = $export_result->fetch_assoc()) {
                $net_price = ($row['sale_price'] * $row['quantity']) - $row['discount'];
                $profit = $net_price - ($row['original_price'] * $row['quantity']);
                
                echo $row['date'] . "\t";
                echo $row['time'] . "\t";
                echo $row['bill_no'] . "\t";
                echo $row['cashier_name'] . "\t";
                echo $row['product_name'] . "\t";
                echo ($row['imei'] ?? '') . "\t";
                echo $row['quantity'] . "\t";
                echo $row['discount'] . "\t";
                echo $row['sale_price'] . "\t";
                echo $row['original_price'] . "\t";
                echo $profit . "\t";
                echo $net_price . "\t";
                echo $row['payment_method'] . "\t";
                echo $row['paid_amount'] . "\t";
                echo $row['total_amount'] . "\n";
            }
        }
    } else {
        $export_sql .= " ORDER BY b.date DESC";
        
        echo "Month\tBill No\tCashier\tProduct\tIMEI\tQuantity\tDiscount\tSale Price\tOriginal Price\tProfit\tNet Price\tPayment Method\tPaid Amount\tTotal Amount\n";
        
        $export_result = $conn->query($export_sql);
        
        if ($export_result) {
            while ($row = $export_result->fetch_assoc()) {
                $net_price = ($row['sale_price'] * $row['quantity']) - $row['discount'];
                $profit = $net_price - ($row['original_price'] * $row['quantity']);
                
                echo $row['month'] . "\t";
                echo $row['bill_no'] . "\t";
                echo $row['cashier_name'] . "\t";
                echo $row['product_name'] . "\t";
                echo ($row['imei'] ?? '') . "\t";
                echo $row['quantity'] . "\t";
                echo $row['discount'] . "\t";
                echo $row['sale_price'] . "\t";
                echo $row['original_price'] . "\t";
                echo $profit . "\t";
                echo $net_price . "\t";
                echo $row['payment_method'] . "\t";
                echo $row['paid_amount'] . "\t";
                echo $row['total_amount'] . "\n";
            }
        }
    }
    
    exit();
}

// Get branch display name
$branch_display = ($selected_branch == 'branch1') ? 'Branch 1' : 'Branch 2';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Kids Berry - Reports</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-purple: #6a0dad;
            --light-purple: #8a2be2;
            --dark-purple: #4b0082;
            --light-green: #90ee90;
            --medium-green: #32cd32;
            --dark-green: #228b22;
            --sms-blue: #007bff;
            --sms-dark-blue: #0056b3;
            --white: #ffffff;
            --light-gray: #f5f5f5;
            --medium-gray: #e0e0e0;
            --dark-gray: #333333;
            --shadow-light: 0 4px 12px rgba(0, 0, 0, 0.08);
            --shadow-medium: 0 8px 25px rgba(0, 0, 0, 0.15);
            --shadow-heavy: 0 15px 35px rgba(0, 0, 0, 0.2);
            --border-radius: 16px;
            --border-radius-small: 10px;
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            --gradient-primary: linear-gradient(135deg, var(--primary-purple), var(--dark-purple));
            --gradient-secondary: linear-gradient(135deg, var(--medium-green), var(--dark-green));
            --gradient-light: linear-gradient(135deg, var(--light-purple), var(--light-green));
            --gradient-sms: linear-gradient(135deg, var(--sms-blue), var(--sms-dark-blue));
            --platinum: #e5e4e2;
            --gold: #ffd700;
            --silver: #c0c0c0;
            --regular: #6c757d;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--gradient-light);
            min-height: 100vh;
            color: var(--dark-gray);
            line-height: 1.6;
            overflow-x: hidden;
            font-size: 14px;
        }

        .container {
            display: flex;
            min-height: 100vh;
            flex-direction: column;
        }

        /* Mobile First Sidebar */
        .sidebar {
            width: 100%;
            background: var(--gradient-primary);
            color: var(--white);
            padding: 15px 0;
            box-shadow: var(--shadow-medium);
            z-index: 1000;
            transition: var(--transition);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transform: translateX(-100%);
            top: 0;
            left: 0;
        }

        .sidebar.active {
            transform: translateX(0);
        }

        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.5rem;
            font-weight: 800;
        }

        .logo i {
            color: var(--light-green);
            font-size: 1.7rem;
        }

        .logo span {
            color: var(--light-green);
        }

        .close-sidebar {
            display: block;
            background: none;
            border: none;
            color: var(--white);
            font-size: 1.3rem;
            cursor: pointer;
        }

        .nav-links {
            list-style: none;
            padding: 0;
        }

        .nav-links li {
            margin-bottom: 5px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--white);
            text-decoration: none;
            padding: 12px 20px;
            transition: var(--transition);
            border-radius: 0 var(--border-radius-small) var(--border-radius-small) 0;
            position: relative;
            overflow: hidden;
        }

        .nav-links a:hover, .nav-links a.active {
            background: rgba(255, 255, 255, 0.15);
            transform: translateX(5px);
        }

        .nav-links a::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 4px;
            background: var(--light-green);
            transform: scaleY(0);
            transition: var(--transition);
        }

        .nav-links a:hover::before, .nav-links a.active::before {
            transform: scaleY(1);
        }

        .nav-links i {
            font-size: 1.1rem;
            width: 20px;
            text-align: center;
        }

        /* Main Content - Mobile First */
        .main-content {
            flex: 1;
            padding: 15px;
            transition: var(--transition);
            width: 100%;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            background: var(--white);
            padding: 15px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-light);
            animation: fadeInDown 0.8s ease;
            flex-wrap: wrap;
            gap: 10px;
        }

        .header h1 {
            color: var(--primary-purple);
            font-size: 1.4rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: bold;
            font-size: 1rem;
            box-shadow: var(--shadow-light);
            transition: var(--transition);
        }

        .user-avatar:hover {
            transform: scale(1.1);
        }

        .logout-btn {
            background: var(--gradient-secondary);
            color: var(--white);
            border: none;
            padding: 8px 15px;
            border-radius: var(--border-radius-small);
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .logout-btn:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-medium);
        }

        /* Branch Selector */
        .branch-selector {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            background: var(--white);
            padding: 12px 15px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-light);
            flex-wrap: wrap;
        }

        .branch-label {
            font-weight: 600;
            color: var(--primary-purple);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .branch-radio-group {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .branch-option {
            display: flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
        }

        .branch-option input[type="radio"] {
            accent-color: var(--primary-purple);
            width: 16px;
            height: 16px;
            cursor: pointer;
        }

        .branch-badge {
            background: var(--gradient-primary);
            color: var(--white);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 10px;
        }

        /* Stats Cards - Mobile Grid */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 15px;
            box-shadow: var(--shadow-light);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            animation: fadeInUp 0.8s ease;
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-medium);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--gradient-primary);
        }

        .stat-card:nth-child(2)::before {
            background: var(--gradient-secondary);
        }

        .stat-card:nth-child(3)::before {
            background: var(--gradient-sms);
        }

        .stat-card:nth-child(4)::before {
            background: linear-gradient(135deg, var(--primary-purple), var(--dark-green));
        }

        .stat-icon {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            margin-bottom: 10px;
            color: var(--white);
            background: var(--gradient-primary);
            transition: var(--transition);
        }

        .stat-card:hover .stat-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .stat-card:nth-child(2) .stat-icon {
            background: var(--gradient-secondary);
        }

        .stat-card:nth-child(3) .stat-icon {
            background: var(--gradient-sms);
        }

        .stat-card:nth-child(4) .stat-icon {
            background: linear-gradient(135deg, var(--primary-purple), var(--dark-green));
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary-purple);
            margin-bottom: 5px;
            transition: var(--transition);
        }

        .stat-card:hover .stat-value {
            transform: scale(1.05);
        }

        .stat-card:nth-child(2) .stat-value {
            color: var(--dark-green);
        }

        .stat-card:nth-child(3) .stat-value {
            color: var(--sms-dark-blue);
        }

        .stat-card:nth-child(4) .stat-value {
            color: var(--dark-purple);
        }

        .stat-label {
            color: var(--dark-gray);
            font-weight: 600;
            font-size: 0.85rem;
        }

        /* Report Form Section */
        .report-section {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 15px;
            box-shadow: var(--shadow-light);
            margin-bottom: 20px;
            animation: fadeInUp 0.8s ease;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-gray);
            flex-wrap: wrap;
            gap: 10px;
        }

        .section-title {
            color: var(--primary-purple);
            font-size: 1.2rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Form Styles */
        .form-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark-gray);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-group input,
        .form-group select {
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            transition: var(--transition);
            background: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.2);
            outline: none;
        }

        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
            justify-content: center;
            height: 46px;
        }

        .btn-primary {
            background: var(--gradient-secondary);
            color: white;
        }

        .btn-primary:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.4);
        }

        .btn-warning {
            background: var(--gold);
            color: var(--dark-gray);
        }

        .btn-warning:hover {
            background: #e0a800;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 193, 7, 0.4);
        }

        /* Report Stats Cards */
        .report-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .report-stat-card {
            background: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: var(--shadow-light);
            text-align: center;
            transition: var(--transition);
            border-left: 4px solid var(--primary-purple);
        }

        .report-stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-medium);
        }

        .report-stat-card.net-sales {
            border-left: 4px solid var(--sms-blue);
        }

        .report-stat-card.net-sales .stat-value {
            color: var(--sms-blue);
        }

        .report-stat-card.cash-payments {
            border-left: 4px solid var(--medium-green);
        }

        .report-stat-card.cash-payments .stat-value {
            color: var(--medium-green);
        }

        .report-stat-card.card-payments {
            border-left: 4px solid var(--primary-purple);
        }

        .report-stat-card.card-payments .stat-value {
            color: var(--primary-purple);
        }

        .report-stat-card.credit-amounts {
            border-left: 4px solid #9c27b0;
        }

        .report-stat-card.credit-amounts .stat-value {
            color: #9c27b0;
        }

        /* Table Styles - UPDATED FOR FLEXIBILITY */
        .table-container {
            overflow-x: auto;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            width: 100%;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            min-width: 100%;
        }

        .report-table th {
            background: var(--gradient-primary);
            color: white;
            padding: 12px 10px;
            text-align: left;
            font-weight: 600;
            position: sticky;
            top: 0;
            font-size: 0.8rem;
            white-space: nowrap;
        }

        .report-table td {
            padding: 10px 10px;
            border-bottom: 1px solid #eee;
            font-size: 0.8rem;
            vertical-align: top;
        }

        /* Make table responsive with auto column widths */
        .report-table th,
        .report-table td {
            min-width: 60px;
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Specific column adjustments */
        .report-table th:nth-child(1),
        .report-table td:nth-child(1) {
            min-width: 100px;
        }

        .report-table th:nth-child(5),
        .report-table td:nth-child(5) {
            min-width: 120px;
        }

        .report-table th:nth-child(6),
        .report-table td:nth-child(6) {
            min-width: 80px;
        }

        .report-table th:nth-child(7),
        .report-table td:nth-child(7) {
            min-width: 60px;
        }

        .report-table th:nth-child(13),
        .report-table td:nth-child(13) {
            min-width: 100px;
        }

        .report-table tr {
            transition: var(--transition);
        }

        .report-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        .report-table tr:hover {
            background-color: #e8f4fc;
        }

        .monthly-total {
            background-color: #e9ecef !important;
            font-weight: bold;
        }

        /* Export Buttons */
        .export-buttons {
            display: flex;
            justify-content: flex-end;
            margin-top: 20px;
            gap: 10px;
        }

        .no-records {
            text-align: center;
            padding: 30px;
            color: #7f8c8d;
        }

        .no-records i {
            font-size: 36px;
            margin-bottom: 10px;
        }

        /* Mobile Menu Toggle */
        .menu-toggle {
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--gradient-primary);
            color: var(--white);
            border: none;
            width: 40px;
            height: 40px;
            border-radius: var(--border-radius-small);
            font-size: 1.2rem;
            cursor: pointer;
            transition: var(--transition);
            box-shadow: var(--shadow-light);
            z-index: 1001;
        }

        .menu-toggle:hover {
            transform: scale(1.05);
        }

        /* Floating Action Button for Mobile */
        .fab {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 55px;
            height: 55px;
            border-radius: 50%;
            background: var(--gradient-primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            box-shadow: var(--shadow-heavy);
            z-index: 1000;
            transition: var(--transition);
            animation: bounce 2s infinite;
        }

        .fab:hover {
            transform: scale(1.1);
        }

        /* Overlay for mobile sidebar */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 999;
        }

        .sidebar-overlay.active {
            display: block;
        }

        /* Toast Notification */
        .toast {
            position: fixed;
            bottom: 15px;
            right: 15px;
            left: 15px;
            background: var(--gradient-primary);
            color: white;
            padding: 12px 18px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-heavy);
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s ease;
            z-index: 2000;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }

        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        /* Tablet Styles */
        @media (min-width: 768px) {
            body {
                font-size: 15px;
            }
            
            .container {
                flex-direction: row;
            }
            
            .sidebar {
                width: 240px;
                transform: translateX(0);
                position: relative;
                height: auto;
            }
            
            .main-content {
                margin-left: 0;
                padding: 20px;
                flex: 1;
            }
            
            .close-sidebar {
                display: none;
            }
            
            .menu-toggle {
                display: none;
            }
            
            .stats-container {
                grid-template-columns: repeat(4, 1fr);
                gap: 20px;
            }
            
            .header {
                padding: 18px 25px;
            }
            
            .header h1 {
                font-size: 1.6rem;
            }
            
            .report-section {
                padding: 20px;
            }
            
            .stat-card {
                padding: 20px;
            }
            
            .stat-value {
                font-size: 1.7rem;
            }
            
            .report-table th,
            .report-table td {
                padding: 12px;
                font-size: 0.85rem;
            }
            
            .toast {
                left: auto;
                right: 20px;
                width: auto;
            }
            
            .fab {
                display: none;
            }
            
            .branch-selector {
                padding: 15px 20px;
            }
        }

        /* Desktop Styles */
        @media (min-width: 1200px) {
            .report-table th,
            .report-table td {
                white-space: nowrap;
            }
            
            .report-table th:nth-child(5),
            .report-table td:nth-child(5) {
                white-space: normal;
                max-width: 150px;
            }
        }

        /* Mobile Optimization for Table */
        @media (max-width: 767px) {
            .report-table th,
            .report-table td {
                font-size: 0.75rem;
                padding: 8px 6px;
            }
            
            .report-table th:nth-child(5),
            .report-table td:nth-child(5) {
                min-width: 100px;
            }
            
            .report-table th:nth-child(6),
            .report-table td:nth-child(6) {
                min-width: 60px;
            }
            
            .report-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .form-container {
                grid-template-columns: 1fr;
            }
            
            .branch-selector {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        /* Extra Small Mobile */
        @media (max-width: 480px) {
            .report-table th,
            .report-table td {
                font-size: 0.7rem;
                padding: 6px 4px;
            }
            
            .report-stats {
                grid-template-columns: 1fr;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .header h1 {
                font-size: 1.2rem;
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% {
                transform: translateY(0);
            }
            40% {
                transform: translateY(-8px);
            }
            60% {
                transform: translateY(-4px);
            }
        }

        @keyframes ripple-animation {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }

        /* Ripple effect styles */
        .ripple {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.5);
            transform: scale(0);
            animation: ripple-animation 0.6s linear;
        }
    </style>
</head>
<body>
    <!-- Mobile Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Floating Action Button -->
    <div class="fab" id="fab">
        <i class="fas fa-bars"></i>
    </div>

    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <i class="fas fa-berry"></i>
                    Kids <span>Berry</span>
                </div>
                <button class="close-sidebar" id="closeSidebar">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <ul class="nav-links">
                 <li><a href="admin_dashboard.php" ><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="cashier_prediction.php" class=""><i class="fas fa-chart-line"></i> Cashier Predictions</a></li>
                <li><a href="customer_manage.php" ><i class="fas fa-users"></i> Customer Management</a></li>
                <li><a href="stockkeeper_manage.php" ><i class="fas fa-user-tie"></i> Stock Keeper Management</a></li>
               <li><a href="cashier_manage.php" ><i class="fas fa-user-tie"></i> Sales Officer Management</a></li>
                <li><a href="sales_manage.php"><i class="fas fa-cash-register"></i> Cashier Management</a></li>
                <li><a href="product_manage.php" ><i class="fas fa-box"></i> Product Management</a></li>
                 <li><a href="suppliers_manage.php"><i class="fas fa-truck"></i> Suppliers Management</a></li>
                <li><a href="report_show.php" class="active"><i class="fas fa-chart-line"></i> Reports</a></li>
                <li><a href="admin_contact_management.php"><i class="fas fa-headset"></i> Contact Requests</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <div class="header">
                <h1><i class="fas fa-chart-line"></i> Sales Reports</h1>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php 
                            $admin_name = $_SESSION['admin_email'] ?? 'Admin';
                            echo strtoupper(substr($admin_name, 0, 1)); 
                        ?>
                    </div>
                    <form method="post" action="admin_logout.php">
                        <button type="submit" class="logout-btn" name="logout">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </button>
                    </form>
                    <button class="menu-toggle" id="menuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                </div>
            </div>

            <!-- Branch Selection - No Form Submit -->
            <div class="branch-selector">
                <div class="branch-label">
                    <i class="fas fa-store"></i> Select Branch:
                </div>
                <div class="branch-radio-group">
                    <label class="branch-option">
                        <input type="radio" name="selected_branch" value="branch1" <?php echo ($selected_branch == 'branch1') ? 'checked' : ''; ?> onchange="changeBranch(this.value)">
                        Branch 1 <span class="branch-badge" style="background: linear-gradient(135deg, #6a0dad, #4b0082);">cashier_users, bills, bill_items, returns_tracking</span>
                    </label>
                    <label class="branch-option">
                        <input type="radio" name="selected_branch" value="branch2" <?php echo ($selected_branch == 'branch2') ? 'checked' : ''; ?> onchange="changeBranch(this.value)">
                        Branch 2 <span class="branch-badge" style="background: linear-gradient(135deg, #228b22, #32cd32);">cashier_users2, bills2, bill_items2, returns_tracking2</span>
                    </label>
                </div>
                <div style="margin-left: auto;">
                    <span class="branch-badge" id="currentBranchBadge">
                        <i class="fas fa-database"></i> Currently viewing: <?php echo ($selected_branch == 'branch1') ? 'Branch 1 (cashier_users, bills, bill_items, returns_tracking)' : 'Branch 2 (cashier_users2, bills2, bill_items2, returns_tracking2)'; ?>
                    </span>
                </div>
            </div>

            <!-- Report Form Section -->
            <div class="report-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-chart-pie"></i> Generate Sales Report - <?php echo $branch_display; ?></h2>
                </div>
                
                <!-- Today's Returns Info -->
                <div style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 8px; padding: 15px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <i class="fas fa-exchange-alt" style="color: #dc3545;"></i>
                        <strong>Today's Returns Summary (<?php echo $branch_display; ?>):</strong> 
                        <span style="margin-left: 10px;">
                            Total Returns: <strong style="color: #dc3545;">Rs<?php echo number_format($today_returns, 2); ?></strong> 
                            (<?php echo $today_return_count; ?> items)
                        </span>
                    </div>
                </div>
                
                <form method="post" class="form-container">
                    <input type="hidden" name="branch" id="report_branch" value="<?php echo $selected_branch; ?>">
                    
                    <div class="form-group">
                        <label><i class="fas fa-chart-bar"></i> Report Type</label>
                        <select name="report_type" required>
                            <option value="Daily Sales" <?php echo $report_type == 'Daily Sales' ? 'selected' : ''; ?>>Daily Sales</option>
                            <option value="Monthly Sales" <?php echo $report_type == 'Monthly Sales' ? 'selected' : ''; ?>>Monthly Sales</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-calendar-start"></i> From</label>
                        <input type="date" name="from" value="<?php echo htmlspecialchars($from_date); ?>" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-calendar-end"></i> To</label>
                        <input type="date" name="to" value="<?php echo htmlspecialchars($to_date); ?>">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-user-tie"></i> Sales Officer</label>
                        <select name="cashier_id">
                            <option value="">All Sales Officers</option>
                            <?php 
                            // Re-run query for the selected branch
                            $cashiers_result = $conn->query($cashiers_sql);
                            if ($cashiers_result) {
                                while ($cashier = $cashiers_result->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $cashier['cashier_id']; ?>" <?php echo $cashier_id == $cashier['cashier_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cashier['name']); ?>
                                </option>
                            <?php 
                                endwhile;
                            } 
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-store"></i> Branch for Report</label>
                        <select name="branch" id="report_branch_select" class="form-control">
                            <option value="branch1" <?php echo ($selected_branch == 'branch1') ? 'selected' : ''; ?>>Branch 1 (tables: cashier_users, bills, bill_items, returns_tracking)</option>
                            <option value="branch2" <?php echo ($selected_branch == 'branch2') ? 'selected' : ''; ?>>Branch 2 (tables: cashier_users2, bills2, bill_items2, returns_tracking2)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="submit" name="generate_report" class="btn btn-primary">
                            <i class="fas fa-sync"></i> Generate Report
                        </button>
                    </div>
                </form>

                <?php if (!empty($results)) { ?>
                    <div class="report-stats">
                        <div class="report-stat-card">
                            <h3>Total Sales Items</h3>
                            <div class="stat-value">
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
                        
                        <div class="report-stat-card">
                            <h3>Total Sales</h3>
                            <div class="stat-value">Rs<?php 
                                if (isset($period_total_sales)) {
                                    echo number_format($period_total_sales, 2);
                                } else {
                                    echo number_format(0, 2);
                                }
                            ?></div>
                        </div>
                        
                        <div class="report-stat-card">
                            <h3>Total Returns (Period)</h3>
                            <div class="stat-value">Rs<?php echo number_format($period_returns, 2); ?></div>
                            <div style="font-size: 12px; color: #666;"><?php echo $period_return_count; ?> items returned</div>
                        </div>
                        
                        <div class="report-stat-card net-sales">
                            <h3>Net Sales</h3>
                            <div class="stat-value">Rs<?php 
                                if (isset($period_total_sales)) {
                                    $net_sales = $period_total_sales - $period_returns;
                                    echo number_format($net_sales, 2);
                                } else {
                                    echo number_format(0, 2);
                                }
                            ?></div>
                            <div style="font-size: 12px; color: #666;">(Sales - Returns)</div>
                        </div>
                        
                        <div class="report-stat-card cash-payments">
                            <h3>Total Cash Payments</h3>
                            <div class="stat-value">Rs<?php echo number_format($total_cash_payments, 2); ?></div>
                        </div>
                        
                        <div class="report-stat-card card-payments">
                            <h3>Total Card Payments</h3>
                            <div class="stat-value">Rs<?php echo number_format($total_card_payments, 2); ?></div>
                        </div>

                        <div class="report-stat-card credit-amounts">
                            <h3>Total Credit Amounts</h3>
                            <div class="stat-value">Rs<?php echo number_format($total_credit_amount, 2); ?></div>
                            <div style="font-size: 12px; color: #666;"><?php echo $total_credits; ?> credit bills</div>
                        </div>
                        
                        <div class="report-stat-card">
                            <h3>Total Discount</h3>
                            <div class="stat-value">Rs<?php echo number_format($total_discount, 2); ?></div>
                        </div>
                        
                        <div class="report-stat-card">
                            <h3>Total Profit</h3>
                            <div class="stat-value">Rs<?php echo number_format($total_profit, 2); ?></div>
                        </div>
                    </div>

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
                                    <th>Sales Officers</th>
                                    <th>Product</th>
                                    <th>IMEI</th>
                                    <th>Quantity</th>
                                    <th>Discount</th>
                                    <th>Sale Price</th>
                                    <th>Original Price</th>
                                    <th>Profit</th>
                                    <th>Net Price</th>
                                    <th>Payment Method</th>
                                    <th>Paid Amount</th>
                                    <th>Total Amount</th>
                                    <th>Outstanding Balance</th>
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
                                        echo "<td>Rs" . number_format($row['original_price'], 2) . "</td>";
                                        echo "<td>Rs" . number_format($row['profit'], 2) . "</td>";
                                        echo "<td>Rs" . number_format($net_price, 2) . "</td>";
                                        echo "<td>" . htmlspecialchars($row['payment_method']) . "</td>";
                                        echo "<td>Rs" . number_format($row['paid_amount'], 2) . "</td>";
                                        echo "<td>Rs" . number_format($row['total_amount'], 2) . "</td>";
                                        echo "<td>Rs" . number_format($row['outstanding_balance'], 2) . "</td>";
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
                                            echo "<td>Rs" . number_format($row['original_price'], 2) . "</td>";
                                            echo "<td>Rs" . number_format($row['profit'], 2) . "</td>";
                                            echo "<td>Rs" . number_format($net_price, 2) . "</td>";
                                            echo "<td>" . htmlspecialchars($row['payment_method']) . "</td>";
                                            echo "<td>Rs" . number_format($row['paid_amount'], 2) . "</td>";
                                            echo "<td>Rs" . number_format($row['total_amount'], 2) . "</td>";
                                            echo "<td>Rs" . number_format($row['outstanding_balance'], 2) . "</td>";
                                            echo "</tr>";
                                        }
                                        echo "<tr class='monthly-total'>";
                                        echo "<td colspan='11'><strong>Monthly Total for " . $month . "</strong></td>";
                                        echo "<td><strong>Rs" . number_format($group['total_sales'], 2) . "</strong></td>";
                                        echo "<td><strong>Cash: Rs" . number_format($group['total_cash_payments'] ?? 0, 2) . "</strong><br>";
                                        echo "<strong>Card: Rs" . number_format($group['total_card_payments'] ?? 0, 2) . "</strong></td>";
                                        echo "<td><strong>Rs" . number_format($group['total_paid_amount'], 2) . "</strong></td>";
                                        echo "<td><strong>Rs" . number_format($group['total_sales'], 2) . "</strong></td>";
                                        echo "<td><strong>Rs" . number_format($group['total_outstanding_balance'], 2) . "</strong></td>";
                                        echo "</tr>";
                                    }
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="export-buttons">
                        <form method="post">
                            <input type="hidden" name="report_type" value="<?php echo htmlspecialchars($report_type); ?>">
                            <input type="hidden" name="from" value="<?php echo htmlspecialchars($from_date); ?>">
                            <input type="hidden" name="to" value="<?php echo htmlspecialchars($to_date); ?>">
                            <input type="hidden" name="cashier_id" value="<?php echo htmlspecialchars($cashier_id); ?>">
                            <input type="hidden" name="branch" value="<?php echo htmlspecialchars($selected_branch); ?>">
                            <button type="submit" name="export_excel" class="btn btn-warning">
                                <i class="fas fa-file-excel"></i> Export to Excel
                            </button>
                        </form>
                    </div>
                <?php } else if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['generate_report'])) { ?>
                    <div class="no-records">
                        <i class="fas fa-inbox"></i>
                        <h3>No data found for the selected period in <?php echo $branch_display; ?></h3>
                        <p>Try selecting a different date range or cashier</p>
                    </div>
                <?php } else { ?>
                    <div class="no-records">
                        <i class="fas fa-chart-line"></i>
                        <h3>Generate a sales report</h3>
                        <p>Select report type and date range to generate a report</p>
                    </div>
                <?php } ?>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div class="toast" id="toast">
        <i class="fas fa-info-circle"></i>
        <span id="toastMessage">This is a toast message</span>
    </div>

    <script>
        // Mobile menu toggle
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const closeSidebar = document.getElementById('closeSidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const fab = document.getElementById('fab');

        function toggleSidebar() {
            sidebar.classList.toggle('active');
            sidebarOverlay.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
        }

        menuToggle.addEventListener('click', toggleSidebar);
        closeSidebar.addEventListener('click', toggleSidebar);
        sidebarOverlay.addEventListener('click', toggleSidebar);
        fab.addEventListener('click', toggleSidebar);

        // Toast notification function
        function showToast(message) {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');
            
            toastMessage.textContent = message;
            toast.classList.add('show');
            
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }

        // Function to change branch via AJAX (no page reload)
        async function changeBranch(branch) {
            try {
                showToast('Switching branch...');
                
                const formData = new FormData();
                formData.append('action', 'change_branch');
                formData.append('selected_branch', branch);

                const res = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await res.json();
                
                if (data.success) {
                    // Update branch badge
                    document.getElementById('currentBranchBadge').innerHTML = '<i class="fas fa-database"></i> Currently viewing: ' + data.branch_name + ' (' + data.cashier_table + ', ' + data.bills_table + ', ' + data.bill_items_table + ', ' + data.returns_table + ')';
                    
                    // Update hidden branch input and select
                    document.getElementById('report_branch').value = branch;
                    document.getElementById('report_branch_select').value = branch;
                    
                    // Reload the page to show data for the selected branch
                    setTimeout(() => {
                        window.location.reload();
                    }, 500);
                }
            } catch (error) {
                console.error('Branch change error:', error);
                showToast('Error switching branch', 'error');
            }
        }

        // Show notification if report was generated
        <?php if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['generate_report']) && !empty($from_date)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            showToast('Report generated successfully!');
        });
        <?php endif; ?>

        // Animate stats with counting effect
        document.addEventListener('DOMContentLoaded', function() {
            const statValues = document.querySelectorAll('.stat-value');
            statValues.forEach(stat => {
                const originalText = stat.textContent;
                const numberMatch = originalText.match(/Rs?([\d,.]+)/);
                if (numberMatch) {
                    const number = parseFloat(numberMatch[1].replace(/,/g, ''));
                    if (!isNaN(number)) {
                        animateValue(stat, 0, number, 1500, true);
                    }
                } else {
                    const number = parseInt(originalText);
                    if (!isNaN(number)) {
                        animateValue(stat, 0, number, 1500, false);
                    }
                }
            });
        });

        // Function to animate counting numbers
        function animateValue(element, start, end, duration, isCurrency) {
            let startTimestamp = null;
            const step = (timestamp) => {
                if (!startTimestamp) startTimestamp = timestamp;
                const progress = Math.min((timestamp - startTimestamp) / duration, 1);
                const value = Math.floor(progress * (end - start) + start);
                if (isCurrency) {
                    element.textContent = 'Rs' + value.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                } else {
                    element.textContent = value.toLocaleString();
                }
                if (progress < 1) {
                    window.requestAnimationFrame(step);
                }
            };
            window.requestAnimationFrame(step);
        }

        // Add ripple effect to stat cards
        document.querySelectorAll('.stat-card').forEach(card => {
            card.addEventListener('click', function(e) {
                const ripple = document.createElement('span');
                const rect = card.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left - size / 2;
                const y = e.clientY - rect.top - size / 2;
                
                ripple.style.width = ripple.style.height = size + 'px';
                ripple.style.left = x + 'px';
                ripple.style.top = y + 'px';
                ripple.classList.add('ripple');
                
                card.appendChild(ripple);
                
                setTimeout(() => {
                    ripple.remove();
                }, 600);
            });
        });

        // Handle touch events for better mobile experience
        document.addEventListener('touchstart', function() {}, {passive: true});
        
        // Prevent zoom on double tap for better mobile experience
        let lastTouchEnd = 0;
        document.addEventListener('touchend', function (event) {
            const now = (new Date()).getTime();
            if (now - lastTouchEnd <= 300) {
                event.preventDefault();
            }
            lastTouchEnd = now;
        }, false);

        // Auto-adjust table column widths
        function adjustTableColumns() {
            const table = document.querySelector('.report-table');
            if (!table) return;
            
            const ths = table.querySelectorAll('th');
            const tds = table.querySelectorAll('td');
            
            // Reset all widths
            ths.forEach(th => th.style.width = 'auto');
            tds.forEach(td => td.style.width = 'auto');
            
            // Calculate optimal widths based on content
            ths.forEach((th, index) => {
                const cells = Array.from(table.querySelectorAll(`td:nth-child(${index + 1})`));
                cells.unshift(th);
                
                const maxWidth = Math.max(...cells.map(cell => {
                    const text = cell.textContent || cell.innerText;
                    return text.length * 8; // Approximate pixel width per character
                }));
                
                const finalWidth = Math.min(Math.max(maxWidth, 60), 200); // Min 60px, Max 200px
                th.style.width = finalWidth + 'px';
                cells.forEach(cell => cell.style.width = finalWidth + 'px');
            });
        }

        // Adjust table columns on load and window resize
        window.addEventListener('load', adjustTableColumns);
        window.addEventListener('resize', adjustTableColumns);
    </script>
</body>
</html>
<?php
$conn->close();
?>