<?php
session_start();
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
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// Handle bulk PSN generation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['generate_psn'])) {
    $product_id = mysqli_real_escape_string($conn, $_POST['product_id']);
    $prefix = mysqli_real_escape_string($conn, $_POST['prefix']);
    $suffix = mysqli_real_escape_string($conn, $_POST['suffix']);
    $start_number = intval($_POST['start_number']);
    $count = intval($_POST['count']);
    
    // Get product name
    $product_sql = "SELECT name FROM products WHERE product_id = '$product_id'";
    $product_result = $conn->query($product_sql);
    if ($product_result->num_rows > 0) {
        $product = $product_result->fetch_assoc();
        $product_name = $product['name'];
        
        $conn->begin_transaction();
        try {
            $generated = 0;
            for ($i = 0; $i < $count; $i++) {
                $current_num = $start_number + $i;
                $psn_number = $prefix . str_pad($current_num, 6, '0', STR_PAD_LEFT) . $suffix;
                
                // Check if PSN already exists
                $check_sql = "SELECT id FROM product_psn_tracking WHERE psn_number = '$psn_number'";
                if ($conn->query($check_sql)->num_rows == 0) {
                    $insert_sql = "INSERT INTO product_psn_tracking 
                                   (product_id, product_name, psn_number, status) 
                                   VALUES ('$product_id', '$product_name', '$psn_number', 'available')";
                    if ($conn->query($insert_sql)) {
                        $generated++;
                    }
                }
            }
            
            $conn->commit();
            $_SESSION['psn_message'] = "Successfully generated $generated PSN numbers for $product_name";
            $_SESSION['psn_message_type'] = "success";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['psn_message'] = "Error generating PSN numbers: " . $e->getMessage();
            $_SESSION['psn_message_type'] = "error";
        }
    }
    
    header("Location: psn_manager.php");
    exit();
}

// Handle PSN deletion
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_psn'])) {
    $psn_id = mysqli_real_escape_string($conn, $_POST['psn_id']);
    
    $delete_sql = "DELETE FROM product_psn_tracking WHERE id = '$psn_id' AND status = 'available'";
    if ($conn->query($delete_sql)) {
        $_SESSION['psn_message'] = "PSN deleted successfully";
        $_SESSION['psn_message_type'] = "success";
    } else {
        $_SESSION['psn_message'] = "Error deleting PSN: " . $conn->error;
        $_SESSION['psn_message_type'] = "error";
    }
    
    header("Location: psn_manager.php");
    exit();
}

// Get all products for dropdown
$products_sql = "SELECT product_id, name FROM products ORDER BY name";
$products_result = $conn->query($products_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIDS Berry - PSN Manager</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #5a3d7e;
            --secondary: #28a745;
            --accent: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --success: #28a745;
            --light: #f8f9fa;
            --dark: #343a40;
            --text: #212529;
            --card-shadow: 0 4px 6px rgba(0,0,0,0.1), 0 1px 3px rgba(0,0,0,0.08);
            --transition: all 0.3s ease;
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
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
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
            height: 4px;
            background: linear-gradient(90deg, var(--secondary), var(--primary), var(--success));
            z-index: 1000;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .header {
            display: flex;
            justify-content: center;
            align-items: center;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 15px 30px;
            border-radius: 0 0 10px 10px;
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
            animation: slideDown 0.7s ease;
            margin-bottom: 30px;
        }

        .header::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
            transform: translateX(-100%);
            animation: shimmer 3s infinite;
        }

        .header-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex-grow: 1;
        }

        .header h1 {
            margin: 0;
            font-size: 32px;
            font-weight: 700;
            text-shadow: 1px 1px 3px rgba(0,0,0,0.3);
            color: #ffffff;
            animation: fadeIn 1s ease;
        }

        .header .back-btn {
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: white;
            text-decoration: none;
            background: rgba(255,255,255,0.2);
            padding: 12px 20px;
            border-radius: 8px;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            z-index: 1;
            animation: fadeInLeft 0.8s ease;
        }

        .header .back-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-50%) scale(1.05);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .section {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: var(--card-shadow);
            margin: 0 auto 30px;
            max-width: 1200px;
            animation: fadeInUp 0.8s ease;
            transition: var(--transition);
        }

        .section:hover {
            box-shadow: 0 15px 30px rgba(0,0,0,0.15), 0 5px 15px rgba(0,0,0,0.07);
        }

        .section h2 {
            color: var(--primary);
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 24px;
            position: relative;
        }

        .section h2::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 60px;
            height: 2px;
            background: var(--secondary);
        }

        .form-group {
            margin-bottom: 20px;
            animation: fadeIn 0.8s ease;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            transition: var(--transition);
            background: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.2);
            outline: none;
        }

        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 16px;
            animation: fadeInUp 0.8s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, #7c5ba1 100%);
            color: white;
            box-shadow: 0 4px 6px rgba(90, 61, 126, 0.2);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #4a2d6b 0%, #6a4d8f 100%);
            transform: translateY(-2px);
            box-shadow: 0 7px 10px rgba(90, 61, 126, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success) 0%, #48c774 100%);
            color: white;
            box-shadow: 0 4px 6px rgba(40, 167, 69, 0.2);
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #218838 0%, #34ce57 100%);
            transform: translateY(-2px);
            box-shadow: 0 7px 10px rgba(40, 167, 69, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--accent) 0%, #e35d6a 100%);
            color: white;
            box-shadow: 0 4px 6px rgba(220, 53, 69, 0.2);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #c82333 0%, #dc3545 100%);
            transform: translateY(-2px);
            box-shadow: 0 7px 10px rgba(220, 53, 69, 0.3);
        }

        .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
        }

        .form-row .form-group {
            flex: 1;
            margin-bottom: 0;
        }

        .table-container {
            overflow-x: auto;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            animation: fadeIn 0.8s ease;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            animation: fadeInUp 0.8s ease;
        }

        table th {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            position: sticky;
            top: 0;
        }

        table tr {
            transition: var(--transition);
            animation: fadeIn 0.5s ease;
        }

        table tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        table tr:hover {
            background-color: #e8f4fc;
            transform: translateX(5px);
        }

        table td {
            padding: 15px;
            border-bottom: 1px solid #eee;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-available {
            background-color: #d4edda;
            color: #155724;
        }

        .status-sold {
            background-color: #cce5ff;
            color: #004085;
        }

        .status-returned {
            background-color: #fff3cd;
            color: #856404;
        }

        .status-defective {
            background-color: #f8d7da;
            color: #721c24;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideInRight 0.5s ease;
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border-left: 4px solid var(--success);
        }

        .alert-error {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border-left: 4px solid var(--accent);
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #7f8c8d;
            animation: fadeIn 0.8s ease;
        }

        .no-data i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
            color: var(--primary);
        }

        .search-form {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            animation: fadeInUp 0.8s ease;
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            text-align: center;
            transition: var(--transition);
            border-left: 4px solid var(--primary);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.15);
        }

        .stat-card h3 {
            font-size: 14px;
            color: var(--dark);
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .stat-card .value {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
        }

        @keyframes slideDown {
            from { transform: translateY(-100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes fadeInUp {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @keyframes fadeInLeft {
            from { transform: translateX(-30px) translateY(-50%); opacity: 0; }
            to { transform: translateX(0) translateY(-50%); opacity: 1; }
        }

        @keyframes slideInRight {
            from { transform: translateX(100px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes shimmer {
            100% { transform: translateX(100%); }
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                text-align: center;
                padding: 15px;
            }

            .header .back-btn {
                position: relative;
                left: 0;
                top: 0;
                transform: none;
                margin-top: 10px;
                align-self: flex-start;
            }

            .form-row {
                flex-direction: column;
                gap: 10px;
            }

            table {
                font-size: 14px;
            }

            table th,
            table td {
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <a href="report.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Reports
        </a>
        <div class="header-content">
            <h1><i class="fas fa-barcode"></i> PSN Manager</h1>
        </div>
    </div>
    
    <div class="section">
        <?php if (isset($_SESSION['psn_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['psn_message_type']; ?>">
                <i class="fas <?php echo $_SESSION['psn_message_type'] == 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
                <?php echo $_SESSION['psn_message']; ?>
            </div>
            <?php 
            unset($_SESSION['psn_message']);
            unset($_SESSION['psn_message_type']);
            ?>
        <?php endif; ?>
        
        <h2><i class="fas fa-plus-circle"></i> Generate PSN Numbers</h2>
        
        <div class="search-form">
            <form method="post" action="">
                <div class="form-row">
                    <div class="form-group">
                        <label for="product_id"><i class="fas fa-box"></i> Product:</label>
                        <select name="product_id" id="product_id" required>
                            <option value="">Select Product</option>
                            <?php while($product = $products_result->fetch_assoc()): ?>
                                <option value="<?php echo $product['product_id']; ?>">
                                    <?php echo htmlspecialchars($product['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="prefix"><i class="fas fa-font"></i> Prefix:</label>
                        <input type="text" name="prefix" id="prefix" placeholder="E.g., KB" value="KB">
                    </div>
                    
                    <div class="form-group">
                        <label for="suffix"><i class="fas fa-font"></i> Suffix:</label>
                        <input type="text" name="suffix" id="suffix" placeholder="E.g., A">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="start_number"><i class="fas fa-sort-numeric-up"></i> Start Number:</label>
                        <input type="number" name="start_number" id="start_number" placeholder="E.g., 1001" min="1" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="count"><i class="fas fa-calculator"></i> Number of PSNs:</label>
                        <input type="number" name="count" id="count" placeholder="E.g., 100" min="1" max="1000" required>
                    </div>
                    
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="submit" name="generate_psn" class="btn btn-success">
                            <i class="fas fa-barcode"></i> Generate PSNs
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <div class="section">
        <h2><i class="fas fa-list"></i> PSN Statistics</h2>
        
        <?php
        // Get PSN statistics
        $stats_sql = "SELECT 
                        COUNT(*) as total_psn,
                        SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_psn,
                        SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold_psn,
                        SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) as returned_psn,
                        SUM(CASE WHEN status = 'defective' THEN 1 ELSE 0 END) as defective_psn
                      FROM product_psn_tracking";
        $stats_result = $conn->query($stats_sql);
        $stats = $stats_result->fetch_assoc();
        ?>
        
        <div class="stats-container">
            <div class="stat-card">
                <h3>Total PSN</h3>
                <div class="value"><?php echo $stats['total_psn'] ?? 0; ?></div>
            </div>
            
            <div class="stat-card">
                <h3>Available</h3>
                <div class="value" style="color: #28a745;"><?php echo $stats['available_psn'] ?? 0; ?></div>
            </div>
            
            <div class="stat-card">
                <h3>Sold</h3>
                <div class="value" style="color: #007bff;"><?php echo $stats['sold_psn'] ?? 0; ?></div>
            </div>
            
            <div class="stat-card">
                <h3>Returned</h3>
                <div class="value" style="color: #ffc107;"><?php echo $stats['returned_psn'] ?? 0; ?></div>
            </div>
            
            <div class="stat-card">
                <h3>Defective</h3>
                <div class="value" style="color: #dc3545;"><?php echo $stats['defective_psn'] ?? 0; ?></div>
            </div>
        </div>
    </div>
    
    <div class="section">
        <h2><i class="fas fa-search"></i> Search PSN Numbers</h2>
        
        <div class="search-form">
            <form method="get" action="">
                <div class="form-row">
                    <div class="form-group">
                        <label for="search_psn"><i class="fas fa-barcode"></i> PSN Number:</label>
                        <input type="text" name="search_psn" id="search_psn" placeholder="Search by PSN" 
                               value="<?php echo isset($_GET['search_psn']) ? htmlspecialchars($_GET['search_psn']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="search_product_psn"><i class="fas fa-box"></i> Product:</label>
                        <input type="text" name="search_product" id="search_product_psn" placeholder="Search by product" 
                               value="<?php echo isset($_GET['search_product']) ? htmlspecialchars($_GET['search_product']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="search_status"><i class="fas fa-filter"></i> Status:</label>
                        <select name="search_status" id="search_status">
                            <option value="">All Status</option>
                            <option value="available" <?php echo (isset($_GET['search_status']) && $_GET['search_status'] == 'available') ? 'selected' : ''; ?>>Available</option>
                            <option value="sold" <?php echo (isset($_GET['search_status']) && $_GET['search_status'] == 'sold') ? 'selected' : ''; ?>>Sold</option>
                            <option value="returned" <?php echo (isset($_GET['search_status']) && $_GET['search_status'] == 'returned') ? 'selected' : ''; ?>>Returned</option>
                            <option value="defective" <?php echo (isset($_GET['search_status']) && $_GET['search_status'] == 'defective') ? 'selected' : ''; ?>>Defective</option>
                        </select>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Search
                </button>
            </form>
        </div>
        
        <?php
        // Build search query
        $search_conditions = [];
        
        if (isset($_GET['search_psn']) && !empty($_GET['search_psn'])) {
            $search_psn = mysqli_real_escape_string($conn, $_GET['search_psn']);
            $search_conditions[] = "psn_number LIKE '%$search_psn%'";
        }
        
        if (isset($_GET['search_product']) && !empty($_GET['search_product'])) {
            $search_product = mysqli_real_escape_string($conn, $_GET['search_product']);
            $search_conditions[] = "product_name LIKE '%$search_product%'";
        }
        
        if (isset($_GET['search_status']) && !empty($_GET['search_status'])) {
            $search_status = mysqli_real_escape_string($conn, $_GET['search_status']);
            $search_conditions[] = "status = '$search_status'";
        }
        
        $psn_sql = "SELECT p.*, pr.name as product_name 
                   FROM product_psn_tracking p
                   LEFT JOIN products pr ON p.product_id = pr.product_id";
        
        if (!empty($search_conditions)) {
            $psn_sql .= " WHERE " . implode(" AND ", $search_conditions);
        }
        
        $psn_sql .= " ORDER BY created_at DESC LIMIT 100";
        
        $psn_result = $conn->query($psn_sql);
        ?>
        
        <?php if ($psn_result->num_rows > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>PSN Number</th>
                        <th>Product</th>
                        <th>Status</th>
                        <th>Sale Bill No</th>
                        <th>Return Bill No</th>
                        <th>Created Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($psn = $psn_result->fetch_assoc()): 
                        $status_class = 'status-' . $psn['status'];
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($psn['psn_number']); ?></strong></td>
                        <td><?php echo htmlspecialchars($psn['product_name']); ?></td>
                        <td><span class="status-badge <?php echo $status_class; ?>"><?php echo ucfirst($psn['status']); ?></span></td>
                        <td><?php echo $psn['sale_bill_no'] ? htmlspecialchars($psn['sale_bill_no']) : '-'; ?></td>
                        <td><?php echo $psn['return_bill_no'] ? htmlspecialchars($psn['return_bill_no']) : '-'; ?></td>
                        <td><?php echo date('Y-m-d', strtotime($psn['created_at'])); ?></td>
                        <td>
                            <?php if ($psn['status'] == 'available'): ?>
                            <form method="post" class="delete-form" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this PSN?');">
                                <input type="hidden" name="psn_id" value="<?php echo $psn['id']; ?>">
                                <button type="submit" name="delete_psn" class="btn btn-danger" style="padding: 5px 10px; font-size: 12px;">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </form>
                            <?php else: ?>
                            <span style="color: #6c757d; font-style: italic;">No action</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="no-data">
            <i class="fas fa-barcode"></i>
            <h3>No PSN numbers found</h3>
            <p>Try different search criteria or generate new PSNs</p>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
    // Set default start number to next available
    document.addEventListener('DOMContentLoaded', function() {
        const productSelect = document.getElementById('product_id');
        
        productSelect.addEventListener('change', function() {
            if (this.value) {
                // You can add AJAX here to get the last PSN number for this product
                // and set it as the default start number
            }
        });
    });
    </script>
</body>
</html>
<?php $conn->close(); ?>