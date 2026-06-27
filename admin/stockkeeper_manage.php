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

// Handle form submissions
$message = "";
$message_type = "";

// Add new stock keeper
if (isset($_POST['add_stock_keeper'])) {
    $name = $conn->real_escape_string($_POST['name']);
    $email = $conn->real_escape_string($_POST['email']);
    $phone_no = $conn->real_escape_string($_POST['phone_no']);
    $address = $conn->real_escape_string($_POST['address']);
    $branch = $_POST['branch'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    
    // Determine which table to use based on branch selection
    $table_name = ($branch == 'branch1') ? 'stock_keeper_users' : 'stock_keeper_users2';
    
    // Check if email already exists in the selected table
    $check_email = $conn->query("SELECT * FROM $table_name WHERE email = '$email'");
    if ($check_email->num_rows > 0) {
        $message = "Email already exists in " . ($branch == 'branch1' ? 'Branch 1' : 'Branch 2') . "!";
        $message_type = "error";
    } else {
        $sql = "INSERT INTO $table_name (name, email, phone_no, address, password) 
                VALUES ('$name', '$email', '$phone_no', '$address', '$password')";
        
        if ($conn->query($sql) === TRUE) {
            $message = "Stock keeper added successfully to " . ($branch == 'branch1' ? 'Branch 1' : 'Branch 2') . "!";
            $message_type = "success";
        } else {
            $message = "Error adding stock keeper: " . $conn->error;
            $message_type = "error";
        }
    }
}

// Update stock keeper
if (isset($_POST['update_stock_keeper'])) {
    $stock_keeper_id = $conn->real_escape_string($_POST['stock_keeper_id']);
    $name = $conn->real_escape_string($_POST['name']);
    $email = $conn->real_escape_string($_POST['email']);
    $phone_no = $conn->real_escape_string($_POST['phone_no']);
    $address = $conn->real_escape_string($_POST['address']);
    $status = $conn->real_escape_string($_POST['status']);
    $branch = $_POST['branch'];
    
    // Determine which table to use based on branch selection
    $table_name = ($branch == 'branch1') ? 'stock_keeper_users' : 'stock_keeper_users2';
    
    // Check if email already exists for another user in the selected table
    $check_email = $conn->query("SELECT * FROM $table_name WHERE email = '$email' AND stock_keeper_id != '$stock_keeper_id'");
    if ($check_email->num_rows > 0) {
        $message = "Email already exists for another user in " . ($branch == 'branch1' ? 'Branch 1' : 'Branch 2') . "!";
        $message_type = "error";
    } else {
        $sql = "UPDATE $table_name SET 
                name = '$name', 
                email = '$email', 
                phone_no = '$phone_no', 
                address = '$address', 
                status = '$status' 
                WHERE stock_keeper_id = '$stock_keeper_id'";
        
        if ($conn->query($sql) === TRUE) {
            $message = "Stock keeper updated successfully in " . ($branch == 'branch1' ? 'Branch 1' : 'Branch 2') . "!";
            $message_type = "success";
        } else {
            $message = "Error updating stock keeper: " . $conn->error;
            $message_type = "error";
        }
    }
}

// Update password
if (isset($_POST['update_password'])) {
    $stock_keeper_id = $conn->real_escape_string($_POST['stock_keeper_id']);
    $branch = $_POST['branch'];
    $new_password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
    
    // Determine which table to use based on branch selection
    $table_name = ($branch == 'branch1') ? 'stock_keeper_users' : 'stock_keeper_users2';
    
    $sql = "UPDATE $table_name SET password = '$new_password' WHERE stock_keeper_id = '$stock_keeper_id'";
    
    if ($conn->query($sql) === TRUE) {
        $message = "Password updated successfully in " . ($branch == 'branch1' ? 'Branch 1' : 'Branch 2') . "!";
        $message_type = "success";
    } else {
        $message = "Error updating password: " . $conn->error;
        $message_type = "error";
    }
}

// Delete stock keeper
if (isset($_POST['delete_stock_keeper'])) {
    $stock_keeper_id = $conn->real_escape_string($_POST['stock_keeper_id']);
    $branch = $_POST['branch'];
    
    // Determine which table to use based on branch selection
    $table_name = ($branch == 'branch1') ? 'stock_keeper_users' : 'stock_keeper_users2';
    
    $sql = "DELETE FROM $table_name WHERE stock_keeper_id = '$stock_keeper_id'";
    
    if ($conn->query($sql) === TRUE) {
        $message = "Stock keeper deleted successfully from " . ($branch == 'branch1' ? 'Branch 1' : 'Branch 2') . "!";
        $message_type = "success";
    } else {
        $message = "Error deleting stock keeper: " . $conn->error;
        $message_type = "error";
    }
}

// Get selected branch from session or default to branch1
$selected_branch = isset($_SESSION['selected_branch_stock']) ? $_SESSION['selected_branch_stock'] : 'branch1';

// Handle branch selection
if (isset($_POST['select_branch'])) {
    $selected_branch = $_POST['selected_branch'];
    $_SESSION['selected_branch_stock'] = $selected_branch;
}

// Get all stock keepers from the selected branch table
$table_name = ($selected_branch == 'branch1') ? 'stock_keeper_users' : 'stock_keeper_users2';
$stock_keepers = $conn->query("SELECT * FROM $table_name ORDER BY created_at DESC");

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Kids Berry - Stock Keeper Management</title>
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
            animation: slideIn 0.5s ease;
            font-size: 0.9rem;
        }

        .message.success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
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

        /* Form Styles */
        .form-container {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 15px;
            box-shadow: var(--shadow-light);
            margin-bottom: 20px;
            animation: fadeInUp 0.8s ease;
        }

        .form-title {
            color: var(--primary-purple);
            font-size: 1.2rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--light-gray);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
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
            box-shadow: 0 0 0 3px rgba(106, 13, 173, 0.1);
        }

        .btn {
            padding: 10px 16px;
            border: none;
            border-radius: var(--border-radius-small);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
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
            box-shadow: var(--shadow-medium);
        }

        /* Table Styles */
        .table-container {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 15px;
            box-shadow: var(--shadow-light);
            overflow: hidden;
            animation: fadeInUp 0.8s ease;
        }

        .table-title {
            color: var(--primary-purple);
            font-size: 1.2rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        .data-table th,
        .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--light-gray);
            font-size: 0.85rem;
        }

        .data-table th {
            background: var(--light-gray);
            color: var(--primary-purple);
            font-weight: 700;
        }

        .data-table tr:hover {
            background: rgba(106, 13, 173, 0.05);
        }

        .status-active {
            color: var(--dark-green);
            font-weight: 600;
        }

        .status-inactive {
            color: #dc3545;
            font-weight: 600;
        }

        .action-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 6px 10px;
            border: none;
            border-radius: var(--border-radius-small);
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .action-btn.edit {
            background: rgba(32, 201, 151, 0.1);
            color: #20c997;
        }

        .action-btn.edit:hover {
            background: #20c997;
            color: white;
        }

        .action-btn.password {
            background: rgba(13, 110, 253, 0.1);
            color: #0d6efd;
        }

        .action-btn.password:hover {
            background: #0d6efd;
            color: white;
        }

        .action-btn.delete {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        .action-btn.delete:hover {
            background: #dc3545;
            color: white;
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
            z-index: 1100;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
            padding: 15px;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius);
            width: 100%;
            max-width: 500px;
            box-shadow: var(--shadow-heavy);
            animation: slideUp 0.3s ease;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 15px 20px;
            border-bottom: 2px solid var(--light-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            color: var(--primary-purple);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.3rem;
            cursor: pointer;
            color: var(--dark-gray);
            transition: var(--transition);
        }

        .close-modal:hover {
            color: var(--primary-purple);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 20px;
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

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
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

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
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
            
            .header {
                padding: 18px 25px;
            }
            
            .header h1 {
                font-size: 1.6rem;
            }
            
            .form-container {
                padding: 20px;
            }
            
            .form-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
            }
            
            .table-container {
                padding: 20px;
            }
            
            .data-table th,
            .data-table td {
                padding: 15px;
                font-size: 0.9rem;
            }
            
            .action-buttons {
                flex-wrap: nowrap;
            }
            
            .action-btn {
                padding: 8px 12px;
                font-size: 0.9rem;
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
            
            .form-container {
                padding: 25px;
            }
            
            .form-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 25px;
            }
            
            .table-container {
                padding: 25px;
            }
            
            .data-table th,
            .data-table td {
                padding: 15px;
            }
        }

        /* Small Mobile Optimization */
        @media (max-width: 360px) {
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
                <li><a href="stockkeeper_manage.php" class="active"><i class="fas fa-user-tie"></i> Stock Keeper Management</a></li>
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
                <h1><i class="fas fa-user-tie"></i> Stock Keeper Management</h1>
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
                    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
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
                            Branch 1 <span class="branch-badge" style="background: linear-gradient(135deg, #6a0dad, #4b0082);">Stock Keepers Table</span>
                        </label>
                        <label class="branch-option">
                            <input type="radio" name="selected_branch" value="branch2" <?php echo ($selected_branch == 'branch2') ? 'checked' : ''; ?> onchange="this.form.submit()">
                            Branch 2 <span class="branch-badge" style="background: linear-gradient(135deg, #228b22, #32cd32);">Stock Keepers2 Table</span>
                        </label>
                    </div>
                    <input type="hidden" name="select_branch" value="1">
                </form>
                <div style="margin-left: auto;">
                    <span class="branch-badge">
                        <i class="fas fa-database"></i> Currently viewing: <?php echo ($selected_branch == 'branch1') ? 'Branch 1 (stock_keeper_users)' : 'Branch 2 (stock_keeper_users2)'; ?>
                    </span>
                </div>
            </div>

            <!-- Add Stock Keeper Form -->
            <div class="form-container">
                <h2 class="form-title"><i class="fas fa-user-plus"></i> Add New Stock Keeper - <?php echo ($selected_branch == 'branch1') ? 'Branch 1' : 'Branch 2'; ?></h2>
                <form method="post" action="">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="name">Full Name</label>
                            <input type="text" id="name" name="name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="phone_no">Phone Number</label>
                            <input type="text" id="phone_no" name="phone_no" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="address">Address</label>
                            <input type="text" id="address" name="address" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="password">Password</label>
                            <input type="password" id="password" name="password" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="branch">Save to Branch</label>
                            <select class="form-control" id="branch" name="branch" required>
                                <option value="branch1" <?php echo ($selected_branch == 'branch1') ? 'selected' : ''; ?>>Branch 1 (stock_keeper_users table)</option>
                                <option value="branch2" <?php echo ($selected_branch == 'branch2') ? 'selected' : ''; ?>>Branch 2 (stock_keeper_users2 table)</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" name="add_stock_keeper" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Stock Keeper
                    </button>
                </form>
            </div>

            <!-- Stock Keepers Table -->
            <div class="table-container">
                <h2 class="table-title"><i class="fas fa-list"></i> All Stock Keepers - <?php echo ($selected_branch == 'branch1') ? 'Branch 1' : 'Branch 2'; ?></h2>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($stock_keepers && $stock_keepers->num_rows > 0): ?>
                                <?php while($keeper = $stock_keepers->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo $keeper['stock_keeper_id']; ?></td>
                                        <td><?php echo htmlspecialchars($keeper['name']); ?></td>
                                        <td><?php echo htmlspecialchars($keeper['email']); ?></td>
                                        <td><?php echo htmlspecialchars($keeper['phone_no']); ?></td>
                                        <td>
                                            <span class="status-<?php echo $keeper['status']; ?>">
                                                <?php echo ucfirst($keeper['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="action-btn edit" onclick="openEditModal(<?php echo $keeper['stock_keeper_id']; ?>, '<?php echo htmlspecialchars($keeper['name']); ?>', '<?php echo htmlspecialchars($keeper['email']); ?>', '<?php echo htmlspecialchars($keeper['phone_no']); ?>', '<?php echo htmlspecialchars($keeper['address']); ?>', '<?php echo $keeper['status']; ?>', '<?php echo $selected_branch; ?>')">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <button class="action-btn password" onclick="openPasswordModal(<?php echo $keeper['stock_keeper_id']; ?>, '<?php echo htmlspecialchars($keeper['name']); ?>', '<?php echo $selected_branch; ?>')">
                                                    <i class="fas fa-key"></i> Password
                                                </button>
                                                <button class="action-btn delete" onclick="openDeleteModal(<?php echo $keeper['stock_keeper_id']; ?>, '<?php echo htmlspecialchars($keeper['name']); ?>', '<?php echo $selected_branch; ?>')">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center;">No stock keepers found in <?php echo ($selected_branch == 'branch1') ? 'Branch 1' : 'Branch 2'; ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div class="toast" id="toast">
        <i class="fas fa-info-circle"></i>
        <span id="toastMessage">This is a toast message</span>
    </div>

    <!-- Edit Stock Keeper Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-edit"></i> Edit Stock Keeper</h3>
                <button class="close-modal" onclick="closeEditModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="post" action="" id="editForm">
                    <input type="hidden" name="stock_keeper_id" id="edit_stock_keeper_id">
                    <input type="hidden" name="branch" id="edit_branch">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="edit_name">Full Name</label>
                            <input type="text" id="edit_name" name="name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_email">Email</label>
                            <input type="email" id="edit_email" name="email" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_phone_no">Phone Number</label>
                            <input type="text" id="edit_phone_no" name="phone_no" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_address">Address</label>
                            <input type="text" id="edit_address" name="address" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_status">Status</label>
                            <select id="edit_status" name="status" class="form-control" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="edit_branch_select">Branch</label>
                            <select id="edit_branch_select" name="branch" class="form-control" required>
                                <option value="branch1">Branch 1 (stock_keeper_users table)</option>
                                <option value="branch2">Branch 2 (stock_keeper_users2 table)</option>
                            </select>
                        </div>
                    </div>
                    <div style="display: flex; gap: 15px; margin-top: 20px;">
                        <button type="submit" name="update_stock_keeper" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Stock Keeper
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeEditModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div class="modal" id="passwordModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-key"></i> Change Password</h3>
                <button class="close-modal" onclick="closePasswordModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="post" action="" id="passwordForm">
                    <input type="hidden" name="stock_keeper_id" id="password_stock_keeper_id">
                    <input type="hidden" name="branch" id="password_branch">
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                    </div>
                    <div style="display: flex; gap: 15px; margin-top: 20px;">
                        <button type="submit" name="update_password" class="btn btn-primary" onclick="return validatePassword()">
                            <i class="fas fa-save"></i> Update Password
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closePasswordModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-trash"></i> Confirm Deletion</h3>
                <button class="close-modal" onclick="closeDeleteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <strong id="delete_keeper_name"></strong>? This action cannot be undone.</p>
                <form method="post" action="" id="deleteForm">
                    <input type="hidden" name="stock_keeper_id" id="delete_stock_keeper_id">
                    <input type="hidden" name="branch" id="delete_branch">
                    <div style="display: flex; gap: 15px; margin-top: 20px;">
                        <button type="submit" name="delete_stock_keeper" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
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

        // Modal functions
        function openEditModal(id, name, email, phone, address, status, branch) {
            document.getElementById('edit_stock_keeper_id').value = id;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_email').value = email;
            document.getElementById('edit_phone_no').value = phone;
            document.getElementById('edit_address').value = address;
            document.getElementById('edit_status').value = status;
            document.getElementById('edit_branch').value = branch;
            document.getElementById('edit_branch_select').value = branch;
            document.getElementById('editModal').classList.add('active');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        function openPasswordModal(id, name, branch) {
            document.getElementById('password_stock_keeper_id').value = id;
            document.getElementById('password_branch').value = branch;
            document.getElementById('passwordModal').classList.add('active');
        }

        function closePasswordModal() {
            document.getElementById('passwordModal').classList.remove('active');
            document.getElementById('passwordForm').reset();
        }

        function openDeleteModal(id, name, branch) {
            document.getElementById('delete_stock_keeper_id').value = id;
            document.getElementById('delete_keeper_name').textContent = name;
            document.getElementById('delete_branch').value = branch;
            document.getElementById('deleteModal').classList.add('active');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
        }

        // Password validation
        function validatePassword() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (newPassword !== confirmPassword) {
                showToast('Passwords do not match!');
                return false;
            }
            
            if (newPassword.length < 6) {
                showToast('Password must be at least 6 characters long!');
                return false;
            }
            
            return true;
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const editModal = document.getElementById('editModal');
            const passwordModal = document.getElementById('passwordModal');
            const deleteModal = document.getElementById('deleteModal');
            
            if (event.target === editModal) {
                closeEditModal();
            }
            if (event.target === passwordModal) {
                closePasswordModal();
            }
            if (event.target === deleteModal) {
                closeDeleteModal();
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

            const animatedElements = document.querySelectorAll('.form-container, .table-container');
            animatedElements.forEach(el => {
                el.style.opacity = 0;
                el.style.transform = 'translateY(15px)';
                el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                observer.observe(el);
            });

            // Add ripple effect to buttons
            document.querySelectorAll('.btn, .action-btn').forEach(button => {
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

            // Show success message with animation
            <?php if (!empty($message) && $message_type == 'success'): ?>
                setTimeout(() => {
                    showToast('<?php echo $message; ?>');
                }, 500);
            <?php endif; ?>
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