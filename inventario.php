<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Obtener inventario con paginación
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? $_GET['search'] : '';
$proveedor_filter = isset($_GET['proveedor']) ? $_GET['proveedor'] : '';
$stock_filter = isset($_GET['stock']) ? $_GET['stock'] : '';

// Construir consulta con filtros
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(descripcion LIKE ? OR proveedor LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($proveedor_filter)) {
    $where_conditions[] = "proveedor = ?";
    $params[] = $proveedor_filter;
}

if (!empty($stock_filter)) {
    switch ($stock_filter) {
        case 'sin_stock':
            $where_conditions[] = "existencia = 0";
            break;
        case 'bajo_stock':
            $where_conditions[] = "existencia > 0 AND existencia < 10";
            break;
        case 'stock_normal':
            $where_conditions[] = "existencia >= 10";
            break;
    }
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Contar total de productos
$count_query = "SELECT COUNT(*) as total FROM productos $where_clause";
$stmt_count = $db->prepare($count_query);
$stmt_count->execute($params);
$total_productos = $stmt_count->fetch()['total'];
$total_pages = ceil($total_productos / $limit);

// Obtener productos con información adicional
$query = "SELECT 
            p.*,
            COALESCE(SUM(di.cantidad), 0) as total_ingresos,
            COALESCE(SUM(dtd.cantidad), 0) as total_salidas,
            (p.existencia * p.precio_venta) as valor_inventario
          FROM productos p
          LEFT JOIN detalle_ingresos di ON p.id = di.producto_id
          LEFT JOIN detalle_tablas_distribucion dtd ON p.id = dtd.producto_id
          $where_clause
          GROUP BY p.id
          ORDER BY p.proveedor, p.descripcion
          LIMIT $limit OFFSET $offset";

$stmt = $db->prepare($query);
$stmt->execute($params);
$productos = $stmt->fetchAll();

// Obtener estadísticas generales
$stmt_stats = $db->prepare("SELECT 
    COUNT(*) as total_productos,
    SUM(existencia) as total_existencia,
    SUM(existencia * precio_venta) as valor_total_inventario,
    COUNT(CASE WHEN existencia = 0 THEN 1 END) as productos_sin_stock,
    COUNT(CASE WHEN existencia > 0 AND existencia < 10 THEN 1 END) as productos_bajo_stock
FROM productos");
$stmt_stats->execute();
$stats = $stmt_stats->fetch();

// Obtener lista de proveedores para el filtro
$stmt_proveedores = $db->prepare("SELECT DISTINCT proveedor FROM productos ORDER BY proveedor");
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
                    <div class="card-header">
                        <h5 class="card-title mb-0">Inventario Actual (<?php echo $total_productos; ?> productos)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($productos) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover table-sm">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Proveedor</th>
                                            <th>Descripción</th>
                                            <th>Precio Venta</th>
                                            <th>Existencia</th>
                                            <th>Total Ingresos</th>
                                            <th>Total Salidas</th>
                                            <th>Valor Inventario</th>
                                            <th>Estado</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($productos as $producto): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($producto['proveedor']); ?></td>
                                                <td><?php echo htmlspecialchars($producto['descripcion']); ?></td>
                                                <td>$<?php echo number_format($producto['precio_venta'], 2); ?></td>
                                                <td>
                                                    <span class="badge <?php 
                                                        echo $producto['existencia'] == 0 ? 'bg-danger' : 
                                                             ($producto['existencia'] < 10 ? 'bg-warning text-dark' : 'bg-success'); 
                                                    ?>">
                                                        <?php echo number_format($producto['existencia']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-success">
                                                    <i class="bi bi-arrow-down"></i> <?php echo number_format($producto['total_ingresos']); ?>
                                                </td>
                                                <td class="text-danger">
                                                    <i class="bi bi-arrow-up"></i> <?php echo number_format($producto['total_salidas']); ?>
                                                </td>
                                                <td class="fw-bold">$<?php echo number_format($producto['valor_inventario'], 2); ?></td>
                                                <td>
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
                                            <th>$<?php echo number_format(array_sum(array_column($productos, 'valor_inventario')), 2); ?></th>
                                            <th></th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            <!-- Paginación -->
                            <?php if ($total_pages > 1): ?>
                                <nav aria-label="Navegación de inventario">
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
                    $stmt_resumen = $db->prepare("SELECT 
                        COUNT(*) as total_productos,
                        SUM(existencia) as total_existencia,
                        SUM(existencia * precio_venta) as valor_total
                    FROM productos WHERE proveedor = ?");
                    $stmt_resumen->execute([$proveedor_filter]);
                    $resumen = $stmt_resumen->fetch();
                    ?>
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Resumen: <?php echo htmlspecialchars($proveedor_filter); ?></h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="text-center">
                                        <h4 class="text-primary"><?php echo $resumen['total_productos']; ?></h4>
                                        <small class="text-muted">Productos</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="text-center">
                                        <h4 class="text-success"><?php echo number_format($resumen['total_existencia']); ?></h4>
                                        <small class="text-muted">Unidades en Stock</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="text-center">
                                        <h4 class="text-info">$<?php echo number_format($resumen['valor_total'], 2); ?></h4>
                                        <small class="text-muted">Valor Total</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>