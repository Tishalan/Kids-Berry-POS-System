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
$selected_branch = isset($_SESSION['selected_branch_prediction']) ? $_SESSION['selected_branch_prediction'] : 'branch1';

// Handle branch selection - through AJAX, not page reload
if (isset($_POST['action']) && $_POST['action'] === 'change_branch') {
    header('Content-Type: application/json');
    $selected_branch = $_POST['selected_branch'];
    $_SESSION['selected_branch_prediction'] = $selected_branch;
    
    // Determine which tables to use based on selected branch
    $cashier_table = ($selected_branch == 'branch1') ? 'cashier_users' : 'cashier_users2';
    $bills_table = ($selected_branch == 'branch1') ? 'bills' : 'bills2';
    $bill_items_table = ($selected_branch == 'branch1') ? 'bill_items' : 'bill_items2';
    
    echo json_encode([
        'success' => true,
        'branch_name' => ($selected_branch == 'branch1') ? 'Branch 1' : 'Branch 2',
        'cashier_table' => $cashier_table,
        'bills_table' => $bills_table,
        'bill_items_table' => $bill_items_table
    ]);
    exit;
}

// Determine which tables to use based on selected branch
$cashier_table = ($selected_branch == 'branch1') ? 'cashier_users' : 'cashier_users2';
$bills_table = ($selected_branch == 'branch1') ? 'bills' : 'bills2';
$bill_items_table = ($selected_branch == 'branch1') ? 'bill_items' : 'bill_items2';

// ============================
// PREDICTION ALGORITHM FUNCTIONS
// ============================

function calculateDayOfWeekAverage($conn, $branch_id, $day_of_week, $bills_table, $bill_items_table, $cashier_table, $exclude_today = true) {
    // Get same day of week data from last 4 weeks
    $query = "SELECT 
                AVG(bi.price * bi.quantity - bi.discount) as avg_sales,
                COUNT(DISTINCT b.bill_no) as transaction_count,
                AVG(bi.quantity) as avg_items
              FROM $bills_table b
              JOIN $bill_items_table bi ON b.bill_no = bi.bill_no
              JOIN $cashier_table c ON b.cashier_id = c.cashier_id
              WHERE b.branch_id = $branch_id
              AND DAYOFWEEK(b.date) = $day_of_week
              AND b.date >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)";
    
    if ($exclude_today) {
        $query .= " AND DATE(b.date) != CURDATE()";
    }
    
    $result = $conn->query($query);
    return $result->fetch_assoc();
}

function calculateGrowthTrend($conn, $branch_id, $bills_table, $bill_items_table) {
    // Calculate trend from last 7 days (excluding today)
    $query = "SELECT 
                DATE(b.date) as sale_date,
                SUM(bi.price * bi.quantity - bi.discount) as daily_sales
              FROM $bills_table b
              JOIN $bill_items_table bi ON b.bill_no = bi.bill_no
              WHERE b.branch_id = $branch_id
              AND b.date >= DATE_SUB(DATE_SUB(CURDATE(), INTERVAL 1 DAY), INTERVAL 7 DAY)
              AND b.date < CURDATE()
              GROUP BY DATE(b.date)
              ORDER BY sale_date ASC";
    
    $result = $conn->query($query);
    $sales_data = [];
    $total_sales = 0;
    $day_count = 0;
    
    while ($row = $result->fetch_assoc()) {
        $sales_data[] = $row['daily_sales'];
        $total_sales += $row['daily_sales'];
        $day_count++;
    }
    
    if ($day_count < 2) return 1.0; // No trend data
    
    // Simple linear growth rate calculation
    if (count($sales_data) >= 2) {
        $first = $sales_data[0];
        $last = end($sales_data);
        $growth_rate = ($last - $first) / max($first, 1);
        return 1 + ($growth_rate * 0.3); // Apply 30% of the growth rate
    }
    
    return 1.0;
}

function calculateSeasonalityFactor($conn, $branch_id, $bills_table, $bill_items_table) {
    $month = date('n');
    
    // Get average sales for this month from last year
    $query = "SELECT 
                AVG(bi.price * bi.quantity - bi.discount) as monthly_avg
              FROM $bills_table b
              JOIN $bill_items_table bi ON b.bill_no = bi.bill_no
              WHERE b.branch_id = $branch_id
              AND MONTH(b.date) = $month
              AND YEAR(b.date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 YEAR))";
    
    $result = $conn->query($query);
    $last_year_avg = $result->fetch_assoc()['monthly_avg'] ?? 0;
    
    // Get overall monthly average
    $query = "SELECT 
                AVG(bi.price * bi.quantity - bi.discount) as overall_avg
              FROM $bills_table b
              JOIN $bill_items_table bi ON b.bill_no = bi.bill_no
              WHERE b.branch_id = $branch_id
              AND b.date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)";
    
    $result = $conn->query($query);
    $overall_avg = $result->fetch_assoc()['overall_avg'] ?? 0;
    
    if ($overall_avg > 0 && $last_year_avg > 0) {
        return $last_year_avg / $overall_avg;
    }
    
    return 1.0;
}

function predictCashierSales($conn, $cashier_id, $branch_id, $day_of_week, $bills_table, $bill_items_table, $cashier_table) {
    // Step 1: Get day-of-week average for this cashier
    $query = "SELECT 
                AVG(bi.price * bi.quantity - bi.discount) as cashier_avg
              FROM $bills_table b
              JOIN $bill_items_table bi ON b.bill_no = bi.bill_no
              WHERE b.cashier_id = $cashier_id
              AND b.branch_id = $branch_id
              AND DAYOFWEEK(b.date) = $day_of_week
              AND b.date >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)
              AND DATE(b.date) != CURDATE()";
    
    $result = $conn->query($query);
    $cashier_avg = $result->fetch_assoc()['cashier_avg'] ?? 0;
    
    // Step 2: Get branch day-of-week average
    $branch_data = calculateDayOfWeekAverage($conn, $branch_id, $day_of_week, $bills_table, $bill_items_table, $cashier_table, true);
    $branch_avg = $branch_data['avg_sales'] ?? 0;
    
    // Step 3: Calculate cashier performance factor
    $performance_factor = ($branch_avg > 0) ? ($cashier_avg / $branch_avg) : 1.0;
    
    // Step 4: Get growth trend
    $growth_trend = calculateGrowthTrend($conn, $branch_id, $bills_table, $bill_items_table);
    
    // Step 5: Get seasonality factor
    $seasonality_factor = calculateSeasonalityFactor($conn, $branch_id, $bills_table, $bill_items_table);
    
    // Step 6: Calculate prediction
    $base_prediction = $branch_avg * $performance_factor;
    $prediction = $base_prediction * $growth_trend * $seasonality_factor;
    
    // Apply noise reduction and smoothing
    $prediction = round($prediction * 100) / 100;
    
    // Ensure minimum prediction
    $min_prediction = 1000; // Minimum Rs.1000 prediction
    return max($prediction, $min_prediction);
}

function calculateAccuracy($actual, $predicted) {
    if ($predicted == 0) return 100;
    
    $difference = abs($actual - $predicted);
    $accuracy = 100 - (($difference / $predicted) * 100);
    
    // Clamp between 0 and 100
    return max(0, min(100, round($accuracy, 1)));
}

function getAccuracyBadge($accuracy) {
    if ($accuracy >= 85) {
        return ['color' => '#2ecc71', 'label' => 'High', 'class' => 'accuracy-high'];
    } elseif ($accuracy >= 70) {
        return ['color' => '#f39c12', 'label' => 'Medium', 'class' => 'accuracy-medium'];
    } else {
        return ['color' => '#e74c3c', 'label' => 'Low', 'class' => 'accuracy-low'];
    }
}

function getStatusBadge($actual, $predicted) {
    $percentage = ($predicted > 0) ? (($actual - $predicted) / $predicted) * 100 : 0;
    
    if ($percentage >= 10) {
        return ['color' => '#2ecc71', 'label' => 'Exceeded', 'class' => 'status-exceeded'];
    } elseif ($percentage >= -10) {
        return ['color' => '#3498db', 'label' => 'On Track', 'class' => 'status-ontrack'];
    } else {
        return ['color' => '#e74c3c', 'label' => 'Below', 'class' => 'status-below'];
    }
}

// ============================
// GET TODAY'S ACTUAL SALES DATA
// ============================

// Get all active cashiers
$cashiers_query = "SELECT cashier_id, name, email FROM $cashier_table WHERE status = 'active' ORDER BY name";
$cashiers_result = $conn->query($cashiers_query);

$cashiers_data = [];
$total_actual_sales = 0;
$cashier_count = 0;

// Get today's date
$today = date('Y-m-d');
$day_of_week = date('N') + 1; // 1=Monday, 7=Sunday (MySQL DAYOFWEEK returns 1=Sunday)

// Get today's actual sales for each cashier
while ($cashier = $cashiers_result->fetch_assoc()) {
    $cashier_id = $cashier['cashier_id'];
    
    // Get today's actual sales
    $sales_query = "SELECT 
                    COALESCE(SUM(bi.price * bi.quantity - bi.discount), 0) as today_sales,
                    COUNT(DISTINCT b.bill_no) as transaction_count
                  FROM $bills_table b
                  JOIN $bill_items_table bi ON b.bill_no = bi.bill_no
                  WHERE b.cashier_id = $cashier_id
                  AND b.branch_id = 1
                  AND DATE(b.date) = '$today'";
    
    $sales_result = $conn->query($sales_query);
    $sales_data = $sales_result->fetch_assoc();
    
    // Calculate prediction
    $predicted_sales = predictCashierSales($conn, $cashier_id, 1, $day_of_week, $bills_table, $bill_items_table, $cashier_table);
    
    $today_actual = $sales_data['today_sales'] ?? 0;
    $accuracy = calculateAccuracy($today_actual, $predicted_sales);
    $accuracy_badge = getAccuracyBadge($accuracy);
    $status_badge = getStatusBadge($today_actual, $predicted_sales);
    
    $cashiers_data[$cashier_id] = [
        'cashier_id' => $cashier_id,
        'name' => $cashier['name'],
        'email' => $cashier['email'],
        'today_actual' => $today_actual,
        'today_predicted' => $predicted_sales,
        'accuracy' => $accuracy,
        'accuracy_badge' => $accuracy_badge,
        'status_badge' => $status_badge,
        'difference' => $today_actual - $predicted_sales,
        'transaction_count' => $sales_data['transaction_count'] ?? 0
    ];
    
    $total_actual_sales += $today_actual;
    $cashier_count++;
}

// Sort cashiers by actual sales (descending)
usort($cashiers_data, function($a, $b) {
    return $b['today_actual'] <=> $a['today_actual'];
});

// Add rank
foreach ($cashiers_data as $index => &$cashier) {
    $cashier['rank'] = $index + 1;
}

// ============================
// CALCULATE DASHBOARD STATISTICS
// ============================

// 1. Active Cashiers
$active_cashiers = $cashier_count;

// 2. Total Sales (Today)
$total_sales = $total_actual_sales;

// 3. Average per Cashier
$avg_per_cashier = ($cashier_count > 0) ? $total_sales / $cashier_count : 0;

// 4. Best Performer
$best_performer = !empty($cashiers_data) ? $cashiers_data[0]['name'] : 'N/A';
$best_sales = !empty($cashiers_data) ? $cashiers_data[0]['today_actual'] : 0;

// 5. Prediction Accuracy (Average)
$total_accuracy = 0;
$accuracy_count = 0;
foreach ($cashiers_data as $cashier) {
    if ($cashier['today_predicted'] > 0) {
        $total_accuracy += $cashier['accuracy'];
        $accuracy_count++;
    }
}
$avg_accuracy = ($accuracy_count > 0) ? $total_accuracy / $accuracy_count : 0;

// 6. Estimated Commission (Assuming 5% commission)
$commission_rate = 0.05;
$estimated_commission = $total_sales * $commission_rate;

// ============================
// GET HISTORICAL DATA FOR CHART
// ============================

// Last 7 days actual sales
$historical_query = "SELECT 
                    DATE(b.date) as sale_date,
                    SUM(bi.price * bi.quantity - bi.discount) as daily_sales
                  FROM $bills_table b
                  JOIN $bill_items_table bi ON b.bill_no = bi.bill_no
                  WHERE b.branch_id = 1
                  AND b.date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                  GROUP BY DATE(b.date)
                  ORDER BY sale_date ASC";

$historical_result = $conn->query($historical_query);
$historical_data = [];
$historical_labels = [];

while ($row = $historical_result->fetch_assoc()) {
    $historical_data[$row['sale_date']] = $row['daily_sales'];
}

// Fill in missing days with 0
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    if (!isset($historical_data[$date])) {
        $historical_data[$date] = 0;
    }
    $historical_labels[] = date('D', strtotime($date));
}

// Next 7 days predictions (simplified - using today's prediction for all future days)
$predictions_data = [];
$prediction_labels = [];

// Start from tomorrow
for ($i = 1; $i <= 7; $i++) {
    $date = date('Y-m-d', strtotime("+$i days"));
    $future_day_of_week = date('N', strtotime($date)) + 1;
    
    // Calculate total prediction for this future day
    $day_prediction = 0;
    foreach ($cashiers_data as $cashier) {
        $cashier_prediction = predictCashierSales($conn, $cashier['cashier_id'], 1, $future_day_of_week, $bills_table, $bill_items_table, $cashier_table);
        $day_prediction += $cashier_prediction;
    }
    
    $predictions_data[] = $day_prediction;
    $prediction_labels[] = date('D', strtotime($date));
}

// Combine for chart
$chart_labels = array_merge($historical_labels, ['Today'], $prediction_labels);
$actual_sales_values = array_values($historical_data);
$predicted_values = array_merge(
    array_fill(0, 7, null), // No predictions for past days
    [$total_sales], // Today's actual
    $predictions_data // Future predictions
);

// Calculate today's total predicted sales
$today_total_predicted = 0;
foreach ($cashiers_data as $cashier) {
    $today_total_predicted += $cashier['today_predicted'];
}

$today_accuracy = calculateAccuracy($total_sales, $today_total_predicted);
$today_accuracy_badge = getAccuracyBadge($today_accuracy);

// Get branch display name
$branch_display = ($selected_branch == 'branch1') ? 'Branch 1' : 'Branch 2';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Kids Berry - Cashier Prediction Dashboard</title>
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
            --success-green: #2ecc71;
            --warning-orange: #e67e22;
            --danger-red: #e74c3c;
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
            --gradient-blue: linear-gradient(135deg, var(--prediction-blue), #2980b9);
            --gradient-success: linear-gradient(135deg, var(--success-green), #27ae60);
            --gradient-warning: linear-gradient(135deg, var(--warning-orange), #d35400);
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
        }

        .stat-card:nth-child(1)::before {
            background: var(--gradient-primary);
        }

        .stat-card:nth-child(2)::before {
            background: var(--gradient-secondary);
        }

        .stat-card:nth-child(3)::before {
            background: var(--gradient-blue);
        }

        .stat-card:nth-child(4)::before {
            background: var(--gradient-success);
        }

        .stat-card:nth-child(5)::before {
            background: var(--gradient-warning);
        }

        .stat-card:nth-child(6)::before {
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
            transition: var(--transition);
        }

        .stat-card:hover .stat-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .stat-card:nth-child(1) .stat-icon {
            background: var(--gradient-primary);
        }

        .stat-card:nth-child(2) .stat-icon {
            background: var(--gradient-secondary);
        }

        .stat-card:nth-child(3) .stat-icon {
            background: var(--gradient-blue);
        }

        .stat-card:nth-child(4) .stat-icon {
            background: var(--gradient-success);
        }

        .stat-card:nth-child(5) .stat-icon {
            background: var(--gradient-warning);
        }

        .stat-card:nth-child(6) .stat-icon {
            background: linear-gradient(135deg, var(--primary-purple), var(--dark-green));
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 800;
            margin-bottom: 5px;
            transition: var(--transition);
        }

        .stat-card:hover .stat-value {
            transform: scale(1.05);
        }

        .stat-card:nth-child(1) .stat-value {
            color: var(--primary-purple);
        }

        .stat-card:nth-child(2) .stat-value {
            color: var(--dark-green);
        }

        .stat-card:nth-child(3) .stat-value {
            color: var(--prediction-blue);
        }

        .stat-card:nth-child(4) .stat-value {
            color: var(--success-green);
        }

        .stat-card:nth-child(5) .stat-value {
            color: var(--warning-orange);
        }

        .stat-card:nth-child(6) .stat-value {
            color: var(--dark-purple);
        }

        .stat-label {
            color: var(--dark-gray);
            font-weight: 600;
            font-size: 0.85rem;
        }

        /* Prediction Comparison Card */
        .prediction-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--shadow-light);
            margin-bottom: 20px;
            transition: var(--transition);
            animation: fadeInUp 0.8s ease 0.2s backwards;
        }

        .prediction-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-medium);
        }

        .prediction-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-gray);
        }

        .prediction-title {
            color: var(--primary-purple);
            font-size: 1.2rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .prediction-date {
            color: var(--dark-gray);
            font-weight: 600;
            font-size: 0.9rem;
            background: var(--light-gray);
            padding: 5px 10px;
            border-radius: var(--border-radius-small);
        }

        .comparison-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
        }

        @media (min-width: 768px) {
            .comparison-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        .comparison-item {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .comparison-label {
            font-weight: 600;
            color: var(--dark-gray);
            font-size: 0.9rem;
        }

        .comparison-value {
            font-size: 1.8rem;
            font-weight: 800;
        }

        .actual-value {
            color: var(--success-green);
        }

        .predicted-value {
            color: var(--prediction-blue);
        }

        .difference-value {
            font-size: 1.2rem;
            padding: 5px 10px;
            border-radius: var(--border-radius-small);
            font-weight: 600;
        }

        .difference-positive {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success-green);
        }

        .difference-negative {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger-red);
        }

        .difference-neutral {
            background: rgba(52, 152, 219, 0.1);
            color: var(--prediction-blue);
        }

        .accuracy-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 10px;
            border-radius: var(--border-radius-small);
            font-weight: 600;
            font-size: 0.9rem;
        }

        .accuracy-high {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success-green);
        }

        .accuracy-medium {
            background: rgba(243, 156, 18, 0.1);
            color: var(--warning-orange);
        }

        .accuracy-low {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger-red);
        }

        /* Chart Container */
        .chart-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--shadow-light);
            margin-bottom: 20px;
            transition: var(--transition);
            animation: fadeInUp 0.8s ease 0.4s backwards;
        }

        .chart-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-medium);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-gray);
        }

        .chart-title {
            color: var(--primary-purple);
            font-size: 1.2rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .chart-container {
            height: 300px;
            width: 100%;
        }

        /* Cashier Ranking Table */
        .ranking-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--shadow-light);
            margin-bottom: 20px;
            transition: var(--transition);
            animation: fadeInUp 0.8s ease 0.6s backwards;
        }

        .ranking-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-medium);
        }

        .ranking-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-gray);
        }

        .ranking-title {
            color: var(--primary-purple);
            font-size: 1.2rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .ranking-table-container {
            overflow-x: auto;
            border-radius: var(--border-radius-small);
            border: 1px solid var(--light-gray);
        }

        .ranking-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        .ranking-table th {
            background: var(--gradient-primary);
            color: var(--white);
            padding: 12px 15px;
            text-align: left;
            font-weight: 700;
            font-size: 0.9rem;
        }

        .ranking-table td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--light-gray);
            font-size: 0.9rem;
        }

        .ranking-table tr:hover {
            background: var(--light-gray);
        }

        .ranking-table tr:nth-child(even) {
            background: rgba(0, 0, 0, 0.02);
        }

        .ranking-table tr:hover:nth-child(even) {
            background: var(--light-gray);
        }

        .rank-badge {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            color: var(--white);
            font-size: 0.8rem;
        }

        .rank-1 {
            background: linear-gradient(135deg, #FFD700, #FFA500);
        }

        .rank-2 {
            background: linear-gradient(135deg, #C0C0C0, #A9A9A9);
        }

        .rank-3 {
            background: linear-gradient(135deg, #CD7F32, #8B4513);
        }

        .rank-other {
            background: var(--light-gray);
            color: var(--dark-gray);
        }

        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: var(--border-radius-small);
            font-weight: 600;
            font-size: 0.8rem;
        }

        .status-exceeded {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success-green);
        }

        .status-ontrack {
            background: rgba(52, 152, 219, 0.1);
            color: var(--prediction-blue);
        }

        .status-below {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger-red);
        }

        .action-btn {
            background: var(--gradient-primary);
            color: var(--white);
            border: none;
            padding: 6px 12px;
            border-radius: var(--border-radius-small);
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.8rem;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-light);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            position: relative;
            background: var(--white);
            margin: 5% auto;
            padding: 30px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-heavy);
            width: 90%;
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
            animation: slideDown 0.3s ease;
        }

        .close-modal {
            position: absolute;
            top: 15px;
            right: 15px;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--dark-gray);
            cursor: pointer;
            transition: var(--transition);
        }

        .close-modal:hover {
            color: var(--primary-purple);
            transform: rotate(90deg);
        }

        .modal-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-gray);
        }

        .modal-title {
            color: var(--primary-purple);
            font-size: 1.4rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .cashier-profile {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        @media (min-width: 768px) {
            .cashier-profile {
                grid-template-columns: 1fr 2fr;
            }
        }

        .profile-info {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-size: 2.5rem;
            font-weight: 800;
            margin: 0 auto;
        }

        .profile-details {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid var(--light-gray);
        }

        .detail-label {
            font-weight: 600;
            color: var(--dark-gray);
        }

        .detail-value {
            font-weight: 700;
            color: var(--primary-purple);
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

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
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
                grid-template-columns: repeat(3, 1fr);
                gap: 20px;
            }
            
            .header {
                padding: 18px 25px;
            }
            
            .header h1 {
                font-size: 1.6rem;
            }
            
            .chart-container {
                height: 350px;
            }
            
            .branch-selector {
                padding: 15px 20px;
            }
        }

        /* Desktop Styles */
        @media (min-width: 1024px) {
            .sidebar {
                width: 260px;
            }
            
            .main-content {
                padding: 25px;
            }
            
            .stats-container {
                grid-template-columns: repeat(6, 1fr);
                gap: 20px;
            }
            
            .stat-card {
                padding: 20px;
            }
            
            .stat-icon {
                width: 50px;
                height: 50px;
                font-size: 1.5rem;
            }
            
            .stat-value {
                font-size: 1.8rem;
            }
            
            .chart-container {
                height: 400px;
            }
        }

        /* Small Mobile Optimization */
        @media (max-width: 360px) {
            .stats-container {
                grid-template-columns: 1fr;
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
        }

        /* Auto-refresh indicator */
        .refresh-indicator {
            display: flex;
            align-items: center;
            gap: 5px;
            color: var(--dark-gray);
            font-size: 0.8rem;
            font-weight: 600;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.5;
            }
        }
    </style>
</head>
<body>
    <!-- Mobile Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

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
                <li><a href="admin_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="cashier_prediction.php" class="active"><i class="fas fa-chart-line"></i> Cashier Predictions</a></li>
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
                <h1><i class="fas fa-chart-line"></i> Cashier Prediction Dashboard</h1>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php 
                            $admin_name = $_SESSION['admin_email'] ?? 'Admin';
                            echo strtoupper(substr($admin_name, 0, 1)); 
                        ?>
                    </div>
                    <div class="refresh-indicator">
                        <i class="fas fa-sync"></i>
                        Auto-refresh in <span id="refreshTimer">30</span>s
                    </div>
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
                        Branch 1 <span class="branch-badge" style="background: linear-gradient(135deg, #6a0dad, #4b0082);">cashier_users, bills, bill_items</span>
                    </label>
                    <label class="branch-option">
                        <input type="radio" name="selected_branch" value="branch2" <?php echo ($selected_branch == 'branch2') ? 'checked' : ''; ?> onchange="changeBranch(this.value)">
                        Branch 2 <span class="branch-badge" style="background: linear-gradient(135deg, #228b22, #32cd32);">cashier_users2, bills2, bill_items2</span>
                    </label>
                </div>
                <div style="margin-left: auto;">
                    <span class="branch-badge" id="currentBranchBadge">
                        <i class="fas fa-database"></i> Currently viewing: <?php echo ($selected_branch == 'branch1') ? 'Branch 1 (cashier_users, bills, bill_items)' : 'Branch 2 (cashier_users2, bills2, bill_items2)'; ?>
                    </span>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="stats-container">
                <div class="stat-card" onclick="showToast('Active Cashiers: <?php echo $active_cashiers; ?>')">
                    <div class="stat-icon">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <div class="stat-value"><?php echo $active_cashiers; ?></div>
                    <div class="stat-label">Active Cashiers</div>
                </div>
                <div class="stat-card" onclick="showToast('Total Sales Today: Rs<?php echo number_format($total_sales, 2); ?>')">
                    <div class="stat-icon">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <div class="stat-value">Rs<?php echo number_format($total_sales, 2); ?></div>
                    <div class="stat-label">Total Sales (Today)</div>
                </div>
                <div class="stat-card" onclick="showToast('Average per Cashier: Rs<?php echo number_format($avg_per_cashier, 2); ?>')">
                    <div class="stat-icon">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <div class="stat-value">Rs<?php echo number_format($avg_per_cashier, 2); ?></div>
                    <div class="stat-label">Average per Cashier</div>
                </div>
                <div class="stat-card" onclick="showToast('Best Performer: <?php echo htmlspecialchars($best_performer); ?> (Rs<?php echo number_format($best_sales, 2); ?>)')">
                    <div class="stat-icon">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <div class="stat-value"><?php echo htmlspecialchars($best_performer); ?></div>
                    <div class="stat-label">Best Performer</div>
                </div>
                <div class="stat-card" onclick="showToast('Prediction Accuracy: <?php echo number_format($avg_accuracy, 1); ?>%')">
                    <div class="stat-icon">
                        <i class="fas fa-bullseye"></i>
                    </div>
                    <div class="stat-value"><?php echo number_format($avg_accuracy, 1); ?>%</div>
                    <div class="stat-label">Prediction Accuracy</div>
                </div>
                <div class="stat-card" onclick="showToast('Estimated Commission: Rs<?php echo number_format($estimated_commission, 2); ?>')">
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-value">Rs<?php echo number_format($estimated_commission, 2); ?></div>
                    <div class="stat-label">Estimated Commission</div>
                </div>
            </div>

            <!-- Today's Prediction Comparison -->
            <div class="prediction-card">
                <div class="prediction-header">
                    <h2 class="prediction-title"><i class="fas fa-chart-pie"></i> Today's Prediction Comparison - <?php echo $branch_display; ?></h2>
                    <div class="prediction-date"><?php echo date('F j, Y'); ?></div>
                </div>
                <div class="comparison-grid">
                    <div class="comparison-item">
                        <div class="comparison-label">Actual Sales</div>
                        <div class="comparison-value actual-value">Rs<?php echo number_format($total_sales, 2); ?></div>
                    </div>
                    <div class="comparison-item">
                        <div class="comparison-label">Predicted Sales</div>
                        <div class="comparison-value predicted-value">Rs<?php echo number_format($today_total_predicted, 2); ?></div>
                    </div>
                    <div class="comparison-item">
                        <div class="comparison-label">Difference</div>
                        <div class="comparison-value difference-value 
                            <?php 
                                $difference = $total_sales - $today_total_predicted;
                                if ($difference > 0) echo 'difference-positive';
                                elseif ($difference < 0) echo 'difference-negative';
                                else echo 'difference-neutral';
                            ?>">
                            <?php 
                                if ($difference > 0) echo '+';
                                echo number_format($difference, 2);
                            ?>
                        </div>
                    </div>
                    <div class="comparison-item">
                        <div class="comparison-label">Prediction Accuracy</div>
                        <div class="comparison-value">
                            <span class="accuracy-badge <?php echo $today_accuracy_badge['class']; ?>">
                                <i class="fas fa-<?php echo $today_accuracy_badge['label'] == 'High' ? 'check-circle' : ($today_accuracy_badge['label'] == 'Medium' ? 'exclamation-circle' : 'times-circle'); ?>"></i>
                                <?php echo number_format($today_accuracy, 1); ?>% (<?php echo $today_accuracy_badge['label']; ?>)
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sales Prediction Chart -->
            <div class="chart-card">
                <div class="chart-header">
                    <h2 class="chart-title"><i class="fas fa-chart-line"></i> 7-Day Sales & Prediction Timeline - <?php echo $branch_display; ?></h2>
                </div>
                <div class="chart-container">
                    <canvas id="predictionChart"></canvas>
                </div>
            </div>

            <!-- Cashier Ranking Table -->
            <div class="ranking-card">
                <div class="ranking-header">
                    <h2 class="ranking-title"><i class="fas fa-list-ol"></i> Cashier Performance Ranking - <?php echo $branch_display; ?></h2>
                    <div class="refresh-indicator">
                        <i class="fas fa-sync"></i> Live Data
                    </div>
                </div>
                <div class="ranking-table-container">
                    <table class="ranking-table">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Cashier Name</th>
                                <th>Today's Sales (Actual)</th>
                                <th>Today's Prediction</th>
                                <th>Accuracy %</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cashiers_data as $cashier): ?>
                            <tr>
                                <td>
                                    <div class="rank-badge 
                                        <?php 
                                            if ($cashier['rank'] == 1) echo 'rank-1';
                                            elseif ($cashier['rank'] == 2) echo 'rank-2';
                                            elseif ($cashier['rank'] == 3) echo 'rank-3';
                                            else echo 'rank-other';
                                        ?>">
                                        <?php echo $cashier['rank']; ?>
                                    </div>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($cashier['name']); ?></strong><br>
                                    <small style="color: var(--dark-gray);">ID: <?php echo $cashier['cashier_id']; ?></small>
                                </td>
                                <td>
                                    <strong style="color: var(--success-green);">Rs<?php echo number_format($cashier['today_actual'], 2); ?></strong><br>
                                    <small><?php echo $cashier['transaction_count']; ?> transactions</small>
                                </td>
                                <td>
                                    <strong style="color: var(--prediction-blue);">Rs<?php echo number_format($cashier['today_predicted'], 2); ?></strong>
                                </td>
                                <td>
                                    <span class="accuracy-badge <?php echo $cashier['accuracy_badge']['class']; ?>">
                                        <?php echo number_format($cashier['accuracy'], 1); ?>%
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $cashier['status_badge']['class']; ?>">
                                        <?php echo $cashier['status_badge']['label']; ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="action-btn" onclick="viewCashierDetails(<?php echo htmlspecialchars(json_encode($cashier)); ?>)">
                                        <i class="fas fa-eye"></i> View Details
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Cashier Details Modal -->
    <div class="modal" id="cashierModal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeModal()">&times;</button>
            <div class="modal-header">
                <h2 class="modal-title"><i class="fas fa-user-tie"></i> Cashier Performance Details</h2>
            </div>
            <div id="cashierModalContent">
                <!-- Dynamic content will be loaded here -->
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div class="toast" id="toast">
        <i class="fas fa-info-circle"></i>
        <span id="toastMessage">This is a toast message</span>
    </div>

    <script>
        // Auto-refresh functionality
        let refreshTimer = 30;
        const refreshInterval = setInterval(() => {
            refreshTimer--;
            document.getElementById('refreshTimer').textContent = refreshTimer;
            
            if (refreshTimer <= 0) {
                refreshTimer = 30;
                window.location.reload();
            }
        }, 1000);

        // Mobile menu toggle
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const closeSidebar = document.getElementById('closeSidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function toggleSidebar() {
            sidebar.classList.toggle('active');
            sidebarOverlay.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
        }

        menuToggle.addEventListener('click', toggleSidebar);
        closeSidebar.addEventListener('click', toggleSidebar);
        sidebarOverlay.addEventListener('click', toggleSidebar);

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
                    document.getElementById('currentBranchBadge').innerHTML = '<i class="fas fa-database"></i> Currently viewing: ' + data.branch_name + ' (' + data.cashier_table + ', ' + data.bills_table + ', ' + data.bill_items_table + ')';
                    
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

        // Sales Prediction Chart
        const predictionChart = new Chart(document.getElementById('predictionChart'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [{
                    label: 'Actual Sales',
                    data: <?php echo json_encode($actual_sales_values); ?>,
                    backgroundColor: 'rgba(106, 13, 173, 0.1)',
                    borderColor: '#6a0dad',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#6a0dad',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7
                }, {
                    label: 'Predicted Sales',
                    data: <?php echo json_encode($predicted_values); ?>,
                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                    borderColor: '#3498db',
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
                        labels: {
                            font: {
                                size: 12
                            },
                            padding: 20
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(106, 13, 173, 0.8)',
                        titleColor: '#ffffff',
                        bodyColor: '#ffffff',
                        borderColor: '#6a0dad',
                        borderWidth: 1,
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            label: function(context) {
                                const datasetLabel = context.dataset.label;
                                const value = context.parsed.y;
                                return `${datasetLabel}: Rs${value.toLocaleString()}`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        },
                        ticks: {
                            callback: function(value) {
                                return 'Rs' + value.toLocaleString();
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                animations: {
                    tension: {
                        duration: 1000,
                        easing: 'linear'
                    }
                }
            }
        });

        // View cashier details function
        function viewCashierDetails(cashierData) {
            try {
                const modalContent = document.getElementById('cashierModalContent');
                
                // Calculate additional metrics
                const performanceScore = Math.round((cashierData.today_actual / cashierData.today_predicted) * 100);
                const commission = cashierData.today_actual * 0.05; // 5% commission
                
                let html = `
                    <div class="cashier-profile">
                        <div class="profile-info">
                            <div class="profile-avatar">
                                ${cashierData.name.charAt(0).toUpperCase()}
                            </div>
                            <div class="profile-details">
                                <div class="detail-item">
                                    <span class="detail-label">Cashier ID:</span>
                                    <span class="detail-value">${cashierData.cashier_id}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Name:</span>
                                    <span class="detail-value">${cashierData.name}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Email:</span>
                                    <span class="detail-value">${cashierData.email || 'N/A'}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Branch:</span>
                                    <span class="detail-value"><?php echo $branch_display; ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="performance-metrics">
                            <h3 style="color: var(--primary-purple); margin-bottom: 15px; font-size: 1.1rem;">
                                <i class="fas fa-chart-bar"></i> Today's Performance
                            </h3>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px;">
                                <div style="background: rgba(46, 204, 113, 0.1); padding: 15px; border-radius: var(--border-radius-small);">
                                    <div style="font-size: 0.9rem; color: var(--dark-gray); margin-bottom: 5px;">Actual Sales</div>
                                    <div style="font-size: 1.5rem; font-weight: 800; color: var(--success-green);">
                                        Rs${parseFloat(cashierData.today_actual).toLocaleString('en-IN', {minimumFractionDigits: 2})}
                                    </div>
                                </div>
                                <div style="background: rgba(52, 152, 219, 0.1); padding: 15px; border-radius: var(--border-radius-small);">
                                    <div style="font-size: 0.9rem; color: var(--dark-gray); margin-bottom: 5px;">Predicted Sales</div>
                                    <div style="font-size: 1.5rem; font-weight: 800; color: var(--prediction-blue);">
                                        Rs${parseFloat(cashierData.today_predicted).toLocaleString('en-IN', {minimumFractionDigits: 2})}
                                    </div>
                                </div>
                            </div>
                            
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px;">
                                <div style="background: rgba(243, 156, 18, 0.1); padding: 15px; border-radius: var(--border-radius-small);">
                                    <div style="font-size: 0.9rem; color: var(--dark-gray); margin-bottom: 5px;">Accuracy</div>
                                    <div style="font-size: 1.5rem; font-weight: 800; color: var(--warning-orange);">
                                        ${parseFloat(cashierData.accuracy).toFixed(1)}%
                                    </div>
                                    <div style="font-size: 0.8rem; color: var(--dark-gray); margin-top: 5px;">
                                        ${cashierData.accuracy_badge.label} Confidence
                                    </div>
                                </div>
                                <div style="background: rgba(155, 89, 182, 0.1); padding: 15px; border-radius: var(--border-radius-small);">
                                    <div style="font-size: 0.9rem; color: var(--dark-gray); margin-bottom: 5px;">Estimated Commission</div>
                                    <div style="font-size: 1.5rem; font-weight: 800; color: #9b59b6;">
                                        Rs${parseFloat(commission).toLocaleString('en-IN', {minimumFractionDigits: 2})}
                                    </div>
                                    <div style="font-size: 0.8rem; color: var(--dark-gray); margin-top: 5px;">
                                        (5% of sales)
                                    </div>
                                </div>
                            </div>
                            
                            <div style="background: var(--light-gray); padding: 20px; border-radius: var(--border-radius-small); margin-bottom: 20px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                    <div style="font-weight: 600; color: var(--dark-gray);">Performance Status</div>
                                    <span class="status-badge ${cashierData.status_badge.class}" style="font-size: 0.9rem;">
                                        ${cashierData.status_badge.label}
                                    </span>
                                </div>
                                <div style="font-size: 0.9rem; color: var(--dark-gray); line-height: 1.5;">
                                    ${cashierData.difference > 0 ? 
                                        `Exceeded prediction by Rs${Math.abs(cashierData.difference).toLocaleString('en-IN', {minimumFractionDigits: 2})} (${performanceScore}% of target)` : 
                                        cashierData.difference < 0 ?
                                        `Below prediction by Rs${Math.abs(cashierData.difference).toLocaleString('en-IN', {minimumFractionDigits: 2})} (${performanceScore}% of target)` :
                                        'Exactly on target'}
                                </div>
                            </div>
                            
                            <div style="background: var(--light-gray); padding: 20px; border-radius: var(--border-radius-small);">
                                <h4 style="color: var(--primary-purple); margin-bottom: 10px; font-size: 1rem;">
                                    <i class="fas fa-chart-line"></i> 7-Day Performance Forecast
                                </h4>
                                <div style="height: 200px;">
                                    <canvas id="cashierForecastChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                modalContent.innerHTML = html;
                document.getElementById('cashierModal').style.display = 'block';
                
                // Initialize forecast chart
                setTimeout(() => {
                    const forecastCtx = document.getElementById('cashierForecastChart').getContext('2d');
                    const forecastChart = new Chart(forecastCtx, {
                        type: 'line',
                        data: {
                            labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                            datasets: [{
                                label: 'Predicted Sales',
                                data: [
                                    cashierData.today_predicted * 0.9,
                                    cashierData.today_predicted * 1.1,
                                    cashierData.today_predicted * 0.95,
                                    cashierData.today_predicted * 1.05,
                                    cashierData.today_predicted * 1.2,
                                    cashierData.today_predicted * 1.3,
                                    cashierData.today_predicted * 0.8
                                ],
                                borderColor: '#3498db',
                                backgroundColor: 'rgba(52, 152, 219, 0.1)',
                                borderWidth: 2,
                                tension: 0.4,
                                fill: true
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) {
                                            return 'Rs' + value.toLocaleString();
                                        }
                                    }
                                }
                            }
                        }
                    });
                }, 100);
                
            } catch (error) {
                console.error('Error loading cashier details:', error);
                showToast('Error loading cashier details');
            }
        }

        // Close modal function
        function closeModal() {
            document.getElementById('cashierModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('cashierModal');
            if (event.target == modal) {
                closeModal();
            }
        }

        // Add animations to elements when they come into view
        document.addEventListener('DOMContentLoaded', function() {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = 1;
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, { threshold: 0.1 });

            const animatedElements = document.querySelectorAll('.stat-card, .prediction-card, .chart-card, .ranking-card');
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
                    const number = parseFloat(originalText.replace('%', ''));
                    if (!isNaN(number)) {
                        animateValue(stat, 0, number, 1500, false);
                        stat.textContent = number.toFixed(1) + '%';
                    }
                } else if (!isNaN(parseInt(originalText))) {
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
                    element.textContent = 'Rs' + value.toLocaleString();
                } else {
                    element.textContent = value.toLocaleString();
                }
                if (progress < 1) {
                    window.requestAnimationFrame(step);
                }
            };
            window.requestAnimationFrame(step);
        }
    </script>
</body>
</html>