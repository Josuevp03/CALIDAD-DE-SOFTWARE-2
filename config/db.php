<?php
/**
 * Conexión PDO a la base de datos la_veloz_encomiendas (XAMPP / MySQL).
 * Ajusta $user / $pass si tu instalación de XAMPP tiene contraseña de root.
 */

$host    = '127.0.0.1';
$db      = 'la_veloz_encomiendas';
$user    = 'root';
$pass    = '';
$charset = 'utf8mb4';

$dsn = "mysql:host={$host};dbname={$db};charset={$charset}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    http_response_code(500);
    die('No se pudo conectar a la base de datos. Verifica que MySQL esté '
        . 'corriendo en XAMPP y que la base "la_veloz_encomiendas" exista. '
        . 'Detalle: ' . htmlspecialchars($e->getMessage()));
}
