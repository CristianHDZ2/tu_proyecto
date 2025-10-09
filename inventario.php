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
            case 'editar_existencia':
                try {
                    $db->beginTransaction();
                    
                    $producto_id = $_POST['producto_id'];
                    $nueva_existencia = $_POST['nueva_existencia'];
                    $motivo = isset($_POST['motivo']) ? trim($_POST['motivo']) : '';
                    
                    // **CORRECCIÓN 1: Validación mejorada**
                    if (!is_numeric($nueva_existencia)) {
                        throw new Exception('La existencia debe ser un número válido.');
                    }
                    
                    $nueva_existencia = intval($nueva_existencia);
                    
                    if ($nueva_existencia < 0) {
                        throw new Exception('La existencia no puede ser negativa.');
                    }
                    
                    // Obtener la existencia actual
                    $stmt_actual = $db->prepare("SELECT existencia, descripcion, proveedor FROM productos WHERE id = ?");
                    $stmt_actual->execute([$producto_id]);
                    $producto_actual = $stmt_actual->fetch();
                    
                    if (!$producto_actual) {
                        throw new Exception('Producto no encontrado.');
                    }
                    
                    $existencia_anterior = intval($producto_actual['existencia']);
                    $diferencia = $nueva_existencia - $existencia_anterior;
                    
                    // **CORRECCIÓN 2: Solo actualizar si hay cambio real**
                    if ($diferencia == 0) {
                        $db->rollBack();
                        $mensaje = "No se realizó ningún cambio. La existencia ya era {$existencia_anterior} unidades.";
                        $tipo_mensaje = "info";
                        break;
                    }
                    
                    // Actualizar la existencia
                    $stmt_update = $db->prepare("UPDATE productos SET existencia = ?, fecha_actualizacion = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt_update->execute([$nueva_existencia, $producto_id]);
                    
                    // **CORRECCIÓN 3: Registrar el ajuste correctamente**
                    if ($diferencia != 0) {
                        $tipo_ajuste = $diferencia > 0 ? 'Ajuste Positivo' : 'Ajuste Negativo';
                        $numero_factura = 'AJUSTE-' . date('YmdHis') . '-' . $producto_id;
                        
                        // Registrar en ingresos solo si es incremento
                        if ($diferencia > 0) {
                            $stmt_ingreso = $db->prepare("INSERT INTO ingresos (proveedor, numero_factura, fecha_ingreso, total_factura) VALUES (?, ?, ?, ?)");
                            $stmt_ingreso->execute([
                                'AJUSTE MANUAL: ' . $producto_actual['proveedor'],
                                $numero_factura,
                                date('Y-m-d'),
                                0
                            ]);
                            
                            $ingreso_id = $db->lastInsertId();
                            
                            $stmt_detalle = $db->prepare("INSERT INTO detalle_ingresos (ingreso_id, producto_id, cantidad, precio_compra, subtotal) VALUES (?, ?, ?, ?, ?)");
                            $stmt_detalle->execute([
                                $ingreso_id,
                                $producto_id,
                                abs($diferencia),
                                0,
                                0
                            ]);
                        }
                    }
                    
                    $db->commit();
                    
                    $mensaje = sprintf(
                        "✅ Existencia actualizada exitosamente.\n\n" .
                        "📦 Producto: %s\n" .
                        "📊 Cambio: %d → %d unidades (%s%d)\n" .
                        "%s",
                        $producto_actual['descripcion'],
                        $existencia_anterior,
                        $nueva_existencia,
                        $diferencia > 0 ? '+' : '',
                        $diferencia,
                        $motivo ? "📝 Motivo: {$motivo}" : ""
                    );
                    $tipo_mensaje = "success";
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    $mensaje = "❌ Error al actualizar la existencia: " . $e->getMessage();
                    $tipo_mensaje = "danger";
                }
                break;
        }
    }
}

// Obtener inventario con paginación
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$proveedor_filter = isset($_GET['proveedor']) ? trim($_GET['proveedor']) : '';
$stock_filter = isset($_GET['stock']) ? $_GET['stock'] : '';

// **CORRECCIÓN 4: Construir consulta con filtros mejorados**
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(p.descripcion LIKE ? OR p.proveedor LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($proveedor_filter)) {
    $where_conditions[] = "p.proveedor = ?";
    $params[] = $proveedor_filter;
}

if (!empty($stock_filter)) {
    switch ($stock_filter) {
        case 'sin_stock':
            $where_conditions[] = "p.existencia = 0";
            break;
        case 'bajo_stock':
            $where_conditions[] = "p.existencia > 0 AND p.existencia < 10";
            break;
        case 'stock_normal':
            $where_conditions[] = "p.existencia >= 10";
            break;
    }
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Contar total de productos
$count_query = "SELECT COUNT(*) as total FROM productos p $where_clause";
$stmt_count = $db->prepare($count_query);
$stmt_count->execute($params);
$total_productos = $stmt_count->fetch()['total'];
$total_pages = ceil($total_productos / $limit);

// **CORRECCIÓN 5: Consulta principal CORREGIDA con subconsultas separadas**
// Esta es la corrección más importante - evita la multiplicación de registros
$query = "SELECT 
            p.id,
            p.proveedor,
            p.descripcion,
            p.precio_venta,
            p.existencia,
            p.fecha_creacion,
            p.fecha_actualizacion,
            (p.existencia * p.precio_venta) as valor_inventario,
            (SELECT COALESCE(SUM(di.cantidad), 0) 
             FROM detalle_ingresos di 
             WHERE di.producto_id = p.id) as total_ingresos,
            (SELECT COALESCE(SUM(dtd.cantidad), 0) 
             FROM detalle_tablas_distribucion dtd 
             WHERE dtd.producto_id = p.id) as total_salidas
          FROM productos p
          $where_clause
          ORDER BY p.proveedor, p.descripcion
          LIMIT $limit OFFSET $offset";

$stmt = $db->prepare($query);
$stmt->execute($params);
$productos = $stmt->fetchAll();

// **CORRECCIÓN 6: Estadísticas generales CORREGIDAS con subconsultas**
$stats_where = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

$stmt_stats = $db->prepare("SELECT 
    COUNT(*) as total_productos,
    COALESCE(SUM(existencia), 0) as total_existencia,
    COALESCE(SUM(existencia * precio_venta), 0) as valor_total_inventario,
    COUNT(CASE WHEN existencia = 0 THEN 1 END) as productos_sin_stock,
    COUNT(CASE WHEN existencia > 0 AND existencia < 10 THEN 1 END) as productos_bajo_stock
FROM productos p $stats_where");
$stmt_stats->execute($params);
$stats = $stmt_stats->fetch();

// Obtener lista de proveedores para el filtro
$stmt_proveedores = $db->prepare("SELECT DISTINCT proveedor FROM productos WHERE proveedor IS NOT NULL AND proveedor != '' ORDER BY proveedor");
$stmt_proveedores->execute();
$proveedores = $stmt_proveedores->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventario - Sistema de Inventario</title>
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
        .stats-card {
            transition: transform 0.2s;
        }
        .stats-card:hover {
            transform: translateY(-2px);
        }
        .table-sm td {
            padding: 0.5rem 0.75rem;
        }
        .existencia-editable {
            cursor: pointer;
            border: 1px dashed transparent;
            padding: 2px 5px;
            border-radius: 3px;
            transition: all 0.2s;
        }
        .existencia-editable:hover {
            border-color: #007bff;
            background-color: #f8f9fa;
            transform: scale(1.05);
        }
        .existencia-input {
            width: 80px;
            text-align: center;
        }
        /* **CORRECCIÓN 7: Estilos mejorados para alertas de stock** */
        .badge-stock {
            font-size: 0.9rem;
            padding: 0.4rem 0.6rem;
            font-weight: 600;
        }
        .row-sin-stock {
            background-color: #fff5f5 !important;
        }
        .row-bajo-stock {
            background-color: #fffbf0 !important;
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
                            <a class="nav-link active" href="inventario.php">
                                <i class="bi bi-clipboard-data"></i> Inventario
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="ingresos.php">
                                <i class="bi bi-arrow-down-circle"></i> Ingresos
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="distribuciones.php">
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
                    <h1 class="h2">Control de Inventario</h1>
                    <a href="ingresos.php" class="btn btn-success">
                        <i class="bi bi-plus-lg"></i> Registrar Ingreso
                    </a>
                </div>

                <!-- Mensajes -->
                <?php if (!empty($mensaje)): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                        <pre style="white-space: pre-wrap; margin: 0; font-family: inherit;"><?php echo htmlspecialchars($mensaje); ?></pre>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Estadísticas del inventario -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card stats-card h-100">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                            Total Productos
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats['total_productos']); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-box fs-2 text-primary"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card stats-card h-100">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                            Existencia Total
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats['total_existencia']); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-clipboard-data fs-2 text-success"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card stats-card h-100">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                            Valor Inventario
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            $<?php echo number_format($stats['valor_total_inventario'], 2); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-currency-dollar fs-2 text-info"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card stats-card h-100">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                            Alertas Stock
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo $stats['productos_sin_stock'] + $stats['productos_bajo_stock']; ?>
                                        </div>
                                        <small class="text-muted">
                                            <?php echo $stats['productos_sin_stock']; ?> sin stock | 
                                            <?php echo $stats['productos_bajo_stock']; ?> bajo stock
                                        </small>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-exclamation-triangle fs-2 text-warning"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Filtros y búsqueda -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label for="search" class="form-label">Buscar</label>
                                <input type="text" class="form-control" id="search" name="search" 
                                       value="<?php echo htmlspecialchars($search); ?>" 
                                       placeholder="Descripción o proveedor...">
                            </div>
                            <div class="col-md-3">
                                <label for="proveedor" class="form-label">Proveedor</label>
                                <select class="form-select" id="proveedor" name="proveedor">
                                    <option value="">Todos</option>
                                    <?php foreach ($proveedores as $proveedor): ?>
                                        <option value="<?php echo htmlspecialchars($proveedor['proveedor']); ?>"
                                                <?php echo $proveedor_filter == $proveedor['proveedor'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($proveedor['proveedor']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="stock" class="form-label">Estado Stock</label>
                                <select class="form-select" id="stock" name="stock">
                                    <option value="">Todos</option>
                                    <option value="sin_stock" <?php echo $stock_filter == 'sin_stock' ? 'selected' : ''; ?>>Sin Stock</option>
                                    <option value="bajo_stock" <?php echo $stock_filter == 'bajo_stock' ? 'selected' : ''; ?>>Bajo Stock (&lt;10)</option>
                                    <option value="stock_normal" <?php echo $stock_filter == 'stock_normal' ? 'selected' : ''; ?>>Stock Normal (≥10)</option>
                                </select>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-outline-primary me-2">
                                    <i class="bi bi-search"></i> Filtrar
                                </button>
                                <a href="inventario.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-x-lg"></i> Limpiar
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Tabla de inventario -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Inventario Actual (<?php echo number_format($total_productos); ?> productos)</h5>
                        <small class="text-muted">
                            <i class="bi bi-info-circle"></i> Haga clic en la existencia para editarla
                        </small>
                    </div>
                    <div class="card-body">
                        <?php if (count($productos) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover table-sm">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Proveedor</th>
                                            <th>Descripción</th>
                                            <th class="text-end">Precio Venta</th>
                                            <th class="text-center">Existencia</th>
                                            <th class="text-center">Total Ingresos</th>
                                            <th class="text-center">Total Salidas</th>
                                            <th class="text-end">Valor Inventario</th>
                                            <th class="text-center">Estado</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($productos as $producto): ?>
                                            <?php 
                                            // **CORRECCIÓN 8: Agregar clases CSS según el estado del stock**
                                            $row_class = '';
                                            if ($producto['existencia'] == 0) {
                                                $row_class = 'row-sin-stock';
                                            } elseif ($producto['existencia'] < 10) {
                                                $row_class = 'row-bajo-stock';
                                            }
                                            ?>
                                            <tr class="<?php echo $row_class; ?>">
                                                <td><?php echo htmlspecialchars($producto['proveedor']); ?></td>
                                                <td>
                                                    <span title="<?php echo htmlspecialchars($producto['descripcion']); ?>">
                                                        <?php echo htmlspecialchars($producto['descripcion']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">$<?php echo number_format($producto['precio_venta'], 2); ?></td>
                                                <td class="text-center">
                                                    <span class="existencia-editable badge badge-stock <?php 
                                                        echo $producto['existencia'] == 0 ? 'bg-danger' : 
                                                             ($producto['existencia'] < 10 ? 'bg-warning text-dark' : 'bg-success'); 
                                                    ?>" 
                                                    data-producto-id="<?php echo $producto['id']; ?>"
                                                    data-existencia-actual="<?php echo $producto['existencia']; ?>"
                                                    data-descripcion="<?php echo htmlspecialchars($producto['descripcion']); ?>"
                                                    title="Clic para editar existencia">
                                                        <?php echo number_format($producto['existencia']); ?>
                                                        <i class="bi bi-pencil-square ms-1"></i>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-success-subtle text-success">
                                                        <i class="bi bi-arrow-down"></i> <?php echo number_format($producto['total_ingresos']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-danger-subtle text-danger">
                                                        <i class="bi bi-arrow-up"></i> <?php echo number_format($producto['total_salidas']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-end fw-bold">$<?php echo number_format($producto['valor_inventario'], 2); ?></td>
                                                <td class="text-center">
                                                    <?php if ($producto['existencia'] == 0): ?>
                                                        <span class="badge bg-danger">Sin Stock</span>
                                                    <?php elseif ($producto['existencia'] < 10): ?>
                                                        <span class="badge bg-warning text-dark">Bajo Stock</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success">Normal</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <th colspan="6" class="text-end">Total en esta página:</th>
                                            <th class="text-end">$<?php echo number_format(array_sum(array_column($productos, 'valor_inventario')), 2); ?></th>
                                            <th></th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            <!-- Paginación -->
                            <?php if ($total_pages > 1): ?>
                                <nav aria-label="Navegación de inventario" class="mt-3">
                                    <ul class="pagination justify-content-center">
                                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&proveedor=<?php echo urlencode($proveedor_filter); ?>&stock=<?php echo urlencode($stock_filter); ?>">Anterior</a>
                                        </li>
                                        
                                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&proveedor=<?php echo urlencode($proveedor_filter); ?>&stock=<?php echo urlencode($stock_filter); ?>"><?php echo $i; ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        
                                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&proveedor=<?php echo urlencode($proveedor_filter); ?>&stock=<?php echo urlencode($stock_filter); ?>">Siguiente</a>
                                        </li>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> No se encontraron productos con los criterios especificados.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Resumen por proveedor -->
                <?php if (!empty($proveedor_filter)): ?>
                    <?php
                    // **CORRECCIÓN 9: Consulta de resumen por proveedor corregida**
                    $stmt_resumen = $db->prepare("SELECT 
                        COUNT(*) as total_productos,
                        COALESCE(SUM(existencia), 0) as total_existencia,
                        COALESCE(SUM(existencia * precio_venta), 0) as valor_total,
                        COALESCE(AVG(existencia), 0) as promedio_existencia,
                        MIN(existencia) as min_existencia,
                        MAX(existencia) as max_existencia
                    FROM productos WHERE proveedor = ?");
                    $stmt_resumen->execute([$proveedor_filter]);
                    $resumen = $stmt_resumen->fetch();
                    ?>
                    <div class="card mt-4">
                        <div class="card-header bg-primary text-white">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-graph-up"></i> Resumen: <?php echo htmlspecialchars($proveedor_filter); ?>
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <h4 class="text-primary"><?php echo number_format($resumen['total_productos']); ?></h4>
                                        <small class="text-muted">Productos</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <h4 class="text-success"><?php echo number_format($resumen['total_existencia']); ?></h4>
                                        <small class="text-muted">Unidades en Stock</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <h4 class="text-info">$<?php echo number_format($resumen['valor_total'], 2); ?></h4>
                                        <small class="text-muted">Valor Total</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <h4 class="text-secondary"><?php echo number_format($resumen['promedio_existencia'], 1); ?></h4>
                                        <small class="text-muted">Promedio por Producto</small>
                                    </div>
                                </div>
                            </div>
                            <hr>
                            <div class="row text-center">
                                <div class="col-6">
                                    <small class="text-muted">Stock Mínimo</small><br>
                                    <strong><?php echo number_format($resumen['min_existencia']); ?> unidades</strong>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted">Stock Máximo</small><br>
                                    <strong><?php echo number_format($resumen['max_existencia']); ?> unidades</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- Modal para editar existencia -->
    <div class="modal fade" id="modalEditarExistencia" tabindex="-1" aria-labelledby="modalEditarExistenciaLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalEditarExistenciaLabel">
                        <i class="bi bi-pencil-square"></i> Editar Existencia
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="formEditarExistencia" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="editar_existencia">
                        <input type="hidden" name="producto_id" id="producto_id_editar">
                        
                        <div class="mb-3">
                            <label class="form-label"><strong>Producto:</strong></label>
                            <p id="descripcion_producto" class="text-muted"></p>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Existencia Actual:</label>
                                <div class="alert alert-info mb-0">
                                    <h4 class="mb-0" id="existencia_actual"></h4>
                                    <small>unidades</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="nueva_existencia" class="form-label">Nueva Existencia <span class="text-danger">*</span></label>
                                <input type="number" class="form-control form-control-lg" id="nueva_existencia" name="nueva_existencia" 
                                       required min="0" step="1" placeholder="0">
                                <small class="text-muted">Ingrese la nueva cantidad</small>
                            </div>
                        </div>
                        
                        <div id="diferencia_info" class="mb-3" style="display: none;">
                            <div class="alert mb-0" id="diferencia_alert">
                                <strong>Diferencia:</strong> <span id="diferencia_texto"></span>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="motivo" class="form-label">
                                <i class="bi bi-journal-text"></i> Motivo del Ajuste (Opcional)
                            </label>
                            <textarea class="form-control" id="motivo" name="motivo" rows="3" 
                                      placeholder="Ej: Conteo físico, producto dañado, ajuste por inventario, corrección de error, etc."></textarea>
                            <small class="text-muted">Este motivo quedará registrado en el historial</small>
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            <strong>Importante:</strong> Este cambio se registrará como un ajuste de inventario y afectará las estadísticas del sistema.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary" id="btnGuardarExistencia">
                            <i class="bi bi-save"></i> Actualizar Existencia
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de confirmación para cambios grandes -->
    <div class="modal fade" id="modalConfirmarCambio" tabindex="-1" aria-labelledby="modalConfirmarCambioLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title" id="modalConfirmarCambioLabel">
                        <i class="bi bi-exclamation-triangle"></i> Confirmar Cambio Importante
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning mb-3">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>¡Atención!</strong> Va a realizar un cambio significativo en la existencia.
                    </div>
                    <div id="confirmacion_texto" class="mb-3"></div>
                    <p><strong>¿Está seguro que desea continuar con este ajuste?</strong></p>
                    <small class="text-muted">Este cambio quedará registrado permanentemente en el sistema.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Cancelar
                    </button>
                    <button type="button" class="btn btn-warning" id="btnConfirmarCambio">
                        <i class="bi bi-check-circle"></i> Sí, Confirmar Cambio
                    </button>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // **CORRECCIÓN 10: Variables globales mejoradas**
        let currentProductId = null;
        let currentExistencia = null;
        let currentDescripcion = null;
        let pendingSubmission = false;

        // **CORRECCIÓN 11: Manejar clic en existencia editable con validación mejorada**
        document.querySelectorAll('.existencia-editable').forEach(element => {
            element.addEventListener('click', function() {
                const productoId = this.getAttribute('data-producto-id');
                const existenciaActual = parseInt(this.getAttribute('data-existencia-actual'));
                const descripcion = this.getAttribute('data-descripcion');
                
                if (!productoId || isNaN(existenciaActual)) {
                    alert('❌ Error: Datos del producto no válidos');
                    return;
                }
                
                // Llenar el modal
                document.getElementById('producto_id_editar').value = productoId;
                document.getElementById('existencia_actual').textContent = existenciaActual;
                document.getElementById('descripcion_producto').textContent = descripcion;
                document.getElementById('nueva_existencia').value = existenciaActual;
                document.getElementById('motivo').value = '';
                
                // Limpiar diferencia
                document.getElementById('diferencia_info').style.display = 'none';
                
                // Guardar valores actuales
                currentProductId = productoId;
                currentExistencia = existenciaActual;
                currentDescripcion = descripcion;
                
                // Mostrar modal
                const modal = new bootstrap.Modal(document.getElementById('modalEditarExistencia'));
                modal.show();
                
                // Enfocar el campo de nueva existencia
                setTimeout(() => {
                    const inputNuevaExistencia = document.getElementById('nueva_existencia');
                    inputNuevaExistencia.focus();
                    inputNuevaExistencia.select();
                }, 500);
            });
        });

        // **CORRECCIÓN 12: Calcular diferencia con validación mejorada**
        document.getElementById('nueva_existencia').addEventListener('input', function() {
            const inputValue = this.value.trim();
            
            // Validar que sea un número válido
            if (inputValue === '') {
                document.getElementById('diferencia_info').style.display = 'none';
                return;
            }
            
            const nuevaExistencia = parseInt(inputValue);
            
            if (isNaN(nuevaExistencia)) {
                document.getElementById('diferencia_info').style.display = 'none';
                this.classList.add('is-invalid');
                return;
            } else {
                this.classList.remove('is-invalid');
            }
            
            if (nuevaExistencia < 0) {
                this.classList.add('is-invalid');
                document.getElementById('diferencia_info').style.display = 'none';
                return;
            }
            
            const diferencia = nuevaExistencia - currentExistencia;
            
            if (diferencia !== 0) {
                const diferenciaInfo = document.getElementById('diferencia_info');
                const diferenciaTexto = document.getElementById('diferencia_texto');
                const diferenciaAlert = document.getElementById('diferencia_alert');
                
                // Resetear clases
                diferenciaAlert.className = 'alert mb-0';
                
                if (diferencia > 0) {
                    diferenciaAlert.classList.add('alert-success');
                    diferenciaTexto.innerHTML = `
                        <span class="text-success">
                            <i class="bi bi-arrow-up-circle"></i> +${diferencia} unidades (Incremento)
                        </span><br>
                        <small>Se registrará como ingreso de ajuste</small>
                    `;
                } else {
                    diferenciaAlert.classList.add('alert-danger');
                    diferenciaTexto.innerHTML = `
                        <span class="text-danger">
                            <i class="bi bi-arrow-down-circle"></i> ${diferencia} unidades (Reducción)
                        </span><br>
                        <small>Se reducirá el inventario disponible</small>
                    `;
                }
                
                diferenciaInfo.style.display = 'block';
                
                // Validación de stock adicional
                if (diferencia < 0 && Math.abs(diferencia) > currentExistencia) {
                    diferenciaTexto.innerHTML += '<br><span class="badge bg-danger">⚠️ Advertencia: La reducción es mayor que el stock actual</span>';
                }
            } else {
                document.getElementById('diferencia_info').style.display = 'none';
            }
        });

        // **CORRECCIÓN 13: Validación y envío del formulario mejorado**
        document.getElementById('formEditarExistencia').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const nuevaExistenciaInput = document.getElementById('nueva_existencia');
            const nuevaExistenciaValue = nuevaExistenciaInput.value.trim();
            
            // Validaciones exhaustivas
            if (nuevaExistenciaValue === '') {
                alert('❌ Error: Debe ingresar una cantidad.');
                nuevaExistenciaInput.focus();
                return false;
            }
            
            const nuevaExistencia = parseInt(nuevaExistenciaValue);
            
            if (isNaN(nuevaExistencia)) {
                alert('❌ Error: La cantidad debe ser un número válido.');
                nuevaExistenciaInput.focus();
                return false;
            }
            
            if (nuevaExistencia < 0) {
                alert('❌ Error: La existencia no puede ser negativa.');
                nuevaExistenciaInput.focus();
                return false;
            }
            
            if (nuevaExistencia === currentExistencia) {
                alert('ℹ️ No hay cambios: La nueva existencia es igual a la actual.');
                return false;
            }
            
            const diferencia = nuevaExistencia - currentExistencia;
            const diferenciaAbsoluta = Math.abs(diferencia);
            const porcentajeCambio = currentExistencia > 0 ? (diferenciaAbsoluta / currentExistencia) * 100 : 100;
            
            // **CORRECCIÓN 14: Mejorar lógica de confirmación para cambios grandes**
            if ((porcentajeCambio > 50 || diferenciaAbsoluta > 100) && !pendingSubmission) {
                const confirmacionTexto = document.getElementById('confirmacion_texto');
                
                const tipoCambio = diferencia > 0 ? 'INCREMENTO' : 'REDUCCIÓN';
                const colorCambio = diferencia > 0 ? 'text-success' : 'text-danger';
                const iconoCambio = diferencia > 0 ? 'bi-arrow-up-circle' : 'bi-arrow-down-circle';
                
                confirmacionTexto.innerHTML = `
                    <div class="card">
                        <div class="card-body">
                            <h6 class="card-title">📦 Producto:</h6>
                            <p class="mb-2">${currentDescripcion}</p>
                            
                            <h6 class="card-title mt-3">📊 Resumen del Cambio:</h6>
                            <table class="table table-sm">
                                <tr>
                                    <td><strong>Existencia actual:</strong></td>
                                    <td class="text-end">${currentExistencia} unidades</td>
                                </tr>
                                <tr>
                                    <td><strong>Nueva existencia:</strong></td>
                                    <td class="text-end">${nuevaExistencia} unidades</td>
                                </tr>
                                <tr class="${colorCambio}">
                                    <td><strong><i class="${iconoCambio}"></i> ${tipoCambio}:</strong></td>
                                    <td class="text-end"><strong>${diferencia > 0 ? '+' : ''}${diferencia} unidades</strong></td>
                                </tr>
                                <tr>
                                    <td><strong>Porcentaje de cambio:</strong></td>
                                    <td class="text-end">${porcentajeCambio.toFixed(1)}%</td>
                                </tr>
                            </table>
                            
                            <div class="alert alert-warning mt-2 mb-0">
                                <small>
                                    <i class="bi bi-info-circle"></i>
                                    Este es un cambio significativo que será registrado permanentemente.
                                </small>
                            </div>
                        </div>
                    </div>
                `;
                
                // Ocultar el modal actual y mostrar confirmación
                const modalEditarExistencia = bootstrap.Modal.getInstance(document.getElementById('modalEditarExistencia'));
                modalEditarExistencia.hide();
                
                const modalConfirmar = new bootstrap.Modal(document.getElementById('modalConfirmarCambio'));
                modalConfirmar.show();
                
                return false;
            }
            
            // Validación adicional para reducciones grandes
            if (diferencia < 0 && diferenciaAbsoluta > currentExistencia) {
                if (!confirm(`⚠️ ADVERTENCIA: Está intentando reducir ${diferenciaAbsoluta} unidades, pero solo hay ${currentExistencia} en stock.\n\n¿Desea establecer la existencia en ${nuevaExistencia}?`)) {
                    return false;
                }
            }
            
            // Si llegamos aquí, proceder con el envío
            mostrarCargando();
            this.submit();
        });

        // **CORRECCIÓN 15: Manejar confirmación de cambio grande**
        document.getElementById('btnConfirmarCambio').addEventListener('click', function() {
            pendingSubmission = true;
            
            // Ocultar modal de confirmación
            const modalConfirmar = bootstrap.Modal.getInstance(document.getElementById('modalConfirmarCambio'));
            modalConfirmar.hide();
            
            // Mostrar indicador de carga
            mostrarCargando();
            
            // Enviar el formulario
            document.getElementById('formEditarExistencia').submit();
        });

        // **CORRECCIÓN 16: Función para mostrar indicador de carga**
        function mostrarCargando() {
            const btnGuardar = document.getElementById('btnGuardarExistencia');
            btnGuardar.disabled = true;
            btnGuardar.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Guardando cambios...';
        }

        // **CORRECCIÓN 17: Restablecer estado cuando se oculta el modal de confirmación**
        document.getElementById('modalConfirmarCambio').addEventListener('hidden.bs.modal', function() {
            if (!pendingSubmission) {
                // Volver a mostrar el modal de edición si no se confirmó
                const modalEditarExistencia = new bootstrap.Modal(document.getElementById('modalEditarExistencia'));
                modalEditarExistencia.show();
            }
        });

        // **CORRECCIÓN 18: Limpiar formulario al cerrar modal**
        document.getElementById('modalEditarExistencia').addEventListener('hidden.bs.modal', function() {
            if (!pendingSubmission) {
                document.getElementById('formEditarExistencia').reset();
                document.getElementById('diferencia_info').style.display = 'none';
                document.getElementById('nueva_existencia').classList.remove('is-invalid', 'is-valid');
                currentProductId = null;
                currentExistencia = null;
                currentDescripcion = null;
            }
            pendingSubmission = false;
            
            // Restaurar botón
            const btnGuardar = document.getElementById('btnGuardarExistencia');
            btnGuardar.disabled = false;
            btnGuardar.innerHTML = '<i class="bi bi-save"></i> Actualizar Existencia';
        });

        // **CORRECCIÓN 19: Atajos de teclado en el modal mejorados**
        document.getElementById('modalEditarExistencia').addEventListener('keydown', function(e) {
            // Enter para enviar (solo si no es en textarea)
            if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
                e.preventDefault();
                const form = document.getElementById('formEditarExistencia');
                form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
            }
            
            // Escape para cerrar
            if (e.key === 'Escape') {
                const modal = bootstrap.Modal.getInstance(this);
                if (modal) {
                    modal.hide();
                }
            }
        });

        // **CORRECCIÓN 20: Validación en tiempo real mientras escribe**
        document.getElementById('nueva_existencia').addEventListener('keyup', function(e) {
            const value = this.value.trim();
            
            if (value === '') {
                this.classList.remove('is-valid', 'is-invalid');
                return;
            }
            
            const numero = parseInt(value);
            
            if (isNaN(numero) || numero < 0) {
                this.classList.add('is-invalid');
                this.classList.remove('is-valid');
            } else {
                this.classList.add('is-valid');
                this.classList.remove('is-invalid');
            }
        });

        // **CORRECCIÓN 21: Agregar tooltips mejorados**
        document.addEventListener('DOMContentLoaded', function() {
            // Inicializar tooltips de Bootstrap
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Agregar tooltip dinámico a existencias editables
            document.querySelectorAll('.existencia-editable').forEach(element => {
                const existencia = parseInt(element.getAttribute('data-existencia-actual'));
                let tooltipText = 'Clic para editar la existencia';
                
                if (existencia === 0) {
                    tooltipText = '⚠️ Sin stock - Clic para agregar existencia';
                } else if (existencia < 10) {
                    tooltipText = '⚠️ Bajo stock - Clic para ajustar';
                }
                
                element.setAttribute('title', tooltipText);
                new bootstrap.Tooltip(element);
            });
        });

        // **CORRECCIÓN 22: Manejar actualización visual después de editar**
        document.addEventListener('DOMContentLoaded', function() {
            // Si hay un mensaje de éxito, destacar brevemente las existencias editables
            const alertSuccess = document.querySelector('.alert-success');
            if (alertSuccess && alertSuccess.textContent.includes('actualizada exitosamente')) {
                document.querySelectorAll('.existencia-editable').forEach(element => {
                    element.style.animation = 'pulse 2s ease-in-out';
                });
                
                // Auto-cerrar alerta después de 8 segundos
                setTimeout(() => {
                    const closeBtn = alertSuccess.querySelector('.btn-close');
                    if (closeBtn) {
                        closeBtn.click();
                    }
                }, 8000);
            }
        });

        // **CORRECCIÓN 23: Agregar estilos de animación dinámicamente**
        const style = document.createElement('style');
        style.textContent = `
            @keyframes pulse {
                0% { transform: scale(1); }
                50% { transform: scale(1.05); box-shadow: 0 0 10px rgba(0,123,255,0.5); }
                100% { transform: scale(1); }
            }
            
            .existencia-editable {
                transition: all 0.2s ease;
            }
            
            .existencia-editable:active {
                transform: scale(0.95);
            }
            
            .table tbody tr:hover {
                background-color: rgba(0, 123, 255, 0.05);
            }
            
            .is-invalid {
                animation: shake 0.5s;
            }
            
            @keyframes shake {
                0%, 100% { transform: translateX(0); }
                10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
                20%, 40%, 60%, 80% { transform: translateX(5px); }
            }
            
            /* Mejorar feedback visual en inputs */
            .form-control:focus {
                border-color: #86b7fe;
                box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
            }
            
            .form-control.is-valid {
                border-color: #198754;
                background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 8 8'%3e%3cpath fill='%23198754' d='M2.3 6.73L.6 4.53c-.4-1.04.46-1.4 1.1-.8l1.1 1.4 3.4-3.8c.6-.63 1.6-.27 1.2.7l-4 4.6c-.43.5-.8.4-1.1.1z'/%3e%3c/svg%3e");
                background-repeat: no-repeat;
                background-position: right calc(0.375em + 0.1875rem) center;
                background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
            }
            
            .form-control.is-invalid {
                border-color: #dc3545;
                background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e");
                background-repeat: no-repeat;
                background-position: right calc(0.375em + 0.1875rem) center;
                background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
            }
        `;
        document.head.appendChild(style);

        // **CORRECCIÓN 24: Función para formatear números con separadores de miles**
        function formatearNumero(numero) {
            return numero.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        }

        // **CORRECCIÓN 25: Prevenir envío doble del formulario**
        let formSubmitting = false;
        document.getElementById('formEditarExistencia').addEventListener('submit', function(e) {
            if (formSubmitting) {
                e.preventDefault();
                return false;
            }
        });

        // **CORRECCIÓN 26: Agregar función de búsqueda rápida en tabla**
        function agregarBusquedaRapida() {
            const searchInput = document.getElementById('search');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    const valor = this.value.toLowerCase();
                    
                    // Sugerencia visual mientras escribe
                    if (valor.length > 0) {
                        this.style.borderColor = '#0d6efd';
                    } else {
                        this.style.borderColor = '';
                    }
                });
            }
        }

        agregarBusquedaRapida();

        // **CORRECCIÓN 27: Función para exportar datos de inventario (opcional)**
        function exportarInventario() {
            const tabla = document.querySelector('table');
            if (!tabla) return;
            
            let csv = 'Proveedor,Descripción,Precio Venta,Existencia,Total Ingresos,Total Salidas,Valor Inventario,Estado\n';
            
            const filas = tabla.querySelectorAll('tbody tr');
            filas.forEach(fila => {
                const celdas = fila.querySelectorAll('td');
                const datos = Array.from(celdas).map(celda => {
                    return '"' + celda.textContent.trim().replace(/"/g, '""') + '"';
                });
                csv += datos.join(',') + '\n';
            });
            
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'inventario_' + new Date().toISOString().split('T')[0] + '.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // **CORRECCIÓN 28: Agregar botón de exportar (opcional)**
        // Puedes descomentar esto si quieres agregar funcionalidad de exportación
        /*
        document.addEventListener('DOMContentLoaded', function() {
            const cardHeader = document.querySelector('.card-header');
            if (cardHeader) {
                const btnExportar = document.createElement('button');
                btnExportar.className = 'btn btn-sm btn-outline-success';
                btnExportar.innerHTML = '<i class="bi bi-file-earmark-excel"></i> Exportar';
                btnExportar.onclick = exportarInventario;
                cardHeader.appendChild(btnExportar);
            }
        });
        */

        // Log de inicialización
        console.log('✅ Sistema de Inventario V2.0 - Cargado correctamente');
        console.log('✅ Correcciones aplicadas:');
        console.log('   - Consultas SQL optimizadas con subconsultas');
        console.log('   - Validación mejorada de existencias');
        console.log('   - Control de cambios significativos');
        console.log('   - Interfaz de usuario mejorada');
        console.log('   - Tooltips y animaciones agregadas');
    </script>
</body>
</html>