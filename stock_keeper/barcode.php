<?php
// File: barcode.php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['stock_keeper_id'])) {
    header("Location: ../index.php");
    exit();
}

// Handle logout
if (isset($_GET['logout'])) {
    unset($_SESSION['stock_keeper_id']);
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

// Handle AJAX search request
if (isset($_GET['ajax_search']) && isset($_GET['barcode'])) {
    $barcode = $conn->real_escape_string($_GET['barcode']);
    $response = array('success' => false, 'products' => array());
    
    if (!empty($barcode)) {
        // Search by barcode or product_id
        $search_query = "
            SELECT p.*, s.supplier_name,
            CASE 
                WHEN p.barcode IS NOT NULL AND p.barcode != '' THEN p.barcode
                ELSE p.product_id
            END as display_barcode
            FROM products p 
            LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id 
            WHERE p.barcode LIKE '%$barcode%' OR p.product_id LIKE '%$barcode%'
            ORDER BY 
                CASE 
                    WHEN p.barcode LIKE '$barcode%' THEN 1
                    WHEN p.product_id LIKE '$barcode%' THEN 2
                    ELSE 3
                END,
                p.name
            LIMIT 20
        ";
        
        $result = $conn->query($search_query);
        
        if ($result && $result->num_rows > 0) {
            $response['success'] = true;
            while ($product = $result->fetch_assoc()) {
                $response['products'][] = $product;
            }
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Handle barcode generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_barcodes'])) {
    $product_id = $conn->real_escape_string($_POST['product_id']);
    $quantity = intval($_POST['quantity']);
    
    // Get product details with supplier information
    $product_result = $conn->query("
        SELECT p.*, s.supplier_name 
        FROM products p 
        LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id 
        WHERE p.product_id='$product_id'
    ");
    
    if ($product_result->num_rows > 0) {
        $product = $product_result->fetch_assoc();
        
        // Use barcode from database, fallback to product_id if not set
        $barcode_value = $product['barcode'] ?: $product['product_id'];
        
        // Generate barcodes
        $barcodes = [];
        for ($i = 0; $i < $quantity; $i++) {
            $barcodes[] = [
                'product_id' => $product['product_id'],
                'name' => $product['name'],
                'sale_price' => $product['sale_price'],
                'barcode' => $barcode_value,
                'product_barcode' => $product['barcode'] ?: 'Not Set',
                'specific_letter' => $product['specific_letter'] ?: ''
            ];
        }
        
        $_SESSION['generated_barcodes'] = $barcodes;
        $_SESSION['barcode_message'] = "Successfully generated $quantity barcodes for " . $product['name'];
    } else {
        $_SESSION['barcode_error'] = "Product not found!";
    }
    
    header("Location: barcode.php");
    exit();
}

// Clear barcodes if requested
if (isset($_GET['clear_barcodes'])) {
    unset($_SESSION['generated_barcodes']);
    header("Location: barcode.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Barcode Generator - Kids Berry</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
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
        
        /* Barcode Scanner Input */
        .barcode-scanner-input {
            position: relative;
            margin-top: 10px;
        }
        
        .barcode-scanner-input .search-box {
            padding-left: 45px;
            padding-right: 45px;
        }
        
        .barcode-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary-purple);
            font-size: 1.1rem;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .barcode-icon:hover {
            color: var(--dark-purple);
            transform: translateY(-50%) scale(1.1);
        }
        
        /* Search Indicator */
        .search-indicator {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin: 10px 0;
            padding: 10px;
            background: var(--light-purple);
            border-radius: var(--border-radius-small);
            font-size: 0.9rem;
            color: var(--dark-purple);
        }
        
        .search-indicator i {
            color: var(--primary-purple);
        }
        
        /* Quantity Control */
        .quantity-control {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
        }
        
        .quantity-btn {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: var(--gradient-primary);
            color: white;
            border: none;
            font-size: 1.2rem;
            font-weight: bold;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            box-shadow: var(--shadow-light);
        }
        
        .quantity-btn:hover {
            transform: scale(1.1);
            box-shadow: var(--shadow-medium);
        }
        
        .quantity-input {
            flex: 1;
            text-align: center;
            font-size: 1.2rem;
            font-weight: bold;
            color: var(--dark-purple);
        }
        
        .quantity-presets {
            display: flex;
            gap: 8px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        
        .quantity-preset {
            padding: 6px 12px;
            background: var(--light-purple);
            border-radius: 20px;
            font-size: 0.9rem;
            cursor: pointer;
            transition: var(--transition);
            border: 2px solid transparent;
        }
        
        .quantity-preset:hover {
            background: var(--primary-purple);
            color: white;
            transform: translateY(-2px);
        }
        
        .quantity-preset.active {
            background: var(--primary-purple);
            color: white;
            border-color: var(--dark-purple);
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
        
        .btn-info {
            background: var(--gradient-blue);
        }
        
        .btn-danger {
            background: var(--gradient-red);
        }
        
        .btn:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-medium);
        }
        
        /* Product List Styles */
        .product-list-container {
            overflow-y: auto;
            max-height: 500px;
            border-radius: var(--border-radius-small);
            border: 1px solid var(--medium-gray);
            background: var(--white);
        }
        
        .product-item {
            padding: 15px;
            border-bottom: 1px solid var(--medium-gray);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .product-item:hover {
            background: var(--light-purple);
        }
        
        .product-item.selected {
            background: var(--light-green);
            border-left: 4px solid var(--primary-green);
        }
        
        .product-photo {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 8px;
            border: 2px solid var(--light-purple);
        }
        
        .product-details {
            flex: 1;
        }
        
        .product-name {
            font-weight: 600;
            color: var(--dark-purple);
            margin-bottom: 4px;
        }
        
        .product-id {
            font-size: 0.85rem;
            color: var(--dark-gray);
            margin-bottom: 4px;
        }
        
        .product-price {
            font-weight: 600;
            color: var(--primary-green);
        }
        
        .product-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 4px;
            font-size: 0.8rem;
        }
        
        .product-category, .product-supplier {
            background: var(--light-blue);
            padding: 2px 6px;
            border-radius: 4px;
            color: var(--primary-blue);
            font-weight: 500;
        }
        
        .product-category i, .product-supplier i {
            font-size: 0.75rem;
        }
        
        /* Barcode Highlight */
        .barcode-match {
            background-color: #ffeb3b;
            color: #000;
            padding: 2px 4px;
            border-radius: 3px;
            font-weight: bold;
        }
        
        /* Barcode Display Styles */
        .barcode-display-container {
            display: none;
            margin-top: 20px;
            background: white;
            border-radius: var(--border-radius);
            padding: 15px;
            box-shadow: var(--shadow-light);
        }
        
        .barcode-display-container.show {
            display: block;
        }
        
        .barcode-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 10px;
            margin-top: 15px;
        }
        
        /* Updated Zebra-compatible Barcode Label Styling - Centered Layout */
        .barcode-label {
            width: 180px;
            height: 90px;
            border: 1px solid #000;
            padding: 3px;
            font-family: Arial, sans-serif;
            page-break-inside: avoid;
            break-inside: avoid;
            background: white;
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            text-align: center;
        }
        
        .company-name {
            font-weight: bold;
            color: #000 !important;
            font-size: 11px !important;
            text-align: center;
            width: 100%;
            margin-bottom: 1px;
        }
        
        .barcode-product-name {
            font-weight: bold;
            width: 100%;
            text-align: center;
            color: #000 !important;
            font-size: 10px !important;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            margin-bottom: 1px;
        }
        
        .barcode-price {
            font-weight: bold;
            color: #000 !important;
            font-size: 12px !important;
            text-align: center;
            width: 100%;
            margin-bottom: 2px;
        }
        
        .barcode-image-container {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 1px 0;
            height: 40px;
        }
        
        .barcode-image {
            max-width: 100%;
            max-height: 35px;
        }
        
        .barcode-footer {
            font-size: 9px !important;
            text-align: center;
            margin-top: 1px;
            font-weight: bold;
            color: #000 !important;
            width: 100%;
            line-height: 1;
        }
        
        /* PRINT STYLES FOR ZEBRA PRINTER - FIXED for multiple rows */
        @media print {
            body * {
                visibility: hidden !important;
                margin: 0 !important;
                padding: 0 !important;
                background: none !important;
            }
            
            #barcodePrintSection, #barcodePrintSection * {
                visibility: visible !important;
                color: #000000 !important;
                font-weight: bold !important;
            }
            
            #barcodePrintSection {
                position: absolute !important;
                left: 0 !important;
                top: 0 !important;
                width: 100% !important;
                height: 100% !important;
                background: white !important;
                margin: 0 !important;
                padding: 0 !important;
                font-family: Arial, sans-serif !important;
            }
            
            .barcode-print-grid {
                display: grid !important;
                grid-template-columns: repeat(2, 50mm) !important;
                grid-auto-rows: 25mm !important;
                gap: 0mm !important;
                margin: 0mm !important;
                padding: 0mm !important;
                width: 100mm !important;
                height: auto !important;
                align-content: start !important;
            }
            
            .barcode-print-label {
                width: 50mm !important;
                height: 25mm !important;
                border: none !important;
                box-shadow: none !important;
                padding: 0.5mm !important;
                margin: 0 !important;
                break-inside: avoid !important;
                page-break-inside: avoid !important;
                background: white !important;
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                justify-content: space-between !important;
                text-align: center !important;
                box-sizing: border-box !important;
                position: relative !important;
            }
            
            /* Print header styling */
            .barcode-print-label .company-name {
                font-size: 10px !important;
                font-weight: bold !important;
                color: #000000 !important;
                text-align: center !important;
                width: 100% !important;
                margin-bottom: 0.2mm !important;
                line-height: 1 !important;
            }
            
            /* Print product name */
            .barcode-print-label .print-product-name {
                font-size: 9px !important;
                font-weight: bold !important;
                color: #000000 !important;
                text-align: center !important;
                width: 100% !important;
                margin-bottom: 0.2mm !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
                white-space: nowrap !important;
                line-height: 1 !important;
                max-height: 4mm !important;
            }
            
            /* Print price */
            .barcode-print-label .barcode-price {
                font-size: 11px !important;
                font-weight: bold !important;
                color: #000000 !important;
                text-align: center !important;
                width: 100% !important;
                margin-bottom: 0.3mm !important;
                line-height: 1 !important;
            }
            
            /* Print barcode image container */
            .barcode-print-label .barcode-image-container {
                width: 100% !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                margin: 0.3mm 0 !important;
                min-height: 9mm !important;
                max-height: 9mm !important;
                overflow: hidden !important;
            }
            
            .barcode-print-label .barcode-image {
                max-width: 95% !important;
                max-height: 9mm !important;
                height: auto !important;
            }
            
            /* Print footer */
            .barcode-print-label .barcode-footer {
                font-size: 9px !important;
                text-align: center !important;
                margin-top: 0.2mm !important;
                font-weight: bold !important;
                color: #000000 !important;
                line-height: 1 !important;
                width: 100% !important;
            }
            
            .no-print {
                display: none !important;
            }
            
            @page {
                size: 100mm auto !important;
                margin: 0mm !important;
                padding: 0mm !important;
            }
            
            /* Hide all non-essential elements during print */
            header, .search-container, .main, .fab, .preview-barcode, .alert, .barcode-display-container, .card, .container {
                display: none !important;
            }
            
            /* Ensure all text is black and bold */
            * {
                color: #000000 !important;
                font-weight: bold !important;
            }
        }
        
        /* Preview Barcode */
        .preview-barcode {
            text-align: center;
            padding: 20px;
            background: var(--light-gray);
            border-radius: var(--border-radius-small);
            margin-top: 15px;
            border: 2px dashed var(--medium-gray);
        }
        
        .preview-title {
            font-weight: 600;
            color: var(--primary-purple);
            margin-bottom: 10px;
        }
        
        /* Search Results Message */
        .search-results-msg {
            padding: 10px;
            margin-top: 10px;
            border-radius: var(--border-radius-small);
            background: var(--light-green);
            color: var(--dark-green);
            font-size: 0.9rem;
            display: none;
        }
        
        /* Sort Indicator */
        .sort-indicator {
            display: inline-block;
            margin-left: 5px;
            color: var(--primary-purple);
            font-size: 0.8rem;
        }
        
        /* Loading Spinner for AJAX */
        .ajax-loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(142, 68, 173, 0.3);
            border-radius: 50%;
            border-top-color: var(--primary-purple);
            animation: spin 1s ease-in-out infinite;
            margin-left: 8px;
            vertical-align: middle;
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
        
        @keyframes spin {
            to { transform: rotate(360deg); }
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
            
            .barcode-item:hover {
                transform: none;
            }
            
            .product-item:hover {
                background: inherit;
            }
            
            .product-item.selected {
                background: var(--light-green);
            }
            
            .quantity-btn:hover {
                transform: none;
            }
            
            .quantity-preset:hover {
                transform: none;
            }
            
            .barcode-icon:hover {
                transform: translateY(-50%);
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
                grid-template-columns: 1fr 2fr; 
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
            
            .barcode-grid {
                grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            }
            
            .product-photo {
                width: 60px;
                height: 60px;
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
            
            .product-item {
                padding: 12px;
            }
            
            .product-photo {
                width: 45px;
                height: 45px;
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
            <a href="product.php"><i class="fas fa-box"></i> Inventory</a>
            <a href="barcode.php" class="active"><i class="fas fa-barcode"></i> Barcode</a>
            <a href="customershow.php"><i class="fas fa-users"></i> Customers</a>
            <a href="suppliers.php"><i class="fas fa-truck"></i> Suppliers</a>
             <a href="stock_transactions1.php"><i class="fas fa-arrows-spin"></i> Stock Transfer</a>
            <a href="report_keep.php"><i class="fas fa-chart-line"></i> Reports</a>
            <a href="?logout=true" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>
</header>

<div class="container">
    <div class="search-container">
        <i class="fas fa-search search-icon"></i>
        <input type="text" id="searchInput" class="search-box" placeholder="Search by Name, Barcode, ID, Category or Supplier... Type numbers for auto-sort...">
    </div>
    
    <!-- Barcode Scanner Input -->
    <div class="search-container barcode-scanner-input">
        <i class="fas fa-barcode search-icon"></i>
        <input type="text" id="barcodeInput" class="search-box" placeholder="Scan or type barcode number (real-time search)...">
        <i class="fas fa-sync-alt barcode-icon" id="refreshBarcode"></i>
    </div>

    <div class="main">
        <!-- Form Section -->
        <div class="card form-section active" id="formSection">
            <h2><i class="fas fa-barcode"></i> Generate Barcodes</h2>
            
            <?php if (isset($_SESSION['barcode_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= $_SESSION['barcode_message'] ?>
                </div>
                <?php unset($_SESSION['barcode_message']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['barcode_error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?= $_SESSION['barcode_error'] ?>
                </div>
                <?php unset($_SESSION['barcode_error']); ?>
            <?php endif; ?>
            
            <form id="barcodeForm" method="POST">
                <input type="hidden" name="product_id" id="product_id">
                
                <div class="form-group">
                    <label for="quantity">Number of Barcodes</label>
                    <div class="quantity-control">
                        <button type="button" class="quantity-btn" id="decreaseBtn">-</button>
                        <input type="number" name="quantity" id="quantity" min="1" max="100" value="2" class="quantity-input" required>
                        <button type="button" class="quantity-btn" id="increaseBtn">+</button>
                    </div>
                    
                    <div class="quantity-presets">
                        <span class="quantity-preset" data-value="1">1</span>
                        <span class="quantity-preset active" data-value="2">2</span>
                        <span class="quantity-preset" data-value="5">5</span>
                        <span class="quantity-preset" data-value="10">10</span>
                        <span class="quantity-preset" data-value="20">20</span>
                        <span class="quantity-preset" data-value="50">50</span>
                    </div>
                    
                    <small style="color: var(--dark-gray); font-size: 0.9rem;">Maximum 100 barcodes per batch</small>
                </div>

                <button type="submit" name="generate_barcodes" class="btn btn-primary" id="generateBtn" disabled>
                    <i class="fas fa-qrcode"></i> Generate Barcodes
                </button>
            </form>
            
            <?php if (isset($_SESSION['generated_barcodes']) && !empty($_SESSION['generated_barcodes'])): ?>
                <div style="margin-top: 10px; display: flex; gap: 10px;">
                    <button type="button" class="btn btn-info" onclick="showPrintDialog()" style="flex: 1;">
                        <i class="fas fa-print"></i> Print Barcodes
                    </button>
                    <a href="?clear_barcodes=true" class="btn btn-danger" style="flex: 1;">
                        <i class="fas fa-trash"></i> Clear Barcodes
                    </a>
                </div>
                
                <!-- Barcode Display Section -->
                <div class="barcode-display-container" id="barcodeDisplayContainer">
                    <h3><i class="fas fa-barcode"></i> Generated Barcodes (Preview)</h3>
                    <div class="barcode-grid" id="barcodeGrid">
                        <?php foreach ($_SESSION['generated_barcodes'] as $index => $barcode): ?>
                            <div class="barcode-label">
                                <div class="company-name">Kids Berry</div>
                                <div class="barcode-product-name"><?= htmlspecialchars($barcode['name']) ?></div>
                                <div class="barcode-price">Rs. <?= number_format($barcode['sale_price'], 2) ?></div>
                                <div class="barcode-image-container">
                                    <svg class="barcode-image"
                                        jsbarcode-value="<?= $barcode['barcode'] ?>"
                                        jsbarcode-format="CODE128"
                                        jsbarcode-height="35"
                                        jsbarcode-width="1.2"
                                        jsbarcode-displayvalue="false"
                                        jsbarcode-margin="0"
                                        jsbarcode-font="Arial"
                                        jsbarcode-fontsize="12"
                                        jsbarcode-textmargin="0">
                                    </svg>
                                </div>
                                <div class="barcode-footer">
                                    <?php if(!empty($barcode['product_barcode']) && $barcode['product_barcode'] != 'Not Set'): ?>
                                        <?= htmlspecialchars($barcode['product_barcode']) ?>
                                        <?php if(!empty($barcode['specific_letter'])): ?>
                                            (<?= htmlspecialchars($barcode['specific_letter']) ?>)
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?= $barcode['product_id'] ?>
                                        <?php if(!empty($barcode['specific_letter'])): ?>
                                            (<?= htmlspecialchars($barcode['specific_letter']) ?>)
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Preview Barcode -->
            <div class="preview-barcode" id="previewBarcode" style="display: none;">
                <div class="preview-title">Selected Product Preview</div>
                <div class="barcode-label" style="margin: 0 auto;">
                    <div class="company-name">Kids Berry</div>
                    <div class="barcode-product-name" id="previewProductName">Product Name</div>
                    <div class="barcode-price" id="previewPrice">Rs. 0.00</div>
                    <div class="barcode-image-container">
                        <svg class="barcode-image" id="previewBarcodeImage"></svg>
                    </div>
                    <div class="barcode-footer" id="previewFooter"></div>
                </div>
            </div>
        </div>

        <!-- Products Section -->
        <div class="card">
            <h2><i class="fas fa-boxes"></i> Select Product 
                <span id="sortIndicator" class="sort-indicator" style="display:none;"></span>
                <span id="ajaxLoading" class="ajax-loading" style="display:none;"></span>
            </h2>
            
            <!-- AJAX Search Indicator -->
            <div id="ajaxSearchIndicator" class="search-indicator" style="display:none;">
                <i class="fas fa-search"></i>
                <span id="ajaxSearchText">Searching...</span>
            </div>
            
            <div class="product-list-container" id="productListContainer">
                <?php
                // Modified query to get all product information
                $products = $conn->query("
                    SELECT p.*, s.supplier_name,
                    CASE 
                        WHEN p.barcode IS NOT NULL AND p.barcode != '' THEN p.barcode
                        ELSE p.product_id
                    END as display_barcode
                    FROM products p 
                    LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id 
                    ORDER BY p.name
                    LIMIT 20
                ");
                if ($products->num_rows == 0):
                ?>
                <div class="empty-state">
                    <i class="fas fa-box-open"></i>
                    <h3>No Products Available</h3>
                    <p>Add products in inventory first</p>
                </div>
                <?php else: ?>
                    <?php while ($product = $products->fetch_assoc()): ?>
                        <div class="product-item" data-product='<?= json_encode($product) ?>' 
                             data-search-text="<?= htmlspecialchars(strtolower($product['name'] . ' ' . $product['product_id'] . ' ' . $product['barcode'] . ' ' . $product['category'] . ' ' . $product['supplier_name'] . ' ' . ($product['specific_letter'] ?? ''))) ?>">
                            <?php if($product['photo']): ?>
                                <img src="<?= htmlspecialchars($product['photo']) ?>" class="product-photo" alt="<?= htmlspecialchars($product['name']) ?>">
                            <?php else: ?>
                                <div style="width:50px; height:50px; background:var(--light-purple); border-radius:8px; display:flex; align-items:center; justify-content:center;">
                                    <i class="fas fa-image" style="font-size:18px; color:var(--primary-purple);"></i>
                                </div>
                            <?php endif; ?>
                            <div class="product-details">
                                <div class="product-name"><?= htmlspecialchars($product['name']) ?></div>
                                <div class="product-id">ID: <?= $product['product_id'] ?> 
                                    <?php if(!empty($product['specific_letter'])): ?>
                                        (<?= htmlspecialchars($product['specific_letter']) ?>)
                                    <?php endif; ?>
                                    | Barcode: <span class="barcode-value"><?= $product['barcode'] ?: $product['product_id'] ?></span>
                                </div>
                                <div class="product-meta">
                                    <?php if(!empty($product['category'])): ?>
                                        <span class="product-category">
                                            <i class="fas fa-tag"></i> <?= htmlspecialchars($product['category']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if(!empty($product['supplier_name'])): ?>
                                        <span class="product-supplier">
                                            <i class="fas fa-truck"></i> <?= htmlspecialchars($product['supplier_name']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="product-price">Rs. <?= number_format($product['sale_price'], 2) ?></div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Hidden Print Section for Zebra Printer -->
<div id="barcodePrintSection" style="display: none; visibility: hidden; position: absolute; left: -9999px; top: -9999px;">
    <?php if (isset($_SESSION['generated_barcodes']) && !empty($_SESSION['generated_barcodes'])): ?>
    <div class="barcode-print-grid">
        <?php foreach ($_SESSION['generated_barcodes'] as $index => $barcode): ?>
            <div class="barcode-print-label">
                <div class="company-name">Kids Berry</div>
                <div class="print-product-name"><?= htmlspecialchars($barcode['name']) ?></div>
                <div class="barcode-price">Rs. <?= number_format($barcode['sale_price'], 2) ?></div>
                <div class="barcode-image-container">
                    <svg class="barcode-image"
                        jsbarcode-value="<?= $barcode['barcode'] ?>"
                        jsbarcode-format="CODE128"
                        jsbarcode-height="50"
                        jsbarcode-width="2.5"
                        jsbarcode-displayvalue="false"
                        jsbarcode-margin="0"
                        jsbarcode-font="Arial"
                        jsbarcode-fontsize="10"
                        jsbarcode-textmargin="0">
                    </svg>
                </div>
                <div class="barcode-footer">
                    <?php if(!empty($barcode['product_barcode']) && $barcode['product_barcode'] != 'Not Set'): ?>
                        <?= htmlspecialchars($barcode['product_barcode']) ?>
                        <?php if(!empty($barcode['specific_letter'])): ?>
                            (<?= htmlspecialchars($barcode['specific_letter']) ?>)
                        <?php endif; ?>
                    <?php else: ?>
                        <?= $barcode['product_id'] ?>
                        <?php if(!empty($barcode['specific_letter'])): ?>
                            (<?= htmlspecialchars($barcode['specific_letter']) ?>)
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Floating Action Button for Mobile -->
<div class="fab no-print" id="fabToggle">
    <i class="fas fa-bars"></i>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Show barcode display if barcodes exist
    <?php if (isset($_SESSION['generated_barcodes']) && !empty($_SESSION['generated_barcodes'])): ?>
    setTimeout(function() {
        document.getElementById('barcodeDisplayContainer').classList.add('show');
        // Generate barcodes
        JsBarcode(".barcode-image").init();
    }, 500);
    <?php endif; ?>
    
    // Quantity control
    const quantityInput = document.getElementById('quantity');
    const decreaseBtn = document.getElementById('decreaseBtn');
    const increaseBtn = document.getElementById('increaseBtn');
    const quantityPresets = document.querySelectorAll('.quantity-preset');
    
    // Decrease quantity
    decreaseBtn.addEventListener('click', function() {
        let value = parseInt(quantityInput.value);
        if (value > 1) {
            quantityInput.value = value - 1;
            updateActivePreset();
        }
    });
    
    // Increase quantity
    increaseBtn.addEventListener('click', function() {
        let value = parseInt(quantityInput.value);
        if (value < 100) {
            quantityInput.value = value + 1;
            updateActivePreset();
        }
    });
    
    // Quantity presets
    quantityPresets.forEach(preset => {
        preset.addEventListener('click', function() {
            const value = this.getAttribute('data-value');
            quantityInput.value = value;
            updateActivePreset();
        });
    });
    
    // Update active preset
    function updateActivePreset() {
        const currentValue = parseInt(quantityInput.value);
        quantityPresets.forEach(preset => {
            if (parseInt(preset.getAttribute('data-value')) === currentValue) {
                preset.classList.add('active');
            } else {
                preset.classList.remove('active');
            }
        });
    }
    
    // Product selection
    function selectProduct(productElement) {
        // Remove selected class from all items
        document.querySelectorAll('.product-item').forEach(i => i.classList.remove('selected'));
        
        // Add selected class to clicked item
        productElement.classList.add('selected');
        
        // Get product data
        const selectedProduct = JSON.parse(productElement.getAttribute('data-product'));
        
        // Update form
        document.getElementById('product_id').value = selectedProduct.product_id;
        document.getElementById('generateBtn').disabled = false;
        
        // Show preview
        const previewBarcode = document.getElementById('previewBarcode');
        const previewImage = document.getElementById('previewBarcodeImage');
        const previewFooter = document.getElementById('previewFooter');
        
        // Get barcode value - use actual barcode if exists, otherwise use product_id
        const barcodeValue = selectedProduct.barcode || selectedProduct.product_id;
        
        // Clear previous barcode
        previewImage.innerHTML = '';
        
        // Generate preview barcode
        JsBarcode(previewImage, barcodeValue, {
            format: "CODE128",
            height: 35,
            width: 1.2,
            displayValue: false,
            margin: 0,
            font: "Arial",
            fontSize: 12,
            textMargin: 0
        });
        
        // Update preview details
        document.getElementById('previewProductName').textContent = selectedProduct.name.substring(0, 20) + (selectedProduct.name.length > 20 ? '...' : '');
        document.getElementById('previewPrice').textContent = 'Rs. ' + parseFloat(selectedProduct.sale_price).toFixed(2);
        
        // Update footer
        let footerText = '';
        if (selectedProduct.barcode) {
            footerText = selectedProduct.barcode;
            if (selectedProduct.specific_letter) {
                footerText += ' (' + selectedProduct.specific_letter + ')';
            }
        } else {
            footerText = selectedProduct.product_id;
            if (selectedProduct.specific_letter) {
                footerText += ' (' + selectedProduct.specific_letter + ')';
            }
        }
        previewFooter.textContent = footerText;
        
        // Show preview
        previewBarcode.style.display = 'block';
    }
    
    // AJAX Barcode Search Functionality
    const barcodeInput = document.getElementById('barcodeInput');
    const refreshBarcode = document.getElementById('refreshBarcode');
    const ajaxLoading = document.getElementById('ajaxLoading');
    const ajaxSearchIndicator = document.getElementById('ajaxSearchIndicator');
    const ajaxSearchText = document.getElementById('ajaxSearchText');
    let ajaxTimeout;
    let lastAjaxSearch = '';
    
    // Function to perform AJAX barcode search
    function performBarcodeSearch(barcode) {
        if (barcode === '') {
            // Reset to original products
            resetToOriginalProducts();
            return;
        }
        
        if (barcode === lastAjaxSearch) {
            return; // Don't search same thing again
        }
        
        lastAjaxSearch = barcode;
        
        // Show loading indicator
        ajaxLoading.style.display = 'inline-block';
        ajaxSearchIndicator.style.display = 'flex';
        ajaxSearchText.textContent = `Searching for barcode: ${barcode}`;
        
        // Perform AJAX request
        fetch(`?ajax_search=true&barcode=${encodeURIComponent(barcode)}`)
            .then(response => response.json())
            .then(data => {
                // Hide loading indicator
                ajaxLoading.style.display = 'none';
                
                if (data.success && data.products.length > 0) {
                    // Update search indicator
                    ajaxSearchText.innerHTML = `<i class="fas fa-check-circle"></i> Found ${data.products.length} product(s) for barcode: <span class="barcode-match">${barcode}</span>`;
                    ajaxSearchIndicator.style.background = 'var(--light-green)';
                    
                    // Update product list
                    updateProductList(data.products);
                    
                    // Auto-select first product if only one result
                    if (data.products.length === 1) {
                        setTimeout(() => {
                            const firstProduct = document.querySelector('.product-item');
                            if (firstProduct) {
                                selectProduct(firstProduct);
                                // Scroll to form on mobile
                                if (window.innerWidth < 992) {
                                    document.getElementById('formSection').scrollIntoView({ behavior: 'smooth' });
                                }
                            }
                        }, 300);
                    }
                } else {
                    // No results found
                    ajaxSearchText.innerHTML = `<i class="fas fa-times-circle"></i> No products found for barcode: <span class="barcode-match">${barcode}</span>`;
                    ajaxSearchIndicator.style.background = 'var(--light-red)';
                    
                    // Show empty state
                    const container = document.getElementById('productListContainer');
                    container.innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-barcode"></i>
                            <h3>No Products Found</h3>
                            <p>No products match barcode: ${barcode}</p>
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                ajaxLoading.style.display = 'none';
                ajaxSearchText.innerHTML = `<i class="fas fa-exclamation-circle"></i> Search error for barcode: ${barcode}`;
                ajaxSearchIndicator.style.background = 'var(--light-red)';
            });
    }
    
    // Function to update product list with AJAX results
    function updateProductList(products) {
        const container = document.getElementById('productListContainer');
        const searchQuery = barcodeInput.value.trim().toLowerCase();
        
        if (products.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-barcode"></i>
                    <h3>No Products Found</h3>
                    <p>No products match your search</p>
                </div>
            `;
            return;
        }
        
        let html = '';
        products.forEach(product => {
            const barcodeValue = product.barcode || product.product_id;
            const searchText = (product.name + ' ' + product.product_id + ' ' + product.barcode + ' ' + 
                              product.category + ' ' + product.supplier_name + ' ' + (product.specific_letter || '')).toLowerCase();
            
            // Highlight barcode match
            const highlightedBarcode = barcodeValue.toString().replace(
                new RegExp(searchQuery, 'gi'),
                match => `<span class="barcode-match">${match}</span>`
            );
            
            html += `
                <div class="product-item" data-product='${JSON.stringify(product)}' 
                     data-search-text="${searchText}">
                    ${product.photo ? 
                        `<img src="${product.photo}" class="product-photo" alt="${product.name}">` :
                        `<div style="width:50px; height:50px; background:var(--light-purple); border-radius:8px; display:flex; align-items:center; justify-content:center;">
                            <i class="fas fa-image" style="font-size:18px; color:var(--primary-purple);"></i>
                        </div>`
                    }
                    <div class="product-details">
                        <div class="product-name">${product.name}</div>
                        <div class="product-id">ID: ${product.product_id} 
                            ${product.specific_letter ? `(${product.specific_letter})` : ''}
                            | Barcode: <span class="barcode-value">${highlightedBarcode}</span>
                        </div>
                        <div class="product-meta">
                            ${product.category ? 
                                `<span class="product-category">
                                    <i class="fas fa-tag"></i> ${product.category}
                                </span>` : ''
                            }
                            ${product.supplier_name ? 
                                `<span class="product-supplier">
                                    <i class="fas fa-truck"></i> ${product.supplier_name}
                                </span>` : ''
                            }
                        </div>
                        <div class="product-price">Rs. ${parseFloat(product.sale_price).toFixed(2)}</div>
                    </div>
                </div>
            `;
        });
        
        container.innerHTML = html;
        
        // Reattach click events to new product items
        container.querySelectorAll('.product-item').forEach(item => {
            item.addEventListener('click', function() {
                selectProduct(this);
            });
        });
    }
    
    // Function to reset to original products
    function resetToOriginalProducts() {
        ajaxSearchIndicator.style.display = 'none';
        lastAjaxSearch = '';
        
        // Reload original products via AJAX
        performBarcodeSearch('');
    }
    
    // Barcode input event with debounce
    barcodeInput.addEventListener('input', function() {
        clearTimeout(ajaxTimeout);
        const barcode = this.value.trim();
        
        if (barcode.length > 0) {
            ajaxTimeout = setTimeout(() => {
                performBarcodeSearch(barcode);
            }, 300);
        } else {
            resetToOriginalProducts();
        }
    });
    
    // Refresh button to clear barcode search
    refreshBarcode.addEventListener('click', function() {
        barcodeInput.value = '';
        resetToOriginalProducts();
        barcodeInput.focus();
    });
    
    // Clear barcode search on Escape key
    barcodeInput.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            this.value = '';
            resetToOriginalProducts();
        }
    });
    
    // Auto-focus barcode input on page load for better UX
    setTimeout(() => {
        barcodeInput.focus();
    }, 500);
    
    // Attach click events to initial product items
    document.querySelectorAll('.product-item').forEach(item => {
        item.addEventListener('click', function() {
            selectProduct(this);
        });
    });
    
    // Enhanced Search with Debounce and Auto-Sort
    let searchTimeout;
    let lastSearchType = '';
    document.getElementById('searchInput').addEventListener('input', function() {
        clearTimeout(searchTimeout);
        
        searchTimeout = setTimeout(() => {
            let val = this.value.toLowerCase().trim();
            const sortIndicator = document.getElementById('sortIndicator');
            
            if (val === '') {
                // Reset to original order
                sortIndicator.style.display = 'none';
                sortIndicator.textContent = '';
                lastSearchType = '';
                
                document.querySelectorAll('.product-item').forEach(item => {
                    item.style.display = '';
                    // Remove highlighting
                    removeHighlighting(item);
                });
                showSearchResultsCount(-1, '');
                return;
            }
            
            let foundCount = 0;
            let allItems = Array.from(document.querySelectorAll('.product-item'));
            
            // Check if search is purely numbers (for barcode/ID search)
            const isNumberSearch = /^\d+$/.test(val);
            
            if (isNumberSearch) {
                // Sort by barcode number match
                sortIndicator.style.display = 'inline';
                sortIndicator.textContent = '(Sorted by barcode match)';
                lastSearchType = 'barcode';
                
                allItems.sort((a, b) => {
                    const productA = JSON.parse(a.getAttribute('data-product'));
                    const productB = JSON.parse(b.getAttribute('data-product'));
                    
                    // Get barcode values
                    const barcodeA = productA.barcode || productA.product_id;
                    const barcodeB = productB.barcode || productB.product_id;
                    
                    // Check which one starts with or contains the search number
                    const startsWithA = barcodeA.toString().startsWith(val);
                    const startsWithB = barcodeB.toString().startsWith(val);
                    const containsA = barcodeA.toString().includes(val);
                    const containsB = barcodeB.toString().includes(val);
                    
                    // Priority: starts with > contains > no match
                    if (startsWithA && !startsWithB) return -1;
                    if (!startsWithA && startsWithB) return 1;
                    if (containsA && !containsB) return -1;
                    if (!containsA && containsB) return 1;
                    
                    // If same match level, sort by barcode
                    return barcodeA.toString().localeCompare(barcodeB.toString());
                });
            } else {
                // Regular search - sort by relevance
                sortIndicator.style.display = 'inline';
                sortIndicator.textContent = '(Sorted by relevance)';
                lastSearchType = 'relevance';
                
                allItems.sort((a, b) => {
                    const searchTextA = a.getAttribute('data-search-text');
                    const searchTextB = b.getAttribute('data-search-text');
                    
                    // Check exact matches first
                    if (searchTextA === val && searchTextB !== val) return -1;
                    if (searchTextA !== val && searchTextB === val) return 1;
                    
                    // Check starts with
                    if (searchTextA.startsWith(val) && !searchTextB.startsWith(val)) return -1;
                    if (!searchTextA.startsWith(val) && searchTextB.startsWith(val)) return 1;
                    
                    // Check contains
                    const indexA = searchTextA.indexOf(val);
                    const indexB = searchTextB.indexOf(val);
                    
                    if (indexA !== -1 && indexB === -1) return -1;
                    if (indexA === -1 && indexB !== -1) return 1;
                    
                    // If both contain, sort by position
                    if (indexA !== -1 && indexB !== -1) {
                        return indexA - indexB;
                    }
                    
                    // Finally sort by name
                    const productA = JSON.parse(a.getAttribute('data-product'));
                    const productB = JSON.parse(b.getAttribute('data-product'));
                    return productA.name.localeCompare(productB.name);
                });
            }
            
            // Reorder items in container
            const container = document.getElementById('productListContainer');
            allItems.forEach(item => {
                container.appendChild(item);
            });
            
            // Show/hide items based on search
            allItems.forEach(item => {
                const searchText = item.getAttribute('data-search-text');
                const productData = JSON.parse(item.getAttribute('data-product'));
                
                // Get barcode value for number search
                const barcodeValue = (productData.barcode || productData.product_id).toString().toLowerCase();
                
                let matches = false;
                
                if (isNumberSearch) {
                    // For number search, check barcode and product_id
                    matches = barcodeValue.includes(val) || 
                             productData.product_id.toLowerCase().includes(val);
                } else {
                    // For text search, check all fields
                    matches = searchText.includes(val);
                }
                
                if (matches) {
                    item.style.display = '';
                    foundCount++;
                    
                    // Highlight matching text
                    highlightText(item, val);
                } else {
                    item.style.display = 'none';
                    removeHighlighting(item);
                }
            });
            
            // Show message if no results found
            showSearchResultsCount(foundCount, val);
            
        }, 300);
    });
    
    // Function to highlight matching text
    function highlightText(element, searchTerm) {
        const textElements = element.querySelectorAll('.product-name, .product-id, .barcode-value, .product-meta');
        
        textElements.forEach(el => {
            const originalText = el.textContent;
            const regex = new RegExp(`(${searchTerm})`, 'gi');
            const highlightedText = originalText.replace(regex, '<mark style="background:#FFEB3B; padding:1px 3px; border-radius:2px;">$1</mark>');
            el.innerHTML = highlightedText;
        });
    }
    
    // Function to remove highlighting
    function removeHighlighting(element) {
        const textElements = element.querySelectorAll('.product-name, .product-id, .barcode-value, .product-meta');
        
        textElements.forEach(el => {
            el.innerHTML = el.textContent; // This removes any HTML tags
        });
    }
    
    // Function to show search results count
    function showSearchResultsCount(count, term) {
        let resultsMsg = document.getElementById('searchResultsMsg');
        
        if (!resultsMsg) {
            resultsMsg = document.createElement('div');
            resultsMsg.id = 'searchResultsMsg';
            resultsMsg.className = 'search-results-msg';
            
            const productList = document.getElementById('productListContainer');
            productList.parentNode.insertBefore(resultsMsg, productList);
        }
        
        if (term === '' || count === -1) {
            resultsMsg.style.display = 'none';
        } else {
            if (count === 0) {
                resultsMsg.innerHTML = `<i class="fas fa-search"></i> No products found for "${term}"`;
                resultsMsg.style.background = 'var(--light-red)';
                resultsMsg.style.color = 'var(--primary-red)';
            } else {
                const sortInfo = lastSearchType === 'barcode' ? ' (Barcode sorted)' : ' (Relevance sorted)';
                resultsMsg.innerHTML = `<i class="fas fa-check-circle"></i> Found ${count} product(s) for "${term}"${sortInfo}`;
                resultsMsg.style.background = 'var(--light-green)';
                resultsMsg.style.color = 'var(--dark-green)';
            }
            resultsMsg.style.display = 'block';
        }
    }
    
    // Clear search on Escape key
    document.getElementById('searchInput').addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            this.value = '';
            this.dispatchEvent(new Event('input'));
        }
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
    document.getElementById('barcodeForm').addEventListener('submit', function() {
        if (window.innerWidth < 992) {
            setTimeout(() => {
                document.getElementById('formSection').classList.remove('active');
                document.getElementById('fabToggle').querySelector('i').className = 'fas fa-bars';
            }, 2000);
        }
    });
    
    // Logout confirmation
    document.querySelector('.logout-btn').addEventListener('click', function(e) {
        if (!confirm('Are you sure you want to logout?')) {
            e.preventDefault();
        }
    });
});

// Show print dialog directly
function showPrintDialog() {
    // First, ensure all barcodes are generated
    JsBarcode(".barcode-image").init();
    
    // Wait a moment for barcodes to render
    setTimeout(function() {
        // Show SweetAlert2 confirmation dialog
        Swal.fire({
            title: 'Print Barcodes',
            html: '<div style="text-align:left; font-size:14px; margin:15px 0;">' +
                  '<p><strong>Printer Setup Instructions:</strong></p>' +
                  '<ol style="padding-left:20px; margin:10px 0;">' +
                  '<li>Ensure Zebra printer is connected</li>' +
                  '<li>Load 50mm × 25mm sticker paper</li>' +
                  '<li>Set page size to 100mm × auto</li>' +
                  '<li>Set margins to 0 (zero)</li>' +
                  '</ol>' +
                  '<p style="color:#8e44ad; font-weight:bold;">Each label: 5cm × 2.5cm (2 per row)</p>' +
                  '<p style="color:#27ae60; font-weight:bold;">Supports multiple rows without gaps</p>' +
                  '</div>',
            icon: 'info',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Ready to Print',
            cancelButtonText: 'Cancel',
            width: 500
        }).then((result) => {
            if (result.isConfirmed) {
                // Prepare print section
                const printSection = document.getElementById('barcodePrintSection');
                if (!printSection) {
                    alert('No barcodes to print!');
                    return;
                }
                
                // Make sure all barcodes in print section are generated
                JsBarcode(printSection.querySelectorAll('.barcode-image')).init();
                
                // Wait for barcodes to render
                setTimeout(function() {
                    // Create a new window for printing
                    const printWindow = window.open('', '_blank');
                    
                    // Create optimized print content for Zebra printer
                    const printContent = `
    <!DOCTYPE html>
    <html>
    <head>
        <title>Print Barcodes - Kids Berry</title>
        <meta charset="UTF-8">
        <style>
            /* Zebra Printer Optimized Styles for Multiple Rows */
            body {
                margin: 0;
                padding: 0;
                width: 100mm;
                font-family: Arial, sans-serif;
                background: white;
                color: #000000 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .barcode-print-grid {
                display: grid;
                grid-template-columns: repeat(2, 50mm);
                grid-auto-rows: 25mm;
                gap: 0mm;
                margin: 0;
                padding: 0;
                width: 100mm;
                height: auto;
                align-content: start;
            }
            
            .barcode-print-label {
                width: 50mm;
                height: 25mm;
                padding: 0.5mm 1mm;
                margin: 0;
                box-sizing: border-box;
                break-inside: avoid;
                page-break-inside: avoid;
                border: 1px solid #ccc;
                background: white;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: space-between;
                text-align: center;
                overflow: hidden;
            }
            
            .company-name {
                font-size: 10px !important;
                font-weight: bold !important;
                color: #000000 !important;
                text-align: center !important;
                width: 100% !important;
                margin-bottom: 0.2mm !important;
                line-height: 1 !important;
            }
            
            /* FIXED: Product name styling */
            .print-product-name {
                font-size: 9px !important;
                font-weight: bold !important;
                color: #000000 !important;
                text-align: center !important;
                width: 100% !important;
                margin-bottom: 0.2mm !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
                white-space: nowrap !important;
                line-height: 1 !important;
                max-height: 4mm !important;
            }
            
            .barcode-price {
                font-size: 11px !important;
                font-weight: bold !important;
                color: #000000 !important;
                text-align: center !important;
                width: 100% !important;
                margin-bottom: 0.3mm !important;
                line-height: 1 !important;
            }
            
            .barcode-image-container {
                width: 100% !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                margin: 0.3mm 0 !important;
                min-height: 9mm !important;
                max-height: 9mm !important;
                overflow: hidden !important;
            }
            
            .barcode-image {
                max-width: 95% !important;
                max-height: 9mm !important;
                height: auto !important;
            }
            
            .barcode-footer {
                font-size: 9px !important;
                text-align: center !important;
                margin-top: 0.2mm !important;
                font-weight: bold !important;
                color: #000000 !important;
                line-height: 1 !important;
                width: 100% !important;
            }
            
            @page {
                size: 100mm auto;
                margin: 0mm;
            }
            
            @media print {
                body {
                    margin: 0 !important;
                    padding: 0 !important;
                    width: 100mm !important;
                }
                
                .barcode-print-label {
                    border: 1px solid #ccc !important;
                }
            }
        </style>
        <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"><\/script>
    </head>
    <body onload="initPrint()">
        ${printSection.innerHTML}
        <script>
            function initPrint() {
                // Initialize barcodes
                JsBarcode(".barcode-image", {
                    format: "CODE128",
                    height: 32,
                    width: 1.1,
                    displayValue: false,
                    margin: 0,
                    font: "Arial",
                    fontSize: 10,
                    textMargin: 0
                }).init();
                
                // Print after a short delay
                setTimeout(function() {
                    window.print();
                    
                    // Close window after printing
                    setTimeout(function() {
                        window.close();
                    }, 500);
                }, 300);
            }
        <\/script>
    </body>
    </html>
`;
                    
                    printWindow.document.open();
                    printWindow.document.write(printContent);
                    printWindow.document.close();
                    
                }, 300);
            }
        });
    }, 200);
}

// Simple print function as fallback
function simplePrint() {
    window.print();
}
</script>

</body>
</html>
<?php $conn->close(); ?>