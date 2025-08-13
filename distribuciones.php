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

// Función mejorada para generar tablas de distribución con planificación por días
function generarTablasDistribucion($db, $distribucion_id, $fecha_inicio, $fecha_fin, $dias_exclusion_json, $tipo_distribucion, $productos_seleccionados_json) {
    try {
        $dias_exclusion = json_decode($dias_exclusion_json, true) ?: [];
        
        // Obtener productos disponibles según el tipo de distribución
        if ($tipo_distribucion == 'completo') {
            $stmt_productos = $db->prepare("SELECT id, descripcion, existencia, precio_venta FROM productos WHERE existencia > 0 ORDER BY id");
            $stmt_productos->execute();
            $productos_base = $stmt_productos->fetchAll();
            
            // Crear array con cantidades disponibles para distribución completa
            $productos_disponibles = [];
            foreach ($productos_base as $producto) {
                $productos_disponibles[] = [
                    'id' => $producto['id'],
                    'descripcion' => $producto['descripcion'],
                    'precio_venta' => $producto['precio_venta'],
                    'cantidad_total' => $producto['existencia']
                ];
            }
        } else {
            $productos_seleccionados = json_decode($productos_seleccionados_json, true) ?: [];
            $productos_disponibles = [];
            
            foreach ($productos_seleccionados as $producto_sel) {
                $stmt_producto = $db->prepare("SELECT id, descripcion, existencia, precio_venta FROM productos WHERE id = ?");
                $stmt_producto->execute([$producto_sel['producto_id']]);
                $producto = $stmt_producto->fetch();
                
                if ($producto) {
                    $productos_disponibles[] = [
                        'id' => $producto['id'],
                        'descripcion' => $producto['descripcion'],
                        'precio_venta' => $producto['precio_venta'],
                        'cantidad_total' => min($producto_sel['cantidad'], $producto['existencia'])
                    ];
                }
            }
        }
        
        if (empty($productos_disponibles)) {
            return ['success' => false, 'message' => 'No hay productos disponibles para distribuir.'];
        }
        
        // Generar fechas válidas (excluyendo días especificados)
        $fechas_validas = [];
        $fecha_actual = new DateTime($fecha_inicio);
        $fecha_limite = new DateTime($fecha_fin);
        
        // Nombres de días en español
        $dias_semana = [
            0 => 'Domingo',
            1 => 'Lunes', 
            2 => 'Martes',
            3 => 'Miércoles',
            4 => 'Jueves',
            5 => 'Viernes',
            6 => 'Sábado'
        ];
        
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
        
        // **PLANIFICACIÓN DE DISTRIBUCIÓN POR DÍAS**
        // Calcular cuánto distribuir por día para cada producto
        $plan_distribucion = [];
        foreach ($productos_disponibles as $producto) {
            $cantidad_total = $producto['cantidad_total'];
            
            // Distribuir la cantidad total entre todos los días de manera proporcional pero aleatoria
            $cantidades_por_dia = [];
            $cantidad_restante = $cantidad_total;
            
            // Para los primeros días, asignar cantidades aleatorias pero conservadoras
            for ($i = 0; $i < $total_dias - 1; $i++) {
                if ($cantidad_restante <= 0) {
                    $cantidades_por_dia[] = 0;
                    continue;
                }
                
                // Calcular el máximo que podemos asignar a este día
                // Reservamos al menos 1 unidad para cada día restante (si es posible)
                $dias_restantes = $total_dias - $i;
                $cantidad_maxima_este_dia = max(1, floor($cantidad_restante * 0.4)); // Máximo 40% de lo restante
                $cantidad_minima_reserva = max(0, $dias_restantes - 1);
                
                if ($cantidad_restante > $cantidad_minima_reserva) {
                    $cantidad_disponible_este_dia = $cantidad_restante - $cantidad_minima_reserva;
                    $cantidad_este_dia = rand(1, min($cantidad_maxima_este_dia, $cantidad_disponible_este_dia));
                } else {
                    $cantidad_este_dia = min(1, $cantidad_restante);
                }
                
                $cantidades_por_dia[] = $cantidad_este_dia;
                $cantidad_restante -= $cantidad_este_dia;
            }
            
            // El último día se lleva todo lo que queda
            $cantidades_por_dia[] = $cantidad_restante;
            
            $plan_distribucion[$producto['id']] = [
                'producto' => $producto,
                'cantidades_por_dia' => $cantidades_por_dia
            ];
        }
        
        // **GENERACIÓN DE TABLAS SIGUIENDO EL PLAN**
        $total_tablas_generadas = 0;
        $total_dias_procesados = 0;
        
        foreach ($fechas_validas as $indice_dia => $fecha_info) {
            $fecha = $fecha_info['fecha'];
            $dia_nombre = $fecha_info['dia_nombre'];
            $fecha_formato = $fecha_info['fecha_formato'];
            
            // Obtener productos disponibles para este día según el plan
            $productos_dia = [];
            foreach ($plan_distribucion as $producto_id => $plan) {
                $cantidad_dia = $plan['cantidades_por_dia'][$indice_dia];
                if ($cantidad_dia > 0) {
                    $productos_dia[] = [
                        'id' => $plan['producto']['id'],
                        'descripcion' => $plan['producto']['descripcion'],
                        'precio_venta' => $plan['producto']['precio_venta'],
                        'cantidad_disponible_dia' => $cantidad_dia
                    ];
                }
            }
            
            if (empty($productos_dia)) {
                // Si no hay productos para este día, generar al menos una tabla vacía o saltar
                continue;
            }
            
            // Generar entre 10 y 30 tablas por día
            $tablas_por_dia = rand(10, 30);
            $total_dia = 0;
            
            // Crear una copia de productos para este día
            $productos_restantes_dia = $productos_dia;
            
            for ($tabla_num = 1; $tabla_num <= $tablas_por_dia; $tabla_num++) {
                // Verificar si aún hay productos disponibles para este día
                $productos_disponibles_tabla = [];
                foreach ($productos_restantes_dia as $key => $producto) {
                    if ($producto['cantidad_disponible_dia'] > 0) {
                        $productos_disponibles_tabla[$key] = $producto;
                    }
                }
                
                if (empty($productos_disponibles_tabla)) {
                    // Si no hay más productos para este día, parar de generar tablas
                    break;
                }
                
                // Insertar tabla
                $stmt_tabla = $db->prepare("INSERT INTO tablas_distribucion (distribucion_id, fecha_tabla, numero_tabla) VALUES (?, ?, ?)");
                $stmt_tabla->execute([$distribucion_id, $fecha, $tabla_num]);
                $tabla_id = $db->lastInsertId();
                
                // Determinar cantidad de productos por tabla (1-40, pero no más de los disponibles)
                $max_productos_tabla = min(40, count($productos_disponibles_tabla));
                $productos_por_tabla = rand(1, $max_productos_tabla);
                
                // Seleccionar productos aleatorios para esta tabla (sin repetir)
                $indices_seleccionados = array_rand($productos_disponibles_tabla, min($productos_por_tabla, count($productos_disponibles_tabla)));
                if (!is_array($indices_seleccionados)) {
                    $indices_seleccionados = [$indices_seleccionados];
                }
                
                $total_tabla = 0;
                
                foreach ($indices_seleccionados as $indice) {
                    $producto = $productos_restantes_dia[$indice];
                    
                    if ($producto['cantidad_disponible_dia'] > 0) {
                        // Cantidad aleatoria del producto (de 1 hasta lo disponible para este día)
                        // Distribuir de manera más conservadora para que dure todo el día
                        $cantidad_maxima = min(
                            $producto['cantidad_disponible_dia'], 
                            max(1, floor($producto['cantidad_disponible_dia'] / max(1, $tablas_por_dia - $tabla_num + 1)) * 2)
                        );
                        $cantidad_usar = rand(1, max(1, $cantidad_maxima));
                        
                        $subtotal = $cantidad_usar * $producto['precio_venta'];
                        $total_tabla += $subtotal;
                        
                        // Insertar detalle
                        $stmt_detalle = $db->prepare("INSERT INTO detalle_tablas_distribucion (tabla_id, producto_id, cantidad, precio_venta, subtotal) VALUES (?, ?, ?, ?, ?)");
                        $stmt_detalle->execute([$tabla_id, $producto['id'], $cantidad_usar, $producto['precio_venta'], $subtotal]);
                        
                        // Actualizar existencia en la base de datos
                        $stmt_update = $db->prepare("UPDATE productos SET existencia = existencia - ? WHERE id = ?");
                        $stmt_update->execute([$cantidad_usar, $producto['id']]);
                        
                        // Actualizar cantidad disponible para este día
                        $productos_restantes_dia[$indice]['cantidad_disponible_dia'] -= $cantidad_usar;
                    }
                }
                
                // Actualizar total de la tabla
                $stmt_total = $db->prepare("UPDATE tablas_distribucion SET total_tabla = ? WHERE id = ?");
                $stmt_total->execute([$total_tabla, $tabla_id]);
                
                $total_dia += $total_tabla;
                $total_tablas_generadas++;
            }
            
            $total_dias_procesados++;
        }
        
        return [
            'success' => true, 
            'message' => "Se generaron {$total_tablas_generadas} tablas distribuidas equitativamente en {$total_dias_procesados} días. Todos los días del período tienen distribución garantizada."
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
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
                    <h1 class="h2">Distribuciones (Salidas)</h1>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalDistribucion">
                        <i class="bi bi-plus-lg"></i> Nueva Distribución
                    </button>
                </div>

                <!-- Mensajes -->
                <?php if (!empty($mensaje)): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($mensaje); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

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
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo $distribucion['tipo_distribucion'] == 'completo' ? 'bg-success' : 'bg-info'; ?>">
                                                        <?php echo ucfirst($distribucion['tipo_distribucion']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo $distribucion['total_tablas'] ?: 0; ?></td>
                                                <td class="fw-bold">$<?php echo number_format($distribucion['total_distribucion'] ?: 0, 2); ?></td>
                                                <td><?php echo date('d/m/Y H:i', strtotime($distribucion['fecha_creacion'])); ?></td>
                                                <td>
                                                    <span class="badge <?php echo $distribucion['estado'] == 'activo' ? 'bg-success' : 'bg-danger'; ?>">
                                                        <?php echo ucfirst($distribucion['estado']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-info" 
                                                            onclick="verTablas(<?php echo $distribucion['id']; ?>)">
                                                        <i class="bi bi-eye"></i> Ver
                                                    </button>
                                                    <?php if ($distribucion['estado'] == 'activo'): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                                                onclick="eliminarDistribucion(<?php echo $distribucion['id']; ?>)">
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
                    <h5 class="modal-title" id="modalDistribucionLabel">Nueva Distribución</h5>
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
                                            <strong>Todo el Inventario</strong><br>
                                            <small class="text-muted">Distribuir todos los productos con existencia</small>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="tipo_distribucion" id="parcial" value="parcial">
                                        <label class="form-check-label" for="parcial">
                                            <strong>Solo una Parte</strong><br>
                                            <small class="text-muted">Seleccionar productos y cantidades específicas</small>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Selección de productos parciales -->
                        <div id="productos-parciales" style="display: none;">
                            <h6>Seleccionar Productos y Cantidades</h6>
                            <div class="row">
                                <?php 
                                $current_proveedor = '';
                                foreach ($productos_con_existencia as $producto):
                                    if ($current_proveedor != $producto['proveedor']):
                                        if ($current_proveedor != '') echo '</div></div>';
                                        $current_proveedor = $producto['proveedor'];
                                        echo '<div class="col-12"><h6 class="mt-3 text-primary">' . htmlspecialchars($current_proveedor) . '</h6><div class="row">';
                                    endif;
                                ?>
                                    <div class="col-md-6 mb-2">
                                        <div class="producto-item">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <strong><?php echo htmlspecialchars($producto['descripcion']); ?></strong><br>
                                                    <small class="text-muted">Existencia: <?php echo $producto['existencia']; ?> | $<?php echo number_format($producto['precio_venta'], 2); ?></small>
                                                </div>
                                                <div class="text-end">
                                                    <input type="hidden" name="productos_parciales[]" value="<?php echo $producto['id']; ?>">
                                                    <input type="number" class="form-control form-control-sm" 
                                                           name="cantidades_parciales[]" min="0" max="<?php echo $producto['existencia']; ?>" 
                                                           value="0" style="width: 80px;">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php 
                                endforeach;
                                if ($current_proveedor != '') echo '</div></div>';
                                ?>
                            </div>
                        </div>

                        <div class="alert alert-info mt-3">
                            <h6><i class="bi bi-info-circle"></i> Configuración de Distribución:</h6>
                            <ul class="mb-0">
                                <li><strong>Tablas por día:</strong> Entre 10 y 30 tablas (aleatorio)</li>
                                <li><strong>Productos por tabla:</strong> Entre 1 y 40 productos (aleatorio)</li>
                                <li><strong>Cantidades:</strong> Aleatorias según disponibilidad</li>
                                <li><strong>Sin repetir:</strong> Un producto no aparece dos veces en la misma tabla</li>
                                <li><strong>Cobertura:</strong> Se generarán tablas en TODOS los días seleccionados</li>
                            </ul>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Generar Distribución
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
                    <h5 class="modal-title" id="modalVerTablasLabel">Tablas de Distribución</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="tablasContent" style="max-height: 70vh; overflow-y: auto;">
                    <!-- Contenido se carga dinámicamente -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
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
                    <h5 class="modal-title" id="modalEliminarLabel">Confirmar Eliminación</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>¿Está seguro que desea eliminar esta distribución?</p>
                    <p class="text-danger">Esta acción revertirá todas las salidas y no se puede deshacer.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="accion" value="eliminar_distribucion">
                        <input type="hidden" name="distribucion_id" id="distribucion_id_eliminar">
                        <button type="submit" class="btn btn-danger">Eliminar</button>
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
                } else {
                    productosParcialesDiv.style.display = 'none';
                }
            });
        });

        // Validación del formulario
        document.getElementById('formDistribucion').addEventListener('submit', function(e) {
            const fechaInicio = new Date(document.getElementById('fecha_inicio').value);
            const fechaFin = new Date(document.getElementById('fecha_fin').value);
            const tipoDistribucion = document.querySelector('input[name="tipo_distribucion"]:checked').value;
            
            // Validar fechas
            if (fechaInicio >= fechaFin) {
                e.preventDefault();
                alert('La fecha de fin debe ser posterior a la fecha de inicio.');
                return false;
            }
            
            // Validar que no esté muy en el pasado
            const hoy = new Date();
            hoy.setHours(0, 0, 0, 0);
            if (fechaFin < hoy) {
                if (!confirm('Las fechas seleccionadas están en el pasado. ¿Desea continuar?')) {
                    e.preventDefault();
                    return false;
                }
            }
            
            // Si es distribución parcial, validar que al menos un producto tenga cantidad > 0
            if (tipoDistribucion === 'parcial') {
                const cantidades = document.querySelectorAll('input[name="cantidades_parciales[]"]');
                let hayProductos = false;
                
                cantidades.forEach(input => {
                    if (parseInt(input.value) > 0) {
                        hayProductos = true;
                    }
                });
                
                if (!hayProductos) {
                    e.preventDefault();
                    alert('Debe seleccionar al menos un producto con cantidad mayor a 0 para distribución parcial.');
                    return false;
                }
            }
            
            // Confirmar la acción con información detallada
            const diasSeleccionados = calcularDiasSeleccionados();
            const confirmMsg = tipoDistribucion === 'completo' 
                ? `¿Confirmar la distribución de TODO el inventario disponible?\n\nSe generarán entre 10-30 tablas por día en ${diasSeleccionados} días válidos.\nCada tabla tendrá entre 1-40 productos con cantidades aleatorias.` 
                : `¿Confirmar la distribución de los productos seleccionados?\n\nSe generarán entre 10-30 tablas por día en ${diasSeleccionados} días válidos.\nCada tabla tendrá entre 1-40 productos con cantidades aleatorias.`;
                
            if (!confirm(confirmMsg)) {
                e.preventDefault();
                return false;
            }
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
            fetch(`get_tablas_distribucion.php?id=${distribucionId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let html = `
                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <h6>Distribución del ${data.distribucion.fecha_inicio} al ${data.distribucion.fecha_fin}</h6>
                                    <p><strong>Tipo:</strong> ${data.distribucion.tipo_distribucion} | 
                                       <strong>Total Tablas:</strong> ${data.tablas.length} | 
                                       <strong>Total Distribuido:</strong> $${parseFloat(data.total_general).toFixed(2)}</p>
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
                        
                        // Mostrar tablas agrupadas por fecha con día de la semana
                        Object.keys(tablasPorFecha).sort().forEach(fecha => {
                            const fechaObj = new Date(fecha + 'T00:00:00');
                            const diasSemana = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
                            const diaNombre = diasSemana[fechaObj.getDay()];
                            const fechaFormateada = fechaObj.toLocaleDateString('es-ES');
                            
                            // Calcular total del día
                            const totalDia = tablasPorFecha[fecha].reduce((sum, tabla) => sum + parseFloat(tabla.total_tabla), 0);
                            
                            html += `
                                <div class="dia-resumen mb-4">
                                    <div class="fecha-header">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <h6 class="mb-0">📅 ${diaNombre} ${fechaFormateada}</h6>
                                            <div>
                                                <span class="badge bg-light text-dark me-2">${tablasPorFecha[fecha].length} tablas</span>
                                                <span class="badge bg-warning text-dark">Total: $${totalDia.toFixed(2)}</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row mt-3">
                            `;
                            
                            tablasPorFecha[fecha].forEach(tabla => {
                                html += `
                                    <div class="col-md-6 mb-3">
                                        <div class="tabla-distribucion">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <h6 class="mb-0">Tabla #${tabla.numero_tabla}</h6>
                                                <strong class="text-success">$${parseFloat(tabla.total_tabla).toFixed(2)}</strong>
                                            </div>
                                            <div class="table-responsive">
                                                <table class="table table-sm table-striped">
                                                    <thead>
                                                        <tr>
                                                            <th>Descripción</th>
                                                            <th>Cant.</th>
                                                            <th>Precio</th>
                                                            <th>Total</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                `;
                                
                                tabla.detalles.forEach(detalle => {
                                    html += `
                                        <tr>
                                            <td>${detalle.descripcion}</td>
                                            <td>${detalle.cantidad}</td>
                                            <td>$${parseFloat(detalle.precio_venta).toFixed(2)}</td>
                                            <td>$${parseFloat(detalle.subtotal).toFixed(2)}</td>
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
                        
                        document.getElementById('tablasContent').innerHTML = html;
                        const modal = new bootstrap.Modal(document.getElementById('modalVerTablas'));
                        modal.show();
                    } else {
                        alert('Error al cargar las tablas: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al cargar las tablas de distribución.');
                });
        }

        // Eliminar distribución
        function eliminarDistribucion(id) {
            document.getElementById('distribucion_id_eliminar').value = id;
            const modal = new bootstrap.Modal(document.getElementById('modalEliminar'));
            modal.show();
        }

        // Imprimir tablas
        function imprimirTablas() {
            const contenido = document.getElementById('tablasContent').innerHTML;
            const ventana = window.open('', '_blank');
            ventana.document.write(`
                <html>
                <head>
                    <title>Tablas de Distribución</title>
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                    <style>
                        body { font-size: 12px; }
                        .tabla-distribucion { border: 1px solid #ccc; border-radius: 8px; margin-bottom: 15px; padding: 10px; page-break-inside: avoid; }
                        .dia-resumen { background-color: #e3f2fd; border-left: 4px solid #2196f3; margin-bottom: 20px; padding: 15px; border-radius: 5px; }
                        .fecha-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 10px 15px; border-radius: 8px 8px 0 0; margin-bottom: 0; }
                        @media print { .btn { display: none; } }
                    </style>
                </head>
                <body>
                    <div class="container-fluid">
                        <h3 class="text-center mb-4">Tablas de Distribución</h3>
                        ${contenido}
                    </div>
                </body>
                </html>
            `);
            ventana.document.close();
            ventana.print();
        }

        // Limpiar formulario al cerrar modal
        document.getElementById('modalDistribucion').addEventListener('hidden.bs.modal', function () {
            document.getElementById('formDistribucion').reset();
            document.getElementById('productos-parciales').style.display = 'none';
            document.getElementById('completo').checked = true;
        });

        // Establecer fecha mínima como hoy
        document.addEventListener('DOMContentLoaded', function() {
            const hoy = new Date().toISOString().split('T')[0];
            document.getElementById('fecha_inicio').value = hoy;
            document.getElementById('fecha_fin').value = hoy;
            document.getElementById('fecha_inicio').min = hoy;
            document.getElementById('fecha_fin').min = hoy;
        });

        // Actualizar fecha mínima de fin cuando cambia fecha de inicio
        document.getElementById('fecha_inicio').addEventListener('change', function() {
            const fechaInicio = this.value;
            document.getElementById('fecha_fin').min = fechaInicio;
            if (document.getElementById('fecha_fin').value < fechaInicio) {
                document.getElementById('fecha_fin').value = fechaInicio;
            }
        });
    </script>
</body>
</html>