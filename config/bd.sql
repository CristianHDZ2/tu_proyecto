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

-- Insertar algunos datos de ejemplo
INSERT INTO productos (proveedor, descripcion, precio_venta) VALUES
('Proveedor A', 'Producto 1 del Proveedor A', 15.50),
('Proveedor A', 'Producto 2 del Proveedor A', 25.00),
('Proveedor B', 'Producto 1 del Proveedor B', 12.75),
('Proveedor B', 'Producto 2 del Proveedor B', 18.25),
('Proveedor C', 'Producto 1 del Proveedor C', 22.50);