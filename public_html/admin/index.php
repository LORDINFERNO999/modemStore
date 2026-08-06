<?php
// admin/index.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/funciones.php';
require_once __DIR__ . '/../includes/seguridad.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequire();
}

$msg = '';

// Vencer servicios cuyo plazo ya pasó
$pdo->query("UPDATE pedidos SET estado='vencido' WHERE estado='entregado' AND fecha_vencimiento IS NOT NULL AND fecha_vencimiento < NOW()");

// Pedidos recientes
$pedidos = $pdo->query("
    SELECT p.*, u.nombre, u.email, pl.nombre as plan_nombre, s.nombre as serv_nombre
    FROM pedidos p
    JOIN usuarios u ON p.usuario_id = u.id
    JOIN planes pl ON p.plan_id = pl.id
    JOIN servicios s ON pl.servicio_id = s.id
    ORDER BY p.created_at DESC LIMIT 30
")->fetchAll();

// Stats
$stats = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM usuarios WHERE rol='cliente') as total_usuarios,
        (SELECT COUNT(*) FROM pedidos WHERE estado IN ('entregado','vencido')) as pedidos_ok,
        (SELECT COUNT(*) FROM pedidos WHERE estado='pendiente') as pedidos_pend,
        (SELECT COALESCE(SUM(monto),0) FROM pedidos WHERE estado IN ('entregado','vencido')) as total_ventas
")->fetch();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Admin — <?= SITE_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root{--bg:#0a0a0f;--surface:#13131a;--surface2:#1c1c27;--accent:#7c6dfa;--text:#f0f0f8;--muted:#6b6b80;--border:rgba(255,255,255,0.07);--success:#34d399;--danger:#f87171;--warning:#fbbf24;}
  *{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);}
  nav{background:var(--surface);border-bottom:1px solid var(--border);padding:0 24px;height:60px;display:flex;align-items:center;justify-content:space-between;}
  .nav-logo{font-family:'Inter',sans-serif;font-weight:800;font-size:18px;background:linear-gradient(135deg,var(--accent),#f472b6);-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
  .container{max-width:1200px;margin:0 auto;padding:28px 20px;}
  h2{font-family:'Inter',sans-serif;font-size:22px;margin-bottom:20px;}
  .stats{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:32px;}
  .stat{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:20px;}
  .stat-val{font-family:'Inter',sans-serif;font-size:28px;font-weight:800;color:var(--accent);}
  .stat-lbl{color:var(--muted);font-size:13px;margin-top:4px;}
  table{width:100%;border-collapse:collapse;background:var(--surface);border-radius:16px;overflow:hidden;border:1px solid var(--border);margin-bottom:32px;}
  th{background:var(--surface2);padding:12px 16px;text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);}
  td{padding:12px 16px;font-size:14px;border-top:1px solid var(--border);}
  .badge{padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;font-family:'Inter',sans-serif;text-transform:uppercase;}
  .badge-pend{background:rgba(251,191,36,.15);color:var(--warning);}
  .badge-ok{background:rgba(52,211,153,.15);color:var(--success);}
  .badge-no{background:rgba(248,113,113,.15);color:var(--danger);}
  .flash{background:rgba(52,211,153,.15);border:1px solid rgba(52,211,153,.3);color:var(--success);border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:14px;}
  a.back{color:var(--muted);font-size:13px;text-decoration:none;}
  a.back:hover{color:var(--text);}
  @media(max-width:768px){
    .stats{grid-template-columns:repeat(2,1fr);gap:10px;}
    .stat{padding:16px;}
    .stat-val{font-size:22px;}
    nav{padding:0 16px;}
    .container{padding:20px 16px;}

    /* Tabla -> tarjetas en movil (sin scroll horizontal) */
    table{border:none;background:none;border-radius:0;}
    table thead{display:none;}
    table tr{display:block;background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:14px 16px;margin-bottom:12px;}
    table td{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:6px 0;border-top:none;font-size:13px;text-align:right;}
    table td::before{content:attr(data-label);font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:700;flex-shrink:0;text-align:left;padding-top:2px;}
    table td[data-label="Usuario"]{flex-direction:column;align-items:flex-start;text-align:left;border-bottom:1px solid var(--border);padding-bottom:10px;margin-bottom:4px;}
    table td[data-label="Usuario"]::before{margin-bottom:2px;}
  }
  @media(max-width:480px){
    .stats{grid-template-columns:1fr;}
  }
</style>
</head>
<body>
<nav>
  <div class="nav-logo">⚙️ Admin — <?= SITE_NAME ?></div>
  <div style="display:flex;gap:10px;align-items:center">
    <a class="back" href="../dashboard.php">← Ir al sitio</a>
    <a class="back" href="../logout.php">Salir</a>
  </div>
</nav>
<div class="container">
  <?php if ($msg): ?><div class="flash">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>

  <div class="stats">
    <div class="stat"><div class="stat-val"><?= $stats['total_usuarios'] ?></div><div class="stat-lbl">Usuarios</div></div>
    <div class="stat"><div class="stat-val"><?= $stats['pedidos_ok'] ?></div><div class="stat-lbl">Servicios entregados</div></div>
    <div class="stat"><div class="stat-val" style="color:var(--warning)"><?= $stats['pedidos_pend'] ?></div><div class="stat-lbl">Pedidos por validar</div></div>
    <div class="stat"><div class="stat-val" style="color:var(--success)">$<?= number_format($stats['total_ventas'],0,'.','.') ?></div><div class="stat-lbl">Total ventas</div></div>
  </div>

  <p style="margin-bottom:20px"><a href="pedidos.php" style="color:var(--accent);font-weight:600;text-decoration:none">→ Ir a Gestionar pedidos (validar y entregar)</a></p>

  <h2>📦 Pedidos recientes</h2>
  <table>
    <thead>
      <tr><th>ID</th><th>Usuario</th><th>Servicio / Plan</th><th>Monto</th><th>Estado</th><th>Fecha</th></tr>
    </thead>
    <tbody>
    <?php foreach ($pedidos as $p): ?>
    <tr>
      <td data-label="ID">#<?= $p['id'] ?></td>
      <td data-label="Usuario"><?= htmlspecialchars($p['nombre']) ?><br><small style="color:var(--muted)"><?= htmlspecialchars($p['email']) ?></small></td>
      <td data-label="Servicio / Plan"><?= htmlspecialchars($p['serv_nombre']) ?> — <?= htmlspecialchars($p['plan_nombre']) ?></td>
      <td data-label="Monto">$<?= number_format($p['monto'],0,'.','.') ?></td>
      <td data-label="Estado"><span class="badge badge-<?= $p['estado']==='entregado'?'ok':(in_array($p['estado'],['cancelado','vencido'])?'no':'pend') ?>"><?= $p['estado'] ?></span></td>
      <td data-label="Fecha" style="color:var(--muted);font-size:12px"><?= date('d/m/Y H:i',strtotime($p['created_at'])) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
</body>
</html>