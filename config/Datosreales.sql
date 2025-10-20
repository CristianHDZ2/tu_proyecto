-- --------------------------------------------------------
-- Host:                         127.0.0.1
-- Versión del servidor:         8.0.30 - MySQL Community Server - GPL
-- SO del servidor:              Win64
-- HeidiSQL Versión:             12.1.0.6537
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


-- Volcando estructura de base de datos para inventario_system
CREATE DATABASE IF NOT EXISTS `inventario_system` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci */ /*!80016 DEFAULT ENCRYPTION='N' */;
USE `inventario_system`;

-- Volcando estructura para tabla inventario_system.detalle_ingresos
CREATE TABLE IF NOT EXISTS `detalle_ingresos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ingreso_id` int NOT NULL,
  `producto_id` int NOT NULL,
  `cantidad` int NOT NULL,
  `precio_compra` decimal(10,2) NOT NULL COMMENT 'Precio de compra en este ingreso específico',
  `subtotal` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ingreso_id` (`ingreso_id`),
  KEY `idx_producto_id` (`producto_id`),
  CONSTRAINT `detalle_ingresos_ibfk_1` FOREIGN KEY (`ingreso_id`) REFERENCES `ingresos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `detalle_ingresos_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Volcando datos para la tabla inventario_system.detalle_ingresos: ~0 rows (aproximadamente)

-- Volcando estructura para tabla inventario_system.detalle_tablas_distribucion
CREATE TABLE IF NOT EXISTS `detalle_tablas_distribucion` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tabla_id` int NOT NULL,
  `producto_id` int NOT NULL,
  `cantidad` int NOT NULL,
  `precio_venta` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tabla_id` (`tabla_id`),
  KEY `idx_producto_id` (`producto_id`),
  CONSTRAINT `detalle_tablas_distribucion_ibfk_1` FOREIGN KEY (`tabla_id`) REFERENCES `tablas_distribucion` (`id`) ON DELETE CASCADE,
  CONSTRAINT `detalle_tablas_distribucion_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Volcando datos para la tabla inventario_system.detalle_tablas_distribucion: ~0 rows (aproximadamente)

-- Volcando estructura para tabla inventario_system.distribuciones
CREATE TABLE IF NOT EXISTS `distribuciones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `fecha_inicio` date NOT NULL,
  `fecha_fin` date NOT NULL,
  `dias_exclusion` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'JSON con días excluidos',
  `tipo_distribucion` enum('completo','parcial') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `productos_seleccionados` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'JSON con productos y cantidades si es parcial',
  `estado` enum('activo','eliminado') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'activo',
  `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_estado` (`estado`),
  KEY `idx_fecha_inicio` (`fecha_inicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Volcando datos para la tabla inventario_system.distribuciones: ~0 rows (aproximadamente)

-- Volcando estructura para tabla inventario_system.historial_precios_compra
CREATE TABLE IF NOT EXISTS `historial_precios_compra` (
  `id` int NOT NULL AUTO_INCREMENT,
  `producto_id` int NOT NULL,
  `precio_compra_anterior` decimal(10,2) DEFAULT '0.00',
  `precio_compra_nuevo` decimal(10,2) NOT NULL,
  `ingreso_id` int DEFAULT NULL COMMENT 'Si el cambio fue por un ingreso, se registra el ID',
  `motivo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'Ingreso de mercancía',
  `usuario` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'Sistema',
  `fecha_cambio` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ingreso_id` (`ingreso_id`),
  KEY `idx_producto_id` (`producto_id`),
  KEY `idx_fecha_cambio` (`fecha_cambio`),
  CONSTRAINT `historial_precios_compra_ibfk_1` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `historial_precios_compra_ibfk_2` FOREIGN KEY (`ingreso_id`) REFERENCES `ingresos` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Volcando datos para la tabla inventario_system.historial_precios_compra: ~0 rows (aproximadamente)

-- Volcando estructura para tabla inventario_system.ingresos
CREATE TABLE IF NOT EXISTS `ingresos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `proveedor` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `numero_factura` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `fecha_ingreso` date NOT NULL,
  `total_factura` decimal(10,2) DEFAULT '0.00',
  `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_numero_factura` (`numero_factura`),
  KEY `idx_fecha_ingreso` (`fecha_ingreso`),
  KEY `idx_proveedor` (`proveedor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Volcando datos para la tabla inventario_system.ingresos: ~0 rows (aproximadamente)

-- Volcando estructura para tabla inventario_system.productos
CREATE TABLE IF NOT EXISTS `productos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `proveedor` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `descripcion` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `precio_compra` decimal(10,2) DEFAULT '0.00' COMMENT 'Último precio de compra registrado',
  `precio_venta` decimal(10,2) NOT NULL,
  `existencia` int DEFAULT '0',
  `margen_ganancia` decimal(5,2) GENERATED ALWAYS AS ((case when (`precio_compra` > 0) then (((`precio_venta` - `precio_compra`) / `precio_compra`) * 100) else 0 end)) STORED COMMENT 'Margen de ganancia en porcentaje (calculado automáticamente)',
  `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_actualizacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_proveedor` (`proveedor`),
  KEY `idx_existencia` (`existencia`)
) ENGINE=InnoDB AUTO_INCREMENT=137 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Volcando datos para la tabla inventario_system.productos: ~136 rows (aproximadamente)
INSERT INTO `productos` (`id`, `proveedor`, `descripcion`, `precio_compra`, `precio_venta`, `existencia`, `fecha_creacion`, `fecha_actualizacion`) VALUES
	(1, 'Pepsi', 'AMP Energy 600ml (12 Pack)', 8.95, 10.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(2, 'Pepsi', 'Agua "Aqua" 750ml (12 Pack)', 3.54, 4.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(3, 'Industrias Romero', 'Agua "Caída del Cielo" (Fardo)', 0.58, 0.90, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(4, 'Grupo AJE', 'Agua "Cielo" 375ml (24 Pack)', 0.00, 4.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(5, 'Grupo AJE', 'Agua "Cielo" 625ml (20 Pack)', 3.98, 4.50, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(6, 'Grupo AJE', 'Agua "Cielo" 1L (8 Pack)', 4.12, 4.65, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(7, 'Industrias Romero', 'Agua "De Los Ángeles" Garrafa 18.5L (Unidad)', 1.24, 2.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(8, 'Diszasa', 'Baygon Oko Mosquitos Y Moscas 400ml (Unidad)', 0.00, 2.25, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(9, 'Diszasa', 'Baygon Poder Mortal 400ml (Unidad)', 0.00, 2.25, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(10, 'Grupo AJE', 'Big Cola 250ml (24 Pack)', 4.42, 5.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(11, 'Grupo AJE', 'Big Lima Limón 250ml (24 Pack)', 4.42, 5.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(12, 'Grupo AJE', 'Big Naranja 250ml (24 Pack)', 0.00, 5.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(13, 'Grupo AJE', 'Big Cola 300ml (24 Pack)', 0.00, 5.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(14, 'Grupo AJE', 'Big Cola 360ml (24 Pack)', 5.31, 6.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(15, 'Grupo AJE', 'Big Cola 625ml (12 Pack)', 4.04, 4.55, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(16, 'Grupo AJE', 'Big Cola 1L (12 Pack)', 0.00, 6.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(17, 'Grupo AJE', 'Big Cola 1.3L (8 Pack)', 4.42, 5.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(18, 'Grupo AJE', 'Big Cola 1.8L (6 Pack)', 0.00, 5.50, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(19, 'Grupo AJE', 'Big Cola 2.6L (6 Pack)', 5.75, 6.50, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(20, 'Grupo AJE', 'Big Cola 3.03L (6 Pack)', 7.74, 8.75, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(21, 'Grupo AJE', 'Big Lima Limón 360ml (12 Pack)', 2.65, 3.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(22, 'Grupo AJE', 'Big Lima Limón 3.03L (6 Pack)', 7.74, 8.75, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(23, 'Grupo AJE', 'Big Lima Limón 250ml (24 Pack)', 4.42, 5.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(24, 'Grupo AJE', 'Big Naranja 360ml (12 Pack)', 2.65, 3.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(25, 'Grupo AJE', 'Big Naranja 3.03L (6 Pack)', 7.74, 8.75, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(26, 'Grupo AJE', 'Big Roja 360ml (12 Pack)', 0.00, 3.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(27, 'Grupo AJE', 'Big Uva 250ml (24 Pack)', 4.42, 5.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(28, 'Grupo AJE', 'Big Uva 360ml (12 Pack)', 2.65, 3.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(29, 'Grupo AJE', 'Big Uva 3.03L (6 Pack)', 7.74, 8.75, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(30, 'Grupo AJE', 'Bio Aloe "Aloe y Uva" 360ml (6 Pack)', 3.32, 3.75, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(31, 'Diszasa', 'Café Instantáneo Aroma Caja (50 Sobres)', 0.00, 3.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(32, 'Diszasa', 'Café Instantáneo Coscafe Caja (40 sobres)', 0.00, 2.85, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(33, 'Diszasa', 'Café Instantáneo Coscafe Caja (50+5 Sobres)', 0.00, 3.95, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(34, 'Grupo AJE', 'Cifrut Citrus Punch 1.3L (8 Pack)', 4.42, 5.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(35, 'Grupo AJE', 'Cifrut Citrus Punch Naranja 250ml (24 Pack)', 4.42, 5.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(36, 'Grupo AJE', 'Cifrut Citrus Punch Naranja 360ml (12 Pack)', 2.65, 3.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(37, 'Grupo AJE', 'Cifrut Citrus Punch Naranja 625ml (15 Pack)', 5.04, 5.70, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(38, 'Grupo AJE', 'Cifrut Citrus Punch Naranja 2.6L (6 Pack)', 0.00, 6.50, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(39, 'Grupo AJE', 'Cifrut Citrus Punch Naranja 3.03L (6 Pack)', 6.64, 8.75, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(40, 'Grupo AJE', 'Cifrut Fruit Punch Rojo 250ml (24 Pack)', 4.42, 5.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(41, 'Grupo AJE', 'Cifrut Fruit Punch Rojo 360ml (12 Pack)', 2.65, 3.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(42, 'Grupo AJE', 'Cifrut Fruit Punch Rojo 1.3L (8 Pack)', 4.42, 5.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(43, 'Grupo AJE', 'Cifrut Fruit Punch Rojo 3.03L (6 Pack)', 7.64, 8.75, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(44, 'La Constancia', 'Coca-Cola Vidrio 354ml (24 Pack)', 8.85, 10.25, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(45, 'La Constancia', 'Coca-Cola 1.25L pet (12 Pack)', 11.04, 13.50, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(46, 'La Constancia', 'Coca-Cola 2.5L pet (6 Pack)', 9.73, 11.55, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(47, 'La Constancia', 'Coca-Cola 3L pet (4 Pack)', 7.56, 8.50, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(48, 'La Constancia', 'Coca-Cola lata 354ml (24 Pack)', 13.01, 14.45, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(49, 'La Constancia', 'Coca-Cola Vidrio 1L (12 Pack)', 0.00, 10.25, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(50, 'La Constancia', 'Coca-Cola Vidrio 1.25L (12 Pack)', 0.00, 10.25, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(51, 'La Constancia', 'Del Valle Mandarina 500ml pet (12 Pack)', 4.86, 5.75, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(52, 'La Constancia', 'Del Valle Mandarina 1.5L pet (6 Pack)', 4.96, 5.85, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(53, 'La Constancia', 'Del Valle Mandarina 2.5L pet (6 Pack)', 7.35, 8.55, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(54, 'La Constancia', 'Energy Fury 500ml pet (12 Pack)', 9.51, 11.25, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(55, 'La Constancia', 'Fanta Naranja 1.25L pet (12pack)', 11.04, 13.50, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(56, 'La Constancia', 'Fanta Naranja 2.5L pet (6 Pack)', 9.68, 11.55, 0, '2025-10-09 15:59:05', '2025-10-20 21:42:15'),
	(57, 'La Constancia', 'Fanta Naranja Lata 354ml (12 Pack)', 0.00, 7.22, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(58, 'La Constancia', 'Fanta Vidrio 354ml (24 Pack)', 8.85, 10.25, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(59, 'Pepsi', 'Gatorade Celeste 600ml (24 Pack)', 19.56, 22.10, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(60, 'Pepsi', 'Gatorade Limón 600ml (24 Pack)', 19.56, 22.10, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(61, 'Pepsi', 'Gatorade Naranja 600ml (24 Pack)', 19.56, 22.10, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(62, 'Pepsi', 'Gatorade Rojo 600ml (24 Pack)', 19.56, 22.10, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(63, 'Pepsi', 'Gatorade Uva 600ml (24 Pack)', 19.56, 22.10, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(64, 'Grupo AJE', 'Kola Real K.R 300ml (12 Pack)', 0.00, 2.50, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(65, 'Grupo AJE', 'Kola Real K.R Naranja 300ml (12 Pack)', 2.50, 5.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(66, 'Pepsi', 'Néctar California de Durazno 330ml (24 Pack)', 0.00, 10.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(67, 'Pepsi', 'Néctar California de Pera 330ml (24 Pack)', 8.85, 10.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(68, 'Pepsi', 'Néctar California Sabores Surtidos 330ml (24 Pack)', 0.00, 10.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(69, 'Pepsi', 'Néctar California de Manzana 330ml (24 Pack)', 8.85, 10.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(70, 'Pepsi', 'Néctar California Melocoton 330ml (24 Pack)', 8.85, 10.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(71, 'Diszasa', 'Papel Higiénico Nevax Fardo (12 Pack)', 8.04, 9.60, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(72, 'Pepsi', 'Pepsi 1.5L (12 Pack)', 0.00, 13.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(73, 'Pepsi', 'Petit Durazno 330ml (24 Pack)', 11.50, 13.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(74, 'Pepsi', 'Petit Manzana 330ml (24 Pack)', 11.50, 13.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(75, 'Pepsi', 'Petit Piña 330ml (24 Pack)', 11.50, 13.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(76, 'Pepsi', 'Petit Tetra Sabores Surtidos 200ml (24 Pack)', 0.00, 8.15, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(77, 'La Constancia', 'Powerade Avalancha 500ml pet (12 Pack)', 5.94, 7.25, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(78, 'La Constancia', 'Powerade Avalancha 750ml pet (12 Pack)', 8.40, 9.75, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(79, 'Grupo AJE', 'Pulp Manzana Caja 145ml (12 Pack)', 2.21, 2.50, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(80, 'Grupo AJE', 'Pulp Melocotón Caja 145ml (12 Pack)', 0.00, 2.50, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(81, 'Grupo AJE', 'Pulp Melocotón Caja 250ml (12 Pack)', 3.01, 3.40, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(82, 'Grupo AJE', 'Pulp Manzana Caja 250ml (12Pack)', 3.01, 3.40, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(83, 'Disna', 'Quanty Naranja 237ml (24 Pack)', 4.48, 5.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(84, 'Disna', 'Quanty Ponche de Frutas 237ml (24 Pack)', 4.48, 5.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(85, 'Disna', 'Quanty Uva 237ml (24 Pack)', 4.48, 5.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(86, 'Grupo EDT', 'Raptor 300ml (24 Pack)', 0.00, 10.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(87, 'Grupo EDT', 'Raptor 600ml (12 Pack)', 8.50, 10.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(88, 'Pepsi', 'Salutaris Agua Mineral 355ml (24 Pack)', 11.50, 13.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(89, 'Pepsi', 'Salutaris de Limón 355ml (24 Pack)', 11.50, 13.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(90, 'Pepsi', 'Salutaris Naranja 355ml (24 Pack)', 11.50, 13.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(91, 'Pepsi', 'Salutaris Toronja 355ml (24 Pack)', 11.50, 13.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(92, 'Grupo AJE', 'Sporade Ice Apple 625ml (12 Pack)', 0.00, 5.75, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(93, 'Grupo AJE', 'Sporade Blue Berry 360ml (12 Pack)', 3.36, 3.80, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(94, 'Grupo AJE', 'Sporade Blue Berry 625ml (12 Pack)', 5.09, 5.75, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(95, 'Grupo AJE', 'Sporade Fruit Punch 360ml (12 Pack)', 3.36, 3.80, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(96, 'Grupo AJE', 'Sporade Fruit Punch 625ml (12 Pack)', 5.09, 5.75, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(97, 'Grupo AJE', 'Sporade Uva 360ml (12 Pack)', 3.36, 3.80, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(98, 'Grupo AJE', 'Sporade Uva 625ml (12 Pack)', 5.09, 5.75, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(99, 'Diszasa', 'Surf Junior Mandarina Bolsa 400ml (12 Pack)', 0.00, 2.50, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(100, 'Diszasa', 'Surf Junior Naranja Bolsa 400ml (12 Pack)', 0.00, 2.50, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(101, 'Diszasa', 'Suero Suerox Fresa y Kiwi 630ml (12 Pack)', 0.00, 24.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(102, 'Diszasa', 'Suero Suerox Frutos Rojos 630ml (12 Pack)', 0.00, 24.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(103, 'Diszasa', 'Suero Suerox Manzana 630ml (12 Pack)', 0.00, 24.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(104, 'Diszasa', 'Suero Suerox Mora Azul 630ml (12 Pack)', 0.00, 24.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(105, 'Diszasa', 'Suero Suerox Naranja 630ml (12 Pack)', 0.00, 24.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(106, 'Diszasa', 'Suero Suerox Uva 630ml (12 Pack)', 0.00, 24.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(107, 'Diszasa', 'Surf Naranja Pet 300ml (12 Pack)', 0.00, 2.50, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(108, 'Diszasa', 'Surf Ponche de frutas Pet 300ml (12 Pack)', 0.00, 2.50, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(109, 'Diszasa', 'Surf Uva Pet 300ml (12 Pack)', 0.00, 2.50, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(110, 'La Constancia', 'Tropical Uva 354ml (12 Pack)', 0.00, 7.22, 0, '2025-10-09 15:59:05', '2025-10-09 17:18:22'),
	(111, 'Grupo AJE', 'Volt Go 360ml (12 Pack)', 3.14, 3.55, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(112, 'Grupo AJE', 'Volt Ponche de Frutas 625ml (12 Pack)', 0.00, 8.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(113, 'Grupo AJE', 'Volt Yellow Fantasy 300ml (12 Pack)', 3.98, 4.50, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(114, 'Grupo AJE', 'Volt Yellow Lata 473ml (6 Pack)', 0.00, 5.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(115, 'Grupo AJE', 'DGussto Cereal Hojuela 25grs (12 Pack)', 0.00, 3.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(116, 'Grupo AJE', 'DGussto Cereal Hojuela 80grs (8 Pack)', 0.00, 6.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(117, 'Grupo AJE', 'DGussto Cereal Hojuela 140grs (6 Pack)', 0.00, 6.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(118, 'Grupo AJE', 'DGussto Cereales Aritos De Colores Bolsa 25grs (12 Pack)', 0.00, 3.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(119, 'Grupo AJE', 'DGussto Cereales Aritos De Colores Bolsa 80grs (8 Pack)', 0.00, 6.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(120, 'Grupo AJE', 'DGussto Cereales Aritos De Colores Bolsa 140grs (6 Pack)', 0.00, 6.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(121, 'Grupo AJE', 'DGussto Cereal Pops Chocolate Bolsa 25grs (12 Pack)', 0.00, 3.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(122, 'Grupo AJE', 'DGussto Cereal Pops Chocolate Bolsa 80grs (8 Pack)', 0.00, 6.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(123, 'Grupo AJE', 'DGussto Cereal Pops Chocolate Bolsa 140grs (6 Pack)', 0.00, 6.00, 0, '2025-10-09 15:59:05', '2025-10-09 15:59:05'),
	(124, 'Grupo AJE', 'Bio Aloe Vera Natural 500ml (6 Pack)', 4.42, 5.00, 0, '2025-10-09 16:33:08', '2025-10-09 16:33:08'),
	(125, 'Grupo AJE', 'Pulp Manzana pet 360ml (6 Pack)', 2.21, 2.50, 0, '2025-10-09 16:37:09', '2025-10-09 16:37:09'),
	(126, 'Pepsi', 'Petit Pera 330ml (24 Pack)', 11.50, 13.00, 0, '2025-10-09 17:13:25', '2025-10-09 17:13:25'),
	(127, 'La Constancia', 'Spray Vidrio 354ml (24 Pack)', 8.85, 10.25, 0, '2025-10-09 17:19:21', '2025-10-09 17:19:21'),
	(128, 'La Constancia', 'Uva Vidrio 354ml (24 Pack)', 8.85, 11.25, 0, '2025-10-09 17:19:51', '2025-10-09 17:19:51'),
	(129, 'La Constancia', 'Crema Soda Vidrio 354ml (24 Pack)', 8.85, 11.25, 0, '2025-10-09 17:20:16', '2025-10-09 17:20:16'),
	(130, 'La Constancia', 'Tropical Uva 2.5L pet (6 Pack)', 9.68, 11.55, 0, '2025-10-09 17:20:54', '2025-10-20 21:42:15'),
	(131, 'La Constancia', 'Tropical Uva 1.25L pet (12pack)', 11.44, 13.50, 0, '2025-10-09 17:21:29', '2025-10-20 21:42:15'),
	(132, 'La Constancia', 'Tropical Fresa 2.5L (6 Pack)', 9.68, 11.55, 0, '2025-10-20 21:42:15', '2025-10-20 21:42:15'),
	(133, 'La Constancia', 'Fanta Naranja 2.5L pet (6 Pack)', 9.68, 11.55, 0, '2025-10-20 21:42:15', '2025-10-20 21:42:15'),
	(134, 'La Constancia', 'Coca-Cola 300ml (24 Pack)', 8.60, 10.50, 0, '2025-10-20 21:42:15', '2025-10-20 21:42:15');

-- Volcando estructura para tabla inventario_system.tablas_distribucion
CREATE TABLE IF NOT EXISTS `tablas_distribucion` (
  `id` int NOT NULL AUTO_INCREMENT,
  `distribucion_id` int NOT NULL,
  `fecha_tabla` date NOT NULL,
  `numero_tabla` int NOT NULL,
  `total_tabla` decimal(10,2) DEFAULT '0.00',
  `estado` enum('activo','eliminado') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'activo',
  PRIMARY KEY (`id`),
  KEY `idx_distribucion_id` (`distribucion_id`),
  KEY `idx_fecha_tabla` (`fecha_tabla`),
  KEY `idx_estado` (`estado`),
  CONSTRAINT `tablas_distribucion_ibfk_1` FOREIGN KEY (`distribucion_id`) REFERENCES `distribuciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Volcando datos para la tabla inventario_system.tablas_distribucion: ~0 rows (aproximadamente)

-- Volcando estructura para vista inventario_system.vista_historial_precios
-- Creando tabla temporal para superar errores de dependencia de VIEW
CREATE TABLE `vista_historial_precios` (
	`id` INT(10) NOT NULL,
	`producto_id` INT(10) NOT NULL,
	`producto` TEXT NOT NULL COLLATE 'utf8mb4_unicode_ci',
	`proveedor` VARCHAR(255) NOT NULL COLLATE 'utf8mb4_unicode_ci',
	`precio_compra_anterior` DECIMAL(10,2) NULL,
	`precio_compra_nuevo` DECIMAL(10,2) NOT NULL,
	`diferencia` DECIMAL(11,2) NULL,
	`porcentaje_cambio` DECIMAL(20,6) NULL,
	`motivo` VARCHAR(255) NULL COLLATE 'utf8mb4_unicode_ci',
	`usuario` VARCHAR(100) NULL COLLATE 'utf8mb4_unicode_ci',
	`fecha_cambio` TIMESTAMP NULL
) ENGINE=MyISAM;

-- Volcando estructura para vista inventario_system.vista_productos_completa
-- Creando tabla temporal para superar errores de dependencia de VIEW
CREATE TABLE `vista_productos_completa` (
	`id` INT(10) NOT NULL,
	`proveedor` VARCHAR(255) NOT NULL COLLATE 'utf8mb4_unicode_ci',
	`descripcion` TEXT NOT NULL COLLATE 'utf8mb4_unicode_ci',
	`precio_compra` DECIMAL(10,2) NULL COMMENT 'Último precio de compra registrado',
	`precio_venta` DECIMAL(10,2) NOT NULL,
	`existencia` INT(10) NULL,
	`margen_ganancia` DECIMAL(5,2) NULL COMMENT 'Margen de ganancia en porcentaje (calculado automáticamente)',
	`valor_compra_inventario` DECIMAL(20,2) NULL,
	`valor_venta_inventario` DECIMAL(20,2) NULL,
	`ganancia_potencial` DECIMAL(21,2) NULL,
	`fecha_creacion` TIMESTAMP NULL,
	`fecha_actualizacion` TIMESTAMP NULL
) ENGINE=MyISAM;

-- Volcando estructura para disparador inventario_system.actualizar_precio_compra_producto
SET @OLDTMP_SQL_MODE=@@SQL_MODE, SQL_MODE='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
DELIMITER //
CREATE TRIGGER `actualizar_precio_compra_producto` AFTER INSERT ON `detalle_ingresos` FOR EACH ROW BEGIN
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
END//
DELIMITER ;
SET SQL_MODE=@OLDTMP_SQL_MODE;

-- Volcando estructura para vista inventario_system.vista_historial_precios
-- Eliminando tabla temporal y crear estructura final de VIEW
DROP TABLE IF EXISTS `vista_historial_precios`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `vista_historial_precios` AS select `h`.`id` AS `id`,`h`.`producto_id` AS `producto_id`,`p`.`descripcion` AS `producto`,`p`.`proveedor` AS `proveedor`,`h`.`precio_compra_anterior` AS `precio_compra_anterior`,`h`.`precio_compra_nuevo` AS `precio_compra_nuevo`,(`h`.`precio_compra_nuevo` - `h`.`precio_compra_anterior`) AS `diferencia`,(case when (`h`.`precio_compra_anterior` > 0) then (((`h`.`precio_compra_nuevo` - `h`.`precio_compra_anterior`) / `h`.`precio_compra_anterior`) * 100) else 0 end) AS `porcentaje_cambio`,`h`.`motivo` AS `motivo`,`h`.`usuario` AS `usuario`,`h`.`fecha_cambio` AS `fecha_cambio` from (`historial_precios_compra` `h` join `productos` `p` on((`h`.`producto_id` = `p`.`id`))) order by `h`.`fecha_cambio` desc;

-- Volcando estructura para vista inventario_system.vista_productos_completa
-- Eliminando tabla temporal y crear estructura final de VIEW
DROP TABLE IF EXISTS `vista_productos_completa`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `vista_productos_completa` AS select `p`.`id` AS `id`,`p`.`proveedor` AS `proveedor`,`p`.`descripcion` AS `descripcion`,`p`.`precio_compra` AS `precio_compra`,`p`.`precio_venta` AS `precio_venta`,`p`.`existencia` AS `existencia`,`p`.`margen_ganancia` AS `margen_ganancia`,(`p`.`existencia` * `p`.`precio_compra`) AS `valor_compra_inventario`,(`p`.`existencia` * `p`.`precio_venta`) AS `valor_venta_inventario`,(`p`.`existencia` * (`p`.`precio_venta` - `p`.`precio_compra`)) AS `ganancia_potencial`,`p`.`fecha_creacion` AS `fecha_creacion`,`p`.`fecha_actualizacion` AS `fecha_actualizacion` from `productos` `p`;

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;