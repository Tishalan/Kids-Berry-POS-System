<?php
session_start();

date_default_timezone_set('Asia/Colombo');

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
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$branch_id = 1; // Hardcoded for now
$cashier_id = $_SESSION['cashiers_id'];

// ============================
// IMPROVED PREDICTION ALGORITHM
// ============================

function calculateDayOfWeekAverage($conn, $branch_id, $day_of_week, $cashier_id = null, $exclude_today = true) {
    // Get same day of week data from last 4 weeks
    $query = "SELECT 
                AVG(bi.price * bi.quantity - bi.discount) as avg_sales,
                COUNT(DISTINCT b.bill_no) as transaction_count,
                AVG(bi.quantity) as avg_items
              FROM bills b
              JOIN bill_items bi ON b.bill_no = bi.bill_no
              JOIN cashier_users c ON b.cashier_id = c.cashier_id
              WHERE b.branch_id = $branch_id
              AND DAYOFWEEK(b.date) = $day_of_week
              AND b.date >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)";
    
    if ($cashier_id) {
        $query .= " AND b.cashier_id = '$cashier_id'";
    }
    
    if ($exclude_today) {
        $query .= " AND DATE(b.date) != CURDATE()";
    }
    
    $result = $conn->query($query);
    if (!$result) {
        return ['avg_sales' => 0, 'transaction_count' => 0, 'avg_items' => 0];
    }
    return $result->fetch_assoc() ?? ['avg_sales' => 0, 'transaction_count' => 0, 'avg_items' => 0];
}

function calculateGrowthTrend($conn, $branch_id, $cashier_id = null) {
    // Calculate trend from last 7 days (excluding today)
    $query = "SELECT 
                DATE(b.date) as sale_date,
                SUM(bi.price * bi.quantity - bi.discount) as daily_sales
              FROM bills b
              JOIN bill_items bi ON b.bill_no = bi.bill_no
              WHERE b.branch_id = $branch_id
              AND b.date >= DATE_SUB(DATE_SUB(CURDATE(), INTERVAL 1 DAY), INTERVAL 7 DAY)
              AND b.date < CURDATE()";
    
    if ($cashier_id) {
        $query .= " AND b.cashier_id = '$cashier_id'";
    }
    
    $query .= " GROUP BY DATE(b.date)
                ORDER BY sale_date ASC";
    
    $result = $conn->query($query);
    if (!$result) {
        return 1.0;
    }
    
    $sales_data = [];
    $total_sales = 0;
    $day_count = 0;
    
    while ($row = $result->fetch_assoc()) {
        $sales_data[] = $row['daily_sales'];
        $total_sales += $row['daily_sales'];
        $day_count++;
    }
    
    if ($day_count < 2) return 1.0; // No trend data
    
    // Calculate average daily growth
    $growth_sum = 0;
    for ($i = 1; $i < count($sales_data); $i++) {
        if ($sales_data[$i-1] > 0) {
            $daily_growth = ($sales_data[$i] - $sales_data[$i-1]) / $sales_data[$i-1];
            $growth_sum += $daily_growth;
        }
    }
    
    $avg_growth = count($sales_data) > 1 ? ($growth_sum / (count($sales_data) - 1)) : 0;
    return 1 + ($avg_growth * 0.5); // Apply 50% of the growth rate
}

function calculateSeasonalityFactor($conn, $branch_id, $cashier_id = null) {
    $month = date('n');
    $year = date('Y');
    
    // Get average sales for this month from last year
    $query = "SELECT 
                AVG(bi.price * bi.quantity - bi.discount) as monthly_avg
              FROM bills b
              JOIN bill_items bi ON b.bill_no = bi.bill_no
              WHERE b.branch_id = $branch_id
              AND MONTH(b.date) = $month
              AND YEAR(b.date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 YEAR))";
    
    if ($cashier_id) {
        $query .= " AND b.cashier_id = '$cashier_id'";
    }
    
    $result = $conn->query($query);
    if (!$result) {
        return 1.0;
    }
    
    $last_year_avg = 0;
    $row = $result->fetch_assoc();
    if ($row) {
        $last_year_avg = $row['monthly_avg'] ?? 0;
    }
    
    // Get overall monthly average for current year
    $query = "SELECT 
                AVG(bi.price * bi.quantity - bi.discount) as overall_avg
              FROM bills b
              JOIN bill_items bi ON b.bill_no = bi.bill_no
              WHERE b.branch_id = $branch_id
              AND YEAR(b.date) = $year";
    
    if ($cashier_id) {
        $query .= " AND b.cashier_id = '$cashier_id'";
    }
    
    $result = $conn->query($query);
    if (!$result) {
        return 1.0;
    }
    
    $overall_avg = 0;
    $row = $result->fetch_assoc();
    if ($row) {
        $overall_avg = $row['overall_avg'] ?? 0;
    }
    
    if ($overall_avg > 0 && $last_year_avg > 0) {
        $factor = $last_year_avg / $overall_avg;
        // Clamp between 0.8 and 1.2 to prevent extreme values
        return max(0.8, min(1.2, $factor));
    }
    
    return 1.0;
}

function calculateSpecialDayFactor() {
    // Check for weekend factor (increase sales on weekends)
    $day_of_week = date('N');
    if ($day_of_week >= 6) { // Saturday (6) or Sunday (7)
        return 1.2; // 20% increase on weekends
    }
    
    // Check for special days (holidays, festivals, etc.)
    $month = date('n');
    $day = date('j');
    
    // Major shopping days (example: 1st and 15th of month)
    if ($day == 1 || $day == 15) {
        return 1.15; // 15% increase on paydays
    }
    
    // End of month shopping
    if ($day >= 25 && $day <= 31) {
        return 1.1; // 10% increase at month end
    }
    
    // Festival seasons (example: April - May for New Year)
    if ($month == 4 || $month == 5) {
        return 1.25; // 25% increase during festival season
    }
    
    // December holiday season
    if ($month == 12) {
        return 1.3; // 30% increase during Christmas season
    }
    
    return 1.0;
}

// NEW FUNCTION: Calculate branch total prediction (similar to admin dashboard)
function predictBranchTotalSales($conn, $branch_id, $day_of_week) {
    // Get historical data for the last 30 days
    $prediction_query = $conn->query("
        SELECT 
            DATE(b.date) as sale_date,
            SUM(bi.price * bi.quantity - bi.discount) as daily_sales,
            COUNT(DISTINCT b.bill_no) as transaction_count,
            COUNT(DISTINCT b.customer_id) as customer_count
        FROM bills b
        JOIN bill_items bi ON b.bill_no = bi.bill_no
        WHERE b.branch_id = $branch_id
        AND b.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY DATE(b.date)
        ORDER BY sale_date ASC
    ");
    
    $historical_data = [];
    if ($prediction_query && $prediction_query->num_rows > 0) {
        while ($row = $prediction_query->fetch_assoc()) {
            $historical_data[] = $row;
        }
    }
    
    // Generate ML predictions
    return generateMLPredictions($historical_data, 0);
}

// NEW FUNCTION: Generate ML predictions for branch total
function generateMLPredictions($historical_data, $today_sales = 0) {
    if (empty($historical_data)) {
        return [
            'today_prediction' => 0,
            'today_accuracy' => 0,
            'confidence_level' => 0
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
    $today_prediction *= $seasonality_factor;
    
    // Calculate confidence level (higher if we have more historical data)
    $data_points = count($historical_data);
    $confidence_level = min(95, 70 + ($data_points / 30 * 25)); // Scales with data points
    
    return [
        'today_prediction' => $today_prediction,
        'today_accuracy' => 0, // Will be calculated when we have actual sales
        'confidence_level' => round($confidence_level),
        'data_points' => $data_points
    ];
}

function predictCashierSales($conn, $cashier_id, $branch_id, $day_of_week) {
    // Step 1: Get cashier's personal day-of-week average
    $cashier_data = calculateDayOfWeekAverage($conn, $branch_id, $day_of_week, $cashier_id, true);
    $cashier_avg = $cashier_data['avg_sales'] ?? 0;
    
    // If cashier doesn't have enough history, use branch average
    if ($cashier_avg < 100) { // Minimum Rs.100 history
        $branch_data = calculateDayOfWeekAverage($conn, $branch_id, $day_of_week, null, true);
        $cashier_avg = $branch_data['avg_sales'] ?? 1000; // Default Rs.1000
    }
    
    // Step 2: Apply personal growth trend
    $personal_growth = calculateGrowthTrend($conn, $branch_id, $cashier_id);
    
    // Step 3: Apply seasonality factor
    $seasonality = calculateSeasonalityFactor($conn, $branch_id, $cashier_id);
    
    // Step 4: Apply special day factor
    $special_factor = calculateSpecialDayFactor();
    
    // Step 5: Apply recent performance weight (last 3 days)
    $recent_query = "SELECT 
                        SUM(bi.price * bi.quantity - bi.discount) as recent_sales
                      FROM bills b
                      JOIN bill_items bi ON b.bill_no = bi.bill_no
                      WHERE b.cashier_id = '$cashier_id'
                      AND b.date >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
                      AND DATE(b.date) != CURDATE()";
    
    $recent_result = $conn->query($recent_query);
    $recent_sales = 0;
    $recent_days = 0;
    
    if ($recent_result) {
        $row = $recent_result->fetch_assoc();
        $recent_sales = $row['recent_sales'] ?? 0;
        $recent_days = 3; // Assume 3 days for calculation
    }
    
    $recent_avg = $recent_days > 0 ? ($recent_sales / $recent_days) : $cashier_avg;
    
    // Step 6: Weighted average calculation
    // 50% personal average, 30% recent performance, 20% adjusted by factors
    $base_prediction = ($cashier_avg * 0.5) + ($recent_avg * 0.3);
    $adjusted_prediction = $base_prediction * $personal_growth * $seasonality * $special_factor;
    
    // Step 7: Apply smoothing and noise reduction
    $prediction = round($adjusted_prediction * 100) / 100;
    
    // Ensure reasonable bounds
    $min_prediction = 500; // Minimum Rs.500 prediction
    $max_prediction = $cashier_avg * 3; // Maximum 3x historical average
    
    return max($min_prediction, min($prediction, $max_prediction));
}

function calculateAccuracy($actual, $predicted) {
    if ($predicted == 0) return 0;
    
    $difference = abs($actual - $predicted);
    $accuracy = 100 - (($difference / $predicted) * 100);
    
    // Clamp between 0 and 100
    $accuracy = max(0, min(100, round($accuracy, 1)));
    
    // If actual is 0 and predicted is high, accuracy should be low
    if ($actual == 0 && $predicted > 0) {
        return 0;
    }
    
    return $accuracy;
}

function getAccuracyBadge($accuracy) {
    if ($accuracy >= 85) {
        return ['color' => '#2ecc71', 'label' => 'High', 'class' => 'accuracy-high', 'icon' => 'check-circle'];
    } elseif ($accuracy >= 70) {
        return ['color' => '#f39c12', 'label' => 'Medium', 'class' => 'accuracy-medium', 'icon' => 'exclamation-circle'];
    } else {
        return ['color' => '#e74c3c', 'label' => 'Low', 'class' => 'accuracy-low', 'icon' => 'times-circle'];
    }
}

function getStatusBadge($actual, $predicted) {
    if ($predicted == 0) {
        return ['color' => '#3498db', 'label' => 'No Prediction', 'class' => 'status-ontrack', 'icon' => 'equals'];
    }
    
    $percentage = ($actual - $predicted) / $predicted * 100;
    
    if ($percentage >= 15) {
        return ['color' => '#2ecc71', 'label' => 'Excellent', 'class' => 'status-exceeded', 'icon' => 'arrow-up'];
    } elseif ($percentage >= 5) {
        return ['color' => '#27ae60', 'label' => 'Exceeded', 'class' => 'status-exceeded', 'icon' => 'arrow-up'];
    } elseif ($percentage >= -5) {
        return ['color' => '#3498db', 'label' => 'On Track', 'class' => 'status-ontrack', 'icon' => 'equals'];
    } elseif ($percentage >= -15) {
        return ['color' => '#e67e22', 'label' => 'Below', 'class' => 'status-below', 'icon' => 'arrow-down'];
    } else {
        return ['color' => '#e74c3c', 'label' => 'Poor', 'class' => 'status-below', 'icon' => 'arrow-down'];
    }
}

// ============================
// GET TODAY'S DATA
// ============================

$today = date('Y-m-d');
$day_of_week = date('N'); // 1=Monday, 7=Sunday
$current_time = date('H:i:s');

// Get cashier details
$cashier_query = "SELECT * FROM cashier_users WHERE cashier_id = '$cashier_id'";
$cashier_result = $conn->query($cashier_query);
$cashier_data = $cashier_result->fetch_assoc();
$cashier_name = $cashier_data['name'] ?? 'Cashier';
$cashier_email = $cashier_data['email'] ?? '';

// Get today's actual sales for this cashier
$today_sales_query = "SELECT 
                        COALESCE(SUM(bi.price * bi.quantity - bi.discount), 0) as today_sales,
                        COUNT(DISTINCT b.bill_no) as transaction_count,
                        COALESCE(AVG(bi.price * bi.quantity - bi.discount), 0) as avg_transaction,
                        COALESCE(SUM(bi.quantity), 0) as total_items
                      FROM bills b
                      JOIN bill_items bi ON b.bill_no = bi.bill_no
                      WHERE b.cashier_id = '$cashier_id'
                      AND DATE(b.date) = '$today'";

$today_sales_result = $conn->query($today_sales_query);
$today_sales_data = $today_sales_result->fetch_assoc();
$today_actual = $today_sales_data['today_sales'] ?? 0;
$transaction_count = $today_sales_data['transaction_count'] ?? 0;
$avg_transaction = $today_sales_data['avg_transaction'] ?? 0;
$total_items = $today_sales_data['total_items'] ?? 0;

// Calculate prediction using improved algorithm
$predicted_sales = predictCashierSales($conn, $cashier_id, $branch_id, $day_of_week);
$accuracy = calculateAccuracy($today_actual, $predicted_sales);
$accuracy_badge = getAccuracyBadge($accuracy);
$status_badge = getStatusBadge($today_actual, $predicted_sales);

// NEW: Get branch total prediction (similar to admin dashboard)
$branch_prediction_data = predictBranchTotalSales($conn, $branch_id, $day_of_week);
$branch_today_prediction = $branch_prediction_data['today_prediction'];
$branch_confidence_level = $branch_prediction_data['confidence_level'];

// Get today's actual branch sales
$branch_today_sales_query = "SELECT 
                        COALESCE(SUM(bi.price * bi.quantity - bi.discount), 0) as today_sales
                      FROM bills b
                      JOIN bill_items bi ON b.bill_no = bi.bill_no
                      WHERE b.branch_id = '$branch_id'
                      AND DATE(b.date) = '$today'";

$branch_today_sales_result = $conn->query($branch_today_sales_query);
$branch_today_sales_data = $branch_today_sales_result->fetch_assoc();
$branch_today_actual = $branch_today_sales_data['today_sales'] ?? 0;

// Calculate branch prediction accuracy
$branch_accuracy = calculateAccuracy($branch_today_actual, $branch_today_prediction);
$branch_accuracy_badge = getAccuracyBadge($branch_accuracy);

// Calculate branch status
$branch_difference = $branch_today_actual - $branch_today_prediction;
$branch_percentage = $branch_today_prediction > 0 ? ($branch_difference / $branch_today_prediction) * 100 : 0;

if ($branch_difference > 0) {
    $branch_status = 'exceeded';
    $branch_status_label = 'Exceeding Predictions';
    $branch_status_class = 'status-exceeded';
    $branch_status_icon = 'arrow-up';
} elseif ($branch_difference < 0) {
    $branch_status = 'below';
    $branch_status_label = 'Below Predictions';
    $branch_status_class = 'status-below';
    $branch_status_icon = 'arrow-down';
} else {
    $branch_status = 'ontrack';
    $branch_status_label = 'On Track';
    $branch_status_class = 'status-ontrack';
    $branch_status_icon = 'equals';
}

// Get all active cashiers for ranking
$all_cashiers_query = "SELECT cashier_id, name, email FROM cashier_users WHERE status = 'active' ORDER BY name";
$all_cashiers_result = $conn->query($all_cashiers_query);

$cashiers_ranking = [];
$total_branch_sales = 0;
$cashier_count = 0;

while ($cashier = $all_cashiers_result->fetch_assoc()) {
    $c_id = $cashier['cashier_id'];
    $c_name = $cashier['name'];
    $c_email = $cashier['email'];
    
    // Get today's sales for each cashier
    $sales_query = "SELECT 
                    COALESCE(SUM(bi.price * bi.quantity - bi.discount), 0) as today_sales,
                    COUNT(DISTINCT b.bill_no) as transaction_count,
                    COALESCE(SUM(bi.quantity), 0) as total_items
                  FROM bills b
                  JOIN bill_items bi ON b.bill_no = bi.bill_no
                  WHERE b.cashier_id = '$c_id'
                  AND DATE(b.date) = '$today'";
    
    $sales_result = $conn->query($sales_query);
    $sales_data = $sales_result->fetch_assoc();
    $c_today_actual = $sales_data['today_sales'] ?? 0;
    $c_transaction_count = $sales_data['transaction_count'] ?? 0;
    $c_total_items = $sales_data['total_items'] ?? 0;
    
    // Calculate prediction for each cashier
    $c_predicted = predictCashierSales($conn, $c_id, $branch_id, $day_of_week);
    $c_accuracy = calculateAccuracy($c_today_actual, $c_predicted);
    $c_status = getStatusBadge($c_today_actual, $c_predicted);
    
    // Get cashier's historical average for context
    $avg_query = "SELECT 
                    AVG(bi.price * bi.quantity - bi.discount) as avg_daily_sales
                  FROM bills b
                  JOIN bill_items bi ON b.bill_no = bi.bill_no
                  WHERE b.cashier_id = '$c_id'
                  AND b.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    
    $avg_result = $conn->query($avg_query);
    $avg_data = $avg_result->fetch_assoc();
    $c_avg_daily = $avg_data['avg_daily_sales'] ?? 0;
    
    $cashiers_ranking[] = [
        'cashier_id' => $c_id,
        'name' => $c_name,
        'email' => $c_email,
        'today_actual' => $c_today_actual,
        'today_predicted' => $c_predicted,
        'accuracy' => $c_accuracy,
        'status' => $c_status,
        'difference' => $c_today_actual - $c_predicted,
        'transaction_count' => $c_transaction_count,
        'total_items' => $c_total_items,
        'avg_daily_sales' => $c_avg_daily
    ];
    
    $total_branch_sales += $c_today_actual;
    $cashier_count++;
}

// Sort cashiers by actual sales (descending)
usort($cashiers_ranking, function($a, $b) {
    return $b['today_actual'] <=> $a['today_actual'];
});

// Add rank and find my rank
$my_rank = null;
foreach ($cashiers_ranking as $index => &$cashier) {
    $cashier['rank'] = $index + 1;
    if ($cashier['cashier_id'] == $cashier_id) {
        $my_rank = $index + 1;
    }
}

// Calculate dashboard statistics
$active_cashiers = $cashier_count;
$avg_per_cashier = ($cashier_count > 0) ? $total_branch_sales / $cashier_count : 0;
$best_performer = !empty($cashiers_ranking) ? $cashiers_ranking[0]['name'] : 'N/A';
$best_sales = !empty($cashiers_ranking) ? $cashiers_ranking[0]['today_actual'] : 0;

// Calculate average accuracy
$total_accuracy = 0;
$accuracy_count = 0;
foreach ($cashiers_ranking as $cashier) {
    if ($cashier['today_predicted'] > 0) {
        $total_accuracy += $cashier['accuracy'];
        $accuracy_count++;
    }
}
$avg_accuracy = ($accuracy_count > 0) ? $total_accuracy / $accuracy_count : 0;

// Estimated commission (assuming 5% commission)
$commission_rate = 0.05;
$estimated_commission = $today_actual * $commission_rate;
$total_estimated_commission = $total_branch_sales * $commission_rate;

// Get today's predictions total
$today_total_predicted = 0;
foreach ($cashiers_ranking as $cashier) {
    $today_total_predicted += $cashier['today_predicted'];
}

// Calculate today's prediction accuracy for branch
$today_branch_accuracy = calculateAccuracy($total_branch_sales, $today_total_predicted);
$today_branch_accuracy_badge = getAccuracyBadge($today_branch_accuracy);

// ============================
// GET HISTORICAL DATA FOR CHART
// ============================

// Last 7 days actual sales for this cashier
$historical_query = "SELECT 
                    DATE(b.date) as sale_date,
                    SUM(bi.price * bi.quantity - bi.discount) as daily_sales
                  FROM bills b
                  JOIN bill_items bi ON b.bill_no = bi.bill_no
                  WHERE b.cashier_id = '$cashier_id'
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

// Next 7 days predictions
$predictions_data = [];
$prediction_labels = [];

// Start from tomorrow
for ($i = 1; $i <= 7; $i++) {
    $date = date('Y-m-d', strtotime("+$i days"));
    $future_day_of_week = date('N', strtotime($date));
    
    // Calculate prediction for this future day
    $day_prediction = predictCashierSales($conn, $cashier_id, $branch_id, $future_day_of_week);
    $predictions_data[] = $day_prediction;
    $prediction_labels[] = date('D', strtotime($date));
}

// Combine for chart
$chart_labels = array_merge($historical_labels, ['Today'], $prediction_labels);
$actual_sales_values = array_values($historical_data);
$predicted_values = array_merge(
    array_fill(0, 7, null), // No predictions for past days
    [$today_actual], // Today's actual
    $predictions_data // Future predictions
);

// Handle logout
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['logout'])) {
    unset($_SESSION['cashiers_id']);
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
    <title>KIDS Berry - Sales Prediction Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #5a3d7e;
            --secondary: #28a745;
            --accent: #dc3545;
            --warning: #ffc107;
            --danger: #dc3545;
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
            --gradient-info: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            --gradient-warning: linear-gradient(135deg, #f39c12 0%, #e67e22 100%);
            --prediction-blue: #3498db;
            --success-green: #2ecc71;
            --warning-orange: #e67e22;
            --danger-red: #e74c3c;
            --ml-purple: #9b59b6;
            --ml-dark-purple: #8e44ad;
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
            background: var(--gradient-accent);
            box-shadow: 0 6px 12px rgba(220, 53, 69, 0.3);
        }

        .nav a:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 15px rgba(0,0,0,0.2);
        }

        .main-container {
            padding: 0 30px;
            max-width: 1600px;
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

        /* ML Prediction Card (New) */
        .ml-prediction-card {
            background: linear-gradient(135deg, rgba(155, 89, 182, 0.1), rgba(142, 68, 173, 0.1));
            border: 2px solid var(--ml-purple);
            padding: 25px;
            border-radius: var(--radius);
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }

        .ml-prediction-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--ml-purple), var(--ml-dark-purple), #3498db);
        }

        .ml-header {
            text-align: center;
            margin-bottom: 25px;
        }

        .ml-header h3 {
            color: var(--ml-dark-purple);
            font-size: 24px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .ml-tag {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: linear-gradient(135deg, var(--ml-purple), var(--ml-dark-purple));
            color: white;
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
        }

        .prediction-comparison-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-top: 20px;
        }

        .prediction-comparison-item {
            background: rgba(255, 255, 255, 0.9);
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            transition: var(--transition);
        }

        .prediction-comparison-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0,0,0,0.1);
        }

        .prediction-comparison-item:nth-child(1) { border-left-color: var(--primary); }
        .prediction-comparison-item:nth-child(2) { border-left-color: var(--prediction-blue); }
        .prediction-comparison-item:nth-child(3) { border-left-color: var(--ml-purple); }
        .prediction-comparison-item:nth-child(4) { border-left-color: var(--success); }

        .prediction-label {
            font-size: 14px;
            color: #666;
            text-transform: uppercase;
            font-weight: 700;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .prediction-value {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .actual-value { color: var(--primary); }
        .predicted-value { color: var(--prediction-blue); }
        .ml-value { color: var(--ml-purple); }
        .difference-value { color: var(--success); }

        .ml-confidence {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-top: 20px;
            padding: 15px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 10px;
        }

        .confidence-meter {
            flex: 1;
            height: 10px;
            background: var(--light);
            border-radius: 5px;
            overflow: hidden;
        }

        .confidence-fill {
            height: 100%;
            border-radius: 5px;
            transition: width 1s ease;
        }

        .confidence-high {
            background: linear-gradient(90deg, var(--success-green), #27ae60);
        }

        .confidence-medium {
            background: linear-gradient(90deg, var(--warning-orange), #d35400);
        }

        .confidence-low {
            background: linear-gradient(90deg, var(--danger-red), #c0392b);
        }

        .ml-algorithm-info {
            margin-top: 20px;
            padding: 15px;
            background: rgba(155, 89, 182, 0.05);
            border-radius: 10px;
            border-left: 4px solid var(--ml-purple);
        }

        .ml-algorithm-info h4 {
            color: var(--ml-dark-purple);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .ml-features {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }

        .ml-feature {
            background: white;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            color: var(--ml-purple);
            border: 1px solid rgba(155, 89, 182, 0.2);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* Stats Cards Grid */
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
            cursor: pointer;
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

        .stat-card:nth-child(1) { border-left: 5px solid var(--primary); }
        .stat-card:nth-child(2) { border-left: 5px solid var(--success); }
        .stat-card:nth-child(3) { border-left: 5px solid var(--info); }
        .stat-card:nth-child(4) { border-left: 5px solid #FFD700; }
        .stat-card:nth-child(5) { border-left: 5px solid var(--warning); }
        .stat-card:nth-child(6) { border-left: 5px solid #9b59b6; }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin: 0 auto 15px;
            color: white;
            background: var(--gradient-primary);
            transition: var(--transition);
        }

        .stat-card:hover .stat-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .stat-card:nth-child(1) .stat-icon { background: var(--gradient-primary); }
        .stat-card:nth-child(2) .stat-icon { background: var(--gradient-secondary); }
        .stat-card:nth-child(3) .stat-icon { background: var(--gradient-info); }
        .stat-card:nth-child(4) .stat-icon { background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%); }
        .stat-card:nth-child(5) .stat-icon { background: var(--gradient-warning); }
        .stat-card:nth-child(6) .stat-icon { background: linear-gradient(135deg, #9b59b6 0%, #8e44ad 100%); }

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

        .stat-card:nth-child(2) .value { color: var(--success); }
        .stat-card:nth-child(3) .value { color: var(--info); }
        .stat-card:nth-child(4) .value { color: #FFA500; }
        .stat-card:nth-child(5) .value { color: var(--warning); }
        .stat-card:nth-child(6) .value { color: #9b59b6; }

        .stat-card .sub-value {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
            font-weight: 600;
        }

        /* Prediction Comparison Card */
        .prediction-comparison {
            background: white;
            border-radius: var(--radius);
            padding: 25px;
            box-shadow: var(--card-shadow);
            margin-bottom: 30px;
            transition: var(--transition);
        }

        .prediction-comparison:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }

        .comparison-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-top: 20px;
        }

        .comparison-item {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid;
            transition: var(--transition);
        }

        .comparison-item:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .comparison-item:nth-child(1) { border-left-color: var(--success); }
        .comparison-item:nth-child(2) { border-left-color: var(--prediction-blue); }
        .comparison-item:nth-child(3) { border-left-color: var(--warning); }
        .comparison-item:nth-child(4) { border-left-color: var(--primary); }

        .comparison-label {
            font-size: 14px;
            color: #666;
            text-transform: uppercase;
            font-weight: 700;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .comparison-value {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .difference-value {
            font-size: 20px;
            font-weight: 700;
            padding: 5px 10px;
            border-radius: 6px;
            display: inline-block;
        }

        .difference-positive {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success);
        }

        .difference-negative {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
        }

        .difference-neutral {
            background: rgba(52, 152, 219, 0.1);
            color: var(--prediction-blue);
        }

        .accuracy-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 14px;
        }

        .accuracy-high {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success);
            border: 2px solid var(--success);
        }

        .accuracy-medium {
            background: rgba(243, 156, 18, 0.1);
            color: var(--warning);
            border: 2px solid var(--warning);
        }

        .accuracy-low {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
            border: 2px solid var(--danger);
        }

        /* Chart Container */
        .chart-card {
            background: white;
            border-radius: var(--radius);
            padding: 25px;
            box-shadow: var(--card-shadow);
            margin-bottom: 30px;
            transition: var(--transition);
        }

        .chart-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }

        .chart-container {
            height: 350px;
            width: 100%;
            margin-top: 15px;
        }

        /* Cashier Ranking Table */
        .table-container {
            overflow-x: auto;
            border-radius: var(--radius);
            box-shadow: var(--card-shadow);
            margin-bottom: 25px;
            border: 1px solid rgba(0,0,0,0.05);
        }

        .ranking-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        .ranking-table th {
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

        .ranking-table tr {
            transition: var(--transition);
            animation: fadeIn 0.5s ease;
        }

        .ranking-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        .ranking-table tr:hover {
            background-color: #e8f4fc;
            transform: translateX(5px);
        }

        .ranking-table tr.current-user {
            background-color: rgba(90, 61, 126, 0.1);
            font-weight: 600;
        }

        .ranking-table tr.current-user:hover {
            background-color: rgba(90, 61, 126, 0.15);
        }

        .ranking-table td {
            padding: 16px;
            border-bottom: 1px solid #eee;
            font-size: 14px;
            font-weight: 500;
        }

        .rank-badge {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            color: white;
            font-size: 14px;
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
            background: var(--light);
            color: var(--dark);
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .status-exceeded {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success);
            border: 1px solid var(--success);
        }

        .status-ontrack {
            background: rgba(52, 152, 219, 0.1);
            color: var(--prediction-blue);
            border: 1px solid var(--prediction-blue);
        }

        .status-below {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
            border: 1px solid var(--danger);
        }

        .action-btn {
            background: var(--gradient-primary);
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

        .action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(90, 61, 126, 0.3);
        }

        /* Refresh Indicator */
        .refresh-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--primary);
            font-size: 14px;
            font-weight: 600;
            padding: 8px 15px;
            background: rgba(90, 61, 126, 0.1);
            border-radius: 20px;
            border: 1px solid rgba(90, 61, 126, 0.2);
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
            width: 80%;
            max-width: 800px;
            max-height: 80vh;
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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

        /* Notification */
        .notification {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: var(--primary);
            color: white;
            padding: 18px 30px;
            border-radius: 10px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
            display: none;
            z-index: 1000;
            animation: slideInRight 0.5s ease, fadeOut 0.5s ease 2.5s forwards;
            font-weight: 600;
            max-width: 350px;
            backdrop-filter: blur(10px);
        }

        /* Auto-refresh animation */
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }

        .pulse {
            animation: pulse 2s infinite;
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

        @keyframes slideInRight {
            from { transform: translateX(150px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; visibility: hidden; }
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        /* Responsive Design */
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
            
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .comparison-grid,
            .prediction-comparison-grid {
                grid-template-columns: 1fr;
            }
            
            .chart-container {
                height: 300px;
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
            
            .ranking-table {
                font-size: 12px;
            }
            
            .ranking-table th,
            .ranking-table td {
                padding: 10px;
            }
        }

        @media (min-width: 1024px) {
            .stats-container {
                grid-template-columns: repeat(6, 1fr);
            }
            
            .comparison-grid,
            .prediction-comparison-grid {
                grid-template-columns: repeat(4, 1fr);
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
            <h1><i class="fas fa-chart-line"></i> Sales Prediction Dashboard</h1>
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
        <a href="bill_management.php"><i class="fas fa-history"></i> Bill History</a>
        <a href="report.php"><i class="fas fa-chart-line"></i> Reports</a>
        <a href="credit_payments.php"><i class="fas fa-credit-card"></i> Credit Customers</a>
        <a href="return_sale.php"><i class="fas fa-undo-alt"></i> Manage Returns</a>
        <a href="prediction_dashboard.php" class="active"><i class="fas fa-bullseye"></i> Predictions</a>
    </div>
    
    <div class="main-container">
        <!-- ML Today's Prediction Card (New) -->
        <div class="ml-prediction-card">
            <div class="ml-header">
                <h3><i class="fas fa-robot"></i> Today's Store Sales Forecast <span class="ml-tag"><i class="fas fa-brain"></i> ML Powered</span></h3>
                <p style="color: #666; margin-bottom: 10px;">AI-powered prediction for total store sales today</p>
            </div>
            
            <div class="prediction-comparison-grid">
                <div class="prediction-comparison-item">
                    <div class="prediction-label">
                        <i class="fas fa-chart-line" style="color: var(--primary);"></i> Actual Store Sales
                    </div>
                    <div class="prediction-value actual-value">Rs<?php echo number_format($branch_today_actual, 2); ?></div>
                    <div class="sub-value">Total sales from all cashiers</div>
                </div>
                
                <div class="prediction-comparison-item">
                    <div class="prediction-label">
                        <i class="fas fa-bullseye" style="color: var(--prediction-blue);"></i> ML Predicted Sales
                    </div>
                    <div class="prediction-value predicted-value">Rs<?php echo number_format($branch_today_prediction, 2); ?></div>
                    <div class="sub-value">AI model prediction</div>
                </div>
                
                <div class="prediction-comparison-item">
                    <div class="prediction-label">
                        <i class="fas fa-balance-scale" style="color: var(--ml-purple);"></i> Difference
                    </div>
                    <div class="prediction-value difference-value" style="color: <?php echo $branch_difference > 0 ? 'var(--success)' : ($branch_difference < 0 ? 'var(--danger)' : 'var(--prediction-blue)'); ?>">
                        <?php 
                            if ($branch_difference > 0) echo '+';
                            echo number_format($branch_difference, 2);
                        ?>
                    </div>
                    <div class="sub-value">Actual vs Prediction</div>
                </div>
                
                <div class="prediction-comparison-item">
                    <div class="prediction-label">
                        <i class="fas fa-chart-bar" style="color: var(--success);"></i> Prediction Accuracy
                    </div>
                    <div class="prediction-value" style="font-size: 28px;">
                        <span class="accuracy-badge <?php echo $branch_accuracy_badge['class']; ?>">
                            <i class="fas fa-<?php echo $branch_accuracy_badge['icon']; ?>"></i>
                            <?php echo number_format($branch_accuracy, 1); ?>%
                        </span>
                    </div>
                    <div class="sub-value">ML Model Accuracy</div>
                </div>
            </div>
            
            <div class="ml-confidence">
                <div style="font-weight: 600; color: var(--ml-dark-purple); min-width: 120px;">
                    <i class="fas fa-shield-alt"></i> Model Confidence:
                </div>
                <div class="confidence-meter">
                    <div class="confidence-fill <?php 
                        echo $branch_confidence_level >= 80 ? 'confidence-high' : 
                            ($branch_confidence_level >= 60 ? 'confidence-medium' : 'confidence-low');
                    ?>" style="width: <?php echo $branch_confidence_level; ?>%;"></div>
                </div>
                <div style="font-weight: 700; color: var(--ml-dark-purple); min-width: 50px;">
                    <?php echo $branch_confidence_level; ?>%
                </div>
            </div>
            
            <div class="ml-algorithm-info">
                <h4><i class="fas fa-cogs"></i> ML Algorithm Features</h4>
                <p style="color: #666; font-size: 14px; margin-bottom: 10px;">
                    Predictions based on 30-day historical data analysis with day-of-week patterns, seasonality factors, and growth trends.
                </p>
                <div class="ml-features">
                    <div class="ml-feature"><i class="fas fa-calendar-alt"></i> Day-of-Week Analysis</div>
                    <div class="ml-feature"><i class="fas fa-chart-line"></i> Growth Trends</div>
                    <div class="ml-feature"><i class="fas fa-sun"></i> Seasonality</div>
                    <div class="ml-feature"><i class="fas fa-chart-bar"></i> Historical Patterns</div>
                </div>
            </div>
            
            <div style="margin-top: 20px; padding: 15px; background: rgba(255, 255, 255, 0.9); border-radius: 10px; border-left: 4px solid <?php echo $branch_status == 'exceeded' ? 'var(--success)' : ($branch_status == 'below' ? 'var(--danger)' : 'var(--prediction-blue)'); ?>;">
                <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div style="width: 15px; height: 15px; background: <?php echo $branch_status == 'exceeded' ? 'var(--success)' : ($branch_status == 'below' ? 'var(--danger)' : 'var(--prediction-blue)'); ?>; border-radius: 3px;"></div>
                        <span style="font-weight: 600; color: var(--dark);">Store Status:</span>
                        <span class="status-badge <?php echo $branch_status_class; ?>">
                            <i class="fas fa-<?php echo $branch_status_icon; ?>"></i>
                            <?php echo $branch_status_label; ?>
                        </span>
                    </div>
                    <div style="flex: 1; height: 1px; background: #e0e0e0;"></div>
                    <div style="font-size: 14px; color: #666;">
                        <i class="fas fa-info-circle"></i>
                        <?php 
                            if ($branch_difference > 0) {
                                $percentage = ($branch_today_prediction > 0) ? abs(($branch_difference / $branch_today_prediction) * 100) : 0;
                                echo "Store is exceeding predictions by " . number_format($percentage, 1) . "%";
                            } elseif ($branch_difference < 0) {
                                $percentage = ($branch_today_prediction > 0) ? abs(($branch_difference / $branch_today_prediction) * 100) : 0;
                                echo "Store is below predictions by " . number_format($percentage, 1) . "%";
                            } else {
                                echo "Store sales exactly match predictions";
                            }
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-tachometer-alt"></i> Today's Performance Metrics</h2>
                <div class="refresh-indicator pulse">
                    <i class="fas fa-sync"></i>
                    Auto-refresh in <span id="refreshTimer">30</span>s
                </div>
            </div>
            
            <div class="stats-container">
                <div class="stat-card" onclick="showNotification('Total Active Sales Officers: <?php echo $active_cashiers; ?>')">
                    <div class="stat-icon">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <h3>Active Sales Officers</h3>
                    <div class="value"><?php echo $active_cashiers; ?></div>
                    <div class="sub-value">Including you</div>
                </div>
                
                <div class="stat-card" onclick="showNotification('Your Sales Today: Rs<?php echo number_format($today_actual, 2); ?>')">
                    <div class="stat-icon">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <h3>Your Today's Sales</h3>
                    <div class="value">Rs<?php echo number_format($today_actual, 2); ?></div>
                    <div class="sub-value"><?php echo $transaction_count; ?> transactions</div>
                </div>
                
                <div class="stat-card" onclick="showNotification('Average per Sales Officer: Rs<?php echo number_format($avg_per_cashier, 2); ?>')">
                    <div class="stat-icon">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <h3>Average per Officer</h3>
                    <div class="value">Rs<?php echo number_format($avg_per_cashier, 2); ?></div>
                    <div class="sub-value">Branch average</div>
                </div>
                
                <div class="stat-card" onclick="showNotification('Best Performer: <?php echo htmlspecialchars($best_performer); ?> (Rs<?php echo number_format($best_sales, 2); ?>)')">
                    <div class="stat-icon">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <h3>Best Performer</h3>
                    <div class="value"><?php echo htmlspecialchars($best_performer); ?></div>
                    <div class="sub-value">Rs<?php echo number_format($best_sales, 2); ?></div>
                </div>
                
                <div class="stat-card" onclick="showNotification('Prediction Accuracy: <?php echo number_format($avg_accuracy, 1); ?>%')">
                    <div class="stat-icon">
                        <i class="fas fa-bullseye"></i>
                    </div>
                    <h3>Accuracy Rate</h3>
                    <div class="value"><?php echo number_format($avg_accuracy, 1); ?>%</div>
                    <div class="sub-value">Branch average</div>
                </div>
                
                <div class="stat-card" onclick="showNotification('Your Estimated Commission: Rs<?php echo number_format($estimated_commission, 2); ?> (5% of sales)')">
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <h3>Your Commission</h3>
                    <div class="value">Rs<?php echo number_format($estimated_commission, 2); ?></div>
                    <div class="sub-value">5% commission rate</div>
                </div>
            </div>
        </div>

        <!-- Today's Prediction Comparison -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-chart-pie"></i> Your Personal Prediction Comparison</h2>
                <div class="refresh-indicator">
                    <i class="fas fa-user-circle"></i>
                    <?php echo htmlspecialchars($cashier_name); ?> (Rank: #<?php echo $my_rank ?? 'N/A'; ?>)
                </div>
            </div>
            
            <div class="prediction-comparison">
                <div class="comparison-grid">
                    <div class="comparison-item">
                        <div class="comparison-label">
                            <i class="fas fa-chart-line"></i> Your Actual Sales
                        </div>
                        <div class="comparison-value actual-value">Rs<?php echo number_format($today_actual, 2); ?></div>
                        <div class="sub-value">Based on <?php echo $transaction_count; ?> transactions</div>
                    </div>
                    
                    <div class="comparison-item">
                        <div class="comparison-label">
                            <i class="fas fa-bullseye"></i> Your Predicted Sales
                        </div>
                        <div class="comparison-value predicted-value">Rs<?php echo number_format($predicted_sales, 2); ?></div>
                        <div class="sub-value">Personalized Prediction</div>
                    </div>
                    
                    <div class="comparison-item">
                        <div class="comparison-label">
                            <i class="fas fa-balance-scale"></i> Your Difference
                        </div>
                        <div class="comparison-value difference-value 
                            <?php 
                                $difference = $today_actual - $predicted_sales;
                                if ($difference > 0) echo 'difference-positive';
                                elseif ($difference < 0) echo 'difference-negative';
                                else echo 'difference-neutral';
                            ?>">
                            <?php 
                                if ($difference > 0) echo '+';
                                echo number_format($difference, 2);
                            ?>
                        </div>
                        <div class="sub-value">Actual vs Predicted</div>
                    </div>
                    
                    <div class="comparison-item">
                        <div class="comparison-label">
                            <i class="fas fa-check-circle"></i> Your Prediction Accuracy
                        </div>
                        <div class="comparison-value">
                            <span class="accuracy-badge <?php echo $accuracy_badge['class']; ?>">
                                <i class="fas fa-<?php echo $accuracy_badge['icon']; ?>"></i>
                                <?php echo number_format($accuracy, 1); ?>%
                            </span>
                        </div>
                        <div class="sub-value"><?php echo $accuracy_badge['label']; ?> Confidence</div>
                    </div>
                </div>
                
                <div style="margin-top: 25px; padding: 20px; background: #f8f9fa; border-radius: 10px; border-left: 4px solid var(--primary);">
                    <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="width: 15px; height: 15px; background: var(--success); border-radius: 3px;"></div>
                            <span style="font-weight: 600; color: var(--dark);">Your Status:</span>
                            <span class="status-badge <?php echo $status_badge['class']; ?>">
                                <i class="fas fa-<?php echo $status_badge['icon']; ?>"></i>
                                <?php echo $status_badge['label']; ?>
                            </span>
                        </div>
                        <div style="flex: 1; height: 1px; background: #e0e0e0;"></div>
                        <div style="font-size: 14px; color: #666;">
                            <i class="fas fa-info-circle"></i>
                            <?php 
                                $percentage = ($predicted_sales > 0) ? (($today_actual - $predicted_sales) / $predicted_sales) * 100 : 0;
                                if ($percentage >= 15) {
                                    echo "Excellent! You're exceeding predictions by " . number_format(abs($percentage), 1) . "%";
                                } elseif ($percentage >= 5) {
                                    echo "Good! You're exceeding predictions by " . number_format(abs($percentage), 1) . "%";
                                } elseif ($percentage >= -5) {
                                    echo "You're on track with predictions";
                                } elseif ($percentage >= -15) {
                                    echo "You're below predictions by " . number_format(abs($percentage), 1) . "%";
                                } else {
                                    echo "Needs improvement. Below predictions by " . number_format(abs($percentage), 1) . "%";
                                }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Branch Summary -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-building"></i> Branch Performance Summary</h2>
                <div class="refresh-indicator">
                    <i class="fas fa-chart-bar"></i>
                    Today's Overview
                </div>
            </div>
            
            <div class="prediction-comparison">
                <div class="comparison-grid">
                    <div class="comparison-item">
                        <div class="comparison-label">
                            <i class="fas fa-money-bill-wave"></i> Total Branch Sales
                        </div>
                        <div class="comparison-value" style="color: var(--primary);">Rs<?php echo number_format($total_branch_sales, 2); ?></div>
                        <div class="sub-value">All sales officers combined</div>
                    </div>
                    
                    <div class="comparison-item">
                        <div class="comparison-label">
                            <i class="fas fa-bullseye"></i> Total Predicted
                        </div>
                        <div class="comparison-value" style="color: var(--prediction-blue);">Rs<?php echo number_format($today_total_predicted, 2); ?></div>
                        <div class="sub-value">Combined predictions</div>
                    </div>
                    
                    <div class="comparison-item">
                        <div class="comparison-label">
                            <i class="fas fa-balance-scale"></i> Branch Difference
                        </div>
                        <div class="comparison-value difference-value 
                            <?php 
                                $branch_difference = $total_branch_sales - $today_total_predicted;
                                if ($branch_difference > 0) echo 'difference-positive';
                                elseif ($branch_difference < 0) echo 'difference-negative';
                                else echo 'difference-neutral';
                            ?>">
                            <?php 
                                if ($branch_difference > 0) echo '+';
                                echo number_format($branch_difference, 2);
                            ?>
                        </div>
                        <div class="sub-value">Actual vs Predicted</div>
                    </div>
                    
                    <div class="comparison-item">
                        <div class="comparison-label">
                            <i class="fas fa-chart-line"></i> Branch Accuracy
                        </div>
                        <div class="comparison-value">
                            <span class="accuracy-badge <?php echo $today_branch_accuracy_badge['class']; ?>">
                                <i class="fas fa-<?php echo $today_branch_accuracy_badge['icon']; ?>"></i>
                                <?php echo number_format($today_branch_accuracy, 1); ?>%
                            </span>
                        </div>
                        <div class="sub-value">Overall prediction accuracy</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sales Prediction Chart -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-chart-line"></i> 7-Day Sales & Prediction Timeline</h2>
                <div class="refresh-indicator">
                    <i class="fas fa-calendar-alt"></i>
                    <?php echo date('F j, Y'); ?>
                </div>
            </div>
            
            <div class="chart-card">
                <div class="chart-container">
                    <canvas id="predictionChart"></canvas>
                </div>
                <div style="text-align: center; margin-top: 15px; color: #666; font-size: 13px;">
                    <i class="fas fa-info-circle"></i>
                    Blue line shows past actual sales. Green dashed line shows future predictions.
                </div>
            </div>
        </div>

        <!-- Cashier Ranking Table -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-list-ol"></i> Sales Officer Performance Ranking</h2>
                <div class="refresh-indicator">
                    <i class="fas fa-sync"></i> Live Data Updates
                </div>
            </div>
            
            <div class="table-container">
                <table class="ranking-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Sales Officer Name</th>
                            <th>Today's Sales (Actual)</th>
                            <th>Today's Prediction</th>
                            <th>Accuracy %</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cashiers_ranking as $cashier): 
                            $is_current_user = ($cashier['cashier_id'] == $cashier_id);
                            $cashier_accuracy_badge = getAccuracyBadge($cashier['accuracy']);
                        ?>
                        <tr class="<?php echo $is_current_user ? 'current-user' : ''; ?>">
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
                                <strong><?php echo htmlspecialchars($cashier['name']); ?></strong>
                                <?php if ($is_current_user): ?>
                                    <br><small style="color: var(--primary); font-weight: 600;">(You)</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong style="color: var(--success);">Rs<?php echo number_format($cashier['today_actual'], 2); ?></strong>
                                <?php if ($cashier['transaction_count'] > 0): ?>
                                    <br><small style="color: #666;"><?php echo $cashier['transaction_count']; ?> trans</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong style="color: var(--prediction-blue);">Rs<?php echo number_format($cashier['today_predicted'], 2); ?></strong>
                                <?php if ($cashier['avg_daily_sales'] > 0): ?>
                                    <br><small style="color: #666;">Avg: Rs<?php echo number_format($cashier['avg_daily_sales'], 2); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="accuracy-badge <?php echo $cashier_accuracy_badge['class']; ?>">
                                    <i class="fas fa-<?php echo $cashier_accuracy_badge['icon']; ?>"></i>
                                    <?php echo number_format($cashier['accuracy'], 1); ?>%
                                </span>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $cashier['status']['class']; ?>">
                                    <i class="fas fa-<?php echo $cashier['status']['icon']; ?>"></i>
                                    <?php echo $cashier['status']['label']; ?>
                                </span>
                            </td>
                            <td>
                                <button class="action-btn" onclick="viewCashierPerformance(<?php echo htmlspecialchars(json_encode($cashier)); ?>)">
                                    <i class="fas fa-chart-bar"></i> Details
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 10px; border-left: 4px solid var(--secondary);">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div style="font-weight: 600; color: var(--dark);">
                        <i class="fas fa-chart-pie"></i> Branch Summary
                    </div>
                    <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                        <div style="text-align: center;">
                            <div style="font-size: 12px; color: #666;">Total Sales</div>
                            <div style="font-weight: 800; color: var(--primary);">Rs<?php echo number_format($total_branch_sales, 2); ?></div>
                        </div>
                        <div style="text-align: center;">
                            <div style="font-size: 12px; color: #666;">Total Commission</div>
                            <div style="font-weight: 800; color: var(--secondary);">Rs<?php echo number_format($total_estimated_commission, 2); ?></div>
                        </div>
                        <div style="text-align: center;">
                            <div style="font-size: 12px; color: #666;">Avg Accuracy</div>
                            <div style="font-weight: 800; color: var(--warning);"><?php echo number_format($avg_accuracy, 1); ?>%</div>
                        </div>
                        <div style="text-align: center;">
                            <div style="font-size: 12px; color: #666;">Total Items Sold</div>
                            <div style="font-weight: 800; color: var(--info);">
                                <?php 
                                    $total_items_sold = 0;
                                    foreach ($cashiers_ranking as $c) {
                                        $total_items_sold += $c['total_items'];
                                    }
                                    echo $total_items_sold;
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Cashier Details Modal -->
    <div class="modal" id="cashierModal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal()">&times;</span>
            <div class="modal-header">
                <h3><i class="fas fa-user-tie"></i> Sales Officer Performance Details</h3>
            </div>
            
            <div id="cashierModalContent">
                <!-- Dynamic content will be loaded here -->
            </div>
            
            <div style="text-align: center; margin-top: 20px;">
                <button class="btn btn-primary" onclick="printModalDetails()">
                    <i class="fas fa-print"></i> Print Details
                </button>
                <button class="btn btn-warning" onclick="closeModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <!-- Notification -->
    <div class="notification" id="notification">
        Dashboard auto-refreshed successfully!
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

        // Auto-refresh functionality
        let refreshTimer = 30;
        const refreshInterval = setInterval(() => {
            refreshTimer--;
            document.getElementById('refreshTimer').textContent = refreshTimer;
            
            if (refreshTimer <= 0) {
                refreshTimer = 30;
                refreshDashboard();
            }
        }, 1000);

        // Refresh dashboard data
        function refreshDashboard() {
            const notification = document.getElementById('notification');
            notification.textContent = 'Refreshing dashboard data...';
            notification.style.display = 'block';
            
            // Reload the page to get fresh data
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        }

        // Show notification
        function showNotification(message) {
            const notification = document.getElementById('notification');
            notification.textContent = message;
            notification.style.display = 'block';
            
            setTimeout(() => {
                notification.style.display = 'none';
            }, 3000);
        }

        // View cashier performance details
        function viewCashierPerformance(cashierData) {
            try {
                const modalContent = document.getElementById('cashierModalContent');
                
                // Calculate percentage difference
                const percentage = cashierData.today_predicted > 0 ? 
                    ((cashierData.today_actual - cashierData.today_predicted) / cashierData.today_predicted) * 100 : 0;
                
                // Get accuracy badge
                const accuracyBadge = getAccuracyBadgeObj(cashierData.accuracy);
                
                // Create details HTML
                let html = `
                    <div style="margin-bottom: 25px;">
                        <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 20px; flex-wrap: wrap;">
                            <div style="width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg, var(--primary), #8e44ad); display: flex; align-items: center; justify-content: center; color: white; font-size: 32px; font-weight: 800;">
                                ${cashierData.name.charAt(0).toUpperCase()}
                            </div>
                            <div>
                                <h4 style="color: var(--primary); margin-bottom: 5px; font-size: 22px;">${cashierData.name}</h4>
                                <div style="color: #666; font-size: 14px; margin-bottom: 5px;">
                                    <i class="fas fa-id-badge"></i> ID: ${cashierData.cashier_id}
                                </div>
                                <div style="color: #666; font-size: 14px;">
                                    <i class="fas fa-envelope"></i> ${cashierData.email || 'No email'}
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="details-grid">
                        <div class="detail-item">
                            <div class="detail-label">Rank</div>
                            <div class="detail-value" style="font-size: 24px;">#${cashierData.rank}</div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label">Today's Sales</div>
                            <div class="detail-value" style="color: var(--success); font-size: 24px;">Rs${parseFloat(cashierData.today_actual).toLocaleString('en-IN', {minimumFractionDigits: 2})}</div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label">Predicted Sales</div>
                            <div class="detail-value" style="color: var(--prediction-blue); font-size: 24px;">Rs${parseFloat(cashierData.today_predicted).toLocaleString('en-IN', {minimumFractionDigits: 2})}</div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label">Accuracy</div>
                            <div class="detail-value">
                                <span class="accuracy-badge ${accuracyBadge.class}" style="font-size: 14px;">
                                    <i class="fas fa-${accuracyBadge.icon}"></i>
                                    ${parseFloat(cashierData.accuracy).toFixed(1)}%
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div style="margin: 25px 0;">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                            <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; border-left: 4px solid var(--info);">
                                <div style="font-size: 12px; color: #666; margin-bottom: 5px;">Transactions Today</div>
                                <div style="font-size: 22px; font-weight: 800; color: var(--info);">
                                    ${cashierData.transaction_count || 0}
                                </div>
                            </div>
                            
                            <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; border-left: 4px solid #9b59b6;">
                                <div style="font-size: 12px; color: #666; margin-bottom: 5px;">Items Sold</div>
                                <div style="font-size: 22px; font-weight: 800; color: #9b59b6;">
                                    ${cashierData.total_items || 0}
                                </div>
                            </div>
                            
                            <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; border-left: 4px solid var(--warning);">
                                <div style="font-size: 12px; color: #666; margin-bottom: 5px;">Average Daily Sales</div>
                                <div style="font-size: 22px; font-weight: 800; color: var(--warning);">
                                    Rs${parseFloat(cashierData.avg_daily_sales || 0).toLocaleString('en-IN', {minimumFractionDigits: 2})}
                                </div>
                            </div>
                            
                            <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; border-left: 4px solid var(--secondary);">
                                <div style="font-size: 12px; color: #666; margin-bottom: 5px;">Estimated Commission</div>
                                <div style="font-size: 22px; font-weight: 800; color: var(--secondary);">
                                    Rs${parseFloat((cashierData.today_actual * 0.05)).toLocaleString('en-IN', {minimumFractionDigits: 2})}
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div style="background: white; padding: 25px; border-radius: 10px; border: 1px solid #e0e0e0; margin-top: 20px;">
                        <h4 style="color: var(--primary); margin-bottom: 15px; border-bottom: 2px solid #f0f0f0; padding-bottom: 10px;">
                            <i class="fas fa-chart-line"></i> Performance Analysis
                        </h4>
                        
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                            <div style="font-weight: 600; color: var(--dark);">Performance Status:</div>
                            <span class="status-badge ${cashierData.status.class}" style="font-size: 14px;">
                                <i class="fas fa-${cashierData.status.icon}"></i>
                                ${cashierData.status.label}
                            </span>
                        </div>
                        
                        <div style="font-size: 14px; color: var(--dark); line-height: 1.6;">
                            ${percentage > 0 ? 
                                `<div style="color: var(--success); margin-bottom: 8px;">
                                    <i class="fas fa-arrow-up"></i> Exceeding prediction by <strong>${Math.abs(percentage).toFixed(1)}%</strong>
                                </div>` : 
                                percentage < 0 ? 
                                `<div style="color: var(--danger); margin-bottom: 8px;">
                                    <i class="fas fa-arrow-down"></i> Below prediction by <strong>${Math.abs(percentage).toFixed(1)}%</strong>
                                </div>` :
                                `<div style="color: var(--prediction-blue); margin-bottom: 8px;">
                                    <i class="fas fa-equals"></i> Exactly on target
                                </div>`
                            }
                            
                            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-top: 10px;">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                    <span style="color: #666;">Difference:</span>
                                    <span style="font-weight: 700; color: ${cashierData.difference > 0 ? 'var(--success)' : cashierData.difference < 0 ? 'var(--danger)' : 'var(--prediction-blue)'}">
                                        ${cashierData.difference > 0 ? '+' : ''}Rs${Math.abs(cashierData.difference).toLocaleString('en-IN', {minimumFractionDigits: 2})}
                                    </span>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <span style="color: #666;">Accuracy Level:</span>
                                    <span style="font-weight: 700; color: ${accuracyBadge.color}">${accuracyBadge.label}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                modalContent.innerHTML = html;
                document.getElementById('cashierModal').style.display = 'block';
                
            } catch (error) {
                console.error('Error loading cashier details:', error);
                showNotification('Error loading performance details');
            }
        }

        // Helper function to get accuracy badge object
        function getAccuracyBadgeObj(accuracy) {
            if (accuracy >= 85) {
                return {color: '#2ecc71', label: 'High', class: 'accuracy-high', icon: 'check-circle'};
            } else if (accuracy >= 70) {
                return {color: '#f39c12', label: 'Medium', class: 'accuracy-medium', icon: 'exclamation-circle'};
            } else {
                return {color: '#e74c3c', label: 'Low', class: 'accuracy-low', icon: 'times-circle'};
            }
        }

        // Close modal
        function closeModal() {
            document.getElementById('cashierModal').style.display = 'none';
        }

        // Print modal details
        function printModalDetails() {
            const modalContent = document.getElementById('cashierModalContent').innerHTML;
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Sales Officer Performance Details</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; }
                        h2 { color: #5a3d7e; border-bottom: 2px solid #5a3d7e; padding-bottom: 10px; }
                        .detail-row { margin-bottom: 10px; display: flex; justify-content: space-between; }
                        .label { font-weight: bold; color: #666; }
                        .value { font-weight: 700; }
                        .highlight { background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 15px 0; }
                        @media print {
                            .no-print { display: none; }
                        }
                    </style>
                </head>
                <body>
                    <h2>Sales Officer Performance Details</h2>
                    ${modalContent}
                    <div class="no-print" style="text-align: center; margin-top: 30px; color: #666; font-size: 12px;">
                        Printed from KIDS Berry Sales Prediction Dashboard on ${new Date().toLocaleDateString()}
                    </div>
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('cashierModal');
            if (event.target == modal) {
                closeModal();
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
                    backgroundColor: 'rgba(90, 61, 126, 0.1)',
                    borderColor: '#5a3d7e',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#5a3d7e',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7
                }, {
                    label: 'Predicted Sales',
                    data: <?php echo json_encode($predicted_values); ?>,
                    backgroundColor: 'rgba(46, 204, 113, 0.1)',
                    borderColor: '#2ecc71',
                    borderWidth: 3,
                    borderDash: [5, 5],
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#2ecc71',
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
                        backgroundColor: 'rgba(90, 61, 126, 0.8)',
                        titleColor: '#ffffff',
                        bodyColor: '#ffffff',
                        borderColor: '#5a3d7e',
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

            const animatedElements = document.querySelectorAll('.stat-card, .comparison-item, .prediction-comparison-item, .chart-card, .ml-prediction-card');
            animatedElements.forEach(el => {
                el.style.opacity = 0;
                el.style.transform = 'translateY(15px)';
                el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                observer.observe(el);
            });

            // Animate stats with counting effect
            const statValues = document.querySelectorAll('.stat-card .value, .prediction-value');
            statValues.forEach(stat => {
                const originalText = stat.textContent;
                if (originalText.includes('Rs')) {
                    const number = parseFloat(originalText.replace('Rs', '').replace(/,/g, ''));
                    if (!isNaN(number) && number > 0) {
                        animateValue(stat, 0, number, 1500, true);
                    }
                } else if (originalText.includes('%')) {
                    const number = parseFloat(originalText.replace('%', ''));
                    if (!isNaN(number) && number > 0) {
                        animateValue(stat, 0, number, 1500, false);
                        stat.textContent = number.toFixed(1) + '%';
                    }
                } else if (!isNaN(parseInt(originalText))) {
                    const number = parseInt(originalText);
                    if (!isNaN(number) && number > 0) {
                        animateValue(stat, 0, number, 1500, false);
                    }
                }
            });

            // Animate confidence meter
            const confidenceFill = document.querySelector('.confidence-fill');
            if (confidenceFill) {
                setTimeout(() => {
                    confidenceFill.style.width = '<?php echo $branch_confidence_level; ?>%';
                }, 500);
            }
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
<?php
$conn->close();
?>