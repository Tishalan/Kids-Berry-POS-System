[file name]: bill_history.php
[file content begin]
<?php

date_default_timezone_set('Asia/Colombo');
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

$branch_id = isset($_SESSION['branch_id']) ? $_SESSION['branch_id'] : 1;

// Get all cashiers for filtering
$cashiers_sql = "SELECT cashier_id, name FROM cashier_users WHERE status = 'active' ORDER BY name";
$cashiers_result = $conn->query($cashiers_sql);

// Initialize variables
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$search_bill_no = isset($_GET['search_bill']) ? mysqli_real_escape_string($conn, $_GET['search_bill']) : '';
$selected_cashier = isset($_GET['cashier_id']) ? intval($_GET['cashier_id']) : 0;
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-7 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Get bills based on filters
$bills_sql = "SELECT 
                b.bill_no,
                b.date,
                b.payment_method,
                b.subtotal,
                b.total_discount,
                b.total,
                b.paid_amount,
                b.balance,
                b.customer_name,
                b.phone_no,
                b.nic_no,
                c.name as cashier_name,
                COUNT(bi.id) as item_count,
                SUM(bi.quantity) as total_quantity,
                SUM(CASE WHEN bi.return_status = 'returned' THEN bi.quantity ELSE 0 END) as returned_quantity,
                SUM(CASE WHEN bi.return_status = 'returned' THEN bi.subtotal ELSE 0 END) as returned_amount
              FROM bills b
              JOIN cashier_users c ON b.cashier_id = c.cashier_id
              LEFT JOIN bill_items bi ON b.bill_no = bi.bill_no
              WHERE b.branch_id = $branch_id";

// Add date filter
if (!empty($selected_date) && $selected_date == date('Y-m-d')) {
    $bills_sql .= " AND DATE(b.date) = '$selected_date'";
} else if (!empty($start_date) && !empty($end_date)) {
    $bills_sql .= " AND DATE(b.date) BETWEEN '$start_date' AND '$end_date'";
}

// Add bill number search
if (!empty($search_bill_no)) {
    $bills_sql .= " AND b.bill_no LIKE '%$search_bill_no%'";
}

// Add cashier filter
if ($selected_cashier > 0) {
    $bills_sql .= " AND b.cashier_id = $selected_cashier";
}

$bills_sql .= " GROUP BY b.bill_no, b.date, b.payment_method, b.subtotal, b.total_discount, 
                b.total, b.paid_amount, b.balance, b.customer_name, b.phone_no, b.nic_no, c.name
                ORDER BY b.date DESC, b.bill_no DESC";

$bills_result = $conn->query($bills_sql);

// Calculate totals
$total_bills = 0;
$total_sales = 0;
$total_discounts = 0;
$total_paid = 0;
$total_balance = 0;
$total_returned = 0;

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIDS Berry - Bill History Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --deep-blue: #1A1B41;
            --teal: #00B4D8;
            --violet: #7209B7;
            --coral: #FF6B6B;
            --mint: #06D6A0;
            --amber: #FFD166;
            --light-gray: #F8F9FA;
            --dark-gray: #343A40;
            --card-shadow: 0 20px 40px rgba(0, 0, 0, 0.08);
            --transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.1);
            --radius-xl: 24px;
            --radius-lg: 20px;
            --radius-md: 16px;
            --radius-sm: 12px;
            --gradient-teal: linear-gradient(135deg, #00B4D8 0%, #0096C7 100%);
            --gradient-violet: linear-gradient(135deg, #7209B7 0%, #560BAD 100%);
            --gradient-coral: linear-gradient(135deg, #FF6B6B 0%, #FF4D4D 100%);
            --gradient-mint: linear-gradient(135deg, #06D6A0 0%, #04A777 100%);
            --gradient-amber: linear-gradient(135deg, #FFD166 0%, #FFB347 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #0C0D1A 0%, #1A1B41 100%);
            color: #FFFFFF;
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 10% 20%, rgba(114, 9, 183, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(0, 180, 216, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 50% 50%, rgba(6, 214, 160, 0.1) 0%, transparent 50%);
            z-index: -1;
        }

        .dashboard-container {
            padding: 30px;
            max-width: 1800px;
            margin: 0 auto;
        }

        /* Header with floating effect */
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            padding: 30px;
            background: rgba(26, 27, 65, 0.8);
            backdrop-filter: blur(20px);
            border-radius: var(--radius-xl);
            border: 1px solid rgba(255, 255, 255, 0.1);
            position: relative;
            overflow: hidden;
            animation: slideDown 0.8s ease;
        }

        .dashboard-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--teal), var(--violet), var(--coral));
            border-radius: var(--radius-xl) var(--radius-xl) 0 0;
        }

        .brand-section {
            display: flex;
            align-items: center;
            gap: 24px;
        }

        .logo-circle {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--gradient-violet);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 30px rgba(114, 9, 183, 0.4);
            transition: var(--transition);
            border: 3px solid rgba(255, 255, 255, 0.2);
        }

        .logo-circle:hover {
            transform: rotate(15deg) scale(1.05);
            box-shadow: 0 15px 40px rgba(114, 9, 183, 0.6);
        }

        .logo-circle i {
            font-size: 36px;
            color: white;
        }

        .title-section h1 {
            font-size: 36px;
            font-weight: 800;
            background: linear-gradient(90deg, #FFFFFF 0%, var(--teal) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .title-section .subtitle {
            color: rgba(255, 255, 255, 0.7);
            font-size: 14px;
            font-weight: 500;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .header-controls {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .datetime-display {
            text-align: right;
            background: rgba(255, 255, 255, 0.05);
            padding: 15px 25px;
            border-radius: var(--radius-md);
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
        }

        .datetime-display .date {
            font-size: 16px;
            font-weight: 600;
            color: var(--teal);
            margin-bottom: 4px;
        }

        .datetime-display .time {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.8);
        }

        .logout-btn {
            background: rgba(255, 107, 107, 0.2);
            color: var(--coral);
            border: 2px solid var(--coral);
            padding: 12px 28px;
            border-radius: 50px;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            backdrop-filter: blur(10px);
        }

        .logout-btn:hover {
            background: var(--coral);
            color: white;
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(255, 107, 107, 0.3);
        }

        /* Navigation with glassmorphism */
        .dashboard-nav {
            display: flex;
            justify-content: center;
            gap: 16px;
            margin-bottom: 40px;
            flex-wrap: wrap;
        }

        .nav-btn {
            text-decoration: none;
            color: white;
            font-weight: 600;
            padding: 16px 32px;
            border-radius: var(--radius-lg);
            background: rgba(255, 255, 255, 0.05);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            font-size: 14px;
            position: relative;
            overflow: hidden;
        }

        .nav-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transition: 0.6s;
        }

        .nav-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-5px);
            border-color: rgba(255, 255, 255, 0.2);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.2);
        }

        .nav-btn:hover::before {
            left: 100%;
        }

        .nav-btn.active {
            background: var(--gradient-teal);
            border-color: transparent;
            box-shadow: 0 10px 25px rgba(0, 180, 216, 0.3);
        }

        .nav-btn i {
            font-size: 16px;
        }

        /* Date Range Selector - Futuristic */
        .date-range-selector {
            background: rgba(26, 27, 65, 0.7);
            backdrop-filter: blur(15px);
            border-radius: var(--radius-xl);
            padding: 30px;
            margin-bottom: 40px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            position: relative;
            overflow: hidden;
        }

        .date-range-selector::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--teal), var(--violet));
        }

        .date-range-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .date-range-title {
            font-size: 22px;
            font-weight: 700;
            color: white;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .date-range-title i {
            color: var(--teal);
            font-size: 24px;
        }

        .date-range-display {
            background: rgba(0, 180, 216, 0.1);
            padding: 12px 24px;
            border-radius: var(--radius-md);
            font-weight: 600;
            border: 1px solid var(--teal);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .date-range-controls {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .date-control-group {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .date-control-group label {
            font-size: 13px;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.8);
            display: flex;
            align-items: center;
            gap: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .date-control-group label i {
            color: var(--teal);
        }

        .date-input {
            padding: 16px;
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid rgba(255, 255, 255, 0.1);
            border-radius: var(--radius-md);
            color: white;
            font-size: 15px;
            font-weight: 500;
            transition: var(--transition);
            backdrop-filter: blur(10px);
        }

        .date-input:focus {
            outline: none;
            border-color: var(--teal);
            box-shadow: 0 0 0 3px rgba(0, 180, 216, 0.2);
            background: rgba(255, 255, 255, 0.08);
        }

        .search-input {
            padding: 16px;
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid rgba(255, 255, 255, 0.1);
            border-radius: var(--radius-md);
            color: white;
            font-size: 15px;
            font-weight: 500;
            transition: var(--transition);
            backdrop-filter: blur(10px);
            padding-left: 50px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='%2300B4D8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='8'%3E%3C/circle%3E%3Cline x1='21' y1='21' x2='16.65' y2='16.65'%3E%3C/line%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: 16px center;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--teal);
            box-shadow: 0 0 0 3px rgba(0, 180, 216, 0.2);
            background-color: rgba(255, 255, 255, 0.08);
        }

        .cashier-select {
            padding: 16px;
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid rgba(255, 255, 255, 0.1);
            border-radius: var(--radius-md);
            color: white;
            font-size: 15px;
            font-weight: 500;
            transition: var(--transition);
            backdrop-filter: blur(10px);
            cursor: pointer;
        }

        .cashier-select option {
            background: var(--deep-blue);
            color: white;
        }

        .cashier-select:focus {
            outline: none;
            border-color: var(--teal);
            box-shadow: 0 0 0 3px rgba(0, 180, 216, 0.2);
        }

        .filter-btn {
            padding: 16px;
            background: var(--gradient-teal);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            cursor: pointer;
            font-weight: 700;
            font-size: 15px;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .filter-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 180, 216, 0.3);
        }

        /* Quick Date Actions */
        .quick-date-actions {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }

        .quick-date-btn {
            padding: 12px 24px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: var(--radius-md);
            color: white;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .quick-date-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
        }

        .quick-date-btn.active {
            background: var(--gradient-violet);
            border-color: transparent;
        }

        /* Stats Dashboard - Grid Layout */
        .stats-dashboard {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: rgba(26, 27, 65, 0.7);
            backdrop-filter: blur(15px);
            border-radius: var(--radius-lg);
            padding: 30px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-10px);
            border-color: rgba(255, 255, 255, 0.2);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 6px;
            height: 100%;
        }

        .stat-card:nth-child(1)::before { background: var(--gradient-teal); }
        .stat-card:nth-child(2)::before { background: var(--gradient-mint); }
        .stat-card:nth-child(3)::before { background: var(--gradient-amber); }
        .stat-card:nth-child(4)::before { background: var(--gradient-coral); }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }

        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }

        .stat-card:nth-child(1) .stat-icon { background: var(--gradient-teal); }
        .stat-card:nth-child(2) .stat-icon { background: var(--gradient-mint); }
        .stat-card:nth-child(3) .stat-icon { background: var(--gradient-amber); }
        .stat-card:nth-child(4) .stat-icon { background: var(--gradient-coral); }

        .stat-trend {
            font-size: 12px;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .stat-trend.positive { color: var(--mint); }
        .stat-trend.negative { color: var(--coral); }

        .stat-title {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.7);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 15px;
            background: linear-gradient(90deg, #FFFFFF, var(--teal));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .stat-comparison {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.5);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Bills Table - Modern Design */
        .bills-table-container {
            background: rgba(26, 27, 65, 0.7);
            backdrop-filter: blur(15px);
            border-radius: var(--radius-xl);
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 40px;
            position: relative;
        }

        .table-header {
            padding: 30px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-title {
            font-size: 22px;
            font-weight: 700;
            color: white;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .table-title i {
            color: var(--teal);
        }

        .table-actions {
            display: flex;
            gap: 15px;
        }

        .export-btn {
            padding: 12px 24px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: var(--radius-md);
            color: white;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .export-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
        }

        .table-wrapper {
            overflow-x: auto;
            padding: 20px;
        }

        .bills-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            min-width: 1200px;
        }

        .bills-table thead th {
            background: rgba(255, 255, 255, 0.05);
            padding: 20px;
            text-align: left;
            font-weight: 700;
            color: rgba(255, 255, 255, 0.9);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            border-bottom: 2px solid rgba(255, 255, 255, 0.1);
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .bills-table tbody tr {
            transition: var(--transition);
            position: relative;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .bills-table tbody tr:hover {
            background: rgba(255, 255, 255, 0.03);
            transform: translateX(10px);
        }

        .bills-table tbody tr::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 4px;
            height: 100%;
            background: transparent;
            transition: var(--transition);
        }

        .bills-table tbody tr:hover::before {
            background: var(--gradient-teal);
        }

        .bills-table tbody td {
            padding: 20px;
            font-size: 14px;
            font-weight: 500;
            color: rgba(255, 255, 255, 0.9);
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .bill-number {
            font-weight: 800;
            color: white;
            font-size: 16px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .bill-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .status-returned {
            background: rgba(255, 107, 107, 0.2);
            color: var(--coral);
            border: 1px solid rgba(255, 107, 107, 0.3);
        }

        .status-completed {
            background: rgba(6, 214, 160, 0.2);
            color: var(--mint);
            border: 1px solid rgba(6, 214, 160, 0.3);
        }

        .customer-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .customer-name {
            font-weight: 700;
            color: white;
        }

        .customer-contact {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.6);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .payment-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .payment-cash {
            background: rgba(6, 214, 160, 0.2);
            color: var(--mint);
            border: 1px solid rgba(6, 214, 160, 0.3);
        }

        .payment-card {
            background: rgba(255, 209, 102, 0.2);
            color: var(--amber);
            border: 1px solid rgba(255, 209, 102, 0.3);
        }

        .payment-credit {
            background: rgba(114, 9, 183, 0.2);
            color: #C77DFF;
            border: 1px solid rgba(114, 9, 183, 0.3);
        }

        .amount-cell {
            font-weight: 700;
            font-size: 15px;
        }

        .positive-amount {
            color: var(--mint);
        }

        .negative-amount {
            color: var(--coral);
        }

        .action-buttons {
            display: flex;
            gap: 10px;
        }

        .action-btn {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            font-size: 14px;
            color: white;
        }

        .view-btn {
            background: rgba(0, 180, 216, 0.2);
            border: 1px solid rgba(0, 180, 216, 0.3);
        }

        .view-btn:hover {
            background: var(--teal);
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 180, 216, 0.3);
        }

        .print-btn {
            background: rgba(255, 209, 102, 0.2);
            border: 1px solid rgba(255, 209, 102, 0.3);
        }

        .print-btn:hover {
            background: var(--amber);
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(255, 209, 102, 0.3);
        }

        .return-btn {
            background: rgba(255, 107, 107, 0.2);
            border: 1px solid rgba(255, 107, 107, 0.3);
        }

        .return-btn:hover {
            background: var(--coral);
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(255, 107, 107, 0.3);
        }

        /* Empty State */
        .empty-state {
            padding: 80px 40px;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 25px;
        }

        .empty-icon {
            width: 120px;
            height: 120px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            color: var(--teal);
            margin-bottom: 20px;
            border: 2px solid rgba(255, 255, 255, 0.1);
        }

        .empty-state h3 {
            font-size: 28px;
            font-weight: 700;
            color: white;
            margin-bottom: 10px;
        }

        .empty-state p {
            font-size: 16px;
            color: rgba(255, 255, 255, 0.6);
            max-width: 400px;
            line-height: 1.6;
        }

        /* Modal - Futuristic */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(10px);
            z-index: 1000;
            animation: fadeIn 0.3s ease;
        }

        .modal-container {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(26, 27, 65, 0.95);
            backdrop-filter: blur(30px);
            border-radius: var(--radius-xl);
            width: 90%;
            max-width: 1200px;
            max-height: 90vh;
            overflow-y: auto;
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 40px 80px rgba(0, 0, 0, 0.5);
            animation: slideUp 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .modal-header {
            padding: 30px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: rgba(26, 27, 65, 0.95);
            backdrop-filter: blur(30px);
            z-index: 10;
        }

        .modal-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--teal), var(--violet));
        }

        .modal-title {
            font-size: 24px;
            font-weight: 700;
            color: white;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .modal-close {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            cursor: pointer;
            transition: var(--transition);
        }

        .modal-close:hover {
            background: var(--coral);
            transform: rotate(90deg);
        }

        .modal-content {
            padding: 30px;
        }

        /* Bill Summary Grid */
        .bill-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .summary-card {
            background: rgba(255, 255, 255, 0.03);
            border-radius: var(--radius-lg);
            padding: 25px;
            border: 1px solid rgba(255, 255, 255, 0.05);
            transition: var(--transition);
        }

        .summary-card:hover {
            background: rgba(255, 255, 255, 0.05);
            transform: translateY(-5px);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .summary-label {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.6);
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .summary-value {
            font-size: 20px;
            font-weight: 800;
            color: white;
        }

        /* Animations */
        @keyframes slideDown {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        @keyframes slideUp {
            from {
                transform: translate(-50%, -40%);
                opacity: 0;
            }
            to {
                transform: translate(-50%, -50%);
                opacity: 1;
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(0, 180, 216, 0.4);
            }
            70% {
                box-shadow: 0 0 0 15px rgba(0, 180, 216, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(0, 180, 216, 0);
            }
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .dashboard-container {
                padding: 20px;
            }
            
            .stats-dashboard {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .date-range-controls {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .dashboard-header {
                flex-direction: column;
                gap: 25px;
                text-align: center;
                padding: 25px;
            }
            
            .brand-section {
                flex-direction: column;
                text-align: center;
            }
            
            .header-controls {
                flex-direction: column;
                width: 100%;
            }
            
            .datetime-display {
                width: 100%;
                text-align: center;
            }
            
            .dashboard-nav {
                flex-direction: column;
            }
            
            .nav-btn {
                justify-content: center;
            }
            
            .stats-dashboard {
                grid-template-columns: 1fr;
            }
            
            .date-range-controls {
                grid-template-columns: 1fr;
            }
            
            .table-header {
                flex-direction: column;
                gap: 20px;
                align-items: stretch;
            }
            
            .table-actions {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .action-buttons {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .action-btn {
                width: 36px;
                height: 36px;
            }
        }

        @media (max-width: 480px) {
            .dashboard-container {
                padding: 15px;
            }
            
            .title-section h1 {
                font-size: 28px;
            }
            
            .stat-card {
                padding: 20px;
            }
            
            .stat-value {
                font-size: 28px;
            }
            
            .modal-container {
                width: 95%;
                padding: 15px;
            }
        }

        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, var(--teal), var(--violet));
            border-radius: 10px;
            border: 2px solid rgba(26, 27, 65, 0.8);
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, var(--violet), var(--coral));
        }

        /* Loading Animation */
        .loading-spinner {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 2000;
        }

        .spinner {
            width: 60px;
            height: 60px;
            border: 4px solid rgba(255, 255, 255, 0.1);
            border-top: 4px solid var(--teal);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <!-- Loading Spinner -->
    <div class="loading-spinner" id="loadingSpinner">
        <div class="spinner"></div>
    </div>

    <div class="dashboard-container">
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div class="brand-section">
                <div class="logo-circle">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="title-section">
                    <h1>Bill History Dashboard</h1>
                    <div class="subtitle">KIDS Berry Sales Analytics</div>
                </div>
            </div>
            
            <div class="header-controls">
                <div class="datetime-display">
                    <div class="date"><?php echo date('l, F j, Y'); ?></div>
                    <div class="time" id="currentTime"><?php echo date('g:i:s A'); ?></div>
                </div>
                <form method="post">
                    <button type="submit" name="logout" class="logout-btn">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Navigation -->
        <div class="dashboard-nav">
            <a href="billing1.php" class="nav-btn">
                <i class="fas fa-cash-register"></i> Billing
            </a>
            <a href="customer1.php" class="nav-btn">
                <i class="fas fa-users"></i> Customers
            </a>
            <a href="report1.php" class="nav-btn">
                <i class="fas fa-chart-pie"></i> Reports
            </a>
            <a href="bill_history1.php" class="nav-btn active">
                <i class="fas fa-history"></i> Bill History
            </a>
            <a href="credit_payments1.php" class="nav-btn">
                <i class="fas fa-credit-card"></i> Credit
            </a>
            <a href="return_sale1.php" class="nav-btn">
                <i class="fas fa-undo"></i> Returns
            </a>
        </div>
        
        <!-- Date Range Selector -->
        <div class="date-range-selector">
            <div class="date-range-header">
                <h3 class="date-range-title">
                    <i class="fas fa-calendar-alt"></i> Date Range Filter
                </h3>
                <div class="date-range-display">
                    <i class="fas fa-clock"></i>
                    <?php 
                    if (!empty($selected_date) && $selected_date == date('Y-m-d')) {
                        echo "Today's Bills";
                    } else if (!empty($start_date) && !empty($end_date)) {
                        echo date('M d, Y', strtotime($start_date)) . " - " . date('M d, Y', strtotime($end_date));
                    }
                    ?>
                </div>
            </div>
            
            <form method="get" action="" class="date-range-controls">
                <div class="date-control-group">
                    <label><i class="fas fa-calendar-plus"></i> Start Date</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="date-input">
                </div>
                
                <div class="date-control-group">
                    <label><i class="fas fa-calendar-minus"></i> End Date</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="date-input">
                </div>
                
                <div class="date-control-group">
                    <label><i class="fas fa-search"></i> Search Bill No</label>
                    <input type="text" name="search_bill" value="<?php echo htmlspecialchars($search_bill_no); ?>" placeholder="Enter bill number..." class="search-input">
                </div>
                
                <div class="date-control-group">
                    <label><i class="fas fa-user-tie"></i> Sales Officer</label>
                    <select name="cashier_id" class="cashier-select">
                        <option value="0">All Sales Officers</option>
                        <?php 
                        $cashiers_result->data_seek(0);
                        while ($cashier = $cashiers_result->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $cashier['cashier_id']; ?>" <?php echo $selected_cashier == $cashier['cashier_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cashier['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="date-control-group">
                    <label><i class="fas fa-filter"></i> Apply Filters</label>
                    <button type="submit" class="filter-btn">
                        <i class="fas fa-sliders-h"></i> Filter Results
                    </button>
                </div>
            </form>
            
            <div class="quick-date-actions">
                <button class="quick-date-btn" onclick="setDateRange('today')">
                    <i class="fas fa-sun"></i> Today
                </button>
                <button class="quick-date-btn" onclick="setDateRange('yesterday')">
                    <i class="fas fa-calendar-day"></i> Yesterday
                </button>
                <button class="quick-date-btn" onclick="setDateRange('week')">
                    <i class="fas fa-calendar-week"></i> This Week
                </button>
                <button class="quick-date-btn" onclick="setDateRange('month')">
                    <i class="fas fa-calendar"></i> This Month
                </button>
            </div>
        </div>
        
        <?php if ($bills_result->num_rows > 0): 
            // Calculate totals while displaying
            $total_bills = 0;
            $total_sales = 0;
            $total_discounts = 0;
            $total_paid = 0;
            $total_balance = 0;
            $total_returned = 0;
        ?>
            <!-- Stats Dashboard -->
            <div class="stats-dashboard">
                <?php while($bill = $bills_result->fetch_assoc()): 
                    $total_bills++;
                    $total_sales += $bill['total'];
                    $total_discounts += $bill['total_discount'];
                    $total_paid += $bill['paid_amount'];
                    $total_balance += $bill['balance'];
                    $total_returned += $bill['returned_amount'];
                ?>
                <?php endwhile; 
                // Reset pointer for table display
                $bills_result->data_seek(0);
                ?>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-receipt"></i>
                        </div>
                        <div class="stat-trend positive">
                            <i class="fas fa-arrow-up"></i> 12%
                        </div>
                    </div>
                    <div class="stat-title">Total Bills</div>
                    <div class="stat-value"><?php echo number_format($total_bills); ?></div>
                    <div class="stat-comparison">
                        <i class="fas fa-chart-line"></i>
                        <?php echo number_format($total_bills / 7, 1); ?> bills/day avg
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="stat-trend positive">
                            <i class="fas fa-arrow-up"></i> 8%
                        </div>
                    </div>
                    <div class="stat-title">Total Sales</div>
                    <div class="stat-value">Rs<?php echo number_format($total_sales, 0); ?></div>
                    <div class="stat-comparison">
                        <i class="fas fa-chart-line"></i>
                        Rs<?php echo number_format($total_sales / 7, 0); ?>/day avg
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-tag"></i>
                        </div>
                        <div class="stat-trend negative">
                            <i class="fas fa-arrow-down"></i> 3%
                        </div>
                    </div>
                    <div class="stat-title">Total Discounts</div>
                    <div class="stat-value">Rs<?php echo number_format($total_discounts, 0); ?></div>
                    <div class="stat-comparison">
                        <i class="fas fa-percentage"></i>
                        <?php echo number_format(($total_discounts / $total_sales) * 100, 1); ?>% of sales
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-undo"></i>
                        </div>
                        <div class="stat-trend positive">
                            <i class="fas fa-arrow-down"></i> 15%
                        </div>
                    </div>
                    <div class="stat-title">Total Returns</div>
                    <div class="stat-value">Rs<?php echo number_format($total_returned, 0); ?></div>
                    <div class="stat-comparison">
                        <i class="fas fa-exchange-alt"></i>
                        <?php echo number_format(($total_returned / $total_sales) * 100, 1); ?>% of sales
                    </div>
                </div>
            </div>
            
            <!-- Bills Table -->
            <div class="bills-table-container">
                <div class="table-header">
                    <h3 class="table-title">
                        <i class="fas fa-list"></i> Bill Details
                    </h3>
                    <div class="table-actions">
                        <button class="export-btn" onclick="exportToCSV()">
                            <i class="fas fa-file-export"></i> Export CSV
                        </button>
                        <button class="export-btn" onclick="printReport()">
                            <i class="fas fa-print"></i> Print Report
                        </button>
                    </div>
                </div>
                
                <div class="table-wrapper">
                    <table class="bills-table">
                        <thead>
                            <tr>
                                <th>Bill No</th>
                                <th>Date & Time</th>
                                <th>Customer</th>
                                <th>Cashier</th>
                                <th>Payment</th>
                                <th>Items</th>
                                <th>Subtotal</th>
                                <th>Discount</th>
                                <th>Total</th>
                                <th>Paid</th>
                                <th>Balance</th>
                                <th>Returns</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($bill = $bills_result->fetch_assoc()): 
                                $payment_class = 'payment-' . strtolower($bill['payment_method']);
                                $status_class = $bill['returned_quantity'] > 0 ? 'status-returned' : 'status-completed';
                            ?>
                            <tr>
                                <td>
                                    <div class="bill-number">
                                        <?php echo $bill['bill_no']; ?>
                                        <span class="bill-status <?php echo $status_class; ?>">
                                            <i class="fas <?php echo $bill['returned_quantity'] > 0 ? 'fa-undo' : 'fa-check'; ?>"></i>
                                            <?php echo $bill['returned_quantity'] > 0 ? 'Part Returned' : 'Completed'; ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <?php echo date('Y-m-d', strtotime($bill['date'])); ?><br>
                                    <small><?php echo date('h:i A', strtotime($bill['date'])); ?></small>
                                </td>
                                <td>
                                    <div class="customer-info">
                                        <div class="customer-name">
                                            <?php echo !empty($bill['customer_name']) ? htmlspecialchars($bill['customer_name']) : 'Walk-in Customer'; ?>
                                        </div>
                                        <?php if($bill['phone_no']): ?>
                                            <div class="customer-contact">
                                                <i class="fas fa-phone"></i>
                                                <?php echo $bill['phone_no']; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($bill['cashier_name']); ?></td>
                                <td>
                                    <span class="payment-badge <?php echo $payment_class; ?>">
                                        <i class="fas <?php echo $bill['payment_method'] == 'Cash' ? 'fa-money-bill' : ($bill['payment_method'] == 'Card' ? 'fa-credit-card' : 'fa-file-invoice-dollar'); ?>"></i>
                                        <?php echo $bill['payment_method']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo $bill['item_count']; ?> items<br>
                                    <small><?php echo $bill['total_quantity']; ?> qty</small>
                                </td>
                                <td class="amount-cell">Rs<?php echo number_format($bill['subtotal'], 2); ?></td>
                                <td class="amount-cell">Rs<?php echo number_format($bill['total_discount'], 2); ?></td>
                                <td class="amount-cell">
                                    <strong>Rs<?php echo number_format($bill['total'], 2); ?></strong>
                                </td>
                                <td class="amount-cell positive-amount">Rs<?php echo number_format($bill['paid_amount'], 2); ?></td>
                                <td class="amount-cell <?php echo $bill['balance'] < 0 ? 'negative-amount' : 'positive-amount'; ?>">
                                    Rs<?php echo number_format($bill['balance'], 2); ?>
                                </td>
                                <td>
                                    <?php if($bill['returned_quantity'] > 0): ?>
                                        <div class="customer-info">
                                            <span class="negative-amount">
                                                Rs<?php echo number_format($bill['returned_amount'], 2); ?>
                                            </span>
                                            <small><?php echo $bill['returned_quantity']; ?> items</small>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: var(--mint);">None</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="action-btn view-btn" onclick="viewBillDetails('<?php echo $bill['bill_no']; ?>')" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="action-btn print-btn" onclick="printBill('<?php echo $bill['bill_no']; ?>')" title="Print Bill">
                                            <i class="fas fa-print"></i>
                                        </button>
                                        <?php if($bill['returned_quantity'] == 0): ?>
                                        <button class="action-btn return-btn" onclick="returnToSalePage('<?php echo $bill['bill_no']; ?>')" title="Return Items">
                                            <i class="fas fa-undo"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <!-- Empty State -->
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="fas fa-receipt"></i>
                </div>
                <h3>No bills found</h3>
                <p>Try selecting a different date range or search criteria</p>
                <div class="quick-date-actions">
                    <button class="quick-date-btn" onclick="setDateRange('today')">
                        <i class="fas fa-sun"></i> View Today
                    </button>
                    <button class="quick-date-btn" onclick="setDateRange('week')">
                        <i class="fas fa-calendar-week"></i> View This Week
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Bill Details Modal -->
    <div class="modal-overlay" id="billModal">
        <div class="modal-container" id="modalContainer">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-file-invoice"></i>
                    Bill Details - <span id="modal-bill-no"></span>
                </h3>
                <div class="modal-close" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </div>
            </div>
            
            <div class="modal-content" id="billModalContent">
                <!-- Dynamic content will be loaded here -->
            </div>
        </div>
    </div>
    
    <script>
        // Update time in real-time
        function updateClock() {
            const now = new Date();
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            let hours = now.getHours();
            let minutes = now.getMinutes();
            let seconds = now.getSeconds();
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12 || 12;
            minutes = minutes < 10 ? '0' + minutes : minutes;
            seconds = seconds < 10 ? '0' + seconds : seconds;
            const timeString = `${hours}:${minutes}:${seconds} ${ampm}`;
            document.getElementById('currentTime').textContent = timeString;
            setTimeout(updateClock, 1000);
        }
        updateClock();
        
        // Loading state management
        function showLoading() {
            document.getElementById('loadingSpinner').style.display = 'block';
        }
        
        function hideLoading() {
            document.getElementById('loadingSpinner').style.display = 'none';
        }
        
        // Set quick date ranges
        function setDateRange(range) {
            const today = new Date();
            let startDate, endDate;
            
            switch(range) {
                case 'today':
                    startDate = endDate = today.toISOString().split('T')[0];
                    break;
                case 'yesterday':
                    const yesterday = new Date(today);
                    yesterday.setDate(today.getDate() - 1);
                    startDate = endDate = yesterday.toISOString().split('T')[0];
                    break;
                case 'week':
                    const weekStart = new Date(today);
                    weekStart.setDate(today.getDate() - today.getDay());
                    startDate = weekStart.toISOString().split('T')[0];
                    endDate = today.toISOString().split('T')[0];
                    break;
                case 'month':
                    const monthStart = new Date(today.getFullYear(), today.getMonth(), 1);
                    startDate = monthStart.toISOString().split('T')[0];
                    endDate = today.toISOString().split('T')[0];
                    break;
                default:
                    return;
            }
            
            // Update form inputs
            document.querySelector('input[name="start_date"]').value = startDate;
            document.querySelector('input[name="end_date"]').value = endDate;
            
            // Submit form
            document.querySelector('form').submit();
        }
        
        // View bill details
        function viewBillDetails(billNo) {
            showLoading();
            
            fetch('get_details.php?bill_no=' + billNo)
                .then(response => response.json())
                .then(data => {
                    hideLoading();
                    if (data.success) {
                        displayBillDetails(data);
                    } else {
                        alert('Error loading bill details: ' + data.message);
                    }
                })
                .catch(error => {
                    hideLoading();
                    console.error('Error:', error);
                    alert('An error occurred while loading bill details.');
                });
        }
        
        function displayBillDetails(data) {
            const modal = document.getElementById('billModal');
            const content = document.getElementById('billModalContent');
            const billNo = document.getElementById('modal-bill-no');
            
            billNo.textContent = data.bill.bill_no;
            
            let html = `
                <div class="bill-summary-grid">
                    <div class="summary-card">
                        <div class="summary-label">
                            <i class="fas fa-receipt"></i> Bill Number
                        </div>
                        <div class="summary-value">${data.bill.bill_no}</div>
                    </div>
                    
                    <div class="summary-card">
                        <div class="summary-label">
                            <i class="fas fa-calendar"></i> Date & Time
                        </div>
                        <div class="summary-value">${data.bill.date}</div>
                    </div>
                    
                    <div class="summary-card">
                        <div class="summary-label">
                            <i class="fas fa-user-tie"></i> Cashier
                        </div>
                        <div class="summary-value">${data.bill.cashier_name}</div>
                    </div>
                    
                    <div class="summary-card">
                        <div class="summary-label">
                            <i class="fas fa-credit-card"></i> Payment Method
                        </div>
                        <div class="summary-value">${data.bill.payment_method}</div>
                    </div>
                    
                    <div class="summary-card">
                        <div class="summary-label">
                            <i class="fas fa-user"></i> Customer Name
                        </div>
                        <div class="summary-value">${data.bill.customer_name || 'Walk-in Customer'}</div>
                    </div>
                    
                    <div class="summary-card">
                        <div class="summary-label">
                            <i class="fas fa-phone"></i> Phone Number
                        </div>
                        <div class="summary-value">${data.bill.phone_no || 'N/A'}</div>
                    </div>
                </div>
                
                <div style="margin: 40px 0;">
                    <h4 style="color: white; margin-bottom: 25px; display: flex; align-items: center; gap: 12px; font-size: 18px;">
                        <i class="fas fa-boxes"></i> Products Purchased
                    </h4>
                    
                    <div class="table-wrapper">
                        <table class="bills-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Color</th>
                                    <th>Quantity</th>
                                    <th>Unit Price</th>
                                    <th>Discount</th>
                                    <th>Subtotal</th>
                                    <th>Customer</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
            `;
            
            // Add items
            data.items.forEach(item => {
                const isReturned = item.return_status === 'returned';
                const rowClass = isReturned ? 'class="returned"' : '';
                
                html += `
                    <tr ${rowClass}>
                        <td>
                            <div class="customer-info">
                                <div class="customer-name">${item.product_name}</div>
                                ${item.product_id ? `<small>ID: ${item.product_id}</small>` : ''}
                            </div>
                        </td>
                        <td>${item.product_color || 'N/A'}</td>
                        <td style="font-weight: 700;">${item.quantity}</td>
                        <td class="amount-cell">Rs${parseFloat(item.price).toFixed(2)}</td>
                        <td class="amount-cell">
                            Rs${parseFloat(item.discount).toFixed(2)}
                            ${item.discount_type === 'percentage' ? `<br><small>(${item.discount_input}%)</small>` : ''}
                        </td>
                        <td class="amount-cell">Rs${parseFloat(item.subtotal).toFixed(2)}</td>
                        <td>${item.imei || 'N/A'}</td>
                        <td>
                            ${isReturned ? 
                                `<span style="color: var(--coral); display: flex; align-items: center; gap: 8px;">
                                    <i class="fas fa-undo"></i> Returned
                                    ${item.return_date ? `<br><small>${item.return_date}</small>` : ''}
                                </span>` : 
                                '<span style="color: var(--mint); display: flex; align-items: center; gap: 8px;"><i class="fas fa-check"></i> Sold</span>'
                            }
                        </td>
                    </tr>
                `;
            });
            
            html += `
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="bill-summary-grid">
                    <div class="summary-card">
                        <div class="summary-label">
                            <i class="fas fa-calculator"></i> Subtotal
                        </div>
                        <div class="summary-value">Rs${parseFloat(data.bill.subtotal).toFixed(2)}</div>
                    </div>
                    
                    <div class="summary-card">
                        <div class="summary-label">
                            <i class="fas fa-tag"></i> Total Discount
                        </div>
                        <div class="summary-value">Rs${parseFloat(data.bill.total_discount).toFixed(2)}</div>
                    </div>
                    
                    <div class="summary-card">
                        <div class="summary-label">
                            <i class="fas fa-file-invoice-dollar"></i> Total Amount
                        </div>
                        <div class="summary-value" style="color: var(--mint);">Rs${parseFloat(data.bill.total).toFixed(2)}</div>
                    </div>
                    
                    <div class="summary-card">
                        <div class="summary-label">
                            <i class="fas fa-money-bill-wave"></i> Paid Amount
                        </div>
                        <div class="summary-value" style="color: var(--mint);">Rs${parseFloat(data.bill.paid_amount).toFixed(2)}</div>
                    </div>
                    
                    <div class="summary-card">
                        <div class="summary-label">
                            <i class="fas fa-balance-scale"></i> Balance
                        </div>
                        <div class="summary-value" style="color: ${data.bill.balance < 0 ? 'var(--coral)' : 'var(--mint)'};">
                            Rs${parseFloat(data.bill.balance).toFixed(2)}
                        </div>
                    </div>
                </div>
                
                <div style="display: flex; gap: 20px; margin-top: 40px; justify-content: center;">
                    <button class="filter-btn" onclick="printBill('${data.bill.bill_no}')" style="padding: 16px 32px;">
                        <i class="fas fa-print"></i> Print Bill
                    </button>
                    <button class="export-btn" onclick="closeModal()" style="padding: 16px 32px;">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
            `;
            
            content.innerHTML = html;
            modal.style.display = 'block';
            
            // Add animation
            const modalContainer = document.getElementById('modalContainer');
            modalContainer.style.animation = 'none';
            setTimeout(() => {
                modalContainer.style.animation = 'slideUp 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275)';
            }, 10);
        }
        
        // Print bill
        function printBill(billNo) {
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
        
        // Return to sale page
        function returnToSalePage(billNo) {
            window.location.href = 'return_sale1.php?search_bill=' + billNo;
        }
        
        // Export to CSV
        function exportToCSV() {
            showLoading();
            
            const params = new URLSearchParams(window.location.search);
            fetch('export_bills.php?' + params.toString())
                .then(response => response.blob())
                .then(blob => {
                    hideLoading();
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `bills_export_${new Date().toISOString().split('T')[0]}.csv`;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    window.URL.revokeObjectURL(url);
                })
                .catch(error => {
                    hideLoading();
                    console.error('Error:', error);
                    alert('An error occurred while exporting data.');
                });
        }
        
        // Print report
        function printReport() {
            window.print();
        }
        
        // Close modal
        function closeModal() {
            const modal = document.getElementById('billModal');
            const modalContainer = document.getElementById('modalContainer');
            
            modalContainer.style.animation = 'slideUp 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275) reverse';
            
            setTimeout(() => {
                modal.style.display = 'none';
                modalContainer.style.animation = '';
            }, 300);
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('billModal');
            if (event.target == modal) {
                closeModal();
            }
        }
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal();
            }
        });
        
        // Add hover effects to quick date buttons
        document.querySelectorAll('.quick-date-btn').forEach(btn => {
            btn.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-3px) scale(1.05)';
            });
            
            btn.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
            });
        });
        
        // Add ripple effect to buttons
        document.querySelectorAll('.filter-btn, .export-btn, .action-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                const ripple = document.createElement('span');
                const rect = this.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left - size / 2;
                const y = e.clientY - rect.top - size / 2;
                
                ripple.style.cssText = `
                    position: absolute;
                    border-radius: 50%;
                    background: rgba(255, 255, 255, 0.3);
                    transform: scale(0);
                    animation: ripple 0.6s linear;
                    width: ${size}px;
                    height: ${size}px;
                    top: ${y}px;
                    left: ${x}px;
                `;
                
                this.style.position = 'relative';
                this.style.overflow = 'hidden';
                this.appendChild(ripple);
                
                setTimeout(() => {
                    ripple.remove();
                }, 600);
            });
        });
        
        // Add CSS for ripple animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes ripple {
                to {
                    transform: scale(4);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
<?php $conn->close(); ?>
[file content end]