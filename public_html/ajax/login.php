<?php
// ajax/login.php — Inicio de sesión vía JSON
require_once '../includes/auth.php';
require_once '../includes/seguridad.php';
header('Content-Type: application/json');

if (isLoggedIn()) {
    echo json_encode(['ok' => true, 'rol' => $_SESSION['rol'] ?? 'cliente']);
    exit;
}

// ── Anti fuerza-bruta (mismo mecanismo que login.php) ──
[$permitido, $espera] = loginThrottleCheck();
if (!$permitido) {
    echo json_encode(['ok' => false, 'msg' => 'Demasiados intentos fallidos. Espera ' . ceil($espera / 60) . ' min e inténtalo de nuevo.']);
    exit;
}

// Acepta tanto JSON como formulario tradicional
$data     = json_decode(file_get_contents('php://input'), true) ?: [];
$email    = trim($data['email'] ?? $_POST['email'] ?? '');
$password = $data['password'] ?? $_POST['password'] ?? '';

if (!$email || !$password) {
    echo json_encode(['ok' => false, 'msg' => 'Completa todos los campos']);
    exit;
}

$res = login($email, $password);
if (!empty($res['ok'])) {
    loginResetFails();
} else {
    loginRegisterFail();
}
echo json_encode($res);