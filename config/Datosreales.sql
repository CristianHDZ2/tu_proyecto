-- Base de datos para el sistema de inventario
CREATE DATABASE IF NOT EXISTS inventario_system;
USE inventario_system;

-- Tabla de productos
CREATE TABLE productos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    proveedor VARCHAR(255) NOT NULL,
    descripcion TEXT NOT NULL,
    precio_venta DECIMAL(10,2) NOT NULL,
    existencia INT DEFAULT 0,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Tabla de ingresos
CREATE TABLE ingresos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    proveedor VARCHAR(255) NOT NULL,
    numero_factura VARCHAR(100) NOT NULL,
    fecha_ingreso DATE NOT NULL,
    total_factura DECIMAL(10,2) DEFAULT 0,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabla de detalles de ingresos
CREATE TABLE detalle_ingresos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ingreso_id INT NOT NULL,
    producto_id INT NOT NULL,
    cantidad INT NOT NULL,
    precio_compra DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (ingreso_id) REFERENCES ingresos(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
);

-- Tabla de distribuciones (salidas programadas)
CREATE TABLE distribuciones (
    id INT PRIMARY KEY AUTO_INCREMENT,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    dias_exclusion TEXT, -- JSON con días excluidos
    tipo_distribucion ENUM('completo', 'parcial') NOT NULL,
    productos_seleccionados TEXT, -- JSON con productos y cantidades si es parcial
    estado ENUM('activo', 'eliminado') DEFAULT 'activo',
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabla de tablas de distribución por día
CREATE TABLE tablas_distribucion (
    id INT PRIMARY KEY AUTO_INCREMENT,
    distribucion_id INT NOT NULL,
    fecha_tabla DATE NOT NULL,
    numero_tabla INT NOT NULL,
    total_tabla DECIMAL(10,2) DEFAULT 0,
    estado ENUM('activo', 'eliminado') DEFAULT 'activo',
    FOREIGN KEY (distribucion_id) REFERENCES distribuciones(id) ON DELETE CASCADE
);

-- Tabla de detalles de tablas de distribución
CREATE TABLE detalle_tablas_distribucion (
    id INT PRIMARY KEY AUTO_INCREMENT,
    tabla_id INT NOT NULL,
    producto_id INT NOT NULL,
    cantidad INT NOT NULL,
    precio_venta DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (tabla_id) REFERENCES tablas_distribucion(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
);

-- LIMPIAR DATOS EXISTENTES
-- Desactivar verificación de claves foráneas temporalmente
SET FOREIGN_KEY_CHECKS = 0;

-- Limpiar todas las tablas
DELETE FROM detalle_tablas_distribucion;
DELETE FROM tablas_distribucion;
DELETE FROM distribuciones;
DELETE FROM detalle_ingresos;
DELETE FROM ingresos;
DELETE FROM productos;

-- Reiniciar auto_increment
ALTER TABLE detalle_tablas_distribucion AUTO_INCREMENT = 1;
ALTER TABLE tablas_distribucion AUTO_INCREMENT = 1;
ALTER TABLE distribuciones AUTO_INCREMENT = 1;
ALTER TABLE detalle_ingresos AUTO_INCREMENT = 1;
ALTER TABLE ingresos AUTO_INCREMENT = 1;
ALTER TABLE productos AUTO_INCREMENT = 1;

-- Reactivar verificación de claves foráneas
SET FOREIGN_KEY_CHECKS = 1;
-- INSERTAR NUEVOS PRODUCTOS - PARTE 1
INSERT INTO productos (proveedor, descripcion, precio_venta) VALUES
('Pepsi', 'AMP Energy 600ml (12 Pack)', 10.00),
('Pepsi', 'Agua "Aqua" 750ml (12 Pack)', 4.00),
('Industrias Romero', 'Agua "Caída del Cielo" (Fardo)', 0.90),
('Grupo AJE', 'Agua "Cielo" 375ml (24 Pack)', 4.00),
('Grupo AJE', 'Agua "Cielo" 625ml (20 Pack)', 4.50),
('Grupo AJE', 'Agua "Cielo" 1L (8 Pack)', 4.65),
('Industrias Romero', 'Agua "De Los Ángeles" Garrafa 18.5L (Unidad)', 2.00),
('Diszasa', 'Baygon Oko Mosquitos Y Moscas 400ml (Unidad)', 2.25),
('Diszasa', 'Baygon Poder Mortal 400ml (Unidad)', 2.25),
('Grupo AJE', 'Big Cola 250ml (24 Pack)', 5.00),
('Grupo AJE', 'Big Lima Limón 250ml (24 Pack)', 5.00),
('Grupo AJE', 'Big Naranja 250ml (24 Pack)', 5.00),
('Grupo AJE', 'Big Cola 300ml (24 Pack)', 5.00),
('Grupo AJE', 'Big Cola 360ml (24 Pack)', 6.00),
('Grupo AJE', 'Big Cola 625ml (12 Pack)', 4.55),
('Grupo AJE', 'Big Cola 1L (12 Pack)', 6.00),
('Grupo AJE', 'Big Cola 1.3L (8 Pack)', 5.00),
('Grupo AJE', 'Big Cola 1.8L (6 Pack)', 5.50),
('Grupo AJE', 'Big Cola 2.6L (6 Pack)', 6.50),
('Grupo AJE', 'Big Cola 3.03L (6 Pack)', 8.75),
('Grupo AJE', 'Big Lima Limón 360ml (12 Pack)', 3.00),
('Grupo AJE', 'Big Lima Limón 3.03L (6 Pack)', 8.75),
('Grupo AJE', 'Big Lima Limón 250ml (24 Pack)', 5.00),
('Grupo AJE', 'Big Naranja 360ml (12 Pack)', 3.00),
('Grupo AJE', 'Big Naranja 3.03L (6 Pack)', 8.75),
('Grupo AJE', 'Big Roja 360ml (12 Pack)', 3.00),
('Grupo AJE', 'Big Uva 250ml (24 Pack)', 5.00),
('Grupo AJE', 'Big Uva 360ml (12 Pack)', 3.00),
('Grupo AJE', 'Big Uva 3.03L (6 Pack)', 8.75),
('Grupo AJE', 'Bio Aloe "Aloe y Uva" 360ml (6 Pack)', 3.75),
('Diszasa', 'Café Instantáneo Aroma Caja (50 Sobres)', 3.00),
('Diszasa', 'Café Instantáneo Coscafe Caja (40 sobres)', 2.85),
('Diszasa', 'Café Instantáneo Coscafe Caja (50+5 Sobres)', 3.95),
('Grupo AJE', 'Cifrut Citrus Punch 1.3L (8 Pack)', 5.00),
('Grupo AJE', 'Cifrut Citrus Punch Naranja 250ml (24 Pack)', 5.00),
('Grupo AJE', 'Cifrut Citrus Punch Naranja 360ml (12 Pack)', 3.00),
('Grupo AJE', 'Cifrut Citrus Punch Naranja 625ml (15 Pack)', 5.70),
('Grupo AJE', 'Cifrut Citrus Punch Naranja 2.6L (6 Pack)', 6.50),
('Grupo AJE', 'Cifrut Citrus Punch Naranja 3.03L (6 Pack)', 8.75),
('Grupo AJE', 'Cifrut Fruit Punch Rojo 250ml (24 Pack)', 5.00),
('Grupo AJE', 'Cifrut Fruit Punch Rojo 360ml (12 Pack)', 3.00),
('Grupo AJE', 'Cifrut Fruit Punch Rojo 1.3L (8 Pack)', 5.00),
('Grupo AJE', 'Cifrut Fruit Punch Rojo 3.03L (6 Pack)', 8.75),
('La Constancia', 'Coca-Cola Vidrio 354ml (24 Pack)', 10.25),
('La Constancia', 'Coca-Cola 1.25L pet (12 Pack)', 13.50),
('La Constancia', 'Coca-Cola 2.5L pet (6 Pack)', 11.55),
('La Constancia', 'Coca-Cola 3L pet (4 Pack)', 8.50),
('La Constancia', 'Coca-Cola lata 354ml (24 Pack)', 14.45),
('La Constancia', 'Coca-Cola Vidrio 1L (12 Pack)', 10.25),
('La Constancia', 'Coca-Cola Vidrio 1.25L (12 Pack)', 10.25),
('La Constancia', 'Del Valle Mandarina 500ml pet (12 Pack)', 5.75),
('La Constancia', 'Del Valle Mandarina 1.5L pet (6 Pack)', 5.85),
('La Constancia', 'Del Valle Mandarina 2.5L pet (6 Pack)', 8.55);
-- INSERTAR NUEVOS PRODUCTOS - PARTE 2
INSERT INTO productos (proveedor, descripcion, precio_venta) VALUES
('La Constancia', 'Fanta Naranja 1.25L pet (12pack)', 13.50),
('La Constancia', 'Fanta Naranja 2.5L pet (6 Pack)', 11.55),
('La Constancia', 'Fanta Naranja Lata 354ml (12 Pack)', 7.22),
('La Constancia', 'Fanta Vidrio 354ml (24 Pack)', 10.25),
('La Constancia', 'Gatorade Celeste 600ml (24 Pack)', 22.10),
('La Constancia', 'Gatorade Limón 600ml (24 Pack)', 22.10),
('La Constancia', 'Gatorade Naranja 600ml (24 Pack)', 22.10),
('La Constancia', 'Gatorade Rojo 600ml (24 Pack)', 22.10),
('La Constancia', 'Gatorade Uva 600ml (24 Pack)', 22.10),
('Grupo AJE', 'Kola Real K.R 300ml (12 Pack)', 2.50),
('Pepsi', 'Néctar California de Durazno 330ml (24 Pack)', 10.00),
('Pepsi', 'Néctar California de Pera 330ml (24 Pack)', 10.00),
('Pepsi', 'Néctar California Sabores Surtidos 330ml (24 Pack)', 10.00),
('Pepsi', 'Néctar California de Manzana 330ml (24 Pack)', 10.00),
('Pepsi', 'Néctar California Melocoton 330ml (24 Pack)', 10.00),
('Diszasa', 'Papel Higiénico Nevax Fardo (12 Pack)', 9.60),
('Pepsi', 'Pepsi 1.5L (12 Pack)', 13.00),
('Pepsi', 'Petit Durazno 330ml (24 Pack)', 13.00),
('Pepsi', 'Petit Manzana 330ml (24 Pack)', 13.00),
('Pepsi', 'Petit Piña 330ml (24 Pack)', 13.00),
('Pepsi', 'Petit Tetra Sabores Surtidos 200ml (24 Pack)', 8.15),
('La Constancia', 'Powerade Avalancha 500ml pet (12 Pack)', 7.25),
('La Constancia', 'Powerade Avalancha 750ml pet (12 Pack)', 9.75),
('Grupo AJE', 'Pulp Manzana Caja 145ml (12 Pack)', 2.50),
('Grupo AJE', 'Pulp Melocotón Caja 145ml (12 Pack)', 2.50),
('Grupo AJE', 'Pulp Melocotón Caja 250ml (12 Pack)', 3.40),
('Grupo AJE', 'Pulp Manzana Caja 250ml (12 Pack)', 3.40),
('Disna', 'Quanty Naranja 237ml (24 Pack)', 5.00),
('Disna', 'Quanty Ponche de Frutas 237ml (24 Pack)', 5.00),
('Disna', 'Quanty Uva 237ml (24 Pack)', 5.00),
('Grupo EDT', 'Raptor 300ml (24 Pack)', 10.00),
('Grupo EDT', 'Raptor 600ml (12 Pack)', 10.00),
('Pepsi', 'Salutaris Agua Mineral 355ml (24 Pack)', 13.00),
('Pepsi', 'Salutaris de Limón 355ml (24 Pack)', 13.00),
('Pepsi', 'Salutaris Naranja 355ml (24 Pack)', 13.00),
('Grupo AJE', 'Sporade Ice Apple 625ml (12 Pack)', 5.75),
('Grupo AJE', 'Sporade Blue Berry 360ml (12 Pack)', 3.80),
('Grupo AJE', 'Sporade Blue Berry 625ml (12 Pack)', 5.75),
('Grupo AJE', 'Sporade Fruit Punch 360ml (12 Pack)', 3.80),
('Grupo AJE', 'Sporade Fruit Punch 625ml (12 Pack)', 5.75),
('Grupo AJE', 'Sporade Uva 360ml (12 Pack)', 3.80),
('Grupo AJE', 'Sporade Uva 625ml (12 Pack)', 5.75),
('Diszasa', 'Surf Junior Mandarina Bolsa 400ml (12 Pack)', 2.50),
('Diszasa', 'Surf Junior Naranja Bolsa 400ml (12 Pack)', 2.50),
('Diszasa', 'Suero Suerox Fresa y Kiwi 630ml (12 Pack)', 24.00),
('Diszasa', 'Suero Suerox Frutos Rojos 630ml (12 Pack)', 24.00),
('Diszasa', 'Suero Suerox Manzana 630ml (12 Pack)', 24.00),
('Diszasa', 'Suero Suerox Mora Azul 630ml (12 Pack)', 24.00),
('Diszasa', 'Suero Suerox Naranja 630ml (12 Pack)', 24.00),
('Diszasa', 'Suero Suerox Uva 630ml (12 Pack)', 24.00),
('Diszasa', 'Surf Naranja Pet 300ml (12 Pack)', 2.50),
('Diszasa', 'Surf Ponche de frutas Pet 300ml (12 Pack)', 2.50),
('Diszasa', 'Surf Uva Pet 300ml (12 Pack)', 2.50),
('La Constancia', 'Tropical 354ml (12 Pack)', 7.22);
-- INSERTAR NUEVOS PRODUCTOS - PARTE 3 (FINAL)
INSERT INTO productos (proveedor, descripcion, precio_venta) VALUES
('Grupo AJE', 'Volt Go 360ml (12 Pack)', 3.55),
('Grupo AJE', 'Volt Ponche de Frutas 625ml (12 Pack)', 8.00),
('Grupo AJE', 'Volt Yellow 300ml (12 Pack)', 4.50),
('Grupo AJE', 'Volt Yellow Lata 473ml (6 Pack)', 5.00),
('Grupo AJE', 'DGussto Cereal Hojuela 25grs (12 Pack)', 3.00),
('Grupo AJE', 'DGussto Cereal Hojuela 80grs (8 Pack)', 6.00),
('Grupo AJE', 'DGussto Cereal Hojuela 140grs (6 Pack)', 6.00),
('Grupo AJE', 'DGussto Cereales Aritos De Colores Bolsa 25grs (12 Pack)', 3.00),
('Grupo AJE', 'DGussto Cereales Aritos De Colores Bolsa 80grs (8 Pack)', 6.00),
('Grupo AJE', 'DGussto Cereales Aritos De Colores Bolsa 140grs (6 Pack)', 6.00),
('Grupo AJE', 'DGussto Cereal Pops Chocolate Bolsa 25grs (12 Pack)', 3.00),
('Grupo AJE', 'DGussto Cereal Pops Chocolate Bolsa 80grs (8 Pack)', 6.00),
('Grupo AJE', 'DGussto Cereal Pops Chocolate Bolsa 140grs (6 Pack)', 6.00);

-- Verificar que todos los productos se insertaron correctamente
SELECT COUNT(*) as total_productos_insertados FROM productos;

-- Mostrar algunos ejemplos de productos por proveedor
SELECT proveedor, COUNT(*) as cantidad_productos 
FROM productos 
GROUP BY proveedor 
ORDER BY cantidad_productos DESC;

-- Verificar los primeros 10 productos insertados
SELECT id, proveedor, descripcion, precio_venta 
FROM productos 
ORDER BY id 
LIMIT 10;