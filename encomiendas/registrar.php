<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$emp = requerirCargo('Recepcionista', 'Administrador');
$base = '..';

/*
 * Implementa CU01 - Registrar encomienda (y HU03, HU04, HU05, HU06, HU07,
 * RF03-RF06, RF18, RN01-RN06) en dos pasos:
 *   - "calcular": valida los datos, busca la tarifa vigente y muestra
 *      el precio antes de confirmar (igual que en el caso de uso).
 *   - "confirmar": vuelve a validar y recién ahí escribe en la base de
 *      datos dentro de una transacción.
 * La sucursal de origen es siempre la del recepcionista en sesión.
 */

// ---- Catálogos para los <select> -------------------------------------
$tiposDocumento   = $pdo->query('SELECT * FROM tipo_documento ORDER BY nombre')->fetchAll();
$ciudades         = $pdo->query('SELECT * FROM ciudad ORDER BY nombre')->fetchAll();
$tiposEncomienda  = $pdo->query('SELECT * FROM tipo_encomienda ORDER BY nombre')->fetchAll();
$formasEnvio      = $pdo->query('SELECT * FROM forma_envio ORDER BY nombre')->fetchAll();
$productosProh    = $pdo->query('SELECT * FROM producto_prohibido ORDER BY nombre')->fetchAll();
$sucursalesDestino = $pdo->prepare(
    'SELECT suc.id_sucursal, suc.nombre, ciu.nombre AS ciudad
     FROM sucursal suc JOIN ciudad ciu ON ciu.id_ciudad = suc.id_ciudad
     WHERE suc.id_sucursal <> ? ORDER BY ciu.nombre, suc.nombre'
);
$sucursalesDestino->execute([$emp['id_sucursal']]);
$sucursalesDestino = $sucursalesDestino->fetchAll();

$campos = [
    'rem_id_tipo_documento', 'rem_numero_documento', 'rem_nombres', 'rem_apellidos',
    'rem_telefono', 'rem_direccion', 'rem_id_ciudad',
    'des_id_tipo_documento', 'des_numero_documento', 'des_nombres', 'des_apellidos',
    'des_telefono', 'des_direccion', 'des_id_ciudad',
    'id_sucursal_destino', 'id_tipo_encomienda', 'id_forma_envio',
    'peso_kg', 'volumen_m3', 'recargo_volumen', 'descripcion_contenido',
];

$etapa       = 'form';
$errores     = [];
$valores     = array_fill_keys($campos, '');
$prohibidosMarcados = [];
$tarifa      = null;
$precioTotal = null;
$resultado   = null; // 'ok' | 'rechazado'
$codigoGenerado = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($campos as $c) {
        $valores[$c] = trim((string) ($_POST[$c] ?? ''));
    }
    $prohibidosMarcados = array_map('intval', $_POST['productos_prohibidos'] ?? []);
    $etapaPost = $_POST['etapa'] ?? 'calcular';

    // ---- Validaciones básicas -----------------------------------------
    if ($valores['rem_id_tipo_documento'] === '' || $valores['rem_numero_documento'] === ''
        || $valores['rem_nombres'] === '' || $valores['rem_apellidos'] === '' || $valores['rem_id_ciudad'] === '') {
        $errores[] = 'Completa los datos obligatorios del remitente (documento, nombres, apellidos, ciudad).';
    }
    if ($valores['des_id_tipo_documento'] === '' || $valores['des_numero_documento'] === ''
        || $valores['des_nombres'] === '' || $valores['des_apellidos'] === '' || $valores['des_id_ciudad'] === '') {
        $errores[] = 'Completa los datos obligatorios del destinatario (documento, nombres, apellidos, ciudad).';
    }
    if ($valores['rem_id_tipo_documento'] !== '' && $valores['des_id_tipo_documento'] !== ''
        && $valores['rem_numero_documento'] === $valores['des_numero_documento']
        && $valores['rem_id_tipo_documento'] === $valores['des_id_tipo_documento']) {
        $errores[] = 'El remitente y el destinatario no pueden ser la misma persona.';
    }
    if ($valores['id_sucursal_destino'] === '') {
        $errores[] = 'Selecciona la sucursal de destino.';
    }
    if ($valores['id_tipo_encomienda'] === '' || $valores['id_forma_envio'] === '') {
        $errores[] = 'Selecciona el tipo de encomienda y la forma de envío.';
    }
    if ($valores['peso_kg'] === '' || !is_numeric($valores['peso_kg']) || (float) $valores['peso_kg'] <= 0) {
        $errores[] = 'Ingresa un peso válido (mayor a 0 kg).';
    }
    if ($valores['volumen_m3'] !== '' && (!is_numeric($valores['volumen_m3']) || (float) $valores['volumen_m3'] < 0)) {
        $errores[] = 'El volumen debe ser un número mayor o igual a 0.';
    }
    if ($valores['recargo_volumen'] === '') {
        $valores['recargo_volumen'] = '0';
    }
    if (!is_numeric($valores['recargo_volumen']) || (float) $valores['recargo_volumen'] < 0) {
        $errores[] = 'El recargo por volumen debe ser un número mayor o igual a 0.';
    }

    // ---- Cálculo de tarifa (RF06, CU01 flujo alterno "sin tarifa") -----
    if (!$errores) {
        $idCiudadOrigen  = (int) $emp['id_ciudad'];
        $stmtCd = $pdo->prepare('SELECT id_ciudad FROM sucursal WHERE id_sucursal = ?');
        $stmtCd->execute([(int) $valores['id_sucursal_destino']]);
        $idCiudadDestino = (int) $stmtCd->fetchColumn();

        if ($idCiudadDestino === $idCiudadOrigen) {
            $errores[] = 'La sucursal de destino no puede estar en la misma ciudad de origen ni ser la misma sucursal.';
        } else {
            $tarifa = obtenerTarifaVigente(
                $pdo,
                (int) $valores['id_tipo_encomienda'],
                (int) $valores['id_forma_envio'],
                $idCiudadOrigen,
                $idCiudadDestino
            );
            if (!$tarifa) {
                $errores[] = 'No existe una tarifa vigente para esa combinación de tipo de encomienda, '
                    . 'forma de envío, origen y destino. Solicita al administrador que la registre.';
            } else {
                $precioTotal = ((float) $tarifa['precio_por_kilo'] * (float) $valores['peso_kg'])
                    + (float) $valores['recargo_volumen'];
            }
        }
    }

    if (!$errores && $etapaPost === 'calcular') {
        $etapa = 'confirmar'; // muestra el resumen con precio antes de guardar
    } elseif (!$errores && $etapaPost === 'confirmar') {
        // ---- Confirmación: se escribe en la base de datos -------------
        try {
            $pdo->beginTransaction();

            $idRemitente = buscarOCrearCliente($pdo, [
                'id_tipo_documento' => (int) $valores['rem_id_tipo_documento'],
                'numero_documento'  => $valores['rem_numero_documento'],
                'nombres'           => $valores['rem_nombres'],
                'apellidos'         => $valores['rem_apellidos'],
                'telefono'          => $valores['rem_telefono'],
                'direccion'         => $valores['rem_direccion'],
                'id_ciudad'         => (int) $valores['rem_id_ciudad'],
            ]);
            $idDestinatario = buscarOCrearCliente($pdo, [
                'id_tipo_documento' => (int) $valores['des_id_tipo_documento'],
                'numero_documento'  => $valores['des_numero_documento'],
                'nombres'           => $valores['des_nombres'],
                'apellidos'         => $valores['des_apellidos'],
                'telefono'          => $valores['des_telefono'],
                'direccion'         => $valores['des_direccion'],
                'id_ciudad'         => (int) $valores['des_id_ciudad'],
            ]);

            $hayRechazo = count($prohibidosMarcados) > 0;
            $codigoGenerado = generarCodigoTracking($pdo);
            $idEstadoInicial = idEstadoPorNombre($pdo, $hayRechazo ? 'Rechazada' : 'Recepcionada');

            $ins = $pdo->prepare(
                'INSERT INTO encomienda
                 (codigo_tracking, id_tipo_encomienda, id_forma_envio, id_remitente,
                  id_destinatario, id_sucursal_origen, id_sucursal_destino, id_tarifa,
                  peso_kg, volumen_m3, recargo_volumen, precio_total,
                  descripcion_contenido, id_recepcionista, id_estado, fecha_hora_recepcion)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, NOW())'
            );
            // precio_total se recalcula igualmente por el trigger de la BD;
            // se envía aquí solo como valor inicial requerido por la columna.
            $ins->execute([
                $codigoGenerado,
                (int) $valores['id_tipo_encomienda'],
                (int) $valores['id_forma_envio'],
                $idRemitente,
                $idDestinatario,
                (int) $emp['id_sucursal'],
                (int) $valores['id_sucursal_destino'],
                (int) $tarifa['id_tarifa'],
                (float) $valores['peso_kg'],
                $valores['volumen_m3'] === '' ? null : (float) $valores['volumen_m3'],
                (float) $valores['recargo_volumen'],
                $precioTotal,
                $valores['descripcion_contenido'] !== '' ? $valores['descripcion_contenido'] : null,
                (int) $emp['id_empleado'],
                $idEstadoInicial,
            ]);
            $idEncomienda = (int) $pdo->lastInsertId();

            if ($hayRechazo) {
                $nombresProhibidos = [];
                $insProh = $pdo->prepare(
                    'INSERT INTO encomienda_producto_prohibido
                     (id_encomienda, id_producto_prohibido, id_empleado_revision,
                      fecha_hora_revision, resultado_revision, observacion)
                     VALUES (?, ?, ?, NOW(), "RECHAZADA", ?)'
                );
                foreach ($prohibidosMarcados as $idProd) {
                    $insProh->execute([
                        $idEncomienda, $idProd, (int) $emp['id_empleado'],
                        'Detectado durante la revisión de recepción.',
                    ]);
                }
                registrarCambioEstado(
                    $pdo, $idEncomienda, $idEstadoInicial, (int) $emp['id_empleado'],
                    'Encomienda rechazada en recepción por contener producto(s) prohibido(s).'
                );
                $resultado = 'rechazado';
            } else {
                registrarCambioEstado(
                    $pdo, $idEncomienda, $idEstadoInicial, (int) $emp['id_empleado'],
                    'Encomienda recepcionada.'
                );
                $resultado = 'ok';
            }

            $pdo->commit();
            $etapa = 'resultado';
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $errores[] = 'Ocurrió un error al registrar la encomienda: ' . $ex->getMessage();
            $etapa = 'form';
        }
    }
}

$tituloPagina = 'Registrar encomienda';
require __DIR__ . '/../includes/header.php';
?>

<?php if ($errores): ?>
  <div class="alert alert-error mb-4">
    <ul class="list-disc list-inside">
      <?php foreach ($errores as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($etapa === 'resultado'): ?>

  <?php if ($resultado === 'ok'): ?>
    <div class="alert alert-success mb-4">
      <span>Encomienda recepcionada correctamente.</span>
    </div>
    <div class="card bg-base-100 shadow">
      <div class="card-body">
        <h2 class="card-title">Código de seguimiento: <span class="font-mono"><?= e($codigoGenerado) ?></span></h2>
        <p>Precio total calculado: <strong><?= bs($precioTotal) ?></strong></p>
        <p class="text-sm text-base-content/70">Entrega este código al remitente para que pueda
          hacer seguimiento del envío y para que el cajero pueda cobrar el servicio.</p>
        <div class="card-actions mt-2">
          <a href="registrar.php" class="btn btn-primary btn-sm">Registrar otra encomienda</a>
          <a href="seguimiento.php?codigo=<?= urlencode($codigoGenerado) ?>" class="btn btn-outline btn-sm">Ver seguimiento</a>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="alert alert-error mb-4">
      <span>La encomienda fue <strong>RECHAZADA</strong>: el contenido declarado corresponde a
        producto(s) prohibido(s) por la normativa vigente (RN01).</span>
    </div>
    <div class="card bg-base-100 shadow">
      <div class="card-body">
        <h2 class="card-title">Código de referencia: <span class="font-mono"><?= e($codigoGenerado) ?></span></h2>
        <p class="text-sm text-base-content/70">Se conserva el registro con estado "Rechazada" para
          trazabilidad, pero la encomienda no continúa su proceso de envío.</p>
        <div class="card-actions mt-2">
          <a href="registrar.php" class="btn btn-primary btn-sm">Registrar otra encomienda</a>
        </div>
      </div>
    </div>
  <?php endif; ?>

<?php elseif ($etapa === 'confirmar'): ?>

  <div class="card bg-base-100 shadow mb-4">
    <div class="card-body">
      <h2 class="card-title">Confirmar registro</h2>
      <div class="overflow-x-auto">
        <table class="table">
          <tbody>
            <tr><th>Remitente</th><td><?= e($valores['rem_nombres'] . ' ' . $valores['rem_apellidos']) ?></td></tr>
            <tr><th>Destinatario</th><td><?= e($valores['des_nombres'] . ' ' . $valores['des_apellidos']) ?></td></tr>
            <tr><th>Sucursal origen</th><td><?= e($emp['sucursal_nombre']) ?></td></tr>
            <tr><th>Peso</th><td><?= e($valores['peso_kg']) ?> kg</td></tr>
            <tr><th>Recargo por volumen</th><td><?= bs($valores['recargo_volumen']) ?></td></tr>
            <tr><th>Precio por kilo (tarifa vigente)</th><td><?= bs($tarifa['precio_por_kilo']) ?></td></tr>
            <tr class="font-bold"><th>Precio total</th><td class="text-lg"><?= bs($precioTotal) ?></td></tr>
            <?php if ($prohibidosMarcados): ?>
              <tr><th class="text-error">Productos prohibidos marcados</th>
                  <td class="text-error">Sí — la encomienda será RECHAZADA al confirmar.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <form method="post" class="mt-4 flex gap-2">
        <?php foreach ($campos as $c): ?>
          <input type="hidden" name="<?= e($c) ?>" value="<?= e($valores[$c]) ?>">
        <?php endforeach; ?>
        <?php foreach ($prohibidosMarcados as $idProd): ?>
          <input type="hidden" name="productos_prohibidos[]" value="<?= (int) $idProd ?>">
        <?php endforeach; ?>
        <input type="hidden" name="etapa" value="confirmar">
        <button type="submit" class="btn btn-primary">Confirmar y registrar</button>
        <a href="registrar.php" class="btn btn-ghost">Editar datos</a>
      </form>
    </div>
  </div>

<?php else: ?>

  <form method="post" class="space-y-6">
    <input type="hidden" name="etapa" value="calcular">

    <div class="card bg-base-100 shadow">
      <div class="card-body">
        <h2 class="card-title text-base">Remitente</h2>
        <div class="grid sm:grid-cols-2 gap-3">
          <select name="rem_id_tipo_documento" class="select select-bordered" required>
            <option value="">Tipo de documento</option>
            <?php foreach ($tiposDocumento as $td): ?>
              <option value="<?= (int) $td['id_tipo_documento'] ?>" <?= $valores['rem_id_tipo_documento'] == $td['id_tipo_documento'] ? 'selected' : '' ?>><?= e($td['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="rem_numero_documento" placeholder="Número de documento" class="input input-bordered" value="<?= e($valores['rem_numero_documento']) ?>" required>
          <input type="text" name="rem_nombres" placeholder="Nombres" class="input input-bordered" value="<?= e($valores['rem_nombres']) ?>" required>
          <input type="text" name="rem_apellidos" placeholder="Apellidos" class="input input-bordered" value="<?= e($valores['rem_apellidos']) ?>" required>
          <input type="text" name="rem_telefono" placeholder="Teléfono" class="input input-bordered" value="<?= e($valores['rem_telefono']) ?>">
          <select name="rem_id_ciudad" class="select select-bordered" required>
            <option value="">Ciudad</option>
            <?php foreach ($ciudades as $c): ?>
              <option value="<?= (int) $c['id_ciudad'] ?>" <?= $valores['rem_id_ciudad'] == $c['id_ciudad'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="rem_direccion" placeholder="Dirección" class="input input-bordered sm:col-span-2" value="<?= e($valores['rem_direccion']) ?>">
        </div>
      </div>
    </div>

    <div class="card bg-base-100 shadow">
      <div class="card-body">
        <h2 class="card-title text-base">Destinatario</h2>
        <div class="grid sm:grid-cols-2 gap-3">
          <select name="des_id_tipo_documento" class="select select-bordered" required>
            <option value="">Tipo de documento</option>
            <?php foreach ($tiposDocumento as $td): ?>
              <option value="<?= (int) $td['id_tipo_documento'] ?>" <?= $valores['des_id_tipo_documento'] == $td['id_tipo_documento'] ? 'selected' : '' ?>><?= e($td['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="des_numero_documento" placeholder="Número de documento" class="input input-bordered" value="<?= e($valores['des_numero_documento']) ?>" required>
          <input type="text" name="des_nombres" placeholder="Nombres" class="input input-bordered" value="<?= e($valores['des_nombres']) ?>" required>
          <input type="text" name="des_apellidos" placeholder="Apellidos" class="input input-bordered" value="<?= e($valores['des_apellidos']) ?>" required>
          <input type="text" name="des_telefono" placeholder="Teléfono" class="input input-bordered" value="<?= e($valores['des_telefono']) ?>">
          <select name="des_id_ciudad" class="select select-bordered" required>
            <option value="">Ciudad</option>
            <?php foreach ($ciudades as $c): ?>
              <option value="<?= (int) $c['id_ciudad'] ?>" <?= $valores['des_id_ciudad'] == $c['id_ciudad'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="des_direccion" placeholder="Dirección" class="input input-bordered sm:col-span-2" value="<?= e($valores['des_direccion']) ?>">
        </div>
      </div>
    </div>

    <div class="card bg-base-100 shadow">
      <div class="card-body">
        <h2 class="card-title text-base">Envío</h2>
        <div class="grid sm:grid-cols-2 gap-3">
          <div class="form-control">
            <span class="label-text">Sucursal de origen</span>
            <input type="text" class="input input-bordered" value="<?= e($emp['sucursal_nombre']) ?>" disabled>
          </div>
          <select name="id_sucursal_destino" class="select select-bordered" required>
            <option value="">Sucursal de destino</option>
            <?php foreach ($sucursalesDestino as $s): ?>
              <option value="<?= (int) $s['id_sucursal'] ?>" <?= $valores['id_sucursal_destino'] == $s['id_sucursal'] ? 'selected' : '' ?>>
                <?= e($s['nombre']) ?> (<?= e($s['ciudad']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <select name="id_tipo_encomienda" class="select select-bordered" required>
            <option value="">Tipo de encomienda</option>
            <?php foreach ($tiposEncomienda as $t): ?>
              <option value="<?= (int) $t['id_tipo_encomienda'] ?>" <?= $valores['id_tipo_encomienda'] == $t['id_tipo_encomienda'] ? 'selected' : '' ?>><?= e($t['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="id_forma_envio" class="select select-bordered" required>
            <option value="">Forma de envío</option>
            <?php foreach ($formasEnvio as $f): ?>
              <option value="<?= (int) $f['id_forma_envio'] ?>" <?= $valores['id_forma_envio'] == $f['id_forma_envio'] ? 'selected' : '' ?>><?= e($f['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="number" step="0.01" min="0.01" name="peso_kg" placeholder="Peso (kg)" class="input input-bordered" value="<?= e($valores['peso_kg']) ?>" required>
          <input type="number" step="0.001" min="0" name="volumen_m3" placeholder="Volumen (m³, opcional)" class="input input-bordered" value="<?= e($valores['volumen_m3']) ?>">
          <input type="number" step="0.01" min="0" name="recargo_volumen" placeholder="Recargo por volumen (Bs, opcional)" class="input input-bordered" value="<?= e($valores['recargo_volumen'] !== '' ? $valores['recargo_volumen'] : '0') ?>">
          <textarea name="descripcion_contenido" placeholder="Descripción del contenido" class="textarea textarea-bordered sm:col-span-2"><?= e($valores['descripcion_contenido']) ?></textarea>
        </div>
      </div>
    </div>

    <?php if ($productosProh): ?>
    <div class="card bg-base-100 shadow">
      <div class="card-body">
        <h2 class="card-title text-base">Revisión de contenido (RN01)</h2>
        <p class="text-sm text-base-content/70 mb-2">Marca únicamente si el contenido declarado
          corresponde a alguno de estos productos prohibidos. Si marcas alguno, la encomienda
          será rechazada.</p>
        <div class="grid sm:grid-cols-2 gap-2">
          <?php foreach ($productosProh as $p): ?>
            <label class="label cursor-pointer justify-start gap-2">
              <input type="checkbox" name="productos_prohibidos[]" value="<?= (int) $p['id_producto_prohibido'] ?>" class="checkbox checkbox-error"
                <?= in_array((int) $p['id_producto_prohibido'], $prohibidosMarcados, true) ? 'checked' : '' ?>>
              <span class="label-text"><?= e($p['nombre']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <button type="submit" class="btn btn-primary">Calcular tarifa y continuar</button>
  </form>

<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
