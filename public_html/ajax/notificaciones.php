<?php
// ajax/notificaciones.php — Campanita del admin (contar / listar / marcar leídas)
require_once '../includes/auth.php';
require_once '../includes/seguridad.php';
header('Content-Type: application/json');

requireAdmin();

$accion = $_REQUEST['accion'] ?? 'contar';

if ($accion === 'contar') {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM notificaciones WHERE leida = 0")->fetchColumn();
    echo json_encode(['ok' => true, 'count' => $n]);
    exit;
}

if ($accion === 'listar') {
    $rows = $pdo->query("
        SELECT n.*, u.nombre AS cliente
        FROM notificaciones n
        JOIN usuarios u ON n.usuario_id = u.id
        ORDER BY n.created_at DESC LIMIT 15
    ")->fetchAll();
    echo json_encode(['ok' => true, 'items' => $rows]);
    exit;
}

if ($accion === 'marcar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequire(true);
    $pdo->query("UPDATE notificaciones SET leida = 1 WHERE leida = 0");
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Acción no válida']);
