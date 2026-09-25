<?php
/**
 * Layout superior compartido.
 * Antes de incluir este archivo, la página debe definir:
 *   $base          -> '.' si la página está en la raíz, '..' si está en /encomiendas
 *   $tituloPagina   -> (opcional) título específico de la página
 */
$base = $base ?? '.';
$emp  = empleadoActual();
?>
<!DOCTYPE html>
<html lang="es" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= isset($tituloPagina) ? e($tituloPagina) . ' · ' : '' ?>La Veloz SRL</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://cdn.jsdelivr.net/npm/daisyui@4.12.10/dist/full.min.css" rel="stylesheet" type="text/css" />
</head>
<body class="min-h-screen bg-base-200">

<div class="navbar bg-primary text-primary-content shadow-md px-4">
  <div class="flex-1">
    <a href="<?= e($base) ?>/index.php" class="btn btn-ghost text-lg normal-case">📦 La Veloz SRL</a>
  </div>
  <?php if ($emp): ?>
    <div class="flex-none gap-3">
      <span class="text-sm hidden sm:inline">
        <?= e($emp['nombres'] . ' ' . $emp['apellidos']) ?>
        <span class="badge badge-secondary badge-sm ml-1"><?= e($emp['cargo']) ?></span>
      </span>
      <a href="<?= e($base) ?>/encomiendas/seguimiento.php" class="btn btn-sm btn-ghost">Seguimiento</a>
      <a href="<?= e($base) ?>/logout.php" class="btn btn-sm btn-outline btn-secondary">Salir</a>
    </div>
  <?php else: ?>
    <div class="flex-none gap-2">
      <a href="<?= e($base) ?>/encomiendas/seguimiento.php" class="btn btn-sm btn-ghost">Seguimiento</a>
      <a href="<?= e($base) ?>/login.php" class="btn btn-sm btn-outline btn-secondary">Iniciar sesión</a>
    </div>
  <?php endif; ?>
</div>

<main class="container mx-auto max-w-4xl p-4">
<?php if (isset($tituloPagina)): ?>
  <h1 class="text-2xl font-bold mb-4"><?= e($tituloPagina) ?></h1>
<?php endif; ?>
