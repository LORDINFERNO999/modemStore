<?php
require_once 'includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

session_destroy();
header('Location: ' . SITE_URL . '/index.php');
exit;