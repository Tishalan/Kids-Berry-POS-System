<?php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['stock_keeper_id'])) {
    header("Location: ../index.php");
    exit();
}

// Handle logout
if (isset($_GET['logout'])) {
    // Only destroy stock keeper related session
    unset($_SESSION['stock_keeper_id']);
    // Don't use session_destroy() as it destroys all sessions
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

// Initialize variables
$report_type = '';
$from_date = '';
$to_date = '';
$results = [];
$total_items = 0;
$total_value = 0;
$low_stock_count = 0;

// Handle report generation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['generate_report'])) {
    $report_type = mysqli_real_escape_string($conn, $_POST['report_type']);
    $from_date = mysqli_real_escape_string($conn, $_POST['from']);
    $to_date = isset($_POST['to']) && !empty($_POST['to']) ? mysqli_real_escape_string($conn, $_POST['to']) : $from_date;
    
    if ($report_type == 'Current Stock') {
        // Current Stock Report
        $stock_sql = "SELECT 
                        p.product_id,
                        p.name,
                        p.category,
                        p.original_price,
                        p.sale_price,
                        p.stock,
                        p.barcode,
                        p.color,
                        p.photo,
                        (p.stock * p.sale_price) as stock_value
                      FROM products p
                      ORDER BY p.stock ASC, p.name ASC";
        
        $stock_result = $conn->query($stock_sql);
        
        if ($stock_result->num_rows > 0) {
            while ($row = $stock_result->fetch_assoc()) {
                $results[] = $row;
                $total_items += $row['stock'];
                $total_value += $row['stock_value'];
                if ($row['stock'] < 10) {
                    $low_stock_count++;
                }
            }
        }
    } else {
        // Stock Movement Report - Updated query to match report.php structure
        $movement_sql = "SELECT 
                        p.product_id,
                        p.name,
                        p.category,
                        SUM(bi.quantity) as sold_quantity,
                        COUNT(bi.bill_no) as times_sold,
                        MIN(b.date) as first_sale,
                        MAX(b.date) as last_sale
                      FROM products p
                      LEFT JOIN bill_items bi ON p.product_id = bi.product_id
                      LEFT JOIN bills b ON bi.bill_no = b.bill_no
                      WHERE b.branch_id = $branch_id
                      AND DATE(b.date) BETWEEN '$from_date' AND '$to_date'
                      GROUP BY p.product_id, p.name, p.category
                      ORDER BY sold_quantity DESC";
        
        $movement_result = $conn->query($movement_sql);
        
        if ($movement_result->num_rows > 0) {
            while ($row = $movement_result->fetch_assoc()) {
                $results[] = $row;
            }
        }
    }
}

// Handle export to Excel
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['export_excel'])) {
    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="stock_report_' . date('Y-m-d') . '.xls"');
    
    $export_report_type = mysqli_real_escape_string($conn, $_POST['report_type']);
    $export_from_date = mysqli_real_escape_string($conn, $_POST['from']);
    $export_to_date = mysqli_real_escape_string($conn, $_POST['to']);
    
    echo "Stock Report\n";
    echo "Report Type: " . $export_report_type . "\n";
    echo "Period: " . $export_from_date . " to " . $export_to_date . "\n\n";
    
    if ($export_report_type == 'Current Stock') {
        echo "Product ID\tName\tCategory\tStock\tOriginal Price\tSale Price\tStock Value\tBarcode\tColor\n";
        
        $export_sql = "SELECT 
                        p.product_id,
                        p.name,
                        p.category,
                        p.stock,
                        p.original_price,
                        p.sale_price,
                        (p.stock * p.sale_price) as stock_value,
                        p.barcode,
                        p.color
                      FROM products p
                      ORDER BY p.stock ASC, p.name ASC";
        
        $export_result = $conn->query($export_sql);
        
        while ($row = $export_result->fetch_assoc()) {
            echo $row['product_id'] . "\t";
            echo $row['name'] . "\t";
            echo $row['category'] . "\t";
            echo $row['stock'] . "\t";
            echo $row['original_price'] . "\t";
            echo $row['sale_price'] . "\t";
            echo $row['stock_value'] . "\t";
            echo $row['barcode'] . "\t";
            echo $row['color'] . "\n";
        }
    } else {
        echo "Product ID\tName\tCategory\tSold Quantity\tTimes Sold\tFirst Sale\tLast Sale\n";
        
        $export_sql = "SELECT 
                        p.product_id,
                        p.name,
                        p.category,
                        SUM(bi.quantity) as sold_quantity,
                        COUNT(bi.bill_no) as times_sold,
                        MIN(b.date) as first_sale,
                        MAX(b.date) as last_sale
                      FROM products p
                      LEFT JOIN bill_items bi ON p.product_id = bi.product_id
                      LEFT JOIN bills b ON bi.bill_no = b.bill_no
                      WHERE b.branch_id = $branch_id
                      AND DATE(b.date) BETWEEN '$export_from_date' AND '$export_to_date'
                      GROUP BY p.product_id, p.name, p.category
                      ORDER BY sold_quantity DESC";
        
        $export_result = $conn->query($export_sql);
        
        while ($row = $export_result->fetch_assoc()) {
            echo $row['product_id'] . "\t";
            echo $row['name'] . "\t";
            echo $row['category'] . "\t";
            echo $row['sold_quantity'] . "\t";
            echo $row['times_sold'] . "\t";
            echo $row['first_sale'] . "\t";
            echo $row['last_sale'] . "\n";
        }
    }
    
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Stock Reports - Kids Berry</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-purple: #8e44ad;
            --light-purple: #e8d4f7;
            --dark-purple: #6c3483;
            --primary-green: #27ae60;
            --light-green: #d5f5e3;
            --dark-green: #229954;
            --primary-blue: #3498db;
            --light-blue: #d6eaf8;
            --primary-orange: #e67e22;
            --light-orange: #fdebd0;
            --primary-red: #e74c3c;
            --light-red: #fadbd8;
            --primary-pink: #e84393;
            --light-pink: #fd79a8;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #495057;
            --shadow-light: 0 2px 8px rgba(0,0,0,0.08);
            --shadow-medium: 0 4px 15px rgba(0,0,0,0.12);
            --shadow-heavy: 0 8px 25px rgba(0,0,0,0.15);
            --border-radius: 12px;
            --border-radius-small: 8px;
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            --gradient-primary: linear-gradient(135deg, var(--primary-purple), var(--dark-purple));
            --gradient-secondary: linear-gradient(135deg, var(--primary-green), var(--dark-green));
            --gradient-blue: linear-gradient(135deg, var(--primary-blue), #2980b9);
            --gradient-orange: linear-gradient(135deg, var(--primary-orange), #d35400);
            --gradient-red: linear-gradient(135deg, var(--primary-red), #c0392b);
            --gradient-pink: linear-gradient(135deg, var(--primary-pink), #fd79a8);
            --gradient-light: linear-gradient(135deg, var(--light-purple), var(--light-green));
            --gradient-rainbow: linear-gradient(135deg, #8e44ad, #3498db, #27ae60, #e67e22, #e74c3c);
        }
        
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
            -webkit-tap-highlight-color: transparent;
        }
        
        html {
            height: 100%;
            overflow-x: hidden;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--gradient-light);
            min-height: 100vh;
            color: var(--dark-gray);
            line-height: 1.5;
            overflow-x: hidden;
            padding-bottom: env(safe-area-inset-bottom);
        }
        
        .container { 
            max-width: 100%; 
            margin: 0 auto; 
            padding: 10px; 
        }
        
        /* Header Styles - Mobile First */
        header {
            background: var(--gradient-primary);
            color: var(--white);
            padding: 0.8rem 0;
            box-shadow: var(--shadow-medium);
            position: sticky; 
            top: 0; 
            z-index: 1000;
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }
        
        .menu { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            flex-wrap: nowrap; 
            gap: 0.5rem; 
            overflow-x: auto;
            padding: 0 5px;
            -webkit-overflow-scrolling: touch;
        }
        
        .logo { 
            font-size: 1.4rem; 
            font-weight: 800; 
            display: flex; 
            align-items: center; 
            gap: 8px; 
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
            white-space: nowrap;
            flex-shrink: 0;
        }
        
        .nav-links {
            display: flex;
            flex-wrap: nowrap;
            gap: 0.3rem;
            align-items: center;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            padding: 5px 0;
        }
        
        .nav-links a {
            color: var(--white); 
            text-decoration: none; 
            padding: 8px 12px; 
            border-radius: 50px;
            transition: var(--transition); 
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255,255,255,0.2);
            white-space: nowrap;
            flex-shrink: 0;
        }
        
        .nav-links a:hover, 
        .nav-links a.active {
            background: var(--light-green); 
            color: var(--dark-purple); 
            transform: translateY(-2px);
            box-shadow: var(--shadow-light);
        }
        
        /* Logout Button Special Style */
        .logout-btn {
            background: var(--gradient-pink) !important;
            color: white !important;
            border: 1px solid rgba(255,255,255,0.3) !important;
            position: relative;
            overflow: hidden;
        }
        
        .logout-btn:hover {
            transform: translateY(-2px) scale(1.03);
            box-shadow: 0 5px 15px rgba(232, 67, 147, 0.4);
        }
        
        /* Welcome User Section */
        .user-welcome {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-bottom: 10px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.1rem;
            box-shadow: var(--shadow-light);
            flex-shrink: 0;
        }
        
        /* Card Styles */
        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 18px;
            box-shadow: var(--shadow-light);
            transition: var(--transition);
            border: 1px solid var(--medium-gray);
            animation: fadeIn 0.5s ease-out;
            position: relative;
            overflow: hidden;
        }
        
        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--gradient-primary);
        }
        
        .card:hover {
            box-shadow: var(--shadow-medium);
            transform: translateY(-3px);
        }
        
        h2 { 
            color: var(--primary-purple); 
            margin-bottom: 15px; 
            font-size: 1.4rem; 
            display: flex; 
            align-items: center; 
            gap: 10px; 
            font-weight: 700;
        }
        
        h3 {
            color: var(--dark-gray);
            margin-bottom: 12px;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 15px;
            position: relative;
        }
        
        input, select, textarea {
            padding: 14px 16px;
            border-radius: var(--border-radius-small);
            font-size: 1rem;
            transition: var(--transition);
            border: 2px solid var(--medium-gray);
            width: 100%;
            background: var(--light-gray);
        }
        
        input:focus, select:focus, textarea:focus {
            border-color: var(--primary-purple);
            box-shadow: 0 0 0 3px rgba(142, 68, 173, 0.2);
            outline: none;
            background: var(--white);
        }
        
        .btn {
            padding: 14px 20px;
            border-radius: var(--border-radius-small);
            font-size: 1rem;
            transition: var(--transition);
            border: none;
            color: var(--white);
            font-weight: bold;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            box-shadow: var(--shadow-light);
        }
        
        .btn-primary {
            background: var(--gradient-primary);
        }
        
        .btn-secondary {
            background: var(--gradient-secondary);
        }
        
        .btn:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-medium);
        }
        
        .btn:active {
            transform: translateY(-1px);
        }
        
        /* Table Styles - Mobile Optimized */
        .table-container {
            overflow-x: auto;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-light);
            background: var(--white);
            -webkit-overflow-scrolling: touch;
        }
        
        table { 
            width: 100%; 
            border-collapse: collapse; 
            background: var(--white);
            min-width: 500px;
        }
        
        th { 
            background: var(--gradient-primary);
            color: var(--white); 
            padding: 14px 12px; 
            text-align: left; 
            font-weight: 600;
            position: sticky;
            left: 0;
            font-size: 0.85rem;
        }
        
        td { 
            padding: 12px; 
            border-bottom: 1px solid var(--medium-gray); 
            transition: var(--transition);
            font-size: 0.85rem;
        }
        
        tr {
            transition: var(--transition);
        }
        
        tr:active { 
            background: var(--light-green); 
        }
        
        /* Stats Container */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 18px;
            text-align: center;
            box-shadow: var(--shadow-light);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
        }
        
        .stat-card:nth-child(1)::before { background: var(--gradient-primary); }
        .stat-card:nth-child(2)::before { background: var(--gradient-secondary); }
        .stat-card:nth-child(3)::before { background: var(--gradient-blue); }
        .stat-card:nth-child(4)::before { background: var(--gradient-orange); }
        
        .stat-card:active {
            transform: scale(0.98);
        }
        
        .stat-icon {
            font-size: 1.8rem;
            margin-bottom: 10px;
            opacity: 0.8;
        }
        
        .stat-card:nth-child(1) .stat-icon { color: var(--primary-purple); }
        .stat-card:nth-child(2) .stat-icon { color: var(--primary-green); }
        .stat-card:nth-child(3) .stat-icon { color: var(--primary-blue); }
        .stat-card:nth-child(4) .stat-icon { color: var(--primary-orange); }
        
        .stat-number {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 5px;
            line-height: 1;
        }
        
        .stat-card:nth-child(1) .stat-number { color: var(--primary-purple); }
        .stat-card:nth-child(2) .stat-number { color: var(--primary-green); }
        .stat-card:nth-child(3) .stat-number { color: var(--primary-blue); }
        .stat-card:nth-child(4) .stat-number { color: var(--primary-orange); }
        
        .stat-label {
            font-size: 0.85rem;
            color: var(--dark-gray);
            font-weight: 600;
        }
        
        /* Stock Badge */
        .stock-badge { 
            background: var(--gradient-secondary); 
            color: var(--white); 
            padding: 5px 10px; 
            border-radius: 20px; 
            font-weight: bold;
            display: inline-block;
            text-align: center;
            min-width: 40px;
            box-shadow: var(--shadow-light);
            font-size: 0.8rem;
        }
        
        .stock-low {
            background: var(--gradient-orange);
        }
        
        /* Photo Preview */
        .photo-preview { 
            width: 50px; 
            height: 50px; 
            object-fit: cover; 
            border-radius: 8px; 
            border: 2px solid var(--light-purple);
            transition: var(--transition);
        }
        
        .photo-preview:hover {
            transform: scale(1.1);
            border-color: var(--primary-purple);
        }
        
        /* Export Buttons */
        .export-buttons {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            justify-content: flex-end;
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 30px 15px;
            color: var(--dark-gray);
        }
        
        .empty-state i {
            font-size: 2.5rem;
            color: var(--light-purple);
            margin-bottom: 12px;
        }
        
        .empty-state h3 {
            font-size: 1.1rem;
            margin-bottom: 8px;
            color: var(--primary-purple);
            justify-content: center;
        }
        
        /* Mobile Menu Toggle for Smaller Screens */
        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 5px;
        }
        
        /* Touch-friendly improvements */
        @media (hover: none) and (pointer: coarse) {
            .card:hover {
                transform: none;
            }
            
            .stat-card:hover {
                transform: none;
            }
            
            .btn:hover {
                transform: none;
            }
            
            .nav-links a:hover {
                transform: none;
            }
            
            .logout-btn:hover {
                transform: none;
            }
        }
        
        /* Tablet and Desktop Styles */
        @media (min-width: 768px) {
            .container {
                padding: 15px;
                max-width: 1400px;
            }
            
            header {
                padding: 1rem 0;
            }
            
            .menu {
                gap: 1rem;
                padding: 0;
                overflow-x: visible;
            }
            
            .logo {
                font-size: 1.8rem;
                gap: 12px;
            }
            
            .nav-links {
                gap: 0.5rem;
                overflow-x: visible;
                padding: 0;
            }
            
            .nav-links a {
                padding: 10px 18px;
                gap: 8px;
                font-size: 0.9rem;
            }
            
            .user-welcome {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
            
            .card {
                padding: 25px;
            }
            
            h2 {
                font-size: 1.6rem;
                margin-bottom: 20px;
            }
            
            h3 {
                font-size: 1.2rem;
                margin-bottom: 15px;
            }
            
            .stats-container {
                grid-template-columns: repeat(4, 1fr);
                gap: 20px;
                margin-bottom: 30px;
            }
            
            .stat-card {
                padding: 25px;
            }
            
            .stat-icon {
                font-size: 2.5rem;
                margin-bottom: 15px;
            }
            
            .stat-number {
                font-size: 2.5rem;
            }
            
            .stat-label {
                font-size: 1rem;
            }
            
            th, td {
                padding: 18px 15px;
                font-size: 1rem;
            }
            
            .export-buttons {
                gap: 15px;
                margin-top: 20px;
            }
            
            .btn {
                padding: 16px 24px;
                font-size: 1.1rem;
            }
        }
        
        /* Very Small Mobile Devices */
        @media (max-width: 360px) {
            .container {
                padding: 8px;
            }
            
            .logo {
                font-size: 1.2rem;
            }
            
            .nav-links a {
                padding: 6px 10px;
                font-size: 0.75rem;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .card {
                padding: 15px;
            }
            
            .export-buttons {
                flex-direction: column;
            }
        }
        
        /* Landscape Mode Optimizations */
        @media (max-height: 500px) and (orientation: landscape) {
            header {
                padding: 0.5rem 0;
            }
            
            .stats-container {
                grid-template-columns: repeat(4, 1fr);
                gap: 10px;
                margin-bottom: 15px;
            }
            
            .stat-card {
                padding: 12px;
            }
            
            .stat-icon {
                font-size: 1.5rem;
                margin-bottom: 8px;
            }
            
            .stat-number {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>

<header>
    <div class="container menu">
        <div class="logo"><i class="fas fa-baby"></i> Kids Berry</div>
        <div class="nav-links">
            <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="product.php"><i class="fas fa-box"></i> Inventory</a>
            <a href="barcode.php"><i class="fas fa-barcode"></i> Barcode</a>
            <a href="customershow.php"><i class="fas fa-users"></i> Customers</a>
            <a href="suppliers.php" ><i class="fas fa-truck"></i> Suppliers</a>
             <a href="stock_transactions1.php" ><i class="fas fa-arrows-spin"></i> Stock Transfer</a>
            <a href="report_keep.php" class="active"><i class="fas fa-chart-line"></i> Reports</a>
            <a href="?logout=true" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>
</header>

<div class="container">
    <!-- Welcome Section -->
    <div class="card" style="margin-bottom: 15px;">
        <div class="user-welcome">
            <div>
                <h2><i class="fas fa-chart-line"></i> Stock Reports</h2>
                <p>Welcome back, <?php echo $_SESSION['stock_keeper_name']; ?>! Generate and analyze stock reports.</p>
            </div>
            <div class="user-info">
                <div class="user-avatar">
                    <i class="fas fa-user"></i>
                </div>
                <div>
                    <strong><?php echo $_SESSION['stock_keeper_name']; ?></strong>
                    <div style="font-size: 0.8rem; opacity: 0.7;">Stock Keeper</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Report Filters -->
    <div class="card" style="margin-bottom: 15px;">
        <h2><i class="fas fa-filter"></i> Report Filters</h2>
        <form method="post" class="form-container">
            <div class="form-group">
                <label><i class="fas fa-chart-bar"></i> Report Type</label>
                <select name="report_type" required>
                    <option value="Current Stock" <?php echo $report_type == 'Current Stock' ? 'selected' : ''; ?>>Current Stock</option>
                    <option value="Stock Movement" <?php echo $report_type == 'Stock Movement' ? 'selected' : ''; ?>>Stock Movement</option>
                </select>
            </div>
            <div class="form-group" id="fromDateGroup">
                <label><i class="fas fa-calendar-start"></i> From</label>
                <input type="date" name="from" value="<?php echo htmlspecialchars($from_date); ?>">
            </div>
            <div class="form-group" id="toDateGroup">
                <label><i class="fas fa-calendar-end"></i> To</label>
                <input type="date" name="to" value="<?php echo htmlspecialchars($to_date); ?>">
            </div>
            <div class="form-group">
                <button type="submit" name="generate_report" class="btn btn-primary">
                    <i class="fas fa-sync"></i> Generate Report
                </button>
            </div>
        </form>
    </div>

    <!-- Report Results -->
    <div class="card">
        <h2><i class="fas fa-th-list"></i> Stock Report</h2>
        
        <?php if (!empty($results)) { ?>
            <div class="stats-container">
                <?php if ($report_type == 'Current Stock') { ?>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-boxes"></i>
                        </div>
                        <div class="stat-number"><?php echo count($results); ?></div>
                        <div class="stat-label">Total Products</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-cubes"></i>
                        </div>
                        <div class="stat-number"><?php echo $total_items; ?></div>
                        <div class="stat-label">Total Items</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="stat-number">Rs<?php echo number_format($total_value, 2); ?></div>
                        <div class="stat-label">Stock Value</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="stat-number"><?php echo $low_stock_count; ?></div>
                        <div class="stat-label">Low Stock</div>
                    </div>
                <?php } else { ?>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                        <div class="stat-number"><?php echo count($results); ?></div>
                        <div class="stat-label">Products Sold</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="stat-number"><?php echo $from_date . ($to_date ? ' to ' . $to_date : ''); ?></div>
                        <div class="stat-label">Report Period</div>
                    </div>
                <?php } ?>
            </div>

            <div class="table-container">
                <table id="stockTable">
                    <thead>
                        <tr>
                            <?php if ($report_type == 'Current Stock') { ?>
                                <th>Photo</th>
                                <th>Product ID</th>
                                <th>Name</th>
                                <th>Category</th>
                                <th>Stock</th>
                                <th>Original Price</th>
                                <th>Sale Price</th>
                                <th>Stock Value</th>
                                <th>Barcode</th>
                                <th>Color</th>
                            <?php } else { ?>
                                <th>Product ID</th>
                                <th>Name</th>
                                <th>Category</th>
                                <th>Sold Quantity</th>
                                <th>Times Sold</th>
                                <th>First Sale</th>
                                <th>Last Sale</th>
                            <?php } ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ($report_type == 'Current Stock') {
                            foreach ($results as $row) {
                                $stockClass = $row['stock'] < 10 ? 'stock-low' : '';
                                echo "<tr>";
                                echo "<td>";
                                if($row['photo']) {
                                    echo "<img src='" . htmlspecialchars($row['photo']) . "' class='photo-preview'>";
                                } else {
                                    echo "<div style='width:50px; height:50px; background:var(--light-purple); border-radius:8px; display:flex; align-items:center; justify-content:center;'>";
                                    echo "<i class='fas fa-image' style='font-size:20px; color:var(--primary-purple);'></i>";
                                    echo "</div>";
                                }
                                echo "</td>";
                                echo "<td><strong>" . htmlspecialchars($row['product_id']) . "</strong></td>";
                                echo "<td>" . htmlspecialchars($row['name']) . "</td>";
                                echo "<td>" . htmlspecialchars($row['category']) . "</td>";
                                echo "<td><span class='stock-badge " . $stockClass . "'>" . $row['stock'] . "</span></td>";
                                echo "<td>Rs. " . number_format($row['original_price'], 2) . "</td>";
                                echo "<td>Rs. " . number_format($row['sale_price'], 2) . "</td>";
                                echo "<td>Rs. " . number_format($row['stock_value'], 2) . "</td>";
                                echo "<td>" . htmlspecialchars($row['barcode'] ?? '') . "</td>";
                                echo "<td>" . htmlspecialchars($row['color'] ?? '') . "</td>";
                                echo "</tr>";
                            }
                        } else {
                            foreach ($results as $row) {
                                echo "<tr>";
                                echo "<td><strong>" . htmlspecialchars($row['product_id']) . "</strong></td>";
                                echo "<td>" . htmlspecialchars($row['name']) . "</td>";
                                echo "<td>" . htmlspecialchars($row['category']) . "</td>";
                                echo "<td>" . $row['sold_quantity'] . "</td>";
                                echo "<td>" . $row['times_sold'] . "</td>";
                                echo "<td>" . $row['first_sale'] . "</td>";
                                echo "<td>" . $row['last_sale'] . "</td>";
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
                    <button type="submit" name="export_excel" class="btn btn-secondary">
                        <i class="fas fa-file-excel"></i> Export to Excel
                    </button>
                </form>
            </div>
        <?php } else if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['generate_report'])) { ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>No data found for the selected criteria</h3>
                <p>Try selecting different filters</p>
            </div>
        <?php } else { ?>
            <div class="empty-state">
                <i class="fas fa-chart-bar"></i>
                <h3>Generate a stock report</h3>
                <p>Select report type and filters to generate a report</p>
            </div>
        <?php } ?>
    </div>
</div>

<script>
// Toggle date fields based on report type
document.querySelector('select[name="report_type"]').addEventListener('change', function() {
    const isMovementReport = this.value === 'Stock Movement';
    document.getElementById('fromDateGroup').style.display = isMovementReport ? 'block' : 'none';
    document.getElementById('toDateGroup').style.display = isMovementReport ? 'block' : 'none';
    
    // Set required attribute for date fields in movement report
    document.querySelector('input[name="from"]').required = isMovementReport;
    document.querySelector('input[name="to"]').required = isMovementReport;
});

// Initialize date field visibility
document.addEventListener('DOMContentLoaded', function() {
    const isMovementReport = document.querySelector('select[name="report_type"]').value === 'Stock Movement';
    document.getElementById('fromDateGroup').style.display = isMovementReport ? 'block' : 'none';
    document.getElementById('toDateGroup').style.display = isMovementReport ? 'block' : 'none';
});

// Add confirmation for logout
document.querySelector('.logout-btn').addEventListener('click', function(e) {
    if (!confirm('Are you sure you want to logout?')) {
        e.preventDefault();
    }
});

// Auto-refresh page every 60 seconds
setTimeout(function() {
    window.location.reload();
}, 40000);

// Touch device improvements
if ('ontouchstart' in window) {
    document.addEventListener('touchstart', function() {}, {passive: true});
}

// Prevent zoom on double tap (iOS)
let lastTouchEnd = 0;
document.addEventListener('touchend', function (event) {
    const now = (new Date()).getTime();
    if (now - lastTouchEnd <= 300) {
        event.preventDefault();
    }
    lastTouchEnd = now;
}, false);
</script>

</body>
</html>

<?php
$conn->close();
?>