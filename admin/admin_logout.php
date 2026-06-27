<?php
session_start();

// In cashier_manage.php - Replace the logout section
if (isset($_POST['logout'])) {
    // Only destroy admin related sessions
    unset($_SESSION['admin_id']);
    unset($_SESSION['admin_email']);
    header("Location: admin_login.php");
    exit();
}
?>