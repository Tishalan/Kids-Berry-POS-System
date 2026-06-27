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
$cashiers_sql = "SELECT cashier_id, name FROM cashier_users2 ORDER BY name";
$cashiers_result = $conn->query($cashiers_sql);

// Initialize variables
$date_filter = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$bill_no = isset($_GET['bill_no']) ? $_GET['bill_no'] : '';
$cashier_id = isset($_GET['cashier_id']) ? $_GET['cashier_id'] : '';
$payment_method = isset($_GET['payment_method']) ? $_GET['payment_method'] : '';

// Build query for bills2
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
                GROUP_CONCAT(DISTINCT bi.product_name ORDER BY bi.product_name SEPARATOR ', ') as products2
              FROM bills2 b
              JOIN cashier_users2 c ON b.cashier_id = c.cashier_id
              LEFT JOIN bill_items2 bi ON b.bill_no = bi.bill_no
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
              FROM bills2
              WHERE branch_id = $branch_id 
              AND DATE(date) = '$today'";
$stats_result = $conn->query($stats_sql);
$today_stats = $stats_result->fetch_assoc();

// Get returns statistics for today
$returns_sql = "SELECT 
                COUNT(*) as return_count,
                SUM(return_amount) as return_amount
                FROM returns_tracking2
                WHERE DATE(return_date) = '$today'";
$returns_result = $conn->query($returns_sql);
$today_returns = $returns_result->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>KIDS Berry - Bill Management</title>
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

        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Header Styles */
        .header {
            background: var(--primary-dark);
            border-radius: var(--radius-lg);
            padding: 20px 30px;
            margin-bottom: 25px;
            box-shadow: var(--card-shadow);
            color: white;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .brand-section {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .brand-logo {
            width: 60px;
            height: 60px;
            background: rgba(255,255,255,0.15);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid var(--accent);
        }

        .brand-logo i {
            font-size: 28px;
            color: var(--accent);
        }

        .brand-text h1 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .brand-text p {
            font-size: 13px;
            opacity: 0.85;
        }

        .header-actions {
            display: flex;
            gap: 15px;
        }

        .btn-back {
            background: rgba(255,255,255,0.15);
            color: white;
            border: 1px solid rgba(255,255,255,0.25);
            padding: 10px 22px;
            border-radius: 50px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            font-size: 14px;
            transition: var(--transition);
        }

        .btn-back:hover {
            background: rgba(255,255,255,0.25);
            transform: translateY(-2px);
        }

        /* Card Sections */
        .card-section {
            background: white;
            border-radius: var(--radius-lg);
            margin-bottom: 25px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
            border: 1px solid rgba(26, 71, 42, 0.1);
        }

        .section-header {
            background: var(--primary);
            color: white;
            padding: 16px 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .section-header i {
            font-size: 20px;
        }

        .section-header h2 {
            font-size: 18px;
            font-weight: 600;
            margin: 0;
        }

        .section-content {
            padding: 24px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }

        .stat-card {
            background: var(--light);
            border-radius: var(--radius);
            padding: 15px;
            transition: var(--transition);
            border: 1px solid #e0e0e0;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 3px;
            height: 100%;
            background: var(--primary);
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--card-shadow);
        }

        .stat-icon {
            width: 45px;
            height: 45px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
            font-size: 20px;
            color: white;
        }

        .icon-sales { background: var(--primary); }
        .icon-bills { background: var(--secondary); }
        .icon-returns { background: var(--accent); }
        .icon-net { background: var(--info); }
        .icon-cash { background: var(--success); }
        .icon-card { background: #8e44ad; }
        .icon-credit { background: #1abc9c; }
        .icon-discount { background: var(--gray); }

        .stat-value {
            font-size: 24px;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 12px;
            color: var(--gray);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-subtext {
            font-size: 11px;
            color: var(--gray);
            margin-top: 6px;
        }

        /* Filter Section */
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 13px;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .filter-group label i {
            color: var(--primary);
            font-size: 14px;
        }

        .filter-group input,
        .filter-group select {
            padding: 10px 14px;
            border: 1px solid #e0e0e0;
            border-radius: var(--radius-sm);
            font-size: 14px;
            transition: var(--transition);
            background: white;
            font-family: inherit;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(26, 71, 42, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        /* Button Styles */
        .btn {
            padding: 10px 24px;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-family: inherit;
        }

        .btn-search {
            background: var(--primary);
            color: white;
        }

        .btn-search:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-reset {
            background: var(--light);
            color: var(--dark);
            border: 1px solid #e0e0e0;
        }

        .btn-reset:hover {
            background: white;
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        /* Table Section */
        .table-container {
            overflow-x: auto;
            border-radius: var(--radius);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        table thead {
            background: var(--primary);
        }

        table th {
            padding: 14px 12px;
            text-align: left;
            color: white;
            font-weight: 600;
            font-size: 13px;
            white-space: nowrap;
        }

        table tbody tr {
            border-bottom: 1px solid #f0f0f0;
            transition: var(--transition);
        }

        table tbody tr:hover {
            background: var(--light);
        }

        table td {
            padding: 12px;
            color: var(--dark);
            font-size: 13px;
        }

        /* Badges */
        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-cash {
            background: rgba(46, 125, 50, 0.15);
            color: var(--success);
            border: 1px solid rgba(46, 125, 50, 0.3);
        }

        .badge-card {
            background: rgba(52, 152, 219, 0.15);
            color: var(--info);
            border: 1px solid rgba(52, 152, 219, 0.3);
        }

        .badge-credit {
            background: rgba(231, 76, 60, 0.15);
            color: var(--danger);
            border: 1px solid rgba(231, 76, 60, 0.3);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .action-btn {
            width: 32px;
            height: 32px;
            border-radius: var(--radius-sm);
            border: none;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            background: var(--primary);
            color: white;
        }

        .action-btn.print {
            background: var(--accent);
        }

        .action-btn:hover {
            transform: translateY(-2px);
            filter: brightness(1.05);
        }

        /* Balance Styling */
        .balance-positive {
            color: var(--success);
            font-weight: 600;
        }

        .balance-negative {
            color: var(--danger);
            font-weight: 600;
        }

        .balance-zero {
            color: var(--gray);
            font-weight: 600;
        }

        .customer-name {
            font-weight: 600;
            color: var(--dark);
        }

        .customer-phone {
            font-size: 11px;
            color: var(--gray);
            margin-top: 2px;
        }

        /* No Data State */
        .no-data {
            text-align: center;
            padding: 50px 20px;
        }

        .no-data-icon {
            font-size: 60px;
            color: #e0e0e0;
            margin-bottom: 15px;
        }

        .no-data h3 {
            color: var(--dark);
            margin-bottom: 8px;
            font-size: 18px;
        }

        .no-data p {
            color: var(--gray);
            font-size: 13px;
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
            max-width: 1000px;
            max-height: 85vh;
            position: relative;
            overflow: auto;
            box-shadow: var(--card-shadow-hover);
        }

        .close-modal {
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 28px;
            color: var(--gray);
            cursor: pointer;
            transition: var(--transition);
            z-index: 1;
        }

        .close-modal:hover {
            color: var(--danger);
        }

        /* Print Form */
        #printForm {
            display: none;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .container {
                padding: 15px;
            }
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            }
        }

        @media (max-width: 992px) {
            .header-content {
                flex-direction: column;
                text-align: center;
            }
            .brand-section {
                flex-direction: column;
                text-align: center;
            }
            .filter-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .section-content {
                padding: 16px;
            }
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
                gap: 12px;
            }
            .stat-card {
                padding: 15px;
            }
            .stat-value {
                font-size: 20px;
            }
            .filter-actions {
                flex-direction: column;
            }
            .btn {
                width: 100%;
                justify-content: center;
            }
            .action-buttons {
                flex-direction: column;
                align-items: center;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 10px;
            }
            .header {
                padding: 15px;
            }
            .section-header {
                padding: 12px 16px;
            }
            .section-header h2 {
                font-size: 16px;
            }
            table th, table td {
                padding: 8px;
                font-size: 11px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <div class="brand-section">
                    <div class="brand-logo">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <div class="brand-text">
                        <h1>Bill Management</h1>
                        <p>Manage and track all sales transactions</p>
                    </div>
                </div>
                <div class="header-actions">
                    <a href="billing1.php" class="btn-back">
                        <i class="fas fa-arrow-left"></i> Back to Billing
                    </a>
                </div>
            </div>
        </div>

        <!-- Stats Section -->
        <div class="card-section">
            <div class="section-header">
                <i class="fas fa-chart-line"></i>
                <h2>Today's Overview - <?php echo date('F j, Y'); ?></h2>
            </div>
            <div class="section-content">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon icon-bills">
                            <i class="fas fa-receipt"></i>
                        </div>
                        <div class="stat-value"><?php echo $today_stats['bill_count'] ?? 0; ?></div>
                        <div class="stat-label">Total Bills</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon icon-sales">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="stat-value">Rs<?php echo number_format($today_stats['total_sales'] ?? 0, 2); ?></div>
                        <div class="stat-label">Today's Sales</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon icon-returns">
                            <i class="fas fa-undo"></i>
                        </div>
                        <div class="stat-value">Rs<?php echo number_format($today_returns['return_amount'] ?? 0, 2); ?></div>
                        <div class="stat-label">Returns</div>
                        <div class="stat-subtext"><?php echo $today_returns['return_count'] ?? 0; ?> items</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon icon-net">
                            <i class="fas fa-calculator"></i>
                        </div>
                        <div class="stat-value">
                            Rs<?php 
                                $net_sales = ($today_stats['total_sales'] ?? 0) - ($today_returns['return_amount'] ?? 0);
                                echo number_format($net_sales, 2);
                            ?>
                        </div>
                        <div class="stat-label">Net Sales</div>
                    </div>
                    
                    <!--<div class="stat-card">-->
                    <!--    <div class="stat-icon icon-cash">-->
                    <!--        <i class="fas fa-money-bill"></i>-->
                    <!--    </div>-->
                    <!--    <div class="stat-value">Rs<?php echo number_format($today_stats['cash_sales'] ?? 0, 2); ?></div>-->
                    <!--    <div class="stat-label">Cash Sales</div>-->
                    <!--</div>-->
                    
                    <!--<div class="stat-card">-->
                    <!--    <div class="stat-icon icon-card">-->
                    <!--        <i class="fas fa-credit-card"></i>-->
                    <!--    </div>-->
                    <!--    <div class="stat-value">Rs<?php echo number_format($today_stats['card_sales'] ?? 0, 2); ?></div>-->
                    <!--    <div class="stat-label">Card Sales</div>-->
                    <!--</div>-->
                    
                    <div class="stat-card">
                        <div class="stat-icon icon-credit">
                            <i class="fas fa-hand-holding-usd"></i>
                        </div>
                        <div class="stat-value">Rs<?php echo number_format($today_stats['credit_sales'] ?? 0, 2); ?></div>
                        <div class="stat-label">Credit Sales</div>
                    </div>
                    
                    <!--<div class="stat-card">-->
                    <!--    <div class="stat-icon icon-discount">-->
                    <!--        <i class="fas fa-percentage"></i>-->
                    <!--    </div>-->
                    <!--    <div class="stat-value">Rs<?php echo number_format($today_stats['total_discount'] ?? 0, 2); ?></div>-->
                    <!--    <div class="stat-label">Total Discount</div>-->
                    <!--</div>-->
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="card-section">
            <div class="section-header">
                <i class="fas fa-search"></i>
                <h2>Search Bills</h2>
            </div>
            <div class="section-content">
                <form method="get" action="">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label for="date"><i class="fas fa-calendar"></i> Date</label>
                            <input type="date" id="date" name="date" value="<?php echo htmlspecialchars($date_filter); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label for="bill_no"><i class="fas fa-receipt"></i> Bill Number</label>
                            <input type="text" id="bill_no" name="bill_no" placeholder="Enter bill number" value="<?php echo htmlspecialchars($bill_no); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label for="cashier_id"><i class="fas fa-user-tie"></i> Sales Officer</label>
                            <select id="cashier_id" name="cashier_id">
                                <option value="">All Sales Officers</option>
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
                        
                        <div class="filter-group">
                            <label for="payment_method"><i class="fas fa-credit-card"></i> Payment Method</label>
                            <select id="payment_method" name="payment_method">
                                <option value="">All Methods</option>
                                <option value="Cash" <?php echo $payment_method == 'Cash' ? 'selected' : ''; ?>>Cash</option>
                                <option value="Card" <?php echo $payment_method == 'Card' ? 'selected' : ''; ?>>Card</option>
                                <option value="Credit" <?php echo $payment_method == 'Credit' ? 'selected' : ''; ?>>Credit</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-search">
                            <i class="fas fa-search"></i> Search Bills
                        </button>
                        <button type="button" class="btn btn-reset" onclick="window.location.href='bill_management1.php'">
                            <i class="fas fa-redo"></i> Reset Filters
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Bills Table Section -->
        <div class="card-section">
            <div class="section-header">
                <i class="fas fa-list"></i>
                <h2>Bills List</h2>
            </div>
            <div class="section-content">
                <div class="table-container">
                    <?php if ($bills_result && $bills_result->num_rows > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Bill No</th>
                                    <th>Date & Time</th>
                                    <th>Customer</th>
                                    <th>Sales Officer</th>
                                    <th>Items</th>
                                    <th>Payment</th>
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
                                    <td><strong><?php echo htmlspecialchars($bill['bill_no']); ?></strong></td>
                                    <td>
                                        <div><?php echo date('Y-m-d', strtotime($bill['date'])); ?></div>
                                        <div class="customer-phone"><?php echo date('H:i:s', strtotime($bill['date'])); ?></div>
                                    </td>
                                    <td>
                                        <?php if (!empty($bill['customer_name'])): ?>
                                            <div class="customer-name"><?php echo htmlspecialchars($bill['customer_name']); ?></div>
                                            <?php if (!empty($bill['phone_no'])): ?>
                                                <div class="customer-phone"><?php echo htmlspecialchars($bill['phone_no']); ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div class="customer-name">Walk-in Customer</div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($bill['cashier_name']); ?></td>
                                    <td>
                                        <div><?php echo $bill['item_count']; ?> items</div>
                                        <div class="customer-phone">Qty: <?php echo $bill['total_quantity']; ?></div>
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
                                            <span class="balance-negative">
                                                Rs<?php echo number_format($bill['balance'], 2); ?>
                                            </span>
                                        <?php elseif ($bill['balance'] < 0): ?>
                                            <span class="balance-positive">
                                                Rs<?php echo number_format(abs($bill['balance']), 2); ?> (Change)
                                            </span>
                                        <?php else: ?>
                                            <span class="balance-zero">Paid</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button type="button" class="action-btn print" onclick="printBill('<?php echo htmlspecialchars($bill['bill_no']); ?>')" title="Print Bill">
                                                <i class="fas fa-print"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-data">
                            <div class="no-data-icon">
                                <i class="fas fa-file-invoice-dollar"></i>
                            </div>
                            <h3>No bills found</h3>
                            <p>Try adjusting your search criteria</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Bill Details Modal -->
    <div id="billModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal()">&times;</span>
            <div id="modalContent"></div>
        </div>
    </div>

    <!-- Print Bill Form -->
    <form id="printForm" method="post" action="bill1.php" target="_blank" style="display: none;">
        <input type="hidden" name="bill_no" id="printBillNo">
        <input type="hidden" name="cashier_id" id="printCashierId">
        <input type="hidden" name="payment_method" id="printPaymentMethod">
        <input type="hidden" name="paid_amount" id="printPaidAmount">
        <input type="hidden" name="cart_items" id="printCartItems">
    </form>

    <script>
        // Print bill function
        function printBill(billNo) {
            // Fetch bill data
            fetch('get_bill_for_print1.php?bill_no=' + encodeURIComponent(billNo))
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Create form and submit to bill1.php
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = 'bill1.php';
                        form.target = '_blank';
                        
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
                        
                        const cartInput = document.createElement('input');
                        cartInput.type = 'hidden';
                        cartInput.name = 'cart_items';
                        cartInput.value = JSON.stringify(data.items);
                        form.appendChild(cartInput);
                        
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
                const billNoInput = document.getElementById('bill_no');
                if (billNoInput) {
                    billNoInput.focus();
                    billNoInput.select();
                }
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
                row.style.transform = 'translateY(15px)';
                row.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                observer.observe(row);
            });
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>