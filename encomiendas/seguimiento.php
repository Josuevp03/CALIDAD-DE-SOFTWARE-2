<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$base = '..';

/* HU20 / RF19: cualquier persona puede consultar el estado de una
   encomienda por su código de seguimiento, sin necesidad de iniciar sesión.
   No se muestran datos internos del empleado por tratarse de una consulta
   pública. */

$codigo     = trim((string) ($_GET['codigo'] ?? ''));
$encomienda = null;
$historial  = [];
$error      = null;

if ($codigo !== '') {
    $encomienda = buscarEncomiendaPorCodigo($pdo, $codigo);
    if (!$encomienda) {
        $error = 'No se encontró ninguna encomienda con ese código.';
    } else {
        $historial = historialDeEncomienda($pdo, $encomienda['id_encomienda']);
    }
}

$tituloPagina = 'Seguimiento de encomienda';
require __DIR__ . '/../includes/header.php';
?>

<div class="card bg-base-100 shadow mb-4">
  <div class="card-body">
    <form method="get" class="flex gap-2">
      <input type="text" name="codigo" placeholder="Código de seguimiento (ej. LV260925A1B2C)"
             class="input input-bordered flex-1 font-mono" value="<?= e($codigo) ?>" required>
      <button type="submit" class="btn btn-primary">Consultar</button>
    </form>
  </div>
</div>

<?php if ($error): ?><div class="alert alert-error mb-4"><span><?= e($error) ?></span></div><?php endif; ?>

<?php if ($encomienda): ?>
  <div class="card bg-base-100 shadow mb-4">
    <div class="card-body">
      <h2 class="card-title">Código <span class="font-mono"><?= e($encomienda['codigo_tracking']) ?></span>
        <span class="badge badge-primary ml-2"><?= e($encomienda['estado']) ?></span>
      </h2>
      <table class="table">
        <tbody>
          <tr><th>Tipo de encomienda</th><td><?= e($encomienda['tipo_encomienda']) ?></td></tr>
          <tr><th>Forma de envío</th><td><?= e($encomienda['forma_envio']) ?></td></tr>
          <tr><th>Origen</th><td><?= e($encomienda['sucursal_origen_nombre'] . ' (' . $encomienda['ciudad_origen_nombre'] . ')') ?></td></tr>
          <tr><th>Destino</th><td><?= e($encomienda['sucursal_destino_nombre'] . ' (' . $encomienda['ciudad_destino_nombre'] . ')') ?></td></tr>
          <tr><th>Fecha de recepción</th><td><?= e($encomienda['fecha_hora_recepcion']) ?></td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card bg-base-100 shadow">
    <div class="card-body">
      <h2 class="card-title text-base">Historial</h2>
      <ul class="timeline timeline-vertical">
        <?php foreach ($historial as $i => $h): ?>
          <li>
            <?php if ($i > 0): ?><hr class="bg-primary"/><?php endif; ?>
            <div class="timeline-start text-sm"><?= e($h['fecha_hora']) ?></div>
            <div class="timeline-middle">●</div>
            <div class="timeline-end timeline-box">
              <strong><?= e($h['estado']) ?></strong>
              <?php if ($h['observacion']): ?><p class="text-sm"><?= e($h['observacion']) ?></p><?php endif; ?>
            </div>
            <?php if ($i < count($historial) - 1): ?><hr class="bg-primary"/><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
