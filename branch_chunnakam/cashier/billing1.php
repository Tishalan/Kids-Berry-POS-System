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
    $prefix = "PS-B2" . $branch_id . "-";
    $sql = "SELECT MAX(bill_no) as max_bill FROM bills2 WHERE bill_no LIKE '$prefix%'";
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    $max_bill = $row['max_bill'];
    if ($max_bill && preg_match('/^PS-B2' . $branch_id . '-(\d+)$/', $max_bill, $matches)) {
        $numeric_part = (int)$matches[1] + 1;
        return $prefix . str_pad($numeric_part, 4, "0", STR_PAD_LEFT);
    }
    return $prefix . "0001";
}

// Initialize bill hold system
$cart_session_key = 'cart_branch_' . $branch_id;
$hold_session_key = 'held_bills_' . $branch_id;

if (!isset($_SESSION[$cart_session_key])) {
    $_SESSION[$cart_session_key] = [];
}

if (!isset($_SESSION[$hold_session_key])) {
    $_SESSION[$hold_session_key] = [];
}

// Generate bill number for new bill
$bill_no = generateBillNo($conn, $branch_id);

$cashiers_sql = "SELECT cashier_id, name FROM cashier_users2 WHERE status = 'active' ORDER BY name";
$cashiers_result = $conn->query($cashiers_sql);

$customers_sql = "SELECT customer_id, name, phone_no, nic_no FROM customers2 ORDER BY name";
$customers_result = $conn->query($customers_sql);

// Handle AJAX requests
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action == 'search_customers') {
        $search = isset($_POST['search']) ? mysqli_real_escape_string($conn, $_POST['search']) : '';
        $customers_sql_ajax = "SELECT customer_id, name, phone_no, nic_no FROM customers2 WHERE 1=1";
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
    
    if ($action == 'search_products') {
        $search = isset($_POST['search']) ? mysqli_real_escape_string($conn, $_POST['search']) : '';
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $limit = 12;
        $offset = ($page - 1) * $limit;
        
        $products_sql = "SELECT * FROM products2 WHERE stock > 0";
        $count_sql = "SELECT COUNT(*) as total FROM products2 WHERE stock > 0";
        
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
            $html .= '<div class="product-name">' . htmlspecialchars($product['barcode']) . '</div>';
            $html .= '</div>';
        }
        
        if ($total_pages > 1) {
            $html .= '<div class="pagination" id="product-pagination">';
            if ($page > 1) {
                $html .= '<button class="page-btn" onclick="loadProductPage(' . ($page - 1) . ')"><i class="fas fa-chevron-left"></i> Previous</button>';
            }
            $html .= '<span class="page-info">Page ' . $page . ' of ' . $total_pages . '</span>';
            if ($page < $total_pages) {
                $html .= '<button class="page-btn" onclick="loadProductPage(' . ($page + 1) . ')">Next <i class="fas fa-chevron-right"></i></button>';
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
    
    if ($action == 'hold_bill') {
        $hold_name = isset($_POST['hold_name']) ? mysqli_real_escape_string($conn, $_POST['hold_name']) : 'Unnamed Bill';
        $hold_id = 'HOLD_' . uniqid() . '_' . time();
        
        $_SESSION[$hold_session_key][$hold_id] = [
            'id' => $hold_id,
            'name' => $hold_name,
            'cart' => $_SESSION[$cart_session_key],
            'created_at' => date('Y-m-d H:i:s'),
            'bill_no' => $bill_no
        ];
        
        $_SESSION[$cart_session_key] = [];
        $bill_no = generateBillNo($conn, $branch_id);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Bill held successfully',
            'bill_no' => $bill_no,
            'held_bills' => array_values($_SESSION[$hold_session_key])
        ]);
        exit();
    }
    
    if ($action == 'load_held_bill') {
        $hold_id = isset($_POST['hold_id']) ? mysqli_real_escape_string($conn, $_POST['hold_id']) : '';
        
        if (isset($_SESSION[$hold_session_key][$hold_id])) {
            $held_bill = $_SESSION[$hold_session_key][$hold_id];
            $_SESSION[$cart_session_key] = $held_bill['cart'];
            $bill_no = generateBillNo($conn, $branch_id);
            unset($_SESSION[$hold_session_key][$hold_id]);
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Bill loaded successfully',
                'bill_no' => $bill_no,
                'held_bills' => array_values($_SESSION[$hold_session_key])
            ]);
            exit();
        } else {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Bill not found'
            ]);
            exit();
        }
    }
    
    if ($action == 'cancel_held_bill') {
        $hold_id = isset($_POST['hold_id']) ? mysqli_real_escape_string($conn, $_POST['hold_id']) : '';
        
        if (isset($_SESSION[$hold_session_key][$hold_id])) {
            unset($_SESSION[$hold_session_key][$hold_id]);
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Held bill cancelled',
                'held_bills' => array_values($_SESSION[$hold_session_key])
            ]);
            exit();
        } else {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Bill not found'
            ]);
            exit();
        }
    }
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

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_to_cart'])) {
    $product_id = mysqli_real_escape_string($conn, $_POST['product_id']);
    $quantity = intval($_POST['quantity']);
    $discount = floatval($_POST['discount']);
    $discount_type = mysqli_real_escape_string($conn, $_POST['discount_type']);
    $imei = mysqli_real_escape_string($conn, $_POST['imei']);
    
    $product_sql = "SELECT * FROM products2 WHERE product_id = '$product_id'";
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
    
    $product_sql = "SELECT stock, sale_price FROM products2 WHERE product_id = '$product_id'";
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
        $bill_sql = "INSERT INTO bills2
                     (bill_no, branch_id, cashier_id, date, payment_method, subtotal, total_discount, total, paid_amount, balance, customer_name, phone_no, nic_no, customer_id)
                     VALUES 
                     ('$bill_no', $branch_id, '$cashier_id', NOW(), '$payment_method', $subtotal, $total_discount, $total, $paid_amount, $balance, " .
                     ($customer_name ? "'$customer_name'" : "NULL") . ", " .
                     ($phone_no ? "'$phone_no'" : "NULL") . ", " .
                     ($nic_no ? "'$nic_no'" : "NULL") . ", " .
                     ($customer_id ? $customer_id : "NULL") . ")";

        if ($conn->query($bill_sql)) {
            $cart_snapshot = $_SESSION[$cart_session_key];

            foreach ($cart_snapshot as $product_id => $item) {
                $product_sql = "SELECT name, color, original_price FROM products2 WHERE product_id = '$product_id'";
                $product_result = $conn->query($product_sql);
                $product_details = $product_result->fetch_assoc();

                $product_name = $product_details['name'] ?? $item['name'];
                $product_color = $product_details['color'] ?? ($item['color'] ?? '');
                $original_price = $product_details['original_price'] ?? $item['price'];

                $product_name_esc = mysqli_real_escape_string($conn, $product_name);
                $product_color_esc = mysqli_real_escape_string($conn, $product_color);
                $imei_esc = mysqli_real_escape_string($conn, $item['imei'] ?? '');
                $discount_type_esc = mysqli_real_escape_string($conn, $item['discount_type'] ?? 'rs');

                $item_sql = "INSERT INTO bill_items2 
                             (bill_no, cashier_id, product_id, quantity, price, discount, subtotal, imei, product_name, product_color, original_price, discount_type, discount_input)
                             VALUES 
                             ('$bill_no', '$cashier_id', '$product_id', {$item['quantity']}, {$item['price']}, {$item['discount']}, {$item['subtotal']}, '$imei_esc', '$product_name_esc', '$product_color_esc', $original_price, '$discount_type_esc', {$item['discount_input']})";

                $conn->query($item_sql);

                $update_stock_sql = "UPDATE products2 
                                     SET stock = stock - {$item['quantity']}, 
                                         sold = sold + {$item['quantity']}, 
                                         last_sold = NOW()
                                     WHERE product_id = '$product_id'";
                $conn->query($update_stock_sql);
            }

            $first_item = reset($cart_snapshot);
            $summary_product_name = $first_item ? mysqli_real_escape_string($conn, $first_item['name']) : null;
            $summary_imei = $first_item ? mysqli_real_escape_string($conn, $first_item['imei'] ?? '') : null;
            $summary_quantity = $first_item ? $first_item['quantity'] : null;

            $bill_summary_sql = "INSERT INTO bill_summary2
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

            $_SESSION[$cart_session_key] = [];
            $bill_no = generateBillNo($conn, $branch_id);

            echo "<script>alert('Sale completed successfully!'); window.location.href='billing1.php';</script>";
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

$bills_sql = "SELECT bill_no, date, payment_method, paid_amount, total, subtotal, total_discount FROM bills2 WHERE DATE(date) = '$current_date' ORDER BY date ASC";
$bills_result = $conn->query($bills_sql);

while ($bill = $bills_result->fetch_assoc()) {
    $bill_no_temp = $bill['bill_no'];
    $payment_method = $bill['payment_method'];
    $paid_amount = floatval($bill['paid_amount']);
    $total_bill = floatval($bill['total']);
    $subtotal_bill = floatval($bill['subtotal']);
    $discount_bill = floatval($bill['total_discount']);

    $total_sales += $total_bill;
    
    $items_sql = "SELECT bi.*, p.original_price FROM bill_items2 bi JOIN products2 p ON bi.product_id = p.product_id WHERE bi.bill_no = '$bill_no_temp'";
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
                        <div class="cart-row">
                            <div class="quantity-controls">
                                <button class="minus" onclick="updateCart(\'' . htmlspecialchars($product_id) . '\', ' . ($item['quantity'] - 1) . ', ' . $item['discount_input'] . ', \'' . $item['discount_type'] . '\', \'' . htmlspecialchars($item['imei']) . '\')">-</button>
                                <span>' . $item['quantity'] . '</span>
                                <button class="plus" onclick="updateCart(\'' . htmlspecialchars($product_id) . '\', ' . ($item['quantity'] + 1) . ', ' . $item['discount_input'] . ', \'' . $item['discount_type'] . '\', \'' . htmlspecialchars($item['imei']) . '\')">+</button>
                            </div>
                            <span class="item-price">Rs' . number_format($item['price'], 2) . '</span>
                        </div>
                        <div class="cart-row">
                            <div class="discount-line">
                                <input type="number" step="0.01" min="0" value="' . number_format($item['discount_input'], 2) . '" onchange="updateCart(\'' . htmlspecialchars($product_id) . '\', ' . $item['quantity'] . ', parseFloat(this.value), \'' . $item['discount_type'] . '\', \'' . htmlspecialchars($item['imei']) . '\')">
                                <select onchange="updateCart(\'' . htmlspecialchars($product_id) . '\', ' . $item['quantity'] . ', ' . $item['discount_input'] . ', this.value, \'' . htmlspecialchars($item['imei']) . '\')">
                                    <option value="rs" ' . ($item['discount_type'] === 'rs' ? 'selected' : '') . '>Rs</option>
                                    <option value="percentage" ' . ($item['discount_type'] === 'percentage' ? 'selected' : '') . '>%</option>
                                </select>
                            </div>
                            <span class="item-subtotal">Rs' . number_format($item['subtotal'], 2) . '</span>
                        </div>
                        <div class="cart-row imei-row">
                            <input type="text" class="imei-input" value="' . htmlspecialchars($item['imei']) . '" placeholder="Customer Name" onchange="updateCart(\'' . htmlspecialchars($product_id) . '\', ' . $item['quantity'] . ', ' . $item['discount_input'] . ', \'' . $item['discount_type'] . '\', this.value)">
                        </div>
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
$products_sql = "SELECT * FROM products2 WHERE stock > 0 LIMIT $products_limit OFFSET $products_offset";
$products_result = $conn->query($products_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>KIDS Berry - Mega Centre</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1a472a;
            --primary-dark: #0e2a1a;
            --primary-light: #2c5e3c;
            --secondary: #2c5e3c;
            --secondary-dark: #1e3e2a;
            --secondary-light: #3e7e52;
            --accent: #d4a373;
            --accent-dark: #bc8f5a;
            --accent-light: #e9c46a;
            --success: #2e7d32;
            --warning: #f39c12;
            --danger: #e74c3c;
            --info: #3498db;
            --light: #f8f9fa;
            --dark: #2c3e2f;
            --gray: #6c757d;
            --white: #ffffff;
            --card-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --card-shadow-hover: 0 8px 24px rgba(0, 0, 0, 0.12);
            --transition: all 0.3s ease;
            --radius: 12px;
            --radius-sm: 8px;
            --radius-lg: 20px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #e8f0e8 0%, #d4e2d4 100%);
            color: var(--dark);
            min-height: 100vh;
            line-height: 1.5;
        }

        /* Header */
        .header {
            background: var(--primary-dark);
            color: white;
            padding: 16px 24px;
            box-shadow: var(--card-shadow);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .logo-container {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .logo-container img {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            border: 2px solid var(--accent);
            object-fit: cover;
        }

        .brand-info h1 {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 2px;
        }

        .header-tagline {
            font-size: 12px;
            opacity: 0.8;
        }

        .date-time {
            background: rgba(255,255,255,0.1);
            padding: 8px 16px;
            border-radius: var(--radius);
            text-align: center;
        }

        .current-date {
            font-size: 14px;
            font-weight: 600;
        }

        .current-time {
            font-size: 12px;
            opacity: 0.9;
        }

        .logout-btn {
            background: rgba(255,255,255,0.15);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 50px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .logout-btn:hover {
            background: rgba(255,255,255,0.25);
            transform: translateY(-2px);
        }

        /* Navigation */
        .nav-container {
            background: white;
            padding: 12px 24px;
            box-shadow: var(--card-shadow);
            position: sticky;
            top: 82px;
            z-index: 99;
        }

        .nav {
            display: flex;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
            max-width: 1400px;
            margin: 0 auto;
        }

        .nav a {
            text-decoration: none;
            color: var(--dark);
            font-weight: 600;
            padding: 10px 20px;
            border-radius: 50px;
            background: var(--light);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
        }

        .nav a:hover {
            background: var(--primary);
            color: white;
        }

        .nav a.active {
            background: var(--primary);
            color: white;
        }

        /* Main Layout */
        .main-container {
            display: flex;
            gap: 20px;
            padding: 20px;
            max-width: 1600px;
            margin: 0 auto;
        }

        .left-panel {
            flex: 3;
            min-width: 0;
        }

        .right-panel {
            flex: 2;
            min-width: 0;
            max-width: 420px;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--card-shadow);
            overflow: hidden;
            margin-bottom: 20px;
        }

        .card-header {
            padding: 16px 20px;
            background: var(--primary);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .card-title {
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .bill-no {
            background: rgba(255,255,255,0.2);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }

        /* Products Grid */
        .search-container {
            padding: 16px;
            border-bottom: 1px solid #e0e0e0;
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 12px 16px;
            padding-left: 40px;
            border: 1px solid #ddd;
            border-radius: var(--radius);
            font-size: 14px;
            transition: var(--transition);
        }

        .search-input:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(26, 71, 42, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 28px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 15px;
            padding: 16px;
            max-height: 480px;
            overflow-y: auto;
        }

        .product-item {
            background: var(--light);
            border-radius: var(--radius);
            padding: 12px;
            cursor: pointer;
            transition: var(--transition);
            text-align: center;
            border: 1px solid #e0e0e0;
        }

        .product-item:hover {
            transform: translateY(-4px);
            box-shadow: var(--card-shadow-hover);
            border-color: var(--primary);
        }

        .product-item img {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: var(--radius-sm);
            margin-bottom: 8px;
        }

        .product-name {
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .product-price {
            color: var(--primary);
            font-weight: 700;
            font-size: 14px;
        }

        .product-stock {
            font-size: 11px;
            color: var(--gray);
        }

        /* Cart Section */
        .cart-header {
            background: var(--primary);
            color: white;
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .cart-count {
            background: var(--accent);
            color: var(--dark);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .cart-content {
            padding: 12px;
            max-height: 280px;
            overflow-y: auto;
            background: var(--light);
        }

        .cart-item {
            background: white;
            border-radius: var(--radius-sm);
            padding: 10px;
            margin-bottom: 10px;
            border: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            gap: 8px;
        }

        .cart-item-content {
            flex: 1;
        }

        .item-name {
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 6px;
        }

        .cart-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
            gap: 8px;
            flex-wrap: wrap;
        }

        .quantity-controls {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .quantity-controls button {
            background: var(--primary);
            color: white;
            border: none;
            width: 24px;
            height: 24px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }

        .quantity-controls span {
            font-weight: 600;
            min-width: 25px;
            text-align: center;
        }

        .item-price, .item-subtotal {
            font-weight: 600;
            font-size: 12px;
        }

        .discount-line {
            display: flex;
            gap: 4px;
            align-items: center;
        }

        .discount-line input {
            width: 55px;
            padding: 4px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 11px;
        }

        .discount-line select {
            padding: 4px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 11px;
        }

        .imei-input {
            width: 100%;
            padding: 5px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 11px;
        }

        .trash {
            background: var(--danger);
            color: white;
            border: none;
            width: 28px;
            height: 28px;
            border-radius: 4px;
            cursor: pointer;
            flex-shrink: 0;
        }

        .empty-cart {
            text-align: center;
            padding: 30px 20px;
            color: var(--gray);
        }

        /* Tools Section - Fixed Layout */
        .tools-section {
            padding: 16px;
            background: white;
            border-bottom: 1px solid #e0e0e0;
        }

        .tools-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .tool-group {
            background: var(--light);
            padding: 12px;
            border-radius: var(--radius-sm);
            border: 1px solid #e0e0e0;
        }

        .tool-title {
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--primary);
        }

        .tool-row {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .tool-row input, .tool-row select {
            flex: 1;
            padding: 8px 10px;
            border: 1px solid #ddd;
            border-radius: var(--radius-sm);
            font-size: 12px;
            background: white;
        }

        .tool-row button {
            background: var(--primary);
            color: white;
            border: none;
            padding: 8px 14px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: var(--transition);
        }

        .tool-row button:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        /* Totals Section */
        .totals-section {
            padding: 16px;
            background: white;
            border-bottom: 1px solid #e0e0e0;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            margin: 8px 0;
            font-size: 14px;
        }

        .total-label {
            font-weight: 600;
        }

        .total-amount {
            font-weight: 700;
        }

        .final-total {
            font-size: 18px;
            color: var(--primary);
            border-top: 2px solid var(--primary);
            padding-top: 10px;
            margin-top: 8px;
        }

        /* Payment Section */
        .payment-section {
            padding: 16px;
            background: white;
        }

        .form-group {
            margin-bottom: 14px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 12px;
            margin-bottom: 5px;
            color: var(--dark);
        }

        .form-group select, .form-group input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: var(--radius-sm);
            font-size: 13px;
            transition: var(--transition);
        }

        .form-group select:focus, .form-group input:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(26, 71, 42, 0.1);
        }

        .customer-search-container {
            position: relative;
        }

        .customer-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: var(--radius-sm);
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: var(--card-shadow);
        }

        .customer-option {
            padding: 10px 12px;
            cursor: pointer;
            border-bottom: 1px solid #eee;
            transition: var(--transition);
        }

        .customer-option:hover {
            background: var(--light);
        }

        .selected-customer {
            background: #e8f5e9;
            padding: 10px 12px;
            border-radius: var(--radius-sm);
            margin-top: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .selected-customer button {
            background: var(--danger);
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 11px;
            transition: var(--transition);
        }

        .selected-customer button:hover {
            background: #c0392b;
        }

        .customer-details {
            margin-top: 12px;
            padding: 12px;
            background: var(--light);
            border-radius: var(--radius-sm);
            border: 1px solid #e0e0e0;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }

        .btn {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: var(--radius-sm);
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 14px;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-secondary {
            background: var(--accent);
            color: var(--dark);
        }

        .btn-primary:hover, .btn-secondary:hover {
            transform: translateY(-2px);
            filter: brightness(1.05);
            box-shadow: var(--card-shadow);
        }

        /* Held Bills Section */
        .held-bills-section {
            padding: 16px;
            border-top: 1px solid #e0e0e0;
            background: white;
        }

        .held-bills-header {
            font-weight: 600;
            margin-bottom: 12px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--primary);
        }

        .held-bills-list {
            max-height: 180px;
            overflow-y: auto;
        }

        .held-bill-item {
            background: var(--light);
            border-radius: var(--radius-sm);
            padding: 10px 12px;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            border: 1px solid #e0e0e0;
        }

        .held-bill-info {
            flex: 1;
        }

        .held-bill-name {
            font-weight: 600;
            font-size: 13px;
        }

        .held-bill-time {
            font-size: 10px;
            color: var(--gray);
            margin-top: 2px;
        }

        .held-bill-actions {
            display: flex;
            gap: 6px;
        }

        .held-bill-actions button {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            padding: 6px 8px;
            border-radius: 4px;
            transition: var(--transition);
        }

        .load-held-btn {
            color: var(--primary);
        }

        .load-held-btn:hover {
            background: var(--primary);
            color: white;
        }

        .cancel-held-btn {
            color: var(--danger);
        }

        .cancel-held-btn:hover {
            background: var(--danger);
            color: white;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: var(--radius);
            padding: 24px;
            max-width: 400px;
            width: 90%;
            box-shadow: var(--card-shadow-hover);
        }

        .modal-content h3 {
            margin-bottom: 16px;
            color: var(--primary);
        }

        .modal-content input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
            font-size: 14px;
        }

        .modal-buttons {
            display: flex;
            gap: 12px;
        }

        .modal-buttons button {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
        }

        /* Notification */
        .notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--primary);
            color: white;
            padding: 12px 20px;
            border-radius: var(--radius);
            display: none;
            z-index: 1000;
            font-size: 13px;
            box-shadow: var(--card-shadow);
            align-items: center;
            gap: 8px;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 15px;
            padding: 16px;
            border-top: 1px solid #e0e0e0;
            background: white;
        }

        .page-btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: var(--transition);
        }

        .page-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .page-info {
            font-size: 13px;
            font-weight: 600;
            padding: 8px 0;
        }

        /* Responsive */
        @media (max-width: 992px) {
            .main-container {
                flex-direction: column;
            }
            .right-panel {
                max-width: 100%;
            }
            .tools-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                text-align: center;
            }
            .nav {
                flex-direction: column;
            }
            .nav a {
                justify-content: center;
            }
            .products-grid {
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            }
            .action-buttons {
                flex-direction: column;
            }
        }

        @media (max-width: 480px) {
            .products-grid {
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            }
            .product-item img {
                width: 60px;
                height: 60px;
            }
        }
    </style>
</head>
<body>

<div class="header">
    <div class="header-content">
        <div class="logo-container">
            <img src="../logo.jpg" alt="Kids Berry Logo">
            <div class="brand-info">
                <h1><i class="fas fa-baby"></i> KIDS Berry Mega Centre</h1>
                <div class="header-tagline"></div>
            </div>
        </div>
        
        <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
            <div class="date-time">
                <div class="current-date"><?php echo date('d M Y'); ?></div>
                <div class="current-time"><?php echo date('h:i A'); ?></div>
            </div>
            <form method="post">
                <button type="submit" name="logout" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </form>
        </div>
    </div>
</div>

<div class="nav-container">
    <div class="nav">
        <a href="billing1.php" class="active"><i class="fas fa-cash-register"></i> Billing</a>
        <a href="customer1.php"><i class="fas fa-users"></i> Customers</a>
        <a href="bill_management1.php"><i class="fas fa-history"></i> Bill History</a>
        <a href="report1.php"><i class="fas fa-chart-line"></i> Reports</a>
        <a href="return_sale1.php"><i class="fas fa-undo-alt"></i> Manage Returns</a>
        <!--<a href="prediction_dashboard1.php"><i class="fas fa-bullseye"></i> Predictions</a>-->
    </div>
</div>

<div class="main-container">
    <div class="left-panel">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-boxes"></i> Products</h2>
                <div class="bill-no"><i class="fas fa-receipt"></i> Bill: <?php echo htmlspecialchars($bill_no); ?></div>
            </div>
            
            <div class="search-container" style="position: relative;">
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
                            <div class="product-name"><?php echo htmlspecialchars($product['barcode']); ?></div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="grid-column: 1/-1; text-align: center; padding: 40px;">No products available</div>
                <?php endif; ?>
            </div>
            
            <div class="pagination" id="product-pagination">
                <button class="page-btn" onclick="loadProductPage(1)" id="prev-page"><i class="fas fa-chevron-left"></i> Previous</button>
                <span class="page-info">Page 1</span>
                <button class="page-btn" onclick="loadProductPage(2)" id="next-page">Next <i class="fas fa-chevron-right"></i></button>
            </div>
        </div>
    </div>

    <div class="right-panel">
        <div class="card">
            <div class="cart-header">
                <div><i class="fas fa-shopping-cart"></i> Shopping Cart</div>
                <div class="cart-count"><i class="fas fa-shopping-bag"></i> <?php echo count($_SESSION[$cart_session_key]); ?></div>
            </div>
            
            <div class="cart-content" id="cart-content">
                <?php if (!empty($_SESSION[$cart_session_key])): ?>
                    <?php foreach ($_SESSION[$cart_session_key] as $product_id => $item): ?>
                        <div class="cart-item" id="cart-item-<?php echo htmlspecialchars($product_id); ?>">
                            <div class="cart-item-content">
                                <div class="item-name"><?php echo htmlspecialchars($item['name']) . ' (' . htmlspecialchars($item['color']) . ')'; ?></div>
                                <div class="cart-row">
                                    <div class="quantity-controls">
                                        <button class="minus" onclick="updateCart('<?php echo htmlspecialchars($product_id); ?>', <?php echo $item['quantity'] - 1; ?>, <?php echo $item['discount_input']; ?>, '<?php echo $item['discount_type']; ?>', '<?php echo htmlspecialchars($item['imei']); ?>')">-</button>
                                        <span><?php echo $item['quantity']; ?></span>
                                        <button class="plus" onclick="updateCart('<?php echo htmlspecialchars($product_id); ?>', <?php echo $item['quantity'] + 1; ?>, <?php echo $item['discount_input']; ?>, '<?php echo $item['discount_type']; ?>', '<?php echo htmlspecialchars($item['imei']); ?>')">+</button>
                                    </div>
                                    <span class="item-price">Rs<?php echo number_format($item['price'], 2); ?></span>
                                </div>
                                <div class="cart-row">
                                    <div class="discount-line">
                                        <input type="number" step="0.01" min="0" value="<?php echo number_format($item['discount_input'], 2); ?>" onchange="updateCart('<?php echo htmlspecialchars($product_id); ?>', <?php echo $item['quantity']; ?>, parseFloat(this.value), '<?php echo $item['discount_type']; ?>', '<?php echo htmlspecialchars($item['imei']); ?>')">
                                        <select onchange="updateCart('<?php echo htmlspecialchars($product_id); ?>', <?php echo $item['quantity']; ?>, <?php echo $item['discount_input']; ?>, this.value, '<?php echo htmlspecialchars($item['imei']); ?>')">
                                            <option value="rs" <?php echo $item['discount_type'] === 'rs' ? 'selected' : ''; ?>>Rs</option>
                                            <option value="percentage" <?php echo $item['discount_type'] === 'percentage' ? 'selected' : ''; ?>>%</option>
                                        </select>
                                    </div>
                                    <span class="item-subtotal">Rs<?php echo number_format($item['subtotal'], 2); ?></span>
                                </div>
                                <div class="cart-row imei-row">
                                    <input type="text" class="imei-input" value="<?php echo htmlspecialchars($item['imei']); ?>" placeholder="Customer Name" onchange="updateCart('<?php echo htmlspecialchars($product_id); ?>', <?php echo $item['quantity']; ?>, <?php echo $item['discount_input']; ?>, '<?php echo $item['discount_type']; ?>', this.value)">
                                </div>
                            </div>
                            <button class="trash" onclick="updateCart('<?php echo htmlspecialchars($product_id); ?>', 0, 0, 'rs', '')"><i class="fas fa-trash"></i></button>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-cart">
                        <i class="fas fa-shopping-cart" style="font-size: 40px; margin-bottom: 10px;"></i>
                        <h3>Empty Cart</h3>
                        <p>Add products to start billing</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Tools Section - Batch Discount and Rounding -->
            <div class="tools-section">
                <div class="tools-grid">
                    <div class="tool-group">
                        <div class="tool-title"><i class="fas fa-tags"></i> Batch Discount</div>
                        <div class="tool-row">
                            <input type="number" step="0.01" min="0" id="batch-discount" placeholder="Amount">
                            <select id="batch-discount-type">
                                <option value="rs">Rs</option>
                                <option value="percentage">%</option>
                            </select>
                            <button onclick="applyBatchDiscount()"><i class="fas fa-check"></i> Apply</button>
                        </div>
                    </div>
                    <Br>
                    
                    <div class="tool-group">
                        <div class="tool-title"><i class="fas fa-calculator"></i> Rounding</div>
                        <div class="tool-row">
                            <select id="round-type">
                                <option value="none">None</option>
                                <option value="normal">Normal</option>
                                <option value="up">Up</option>
                                <option value="down">Down</option>
                            </select>
                            <select id="round-target">
                                <option value="discount">Discount</option>
                                <option value="total">Total</option>
                            </select>
                            <button onclick="applyRounding()"><i class="fas fa-check"></i> Apply</button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Totals Section -->
            <div class="totals-section">
                <div class="total-row">
                    <span class="total-label">Subtotal:</span>
                    <span class="total-amount" id="subtotal-display">Rs<?php echo number_format($subtotal, 2); ?></span>
                </div>
                <div class="total-row">
                    <span class="total-label">Discount:</span>
                    <span class="total-amount" id="total-discount-display">-Rs<?php echo number_format($total_discount, 2); ?></span>
                </div>
                <div class="total-row final-total">
                    <span class="total-label">Total:</span>
                    <span class="total-amount" id="total-display">Rs<?php echo number_format($total, 2); ?></span>
                </div>
            </div>
            
            <!-- Payment Section -->
            <div class="payment-section">
                <form id="totals-form" method="post" onsubmit="return validateForm()">
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Customer</label>
                        <div class="customer-search-container">
                            <input type="text" class="customer-search-input" id="customer-search" placeholder="Search customers..." autocomplete="off">
                            <div class="customer-dropdown" id="customer-dropdown"></div>
                        </div>
                        <div id="selected-customer-display"></div>
                        <input type="hidden" name="customer_id" id="customer-id">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-user-tie"></i> Sales Officer</label>
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
                            <label>Customer Name</label>
                            <input type="text" name="customer_name" id="customer-name" placeholder="Enter customer name">
                        </div>
                        <div class="form-group">
                            <label>Phone Number</label>
                            <input type="text" name="phone_no" id="phone-no" placeholder="Enter phone number">
                        </div>
                        <div class="form-group">
                            <label>NIC Number</label>
                            <input type="text" name="nic_no" id="nic-no" placeholder="Enter NIC number">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-money-bill-wave"></i> Paid Amount</label>
                        <input type="number" name="paid_amount" value="0" step="0.01" min="0" id="paid-amount" oninput="updateBalance()" required>
                    </div>
                    
                    <div class="total-row" style="margin-bottom: 16px;">
                        <span class="total-label">Balance:</span>
                        <span class="total-amount" id="balance-display">Rs<?php echo number_format(0 - $total, 2); ?></span>
                    </div>
                    
                    <div class="action-buttons">
                        <button type="button" class="btn btn-secondary" onclick="printBill()">
                            <i class="fas fa-print"></i> Print Bill
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="showHoldModal()">
                            <i class="fas fa-pause-circle"></i> Hold Bill
                        </button>
                        <button type="submit" name="complete_sale" class="btn btn-primary">
                            <i class="fas fa-check-circle"></i> Complete Sale
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Held Bills Section -->
            <?php if (!empty($_SESSION[$hold_session_key])): ?>
            <div class="held-bills-section">
                <div class="held-bills-header">
                    <i class="fas fa-clock"></i> Held Bills (<?php echo count($_SESSION[$hold_session_key]); ?>)
                </div>
                <div class="held-bills-list" id="held-bills-list">
                    <?php foreach ($_SESSION[$hold_session_key] as $hold_id => $hold): ?>
                        <div class="held-bill-item" data-hold-id="<?php echo htmlspecialchars($hold_id); ?>">
                            <div class="held-bill-info">
                                <div class="held-bill-name"><?php echo htmlspecialchars($hold['name']); ?></div>
                                <div class="held-bill-time"><?php echo date('d M H:i', strtotime($hold['created_at'])); ?></div>
                            </div>
                            <div class="held-bill-actions">
                                <button class="load-held-btn" onclick="loadHeldBill('<?php echo htmlspecialchars($hold_id); ?>')" title="Load Bill">
                                    <i class="fas fa-folder-open"></i>
                                </button>
                                <button class="cancel-held-btn" onclick="cancelHeldBill('<?php echo htmlspecialchars($hold_id); ?>')" title="Cancel">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Hold Bill Modal -->
<div class="modal" id="hold-modal">
    <div class="modal-content">
        <h3><i class="fas fa-pause-circle"></i> Hold Current Bill</h3>
        <input type="text" id="hold-name" placeholder="Enter bill name (e.g., Customer Name or Order #)" autocomplete="off">
        <div class="modal-buttons">
            <button onclick="holdBill()" style="background: var(--primary); color: white;">Hold Bill</button>
            <button onclick="closeHoldModal()" style="background: var(--gray); color: white;">Cancel</button>
        </div>
    </div>
</div>

<div class="notification" id="notification">
    <i class="fas fa-check-circle"></i> <span id="notification-message"></span>
</div>

<script>
    let currentProductPage = 1;
    let totalProductPages = 1;
    
    function updateClock() {
        const now = new Date();
        const options = { year: 'numeric', month: 'short', day: 'numeric' };
        document.querySelector('.current-date').textContent = now.toLocaleDateString('en-US', options);
        let hours = now.getHours();
        let minutes = now.getMinutes();
        const ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12 || 12;
        minutes = minutes < 10 ? '0' + minutes : minutes;
        document.querySelector('.current-time').textContent = `${hours}:${minutes} ${ampm}`;
        setTimeout(updateClock, 60000);
    }
    updateClock();
    
    function loadProductPage(page) {
        const searchTerm = document.getElementById('search-input').value.trim();
        const formData = new FormData();
        formData.append('action', 'search_products');
        formData.append('search', searchTerm);
        formData.append('page', page);
        
        fetch('billing1.php', {
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
                paginationDiv.innerHTML += `<button class="page-btn" onclick="loadProductPage(${currentProductPage - 1})"><i class="fas fa-chevron-left"></i> Previous</button>`;
            }
            
            paginationDiv.innerHTML += `<span class="page-info">Page ${currentProductPage} of ${totalProductPages}</span>`;
            
            if (currentProductPage < totalProductPages) {
                paginationDiv.innerHTML += `<button class="page-btn" onclick="loadProductPage(${currentProductPage + 1})">Next <i class="fas fa-chevron-right"></i></button>`;
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
        
        fetch('billing1.php', {
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
                    <strong><i class="fas fa-check-circle"></i> Selected:</strong>
                    <div>${escapeHtml(name)} (${escapeHtml(phone)} | ${escapeHtml(nic || 'N/A')})</div>
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
        const imei = prompt("Enter Customer Name:", "");
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
        
        fetch('billing1.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification(data.message);
                document.getElementById('cart-content').innerHTML = data.cart_html;
                updateTotalsDisplay(data.totals);
                document.querySelector('.cart-count').innerHTML = `<i class="fas fa-shopping-bag"></i> ${data.cart_count}`;
                
                if (data.cart_count === 0) {
                    document.getElementById('cart-content').innerHTML = `
                        <div class="empty-cart">
                            <i class="fas fa-shopping-cart" style="font-size: 40px; margin-bottom: 10px;"></i>
                            <h3>Empty Cart</h3>
                            <p>Add products to start billing</p>
                        </div>
                    `;
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
        
        fetch('billing1.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('cart-content').innerHTML = data.cart_html;
                updateTotalsDisplay(data.totals);
                document.querySelector('.cart-count').innerHTML = `<i class="fas fa-shopping-bag"></i> ${data.cart_count}`;
                
                if (data.cart_count === 0) {
                    document.getElementById('cart-content').innerHTML = `
                        <div class="empty-cart">
                            <i class="fas fa-shopping-cart" style="font-size: 40px; margin-bottom: 10px;"></i>
                            <h3>Empty Cart</h3>
                            <p>Add products to start billing</p>
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
        
        fetch('billing1.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification(data.message);
                document.getElementById('cart-content').innerHTML = data.cart_html;
                updateTotalsDisplay(data.totals);
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
        
        if (roundType === 'none') {
            alert('Please select a rounding type');
            return;
        }
        
        const formData = new FormData();
        formData.append('round_type', roundType);
        formData.append('round_target', roundTarget);
        formData.append('apply_rounding', '1');
        
        fetch('billing1.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification(data.message);
                document.getElementById('cart-content').innerHTML = data.cart_html;
                updateTotalsDisplay(data.totals);
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
            balanceDisplay.style.color = 'var(--danger)';
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
        const cartCount = parseInt(document.querySelector('.cart-count').textContent.match(/\d+/) || 0);
        
        let errors = [];
        
        if (cartCount === 0) {
            errors.push("Cart is empty. Please add products to complete sale.");
        }
        if (!cashierId) {
            errors.push("Please select a sales officer.");
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
        form.action = 'print_bill1.php';
        form.target = '_blank';

        const billNo = '<?php echo htmlspecialchars($bill_no); ?>';
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
    
    function showNotification(message) {
        const notification = document.getElementById('notification');
        const messageSpan = document.getElementById('notification-message');
        if (notification && messageSpan) {
            messageSpan.textContent = message;
            notification.style.display = 'flex';
            setTimeout(() => {
                notification.style.display = 'none';
            }, 3000);
        }
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Hold Bill Functions
    function showHoldModal() {
        const cartCount = parseInt(document.querySelector('.cart-count').textContent.match(/\d+/) || 0);
        if (cartCount === 0) {
            alert("Cart is empty. Cannot hold empty bill.");
            return;
        }
        document.getElementById('hold-modal').style.display = 'flex';
        document.getElementById('hold-name').value = '';
        document.getElementById('hold-name').focus();
    }
    
    function closeHoldModal() {
        document.getElementById('hold-modal').style.display = 'none';
    }
    
    function holdBill() {
        const holdName = document.getElementById('hold-name').value.trim();
        if (!holdName) {
            alert("Please enter a name for this held bill");
            return;
        }
        
        const formData = new FormData();
        formData.append('action', 'hold_bill');
        formData.append('hold_name', holdName);
        
        fetch('billing1.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification(data.message);
                // Clear cart display
                document.getElementById('cart-content').innerHTML = `
                    <div class="empty-cart">
                        <i class="fas fa-shopping-cart" style="font-size: 40px; margin-bottom: 10px;"></i>
                        <h3>Empty Cart</h3>
                        <p>Add products to start billing</p>
                    </div>
                `;
                document.querySelector('.cart-count').innerHTML = '<i class="fas fa-shopping-bag"></i> 0';
                document.getElementById('subtotal-display').textContent = 'Rs0.00';
                document.getElementById('total-discount-display').textContent = '-Rs0.00';
                document.getElementById('total-display').textContent = 'Rs0.00';
                document.getElementById('balance-display').textContent = 'Rs0.00';
                
                // Update held bills list
                if (data.held_bills && data.held_bills.length > 0) {
                    updateHeldBillsList(data.held_bills);
                }
                
                // Update bill number display
                document.querySelector('.bill-no').innerHTML = `<i class="fas fa-receipt"></i> Bill: ${data.bill_no}`;
                
                closeHoldModal();
            } else {
                alert(data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while holding the bill.');
        });
    }
    
    function loadHeldBill(holdId) {
        const formData = new FormData();
        formData.append('action', 'load_held_bill');
        formData.append('hold_id', holdId);
        
        fetch('billing1.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification(data.message);
                // Reload the page to reflect loaded cart
                window.location.reload();
            } else {
                alert(data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while loading the bill.');
        });
    }
    
    function cancelHeldBill(holdId) {
        if (confirm('Are you sure you want to cancel this held bill?')) {
            const formData = new FormData();
            formData.append('action', 'cancel_held_bill');
            formData.append('hold_id', holdId);
            
            fetch('billing1.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message);
                    updateHeldBillsList(data.held_bills);
                } else {
                    alert(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while cancelling the bill.');
            });
        }
    }
    
    function updateHeldBillsList(heldBills) {
        if (!heldBills || heldBills.length === 0) {
            const existingSection = document.querySelector('.held-bills-section');
            if (existingSection) existingSection.remove();
            return;
        }
        
        let html = `
            <div class="held-bills-section">
                <div class="held-bills-header">
                    <i class="fas fa-clock"></i> Held Bills (${heldBills.length})
                </div>
                <div class="held-bills-list" id="held-bills-list">
        `;
        
        heldBills.forEach(bill => {
            html += `
                <div class="held-bill-item" data-hold-id="${escapeHtml(bill.id)}">
                    <div class="held-bill-info">
                        <div class="held-bill-name">${escapeHtml(bill.name)}</div>
                        <div class="held-bill-time">${formatDateTime(bill.created_at)}</div>
                    </div>
                    <div class="held-bill-actions">
                        <button class="load-held-btn" onclick="loadHeldBill('${escapeHtml(bill.id)}')" title="Load Bill">
                            <i class="fas fa-folder-open"></i>
                        </button>
                        <button class="cancel-held-btn" onclick="cancelHeldBill('${escapeHtml(bill.id)}')" title="Cancel">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            `;
        });
        
        html += `</div></div>`;
        
        const existingSection = document.querySelector('.held-bills-section');
        if (existingSection) {
            existingSection.outerHTML = html;
        } else {
            const paymentSection = document.querySelector('.payment-section');
            if (paymentSection) {
                paymentSection.insertAdjacentHTML('afterend', html);
            }
        }
    }
    
    function formatDateTime(dateTimeStr) {
        if (!dateTimeStr) return '';
        const date = new Date(dateTimeStr);
        return `${date.getDate().toString().padStart(2, '0')}/${(date.getMonth()+1).toString().padStart(2, '0')} ${date.getHours().toString().padStart(2, '0')}:${date.getMinutes().toString().padStart(2, '0')}`;
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        updateBalance();
        
        // Close modal when clicking outside
        const modal = document.getElementById('hold-modal');
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeHoldModal();
                }
            });
        }
        
        // Enter key in modal
        const holdNameInput = document.getElementById('hold-name');
        if (holdNameInput) {
            holdNameInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    holdBill();
                }
            });
        }
    });
</script>

</body>
</html>

<?php
$conn->close();
?>