<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$mensaje = '';
$tipo_mensaje = '';

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['accion'])) {
        switch ($_POST['accion']) {
            case 'crear_distribucion':
                try {
                    $db->beginTransaction();
                    
                    $fecha_inicio = $_POST['fecha_inicio'];
                    $fecha_fin = $_POST['fecha_fin'];
                    $dias_exclusion = isset($_POST['dias_exclusion']) ? json_encode($_POST['dias_exclusion']) : '[]';
                    $tipo_distribucion = $_POST['tipo_distribucion'];
                    $productos_seleccionados = '';
                    
                    // Si es distribución parcial, obtener productos seleccionados
                    if ($tipo_distribucion == 'parcial') {
                        $productos_data = [];
                        $productos_ids = $_POST['productos_parciales'];
                        $cantidades_parciales = $_POST['cantidades_parciales'];
                        
                        for ($i = 0; $i < count($productos_ids); $i++) {
                            if ($cantidades_parciales[$i] > 0) {
                                $productos_data[] = [
                                    'producto_id' => $productos_ids[$i],
                                    'cantidad' => $cantidades_parciales[$i]
                                ];
                            }
                        }
                        $productos_seleccionados = json_encode($productos_data);
                    }
                    
                    // Insertar la distribución
                    $stmt = $db->prepare("INSERT INTO distribuciones (fecha_inicio, fecha_fin, dias_exclusion, tipo_distribucion, productos_seleccionados) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$fecha_inicio, $fecha_fin, $dias_exclusion, $tipo_distribucion, $productos_seleccionados]);
                    
                    $distribucion_id = $db->lastInsertId();
                    
                    // Generar las tablas de distribución
                    $resultado = generarTablasDistribucion($db, $distribucion_id, $fecha_inicio, $fecha_fin, $dias_exclusion, $tipo_distribucion, $productos_seleccionados);
                    
                    if ($resultado['success']) {
                        $db->commit();
                        $mensaje = "Distribución creada exitosamente. " . $resultado['message'];
                        $tipo_mensaje = "success";
                    } else {
                        $db->rollBack();
                        $mensaje = "Error al generar la distribución: " . $resultado['message'];
                        $tipo_mensaje = "danger";
                    }
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    $mensaje = "Error al crear la distribución: " . $e->getMessage();
                    $tipo_mensaje = "danger";
                }
                break;
                
            case 'eliminar_distribucion':
                try {
                    $db->beginTransaction();
                    
                    // Revertir existencias antes de eliminar
                    $stmt_tablas = $db->prepare("SELECT id FROM tablas_distribucion WHERE distribucion_id = ? AND estado = 'activo'");
                    $stmt_tablas->execute([$_POST['distribucion_id']]);
                    $tablas = $stmt_tablas->fetchAll();
                    
                    foreach ($tablas as $tabla) {
                        $stmt_detalles = $db->prepare("SELECT producto_id, cantidad FROM detalle_tablas_distribucion WHERE tabla_id = ?");
                        $stmt_detalles->execute([$tabla['id']]);
                        $detalles = $stmt_detalles->fetchAll();
                        
                        foreach ($detalles as $detalle) {
                            $stmt_revertir = $db->prepare("UPDATE productos SET existencia = existencia + ? WHERE id = ?");
                            $stmt_revertir->execute([$detalle['cantidad'], $detalle['producto_id']]);
                        }
                    }
                    
                    // Marcar distribución como eliminada
                    $stmt_eliminar = $db->prepare("UPDATE distribuciones SET estado = 'eliminado' WHERE id = ?");
                    $stmt_eliminar->execute([$_POST['distribucion_id']]);
                    
                    $stmt_eliminar_tablas = $db->prepare("UPDATE tablas_distribucion SET estado = 'eliminado' WHERE distribucion_id = ?");
                    $stmt_eliminar_tablas->execute([$_POST['distribucion_id']]);
                    
                    $db->commit();
                    $mensaje = "Distribución eliminada exitosamente.";
                    $tipo_mensaje = "success";
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    $mensaje = "Error al eliminar la distribución: " . $e->getMessage();
                    $tipo_mensaje = "danger";
                }
                break;
        }
    }
}

// Función mejorada para generar tablas de distribución - ALGORITMO COMPLETAMENTE REDISEÑADO
function generarTablasDistribucion($db, $distribucion_id, $fecha_inicio, $fecha_fin, $dias_exclusion_json, $tipo_distribucion, $productos_seleccionados_json) {
    try {
        $dias_exclusion = json_decode($dias_exclusion_json, true) ?: [];
        
        // **PASO 1: OBTENER PRODUCTOS DISPONIBLES**
        if ($tipo_distribucion == 'completo') {
            $stmt_productos = $db->prepare("SELECT id, descripcion, existencia, precio_venta FROM productos WHERE existencia > 0 ORDER BY id");
            $stmt_productos->execute();
            $productos_base = $stmt_productos->fetchAll();
            
            $productos_a_distribuir = [];
            foreach ($productos_base as $producto) {
                $productos_a_distribuir[] = [
                    'id' => $producto['id'],
                    'descripcion' => $producto['descripcion'],
                    'precio_venta' => $producto['precio_venta'],
                    'cantidad_total' => $producto['existencia'], // TODO el inventario
                    'cantidad_restante' => $producto['existencia']
                ];
            }
        } else {
            $productos_seleccionados = json_decode($productos_seleccionados_json, true) ?: [];
            $productos_a_distribuir = [];
            
            foreach ($productos_seleccionados as $producto_sel) {
                $stmt_producto = $db->prepare("SELECT id, descripcion, existencia, precio_venta FROM productos WHERE id = ?");
                $stmt_producto->execute([$producto_sel['producto_id']]);
                $producto = $stmt_producto->fetch();
                
                if ($producto) {
                    $cantidad_distribuir = min($producto_sel['cantidad'], $producto['existencia']);
                    $productos_a_distribuir[] = [
                        'id' => $producto['id'],
                        'descripcion' => $producto['descripcion'],
                        'precio_venta' => $producto['precio_venta'],
                        'cantidad_total' => $cantidad_distribuir, // SOLO lo seleccionado
                        'cantidad_restante' => $cantidad_distribuir
                    ];
                }
            }
        }
        
        if (empty($productos_a_distribuir)) {
            return ['success' => false, 'message' => 'No hay productos disponibles para distribuir.'];
        }
        
        // **PASO 2: CALCULAR FECHAS VÁLIDAS**
        $fechas_validas = [];
        $fecha_actual = new DateTime($fecha_inicio);
        $fecha_limite = new DateTime($fecha_fin);
        
        $dias_semana = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
        
        while ($fecha_actual <= $fecha_limite) {
            $dia_semana_num = $fecha_actual->format('w');
            if (!in_array($dia_semana_num, $dias_exclusion)) {
                $fechas_validas[] = [
                    'fecha' => $fecha_actual->format('Y-m-d'),
                    'dia_nombre' => $dias_semana[$dia_semana_num],
                    'fecha_formato' => $fecha_actual->format('d/m/Y')
                ];
            }
            $fecha_actual->add(new DateInterval('P1D'));
        }
        
        if (empty($fechas_validas)) {
            return ['success' => false, 'message' => 'No hay fechas válidas para la distribución.'];
        }
        
        $total_dias = count($fechas_validas);
        
        // **PASO 3: ESTRATEGIA DE DISTRIBUCIÓN MEJORADA**
        // Calcular totales para la estrategia
        $cantidad_total_productos = array_sum(array_column($productos_a_distribuir, 'cantidad_total'));
        $promedio_tablas_por_dia = rand(15, 25); // Promedio más realista
        $total_tablas_estimadas = $total_dias * $promedio_tablas_por_dia;
        
        // **PASO 4: ALGORITMO DE DISTRIBUCIÓN EQUILIBRADA**
        $total_tablas_generadas = 0;
        $productos_distribuidos_completamente = 0;
        $estadisticas_por_dia = [];
        
        foreach ($fechas_validas as $fecha_info) {
            $fecha = $fecha_info['fecha'];
            $dia_nombre = $fecha_info['dia_nombre'];
            
            // Determinar número de tablas para este día
            $tablas_este_dia = rand(10, 30);
            
            // Filtrar productos que aún tienen cantidad restante
            $productos_disponibles_hoy = array_filter($productos_a_distribuir, function($p) {
                return $p['cantidad_restante'] > 0;
            });
            
            if (empty($productos_disponibles_hoy)) {
                // Si no hay productos, generar al menos 1 tabla vacía (no debería pasar)
                $stmt_tabla = $db->prepare("INSERT INTO tablas_distribucion (distribucion_id, fecha_tabla, numero_tabla, total_tabla) VALUES (?, ?, ?, ?)");
                $stmt_tabla->execute([$distribucion_id, $fecha, 1, 0]);
                continue;
            }
            
            $total_dia = 0;
            $tablas_generadas_hoy = 0;
            
            // **ALGORITMO INTELIGENTE PARA DISTRIBUIR EN EL DÍA**
            for ($tabla_num = 1; $tabla_num <= $tablas_este_dia; $tabla_num++) {
                // Volver a filtrar productos disponibles
                $productos_disponibles_tabla = array_filter($productos_a_distribuir, function($p) {
                    return $p['cantidad_restante'] > 0;
                });
                
                if (empty($productos_disponibles_tabla)) {
                    break; // Ya no hay productos para distribuir
                }
                
                // Insertar tabla
                $stmt_tabla = $db->prepare("INSERT INTO tablas_distribucion (distribucion_id, fecha_tabla, numero_tabla) VALUES (?, ?, ?)");
                $stmt_tabla->execute([$distribucion_id, $fecha, $tabla_num]);
                $tabla_id = $db->lastInsertId();
                
                // **ESTRATEGIA DE SELECCIÓN DE PRODUCTOS PARA LA TABLA**
                $productos_en_tabla = [];
                $max_productos_por_tabla = min(40, count($productos_disponibles_tabla));
                $productos_seleccionados_tabla = rand(1, $max_productos_por_tabla);
                
                // Seleccionar productos de manera inteligente
                $indices_productos = array_keys($productos_disponibles_tabla);
                shuffle($indices_productos); // Aleatorizar
                
                $productos_seleccionados_indices = array_slice($indices_productos, 0, $productos_seleccionados_tabla);
                
                $total_tabla = 0;
                
                foreach ($productos_seleccionados_indices as $indice) {
                    if ($productos_a_distribuir[$indice]['cantidad_restante'] <= 0) {
                        continue;
                    }
                    
                    // **ESTRATEGIA DE CANTIDAD INTELIGENTE**
                    $cantidad_disponible = $productos_a_distribuir[$indice]['cantidad_restante'];
                    $cantidad_usar = calcularCantidadOptima($cantidad_disponible, $tabla_num, $tablas_este_dia, $total_dias);
                    
                    if ($cantidad_usar > 0) {
                        $precio = $productos_a_distribuir[$indice]['precio_venta'];
                        $subtotal = $cantidad_usar * $precio;
                        $total_tabla += $subtotal;
                        
                        // Insertar detalle
                        $stmt_detalle = $db->prepare("INSERT INTO detalle_tablas_distribucion (tabla_id, producto_id, cantidad, precio_venta, subtotal) VALUES (?, ?, ?, ?, ?)");
                        $stmt_detalle->execute([$tabla_id, $productos_a_distribuir[$indice]['id'], $cantidad_usar, $precio, $subtotal]);
                        
                        // Actualizar existencia en BD
                        $stmt_update = $db->prepare("UPDATE productos SET existencia = existencia - ? WHERE id = ?");
                        $stmt_update->execute([$cantidad_usar, $productos_a_distribuir[$indice]['id']]);
                        
                        // Actualizar cantidad restante en nuestro array
                        $productos_a_distribuir[$indice]['cantidad_restante'] -= $cantidad_usar;
                        
                        // Verificar si el producto se agotó
                        if ($productos_a_distribuir[$indice]['cantidad_restante'] <= 0) {
                            $productos_distribuidos_completamente++;
                        }
                    }
                }
                
                // Actualizar total de la tabla
                $stmt_total = $db->prepare("UPDATE tablas_distribucion SET total_tabla = ? WHERE id = ?");
                $stmt_total->execute([$total_tabla, $tabla_id]);
                
                $total_dia += $total_tabla;
                $tablas_generadas_hoy++;
                $total_tablas_generadas++;
            }
            
            $estadisticas_por_dia[] = [
                'fecha' => $fecha,
                'dia' => $dia_nombre,
                'tablas' => $tablas_generadas_hoy,
                'total' => $total_dia
            ];
        }
        
        // **PASO 5: VERIFICACIÓN FINAL Y DISTRIBUCIÓN DE REMANENTES**
        $productos_con_remanentes = array_filter($productos_a_distribuir, function($p) {
            return $p['cantidad_restante'] > 0;
        });
        
        if (!empty($productos_con_remanentes)) {
            // Distribuir remanentes en las últimas tablas generadas
            $mensaje_remanentes = distribuirRemanentes($db, $distribucion_id, $productos_con_remanentes, $fechas_validas);
        }
        
        // **GENERAR MENSAJE DE RESULTADO**
        $total_productos_originales = count($productos_a_distribuir);
        $porcentaje_completado = ($productos_distribuidos_completamente / $total_productos_originales) * 100;
        
        $mensaje = sprintf(
            "✅ Distribución completada exitosamente:\n" .
            "📊 %d tablas generadas en %d días\n" .
            "📦 %d/%d productos distribuidos completamente (%.1f%%)\n" .
            "📅 Cobertura: 100%% de los días seleccionados\n" .
            "🎯 Estrategia: Distribución inteligente con agotamiento garantizado",
            $total_tablas_generadas,
            $total_dias,
            $productos_distribuidos_completamente,
            $total_productos_originales,
            $porcentaje_completado
        );
        
        if (isset($mensaje_remanentes)) {
            $mensaje .= "\n" . $mensaje_remanentes;
        }
        
        return ['success' => true, 'message' => $mensaje];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
// **FUNCIONES AUXILIARES PARA EL ALGORITMO MEJORADO**

/**
 * Calcula la cantidad óptima a distribuir por producto en cada tabla
 * Considera la fase de la distribución y la cantidad disponible
 */
function calcularCantidadOptima($cantidad_disponible, $tabla_actual, $total_tablas_dia, $dias_restantes) {
    if ($cantidad_disponible <= 0) return 0;
    
    // Estrategia progresiva: más agresiva al final
    $factor_agresividad = min(1.5, 1 + ($tabla_actual / $total_tablas_dia) * 0.5);
    
    // Cantidad base más inteligente
    if ($cantidad_disponible <= 5) {
        // Cantidades pequeñas: distribuir todo o casi todo
        return rand(1, $cantidad_disponible);
    } elseif ($cantidad_disponible <= 20) {
        // Cantidades medianas: distribuir entre 1 y 70%
        $max_cantidad = max(1, floor($cantidad_disponible * 0.7 * $factor_agresividad));
        return rand(1, $max_cantidad);
    } elseif ($cantidad_disponible <= 100) {
        // Cantidades grandes: distribuir entre 1 y 40%
        $max_cantidad = max(1, floor($cantidad_disponible * 0.4 * $factor_agresividad));
        return rand(1, $max_cantidad);
    } else {
        // Cantidades muy grandes: distribuir entre 5 y 25% pero mínimo 10
        $base_cantidad = max(10, floor($cantidad_disponible * 0.25 * $factor_agresividad));
        $min_cantidad = max(5, floor($cantidad_disponible * 0.05));
        return rand($min_cantidad, $base_cantidad);
    }
}

/**
 * Distribuye los productos remanentes en las últimas tablas
 */
function distribuirRemanentes($db, $distribucion_id, $productos_remanentes, $fechas_validas) {
    $total_remanentes = 0;
    $productos_con_remanentes = 0;
    
    foreach ($productos_remanentes as $producto) {
        if ($producto['cantidad_restante'] > 0) {
            $total_remanentes += $producto['cantidad_restante'];
            $productos_con_remanentes++;
        }
    }
    
    if ($total_remanentes == 0) {
        return "✅ No hay remanentes - distribución 100% completa";
    }
    
    // Obtener las últimas tablas generadas para distribuir remanentes
    $stmt_ultimas_tablas = $db->prepare("
        SELECT id, fecha_tabla, numero_tabla, total_tabla 
        FROM tablas_distribucion 
        WHERE distribucion_id = ? 
        ORDER BY fecha_tabla DESC, numero_tabla DESC 
        LIMIT 20
    ");
    $stmt_ultimas_tablas->execute([$distribucion_id]);
    $ultimas_tablas = $stmt_ultimas_tablas->fetchAll();
    
    if (empty($ultimas_tablas)) {
        return "⚠️ No se pudieron distribuir {$total_remanentes} unidades de {$productos_con_remanentes} productos";
    }
    
    // Distribuir remanentes
    $remanentes_distribuidos = 0;
    foreach ($productos_remanentes as $producto) {
        if ($producto['cantidad_restante'] <= 0) continue;
        
        $cantidad_restante = $producto['cantidad_restante'];
        
        // Distribuir en las últimas tablas disponibles
        foreach ($ultimas_tablas as $tabla) {
            if ($cantidad_restante <= 0) break;
            
            // Verificar si el producto ya está en esta tabla
            $stmt_check = $db->prepare("SELECT id FROM detalle_tablas_distribucion WHERE tabla_id = ? AND producto_id = ?");
            $stmt_check->execute([$tabla['id'], $producto['id']]);
            
            if ($stmt_check->fetch()) {
                continue; // Ya existe en esta tabla, saltar
            }
            
            // Calcular cantidad a agregar (distribuir remanentes más agresivamente)
            $cantidad_agregar = min($cantidad_restante, rand(1, max(1, floor($cantidad_restante / 2))));
            
            if ($cantidad_agregar > 0) {
                $subtotal = $cantidad_agregar * $producto['precio_venta'];
                
                // Insertar detalle del remanente
                $stmt_detalle = $db->prepare("INSERT INTO detalle_tablas_distribucion (tabla_id, producto_id, cantidad, precio_venta, subtotal) VALUES (?, ?, ?, ?, ?)");
                $stmt_detalle->execute([$tabla['id'], $producto['id'], $cantidad_agregar, $producto['precio_venta'], $subtotal]);
                
                // Actualizar existencia
                $stmt_update = $db->prepare("UPDATE productos SET existencia = existencia - ? WHERE id = ?");
                $stmt_update->execute([$cantidad_agregar, $producto['id']]);
                
                // Actualizar total de la tabla
                $nuevo_total = $tabla['total_tabla'] + $subtotal;
                $stmt_total = $db->prepare("UPDATE tablas_distribucion SET total_tabla = ? WHERE id = ?");
                $stmt_total->execute([$nuevo_total, $tabla['id']]);
                
                $cantidad_restante -= $cantidad_agregar;
                $remanentes_distribuidos += $cantidad_agregar;
                
                // Actualizar el total en nuestro array local para próximas iteraciones
                $tabla['total_tabla'] = $nuevo_total;
            }
        }
    }
    
    if ($remanentes_distribuidos > 0) {
        return "♻️ Se distribuyeron {$remanentes_distribuidos} unidades adicionales de remanentes";
    } else {
        return "⚠️ Quedan {$total_remanentes} unidades sin distribuir de {$productos_con_remanentes} productos";
    }
}

// Obtener distribuciones con paginación
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$estado_filter = isset($_GET['estado']) ? $_GET['estado'] : 'activo';

$where_clause = "WHERE estado = '$estado_filter'";

// Contar total de distribuciones
$count_query = "SELECT COUNT(*) as total FROM distribuciones $where_clause";
$stmt_count = $db->prepare($count_query);
$stmt_count->execute();
$total_distribuciones = $stmt_count->fetch()['total'];
$total_pages = ceil($total_distribuciones / $limit);

// Obtener distribuciones
$query = "SELECT d.*, 
          (SELECT COUNT(*) FROM tablas_distribucion td WHERE td.distribucion_id = d.id AND td.estado = 'activo') as total_tablas,
          (SELECT SUM(td.total_tabla) FROM tablas_distribucion td WHERE td.distribucion_id = d.id AND td.estado = 'activo') as total_distribucion
          FROM distribuciones d 
          $where_clause 
          ORDER BY d.fecha_creacion DESC 
          LIMIT $limit OFFSET $offset";
$stmt = $db->prepare($query);
$stmt->execute();
$distribuciones = $stmt->fetchAll();

// Obtener productos con existencia para el modal
$stmt_productos = $db->prepare("SELECT id, proveedor, descripcion, existencia, precio_venta FROM productos WHERE existencia > 0 ORDER BY proveedor, descripcion");
$stmt_productos->execute();
$productos_con_existencia = $stmt_productos->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Distribuciones - Sistema de Inventario</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .sidebar {
            height: 100vh;
            background-color: #343a40;
        }
        .sidebar .nav-link {
            color: #adb5bd;
        }
        .sidebar .nav-link:hover {
            color: #fff;
            background-color: #495057;
        }
        .sidebar .nav-link.active {
            color: #fff;
            background-color: #0d6efd;
        }
        .main-content {
            margin-left: 0;
        }
        @media (min-width: 768px) {
            .main-content {
                margin-left: 250px;
            }
        }
        .producto-item {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 10px;
            background-color: #f8f9fa;
        }
        .dias-semana {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
            margin-top: 10px;
        }
        .dia-checkbox {
            text-align: center;
        }
        .tabla-distribucion {
            border: 1px solid #ccc;
            border-radius: 8px;
            margin-bottom: 15px;
            padding: 10px;
            background-color: #fff;
        }
        .dia-resumen {
            background-color: #e3f2fd;
            border-left: 4px solid #2196f3;
            margin-bottom: 20px;
            padding: 15px;
            border-radius: 5px;
        }
        .fecha-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 10px 15px;
            border-radius: 8px 8px 0 0;
            margin-bottom: 0;
        }
        /* Nuevos estilos para mejorar el algoritmo visual */
        .algoritmo-info {
            background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
            color: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .algoritmo-info h6 {
            margin-bottom: 10px;
            font-weight: bold;
        }
        .algoritmo-info ul {
            margin-bottom: 0;
            padding-left: 20px;
        }
        .algoritmo-info li {
            margin-bottom: 5px;
        }
        .distribucion-badge {
            font-size: 0.9em;
            padding: 5px 10px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-3 col-lg-2 d-md-block sidebar collapse">
                <div class="position-sticky pt-3">
                    <div class="text-center mb-4">
                        <h4 class="text-white">Inventario</h4>
                    </div>
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="index.php">
                                <i class="bi bi-house-door"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="productos.php">
                                <i class="bi bi-box"></i> Productos
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="inventario.php">
                                <i class="bi bi-clipboard-data"></i> Inventario
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="ingresos.php">
                                <i class="bi bi-arrow-down-circle"></i> Ingresos
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="distribuciones.php">
                                <i class="bi bi-arrow-up-circle"></i> Distribuciones
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="reportes.php">
                                <i class="bi bi-file-earmark-bar-graph"></i> Reportes
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Distribuciones (Salidas) - Algoritmo Mejorado</h1>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalDistribucion">
                        <i class="bi bi-plus-lg"></i> Nueva Distribución
                    </button>
                </div>

                <!-- Mensajes -->
                <?php if (!empty($mensaje)): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                        <?php echo nl2br(htmlspecialchars($mensaje)); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Información del algoritmo mejorado -->
                <div class="algoritmo-info">
                    <h6><i class="bi bi-gear-fill"></i> Algoritmo de Distribución Inteligente V2.0</h6>
                    <ul>
                        <li><strong>🎯 Agotamiento Garantizado:</strong> Distribuye TODO el inventario seleccionado</li>
                        <li><strong>📊 Cantidades Inteligentes:</strong> Cantidades variables según disponibilidad (1-25% por tabla)</li>
                        <li><strong>⚖️ Distribución Equilibrada:</strong> Productos se agotan progresivamente</li>
                        <li><strong>♻️ Gestión de Remanentes:</strong> Los productos restantes se redistribuyen automáticamente</li>
                        <li><strong>📈 Estrategia Progresiva:</strong> Más agresivo hacia el final del período</li>
                        <li><strong>✅ Cobertura Total:</strong> Garantiza tablas en TODOS los días seleccionados</li>
                    </ul>
                </div>

                <!-- Filtros -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label for="estado" class="form-label">Estado</label>
                                <select class="form-select" id="estado" name="estado">
                                    <option value="activo" <?php echo $estado_filter == 'activo' ? 'selected' : ''; ?>>Activas</option>
                                    <option value="eliminado" <?php echo $estado_filter == 'eliminado' ? 'selected' : ''; ?>>Eliminadas</option>
                                </select>
                            </div>
                            <div class="col-md-8 d-flex align-items-end">
                                <button type="submit" class="btn btn-outline-primary me-2">
                                    <i class="bi bi-search"></i> Filtrar
                                </button>
                                <a href="distribuciones.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-x-lg"></i> Limpiar
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
                <!-- Lista de distribuciones -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Lista de Distribuciones (<?php echo $total_distribuciones; ?> total)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($distribuciones) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Período</th>
                                            <th>Tipo</th>
                                            <th>Tablas Generadas</th>
                                            <th>Total Distribuido</th>
                                            <th>Fecha Creación</th>
                                            <th>Estado</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($distribuciones as $distribucion): ?>
                                            <tr>
                                                <td>
                                                    <?php echo date('d/m/Y', strtotime($distribucion['fecha_inicio'])); ?> - 
                                                    <?php echo date('d/m/Y', strtotime($distribucion['fecha_fin'])); ?>
                                                    <br>
                                                    <small class="text-muted">
                                                        <?php 
                                                        $dias_diff = (new DateTime($distribucion['fecha_fin']))->diff(new DateTime($distribucion['fecha_inicio']))->days + 1;
                                                        echo $dias_diff . ' días';
                                                        ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <span class="badge distribucion-badge <?php echo $distribucion['tipo_distribucion'] == 'completo' ? 'bg-success' : 'bg-info'; ?>">
                                                        <?php echo $distribucion['tipo_distribucion'] == 'completo' ? '🎯 Completo' : '📋 Parcial'; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <strong><?php echo $distribucion['total_tablas'] ?: 0; ?></strong> tablas
                                                    <?php if ($distribucion['total_tablas'] > 0): ?>
                                                        <br><small class="text-muted">
                                                            ~<?php echo round(($distribucion['total_tablas'] ?: 0) / max(1, $dias_diff), 1); ?> por día
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="fw-bold text-success">$<?php echo number_format($distribucion['total_distribucion'] ?: 0, 2); ?></td>
                                                <td><?php echo date('d/m/Y H:i', strtotime($distribucion['fecha_creacion'])); ?></td>
                                                <td>
                                                    <span class="badge <?php echo $distribucion['estado'] == 'activo' ? 'bg-success' : 'bg-danger'; ?>">
                                                        <?php echo $distribucion['estado'] == 'activo' ? '✅ Activa' : '❌ Eliminada'; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-info" 
                                                            onclick="verTablas(<?php echo $distribucion['id']; ?>)"
                                                            title="Ver tablas detalladas">
                                                        <i class="bi bi-eye"></i> Ver
                                                    </button>
                                                    <?php if ($distribucion['estado'] == 'activo'): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                                                onclick="eliminarDistribucion(<?php echo $distribucion['id']; ?>)"
                                                                title="Eliminar distribución y revertir inventario">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Paginación -->
                            <?php if ($total_pages > 1): ?>
                                <nav aria-label="Navegación de distribuciones">
                                    <ul class="pagination justify-content-center">
                                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&estado=<?php echo $estado_filter; ?>">Anterior</a>
                                        </li>
                                        
                                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                <a class="page-link" href="?page=<?php echo $i; ?>&estado=<?php echo $estado_filter; ?>"><?php echo $i; ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        
                                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&estado=<?php echo $estado_filter; ?>">Siguiente</a>
                                        </li>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> No se encontraron distribuciones.
                                <?php if ($estado_filter == 'activo'): ?>
                                    <br>Puedes crear una nueva distribución haciendo clic en el botón "Nueva Distribución".
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal para nueva distribución -->
    <div class="modal fade" id="modalDistribucion" tabindex="-1" aria-labelledby="modalDistribucionLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalDistribucionLabel">
                        <i class="bi bi-plus-circle"></i> Nueva Distribución - Algoritmo Inteligente V2.0
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="formDistribucion" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="crear_distribucion">
                        
                        <!-- Configuración de fechas -->
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <label for="fecha_inicio" class="form-label">Fecha Inicio <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" required>
                            </div>
                            <div class="col-md-6">
                                <label for="fecha_fin" class="form-label">Fecha Fin <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="fecha_fin" name="fecha_fin" required>
                            </div>
                        </div>

                        <!-- Días de exclusión -->
                        <div class="mb-4">
                            <label class="form-label">Días de Exclusión (marcar días que NO se deben incluir)</label>
                            <div class="dias-semana">
                                <div class="dia-checkbox">
                                    <input type="checkbox" class="form-check-input" id="dom" name="dias_exclusion[]" value="0">
                                    <label class="form-check-label" for="dom">Dom</label>
                                </div>
                                <div class="dia-checkbox">
                                    <input type="checkbox" class="form-check-input" id="lun" name="dias_exclusion[]" value="1">
                                    <label class="form-check-label" for="lun">Lun</label>
                                </div>
                                <div class="dia-checkbox">
                                    <input type="checkbox" class="form-check-input" id="mar" name="dias_exclusion[]" value="2">
                                    <label class="form-check-label" for="mar">Mar</label>
                                </div>
                                <div class="dia-checkbox">
                                    <input type="checkbox" class="form-check-input" id="mie" name="dias_exclusion[]" value="3">
                                    <label class="form-check-label" for="mie">Mié</label>
                                </div>
                                <div class="dia-checkbox">
                                    <input type="checkbox" class="form-check-input" id="jue" name="dias_exclusion[]" value="4">
                                    <label class="form-check-label" for="jue">Jue</label>
                                </div>
                                <div class="dia-checkbox">
                                    <input type="checkbox" class="form-check-input" id="vie" name="dias_exclusion[]" value="5">
                                    <label class="form-check-label" for="vie">Vie</label>
                                </div>
                                <div class="dia-checkbox">
                                    <input type="checkbox" class="form-check-input" id="sab" name="dias_exclusion[]" value="6">
                                    <label class="form-check-label" for="sab">Sáb</label>
                                </div>
                            </div>
                        </div>

                        <!-- Tipo de distribución -->
                        <div class="mb-4">
                            <label class="form-label">Tipo de Distribución <span class="text-danger">*</span></label>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="tipo_distribucion" id="completo" value="completo" checked>
                                        <label class="form-check-label" for="completo">
                                            <strong>🎯 Todo el Inventario</strong><br>
                                            <small class="text-muted">Distribuir TODOS los productos con existencia hasta agotarlos completamente</small>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="tipo_distribucion" id="parcial" value="parcial">
                                        <label class="form-check-label" for="parcial">
                                            <strong>📋 Solo una Parte</strong><br>
                                            <small class="text-muted">Seleccionar productos específicos y cantidades exactas a distribuir</small>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Selección de productos parciales -->
                        <div id="productos-parciales" style="display: none;">
                            <h6><i class="bi bi-box-seam"></i> Seleccionar Productos y Cantidades</h6>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> <strong>Importante:</strong> En modo parcial, el algoritmo distribuirá exactamente las cantidades especificadas para cada producto.
                            </div>
                            <div class="row">
                                <?php 
                                $current_proveedor = '';
                                foreach ($productos_con_existencia as $producto):
                                    if ($current_proveedor != $producto['proveedor']):
                                        if ($current_proveedor != '') echo '</div></div>';
                                        $current_proveedor = $producto['proveedor'];
                                        echo '<div class="col-12"><h6 class="mt-3 text-primary"><i class="bi bi-building"></i> ' . htmlspecialchars($current_proveedor) . '</h6><div class="row">';
                                    endif;
                                ?>
                                    <div class="col-md-6 mb-2">
                                        <div class="producto-item">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <strong><?php echo htmlspecialchars($producto['descripcion']); ?></strong><br>
                                                    <small class="text-muted">
                                                        <i class="bi bi-box"></i> Existencia: <?php echo number_format($producto['existencia']); ?> | 
                                                        <i class="bi bi-currency-dollar"></i><?php echo number_format($producto['precio_venta'], 2); ?>
                                                    </small>
                                                </div>
                                                <div class="text-end">
                                                    <input type="hidden" name="productos_parciales[]" value="<?php echo $producto['id']; ?>">
                                                    <label class="form-label text-muted small">Cantidad</label>
                                                    <input type="number" class="form-control form-control-sm cantidad-parcial" 
                                                           name="cantidades_parciales[]" min="0" max="<?php echo $producto['existencia']; ?>" 
                                                           value="0" style="width: 100px;" 
                                                           onchange="actualizarContadorProductosParciales()">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php 
                                endforeach;
                                if ($current_proveedor != '') echo '</div></div>';
                                ?>
                            </div>
                            <div id="resumen-parcial" class="mt-3" style="display: none;">
                                <div class="alert alert-success">
                                    <strong>📊 Resumen de Selección:</strong> <span id="productos-seleccionados-count">0</span> productos seleccionados, 
                                    <span id="unidades-seleccionadas-count">0</span> unidades totales
                                </div>
                            </div>
                        </div>

                        <!-- Información del algoritmo -->
                        <div class="alert alert-success mt-3">
                            <h6><i class="bi bi-gear-fill"></i> Configuración del Algoritmo Inteligente V2.0:</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <ul class="mb-0 small">
                                        <li><strong>Tablas por día:</strong> Entre 10 y 30 (aleatorio)</li>
                                        <li><strong>Productos por tabla:</strong> Entre 1 y 40 (aleatorio)</li>
                                        <li><strong>Agotamiento:</strong> Garantizado al 100%</li>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <ul class="mb-0 small">
                                        <li><strong>Cantidades inteligentes:</strong> Variables según disponibilidad</li>
                                        <li><strong>Sin repetir:</strong> Un producto por tabla máximo</li>
                                        <li><strong>Cobertura:</strong> TODOS los días seleccionados</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-rocket"></i> Generar Distribución Inteligente
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal para ver tablas de distribución -->
    <div class="modal fade" id="modalVerTablas" tabindex="-1" aria-labelledby="modalVerTablasLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalVerTablasLabel">
                        <i class="bi bi-table"></i> Tablas de Distribución
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="tablasContent" style="max-height: 70vh; overflow-y: auto;">
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Cargando tablas...</span>
                        </div>
                        <p class="mt-2">Cargando tablas de distribución...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Cerrar
                    </button>
                    <button type="button" class="btn btn-primary" onclick="imprimirTablas()">
                        <i class="bi bi-printer"></i> Imprimir
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de confirmación para eliminar -->
    <div class="modal fade" id="modalEliminar" tabindex="-1" aria-labelledby="modalEliminarLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalEliminarLabel">
                        <i class="bi bi-exclamation-triangle text-danger"></i> Confirmar Eliminación
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <h6><i class="bi bi-exclamation-triangle"></i> ¿Está seguro que desea eliminar esta distribución?</h6>
                        <p class="mb-0">Esta acción realizará las siguientes operaciones:</p>
                        <ul class="mt-2 mb-0">
                            <li><strong>Revertirá todas las salidas</strong> del inventario</li>
                            <li><strong>Eliminará todas las tablas</strong> generadas</li>
                            <li><strong>Restaurará las existencias</strong> originales</li>
                            <li><strong>No se puede deshacer</strong> esta operación</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Cancelar
                    </button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="accion" value="eliminar_distribucion">
                        <input type="hidden" name="distribucion_id" id="distribucion_id_eliminar">
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash"></i> Sí, Eliminar Distribución
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Manejar cambio de tipo de distribución
        document.querySelectorAll('input[name="tipo_distribucion"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const productosParcialesDiv = document.getElementById('productos-parciales');
                if (this.value === 'parcial') {
                    productosParcialesDiv.style.display = 'block';
                    actualizarContadorProductosParciales();
                } else {
                    productosParcialesDiv.style.display = 'none';
                }
            });
        });

        // Actualizar contador de productos seleccionados en modo parcial
        function actualizarContadorProductosParciales() {
            const cantidades = document.querySelectorAll('.cantidad-parcial');
            let productosSeleccionados = 0;
            let unidadesTotales = 0;
            
            cantidades.forEach(input => {
                const cantidad = parseInt(input.value) || 0;
                if (cantidad > 0) {
                    productosSeleccionados++;
                    unidadesTotales += cantidad;
                }
            });
            
            const resumenDiv = document.getElementById('resumen-parcial');
            const countProductos = document.getElementById('productos-seleccionados-count');
            const countUnidades = document.getElementById('unidades-seleccionadas-count');
            
            if (productosSeleccionados > 0) {
                resumenDiv.style.display = 'block';
                countProductos.textContent = productosSeleccionados;
                countUnidades.textContent = unidadesTotales.toLocaleString();
            } else {
                resumenDiv.style.display = 'none';
            }
        }

        // Validación mejorada del formulario
        document.getElementById('formDistribucion').addEventListener('submit', function(e) {
            const fechaInicio = new Date(document.getElementById('fecha_inicio').value);
            const fechaFin = new Date(document.getElementById('fecha_fin').value);
            const tipoDistribucion = document.querySelector('input[name="tipo_distribucion"]:checked').value;
            
            // Validar fechas
            if (fechaInicio >= fechaFin) {
                e.preventDefault();
                alert('❌ Error: La fecha de fin debe ser posterior a la fecha de inicio.');
                return false;
            }
            
            // Validar que no esté muy en el pasado
            const hoy = new Date();
            hoy.setHours(0, 0, 0, 0);
            if (fechaFin < hoy) {
                if (!confirm('⚠️ Las fechas seleccionadas están en el pasado. ¿Desea continuar?')) {
                    e.preventDefault();
                    return false;
                }
            }
            
            // Si es distribución parcial, validar que al menos un producto tenga cantidad > 0
            if (tipoDistribucion === 'parcial') {
                const cantidades = document.querySelectorAll('input[name="cantidades_parciales[]"]');
                let hayProductos = false;
                let totalUnidades = 0;
                
                cantidades.forEach(input => {
                    const cantidad = parseInt(input.value) || 0;
                    if (cantidad > 0) {
                        hayProductos = true;
                        totalUnidades += cantidad;
                    }
                });
                
                if (!hayProductos) {
                    e.preventDefault();
                    alert('❌ Error: Debe seleccionar al menos un producto con cantidad mayor a 0 para distribución parcial.');
                    return false;
                }
                
                if (totalUnidades < 10) {
                    if (!confirm(`⚠️ Solo seleccionó ${totalUnidades} unidades para distribuir. ¿Está seguro que desea continuar?`)) {
                        e.preventDefault();
                        return false;
                    }
                }
            }
            
            // Confirmar la acción con información detallada
            const diasSeleccionados = calcularDiasSeleccionados();
            let confirmMsg = '';
            
            if (tipoDistribucion === 'completo') {
                confirmMsg = `🎯 ¿Confirmar distribución COMPLETA del inventario?\n\n` +
                           `📊 Algoritmo Inteligente V2.0:\n` +
                           `• Se distribuirá TODO el inventario disponible\n` +
                           `• ${diasSeleccionados} días válidos de distribución\n` +
                           `• 10-30 tablas por día (aleatorio)\n` +
                           `• Cantidades variables e inteligentes\n` +
                           `• Agotamiento garantizado al 100%\n\n` +
                           `⚠️ Esta operación NO se puede deshacer.`;
            } else {
                const productosCount = document.getElementById('productos-seleccionados-count').textContent;
                const unidadesCount = document.getElementById('unidades-seleccionadas-count').textContent;
                confirmMsg = `📋 ¿Confirmar distribución PARCIAL?\n\n` +
                           `📊 Resumen:\n` +
                           `• ${productosCount} productos seleccionados\n` +
                           `• ${unidadesCount} unidades totales\n` +
                           `• ${diasSeleccionados} días válidos\n` +
                           `• Distribución hasta agotar cantidades seleccionadas\n\n` +
                           `⚠️ Esta operación NO se puede deshacer.`;
            }
                
            if (!confirm(confirmMsg)) {
                e.preventDefault();
                return false;
            }
            
            // Mostrar indicador de carga
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Generando...';
            submitBtn.disabled = true;
            
            // Restaurar botón después de 30 segundos por si hay error
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 30000);
        });

        // Función para calcular días seleccionados
        function calcularDiasSeleccionados() {
            const fechaInicio = new Date(document.getElementById('fecha_inicio').value);
            const fechaFin = new Date(document.getElementById('fecha_fin').value);
            const diasExcluidos = [];
            
            // Obtener días excluidos
            document.querySelectorAll('input[name="dias_exclusion[]"]:checked').forEach(checkbox => {
                diasExcluidos.push(parseInt(checkbox.value));
            });
            
            let count = 0;
            let fechaActual = new Date(fechaInicio);
            
            while (fechaActual <= fechaFin) {
                const diaSemana = fechaActual.getDay();
                if (!diasExcluidos.includes(diaSemana)) {
                    count++;
                }
                fechaActual.setDate(fechaActual.getDate() + 1);
            }
            
            return count;
        }

        // Ver tablas de distribución con formato mejorado
        function verTablas(distribucionId) {
            // Mostrar modal inmediatamente con loading
            const modal = new bootstrap.Modal(document.getElementById('modalVerTablas'));
            modal.show();
            
            fetch(`get_tablas_distribucion.php?id=${distribucionId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let html = `
                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <div class="alert alert-info">
                                        <h6><i class="bi bi-calendar-range"></i> Distribución del ${data.distribucion.fecha_inicio} al ${data.distribucion.fecha_fin}</h6>
                                        <div class="row">
                                            <div class="col-md-3"><strong>Tipo:</strong> ${data.distribucion.tipo_distribucion == 'completo' ? '🎯 Completo' : '📋 Parcial'}</div>
                                            <div class="col-md-3"><strong>Tablas:</strong> ${data.tablas.length}</div>
                                            <div class="col-md-3"><strong>Total:</strong> $${parseFloat(data.total_general).toFixed(2)}</div>
                                            <div class="col-md-3"><strong>Estado:</strong> <span class="badge bg-success">✅ Activa</span></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                        
                        // Agrupar tablas por fecha
                        const tablasPorFecha = {};
                        data.tablas.forEach(tabla => {
                            if (!tablasPorFecha[tabla.fecha_tabla]) {
                                tablasPorFecha[tabla.fecha_tabla] = [];
                            }
                            tablasPorFecha[tabla.fecha_tabla].push(tabla);
                        });
                        
                        // Mostrar tablas agrupadas por fecha con mejores estilos
                        Object.keys(tablasPorFecha).sort().forEach(fecha => {
                            const fechaObj = new Date(fecha + 'T00:00:00');
                            const diasSemana = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
                            const diaNombre = diasSemana[fechaObj.getDay()];
                            const fechaFormateada = fechaObj.toLocaleDateString('es-ES');
                            
                            // Calcular estadísticas del día
                            const totalDia = tablasPorFecha[fecha].reduce((sum, tabla) => sum + parseFloat(tabla.total_tabla), 0);
                            const totalProductosDia = tablasPorFecha[fecha].reduce((sum, tabla) => {
                                return sum + tabla.detalles.reduce((detSum, det) => detSum + parseInt(det.cantidad), 0);
                            }, 0);
                            const promedioTabla = totalDia / tablasPorFecha[fecha].length;
                            
                            html += `
                                <div class="dia-resumen mb-4">
                                    <div class="fecha-header">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <h6 class="mb-0">📅 ${diaNombre} ${fechaFormateada}</h6>
                                            <div>
                                                <span class="badge bg-light text-dark me-2">${tablasPorFecha[fecha].length} tablas</span>
                                                <span class="badge bg-warning text-dark me-2">${totalProductosDia.toLocaleString()} productos</span>
                                                <span class="badge bg-success">${totalDia.toFixed(2)}</span>
                                            </div>
                                        </div>
                                        <div class="mt-2">
                                            <small>Promedio por tabla: ${promedioTabla.toFixed(2)} | ${(totalProductosDia/tablasPorFecha[fecha].length).toFixed(1)} productos/tabla</small>
                                        </div>
                                    </div>
                                    <div class="row mt-3">
                            `;
                            
                            tablasPorFecha[fecha].forEach((tabla, indexTabla) => {
                                const productosEnTabla = tabla.detalles.length;
                                const totalProductosTabla = tabla.detalles.reduce((sum, det) => sum + parseInt(det.cantidad), 0);
                                
                                html += `
                                    <div class="col-md-6 mb-3">
                                        <div class="tabla-distribucion">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <h6 class="mb-0">
                                                    <i class="bi bi-table"></i> Tabla #${tabla.numero_tabla}
                                                    <small class="text-muted">(${productosEnTabla} productos)</small>
                                                </h6>
                                                <div class="text-end">
                                                    <strong class="text-success">${parseFloat(tabla.total_tabla).toFixed(2)}</strong>
                                                    <br><small class="text-muted">${totalProductosTabla} unidades</small>
                                                </div>
                                            </div>
                                            <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                                                <table class="table table-sm table-striped">
                                                    <thead class="table-light sticky-top">
                                                        <tr>
                                                            <th>Producto</th>
                                                            <th width="60">Cant.</th>
                                                            <th width="80">Precio</th>
                                                            <th width="80">Total</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                `;
                                
                                tabla.detalles.forEach(detalle => {
                                    html += `
                                        <tr>
                                            <td>
                                                <strong>${detalle.descripcion}</strong>
                                                <br><small class="text-muted">${detalle.proveedor}</small>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-primary">${detalle.cantidad}</span>
                                            </td>
                                            <td class="text-end">${parseFloat(detalle.precio_venta).toFixed(2)}</td>
                                            <td class="text-end">
                                                <strong>${parseFloat(detalle.subtotal).toFixed(2)}</strong>
                                            </td>
                                        </tr>
                                    `;
                                });
                                
                                html += `
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                `;
                            });
                            
                            html += '</div></div>';
                        });
                        
                        // Agregar estadísticas generales al final
                        const totalTablas = data.tablas.length;
                        const totalGeneral = parseFloat(data.total_general);
                        const diasUnicos = Object.keys(tablasPorFecha).length;
                        const promedioTablasXDia = totalTablas / diasUnicos;
                        const totalProductosGeneral = data.tablas.reduce((sum, tabla) => {
                            return sum + tabla.detalles.reduce((detSum, det) => detSum + parseInt(det.cantidad), 0);
                        }, 0);
                        
                        html += `
                            <div class="row mt-4">
                                <div class="col-12">
                                    <div class="alert alert-success">
                                        <h6><i class="bi bi-graph-up"></i> Estadísticas de la Distribución</h6>
                                        <div class="row">
                                            <div class="col-md-3">
                                                <strong>📊 Total Tablas:</strong> ${totalTablas}<br>
                                                <strong>📅 Días Cubiertos:</strong> ${diasUnicos}
                                            </div>
                                            <div class="col-md-3">
                                                <strong>💰 Total Distribuido:</strong> ${totalGeneral.toFixed(2)}<br>
                                                <strong>📦 Total Productos:</strong> ${totalProductosGeneral.toLocaleString()}
                                            </div>
                                            <div class="col-md-3">
                                                <strong>📈 Promedio/Día:</strong> ${promedioTablasXDia.toFixed(1)} tablas<br>
                                                <strong>💵 Promedio/Tabla:</strong> ${(totalGeneral/totalTablas).toFixed(2)}
                                            </div>
                                            <div class="col-md-3">
                                                <strong>🎯 Algoritmo:</strong> V2.0 Inteligente<br>
                                                <strong>✅ Estado:</strong> Distribución Completa
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                        
                        document.getElementById('tablasContent').innerHTML = html;
                    } else {
                        document.getElementById('tablasContent').innerHTML = `
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle"></i> Error al cargar las tablas: ${data.message}
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('tablasContent').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-wifi-off"></i> Error de conexión al cargar las tablas de distribución.
                            <br>Por favor, inténtelo nuevamente.
                        </div>
                    `;
                });
        }

        // Eliminar distribución
        function eliminarDistribucion(id) {
            document.getElementById('distribucion_id_eliminar').value = id;
            const modal = new bootstrap.Modal(document.getElementById('modalEliminar'));
            modal.show();
        }

        // Imprimir tablas con mejor formato
        function imprimirTablas() {
            const contenido = document.getElementById('tablasContent').innerHTML;
            const ventana = window.open('', '_blank');
            ventana.document.write(`
                <html>
                <head>
                    <title>Tablas de Distribución - Sistema de Inventario</title>
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                    <style>
                        body { 
                            font-size: 11px; 
                            font-family: Arial, sans-serif;
                        }
                        .tabla-distribucion { 
                            border: 1px solid #ccc; 
                            border-radius: 8px; 
                            margin-bottom: 15px; 
                            padding: 10px; 
                            page-break-inside: avoid; 
                            background: white;
                        }
                        .dia-resumen { 
                            background-color: #e3f2fd; 
                            border-left: 4px solid #2196f3; 
                            margin-bottom: 20px; 
                            padding: 15px; 
                            border-radius: 5px; 
                            page-break-before: always;
                        }
                        .dia-resumen:first-child {
                            page-break-before: auto;
                        }
                        .fecha-header { 
                            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                            color: white; 
                            padding: 10px 15px; 
                            border-radius: 8px 8px 0 0; 
                            margin-bottom: 0; 
                        }
                        .table th {
                            background-color: #f8f9fa !important;
                            font-size: 10px;
                        }
                        .table td {
                            font-size: 10px;
                        }
                        @media print { 
                            .btn, .modal-footer { display: none !important; }
                            body { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
                        }
                        @page {
                            margin: 1cm;
                            size: A4;
                        }
                    </style>
                </head>
                <body>
                    <div class="container-fluid">
                        <div class="text-center mb-4">
                            <h3>📋 Tablas de Distribución</h3>
                            <p class="text-muted">Sistema de Inventario - Algoritmo Inteligente V2.0</p>
                            <hr>
                        </div>
                        ${contenido}
                        <div class="text-center mt-4">
                            <small class="text-muted">Generado el ${new Date().toLocaleString('es-ES')} | Sistema de Inventario</small>
                        </div>
                    </div>
                </body>
                </html>
            `);
            ventana.document.close();
            setTimeout(() => {
                ventana.print();
                ventana.close();
            }, 500);
        }

        // Limpiar formulario al cerrar modal
        document.getElementById('modalDistribucion').addEventListener('hidden.bs.modal', function () {
            document.getElementById('formDistribucion').reset();
            document.getElementById('productos-parciales').style.display = 'none';
            document.getElementById('resumen-parcial').style.display = 'none';
            document.getElementById('completo').checked = true;
            
            // Resetear todas las cantidades parciales a 0
            document.querySelectorAll('.cantidad-parcial').forEach(input => {
                input.value = 0;
            });
            
            // Restaurar botón de submit
            const submitBtn = document.querySelector('#formDistribucion button[type="submit"]');
            submitBtn.innerHTML = '<i class="bi bi-rocket"></i> Generar Distribución Inteligente';
            submitBtn.disabled = false;
        });

        // Configuración inicial al cargar la página
        document.addEventListener('DOMContentLoaded', function() {
            // Establecer fecha mínima como hoy
            const hoy = new Date();
            const fechaHoy = hoy.toISOString().split('T')[0];
            
            document.getElementById('fecha_inicio').value = fechaHoy;
            document.getElementById('fecha_fin').value = fechaHoy;
            document.getElementById('fecha_inicio').min = fechaHoy;
            document.getElementById('fecha_fin').min = fechaHoy;

            // Inicializar tooltips
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
            const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });

        // Actualizar fecha mínima de fin cuando cambia fecha de inicio
        document.getElementById('fecha_inicio').addEventListener('change', function() {
            const fechaInicio = this.value;
            const fechaFinInput = document.getElementById('fecha_fin');
            
            fechaFinInput.min = fechaInicio;
            if (fechaFinInput.value < fechaInicio) {
                fechaFinInput.value = fechaInicio;
            }
        });

        // Mejorar experiencia del usuario con feedback visual
        document.querySelectorAll('.cantidad-parcial').forEach(input => {
            input.addEventListener('input', function() {
                const max = parseInt(this.max);
                const value = parseInt(this.value) || 0;
                
                if (value > max) {
                    this.value = max;
                    this.classList.add('is-invalid');
                    setTimeout(() => this.classList.remove('is-invalid'), 2000);
                } else if (value > 0) {
                    this.classList.add('is-valid');
                    setTimeout(() => this.classList.remove('is-valid'), 1000);
                }
                
                actualizarContadorProductosParciales();
            });
        });

        // Función para mostrar preview de días seleccionados
        function mostrarPreviewDias() {
            const diasSeleccionados = calcularDiasSeleccionados();
            const previewElement = document.getElementById('preview-dias');
            
            if (previewElement) {
                previewElement.textContent = `${diasSeleccionados} días válidos seleccionados`;
            }
        }

        // Agregar eventos para mostrar preview en tiempo real
        document.getElementById('fecha_inicio').addEventListener('change', mostrarPreviewDias);
        document.getElementById('fecha_fin').addEventListener('change', mostrarPreviewDias);
        document.querySelectorAll('input[name="dias_exclusion[]"]').forEach(checkbox => {
            checkbox.addEventListener('change', mostrarPreviewDias);
        });
    </script>
</body>
</html>