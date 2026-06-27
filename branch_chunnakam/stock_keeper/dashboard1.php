<?php
// File: dashboard1.php - COMPLETE REDESIGN with fresh, attractive layout matching barcode1.php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['stock_keeper_id'])) {
    header("Location: ../index.php");
    exit();
}

// Handle logout
if (isset($_GET['logout'])) {
    unset($_SESSION['stock_keeper_id']);
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
$totalProducts = $conn->query("SELECT COUNT(*) as total FROM products2")->fetch_assoc()['total'];
$totalStockValue = $conn->query("SELECT COALESCE(SUM(stock * sale_price), 0) as value FROM products2")->fetch_assoc()['value'];
$lowStockItems = $conn->query("SELECT COUNT(*) as low FROM products2 WHERE stock < 10")->fetch_assoc()['low'];
$outOfStockItems = $conn->query("SELECT COUNT(*) as out_of_stock FROM products2 WHERE stock = 0")->fetch_assoc()['out_of_stock'];

// Get recent stock movements
$recentMovements = $conn->query("
    SELECT p.name, bi.quantity, b.date, b.bill_no, c.name as customer_name 
    FROM bill_items2 bi 
    JOIN bills b ON bi.bill_no = b.bill_no 
    JOIN products2 p ON bi.product_id = p.product_id 
    LEFT JOIN customers2 c ON b.phone_no = c.phone_no 
    ORDER BY b.date DESC 
    LIMIT 10
");

// Get top selling products
$topSelling = $conn->query("
    SELECT p.name, p.category, SUM(bi.quantity) as total_sold 
    FROM bill_items2 bi 
    JOIN products2 p ON bi.product_id = p.product_id 
    GROUP BY p.product_id 
    ORDER BY total_sold DESC 
    LIMIT 5
");

// Get stock alerts
$stockAlerts = $conn->query("
    SELECT name, stock, category 
    FROM products2
    WHERE stock < 10 
    ORDER BY stock ASC 
    LIMIT 10
");

// ============ REAL CHART DATA ============
// Get monthly sales data
$monthlySales = $conn->query("
    SELECT 
        MONTH(b.date) as month,
        SUM(bi.quantity) as total_sold,
        COUNT(DISTINCT b.bill_no) as total_orders
    FROM bill_items2 bi
    JOIN bills b ON bi.bill_no = b.bill_no
    WHERE YEAR(b.date) = YEAR(CURDATE())
    GROUP BY MONTH(b.date)
    ORDER BY month
");

// Get category distribution
$categoryDistribution = $conn->query("
    SELECT category, COUNT(*) as count 
    FROM products2 
    WHERE category IS NOT NULL AND category != '' 
    GROUP BY category 
    ORDER BY count DESC 
    LIMIT 6
");

// Get recent products added (last 5)
$recentProducts = $conn->query("
    SELECT product_id, name, stock, sale_price, photo 
    FROM products2 
    ORDER BY product_id DESC 
    LIMIT 5
");

// Prepare chart data
$months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$productsSoldData = array_fill(0, 12, 0);
$ordersData = array_fill(0, 12, 0);

// Fill products sold data and orders data
while ($row = $monthlySales->fetch_assoc()) {
    $monthIndex = $row['month'] - 1;
    if ($monthIndex >= 0 && $monthIndex < 12) {
        $productsSoldData[$monthIndex] = $row['total_sold'];
        $ordersData[$monthIndex] = $row['total_orders'];
    }
}

// Get current month for chart display (show last 6 months)
$currentMonth = date('n') - 1; // 0-based index
$startMonth = ($currentMonth - 5 + 12) % 12;

$chartLabels = [];
$chartProductsSold = [];
$chartOrders = [];

for ($i = 0; $i < 6; $i++) {
    $monthIndex = ($startMonth + $i) % 12;
    $chartLabels[] = $months[$monthIndex];
    $chartProductsSold[] = $productsSoldData[$monthIndex];
    $chartOrders[] = $ordersData[$monthIndex];
}

// Category data for pie chart
$categoryLabels = [];
$categoryCounts = [];
$categoryColors = ['#2c5e2e', '#1a4d1c', '#3e7c41', '#27632a', '#5b8c5e', '#8aae8c'];

while ($cat = $categoryDistribution->fetch_assoc()) {
    $categoryLabels[] = $cat['category'];
    $categoryCounts[] = $cat['count'];
}

// Convert to JSON for JavaScript
$chartLabelsJson = json_encode($chartLabels);
$chartProductsSoldJson = json_encode($chartProductsSold);
$chartOrdersJson = json_encode($chartOrders);
$categoryLabelsJson = json_encode($categoryLabels);
$categoryCountsJson = json_encode($categoryCounts);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>🌿 Dashboard · KidsBerry Mega Centre</title>
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Google Fonts - Modern & Clean -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ----------------------------------------------------------------------
           DARK GREEN & WHITE · MODERN · TRENDY · FULLY RESPONSIVE
           - Clean typography, balanced spacing, glass elements, subtle shadows
           - Consistent with brand: KidsBerry Mega Centre
        ----------------------------------------------------------------------- */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --dark-green: #1a3b2f;
            --forest-green: #2c5e2e;
            --sage: #8aae8c;
            --light-mint: #eaf7ea;
            --pure-white: #ffffff;
            --off-white: #f9fbf7;
            --charcoal: #2c3e2f;
            --soft-gray: #f0f2ee;
            --shadow-sm: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.02);
            --shadow-md: 0 20px 30px -12px rgba(26, 59, 47, 0.12);
            --radius-card: 24px;
            --radius-element: 16px;
            --transition-smooth: all 0.3s cubic-bezier(0.2, 0.9, 0.4, 1.1);
        }

        body {
            font-family: 'Inter', 'Outfit', system-ui, -apple-system, sans-serif;
            background: var(--off-white);
            color: #1e2a24;
            line-height: 1.5;
            min-height: 100vh;
        }

        /* Typography - balanced, not oversized */
        h1, h2, h3, .card-title, .logo {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px 24px;
        }

        /* ===== HEADER ===== */
        header {
            background: var(--dark-green);
            color: white;
            padding: 0.7rem 0;
            position: sticky;
            top: 0;
            z-index: 100;
            backdrop-filter: blur(2px);
            border-bottom: 1px solid rgba(138, 174, 140, 0.3);
        }
        .menu {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .logo {
            font-size: 1.65rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .logo i {
            color: var(--sage);
            font-size: 1.7rem;
        }
        .logo span {
            font-size: 0.9rem;
            font-weight: 500;
            background: rgba(255,255,255,0.15);
            padding: 2px 10px;
            border-radius: 40px;
            margin-left: 8px;
        }
        .nav-links {
            display: flex;
            gap: 0.3rem;
            flex-wrap: wrap;
        }
        .nav-links a {
            color: white;
            text-decoration: none;
            padding: 8px 18px;
            border-radius: 40px;
            font-weight: 500;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.05);
            transition: var(--transition-smooth);
        }
        .nav-links a:hover, .nav-links a.active {
            background: white;
            color: var(--dark-green);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        .logout-btn {
            background: rgba(255,255,255,0.15) !important;
        }
        .logout-btn:hover {
            background: #ff5e4a !important;
            color: white !important;
        }

        /* Welcome card - minimal glass */
        .welcome-card {
            background: var(--pure-white);
            border-radius: var(--radius-card);
            padding: 22px 28px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            border: 1px solid rgba(44, 94, 46, 0.15);
        }
        .welcome-title {
            font-size: 1.7rem;
            font-weight: 800;
            color: var(--dark-green);
        }
        .welcome-subtitle {
            color: #4a5b52;
            font-size: 0.95rem;
            margin-top: 6px;
        }
        .user-profile {
            display: flex;
            align-items: center;
            gap: 14px;
            background: var(--soft-gray);
            padding: 8px 20px;
            border-radius: 60px;
        }
        .user-avatar {
            width: 48px;
            height: 48px;
            background: var(--forest-green);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.3rem;
        }
        .user-name {
            font-weight: 800;
            color: var(--dark-green);
        }
        .user-role {
            font-size: 0.75rem;
            background: var(--light-mint);
            padding: 4px 12px;
            border-radius: 30px;
            display: inline-block;
            margin-top: 4px;
        }

        /* Stats Grid - balanced cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 32px;
        }
        .stat-card {
            background: var(--pure-white);
            border-radius: var(--radius-card);
            padding: 20px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition-smooth);
            display: flex;
            align-items: center;
            gap: 16px;
            border: 1px solid #eef2e8;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
            border-color: var(--sage);
        }
        .stat-icon-wrapper {
            width: 56px;
            height: 56px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
        }
        .stat-icon-teal { background: rgba(44, 94, 46, 0.1); color: var(--forest-green); }
        .stat-icon-coral { background: rgba(44, 94, 46, 0.08); color: var(--dark-green); }
        .stat-icon-navy { background: rgba(26, 59, 47, 0.08); color: #2c5e2e; }
        .stat-icon-gold { background: rgba(138, 174, 140, 0.15); color: #3e7c41; }
        .stat-value {
            font-size: 1.9rem;
            font-weight: 800;
            line-height: 1.2;
            color: var(--dark-green);
        }
        .stat-label {
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #5a6e60;
        }
        .stat-trend {
            font-size: 0.7rem;
            margin-top: 6px;
            color: var(--forest-green);
        }

        /* Cards */
        .card {
            background: var(--pure-white);
            border-radius: var(--radius-card);
            padding: 24px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition-smooth);
            border: 1px solid #ecf3e8;
            height: 100%;
        }
        .card:hover {
            box-shadow: var(--shadow-md);
            border-color: var(--sage);
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
            border-bottom: 2px solid var(--light-mint);
            padding-bottom: 12px;
        }
        .card-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--dark-green);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .card-title i {
            color: var(--forest-green);
            font-size: 1.2rem;
        }
        .card-link {
            color: var(--forest-green);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.85rem;
            padding: 6px 14px;
            border-radius: 30px;
            background: var(--light-mint);
            transition: 0.2s;
        }
        .card-link:hover {
            background: var(--forest-green);
            color: white;
        }

        /* Alert items */
        .alert-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .alert-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px;
            background: var(--off-white);
            border-radius: 20px;
            border-left: 6px solid;
            transition: 0.2s;
            cursor: pointer;
        }
        .alert-item.stock-low { border-left-color: #e6a157; }
        .alert-item.stock-out { border-left-color: #d9534f; }
        .alert-product {
            font-weight: 700;
            color: var(--dark-green);
        }
        .alert-badge {
            padding: 6px 16px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 0.9rem;
            background: var(--forest-green);
            color: white;
        }
        .badge-low { background: #e6a157; }
        .badge-out { background: #d9534f; }

        /* Tables - modern minimal */
        .table-container {
            overflow-x: auto;
            border-radius: 18px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            text-align: left;
            padding: 14px 10px;
            background: var(--light-mint);
            color: var(--dark-green);
            font-weight: 700;
            font-size: 0.8rem;
        }
        td {
            padding: 12px 10px;
            border-bottom: 1px solid #eef2e8;
            font-size: 0.9rem;
        }
        tr:hover td {
            background: var(--off-white);
        }
        .stock-badge {
            background: var(--forest-green);
            color: white;
            padding: 4px 12px;
            border-radius: 40px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        /* Recent Products */
        .recent-products {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .recent-product-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 10px;
            background: var(--off-white);
            border-radius: 18px;
            transition: 0.2s;
            cursor: pointer;
        }
        .recent-product-item:hover {
            background: var(--light-mint);
        }
        .product-thumb {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            background: var(--sage);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.3rem;
        }
        .product-title {
            font-weight: 700;
            color: var(--dark-green);
        }
        .product-meta {
            display: flex;
            gap: 12px;
            margin-top: 4px;
            font-size: 0.75rem;
        }
        .product-price {
            color: var(--forest-green);
            font-weight: 700;
        }

        /* Charts row */
        .chart-row {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
            margin: 24px 0;
        }
        .chart-container, .pie-chart-container {
            position: relative;
            height: 260px;
            width: 100%;
            margin-top: 10px;
        }

        /* Quick actions */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
        }
        .action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 16px;
            background: var(--off-white);
            border-radius: 20px;
            text-decoration: none;
            color: var(--dark-green);
            font-weight: 600;
            transition: var(--transition-smooth);
            text-align: center;
            border: 1px solid #eef2e8;
        }
        .action-btn:hover {
            background: var(--light-mint);
            transform: translateY(-3px);
            border-color: var(--sage);
        }
        .action-icon {
            font-size: 1.8rem;
            margin-bottom: 8px;
            color: var(--forest-green);
        }
        .action-text {
            font-size: 0.85rem;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 30px 16px;
            color: #6f8675;
        }
        .empty-state i {
            font-size: 2.5rem;
            color: var(--sage);
            margin-bottom: 12px;
        }

        /* FAB (mobile) */
        .fab {
            position: fixed;
            bottom: 24px;
            right: 24px;
            width: 56px;
            height: 56px;
            background: var(--forest-green);
            border-radius: 50%;
            display: none;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.6rem;
            box-shadow: 0 8px 20px rgba(44, 94, 46, 0.3);
            z-index: 99;
            cursor: pointer;
            transition: 0.2s;
        }

        /* Responsive */
        @media (max-width: 1100px) {
            .stats-grid { grid-template-columns: repeat(2,1fr); }
        }
        @media (max-width: 900px) {
            .chart-row { grid-template-columns: 1fr; gap: 20px; }
            .quick-actions-grid { grid-template-columns: repeat(2,1fr); }
        }
        @media (max-width: 768px) {
            .container { padding: 16px; }
            .menu { flex-direction: column; align-items: stretch; }
            .nav-links { justify-content: center; overflow-x: auto; padding-bottom: 5px; }
            .stats-grid { grid-template-columns: 1fr; }
            .welcome-card { flex-direction: column; align-items: flex-start; }
            .fab { display: flex; }
            .card-title { font-size: 1.1rem; }
            .stat-value { font-size: 1.6rem; }
        }
        @media (max-width: 480px) {
            .quick-actions-grid { grid-template-columns: 1fr; }
            .stat-card { padding: 16px; }
        }

        /* small animations */
        .fadeInUp {
            animation: fadeUp 0.4s ease-out;
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

<header>
    <div class="container menu">
        <div class="logo">
            <i class="fas fa-seedling"></i> KidsBerry Mega Centre
            <span>Mega Centre</span>
        </div>
        <div class="nav-links">
            <a href="dashboard1.php" class="active"><i class="fas fa-chart-pie"></i> Dashboard</a>
            <a href="product1.php"><i class="fas fa-cubes"></i> Inventory</a>
            <a href="stock_transactions.php"><i class="fas fa-arrows-spin"></i> Movements</a>
            <a href="barcode1.php"><i class="fas fa-qrcode"></i> Barcode</a>
            <a href="suppliers.php"><i class="fas fa-parachute-box"></i> Suppliers</a>
            <a href="?logout=true" class="logout-btn"><i class="fas fa-arrow-right-from-bracket"></i> Logout</a>
        </div>
    </div>
</header>

<div class="container">
    <!-- Welcome Section -->
    <div class="welcome-card fadeInUp">
        <div>
            <div class="welcome-title">
                <i class="fas fa-chart-line" style="color: var(--forest-green);"></i> Stock Dashboard
            </div>
            <div class="welcome-subtitle">
                Real-time insights · Manage inventory effortlessly
            </div>
        </div>
        <div class="user-profile">
            <div class="user-avatar">
                <i class="fas fa-user-astronaut"></i>
            </div>
            <div>
                <div class="user-name"><?php echo htmlspecialchars($_SESSION['stock_keeper_name'] ?? 'Keeper'); ?></div>
                <div class="user-role">Stock Manager</div>
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-grid fadeInUp">
        <div class="stat-card">
            <div class="stat-icon-wrapper stat-icon-teal"><i class="fas fa-boxes"></i></div>
            <div><div class="stat-value"><?php echo number_format($totalProducts); ?></div><div class="stat-label">Total Products</div><div class="stat-trend">Active inventory</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon-wrapper stat-icon-coral"><i class="fas fa-rupee-sign"></i></div>
            <div><div class="stat-value">LKR <?php echo number_format($totalStockValue ?? 0, 0); ?></div><div class="stat-label">Stock Value</div><div class="stat-trend">Total assets</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon-wrapper stat-icon-navy"><i class="fas fa-exclamation-triangle"></i></div>
            <div><div class="stat-value"><?php echo $lowStockItems; ?></div><div class="stat-label">Low Stock</div><div class="stat-trend">&lt;10 units</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon-wrapper stat-icon-gold"><i class="fas fa-circle-xmark"></i></div>
            <div><div class="stat-value"><?php echo $outOfStockItems; ?></div><div class="stat-label">Out of Stock</div><div class="stat-trend">Need reorder</div></div>
        </div>
    </div>

    <!-- Grid 2 columns -->
    <div class="dashboard-grid" style="display: grid; grid-template-columns: repeat(2,1fr); gap: 24px; margin-bottom: 24px;">
        <!-- Stock Alerts -->
        <div class="card fadeInUp">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-bell"></i> Stock Alerts</div>
                <a href="product1.php?filter=lowstock" class="card-link">View all <i class="fas fa-arrow-right"></i></a>
            </div>
            <?php if ($stockAlerts && $stockAlerts->num_rows > 0): ?>
                <div class="alert-list">
                    <?php while($alert = $stockAlerts->fetch_assoc()): 
                        $stockClass = $alert['stock'] == 0 ? 'stock-out' : 'stock-low';
                        $badgeClass = $alert['stock'] == 0 ? 'badge-out' : 'badge-low';
                    ?>
                    <div class="alert-item <?php echo $stockClass; ?>" onclick="window.location='product1.php?search=<?php echo urlencode($alert['name']); ?>'">
                        <div><div class="alert-product"><?php echo htmlspecialchars($alert['name']); ?></div><div class="alert-category" style="font-size:0.7rem;"><?php echo htmlspecialchars($alert['category'] ?: 'General'); ?></div></div>
                        <div class="alert-badge <?php echo $badgeClass; ?>"><?php echo $alert['stock']; ?> left</div>
                    </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="empty-state"><i class="fas fa-check-circle"></i><h3>All good</h3><p>Stock levels are healthy</p></div>
            <?php endif; ?>
        </div>

        <!-- Recent Products -->
        <div class="card fadeInUp">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-clock"></i> Recently Added</div>
                <a href="product1.php" class="card-link">All items <i class="fas fa-arrow-right"></i></a>
            </div>
            <?php if ($recentProducts && $recentProducts->num_rows > 0): ?>
                <div class="recent-products">
                    <?php while($product = $recentProducts->fetch_assoc()): ?>
                    <div class="recent-product-item" onclick="window.location='product1.php?edit=<?php echo $product['product_id']; ?>'">
                        <div class="product-thumb"><?php if(!empty($product['photo'])): ?><img src="<?php echo htmlspecialchars($product['photo']); ?>" style="width:100%;height:100%;object-fit:cover;border-radius:16px;"><?php else: ?><i class="fas fa-cube"></i><?php endif; ?></div>
                        <div><div class="product-title"><?php echo htmlspecialchars(substr($product['name'],0,22)); ?></div><div class="product-meta"><span class="product-price">LKR <?php echo number_format($product['sale_price'],2); ?></span><span><i class="fas fa-cubes"></i> <?php echo $product['stock']; ?></span></div></div>
                        <i class="fas fa-chevron-right" style="margin-left:auto; color:var(--sage);"></i>
                    </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="empty-state"><i class="fas fa-box-open"></i><h3>No products yet</h3><p>Start adding items</p></div>
            <?php endif; ?>
        </div>

        <!-- Top Sellers -->
        <div class="card fadeInUp">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-trophy"></i> Top Sellers</div>
                <a href="report_keep1.php" class="card-link">Reports <i class="fas fa-arrow-right"></i></a>
            </div>
            <?php if ($topSelling && $topSelling->num_rows > 0): ?>
                <div class="table-container">
                    <table>
                        <thead><tr><th>Product</th><th>Category</th><th>Sold</th></tr></thead>
                        <tbody><?php $rank=1; while($top = $topSelling->fetch_assoc()): ?>
                            <tr><td><span style="background:var(--light-mint); padding:2px 8px; border-radius:30px; margin-right:8px;">#<?php echo $rank++; ?></span> <?php echo htmlspecialchars(substr($top['name'],0,18)); ?></td>
                            <td><span style="background:var(--sage); color:white; padding:2px 12px; border-radius:40px; font-size:0.7rem;"><?php echo htmlspecialchars($top['category']?:'General'); ?></span></td>
                            <td><span class="stock-badge"><?php echo number_format($top['total_sold']); ?></span></td></tr>
                        <?php endwhile; ?></tbody>
                    </table>
                </div>
            <?php else: ?><div class="empty-state"><i class="fas fa-chart-simple"></i><h3>No sales data</h3></div><?php endif; ?>
        </div>

        <!-- Recent Movements -->
        <div class="card fadeInUp">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-arrows-spin"></i> Recent Movements</div>
                <a href="report_keep1.php" class="card-link">Details</a>
            </div>
            <?php if ($recentMovements && $recentMovements->num_rows > 0): ?>
                <div class="table-container">
                    <table>
                        <thead><tr><th>Product</th><th>Qty</th><th>Time</th></tr></thead>
                        <tbody><?php while($mov = $recentMovements->fetch_assoc()): ?>
                            <tr><td><?php echo htmlspecialchars(substr($mov['name'],0,14)); ?></td><td style="font-weight:700;"><?php echo $mov['quantity']; ?></td><td style="font-size:0.75rem;"><?php echo date('h:i A', strtotime($mov['date'])); ?></td></tr>
                        <?php endwhile; ?></tbody>
                    </table>
                </div>
            <?php else: ?><div class="empty-state"><i class="fas fa-history"></i><h3>No activity yet</h3></div><?php endif; ?>
        </div>
    </div>

    <!-- Charts row -->
    <div class="chart-row fadeInUp">
        <div class="card">
            <div class="card-header"><div class="card-title"><i class="fas fa-chart-line"></i> Sales Overview</div><span style="background:var(--light-mint); padding:4px 12px; border-radius:30px;">Last 6 months</span></div>
            <div class="chart-container"><canvas id="salesChart"></canvas></div>
            <div style="display:flex; justify-content:center; gap:20px; margin-top:12px;"><span><span style="background:var(--forest-green); display:inline-block; width:12px; height:12px; border-radius:4px;"></span> Products Sold</span> <span><span style="background:#8aae8c; display:inline-block; width:12px; height:12px; border-radius:4px;"></span> Orders</span></div>
        </div>
        <div class="card">
            <div class="card-header"><div class="card-title"><i class="fas fa-chart-pie"></i> Category Distribution</div><span><?php echo array_sum($categoryCounts); ?> items</span></div>
            <?php if (!empty($categoryLabels)): ?>
                <div class="pie-chart-container"><canvas id="categoryChart"></canvas></div>
                <div style="display:flex; flex-wrap:wrap; gap:8px; justify-content:center; margin-top:16px;"><?php foreach($categoryLabels as $idx=>$label): ?><span style="font-size:0.7rem;"><span style="display:inline-block; width:10px; height:10px; background:<?php echo $categoryColors[$idx%count($categoryColors)]; ?>; border-radius:2px;"></span> <?php echo htmlspecialchars($label); ?> (<?php echo $categoryCounts[$idx]; ?>)</span><?php endforeach; ?></div>
            <?php else: ?><div class="empty-state"><i class="fas fa-chart-pie"></i><h3>No categories</h3></div><?php endif; ?>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="card fadeInUp" style="margin-top: 8px;">
        <div class="card-header"><div class="card-title"><i class="fas fa-bolt"></i> Quick Actions</div><span style="color:var(--forest-green);">Instant tools</span></div>
        <div class="quick-actions-grid">
            <a href="product1.php?action=add" class="action-btn"><div class="action-icon"><i class="fas fa-plus-circle"></i></div><div class="action-text">Add Product</div></a>
            <a href="barcode1.php" class="action-btn"><div class="action-icon"><i class="fas fa-qrcode"></i></div><div class="action-text">Barcode</div></a>
            <a href="report_keep1.php" class="action-btn"><div class="action-icon"><i class="fas fa-file-pdf"></i></div><div class="action-text">Export Report</div></a>
            <a href="suppliers.php" class="action-btn"><div class="action-icon"><i class="fas fa-truck"></i></div><div class="action-text">Suppliers</div></a>
        </div>
    </div>
</div>

<div class="fab" id="fabToggle"><i class="fas fa-arrow-up"></i></div>

<script>
(function() {
    // Sales chart
    const salesCtx = document.getElementById('salesChart')?.getContext('2d');
    if(salesCtx) {
        new Chart(salesCtx, {
            type: 'bar',
            data: {
                labels: <?php echo $chartLabelsJson; ?>,
                datasets: [
                    { label: 'Products Sold', data: <?php echo $chartProductsSoldJson; ?>, backgroundColor: '#2c5e2e', borderRadius: 10, barPercentage: 0.7 },
                    { label: 'Orders', data: <?php echo $chartOrdersJson; ?>, backgroundColor: '#8aae8c', borderRadius: 10, barPercentage: 0.7 }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: '#eef2e8' } }, x: { grid: { display: false } } } }
        });
    }
    // Pie chart
    const catCtx = document.getElementById('categoryChart')?.getContext('2d');
    if(catCtx && <?php echo json_encode(!empty($categoryLabels)); ?>) {
        new Chart(catCtx, {
            type: 'doughnut',
            data: { labels: <?php echo $categoryLabelsJson; ?>, datasets: [{ data: <?php echo $categoryCountsJson; ?>, backgroundColor: ['#2c5e2e','#1a4d1c','#3e7c41','#5b8c5e','#8aae8c','#a7c5a9'], borderWidth: 0, hoverOffset: 6 }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: '65%', plugins: { legend: { display: false } } }
        });
    }

    // Logout confirmation
    document.querySelector('.logout-btn')?.addEventListener('click', function(e) {
        e.preventDefault();
        Swal.fire({ title: 'Logout?', text: 'Are you sure?', icon: 'question', showCancelButton: true, confirmButtonColor: '#1a3b2f', cancelButtonColor: '#d9534f', confirmButtonText: 'Yes' }).then(r => r.isConfirmed && (window.location.href = '?logout=true'));
    });
    // FAB scroll top
    document.getElementById('fabToggle')?.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
    // Auto refresh after 5 min
    setTimeout(() => window.location.reload(), 300000);
})();
</script>
<?php $conn->close(); ?>
</body>
</html>