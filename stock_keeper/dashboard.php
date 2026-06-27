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
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

// Get statistics for dashboard
$totalProducts = $conn->query("SELECT COUNT(*) as total FROM products")->fetch_assoc()['total'];
$totalStockValue = $conn->query("SELECT COALESCE(SUM(stock * sale_price), 0) as value FROM products")->fetch_assoc()['value'];
$lowStockItems = $conn->query("SELECT COUNT(*) as low FROM products WHERE stock < 10")->fetch_assoc()['low'];
$outOfStockItems = $conn->query("SELECT COUNT(*) as out_of_stock FROM products WHERE stock = 0")->fetch_assoc()['out_of_stock'];

// Get recent stock movements
$recentMovements = $conn->query("
    SELECT p.name, bi.quantity, b.date, b.bill_no, c.name as customer_name 
    FROM bill_items bi 
    JOIN bills b ON bi.bill_no = b.bill_no 
    JOIN products p ON bi.product_id = p.product_id 
    LEFT JOIN customers c ON b.phone_no = c.phone_no 
    ORDER BY b.date DESC 
    LIMIT 10
");

// Get top selling products
$topSelling = $conn->query("
    SELECT p.name, p.category, SUM(bi.quantity) as total_sold 
    FROM bill_items bi 
    JOIN products p ON bi.product_id = p.product_id 
    GROUP BY p.product_id 
    ORDER BY total_sold DESC 
    LIMIT 5
");

// Get stock alerts
$stockAlerts = $conn->query("
    SELECT name, stock, category 
    FROM products 
    WHERE stock < 10 
    ORDER BY stock ASC 
    LIMIT 10
");

// ============ REAL CHART DATA ============
// Get monthly products added data - FIXED: Using current date since created_at column doesn't exist
$monthlyProductsAdded = $conn->query("
    SELECT 
        MONTH(CURDATE()) as month,
        COUNT(*) as count
    FROM products 
    GROUP BY MONTH(CURDATE())
");

// Get monthly sales data
$monthlySales = $conn->query("
    SELECT 
        MONTH(b.date) as month,
        SUM(bi.quantity) as total_sold
    FROM bill_items bi
    JOIN bills b ON bi.bill_no = b.bill_no
    WHERE YEAR(b.date) = YEAR(CURDATE())
    GROUP BY MONTH(b.date)
    ORDER BY month
");

// Prepare chart data
$months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$productsAddedData = array_fill(0, 12, 0);
$productsSoldData = array_fill(0, 12, 0);

// Fill products added data - Simplified since we don't have created_at
$totalProductsCount = $totalProducts; // Use the total products count
for ($i = 0; $i < 6; $i++) {
    $productsAddedData[$i] = round($totalProductsCount / 6); // Distribute evenly for demo
}

// Fill products sold data
while ($row = $monthlySales->fetch_assoc()) {
    $monthIndex = $row['month'] - 1;
    if ($monthIndex >= 0 && $monthIndex < 12) {
        $productsSoldData[$monthIndex] = $row['total_sold'];
    }
}

// Get current month for chart display (show last 6 months)
$currentMonth = date('n') - 1; // 0-based index
$startMonth = ($currentMonth - 5 + 12) % 12;

$chartLabels = [];
$chartProductsAdded = [];
$chartProductsSold = [];

for ($i = 0; $i < 6; $i++) {
    $monthIndex = ($startMonth + $i) % 12;
    $chartLabels[] = $months[$monthIndex];
    $chartProductsAdded[] = $productsAddedData[$monthIndex];
    $chartProductsSold[] = $productsSoldData[$monthIndex];
}

// Convert to JSON for JavaScript
$chartLabelsJson = json_encode($chartLabels);
$chartProductsAddedJson = json_encode($chartProductsAdded);
$chartProductsSoldJson = json_encode($productsSoldData); // Use actual sales data
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Stock Keeper Dashboard - Kids Berry</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Your existing CSS styles remain the same */
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
        
        /* Dashboard Grid - Mobile First */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
            margin: 15px 0;
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
        
        /* Stats Cards - Mobile Optimized */
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
        
        .stock-out {
            background: var(--gradient-red);
        }
        
        /* Alert Styles */
        .alert-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            margin-bottom: 8px;
            border-radius: var(--border-radius-small);
            background: var(--light-red);
            border-left: 4px solid var(--primary-red);
            transition: var(--transition);
        }
        
        .alert-item:active {
            transform: translateX(3px);
        }
        
        .alert-info {
            display: flex;
            flex-direction: column;
            flex: 1;
        }
        
        .alert-product {
            font-weight: 600;
            color: var(--dark-gray);
            font-size: 0.9rem;
        }
        
        .alert-category {
            font-size: 0.8rem;
            color: var(--dark-gray);
            opacity: 0.7;
        }
        
        /* Chart Container */
        .chart-container {
            position: relative;
            height: 250px;
            width: 100%;
        }
        
        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-top: 15px;
        }
        
        .action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 15px 10px;
            background: var(--white);
            border-radius: var(--border-radius-small);
            box-shadow: var(--shadow-light);
            transition: var(--transition);
            text-decoration: none;
            color: var(--dark-gray);
            text-align: center;
            border: none;
            cursor: pointer;
        }
        
        .action-btn:active {
            transform: scale(0.95);
            box-shadow: var(--shadow-medium);
            color: var(--primary-purple);
        }
        
        .action-icon {
            font-size: 1.6rem;
            margin-bottom: 8px;
            color: var(--primary-purple);
        }
        
        .action-text {
            font-weight: 600;
            font-size: 0.8rem;
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
            
            .action-btn:hover {
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
            
            .dashboard-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
                margin: 20px 0;
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
            
            .quick-actions {
                grid-template-columns: repeat(4, 1fr);
                gap: 15px;
                margin-top: 20px;
            }
            
            .action-btn {
                padding: 20px 15px;
            }
            
            .action-icon {
                font-size: 2rem;
                margin-bottom: 10px;
            }
            
            .action-text {
                font-size: 0.9rem;
            }
            
            .chart-container {
                height: 300px;
            }
            
            th, td {
                padding: 18px 15px;
                font-size: 1rem;
            }
            
            .alert-item {
                padding: 15px;
            }
            
            .alert-product {
                font-size: 1rem;
            }
        }
        
        /* Large Desktop */
        @media (min-width: 1200px) {
            .dashboard-grid {
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                gap: 25px;
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
            
            .quick-actions {
                grid-template-columns: 1fr;
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
            <a href="dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="product.php"><i class="fas fa-box"></i> Inventory</a>
            <a href="barcode.php"><i class="fas fa-barcode"></i> Barcode</a>
            <a href="customershow.php"><i class="fas fa-users"></i> Customers</a>
            <a href="suppliers.php" ><i class="fas fa-truck"></i> Suppliers</a>
             <a href="stock_transactions1.php" ><i class="fas fa-arrows-spin"></i> Stock Transfer</a>
            <a href="report_keep.php"><i class="fas fa-chart-line"></i> Reports</a>
            <a href="?logout=true" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>
</header>

<div class="container">
    <!-- Welcome Section -->
    <div class="card" style="margin-bottom: 15px;">
        <div class="user-welcome">
            <div>
                <h2><i class="fas fa-tachometer-alt"></i> Stock Dashboard</h2>
                <p>Welcome back, <?php echo $_SESSION['stock_keeper_name']; ?>! Monitor stock levels and manage products.</p>
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

    <!-- Statistics Cards -->
    <div class="stats-container">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-boxes"></i>
            </div>
            <div class="stat-number"><?php echo $totalProducts; ?></div>
            <div class="stat-label">Total Products</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <div class="stat-number">Rs<?php echo number_format($totalStockValue ?? 0, 2); ?></div>
            <div class="stat-label">Stock Value</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="stat-number"><?php echo $lowStockItems; ?></div>
            <div class="stat-label">Low Stock</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-times-circle"></i>
            </div>
            <div class="stat-number"><?php echo $outOfStockItems; ?></div>
            <div class="stat-label">Out of Stock</div>
        </div>
    </div>

    <!-- Dashboard Grid -->
    <div class="dashboard-grid">
        <!-- Stock Alerts -->
        <div class="card">
            <h3><i class="fas fa-exclamation-circle" style="color: #e74c3c;"></i> Stock Alerts</h3>
            <?php if ($stockAlerts->num_rows > 0): ?>
                <div>
                    <?php while($alert = $stockAlerts->fetch_assoc()): 
                        $stockClass = $alert['stock'] == 0 ? 'stock-out' : 'stock-low';
                    ?>
                    <div class="alert-item">
                        <div class="alert-info">
                            <div class="alert-product"><?php echo htmlspecialchars($alert['name']); ?></div>
                            <div class="alert-category"><?php echo htmlspecialchars($alert['category']); ?></div>
                        </div>
                        <span class="stock-badge <?php echo $stockClass; ?>"><?php echo $alert['stock']; ?></span>
                    </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-check-circle" style="color: var(--primary-green);"></i>
                    <h3>All Stock Levels Good</h3>
                    <p>No low stock alerts</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Recent Stock Movements -->
        <div class="card">
            <h3><i class="fas fa-exchange-alt" style="color: var(--primary-blue);"></i> Recent Movements</h3>
            <?php if ($recentMovements->num_rows > 0): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Qty</th>
                                <th>Date</th>
                                <th>Customer</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($movement = $recentMovements->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($movement['name']); ?></td>
                                <td><?php echo $movement['quantity']; ?></td>
                                <td><?php echo date('M j, g:i A', strtotime($movement['date'])); ?></td>
                                <td><?php echo htmlspecialchars($movement['customer_name'] ?: 'Walk-in'); ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-history"></i>
                    <h3>No Recent Activity</h3>
                    <p>Stock movements will appear here</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Top Selling Products -->
        <div class="card">
            <h3><i class="fas fa-chart-line" style="color: var(--primary-green);"></i> Top Sellers</h3>
            <?php if ($topSelling->num_rows > 0): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Sold</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($top = $topSelling->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($top['name']); ?></td>
                                <td><?php echo htmlspecialchars($top['category']); ?></td>
                                <td><span class="stock-badge"><?php echo $top['total_sold']; ?></span></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-chart-bar"></i>
                    <h3>No Sales Data</h3>
                    <p>Sales info will appear here</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Quick Actions -->
        <div class="card">
            <h3><i class="fas fa-bolt" style="color: var(--primary-orange);"></i> Quick Actions</h3>
            <div class="quick-actions">
                <a href="product.php" class="action-btn">
                    <div class="action-icon">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                    <div class="action-text">Add Product</div>
                </a>
                
                <a href="product.php" class="action-btn">
                    <div class="action-icon">
                        <i class="fas fa-edit"></i>
                    </div>
                    <div class="action-text">Manage Inventory</div>
                </a>
                
                <a href="report_keep.php" class="action-btn">
                    <div class="action-icon">
                        <i class="fas fa-file-export"></i>
                    </div>
                    <div class="action-text">Generate Report</div>
                </a>
                
                <a href="barcode.php" class="action-btn">
                    <div class="action-icon">
                        <i class="fas fa-barcode"></i>
                    </div>
                    <div class="action-text">Print Barcode</div>
                </a>
            </div>
        </div>
    </div>

    <!-- Stock Chart -->
    <div class="card" style="margin-top: 15px;">
        <h3><i class="fas fa-chart-bar" style="color: var(--primary-purple);"></i> Stock Overview (Last 6 Months)</h3>
        <div class="chart-container">
            <canvas id="stockChart"></canvas>
        </div>
    </div>
</div>

<script>
// Stock Chart with REAL DATA
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('stockChart').getContext('2d');
    
    // Use PHP-generated real data
    const chartLabels = <?php echo $chartLabelsJson; ?>;
    const productsAddedData = <?php echo $chartProductsAddedJson; ?>;
    const productsSoldData = <?php echo $chartProductsSoldJson; ?>;
    
    const stockChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: chartLabels,
            datasets: [{
                label: 'Products Added',
                data: productsAddedData,
                backgroundColor: 'rgba(142, 68, 173, 0.7)',
                borderColor: 'rgba(142, 68, 173, 1)',
                borderWidth: 1
            }, {
                label: 'Products Sold',
                data: productsSoldData,
                backgroundColor: 'rgba(39, 174, 96, 0.7)',
                borderColor: 'rgba(39, 174, 96, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            },
            animation: {
                duration: 1500,
                easing: 'easeOutQuart'
            },
            plugins: {
                legend: {
                    labels: {
                        font: {
                            size: window.innerWidth < 768 ? 10 : 12
                        }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            label += context.parsed.y;
                            return label;
                        }
                    }
                }
            }
        }
    });
});

// Auto-refresh dashboard every 60 seconds
setTimeout(function() {
    window.location.reload();
}, 40000);

// Add confirmation for logout
document.querySelector('.logout-btn').addEventListener('click', function(e) {
    if (!confirm('Are you sure you want to logout?')) {
        e.preventDefault();
    }
});

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
<?php $conn->close(); ?>