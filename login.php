<?php
require_once __DIR__ . '/includes/bootstrap.php';

/*
 * NOTA DE ALCANCE:
 * HU01 (gestión de usuarios y roles con autenticación por contraseña) no
 * forma parte de este entregable porque el pedido se centró en el flujo de
 * envío/recepción de encomiendas. Para poder cumplir RN06/RN07/RN10
 * (identificar siempre al empleado responsable de cada operación) se usa
 * una sesión simplificada: el empleado se selecciona de una lista real de
 * la tabla "empleado". Sustituir esto por login con contraseña es la
 * mejora natural cuando se implemente HU01.
 */

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idEmpleado = (int) ($_POST['id_empleado'] ?? 0);

    $stmt = $pdo->prepare(
        'SELECT emp.id_empleado, emp.nombres, emp.apellidos, emp.ci,
                emp.id_sucursal, car.nombre AS cargo,
                suc.nombre AS sucursal_nombre, suc.id_ciudad
         FROM empleado emp
         JOIN cargo car ON car.id_cargo = emp.id_cargo
         JOIN sucursal suc ON suc.id_sucursal = emp.id_sucursal
         WHERE emp.id_empleado = ? AND emp.activo = 1'
    );
    $stmt->execute([$idEmpleado]);
    $empleado = $stmt->fetch();

    if (!$empleado) {
        $error = 'Selecciona un empleado válido.';
    } else {
        $_SESSION['empleado'] = $empleado;
        header('Location: index.php');
        exit;
    }
}

$empleados = $pdo->query(
    'SELECT emp.id_empleado, emp.nombres, emp.apellidos, car.nombre AS cargo,
            suc.nombre AS sucursal_nombre
     FROM empleado emp
     JOIN cargo car ON car.id_cargo = emp.id_cargo
     JOIN sucursal suc ON suc.id_sucursal = emp.id_sucursal
     WHERE emp.activo = 1
     ORDER BY car.nombre, emp.nombres'
)->fetchAll();

$base = '.';
$tituloPagina = 'Iniciar sesión';
require __DIR__ . '/includes/header.php';
?>

<div class="card bg-base-100 shadow max-w-md mx-auto">
  <div class="card-body">
    <?php if ($error): ?>
      <div class="alert alert-error mb-2"><span><?= e($error) ?></span></div>
    <?php endif; ?>

    <form method="post" class="space-y-3">
      <label class="form-control">
        <span class="label-text">Empleado</span>
        <select name="id_empleado" class="select select-bordered" required>
          <option value="">-- Selecciona tu usuario --</option>
          <?php foreach ($empleados as $emp): ?>
            <option value="<?= (int) $emp['id_empleado'] ?>">
              <?= e($emp['nombres'] . ' ' . $emp['apellidos']) ?>
              (<?= e($emp['cargo']) ?> · <?= e($emp['sucursal_nombre']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="btn btn-primary w-full">Entrar</button>
    </form>

    <p class="text-xs text-base-content/60 mt-2">
      Sesión simplificada por empleado (sin contraseña) para poder centrar
      este entregable en el flujo de recepción y entrega de encomiendas.
    </p>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
