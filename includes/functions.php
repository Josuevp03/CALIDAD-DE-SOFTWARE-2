<?php
/**
 * Funciones de apoyo para la lógica de negocio de La Veloz SRL.
 * Se asume que $pdo (config/db.php) ya fue cargado antes de incluir este
 * archivo.
 */

/** Escapa texto para salida segura en HTML. */
function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/** Empleado actualmente "logueado" (sesión simplificada, ver login.php). */
function empleadoActual(): ?array
{
    return $_SESSION['empleado'] ?? null;
}

/** Exige que haya un empleado en sesión; si no, redirige a login. */
function requerirSesion(): array
{
    $emp = empleadoActual();
    if (!$emp) {
        header('Location: ../login.php');
        exit;
    }
    return $emp;
}

/** Exige que haya un empleado en sesión con alguno de los cargos dados. */
function requerirCargo(string ...$cargos): array
{
    $emp = requerirSesion();
    if (!in_array($emp['cargo'], $cargos, true)) {
        http_response_code(403);
        echo '<div class="alert alert-error max-w-xl mx-auto mt-10">'
            . 'No tienes permisos para acceder a esta sección. Se requiere el '
            . 'cargo: ' . e(implode(' o ', $cargos)) . '.</div>';
        exit;
    }
    return $emp;
}

/**
 * Genera un código de seguimiento único (ej. LV260925A1B2C).
 * Reintenta hasta encontrar uno que no exista todavía en "encomienda".
 */
function generarCodigoTracking(PDO $pdo): string
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM encomienda WHERE codigo_tracking = ?');
    do {
        $codigo = 'LV' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        $stmt->execute([$codigo]);
    } while ((int) $stmt->fetchColumn() > 0);

    return $codigo;
}

/** Genera un número de recibo único para un pago. */
function generarNumeroRecibo(PDO $pdo): string
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM pago WHERE numero_recibo = ?');
    do {
        $numero = 'REC' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        $stmt->execute([$numero]);
    } while ((int) $stmt->fetchColumn() > 0);

    return $numero;
}

/**
 * Busca un cliente por tipo+número de documento; si no existe lo crea.
 * Si existe, actualiza sus datos de contacto (pueden haber cambiado).
 * Devuelve el id_cliente.
 */
function buscarOCrearCliente(PDO $pdo, array $d): int
{
    $stmt = $pdo->prepare(
        'SELECT id_cliente FROM cliente WHERE id_tipo_documento = ? AND numero_documento = ?'
    );
    $stmt->execute([$d['id_tipo_documento'], $d['numero_documento']]);
    $row = $stmt->fetch();

    if ($row) {
        $upd = $pdo->prepare(
            'UPDATE cliente SET nombres = ?, apellidos = ?, telefono = ?, '
            . 'direccion = ?, id_ciudad = ? WHERE id_cliente = ?'
        );
        $upd->execute([
            $d['nombres'], $d['apellidos'], $d['telefono'],
            $d['direccion'], $d['id_ciudad'], $row['id_cliente'],
        ]);
        return (int) $row['id_cliente'];
    }

    $ins = $pdo->prepare(
        'INSERT INTO cliente (id_tipo_documento, numero_documento, nombres, '
        . 'apellidos, telefono, direccion, id_ciudad) VALUES (?,?,?,?,?,?,?)'
    );
    $ins->execute([
        $d['id_tipo_documento'], $d['numero_documento'], $d['nombres'],
        $d['apellidos'], $d['telefono'], $d['direccion'], $d['id_ciudad'],
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Busca la tarifa vigente hoy para una combinación de tipo de encomienda,
 * forma de envío, ciudad origen y ciudad destino.
 * RN02: el precio depende de modalidad, origen, destino, tipo y peso.
 */
function obtenerTarifaVigente(
    PDO $pdo,
    int $idTipoEncomienda,
    int $idFormaEnvio,
    int $idCiudadOrigen,
    int $idCiudadDestino
): ?array {
    $sql = 'SELECT * FROM tarifa
            WHERE id_tipo_encomienda = ? AND id_forma_envio = ?
              AND id_ciudad_origen = ? AND id_ciudad_destino = ?
              AND vigente_desde <= CURDATE()
              AND (vigente_hasta IS NULL OR vigente_hasta >= CURDATE())
            ORDER BY vigente_desde DESC
            LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$idTipoEncomienda, $idFormaEnvio, $idCiudadOrigen, $idCiudadDestino]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/** Devuelve el id_estado a partir del nombre del catálogo estado_encomienda. */
function idEstadoPorNombre(PDO $pdo, string $nombre): int
{
    $stmt = $pdo->prepare('SELECT id_estado FROM estado_encomienda WHERE nombre = ?');
    $stmt->execute([$nombre]);
    $v = $stmt->fetchColumn();
    if ($v === false) {
        throw new RuntimeException("El estado \"$nombre\" no existe en el catálogo estado_encomienda.");
    }
    return (int) $v;
}

/**
 * Cambia el estado actual de una encomienda y deja constancia en el
 * historial (RN09, RN10: toda operación relevante debe registrar fecha,
 * hora y responsable).
 */
function registrarCambioEstado(
    PDO $pdo,
    int $idEncomienda,
    int $idEstado,
    int $idEmpleado,
    ?string $observacion = null
): void {
    $upd = $pdo->prepare('UPDATE encomienda SET id_estado = ? WHERE id_encomienda = ?');
    $upd->execute([$idEstado, $idEncomienda]);

    $ins = $pdo->prepare(
        'INSERT INTO historial_estado_encomienda '
        . '(id_encomienda, id_estado, id_empleado, fecha_hora, observacion) '
        . 'VALUES (?, ?, ?, NOW(), ?)'
    );
    $ins->execute([$idEncomienda, $idEstado, $idEmpleado, $observacion]);
}

/** Carga una encomienda completa (con nombres legibles) por su código de tracking. */
function buscarEncomiendaPorCodigo(PDO $pdo, string $codigo): ?array
{
    $sql = 'SELECT en.*,
                   te.nombre  AS tipo_encomienda,
                   fe.nombre  AS forma_envio,
                   es.nombre  AS estado,
                   rem.nombres AS remitente_nombres, rem.apellidos AS remitente_apellidos,
                   des.nombres AS destinatario_nombres, des.apellidos AS destinatario_apellidos,
                   des.id_tipo_documento AS destinatario_tipo_doc_id,
                   des.numero_documento  AS destinatario_numero_doc,
                   so.nombre AS sucursal_origen_nombre, co.nombre AS ciudad_origen_nombre,
                   sd.nombre AS sucursal_destino_nombre, cd.nombre AS ciudad_destino_nombre,
                   emp.nombres AS recepcionista_nombres, emp.apellidos AS recepcionista_apellidos
            FROM encomienda en
            JOIN tipo_encomienda te ON te.id_tipo_encomienda = en.id_tipo_encomienda
            JOIN forma_envio fe ON fe.id_forma_envio = en.id_forma_envio
            JOIN estado_encomienda es ON es.id_estado = en.id_estado
            JOIN cliente rem ON rem.id_cliente = en.id_remitente
            JOIN cliente des ON des.id_cliente = en.id_destinatario
            JOIN sucursal so ON so.id_sucursal = en.id_sucursal_origen
            JOIN ciudad co ON co.id_ciudad = so.id_ciudad
            JOIN sucursal sd ON sd.id_sucursal = en.id_sucursal_destino
            JOIN ciudad cd ON cd.id_ciudad = sd.id_ciudad
            JOIN empleado emp ON emp.id_empleado = en.id_recepcionista
            WHERE en.codigo_tracking = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$codigo]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/** Historial de estados de una encomienda, del más antiguo al más reciente. */
function historialDeEncomienda(PDO $pdo, int $idEncomienda): array
{
    $sql = 'SELECT h.*, es.nombre AS estado, emp.nombres, emp.apellidos, emp.ci
            FROM historial_estado_encomienda h
            JOIN estado_encomienda es ON es.id_estado = h.id_estado
            JOIN empleado emp ON emp.id_empleado = h.id_empleado
            WHERE h.id_encomienda = ?
            ORDER BY h.fecha_hora ASC, h.id_historial ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$idEncomienda]);

    return $stmt->fetchAll();
}

/** Da formato de moneda boliviana simple. */
function bs($monto): string
{
    return 'Bs ' . number_format((float) $monto, 2);
}
