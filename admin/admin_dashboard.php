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
$selected_branch = isset($_SESSION['selected_branch_dashboard']) ? $_SESSION['selected_branch_dashboard'] : 'branch1';

// Handle branch selection - through AJAX, not page reload
if (isset($_POST['action']) && $_POST['action'] === 'change_branch') {
    header('Content-Type: application/json');
    $selected_branch = $_POST['selected_branch'];
    $_SESSION['selected_branch_dashboard'] = $selected_branch;
    
    // Determine which tables to use based on selected branch
    $customers_table = ($selected_branch == 'branch1') ? 'customers' : 'customers2';
    $products_table = ($selected_branch == 'branch1') ? 'products' : 'products2';
    $bills_table = ($selected_branch == 'branch1') ? 'bills' : 'bills2';
    $bill_items_table = ($selected_branch == 'branch1') ? 'bill_items' : 'bill_items2';
    
    echo json_encode([
        'success' => true,
        'branch_name' => ($selected_branch == 'branch1') ? 'Branch 1' : 'Branch 2',
        'customers_table' => $customers_table,
        'products_table' => $products_table,
        'bills_table' => $bills_table,
        'bill_items_table' => $bill_items_table
    ]);
    exit;
}

// Determine which tables to use based on selected branch
$customers_table = ($selected_branch == 'branch1') ? 'customers' : 'customers2';
$products_table = ($selected_branch == 'branch1') ? 'products' : 'products2';
$bills_table = ($selected_branch == 'branch1') ? 'bills' : 'bills2';
$bill_items_table = ($selected_branch == 'branch1') ? 'bill_items' : 'bill_items2';

$branch_id = 1; // Hardcoded for now, update to $_SESSION['branch_id'] later
$_SESSION['branch_id'] = $branch_id;

// Get statistics for dashboard from selected branch
$total_customers = $conn->query("SELECT COUNT(*) as count FROM $customers_table")->fetch_assoc()['count'] ?? 0;
$total_products = $conn->query("SELECT COUNT(*) as count FROM $products_table")->fetch_assoc()['count'] ?? 0;
$total_sales = $conn->query("SELECT SUM(bi.price * bi.quantity - bi.discount) as total FROM $bill_items_table bi JOIN $bills_table b ON bi.bill_no = b.bill_no WHERE DATE(b.date) = CURDATE()")->fetch_assoc()['total'] ?? 0;

// Calculate Today's Profit from selected branch
$today_profit_query = $conn->query("
    SELECT 
        SUM((bi.price * bi.quantity - bi.discount) - COALESCE(bi.original_price, 0) * bi.quantity) as profit 
    FROM $bill_items_table bi 
    JOIN $bills_table b ON bi.bill_no = b.bill_no 
    WHERE b.branch_id = $branch_id
    AND DATE(b.date) = CURDATE()
");
$today_profit = $today_profit_query->fetch_assoc()['profit'] ?? 0;

$total_stock_value = $conn->query("SELECT SUM(sale_price * stock) as value FROM $products_table")->fetch_assoc()['value'] ?? 0;

// Get recent activities from selected branch
$recent_activities = $conn->query("
    SELECT 'Sale' as type, CONCAT('Bill #', b.bill_no) as description, b.date 
    FROM $bills_table b 
    ORDER BY b.date DESC 
    LIMIT 5
");

// Get low stock products from selected branch
$low_stock_products = $conn->query("
    SELECT product_id, name, stock 
    FROM $products_table 
    WHERE stock < 10 
    ORDER BY stock ASC 
    LIMIT 5
");

// Get real sales data for the chart (last 7 days) from selected branch
$sales_data = [];
$sales_labels = [];

// Get sales data for the last 7 days
$sales_chart_query = $conn->query("
    SELECT 
        DATE(b.date) as sale_date,
        SUM(bi.price * bi.quantity - bi.discount) as daily_sales
    FROM $bills_table b
    JOIN $bill_items_table bi ON b.bill_no = bi.bill_no
    WHERE b.date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(b.date)
    ORDER BY sale_date ASC
");

// Initialize with zero values for last 7 days
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $sales_data[$date] = 0;
    $sales_labels[] = date('D', strtotime($date));
}

// Fill in actual sales data
if ($sales_chart_query && $sales_chart_query->num_rows > 0) {
    while ($row = $sales_chart_query->fetch_assoc()) {
        $sales_data[$row['sale_date']] = floatval($row['daily_sales']);
    }
}

// Convert to arrays for JavaScript
$chart_sales_values = array_values($sales_data);

// Get top selling products for the week from selected branch
$top_products_query = $conn->query("
    SELECT 
        p.name,
        SUM(bi.quantity) as total_sold,
        SUM(bi.price * bi.quantity - bi.discount) as total_revenue
    FROM $products_table p
    JOIN $bill_items_table bi ON p.product_id = bi.product_id
    JOIN $bills_table b ON bi.bill_no = b.bill_no
    WHERE b.date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY p.product_id, p.name
    ORDER BY total_sold DESC
    LIMIT 5
");

$top_products = [];
if ($top_products_query && $top_products_query->num_rows > 0) {
    while ($row = $top_products_query->fetch_assoc()) {
        $top_products[] = $row;
    }
}

// ========== ML PREDICTION DATA ==========
// Note: In production, these would come from actual ML models
// For now, we'll simulate predictions based on historical data

// Get sales data for prediction calculations (last 30 days) from selected branch
$prediction_query = $conn->query("
    SELECT 
        DATE(b.date) as sale_date,
        SUM(bi.price * bi.quantity - bi.discount) as daily_sales,
        COUNT(DISTINCT b.bill_no) as transaction_count,
        COUNT(DISTINCT b.customer_id) as customer_count
    FROM $bills_table b
    JOIN $bill_items_table bi ON b.bill_no = bi.bill_no
    WHERE b.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(b.date)
    ORDER BY sale_date ASC
");

$historical_data = [];
if ($prediction_query && $prediction_query->num_rows > 0) {
    while ($row = $prediction_query->fetch_assoc()) {
        $historical_data[] = $row;
    }
}

// Generate ML predictions (simulated)
$ml_predictions = generate_ml_predictions($historical_data, $total_sales);

// Get high-risk inventory (stock < 5) from selected branch
$high_risk_inventory = $conn->query("
    SELECT 
        p.product_id, 
        p.name, 
        p.stock, 
        p.sale_price,
        COALESCE((
            SELECT SUM(bi.quantity) 
            FROM $bill_items_table bi 
            JOIN $bills_table b ON bi.bill_no = b.bill_no 
            WHERE bi.product_id = p.product_id 
            AND b.date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ), 0) as weekly_sales
    FROM $products_table p
    WHERE p.stock < 5
    ORDER BY p.stock ASC
    LIMIT 5
");

// Get upcoming seasonal events
$seasonal_events = [
    ['name' => 'Back to School', 'date' => date('Y-08-15'), 'impact' => 'high'],
    ['name' => 'Holiday Season', 'date' => date('Y-12-10'), 'impact' => 'very high'],
    ['name' => 'Summer Break', 'date' => date('Y-06-01'), 'impact' => 'medium'],
    ['name' => 'Winter Sales', 'date' => date('Y-01-15'), 'impact' => 'high'],
];

// Function to generate simulated ML predictions including today's prediction
function generate_ml_predictions($historical_data, $today_sales = 0) {
    if (empty($historical_data)) {
        return [
            'today_prediction' => 0,
            'today_accuracy' => 0,
            'next_month_revenue' => 0,
            'growth_percentage' => 0,
            'confidence_level' => 0,
            'high_demand_days' => [],
            'product_predictions' => [],
            'risk_products' => [],
            'today_vs_prediction' => [
                'actual' => $today_sales,
                'predicted' => 0,
                'difference' => $today_sales,
                'status' => $today_sales >= 0 ? 'exceeded' : 'below'
            ]
        ];
    }
    
    // Calculate average daily sales from historical data
    $total_sales = 0;
    $day_count = 0;
    
    // Get last 7 days for trend analysis
    $last_7_days = array_slice($historical_data, -7);
    foreach ($last_7_days as $day) {
        $total_sales += $day['daily_sales'];
        $day_count++;
    }
    
    $avg_daily_sales = $day_count > 0 ? $total_sales / $day_count : 0;
    
    // Calculate today's prediction based on day of week pattern
    $today_day = date('l');
    $day_of_week_sales = [];
    
    // Group sales by day of week
    foreach ($historical_data as $day) {
        $day_name = date('l', strtotime($day['sale_date']));
        if (!isset($day_of_week_sales[$day_name])) {
            $day_of_week_sales[$day_name] = [];
        }
        $day_of_week_sales[$day_name][] = $day['daily_sales'];
    }
    
    // Calculate average for today's day of week
    $today_prediction = $avg_daily_sales; // Default to overall average
    if (isset($day_of_week_sales[$today_day])) {
        $today_sales_data = $day_of_week_sales[$today_day];
        $today_prediction = array_sum($today_sales_data) / count($today_sales_data);
        
        // Apply growth factor based on recent trend (last 3 days vs previous 3 days)
        if (count($last_7_days) >= 6) {
            $last_3_days = array_slice($last_7_days, -3);
            $prev_3_days = array_slice($last_7_days, -6, 3);
            
            $last_3_avg = array_sum(array_column($last_3_days, 'daily_sales')) / 3;
            $prev_3_avg = array_sum(array_column($prev_3_days, 'daily_sales')) / 3;
            
            if ($prev_3_avg > 0) {
                $growth_factor = $last_3_avg / $prev_3_avg;
                $today_prediction *= $growth_factor;
            }
        }
    }
    
    // Calculate today's accuracy if we have actual sales
    $today_accuracy = 0;
    if ($today_sales > 0 && $today_prediction > 0) {
        $today_accuracy = 100 - (abs($today_sales - $today_prediction) / $today_prediction * 100);
        $today_accuracy = max(0, min(100, $today_accuracy)); // Clamp between 0-100
    }
    
    // Generate next month prediction with seasonality factor
    $next_month_revenue = $avg_daily_sales * 30;
    
    // Apply seasonality based on current month
    $current_month = date('n');
    $seasonality_factors = [
        1 => 1.1,  // January - Post-holiday sales
        2 => 0.9,  // February
        3 => 1.0,  // March
        4 => 1.05, // April - Spring
        5 => 1.1,  // May - Summer prep
        6 => 1.15, // June - Summer start
        7 => 1.2,  // July - Summer peak
        8 => 1.25, // August - Back to school
        9 => 1.3,  // September - School season
        10 => 1.2, // October - Fall
        11 => 1.35, // November - Holiday prep
        12 => 1.5  // December - Holiday peak
    ];
    
    $seasonality_factor = $seasonality_factors[$current_month] ?? 1.0;
    $next_month_revenue *= $seasonality_factor;
    
    // Add some randomness for realistic simulation (±10%)
    $random_factor = 0.9 + (mt_rand(0, 20) / 100); // 0.9 to 1.1
    $next_month_revenue *= $random_factor;
    
    // Calculate growth percentage
    $last_month_avg = $avg_daily_sales;
    $growth_percentage = $seasonality_factor > 1 ? 
        (($seasonality_factor - 1) * 100) + mt_rand(0, 10) : 
        mt_rand(5, 15);
    
    return [
        'today_prediction' => $today_prediction,
        'today_accuracy' => round($today_accuracy),
        'next_month_revenue' => $next_month_revenue,
        'growth_percentage' => round($growth_percentage),
        'confidence_level' => round(70 + ($today_accuracy / 100 * 30)), // Higher if today's prediction is accurate
        'high_demand_days' => ['Monday', 'Friday', 'Saturday'],
        'product_predictions' => [
            ['name' => 'Educational Toys', 'trend' => 'up', 'probability' => 85],
            ['name' => 'Baby Clothes', 'trend' => 'stable', 'probability' => 70],
            ['name' => 'Kids Electronics', 'trend' => 'up', 'probability' => 90],
            ['name' => 'Outdoor Games', 'trend' => 'down', 'probability' => 45],
        ],
        'risk_products' => [
            ['name' => 'Winter Jackets', 'risk' => 'high', 'days_remaining' => 7],
            ['name' => 'School Bags', 'risk' => 'medium', 'days_remaining' => 14],
        ],
        'today_vs_prediction' => [
            'actual' => $today_sales,
            'predicted' => $today_prediction,
            'difference' => $today_sales - $today_prediction,
            'status' => $today_sales >= $today_prediction ? 'exceeded' : 'below'
        ]
    ];
}

// Get branch display name
$branch_display = ($selected_branch == 'branch1') ? 'Branch 1' : 'Branch 2';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Kids Berry - Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-purple: #6a0dad;
            --light-purple: #8a2be2;
            --dark-purple: #4b0082;
            --light-green: #90ee90;
            --medium-green: #32cd32;
            --dark-green: #228b22;
            --prediction-blue: #3498db;
            --prediction-dark-blue: #2980b9;
            --warning-orange: #e67e22;
            --danger-red: #e74c3c;
            --success-green: #2ecc71;
            --today-blue: #1abc9c;
            --today-dark-blue: #16a085;
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
            --gradient-prediction: linear-gradient(135deg, var(--prediction-blue), var(--prediction-dark-blue));
            --gradient-today: linear-gradient(135deg, var(--today-blue), var(--today-dark-blue));
            --gradient-warning: linear-gradient(135deg, var(--warning-orange), #d35400);
            --gradient-danger: linear-gradient(135deg, var(--danger-red), #c0392b);
            --gradient-success: linear-gradient(135deg, var(--success-green), #27ae60);
            --gradient-light: linear-gradient(135deg, var(--light-purple), var(--light-green));
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
            background: var(--gradient-today);
        }

        .stat-card:nth-child(4)::before {
            background: var(--gradient-success);
        }

        .stat-card:nth-child(5)::before {
            background: var(--gradient-prediction);
        }

        .stat-card:nth-child(6)::before {
            background: var(--gradient-warning);
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
            background: var(--gradient-today);
        }

        .stat-card:nth-child(4) .stat-icon {
            background: var(--gradient-success);
        }

        .stat-card:nth-child(5) .stat-icon {
            background: var(--gradient-prediction);
        }

        .stat-card:nth-child(6) .stat-icon {
            background: var(--gradient-warning);
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
            color: var(--today-dark-blue);
        }

        .stat-card:nth-child(4) .stat-value {
            color: var(--success-green);
        }

        .stat-card:nth-child(5) .stat-value {
            color: var(--prediction-dark-blue);
        }

        .stat-card:nth-child(6) .stat-value {
            color: var(--warning-orange);
        }

        .stat-label {
            color: var(--dark-gray);
            font-weight: 600;
            font-size: 0.85rem;
        }

        /* Dashboard Content - Mobile Stack */
        .dashboard-content {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 15px;
            box-shadow: var(--shadow-light);
            transition: var(--transition);
            animation: fadeInUp 0.8s ease;
        }

        .card:hover {
            box-shadow: var(--shadow-medium);
            transform: translateY(-3px);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--light-gray);
        }

        .card-title {
            color: var(--primary-purple);
            font-size: 1.2rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .view-all {
            color: var(--primary-purple);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.85rem;
        }

        .view-all:hover {
            color: var(--light-purple);
            transform: translateX(3px);
        }

        /* Activity List */
        .activity-list {
            list-style: none;
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid var(--light-gray);
            transition: var(--transition);
        }

        .activity-item:hover {
            background: var(--light-gray);
            padding-left: 8px;
            border-radius: var(--border-radius-small);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            background: var(--gradient-primary);
            transition: var(--transition);
            font-size: 0.9rem;
        }

        .activity-item:hover .activity-icon {
            transform: scale(1.1);
        }

        .activity-details {
            flex: 1;
        }

        .activity-description {
            font-weight: 600;
            margin-bottom: 3px;
            font-size: 0.9rem;
        }

        .activity-time {
            color: var(--dark-gray);
            font-size: 0.8rem;
        }

        /* Low Stock List */
        .stock-list {
            list-style: none;
        }

        .stock-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid var(--light-gray);
            transition: var(--transition);
        }

        .stock-item:hover {
            background: var(--light-gray);
            padding-left: 8px;
            border-radius: var(--border-radius-small);
        }

        .stock-item:last-child {
            border-bottom: none;
        }

        .stock-warning {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            background: #ff6b6b;
            transition: var(--transition);
            font-size: 0.9rem;
        }

        .stock-item:hover .stock-warning {
            transform: scale(1.1);
            animation: pulse 1s infinite;
        }

        .stock-details {
            flex: 1;
        }

        .stock-name {
            font-weight: 600;
            margin-bottom: 3px;
            font-size: 0.9rem;
        }

        .stock-count {
            color: #ff6b6b;
            font-weight: 600;
            font-size: 0.85rem;
        }

        /* Chart Container */
        .chart-container {
            height: 250px;
            margin-top: 10px;
            position: relative;
        }

        /* Top Products List */
        .top-products-list {
            list-style: none;
        }

        .top-product-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid var(--light-gray);
            transition: var(--transition);
        }

        .top-product-item:hover {
            background: var(--light-gray);
            padding-left: 8px;
            border-radius: var(--border-radius-small);
        }

        .top-product-item:last-child {
            border-bottom: none;
        }

        .product-rank {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            background: var(--gradient-primary);
            font-weight: bold;
            font-size: 0.8rem;
        }

        .top-product-item:nth-child(1) .product-rank {
            background: linear-gradient(135deg, #FFD700, #FFA500);
        }

        .top-product-item:nth-child(2) .product-rank {
            background: linear-gradient(135deg, #C0C0C0, #A9A9A9);
        }

        .top-product-item:nth-child(3) .product-rank {
            background: linear-gradient(135deg, #CD7F32, #8B4513);
        }

        .product-details {
            flex: 1;
        }

        .product-name {
            font-weight: 600;
            margin-bottom: 3px;
            font-size: 0.9rem;
        }

        .product-sales {
            color: var(--dark-gray);
            font-size: 0.8rem;
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

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
            margin-top: 12px;
        }

        .quick-action-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 15px;
            text-decoration: none;
            text-align: center;
            transition: var(--transition);
            box-shadow: var(--shadow-light);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .quick-action-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-medium);
        }

        /* ========== NEW ML PREDICTION STYLES ========== */
        
        /* Prediction Gauge */
        .prediction-gauge {
            width: 100%;
            height: 180px;
            position: relative;
            margin: 20px 0;
        }

        .gauge-container {
            position: relative;
            width: 100%;
            height: 150px;
        }

        .gauge-value {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 1.8rem;
            font-weight: bold;
            color: var(--prediction-dark-blue);
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }

        .gauge-label {
            text-align: center;
            margin-top: 10px;
            font-weight: 600;
            color: var(--dark-gray);
        }

        /* Trend Indicator */
        .trend-indicator {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 10px;
        }

        .trend-up {
            background: rgba(46, 204, 113, 0.2);
            color: var(--dark-green);
        }

        .trend-down {
            background: rgba(231, 76, 60, 0.2);
            color: var(--danger-red);
        }

        .trend-stable {
            background: rgba(52, 152, 219, 0.2);
            color: var(--prediction-blue);
        }

        /* Risk Level Badge */
        .risk-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 5px;
        }

        .risk-high {
            background: linear-gradient(135deg, rgba(231, 76, 60, 0.2), rgba(192, 57, 43, 0.2));
            color: var(--danger-red);
        }

        .risk-medium {
            background: linear-gradient(135deg, rgba(230, 126, 34, 0.2), rgba(211, 84, 0, 0.2));
            color: var(--warning-orange);
        }

        .risk-low {
            background: linear-gradient(135deg, rgba(46, 204, 113, 0.2), rgba(39, 174, 96, 0.2));
            color: var(--dark-green);
        }

        /* Prediction Confidence */
        .confidence-meter {
            width: 100%;
            height: 8px;
            background: var(--light-gray);
            border-radius: 4px;
            overflow: hidden;
            margin: 10px 0;
        }

        .confidence-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 1s ease;
        }

        .confidence-high {
            background: var(--gradient-success);
        }

        .confidence-medium {
            background: var(--gradient-warning);
        }

        .confidence-low {
            background: var(--gradient-danger);
        }

        /* Heatmap */
        .heatmap-container {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
            margin: 15px 0;
        }

        .heatmap-day {
            aspect-ratio: 1;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 600;
            color: white;
            transition: var(--transition);
        }

        .heatmap-day:hover {
            transform: scale(1.1);
            box-shadow: var(--shadow-light);
        }

        .heatmap-low {
            background: #c6e48b;
        }

        .heatmap-medium {
            background: #7bc96f;
        }

        .heatmap-high {
            background: #239a3b;
        }

        .heatmap-very-high {
            background: #196127;
        }

        /* Product Prediction List */
        .prediction-list {
            list-style: none;
        }

        .prediction-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid var(--light-gray);
        }

        .prediction-item:last-child {
            border-bottom: none;
        }

        .prediction-probability {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
            font-size: 0.8rem;
        }

        /* Seasonal Events */
        .event-timeline {
            position: relative;
            padding-left: 20px;
        }

        .event-timeline::before {
            content: '';
            position: absolute;
            left: 7px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--medium-gray);
        }

        .event-item {
            position: relative;
            margin-bottom: 20px;
            padding: 10px;
            background: var(--white);
            border-radius: var(--border-radius-small);
            box-shadow: var(--shadow-light);
            border-left: 4px solid;
        }

        .event-item::before {
            content: '';
            position: absolute;
            left: -20px;
            top: 15px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--primary-purple);
        }

        .event-high {
            border-left-color: var(--danger-red);
        }

        .event-medium {
            border-left-color: var(--warning-orange);
        }

        .event-low {
            border-left-color: var(--prediction-blue);
        }

        /* Inventory Risk Matrix */
        .risk-matrix {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin-top: 15px;
        }

        .risk-item {
            padding: 10px;
            border-radius: var(--border-radius-small);
            text-align: center;
            transition: var(--transition);
        }

        .risk-item:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-light);
        }

        /* ML Model Status */
        .model-status {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: var(--light-gray);
            border-radius: var(--border-radius-small);
            margin-top: 15px;
            cursor: pointer;
        }

        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--success-green);
            animation: pulse 2s infinite;
        }

        .status-indicator.offline {
            background: var(--danger-red);
        }

        /* Today's Prediction Card */
        .today-prediction-card {
            background: linear-gradient(135deg, rgba(26, 188, 156, 0.1), rgba(22, 160, 133, 0.1));
            border: 2px solid var(--today-blue);
            padding: 15px;
            border-radius: var(--border-radius-small);
            margin-bottom: 15px;
        }

        .prediction-comparison {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 10px;
        }

        .prediction-actual, .prediction-forecast {
            text-align: center;
        }

        .prediction-difference {
            text-align: center;
            font-weight: bold;
            margin-top: 5px;
        }

        .difference-positive {
            color: var(--success-green);
        }

        .difference-negative {
            color: var(--danger-red);
        }

        .difference-neutral {
            color: var(--prediction-blue);
        }

        /* Animations */
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

        @keyframes pulse {
            0% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
            100% {
                transform: scale(1);
            }
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

        .pulse {
            animation: pulse 2s infinite;
        }

        .bounce {
            animation: bounce 2s infinite;
        }

        /* Loading Animation */
        .loading {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
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
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }

        .toast.show {
            transform: translateY(0);
            opacity: 1;
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

        /* Ripple effect styles */
        .ripple {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.5);
            transform: scale(0);
            animation: ripple-animation 0.6s linear;
        }
        
        @keyframes ripple-animation {
            to {
                transform: scale(4);
                opacity: 0;
            }
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
                grid-template-columns: repeat(6, 1fr);
                gap: 15px;
            }
            
            .dashboard-content {
                display: grid;
                grid-template-columns: 2fr 1fr;
                gap: 20px;
            }
            
            .quick-actions {
                grid-template-columns: 1fr 1fr;
            }
            
            .header {
                padding: 18px 25px;
            }
            
            .header h1 {
                font-size: 1.6rem;
            }
            
            .card {
                padding: 20px;
            }
            
            .stat-card {
                padding: 20px;
            }
            
            .stat-value {
                font-size: 1.7rem;
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
            
            /* ML Components Tablet Layout */
            .prediction-gauge {
                height: 200px;
            }
            
            .gauge-value {
                font-size: 2.2rem;
            }
            
            .heatmap-container {
                gap: 8px;
            }
        }

        /* Desktop Styles */
        @media (min-width: 992px) {
            .sidebar {
                width: 260px;
            }
            
            .main-content {
                margin-left: 0;
                padding: 25px;
            }
            
            .stats-container {
                gap: 20px;
            }
            
            .stat-card {
                padding: 20px;
            }
            
            .stat-icon {
                width: 60px;
                height: 60px;
                font-size: 1.8rem;
            }
            
            .stat-value {
                font-size: 2rem;
            }
            
            .dashboard-content {
                gap: 25px;
            }
            
            .card {
                padding: 25px;
            }
            
            /* ML Components Desktop Layout */
            .prediction-gauge {
                height: 220px;
            }
            
            .gauge-value {
                font-size: 2.5rem;
            }
        }

        /* Small Mobile Optimization */
        @media (max-width: 360px) {
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .header h1 {
                font-size: 1.2rem;
            }
            
            .header {
                flex-direction: column;
                text-align: center;
            }
            
            .user-info {
                width: 100%;
                justify-content: center;
            }
            
            .logo {
                font-size: 1.3rem;
            }
            
            .logo i {
                font-size: 1.5rem;
            }
            
            .branch-selector {
                flex-direction: column;
                align-items: flex-start;
            }
            
            /* ML Components Small Mobile */
            .stat-card:nth-child(5),
            .stat-card:nth-child(6) {
                display: none;
            }
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
                <li><a href="admin_dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="cashier_prediction.php" class=""><i class="fas fa-chart-line"></i> Cashier Predictions</a></li>
                <li><a href="customer_manage.php"><i class="fas fa-users"></i> Customer Management</a></li>
                <li><a href="stockkeeper_manage.php"><i class="fas fa-user-tie"></i> Stock Keeper Management</a></li>
               <li><a href="cashier_manage.php"><i class="fas fa-user-tie"></i> Sales Officer Management</a></li>
                <li><a href="sales_manage.php"><i class="fas fa-cash-register"></i> Cashier Management</a></li>
                <li><a href="product_manage.php"><i class="fas fa-box"></i> Product Management</a></li>
                 <li><a href="suppliers_manage.php"><i class="fas fa-truck"></i> Suppliers Management</a></li>
                <li><a href="report_show.php"><i class="fas fa-chart-line"></i> Reports</a></li>
                <li><a href="admin_contact_management.php"><i class="fas fa-headset"></i> Contact Requests</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <div class="header">
                <h1><i class="fas fa-tachometer-alt"></i> Admin Dashboard - <?php echo $branch_display; ?> <span class="trend-indicator trend-up">ML Insights Active</span></h1>
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
                        Branch 1 <span class="branch-badge" style="background: linear-gradient(135deg, #6a0dad, #4b0082);">customers, products, bills, bill_items</span>
                    </label>
                    <label class="branch-option">
                        <input type="radio" name="selected_branch" value="branch2" <?php echo ($selected_branch == 'branch2') ? 'checked' : ''; ?> onchange="changeBranch(this.value)">
                        Branch 2 <span class="branch-badge" style="background: linear-gradient(135deg, #228b22, #32cd32);">customers2, products2, bills2, bill_items2</span>
                    </label>
                </div>
                <div style="margin-left: auto;">
                    <span class="branch-badge" id="currentBranchBadge">
                        <i class="fas fa-database"></i> Currently viewing: <?php echo ($selected_branch == 'branch1') ? 'Branch 1 (customers, products, bills, bill_items)' : 'Branch 2 (customers2, products2, bills2, bill_items2)'; ?>
                    </span>
                </div>
            </div>

            <!-- Stats Cards - Now with ML Predictions -->
            <div class="stats-container">
                <div class="stat-card" onclick="showToast('Total Customers in <?php echo $branch_display; ?>: <?php echo number_format($total_customers); ?>')">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-value"><?php echo number_format($total_customers); ?></div>
                    <div class="stat-label">Total Customers</div>
                </div>
                <div class="stat-card" onclick="showToast('Today\'s Profit in <?php echo $branch_display; ?>: Rs<?php echo number_format($today_profit, 2); ?>')">
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-value">Rs<?php echo number_format($today_profit, 2); ?></div>
                    <div class="stat-label">Today's Profit</div>
                </div>
                <div class="stat-card" onclick="showToast('Today\'s Predicted Sales for <?php echo $branch_display; ?>: Rs<?php echo number_format($ml_predictions['today_prediction'], 2); ?> (Accuracy: <?php echo $ml_predictions['today_accuracy']; ?>%)')">
                    <div class="stat-icon">
                        <i class="fas fa-bullseye"></i>
                    </div>
                    <div class="stat-value">Rs<?php echo number_format($ml_predictions['today_prediction'], 2); ?></div>
                    <div class="stat-label">Today's Prediction</div>
                </div>
                <div class="stat-card" onclick="showToast('Prediction Accuracy for <?php echo $branch_display; ?>: <?php echo $ml_predictions['today_accuracy']; ?>%')">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-value"><?php echo $ml_predictions['today_accuracy']; ?>%</div>
                    <div class="stat-label">Prediction Accuracy</div>
                </div>
                <div class="stat-card" onclick="showToast('Predicted Next Month Revenue for <?php echo $branch_display; ?>: Rs<?php echo number_format($ml_predictions['next_month_revenue'], 2); ?>')">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="stat-value">Rs<?php echo number_format($ml_predictions['next_month_revenue'], 2); ?></div>
                    <div class="stat-label">Next Month Prediction</div>
                </div>
                <div class="stat-card" onclick="showToast('Predicted Growth for <?php echo $branch_display; ?>: <?php echo $ml_predictions['growth_percentage']; ?>%')">
                    <div class="stat-icon">
                        <i class="fas fa-arrow-up"></i>
                    </div>
                    <div class="stat-value"><?php echo $ml_predictions['growth_percentage']; ?>%</div>
                    <div class="stat-label">Predicted Growth</div>
                </div>
            </div>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Today's Sales Prediction Card -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-chart-bar"></i> Today's Sales Performance - <?php echo $branch_display; ?></h2>
                        </div>
                        <div class="today-prediction-card">
                            <h3 style="color: var(--today-dark-blue); margin-bottom: 15px; text-align: center;">
                                <i class="fas fa-bullseye"></i> Today's Sales Forecast vs Actual
                            </h3>
                            <div class="prediction-comparison">
                                <div class="prediction-actual">
                                    <div style="font-size: 0.9rem; color: #666;">Actual Sales</div>
                                    <div style="font-size: 1.8rem; font-weight: bold; color: var(--primary-purple);">
                                        Rs<?php echo number_format($ml_predictions['today_vs_prediction']['actual'], 2); ?>
                                    </div>
                                </div>
                                <div style="text-align: center;">
                                    <div style="font-size: 2rem; color: var(--prediction-blue);">
                                        <i class="fas fa-<?php echo $ml_predictions['today_vs_prediction']['status'] == 'exceeded' ? 'arrow-up' : 'arrow-down'; ?>"></i>
                                    </div>
                                    <div style="font-size: 0.8rem; color: #666;">vs Prediction</div>
                                </div>
                                <div class="prediction-forecast">
                                    <div style="font-size: 0.9rem; color: #666;">Predicted Sales</div>
                                    <div style="font-size: 1.8rem; font-weight: bold; color: var(--prediction-dark-blue);">
                                        Rs<?php echo number_format($ml_predictions['today_vs_prediction']['predicted'], 2); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="prediction-difference <?php 
                                echo $ml_predictions['today_vs_prediction']['difference'] > 0 ? 'difference-positive' : 
                                    ($ml_predictions['today_vs_prediction']['difference'] < 0 ? 'difference-negative' : 'difference-neutral'); 
                            ?>">
                                <?php 
                                $difference = $ml_predictions['today_vs_prediction']['difference'];
                                $percentage = $ml_predictions['today_vs_prediction']['predicted'] > 0 ? 
                                    abs($difference / $ml_predictions['today_vs_prediction']['predicted'] * 100) : 0;
                                
                                if ($difference > 0) {
                                    echo "<i class='fas fa-arrow-up'></i> Exceeded by Rs" . number_format($difference, 2) . " (" . number_format($percentage, 1) . "%)";
                                } elseif ($difference < 0) {
                                    echo "<i class='fas fa-arrow-down'></i> Below by Rs" . number_format(abs($difference), 2) . " (" . number_format($percentage, 1) . "%)";
                                } else {
                                    echo "<i class='fas fa-equals'></i> Exactly as predicted";
                                }
                                ?>
                            </div>
                            <div class="confidence-meter" style="margin-top: 15px;">
                                <div class="confidence-fill <?php 
                                    echo $ml_predictions['confidence_level'] >= 80 ? 'confidence-high' : 
                                        ($ml_predictions['confidence_level'] >= 60 ? 'confidence-medium' : 'confidence-low');
                                ?>" style="width: <?php echo $ml_predictions['confidence_level']; ?>%;"></div>
                            </div>
                            <div style="text-align: center; margin-top: 10px; font-size: 0.9rem; color: #666;">
                                Model Confidence: <?php echo $ml_predictions['confidence_level']; ?>%
                            </div>
                        </div>
                    </div>

                    <!-- Sales Chart with Predictions -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-chart-line"></i> Sales Forecast - <?php echo $branch_display; ?> <span class="trend-indicator trend-up">ML Enhanced</span></h2>
                            <a href="report_show.php" class="view-all">View Details <i class="fas fa-arrow-right"></i></a>
                        </div>
                        <div class="chart-container">
                            <canvas id="salesForecastChart"></canvas>
                        </div>
                        <div class="model-status">
                            <div class="status-indicator"></div>
                            <span>ML Model: Active (<?php echo $ml_predictions['confidence_level']; ?>% confidence)</span>
                        </div>
                    </div>

                    <!-- Product Performance Predictions -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-rocket"></i> Product Performance Predictions - <?php echo $branch_display; ?></h2>
                        </div>
                        <ul class="prediction-list">
                            <?php foreach ($ml_predictions['product_predictions'] as $product): ?>
                            <li class="prediction-item">
                                <div class="product-details">
                                    <div class="product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                                    <div class="trend-indicator trend-<?php echo $product['trend']; ?>">
                                        <i class="fas fa-arrow-<?php echo $product['trend'] == 'down' ? 'down' : ($product['trend'] == 'up' ? 'up' : 'right'); ?>"></i>
                                        <?php echo ucfirst($product['trend']); ?>
                                    </div>
                                </div>
                                <div class="prediction-probability" style="background: <?php 
                                    if ($product['probability'] >= 80) echo 'var(--success-green)';
                                    elseif ($product['probability'] >= 60) echo 'var(--warning-orange)';
                                    else echo 'var(--danger-red)';
                                ?>;">
                                    <?php echo $product['probability']; ?>%
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <!-- Inventory Risk Matrix -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-exclamation-triangle"></i> Inventory Risk Alert - <?php echo $branch_display; ?></h2>
                            <a href="product_manage.php" class="view-all">Manage <i class="fas fa-arrow-right"></i></a>
                        </div>
                        <div class="risk-matrix">
                            <?php if ($high_risk_inventory && $high_risk_inventory->num_rows > 0): ?>
                                <?php while($product = $high_risk_inventory->fetch_assoc()): 
                                    $risk_level = $product['stock'] < 2 ? 'high' : ($product['stock'] < 5 ? 'medium' : 'low');
                                    $weekly_sales = $product['weekly_sales'] ?? 0;
                                    $days_remaining = $weekly_sales > 0 ? floor($product['stock'] / ($weekly_sales / 7)) : 0;
                                ?>
                                <div class="risk-item" style="background: <?php 
                                    if ($risk_level == 'high') echo 'rgba(231, 76, 60, 0.1)';
                                    elseif ($risk_level == 'medium') echo 'rgba(230, 126, 34, 0.1)';
                                    else echo 'rgba(46, 204, 113, 0.1)';
                                ?>;">
                                    <div class="stock-name"><?php echo htmlspecialchars($product['name']); ?></div>
                                    <div class="stock-count">Stock: <?php echo $product['stock']; ?></div>
                                    <div class="risk-badge risk-<?php echo $risk_level; ?>">
                                        <?php echo ucfirst($risk_level); ?> Risk
                                    </div>
                                    <?php if ($days_remaining > 0): ?>
                                    <div style="font-size: 0.8rem; color: #666; margin-top: 5px;">
                                        ~<?php echo $days_remaining; ?> days left
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="no-records" style="text-align: center; padding: 20px; color: #7f8c8d; width: 100%;">
                                    <i class="fas fa-check-circle" style="font-size: 2rem; margin-bottom: 10px;"></i>
                                    <div>All products are well stocked in <?php echo $branch_display; ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    <!-- Revenue Prediction Gauge -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-bullseye"></i> Next Month Revenue Prediction - <?php echo $branch_display; ?></h2>
                        </div>
                        <div class="prediction-gauge">
                            <div class="gauge-container">
                                <canvas id="revenueGauge"></canvas>
                                <div class="gauge-value">Rs<?php echo number_format($ml_predictions['next_month_revenue'], 2); ?></div>
                            </div>
                            <div class="gauge-label">Next Month Prediction</div>
                            <div class="confidence-meter">
                                <div class="confidence-fill <?php 
                                    echo $ml_predictions['confidence_level'] >= 80 ? 'confidence-high' : 
                                        ($ml_predictions['confidence_level'] >= 60 ? 'confidence-medium' : 'confidence-low');
                                ?>" style="width: <?php echo $ml_predictions['confidence_level']; ?>%;"></div>
                            </div>
                            <div style="text-align: center; font-size: 0.9rem; color: #666;">
                                Confidence: <?php echo $ml_predictions['confidence_level']; ?>%
                            </div>
                        </div>
                    </div>

                    <!-- Demand Heatmap -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-calendar-alt"></i> Weekly Demand Heatmap - <?php echo $branch_display; ?></h2>
                        </div>
                        <div class="heatmap-container">
                            <?php 
                            $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                            $demand_levels = ['low', 'medium', 'high', 'very-high'];
                            ?>
                            <?php foreach($days as $day): 
                                $demand = $demand_levels[array_rand($demand_levels)];
                            ?>
                            <div class="heatmap-day heatmap-<?php echo $demand; ?>" title="<?php echo $day; ?>: <?php echo ucfirst($demand); ?> demand">
                                <?php echo $day; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-top: 10px; font-size: 0.8rem;">
                            <div style="display: flex; align-items: center; gap: 5px;">
                                <div style="width: 12px; height: 12px; background: #c6e48b; border-radius: 2px;"></div>
                                <span>Low</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 5px;">
                                <div style="width: 12px; height: 12px; background: #7bc96f; border-radius: 2px;"></div>
                                <span>Medium</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 5px;">
                                <div style="width: 12px; height: 12px; background: #239a3b; border-radius: 2px;"></div>
                                <span>High</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 5px;">
                                <div style="width: 12px; height: 12px; background: #196127; border-radius: 2px;"></div>
                                <span>Very High</span>
                            </div>
                        </div>
                    </div>

                    <!-- Seasonal Impact Timeline -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-calendar-star"></i> Upcoming Seasonal Events</h2>
                        </div>
                        <div class="event-timeline">
                            <?php foreach($seasonal_events as $event): 
                                $days_until = floor((strtotime($event['date']) - time()) / (60 * 60 * 24));
                                if ($days_until > 0 && $days_until < 120):
                            ?>
                            <div class="event-item event-<?php echo $event['impact']; ?>">
                                <div style="font-weight: 600; margin-bottom: 5px;"><?php echo $event['name']; ?></div>
                                <div style="font-size: 0.85rem; color: #666;">
                                    <i class="far fa-calendar"></i> <?php echo date('M j, Y', strtotime($event['date'])); ?>
                                    <span style="margin-left: 10px;">
                                        <i class="far fa-clock"></i> <?php echo $days_until; ?> days
                                    </span>
                                    <span class="trend-indicator" style="margin-left: 10px; background: <?php 
                                        if ($event['impact'] == 'very high') echo 'var(--danger-red)';
                                        elseif ($event['impact'] == 'high') echo 'var(--warning-orange)';
                                        else echo 'var(--prediction-blue)';
                                    ?>; color: white;">
                                        <?php echo ucfirst($event['impact']); ?> impact
                                    </span>
                                </div>
                            </div>
                            <?php endif; endforeach; ?>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-bolt"></i> Quick Actions</h2>
                        </div>
                        <div class="quick-actions">
                            <a href="product_manage.php" class="quick-action-card">
                                <div class="stat-icon" style="margin: 0 auto 10px; background: var(--gradient-prediction);">
                                    <i class="fas fa-robot"></i>
                                </div>
                                <div class="stat-label">Update ML Models</div>
                            </a>
                            <a href="report_show.php" class="quick-action-card">
                                <div class="stat-icon" style="margin: 0 auto 10px;">
                                    <i class="fas fa-file-export"></i>
                                </div>
                                <div class="stat-label">Export Predictions</div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div class="toast" id="toast">
        <i class="fas fa-info-circle"></i>
        <span id="toastMessage">This is a toast message</span>
    </div>

    <script>
        setTimeout(function() {
            window.location.reload();
        }, 40000);
        
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
                    document.getElementById('currentBranchBadge').innerHTML = '<i class="fas fa-database"></i> Currently viewing: ' + data.branch_name + ' (' + data.customers_table + ', ' + data.products_table + ', ' + data.bills_table + ', ' + data.bill_items_table + ')';
                    
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

        // Sales Forecast Chart with Historical + Predictions
        const historicalDates = <?php echo json_encode(array_slice($sales_labels, -7)); ?>;
        const historicalSales = <?php echo json_encode(array_slice($chart_sales_values, -7)); ?>;
        
        // Generate predictions for next 7 days (simulated)
        const predictionDates = [];
        const predictionSales = [];
        const lastValue = historicalSales[historicalSales.length - 1] || 0;
        const todayPrediction = <?php echo $ml_predictions['today_prediction']; ?>;
        
        // Start predictions from today
        for (let i = 0; i < 7; i++) {
            if (i === 0) {
                // Today's prediction
                predictionDates.push('Today');
                predictionSales.push(todayPrediction);
            } else {
                // Future predictions with growth factor
                predictionDates.push('Day +' + i);
                const growth = 1 + (Math.random() * 0.1 - 0.02); // -2% to +8% daily growth
                predictionSales.push(todayPrediction * Math.pow(growth, i));
            }
        }

        const forecastCtx = document.getElementById('salesForecastChart').getContext('2d');
        const salesForecastChart = new Chart(forecastCtx, {
            type: 'line',
            data: {
                labels: [...historicalDates, ...predictionDates],
                datasets: [{
                    label: 'Historical Sales',
                    data: [...historicalSales, ...Array(7).fill(null)],
                    borderColor: '#6a0dad',
                    backgroundColor: 'rgba(106, 13, 173, 0.1)',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#6a0dad',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7
                }, {
                    label: 'ML Predictions',
                    data: [...Array(7).fill(null), ...predictionSales],
                    borderColor: '#3498db',
                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                    borderWidth: 3,
                    borderDash: [5, 5],
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#3498db',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += 'Rs' + context.parsed.y.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'Rs' + value.toLocaleString('en-IN', {minimumFractionDigits: 0, maximumFractionDigits: 0});
                            }
                        }
                    }
                }
            }
        });

        // Revenue Prediction Gauge
        const gaugeCtx = document.getElementById('revenueGauge').getContext('2d');
        const revenueGauge = new Chart(gaugeCtx, {
            type: 'doughnut',
            data: {
                datasets: [{
                    data: [<?php echo $ml_predictions['confidence_level']; ?>, 100 - <?php echo $ml_predictions['confidence_level']; ?>],
                    backgroundColor: [
                        'rgba(52, 152, 219, 0.8)',
                        'rgba(236, 240, 241, 0.5)'
                    ],
                    borderWidth: 0,
                    circumference: 270,
                    rotation: 225
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '80%',
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        enabled: false
                    }
                }
            }
        });

        // Add animations to elements when they come into view
        document.addEventListener('DOMContentLoaded', function() {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = 1;
                        entry.target.style.transform = 'translateY(0)';
                        
                        // Animate gauge fill
                        if (entry.target.classList.contains('gauge-container')) {
                            const confidenceFill = entry.target.querySelector('.confidence-fill');
                            if (confidenceFill) {
                                setTimeout(() => {
                                    confidenceFill.style.width = '<?php echo $ml_predictions['confidence_level']; ?>%';
                                }, 300);
                            }
                        }
                    }
                });
            }, { threshold: 0.1 });

            const animatedElements = document.querySelectorAll('.stat-card, .card, .gauge-container, .heatmap-day, .prediction-item, .risk-item, .event-item');
            animatedElements.forEach(el => {
                el.style.opacity = 0;
                el.style.transform = 'translateY(15px)';
                el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                observer.observe(el);
            });

            // Animate stats with counting effect
            const statValues = document.querySelectorAll('.stat-value');
            statValues.forEach(stat => {
                const originalText = stat.textContent;
                if (originalText.includes('Rs')) {
                    const number = parseFloat(originalText.replace('Rs', '').replace(/,/g, ''));
                    if (!isNaN(number)) {
                        animateValue(stat, 0, number, 1500, true);
                    }
                } else if (originalText.includes('%')) {
                    const number = parseFloat(originalText.replace('%', '').replace(/,/g, ''));
                    if (!isNaN(number)) {
                        animateValue(stat, 0, number, 1500, false, true);
                    }
                } else {
                    const number = parseInt(originalText.replace(/,/g, ''));
                    if (!isNaN(number)) {
                        animateValue(stat, 0, number, 1500, false);
                    }
                }
            });

            // Add interactive heatmap
            document.querySelectorAll('.heatmap-day').forEach(day => {
                day.addEventListener('click', function() {
                    const dayName = this.textContent;
                    const demand = this.classList[1].split('-')[1];
                    showToast(`${dayName}: Predicted ${demand.replace('-', ' ')} demand`);
                });
            });

            // Add prediction item hover effects
            document.querySelectorAll('.prediction-item').forEach(item => {
                item.addEventListener('click', function() {
                    const productName = this.querySelector('.product-name').textContent;
                    const trend = this.querySelector('.trend-indicator').textContent.trim();
                    const probability = this.querySelector('.prediction-probability').textContent;
                    showToast(`${productName}: ${trend} trend with ${probability} probability`);
                });
            });
        });

        // Function to animate counting numbers
        function animateValue(element, start, end, duration, isCurrency, isPercent) {
            let startTimestamp = null;
            const step = (timestamp) => {
                if (!startTimestamp) startTimestamp = timestamp;
                const progress = Math.min((timestamp - startTimestamp) / duration, 1);
                const value = Math.floor(progress * (end - start) + start);
                
                if (isCurrency) {
                    element.textContent = 'Rs' + value.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                } else if (isPercent) {
                    element.textContent = value.toFixed(0) + '%';
                } else {
                    element.textContent = value.toLocaleString('en-IN');
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

        // Refresh data every 30 seconds
        setInterval(() => {
            // Show refreshing indicator
            showToast('Refreshing ML predictions...');
            
            // Refresh the page to get updated data
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        }, 30000);

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

        // ML Model interaction
        document.querySelector('.model-status').addEventListener('click', function() {
            showToast('ML Model trained on 30 days of historical data. Using day-of-week patterns and seasonality factors.');
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>