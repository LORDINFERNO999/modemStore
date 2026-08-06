<?php
// ajax/agregar-stock.php — Agregar una cuenta al stock de un plan (solo admin)
require_once '../includes/auth.php';
require_once '../includes/seguridad.php';
header('Content-Type: application/json');

requireAdmin();
csrfRequire(true);

$data            = json_decode(file_get_contents('php://input'), true) ?: [];
$planId          = (int)($data['plan_id']         ?? $_POST['plan_id']         ?? 0);
$emailCuenta     = trim($data['email_cuenta']     ?? $_POST['email_cuenta']    ?? '');
$passwordCuenta  = trim($data['password_cuenta']  ?? $_POST['password_cuenta'] ?? '');
$perfil          = trim($data['perfil']           ?? $_POST['perfil']          ?? '');
$pin             = trim($data['pin']              ?? $_POST['pin']             ?? '');

if (!$planId || !$emailCuenta) {
    echo json_encode(['ok' => false, 'msg' => 'Plan y email de la cuenta son obligatorios']);
    exit;
}

// Verificar que el plan exista
$stmt = $pdo->prepare("SELECT id FROM planes WHERE id = ?");
$stmt->execute([$planId]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'msg' => 'El plan no existe']);
    exit;
}

$pdo->prepare("
    INSERT INTO cuentas_stock (plan_id, email_cuenta, password_cuenta, perfil, pin, estado)
    VALUES (?, ?, ?, ?, ?, 'disponible')
")->execute([$planId, $emailCuenta, $passwordCuenta, $perfil ?: null, $pin ?: null]);

echo json_encode(['ok' => true, 'msg' => 'Cuenta agregada al stock ✓', 'id' => $pdo->lastInsertId()]);
