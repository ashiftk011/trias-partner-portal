<?php
/**
 * Trias Partner Portal — Entry Point
 * Redirects to dashboard or login based on session state.
 */
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    $role = $_SESSION['user_role'] ?? '';
    if ($role === 'telecall') {
        header('Location: ' . BASE_URL . '/modules/leads/index.php');
    } elseif ($role === 'finance') {
        header('Location: ' . BASE_URL . '/modules/invoices/index.php');
    } else {
        header('Location: ' . BASE_URL . '/modules/dashboard/index.php');
    }
} else {
    header('Location: ' . BASE_URL . '/modules/auth/login.php');
}
exit;
