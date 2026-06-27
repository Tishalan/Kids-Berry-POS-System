<?php
// File: product_search1.php - NEXT-GEN BARCODE SYSTEM WITH NDA DESIGN LANGUAGE
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
        $search_query = "
            SELECT p.*, s.supplier_name,
            CASE 
                WHEN p.barcode IS NOT NULL AND p.barcode != '' THEN p.barcode
                ELSE p.product_id
            END as display_barcode
            FROM products2 p 
            LEFT JOIN suppliers2 s ON p.supplier_id = s.supplier_id 
            WHERE p.barcode LIKE '%$barcode%' 
               OR p.product_id LIKE '%$barcode%'
               OR p.name LIKE '%$barcode%'
               OR p.category LIKE '%$barcode%'
               OR s.supplier_name LIKE '%$barcode%'
            ORDER BY 
                CASE 
                    WHEN p.barcode = '$barcode' THEN 1
                    WHEN p.product_id = '$barcode' THEN 2
                    WHEN p.barcode LIKE '$barcode%' THEN 3
                    WHEN p.product_id LIKE '$barcode%' THEN 4
                    ELSE 5
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
    
    $product_result = $conn->query("
        SELECT p.*, s.supplier_name 
        FROM products2 p 
        LEFT JOIN suppliers2 s ON p.supplier_id = s.supplier_id 
        WHERE p.product_id='$product_id'
    ");
    
    if ($product_result->num_rows > 0) {
        $product = $product_result->fetch_assoc();
        $barcode_value = $product['barcode'] ?: $product['product_id'];
        
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
    
    header("Location: product_search1.php");
    exit();
}

// Clear barcodes
if (isset($_GET['clear_barcodes'])) {
    unset($_SESSION['generated_barcodes']);
    header("Location: product_search1.php");
    exit();
}

// Get initial products
$initial_products = $conn->query("
    SELECT p.*, s.supplier_name,
    CASE 
        WHEN p.barcode IS NOT NULL AND p.barcode != '' THEN p.barcode
        ELSE p.product_id
    END as display_barcode
    FROM products2 p 
    LEFT JOIN suppliers2 s ON p.supplier_id = s.supplier_id 
    ORDER BY p.created_at DESC 
    LIMIT 12
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>NDA • Barcode Forge</title>
    
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
    <!-- Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    
    <style>
        /* =====================================================
           NDA DESIGN SYSTEM - COMPLETE REDESIGN
           Minimal, luxurious, dark mode aesthetic
           Inspired by modern fintech & design agencies
           ===================================================== */
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            /* NDA Dark Palette */
            --nd-bg-primary: #0A0C0F;
            --nd-bg-secondary: #121417;
            --nd-bg-tertiary: #1A1D23;
            --nd-bg-elevated: #24282F;
            --nd-border: #2C313A;
            --nd-border-light: #363B44;
            
            /* Accents */
            --nd-accent: #5E9BFF;
            --nd-accent-soft: rgba(94, 155, 255, 0.1);
            --nd-accent-glow: rgba(94, 155, 255, 0.2);
            --nd-success: #2ECC71;
            --nd-warning: #F39C12;
            --nd-danger: #E74C3C;
            
            /* Text */
            --nd-text-primary: #FFFFFF;
            --nd-text-secondary: #9CA3AF;
            --nd-text-tertiary: #6B7280;
            --nd-text-inverse: #0A0C0F;
            
            /* Gradients */
            --nd-gradient-dark: linear-gradient(145deg, #121417, #0D0F12);
            --nd-gradient-card: linear-gradient(145deg, #1A1D23, #15181D);
            --nd-gradient-accent: linear-gradient(135deg, #5E9BFF, #7C4DFF);
            
            /* Spacing */
            --nd-space-xs: 4px;
            --nd-space-sm: 8px;
            --nd-space-md: 16px;
            --nd-space-lg: 24px;
            --nd-space-xl: 32px;
            --nd-space-2xl: 48px;
            
            /* Border Radius */
            --nd-radius-sm: 8px;
            --nd-radius-md: 12px;
            --nd-radius-lg: 16px;
            --nd-radius-xl: 24px;
            --nd-radius-full: 9999px;
            
            /* Shadows */
            --nd-shadow-sm: 0 2px 4px rgba(0, 0, 0, 0.3);
            --nd-shadow-md: 0 4px 12px rgba(0, 0, 0, 0.4);
            --nd-shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.5);
            --nd-shadow-xl: 0 20px 40px rgba(0, 0, 0, 0.6);
            --nd-shadow-accent: 0 4px 20px rgba(94, 155, 255, 0.2);
            
            /* Transitions */
            --nd-transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--nd-bg-primary);
            color: var(--nd-text-primary);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* Typography */
        h1, h2, h3, h4, h5, h6 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-weight: 600;
            letter-spacing: -0.02em;
        }

        /* Layout */
        .nd-container {
            max-width: 1440px;
            margin: 0 auto;
            padding: var(--nd-space-lg);
        }

        /* Header - NDA Style */
        .nd-header {
            background: var(--nd-bg-secondary);
            border-bottom: 1px solid var(--nd-border);
            padding: var(--nd-space-md) 0;
            position: sticky;
            top: 0;
            z-index: 1000;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            background: rgba(10, 12, 15, 0.95);
        }

        .nd-header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            max-width: 1440px;
            margin: 0 auto;
            padding: 0 var(--nd-space-lg);
        }

        .nd-logo {
            display: flex;
            align-items: center;
            gap: var(--nd-space-sm);
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.5rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            color: var(--nd-text-primary);
        }

        .nd-logo i {
            color: var(--nd-accent);
            font-size: 1.8rem;
        }

        .nd-nav {
            display: flex;
            align-items: center;
            gap: var(--nd-space-xs);
        }

        .nd-nav-item {
            display: flex;
            align-items: center;
            gap: var(--nd-space-sm);
            padding: var(--nd-space-sm) var(--nd-space-lg);
            border-radius: var(--nd-radius-full);
            color: var(--nd-text-secondary);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            transition: var(--nd-transition);
            border: 1px solid transparent;
        }

        .nd-nav-item:hover {
            background: var(--nd-bg-tertiary);
            color: var(--nd-text-primary);
            border-color: var(--nd-border-light);
        }

        .nd-nav-item.active {
            background: var(--nd-accent-soft);
            color: var(--nd-accent);
            border: 1px solid var(--nd-accent);
        }

        .nd-nav-item.logout {
            background: var(--nd-bg-elevated);
            color: var(--nd-danger);
            border: 1px solid var(--nd-border);
        }

        .nd-nav-item.logout:hover {
            background: var(--nd-danger);
            color: white;
            border-color: var(--nd-danger);
        }

        /* Main Grid */
        .nd-grid {
            display: grid;
            grid-template-columns: 400px 1fr;
            gap: var(--nd-space-lg);
            margin-top: var(--nd-space-lg);
        }

        /* Cards - NDA Design */
        .nd-card {
            background: var(--nd-gradient-card);
            border: 1px solid var(--nd-border);
            border-radius: var(--nd-radius-xl);
            padding: var(--nd-space-xl);
            position: relative;
            overflow: hidden;
        }

        .nd-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--nd-gradient-accent);
            opacity: 0.5;
        }

        .nd-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: var(--nd-space-lg);
        }

        .nd-card-title {
            display: flex;
            align-items: center;
            gap: var(--nd-space-sm);
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--nd-text-primary);
        }

        .nd-card-title i {
            color: var(--nd-accent);
        }

        /* Search Section */
        .nd-search-section {
            margin-bottom: var(--nd-space-xl);
        }

        .nd-search-container {
            position: relative;
            margin-bottom: var(--nd-space-md);
        }

        .nd-search-icon {
            position: absolute;
            left: var(--nd-space-md);
            top: 50%;
            transform: translateY(-50%);
            color: var(--nd-text-tertiary);
            font-size: 1rem;
            z-index: 1;
        }

        .nd-search-input {
            width: 100%;
            padding: var(--nd-space-md) var(--nd-space-md) var(--nd-space-md) 44px;
            background: var(--nd-bg-tertiary);
            border: 1px solid var(--nd-border);
            border-radius: var(--nd-radius-full);
            color: var(--nd-text-primary);
            font-size: 0.95rem;
            transition: var(--nd-transition);
        }

        .nd-search-input:focus {
            outline: none;
            border-color: var(--nd-accent);
            background: var(--nd-bg-elevated);
            box-shadow: var(--nd-shadow-accent);
        }

        .nd-search-input::placeholder {
            color: var(--nd-text-tertiary);
        }

        .nd-clear-btn {
            position: absolute;
            right: var(--nd-space-sm);
            top: 50%;
            transform: translateY(-50%);
            background: var(--nd-bg-elevated);
            border: 1px solid var(--nd-border);
            color: var(--nd-text-tertiary);
            width: 32px;
            height: 32px;
            border-radius: var(--nd-radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--nd-transition);
        }

        .nd-clear-btn:hover {
            background: var(--nd-bg-tertiary);
            color: var(--nd-text-primary);
            border-color: var(--nd-border-light);
        }

        /* Quantity Control - NDA Style */
        .nd-quantity-control {
            background: var(--nd-bg-tertiary);
            border-radius: var(--nd-radius-full);
            padding: var(--nd-space-xs);
            display: flex;
            align-items: center;
            gap: var(--nd-space-xs);
            border: 1px solid var(--nd-border);
        }

        .nd-quantity-btn {
            width: 40px;
            height: 40px;
            border-radius: var(--nd-radius-full);
            background: var(--nd-bg-elevated);
            border: 1px solid var(--nd-border);
            color: var(--nd-text-primary);
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--nd-transition);
        }

        .nd-quantity-btn:hover {
            background: var(--nd-accent);
            border-color: var(--nd-accent);
            color: white;
        }

        .nd-quantity-input {
            flex: 1;
            text-align: center;
            background: transparent;
            border: none;
            color: var(--nd-text-primary);
            font-size: 1.1rem;
            font-weight: 600;
            padding: var(--nd-space-sm);
        }

        .nd-quantity-input:focus {
            outline: none;
        }

        .nd-quantity-presets {
            display: flex;
            gap: var(--nd-space-xs);
            margin-top: var(--nd-space-md);
            flex-wrap: wrap;
        }

        .nd-preset {
            padding: var(--nd-space-xs) var(--nd-space-md);
            background: var(--nd-bg-tertiary);
            border: 1px solid var(--nd-border);
            border-radius: var(--nd-radius-full);
            color: var(--nd-text-secondary);
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--nd-transition);
        }

        .nd-preset:hover {
            background: var(--nd-bg-elevated);
            color: var(--nd-text-primary);
            border-color: var(--nd-border-light);
        }

        .nd-preset.active {
            background: var(--nd-accent);
            border-color: var(--nd-accent);
            color: white;
        }

        /* Buttons */
        .nd-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: var(--nd-space-sm);
            padding: var(--nd-space-md) var(--nd-space-lg);
            border-radius: var(--nd-radius-full);
            font-weight: 600;
            font-size: 0.95rem;
            border: none;
            cursor: pointer;
            transition: var(--nd-transition);
            width: 100%;
        }

        .nd-btn-primary {
            background: var(--nd-gradient-accent);
            color: white;
            box-shadow: var(--nd-shadow-accent);
        }

        .nd-btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-2px);
            box-shadow: 0 6px 24px rgba(94, 155, 255, 0.3);
        }

        .nd-btn-secondary {
            background: var(--nd-bg-elevated);
            border: 1px solid var(--nd-border);
            color: var(--nd-text-primary);
        }

        .nd-btn-secondary:hover {
            background: var(--nd-bg-tertiary);
            border-color: var(--nd-border-light);
            transform: translateY(-2px);
        }

        .nd-btn-danger {
            background: var(--nd-bg-elevated);
            border: 1px solid var(--nd-danger);
            color: var(--nd-danger);
        }

        .nd-btn-danger:hover {
            background: var(--nd-danger);
            color: white;
            transform: translateY(-2px);
        }

        .nd-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        /* Product Grid */
        .nd-products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: var(--nd-space-md);
            max-height: 800px;
            overflow-y: auto;
            padding-right: var(--nd-space-xs);
        }

        .nd-products-grid::-webkit-scrollbar {
            width: 4px;
        }

        .nd-products-grid::-webkit-scrollbar-track {
            background: var(--nd-bg-tertiary);
            border-radius: var(--nd-radius-full);
        }

        .nd-products-grid::-webkit-scrollbar-thumb {
            background: var(--nd-border);
            border-radius: var(--nd-radius-full);
        }

        .nd-products-grid::-webkit-scrollbar-thumb:hover {
            background: var(--nd-border-light);
        }

        .nd-product-card {
            background: var(--nd-bg-tertiary);
            border: 1px solid var(--nd-border);
            border-radius: var(--nd-radius-lg);
            padding: var(--nd-space-lg);
            display: flex;
            flex-direction: column;
            gap: var(--nd-space-md);
            transition: var(--nd-transition);
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .nd-product-card:hover {
            transform: translateY(-4px);
            border-color: var(--nd-accent);
            box-shadow: var(--nd-shadow-accent);
        }

        .nd-product-card.selected {
            border: 2px solid var(--nd-accent);
            background: linear-gradient(145deg, var(--nd-bg-tertiary), rgba(94, 155, 255, 0.05));
        }

        .nd-product-image {
            width: 100%;
            height: 160px;
            object-fit: cover;
            border-radius: var(--nd-radius-md);
            background: var(--nd-bg-elevated);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .nd-product-image i {
            font-size: 2.5rem;
            color: var(--nd-text-tertiary);
        }

        .nd-product-info {
            flex: 1;
        }

        .nd-product-name {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--nd-text-primary);
            margin-bottom: var(--nd-space-xs);
            line-height: 1.3;
        }

        .nd-product-id {
            font-size: 0.8rem;
            color: var(--nd-text-tertiary);
            margin-bottom: var(--nd-space-sm);
            font-family: monospace;
        }

        .nd-product-badge {
            display: inline-block;
            padding: var(--nd-space-xs) var(--nd-space-sm);
            background: var(--nd-accent-soft);
            border: 1px solid var(--nd-accent);
            border-radius: var(--nd-radius-full);
            color: var(--nd-accent);
            font-size: 0.7rem;
            font-weight: 600;
            margin-right: var(--nd-space-xs);
            margin-bottom: var(--nd-space-xs);
        }

        .nd-product-price {
            font-size: 1.3rem;
            font-weight: 800;
            color: var(--nd-text-primary);
            margin-top: var(--nd-space-sm);
        }

        .nd-product-price span {
            color: var(--nd-text-tertiary);
            font-size: 0.9rem;
            font-weight: 400;
        }

        /* Barcode Preview */
        .nd-barcode-preview {
            background: white;
            border-radius: var(--nd-radius-md);
            padding: var(--nd-space-lg);
            margin-top: var(--nd-space-lg);
            border: 1px solid var(--nd-border);
        }

        .nd-barcode-label {
            background: white;
            border-radius: var(--nd-radius-sm);
            padding: var(--nd-space-md);
            text-align: center;
            max-width: 240px;
            margin: 0 auto;
        }

        .nd-company-name {
            color: var(--nd-accent);
            font-weight: 800;
            font-size: 0.9rem;
            letter-spacing: 1px;
        }

        .nd-product-name-small {
            color: var(--nd-bg-primary);
            font-weight: 700;
            font-size: 0.9rem;
            margin: var(--nd-space-xs) 0;
        }

        .nd-price-tag {
            color: var(--nd-success);
            font-weight: 800;
            font-size: 1.1rem;
        }

        .nd-barcode-footer {
            color: var(--nd-text-tertiary);
            font-size: 0.7rem;
            font-weight: 600;
            margin-top: var(--nd-space-xs);
        }

        /* Generated Barcodes */
        .nd-generated-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: var(--nd-space-md);
            margin-top: var(--nd-space-lg);
        }

        /* Alert Messages */
        .nd-alert {
            padding: var(--nd-space-md) var(--nd-space-lg);
            border-radius: var(--nd-radius-full);
            margin-bottom: var(--nd-space-lg);
            display: flex;
            align-items: center;
            gap: var(--nd-space-sm);
            font-weight: 500;
        }

        .nd-alert-success {
            background: rgba(46, 204, 113, 0.1);
            border: 1px solid var(--nd-success);
            color: var(--nd-success);
        }

        .nd-alert-error {
            background: rgba(231, 76, 60, 0.1);
            border: 1px solid var(--nd-danger);
            color: var(--nd-danger);
        }

        /* Loading Indicator */
        .nd-loading {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: var(--nd-space-xl);
            color: var(--nd-text-tertiary);
            gap: var(--nd-space-sm);
        }

        .nd-spinner {
            width: 20px;
            height: 20px;
            border: 2px solid var(--nd-border);
            border-top-color: var(--nd-accent);
            border-radius: 50%;
            animation: nd-spin 0.8s linear infinite;
        }

        @keyframes nd-spin {
            to { transform: rotate(360deg); }
        }

        /* Empty State */
        .nd-empty-state {
            text-align: center;
            padding: var(--nd-space-2xl);
            color: var(--nd-text-tertiary);
        }

        .nd-empty-state i {
            font-size: 3rem;
            margin-bottom: var(--nd-space-md);
            color: var(--nd-border);
        }

        .nd-empty-state h3 {
            color: var(--nd-text-secondary);
            margin-bottom: var(--nd-space-xs);
        }

        /* Print Styles - Zebra Compatible */
        @media print {
            body * {
                visibility: hidden !important;
                margin: 0;
                padding: 0;
            }
            
            #barcodePrintSection,
            #barcodePrintSection * {
                visibility: visible !important;
            }
            
            #barcodePrintSection {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                background: white;
            }
            
            .print-grid {
                display: grid !important;
                grid-template-columns: repeat(2, 50mm) !important;
                grid-auto-rows: 25mm !important;
                gap: 0 !important;
                padding: 0 !important;
                margin: 0 !important;
                width: 100mm !important;
            }
            
            .print-label {
                width: 50mm !important;
                height: 25mm !important;
                padding: 2mm !important;
                margin: 0 !important;
                border: none !important;
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                justify-content: center !important;
                background: white !important;
                color: black !important;
            }
            
            @page {
                size: 100mm auto;
                margin: 0mm;
            }
        }

        /* Mobile FAB */
        .nd-fab {
            display: none;
            position: fixed;
            bottom: var(--nd-space-lg);
            right: var(--nd-space-lg);
            width: 56px;
            height: 56px;
            background: var(--nd-gradient-accent);
            border-radius: var(--nd-radius-full);
            color: white;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            box-shadow: var(--nd-shadow-xl);
            border: 1px solid rgba(255, 255, 255, 0.2);
            z-index: 999;
            cursor: pointer;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .nd-grid {
                grid-template-columns: 1fr;
            }
            
            .nd-card.form-card {
                display: none;
            }
            
            .nd-card.form-card.active {
                display: block;
            }
            
            .nd-fab {
                display: flex;
            }
            
            .nd-nav {
                display: none;
            }
            
            .nd-header-content {
                padding: 0 var(--nd-space-md);
            }
        }

        @media (max-width: 640px) {
            .nd-container {
                padding: var(--nd-space-md);
            }
            
            .nd-card {
                padding: var(--nd-space-lg);
            }
            
            .nd-products-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Header - NDA Style -->
    <header class="nd-header">
        <div class="nd-header-content">
            <div class="nd-logo">
                <i class="fas fa-cube"></i>
                NDA·forge
            </div>
            
            <nav class="nd-nav">
                <a href="dashboard1.php" class="nd-nav-item">
                    <i class="fas fa-chart-scatter"></i>
                    Dashboard
                </a>
                <a href="product1.php" class="nd-nav-item">
                    <i class="fas fa-archive"></i>
                    Vault
                </a>
                <a href="product_search1.php" class="nd-nav-item active">
                    <i class="fas fa-barcode"></i>
                    Barcode
                </a>
                <!--<a href="customershow.php" class="nd-nav-item">-->
                <!--    <i class="fas fa-users"></i>-->
                <!--    Clients-->
                <!--</a>-->
                <a href="suppliers1.php" class="nd-nav-item">
                    <i class="fas fa-truck"></i>
                    Suppliers
                </a>
                <a href="?logout=true" class="nd-nav-item logout">
                    <i class="fas fa-sign-out-alt"></i>
                    Exit
                </a>
            </nav>
        </div>
    </header>

    <main class="nd-container">
        <!-- Search Section - NDA Style -->
        <div class="nd-search-section">
            <div class="nd-search-container">
                <i class="fas fa-search nd-search-icon"></i>
                <input type="text" id="barcodeSearch" class="nd-search-input" placeholder="Search by barcode, product ID, name, category, supplier..." autofocus>
                <button class="nd-clear-btn" id="clearSearch">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="nd-search-container">
                <i class="fas fa-camera nd-search-icon"></i>
                <input type="text" id="scannerInput" class="nd-search-input" placeholder="📷 Scan barcode directly...">
            </div>
        </div>

        <!-- Alerts -->
        <?php if (isset($_SESSION['barcode_message'])): ?>
            <div class="nd-alert nd-alert-success">
                <i class="fas fa-check-circle"></i>
                <?= $_SESSION['barcode_message'] ?>
            </div>
            <?php unset($_SESSION['barcode_message']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['barcode_error'])): ?>
            <div class="nd-alert nd-alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?= $_SESSION['barcode_error'] ?>
            </div>
            <?php unset($_SESSION['barcode_error']); ?>
        <?php endif; ?>

        <!-- Main Grid -->
        <div class="nd-grid">
            <!-- Barcode Generator Card -->
            <div class="nd-card form-card" id="formCard">
                <div class="nd-card-header">
                    <h3 class="nd-card-title">
                        <i class="fas fa-magic"></i>
                        Barcode Forge
                    </h3>
                    <span style="color: var(--nd-text-tertiary); font-size: 0.9rem; background: var(--nd-bg-tertiary); padding: 4px 12px; border-radius: var(--nd-radius-full);">
                        v2.0.NDA
                    </span>
                </div>

                <form id="barcodeForm" method="POST">
                    <input type="hidden" name="product_id" id="productId">
                    
                    <!-- Quantity Control -->
                    <div style="margin-bottom: var(--nd-space-lg);">
                        <label style="display: block; margin-bottom: var(--nd-space-sm); color: var(--nd-text-secondary); font-weight: 500; font-size: 0.9rem;">
                            <i class="fas fa-layer-group"></i>
                            Label Quantity
                        </label>
                        
                        <div class="nd-quantity-control">
                            <button type="button" class="nd-quantity-btn" id="decreaseQty">−</button>
                            <input type="number" name="quantity" id="quantity" value="2" min="1" max="100" class="nd-quantity-input">
                            <button type="button" class="nd-quantity-btn" id="increaseQty">+</button>
                        </div>
                        
                        <div class="nd-quantity-presets">
                            <span class="nd-preset" data-value="1">1</span>
                            <span class="nd-preset active" data-value="2">2</span>
                            <span class="nd-preset" data-value="5">5</span>
                            <span class="nd-preset" data-value="10">10</span>
                            <span class="nd-preset" data-value="20">20</span>
                            <span class="nd-preset" data-value="50">50</span>
                            <span class="nd-preset" data-value="100">100</span>
                        </div>
                    </div>

                    <button type="submit" name="generate_barcodes" id="generateBtn" class="nd-btn nd-btn-primary" disabled>
                        <i class="fas fa-wand-magic-sparkles"></i>
                        Generate Barcodes
                    </button>
                </form>

                <!-- Live Preview -->
                <div id="livePreview" style="display: none; margin-top: var(--nd-space-xl);">
                    <div style="display: flex; align-items: center; gap: var(--nd-space-sm); margin-bottom: var(--nd-space-md);">
                        <i class="fas fa-eye" style="color: var(--nd-accent);"></i>
                        <span style="color: var(--nd-text-secondary); font-weight: 500;">Live Preview</span>
                    </div>
                    
                    <div class="nd-barcode-preview">
                        <div class="nd-barcode-label">
                            <div class="nd-company-name">NDA·KIDS</div>
                            <div id="previewName" class="nd-product-name-small">Product Name</div>
                            <div id="previewPrice" class="nd-price-tag">LKR 0.00</div>
                            <svg id="previewBarcode" style="width: 100%; margin: 8px 0;"></svg>
                            <div id="previewCode" class="nd-barcode-footer">---</div>
                        </div>
                    </div>
                </div>

                <!-- Generated Barcodes -->
                <?php if (isset($_SESSION['generated_barcodes']) && !empty($_SESSION['generated_barcodes'])): ?>
                    <div style="margin-top: var(--nd-space-xl);">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--nd-space-lg);">
                            <div style="display: flex; align-items: center; gap: var(--nd-space-sm);">
                                <i class="fas fa-check-circle" style="color: var(--nd-success);"></i>
                                <span style="font-weight: 600;">Generated Labels</span>
                                <span style="background: var(--nd-bg-tertiary); padding: 2px 10px; border-radius: var(--nd-radius-full); font-size: 0.8rem; color: var(--nd-text-secondary);">
                                    <?= count($_SESSION['generated_barcodes']) ?> pcs
                                </span>
                            </div>
                            
                            <div style="display: flex; gap: var(--nd-space-sm);">
                                <button onclick="printBarcodes()" class="nd-btn nd-btn-secondary" style="width: auto; padding: 8px 16px;">
                                    <i class="fas fa-print"></i>
                                    Print
                                </button>
                                <a href="?clear_barcodes=true" class="nd-btn nd-btn-danger" style="width: auto; padding: 8px 16px;">
                                    <i class="fas fa-trash"></i>
                                    Clear
                                </a>
                            </div>
                        </div>
                        
                        <div class="nd-generated-grid">
                            <?php foreach ($_SESSION['generated_barcodes'] as $barcode): ?>
                                <div style="background: white; border-radius: var(--nd-radius-md); padding: var(--nd-space-md); text-align: center;">
                                    <div style="color: var(--nd-bg-primary); font-weight: 800; font-size: 0.8rem;">NDA·KIDS</div>
                                    <div style="color: var(--nd-bg-primary); font-weight: 700; font-size: 0.9rem; margin: 4px 0;">
                                        <?= htmlspecialchars(substr($barcode['name'], 0, 20)) ?>
                                    </div>
                                    <div style="color: var(--nd-success); font-weight: 800;">
                                        LKR <?= number_format($barcode['sale_price'], 2) ?>
                                    </div>
                                    <svg class="barcode-svg" jsbarcode-value="<?= $barcode['barcode'] ?>" 
                                         jsbarcode-format="CODE128" jsbarcode-height="30" 
                                         jsbarcode-width="1.2" jsbarcode-displayvalue="false"
                                         style="width: 100%; margin: 8px 0;">
                                    </svg>
                                    <div style="color: var(--nd-text-tertiary); font-size: 0.7rem; font-weight: 600;">
                                        <?= htmlspecialchars($barcode['product_barcode'] != 'Not Set' ? $barcode['product_barcode'] : $barcode['product_id']) ?>
                                        <?= !empty($barcode['specific_letter']) ? '(' . $barcode['specific_letter'] . ')' : '' ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Product Vault Card -->
            <div class="nd-card">
                <div class="nd-card-header">
                    <h3 class="nd-card-title">
                        <i class="fas fa-cubes"></i>
                        Product Vault
                    </h3>
                    <span id="productCount" style="color: var(--nd-text-tertiary); font-size: 0.9rem;">
                        <?php if ($initial_products): ?><?= $initial_products->num_rows ?> items<?php endif; ?>
                    </span>
                </div>

                <!-- Loading Indicator -->
                <div id="searchLoading" class="nd-loading" style="display: none;">
                    <div class="nd-spinner"></div>
                    <span>Searching vault...</span>
                </div>

                <!-- Products Grid -->
                <div id="productsGrid" class="nd-products-grid">
                    <?php if ($initial_products && $initial_products->num_rows > 0): ?>
                        <?php while ($product = $initial_products->fetch_assoc()): 
                            $barcodeVal = $product['barcode'] ?: $product['product_id'];
                        ?>
                            <div class="nd-product-card" 
                                 data-product='<?= json_encode($product) ?>'
                                 data-search='<?= strtolower($product['name'] . ' ' . $product['product_id'] . ' ' . $product['barcode'] . ' ' . $product['category'] . ' ' . ($product['supplier_name'] ?? '')) ?>'>
                                
                                <?php if (!empty($product['photo'])): ?>
                                    <img src="<?= htmlspecialchars($product['photo']) ?>" class="nd-product-image" alt="<?= htmlspecialchars($product['name']) ?>">
                                <?php else: ?>
                                    <div class="nd-product-image">
                                        <i class="fas fa-box"></i>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="nd-product-info">
                                    <div class="nd-product-name"><?= htmlspecialchars($product['name']) ?></div>
                                    <div class="nd-product-id">
                                        <i class="fas fa-fingerprint"></i> <?= $product['product_id'] ?>
                                    </div>
                                    
                                    <div style="margin-bottom: var(--nd-space-sm);">
                                        <span class="nd-product-badge">
                                            <i class="fas fa-barcode"></i> <?= $barcodeVal ?>
                                        </span>
                                        <?php if (!empty($product['category'])): ?>
                                            <span class="nd-product-badge" style="background: rgba(46, 204, 113, 0.1); border-color: var(--nd-success); color: var(--nd-success);">
                                                <?= $product['category'] ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="nd-product-price">
                                        LKR <?= number_format($product['sale_price'], 2) ?>
                                        <span>LKR <?= number_format($product['purchase_price'] ?? 0, 2) ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="nd-empty-state">
                            <i class="fas fa-box-open"></i>
                            <h3>Vault Empty</h3>
                            <p>Add products to start generating barcodes</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Print Section - Zebra Compatible -->
    <div id="barcodePrintSection" style="display: none;">
        <?php if (isset($_SESSION['generated_barcodes']) && !empty($_SESSION['generated_barcodes'])): ?>
            <div class="print-grid">
                <?php foreach ($_SESSION['generated_barcodes'] as $barcode): ?>
                    <div class="print-label">
                        <div style="font-weight: 800; font-size: 12px;">NDA KIDS</div>
                        <div style="font-weight: 700; font-size: 10px;"><?= htmlspecialchars(substr($barcode['name'], 0, 20)) ?></div>
                        <div style="font-weight: 800; font-size: 14px; color: black;">Rs. <?= number_format($barcode['sale_price'], 2) ?></div>
                        <svg class="print-barcode" jsbarcode-value="<?= $barcode['barcode'] ?>" 
                             jsbarcode-format="CODE128" jsbarcode-height="40" 
                             jsbarcode-width="2" jsbarcode-displayvalue="false">
                        </svg>
                        <div style="font-size: 8px;"><?= $barcode['product_barcode'] != 'Not Set' ? $barcode['product_barcode'] : $barcode['product_id'] ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Mobile FAB -->
    <div class="nd-fab" id="mobileFab">
        <i class="fas fa-qrcode"></i>
    </div>

    <script>
        // =====================================================
        // NDA BARCODE SYSTEM - ADVANCED INTERFACE
        // =====================================================
        
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize JsBarcode
            setTimeout(() => {
                JsBarcode(".barcode-svg").init();
                JsBarcode("#previewBarcode").init();
            }, 100);
            
            // DOM Elements
            const searchInput = document.getElementById('barcodeSearch');
            const scannerInput = document.getElementById('scannerInput');
            const clearBtn = document.getElementById('clearSearch');
            const productsGrid = document.getElementById('productsGrid');
            const productCards = document.querySelectorAll('.nd-product-card');
            const productIdField = document.getElementById('productId');
            const generateBtn = document.getElementById('generateBtn');
            const quantityInput = document.getElementById('quantity');
            const decreaseBtn = document.getElementById('decreaseQty');
            const increaseBtn = document.getElementById('increaseQty');
            const presets = document.querySelectorAll('.nd-preset');
            const livePreview = document.getElementById('livePreview');
            const formCard = document.getElementById('formCard');
            const mobileFab = document.getElementById('mobileFab');
            const searchLoading = document.getElementById('searchLoading');
            const productCount = document.getElementById('productCount');
            
            // Selected product
            let selectedProduct = null;
            
            // ========== Quantity Controls ==========
            decreaseBtn.addEventListener('click', () => {
                let val = parseInt(quantityInput.value);
                if (val > 1) quantityInput.value = val - 1;
                updateActivePreset();
            });
            
            increaseBtn.addEventListener('click', () => {
                let val = parseInt(quantityInput.value);
                if (val < 100) quantityInput.value = val + 1;
                updateActivePreset();
            });
            
            quantityInput.addEventListener('input', function() {
                if (this.value < 1) this.value = 1;
                if (this.value > 100) this.value = 100;
                updateActivePreset();
            });
            
            presets.forEach(preset => {
                preset.addEventListener('click', function() {
                    quantityInput.value = this.dataset.value;
                    updateActivePreset();
                });
            });
            
            function updateActivePreset() {
                let val = parseInt(quantityInput.value);
                presets.forEach(p => {
                    p.classList.toggle('active', parseInt(p.dataset.value) === val);
                });
            }
            
            // ========== Product Selection ==========
            function selectProduct(product, element) {
                selectedProduct = product;
                
                // Update UI
                document.querySelectorAll('.nd-product-card').forEach(card => {
                    card.classList.remove('selected');
                });
                
                if (element) {
                    element.classList.add('selected');
                }
                
                // Update form
                productIdField.value = product.product_id;
                generateBtn.disabled = false;
                
                // Update preview
                const barcodeVal = product.barcode || product.product_id;
                JsBarcode("#previewBarcode", barcodeVal, {
                    format: "CODE128",
                    height: 30,
                    width: 1.2,
                    displayValue: false
                });
                
                document.getElementById('previewName').textContent = 
                    product.name.length > 25 ? product.name.substring(0, 25) + '...' : product.name;
                
                document.getElementById('previewPrice').textContent = 
                    'LKR ' + parseFloat(product.sale_price).toFixed(2);
                
                let codeText = product.barcode || product.product_id;
                if (product.specific_letter) codeText += ' (' + product.specific_letter + ')';
                document.getElementById('previewCode').textContent = codeText;
                
                livePreview.style.display = 'block';
            }
            
            // Attach click handlers to product cards
            productCards.forEach(card => {
                card.addEventListener('click', function(e) {
                    const product = JSON.parse(this.dataset.product);
                    selectProduct(product, this);
                });
            });
            
            // ========== Search Functionality ==========
            let searchTimeout;
            
            function performSearch(query) {
                if (query.length < 1) {
                    // Reload default products
                    location.reload();
                    return;
                }
                
                searchLoading.style.display = 'flex';
                
                fetch('?ajax_search=true&barcode=' + encodeURIComponent(query))
                    .then(response => response.json())
                    .then(data => {
                        searchLoading.style.display = 'none';
                        
                        if (data.success && data.products.length > 0) {
                            updateProductsGrid(data.products);
                            
                            if (data.products.length === 1) {
                                setTimeout(() => {
                                    const firstCard = document.querySelector('.nd-product-card');
                                    if (firstCard) {
                                        selectProduct(data.products[0], firstCard);
                                    }
                                }, 100);
                            }
                        } else {
                            productsGrid.innerHTML = `
                                <div class="nd-empty-state">
                                    <i class="fas fa-binoculars"></i>
                                    <h3>No Results Found</h3>
                                    <p>No matches for "${query}"</p>
                                </div>
                            `;
                        }
                    })
                    .catch(error => {
                        console.error('Search error:', error);
                        searchLoading.style.display = 'none';
                    });
            }
            
            function updateProductsGrid(products) {
                let html = '';
                
                products.forEach(p => {
                    const barcodeVal = p.barcode || p.product_id;
                    const searchText = (p.name + ' ' + p.product_id + ' ' + (p.barcode || '') + ' ' + (p.category || '') + ' ' + (p.supplier_name || '')).toLowerCase();
                    
                    html += `
                        <div class="nd-product-card" 
                             data-product='${JSON.stringify(p)}'
                             data-search='${searchText}'>
                            
                            ${p.photo ? 
                                `<img src="${p.photo}" class="nd-product-image">` : 
                                `<div class="nd-product-image"><i class="fas fa-box"></i></div>`
                            }
                            
                            <div class="nd-product-info">
                                <div class="nd-product-name">${escapeHtml(p.name)}</div>
                                <div class="nd-product-id">
                                    <i class="fas fa-fingerprint"></i> ${p.product_id}
                                </div>
                                
                                <div style="margin-bottom: var(--nd-space-sm);">
                                    <span class="nd-product-badge">
                                        <i class="fas fa-barcode"></i> ${barcodeVal}
                                    </span>
                                    ${p.category ? 
                                        `<span class="nd-product-badge" style="background: rgba(46, 204, 113, 0.1); border-color: var(--nd-success); color: var(--nd-success);">
                                            ${p.category}
                                        </span>` : ''
                                    }
                                    ${p.supplier_name ? 
                                        `<span class="nd-product-badge" style="background: rgba(243, 156, 18, 0.1); border-color: var(--nd-warning); color: var(--nd-warning);">
                                            ${p.supplier_name}
                                        </span>` : ''
                                    }
                                </div>
                                
                                <div class="nd-product-price">
                                    LKR ${parseFloat(p.sale_price).toFixed(2)}
                                    <span>LKR ${p.purchase_price ? parseFloat(p.purchase_price).toFixed(2) : '0.00'}</span>
                                </div>
                            </div>
                        </div>
                    `;
                });
                
                productsGrid.innerHTML = html;
                
                // Reattach event listeners
                document.querySelectorAll('.nd-product-card').forEach(card => {
                    card.addEventListener('click', function(e) {
                        const product = JSON.parse(this.dataset.product);
                        selectProduct(product, this);
                    });
                });
                
                if (productCount) {
                    productCount.textContent = products.length + ' items';
                }
            }
            
            // Escape HTML to prevent XSS
            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
            
            // Search input handler
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                const query = this.value.trim();
                
                if (query.length > 0) {
                    searchTimeout = setTimeout(() => performSearch(query), 300);
                }
            });
            
            // Scanner input handler
            scannerInput.addEventListener('input', function() {
                const query = this.value.trim();
                if (query.length > 0) {
                    searchInput.value = query;
                    performSearch(query);
                }
            });
            
            scannerInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const query = this.value.trim();
                    if (query.length > 0) {
                        searchInput.value = query;
                        performSearch(query);
                    }
                }
            });
            
            // Clear search
            clearBtn.addEventListener('click', function() {
                searchInput.value = '';
                scannerInput.value = '';
                location.reload();
            });
            
            // ========== Mobile FAB ==========
            mobileFab.addEventListener('click', function() {
                formCard.classList.toggle('active');
                this.innerHTML = formCard.classList.contains('active') ? 
                    '<i class="fas fa-times"></i>' : 
                    '<i class="fas fa-qrcode"></i>';
            });
            
            // ========== Keyboard Shortcuts ==========
            document.addEventListener('keydown', function(e) {
                // Ctrl/Cmd + F: Focus search
                if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                    e.preventDefault();
                    searchInput.focus();
                }
                
                // Esc: Clear search
                if (e.key === 'Escape') {
                    if (searchInput.value.length > 0) {
                        searchInput.value = '';
                        scannerInput.value = '';
                        location.reload();
                    }
                }
                
                // Ctrl/Cmd + P: Print
                if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                    e.preventDefault();
                    if (document.querySelector('.nd-product-card.selected')) {
                        printBarcodes();
                    } else {
                        Swal.fire({
                            title: 'No Barcodes',
                            text: 'Generate barcodes first before printing',
                            icon: 'warning',
                            background: '#1A1D23',
                            color: '#fff',
                            confirmButtonColor: '#5E9BFF'
                        });
                    }
                }
            });
            
            // ========== Auto-select if only one product ==========
            if (productCards.length === 1) {
                setTimeout(() => {
                    selectProduct(JSON.parse(productCards[0].dataset.product), productCards[0]);
                }, 200);
            }
        });
        
        // ========== Print Function ==========
        function printBarcodes() {
            JsBarcode(".barcode-svg").init();
            JsBarcode(".print-barcode").init();
            
            setTimeout(() => {
                Swal.fire({
                    title: 'Print Labels',
                    html: `
                        <div style="text-align: left; color: #fff;">
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px;">
                                <i class="fas fa-print" style="color: #5E9BFF;"></i>
                                <span style="font-weight: 600;">Zebra Compatible Labels</span>
                            </div>
                            <div style="background: #24282F; padding: 16px; border-radius: 12px; border: 1px solid #2C313A;">
                                <p style="margin-bottom: 8px;">📏 <strong>Label Size:</strong> 50mm x 25mm</p>
                                <p style="margin-bottom: 8px;">📐 <strong>Layout:</strong> 2 per row, continuous</p>
                                <p style="color: #9CA3AF; font-size: 0.9rem;">⚠️ Set page size to 100mm width, margins 0mm</p>
                            </div>
                        </div>
                    `,
                    icon: 'info',
                    background: '#1A1D23',
                    color: '#fff',
                    confirmButtonColor: '#5E9BFF',
                    cancelButtonColor: '#E74C3C',
                    showCancelButton: true,
                    confirmButtonText: 'Print Now',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        const printSection = document.getElementById('barcodePrintSection');
                        const printWindow = window.open('', '_blank');
                        
                        const styles = document.querySelector('style').innerHTML;
                        const content = `
                            <!DOCTYPE html>
                            <html>
                                <head>
                                    <title>NDA Barcode Print</title>
                                    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
                                    <style>
                                        ${styles}
                                        @media print {
                                            @page { size: 100mm auto; margin: 0mm; }
                                            body { margin: 0; background: white; }
                                        }
                                    </style>
                                </head>
                                <body onload="JsBarcode('.print-barcode').init(); setTimeout(() => { window.print(); window.close(); }, 100);">
                                    ${printSection.innerHTML}
                                </body>
                            </html>
                        `;
                        
                        printWindow.document.write(content);
                        printWindow.document.close();
                    }
                });
            }, 200);
        }
        
        // Initialize on load
        window.addEventListener('load', function() {
            JsBarcode(".barcode-svg").init();
            JsBarcode(".print-barcode").init();
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>