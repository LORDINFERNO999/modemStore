<?php
// includes/funciones.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/whatsapp.php';

function registrarMovimiento(int $usuarioId, string $tipo, float $monto, ?int $referenciaId = null, string $descripcion = ''): bool {
    global $pdo;
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT saldo FROM usuarios WHERE id = ? FOR UPDATE");
        $stmt->execute([$usuarioId]);
        $user = $stmt->fetch();
        $saldoAnterior = (float)$user['saldo'];
        if ($tipo === 'compra') {
            $saldoNuevo = $saldoAnterior - $monto;
        } elseif (in_array($tipo, ['recarga', 'reembolso', 'ajuste'])) {
            $saldoNuevo = $saldoAnterior + $monto;
        } else {
            $pdo->rollBack(); return false;
        }
        if ($saldoNuevo < 0) { $pdo->rollBack(); return false; }
        $pdo->prepare("UPDATE usuarios SET saldo = ? WHERE id = ?")->execute([$saldoNuevo, $usuarioId]);
        $pdo->prepare("INSERT INTO movimientos_saldo (usuario_id, tipo, monto, saldo_anterior, saldo_nuevo, referencia_id, descripcion) VALUES (?,?,?,?,?,?,?)")
            ->execute([$usuarioId, $tipo, $monto, $saldoAnterior, $saldoNuevo, $referenciaId, $descripcion]);
        $_SESSION['saldo'] = $saldoNuevo;
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error registrarMovimiento: " . $e->getMessage());
        return false;
    }
}

/**
 * Crea una solicitud de compra (pago por transferencia).
 * No usa saldo: deja el pedido en estado 'pendiente' a la espera de que el
 * admin valide la transferencia y entregue las credenciales.
 */
function comprarPlan(int $usuarioId, int $planId, string $comprobante = ''): array {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT p.*, s.nombre as servicio_nombre FROM planes p JOIN servicios s ON p.servicio_id = s.id WHERE p.id = ? AND p.estado = 'activo'");
        $stmt->execute([$planId]);
        $plan = $stmt->fetch();
        if (!$plan) { return ['ok' => false, 'msg' => 'Plan no disponible']; }

        $dias = (int)($plan['duracion_dias'] ?: 30);
        $pdo->prepare("INSERT INTO pedidos (usuario_id, plan_id, monto, duracion_dias, comprobante, estado) VALUES (?,?,?,?,?, 'pendiente')")
            ->execute([$usuarioId, $planId, $plan['precio'], $dias, $comprobante ?: null]);
        $pedidoId = (int)$pdo->lastInsertId();

        crearNotificacion('compra', $usuarioId, $pedidoId,
            "Nueva compra pendiente: {$plan['servicio_nombre']} - {$plan['nombre']} (" . formatMoney((float)$plan['precio']) . ")");

        // Aviso al admin por WhatsApp
        if (function_exists('enviarWhatsApp')) {
            $u = $pdo->prepare("SELECT nombre, email FROM usuarios WHERE id=?");
            $u->execute([$usuarioId]);
            $ui = $u->fetch();
            $panelUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                      . '://' . ($_SERVER['HTTP_HOST'] ?? 'tu-sitio.com') . '/admin/pedidos.php';
            enviarWhatsApp(
                "🛒 *NUEVA COMPRA PENDIENTE*\n"
              . "━━━━━━━━━━━━━━━━━━\n"
              . "👤 Cliente: *" . ($ui['nombre'] ?? 'Cliente') . "*\n"
              . "📧 Correo: " . ($ui['email'] ?? '—') . "\n"
              . "📦 Servicio: *{$plan['servicio_nombre']}*\n"
              . "🎟️ Plan: {$plan['nombre']}\n"
              . "⏱️ Duración: {$dias} días\n"
              . "💵 Valor: *" . formatMoney((float)$plan['precio']) . "*\n"
              . "🧾 Pedido: #{$pedidoId}\n"
              . "🗓️ " . date('d/m/Y H:i') . "\n"
              . "━━━━━━━━━━━━━━━━━━\n"
              . "💳 Pago por transferencia — revisa el comprobante.\n"
              . "👉 Valida y entrega en:\n{$panelUrl}"
            );
        }

        return [
            'ok'        => true,
            'msg'       => '¡Solicitud enviada! Tu pedido quedó pendiente de validación. Te entregaremos los datos en breve.',
            'pedido_id' => $pedidoId,
            'plan'      => $plan,
        ];
    } catch (Exception $e) {
        error_log("comprarPlan: " . $e->getMessage());
        return ['ok' => false, 'msg' => 'Error al procesar la solicitud'];
    }
}

/**
 * Registra una notificación para el admin (campanita).
 */
function crearNotificacion(string $tipo, int $usuarioId, ?int $referenciaId, string $mensaje): void {
    global $pdo;
    try {
        $pdo->prepare("INSERT INTO notificaciones (tipo, usuario_id, referencia_id, mensaje) VALUES (?,?,?,?)")
            ->execute([$tipo, $usuarioId, $referenciaId, $mensaje]);
    } catch (Exception $e) {
        error_log("crearNotificacion: " . $e->getMessage());
    }
}

function formatMoney(float $amount): string {
    return '$ ' . number_format($amount, 0, ',', '.');
}

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'hace un momento';
    if ($diff < 3600) return 'hace ' . floor($diff / 60) . ' min';
    if ($diff < 86400) return 'hace ' . floor($diff / 3600) . ' h';
    return date('d/m/Y', strtotime($datetime));
}

function getServiceImage(string $nombre): string {
    $map = [
        'Netflix'     => 'netflix.png',
        'Spotify'     => 'spotify.png',
        'Disney+'     => 'disney.png',
        'Prime Video' => 'prime.png',
        'Apple TV+'   => 'appletv.png',
        'Max'         => 'max.png',
        'Crunchyroll' => 'crunchyroll.png',
        'Deezer'      => 'deezer.png',
        'ChatGPT'     => 'chatgpt.png',
    ];
    return $map[$nombre] ?? 'logo.png';
}