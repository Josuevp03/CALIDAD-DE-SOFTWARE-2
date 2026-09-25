<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$emp = requerirCargo('Cajero', 'Administrador');
$base = '..';

/* Implementa CU02 - Registrar pago (HU08, RF08, RN05). */

$codigo    = trim((string) ($_GET['codigo'] ?? $_POST['codigo'] ?? ''));
$encomienda = null;
$pagoExistente = null;
$mensaje = null;
$error   = null;

if ($codigo !== '') {
    $encomienda = buscarEncomiendaPorCodigo($pdo, $codigo);
    if (!$encomienda) {
        $error = 'No se encontró ninguna encomienda con ese código de seguimiento.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT p.*, m.nombre AS metodo_pago
             FROM pago p JOIN metodo_pago m ON m.id_metodo_pago = p.id_metodo_pago
             WHERE p.id_encomienda = ?'
        );
        $stmt->execute([$encomienda['id_encomienda']]);
        $pagoExistente = $stmt->fetch() ?: null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_pago']) && $encomienda && !$pagoExistente) {
    $idMetodo = (int) ($_POST['id_metodo_pago'] ?? 0);
    if ($idMetodo <= 0) {
        $error = 'Selecciona un método de pago.';
    } else {
        try {
            $pdo->beginTransaction();
            $numeroRecibo = generarNumeroRecibo($pdo);
            $ins = $pdo->prepare(
                'INSERT INTO pago (id_encomienda, id_cajera, id_metodo_pago, monto, numero_recibo, fecha_hora_pago)
                 VALUES (?, ?, ?, ?, ?, NOW())'
            );
            $ins->execute([
                $encomienda['id_encomienda'], $emp['id_empleado'], $idMetodo,
                $encomienda['precio_total'], $numeroRecibo,
            ]);
            $pdo->commit();
            $mensaje = "Pago registrado correctamente. Recibo: $numeroRecibo.";

            // refrescar datos de pago para mostrarlos abajo
            $stmt = $pdo->prepare(
                'SELECT p.*, m.nombre AS metodo_pago
                 FROM pago p JOIN metodo_pago m ON m.id_metodo_pago = p.id_metodo_pago
                 WHERE p.id_encomienda = ?'
            );
            $stmt->execute([$encomienda['id_encomienda']]);
            $pagoExistente = $stmt->fetch() ?: null;
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $error = 'No se pudo registrar el pago: ' . $ex->getMessage();
        }
    }
}

$metodosPago = $pdo->query('SELECT * FROM metodo_pago ORDER BY nombre')->fetchAll();

$tituloPagina = 'Registrar pago';
require __DIR__ . '/../includes/header.php';
?>

<div class="card bg-base-100 shadow mb-4">
  <div class="card-body">
    <form method="get" class="flex gap-2">
      <input type="text" name="codigo" placeholder="Código de seguimiento (ej. LV260925A1B2C)"
             class="input input-bordered flex-1 font-mono" value="<?= e($codigo) ?>" required>
      <button type="submit" class="btn btn-primary">Buscar</button>
    </form>
  </div>
</div>

<?php if ($error): ?>
  <div class="alert alert-error mb-4"><span><?= e($error) ?></span></div>
<?php endif; ?>
<?php if ($mensaje): ?>
  <div class="alert alert-success mb-4"><span><?= e($mensaje) ?></span></div>
<?php endif; ?>

<?php if ($encomienda): ?>
  <div class="card bg-base-100 shadow mb-4">
    <div class="card-body">
      <h2 class="card-title">Encomienda <span class="font-mono"><?= e($encomienda['codigo_tracking']) ?></span></h2>
      <table class="table">
        <tbody>
          <tr><th>Remitente</th><td><?= e($encomienda['remitente_nombres'] . ' ' . $encomienda['remitente_apellidos']) ?></td></tr>
          <tr><th>Destinatario</th><td><?= e($encomienda['destinatario_nombres'] . ' ' . $encomienda['destinatario_apellidos']) ?></td></tr>
          <tr><th>Ruta</th><td><?= e($encomienda['sucursal_origen_nombre'] . ' (' . $encomienda['ciudad_origen_nombre'] . ')') ?>
              → <?= e($encomienda['sucursal_destino_nombre'] . ' (' . $encomienda['ciudad_destino_nombre'] . ')') ?></td></tr>
          <tr><th>Estado actual</th><td><span class="badge badge-info"><?= e($encomienda['estado']) ?></span></td></tr>
          <tr class="font-bold"><th>Monto a cobrar</th><td class="text-lg"><?= bs($encomienda['precio_total']) ?></td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($pagoExistente): ?>
    <div class="alert alert-info">
      <span>Esta encomienda ya fue pagada el <?= e($pagoExistente['fecha_hora_pago']) ?>
        mediante <?= e($pagoExistente['metodo_pago']) ?> — Recibo <?= e($pagoExistente['numero_recibo']) ?>.</span>
    </div>
  <?php else: ?>
    <div class="card bg-base-100 shadow">
      <div class="card-body">
        <form method="post" class="flex flex-wrap gap-3 items-end">
          <input type="hidden" name="codigo" value="<?= e($codigo) ?>">
          <label class="form-control">
            <span class="label-text">Método de pago</span>
            <select name="id_metodo_pago" class="select select-bordered" required>
              <option value="">Selecciona...</option>
              <?php foreach ($metodosPago as $m): ?>
                <option value="<?= (int) $m['id_metodo_pago'] ?>"><?= e($m['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button type="submit" name="confirmar_pago" value="1" class="btn btn-primary">Registrar pago</button>
        </form>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
