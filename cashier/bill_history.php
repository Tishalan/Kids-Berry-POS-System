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
    <title>KIDS Berry - Bill History & Details</title>
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
            background: var(--gradient-info);
            box-shadow: 0 6px 12px rgba(23, 162, 184, 0.3);
        }

        .nav a:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 15px rgba(0,0,0,0.2);
        }

        .main-container {
            padding: 0 30px;
            max-width: 1800px;
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
            margin-bottom: 30px;
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

        /* Filter Section */
        .filter-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            margin-bottom: 10px;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px;
        }

        .filter-group label i {
            color: var(--primary);
        }

        .filter-group input,
        .filter-group select {
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 15px;
            transition: var(--transition);
            background: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            font-weight: 500;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.15);
            outline: none;
            transform: translateY(-2px);
        }

        .btn {
            padding: 14px 25px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 700;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 16px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
            height: 50px;
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

        .btn-primary {
            background: var(--gradient-secondary);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(40, 167, 69, 0.3);
        }

        .btn-info {
            background: var(--gradient-info);
            color: white;
        }

        .btn-info:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(23, 162, 184, 0.3);
        }

        .btn-warning {
            background: var(--warning);
            color: var(--dark);
        }

        .btn-warning:hover {
            background: #e0a800;
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(255, 193, 7, 0.3);
        }

        /* Stats Summary */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: var(--radius);
            box-shadow: var(--card-shadow);
            text-align: center;
            transition: var(--transition);
            border: 1px solid rgba(0,0,0,0.05);
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 25px rgba(0,0,0,0.15);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
        }

        .stat-card.total-bills {
            border-left: 5px solid var(--primary);
        }

        .stat-card.total-bills .value {
            color: var(--primary);
        }

        .stat-card.total-sales {
            border-left: 5px solid var(--success);
        }

        .stat-card.total-sales .value {
            color: var(--success);
        }

        .stat-card.total-discounts {
            border-left: 5px solid var(--warning);
        }

        .stat-card.total-discounts .value {
            color: var(--warning);
        }

        .stat-card.total-returns {
            border-left: 5px solid var(--accent);
        }

        .stat-card.total-returns .value {
            color: var(--accent);
        }

        .stat-card h3 {
            font-size: 14px;
            color: var(--dark);
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 700;
        }

        .stat-card .value {
            font-size: 28px;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 8px;
        }

        /* Table Container */
        .table-container {
            overflow-x: auto;
            border-radius: var(--radius);
            box-shadow: var(--card-shadow);
            margin-bottom: 25px;
            border: 1px solid rgba(0,0,0,0.05);
        }

        .bills-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        .bills-table th {
            background: var(--gradient-primary);
            color: white;
            padding: 18px;
            text-align: left;
            font-weight: 700;
            position: sticky;
            top: 0;
            font-size: 15px;
            white-space: nowrap;
        }

        .bills-table tr {
            transition: var(--transition);
            animation: fadeIn 0.5s ease;
        }

        .bills-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        .bills-table tr:hover {
            background-color: #e8f4fc;
            transform: translateX(5px);
        }

        .bills-table td {
            padding: 16px;
            border-bottom: 1px solid #eee;
            font-size: 14px;
            font-weight: 500;
        }

        .bill-no {
            font-weight: 700;
            color: var(--primary);
        }

        .returned-badge {
            background: var(--accent);
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            margin-top: 5px;
        }

        .payment-method {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            display: inline-block;
        }

        .payment-cash {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success);
            border: 1px solid var(--success);
        }

        .payment-card {
            background: rgba(0, 123, 255, 0.1);
            color: #007bff;
            border: 1px solid #007bff;
        }

        .payment-credit {
            background: rgba(220, 53, 69, 0.1);
            color: var(--accent);
            border: 1px solid var(--accent);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .view-btn {
            background: var(--gradient-info);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
            font-size: 13px;
        }

        .view-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(23, 162, 184, 0.3);
        }

        .print-btn {
            background: var(--gradient-secondary);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
            font-size: 13px;
        }

        .print-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
        }

        .return-btn {
            background: var(--gradient-accent);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
            font-size: 13px;
        }

        .return-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(220, 53, 69, 0.3);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background-color: white;
            margin: 2% auto;
            padding: 30px;
            border-radius: var(--radius);
            width: 90%;
            max-width: 1200px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3);
            animation: slideDown 0.3s ease;
            position: relative;
        }

        .close-modal {
            position: absolute;
            right: 20px;
            top: 15px;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            color: var(--primary);
            transition: var(--transition);
        }

        .close-modal:hover {
            color: var(--accent);
            transform: scale(1.2);
        }

        .modal-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light);
        }

        .modal-header h3 {
            color: var(--primary);
            font-size: 24px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .bill-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .bill-detail-item {
            background: #f8f9fa;
            padding: 18px;
            border-radius: 8px;
            border-left: 4px solid var(--primary);
        }

        .bill-detail-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .bill-detail-value {
            font-size: 16px;
            font-weight: 700;
            color: var(--dark);
        }

        .bill-items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: white;
        }

        .bill-items-table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 700;
            color: var(--dark);
            border-bottom: 2px solid #dee2e6;
        }

        .bill-items-table td {
            padding: 15px;
            border-bottom: 1px solid #eee;
        }

        .bill-items-table tr.returned {
            background-color: #fff5f5;
            text-decoration: line-through;
            color: #dc3545;
        }

        .return-info {
            background: #fff3cd;
            padding: 10px;
            border-radius: 6px;
            margin-top: 5px;
            font-size: 12px;
            color: #856404;
        }

        /* Date Navigation */
        .date-navigation {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 20px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .date-btn {
            background: var(--gradient-primary);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
        }

        .date-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(90, 61, 126, 0.3);
        }

        .current-date {
            font-size: 18px;
            font-weight: 700;
            color: var(--primary);
            background: white;
            padding: 12px 25px;
            border-radius: 8px;
            box-shadow: 0 3px 6px rgba(0,0,0,0.1);
        }

        .no-records {
            text-align: center;
            padding: 60px 20px;
            color: #7f8c8d;
        }

        .no-records i {
            font-size: 70px;
            margin-bottom: 20px;
            color: #dee2e6;
        }

        .no-records h3 {
            font-size: 22px;
            margin-bottom: 10px;
            color: var(--dark);
            font-weight: 700;
        }

        .no-records p {
            font-size: 16px;
            max-width: 400px;
            margin: 0 auto;
            color: #6c757d;
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
            from { transform: translateY(40px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .filter-container {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
            
            .stats-container {
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            }
        }

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
            
            .filter-container {
                grid-template-columns: 1fr;
            }
            
            .date-navigation {
                flex-direction: column;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .modal-content {
                width: 95%;
                padding: 20px;
            }
        }

        @media (max-width: 480px) {
            .header h1 {
                font-size: 24px;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .bill-details-grid {
                grid-template-columns: 1fr;
            }
            
            .bills-table {
                font-size: 12px;
            }
            
            .bills-table th,
            .bills-table td {
                padding: 10px;
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
            <h1><i class="fas fa-baby"></i> KIDS Berry - Bill History & Details</h1>
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
        <a href="report.php"><i class="fas fa-chart-line"></i> Reports</a>
        <a href="bill_history.php" class="active"><i class="fas fa-history"></i> Bill History</a>
        <a href="credit_payments.php"><i class="fas fa-credit-card"></i> Credit Customers</a>
        <a href="return_sale.php"><i class="fas fa-undo-alt"></i> Manage Returns</a>
    </div>
    
    <div class="main-container">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-receipt"></i> Bill History & Details</h2>
            </div>
            
            <!-- Date Navigation -->
            <div class="date-navigation">
                <button class="date-btn" onclick="changeDate(-7)">
                    <i class="fas fa-arrow-left"></i> Previous Week
                </button>
                
                <div class="current-date">
                    <?php 
                    if (!empty($selected_date) && $selected_date == date('Y-m-d')) {
                        echo "Today's Bills";
                    } else if (!empty($start_date) && !empty($end_date)) {
                        echo "Bills from " . date('M d, Y', strtotime($start_date)) . " to " . date('M d, Y', strtotime($end_date));
                    }
                    ?>
                </div>
                
                <button class="date-btn" onclick="changeDate(7)">
                    Next Week <i class="fas fa-arrow-right"></i>
                </button>
                
                <button class="btn btn-info" onclick="showToday()">
                    <i class="fas fa-calendar-day"></i> Today
                </button>
            </div>
            
            <!-- Filter Form -->
            <form method="get" action="" class="filter-container">
                <div class="filter-group">
                    <label><i class="fas fa-calendar-alt"></i> Start Date</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                </div>
                
                <div class="filter-group">
                    <label><i class="fas fa-calendar-alt"></i> End Date</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                </div>
                
                <div class="filter-group">
                    <label><i class="fas fa-search"></i> Search Bill No</label>
                    <input type="text" name="search_bill" value="<?php echo htmlspecialchars($search_bill_no); ?>" placeholder="Enter bill number">
                </div>
                
                <div class="filter-group">
                    <label><i class="fas fa-user-tie"></i> Sales Officer</label>
                    <select name="cashier_id">
                        <option value="0">All Sales Officers</option>
                        <?php 
                        $cashiers_result = $conn->query($cashiers_sql);
                        while ($cashier = $cashiers_result->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $cashier['cashier_id']; ?>" <?php echo $selected_cashier == $cashier['cashier_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cashier['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Filter Bills
                    </button>
                </div>
            </form>
            
            <?php if ($bills_result->num_rows > 0): 
                // Calculate totals while displaying
                $total_bills = 0;
                $total_sales = 0;
                $total_discounts = 0;
                $total_paid = 0;
                $total_balance = 0;
                $total_returned = 0;
            ?>
                <!-- Stats Summary -->
                <div class="stats-container">
                    <div class="stat-card total-bills">
                        <h3>Total Bills</h3>
                        <div class="value"><?php echo $bills_result->num_rows; ?></div>
                    </div>
                    
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
                    
                    <div class="stat-card total-sales">
                        <h3>Total Sales</h3>
                        <div class="value">Rs<?php echo number_format($total_sales, 2); ?></div>
                    </div>
                    
                    <div class="stat-card total-discounts">
                        <h3>Total Discounts</h3>
                        <div class="value">Rs<?php echo number_format($total_discounts, 2); ?></div>
                    </div>
                    
                    <div class="stat-card total-returns">
                        <h3>Total Returns</h3>
                        <div class="value">Rs<?php echo number_format($total_returned, 2); ?></div>
                    </div>
                </div>
                
                <!-- Bills Table -->
                <div class="table-container">
                    <table class="bills-table">
                        <thead>
                            <tr>
                                <th>Bill No</th>
                                <th>Date & Time</th>
                                <th>Customer</th>
                                <th>Cashier</th>
                                <th>Payment Method</th>
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
                            ?>
                            <tr>
                                <td>
                                    <div class="bill-no"><?php echo $bill['bill_no']; ?></div>
                                    <?php if($bill['returned_quantity'] > 0): ?>
                                        <div class="returned-badge">
                                            <i class="fas fa-undo"></i> <?php echo $bill['returned_quantity']; ?> items returned
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo date('Y-m-d', strtotime($bill['date'])); ?><br>
                                    <small><?php echo date('h:i A', strtotime($bill['date'])); ?></small>
                                </td>
                                <td>
                                    <?php if(!empty($bill['customer_name'])): ?>
                                        <strong><?php echo htmlspecialchars($bill['customer_name']); ?></strong><br>
                                        <?php if($bill['phone_no']): ?>
                                            <small><?php echo $bill['phone_no']; ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        Walk-in Customer
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($bill['cashier_name']); ?></td>
                                <td>
                                    <span class="payment-method <?php echo $payment_class; ?>">
                                        <?php echo $bill['payment_method']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo $bill['item_count']; ?> items<br>
                                    <small><?php echo $bill['total_quantity']; ?> qty</small>
                                </td>
                                <td>Rs<?php echo number_format($bill['subtotal'], 2); ?></td>
                                <td>Rs<?php echo number_format($bill['total_discount'], 2); ?></td>
                                <td>
                                    <strong>Rs<?php echo number_format($bill['total'], 2); ?></strong>
                                </td>
                                <td>Rs<?php echo number_format($bill['paid_amount'], 2); ?></td>
                                <td>
                                    <span style="color: <?php echo $bill['balance'] < 0 ? 'var(--accent)' : 'var(--success)'; ?>;">
                                        Rs<?php echo number_format($bill['balance'], 2); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if($bill['returned_quantity'] > 0): ?>
                                        <span style="color: var(--accent);">
                                            Rs<?php echo number_format($bill['returned_amount'], 2); ?><br>
                                            <small><?php echo $bill['returned_quantity']; ?> items</small>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: var(--success);">None</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button type="button" class="view-btn" onclick="viewBillDetails('<?php echo $bill['bill_no']; ?>')">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <button type="button" class="print-btn" onclick="printBill('<?php echo $bill['bill_no']; ?>')">
                                            <i class="fas fa-print"></i> Print
                                        </button>
                                        <?php if($bill['returned_quantity'] == 0): ?>
                                        <button type="button" class="return-btn" onclick="returnToSalePage('<?php echo $bill['bill_no']; ?>')">
                                            <i class="fas fa-undo"></i> Return
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="no-records">
                    <i class="fas fa-receipt"></i>
                    <h3>No bills found</h3>
                    <p>Try selecting a different date range or search criteria</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Bill Details Modal -->
    <div id="billModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal()">&times;</span>
            <div class="modal-header">
                <h3><i class="fas fa-receipt"></i> Bill Details - <span id="modal-bill-no"></span></h3>
            </div>
            
            <div id="billModalContent">
                <!-- Dynamic content will be loaded here -->
            </div>
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
        
        // View bill details
        function viewBillDetails(billNo) {
            fetch('get_details.php?bill_no=' + billNo)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayBillDetails(data);
                    } else {
                        alert('Error loading bill details: ' + data.message);
                    }
                })
                .catch(error => {
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
                <div class="bill-details-grid">
                    <div class="bill-detail-item">
                        <div class="bill-detail-label">Bill Number</div>
                        <div class="bill-detail-value">${data.bill.bill_no}</div>
                    </div>
                    <div class="bill-detail-item">
                        <div class="bill-detail-label">Date & Time</div>
                        <div class="bill-detail-value">${data.bill.date}</div>
                    </div>
                    <div class="bill-detail-item">
                        <div class="bill-detail-label">Cashier</div>
                        <div class="bill-detail-value">${data.bill.cashier_name}</div>
                    </div>
                    <div class="bill-detail-item">
                        <div class="bill-detail-label">Payment Method</div>
                        <div class="bill-detail-value">${data.bill.payment_method}</div>
                    </div>
                </div>
                
                <div class="bill-details-grid">
                    <div class="bill-detail-item">
                        <div class="bill-detail-label">Customer Name</div>
                        <div class="bill-detail-value">${data.bill.customer_name || 'Walk-in Customer'}</div>
                    </div>
                    <div class="bill-detail-item">
                        <div class="bill-detail-label">Phone Number</div>
                        <div class="bill-detail-value">${data.bill.phone_no || 'N/A'}</div>
                    </div>
                    <div class="bill-detail-item">
                        <div class="bill-detail-label">NIC Number</div>
                        <div class="bill-detail-value">${data.bill.nic_no || 'N/A'}</div>
                    </div>
                    <div class="bill-detail-item">
                        <div class="bill-detail-label">Customer ID</div>
                        <div class="bill-detail-value">${data.bill.customer_id || 'N/A'}</div>
                    </div>
                </div>
                
                <div style="margin: 30px 0;">
                    <h4 style="color: var(--primary); margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-boxes"></i> Products Purchased
                    </h4>
                    
                    <div class="table-container">
                        <table class="bill-items-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Color</th>
                                    <th>Quantity</th>
                                    <th>Unit Price</th>
                                    <th>Discount</th>
                                    <th>Subtotal</th>
                                    <th>Customer Name</th>
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
                            ${item.product_name}
                            ${item.product_id ? `<br><small>ID: ${item.product_id}</small>` : ''}
                        </td>
                        <td>${item.product_color || 'N/A'}</td>
                        <td>${item.quantity}</td>
                        <td>Rs${parseFloat(item.price).toFixed(2)}</td>
                        <td>
                            Rs${parseFloat(item.discount).toFixed(2)}
                            ${item.discount_type === 'percentage' ? `<br><small>(${item.discount_input}%)</small>` : ''}
                        </td>
                        <td>Rs${parseFloat(item.subtotal).toFixed(2)}</td>
                        <td>${item.imei || 'N/A'}</td>
                        <td>
                            ${isReturned ? 
                                `<span style="color: var(--accent);">
                                    <i class="fas fa-undo"></i> Returned
                                    ${item.return_date ? `<br><small>${item.return_date}</small>` : ''}
                                    ${item.return_reason ? `<div class="return-info">${item.return_reason}</div>` : ''}
                                </span>` : 
                                '<span style="color: var(--success);"><i class="fas fa-check"></i> Sold</span>'
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
                
                <div class="bill-details-grid">
                    <div class="bill-detail-item">
                        <div class="bill-detail-label">Subtotal</div>
                        <div class="bill-detail-value">Rs${parseFloat(data.bill.subtotal).toFixed(2)}</div>
                    </div>
                    <div class="bill-detail-item">
                        <div class="bill-detail-label">Total Discount</div>
                        <div class="bill-detail-value">Rs${parseFloat(data.bill.total_discount).toFixed(2)}</div>
                    </div>
                    <div class="bill-detail-item">
                        <div class="bill-detail-label">Total Amount</div>
                        <div class="bill-detail-value" style="color: var(--success); font-size: 20px;">
                            Rs${parseFloat(data.bill.total).toFixed(2)}
                        </div>
                    </div>
                    <div class="bill-detail-item">
                        <div class="bill-detail-label">Paid Amount</div>
                        <div class="bill-detail-value">Rs${parseFloat(data.bill.paid_amount).toFixed(2)}</div>
                    </div>
                    <div class="bill-detail-item">
                        <div class="bill-detail-label">Balance</div>
                        <div class="bill-detail-value" style="color: ${data.bill.balance < 0 ? 'var(--accent)' : 'var(--success)'};">
                            Rs${parseFloat(data.bill.balance).toFixed(2)}
                        </div>
                    </div>
                </div>
                
                <div style="text-align: center; margin-top: 30px;">
                    <button class="btn btn-primary" onclick="printBill('${data.bill.bill_no}')">
                        <i class="fas fa-print"></i> Print Bill
                    </button>
                    <button class="btn btn-warning" onclick="closeModal()">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
            `;
            
            content.innerHTML = html;
            modal.style.display = 'block';
        }
        
        // Print bill
        function printBill(billNo) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'print_bill.php';
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
            window.location.href = 'return_sale.php?search_bill=' + billNo;
        }
        
        // Date navigation
        function changeDate(days) {
            const url = new URL(window.location.href);
            const currentStart = new Date(url.searchParams.get('start_date') || '<?php echo $start_date; ?>');
            const currentEnd = new Date(url.searchParams.get('end_date') || '<?php echo $end_date; ?>');
            
            currentStart.setDate(currentStart.getDate() + days);
            currentEnd.setDate(currentEnd.getDate() + days);
            
            url.searchParams.set('start_date', currentStart.toISOString().split('T')[0]);
            url.searchParams.set('end_date', currentEnd.toISOString().split('T')[0]);
            
            window.location.href = url.toString();
        }
        
        function showToday() {
            const today = new Date().toISOString().split('T')[0];
            window.location.href = 'bill_history.php?date=' + today;
        }
        
        // Close modal
        function closeModal() {
            document.getElementById('billModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('billModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>
<?php $conn->close(); ?>
[file content end]