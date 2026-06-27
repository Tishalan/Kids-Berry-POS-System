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

// Initialize variables
$message = "";
$message_type = ""; // success, error

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Add new cashier
    if (isset($_POST['add_cashier'])) {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $phone_no = trim($_POST['phone_no']);
        $address = trim($_POST['address']);
        $branch = $_POST['branch'];
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        
        // Determine which table to use based on branch selection
        $table_name = ($branch == 'branch1') ? 'cashier' : 'cashier2';
        
        // Check if email already exists in the selected table
        $check_sql = "SELECT * FROM $table_name WHERE email = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $email);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        if ($result->num_rows > 0) {
            $message = "A cashier with this email already exists in " . ($branch == 'branch1' ? 'Branch 1' : 'Branch 2') . "!";
            $message_type = "error";
        } else {
            $insert_sql = "INSERT INTO $table_name (name, email, phone_no, address, password) VALUES (?, ?, ?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("sssss", $name, $email, $phone_no, $address, $password);
            
            if ($insert_stmt->execute()) {
                $message = "Cashier added successfully to " . ($branch == 'branch1' ? 'Branch 1' : 'Branch 2') . "!";
                $message_type = "success";
            } else {
                $message = "Error adding cashier: " . $conn->error;
                $message_type = "error";
            }
        }
    }
    
    // Update cashier
    if (isset($_POST['update_cashier'])) {
        $cashiers_id = $_POST['cashiers_id'];
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $phone_no = trim($_POST['phone_no']);
        $address = trim($_POST['address']);
        $branch = $_POST['branch'];
        
        // Determine which table to use based on branch selection
        $table_name = ($branch == 'branch1') ? 'cashier' : 'cashier2';
        
        // Check if email already exists for another cashier in the selected table
        $check_sql = "SELECT * FROM $table_name WHERE email = ? AND cashiers_id != ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("si", $email, $cashiers_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        if ($result->num_rows > 0) {
            $message = "A cashier with this email already exists in " . ($branch == 'branch1' ? 'Branch 1' : 'Branch 2') . "!";
            $message_type = "error";
        } else {
            // If password is provided, update it too
            if (!empty($_POST['password'])) {
                $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $update_sql = "UPDATE $table_name SET name = ?, email = ?, phone_no = ?, address = ?, password = ? WHERE cashiers_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("sssssi", $name, $email, $phone_no, $address, $password, $cashiers_id);
            } else {
                $update_sql = "UPDATE $table_name SET name = ?, email = ?, phone_no = ?, address = ? WHERE cashiers_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("ssssi", $name, $email, $phone_no, $address, $cashiers_id);
            }
            
            if ($update_stmt->execute()) {
                $message = "Cashier updated successfully in " . ($branch == 'branch1' ? 'Branch 1' : 'Branch 2') . "!";
                $message_type = "success";
            } else {
                $message = "Error updating cashier: " . $conn->error;
                $message_type = "error";
            }
        }
    }
    
    // Toggle cashier status
    if (isset($_POST['toggle_status'])) {
        $cashiers_id = $_POST['cashiers_id'];
        $current_status = $_POST['current_status'];
        $branch = $_POST['branch'];
        $new_status = ($current_status == 'active') ? 'inactive' : 'active';
        
        // Determine which table to use based on branch selection
        $table_name = ($branch == 'branch1') ? 'cashier' : 'cashier2';
        
        $update_sql = "UPDATE $table_name SET status = ? WHERE cashiers_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("si", $new_status, $cashiers_id);
        
        if ($update_stmt->execute()) {
            $message = "Cashier status updated successfully in " . ($branch == 'branch1' ? 'Branch 1' : 'Branch 2') . "!";
            $message_type = "success";
        } else {
            $message = "Error updating cashier status: " . $conn->error;
            $message_type = "error";
        }
    }
    
    // Delete cashier
    if (isset($_POST['delete_cashier'])) {
        $cashiers_id = $_POST['cashiers_id'];
        $branch = $_POST['branch'];
        
        // Determine which table to use based on branch selection
        $table_name = ($branch == 'branch1') ? 'cashier' : 'cashier2';
        
        $delete_sql = "DELETE FROM $table_name WHERE cashiers_id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $cashiers_id);
        
        if ($delete_stmt->execute()) {
            $message = "Cashier deleted successfully from " . ($branch == 'branch1' ? 'Branch 1' : 'Branch 2') . "!";
            $message_type = "success";
        } else {
            $message = "Error deleting cashier: " . $conn->error;
            $message_type = "error";
        }
    }
    
    // Get cashiers by branch
    if (isset($_POST['view_branch'])) {
        $selected_branch = $_POST['selected_branch'];
        $_SESSION['selected_branch'] = $selected_branch;
    }
}

// Get selected branch from session or default to branch1
$selected_branch = isset($_SESSION['selected_branch']) ? $_SESSION['selected_branch'] : 'branch1';

// Get cashiers from the selected branch table
$table_name = ($selected_branch == 'branch1') ? 'cashier' : 'cashier2';
$cashiers = $conn->query("SELECT * FROM $table_name ORDER BY created_at DESC");

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Kids Berry - Cashier Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-purple: #6a0dad;
            --light-purple: #8a2be2;
            --dark-purple: #4b0082;
            --light-green: #90ee90;
            --medium-green: #32cd32;
            --dark-green: #228b22;
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

        /* Message Styles */
        .message {
            padding: 12px 15px;
            border-radius: var(--border-radius-small);
            margin-bottom: 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            animation: fadeIn 0.5s ease;
        }

        .message.success {
            background-color: #d4edda;
            color: #155724;
            border-left: 5px solid var(--medium-green);
        }

        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border-left: 5px solid #dc3545;
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

        /* Content Layout - Mobile Stack */
        .content-layout {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        /* Card Styles */
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

        .badge {
            background: var(--gradient-primary);
            color: var(--white);
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 15px;
        }

        .form-label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: var(--dark-gray);
        }

        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid var(--medium-gray);
            border-radius: var(--border-radius-small);
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .form-control:focus {
            border-color: var(--primary-purple);
            outline: none;
            box-shadow: 0 0 0 3px rgba(106, 13, 173, 0.2);
        }

        .btn {
            padding: 10px 15px;
            border: none;
            border-radius: var(--border-radius-small);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: var(--white);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-medium);
        }

        .btn-secondary {
            background: var(--gradient-secondary);
            color: var(--white);
        }

        .btn-secondary:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-medium);
        }

        .btn-danger {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: var(--white);
        }

        .btn-danger:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(220, 53, 69, 0.3);
        }

        .btn-sm {
            padding: 6px 10px;
            font-size: 0.8rem;
        }

        /* Table Styles */
        .table-container {
            overflow-x: auto;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-light);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            background: var(--white);
            min-width: 600px;
        }

        .table th, .table td {
            padding: 12px 10px;
            text-align: left;
            border-bottom: 1px solid var(--medium-gray);
            font-size: 0.85rem;
        }

        .table th {
            background: var(--gradient-primary);
            color: var(--white);
            font-weight: 600;
        }

        .table tr:last-child td {
            border-bottom: none;
        }

        .table tr:hover {
            background-color: var(--light-gray);
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-active {
            background-color: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background-color: #f8d7da;
            color: #721c24;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1100;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
            padding: 15px;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius);
            width: 100%;
            max-width: 500px;
            box-shadow: var(--shadow-heavy);
            animation: slideIn 0.3s ease;
            overflow: hidden;
        }

        .modal-header {
            padding: 15px 20px;
            background: var(--gradient-primary);
            color: var(--white);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 1.2rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .close-btn {
            background: none;
            border: none;
            color: var(--white);
            font-size: 1.3rem;
            cursor: pointer;
            transition: var(--transition);
        }

        .close-btn:hover {
            transform: scale(1.2);
        }

        .modal-body {
            padding: 20px;
            max-height: 70vh;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 12px 20px;
            background: var(--light-gray);
            display: flex;
            justify-content: flex-end;
            gap: 8px;
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
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
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
            
            .content-layout {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
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
            
            .message {
                padding: 15px 20px;
            }
            
            .form-control {
                padding: 12px 15px;
            }
            
            .btn {
                padding: 12px 20px;
            }
            
            .table th, .table td {
                padding: 15px;
                font-size: 0.9rem;
            }
            
            .action-buttons {
                gap: 8px;
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
            
            .content-layout {
                gap: 25px;
            }
            
            .card {
                padding: 25px;
            }
        }

        /* Small Mobile Optimization */
        @media (max-width: 360px) {
            .header {
                flex-direction: column;
                text-align: center;
            }
            
            .header h1 {
                font-size: 1.2rem;
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
            
            .action-buttons {
                flex-direction: column;
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
                <li><a href="customer_manage.php"><i class="fas fa-users"></i> Customer Management</a></li>
                <li><a href="stockkeeper_manage.php"><i class="fas fa-user-tie"></i> Stock Keeper Management</a></li>
                <li><a href="cashier_manage.php"><i class="fas fa-user-tie"></i> Sales Officer Management</a></li>
                <li><a href="sales_manage.php" class="active"><i class="fas fa-cash-register"></i> Cashier Management</a></li>
                <li><a href="product_manage.php"><i class="fas fa-box"></i> Product Management</a></li>
                <li><a href="suppliers_manage.php"><i class="fas fa-truck"></i> Suppliers Management</a></li>
                <li><a href="report_show.php"><i class="fas fa-chart-line"></i> Reports</a></li>
                <li><a href="admin_contact_management.php"><i class="fas fa-headset"></i> Contact Requests</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <div class="header">
                <h1><i class="fas fa-cash-register"></i> Cashier Management</h1>
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

            <!-- Message Display -->
            <?php if (!empty($message)): ?>
                <div class="message <?php echo $message_type; ?>">
                    <i class="fas <?php echo $message_type == 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <!-- Branch Selection -->
            <div class="branch-selector">
                <div class="branch-label">
                    <i class="fas fa-store"></i> Select Branch:
                </div>
                <form method="post" action="" id="branchForm">
                    <div class="branch-radio-group">
                        <label class="branch-option">
                            <input type="radio" name="selected_branch" value="branch1" <?php echo ($selected_branch == 'branch1') ? 'checked' : ''; ?> onchange="this.form.submit()">
                            Branch 1 <span class="branch-badge" style="background: linear-gradient(135deg, #6a0dad, #4b0082);">Cashier Table</span>
                        </label>
                        <label class="branch-option">
                            <input type="radio" name="selected_branch" value="branch2" <?php echo ($selected_branch == 'branch2') ? 'checked' : ''; ?> onchange="this.form.submit()">
                            Branch 2 <span class="branch-badge" style="background: linear-gradient(135deg, #228b22, #32cd32);">Cashier2 Table</span>
                        </label>
                    </div>
                    <input type="hidden" name="view_branch" value="1">
                </form>
                <div style="margin-left: auto;">
                    <span class="branch-badge">
                        <i class="fas fa-database"></i> Currently viewing: <?php echo ($selected_branch == 'branch1') ? 'Branch 1 (cashier)' : 'Branch 2 (cashier2)'; ?>
                    </span>
                </div>
            </div>

            <!-- Content Layout -->
            <div class="content-layout">
                <!-- Add Cashier Form -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title"><i class="fas fa-user-plus"></i> Add New Cashier</h2>
                        <span class="badge"><?php echo ($selected_branch == 'branch1') ? 'Branch 1' : 'Branch 2'; ?></span>
                    </div>
                    <form method="post" action="">
                        <div class="form-group">
                            <label class="form-label" for="name">Full Name</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="email">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="phone_no">Phone Number</label>
                            <input type="text" class="form-control" id="phone_no" name="phone_no" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="address">Address</label>
                            <textarea class="form-control" id="address" name="address" rows="3" required></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="password">Password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="branch">Save to Branch</label>
                            <select class="form-control" id="branch" name="branch" required>
                                <option value="branch1" <?php echo ($selected_branch == 'branch1') ? 'selected' : ''; ?>>Branch 1 (cashier table)</option>
                                <option value="branch2" <?php echo ($selected_branch == 'branch2') ? 'selected' : ''; ?>>Branch 2 (cashier2 table)</option>
                            </select>
                        </div>
                        <button type="submit" name="add_cashier" class="btn btn-primary">
                            <i class="fas fa-save"></i> Add Cashier
                        </button>
                    </form>
                </div>

                <!-- Cashiers List -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title"><i class="fas fa-list"></i> Cashiers List - <?php echo ($selected_branch == 'branch1') ? 'Branch 1' : 'Branch 2'; ?></h2>
                        <span class="badge"><?php echo $cashiers->num_rows; ?> Cashiers</span>
                    </div>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($cashiers && $cashiers->num_rows > 0): ?>
                                    <?php while($cashier = $cashiers->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($cashier['name']); ?></td>
                                            <td><?php echo htmlspecialchars($cashier['email']); ?></td>
                                            <td><?php echo htmlspecialchars($cashier['phone_no']); ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo $cashier['status']; ?>">
                                                    <?php echo ucfirst($cashier['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-primary btn-sm edit-cashier" 
                                                            data-id="<?php echo $cashier['cashiers_id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($cashier['name']); ?>"
                                                            data-email="<?php echo htmlspecialchars($cashier['email']); ?>"
                                                            data-phone="<?php echo htmlspecialchars($cashier['phone_no']); ?>"
                                                            data-address="<?php echo htmlspecialchars($cashier['address']); ?>"
                                                            data-status="<?php echo $cashier['status']; ?>"
                                                            data-branch="<?php echo $selected_branch; ?>">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                    <form method="post" style="display:inline;">
                                                        <input type="hidden" name="cashiers_id" value="<?php echo $cashier['cashiers_id']; ?>">
                                                        <input type="hidden" name="current_status" value="<?php echo $cashier['status']; ?>">
                                                        <input type="hidden" name="branch" value="<?php echo $selected_branch; ?>">
                                                        <button type="submit" name="toggle_status" class="btn btn-secondary btn-sm">
                                                            <i class="fas fa-toggle-<?php echo $cashier['status'] == 'active' ? 'on' : 'off'; ?>"></i> 
                                                            <?php echo $cashier['status'] == 'active' ? 'Deactivate' : 'Activate'; ?>
                                                        </button>
                                                    </form>
                                                    <button class="btn btn-danger btn-sm delete-cashier" 
                                                            data-id="<?php echo $cashier['cashiers_id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($cashier['name']); ?>"
                                                            data-branch="<?php echo $selected_branch; ?>">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center;">No cashiers found in <?php echo ($selected_branch == 'branch1') ? 'Branch 1' : 'Branch 2'; ?>. Add your first cashier!</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Cashier Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-edit"></i> Edit Cashier</h3>
                <button class="close-btn" id="closeEditModal">&times;</button>
            </div>
            <form method="post" action="">
                <div class="modal-body">
                    <input type="hidden" name="cashiers_id" id="edit_cashiers_id">
                    <input type="hidden" name="branch" id="edit_branch" value="<?php echo $selected_branch; ?>">
                    <div class="form-group">
                        <label class="form-label" for="edit_name">Full Name</label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_email">Email Address</label>
                        <input type="email" class="form-control" id="edit_email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_phone_no">Phone Number</label>
                        <input type="text" class="form-control" id="edit_phone_no" name="phone_no" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_address">Address</label>
                        <textarea class="form-control" id="edit_address" name="address" rows="3" required></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_password">New Password (Leave blank to keep current)</label>
                        <input type="password" class="form-control" id="edit_password" name="password">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_branch_select">Branch</label>
                        <select class="form-control" id="edit_branch_select" name="branch" required>
                            <option value="branch1">Branch 1 (cashier table)</option>
                            <option value="branch2">Branch 2 (cashier2 table)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancelEdit">Cancel</button>
                    <button type="submit" name="update_cashier" class="btn btn-primary">Update Cashier</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Confirm Deletion</h3>
                <button class="close-btn" id="closeDeleteModal">&times;</button>
            </div>
            <form method="post" action="">
                <div class="modal-body">
                    <input type="hidden" name="cashiers_id" id="delete_cashiers_id">
                    <input type="hidden" name="branch" id="delete_branch">
                    <p>Are you sure you want to delete cashier: <strong id="delete_cashier_name"></strong>?</p>
                    <p class="text-danger">This action cannot be undone!</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancelDelete">Cancel</button>
                    <button type="submit" name="delete_cashier" class="btn btn-danger">Delete Cashier</button>
                </div>
            </form>
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

        // Edit Cashier Modal
        const editButtons = document.querySelectorAll('.edit-cashier');
        const editModal = document.getElementById('editModal');
        const closeEditModal = document.getElementById('closeEditModal');
        const cancelEdit = document.getElementById('cancelEdit');

        editButtons.forEach(button => {
            button.addEventListener('click', function() {
                const cashiersId = this.getAttribute('data-id');
                const name = this.getAttribute('data-name');
                const email = this.getAttribute('data-email');
                const phone = this.getAttribute('data-phone');
                const address = this.getAttribute('data-address');
                const branch = this.getAttribute('data-branch');
                
                document.getElementById('edit_cashiers_id').value = cashiersId;
                document.getElementById('edit_name').value = name;
                document.getElementById('edit_email').value = email;
                document.getElementById('edit_phone_no').value = phone;
                document.getElementById('edit_address').value = address;
                document.getElementById('edit_branch').value = branch;
                document.getElementById('edit_branch_select').value = branch;
                
                editModal.style.display = 'flex';
            });
        });

        closeEditModal.addEventListener('click', function() {
            editModal.style.display = 'none';
        });

        cancelEdit.addEventListener('click', function() {
            editModal.style.display = 'none';
        });

        // Delete Cashier Modal
        const deleteButtons = document.querySelectorAll('.delete-cashier');
        const deleteModal = document.getElementById('deleteModal');
        const closeDeleteModal = document.getElementById('closeDeleteModal');
        const cancelDelete = document.getElementById('cancelDelete');

        deleteButtons.forEach(button => {
            button.addEventListener('click', function() {
                const cashiersId = this.getAttribute('data-id');
                const name = this.getAttribute('data-name');
                const branch = this.getAttribute('data-branch');
                
                document.getElementById('delete_cashiers_id').value = cashiersId;
                document.getElementById('delete_cashier_name').textContent = name;
                document.getElementById('delete_branch').value = branch;
                
                deleteModal.style.display = 'flex';
            });
        });

        closeDeleteModal.addEventListener('click', function() {
            deleteModal.style.display = 'none';
        });

        cancelDelete.addEventListener('click', function() {
            deleteModal.style.display = 'none';
        });

        // Close modals when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target === editModal) {
                editModal.style.display = 'none';
            }
            if (event.target === deleteModal) {
                deleteModal.style.display = 'none';
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

            const animatedElements = document.querySelectorAll('.card');
            animatedElements.forEach(el => {
                el.style.opacity = 0;
                el.style.transform = 'translateY(15px)';
                el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                observer.observe(el);
            });

            // Show success message with animation
            <?php if (!empty($message) && $message_type == 'success'): ?>
                setTimeout(() => {
                    showToast('<?php echo $message; ?>');
                }, 500);
            <?php endif; ?>
        });

        // Add ripple effect to buttons
        document.querySelectorAll('.btn').forEach(button => {
            button.addEventListener('click', function(e) {
                const ripple = document.createElement('span');
                const rect = button.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left - size / 2;
                const y = e.clientY - rect.top - size / 2;
                
                ripple.style.width = ripple.style.height = size + 'px';
                ripple.style.left = x + 'px';
                ripple.style.top = y + 'px';
                ripple.classList.add('ripple');
                
                button.appendChild(ripple);
                
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