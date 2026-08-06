<?php
require_once __DIR__ . '/../core/Auth.php';

if (!\Auth::check()) {
    header('Location: login.php');
} elseif (\Auth::isAdmin()) {
    header('Location: ../admin/index.php'); // el admin entra directo a su panel
} else {
    header('Location: dashboard.php');
}
exit;
