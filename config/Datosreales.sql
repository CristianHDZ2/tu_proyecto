-- =====================================================
-- SISTEMA DE INVENTARIO - BASE DE DATOS V2.0
-- Incluye gestión de precio de compra por producto
-- =====================================================

-- Crear base de datos
CREATE DATABASE IF NOT EXISTS inventario_system;
USE inventario_system;

-- =====================================================
-- TABLA: productos
-- Agregado: precio_compra y margen de ganancia
-- =====================================================
CREATE TABLE productos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    proveedor VARCHAR(255) NOT NULL,
    descripcion TEXT NOT NULL,
    precio_compra DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Último precio de compra registrado',
    precio_venta DECIMAL(10,2) NOT NULL,
    existencia INT DEFAULT 0,
    margen_ganancia DECIMAL(5,2) AS (
        CASE 
            WHEN precio_compra > 0 THEN ((precio_venta - precio_compra) / precio_compra * 100)
            ELSE 0
        END
    ) STORED COMMENT 'Margen de ganancia en porcentaje (calculado automáticamente)',
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_proveedor (proveedor),
    INDEX idx_existencia (existencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLA: ingresos
-- Sin cambios
-- =====================================================
CREATE TABLE ingresos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    proveedor VARCHAR(255) NOT NULL,
    numero_factura VARCHAR(100) NOT NULL,
    fecha_ingreso DATE NOT NULL,
    total_factura DECIMAL(10,2) DEFAULT 0,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_numero_factura (numero_factura),
    INDEX idx_fecha_ingreso (fecha_ingreso),
    INDEX idx_proveedor (proveedor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLA: detalle_ingresos
-- Sin cambios - ya tiene precio_compra
-- =====================================================
CREATE TABLE detalle_ingresos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ingreso_id INT NOT NULL,
    producto_id INT NOT NULL,
    cantidad INT NOT NULL,
    precio_compra DECIMAL(10,2) NOT NULL COMMENT 'Precio de compra en este ingreso específico',
    subtotal DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (ingreso_id) REFERENCES ingresos(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
    INDEX idx_ingreso_id (ingreso_id),
    INDEX idx_producto_id (producto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLA: distribuciones
-- Sin cambios
-- =====================================================
CREATE TABLE distribuciones (
    id INT PRIMARY KEY AUTO_INCREMENT,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    dias_exclusion TEXT COMMENT 'JSON con días excluidos',
    tipo_distribucion ENUM('completo', 'parcial') NOT NULL,
    productos_seleccionados TEXT COMMENT 'JSON con productos y cantidades si es parcial',
    estado ENUM('activo', 'eliminado') DEFAULT 'activo',
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_estado (estado),
    INDEX idx_fecha_inicio (fecha_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLA: tablas_distribucion
-- Sin cambios
-- =====================================================
CREATE TABLE tablas_distribucion (
    id INT PRIMARY KEY AUTO_INCREMENT,
    distribucion_id INT NOT NULL,
    fecha_tabla DATE NOT NULL,
    numero_tabla INT NOT NULL,
    total_tabla DECIMAL(10,2) DEFAULT 0,
    estado ENUM('activo', 'eliminado') DEFAULT 'activo',
    FOREIGN KEY (distribucion_id) REFERENCES distribuciones(id) ON DELETE CASCADE,
    INDEX idx_distribucion_id (distribucion_id),
    INDEX idx_fecha_tabla (fecha_tabla),
    INDEX idx_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLA: detalle_tablas_distribucion
-- Sin cambios
-- =====================================================
CREATE TABLE detalle_tablas_distribucion (
    id INT PRIMARY KEY AUTO_INCREMENT,
    tabla_id INT NOT NULL,
    producto_id INT NOT NULL,
    cantidad INT NOT NULL,
    precio_venta DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (tabla_id) REFERENCES tablas_distribucion(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
    INDEX idx_tabla_id (tabla_id),
    INDEX idx_producto_id (producto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLA NUEVA: historial_precios_compra
-- Guarda el historial de cambios de precios de compra
-- =====================================================
CREATE TABLE historial_precios_compra (
    id INT PRIMARY KEY AUTO_INCREMENT,
    producto_id INT NOT NULL,
    precio_compra_anterior DECIMAL(10,2) DEFAULT 0.00,
    precio_compra_nuevo DECIMAL(10,2) NOT NULL,
    ingreso_id INT NULL COMMENT 'Si el cambio fue por un ingreso, se registra el ID',
    motivo VARCHAR(255) DEFAULT 'Ingreso de mercancía',
    usuario VARCHAR(100) DEFAULT 'Sistema',
    fecha_cambio TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
    FOREIGN KEY (ingreso_id) REFERENCES ingresos(id) ON DELETE SET NULL,
    INDEX idx_producto_id (producto_id),
    INDEX idx_fecha_cambio (fecha_cambio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TRIGGER: Actualizar precio_compra del producto
-- Se activa al insertar un nuevo detalle de ingreso
-- =====================================================
DELIMITER $$

CREATE TRIGGER actualizar_precio_compra_producto
AFTER INSERT ON detalle_ingresos
FOR EACH ROW
BEGIN
    DECLARE precio_anterior DECIMAL(10,2);
    
    -- Obtener el precio de compra actual del producto
    SELECT precio_compra INTO precio_anterior
    FROM productos
    WHERE id = NEW.producto_id;
    
    -- Actualizar el precio de compra del producto con el nuevo precio
    UPDATE productos
    SET precio_compra = NEW.precio_compra
    WHERE id = NEW.producto_id;
    
    -- Registrar en el historial de precios
    INSERT INTO historial_precios_compra (
        producto_id,
        precio_compra_anterior,
        precio_compra_nuevo,
        ingreso_id,
        motivo
    ) VALUES (
        NEW.producto_id,
        precio_anterior,
        NEW.precio_compra,
        NEW.ingreso_id,
        'Actualización automática por ingreso de mercancía'
    );
END$$

DELIMITER ;

-- =====================================================
-- LIMPIAR DATOS EXISTENTES (SOLO PARA DESARROLLO)
-- =====================================================
SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM historial_precios_compra;
DELETE FROM detalle_tablas_distribucion;
DELETE FROM tablas_distribucion;
DELETE FROM distribuciones;
DELETE FROM detalle_ingresos;
DELETE FROM ingresos;
DELETE FROM productos;

ALTER TABLE historial_precios_compra AUTO_INCREMENT = 1;
ALTER TABLE detalle_tablas_distribucion AUTO_INCREMENT = 1;
ALTER TABLE tablas_distribucion AUTO_INCREMENT = 1;
ALTER TABLE distribuciones AUTO_INCREMENT = 1;
ALTER TABLE detalle_ingresos AUTO_INCREMENT = 1;
ALTER TABLE ingresos AUTO_INCREMENT = 1;
ALTER TABLE productos AUTO_INCREMENT = 1;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================
-- INSERTAR PRODUCTOS - PARTE 1 (PRECIOS ACTUALIZADOS)
-- =====================================================
INSERT INTO productos (proveedor, descripcion, precio_venta, precio_compra) VALUES
('Pepsi', 'AMP Energy 600ml (12 Pack)', 10.00, 8.95),
('Pepsi', 'Agua "Aqua" 750ml (12 Pack)', 4.00, 3.54),
('Industrias Romero', 'Agua "Caída del Cielo" (Fardo)', 0.90, 0.58),
('Grupo AJE', 'Agua "Cielo" 375ml (24 Pack)', 4.00, 0.00),
('Grupo AJE', 'Agua "Cielo" 625ml (20 Pack)', 4.50, 3.98),
('Grupo AJE', 'Agua "Cielo" 1L (8 Pack)', 4.65, 4.12),
('Industrias Romero', 'Agua "De Los Ángeles" Garrafa 18.5L (Unidad)', 2.00, 1.24),
('Diszasa', 'Baygon Oko Mosquitos Y Moscas 400ml (Unidad)', 2.25, 0.00),
('Diszasa', 'Baygon Poder Mortal 400ml (Unidad)', 2.25, 0.00),
('Grupo AJE', 'Big Cola 250ml (24 Pack)', 5.00, 4.42),
('Grupo AJE', 'Big Lima Limón 250ml (24 Pack)', 5.00, 4.42),
('Grupo AJE', 'Big Naranja 250ml (24 Pack)', 5.00, 0.00),
('Grupo AJE', 'Big Cola 300ml (24 Pack)', 5.00, 0.00),
('Grupo AJE', 'Big Cola 360ml (24 Pack)', 6.00, 5.31),
('Grupo AJE', 'Big Cola 625ml (12 Pack)', 4.55, 4.04),
('Grupo AJE', 'Big Cola 1L (12 Pack)', 6.00, 0.00),
('Grupo AJE', 'Big Cola 1.3L (8 Pack)', 5.00, 4.42),
('Grupo AJE', 'Big Cola 1.8L (6 Pack)', 5.50, 0.00),
('Grupo AJE', 'Big Cola 2.6L (6 Pack)', 6.50, 5.75),
('Grupo AJE', 'Big Cola 3.03L (6 Pack)', 8.75, 7.74),
('Grupo AJE', 'Big Lima Limón 360ml (12 Pack)', 3.00, 2.65),
('Grupo AJE', 'Big Lima Limón 3.03L (6 Pack)', 8.75, 7.74),
('Grupo AJE', 'Big Lima Limón 250ml (24 Pack)', 5.00, 4.42),
('Grupo AJE', 'Big Naranja 360ml (12 Pack)', 3.00, 2.65),
('Grupo AJE', 'Big Naranja 3.03L (6 Pack)', 8.75, 7.74),
('Grupo AJE', 'Big Roja 360ml (12 Pack)', 3.00, 0.00),
('Grupo AJE', 'Big Uva 250ml (24 Pack)', 5.00, 0.00),
('Grupo AJE', 'Big Uva 360ml (12 Pack)', 3.00, 2.65),
('Grupo AJE', 'Big Uva 3.03L (6 Pack)', 8.75, 7.74),
('Grupo AJE', 'Bio Aloe "Aloe y Uva" 360ml (6 Pack)', 3.75, 0.00),
('Diszasa', 'Café Instantáneo Aroma Caja (50 Sobres)', 3.00, 0.00),
('Diszasa', 'Café Instantáneo Coscafe Caja (40 sobres)', 2.85, 0.00),
('Diszasa', 'Café Instantáneo Coscafe Caja (50+5 Sobres)', 3.95, 0.00),
('Grupo AJE', 'Cifrut Citrus Punch 1.3L (8 Pack)', 5.00, 4.42),
('Grupo AJE', 'Cifrut Citrus Punch Naranja 250ml (24 Pack)', 5.00, 4.42),
('Grupo AJE', 'Cifrut Citrus Punch Naranja 360ml (12 Pack)', 3.00, 2.65),
('Grupo AJE', 'Cifrut Citrus Punch Naranja 625ml (15 Pack)', 5.70, 0.00),
('Grupo AJE', 'Cifrut Citrus Punch Naranja 2.6L (6 Pack)', 6.50, 0.00),
('Grupo AJE', 'Cifrut Citrus Punch Naranja 3.03L (6 Pack)', 8.75, 6.64),
('Grupo AJE', 'Cifrut Fruit Punch Rojo 250ml (24 Pack)', 5.00, 4.42),
('Grupo AJE', 'Cifrut Fruit Punch Rojo 360ml (12 Pack)', 3.00, 2.65),
('Grupo AJE', 'Cifrut Fruit Punch Rojo 1.3L (8 Pack)', 5.00, 4.42),
('Grupo AJE', 'Cifrut Fruit Punch Rojo 3.03L (6 Pack)', 8.75, 6.64),
('La Constancia', 'Coca-Cola Vidrio 354ml (24 Pack)', 10.25, 8.85),
('La Constancia', 'Coca-Cola 1.25L pet (12 Pack)', 13.50, 11.04),
('La Constancia', 'Coca-Cola 2.5L pet (6 Pack)', 11.55, 9.73),
('La Constancia', 'Coca-Cola 3L pet (4 Pack)', 8.50, 7.56),
('La Constancia', 'Coca-Cola lata 354ml (24 Pack)', 14.45, 13.01),
('La Constancia', 'Coca-Cola Vidrio 1L (12 Pack)', 10.25, 0.00),
('La Constancia', 'Coca-Cola Vidrio 1.25L (12 Pack)', 10.25, 0.00),
('La Constancia', 'Del Valle Mandarina 500ml pet (12 Pack)', 5.75, 4.86),
('La Constancia', 'Del Valle Mandarina 1.5L pet (6 Pack)', 5.85, 4.96),
('La Constancia', 'Del Valle Mandarina 2.5L pet (6 Pack)', 8.55, 7.35);

-- =====================================================
-- INSERTAR PRODUCTOS - PARTE 2 (PRECIOS ACTUALIZADOS)
-- =====================================================
INSERT INTO productos (proveedor, descripcion, precio_venta, precio_compra) VALUES
('La Constancia', 'Energy Fury 500ml pet (12 Pack)', 11.25, 9.51),
('La Constancia', 'Fanta Naranja 1.25L pet (12pack)', 13.50, 0.00),
('La Constancia', 'Fanta Naranja 2.5L pet (6 Pack)', 11.55, 10.13),
('La Constancia', 'Fanta Naranja Lata 354ml (12 Pack)', 7.22, 0.00),
('La Constancia', 'Fanta Vidrio 354ml (24 Pack)', 10.25, 8.85),
('Pepsi', 'Gatorade Celeste 600ml (24 Pack)', 22.10, 19.56),
('Pepsi', 'Gatorade Limón 600ml (24 Pack)', 22.10, 19.56),
('Pepsi', 'Gatorade Naranja 600ml (24 Pack)', 22.10, 19.56),
('Pepsi', 'Gatorade Rojo 600ml (24 Pack)', 22.10, 19.56),
('Pepsi', 'Gatorade Uva 600ml (24 Pack)', 22.10, 19.56),
('Grupo AJE', 'Kola Real K.R 300ml (12 Pack)', 2.50, 0.00),
('Grupo AJE', 'Kola Real K.R Naranja 300ml (12 Pack)', 5.00, 2.50),
('Pepsi', 'Néctar California de Durazno 330ml (24 Pack)', 10.00, 0.00),
('Pepsi', 'Néctar California de Pera 330ml (24 Pack)', 10.00, 8.85),
('Pepsi', 'Néctar California Sabores Surtidos 330ml (24 Pack)', 10.00, 0.00),
('Pepsi', 'Néctar California de Manzana 330ml (24 Pack)', 10.00, 8.85),
('Pepsi', 'Néctar California Melocoton 330ml (24 Pack)', 10.00, 8.85),
('Diszasa', 'Papel Higiénico Nevax Fardo (12 Pack)', 9.60, 8.04),
('Pepsi', 'Pepsi 1.5L (12 Pack)', 13.00, 0.00),
('Pepsi', 'Petit Durazno 330ml (24 Pack)', 13.00, 11.50),
('Pepsi', 'Petit Manzana 330ml (24 Pack)', 13.00, 11.50),
('Pepsi', 'Petit Piña 330ml (24 Pack)', 13.00, 11.50),
('Pepsi', 'Petit Tetra Sabores Surtidos 200ml (24 Pack)', 8.15, 0.00),
('La Constancia', 'Powerade Avalancha 500ml pet (12 Pack)', 7.25, 5.94),
('La Constancia', 'Powerade Avalancha 750ml pet (12 Pack)', 9.75, 8.40),
('Grupo AJE', 'Pulp Manzana Caja 145ml (12 Pack)', 2.50, 0.00),
('Grupo AJE', 'Pulp Melocotón Caja 145ml (12 Pack)', 2.50, 0.00),
('Grupo AJE', 'Pulp Melocotón Caja 250ml (12 Pack)', 3.40, 3.01),
('Grupo AJE', 'Pulp Manzana Caja 250ml (12 Pack)', 3.40, 3.01),
('Disna', 'Quanty Naranja 237ml (24 Pack)', 5.00, 4.48),
('Disna', 'Quanty Ponche de Frutas 237ml (24 Pack)', 5.00, 4.48),
('Disna', 'Quanty Uva 237ml (24 Pack)', 5.00, 4.48),
('Grupo EDT', 'Raptor 300ml (24 Pack)', 10.00, 0.00),
('Grupo EDT', 'Raptor 600ml (12 Pack)', 10.00, 8.50),
('Pepsi', 'Salutaris Agua Mineral 355ml (24 Pack)', 13.00, 11.50),
('Pepsi', 'Salutaris de Limón 355ml (24 Pack)', 13.00, 11.50),
('Pepsi', 'Salutaris Naranja 355ml (24 Pack)', 13.00, 11.50),
('Pepsi', 'Salutaris Toronja 355ml (24 Pack)', 13.00, 11.50),
('Grupo AJE', 'Sporade Ice Apple 625ml (12 Pack)', 5.75, 0.00),
('Grupo AJE', 'Sporade Blue Berry 360ml (12 Pack)', 3.80, 3.36),
('Grupo AJE', 'Sporade Blue Berry 625ml (12 Pack)', 5.75, 5.09),
('Grupo AJE', 'Sporade Fruit Punch 360ml (12 Pack)', 3.80, 3.36),
('Grupo AJE', 'Sporade Fruit Punch 625ml (12 Pack)', 5.75, 5.09),
('Grupo AJE', 'Sporade Uva 360ml (12 Pack)', 3.80, 3.36),
('Grupo AJE', 'Sporade Uva 625ml (12 Pack)', 5.75, 5.09),
('Diszasa', 'Surf Junior Mandarina Bolsa 400ml (12 Pack)', 2.50, 0.00),
('Diszasa', 'Surf Junior Naranja Bolsa 400ml (12 Pack)', 2.50, 0.00),
('Diszasa', 'Suero Suerox Fresa y Kiwi 630ml (12 Pack)', 24.00, 0.00),
('Diszasa', 'Suero Suerox Frutos Rojos 630ml (12 Pack)', 24.00, 0.00),
('Diszasa', 'Suero Suerox Manzana 630ml (12 Pack)', 24.00, 0.00),
('Diszasa', 'Suero Suerox Mora Azul 630ml (12 Pack)', 24.00, 0.00),
('Diszasa', 'Suero Suerox Naranja 630ml (12 Pack)', 24.00, 0.00),
('Diszasa', 'Suero Suerox Uva 630ml (12 Pack)', 24.00, 0.00),
('Diszasa', 'Surf Naranja Pet 300ml (12 Pack)', 2.50, 0.00),
('Diszasa', 'Surf Ponche de frutas Pet 300ml (12 Pack)', 2.50, 0.00),
('Diszasa', 'Surf Uva Pet 300ml (12 Pack)', 2.50, 0.00),
('La Constancia', 'Tropical 354ml (12 Pack)', 7.22, 0.00);

-- =====================================================
-- INSERTAR PRODUCTOS - PARTE 3 (PRECIOS ACTUALIZADOS)
-- =====================================================
INSERT INTO productos (proveedor, descripcion, precio_venta, precio_compra) VALUES
('Grupo AJE', 'Volt Go 360ml (12 Pack)', 3.55, 3.14),
('Grupo AJE', 'Volt Ponche de Frutas 625ml (12 Pack)', 8.00, 0.00),
('Grupo AJE', 'Volt Yellow 300ml (12 Pack)', 4.50, 3.98),
('Grupo AJE', 'Volt Yellow Lata 473ml (6 Pack)', 5.00, 0.00),
('Grupo AJE', 'DGussto Cereal Hojuela 25grs (12 Pack)', 3.00, 0.00),
('Grupo AJE', 'DGussto Cereal Hojuela 80grs (8 Pack)', 6.00, 0.00),
('Grupo AJE', 'DGussto Cereal Hojuela 140grs (6 Pack)', 6.00, 0.00),
('Grupo AJE', 'DGussto Cereales Aritos De Colores Bolsa 25grs (12 Pack)', 3.00, 0.00),
('Grupo AJE', 'DGussto Cereales Aritos De Colores Bolsa 80grs (8 Pack)', 6.00, 0.00),
('Grupo AJE', 'DGussto Cereales Aritos De Colores Bolsa 140grs (6 Pack)', 6.00, 0.00),
('Grupo AJE', 'DGussto Cereal Pops Chocolate Bolsa 25grs (12 Pack)', 3.00, 0.00),
('Grupo AJE', 'DGussto Cereal Pops Chocolate Bolsa 80grs (8 Pack)', 6.00, 0.00),
('Grupo AJE', 'DGussto Cereal Pops Chocolate Bolsa 140grs (6 Pack)', 6.00, 0.00);

-- =====================================================
-- CONSULTAS DE VERIFICACIÓN
-- =====================================================

-- Verificar total de productos insertados
SELECT COUNT(*) as total_productos_insertados FROM productos;

-- Mostrar productos por proveedor
SELECT 
    proveedor, 
    COUNT(*) as cantidad_productos,
    AVG(margen_ganancia) as margen_promedio
FROM productos 
GROUP BY proveedor 
ORDER BY cantidad_productos DESC;

-- Verificar primeros 10 productos con precios
SELECT 
    id, 
    proveedor, 
    descripcion, 
    precio_compra,
    precio_venta,
    margen_ganancia as margen_porcentaje
FROM productos 
ORDER BY id 
LIMIT 10;

-- Productos con mejor margen de ganancia
SELECT 
    proveedor, 
    descripcion, 
    precio_compra,
    precio_venta,
    margen_ganancia as margen_porcentaje
FROM productos 
ORDER BY margen_ganancia DESC 
LIMIT 10;

-- Productos con menor margen de ganancia
SELECT 
    proveedor, 
    descripcion, 
    precio_compra,
    precio_venta,
    margen_ganancia as margen_porcentaje
FROM productos 
ORDER BY margen_ganancia ASC 
LIMIT 10;

-- Productos sin precio de compra
SELECT 
    id,
    proveedor, 
    descripcion, 
    precio_venta
FROM productos 
WHERE precio_compra = 0.00
ORDER BY proveedor, descripcion;

-- =====================================================
-- VISTAS ÚTILES
-- =====================================================

-- Vista de productos con información completa
CREATE OR REPLACE VIEW vista_productos_completa AS
SELECT 
    p.id,
    p.proveedor,
    p.descripcion,
    p.precio_compra,
    p.precio_venta,
    p.existencia,
    p.margen_ganancia,
    (p.existencia * p.precio_compra) as valor_compra_inventario,
    (p.existencia * p.precio_venta) as valor_venta_inventario,
    (p.existencia * (p.precio_venta - p.precio_compra)) as ganancia_potencial,
    p.fecha_creacion,
    p.fecha_actualizacion
FROM productos p;

-- Vista de historial de precios
CREATE OR REPLACE VIEW vista_historial_precios AS
SELECT 
    h.id,
    h.producto_id,
    p.descripcion as producto,
    p.proveedor,
    h.precio_compra_anterior,
    h.precio_compra_nuevo,
    (h.precio_compra_nuevo - h.precio_compra_anterior) as diferencia,
    CASE 
        WHEN h.precio_compra_anterior > 0 THEN 
            ((h.precio_compra_nuevo - h.precio_compra_anterior) / h.precio_compra_anterior * 100)
        ELSE 0
    END as porcentaje_cambio,
    h.motivo,
    h.usuario,
    h.fecha_cambio
FROM historial_precios_compra h
INNER JOIN productos p ON h.producto_id = p.id
ORDER BY h.fecha_cambio DESC;

-- =====================================================
-- FIN DEL SCRIPT
-- =====================================================

SELECT '✅ Base de datos creada exitosamente con gestión de precios de compra' as mensaje;
SELECT CONCAT('📦 Total de productos: ', COUNT(*)) as resumen FROM productos;
SELECT '📊 Características:' as info;
SELECT '   - Precio de compra editable por producto' as caracteristica_1;
SELECT '   - Margen de ganancia calculado automáticamente' as caracteristica_2;
SELECT '   - Historial completo de cambios de precios' as caracteristica_3;
SELECT '   - Trigger automático al registrar ingresos' as caracteristica_4;
SELECT '   - Vistas para análisis de rentabilidad' as caracteristica_5;