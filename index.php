<?php
require_once __DIR__ . '/includes/bootstrap.php';
$emp = empleadoActual();

$base = '.';
$tituloPagina = 'Panel principal';
require __DIR__ . '/includes/header.php';
?>

<?php if (!$emp): ?>
  <div class="hero bg-base-100 rounded-box shadow p-10">
    <div class="hero-content text-center">
      <div class="max-w-md">
        <h2 class="text-xl font-bold mb-2">Bienvenido</h2>
        <p class="mb-4">Inicia sesión con tu usuario de empleado para registrar
          y gestionar encomiendas, o consulta el estado de un envío sin
          necesidad de iniciar sesión.</p>
        <div class="flex gap-2 justify-center">
          <a href="login.php" class="btn btn-primary">Iniciar sesión</a>
          <a href="encomiendas/seguimiento.php" class="btn btn-outline">Seguimiento de encomienda</a>
        </div>
      </div>
    </div>
  </div>
<?php else: ?>

  <div class="grid gap-4 sm:grid-cols-2">

    <?php if (in_array($emp['cargo'], ['Recepcionista', 'Administrador'], true)): ?>
      <a href="encomiendas/registrar.php" class="card bg-base-100 shadow hover:shadow-lg transition">
        <div class="card-body">
          <h2 class="card-title">📥 Registrar encomienda</h2>
          <p>Recepción de una nueva encomienda: remitente, destinatario, tarifa y precio.</p>
        </div>
      </a>
      <a href="encomiendas/salida.php" class="card bg-base-100 shadow hover:shadow-lg transition">
        <div class="card-body">
          <h2 class="card-title">🚚 Registrar salida</h2>
          <p>Marca como "En tránsito" las encomiendas recepcionadas en tu sucursal.</p>
        </div>
      </a>
      <a href="encomiendas/llegada.php" class="card bg-base-100 shadow hover:shadow-lg transition">
        <div class="card-body">
          <h2 class="card-title">🏢 Registrar llegada</h2>
          <p>Marca como "En oficina destino" las encomiendas que llegaron a tu sucursal.</p>
        </div>
      </a>
    <?php endif; ?>

    <?php if (in_array($emp['cargo'], ['Cajero', 'Administrador'], true)): ?>
      <a href="encomiendas/pago.php" class="card bg-base-100 shadow hover:shadow-lg transition">
        <div class="card-body">
          <h2 class="card-title">💵 Registrar pago</h2>
          <p>Cobra el servicio de una encomienda por su código de seguimiento.</p>
        </div>
      </a>
    <?php endif; ?>

    <?php if (in_array($emp['cargo'], ['Despachante', 'Administrador'], true)): ?>
      <a href="encomiendas/pendientes.php" class="card bg-base-100 shadow hover:shadow-lg transition">
        <div class="card-body">
          <h2 class="card-title">📋 Pendientes de entrega</h2>
          <p>Encomiendas ya en tu sucursal, listas para ser recogidas por el destinatario.</p>
        </div>
      </a>
      <a href="encomiendas/entrega.php" class="card bg-base-100 shadow hover:shadow-lg transition">
        <div class="card-body">
          <h2 class="card-title">✅ Registrar entrega</h2>
          <p>Verifica la identidad del destinatario y entrega la encomienda.</p>
        </div>
      </a>
    <?php endif; ?>

    <a href="encomiendas/seguimiento.php" class="card bg-base-100 shadow hover:shadow-lg transition">
      <div class="card-body">
        <h2 class="card-title">🔎 Seguimiento</h2>
        <p>Consulta el estado actual e historial de cualquier encomienda por su código.</p>
      </div>
    </a>

  </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
