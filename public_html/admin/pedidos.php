<?php
// admin/pedidos.php — Gestión de pedidos y entrega manual de credenciales
require_once '../includes/auth.php';
require_once '../includes/seguridad.php';
require_once '../includes/funciones.php';
requireAdmin();

$msg = ''; $msgTipo = 'ok';

// Entregar pedido: guardar usuario/contraseña/perfil/pin, marcar entregado y fijar vencimiento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'entregar') {
    csrfRequire();
    $pedidoId   = (int)($_POST['pedido_id'] ?? 0);
    $credUser   = trim($_POST['cred_usuario'] ?? '');
    $credPass   = trim($_POST['cred_password'] ?? '');
    $credPerfil = trim($_POST['cred_perfil'] ?? '');
    $credPin    = trim($_POST['cred_pin'] ?? '');

    if ($pedidoId && $credUser !== '' && $credPass !== '') {
        $stmt = $pdo->prepare("SELECT * FROM pedidos WHERE id = ?");
        $stmt->execute([$pedidoId]);
        $ped = $stmt->fetch();

        if ($ped && in_array($ped['estado'], ['pendiente'])) {
            $dias = (int)($ped['duracion_dias'] ?: 30);
            $pdo->prepare("
                UPDATE pedidos
                SET cred_usuario = ?, cred_password = ?, cred_perfil = ?, cred_pin = ?, estado = 'entregado',
                    fecha_entrega = NOW(), fecha_vencimiento = DATE_ADD(NOW(), INTERVAL ? DAY)
                WHERE id = ?
            ")->execute([$credUser, $credPass, ($credPerfil !== '' ? $credPerfil : null), ($credPin !== '' ? $credPin : null), $dias, $pedidoId]);

            // Marcar como atendida la notificación de esta compra
            $pdo->prepare("UPDATE notificaciones SET atendida = 1, leida = 1 WHERE tipo = 'compra' AND referencia_id = ?")
                ->execute([$pedidoId]);

            $msg = "Pedido #$pedidoId entregado ✓ (vence en $dias días)";
        } else {
            $msg = 'El pedido no existe o ya fue entregado'; $msgTipo = 'err';
        }
    } else {
        $msg = 'Usuario y contraseña son obligatorios'; $msgTipo = 'err';
    }
}

// Rechazar pedido (transferencia no válida) — no hay saldo que devolver
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'rechazar') {
    csrfRequire();
    $pedidoId = (int)($_POST['pedido_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM pedidos WHERE id = ?");
    $stmt->execute([$pedidoId]);
    $ped = $stmt->fetch();
    $nota = trim($_POST['nota'] ?? '');
    if ($ped && $ped['estado'] === 'pendiente') {
        $pdo->prepare("UPDATE pedidos SET estado = 'cancelado', nota_admin = ? WHERE id = ?")
            ->execute([$nota !== '' ? $nota : 'La transferencia no pudo ser validada.', $pedidoId]);
        $pdo->prepare("UPDATE notificaciones SET atendida = 1, leida = 1 WHERE tipo = 'compra' AND referencia_id = ?")->execute([$pedidoId]);
        $msg = "Pedido #$pedidoId rechazado";
    } else {
        $msg = 'Solo se pueden rechazar pedidos pendientes'; $msgTipo = 'err';
    }
}

// Eliminar uno o varios pedidos (borrado definitivo)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {
    csrfRequire();
    $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['ids'] ?? []))));

    if ($ids) {
        $place = implode(',', array_fill(0, count($ids), '?'));
        // Limpiamos primero las notificaciones asociadas a esos pedidos
        $pdo->prepare("DELETE FROM notificaciones WHERE tipo = 'compra' AND referencia_id IN ($place)")
            ->execute($ids);

        $stmt = $pdo->prepare("DELETE FROM pedidos WHERE id IN ($place)");
        $stmt->execute($ids);
        $n = $stmt->rowCount();

        $msg = $n === 1 ? "Pedido eliminado ✓" : "$n pedidos eliminados ✓";
    } else {
        $msg = 'No se seleccionó ningún pedido para eliminar'; $msgTipo = 'err';
    }
}

// Marcar como vencidos los pedidos cuyo plazo ya pasó (se recalcula en cada carga)
$pdo->query("UPDATE pedidos SET estado = 'vencido' WHERE estado = 'entregado' AND fecha_vencimiento IS NOT NULL AND fecha_vencimiento < NOW()");

// Filtro por estado
$filtro = $_GET['estado'] ?? '';
$where = ''; $params = [];
if (in_array($filtro, ['pendiente','entregado','vencido','cancelado'])) {
    $where = "WHERE p.estado = ?"; $params[] = $filtro;
}

$pedidos = $pdo->prepare("
    SELECT p.*, u.nombre AS cliente_nombre, u.apellido AS cliente_apellido, u.email AS cliente_email,
           pl.nombre AS plan_nombre, s.nombre AS servicio_nombre, s.imagen AS servicio_imagen
    FROM pedidos p
    JOIN usuarios u ON p.usuario_id = u.id
    JOIN planes pl ON p.plan_id = pl.id
    JOIN servicios s ON pl.servicio_id = s.id
    $where
    ORDER BY (p.estado = 'pendiente') DESC, p.created_at DESC
");
$pedidos->execute($params);
$pedidos = $pedidos->fetchAll();

$pendientesCount = (int)$pdo->query("SELECT COUNT(*) FROM pedidos WHERE estado = 'pendiente'")->fetchColumn();

// Trazabilidad / resumen de ventas (por valor)
$tz = $pdo->query("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(CASE WHEN estado IN ('entregado','vencido') THEN monto END),0) AS vendido,
        COALESCE(SUM(CASE WHEN estado='pendiente' THEN monto END),0) AS pendiente_monto,
        SUM(estado='entregado') AS n_entregado,
        SUM(estado='vencido')   AS n_vencido,
        SUM(estado='cancelado') AS n_cancelado
    FROM pedidos
")->fetch();

// Ventas del mes actual (pedidos entregados, contando los ya vencidos también)
// FIX: usa COALESCE(fecha_entrega, created_at) para que cuenten también las
// ventas automáticas o pedidos sin fecha_entrega (antes mostraba $0).
$mesActual = $pdo->query("
    SELECT
        COUNT(*) AS n,
        COALESCE(SUM(monto),0) AS monto
    FROM pedidos
    WHERE estado IN ('entregado','vencido')
      AND COALESCE(fecha_entrega, created_at) >= DATE_FORMAT(NOW(), '%Y-%m-01')
")->fetch();

$meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
$nombreMes = $meses[(int)date('n') - 1];
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Gestionar pedidos — Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#0d0d0d;--surface:#161616;--s2:#1e1e1e;--s3:#262626;--border:rgba(255,255,255,.08);--border2:rgba(255,255,255,.14);--accent:#7c6dfa;--text:#fff;--text2:#a3a3a3;--text3:#555;--ok:#1db954;--warn:#f59e0b;--err:#ef4444;--info:#3b82f6;--r:10px;--rl:16px;--rxl:20px;}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;font-size:14px;-webkit-font-smoothing:antialiased;}
nav{background:var(--surface);border-bottom:1px solid var(--border);padding:0 28px;height:58px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;gap:10px;}
.nav-logo{font-size:16px;font-weight:800;background:linear-gradient(135deg,var(--accent),#f472b6);-webkit-background-clip:text;-webkit-text-fill-color:transparent;flex-shrink:0;}
.nav-links{display:flex;gap:4px;overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none;}
.nav-links::-webkit-scrollbar{display:none;}
.nav-links a{color:var(--text2);font-size:13px;text-decoration:none;padding:7px 14px;border-radius:var(--r);transition:all .2s;font-weight:500;white-space:nowrap;}
.nav-links a:hover,.nav-links a.active{background:var(--s2);color:var(--text);}
.container{max-width:1300px;margin:0 auto;padding:28px 24px 80px;}
h1{font-size:22px;font-weight:800;margin-bottom:4px;}
.sub{color:var(--text2);font-size:13px;margin-bottom:22px;}
.flash{display:flex;align-items:center;justify-content:space-between;gap:12px;border-radius:var(--r);padding:12px 16px;font-size:13px;margin-bottom:20px;font-weight:500;animation:slideDown .3s ease;}
.flash span{display:flex;align-items:center;gap:8px;}
.flash button{background:none;border:none;color:inherit;cursor:pointer;font-size:15px;opacity:.6;line-height:1;flex-shrink:0;}
.flash button:hover{opacity:1;}
.flash.ok{background:rgba(29,185,84,.08);border:1px solid rgba(29,185,84,.25);color:var(--ok);}
.flash.err{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);color:var(--err);}
@keyframes slideDown{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
.filters{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;}
.filters a{padding:7px 14px;border-radius:20px;font-size:12px;font-weight:600;text-decoration:none;background:var(--surface);border:1px solid var(--border);color:var(--text2);}
.filters a.active{background:var(--accent);color:#fff;border-color:var(--accent);}
.select-bar{display:flex;align-items:center;gap:8px;margin-bottom:12px;}
.select-bar label{display:flex;align-items:center;gap:7px;font-size:12px;color:var(--text2);cursor:pointer;user-select:none;}
.select-bar input[type=checkbox]{width:16px;height:16px;accent-color:var(--accent);cursor:pointer;}
.table-wrap{background:var(--surface);border:1px solid var(--border);border-radius:var(--rxl);overflow:hidden;}
table{width:100%;border-collapse:collapse;}
th{background:var(--s2);padding:9px 9px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.6px;color:var(--text3);font-weight:700;}
td{padding:10px 9px;border-top:1px solid var(--border);vertical-align:middle;font-size:12.5px;}
tr:hover td{background:rgba(255,255,255,.015);}
.srv-img{width:30px;height:30px;object-fit:contain;border-radius:7px;background:var(--s2);padding:3px;}
.cliente{font-weight:600;font-size:13px;}
.cliente small{display:block;color:var(--text3);font-size:11px;font-weight:400;}
.badge{padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;}
.b-pendiente{background:rgba(245,158,11,.14);color:var(--warn);}
.b-entregado{background:rgba(29,185,84,.14);color:var(--ok);}
.b-vencido{background:rgba(239,68,68,.14);color:var(--err);}
.b-cancelado{background:rgba(120,120,120,.18);color:#aaa;}
.b-completado{background:rgba(59,130,246,.14);color:var(--info);}
.btn-sm{padding:6px 12px;border-radius:7px;cursor:pointer;font-family:'Inter',sans-serif;font-size:11px;font-weight:700;border:none;transition:all .15s;}
.btn-entregar{background:rgba(29,185,84,.16);color:var(--ok);}
.btn-entregar:hover{background:rgba(29,185,84,.3);}
.btn-cancelar{background:rgba(239,68,68,.14);color:var(--err);}
.btn-cancelar:hover{background:rgba(239,68,68,.28);}
.btn-eliminar{background:rgba(239,68,68,.1);color:var(--err);width:30px;height:30px;border-radius:7px;display:inline-flex;align-items:center;justify-content:center;font-size:13px;}
.btn-eliminar:hover{background:rgba(239,68,68,.25);}
.creds-mini{font-size:11px;color:var(--text2);line-height:1.5;}
.creds-mini b{color:var(--text);}
.empty{text-align:center;padding:60px 20px;color:var(--text3);}
.row-check{width:16px;height:16px;accent-color:var(--accent);cursor:pointer;}
.acciones-cell{display:flex;gap:6px;flex-wrap:wrap;align-items:center;}
.bulk-bar{position:fixed;left:0;right:0;bottom:0;background:var(--s2);border-top:1px solid var(--border2);padding:14px 20px;padding-bottom:calc(14px + env(safe-area-inset-bottom));display:flex;align-items:center;justify-content:space-between;gap:12px;transform:translateY(110%);transition:transform .25s ease;z-index:200;box-shadow:0 -8px 24px rgba(0,0,0,.45);flex-wrap:wrap;}
.bulk-bar.show{transform:translateY(0);}
.bulk-bar .info{font-size:13px;font-weight:600;color:var(--text2);}
.bulk-bar .info b{color:var(--text);}
.bulk-bar .actions{display:flex;gap:8px;}
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:1000;display:none;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(8px);}
.overlay.open{display:flex;}
.modal{background:var(--surface);border:1px solid var(--border2);border-radius:var(--rxl);padding:26px;width:100%;max-width:440px;position:relative;animation:fadeUp .25s ease;max-height:90vh;overflow-y:auto;}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.modal-close{position:absolute;top:14px;right:14px;width:28px;height:28px;border-radius:50%;background:var(--s2);border:none;color:var(--text2);cursor:pointer;font-size:15px;}
.modal-title{font-size:17px;font-weight:800;margin-bottom:6px;}
.modal-sub{font-size:12px;color:var(--text3);margin-bottom:18px;}
.form-group{margin-bottom:14px;}
.form-group label{display:block;font-size:11px;color:var(--text3);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;font-weight:600;}
.form-group input,.form-group textarea{width:100%;padding:10px 12px;background:var(--s2);border:1px solid var(--border);border-radius:var(--r);color:var(--text);font-family:'Inter',sans-serif;font-size:13px;outline:none;}
.form-group input:focus,.form-group textarea:focus{border-color:rgba(255,255,255,.25);}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.btn-primary{width:100%;padding:12px;background:linear-gradient(135deg,var(--accent),#9c8df7);border:none;border-radius:var(--r);color:#fff;font-family:'Inter',sans-serif;font-size:14px;font-weight:700;cursor:pointer;}
@media (max-width: 640px){
  nav{padding:0 14px;}
  .container{padding:18px 14px 90px;}
  h1{font-size:19px;}
}
@media (max-width: 768px){
  .table-wrap table, .table-wrap thead, .table-wrap tbody, .table-wrap tr, .table-wrap td{ display:block; width:100%; }
  .table-wrap thead{display:none;}
  .table-wrap tr{ border-top:1px solid var(--border); padding:14px 14px 10px; }
  .table-wrap tbody tr:first-child{border-top:none;}
  .table-wrap td{ border-top:none; padding:6px 0; display:flex; justify-content:space-between; align-items:flex-start; gap:10px; text-align:right; }
  .table-wrap td::before{ content:attr(data-label); font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:var(--text3); font-weight:700; flex-shrink:0; text-align:left; padding-top:2px; }
  .table-wrap td[data-label=""]::before{content:none;}
  .acciones-cell{justify-content:flex-end;}
  .bulk-bar{flex-direction:column;align-items:stretch;}
  .bulk-bar .actions{justify-content:flex-end;}
  .form-row{grid-template-columns:1fr;}
}
</style>
</head>
<body>
<nav>
  <div class="nav-logo">⚙️ Admin Panel</div>
  <div class="nav-links">
    <a href="index.php">Dashboard</a>
    <a href="servicios.php">Servicios &amp; Planes</a>
    <a href="stock.php">Stock</a>
    <a href="pedidos.php" class="active">Pedidos</a>
    <a href="usuarios.php">Usuarios</a>
    <a href="pago.php">Datos de pago</a>
    <a href="../logout.php" style="color:#ef4444">Salir</a>
  </div>
</nav>


<div class="container">
  <h1>Gestionar pedidos</h1>
  <p class="sub"><?= $pendientesCount ?> pedido<?= $pendientesCount != 1 ? 's' : '' ?> pendiente<?= $pendientesCount != 1 ? 's' : '' ?> de entrega</p>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:22px">
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--rl);padding:16px 18px;border-bottom:2px solid var(--accent)">
      <div style="font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:.5px">Ventas de <?= $nombreMes ?></div>
      <div style="font-size:22px;font-weight:800;color:var(--accent)">$<?= number_format((float)$mesActual['monto'],0,'.','.') ?></div>
      <div style="font-size:11px;color:var(--text3);margin-top:2px"><?= (int)$mesActual['n'] ?> pedido<?= (int)$mesActual['n'] != 1 ? 's' : '' ?></div>
    </div>
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--rl);padding:16px 18px;border-bottom:2px solid var(--ok)">
      <div style="font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:.5px">Total vendido</div>
      <div style="font-size:22px;font-weight:800;color:var(--ok)">$<?= number_format((float)$tz['vendido'],0,'.','.') ?></div>
    </div>
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--rl);padding:16px 18px;border-bottom:2px solid var(--warn)">
      <div style="font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:.5px">En espera (por validar)</div>
      <div style="font-size:22px;font-weight:800;color:var(--warn)">$<?= number_format((float)$tz['pendiente_monto'],0,'.','.') ?></div>
    </div>
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--rl);padding:16px 18px;border-bottom:2px solid var(--info)">
      <div style="font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:.5px">Entregados activos</div>
      <div style="font-size:22px;font-weight:800"><?= (int)$tz['n_entregado'] ?></div>
    </div>
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--rl);padding:16px 18px;border-bottom:2px solid var(--err)">
      <div style="font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:.5px">Vencidos / Rechazados</div>
      <div style="font-size:22px;font-weight:800"><?= (int)$tz['n_vencido'] ?> / <?= (int)$tz['n_cancelado'] ?></div>
    </div>
  </div>

  <?php if ($msg): ?>
  <div class="flash <?= $msgTipo ?>" id="flashMsg">
    <span><?= $msgTipo === 'ok' ? '✓' : '⚠' ?> <?= htmlspecialchars($msg) ?></span>
    <button type="button" onclick="document.getElementById('flashMsg').remove()">✕</button>
  </div>
  <?php endif; ?>

  <div class="filters">
    <?php
    $estados = ['' => 'Todos', 'pendiente' => 'Pendientes', 'entregado' => 'Entregados', 'vencido' => 'Vencidos', 'cancelado' => 'Cancelados'];
    foreach ($estados as $k => $lbl): ?>
      <a href="?estado=<?= $k ?>" class="<?= $filtro === $k ? 'active' : '' ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!empty($pedidos)): ?>
  <div class="select-bar">
    <label>
      <input type="checkbox" id="selectAll" onchange="toggleAll(this)">
      Seleccionar todos
    </label>
  </div>
  <?php endif; ?>

  <div class="table-wrap">
    <?php if (empty($pedidos)): ?>
      <div class="empty">No hay pedidos<?= $filtro ? ' con ese estado' : ' todavía' ?>.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th style="width:30px"></th><th>#</th><th>Cliente</th><th>Servicio / Plan</th><th>Monto</th><th>Estado</th><th>Vence</th><th>Comprobante</th><th>Credenciales</th><th>Acciones</th></tr></thead>
      <tbody>
        <?php foreach ($pedidos as $p):
          $diasRest = null;
          if ($p['fecha_vencimiento']) {
              $diff = strtotime($p['fecha_vencimiento']) - time();
              $diasRest = (int)ceil($diff / 86400);
          }
        ?>
        <tr>
          <td data-label=""><input type="checkbox" class="row-check" value="<?= (int)$p['id'] ?>" onchange="updateBulkBar()"></td>
          <td data-label="#">#<?= $p['id'] ?></td>
          <td data-label="Cliente">
            <div class="cliente"><?= htmlspecialchars($p['cliente_nombre'].' '.$p['cliente_apellido']) ?>
              <small><?= htmlspecialchars($p['cliente_email']) ?></small>
            </div>
          </td>
          <td data-label="Servicio / Plan">
            <div style="display:flex;align-items:center;gap:8px">
              <img class="srv-img" src="../assets/img/<?= htmlspecialchars($p['servicio_imagen'] ?? '') ?>" onerror="this.style.opacity='.15'">
              <div><?= htmlspecialchars($p['servicio_nombre']) ?><br><small style="color:var(--text3)"><?= htmlspecialchars($p['plan_nombre']) ?></small></div>
            </div>
          </td>
          <td data-label="Monto"><b>$<?= number_format((float)$p['monto'],0,'.','.') ?></b></td>
          <td data-label="Estado"><span class="badge b-<?= $p['estado'] ?>"><?= $p['estado'] ?></span></td>
          <td data-label="Vence" style="font-size:12px;color:var(--text2)">
            <?php if ($p['fecha_vencimiento']): ?>
              <?= date('d/m/Y', strtotime($p['fecha_vencimiento'])) ?>
              <?php if ($p['estado'] === 'entregado'): ?><br><small style="color:<?= $diasRest <= 3 ? 'var(--warn)' : 'var(--text3)' ?>"><?= $diasRest ?> día<?= $diasRest != 1 ? 's' : '' ?></small><?php endif; ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td data-label="Comprobante">
            <?php if (!empty($p['comprobante'])): ?>
              <a href="../<?= htmlspecialchars($p['comprobante']) ?>" target="_blank" style="color:var(--accent);font-size:12px;font-weight:700">Ver 🔗</a>
            <?php else: ?><span style="color:var(--text3)">—</span><?php endif; ?>
          </td>
          <td data-label="Credenciales">
            <?php if ($p['cred_usuario']): ?>
              <div class="creds-mini">Usuario: <b><?= htmlspecialchars($p['cred_usuario']) ?></b><br>Clave: <b><?= htmlspecialchars($p['cred_password']) ?></b><?php if (!empty($p['cred_perfil'])): ?><br>Perfil: <b><?= htmlspecialchars($p['cred_perfil']) ?></b><?php endif; ?><?php if (!empty($p['cred_pin'])): ?><br>PIN: <b><?= htmlspecialchars($p['cred_pin']) ?></b><?php endif; ?></div>
            <?php else: ?><span style="color:var(--text3)">—</span><?php endif; ?>
          </td>
          <td data-label="Acciones">
            <div class="acciones-cell">
              <?php if ($p['estado'] === 'pendiente'): ?>
                <button class="btn-sm btn-entregar" onclick='abrirEntrega(<?= json_encode([
                    'id' => $p['id'],
                    'cliente' => $p['cliente_nombre'].' '.$p['cliente_apellido'],
                    'servicio' => $p['servicio_nombre'].' — '.$p['plan_nombre'],
                    'dias' => (int)$p['duracion_dias'],
                ], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Entregar</button>
                <button class="btn-sm btn-cancelar" onclick='abrirRechazo(<?= (int)$p['id'] ?>, <?= json_encode($p['cliente_nombre'].' — '.$p['servicio_nombre'], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Rechazar</button>
              <?php endif; ?>
              <button class="btn-sm btn-eliminar" title="Eliminar pedido" onclick='eliminarUno(<?= (int)$p['id'] ?>, <?= json_encode('#'.$p['id'].' — '.$p['cliente_nombre'].' '.$p['cliente_apellido'], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>🗑</button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>


<!-- BARRA FLOTANTE: SELECCIÓN MÚLTIPLE -->
<div class="bulk-bar" id="bulkBar">
  <div class="info"><b id="bulkCount">0</b> pedido(s) seleccionado(s)</div>
  <div class="actions">
    <button type="button" class="btn-sm" style="background:var(--s3);color:var(--text2)" onclick="limpiarSeleccion()">Cancelar</button>
    <button type="button" class="btn-sm btn-cancelar" onclick="confirmarEliminarSeleccion()">Eliminar seleccionados</button>
  </div>
</div>

<!-- FORM OCULTO PARA ELIMINACIÓN (individual y masiva) -->
<form method="POST" id="deleteForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="accion" value="eliminar">
  <div id="deleteIdsContainer"></div>
</form>

<!-- MODAL ENTREGA -->
<div class="overlay" id="overlayEntrega">
  <div class="modal">
    <button class="modal-close" onclick="cerrar()">✕</button>
    <div class="modal-title">Entregar credenciales</div>
    <div class="modal-sub" id="entregaInfo"></div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="accion" value="entregar">
      <input type="hidden" name="pedido_id" id="e_pedido_id">
      <div class="form-group">
        <label>Usuario / Email de la cuenta</label>
        <input type="text" name="cred_usuario" id="e_usuario" required autocomplete="off" placeholder="cuenta@correo.com">
      </div>
      <div class="form-group">
        <label>Contraseña de la cuenta</label>
        <input type="text" name="cred_password" id="e_password" required autocomplete="off" placeholder="••••••••">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Perfil (opcional)</label>
          <input type="text" name="cred_perfil" id="e_perfil" autocomplete="off" placeholder="Ej: Perfil 2">
        </div>
        <div class="form-group">
          <label>PIN (opcional)</label>
          <input type="text" name="cred_pin" id="e_pin" autocomplete="off" placeholder="Ej: 1234">
        </div>
      </div>
      <button type="submit" class="btn-primary">Enviar al cliente →</button>
    </form>
  </div>
</div>

<!-- MODAL RECHAZO -->
<div class="overlay" id="overlayRechazo">
  <div class="modal">
    <button class="modal-close" onclick="cerrarRechazo()">✕</button>
    <div class="modal-title">Rechazar pedido</div>
    <div class="modal-sub" id="rechazoInfo"></div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="accion" value="rechazar">
      <input type="hidden" name="pedido_id" id="r_pedido_id">
      <div class="form-group">
        <label>Motivo del rechazo (lo verá el cliente)</label>
        <textarea name="nota" id="r_nota" required rows="3" placeholder="Ej: No se recibió la transferencia / el comprobante no es válido / el monto no coincide."></textarea>
      </div>
      <button type="submit" class="btn-sm btn-cancelar" style="width:100%;padding:12px">Rechazar y notificar →</button>
    </form>
  </div>
</div>

<!-- MODAL DE CONFIRMACIÓN PERSONALIZADO -->
<div class="overlay" id="overlayConfirm">
  <div class="modal" style="max-width:380px;text-align:center">
    <div style="font-size:34px;margin-bottom:6px" id="confirmIcon">🗑</div>
    <div class="modal-title" id="confirmTitle" style="margin-bottom:8px">¿Eliminar?</div>
    <div class="modal-sub" id="confirmMsg" style="margin-bottom:22px;line-height:1.5"></div>
    <div style="display:flex;gap:10px">
      <button type="button" class="btn-sm" style="flex:1;padding:12px;background:var(--s3);color:var(--text2);font-size:13px" onclick="cerrarConfirm()">Cancelar</button>
      <button type="button" class="btn-sm" id="confirmBtnOk" style="flex:1;padding:12px;background:var(--err);color:#fff;font-size:13px" onclick="aceptarConfirm()">Eliminar</button>
    </div>
  </div>
</div>

<script>
function abrirEntrega(p) {
  document.getElementById('e_pedido_id').value = p.id;
  document.getElementById('e_usuario').value = '';
  document.getElementById('e_password').value = '';
  document.getElementById('e_perfil').value = '';
  document.getElementById('e_pin').value = '';
  document.getElementById('entregaInfo').textContent =
    'Pedido #' + p.id + ' · ' + p.cliente + ' · ' + p.servicio + ' · vence en ' + p.dias + ' días';
  document.getElementById('overlayEntrega').classList.add('open');
}
function cerrar() { document.getElementById('overlayEntrega').classList.remove('open'); }
document.getElementById('overlayEntrega').addEventListener('click', e => { if (e.target.id === 'overlayEntrega') cerrar(); });

function abrirRechazo(id, info) {
  document.getElementById('r_pedido_id').value = id;
  document.getElementById('r_nota').value = '';
  document.getElementById('rechazoInfo').textContent = 'Pedido #' + id + ' · ' + info;
  document.getElementById('overlayRechazo').classList.add('open');
}
function cerrarRechazo() { document.getElementById('overlayRechazo').classList.remove('open'); }
document.getElementById('overlayRechazo').addEventListener('click', e => { if (e.target.id === 'overlayRechazo') cerrarRechazo(); });

// ===== Modal de confirmación personalizado (reemplaza confirm() nativo) =====
let _confirmCallback = null;

function customConfirm(opts) {
  document.getElementById('confirmTitle').textContent = opts.title || '¿Confirmar?';
  document.getElementById('confirmMsg').textContent = opts.message || '';
  document.getElementById('confirmIcon').textContent = opts.icon || '🗑';
  const btn = document.getElementById('confirmBtnOk');
  btn.textContent = opts.okText || 'Eliminar';
  btn.style.background = opts.okColor || 'var(--err)';
  _confirmCallback = opts.onConfirm;
  document.getElementById('overlayConfirm').classList.add('open');
}
function cerrarConfirm() {
  document.getElementById('overlayConfirm').classList.remove('open');
  _confirmCallback = null;
}
function aceptarConfirm() {
  const cb = _confirmCallback;
  cerrarConfirm();
  if (cb) cb();
}
document.getElementById('overlayConfirm').addEventListener('click', e => { if (e.target.id === 'overlayConfirm') cerrarConfirm(); });

// ===== Selección múltiple =====
function rowChecks() { return Array.from(document.querySelectorAll('.row-check')); }

function toggleAll(src) {
  rowChecks().forEach(cb => cb.checked = src.checked);
  updateBulkBar();
}

function updateBulkBar() {
  const checks = rowChecks();
  const seleccionados = checks.filter(cb => cb.checked);
  document.getElementById('bulkCount').textContent = seleccionados.length;
  document.getElementById('bulkBar').classList.toggle('show', seleccionados.length > 0);
  const selectAll = document.getElementById('selectAll');
  if (selectAll) selectAll.checked = checks.length > 0 && seleccionados.length === checks.length;
}

function limpiarSeleccion() {
  rowChecks().forEach(cb => cb.checked = false);
  updateBulkBar();
}

function enviarEliminar(ids) {
  const container = document.getElementById('deleteIdsContainer');
  container.innerHTML = '';
  ids.forEach(id => {
    const inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = id;
    container.appendChild(inp);
  });
  document.getElementById('deleteForm').submit();
}

function eliminarUno(id, info) {
  customConfirm({
    title: 'Eliminar pedido',
    message: '¿Eliminar el pedido ' + info + '? Esta acción no se puede deshacer.',
    okText: 'Eliminar',
    onConfirm: () => enviarEliminar([id])
  });
}

function confirmarEliminarSeleccion() {
  const ids = rowChecks().filter(cb => cb.checked).map(cb => cb.value);
  if (ids.length === 0) return;
  customConfirm({
    title: 'Eliminar pedidos',
    message: '¿Eliminar ' + ids.length + ' pedido(s) seleccionado(s)? Esta acción no se puede deshacer.',
    okText: 'Eliminar todos',
    onConfirm: () => enviarEliminar(ids)
  });
}
</script>
</body>
</html>