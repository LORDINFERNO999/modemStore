<?php
// ajax/registro.php — Registro de usuario vía JSON
require_once '../includes/auth.php';
require_once '../includes/seguridad.php';
header('Content-Type: application/json');

// Protección CSRF (evita creación masiva de cuentas por bots)
csrfRequire(true);

$data     = json_decode(file_get_contents('php://input'), true) ?: [];
$nombre   = trim($data['nombre']   ?? $_POST['nombre']   ?? '');
$apellido = trim($data['apellido'] ?? $_POST['apellido'] ?? '');
$email    = trim($data['email']    ?? $_POST['email']    ?? '');
$password = $data['password'] ?? $_POST['password'] ?? '';
$confirm  = $data['confirm']  ?? $_POST['confirm']  ?? $password;

if (!$nombre || !$apellido || !$email || !$password) {
    echo json_encode(['ok' => false, 'msg' => 'Completa todos los campos']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'msg' => 'Ingresa un correo electrónico válido']);
    exit;
}
if ($password !== $confirm) {
    echo json_encode(['ok' => false, 'msg' => 'Las contraseñas no coinciden']);
    exit;
}
if (strlen($password) < 6) {
    echo json_encode(['ok' => false, 'msg' => 'La contraseña debe tener al menos 6 caracteres']);
    exit;
}

$stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    echo json_encode(['ok' => false, 'msg' => 'Este correo ya está registrado']);
    exit;
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO usuarios (nombre, apellido, email, password) VALUES (?,?,?,?)")
    ->execute([$nombre, $apellido, $email, $hash]);

echo json_encode(['ok' => true, 'msg' => '¡Cuenta creada correctamente! Ya puedes iniciar sesión.']);