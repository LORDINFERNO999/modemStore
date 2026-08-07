<?php
// mis-pedidos.php — Historial de pedidos del usuario
require_once 'includes/auth.php';
require_once 'includes/funciones.php';
requireLogin();

$usuarioId = $_SESSION['usuario_id'];

// Vencer servicios cuyo plazo ya pasó
$pdo->prepare("UPDATE pedidos SET estado='vencido' WHERE usuario_id=? AND estado='entregado' AND fecha_vencimiento IS NOT NULL AND fecha_vencimiento < NOW()")
    ->execute([$usuarioId]);

$stmt = $pdo->prepare("
    SELECT p.*, pl.nombre AS plan_nombre, s.nombre AS servicio_nombre, s.color, s.imagen AS servicio_imagen
    FROM pedidos p
    JOIN planes pl ON p.plan_id = pl.id
    JOIN servicios s ON pl.servicio_id = s.id
    WHERE p.usuario_id = ?
    ORDER BY p.created_at DESC
");
$stmt->execute([$usuarioId]);
$pedidos = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mis pedidos — <?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#07070d;--surface:#13131a;--surface2:#1c1c27;--accent:#7c6dfa;--accent2:#f472b6;--text:#f0f0f8;--muted:#7a7a96;--border:rgba(255,255,255,0.07);--ok:#34d399;--warn:#fbbf24;--danger:#f87171;--r:16px;}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased;}
nav{display:flex;align-items:center;justify-content:space-between;padding:0 5%;height:64px;background:rgba(7,7,13,.85);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50;backdrop-filter:blur(20px);}
.nav-logo{font-family:'Syne',sans-serif;font-weight:800;font-size:20px;background:linear-gradient(135deg,#a89cf7,var(--accent2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;text-decoration:none;}
.nav-links{display:flex;gap:8px;align-items:center;}
.nav-links a{color:var(--muted);text-decoration:none;font-size:14px;padding:8px 14px;border-radius:10px;transition:all .2s;font-weight:500;}
.nav-links a:hover{color:var(--text);background:var(--surface2);}
.container{max-width:980px;margin:0 auto;padding:34px 20px 60px;}
h1{font-family:'Syne',sans-serif;font-size:26px;margin-bottom:6px;}
.sub{color:var(--muted);font-size:14px;margin-bottom:28px;}
.order{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:18px 20px;margin-bottom:14px;}
.order-head{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;}
.order-info{display:flex;align-items:center;gap:12px;}
.order-logo{width:44px;height:44px;border-radius:11px;object-fit:contain;background:var(--surface2);padding:6px;}
.order-title{font-weight:600;}
.order-meta{font-size:12px;color:var(--muted);margin-top:2px;}
.order-right{text-align:right;}
.order-monto{font-family:'Syne',sans-serif;font-weight:800;font-size:17px;}
.badge{padding:4px 11px;border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;display:inline-block;margin-top:4px;}
.badge-ok{background:rgba(52,211,153,.14);color:var(--ok);}
.badge-pend{background:rgba(251,191,36,.14);color:var(--warn);}
.badge-no{background:rgba(248,113,113,.14);color:var(--danger);}
.creds{margin-top:14px;padding-top:14px;border-top:1px dashed var(--border);display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;}
.cred{background:var(--surface2);border-radius:10px;padding:9px 12px;}
.cred .k{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;}
.cred .v{font-size:14px;font-weight:600;word-break:break-all;}
.pend-note{margin-top:12px;font-size:13px;color:var(--warn);background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.2);border-radius:10px;padding:10px 12px;}
.empty{text-align:center;padding:70px 20px;color:var(--muted);}
.empty a{color:var(--accent);font-weight:600;text-decoration:none;}
</style>
</head>
<body>
<nav>
  <a href="dashboard.php" class="nav-logo"><?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?></a>
  <div class="nav-links">
    <a href="dashboard.php">Mi cuenta</a>
    <a href="dashboard.php?tab=billetera">Recargar</a>
    <a href="logout.php">Salir</a>
  </div>
</nav>

<div class="container">
  <h1>Mis pedidos</h1>
  <p class="sub"><?= count($pedidos) ?> pedido<?= count($pedidos) != 1 ? 's' : '' ?> en total</p>

  <?php if (empty($pedidos)): ?>
    <div class="empty">
      Aún no tienes pedidos.<br>
      <a href="dashboard.php">Explora la tienda →</a>
    </div>
  <?php else: ?>
    <?php foreach ($pedidos as $p): ?>
      <div class="order">
        <div class="order-head">
          <div class="order-info">
            <img class="order-logo" src="assets/img/<?= htmlspecialchars($p['servicio_imagen'] ?? '') ?>" onerror="this.style.opacity='.15'" alt="">
            <div>
              <div class="order-title"><?= htmlspecialchars($p['servicio_nombre']) ?> — <?= htmlspecialchars($p['plan_nombre']) ?></div>
              <div class="order-meta">Pedido #<?= $p['id'] ?> · <?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></div>
            </div>
          </div>
          <div class="order-right">
            <div class="order-monto"><?= formatMoney((float)$p['monto']) ?></div>
            <span class="badge badge-<?= $p['estado'] === 'entregado' ? 'ok' : (in_array($p['estado'],['cancelado','vencido']) ? 'no' : 'pend') ?>"><?= htmlspecialchars($p['estado']) ?></span>
          </div>
        </div>

        <?php
          $diasRest = !empty($p['fecha_vencimiento']) ? (int)ceil((strtotime($p['fecha_vencimiento']) - time()) / 86400) : null;
        ?>
        <?php if ($p['estado'] === 'entregado'): ?>
          <div style="margin-top:10px;font-size:12px;color:<?= $diasRest <= 3 ? 'var(--warn)' : 'var(--muted)' ?>">
            📆 Vigente hasta el <?= date('d/m/Y', strtotime($p['fecha_vencimiento'])) ?> · <b><?= max(0,$diasRest) ?> día<?= $diasRest==1?'':'s' ?> restante<?= $diasRest==1?'':'s' ?></b>
          </div>
          <div class="creds">
            <div class="cred"><div class="k">Usuario</div><div class="v"><?= htmlspecialchars($p['cred_usuario'] ?: '—') ?></div></div>
            <div class="cred"><div class="k">Contraseña</div><div class="v"><?= htmlspecialchars($p['cred_password'] ?: '—') ?></div></div>
            <?php if (!empty($p['cred_perfil'])): ?>
            <div class="cred"><div class="k">Perfil</div><div class="v"><?= htmlspecialchars($p['cred_perfil']) ?></div></div>
            <?php endif; ?>
            <?php if (!empty($p['cred_pin'])): ?>
            <div class="cred"><div class="k">PIN</div><div class="v"><?= htmlspecialchars($p['cred_pin']) ?></div></div>
            <?php endif; ?>
          </div>
        <?php elseif ($p['estado'] === 'pendiente'): ?>
          <div class="pend-note">⏳ Estamos validando tu transferencia. Te entregaremos los datos de acceso en breve.</div>
        <?php elseif ($p['estado'] === 'vencido'): ?>
          <div class="pend-note" style="color:var(--danger);background:rgba(248,113,113,.08);border-color:rgba(248,113,113,.2)">🔒 Servicio vencido<?= $p['fecha_vencimiento'] ? ' el '.date('d/m/Y', strtotime($p['fecha_vencimiento'])) : '' ?>. Cómpralo de nuevo para renovarlo.</div>
        <?php elseif ($p['estado'] === 'cancelado'): ?>
          <div class="pend-note" style="color:var(--danger);background:rgba(248,113,113,.08);border-color:rgba(248,113,113,.2)">
            ✕ <b>Pedido rechazado.</b>
            <?php if (!empty($p['nota_admin'])): ?><br>Motivo: <?= htmlspecialchars($p['nota_admin']) ?><?php endif; ?>
            <br>Si deseas intentarlo de nuevo, realiza la compra desde la <b>Tienda</b>.
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
</body>
</html>