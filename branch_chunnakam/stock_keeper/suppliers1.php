<?php
// File: suppliers1.php - COMPLETE REDESIGN with dark green & white theme
// Consistent with dashboard, inventory, and barcode pages
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

// ==================== PHP BACKEND (AJAX) ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    // Delete Supplier
    if (isset($_POST['delete_supplier_id'])) {
        $id = $conn->real_escape_string($_POST['delete_supplier_id']);
        
        // Check if supplier has payments in supplier_payments2 table
        $check_payments = $conn->query("SELECT COUNT(*) as count FROM supplier_payments2 WHERE supplier_id = '$id'");
        $payment_count = $check_payments->fetch_assoc()['count'];
        
        // Check if supplier has products in products2 table
        $check_products = $conn->query("SELECT COUNT(*) as count FROM products2 WHERE supplier_id = '$id'");
        $product_count = $check_products->fetch_assoc()['count'];
        
        if ($payment_count > 0 || $product_count > 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete supplier with existing payments or products!']);
            exit;
        }
        
        $conn->query("DELETE FROM suppliers2 WHERE supplier_id = '$id'");
        echo json_encode(['success' => true, 'message' => 'Supplier deleted!']);
        exit;
    }

    // Delete Supplier Payment
    if (isset($_POST['delete_payment_id'])) {
        $id = $conn->real_escape_string($_POST['delete_payment_id']);
        $conn->query("DELETE FROM supplier_payments2 WHERE payment_id = '$id'");
        echo json_encode(['success' => true, 'message' => 'Payment deleted!']);
        exit;
    }

    // Add or Update Supplier
    if (isset($_POST['supplier_name'])) {
        $supplier_id = trim($_POST['supplier_id'] ?? '');
        $supplier_name = $conn->real_escape_string($_POST['supplier_name']);
        $contact_person = $conn->real_escape_string($_POST['contact_person'] ?? '');
        $phone_number = $conn->real_escape_string($_POST['phone_number'] ?? '');
        $email = $conn->real_escape_string($_POST['email'] ?? '');
        $address = $conn->real_escape_string($_POST['address'] ?? '');

        if (empty($supplier_id)) {
            // === ADD NEW SUPPLIER ===
            $sql = "INSERT INTO suppliers2 
                    (supplier_name, contact_person, phone_number, email, address) 
                    VALUES 
                    ('$supplier_name', '$contact_person', '$phone_number', '$email', '$address')";
            $msg = "Supplier added successfully!";
        } else {
            // === UPDATE EXISTING SUPPLIER ===
            $sql = "UPDATE suppliers2 SET 
                    supplier_name='$supplier_name',
                    contact_person='$contact_person',
                    phone_number='$phone_number',
                    email='$email',
                    address='$address'
                    WHERE supplier_id='$supplier_id'";
            $msg = "Supplier updated successfully!";
        }

        if ($conn->query($sql) === TRUE) {
            echo json_encode(['success' => true, 'message' => $msg]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database Error: ' . $conn->error]);
        }
        exit;
    }

    // Add or Update Supplier Payment
    if (isset($_POST['payment_amount'])) {
        $payment_id = trim($_POST['payment_id'] ?? '');
        $supplier_id = $conn->real_escape_string($_POST['payment_supplier_id']);
        $amount = floatval($_POST['payment_amount']);
        $payment_date = $conn->real_escape_string($_POST['payment_date']);

        if (empty($payment_id)) {
            // === ADD NEW PAYMENT ===
            $sql = "INSERT INTO supplier_payments2
                    (supplier_id, amount, payment_date) 
                    VALUES 
                    ('$supplier_id', $amount, '$payment_date')";
            $msg = "Payment added successfully!";
        } else {
            // === UPDATE EXISTING PAYMENT ===
            $sql = "UPDATE supplier_payments2 SET 
                    supplier_id='$supplier_id',
                    amount=$amount,
                    payment_date='$payment_date'
                    WHERE payment_id='$payment_id'";
            $msg = "Payment updated successfully!";
        }

        if ($conn->query($sql) === TRUE) {
            echo json_encode(['success' => true, 'message' => $msg]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database Error: ' . $conn->error]);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>🤝 Supplier Hub · KidsBerry Mega Centre</title>
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ----------------------------------------------------------------------
           DARK GREEN & WHITE · MODERN · TRENDY · FULLY RESPONSIVE
           - Consistent with Dashboard, Inventory, and Barcode pages
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
            max-width: 500px;
            margin: 10px 0 25px 0;
        }
        .search-box {
            width: 100%;
            padding: 14px 20px 14px 45px;
            border-radius: 80px;
            border: none;
            font-size: 0.95rem;
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
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--forest-green);
            font-size: 1.1rem;
        }
        .clear-icon {
            position: absolute;
            right: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--forest-green);
            font-size: 1rem;
            cursor: pointer;
            transition: 0.2s;
        }
        .clear-icon:hover {
            color: var(--coral-soft);
        }

        /* ----- SECTION TABS ----- */
        .section-tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }
        .section-tab {
            padding: 12px 24px;
            border-radius: 60px;
            background: var(--pure-white);
            color: var(--charcoal);
            font-weight: 700;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: var(--shadow-sm);
            cursor: pointer;
            transition: var(--transition-smooth);
            border: 2px solid transparent;
        }
        .section-tab i {
            color: var(--forest-green);
            font-size: 1rem;
        }
        .section-tab.active {
            background: var(--forest-green);
            color: white;
            box-shadow: 0 8px 20px rgba(44, 94, 46, 0.25);
            transform: translateY(-2px);
        }
        .section-tab.active i {
            color: white;
        }
        .section-tab:hover {
            border-color: var(--sage);
            transform: translateY(-1px);
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
            font-size: 1.5rem;
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
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--charcoal);
            font-size: 0.85rem;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border-radius: 50px;
            border: 2px solid rgba(44, 94, 46, 0.1);
            font-size: 0.95rem;
            background: var(--pure-white);
            transition: var(--transition-smooth);
            outline: none;
            color: var(--charcoal);
        }
        .form-group textarea {
            border-radius: 24px;
            resize: vertical;
            min-height: 80px;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--forest-green);
            box-shadow: 0 0 0 4px rgba(44, 94, 46, 0.08);
        }

        .form-buttons {
            display: flex;
            gap: 15px;
            margin-top: 25px;
        }

        /* BUTTONS */
        .btn {
            padding: 12px 24px;
            border-radius: 60px;
            font-size: 0.95rem;
            font-weight: 700;
            border: none;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            flex: 1;
            transition: var(--transition-smooth);
            cursor: pointer;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--forest-green), #3e7c41);
        }
        .btn-secondary {
            background: linear-gradient(135deg, var(--dark-green), #2c5e2e);
        }
        .btn-info {
            background: linear-gradient(135deg, #5a6e60, #4a5b52);
        }
        .btn-danger {
            background: linear-gradient(135deg, #d9534f, #c9302c);
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(44, 94, 46, 0.2);
        }

        /* TABLES */
        .table-container {
            max-height: 550px;
            overflow-y: auto;
            border-radius: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: var(--pure-white);
            border-radius: 20px;
            overflow: hidden;
        }
        th {
            background: var(--light-mint);
            color: var(--dark-green);
            padding: 14px 12px;
            text-align: left;
            font-weight: 700;
            font-size: 0.85rem;
            position: sticky;
            top: 0;
        }
        td {
            padding: 14px 12px;
            border-bottom: 1px solid #eef2e8;
            color: var(--charcoal);
            font-size: 0.9rem;
        }
        tr:hover td {
            background: var(--off-white);
        }

        /* ACTION BUTTONS */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .action-btn {
            padding: 6px 14px;
            border: none;
            border-radius: 40px;
            color: white;
            cursor: pointer;
            transition: 0.2s;
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            gap: 5px;
            font-weight: 600;
        }
        .edit-btn {
            background: var(--forest-green);
        }
        .del-btn {
            background: #d9534f;
        }
        .action-btn:hover {
            transform: translateY(-1px);
            filter: brightness(1.05);
        }

        /* BADGES & SUMMARY CARDS */
        .summary-card {
            background: var(--light-mint);
            border-radius: 20px;
            padding: 18px 24px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            border-left: 5px solid var(--forest-green);
        }
        .summary-card h3 {
            color: var(--dark-green);
            font-size: 1.1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .summary-amount {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--forest-green);
        }
        .value-badge {
            background: var(--forest-green);
            color: white;
            padding: 4px 12px;
            border-radius: 40px;
            font-weight: 700;
            display: inline-block;
            font-size: 0.8rem;
        }
        .stock-badge {
            background: var(--dark-green);
            color: white;
            padding: 4px 12px;
            border-radius: 40px;
            font-weight: 700;
            display: inline-block;
            font-size: 0.8rem;
        }

        /* FILTER DROPDOWN */
        .supplier-filter {
            margin-bottom: 20px;
        }
        .supplier-filter select {
            width: 100%;
            padding: 12px 18px;
            border-radius: 60px;
            border: 2px solid rgba(44, 94, 46, 0.1);
            font-size: 0.9rem;
            background: var(--pure-white);
        }

        /* EMPTY STATE */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #8aae8c;
        }
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
        }
        .empty-state h3 {
            font-size: 1.2rem;
            margin-bottom: 8px;
            color: var(--dark-green);
        }

        /* FORM SECTION VISIBILITY */
        .form-section, .table-section {
            display: none;
        }
        .form-section.active, .table-section.active {
            display: block;
        }

        /* SPINNER */
        .spinner {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 0.6s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
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

        /* RESPONSIVE */
        @media (max-width: 992px) {
            .main { grid-template-columns: 1fr; }
            .container { padding: 16px; }
            .fab { display: flex; }
        }
        @media (max-width: 576px) {
            .section-tab { padding: 8px 16px; font-size: 0.8rem; }
            h2 { font-size: 1.3rem; }
            .summary-amount { font-size: 1.3rem; }
            .action-buttons { flex-direction: column; gap: 5px; }
            .action-btn { justify-content: center; }
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
            <a href="barcode1.php"><i class="fas fa-qrcode"></i> Barcode</a>
            <a href="suppliers1.php" class="active"><i class="fas fa-parachute-box"></i> Suppliers</a>
            <a href="?logout=true" class="logout-btn"><i class="fas fa-arrow-right-from-bracket"></i> Logout</a>
        </div>
    </div>
</header>

<div class="container">
    <!-- SECTION TABS -->
    <div class="section-tabs">
        <div class="section-tab active" data-section="suppliers">
            <i class="fas fa-truck"></i> Suppliers
        </div>
        <div class="section-tab" data-section="payments">
            <i class="fas fa-coins"></i> Payments
        </div>
        <div class="section-tab" data-section="productTotals">
            <i class="fas fa-cubes-stack"></i> Product Totals
        </div>
    </div>

    <!-- SEARCH (Suppliers only) -->
    <div class="search-container" id="supplierSearchContainer">
        <i class="fas fa-search search-icon"></i>
        <input type="text" id="supplierSearch" class="search-box" placeholder="Search suppliers by name, contact, phone...">
        <i class="fas fa-times-circle clear-icon" id="clearSupplierSearch"></i>
    </div>

    <div class="main">
        <!-- ============ SUPPLIERS SECTION ============ -->
        <div class="card form-section active" id="suppliersFormSection">
            <h2 id="suppliersFormTitle"><i class="fas fa-plus-circle"></i> Add Supplier</h2>
            
            <form id="supplierForm">
                <input type="hidden" name="supplier_id" id="supplier_id">
                
                <div class="form-group">
                    <label><i class="fas fa-building"></i> Supplier Name *</label>
                    <input type="text" name="supplier_name" id="supplier_name" placeholder="e.g. Kids Fashion Ltd." required>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Contact Person</label>
                    <input type="text" name="contact_person" id="contact_person" placeholder="e.g. John Doe">
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-phone"></i> Phone Number</label>
                    <input type="tel" name="phone_number" id="phone_number" placeholder="e.g. 0771234567">
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-envelope"></i> Email</label>
                    <input type="email" name="email" id="email" placeholder="contact@supplier.com">
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-location-dot"></i> Address</label>
                    <textarea name="address" id="address" placeholder="Full address..."></textarea>
                </div>

                <div class="form-buttons">
                    <button type="submit" class="btn btn-primary" id="supplierSubmitBtn">
                        <i class="fas fa-save"></i> Save Supplier
                    </button>
                    <button type="button" class="btn btn-info" id="supplierResetBtn">
                        <i class="fas fa-undo-alt"></i> Reset
                    </button>
                </div>
            </form>
        </div>

        <div class="card table-section active" id="suppliersTableSection">
            <h2><i class="fas fa-list"></i> Supplier Registry</h2>
            <div class="table-container">
                <table id="supplierTable">
                    <thead>
                        <tr><th>ID</th><th>Supplier</th><th>Contact</th><th>Phone</th><th>Email</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php
                        $suppliers = $conn->query("SELECT * FROM suppliers2 ORDER BY supplier_name");
                        if ($suppliers->num_rows == 0):
                        ?>
                        <tr><td colspan="6"><div class="empty-state"><i class="fas fa-truck"></i><h3>No Suppliers Yet</h3><p>Add your first supplier to get started!</p></div></td></tr>
                        <?php else: while($row = $suppliers->fetch_assoc()): ?>
                        <tr>
                            <td><strong style="color: var(--forest-green);">#<?=htmlspecialchars($row['supplier_id'])?></strong></td>
                            <td style="font-weight: 700;"><?=htmlspecialchars($row['supplier_name'])?></td>
                            <td><?=htmlspecialchars($row['contact_person'] ?: '—')?></td>
                            <td><?=htmlspecialchars($row['phone_number'] ?: '—')?></td>
                            <td><?=htmlspecialchars($row['email'] ?: '—')?></td>
                            <td>
                                <div class="action-buttons">
                                    <button class="action-btn edit-btn" onclick='editSupplier(<?=json_encode($row)?>)'><i class="fas fa-edit"></i> Edit</button>
                                    <button class="action-btn del-btn" onclick="deleteSupplier('<?=$row['supplier_id']?>')"><i class="fas fa-trash"></i> Delete</button>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ============ PAYMENTS SECTION ============ -->
        <div class="card form-section" id="paymentsFormSection">
            <h2 id="paymentsFormTitle"><i class="fas fa-plus-circle"></i> Record Payment</h2>
            
            <form id="paymentForm">
                <input type="hidden" name="payment_id" id="payment_id">
                
                <div class="form-group">
                    <label><i class="fas fa-truck"></i> Select Supplier *</label>
                    <select name="payment_supplier_id" id="payment_supplier_id" required>
                        <option value="">— Choose Supplier —</option>
                        <?php
                        $suppliers = $conn->query("SELECT * FROM suppliers2 ORDER BY supplier_name");
                        while ($supplier = $suppliers->fetch_assoc()) {
                            echo "<option value=\"{$supplier['supplier_id']}\">{$supplier['supplier_name']}</option>";
                        }
                        ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-money-bill-wave"></i> Payment Amount (Rs.) *</label>
                    <input type="number" step="0.01" name="payment_amount" id="payment_amount" placeholder="e.g. 25000.00" required>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-calendar"></i> Payment Date *</label>
                    <input type="date" name="payment_date" id="payment_date" required>
                </div>

                <div class="form-buttons">
                    <button type="submit" class="btn btn-secondary" id="paymentSubmitBtn">
                        <i class="fas fa-save"></i> Save Payment
                    </button>
                    <button type="button" class="btn btn-info" id="paymentResetBtn">
                        <i class="fas fa-undo-alt"></i> Reset
                    </button>
                </div>
            </form>
        </div>

        <div class="card table-section" id="paymentsTableSection">
            <h2><i class="fas fa-coins"></i> Payment History</h2>
            
            <div class="supplier-filter">
                <select id="paymentFilter">
                    <option value="">All Suppliers</option>
                    <?php
                    $suppliers = $conn->query("SELECT * FROM suppliers2 ORDER BY supplier_name");
                    while ($supplier = $suppliers->fetch_assoc()) {
                        echo "<option value=\"{$supplier['supplier_id']}\">{$supplier['supplier_name']}</option>";
                    }
                    ?>
                </select>
            </div>
            
            <?php
            $totalPayments = 0;
            $paymentsRes = $conn->query("SELECT SUM(amount) as total FROM supplier_payments2");
            if ($paymentsRes && $paymentsRow = $paymentsRes->fetch_assoc()) {
                $totalPayments = $paymentsRow['total'] ?? 0;
            }
            ?>
            
            <div class="summary-card">
                <h3><i class="fas fa-circle-dollar-to-slot"></i> Total Payments</h3>
                <span class="summary-amount">Rs. <?=number_format($totalPayments, 2)?></span>
            </div>
            
            <div class="table-container">
                <table id="paymentTable">
                    <thead>
                        <tr><th>ID</th><th>Supplier</th><th>Amount</th><th>Date</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php
                        $payments = $conn->query("
                            SELECT p.*, s.supplier_name 
                            FROM supplier_payments2 p 
                            LEFT JOIN suppliers2 s ON p.supplier_id = s.supplier_id 
                            ORDER BY p.payment_date DESC
                        ");
                        if ($payments->num_rows == 0):
                        ?>
                        <tr><td colspan="5"><div class="empty-state"><i class="fas fa-money-bill-wave"></i><h3>No Payments Yet</h3><p>Record your first supplier payment!</p></div></td></tr>
                        <?php else: while($payment = $payments->fetch_assoc()): ?>
                        <tr data-supplier-id="<?=$payment['supplier_id']?>">
                            <td><strong style="color: var(--forest-green);">#<?=htmlspecialchars($payment['payment_id'])?></strong></td>
                            <td style="font-weight: 600;"><?=htmlspecialchars($payment['supplier_name'])?></td>
                            <td><span class="value-badge">Rs. <?=number_format($payment['amount'],2)?></span></td>
                            <td><?=date('M d, Y', strtotime($payment['payment_date']))?></td>
                            <td>
                                <div class="action-buttons">
                                    <button class="action-btn edit-btn" onclick='editPayment(<?=json_encode($payment)?>)'><i class="fas fa-edit"></i> Edit</button>
                                    <button class="action-btn del-btn" onclick="deletePayment('<?=$payment['payment_id']?>')"><i class="fas fa-trash"></i> Delete</button>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ============ PRODUCT TOTALS SECTION ============ -->
        <div class="card table-section" id="productTotalsTableSection">
            <h2><i class="fas fa-chart-bar"></i> Supplier Product Summary</h2>
            
            <?php
            $productTotals = $conn->query("
                SELECT 
                    s.supplier_id,
                    s.supplier_name,
                    COUNT(p.product_id) as product_count,
                    IFNULL(SUM(p.stock), 0) as total_stock,
                    IFNULL(SUM(p.original_price * p.stock), 0) as total_original_value,
                    IFNULL(SUM(p.sale_price * p.stock), 0) as total_sale_value
                FROM suppliers2 s
                LEFT JOIN products2 p ON s.supplier_id = p.supplier_id
                GROUP BY s.supplier_id, s.supplier_name
                ORDER BY s.supplier_name
            ");
            
            $grandTotalOriginal = 0;
            $grandTotalProducts = 0;
            $grandTotalStock = 0;
            
            while ($row = $productTotals->fetch_assoc()) {
                $grandTotalOriginal += $row['total_original_value'];
                $grandTotalProducts += $row['product_count'];
                $grandTotalStock += $row['total_stock'];
            }
            $productTotals->data_seek(0);
            ?>
            
            <div class="summary-card">
                <div>
                    <h3><i class="fas fa-boxes"></i> Inventory Value</h3>
                    <div style="display: flex; gap: 20px; margin-top: 8px; flex-wrap: wrap;">
                        <span><strong>Products:</strong> <?=$grandTotalProducts?></span>
                        <span><strong>Stock:</strong> <?=$grandTotalStock?> units</span>
                    </div>
                </div>
                <div>
                    <div style="font-size: 0.8rem; color: var(--charcoal);">Total Original Value</div>
                    <span class="summary-amount">Rs. <?=number_format($grandTotalOriginal, 2)?></span>
                </div>
            </div>
            
            <div class="table-container">
                <table id="productTotalsTable">
                    <thead>
                        <tr><th>ID</th><th>Supplier</th><th>Products</th><th>Total Stock</th><th>Original Value</th><th>Sale Value</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($productTotals->num_rows == 0 || $grandTotalProducts == 0): ?>
                        <tr><td colspan="6"><div class="empty-state"><i class="fas fa-box-open"></i><h3>No Products Yet</h3><p>Add products to see supplier summaries!</p></div></td></tr>
                        <?php else: while($row = $productTotals->fetch_assoc()): 
                            if ($row['product_count'] == 0) continue; ?>
                        <tr>
                            <td><strong style="color: var(--forest-green);">#<?=htmlspecialchars($row['supplier_id'])?></strong></td>
                            <td style="font-weight: 700;"><?=htmlspecialchars($row['supplier_name'])?></td>
                            <td><span class="stock-badge"><?=$row['product_count']?></span></td>
                            <td><span class="stock-badge"><?=$row['total_stock']?></span></td>
                            <td><span class="value-badge">Rs. <?=number_format($row['total_original_value'], 2)?></span></td>
                            <td><span class="value-badge" style="background: var(--forest-green);">Rs. <?=number_format($row['total_sale_value'], 2)?></span></td>
                        </tr>
                        <?php endwhile; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Floating Action Button for Mobile -->
<div class="fab" id="fabToggle">
    <i class="fas fa-plus"></i>
</div>

<script>
(function() {
    // ============ SECTION TAB SWITCHING ============
    const tabs = document.querySelectorAll('.section-tab');
    const sections = {
        suppliers: ['suppliersFormSection', 'suppliersTableSection'],
        payments: ['paymentsFormSection', 'paymentsTableSection'],
        productTotals: ['productTotalsTableSection']
    };

    function activateSection(sectionName) {
        document.querySelectorAll('.form-section, .table-section').forEach(el => {
            el.classList.remove('active');
        });
        
        sections[sectionName].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.classList.add('active');
        });
        
        tabs.forEach(tab => {
            tab.classList.remove('active');
            if (tab.dataset.section === sectionName) {
                tab.classList.add('active');
            }
        });
        
        const searchContainer = document.getElementById('supplierSearchContainer');
        if (searchContainer) {
            searchContainer.style.display = sectionName === 'suppliers' ? 'block' : 'none';
        }
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            activateSection(this.dataset.section);
        });
    });

    // ============ SUPPLIER SEARCH ============
    const supplierSearch = document.getElementById('supplierSearch');
    const clearSearch = document.getElementById('clearSupplierSearch');
    
    if (supplierSearch) {
        supplierSearch.addEventListener('keyup', function() {
            const term = this.value.toLowerCase();
            const rows = document.querySelectorAll('#supplierTable tbody tr');
            rows.forEach(row => {
                if (row.querySelector('.empty-state')) return;
                const text = row.innerText.toLowerCase();
                row.style.display = text.includes(term) ? '' : 'none';
            });
        });
        
        clearSearch.addEventListener('click', function() {
            supplierSearch.value = '';
            const rows = document.querySelectorAll('#supplierTable tbody tr');
            rows.forEach(row => row.style.display = '');
            supplierSearch.focus();
        });
    }

    // ============ SUPPLIER FORM FUNCTIONS ============
    window.resetSupplierForm = function() {
        document.getElementById('supplierForm').reset();
        document.getElementById('supplier_id').value = '';
        document.getElementById('supplierSubmitBtn').innerHTML = '<i class="fas fa-save"></i> Save Supplier';
        document.getElementById('supplierSubmitBtn').className = 'btn btn-primary';
        document.getElementById('suppliersFormTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Add Supplier';
    };

    window.editSupplier = function(s) {
        document.getElementById('supplier_id').value = s.supplier_id;
        document.getElementById('supplier_name').value = s.supplier_name;
        document.getElementById('contact_person').value = s.contact_person || '';
        document.getElementById('phone_number').value = s.phone_number || '';
        document.getElementById('email').value = s.email || '';
        document.getElementById('address').value = s.address || '';

        document.getElementById('supplierSubmitBtn').innerHTML = '<i class="fas fa-edit"></i> Update Supplier';
        document.getElementById('supplierSubmitBtn').className = 'btn btn-secondary';
        document.getElementById('suppliersFormTitle').innerHTML = '<i class="fas fa-edit"></i> Update Supplier #' + s.supplier_id;

        activateSection('suppliers');
        
        if (window.innerWidth < 992) {
            document.getElementById('suppliersFormSection').classList.add('active');
            document.getElementById('suppliersFormSection').scrollIntoView({ behavior: 'smooth' });
        }
    };

    // ============ PAYMENT FORM FUNCTIONS ============
    window.resetPaymentForm = function() {
        document.getElementById('paymentForm').reset();
        document.getElementById('payment_id').value = '';
        document.getElementById('paymentSubmitBtn').innerHTML = '<i class="fas fa-save"></i> Save Payment';
        document.getElementById('paymentSubmitBtn').className = 'btn btn-secondary';
        document.getElementById('paymentsFormTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Record Payment';
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('payment_date').value = today;
    };

    window.editPayment = function(p) {
        document.getElementById('payment_id').value = p.payment_id;
        document.getElementById('payment_supplier_id').value = p.supplier_id;
        document.getElementById('payment_amount').value = p.amount;
        document.getElementById('payment_date').value = p.payment_date;

        document.getElementById('paymentSubmitBtn').innerHTML = '<i class="fas fa-edit"></i> Update Payment';
        document.getElementById('paymentSubmitBtn').className = 'btn btn-info';
        document.getElementById('paymentsFormTitle').innerHTML = '<i class="fas fa-edit"></i> Update Payment #' + p.payment_id;

        activateSection('payments');
        
        if (window.innerWidth < 992) {
            document.getElementById('paymentsFormSection').classList.add('active');
            document.getElementById('paymentsFormSection').scrollIntoView({ behavior: 'smooth' });
        }
    };

    // ============ PAYMENT FILTER ============
    const paymentFilter = document.getElementById('paymentFilter');
    if (paymentFilter) {
        paymentFilter.addEventListener('change', function() {
            const supplierId = this.value;
            const rows = document.querySelectorAll('#paymentTable tbody tr');
            let totalAmount = 0;
            
            rows.forEach(row => {
                if (row.querySelector('.empty-state')) return;
                const rowSupplierId = row.getAttribute('data-supplier-id');
                if (supplierId === '' || rowSupplierId === supplierId) {
                    row.style.display = '';
                    const amountSpan = row.querySelector('.value-badge');
                    if (amountSpan) {
                        const amountText = amountSpan.innerText.replace('Rs. ', '').replace(/,/g, '');
                        totalAmount += parseFloat(amountText);
                    }
                } else {
                    row.style.display = 'none';
                }
            });
            
            const summaryAmount = document.querySelector('#paymentsTableSection .summary-amount');
            if (summaryAmount) {
                if (supplierId === '') {
                    summaryAmount.innerText = 'Rs. <?=number_format($totalPayments, 2)?>';
                } else {
                    summaryAmount.innerText = 'Rs. ' + totalAmount.toFixed(2);
                }
            }
        });
    }

    // ============ DELETE FUNCTIONS ============
    window.deleteSupplier = async function(id) {
        const result = await Swal.fire({
            title: 'Delete Supplier?',
            text: "This action cannot be undone!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d9534f',
            cancelButtonColor: '#8aae8c',
            confirmButtonText: 'Yes, delete',
            cancelButtonText: 'Cancel',
            background: 'var(--pure-white)'
        });

        if (result.isConfirmed) {
            const res = await fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'delete_supplier_id=' + id
            });
            const data = await res.json();
            
            if (data.success) {
                Swal.fire({ title: 'Deleted!', text: data.message, icon: 'success', timer: 1500, showConfirmButton: false }).then(() => location.reload());
            } else {
                Swal.fire({ title: 'Error!', text: data.message, icon: 'error', background: '#fff9f5' });
            }
        }
    };

    window.deletePayment = async function(id) {
        const result = await Swal.fire({
            title: 'Delete Payment?',
            text: "This payment record will be removed!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d9534f',
            cancelButtonColor: '#8aae8c',
            confirmButtonText: 'Yes, delete',
            cancelButtonText: 'Cancel',
            background: 'var(--pure-white)'
        });

        if (result.isConfirmed) {
            const res = await fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'delete_payment_id=' + id
            });
            const data = await res.json();
            
            if (data.success) {
                Swal.fire({ title: 'Deleted!', text: data.message, icon: 'success', timer: 1500, showConfirmButton: false }).then(() => location.reload());
            }
        }
    };

    // ============ FORM SUBMIT HANDLERS ============
    document.getElementById('supplierForm').onsubmit = async function(e) {
        e.preventDefault();
        const submitBtn = document.getElementById('supplierSubmitBtn');
        const originalText = submitBtn.innerHTML;
        
        submitBtn.innerHTML = '<span class="spinner"></span> Processing...';
        submitBtn.disabled = true;
        
        const formData = new FormData(this);
        try {
            const res = await fetch('', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Success!', text: data.message, timer: 1500, showConfirmButton: false }).then(() => location.reload());
            } else {
                Swal.fire({ title: 'Error!', text: data.message, icon: 'error' });
            }
        } catch(err) {
            Swal.fire({ title: 'Error!', text: 'Network or server error!', icon: 'error' });
        } finally {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    };

    document.getElementById('paymentForm').onsubmit = async function(e) {
        e.preventDefault();
        const submitBtn = document.getElementById('paymentSubmitBtn');
        const originalText = submitBtn.innerHTML;
        
        submitBtn.innerHTML = '<span class="spinner"></span> Processing...';
        submitBtn.disabled = true;
        
        const formData = new FormData(this);
        try {
            const res = await fetch('', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Success!', text: data.message, timer: 1500, showConfirmButton: false }).then(() => location.reload());
            } else {
                Swal.fire({ title: 'Error!', text: data.message, icon: 'error' });
            }
        } catch(err) {
            Swal.fire({ title: 'Error!', text: 'Network or server error!', icon: 'error' });
        } finally {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    };

    // ============ RESET BUTTONS ============
    document.getElementById('supplierResetBtn').addEventListener('click', resetSupplierForm);
    document.getElementById('paymentResetBtn').addEventListener('click', resetPaymentForm);

    // ============ MOBILE FAB ============
    const fab = document.getElementById('fabToggle');
    if (fab) {
        fab.addEventListener('click', function() {
            const activeTab = document.querySelector('.section-tab.active');
            if (!activeTab) return;
            
            const section = activeTab.dataset.section;
            let formSection = null;
            
            if (section === 'suppliers') {
                formSection = document.getElementById('suppliersFormSection');
            } else if (section === 'payments') {
                formSection = document.getElementById('paymentsFormSection');
            }
            
            if (formSection) {
                formSection.classList.toggle('active');
                const icon = this.querySelector('i');
                if (formSection.classList.contains('active')) {
                    icon.className = 'fas fa-times';
                    formSection.scrollIntoView({ behavior: 'smooth' });
                } else {
                    icon.className = 'fas fa-plus';
                }
            }
        });
    }

    // ============ LOGOUT CONFIRMATION ============
    document.querySelector('.logout-btn').addEventListener('click', function(e) {
        if (!confirm('Are you sure you want to logout?')) {
            e.preventDefault();
        }
    });

    // ============ SET DEFAULT DATE ============
    const today = new Date().toISOString().split('T')[0];
    if (document.getElementById('payment_date') && !document.getElementById('payment_date').value) {
        document.getElementById('payment_date').value = today;
    }

})();
</script>

<?php $conn->close(); ?>
</body>
</html>