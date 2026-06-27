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
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

// Create uploads folder
if (!is_dir('uploads')) mkdir('uploads', 0777, true);

// Get selected branch from session or default to branch1
$selected_branch = isset($_SESSION['selected_branch_product']) ? $_SESSION['selected_branch_product'] : 'branch1';

// Handle branch selection - only through AJAX, not page reload
if (isset($_POST['action']) && $_POST['action'] === 'change_branch') {
    header('Content-Type: application/json');
    $selected_branch = $_POST['selected_branch'];
    $_SESSION['selected_branch_product'] = $selected_branch;
    
    // Determine which table to use based on selected branch
    $table_name = ($selected_branch == 'branch1') ? 'products' : 'products2';
    
    // Get updated totals from the selected table
    $total_sales_result = $conn->query("SELECT SUM(sale_price * stock) as total_sale_amount FROM $table_name");
    $total_original_result = $conn->query("SELECT SUM(original_price * stock) as total_original_amount FROM $table_name");
    $out_of_stock_count = $conn->query("SELECT COUNT(*) as count FROM $table_name WHERE stock = 0")->fetch_assoc()['count'] ?? 0;
    
    $total_sale_amount = $total_sales_result->fetch_assoc()['total_sale_amount'] ?? 0;
    $total_original_amount = $total_original_result->fetch_assoc()['total_original_amount'] ?? 0;
    
    // Get products for the selected branch
    $res = $conn->query("
        SELECT p.*, s.supplier_name, (p.sale_price * p.stock) as total_value 
        FROM $table_name p 
        LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id 
        ORDER BY p.name
    ");
    
    $html = '';
    if ($res->num_rows == 0) {
        $html = '<tr>
                    <td colspan="10">
                        <div class="empty-state">
                            <i class="fas fa-box-open"></i>
                            <h3>No Products Yet</h3>
                            <p>Add your first product to ' . ($selected_branch == 'branch1' ? 'Branch 1' : 'Branch 2') . ' to get started!</p>
                        </div>
                    </td>
                </tr>';
    } else {
        while($row = $res->fetch_assoc()) {
            $stockClass = $row['stock'] < 10 ? 'stock-low' : '';
            $totalValue = $row['sale_price'] * $row['stock'];
            
            $html .= '<tr data-id="'.htmlspecialchars($row['product_id']).'" 
                          data-name="'.htmlspecialchars($row['name']).'"
                          data-category="'.htmlspecialchars($row['category']).'"
                          data-barcode="'.htmlspecialchars($row['barcode']).'">
                        <td>
                            '.($row['photo'] ? '<img src="'.htmlspecialchars($row['photo']).'" class="photo-preview">' : 
                            '<div style="width:60px; height:60px; background:var(--light-purple); border-radius:10px; display:flex; align-items:center; justify-content:center;">
                                <i class="fas fa-image" style="font-size:20px; color:var(--primary-purple);"></i>
                            </div>').'
                        </td>
                        <td><strong>'.htmlspecialchars($row['product_id']).'</strong></td>
                        <td>
                            <div>'.htmlspecialchars($row['name']).'</div>
                            '.($row['specific_letter'] ? 
                            '<div class="specific-letter" style="font-size:0.8rem; color:var(--dark-purple); margin-top:3px;">
                                <i class="fas fa-sticky-note"></i> '.htmlspecialchars($row['specific_letter']).'
                            </div>' : '').'
                        </td>
                        <td>'.htmlspecialchars($row['category']).'</td>
                        <td>
                            '.($row['supplier_name'] ? 
                            '<span class="supplier-badge">'.htmlspecialchars($row['supplier_name']).'</span>' : 
                            '<span style="color:var(--medium-gray); font-style:italic;">No supplier</span>').'
                        </td>
                        <td>
                            '.($row['barcode'] ? 
                            '<span class="barcode-badge">'.htmlspecialchars($row['barcode']).'</span>' : 
                            '<span style="color:var(--medium-gray); font-style:italic;">No barcode</span>').'
                        </td>
                        <td>Rs. '.number_format($row['sale_price'],2).'</td>
                        <td><span class="stock-badge '.$stockClass.'">'.$row['stock'].'</span></td>
                        <td><span class="value-badge">Rs. '.number_format($totalValue, 2).'</span></td>
                        <td>
                            <div class="action-buttons">
                                <button class="action-btn edit-btn" onclick=\'editProduct('.json_encode($row).')\'>
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="action-btn del-btn" onclick="deleteProduct(\''.$row['product_id'].'\')">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                        </td>
                    </tr>';
        }
    }
    
    echo json_encode([
        'success' => true,
        'html' => $html,
        'total_sale' => 'Rs. ' . number_format($total_sale_amount, 2),
        'total_original' => 'Rs. ' . number_format($total_original_amount, 2),
        'out_of_stock_count' => $out_of_stock_count,
        'branch_name' => ($selected_branch == 'branch1') ? 'Branch 1' : 'Branch 2',
        'table_name' => ($selected_branch == 'branch1') ? 'products' : 'products2'
    ]);
    exit;
}

// Determine which table to use based on selected branch
$table_name = ($selected_branch == 'branch1') ? 'products' : 'products2';

// ==================== PHP BACKEND (AJAX) ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // Handle different AJAX actions
    $action = $_POST['action'] ?? '';

    // Delete Product
    if ($action === 'delete') {
        $id = $conn->real_escape_string($_POST['delete_id']);
        $branch = $_POST['branch'] ?? $selected_branch;
        $target_table = ($branch == 'branch1') ? 'products' : 'products2';
        
        // Optional: Delete photo file too
        $photo = $conn->query("SELECT photo FROM $target_table WHERE product_id='$id'")->fetch_assoc();
        if ($photo && $photo['photo'] && file_exists($photo['photo'])) unlink($photo['photo']);

        $conn->query("DELETE FROM $target_table WHERE product_id = '$id'");
        echo json_encode(['success' => true, 'message' => 'Product deleted!']);
        exit;
    }

    // Search Products (AJAX)
    if ($action === 'search') {
        $searchTerm = $conn->real_escape_string($_POST['search'] ?? '');
        $branch = $_POST['branch'] ?? $selected_branch;
        $target_table = ($branch == 'branch1') ? 'products' : 'products2';
        
        $where = '';
        
        if (!empty($searchTerm)) {
            $where = "WHERE p.product_id LIKE '%$searchTerm%' 
                      OR p.name LIKE '%$searchTerm%' 
                      OR p.category LIKE '%$searchTerm%' 
                      OR p.barcode LIKE '%$searchTerm%' 
                      OR s.supplier_name LIKE '%$searchTerm%'";
        }
        
        $res = $conn->query("
            SELECT p.*, s.supplier_name, (p.sale_price * p.stock) as total_value 
            FROM $target_table p 
            LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id 
            $where
            ORDER BY p.name
        ");
        
        $html = '';
        if ($res->num_rows == 0) {
            $html = '<tr>
                        <td colspan="10">
                            <div class="empty-state">
                                <i class="fas fa-box-open"></i>
                                <h3>No Products Found</h3>
                                <p>Try searching with different keywords</p>
                            </div>
                        </td>
                    </tr>';
        } else {
            while($row = $res->fetch_assoc()) {
                $stockClass = $row['stock'] < 10 ? 'stock-low' : '';
                $totalValue = $row['sale_price'] * $row['stock'];
                
                $html .= '<tr data-id="'.htmlspecialchars($row['product_id']).'" 
                              data-name="'.htmlspecialchars($row['name']).'"
                              data-category="'.htmlspecialchars($row['category']).'"
                              data-barcode="'.htmlspecialchars($row['barcode']).'">
                            <td>
                                '.($row['photo'] ? '<img src="'.htmlspecialchars($row['photo']).'" class="photo-preview">' : 
                                '<div style="width:60px; height:60px; background:var(--light-purple); border-radius:10px; display:flex; align-items:center; justify-content:center;">
                                    <i class="fas fa-image" style="font-size:20px; color:var(--primary-purple);"></i>
                                </div>').'
                            </td>
                            <td><strong>'.htmlspecialchars($row['product_id']).'</strong></td>
                            <td>
                                <div>'.htmlspecialchars($row['name']).'</div>
                                '.($row['specific_letter'] ? 
                                '<div class="specific-letter" style="font-size:0.8rem; color:var(--dark-purple); margin-top:3px;">
                                    <i class="fas fa-sticky-note"></i> '.htmlspecialchars($row['specific_letter']).'
                                </div>' : '').'
                            </td>
                            <td>'.htmlspecialchars($row['category']).'</td>
                            <td>
                                '.($row['supplier_name'] ? 
                                '<span class="supplier-badge">'.htmlspecialchars($row['supplier_name']).'</span>' : 
                                '<span style="color:var(--medium-gray); font-style:italic;">No supplier</span>').'
                            </td>
                            <td>
                                '.($row['barcode'] ? 
                                '<span class="barcode-badge">'.htmlspecialchars($row['barcode']).'</span>' : 
                                '<span style="color:var(--medium-gray); font-style:italic;">No barcode</span>').'
                            </td>
                            <td>Rs. '.number_format($row['sale_price'],2).'</td>
                            <td><span class="stock-badge '.$stockClass.'">'.$row['stock'].'</span></td>
                            <td><span class="value-badge">Rs. '.number_format($totalValue, 2).'</span></td>
                            <td>
                                <div class="action-buttons">
                                    <button class="action-btn edit-btn" onclick=\'editProduct('.json_encode($row).')\'>
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button class="action-btn del-btn" onclick="deleteProduct(\''.$row['product_id'].'\')">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </td>
                        </tr>';
            }
        }
        
        // Get updated totals from the selected table
        $total_sales_result = $conn->query("SELECT SUM(sale_price * stock) as total_sale_amount FROM $target_table");
        $total_original_result = $conn->query("SELECT SUM(original_price * stock) as total_original_amount FROM $target_table");
        
        $total_sale_amount = $total_sales_result->fetch_assoc()['total_sale_amount'] ?? 0;
        $total_original_amount = $total_original_result->fetch_assoc()['total_original_amount'] ?? 0;
        
        echo json_encode([
            'success' => true,
            'html' => $html,
            'total_sale' => 'Rs. ' . number_format($total_sale_amount, 2),
            'total_original' => 'Rs. ' . number_format($total_original_amount, 2)
        ]);
        exit;
    }

    // Get Out of Stock Products (AJAX)
    if ($action === 'get_out_of_stock') {
        $branch = $_POST['branch'] ?? $selected_branch;
        $target_table = ($branch == 'branch1') ? 'products' : 'products2';
        
        $res = $conn->query("
            SELECT p.*, s.supplier_name
            FROM $target_table p 
            LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id 
            WHERE p.stock = 0
            ORDER BY p.name
        ");
        
        $html = '';
        if ($res->num_rows == 0) {
            $html = '<tr>
                        <td colspan="10">
                            <div class="empty-state">
                                <i class="fas fa-check-circle"></i>
                                <h3>All Products in Stock!</h3>
                                <p>No out of stock products found</p>
                            </div>
                        </td>
                    </tr>';
        } else {
            while($row = $res->fetch_assoc()) {
                $html .= '<tr data-id="'.htmlspecialchars($row['product_id']).'" 
                              data-name="'.htmlspecialchars($row['name']).'"
                              data-category="'.htmlspecialchars($row['category']).'"
                              data-barcode="'.htmlspecialchars($row['barcode']).'">
                            <td>
                                '.($row['photo'] ? '<img src="'.htmlspecialchars($row['photo']).'" class="photo-preview">' : 
                                '<div style="width:60px; height:60px; background:var(--light-purple); border-radius:10px; display:flex; align-items:center; justify-content:center;">
                                    <i class="fas fa-image" style="font-size:20px; color:var(--primary-purple);"></i>
                                </div>').'
                            </td>
                            <td><strong>'.htmlspecialchars($row['product_id']).'</strong></td>
                            <td>
                                <div>'.htmlspecialchars($row['name']).'</div>
                                '.($row['specific_letter'] ? 
                                '<div class="specific-letter" style="font-size:0.8rem; color:var(--dark-purple); margin-top:3px;">
                                    <i class="fas fa-sticky-note"></i> '.htmlspecialchars($row['specific_letter']).'
                                </div>' : '').'
                            </td>
                            <td>'.htmlspecialchars($row['category']).'</td>
                            <td>
                                '.($row['supplier_name'] ? 
                                '<span class="supplier-badge">'.htmlspecialchars($row['supplier_name']).'</span>' : 
                                '<span style="color:var(--medium-gray); font-style:italic;">No supplier</span>').'
                            </td>
                            <td>
                                '.($row['barcode'] ? 
                                '<span class="barcode-badge">'.htmlspecialchars($row['barcode']).'</span>' : 
                                '<span style="color:var(--medium-gray); font-style:italic;">No barcode</span>').'
                            </td>
                            <td>Rs. '.number_format($row['sale_price'],2).'</td>
                            <td><span class="stock-badge stock-out">0</span></td>
                            <td><span class="value-badge">Rs. 0.00</span></td>
                            <td>
                                <div class="action-buttons">
                                    <button class="action-btn edit-btn" onclick=\'editProduct('.json_encode($row).')\'>
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button class="action-btn del-btn" onclick="deleteProduct(\''.$row['product_id'].'\')">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </td>
                        </tr>';
            }
        }
        
        echo json_encode([
            'success' => true,
            'html' => $html,
            'count' => $res->num_rows
        ]);
        exit;
    }

    // Add or Update Product
    if ($action === 'save') {
        $product_id = trim($_POST['product_id'] ?? '');
        $name = $conn->real_escape_string($_POST['name']);
        $category = $conn->real_escape_string($_POST['category']);
        $supplier_id = isset($_POST['supplier_id']) && !empty($_POST['supplier_id']) ? 
                       intval($_POST['supplier_id']) : NULL;
        $original_price = floatval($_POST['original_price']);
        $sale_price = floatval($_POST['sale_price']);
        $stock = intval($_POST['stock']);
        $barcode = $conn->real_escape_string($_POST['barcode'] ?? '');
        $color = $conn->real_escape_string($_POST['color'] ?? '');
        $specific_letter = $conn->real_escape_string($_POST['specific_letter'] ?? '');
        $branch = $_POST['branch'] ?? $selected_branch;
        
        // Determine which table to use based on branch selection
        $target_table = ($branch == 'branch1') ? 'products' : 'products2';

        $photo = '';
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === 0) {
            $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $photo = "admin/uploads/" . uniqid('prod_') . '.' . $ext;
            move_uploaded_file($_FILES['photo']['tmp_name'], $photo);
        }

        if (empty($product_id)) {
            // === ADD NEW PRODUCT ===
            $res = $conn->query("SELECT product_id FROM $target_table ORDER BY CAST(SUBSTRING(product_id,3) AS UNSIGNED) DESC LIMIT 1");
            $last = $res->fetch_assoc();
            $num = $last ? intval(substr($last['product_id'], 2)) + 1 : 1;
            $product_id = "KB" . str_pad($num, 5, "0", STR_PAD_LEFT);

            $sql = "INSERT INTO $target_table 
                    (product_id, name, category, supplier_id, original_price, sale_price, stock, barcode, color, photo, specific_letter) 
                    VALUES 
                    ('$product_id', '$name', '$category', " . ($supplier_id ? "'$supplier_id'" : "NULL") . ", $original_price, $sale_price, $stock, '$barcode', '$color', '$photo', '$specific_letter')";

            $msg = "Product added to " . ($branch == 'branch1' ? 'Branch 1' : 'Branch 2') . "! ID: $product_id";
        } else {
            // === UPDATE EXISTING PRODUCT ===
            $photo_sql = $photo ? ", photo='$photo'" : "";
            $sql = "UPDATE $target_table SET 
                    name='$name',
                    category='$category',
                    supplier_id=" . ($supplier_id ? "'$supplier_id'" : "NULL") . ",
                    original_price=$original_price,
                    sale_price=$sale_price,
                    stock=$stock,
                    barcode='$barcode',
                    color='$color',
                    specific_letter='$specific_letter' $photo_sql
                    WHERE product_id='$product_id'";

            $msg = "Product updated in " . ($branch == 'branch1' ? 'Branch 1' : 'Branch 2') . " successfully!";
        }

        if ($conn->query($sql) === TRUE) {
            // Get updated totals from the selected table
            $total_sales_result = $conn->query("SELECT SUM(sale_price * stock) as total_sale_amount FROM $target_table");
            $total_original_result = $conn->query("SELECT SUM(original_price * stock) as total_original_amount FROM $target_table");
            
            $total_sale_amount = $total_sales_result->fetch_assoc()['total_sale_amount'] ?? 0;
            $total_original_amount = $total_original_result->fetch_assoc()['total_original_amount'] ?? 0;
            
            echo json_encode([
                'success' => true, 
                'message' => $msg,
                'total_sale' => 'Rs. ' . number_format($total_sale_amount, 2),
                'total_original' => 'Rs. ' . number_format($total_original_amount, 2)
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database Error: ' . $conn->error]);
        }
        exit;
    }
}

// Calculate initial totals from selected table
$total_sales_result = $conn->query("SELECT SUM(sale_price * stock) as total_sale_amount FROM $table_name");
$total_original_result = $conn->query("SELECT SUM(original_price * stock) as total_original_amount FROM $table_name");

$total_sale_amount = $total_sales_result->fetch_assoc()['total_sale_amount'] ?? 0;
$total_original_amount = $total_original_result->fetch_assoc()['total_original_amount'] ?? 0;

// Get count of out of stock products from selected table
$out_of_stock_count = $conn->query("SELECT COUNT(*) as count FROM $table_name WHERE stock = 0")->fetch_assoc()['count'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Kids Berry - Product Management</title>
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
            --gradient-gold: linear-gradient(135deg, #ffd700, #ffa500);
            --gradient-silver: linear-gradient(135deg, #c0c0c0, #a0a0a0);
            --gradient-blue: linear-gradient(135deg, #3498db, #2980b9);
            --gradient-red: linear-gradient(135deg, #e74c3c, #c0392b);
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
        }

        /* Sidebar */
        .sidebar {
            width: 260px;
            background: var(--gradient-primary);
            color: var(--white);
            padding: 15px 0;
            box-shadow: var(--shadow-medium);
            z-index: 1000;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            left: 0;
            top: 0;
            transition: var(--transition);
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
            display: none;
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

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 15px;
            margin-left: 260px;
            transition: var(--transition);
            width: calc(100% - 260px);
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

        /* Summary Cards */
        .summary-cards {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }

        .summary-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--shadow-light);
            transition: var(--transition);
            animation: fadeInUp 0.8s ease;
            position: relative;
            overflow: hidden;
            text-align: center;
        }

        .summary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-primary);
        }

        .summary-card.sale::before {
            background: var(--gradient-gold);
        }

        .summary-card.original::before {
            background: var(--gradient-silver);
        }

        .summary-card.out-of-stock::before {
            background: var(--gradient-red);
        }

        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-medium);
        }

        .summary-icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
            display: inline-block;
            padding: 15px;
            border-radius: 50%;
            background: var(--light-gray);
            transition: var(--transition);
        }

        .summary-card.sale .summary-icon {
            background: linear-gradient(135deg, rgba(255, 215, 0, 0.2), rgba(255, 165, 0, 0.2));
            color: #ffa500;
        }

        .summary-card.original .summary-icon {
            background: linear-gradient(135deg, rgba(192, 192, 192, 0.2), rgba(160, 160, 160, 0.2));
            color: #808080;
        }

        .summary-card.out-of-stock .summary-icon {
            background: linear-gradient(135deg, rgba(231, 76, 60, 0.2), rgba(192, 57, 43, 0.2));
            color: #e74c3c;
        }

        .summary-card:hover .summary-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .summary-title {
            font-size: 0.9rem;
            color: var(--dark-gray);
            margin-bottom: 8px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .summary-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary-purple);
            margin-bottom: 5px;
            transition: var(--transition);
        }

        .summary-card.sale .summary-value {
            color: #ff8c00;
        }

        .summary-card.original .summary-value {
            color: #696969;
        }

        .summary-card.out-of-stock .summary-value {
            color: #e74c3c;
        }

        .summary-subtitle {
            font-size: 0.8rem;
            color: var(--dark-gray);
            opacity: 0.8;
        }

        /* Search Box */
        .search-container {
            position: relative;
            width: 100%;
            margin: 20px auto;
        }

        .search-box {
            width: 100%;
            padding: 15px 20px 15px 45px;
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
            display: flex;
            flex-direction: column;
            gap: 20px;
            margin-top: 10px;
        }

        /* Card Styles */
        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--shadow-light);
            transition: var(--transition);
            animation: fadeInUp 0.8s ease;
        }

        .card:hover {
            box-shadow: var(--shadow-medium);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--light-gray);
            flex-wrap: wrap;
            gap: 10px;
        }

        .card-title {
            color: var(--primary-purple);
            font-size: 1.2rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Form Styles */
        .form-container {
            display: none;
            margin-top: 20px;
            padding: 20px;
            background: var(--light-gray);
            border-radius: var(--border-radius);
            border: 2px solid var(--medium-gray);
        }

        .form-container.active {
            display: block;
            animation: fadeInUp 0.5s ease;
        }

        .form-group {
            margin-bottom: 15px;
            position: relative;
        }

        input, select, textarea {
            padding: 14px 16px;
            border-radius: var(--border-radius-small);
            font-size: 0.95rem;
            transition: var(--transition);
            border: 2px solid var(--medium-gray);
            width: 100%;
            background: var(--white);
        }

        input:focus, select:focus, textarea:focus {
            border-color: var(--primary-purple);
            box-shadow: 0 0 0 3px rgba(106, 13, 173, 0.2);
            outline: none;
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
            box-shadow: var(--shadow-light);
            min-width: 150px;
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

        .btn-red {
            background: var(--gradient-red);
        }

        .btn:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-medium);
        }

        .btn:active {
            transform: translateY(-1px);
        }

        /* Percentage Selector */
        .percentage-selector {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .percentage-option {
            flex: 1;
            min-width: 120px;
        }

        .percentage-option input[type="radio"] {
            display: none;
        }

        .percentage-label {
            display: block;
            padding: 12px 15px;
            background: var(--white);
            border: 2px solid var(--medium-gray);
            border-radius: var(--border-radius-small);
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
            color: var(--dark-gray);
        }

        .percentage-label:hover {
            background: var(--light-gray);
            border-color: var(--primary-purple);
        }

        .percentage-option input[type="radio"]:checked + .percentage-label {
            background: var(--gradient-primary);
            color: var(--white);
            border-color: var(--primary-purple);
            box-shadow: var(--shadow-light);
        }

        /* Price Calculator Info */
        .price-info {
            background: var(--light-gray);
            padding: 10px 15px;
            border-radius: var(--border-radius-small);
            margin-bottom: 15px;
            border-left: 4px solid var(--primary-purple);
            font-size: 0.85rem;
        }

        .price-info h4 {
            color: var(--primary-purple);
            margin-bottom: 5px;
            font-size: 0.9rem;
        }

        .price-info p {
            margin: 3px 0;
            color: var(--dark-gray);
        }

        /* Secret Code Info */
        .secret-code-info {
            background: linear-gradient(135deg, rgba(138, 43, 226, 0.1), rgba(144, 238, 144, 0.1));
            padding: 10px 15px;
            border-radius: var(--border-radius-small);
            margin: 10px 0;
            border: 1px solid var(--light-purple);
            font-size: 0.8rem;
        }

        .secret-code-info h5 {
            color: var(--dark-purple);
            margin-bottom: 5px;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .code-mapping {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 5px;
            margin-top: 8px;
        }

        .code-item {
            text-align: center;
            padding: 3px;
            background: var(--white);
            border-radius: 5px;
            border: 1px solid var(--medium-gray);
            font-size: 0.75rem;
        }

        .code-number {
            font-weight: bold;
            color: var(--primary-purple);
        }

        .code-letter {
            font-weight: bold;
            color: var(--dark-green);
        }

        /* Table Styles */
        .table-container {
            overflow-x: auto;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-light);
            background: var(--white);
            margin-top: 10px;
            max-height: 500px;
            overflow-y: auto;
        }

        table { 
            width: 100%; 
            border-collapse: collapse; 
            background: var(--white);
            min-width: 800px;
        }

        th { 
            background: var(--gradient-primary);
            color: var(--white); 
            padding: 15px 12px; 
            text-align: left; 
            font-weight: 600;
            position: sticky;
            top: 0;
            font-size: 0.9rem;
        }

        td { 
            padding: 14px 12px; 
            border-bottom: 1px solid var(--medium-gray); 
            transition: var(--transition);
            font-size: 0.9rem;
        }

        tr {
            transition: var(--transition);
        }

        tr:hover { 
            background: rgba(144, 238, 144, 0.2); 
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
            min-width: 70px;
            justify-content: center;
        }

        .edit-btn { 
            background: #27ae60;
            border: 2px solid #27ae60;
        }

        .del-btn { 
            background: #e74c3c;
            border: 2px solid #e74c3c;
        }

        .action-btn:hover { 
            transform: translateY(-3px);
            box-shadow: var(--shadow-light);
            filter: brightness(1.1);
        }

        /* Photo Preview */
        .photo-preview { 
            width: 60px; 
            height: 60px; 
            object-fit: cover; 
            border-radius: 10px; 
            border: 2px solid var(--light-purple);
            transition: var(--transition);
        }

        .photo-preview:hover {
            transform: scale(1.1);
            border-color: var(--primary-purple);
        }

        /* Stock Badge */
        .stock-badge { 
            background: var(--gradient-secondary); 
            color: var(--white); 
            padding: 5px 10px; 
            border-radius: 30px; 
            font-weight: bold;
            display: inline-block;
            text-align: center;
            min-width: 45px;
            box-shadow: var(--shadow-light);
            font-size: 0.85rem;
        }

        .stock-low {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
        }

        .stock-out {
            background: var(--gradient-red);
        }

        /* Value Badge */
        .value-badge { 
            background: linear-gradient(135deg, #6a0dad, #8a2be2); 
            color: var(--white); 
            padding: 5px 10px; 
            border-radius: 30px; 
            font-weight: bold;
            display: inline-block;
            text-align: center;
            min-width: 80px;
            box-shadow: var(--shadow-light);
            font-size: 0.85rem;
        }

        /* Supplier Badge */
        .supplier-badge {
            background: var(--gradient-blue);
            color: var(--white);
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
            margin-top: 3px;
        }

        /* Barcode Badge */
        .barcode-badge {
            background: var(--gradient-blue);
            color: var(--white);
            padding: 3px 8px;
            border-radius: 5px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }

        /* File Input Styling */
        .file-input-container {
            position: relative;
            overflow: hidden;
            display: inline-block;
            width: 100%;
        }

        .file-input-container input[type=file] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .file-input-label {
            display: block;
            padding: 14px 16px;
            background: var(--white);
            border: 2px dashed var(--medium-gray);
            border-radius: var(--border-radius-small);
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            color: var(--dark-gray);
            font-size: 0.9rem;
        }

        .file-input-label:hover {
            background: var(--light-purple);
            border-color: var(--primary-purple);
            color: var(--white);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 15px;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--light-purple);
            margin-bottom: 15px;
        }

        .empty-state h3 {
            font-size: 1.3rem;
            margin-bottom: 8px;
            color: var(--primary-purple);
        }

        .empty-state p {
            font-size: 0.9rem;
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        /* Mobile Menu Toggle */
        .menu-toggle {
            display: none;
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

        /* Out of Stock Indicator */
        .out-of-stock-indicator {
            position: absolute;
            top: 10px;
            right: 10px;
            background: var(--gradient-red);
            color: white;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: bold;
            box-shadow: var(--shadow-light);
            z-index: 1;
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

        /* Loading Spinner */
        .spinner {
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

        /* Toast Notification */
        .toast {
            position: fixed;
            bottom: 15px;
            right: 15px;
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

        /* Mobile Styles */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .close-sidebar {
                display: block;
            }
            
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 15px;
            }
            
            .menu-toggle {
                display: flex;
            }
            
            .summary-cards {
                grid-template-columns: 1fr;
            }
            
            .header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .user-info {
                width: 100%;
                justify-content: center;
            }
            
            .percentage-selector {
                flex-direction: column;
            }
            
            .percentage-option {
                min-width: 100%;
            }
            
            .code-mapping {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
            
            .card-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .branch-selector {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        /* Small Mobile */
        @media (max-width: 480px) {
            .action-buttons {
                flex-direction: column;
            }
            
            .action-btn {
                width: 100%;
            }
            
            .card {
                padding: 15px;
            }
            
            .code-mapping {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
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
                <li><a href="admin_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="cashier_prediction.php" class=""><i class="fas fa-chart-line"></i> Cashier Predictions</a></li>
                <li><a href="customer_manage.php"><i class="fas fa-users"></i> Customer Management</a></li>
                <li><a href="stockkeeper_manage.php"><i class="fas fa-user-tie"></i> Stock Keeper Management</a></li>
                <li><a href="cashier_manage.php"><i class="fas fa-user-tie"></i> Sales Officer Management</a></li>
                <li><a href="sales_manage.php"><i class="fas fa-cash-register"></i> Cashier Management</a></li>
                <li><a href="product_manage.php" class="active"><i class="fas fa-box"></i> Product Management</a></li>
                <li><a href="suppliers_manage.php"><i class="fas fa-truck"></i> Suppliers Management</a></li>
                <li><a href="report_show.php"><i class="fas fa-chart-line"></i> Reports</a></li>
                <li><a href="admin_contact_management.php"><i class="fas fa-headset"></i> Contact Requests</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <div class="header">
                <div>
                    <h1><i class="fas fa-box"></i> Product Management</h1>
                </div>
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
                        Branch 1 <span class="branch-badge" style="background: linear-gradient(135deg, #6a0dad, #4b0082);">Products Table</span>
                    </label>
                    <label class="branch-option">
                        <input type="radio" name="selected_branch" value="branch2" <?php echo ($selected_branch == 'branch2') ? 'checked' : ''; ?> onchange="changeBranch(this.value)">
                        Branch 2 <span class="branch-badge" style="background: linear-gradient(135deg, #228b22, #32cd32);">Products2 Table</span>
                    </label>
                </div>
                <div style="margin-left: auto;">
                    <span class="branch-badge" id="currentBranchBadge">
                        <i class="fas fa-database"></i> Currently viewing: <?php echo ($selected_branch == 'branch1') ? 'Branch 1 (products)' : 'Branch 2 (products2)'; ?>
                    </span>
                </div>
            </div>

            <!-- Summary Cards Section -->
            <div class="summary-cards">
                <div class="summary-card sale">
                    <i class="fas fa-tags summary-icon"></i>
                    <div class="summary-title">Total Sale Value <span id="branchNameSale">(<?php echo ($selected_branch == 'branch1') ? 'Branch 1' : 'Branch 2'; ?>)</span></div>
                    <div class="summary-value count-up" id="totalSaleValue">Rs. <?=number_format($total_sale_amount, 2)?></div>
                    <div class="summary-subtitle">Based on current stock</div>
                </div>
                <div class="summary-card original">
                    <i class="fas fa-receipt summary-icon"></i>
                    <div class="summary-title">Total Original Value <span id="branchNameOriginal">(<?php echo ($selected_branch == 'branch1') ? 'Branch 1' : 'Branch 2'; ?>)</span></div>
                    <div class="summary-value count-up" id="totalOriginalValue">Rs. <?=number_format($total_original_amount, 2)?></div>
                    <div class="summary-subtitle">Based on original prices</div>
                </div>
                <div class="summary-card out-of-stock">
                    <i class="fas fa-exclamation-triangle summary-icon"></i>
                    <div class="summary-title">Out of Stock <span id="branchNameOutOfStock">(<?php echo ($selected_branch == 'branch1') ? 'Branch 1' : 'Branch 2'; ?>)</span></div>
                    <div class="summary-value count-up" id="outOfStockCount"><?=$out_of_stock_count?></div>
                    <div class="summary-subtitle">Products with zero stock</div>
                </div>
            </div>

            <div class="search-container">
                <i class="fas fa-search search-icon"></i>
                <input type="text" id="searchInput" class="search-box" placeholder="Search Product Name, Category, Barcode, ID...">
            </div>

            <div class="main">
                <!-- Add Product Button -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title" id="tableTitle"><i class="fas fa-box"></i> Products - <span id="branchNameTitle"><?php echo ($selected_branch == 'branch1') ? 'Branch 1' : 'Branch 2'; ?></span></h2>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <button class="btn btn-primary" id="addProductBtn">
                                <i class="fas fa-plus"></i> Add New Product
                            </button>
                            <button class="btn btn-red" id="viewOutOfStockBtn">
                                <i class="fas fa-exclamation-triangle"></i> View Out of Stock (<span id="outOfStockCountBtn"><?=$out_of_stock_count?></span>)
                            </button>
                        </div>
                    </div>
                    
                    <!-- Form Container (Hidden by default) -->
                    <div class="form-container" id="formContainer">
                        <div class="price-info">
                            <h4><i class="fas fa-calculator"></i> Price Calculation Formula</h4>
                            <p><strong>Formula:</strong> Sale Price = PC Price × 2</p>
                            <p><strong>Original Price Formula:</strong> PC Price + (PC Price × 2 ÷ Division Factor)</p>
                            <p>Where Division Factor = 10 (for 20%) or 4 (for 50%)</p>
                        </div>
                        
                        <div class="secret-code-info">
                            <h5><i class="fas fa-key"></i> Secret Code Mapping</h5>
                            <div class="code-mapping">
                                <div class="code-item"><span class="code-number">1</span> → <span class="code-letter">H</span></div>
                                <div class="code-item"><span class="code-number">2</span> → <span class="code-letter">O</span></div>
                                <div class="code-item"><span class="code-number">3</span> → <span class="code-letter">R</span></div>
                                <div class="code-item"><span class="code-number">4</span> → <span class="code-letter">L</span></div>
                                <div class="code-item"><span class="code-number">5</span> → <span class="code-letter">I</span></div>
                                <div class="code-item"><span class="code-number">6</span> → <span class="code-letter">K</span></div>
                                <div class="code-item"><span class="code-number">7</span> → <span class="code-letter">S</span></div>
                                <div class="code-item"><span class="code-number">8</span> → <span class="code-letter">X</span></div>
                                <div class="code-item"><span class="code-number">9</span> → <span class="code-letter">Y</span></div>
                                <div class="code-item"><span class="code-number">0</span> → <span class="code-letter">Z</span></div>
                            </div>
                        </div>
                        
                        <form id="productForm" enctype="multipart/form-data">
                            <input type="hidden" name="product_id" id="product_id">
                            <input type="hidden" name="action" value="save">
                            <input type="hidden" name="branch" id="form_branch" value="<?php echo $selected_branch; ?>">
                            
                            <div class="form-group">
                                <input type="text" name="name" id="name" placeholder="Product Name" required>
                            </div>
                            
                            <div class="form-group">
                                <input type="text" name="category" id="category" list="categoryList" placeholder="Category (Type anything)" required>
                                <datalist id="categoryList">
                                    <?php
                                    $cats = $conn->query("SELECT DISTINCT category FROM $table_name ORDER BY category");
                                    while ($c = $cats->fetch_assoc()) echo "<option value=\"{$c['category']}\">";
                                    ?>
                                </datalist>
                            </div>
                            
                            <div class="form-group">
                                <select name="supplier_id" id="supplier_id">
                                    <option value="">Select Supplier (Optional)</option>
                                    <?php
                                    $suppliers = $conn->query("SELECT * FROM suppliers ORDER BY supplier_name");
                                    while ($supplier = $suppliers->fetch_assoc()) {
                                        echo "<option value=\"{$supplier['supplier_id']}\">{$supplier['supplier_name']}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            
                            <!-- Percentage Selector -->
                            <div class="percentage-selector">
                                <div class="percentage-option">
                                    <input type="radio" id="percent20" name="percentage" value="20" checked>
                                    <label for="percent20" class="percentage-label">
                                        <i class="fas fa-percentage"></i> 20% Markup
                                    </label>
                                </div>
                                <div class="percentage-option">
                                    <input type="radio" id="percent50" name="percentage" value="50">
                                    <label for="percent50" class="percentage-label">
                                        <i class="fas fa-percentage"></i> 50% Markup
                                    </label>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <input type="number" step="0.01" name="pc_price" id="pc_price" placeholder="PC Price (Optional)" oninput="calculatePrices()">
                            </div>
                            
                            <div class="form-group">
                                <input type="number" step="0.01" name="original_price" id="original_price" placeholder="Original Price" required>
                            </div>
                            
                            <div class="form-group">
                                <input type="number" step="0.01" name="sale_price" id="sale_price" placeholder="Sale Price" required>
                            </div>
                            
                            <div class="form-group">
                                <input type="number" name="stock" id="stock" placeholder="Stock Quantity" required>
                            </div>
                            
                            <div class="form-group">
                                <input type="text" name="barcode" id="barcode" placeholder="Barcode (Optional)">
                            </div>
                            
                            <div class="form-group">
                                <input type="text" name="color" id="color" placeholder="Color (Optional)">
                            </div>
                            
                            <div class="form-group">
                                <textarea name="specific_letter" id="specific_letter" placeholder="Specific Letter/Notes (Optional)" rows="3" oninput="convertNumberToLetters(this)"></textarea>
                                <small style="color: var(--medium-gray); font-size: 0.8rem; display: block; margin-top: 5px;">
                                    <i class="fas fa-info-circle"></i> Type numbers to auto-convert to secret code
                                </small>
                            </div>
                            
                            <div class="form-group">
                                <div class="file-input-container">
                                    <label class="file-input-label" for="photo">
                                        <i class="fas fa-cloud-upload-alt"></i> Choose Product Photo (Optional)
                                    </label>
                                    <input type="file" name="photo" id="photo" accept="image/*">
                                </div>
                                <img id="preview" class="photo-preview" style="display:none; margin-top:15px;">
                            </div>

                            <div class="form-group">
                                <label for="form_branch_select">Save to Branch</label>
                                <select id="form_branch_select" name="branch" class="form-control">
                                    <option value="branch1" <?php echo ($selected_branch == 'branch1') ? 'selected' : ''; ?>>Branch 1 (products table)</option>
                                    <option value="branch2" <?php echo ($selected_branch == 'branch2') ? 'selected' : ''; ?>>Branch 2 (products2 table)</option>
                                </select>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary" id="submitBtn">
                                    <i class="fas fa-save"></i> Save Product
                                </button>
                                
                                <button type="button" class="btn btn-secondary" id="cancelBtn">
                                    <i class="fas fa-times"></i> Cancel
                                </button>
                                
                                <button type="button" class="btn btn-blue" id="resetFormBtn">
                                    <i class="fas fa-redo"></i> Reset Form
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Table Section -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title" id="tableListTitle"><i class="fas fa-th-list"></i> Product List - <span id="branchNameList"><?php echo ($selected_branch == 'branch1') ? 'Branch 1' : 'Branch 2'; ?></span></h2>
                        <button class="btn btn-secondary" id="backToAllBtn" style="display: none;">
                            <i class="fas fa-arrow-left"></i> Back to All Products
                        </button>
                    </div>
                    <div class="table-container">
                        <table id="productTable">
                            <thead>
                                <tr>
                                    <th>Photo</th>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Category</th>
                                    <th>Supplier</th>
                                    <th>Barcode</th>
                                    <th>Sale Price</th>
                                    <th>Stock</th>
                                    <th>Total Value</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="productTableBody">
                                <?php
                                $res = $conn->query("
                                    SELECT p.*, s.supplier_name, (p.sale_price * p.stock) as total_value 
                                    FROM $table_name p 
                                    LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id 
                                    ORDER BY p.name
                                ");
                                if ($res->num_rows == 0):
                                ?>
                                <tr>
                                    <td colspan="10">
                                        <div class="empty-state">
                                            <i class="fas fa-box-open"></i>
                                            <h3>No Products Yet</h3>
                                            <p>Add your first product to <?php echo ($selected_branch == 'branch1') ? 'Branch 1' : 'Branch 2'; ?> to get started!</p>
                                        </div>
                                    </td>
                                </tr>
                                <?php else: while($row = $res->fetch_assoc()): 
                                    $stockClass = $row['stock'] < 10 ? 'stock-low' : '';
                                    $totalValue = $row['sale_price'] * $row['stock'];
                                ?>
                                <tr data-id="<?=htmlspecialchars($row['product_id'])?>" 
                                    data-name="<?=htmlspecialchars($row['name'])?>"
                                    data-category="<?=htmlspecialchars($row['category'])?>"
                                    data-barcode="<?=htmlspecialchars($row['barcode'])?>">
                                    <td>
                                        <?php if($row['photo']): ?>
                                            <img src="<?=htmlspecialchars($row['photo'])?>" class="photo-preview">
                                        <?php else: ?>
                                            <div style="width:60px; height:60px; background:var(--light-purple); border-radius:10px; display:flex; align-items:center; justify-content:center;">
                                                <i class="fas fa-image" style="font-size:20px; color:var(--primary-purple);"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?=htmlspecialchars($row['product_id'])?></strong></td>
                                    <td>
                                        <div><?=htmlspecialchars($row['name'])?></div>
                                        <?php if($row['specific_letter']): ?>
                                            <div class="specific-letter" style="font-size:0.8rem; color:var(--dark-purple); margin-top:3px;">
                                                <i class="fas fa-sticky-note"></i> <?=htmlspecialchars($row['specific_letter'])?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?=htmlspecialchars($row['category'])?></td>
                                    <td>
                                        <?php if($row['supplier_name']): ?>
                                            <span class="supplier-badge"><?=htmlspecialchars($row['supplier_name'])?></span>
                                        <?php else: ?>
                                            <span style="color:var(--medium-gray); font-style:italic;">No supplier</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($row['barcode']): ?>
                                            <span class="barcode-badge"><?=htmlspecialchars($row['barcode'])?></span>
                                        <?php else: ?>
                                            <span style="color:var(--medium-gray); font-style:italic;">No barcode</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>Rs. <?=number_format($row['sale_price'],2)?></td>
                                    <td><span class="stock-badge <?=$stockClass?>"><?=$row['stock']?></span></td>
                                    <td><span class="value-badge">Rs. <?=number_format($totalValue, 2)?></span></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn edit-btn" onclick='editProduct(<?=json_encode($row)?>)'>
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button class="action-btn del-btn" onclick="deleteProduct('<?=$row['product_id']?>')">
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
        const closeSidebar = document.getElementById('closeSidebar');

        menuToggle.addEventListener('click', () => {
            sidebar.classList.add('active');
        });

        closeSidebar.addEventListener('click', () => {
            sidebar.classList.remove('active');
        });

        // Toast notification function
        function showToast(message, type = 'info') {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');
            
            toastMessage.textContent = message;
            toast.classList.add('show');
            
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }

        // Form Management
        const addProductBtn = document.getElementById('addProductBtn');
        const formContainer = document.getElementById('formContainer');
        const cancelBtn = document.getElementById('cancelBtn');
        const resetFormBtn = document.getElementById('resetFormBtn');
        const productForm = document.getElementById('productForm');
        const submitBtn = document.getElementById('submitBtn');
        const viewOutOfStockBtn = document.getElementById('viewOutOfStockBtn');
        const backToAllBtn = document.getElementById('backToAllBtn');
        const tableTitle = document.getElementById('tableTitle');
        const tableListTitle = document.getElementById('tableListTitle');

        // Show form when Add Product button is clicked
        addProductBtn.addEventListener('click', () => {
            formContainer.classList.add('active');
            resetForm();
            submitBtn.innerHTML = '<i class="fas fa-plus"></i> Add Product';
            submitBtn.className = 'btn btn-primary';
            showToast('Add new product form opened');
        });

        // Hide form when Cancel button is clicked
        cancelBtn.addEventListener('click', () => {
            formContainer.classList.remove('active');
            showToast('Form closed');
        });

        // Reset form
        function resetForm() {
            productForm.reset();
            document.getElementById('product_id').value = '';
            document.getElementById('preview').style.display = 'none';
            document.getElementById('percent20').checked = true;
            submitBtn.innerHTML = '<i class="fas fa-plus"></i> Add Product';
            submitBtn.className = 'btn btn-primary';
        }

        resetFormBtn.addEventListener('click', resetForm);

        // Photo Preview
        document.getElementById('photo').addEventListener('change', function(e) {
            if (e.target.files[0]) {
                document.getElementById('preview').src = URL.createObjectURL(e.target.files[0]);
                document.getElementById('preview').style.display = 'block';
            }
        });

        // Number to letter conversion mapping
        const numberToLetterMap = {
            '1': 'H', '2': 'O', '3': 'R', '4': 'L', '5': 'I',
            '6': 'K', '7': 'S', '8': 'X', '9': 'Y', '0': 'Z'
        };

        // Convert numbers to secret code letters (for specific letter textarea)
        function convertNumberToLetters(textarea) {
            const cursorPosition = textarea.selectionStart;
            let value = textarea.value;
            
            // Convert only numbers
            let converted = '';
            for (let char of value) {
                if (char >= '0' && char <= '9') {
                    converted += numberToLetterMap[char];
                } else {
                    converted += char;
                }
            }
            
            textarea.value = converted;
            textarea.setSelectionRange(cursorPosition, cursorPosition);
        }

        // Convert PC Price number to secret code (full conversion)
        function convertPCPriceToLetters(pcPrice) {
            if (!pcPrice || pcPrice <= 0) return '';
            
            // Remove decimal points and convert to string
            const priceStr = Math.floor(pcPrice).toString();
            let secretCode = '';
            
            // Convert each digit to corresponding letter
            for (let char of priceStr) {
                if (numberToLetterMap[char]) {
                    secretCode += numberToLetterMap[char];
                }
            }
            
            return secretCode;
        }

        // Price calculation based on PC Price and selected percentage
        function calculatePrices() {
            const pcPriceInput = document.getElementById('pc_price');
            const originalPriceInput = document.getElementById('original_price');
            const salePriceInput = document.getElementById('sale_price');
            const percentageRadios = document.getElementsByName('percentage');
            const specificLetterTextarea = document.getElementById('specific_letter');
            
            const pcPrice = parseFloat(pcPriceInput.value) || 0;
            
            if (pcPrice > 0) {
                // Determine selected percentage
                let selectedPercentage = 20;
                for (let radio of percentageRadios) {
                    if (radio.checked) {
                        selectedPercentage = parseInt(radio.value);
                        break;
                    }
                }
                
                // Calculate sale price (PC Price × 2)
                const salePrice = pcPrice * 2;
                
                // Calculate original price based on percentage
                let originalPrice;
                if (selectedPercentage === 20) {
                    // For 20%: PC Price + (PC Price × 2 ÷ 10)
                    originalPrice = pcPrice + (pcPrice * 2 / 10);
                } else if (selectedPercentage === 50) {
                    // For 50%: PC Price + (PC Price × 2 ÷ 4)
                    originalPrice = pcPrice + (pcPrice * 2 / 4);
                }
                
                // Update price fields
                salePriceInput.value = salePrice.toFixed(2);
                originalPriceInput.value = originalPrice.toFixed(2);
                
                // Generate secret code from PC Price
                const secretCode = convertPCPriceToLetters(pcPrice);
                
                // Update specific letter field with the secret code
                // Only auto-fill if field is empty or contains only numbers/secret code
                const currentValue = specificLetterTextarea.value;
                const isSecretCodeFormat = /^[HORLIKSXYZ]+$/.test(currentValue);
                const isEmptyOrSameCode = !currentValue || currentValue === secretCode;
                
                if (isEmptyOrSameCode || (currentValue.length === secretCode.length && isSecretCodeFormat)) {
                    specificLetterTextarea.value = secretCode;
                }
                
                showToast(`Prices calculated with ${selectedPercentage}% markup`);
            }
        }

        // Listen to percentage radio button changes
        document.querySelectorAll('input[name="percentage"]').forEach(radio => {
            radio.addEventListener('change', () => {
                const pcPrice = parseFloat(document.getElementById('pc_price').value) || 0;
                if (pcPrice > 0) {
                    calculatePrices();
                }
            });
        });

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
                    // Update table content
                    document.getElementById('productTableBody').innerHTML = data.html;
                    
                    // Update summary cards
                    document.getElementById('totalSaleValue').textContent = data.total_sale;
                    document.getElementById('totalOriginalValue').textContent = data.total_original;
                    document.getElementById('outOfStockCount').textContent = data.out_of_stock_count;
                    document.getElementById('outOfStockCountBtn').textContent = data.out_of_stock_count;
                    
                    // Update branch names in UI
                    document.getElementById('branchNameSale').textContent = '(' + data.branch_name + ')';
                    document.getElementById('branchNameOriginal').textContent = '(' + data.branch_name + ')';
                    document.getElementById('branchNameOutOfStock').textContent = '(' + data.branch_name + ')';
                    document.getElementById('branchNameTitle').textContent = data.branch_name;
                    document.getElementById('branchNameList').textContent = data.branch_name;
                    document.getElementById('currentBranchBadge').innerHTML = '<i class="fas fa-database"></i> Currently viewing: ' + data.branch_name + ' (' + data.table_name + ')';
                    
                    // Update form branch hidden input and select
                    document.getElementById('form_branch').value = branch;
                    document.getElementById('form_branch_select').value = branch;
                    
                    // Reset any view state
                    backToAllBtn.style.display = 'none';
                    viewOutOfStockBtn.style.display = 'flex';
                    tableTitle.innerHTML = '<i class="fas fa-box"></i> Products - ' + data.branch_name;
                    tableListTitle.innerHTML = '<i class="fas fa-th-list"></i> Product List - ' + data.branch_name;
                    
                    showToast('Switched to ' + data.branch_name + ' successfully');
                }
            } catch (error) {
                console.error('Branch change error:', error);
                showToast('Error switching branch', 'error');
            }
        }

        // Form Submit (Add & Update)
        productForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const submitBtn = document.getElementById('submitBtn');
            const originalText = submitBtn.innerHTML;
            const formData = new FormData(this);

            // Show loading state
            submitBtn.innerHTML = '<span class="spinner"></span> Processing...';
            submitBtn.disabled = true;

            try {
                const res = await fetch('', { 
                    method: 'POST', 
                    body: formData 
                });
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
                    
                    // Update totals
                    document.getElementById('totalSaleValue').textContent = data.total_sale;
                    document.getElementById('totalOriginalValue').textContent = data.total_original;
                    
                    // Refresh table with AJAX search
                    await performSearch(document.getElementById('searchInput').value);
                    
                    // Reset and hide form
                    resetForm();
                    formContainer.classList.remove('active');
                    
                    // Update out of stock count
                    await updateOutOfStockCount();
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
        });

        // Edit Product
        function editProduct(p) {
            formContainer.classList.add('active');
            
            document.getElementById('product_id').value = p.product_id;
            document.getElementById('name').value = p.name;
            document.getElementById('category').value = p.category;
            document.getElementById('supplier_id').value = p.supplier_id || '';
            document.getElementById('original_price').value = p.original_price;
            document.getElementById('sale_price').value = p.sale_price;
            document.getElementById('stock').value = p.stock;
            document.getElementById('barcode').value = p.barcode || '';
            document.getElementById('color').value = p.color || '';
            document.getElementById('specific_letter').value = p.specific_letter || '';
            document.getElementById('form_branch').value = '<?php echo $selected_branch; ?>';
            document.getElementById('form_branch_select').value = '<?php echo $selected_branch; ?>';

            // Clear PC Price for editing (optional field)
            document.getElementById('pc_price').value = '';

            if (p.photo) {
                document.getElementById('preview').src = p.photo;
                document.getElementById('preview').style.display = 'block';
            }

            submitBtn.innerHTML = '<i class="fas fa-sync"></i> Update Product';
            submitBtn.className = 'btn btn-secondary';
            
            // Scroll to form on mobile
            if (window.innerWidth < 768) {
                formContainer.scrollIntoView({ behavior: 'smooth' });
            }
            
            showToast('Editing product: ' + p.name);
        }

        // Delete Product
        async function deleteProduct(id) {
            const branch = document.getElementById('form_branch').value;
            
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
                formData.append('action', 'delete');
                formData.append('delete_id', id);
                formData.append('branch', branch);

                const res = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await res.json();
                
                if (data.success) {
                    Swal.fire({
                        title: 'Deleted!',
                        text: 'Product has been deleted.',
                        icon: 'success',
                        timer: 1500,
                        showConfirmButton: false,
                        background: 'var(--light-green)',
                        color: 'var(--dark-green)'
                    });
                    
                    // Update totals and refresh table
                    await performSearch(document.getElementById('searchInput').value);
                    // Update out of stock count
                    await updateOutOfStockCount();
                }
            }
        }

        // View Out of Stock Products
        viewOutOfStockBtn.addEventListener('click', async function() {
            await loadOutOfStockProducts();
        });

        // Back to All Products
        backToAllBtn.addEventListener('click', async function() {
            await showAllProducts();
        });

        // Load Out of Stock Products
        async function loadOutOfStockProducts() {
            const branch = document.getElementById('form_branch').value;
            
            try {
                showToast('Loading out of stock products...');
                
                const formData = new FormData();
                formData.append('action', 'get_out_of_stock');
                formData.append('branch', branch);

                const res = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await res.json();
                
                if (data.success) {
                    document.getElementById('productTableBody').innerHTML = data.html;
                    tableTitle.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Out of Stock Products - <span id="branchNameOutOfStockTitle">' + (branch == 'branch1' ? 'Branch 1' : 'Branch 2') + '</span>';
                    tableListTitle.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Out of Stock Products - ' + (branch == 'branch1' ? 'Branch 1' : 'Branch 2');
                    
                    // Show back button, hide view out of stock button
                    backToAllBtn.style.display = 'flex';
                    viewOutOfStockBtn.style.display = 'none';
                    
                    showToast(`Found ${data.count} out of stock products`);
                }
            } catch (error) {
                console.error('Error loading out of stock products:', error);
                showToast('Error loading out of stock products', 'error');
            }
        }

        // Show All Products
        async function showAllProducts() {
            await performSearch(document.getElementById('searchInput').value);
            
            const branch = document.getElementById('form_branch').value;
            const branchName = branch == 'branch1' ? 'Branch 1' : 'Branch 2';
            
            tableTitle.innerHTML = '<i class="fas fa-box"></i> Products - ' + branchName;
            tableListTitle.innerHTML = '<i class="fas fa-th-list"></i> Product List - ' + branchName;
            
            // Show view out of stock button, hide back button
            backToAllBtn.style.display = 'none';
            viewOutOfStockBtn.style.display = 'flex';
            
            showToast('Showing all products');
        }

        // Update Out of Stock Count
        async function updateOutOfStockCount() {
            const branch = document.getElementById('form_branch').value;
            
            try {
                const formData = new FormData();
                formData.append('action', 'get_out_of_stock');
                formData.append('branch', branch);

                const res = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await res.json();
                
                if (data.success) {
                    document.getElementById('outOfStockCount').textContent = data.count;
                    document.getElementById('outOfStockCountBtn').textContent = data.count;
                    viewOutOfStockBtn.innerHTML = `<i class="fas fa-exclamation-triangle"></i> View Out of Stock (${data.count})`;
                }
            } catch (error) {
                console.error('Error updating out of stock count:', error);
            }
        }

        // AJAX Search with Debouncing
        let searchTimeout;
        const searchInput = document.getElementById('searchInput');
        
        async function performSearch(searchTerm) {
            const branch = document.getElementById('form_branch').value;
            
            try {
                const formData = new FormData();
                formData.append('action', 'search');
                formData.append('search', searchTerm);
                formData.append('branch', branch);

                const res = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await res.json();
                
                if (data.success) {
                    document.getElementById('productTableBody').innerHTML = data.html;
                    document.getElementById('totalSaleValue').textContent = data.total_sale;
                    document.getElementById('totalOriginalValue').textContent = data.total_original;
                }
            } catch (error) {
                console.error('Search error:', error);
            }
        }

        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                performSearch(this.value);
            }, 300);
        });

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Add animations
            const cards = document.querySelectorAll('.card, .summary-card');
            cards.forEach(card => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                
                setTimeout(() => {
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, 100);
            });
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>