<?php
require_once 'includes/auth.php';
require_once 'includes/seguridad.php';
require_once 'includes/mailer.php';
if (isLoggedIn()) { header('Location: dashboard.php'); exit; }

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrfCheck()) {
    $error = 'Token de seguridad inválido. Recarga la página.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre   = trim($_POST['nombre'] ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    if (!$nombre || !$apellido || !$email || !$password || !$confirm) {
        $error = 'Completa todos los campos';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Ingresa un correo electrónico válido';
    } elseif ($password !== $confirm) {
        $error = 'Las contraseñas no coinciden';
    } elseif (strlen($password) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            $error = 'Este correo ya está registrado';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $token = bin2hex(random_bytes(32));

            // Verificación de correo desactivada: la cuenta queda verificada desde su creación
            $stmt = $pdo->prepare("INSERT INTO usuarios (nombre, apellido, email, password, email_verificado, token_verificacion) VALUES (?,?,?,?,1,?)");
            $stmt->execute([$nombre, $apellido, $email, $hash, $token]);

            $success = '¡Cuenta creada! Ya puedes iniciar sesión.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="assets/img/logo-crop.png">
<title>Registro — <?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
  --bg: #09090f;
  --bg2: #11111a;
  --surface: rgba(18, 18, 26, 0.94);
  --surface2: rgba(28, 28, 39, 0.98);
  --surface3: rgba(35, 35, 50, 0.96);
  --accent: #7c6dfa;
  --accent2: #f472b6;
  --accent3: #9c8df7;
  --text: #f4f4fb;
  --muted: #9b9bb1;
  --border: rgba(255, 255, 255, 0.09);
  --danger: #f87171;
  --success: #34d399;
  --radius-xl: 30px;
  --radius-lg: 22px;
  --radius-md: 14px;
  --transition: all 0.25s ease;
}
*{margin:0;padding:0;box-sizing:border-box}
html,body{width:100%}
body{font-family:'Inter',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;color:var(--text);background:radial-gradient(circle at top right,rgba(124,109,250,0.18),transparent 28%),radial-gradient(circle at bottom left,rgba(244,114,182,0.14),transparent 30%),linear-gradient(180deg,var(--bg),var(--bg2));overflow-x:hidden;-webkit-font-smoothing:antialiased}
.bg-blobs{position:fixed;inset:0;z-index:0;pointer-events:none}
.blob{position:absolute;border-radius:50%;filter:blur(110px);opacity:.12;mix-blend-mode:screen}
.blob1{width:min(500px,80vw);height:min(500px,80vw);background:var(--accent);top:-120px;right:-120px}
.blob2{width:min(380px,70vw);height:min(380px,70vw);background:var(--accent2);bottom:-120px;left:-120px}
.card{position:relative;z-index:1;width:min(100%,500px);background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-xl);padding:34px;box-shadow:0 30px 80px rgba(0,0,0,0.6);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);animation:slideUp .45s ease}
@keyframes slideUp{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:translateY(0)}}
.back-link{display:inline-flex;align-items:center;gap:8px;color:var(--muted);font-size:14px;text-decoration:none;margin-bottom:20px;transition:var(--transition);max-width:100%}
.back-link:hover{color:var(--text);transform:translateX(-3px)}
.brand{display:flex;flex-direction:column;align-items:center;text-align:center;width:100%;margin-bottom:26px}
.subtitle{color:var(--muted);font-size:15px;line-height:1.5}
.msg{display:flex;align-items:center;justify-content:center;gap:8px;border-radius:var(--radius-md);padding:14px 16px;font-size:14px;margin-bottom:22px;text-align:center;width:100%}
.msg.error{background:rgba(248,113,113,0.08);border:1px solid rgba(248,113,113,0.24);color:#ff9a9a;animation:shake .4s ease}
.msg.success{background:rgba(52,211,153,0.08);border:1px solid rgba(52,211,153,0.24);color:#7ef0be;animation:popIn .3s ease}
@keyframes shake{0%,100%{transform:translateX(0)}25%{transform:translateX(-4px)}75%{transform:translateX(4px)}}
@keyframes popIn{from{transform:scale(0.98);opacity:0}to{transform:scale(1);opacity:1}}
form{width:100%}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-group{margin-bottom:18px;width:100%}
label{display:block;font-size:11px;color:var(--muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:1.2px;font-weight:700}
.input-wrap{position:relative;width:100%}
.input-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);width:18px;height:18px;opacity:.72;pointer-events:none;fill:#d6d6e7}
input{width:100%;padding:14px 16px 14px 46px;background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-md);color:var(--text);font-family:'Inter',sans-serif;font-size:15px;transition:var(--transition);outline:none;display:block}
input::placeholder{color:rgba(255,255,255,0.22)}
input:focus{border-color:rgba(124,109,250,0.7);background:var(--surface3);box-shadow:0 0 0 4px rgba(124,109,250,0.14)}
.password-input{padding-right:78px}
.password-toggle{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:transparent;border:none;color:var(--muted);cursor:pointer;font-size:13px;font-weight:700;padding:8px 10px;border-radius:10px;transition:var(--transition)}
.password-toggle:hover{color:var(--text);background:rgba(255,255,255,0.04)}
.btn{width:100%;padding:15px;margin-top:10px;border:none;border-radius:var(--radius-md);color:#fff;cursor:pointer;font-family:'Inter',sans-serif;font-size:16px;font-weight:800;background:linear-gradient(135deg,var(--accent),var(--accent3));box-shadow:0 10px 24px rgba(124,109,250,0.25);transition:var(--transition)}
.btn:hover{transform:translateY(-2px);box-shadow:0 14px 30px rgba(124,109,250,0.38)}
.btn:active{transform:translateY(0)}
.link{text-align:center;margin-top:24px;font-size:14px;color:var(--muted)}
.link a{color:var(--accent);text-decoration:none;font-weight:700;transition:var(--transition)}
.link a:hover{color:var(--text);text-decoration:underline}

@media(max-width:480px){
  body{padding:12px;align-items:flex-start}
  .card{width:100%;max-width:100%;padding:22px 16px;border-radius:20px;margin-top:10px}
  .back-link{font-size:13px;margin-bottom:18px;white-space:nowrap}
  .brand{margin-bottom:22px}
  .subtitle{font-size:14px}
  .form-row{grid-template-columns:1fr;gap:0}
  .form-group{margin-bottom:16px}
  label{font-size:10px;margin-bottom:6px}
  input{padding:13px 14px 13px 44px;font-size:14px}
  .password-input{padding-right:70px}
  .input-icon{left:12px;width:16px;height:16px}
  .password-toggle{right:8px;font-size:12px}
  .btn{padding:14px;font-size:15px}
  .link{margin-top:20px;font-size:13px}
}
</style>
</head>
<body>

<div class="bg-blobs">
  <div class="blob blob1"></div>
  <div class="blob blob2"></div>
</div>

<div class="card">
  <a href="index.php" class="back-link">← Volver al inicio</a>

  <div class="brand">
    <img src="assets/img/logo-crop.png" alt="<?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?>" style="width:240px;max-width:80%;height:auto;margin-bottom:14px" onerror="this.src='assets/img/logo.png'">
    <p class="subtitle">Crea tu cuenta y empieza a comprar</p>
  </div>

  <?php if ($error): ?>
    <div class="msg error">⚠ <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="msg success">✓ <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
  <?php else: ?>

  <form method="POST" autocomplete="on">
    <?= csrfField() ?>
    <div class="form-row">
      <div class="form-group">
        <label for="nombre">Nombre</label>
        <div class="input-wrap">
          <svg class="input-icon" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5zm0 2c-4.33 0-8 2.17-8 5v1h16v-1c0-2.83-3.67-5-8-5z"/>
          </svg>
          <input id="nombre" name="nombre" type="text" placeholder="Juan"
                 value="<?= htmlspecialchars($_POST['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                 required autocomplete="given-name">
        </div>
      </div>

      <div class="form-group">
        <label for="apellido">Apellido</label>
        <div class="input-wrap">
          <svg class="input-icon" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5zm0 2c-4.33 0-8 2.17-8 5v1h16v-1c0-2.83-3.67-5-8-5z"/>
          </svg>
          <input id="apellido" name="apellido" type="text" placeholder="Pérez"
                 value="<?= htmlspecialchars($_POST['apellido'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                 required autocomplete="family-name">
        </div>
      </div>
    </div>

    <div class="form-group">
      <label for="email">Correo electrónico</label>
      <div class="input-wrap">
        <svg class="input-icon" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4-8 5-8-5V6l8 5 8-5v2z"/>
        </svg>
        <input id="email" name="email" type="email" placeholder="tu@correo.com"
               value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
               required autocomplete="email">
      </div>
    </div>

    <div class="form-group">
      <label for="password">Contraseña</label>
      <div class="input-wrap">
        <svg class="input-icon" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M17 8h-1V6a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2zm-6 6.73V16a1 1 0 0 0 2 0v-1.27a2 2 0 1 0-2 0zM10 8V6a2 2 0 1 1 4 0v2h-4z"/>
        </svg>
        <input id="password" name="password" type="password" class="password-input"
               placeholder="Mínimo 6 caracteres" required autocomplete="new-password">
        <button type="button" class="password-toggle" id="togglePassword">Ver</button>
      </div>
    </div>

    <div class="form-group">
      <label for="confirm">Confirmar contraseña</label>
      <div class="input-wrap">
        <svg class="input-icon" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M17 8h-1V6a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2zm-6 6.73V16a1 1 0 0 0 2 0v-1.27a2 2 0 1 0-2 0zM10 8V6a2 2 0 1 1 4 0v2h-4z"/>
        </svg>
        <input id="confirm" name="confirm" type="password" class="password-input"
               placeholder="Repite la contraseña" required autocomplete="new-password">
        <button type="button" class="password-toggle" id="toggleConfirm">Ver</button>
      </div>
    </div>

    <button class="btn" type="submit">Crear cuenta →</button>
  </form>

  <?php endif; ?>

  <div class="link">
    ¿Ya tienes cuenta? <a href="login.php">Inicia sesión</a>
  </div>
</div>

<script>
const togglePassword = document.getElementById('togglePassword');
const passwordInput = document.getElementById('password');
const toggleConfirm = document.getElementById('toggleConfirm');
const confirmInput = document.getElementById('confirm');

if (togglePassword) {
  togglePassword.addEventListener('click', () => {
    const isPassword = passwordInput.type === 'password';
    passwordInput.type = isPassword ? 'text' : 'password';
    togglePassword.textContent = isPassword ? 'Ocultar' : 'Ver';
  });
}
if (toggleConfirm) {
  toggleConfirm.addEventListener('click', () => {
    const isPassword = confirmInput.type === 'password';
    confirmInput.type = isPassword ? 'text' : 'password';
    toggleConfirm.textContent = isPassword ? 'Ocultar' : 'Ver';
  });
}
</script>
</body>
</html>