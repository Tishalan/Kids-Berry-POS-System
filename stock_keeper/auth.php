<?php
// auth.php - Authentication helper functions

function checkStockKeeperAuth() {
    session_start();
    
    if (!isset($_SESSION['stock_keeper_id']) || !isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'stock_keeper') {
        header("Location: stologin.php");
        exit();
    }
    
    return true;
}

function getStockKeeperInfo() {
    if (isset($_SESSION['stock_keeper_id'])) {
        return [
            'id' => $_SESSION['stock_keeper_id'],
            'name' => $_SESSION['stock_keeper_name'],
            'email' => $_SESSION['stock_keeper_email']
        ];
    }
    return null;
}

function logout() {
    session_start();
    session_unset();
    session_destroy();
    header("Location: stologin.php");
    exit();
}
?>