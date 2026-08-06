<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Database.php';

\Auth::requireAdmin();
$db = Database::get();

/** Cuenta segura: si una tabla falta (migración pendiente), devuelve 0. */
function countOf(PDO $db, string $sql): int
{
    try {
        return (int)$db->query($sql)->fetchColumn();
    } catch (\PDOException $e) {
        return 0;
    }
}

$totalMailboxes  = countOf($db, 'SELECT COUNT(*) FROM mailboxes');
$activeMailboxes = countOf($db, 'SELECT COUNT(*) FROM mailboxes WHERE active = 1');
$totalUsers      = countOf($db, "SELECT COUNT(*) FROM users WHERE role = 'user'");
$totalPlatforms  = countOf($db, 'SELECT COUNT(*) FROM platforms WHERE active = 1');
$queriesToday    = countOf($db, 'SELECT COUNT(*) FROM query_logs WHERE DATE(created_at) = CURDATE()');
$successToday    = countOf($db, "SELECT COUNT(*) FROM query_logs WHERE result = 'success' AND DATE(created_at) = CURDATE()");

// Últimas consultas
try {
    $recent = $db->query(
        "SELECT ql.created_at, ql.result, u.username, m.email, m.service_type
         FROM query_logs ql
         LEFT JOIN users u ON u.id = ql.user_id
         LEFT JOIN mailboxes m ON m.id = ql.mailbox_id
         ORDER BY ql.created_at DESC LIMIT 8"
    )->fetchAll();
} catch (\PDOException $e) {
    $recent = [];
}

$pageTitle = 'Admin · Inicio';
include __DIR__ . '/../includes/header.php';

$cards = [
    ['Cuentas de correo', $totalMailboxes, $activeMailboxes . ' activas', 'bi-envelope-at', 'mailboxes.php'],
    ['Usuarios',          $totalUsers,     'operativos',                 'bi-people',      'users.php'],
    ['Plataformas',       $totalPlatforms, 'activas',                    'bi-collection-play', 'platforms.php'],
    ['Consultas hoy',     $queriesToday,   $successToday . ' con código', 'bi-clock-history', 'logs.php'],
];
?>
<h4 class="mb-4"><i class="bi bi-speedometer2"></i> Panel de administración</h4>

<ul class="nav nav-pills mb-4">
  <li class="nav-item"><a class="nav-link active" href="index.php">Inicio</a></li>
  <li class="nav-item"><a class="nav-link" href="mailboxes.php">Cuentas</a></li>
  <li class="nav-item"><a class="nav-link" href="users.php">Usuarios</a></li>
  <li class="nav-item"><a class="nav-link" href="assign.php">Asignaciones</a></li>
  <li class="nav-item"><a class="nav-link" href="platforms.php">Plataformas</a></li>
  <li class="nav-item"><a class="nav-link" href="logs.php">Registros</a></li>
</ul>

<div class="row g-3 mb-4">
  <?php foreach ($cards as [$title, $num, $sub, $icon, $link]): ?>
  <div class="col-6 col-lg-3">
    <a href="<?= $link ?>" class="text-decoration-none">
      <div class="card p-3 h-100">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <div class="text-secondary small"><?= htmlspecialchars($title) ?></div>
            <div style="font-size:2rem;font-weight:800;color:#d3c4ff"><?= (int)$num ?></div>
            <div class="text-secondary" style="font-size:.75rem"><?= htmlspecialchars($sub) ?></div>
          </div>
          <i class="bi <?= $icon ?>" style="font-size:1.6rem;color:var(--purple-2)"></i>
        </div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<div class="card p-3">
  <h6 class="mb-3"><i class="bi bi-activity"></i> Últimas consultas</h6>
  <?php if (empty($recent)): ?>
    <div class="text-secondary small">Aún no hay consultas registradas.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-dark table-sm align-middle mb-0">
      <thead><tr><th>Fecha</th><th>Usuario</th><th>Cuenta</th><th>Servicio</th><th>Resultado</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $log): ?>
        <tr>
          <td class="small text-secondary"><?= htmlspecialchars($log['created_at']) ?></td>
          <td><?= htmlspecialchars($log['username'] ?? '—') ?></td>
          <td class="small"><?= htmlspecialchars($log['email'] ?? '—') ?></td>
          <td class="small"><?= htmlspecialchars($log['service_type'] ?? '—') ?></td>
          <td>
            <?php $b = match($log['result']) { 'success'=>'bg-success','denied'=>'bg-danger','error'=>'bg-warning text-dark',default=>'bg-secondary' }; ?>
            <span class="badge <?= $b ?>"><?= htmlspecialchars($log['result']) ?></span>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
