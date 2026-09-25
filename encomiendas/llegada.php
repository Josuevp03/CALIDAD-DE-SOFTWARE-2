<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$emp = requerirCargo('Recepcionista', 'Administrador');
$base = '..';

/* HU10 / RF10: registrar la llegada de encomiendas "En tránsito" cuya
   sucursal de destino es la del empleado en sesión. */

$mensaje = null;
$error   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id_encomienda'] ?? 0);
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT id_encomienda, codigo_tracking FROM encomienda
             WHERE id_encomienda = ? AND id_sucursal_destino = ?
               AND id_estado = (SELECT id_estado FROM estado_encomienda WHERE nombre = "En transito")
             FOR UPDATE'
        );
        $stmt->execute([$id, $emp['id_sucursal']]);
        $enc = $stmt->fetch();

        if (!$enc) {
            throw new RuntimeException('La encomienda ya no está disponible para registrar llegada.');
        }

        $upd = $pdo->prepare('UPDATE encomienda SET fecha_hora_llegada_real = NOW() WHERE id_encomienda = ?');
        $upd->execute([$id]);

        $idEstado = idEstadoPorNombre($pdo, 'En oficina destino');
        registrarCambioEstado($pdo, $id, $idEstado, $emp['id_empleado'], 'Llegada registrada en oficina destino.');

        $pdo->commit();
        $mensaje = "Llegada registrada para la encomienda {$enc['codigo_tracking']}. Ya puede ser recogida por el destinatario.";
    } catch (Throwable $ex) {
        $pdo->rollBack();
        $error = 'No se pudo registrar la llegada: ' . $ex->getMessage();
    }
}

$stmt = $pdo->prepare(
    "SELECT en.id_encomienda, en.codigo_tracking, en.fecha_hora_salida,
            so.nombre AS sucursal_origen, co.nombre AS ciudad_origen,
            des.nombres AS des_nombres, des.apellidos AS des_apellidos
     FROM encomienda en
     JOIN sucursal so ON so.id_sucursal = en.id_sucursal_origen
     JOIN ciudad co ON co.id_ciudad = so.id_ciudad
     JOIN cliente des ON des.id_cliente = en.id_destinatario
     WHERE en.id_sucursal_destino = ?
       AND en.id_estado = (SELECT id_estado FROM estado_encomienda WHERE nombre = 'En transito')
     ORDER BY en.fecha_hora_salida ASC"
);
$stmt->execute([$emp['id_sucursal']]);
$enTransito = $stmt->fetchAll();

$tituloPagina = 'Registrar llegada';
require __DIR__ . '/../includes/header.php';
?>

<?php if ($mensaje): ?><div class="alert alert-success mb-4"><span><?= e($mensaje) ?></span></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error mb-4"><span><?= e($error) ?></span></div><?php endif; ?>

<p class="mb-3 text-sm text-base-content/70">Encomiendas en tránsito hacia
  <strong><?= e($emp['sucursal_nombre']) ?></strong>.</p>

<?php if (!$enTransito): ?>
  <div class="alert alert-info"><span>No hay encomiendas en tránsito hacia tu sucursal.</span></div>
<?php else: ?>
  <div class="overflow-x-auto bg-base-100 rounded-box shadow">
    <table class="table">
      <thead><tr><th>Código</th><th>Destinatario</th><th>Origen</th><th>Salió el</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($enTransito as $p): ?>
          <tr>
            <td class="font-mono"><?= e($p['codigo_tracking']) ?></td>
            <td><?= e($p['des_nombres'] . ' ' . $p['des_apellidos']) ?></td>
            <td><?= e($p['sucursal_origen']) ?> (<?= e($p['ciudad_origen']) ?>)</td>
            <td><?= e($p['fecha_hora_salida']) ?></td>
            <td>
              <form method="post">
                <input type="hidden" name="id_encomienda" value="<?= (int) $p['id_encomienda'] ?>">
                <button type="submit" class="btn btn-sm btn-primary">Registrar llegada</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
