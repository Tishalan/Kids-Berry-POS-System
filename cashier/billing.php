<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

if (!isset($_SESSION['cashiers_id'])) {
    header("Location: ../index.php");
    exit();
}

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "kidsberry";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$branch_id = 1;
$_SESSION['branch_id'] = $branch_id;

function generateBillNo($conn, $branch_id) {
    $prefix = "PS-B" . $branch_id . "-";
    $sql = "SELECT MAX(bill_no) as max_bill FROM bills WHERE bill_no LIKE '$prefix%'";
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    $max_bill = $row['max_bill'];
    if ($max_bill && preg_match('/^PS-B' . $branch_id . '-(\d+)$/', $max_bill, $matches)) {
        $numeric_part = (int)$matches[1] + 1;
        return $prefix . str_pad($numeric_part, 4, "0", STR_PAD_LEFT);
    }
    return $prefix . "0001";
}

$bill_no = generateBillNo($conn, $branch_id);

$cashiers_sql = "SELECT cashier_id, name FROM cashier_users WHERE status = 'active' ORDER BY name";
$cashiers_result = $conn->query($cashiers_sql);

$customers_sql = "SELECT customer_id, name, phone_no, nic_no FROM customers ORDER BY name";
$customers_result = $conn->query($customers_sql);

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'search_customers') {
    $search = isset($_POST['search']) ? mysqli_real_escape_string($conn, $_POST['search']) : '';
    $customers_sql_ajax = "SELECT customer_id, name, phone_no, nic_no FROM customers WHERE 1=1";
    if ($search) {
        $customers_sql_ajax .= " AND (name LIKE '%$search%' OR phone_no LIKE '%$search%' OR nic_no LIKE '%$search%')";
    }
    $customers_sql_ajax .= " LIMIT 10";
    $customers_result_ajax = $conn->query($customers_sql_ajax);
    
    $html = '';
    while ($customer = $customers_result_ajax->fetch_assoc()) {
        $html .= '<div class="customer-option" onclick="selectCustomer(' . $customer['customer_id'] . ', \'' . addslashes($customer['name']) . '\', \'' . addslashes($customer['phone_no']) . '\', \'' . addslashes($customer['nic_no'] ?? '') . '\')">';
        $html .= '<strong>' . htmlspecialchars($customer['name']) . '</strong>';
        $html .= '<br><small>Phone: ' . htmlspecialchars($customer['phone_no']) . ' | NIC: ' . htmlspecialchars($customer['nic_no'] ?? 'N/A') . '</small>';
        $html .= '</div>';
    }
    
    header('Content-Type: application/json');
    echo json_encode(['html' => $html]);
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'search_products') {
    $search = isset($_POST['search']) ? mysqli_real_escape_string($conn, $_POST['search']) : '';
    $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
    $limit = 12;
    $offset = ($page - 1) * $limit;
    
    $products_sql = "SELECT * FROM products WHERE stock > 0";
    $count_sql = "SELECT COUNT(*) as total FROM products WHERE stock > 0";
    
    if ($search) {
        $products_sql .= " AND (name LIKE '%$search%' OR barcode LIKE '%$search%')";
        $count_sql .= " AND (name LIKE '%$search%' OR barcode LIKE '%$search%')";
    }
    
    $products_sql .= " LIMIT $limit OFFSET $offset";
    $products_result = $conn->query($products_sql);
    $count_result = $conn->query($count_sql);
    $total_products = $count_result->fetch_assoc()['total'];
    $total_pages = ceil($total_products / $limit);

    $html = '';
    while ($product = $products_result->fetch_assoc()) {
        $html .= '<div class="product-item" onclick="showIMEIPrompt(\'' . htmlspecialchars($product['product_id']) . '\', 1, 0, \'rs\')">';
        if ($product['photo']) {
            $html .= '<img src="' . htmlspecialchars($product['photo']) . '" alt="' . htmlspecialchars($product['name']) . '">';
        }
        $html .= '<div class="product-name">' . htmlspecialchars($product['name']) . '</div>';
        $html .= '<div class="product-price">Rs' . number_format($product['sale_price'], 2) . '</div>';
        $html .= '<div class="product-stock">Stock: ' . $product['stock'] . '</div>';
        $html .= '<div class="product-stock">Barcode: ' . $product['barcode'] . '</div>';
        $html .= '</div>';
    }
    
    if ($total_pages > 1) {
        $html .= '<div class="pagination" id="product-pagination">';
        if ($page > 1) {
            $html .= '<button class="page-btn" onclick="loadProductPage(' . ($page - 1) . ')">&laquo; Previous</button>';
        }
        $html .= '<span class="page-info">Page ' . $page . ' of ' . $total_pages . '</span>';
        if ($page < $total_pages) {
            $html .= '<button class="page-btn" onclick="loadProductPage(' . ($page + 1) . ')">Next &raquo;</button>';
        }
        $html .= '</div>';
    }

    header('Content-Type: application/json');
    echo json_encode([
        'html' => $html,
        'total_pages' => $total_pages,
        'current_page' => $page
    ]);
    exit();
}

// Main cart session key
$cart_session_key = 'cart_branch_' . $branch_id;

// Held bills session key
$held_bills_key = 'held_bills_branch_' . $branch_id;

// Initialize held bills if not exists
if (!isset($_SESSION[$held_bills_key])) {
    $_SESSION[$held_bills_key] = [];
}

// Initialize main cart if not exists
if (!isset($_SESSION[$cart_session_key])) {
    $_SESSION[$cart_session_key] = [];
}

function roundAmount($amount, $round_type = 'none') {
    switch($round_type) {
        case 'normal':
            return round($amount);
        case 'up':
            return ceil($amount);
        case 'down':
            return floor($amount);
        case 'nearest_10':
            return round($amount / 10) * 10;
        case 'nearest_50':
            return round($amount / 50) * 50;
        case 'nearest_100':
            return round($amount / 100) * 100;
        default:
            return $amount;
    }
}

// Handle Hold Bill action
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['hold_bill'])) {
    $hold_note = isset($_POST['hold_note']) ? mysqli_real_escape_string($conn, $_POST['hold_note']) : '';
    $cashier_id = isset($_POST['cashier_id']) ? mysqli_real_escape_string($conn, $_POST['cashier_id']) : '';
    $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : null;
    $customer_name = isset($_POST['customer_name']) ? mysqli_real_escape_string($conn, $_POST['customer_name']) : '';
    $phone_no = isset($_POST['phone_no']) ? mysqli_real_escape_string($conn, $_POST['phone_no']) : '';
    $nic_no = isset($_POST['nic_no']) ? mysqli_real_escape_string($conn, $_POST['nic_no']) : '';
    
    if (!empty($_SESSION[$cart_session_key])) {
        $held_bill = [
            'id' => uniqid('hold_', true),
            'date' => date('Y-m-d H:i:s'),
            'bill_no' => $bill_no,
            'cart' => $_SESSION[$cart_session_key],
            'totals' => calculateTotals($_SESSION[$cart_session_key]),
            'cashier_id' => $cashier_id,
            'customer_id' => $customer_id,
            'customer_name' => $customer_name,
            'phone_no' => $phone_no,
            'nic_no' => $nic_no,
            'hold_note' => $hold_note
        ];
        
        $_SESSION[$held_bills_key][] = $held_bill;
        
        // Clear current cart
        $_SESSION[$cart_session_key] = [];
        
        // Generate new bill number for next bill
        $bill_no = generateBillNo($conn, $branch_id);
        
        echo json_encode(['success' => true, 'message' => 'Bill held successfully!']);
        exit();
    } else {
        echo json_encode(['success' => false, 'message' => 'Cart is empty!']);
        exit();
    }
}

// Handle Retrieve Held Bill
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['retrieve_held_bill'])) {
    $hold_id = mysqli_real_escape_string($conn, $_POST['hold_id']);
    
    foreach ($_SESSION[$held_bills_key] as $index => $held_bill) {
        if ($held_bill['id'] == $hold_id) {
            // Restore cart
            $_SESSION[$cart_session_key] = $held_bill['cart'];
            
            // Remove from held bills
            unset($_SESSION[$held_bills_key][$index]);
            $_SESSION[$held_bills_key] = array_values($_SESSION[$held_bills_key]);
            
            // Update bill number (keep the original)
            $bill_no = $held_bill['bill_no'];
            
            $cart_html = generateCartHTML($_SESSION[$cart_session_key]);
            $totals = calculateTotals($_SESSION[$cart_session_key]);
            
            echo json_encode([
                'success' => true, 
                'message' => 'Bill retrieved successfully!',
                'cart_html' => $cart_html,
                'totals' => $totals,
                'cart_count' => count($_SESSION[$cart_session_key]),
                'bill_no' => $bill_no,
                'cashier_id' => $held_bill['cashier_id'],
                'customer_id' => $held_bill['customer_id'],
                'customer_name' => $held_bill['customer_name'],
                'phone_no' => $held_bill['phone_no'],
                'nic_no' => $held_bill['nic_no']
            ]);
            exit();
        }
    }
    
    echo json_encode(['success' => false, 'message' => 'Held bill not found!']);
    exit();
}

// Handle Delete Held Bill
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_held_bill'])) {
    $hold_id = mysqli_real_escape_string($conn, $_POST['hold_id']);
    
    foreach ($_SESSION[$held_bills_key] as $index => $held_bill) {
        if ($held_bill['id'] == $hold_id) {
            unset($_SESSION[$held_bills_key][$index]);
            $_SESSION[$held_bills_key] = array_values($_SESSION[$held_bills_key]);
            
            echo json_encode(['success' => true, 'message' => 'Held bill deleted successfully!']);
            exit();
        }
    }
    
    echo json_encode(['success' => false, 'message' => 'Held bill not found!']);
    exit();
}

// Handle Get Held Bills List
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'get_held_bills') {
    $html = '';
    if (!empty($_SESSION[$held_bills_key])) {
        foreach ($_SESSION[$held_bills_key] as $held_bill) {
            $html .= '<div class="held-bill-item" data-id="' . htmlspecialchars($held_bill['id']) . '">';
            $html .= '<div class="held-bill-header">';
            $html .= '<span class="held-bill-no">Bill: ' . htmlspecialchars($held_bill['bill_no']) . '</span>';
            $html .= '<span class="held-bill-date">' . date('d/m/Y H:i', strtotime($held_bill['date'])) . '</span>';
            $html .= '</div>';
            $html .= '<div class="held-bill-details">';
            $html .= '<div><strong>Items:</strong> ' . count($held_bill['cart']) . ' products</div>';
            $html .= '<div><strong>Total:</strong> Rs' . number_format($held_bill['totals']['total'], 2) . '</div>';
            if ($held_bill['hold_note']) {
                $html .= '<div class="held-bill-note"><i class="fas fa-sticky-note"></i> ' . htmlspecialchars($held_bill['hold_note']) . '</div>';
            }
            if ($held_bill['customer_name']) {
                $html .= '<div><i class="fas fa-user"></i> ' . htmlspecialchars($held_bill['customer_name']) . '</div>';
            }
            $html .= '</div>';
            $html .= '<div class="held-bill-actions">';
            $html .= '<button class="btn-retrieve" onclick="retrieveHeldBill(\'' . $held_bill['id'] . '\')"><i class="fas fa-folder-open"></i> Retrieve</button>';
            $html .= '<button class="btn-delete-held" onclick="deleteHeldBill(\'' . $held_bill['id'] . '\')"><i class="fas fa-trash"></i> Delete</button>';
            $html .= '</div>';
            $html .= '</div>';
        }
    } else {
        $html = '<div class="no-held-bills">';
        $html .= '<i class="fas fa-inbox"></i>';
        $html .= '<h3>No Hold Bills</h3>';
        $html .= '<p>Your hold bills will appear here</p>';
        $html .= '</div>';
    }
    
    echo json_encode(['html' => $html]);
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_to_cart'])) {
    $product_id = mysqli_real_escape_string($conn, $_POST['product_id']);
    $quantity = intval($_POST['quantity']);
    $discount = floatval($_POST['discount']);
    $discount_type = mysqli_real_escape_string($conn, $_POST['discount_type']);
    $imei = mysqli_real_escape_string($conn, $_POST['imei']);
    
    $product_sql = "SELECT * FROM products WHERE product_id = '$product_id'";
    $product_result = $conn->query($product_sql);
    
    if ($product_result->num_rows > 0) {
        $product = $product_result->fetch_assoc();
        $existing_quantity = isset($_SESSION[$cart_session_key][$product_id]) ? $_SESSION[$cart_session_key][$product_id]['quantity'] : 0;
        $total_quantity = $existing_quantity + $quantity;
        
        if ($total_quantity <= $product['stock']) {
            $subtotal_amount = $product['sale_price'] * $total_quantity;
            
            if ($discount_type === 'percentage') {
                $discount_amount = ($subtotal_amount * $discount) / 100;
            } else {
                $discount_amount = $discount;
            }
            
            $new_subtotal = $subtotal_amount - $discount_amount;
            
            if (isset($_SESSION[$cart_session_key][$product_id])) {
                $_SESSION[$cart_session_key][$product_id]['quantity'] = $total_quantity;
                $_SESSION[$cart_session_key][$product_id]['discount'] = $discount_amount;
                $_SESSION[$cart_session_key][$product_id]['discount_input'] = $discount;
                $_SESSION[$cart_session_key][$product_id]['discount_type'] = $discount_type;
                $_SESSION[$cart_session_key][$product_id]['subtotal'] = $new_subtotal;
                $_SESSION[$cart_session_key][$product_id]['imei'] = $imei;
            } else {
                $_SESSION[$cart_session_key][$product_id] = [
                    'name' => $product['name'],
                    'color' => $product['color'],
                    'price' => $product['sale_price'],
                    'quantity' => $quantity,
                    'discount' => $discount_amount,
                    'discount_input' => $discount,
                    'discount_type' => $discount_type,
                    'subtotal' => $new_subtotal,
                    'photo' => $product['photo'],
                    'imei' => $imei,
                    'original_subtotal' => $subtotal_amount
                ];
            }
            
            $cart_html = generateCartHTML($_SESSION[$cart_session_key]);
            $totals = calculateTotals($_SESSION[$cart_session_key]);
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true, 
                'message' => 'Product added to cart',
                'cart_html' => $cart_html,
                'totals' => $totals,
                'cart_count' => count($_SESSION[$cart_session_key])
            ]);
            exit();
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => "Insufficient stock for {$product['name']}. Available: {$product['stock']}"]);
            exit();
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => "Product not found."]);
        exit();
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_cart'])) {
    $product_id = mysqli_real_escape_string($conn, $_POST['product_id']);
    $quantity = intval($_POST['quantity']);
    $discount = floatval($_POST['discount']);
    $discount_type = mysqli_real_escape_string($conn, $_POST['discount_type']);
    $imei = mysqli_real_escape_string($conn, $_POST['imei']);
    
    $product_sql = "SELECT stock, sale_price FROM products WHERE product_id = '$product_id'";
    $product_result = $conn->query($product_sql);
    
    if ($product_result->num_rows > 0) {
        $product = $product_result->fetch_assoc();
        if ($quantity <= $product['stock']) {
            $subtotal_amount = $product['sale_price'] * $quantity;
            
            if ($discount_type === 'percentage') {
                $discount_amount = ($subtotal_amount * $discount) / 100;
            } else {
                $discount_amount = $discount;
            }
            
            $new_subtotal = $subtotal_amount - $discount_amount;
            
            if ($quantity > 0) {
                $_SESSION[$cart_session_key][$product_id]['quantity'] = $quantity;
                $_SESSION[$cart_session_key][$product_id]['discount'] = $discount_amount;
                $_SESSION[$cart_session_key][$product_id]['discount_input'] = $discount;
                $_SESSION[$cart_session_key][$product_id]['discount_type'] = $discount_type;
                $_SESSION[$cart_session_key][$product_id]['subtotal'] = $new_subtotal;
                $_SESSION[$cart_session_key][$product_id]['imei'] = $imei;
                $_SESSION[$cart_session_key][$product_id]['original_subtotal'] = $subtotal_amount;
            } else {
                unset($_SESSION[$cart_session_key][$product_id]);
            }
            
            $cart_html = generateCartHTML($_SESSION[$cart_session_key]);
            $totals = calculateTotals($_SESSION[$cart_session_key]);
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'cart_html' => $cart_html,
                'totals' => $totals,
                'cart_count' => count($_SESSION[$cart_session_key])
            ]);
            exit();
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => "Insufficient stock for {$_SESSION[$cart_session_key][$product_id]['name']}. Available: {$product['stock']}"]);
            exit();
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => "Product not found."]);
        exit();
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['apply_batch_discount'])) {
    $batch_discount = floatval($_POST['batch_discount']);
    $batch_discount_type = mysqli_real_escape_string($conn, $_POST['batch_discount_type']);
    
    foreach ($_SESSION[$cart_session_key] as $product_id => $item) {
        $subtotal_amount = $item['price'] * $item['quantity'];
        
        if ($batch_discount_type === 'percentage') {
            $discount_amount = ($subtotal_amount * $batch_discount) / 100;
        } else {
            $discount_amount = $batch_discount;
        }
        
        $_SESSION[$cart_session_key][$product_id]['discount'] = $discount_amount;
        $_SESSION[$cart_session_key][$product_id]['discount_input'] = $batch_discount;
        $_SESSION[$cart_session_key][$product_id]['discount_type'] = $batch_discount_type;
        $_SESSION[$cart_session_key][$product_id]['subtotal'] = $subtotal_amount - $discount_amount;
    }
    
    $cart_html = generateCartHTML($_SESSION[$cart_session_key]);
    $totals = calculateTotals($_SESSION[$cart_session_key]);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'cart_html' => $cart_html,
        'totals' => $totals,
        'message' => 'Batch discount applied successfully'
    ]);
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['apply_rounding'])) {
    $round_type = mysqli_real_escape_string($conn, $_POST['round_type']);
    $round_target = mysqli_real_escape_string($conn, $_POST['round_target']);
    
    if ($round_type !== 'none') {
        if ($round_target === 'discount') {
            foreach ($_SESSION[$cart_session_key] as $product_id => $item) {
                $rounded_discount = roundAmount($item['discount'], $round_type);
                $_SESSION[$cart_session_key][$product_id]['discount'] = $rounded_discount;
                $_SESSION[$cart_session_key][$product_id]['subtotal'] = $item['original_subtotal'] - $rounded_discount;
            }
        } else {
            $totals = calculateTotals($_SESSION[$cart_session_key]);
            $rounded_total = roundAmount($totals['total'], $round_type);
            $difference = $rounded_total - $totals['total'];
            
            if (!empty($_SESSION[$cart_session_key])) {
                $keys = array_keys($_SESSION[$cart_session_key]);
                $last_key = end($keys);
                
                $new_discount = $_SESSION[$cart_session_key][$last_key]['discount'] - $difference;
                if ($new_discount < 0) $new_discount = 0;
                
                $_SESSION[$cart_session_key][$last_key]['discount'] = $new_discount;
                $_SESSION[$cart_session_key][$last_key]['subtotal'] = 
                    $_SESSION[$cart_session_key][$last_key]['original_subtotal'] - $new_discount;
            }
        }
    }
    
    $cart_html = generateCartHTML($_SESSION[$cart_session_key]);
    $totals = calculateTotals($_SESSION[$cart_session_key]);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'cart_html' => $cart_html,
        'totals' => $totals,
        'message' => 'Rounding applied successfully'
    ]);
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['complete_sale'])) {
    $cashier_id = mysqli_real_escape_string($conn, $_POST['cashier_id']);
    $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method']);
    $paid_amount = floatval($_POST['paid_amount']);
    $customer_name = isset($_POST['customer_name']) ? mysqli_real_escape_string($conn, $_POST['customer_name']) : null;
    $phone_no = isset($_POST['phone_no']) ? mysqli_real_escape_string($conn, $_POST['phone_no']) : null;
    $nic_no = isset($_POST['nic_no']) ? mysqli_real_escape_string($conn, $_POST['nic_no']) : null;
    $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : null;

    $subtotal = 0;
    $total_discount = 0;

    $errors = [];
    if (empty($_SESSION[$cart_session_key])) {
        $errors[] = "Cart is empty. Please add products to complete sale.";
    }
    if (empty($cashier_id)) {
        $errors[] = "Please select a cashier.";
    }
    if (empty($payment_method)) {
        $errors[] = "Please select a payment method.";
    }
    if ($payment_method == "Credit" && (empty($customer_name) || empty($phone_no) || empty($nic_no))) {
        $errors[] = "Customer Name, Phone Number, and NIC Number are required for Credit payment.";
    }

    foreach ($_SESSION[$cart_session_key] as $item) {
        $subtotal += ($item['price'] * $item['quantity']);
        $total_discount += $item['discount'];
    }
    $total = $subtotal - $total_discount;

    if ($total <= 0) {
        $errors[] = "Total amount must be greater than zero.";
    }

    if (!empty($errors)) {
        $error_message = implode("\\n", $errors);
        echo "<script>alert('$error_message');</script>";
    } else {
        $balance = $paid_amount - $total;

        // Insert bill header
        $bill_sql = "INSERT INTO bills 
                     (bill_no, branch_id, cashier_id, date, payment_method, subtotal, total_discount, total, paid_amount, balance, customer_name, phone_no, nic_no, customer_id)
                     VALUES 
                     ('$bill_no', $branch_id, '$cashier_id', NOW(), '$payment_method', $subtotal, $total_discount, $total, $paid_amount, $balance, " .
                     ($customer_name ? "'$customer_name'" : "NULL") . ", " .
                     ($phone_no ? "'$phone_no'" : "NULL") . ", " .
                     ($nic_no ? "'$nic_no'" : "NULL") . ", " .
                     ($customer_id ? $customer_id : "NULL") . ")";

        if ($conn->query($bill_sql)) {
            // Save cart to a temporary variable BEFORE clearing session
            $cart_snapshot = $_SESSION[$cart_session_key];

            // Insert bill items using snapshot
            foreach ($cart_snapshot as $product_id => $item) {
                $product_sql = "SELECT name, color, original_price FROM products WHERE product_id = '$product_id'";
                $product_result = $conn->query($product_sql);
                $product_details = $product_result->fetch_assoc();

                $product_name = $product_details['name'] ?? $item['name'];
                $product_color = $product_details['color'] ?? ($item['color'] ?? '');
                $original_price = $product_details['original_price'] ?? $item['price'];

                // Properly escape and quote all values
                $product_name_esc = mysqli_real_escape_string($conn, $product_name);
                $product_color_esc = mysqli_real_escape_string($conn, $product_color);
                $imei_esc = mysqli_real_escape_string($conn, $item['imei'] ?? '');
                $discount_type_esc = mysqli_real_escape_string($conn, $item['discount_type'] ?? 'rs');

                $item_sql = "INSERT INTO bill_items 
                             (bill_no, cashier_id, product_id, quantity, price, discount, subtotal, imei, product_name, product_color, original_price, discount_type, discount_input)
                             VALUES 
                             ('$bill_no', '$cashier_id', '$product_id', {$item['quantity']}, {$item['price']}, {$item['discount']}, {$item['subtotal']}, '$imei_esc', '$product_name_esc', '$product_color_esc', $original_price, '$discount_type_esc', {$item['discount_input']})";

                $conn->query($item_sql);

                // Update stock
                $update_stock_sql = "UPDATE products 
                                     SET stock = stock - {$item['quantity']}, 
                                         sold = sold + {$item['quantity']}, 
                                         last_sold = NOW()
                                     WHERE product_id = '$product_id'";
                $conn->query($update_stock_sql);
            }

            // Insert bill_summary (using first item from snapshot)
            $first_item = reset($cart_snapshot);
            $summary_product_name = $first_item ? mysqli_real_escape_string($conn, $first_item['name']) : null;
            $summary_imei = $first_item ? mysqli_real_escape_string($conn, $first_item['imei'] ?? '') : null;
            $summary_quantity = $first_item ? $first_item['quantity'] : null;

            $bill_summary_sql = "INSERT INTO bill_summary 
                                 (bill_no, cashier_id, date, customer_name, phone_no, nic_no, total_amount, balance, product_name, imei, quantity, customer_id)
                                 VALUES 
                                 ('$bill_no', '$cashier_id', NOW(), " .
                                 ($customer_name ? "'$customer_name'" : "NULL") . ", " .
                                 ($phone_no ? "'$phone_no'" : "NULL") . ", " .
                                 ($nic_no ? "'$nic_no'" : "NULL") . ", $total, $balance, " .
                                 ($summary_product_name ? "'$summary_product_name'" : "NULL") . ", " .
                                 ($summary_imei ? "'$summary_imei'" : "NULL") . ", " .
                                 ($summary_quantity ? $summary_quantity : "NULL") . ", " .
                                 ($customer_id ? $customer_id : "NULL") . ")";

            $conn->query($bill_summary_sql);

            // NOW clear the cart (after everything is saved)
            $_SESSION[$cart_session_key] = [];

            // Generate new bill number for next bill
            $bill_no = generateBillNo($conn, $branch_id);

            echo "<script>alert('Sale completed successfully!'); window.location.href='billing.php';</script>";
            exit();
        } else {
            echo "<script>alert('Error completing sale: " . addslashes($conn->error) . "');</script>";
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['logout'])) {
    unset($_SESSION['cashier_id']);
    unset($_SESSION['branch_id']);
    header("Location: ../index.php");
    exit();
}

$current_date = date('Y-m-d');
$total_sales = 0;
$total_profit = 0;
$total_discount = 0;

$bills_sql = "SELECT bill_no, date, payment_method, paid_amount, total, subtotal, total_discount FROM bills WHERE DATE(date) = '$current_date' ORDER BY date ASC";
$bills_result = $conn->query($bills_sql);

while ($bill = $bills_result->fetch_assoc()) {
    $bill_no = $bill['bill_no'];
    $payment_method = $bill['payment_method'];
    $paid_amount = floatval($bill['paid_amount']);
    $total_bill = floatval($bill['total']);
    $subtotal_bill = floatval($bill['subtotal']);
    $discount_bill = floatval($bill['total_discount']);

    $total_sales += $total_bill;
    
    $items_sql = "SELECT bi.*, p.original_price FROM bill_items bi JOIN products p ON bi.product_id = p.product_id WHERE bi.bill_no = '$bill_no'";
    $items_result = $conn->query($items_sql);
    
    while ($item = $items_result->fetch_assoc()) {
        $cost = $item['original_price'] * $item['quantity'];
        $revenue = $item['subtotal'] - $item['discount'];
        $profit = $revenue - $cost;
        $total_profit += $profit;
    }
}

function generateCartHTML($cart) {
    $html = '';
    if (!empty($cart)) {
        foreach ($cart as $product_id => $item) {
            $discount_display = '';
            if ($item['discount_type'] === 'percentage') {
                $discount_display = $item['discount_input'] . '%';
            } else {
                $discount_display = 'Rs' . number_format($item['discount_input'], 2);
            }
            
            $html .= '
                <div class="cart-item" id="cart-item-' . htmlspecialchars($product_id) . '">
                    <div class="cart-item-content">
                        <div class="item-name">' . htmlspecialchars($item['name']) . ' (' . htmlspecialchars($item['color']) . ')</div>
                        <div class="quantity-controls">
                            <button class="minus" onclick="updateCart(\'' . htmlspecialchars($product_id) . '\', ' . ($item['quantity'] - 1) . ', ' . $item['discount_input'] . ', \'' . $item['discount_type'] . '\', \'' . htmlspecialchars($item['imei']) . '\')">-</button>
                            <span>' . $item['quantity'] . '</span>
                            <button class="plus" onclick="updateCart(\'' . htmlspecialchars($product_id) . '\', ' . ($item['quantity'] + 1) . ', ' . $item['discount_input'] . ', \'' . $item['discount_type'] . '\', \'' . htmlspecialchars($item['imei']) . '\')">+</button>
                        </div>
                        <div class="discount-line">
                            <span>Discount:</span>
                            <input type="number" step="0.01" min="0" value="' . number_format($item['discount_input'], 2) . '" onchange="updateCart(\'' . htmlspecialchars($product_id) . '\', ' . $item['quantity'] . ', parseFloat(this.value), \'' . $item['discount_type'] . '\', \'' . htmlspecialchars($item['imei']) . '\')">
                            <select onchange="updateCart(\'' . htmlspecialchars($product_id) . '\', ' . $item['quantity'] . ', ' . $item['discount_input'] . ', this.value, \'' . htmlspecialchars($item['imei']) . '\')">
                                <option value="rs" ' . ($item['discount_type'] === 'rs' ? 'selected' : '') . '>Rs</option>
                                <option value="percentage" ' . ($item['discount_type'] === 'percentage' ? 'selected' : '') . '>%</option>
                            </select>
                        </div>
                        <div class="imei-line">
                            <span>Customer:</span>
                            <input type="text" value="' . htmlspecialchars($item['imei']) . '" onchange="updateCart(\'' . htmlspecialchars($product_id) . '\', ' . $item['quantity'] . ', ' . $item['discount_input'] . ', \'' . $item['discount_type'] . '\', this.value)">
                        </div>
                        <div class="subtotal-line">Price: Rs' . number_format($item['price'], 2) . ' x ' . $item['quantity'] . ' = Rs' . number_format($item['original_subtotal'], 2) . ' - ' . $discount_display . ' = Rs' . number_format($item['subtotal'], 2) . '</div>
                    </div>
                    <button class="trash" onclick="updateCart(\'' . htmlspecialchars($product_id) . '\', 0, 0, \'rs\', \'\')"><i class="fas fa-trash"></i></button>
                </div>';
        }
    }
    return $html;
}

function calculateTotals($cart) {
    $subtotal = 0;
    $total_discount = 0;
    $original_subtotal = 0;
    
    if (!empty($cart)) {
        foreach ($cart as $item) {
            $subtotal += ($item['price'] * $item['quantity']);
            $total_discount += $item['discount'];
            $original_subtotal += isset($item['original_subtotal']) ? $item['original_subtotal'] : ($item['price'] * $item['quantity']);
        }
    }
    
    $total = $subtotal - $total_discount;
    
    return [
        'subtotal' => $subtotal,
        'original_subtotal' => $original_subtotal,
        'total_discount' => $total_discount,
        'total' => $total,
        'rounded_total' => round($total)
    ];
}

$cart_totals = calculateTotals($_SESSION[$cart_session_key]);
$subtotal = $cart_totals['subtotal'];
$original_subtotal = $cart_totals['original_subtotal'];
$total_discount = $cart_totals['total_discount'];
$total = $cart_totals['total'];

$products_page = 1;
$products_limit = 12;
$products_offset = ($products_page - 1) * $products_limit;
$products_sql = "SELECT * FROM products WHERE stock > 0 LIMIT $products_limit OFFSET $products_offset";
$products_result = $conn->query($products_sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIDS Berry - Billing System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #5a3d7e;
            --secondary: #28a745;
            --accent: #dc3545;
            --success: #28a745;
            --warning: #ffc107;
            --light: #f8f9fa;
            --dark: #343a40;
            --text: #212529;
            --card-shadow: 0 6px 12px rgba(0,0,0,0.15), 0 2px 6px rgba(0,0,0,0.1);
            --transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            --radius: 15px;
            --gradient-primary: linear-gradient(135deg, #5a3d7e 0%, #8e44ad 100%);
            --gradient-secondary: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            --gradient-accent: linear-gradient(135deg, #dc3545 0%, #ff6b6b 100%);
            --gradient-hold: linear-gradient(135deg, #ff9800 0%, #ff5722 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
            color: var(--text);
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--secondary), var(--primary), var(--accent));
            z-index: 1000;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--gradient-primary);
            color: white;
            padding: 18px 35px;
            border-radius: 0 0 var(--radius) var(--radius);
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
            animation: slideDown 0.7s ease;
        }

        .header::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
            animation: shimmer 3s infinite;
        }

        .header-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex-grow: 1;
            z-index: 1;
        }

        .header h1 {
            margin: 0;
            font-size: 32px;
            font-weight: 800;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
            color: #ffffff;
            letter-spacing: 0.5px;
        }

        .header .date-time {
            font-size: 16px;
            text-align: center;
            margin-top: 5px;
            opacity: 0.9;
        }

        .logo-container img {
            width: 85px;
            height: 85px;
            border-radius: 50%;
            border: 3px solid white;
            box-shadow: 0 0 20px rgba(255,255,255,0.6);
            transition: var(--transition);
            z-index: 1;
        }

        .logo-container img:hover {
            transform: rotate(5deg) scale(1.05);
            box-shadow: 0 0 25px rgba(255,255,255,0.8);
        }

        .logout-btn {
            background: rgba(255,255,255,0.15);
            color: white;
            border: 2px solid rgba(255,255,255,0.3);
            padding: 12px 25px;
            border-radius: 50px;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
            font-size: 15px;
            backdrop-filter: blur(10px);
            z-index: 1;
        }

        .logout-btn:hover {
            background: rgba(255,255,255,0.25);
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .nav {
            margin: 25px auto;
            display: flex;
            justify-content: center;
            max-width: 95%;
            flex-wrap: wrap;
            gap: 10px;
        }

        .nav a {
            text-decoration: none;
            color: white;
            font-weight: 600;
            padding: 14px 28px;
            border-radius: 50px;
            background: var(--gradient-secondary);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }

        .nav a::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: 0.5s;
        }

        .nav a:hover::before {
            left: 100%;
        }

        .nav a.active {
            background: var(--gradient-accent);
            box-shadow: 0 6px 12px rgba(220, 53, 69, 0.3);
        }

        .nav a:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 15px rgba(0,0,0,0.2);
        }

        .main-container {
            display: flex;
            gap: 30px;
            padding: 0 30px;
            max-width: 1600px;
            margin: 0 auto 30px;
        }

        .left-panel {
            flex: 3;
            display: flex;
            flex-direction: column;
            gap: 25px;
        }

        .right-panel {
            flex: 2;
        }

        .card {
            background: white;
            border-radius: var(--radius);
            padding: 30px;
            box-shadow: var(--card-shadow);
            animation: fadeInUp 0.8s ease;
            border: 1px solid rgba(0,0,0,0.05);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .card:hover {
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: var(--gradient-primary);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 18px;
            border-bottom: 2px solid var(--light);
            position: relative;
        }

        .card-header::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 100px;
            height: 3px;
            background: var(--gradient-primary);
            border-radius: 3px;
        }

        .card-title {
            color: var(--primary);
            font-size: 26px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
        }

        .card-title i {
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .bill-no, .cart-count {
            background: var(--gradient-primary);
            color: white;
            padding: 8px 15px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 14px;
            box-shadow: 0 3px 6px rgba(90, 61, 126, 0.2);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 20px;
            border-radius: var(--radius);
            text-align: center;
            transition: var(--transition);
            border: 1px solid rgba(0,0,0,0.05);
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }

        .stat-label {
            color: var(--dark);
            font-size: 14px;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .stat-value {
            color: var(--primary);
            font-size: 28px;
            font-weight: 800;
        }

        /* Products Section */
        .search-container {
            position: relative;
            margin-bottom: 25px;
        }

        .search-input {
            width: 100%;
            padding: 16px 20px;
            padding-left: 50px;
            border: 2px solid #e0e0e0;
            border-radius: var(--radius);
            font-size: 16px;
            transition: var(--transition);
            background: #fafafa;
            font-weight: 500;
        }

        .search-input:focus {
            border-color: var(--secondary);
            box-shadow: 0 0 0 4px rgba(40, 167, 69, 0.15);
            outline: none;
            background: white;
            transform: translateY(-2px);
        }

        .search-icon {
            position: absolute;
            left: 20px;
            top: 16px;
            color: var(--primary);
            font-size: 18px;
        }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
            max-height: 500px;
            overflow-y: auto;
            padding: 10px;
            scrollbar-width: thin;
            scrollbar-color: var(--primary) #f0f0f0;
        }

        .products-grid::-webkit-scrollbar {
            width: 8px;
        }

        .products-grid::-webkit-scrollbar-track {
            background: #f0f0f0;
            border-radius: 10px;
        }

        .products-grid::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 10px;
        }

        .product-item {
            background: white;
            border-radius: var(--radius);
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            border: 2px solid transparent;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.08);
            position: relative;
            overflow: hidden;
        }

        .product-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: var(--gradient-primary);
        }

        .product-item:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 15px 30px rgba(0,0,0,0.15);
            border-color: var(--secondary);
        }

        .product-item img {
            max-height: 100px;
            border-radius: 10px;
            object-fit: cover;
            width: 100%;
            transition: var(--transition);
        }

        .product-item:hover img {
            transform: scale(1.05);
        }

        .product-name {
            font-weight: 700;
            color: var(--dark);
            font-size: 16px;
            line-height: 1.4;
        }

        .product-price {
            color: var(--secondary);
            font-weight: 800;
            font-size: 18px;
            background: rgba(40, 167, 69, 0.1);
            padding: 5px 12px;
            border-radius: 20px;
        }

        .product-stock {
            color: #6c757d;
            font-size: 13px;
            font-weight: 600;
        }

        .product-color {
            color: var(--accent);
            font-size: 13px;
            font-weight: 600;
            background: rgba(220, 53, 69, 0.1);
            padding: 3px 10px;
            border-radius: 15px;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 20px;
            margin-top: 25px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: var(--radius);
            border: 1px solid rgba(0,0,0,0.05);
        }

        .page-btn {
            padding: 10px 20px;
            background: var(--gradient-primary);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
            box-shadow: 0 3px 6px rgba(90, 61, 126, 0.2);
        }

        .page-btn:hover {
            background: var(--gradient-secondary);
            transform: translateY(-3px);
            box-shadow: 0 5px 12px rgba(40, 167, 69, 0.3);
        }

        .page-info {
            font-weight: 700;
            color: var(--dark);
            font-size: 15px;
        }

        /* Cart Section */
        .cart-container {
            background: white;
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--card-shadow);
            border: 1px solid rgba(0,0,0,0.05);
            display: flex;
            flex-direction: column;
            height: auto;
            min-height: 600px;
        }

        .cart-header {
            background: var(--gradient-primary);
            color: white;
            padding: 20px;
            text-align: center;
            font-weight: 700;
            font-size: 20px;
            position: relative;
            overflow: hidden;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .cart-header::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, transparent 30%, rgba(255,255,255,0.1) 50%, transparent 70%);
            animation: shimmer 2s infinite;
        }

        .cart-content {
            flex: 1;
            height: auto;
            overflow-y: visible;
            padding: 20px;
        }

        .cart-item {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 15px;
            transition: var(--transition);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border: 1px solid rgba(0,0,0,0.05);
            position: relative;
            overflow: hidden;
        }

        .cart-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--gradient-secondary);
        }

        .cart-item:hover {
            transform: translateX(8px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .cart-item-content {
            flex: 1;
            padding-right: 15px;
        }

        .item-name {
            font-weight: 700;
            color: var(--dark);
            font-size: 16px;
            margin-bottom: 10px;
            line-height: 1.4;
        }

        .quantity-controls {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 12px 0;
        }

        .quantity-controls button {
            background: var(--gradient-secondary);
            color: white;
            border: none;
            border-radius: 6px;
            width: 35px;
            height: 35px;
            cursor: pointer;
            font-weight: bold;
            font-size: 16px;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .quantity-controls button:hover {
            transform: scale(1.1);
            box-shadow: 0 3px 8px rgba(40, 167, 69, 0.3);
        }

        .quantity-controls span {
            font-weight: 700;
            font-size: 16px;
            min-width: 30px;
            text-align: center;
        }

        .discount-line, .imei-line {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 8px 0;
            font-size: 14px;
            font-weight: 600;
            color: var(--dark);
        }

        .discount-line input, .imei-line input {
            width: 90px;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-weight: 500;
            transition: var(--transition);
        }

        .discount-line input:focus, .imei-line input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(90, 61, 126, 0.1);
            outline: none;
        }

        .discount-line select {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-weight: 500;
            background: white;
            cursor: pointer;
            transition: var(--transition);
        }

        .discount-line select:focus {
            border-color: var(--primary);
            outline: none;
        }

        .subtotal-line {
            font-size: 13px;
            color: var(--dark);
            margin-top: 10px;
            background: rgba(0,0,0,0.03);
            padding: 8px 12px;
            border-radius: 6px;
            font-weight: 600;
        }

        .trash {
            background: var(--gradient-accent);
            color: white;
            border: none;
            border-radius: 6px;
            width: 40px;
            height: 40px;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .trash:hover {
            transform: rotate(15deg) scale(1.1);
            box-shadow: 0 4px 10px rgba(220, 53, 69, 0.3);
        }

        /* Held Bills Section */
        .held-bills-section {
            margin-top: 20px;
            background: white;
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--card-shadow);
        }

        .held-bills-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light);
        }

        .held-bills-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .held-bills-title i {
            color: var(--warning);
        }

        .held-bills-count {
            background: var(--warning);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }

        .held-bills-container {
            max-height: 400px;
            overflow-y: auto;
            padding: 10px;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        .held-bill-item {
            background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%);
            border-radius: 12px;
            padding: 15px;
            border-left: 5px solid var(--warning);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .held-bill-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }

        .held-bill-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px dashed rgba(0,0,0,0.1);
        }

        .held-bill-no {
            font-weight: 700;
            color: var(--dark);
            font-size: 14px;
        }

        .held-bill-date {
            font-size: 12px;
            color: #6c757d;
        }

        .held-bill-details {
            margin: 10px 0;
            font-size: 13px;
            line-height: 1.6;
        }

        .held-bill-details div {
            margin: 5px 0;
        }

        .held-bill-note {
            background: rgba(255,255,255,0.5);
            padding: 8px;
            border-radius: 6px;
            font-style: italic;
            margin: 8px 0;
        }

        .held-bill-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .btn-retrieve {
            flex: 1;
            background: var(--gradient-secondary);
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            transition: var(--transition);
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }

        .btn-retrieve:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 10px rgba(40, 167, 69, 0.3);
        }

        .btn-delete-held {
            flex: 1;
            background: var(--gradient-accent);
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            transition: var(--transition);
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }

        .btn-delete-held:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 10px rgba(220, 53, 69, 0.3);
        }

        .no-held-bills {
            grid-column: 1 / -1;
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }

        .no-held-bills i {
            font-size: 50px;
            margin-bottom: 15px;
            color: #dee2e6;
        }

        .no-held-bills h3 {
            font-size: 18px;
            margin-bottom: 10px;
            color: var(--dark);
        }

        .no-held-bills p {
            font-size: 14px;
        }

        /* Hold Bill Button */
        .hold-bill-btn {
            background: var(--gradient-hold);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 6px rgba(255, 152, 0, 0.2);
        }

        .hold-bill-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 15px rgba(255, 152, 0, 0.3);
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
            backdrop-filter: blur(5px);
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: var(--radius);
            padding: 30px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            position: relative;
            animation: modalSlideIn 0.3s ease;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light);
        }

        .modal-header h3 {
            font-size: 22px;
            color: var(--primary);
            font-weight: 700;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #6c757d;
            transition: var(--transition);
        }

        .modal-close:hover {
            color: var(--accent);
            transform: rotate(90deg);
        }

        .modal-body {
            margin-bottom: 20px;
        }

        .modal-footer {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .tools-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 20px;
            border-radius: var(--radius);
            margin: 20px 0;
            border: 1px solid rgba(0,0,0,0.05);
        }

        .tools-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .tool-group {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
            transition: var(--transition);
            border: 1px solid rgba(0,0,0,0.03);
        }

        .tool-group:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }

        .tool-title {
            font-weight: 700;
            margin-bottom: 15px;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
        }

        .tool-row {
            display: flex;
            gap: 12px;
            margin-bottom: 12px;
            align-items: center;
        }

        .tool-row select, .tool-row input {
            flex: 1;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-weight: 500;
            background: white;
            transition: var(--transition);
        }

        .tool-row select:focus, .tool-row input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(90, 61, 126, 0.1);
            outline: none;
        }

        .tool-row button {
            flex: 1;
            padding: 10px;
            background: var(--gradient-primary);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
            box-shadow: 0 3px 6px rgba(90, 61, 126, 0.2);
        }

        .tool-row button:hover {
            background: var(--gradient-secondary);
            transform: translateY(-3px);
            box-shadow: 0 5px 12px rgba(40, 167, 69, 0.3);
        }

        .totals-section {
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: var(--radius);
            margin: 20px 0;
            border: 1px solid rgba(0,0,0,0.05);
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            margin: 12px 0;
            font-size: 17px;
            padding: 10px 0;
            border-bottom: 1px dashed rgba(0,0,0,0.1);
        }

        .total-row:last-child {
            border-bottom: none;
        }

        .total-amount {
            font-weight: 800;
            color: var(--primary);
        }

        .total-label {
            font-weight: 700;
            color: var(--dark);
        }

        .final-total {
            font-size: 22px;
            color: var(--success);
            border-top: 2px solid var(--light);
            padding-top: 15px;
            margin-top: 15px;
            font-weight: 800;
        }

        .payment-section {
            background: white;
            padding: 25px;
            border-radius: var(--radius);
            margin-top: 20px;
            border: 1px solid rgba(0,0,0,0.05);
        }

        .form-group {
            margin-bottom: 20px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px;
        }

        .form-group label i {
            color: var(--primary);
        }

        .form-group select, .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 500;
            transition: var(--transition);
            background: white;
        }

        .form-group select:focus, .form-group input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(90, 61, 126, 0.1);
            outline: none;
            transform: translateY(-2px);
        }

        .action-buttons {
            display: flex;
            gap: 20px;
            margin-top: 25px;
        }

        .btn {
            flex: 1;
            padding: 16px;
            border: none;
            border-radius: 10px;
            font-size: 17px;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: 0.5s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: var(--gradient-secondary);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(40, 167, 69, 0.3);
        }

        .btn-secondary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-secondary:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(90, 61, 126, 0.3);
        }

        .btn-hold {
            background: var(--gradient-hold);
            color: white;
        }

        .btn-hold:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(255, 152, 0, 0.3);
        }

        /* Customer Search Section */
        .customer-search-container {
            position: relative;
            margin-bottom: 15px;
        }

        .customer-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            max-height: 250px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            border-top: none;
            border-top-left-radius: 0;
            border-top-right-radius: 0;
        }

        .customer-option {
            padding: 15px;
            cursor: pointer;
            border-bottom: 1px solid #eee;
            transition: background 0.2s;
            font-size: 14px;
        }

        .customer-option:hover {
            background: #f0f8ff;
        }

        .customer-option strong {
            color: var(--primary);
            font-size: 15px;
        }

        .customer-option small {
            color: #6c757d;
        }

        #selected-customer-display {
            margin-top: 15px;
        }

        .selected-customer {
            background: linear-gradient(135deg, #e8f4fc 0%, #d4e9ff 100%);
            padding: 18px;
            border-radius: 10px;
            border: 2px solid var(--secondary);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            animation: fadeIn 0.5s ease;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        }

        .selected-customer > div {
            flex: 1;
            min-width: 250px;
        }

        .selected-customer strong {
            color: var(--primary);
            font-size: 16px;
            margin-bottom: 8px;
            display: block;
        }

        .selected-customer strong i {
            color: var(--success);
            margin-right: 8px;
        }

        .selected-customer-info {
            color: var(--dark);
            font-size: 14px;
            line-height: 1.5;
        }

        .selected-customer button {
            background: var(--gradient-accent);
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            box-shadow: 0 3px 6px rgba(220, 53, 69, 0.2);
        }

        .selected-customer button:hover {
            background: var(--accent);
            transform: translateY(-3px);
            box-shadow: 0 5px 12px rgba(220, 53, 69, 0.3);
        }

        .notification {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: #2196F3;
            color: white;
            padding: 18px 30px;
            border-radius: 10px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
            display: none;
            z-index: 1000;
            animation: slideInRight 0.5s ease, fadeOut 0.5s ease 2.5s forwards;
            font-weight: 600;
            max-width: 350px;
            backdrop-filter: blur(10px);
        }

        @keyframes slideDown {
            from { transform: translateY(-100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @keyframes fadeInUp {
            from { transform: translateY(40px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @keyframes slideInRight {
            from { transform: translateX(150px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; visibility: hidden; }
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 1200px) {
            .main-container {
                flex-direction: column;
            }
            
            .tools-grid {
                grid-template-columns: 1fr;
            }
            
            .products-grid {
                grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            }
            
            .held-bills-container {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 20px;
                text-align: center;
                padding: 20px;
            }
            
            .nav {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .nav a {
                padding: 12px 20px;
                font-size: 14px;
            }
            
            .main-container {
                padding: 0 15px;
                gap: 20px;
            }
            
            .card {
                padding: 20px;
            }
            
            .products-grid {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
                gap: 15px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .selected-customer {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .selected-customer button {
                align-self: flex-end;
            }
            
            .held-bills-container {
                grid-template-columns: 1fr;
            }
            
            .modal-content {
                width: 95%;
                padding: 20px;
            }
        }

        @media (max-width: 480px) {
            .header h1 {
                font-size: 24px;
            }
            
            .products-grid {
                grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
            }
            
            .tool-row {
                flex-direction: column;
            }
            
            .cart-item {
                flex-direction: column;
                gap: 15px;
            }
            
            .trash {
                align-self: flex-end;
            }
            
            .held-bills-container {
                grid-template-columns: 1fr;
            }
            
            .held-bill-actions {
                flex-direction: column;
            }
        }

        .empty-cart {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }

        .empty-cart i {
            font-size: 60px;
            margin-bottom: 20px;
            color: #dee2e6;
        }

        .empty-cart h3 {
            font-size: 20px;
            margin-bottom: 10px;
            color: var(--dark);
        }

        .empty-cart p {
            font-size: 14px;
            max-width: 300px;
            margin: 0 auto;
        }
    </style>
</head>
<body>

    <div class="header">
        <div class="logo-container">
            <img src="../logo.jpg" alt="Kids Berry Logo">
        </div>
        <div class="header-content">
            <h1><i class="fas fa-baby"></i> KIDS Berry - Billing System</h1>
            <div class="date-time">
                <p id="current-date"><?php echo date('l, F j, Y'); ?></p>
                <p id="current-time"><?php echo date('g:i:s A'); ?></p>
            </div>
        </div>
        <form method="post">
            <button type="submit" name="logout" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </form>
    </div>

    <div class="nav">
        <a href="billing.php" class="active"><i class="fas fa-cash-register"></i> Billing</a>
        <a href="customer.php"><i class="fas fa-users"></i> Customers</a>
        <a href="bill_management.php" class=""><i class="fas fa-history"></i> Bill History</a>
        <a href="report.php"><i class="fas fa-chart-line"></i> Reports</a>
        <a href="credit_payments.php"><i class="fas fa-credit-card"></i> Credit Customers</a>
        <a href="return_sale.php"><i class="fas fa-undo-alt"></i> Manage Returns</a>
        <!--<a href="prediction_dashboard.php" class=""><i class="fas fa-bullseye"></i> Predictions</a>-->
    </div>

    <div class="main-container">
        <div class="left-panel">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title"><i class="fas fa-boxes"></i> Products</h2>
                    <div class="bill-no" id="bill-no-display">Bill: <?php echo htmlspecialchars($bill_no); ?></div>
                </div>
                
                <div class="search-container">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" class="search-input" id="search-input" placeholder="Search products by name or barcode...">
                </div>
                
                <div class="products-grid" id="products-container">
                    <?php if ($products_result->num_rows > 0): ?>
                        <?php while ($product = $products_result->fetch_assoc()): ?>
                            <div class="product-item" onclick="showIMEIPrompt('<?php echo htmlspecialchars($product['product_id']); ?>', 1, 0, 'rs')">
                                <?php if ($product['photo']): ?>
                                    <img src="<?php echo htmlspecialchars($product['photo']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                                <?php endif; ?>
                                <div class="product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                                <div class="product-price">Rs<?php echo number_format($product['sale_price'], 2); ?></div>
                                <div class="product-stock">Stock: <?php echo $product['stock']; ?></div>
                                <div class="product-stock">Barcode: <?php echo $product['barcode']; ?></div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: #6c757d;">
                            <i class="fas fa-box-open" style="font-size: 60px; margin-bottom: 20px; color: #dee2e6;"></i>
                            <h3>No Products Available</h3>
                            <p>All products are out of stock or no products found.</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="pagination" id="product-pagination">
                    <button class="page-btn" onclick="loadProductPage(1)" id="prev-page">Previous</button>
                    <span class="page-info">Page 1</span>
                    <button class="page-btn" onclick="loadProductPage(2)" id="next-page">Next</button>
                </div>
            </div>

            <!-- Held Bills Section -->
            <div class="held-bills-section">
                <div class="held-bills-header">
                    <div class="held-bills-title">
                        <i class="fas fa-pause-circle"></i> Hold Bills
                        <span class="held-bills-count" id="held-bills-count">0</span>
                    </div>
                    <button class="btn-refresh" onclick="loadHeldBills()" style="background: none; border: none; color: var(--primary); cursor: pointer; font-size: 18px;">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
                <div class="held-bills-container" id="held-bills-container">
                    <!-- Held bills will be loaded here via AJAX -->
                </div>
            </div>
        </div>

        <div class="right-panel">
            <div class="cart-container">
                <div class="cart-header">
                    <span><i class="fas fa-shopping-cart"></i> Cart</span>
                    <span class="cart-count" id="cart-count">Items: <?php echo count($_SESSION[$cart_session_key]); ?></span>
                </div>
                
                <div class="cart-content" id="cart-content">
                    <?php if (!empty($_SESSION[$cart_session_key])): ?>
                        <?php foreach ($_SESSION[$cart_session_key] as $product_id => $item): ?>
                            <?php
                            $discount_display = '';
                            if ($item['discount_type'] === 'percentage') {
                                $discount_display = $item['discount_input'] . '%';
                            } else {
                                $discount_display = 'Rs' . number_format($item['discount_input'], 2);
                            }
                            ?>
                            <div class="cart-item" id="cart-item-<?php echo htmlspecialchars($product_id); ?>">
                                <div class="cart-item-content">
                                    <div class="item-name"><?php echo htmlspecialchars($item['name']) . ' (' . htmlspecialchars($item['color']) . ')'; ?></div>
                                    <div class="quantity-controls">
                                        <button class="minus" onclick="updateCart('<?php echo htmlspecialchars($product_id); ?>', <?php echo $item['quantity'] - 1; ?>, <?php echo $item['discount_input']; ?>, '<?php echo $item['discount_type']; ?>', '<?php echo htmlspecialchars($item['imei']); ?>')">-</button>
                                        <span><?php echo $item['quantity']; ?></span>
                                        <button class="plus" onclick="updateCart('<?php echo htmlspecialchars($product_id); ?>', <?php echo $item['quantity'] + 1; ?>, <?php echo $item['discount_input']; ?>, '<?php echo $item['discount_type']; ?>', '<?php echo htmlspecialchars($item['imei']); ?>')">+</button>
                                    </div>
                                    <div class="discount-line">
                                        <span>Discount:</span>
                                        <input type="number" step="0.01" min="0" value="<?php echo number_format($item['discount_input'], 2); ?>" onchange="updateCart('<?php echo htmlspecialchars($product_id); ?>', <?php echo $item['quantity']; ?>, parseFloat(this.value), '<?php echo $item['discount_type']; ?>', '<?php echo htmlspecialchars($item['imei']); ?>')">
                                        <select onchange="updateCart('<?php echo htmlspecialchars($product_id); ?>', <?php echo $item['quantity']; ?>, <?php echo $item['discount_input']; ?>, this.value, '<?php echo htmlspecialchars($item['imei']); ?>')">
                                            <option value="rs" <?php echo $item['discount_type'] === 'rs' ? 'selected' : ''; ?>>Rs</option>
                                            <option value="percentage" <?php echo $item['discount_type'] === 'percentage' ? 'selected' : ''; ?>>%</option>
                                        </select>
                                    </div>
                                    <div class="imei-line">
                                        <span>Customer:</span>
                                        <input type="text" value="<?php echo htmlspecialchars($item['imei']); ?>" onchange="updateCart('<?php echo htmlspecialchars($product_id); ?>', <?php echo $item['quantity']; ?>, <?php echo $item['discount_input']; ?>, '<?php echo $item['discount_type']; ?>', this.value)">
                                    </div>
                                    <div class="subtotal-line">Price: Rs<?php echo number_format($item['price'], 2); ?> x <?php echo $item['quantity']; ?> = Rs<?php echo number_format($item['original_subtotal'], 2); ?> - <?php echo $discount_display; ?> = Rs<?php echo number_format($item['subtotal'], 2); ?></div>
                                </div>
                                <button class="trash" onclick="updateCart('<?php echo htmlspecialchars($product_id); ?>', 0, 0, 'rs', '')"><i class="fas fa-trash"></i></button>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-cart">
                            <i class="fas fa-shopping-cart"></i>
                            <h3>Your cart is empty</h3>
                            <p>Add products from the left panel to get started</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="tools-section">
                    <div class="tools-grid">
                        <div class="tool-group">
                            <div class="tool-title"><i class="fas fa-tags"></i> Batch Discount</div>
                            <div class="tool-row">
                                <input type="number" step="0.01" min="0" id="batch-discount" placeholder="Discount">
                                <select id="batch-discount-type">
                                    <option value="rs">Rs</option>
                                    <option value="percentage">%</option>
                                </select>
                                <button onclick="applyBatchDiscount()">Apply</button>
                            </div>
                        </div>
                        
                        <div class="tool-group">
                            <div class="tool-title"><i class="fas fa-calculator"></i> Rounding Tools</div>
                            <div class="tool-row">
                                <select id="round-type">
                                    <option value="none">No Rounding</option>
                                    <option value="normal">Normal Round</option>
                                    <option value="up">Round Up</option>
                                    <option value="down">Round Down</option>
                                    <option value="nearest_10">Nearest 10</option>
                                    <option value="nearest_50">Nearest 50</option>
                                    <option value="nearest_100">Nearest 100</option>
                                </select>
                                <select id="round-target">
                                    <option value="discount">Round Discounts</option>
                                    <option value="total">Round Final Total</option>
                                </select>
                                <button onclick="applyRounding()">Apply</button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="totals-section">
                    <div class="total-row">
                        <span class="total-label">Subtotal:</span>
                        <span class="total-amount" id="subtotal-display">Rs<?php echo number_format($subtotal, 2); ?></span>
                    </div>
                    <div class="total-row">
                        <span class="total-label">Total Discount:</span>
                        <span class="total-amount" id="total-discount-display">-Rs<?php echo number_format($total_discount, 2); ?></span>
                    </div>
                    <div class="total-row final-total">
                        <span class="total-label">Total Amount:</span>
                        <span class="total-amount" id="total-display">Rs<?php echo number_format($total, 2); ?></span>
                    </div>
                </div>
                
                <div class="payment-section">
                    <form id="totals-form" method="post" onsubmit="return validateForm()">
                        <input type="hidden" name="bill_no" id="bill-no-hidden" value="<?php echo htmlspecialchars($bill_no); ?>">
                        
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Select Customer (Optional)</label>
                            <div class="customer-search-container">
                                <input type="text" class="customer-search-input" id="customer-search" placeholder="Search customer by name, phone or NIC..." autocomplete="off">
                                <div class="customer-dropdown" id="customer-dropdown"></div>
                            </div>
                            <div id="selected-customer-display"></div>
                            <input type="hidden" name="customer_id" id="customer-id">
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-user-tie"></i> Sales Officer Name</label>
                            <select name="cashier_id" id="cashier-id" required>
                                <option value="">Select Sales Officer</option>
                                <?php 
                                $cashiers_result->data_seek(0);
                                while ($cashier = $cashiers_result->fetch_assoc()): ?>
                                    <option value="<?php echo $cashier['cashier_id']; ?>"><?php echo htmlspecialchars($cashier['name']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-credit-card"></i> Payment Method</label>
                            <select name="payment_method" id="payment-method" onchange="toggleCustomerDetails()" required>
                                <option value="">Select Payment Method</option>
                                <option value="Cash">Cash</option>
                                <option value="Card">Card</option>
                                <option value="Credit">Credit</option>
                            </select>
                        </div>
                        
                        <div class="customer-details" id="customer-details" style="display: none;">
                            <div class="form-group">
                                <label><i class="fas fa-user"></i> Customer Name</label>
                                <input type="text" name="customer_name" id="customer-name" placeholder="Enter customer name">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-phone"></i> Phone Number</label>
                                <input type="text" name="phone_no" id="phone-no" placeholder="Enter phone number">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-id-card"></i> NIC Number</label>
                                <input type="text" name="nic_no" id="nic-no" placeholder="Enter NIC number">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-money-bill-wave"></i> Paid Amount</label>
                            <input type="number" name="paid_amount" value="0" step="0.01" min="0" id="paid-amount" oninput="updateBalance()" required>
                        </div>
                        
                        <div class="total-row">
                            <span class="total-label">Balance:</span>
                            <span class="total-amount" id="balance-display">Rs<?php echo number_format(0 - $total, 2); ?></span>
                        </div>
                        
                        <div class="action-buttons">
                            <button type="button" class="btn btn-secondary" onclick="printBill()">
                                <i class="fas fa-print"></i> Print
                            </button>
                            <button type="button" class="btn btn-hold" onclick="showHoldBillModal()">
                                <i class="fas fa-pause"></i> Hold Bill
                            </button>
                            <button type="submit" name="complete_sale" class="btn btn-primary">
                                <i class="fas fa-check-circle"></i> Complete
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Hold Bill Modal -->
    <div class="modal" id="hold-bill-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-pause-circle"></i> Hold Bill</h3>
                <button class="modal-close" onclick="closeHoldBillModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to hold this bill? The cart will be saved and cleared for the next bill.</p>
                <div class="form-group">
                    <label><i class="fas fa-sticky-note"></i> Notes (Optional)</label>
                    <textarea id="hold-note" placeholder="Add any notes about this held bill..." style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; resize: vertical;"></textarea>
                </div>
                <div class="held-bill-summary">
                    <p><strong>Bill No:</strong> <?php echo htmlspecialchars($bill_no); ?></p>
                    <p><strong>Items:</strong> <span id="hold-items-count"><?php echo count($_SESSION[$cart_session_key]); ?></span></p>
                    <p><strong>Total:</strong> Rs<span id="hold-total-amount"><?php echo number_format($total, 2); ?></span></p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeHoldBillModal()" style="flex: 1;">Cancel</button>
                <button class="btn btn-hold" onclick="holdBill()" style="flex: 1;">Hold Bill</button>
            </div>
        </div>
    </div>

    <div class="notification" id="notification">
        <i class="fas fa-check-circle"></i> Product added to cart!
    </div>

    <script>
        let currentProductPage = 1;
        let totalProductPages = 1;
        
        function updateClock() {
            const now = new Date();
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            document.getElementById('current-date').textContent = now.toLocaleDateString('en-US', options);
            let hours = now.getHours();
            let minutes = now.getMinutes();
            let seconds = now.getSeconds();
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12 || 12;
            minutes = minutes < 10 ? '0' + minutes : minutes;
            seconds = seconds < 10 ? '0' + seconds : seconds;
            const timeString = `${hours}:${minutes}:${seconds} ${ampm}`;
            document.getElementById('current-time').textContent = timeString;
            setTimeout(updateClock, 1000);
        }
        updateClock();
        
        // Load held bills on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadHeldBills();
            updateBalance();
        });
        
        function loadProductPage(page) {
            const searchTerm = document.getElementById('search-input').value.trim();
            const formData = new FormData();
            formData.append('action', 'search_products');
            formData.append('search', searchTerm);
            formData.append('page', page);
            
            fetch('billing.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                currentProductPage = data.current_page;
                totalProductPages = data.total_pages;
                document.getElementById('products-container').innerHTML = data.html;
                updatePaginationButtons();
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }
        
        function updatePaginationButtons() {
            const paginationDiv = document.getElementById('product-pagination');
            if (paginationDiv) {
                paginationDiv.innerHTML = '';
                
                if (currentProductPage > 1) {
                    paginationDiv.innerHTML += `<button class="page-btn" onclick="loadProductPage(${currentProductPage - 1})">&laquo; Previous</button>`;
                }
                
                paginationDiv.innerHTML += `<span class="page-info">Page ${currentProductPage} of ${totalProductPages}</span>`;
                
                if (currentProductPage < totalProductPages) {
                    paginationDiv.innerHTML += `<button class="page-btn" onclick="loadProductPage(${currentProductPage + 1})">Next &raquo;</button>`;
                }
            }
        }
        
        let searchTimeout;
        document.getElementById('search-input').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                loadProductPage(1);
            }, 300);
        });
        
        let customerSearchTimeout;
        function searchCustomers(searchTerm) {
            if (searchTerm.length < 2) {
                document.getElementById('customer-dropdown').style.display = 'none';
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'search_customers');
            formData.append('search', searchTerm);
            
            fetch('billing.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                const dropdown = document.getElementById('customer-dropdown');
                if (data.html) {
                    dropdown.innerHTML = data.html;
                    dropdown.style.display = 'block';
                } else {
                    dropdown.style.display = 'none';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('customer-dropdown').style.display = 'none';
            });
        }
        
        document.getElementById('customer-search').addEventListener('input', function() {
            clearTimeout(customerSearchTimeout);
            customerSearchTimeout = setTimeout(() => {
                searchCustomers(this.value.trim());
            }, 300);
        });
        
        function selectCustomer(customerId, name, phone, nic) {
            document.getElementById('customer-id').value = customerId;
            document.getElementById('customer-name').value = name;
            document.getElementById('phone-no').value = phone;
            document.getElementById('nic-no').value = nic;
            
            const displayDiv = document.getElementById('selected-customer-display');
            displayDiv.innerHTML = `
                <div class="selected-customer">
                    <div>
                        <strong><i class="fas fa-check-circle"></i> Selected Customer:</strong>
                        <div class="selected-customer-info">
                            ${name} (Phone: ${phone} | NIC: ${nic || 'N/A'})
                        </div>
                    </div>
                    <button type="button" onclick="removeSelectedCustomer()">
                        <i class="fas fa-times"></i> Remove
                    </button>
                </div>
            `;
            
            document.getElementById('customer-dropdown').style.display = 'none';
            document.getElementById('customer-search').value = '';
        }
        
        function removeSelectedCustomer() {
            document.getElementById('customer-id').value = '';
            document.getElementById('customer-name').value = '';
            document.getElementById('phone-no').value = '';
            document.getElementById('nic-no').value = '';
            
            document.getElementById('selected-customer-display').innerHTML = '';
        }
        
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('customer-dropdown');
            const searchInput = document.getElementById('customer-search');
            if (dropdown && !dropdown.contains(event.target) && !searchInput.contains(event.target)) {
                dropdown.style.display = 'none';
            }
        });
        
        function showIMEIPrompt(product_id, quantity, discount, discount_type) {
            const imei = prompt("Enter Customer Name:");
            if (imei !== null) {
                addToCart(product_id, quantity, discount, discount_type, imei);
            }
        }
        
        function addToCart(product_id, quantity, discount, discount_type, imei) {
            const formData = new FormData();
            formData.append('product_id', product_id);
            formData.append('quantity', quantity);
            formData.append('discount', discount);
            formData.append('discount_type', discount_type);
            formData.append('imei', imei);
            formData.append('add_to_cart', '1');
            
            fetch('billing.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message);
                    document.getElementById('cart-content').innerHTML = data.cart_html;
                    updateTotalsDisplay(data.totals);
                    document.getElementById('cart-count').textContent = `Items: ${data.cart_count}`;
                    
                    // Update hold modal values if open
                    updateHoldModalValues();
                    
                    // If cart was empty, remove empty state
                    const emptyCart = document.querySelector('.empty-cart');
                    if (emptyCart) {
                        emptyCart.remove();
                    }
                } else {
                    alert(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while adding to cart.');
            });
        }
        
        function updateCart(product_id, quantity, discount, discount_type, imei) {
            const formData = new FormData();
            formData.append('product_id', product_id);
            formData.append('quantity', quantity);
            formData.append('discount', discount);
            formData.append('discount_type', discount_type);
            formData.append('imei', imei);
            formData.append('update_cart', '1');
            
            fetch('billing.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('cart-content').innerHTML = data.cart_html;
                    updateTotalsDisplay(data.totals);
                    document.getElementById('cart-count').textContent = `Items: ${data.cart_count}`;
                    
                    // Update hold modal values if open
                    updateHoldModalValues();
                    
                    // If cart is empty, show empty state
                    if (data.cart_count === 0) {
                        document.getElementById('cart-content').innerHTML = `
                            <div class="empty-cart">
                                <i class="fas fa-shopping-cart"></i>
                                <h3>Your cart is empty</h3>
                                <p>Add products from the left panel to get started</p>
                            </div>
                        `;
                    }
                } else {
                    alert(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while updating cart.');
            });
        }
        
        function applyBatchDiscount() {
            const discount = document.getElementById('batch-discount').value;
            const discountType = document.getElementById('batch-discount-type').value;
            
            if (!discount || discount <= 0) {
                alert('Please enter a valid discount amount');
                return;
            }
            
            const formData = new FormData();
            formData.append('batch_discount', discount);
            formData.append('batch_discount_type', discountType);
            formData.append('apply_batch_discount', '1');
            
            fetch('billing.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message);
                    document.getElementById('cart-content').innerHTML = data.cart_html;
                    updateTotalsDisplay(data.totals);
                    
                    // Update hold modal values if open
                    updateHoldModalValues();
                } else {
                    alert(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while applying batch discount.');
            });
        }
        
        function applyRounding() {
            const roundType = document.getElementById('round-type').value;
            const roundTarget = document.getElementById('round-target').value;
            
            const formData = new FormData();
            formData.append('round_type', roundType);
            formData.append('round_target', roundTarget);
            formData.append('apply_rounding', '1');
            
            fetch('billing.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message);
                    document.getElementById('cart-content').innerHTML = data.cart_html;
                    updateTotalsDisplay(data.totals);
                    
                    // Update hold modal values if open
                    updateHoldModalValues();
                } else {
                    alert(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while applying rounding.');
            });
        }
        
        function updateTotalsDisplay(totals) {
            document.getElementById('subtotal-display').textContent = `Rs${totals.subtotal.toFixed(2)}`;
            document.getElementById('total-discount-display').textContent = `-Rs${totals.total_discount.toFixed(2)}`;
            document.getElementById('total-display').textContent = `Rs${totals.total.toFixed(2)}`;
            updateBalance();
        }
        
        function updateBalance() {
            const totalText = document.getElementById('total-display').textContent;
            const total = parseFloat(totalText.replace('Rs', '')) || 0;
            const paid = parseFloat(document.getElementById('paid-amount').value) || 0;
            const balance = paid - total;
            
            const balanceDisplay = document.getElementById('balance-display');
            balanceDisplay.textContent = `Rs${balance.toFixed(2)}`;
            
            if (balance < 0) {
                balanceDisplay.style.color = 'var(--accent)';
            } else {
                balanceDisplay.style.color = 'var(--success)';
            }
        }
        
        function toggleCustomerDetails() {
            const paymentMethod = document.getElementById('payment-method').value;
            const customerDetails = document.getElementById('customer-details');
            if (paymentMethod === 'Credit') {
                customerDetails.style.display = 'block';
            } else {
                customerDetails.style.display = 'none';
                if (!document.getElementById('customer-id').value) {
                    document.getElementById('customer-name').value = '';
                    document.getElementById('phone-no').value = '';
                    document.getElementById('nic-no').value = '';
                }
            }
        }
        
        function validateForm() {
            const cashierId = document.getElementById('cashier-id').value;
            const paymentMethod = document.getElementById('payment-method').value;
            const customerName = document.getElementById('customer-name').value;
            const phoneNo = document.getElementById('phone-no').value;
            const nicNo = document.getElementById('nic-no').value;
            const cartCount = parseInt(document.getElementById('cart-count').textContent.replace('Items: ', '')) || 0;
            
            let errors = [];
            
            if (cartCount === 0) {
                errors.push("Cart is empty. Please add products to complete sale.");
            }
            if (!cashierId) {
                errors.push("Please select a cashier.");
            }
            if (!paymentMethod) {
                errors.push("Please select a payment method.");
            }
            if (paymentMethod === 'Credit' && (!customerName || !phoneNo || !nicNo)) {
                errors.push("Customer Name, Phone Number, and NIC Number are required for Credit payment.");
            }
            
            if (errors.length > 0) {
                alert(errors.join("\n"));
                return false;
            }
            return true;
        }
        
        function printBill() {
            const cashierId = document.getElementById('cashier-id').value;
            const paymentMethod = document.getElementById('payment-method').value;
            const paidAmount = document.getElementById('paid-amount').value;

            if (!cashierId) {
                alert("Please select a cashier before printing the bill.");
                return;
            }
            if (!paymentMethod) {
                alert("Please select a payment method before printing the bill.");
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'print_bill.php';
            form.target = '_blank';

            const billNo = document.getElementById('bill-no-hidden').value;
            const customerId = document.getElementById('customer-id').value;

            const inputs = [
                {name: 'bill_no', value: billNo},
                {name: 'cashier_id', value: cashierId},
                {name: 'payment_method', value: paymentMethod},
                {name: 'paid_amount', value: paidAmount},
                {name: 'customer_id', value: customerId}
            ];

            inputs.forEach(input => {
                const el = document.createElement('input');
                el.type = 'hidden';
                el.name = input.name;
                el.value = input.value;
                form.appendChild(el);
            });

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }
        
        // Hold Bill Functions
        function showHoldBillModal() {
            const cartCount = parseInt(document.getElementById('cart-count').textContent.replace('Items: ', '')) || 0;
            
            if (cartCount === 0) {
                alert('Cart is empty! Add products before holding a bill.');
                return;
            }
            
            updateHoldModalValues();
            document.getElementById('hold-bill-modal').classList.add('active');
        }
        
        function closeHoldBillModal() {
            document.getElementById('hold-bill-modal').classList.remove('active');
            document.getElementById('hold-note').value = '';
        }
        
        function updateHoldModalValues() {
            const cartCount = parseInt(document.getElementById('cart-count').textContent.replace('Items: ', '')) || 0;
            const totalText = document.getElementById('total-display').textContent;
            const total = parseFloat(totalText.replace('Rs', '')) || 0;
            
            document.getElementById('hold-items-count').textContent = cartCount;
            document.getElementById('hold-total-amount').textContent = total.toFixed(2);
        }
        
        function holdBill() {
            const holdNote = document.getElementById('hold-note').value;
            const cashierId = document.getElementById('cashier-id').value;
            const customerId = document.getElementById('customer-id').value;
            const customerName = document.getElementById('customer-name').value;
            const phoneNo = document.getElementById('phone-no').value;
            const nicNo = document.getElementById('nic-no').value;
            
            const formData = new FormData();
            formData.append('hold_bill', '1');
            formData.append('hold_note', holdNote);
            formData.append('cashier_id', cashierId);
            formData.append('customer_id', customerId);
            formData.append('customer_name', customerName);
            formData.append('phone_no', phoneNo);
            formData.append('nic_no', nicNo);
            
            fetch('billing.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message);
                    closeHoldBillModal();
                    
                    // Clear cart UI
                    document.getElementById('cart-content').innerHTML = `
                        <div class="empty-cart">
                            <i class="fas fa-shopping-cart"></i>
                            <h3>Your cart is empty</h3>
                            <p>Add products from the left panel to get started</p>
                        </div>
                    `;
                    document.getElementById('cart-count').textContent = 'Items: 0';
                    
                    // Reset totals
                    document.getElementById('subtotal-display').textContent = 'Rs0.00';
                    document.getElementById('total-discount-display').textContent = '-Rs0.00';
                    document.getElementById('total-display').textContent = 'Rs0.00';
                    updateBalance();
                    
                    // Generate new bill number (reload page to get new number)
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    alert(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while holding the bill.');
            });
        }
        
        function loadHeldBills() {
            const formData = new FormData();
            formData.append('action', 'get_held_bills');
            
            fetch('billing.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('held-bills-container').innerHTML = data.html;
                
                // Update count
                const count = document.querySelectorAll('.held-bill-item').length;
                document.getElementById('held-bills-count').textContent = count;
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }
        
        function retrieveHeldBill(holdId) {
            if (!confirm('Are you sure you want to retrieve this held bill? Current cart will be replaced.')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('retrieve_held_bill', '1');
            formData.append('hold_id', holdId);
            
            fetch('billing.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message);
                    
                    // Update cart UI
                    document.getElementById('cart-content').innerHTML = data.cart_html;
                    updateTotalsDisplay(data.totals);
                    document.getElementById('cart-count').textContent = `Items: ${data.cart_count}`;
                    
                    // Update bill number
                    document.getElementById('bill-no-display').textContent = `Bill: ${data.bill_no}`;
                    document.getElementById('bill-no-hidden').value = data.bill_no;
                    
                    // Update customer info if exists
                    if (data.customer_id) {
                        selectCustomer(data.customer_id, data.customer_name, data.phone_no, data.nic_no);
                    }
                    
                    // Update cashier if exists
                    if (data.cashier_id) {
                        document.getElementById('cashier-id').value = data.cashier_id;
                    }
                    
                    // Reload held bills list
                    loadHeldBills();
                    
                    // Update hold modal values
                    updateHoldModalValues();
                } else {
                    alert(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while retrieving the bill.');
            });
        }
        
        function deleteHeldBill(holdId) {
            if (!confirm('Are you sure you want to delete this held bill? This action cannot be undone.')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('delete_held_bill', '1');
            formData.append('hold_id', holdId);
            
            fetch('billing.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message);
                    loadHeldBills();
                } else {
                    alert(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while deleting the bill.');
            });
        }
        
        function showNotification(message) {
            const notification = document.getElementById('notification');
            if (notification) {
                notification.innerHTML = `<i class="fas fa-check-circle"></i> ${message}`;
                notification.style.display = 'block';
                setTimeout(() => {
                    notification.style.display = 'none';
                }, 3000);
            }
        }
        
        // Auto-refresh held bills every 30 seconds
        setInterval(loadHeldBills, 30000);
    </script>
</body>
</html>
<?php
$conn->close();
?>