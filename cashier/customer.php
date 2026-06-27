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

$branch_id = 2; // Hardcoded for now, update to $_SESSION['branch_id'] later
$_SESSION['branch_id'] = $branch_id;

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
function updateCustomerMembership($conn, $customer_id) {
    // Get total purchases for this customer
    $purchase_sql = "SELECT COUNT(*) as total_purchases FROM bills WHERE phone_no = 
                    (SELECT phone_no FROM customers WHERE customer_id = $customer_id)";
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
    $update_sql = "UPDATE customers SET membership_type = '$membership_type' WHERE customer_id = $customer_id";
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
    
    // Check if phone number already exists
    $check_sql = "SELECT * FROM customers WHERE phone_no = '$phone_no'";
    $check_result = $conn->query($check_sql);
    
    if ($check_result->num_rows > 0) {
        echo "<script>alert('Customer with this phone number already exists!');</script>";
    } else {
        $insert_sql = "INSERT INTO customers (name, phone_no, nic_no, email, membership_type, total_purchases) 
                      VALUES ('$name', '$phone_no', " . ($nic_no ? "'$nic_no'" : "NULL") . ", 
                      " . ($email ? "'$email'" : "NULL") . ", '$membership_type', 0)";
        if ($conn->query($insert_sql)) {
            echo "<script>alert('Customer added successfully!'); window.location.href='customer.php';</script>";
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
    
    $update_sql = "UPDATE customers SET name='$name', phone_no='$phone_no', 
                  nic_no=" . ($nic_no ? "'$nic_no'" : "NULL") . ", 
                  email=" . ($email ? "'$email'" : "NULL") . ", 
                  membership_type='$membership_type' WHERE customer_id=$customer_id";
    if ($conn->query($update_sql)) {
        echo "<script>alert('Customer updated successfully!'); window.location.href='customer.php';</script>";
    } else {
        echo "<script>alert('Error updating customer: " . addslashes($conn->error) . "');</script>";
    }
}

// Handle delete customer
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_customer'])) {
    $customer_id = intval($_POST['customer_id']);
    
    $delete_sql = "DELETE FROM customers WHERE customer_id=$customer_id";
    if ($conn->query($delete_sql)) {
        echo "<script>alert('Customer deleted successfully!'); window.location.href='customer.php';</script>";
    } else {
        echo "<script>alert('Error deleting customer: " . addslashes($conn->error) . "');</script>";
    }
}

// Handle search
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$customers_sql = "SELECT c.*, 
                  (SELECT COUNT(*) FROM bills b WHERE b.phone_no = c.phone_no) as purchase_count,
                  (SELECT SUM(b.total) FROM bills b WHERE b.phone_no = c.phone_no) as total_spent,
                  (SELECT MAX(b.date) FROM bills b WHERE b.phone_no = c.phone_no) as last_purchase
                  FROM customers c WHERE 1=1";
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

// Handle logout
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['logout'])) {
    unset($_SESSION['cashier_id']);
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
    <title>KIDS Berry - Customer Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #5a3d7e;
            --secondary: #28a745;
            --accent: #dc3545;
            --success: #28a745;
            --warning: #ffc107;
            --light: #f8f9fa;
            --dark: #343a40;
            --text: #212529;
            --card-shadow: 0 6px 12px rgba(0,0,0,0.15), 0 2px 6px rgba(0,0,0,0.1);
            --transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            --radius: 15px;
            --gradient-primary: linear-gradient(135deg, #5a3d7e 0%, #8e44ad 100%);
            --gradient-secondary: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            --gradient-accent: linear-gradient(135deg, #dc3545 0%, #ff6b6b 100%);
            --gradient-success: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            --gradient-warning: linear-gradient(135deg, #ffc107 0%, #ffd54f 100%);
            --platinum: #e5e4e2;
            --gold: #ffd700;
            --silver: #c0c0c0;
            --regular: #6c757d;
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
            display: flex;
            flex-direction: column;
            gap: 30px;
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

        .add-btn {
            background: var(--gradient-success);
            color: white;
            padding: 12px 25px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.2);
        }

        .add-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 15px rgba(40, 167, 69, 0.3);
        }

        .search-container {
            position: relative;
            margin-bottom: 25px;
        }

        .search-input {
            width: 100%;
            padding: 16px 20px;
            padding-left: 50px;
            border: 2px solid #e0e0e0;
            border-radius: var(--radius);
            font-size: 16px;
            transition: var(--transition);
            background: #fafafa;
            font-weight: 500;
        }

        .search-input:focus {
            border-color: var(--secondary);
            box-shadow: 0 0 0 4px rgba(40, 167, 69, 0.15);
            outline: none;
            background: white;
            transform: translateY(-2px);
        }

        .search-icon {
            position: absolute;
            left: 20px;
            top: 16px;
            color: var(--primary);
            font-size: 18px;
        }

        /* Enhanced Table Styling */
        .table-container {
            overflow-x: auto;
            border-radius: var(--radius);
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
            border: 1px solid rgba(0,0,0,0.05);
            background: white;
            position: relative;
        }

        .customers-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: white;
            min-width: 1200px;
        }

        .customers-table th {
            background: var(--gradient-primary);
            color: white;
            padding: 18px 15px;
            text-align: left;
            font-weight: 700;
            position: sticky;
            top: 0;
            font-size: 16px;
            white-space: nowrap;
            border-bottom: 2px solid rgba(255,255,255,0.2);
        }

        .customers-table th:first-child {
            border-top-left-radius: var(--radius);
        }

        .customers-table th:last-child {
            border-top-right-radius: var(--radius);
        }

        .customers-table tr {
            transition: var(--transition);
            animation: fadeIn 0.5s ease;
        }

        .customers-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        .customers-table tr:hover {
            background-color: #e8f4fc;
            transform: translateX(5px);
        }

        .customers-table td {
            padding: 16px 15px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
            font-size: 15px;
        }

        .customers-table tr:last-child td {
            border-bottom: none;
        }

        .customer-name {
            font-weight: 700;
            color: var(--dark);
            font-size: 16px;
        }

        .phone-no {
            color: var(--secondary);
            font-weight: 600;
        }

        .nic-no {
            color: #7f8c8d;
            font-weight: 500;
        }

        .email {
            color: var(--primary);
            font-weight: 500;
        }

        .membership-badge {
            display: inline-block;
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .membership-platinum {
            background: linear-gradient(135deg, var(--platinum) 0%, #b8b8b8 100%);
            color: #000;
            border: 1px solid #d4d4d4;
        }

        .membership-gold {
            background: linear-gradient(135deg, var(--gold) 0%, #daa520 100%);
            color: #000;
            border: 1px solid #ffc107;
        }

        .membership-silver {
            background: linear-gradient(135deg, var(--silver) 0%, #a9a9a9 100%);
            color: #000;
            border: 1px solid #c0c0c0;
        }

        .membership-regular {
            background: linear-gradient(135deg, var(--regular) 0%, #495057 100%);
            color: white;
            border: 1px solid #6c757d;
        }

        .stats-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 12px;
            background: rgba(0,0,0,0.1);
            margin-left: 8px;
            font-weight: 600;
        }

        .purchase-count {
            color: var(--success);
            font-weight: 800;
            font-size: 16px;
            text-align: center;
        }

        .total-spent {
            color: var(--primary);
            font-weight: 800;
            font-size: 16px;
            text-align: center;
        }

        .created-at {
            color: #6c757d;
            font-size: 14px;
            font-weight: 500;
        }

        .timezone-indicator {
            font-size: 11px;
            color: #6c757d;
            display: block;
            margin-top: 2px;
        }

        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .action-btn {
            background: var(--gradient-secondary);
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 8px;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            font-size: 14px;
            box-shadow: 0 3px 6px rgba(40, 167, 69, 0.2);
        }

        .action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 12px rgba(40, 167, 69, 0.3);
        }

        .delete-btn {
            background: var(--gradient-accent);
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 8px;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            font-size: 14px;
            box-shadow: 0 3px 6px rgba(220, 53, 69, 0.2);
        }

        .delete-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 12px rgba(220, 53, 69, 0.3);
        }

        /* History Modal Styling - Fixed */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.7);
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: white;
            padding: 30px;
            border-radius: var(--radius);
            box-shadow: var(--card-shadow);
            width: 90%;
            max-width: 900px;
            position: relative;
            animation: fadeInUp 0.3s ease;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
            border: 1px solid rgba(0,0,0,0.05);
        }

        .modal-content h3 {
            margin-top: 0;
            color: var(--primary);
            font-size: 24px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-content .close {
            position: absolute;
            right: 20px;
            top: 20px;
            font-size: 28px;
            cursor: pointer;
            color: var(--text);
            transition: var(--transition);
            background: none;
            border: none;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .modal-content .close:hover {
            color: var(--accent);
            background: rgba(0,0,0,0.05);
        }

        .modal-content .form-group {
            margin-bottom: 20px;
        }

        .modal-content label {
            display: block;
            margin-bottom: 8px;
            font-weight: 700;
            font-size: 16px;
            color: var(--dark);
        }

        .modal-content input,
        .modal-content select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            transition: var(--transition);
            font-weight: 500;
        }

        .modal-content input:focus,
        .modal-content select:focus {
            border-color: var(--secondary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.2);
        }

        .modal-content button {
            width: 100%;
            padding: 15px;
            background: var(--gradient-success);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 700;
            font-size: 16px;
            transition: var(--transition);
            margin-top: 10px;
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.2);
        }

        .modal-content button:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 15px rgba(40, 167, 69, 0.3);
        }

        /* History Table Styling - Fixed Alignment */
        .history-table-container {
            overflow-y: auto;
            max-height: 50vh;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            margin-top: 20px;
            background: white;
        }

        .history-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        .history-table th {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            color: var(--dark);
            padding: 15px;
            text-align: left;
            font-weight: 700;
            font-size: 15px;
            border-bottom: 2px solid var(--primary);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .history-table td {
            padding: 14px 15px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
            font-size: 14px;
        }

        .history-table tr:hover {
            background-color: #f8f9fa;
        }

        .history-table tr:last-child td {
            border-bottom: none;
        }

        .bill-no {
            color: var(--primary);
            font-weight: 700;
            font-family: 'Courier New', monospace;
        }

        .history-date {
            color: var(--dark);
            font-weight: 500;
        }

        .history-total {
            color: var(--success);
            font-weight: 700;
        }

        .payment-method-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .payment-cash {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success);
            border: 1px solid var(--success);
        }

        .payment-card {
            background: rgba(0, 123, 255, 0.1);
            color: #007bff;
            border: 1px solid #007bff;
        }

        .payment-credit {
            background: rgba(255, 193, 7, 0.1);
            color: var(--warning);
            border: 1px solid var(--warning);
        }

        .no-records {
            text-align: center;
            padding: 60px;
            color: #7f8c8d;
            background: #f8f9fa;
            border-radius: var(--radius);
            border: 2px dashed #dee2e6;
        }

        .no-records i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
            color: var(--primary);
        }

        .no-records h3 {
            margin-bottom: 10px;
            font-size: 24px;
            color: var(--dark);
        }

        .no-records p {
            font-size: 16px;
            max-width: 400px;
            margin: 0 auto;
        }

        @keyframes slideDown {
            from { transform: translateY(-100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
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

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .main-container {
                padding: 0 20px;
            }
        }

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
                gap: 20px;
            }
            
            .card {
                padding: 20px;
            }
            
            .action-buttons {
                flex-direction: column;
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
            
            .card-title {
                font-size: 20px;
            }
            
            .add-btn {
                padding: 10px 15px;
                font-size: 14px;
            }
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
            background: #f8f9fa;
            border-radius: var(--radius);
            border: 2px dashed #dee2e6;
        }

        .empty-state i {
            font-size: 60px;
            margin-bottom: 20px;
            color: var(--primary);
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 22px;
            margin-bottom: 10px;
            color: var(--dark);
        }

        .empty-state p {
            font-size: 15px;
            max-width: 400px;
            margin: 0 auto;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo-container">
            <img src="../logo.jpg" alt="Kids Berry Logo">
        </div>
        <div class="header-content">
            <h1><i class="fas fa-users"></i> KIDS Berry - Customer Management</h1>
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
        <a href="customer.php" class="active"><i class="fas fa-users"></i> Customers</a>
                <a href="bill_management.php" class=""><i class="fas fa-history"></i> Bill History</a>
        <a href="report.php"><i class="fas fa-chart-line"></i> Reports</a>
        <a href="credit_payments.php"><i class="fas fa-credit-card"></i> Credit Customers</a>
        <a href="return_sale.php"><i class="fas fa-undo-alt"></i> Manage Returns</a>
                <!--<a href="prediction_dashboard.php" class=""><i class="fas fa-bullseye"></i> Predictions</a>-->

    </div>

    <div class="main-container">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-user-friends"></i> Customer Management</h2>
                <button class="add-btn" onclick="showAddModal()">
                    <i class="fas fa-plus"></i> Add Customer
                </button>
            </div>
            
            <div class="search-container">
                <i class="fas fa-search search-icon"></i>
                <input type="text" class="search-input" id="search-input" placeholder="Search customers by name, phone, NIC, or email..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            
            <div class="table-container">
                <?php if ($customers_result->num_rows > 0): ?>
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
                                // Get purchase history for this customer
                                $history_sql = "SELECT bill_no, date, total, payment_method 
                                              FROM bills 
                                              WHERE phone_no = '{$row['phone_no']}' 
                                              ORDER BY date DESC 
                                              LIMIT 5";
                                $history_result = $conn->query($history_sql);
                                
                                // Update membership based on purchases
                                $membership_info = updateCustomerMembership($conn, $row['customer_id']);
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
                                        <?php if($last_purchase_display != 'Never'): ?>
                                            <!--<span class="timezone-indicator">Colombo Time</span>-->
                                        <?php endif; ?>
                                    </td>
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
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <h3>No customers found</h3>
                        <p>No customers match your search criteria</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <button class="close" onclick="closeAddModal()">&times;</button>
            <h3>Add New Customer</h3>
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
                <button type="submit" name="add_customer">Add Customer</button>
            </form>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <button class="close" onclick="closeEditModal()">&times;</button>
            <h3>Edit Customer</h3>
            <form method="post">
                <input type="hidden" id="edit-customer_id" name="customer_id">
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
                <button type="submit" name="update_customer">Update Customer</button>
            </form>
        </div>
    </div>

    <!-- History Modal -->
    <div id="historyModal" class="modal">
        <div class="modal-content">
            <button class="close" onclick="closeHistoryModal()">&times;</button>
            <h3 id="history-modal-title"><i class="fas fa-history"></i> Purchase History</h3>
            <div id="history-content" class="history-table-container">
                <!-- History will be loaded via AJAX -->
            </div>
        </div>
    </div>

    <script>
        // Update time in real-time (Colombo time)
        function updateClock() {
            const now = new Date();
            
            // Convert to Colombo time (UTC+5:30)
            const colomboTime = new Date(now.getTime() + (5.5 * 60 * 60 * 1000));
            
            const options = { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric',
                timeZone: 'Asia/Colombo'
            };
            
            document.getElementById('current-date').textContent = colomboTime.toLocaleDateString('en-US', options);
            
            let hours = colomboTime.getUTCHours();
            let minutes = colomboTime.getUTCMinutes();
            let seconds = colomboTime.getUTCSeconds();
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12 || 12;
            minutes = minutes < 10 ? '0' + minutes : minutes;
            seconds = seconds < 10 ? '0' + seconds : seconds;
            const timeString = `${hours}:${minutes}:${seconds} ${ampm}`;
            document.getElementById('current-time').textContent = timeString;
            
            setTimeout(updateClock, 1000);
        }

        updateClock();

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

        // Modal functions for History - Fixed with proper table styling
        function showHistoryModal(customerName, phoneNo) {
            document.getElementById('history-modal-title').textContent = `Purchase History for ${customerName}`;
            
            // Load history via AJAX with Colombo time conversion
            fetch('get_customer_history.php?phone_no=' + encodeURIComponent(phoneNo))
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
                        // Wrap the data in proper table structure
                        historyContent.innerHTML = `
                            <table class="history-table">
                                <thead>
                                    <tr>
                                        <th>Bill No</th>
                                        <th>Date & Time</th>
                                        <th>Total Amount</th>
                                        <th>Payment Method</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${data}
                                </tbody>
                            </table>
                        `;
                    }
                    
                    document.getElementById('historyModal').style.display = 'flex';
                })
                .catch(error => {
                    console.error('Error loading history:', error);
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
            // Clear history content when closing
            document.getElementById('history-content').innerHTML = '';
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const addModal = document.getElementById('addModal');
            const editModal = document.getElementById('editModal');
            const historyModal = document.getElementById('historyModal');
            
            if (event.target == addModal) closeAddModal();
            if (event.target == editModal) closeEditModal();
            if (event.target == historyModal) closeHistoryModal();
        }

        // Add animations to table rows
        document.addEventListener('DOMContentLoaded', function() {
            const tableRows = document.querySelectorAll('.customers-table tbody tr');
            tableRows.forEach((row, index) => {
                row.style.animationDelay = `${index * 0.05}s`;
            });
        });

        // Handle Escape key to close modals
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeAddModal();
                closeEditModal();
                closeHistoryModal();
            }
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>