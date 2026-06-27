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

$conn = new mysqli("localhost", "root", "", "kidsberry");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

// ==================== PHP BACKEND (AJAX) ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    // Delete Supplier
    if (isset($_POST['delete_supplier_id'])) {
        $id = $conn->real_escape_string($_POST['delete_supplier_id']);
        
        // Check if supplier has payments
        $check_payments = $conn->query("SELECT COUNT(*) as count FROM supplier_payments WHERE supplier_id = '$id'");
        $payment_count = $check_payments->fetch_assoc()['count'];
        
        // Check if supplier has products
        $check_products = $conn->query("SELECT COUNT(*) as count FROM products WHERE supplier_id = '$id'");
        $product_count = $check_products->fetch_assoc()['count'];
        
        if ($payment_count > 0 || $product_count > 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete supplier with existing payments or products!']);
            exit;
        }
        
        $conn->query("DELETE FROM suppliers WHERE supplier_id = '$id'");
        echo json_encode(['success' => true, 'message' => 'Supplier deleted!']);
        exit;
    }

    // Delete Supplier Payment
    if (isset($_POST['delete_payment_id'])) {
        $id = $conn->real_escape_string($_POST['delete_payment_id']);
        $conn->query("DELETE FROM supplier_payments WHERE payment_id = '$id'");
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
            $sql = "INSERT INTO suppliers 
                    (supplier_name, contact_person, phone_number, email, address) 
                    VALUES 
                    ('$supplier_name', '$contact_person', '$phone_number', '$email', '$address')";

            $msg = "Supplier added successfully!";
        } else {
            // === UPDATE EXISTING SUPPLIER ===
            $sql = "UPDATE suppliers SET 
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
            $sql = "INSERT INTO supplier_payments 
                    (supplier_id, amount, payment_date) 
                    VALUES 
                    ('$supplier_id', $amount, '$payment_date')";

            $msg = "Payment added successfully!";
        } else {
            // === UPDATE EXISTING PAYMENT ===
            $sql = "UPDATE supplier_payments SET 
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Suppliers - Kids Berry</title>
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
        
        .btn-blue {
            background: var(--gradient-blue);
        }
        
        .btn-orange {
            background: var(--gradient-orange);
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
            white-space: nowrap;
        }
        
        td { 
            padding: 12px; 
            border-bottom: 1px solid var(--medium-gray); 
            transition: var(--transition);
            font-size: 0.85rem;
            white-space: nowrap;
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
        
        /* Dropdown Selector */
        .section-selector {
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .section-selector select {
            flex: 1;
            max-width: 300px;
        }
        
        .section-selector label {
            font-weight: bold;
            color: var(--primary-purple);
            white-space: nowrap;
            font-weight: 800;
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
            
            /* Desktop Table Styles - Larger and more readable */
            th, td {
                padding: 18px 15px;
                font-size: 1rem;
            }
            
            table {
                min-width: 900px; /* Increased minimum width for better desktop layout */
            }
            
            .action-btn {
                padding: 10px 14px;
                font-size: 0.9rem;
            }
            
            .search-box {
                padding: 18px 25px 18px 50px;
                font-size: 1.1rem;
            }
            
            .search-icon {
                font-size: 1.2rem;
            }
            
            /* Better table layout for desktop */
            #supplierTable th:nth-child(1),
            #supplierTable td:nth-child(1) {
                width: 80px; /* ID column */
            }
            
            #supplierTable th:nth-child(2),
            #supplierTable td:nth-child(2) {
                width: 180px; /* Name column */
            }
            
            #supplierTable th:nth-child(3),
            #supplierTable td:nth-child(3) {
                width: 150px; /* Contact Person column */
            }
            
            #supplierTable th:nth-child(4),
            #supplierTable td:nth-child(4) {
                width: 140px; /* Phone column */
            }
            
            #supplierTable th:nth-child(5),
            #supplierTable td:nth-child(5) {
                width: 200px; /* Email column */
            }
            
            #supplierTable th:nth-child(6),
            #supplierTable td:nth-child(6) {
                width: 180px; /* Actions column */
            }
            
            /* Payment table column widths */
            #paymentTable th:nth-child(1),
            #paymentTable td:nth-child(1) {
                width: 80px; /* ID column */
            }
            
            #paymentTable th:nth-child(2),
            #paymentTable td:nth-child(2) {
                width: 200px; /* Supplier column */
            }
            
            #paymentTable th:nth-child(3),
            #paymentTable td:nth-child(3) {
                width: 120px; /* Amount column */
            }
            
            #paymentTable th:nth-child(4),
            #paymentTable td:nth-child(4) {
                width: 130px; /* Date column */
            }
            
            #paymentTable th:nth-child(5),
            #paymentTable td:nth-child(5) {
                width: 180px; /* Actions column */
            }
            
            /* Product totals table column widths */
            #productTotalsTable th:nth-child(1),
            #productTotalsTable td:nth-child(1) {
                width: 80px; /* ID column */
            }
            
            #productTotalsTable th:nth-child(2),
            #productTotalsTable td:nth-child(2) {
                width: 180px; /* Supplier column */
            }
            
            #productTotalsTable th:nth-child(3),
            #productTotalsTable td:nth-child(3) {
                width: 100px; /* Products column */
            }
            
            #productTotalsTable th:nth-child(4),
            #productTotalsTable td:nth-child(4) {
                width: 150px; /* Total Stock column */
            }
            
            #productTotalsTable th:nth-child(5),
            #productTotalsTable td:nth-child(5) {
                width: 150px; /* Total Original Price column */
            }
            
            #productTotalsTable th:nth-child(6),
            #productTotalsTable td:nth-child(6) {
                width: 150px; /* Total Sale Price column */
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
        
        /* Payment Summary */
        .payment-summary {
            background: var(--light-blue);
            padding: 15px;
            border-radius: var(--border-radius-small);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .payment-summary h3 {
            color: var(--primary-blue);
            margin: 0;
        }
        
        .payment-summary .amount {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--primary-blue);
        }
        
        /* Product Totals Summary */
        .product-totals-summary {
            background: var(--light-orange);
            padding: 15px;
            border-radius: var(--border-radius-small);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .product-totals-summary h3 {
            color: var(--primary-orange);
            margin: 0;
        }
        
        .product-totals-summary .amount {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--primary-orange);
        }
        
        /* Supplier Filter for Payments - Simplified */
        .supplier-filter {
            margin-bottom: 20px;
        }
        
        .supplier-filter select {
            width: 100%;
        }
        
        /* Improved spacing for forms and tables */
        .form-buttons {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 20px;
        }
        
        .table-section {
            margin-top: 25px;
        }
        
        /* Value Badge */
        .value-badge {
            background: var(--gradient-primary);
            color: var(--white);
            padding: 8px 12px;
            border-radius: var(--border-radius-small);
            font-weight: bold;
            display: inline-block;
            text-align: center;
            min-width: 100px;
            box-shadow: var(--shadow-light);
            font-size: 0.9rem;
        }
        
        .stock-badge {
            background: var(--gradient-secondary);
            color: var(--white);
            padding: 5px 10px;
            border-radius: var(--border-radius-small);
            font-weight: bold;
            display: inline-block;
            text-align: center;
            min-width: 40px;
            box-shadow: var(--shadow-light);
            font-size: 0.8rem;
        }
        
        /* Desktop-only: Product Totals Table below Payments Table */
        @media (min-width: 768px) {
            .desktop-product-totals-section {
                display: block;
                margin-top: 30px;
            }
            
            /* Hide the dropdown version in desktop view */
            .product-totals-table-section {
                display: none;
            }
        }
        
        @media (max-width: 767px) {
            .desktop-product-totals-section {
                display: none;
            }
            
            /* Show the dropdown version in mobile view */
            .product-totals-table-section {
                display: block;
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
            <a href="barcode.php"><i class="fas fa-barcode"></i> Barcode</a>
            <a href="customershow.php"><i class="fas fa-users"></i> Customers</a>
            <a href="suppliers.php" class="active"><i class="fas fa-truck"></i> Suppliers</a>
             <a href="stock_transactions1.php" ><i class="fas fa-arrows-spin"></i> Stock Transfer</a>
            <a href="report_keep.php"><i class="fas fa-chart-line"></i> Reports</a>
            <a href="?logout=true" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>
</header>

<div class="container">
    <!-- Section Selector Dropdown -->
    <div class="section-selector">
        <label for="sectionSelect">View:</label>
        <select id="sectionSelect">
            <option value="suppliers">Suppliers</option>
            <option value="payments">Supplier Payments</option>
            <option value="productTotals">Supplier Products Total</option>
        </select>
    </div>

    <div class="main">
        <!-- Suppliers Form Section -->
        <div class="card form-section active" id="suppliersFormSection">
            <h2 id="suppliersFormTitle"><i class="fas fa-plus-circle"></i> Add New Supplier</h2>
            <form id="supplierForm">
                <input type="hidden" name="supplier_id" id="supplier_id">
                
                <div class="form-group">
                    <input type="text" name="supplier_name" id="supplier_name" placeholder="Supplier Name" required>
                </div>
                
                <div class="form-group">
                    <input type="text" name="contact_person" id="contact_person" placeholder="Contact Person (Optional)">
                </div>
                
                <div class="form-group">
                    <input type="tel" name="phone_number" id="phone_number" placeholder="Phone Number (Optional)">
                </div>
                
                <div class="form-group">
                    <input type="email" name="email" id="email" placeholder="Email (Optional)">
                </div>
                
                <div class="form-group">
                    <textarea name="address" id="address" placeholder="Address (Optional)" rows="3"></textarea>
                </div>

                <div class="form-buttons">
                    <button type="submit" class="btn btn-primary" id="supplierSubmitBtn">
                        <i class="fas fa-plus"></i> Add Supplier
                    </button>
                    
                    <button type="button" class="btn btn-secondary" id="supplierResetBtn">
                        <i class="fas fa-redo"></i> Reset Form
                    </button>
                </div>
            </form>
        </div>

        <!-- Payments Form Section -->
        <div class="card form-section" id="paymentsFormSection">
            <h2 id="paymentsFormTitle"><i class="fas fa-plus-circle"></i> Add New Payment</h2>
            <form id="paymentForm">
                <input type="hidden" name="payment_id" id="payment_id">
                
                <div class="form-group">
                    <select name="payment_supplier_id" id="payment_supplier_id" required>
                        <option value="">Select Supplier</option>
                        <?php
                        $suppliers = $conn->query("SELECT * FROM suppliers ORDER BY supplier_name");
                        while ($supplier = $suppliers->fetch_assoc()) {
                            echo "<option value=\"{$supplier['supplier_id']}\">{$supplier['supplier_name']}</option>";
                        }
                        ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <input type="number" step="0.01" name="payment_amount" id="payment_amount" placeholder="Payment Amount" required>
                </div>
                
                <div class="form-group">
                    <input type="date" name="payment_date" id="payment_date" placeholder="Select Date" required>
                </div>

                <div class="form-buttons">
                    <button type="submit" class="btn btn-blue" id="paymentSubmitBtn">
                        <i class="fas fa-plus"></i> Add Payment
                    </button>
                    
                    <button type="button" class="btn btn-secondary" id="paymentResetBtn">
                        <i class="fas fa-redo"></i> Reset Form
                    </button>
                </div>
            </form>
        </div>

        <!-- Suppliers Table Section -->
        <div class="card table-section active" id="suppliersTableSection">
            <h2><i class="fas fa-th-list"></i> Suppliers List</h2>
            <div class="table-container">
                <table id="supplierTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Contact Person</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $res = $conn->query("SELECT * FROM suppliers ORDER BY supplier_name");
                        if ($res->num_rows == 0):
                        ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <i class="fas fa-truck"></i>
                                    <h3>No Suppliers Yet</h3>
                                    <p>Add your first supplier to get started!</p>
                                </div>
                            </td>
                        </tr>
                        <?php else: while($row = $res->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?=htmlspecialchars($row['supplier_id'])?></strong></td>
                            <td><?=htmlspecialchars($row['supplier_name'])?></td>
                            <td><?=htmlspecialchars($row['contact_person'])?></td>
                            <td><?=htmlspecialchars($row['phone_number'])?></td>
                            <td><?=htmlspecialchars($row['email'])?></td>
                            <td>
                                <div class="action-buttons">
                                    <button class="action-btn edit-btn" onclick='editSupplier(<?=json_encode($row)?>)'>
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button class="action-btn del-btn" onclick="deleteSupplier('<?=$row['supplier_id']?>')">
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

        <!-- Payments Table Section -->
        <div class="card table-section" id="paymentsTableSection">
            <h2><i class="fas fa-th-list"></i> Payments List</h2>
            
            <!-- Simplified Supplier Filter for Payments -->
            <div class="supplier-filter">
                <select id="supplierFilter">
                    <option value="">All Suppliers</option>
                    <?php
                    $suppliers = $conn->query("SELECT * FROM suppliers ORDER BY supplier_name");
                    while ($supplier = $suppliers->fetch_assoc()) {
                        echo "<option value=\"{$supplier['supplier_id']}\">{$supplier['supplier_name']}</option>";
                    }
                    ?>
                </select>
            </div>
            
            <!-- Payment Summary -->
            <div class="payment-summary" id="paymentSummary">
                <h3>Total Payments: <span class="amount" id="totalAmount">Rs. 0.00</span></h3>
                <div id="selectedSupplierInfo"></div>
            </div>
            
            <div class="table-container">
                <table id="paymentTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Supplier</th>
                            <th>Amount</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $res = $conn->query("
                            SELECT p.*, s.supplier_name 
                            FROM supplier_payments p 
                            LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id 
                            ORDER BY p.payment_date DESC
                        ");
                        if ($res->num_rows == 0):
                        ?>
                        <tr>
                            <td colspan="5">
                                <div class="empty-state">
                                    <i class="fas fa-money-bill-wave"></i>
                                    <h3>No Payments Yet</h3>
                                    <p>Add your first payment to get started!</p>
                                </div>
                            </td>
                        </tr>
                        <?php else: 
                        $totalAmount = 0;
                        while($row = $res->fetch_assoc()): 
                            $totalAmount += $row['amount'];
                        ?>
                        <tr data-supplier-id="<?=$row['supplier_id']?>">
                            <td><strong><?=htmlspecialchars($row['payment_id'])?></strong></td>
                            <td><?=htmlspecialchars($row['supplier_name'])?></td>
                            <td>Rs. <?=number_format($row['amount'],2)?></td>
                            <td><?=date('M j, Y', strtotime($row['payment_date']))?></td>
                            <td>
                                <div class="action-buttons">
                                    <button class="action-btn edit-btn" onclick='editPayment(<?=json_encode($row)?>)'>
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button class="action-btn del-btn" onclick="deletePayment('<?=$row['payment_id']?>')">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; 
                        echo "<script>document.getElementById('totalAmount').textContent = 'Rs. " . number_format($totalAmount, 2) . "';</script>";
                        endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- DESKTOP ONLY: Product Totals Table (shown below Payments table in desktop view) -->
            <div class="desktop-product-totals-section">
                <h2 style="margin-top: 40px; color: var(--primary-orange);"><i class="fas fa-calculator"></i> Supplier Products Total</h2>
                
                <?php
                $productTotalsRes = $conn->query("
                    SELECT 
                        s.supplier_id,
                        s.supplier_name,
                        COUNT(p.product_id) as product_count,
                        SUM(p.stock) as total_stock,
                        SUM(p.original_price * p.stock) as total_original_price,
                        SUM(p.sale_price * p.stock) as total_sale_price
                    FROM suppliers s
                    LEFT JOIN products p ON s.supplier_id = p.supplier_id
                    GROUP BY s.supplier_id, s.supplier_name
                    ORDER BY s.supplier_name
                ");
                
                $totalAllProducts = 0;
                $totalAllStock = 0;
                $totalAllOriginal = 0;
                $totalAllSale = 0;
                
                while ($totalRow = $productTotalsRes->fetch_assoc()) {
                    $totalAllProducts += $totalRow['product_count'];
                    $totalAllStock += $totalRow['total_stock'] ?? 0;
                    $totalAllOriginal += $totalRow['total_original_price'] ?? 0;
                    $totalAllSale += $totalRow['total_sale_price'] ?? 0;
                }
                ?>
                
                <div class="product-totals-summary">
                    <h3>Total Products Value: <span class="amount">Rs. <?=number_format($totalAllOriginal, 2)?></span></h3>
                    <div>
                        <div><strong>Total Products:</strong> <?=$totalAllProducts?></div>
                        <div><strong>Total Stock:</strong> <?=$totalAllStock?></div>
                    </div>
                </div>
                
                <div class="table-container">
                    <table id="productTotalsTableDesktop">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Supplier</th>
                                <th>Products</th>
                                <th>Total Stock</th>
                                <th>Total Original Price</th>
                                <th>Total Sale Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Reset pointer for the results
                            $productTotalsRes->data_seek(0);
                            
                            if ($productTotalsRes->num_rows == 0):
                            ?>
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state">
                                        <i class="fas fa-boxes"></i>
                                        <h3>No Supplier Products Yet</h3>
                                        <p>Add products to suppliers to see totals!</p>
                                    </div>
                                </td>
                            </tr>
                            <?php else: while($row = $productTotalsRes->fetch_assoc()): 
                                $product_count = $row['product_count'] ?? 0;
                                $total_stock = $row['total_stock'] ?? 0;
                                $total_original = $row['total_original_price'] ?? 0;
                                $total_sale = $row['total_sale_price'] ?? 0;
                            ?>
                            <tr>
                                <td><strong><?=htmlspecialchars($row['supplier_id'])?></strong></td>
                                <td><?=htmlspecialchars($row['supplier_name'])?></td>
                                <td><span class="stock-badge"><?=$product_count?></span></td>
                                <td><span class="stock-badge"><?=$total_stock?></span></td>
                                <td><span class="value-badge">Rs. <?=number_format($total_original, 2)?></span></td>
                                <td><span class="value-badge">Rs. <?=number_format($total_sale, 2)?></span></td>
                            </tr>
                            <?php endwhile; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Product Totals Table Section (for dropdown selector - mobile view) -->
        <div class="card table-section product-totals-table-section" id="productTotalsTableSection">
            <h2><i class="fas fa-calculator"></i> Supplier Products Total</h2>
            
            <!-- Product Totals Summary -->
            <?php
            // Re-run the query for mobile view
            $productTotalsResMobile = $conn->query("
                SELECT 
                    s.supplier_id,
                    s.supplier_name,
                    COUNT(p.product_id) as product_count,
                    SUM(p.stock) as total_stock,
                    SUM(p.original_price * p.stock) as total_original_price,
                    SUM(p.sale_price * p.stock) as total_sale_price
                FROM suppliers s
                LEFT JOIN products p ON s.supplier_id = p.supplier_id
                GROUP BY s.supplier_id, s.supplier_name
                ORDER BY s.supplier_name
            ");
            
            $totalAllProductsMobile = 0;
            $totalAllStockMobile = 0;
            $totalAllOriginalMobile = 0;
            $totalAllSaleMobile = 0;
            
            while ($totalRowMobile = $productTotalsResMobile->fetch_assoc()) {
                $totalAllProductsMobile += $totalRowMobile['product_count'];
                $totalAllStockMobile += $totalRowMobile['total_stock'] ?? 0;
                $totalAllOriginalMobile += $totalRowMobile['total_original_price'] ?? 0;
                $totalAllSaleMobile += $totalRowMobile['total_sale_price'] ?? 0;
            }
            ?>
            
            <div class="product-totals-summary" id="productTotalsSummary">
                <h3>Total Products Value: <span class="amount" id="totalProductsValue">Rs. <?=number_format($totalAllOriginalMobile, 2)?></span></h3>
                <div id="productsSummaryInfo">
                    <div><strong>Total Products:</strong> <?=$totalAllProductsMobile?></div>
                    <div><strong>Total Stock:</strong> <?=$totalAllStockMobile?></div>
                </div>
            </div>
            
            <div class="table-container">
                <table id="productTotalsTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Supplier</th>
                            <th>Products</th>
                            <th>Total Stock</th>
                            <th>Total Original Price</th>
                            <th>Total Sale Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Reset pointer for the results
                        $productTotalsResMobile->data_seek(0);
                        
                        if ($productTotalsResMobile->num_rows == 0):
                        ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <i class="fas fa-boxes"></i>
                                    <h3>No Supplier Products Yet</h3>
                                    <p>Add products to suppliers to see totals!</p>
                                </div>
                            </td>
                        </tr>
                        <?php else: while($row = $productTotalsResMobile->fetch_assoc()): 
                            $product_count = $row['product_count'] ?? 0;
                            $total_stock = $row['total_stock'] ?? 0;
                            $total_original = $row['total_original_price'] ?? 0;
                            $total_sale = $row['total_sale_price'] ?? 0;
                        ?>
                        <tr>
                            <td><strong><?=htmlspecialchars($row['supplier_id'])?></strong></td>
                            <td><?=htmlspecialchars($row['supplier_name'])?></td>
                            <td><span class="stock-badge"><?=$product_count?></span></td>
                            <td><span class="stock-badge"><?=$total_stock?></span></td>
                            <td><span class="value-badge">Rs. <?=number_format($total_original, 2)?></span></td>
                            <td><span class="value-badge">Rs. <?=number_format($total_sale, 2)?></span></td>
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
    <i class="fas fa-bars"></i>
</div>

<script>
    
setTimeout(function() {
    window.location.reload();
}, 40000);

// Section Selector Dropdown
document.getElementById('sectionSelect').addEventListener('change', function() {
    const selectedSection = this.value;
    
    // Hide all sections
    document.querySelectorAll('.form-section').forEach(section => {
        section.classList.remove('active');
    });
    document.querySelectorAll('.table-section').forEach(section => {
        section.classList.remove('active');
    });
    
    // Show selected sections
    if (selectedSection === 'suppliers') {
        document.getElementById('suppliersFormSection').classList.add('active');
        document.getElementById('suppliersTableSection').classList.add('active');
    } else if (selectedSection === 'payments') {
        document.getElementById('paymentsFormSection').classList.add('active');
        document.getElementById('paymentsTableSection').classList.add('active');
    } else if (selectedSection === 'productTotals') {
        // In mobile, show the dropdown version
        if (window.innerWidth < 768) {
            document.getElementById('productTotalsTableSection').classList.add('active');
        }
        // In desktop, payments table already shows the product totals below it
    }
    
    // Reset FAB icon
    document.getElementById('fabToggle').querySelector('i').className = 'fas fa-bars';
});

// Reset Forms
function resetSupplierForm() {
    document.getElementById('supplierForm').reset();
    document.getElementById('supplier_id').value = '';
    document.getElementById('supplierSubmitBtn').innerHTML = '<i class="fas fa-plus"></i> Add Supplier';
    document.getElementById('supplierSubmitBtn').className = 'btn btn-primary';
    document.getElementById('suppliersFormTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Add New Supplier';
}

function resetPaymentForm() {
    document.getElementById('paymentForm').reset();
    document.getElementById('payment_id').value = '';
    // Don't set default date - let user select
    document.getElementById('paymentSubmitBtn').innerHTML = '<i class="fas fa-plus"></i> Add Payment';
    document.getElementById('paymentSubmitBtn').className = 'btn btn-blue';
    document.getElementById('paymentsFormTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Add New Payment';
}

// Add event listeners to reset buttons
document.getElementById('supplierResetBtn').addEventListener('click', resetSupplierForm);
document.getElementById('paymentResetBtn').addEventListener('click', resetPaymentForm);

// Form Submit (Add & Update) - Suppliers
document.getElementById('supplierForm').onsubmit = async function(e) {
    e.preventDefault();
    const submitBtn = document.getElementById('supplierSubmitBtn');
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

    // Form Submit (Add & Update) - Payments
    document.getElementById('paymentForm').onsubmit = async function(e) {
        e.preventDefault();
        const submitBtn = document.getElementById('paymentSubmitBtn');
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

    // Edit Supplier
    function editSupplier(s) {
        document.getElementById('supplier_id').value = s.supplier_id;
        document.getElementById('supplier_name').value = s.supplier_name;
        document.getElementById('contact_person').value = s.contact_person || '';
        document.getElementById('phone_number').value = s.phone_number || '';
        document.getElementById('email').value = s.email || '';
        document.getElementById('address').value = s.address || '';

        document.getElementById('supplierSubmitBtn').innerHTML = '<i class="fas fa-sync"></i> Update Supplier';
        document.getElementById('supplierSubmitBtn').className = 'btn btn-secondary';
        document.getElementById('suppliersFormTitle').innerHTML = '<i class="fas fa-edit"></i> Update Supplier - ' + s.supplier_id;

        // Switch to suppliers section
        document.getElementById('sectionSelect').value = 'suppliers';
        document.querySelectorAll('.form-section').forEach(s => s.classList.remove('active'));
        document.getElementById('suppliersFormSection').classList.add('active');
        document.querySelectorAll('.table-section').forEach(t => t.classList.remove('active'));
        document.getElementById('suppliersTableSection').classList.add('active');

        // On mobile, scroll to form
        if (window.innerWidth < 992) {
            document.getElementById('suppliersFormSection').scrollIntoView({ behavior: 'smooth' });
        } else {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    }

    // Edit Payment
    function editPayment(p) {
        document.getElementById('payment_id').value = p.payment_id;
        document.getElementById('payment_supplier_id').value = p.supplier_id;
        document.getElementById('payment_amount').value = p.amount;
        document.getElementById('payment_date').value = p.payment_date;

        document.getElementById('paymentSubmitBtn').innerHTML = '<i class="fas fa-sync"></i> Update Payment';
        document.getElementById('paymentSubmitBtn').className = 'btn btn-secondary';
        document.getElementById('paymentsFormTitle').innerHTML = '<i class="fas fa-edit"></i> Update Payment - ' + p.payment_id;

        // Switch to payments section
        document.getElementById('sectionSelect').value = 'payments';
        document.querySelectorAll('.form-section').forEach(s => s.classList.remove('active'));
        document.getElementById('paymentsFormSection').classList.add('active');
        document.querySelectorAll('.table-section').forEach(t => t.classList.remove('active'));
        document.getElementById('paymentsTableSection').classList.add('active');

        // On mobile, scroll to form
        if (window.innerWidth < 992) {
            document.getElementById('paymentsFormSection').scrollIntoView({ behavior: 'smooth' });
        } else {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    }

    // Delete Supplier
    async function deleteSupplier(id) {
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
                body: 'delete_supplier_id=' + id
            });
            const data = await res.json();
            if (data.success) {
                Swal.fire({
                    title: 'Deleted!',
                    text: 'Supplier has been deleted.',
                    icon: 'success',
                    timer: 1500,
                    showConfirmButton: false,
                    background: 'var(--light-green)',
                    color: 'var(--dark-green)'
                }).then(() => location.reload());
            } else {
                Swal.fire({
                    title: 'Error!',
                    text: data.message,
                    icon: 'error',
                    background: '#ffebee',
                    color: '#c62828'
                });
            }
        }
    }

    // Delete Payment
    async function deletePayment(id) {
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
                body: 'delete_payment_id=' + id
            });
            const data = await res.json();
            if (data.success) {
                Swal.fire({
                    title: 'Deleted!',
                    text: 'Payment has been deleted.',
                    icon: 'success',
                    timer: 1500,
                    showConfirmButton: false,
                    background: 'var(--light-green)',
                    color: 'var(--dark-green)'
                }).then(() => location.reload());
            }
        }
    }

    // Mobile Toggle for Form
    document.getElementById('fabToggle').addEventListener('click', function() {
        const selectedSection = document.getElementById('sectionSelect').value;
        let formSection = null;
        
        if (selectedSection === 'suppliers') {
            formSection = document.getElementById('suppliersFormSection');
        } else if (selectedSection === 'payments') {
            formSection = document.getElementById('paymentsFormSection');
        }
        
        if (formSection) {
            formSection.classList.toggle('active');
            
            // Change icon based on state
            const icon = this.querySelector('i');
            if (formSection.classList.contains('active')) {
                icon.className = 'fas fa-times';
                formSection.scrollIntoView({ behavior: 'smooth' });
            } else {
                icon.className = 'fas fa-bars';
            }
        }
    });

    // Auto-hide form on mobile after submission if it was opened by FAB
    document.getElementById('supplierForm').addEventListener('submit', function() {
        if (window.innerWidth < 992) {
            setTimeout(() => {
                document.getElementById('suppliersFormSection').classList.remove('active');
                document.getElementById('fabToggle').querySelector('i').className = 'fas fa-bars';
            }, 2000);
        }
    });

    document.getElementById('paymentForm').addEventListener('submit', function() {
        if (window.innerWidth < 992) {
            setTimeout(() => {
                document.getElementById('paymentsFormSection').classList.remove('active');
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

    // Supplier Filter for Payments - Auto-filter on change
    document.getElementById('supplierFilter').addEventListener('change', function() {
        const supplierId = this.value;
        const rows = document.querySelectorAll('#paymentTable tbody tr');
        let totalAmount = 0;
        let supplierName = '';
        
        rows.forEach(row => {
            if (row.querySelector('.empty-state')) return;
            
            const rowSupplierId = row.getAttribute('data-supplier-id');
            if (supplierId === '' || rowSupplierId === supplierId) {
                row.style.display = '';
                if (supplierId !== '') {
                    const amountText = row.cells[2].textContent;
                    const amount = parseFloat(amountText.replace('Rs. ', '').replace(/,/g, ''));
                    totalAmount += amount;
                    
                    if (!supplierName) {
                        supplierName = row.cells[1].textContent;
                    }
                }
            } else {
                row.style.display = 'none';
            }
        });
        
        // Update payment summary
        if (supplierId === '') {
            // Recalculate total for all visible rows
            totalAmount = 0;
            rows.forEach(row => {
                if (row.querySelector('.empty-state')) return;
                if (row.style.display !== 'none') {
                    const amountText = row.cells[2].textContent;
                    const amount = parseFloat(amountText.replace('Rs. ', '').replace(/,/g, ''));
                    totalAmount += amount;
                }
            });
            document.getElementById('totalAmount').textContent = 'Rs. ' + totalAmount.toFixed(2);
            document.getElementById('selectedSupplierInfo').innerHTML = '';
        } else {
            document.getElementById('totalAmount').textContent = 'Rs. ' + totalAmount.toFixed(2);
            document.getElementById('selectedSupplierInfo').innerHTML = 
                `<span>Payments for: <strong>${supplierName}</strong></span>`;
        }
    });
</script>

</body>
</html>
<?php $conn->close(); ?>