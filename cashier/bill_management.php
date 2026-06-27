
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

$branch_id = 1; // Hardcoded for now, update to $_SESSION['branch_id'] later

// Get all cashiers
$cashiers_sql = "SELECT cashier_id, name FROM cashier_users ORDER BY name";
$cashiers_result = $conn->query($cashiers_sql);

// Initialize variables
$date_filter = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$bill_no = isset($_GET['bill_no']) ? $_GET['bill_no'] : '';
$cashier_id = isset($_GET['cashier_id']) ? $_GET['cashier_id'] : '';
$payment_method = isset($_GET['payment_method']) ? $_GET['payment_method'] : '';

// Build query for bills
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
                GROUP_CONCAT(DISTINCT bi.product_name ORDER BY bi.product_name SEPARATOR ', ') as products
              FROM bills b
              JOIN cashier_users c ON b.cashier_id = c.cashier_id
              LEFT JOIN bill_items bi ON b.bill_no = bi.bill_no
              WHERE b.branch_id = $branch_id";

// Add filters
if (!empty($date_filter)) {
    $bills_sql .= " AND DATE(b.date) = '$date_filter'";
}
if (!empty($bill_no)) {
    $bills_sql .= " AND b.bill_no LIKE '%$bill_no%'";
}
if (!empty($cashier_id)) {
    $bills_sql .= " AND b.cashier_id = '$cashier_id'";
}
if (!empty($payment_method)) {
    $bills_sql .= " AND b.payment_method = '$payment_method'";
}

$bills_sql .= " GROUP BY b.bill_no
                ORDER BY b.date DESC
                LIMIT 100";

$bills_result = $conn->query($bills_sql);

// Get today's statistics
$today = date('Y-m-d');
$stats_sql = "SELECT 
                COUNT(DISTINCT bill_no) as bill_count,
                SUM(total) as total_sales,
                SUM(total_discount) as total_discount,
                SUM(paid_amount) as total_paid,
                SUM(balance) as total_balance,
                SUM(CASE WHEN payment_method = 'Cash' THEN total ELSE 0 END) as cash_sales,
                SUM(CASE WHEN payment_method = 'Card' THEN total ELSE 0 END) as card_sales,
                SUM(CASE WHEN payment_method = 'Credit' THEN total ELSE 0 END) as credit_sales
              FROM bills 
              WHERE branch_id = $branch_id 
              AND DATE(date) = '$today'";
$stats_result = $conn->query($stats_sql);
$today_stats = $stats_result->fetch_assoc();

// Get returns statistics for today
$returns_sql = "SELECT 
                COUNT(*) as return_count,
                SUM(return_amount) as return_amount
                FROM returns_tracking 
                WHERE DATE(return_date) = '$today'";
$returns_result = $conn->query($returns_sql);
$today_returns = $returns_result->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIDS Berry - Bill Management</title>
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
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .section {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--card-shadow);
            margin: 0 auto 30px;
            max-width: 1600px;
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

        /* Stats Cards */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 22px;
            border-radius: var(--radius);
            box-shadow: var(--card-shadow);
            text-align: center;
            transition: var(--transition);
            border: 1px solid rgba(0,0,0,0.05);
            position: relative;
            overflow: hidden;
            border-left: 5px solid var(--primary);
        }

        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 25px rgba(0,0,0,0.15);
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
            font-size: 26px;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 8px;
        }

        .stat-card .sub-value {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
            font-weight: 600;
        }

        /* Filter Form */
        .filter-form {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 25px;
            border-radius: var(--radius);
            margin-bottom: 25px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            animation: fadeInUp 0.8s ease;
            border: 1px solid rgba(0,0,0,0.05);
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 10px;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px;
        }

        .form-group label i {
            color: var(--primary);
        }

        .form-group input,
        .form-group select {
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 15px;
            transition: var(--transition);
            background: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            font-weight: 500;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.15);
            outline: none;
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

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #4a2d6b 0%, #6a4d8f 100%);
            transform: translateY(-3px);
            box-shadow: 0 8px 15px rgba(90, 61, 126, 0.3);
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

        .btn-warning {
            background: var(--gradient-warning);
            color: var(--dark);
        }

        .btn-warning:hover {
            background: linear-gradient(135deg, #e0a800 0%, #ffc107 100%);
            transform: translateY(-3px);
            box-shadow: 0 8px 15px rgba(255, 193, 7, 0.3);
        }

        .btn-danger {
            background: var(--gradient-accent);
            color: white;
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #c82333 0%, #dc3545 100%);
            transform: translateY(-3px);
            box-shadow: 0 8px 15px rgba(220, 53, 69, 0.3);
        }

        /* Table Styles */
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
            white-space: nowrap;
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
            font-weight: 500;
        }

        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .badge-cash {
            background: #d4edda;
            color: #155724;
        }

        .badge-card {
            background: #d1ecf1;
            color: #0c5460;
        }

        .badge-credit {
            background: #f8d7da;
            color: #721c24;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
        }

        .action-btn.view {
            background: var(--gradient-info);
            color: white;
        }

        .action-btn.print {
            background: var(--gradient-secondary);
            color: white;
        }

        .action-btn.return {
            background: var(--gradient-warning);
            color: var(--dark);
        }

        .action-btn.returns {
            background: var(--gradient-accent);
            color: white;
        }

        .action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
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
            margin: 5% auto;
            padding: 30px;
            border-radius: var(--radius);
            width: 90%;
            max-width: 1000px;
            max-height: 85vh;
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

        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .detail-item {
            background: #f8f9fa;
            padding: 18px;
            border-radius: 8px;
            border-left: 4px solid var(--primary);
        }

        .detail-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .detail-value {
            font-size: 16px;
            font-weight: 700;
            color: var(--dark);
        }

        .items-table {
            width: 100%;
            margin-top: 20px;
            border-collapse: collapse;
        }

        .items-table th {
            background: var(--gradient-primary);
            color: white;
            padding: 12px;
            text-align: left;
        }

        .items-table td {
            padding: 10px;
            border-bottom: 1px solid #eee;
        }

        .returned-item {
            background-color: #fff3cd !important;
            color: #856404;
        }

        .returned-badge {
            background: #dc3545;
            color: white;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
        }

        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #7f8c8d;
        }

        .no-data i {
            font-size: 70px;
            margin-bottom: 20px;
            color: #dee2e6;
        }

        .no-data h3 {
            font-size: 22px;
            margin-bottom: 10px;
            color: var(--dark);
            font-weight: 700;
        }

        .no-data p {
            font-size: 16px;
            max-width: 400px;
            margin: 0 auto;
            color: #6c757d;
        }

        /* Returns Form in Modal */
        .return-form {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
        }

        .return-form .form-group {
            margin-bottom: 15px;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 20px;
                text-align: center;
                padding: 20px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            table {
                font-size: 13px;
            }
            
            table th,
            table td {
                padding: 12px 8px;
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
            
            .section-header h2 {
                font-size: 20px;
            }
            
            .stat-card .value {
                font-size: 22px;
            }
            
            .btn {
                padding: 12px 20px;
                font-size: 14px;
            }
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
            from { transform: translateX(-30px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes shimmer {
            100% { transform: translateX(100%); }
        }
    </style>
</head>
<body>
    <div class="header">
        <a href="report.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Reports
        </a>
        <div class="header-content">
            <h1><i class="fas fa-file-invoice"></i> Bill Management</h1>
        </div>
    </div>
    
    <div class="section">
        <div class="section-header">
            <h2><i class="fas fa-chart-bar"></i> Today's Overview - <?php echo date('F j, Y'); ?></h2>
        </div>
        
        <div class="section-content">
            <div class="stats-container">
                <div class="stat-card">
                    <h3>Total Bills Today</h3>
                    <div class="value"><?php echo $today_stats['bill_count'] ?? 0; ?></div>
                </div>
                
                <div class="stat-card">
                    <h3>Today's Sales</h3>
                    <div class="value">Rs<?php echo number_format($today_stats['total_sales'] ?? 0, 2); ?></div>
                </div>
                
                <div class="stat-card">
                    <h3>Today's Returns</h3>
                    <div class="value">Rs<?php echo number_format($today_returns['return_amount'] ?? 0, 2); ?></div>
                    <div class="sub-value"><?php echo $today_returns['return_count'] ?? 0; ?> items</div>
                </div>
                
                <div class="stat-card">
                    <h3>Net Sales Today</h3>
                    <div class="value">Rs<?php 
                        $net_sales = ($today_stats['total_sales'] ?? 0) - ($today_returns['return_amount'] ?? 0);
                        echo number_format($net_sales, 2);
                    ?></div>
                    <div class="sub-value">(Sales - Returns)</div>
                </div>
                
                <div class="stat-card">
                    <h3>Cash Sales</h3>
                    <div class="value">Rs<?php echo number_format($today_stats['cash_sales'] ?? 0, 2); ?></div>
                </div>
                
                <div class="stat-card">
                    <h3>Card Sales</h3>
                    <div class="value">Rs<?php echo number_format($today_stats['card_sales'] ?? 0, 2); ?></div>
                </div>
                
                <div class="stat-card">
                    <h3>Credit Sales</h3>
                    <div class="value">Rs<?php echo number_format($today_stats['credit_sales'] ?? 0, 2); ?></div>
                </div>
                
                <div class="stat-card">
                    <h3>Total Discount</h3>
                    <div class="value">Rs<?php echo number_format($today_stats['total_discount'] ?? 0, 2); ?></div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="section">
        <div class="section-header">
            <h2><i class="fas fa-search"></i> Search Bills</h2>
        </div>
        
        <div class="section-content">
            <form method="get" action="" class="filter-form">
                <div class="form-row">
                    <div class="form-group">
                        <label for="date"><i class="fas fa-calendar"></i> Date</label>
                        <input type="date" id="date" name="date" value="<?php echo htmlspecialchars($date_filter); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="bill_no"><i class="fas fa-receipt"></i> Bill Number</label>
                        <input type="text" id="bill_no" name="bill_no" placeholder="Enter bill number" value="<?php echo htmlspecialchars($bill_no); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="cashier_id"><i class="fas fa-user-tie"></i> Sales Officer</label>
                        <select id="cashier_id" name="cashier_id">
                            <option value="">All Sales Officers</option>
                            <?php 
                            while ($cashier = $cashiers_result->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $cashier['cashier_id']; ?>" <?php echo $cashier_id == $cashier['cashier_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cashier['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="payment_method"><i class="fas fa-credit-card"></i> Payment Method</label>
                        <select id="payment_method" name="payment_method">
                            <option value="">All Methods</option>
                            <option value="Cash" <?php echo $payment_method == 'Cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="Card" <?php echo $payment_method == 'Card' ? 'selected' : ''; ?>>Card</option>
                            <option value="Credit" <?php echo $payment_method == 'Credit' ? 'selected' : ''; ?>>Credit</option>
                        </select>
                    </div>
                </div>
                
                <div style="display: flex; gap: 15px;">
                    <button type="submit" class="btn btn-info">
                        <i class="fas fa-search"></i> Search Bills
                    </button>
                    <button type="button" class="btn btn-warning" onclick="window.location.href='bill_management.php'">
                        <i class="fas fa-redo"></i> Reset Filters
                    </button>
                </div>
            </form>
            
            <div class="table-container">
                <?php if ($bills_result->num_rows > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Bill No</th>
                                <th>Date & Time</th>
                                <th>Customer</th>
                                <th>Sales Officer</th>
                                <th>Items</th>
                                <th>Payment Method</th>
                                <th>Subtotal</th>
                                <th>Discount</th>
                                <th>Total</th>
                                <th>Paid</th>
                                <th>Balance</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($bill = $bills_result->fetch_assoc()): 
                                $payment_class = '';
                                if ($bill['payment_method'] == 'Cash') $payment_class = 'badge-cash';
                                elseif ($bill['payment_method'] == 'Card') $payment_class = 'badge-card';
                                else $payment_class = 'badge-credit';
                            ?>
                            <tr>
                                <td><strong><?php echo $bill['bill_no']; ?></strong></td>
                                <td>
                                    <?php echo date('Y-m-d', strtotime($bill['date'])); ?><br>
                                    <small style="color: #666;"><?php echo date('H:i:s', strtotime($bill['date'])); ?></small>
                                </td>
                                <td>
                                    <?php if (!empty($bill['customer_name'])): ?>
                                        <?php echo htmlspecialchars($bill['customer_name']); ?><br>
                                        <?php if (!empty($bill['phone_no'])): ?>
                                            <small style="color: #666;"><?php echo htmlspecialchars($bill['phone_no']); ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #999;">Walk-in Customer</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($bill['cashier_name']); ?></td>
                                <td>
                                    <?php echo $bill['item_count']; ?> items<br>
                                    <small style="color: #666;">Qty: <?php echo $bill['total_quantity']; ?></small>
                                </td>
                                <td>
                                    <span class="badge <?php echo $payment_class; ?>">
                                        <?php echo $bill['payment_method']; ?>
                                    </span>
                                </td>
                                <td>Rs<?php echo number_format($bill['subtotal'], 2); ?></td>
                                <td>Rs<?php echo number_format($bill['total_discount'], 2); ?></td>
                                <td><strong>Rs<?php echo number_format($bill['total'], 2); ?></strong></td>
                                <td>Rs<?php echo number_format($bill['paid_amount'], 2); ?></td>
                                <td>
                                    <?php if ($bill['balance'] > 0): ?>
                                        <span style="color: var(--accent); font-weight: bold;">
                                            Rs<?php echo number_format($bill['balance'], 2); ?>
                                        </span>
                                    <?php elseif ($bill['balance'] < 0): ?>
                                        <span style="color: var(--warning); font-weight: bold;">
                                            Rs<?php echo number_format(abs($bill['balance']), 2); ?> (Change)
                                        </span>
                                    <?php else: ?>
                                        <span style="color: var(--success); font-weight: bold;">Paid</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <!--<button type="button" class="action-btn view" onclick="viewBill('<?php echo $bill['bill_no']; ?>')">-->
                                        <!--    <i class="fas fa-eye"></i> View-->
                                        <!--</button>-->
                                        <button type="button" class="action-btn print" onclick="printBill('<?php echo $bill['bill_no']; ?>')">
                                            <i class="fas fa-print"></i> Print
                                        </button>
                                        <!--<a href="return_sale.php?bill_no=<?php echo urlencode($bill['bill_no']); ?>" class="action-btn return">-->
                                        <!--    <i class="fas fa-undo"></i> Return-->
                                        <!--</a>-->
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-file-invoice-dollar"></i>
                        <h3>No bills found</h3>
                        <p>Try adjusting your search criteria</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bill Details Modal -->
    <div id="billModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal()">&times;</span>
            <div class="modal-header">
                <h3><i class="fas fa-file-invoice"></i> Bill Details</h3>
            </div>
            
            <div id="modalContent">
                <!-- Dynamic content will be loaded here -->
            </div>
        </div>
    </div>

    <!-- Print Bill Form (Hidden) -->
    <form id="printForm" method="post" action="bill.php" target="_blank" style="display: none;">
        <input type="hidden" name="bill_no" id="printBillNo">
        <input type="hidden" name="cashier_id" id="printCashierId">
        <input type="hidden" name="payment_method" id="printPaymentMethod">
        <input type="hidden" name="paid_amount" id="printPaidAmount">
    </form>

    <script>
        // View bill details
        function viewBill(billNo) {
            fetch('get_details.php?bill_no=' + encodeURIComponent(billNo))
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const bill = data.bill;
                        const items = data.items;
                        
                        // Calculate returns if any
                        const returnedItems = items.filter(item => item.return_status === 'returned');
                        const returnedTotal = returnedItems.reduce((sum, item) => sum + (item.price * item.quantity - item.discount), 0);
                        
                        let html = `
                            <div class="details-grid">
                                <div class="detail-item">
                                    <div class="detail-label">Bill Number</div>
                                    <div class="detail-value">${bill.bill_no}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Date & Time</div>
                                    <div class="detail-value">${bill.date} ${bill.time}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Sales Officer</div>
                                    <div class="detail-value">${bill.cashier_name}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Payment Method</div>
                                    <div class="detail-value">${bill.payment_method}</div>
                                </div>
                            </div>
                            
                            <div class="details-grid">
                                <div class="detail-item">
                                    <div class="detail-label">Customer Name</div>
                                    <div class="detail-value">${bill.customer_name || 'Walk-in Customer'}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Phone Number</div>
                                    <div class="detail-value">${bill.phone_no || 'N/A'}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">NIC Number</div>
                                    <div class="detail-value">${bill.nic_no || 'N/A'}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Total Items</div>
                                    <div class="detail-value">${items.length} items</div>
                                </div>
                            </div>
                            
                            <div style="margin: 20px 0;">
                                <h4 style="color: var(--primary); margin-bottom: 15px;">
                                    <i class="fas fa-boxes"></i> Items in this Bill
                                    ${returnedItems.length > 0 ? `<span class="returned-badge">${returnedItems.length} items returned</span>` : ''}
                                </h4>
                                <div class="table-container">
                                    <table class="items-table">
                                        <thead>
                                            <tr>
                                                <th>Product</th>
                                                <th>IMEI/PSN</th>
                                                <th>Qty</th>
                                                <th>Price</th>
                                                <th>Discount</th>
                                                <th>Total</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                        `;
                        
                        items.forEach(item => {
                            const isReturned = item.return_status === 'returned';
                            const rowClass = isReturned ? 'returned-item' : '';
                            const itemTotal = (item.price * item.quantity) - item.discount;
                            
                            html += `
                                <tr class="${rowClass}">
                                    <td>
                                        <strong>${item.product_name}</strong><br>
                                        <small>${item.product_color || ''}</small>
                                    </td>
                                    <td>${item.imei || item.psn_numbers || 'N/A'}</td>
                                    <td>${item.quantity}</td>
                                    <td>Rs${parseFloat(item.price).toFixed(2)}</td>
                                    <td>
                                        ${item.discount > 0 ? `Rs${parseFloat(item.discount).toFixed(2)}` : '—'}
                                        ${item.discount_type === 'percentage' ? `(${item.discount_input}%)` : ''}
                                    </td>
                                    <td>Rs${itemTotal.toFixed(2)}</td>
                                    <td>
                                        ${isReturned ? 
                                            `<span class="returned-badge">Returned</span><br>
                                             <small>${item.return_reason || ''}</small><br>
                                             <small>${item.return_date ? item.return_date.split(' ')[0] : ''}</small>` 
                                            : '<span style="color: var(--success);">✓ Sold</span>'
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
                            
                            <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin: 20px 0;">
                                <h4 style="color: var(--primary); margin-bottom: 15px;">
                                    <i class="fas fa-calculator"></i> Bill Summary
                                </h4>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                    <div>
                                        <div class="detail-label">Subtotal</div>
                                        <div class="detail-value">Rs${parseFloat(bill.subtotal).toFixed(2)}</div>
                                    </div>
                                    <div>
                                        <div class="detail-label">Total Discount</div>
                                        <div class="detail-value">- Rs${parseFloat(bill.total_discount).toFixed(2)}</div>
                                    </div>
                                    <div>
                                        <div class="detail-label">Total Amount</div>
                                        <div class="detail-value">Rs${parseFloat(bill.total).toFixed(2)}</div>
                                    </div>
                                    <div>
                                        <div class="detail-label">Paid Amount</div>
                                        <div class="detail-value">Rs${parseFloat(bill.paid_amount).toFixed(2)}</div>
                                    </div>
                                    <div>
                                        <div class="detail-label">Balance</div>
                                        <div class="detail-value" style="color: ${bill.balance > 0 ? 'var(--accent)' : (bill.balance < 0 ? 'var(--warning)' : 'var(--success)')}">
                                            ${bill.balance > 0 ? 'Rs' + parseFloat(bill.balance).toFixed(2) : 
                                              bill.balance < 0 ? 'Rs' + Math.abs(parseFloat(bill.balance)).toFixed(2) + ' (Change)' : 'Paid'}
                                        </div>
                                    </div>
                                    ${returnedItems.length > 0 ? `
                                        <div>
                                            <div class="detail-label">Returned Amount</div>
                                            <div class="detail-value" style="color: var(--accent);">
                                                - Rs${returnedTotal.toFixed(2)}
                                            </div>
                                        </div>
                                        <div>
                                            <div class="detail-label">Net Amount</div>
                                            <div class="detail-value" style="color: var(--success); font-size: 18px;">
                                                Rs${(parseFloat(bill.total) - returnedTotal).toFixed(2)}
                                            </div>
                                        </div>
                                    ` : ''}
                                </div>
                            </div>
                            
                            <div style="text-align: center; margin-top: 20px; display: flex; gap: 10px; justify-content: center;">
                                <button class="btn btn-primary" onclick="printBill('${bill.bill_no}')">
                                    <i class="fas fa-print"></i> Print Bill
                                </button>
                                ${returnedItems.length === 0 ? `
                                    <a href="return_sale.php?bill_no=${encodeURIComponent(bill.bill_no)}" class="btn btn-warning">
                                        <i class="fas fa-undo"></i> Process Return
                                    </a>
                                ` : ''}
                                <button class="btn btn-info" onclick="downloadBillDetails('${bill.bill_no}')">
                                    <i class="fas fa-download"></i> Export Details
                                </button>
                                <button class="btn btn-danger" onclick="closeModal()">
                                    <i class="fas fa-times"></i> Close
                                </button>
                            </div>
                        `;
                        
                        document.getElementById('modalContent').innerHTML = html;
                        document.getElementById('billModal').style.display = 'block';
                        
                        // Store bill data for printing
                        document.getElementById('printBillNo').value = bill.bill_no;
                        document.getElementById('printCashierId').value = bill.cashier_id;
                        document.getElementById('printPaymentMethod').value = bill.payment_method;
                        document.getElementById('printPaidAmount').value = bill.paid_amount;
                        
                    } else {
                        alert('Error loading bill details: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading bill details');
                });
        }

        // Print bill
        function printBill(billNo) {
    // Fetch bill data first
    fetch('get_bill_for_print.php?bill_no=' + encodeURIComponent(billNo))
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Create a form and submit to print_bill.php
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'bill.php';
                form.target = '_blank';
                
                // Add bill data
                const billNoInput = document.createElement('input');
                billNoInput.type = 'hidden';
                billNoInput.name = 'bill_no';
                billNoInput.value = data.bill.bill_no;
                form.appendChild(billNoInput);
                
                const cashierIdInput = document.createElement('input');
                cashierIdInput.type = 'hidden';
                cashierIdInput.name = 'cashier_id';
                cashierIdInput.value = data.bill.cashier_id;
                form.appendChild(cashierIdInput);
                
                const paymentMethodInput = document.createElement('input');
                paymentMethodInput.type = 'hidden';
                paymentMethodInput.name = 'payment_method';
                paymentMethodInput.value = data.bill.payment_method;
                form.appendChild(paymentMethodInput);
                
                const paidAmountInput = document.createElement('input');
                paidAmountInput.type = 'hidden';
                paidAmountInput.name = 'paid_amount';
                paidAmountInput.value = data.bill.paid_amount;
                form.appendChild(paidAmountInput);
                
                // Add cart items as JSON
                const cartInput = document.createElement('input');
                cartInput.type = 'hidden';
                cartInput.name = 'cart_items';
                cartInput.value = JSON.stringify(data.items);
                form.appendChild(cartInput);
                
                // Submit the form
                document.body.appendChild(form);
                form.submit();
                document.body.removeChild(form);
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading bill data for printing');
        });
}

        // Download bill details as PDF/Excel
        function downloadBillDetails(billNo) {
            // Create a form and submit to download endpoint
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'download_bill.php';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'bill_no';
            input.value = billNo;
            form.appendChild(input);
            
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
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

        // Auto-focus search box if bill number is in URL
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const billNoParam = urlParams.get('bill_no');
            
            if (billNoParam) {
                document.getElementById('bill_no').focus();
                document.getElementById('bill_no').select();
            }
            
            // Add animation to table rows
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = 1;
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, { threshold: 0.1 });
            
            const tableRows = document.querySelectorAll('table tbody tr');
            tableRows.forEach(row => {
                row.style.opacity = 0;
                row.style.transform = 'translateY(20px)';
                row.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                observer.observe(row);
            });
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>
[file content end]