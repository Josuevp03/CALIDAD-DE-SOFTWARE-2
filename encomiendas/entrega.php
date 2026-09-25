<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$emp = requerirCargo('Despachante', 'Administrador');
$base = '..';

/* Implementa CU03 - Registrar entrega (HU12, HU13, RF12, RF13, RN07, RN08):
   el despachante verifica el documento presentado contra el registrado
   para el destinatario antes de completar la entrega. */

$codigo     = trim((string) ($_GET['codigo'] ?? $_POST['codigo'] ?? ''));
$encomienda = null;
$error      = null;
$mensaje    = null;

if ($codigo !== '') {
    $encomienda = buscarEncomiendaPorCodigo($pdo, $codigo);
    if (!$encomienda) {
        $error = 'No se encontró ninguna encomienda con ese código.';
    } elseif ($encomienda['estado'] !== 'En oficina destino') {
        $error = 'Esta encomienda no está en estado "En oficina destino" (estado actual: '
            . $encomienda['estado'] . '). No puede entregarse todavía.';
    } elseif ((int) $encomienda['id_sucursal_destino'] !== (int) $emp['id_sucursal']) {
        $error = 'Esta encomienda no corresponde a tu sucursal de destino.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_entrega']) && $encomienda && !$error) {
    $tipoDocPresentado = (int) ($_POST['id_tipo_documento_presentado'] ?? 0);
    $numeroPresentado  = trim((string) ($_POST['numero_documento_presentado'] ?? ''));

    if ($tipoDocPresentado <= 0 || $numeroPresentado === '') {
        $error = 'Ingresa el tipo y número de documento presentado por el destinatario.';
    } elseif ($tipoDocPresentado !== (int) $encomienda['destinatario_tipo_doc_id']
        || $numeroPresentado !== $encomienda['destinatario_numero_doc']) {
        // RN08: el documento presentado debe coincidir con el registrado.
        $error = 'El documento presentado NO coincide con los datos registrados del '
            . 'destinatario. No se puede completar la entrega.';
    } else {
        try {
            $pdo->beginTransaction();

            $ins = $pdo->prepare(
                'INSERT INTO entrega
                 (id_encomienda, id_despachante, id_receptor_presento,
                  id_tipo_documento_presentado, numero_documento_presentado,
                  fecha_hora_recojo, observaciones)
                 VALUES (?,?,?,?,?, NOW(), ?)'
            );
            $ins->execute([
                $encomienda['id_encomienda'], $emp['id_empleado'], $encomienda['id_destinatario'],
                $tipoDocPresentado, $numeroPresentado, 'Identidad verificada contra el registro del destinatario.',
            ]);

            $idEstado = idEstadoPorNombre($pdo, 'Entregada');
            registrarCambioEstado(
                $pdo, $encomienda['id_encomienda'], $idEstado, $emp['id_empleado'],
                'Entregada al destinatario tras verificar su documento.'
            );

            $pdo->commit();
            $mensaje = 'Entrega registrada correctamente. La encomienda quedó en estado "Entregada".';
            $encomienda = buscarEncomiendaPorCodigo($pdo, $codigo); // refrescar estado mostrado
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $error = 'No se pudo registrar la entrega: ' . $ex->getMessage();
        }
    }
}

$tiposDocumento = $pdo->query('SELECT * FROM tipo_documento ORDER BY nombre')->fetchAll();

$tituloPagina = 'Registrar entrega';
require __DIR__ . '/../includes/header.php';
?>

<div class="card bg-base-100 shadow mb-4">
  <div class="card-body">
    <form method="get" class="flex gap-2">
      <input type="text" name="codigo" placeholder="Código de seguimiento" class="input input-bordered flex-1 font-mono" value="<?= e($codigo) ?>" required>
      <button type="submit" class="btn btn-primary">Buscar</button>
    </form>
  </div>
</div>

<?php if ($error): ?><div class="alert alert-error mb-4"><span><?= e($error) ?></span></div><?php endif; ?>
<?php if ($mensaje): ?><div class="alert alert-success mb-4"><span><?= e($mensaje) ?></span></div><?php endif; ?>

<?php if ($encomienda): ?>
  <div class="card bg-base-100 shadow mb-4">
    <div class="card-body">
      <h2 class="card-title">Encomienda <span class="font-mono"><?= e($encomienda['codigo_tracking']) ?></span></h2>
      <table class="table">
        <tbody>
          <tr><th>Destinatario registrado</th><td><?= e($encomienda['destinatario_nombres'] . ' ' . $encomienda['destinatario_apellidos']) ?></td></tr>
          <tr><th>Estado actual</th><td><span class="badge badge-info"><?= e($encomienda['estado']) ?></span></td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($encomienda['estado'] === 'En oficina destino' && !$mensaje): ?>
    <div class="card bg-base-100 shadow">
      <div class="card-body">
        <h2 class="card-title text-base">Verificar identidad y entregar</h2>
        <p class="text-sm text-base-content/70 mb-2">Ingresa el documento que presenta la
          persona en mostrador; el sistema lo comparará con el registrado para el destinatario.</p>
        <form method="post" class="grid sm:grid-cols-3 gap-3 items-end">
          <input type="hidden" name="codigo" value="<?= e($codigo) ?>">
          <select name="id_tipo_documento_presentado" class="select select-bordered" required>
            <option value="">Tipo de documento</option>
            <?php foreach ($tiposDocumento as $td): ?>
              <option value="<?= (int) $td['id_tipo_documento'] ?>"><?= e($td['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="numero_documento_presentado" placeholder="Número de documento" class="input input-bordered" required>
          <button type="submit" name="confirmar_entrega" value="1" class="btn btn-primary">Verificar y entregar</button>
        </form>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
