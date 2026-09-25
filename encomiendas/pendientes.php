<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$emp = requerirCargo('Despachante', 'Administrador');
$base = '..';

/* HU11 / RF11: consultar encomiendas pendientes de entrega en la sucursal
   del despachante (estado "En oficina destino"). */

$stmt = $pdo->prepare(
    "SELECT en.id_encomienda, en.codigo_tracking, en.fecha_hora_llegada_real,
            des.nombres AS des_nombres, des.apellidos AS des_apellidos,
            td.nombre AS tipo_documento, des.numero_documento
     FROM encomienda en
     JOIN cliente des ON des.id_cliente = en.id_destinatario
     JOIN tipo_documento td ON td.id_tipo_documento = des.id_tipo_documento
     WHERE en.id_sucursal_destino = ?
       AND en.id_estado = (SELECT id_estado FROM estado_encomienda WHERE nombre = 'En oficina destino')
     ORDER BY en.fecha_hora_llegada_real ASC"
);
$stmt->execute([$emp['id_sucursal']]);
$pendientes = $stmt->fetchAll();

$tituloPagina = 'Pendientes de entrega';
require __DIR__ . '/../includes/header.php';
?>

<p class="mb-3 text-sm text-base-content/70">Encomiendas en <strong><?= e($emp['sucursal_nombre']) ?></strong>
  listas para ser recogidas por el destinatario.</p>

<?php if (!$pendientes): ?>
  <div class="alert alert-info"><span>No hay encomiendas pendientes de entrega en tu sucursal.</span></div>
<?php else: ?>
  <div class="overflow-x-auto bg-base-100 rounded-box shadow">
    <table class="table">
      <thead><tr><th>Código</th><th>Destinatario</th><th>Documento esperado</th><th>Llegó el</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($pendientes as $p): ?>
          <tr>
            <td class="font-mono"><?= e($p['codigo_tracking']) ?></td>
            <td><?= e($p['des_nombres'] . ' ' . $p['des_apellidos']) ?></td>
            <td><?= e($p['tipo_documento']) ?> ****<?= e(substr($p['numero_documento'], -3)) ?></td>
            <td><?= e($p['fecha_hora_llegada_real']) ?></td>
            <td><a href="entrega.php?codigo=<?= urlencode($p['codigo_tracking']) ?>" class="btn btn-sm btn-primary">Entregar</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
