<?php
session_start();

include '../send_sms.php'; // Dialog SMS API include කරන්න

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

// Function to convert UTC to Colombo time (UTC+5:30)
function convertToColomboTime($utc_time) {
    if (empty($utc_time) || $utc_time == '0000-00-00 00:00:00') {
        return 'Never';
    }
    
    // Create DateTime object from UTC time
    $utc_date = new DateTime($utc_time, new DateTimeZone('UTC'));
    
    // Convert to Colombo time (UTC+5:30)
    $colombo_timezone = new DateTimeZone('Asia/Colombo');
    $utc_date->setTimezone($colombo_timezone);
    
    // Format as Y-m-d H:i
    return $utc_date->format('Y-m-d H:i');
}

// Function to automatically update customer membership based on purchase count
function updateCustomerMembership($conn, $customer_id, $table_name) {
    // Get total purchases for this customer
    $purchase_sql = "SELECT COUNT(*) as total_purchases FROM bills WHERE phone_no = 
                    (SELECT phone_no FROM $table_name WHERE customer_id = $customer_id)";
    $purchase_result = $conn->query($purchase_sql);
    $purchase_data = $purchase_result->fetch_assoc();
    $total_purchases = $purchase_data['total_purchases'];
    
    // Determine membership based on purchase count
    $membership_type = 'Regular';
    if ($total_purchases >= 50) {
        $membership_type = 'Platinum';
    } elseif ($total_purchases >= 30) {
        $membership_type = 'Gold';
    } elseif ($total_purchases >= 10) {
        $membership_type = 'Silver';
    }
    
    // Update membership in database
    $update_sql = "UPDATE $table_name SET membership_type = '$membership_type' WHERE customer_id = $customer_id";
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
    $branch = $_POST['branch'];
    
    // Determine which table to use based on branch selection
    $table_name = ($branch == 'branch1') ? 'customers' : 'customers2';
    
    // Check if phone number already exists in the selected table
    $check_sql = "SELECT * FROM $table_name WHERE phone_no = '$phone_no'";
    $check_result = $conn->query($check_sql);
    
    if ($check_result->num_rows > 0) {
        echo "<script>alert('Customer with this phone number already exists in " . ($branch == 'branch1' ? 'Branch 1' : 'Branch 2') . "!');</script>";
    } else {
        $insert_sql = "INSERT INTO $table_name (name, phone_no, nic_no, email, membership_type, total_purchases) 
                      VALUES ('$name', '$phone_no', " . ($nic_no ? "'$nic_no'" : "NULL") . ", 
                      " . ($email ? "'$email'" : "NULL") . ", '$membership_type', 0)";
        if ($conn->query($insert_sql)) {
            echo "<script>alert('Customer added successfully to " . ($branch == 'branch1' ? 'Branch 1' : 'Branch 2') . "!'); window.location.href='customer_manage.php';</script>";
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
    $branch = $_POST['branch'];
    
    // Determine which table to use based on branch selection
    $table_name = ($branch == 'branch1') ? 'customers' : 'customers2';
    
    // Check if phone number already exists for another customer in the selected table
    $check_sql = "SELECT * FROM $table_name WHERE phone_no = '$phone_no' AND customer_id != $customer_id";
    $check_result = $conn->query($check_sql);
    
    if ($check_result->num_rows > 0) {
        echo "<script>alert('Customer with this phone number already exists in " . ($branch == 'branch1' ? 'Branch 1' : 'Branch 2') . "!');</script>";
    } else {
        $update_sql = "UPDATE $table_name SET name='$name', phone_no='$phone_no', 
                      nic_no=" . ($nic_no ? "'$nic_no'" : "NULL") . ", 
                      email=" . ($email ? "'$email'" : "NULL") . ", 
                      membership_type='$membership_type' WHERE customer_id=$customer_id";
        if ($conn->query($update_sql)) {
            echo "<script>alert('Customer updated successfully in " . ($branch == 'branch1' ? 'Branch 1' : 'Branch 2') . "!'); window.location.href='customer_manage.php';</script>";
        } else {
            echo "<script>alert('Error updating customer: " . addslashes($conn->error) . "');</script>";
        }
    }
}

// Handle delete customer
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_customer'])) {
    $customer_id = intval($_POST['customer_id']);
    $branch = $_POST['branch'];
    
    // Determine which table to use based on branch selection
    $table_name = ($branch == 'branch1') ? 'customers' : 'customers2';
    
    $delete_sql = "DELETE FROM $table_name WHERE customer_id=$customer_id";
    if ($conn->query($delete_sql)) {
        echo "<script>alert('Customer deleted successfully from " . ($branch == 'branch1' ? 'Branch 1' : 'Branch 2') . "!'); window.location.href='customer_manage.php';</script>";
    } else {
        echo "<script>alert('Error deleting customer: " . addslashes($conn->error) . "');</script>";
    }
}

// Handle bulk SMS send
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['send_bulk_sms'])) {
    $message = trim($_POST['sms_message']);
    if (empty($message)) {
        echo "<script>alert('Please type a message!');</script>";
    } else {
        // Call the bulk SMS function
        $result = sendBulkSMS($message);
        echo "<script>alert('$result');</script>";
    }
}

// Handle single SMS send
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['send_single_sms'])) {
    $customer_id = intval($_POST['customer_id']);
    $message = trim($_POST['single_sms_message']);
    $branch = $_POST['branch'];
    
    if (empty($message)) {
        echo "<script>alert('Please type a message!');</script>";
    } else {
        // Determine which table to use based on branch selection
        $table_name = ($branch == 'branch1') ? 'customers' : 'customers2';
        
        // Get customer details
        $customer_sql = "SELECT phone_no, name FROM $table_name WHERE customer_id = $customer_id";
        $customer_result = $conn->query($customer_sql);
        
        if ($customer_result->num_rows > 0) {
            $customer = $customer_result->fetch_assoc();
            $result = sendSingleSMS($customer['phone_no'], $message, $customer['name']);
            
            if ($result['success']) {
                echo "<script>alert('SMS sent successfully to " . addslashes($customer['name']) . "!');</script>";
            } else {
                echo "<script>alert('Failed to send SMS: " . addslashes($result['error']) . "');</script>";
            }
        } else {
            echo "<script>alert('Customer not found!');</script>";
        }
    }
}

// Get selected branch from session or default to branch1
$selected_branch = isset($_SESSION['selected_branch_customer']) ? $_SESSION['selected_branch_customer'] : 'branch1';

// Handle branch selection
if (isset($_POST['select_branch'])) {
    $selected_branch = $_POST['selected_branch'];
    $_SESSION['selected_branch_customer'] = $selected_branch;
}

// Determine which table to use based on selected branch
$table_name = ($selected_branch == 'branch1') ? 'customers' : 'customers2';

// Handle search
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$customers_sql = "SELECT c.*, 
                  (SELECT COUNT(*) FROM bills b WHERE b.phone_no = c.phone_no) as purchase_count,
                  (SELECT SUM(b.total) FROM bills b WHERE b.phone_no = c.phone_no) as total_spent,
                  (SELECT MAX(b.date) FROM bills b WHERE b.phone_no = c.phone_no) as last_purchase
                  FROM $table_name c WHERE 1=1";
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
    (SELECT COUNT(*) FROM bills b WHERE b.phone_no = c.phone_no) DESC";
$customers_result = $conn->query($customers_sql);

// Get statistics for the selected branch
$total_customers = $conn->query("SELECT COUNT(*) as count FROM $table_name")->fetch_assoc()['count'];
$regular_customers = $conn->query("SELECT COUNT(*) as count FROM $table_name WHERE membership_type='Regular'")->fetch_assoc()['count'];
$premium_customers = $conn->query("SELECT COUNT(*) as count FROM $table_name WHERE membership_type IN ('Silver', 'Gold', 'Platinum')")->fetch_assoc()['count'];
$new_customers_today = $conn->query("SELECT COUNT(*) as count FROM $table_name WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['count'];

// Get SMS statistics (global, not branch specific)
$sms_sent_today = $conn->query("SELECT COUNT(*) as count FROM sms_logs WHERE DATE(sent_at) = CURDATE() AND status='success'")->fetch_assoc()['count'];
$sms_sent_total = $conn->query("SELECT COUNT(*) as count FROM sms_logs WHERE status='success'")->fetch_assoc()['count'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Kids Berry - Customer Management</title>
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
            --sms-blue: #007bff;
            --sms-dark-blue: #0056b3;
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
            --gradient-sms: linear-gradient(135deg, var(--sms-blue), var(--sms-dark-blue));
            --platinum: #e5e4e2;
            --gold: #ffd700;
            --silver: #c0c0c0;
            --regular: #6c757d;
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
            background: var(--gradient-sms);
        }

        .stat-card:nth-child(4)::before {
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
            background: var(--gradient-sms);
        }

        .stat-card:nth-child(4) .stat-icon {
            background: linear-gradient(135deg, var(--primary-purple), var(--dark-green));
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
            color: var(--sms-dark-blue);
        }

        .stat-card:nth-child(4) .stat-value {
            color: var(--dark-purple);
        }

        .stat-label {
            color: var(--dark-gray);
            font-weight: 600;
            font-size: 0.85rem;
        }

        /* Bulk SMS Section */
        .bulk-sms-section {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 15px;
            box-shadow: var(--shadow-light);
            margin-bottom: 20px;
            animation: fadeInUp 0.8s ease;
        }

        .bulk-sms-section h3 {
            color: var(--sms-dark-blue);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .bulk-sms-section textarea {
            width: 100%;
            height: 120px;
            padding: 15px;
            font-size: 14px;
            border-radius: var(--border-radius-small);
            border: 2px solid var(--sms-blue);
            resize: vertical;
            margin-bottom: 15px;
            background: #f8fbff;
        }

        .bulk-sms-section .btn-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .bulk-sms-section button {
            padding: 12px 20px;
            font-size: 14px;
            background: var(--gradient-sms);
            color: white;
            border: none;
            border-radius: var(--border-radius-small);
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .bulk-sms-section button:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        .bulk-sms-section button.secondary {
            background: var(--gradient-primary);
        }

        .sms-info {
            margin-top: 15px;
            padding: 10px;
            background: #e7f3ff;
            border-radius: var(--border-radius-small);
            border-left: 4px solid var(--sms-blue);
            font-size: 0.85rem;
        }

        .sms-info i {
            color: var(--sms-blue);
            margin-right: 5px;
        }

        /* Customer Management Section */
        .management-section {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 15px;
            box-shadow: var(--shadow-light);
            margin-bottom: 20px;
            animation: fadeInUp 0.8s ease;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-gray);
            flex-wrap: wrap;
            gap: 10px;
        }

        .section-title {
            color: var(--primary-purple);
            font-size: 1.2rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .add-btn {
            background: var(--gradient-secondary);
            color: var(--white);
            border: none;
            padding: 10px 15px;
            border-radius: var(--border-radius-small);
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.9rem;
        }

        .add-btn:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-medium);
        }

        /* Search Bar */
        .search-bar {
            margin-bottom: 15px;
            position: relative;
        }

        .search-bar input {
            width: 100%;
            padding: 12px 15px;
            padding-left: 45px;
            border: 2px solid var(--medium-gray);
            border-radius: var(--border-radius-small);
            font-size: 14px;
            transition: var(--transition);
            background: var(--white);
            box-shadow: var(--shadow-light);
        }

        .search-bar input:focus {
            border-color: var(--primary-purple);
            box-shadow: 0 0 0 3px rgba(106, 13, 173, 0.1);
            outline: none;
        }

        .search-bar i {
            position: absolute;
            left: 15px;
            top: 12px;
            color: #7f8c8d;
            font-size: 1.1rem;
        }

        /* Table Styles */
        .table-container {
            overflow-x: auto;
            border-radius: var(--border-radius-small);
            box-shadow: var(--shadow-light);
            margin-bottom: 15px;
        }

        .customers-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--white);
            min-width: 1200px;
        }

        .customers-table th {
            background: var(--gradient-primary);
            color: var(--white);
            padding: 12px 10px;
            text-align: left;
            font-weight: 600;
            position: sticky;
            top: 0;
            font-size: 0.85rem;
        }

        .customers-table tr {
            transition: var(--transition);
            animation: fadeIn 0.5s ease;
        }

        .customers-table tr:nth-child(even) {
            background-color: var(--light-gray);
        }

        .customers-table tr:hover {
            background-color: #e8f4fc;
            transform: translateX(5px);
        }

        .customers-table td {
            padding: 12px 10px;
            border-bottom: 1px solid var(--medium-gray);
            font-size: 0.85rem;
        }

        .customer-name {
            font-weight: 600;
            color: var(--dark-gray);
        }

        .phone-no {
            color: var(--dark-green);
        }

        .nic-no {
            color: #7f8c8d;
        }

        .email {
            color: var(--primary-purple);
        }

        .membership-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
            margin-right: 5px;
        }

        .membership-platinum {
            background: linear-gradient(135deg, var(--platinum) 0%, #b8b8b8 100%);
            color: #000;
        }

        .membership-gold {
            background: linear-gradient(135deg, var(--gold) 0%, #daa520 100%);
            color: #000;
        }

        .membership-silver {
            background: linear-gradient(135deg, var(--silver) 0%, #a9a9a9 100%);
            color: #000;
        }

        .membership-regular {
            background: linear-gradient(135deg, var(--regular) 0%, #495057 100%);
            color: white;
        }

        .stats-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 8px;
            font-size: 10px;
            background: rgba(0,0,0,0.1);
        }

        .purchase-count {
            color: var(--dark-green);
            font-weight: bold;
        }

        .total-spent {
            color: var(--primary-purple);
            font-weight: bold;
        }

        .created-at {
            color: #6c757d;
            font-size: 13px;
        }

        .action-btn {
            background: var(--gradient-secondary);
            color: var(--white);
            border: none;
            padding: 6px 10px;
            border-radius: var(--border-radius-small);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 4px;
            font-weight: 600;
            margin-right: 5px;
            font-size: 0.8rem;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-light);
        }

        .sms-btn {
            background: var(--gradient-sms);
            color: var(--white);
            border: none;
            padding: 6px 10px;
            border-radius: var(--border-radius-small);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 4px;
            font-weight: 600;
            margin-right: 5px;
            font-size: 0.8rem;
        }

        .sms-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-light);
        }

        .history-btn {
            background: linear-gradient(135deg, var(--light-purple), var(--medium-green));
            color: var(--white);
            border: none;
            padding: 6px 10px;
            border-radius: var(--border-radius-small);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 4px;
            font-weight: 600;
            margin-right: 5px;
            font-size: 0.8rem;
        }

        .history-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-light);
        }

        .delete-btn {
            background: linear-gradient(135deg, #dc3545, #c0392b);
            color: var(--white);
            border: none;
            padding: 6px 10px;
            border-radius: var(--border-radius-small);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 4px;
            font-weight: 600;
            font-size: 0.8rem;
        }

        .delete-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(220, 53, 69, 0.4);
        }

        .no-records {
            text-align: center;
            padding: 30px;
            color: #7f8c8d;
        }

        .no-records i {
            font-size: 36px;
            margin-bottom: 10px;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            justify-content: center;
            align-items: center;
            animation: fadeIn 0.3s ease;
            padding: 15px;
        }

        .modal-content {
            background: var(--white);
            padding: 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-heavy);
            width: 100%;
            max-width: 500px;
            position: relative;
            animation: fadeInUp 0.3s ease;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-content h3 {
            margin-top: 0;
            color: var(--primary-purple);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.3rem;
        }

        .modal-content .close {
            position: absolute;
            right: 15px;
            top: 15px;
            font-size: 20px;
            cursor: pointer;
            color: var(--dark-gray);
            transition: var(--transition);
            background: none;
            border: none;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .modal-content .close:hover {
            color: var(--primary-purple);
            transform: scale(1.1);
            background: var(--light-gray);
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: var(--dark-gray);
            font-size: 0.9rem;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 2px solid var(--medium-gray);
            border-radius: var(--border-radius-small);
            font-size: 14px;
            transition: var(--transition);
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--primary-purple);
            outline: none;
            box-shadow: 0 0 0 3px rgba(106, 13, 173, 0.1);
        }

        .modal-content button {
            width: 100%;
            padding: 12px;
            background: var(--gradient-secondary);
            color: var(--white);
            border: none;
            border-radius: var(--border-radius-small);
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            font-size: 14px;
        }

        .modal-content button:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        /* History Modal Specific */
        #historyModal .modal-content {
            max-width: 700px;
        }

        .history-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .history-table th {
            background: var(--gradient-primary);
            color: white;
            padding: 10px;
            font-size: 13px;
        }

        .history-table td {
            padding: 8px 10px;
            border-bottom: 1px solid var(--light-gray);
            font-size: 13px;
        }

        .history-table tr:hover {
            background: var(--light-gray);
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
            from { opacity: 0; }
            to { opacity: 1; }
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

        .pulse {
            animation: pulse 2s infinite;
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
            z-index: 2000;
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
                grid-template-columns: repeat(4, 1fr);
                gap: 20px;
            }
            
            .header {
                padding: 18px 25px;
            }
            
            .header h1 {
                font-size: 1.6rem;
            }
            
            .management-section, .bulk-sms-section {
                padding: 20px;
            }
            
            .stat-card {
                padding: 20px;
            }
            
            .stat-value {
                font-size: 1.7rem;
            }
            
            .customers-table th,
            .customers-table td {
                padding: 15px;
                font-size: 0.9rem;
            }
            
            .action-btn,
            .sms-btn,
            .history-btn,
            .delete-btn {
                padding: 8px 15px;
                font-size: 0.85rem;
            }
            
            .toast {
                left: auto;
                right: 20px;
                width: auto;
            }
            
            .fab {
                display: none;
            }
            
            .bulk-sms-section .btn-group {
                flex-wrap: nowrap;
            }
            
            .branch-selector {
                padding: 15px 20px;
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
                gap: 25px;
            }
            
            .stat-card {
                padding: 25px;
            }
            
            .stat-icon {
                width: 60px;
                height: 60px;
                font-size: 1.8rem;
            }
            
            .stat-value {
                font-size: 2rem;
            }
            
            .management-section, .bulk-sms-section {
                padding: 25px;
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
            
            .section-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .add-btn {
                width: 100%;
                justify-content: center;
            }
            
            .bulk-sms-section .btn-group {
                flex-direction: column;
            }
            
            .bulk-sms-section button {
                width: 100%;
                justify-content: center;
            }
            
            .branch-selector {
                flex-direction: column;
                align-items: flex-start;
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
                <li><a href="admin_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="cashier_prediction.php"><i class="fas fa-chart-line"></i> Cashier Predictions</a></li>
                <li><a href="customer_manage.php" class="active"><i class="fas fa-users"></i> Customer Management</a></li>
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
                <h1><i class="fas fa-users"></i> Customer Management</h1>
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

            <!-- Branch Selection -->
            <div class="branch-selector">
                <div class="branch-label">
                    <i class="fas fa-store"></i> Select Branch:
                </div>
                <form method="post" action="" id="branchForm">
                    <div class="branch-radio-group">
                        <label class="branch-option">
                            <input type="radio" name="selected_branch" value="branch1" <?php echo ($selected_branch == 'branch1') ? 'checked' : ''; ?> onchange="this.form.submit()">
                            Branch 1 <span class="branch-badge" style="background: linear-gradient(135deg, #6a0dad, #4b0082);">Customers Table</span>
                        </label>
                        <label class="branch-option">
                            <input type="radio" name="selected_branch" value="branch2" <?php echo ($selected_branch == 'branch2') ? 'checked' : ''; ?> onchange="this.form.submit()">
                            Branch 2 <span class="branch-badge" style="background: linear-gradient(135deg, #228b22, #32cd32);">Customers2 Table</span>
                        </label>
                    </div>
                    <input type="hidden" name="select_branch" value="1">
                </form>
                <div style="margin-left: auto;">
                    <span class="branch-badge">
                        <i class="fas fa-database"></i> Currently viewing: <?php echo ($selected_branch == 'branch1') ? 'Branch 1 (customers)' : 'Branch 2 (customers2)'; ?>
                    </span>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="stats-container">
                <div class="stat-card" onclick="showToast('Total Customers in <?php echo ($selected_branch == 'branch1') ? 'Branch 1' : 'Branch 2'; ?>: <?php echo $total_customers; ?>')">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-value"><?php echo $total_customers; ?></div>
                    <div class="stat-label">Total Customers</div>
                </div>
                <div class="stat-card" onclick="showToast('Regular Members: <?php echo $regular_customers; ?>')">
                    <div class="stat-icon">
                        <i class="fas fa-user-friends"></i>
                    </div>
                    <div class="stat-value"><?php echo $regular_customers; ?></div>
                    <div class="stat-label">Regular Members</div>
                </div>
                <div class="stat-card" onclick="showToast('SMS Sent Today: <?php echo $sms_sent_today; ?> | Total: <?php echo $sms_sent_total; ?>')">
                    <div class="stat-icon">
                        <i class="fas fa-sms"></i>
                    </div>
                    <div class="stat-value"><?php echo $sms_sent_today; ?></div>
                    <div class="stat-label">SMS Sent Today</div>
                </div>
                <div class="stat-card" onclick="showToast('New Customers Today: <?php echo $new_customers_today; ?>')">
                    <div class="stat-icon">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <div class="stat-value"><?php echo $new_customers_today; ?></div>
                    <div class="stat-label">New Today</div>
                </div>
            </div>

            <!-- Bulk SMS Section -->
            <div class="bulk-sms-section">
                <h3><i class="fas fa-sms"></i> Bulk SMS to All Customers</h3>
                <form method="post">
                    <textarea name="sms_message" placeholder="Type your SMS message here... Example: Dear [Customer Name], 🎉 New Year Offer! Get 20% discount on all mobiles till Dec 31! Visit Kids Berry today! 🛍️" required></textarea>
                    <div class="btn-group">
                        <button type="submit" name="send_bulk_sms" onclick="return confirm('Send SMS to all <?php echo $total_customers; ?> customers in <?php echo ($selected_branch == 'branch1') ? 'Branch 1' : 'Branch 2'; ?>?\n\nDaily Limit: 15 SMS (Dialog Ideamart)\nFrom Number: 0789239271\n\nAre you sure?')">
                            <i class="fas fa-paper-plane"></i> Send to All Customers (<?php echo $total_customers; ?>)
                        </button>
                        <button type="button" class="secondary" onclick="showSMSTemplateModal()">
                            <i class="fas fa-lightbulb"></i> Use Template
                        </button>
                    </div>
                </form>
                <div class="sms-info">
                    <i class="fas fa-info-circle"></i>
                    <strong>SMS Service Info:</strong> Messages will be sent via Dialog Ideamart API from number <strong>0789239271</strong>. Daily limit: 15 SMS (free). Use [Customer Name] for personalization.
                </div>
            </div>

            <!-- Customer Management Section -->
            <div class="management-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-list"></i> Customer List - <?php echo ($selected_branch == 'branch1') ? 'Branch 1' : 'Branch 2'; ?></h2>
                    <button class="add-btn" onclick="showAddModal()">
                        <i class="fas fa-plus"></i> Add Customer
                    </button>
                </div>
                
                <div class="search-bar">
                    <i class="fas fa-search"></i>
                    <input type="text" id="search-input" placeholder="Search by Name, Phone, NIC, or Email" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                
                <div class="table-container">
                    <?php if ($customers_result && $customers_result->num_rows > 0): ?>
                        <table class="customers-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Phone Number</th>
                                    <th>NIC Number</th>
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
                                    // Update membership based on purchases
                                    $membership_info = updateCustomerMembership($conn, $row['customer_id'], $table_name);
                                    $membership_class = 'membership-' . strtolower($row['membership_type']);
                                    
                                    // Convert last purchase time to Colombo time
                                    $last_purchase_display = convertToColomboTime($row['last_purchase']);
                                ?>
                                    <tr>
                                        <td><?php echo $row['customer_id']; ?></td>
                                        <td class="customer-name"><?php echo htmlspecialchars($row['name']); ?></td>
                                        <td class="phone-no"><?php echo htmlspecialchars($row['phone_no']); ?></td>
                                        <td class="nic-no"><?php echo htmlspecialchars($row['nic_no'] ?? 'N/A'); ?></td>
                                        <td class="email"><?php echo htmlspecialchars($row['email'] ?? 'N/A'); ?></td>
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
                                        <td class="created-at">
                                            <?php echo $last_purchase_display; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; flex-wrap: wrap; gap: 5px;">
                                                <button class="action-btn" onclick="showEditModal(<?php echo $row['customer_id']; ?>, '<?php echo addslashes($row['name']); ?>', '<?php echo addslashes($row['phone_no']); ?>', '<?php echo addslashes($row['nic_no'] ?? ''); ?>', '<?php echo addslashes($row['email'] ?? ''); ?>', '<?php echo addslashes($row['membership_type']); ?>', '<?php echo $selected_branch; ?>')">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <button class="sms-btn" onclick="showSingleSMSModal(<?php echo $row['customer_id']; ?>, '<?php echo addslashes($row['name']); ?>', '<?php echo addslashes($row['phone_no']); ?>', '<?php echo $selected_branch; ?>')">
                                                    <i class="fas fa-sms"></i> SMS
                                                </button>
                                                <button class="history-btn" onclick="showHistoryModal('<?php echo addslashes($row['name']); ?>', '<?php echo addslashes($row['phone_no']); ?>')">
                                                    <i class="fas fa-history"></i> History
                                                </button>
                                                <form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this customer from <?php echo ($selected_branch == 'branch1') ? 'Branch 1' : 'Branch 2'; ?>?')">
                                                    <input type="hidden" name="customer_id" value="<?php echo $row['customer_id']; ?>">
                                                    <input type="hidden" name="branch" value="<?php echo $selected_branch; ?>">
                                                    <button type="submit" name="delete_customer" class="delete-btn">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-records">
                            <i class="fas fa-users"></i>
                            <h3>No customers found in <?php echo ($selected_branch == 'branch1') ? 'Branch 1' : 'Branch 2'; ?></h3>
                            <p>No customers match your search criteria</p>
                        </div>
                    <?php endif; ?>
                </div>
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
                    <label for="add-name">Name *</label>
                    <input type="text" id="add-name" name="name" required>
                </div>
                <div class="form-group">
                    <label for="add-phone_no">Phone Number *</label>
                    <input type="text" id="add-phone_no" name="phone_no" required 
                           pattern="[0-9]{10}" title="Enter a valid 10-digit phone number">
                </div>
                <div class="form-group">
                    <label for="add-nic_no">NIC Number</label>
                    <input type="text" id="add-nic_no" name="nic_no">
                </div>
                <div class="form-group">
                    <label for="add-email">Email</label>
                    <input type="email" id="add-email" name="email">
                </div>
                <div class="form-group">
                    <label for="add-membership_type">Membership Type</label>
                    <select id="add-membership_type" name="membership_type">
                        <option value="Regular">Regular (0-9 purchases)</option>
                        <option value="Silver">Silver (10-29 purchases)</option>
                        <option value="Gold">Gold (30-49 purchases)</option>
                        <option value="Platinum">Platinum (50+ purchases)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="add-branch">Save to Branch</label>
                    <select class="form-control" id="add-branch" name="branch" required>
                        <option value="branch1" <?php echo ($selected_branch == 'branch1') ? 'selected' : ''; ?>>Branch 1 (customers table)</option>
                        <option value="branch2" <?php echo ($selected_branch == 'branch2') ? 'selected' : ''; ?>>Branch 2 (customers2 table)</option>
                    </select>
                </div>
                <button type="submit" name="add_customer">Add Customer</button>
            </form>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <button class="close" onclick="closeEditModal()">&times;</button>
            <h3><i class="fas fa-edit"></i> Edit Customer</h3>
            <form method="post">
                <input type="hidden" id="edit-customer_id" name="customer_id">
                <input type="hidden" id="edit-branch" name="branch">
                <div class="form-group">
                    <label for="edit-name">Name *</label>
                    <input type="text" id="edit-name" name="name" required>
                </div>
                <div class="form-group">
                    <label for="edit-phone_no">Phone Number *</label>
                    <input type="text" id="edit-phone_no" name="phone_no" required 
                           pattern="[0-9]{10}" title="Enter a valid 10-digit phone number">
                </div>
                <div class="form-group">
                    <label for="edit-nic_no">NIC Number</label>
                    <input type="text" id="edit-nic_no" name="nic_no">
                </div>
                <div class="form-group">
                    <label for="edit-email">Email</label>
                    <input type="email" id="edit-email" name="email">
                </div>
                <div class="form-group">
                    <label for="edit-membership_type">Membership Type</label>
                    <select id="edit-membership_type" name="membership_type">
                        <option value="Regular">Regular</option>
                        <option value="Silver">Silver</option>
                        <option value="Gold">Gold</option>
                        <option value="Platinum">Platinum</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="edit-branch_select">Branch</label>
                    <select id="edit-branch_select" name="branch" class="form-control" required>
                        <option value="branch1">Branch 1 (customers table)</option>
                        <option value="branch2">Branch 2 (customers2 table)</option>
                    </select>
                </div>
                <button type="submit" name="update_customer">Update Customer</button>
            </form>
        </div>
    </div>

    <!-- Single SMS Modal -->
    <div id="singleSMSModal" class="modal">
        <div class="modal-content">
            <button class="close" onclick="closeSingleSMSModal()">&times;</button>
            <h3><i class="fas fa-sms"></i> Send SMS to Customer</h3>
            <form method="post">
                <input type="hidden" id="sms-customer_id" name="customer_id">
                <input type="hidden" id="sms-branch" name="branch">
                <div class="form-group">
                    <label>Customer: <span id="sms-customer-name" style="color: var(--primary-purple); font-weight: bold;"></span></label>
                </div>
                <div class="form-group">
                    <label>Phone: <span id="sms-customer-phone" style="color: var(--dark-green); font-weight: bold;"></span></label>
                </div>
                <div class="form-group">
                    <label for="single-sms-message">Message *</label>
                    <textarea id="single-sms-message" name="single_sms_message" rows="4" required placeholder="Type your SMS message here..."></textarea>
                </div>
                <div class="form-group">
                    <button type="button" onclick="useSMSTemplate('single')" style="background: var(--gradient-primary); margin-bottom: 10px;">
                        <i class="fas fa-lightbulb"></i> Use Template
                    </button>
                </div>
                <button type="submit" name="send_single_sms" style="background: var(--gradient-sms);">
                    <i class="fas fa-paper-plane"></i> Send SMS Message
                </button>
            </form>
        </div>
    </div>

    <!-- SMS Template Modal -->
    <div id="smsTemplateModal" class="modal">
        <div class="modal-content">
            <button class="close" onclick="closeSMSTemplateModal()">&times;</button>
            <h3><i class="fas fa-lightbulb"></i> SMS Message Templates</h3>
            <div class="form-group">
                <label>Select a template:</label>
                <select id="template-select" onchange="loadSMSTemplate(this.value)">
                    <option value="">-- Select Template --</option>
                    <option value="welcome">Welcome Message</option>
                    <option value="promotion">Promotion/Offer</option>
                    <option value="anniversary">Anniversary/Birthday</option>
                    <option value="back_in_stock">Back in Stock</option>
                    <option value="feedback">Feedback Request</option>
                    <option value="membership">Membership Upgrade</option>
                    <option value="seasonal">Seasonal Greetings</option>
                </select>
            </div>
            <div class="form-group">
                <label>Template Preview:</label>
                <textarea id="template-preview" rows="6" readonly style="background: #f5f5f5;"></textarea>
            </div>
            <div class="form-group">
                <button type="button" onclick="applySMSTemplate()" style="background: var(--gradient-sms);">
                    <i class="fas fa-check"></i> Use This Template
                </button>
                <button type="button" onclick="closeSMSTemplateModal()" style="background: var(--medium-gray); color: var(--dark-gray); margin-top: 10px;">
                    Cancel
                </button>
            </div>
        </div>
    </div>

    <!-- History Modal -->
    <div id="historyModal" class="modal">
        <div class="modal-content">
            <button class="close" onclick="closeHistoryModal()">&times;</button>
            <h3 id="history-modal-title"><i class="fas fa-history"></i> Purchase History</h3>
            <div id="history-content">
                <!-- History will be loaded via AJAX -->
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div class="toast" id="toast">
        <i class="fas fa-info-circle"></i>
        <span id="toastMessage">This is a toast message</span>
    </div>

    <script>
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

        // Search functionality
        const searchInput = document.getElementById('search-input');
        let searchTimeout;
        
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                window.location.href = '?search=' + encodeURIComponent(this.value);
            }, 500);
        });

        searchInput.addEventListener('focus', function() {
            this.select();
        });

        // Modal functions for Add
        function showAddModal() {
            document.getElementById('addModal').style.display = 'flex';
        }

        function closeAddModal() {
            document.getElementById('addModal').style.display = 'none';
        }

        // Modal functions for Edit
        function showEditModal(id, name, phone_no, nic_no, email, membership_type, branch) {
            document.getElementById('edit-customer_id').value = id;
            document.getElementById('edit-name').value = name;
            document.getElementById('edit-phone_no').value = phone_no;
            document.getElementById('edit-nic_no').value = nic_no;
            document.getElementById('edit-email').value = email;
            document.getElementById('edit-membership_type').value = membership_type;
            document.getElementById('edit-branch').value = branch;
            document.getElementById('edit-branch_select').value = branch;
            document.getElementById('editModal').style.display = 'flex';
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        // Modal functions for Single SMS
        function showSingleSMSModal(customerId, customerName, customerPhone, branch) {
            document.getElementById('sms-customer_id').value = customerId;
            document.getElementById('sms-customer-name').textContent = customerName;
            document.getElementById('sms-customer-phone').textContent = customerPhone;
            document.getElementById('sms-branch').value = branch;
            document.getElementById('singleSMSModal').style.display = 'flex';
        }

        function closeSingleSMSModal() {
            document.getElementById('singleSMSModal').style.display = 'none';
        }

        // Modal functions for SMS Template
        function showSMSTemplateModal() {
            document.getElementById('smsTemplateModal').style.display = 'flex';
        }

        function closeSMSTemplateModal() {
            document.getElementById('smsTemplateModal').style.display = 'none';
        }

        // SMS Templates
        const smsTemplates = {
            'welcome': `Welcome to Kids Berry!

Dear [Customer Name],

Thank you for choosing Kids Berry! Get 15% off on your first purchase.

Visit: [Your Store Address]
Call: [Your Phone Number]

- Kids Berry Team`,
            
            'promotion': `SPECIAL OFFER!

Dear [Customer Name],

25% OFF on all electronics till [End Date].

Visit Kids Berry today for best deals!

- Kids Berry Team`,
            
            'anniversary': `Happy Birthday/Anniversary!

Dear [Customer Name],

Celebrate with 20% DISCOUNT on your next purchase. Valid for 7 days.

Visit Kids Berry!

- Kids Berry Team`,
            
            'back_in_stock': `BACK IN STOCK!

Dear [Customer Name],

Great news! Product you wanted is back in stock.

Hurry, limited stock available!

- Kids Berry Team`,
            
            'feedback': `WE VALUE YOUR FEEDBACK!

Dear [Customer Name],

Thank you for shopping with Kids Berry. Share your experience with us.

Your feedback helps us serve you better!

- Kids Berry Team`,
            
            'membership': `MEMBERSHIP UPGRADE!

Dear [Customer Name],

Congratulations! You've been upgraded to [Membership Level] membership.

Enjoy exclusive benefits!

- Kids Berry Team`,
            
            'seasonal': `Season's Greetings!

Dear [Customer Name],

Wishing you joy this season. Get special holiday discounts at Kids Berry!

Visit us soon!

- Kids Berry Team`
        };

        function loadSMSTemplate(templateKey) {
            const preview = document.getElementById('template-preview');
            if (templateKey && smsTemplates[templateKey]) {
                preview.value = smsTemplates[templateKey];
            } else {
                preview.value = '';
            }
        }

        function applySMSTemplate() {
            const templateSelect = document.getElementById('template-select');
            const selectedTemplate = templateSelect.value;
            
            if (selectedTemplate && smsTemplates[selectedTemplate]) {
                // Check which textarea to update
                const bulkTextarea = document.querySelector('textarea[name="sms_message"]');
                const singleTextarea = document.getElementById('single-sms-message');
                
                if (bulkTextarea && bulkTextarea.offsetParent) {
                    bulkTextarea.value = smsTemplates[selectedTemplate];
                } else if (singleTextarea && singleTextarea.offsetParent) {
                    singleTextarea.value = smsTemplates[selectedTemplate];
                }
                
                closeSMSTemplateModal();
                showToast('Template applied successfully!');
            } else {
                showToast('Please select a template first');
            }
        }

        function useSMSTemplate(target) {
            showSMSTemplateModal();
        }

        // Modal functions for History
        function showHistoryModal(customerName, phoneNo) {
            document.getElementById('history-modal-title').textContent = `Purchase History for ${customerName}`;
            
            // Load history via AJAX with Colombo time conversion
            fetch('get_customer_history_admin.php?phone_no=' + encodeURIComponent(phoneNo))
                .then(response => response.text())
                .then(data => {
                    document.getElementById('history-content').innerHTML = data;
                    document.getElementById('historyModal').style.display = 'flex';
                })
                .catch(error => {
                    document.getElementById('history-content').innerHTML = '<p>Error loading history</p>';
                    document.getElementById('historyModal').style.display = 'flex';
                });
        }

        function closeHistoryModal() {
            document.getElementById('historyModal').style.display = 'none';
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const addModal = document.getElementById('addModal');
            const editModal = document.getElementById('editModal');
            const singleSMSModal = document.getElementById('singleSMSModal');
            const smsTemplateModal = document.getElementById('smsTemplateModal');
            const historyModal = document.getElementById('historyModal');
            
            if (event.target == addModal) closeAddModal();
            if (event.target == editModal) closeEditModal();
            if (event.target == singleSMSModal) closeSingleSMSModal();
            if (event.target == smsTemplateModal) closeSMSTemplateModal();
            if (event.target == historyModal) closeHistoryModal();
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

            const animatedElements = document.querySelectorAll('.stat-card, .management-section, .bulk-sms-section');
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
                const number = parseInt(originalText);
                if (!isNaN(number)) {
                    animateValue(stat, 0, number, 1500, false);
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
                element.textContent = value.toLocaleString();
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
    </script>
</body>
</html>
<?php
$conn->close();
?>