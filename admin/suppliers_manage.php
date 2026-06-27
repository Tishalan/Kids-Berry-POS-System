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
$conn->set_charset("utf8mb4");

// Get selected branch from session or default to branch1
$selected_branch = isset($_SESSION['selected_branch_supplier']) ? $_SESSION['selected_branch_supplier'] : 'branch1';

// Handle branch selection - through AJAX, not page reload
if (isset($_POST['action']) && $_POST['action'] === 'change_branch') {
    header('Content-Type: application/json');
    $selected_branch = $_POST['selected_branch'];
    $_SESSION['selected_branch_supplier'] = $selected_branch;
    
    // Determine which tables to use based on selected branch
    $supplier_table = ($selected_branch == 'branch1') ? 'suppliers' : 'suppliers2';
    $payment_table = ($selected_branch == 'branch1') ? 'supplier_payments' : 'supplier_payments2';
    $product_table = ($selected_branch == 'branch1') ? 'products' : 'products2';
    
    // Get statistics
    $total_suppliers = $conn->query("SELECT COUNT(*) as count FROM $supplier_table")->fetch_assoc()['count'];
    $total_payments = $conn->query("SELECT COUNT(*) as count FROM $payment_table")->fetch_assoc()['count'];
    $total_payments_amount = $conn->query("SELECT SUM(amount) as total FROM $payment_table")->fetch_assoc()['total'] ?? 0;
    $recent_suppliers = $conn->query("SELECT COUNT(*) as count FROM $supplier_table WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_assoc()['count'];
    
    // Get recent payments
    $recent_payments_html = '';
    $recent_payments = $conn->query("
        SELECT p.*, s.supplier_name 
        FROM $payment_table p 
        LEFT JOIN $supplier_table s ON p.supplier_id = s.supplier_id 
        ORDER BY p.payment_date DESC 
        LIMIT 5
    ");
    
    if ($recent_payments && $recent_payments->num_rows > 0) {
        while($payment = $recent_payments->fetch_assoc()) {
            $recent_payments_html .= '<li class="activity-item">
                <div class="activity-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="activity-details">
                    <div class="activity-description">Payment to ' . htmlspecialchars($payment['supplier_name']) . '</div>
                    <div class="activity-time">Rs' . number_format($payment['amount'], 2) . ' - ' . date('M j, g:i A', strtotime($payment['payment_date'])) . '</div>
                </div>
            </li>';
        }
    } else {
        $recent_payments_html = '<li class="activity-item">
            <div class="activity-details">
                <div class="activity-description">No recent payments</div>
            </div>
        </li>';
    }
    
    // Get top suppliers
    $top_suppliers_html = '';
    $top_suppliers = $conn->query("
        SELECT 
            s.supplier_name,
            COUNT(p.payment_id) as payment_count,
            SUM(p.amount) as total_paid
        FROM $supplier_table s
        LEFT JOIN $payment_table p ON s.supplier_id = p.supplier_id
        GROUP BY s.supplier_id, s.supplier_name
        ORDER BY total_paid DESC
        LIMIT 5
    ");
    
    if ($top_suppliers && $top_suppliers->num_rows > 0) {
        $index = 0;
        while($supplier = $top_suppliers->fetch_assoc()) {
            $index++;
            $top_suppliers_html .= '<li class="top-supplier-item">
                <div class="supplier-rank">' . $index . '</div>
                <div class="supplier-details">
                    <div class="supplier-name">' . htmlspecialchars($supplier['supplier_name']) . '</div>
                    <div class="supplier-payments">
                        Payments: ' . ($supplier['payment_count'] ?? 0) . ' | 
                        Total: Rs' . number_format($supplier['total_paid'] ?? 0, 2) . '
                    </div>
                </div>
            </li>';
        }
    } else {
        $top_suppliers_html = '<li class="top-supplier-item">
            <div class="supplier-details">
                <div class="supplier-name">No payment data available</div>
            </div>
        </li>';
    }
    
    // Get suppliers table HTML
    $suppliers_html = '';
    $suppliers_res = $conn->query("SELECT * FROM $supplier_table ORDER BY supplier_name");
    if ($suppliers_res->num_rows == 0) {
        $suppliers_html = '<tr>
            <td colspan="6">
                <div class="empty-state">
                    <i class="fas fa-truck"></i>
                    <h3>No Suppliers Yet</h3>
                    <p>Add your first supplier to get started!</p>
                </div>
            </td>
        </tr>';
    } else {
        while($row = $suppliers_res->fetch_assoc()) {
            $suppliers_html .= '<tr>
                <td><strong>' . htmlspecialchars($row['supplier_id']) . '</strong></td>
                <td>' . htmlspecialchars($row['supplier_name']) . '</td>
                <td>' . htmlspecialchars($row['contact_person']) . '</td>
                <td>' . htmlspecialchars($row['phone_number']) . '</td>
                <td>' . htmlspecialchars($row['email']) . '</td>
                <td>
                    <div class="action-buttons">
                        <button class="action-btn edit-btn" onclick=\'editSupplier(' . json_encode($row) . ')\'>
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <button class="action-btn del-btn" onclick="deleteSupplier(\'' . $row['supplier_id'] . '\')">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </div>
                </td>
            </tr>';
        }
    }
    
    // Get supplier filter options
    $supplier_options = '<option value="">All Suppliers</option>';
    $suppliers_options_res = $conn->query("SELECT * FROM $supplier_table ORDER BY supplier_name");
    while ($supplier = $suppliers_options_res->fetch_assoc()) {
        $supplier_options .= "<option value=\"{$supplier['supplier_id']}\">{$supplier['supplier_name']}</option>";
    }
    
    // Get payments table HTML
    $payments_html = '';
    $total_payments_sum = 0;
    $payments_res = $conn->query("
        SELECT p.*, s.supplier_name 
        FROM $payment_table p 
        LEFT JOIN $supplier_table s ON p.supplier_id = s.supplier_id 
        ORDER BY p.payment_date DESC
    ");
    
    if ($payments_res->num_rows == 0) {
        $payments_html = '<tr>
            <td colspan="5">
                <div class="empty-state">
                    <i class="fas fa-money-bill-wave"></i>
                    <h3>No Payments Yet</h3>
                    <p>Add your first payment to get started!</p>
                </div>
            </td>
        </tr>';
    } else {
        while($row = $payments_res->fetch_assoc()) {
            $total_payments_sum += $row['amount'];
            $payments_html .= '<tr data-supplier-id="' . htmlspecialchars($row['supplier_id']) . '">
                <td><strong>' . htmlspecialchars($row['payment_id']) . '</strong></td>
                <td>' . htmlspecialchars($row['supplier_name']) . '</td>
                <td>Rs. ' . number_format($row['amount'],2) . '</td>
                <td>' . date('M j, Y', strtotime($row['payment_date'])) . '</td>
                <td>
                    <div class="action-buttons">
                        <button class="action-btn edit-btn" onclick=\'editPayment(' . json_encode($row) . ')\'>
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <button class="action-btn del-btn" onclick="deletePayment(\'' . $row['payment_id'] . '\')">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </div>
                </td>
            </tr>';
        }
    }
    
    // Get product totals
    $total_all_products = 0;
    $total_all_stock = 0;
    $total_all_original = 0;
    $total_all_sale = 0;
    
    $product_totals_html = '';
    $product_totals_res = $conn->query("
        SELECT 
            s.supplier_id,
            s.supplier_name,
            COUNT(p.product_id) as product_count,
            SUM(p.stock) as total_stock,
            SUM(p.original_price * p.stock) as total_original_price,
            SUM(p.sale_price * p.stock) as total_sale_price
        FROM $supplier_table s
        LEFT JOIN $product_table p ON s.supplier_id = p.supplier_id
        GROUP BY s.supplier_id, s.supplier_name
        ORDER BY s.supplier_name
    ");
    
    if ($product_totals_res->num_rows == 0) {
        $product_totals_html = '<tr>
            <td colspan="6">
                <div class="empty-state">
                    <i class="fas fa-boxes"></i>
                    <h3>No Supplier Products Yet</h3>
                    <p>Add products to suppliers to see totals!</p>
                </div>
            </td>
        </tr>';
    } else {
        while($row = $product_totals_res->fetch_assoc()) {
            $product_count = $row['product_count'] ?? 0;
            $total_stock = $row['total_stock'] ?? 0;
            $total_original = $row['total_original_price'] ?? 0;
            $total_sale = $row['total_sale_price'] ?? 0;
            
            $total_all_products += $product_count;
            $total_all_stock += $total_stock;
            $total_all_original += $total_original;
            $total_all_sale += $total_sale;
            
            $product_totals_html .= '<tr>
                <td><strong>' . htmlspecialchars($row['supplier_id']) . '</strong></td>
                <td>' . htmlspecialchars($row['supplier_name']) . '</td>
                <td><span class="stock-badge">' . $product_count . '</span></td>
                <td><span class="stock-badge">' . $total_stock . '</span></td>
                <td><span class="value-badge">Rs. ' . number_format($total_original, 2) . '</span></td>
                <td><span class="value-badge">Rs. ' . number_format($total_sale, 2) . '</span></td>
            </tr>';
        }
    }
    
    echo json_encode([
        'success' => true,
        'total_suppliers' => $total_suppliers,
        'total_payments' => $total_payments,
        'total_payments_amount' => 'Rs' . number_format($total_payments_amount, 2),
        'total_products_value' => 'Rs' . number_format($total_all_original, 2),
        'recent_payments' => $recent_payments_html,
        'top_suppliers' => $top_suppliers_html,
        'suppliers_table' => $suppliers_html,
        'supplier_options' => $supplier_options,
        'payments_table' => $payments_html,
        'total_payments_sum' => number_format($total_payments_sum, 2),
        'product_totals_table' => $product_totals_html,
        'total_all_products' => $total_all_products,
        'total_all_stock' => $total_all_stock,
        'total_all_original' => number_format($total_all_original, 2),
        'branch_name' => ($selected_branch == 'branch1') ? 'Branch 1' : 'Branch 2',
        'supplier_table' => ($selected_branch == 'branch1') ? 'suppliers' : 'suppliers2',
        'payment_table' => ($selected_branch == 'branch1') ? 'supplier_payments' : 'supplier_payments2'
    ]);
    exit;
}

// Determine which tables to use based on selected branch
$supplier_table = ($selected_branch == 'branch1') ? 'suppliers' : 'suppliers2';
$payment_table = ($selected_branch == 'branch1') ? 'supplier_payments' : 'supplier_payments2';
$product_table = ($selected_branch == 'branch1') ? 'products' : 'products2';

// ==================== PHP BACKEND (AJAX) ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    // Delete Supplier
    if (isset($_POST['delete_supplier_id'])) {
        $id = $conn->real_escape_string($_POST['delete_supplier_id']);
        $branch = $_POST['branch'] ?? $selected_branch;
        
        $target_supplier_table = ($branch == 'branch1') ? 'suppliers' : 'suppliers2';
        $target_payment_table = ($branch == 'branch1') ? 'supplier_payments' : 'supplier_payments2';
        $target_product_table = ($branch == 'branch1') ? 'products' : 'products2';
        
        // Check if supplier has payments
        $check_payments = $conn->query("SELECT COUNT(*) as count FROM $target_payment_table WHERE supplier_id = '$id'");
        $payment_count = $check_payments->fetch_assoc()['count'];
        
        // Check if supplier has products
        $check_products = $conn->query("SELECT COUNT(*) as count FROM $target_product_table WHERE supplier_id = '$id'");
        $product_count = $check_products->fetch_assoc()['count'];
        
        if ($payment_count > 0 || $product_count > 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete supplier with existing payments or products!']);
            exit;
        }
        
        $conn->query("DELETE FROM $target_supplier_table WHERE supplier_id = '$id'");
        echo json_encode(['success' => true, 'message' => 'Supplier deleted from ' . ($branch == 'branch1' ? 'Branch 1' : 'Branch 2') . '!']);
        exit;
    }

    // Delete Supplier Payment
    if (isset($_POST['delete_payment_id'])) {
        $id = $conn->real_escape_string($_POST['delete_payment_id']);
        $branch = $_POST['branch'] ?? $selected_branch;
        $target_payment_table = ($branch == 'branch1') ? 'supplier_payments' : 'supplier_payments2';
        
        $conn->query("DELETE FROM $target_payment_table WHERE payment_id = '$id'");
        echo json_encode(['success' => true, 'message' => 'Payment deleted from ' . ($branch == 'branch1' ? 'Branch 1' : 'Branch 2') . '!']);
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
        $branch = $_POST['branch'] ?? $selected_branch;
        
        $target_supplier_table = ($branch == 'branch1') ? 'suppliers' : 'suppliers2';

        if (empty($supplier_id)) {
            // === ADD NEW SUPPLIER ===
            $sql = "INSERT INTO $target_supplier_table 
                    (supplier_name, contact_person, phone_number, email, address) 
                    VALUES 
                    ('$supplier_name', '$contact_person', '$phone_number', '$email', '$address')";

            $msg = "Supplier added to " . ($branch == 'branch1' ? 'Branch 1' : 'Branch 2') . " successfully!";
        } else {
            // === UPDATE EXISTING SUPPLIER ===
            $sql = "UPDATE $target_supplier_table SET 
                    supplier_name='$supplier_name',
                    contact_person='$contact_person',
                    phone_number='$phone_number',
                    email='$email',
                    address='$address'
                    WHERE supplier_id='$supplier_id'";

            $msg = "Supplier updated in " . ($branch == 'branch1' ? 'Branch 1' : 'Branch 2') . " successfully!";
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
        $branch = $_POST['branch'] ?? $selected_branch;
        
        $target_payment_table = ($branch == 'branch1') ? 'supplier_payments' : 'supplier_payments2';

        if (empty($payment_id)) {
            // === ADD NEW PAYMENT ===
            $sql = "INSERT INTO $target_payment_table 
                    (supplier_id, amount, payment_date) 
                    VALUES 
                    ('$supplier_id', $amount, '$payment_date')";

            $msg = "Payment added to " . ($branch == 'branch1' ? 'Branch 1' : 'Branch 2') . " successfully!";
        } else {
            // === UPDATE EXISTING PAYMENT ===
            $sql = "UPDATE $target_payment_table SET 
                    supplier_id='$supplier_id',
                    amount=$amount,
                    payment_date='$payment_date'
                    WHERE payment_id='$payment_id'";

            $msg = "Payment updated in " . ($branch == 'branch1' ? 'Branch 1' : 'Branch 2') . " successfully!";
        }

        if ($conn->query($sql) === TRUE) {
            echo json_encode(['success' => true, 'message' => $msg]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database Error: ' . $conn->error]);
        }
        exit;
    }
}

// Get statistics from selected tables
$total_suppliers = $conn->query("SELECT COUNT(*) as count FROM $supplier_table")->fetch_assoc()['count'];
$total_payments = $conn->query("SELECT COUNT(*) as count FROM $payment_table")->fetch_assoc()['count'];
$total_payments_amount = $conn->query("SELECT SUM(amount) as total FROM $payment_table")->fetch_assoc()['total'] ?? 0;
$recent_suppliers = $conn->query("SELECT COUNT(*) as count FROM $supplier_table WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_assoc()['count'];

// Get recent payments
$recent_payments = $conn->query("
    SELECT p.*, s.supplier_name 
    FROM $payment_table p 
    LEFT JOIN $supplier_table s ON p.supplier_id = s.supplier_id 
    ORDER BY p.payment_date DESC 
    LIMIT 5
");

// Get suppliers with most payments
$top_suppliers = $conn->query("
    SELECT 
        s.supplier_name,
        COUNT(p.payment_id) as payment_count,
        SUM(p.amount) as total_paid
    FROM $supplier_table s
    LEFT JOIN $payment_table p ON s.supplier_id = p.supplier_id
    GROUP BY s.supplier_id, s.supplier_name
    ORDER BY total_paid DESC
    LIMIT 5
");

// Get supplier products total
$productTotalsRes = $conn->query("
    SELECT 
        s.supplier_id,
        s.supplier_name,
        COUNT(p.product_id) as product_count,
        SUM(p.stock) as total_stock,
        SUM(p.original_price * p.stock) as total_original_price,
        SUM(p.sale_price * p.stock) as total_sale_price
    FROM $supplier_table s
    LEFT JOIN $product_table p ON s.supplier_id = p.supplier_id
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

// Reset pointer for later use
$productTotalsRes = $conn->query("
    SELECT 
        s.supplier_id,
        s.supplier_name,
        COUNT(p.product_id) as product_count,
        SUM(p.stock) as total_stock,
        SUM(p.original_price * p.stock) as total_original_price,
        SUM(p.sale_price * p.stock) as total_sale_price
    FROM $supplier_table s
    LEFT JOIN $product_table p ON s.supplier_id = p.supplier_id
    GROUP BY s.supplier_id, s.supplier_name
    ORDER BY s.supplier_name
");

// Get all suppliers for payment filter dropdown
$suppliers = $conn->query("SELECT * FROM $supplier_table ORDER BY supplier_name");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Kids Berry - Suppliers Management</title>
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
            --primary-orange: #e67e22;
            --light-orange: #fdebd0;
            --dark-orange: #d35400;
            --primary-blue: #3498db;
            --light-blue: #d6eaf8;
            --primary-red: #e74c3c;
            --light-red: #fadbd8;
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
            --gradient-orange: linear-gradient(135deg, var(--primary-orange), var(--dark-orange));
            --gradient-blue: linear-gradient(135deg, var(--primary-blue), #2980b9);
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

        /* Section Selector Dropdown */
        .section-selector {
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .section-selector select {
            flex: 1;
            max-width: 300px;
            padding: 12px 16px;
            border-radius: var(--border-radius-small);
            font-size: 1rem;
            transition: var(--transition);
            border: 2px solid var(--medium-gray);
            background: var(--light-gray);
        }
        
        .section-selector select:focus {
            border-color: var(--primary-purple);
            box-shadow: 0 0 0 3px rgba(106, 13, 173, 0.2);
            outline: none;
            background: var(--white);
        }
        
        .section-selector label {
            font-weight: bold;
            color: var(--primary-purple);
            white-space: nowrap;
            font-weight: 800;
            font-size: 1rem;
        }

        /* Hide sections by default */
        .tab-content.product-totals {
            display: none;
        }

        /* Desktop Product Totals Section */
        .desktop-product-totals-section {
            display: none;
        }

        @media (min-width: 768px) {
            .desktop-product-totals-section {
                display: block;
                margin-top: 30px;
            }
        }

        /* For the new tab */
        .tabs {
            display: flex;
            margin-bottom: 20px;
            border-radius: var(--border-radius-small);
            overflow: hidden;
            box-shadow: var(--shadow-light);
        }

        .tab {
            flex: 1;
            padding: 12px;
            text-align: center;
            background: var(--light-gray);
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
            color: var(--dark-gray);
        }

        .tab.active {
            background: var(--gradient-primary);
            color: var(--white);
        }

        @media (max-width: 767px) {
            .desktop-product-totals-section {
                display: none;
            }
            
            .section-selector {
                display: flex !important;
            }
        }

        @media (min-width: 768px) {
            .section-selector {
                display: none !important;
            }
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

        /* Stats Cards - Mobile Grid */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 15px;
            box-shadow: var(--shadow-light);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            animation: fadeInUp 0.8s ease;
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-medium);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--gradient-primary);
        }

        .stat-card:nth-child(2)::before {
            background: var(--gradient-secondary);
        }

        .stat-card:nth-child(3)::before {
            background: linear-gradient(135deg, var(--light-purple), var(--medium-green));
        }

        .stat-card:nth-child(4)::before {
            background: linear-gradient(135deg, var(--primary-purple), var(--dark-green));
        }

        .stat-icon {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            margin-bottom: 10px;
            color: var(--white);
            background: var(--gradient-primary);
            transition: var(--transition);
        }

        .stat-card:hover .stat-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .stat-card:nth-child(2) .stat-icon {
            background: var(--gradient-secondary);
        }

        .stat-card:nth-child(3) .stat-icon {
            background: linear-gradient(135deg, var(--light-purple), var(--medium-green));
        }

        .stat-card:nth-child(4) .stat-icon {
            background: linear-gradient(135deg, var(--primary-purple), var(--dark-green));
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary-purple);
            margin-bottom: 5px;
            transition: var(--transition);
        }

        .stat-card:hover .stat-value {
            transform: scale(1.05);
        }

        .stat-card:nth-child(2) .stat-value {
            color: var(--dark-green);
        }

        .stat-card:nth-child(3) .stat-value {
            color: var(--light-purple);
        }

        .stat-card:nth-child(4) .stat-value {
            color: var(--dark-purple);
        }

        .stat-label {
            color: var(--dark-gray);
            font-weight: 600;
            font-size: 0.85rem;
        }

        /* Dashboard Content - Mobile Stack */
        .dashboard-content {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

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

        .view-all {
            color: var(--primary-purple);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.85rem;
        }

        .view-all:hover {
            color: var(--light-purple);
            transform: translateX(3px);
        }

        /* Tabs */
        .tabs {
            display: flex;
            margin-bottom: 20px;
            border-radius: var(--border-radius-small);
            overflow: hidden;
            box-shadow: var(--shadow-light);
        }

        .tab {
            flex: 1;
            padding: 12px;
            text-align: center;
            background: var(--light-gray);
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
            color: var(--dark-gray);
        }

        .tab.active {
            background: var(--gradient-primary);
            color: var(--white);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Form Styles */
        .form-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 15px;
            box-shadow: var(--shadow-light);
            transition: var(--transition);
        }

        .form-card:hover {
            box-shadow: var(--shadow-medium);
        }

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
            box-shadow: 0 0 0 3px rgba(106, 13, 173, 0.2);
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
            background: linear-gradient(135deg, #3498db, #2980b9);
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
            background: black;
        }

        .del-btn { 
            background: #e74c3c;
        }

        .action-btn:active { 
            transform: translateY(-2px);
            box-shadow: var(--shadow-light);
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

        /* Activity List */
        .activity-list {
            list-style: none;
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid var(--light-gray);
            transition: var(--transition);
        }

        .activity-item:hover {
            background: var(--light-gray);
            padding-left: 8px;
            border-radius: var(--border-radius-small);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            background: var(--gradient-primary);
            transition: var(--transition);
            font-size: 0.9rem;
        }

        .activity-item:hover .activity-icon {
            transform: scale(1.1);
        }

        .activity-details {
            flex: 1;
        }

        .activity-description {
            font-weight: 600;
            margin-bottom: 3px;
            font-size: 0.9rem;
        }

        .activity-time {
            color: var(--dark-gray);
            font-size: 0.8rem;
        }

        /* Top Suppliers List */
        .top-suppliers-list {
            list-style: none;
        }

        .top-supplier-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid var(--light-gray);
            transition: var(--transition);
        }

        .top-supplier-item:hover {
            background: var(--light-gray);
            padding-left: 8px;
            border-radius: var(--border-radius-small);
        }

        .top-supplier-item:last-child {
            border-bottom: none;
        }

        .supplier-rank {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            background: var(--gradient-primary);
            font-weight: bold;
            font-size: 0.8rem;
        }

        .top-supplier-item:nth-child(1) .supplier-rank {
            background: linear-gradient(135deg, #FFD700, #FFA500);
        }

        .top-supplier-item:nth-child(2) .supplier-rank {
            background: linear-gradient(135deg, #C0C0C0, #A9A9A9);
        }

        .top-supplier-item:nth-child(3) .supplier-rank {
            background: linear-gradient(135deg, #CD7F32, #8B4513);
        }

        .supplier-details {
            flex: 1;
        }

        .supplier-name {
            font-weight: 600;
            margin-bottom: 3px;
            font-size: 0.9rem;
        }

        .supplier-payments {
            color: var(--dark-gray);
            font-size: 0.8rem;
        }

        /* Payment Summary */
        .payment-summary {
            background: var(--light-gray);
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
            color: var(--primary-purple);
            margin: 0;
        }

        .payment-summary .amount {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--primary-purple);
        }

        /* Supplier Filter for Payments - Simplified */
        .supplier-filter {
            margin-bottom: 20px;
        }

        .supplier-filter select {
            width: 100%;
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

        @keyframes pulse {
            0% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
            100% {
                transform: scale(1);
            }
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

        .pulse {
            animation: pulse 2s infinite;
        }

        .bounce {
            animation: bounce 2s infinite;
        }

        /* Loading Animation */
        .loading {
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
            animation: bounce 2s infinite;
        }

        .fab:hover {
            transform: scale(1.1);
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
            
            .stats-container {
                grid-template-columns: repeat(4, 1fr);
                gap: 20px;
            }
            
            .dashboard-content {
                display: grid;
                grid-template-columns: 2fr 1fr;
                gap: 20px;
            }
            
            .form-container {
                grid-template-columns: 1fr 1fr;
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
            
            .stat-card {
                padding: 20px;
            }
            
            .stat-value {
                font-size: 1.7rem;
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
            
            .stats-container {
                gap: 25px;
            }
            
            .stat-card {
                padding: 25px;
            }
            
            .stat-icon {
                width: 60px;
                height: 60px;
                font-size: 1.8rem;
            }
            
            .stat-value {
                font-size: 2rem;
            }
            
            .dashboard-content {
                gap: 25px;
            }
            
            .card {
                padding: 25px;
            }
        }

        /* Small Mobile Optimization */
        @media (max-width: 360px) {
            .stats-container {
                grid-template-columns: 1fr;
            }
            
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
                <li><a href="admin_dashboard.php" ><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="cashier_prediction.php" class=""><i class="fas fa-chart-line"></i> Cashier Predictions</a></li>
                <li><a href="customer_manage.php" ><i class="fas fa-users"></i> Customer Management</a></li>
                <li><a href="stockkeeper_manage.php" ><i class="fas fa-user-tie"></i> Stock Keeper Management</a></li>
               <li><a href="cashier_manage.php" ><i class="fas fa-user-tie"></i> Sales Officer Management</a></li>
                <li><a href="sales_manage.php"><i class="fas fa-cash-register"></i> Cashier Management</a></li>
                <li><a href="product_manage.php" ><i class="fas fa-box"></i> Product Management</a></li>
                 <li><a href="suppliers_manage.php" class="active"><i class="fas fa-truck"></i> Suppliers Management</a></li>
                <li><a href="report_show.php"><i class="fas fa-chart-line"></i> Reports</a></li>
                <li><a href="admin_contact_management.php"><i class="fas fa-headset"></i> Contact Requests</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <div class="header">
                <h1><i class="fas fa-truck"></i> Suppliers Management</h1>
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

            <!-- Branch Selection - No Form Submit -->
            <div class="branch-selector">
                <div class="branch-label">
                    <i class="fas fa-store"></i> Select Branch:
                </div>
                <div class="branch-radio-group">
                    <label class="branch-option">
                        <input type="radio" name="selected_branch" value="branch1" <?php echo ($selected_branch == 'branch1') ? 'checked' : ''; ?> onchange="changeBranch(this.value)">
                        Branch 1 <span class="branch-badge" style="background: linear-gradient(135deg, #6a0dad, #4b0082);">suppliers, supplier_payments</span>
                    </label>
                    <label class="branch-option">
                        <input type="radio" name="selected_branch" value="branch2" <?php echo ($selected_branch == 'branch2') ? 'checked' : ''; ?> onchange="changeBranch(this.value)">
                        Branch 2 <span class="branch-badge" style="background: linear-gradient(135deg, #228b22, #32cd32);">suppliers2, supplier_payments2</span>
                    </label>
                </div>
                <div style="margin-left: auto;">
                    <span class="branch-badge" id="currentBranchBadge">
                        <i class="fas fa-database"></i> Currently viewing: <?php echo ($selected_branch == 'branch1') ? 'Branch 1 (suppliers, supplier_payments)' : 'Branch 2 (suppliers2, supplier_payments2)'; ?>
                    </span>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="stats-container">
                <div class="stat-card" onclick="showToast('Total Suppliers: <?php echo $total_suppliers; ?>')">
                    <div class="stat-icon">
                        <i class="fas fa-truck"></i>
                    </div>
                    <div class="stat-value" id="totalSuppliers"><?php echo $total_suppliers; ?></div>
                    <div class="stat-label">Total Suppliers</div>
                </div>
                <div class="stat-card" onclick="showToast('Total Payments: <?php echo $total_payments; ?>')">
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-value" id="totalPayments"><?php echo $total_payments; ?></div>
                    <div class="stat-label">Total Payments</div>
                </div>
                <div class="stat-card" onclick="showToast('Total Payments Amount: Rs<?php echo number_format($total_payments_amount, 2); ?>')">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-value" id="totalPaymentsAmount">Rs<?php echo number_format($total_payments_amount, 2); ?></div>
                    <div class="stat-label">Total Payments Amount</div>
                </div>
                <div class="stat-card" onclick="showToast('Total Products Value: Rs<?php echo number_format($totalAllOriginal, 2); ?>')">
                    <div class="stat-icon">
                        <i class="fas fa-boxes"></i>
                    </div>
                    <div class="stat-value" id="totalProductsValue">Rs<?php echo number_format($totalAllOriginal, 2); ?></div>
                    <div class="stat-label">Products Value</div>
                </div>
            </div>

            <!-- Mobile Section Selector -->
            <div class="section-selector" id="mobileSectionSelector">
                <label for="sectionSelect">View:</label>
                <select id="sectionSelect">
                    <option value="suppliers">Suppliers</option>
                    <option value="payments">Supplier Payments</option>
                    <option value="productTotals">Supplier Products Total</option>
                </select>
            </div>

            <!-- Tabs (Desktop) -->
            <div class="tabs" id="desktopTabs">
                <div class="tab active" data-tab="suppliers">Suppliers</div>
                <div class="tab" data-tab="payments">Supplier Payments</div>
                <div class="tab" data-tab="productTotals">Supplier Products Total</div>
            </div>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Suppliers Form Section -->
                    <div class="card tab-content active" id="suppliersFormSection">
                        <div class="card-header">
                            <h2 class="card-title" id="suppliersFormTitle"><i class="fas fa-plus-circle"></i> Add New Supplier</h2>
                        </div>
                        <form id="supplierForm">
                            <input type="hidden" name="supplier_id" id="supplier_id">
                            <input type="hidden" name="branch" id="supplier_branch" value="<?php echo $selected_branch; ?>">
                            
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

                            <div class="form-group">
                                <label for="supplier_branch_select">Save to Branch</label>
                                <select id="supplier_branch_select" name="branch" class="form-control">
                                    <option value="branch1" <?php echo ($selected_branch == 'branch1') ? 'selected' : ''; ?>>Branch 1 (suppliers table)</option>
                                    <option value="branch2" <?php echo ($selected_branch == 'branch2') ? 'selected' : ''; ?>>Branch 2 (suppliers2 table)</option>
                                </select>
                            </div>

                            <button type="submit" class="btn btn-primary" id="supplierSubmitBtn">
                                <i class="fas fa-plus"></i> Add Supplier
                            </button>
                            
                            <button type="button" class="btn btn-secondary" id="supplierResetBtn" style="margin-top: 10px;">
                                <i class="fas fa-redo"></i> Reset Form
                            </button>
                        </form>
                    </div>

                    <!-- Payments Form Section -->
                    <div class="card tab-content" id="paymentsFormSection">
                        <div class="card-header">
                            <h2 class="card-title" id="paymentsFormTitle"><i class="fas fa-plus-circle"></i> Add New Payment</h2>
                        </div>
                        <form id="paymentForm">
                            <input type="hidden" name="payment_id" id="payment_id">
                            <input type="hidden" name="branch" id="payment_branch" value="<?php echo $selected_branch; ?>">
                            
                            <div class="form-group">
                                <select name="payment_supplier_id" id="payment_supplier_id" required>
                                    <option value="">Select Supplier</option>
                                    <?php
                                    $conn = new mysqli($servername, $username, $password, $dbname);
                                    if (!$conn->connect_error) {
                                        $suppliers = $conn->query("SELECT * FROM $supplier_table ORDER BY supplier_name");
                                        while ($supplier = $suppliers->fetch_assoc()) {
                                            echo "<option value=\"{$supplier['supplier_id']}\">{$supplier['supplier_name']}</option>";
                                        }
                                        $conn->close();
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

                            <div class="form-group">
                                <label for="payment_branch_select">Save to Branch</label>
                                <select id="payment_branch_select" name="branch" class="form-control">
                                    <option value="branch1" <?php echo ($selected_branch == 'branch1') ? 'selected' : ''; ?>>Branch 1 (supplier_payments table)</option>
                                    <option value="branch2" <?php echo ($selected_branch == 'branch2') ? 'selected' : ''; ?>>Branch 2 (supplier_payments2 table)</option>
                                </select>
                            </div>

                            <button type="submit" class="btn btn-blue" id="paymentSubmitBtn">
                                <i class="fas fa-plus"></i> Add Payment
                            </button>
                            
                            <button type="button" class="btn btn-secondary" id="paymentResetBtn" style="margin-top: 10px;">
                                <i class="fas fa-redo"></i> Reset Form
                            </button>
                        </form>
                    </div>

                    <!-- Suppliers Table Section -->
                    <div class="card tab-content active" id="suppliersTableSection">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-th-list"></i> Suppliers List</h2>
                        </div>
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
                                <tbody id="supplierTableBody">
                                    <?php
                                    $conn = new mysqli($servername, $username, $password, $dbname);
                                    if (!$conn->connect_error) {
                                        $res = $conn->query("SELECT * FROM $supplier_table ORDER BY supplier_name");
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
                                    <?php endwhile; endif; 
                                        $conn->close();
                                    } else {
                                        echo "<tr><td colspan='6'>Database connection error</td></tr>";
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Payments Table Section -->
                    <div class="card tab-content" id="paymentsTableSection">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-th-list"></i> Payments List</h2>
                        </div>
                        
                        <!-- Simplified Supplier Filter for Payments -->
                        <div class="supplier-filter">
                            <select id="supplierFilter">
                                <option value="">All Suppliers</option>
                                <?php
                                $conn = new mysqli($servername, $username, $password, $dbname);
                                if (!$conn->connect_error) {
                                    $suppliers = $conn->query("SELECT * FROM $supplier_table ORDER BY supplier_name");
                                    while ($supplier = $suppliers->fetch_assoc()) {
                                        echo "<option value=\"{$supplier['supplier_id']}\">{$supplier['supplier_name']}</option>";
                                    }
                                    $conn->close();
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
                                <tbody id="paymentTableBody">
                                    <?php
                                    $conn = new mysqli($servername, $username, $password, $dbname);
                                    if (!$conn->connect_error) {
                                        $res = $conn->query("
                                            SELECT p.*, s.supplier_name 
                                            FROM $payment_table p 
                                            LEFT JOIN $supplier_table s ON p.supplier_id = s.supplier_id 
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
                                    endif; 
                                    $conn->close();
                                } else {
                                    echo "<tr><td colspan='5'>Database connection error</td></tr>";
                                }
                                ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- DESKTOP ONLY: Product Totals Table (shown below Payments table in desktop view) -->
                        <div class="desktop-product-totals-section">
                            <h2 style="margin-top: 40px; color: var(--primary-orange);"><i class="fas fa-calculator"></i> Supplier Products Total</h2>
                            
                            <div class="product-totals-summary">
                                <h3>Total Products Value: <span class="amount" id="desktopTotalProductsValue">Rs. <?=number_format($totalAllOriginal, 2)?></span></h3>
                                <div>
                                    <div><strong>Total Products:</strong> <span id="desktopTotalProducts"><?=$totalAllProducts?></span></div>
                                    <div><strong>Total Stock:</strong> <span id="desktopTotalStock"><?=$totalAllStock?></span></div>
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
                                    <tbody id="productTotalsTableDesktopBody">
                                        <?php
                                        $conn = new mysqli($servername, $username, $password, $dbname);
                                        if (!$conn->connect_error) {
                                            $productTotalsRes = $conn->query("
                                                SELECT 
                                                    s.supplier_id,
                                                    s.supplier_name,
                                                    COUNT(p.product_id) as product_count,
                                                    SUM(p.stock) as total_stock,
                                                    SUM(p.original_price * p.stock) as total_original_price,
                                                    SUM(p.sale_price * p.stock) as total_sale_price
                                                FROM $supplier_table s
                                                LEFT JOIN $product_table p ON s.supplier_id = p.supplier_id
                                                GROUP BY s.supplier_id, s.supplier_name
                                                ORDER BY s.supplier_name
                                            ");
                                            
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
                                        <?php endwhile; endif; 
                                            $conn->close();
                                        } else {
                                            echo "<tr><td colspan='6'>Database connection error</td></tr>";
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Product Totals Table Section (for mobile view and tab view) -->
                    <div class="card tab-content product-totals" id="productTotalsTableSection">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-calculator"></i> Supplier Products Total</h2>
                        </div>
                        
                        <div class="product-totals-summary" id="productTotalsSummary">
                            <h3>Total Products Value: <span class="amount" id="mobileTotalProductsValue">Rs. <?=number_format($totalAllOriginal, 2)?></span></h3>
                            <div id="productsSummaryInfo">
                                <div><strong>Total Products:</strong> <span id="mobileTotalProducts"><?=$totalAllProducts?></span></div>
                                <div><strong>Total Stock:</strong> <span id="mobileTotalStock"><?=$totalAllStock?></span></div>
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
                                <tbody id="productTotalsTableBody">
                                    <?php
                                    $conn = new mysqli($servername, $username, $password, $dbname);
                                    if (!$conn->connect_error) {
                                        $productTotalsRes = $conn->query("
                                            SELECT 
                                                s.supplier_id,
                                                s.supplier_name,
                                                COUNT(p.product_id) as product_count,
                                                SUM(p.stock) as total_stock,
                                                SUM(p.original_price * p.stock) as total_original_price,
                                                SUM(p.sale_price * p.stock) as total_sale_price
                                            FROM $supplier_table s
                                            LEFT JOIN $product_table p ON s.supplier_id = p.supplier_id
                                            GROUP BY s.supplier_id, s.supplier_name
                                            ORDER BY s.supplier_name
                                        ");
                                        
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
                                    <?php endwhile; endif; 
                                        $conn->close();
                                    } else {
                                        echo "<tr><td colspan='6'>Database connection error</td></tr>";
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    <!-- Recent Payments -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-history"></i> Recent Payments</h2>
                            <a href="#" class="view-all" onclick="switchToPaymentsTab()">View All <i class="fas fa-arrow-right"></i></a>
                        </div>
                        <ul class="activity-list" id="recentPaymentsList">
                            <?php if ($recent_payments && $recent_payments->num_rows > 0): ?>
                                <?php while($payment = $recent_payments->fetch_assoc()): ?>
                                    <li class="activity-item">
                                        <div class="activity-icon">
                                            <i class="fas fa-money-bill-wave"></i>
                                        </div>
                                        <div class="activity-details">
                                            <div class="activity-description">Payment to <?php echo htmlspecialchars($payment['supplier_name']); ?></div>
                                            <div class="activity-time">Rs<?php echo number_format($payment['amount'], 2); ?> - <?php echo date('M j, g:i A', strtotime($payment['payment_date'])); ?></div>
                                        </div>
                                    </li>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <li class="activity-item">
                                    <div class="activity-details">
                                        <div class="activity-description">No recent payments</div>
                                    </div>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <!-- Top Suppliers -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-trophy"></i> Top Suppliers</h2>
                        </div>
                        <ul class="top-suppliers-list" id="topSuppliersList">
                            <?php if (!empty($top_suppliers) && $top_suppliers->num_rows > 0): ?>
                                <?php 
                                $index = 0;
                                while($supplier = $top_suppliers->fetch_assoc()): 
                                    $index++;
                                ?>
                                    <li class="top-supplier-item">
                                        <div class="supplier-rank"><?php echo $index; ?></div>
                                        <div class="supplier-details">
                                            <div class="supplier-name"><?php echo htmlspecialchars($supplier['supplier_name']); ?></div>
                                            <div class="supplier-payments">
                                                Payments: <?php echo $supplier['payment_count']; ?> | 
                                                Total: Rs<?php echo number_format($supplier['total_paid'] ?? 0, 2); ?>
                                            </div>
                                        </div>
                                    </li>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <li class="top-supplier-item">
                                    <div class="supplier-details">
                                        <div class="supplier-name">No payment data available</div>
                                    </div>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <!-- Quick Actions -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-bolt"></i> Quick Actions</h2>
                        </div>
                        <div class="quick-actions">
                            <button class="btn btn-primary" onclick="resetSupplierForm()">
                                <i class="fas fa-plus"></i> Add New Supplier
                            </button>
                            <button class="btn btn-blue" onclick="resetPaymentForm()">
                                <i class="fas fa-money-bill-wave"></i> Add New Payment
                            </button>
                        </div>
                    </div>
                </div>
            </div>
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

        // Function to change branch via AJAX (no page reload)
        async function changeBranch(branch) {
            try {
                showToast('Switching branch...');
                
                const formData = new FormData();
                formData.append('action', 'change_branch');
                formData.append('selected_branch', branch);

                const res = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await res.json();
                
                if (data.success) {
                    // Update stats
                    document.getElementById('totalSuppliers').textContent = data.total_suppliers;
                    document.getElementById('totalPayments').textContent = data.total_payments;
                    document.getElementById('totalPaymentsAmount').textContent = data.total_payments_amount;
                    document.getElementById('totalProductsValue').textContent = data.total_products_value;
                    
                    // Update recent payments
                    document.getElementById('recentPaymentsList').innerHTML = data.recent_payments;
                    
                    // Update top suppliers
                    document.getElementById('topSuppliersList').innerHTML = data.top_suppliers;
                    
                    // Update suppliers table
                    document.getElementById('supplierTableBody').innerHTML = data.suppliers_table;
                    
                    // Update supplier filter options
                    document.getElementById('supplierFilter').innerHTML = data.supplier_options;
                    
                    // Update payments table
                    document.getElementById('paymentTableBody').innerHTML = data.payments_table;
                    document.getElementById('totalAmount').textContent = 'Rs. ' + data.total_payments_sum;
                    
                    // Update product totals table (mobile)
                    document.getElementById('productTotalsTableBody').innerHTML = data.product_totals_table;
                    
                    // Update product totals table (desktop)
                    document.getElementById('productTotalsTableDesktopBody').innerHTML = data.product_totals_table;
                    
                    // Update product totals summary
                    document.getElementById('mobileTotalProductsValue').textContent = 'Rs. ' + data.total_all_original;
                    document.getElementById('mobileTotalProducts').textContent = data.total_all_products;
                    document.getElementById('mobileTotalStock').textContent = data.total_all_stock;
                    
                    document.getElementById('desktopTotalProductsValue').textContent = 'Rs. ' + data.total_all_original;
                    document.getElementById('desktopTotalProducts').textContent = data.total_all_products;
                    document.getElementById('desktopTotalStock').textContent = data.total_all_stock;
                    
                    // Update branch badge
                    document.getElementById('currentBranchBadge').innerHTML = '<i class="fas fa-database"></i> Currently viewing: ' + data.branch_name + ' (' + data.supplier_table + ', ' + data.payment_table + ')';
                    
                    // Update form branch hidden inputs and selects
                    document.getElementById('supplier_branch').value = branch;
                    document.getElementById('supplier_branch_select').value = branch;
                    document.getElementById('payment_branch').value = branch;
                    document.getElementById('payment_branch_select').value = branch;
                    
                    // Reset any editing state
                    resetSupplierForm();
                    resetPaymentForm();
                    
                    showToast('Switched to ' + data.branch_name + ' successfully');
                }
            } catch (error) {
                console.error('Branch change error:', error);
                showToast('Error switching branch', 'error');
            }
        }

        // Section selector for mobile
        const sectionSelect = document.getElementById('sectionSelect');
        const mobileSectionSelector = document.getElementById('mobileSectionSelector');
        const desktopTabs = document.getElementById('desktopTabs');
        
        // Show/hide mobile/desktop navigation based on screen size
        function updateNavigationView() {
            if (window.innerWidth < 768) {
                mobileSectionSelector.style.display = 'flex';
                desktopTabs.style.display = 'none';
            } else {
                mobileSectionSelector.style.display = 'none';
                desktopTabs.style.display = 'flex';
            }
        }
        
        // Initial call
        updateNavigationView();
        
        // Update on resize
        window.addEventListener('resize', updateNavigationView);

        // Tab Switching for Desktop
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function() {
                // Update active tab
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                
                // Show corresponding content
                const tabName = this.getAttribute('data-tab');
                showTabContent(tabName);
            });
        });

        // Mobile section selector change
        sectionSelect.addEventListener('change', function() {
            const tabName = this.value;
            
            // Update desktop tabs if visible
            document.querySelectorAll('.tab').forEach(t => {
                t.classList.remove('active');
                if (t.getAttribute('data-tab') === tabName) {
                    t.classList.add('active');
                }
            });
            
            showTabContent(tabName);
        });

        function showTabContent(tabName) {
            // Hide all tab content
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Show selected tab content
            if (tabName === 'suppliers') {
                document.getElementById('suppliersTableSection').classList.add('active');
                document.getElementById('suppliersFormSection').classList.add('active');
            } else if (tabName === 'payments') {
                document.getElementById('paymentsTableSection').classList.add('active');
                document.getElementById('paymentsFormSection').classList.add('active');
            } else if (tabName === 'productTotals') {
                document.getElementById('productTotalsTableSection').classList.add('active');
            }
        }

        // Reset Forms
        function resetSupplierForm() {
            document.getElementById('supplierForm').reset();
            document.getElementById('supplier_id').value = '';
            document.getElementById('supplierSubmitBtn').innerHTML = '<i class="fas fa-plus"></i> Add Supplier';
            document.getElementById('supplierSubmitBtn').className = 'btn btn-primary';
            document.getElementById('suppliersFormTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Add New Supplier';
            
            // Switch to suppliers tab
            if (window.innerWidth < 768) {
                sectionSelect.value = 'suppliers';
                showTabContent('suppliers');
            } else {
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelector('.tab[data-tab="suppliers"]').classList.add('active');
                showTabContent('suppliers');
            }
        }

        function resetPaymentForm() {
            document.getElementById('paymentForm').reset();
            document.getElementById('payment_id').value = '';
            document.getElementById('paymentSubmitBtn').innerHTML = '<i class="fas fa-plus"></i> Add Payment';
            document.getElementById('paymentSubmitBtn').className = 'btn btn-blue';
            document.getElementById('paymentsFormTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Add New Payment';
            
            // Switch to payments tab
            if (window.innerWidth < 768) {
                sectionSelect.value = 'payments';
                showTabContent('payments');
            } else {
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelector('.tab[data-tab="payments"]').classList.add('active');
                showTabContent('payments');
            }
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
            submitBtn.innerHTML = '<span class="loading"></span> Processing...';
            submitBtn.disabled = true;
            
            const formData = new FormData(this);

            try {
                const res = await fetch('', { method: 'POST', body: formData });
                const data = await res.json();

                if (data.success) {
                    showToast(data.message);
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(data.message);
                }
            } catch(err) {
                showToast('Network or server error!');
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
            submitBtn.innerHTML = '<span class="loading"></span> Processing...';
            submitBtn.disabled = true;
            
            const formData = new FormData(this);

            try {
                const res = await fetch('', { method: 'POST', body: formData });
                const data = await res.json();

                if (data.success) {
                    showToast(data.message);
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(data.message);
                }
            } catch(err) {
                showToast('Network or server error!');
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
            document.getElementById('supplier_branch').value = '<?php echo $selected_branch; ?>';
            document.getElementById('supplier_branch_select').value = '<?php echo $selected_branch; ?>';

            document.getElementById('supplierSubmitBtn').innerHTML = '<i class="fas fa-sync"></i> Update Supplier';
            document.getElementById('supplierSubmitBtn').className = 'btn btn-secondary';
            document.getElementById('suppliersFormTitle').innerHTML = '<i class="fas fa-edit"></i> Update Supplier - ' + s.supplier_id;

            // Switch to suppliers tab
            if (window.innerWidth < 768) {
                sectionSelect.value = 'suppliers';
                showTabContent('suppliers');
            } else {
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelector('.tab[data-tab="suppliers"]').classList.add('active');
                showTabContent('suppliers');
            }
        }

        // Edit Payment
        function editPayment(p) {
            document.getElementById('payment_id').value = p.payment_id;
            document.getElementById('payment_supplier_id').value = p.supplier_id;
            document.getElementById('payment_amount').value = p.amount;
            document.getElementById('payment_date').value = p.payment_date;
            document.getElementById('payment_branch').value = '<?php echo $selected_branch; ?>';
            document.getElementById('payment_branch_select').value = '<?php echo $selected_branch; ?>';

            document.getElementById('paymentSubmitBtn').innerHTML = '<i class="fas fa-sync"></i> Update Payment';
            document.getElementById('paymentSubmitBtn').className = 'btn btn-secondary';
            document.getElementById('paymentsFormTitle').innerHTML = '<i class="fas fa-edit"></i> Update Payment - ' + p.payment_id;

            // Switch to payments tab
            if (window.innerWidth < 768) {
                sectionSelect.value = 'payments';
                showTabContent('payments');
            } else {
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelector('.tab[data-tab="payments"]').classList.add('active');
                showTabContent('payments');
            }
        }

        // Delete Supplier
        async function deleteSupplier(id) {
            const branch = document.getElementById('supplier_branch').value;
            
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
                const formData = new FormData();
                formData.append('delete_supplier_id', id);
                formData.append('branch', branch);

                const res = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Supplier has been deleted.');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(data.message);
                }
            }
        }

        // Delete Payment
        async function deletePayment(id) {
            const branch = document.getElementById('payment_branch').value;
            
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
                const formData = new FormData();
                formData.append('delete_payment_id', id);
                formData.append('branch', branch);

                const res = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Payment has been deleted.');
                    setTimeout(() => location.reload(), 1500);
                }
            }
        }

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

        // Switch to payments tab from recent payments view all
        function switchToPaymentsTab() {
            if (window.innerWidth < 768) {
                sectionSelect.value = 'payments';
                showTabContent('payments');
            } else {
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelector('.tab[data-tab="payments"]').classList.add('active');
                showTabContent('payments');
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

            const animatedElements = document.querySelectorAll('.stat-card, .card');
            animatedElements.forEach(el => {
                el.style.opacity = 0;
                el.style.transform = 'translateY(15px)';
                el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                observer.observe(el);
            });

            // Animate stats with counting effect
            const statValues = document.querySelectorAll('.stat-value');
            statValues.forEach(stat => {
                const originalText = stat.textContent;
                if (originalText.includes('Rs')) {
                    const number = parseFloat(originalText.replace('Rs', '').replace(/,/g, ''));
                    if (!isNaN(number)) {
                        animateValue(stat, 0, number, 1500, true);
                    }
                } else {
                    const number = parseInt(originalText);
                    if (!isNaN(number)) {
                        animateValue(stat, 0, number, 1500, false);
                    }
                }
            });
        });

        // Function to animate counting numbers
        function animateValue(element, start, end, duration, isCurrency) {
            let startTimestamp = null;
            const step = (timestamp) => {
                if (!startTimestamp) startTimestamp = timestamp;
                const progress = Math.min((timestamp - startTimestamp) / duration, 1);
                const value = Math.floor(progress * (end - start) + start);
                element.textContent = isCurrency ? 'Rs' + value.toLocaleString() : value.toLocaleString();
                if (progress < 1) {
                    window.requestAnimationFrame(step);
                }
            };
            window.requestAnimationFrame(step);
        }

        // Add ripple effect to stat cards
        document.querySelectorAll('.stat-card').forEach(card => {
            card.addEventListener('click', function(e) {
                const ripple = document.createElement('span');
                const rect = card.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left - size / 2;
                const y = e.clientY - rect.top - size / 2;
                
                ripple.style.width = ripple.style.height = size + 'px';
                ripple.style.left = x + 'px';
                ripple.style.top = y + 'px';
                ripple.classList.add('ripple');
                
                card.appendChild(ripple);
                
                setTimeout(() => {
                    ripple.remove();
                }, 600);
            });
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