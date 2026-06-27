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
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$branch_id = 1;
$_SESSION['branch_id'] = $branch_id;

function convertToColomboTime($utc_time) {
    if (empty($utc_time) || $utc_time == '0000-00-00 00:00:00') {
        return 'Never';
    }
    
    $utc_date = new DateTime($utc_time, new DateTimeZone('UTC'));
    $colombo_timezone = new DateTimeZone('Asia/Colombo');
    $utc_date->setTimezone($colombo_timezone);
    return $utc_date->format('Y-m-d H:i');
}

// Function to automatically update customer membership based on purchase count
function updateCustomerMembership($conn, $customer_id) {
    $purchase_sql = "SELECT COUNT(*) as total_purchases FROM bills2 WHERE phone_no = 
                    (SELECT phone_no FROM customers2 WHERE customer_id = $customer_id)";
    $purchase_result = $conn->query($purchase_sql);
    $purchase_data = $purchase_result->fetch_assoc();
    $total_purchases = $purchase_data['total_purchases'];
    
    $membership_type = 'Regular';
    if ($total_purchases >= 50) {
        $membership_type = 'Platinum';
    } elseif ($total_purchases >= 30) {
        $membership_type = 'Gold';
    } elseif ($total_purchases >= 10) {
        $membership_type = 'Silver';
    }
    
    $update_sql = "UPDATE customers2 SET membership_type = '$membership_type' WHERE customer_id = $customer_id";
    $conn->query($update_sql);
    
    return [
        'membership_type' => $membership_type,
        'total_purchases' => $total_purchases
    ];
}

// Handle add customer
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_customer'])) {
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $phone_no = mysqli_real_escape_string($conn, $_POST['phone_no']);
    $nic_no = mysqli_real_escape_string($conn, $_POST['nic_no']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $membership_type = mysqli_real_escape_string($conn, $_POST['membership_type']);
    
    $check_sql = "SELECT * FROM customers2 WHERE phone_no = '$phone_no'";
    $check_result = $conn->query($check_sql);
    
    if ($check_result->num_rows > 0) {
        echo "<script>alert('Customer with this phone number already exists!');</script>";
    } else {
        $insert_sql = "INSERT INTO customers2 (name, phone_no, nic_no, email, membership_type, total_purchases) 
                      VALUES ('$name', '$phone_no', " . ($nic_no ? "'$nic_no'" : "NULL") . ", 
                      " . ($email ? "'$email'" : "NULL") . ", '$membership_type', 0)";
        if ($conn->query($insert_sql)) {
            echo "<script>alert('Customer added successfully!'); window.location.href='customer1.php';</script>";
        } else {
            echo "<script>alert('Error adding customer: " . addslashes($conn->error) . "');</script>";
        }
    }
}

// Handle update customer
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_customer'])) {
    $customer_id = intval($_POST['customer_id']);
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $phone_no = mysqli_real_escape_string($conn, $_POST['phone_no']);
    $nic_no = mysqli_real_escape_string($conn, $_POST['nic_no']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $membership_type = mysqli_real_escape_string($conn, $_POST['membership_type']);
    
    $update_sql = "UPDATE customers2 SET name='$name', phone_no='$phone_no', 
                  nic_no=" . ($nic_no ? "'$nic_no'" : "NULL") . ", 
                  email=" . ($email ? "'$email'" : "NULL") . ", 
                  membership_type='$membership_type' WHERE customer_id=$customer_id";
    if ($conn->query($update_sql)) {
        echo "<script>alert('Customer updated successfully!'); window.location.href='customer1.php';</script>";
    } else {
        echo "<script>alert('Error updating customer: " . addslashes($conn->error) . "');</script>";
    }
}

// Handle delete customer
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_customer'])) {
    $customer_id = intval($_POST['customer_id']);
    
    $delete_sql = "DELETE FROM customers2 WHERE customer_id=$customer_id";
    if ($conn->query($delete_sql)) {
        echo "<script>alert('Customer deleted successfully!'); window.location.href='customer1.php';</script>";
    } else {
        echo "<script>alert('Error deleting customer: " . addslashes($conn->error) . "');</script>";
    }
}

// Handle search
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$customers_sql = "SELECT c.*, 
                  (SELECT COUNT(*) FROM bills2 b WHERE b.phone_no = c.phone_no) as purchase_count,
                  (SELECT SUM(b.total) FROM bills2 b WHERE b.phone_no = c.phone_no) as total_spent,
                  (SELECT MAX(b.date) FROM bills2 b WHERE b.phone_no = c.phone_no) as last_purchase
                  FROM customers2 c WHERE 1=1";
if ($search) {
    $customers_sql .= " AND (c.name LIKE '%$search%' OR c.phone_no LIKE '%$search%' OR c.nic_no LIKE '%$search%' OR c.email LIKE '%$search%')";
}
$customers_sql .= " ORDER BY 
    CASE 
        WHEN c.membership_type = 'Platinum' THEN 1
        WHEN c.membership_type = 'Gold' THEN 2
        WHEN c.membership_type = 'Silver' THEN 3
        ELSE 4
    END,
    (SELECT COUNT(*) FROM bills2 b WHERE b.phone_no = c.phone_no) DESC";
$customers_result = $conn->query($customers_sql);

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>KIDS Berry - Customer Management</title>
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
            --platinum: linear-gradient(135deg, #E5E4E2 0%, #B8B8B8 100%);
            --gold: linear-gradient(135deg, #FFD700 0%, #DAA520 100%);
            --silver: linear-gradient(135deg, #C0C0C0 0%, #A9A9A9 100%);
            --regular: linear-gradient(135deg, #6C757D 0%, #495057 100%);
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

        /* Header */
        .header {
            background: var(--primary-dark);
            color: white;
            padding: 16px 24px;
            box-shadow: var(--card-shadow);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .logo-container {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .logo-container img {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            border: 2px solid var(--accent);
            object-fit: cover;
        }

        .brand-info h1 {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 2px;
        }

        .header-tagline {
            font-size: 12px;
            opacity: 0.8;
        }

        .date-time {
            background: rgba(255,255,255,0.1);
            padding: 8px 16px;
            border-radius: var(--radius);
            text-align: center;
        }

        .current-date {
            font-size: 14px;
            font-weight: 600;
        }

        .current-time {
            font-size: 12px;
            opacity: 0.9;
        }

        .logout-btn {
            background: rgba(255,255,255,0.15);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 50px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .logout-btn:hover {
            background: rgba(255,255,255,0.25);
            transform: translateY(-2px);
        }

        /* Navigation */
        .nav-container {
            background: white;
            padding: 12px 24px;
            box-shadow: var(--card-shadow);
            position: sticky;
            top: 82px;
            z-index: 99;
        }

        .nav {
            display: flex;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
            max-width: 1400px;
            margin: 0 auto;
        }

        .nav a {
            text-decoration: none;
            color: var(--dark);
            font-weight: 600;
            padding: 10px 20px;
            border-radius: 50px;
            background: var(--light);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
        }

        .nav a:hover {
            background: var(--primary);
            color: white;
        }

        .nav a.active {
            background: var(--primary);
            color: white;
        }

        /* Main Container */
        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Stats Grid */
        .stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            border: 1px solid rgba(26, 71, 42, 0.1);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary);
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--card-shadow-hover);
        }

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .stat-icon {
            width: 45px;
            height: 45px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: white;
            background: var(--primary);
        }

        .stat-icon.green { background: var(--success); }
        .stat-icon.orange { background: var(--warning); }
        .stat-icon.blue { background: var(--info); }

        .stat-value {
            font-size: 28px;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 12px;
            color: var(--gray);
            font-weight: 600;
        }

        .stat-progress {
            height: 4px;
            background: #e0e0e0;
            border-radius: 2px;
            overflow: hidden;
            margin-top: 12px;
        }

        .stat-progress-fill {
            height: 100%;
            border-radius: 2px;
            transition: width 1s ease;
        }

        /* Card */
        .card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--card-shadow);
            overflow: hidden;
            border: 1px solid rgba(26, 71, 42, 0.1);
        }

        .card-header {
            background: var(--primary);
            color: white;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .card-title {
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .add-btn {
            background: var(--accent);
            color: white;
            padding: 8px 20px;
            border-radius: 50px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            border: none;
            transition: var(--transition);
        }

        .add-btn:hover {
            background: var(--accent-dark);
            transform: translateY(-2px);
        }

        /* Search */
        .search-container {
            padding: 20px;
            border-bottom: 1px solid #e0e0e0;
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 12px 16px;
            padding-left: 40px;
            border: 1px solid #e0e0e0;
            border-radius: var(--radius);
            font-size: 13px;
            transition: var(--transition);
        }

        .search-input:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(26, 71, 42, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 32px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }

        /* Table */
        .table-container {
            overflow-x: auto;
            padding: 0;
        }

        .customers-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .customers-table th {
            background: var(--primary);
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            white-space: nowrap;
        }

        .customers-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #f0f0f0;
        }

        .customers-table tr:hover {
            background: var(--light);
        }

        .customer-name {
            font-weight: 600;
            color: var(--dark);
        }

        .phone-no {
            color: var(--success);
            font-weight: 600;
        }

        .membership-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            color: white;
        }

        .membership-platinum {
            background: linear-gradient(135deg, #E5E4E2 0%, #B8B8B8 100%);
            color: #000;
        }

        .membership-gold {
            background: linear-gradient(135deg, #FFD700 0%, #DAA520 100%);
            color: #000;
        }

        .membership-silver {
            background: linear-gradient(135deg, #C0C0C0 0%, #A9A9A9 100%);
            color: #000;
        }

        .membership-regular {
            background: linear-gradient(135deg, #6C757D 0%, #495057 100%);
            color: white;
        }

        .stats-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            background: var(--light);
            margin-left: 5px;
            font-weight: 600;
            color: var(--primary);
        }

        .purchase-count, .total-spent {
            font-weight: 700;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .action-btn, .delete-btn {
            padding: 6px 12px;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-weight: 600;
            font-size: 11px;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .action-btn {
            background: var(--info);
            color: white;
        }

        .delete-btn {
            background: var(--danger);
            color: white;
        }

        .action-btn:hover, .delete-btn:hover {
            transform: translateY(-2px);
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
            max-width: 450px;
            max-height: 85vh;
            overflow: auto;
            position: relative;
            animation: slideDown 0.3s ease;
        }

        .close {
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 24px;
            cursor: pointer;
            color: var(--gray);
            background: none;
            border: none;
            transition: var(--transition);
        }

        .close:hover {
            color: var(--danger);
        }

        .modal-content h3 {
            padding: 20px 24px;
            background: var(--primary);
            color: white;
            margin: 0;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-content form {
            padding: 24px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 12px;
            margin-bottom: 6px;
            color: var(--dark);
        }

        .form-group label i {
            color: var(--primary);
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e0e0e0;
            border-radius: var(--radius-sm);
            font-size: 13px;
            transition: var(--transition);
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(26, 71, 42, 0.1);
        }

        .modal-content button[type="submit"] {
            width: 100%;
            padding: 12px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: var(--transition);
            margin-top: 10px;
        }

        .modal-content button[type="submit"]:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        /* History Table */
        .history-table-container {
            max-height: 400px;
            overflow-y: auto;
            padding: 20px;
        }

        .history-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        .history-table th {
            background: var(--light);
            padding: 10px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid var(--primary);
        }

        .history-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #f0f0f0;
        }

        .bill-no {
            color: var(--primary);
            font-weight: 600;
        }

        .payment-method-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }

        .payment-cash {
            background: rgba(46, 125, 50, 0.1);
            color: var(--success);
        }

        .payment-card {
            background: rgba(52, 152, 219, 0.1);
            color: var(--info);
        }

        .payment-credit {
            background: rgba(243, 156, 18, 0.1);
            color: var(--warning);
        }

        .no-records {
            text-align: center;
            padding: 40px 20px;
        }

        .no-records i {
            font-size: 50px;
            color: #e0e0e0;
            margin-bottom: 15px;
        }

        .no-records h3 {
            color: var(--dark);
            font-size: 16px;
            margin-bottom: 5px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state i {
            font-size: 60px;
            color: #e0e0e0;
            margin-bottom: 15px;
        }

        .empty-state h3 {
            color: var(--dark);
            font-size: 18px;
            margin-bottom: 8px;
        }

        .empty-state p {
            color: var(--gray);
            font-size: 13px;
            margin-bottom: 20px;
        }

        /* Animations */
        @keyframes slideDown {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Responsive */
        @media (max-width: 992px) {
            .header-content {
                flex-direction: column;
                text-align: center;
            }
            .nav {
                flex-direction: column;
            }
            .nav a {
                justify-content: center;
            }
        }

        @media (max-width: 768px) {
            .main-container {
                padding: 15px;
            }
            .stats-overview {
                grid-template-columns: 1fr 1fr;
            }
            .action-buttons {
                flex-direction: column;
            }
            .card-header {
                flex-direction: column;
                text-align: center;
            }
        }

        @media (max-width: 480px) {
            .stats-overview {
                grid-template-columns: 1fr;
            }
            .stat-card {
                padding: 15px;
            }
            .stat-value {
                font-size: 24px;
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
                    <h1><i class="fas fa-users"></i> Customer Management</h1>
                    <div class="header-tagline">Manage Your Customers Efficiently</div>
                </div>
            </div>
            
            <div style="display: flex; align-items: center; gap: 15px;">
                <div class="date-time">
                    <div class="current-date"><?php echo date('d M Y'); ?></div>
                    <div class="current-time"><?php echo date('h:i A'); ?></div>
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
            <a href="customer1.php" class="active"><i class="fas fa-users"></i> Customers</a>
            <a href="bill_management1.php"><i class="fas fa-history"></i> Bill History</a>
            <a href="report1.php"><i class="fas fa-chart-line"></i> Reports</a>
            <a href="return_sale1.php"><i class="fas fa-undo-alt"></i> Manage Returns</a>
            <!--<a href="prediction_dashboard1.php"><i class="fas fa-bullseye"></i> Predictions</a>-->
        </div>
    </div>

    <div class="main-container">
        <!-- Stats Overview -->
        <div class="stats-overview">
            <?php
            $total_customers_query = "SELECT COUNT(*) as total FROM customers2";
            $total_customers_result = $conn->query($total_customers_query);
            $total_customers = $total_customers_result->fetch_assoc()['total'];

            $membership_counts_query = "SELECT 
                SUM(CASE WHEN membership_type = 'Platinum' THEN 1 ELSE 0 END) as platinum,
                SUM(CASE WHEN membership_type = 'Gold' THEN 1 ELSE 0 END) as gold,
                SUM(CASE WHEN membership_type = 'Silver' THEN 1 ELSE 0 END) as silver,
                SUM(CASE WHEN membership_type = 'Regular' THEN 1 ELSE 0 END) as regular
                FROM customers2";
            $membership_counts_result = $conn->query($membership_counts_query);
            $membership_counts = $membership_counts_result->fetch_assoc();

            $total_purchases_query = "SELECT COUNT(*) as total_purchases, SUM(total) as total_revenue FROM bills2";
            $total_purchases_result = $conn->query($total_purchases_query);
            $total_purchases_data = $total_purchases_result->fetch_assoc();
            ?>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                </div>
                <div class="stat-value"><?php echo $total_customers; ?></div>
                <div class="stat-label">Total Customers</div>
                <div class="stat-progress">
                    <div class="stat-progress-fill" style="width: 100%; background: var(--primary);"></div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon green"><i class="fas fa-crown"></i></div>
                </div>
                <div class="stat-value"><?php echo $membership_counts['platinum'] ?? 0; ?></div>
                <div class="stat-label">Platinum Members</div>
                <div class="stat-progress">
                    <div class="stat-progress-fill" style="width: <?php echo $total_customers > 0 ? ($membership_counts['platinum'] / $total_customers) * 100 : 0; ?>%; background: #E5E4E2;"></div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon orange"><i class="fas fa-star"></i></div>
                </div>
                <div class="stat-value"><?php echo $membership_counts['gold'] ?? 0; ?></div>
                <div class="stat-label">Gold Members</div>
                <div class="stat-progress">
                    <div class="stat-progress-fill" style="width: <?php echo $total_customers > 0 ? ($membership_counts['gold'] / $total_customers) * 100 : 0; ?>%; background: #FFD700;"></div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon blue"><i class="fas fa-medal"></i></div>
                </div>
                <div class="stat-value"><?php echo $membership_counts['silver'] ?? 0; ?></div>
                <div class="stat-label">Silver Members</div>
                <div class="stat-progress">
                    <div class="stat-progress-fill" style="width: <?php echo $total_customers > 0 ? ($membership_counts['silver'] / $total_customers) * 100 : 0; ?>%; background: #C0C0C0;"></div>
                </div>
            </div>
        </div>

        <!-- Customer Directory -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-user-friends"></i> Customer Directory
                </div>
                <button class="add-btn" onclick="showAddModal()">
                    <i class="fas fa-plus"></i> Add Customer
                </button>
            </div>
            
            <div class="search-container">
                <i class="fas fa-search search-icon"></i>
                <input type="text" class="search-input" id="search-input" 
                       placeholder="Search customers by name, phone, NIC, or email..." 
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
            
            <div class="table-container">
                <?php if ($customers_result->num_rows > 0): ?>
                    <table class="customers-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Phone</th>
                                <th>NIC</th>
                                <th>Email</th>
                                <th>Membership</th>
                                <th>Purchases</th>
                                <th>Total Spent</th>
                                <th>Last Purchase</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $customers_result->fetch_assoc()) { 
                                $history_sql = "SELECT bill_no, date, total, payment_method 
                                              FROM bills2
                                              WHERE phone_no = '{$row['phone_no']}' 
                                              ORDER BY date DESC 
                                              LIMIT 5";
                                $history_result = $conn->query($history_sql);
                                
                                $membership_info = updateCustomerMembership($conn, $row['customer_id']);
                                $membership_class = 'membership-' . strtolower($row['membership_type']);
                                $last_purchase_display = convertToColomboTime($row['last_purchase']);
                            ?>
                                <tr>
                                    <td><?php echo $row['customer_id']; ?></td>
                                    <td class="customer-name"><?php echo htmlspecialchars($row['name']); ?></td>
                                    <td class="phone-no"><?php echo htmlspecialchars($row['phone_no']); ?></td>
                                    <td><?php echo htmlspecialchars($row['nic_no'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($row['email'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="membership-badge <?php echo $membership_class; ?>">
                                            <?php echo $row['membership_type']; ?>
                                        </span>
                                        <?php if($row['purchase_count'] > 0): ?>
                                            <span class="stats-badge"><?php echo $row['purchase_count']; ?> purchases</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="purchase-count"><?php echo $row['purchase_count'] ?? 0; ?></td>
                                    <td class="total-spent">Rs<?php echo number_format($row['total_spent'] ?? 0, 2); ?></td>
                                    <td><?php echo $last_purchase_display; ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn" onclick="showEditModal(<?php echo $row['customer_id']; ?>, '<?php echo addslashes($row['name']); ?>', '<?php echo addslashes($row['phone_no']); ?>', '<?php echo addslashes($row['nic_no'] ?? ''); ?>', '<?php echo addslashes($row['email'] ?? ''); ?>', '<?php echo addslashes($row['membership_type']); ?>')">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button class="action-btn" onclick="showHistoryModal('<?php echo addslashes($row['name']); ?>', '<?php echo addslashes($row['phone_no']); ?>')">
                                                <i class="fas fa-history"></i> History
                                            </button>
                                            <form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this customer?')">
                                                <input type="hidden" name="customer_id" value="<?php echo $row['customer_id']; ?>">
                                                <button type="submit" name="delete_customer" class="delete-btn">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </form>
                                        </div>
                                     </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <h3>No customers found</h3>
                        <p>No customers match your search criteria</p>
                        <button class="add-btn" onclick="showAddModal()">
                            <i class="fas fa-plus"></i> Add Your First Customer
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <button class="close" onclick="closeAddModal()">&times;</button>
            <h3><i class="fas fa-user-plus"></i> Add New Customer</h3>
            <form method="post">
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Name *</label>
                    <input type="text" name="name" required placeholder="Enter customer name">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-phone"></i> Phone Number *</label>
                    <input type="text" name="phone_no" required pattern="[0-9]{10}" placeholder="Enter 10-digit phone number">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-id-card"></i> NIC Number</label>
                    <input type="text" name="nic_no" placeholder="Enter NIC number">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-envelope"></i> Email</label>
                    <input type="email" name="email" placeholder="Enter email address">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-crown"></i> Membership Type</label>
                    <select name="membership_type">
                        <option value="Regular">Regular (0-9 purchases)</option>
                        <option value="Silver">Silver (10-29 purchases)</option>
                        <option value="Gold">Gold (30-49 purchases)</option>
                        <option value="Platinum">Platinum (50+ purchases)</option>
                    </select>
                </div>
                <button type="submit" name="add_customer">
                    <i class="fas fa-user-plus"></i> Add Customer
                </button>
            </form>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <button class="close" onclick="closeEditModal()">&times;</button>
            <h3><i class="fas fa-user-edit"></i> Edit Customer</h3>
            <form method="post">
                <input type="hidden" name="customer_id" id="edit-customer_id">
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Name *</label>
                    <input type="text" id="edit-name" name="name" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-phone"></i> Phone Number *</label>
                    <input type="text" id="edit-phone_no" name="phone_no" required pattern="[0-9]{10}">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-id-card"></i> NIC Number</label>
                    <input type="text" id="edit-nic_no" name="nic_no">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-envelope"></i> Email</label>
                    <input type="email" id="edit-email" name="email">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-crown"></i> Membership Type</label>
                    <select id="edit-membership_type" name="membership_type">
                        <option value="Regular">Regular</option>
                        <option value="Silver">Silver</option>
                        <option value="Gold">Gold</option>
                        <option value="Platinum">Platinum</option>
                    </select>
                </div>
                <button type="submit" name="update_customer">
                    <i class="fas fa-save"></i> Update Customer
                </button>
            </form>
        </div>
    </div>

    <!-- History Modal -->
    <div id="historyModal" class="modal">
        <div class="modal-content">
            <button class="close" onclick="closeHistoryModal()">&times;</button>
            <h3 id="history-modal-title"><i class="fas fa-history"></i> Purchase History</h3>
            <div id="history-content" class="history-table-container"></div>
        </div>
    </div>

    <script>
        function updateClock() {
            const now = new Date();
            const options = { year: 'numeric', month: 'short', day: 'numeric' };
            document.querySelector('.current-date').textContent = now.toLocaleDateString('en-US', options);
            let hours = now.getHours();
            let minutes = now.getMinutes();
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12 || 12;
            minutes = minutes < 10 ? '0' + minutes : minutes;
            document.querySelector('.current-time').textContent = `${hours}:${minutes} ${ampm}`;
            setTimeout(updateClock, 60000);
        }
        updateClock();

        const searchInput = document.getElementById('search-input');
        let searchTimeout;
        
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                window.location.href = '?search=' + encodeURIComponent(this.value);
            }, 500);
        });

        function showAddModal() {
            document.getElementById('addModal').style.display = 'flex';
        }

        function closeAddModal() {
            document.getElementById('addModal').style.display = 'none';
        }

        function showEditModal(id, name, phone_no, nic_no, email, membership_type) {
            document.getElementById('edit-customer_id').value = id;
            document.getElementById('edit-name').value = name;
            document.getElementById('edit-phone_no').value = phone_no;
            document.getElementById('edit-nic_no').value = nic_no;
            document.getElementById('edit-email').value = email;
            document.getElementById('edit-membership_type').value = membership_type;
            document.getElementById('editModal').style.display = 'flex';
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        function showHistoryModal(customerName, phoneNo) {
            document.getElementById('history-modal-title').innerHTML = `<i class="fas fa-history"></i> Purchase History for ${customerName}`;
            
            fetch('get_customer_history1.php?phone_no=' + encodeURIComponent(phoneNo))
                .then(response => response.text())
                .then(data => {
                    const historyContent = document.getElementById('history-content');
                    if (data.trim().length === 0) {
                        historyContent.innerHTML = `
                            <div class="no-records">
                                <i class="fas fa-receipt"></i>
                                <h3>No Purchase History</h3>
                                <p>This customer has no purchase history yet.</p>
                            </div>
                        `;
                    } else {
                        historyContent.innerHTML = `
                            <table class="history-table">
                                <thead>
                                    <tr><th>Bill No</th><th>Date & Time</th><th>Total</th><th>Payment</th></tr>
                                </thead>
                                <tbody>${data}</tbody>
                            </table>
                        `;
                    }
                    document.getElementById('historyModal').style.display = 'flex';
                })
                .catch(error => {
                    document.getElementById('history-content').innerHTML = `
                        <div class="no-records">
                            <i class="fas fa-exclamation-triangle"></i>
                            <h3>Error Loading History</h3>
                            <p>Could not load purchase history. Please try again.</p>
                        </div>
                    `;
                    document.getElementById('historyModal').style.display = 'flex';
                });
        }

        function closeHistoryModal() {
            document.getElementById('historyModal').style.display = 'none';
            document.getElementById('history-content').innerHTML = '';
        }

        window.onclick = function(event) {
            if (event.target == document.getElementById('addModal')) closeAddModal();
            if (event.target == document.getElementById('editModal')) closeEditModal();
            if (event.target == document.getElementById('historyModal')) closeHistoryModal();
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeAddModal();
                closeEditModal();
                closeHistoryModal();
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            const progressBars = document.querySelectorAll('.stat-progress-fill');
            progressBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0%';
                setTimeout(() => { bar.style.width = width; }, 300);
            });
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>