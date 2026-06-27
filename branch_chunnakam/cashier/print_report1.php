<?php
session_start();

if (!isset($_SESSION['cashiers_id'])) {
    header("Location: ../index.php");
    exit();
}

// Set timezone to Sri Lanka (Asia/Colombo)
date_default_timezone_set('Asia/Colombo');

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "kidsberry";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$branch_id = isset($_SESSION['branch_id']) ? $_SESSION['branch_id'] : 1;

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['print_report'])) {
    // Get all data from POST
    $report_type = $_POST['report_type'] ?? 'Daily Sales';
    $from_date = $_POST['from'] ?? date('Y-m-d');
    $to_date = $_POST['to'] ?? $from_date;
    $cashier_id = $_POST['cashier_id'] ?? '';
    
    // Get summary values
    $total_items = intval($_POST['total_items'] ?? 0);
    $total_sales = floatval($_POST['total_sales'] ?? 0);
    $period_returns = floatval($_POST['period_returns'] ?? 0);
    $period_return_count = intval($_POST['period_return_count'] ?? 0);
    $net_sales = floatval($_POST['net_sales'] ?? 0);
    $total_cash_payments = floatval($_POST['total_cash_payments'] ?? 0);
    $total_card_payments = floatval($_POST['total_card_payments'] ?? 0);
    $total_discount = floatval($_POST['total_discount'] ?? 0);
    $total_credits = intval($_POST['total_credits'] ?? 0);
    $total_credit_amount = floatval($_POST['total_credit_amount'] ?? 0);
    
    // Get cashier name if selected
    $cashier_name = "All Sales Officers";
    if (!empty($cashier_id)) {
        $cashier_sql = "SELECT name FROM cashier_users2 WHERE cashier_id = ?";
        $stmt = $conn->prepare($cashier_sql);
        $stmt->bind_param("s", $cashier_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $cashier_name = $result->fetch_assoc()['name'];
        }
        $stmt->close();
    }
    
    // SIMPLE BRANCH NAME
    $branch_name = "KIDS BERRY - JAFFNA";
    
    // Get logged in cashier info
    $logged_cashier_id = $_SESSION['cashiers_id'] ?? $_SESSION['cashier_id'] ?? '';
    $logged_cashier_name = "Cashier";
    if (!empty($logged_cashier_id)) {
        $logged_cashier_sql = "SELECT name FROM cashier_users2 WHERE cashier_id = ?";
        $stmt = $conn->prepare($logged_cashier_sql);
        $stmt->bind_param("s", $logged_cashier_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $logged_cashier_name = $result->fetch_assoc()['name'];
        }
        $stmt->close();
    }
    
    // Calculate other metrics
    $total_payments = $total_cash_payments + $total_card_payments;
    $average_sale = $total_items > 0 ? $net_sales / $total_items : 0;
    
    // Date formatting
    $date_range = date('d/m/Y', strtotime($from_date));
    if ($to_date != $from_date) {
        $date_range = date('d/m', strtotime($from_date)) . " to " . date('d/m/Y', strtotime($to_date));
    }
    
    // Check if today's report
    $today = date('Y-m-d');
    $is_today_report = ($from_date == $today && $to_date == $today);
    
    $print_time = date('d/m/Y H:i:s');
    
    // Calculate dynamic height
    $rows_count = 15; // Base rows
    if ($total_credits > 0) $rows_count += 4; // Add credit section rows
    
    // Dynamic height calculation (similar to bill print)
    $estimated_height_mm = 60 + ($rows_count * 4);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Sales Report</title>
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
            font-weight: bold !important; /* Bold everything for clear printing */
            line-height: 1 !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        
        /* FIXED WIDTH FOR CV2 PRINTER: 58mm printable area */
        body {
            width: 58mm !important;
            min-height: <?php echo max(100, $estimated_height_mm); ?>mm !important;
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
                size: 58mm <?php echo max(100, $estimated_height_mm); ?>mm !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            
            html, body {
                width: 58mm !important;
                height: auto !important;
                min-height: <?php echo max(100, $estimated_height_mm); ?>mm !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #FFFFFF !important;
            }
            
            .receipt-container {
                width: 58mm !important;
                min-height: <?php echo max(100, $estimated_height_mm); ?>mm !important;
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
            min-height: <?php echo max(100, $estimated_height_mm); ?>mm;
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
            margin: 1px 0 !important;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .store-tagline {
            font-size: 10px !important;
            margin: 1px 0 !important;
        }
        
        .store-address {
            font-size: 9px !important;
            margin: 0.5px 0 !important;
        }
        
        .store-contact {
            font-size: 9px !important;
            margin: 1px 0 !important;
        }
        
        /* SEPARATOR LINE */
        .separator {
            text-align: center;
            margin: 2px 0 3px 0 !important;
            border-top: 1px dashed #000000 !important;
            height: 1px !important;
        }
        
        /* REPORT TITLE */
        .report-title {
            text-align: center;
            font-size: 12px !important;
            margin: 2px 0 3px 0 !important;
            text-transform: uppercase;
        }
        
        .report-period {
            text-align: center;
            font-size: 10px !important;
            margin: 1px 0 2px 0 !important;
        }
        
        /* INFO ROWS */
        .info-section {
            margin-bottom: 3px !important;
            padding-bottom: 2px !important;
            border-bottom: 1px solid #000000 !important;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin: 1px 0 !important;
            font-size: 9px !important;
        }
        
        .info-label {
            text-align: left;
        }
        
        .info-value {
            text-align: right;
        }
        
        /* SUMMARY SECTION */
        .summary-section {
            margin: 2px 0 3px 0 !important;
            padding-bottom: 3px !important;
            border-bottom: 1px solid #000000 !important;
        }
        
        .summary-title {
            text-align: center;
            font-size: 10px !important;
            margin: 2px 0 !important;
            text-transform: uppercase;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin: 1.5px 0 !important;
            font-size: 9px !important;
        }
        
        .summary-label {
            text-align: left;
        }
        
        .summary-value {
            text-align: right;
        }
        
        .sub-row {
            display: flex;
            justify-content: space-between;
            margin: 1px 0 1px 5px !important;
            font-size: 8px !important;
        }
        
        /* PAYMENT SECTION */
        .payment-section {
            margin: 2px 0 3px 0 !important;
            padding-bottom: 3px !important;
            border-bottom: 1px solid #000000 !important;
        }
        
        .payment-title {
            text-align: center;
            font-size: 10px !important;
            margin: 2px 0 !important;
            text-transform: uppercase;
        }
        
        .payment-row {
            display: flex;
            justify-content: space-between;
            margin: 1.5px 0 !important;
            font-size: 9px !important;
        }
        
        .payment-total-row {
            display: flex;
            justify-content: space-between;
            margin: 2px 0 !important;
            padding: 1px 0 !important;
            border-top: 1px solid #000000 !important;
            font-size: 10px !important;
        }
        
        /* CASH HANDOVER */
        .handover-section {
            margin: 3px 0 !important;
            padding: 2px !important;
            border: 1px solid #000000 !important;
            text-align: center;
        }
        
        .handover-title {
            font-size: 9px !important;
            margin: 1px 0 !important;
        }
        
        .handover-amount {
            font-size: 14px !important;
            margin: 2px 0 !important;
            text-decoration: underline;
        }
        
        .handover-note {
            font-size: 8px !important;
            margin: 1px 0 !important;
            font-style: italic;
        }
        
        /* CREDIT SECTION */
        .credit-section {
            margin: 2px 0 3px 0 !important;
            padding-bottom: 3px !important;
            border-bottom: 1px solid #000000 !important;
        }
        
        .credit-note {
            font-size: 8px !important;
            text-align: center;
            margin: 1px 0 !important;
            font-style: italic;
        }
        
        /* SIGNATURE SECTION */
        .signature-section {
            margin-top: 4px !important;
            padding-top: 3px !important;
            border-top: 1px dashed #000000 !important;
        }
        
        .signature-row {
            display: flex;
            justify-content: space-between;
            margin: 2px 0 !important;
        }
        
        .signature-box {
            text-align: center;
            width: 30%;
        }
        
        .signature-line {
            border-top: 1px solid #000000 !important;
            width: 100%;
            margin: 3px 0 !important;
        }
        
        .signature-label {
            font-size: 7px !important;
            margin: 1px 0 !important;
        }
        
        .signature-name {
            font-size: 8px !important;
            margin: 1px 0 !important;
        }
        
        /* FOOTER */
        .footer-section {
            margin-top: 3px !important;
            padding-top: 2px !important;
            border-top: 1px dashed #000000 !important;
            text-align: center;
            font-size: 8px !important;
        }
        
        .developer-info {
            margin: 1px 0 !important;
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
                min-height: <?php echo max(100, $estimated_height_mm); ?>mm;
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
            <!--<div class="store-address">272 Manipay-karainagar Rd</div>-->
            <!--<div class="store-address">Jaffna</div>-->
            <!--<div class="store-contact">076 858 9493</div>-->
        </div>
        
        <!-- SEPARATOR -->
        <div class="separator"></div>
        
        <!-- REPORT TITLE -->
        <div class="report-title">
            <?php echo $is_today_report ? "TODAY'S SALES REPORT" : "SALES REPORT"; ?>
        </div>
        
        <div class="report-period">
            <?php echo $date_range; ?>
        </div>
        
        <!-- SEPARATOR -->
        <div class="separator"></div>
        
        <!-- INFO SECTION -->
        <div class="info-section">
            <div class="info-row">
                <span class="info-label">REPORT TYPE:</span>
                <span class="info-value"><?php echo htmlspecialchars($report_type); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">CASHIER:</span>
                <span class="info-value"><?php echo htmlspecialchars($logged_cashier_name); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">PRINTED:</span>
                <span class="info-value"><?php echo $print_time; ?></span>
            </div>
        </div>
        
        <!-- SUMMARY SECTION -->
        <div class="summary-section">
            <div class="summary-title">SALES SUMMARY</div>
            
            <div class="summary-row">
                <span class="summary-label">Total Items Sold:</span>
                <span class="summary-value"><?php echo number_format($total_items); ?></span>
            </div>
            
            <div class="summary-row">
                <span class="summary-label">Total Sales:</span>
                <span class="summary-value">Rs<?php 
        // Calculate gross sales = current total sales + returns
        $gross_sales = $total_sales ;
        echo number_format($gross_sales, 2); 
    ?></span>
            </div>
            
            <?php if ($period_returns > 0): ?>
            <div class="sub-row">
                <span>Returns:</span>
                <span>Rs<?php echo number_format($period_returns, 2); ?></span>
            </div>
            <div class="sub-row">
                <span>(<?php echo $period_return_count; ?> items)</span>
                <span></span>
            </div>
            <?php endif; ?>
            
            <!--<?php if ($total_discount > 0): ?>-->
            <!--<div class="summary-row">-->
            <!--    <span class="summary-label">Discount Given:</span>-->
            <!--    <span class="summary-value">Rs<?php echo number_format($total_discount, 2); ?></span>-->
            <!--</div>-->
            <!--<?php endif; ?>-->
            
            <div class="summary-row">
                <span class="summary-label">Net Sales:</span>
                <span class="summary-value">Rs<?php echo number_format($gross_sales - $period_returns, 2); ?></span>
            </div>
            
            <!--<?php if ($total_items > 0): ?>-->
            <!--<div class="summary-="row">-->
            <!--    <span class="summary-label">Average Sale:</span>-->
            <!--    <span class="summary-value">Rs<?php echo number_format($average_sale, 2); ?></span>-->
            <!--</div>-->
            <!--<?php endif; ?>-->
            
            <?php if ($is_today_report): ?>
            <div classsummary-row">
                <span class="summary-label">Today's Total Sales:</span>
                <span class="summary-value">Rs<?php echo number_format($gross_sales - $period_returns, 2); ?></span>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- PAYMENT SECTION -->
        <div class="payment-section">
            <div class="payment-title">PAYMENT BREAKDOWN</div>
            
            <div class="payment-row">
                <span>Total Cash Payments:</span>
                <span>Rs<?php echo number_format($total_cash_payments, 2); ?></span>
            </div>
            
            <?php if ($total_cash_payments > 0 && $total_payments > 0): ?>
            <div class="sub-row">
                <span>Percentage:</span>
                <span><?php echo number_format(($total_cash_payments / $total_payments * 100), 1); ?>%</span>
            </div>
            <?php endif; ?>
            
            <div class="payment-row">
                <span>Total Card Payments:</span>
                <span>Rs<?php echo number_format($total_card_payments, 2); ?></span>
            </div>
            
            <?php if ($total_card_payments > 0 && $total_payments > 0): ?>
            <div class="sub-row">
                <span>Percentage:</span>
                <span><?php echo number_format(($total_card_payments / $total_payments * 100), 1); ?>%</span>
            </div>
            <?php endif; ?>
            
            <div class="payment-total-row">
                <span>TOTAL RECEIVED:</span>
                <span>Rs<?php echo number_format($total_payments, 2); ?></span>
            </div>
        </div>
        
        <!-- CASH HANDOVER -->
        <div class="handover-section">
            <div class="handover-title">CASH TO HANDOVER</div>
            <div class="handover-amount">Rs<?php echo number_format($gross_sales - $period_returns, 2); ?></div>
            <div class="handover-note">Note: Verify cash in drawer matches this amount (after any deductions/balances given)</div>
        </div>
        
        <?php if ($total_credits > 0): ?>
        <!-- CREDIT SECTION -->
        <div class="credit-section">
            <div class="summary-title">CREDIT INFORMATION</div>
            
            <div class="summary-row">
                <span class="summary-label">Credit Bills:</span>
                <span class="summary-value"><?php echo $total_credits; ?></span>
            </div>
            
            <div class="summary-row">
                <span class="summary-label">Credit Amount:</span>
                <span class="summary-value">Rs<?php echo number_format($total_credit_amount, 2); ?></span>
            </div>
            
            <div class="credit-note">
                Note: <?php echo $total_credits; ?> credit bills pending
            </div>
        </div>
        <?php endif; ?>
        
        <!-- SIGNATURES -->
        <div class="signature-section">
            <div class="signature-row">
                <div class="signature-box">
                    <div class="signature-label">Prepared By</div>
                    <div class="signature-line"></div>
                    <div class="signature-name"><?php echo $logged_cashier_name; ?></div>
                    <div class="signature-label">Cashier</div>
                </div>
                
                <div class="signature-box">
                    <div class="signature-label">Checked By</div>
                    <div class="signature-line"></div>
                    <div class="signature-name">Manager</div>
                    <div class="signature-label">Store</div>
                </div>
                
                <div class="signature-box">
                    <div class="signature-label">Received By</div>
                    <div class="signature-line"></div>
                    <div class="signature-name">Accounts</div>
                    <div class="signature-label">Date: ______</div>
                </div>
            </div>
        </div>
        
        <!-- FOOTER -->
        <div class="footer-section">
            <!--<div style="margin: 1px 0 !important;">-->
            <!--    KIDS BERRY Sales System-->
            <!--</div>-->
            <div class="developer-info">
                <div>Developed by SKY-TEC</div>
                <div>+94 75 090 6065 | www.sky-tec.site</div>
            </div>
            <div style="margin-top: 2px !important; font-style: italic;">
                Computer Generated Report
            </div>
        </div>
    </div>
    
    <!-- PRINT CONTROLS - SCREEN ONLY -->
    <div class="no-print" style="position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); background: white; padding: 10px 15px; border-radius: 5px; box-shadow: 0 0 10px rgba(0,0,0,0.3); border: 2px solid #000000; display: flex; gap: 10px; z-index: 1000;">
        <button onclick="printReceipt()" style="padding: 8px 16px; background: #000000; color: white; border: none; border-radius: 3px; cursor: pointer; font-weight: bold; font-family: 'Courier New', monospace; font-size: 12px;">
            🖨️ PRINT REPORT
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
    $conn->close();
} else {
    // Invalid access
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>Invalid Access</title>
        <style>
            body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
            .message-box { 
                background: #fff; 
                padding: 30px; 
                border-radius: 10px; 
                box-shadow: 0 0 20px rgba(0,0,0,0.2);
                display: inline-block;
            }
            .btn {
                background: #000;
                color: white;
                padding: 10px 20px;
                text-decoration: none;
                border-radius: 5px;
                display: inline-block;
                margin-top: 20px;
                font-weight: bold;
            }
        </style>
    </head>
    <body style="background: #666;">
        <div class="message-box">
            <h2 style="color: #000; margin-bottom: 20px;">⚠️ Invalid Access</h2>
            <p style="color: #333; margin-bottom: 20px;">Please generate a report first from the Reports page.</p>
            <a href="report1.php" class="btn">Go to Reports Page</a>
        </div>
    </body>
    </html>';
}
?>