-- =====================================================================
-- SISTEMA DE INFORMACION PARA LA GESTION DE ENCOMIENDAS
-- Empresa: CURRIER LA VELOZ SRL
-- Motor: MySQL 8.x (XAMPP)
-- Normalizacion aplicada hasta 4FN (ver notas junto a cada tabla)
-- =====================================================================

DROP DATABASE IF EXISTS la_veloz_encomiendas;
CREATE DATABASE la_veloz_encomiendas
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE la_veloz_encomiendas;

SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================================
-- CATALOGOS BASE (evitan datos repetidos en texto libre -> 3FN/BCNF)
-- =====================================================================

CREATE TABLE ciudad (
    id_ciudad      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre         VARCHAR(80)  NOT NULL,
    departamento   VARCHAR(80)  NOT NULL,
    UNIQUE KEY uq_ciudad (nombre, departamento)
) ENGINE=InnoDB;

CREATE TABLE sucursal (
    id_sucursal    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_ciudad      INT UNSIGNED NOT NULL,
    nombre         VARCHAR(100) NOT NULL,
    direccion      VARCHAR(150) NOT NULL,
    telefono       VARCHAR(20),
    CONSTRAINT fk_sucursal_ciudad FOREIGN KEY (id_ciudad)
        REFERENCES ciudad(id_ciudad)
) ENGINE=InnoDB;

CREATE TABLE cargo (
    id_cargo       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre         VARCHAR(40) NOT NULL UNIQUE
    -- Recepcionista, Despachante, Cajero, Administrador
) ENGINE=InnoDB;

CREATE TABLE tipo_documento (
    id_tipo_documento INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre            VARCHAR(30) NOT NULL UNIQUE  -- CI, Pasaporte, NIT
) ENGINE=InnoDB;

CREATE TABLE forma_envio (
    id_forma_envio INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre         VARCHAR(20) NOT NULL UNIQUE   -- Terrestre, Aereo
) ENGINE=InnoDB;

CREATE TABLE tipo_encomienda (
    id_tipo_encomienda INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre             VARCHAR(60) NOT NULL UNIQUE, -- Sobres-Documentos, Cajas, Alimentos, Otros
    descripcion        VARCHAR(200)
) ENGINE=InnoDB;

CREATE TABLE producto_prohibido (
    id_producto_prohibido INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre                 VARCHAR(100) NOT NULL,
    descripcion            VARCHAR(255),
    base_legal             VARCHAR(150)  -- referencia a la norma boliviana que lo prohibe
) ENGINE=InnoDB;

CREATE TABLE estado_encomienda (
    id_estado      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre         VARCHAR(30) NOT NULL UNIQUE
    -- Recepcionada, En transito, En oficina destino, Entregada, Rechazada
) ENGINE=InnoDB;

CREATE TABLE metodo_pago (
    id_metodo_pago INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre         VARCHAR(30) NOT NULL UNIQUE  -- Efectivo, QR, Tarjeta
) ENGINE=InnoDB;

-- =====================================================================
-- PERSONAS
-- =====================================================================

CREATE TABLE empleado (
    id_empleado    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ci              VARCHAR(20)  NOT NULL UNIQUE,
    nombres         VARCHAR(80)  NOT NULL,
    apellidos       VARCHAR(80)  NOT NULL,
    id_cargo        INT UNSIGNED NOT NULL,
    id_sucursal     INT UNSIGNED NOT NULL,
    telefono        VARCHAR(20),
    fecha_ingreso   DATE NOT NULL,
    activo          TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT fk_empleado_cargo FOREIGN KEY (id_cargo)
        REFERENCES cargo(id_cargo),
    CONSTRAINT fk_empleado_sucursal FOREIGN KEY (id_sucursal)
        REFERENCES sucursal(id_sucursal)
) ENGINE=InnoDB;

CREATE TABLE cliente (
    id_cliente        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_tipo_documento INT UNSIGNED NOT NULL,
    numero_documento  VARCHAR(20)  NOT NULL,
    nombres           VARCHAR(80)  NOT NULL,
    apellidos         VARCHAR(80)  NOT NULL,
    telefono          VARCHAR(20),
    direccion         VARCHAR(150),
    id_ciudad         INT UNSIGNED NOT NULL,
    UNIQUE KEY uq_cliente_doc (id_tipo_documento, numero_documento),
    CONSTRAINT fk_cliente_tipodoc FOREIGN KEY (id_tipo_documento)
        REFERENCES tipo_documento(id_tipo_documento),
    CONSTRAINT fk_cliente_ciudad FOREIGN KEY (id_ciudad)
        REFERENCES ciudad(id_ciudad)
) ENGINE=InnoDB;

-- =====================================================================
-- TARIFARIO
-- El precio depende en conjunto de tipo de encomienda + forma de envio +
-- ciudad origen + ciudad destino (dependencia total sobre la llave
-- compuesta, no parcial: por eso NO se separa mas). El recargo por
-- volumen queda a criterio del recepcionista y por eso se registra por
-- encomienda, no en la tarifa.
-- =====================================================================

CREATE TABLE tarifa (
    id_tarifa          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_tipo_encomienda INT UNSIGNED NOT NULL,
    id_forma_envio     INT UNSIGNED NOT NULL,
    id_ciudad_origen   INT UNSIGNED NOT NULL,
    id_ciudad_destino  INT UNSIGNED NOT NULL,
    precio_por_kilo    DECIMAL(10,2) NOT NULL,
    vigente_desde      DATE NOT NULL,
    vigente_hasta      DATE NULL,   -- NULL = vigente actualmente
    UNIQUE KEY uq_tarifa (id_tipo_encomienda, id_forma_envio,
                           id_ciudad_origen, id_ciudad_destino, vigente_desde),
    CONSTRAINT fk_tarifa_tipo FOREIGN KEY (id_tipo_encomienda)
        REFERENCES tipo_encomienda(id_tipo_encomienda),
    CONSTRAINT fk_tarifa_forma FOREIGN KEY (id_forma_envio)
        REFERENCES forma_envio(id_forma_envio),
    CONSTRAINT fk_tarifa_origen FOREIGN KEY (id_ciudad_origen)
        REFERENCES ciudad(id_ciudad),
    CONSTRAINT fk_tarifa_destino FOREIGN KEY (id_ciudad_destino)
        REFERENCES ciudad(id_ciudad),
    CONSTRAINT chk_tarifa_ciudades CHECK (id_ciudad_origen <> id_ciudad_destino)
) ENGINE=InnoDB;

-- =====================================================================
-- ENCOMIENDA (recepcion)
-- =====================================================================

CREATE TABLE encomienda (
    id_encomienda       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo_tracking     VARCHAR(20) NOT NULL UNIQUE,
    id_tipo_encomienda  INT UNSIGNED NOT NULL,
    id_forma_envio      INT UNSIGNED NOT NULL,
    id_remitente        INT UNSIGNED NOT NULL,
    id_destinatario     INT UNSIGNED NOT NULL,   -- persona que debe recibir la encomienda
    id_sucursal_origen  INT UNSIGNED NOT NULL,
    id_sucursal_destino INT UNSIGNED NOT NULL,   -- sucursal donde se recoge; la ciudad
                                                  -- destino se obtiene via sucursal->ciudad
                                                  -- (evita redundancia con tarifa)
    id_tarifa           INT UNSIGNED NOT NULL,   -- tarifa vigente aplicada al registrar
    peso_kg             DECIMAL(8,2) NOT NULL,
    volumen_m3          DECIMAL(8,3),
    recargo_volumen     DECIMAL(10,2) NOT NULL DEFAULT 0,
    -- precio_total = (tarifa.precio_por_kilo * peso_kg) + recargo_volumen
    -- se calcula y guarda al registrar (ver trigger mas abajo) para que el
    -- valor cobrado quede fijo aunque la tarifa cambie despues (auditoria).
    precio_total        DECIMAL(10,2) NOT NULL,
    descripcion_contenido VARCHAR(255),
    id_recepcionista    INT UNSIGNED NOT NULL,
    id_estado           INT UNSIGNED NOT NULL,
    fecha_hora_recepcion     DATETIME NOT NULL,
    fecha_hora_salida        DATETIME,
    fecha_hora_llegada_est   DATETIME,
    fecha_hora_llegada_real  DATETIME,
    CONSTRAINT fk_enc_tipo FOREIGN KEY (id_tipo_encomienda)
        REFERENCES tipo_encomienda(id_tipo_encomienda),
    CONSTRAINT fk_enc_forma FOREIGN KEY (id_forma_envio)
        REFERENCES forma_envio(id_forma_envio),
    CONSTRAINT fk_enc_remitente FOREIGN KEY (id_remitente)
        REFERENCES cliente(id_cliente),
    CONSTRAINT fk_enc_destinatario FOREIGN KEY (id_destinatario)
        REFERENCES cliente(id_cliente),
    CONSTRAINT fk_enc_sucursal_origen FOREIGN KEY (id_sucursal_origen)
        REFERENCES sucursal(id_sucursal),
    CONSTRAINT fk_enc_sucursal_destino FOREIGN KEY (id_sucursal_destino)
        REFERENCES sucursal(id_sucursal),
    CONSTRAINT fk_enc_tarifa FOREIGN KEY (id_tarifa)
        REFERENCES tarifa(id_tarifa),
    CONSTRAINT fk_enc_recepcionista FOREIGN KEY (id_recepcionista)
        REFERENCES empleado(id_empleado),
    CONSTRAINT fk_enc_estado FOREIGN KEY (id_estado)
        REFERENCES estado_encomienda(id_estado),
    CONSTRAINT chk_enc_remitente_destinatario CHECK (id_remitente <> id_destinatario),
    CONSTRAINT chk_enc_sucursales CHECK (id_sucursal_origen <> id_sucursal_destino)
) ENGINE=InnoDB;

CREATE INDEX idx_enc_estado ON encomienda(id_estado);
CREATE INDEX idx_enc_destinatario ON encomienda(id_destinatario);
CREATE INDEX idx_enc_recepcionista ON encomienda(id_recepcionista);
CREATE INDEX idx_enc_sucursal_destino ON encomienda(id_sucursal_destino);

-- ---------------------------------------------------------------------
-- Calculo automatico de precio_total = (precio_por_kilo * peso_kg) + recargo_volumen
-- Se fija el valor al registrar/actualizar para conservar el monto
-- cobrado aunque la tarifa cambie despues (el historico de tarifas se
-- mantiene intacto en la tabla "tarifa").
-- ---------------------------------------------------------------------
DELIMITER $$

CREATE TRIGGER trg_encomienda_precio_bi
BEFORE INSERT ON encomienda
FOR EACH ROW
BEGIN
    DECLARE v_precio_kilo DECIMAL(10,2);
    SELECT precio_por_kilo INTO v_precio_kilo
        FROM tarifa WHERE id_tarifa = NEW.id_tarifa;
    SET NEW.precio_total = (v_precio_kilo * NEW.peso_kg) + NEW.recargo_volumen;
END$$

CREATE TRIGGER trg_encomienda_precio_bu
BEFORE UPDATE ON encomienda
FOR EACH ROW
BEGIN
    DECLARE v_precio_kilo DECIMAL(10,2);
    IF NEW.id_tarifa <> OLD.id_tarifa
       OR NEW.peso_kg <> OLD.peso_kg
       OR NEW.recargo_volumen <> OLD.recargo_volumen THEN
        SELECT precio_por_kilo INTO v_precio_kilo
            FROM tarifa WHERE id_tarifa = NEW.id_tarifa;
        SET NEW.precio_total = (v_precio_kilo * NEW.peso_kg) + NEW.recargo_volumen;
    END IF;
END$$

DELIMITER ;

-- Catalogo de contenidos prohibidos detectados en una revision
-- (relacion muchos-a-muchos: una encomienda puede tener 0..N hallazgos).
-- Se agrega quien reviso, cuando y el resultado, para poder saber quien
-- hizo la revision de cada encomienda.
CREATE TABLE encomienda_producto_prohibido (
    id_encomienda          INT UNSIGNED NOT NULL,
    id_producto_prohibido  INT UNSIGNED NOT NULL,
    id_empleado_revision   INT UNSIGNED NOT NULL,
    fecha_hora_revision    DATETIME NOT NULL,
    resultado_revision     ENUM('APROBADA','RECHAZADA','OBSERVADA') NOT NULL,
    observacion            VARCHAR(255),
    PRIMARY KEY (id_encomienda, id_producto_prohibido),
    CONSTRAINT fk_epp_encomienda FOREIGN KEY (id_encomienda)
        REFERENCES encomienda(id_encomienda) ON DELETE CASCADE,
    CONSTRAINT fk_epp_producto FOREIGN KEY (id_producto_prohibido)
        REFERENCES producto_prohibido(id_producto_prohibido),
    CONSTRAINT fk_epp_empleado FOREIGN KEY (id_empleado_revision)
        REFERENCES empleado(id_empleado)
) ENGINE=InnoDB;

-- =====================================================================
-- HISTORIAL DE ESTADOS DE LA ENCOMIENDA
-- "encomienda.id_estado" conserva el estado ACTUAL (lectura rapida sin
-- join); esta tabla conserva cada cambio para trazabilidad completa
-- (que encomienda, a que estado, quien lo cambio y cuando).
-- =====================================================================
CREATE TABLE historial_estado_encomienda (
    id_historial     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_encomienda    INT UNSIGNED NOT NULL,
    id_estado        INT UNSIGNED NOT NULL,
    id_empleado      INT UNSIGNED NOT NULL,
    fecha_hora       DATETIME NOT NULL,
    observacion      VARCHAR(255),
    CONSTRAINT fk_hee_encomienda FOREIGN KEY (id_encomienda)
        REFERENCES encomienda(id_encomienda) ON DELETE CASCADE,
    CONSTRAINT fk_hee_estado FOREIGN KEY (id_estado)
        REFERENCES estado_encomienda(id_estado),
    CONSTRAINT fk_hee_empleado FOREIGN KEY (id_empleado)
        REFERENCES empleado(id_empleado)
) ENGINE=InnoDB;

CREATE INDEX idx_hee_encomienda ON historial_estado_encomienda(id_encomienda, fecha_hora);

-- =====================================================================
-- ENTREGA / RECOJO
-- Es un evento independiente de la recepcion (ocurre despues, en otro
-- momento, y con otro empleado responsable) -> relacion 1:1 opcional,
-- no columnas dentro de "encomienda".
-- =====================================================================

CREATE TABLE entrega (
    id_entrega          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_encomienda        INT UNSIGNED NOT NULL UNIQUE,
    id_despachante        INT UNSIGNED NOT NULL,
    id_receptor_presento  INT UNSIGNED NOT NULL,  -- cliente que retira (normalmente el receptor)
    id_tipo_documento_presentado INT UNSIGNED NOT NULL,
    numero_documento_presentado  VARCHAR(20) NOT NULL,
    fecha_hora_recojo     DATETIME NOT NULL,
    observaciones          VARCHAR(255),
    CONSTRAINT fk_entrega_encomienda FOREIGN KEY (id_encomienda)
        REFERENCES encomienda(id_encomienda),
    CONSTRAINT fk_entrega_despachante FOREIGN KEY (id_despachante)
        REFERENCES empleado(id_empleado),
    CONSTRAINT fk_entrega_receptor FOREIGN KEY (id_receptor_presento)
        REFERENCES cliente(id_cliente),
    CONSTRAINT fk_entrega_tipodoc FOREIGN KEY (id_tipo_documento_presentado)
        REFERENCES tipo_documento(id_tipo_documento)
) ENGINE=InnoDB;

-- =====================================================================
-- PAGO
-- Evento independiente (se cobra en caja, puede ocurrir antes o despues
-- del recojo) -> tabla propia en vez de columnas en "encomienda".
-- =====================================================================

CREATE TABLE pago (
    id_pago         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_encomienda    INT UNSIGNED NOT NULL,
    id_cajera        INT UNSIGNED NOT NULL,
    id_metodo_pago   INT UNSIGNED NOT NULL,
    monto            DECIMAL(10,2) NOT NULL,
    numero_recibo    VARCHAR(20) NOT NULL UNIQUE,
    fecha_hora_pago  DATETIME NOT NULL,
    CONSTRAINT fk_pago_encomienda FOREIGN KEY (id_encomienda)
        REFERENCES encomienda(id_encomienda),
    CONSTRAINT fk_pago_cajera FOREIGN KEY (id_cajera)
        REFERENCES empleado(id_empleado),
    CONSTRAINT fk_pago_metodo FOREIGN KEY (id_metodo_pago)
        REFERENCES metodo_pago(id_metodo_pago)
) ENGINE=InnoDB;

CREATE INDEX idx_pago_encomienda ON pago(id_encomienda);

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- DATOS BASE (catalogos) tomados del caso de estudio
-- =====================================================================

INSERT INTO cargo (nombre) VALUES
    ('Recepcionista'), ('Despachante'), ('Cajero'), ('Administrador');

INSERT INTO tipo_documento (nombre) VALUES
    ('Carnet de Identidad'), ('Pasaporte'), ('NIT');

INSERT INTO forma_envio (nombre) VALUES ('Terrestre'), ('Aereo');

INSERT INTO tipo_encomienda (nombre, descripcion) VALUES
    ('Sobres-Documentos', 'Sobres y documentos'),
    ('Cajas', 'Cajas de distinto contenido'),
    ('Alimentos', 'Productos alimenticios permitidos'),
    ('Otros', 'Otros tipos de encomienda');

INSERT INTO estado_encomienda (nombre) VALUES
    ('Recepcionada'), ('En transito'), ('En oficina destino'),
    ('Entregada'), ('Rechazada');

INSERT INTO metodo_pago (nombre) VALUES
    ('Efectivo'), ('QR'), ('Tarjeta');

INSERT INTO ciudad (nombre, departamento) VALUES
    ('Santa Cruz de la Sierra', 'Santa Cruz'),
    ('La Paz', 'La Paz'),
    ('Cochabamba', 'Cochabamba'),
    ('Sucre', 'Chuquisaca'),
    ('Tarija', 'Tarija'),
    ('Oruro', 'Oruro'),
    ('Potosi', 'Potosi'),
    ('Trinidad', 'Beni'),
    ('Cobija', 'Pando');

-- Tarifa de ejemplo (Santa Cruz -> La Paz, terrestre), segun tabla del caso
INSERT INTO tarifa (id_tipo_encomienda, id_forma_envio, id_ciudad_origen,
                     id_ciudad_destino, precio_por_kilo, vigente_desde)
SELECT te.id_tipo_encomienda, fe.id_forma_envio, co.id_ciudad, cd.id_ciudad,
       v.precio, '2026-01-01'
FROM (SELECT 'Sobres-Documentos' n, 50.00 precio
      UNION ALL SELECT 'Alimentos', 2.00
      UNION ALL SELECT 'Otros', 3.00) v
JOIN tipo_encomienda te ON te.nombre = v.n
JOIN forma_envio fe ON fe.nombre = 'Terrestre'
JOIN ciudad co ON co.nombre = 'Santa Cruz de la Sierra'
JOIN ciudad cd ON cd.nombre = 'La Paz';

-- =====================================================================
-- DATOS DE PRUEBA para poder usar la aplicacion web de inmediato
-- (sucursales, empleados y productos prohibidos de ejemplo).
-- =====================================================================

INSERT INTO sucursal (id_ciudad, nombre, direccion, telefono)
SELECT id_ciudad, v.nombre, v.direccion, v.telefono
FROM (SELECT 'Santa Cruz de la Sierra' ciudad, 'Sucursal Santa Cruz Central' nombre,
             'Av. Cristo Redentor #123' direccion, '3-3000001' telefono
      UNION ALL SELECT 'La Paz', 'Sucursal La Paz Central',
             'Av. 16 de Julio #456', '2-2000002'
      UNION ALL SELECT 'Cochabamba', 'Sucursal Cochabamba Central',
             'Av. Heroínas #789', '4-4000003') v
JOIN ciudad ON ciudad.nombre = v.ciudad;

-- Un empleado por cargo, distribuido en Santa Cruz (origen) y La Paz
-- (destino), para poder probar el flujo completo salida -> llegada -> entrega.
INSERT INTO empleado (ci, nombres, apellidos, id_cargo, id_sucursal, telefono, fecha_ingreso)
SELECT v.ci, v.nombres, v.apellidos, car.id_cargo, suc.id_sucursal, v.telefono, v.fecha_ingreso
FROM (SELECT '1000001' ci, 'Roxana' nombres, 'Fernández' apellidos, 'Administrador' cargo,
             'Sucursal Santa Cruz Central' sucursal, '70000001' telefono, '2025-01-06' fecha_ingreso
      UNION ALL SELECT '1000002', 'Juan', 'Pérez', 'Recepcionista',
             'Sucursal Santa Cruz Central', '70000002', '2025-02-03'
      UNION ALL SELECT '1000003', 'Marisol', 'Rivera', 'Cajero',
             'Sucursal Santa Cruz Central', '70000003', '2025-02-03'
      UNION ALL SELECT '1000004', 'Marcela', 'Rojas', 'Recepcionista',
             'Sucursal La Paz Central', '70000004', '2025-03-10'
      UNION ALL SELECT '1000005', 'Pedro', 'Gómez', 'Despachante',
             'Sucursal La Paz Central', '70000005', '2025-03-10') v
JOIN cargo car ON car.nombre = v.cargo
JOIN sucursal suc ON suc.nombre = v.sucursal;

INSERT INTO producto_prohibido (nombre, descripcion, base_legal) VALUES
    ('Armas de fuego y municiones', 'Armas, réplicas y municiones de cualquier calibre',
     'Ley de Control de Armas de Fuego, Municiones, Explosivos y Otros Materiales Relacionados'),
    ('Explosivos y pirotecnia', 'Explosivos, pólvora y artículos pirotécnicos',
     'Normativa boliviana de control de explosivos'),
    ('Sustancias controladas', 'Estupefacientes y sustancias psicotrópicas',
     'Ley 1008 - Régimen de la Coca y Sustancias Controladas'),
    ('Animales vivos', 'Fauna viva de cualquier especie',
     'Normativa de sanidad agropecuaria e inocuidad alimentaria (SENASAG)'),
    ('Dinero en efectivo y valores', 'Moneda nacional o extranjera, cheques y títulos valor',
     'Política interna de la empresa por riesgo de pérdida y normativa de transporte de valores');
