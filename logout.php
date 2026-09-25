<?php
require_once __DIR__ . '/includes/bootstrap.php';
unset($_SESSION['empleado']);
session_destroy();
header('Location: login.php');
exit;
