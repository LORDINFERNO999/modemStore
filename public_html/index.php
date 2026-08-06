<?php
// index.php — Página de inicio pública — CON COMBOS
require_once 'includes/config.php';
require_once 'includes/auth.php';

if (isLoggedIn()) {
    header('Location: dashboard.php'); exit;
}

try {
    $servicios = $pdo->query("
        SELECT s.*,
               MIN(p.precio) as precio_desde,
               COUNT(p.id) as total_planes
        FROM servicios s
        JOIN planes p ON p.servicio_id = s.id
        WHERE s.estado = 'activo' AND p.estado = 'activo'
        GROUP BY s.id
        ORDER BY s.id
    ")->fetchAll();
} catch(Exception $e) { $servicios = []; }

try {
    $hbSlides = $pdo->query("
        SELECT * FROM sliders WHERE estado = 'activo' ORDER BY id ASC
    ")->fetchAll();
} catch(Exception $e) { $hbSlides = []; }

// ── COMBOS ACTIVOS ───────────────────────────────────────────────
try {
    $combos = $pdo->query("
        SELECT c.*,
               COUNT(cp.plan_id) as total_planes_combo
        FROM combos c
        LEFT JOIN combo_planes cp ON cp.combo_id = c.id
        WHERE c.estado = 'activo'
        GROUP BY c.id
        ORDER BY c.nombre
    ")->fetchAll();
} catch(Exception $e) { $combos = []; }
// ────────────────────────────────────────────────────────────────

// Círculos: servicios + combos
$cats = [];
foreach ($servicios as $s) {
    $imgCirculo = !empty($s['imagen_circulo']) ? $s['imagen_circulo'] : ($s['imagen'] ?? '');
    if (!empty($imgCirculo)) {
        $cats[] = [
            'img'    => $imgCirculo,
            'label'  => $s['nombre'],
            'color'  => !empty($s['color']) ? $s['color'] : '#7c6dfa',
            'esCombo'=> false,
        ];
    }
}
// UN SOLO círculo "Combos" que agrupa todos (no uno por cada combo)
if (!empty($combos)) {
    $cats[] = [
        'img'    => '',
        'label'  => 'Combos',
        'color'  => $combos[0]['color'] ?? '#7c6dfa',
        'esCombo'=> true,
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#000000">
<link rel="icon" type="image/png" href="assets/img/logo-crop.png">
<title><?= SITE_NAME ?> — Streaming al mejor precio en Colombia</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#07070d;--surface:#000000;--surface2:#111111;
  --accent:#7c6dfa;--accent-glow:rgba(124,109,250,0.35);--accent2:#f472b6;
  --text:#f0f0f8;--muted:#7a7a96;--muted2:#555570;
  --border:rgba(255,255,255,0.07);--border-hover:rgba(124,109,250,0.35);
  --radius-xl:24px;--radius-lg:16px;--radius-md:10px;
  --ease:cubic-bezier(0.4,0,0.2,1);
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);overflow-x:hidden;-webkit-font-smoothing:antialiased}

/* NAV */
nav{position:fixed;top:0;left:0;right:0;z-index:200;display:flex;align-items:center;justify-content:space-between;padding:0 5%;height:68px;background:rgba(7,7,13,0.6);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);border-bottom:1px solid var(--border);transition:all .3s var(--ease)}
nav.scrolled{background:rgba(7,7,13,0.97);box-shadow:0 4px 40px rgba(0,0,0,0.5)}
.nav-logo{text-decoration:none;display:flex;align-items:center;gap:10px}
.nav-logo img{height:44px;object-fit:contain}
.nav-links{display:flex;align-items:center;gap:10px}
.btn-nav{padding:9px 20px;border-radius:var(--radius-md);font-size:14px;font-family:'DM Sans',sans-serif;font-weight:500;cursor:pointer;text-decoration:none;transition:all .25s var(--ease);white-space:nowrap}
.btn-outline{border:1px solid var(--border);color:var(--muted);background:transparent}
.btn-outline:hover{border-color:var(--border-hover);color:var(--text);background:rgba(124,109,250,0.07)}
.btn-fill{background:linear-gradient(135deg,var(--accent),#9c8df7);color:#fff;border:none;font-family:'Syne',sans-serif;font-weight:700;font-size:14px}
.btn-fill:hover{transform:translateY(-2px);box-shadow:0 6px 24px var(--accent-glow)}
.nav-hamburger{display:none;flex-direction:column;justify-content:center;align-items:center;width:44px;height:44px;gap:5px;cursor:pointer;background:rgba(255,255,255,0.05);border:1px solid var(--border);border-radius:var(--radius-md);transition:all .2s;flex-shrink:0}
.nav-hamburger:hover{background:rgba(124,109,250,0.1);border-color:rgba(124,109,250,0.3)}
.nav-hamburger span{display:block;width:18px;height:1.5px;background:var(--text);border-radius:2px;transition:all .3s var(--ease)}
.nav-hamburger.open span:nth-child(1){transform:translateY(6.5px) rotate(45deg)}
.nav-hamburger.open span:nth-child(2){opacity:0;transform:scaleX(0)}
.nav-hamburger.open span:nth-child(3){transform:translateY(-6.5px) rotate(-45deg)}
.nav-mobile-menu{display:none;position:fixed;top:60px;left:0;right:0;z-index:299;background:rgba(7,7,13,0.98);backdrop-filter:blur(20px);border-bottom:1px solid var(--border);padding:16px;flex-direction:column;gap:8px;animation:menuSlide .25s var(--ease)}
.nav-mobile-menu.open{display:flex}
@keyframes menuSlide{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
.nav-mobile-menu a{display:block;padding:14px 18px;border-radius:var(--radius-lg);font-size:15px;font-weight:500;color:var(--muted);border:1px solid var(--border);transition:all .2s;text-decoration:none}
.nav-mobile-menu a:hover{color:var(--text);background:rgba(255,255,255,0.04)}
.nav-mobile-menu .btn-fill-mobile{background:linear-gradient(135deg,var(--accent),#9c8df7);color:#fff;font-family:'Syne',sans-serif;font-weight:700;border:none;text-align:center;margin-top:4px}

/* HERO BAND */
.hero-band-section{margin-top:68px;background:#0a0a0a}
.hb-slider{position:relative;width:100%;height:400px;overflow:hidden;cursor:grab;background:#0a0a0a}
.hb-slider:active{cursor:grabbing}
.hb-slide-pasarela{position:absolute;inset:0;opacity:0;pointer-events:none;transition:opacity .55s ease}
.hb-slide-pasarela.active{opacity:1;pointer-events:auto}
.hb-slide-pasarela a{display:block;width:100%;height:100%}
.hb-slide-pasarela img{width:100%;height:100%;object-fit:cover;object-position:center;display:block;pointer-events:none}
.hb-slide-pasarela .hb-vignette{position:absolute;inset:0;pointer-events:none;background:linear-gradient(0deg,rgba(0,0,0,0.35) 0%,transparent 35%),linear-gradient(180deg,rgba(0,0,0,0.2) 0%,transparent 30%)}
.hb-brand{position:absolute;top:18px;right:20px;z-index:20;font-family:'Syne',sans-serif;font-size:13px;font-weight:800;letter-spacing:1px;text-align:right;color:rgba(255,255,255,0.9);text-shadow:0 2px 8px rgba(0,0,0,0.5);line-height:1.2}
.hb-arrow{position:absolute;top:50%;transform:translateY(-50%);z-index:30;width:44px;height:44px;border-radius:50%;background:rgba(0,0,0,0.4);border:1px solid rgba(255,255,255,0.2);color:rgba(255,255,255,0.8);cursor:pointer;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(6px);transition:all .2s}
.hb-arrow:hover{background:rgba(255,255,255,0.2);color:#fff}
.hb-prev{left:20px}.hb-next{right:20px}
.hb-dots{position:absolute;bottom:16px;left:50%;transform:translateX(-50%);z-index:30;display:flex;gap:8px}
.hb-dot{width:8px;height:8px;border-radius:50%;background:rgba(255,255,255,0.3);border:none;cursor:pointer;padding:0;transition:all .3s}
.hb-dot.active{background:#fff;width:24px;border-radius:4px}
.hb-progress{position:absolute;bottom:0;left:0;height:3px;z-index:30;background:rgba(255,255,255,0.55);width:0%;transition:width linear}
.hb-slider-empty{display:flex;align-items:center;justify-content:center;height:100%;color:rgba(255,255,255,0.35);font-size:15px;gap:8px}


/* CATEGORÍAS (círculos) — carrusel automático infinito */
.hb-cats{background:#111;border-top:1px solid rgba(255,255,255,0.07);border-bottom:1px solid rgba(255,255,255,0.07);padding:26px 0;overflow:hidden;position:relative}
.hb-cats-track{display:flex;align-items:center;width:max-content;animation:hbMarquee 60s linear infinite;will-change:transform}
.hb-cats:hover .hb-cats-track{animation-play-state:paused}
@keyframes hbMarquee{from{transform:translateX(0)}to{transform:translateX(-50%)}}
@media(prefers-reduced-motion:reduce){.hb-cats-track{animation:none}}
.hb-cat-item{display:flex;flex-direction:column;align-items:center;gap:9px;flex-shrink:0;text-decoration:none;cursor:pointer;transition:transform .2s ease;min-width:90px;padding:6px 0;margin:0 12px}
.hb-cat-item:hover{transform:scale(1.09)}
.hb-cat-item:active{transform:scale(0.93)}
.hb-cat-circle{width:90px;height:90px;border-radius:50%;border:2.5px solid;display:flex;align-items:center;justify-content:center;overflow:hidden;transition:all .25s ease;position:relative}
.hb-cat-circle img{width:66px;height:66px;object-fit:contain;filter:drop-shadow(0 2px 6px rgba(0,0,0,0.5))}
.hb-cat-circle .hb-cat-emoji{font-size:30px;line-height:1}
.hb-cat-item:hover .hb-cat-circle{box-shadow:0 0 22px rgba(255,255,255,0.18);border-width:3px}
.hb-cat-label{font-size:11px;font-weight:600;color:rgba(255,255,255,0.55);letter-spacing:.3px;white-space:nowrap;text-align:center;max-width:90px;overflow:hidden;text-overflow:ellipsis;transition:color .2s}
.hb-cat-item:hover .hb-cat-label{color:rgba(255,255,255,0.95)}
/* Badge COMBO en círculo */
.hb-combo-badge{position:absolute;top:-2px;right:-2px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;font-size:7px;font-weight:800;padding:2px 5px;border-radius:8px;line-height:1.4;letter-spacing:.3px;white-space:nowrap}

/* HERO SUB BAND */
.hero-sub-band{background:var(--bg);border-bottom:1px solid var(--border);padding:60px 8%;display:flex;align-items:center;justify-content:space-between;gap:40px;flex-wrap:wrap;position:relative;overflow:hidden}
.hero-sub-band::before{content:'';position:absolute;inset:0;pointer-events:none;background:radial-gradient(ellipse 60% 120% at 0% 50%,rgba(124,109,250,0.06),transparent 65%)}
.hero-sub-text{position:relative;z-index:1}
.hero-sub-badge{display:inline-flex;align-items:center;gap:8px;background:rgba(124,109,250,0.1);border:1px solid rgba(124,109,250,0.22);border-radius:40px;padding:7px 18px;font-size:12px;color:#b0a3f7;font-weight:600;letter-spacing:.5px;text-transform:uppercase;margin-bottom:16px}
.hero-sub-badge-dot{width:7px;height:7px;border-radius:50%;background:var(--accent);box-shadow:0 0 8px rgba(124,109,250,0.9);animation:pulseDot 2s ease-in-out infinite}
@keyframes pulseDot{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(1.4);opacity:0.5}}
.hero-sub-title{font-family:'Syne',sans-serif;font-size:clamp(28px,3.8vw,46px);font-weight:800;letter-spacing:-1.5px;line-height:1.12;margin-bottom:14px}
.hero-sub-title em{font-style:normal;background:linear-gradient(135deg,#a89cf7,var(--accent2));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.hero-sub-desc{font-size:17px;color:rgba(240,240,248,0.55);line-height:1.65;max-width:520px}
.hero-sub-btns{display:flex;gap:14px;flex-wrap:wrap;position:relative;z-index:1;flex-shrink:0}
.btn-hero-main{padding:17px 38px;border-radius:var(--radius-lg);background:linear-gradient(135deg,var(--accent),#9c8df7);color:#fff;font-family:'Syne',sans-serif;font-weight:700;font-size:16px;text-decoration:none;box-shadow:0 6px 30px var(--accent-glow);transition:all .3s var(--ease);white-space:nowrap}
.btn-hero-main:hover{transform:translateY(-3px);box-shadow:0 14px 40px var(--accent-glow)}
.btn-hero-sec{padding:17px 38px;border-radius:var(--radius-lg);border:1px solid rgba(255,255,255,0.12);color:rgba(240,240,248,0.75);font-size:16px;text-decoration:none;background:rgba(255,255,255,0.03);transition:all .3s var(--ease);backdrop-filter:blur(8px);white-space:nowrap}
.btn-hero-sec:hover{border-color:rgba(255,255,255,0.22);color:var(--text);background:rgba(255,255,255,0.07)}

/* STATS */
.stats{display:grid;grid-template-columns:repeat(4,1fr);background:var(--surface);border-bottom:1px solid var(--border)}
.stat-item{text-align:center;padding:48px 20px;border-right:1px solid var(--border);position:relative;overflow:hidden}
.stat-item:last-child{border-right:none}
.stat-item::after{content:'';position:absolute;top:0;left:50%;transform:translateX(-50%);width:40px;height:2px;background:linear-gradient(90deg,transparent,var(--accent),transparent)}
.stat-num{font-family:'Syne',sans-serif;font-size:clamp(32px,3.5vw,44px);font-weight:800;background:linear-gradient(135deg,#a89cf7,var(--accent2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;line-height:1}
.stat-lbl{font-size:14px;color:var(--muted);margin-top:10px;letter-spacing:.3px}

/* SERVICIOS */
.section{padding:100px 5%}
.section-inner{max-width:1600px;width:100%;margin:0 auto}
.section-label{font-size:12px;text-transform:uppercase;letter-spacing:3.5px;color:var(--accent);font-weight:700;margin-bottom:14px}
.section-title{font-family:'Syne',sans-serif;font-size:clamp(30px,4vw,48px);font-weight:800;letter-spacing:-1.5px;line-height:1.12;margin-bottom:16px;max-width:100%;word-wrap:break-word;overflow-wrap:break-word}
.section-sub{color:var(--muted);font-size:17px;max-width:520px;line-height:1.65;margin-bottom:60px}
.services-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:22px}
.service-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-xl);padding:0;text-align:center;text-decoration:none;transition:all .35s var(--ease);cursor:pointer;position:relative;overflow:hidden;display:flex;flex-direction:column;align-items:center}
.service-card::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,rgba(124,109,250,0.06),rgba(244,114,182,0.03));opacity:0;transition:opacity .35s}
.service-card::after{content:'';position:absolute;top:0;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,rgba(124,109,250,0.5),transparent);opacity:0;transition:opacity .35s}
.service-card:hover{transform:translateY(-8px);border-color:rgba(124,109,250,0.28);box-shadow:0 24px 48px rgba(0,0,0,0.55),0 0 0 1px rgba(124,109,250,0.1)}
.service-card:hover::before,.service-card:hover::after{opacity:1}
.service-card>*{position:relative;z-index:1}
.service-img-wrap{width:100%;aspect-ratio:1/1;background:rgba(255,255,255,0.03);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:center;transition:all .35s var(--ease);overflow:hidden}
.service-img{width:100%;height:100%;object-fit:cover;transition:transform .35s var(--ease)}
.service-card:hover .service-img-wrap{background:rgba(124,109,250,0.05)}
.service-card:hover .service-img{transform:scale(1.1)}
.service-info{padding:20px 18px 24px;width:100%}
.service-name{font-family:'Inter',sans-serif;font-weight:700;font-size:17px;letter-spacing:-0.1px;margin-bottom:6px;color:var(--text)}
.service-desde{font-size:12px;color:var(--muted2);letter-spacing:.3px}
.service-precio{font-family:'Inter',sans-serif;font-weight:800;font-size:22px;letter-spacing:-0.5px;background:linear-gradient(135deg,#c9b6ff,var(--accent2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-top:6px}
/* Tarjeta especial para combos */
.service-card.combo-card{border-color:rgba(124,109,250,0.2)}
.service-card.combo-card .service-img-wrap{background:linear-gradient(135deg,rgba(124,109,250,0.08),rgba(244,114,182,0.05))}
.combo-card-badge{position:absolute;top:12px;left:12px;z-index:10;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;font-size:10px;font-weight:800;padding:4px 10px;border-radius:20px;letter-spacing:.5px}
.combo-imgs{display:grid;grid-template-columns:repeat(2,1fr);gap:6px;padding:12px;width:100%;height:100%;align-content:center}
.combo-imgs img{width:100%;aspect-ratio:1/1;object-fit:cover;border-radius:10px;background:rgba(0,0,0,0.3)}
.combo-imgs img:only-child{grid-column:1/-1;width:70%;justify-self:center}
.combo-emoji{font-size:52px;line-height:1;grid-column:1/-1;text-align:center}

/* CÓMO FUNCIONA */
.how-section{background:var(--surface);border-top:1px solid var(--border);border-bottom:1px solid var(--border)}
.how-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:24px}
.how-card{background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-xl);padding:48px 36px;transition:all .35s var(--ease);position:relative;overflow:hidden}
.how-card:hover{border-color:rgba(124,109,250,0.25);transform:translateY(-4px)}
.how-num{font-family:'Syne',sans-serif;font-size:64px;font-weight:800;background:linear-gradient(135deg,rgba(124,109,250,0.4),rgba(244,114,182,0.3));-webkit-background-clip:text;-webkit-text-fill-color:transparent;line-height:1;margin-bottom:28px}
.how-title{font-family:'Syne',sans-serif;font-size:20px;font-weight:700;margin-bottom:12px}
.how-desc{color:var(--muted);font-size:15px;line-height:1.65}

/* CTA */
.cta-section{padding:130px 5%;text-align:center;position:relative;overflow:hidden}
.cta-bg{position:absolute;inset:0;pointer-events:none;background:radial-gradient(ellipse 55% 60% at 50% 50%,rgba(124,109,250,0.07),transparent 65%),radial-gradient(ellipse 30% 40% at 70% 60%,rgba(244,114,182,0.05),transparent 60%)}
.cta-section .section-title{max-width:620px;margin:0 auto 16px;position:relative}
.cta-section>p{color:var(--muted);font-size:18px;margin-bottom:44px;position:relative}
.cta-btns{display:flex;gap:14px;justify-content:center;flex-wrap:wrap;position:relative}

/* FOOTER */
footer{background:var(--surface);border-top:1px solid var(--border);padding:48px 5%;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:24px}
.footer-logo{display:flex;align-items:center;gap:10px;text-decoration:none}
.footer-logo img{height:40px;opacity:.8}
.footer-txt{color:var(--muted2);font-size:13px}
.footer-links{display:flex;gap:28px}
.footer-links a{color:var(--muted);font-size:14px;text-decoration:none;transition:color .25s}
.footer-links a:hover{color:var(--text)}


/* RESPONSIVE */
@media(max-width:992px){.how-grid{grid-template-columns:repeat(2,1fr)}.how-grid .how-card:last-child{grid-column:span 2}.section{padding:72px 5%}.services-grid{grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:18px}}
@media(max-width:768px){
  nav{padding:0 16px;height:60px}.nav-links{display:none}.nav-hamburger{display:flex}
  .hero-band-section{margin-top:60px}.hb-slider{height:300px}
  .hb-cats{padding:18px 14px;gap:12px}.hb-cat-circle{width:72px;height:72px}.hb-cat-circle img{width:52px;height:52px}.hb-cat-label{font-size:10px;max-width:72px}
  .hero-sub-band{padding:36px 20px;flex-direction:column;align-items:flex-start;gap:24px}
  .hero-sub-title{font-size:clamp(24px,6vw,32px)}.hero-sub-desc{font-size:15px}
  .hero-sub-btns{width:100%;gap:10px}.btn-hero-main,.btn-hero-sec{flex:1;text-align:center;padding:16px 20px;font-size:15px}
  .stats{grid-template-columns:repeat(2,1fr)}.stat-item{border-right:none;border-bottom:1px solid var(--border);padding:32px 16px}
  .stat-item:nth-child(odd){border-right:1px solid var(--border)}.stat-item:nth-child(3),.stat-item:nth-child(4){border-bottom:none}
  .services-grid{grid-template-columns:repeat(2,1fr);gap:14px}.service-card{border-radius:18px}.service-img-wrap{aspect-ratio:1/1}.service-img{width:100%;height:100%;object-fit:cover}
  .service-info{padding:14px 12px 18px}.service-name{font-size:15px;margin-bottom:4px}.service-desde{font-size:10px}.service-precio{font-size:18px}
  .how-grid{grid-template-columns:1fr;gap:14px}.how-grid .how-card:last-child{grid-column:span 1}.how-card{padding:30px 24px}.how-num{font-size:52px;margin-bottom:16px}
  .section{padding:56px 20px}.section-sub{margin-bottom:36px;font-size:15px}
  .cta-section{padding:72px 20px}.cta-section>p{font-size:15px}
  footer{flex-direction:column;text-align:center;align-items:center;padding:32px 20px;gap:18px}.footer-links{justify-content:center;flex-wrap:wrap;gap:18px}
}
@media(max-width:480px){
  .hb-slider{height:240px}.hb-arrow{display:none}.hb-cats{padding:14px 12px;gap:10px}.hb-cat-circle{width:62px;height:62px}.hb-cat-circle img{width:44px;height:44px}.hb-cat-label{font-size:9px;max-width:62px}
  .hero-sub-band{padding:28px 16px;gap:18px}.hero-sub-title{font-size:clamp(22px,6vw,28px);letter-spacing:-.5px}.hero-sub-desc{font-size:14px}
  .hero-sub-btns{flex-direction:column;gap:9px;width:100%}.btn-hero-main,.btn-hero-sec{width:100%;text-align:center;padding:16px 20px;font-size:15px;border-radius:12px}
  .services-grid{grid-template-columns:repeat(2,1fr);gap:12px}.service-card{border-radius:16px}.service-img-wrap{aspect-ratio:1/1}.service-img{width:100%;height:100%;object-fit:cover}
  .service-info{padding:12px 10px 16px}.service-name{font-size:14px}.service-desde{font-size:10px}.service-precio{font-size:17px}
  .stats{grid-template-columns:1fr}.stat-item{border-right:none!important;border-bottom:1px solid var(--border);padding:22px 20px;display:flex;align-items:center;gap:18px;text-align:left}.stat-item::after{display:none}.stat-item:last-child{border-bottom:none}.stat-num{font-size:34px;flex-shrink:0}.stat-lbl{margin-top:0;font-size:14px}
  .how-card{padding:26px 20px;border-radius:18px}.how-num{font-size:48px;margin-bottom:14px}.how-title{font-size:17px}.how-desc{font-size:14px}
  .section{padding:48px 16px}.section-label{font-size:10px;letter-spacing:2.5px}.section-title{font-size:clamp(24px,6vw,30px);letter-spacing:-.5px;margin-bottom:10px}.section-sub{font-size:14px;margin-bottom:28px}
  .cta-section{padding:56px 16px}.cta-section>p{font-size:14px;margin-bottom:28px}.cta-btns{flex-direction:column;align-items:stretch;gap:9px}.cta-btns a{width:100%;text-align:center;padding:16px 20px;font-size:15px;border-radius:12px}
  footer{padding:28px 16px;gap:14px}.footer-logo img{height:34px}.footer-txt{font-size:12px}.footer-links{gap:14px}.footer-links a{font-size:13px}
  .combo-imgs{padding:8px;gap:5px}
}
@media(max-width:360px){.hb-slider{height:200px}.hb-cat-circle{width:54px;height:54px}.hb-cat-circle img{width:38px;height:38px}.hb-cat-label{font-size:8.5px;max-width:54px}.services-grid{gap:10px}.service-card{border-radius:14px}.service-img-wrap{aspect-ratio:1/1}.service-img{width:100%;height:100%;object-fit:cover}.service-info{padding:10px 8px 14px}.service-name{font-size:13px}.service-desde{font-size:9px}.service-precio{font-size:15px}.hero-sub-title{font-size:20px}.hero-sub-desc{font-size:13px}.btn-hero-main,.btn-hero-sec{font-size:14px;padding:14px 16px}}
@media(hover:none){.service-card:hover{transform:none;box-shadow:none;border-color:var(--border)}.service-card:hover::before,.service-card:hover::after{opacity:0}.how-card:hover{transform:none;border-color:var(--border)}.btn-hero-main:hover{transform:none;box-shadow:0 6px 30px var(--accent-glow)}.btn-fill:hover{transform:none;box-shadow:none}.hb-cat-item:hover{transform:none}.hb-cat-item:hover .hb-cat-circle{box-shadow:none;border-width:2.5px}.hb-cat-item:hover .hb-cat-label{color:rgba(255,255,255,0.55)}.service-card:active{opacity:.72;transform:scale(0.95);transition:all .1s}.btn-hero-main:active{opacity:.82;transform:scale(0.96);transition:all .08s}.btn-hero-sec:active{opacity:.75;transform:scale(0.96);transition:all .08s}.hb-cat-item:active{transform:scale(0.88);transition:all .08s}.btn-fill:active{opacity:.82;transform:scale(0.96);transition:all .08s}.hb-dot:active{transform:scale(1.3);transition:all .08s}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important}.hero-sub-badge-dot{animation:none}}
</style>
</head>
<body>

<nav id="mainNav">
  <a href="index.php" class="nav-logo">
    <img src="assets/img/logo-crop.png" alt="<?= SITE_NAME ?>" onerror="this.src='assets/img/logo.png'">
  </a>
  <div class="nav-links">
    <a href="#servicios" class="btn-nav btn-outline">Servicios</a>
    <a href="#como" class="btn-nav btn-outline">¿Cómo funciona?</a>
    <a href="login.php" class="btn-nav btn-outline">Iniciar sesión</a>
    <a href="registro.php" class="btn-nav btn-fill">Crear cuenta</a>
  </div>
  <button class="nav-hamburger" id="hamburger" aria-label="Abrir menú" aria-expanded="false">
    <span></span><span></span><span></span>
  </button>
</nav>
<div class="nav-mobile-menu" id="mobileMenu">
  <a href="#servicios">📺 Servicios</a>
  <a href="#como">❓ ¿Cómo funciona?</a>
  <a href="login.php">🔑 Iniciar sesión</a>
  <a href="registro.php" class="btn-fill-mobile">✨ Crear cuenta gratis</a>
</div>


<!-- HERO BAND -->
<section class="hero-band-section">
  <div class="hb-slider" id="hbSlider">
    <?php if (!empty($hbSlides)): ?>
      <?php foreach ($hbSlides as $i => $sl): ?>
      <div class="hb-slide-pasarela <?= $i===0?'active':'' ?>">
        <a href="registro.php" draggable="false">
          <img src="assets/img/<?= htmlspecialchars($sl['imagen']) ?>"
            alt="<?= htmlspecialchars($sl['titulo']?:'Promo '.($i+1)) ?>"
            draggable="false" loading="<?= $i===0?'eager':'lazy' ?>"
            onerror="this.parentElement.parentElement.style.display='none'">
        </a>
        <div class="hb-vignette"></div>
      </div>
      <?php endforeach; ?>
      <?php if (count($hbSlides)>1): ?>
      <button class="hb-arrow hb-prev" id="hbPrev" aria-label="Anterior">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="15 18 9 12 15 6"/></svg>
      </button>
      <button class="hb-arrow hb-next" id="hbNext" aria-label="Siguiente">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
      </button>
      <div class="hb-dots" id="hbDots">
        <?php for($i=0;$i<count($hbSlides);$i++): ?>
        <button class="hb-dot <?= $i===0?'active':'' ?>" data-i="<?= $i ?>" aria-label="Slide <?= $i+1 ?>"></button>
        <?php endfor; ?>
      </div>
      <?php endif; ?>
      <div class="hb-progress" id="hbProgress"></div>
      <div class="hb-brand"><?= SITE_NAME ?></div>
    <?php else: ?>
      <div class="hb-slider-empty">📷 Sin imágenes</div>
    <?php endif; ?>
  </div>

  <!-- CÍRCULOS: servicios + combos -->
  <div class="hb-cats" id="hbCats">
    <div class="hb-cats-track">
    <?php for ($rep = 0; $rep < 2; $rep++): ?>
      <?php foreach ($cats as $cat): ?>
      <a href="registro.php" class="hb-cat-item"<?= $rep ? ' aria-hidden="true" tabindex="-1"' : '' ?>>
        <div class="hb-cat-circle" style="background:<?= htmlspecialchars($cat['color']) ?>22;border-color:<?= htmlspecialchars($cat['color']) ?>77">
          <?php if ($cat['img']): ?>
            <img src="assets/img/<?= htmlspecialchars($cat['img']) ?>" alt="<?= htmlspecialchars($cat['label']) ?>" loading="lazy" onerror="this.style.opacity='0'">
          <?php else: ?>
            <span class="hb-cat-emoji">🎁</span>
          <?php endif; ?>
          <?php if ($cat['esCombo']): ?>
            <span class="hb-combo-badge">COMBO</span>
          <?php endif; ?>
        </div>
        <span class="hb-cat-label"><?= htmlspecialchars($cat['label']) ?></span>
      </a>
      <?php endforeach; ?>
    <?php endfor; ?>
    </div>
  </div>
</section>

<!-- HERO SUB BAND -->
<div class="hero-sub-band">
  <div class="hero-sub-text">
    <div class="hero-sub-badge">
      <span class="hero-sub-badge-dot"></span>
      💎 Acceso VIP · Entrega instantánea · Pesos colombianos
    </div>
    <div class="hero-sub-title">Todo tu entretenimiento,<br><em>un solo lugar.</em></div>
    <p class="hero-sub-desc">Activa Netflix, Spotify, Disney+ y más desde tu billetera virtual. Sin tarjetas internacionales. Desde $<?= number_format(4900,0,'.','.') ?> COP.</p>
  </div>
  <div class="hero-sub-btns">
    <a href="registro.php" class="btn-hero-main">Empezar ahora →</a>
    <a href="#servicios" class="btn-hero-sec">Ver servicios</a>
  </div>
</div>

<!-- SERVICIOS + COMBOS -->
<section class="section" id="servicios">
  <div class="section-inner">
    <div class="section-label">Catálogo completo</div>
    <div class="section-title">Todo lo que necesitas en un solo lugar</div>
    <div class="section-sub">Desde $<?= number_format(4900,0,'.','.') ?> COP al mes. Planes para todos los gustos y bolsillos.</div>
    <div class="services-grid">

      <?php if (!empty($servicios)): ?>
        <?php foreach($servicios as $s): ?>
        <a href="registro.php" class="service-card">
          <div class="service-img-wrap">
            <img class="service-img" src="assets/img/<?= htmlspecialchars($s['imagen']) ?>" alt="<?= htmlspecialchars($s['nombre']) ?>" loading="lazy" onerror="this.style.display='none'">
          </div>
          <div class="service-info">
            <div class="service-name"><?= htmlspecialchars($s['nombre']) ?></div>
            <div class="service-desde">Desde</div>
            <div class="service-precio">$<?= number_format($s['precio_desde'],0,'.','.') ?> COP</div>
          </div>
        </a>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php foreach($combos as $c):
        // Obtener imágenes de los planes del combo para el collage
        $stmtCI = $pdo->prepare("
            SELECT p.imagen, s.imagen as srv_img
            FROM combo_planes cp
            JOIN planes p ON cp.plan_id = p.id
            JOIN servicios s ON p.servicio_id = s.id
            WHERE cp.combo_id = ? LIMIT 4
        ");
        $stmtCI->execute([$c['id']]);
        $comboImgsRows = $stmtCI->fetchAll();
      ?>
      <a href="registro.php" class="service-card combo-card" style="border-color:<?= htmlspecialchars($c['color']) ?>44">
        <span class="combo-card-badge">🎁 COMBO</span>
        <div class="service-img-wrap" style="position:relative;background:linear-gradient(135deg,<?= htmlspecialchars($c['color']) ?>18,rgba(244,114,182,0.06))">
          <!-- Collage de servicios (base, siempre visible como respaldo) -->
          <div class="combo-imgs">
            <?php if (!empty($comboImgsRows)): ?>
              <?php foreach($comboImgsRows as $ci): $ciImg = $ci['imagen']?:$ci['srv_img']; ?>
              <img src="assets/img/<?= htmlspecialchars($ciImg) ?>" loading="lazy" onerror="this.style.display='none'">
              <?php endforeach; ?>
            <?php else: ?>
              <span class="combo-emoji">🎁</span>
            <?php endif; ?>
          </div>
          <?php if ($c['imagen']): ?>
          <!-- Imagen propia del combo encima; si falla, se ve el collage de abajo -->
          <img class="service-img" style="position:absolute;inset:0" src="assets/img/<?= htmlspecialchars($c['imagen']) ?>" alt="<?= htmlspecialchars($c['nombre']) ?>" loading="lazy" onerror="this.style.display='none'">
          <?php endif; ?>
        </div>
        <div class="service-info">
          <div class="service-name"><?= htmlspecialchars($c['nombre']) ?></div>
          <div class="service-desde"><?= (int)$c['total_planes_combo'] ?> servicios incluidos</div>
          <div class="service-precio">$<?= number_format($c['precio'],0,'.','.') ?> COP</div>
        </div>
      </a>
      <?php endforeach; ?>

    </div>
    <div style="text-align:center;margin-top:52px">
      <a href="registro.php" class="btn-hero-main">Ver todos los planes →</a>
    </div>
  </div>
</section>

<!-- CÓMO FUNCIONA -->
<section class="section how-section" id="como">
  <div class="section-inner">
    <div class="section-label">Simple y rápido</div>
    <div class="section-title">¿Cómo funciona?</div>
    <div class="section-sub">En 3 pasos tienes tu cuenta activa y lista para usar.</div>
    <div class="how-grid">
      <div class="how-card"><div class="how-num">01</div><div class="how-title">Crea tu cuenta</div><div class="how-desc">Regístrate gratis en menos de 1 minuto. Solo necesitas tu correo electrónico.</div></div>
      <div class="how-card"><div class="how-num">02</div><div class="how-title">Realiza tu pago</div><div class="how-desc">Transfiere vía Nequi, Daviplata, transferencia bancaria o efectivo en pesos colombianos.</div></div>
      <div class="how-card"><div class="how-num">03</div><div class="how-title">Compra y disfruta</div><div class="how-desc">Elige tu plan favorito y recibe los datos de tu cuenta al instante directamente en la plataforma.</div></div>
    </div>
  </div>
</section>

<!-- STATS -->
<div class="stats">
  <div class="stat-item"><div class="stat-num">15+</div><div class="stat-lbl">Servicios disponibles</div></div>
  <div class="stat-item"><div class="stat-num">24/7</div><div class="stat-lbl">Soporte al cliente</div></div>
  <div class="stat-item"><div class="stat-num">100%</div><div class="stat-lbl">Entrega inmediata</div></div>
  <div class="stat-item"><div class="stat-num">COP</div><div class="stat-lbl">Pesos colombianos</div></div>
</div>

<!-- CTA FINAL -->
<section class="cta-section">
  <div class="cta-bg"></div>
  <div class="section-label">¿Listo?</div>
  <div class="section-title">Empieza hoy mismo</div>
  <p>Crea tu cuenta gratis y recarga desde $<?= number_format(5000,0,'.','.') ?> COP</p>
  <div class="cta-btns">
    <a href="registro.php" class="btn-hero-main">Crear cuenta gratis →</a>
    <a href="login.php" class="btn-hero-sec">Ya tengo cuenta</a>
  </div>
</section>

<!-- FOOTER -->
<footer>
  <a href="index.php" class="footer-logo"><img src="assets/img/logo-crop.png" alt="<?= SITE_NAME ?>" onerror="this.src='assets/img/logo.png'"></a>
  <p class="footer-txt">© <?= date('Y') ?> <?= SITE_NAME ?> · Medellín, Colombia 🇨🇴</p>
  <div class="footer-links"><a href="login.php">Iniciar sesión</a><a href="registro.php">Registrarse</a></div>
</footer>

<script>
const nav=document.getElementById('mainNav');
window.addEventListener('scroll',()=>{nav.classList.toggle('scrolled',window.scrollY>50)},{passive:true});
const hamburger=document.getElementById('hamburger'),mobileMenu=document.getElementById('mobileMenu');
hamburger.addEventListener('click',()=>{const o=mobileMenu.classList.toggle('open');hamburger.classList.toggle('open',o);hamburger.setAttribute('aria-expanded',String(o));document.body.style.overflow=o?'hidden':''});
mobileMenu.querySelectorAll('a').forEach(l=>l.addEventListener('click',()=>{mobileMenu.classList.remove('open');hamburger.classList.remove('open');hamburger.setAttribute('aria-expanded','false');document.body.style.overflow=''}));
window.addEventListener('scroll',()=>{if(mobileMenu.classList.contains('open')){mobileMenu.classList.remove('open');hamburger.classList.remove('open');hamburger.setAttribute('aria-expanded','false');document.body.style.overflow=''}},{passive:true});
// Reveal animado
const revealEls=document.querySelectorAll('.service-card,.how-card');
const io=new IntersectionObserver(entries=>{entries.forEach(e=>{if(e.isIntersecting){const i=Array.from(e.target.parentElement.children).indexOf(e.target)%6;e.target.style.transitionDelay=(i*.07)+'s';e.target.style.opacity='1';e.target.style.transform='translateY(0)';io.unobserve(e.target)}})},{threshold:.08,rootMargin:'0px 0px -20px 0px'});
revealEls.forEach(el=>{el.style.opacity='0';el.style.transform='translateY(18px)';el.style.transition='opacity .45s ease,transform .45s ease,border-color .3s,box-shadow .3s';io.observe(el)});
// Slider
(function(){const A=5000,S=document.querySelectorAll('.hb-slide-pasarela'),D=document.querySelectorAll('.hb-dot'),P=document.getElementById('hbProgress');if(!S.length)return;let c=0,t;function go(n){S[c].classList.remove('active');if(D[c])D[c].classList.remove('active');c=(n+S.length)%S.length;S[c].classList.add('active');if(D[c])D[c].classList.add('active');rp()}function rp(){if(!P)return;P.style.transition='none';P.style.width='0%';P.offsetWidth;P.style.transition='width '+A+'ms linear';P.style.width='100%'}function play(){clearInterval(t);if(S.length>1)t=setInterval(()=>go(c+1),A)}const pB=document.getElementById('hbPrev'),nB=document.getElementById('hbNext');if(pB)pB.onclick=()=>{go(c-1);play()};if(nB)nB.onclick=()=>{go(c+1);play()};D.forEach(d=>{d.onclick=()=>{go(+d.dataset.i);play()}});let tx=0;const sl=document.getElementById('hbSlider');sl.addEventListener('touchstart',e=>{tx=e.touches[0].clientX},{passive:true});sl.addEventListener('touchend',e=>{const dx=e.changedTouches[0].clientX-tx;if(Math.abs(dx)>40){go(dx<0?c+1:c-1);play()}},{passive:true});sl.addEventListener('mouseenter',()=>clearInterval(t));sl.addEventListener('mouseleave',()=>{play();rp()});play();rp()})();
// Drag scroll círculos
(function(){const el=document.getElementById('hbCats');if(!el)return;let d=false,sx,sl;el.addEventListener('mousedown',e=>{d=true;sx=e.pageX-el.offsetLeft;sl=el.scrollLeft});el.addEventListener('mouseleave',()=>{d=false});el.addEventListener('mouseup',()=>{d=false});el.addEventListener('mousemove',e=>{if(!d)return;e.preventDefault();el.scrollLeft=sl-(e.pageX-el.offsetLeft-sx)*1.5})})();
</script>
</body>
</html>