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
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $request_id = intval($_POST['request_id']);
    $status = $conn->real_escape_string($_POST['status']);
    $admin_notes = $conn->real_escape_string($_POST['admin_notes'] ?? '');
    
    $resolved_at = $status === 'resolved' ? ', resolved_at = NOW()' : '';
    
    $sql = "UPDATE admin_contact_requests 
            SET status = '$status', 
                admin_notes = '$admin_notes'
                $resolved_at 
            WHERE request_id = $request_id";
    
    if ($conn->query($sql)) {
        $success_message = "Request status updated successfully!";
    } else {
        $error_message = "Error updating request: " . $conn->error;
    }
}

// Handle delete request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $delete_id = intval($_POST['delete_id']);
    $conn->query("DELETE FROM admin_contact_requests WHERE request_id = $delete_id");
    $success_message = "Request deleted successfully!";
}

// Get all contact requests
$requests_result = $conn->query("
    SELECT * FROM admin_contact_requests 
    ORDER BY 
        CASE status 
            WHEN 'pending' THEN 1 
            WHEN 'in_progress' THEN 2 
            WHEN 'resolved' THEN 3 
        END, 
        created_at DESC
");

// Count requests by status
$stats_result = $conn->query("
    SELECT status, COUNT(*) as count 
    FROM admin_contact_requests 
    GROUP BY status
");
$stats = [];
while ($row = $stats_result->fetch_assoc()) {
    $stats[$row['status']] = $row['count'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Kids Berry - Contact Requests Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
            --gradient-pending: linear-gradient(135deg, #ff6b6b, #ee5a52);
            --gradient-progress: linear-gradient(135deg, #feca57, #ff9ff3);
            --gradient-resolved: linear-gradient(135deg, #48cae4, #0096c7);
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

        /* Summary Cards */
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .summary-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--shadow-light);
            transition: var(--transition);
            animation: fadeInUp 0.8s ease;
            position: relative;
            overflow: hidden;
            text-align: center;
        }

        .summary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }

        .summary-card.pending::before {
            background: var(--gradient-pending);
        }

        .summary-card.progress::before {
            background: var(--gradient-progress);
        }

        .summary-card.resolved::before {
            background: var(--gradient-resolved);
        }

        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-medium);
        }

        .summary-icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
            display: inline-block;
            padding: 15px;
            border-radius: 50%;
            transition: var(--transition);
        }

        .summary-card.pending .summary-icon {
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.2), rgba(238, 90, 82, 0.2));
            color: #ee5a52;
        }

        .summary-card.progress .summary-icon {
            background: linear-gradient(135deg, rgba(254, 202, 87, 0.2), rgba(255, 159, 243, 0.2));
            color: #feca57;
        }

        .summary-card.resolved .summary-icon {
            background: linear-gradient(135deg, rgba(72, 202, 228, 0.2), rgba(0, 150, 199, 0.2));
            color: #48cae4;
        }

        .summary-card:hover .summary-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .summary-title {
            font-size: 0.9rem;
            color: var(--dark-gray);
            margin-bottom: 8px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .summary-value {
            font-size: 1.5rem;
            font-weight: 800;
            margin-bottom: 5px;
            transition: var(--transition);
        }

        .summary-card.pending .summary-value {
            color: #ee5a52;
        }

        .summary-card.progress .summary-value {
            color: #feca57;
        }

        .summary-card.resolved .summary-value {
            color: #48cae4;
        }

        /* Search Box */
        .search-container {
            position: relative;
            width: 100%;
            margin: 20px auto;
        }

        .search-box {
            width: 100%;
            padding: 15px 20px 15px 45px;
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
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary-purple);
            font-size: 1.1rem;
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

        /* Table Styles */
        .table-container {
            overflow-x: auto;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-light);
            background: var(--white);
            margin-top: 10px;
        }

        table { 
            width: 100%; 
            border-collapse: collapse; 
            background: var(--white);
            min-width: 800px;
        }

        th { 
            background: var(--gradient-primary);
            color: var(--white); 
            padding: 15px 12px; 
            text-align: left; 
            font-weight: 600;
            position: sticky;
            top: 0;
            font-size: 0.9rem;
        }

        td { 
            padding: 14px 12px; 
            border-bottom: 1px solid var(--medium-gray); 
            transition: var(--transition);
            font-size: 0.9rem;
        }

        tr {
            transition: var(--transition);
        }

        tr:hover { 
            background: var(--light-green); 
            transform: scale(1.01);
        }

        /* Status Badges */
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-pending {
            background: var(--gradient-pending);
            color: white;
        }

        .status-progress {
            background: var(--gradient-progress);
            color: var(--dark-gray);
        }

        .status-resolved {
            background: var(--gradient-resolved);
            color: white;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 8px 12px;
            border: none;
            border-radius: var(--border-radius-small);
            color: var(--white);
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 5px;
            font-weight: 600;
            min-width: 70px;
            justify-content: center;
        }

        .view-btn { 
            background: #3498db;
            border: 2px solid #3498db;
        }

        .edit-btn { 
            background: #27ae60;
            border: 2px solid #27ae60;
        }

        .del-btn { 
            background: #e74c3c;
            border: 2px solid #e74c3c;
        }

        .action-btn:hover { 
            transform: translateY(-3px);
            box-shadow: var(--shadow-light);
            filter: brightness(1.1);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 15px;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--shadow-heavy);
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--light-gray);
        }

        .modal-title {
            color: var(--primary-purple);
            font-size: 1.3rem;
            font-weight: 700;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--dark-gray);
            cursor: pointer;
            transition: var(--transition);
        }

        .close-modal:hover {
            color: var(--primary-purple);
            transform: scale(1.1);
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: var(--dark-gray);
        }

        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--medium-gray);
            border-radius: var(--border-radius-small);
            font-size: 0.95rem;
            transition: var(--transition);
            background: var(--light-gray);
        }

        .form-group textarea {
            height: 100px;
            resize: vertical;
        }

        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: var(--primary-purple);
            box-shadow: 0 0 0 3px rgba(106, 13, 173, 0.2);
            outline: none;
            background: var(--white);
        }

        .btn {
            padding: 12px 20px;
            border-radius: var(--border-radius-small);
            font-size: 1rem;
            transition: var(--transition);
            border: none;
            color: var(--white);
            font-weight: bold;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            box-shadow: var(--shadow-light);
        }

        .btn-primary {
            background: var(--gradient-primary);
        }

        .btn-secondary {
            background: var(--gradient-secondary);
        }

        .btn:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-medium);
        }

        /* Message Preview */
        .message-preview {
            background: var(--light-gray);
            padding: 15px;
            border-radius: var(--border-radius-small);
            border-left: 4px solid var(--primary-purple);
            margin: 10px 0;
            font-size: 0.9rem;
            line-height: 1.5;
        }

        /* User Type Badge */
        .user-type-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            background: var(--light-purple);
            color: white;
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
            margin-bottom: 15px;
        }

        .empty-state h3 {
            font-size: 1.3rem;
            margin-bottom: 8px;
            color: var(--primary-purple);
        }

        .empty-state p {
            font-size: 0.9rem;
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

        /* Loading Spinner */
        .spinner {
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
        }

        .fab:hover {
            transform: scale(1.1);
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
            
            .card {
                padding: 20px;
            }
            
            .summary-cards {
                grid-template-columns: repeat(3, 1fr);
                gap: 20px;
            }
            
            .search-box {
                padding: 16px 20px 16px 50px;
                font-size: 1.1rem;
            }
            
            .search-icon {
                font-size: 1.2rem;
                left: 20px;
            }
            
            .toast {
                left: auto;
                right: 20px;
                width: auto;
            }
            
            .fab {
                display: none;
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
            
            .card {
                padding: 25px;
            }
            
            .summary-cards {
                gap: 25px;
            }
            
            .summary-card {
                padding: 25px;
            }
            
            .summary-value {
                font-size: 1.8rem;
            }
            
            .btn {
                padding: 14px 24px;
                font-size: 1.1rem;
            }
            
            th, td {
                padding: 16px 15px;
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
                 <li><a href="admin_dashboard.php" ><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="cashier_prediction.php" class=""><i class="fas fa-chart-line"></i> Cashier Predictions</a></li>
                <li><a href="customer_manage.php" ><i class="fas fa-users"></i> Customer Management</a></li>
                <li><a href="stockkeeper_manage.php" ><i class="fas fa-user-tie"></i> Stock Keeper Management</a></li>
               <li><a href="cashier_manage.php" ><i class="fas fa-user-tie"></i> Sales Officer Management</a></li>
                <li><a href="sales_manage.php"><i class="fas fa-cash-register"></i> Cashier Management</a></li>
                <li><a href="product_manage.php" ><i class="fas fa-box"></i> Product Management</a></li>
                 <li><a href="suppliers_manage.php"><i class="fas fa-truck"></i> Suppliers Management</a></li>
                <li><a href="report_show.php"><i class="fas fa-chart-line"></i> Reports</a></li>
                <li><a href="admin_contact_management.php" class="active"><i class="fas fa-headset"></i> Contact Requests</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <div class="header">
                <h1><i class="fas fa-headset"></i> Contact Requests Management</h1>
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

            <!-- Summary Cards Section -->
            <div class="summary-cards">
                <div class="summary-card pending">
                    <i class="fas fa-clock summary-icon"></i>
                    <div class="summary-title">Pending Requests</div>
                    <div class="summary-value count-up"><?= $stats['pending'] ?? 0 ?></div>
                    <div class="summary-subtitle">Awaiting response</div>
                </div>
                <div class="summary-card progress">
                    <i class="fas fa-spinner summary-icon"></i>
                    <div class="summary-title">In Progress</div>
                    <div class="summary-value count-up"><?= $stats['in_progress'] ?? 0 ?></div>
                    <div class="summary-subtitle">Being handled</div>
                </div>
                <div class="summary-card resolved">
                    <i class="fas fa-check-circle summary-icon"></i>
                    <div class="summary-title">Resolved</div>
                    <div class="summary-value count-up"><?= $stats['resolved'] ?? 0 ?></div>
                    <div class="summary-subtitle">Completed requests</div>
                </div>
            </div>

            <div class="search-container">
                <i class="fas fa-search search-icon"></i>
                <input type="text" id="searchInput" class="search-box" placeholder="Search by name, email, subject...">
            </div>

            <!-- Requests Table -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title"><i class="fas fa-list"></i> All Contact Requests</h2>
                </div>
                <div class="table-container">
                    <table id="requestsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>User Type</th>
                                <th>Subject</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($requests_result->num_rows == 0): ?>
                            <tr>
                                <td colspan="8">
                                    <div class="empty-state">
                                        <i class="fas fa-inbox"></i>
                                        <h3>No Contact Requests</h3>
                                        <p>All contact requests will appear here</p>
                                    </div>
                                </td>
                            </tr>
                            <?php else: while($row = $requests_result->fetch_assoc()): ?>
                            <tr>
                                <td><strong>#<?= $row['request_id'] ?></strong></td>
                                <td><?= htmlspecialchars($row['name']) ?></td>
                                <td><?= htmlspecialchars($row['email']) ?></td>
                                <td><span class="user-type-badge"><?= $row['user_type'] ?></span></td>
                                <td><?= htmlspecialchars($row['subject']) ?></td>
                                <td>
                                    <span class="status-badge status-<?= $row['status'] ?>">
                                        <?= str_replace('_', ' ', $row['status']) ?>
                                    </span>
                                </td>
                                <td><?= date('M j, Y g:i A', strtotime($row['created_at'])) ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="action-btn view-btn" onclick="viewRequest(<?= htmlspecialchars(json_encode($row)) ?>)">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <button class="action-btn edit-btn" onclick="editRequest(<?= htmlspecialchars(json_encode($row)) ?>)">
                                            <i class="fas fa-edit"></i> Update
                                        </button>
                                        <button class="action-btn del-btn" onclick="deleteRequest(<?= $row['request_id'] ?>)">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- View Request Modal -->
    <div class="modal" id="viewModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-eye"></i> Request Details</h3>
                <button class="close-modal" onclick="closeModal('viewModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="viewModalContent">
                <!-- Content will be loaded dynamically -->
            </div>
        </div>
    </div>

    <!-- Edit Request Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-edit"></i> Update Request Status</h3>
                <button class="close-modal" onclick="closeModal('editModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="editForm" method="POST">
                <input type="hidden" name="update_status" value="1">
                <input type="hidden" name="request_id" id="editRequestId">
                
                <div class="form-group">
                    <label for="status">Status</label>
                    <select name="status" id="status" required>
                        <option value="pending">Pending</option>
                        <option value="in_progress">In Progress</option>
                        <option value="resolved">Resolved</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="admin_notes">Admin Notes</label>
                    <textarea name="admin_notes" id="admin_notes" placeholder="Add any notes or follow-up actions..."></textarea>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Update Status
                </button>
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

        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = '';
        }

        // Close modal when clicking outside
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeModal(this.id);
                }
            });
        });

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

        // View Request
        function viewRequest(request) {
            const modalContent = document.getElementById('viewModalContent');
            
            const content = `
                <div class="form-group">
                    <label>Request ID</label>
                    <div style="padding: 12px; background: var(--light-gray); border-radius: var(--border-radius-small);">
                        <strong>#${request.request_id}</strong>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>User Information</label>
                    <div style="padding: 12px; background: var(--light-gray); border-radius: var(--border-radius-small);">
                        <strong>${request.name}</strong><br>
                        ${request.email}<br>
                        <span class="user-type-badge">${request.user_type}</span>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Subject</label>
                    <div style="padding: 12px; background: var(--light-gray); border-radius: var(--border-radius-small);">
                        ${request.subject}
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Message</label>
                    <div class="message-preview">
                        ${request.message}
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Status</label>
                    <div style="padding: 12px; background: var(--light-gray); border-radius: var(--border-radius-small);">
                        <span class="status-badge status-${request.status}">
                            ${request.status.replace('_', ' ')}
                        </span>
                    </div>
                </div>
                
                ${request.admin_notes ? `
                <div class="form-group">
                    <label>Admin Notes</label>
                    <div class="message-preview" style="border-left-color: var(--medium-green);">
                        ${request.admin_notes}
                    </div>
                </div>
                ` : ''}
                
                <div class="form-group">
                    <label>Timeline</label>
                    <div style="padding: 12px; background: var(--light-gray); border-radius: var(--border-radius-small);">
                        <strong>Created:</strong> ${new Date(request.created_at).toLocaleString()}<br>
                        ${request.resolved_at ? `<strong>Resolved:</strong> ${new Date(request.resolved_at).toLocaleString()}` : ''}
                    </div>
                </div>
            `;
            
            modalContent.innerHTML = content;
            openModal('viewModal');
        }

        // Edit Request
        function editRequest(request) {
            document.getElementById('editRequestId').value = request.request_id;
            document.getElementById('status').value = request.status;
            document.getElementById('admin_notes').value = request.admin_notes || '';
            openModal('editModal');
        }

        // Delete Request
        function deleteRequest(requestId) {
            Swal.fire({
                title: 'Are you sure?',
                text: "You won't be able to revert this!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#e74c3c',
                cancelButtonColor: '#95a5a6',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel',
                background: 'var(--white)'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.style.display = 'none';
                    
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'delete_id';
                    input.value = requestId;
                    
                    form.appendChild(input);
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Live Search
        document.getElementById('searchInput').addEventListener('keyup', function() {
            let val = this.value.toLowerCase();
            document.querySelectorAll('#requestsTable tbody tr').forEach(row => {
                if (row.querySelector('.empty-state')) return;
                row.style.display = row.textContent.toLowerCase().includes(val) ? '' : 'none';
            });
        });

        // Form submission handling
        document.getElementById('editForm').addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            submitBtn.innerHTML = '<span class="spinner"></span> Updating...';
            submitBtn.disabled = true;
            
            // Form will submit normally, let it proceed
        });

        // Add animations
        document.addEventListener('DOMContentLoaded', function() {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = 1;
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, { threshold: 0.1 });

            const animatedElements = document.querySelectorAll('.card, .summary-card');
            animatedElements.forEach(el => {
                el.style.opacity = 0;
                el.style.transform = 'translateY(15px)';
                el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                observer.observe(el);
            });

            // Show success/error messages
            <?php if (isset($success_message)): ?>
                showToast('<?= $success_message ?>');
            <?php elseif (isset($error_message)): ?>
                showToast('<?= $error_message ?>');
            <?php endif; ?>
        });

        // Handle touch events for better mobile experience
        document.addEventListener('touchstart', function() {}, {passive: true});
    </script>
</body>
</html>
<?php $conn->close(); ?>