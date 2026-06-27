<?php
// File: barcode1.php - COMPLETE REDESIGN with dark green & white theme
// Consistent with dashboard and inventory pages
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
        // Search by barcode or product_id in products2 table
        $search_query = "
            SELECT p.*, s.supplier_name,
            CASE 
                WHEN p.barcode IS NOT NULL AND p.barcode != '' THEN p.barcode
                ELSE p.product_id
            END as display_barcode
            FROM products2 p 
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
    
    // Get product details from products2 table with supplier information
    $product_result = $conn->query("
        SELECT p.*, s.supplier_name 
        FROM products2 p 
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
    
    header("Location: barcode1.php");
    exit();
}

// Clear barcodes if requested
if (isset($_GET['clear_barcodes'])) {
    unset($_SESSION['generated_barcodes']);
    header("Location: barcode1.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>📦 Barcode Studio · KidsBerry Mega Centre</title>
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <!-- SweetAlert2 & JsBarcode -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ----------------------------------------------------------------------
           DARK GREEN & WHITE · MODERN · TRENDY · FULLY RESPONSIVE
           - Consistent with Dashboard and Inventory pages
        ----------------------------------------------------------------------- */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --dark-green: #1a3b2f;
            --forest-green: #2c5e2e;
            --sage: #8aae8c;
            --light-mint: #eaf7ea;
            --pure-white: #ffffff;
            --off-white: #f9fbf7;
            --charcoal: #2c3e2f;
            --coral-soft: #e67e22;
            --shadow-sm: 0 10px 25px -5px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 20px 30px -12px rgba(26, 59, 47, 0.12);
            --radius-card: 24px;
            --radius-element: 16px;
            --transition-smooth: all 0.3s cubic-bezier(0.2, 0.9, 0.4, 1.1);
        }

        body {
            font-family: 'Inter', 'Outfit', system-ui, sans-serif;
            background: var(--off-white);
            color: #1e2a24;
            line-height: 1.5;
            min-height: 100vh;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px 24px;
        }

        /* ===== HEADER (matches dashboard) ===== */
        header {
            background: var(--dark-green);
            color: white;
            padding: 0.7rem 0;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid rgba(138, 174, 140, 0.3);
        }

        .menu {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            padding: 0 20px;
        }

        .logo {
            font-size: 1.65rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 10px;
            font-family: 'Outfit', sans-serif;
        }
        .logo i {
            color: var(--sage);
        }
        .logo span {
            font-size: 0.9rem;
            background: rgba(255, 255, 255, 0.15);
            padding: 2px 10px;
            border-radius: 40px;
            margin-left: 8px;
        }

        .nav-links {
            display: flex;
            gap: 0.3rem;
            flex-wrap: wrap;
        }
        .nav-links a {
            color: white;
            text-decoration: none;
            padding: 8px 18px;
            border-radius: 40px;
            font-weight: 500;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.05);
            transition: var(--transition-smooth);
        }
        .nav-links a:hover, .nav-links a.active {
            background: white;
            color: var(--dark-green);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        .logout-btn {
            background: rgba(255, 255, 255, 0.15) !important;
        }
        .logout-btn:hover {
            background: #ff5e4a !important;
            color: white !important;
        }

        /* ----- SEARCH SECTION ----- */
        .search-container {
            position: relative;
            width: 100%;
            max-width: 700px;
            margin: 20px auto;
        }
        .search-box {
            width: 100%;
            padding: 16px 25px 16px 55px;
            border-radius: 80px;
            border: none;
            font-size: 1rem;
            background: var(--pure-white);
            box-shadow: var(--shadow-sm);
            outline: none;
            border: 2px solid rgba(44, 94, 46, 0.1);
            transition: var(--transition-smooth);
            color: var(--dark-green);
            font-weight: 500;
        }
        .search-box:focus {
            border-color: var(--forest-green);
            box-shadow: 0 0 0 4px rgba(44, 94, 46, 0.08);
        }
        .search-icon {
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--forest-green);
            font-size: 1.3rem;
        }
        .barcode-icon {
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--forest-green);
            font-size: 1.3rem;
            cursor: pointer;
            transition: 0.2s;
        }
        .barcode-icon:hover {
            color: var(--coral-soft);
            transform: translateY(-50%) scale(1.1);
        }

        /* ----- MAIN GRID ----- */
        .main {
            display: grid;
            grid-template-columns: 1fr 1.8fr;
            gap: 30px;
            margin-top: 15px;
        }

        /* CARD STYLES */
        .card {
            background: var(--pure-white);
            border-radius: var(--radius-card);
            padding: 28px;
            box-shadow: var(--shadow-sm);
            border: 1px solid #ecf3e8;
        }
        .card:hover {
            box-shadow: var(--shadow-md);
            border-color: var(--sage);
        }

        h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.7rem;
            font-weight: 700;
            color: var(--dark-green);
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 3px solid var(--light-mint);
            padding-bottom: 12px;
        }
        h2 i {
            color: var(--forest-green);
        }

        /* Quantity Control */
        .quantity-control {
            display: flex;
            align-items: center;
            gap: 15px;
            background: var(--light-mint);
            padding: 8px 18px;
            border-radius: 60px;
            margin-bottom: 18px;
        }
        .quantity-btn {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: var(--forest-green);
            color: white;
            border: 2px solid white;
            font-size: 1.8rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: 0.2s;
        }
        .quantity-btn:hover {
            background: var(--dark-green);
            transform: scale(0.95);
        }
        .quantity-input {
            flex: 1;
            text-align: center;
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--dark-green);
            background: transparent;
            border: none;
            outline: none;
        }
        .quantity-presets {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 8px;
        }
        .quantity-preset {
            padding: 10px 18px;
            background: var(--pure-white);
            border-radius: 40px;
            font-weight: 600;
            color: var(--forest-green);
            border: 2px solid var(--sage);
            cursor: pointer;
            transition: 0.2s;
        }
        .quantity-preset.active, .quantity-preset:hover {
            background: var(--forest-green);
            color: white;
            border-color: var(--forest-green);
        }

        /* BUTTONS */
        .btn {
            padding: 14px 24px;
            border-radius: 60px;
            font-size: 1rem;
            font-weight: 700;
            border: none;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            width: 100%;
            transition: var(--transition-smooth);
            cursor: pointer;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--forest-green), #3e7c41);
        }
        .btn-secondary {
            background: linear-gradient(135deg, var(--dark-green), #2c5e2e);
        }
        .btn-danger {
            background: linear-gradient(135deg, #d9534f, #c9302c);
        }

        /* PRODUCT LIST */
        .product-list-container {
            max-height: 550px;
            overflow-y: auto;
            border-radius: 20px;
            padding: 5px;
        }
        .product-item {
            background: var(--pure-white);
            padding: 18px;
            border-radius: 20px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: var(--transition-smooth);
            cursor: pointer;
            border: 1px solid #eef2e8;
            box-shadow: var(--shadow-sm);
        }
        .product-item:hover {
            border-color: var(--sage);
            transform: translateX(4px);
            background: var(--off-white);
        }
        .product-item.selected {
            background: var(--light-mint);
            border-left: 5px solid var(--forest-green);
        }
        .product-photo {
            width: 70px;
            height: 70px;
            object-fit: cover;
            border-radius: 18px;
            border: 2px solid var(--light-mint);
        }
        .product-name {
            font-weight: 800;
            color: var(--dark-green);
            font-size: 1.1rem;
            margin-bottom: 4px;
        }
        .product-id {
            font-size: 0.8rem;
            color: #6c7e70;
            font-weight: 500;
        }
        .product-price {
            font-weight: 800;
            color: var(--forest-green);
            font-size: 1.2rem;
            margin-top: 5px;
        }
        .barcode-match {
            background: var(--light-mint);
            padding: 2px 8px;
            border-radius: 30px;
            font-weight: 700;
            color: var(--forest-green);
            font-size: 0.7rem;
        }

        /* Barcode Preview */
        .preview-barcode {
            background: var(--light-mint);
            border-radius: 24px;
            padding: 20px;
            margin-top: 25px;
            border: 2px solid var(--sage);
        }
        .barcode-label {
            width: 200px;
            background: var(--pure-white);
            border-radius: 16px;
            padding: 12px 8px;
            border: 1px solid #e0e8e0;
            box-shadow: var(--shadow-sm);
            font-family: 'Inter', monospace;
            display: flex;
            flex-direction: column;
            align-items: center;
            margin: 0 auto;
        }
        .company-name {
            font-weight: 800;
            color: var(--dark-green);
            font-size: 12px;
        }
        .barcode-price {
            color: var(--forest-green);
            font-size: 14px;
            font-weight: 800;
        }
        .barcode-footer {
            font-size: 9px;
            color: #6c7e70;
            font-weight: 600;
        }

        /* Generated Barcodes Grid */
        .generated-barcodes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        /* Message Boxes */
        .success-message {
            background: var(--light-mint);
            color: var(--forest-green);
            padding: 12px 16px;
            border-radius: 50px;
            margin-bottom: 20px;
            font-weight: 600;
            border-left: 4px solid var(--forest-green);
        }
        .error-message {
            background: #ffe2dd;
            color: #d9534f;
            padding: 12px 16px;
            border-radius: 50px;
            margin-bottom: 20px;
            font-weight: 600;
            border-left: 4px solid #d9534f;
        }

        /* ========== ZEBRA PRINTER COMPATIBLE PRINT STYLES ========== */
        @media print {
            body * {
                visibility: hidden !important;
                margin: 0 !important;
                padding: 0 !important;
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
            }
            
            .barcode-print-label .company-name { font-size: 10px !important; font-weight: bold !important; }
            .barcode-print-label .print-product-name { font-size: 9px !important; font-weight: bold !important; overflow: hidden !important; white-space: nowrap !important; }
            .barcode-print-label .barcode-price { font-size: 11px !important; font-weight: bold !important; }
            .barcode-print-label .barcode-image-container { width: 100% !important; display: flex !important; justify-content: center !important; margin: 0.3mm 0 !important; }
            .barcode-print-label .barcode-image { max-width: 95% !important; max-height: 9mm !important; }
            .barcode-print-label .barcode-footer { font-size: 9px !important; text-align: center !important; font-weight: bold !important; }
            
            @page {
                size: 100mm auto !important;
                margin: 0mm !important;
            }
            
            header, .search-container, .main, .fab, .preview-barcode, .success-message, .error-message, .card, .container {
                display: none !important;
            }
        }

        /* FAB for mobile */
        .fab {
            position: fixed;
            bottom: 24px;
            right: 24px;
            width: 56px;
            height: 56px;
            background: var(--forest-green);
            border-radius: 50%;
            display: none;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.6rem;
            box-shadow: 0 8px 20px rgba(44, 94, 46, 0.3);
            z-index: 99;
            cursor: pointer;
            transition: 0.2s;
        }
        .fab:active {
            transform: scale(0.95);
        }

        .ajax-loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(44, 94, 46, 0.2);
            border-radius: 50%;
            border-top-color: var(--forest-green);
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #8aae8c;
        }
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
        }

        @media (max-width: 992px) {
            .main { grid-template-columns: 1fr; }
            .container { padding: 16px; }
            .fab { display: flex; }
        }
        @media (max-width: 576px) {
            .product-item { flex-direction: column; text-align: center; }
            .product-photo { width: 80px; height: 80px; }
        }
    </style>
</head>
<body>

<header>
    <div class="container menu">
        <div class="logo">
            <i class="fas fa-seedling"></i> KidsBerry Mega Centre
            <span>Mega Centre</span>
        </div>
        <div class="nav-links">
            <a href="dashboard1.php"><i class="fas fa-chart-pie"></i> Dashboard</a>
            <a href="product1.php"><i class="fas fa-cubes"></i> Inventory</a>
            <a href="stock_transactions.php"><i class="fas fa-arrows-spin"></i> Stock Transfer</a>
            <a href="barcode1.php" class="active"><i class="fas fa-qrcode"></i> Barcode Studio</a>
            <a href="suppliers1.php"><i class="fas fa-parachute-box"></i> Suppliers</a>
            <a href="?logout=true" class="logout-btn"><i class="fas fa-arrow-right-from-bracket"></i> Logout</a>
        </div>
    </div>
</header>

<div class="container">
    <!-- Search Section -->
    <div class="search-container">
        <i class="fas fa-magnifying-glass search-icon"></i>
        <input type="text" id="searchInput" class="search-box" placeholder="Search product by name, ID, or category...">
    </div>
    <div class="search-container">
        <i class="fas fa-camera search-icon"></i>
        <input type="text" id="barcodeInput" class="search-box" placeholder="Scan or type barcode for instant search...">
        <i class="fas fa-rotate-right barcode-icon" id="refreshBarcode"></i>
    </div>

    <div class="main">
        <!-- Barcode Generation Card -->
        <div class="card" id="formSection">
            <h2><i class="fas fa-qrcode"></i> Barcode Studio</h2>
            
            <?php if (isset($_SESSION['barcode_message'])): ?>
                <div class="success-message">
                    <i class="fas fa-circle-check"></i> <?= htmlspecialchars($_SESSION['barcode_message']) ?>
                </div>
                <?php unset($_SESSION['barcode_message']); ?>
            <?php endif; ?>
            <?php if (isset($_SESSION['barcode_error'])): ?>
                <div class="error-message">
                    <i class="fas fa-triangle-exclamation"></i> <?= htmlspecialchars($_SESSION['barcode_error']) ?>
                </div>
                <?php unset($_SESSION['barcode_error']); ?>
            <?php endif; ?>

            <form id="barcodeForm" method="POST">
                <input type="hidden" name="product_id" id="product_id">
                
                <div style="background: var(--light-mint); border-radius: 50px; padding: 20px;">
                    <label style="font-weight: 700; color: var(--dark-green); margin-bottom: 10px; display: block;">
                        <i class="fas fa-layer-group"></i> Number of Labels
                    </label>
                    <div class="quantity-control">
                        <button type="button" class="quantity-btn" id="decreaseBtn">−</button>
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
                </div>

                <button type="submit" name="generate_barcodes" class="btn btn-primary" id="generateBtn" disabled style="margin-top: 25px;">
                    <i class="fas fa-wand-magic-sparkles"></i> Generate Barcodes
                </button>
            </form>

            <?php if (isset($_SESSION['generated_barcodes']) && !empty($_SESSION['generated_barcodes'])): ?>
                <div style="display: flex; gap: 12px; margin-top: 25px;">
                    <button onclick="showPrintDialog()" class="btn btn-secondary" style="flex: 1;">
                        <i class="fas fa-print"></i> Print Labels
                    </button>
                    <a href="?clear_barcodes=true" class="btn btn-danger" style="flex: 1;">
                        <i class="fas fa-trash-can"></i> Clear All
                    </a>
                </div>

                <!-- Preview of Generated Barcodes -->
                <div style="margin-top: 30px;">
                    <h3 style="color: var(--dark-green); font-weight: 700; margin-bottom: 15px;">
                        <i class="fas fa-barcode"></i> Generated Barcodes
                    </h3>
                    <div class="generated-barcodes-grid">
                        <?php foreach ($_SESSION['generated_barcodes'] as $barcode): ?>
                        <div class="barcode-label">
                            <div class="company-name"><i class="fas fa-seedling"></i> KidsBerry</div>
                            <div style="font-weight: 800; color: var(--dark-green); font-size: 12px; text-align: center;"><?= htmlspecialchars(substr($barcode['name'], 0, 20)) ?></div>
                            <div class="barcode-price">LKR <?= number_format($barcode['sale_price'], 2) ?></div>
                            <div>
                                <svg class="barcode-image" jsbarcode-value="<?= $barcode['barcode'] ?>" jsbarcode-format="CODE128" jsbarcode-height="32" jsbarcode-width="1.2" jsbarcode-displayvalue="false"></svg>
                            </div>
                            <div class="barcode-footer"><?= htmlspecialchars($barcode['product_barcode'] != 'Not Set' ? $barcode['product_barcode'] : $barcode['product_id']) ?> <?= !empty($barcode['specific_letter']) ? '(' . htmlspecialchars($barcode['specific_letter']) . ')' : '' ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Live Preview for Selected Product -->
            <div class="preview-barcode" id="previewBarcode" style="display: none;">
                <span style="font-weight: 800; color: var(--dark-green);"><i class="fas fa-eye"></i> Live Preview</span>
                <div class="barcode-label" style="margin-top: 10px;">
                    <div class="company-name">KidsBerry</div>
                    <div id="previewProductName" style="font-weight: 800; font-size: 12px;">Product</div>
                    <div class="barcode-price" id="previewPrice">LKR 0.00</div>
                    <div><svg id="previewBarcodeImage" class="barcode-image"></svg></div>
                    <div class="barcode-footer" id="previewFooter"></div>
                </div>
            </div>
        </div>

        <!-- Product Selection Card -->
        <div class="card">
            <h2><i class="fas fa-cubes-stack"></i> Product Shelf</h2>
            <div id="ajaxSearchIndicator" style="display: none; background: var(--light-mint); padding: 12px; border-radius: 60px; margin-bottom: 15px;">
                <div class="ajax-loading" style="margin-right: 10px;"></div>
                <span id="ajaxSearchText">Searching...</span>
            </div>
            <div class="product-list-container" id="productListContainer">
                <?php
                $products = $conn->query("
                    SELECT p.*, s.supplier_name,
                    CASE WHEN p.barcode IS NOT NULL AND p.barcode != '' THEN p.barcode ELSE p.product_id END as display_barcode
                    FROM products2 p 
                    LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id 
                    ORDER BY p.name LIMIT 20
                ");
                if ($products->num_rows == 0): ?>
                <div class="empty-state">
                    <i class="fas fa-box-open"></i>
                    <h3>No Products Yet</h3>
                    <p>Add products to generate barcodes</p>
                </div>
                <?php else: while ($product = $products->fetch_assoc()): 
                    $barcodeVal = $product['barcode'] ?: $product['product_id']; ?>
                    <div class="product-item" data-product='<?= json_encode($product) ?>' data-search-text="<?= htmlspecialchars(strtolower($product['name'] . ' ' . $product['product_id'] . ' ' . $product['barcode'] . ' ' . $product['category'] . ' ' . $product['supplier_name'])) ?>">
                        <?php if($product['photo']): ?>
                            <img src="<?= htmlspecialchars($product['photo']) ?>" class="product-photo">
                        <?php else: ?>
                            <div style="width: 70px; height: 70px; background: var(--light-mint); border-radius: 18px; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-image" style="font-size: 28px; color: var(--forest-green);"></i>
                            </div>
                        <?php endif; ?>
                        <div style="flex: 1;">
                            <div class="product-name"><?= htmlspecialchars($product['name']) ?></div>
                            <div class="product-id">
                                ID: <?= $product['product_id'] ?> 
                                <span class="barcode-match"><?= htmlspecialchars($barcodeVal) ?></span>
                            </div>
                            <div style="display: flex; gap: 6px; margin: 5px 0; flex-wrap: wrap;">
                                <?php if(!empty($product['category'])): ?>
                                    <span style="background: var(--sage); color: white; padding: 2px 10px; border-radius: 50px; font-size: 0.7rem;"><?= htmlspecialchars($product['category']) ?></span>
                                <?php endif; ?>
                                <?php if(!empty($product['supplier_name'])): ?>
                                    <span style="background: var(--dark-green); color: white; padding: 2px 10px; border-radius: 50px; font-size: 0.7rem;"><?= htmlspecialchars($product['supplier_name']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="product-price">LKR <?= number_format($product['sale_price'], 2) ?></div>
                        </div>
                    </div>
                <?php endwhile; endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- PRINT SECTION - Zebra compatible -->
<div id="barcodePrintSection" style="display: none; visibility: hidden; position: absolute; left: -9999px; top: -9999px;">
    <?php if (isset($_SESSION['generated_barcodes']) && !empty($_SESSION['generated_barcodes'])): ?>
    <div class="barcode-print-grid">
        <?php foreach ($_SESSION['generated_barcodes'] as $index => $barcode): ?>
            <div class="barcode-print-label">
                <div class="company-name">KidsBerry</div>
                <div class="print-product-name"><?= htmlspecialchars($barcode['name']) ?></div>
                <div class="barcode-price">Rs. <?= number_format($barcode['sale_price'], 2) ?></div>
                <div class="barcode-image-container">
                    <svg class="barcode-image"
                        jsbarcode-value="<?= $barcode['barcode'] ?>"
                        jsbarcode-format="CODE128"
                        jsbarcode-height="50"
                        jsbarcode-width="2.5"
                        jsbarcode-displayvalue="false"
                        jsbarcode-margin="0">
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

<div class="fab" id="fabToggle">
    <i class="fas fa-qrcode"></i>
</div>

<script>
(function() {
    const quantityInput = document.getElementById('quantity');
    const decreaseBtn = document.getElementById('decreaseBtn');
    const increaseBtn = document.getElementById('increaseBtn');
    const presets = document.querySelectorAll('.quantity-preset');
    const generateBtn = document.getElementById('generateBtn');
    const productIdField = document.getElementById('product_id');
    const barcodeInput = document.getElementById('barcodeInput');
    const refreshBarcode = document.getElementById('refreshBarcode');
    const ajaxSearchIndicator = document.getElementById('ajaxSearchIndicator');
    const ajaxSearchText = document.getElementById('ajaxSearchText');
    const searchInput = document.getElementById('searchInput');

    // Quantity controls
    decreaseBtn.addEventListener('click', () => {
        let v = parseInt(quantityInput.value);
        if (v > 1) quantityInput.value = v - 1;
        updatePresetActive();
    });
    increaseBtn.addEventListener('click', () => {
        let v = parseInt(quantityInput.value);
        if (v < 100) quantityInput.value = v + 1;
        updatePresetActive();
    });
    presets.forEach(p => {
        p.addEventListener('click', function() {
            quantityInput.value = this.dataset.value;
            updatePresetActive();
        });
    });
    function updatePresetActive() {
        let val = parseInt(quantityInput.value);
        presets.forEach(p => {
            p.classList.toggle('active', parseInt(p.dataset.value) === val);
        });
    }

    // Product selection
    function selectProduct(el) {
        document.querySelectorAll('.product-item').forEach(i => i.classList.remove('selected'));
        el.classList.add('selected');
        let prod = JSON.parse(el.dataset.product);
        productIdField.value = prod.product_id;
        generateBtn.disabled = false;

        let preview = document.getElementById('previewBarcode');
        let previewImg = document.getElementById('previewBarcodeImage');
        let barcodeVal = prod.barcode || prod.product_id;
        JsBarcode(previewImg, barcodeVal, { format: "CODE128", height: 32, width: 1.2, displayValue: false });
        document.getElementById('previewProductName').innerText = prod.name.substring(0, 20) + (prod.name.length > 20 ? '...' : '');
        document.getElementById('previewPrice').innerText = 'LKR ' + parseFloat(prod.sale_price).toFixed(2);
        let foot = prod.barcode ? prod.barcode : prod.product_id;
        if (prod.specific_letter) foot += ' (' + prod.specific_letter + ')';
        document.getElementById('previewFooter').innerText = foot;
        preview.style.display = 'block';
    }

    // Attach product click
    document.querySelectorAll('.product-item').forEach(el => {
        el.addEventListener('click', function() { selectProduct(this); });
    });

    // Live search on product list
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const term = this.value.toLowerCase().trim();
            const items = document.querySelectorAll('.product-item');
            let found = false;
            items.forEach(item => {
                const searchText = item.dataset.searchText || '';
                if (term === '' || searchText.includes(term)) {
                    item.style.display = 'flex';
                    found = true;
                } else {
                    item.style.display = 'none';
                }
            });
            const container = document.getElementById('productListContainer');
            if (!found && term !== '') {
                const existing = container.querySelector('.empty-state');
                if (!existing) {
                    const emptyDiv = document.createElement('div');
                    emptyDiv.className = 'empty-state';
                    emptyDiv.innerHTML = `<i class="fas fa-search"></i><h3>No Results</h3><p>No products match "${escapeHtml(term)}"</p>`;
                    container.appendChild(emptyDiv);
                }
            } else {
                const emptyDiv = container.querySelector('.empty-state');
                if (emptyDiv && term === '') emptyDiv.remove();
            }
        });
    }

    // Barcode AJAX search
    let lastSearch = '', timeout;
    function performBarcodeSearch(b) {
        if (b === lastSearch) return;
        lastSearch = b;
        if (ajaxSearchIndicator) {
            ajaxSearchIndicator.style.display = 'flex';
            ajaxSearchText.innerText = '🔎 Scanning: ' + b;
        }
        fetch('?ajax_search=true&barcode=' + encodeURIComponent(b))
            .then(r => r.json())
            .then(data => {
                if (ajaxSearchIndicator) ajaxSearchIndicator.style.display = 'none';
                if (data.success && data.products.length) {
                    updateProductList(data.products);
                    if (data.products.length === 1) {
                        setTimeout(() => {
                            let first = document.querySelector('.product-item');
                            if (first) selectProduct(first);
                        }, 100);
                    }
                } else {
                    document.getElementById('productListContainer').innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-binoculars"></i>
                            <h3>No match for "${escapeHtml(b)}"</h3>
                            <p>Try a different barcode or product name</p>
                        </div>
                    `;
                }
            })
            .catch(() => {
                if (ajaxSearchIndicator) ajaxSearchIndicator.style.display = 'none';
            });
    }

    barcodeInput.addEventListener('input', function() {
        clearTimeout(timeout);
        let v = this.value.trim();
        if (v.length > 0) {
            timeout = setTimeout(() => performBarcodeSearch(v), 300);
        } else {
            resetProductList();
        }
    });

    refreshBarcode.addEventListener('click', () => {
        barcodeInput.value = '';
        resetProductList();
        barcodeInput.focus();
    });

    function resetProductList() {
        lastSearch = '';
        performBarcodeSearch('');
    }

    function updateProductList(products) {
        let html = '';
        products.forEach(p => {
            let bVal = p.barcode || p.product_id;
            html += `
                <div class="product-item" data-product='${JSON.stringify(p).replace(/'/g, "&apos;")}' data-search-text="${(p.name + ' ' + p.product_id + ' ' + (p.barcode || '') + ' ' + (p.category || '') + ' ' + (p.supplier_name || '')).toLowerCase()}">
                    ${p.photo ? `<img src="${p.photo}" class="product-photo">` : `<div style="width:70px;height:70px;background:var(--light-mint);border-radius:18px;display:flex;align-items:center;justify-content:center;"><i class="fas fa-image" style="font-size:28px;color:var(--forest-green);"></i></div>`}
                    <div style="flex:1">
                        <div class="product-name">${escapeHtml(p.name)}</div>
                        <div class="product-id">ID: ${p.product_id} <span class="barcode-match">${escapeHtml(bVal)}</span></div>
                        <div style="display:flex;gap:6px;margin:5px 0;flex-wrap:wrap;">
                            ${p.category ? `<span style="background:var(--sage);color:white;padding:2px 10px;border-radius:50px;font-size:0.7rem;">${escapeHtml(p.category)}</span>` : ''}
                            ${p.supplier_name ? `<span style="background:var(--dark-green);color:white;padding:2px 10px;border-radius:50px;font-size:0.7rem;">${escapeHtml(p.supplier_name)}</span>` : ''}
                        </div>
                        <div class="product-price">LKR ${parseFloat(p.sale_price).toFixed(2)}</div>
                    </div>
                </div>
            `;
        });
        document.getElementById('productListContainer').innerHTML = html;
        document.querySelectorAll('.product-item').forEach(el => {
            el.addEventListener('click', function() { selectProduct(this); });
        });
    }

    // Mobile FAB
    document.getElementById('fabToggle').addEventListener('click', function() {
        let fs = document.getElementById('formSection');
        fs.classList.toggle('active');
        this.querySelector('i').className = fs.classList.contains('active') ? 'fas fa-times' : 'fas fa-qrcode';
    });

    // Initialize barcodes
    <?php if (isset($_SESSION['generated_barcodes']) && !empty($_SESSION['generated_barcodes'])): ?>
    setTimeout(() => { JsBarcode(".barcode-image").init(); }, 200);
    <?php endif; ?>
    JsBarcode("#previewBarcodeImage").init();

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }
})();

// Print Dialog for Zebra Printer
function showPrintDialog() {
    JsBarcode(".barcode-image").init();
    
    setTimeout(function() {
        Swal.fire({
            title: 'Print Barcodes',
            html: '<div style="text-align:left; font-size:14px; margin:15px 0;">' +
                  '<p><strong>Printer Setup:</strong></p>' +
                  '<ul style="padding-left:20px; margin:10px 0;">' +
                  '<li>Ensure Zebra printer is connected</li>' +
                  '<li>Load 50mm × 25mm sticker paper</li>' +
                  '<li>Set page size to 100mm × auto</li>' +
                  '<li>Set margins to 0 (zero)</li>' +
                  '</ul>' +
                  '<p style="color:#2c5e2e; font-weight:bold;">Each label: 5cm × 2.5cm (2 per row)</p>' +
                  '</div>',
            icon: 'info',
            showCancelButton: true,
            confirmButtonColor: '#2c5e2e',
            cancelButtonColor: '#d9534f',
            confirmButtonText: 'Print Now',
            cancelButtonText: 'Cancel',
            width: 500
        }).then((result) => {
            if (result.isConfirmed) {
                const printSection = document.getElementById('barcodePrintSection');
                if (!printSection || printSection.innerHTML.trim() === '') {
                    Swal.fire('No Barcodes', 'Please generate barcodes first.', 'warning');
                    return;
                }
                
                JsBarcode(printSection.querySelectorAll('.barcode-image')).init();
                
                setTimeout(function() {
                    const printWindow = window.open('', '_blank');
                    const printContent = `
<!DOCTYPE html>
<html>
<head>
    <title>Print Barcodes - KidsBerry Mega Centre</title>
    <meta charset="UTF-8">
    <style>
        body { margin: 0; padding: 0; width: 100mm; font-family: Arial, sans-serif; background: white; }
        .barcode-print-grid { display: grid; grid-template-columns: repeat(2, 50mm); grid-auto-rows: 25mm; gap: 0; margin: 0; padding: 0; width: 100mm; }
        .barcode-print-label { width: 50mm; height: 25mm; padding: 0.5mm; margin: 0; box-sizing: border-box; break-inside: avoid; border: 1px solid #ccc; background: white; display: flex; flex-direction: column; align-items: center; justify-content: space-between; text-align: center; }
        .company-name { font-size: 10px; font-weight: bold; margin-bottom: 0.2mm; }
        .print-product-name { font-size: 9px; font-weight: bold; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; width: 100%; }
        .barcode-price { font-size: 11px; font-weight: bold; margin-bottom: 0.3mm; }
        .barcode-image-container { width: 100%; display: flex; justify-content: center; margin: 0.3mm 0; min-height: 9mm; }
        .barcode-image { max-width: 95%; max-height: 9mm; }
        .barcode-footer { font-size: 9px; text-align: center; font-weight: bold; margin-top: 0.2mm; }
        @page { size: 100mm auto; margin: 0mm; }
        @media print { body { margin: 0; padding: 0; } .barcode-print-label { border: 1px solid #ccc; } }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"><\/script>
</head>
<body onload="initPrint()">
    ${printSection.innerHTML}
    <script>
        function initPrint() {
            JsBarcode(".barcode-image", { format: "CODE128", height: 32, width: 1.1, displayValue: false, margin: 0 }).init();
            setTimeout(function() { window.print(); setTimeout(function() { window.close(); }, 500); }, 300);
        }
    <\/script>
</body>
</html>`;
                    printWindow.document.write(printContent);
                    printWindow.document.close();
                }, 300);
            }
        });
    }, 200);
}
</script>

<?php $conn->close(); ?>
</body>
</html>