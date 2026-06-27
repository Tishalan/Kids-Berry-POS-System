
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
              FROM bills2 b
              JOIN bill_items2 bi ON b.bill_no = bi.bill_no
              JOIN cashier_users2 c ON b.cashier_id = c.cashier_id
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
              FROM bills2 b
              JOIN bill_items2 bi ON b.bill_no = bi.bill_no
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
              FROM bills2 b
              JOIN bill_items2 bi ON b.bill_no = bi.bill_no
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
              FROM bills2 b
              JOIN bill_items2 bi ON b.bill_no = bi.bill_no
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
        FROM bills2 b
        JOIN bill_items2 bi ON b.bill_no = bi.bill_no
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
                      FROM bills2 b
                      JOIN bill_items2 bi ON b.bill_no = bi.bill_no
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
        return ['color' => '#10B981', 'label' => 'High', 'class' => 'accuracy-high', 'icon' => 'check-circle'];
    } elseif ($accuracy >= 70) {
        return ['color' => '#F59E0B', 'label' => 'Medium', 'class' => 'accuracy-medium', 'icon' => 'exclamation-circle'];
    } else {
        return ['color' => '#EF4444', 'label' => 'Low', 'class' => 'accuracy-low', 'icon' => 'times-circle'];
    }
}

function getStatusBadge($actual, $predicted) {
    if ($predicted == 0) {
        return ['color' => '#3B82F6', 'label' => 'No Prediction', 'class' => 'status-ontrack', 'icon' => 'equals'];
    }
    
    $percentage = ($actual - $predicted) / $predicted * 100;
    
    if ($percentage >= 15) {
        return ['color' => '#10B981', 'label' => 'Excellent', 'class' => 'status-exceeded', 'icon' => 'arrow-up'];
    } elseif ($percentage >= 5) {
        return ['color' => '#10B981', 'label' => 'Exceeded', 'class' => 'status-exceeded', 'icon' => 'arrow-up'];
    } elseif ($percentage >= -5) {
        return ['color' => '#3B82F6', 'label' => 'On Track', 'class' => 'status-ontrack', 'icon' => 'equals'];
    } elseif ($percentage >= -15) {
        return ['color' => '#F59E0B', 'label' => 'Below', 'class' => 'status-below', 'icon' => 'arrow-down'];
    } else {
        return ['color' => '#EF4444', 'label' => 'Poor', 'class' => 'status-below', 'icon' => 'arrow-down'];
    }
}

// ============================
// GET TODAY'S DATA
// ============================

$today = date('Y-m-d');
$day_of_week = date('N'); // 1=Monday, 7=Sunday
$current_time = date('H:i:s');

// Get cashier details
$cashier_query = "SELECT * FROM cashier_users2 WHERE cashier_id = '$cashier_id'";
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
                      FROM bills2 b
                      JOIN bill_items2 bi ON b.bill_no = bi.bill_no
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
                      FROM bills2 b
                      JOIN bill_items2 bi ON b.bill_no = bi.bill_no
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
$all_cashiers_query = "SELECT cashier_id, name, email FROM cashier_users2 WHERE status = 'active' ORDER BY name";
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
                  FROM bills2 b
                  JOIN bill_items2 bi ON b.bill_no = bi.bill_no
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
                  FROM bills2 b
                  JOIN bill_items2 bi ON b.bill_no = bi.bill_no
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
                  FROM bills2 b
                  JOIN bill_items2 bi ON b.bill_no = bi.bill_no
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
            --primary: #2A4B8C;
            --primary-dark: #1A336B;
            --primary-light: #4A7BDE;
            --secondary: #00C9A7;
            --secondary-dark: #00A88B;
            --secondary-light: #2CE5CC;
            --accent: #FF6B35;
            --accent-dark: #E55A2B;
            --accent-light: #FF8E5C;
            --success: #10B981;
            --warning: #F59E0B;
            --danger: #EF4444;
            --info: #3B82F6;
            --light: #F8F9FC;
            --dark: #1A1D29;
            --gray: #6C757D;
            --card-shadow: 0 12px 24px rgba(42, 75, 140, 0.08), 0 4px 8px rgba(42, 75, 140, 0.04);
            --card-shadow-hover: 0 20px 40px rgba(42, 75, 140, 0.12), 0 8px 16px rgba(42, 75, 140, 0.08);
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            --radius: 16px;
            --radius-sm: 8px;
            --radius-lg: 24px;
            --gradient-primary: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            --gradient-secondary: linear-gradient(135deg, var(--secondary) 0%, var(--secondary-light) 100%);
            --gradient-accent: linear-gradient(135deg, var(--accent) 0%, var(--accent-light) 100%);
            --gradient-dark: linear-gradient(135deg, var(--dark) 0%, #2D3748 100%);
            --gradient-success: linear-gradient(135deg, var(--success) 0%, #34D399 100%);
            --gradient-warning: linear-gradient(135deg, var(--warning) 0%, #FBBF24 100%);
            --gradient-danger: linear-gradient(135deg, var(--danger) 0%, #F87171 100%);
            --gradient-info: linear-gradient(135deg, var(--info) 0%, #60A5FA 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #F5F7FF 0%, #E8ECFF 50%, #F0F4FF 100%);
            color: var(--dark);
            min-height: 100vh;
            line-height: 1.6;
            overflow-x: hidden;
        }

        .header {
            background: var(--gradient-dark);
            color: white;
            padding: 24px 40px;
            border-radius: 0 0 var(--radius-lg) var(--radius-lg);
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
            margin-bottom: 30px;
        }

        .header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at 20% 80%, rgba(74, 123, 222, 0.1) 0%, transparent 50%),
                        radial-gradient(circle at 80% 20%, rgba(0, 201, 167, 0.1) 0%, transparent 50%);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            z-index: 2;
        }

        .logo-container {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .logo-container img {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            border: 4px solid white;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
            transition: var(--transition);
        }

        .logo-container img:hover {
            transform: rotate(10deg) scale(1.05);
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.3);
        }

        .brand-info {
            display: flex;
            flex-direction: column;
        }

        .header h1 {
            font-size: 32px;
            font-weight: 800;
            background: linear-gradient(90deg, #FFF 0%, #D1FAE5 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 4px;
            letter-spacing: -0.5px;
        }

        .header-tagline {
            font-size: 14px;
            opacity: 0.9;
            color: #E2E8F0;
            font-weight: 500;
        }

        .date-time {
            text-align: right;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            padding: 12px 24px;
            border-radius: var(--radius);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .current-date {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .current-time {
            font-size: 14px;
            opacity: 0.9;
        }

        .logout-btn {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.25);
            padding: 12px 28px;
            border-radius: 50px;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
            font-size: 15px;
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            gap: 10px;
            margin-left: 20px;
        }

        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
            border-color: rgba(255, 255, 255, 0.4);
        }

        .nav-container {
            background: white;
            border-radius: var(--radius);
            padding: 20px;
            margin: 0 40px 30px;
            box-shadow: var(--card-shadow);
            border: 1px solid rgba(42, 75, 140, 0.1);
        }

        .nav {
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .nav a {
            text-decoration: none;
            color: var(--dark);
            font-weight: 600;
            padding: 14px 28px;
            border-radius: 50px;
            background: var(--light);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 12px;
            border: 2px solid transparent;
            font-size: 15px;
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
            background: linear-gradient(90deg, transparent, rgba(42, 75, 140, 0.1), transparent);
            transition: 0.6s;
        }

        .nav a:hover::before {
            left: 100%;
        }

        .nav a:hover {
            background: white;
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(42, 75, 140, 0.1);
        }

        .nav a.active {
            background: var(--gradient-secondary);
            color: white;
            border-color: transparent;
            box-shadow: 0 8px 20px rgba(0, 201, 167, 0.2);
        }

        .nav a.active i {
            color: white;
        }

        .nav a i {
            color: var(--primary);
            font-size: 16px;
        }

        .main-container {
            padding: 0 40px;
            max-width: 1800px;
            margin: 0 auto 40px;
            display: flex;
            flex-direction: column;
            gap: 30px;
        }

        /* AI Prediction Hero Card */
        .ai-prediction-hero {
            background: var(--gradient-primary);
            border-radius: var(--radius-lg);
            padding: 40px;
            color: white;
            position: relative;
            overflow: hidden;
            box-shadow: var(--card-shadow);
            margin-bottom: 30px;
        }

        .ai-prediction-hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at 80% 20%, rgba(255, 255, 255, 0.1) 0%, transparent 50%);
        }

        .ai-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            position: relative;
            z-index: 2;
        }

        .ai-title {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .ai-title h2 {
            font-size: 32px;
            font-weight: 800;
            margin: 0;
        }

        .ai-tag {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            padding: 8px 20px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .confidence-meter {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .meter-container {
            flex: 1;
            background: rgba(255, 255, 255, 0.1);
            height: 12px;
            border-radius: 6px;
            overflow: hidden;
        }

        .meter-fill {
            height: 100%;
            border-radius: 6px;
            transition: width 1.5s ease;
            background: var(--gradient-success);
        }

        .meter-value {
            font-weight: 800;
            font-size: 24px;
            min-width: 60px;
            text-align: right;
        }

        .prediction-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            position: relative;
            z-index: 2;
        }

        .prediction-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: var(--radius);
            padding: 25px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: var(--transition);
        }

        .prediction-card:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.2);
        }

        .prediction-label {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.8);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .prediction-value {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .prediction-sub {
            font-size: 13px;
            color: rgba(255, 255, 255, 0.7);
            font-weight: 500;
        }

        /* Stats Overview */
        .stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: var(--radius);
            padding: 30px;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--card-shadow-hover);
            border-color: var(--primary-light);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 6px;
            height: 100%;
            background: var(--gradient-primary);
        }

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            background: var(--gradient-primary);
        }

        .stat-icon.green {
            background: var(--gradient-success);
        }

        .stat-icon.blue {
            background: var(--gradient-info);
        }

        .stat-icon.orange {
            background: var(--gradient-warning);
        }

        .stat-icon.red {
            background: var(--gradient-danger);
        }

        .stat-icon.purple {
            background: var(--gradient-accent);
        }

        .stat-badge {
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-badge.high {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .stat-badge.medium {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .stat-badge.low {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .stat-value {
            font-size: 36px;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 10px;
            line-height: 1;
        }

        .stat-label {
            font-size: 16px;
            color: var(--gray);
            font-weight: 600;
            margin-bottom: 15px;
        }

        .stat-progress {
            height: 8px;
            background: #E5E7EB;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 20px;
        }

        .stat-progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 1s ease;
        }

        /* Comparison Cards */
        .comparison-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        @media (max-width: 1200px) {
            .comparison-grid {
                grid-template-columns: 1fr;
            }
        }

        .comparison-card {
            background: white;
            border-radius: var(--radius);
            padding: 30px;
            box-shadow: var(--card-shadow);
            border: 2px solid transparent;
            transition: var(--transition);
        }

        .comparison-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        .comparison-card.personal {
            border-color: var(--primary-light);
        }

        .comparison-card.branch {
            border-color: var(--secondary-light);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--light);
        }

        .card-title {
            font-size: 22px;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .card-title i {
            color: var(--primary);
        }

        .rank-badge {
            background: var(--gradient-primary);
            color: white;
            padding: 8px 20px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .comparison-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 25px;
        }

        .stat-box {
            background: #F8FAFC;
            padding: 25px;
            border-radius: var(--radius-sm);
            border-left: 4px solid var(--primary);
            transition: var(--transition);
        }

        .stat-box:hover {
            background: white;
            box-shadow: 0 8px 20px rgba(42, 75, 140, 0.08);
        }

        .stat-box.actual {
            border-left-color: var(--success);
        }

        .stat-box.predicted {
            border-left-color: var(--info);
        }

        .stat-box.difference {
            border-left-color: var(--warning);
        }

        .stat-box.accuracy {
            border-left-color: var(--accent);
        }

        .stat-label-small {
            font-size: 13px;
            color: var(--gray);
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 1px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .stat-value-large {
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .stat-value-large.positive {
            color: var(--success);
        }

        .stat-value-large.negative {
            color: var(--danger);
        }

        .stat-value-large.neutral {
            color: var(--info);
        }

        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 13px;
        }

        .status-indicator.exceeded {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .status-indicator.ontrack {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .status-indicator.below {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        /* Chart Container */
        .chart-container {
            background: white;
            border-radius: var(--radius);
            padding: 30px;
            box-shadow: var(--card-shadow);
            margin-bottom: 30px;
            border: 2px solid transparent;
            transition: var(--transition);
        }

        .chart-container:hover {
            box-shadow: var(--card-shadow-hover);
            border-color: var(--primary-light);
        }

        .chart-wrapper {
            height: 400px;
            width: 100%;
            margin-top: 20px;
            position: relative;
        }

        /* Ranking Table */
        .ranking-container {
            background: white;
            border-radius: var(--radius);
            padding: 30px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
        }

        .ranking-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--light);
        }

        .table-wrapper {
            overflow-x: auto;
            border-radius: var(--radius-sm);
            border: 1px solid #E5E7EB;
        }

        .ranking-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        .ranking-table thead {
            background: var(--gradient-primary);
        }

        .ranking-table th {
            padding: 18px 20px;
            text-align: left;
            color: white;
            font-weight: 700;
            font-size: 15px;
            white-space: nowrap;
        }

        .ranking-table th:first-child {
            border-top-left-radius: var(--radius-sm);
        }

        .ranking-table th:last-child {
            border-top-right-radius: var(--radius-sm);
        }

        .ranking-table tbody tr {
            border-bottom: 1px solid #E5E7EB;
            transition: var(--transition);
        }

        .ranking-table tbody tr:hover {
            background: #F8FAFC;
        }

        .ranking-table tbody tr.current-user {
            background: rgba(42, 75, 140, 0.05);
            font-weight: 600;
        }

        .ranking-table td {
            padding: 16px 20px;
            font-size: 15px;
            font-weight: 500;
        }

        .rank-cell {
            width: 70px;
        }

        .rank-badge-small {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 14px;
            color: white;
        }

        .rank-badge-small.gold {
            background: linear-gradient(135deg, #FBBF24 0%, #D97706 100%);
        }

        .rank-badge-small.silver {
            background: linear-gradient(135deg, #E5E7EB 0%, #9CA3AF 100%);
        }

        .rank-badge-small.bronze {
            background: linear-gradient(135deg, #F59E0B 0%, #B45309 100%);
        }

        .rank-badge-small.other {
            background: var(--light);
            color: var(--dark);
        }

        .performance-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .performance-tag.excellent {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .performance-tag.good {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .performance-tag.average {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .performance-tag.poor {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .action-btn {
            padding: 8px 16px;
            background: var(--gradient-primary);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(42, 75, 140, 0.2);
        }

        /* Branch Summary */
        .branch-summary {
            background: linear-gradient(135deg, #F8FAFF 0%, #F0F4FF 100%);
            padding: 30px;
            border-radius: var(--radius);
            border: 2px solid rgba(42, 75, 140, 0.1);
            margin-bottom: 30px;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 25px;
            margin-top: 20px;
        }

        .summary-item {
            background: white;
            padding: 25px;
            border-radius: var(--radius-sm);
            box-shadow: 0 4px 12px rgba(42, 75, 140, 0.08);
            text-align: center;
            transition: var(--transition);
        }

        .summary-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(42, 75, 140, 0.12);
        }

        .summary-value {
            font-size: 32px;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 8px;
        }

        .summary-label {
            font-size: 14px;
            color: var(--gray);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
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
            background-color: rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 40px;
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
            animation: slideDown 0.3s ease;
            position: relative;
        }

        .close-modal {
            position: absolute;
            right: 25px;
            top: 25px;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            color: var(--primary);
            transition: var(--transition);
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: var(--light);
        }

        .close-modal:hover {
            color: var(--accent);
            transform: rotate(90deg);
            background: #F1F5F9;
        }

        /* Notification */
        .notification {
            position: fixed;
            bottom: 40px;
            right: 40px;
            background: var(--gradient-primary);
            color: white;
            padding: 20px 32px;
            border-radius: var(--radius);
            box-shadow: 0 16px 40px rgba(42, 75, 140, 0.3);
            display: none;
            z-index: 1000;
            animation: slideInRight 0.5s ease, fadeOut 0.5s ease 2.5s forwards;
            font-weight: 600;
            font-size: 16px;
            max-width: 400px;
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideDown {
            from { transform: translateY(-50px); opacity: 0; }
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

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .pulse {
            animation: pulse 2s infinite;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .header {
                padding: 20px;
            }
            
            .header-content {
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }
            
            .nav-container {
                margin: 0 20px 20px;
            }
            
            .main-container {
                padding: 0 20px;
            }
            
            .ai-prediction-hero {
                padding: 30px;
            }
            
            .prediction-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .stats-overview {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .header h1 {
                font-size: 24px;
            }
            
            .nav {
                flex-direction: column;
                align-items: stretch;
            }
            
            .nav a {
                justify-content: center;
            }
            
            .prediction-grid,
            .stats-overview {
                grid-template-columns: 1fr;
            }
            
            .comparison-stats {
                grid-template-columns: 1fr;
            }
            
            .ai-title h2 {
                font-size: 24px;
            }
            
            .prediction-value {
                font-size: 24px;
            }
            
            .stat-value {
                font-size: 28px;
            }
        }

        @media (max-width: 480px) {
            .header {
                border-radius: 0 0 var(--radius) var(--radius);
            }
            
            .logo-container img {
                width: 60px;
                height: 60px;
            }
            
            .ai-prediction-hero {
                padding: 20px;
            }
            
            .comparison-grid {
                gap: 20px;
            }
            
            .comparison-card {
                padding: 20px;
            }
            
            .modal-content {
                padding: 25px;
                width: 95%;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <div class="logo-container">
                <img src="../logo.jpg" alt="Kids Berry Logo">
                <div class="brand-info">
                    <h1><i class="fas fa-chart-network"></i> Sales Intelligence Dashboard</h1>
                    <div class="header-tagline">AI-Powered Sales Predictions & Analytics</div>
                </div>
            </div>
            
            <div style="display: flex; align-items: center; gap: 20px;">
                <div class="date-time">
                    <div class="current-date"><?php echo date('l, F j, Y'); ?></div>
                    <div class="current-time"><?php echo date('g:i:s A'); ?></div>
                </div>
                <form method="post">
                    <button type="submit" name="logout" class="logout-btn">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="nav-container">
        <div class="nav">
            <a href="billing1.php"><i class="fas fa-cash-register"></i> Billing</a>
            <a href="customer1.php"><i class="fas fa-users"></i> Customers</a>
            <a href="bill_management1.php"><i class="fas fa-history"></i> Bill History</a>
            <a href="report1.php"><i class="fas fa-chart-line"></i> Reports</a>
            <!--<a href="credit_payments.php"><i class="fas fa-credit-card"></i> Credit Customers</a>-->
            <a href="return_sale1.php"><i class="fas fa-undo-alt"></i> Manage Returns</a>
            <a href="prediction_dashboard1.php" class="active"><i class="fas fa-bullseye"></i> Predictions</a>
        </div>
    </div>

    <div class="main-container">
        <!-- AI Prediction Hero Section -->
        <div class="ai-prediction-hero">
            <div class="ai-header">
                <div class="ai-title">
                    <h2><i class="fas fa-robot"></i> AI Sales Forecast</h2>
                    <div class="ai-tag">
                        <i class="fas fa-bolt"></i> LIVE PREDICTIONS
                    </div>
                </div>
                <div class="confidence-meter">
                    <div class="meter-container">
                        <div class="meter-fill" style="width: <?php echo $branch_confidence_level; ?>%;"></div>
                    </div>
                    <div class="meter-value"><?php echo $branch_confidence_level; ?>%</div>
                </div>
            </div>
            
            <div class="prediction-grid">
                <div class="prediction-card">
                    <div class="prediction-label">
                        <i class="fas fa-store"></i> Total Store Sales
                    </div>
                    <div class="prediction-value">Rs<?php echo number_format($branch_today_actual, 2); ?></div>
                    <div class="prediction-sub">Today's Actual Revenue</div>
                </div>
                
                <div class="prediction-card">
                    <div class="prediction-label">
                        <i class="fas fa-brain"></i> AI Prediction
                    </div>
                    <div class="prediction-value">Rs<?php echo number_format($branch_today_prediction, 2); ?></div>
                    <div class="prediction-sub">Forecasted Target</div>
                </div>
                
                <div class="prediction-card">
                    <div class="prediction-label">
                        <i class="fas fa-chart-line"></i> Variance
                    </div>
                    <div class="prediction-value" style="color: <?php echo $branch_difference > 0 ? 'var(--success)' : ($branch_difference < 0 ? 'var(--danger)' : 'var(--info)'); ?>">
                        <?php echo $branch_difference > 0 ? '+' : ''; ?>Rs<?php echo number_format($branch_difference, 2); ?>
                    </div>
                    <div class="prediction-sub">Actual vs Prediction</div>
                </div>
                
                <div class="prediction-card">
                    <div class="prediction-label">
                        <i class="fas fa-bullseye"></i> AI Accuracy
                    </div>
                    <div class="prediction-value"><?php echo number_format($branch_accuracy, 1); ?>%</div>
                    <div class="prediction-sub">Model Performance</div>
                </div>
            </div>
        </div>

        <!-- Stats Overview -->
        <div class="stats-overview">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <span class="stat-badge high">Active</span>
                </div>
                <div class="stat-value"><?php echo $active_cashiers; ?></div>
                <div class="stat-label">Active Sales Officers</div>
                <div class="stat-progress">
                    <div class="stat-progress-fill" style="width: 100%; background: var(--gradient-primary);"></div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon green">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <span class="stat-badge <?php echo $accuracy >= 85 ? 'high' : ($accuracy >= 70 ? 'medium' : 'low'); ?>">
                        <?php echo $accuracy_badge['label']; ?>
                    </span>
                </div>
                <div class="stat-value">Rs<?php echo number_format($today_actual, 2); ?></div>
                <div class="stat-label">Your Today's Sales</div>
                <div class="stat-progress">
                    <div class="stat-progress-fill" style="width: <?php echo min(100, ($today_actual / max(1, $predicted_sales)) * 100); ?>%; background: var(--gradient-success);"></div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon blue">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <span class="stat-badge <?php echo $avg_accuracy >= 85 ? 'high' : ($avg_accuracy >= 70 ? 'medium' : 'low'); ?>">
                        <?php echo $avg_accuracy >= 85 ? 'High' : ($avg_accuracy >= 70 ? 'Medium' : 'Low'); ?>
                    </span>
                </div>
                <div class="stat-value">Rs<?php echo number_format($avg_per_cashier, 2); ?></div>
                <div class="stat-label">Average per Officer</div>
                <div class="stat-progress">
                    <div class="stat-progress-fill" style="width: <?php echo min(100, ($today_actual / max(1, $avg_per_cashier)) * 100); ?>%; background: var(--gradient-info);"></div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon orange">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <span class="stat-badge high">Top Performer</span>
                </div>
                <div class="stat-value"><?php echo htmlspecialchars($best_performer); ?></div>
                <div class="stat-label">Leading Sales Officer</div>
                <div class="stat-progress">
                    <div class="stat-progress-fill" style="width: 100%; background: var(--gradient-warning);"></div>
                </div>
            </div>
        </div>

        <!-- Performance Comparison -->
        <div class="comparison-grid">
            <!-- Personal Performance -->
            <div class="comparison-card personal">
                <div class="card-header">
                    <h2 class="card-title"><i class="fas fa-user-circle"></i> Your Performance</h2>
                    <div class="rank-badge">
                        <i class="fas fa-crown"></i> Rank: #<?php echo $my_rank ?? 'N/A'; ?>
                    </div>
                </div>
                
                <div class="comparison-stats">
                    <div class="stat-box actual">
                        <div class="stat-label-small">
                            <i class="fas fa-chart-line"></i> Actual Sales
                        </div>
                        <div class="stat-value-large">Rs<?php echo number_format($today_actual, 2); ?></div>
                        <div class="stat-sub"><?php echo $transaction_count; ?> transactions</div>
                    </div>
                    
                    <div class="stat-box predicted">
                        <div class="stat-label-small">
                            <i class="fas fa-bullseye"></i> Predicted Sales
                        </div>
                        <div class="stat-value-large">Rs<?php echo number_format($predicted_sales, 2); ?></div>
                        <div class="stat-sub">Personalized forecast</div>
                    </div>
                    
                    <div class="stat-box difference">
                        <div class="stat-label-small">
                            <i class="fas fa-balance-scale"></i> Difference
                        </div>
                        <div class="stat-value-large <?php echo ($today_actual - $predicted_sales) > 0 ? 'positive' : (($today_actual - $predicted_sales) < 0 ? 'negative' : 'neutral'); ?>">
                            <?php echo ($today_actual - $predicted_sales) > 0 ? '+' : ''; ?>Rs<?php echo number_format($today_actual - $predicted_sales, 2); ?>
                        </div>
                        <div class="stat-sub">Actual vs Prediction</div>
                    </div>
                    
                    <div class="stat-box accuracy">
                        <div class="stat-label-small">
                            <i class="fas fa-check-circle"></i> Accuracy
                        </div>
                        <div class="stat-value-large"><?php echo number_format($accuracy, 1); ?>%</div>
                        <div class="stat-sub">Prediction accuracy</div>
                    </div>
                </div>
                
                <div style="margin-top: 25px; padding: 20px; background: #F8FAFC; border-radius: var(--radius-sm);">
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div class="status-indicator <?php echo $status_badge['class']; ?>">
                                <i class="fas fa-<?php echo $status_badge['icon']; ?>"></i>
                                <?php echo $status_badge['label']; ?>
                            </div>
                            <span style="color: var(--gray); font-size: 14px;">
                                <?php 
                                    $percentage = ($predicted_sales > 0) ? (($today_actual - $predicted_sales) / $predicted_sales) * 100 : 0;
                                    if ($percentage >= 15) {
                                        echo "Excellent! You're exceeding predictions by " . number_format(abs($percentage), 1) . "%";
                                    } elseif ($percentage >= 5) {
                                        echo "Great! You're exceeding predictions by " . number_format(abs($percentage), 1) . "%";
                                    } elseif ($percentage >= -5) {
                                        echo "You're perfectly on track with predictions";
                                    } elseif ($percentage >= -15) {
                                        echo "You're below predictions by " . number_format(abs($percentage), 1) . "%";
                                    } else {
                                        echo "Needs improvement. Below by " . number_format(abs($percentage), 1) . "%";
                                    }
                                ?>
                            </span>
                        </div>
                        <div style="font-weight: 700; color: var(--secondary);">
                            <i class="fas fa-coins"></i> Commission: Rs<?php echo number_format($estimated_commission, 2); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Branch Performance -->
            <div class="comparison-card branch">
                <div class="card-header">
                    <h2 class="card-title"><i class="fas fa-building"></i> Branch Performance</h2>
                    <div class="rank-badge" style="background: var(--gradient-secondary);">
                        <i class="fas fa-chart-pie"></i> Overall View
                    </div>
                </div>
                
                <div class="comparison-stats">
                    <div class="stat-box actual">
                        <div class="stat-label-small">
                            <i class="fas fa-store"></i> Total Sales
                        </div>
                        <div class="stat-value-large">Rs<?php echo number_format($total_branch_sales, 2); ?></div>
                        <div class="stat-sub">All officers combined</div>
                    </div>
                    
                    <div class="stat-box predicted">
                        <div class="stat-label-small">
                            <i class="fas fa-brain"></i> Total Predicted
                        </div>
                        <div class="stat-value-large">Rs<?php echo number_format($today_total_predicted, 2); ?></div>
                        <div class="stat-sub">Combined forecasts</div>
                    </div>
                    
                    <div class="stat-box difference">
                        <div class="stat-label-small">
                            <i class="fas fa-balance-scale"></i> Branch Difference
                        </div>
                        <?php $branch_total_diff = $total_branch_sales - $today_total_predicted; ?>
                        <div class="stat-value-large <?php echo $branch_total_diff > 0 ? 'positive' : ($branch_total_diff < 0 ? 'negative' : 'neutral'); ?>">
                            <?php echo $branch_total_diff > 0 ? '+' : ''; ?>Rs<?php echo number_format($branch_total_diff, 2); ?>
                        </div>
                        <div class="stat-sub">Actual vs Prediction</div>
                    </div>
                    
                    <div class="stat-box accuracy">
                        <div class="stat-label-small">
                            <i class="fas fa-bullseye"></i> Branch Accuracy
                        </div>
                        <div class="stat-value-large"><?php echo number_format($today_branch_accuracy, 1); ?>%</div>
                        <div class="stat-sub">Overall prediction accuracy</div>
                    </div>
                </div>
                
                <div style="margin-top: 25px; padding: 20px; background: #F0F9FF; border-radius: var(--radius-sm); border: 1px solid rgba(0, 201, 167, 0.2);">
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div class="status-indicator <?php echo $branch_status_class; ?>">
                                <i class="fas fa-<?php echo $branch_status_icon; ?>"></i>
                                <?php echo $branch_status_label; ?>
                            </div>
                            <span style="color: var(--gray); font-size: 14px;">
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
                            </span>
                        </div>
                        <div style="font-weight: 700; color: var(--secondary);">
                            <i class="fas fa-money-bill-wave"></i> Total Commission: Rs<?php echo number_format($total_estimated_commission, 2); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sales Chart -->
        <div class="chart-container">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-chart-line"></i> 7-Day Sales Trend & Forecast</h2>
                <div style="color: var(--gray); font-size: 14px; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-calendar-alt"></i>
                    <?php echo date('F j, Y'); ?>
                </div>
            </div>
            
            <div class="chart-wrapper">
                <canvas id="predictionChart"></canvas>
            </div>
        </div>

        <!-- Sales Officer Ranking -->
        <div class="ranking-container">
            <div class="ranking-header">
                <h2 class="card-title"><i class="fas fa-list-ol"></i> Sales Officer Rankings</h2>
                <div style="color: var(--gray); font-size: 14px; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-sync-alt pulse"></i>
                    Live Ranking Updates
                </div>
            </div>
            
            <div class="table-wrapper">
                <table class="ranking-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Sales Officer</th>
                            <th>Today's Sales</th>
                            <th>Prediction</th>
                            <th>Accuracy</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cashiers_ranking as $cashier): 
                            $is_current_user = ($cashier['cashier_id'] == $cashier_id);
                            $performance_level = '';
                            if ($cashier['accuracy'] >= 85) $performance_level = 'excellent';
                            elseif ($cashier['accuracy'] >= 70) $performance_level = 'good';
                            elseif ($cashier['accuracy'] >= 50) $performance_level = 'average';
                            else $performance_level = 'poor';
                        ?>
                        <tr class="<?php echo $is_current_user ? 'current-user' : ''; ?>">
                            <td class="rank-cell">
                                <div class="rank-badge-small 
                                    <?php 
                                        if ($cashier['rank'] == 1) echo 'gold';
                                        elseif ($cashier['rank'] == 2) echo 'silver';
                                        elseif ($cashier['rank'] == 3) echo 'bronze';
                                        else echo 'other';
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
                                <br>
                                <small style="color: var(--gray);"><?php echo $cashier['transaction_count']; ?> trans</small>
                            </td>
                            <td>
                                <strong style="color: var(--info);">Rs<?php echo number_format($cashier['today_predicted'], 2); ?></strong>
                                <br>
                                <small style="color: var(--gray);">Avg: Rs<?php echo number_format($cashier['avg_daily_sales'], 2); ?></small>
                            </td>
                            <td>
                                <div class="performance-tag <?php echo $performance_level; ?>">
                                    <i class="fas fa-<?php echo getAccuracyBadge($cashier['accuracy'])['icon']; ?>"></i>
                                    <?php echo number_format($cashier['accuracy'], 1); ?>%
                                </div>
                            </td>
                            <td>
                                <div class="status-indicator <?php echo $cashier['status']['class']; ?>">
                                    <i class="fas fa-<?php echo $cashier['status']['icon']; ?>"></i>
                                    <?php echo $cashier['status']['label']; ?>
                                </div>
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
        </div>

        <!-- Branch Summary -->
        <div class="branch-summary">
            <div style="text-align: center; margin-bottom: 25px;">
                <h2 style="color: var(--dark); font-size: 24px; margin-bottom: 10px;">
                    <i class="fas fa-chart-pie"></i> Branch Performance Summary
                </h2>
                <p style="color: var(--gray);">Overview of today's branch performance metrics</p>
            </div>
            
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="summary-value"><?php echo $active_cashiers; ?></div>
                    <div class="summary-label">Active Officers</div>
                </div>
                
                <div class="summary-item">
                    <div class="summary-value"><?php 
                        $total_items_sold = 0;
                        foreach ($cashiers_ranking as $c) {
                            $total_items_sold += $c['total_items'];
                        }
                        echo $total_items_sold;
                    ?></div>
                    <div class="summary-label">Items Sold</div>
                </div>
                
                <div class="summary-item">
                    <div class="summary-value"><?php echo number_format($avg_accuracy, 1); ?>%</div>
                    <div class="summary-label">Avg Accuracy</div>
                </div>
                
                <div class="summary-item">
                    <div class="summary-value">Rs<?php echo number_format($total_estimated_commission, 2); ?></div>
                    <div class="summary-label">Total Commission</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Cashier Details Modal -->
    <div class="modal" id="cashierModal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal()">&times;</span>
            <div class="card-header" style="margin-bottom: 30px;">
                <h2 class="card-title"><i class="fas fa-user-tie"></i> Officer Performance Details</h2>
            </div>
            
            <div id="cashierModalContent">
                <!-- Dynamic content will be loaded here -->
            </div>
        </div>
    </div>

    <!-- Notification -->
    <div class="notification" id="notification">
        <i class="fas fa-check-circle"></i> Dashboard updated successfully!
    </div>

    <script>
        // Update time in real-time
        function updateClock() {
            const now = new Date();
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            document.querySelector('.current-date').textContent = now.toLocaleDateString('en-US', options);
            let hours = now.getHours();
            let minutes = now.getMinutes();
            let seconds = now.getSeconds();
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12 || 12;
            minutes = minutes < 10 ? '0' + minutes : minutes;
            seconds = seconds < 10 ? '0' + seconds : seconds;
            const timeString = `${hours}:${minutes}:${seconds} ${ampm}`;
            document.querySelector('.current-time').textContent = timeString;
            setTimeout(updateClock, 1000);
        }

        updateClock();

        // Auto-refresh functionality
        let refreshTimer = 30;
        const refreshInterval = setInterval(() => {
            refreshTimer--;
            
            if (refreshTimer <= 0) {
                refreshTimer = 30;
                refreshDashboard();
            }
        }, 1000);

        // Refresh dashboard data
        function refreshDashboard() {
            const notification = document.getElementById('notification');
            notification.innerHTML = '<i class="fas fa-sync-alt spin"></i> Refreshing dashboard...';
            notification.style.display = 'flex';
            
            // Reload the page to get fresh data
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        }

        // Show notification
        function showNotification(message) {
            const notification = document.getElementById('notification');
            notification.innerHTML = `<i class="fas fa-check-circle"></i> ${message}`;
            notification.style.display = 'flex';
            
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
                    <div style="margin-bottom: 30px;">
                        <div style="display: flex; align-items: center; gap: 25px; margin-bottom: 25px; flex-wrap: wrap;">
                            <div style="width: 100px; height: 100px; border-radius: 50%; background: var(--gradient-primary); display: flex; align-items: center; justify-content: center; color: white; font-size: 42px; font-weight: 800; box-shadow: 0 8px 25px rgba(42, 75, 140, 0.3);">
                                ${cashierData.name.charAt(0).toUpperCase()}
                            </div>
                            <div style="flex: 1;">
                                <h3 style="color: var(--dark); font-size: 28px; margin-bottom: 8px;">${cashierData.name}</h3>
                                <div style="display: flex; flex-wrap: wrap; gap: 20px;">
                                    <div style="color: var(--gray); font-size: 15px;">
                                        <i class="fas fa-id-badge"></i> ID: ${cashierData.cashier_id}
                                    </div>
                                    <div style="color: var(--gray); font-size: 15px;">
                                        <i class="fas fa-envelope"></i> ${cashierData.email || 'No email'}
                                    </div>
                                    <div style="color: var(--gray); font-size: 15px;">
                                        <i class="fas fa-crown"></i> Rank: #${cashierData.rank}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
                        <div style="background: linear-gradient(135deg, #F0F9FF 0%, #E0F2FE 100%); padding: 25px; border-radius: var(--radius); border: 2px solid rgba(59, 130, 246, 0.2);">
                            <div style="font-size: 14px; color: var(--gray); margin-bottom: 10px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-chart-line"></i> Today's Sales
                            </div>
                            <div style="font-size: 32px; font-weight: 800; color: var(--success);">Rs${parseFloat(cashierData.today_actual).toLocaleString('en-IN', {minimumFractionDigits: 2})}</div>
                            <div style="font-size: 14px; color: var(--gray); margin-top: 8px;">
                                ${cashierData.transaction_count || 0} transactions
                            </div>
                        </div>
                        
                        <div style="background: linear-gradient(135deg, #F0FDF4 0%, #DCFCE7 100%); padding: 25px; border-radius: var(--radius); border: 2px solid rgba(16, 185, 129, 0.2);">
                            <div style="font-size: 14px; color: var(--gray); margin-bottom: 10px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-bullseye"></i> Predicted Sales
                            </div>
                            <div style="font-size: 32px; font-weight: 800; color: var(--info);">Rs${parseFloat(cashierData.today_predicted).toLocaleString('en-IN', {minimumFractionDigits: 2})}</div>
                            <div style="font-size: 14px; color: var(--gray); margin-top: 8px;">
                                Personalized forecast
                            </div>
                        </div>
                        
                        <div style="background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%); padding: 25px; border-radius: var(--radius); border: 2px solid rgba(245, 158, 11, 0.2);">
                            <div style="font-size: 14px; color: var(--gray); margin-bottom: 10px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-balance-scale"></i> Performance
                            </div>
                            <div style="font-size: 32px; font-weight: 800; color: ${cashierData.difference > 0 ? 'var(--success)' : cashierData.difference < 0 ? 'var(--danger)' : 'var(--info)'}">
                                ${cashierData.difference > 0 ? '+' : ''}Rs${Math.abs(cashierData.difference).toLocaleString('en-IN', {minimumFractionDigits: 2})}
                            </div>
                            <div style="font-size: 14px; color: var(--gray); margin-top: 8px;">
                                ${percentage > 0 ? 'Above' : percentage < 0 ? 'Below' : 'On'} target
                            </div>
                        </div>
                    </div>
                    
                    <div style="background: white; padding: 30px; border-radius: var(--radius); box-shadow: 0 8px 25px rgba(0, 0, 0, 0.05); margin-top: 20px;">
                        <h4 style="color: var(--dark); margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid var(--light); display: flex; align-items: center; gap: 12px;">
                            <i class="fas fa-chart-bar"></i> Performance Analytics
                        </h4>
                        
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 25px;">
                            <div>
                                <div style="font-size: 13px; color: var(--gray); margin-bottom: 5px; font-weight: 600;">Accuracy Level</div>
                                <div class="performance-tag ${accuracyBadge.class}" style="font-size: 14px;">
                                    <i class="fas fa-${accuracyBadge.icon}"></i>
                                    ${accuracyBadge.label}
                                </div>
                            </div>
                            
                            <div>
                                <div style="font-size: 13px; color: var(--gray); margin-bottom: 5px; font-weight: 600;">Prediction Accuracy</div>
                                <div style="font-size: 20px; font-weight: 800; color: ${accuracyBadge.color}">${parseFloat(cashierData.accuracy).toFixed(1)}%</div>
                            </div>
                            
                            <div>
                                <div style="font-size: 13px; color: var(--gray); margin-bottom: 5px; font-weight: 600;">Items Sold</div>
                                <div style="font-size: 20px; font-weight: 800; color: var(--accent);">${cashierData.total_items || 0}</div>
                            </div>
                            
                            <div>
                                <div style="font-size: 13px; color: var(--gray); margin-bottom: 5px; font-weight: 600;">Avg Daily Sales</div>
                                <div style="font-size: 20px; font-weight: 800; color: var(--warning);">Rs${parseFloat(cashierData.avg_daily_sales || 0).toLocaleString('en-IN', {minimumFractionDigits: 2})}</div>
                            </div>
                        </div>
                        
                        <div style="background: #F8FAFC; padding: 20px; border-radius: var(--radius-sm); border-left: 4px solid ${accuracyBadge.color};">
                            <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                                <div class="status-indicator ${cashierData.status.class}" style="font-size: 14px;">
                                    <i class="fas fa-${cashierData.status.icon}"></i>
                                    ${cashierData.status.label}
                                </div>
                                <div style="font-weight: 600; color: var(--dark);">Performance Status</div>
                            </div>
                            <div style="color: var(--gray); line-height: 1.6; font-size: 15px;">
                                ${percentage > 0 ? 
                                    `<span style="color: var(--success); font-weight: 600;">Exceeding predictions by ${Math.abs(percentage).toFixed(1)}%</span> - ` : 
                                    percentage < 0 ? 
                                    `<span style="color: var(--danger); font-weight: 600;">Below predictions by ${Math.abs(percentage).toFixed(1)}%</span> - ` :
                                    `<span style="color: var(--info); font-weight: 600;">Exactly on target</span> - `
                                }
                                ${cashierData.difference > 0 ? 
                                    'Great performance! Keep up the excellent work.' : 
                                    cashierData.difference < 0 ? 
                                    'Consider strategies to improve sales performance.' : 
                                    'Perfect alignment with predictions.'}
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
                return {color: '#10B981', label: 'High', class: 'excellent', icon: 'check-circle'};
            } else if (accuracy >= 70) {
                return {color: '#F59E0B', label: 'Medium', class: 'good', icon: 'exclamation-circle'};
            } else {
                return {color: '#EF4444', label: 'Low', class: 'poor', icon: 'times-circle'};
            }
        }

        // Close modal
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

        // Print modal details
        function printModalDetails() {
            const modalContent = document.getElementById('cashierModalContent').innerHTML;
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Sales Officer Performance Report</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 30px; background: #fff; }
                        h1 { color: #2A4B8C; border-bottom: 3px solid #2A4B8C; padding-bottom: 15px; margin-bottom: 30px; }
                        .report-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin: 25px 0; }
                        .report-item { padding: 20px; border-radius: 10px; border: 1px solid #e0e0e0; }
                        .report-label { font-size: 12px; color: #666; text-transform: uppercase; font-weight: bold; margin-bottom: 5px; }
                        .report-value { font-size: 24px; font-weight: bold; color: #2A4B8C; }
                        @media print {
                            .no-print { display: none; }
                        }
                    </style>
                </head>
                <body>
                    <h1>Sales Officer Performance Report</h1>
                    ${modalContent}
                    <div class="no-print" style="text-align: center; margin-top: 40px; color: #666; font-size: 12px;">
                        Generated from KIDS Berry Sales Intelligence Dashboard on ${new Date().toLocaleDateString()}
                    </div>
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }

        // Sales Prediction Chart
        const predictionChart = new Chart(document.getElementById('predictionChart'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [{
                    label: 'Actual Sales',
                    data: <?php echo json_encode($actual_sales_values); ?>,
                    backgroundColor: 'rgba(42, 75, 140, 0.1)',
                    borderColor: '#2A4B8C',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#2A4B8C',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 6,
                    pointHoverRadius: 9
                }, {
                    label: 'Predicted Sales',
                    data: <?php echo json_encode($predicted_values); ?>,
                    backgroundColor: 'rgba(0, 201, 167, 0.1)',
                    borderColor: '#00C9A7',
                    borderWidth: 3,
                    borderDash: [6, 6],
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#00C9A7',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 6,
                    pointHoverRadius: 9
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
                                size: 13,
                                weight: '600'
                            },
                            padding: 20,
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(26, 29, 41, 0.9)',
                        titleColor: '#ffffff',
                        bodyColor: '#ffffff',
                        borderColor: '#2A4B8C',
                        borderWidth: 1,
                        cornerRadius: 10,
                        displayColors: false,
                        padding: 15,
                        callbacks: {
                            label: function(context) {
                                const datasetLabel = context.dataset.label;
                                const value = context.parsed.y;
                                return `${datasetLabel}: Rs${value.toLocaleString('en-IN', {minimumFractionDigits: 2})}`;
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
                                return 'Rs' + value.toLocaleString('en-IN');
                            },
                            font: {
                                size: 12,
                                weight: '600'
                            },
                            color: '#6C757D'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 12,
                                weight: '600'
                            },
                            color: '#6C757D'
                        }
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                animations: {
                    tension: {
                        duration: 1500,
                        easing: 'easeOutQuart'
                    }
                },
                elements: {
                    line: {
                        tension: 0.4
                    }
                }
            }
        });

        // Animate elements on scroll
        document.addEventListener('DOMContentLoaded', function() {
            // Animate confidence meter
            const meterFill = document.querySelector('.meter-fill');
            if (meterFill) {
                setTimeout(() => {
                    meterFill.style.width = '<?php echo $branch_confidence_level; ?>%';
                }, 500);
            }

            // Animate progress bars
            const progressBars = document.querySelectorAll('.stat-progress-fill');
            progressBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0%';
                setTimeout(() => {
                    bar.style.width = width;
                }, 300);
            });

            // Add scroll animations
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = 1;
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, { threshold: 0.1 });

            const animatedElements = document.querySelectorAll('.stat-card, .prediction-card, .comparison-card, .summary-item');
            animatedElements.forEach(el => {
                el.style.opacity = 0;
                el.style.transform = 'translateY(20px)';
                el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                observer.observe(el);
            });

            // Animate numbers with counting effect
            const statValues = document.querySelectorAll('.stat-value, .summary-value, .prediction-value');
            statValues.forEach(stat => {
                const originalText = stat.textContent;
                if (originalText.includes('Rs')) {
                    const number = parseFloat(originalText.replace('Rs', '').replace(/,/g, ''));
                    if (!isNaN(number) && number > 0) {
                        animateValue(stat, 0, number, 2000, true);
                    }
                } else if (originalText.includes('%')) {
                    const number = parseFloat(originalText.replace('%', ''));
                    if (!isNaN(number) && number > 0) {
                        animateValue(stat, 0, number, 2000, false, true);
                    }
                } else if (!isNaN(parseFloat(originalText.replace(/,/g, '')))) {
                    const number = parseFloat(originalText.replace(/,/g, ''));
                    if (!isNaN(number) && number > 0) {
                        animateValue(stat, 0, number, 2000, false, false);
                    }
                }
            });
        });

        // Function to animate counting numbers
        function animateValue(element, start, end, duration, isCurrency, isPercentage) {
            let startTimestamp = null;
            const step = (timestamp) => {
                if (!startTimestamp) startTimestamp = timestamp;
                const progress = Math.min((timestamp - startTimestamp) / duration, 1);
                const easedProgress = easeOutCubic(progress);
                let value = Math.floor(easedProgress * (end - start) + start);
                
                if (isCurrency) {
                    element.textContent = 'Rs' + value.toLocaleString('en-IN');
                } else if (isPercentage) {
                    element.textContent = value.toFixed(1) + '%';
                } else {
                    element.textContent = value.toLocaleString('en-IN');
                }
                
                if (progress < 1) {
                    window.requestAnimationFrame(step);
                }
            };
            window.requestAnimationFrame(step);
        }

        function easeOutCubic(x) {
            return 1 - Math.pow(1 - x, 3);
        }

        // Initialize tooltips
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>
[file content end]