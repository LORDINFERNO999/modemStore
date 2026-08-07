<?php
// ajax/recargar.php — Solicitud de recarga de saldo
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/funciones.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/whatsapp.php'; // ← WhatsApp

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'msg' => 'Debes iniciar sesión']);
    exit;
}

$token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!csrfCheck($token)) {
    echo json_encode(['ok' => false, 'msg' => 'Token de seguridad inválido. Recarga la página e intenta de nuevo.']);
    exit;
}

// Limpia separadores de miles ("8.000" → 8000, "$20.000" → 20000)
$monto = (float) preg_replace('/[^0-9]/', '', (string)($_POST['monto'] ?? '0'));
if ($monto < 5000) {
    echo json_encode(['ok' => false, 'msg' => 'El monto mínimo de recarga es $5.000 COP']); exit;
}
if ($monto > 500000) {
    echo json_encode(['ok' => false, 'msg' => 'El monto máximo por recarga es $500.000 COP']); exit;
}

if (empty($_FILES['comprobante']['name']) || ($_FILES['comprobante']['error'] ?? 1) !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'msg' => 'Debes adjuntar el comprobante de la transferencia']); exit;
}

$file = $_FILES['comprobante'];
$ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'])) {
    echo json_encode(['ok' => false, 'msg' => 'Formato no válido. Sube una imagen (JPG/PNG/WEBP) o PDF.']); exit;
}
if ($file['size'] > 5 * 1024 * 1024) {
    echo json_encode(['ok' => false, 'msg' => 'El archivo supera el máximo de 5 MB']); exit;
}

// ── Validaciones anti-duplicado (ANTES de guardar el archivo) ──
$stmtPend = $pdo->prepare("SELECT COUNT(*) FROM recargas WHERE usuario_id = ? AND estado = 'pendiente'");
$stmtPend->execute([$_SESSION['usuario_id']]);
if ((int)$stmtPend->fetchColumn() >= 3) {
    echo json_encode(['ok' => false, 'msg' => 'Ya tienes 3 recargas pendientes de validación. Espera a que las aprobemos antes de enviar otra.']); exit;
}

// Evita reenvíos del MISMO valor: misma persona, mismo monto, pendiente y reciente (últimos 15 min).
// Así, si el cliente no ve el saldo y reenvía el comprobante, no se crean recargas duplicadas.
$stmtDup = $pdo->prepare("SELECT COUNT(*) FROM recargas WHERE usuario_id = ? AND monto = ? AND estado = 'pendiente' AND created_at >= (NOW() - INTERVAL 15 MINUTE)");
$stmtDup->execute([$_SESSION['usuario_id'], $monto]);
if ((int)$stmtDup->fetchColumn() > 0) {
    echo json_encode(['ok' => false, 'msg' => 'Ya recibimos tu recarga de ' . formatMoney($monto) . ' y está pendiente de validación. ¡No la envíes de nuevo! En unos minutos refresca la página para ver tu saldo actualizado.']); exit;
}

// Nombre aleatorio (no adivinable) para proteger comprobantes de otros clientes
$nombre  = 'recarga_' . $_SESSION['usuario_id'] . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
$destAbs = __DIR__ . '/../assets/comprobantes/' . $nombre;
$rutaRel = 'assets/comprobantes/' . $nombre;

if (!move_uploaded_file($file['tmp_name'], $destAbs)) {
    echo json_encode(['ok' => false, 'msg' => 'No se pudo guardar el comprobante. Intenta de nuevo.']); exit;
}

try {
    $pdo->prepare("INSERT INTO recargas (usuario_id, monto, comprobante, estado) VALUES (?, ?, ?, 'pendiente')")
        ->execute([$_SESSION['usuario_id'], $monto, $rutaRel]);
    $recargaId = (int)$pdo->lastInsertId();

    crearNotificacion('recarga', $_SESSION['usuario_id'], $recargaId,
        "Nueva recarga pendiente: " . formatMoney($monto) . " — " . ($_SESSION['nombre'] ?? 'Usuario'));

    // ── Aviso WhatsApp al admin ──────────────────────────────────
    // Leer datos del usuario directo de BD (más confiable que sesión)
    $stmtU = $pdo->prepare("SELECT nombre, email FROM usuarios WHERE id=?");
    $stmtU->execute([$_SESSION['usuario_id']]);
    $usuarioWA = $stmtU->fetch();

    $nombreCliente = $usuarioWA['nombre'] ?? ($_SESSION['nombre'] ?? 'Cliente');
    $emailCliente  = $usuarioWA['email']  ?? '';
    $montoFmt      = '$' . number_format($monto, 0, ',', '.');

    $mensajeTG = "💰 *NUEVA RECARGA PENDIENTE*\n"
               . "━━━━━━━━━━━━━━━━━━\n"
               . "👤 Cliente: *{$nombreCliente}*\n"
               . "📧 Correo: " . ($emailCliente ?: '—') . "\n"
               . "💵 Valor: *{$montoFmt} COP*\n"
               . "🧾 Recarga: #{$recargaId}\n"
               . "🗓️ " . date('d/m/Y H:i') . "\n"
               . "━━━━━━━━━━━━━━━━━━\n"
               . "👇 Revisa la colilla y aprueba o rechaza con los botones.";

    $comprobanteUrl = SITE_URL . '/' . $rutaRel;
    $esImagen = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true);

    // 1) Responder al cliente DE INMEDIATO (no lo hacemos esperar por Telegram)
    echo json_encode([
        'ok'  => true,
        'msg' => '¡Solicitud enviada! Tu saldo se actualizará cuando el admin valide tu transferencia.',
        'recarga_id' => $recargaId,
    ]);

    // 2) Cerrar la conexión con el navegador para que no espere la descarga de la foto en Telegram
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    }

    // 3) Ya sin el cliente esperando, se envía el aviso con la colilla + botones a Telegram
    if (function_exists('enviarRecargaTelegram')) {
        enviarRecargaTelegram($recargaId, $mensajeTG, $comprobanteUrl, $esImagen);
    } else {
        enviarWhatsApp($mensajeTG); // respaldo por si acaso
    }
    exit;

} catch (Exception $e) {
    error_log("ajax/recargar.php: " . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Error al procesar la solicitud. Intenta de nuevo.']);
}