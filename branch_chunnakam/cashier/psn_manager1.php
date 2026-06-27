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
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// Handle bulk PSN generation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['generate_psn'])) {
    $product_id = mysqli_real_escape_string($conn, $_POST['product_id']);
    $prefix = mysqli_real_escape_string($conn, $_POST['prefix']);
    $suffix = mysqli_real_escape_string($conn, $_POST['suffix']);
    $start_number = intval($_POST['start_number']);
    $count = intval($_POST['count']);
    
    // Get product name
    $product_sql = "SELECT name FROM products2 WHERE product_id = '$product_id'";
    $product_result = $conn->query($product_sql);
    if ($product_result->num_rows > 0) {
        $product = $product_result->fetch_assoc();
        $product_name = $product['name'];
        
        $conn->begin_transaction();
        try {
            $generated = 0;
            for ($i = 0; $i < $count; $i++) {
                $current_num = $start_number + $i;
                $psn_number = $prefix . str_pad($current_num, 6, '0', STR_PAD_LEFT) . $suffix;
                
                // Check if PSN already exists
                $check_sql = "SELECT id FROM product_psn_tracking2 WHERE psn_number = '$psn_number'";
                if ($conn->query($check_sql)->num_rows == 0) {
                    $insert_sql = "INSERT INTO product_psn_tracking2 
                                   (product_id, product_name, psn_number, status) 
                                   VALUES ('$product_id', '$product_name', '$psn_number', 'available')";
                    if ($conn->query($insert_sql)) {
                        $generated++;
                    }
                }
            }
            
            $conn->commit();
            $_SESSION['psn_message'] = "Successfully generated $generated PSN numbers for $product_name";
            $_SESSION['psn_message_type'] = "success";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['psn_message'] = "Error generating PSN numbers: " . $e->getMessage();
            $_SESSION['psn_message_type'] = "error";
        }
    }
    
    header("Location: psn_manager1.php");
    exit();
}

// Handle PSN deletion
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_psn'])) {
    $psn_id = mysqli_real_escape_string($conn, $_POST['psn_id']);
    
    $delete_sql = "DELETE FROM product_psn_tracking2 WHERE id = '$psn_id' AND status = 'available'";
    if ($conn->query($delete_sql)) {
        $_SESSION['psn_message'] = "PSN deleted successfully";
        $_SESSION['psn_message_type'] = "success";
    } else {
        $_SESSION['psn_message'] = "Error deleting PSN: " . $conn->error;
        $_SESSION['psn_message_type'] = "error";
    }
    
    header("Location: psn_manager1.php");
    exit();
}

// Handle PSN status update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_status'])) {
    $psn_id = mysqli_real_escape_string($conn, $_POST['psn_id']);
    $new_status = mysqli_real_escape_string($conn, $_POST['new_status']);
    
    $update_sql = "UPDATE product_psn_tracking2 SET status = '$new_status' WHERE id = '$psn_id'";
    if ($conn->query($update_sql)) {
        $_SESSION['psn_message'] = "PSN status updated successfully";
        $_SESSION['psn_message_type'] = "success";
    } else {
        $_SESSION['psn_message'] = "Error updating status: " . $conn->error;
        $_SESSION['psn_message_type'] = "error";
    }
    
    header("Location: psn_manager1.php");
    exit();
}

// Get all products for dropdown
$products_sql = "SELECT product_id, name FROM products2 ORDER BY name";
$products_result = $conn->query($products_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIDS Berry - PSN Manager | Product Serial Number Tracking</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Modern CSS Reset & Variables */
        :root {
            --primary-purple: #8A2BE2;
            --primary-purple-dark: #6B1FA6;
            --primary-purple-light: #B47BFF;
            --primary-purple-soft: #F3E8FF;
            --secondary-green: #32CD32;
            --secondary-green-dark: #228B22;
            --secondary-green-light: #90EE90;
            --secondary-green-soft: #E8FFE8;
            --accent-gradient: linear-gradient(135deg, #8A2BE2, #32CD32);
            --accent-gradient-reverse: linear-gradient(135deg, #32CD32, #8A2BE2);
            --dark: #1A1D29;
            --gray: #6C757D;
            --light: #F8F9FC;
            --white: #FFFFFF;
            --success: #28A745;
            --warning: #FFC107;
            --danger: #DC3545;
            --info: #17A2B8;
            
            --card-shadow: 0 20px 35px rgba(138, 43, 226, 0.12), 0 8px 15px rgba(50, 205, 50, 0.08);
            --card-shadow-hover: 0 30px 50px rgba(138, 43, 226, 0.2), 0 15px 25px rgba(50, 205, 50, 0.15);
            --transition: all 0.4s cubic-bezier(0.165, 0.84, 0.44, 1);
            --radius-sm: 12px;
            --radius-md: 20px;
            --radius-lg: 30px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(145deg, #F8F4FF 0%, #F0FFF0 50%, #F8F4FF 100%);
            color: var(--dark);
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
            line-height: 1.6;
        }

        /* Animated Background Elements */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 10% 20%, rgba(138, 43, 226, 0.05) 0%, transparent 30%),
                radial-gradient(circle at 90% 70%, rgba(50, 205, 50, 0.05) 0%, transparent 30%),
                radial-gradient(circle at 30% 80%, rgba(138, 43, 226, 0.05) 0%, transparent 40%),
                radial-gradient(circle at 70% 30%, rgba(50, 205, 50, 0.05) 0%, transparent 40%);
            pointer-events: none;
            animation: backgroundFloat 20s ease-in-out infinite alternate;
        }

        @keyframes backgroundFloat {
            0% { opacity: 0.5; transform: scale(1); }
            100% { opacity: 0.8; transform: scale(1.1); }
        }

        /* Header Section - Modern & Dynamic */
        .header {
            background: linear-gradient(120deg, #1a1f2e 0%, #232837 100%);
            color: white;
            padding: 30px 50px;
            border-radius: 0 0 50px 50px;
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
            margin-bottom: 40px;
        }

        .header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, 
                transparent 30%, 
                rgba(138, 43, 226, 0.1) 50%, 
                transparent 70%);
            animation: headerShine 8s infinite;
        }

        @keyframes headerShine {
            0% { transform: translateX(-100%) rotate(45deg); }
            100% { transform: translateX(100%) rotate(45deg); }
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            z-index: 2;
            max-width: 1600px;
            margin: 0 auto;
        }

        .logo-area {
            display: flex;
            align-items: center;
            gap: 25px;
        }

        .logo-icon {
            width: 80px;
            height: 80px;
            background: var(--accent-gradient);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            color: white;
            transform: rotate(-5deg);
            box-shadow: 0 15px 30px rgba(0,0,0,0.3);
            transition: var(--transition);
        }

        .logo-icon:hover {
            transform: rotate(0deg) scale(1.05);
            box-shadow: 0 20px 40px rgba(138, 43, 226, 0.4);
        }

        .brand-text {
            display: flex;
            flex-direction: column;
        }

        .brand-text h1 {
            font-size: 38px;
            font-weight: 800;
            background: linear-gradient(135deg, #fff, var(--secondary-green-light));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 5px;
            letter-spacing: -0.5px;
        }

        .brand-tagline {
            font-size: 16px;
            font-weight: 500;
            color: rgba(255,255,255,0.9);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .brand-tagline i {
            color: var(--secondary-green-light);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .date-badge {
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            padding: 15px 25px;
            border-radius: 15px;
            border: 1px solid rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .date-badge i {
            color: var(--secondary-green-light);
            font-size: 20px;
        }

        .date-info {
            display: flex;
            flex-direction: column;
        }

        .date-day {
            font-size: 16px;
            font-weight: 600;
        }

        .date-time {
            font-size: 14px;
            opacity: 0.9;
        }

        .back-btn {
            background: var(--accent-gradient);
            color: white;
            padding: 15px 30px;
            border-radius: 15px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
            font-size: 16px;
            transition: var(--transition);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .back-btn:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(138, 43, 226, 0.4);
            background: linear-gradient(135deg, #32CD32, #8A2BE2);
        }

        /* Main Container */
        .main-container {
            max-width: 1600px;
            margin: 0 auto 50px;
            padding: 0 30px;
        }

        /* Stats Cards Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 30px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: var(--white);
            border-radius: 25px;
            padding: 30px;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(138, 43, 226, 0.1);
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: var(--accent-gradient);
        }

        .stat-card:hover {
            transform: translateY(-10px);
            box-shadow: var(--card-shadow-hover);
        }

        .stat-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--primary-purple-soft), var(--secondary-green-soft));
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            color: var(--primary-purple);
        }

        .stat-content {
            flex: 1;
        }

        .stat-label {
            font-size: 15px;
            font-weight: 600;
            color: var(--gray);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .stat-value {
            font-size: 36px;
            font-weight: 800;
            color: var(--dark);
            line-height: 1;
            margin-bottom: 5px;
        }

        .stat-trend {
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* Section Cards */
        .section-card {
            background: var(--white);
            border-radius: 30px;
            padding: 35px;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            margin-bottom: 40px;
            border: 1px solid rgba(138, 43, 226, 0.15);
            position: relative;
            overflow: hidden;
        }

        .section-card:hover {
            box-shadow: var(--card-shadow-hover);
            transform: translateY(-5px);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 3px solid;
            border-image: linear-gradient(90deg, var(--primary-purple), var(--secondary-green)) 1;
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .section-title i {
            font-size: 28px;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .section-title h2 {
            font-size: 26px;
            font-weight: 700;
            color: var(--dark);
        }

        .section-badge {
            background: linear-gradient(135deg, var(--primary-purple-soft), var(--secondary-green-soft));
            padding: 10px 20px;
            border-radius: 15px;
            color: var(--primary-purple-dark);
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Modern Form Styles */
        .form-modern {
            background: linear-gradient(145deg, #FFFFFF, #F8F9FC);
            padding: 30px;
            border-radius: 20px;
            border: 1px solid rgba(138, 43, 226, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }

        .form-group {
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: var(--dark);
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-group label i {
            color: var(--primary-purple);
            font-size: 16px;
        }

        .form-control {
            width: 100%;
            padding: 15px 20px;
            border: 2px solid #E9ECEF;
            border-radius: 15px;
            font-size: 15px;
            font-weight: 500;
            transition: var(--transition);
            background: var(--white);
            color: var(--dark);
        }

        .form-control:focus {
            border-color: var(--primary-purple);
            box-shadow: 0 0 0 4px rgba(138, 43, 226, 0.15);
            outline: none;
            transform: translateY(-2px);
        }

        .form-control:hover {
            border-color: var(--secondary-green);
        }

        select.form-control {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%238A2BE2' stroke-width='2' stroke-linecap='round' stroke-linecap='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 20px center;
            padding-right: 50px;
        }

        /* Button Styles */
        .btn {
            padding: 15px 30px;
            border: none;
            border-radius: 15px;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255,255,255,0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-purple), var(--primary-purple-light));
            color: white;
            box-shadow: 0 10px 20px rgba(138, 43, 226, 0.25);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 30px rgba(138, 43, 226, 0.35);
            background: linear-gradient(135deg, var(--primary-purple-dark), var(--primary-purple));
        }

        .btn-success {
            background: linear-gradient(135deg, var(--secondary-green), var(--secondary-green-light));
            color: white;
            box-shadow: 0 10px 20px rgba(50, 205, 50, 0.25);
        }

        .btn-success:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 30px rgba(50, 205, 50, 0.35);
            background: linear-gradient(135deg, var(--secondary-green-dark), var(--secondary-green));
        }

        .btn-danger {
            background: linear-gradient(135deg, #FF6B6B, #FF8787);
            color: white;
            box-shadow: 0 10px 20px rgba(220, 53, 69, 0.25);
        }

        .btn-danger:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 30px rgba(220, 53, 69, 0.35);
        }

        .btn-sm {
            padding: 10px 20px;
            font-size: 13px;
        }

        /* Alert Messages */
        .alert {
            padding: 20px 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: slideInDown 0.6s ease;
            border-left: 6px solid;
            backdrop-filter: blur(10px);
        }

        .alert-success {
            background: linear-gradient(145deg, #E8FFE8, #D4FFD4);
            border-left-color: var(--secondary-green);
            color: #1E4B1E;
        }

        .alert-error {
            background: linear-gradient(145deg, #FFE8E8, #FFD4D4);
            border-left-color: var(--danger);
            color: #721C24;
        }

        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Table Styles - Modern & Elegant */
        .table-wrapper {
            background: var(--white);
            border-radius: 20px;
            overflow: hidden;
            border: 1px solid rgba(138, 43, 226, 0.1);
            margin-top: 30px;
        }

        .modern-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 15px;
        }

        .modern-table thead {
            background: linear-gradient(145deg, var(--primary-purple), var(--secondary-green));
        }

        .modern-table thead th {
            padding: 20px 25px;
            color: white;
            font-weight: 600;
            text-align: left;
            font-size: 15px;
            letter-spacing: 0.5px;
        }

        .modern-table thead th i {
            margin-right: 10px;
            color: rgba(255,255,255,0.9);
        }

        .modern-table tbody tr {
            border-bottom: 1px solid rgba(138, 43, 226, 0.1);
            transition: var(--transition);
        }

        .modern-table tbody tr:hover {
            background: linear-gradient(145deg, var(--primary-purple-soft), var(--secondary-green-soft));
            transform: scale(1.01);
            box-shadow: 0 5px 15px rgba(138, 43, 226, 0.1);
        }

        .modern-table td {
            padding: 20px 25px;
            color: var(--dark);
        }

        /* Status Badges */
        .status-badge {
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            letter-spacing: 0.5px;
        }

        .status-available {
            background: linear-gradient(145deg, #E8FFE8, #D4FFD4);
            color: #1E4B1E;
            border: 1px solid var(--secondary-green);
        }

        .status-sold {
            background: linear-gradient(145deg, #E3F2FD, #BBDEFB);
            color: #0D47A1;
            border: 1px solid #2196F3;
        }

        .status-returned {
            background: linear-gradient(145deg, #FFF3E0, #FFE0B2);
            color: #BF360C;
            border: 1px solid #FF9800;
        }

        .status-defective {
            background: linear-gradient(145deg, #FFEBEE, #FFCDD2);
            color: #B71C1C;
            border: 1px solid var(--danger);
        }

        /* Search Section */
        .search-section {
            background: linear-gradient(145deg, #FFFFFF, #F8F4FF);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            border: 1px solid rgba(138, 43, 226, 0.15);
        }

        .search-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            align-items: end;
        }

        .search-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
        }

        /* Action Buttons Group */
        .action-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 8px 15px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: white;
            border: 1px solid;
        }

        .action-btn-edit {
            color: var(--primary-purple);
            border-color: var(--primary-purple);
        }

        .action-btn-edit:hover {
            background: var(--primary-purple);
            color: white;
        }

        .action-btn-delete {
            color: var(--danger);
            border-color: var(--danger);
        }

        .action-btn-delete:hover {
            background: var(--danger);
            color: white;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 40px;
            background: linear-gradient(145deg, #FFFFFF, #F8F9FC);
            border-radius: 20px;
            margin: 20px 0;
        }

        .empty-state i {
            font-size: 80px;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 24px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 10px;
        }

        .empty-state p {
            font-size: 16px;
            color: var(--gray);
            max-width: 400px;
            margin: 0 auto;
        }

        /* Animations */
        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0px); }
        }

        .animate-float {
            animation: float 3s ease-in-out infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .animate-pulse {
            animation: pulse 2s ease-in-out infinite;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .header-content {
                flex-direction: column;
                gap: 20px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .header {
                padding: 20px;
                border-radius: 0 0 30px 30px;
            }
            
            .logo-area {
                flex-direction: column;
                text-align: center;
            }
            
            .brand-text h1 {
                font-size: 28px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .section-card {
                padding: 20px;
            }
            
            .search-grid {
                grid-template-columns: 1fr;
            }
            
            .search-actions {
                justify-content: stretch;
            }
            
            .search-actions button {
                flex: 1;
            }
            
            .modern-table {
                font-size: 13px;
            }
            
            .modern-table thead th,
            .modern-table td {
                padding: 15px;
            }
        }

        @media (max-width: 480px) {
            .main-container {
                padding: 0 15px;
            }
            
            .stat-card {
                padding: 20px;
            }
            
            .stat-icon {
                width: 50px;
                height: 50px;
                font-size: 24px;
            }
            
            .stat-value {
                font-size: 28px;
            }
            
            .btn {
                width: 100%;
            }
            
            .action-group {
                flex-direction: column;
            }
        }

        /* Loading Animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(138, 43, 226, 0.3);
            border-radius: 50%;
            border-top-color: var(--primary-purple);
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Tooltip */
        [data-tooltip] {
            position: relative;
            cursor: pointer;
        }

        [data-tooltip]:before {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            padding: 8px 15px;
            background: var(--dark);
            color: white;
            font-size: 12px;
            border-radius: 8px;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
            pointer-events: none;
            margin-bottom: 10px;
        }

        [data-tooltip]:hover:before {
            opacity: 1;
            visibility: visible;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <div class="logo-area">
                <div class="logo-icon animate-float">
                    <i class="fas fa-barcode"></i>
                </div>
                <div class="brand-text">
                    <h1><i class="fas fa-qrcode"></i> PSN Manager</h1>
                    <div class="brand-tagline">
                        <i class="fas fa-cubes"></i> Product Serial Number Tracking
                        <span style="background: rgba(255,255,255,0.2); padding: 5px 10px; border-radius: 10px; margin-left: 10px;">
                            <i class="fas fa-shield-alt"></i> Secure & Reliable
                        </span>
                    </div>
                </div>
            </div>
            
            <div class="header-actions">
                <div class="date-badge">
                    <i class="fas fa-calendar-alt"></i>
                    <div class="date-info">
                        <span class="date-day"><?php echo date('l, F j, Y'); ?></span>
                        <span class="date-time"><?php echo date('g:i:s A'); ?></span>
                    </div>
                </div>
                <a href="report1.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
            </div>
        </div>
    </div>
    
    <div class="main-container">
        <?php if (isset($_SESSION['psn_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['psn_message_type']; ?>">
                <i class="fas <?php echo $_SESSION['psn_message_type'] == 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?> fa-2x"></i>
                <div style="flex: 1;">
                    <strong style="font-size: 16px;">
                        <?php echo $_SESSION['psn_message_type'] == 'success' ? 'Success!' : 'Error!'; ?>
                    </strong>
                    <p style="margin-top: 5px;"><?php echo $_SESSION['psn_message']; ?></p>
                </div>
            </div>
            <?php 
            unset($_SESSION['psn_message']);
            unset($_SESSION['psn_message_type']);
            ?>
        <?php endif; ?>
        
        <?php
        // Get PSN statistics
        $stats_sql = "SELECT 
                        COUNT(*) as total_psn,
                        SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_psn,
                        SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold_psn,
                        SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) as returned_psn,
                        SUM(CASE WHEN status = 'defective' THEN 1 ELSE 0 END) as defective_psn
                      FROM product_psn_tracking2";
        $stats_result = $conn->query($stats_sql);
        $stats = $stats_result->fetch_assoc();
        ?>
        
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card animate-float">
                <div class="stat-icon">
                    <i class="fas fa-cubes"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Total PSN</div>
                    <div class="stat-value"><?php echo number_format($stats['total_psn'] ?? 0); ?></div>
                    <div class="stat-trend">
                        <i class="fas fa-database"></i> Registered Numbers
                    </div>
                </div>
            </div>
            
            <div class="stat-card animate-float" style="animation-delay: 0.2s;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #E8FFE8, #D4FFD4); color: #28A745;">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Available</div>
                    <div class="stat-value" style="color: #28A745;"><?php echo number_format($stats['available_psn'] ?? 0); ?></div>
                    <div class="stat-trend">
                        <i class="fas fa-arrow-up"></i> Ready to sell
                    </div>
                </div>
            </div>
            
            <div class="stat-card animate-float" style="animation-delay: 0.4s;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #E3F2FD, #BBDEFB); color: #2196F3;">
                    <i class="fas fa-shopping-cart"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Sold</div>
                    <div class="stat-value" style="color: #2196F3;"><?php echo number_format($stats['sold_psn'] ?? 0); ?></div>
                    <div class="stat-trend">
                        <i class="fas fa-chart-line"></i> Total sales
                    </div>
                </div>
            </div>
            
            <div class="stat-card animate-float" style="animation-delay: 0.6s;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #FFF3E0, #FFE0B2); color: #FF9800;">
                    <i class="fas fa-undo-alt"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Returned</div>
                    <div class="stat-value" style="color: #FF9800;"><?php echo number_format($stats['returned_psn'] ?? 0); ?></div>
                    <div class="stat-trend">
                        <i class="fas fa-exchange-alt"></i> Processed
                    </div>
                </div>
            </div>
            
            <div class="stat-card animate-float" style="animation-delay: 0.8s;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #FFEBEE, #FFCDD2); color: #DC3545;">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Defective</div>
                    <div class="stat-value" style="color: #DC3545;"><?php echo number_format($stats['defective_psn'] ?? 0); ?></div>
                    <div class="stat-trend">
                        <i class="fas fa-times-circle"></i> Needs attention
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Generate PSN Section -->
        <div class="section-card">
            <div class="section-header">
                <div class="section-title">
                    <i class="fas fa-magic"></i>
                    <h2>Generate PSN Numbers</h2>
                </div>
                <div class="section-badge">
                    <i class="fas fa-bolt"></i> Bulk Generation
                </div>
            </div>
            
            <div class="form-modern">
                <form method="post" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="product_id">
                                <i class="fas fa-box"></i> Select Product
                            </label>
                            <select name="product_id" id="product_id" class="form-control" required>
                                <option value="">-- Choose a product --</option>
                                <?php 
                                $products_result->data_seek(0);
                                while($product = $products_result->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $product['product_id']; ?>">
                                        <?php echo htmlspecialchars($product['name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="prefix">
                                <i class="fas fa-font"></i> Prefix
                            </label>
                            <input type="text" name="prefix" id="prefix" class="form-control" 
                                   placeholder="e.g., KB" value="KB" maxlength="10">
                        </div>
                        
                        <div class="form-group">
                            <label for="suffix">
                                <i class="fas fa-font"></i> Suffix
                            </label>
                            <input type="text" name="suffix" id="suffix" class="form-control" 
                                   placeholder="e.g., A" maxlength="10">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="start_number">
                                <i class="fas fa-sort-numeric-up"></i> Start Number
                            </label>
                            <input type="number" name="start_number" id="start_number" class="form-control" 
                                   placeholder="e.g., 1001" min="1" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="count">
                                <i class="fas fa-calculator"></i> Quantity to Generate
                            </label>
                            <input type="number" name="count" id="count" class="form-control" 
                                   placeholder="e.g., 100" min="1" max="1000" required>
                        </div>
                        
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="submit" name="generate_psn" class="btn btn-success" style="width: 100%;">
                                <i class="fas fa-barcode"></i> Generate PSNs
                                <span style="background: rgba(255,255,255,0.2); padding: 5px 10px; border-radius: 10px; margin-left: 10px; font-size: 12px;">
                                    Bulk
                                </span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            
            <div style="margin-top: 20px; padding: 15px; background: linear-gradient(145deg, var(--primary-purple-soft), var(--secondary-green-soft)); border-radius: 15px; display: flex; align-items: center; gap: 15px;">
                <i class="fas fa-info-circle" style="color: var(--primary-purple); font-size: 20px;"></i>
                <span style="color: var(--dark); font-size: 14px;">
                    <strong>Format:</strong> Prefix + 6-digit number + Suffix (e.g., KB001234A)
                </span>
            </div>
        </div>
        
        <!-- Search & Manage PSN Section -->
        <div class="section-card">
            <div class="section-header">
                <div class="section-title">
                    <i class="fas fa-search"></i>
                    <h2>Search & Manage PSN</h2>
                </div>
                <div class="section-badge">
                    <i class="fas fa-filter"></i> Advanced Filters
                </div>
            </div>
            
            <div class="search-section">
                <form method="get" action="">
                    <div class="search-grid">
                        <div class="form-group">
                            <label for="search_psn">
                                <i class="fas fa-barcode"></i> PSN Number
                            </label>
                            <input type="text" name="search_psn" id="search_psn" class="form-control" 
                                   placeholder="Search by PSN..." 
                                   value="<?php echo isset($_GET['search_psn']) ? htmlspecialchars($_GET['search_psn']) : ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="search_product_psn">
                                <i class="fas fa-box"></i> Product Name
                            </label>
                            <input type="text" name="search_product" id="search_product_psn" class="form-control" 
                                   placeholder="Search by product..." 
                                   value="<?php echo isset($_GET['search_product']) ? htmlspecialchars($_GET['search_product']) : ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="search_status">
                                <i class="fas fa-tag"></i> Status
                            </label>
                            <select name="search_status" id="search_status" class="form-control">
                                <option value="">All Status</option>
                                <option value="available" <?php echo (isset($_GET['search_status']) && $_GET['search_status'] == 'available') ? 'selected' : ''; ?>>Available</option>
                                <option value="sold" <?php echo (isset($_GET['search_status']) && $_GET['search_status'] == 'sold') ? 'selected' : ''; ?>>Sold</option>
                                <option value="returned" <?php echo (isset($_GET['search_status']) && $_GET['search_status'] == 'returned') ? 'selected' : ''; ?>>Returned</option>
                                <option value="defective" <?php echo (isset($_GET['search_status']) && $_GET['search_status'] == 'defective') ? 'selected' : ''; ?>>Defective</option>
                            </select>
                        </div>
                        
                        <div class="search-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Search
                            </button>
                            <a href="psn_manager1.php" class="btn" style="background: linear-gradient(135deg, #6C757D, #868E96); color: white;">
                                <i class="fas fa-redo-alt"></i> Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>
            
            <?php
            // Build search query
            $search_conditions = [];
            
            if (isset($_GET['search_psn']) && !empty($_GET['search_psn'])) {
                $search_psn = mysqli_real_escape_string($conn, $_GET['search_psn']);
                $search_conditions[] = "p.psn_number LIKE '%$search_psn%'";
            }
            
            if (isset($_GET['search_product']) && !empty($_GET['search_product'])) {
                $search_product = mysqli_real_escape_string($conn, $_GET['search_product']);
                $search_conditions[] = "p.product_name LIKE '%$search_product%'";
            }
            
            if (isset($_GET['search_status']) && !empty($_GET['search_status'])) {
                $search_status = mysqli_real_escape_string($conn, $_GET['search_status']);
                $search_conditions[] = "p.status = '$search_status'";
            }
            
            $psn_sql = "SELECT p.*, pr.name as product_name, pr.product_id 
                       FROM product_psn_tracking2 p
                       LEFT JOIN products pr ON p.product_id = pr.product_id";
            
            if (!empty($search_conditions)) {
                $psn_sql .= " WHERE " . implode(" AND ", $search_conditions);
            }
            
            $psn_sql .= " ORDER BY p.created_at DESC LIMIT 100";
            
            $psn_result = $conn->query($psn_sql);
            ?>
            
            <?php if ($psn_result->num_rows > 0): ?>
                <div class="table-wrapper">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th><i class="fas fa-barcode"></i> PSN Number</th>
                                <th><i class="fas fa-box"></i> Product</th>
                                <th><i class="fas fa-tag"></i> Status</th>
                                <th><i class="fas fa-receipt"></i> Sale Bill</th>
                                <th><i class="fas fa-undo"></i> Return Bill</th>
                                <th><i class="fas fa-calendar"></i> Created Date</th>
                                <th><i class="fas fa-cog"></i> Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($psn = $psn_result->fetch_assoc()): 
                                $status_class = 'status-' . $psn['status'];
                                $status_icon = '';
                                switch($psn['status']) {
                                    case 'available': $status_icon = 'fa-check-circle'; break;
                                    case 'sold': $status_icon = 'fa-shopping-cart'; break;
                                    case 'returned': $status_icon = 'fa-undo'; break;
                                    case 'defective': $status_icon = 'fa-exclamation-triangle'; break;
                                    default: $status_icon = 'fa-circle';
                                }
                            ?>
                            <tr>
                                <td>
                                    <strong style="color: var(--primary-purple); font-size: 15px; letter-spacing: 1px;">
                                        <?php echo htmlspecialchars($psn['psn_number']); ?>
                                    </strong>
                                </td>
                                <td><?php echo htmlspecialchars($psn['product_name']); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <i class="fas <?php echo $status_icon; ?>"></i>
                                        <?php echo ucfirst($psn['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($psn['sale_bill_no']): ?>
                                        <span style="background: #E3F2FD; padding: 5px 10px; border-radius: 8px; color: #0D47A1; font-size: 12px;">
                                            <i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($psn['sale_bill_no']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: var(--gray);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($psn['return_bill_no']): ?>
                                        <span style="background: #FFF3E0; padding: 5px 10px; border-radius: 8px; color: #BF360C; font-size: 12px;">
                                            <i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($psn['return_bill_no']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: var(--gray);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="display: flex; flex-direction: column;">
                                        <span style="font-weight: 600;"><?php echo date('d M Y', strtotime($psn['created_at'])); ?></span>
                                        <span style="font-size: 12px; color: var(--gray);"><?php echo date('h:i A', strtotime($psn['created_at'])); ?></span>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-group">
                                        <?php if ($psn['status'] == 'available'): ?>
                                            <form method="post" style="display: inline;" onsubmit="return confirm('⚠️ Are you sure you want to delete this PSN?\nThis action cannot be undone!');">
                                                <input type="hidden" name="psn_id" value="<?php echo $psn['id']; ?>">
                                                <button type="submit" name="delete_psn" class="action-btn action-btn-delete" data-tooltip="Delete PSN">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </form>
                                            
                                            <form method="post" style="display: inline;">
                                                <input type="hidden" name="psn_id" value="<?php echo $psn['id']; ?>">
                                                <input type="hidden" name="new_status" value="defective">
                                                <button type="submit" name="update_status" class="action-btn action-btn-edit" data-tooltip="Mark as Defective" 
                                                        onclick="return confirm('Mark this PSN as defective?');">
                                                    <i class="fas fa-exclamation-triangle"></i> Defective
                                                </button>
                                            </form>
                                        <?php elseif ($psn['status'] == 'defective'): ?>
                                            <form method="post" style="display: inline;">
                                                <input type="hidden" name="psn_id" value="<?php echo $psn['id']; ?>">
                                                <input type="hidden" name="new_status" value="available">
                                                <button type="submit" name="update_status" class="action-btn" style="color: #28A745; border-color: #28A745;" 
                                                        onclick="return confirm('Mark this PSN as available?');">
                                                    <i class="fas fa-check-circle"></i> Restore
                                                </button>
                                            </form>
                                        <?php elseif ($psn['status'] == 'returned'): ?>
                                            <form method="post" style="display: inline;">
                                                <input type="hidden" name="psn_id" value="<?php echo $psn['id']; ?>">
                                                <input type="hidden" name="new_status" value="available">
                                                <button type="submit" name="update_status" class="action-btn" style="color: #28A745; border-color: #28A745;" 
                                                        onclick="return confirm('Make this PSN available for sale again?');">
                                                    <i class="fas fa-undo-alt"></i> Restock
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span style="color: var(--gray); font-style: italic; font-size: 12px;">Locked</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                
                <div style="margin-top: 20px; display: flex; justify-content: flex-end; align-items: center; gap: 15px;">
                    <span style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--gray);">
                        <i class="fas fa-info-circle" style="color: var(--primary-purple);"></i>
                        Showing <?php echo $psn_result->num_rows; ?> of <?php echo $stats['total_psn'] ?? 0; ?> PSN numbers
                    </span>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-barcode"></i>
                    <h3>No PSN Numbers Found</h3>
                    <p>Try adjusting your search criteria or generate new PSN numbers to get started.</p>
                    <button onclick="document.getElementById('product_id').focus()" class="btn btn-primary" style="margin-top: 20px;">
                        <i class="fas fa-plus-circle"></i> Generate PSN Numbers
                    </button>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Quick Actions Section -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px; margin-top: 30px;">
            <div class="section-card" style="margin-bottom: 0;">
                <div style="display: flex; align-items: center; gap: 20px;">
                    <div style="background: linear-gradient(135deg, var(--primary-purple-soft), var(--secondary-green-soft)); width: 60px; height: 60px; border-radius: 15px; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-download" style="font-size: 30px; color: var(--primary-purple);"></i>
                    </div>
                    <div>
                        <h3 style="font-size: 18px; margin-bottom: 5px;">Export PSN List</h3>
                        <p style="color: var(--gray); font-size: 14px;">Download all PSN numbers in CSV format</p>
                    </div>
                    <a href="export_psn1.php" class="btn btn-primary" style="margin-left: auto; padding: 12px 25px;">
                        <i class="fas fa-file-csv"></i> Export
                    </a>
                </div>
            </div>
            
            <div class="section-card" style="margin-bottom: 0;">
                <div style="display: flex; align-items: center; gap: 20px;">
                    <div style="background: linear-gradient(135deg, #FFE8E8, #FFD4D4); width: 60px; height: 60px; border-radius: 15px; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-chart-pie" style="font-size: 30px; color: #DC3545;"></i>
                    </div>
                    <div>
                        <h3 style="font-size: 18px; margin-bottom: 5px;">PSN Reports</h3>
                        <p style="color: var(--gray); font-size: 14px;">View detailed analytics and insights</p>
                    </div>
                    <a href="psn_reports.php" class="btn" style="background: linear-gradient(135deg, #DC3545, #FF6B6B); color: white; margin-left: auto; padding: 12px 25px;">
                        <i class="fas fa-chart-bar"></i> View
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Live clock update
        function updateDateTime() {
            const now = new Date();
            const dateOptions = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            const timeOptions = { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
            
            document.querySelector('.date-day').textContent = now.toLocaleDateString('en-US', dateOptions);
            document.querySelector('.date-time').textContent = now.toLocaleTimeString('en-US', timeOptions);
        }
        setInterval(updateDateTime, 1000);
        
        // Auto-dismiss alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(function() {
                    alert.remove();
                }, 500);
            });
        }, 5000);
        
        // Set default start number based on product selection
        document.getElementById('product_id').addEventListener('change', function() {
            if (this.value) {
                // You can implement AJAX here to get the last PSN number
                document.getElementById('start_number').placeholder = 'Enter start number';
            }
        });
        
        // Search form enhancements
        document.addEventListener('DOMContentLoaded', function() {
            // Focus on first input
            const urlParams = new URLSearchParams(window.location.search);
            if (!urlParams.has('search_psn') && !urlParams.has('search_product')) {
                document.getElementById('product_id').focus();
            }
            
            // Add loading state to generate button
            const generateForm = document.querySelector('form[action=""][method="post"]');
            if (generateForm) {
                generateForm.addEventListener('submit', function(e) {
                    const btn = this.querySelector('button[name="generate_psn"]');
                    if (btn) {
                        btn.innerHTML = '<span class="loading" style="margin-right: 10px;"></span> Generating...';
                        btn.disabled = true;
                    }
                });
            }
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + G = Focus on product select
            if (e.ctrlKey && e.key === 'g') {
                e.preventDefault();
                document.getElementById('product_id').focus();
            }
            // Ctrl + F = Focus on search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.getElementById('search_psn').focus();
            }
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>