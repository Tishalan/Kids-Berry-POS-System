<?php
session_start();

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "kidsberry";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$phone_no = isset($_GET['phone_no']) ? mysqli_real_escape_string($conn, $_GET['phone_no']) : '';

if (empty($phone_no)) {
    echo "<p>No phone number provided</p>";
    exit();
}

// Get customer purchase history
$history_sql = "SELECT b.*, cu.name as cashier_name 
                FROM bills b 
                LEFT JOIN cashier_users cu ON b.cashier_id = cu.cashier_id 
                WHERE b.phone_no = '$phone_no' 
                ORDER BY b.date DESC 
                LIMIT 20";
$history_result = $conn->query($history_sql);

// Get customer info
$customer_sql = "SELECT * FROM customers WHERE phone_no = '$phone_no'";
$customer_result = $conn->query($customer_sql);
$customer = $customer_result->fetch_assoc();

if ($history_result->num_rows > 0): ?>
    <div class="customer-info">
        <?php if($customer): ?>
            <div class="customer-summary">
                <h4>Customer Summary</h4>
                <p><strong>Name:</strong> <?php echo htmlspecialchars($customer['name']); ?></p>
                <p><strong>Membership:</strong> <?php echo htmlspecialchars($customer['membership_type']); ?></p>
                <p><strong>Total Purchases:</strong> <?php echo $customer['total_purchases']; ?></p>
            </div>
        <?php endif; ?>
        
        <h4>Purchase History (Last 20)</h4>
        <div class="history-table">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8f9fa;">
                        <th style="padding: 8px; border: 1px solid #ddd;">Bill No</th>
                        <th style="padding: 8px; border: 1px solid #ddd;">Date</th>
                        <th style="padding: 8px; border: 1px solid #ddd;">Cashier</th>
                        <th style="padding: 8px; border: 1px solid #ddd;">Method</th>
                        <th style="padding: 8px; border: 1px solid #ddd;">Amount</th>
                        <th style="padding: 8px; border: 1px solid #ddd;">Paid</th>
                        <th style="padding: 8px; border: 1px solid #ddd;">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($history = $history_result->fetch_assoc()): ?>
                        <tr>
                            <td style="padding: 8px; border: 1px solid #ddd;"><?php echo $history['bill_no']; ?></td>
                            <td style="padding: 8px; border: 1px solid #ddd;"><?php echo date('Y-m-d H:i', strtotime($history['date'])); ?></td>
                            <td style="padding: 8px; border: 1px solid #ddd;"><?php echo htmlspecialchars($history['cashier_name'] ?? 'N/A'); ?></td>
                            <td style="padding: 8px; border: 1px solid #ddd;"><?php echo $history['payment_method']; ?></td>
                            <td style="padding: 8px; border: 1px solid #ddd;">Rs<?php echo number_format($history['total'], 2); ?></td>
                            <td style="padding: 8px; border: 1px solid #ddd;">Rs<?php echo number_format($history['paid_amount'], 2); ?></td>
                            <td style="padding: 8px; border: 1px solid #ddd;">Rs<?php echo number_format($history['balance'], 2); ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php else: ?>
    <p style="text-align: center; color: #6c757d; padding: 20px;">
        <i class="fas fa-history"></i> No purchase history found for this customer.
    </p>
<?php endif;

$conn->close();
?>