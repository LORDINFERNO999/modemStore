<?php
require_once 'includes/auth.php';
require_once 'includes/funciones.php';
require_once 'includes/seguridad.php';
requireLogin();

$usuarioId = $_SESSION['usuario_id'];

$stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([$usuarioId]);
$usuario = $stmt->fetch();

$esAdmin = isset($usuario['rol']) && $usuario['rol'] === 'admin';
$esRevendedor = !empty($usuario['es_revendedor']);

$planes = $pdo->query("
    SELECT p.*, s.nombre as servicio_nombre, s.imagen as servicio_imagen, s.color as servicio_color, s.imagen_circulo as servicio_imagen_circulo,
           (SELECT COUNT(*) FROM cuentas_stock cs WHERE cs.plan_id = p.id AND cs.estado = 'disponible') as stock
    FROM planes p JOIN servicios s ON p.servicio_id = s.id
    WHERE p.estado = 'activo' ORDER BY s.nombre, p.precio
")->fetchAll();

$combos = $pdo->query("
    SELECT c.*,
           GROUP_CONCAT(cp.plan_id ORDER BY cp.plan_id) as plan_ids,
           GROUP_CONCAT(s.nombre ORDER BY s.nombre SEPARATOR '|||') as servicios_nombres,
           GROUP_CONCAT(s.imagen ORDER BY s.nombre SEPARATOR '|||') as servicios_imagenes
    FROM combos c
    JOIN combo_planes cp ON cp.combo_id = c.id
    JOIN planes p ON cp.plan_id = p.id
    JOIN servicios s ON p.servicio_id = s.id
    WHERE c.estado = 'activo'
    GROUP BY c.id
    ORDER BY c.nombre
")->fetchAll();

// ── VERIFICAR STOCK DE CADA COMBO ────────────────────────────────────────────
foreach ($combos as &$combo) {
    $combo['agotado'] = false;
    $planIds = array_filter(array_map('intval', explode(',', $combo['plan_ids'] ?? '')));
    foreach ($planIds as $pid) {
        $stk = $pdo->prepare("SELECT COUNT(*) FROM cuentas_stock WHERE plan_id=? AND estado='disponible'");
        $stk->execute([$pid]);
        if ((int)$stk->fetchColumn() === 0) { $combo['agotado'] = true; break; }
    }
}
unset($combo);
// ─────────────────────────────────────────────────────────────────────────────

// Vencer automáticamente los servicios del usuario cuyo plazo ya pasó
$pdo->prepare("UPDATE pedidos SET estado='vencido' WHERE usuario_id=? AND estado='entregado' AND fecha_vencimiento IS NOT NULL AND fecha_vencimiento < NOW()")
    ->execute([$usuarioId]);

$stmt = $pdo->prepare("
    SELECT p.*, pl.nombre as plan_nombre, s.nombre as servicio_nombre, s.color, s.imagen as servicio_imagen
    FROM pedidos p
    JOIN planes pl ON p.plan_id = pl.id
    JOIN servicios s ON pl.servicio_id = s.id
    WHERE p.usuario_id = ? ORDER BY p.created_at DESC LIMIT 20
");
$stmt->execute([$usuarioId]);
$pedidos = $stmt->fetchAll();

// Slider / Pasarela: MISMAS imágenes que la portada (index.php).
try {
    $hbSlides = $pdo->query("
        SELECT * FROM sliders
        WHERE estado = 'activo'
        ORDER BY id ASC
    ")->fetchAll();
} catch (Exception $e) {
    $hbSlides = [];
}

$serviciosGrupo = [];
foreach ($planes as $plan) {
    $serviciosGrupo[$plan['servicio_nombre']][] = $plan;
}

// Datos de pago configurados por el admin (para el modal de compra)
$pagoQr      = getConfig('pago_qr', '');
$pagoTitular = getConfig('pago_titular', '');
$pagoLlave   = getConfig('pago_llave', '');
$pagoBanco   = getConfig('pago_banco', '');
$pagoInstr   = getConfig('pago_instrucciones', '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<link rel="icon" type="image/png" href="assets/img/logo-crop.png">
<title><?= SITE_NAME ?> — Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#0d0d0d;--surface:#161616;--surface2:#1e1e1e;--surface3:#262626;
  --border:rgba(255,255,255,0.08);--border2:rgba(255,255,255,0.14);
  --accent:#7c6dfa;--accent2:#f472b6;
  --text:#ffffff;--text2:#a3a3a3;--text3:#666666;
  --success:#1db954;--warning:#f59e0b;--danger:#ef4444;
  --r-sm:6px;--r-md:10px;--r-lg:16px;--r-xl:22px;
  --ease:cubic-bezier(0.4,0,0.2,1);
}
*{margin:0;padding:0;box-sizing:border-box}
html{overflow-x:hidden;width:100%}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased;overflow-x:hidden;width:100%;max-width:100vw}
img{max-width:100%}


/* SIDEBAR */
.layout{display:flex;min-height:100vh;width:100%;max-width:100vw;overflow-x:hidden}
.sidebar{width:240px;background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:200;transition:transform 0.3s var(--ease)}
.sidebar-logo{padding:24px 16px 20px;border-bottom:1px solid var(--border);display:flex;flex-direction:column;align-items:center;gap:8px;text-decoration:none}
.sidebar-logo img{width:160px;max-width:90%;height:auto;object-fit:contain}
.sidebar-nav{flex:1;padding:16px 12px;display:flex;flex-direction:column;gap:2px;overflow-y:auto}
.nav-item{display:flex;align-items:center;gap:12px;padding:11px 14px;border-radius:var(--r-md);cursor:pointer;font-size:14px;font-weight:500;color:var(--text2);transition:all 0.2s var(--ease);border:none;background:none;width:100%;text-align:left;text-decoration:none}
.nav-item svg{width:18px;height:18px;flex-shrink:0;opacity:0.7}
.nav-item:hover{background:var(--surface2);color:var(--text)}
.nav-item:hover svg{opacity:1}
.nav-item.active{background:rgba(124,109,250,0.12);color:var(--text);border:1px solid rgba(124,109,250,0.2)}
.nav-item.active svg{opacity:1;color:var(--accent)}
.nav-label{flex:1}
.nav-badge{background:var(--accent);color:#fff;font-size:10px;font-weight:700;padding:2px 7px;border-radius:20px}
.sidebar-footer{padding:16px 12px;border-top:1px solid var(--border)}
.user-row{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:var(--r-md);background:var(--surface2)}
.user-avatar{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#fff;flex-shrink:0}
.user-name{font-size:13px;font-weight:600;line-height:1.2}
.user-role{font-size:11px;color:var(--text3)}
.btn-logout-side{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:var(--r-md);margin-top:4px;color:var(--text3);font-size:13px;font-weight:500;cursor:pointer;transition:all 0.2s;text-decoration:none;border:none;background:none;width:100%}
.btn-logout-side svg{width:16px;height:16px}
.btn-logout-side:hover{color:var(--danger);background:rgba(239,68,68,0.08)}

/* MAIN */
.main{margin-left:240px;flex:1;display:flex;flex-direction:column;min-height:100vh;min-width:0;max-width:100%;overflow-x:hidden}
.topbar{height:64px;background:rgba(13,13,13,0.9);backdrop-filter:blur(20px);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 32px;position:sticky;top:0;z-index:100;width:100%}
.topbar-left{display:flex;flex-direction:column}
.topbar-title{font-size:16px;font-weight:600}
.topbar-sub{font-size:12px;color:var(--text3);margin-top:1px}
.topbar-right{display:flex;align-items:center;gap:12px;flex-shrink:0}
.topbar-left{min-width:0}
.topbar-title{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.topbar > div:first-child{min-width:0;flex:1}
@media(max-width:600px){
  .topbar{padding:0 12px;gap:8px}
  .topbar-sub{display:none}
  .topbar-right .search-box{display:none}
}
.search-box{display:flex;align-items:center;gap:8px;background:var(--surface2);border:1px solid var(--border);border-radius:var(--r-md);padding:8px 14px;transition:all 0.2s}
.search-box:focus-within{border-color:rgba(255,255,255,0.2)}
.search-box svg{width:15px;height:15px;color:var(--text3);flex-shrink:0}
.search-box input{background:none;border:none;outline:none;color:var(--text);font-family:'Inter',sans-serif;font-size:13px;width:200px}
.search-box input::placeholder{color:var(--text3)}

.content{flex:1;padding:32px;overflow-x:hidden;width:100%;max-width:100%;min-width:0}
.panel{display:none;min-width:0;max-width:100%}
.panel.active{display:block;animation:fadeUp 0.35s var(--ease)}
.admin-embed{margin:-32px;height:calc(100vh - 64px)}
.admin-embed iframe{width:100%;height:100%;border:0;display:block;background:var(--bg)}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}

/* SLIDER / PASARELA */
.hb-slider{position:relative;width:100%;height:300px;overflow:hidden;cursor:grab;background:#0a0a0a;border:1px solid var(--border);border-radius:var(--r-xl);margin-bottom:24px}
.hb-slider:active{cursor:grabbing}
.hb-slide-pasarela{position:absolute;inset:0;opacity:0;pointer-events:none;transition:opacity .55s ease}
.hb-slide-pasarela.active{opacity:1;pointer-events:auto}
.hb-slide-pasarela a{display:block;width:100%;height:100%}
.hb-slide-pasarela img{width:100%;height:100%;object-fit:cover;object-position:center;display:block;pointer-events:none}
.hb-vignette{position:absolute;inset:0;pointer-events:none;background:linear-gradient(0deg,rgba(0,0,0,0.35) 0%,transparent 35%),linear-gradient(180deg,rgba(0,0,0,0.2) 0%,transparent 30%)}
.hb-brand{position:absolute;top:16px;right:18px;z-index:20;font-size:13px;font-weight:800;letter-spacing:1px;text-align:right;color:rgba(255,255,255,0.9);text-shadow:0 2px 8px rgba(0,0,0,0.5);line-height:1.2}
.hb-arrow{position:absolute;top:50%;transform:translateY(-50%);z-index:30;width:42px;height:42px;border-radius:50%;background:rgba(0,0,0,0.4);border:1px solid rgba(255,255,255,0.2);color:rgba(255,255,255,0.85);cursor:pointer;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(6px);transition:all .2s}
.hb-arrow:hover{background:rgba(255,255,255,0.2);color:#fff}
.hb-prev{left:16px}
.hb-next{right:16px}
.hb-dots{position:absolute;bottom:14px;left:50%;transform:translateX(-50%);z-index:30;display:flex;gap:8px}
.hb-dot{width:8px;height:8px;border-radius:50%;background:rgba(255,255,255,0.35);border:none;cursor:pointer;padding:0;transition:all .3s}
.hb-dot.active{background:#fff;width:24px;border-radius:4px}
.hb-progress{position:absolute;bottom:0;left:0;height:3px;z-index:30;background:rgba(255,255,255,0.55);width:0%;transition:width linear}
.hb-slider-empty{display:flex;align-items:center;justify-content:center;height:100%;color:rgba(255,255,255,0.4);font-size:14px;gap:8px;text-align:center;padding:0 16px}
.hb-slider-empty a{color:var(--accent)}

/* BURBUJAS DE SERVICIOS */
.srv-bubbles{display:flex;align-items:flex-start;gap:14px;overflow-x:auto;scrollbar-width:none;-ms-overflow-style:none;padding:4px 0 24px;margin-bottom:8px;-webkit-overflow-scrolling:touch;border-bottom:1px solid var(--border);max-width:100%}
.srv-bubbles::-webkit-scrollbar{display:none}
.srv-bubble{display:flex;flex-direction:column;align-items:center;gap:7px;flex-shrink:0;text-decoration:none;cursor:pointer;transition:transform .2s ease;min-width:72px;position:relative}
.srv-combo-badge{position:absolute;top:-6px;right:8px;z-index:5;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;font-size:8px;font-weight:800;padding:2px 6px;border-radius:10px;line-height:1.3;letter-spacing:.3px;box-shadow:0 2px 6px rgba(0,0,0,.4);pointer-events:none}
.srv-bubble:hover{transform:scale(1.08)}
.srv-bubble:active{transform:scale(0.92)}
.srv-bubble-circle{width:64px;height:64px;border-radius:50%;border:2.5px solid;display:flex;align-items:center;justify-content:center;overflow:hidden;transition:all .25s ease}
.srv-bubble-circle img{width:46px;height:46px;object-fit:contain;filter:drop-shadow(0 2px 4px rgba(0,0,0,0.4))}
.srv-bubble:hover .srv-bubble-circle{box-shadow:0 0 16px rgba(255,255,255,0.15);border-width:3px}
.srv-bubble-name{font-size:10px;font-weight:600;color:var(--text2);white-space:nowrap;text-align:center;max-width:72px;overflow:hidden;text-overflow:ellipsis}
.srv-bubble:hover .srv-bubble-name{color:var(--text)}
.srv-bubble-active .srv-bubble-circle{border-width:3px;box-shadow:0 0 18px rgba(124,109,250,0.5);border-color:var(--accent) !important}
.srv-bubble-active .srv-bubble-name{color:var(--text)}

/* TIENDA */
.servicio-block{margin-bottom:48px;scroll-margin-top:80px;min-width:0}
.srv-header{display:flex;align-items:center;gap:14px;margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid var(--border)}
.srv-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.srv-name{font-size:16px;font-weight:700;letter-spacing:-0.2px;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis}
.srv-count{font-size:12px;color:var(--text3);font-weight:500;flex-shrink:0}

.planes-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;width:100%}
.plan-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-xl);padding:22px;cursor:pointer;transition:all 0.25s var(--ease);position:relative;overflow:hidden;display:flex;flex-direction:column;gap:14px;min-width:0;max-width:100%}
.plan-card::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,var(--c,transparent) 0%,transparent 60%);opacity:0;transition:opacity 0.3s;pointer-events:none}
.plan-card:hover{border-color:var(--border2);transform:translateY(-3px);box-shadow:0 20px 40px rgba(0,0,0,0.5)}
.plan-card:hover::before{opacity:0.06}
.pc-banner{position:relative;aspect-ratio:1/1;border-radius:var(--r-md);overflow:hidden;background:linear-gradient(135deg,var(--c,#333),#0b0b0b);width:100%}
.pc-banner img{width:100%;height:100%;object-fit:cover;display:block;transition:transform 0.35s var(--ease)}
.plan-card:hover .pc-banner img{transform:scale(1.06)}
.pc-badge{position:absolute;top:8px;right:8px;background:rgba(0,0,0,0.55);backdrop-filter:blur(4px);color:#fff;font-size:10px;font-weight:700;padding:4px 10px;border-radius:20px;letter-spacing:0.3px;max-width:80%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pc-info{flex:1;min-width:0}
.pc-nombre{font-size:14px;font-weight:700;line-height:1.3;letter-spacing:-0.1px;word-break:break-word;overflow-wrap:break-word}
.pc-desc{font-size:12px;color:var(--text3);margin-top:4px;line-height:1.5;word-break:break-word;overflow-wrap:break-word}
.pc-footer{display:flex;align-items:center;justify-content:space-between;margin-top:auto;padding-top:14px;border-top:1px solid var(--border);gap:8px;min-width:0}
.pc-price-block{min-width:0}
.pc-price{font-size:22px;font-weight:800;letter-spacing:-0.5px;line-height:1;background:linear-gradient(135deg,#c9b6ff,var(--accent2));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.pc-price-old{font-size:12px;color:var(--text3);text-decoration:line-through;margin-bottom:2px}
.pc-margen{font-size:11px;color:var(--success);font-weight:700;margin-top:4px}
.combo-chip-row{display:grid;grid-template-columns:repeat(2,1fr);gap:6px;padding:12px;width:100%;height:100%;align-content:center}
.combo-chip{width:100%;aspect-ratio:1/1;border-radius:12px;background:rgba(0,0,0,.5);border:1px solid rgba(255,255,255,.08);overflow:hidden}
.combo-chip img{width:100%;height:100%;object-fit:cover}
.combo-chip:only-child{grid-column:1/-1;width:70%;justify-self:center}
.combo-plus{display:none}
.combo-tags{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
.combo-tag{display:flex;align-items:center;gap:5px;font-size:10.5px;font-weight:700;color:var(--text2);background:var(--surface2);border:1px solid var(--border);border-radius:20px;padding:5px 10px}
.combo-tag b{color:var(--text)}
.combo-hero{text-align:center;margin-bottom:24px}
.combo-hero-pill{display:inline-flex;align-items:center;gap:8px;padding:10px 24px;border-radius:30px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;font-weight:800;font-size:14px;letter-spacing:.3px;margin-bottom:22px;box-shadow:0 8px 20px rgba(124,109,250,.35)}
.combo-detail-row{display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.combo-detail-card{width:150px;border-radius:16px;overflow:hidden;background:var(--surface);border:1px solid var(--border)}
.combo-detail-card img{display:block;width:100%;height:150px;object-fit:cover;background:rgba(255,255,255,.03)}
.combo-detail-label{font-size:9px;font-weight:800;text-align:center;padding:6px 4px;color:var(--text2);letter-spacing:.4px;text-transform:uppercase;border-top:1px solid var(--border)}
.combo-detail-plus{font-size:20px;font-weight:800;color:var(--text3);flex-shrink:0}
@media(max-width:600px){
  .combo-detail-card{width:110px}
  .combo-detail-card img{height:110px}
  .combo-chip-row{padding:8px;gap:5px}
}
.plan-card-rev{border-color:rgba(245,158,11,0.35)}
.plan-card-rev .pc-badge{background:rgba(245,158,11,0.92);color:#1a1300}
.pc-dias{font-size:11px;color:var(--text3);margin-top:3px}
.btn-buy{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;border:none;border-radius:var(--r-md);font-family:'Inter',sans-serif;font-size:12px;font-weight:700;padding:9px 16px;cursor:pointer;transition:all 0.2s;white-space:nowrap;letter-spacing:0.2px;flex-shrink:0}
.btn-buy:hover{transform:scale(1.05);box-shadow:0 8px 18px rgba(124,109,250,0.4)}

/* AGOTADO */
.plan-card.agotado{opacity:0.55;cursor:not-allowed}
.plan-card.agotado:hover{transform:none;box-shadow:none;border-color:var(--border)}
.plan-card.agotado .pc-banner img{filter:grayscale(0.5);opacity:0.7}
.pc-badge-agotado{background:rgba(239,68,68,0.9)!important;color:#fff!important}
.btn-buy:disabled,.btn-buy.disabled{background:#333!important;color:#777!important;cursor:not-allowed!important;transform:none!important;box-shadow:none!important}

/* PEDIDOS */
.pedido-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-xl);padding:20px 22px;display:flex;gap:16px;align-items:flex-start;transition:border-color 0.2s;margin-bottom:10px;min-width:0;max-width:100%}
.pedido-card:hover{border-color:var(--border2)}
.pedido-img{width:52px;height:52px;border-radius:var(--r-md);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0}
.pedido-img img{width:100%;height:100%;object-fit:contain;padding:6px}
.pedido-body{flex:1;min-width:0}
.pedido-nombre{font-size:14px;font-weight:700;letter-spacing:-0.1px;word-break:break-word}
.pedido-meta{font-size:12px;color:var(--text3);margin-top:3px}
.pedido-creds{margin-top:12px;background:var(--surface2);border:1px solid var(--border);border-radius:var(--r-md);padding:12px 14px;display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:6px 20px}
.cred-row{display:flex;flex-direction:column;gap:1px;min-width:0}
.cred-label{font-size:10px;color:var(--text3);text-transform:uppercase;letter-spacing:0.5px;font-weight:600}
.cred-value{font-size:13px;font-weight:600;font-family:'SF Mono','Fira Code',monospace;word-break:break-all}
.status-pill{padding:5px 12px;border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;white-space:nowrap;flex-shrink:0;align-self:flex-start}

/* MODAL */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,0.8);z-index:1000;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity 0.25s var(--ease);backdrop-filter:blur(12px)}
.overlay.open{opacity:1;pointer-events:all}
.modal-box{background:var(--surface);border:1px solid var(--border2);border-radius:var(--r-xl);padding:32px;width:100%;max-width:420px;max-height:92vh;overflow-y:auto;transform:scale(0.95) translateY(10px);transition:transform 0.3s var(--ease);position:relative}
.overlay.open .modal-box{transform:scale(1) translateY(0)}
.modal-close{position:absolute;top:16px;right:16px;width:30px;height:30px;border-radius:50%;background:var(--surface2);border:none;color:var(--text2);cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;transition:all 0.2s}
.modal-close:hover{background:var(--surface3);color:var(--text)}
.modal-head{display:flex;align-items:center;gap:14px;margin-bottom:22px}
.modal-img-wrap{width:52px;height:52px;border-radius:var(--r-md);background:var(--surface2);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0}
.modal-img-wrap img{width:100%;height:100%;object-fit:contain;padding:8px}
.modal-srv{font-size:18px;font-weight:800;letter-spacing:-0.3px}
.modal-plan{font-size:13px;color:var(--text3);margin-top:2px}
.modal-price-hero{text-align:center;padding:24px 0 20px}
.modal-price-big{font-size:48px;font-weight:900;letter-spacing:-2px;line-height:1}
.modal-price-cur{font-size:14px;color:var(--text3);margin-top:6px}
.btn-confirm{width:100%;background:#fff;color:#000;border:none;border-radius:var(--r-md);font-family:'Inter',sans-serif;font-size:15px;font-weight:800;padding:15px;cursor:pointer;transition:all 0.2s;letter-spacing:-0.2px}
.btn-confirm:hover{background:#e8e8e8}
.btn-confirm:disabled{opacity:0.5;cursor:not-allowed}
.modal-alert{border-radius:var(--r-md);padding:11px 14px;font-size:13px;margin-top:12px;display:none;text-align:center}
.modal-alert.err{background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);color:var(--danger)}
.modal-alert.ok{background:rgba(29,185,84,0.08);border:1px solid rgba(29,185,84,0.2);color:var(--success)}

/* FORM ALERTS */
.f-alert{border-radius:var(--r-md);padding:11px 14px;font-size:13px;margin-top:12px;display:none;text-align:center}
.f-alert.err{background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);color:var(--danger)}
.f-alert.ok{background:rgba(29,185,84,0.08);border:1px solid rgba(29,185,84,0.2);color:var(--success)}

/* EMPTY */
.empty{text-align:center;padding:64px 20px;color:var(--text2)}
.empty svg{width:40px;height:40px;margin-bottom:16px;color:var(--accent);padding:18px;border-radius:50%;background:rgba(124,109,250,0.10);border:1px solid rgba(124,109,250,0.25);box-sizing:content-box}
.empty p{font-size:15px;font-weight:500}

/* BILLETERA */
.btn-monto{padding:8px 16px;border-radius:var(--r-md);background:var(--surface2);border:1px solid var(--border2);color:var(--text2);font-family:'Inter',sans-serif;font-size:13px;font-weight:600;cursor:pointer;transition:all 0.2s}
.btn-monto:hover{border-color:var(--accent);color:var(--accent);background:rgba(124,109,250,0.08)}
.btn-monto.active{border-color:var(--accent);color:var(--accent);background:rgba(124,109,250,0.12)}

/* MODAL RECARGA ÉXITO */
.recarga-overlay{position:fixed;inset:0;z-index:3000;background:rgba(0,0,0,.78);backdrop-filter:blur(10px);display:none;align-items:center;justify-content:center;padding:20px}
.recarga-overlay.open{display:flex;animation:fadeUp .3s var(--ease)}
.recarga-box{background:var(--surface);border:1px solid var(--border2);border-radius:24px;padding:40px 32px 32px;max-width:420px;width:100%;text-align:center;box-shadow:0 30px 80px rgba(0,0,0,.7);position:relative}
.recarga-icon{font-size:56px;line-height:1;margin-bottom:16px}
.recarga-title{font-size:20px;font-weight:900;letter-spacing:-.4px;margin-bottom:8px}
.recarga-sub{font-size:14px;color:var(--text2);line-height:1.6;margin-bottom:24px}
.recarga-steps{display:flex;flex-direction:column;gap:10px;margin-bottom:28px;text-align:left}
.recarga-step{display:flex;align-items:center;gap:12px;background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:12px 14px}
.recarga-step-num{width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.recarga-step-txt{font-size:13px;color:var(--text2);line-height:1.4}
.recarga-step-txt b{color:var(--text);display:block;margin-bottom:1px}
.recarga-bar{height:3px;border-radius:3px;background:linear-gradient(90deg,var(--accent),var(--accent2));margin-bottom:20px;animation:cfShrink 6s linear forwards}
.recarga-btn-ok{width:100%;padding:13px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;border:none;border-radius:var(--r-md);font-family:'Inter',sans-serif;font-size:15px;font-weight:800;cursor:pointer;transition:all .2s;letter-spacing:-.2px}
.recarga-btn-ok:hover{opacity:.9;transform:translateY(-1px)}
@keyframes spin{to{transform:rotate(360deg)}}


/* RESPONSIVE */
@media(max-width:900px){
  .sidebar{transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .main{margin-left:0}
  .topbar{padding:0 20px}
  .content{padding:20px}
  .menu-toggle{display:flex !important}
  .search-box input{width:140px}
  .hb-slider{height:240px}
}
@media(max-width:600px){
  .content{padding:14px}
  .hb-slider{height:185px;border-radius:16px;margin-bottom:16px}
  .hb-slide-pasarela img{object-fit:contain;background:#0a0a0a}
  .hb-arrow{display:none}
  .hb-brand{font-size:11px;top:12px;right:12px}
  .hb-dots{bottom:10px}
  .planes-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
  .plan-card{padding:10px;gap:8px;border-radius:16px}
  .pc-banner{aspect-ratio:1/1}
  .pc-badge{font-size:8.5px;padding:3px 7px;top:6px;right:6px;max-width:70%}
  .pc-nombre{font-size:12.5px}
  .pc-desc{font-size:11px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
  .pc-footer{flex-direction:column;align-items:stretch;gap:8px;padding-top:10px}
  .pc-price{font-size:17px}
  .pc-dias{font-size:10px}
  .btn-buy{width:100%;padding:9px;text-align:center}
  .servicio-block{margin-bottom:32px}
  .srv-header{gap:10px;margin-bottom:14px;padding-bottom:10px}
  .srv-name{font-size:14px}
  .pedido-card{flex-wrap:wrap;padding:16px}
  .pedido-creds{grid-template-columns:1fr}
  .modal-box{padding:22px 18px;border-radius:18px}
  .modal-price-big{font-size:38px;letter-spacing:-1px}
  .topbar{padding:0 14px}
  .topbar-title{font-size:15px}
  .bell-dropdown{width:min(320px,90vw);right:-8px}
  .cf-box{padding:26px 20px}
  .cf-msg{font-size:15px}
  .srv-bubbles{gap:10px;padding:4px 0 16px}
  .srv-bubble-circle{width:54px;height:54px}
  .srv-bubble-circle img{width:38px;height:38px}
  .srv-bubble-name{font-size:9px;max-width:60px}
  .srv-bubble{min-width:60px}
  .recarga-box{padding:28px 20px 24px}
  .recarga-title{font-size:18px}
  .qr-img-wrap img{width:160px!important;height:160px!important}
}
@media(max-width:420px){
  .hb-slider{height:150px;border-radius:14px}
  .planes-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
  .plan-card{padding:8px;gap:7px}
  .pc-banner{aspect-ratio:1/1}
  .pc-nombre{font-size:11.5px}
  .pc-desc{font-size:10.5px}
  .pc-price{font-size:16px}
  .pc-badge{font-size:8px;padding:2px 6px}
  .topbar-right .search-box{display:none}
}

.menu-toggle{display:none;align-items:center;justify-content:center;width:36px;height:36px;background:var(--surface2);border:1px solid var(--border);border-radius:var(--r-sm);cursor:pointer;color:var(--text)}
.nav-section-label{font-size:10px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--text3);padding:14px 14px 6px}
.nav-divider{height:1px;background:var(--border);margin:10px 12px}
.nav-item.admin-item svg{color:#f59e0b}
.nav-item.admin-item:hover{background:rgba(245,158,11,0.08);color:#f59e0b}
.admin-badge{font-size:9px;font-weight:800;padding:2px 6px;border-radius:4px;background:rgba(245,158,11,0.15);color:#f59e0b;letter-spacing:0.5px;text-transform:uppercase}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:190;backdrop-filter:blur(4px)}
.sidebar-overlay.open{display:block}
</style>
</head>
<body>
<div class="layout">

<aside class="sidebar" id="sidebar">
  <a href="index.php" class="sidebar-logo">
    <img src="assets/img/logo-crop.png" alt="<?= SITE_NAME ?>" onerror="this.src='assets/img/logo.png'">
  </a>
  <nav class="sidebar-nav">
    <button class="nav-item <?= !$esAdmin ? 'active' : '' ?>" onclick="showTab('tienda',this)" id="btn-tienda">
      <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4m1.6 8L5 5H3m4 8v6m0 0a1 1 0 1 0 2 0m-2 0h2m6-6v6m0 0a1 1 0 1 0 2 0m-2 0h2"/></svg>
      <span class="nav-label">Tienda</span>
    </button>
    <button class="nav-item" onclick="showTab('pedidos',this)" id="btn-pedidos">
      <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2"/></svg>
      <span class="nav-label">Mis Pedidos</span>
      <?php if (!empty($pedidos)): ?><span class="nav-badge"><?= count($pedidos) ?></span><?php endif; ?>
    </button>
    <button class="nav-item" onclick="showTab('billetera',this)" id="btn-billetera">
      <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h2M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
      <span class="nav-label">Billetera</span>
      <span class="nav-badge" style="background:var(--success)">$<?= number_format((float)($usuario['saldo'] ?? 0),0,'.','.') ?></span>
    </button>

    <?php if ($esAdmin): ?>
    <div class="nav-divider"></div>
    <div class="nav-section-label">Administración</div>
    <button type="button" class="nav-item admin-item active" onclick="showAdmin('admin/index.php','Dashboard admin',this)">
      <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2zm0 0V9a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v10m-6 0a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2m0 0V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-2a2 2 0 0 1-2-2z"/></svg>
      <span class="nav-label">Dashboard admin</span>
      <span class="admin-badge">Admin</span>
    </button>
    <button type="button" class="nav-item admin-item" onclick="showAdmin('admin/servicios.php','Servicios & Planes',this)">
      <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
      <span class="nav-label">Servicios & Planes</span>
    </button>
    <button type="button" class="nav-item admin-item" onclick="showAdmin('admin/combos.php','Combos',this)">
      <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/>
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 7V5m0 14v-2"/>
      </svg>
      <span class="nav-label">Combos</span>
    </button>
    <button type="button" class="nav-item admin-item" onclick="showAdmin('admin/stock.php','Stock de cuentas',this)">
      <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2zM12 12h.01"/></svg>
      <span class="nav-label">Stock de cuentas</span>
    </button>
    <button type="button" class="nav-item admin-item" onclick="showAdmin('admin/pedidos.php','Gestionar pedidos',this)">
      <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16 11V7a4 4 0 0 0-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
      <span class="nav-label">Gestionar pedidos</span>
    </button>
    <button type="button" class="nav-item admin-item" onclick="showAdmin('admin/usuarios.php','Usuarios',this)">
      <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path stroke-linecap="round" d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      <span class="nav-label">Usuarios</span>
    </button>
    <button type="button" class="nav-item admin-item" onclick="showAdmin('admin/recargas.php','Recargas de saldo',this)">
      <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      <span class="nav-label">Recargas de saldo</span>
    </button>
    <button type="button" class="nav-item admin-item" onclick="showAdmin('admin/pago.php','Datos de pago',this)">
      <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h2M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
      <span class="nav-label">Datos de pago</span>
    </button>
    <button type="button" class="nav-item admin-item" onclick="showAdmin('admin/pasarela.php','Slider / Pasarela',this)">
      <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 0 1 2.828 0L16 16m-2-2 1.586-1.586a2 2 0 0 1 2.828 0L20 14M8 10a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"/><rect x="3" y="5" width="18" height="14" rx="2" stroke-linecap="round"/></svg>
      <span class="nav-label">Slider / Pasarela</span>
    </button>
    <?php endif; ?>
  </nav>
  <div class="sidebar-footer">
    <div class="user-row">
      <div class="user-avatar"><?= strtoupper(substr($usuario['nombre'],0,1)) ?></div>
      <div>
        <div class="user-name"><?= htmlspecialchars($usuario['nombre']) ?></div>
        <div class="user-role"><?= $esAdmin ? 'Administrador' : 'Cliente' ?></div>
      </div>
    </div>
    <button type="button" class="btn-logout-side" onclick="abrirCambioPassword()" style="color:var(--text2)">
      <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 0 1 2 2m4 0a6 6 0 0 1-7.7 5.7l-2.3 2.3H9v2H7v2H4a1 1 0 0 1-1-1v-2.6a1 1 0 0 1 .3-.7l5.7-5.7A6 6 0 1 1 21 9z"/></svg>
      Cambiar contraseña
    </button>
    <a href="logout.php" class="btn-logout-side">
      <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3V7a3 3 0 0 1 3-3h4a3 3 0 0 1 3 3v1"/></svg>
      Cerrar sesión
    </a>
  </div>
</aside>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>



<div class="main">
  <header class="topbar">
    <div style="display:flex;align-items:center;gap:14px">
      <button class="menu-toggle" id="menuToggle" onclick="toggleSidebar()">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
      </button>
      <div class="topbar-left">
        <div class="topbar-title" id="topbar-title"><?= $esAdmin ? 'Dashboard admin' : 'Tienda' ?></div>
        <div class="topbar-sub" id="topbar-sub"><?= $esAdmin ? '' : 'Explora todos los servicios disponibles' ?></div>
      </div>
    </div>
    <div class="topbar-right">
      <?php if ($esAdmin): ?>
      <style>
        .bell-wrap{position:relative}
        .bell-btn{position:relative;background:var(--surface2);border:1px solid var(--border2);border-radius:40px;width:42px;height:42px;font-size:18px;cursor:pointer;color:var(--text);display:flex;align-items:center;justify-content:center;transition:all .2s}
        .bell-btn:hover{border-color:rgba(255,255,255,.3)}
        .bell-badge{position:absolute;top:-2px;right:-2px;background:var(--accent);color:#fff;font-size:10px;font-weight:800;min-width:18px;height:18px;border-radius:10px;display:flex;align-items:center;justify-content:center;padding:0 4px}
        .bell-dropdown{display:none;position:absolute;right:0;top:52px;width:320px;max-height:420px;overflow-y:auto;background:var(--surface);border:1px solid var(--border2);border-radius:14px;box-shadow:0 16px 40px rgba(0,0,0,.5);z-index:300}
        .bell-dropdown.open{display:block}
        .bell-head{padding:14px 16px;font-weight:700;font-size:14px;border-bottom:1px solid var(--border)}
        .bell-item{padding:12px 16px;border-bottom:1px solid var(--border);cursor:pointer;transition:background .15s}
        .bell-item:hover{background:var(--surface2)}
        .bell-item.unread{background:rgba(124,109,250,.06)}
        .bell-item .bi-msg{font-size:13px;color:var(--text);line-height:1.4}
        .bell-item .bi-time{font-size:11px;color:var(--text3);margin-top:3px}
        .bell-empty{padding:30px 16px;text-align:center;color:var(--text3);font-size:13px}
      </style>
      <div class="bell-wrap">
        <button class="bell-btn" id="bellBtn" onclick="toggleBell()" title="Notificaciones">🔔<span class="bell-badge" id="bellBadge" style="display:none">0</span></button>
        <div class="bell-dropdown" id="bellDropdown">
          <div class="bell-head">Notificaciones</div>
          <div id="bellList"><div class="bell-empty">Cargando…</div></div>
        </div>
      </div>
      <?php endif; ?>
      <div class="search-box">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path stroke-linecap="round" d="M21 21l-4.35-4.35"/></svg>
        <input type="text" id="searchInput" placeholder="Buscar plan..." oninput="filtrarPlanes(this.value)">
      </div>
    </div>
  </header>

  <div class="content">

    <!-- TIENDA -->
    <div class="panel <?= !$esAdmin ? 'active' : '' ?>" id="panel-tienda">

      <!-- SLIDER / PASARELA -->
      <div class="hb-slider" id="hbSlider">
        <?php if (!empty($hbSlides)): ?>
          <?php foreach ($hbSlides as $i => $sl): ?>
          <div class="hb-slide-pasarela <?= $i === 0 ? 'active' : '' ?>">
            <a href="javascript:void(0)" draggable="false">
              <img src="assets/img/<?= htmlspecialchars($sl['imagen']) ?>"
                alt="<?= htmlspecialchars($sl['titulo'] ?: 'Promo ' . ($i + 1)) ?>"
                draggable="false" loading="<?= $i === 0 ? 'eager' : 'lazy' ?>"
                onerror="this.parentElement.parentElement.style.display='none'">
            </a>
            <div class="hb-vignette"></div>
          </div>
          <?php endforeach; ?>
          <?php if (count($hbSlides) > 1): ?>
          <button class="hb-arrow hb-prev" id="hbPrev" type="button" aria-label="Anterior">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="15 18 9 12 15 6"/></svg>
          </button>
          <button class="hb-arrow hb-next" id="hbNext" type="button" aria-label="Siguiente">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
          </button>
          <div class="hb-dots" id="hbDots">
            <?php for ($i = 0; $i < count($hbSlides); $i++): ?>
            <button class="hb-dot <?= $i === 0 ? 'active' : '' ?>" data-i="<?= $i ?>" type="button" aria-label="Slide <?= $i + 1 ?>"></button>
            <?php endfor; ?>
          </div>
          <?php endif; ?>
          <div class="hb-progress" id="hbProgress"></div>
          <div class="hb-brand"><?= SITE_NAME ?></div>
        <?php else: ?>
          <div class="hb-slider-empty">
            📷 Sin promociones —
            <?php if ($esAdmin): ?>
              <a href="javascript:void(0)" onclick="showAdmin('admin/pasarela.php','Slider / Pasarela',null)">Añadir desde el admin</a>
            <?php else: ?>
              <span style="margin-left:4px">próximamente</span>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Burbujas de servicios -->
      <div class="srv-bubbles" id="srvBubbles">
        <a href="javascript:void(0)" class="srv-bubble srv-bubble-active" id="bubble-all" onclick="filtrarServicio(null, this)">
          <div class="srv-bubble-circle" style="border-color:#7c6dfa88;background:#7c6dfa22">
            <span style="font-size:24px">🏠</span>
          </div>
          <span class="srv-bubble-name">Todos</span>
        </a>
        <?php if (!empty($combos)):
          // UNA sola burbuja que agrupa TODOS los combos
          $primerCombo = $combos[0];
          $comboColor = $primerCombo['color'] ?? '#7c6dfa';
        ?>
        <a href="javascript:void(0)" class="srv-bubble" data-srv="combos-all" onclick="filtrarCombos(this)">
          <span class="srv-combo-badge">COMBO</span>
          <div class="srv-bubble-circle" style="border-color:<?= htmlspecialchars($comboColor) ?>88;background:<?= htmlspecialchars($comboColor) ?>22">
            <span style="font-size:26px">🎁</span>
          </div>
          <span class="srv-bubble-name">Combos</span>
        </a>
        <?php endif; ?>
        <?php foreach ($serviciosGrupo as $servNombre => $planesGrupo):
          $bColor = $planesGrupo[0]['servicio_color'];
          $bImg   = !empty($planesGrupo[0]['servicio_imagen_circulo']) ? $planesGrupo[0]['servicio_imagen_circulo'] : $planesGrupo[0]['servicio_imagen'];
          $bSlug  = 'srv-' . preg_replace('/[^a-z0-9]/','_', strtolower($servNombre));
        ?>
        <a href="javascript:void(0)" class="srv-bubble" data-srv="<?= $bSlug ?>" onclick="filtrarServicio('<?= $bSlug ?>', this)">
          <div class="srv-bubble-circle" style="border-color:<?= $bColor ?>88;background:<?= $bColor ?>22">
            <img src="assets/img/<?= htmlspecialchars($bImg) ?>" alt="<?= htmlspecialchars($servNombre) ?>" onerror="this.style.opacity='0'">
          </div>
          <span class="srv-bubble-name"><?= htmlspecialchars($servNombre) ?></span>
        </a>
        <?php endforeach; ?>
      </div>


      <!-- VISTA GENERAL: todas las plataformas juntas -->
      <div class="planes-grid" id="overviewGrid">
        <?php foreach ($serviciosGrupo as $servNombre => $planesGrupo):
          $oColor = $planesGrupo[0]['servicio_color'];
          $oImg   = $planesGrupo[0]['servicio_imagen'];
          $oSlug  = 'srv-' . preg_replace('/[^a-z0-9]/','_', strtolower($servNombre));
          $totalPlanes = count($planesGrupo);
          // Stock del servicio: hay stock si AL MENOS un plan tiene stock
          $servicioTieneStock = false;
          $precioDesde = null;
          foreach ($planesGrupo as $pl) {
            if ((int)$pl['stock'] > 0) $servicioTieneStock = true;
            $tr = $esRevendedor && $pl['precio_revendedor'] !== null && (float)$pl['precio_revendedor'] > 0;
            $pf = $tr ? (float)$pl['precio_revendedor'] : (float)$pl['precio'];
            if ($precioDesde === null || $pf < $precioDesde) $precioDesde = $pf;
          }
        ?>
        <div class="plan-card<?= !$servicioTieneStock ? ' agotado' : '' ?>" style="--c:<?= $oColor ?>" onclick="filtrarServicio('<?= $oSlug ?>')">
          <div class="pc-banner">
            <img src="assets/img/<?= htmlspecialchars($oImg) ?>" alt="<?= htmlspecialchars($servNombre) ?>" onerror="this.style.display='none'">
            <?php if (!$servicioTieneStock): ?>
            <span class="pc-badge pc-badge-agotado">🔴 Agotado</span>
            <?php else: ?>
            <span class="pc-badge"><?= $totalPlanes ?> plan<?= $totalPlanes>1?'es':'' ?></span>
            <?php endif; ?>
          </div>
          <div class="pc-info">
            <div class="pc-nombre"><?= htmlspecialchars($servNombre) ?></div>
          </div>
          <div class="pc-footer">
            <div class="pc-price-block">
              <div class="pc-dias">Desde</div>
              <div class="pc-price">$<?= number_format((float)$precioDesde,0,'.','.') ?></div>
              <div class="pc-dias">COP</div>
            </div>
            <button class="btn-buy<?= !$servicioTieneStock ? ' disabled' : '' ?>" <?= !$servicioTieneStock ? 'disabled' : '' ?>><?= !$servicioTieneStock ? 'Agotado' : 'Ver →' ?></button>
          </div>
        </div>
        <?php endforeach; ?>
        <?php foreach ($combos as $combo):
          $cSlug = 'combo-' . $combo['id'];
          $esRevC = $esRevendedor && $combo['precio_revendedor'] > 0;
          $precioC = $esRevC ? (float)$combo['precio_revendedor'] : (float)$combo['precio'];
          $imgs = array_slice(array_filter(explode('|||', $combo['servicios_imagenes'] ?? '')), 0, 4);
          $totalIncluidos = substr_count($combo['servicios_imagenes'] ?? '', '|||') + (($combo['servicios_imagenes'] ?? '') !== '' ? 1 : 0);
          $comboSinStock = !empty($combo['agotado']);
        ?>
        <div class="plan-card<?= $comboSinStock ? ' agotado' : '' ?>" style="--c:<?= htmlspecialchars($combo['color']) ?>"
             <?php if (!$comboSinStock): ?>
             onclick="abrirCompraCombo(<?= $combo['id'] ?>,'<?= addslashes($combo['nombre']) ?>',<?= $precioC ?>,<?= $combo['duracion_dias'] ?>,'<?= htmlspecialchars($combo['imagen']??'') ?>',<?= $esRevC?1:0 ?>)"
             <?php endif; ?>>
          <div class="pc-banner" style="background:linear-gradient(135deg,<?= htmlspecialchars($combo['color']) ?>33,#0b0b0b)">
            <div class="combo-chip-row">
              <?php foreach ($imgs as $ci => $im): ?>
                <?php if ($ci > 0): ?><span class="combo-plus">+</span><?php endif; ?>
                <div class="combo-chip"><img src="assets/img/<?= htmlspecialchars($im) ?>" alt="" onerror="this.style.display='none'"></div>
              <?php endforeach; ?>
            </div>
            <?php if ($comboSinStock): ?>
            <span class="pc-badge pc-badge-agotado">🔴 Agotado</span>
            <?php else: ?>
            <span class="pc-badge" style="background:linear-gradient(135deg,var(--accent),var(--accent2))">🎁 COMBO</span>
            <?php endif; ?>
          </div>
          <div class="pc-info">
            <div class="pc-nombre">Combo</div>
            <?php if ($combo['descripcion']): ?><div class="pc-desc"><?= htmlspecialchars($combo['descripcion']) ?></div><?php endif; ?>
            <div class="combo-tags">
              <span class="combo-tag">✓ <b><?= $totalIncluidos ?></b> suscripciones</span>
              <?php if ($comboSinStock): ?>
              <span class="combo-tag" style="color:var(--danger);border-color:rgba(239,68,68,.3)">⚠️ Sin stock</span>
              <?php else: ?>
              <span class="combo-tag">🏷️ Mejor precio</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="pc-footer">
            <div class="pc-price-block">
              <?php if ($esRevC): ?>
                <div class="pc-price-old">$<?= number_format($combo['precio'],0,'.','.') ?></div>
                <div class="pc-price">$<?= number_format($precioC,0,'.','.') ?></div>
                <div class="pc-margen">Ganas $<?= number_format($combo['precio']-$precioC,0,'.','.') ?></div>
              <?php else: ?>
                <div class="pc-dias">Pack completo</div>
                <div class="pc-price">$<?= number_format($precioC,0,'.','.') ?></div>
                <div class="pc-dias"><?= $combo['duracion_dias'] ?> días · COP</div>
              <?php endif; ?>
            </div>
            <button class="btn-buy<?= $comboSinStock ? ' disabled' : '' ?>" <?= $comboSinStock ? 'disabled' : '' ?>><?= $comboSinStock ? 'Agotado' : 'Ver →' ?></button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>


      <?php
      foreach ($serviciosGrupo as $servNombre => $planesGrupo):
        $color  = $planesGrupo[0]['servicio_color'];
        $imgSrv = $planesGrupo[0]['servicio_imagen'];
        $slug   = 'srv-' . preg_replace('/[^a-z0-9]/','_', strtolower($servNombre));
      ?>
      <div class="servicio-block" id="<?= $slug ?>" style="display:none">
        <div class="srv-header">
          <span class="srv-dot" style="background:<?= $color ?>"></span>
          <img src="assets/img/<?= htmlspecialchars($imgSrv) ?>" alt="" style="height:20px;object-fit:contain;opacity:.85" onerror="this.style.display='none'">
          <span class="srv-name"><?= htmlspecialchars($servNombre) ?></span>
          <span class="srv-count"><?= count($planesGrupo) ?> plan<?= count($planesGrupo)>1?'es':'' ?></span>
        </div>
        <div class="planes-grid">
          <?php foreach ($planesGrupo as $plan):
            $tieneRev    = $esRevendedor && $plan['precio_revendedor'] !== null && (float)$plan['precio_revendedor'] > 0;
            $precioFinal = $tieneRev ? (float)$plan['precio_revendedor'] : (float)$plan['precio'];
            $margen      = $tieneRev ? ((float)$plan['precio'] - (float)$plan['precio_revendedor']) : 0;
            $sinStock    = ((int)$plan['stock'] === 0);
          ?>
          <div class="plan-card<?= $tieneRev ? ' plan-card-rev' : '' ?><?= $sinStock ? ' agotado' : '' ?>" style="--c:<?= $plan['servicio_color'] ?>"
               <?php if (!$sinStock): ?>
               onclick="abrirCompra(<?= $plan['id'] ?>,'<?= addslashes($plan['nombre']) ?>','<?= addslashes($plan['servicio_nombre']) ?>',<?= $precioFinal ?>,<?= $plan['duracion_dias'] ?>,<?= $plan['stock'] ?>,'<?= htmlspecialchars($plan['imagen'] ?: $plan['servicio_imagen']) ?>',<?= $tieneRev ? 1 : 0 ?>)"
               <?php endif; ?>>
            <div class="pc-banner">
              <img src="assets/img/<?= htmlspecialchars($plan['imagen'] ?: $plan['servicio_imagen']) ?>" alt="<?= htmlspecialchars($plan['servicio_nombre']) ?>" onerror="this.style.display='none'">
              <?php if ($sinStock): ?>
              <span class="pc-badge pc-badge-agotado">🔴 Agotado</span>
              <?php else: ?>
              <span class="pc-badge"><?= $tieneRev ? '⭐ Revendedor' : '✓ Disponible' ?></span>
              <?php endif; ?>
            </div>
            <div class="pc-info">
              <div class="pc-nombre"><?= htmlspecialchars($plan['nombre']) ?></div>
              <div class="pc-desc"><?= htmlspecialchars($plan['descripcion'] ?? '') ?></div>
            </div>
            <div class="pc-footer">
              <div class="pc-price-block">
                <?php if ($tieneRev): ?>
                  <div class="pc-price-old">$<?= number_format($plan['precio'],0,'.','.') ?></div>
                  <div class="pc-price">$<?= number_format($precioFinal,0,'.','.') ?></div>
                  <div class="pc-margen">Ganas $<?= number_format($margen,0,'.','.') ?> · <?= $plan['duracion_dias'] ?> días</div>
                <?php else: ?>
                  <div class="pc-price">$<?= number_format($precioFinal,0,'.','.') ?></div>
                  <div class="pc-dias"><?= $plan['duracion_dias'] ?> días · COP</div>
                <?php endif; ?>
              </div>
              <?php if ($sinStock): ?>
              <button class="btn-buy disabled" disabled>Agotado</button>
              <?php else: ?>
              <button class="btn-buy">Comprar</button>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>


      <?php if (!empty($combos)): ?>
      <!-- BLOQUE ÚNICO: todos los combos juntos en cuadrícula -->
      <div class="servicio-block combo-block" id="combos-all-block" style="display:none">
        <div class="srv-header">
          <span style="font-size:20px">🎁</span>
          <span class="srv-name">Combos</span>
          <span class="srv-count"><?= count($combos) ?> combo<?= count($combos)>1?'s':'' ?></span>
        </div>
        <div class="planes-grid">
          <?php foreach ($combos as $combo):
            $esRevC  = $esRevendedor && $combo['precio_revendedor'] > 0;
            $precioC = $esRevC ? (float)$combo['precio_revendedor'] : (float)$combo['precio'];
            $margenC = $esRevC ? ((float)$combo['precio'] - $precioC) : 0;
            $comboSinStock = !empty($combo['agotado']);
            $stmtCP = $pdo->prepare("
                SELECT p.imagen, s.imagen as servicio_imagen
                FROM combo_planes cp
                JOIN planes p ON cp.plan_id = p.id
                JOIN servicios s ON p.servicio_id = s.id
                WHERE cp.combo_id = ? ORDER BY s.nombre
            ");
            $stmtCP->execute([$combo['id']]);
            $detallePlanes = $stmtCP->fetchAll();
          ?>
          <div class="plan-card<?= $esRevC?' plan-card-rev':'' ?><?= $comboSinStock ? ' agotado' : '' ?>" style="--c:<?= htmlspecialchars($combo['color']) ?>"
               <?php if (!$comboSinStock): ?>
               onclick="abrirCompraCombo(<?= $combo['id'] ?>,'<?= addslashes($combo['nombre']) ?>',<?= $precioC ?>,<?= $combo['duracion_dias'] ?>,'<?= htmlspecialchars($combo['imagen']??'') ?>',<?= $esRevC?1:0 ?>)"
               <?php endif; ?>>
            <div class="pc-banner" style="background:linear-gradient(135deg,<?= htmlspecialchars($combo['color']) ?>33,#0b0b0b)">
              <div class="combo-chip-row">
                <?php foreach (array_slice($detallePlanes,0,4) as $ci => $dp): ?>
                  <?php if ($ci > 0): ?><span class="combo-plus">+</span><?php endif; ?>
                  <div class="combo-chip"><img src="assets/img/<?= htmlspecialchars($dp['imagen']?:$dp['servicio_imagen']) ?>" alt="" onerror="this.style.display='none'"></div>
                <?php endforeach; ?>
              </div>
              <?php if ($comboSinStock): ?>
              <span class="pc-badge pc-badge-agotado">🔴 Agotado</span>
              <?php else: ?>
              <span class="pc-badge" style="background:linear-gradient(135deg,var(--accent),var(--accent2))">🎁 COMBO</span>
              <?php endif; ?>
            </div>
            <div class="pc-info">
              <div class="pc-nombre"><?= htmlspecialchars($combo['nombre']) ?></div>
              <div class="pc-desc"><?= count($detallePlanes) ?> servicios · <?= $combo['duracion_dias'] ?> días</div>
            </div>
            <div class="pc-footer">
              <div class="pc-price-block">
                <?php if ($esRevC): ?>
                  <div class="pc-price-old">$<?= number_format($combo['precio'],0,'.','.') ?></div>
                  <div class="pc-price">$<?= number_format($precioC,0,'.','.') ?></div>
                  <div class="pc-margen">Ganas $<?= number_format($margenC,0,'.','.') ?></div>
                <?php else: ?>
                  <div class="pc-price">$<?= number_format($precioC,0,'.','.') ?></div>
                  <div class="pc-dias"><?= $combo['duracion_dias'] ?> días · COP</div>
                <?php endif; ?>
              </div>
              <?php if ($comboSinStock): ?>
              <button class="btn-buy disabled" disabled>Agotado</button>
              <?php else: ?>
              <button class="btn-buy">Comprar</button>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>



    <!-- PEDIDOS -->
    <div class="panel" id="panel-pedidos">
      <?php if (empty($pedidos)): ?>
      <div class="empty">
        <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2"/></svg>
        <p>No tienes pedidos aún. ¡Ve a la tienda!</p>
      </div>
      <?php endif; ?>
      <?php
      $estadoColor = ['pendiente'=>'#f59e0b','entregado'=>'#1db954','vencido'=>'#ef4444','cancelado'=>'#888','completado'=>'#3b82f6'];
      foreach ($pedidos as $p):
        $col = $estadoColor[$p['estado']] ?? '#888';
        $diasRest = !empty($p['fecha_vencimiento']) ? (int)ceil((strtotime($p['fecha_vencimiento']) - time()) / 86400) : null;
      ?>
      <div class="pedido-card">
        <div class="pedido-img" style="background:<?= $p['color'] ?>22">
          <img src="assets/img/<?= htmlspecialchars($p['servicio_imagen']) ?>" alt="" onerror="this.innerHTML='🎬'">
        </div>
        <div class="pedido-body">
          <div class="pedido-nombre"><?= htmlspecialchars($p['servicio_nombre']) ?> — <?= htmlspecialchars($p['plan_nombre']) ?></div>
          <div class="pedido-meta"><?= date('d/m/Y H:i',strtotime($p['created_at'])) ?> · $<?= number_format((float)$p['monto'],0,'.','.') ?> COP · <?= (int)$p['duracion_dias'] ?> días</div>
          <?php if ($p['estado'] === 'pendiente'): ?>
            <div style="margin-top:10px;font-size:13px;color:#f59e0b;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:10px;padding:9px 12px">⏳ Estamos validando tu pago. Te entregaremos los datos en breve.</div>
          <?php elseif ($p['estado'] === 'entregado'): ?>
            <div style="margin-top:10px;font-size:12px;color:<?= $diasRest <= 3 ? '#f59e0b' : 'var(--text2)' ?>">📆 Vigente hasta el <?= date('d/m/Y', strtotime($p['fecha_vencimiento'])) ?> · <b><?= max(0,$diasRest) ?> día<?= $diasRest==1?'':'s' ?> restante<?= $diasRest==1?'':'s' ?></b></div>
            <div class="pedido-creds">
              <div class="cred-row">
                <span class="cred-label">Usuario</span>
                <span class="cred-value"><?= htmlspecialchars($p['cred_usuario']) ?></span>
              </div>
              <div class="cred-row">
                <span class="cred-label">Contraseña</span>
                <span class="cred-value"><?= htmlspecialchars($p['cred_password']) ?></span>
              </div>
              <?php if (!empty($p['cred_perfil'])): ?>
              <div class="cred-row">
                <span class="cred-label">Perfil</span>
                <span class="cred-value"><?= htmlspecialchars($p['cred_perfil']) ?></span>
              </div>
              <?php endif; ?>
              <?php if (!empty($p['cred_pin'])): ?>
              <div class="cred-row">
                <span class="cred-label">PIN</span>
                <span class="cred-value"><?= htmlspecialchars($p['cred_pin']) ?></span>
              </div>
              <?php endif; ?>
            </div>
          <?php elseif ($p['estado'] === 'vencido'): ?>
            <div style="margin-top:10px;font-size:13px;color:#ef4444;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:10px;padding:9px 12px">🔒 Servicio vencido<?= $p['fecha_vencimiento'] ? ' el '.date('d/m/Y', strtotime($p['fecha_vencimiento'])) : '' ?>. Cómpralo de nuevo para renovarlo.</div>
          <?php elseif ($p['estado'] === 'cancelado'): ?>
            <div style="margin-top:10px;font-size:13px;color:#ef4444;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:10px;padding:10px 12px">✕ <b>Pedido rechazado.</b><?php if (!empty($p['nota_admin'])): ?><br><span style="color:var(--text2)">Motivo: <?= htmlspecialchars($p['nota_admin']) ?></span><?php endif; ?></div>
          <?php endif; ?>
        </div>
        <span class="status-pill" style="background:<?= $col ?>22;color:<?= $col ?>;text-transform:capitalize"><?= $p['estado'] ?></span>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- BILLETERA -->
    <div class="panel" id="panel-billetera">
      <div style="max-width:600px">
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--r-xl);padding:32px;text-align:center;margin-bottom:24px">
          <div style="font-size:12px;color:var(--text3);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">Tu saldo disponible</div>
          <div style="font-size:42px;font-weight:900;background:linear-gradient(135deg,#c9b6ff,var(--accent2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;letter-spacing:-1px" id="saldoDisplay">$<?= number_format((float)($usuario['saldo'] ?? 0),0,'.','.') ?></div>
          <div style="font-size:13px;color:var(--text3);margin-top:6px">Pesos colombianos (COP)</div>
        </div>
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--r-xl);padding:28px">
          <div style="font-size:16px;font-weight:800;margin-bottom:6px">💰 Recargar saldo</div>
          <div style="font-size:13px;color:var(--text3);margin-bottom:20px">Transfiere el monto y adjunta tu comprobante. Tu saldo se actualizará cuando el admin lo apruebe.</div>

          <div style="display:flex;gap:20px;margin-bottom:20px;align-items:flex-start;flex-wrap:wrap">
            <div style="flex:1;min-width:160px;font-size:13px;color:var(--text2);line-height:1.7">
              <?php if ($pagoTitular): ?><div><b style="color:var(--text)"><?= htmlspecialchars($pagoTitular) ?></b></div><?php endif; ?>
              <div><?= htmlspecialchars(trim($pagoBanco . ' · ' . $pagoLlave, ' ·')) ?></div>
              <?php if ($pagoInstr): ?><div style="margin-top:8px;font-size:12px;color:var(--text3);white-space:pre-line"><?= htmlspecialchars($pagoInstr) ?></div><?php endif; ?>
            </div>
            <div class="qr-img-wrap" style="flex-shrink:0;text-align:center">
              <?php if ($pagoQr): ?>
                <img src="assets/img/<?= htmlspecialchars($pagoQr) ?>" alt="QR Pago"
                     style="width:220px;height:220px;object-fit:contain;border-radius:14px;background:#fff;padding:8px;display:block"
                     onerror="this.style.display='none'">
                <div style="font-size:11px;color:var(--text3);margin-top:6px;font-weight:600">Escanea para pagar</div>
              <?php else: ?>
                <div style="font-size:11px;color:var(--text3);padding:20px;border:1px dashed var(--border2);border-radius:10px;width:160px">Sin QR configurado</div>
              <?php endif; ?>
            </div>
          </div>

          <div style="font-size:11px;color:var(--text3);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px;font-weight:600">Monto a recargar</div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
            <button type="button" class="btn-monto" onclick="setMonto(10000,this)">$10.000</button>
            <button type="button" class="btn-monto" onclick="setMonto(20000,this)">$20.000</button>
            <button type="button" class="btn-monto" onclick="setMonto(50000,this)">$50.000</button>
            <button type="button" class="btn-monto" onclick="setMonto(100000,this)">$100.000</button>
          </div>
          <input type="text" inputmode="numeric" id="recargaMonto" placeholder="O escribe el monto (mín $5.000)" oninput="this.value=this.value.replace(/[^0-9.]/g,'')" style="width:100%;padding:12px 14px;background:var(--surface2);border:1px solid var(--border2);border-radius:var(--r-md);color:var(--text);font-family:'Inter',sans-serif;font-size:14px;outline:none;margin-bottom:14px">
          <label style="display:flex;align-items:center;justify-content:center;gap:8px;padding:14px;border:1px dashed var(--border2);border-radius:12px;cursor:pointer;font-size:13px;font-weight:600;color:var(--accent2);transition:all .2s;margin-bottom:16px" id="recargaFileLabel">
            <span id="recargaFileText">📎 Adjuntar comprobante de transferencia</span>
            <input type="file" id="recargaFile" accept="image/*,application/pdf" onchange="onRecargaFile(this)" hidden>
          </label>
          <button type="button" onclick="enviarRecarga()" style="width:100%;padding:14px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;border:none;border-radius:var(--r-md);font-family:'Inter',sans-serif;font-size:15px;font-weight:800;cursor:pointer;transition:all .2s" id="btnRecarga">Solicitar recarga →</button>
          <div id="recargaAlert" class="f-alert"></div>
        </div>
      </div>
    </div>

    <!-- ADMIN (embebido) -->
    <div class="panel <?= $esAdmin ? 'active' : '' ?>" id="panel-admin">
      <div class="admin-embed">
        <iframe id="adminFrame" src="<?= $esAdmin ? 'admin/index.php' : 'about:blank' ?>" title="Panel de administración"></iframe>
      </div>
    </div>

  </div>
</div>
</div>



<!-- MODAL COMPRA -->
<div class="overlay" id="overlayCompra">
  <div class="modal-box" style="max-width:440px">
    <button class="modal-close" onclick="cerrarModal()">✕</button>
    <div class="modal-head">
      <div class="modal-img-wrap"><img id="mImg" alt="" onerror="this.style.display='none'"></div>
      <div>
        <div class="modal-srv" id="mSrv"></div>
        <div class="modal-plan" id="mPlan"></div>
      </div>
    </div>
    <div class="modal-price-hero">
      <div id="mRevTag" style="display:none;font-size:12px;font-weight:800;color:#f59e0b;margin-bottom:6px;letter-spacing:.5px">⭐ PRECIO REVENDEDOR</div>
      <div class="modal-price-big" id="mPrice"></div>
      <div class="modal-price-cur">Valor del plan · COP · <span id="mDias"></span></div>
    </div>
    <div style="background:var(--surface2);border-radius:var(--r-md);padding:14px;margin:0 0 16px;text-align:center">
      <div style="font-size:11px;color:var(--text3);margin-bottom:4px">Tu saldo disponible</div>
      <div style="font-size:24px;font-weight:800;color:var(--success)" id="mSaldo">$<?= number_format((float)($usuario['saldo'] ?? 0),0,'.','.') ?></div>
    </div>
    <button class="btn-confirm" id="btnConfirmar" onclick="confirmarCompra()">Comprar con saldo</button>
    <div class="modal-alert err" id="mAlert"></div>
  </div>
</div>

<!-- MODAL ÉXITO RECARGA -->
<div class="recarga-overlay" id="recargaSuccessModal">
  <div class="recarga-box">
    <div class="recarga-icon">🎉</div>
    <div class="recarga-title">¡Solicitud enviada!</div>
    <div class="recarga-sub">Tu comprobante fue recibido correctamente.<br>El admin revisará tu pago y activará el saldo.</div>
    <div class="recarga-steps">
      <div class="recarga-step">
        <div class="recarga-step-num">1</div>
        <div class="recarga-step-txt"><b>Comprobante recibido ✓</b>Tu transferencia fue enviada para revisión.</div>
      </div>
      <div class="recarga-step">
        <div class="recarga-step-num">2</div>
        <div class="recarga-step-txt"><b>Revisión del admin</b>Verificamos que el pago llegó correctamente.</div>
      </div>
      <div class="recarga-step">
        <div class="recarga-step-num">3</div>
        <div class="recarga-step-txt"><b>Saldo activado 💰</b>Tu billetera se actualizará automáticamente.</div>
      </div>
    </div>
    <div class="recarga-bar"></div>
    <button class="recarga-btn-ok" onclick="cerrarRecargaModal()">Entendido →</button>
  </div>
</div>

<div id="flashMsg" class="flash" style="display:none"></div>

<style>
.center-flash{display:none;position:fixed;inset:0;z-index:4000;background:rgba(0,0,0,.72);align-items:center;justify-content:center;backdrop-filter:blur(5px);padding:20px}
.center-flash.open{display:flex}
.cf-box{background:var(--surface);border:1px solid var(--border2);border-radius:22px;padding:34px 30px;max-width:440px;width:100%;text-align:center;box-shadow:0 24px 70px rgba(0,0,0,.65);animation:fadeUp .3s ease}
.cf-icon{font-size:52px;margin-bottom:14px;line-height:1}
.cf-msg{font-size:17px;font-weight:700;line-height:1.5;margin-bottom:10px}
.cf-sub{font-size:13px;color:var(--text3)}
.cf-bar{height:4px;border-radius:4px;background:var(--success);margin-top:18px;animation:cfShrink 5s linear forwards}
@keyframes cfShrink{from{width:100%}to{width:0%}}
</style>
<div id="centerFlash" class="center-flash" onclick="location.reload()">
  <div class="cf-box">
    <div class="cf-icon">✅</div>
    <div class="cf-msg" id="cfMsg"></div>
    <div class="cf-sub">Revisa "Mis Pedidos". Esta ventana se cerrará sola…</div>
    <div class="cf-bar"></div>
  </div>
</div>



<script>
let planActual = null;
const CSRF = '<?= csrfToken() ?>';
const titles = {
  tienda:['Tienda','Explora todos los servicios disponibles'],
  pedidos:['Mis Pedidos','Historial de compras y credenciales'],
  billetera:['Billetera','Recarga saldo y gestiona tu dinero'],
};
function showTab(tab, btn){
  document.querySelectorAll('.panel').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(b=>b.classList.remove('active'));
  document.getElementById('panel-'+tab).classList.add('active');
  if(btn)btn.classList.add('active');
  const [t,s]=titles[tab]||['',''];
  document.getElementById('topbar-title').textContent=t;
  document.getElementById('topbar-sub').textContent=s;
  closeSidebar();
}
function showAdmin(page,label,btn){
  document.querySelectorAll('.panel').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(b=>b.classList.remove('active'));
  document.getElementById('adminFrame').src=page;
  document.getElementById('panel-admin').classList.add('active');
  if(btn)btn.classList.add('active');
  document.getElementById('topbar-title').textContent=label;
  document.getElementById('topbar-sub').textContent='';
  closeSidebar();
}
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');document.getElementById('sidebarOverlay').classList.toggle('open')}
function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('sidebarOverlay').classList.remove('open')}

// Filtrar servicios desde las burbujas
function filtrarServicio(slug, elBurbuja){
  document.querySelectorAll('.srv-bubble').forEach(b=>b.classList.remove('srv-bubble-active'));
  if(elBurbuja && elBurbuja.classList && elBurbuja.classList.contains('srv-bubble')){
    elBurbuja.classList.add('srv-bubble-active');
  }else{
    const tgt = slug ? document.querySelector('.srv-bubble[data-srv="'+slug+'"]') : document.getElementById('bubble-all');
    if(tgt)tgt.classList.add('srv-bubble-active');
  }
  const search=document.getElementById('searchInput');
  if(search)search.value='';
  const bubbles=document.getElementById('srvBubbles');
  if(bubbles)bubbles.style.display='flex';
  document.querySelectorAll('.servicio-block .plan-card').forEach(c=>c.style.display='');
  const overview=document.getElementById('overviewGrid');
  const bloques=document.querySelectorAll('.servicio-block');
  if(!slug){
    if(overview)overview.style.display='';
    bloques.forEach(b=>b.style.display='none');
  }else{
    if(overview)overview.style.display='none';
    bloques.forEach(b=>{ b.style.display=(b.id===slug)?'':'none'; });
    const content=document.querySelector('.content');
    if(content)content.scrollTo({top:0,behavior:'smooth'});
  }
}

// Mostrar TODOS los combos juntos (una sola burbuja los agrupa)
function filtrarCombos(elBurbuja){
  document.querySelectorAll('.srv-bubble').forEach(b=>b.classList.remove('srv-bubble-active'));
  if(elBurbuja)elBurbuja.classList.add('srv-bubble-active');
  const search=document.getElementById('searchInput');
  if(search)search.value='';
  const bubbles=document.getElementById('srvBubbles');
  if(bubbles)bubbles.style.display='flex';
  document.querySelectorAll('.servicio-block .plan-card').forEach(c=>c.style.display='');
  const overview=document.getElementById('overviewGrid');
  if(overview)overview.style.display='none';
  // Ocultar todos los bloques y mostrar SOLO los de combos
  document.querySelectorAll('.servicio-block').forEach(b=>{
    b.style.display = b.classList.contains('combo-block') ? '' : 'none';
  });
  const content=document.querySelector('.content');
  if(content)content.scrollTo({top:0,behavior:'smooth'});
}

function irAServicio(id){
  showTab('tienda',document.getElementById('btn-tienda'));
  setTimeout(()=>{
    const el=document.getElementById(id);
    if(el){
      el.scrollIntoView({behavior:'smooth',block:'start'});
      el.style.transition='background .3s';
      el.style.background='rgba(124,109,250,0.05)';
      el.style.borderRadius='12px';
      setTimeout(()=>el.style.background='',1500);
    }
  },120);
}

// Campanita
const bellBtn=document.getElementById('bellBtn');
if(bellBtn){
  function escapeHtml(s){return (s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}
  function pollBell(){
    fetch('ajax/notificaciones.php?accion=contar').then(r=>r.json()).then(d=>{
      const b=document.getElementById('bellBadge');
      if(d.ok&&d.count>0){b.textContent=d.count;b.style.display='flex'}else{b.style.display='none'}
    }).catch(()=>{});
  }
  function loadBellList(){
    const list=document.getElementById('bellList');
    fetch('ajax/notificaciones.php?accion=listar').then(r=>r.json()).then(d=>{
      if(!d.ok||!d.items.length){list.innerHTML='<div class="bell-empty">Sin notificaciones</div>';return}
      list.innerHTML=d.items.map(n=>`<div class="bell-item ${n.leida=='0'?'unread':''}" onclick="irNotif('${escapeHtml(n.tipo)}')"><div class="bi-msg">${n.tipo==='recarga'?'💳':'🛒'} ${escapeHtml(n.mensaje||'')}</div><div class="bi-time">${escapeHtml(n.cliente||'')} · ${escapeHtml(n.created_at||'')}</div></div>`).join('');
      const fd=new FormData();fd.append('accion','marcar');fd.append('csrf_token',CSRF);
      fetch('ajax/notificaciones.php',{method:'POST',body:fd}).then(()=>pollBell());
    });
  }
  window.toggleBell=function(){const dd=document.getElementById('bellDropdown');if(dd.classList.toggle('open'))loadBellList()};
  window.irNotif=function(tipo){
    document.getElementById('bellDropdown').classList.remove('open');
    if(tipo==='recarga')showAdmin('admin/recargas.php','Recargas de saldo',null);
    else showAdmin('admin/pedidos.php','Gestionar pedidos',null);
  };
  document.addEventListener('click',e=>{const w=document.querySelector('.bell-wrap');if(w&&!w.contains(e.target))document.getElementById('bellDropdown').classList.remove('open')});
  pollBell();setInterval(pollBell,20000);
}
// ── Auto-scroll suave de las burbujas de servicios (se pausa al pasar el mouse o tocar) ──
(function(){
  const bar = document.getElementById('srvBubbles');
  if(!bar) return;
  let dir = 1, paused = false, pos = bar.scrollLeft;
  function loop(){
    const max = bar.scrollWidth - bar.clientWidth;
    if(max > 4 && !paused){
      pos += 0.4 * dir;                 // velocidad lenta (~24px/seg)
      if(pos >= max){ pos = max; dir = -1; }
      else if(pos <= 0){ pos = 0; dir = 1; }
      bar.scrollLeft = pos;
    } else {
      pos = bar.scrollLeft;             // sincroniza si el usuario desliza manualmente
    }
    requestAnimationFrame(loop);
  }
  // Pausar al interactuar; reanudar poco después
  ['mouseenter','touchstart','pointerdown'].forEach(ev => bar.addEventListener(ev, () => { paused = true; }, {passive:true}));
  ['mouseleave','touchend','pointerup','pointercancel'].forEach(ev => bar.addEventListener(ev, () => { setTimeout(() => { paused = false; }, 1500); }, {passive:true}));
  requestAnimationFrame(loop);
})();
function fmt(n){return '$'+Math.round(n).toLocaleString('es-CO')}
function filtrarPlanes(q){
  q=q.toLowerCase().trim();
  const overview=document.getElementById('overviewGrid');
  const bubbles=document.getElementById('srvBubbles');
  if(q){
    document.querySelectorAll('.srv-bubble').forEach(b=>b.classList.remove('srv-bubble-active'));
    const all=document.getElementById('bubble-all');if(all)all.classList.add('srv-bubble-active');
    if(overview)overview.style.display='none';
    if(bubbles)bubbles.style.display='none';
    document.querySelectorAll('.servicio-block').forEach(s=>{
      let any=false;
      s.querySelectorAll('.plan-card').forEach(c=>{const m=c.innerText.toLowerCase().includes(q);c.style.display=m?'':'none';if(m)any=true;});
      s.style.display=any?'':'none';
    });
  }else{
    document.querySelectorAll('.servicio-block .plan-card').forEach(c=>c.style.display='');
    document.querySelectorAll('.servicio-block').forEach(s=>s.style.display='none');
    if(overview)overview.style.display='';
    if(bubbles)bubbles.style.display='flex';
    document.querySelectorAll('.srv-bubble').forEach(b=>b.classList.remove('srv-bubble-active'));
    const all=document.getElementById('bubble-all');if(all)all.classList.add('srv-bubble-active');
  }
}
function abrirCompra(id,nombre,servicio,precio,dias,stock,img,esRev){
  if(stock === 0){ alert('⚠️ Este plan está agotado.'); return; }
  planActual={id,nombre,servicio,precio,dias,esCombo:false};
  const mImg=document.getElementById('mImg');
  mImg.style.display='block';mImg.src='assets/img/'+img;
  document.getElementById('mSrv').textContent=servicio;
  document.getElementById('mPlan').textContent=nombre;
  document.getElementById('mPrice').textContent=fmt(precio);
  document.getElementById('mDias').textContent='vigencia '+dias+' día'+(dias>1?'s':'');
  document.getElementById('mRevTag').style.display = esRev ? 'block' : 'none';
  document.getElementById('mAlert').style.display='none';
  document.getElementById('overlayCompra').classList.add('open');
  document.body.style.overflow='hidden';
}
function abrirCompraCombo(comboId, nombre, precio, dias, img, esRev){
  planActual = {id: null, comboId: comboId, nombre: nombre, precio: precio, dias: dias, esCombo: true};
  const mImg = document.getElementById('mImg');
  if (img) { mImg.style.display='block'; mImg.src='assets/img/'+img; }
  else { mImg.style.display='none'; }
  document.getElementById('mSrv').textContent = '🎁 COMBO';
  document.getElementById('mPlan').textContent = nombre;
  document.getElementById('mPrice').textContent = fmt(precio);
  document.getElementById('mDias').textContent = 'vigencia '+dias+' día'+(dias>1?'s':'');
  document.getElementById('mRevTag').style.display = esRev ? 'block' : 'none';
  document.getElementById('mAlert').style.display = 'none';
  document.getElementById('overlayCompra').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function cerrarModal(){document.getElementById('overlayCompra').classList.remove('open');document.body.style.overflow='';planActual=null}
function confirmarCompra(){
  if(!planActual || (!planActual.esCombo && !planActual.id)){
    document.getElementById('mAlert').textContent='Este plan estará disponible muy pronto.';
    document.getElementById('mAlert').style.display='block';return;
  }
  const btn=document.getElementById('btnConfirmar');
  btn.textContent='Comprando...';btn.disabled=true;
  document.getElementById('mAlert').style.display='none';
  const fd=new FormData();fd.append('csrf_token',CSRF);

  let endpoint;
  if (planActual.esCombo) {
    fd.append('combo_id', planActual.comboId);
    endpoint = 'ajax/comprar_combo.php';
  } else {
    fd.append('plan_id', planActual.id);
    endpoint = 'ajax/comprar.php';
  }

  fetch(endpoint,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
    btn.textContent='Comprar con saldo';btn.disabled=false;
    if(data.ok){
      cerrarModal();
      if(data.saldo_nuevo!==undefined){
        const sf='$'+Math.round(data.saldo_nuevo).toLocaleString('es-CO');
        document.getElementById('saldoDisplay').textContent=sf;
        document.getElementById('mSaldo').textContent=sf;
        const badge=document.querySelector('#btn-billetera .nav-badge');if(badge)badge.textContent=sf;
      }
      document.getElementById('cfMsg').textContent=data.msg||'¡Compra exitosa! Revisa tus pedidos.';
      document.getElementById('centerFlash').classList.add('open');
      setTimeout(()=>location.reload(),5000);
    }else{
      document.getElementById('mAlert').textContent=data.msg;
      document.getElementById('mAlert').className='modal-alert err';
      document.getElementById('mAlert').style.display='block';
    }
  }).catch(()=>{
    btn.textContent='Comprar con saldo';btn.disabled=false;
    document.getElementById('mAlert').textContent='Error de conexión';
    document.getElementById('mAlert').style.display='block';
  });
}
document.getElementById('overlayCompra').addEventListener('click',function(e){if(e.target===this)cerrarModal()});

// Billetera
function setMonto(val,el){document.getElementById('recargaMonto').value=val;document.querySelectorAll('.btn-monto').forEach(b=>b.classList.remove('active'));if(el)el.classList.add('active')}
function onRecargaFile(input){
  const has=input.files&&input.files.length>0;
  document.getElementById('recargaFileText').textContent=has?('✓ '+input.files[0].name):'📎 Adjuntar comprobante de transferencia';
  const lbl=document.getElementById('recargaFileLabel');
  lbl.style.borderColor=has?'var(--success)':'';lbl.style.color=has?'var(--success)':'';
}
function cerrarRecargaModal(){
  document.getElementById('recargaSuccessModal').classList.remove('open');
  document.body.style.overflow='';
}
function enviarRecarga(){
  // Quita puntos/separadores de miles: "8.000" → 8000
  const monto=parseInt(String(document.getElementById('recargaMonto').value).replace(/[^0-9]/g,''))||0;
  const fileInp=document.getElementById('recargaFile');
  const alertEl=document.getElementById('recargaAlert');
  const btn=document.getElementById('btnRecarga');
  alertEl.style.display='none';

  if(monto<5000){
    alertEl.innerHTML='⚠️ El monto mínimo es <b>$5.000 COP</b>';
    alertEl.className='f-alert err';
    alertEl.style.display='block';
    return;
  }
  if(!fileInp.files||!fileInp.files.length){
    alertEl.innerHTML='📎 Debes adjuntar el <b>comprobante de transferencia</b> para continuar.';
    alertEl.className='f-alert err';
    alertEl.style.display='block';
    return;
  }

  btn.innerHTML='<span style="display:inline-flex;align-items:center;gap:8px"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="animation:spin 1s linear infinite"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>Enviando…</span>';
  btn.disabled=true;

  const fd=new FormData();
  fd.append('monto',monto);
  fd.append('comprobante',fileInp.files[0]);
  fd.append('csrf_token',CSRF);

  fetch('ajax/recargar.php',{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
    btn.textContent='Solicitar recarga →';
    btn.disabled=false;
    if(data.ok){
      document.getElementById('recargaMonto').value='';
      fileInp.value='';
      document.getElementById('recargaFileText').textContent='📎 Adjuntar comprobante de transferencia';
      const lbl=document.getElementById('recargaFileLabel');
      lbl.style.borderColor='';lbl.style.color='';
      document.querySelectorAll('.btn-monto').forEach(b=>b.classList.remove('active'));
      document.body.style.overflow='hidden';
      document.getElementById('recargaSuccessModal').classList.add('open');
    }else{
      alertEl.innerHTML='❌ '+data.msg;
      alertEl.className='f-alert err';
      alertEl.style.display='block';
    }
  }).catch(()=>{
    btn.textContent='Solicitar recarga →';
    btn.disabled=false;
    alertEl.innerHTML='❌ Error de conexión. Intenta de nuevo.';
    alertEl.className='f-alert err';
    alertEl.style.display='block';
  });
}
document.getElementById('recargaSuccessModal').addEventListener('click',function(e){if(e.target===this)cerrarRecargaModal()});

// SLIDER / PASARELA
(function(){
  const A=5000,
        S=document.querySelectorAll('.hb-slide-pasarela'),
        D=document.querySelectorAll('.hb-dot'),
        P=document.getElementById('hbProgress');
  if(!S.length)return;
  let c=0,t;
  function go(n){
    S[c].classList.remove('active');if(D[c])D[c].classList.remove('active');
    c=(n+S.length)%S.length;
    S[c].classList.add('active');if(D[c])D[c].classList.add('active');
    rp();
  }
  function rp(){
    if(!P)return;
    P.style.transition='none';P.style.width='0%';P.offsetWidth;
    P.style.transition='width '+A+'ms linear';P.style.width='100%';
  }
  function play(){clearInterval(t);if(S.length>1)t=setInterval(()=>go(c+1),A)}
  const pB=document.getElementById('hbPrev'),nB=document.getElementById('hbNext');
  if(pB)pB.onclick=()=>{go(c-1);play()};
  if(nB)nB.onclick=()=>{go(c+1);play()};
  D.forEach(d=>{d.onclick=()=>{go(+d.dataset.i);play()}});
  let tx=0;const sl=document.getElementById('hbSlider');
  if(sl){
    sl.addEventListener('touchstart',e=>{tx=e.touches[0].clientX},{passive:true});
    sl.addEventListener('touchend',e=>{const dx=e.changedTouches[0].clientX-tx;if(Math.abs(dx)>40){go(dx<0?c+1:c-1);play()}},{passive:true});
    sl.addEventListener('mouseenter',()=>clearInterval(t));
    sl.addEventListener('mouseleave',()=>{play();rp()});
  }
  play();rp();
})();
</script>
<?php include __DIR__ . '/includes/modal-password.php'; ?>
</body>
</html>