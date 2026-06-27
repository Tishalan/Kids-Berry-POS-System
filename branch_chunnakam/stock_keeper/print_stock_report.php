<?php
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['stock_keeper_id'])) {
    header("Location: ../index.php");
    exit();
}

// Set timezone to Sri Lanka
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

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['print_report'])) {
    // Get data from POST
    $transaction_id = isset($_POST['transaction_id']) ? intval($_POST['transaction_id']) : 0;
    $transaction_json = isset($_POST['transaction']) ? $_POST['transaction'] : '{}';
    $transaction = json_decode($transaction_json, true);
    
    // Get stock keeper info
    $stock_keeper_id = $_SESSION['stock_keeper_id'];
    $stock_keeper_name = "Stock Keeper";
    
    $keeper_sql = "SELECT name FROM stock_keeper_users WHERE stock_keeper_id = ?";
    $stmt = $conn->prepare($keeper_sql);
    $stmt->bind_param("s", $stock_keeper_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $stock_keeper_name = $result->fetch_assoc()['name'];
    }
    $stmt->close();
    
    $print_time = date('d/m/Y H:i:s');
    
    // Calculate items count
    $items = isset($transaction['items']) ? $transaction['items'] : [];
    $total_items = count($items);
    $total_quantity = 0;
    foreach ($items as $item) {
        $total_quantity += intval($item['quantity']);
    }
    
    // Format branch names
    $from_branch_display = ($transaction['from_branch'] == 'branch1') ? 'Kids Berry' : 'Mega Centre';
    $to_branch_display = ($transaction['to_branch'] == 'branch1') ? 'Kids Berry' : 'Mega Centre';
    
    // Calculate dynamic height based on number of items
    $rows_count = $total_items + 25;
    $estimated_height_mm = 60 + ($rows_count * 4);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Stock Transfer Receipt</title>
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
            font-weight: bold !important;
            line-height: 1 !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        
        /* FIXED WIDTH FOR THERMAL PRINTER: 58mm */
        body {
            width: 58mm !important;
            min-height: <?php echo max(100, $estimated_height_mm); ?>mm !important;
            margin: 0 auto !important;
            padding: 0 !important;
            background: #FFFFFF !important;
            color: #000000 !important;
            font-size: 10px !important;
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
            }
            
            .no-print {
                display: none !important;
            }
        }
        
        /* RECEIPT CONTAINER */
        .receipt-container {
            width: 58mm;
            min-height: <?php echo max(100, $estimated_height_mm); ?>mm;
            margin: 0 auto;
            padding: 1mm;
            background: #FFFFFF;
            font-family: 'Courier New', Courier, monospace;
        }
        
        /* LOGO SECTION */
        .logo-section {
            text-align: center;
            margin-bottom: 2px !important;
        }
        
        .logo-img {
            width: 30mm !important;
            height: 15mm !important;
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
            font-size: 12px !important;
            margin: 1px 0 !important;
            text-transform: uppercase;
        }
        
        .store-tagline {
            font-size: 9px !important;
            margin: 1px 0 !important;
        }
        
        /* SEPARATOR */
        .separator {
            text-align: center;
            margin: 2px 0 !important;
            border-top: 1px dashed #000000 !important;
            height: 1px !important;
        }
        
        /* REPORT TITLE */
        .report-title {
            text-align: center;
            font-size: 11px !important;
            margin: 2px 0 !important;
            text-transform: uppercase;
        }
        
        .transaction-id {
            text-align: center;
            font-size: 9px !important;
            margin: 1px 0 2px 0 !important;
            font-weight: normal !important;
        }
        
        /* INFO SECTION */
        .info-section {
            margin-bottom: 3px !important;
            padding-bottom: 2px !important;
            border-bottom: 1px solid #000000 !important;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin: 1px 0 !important;
            font-size: 8px !important;
        }
        
        /* TRANSFER DETAILS */
        .transfer-details {
            margin: 2px 0 3px 0 !important;
            padding-bottom: 3px !important;
            border-bottom: 1px solid #000000 !important;
        }
        
        .details-title {
            text-align: center;
            font-size: 9px !important;
            margin: 2px 0 !important;
            text-transform: uppercase;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            margin: 1.5px 0 !important;
            font-size: 8px !important;
        }
        
        /* ITEMS LIST */
        .items-section {
            margin: 2px 0 !important;
        }
        
        .items-title {
            text-align: center;
            font-size: 9px !important;
            margin: 2px 0 !important;
            text-transform: uppercase;
            border-bottom: 1px solid #000000 !important;
            padding-bottom: 2px !important;
        }
        
        .item-row {
            margin: 2px 0 !important;
            padding: 2px 0 !important;
            border-bottom: 1px dotted #000000 !important;
        }
        
        .item-header {
            display: flex;
            justify-content: space-between;
            font-size: 8px !important;
            margin: 1px 0 !important;
        }
        
        .item-name {
            font-size: 8px !important;
            font-weight: bold !important;
        }
        
        .item-barcode {
            font-size: 6px !important;
            margin: 1px 0 !important;
        }
        
        .item-detail {
            display: flex;
            justify-content: space-between;
            font-size: 7px !important;
            margin-left: 2px !important;
        }
        
        /* SUMMARY SECTION */
        .summary-section {
            margin: 3px 0 2px 0 !important;
            padding-top: 2px !important;
            border-top: 1px solid #000000 !important;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin: 1.5px 0 !important;
            font-size: 8px !important;
        }
        
        /* FOOTER */
        .footer-section {
            margin-top: 3px !important;
            padding-top: 2px !important;
            border-top: 1px dashed #000000 !important;
            text-align: center;
            font-size: 7px !important;
        }
        
        .signature-section {
            margin-top: 3px !important;
            padding-top: 2px !important;
            border-top: 1px solid #000000 !important;
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
            margin: 2px 0 !important;
        }
        
        .signature-label {
            font-size: 6px !important;
        }
        
        .signature-name {
            font-size: 7px !important;
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
            }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <!-- LOGO SECTION -->
        <!--<div class="logo-section">-->
        <!--    <img src="logo.jpg" alt="Kids Berry Logo" class="logo-img">-->
        <!--</div>-->
        
        <!-- HEADER -->
        <div class="header-section">
            <div class="store-name">KIDS BERRY</div>
            <div class="store-tagline">Stock Transfer Receipt</div>
        </div>
        
        <!-- SEPARATOR -->
        <div class="separator"></div>
        
        <!-- REPORT TITLE -->
        <div class="report-title">
            STOCK TRANSFER RECEIPT
        </div>
        
        <div class="transaction-id">
            ID: #<?php echo str_pad($transaction_id, 6, '0', STR_PAD_LEFT); ?>
        </div>
        
        <!-- SEPARATOR -->
        <div class="separator"></div>
        
        <!-- INFO SECTION -->
        <div class="info-section">
            <div class="info-row">
                <span>Generated By:</span>
                <span><?php echo htmlspecialchars($stock_keeper_name); ?></span>
            </div>
            <div class="info-row">
                <span>Date & Time:</span>
                <span><?php echo $print_time; ?></span>
            </div>
        </div>
        
        <!-- TRANSFER DETAILS -->
        <div class="transfer-details">
            <div class="details-title">TRANSFER DETAILS</div>
            
            <div class="detail-row">
                <span>From Branch:</span>
                <span><?php echo $from_branch_display; ?></span>
            </div>
            
            <div class="detail-row">
                <span>To Branch:</span>
                <span><?php echo $to_branch_display; ?></span>
            </div>
            
            <?php if (!empty($transaction['notes'])): ?>
            <div class="detail-row">
                <span>Notes:</span>
                <span><?php echo htmlspecialchars(substr($transaction['notes'], 0, 30)); ?></span>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- ITEMS LIST -->
        <div class="items-section">
            <div class="items-title">TRANSFERRED ITEMS</div>
            
            <?php if (empty($items)): ?>
                <div style="text-align: center; margin: 5px 0; font-size: 8px;">No items in this transfer</div>
            <?php else: ?>
                <?php foreach ($items as $item): ?>
                    <div class="item-row">
                        <div class="item-header">
                            <span class="item-name"><?php echo htmlspecialchars(substr($item['product_name'], 0, 20)); ?></span>
                            <span>x<?php echo $item['quantity']; ?></span>
                        </div>
                        
                        <?php if (!empty($item['product_barcode'])): ?>
                            <div class="item-barcode">
                                BC: <?php echo htmlspecialchars($item['product_barcode']); ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="item-detail">
                            <span>ID: <?php echo htmlspecialchars($item['product_id']); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- SUMMARY SECTION -->
        <div class="summary-section">
            <div class="summary-row">
                <span>Total Items:</span>
                <span><?php echo $total_items; ?></span>
            </div>
            
            <div class="summary-row">
                <span>Total Quantity:</span>
                <span><?php echo $total_quantity; ?> units</span>
            </div>
        </div>
        
        <!-- SIGNATURES -->
        <div class="signature-section">
            <div class="signature-row">
                <div class="signature-box">
                    <div class="signature-label">Prepared By</div>
                    <div class="signature-line"></div>
                    <div class="signature-name"><?php echo htmlspecialchars($stock_keeper_name); ?></div>
                </div>
                
                <div class="signature-box">
                    <div class="signature-label">Received By</div>
                    <div class="signature-line"></div>
                    <div class="signature-name">Recipient</div>
                </div>
                
                <div class="signature-box">
                    <div class="signature-label">Verified By</div>
                    <div class="signature-line"></div>
                    <div class="signature-name">Manager</div>
                </div>
            </div>
        </div>
        
        <!-- FOOTER -->
        <div class="footer-section">
            <div class="developer-info">
                <div>Developed by SKY-TEC</div>
                <div>+94 75 090 6065</div>
            </div>
            <div style="margin-top: 1px !important;">
                Computer Generated Receipt
            </div>
            <div style="margin-top: 1px !important; font-size: 6px;">
                Thank you for using Kids Berry
            </div>
        </div>
    </div>
    
    <!-- PRINT CONTROLS -->
    <div class="no-print" style="position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); background: white; padding: 10px 15px; border-radius: 5px; box-shadow: 0 0 10px rgba(0,0,0,0.3); border: 2px solid #000000; display: flex; gap: 10px; z-index: 1000;">
        <button onclick="printReport()" style="padding: 8px 16px; background: #000000; color: white; border: none; border-radius: 3px; cursor: pointer; font-weight: bold; font-family: 'Courier New', monospace; font-size: 12px;">
            🖨️ PRINT RECEIPT
        </button>
        <button onclick="window.close()" style="padding: 8px 16px; background: #666666; color: white; border: none; border-radius: 3px; cursor: pointer; font-weight: bold; font-family: 'Courier New', monospace; font-size: 12px;">
            ❌ CLOSE
        </button>
    </div>
    
    <script>
        function printReport() {
            window.print();
        }
        
        window.addEventListener('load', function() {
            setTimeout(function() {
                window.focus();
                setTimeout(function() {
                    window.print();
                }, 500);
            }, 800);
        });
    </script>
</body>
</html>
<?php
    $conn->close();
} else {
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>Invalid Access</title>
        <style>
            body { font-family: Arial, sans-serif; text-align: center; padding: 50px; background: #666; }
            .message-box { 
                background: #fff; 
                padding: 30px; 
                border-radius: 10px; 
                box-shadow: 0 0 20px rgba(0,0,0,0.2);
                display: inline-block;
            }
            .btn {
                background: #0f7173;
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
    <body>
        <div class="message-box">
            <h2 style="color: #000; margin-bottom: 20px;">⚠️ Invalid Access</h2>
            <p style="color: #333; margin-bottom: 20px;">Please generate a receipt from Stock Transactions page.</p>
            <a href="stock_transactions.php" class="btn">Go to Stock Transactions</a>
        </div>
    </body>
    </html>';
}
?>