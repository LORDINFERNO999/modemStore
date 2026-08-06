<?php
// verificar.php — Confirma el correo electrónico
require_once 'includes/config.php';

$msg = '';
$ok = false;

$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    $msg = 'Link inválido.';
} else {
    $stmt = $pdo->prepare("SELECT id, nombre FROM usuarios WHERE token_verificacion = ? AND email_verificado = 0");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
        $pdo->prepare("UPDATE usuarios SET email_verificado = 1, token_verificacion = NULL WHERE id = ?")
            ->execute([$user['id']]);
        $ok = true;
        $msg = '¡Correo verificado exitosamente! Ya puedes iniciar sesión.';
    } else {
        $msg = 'El link es inválido o ya fue usado.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Verificar correo — <?= SITE_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:#0d0d0d;color:#fff;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:#161616;border:1px solid rgba(255,255,255,.08);border-radius:20px;padding:40px;max-width:420px;width:100%;text-align:center}
.icon{font-size:52px;margin-bottom:16px}
h1{font-size:20px;font-weight:800;margin-bottom:10px}
p{font-size:14px;color:#a3a3a3;line-height:1.6;margin-bottom:24px}
.btn{display:inline-block;padding:13px 28px;background:linear-gradient(135deg,#7c6dfa,#9c8df7);color:#fff;border-radius:10px;text-decoration:none;font-weight:700;font-size:14px}
.btn:hover{opacity:.9}
.err{color:#ef4444}
.ok{color:#1db954}
</style>
</head>
<body>
<div class="card">
    <div class="icon"><?= $ok ? '✓' : '✗' ?></div>
    <h1 class="<?= $ok ? 'ok' : 'err' ?>"><?= $ok ? '¡Verificado!' : 'Error' ?></h1>
    <p><?= htmlspecialchars($msg) ?></p>
    <a href="login.php" class="btn"><?= $ok ? 'Iniciar sesión →' : 'Volver al inicio' ?></a>
</div>
</body>
</html>
