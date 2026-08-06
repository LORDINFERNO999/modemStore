<?php
// reset-password.php — Restablecer contraseña con token
require_once 'includes/config.php';
require_once 'includes/seguridad.php';

$msg = '';
$msgTipo = '';
$tokenValido = false;

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

if (!empty($token)) {
    $stmt = $pdo->prepare("SELECT id, nombre FROM usuarios WHERE token_recuperacion = ? AND estado = 'activo'");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if ($user) {
        $tokenValido = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValido) {
    csrfRequire();
    $pass1 = $_POST['password'] ?? '';
    $pass2 = $_POST['password2'] ?? '';

    if (strlen($pass1) < 6) {
        $msg = 'La contraseña debe tener al menos 6 caracteres.';
        $msgTipo = 'err';
    } elseif ($pass1 !== $pass2) {
        $msg = 'Las contraseñas no coinciden.';
        $msgTipo = 'err';
    } else {
        $hash = password_hash($pass1, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE usuarios SET password = ?, token_recuperacion = NULL WHERE id = ?")
            ->execute([$hash, $user['id']]);
        $msg = '¡Contraseña actualizada! Ya puedes iniciar sesión.';
        $msgTipo = 'ok';
        $tokenValido = false; // ocultar el formulario
    }
}

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Nueva contraseña — <?= SITE_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:#0d0d0d;color:#fff;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:#161616;border:1px solid rgba(255,255,255,.08);border-radius:20px;padding:36px;max-width:400px;width:100%}
h1{font-size:20px;font-weight:800;margin-bottom:6px;text-align:center}
.sub{font-size:13px;color:#666;text-align:center;margin-bottom:24px}
label{display:block;font-size:12px;color:#a3a3a3;margin-bottom:6px;font-weight:600}
input[type=password]{width:100%;padding:12px 14px;background:#1e1e1e;border:1px solid rgba(255,255,255,.14);border-radius:10px;color:#fff;font-family:'Inter',sans-serif;font-size:14px;outline:none;margin-bottom:14px}
input[type=password]:focus{border-color:#7c6dfa}
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
    <h1>🔒 Nueva contraseña</h1>
    <p class="sub">Ingresa tu nueva contraseña.</p>

    <?php if ($msg): ?>
    <div class="msg <?= $msgTipo ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <?php if ($tokenValido): ?>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <label>Nueva contraseña</label>
        <input type="password" name="password" required minlength="6" placeholder="Mínimo 6 caracteres">
        <label>Confirmar contraseña</label>
        <input type="password" name="password2" required minlength="6" placeholder="Repite la contraseña">
        <button type="submit" class="btn">Guardar contraseña →</button>
    </form>
    <?php elseif ($msgTipo !== 'ok'): ?>
    <div class="msg err">El link es inválido o ya expiró.</div>
    <?php endif; ?>

    <a href="login.php" class="back">← Ir a iniciar sesión</a>
</div>
</body>
</html>
