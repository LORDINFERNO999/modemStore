<?php
// ajax/cambiar-password.php — Cambio de contraseña del usuario en sesión
require_once '../includes/auth.php';
require_once '../includes/seguridad.php';
header('Content-Type: application/json');

requireLogin();
csrfRequire(true); // valida token CSRF; responde JSON si falla

$data      = json_decode(file_get_contents('php://input'), true) ?: [];
$actual    = $data['actual']    ?? $_POST['actual']    ?? '';
$nueva     = $data['nueva']     ?? $_POST['nueva']     ?? '';
$confirmar = $data['confirmar'] ?? $_POST['confirmar'] ?? '';

if ($actual === '' || $nueva === '' || $confirmar === '') {
    echo json_encode(['ok' => false, 'msg' => 'Completa todos los campos']); exit;
}
if (strlen($nueva) < 6) {
    echo json_encode(['ok' => false, 'msg' => 'La nueva contraseña debe tener al menos 6 caracteres']); exit;
}
if ($nueva !== $confirmar) {
    echo json_encode(['ok' => false, 'msg' => 'La nueva contraseña y su confirmación no coinciden']); exit;
}

$stmt = $pdo->prepare("SELECT password FROM usuarios WHERE id = ?");
$stmt->execute([$_SESSION['usuario_id']]);
$row = $stmt->fetch();

if (!$row || !password_verify($actual, $row['password'])) {
    echo json_encode(['ok' => false, 'msg' => 'La contraseña actual no es correcta']); exit;
}
if (password_verify($nueva, $row['password'])) {
    echo json_encode(['ok' => false, 'msg' => 'La nueva contraseña no puede ser igual a la actual']); exit;
}

$hash = password_hash($nueva, PASSWORD_DEFAULT);
$pdo->prepare("UPDATE usuarios SET password = ? WHERE id = ?")
    ->execute([$hash, $_SESSION['usuario_id']]);

echo json_encode(['ok' => true, 'msg' => 'Contraseña actualizada correctamente ✓']);
