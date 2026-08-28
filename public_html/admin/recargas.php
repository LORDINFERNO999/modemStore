<?php
// admin/recargas.php — Gestión de recargas de saldo
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/funciones.php';
requireAdmin();

$msg = ''; $msgTipo = 'ok';

// Guardar configuración de la aprobación automática (interruptor ON/OFF + tope)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'auto_config') {
    csrfRequire();
    setConfig('auto_recarga_activa', (($_POST['auto_activa'] ?? '0') === '1') ? '1' : '0');
    $tope = (int) preg_replace('/[^0-9]/', '', (string)($_POST['auto_tope'] ?? '40000'));
    if ($tope < 1000) $tope = 40000;
    setConfig('auto_recarga_tope', (string)$tope);
    $msg = (($_POST['auto_activa'] ?? '0') === '1')
         ? "Aprobación automática ACTIVADA (tope $" . number_format($tope, 0, ',', '.') . ") ✓"
         : "Aprobación automática DESACTIVADA ✓";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'aprobar') {
    csrfRequire();
    $recargaId = (int)($_POST['recarga_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM recargas WHERE id = ? AND estado = 'pendiente'");
    $stmt->execute([$recargaId]);
    $recarga = $stmt->fetch();
    if ($recarga) {
        $ok = registrarMovimiento((int)$recarga['usuario_id'], 'recarga', (float)$recarga['monto'], $recargaId, 'Recarga aprobada por admin');
        if ($ok) {
            $pdo->prepare("UPDATE recargas SET estado = 'aprobada' WHERE id = ?")->execute([$recargaId]);
            $pdo->prepare("UPDATE notificaciones SET atendida = 1, leida = 1 WHERE tipo = 'recarga' AND referencia_id = ?")->execute([$recargaId]);
            $msg = "Recarga #$recargaId aprobada ✓ — Se acreditaron " . formatMoney((float)$recarga['monto']);
        } else { $msg = 'Error al acreditar el saldo'; $msgTipo = 'err'; }
    } else { $msg = 'La recarga no existe o ya fue procesada'; $msgTipo = 'err'; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'rechazar') {
    csrfRequire();
    $recargaId = (int)($_POST['recarga_id'] ?? 0);
    $nota = trim($_POST['nota'] ?? '');
    $stmt = $pdo->prepare("SELECT * FROM recargas WHERE id = ? AND estado = 'pendiente'");
    $stmt->execute([$recargaId]);
    $recarga = $stmt->fetch();
    if ($recarga) {
        $pdo->prepare("UPDATE recargas SET estado = 'rechazada', nota_admin = ? WHERE id = ?")
            ->execute([$nota !== '' ? $nota : 'Comprobante no válido.', $recargaId]);
        $pdo->prepare("UPDATE notificaciones SET atendida = 1, leida = 1 WHERE tipo = 'recarga' AND referencia_id = ?")->execute([$recargaId]);
        $msg = "Recarga #$recargaId rechazada";
    } else { $msg = 'Solo se pueden rechazar recargas pendientes'; $msgTipo = 'err'; }
}

// Eliminar una o varias recargas (borrado definitivo del registro)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {
    csrfRequire();
    $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['ids'] ?? []))));

    if ($ids) {
        $place = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM notificaciones WHERE tipo = 'recarga' AND referencia_id IN ($place)")
            ->execute($ids);

        $stmt = $pdo->prepare("DELETE FROM recargas WHERE id IN ($place)");
        $stmt->execute($ids);
        $n = $stmt->rowCount();

        $msg = $n === 1 ? "Recarga eliminada ✓" : "$n recargas eliminadas ✓";
    } else {
        $msg = 'No se seleccionó ninguna recarga para eliminar'; $msgTipo = 'err';
    }
}

$filtro = $_GET['estado'] ?? '';
$where = ''; $params = [];
if (in_array($filtro, ['pendiente','aprobada','rechazada'])) { $where = "WHERE r.estado = ?"; $params[] = $filtro; }

$recargas = $pdo->prepare("
    SELECT r.*, u.nombre AS cliente_nombre, u.apellido AS cliente_apellido, u.email AS cliente_email, u.saldo AS saldo_actual
    FROM recargas r JOIN usuarios u ON r.usuario_id = u.id $where
    ORDER BY (r.estado = 'pendiente') DESC, r.created_at DESC LIMIT 100
");
$recargas->execute($params);
$recargas = $recargas->fetchAll();

$pendientesCount = (int)$pdo->query("SELECT COUNT(*) FROM recargas WHERE estado = 'pendiente'")->fetchColumn();

// Estado actual de la aprobación automática
$autoActivaCfg = (getConfig('auto_recarga_activa', '0') === '1');
$autoTopeCfg   = (int) getConfig('auto_recarga_tope', '40000');

// Posibles duplicados: mismo cliente + mismo monto con varias recargas PENDIENTES
$dupMap = [];
foreach ($pdo->query("SELECT usuario_id, monto, COUNT(*) AS c FROM recargas WHERE estado='pendiente' GROUP BY usuario_id, monto HAVING c > 1")->fetchAll() as $d) {
    $dupMap[$d['usuario_id'] . '|' . (float)$d['monto']] = (int)$d['c'];
}
$tz = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN estado='aprobada' THEN monto END),0) AS total_aprobado, COALESCE(SUM(CASE WHEN estado='pendiente' THEN monto END),0) AS total_pendiente, SUM(estado='aprobada') AS n_aprobada, SUM(estado='rechazada') AS n_rechazada FROM recargas")->fetch();
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Recargas — Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#0d0d0d;--surface:#161616;--s2:#1e1e1e;--s3:#262626;--border:rgba(255,255,255,.08);--border2:rgba(255,255,255,.14);--accent:#7c6dfa;--text:#fff;--text2:#a3a3a3;--text3:#555;--ok:#1db954;--warn:#f59e0b;--err:#ef4444;--r:10px;--rl:16px;--rxl:20px}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased;padding:20px 16px 60px}
h1{font-size:20px;font-weight:800;margin-bottom:4px}
.sub{color:var(--text2);font-size:13px;margin-bottom:18px}

.flash{display:flex;align-items:center;justify-content:space-between;gap:12px;border-radius:var(--r);padding:12px 16px;font-size:13px;margin-bottom:16px;font-weight:500;animation:slideDown .3s ease}
.flash span{display:flex;align-items:center;gap:8px}
.flash button{background:none;border:none;color:inherit;cursor:pointer;font-size:15px;opacity:.6;line-height:1;flex-shrink:0}
.flash button:hover{opacity:1}
.flash.ok{background:rgba(29,185,84,.08);border:1px solid rgba(29,185,84,.25);color:var(--ok)}
.flash.err{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);color:var(--err)}
.auto-box{border-radius:var(--rl);padding:16px;margin-bottom:18px;border:1px solid var(--border)}
.auto-box.on{background:rgba(29,185,84,.07);border-color:rgba(29,185,84,.35)}
.auto-box.off{background:var(--surface)}
.auto-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.auto-title{font-size:14px;font-weight:800}
.auto-desc{font-size:12px;color:var(--text2);margin-top:3px;line-height:1.4}
.auto-badge{font-size:10px;font-weight:800;letter-spacing:.5px;padding:4px 10px;border-radius:20px;flex-shrink:0}
.auto-badge.on{background:rgba(29,185,84,.18);color:var(--ok)}
.auto-badge.off{background:rgba(239,68,68,.15);color:var(--err)}
.auto-form{display:flex;align-items:flex-end;gap:10px;margin-top:12px;flex-wrap:wrap}
.auto-tope{font-size:11px;color:var(--text3);display:flex;flex-direction:column;gap:4px;width:180px}
.auto-tope input{background:var(--s2);border:1px solid var(--border);border-radius:8px;padding:11px;color:var(--text);font-family:inherit;font-size:15px;font-weight:700;outline:none;width:100%}
.auto-tope input:focus{border-color:var(--accent)}
.auto-btns{flex:1;display:flex;justify-content:flex-end}
.auto-btn{border:none;border-radius:8px;padding:12px 22px;font-family:inherit;font-size:14px;font-weight:800;cursor:pointer;white-space:nowrap}
.auto-btn.activar{background:linear-gradient(135deg,var(--ok),#22c55e);color:#fff}
.auto-btn.desactivar{background:rgba(239,68,68,.15);color:var(--err)}
.auto-note{font-size:11px;color:var(--text3);margin-top:10px;line-height:1.4}
@media(max-width:600px){
  .auto-box{padding:14px}
  .auto-head{flex-direction:column;gap:6px}
  .auto-badge{align-self:flex-start}
  .auto-form{flex-direction:column;align-items:stretch;gap:12px}
  .auto-tope{width:100%}
  .auto-btns{width:100%}
  .auto-btn{width:100%;padding:14px}
}
@keyframes slideDown{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}

.stats-row{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:18px}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--rl);padding:14px 16px}
.stat-card .label{font-size:10px;color:var(--text3);text-transform:uppercase;letter-spacing:.4px}
.stat-card .value{font-size:20px;font-weight:800;margin-top:3px}

.filters{display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap}
.filters a{padding:8px 14px;border-radius:20px;font-size:12px;font-weight:600;text-decoration:none;background:var(--surface);border:1px solid var(--border);color:var(--text2);touch-action:manipulation}
.filters a.active{background:var(--accent);color:#fff;border-color:var(--accent)}

.select-bar{display:flex;align-items:center;gap:8px;margin-bottom:14px}
.select-bar label{display:flex;align-items:center;gap:7px;font-size:12px;color:var(--text2);cursor:pointer;user-select:none}
.select-bar input[type=checkbox]{width:17px;height:17px;accent-color:var(--accent);cursor:pointer}

.recarga-list{display:flex;flex-direction:column;gap:12px}
.recarga-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--rl);padding:16px;transition:border-color .2s;position:relative}
.recarga-card:hover{border-color:var(--border2)}
.recarga-card.checked{border-color:var(--accent);box-shadow:0 0 0 1px var(--accent) inset}
.rc-top{display:flex;gap:10px;align-items:flex-start}
.rc-check{width:18px;height:18px;accent-color:var(--accent);cursor:pointer;margin-top:3px;flex-shrink:0}
.rc-body{flex:1;min-width:0}
.rc-header{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:10px}
.rc-cliente{font-weight:700;font-size:14px;line-height:1.3}
.rc-email{font-size:11px;color:var(--text3);margin-top:2px;word-break:break-all}
.rc-monto{font-size:20px;font-weight:800;color:var(--ok);white-space:nowrap}
.rc-details{display:flex;flex-wrap:wrap;gap:8px 16px;margin-bottom:12px;font-size:12px;color:var(--text2)}
.rc-detail{display:flex;align-items:center;gap:4px}
.rc-detail b{color:var(--text)}
.badge{padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
.b-pendiente{background:rgba(245,158,11,.14);color:var(--warn)}
.b-aprobada{background:rgba(29,185,84,.14);color:var(--ok)}
.b-rechazada{background:rgba(239,68,68,.14);color:var(--err)}
.rc-actions{display:flex;gap:8px;margin-top:12px;flex-wrap:wrap}
.rc-actions form{flex:1;min-width:120px}
.btn-action{width:100%;padding:12px;border-radius:var(--r);cursor:pointer;font-family:'Inter',sans-serif;font-size:13px;font-weight:700;border:none;transition:all .15s;touch-action:manipulation}
.btn-aprobar{background:rgba(29,185,84,.16);color:var(--ok)}
.btn-aprobar:hover,.btn-aprobar:active{background:rgba(29,185,84,.3)}
.btn-rechazar{background:rgba(239,68,68,.14);color:var(--err)}
.btn-rechazar:hover,.btn-rechazar:active{background:rgba(239,68,68,.28)}
.btn-eliminar-card{flex:0 0 auto;width:44px;padding:12px;border-radius:var(--r);background:rgba(239,68,68,.1);color:var(--err);border:none;cursor:pointer;font-size:14px;touch-action:manipulation}
.btn-eliminar-card:hover,.btn-eliminar-card:active{background:rgba(239,68,68,.25)}
.rc-nota{margin-top:8px;font-size:11px;color:var(--text3);background:var(--s2);padding:8px 10px;border-radius:8px}
.rc-dup{margin-top:8px;font-size:12px;font-weight:700;color:var(--warn);background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.35);padding:9px 11px;border-radius:8px;display:flex;align-items:flex-start;gap:6px;line-height:1.35}
.rc-verif{margin-top:6px;font-size:11px;color:var(--text2)}
.rc-verif b{color:var(--text)}
.rc-comprobante{display:inline-flex;align-items:center;gap:4px;color:var(--accent);font-size:12px;font-weight:700;text-decoration:none;padding:6px 12px;background:rgba(124,109,250,.08);border-radius:8px;margin-top:8px}
.rc-comprobante:active{opacity:.7}
.rc-delete-row{display:flex;justify-content:flex-end;margin-top:10px}

.empty{text-align:center;padding:48px 20px;color:var(--text3);font-size:14px}

.bulk-bar{position:fixed;left:0;right:0;bottom:0;background:var(--s2);border-top:1px solid var(--border2);padding:14px 16px;padding-bottom:calc(14px + env(safe-area-inset-bottom));display:flex;align-items:center;justify-content:space-between;gap:12px;transform:translateY(110%);transition:transform .25s ease;z-index:200;box-shadow:0 -8px 24px rgba(0,0,0,.45);flex-wrap:wrap}
.bulk-bar.show{transform:translateY(0)}
.bulk-bar .info{font-size:13px;font-weight:600;color:var(--text2)}
.bulk-bar .info b{color:var(--text)}
.bulk-bar .actions{display:flex;gap:8px}
.bulk-bar .actions button{padding:10px 16px;border-radius:var(--r);font-family:'Inter',sans-serif;font-size:12px;font-weight:700;border:none;cursor:pointer;touch-action:manipulation}

.overlay{position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:1000;display:none;align-items:flex-end;justify-content:center;padding:0;backdrop-filter:blur(8px)}
.overlay.open{display:flex}
.modal{background:var(--surface);border:1px solid var(--border2);border-radius:20px 20px 0 0;padding:24px 20px 32px;width:100%;max-width:500px;position:relative;animation:slideModal .3s ease}
@keyframes slideModal{from{transform:translateY(100%)}to{transform:translateY(0)}}
.modal-title{font-size:17px;font-weight:800;margin-bottom:6px}
.modal-sub{font-size:12px;color:var(--text3);margin-bottom:16px}
.modal-close{position:absolute;top:14px;right:14px;width:32px;height:32px;border-radius:50%;background:var(--s2);border:none;color:var(--text2);cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center}
.form-group{margin-bottom:14px}
.form-group label{display:block;font-size:11px;color:var(--text3);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;font-weight:600}
.form-group textarea{width:100%;padding:12px;background:var(--s2);border:1px solid var(--border);border-radius:var(--r);color:var(--text);font-family:'Inter',sans-serif;font-size:14px;outline:none;resize:vertical;min-height:90px}
.form-group textarea:focus{border-color:rgba(255,255,255,.25)}

#overlayConfirm{align-items:center;padding:20px}
#overlayConfirm .modal{border-radius:var(--rxl);animation:fadeUpConfirm .25s ease;padding:26px}
@keyframes fadeUpConfirm{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}

@media(min-width:600px){
  body{padding:28px 24px 90px}
  .stats-row{grid-template-columns:repeat(4,1fr)}
  .recarga-list{gap:14px}
  .recarga-card{padding:20px}
  .rc-actions{max-width:380px}
  .overlay{align-items:center;padding:20px}
  .modal{border-radius:var(--rxl);max-height:90vh;overflow-y:auto}
  @keyframes slideModal{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
  .bulk-bar{left:50%;right:auto;bottom:24px;transform:translate(-50%,150%);width:auto;min-width:380px;border-radius:var(--rxl);border:1px solid var(--border2);border-top:1px solid var(--border2)}
  .bulk-bar.show{transform:translate(-50%,0)}
}
</style>
</head>
<body>
<h1>💰 Recargas de saldo</h1>
<p class="sub"><?= $pendientesCount ?> pendiente<?= $pendientesCount != 1 ? 's' : '' ?></p>

<div class="stats-row">
  <div class="stat-card" style="border-bottom:2px solid var(--ok)"><div class="label">Acreditado</div><div class="value" style="color:var(--ok)">$<?= number_format((float)$tz['total_aprobado'],0,'.','.') ?></div></div>
  <div class="stat-card" style="border-bottom:2px solid var(--warn)"><div class="label">Pendiente</div><div class="value" style="color:var(--warn)">$<?= number_format((float)$tz['total_pendiente'],0,'.','.') ?></div></div>
  <div class="stat-card"><div class="label">Aprobadas</div><div class="value"><?= (int)$tz['n_aprobada'] ?></div></div>
  <div class="stat-card" style="border-bottom:2px solid var(--err)"><div class="label">Rechazadas</div><div class="value"><?= (int)$tz['n_rechazada'] ?></div></div>
</div>

<?php if ($msg): ?>
<div class="flash <?= $msgTipo ?>" id="flashMsg">
  <span><?= $msgTipo === 'ok' ? '✓' : '⚠' ?> <?= htmlspecialchars($msg) ?></span>
  <button type="button" onclick="document.getElementById('flashMsg').remove()">✕</button>
</div>
<?php endif; ?>

<!-- Interruptor de aprobación automática -->
<div class="auto-box <?= $autoActivaCfg ? 'on' : 'off' ?>">
  <div class="auto-head">
    <div>
      <div class="auto-title">🤖 Aprobación automática de recargas</div>
      <div class="auto-desc">
        <?= $autoActivaCfg
            ? 'ACTIVADA — las recargas de clientes de confianza (hasta $' . number_format($autoTopeCfg, 0, ',', '.') . ') se aprueban solas.'
            : 'DESACTIVADA — todas las recargas las apruebas tú manualmente.' ?>
      </div>
    </div>
    <span class="auto-badge <?= $autoActivaCfg ? 'on' : 'off' ?>"><?= $autoActivaCfg ? 'ACTIVA' : 'INACTIVA' ?></span>
  </div>
  <form method="POST" class="auto-form">
    <?= csrfField() ?>
    <input type="hidden" name="accion" value="auto_config">
    <label class="auto-tope">Tope máximo por recarga (COP)
      <input type="number" name="auto_tope" value="<?= $autoTopeCfg ?>" min="1000" step="1000">
    </label>
    <div class="auto-btns">
      <?php if ($autoActivaCfg): ?>
        <input type="hidden" name="auto_activa" value="0">
        <button type="submit" class="auto-btn desactivar">⏸ Desactivar</button>
      <?php else: ?>
        <input type="hidden" name="auto_activa" value="1">
        <button type="submit" class="auto-btn activar">▶ Activar</button>
      <?php endif; ?>
    </div>
  </form>
  <div class="auto-note">💡 Actívala cuando salgas y desactívala al volver. Los clientes nuevos y montos mayores al tope siempre requieren tu aprobación.</div>
</div>

<div class="filters">
  <?php foreach (['' => 'Todas', 'pendiente' => 'Pendientes', 'aprobada' => 'Aprobadas', 'rechazada' => 'Rechazadas'] as $k => $lbl): ?>
    <a href="?estado=<?= $k ?>" class="<?= $filtro === $k ? 'active' : '' ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<?php if (!empty($recargas)): ?>
<div class="select-bar">
  <label>
    <input type="checkbox" id="selectAll" onchange="toggleAll(this)">
    Seleccionar todas
  </label>
</div>
<?php endif; ?>

<?php if (empty($recargas)): ?>
  <div class="empty">No hay recargas<?= $filtro ? ' con ese estado' : ' todavía' ?>.</div>
<?php else: ?>
<div class="recarga-list">
  <?php foreach ($recargas as $r):
    $infoJs = json_encode($r['cliente_nombre'].' — $'.number_format((float)$r['monto'],0,'.','.'), JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE);
    $infoDel = json_encode('#'.$r['id'].' — '.$r['cliente_nombre'].' '.$r['cliente_apellido'], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE);
  ?>
  <div class="recarga-card" id="card-<?= $r['id'] ?>">
    <div class="rc-top">
      <input type="checkbox" class="rc-check" value="<?= (int)$r['id'] ?>" onchange="updateBulkBar()">
      <div class="rc-body">
        <div class="rc-header">
          <div>
            <div class="rc-cliente"><?= htmlspecialchars($r['cliente_nombre'].' '.$r['cliente_apellido']) ?></div>
            <div class="rc-email"><?= htmlspecialchars($r['cliente_email']) ?></div>
          </div>
          <div class="rc-monto">$<?= number_format((float)$r['monto'],0,'.','.') ?></div>
        </div>
        <div class="rc-details">
          <div class="rc-detail"><span class="badge b-<?= $r['estado'] ?>"><?= $r['estado'] ?></span></div>
          <div class="rc-detail">📅 <?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></div>
          <div class="rc-detail">💰 Saldo: <b>$<?= number_format((float)$r['saldo_actual'],0,'.','.') ?></b></div>
        </div>

        <?php if (!empty($r['comprobante'])): ?>
          <a href="../<?= htmlspecialchars($r['comprobante']) ?>" target="_blank" class="rc-comprobante">📎 Ver comprobante</a>
        <?php endif; ?>

        <?php
          $dupN = $dupMap[$r['usuario_id'] . '|' . (float)$r['monto']] ?? 0;
        ?>
        <?php if ($r['estado'] === 'pendiente' && $dupN > 1): ?>
          <div class="rc-dup">⚠ <span>Posible duplicado: este cliente tiene <b><?= $dupN ?></b> recargas pendientes del mismo valor ($<?= number_format((float)$r['monto'],0,'.','.') ?>). Aprueba solo una y rechaza las demás.</span></div>
        <?php endif; ?>

        <?php if ($r['estado'] === 'pendiente'): ?>
          <div class="rc-verif">✓ Verifica que el comprobante sea exactamente por <b>$<?= number_format((float)$r['monto'],0,'.','.') ?></b> antes de aprobar.</div>
        <div class="rc-actions">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="accion" value="aprobar">
            <input type="hidden" name="recarga_id" value="<?= $r['id'] ?>">
            <button type="button" class="btn-action btn-aprobar" onclick="confirmarAprobar(this.closest('form'), <?= (float)$r['monto'] ?>)">✓ Aprobar</button>
          </form>
          <button type="button" class="btn-action btn-rechazar" onclick='abrirRechazo(<?= (int)$r["id"] ?>, <?= $infoJs ?>)'>✗ Rechazar</button>
          <button type="button" class="btn-eliminar-card" title="Eliminar recarga" onclick='eliminarUna(<?= (int)$r['id'] ?>, <?= $infoDel ?>)'>🗑</button>
        </div>
        <?php else: ?>
          <?php if ($r['estado'] === 'rechazada' && !empty($r['nota_admin'])): ?>
            <div class="rc-nota">📝 <?= htmlspecialchars($r['nota_admin']) ?></div>
          <?php endif; ?>
          <div class="rc-delete-row">
            <button type="button" class="btn-eliminar-card" title="Eliminar recarga" onclick='eliminarUna(<?= (int)$r['id'] ?>, <?= $infoDel ?>)'>🗑</button>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="bulk-bar" id="bulkBar">
  <div class="info"><b id="bulkCount">0</b> recarga(s) seleccionada(s)</div>
  <div class="actions">
    <button type="button" style="background:var(--s3);color:var(--text2)" onclick="limpiarSeleccion()">Cancelar</button>
    <button type="button" class="btn-rechazar" onclick="confirmarEliminarSeleccion()">Eliminar seleccionadas</button>
  </div>
</div>

<form method="POST" id="deleteForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="accion" value="eliminar">
  <div id="deleteIdsContainer"></div>
</form>

<div class="overlay" id="overlayRechazo">
  <div class="modal">
    <button type="button" class="modal-close" onclick="cerrarRechazo()">✕</button>
    <div class="modal-title">Rechazar recarga</div>
    <div class="modal-sub" id="rechazoInfo"></div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="accion" value="rechazar">
      <input type="hidden" name="recarga_id" id="r_recarga_id">
      <div class="form-group">
        <label>Motivo del rechazo</label>
        <textarea name="nota" required placeholder="Ej: No se recibió la transferencia / comprobante no válido"></textarea>
      </div>
      <button type="submit" class="btn-action btn-rechazar">Rechazar recarga →</button>
    </form>
  </div>
</div>

<div class="overlay" id="overlayConfirm">
  <div class="modal" style="max-width:380px;text-align:center">
    <div style="font-size:34px;margin-bottom:6px" id="confirmIcon">🗑</div>
    <div class="modal-title" id="confirmTitle" style="margin-bottom:8px">¿Eliminar?</div>
    <div class="modal-sub" id="confirmMsg" style="margin-bottom:22px;line-height:1.5"></div>
    <div style="display:flex;gap:10px">
      <button type="button" class="btn-action" style="background:var(--s3);color:var(--text2)" onclick="cerrarConfirm()">Cancelar</button>
      <button type="button" class="btn-action" id="confirmBtnOk" style="background:var(--err);color:#fff" onclick="aceptarConfirm()">Eliminar</button>
    </div>
  </div>
</div>

<script>
function abrirRechazo(id, info){
  document.getElementById('r_recarga_id').value = id;
  document.getElementById('rechazoInfo').textContent = '#' + id + ' · ' + info;
  document.getElementById('overlayRechazo').classList.add('open');
}
function cerrarRechazo(){ document.getElementById('overlayRechazo').classList.remove('open'); }
document.getElementById('overlayRechazo').addEventListener('click', e => { if (e.target.id === 'overlayRechazo') cerrarRechazo(); });

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

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { cerrarRechazo(); cerrarConfirm(); }
});

function cardChecks() { return Array.from(document.querySelectorAll('.rc-check')); }
function toggleAll(src) {
  cardChecks().forEach(cb => { cb.checked = src.checked; marcarTarjeta(cb); });
  updateBulkBar();
}
function marcarTarjeta(cb) {
  const card = cb.closest('.recarga-card');
  if (card) card.classList.toggle('checked', cb.checked);
}
function updateBulkBar() {
  const checks = cardChecks();
  checks.forEach(marcarTarjeta);
  const seleccionadas = checks.filter(cb => cb.checked);
  document.getElementById('bulkCount').textContent = seleccionadas.length;
  document.getElementById('bulkBar').classList.toggle('show', seleccionadas.length > 0);
  const selectAll = document.getElementById('selectAll');
  if (selectAll) selectAll.checked = checks.length > 0 && seleccionadas.length === checks.length;
}
function limpiarSeleccion() {
  cardChecks().forEach(cb => { cb.checked = false; marcarTarjeta(cb); });
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
function eliminarUna(id, info) {
  customConfirm({
    title: 'Eliminar recarga',
    message: '¿Eliminar la recarga ' + info + '? Esta acción no se puede deshacer.',
    okText: 'Eliminar',
    onConfirm: () => enviarEliminar([id])
  });
}
function confirmarEliminarSeleccion() {
  const ids = cardChecks().filter(cb => cb.checked).map(cb => cb.value);
  if (ids.length === 0) return;
  customConfirm({
    title: 'Eliminar recargas',
    message: '¿Eliminar ' + ids.length + ' recarga(s) seleccionada(s)? Esta acción no se puede deshacer.',
    okText: 'Eliminar todas',
    onConfirm: () => enviarEliminar(ids)
  });
}
function confirmarAprobar(form, monto) {
  const montoFmt = '$' + monto.toLocaleString('es-CO');
  customConfirm({
    title: 'Aprobar recarga',
    message: '¿Aprobar ' + montoFmt + '? El saldo se acreditará de inmediato al cliente.',
    okText: 'Aprobar',
    okColor: 'var(--ok)',
    icon: '✓',
    onConfirm: () => form.submit()
  });
}
</script>
</body>
</html>
