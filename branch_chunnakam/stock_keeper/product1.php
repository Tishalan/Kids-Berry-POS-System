<?php
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

// Create uploads folder
if (!is_dir('uploads')) mkdir('uploads', 0777, true);

// ==================== PHP BACKEND (AJAX) ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    // Delete Product
    if (isset($_POST['delete_id'])) {
        $id = $conn->real_escape_string($_POST['delete_id']);
        $photo = $conn->query("SELECT photo FROM products2 WHERE product_id='$id'")->fetch_assoc();
        if ($photo && $photo['photo'] && file_exists($photo['photo'])) unlink($photo['photo']);
        $conn->query("DELETE FROM products2 WHERE product_id = '$id'");
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
        $res = $conn->query("SELECT product_id FROM products2 ORDER BY CAST(SUBSTRING(product_id,3) AS UNSIGNED) DESC LIMIT 1");
        $last = $res->fetch_assoc();
        $num = $last ? intval(substr($last['product_id'], 2)) + 1 : 1;
        $product_id = "KB" . str_pad($num, 5, "0", STR_PAD_LEFT);

        $sql = "INSERT INTO products2
                (product_id, name, category, supplier_id, original_price, sale_price, stock, barcode, color, specific_letter, photo) 
                VALUES 
                ('$product_id', '$name', '$category', '$supplier_id', $original_price, $sale_price, $stock, '$barcode', '$color', '$specific_letter', '$photo')";
        $msg = "Product added! ID: $product_id";
    } else {
        // === UPDATE EXISTING PRODUCT ===
        $photo_sql = $photo ? ", photo='$photo'" : "";
        $sql = "UPDATE products2 SET 
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>📦 Inventory · KidsBerry Mega Centre</title>
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ----------------------------------------------------------------------
           DARK GREEN & WHITE · MODERN · TRENDY · FULLY RESPONSIVE
           - Consistent with Dashboard: fresh, clean, professional
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
            --soft-gray: #f0f2ee;
            --coral-soft: #e67e22;
            --shadow-sm: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.02);
            --shadow-md: 0 20px 30px -12px rgba(26, 59, 47, 0.12);
            --radius-card: 24px;
            --radius-element: 16px;
            --transition-smooth: all 0.3s cubic-bezier(0.2, 0.9, 0.4, 1.1);
        }

        body {
            font-family: 'Inter', 'Outfit', system-ui, -apple-system, sans-serif;
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
            backdrop-filter: blur(2px);
            border-bottom: 1px solid rgba(138, 174, 140, 0.3);
        }
        .menu {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
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
            font-size: 1.7rem;
        }
        .logo span {
            font-size: 0.9rem;
            font-weight: 500;
            background: rgba(255,255,255,0.15);
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
            background: rgba(255,255,255,0.05);
            transition: var(--transition-smooth);
        }
        .nav-links a:hover, .nav-links a.active {
            background: white;
            color: var(--dark-green);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        .logout-btn {
            background: rgba(255,255,255,0.15) !important;
        }
        .logout-btn:hover {
            background: #ff5e4a !important;
            color: white !important;
        }

        /* ----- SEARCH BAR ----- */
        .search-container {
            position: relative;
            width: 100%;
            max-width: 700px;
            margin: 20px auto 30px auto;
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
            box-shadow: 0 0 0 4px rgba(44, 94, 46, 0.1);
        }
        .search-icon {
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--forest-green);
            font-size: 1.3rem;
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
            transition: var(--transition-smooth);
            border: 1px solid #ecf3e8;
            height: 100%;
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

        /* FORM STYLES */
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 14px 18px;
            border-radius: 50px;
            border: 2px solid rgba(44, 94, 46, 0.1);
            font-size: 0.95rem;
            background: var(--pure-white);
            transition: var(--transition-smooth);
            outline: none;
            color: var(--charcoal);
            font-weight: 500;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--forest-green);
            box-shadow: 0 0 0 4px rgba(44, 94, 46, 0.08);
        }
        .form-group textarea {
            border-radius: 24px;
            resize: vertical;
            min-height: 90px;
        }

        /* File Input */
        .file-input-container {
            position: relative;
            overflow: hidden;
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
            padding: 14px 18px;
            background: var(--light-mint);
            border: 2px dashed var(--sage);
            border-radius: 50px;
            text-align: center;
            cursor: pointer;
            transition: var(--transition-smooth);
            color: var(--forest-green);
            font-weight: 600;
        }
        .file-input-label:hover {
            background: var(--pure-white);
            border-color: var(--forest-green);
        }
        .photo-preview {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 20px;
            margin-top: 15px;
            display: none;
            border: 3px solid var(--sage);
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
            gap: 10px;
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
        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 20px rgba(44, 94, 46, 0.25);
        }

        /* PRODUCT LIST */
        .product-list-container {
            max-height: 600px;
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
        .product-photo {
            width: 70px;
            height: 70px;
            object-fit: cover;
            border-radius: 18px;
            border: 2px solid var(--light-mint);
        }
        .product-info {
            flex: 1;
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
        .product-meta {
            display: flex;
            gap: 8px;
            margin: 8px 0;
            flex-wrap: wrap;
        }
        .product-category {
            background: var(--sage);
            color: white;
            padding: 4px 14px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .product-supplier {
            background: var(--dark-green);
            color: white;
            padding: 4px 14px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .product-price {
            font-weight: 800;
            color: var(--forest-green);
            font-size: 1.2rem;
        }
        .stock-badge {
            background: var(--forest-green);
            color: white;
            padding: 5px 12px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 0.8rem;
            display: inline-block;
        }
        .stock-low {
            background: #e67e22;
        }
        .stock-out {
            background: #d9534f;
        }
        .action-buttons {
            display: flex;
            gap: 8px;
            margin-top: 10px;
        }
        .action-btn {
            padding: 6px 14px;
            border: none;
            border-radius: 40px;
            color: white;
            font-weight: 600;
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
            transition: 0.2s;
        }
        .view-btn { background: var(--dark-green); }
        .edit-btn { background: var(--forest-green); }
        .del-btn { background: #d9534f; }
        .action-btn:hover {
            transform: translateY(-2px);
            filter: brightness(1.05);
        }

        /* MODAL */
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
            backdrop-filter: blur(5px);
        }
        .modal-content {
            background: var(--pure-white);
            margin: 2% auto;
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-md);
            max-width: 700px;
            width: 100%;
            animation: modalFadeIn 0.3s ease-out;
        }
        .modal-header {
            background: var(--dark-green);
            color: white;
            padding: 20px 25px;
            border-radius: var(--radius-card) var(--radius-card) 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 {
            font-size: 1.4rem;
            display: flex;
            align-items: center;
            gap: 12px;
            font-family: 'Outfit', sans-serif;
        }
        .close-modal {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            font-size: 1.6rem;
            cursor: pointer;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: 0.2s;
        }
        .close-modal:hover {
            background: rgba(255,255,255,0.3);
            transform: rotate(90deg);
        }
        .modal-body {
            padding: 30px;
        }
        .detail-row {
            display: flex;
            margin-bottom: 15px;
            border-bottom: 1px solid #eef2e8;
            padding-bottom: 12px;
        }
        .detail-label {
            font-weight: 700;
            color: var(--dark-green);
            min-width: 140px;
            flex-shrink: 0;
        }
        .detail-value {
            flex: 1;
            color: #2c3e2f;
        }
        .detail-photo {
            text-align: center;
            margin-top: 10px;
        }
        .detail-photo img {
            max-width: 200px;
            border-radius: 20px;
            border: 3px solid var(--light-mint);
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-40px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            background: var(--light-mint);
            border-radius: 28px;
        }
        .empty-state i {
            font-size: 3rem;
            color: var(--sage);
            margin-bottom: 15px;
        }
        .empty-state h3 {
            font-size: 1.2rem;
            color: var(--dark-green);
            margin-bottom: 8px;
        }

        /* FAB Mobile */
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

        /* Spinner */
        .spinner {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 0.6s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Responsive */
        @media (max-width: 992px) {
            .main { grid-template-columns: 1fr; }
            .form-section { display: none; }
            .form-section.active { display: block; }
            .fab { display: flex; }
            .logo { font-size: 1.5rem; }
        }
        @media (max-width: 576px) {
            .container { padding: 12px; }
            .card { padding: 20px; }
            h2 { font-size: 1.4rem; }
            .product-item { flex-direction: column; text-align: center; }
            .action-buttons { justify-content: center; }
            .detail-row { flex-direction: column; gap: 5px; }
            .detail-label { min-width: auto; }
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
            <a href="product1.php" class="active"><i class="fas fa-cubes"></i> Inventory</a>
            <a href="stock_transactions.php"><i class="fas fa-arrows-spin"></i> Movements</a>
            <a href="barcode1.php"><i class="fas fa-qrcode"></i> Barcode</a>
            <a href="suppliers1.php"><i class="fas fa-parachute-box"></i> Suppliers</a>
            <a href="?logout=true" class="logout-btn"><i class="fas fa-arrow-right-from-bracket"></i> Logout</a>
        </div>
    </div>
</header>

<div class="container">
    <!-- SEARCH SECTION -->
    <div class="search-container">
        <i class="fas fa-magnifying-glass search-icon"></i>
        <input type="text" id="searchInput" class="search-box" placeholder="Search by name, ID, category, barcode, supplier...">
    </div>

    <div class="main">
        <!-- FORM CARD: Add/Edit Product -->
        <div class="card form-section" id="formSection">
            <h2 id="formTitle"><i class="fas fa-wand-magic-sparkles"></i> Product Forge</h2>
            
            <form id="productForm" enctype="multipart/form-data">
                <input type="hidden" name="product_id" id="product_id">
                
                <div class="form-group">
                    <input type="text" name="name" id="name" placeholder="✨ Product Name" required>
                </div>
                
                <div class="form-group">
                    <input type="text" name="category" id="category" list="categoryList" placeholder="🏷️ Category" required>
                    <datalist id="categoryList">
                        <?php
                        $cats = $conn->query("SELECT DISTINCT category FROM products2 ORDER BY category");
                        while ($c = $cats->fetch_assoc()) echo "<option value=\"{$c['category']}\">";
                        ?>
                    </datalist>
                </div>
                
                <div class="form-group">
                    <select name="supplier_id" id="supplier_id">
                        <option value="">🏢 Select Supplier (Optional)</option>
                        <?php
                        $suppliers = $conn->query("SELECT * FROM suppliers ORDER BY supplier_name");
                        while ($supplier = $suppliers->fetch_assoc()) {
                            echo "<option value=\"{$supplier['supplier_id']}\">{$supplier['supplier_name']}</option>";
                        }
                        ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <input type="number" step="0.01" name="original_price" id="original_price" placeholder="💰 Original Price" required>
                </div>
                
                <div class="form-group">
                    <input type="number" step="0.01" name="sale_price" id="sale_price" placeholder="🏷️ Sale Price" required>
                </div>
                
                <div class="form-group">
                    <input type="number" name="stock" id="stock" placeholder="📦 Stock Quantity" required>
                </div>
                
                <div class="form-group">
                    <input type="text" name="barcode" id="barcode" placeholder="📊 Barcode (Optional)">
                </div>
                
                <div class="form-group">
                    <input type="text" name="color" id="color" placeholder="🎨 Color (Optional)">
                </div>
                
                <div class="form-group">
                    <textarea name="specific_letter" id="specific_letter" placeholder="📝 Specific Letter/Notes (Optional)" rows="3"></textarea>
                </div>
                
                <div class="form-group">
                    <div class="file-input-container">
                        <label class="file-input-label" for="photo">
                            <i class="fas fa-cloud-upload-alt"></i> 📸 Upload Product Photo
                        </label>
                        <input type="file" name="photo" id="photo" accept="image/*">
                    </div>
                    <img id="preview" class="photo-preview">
                </div>

                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <i class="fas fa-plus"></i> Add Product
                </button>
                
                <button type="button" class="btn btn-secondary" id="resetBtn" style="margin-top: 15px;">
                    <i class="fas fa-rotate-left"></i> Reset Form
                </button>
            </form>
        </div>

        <!-- PRODUCT LIST CARD -->
        <div class="card">
            <h2><i class="fas fa-cubes-stack"></i> Product Shelf</h2>
            <div class="product-list-container" id="productListContainer">
                <?php
                $products = $conn->query("
                    SELECT p.*, s.supplier_name 
                    FROM products2 p 
                    LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id 
                    ORDER BY p.name
                ");
                
                if ($products->num_rows == 0): ?>
                <div class="empty-state">
                    <i class="fas fa-box-open"></i>
                    <h3>No Products Yet</h3>
                    <p>Add your first product to get started!</p>
                </div>
                <?php else: while ($product = $products->fetch_assoc()): 
                    $stockClass = '';
                    if ($product['stock'] <= 0) $stockClass = 'stock-out';
                    else if ($product['stock'] < 10) $stockClass = 'stock-low';
                ?>
                <div class="product-item" data-product='<?= json_encode($product) ?>'>
                    <?php if($product['photo']): ?>
                        <img src="<?= htmlspecialchars($product['photo']) ?>" class="product-photo">
                    <?php else: ?>
                        <div style="width:70px; height:70px; background:var(--light-mint); border-radius:18px; display:flex; align-items:center; justify-content:center;">
                            <i class="fas fa-image" style="font-size:28px; color:var(--forest-green);"></i>
                        </div>
                    <?php endif; ?>
                    
                    <div class="product-info">
                        <div class="product-name"><?= htmlspecialchars($product['name']) ?></div>
                        <div class="product-id">ID: <?= htmlspecialchars($product['product_id']) ?></div>
                        
                        <div class="product-meta">
                            <?php if(!empty($product['category'])): ?>
                                <span class="product-category"><?= htmlspecialchars($product['category']) ?></span>
                            <?php endif; ?>
                            <?php if(!empty($product['supplier_name'])): ?>
                                <span class="product-supplier"><?= htmlspecialchars($product['supplier_name']) ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                            <div class="product-price">LKR <?= number_format($product['sale_price'], 2) ?></div>
                            <span class="stock-badge <?= $stockClass ?>"><?= $product['stock'] ?> in stock</span>
                        </div>
                        
                        <div class="action-buttons">
                            <button class="action-btn view-btn" onclick="event.stopPropagation(); showProduct(<?= htmlspecialchars(json_encode($product)) ?>)">
                                <i class="fas fa-eye"></i> View
                            </button>
                            <button class="action-btn edit-btn" onclick="event.stopPropagation(); editProduct(<?= htmlspecialchars(json_encode($product)) ?>)">
                                <i class="fas fa-pen"></i> Edit
                            </button>
                            <button class="action-btn del-btn" onclick="event.stopPropagation(); deleteProduct('<?= $product['product_id'] ?>')">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </div>
                    </div>
                </div>
                <?php endwhile; endif; ?>
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
        <div class="modal-body" id="modalBody"></div>
    </div>
</div>

<!-- Floating Action Button for Mobile -->
<div class="fab" id="fabToggle">
    <i class="fas fa-plus"></i>
</div>

<script>
// Photo Preview
document.getElementById('photo').addEventListener('change', function(e) {
    const preview = document.getElementById('preview');
    if (e.target.files[0]) {
        preview.src = URL.createObjectURL(e.target.files[0]);
        preview.style.display = 'block';
    } else {
        preview.style.display = 'none';
    }
});

// Price validation
document.getElementById('sale_price').addEventListener('blur', function() {
    const originalPrice = parseFloat(document.getElementById('original_price').value) || 0;
    const salePrice = parseFloat(this.value) || 0;
    if (originalPrice > 0 && salePrice > 0 && salePrice < originalPrice) {
        Swal.fire({
            title: '⚠️ Price Warning',
            text: 'Sale price is less than original price!',
            icon: 'warning',
            confirmButtonColor: '#2c5e2e',
            background: '#fff9f5'
        });
        this.style.borderColor = '#d9534f';
    } else {
        this.style.borderColor = '';
    }
});

// Reset Form
function resetForm() {
    document.getElementById('productForm').reset();
    document.getElementById('product_id').value = '';
    document.getElementById('preview').style.display = 'none';
    document.getElementById('preview').src = '';
    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-plus"></i> Add Product';
    document.getElementById('submitBtn').className = 'btn btn-primary';
    document.getElementById('formTitle').innerHTML = '<i class="fas fa-wand-magic-sparkles"></i> Product Forge';
}
document.getElementById('resetBtn').addEventListener('click', resetForm);

// Form Submit
document.getElementById('productForm').onsubmit = async function(e) {
    e.preventDefault();
    
    const originalPrice = parseFloat(document.getElementById('original_price').value) || 0;
    const salePrice = parseFloat(document.getElementById('sale_price').value) || 0;
    if (salePrice < originalPrice) {
        Swal.fire({ title: '❌ Price Error!', text: 'Sale price cannot be less than original price!', icon: 'error', confirmButtonColor: '#2c5e2e' });
        return false;
    }
    
    const submitBtn = document.getElementById('submitBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<span class="spinner"></span> Processing...';
    submitBtn.disabled = true;
    
    const formData = new FormData(this);
    try {
        const res = await fetch('', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            Swal.fire({ icon: 'success', title: '✨ Success!', text: data.message, timer: 1800, showConfirmButton: false, background: '#fff9f5' });
            setTimeout(() => location.reload(), 1600);
        } else {
            Swal.fire({ title: '❌ Error!', text: data.message, icon: 'error', confirmButtonColor: '#2c5e2e' });
        }
    } catch(err) {
        Swal.fire({ title: '❌ Error!', text: 'Network error!', icon: 'error', confirmButtonColor: '#2c5e2e' });
    } finally {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
};

// Show Product Details
function showProduct(product) {
    const modal = document.getElementById('productModal');
    const modalBody = document.getElementById('modalBody');
    
    let stockStatus = '';
    let stockClass = '';
    if (product.stock >= 10) { stockStatus = 'In Stock'; stockClass = 'stock-badge'; }
    else if (product.stock > 0) { stockStatus = 'Low Stock'; stockClass = 'stock-badge stock-low'; }
    else { stockStatus = 'Out of Stock'; stockClass = 'stock-badge stock-out'; }
    
    modalBody.innerHTML = `
        <div class="detail-row"><div class="detail-label">📋 Product ID:</div><div class="detail-value"><strong>${product.product_id}</strong></div></div>
        <div class="detail-row"><div class="detail-label">✨ Name:</div><div class="detail-value">${escapeHtml(product.name)}</div></div>
        <div class="detail-row"><div class="detail-label">🏷️ Category:</div><div class="detail-value">${escapeHtml(product.category) || '—'}</div></div>
        <div class="detail-row"><div class="detail-label">🏢 Supplier:</div><div class="detail-value">${escapeHtml(product.supplier_name) || 'Not Assigned'}</div></div>
        <div class="detail-row"><div class="detail-label">💰 Original Price:</div><div class="detail-value">LKR ${parseFloat(product.original_price).toFixed(2)}</div></div>
        <div class="detail-row"><div class="detail-label">🏷️ Sale Price:</div><div class="detail-value">LKR ${parseFloat(product.sale_price).toFixed(2)}</div></div>
        <div class="detail-row"><div class="detail-label">📦 Stock:</div><div class="detail-value"><span class="${stockClass}">${product.stock}</span> - ${stockStatus}</div></div>
        <div class="detail-row"><div class="detail-label">📊 Barcode:</div><div class="detail-value">${product.barcode || '<span style="color:#d9534f;">Not Set</span>'}</div></div>
        <div class="detail-row"><div class="detail-label">🎨 Color:</div><div class="detail-value">${product.color || '—'}</div></div>
        <div class="detail-row"><div class="detail-label">📝 Notes:</div><div class="detail-value">${product.specific_letter || '—'}</div></div>
        <div class="detail-row"><div class="detail-label">📸 Photo:</div><div class="detail-value">${product.photo ? `<div class="detail-photo"><img src="${product.photo}" alt="${escapeHtml(product.name)}"></div>` : '<span>No Image</span>'}</div></div>
    `;
    modal.style.display = 'block';
}

function escapeHtml(str) { if(!str) return ''; return str.replace(/[&<>]/g, function(m){if(m==='&') return '&amp;'; if(m==='<') return '&lt;'; if(m==='>') return '&gt;'; return m;}); }

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
    } else {
        document.getElementById('preview').style.display = 'none';
    }
    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-sync"></i> Update Product';
    document.getElementById('submitBtn').className = 'btn btn-secondary';
    document.getElementById('formTitle').innerHTML = '<i class="fas fa-edit"></i> Update Product - ' + p.product_id;
    if (window.innerWidth < 992) {
        document.getElementById('formSection').classList.add('active');
        document.getElementById('fabToggle').querySelector('i').className = 'fas fa-times';
        setTimeout(() => document.getElementById('formSection').scrollIntoView({ behavior: 'smooth' }), 100);
    } else {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

// Delete Product
async function deleteProduct(id) {
    const result = await Swal.fire({
        title: '🗑️ Delete Product?',
        text: "This action cannot be undone!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d9534f',
        cancelButtonColor: '#2c5e2e',
        confirmButtonText: 'Yes, delete',
        cancelButtonText: 'Cancel'
    });
    if (result.isConfirmed) {
        const res = await fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'delete_id=' + id });
        const data = await res.json();
        if (data.success) {
            Swal.fire({ title: '✅ Deleted!', text: 'Product removed.', icon: 'success', timer: 1500, showConfirmButton: false }).then(() => location.reload());
        }
    }
}

// Live Search
document.getElementById('searchInput').addEventListener('keyup', function() {
    const term = this.value.toLowerCase().trim();
    const items = document.querySelectorAll('.product-item');
    let found = false;
    items.forEach(item => {
        const data = item.dataset.product ? JSON.parse(item.dataset.product) : null;
        if (!data) return;
        const text = (data.name + ' ' + data.product_id + ' ' + (data.barcode||'') + ' ' + data.category + ' ' + (data.supplier_name||'') + ' ' + (data.color||'')).toLowerCase();
        if (text.includes(term)) {
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

// Mobile Toggle
document.getElementById('fabToggle').addEventListener('click', function() {
    const form = document.getElementById('formSection');
    form.classList.toggle('active');
    const icon = this.querySelector('i');
    if (form.classList.contains('active')) {
        icon.className = 'fas fa-times';
        form.scrollIntoView({ behavior: 'smooth' });
    } else {
        icon.className = 'fas fa-plus';
    }
});

// Modal close
document.querySelector('.close-modal').onclick = () => document.getElementById('productModal').style.display = 'none';
window.onclick = (e) => { if (e.target == document.getElementById('productModal')) document.getElementById('productModal').style.display = 'none'; };
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') document.getElementById('productModal').style.display = 'none'; });

// Logout confirm
document.querySelector('.logout-btn').addEventListener('click', function(e) {
    if (!confirm('Are you sure you want to logout?')) e.preventDefault();
});

// Touch optimizations
if ('ontouchstart' in window) document.addEventListener('touchstart', function() {}, {passive: true});
</script>

<?php $conn->close(); ?>
</body>
</html>