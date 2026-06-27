<?php
session_start();

// Set timezone to Sri Lanka (Asia/Colombo)
date_default_timezone_set('Asia/Colombo');

// Check if logged in
if (!isset($_SESSION['cashiers_id'])) {
    header("Location: ../index.php");
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

$branch_id = 1; // Hardcoded for now
$cart_session_key = 'cart_branch_' . $branch_id;

// Get bill data from POST request
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $bill_no = mysqli_real_escape_string($conn, $_POST['bill_no']);
    $cashier_id = mysqli_real_escape_string($conn, $_POST['cashier_id']);
    $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method']);
    $paid_amount = isset($_POST['paid_amount']) ? floatval($_POST['paid_amount']) : 0;
    
    // 🔴 BILL NO +1 LOGIC START
    // Extract the numeric part from the bill number
    if (preg_match('/^PS-B\d+-(\d+)$/', $bill_no, $matches)) {
        $numeric_part = (int)$matches[1];
        $new_numeric_part = $numeric_part + 1;
        
        // Format the new bill number with leading zeros
        $prefix = "PS-B" . $branch_id . "-";
        $bill_no = $prefix . str_pad($new_numeric_part, 4, "0", STR_PAD_LEFT);
    }
    // 🔴 BILL NO +1 LOGIC END
    
    // Get cashier name
    $cashier_sql = "SELECT name FROM cashier_users2 WHERE cashier_id = '$cashier_id'";
    $cashier_result = $conn->query($cashier_sql);
    $cashier_name = "Unknown";
    if ($cashier_result->num_rows > 0) {
        $cashier = $cashier_result->fetch_assoc();
        $cashier_name = $cashier['name'];
    }
    
    // Calculate totals from cart
    $subtotal = 0;
    $total_discount = 0;
    $total_items = 0;
    $item_rows = 0; // Count total rows including discounts
    
    if (isset($_SESSION[$cart_session_key])) {
        foreach ($_SESSION[$cart_session_key] as $item) {
            $subtotal += ($item['price'] * $item['quantity']);
            $total_discount += $item['discount'];
            $total_items += $item['quantity'];
            $item_rows++; // Main item row
            if ($item['discount'] > 0) {
                $item_rows++; // Discount row
            }
        }
    }
    
    $total = $subtotal - $total_discount;
    $balance = $paid_amount - $total;
    
    // Calculate dynamic height based on content
    $estimated_height_mm = 50 + ($item_rows * 4);
    
    // Get Sri Lankan (Colombo) time
    $current_date = date('d/m/Y'); // Sri Lankan date format
    $current_time = date('H:i:s'); // Sri Lankan time
    $current_datetime = date('d/m/Y H:i:s'); // Full Sri Lankan date time
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Bill</title>
    <style>
        /* COMPLETE RESET FOR THERMAL PRINTING */
        * {
            margin: 0 !important;
            padding: 0 !important;
            box-sizing: border-box !important;
            color: #000000 !important;
            text-decoration: none !important;
            list-style: none !important;
            border: none !important;
            outline: none !important;
            background: transparent !important;
            font-family: 'Courier New', Courier, monospace !important;
            font-weight: normal !important;
            line-height: 1 !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        
        /* FIXED WIDTH FOR CV2 PRINTER: 58mm printable area */
        body {
            width: 58mm !important;
            min-height: <?php echo max(80, $estimated_height_mm); ?>mm !important;
            margin: 0 auto !important;
            padding: 0 !important;
            background: #FFFFFF !important;
            color: #000000 !important;
            font-size: 11px !important;
            overflow: hidden !important;
        }
        
        /* PRINT SPECIFIC STYLES */
        @media print {
            @page {
                size: 58mm <?php echo max(80, $estimated_height_mm); ?>mm !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            
            html, body {
                width: 58mm !important;
                height: auto !important;
                min-height: <?php echo max(80, $estimated_height_mm); ?>mm !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #FFFFFF !important;
            }
            
            .receipt-container {
                width: 58mm !important;
                min-height: <?php echo max(80, $estimated_height_mm); ?>mm !important;
                margin: 0 !important;
                padding: 1mm !important;
                page-break-inside: avoid !important;
                page-break-after: avoid !important;
                page-break-before: avoid !important;
            }
            
            .no-print {
                display: none !important;
                visibility: hidden !important;
                height: 0 !important;
                width: 0 !important;
                opacity: 0 !important;
            }
        }
        
        /* RECEIPT CONTAINER - DYNAMIC HEIGHT */
        .receipt-container {
            width: 58mm;
            min-height: <?php echo max(80, $estimated_height_mm); ?>mm;
            margin: 0 auto;
            padding: 1mm;
            background: #FFFFFF;
            color: #000000;
            font-family: 'Courier New', Courier, monospace;
            display: flex;
            flex-direction: column;
        }
        
        /* LOGO SECTION */
        .logo-section {
            text-align: center;
            margin-bottom: 3px !important;
        }
        
        .logo-img {
            width: 30mm !important;
            height: 20mm !important;
            max-height: 20mm !important;
            margin: 0 auto !important;
            display: block !important;
        }
        
        /* HEADER SECTION */
        .header-section {
            text-align: center;
            margin-bottom: 3px !important;
            padding-bottom: 3px !important;
            border-bottom: 1px solid #000000 !important;
        }
        
        .store-name {
            font-size: 13px !important;
            font-weight: bold !important;
            margin: 1px 0 !important;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .store-tagline {
            font-size: 10px !important;
            font-weight: bold !important;
            margin: 1px 0 !important;
        }
        
        .store-address {
            font-size: 9px !important;
            margin: 0.5px 0 !important;
        }
        
        .store-contact {
            font-size: 9px !important;
            font-weight: bold !important;
            margin: 1px 0 !important;
        }
        
        /* SEPARATOR LINE */
        .separator {
            text-align: center;
            margin: 2px 0 3px 0 !important;
            border-top: 1px dashed #000000 !important;
            height: 1px !important;
        }
        
        /* BILL INFO SECTION */
        .bill-info-section {
            margin-bottom: 3px !important;
            padding-bottom: 3px !important;
            border-bottom: 1px solid #000000 !important;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin: 1px 0 !important;
            font-size: 9px !important;
        }
        
        .info-label {
            font-weight: bold !important;
            text-align: left;
        }
        
        .info-value {
            text-align: right;
            font-weight: bold !important;
        }
        
        /* PRODUCTS HEADER */
        .products-header {
            text-align: center;
            margin: 3px 0 2px 0 !important;
            font-size: 10px !important;
            font-weight: bold !important;
            text-transform: uppercase;
        }
        
        /* ITEMS LIST - DYNAMIC SECTION */
        .items-section {
            flex: 1;
            min-height: 30mm;
            margin: 2px 0 !important;
        }
        
        .item-block {
            margin: 2px 0 !important;
            padding: 1px 0 !important;
            border-bottom: 1px dotted #000000 !important;
        }
        
        .item-name-row {
            display: flex;
            justify-content: space-between;
            margin: 0.5px 0 !important;
        }
        
        .item-main-name {
            font-weight: bold !important;
            font-size: 9px !important;
            flex: 1;
        }
        
        .item-qty {
            font-size: 9px !important;
            width: 15mm;
            text-align: right;
            font-weight: bold !important;
        }
        
        .item-details {
            font-size: 8px !important;
            margin: 0.5px 0 1px 5px !important;
            font-style: italic;
        }
        
        .item-price-row {
            display: flex;
            justify-content: space-between;
            margin: 0.5px 0 !important;
            font-size: 9px !important;
        }
        
        .item-price-label {
            text-align: left;
            font-weight: bold !important;
        }
        
        .item-price-value {
            font-weight: bold !important;
            text-align: right;
        }
        
        .item-total-row {
            display: flex;
            justify-content: space-between;
            margin: 0.5px 0 !important;
            font-size: 9px !important;
        }
        
        .item-total-label {
            text-align: left;
            font-weight: bold !important;
        }
        
        .item-total-value {
            font-weight: bold !important;
            text-align: right;
        }
        
        .discount-row {
            font-size: 8px !important;
            margin: 0.5px 0 1px 10px !important;
            color: #000000 !important;
            font-weight: bold !important;
        }
        
        /* TOTALS SECTION */
        .totals-section {
            margin-top: 3px !important;
            padding-top: 3px !important;
            border-top: 1px solid #000000 !important;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            margin: 1px 0 !important;
            font-size: 10px !important;
        }
        
        .total-label {
            font-weight: bold !important;
            text-align: left;
        }
        
        .total-value {
            font-weight: bold !important;
            text-align: right;
        }
        
        .grand-total-row {
            margin: 2px 0 !important;
            padding: 1px 0 !important;
            border-top: 2px solid #000000 !important;
            border-bottom: 2px solid #000000 !important;
            font-size: 11px !important;
            font-weight: bold !important;
        }
        
        /* PAYMENT SECTION */
        .payment-section {
            margin: 3px 0 !important;
            padding-top: 2px !important;
            border-top: 1px dashed #000000 !important;
            text-align: center;
            font-size: 9px !important;
            font-weight: bold !important;
        }
        
        /* BALANCE SECTION */
        .balance-section {
            margin: 2px 0 !important;
        }
        
        .balance-row {
            display: flex;
            justify-content: space-between;
            margin: 1px 0 !important;
            font-size: 10px !important;
            font-weight: bold !important;
        }
        
        /* FOOTER SECTION */
        .footer-section {
            margin-top: 4px !important;
            padding-top: 3px !important;
            border-top: 1px dashed #000000 !important;
            text-align: center;
        }
        
        .thank-you {
            font-weight: bold !important;
            margin: 1px 0 !important;
            font-size: 10px !important;
            text-transform: uppercase;
        }
        
        .developer-info {
            font-size: 8px !important;
            margin: 1px 0 !important;
        }
        
        .developer-name {
            font-weight: bold !important;
        }
        
        /* FOR SCREEN PREVIEW */
        @media screen {
            body {
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                background: #666666 !important;
                padding: 20px;
            }
            
            .receipt-container {
                background: #FFFFFF !important;
                box-shadow: 0 0 15px rgba(0,0,0,0.3);
                border-radius: 3px;
                min-height: <?php echo max(80, $estimated_height_mm); ?>mm;
            }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <!-- LOGO SECTION -->
        <div class="logo-section">
            <img src="Logo.jpeg" alt="Kids Berry Logo" class="logo-img">
        </div>
        
        <!-- HEADER -->
        <div class="header-section">
            <div class="store-name">KIDS BERRY MEGA CENTRE</div>
            <div class="store-tagline">Kids & Baby Needs</div>
            <div class="store-address"  style="font-weight: bold !important;" >225 KKS Road</div>
            <div class="store-address" style="font-weight: bold !important;" >Chunnakam</div>
            <div class="store-contact">077 994 2838</div>
        </div>
        
        <!-- SEPARATOR -->
        <div class="separator"></div>
        
        <!-- BILL INFO -->
        <div class="bill-info-section">
            <div class="info-row">
                <span class="info-label">BILL NO:</span>
                <span class="info-value"><?php echo htmlspecialchars($bill_no); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">DATE:</span>
                <span class="info-value"><?php echo $current_datetime; ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Sales Offcier:</span>
                <span class="info-value" style="font-weight: 900 !important;"><?php echo htmlspecialchars($cashier_name); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">PAYMENT:</span>
                <span class="info-value"><?php echo htmlspecialchars($payment_method); ?></span>
            </div>
        </div>
        
        <!-- PRODUCTS HEADER -->
        <div class="products-header">
            PRODUCTS
        </div>
        
        <!-- ITEMS LIST - DYNAMIC CONTENT -->
        <div class="items-section">
            <?php
            if (isset($_SESSION[$cart_session_key])) {
                foreach ($_SESSION[$cart_session_key] as $product_id => $item) {
                    // Item name
                    $item_name = $item['name'];
                    
                    // Item details
                    $color_info = !empty($item['color']) ? '(' . $item['color'] . ')' : '';
                    $imei_info = !empty($item['imei']) ? '[' . $item['imei'] . ']' : '';
                    
                    // Calculate item total
                    $item_total = $item['price'] * $item['quantity'];
                    
                    // Display item block
                    echo '<div class="item-block">';
                    
                    // Item name and quantity
                    echo '<div class="item-name-row">';
                    echo '<span class="item-main-name">' . htmlspecialchars($item_name) . ' ' . $color_info . '</span>';
                    echo '<span class="item-qty">Qty: ' . $item['quantity'] . '</span>';
                    echo '</div>';
                    
                    // IMEI details if exists
                    if (!empty($imei_info)) {
                        echo '<div class="item-details">' . $imei_info . '</div>';
                    }
                    
                    // Price row
                    echo '<div class="item-price-row">';
                    echo '<span class="item-price-label">Price:</span>';
                    echo '<span class="item-price-value">Rs' . number_format($item['price'], 2) . '</span>';
                    echo '</div>';
                    
                    // Total row
                    echo '<div class="item-total-row">';
                    echo '<span class="item-total-label">Item Total:</span>';
                    echo '<span class="item-total-value">Rs' . number_format($item_total, 2) . '</span>';
                    echo '</div>';
                    
                    // Discount if applied
                    if ($item['discount'] > 0) {
                        echo '<div class="discount-row">';
                        
                        // Get discount details from session cart
                        $cart_session_key = 'cart_branch_' . $branch_id;
                        $discount_display = '';
                        $discount_type = 'rs';
                        $discount_input = $item['discount'];
                        
                        // Check if we have discount type info in session
                        if (isset($_SESSION[$cart_session_key][$product_id])) {
                            $cart_item = $_SESSION[$cart_session_key][$product_id];
                            if (isset($cart_item['discount_type'])) {
                                $discount_type = $cart_item['discount_type'];
                                $discount_input = $cart_item['discount_input'];
                                
                                if ($discount_type === 'percentage') {
                                    $discount_display = '-'.$discount_input.'%';
                                } else {
                                    $discount_display = '-Rs'.number_format($discount_input, 2);
                                }
                            } else {
                                $discount_display = '-Rs'.number_format($item['discount'], 2);
                            }
                        } else {
                            $discount_display = '-Rs'.number_format($item['discount'], 2);
                        }
                        
                        // Show percentage first, then calculated amount
                        if ($discount_type === 'percentage') {
                            echo 'Discount '.$discount_display.' = -Rs' . number_format($item['discount'], 2);
                        } else {
                            echo 'Discount: ' . $discount_display;
                        }
                        
                        echo '</div>';
                    }
                    
                    echo '</div>'; // End item-block
                }
            }
            ?>
        </div>
        
        <!-- TOTALS SECTION -->
        <div class="totals-section">
            <div class="total-row">
                <span class="total-label">SUBTOTAL:</span>
                <span class="total-value">Rs<?php echo number_format($subtotal, 2); ?></span>
            </div>
            <?php if ($total_discount > 0): ?>
            <div class="total-row">
                <span class="total-label">DISCOUNT:</span>
                <span class="total-value">-Rs<?php echo number_format($total_discount, 2); ?></span>
            </div>
            <?php endif; ?>
            <div class="total-row grand-total-row">
                <span class="total-label">TOTAL:</span>
                <span class="total-value">Rs<?php echo number_format($total, 2); ?></span>
            </div>
        </div>
        
        <!-- PAYMENT INFO -->
        <div class="payment-section">
            Payment Method: <?php echo htmlspecialchars($payment_method); ?>
        </div>
        
        <!-- BALANCE -->
        <div class="balance-section">
            <div class="total-row">
                <span class="total-label">PAID:</span>
                <span class="total-value">Rs<?php echo number_format($paid_amount, 2); ?></span>
            </div>
            <div class="balance-row">
                <span class="total-label">BALANCE:</span>
                <span class="total-value">Rs<?php echo number_format($balance, 2); ?></span>
            </div>
        </div>
        
        <!-- FOOTER -->
        <div class="footer-section">
            <div class="thank-you">GROW WITH STYLE!</div>
            <div style="font-size: 8px !important; margin: 2px 0 !important; font-style: italic; font-weight: bold !important;">
                Returns accepted within 3 days with original receipt
            </div>
             <div class="thank-you">You Saved Rs <?php echo number_format($total_discount, 2); ?></div>
            <br>
            <div class="developer-info">
                <div class="developer-name">Developed by SKY-TEC</div>
                <div style="font-weight: bold !important;"  >+94 75 090 6065</div>
                <div style="font-weight: bold !important;"  >www.sky-tec.site</div>
                <!--<div><?php echo date('d-M-Y H:i', strtotime($current_datetime)); ?></div>-->
            </div>
        </div>
    </div>
    
    <!-- PRINT CONTROLS - SCREEN ONLY -->
    <div class="no-print" style="position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); background: white; padding: 10px 15px; border-radius: 5px; box-shadow: 0 0 10px rgba(0,0,0,0.3); border: 2px solid #000000; display: flex; gap: 10px; z-index: 1000;">
        <button onclick="printReceipt()" style="padding: 8px 16px; background: #000000; color: white; border: none; border-radius: 3px; cursor: pointer; font-weight: bold; font-family: 'Courier New', monospace; font-size: 12px;">
            🖨️ PRINT BILL
        </button>
        <button onclick="window.close()" style="padding: 8px 16px; background: #666666; color: white; border: none; border-radius: 3px; cursor: pointer; font-weight: bold; font-family: 'Courier New', monospace; font-size: 12px;">
            ❌ CLOSE
        </button>
    </div>
    
    <script>
        // Print function optimized for thermal printers
        function printReceipt() {
            // Force print dialog
            window.print();
        }
        
        // Auto-print on load with delay for better rendering
        window.addEventListener('load', function() {
            // Calculate if we need to auto-print
            const urlParams = new URLSearchParams(window.location.search);
            const shouldAutoPrint = !urlParams.has('noautoprint');
            
            if (shouldAutoPrint) {
                setTimeout(function() {
                    // Focus window
                    window.focus();
                    
                    // Print after a short delay
                    setTimeout(function() {
                        window.print();
                    }, 300);
                }, 800);
            }
        });
        
        // Optional: Handle after print event
        window.addEventListener('afterprint', function() {
            // You can add auto-close here if needed
            // setTimeout(function() {
            //     window.close();
            // }, 1000);
        });
    </script>
</body>
</html>
<?php
} else {
    echo "Invalid request method.";
}

$conn->close();
?>