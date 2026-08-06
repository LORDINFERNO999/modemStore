<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Csrf.php';

// Destino según el rol: el admin entra directo a su panel.
function landingUrl(): string
{
    return \Auth::isAdmin() ? '../admin/index.php' : 'dashboard.php';
}

if (\Auth::check()) {
    header('Location: ' . landingUrl());
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    \Csrf::requireValid();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Usuario y contraseña son obligatorios.';
    } elseif (\Auth::attempt($username, $password)) {
        header('Location: ' . landingUrl());
        exit;
    } else {
        $error = 'Usuario o contraseña incorrectos.';
    }
}

$pageTitle = 'Iniciar sesión - VerifyCodes';
include __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center" style="margin-top:8vh">
  <div class="col-md-5 col-lg-4">
    <div class="card p-4 p-md-5">
      <div class="text-center mb-4">
        <div class="mb-2" style="font-size:2.6rem;color:var(--purple-2)"><i class="bi bi-shield-lock-fill"></i></div>
        <h4 class="mb-1 fw-bold">VerifyCodes</h4>
        <div class="text-secondary small">Consulta tus códigos de verificación</div>
      </div>
      <?php if ($error): ?>
        <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <form method="post">
        <?= \Csrf::field() ?>
        <div class="mb-3">
          <label class="form-label small text-secondary">Usuario</label>
          <input type="text" name="username" class="form-control" placeholder="tu usuario" required autofocus>
        </div>
        <div class="mb-4">
          <label class="form-label small text-secondary">Contraseña</label>
          <input type="password" name="password" class="form-control" placeholder="••••••••" required>
        </div>
        <button type="submit" class="btn btn-success w-100 py-2"><i class="bi bi-box-arrow-in-right"></i> Ingresar</button>
      </form>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
