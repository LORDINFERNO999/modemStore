<?php
// ajax/recargar.php — Solicitud de recarga de saldo
// Evita que las advertencias corrompan el JSON de respuesta
@ini_set('display_errors', '0');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/funciones.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/whatsapp.php'; // ← Telegram

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

    // Datos del usuario (más confiable desde BD que desde la sesión)
    $stmtU = $pdo->prepare("SELECT nombre, email FROM usuarios WHERE id=?");
    $stmtU->execute([$_SESSION['usuario_id']]);
    $usuarioWA = $stmtU->fetch();

    $nombreCliente = $usuarioWA['nombre'] ?? ($_SESSION['nombre'] ?? 'Cliente');
    $emailCliente  = $usuarioWA['email']  ?? '';
    $montoFmt      = '$' . number_format($monto, 0, ',', '.');
    $comprobanteUrl = SITE_URL . '/' . $rutaRel;
    $esImagen = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true);

    // ── AUTO-APROBACIÓN NOCTURNA ──────────────────────────────────
    // Horario: 11 PM a 10 AM · Tope: $30.000 · Cliente de confianza:
    // al menos 1 recarga ya aprobada y sin rechazos en los últimos 30 días.
    $topeAuto = 30000;
    $hora = (int) date('G'); // 0-23
    $enHorarioNocturno = ($hora >= 23 || $hora < 10); // 23:00 → 09:59 (hasta las 10 AM)
    $autoAprobada = false;

    if ($enHorarioNocturno && $monto <= $topeAuto) {
        $stmtConf = $pdo->prepare("
            SELECT
                SUM(estado = 'aprobada') AS aprobadas,
                SUM(estado = 'rechazada' AND created_at >= (NOW() - INTERVAL 30 DAY)) AS rechazos_recientes
            FROM recargas WHERE usuario_id = ?
        ");
        $stmtConf->execute([$_SESSION['usuario_id']]);
        $conf = $stmtConf->fetch();

        $esConfiable = ((int)($conf['aprobadas'] ?? 0) >= 1) && ((int)($conf['rechazos_recientes'] ?? 0) === 0);

        if ($esConfiable) {
            $ok = registrarMovimiento((int)$_SESSION['usuario_id'], 'recarga', (float)$monto, $recargaId, 'Recarga auto-aprobada (nocturna)');
            if ($ok) {
                $pdo->prepare("UPDATE recargas SET estado = 'aprobada' WHERE id = ?")->execute([$recargaId]);
                $pdo->prepare("UPDATE notificaciones SET atendida = 1, leida = 1 WHERE tipo = 'recarga' AND referencia_id = ?")->execute([$recargaId]);
                $autoAprobada = true;
            }
        }
    }

    // Mensajes según el resultado
    if ($autoAprobada) {
        $respuestaMsg = '¡Recarga aprobada automáticamente! 🎉 Tu saldo ya fue actualizado. Refresca la página para verlo.';
        $mensajeTG = "✅ *RECARGA AUTO-APROBADA* (nocturna)\n"
                   . "━━━━━━━━━━━━━━━━━━\n"
                   . "👤 Cliente: *{$nombreCliente}*\n"
                   . "📧 Correo: " . ($emailCliente ?: '—') . "\n"
                   . "💵 Valor: *{$montoFmt} COP*\n"
                   . "🧾 Recarga: #{$recargaId}\n"
                   . "🗓️ " . date('d/m/Y H:i') . "\n"
                   . "━━━━━━━━━━━━━━━━━━\n"
                   . "Cliente de confianza · saldo acreditado. Colilla: {$comprobanteUrl}";
    } else {
        $respuestaMsg = '¡Solicitud enviada! Tu saldo se actualizará cuando el admin valide tu transferencia.';
        $mensajeTG = "💰 *NUEVA RECARGA PENDIENTE*\n"
                   . "━━━━━━━━━━━━━━━━━━\n"
                   . "👤 Cliente: *{$nombreCliente}*\n"
                   . "📧 Correo: " . ($emailCliente ?: '—') . "\n"
                   . "💵 Valor: *{$montoFmt} COP*\n"
                   . "🧾 Recarga: #{$recargaId}\n"
                   . "🗓️ " . date('d/m/Y H:i') . "\n"
                   . "━━━━━━━━━━━━━━━━━━\n"
                   . "👇 Revisa la colilla y aprueba o rechaza con los botones.";
    }

    // Responder al cliente con JSON limpio (sin manipular headers, para no romper la respuesta)
    echo json_encode([
        'ok'  => true,
        'msg' => $respuestaMsg,
        'recarga_id' => $recargaId,
        'auto_aprobada' => $autoAprobada,
    ]);

    // Cerrar la conexión si el servidor lo permite, para no esperar por Telegram
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    }

    // Aviso a Telegram (nunca debe afectar la respuesta al cliente)
    try {
        if ($autoAprobada) {
            enviarWhatsApp($mensajeTG); // ya aprobada → aviso informativo, sin botones
        } elseif (function_exists('enviarRecargaTelegram')) {
            enviarRecargaTelegram($recargaId, $mensajeTG, $comprobanteUrl, $esImagen);
        } else {
            enviarWhatsApp($mensajeTG);
        }
    } catch (\Throwable $e) {
        error_log('recargar.php Telegram: ' . $e->getMessage());
    }
    exit;

} catch (\Throwable $e) {
    error_log("ajax/recargar.php: " . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Error al procesar la solicitud. Intenta de nuevo.']);
}