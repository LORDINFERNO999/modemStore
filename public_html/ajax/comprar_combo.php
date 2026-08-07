<?php
// ajax/comprar_combo.php  — Procesa la compra de un combo
require_once '../includes/auth.php';
require_once '../includes/seguridad.php';
require_once '../includes/whatsapp.php';
require_once '../includes/funciones.php';
requireLogin();
csrfRequire();
header('Content-Type: application/json');

$usuarioId = (int)$_SESSION['usuario_id'];
$comboId   = (int)($_POST['combo_id'] ?? 0);

if (!$comboId) {
    echo json_encode(['ok'=>false,'msg'=>'Combo inválido']); exit;
}

// Datos del combo
$stmt = $pdo->prepare("SELECT * FROM combos WHERE id=? AND estado='activo'");
$stmt->execute([$comboId]);
$combo = $stmt->fetch();
if (!$combo) {
    echo json_encode(['ok'=>false,'msg'=>'Combo no disponible']); exit;
}

// Planes del combo
$stmtPl = $pdo->prepare("
    SELECT p.*, s.nombre as servicio_nombre
    FROM combo_planes cp
    JOIN planes p ON cp.plan_id = p.id
    JOIN servicios s ON p.servicio_id = s.id
    WHERE cp.combo_id = ?
");
$stmtPl->execute([$comboId]);
$planes = $stmtPl->fetchAll();
if (count($planes) < 1) {
    echo json_encode(['ok'=>false,'msg'=>'El combo no tiene planes configurados']); exit;
}

// ── VERIFICACIÓN DE STOCK POR CADA PLAN DEL COMBO ────────────────
foreach ($planes as $plan) {
    $stmtStock = $pdo->prepare("
        SELECT COUNT(*) FROM cuentas_stock
        WHERE plan_id = ? AND estado = 'disponible'
    ");
    $stmtStock->execute([$plan['id']]);
    $stockDisp = (int)$stmtStock->fetchColumn();

    if ($stockDisp === 0) {
        echo json_encode([
            'ok'  => false,
            'msg' => "⚠️ El plan \"{$plan['servicio_nombre']} — {$plan['nombre']}\" del combo está agotado. No se puede comprar el combo en este momento."
        ]);
        exit;
    }
}
// ──────────────────────────────────────────────────────────────────

// Usuario y saldo
$stmtU = $pdo->prepare("SELECT saldo, es_revendedor FROM usuarios WHERE id=?");
$stmtU->execute([$usuarioId]);
$usuario = $stmtU->fetch();

$esRev       = !empty($usuario['es_revendedor']);
$precioFinal = ($esRev && $combo['precio_revendedor'] > 0)
               ? (float)$combo['precio_revendedor']
               : (float)$combo['precio'];
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

    // UUID para agrupar los pedidos de este combo
    $grupoUUID = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));

    $fechaVenc = date('Y-m-d H:i:s', strtotime('+' . (int)$combo['duracion_dias'] . ' days'));

    // Crear un pedido por cada plan del combo
    $stmtIns = $pdo->prepare("
        INSERT INTO pedidos
            (usuario_id, plan_id, combo_id, combo_grupo, monto, estado,
             duracion_dias, fecha_vencimiento, cred_usuario, cred_password, cred_perfil, cred_pin, nota_admin)
        VALUES (?, ?, ?, ?, ?, 'pendiente', ?, ?, NULL, NULL, NULL, NULL, ?)
    ");

    // Monto prorrateado entre los planes
    $totalPrecioPlanes = array_sum(array_column($planes, 'precio')) ?: 1;
    $hayPendiente = false;

    foreach ($planes as $plan) {
        $montoPlan = round($precioFinal * ($plan['precio'] / $totalPrecioPlanes), 2);
        $notaAdmin = "Combo: {$combo['nombre']}";

        // Intentar asignar cuenta del stock automáticamente (con bloqueo anti doble-venta)
        $stmtStk = $pdo->prepare("
            SELECT id, email_cuenta, password_cuenta, perfil, pin FROM cuentas_stock
            WHERE plan_id=? AND estado='disponible'
            ORDER BY id ASC LIMIT 1
            FOR UPDATE
        ");
        $stmtStk->execute([$plan['id']]);
        $cuenta = $stmtStk->fetch();

        $credU = null; $credP = null; $credPerfil = null; $credPin = null;
        $estadoPedido = 'pendiente';

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
            }
        }

        $stmtIns->execute([
            $usuarioId, $plan['id'], $comboId, $grupoUUID, $montoPlan,
            $combo['duracion_dias'], $fechaVenc, $notaAdmin,
        ]);
        $pedidoId = (int)$pdo->lastInsertId();

        if ($estadoPedido === 'entregado') {
            $pdo->prepare("UPDATE pedidos SET estado='entregado', fecha_entrega=NOW(), cred_usuario=?, cred_password=?, cred_perfil=?, cred_pin=? WHERE id=?")
                ->execute([$credU, $credP, $credPerfil, $credPin, $pedidoId]);
        } else {
            $hayPendiente = true;
        }
    }

    // Registrar movimiento de saldo (consistente con el resto del sistema)
    $pdo->prepare("INSERT INTO movimientos_saldo (usuario_id, tipo, monto, saldo_anterior, saldo_nuevo, referencia_id, descripcion) VALUES (?,?,?,?,?,?,?)")
        ->execute([$usuarioId, 'compra', $precioFinal, $saldo, $nuevoSaldo, null, "Compra combo: {$combo['nombre']}"]);

    $pdo->commit();

    // Campanita del admin: registrar la compra del combo
    $estadoTxt = $hayPendiente ? 'con planes pendientes de entrega' : 'entregada automáticamente';
    crearNotificacion('compra', $usuarioId, $pedidoId,
        "Nueva compra de combo ($estadoTxt): {$combo['nombre']} (" . number_format($precioFinal, 0, '.', '.') . ")");

    // Si alguno de los planes del combo quedó PENDIENTE de entregar, avisar al admin
    if ($hayPendiente) {
        $u = $pdo->prepare("SELECT nombre, email FROM usuarios WHERE id=?");
        $u->execute([$usuarioId]);
        $ui = $u->fetch();
        $panelUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                  . '://' . ($_SERVER['HTTP_HOST'] ?? 'tu-sitio.com') . '/admin/pedidos.php';
        enviarWhatsApp(
            "🎁 *Nueva compra de combo pendiente de entregar*\n"
          . "👤 Solicitada por: *" . ($ui['nombre'] ?? 'Cliente') . "*\n"
          . "🎁 Combo: {$combo['nombre']}\n"
          . "💵 Valor: *$" . number_format($precioFinal, 0, '.', '.') . " COP*\n"
          . "🔗 Entrégala aquí: {$panelUrl}"
        );
    }

    echo json_encode([
        'ok'          => true,
        'msg'         => "✓ Combo \"{$combo['nombre']}\" comprado. Revisa tus pedidos.",
        'saldo_nuevo' => $nuevoSaldo,
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['ok'=>false,'msg'=>'Error al procesar la compra: '.$e->getMessage()]);
}