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
    // Get all data from POST
    $report_type = $_POST['report_type'] ?? 'all';
    $days = intval($_POST['days'] ?? 30);
    $transactions_json = $_POST['transactions'] ?? '[]';
    $transactions = json_decode($transactions_json, true);
    
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
    
    // Calculate summary
    $total_transfers = 0;
    $total_quantity = 0;
    $branch1_sent = 0;
    $branch2_sent = 0;
    $branch1_received = 0;
    $branch2_received = 0;
    
    foreach ($transactions as $t) {
        if ($t['transaction_type'] == 'transfer') {
            $total_transfers++;
            $total_quantity += intval($t['quantity']);
            
            if ($t['from_branch'] == 'branch1') {
                $branch1_sent += intval($t['quantity']);
            } else if ($t['from_branch'] == 'branch2') {
                $branch2_sent += intval($t['quantity']);
            }
            
            if ($t['to_branch'] == 'branch1') {
                $branch1_received += intval($t['quantity']);
            } else if ($t['to_branch'] == 'branch2') {
                $branch2_received += intval($t['quantity']);
            }
        }
    }
    
    // Date formatting
    $date_range = "Last " . $days . " Days";
    if ($days == 1) $date_range = "Today";
    else if ($days == 7) $date_range = "Last 7 Days";
    else if ($days == 30) $date_range = "Last 30 Days";
    else if ($days == 90) $date_range = "Last 90 Days";
    else if ($days == 365) $date_range = "Last Year";
    
    $print_time = date('d/m/Y H:i:s');
    
    // Count by type for display
    $type_text = ($report_type == 'all') ? 'All Transactions' : 
                 ($report_type == 'transfer' ? 'Transfers Only' : 
                 ($report_type == 'adjustment' ? 'Adjustments Only' : 'Returns Only'));
    
    // Calculate dynamic height based on number of transactions
    $rows_count = count($transactions) + 30; // Base rows + transaction rows
    $estimated_height_mm = 60 + ($rows_count * 3);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Stock Report</title>
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
        
        .report-period {
            text-align: center;
            font-size: 9px !important;
            margin: 1px 0 2px 0 !important;
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
        
        /* SUMMARY SECTION */
        .summary-section {
            margin: 2px 0 3px 0 !important;
            padding-bottom: 3px !important;
            border-bottom: 1px solid #000000 !important;
        }
        
        .summary-title {
            text-align: center;
            font-size: 9px !important;
            margin: 2px 0 !important;
            text-transform: uppercase;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin: 1.5px 0 !important;
            font-size: 8px !important;
        }
        
        .summary-label {
            text-align: left;
        }
        
        .summary-value {
            text-align: right;
        }
        
        /* BRANCH SUMMARY */
        .branch-summary {
            margin: 2px 0 !important;
            padding: 2px 0 !important;
            border-bottom: 1px solid #000000 !important;
        }
        
        .branch-row {
            display: flex;
            justify-content: space-between;
            margin: 1px 0 !important;
            font-size: 8px !important;
        }
        
        /* TRANSACTIONS LIST */
        .transactions-section {
            margin: 2px 0 !important;
        }
        
        .transactions-title {
            text-align: center;
            font-size: 9px !important;
            margin: 2px 0 !important;
            text-transform: uppercase;
            border-bottom: 1px solid #000000 !important;
            padding-bottom: 2px !important;
        }
        
        .transaction-item {
            margin: 2px 0 !important;
            padding: 2px 0 !important;
            border-bottom: 1px dotted #000000 !important;
        }
        
        .transaction-header {
            display: flex;
            justify-content: space-between;
            font-size: 8px !important;
            margin: 1px 0 !important;
        }
        
        .transaction-detail {
            display: flex;
            justify-content: space-between;
            font-size: 7px !important;
            margin-left: 2px !important;
        }
        
        .transaction-product {
            font-size: 8px !important;
            font-weight: bold !important;
        }
        
        .transaction-barcode {
            font-size: 6px !important;
            color: #333 !important;
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
        <div class="logo-section">
            <img src="Logo.jpeg" alt="Kids Berry Logo" class="logo-img">
        </div>
        
        <!-- HEADER -->
        <div class="header-section">
            <div class="store-name">KIDS BERRY</div>
            <div class="store-tagline">Stock Movement Report</div>
        </div>
        
        <!-- SEPARATOR -->
        <div class="separator"></div>
        
        <!-- REPORT TITLE -->
        <div class="report-title">
            STOCK TRANSACTIONS REPORT
        </div>
        
        <div class="report-period">
            <?php echo $date_range; ?> | <?php echo $type_text; ?>
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
            <div class="info-row">
                <span>Total Transactions:</span>
                <span><?php echo count($transactions); ?></span>
            </div>
        </div>
        
        <!-- SUMMARY SECTION -->
        <div class="summary-section">
            <div class="summary-title">SUMMARY</div>
            
            <div class="summary-row">
                <span class="summary-label">Total Transfers:</span>
                <span class="summary-value"><?php echo $total_transfers; ?></span>
            </div>
            
            <div class="summary-row">
                <span class="summary-label">Total Quantity:</span>
                <span class="summary-value"><?php echo $total_quantity; ?> units</span>
            </div>
        </div>
        
        <!-- BRANCH SUMMARY -->
        <div class="branch-summary">
            <div class="summary-title">BRANCH MOVEMENT</div>
            
            <div class="branch-row">
                <span>Branch 1 Sent:</span>
                <span><?php echo $branch1_sent; ?> units</span>
            </div>
            <div class="branch-row">
                <span>Branch 1 Received:</span>
                <span><?php echo $branch1_received; ?> units</span>
            </div>
            <div class="branch-row">
                <span>Branch 1 Net:</span>
                <span><?php echo ($branch1_received - $branch1_sent); ?> units</span>
            </div>
            
            <div class="separator" style="margin: 1px 0 !important;"></div>
            
            <div class="branch-row">
                <span>Branch 2 Sent:</span>
                <span><?php echo $branch2_sent; ?> units</span>
            </div>
            <div class="branch-row">
                <span>Branch 2 Received:</span>
                <span><?php echo $branch2_received; ?> units</span>
            </div>
            <div class="branch-row">
                <span>Branch 2 Net:</span>
                <span><?php echo ($branch2_received - $branch2_sent); ?> units</span>
            </div>
        </div>
        
        <!-- TRANSACTIONS LIST -->
        <div class="transactions-section">
            <div class="transactions-title">TRANSACTION DETAILS</div>
            
            <?php if (empty($transactions)): ?>
                <div style="text-align: center; margin: 5px 0; font-size: 8px;">No transactions found</div>
            <?php else: ?>
                <?php foreach ($transactions as $t): ?>
                    <div class="transaction-item">
                        <div class="transaction-header">
                            <span>
                                <?php 
                                if ($t['transaction_type'] == 'transfer') echo '📦 TRANSFER';
                                else if ($t['transaction_type'] == 'adjustment') echo '⚖️ ' . strtoupper($t['adjustment_type'] ?? 'ADJUSTMENT');
                                else echo '🔄 ' . strtoupper($t['return_type'] ?? 'RETURN');
                                ?>
                            </span>
                            <span><?php echo date('d/m H:i', strtotime($t['created_at'])); ?></span>
                        </div>
                        
                        <div class="transaction-detail">
                            <span class="transaction-product"><?php echo htmlspecialchars(substr($t['product_name'] ?? 'N/A', 0, 20)); ?></span>
                            <span>x<?php echo $t['quantity']; ?></span>
                        </div>
                        
                        <?php if (!empty($t['product_barcode'])): ?>
                            <div class="transaction-barcode">
                                <span>BC: <?php echo htmlspecialchars($t['product_barcode']); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <div class="transaction-detail">
                            <?php if ($t['transaction_type'] == 'transfer'): ?>
                                <span>From: <?php echo $t['branch_name'] ?? $t['from_branch']; ?></span>
                                <span>To: <?php echo $t['dest_branch_name'] ?? $t['to_branch']; ?></span>
                            <?php else: ?>
                                <span>Branch: <?php echo $t['branch_name'] ?? $t['from_branch']; ?></span>
                                <?php if (!empty($t['reason'])): ?>
                                    <span><?php echo htmlspecialchars($t['reason']); ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($t['customer_name'])): ?>
                            <div class="transaction-detail">
                                <span>Customer: <?php echo htmlspecialchars($t['customer_name']); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($t['supplier_name'])): ?>
                            <div class="transaction-detail">
                                <span>Supplier: <?php echo htmlspecialchars($t['supplier_name']); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($t['notes'])): ?>
                            <div class="transaction-detail">
                                <span>Note: <?php echo htmlspecialchars(substr($t['notes'], 0, 25)); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($t['old_stock']) && !empty($t['new_stock'])): ?>
                            <div class="transaction-detail">
                                <span>Stock: <?php echo $t['old_stock']; ?> → <?php echo $t['new_stock']; ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
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
                    <div class="signature-label">Checked By</div>
                    <div class="signature-line"></div>
                    <div class="signature-name">Manager</div>
                </div>
                
                <div class="signature-box">
                    <div class="signature-label">Received By</div>
                    <div class="signature-line"></div>
                    <div class="signature-name">Accounts</div>
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
                Computer Generated Report
            </div>
            <div style="margin-top: 1px !important; font-size: 6px;">
                End of Report - <?php echo count($transactions); ?> transactions
            </div>
        </div>
    </div>
    
    <!-- PRINT CONTROLS -->
    <div class="no-print" style="position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); background: white; padding: 10px 15px; border-radius: 5px; box-shadow: 0 0 10px rgba(0,0,0,0.3); border: 2px solid #000000; display: flex; gap: 10px; z-index: 1000;">
        <button onclick="printReport()" style="padding: 8px 16px; background: #000000; color: white; border: none; border-radius: 3px; cursor: pointer; font-weight: bold; font-family: 'Courier New', monospace; font-size: 12px;">
            🖨️ PRINT REPORT
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
            <p style="color: #333; margin-bottom: 20px;">Please generate a report first from Stock Transactions page.</p>
            <a href="stock_transactions1.php" class="btn">Go to Stock Transactions</a>
        </div>
    </body>
    </html>';
}
?>