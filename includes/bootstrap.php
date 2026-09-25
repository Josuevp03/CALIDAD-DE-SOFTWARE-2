<?php
/**
 * Punto de arranque común: sesión, conexión a BD y funciones de negocio.
 * Toda página del sistema debe incluir este archivo primero.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';
