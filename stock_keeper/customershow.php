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

// Simple search
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$sql = "SELECT * FROM customers WHERE name LIKE '%$search%' OR phone_no LIKE '%$search%' OR nic_no LIKE '%$search%' OR email LIKE '%$search%' ORDER BY created_at DESC";
$result = $conn->query($sql);

// Calculate statistics
$totalCustomers = 0;
$premiumCustomers = 0;
$newThisMonth = 0;

// Get total customers count
$countSql = "SELECT COUNT(*) as total FROM customers";
$countResult = $conn->query($countSql);
if ($countResult) {
    $totalCustomers = $countResult->fetch_assoc()['total'];
}

// Get premium customers count (gold, platinum, diamond)
$premiumSql = "SELECT COUNT(*) as premium FROM customers WHERE LOWER(membership_type) IN ('gold', 'platinum', 'diamond')";
$premiumResult = $conn->query($premiumSql);
if ($premiumResult) {
    $premiumCustomers = $premiumResult->fetch_assoc()['premium'];
}

// Get new customers this month
$newMonthSql = "SELECT COUNT(*) as new_this_month FROM customers WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())";
$newMonthResult = $conn->query($newMonthSql);
if ($newMonthResult) {
    $newThisMonth = $newMonthResult->fetch_assoc()['new_this_month'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Customers - Kids Berry</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
        
        .logout-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s;
        }
        
        .logout-btn:hover::before {
            left: 100%;
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
        
        /* Search Box */
        .search-container {
            position: relative;
            width: 100%;
            max-width: 700px;
            margin: 20px auto;
        }
        
        .search-box {
            width: 100%;
            padding: 16px 25px 16px 50px;
            border-radius: 50px;
            border: none;
            font-size: 1rem;
            box-shadow: var(--shadow-light);
            outline: none;
            background: var(--white);
            transition: var(--transition);
            border: 2px solid transparent;
        }
        
        .search-box:focus {
            box-shadow: var(--shadow-medium);
            border-color: var(--primary-purple);
        }
        
        .search-icon {
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary-purple);
            font-size: 1.1rem;
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
        
        .stat-number {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 5px;
            line-height: 1;
        }
        
        .stat-card:nth-child(1) .stat-number { color: var(--primary-purple); }
        .stat-card:nth-child(2) .stat-number { color: var(--primary-green); }
        .stat-card:nth-child(3) .stat-number { color: var(--primary-blue); }
        
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
            min-width: 600px;
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
        
        /* Membership Badges */
        .membership-badge {
            padding: 6px 12px;
            border-radius: 20px;
            color: var(--white);
            font-weight: bold;
            font-size: 0.75rem;
            display: inline-block;
            text-align: center;
            min-width: 70px;
            box-shadow: var(--shadow-light);
            transition: var(--transition);
        }
        
        .membership-badge:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }
        
        .regular { 
            background: linear-gradient(135deg, #95a5a6, #7f8c8d);
        }
        .silver { 
            background: linear-gradient(135deg, #bdc3c7, #95a5a6);
        }
        .gold { 
            background: linear-gradient(135deg, #f1c40f, #f39c12);
        }
        .platinum { 
            background: var(--gradient-secondary);
        }
        .diamond { 
            background: linear-gradient(135deg, #3498db, #2980b9);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 15px;
            color: var(--dark-gray);
        }
        
        .empty-state i {
            font-size: 3rem;
            color: var(--light-purple);
            margin-bottom: 12px;
        }
        
        .empty-state h3 {
            font-size: 1.1rem;
            margin-bottom: 8px;
            color: var(--primary-purple);
            justify-content: center;
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Customer Details Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            backdrop-filter: blur(5px);
        }
        
        .modal-content {
            background: var(--white);
            margin: 10% auto;
            padding: 25px;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 500px;
            box-shadow: var(--shadow-heavy);
            animation: slideIn 0.3s ease-out;
            position: relative;
        }
        
        .close-modal {
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 1.5rem;
            color: var(--dark-gray);
            cursor: pointer;
            transition: var(--transition);
        }
        
        .close-modal:hover {
            color: var(--primary-purple);
            transform: rotate(90deg);
        }
        
        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        /* Filter Buttons */
        .filter-buttons {
            display: flex;
            gap: 8px;
            margin-bottom: 15px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .filter-btn {
            padding: 8px 16px;
            border: none;
            border-radius: var(--border-radius-small);
            background: var(--light-gray);
            color: var(--dark-gray);
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
            font-size: 0.8rem;
        }
        
        .filter-btn.active, .filter-btn:active {
            background: var(--gradient-primary);
            color: var(--white);
            transform: translateY(-2px);
        }
        
        /* Mobile table styles */
        @media (max-width: 768px) {
            table, thead, tbody, th, td, tr { 
                display: block; 
            }
            
            thead tr { 
                position: absolute; 
                top: -9999px; 
                left: -9999px; 
            }
            
            tr { 
                border: 1px solid var(--medium-gray); 
                border-radius: var(--border-radius);
                margin-bottom: 15px; 
                padding: 15px;
                background: var(--white);
                box-shadow: var(--shadow-light);
            }
            
            td { 
                border: none; 
                position: relative; 
                padding-left: 50%; 
                text-align: right;
                padding: 10px 15px;
                border-bottom: 1px solid var(--medium-gray);
            }
            
            td:last-child {
                border-bottom: none;
            }
            
            td:before { 
                content: attr(data-label); 
                position: absolute; 
                left: 15px; 
                width: 45%; 
                font-weight: bold; 
                text-align: left;
                color: var(--primary-purple);
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
            
            .stats-container {
                grid-template-columns: repeat(3, 1fr);
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
            
            .search-box {
                padding: 18px 25px 18px 50px;
                font-size: 1.1rem;
            }
            
            .filter-buttons {
                gap: 15px;
            }
            
            .filter-btn {
                padding: 10px 20px;
                font-size: 0.9rem;
            }
        }
        
        /* Touch-friendly improvements */
        @media (hover: none) and (pointer: coarse) {
            .card:hover {
                transform: none;
            }
            
            .stat-card:hover {
                transform: none;
            }
            
            .nav-links a:hover {
                transform: none;
            }
            
            .logout-btn:hover {
                transform: none;
            }
            
            .membership-badge:hover {
                transform: none;
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
            <a href="customershow.php" class="active"><i class="fas fa-users"></i> Customers</a>
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
                <h2><i class="fas fa-users"></i> Customer Management</h2>
                <p>Welcome back, <?php echo $_SESSION['stock_keeper_name']; ?>! Manage and view customer information.</p>
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

    <!-- Search Box -->
    <div class="search-container">
        <i class="fas fa-search search-icon"></i>
        <input type="text" id="searchInput" class="search-box" placeholder="Search by Name, Phone, NIC, Email..." 
               value="<?php echo htmlspecialchars($search); ?>">
    </div>

    <!-- Customer Statistics -->
    <div class="stats-container">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-number"><?php echo $totalCustomers; ?></div>
            <div class="stat-label">Total Customers</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-crown"></i>
            </div>
            <div class="stat-number"><?php echo $premiumCustomers; ?></div>
            <div class="stat-label">Premium Members</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-calendar-plus"></i>
            </div>
            <div class="stat-number"><?php echo $newThisMonth; ?></div>
            <div class="stat-label">New This Month</div>
        </div>
    </div>

    <!-- Filter Buttons -->
    <div class="filter-buttons">
        <button class="filter-btn active" onclick="filterCustomers('all')">All Customers</button>
        <button class="filter-btn" onclick="filterCustomers('regular')">Regular</button>
        <button class="filter-btn" onclick="filterCustomers('silver')">Silver</button>
        <button class="filter-btn" onclick="filterCustomers('gold')">Gold</button>
        <button class="filter-btn" onclick="filterCustomers('platinum')">Platinum</button>
        <button class="filter-btn" onclick="filterCustomers('diamond')">Diamond</button>
    </div>

    <div class="card">
        <h2><i class="fas fa-list"></i> Customer Directory</h2>

        <?php if ($result->num_rows > 0): ?>
            <div class="table-container">
                <table id="customerTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>NIC</th>
                            <th>Email</th>
                            <th>Membership</th>
                            <th>Joined</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $result->fetch_assoc()): ?>
                        <tr onclick="showCustomerDetails(<?= htmlspecialchars(json_encode($row)) ?>)">
                            <td data-label="ID"><?php echo htmlspecialchars($row['customer_id']); ?></td>
                            <td data-label="Name">
                                <strong><?php echo htmlspecialchars($row['name']); ?></strong>
                            </td>
                            <td data-label="Phone">
                                <i class="fas fa-phone" style="color: var(--primary-green); margin-right: 5px;"></i>
                                <?php echo htmlspecialchars($row['phone_no']); ?>
                            </td>
                            <td data-label="NIC"><?php echo htmlspecialchars($row['nic_no'] ?? '—'); ?></td>
                            <td data-label="Email">
                                <?php if(!empty($row['email'])): ?>
                                    <i class="fas fa-envelope" style="color: var(--primary-purple); margin-right: 5px;"></i>
                                    <?php echo htmlspecialchars($row['email']); ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td data-label="Membership">
                                <span class="membership-badge <?php echo strtolower($row['membership_type']); ?>">
                                    <i class="fas fa-crown" style="margin-right: 5px;"></i>
                                    <?php echo htmlspecialchars($row['membership_type']); ?>
                                </span>
                            </td>
                            <td data-label="Joined">
                                <i class="fas fa-calendar" style="color: var(--primary-purple); margin-right: 5px;"></i>
                                <?php echo date('d/m/Y', strtotime($row['created_at'])); ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-users"></i>
                <h3>No Customers Found</h3>
                <p>Start adding customers to see them listed here</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Customer Details Modal -->
<div class="modal" id="customerModal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal()">&times;</span>
        <h2 id="modalTitle"><i class="fas fa-user"></i> Customer Details</h2>
        <div id="customerDetails"></div>
    </div>
</div>

<script>

// Live Search with debouncing
let searchTimeout;
document.getElementById('searchInput').addEventListener('input', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(liveSearch, 300);
});

function liveSearch() {
    let input = document.getElementById('searchInput').value.toLowerCase();
    let rows = document.querySelectorAll('#customerTable tbody tr');
    let visibleCount = 0;

    rows.forEach(row => {
        let text = row.textContent.toLowerCase();
        if (text.includes(input)) {
            row.style.display = '';
            visibleCount++;
            // Add animation
            row.style.animation = 'fadeIn 0.5s ease-out';
        } else {
            row.style.display = 'none';
        }
    });

    // Update stats based on visible rows
    updateCustomerStats();
}

// Filter customers by membership type
function filterCustomers(type) {
    // Update active filter button
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    event.target.classList.add('active');

    let rows = document.querySelectorAll('#customerTable tbody tr');
    let visibleCount = 0;

    rows.forEach(row => {
        if (type === 'all') {
            row.style.display = '';
            visibleCount++;
        } else {
            let membership = row.querySelector('.membership-badge').textContent.toLowerCase();
            if (membership.includes(type)) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        }
        // Add animation
        row.style.animation = 'fadeIn 0.3s ease-out';
    });

    updateCustomerStats();
}

// Update customer statistics based on visible rows
function updateCustomerStats() {
    const visibleRows = document.querySelectorAll('#customerTable tbody tr[style=""]');
    const totalVisible = visibleRows.length;
    
    // Count premium members (gold, platinum, diamond) from visible rows
    let premiumCount = 0;
    let newThisMonth = 0;
    const currentMonth = new Date().getMonth();
    const currentYear = new Date().getFullYear();
    
    visibleRows.forEach(row => {
        const membership = row.querySelector('.membership-badge').textContent.toLowerCase();
        if (membership.includes('gold') || membership.includes('platinum') || membership.includes('diamond')) {
            premiumCount++;
        }
        
        // Get the date from the row (assuming it's in the last cell)
        const dateCell = row.cells[6]; // Joined date column
        const dateText = dateCell.textContent.trim();
        
        // Parse the date (assuming format dd/mm/yyyy)
        const dateParts = dateText.split('/');
        if (dateParts.length === 3) {
            const day = parseInt(dateParts[0]);
            const month = parseInt(dateParts[1]) - 1; // Months are 0-indexed in JS
            const year = parseInt(dateParts[2]);
            
            // Check if the customer joined this month and year
            if (month === currentMonth && year === currentYear) {
                newThisMonth++;
            }
        }
    });
    
    // Update the stats with animation
    animateCounter('totalCustomers', totalVisible);
    animateCounter('premiumCustomers', premiumCount);
    animateCounter('newThisMonth', newThisMonth);
}

// Animate number counter
function animateCounter(elementId, target) {
    const element = document.getElementById(elementId);
    const current = parseInt(element.textContent) || 0;
    
    // Only animate if the value has changed
    if (current !== target) {
        const increment = target > current ? 1 : -1;
        let currentValue = current;
        
        const timer = setInterval(() => {
            currentValue += increment;
            element.textContent = currentValue;
            
            if ((increment === 1 && currentValue >= target) || 
                (increment === -1 && currentValue <= target)) {
                element.textContent = target;
                clearInterval(timer);
            }
        }, 30);
    }
}

// Show customer details in modal
function showCustomerDetails(customer) {
    const modal = document.getElementById('customerModal');
    const details = document.getElementById('customerDetails');
    const title = document.getElementById('modalTitle');
    
    title.innerHTML = `<i class="fas fa-user"></i> ${customer.name}`;
    
    details.innerHTML = `
        <div style="display: grid; gap: 12px; margin-top: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: var(--light-gray); border-radius: var(--border-radius-small);">
                <strong>Customer ID:</strong>
                <span>${customer.customer_id}</span>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: var(--light-gray); border-radius: var(--border-radius-small);">
                <strong>Phone:</strong>
                <span>${customer.phone_no}</span>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: var(--light-gray); border-radius: var(--border-radius-small);">
                <strong>NIC:</strong>
                <span>${customer.nic_no || '—'}</span>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: var(--light-gray); border-radius: var(--border-radius-small);">
                <strong>Email:</strong>
                <span>${customer.email || '—'}</span>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: var(--light-gray); border-radius: var(--border-radius-small);">
                <strong>Membership:</strong>
                <span class="membership-badge ${customer.membership_type.toLowerCase()}">${customer.membership_type}</span>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: var(--light-gray); border-radius: var(--border-radius-small);">
                <strong>Joined:</strong>
                <span>${new Date(customer.created_at).toLocaleDateString()}</span>
            </div>
        </div>
    `;
    
    modal.style.display = 'block';
}

// Close modal
function closeModal() {
    document.getElementById('customerModal').style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('customerModal');
    if (event.target === modal) {
        closeModal();
    }
}

// Add confirmation for logout
document.querySelector('.logout-btn').addEventListener('click', function(e) {
    if (!confirm('Are you sure you want to logout?')) {
        e.preventDefault();
    }
});

// Initialize stats on page load
document.addEventListener('DOMContentLoaded', function() {
    // Add initial animation to cards
    const cards = document.querySelectorAll('.card, .stat-card');
    cards.forEach((card, index) => {
        card.style.animationDelay = `${index * 0.1}s`;
    });
});

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