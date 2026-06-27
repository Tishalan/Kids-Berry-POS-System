<?php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['stock_keeper_id'])) {
    header("Location: ../index.php");
    exit();
}

// Handle logout
if (isset($_GET['logout'])) {
    // Only destroy stock keeper related session
    unset($_SESSION['stock_keeper_id']);
    // Don't use session_destroy() as it destroys all sessions
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
$conn->set_charset("utf8mb4");

// Create uploads folder
if (!is_dir('uploads')) mkdir('uploads', 0777, true);

// ==================== PHP BACKEND (AJAX) ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    // Delete Product
    if (isset($_POST['delete_id'])) {
        $id = $conn->real_escape_string($_POST['delete_id']);
        // Optional: Delete photo file too
        $photo = $conn->query("SELECT photo FROM products WHERE product_id='$id'")->fetch_assoc();
        if ($photo && $photo['photo'] && file_exists($photo['photo'])) unlink($photo['photo']);

        $conn->query("DELETE FROM products WHERE product_id = '$id'");
        echo json_encode(['success' => true, 'message' => 'Product deleted!']);
        exit;
    }

    // Add or Update Product
    $product_id = trim($_POST['product_id'] ?? '');
    $name = $conn->real_escape_string($_POST['name']);
    $category = $conn->real_escape_string($_POST['category']);
    $supplier_id = $conn->real_escape_string($_POST['supplier_id'] ?? '');
    $original_price = floatval($_POST['original_price']);
    $sale_price = floatval($_POST['sale_price']);
    $stock = intval($_POST['stock']);
    $barcode = $conn->real_escape_string($_POST['barcode'] ?? '');
    $color = $conn->real_escape_string($_POST['color'] ?? '');
    $specific_letter = $conn->real_escape_string($_POST['specific_letter'] ?? '');

    // Validate sale price
    if ($sale_price < $original_price) {
        echo json_encode(['success' => false, 'message' => 'Sale price cannot be less than original price!']);
        exit;
    }

    $photo = '';
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === 0) {
        $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
        $photo = "uploads/" . uniqid('prod_') . '.' . $ext;
        move_uploaded_file($_FILES['photo']['tmp_name'], $photo);
    }

    if (empty($product_id)) {
        // === ADD NEW PRODUCT ===
        $res = $conn->query("SELECT product_id FROM products ORDER BY CAST(SUBSTRING(product_id,3) AS UNSIGNED) DESC LIMIT 1");
        $last = $res->fetch_assoc();
        $num = $last ? intval(substr($last['product_id'], 2)) + 1 : 1;
        $product_id = "KB" . str_pad($num, 5, "0", STR_PAD_LEFT);

        $sql = "INSERT INTO products 
                (product_id, name, category, supplier_id, original_price, sale_price, stock, barcode, color, specific_letter, photo) 
                VALUES 
                ('$product_id', '$name', '$category', '$supplier_id', $original_price, $sale_price, $stock, '$barcode', '$color', '$specific_letter', '$photo')";

        $msg = "Product added! ID: $product_id";
    } else {
        // === UPDATE EXISTING PRODUCT ===
        $photo_sql = $photo ? ", photo='$photo'" : "";
        $sql = "UPDATE products SET 
                name='$name',
                category='$category',
                supplier_id='$supplier_id',
                original_price=$original_price,
                sale_price=$sale_price,
                stock=$stock,
                barcode='$barcode',
                color='$color',
                specific_letter='$specific_letter' $photo_sql
                WHERE product_id='$product_id'";

        $msg = "Product updated successfully!";
    }

    if ($conn->query($sql) === TRUE) {
        echo json_encode(['success' => true, 'message' => $msg]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database Error: ' . $conn->error]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Inventory - Kids Berry</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-purple: #8e44ad;
            --light-purple: #e8d4f7;
            --dark-purple: #6c3483;
            --primary-green: #27ae60;
            --light-green: #d5f5e3;
            --dark-green: #229954;
            --primary-blue: #3498db;
            --light-blue: #d6eaf8;
            --primary-orange: #e67e22;
            --light-orange: #fdebd0;
            --primary-red: #e74c3c;
            --light-red: #fadbd8;
            --primary-pink: #e84393;
            --light-pink: #fd79a8;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #495057;
            --shadow-light: 0 2px 8px rgba(0,0,0,0.08);
            --shadow-medium: 0 4px 15px rgba(0,0,0,0.12);
            --shadow-heavy: 0 8px 25px rgba(0,0,0,0.15);
            --border-radius: 12px;
            --border-radius-small: 8px;
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            --gradient-primary: linear-gradient(135deg, var(--primary-purple), var(--dark-purple));
            --gradient-secondary: linear-gradient(135deg, var(--primary-green), var(--dark-green));
            --gradient-blue: linear-gradient(135deg, var(--primary-blue), #2980b9);
            --gradient-orange: linear-gradient(135deg, var(--primary-orange), #d35400);
            --gradient-red: linear-gradient(135deg, var(--primary-red), #c0392b);
            --gradient-pink: linear-gradient(135deg, var(--primary-pink), #fd79a8);
            --gradient-light: linear-gradient(135deg, var(--light-purple), var(--light-green));
            --gradient-rainbow: linear-gradient(135deg, #8e44ad, #3498db, #27ae60, #e67e22, #e74c3c);
        }
        
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
            -webkit-tap-highlight-color: transparent;
        }
        
        html {
            height: 100%;
            overflow-x: hidden;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--gradient-light);
            min-height: 100vh;
            color: var(--dark-gray);
            line-height: 1.5;
            overflow-x: hidden;
            padding-bottom: env(safe-area-inset-bottom);
        }
        
        .container { 
            max-width: 100%; 
            margin: 0 auto; 
            padding: 10px; 
        }
        
        /* Header Styles - Mobile First */
        header {
            background: var(--gradient-primary);
            color: var(--white);
            padding: 0.8rem 0;
            box-shadow: var(--shadow-medium);
            position: sticky; 
            top: 0; 
            z-index: 1000;
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }
        
        .menu { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            flex-wrap: nowrap; 
            gap: 0.5rem; 
            overflow-x: auto;
            padding: 0 5px;
            -webkit-overflow-scrolling: touch;
        }
        
        .logo { 
            font-size: 1.4rem; 
            font-weight: 800; 
            display: flex; 
            align-items: center; 
            gap: 8px; 
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
            white-space: nowrap;
            flex-shrink: 0;
        }
        
        .nav-links {
            display: flex;
            flex-wrap: nowrap;
            gap: 0.3rem;
            align-items: center;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            padding: 5px 0;
        }
        
        .nav-links a {
            color: var(--white); 
            text-decoration: none; 
            padding: 8px 12px; 
            border-radius: 50px;
            transition: var(--transition); 
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255,255,255,0.2);
            white-space: nowrap;
            flex-shrink: 0;
        }
        
        .nav-links a:hover, 
        .nav-links a.active {
            background: var(--light-green); 
            color: var(--dark-purple); 
            transform: translateY(-2px);
            box-shadow: var(--shadow-light);
        }
        
        /* Logout Button Special Style */
        .logout-btn {
            background: var(--gradient-pink) !important;
            color: white !important;
            border: 1px solid rgba(255,255,255,0.3) !important;
            position: relative;
            overflow: hidden;
        }
        
        .logout-btn:hover {
            transform: translateY(-2px) scale(1.03);
            box-shadow: 0 5px 15px rgba(232, 67, 147, 0.4);
        }
        
        /* Search Box */
        .search-container {
            position: relative;
            width: 100%;
            max-width: 700px;
            margin: 20px auto;
        }
        
        .search-box {
            width: 100%;
            padding: 16px 20px 16px 45px;
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
        
        /* Main Layout */
        .main { 
            display: grid; 
            grid-template-columns: 1fr; 
            gap: 20px; 
            margin-top: 15px; 
        }
        
        /* Card Styles */
        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 18px;
            box-shadow: var(--shadow-light);
            transition: var(--transition);
            border: 1px solid var(--medium-gray);
            animation: fadeIn 0.5s ease-out;
            position: relative;
            overflow: hidden;
        }
        
        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--gradient-primary);
        }
        
        .card:hover {
            box-shadow: var(--shadow-medium);
            transform: translateY(-3px);
        }
        
        h2 { 
            color: var(--primary-purple); 
            margin-bottom: 15px; 
            font-size: 1.4rem; 
            display: flex; 
            align-items: center; 
            gap: 10px; 
            font-weight: 700;
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 18px;
            position: relative;
        }
        
        input, select, textarea {
            padding: 14px 16px;
            border-radius: var(--border-radius-small);
            font-size: 1rem;
            transition: var(--transition);
            border: 2px solid var(--medium-gray);
            width: 100%;
            background: var(--light-gray);
        }
        
        input:focus, select:focus, textarea:focus {
            border-color: var(--primary-purple);
            box-shadow: 0 0 0 3px rgba(142, 68, 173, 0.2);
            outline: none;
            background: var(--white);
        }
        
        .btn {
            padding: 14px 20px;
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
            transform: translateY(-3px);
            box-shadow: var(--shadow-medium);
        }
        
        /* Table Styles - Mobile Optimized */
        .table-container {
            overflow-x: auto;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-light);
            background: var(--white);
            -webkit-overflow-scrolling: touch;
        }
        
        table { 
            width: 100%; 
            border-collapse: collapse; 
            background: var(--white);
            min-width: 700px;
        }
        
        th { 
            background: var(--gradient-primary);
            color: var(--white); 
            padding: 14px 12px; 
            text-align: left; 
            font-weight: 600;
            position: sticky;
            left: 0;
            font-size: 0.85rem;
        }
        
        td { 
            padding: 12px; 
            border-bottom: 1px solid var(--medium-gray); 
            transition: var(--transition);
            font-size: 0.85rem;
        }
        
        tr {
            transition: var(--transition);
        }
        
        tr:active { 
            background: var(--light-green); 
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
        }
        
        .view-btn { 
            background: var(--primary-blue);
        }
        
        .edit-btn { 
            background: var(--primary-green);
        }
        
        .del-btn { 
            background: var(--primary-red);
        }
        
        .action-btn:active { 
            transform: translateY(-2px);
            box-shadow: var(--shadow-light);
        }
        
        /* Photo Preview */
        .photo-preview { 
            width: 60px; 
            height: 60px; 
            object-fit: cover; 
            border-radius: 10px; 
            border: 2px solid var(--light-purple);
            transition: var(--transition);
        }
        
        .photo-preview:active {
            transform: scale(1.1);
            border-color: var(--primary-purple);
        }
        
        /* Stock Badge */
        .stock-badge { 
            background: var(--gradient-secondary); 
            color: var(--white); 
            padding: 5px 10px; 
            border-radius: 20px; 
            font-weight: bold;
            display: inline-block;
            text-align: center;
            min-width: 40px;
            box-shadow: var(--shadow-light);
            font-size: 0.8rem;
        }
        
        .stock-low {
            background: var(--gradient-orange);
        }
        
        .stock-out {
            background: var(--gradient-red);
        }
        
        /* File Input Styling */
        .file-input-container {
            position: relative;
            overflow: hidden;
            display: inline-block;
            width: 100%;
        }
        
        .file-input-container input[type=file] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        
        .file-input-label {
            display: block;
            padding: 14px 16px;
            background: var(--light-gray);
            border: 2px dashed var(--medium-gray);
            border-radius: var(--border-radius-small);
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            color: var(--dark-gray);
        }
        
        .file-input-label:active {
            background: var(--light-purple);
            border-color: var(--primary-purple);
        }
        
        /* Modal Styles for Show Details */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 2000;
            overflow-y: auto;
            padding: 20px;
        }
        
        .modal-content {
            background: var(--white);
            margin: 2% auto;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-heavy);
            max-width: 600px;
            width: 100%;
            animation: modalFadeIn 0.3s ease-out;
            position: relative;
        }
        
        .modal-header {
            background: var(--gradient-primary);
            color: var(--white);
            padding: 20px;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 1.4rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .close-modal {
            background: none;
            border: none;
            color: var(--white);
            font-size: 1.5rem;
            cursor: pointer;
            transition: var(--transition);
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .close-modal:hover {
            background: rgba(255,255,255,0.2);
        }
        
        .modal-body {
            padding: 25px;
        }
        
        .detail-row {
            display: flex;
            margin-bottom: 15px;
            border-bottom: 1px solid var(--medium-gray);
            padding-bottom: 15px;
        }
        
        .detail-label {
            font-weight: 600;
            color: var(--primary-purple);
            min-width: 140px;
            flex-shrink: 0;
        }
        
        .detail-value {
            flex: 1;
            color: var(--dark-gray);
        }
        
        .detail-photo {
            text-align: center;
            margin-top: 10px;
        }
        
        .detail-photo img {
            max-width: 200px;
            max-height: 200px;
            border-radius: var(--border-radius-small);
            border: 3px solid var(--light-purple);
        }
        
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-50px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 30px 15px;
            color: var(--dark-gray);
        }
        
        .empty-state i {
            font-size: 2.5rem;
            color: var(--light-purple);
            margin-bottom: 12px;
        }
        
        .empty-state h3 {
            font-size: 1.1rem;
            margin-bottom: 8px;
            color: var(--primary-purple);
            justify-content: center;
        }
        
        /* Floating Action Button for Mobile */
        .fab {
            position: fixed;
            bottom: 25px;
            right: 25px;
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
            z-index: 100;
            cursor: pointer;
            transition: var(--transition);
            animation: pulse 2s infinite;
        }
        
        .fab:active {
            transform: scale(1.1) rotate(90deg);
        }
        
        /* Toggle Form Visibility on Mobile */
        @media (max-width: 991px) {
            .form-section {
                display: none;
            }
            
            .form-section.active {
                display: block;
            }
        }
        
        /* Loading Spinner */
        .spinner {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Success/Error Messages */
        .alert {
            padding: 12px;
            border-radius: var(--border-radius-small);
            margin-bottom: 15px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .alert-success {
            background: var(--light-green);
            color: var(--dark-green);
            border-left: 4px solid var(--primary-green);
        }
        
        .alert-error {
            background: #ffebee;
            color: #c62828;
            border-left: 4px solid #f44336;
        }
        
        /* Touch-friendly improvements */
        @media (hover: none) and (pointer: coarse) {
            .card:hover {
                transform: none;
            }
            
            .btn:hover {
                transform: none;
            }
            
            .nav-links a:hover {
                transform: none;
            }
            
            .logout-btn:hover {
                transform: none;
            }
            
            .action-btn:hover {
                transform: none;
            }
        }
        
        /* Tablet and Desktop Styles */
        @media (min-width: 768px) {
            .container {
                padding: 15px;
                max-width: 1400px;
            }
            
            header {
                padding: 1rem 0;
            }
            
            .menu {
                gap: 1rem;
                padding: 0;
                overflow-x: visible;
            }
            
            .logo {
                font-size: 1.8rem;
                gap: 12px;
            }
            
            .nav-links {
                gap: 0.5rem;
                overflow-x: visible;
                padding: 0;
            }
            
            .nav-links a {
                padding: 10px 18px;
                gap: 8px;
                font-size: 0.9rem;
            }
            
            .main { 
                grid-template-columns: 1fr 2.5fr; 
                gap: 25px; 
                margin-top: 20px; 
            }
            
            .card {
                padding: 25px;
            }
            
            h2 {
                font-size: 1.6rem;
                margin-bottom: 20px;
            }
            
            input, .btn {
                padding: 16px 18px;
            }
            
            .btn {
                font-size: 1.1rem;
            }
            
            th, td {
                padding: 18px 15px;
                font-size: 1rem;
            }
            
            .action-btn {
                padding: 10px 14px;
                font-size: 0.9rem;
            }
            
            .photo-preview { 
                width: 70px; 
                height: 70px; 
            }
            
            .search-box {
                padding: 18px 25px 18px 50px;
                font-size: 1.1rem;
            }
            
            .search-icon {
                font-size: 1.2rem;
            }
        }
        
        /* Very Small Mobile Devices */
        @media (max-width: 360px) {
            .container {
                padding: 8px;
            }
            
            .logo {
                font-size: 1.2rem;
            }
            
            .nav-links a {
                padding: 6px 10px;
                font-size: 0.75rem;
            }
            
            .card {
                padding: 15px;
            }
            
            .fab {
                width: 50px;
                height: 50px;
                font-size: 1.2rem;
                bottom: 20px;
                right: 20px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }
        
        /* Landscape Mode Optimizations */
        @media (max-height: 500px) and (orientation: landscape) {
            header {
                padding: 0.5rem 0;
            }
            
            .card {
                padding: 15px;
            }
        }
    </style>
</head>
<body>

<header>
    <div class="container menu">
        <div class="logo"><i class="fas fa-baby"></i> Kids Berry</div>
        <div class="nav-links">
            <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="product.php" class="active"><i class="fas fa-box"></i> Inventory</a>
            <a href="barcode.php"><i class="fas fa-barcode"></i> Barcode</a>
            <a href="customershow.php"><i class="fas fa-users"></i> Customers</a>
            <a href="suppliers.php"><i class="fas fa-truck"></i> Suppliers</a>
             <a href="stock_transactions1.php" ><i class="fas fa-arrows-spin"></i> Stock Transfer</a>
            <a href="report_keep.php"><i class="fas fa-chart-line"></i> Reports</a>
            <a href="?logout=true" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>
</header>

<div class="container">
    <div class="search-container">
        <i class="fas fa-search search-icon"></i>
        <input type="text" id="searchInput" class="search-box" placeholder="Search Product Name, Category, Barcode, ID...">
    </div>

    <div class="main">
        <!-- Form Section -->
        <div class="card form-section active" id="formSection">
            <h2 id="formTitle"><i class="fas fa-plus-circle"></i> Add New Product</h2>
            <form id="productForm" enctype="multipart/form-data">
                <input type="hidden" name="product_id" id="product_id">
                
                <div class="form-group">
                    <input type="text" name="name" id="name" placeholder="Product Name" required>
                </div>
                
                <div class="form-group">
                    <input type="text" name="category" id="category" list="categoryList" placeholder="Category (Type anything)" required>
                    <datalist id="categoryList">
                        <?php
                        $cats = $conn->query("SELECT DISTINCT category FROM products ORDER BY category");
                        while ($c = $cats->fetch_assoc()) echo "<option value=\"{$c['category']}\">";
                        ?>
                    </datalist>
                </div>
                
                <!-- Supplier Dropdown -->
                <div class="form-group">
                    <select name="supplier_id" id="supplier_id">
                        <option value="">Select Supplier (Optional)</option>
                        <?php
                        $suppliers = $conn->query("SELECT * FROM suppliers ORDER BY supplier_name");
                        while ($supplier = $suppliers->fetch_assoc()) {
                            echo "<option value=\"{$supplier['supplier_id']}\">{$supplier['supplier_name']}</option>";
                        }
                        ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <input type="number" step="0.01" name="original_price" id="original_price" placeholder="Original Price" required>
                </div>
                
                <div class="form-group">
                    <input type="number" step="0.01" name="sale_price" id="sale_price" placeholder="Sale Price" required>
                </div>
                
                <div class="form-group">
                    <input type="number" name="stock" id="stock" placeholder="Stock Quantity" required>
                </div>
                
                <div class="form-group">
                    <input type="text" name="barcode" id="barcode" placeholder="Barcode (Optional)">
                </div>
                
                <div class="form-group">
                    <input type="text" name="color" id="color" placeholder="Color (Optional)">
                </div>
                
                <!-- Specific Letter Field -->
                <div class="form-group">
                    <textarea name="specific_letter" id="specific_letter" placeholder="Specific Letter/Notes (Optional)" rows="3"></textarea>
                </div>
                
                <div class="form-group">
                    <div class="file-input-container">
                        <label class="file-input-label" for="photo">
                            <i class="fas fa-cloud-upload-alt"></i> Choose Product Photo (Optional)
                        </label>
                        <input type="file" name="photo" id="photo" accept="image/*">
                    </div>
                    <img id="preview" class="photo-preview" style="display:none; margin-top:15px;">
                </div>

                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <i class="fas fa-plus"></i> Add Product
                </button>
                
                <button type="button" class="btn btn-secondary" id="resetBtn" style="margin-top: 10px;">
                    <i class="fas fa-redo"></i> Reset Form
                </button>
            </form>
        </div>

        <!-- Table Section -->
        <div class="card">
            <h2><i class="fas fa-th-list"></i> Product List</h2>
            <div class="table-container">
                <table id="productTable">
                    <thead>
                        <tr>
                            <th>Photo</th>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Supplier</th>
                            <th>Sale Price</th>
                            <th>Stock</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $res = $conn->query("
                            SELECT p.*, s.supplier_name 
                            FROM products p 
                            LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id 
                            ORDER BY p.name
                        ");
                        if ($res->num_rows == 0):
                        ?>
                        <tr>
                            <td colspan="8">
                                <div class="empty-state">
                                    <i class="fas fa-box-open"></i>
                                    <h3>No Products Yet</h3>
                                    <p>Add your first product to get started!</p>
                                </div>
                            </td>
                        </tr>
                        <?php else: while($row = $res->fetch_assoc()): 
                            $stockClass = $row['stock'] < 10 ? 'stock-low' : '';
                        ?>
                        <tr>
                            <td>
                                <?php if($row['photo']): ?>
                                    <img src="<?=htmlspecialchars($row['photo'])?>" class="photo-preview">
                                <?php else: ?>
                                    <div style="width:60px; height:60px; background:var(--light-purple); border-radius:10px; display:flex; align-items:center; justify-content:center;">
                                        <i class="fas fa-image" style="font-size:20px; color:var(--primary-purple);"></i>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><strong><?=htmlspecialchars($row['product_id'])?></strong></td>
                            <td><?=htmlspecialchars($row['name'])?></td>
                            <td><?=htmlspecialchars($row['category'])?></td>
                            <td><?=htmlspecialchars($row['supplier_name'] ?: 'N/A')?></td>
                            <td>Rs. <?=number_format($row['sale_price'],2)?></td>
                            <td><span class="stock-badge <?=$stockClass?>"><?=$row['stock']?></span></td>
                            <td>
                                <div class="action-buttons">
                                    <button class="action-btn view-btn" onclick='showProduct(<?=json_encode($row)?>)'>
                                        <i class="fas fa-eye"></i> Show
                                    </button>
                                    <!-- <button class="action-btn edit-btn" onclick='editProduct(<?=json_encode($row)?>)'>
                                        <i class="fas fa-edit"></i> Edit
                                    </button> -->
                                    <button class="action-btn del-btn" onclick="deleteProduct('<?=$row['product_id']?>')">
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

<!-- Product Details Modal -->
<div id="productModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-info-circle"></i> Product Details</h3>
            <button class="close-modal">&times;</button>
        </div>
        <div class="modal-body" id="modalBody">
            <!-- Product details will be loaded here -->
        </div>
    </div>
</div>

<!-- Floating Action Button for Mobile -->
<div class="fab" id="fabToggle">
    <i class="fas fa-bars"></i>
</div>

<script>
// Photo Preview
document.getElementById('photo').addEventListener('change', function(e) {
    if (e.target.files[0]) {
        document.getElementById('preview').src = URL.createObjectURL(e.target.files[0]);
        document.getElementById('preview').style.display = 'block';
    }
});

// Price validation
document.getElementById('sale_price').addEventListener('blur', function() {
    const originalPrice = parseFloat(document.getElementById('original_price').value) || 0;
    const salePrice = parseFloat(this.value) || 0;
    
    if (originalPrice > 0 && salePrice > 0 && salePrice < originalPrice) {
        alert('Warning: Sale price is less than original price!');
        this.style.borderColor = 'var(--primary-red)';
    } else {
        this.style.borderColor = '';
    }
});

// Reset Form
function resetForm() {
    document.getElementById('productForm').reset();
    document.getElementById('product_id').value = '';
    document.getElementById('preview').style.display = 'none';
    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-plus"></i> Add Product';
    document.getElementById('submitBtn').className = 'btn btn-primary';
    document.getElementById('formTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Add New Product';
}

// Add event listener to reset button
document.getElementById('resetBtn').addEventListener('click', resetForm);

// Form Submit (Add & Update)
document.getElementById('productForm').onsubmit = async function(e) {
    e.preventDefault();
    
    // Price validation
    const originalPrice = parseFloat(document.getElementById('original_price').value) || 0;
    const salePrice = parseFloat(document.getElementById('sale_price').value) || 0;
    
    if (salePrice < originalPrice) {
        Swal.fire({
            title: 'Price Error!',
            text: 'Sale price cannot be less than original price!',
            icon: 'error',
            background: '#ffebee',
            color: '#c62828'
        });
        return false;
    }
    
    const submitBtn = document.getElementById('submitBtn');
    const originalText = submitBtn.innerHTML;
    
    // Show loading state
    submitBtn.innerHTML = '<span class="spinner"></span> Processing...';
    submitBtn.disabled = true;
    
    const formData = new FormData(this);

    try {
        const res = await fetch('', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
            Swal.fire({ 
                icon: 'success', 
                title: 'Success!', 
                text: data.message, 
                timer: 2000,
                showConfirmButton: false,
                background: 'var(--light-green)',
                color: 'var(--dark-green)'
            });
            setTimeout(() => location.reload(), 1500);
        } else {
            Swal.fire({
                title: 'Error!', 
                text: data.message, 
                icon: 'error',
                background: '#ffebee',
                color: '#c62828'
            });
        }
    } catch(err) {
        Swal.fire({
            title: 'Error!', 
            text: 'Network or server error!', 
            icon: 'error',
            background: '#ffebee',
            color: '#c62828'
        });
    } finally {
        // Restore button state
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
};

// Show Product Details
function showProduct(product) {
    const modal = document.getElementById('productModal');
    const modalBody = document.getElementById('modalBody');
    
    // Format stock status
    let stockStatus = '';
    let stockClass = '';
    if (product.stock >= 10) {
        stockStatus = 'In Stock';
        stockClass = 'stock-badge';
    } else if (product.stock > 0) {
        stockStatus = 'Low Stock';
        stockClass = 'stock-badge stock-low';
    } else {
        stockStatus = 'Out of Stock';
        stockClass = 'stock-badge stock-out';
    }
    
    // Create modal content
    modalBody.innerHTML = `
        <div class="detail-row">
            <div class="detail-label">Product ID:</div>
            <div class="detail-value"><strong>${product.product_id}</strong></div>
        </div>
        
        <div class="detail-row">
            <div class="detail-label">Product Name:</div>
            <div class="detail-value">${product.name}</div>
        </div>
        
        <div class="detail-row">
            <div class="detail-label">Category:</div>
            <div class="detail-value">${product.category}</div>
        </div>
        
        <div class="detail-row">
            <div class="detail-label">Supplier:</div>
            <div class="detail-value">${product.supplier_name || 'Not Assigned'}</div>
        </div>
        
        <div class="detail-row">
            <div class="detail-label">Original Price:</div>
            <div class="detail-value">Rs. ${parseFloat(product.original_price).toFixed(2)}</div>
        </div>
        
        <div class="detail-row">
            <div class="detail-label">Sale Price:</div>
            <div class="detail-value">Rs. ${parseFloat(product.sale_price).toFixed(2)}</div>
        </div>
        
        <div class="detail-row">
            <div class="detail-label">Stock Quantity:</div>
            <div class="detail-value">
                <span class="${stockClass}">${product.stock}</span> - ${stockStatus}
            </div>
        </div>
        
        <div class="detail-row">
            <div class="detail-label">Barcode:</div>
            <div class="detail-value">${product.barcode || 'Not Set'}</div>
        </div>
        
        <div class="detail-row">
            <div class="detail-label">Color:</div>
            <div class="detail-value">${product.color || 'Not Set'}</div>
        </div>
        
        <div class="detail-row">
            <div class="detail-label">Specific Letter/Notes:</div>
            <div class="detail-value">${product.specific_letter || 'No notes available'}</div>
        </div>
        
        <div class="detail-row">
            <div class="detail-label">Product Photo:</div>
            <div class="detail-value">
                ${product.photo ? 
                    `<div class="detail-photo">
                        <img src="${product.photo}" alt="${product.name}">
                    </div>` : 
                    '<div style="text-align:center; padding:20px; background:var(--light-gray); border-radius:var(--border-radius-small);">No Image Available</div>'
                }
            </div>
        </div>
    `;
    
    // Show modal
    modal.style.display = 'block';
    
    // Close modal when clicking the X button
    document.querySelector('.close-modal').onclick = function() {
        modal.style.display = 'none';
    };
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        if (event.target == modal) {
            modal.style.display = 'none';
        }
    };
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            modal.style.display = 'none';
        }
    });
}

// Edit Product
function editProduct(p) {
    document.getElementById('product_id').value = p.product_id;
    document.getElementById('name').value = p.name;
    document.getElementById('category').value = p.category;
    document.getElementById('supplier_id').value = p.supplier_id || '';
    document.getElementById('original_price').value = p.original_price;
    document.getElementById('sale_price').value = p.sale_price;
    document.getElementById('stock').value = p.stock;
    document.getElementById('barcode').value = p.barcode || '';
    document.getElementById('color').value = p.color || '';
    document.getElementById('specific_letter').value = p.specific_letter || '';

    if (p.photo) {
        document.getElementById('preview').src = p.photo;
        document.getElementById('preview').style.display = 'block';
    }

    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-sync"></i> Update Product';
    document.getElementById('submitBtn').className = 'btn btn-secondary';
    document.getElementById('formTitle').innerHTML = '<i class="fas fa-edit"></i> Update Product - ' + p.product_id;

    // On mobile, scroll to form
    if (window.innerWidth < 992) {
        document.getElementById('formSection').scrollIntoView({ behavior: 'smooth' });
    } else {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

// Delete Product
async function deleteProduct(id) {
    const result = await Swal.fire({
        title: 'Are you sure?',
        text: "You won't be able to revert this!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e74c3c',
        cancelButtonColor: '#95a5a6',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel',
        background: 'var(--white)'
    });

    if (result.isConfirmed) {
        const res = await fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'delete_id=' + id
        });
        const data = await res.json();
        if (data.success) {
            Swal.fire({
                title: 'Deleted!',
                text: 'Product has been deleted.',
                icon: 'success',
                timer: 1500,
                showConfirmButton: false,
                background: 'var(--light-green)',
                color: 'var(--dark-green)'
            }).then(() => location.reload());
        }
    }
}

// Live Search - INCLUDES BARCODE SEARCH
document.getElementById('searchInput').addEventListener('keyup', function() {
    let val = this.value.toLowerCase().trim();
    let found = false;
    
    document.querySelectorAll('#productTable tbody tr').forEach(row => {
        // Skip the empty state row
        if (row.querySelector('.empty-state')) {
            row.style.display = 'none';
            return;
        }
        
        // Get all text content from the row
        const rowText = row.textContent.toLowerCase();
        
        // Get specific columns for targeted search
        const productId = row.cells[1]?.textContent?.toLowerCase() || '';
        const productName = row.cells[2]?.textContent?.toLowerCase() || '';
        const category = row.cells[3]?.textContent?.toLowerCase() || '';
        const supplier = row.cells[4]?.textContent?.toLowerCase() || '';
        
        // Try to find barcode in the row - we need to extract it from the row data
        // Since barcode is not displayed in the table, we need to get it from data attributes
        const rowDataBarcode = row.getAttribute('data-barcode') || '';
        
        // Check if any field matches the search value
        const matches = 
            productId.includes(val) || 
            productName.includes(val) || 
            category.includes(val) || 
            supplier.includes(val) ||
            rowDataBarcode.includes(val) ||
            rowText.includes(val);
        
        if (matches) {
            row.style.display = '';
            found = true;
        } else {
            row.style.display = 'none';
        }
    });
    
    // Show/hide empty state based on search results
    const emptyState = document.querySelector('.empty-state');
    const tableBody = document.querySelector('#productTable tbody');
    
    if (!found && val !== '') {
        // Show custom empty state for search
        if (!emptyState || !emptyState.closest('tr')) {
            const noResultsRow = document.createElement('tr');
            noResultsRow.innerHTML = `
                <td colspan="8">
                    <div class="empty-state">
                        <i class="fas fa-search"></i>
                        <h3>No Products Found</h3>
                        <p>No products match "${val}"</p>
                    </div>
                </td>
            `;
            // Remove existing empty state if any
            document.querySelectorAll('#productTable tbody tr').forEach(row => {
                if (row.querySelector('.empty-state')) {
                    row.remove();
                }
            });
            tableBody.appendChild(noResultsRow);
        }
    } else if (val === '') {
        // If search is cleared, show all rows
        document.querySelectorAll('#productTable tbody tr').forEach(row => {
            if (!row.querySelector('.empty-state')) {
                row.style.display = '';
            }
        });
        // Remove search empty state
        document.querySelectorAll('#productTable tbody tr').forEach(row => {
            if (row.querySelector('.empty-state')) {
                if (row.querySelector('.fa-search')) {
                    row.remove();
                }
            }
        });
    }
});

// Add barcode data to each row for search functionality
document.addEventListener('DOMContentLoaded', function() {
    // Fetch barcode data for each product and add as data attribute
    <?php
    $barcodeQuery = $conn->query("SELECT product_id, barcode FROM products");
    $barcodeData = [];
    while ($row = $barcodeQuery->fetch_assoc()) {
        $barcodeData[$row['product_id']] = $row['barcode'];
    }
    ?>
    
    const barcodeData = <?php echo json_encode($barcodeData); ?>;
    
    // Add data-barcode attribute to each table row
    document.querySelectorAll('#productTable tbody tr').forEach(row => {
        const productIdCell = row.cells[1];
        if (productIdCell) {
            const productId = productIdCell.textContent.trim();
            if (barcodeData[productId]) {
                row.setAttribute('data-barcode', barcodeData[productId].toLowerCase());
            }
        }
    });
});

// Mobile Toggle for Form
document.getElementById('fabToggle').addEventListener('click', function() {
    const formSection = document.getElementById('formSection');
    formSection.classList.toggle('active');
    
    // Change icon based on state
    const icon = this.querySelector('i');
    if (formSection.classList.contains('active')) {
        icon.className = 'fas fa-times';
        formSection.scrollIntoView({ behavior: 'smooth' });
    } else {
        icon.className = 'fas fa-bars';
    }
});

// Auto-hide form on mobile after submission if it was opened by FAB
document.getElementById('productForm').addEventListener('submit', function() {
    if (window.innerWidth < 992) {
        setTimeout(() => {
            document.getElementById('formSection').classList.remove('active');
            document.getElementById('fabToggle').querySelector('i').className = 'fas fa-bars';
        }, 2000);
    }
});

// Add confirmation for logout
document.querySelector('.logout-btn').addEventListener('click', function(e) {
    if (!confirm('Are you sure you want to logout?')) {
        e.preventDefault();
    }
});

// Touch device improvements
if ('ontouchstart' in window) {
    document.addEventListener('touchstart', function() {}, {passive: true});
}

// Prevent zoom on double tap (iOS)
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
<?php $conn->close(); ?>