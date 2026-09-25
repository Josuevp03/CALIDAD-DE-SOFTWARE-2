<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$emp = requerirCargo('Recepcionista', 'Administrador');
$base = '..';

/* HU09 / RF09: registrar la salida (fecha/hora) de encomiendas ya
   recepcionadas en la sucursal de origen del empleado en sesión. */

$mensaje = null;
$error   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id_encomienda'] ?? 0);
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT id_encomienda, codigo_tracking FROM encomienda
             WHERE id_encomienda = ? AND id_sucursal_origen = ?
               AND id_estado = (SELECT id_estado FROM estado_encomienda WHERE nombre = "Recepcionada")
             FOR UPDATE'
        );
        $stmt->execute([$id, $emp['id_sucursal']]);
        $enc = $stmt->fetch();

        if (!$enc) {
            throw new RuntimeException('La encomienda ya no está disponible para registrar salida.');
        }

        $upd = $pdo->prepare('UPDATE encomienda SET fecha_hora_salida = NOW() WHERE id_encomienda = ?');
        $upd->execute([$id]);

        $idEstado = idEstadoPorNombre($pdo, 'En transito');
        registrarCambioEstado($pdo, $id, $idEstado, $emp['id_empleado'], 'Salida registrada hacia destino.');

        $pdo->commit();
        $mensaje = "Salida registrada para la encomienda {$enc['codigo_tracking']}.";
    } catch (Throwable $ex) {
        $pdo->rollBack();
        $error = 'No se pudo registrar la salida: ' . $ex->getMessage();
    }
}

$stmt = $pdo->prepare(
    "SELECT en.id_encomienda, en.codigo_tracking, en.fecha_hora_recepcion, en.peso_kg,
            te.nombre AS tipo_encomienda, fe.nombre AS forma_envio,
            sd.nombre AS sucursal_destino, cd.nombre AS ciudad_destino,
            des.nombres AS des_nombres, des.apellidos AS des_apellidos
     FROM encomienda en
     JOIN tipo_encomienda te ON te.id_tipo_encomienda = en.id_tipo_encomienda
     JOIN forma_envio fe ON fe.id_forma_envio = en.id_forma_envio
     JOIN sucursal sd ON sd.id_sucursal = en.id_sucursal_destino
     JOIN ciudad cd ON cd.id_ciudad = sd.id_ciudad
     JOIN cliente des ON des.id_cliente = en.id_destinatario
     WHERE en.id_sucursal_origen = ?
       AND en.id_estado = (SELECT id_estado FROM estado_encomienda WHERE nombre = 'Recepcionada')
     ORDER BY en.fecha_hora_recepcion ASC"
);
$stmt->execute([$emp['id_sucursal']]);
$pendientesSalida = $stmt->fetchAll();

$tituloPagina = 'Registrar salida';
require __DIR__ . '/../includes/header.php';
?>

<?php if ($mensaje): ?><div class="alert alert-success mb-4"><span><?= e($mensaje) ?></span></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error mb-4"><span><?= e($error) ?></span></div><?php endif; ?>

<p class="mb-3 text-sm text-base-content/70">Encomiendas recepcionadas en
  <strong><?= e($emp['sucursal_nombre']) ?></strong> listas para salir hacia su destino.</p>

<?php if (!$pendientesSalida): ?>
  <div class="alert alert-info"><span>No hay encomiendas pendientes de salida en tu sucursal.</span></div>
<?php else: ?>
  <div class="overflow-x-auto bg-base-100 rounded-box shadow">
    <table class="table">
      <thead>
        <tr><th>Código</th><th>Destinatario</th><th>Destino</th><th>Tipo / Envío</th><th>Peso</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($pendientesSalida as $p): ?>
          <tr>
            <td class="font-mono"><?= e($p['codigo_tracking']) ?></td>
            <td><?= e($p['des_nombres'] . ' ' . $p['des_apellidos']) ?></td>
            <td><?= e($p['sucursal_destino']) ?> (<?= e($p['ciudad_destino']) ?>)</td>
            <td><?= e($p['tipo_encomienda']) ?> · <?= e($p['forma_envio']) ?></td>
            <td><?= e($p['peso_kg']) ?> kg</td>
            <td>
              <form method="post">
                <input type="hidden" name="id_encomienda" value="<?= (int) $p['id_encomienda'] ?>">
                <button type="submit" class="btn btn-sm btn-primary">Registrar salida</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
