<?php
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/Csrf.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../services/CacheService.php';
require_once __DIR__ . '/../../services/ImapService.php';
require_once __DIR__ . '/../../services/LogService.php';
require_once __DIR__ . '/../../config/init.php';

header('Content-Type: application/json; charset=utf-8');

\Auth::requireLogin();
\Csrf::requireValid();

$mailboxId = filter_input(INPUT_POST, 'mailbox_id', FILTER_VALIDATE_INT);
if (!$mailboxId) {
    echo json_encode(['success' => false, 'message' => 'Cuenta inválida.']);
    exit;
}

// Ventana de búsqueda en minutos, acotada al máximo permitido por el config.
$maxWindow = defined('IMAP_SEARCH_WINDOW_MINUTES_MAX') ? IMAP_SEARCH_WINDOW_MINUTES_MAX : 120;
$window = filter_input(INPUT_POST, 'window', FILTER_VALIDATE_INT) ?: IMAP_SEARCH_WINDOW_MINUTES;
$window = max(1, min($window, $maxWindow));

$db = Database::get();

// 1) Verificar que el usuario tiene permiso sobre esta cuenta
$stmt = $db->prepare(
    'SELECT m.* FROM mailboxes m
     INNER JOIN user_mailbox_access uma ON uma.mailbox_id = m.id
     WHERE m.id = ? AND uma.user_id = ? AND m.active = 1
     LIMIT 1'
);
$stmt->execute([$mailboxId, \Auth::id()]);
$mailbox = $stmt->fetch();

if (!$mailbox) {
    LogService::record(\Auth::id(), $mailboxId, 'denied');
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No tienes acceso a esta cuenta.']);
    exit;
}

// Plataforma a buscar: la que elija el usuario (si es válida), o la del buzón.
// Permite que un mismo correo se consulte para distintos servicios.
$service = trim((string) filter_input(INPUT_POST, 'service', FILTER_SANITIZE_SPECIAL_CHARS));
if ($service === '' || !CodeExtractor::isSupported($service)) {
    $service = $mailbox['service_type'];
}
$mailbox['service_type'] = $service; // ImapService usará estas reglas

// 1b) Permiso por plataforma: si el admin restringió al usuario, validar.
//     Sin filas => puede todas; con filas => solo esas.
try {
    $permStmt = $db->prepare('SELECT service_key FROM user_platform_access WHERE user_id = ?');
    $permStmt->execute([\Auth::id()]);
    $allowedPlatforms = array_column($permStmt->fetchAll(), 'service_key');
} catch (\PDOException $e) {
    $allowedPlatforms = [];
}
if (!empty($allowedPlatforms) && !in_array($service, $allowedPlatforms, true)) {
    LogService::record(\Auth::id(), $mailboxId, 'denied');
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No tienes permiso para consultar esta plataforma.']);
    exit;
}

// 2) Intentar servir desde caché (evita golpear IMAP en cada clic)
$cached = CacheService::getFresh($mailboxId, $service);
$validSeconds = CODE_VALID_SECONDS;

if ($cached) {
    $result = $cached['code']
        ? ['type' => 'code', 'code' => $cached['code']]
        : ($cached['message_type'] === 'travel' ? ['type' => 'travel', 'code' => null] : null);
    // Segundos restantes reales según cuándo se cacheó el código
    if ($result && $result['type'] === 'code') {
        $validSeconds = CacheService::remainingSeconds($cached['valid_until']);
    }
} else {
    // Aviso claro si la extensión IMAP no está habilitada (típico en XAMPP local)
    if (!function_exists('imap_open')) {
        LogService::record(\Auth::id(), $mailboxId, 'error');
        echo json_encode([
            'success' => false,
            'message' => 'La extensión IMAP de PHP no está habilitada. Actívala en php.ini (extension=imap) y reinicia el servidor.',
        ]);
        exit;
    }

    // 3) Consultar IMAP solo si el caché venció
    try {
        $result = ImapService::fetchLatest($mailbox, $window);
    } catch (\Throwable $e) {
        error_log('ImapService error: ' . $e->getMessage());
        LogService::record(\Auth::id(), $mailboxId, 'error');
        echo json_encode(['success' => false, 'message' => 'No se pudo conectar al correo. Intenta más tarde.']);
        exit;
    }
    // Guardamos en caché el resultado (incluso si fue null, para no reintentar de inmediato)
    CacheService::store($mailboxId, $service, $result['code'] ?? null, $result['type'] ?? 'code');
}

if (!$result) {
    LogService::record(\Auth::id(), $mailboxId, 'no_code');
    echo json_encode(['success' => true, 'type' => 'none']);
    exit;
}

LogService::record(\Auth::id(), $mailboxId, 'success');

echo json_encode([
    'success'       => true,
    'type'          => $result['type'],
    'code'          => $result['code'],
    'valid_seconds' => $validSeconds,
]);