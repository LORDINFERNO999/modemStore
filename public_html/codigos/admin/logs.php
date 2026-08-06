<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../services/LogService.php';

\Auth::requireAdmin();
$logs = LogService::recent(200);

$pageTitle = 'Admin · Registros';
include __DIR__ . '/../includes/header.php';
?>
<h4 class="mb-4"><i class="bi bi-clock-history"></i> Registro de consultas</h4>

<ul class="nav nav-pills mb-4">
  <li class="nav-item"><a class="nav-link" href="index.php">Inicio</a></li>
  <li class="nav-item"><a class="nav-link" href="mailboxes.php">Cuentas</a></li>
  <li class="nav-item"><a class="nav-link" href="users.php">Usuarios</a></li>
  <li class="nav-item"><a class="nav-link" href="assign.php">Asignaciones</a></li>
  <li class="nav-item"><a class="nav-link" href="platforms.php">Plataformas</a></li>
  <li class="nav-item"><a class="nav-link active" href="logs.php">Registros</a></li>
</ul>

<div class="table-responsive">
<table class="table table-dark table-striped table-sm">
<thead><tr><th>Fecha</th><th>Usuario</th><th>Cuenta</th><th>Servicio</th><th>Resultado</th><th>IP</th></tr></thead>
<tbody>
<?php foreach ($logs as $log): ?>
<tr>
  <td><?= htmlspecialchars($log['created_at']) ?></td>
  <td><?= htmlspecialchars($log['username'] ?? '—') ?></td>
  <td><?= htmlspecialchars($log['email'] ?? '—') ?></td>
  <td><?= htmlspecialchars($log['service_type'] ?? '—') ?></td>
  <td>
    <?php
      $badgeClass = match($log['result']) {
        'success' => 'bg-success',
        'denied' => 'bg-danger',
        'error' => 'bg-warning text-dark',
        default => 'bg-secondary',
      };
    ?>
    <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($log['result']) ?></span>
  </td>
  <td class="small text-secondary"><?= htmlspecialchars($log['ip']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
