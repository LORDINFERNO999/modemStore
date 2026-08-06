<?php
// recuperar.php — Solicitar recuperación de contraseña
require_once 'includes/config.php';
require_once 'includes/seguridad.php';
require_once 'includes/mailer.php';

$msg = '';
$msgTipo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequire();
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = 'Ingresa un correo válido.';
        $msgTipo = 'err';
    } else {
        $stmt = $pdo->prepare("SELECT id, nombre, email FROM usuarios WHERE email = ? AND estado = 'activo'");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare("UPDATE usuarios SET token_recuperacion = ? WHERE id = ?")
                ->execute([$token, $user['id']]);

            enviarRecuperacion($user['email'], $user['nombre'], $token);
        }

        // Siempre mostrar el mismo mensaje (no revelar si el email existe)
        $msg = 'Si el correo está registrado, recibirás un link para restablecer tu contraseña.';
        $msgTipo = 'ok';
    }
}

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Recuperar contraseña — <?= SITE_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:#0d0d0d;color:#fff;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:#161616;border:1px solid rgba(255,255,255,.08);border-radius:20px;padding:36px;max-width:400px;width:100%}
h1{font-size:20px;font-weight:800;margin-bottom:6px;text-align:center}
.sub{font-size:13px;color:#666;text-align:center;margin-bottom:24px}
label{display:block;font-size:12px;color:#a3a3a3;margin-bottom:6px;font-weight:600}
input[type=email]{width:100%;padding:12px 14px;background:#1e1e1e;border:1px solid rgba(255,255,255,.14);border-radius:10px;color:#fff;font-family:'Inter',sans-serif;font-size:14px;outline:none;margin-bottom:18px}
input[type=email]:focus{border-color:#7c6dfa}
.btn{width:100%;padding:14px;background:linear-gradient(135deg,#7c6dfa,#9c8df7);color:#fff;border:none;border-radius:10px;font-family:'Inter',sans-serif;font-size:15px;font-weight:700;cursor:pointer}
.btn:hover{opacity:.9}
.msg{border-radius:10px;padding:12px;font-size:13px;margin-bottom:16px;text-align:center}
.msg.ok{background:rgba(29,185,84,.1);border:1px solid rgba(29,185,84,.25);color:#1db954}
.msg.err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:#ef4444}
.back{display:block;text-align:center;margin-top:18px;font-size:13px;color:#666;text-decoration:none}
.back:hover{color:#fff}
</style>
</head>
<body>
<div class="card">
    <h1>🔑 Recuperar contraseña</h1>
    <p class="sub">Te enviaremos un link para restablecer tu contraseña.</p>

    <?php if ($msg): ?>
    <div class="msg <?= $msgTipo ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <label>Correo electrónico</label>
        <input type="email" name="email" required placeholder="tu@correo.com" autofocus>
        <button type="submit" class="btn">Enviar link de recuperación →</button>
    </form>
    <a href="login.php" class="back">← Volver a iniciar sesión</a>
</div>
</body>
</html>
