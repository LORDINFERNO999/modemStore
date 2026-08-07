<?php
// ajax/comprar.php — Procesa la compra de un plan individual
require_once '../includes/auth.php';
require_once '../includes/seguridad.php';
require_once '../includes/whatsapp.php';
require_once '../includes/funciones.php';
requireLogin();
csrfRequire();
header('Content-Type: application/json');

$usuarioId = (int)$_SESSION['usuario_id'];
$planId    = (int)($_POST['plan_id'] ?? 0);

if (!$planId) {
    echo json_encode(['ok'=>false,'msg'=>'Plan inválido']); exit;
}

// Datos del plan
$stmt = $pdo->prepare("
    SELECT p.*, s.nombre as servicio_nombre
    FROM planes p
    JOIN servicios s ON p.servicio_id = s.id
    WHERE p.id = ? AND p.estado = 'activo'
");
$stmt->execute([$planId]);
$plan = $stmt->fetch();
if (!$plan) {
    echo json_encode(['ok'=>false,'msg'=>'Plan no disponible']); exit;
}

// ── VERIFICACIÓN DE STOCK ─────────────────────────────────────────
$stmtStock = $pdo->prepare("
    SELECT COUNT(*) FROM cuentas_stock
    WHERE plan_id = ? AND estado = 'disponible'
");
$stmtStock->execute([$planId]);
$stockDisp = (int)$stmtStock->fetchColumn();

if ($stockDisp === 0) {
    echo json_encode([
        'ok'  => false,
        'msg' => '⚠️ Este plan está agotado en este momento. Pronto habrá más stock disponible.'
    ]);
    exit;
}
// ──────────────────────────────────────────────────────────────────

// Usuario y saldo
$stmtU = $pdo->prepare("SELECT saldo, es_revendedor FROM usuarios WHERE id=?");
$stmtU->execute([$usuarioId]);
$usuario = $stmtU->fetch();

$esRev       = !empty($usuario['es_revendedor']);
$precioFinal = ($esRev && $plan['precio_revendedor'] !== null && (float)$plan['precio_revendedor'] > 0)
               ? (float)$plan['precio_revendedor']
               : (float)$plan['precio'];
$saldo       = (float)$usuario['saldo'];

if ($saldo < $precioFinal) {
    $falta = $precioFinal - $saldo;
    echo json_encode(['ok'=>false,'msg'=>'Saldo insuficiente. Te faltan $'.number_format($falta,0,'.','.')]); exit;
}

// Todo OK → transacción
$pdo->beginTransaction();
try {
    // Descontar saldo con condición de concurrencia
    $stmtDeb = $pdo->prepare("UPDATE usuarios SET saldo = saldo - ? WHERE id = ? AND saldo >= ?");
    $stmtDeb->execute([$precioFinal, $usuarioId, $precioFinal]);

    // Si no se afectó ninguna fila, el saldo era insuficiente (o cambió en paralelo)
    if ($stmtDeb->rowCount() === 0) {
        throw new Exception('Saldo insuficiente');
    }

    // Saldo real tras el descuento (para el reporte de movimientos)
    $stmtV = $pdo->prepare("SELECT saldo FROM usuarios WHERE id=?");
    $stmtV->execute([$usuarioId]);
    $nuevoSaldo = (float)$stmtV->fetchColumn();

    $fechaVenc = date('Y-m-d H:i:s', strtotime('+' . (int)$plan['duracion_dias'] . ' days'));

    // Intentar asignar cuenta del stock automáticamente (con bloqueo anti doble-venta)
    $stmtSt = $pdo->prepare("
        SELECT id, email_cuenta, password_cuenta, perfil, pin FROM cuentas_stock
        WHERE plan_id = ? AND estado = 'disponible'
        ORDER BY id ASC LIMIT 1
        FOR UPDATE
    ");
    $stmtSt->execute([$planId]);
    $cuenta = $stmtSt->fetch();

    $credU = null; $credP = null; $credPerfil = null; $credPin = null;
    $estadoPedido = 'pendiente';
    $fechaEntrega = null;

    if ($cuenta) {
        // Marcar como vendida SOLO si sigue disponible (evita entregar la misma cuenta 2 veces)
        $stmtVend = $pdo->prepare("UPDATE cuentas_stock SET estado='vendida' WHERE id=? AND estado='disponible'");
        $stmtVend->execute([$cuenta['id']]);
        if ($stmtVend->rowCount() === 1) {
            $credU      = $cuenta['email_cuenta'];
            $credP      = $cuenta['password_cuenta'];
            $credPerfil = $cuenta['perfil'];
            $credPin    = $cuenta['pin'];
            $estadoPedido = 'entregado';
            $fechaEntrega = date('Y-m-d H:i:s'); // se entrega ahora mismo (para el reporte de ventas)
        }
    }

    // Insertar pedido
    $stmtPed = $pdo->prepare("
        INSERT INTO pedidos
            (usuario_id, plan_id, monto, estado, duracion_dias, fecha_vencimiento, fecha_entrega,
             cred_usuario, cred_password, cred_perfil, cred_pin)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtPed->execute([
        $usuarioId, $planId, $precioFinal, $estadoPedido,
        $plan['duracion_dias'], $fechaVenc, $fechaEntrega,
        $credU, $credP, $credPerfil, $credPin
    ]);
    $pedidoId = (int)$pdo->lastInsertId();

    // Registrar movimiento de saldo (consistente con el resto del sistema)
    $pdo->prepare("INSERT INTO movimientos_saldo (usuario_id, tipo, monto, saldo_anterior, saldo_nuevo, referencia_id, descripcion) VALUES (?,?,?,?,?,?,?)")
        ->execute([$usuarioId, 'compra', $precioFinal, $saldo, $nuevoSaldo, $pedidoId, "Compra: {$plan['servicio_nombre']} — {$plan['nombre']}"]);

    $pdo->commit();

    // Campanita del admin: registrar la compra (entregada o pendiente)
    $estadoTxt = $estadoPedido === 'entregado' ? 'entregada automáticamente' : 'pendiente de entrega';
    crearNotificacion('compra', $usuarioId, $pedidoId,
        "Nueva compra ($estadoTxt): {$plan['servicio_nombre']} — {$plan['nombre']} (" . number_format($precioFinal, 0, '.', '.') . ")");

    // Si quedó PENDIENTE (no había cuenta en stock para entregar), avisar al admin por WhatsApp
    if ($estadoPedido === 'pendiente') {
        $u = $pdo->prepare("SELECT nombre, email FROM usuarios WHERE id=?");
        $u->execute([$usuarioId]);
        $ui = $u->fetch();
        $panelUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                  . '://' . ($_SERVER['HTTP_HOST'] ?? 'tu-sitio.com') . '/admin/pedidos.php';
        enviarWhatsApp(
            "🛒 *Nueva compra pendiente de entregar*\n"
          . "👤 Solicitada por: *" . ($ui['nombre'] ?? 'Cliente') . "*\n"
          . "📦 {$plan['servicio_nombre']} — {$plan['nombre']}\n"
          . "💵 Valor: *$" . number_format($precioFinal, 0, '.', '.') . " COP*\n"
          . "🔗 Entrégala aquí: {$panelUrl}"
        );
    }

    echo json_encode([
        'ok'          => true,
        'msg'         => "✓ \"{$plan['servicio_nombre']} — {$plan['nombre']}\" comprado. Revisa tus pedidos.",
        'saldo_nuevo' => $nuevoSaldo,
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['ok'=>false,'msg'=>'Error al procesar la compra: '.$e->getMessage()]);
}