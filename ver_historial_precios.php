<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Obtener filtros
$producto_id = isset($_GET['producto_id']) ? $_GET['producto_id'] : '';
$fecha_desde = isset($_GET['fecha_desde']) ? $_GET['fecha_desde'] : '';
$fecha_hasta = isset($_GET['fecha_hasta']) ? $_GET['fecha_hasta'] : '';

// Construir consulta
$where_conditions = [];
$params = [];

if (!empty($producto_id)) {
    $where_conditions[] = "h.producto_id = ?";
    $params[] = $producto_id;
}

if (!empty($fecha_desde)) {
    $where_conditions[] = "DATE(h.fecha_cambio) >= ?";
    $params[] = $fecha_desde;
}

if (!empty($fecha_hasta)) {
    $where_conditions[] = "DATE(h.fecha_cambio) <= ?";
    $params[] = $fecha_hasta;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Obtener historial
$query = "SELECT 
    h.*,
    p.descripcion,
    p.proveedor,
    p.precio_venta,
    CASE 
        WHEN h.precio_compra_nuevo > 0 THEN 
            ((p.precio_venta - h.precio_compra_nuevo) / h.precio_compra_nuevo * 100)
        ELSE 0
    END as margen_ganancia
FROM historial_precios_compra h
INNER JOIN productos p ON h.producto_id = p.id
$where_clause
ORDER BY h.fecha_cambio DESC
LIMIT 100";

$stmt = $db->prepare($query);
$stmt->execute($params);
$historial = $stmt->fetchAll();

// Obtener lista de productos para filtro
$stmt_productos = $db->prepare("SELECT id, descripcion, proveedor FROM productos ORDER BY proveedor, descripcion");
$stmt_productos->execute();
$productos = $stmt_productos->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Precios de Compra - Sistema de Inventario</title>
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
        .precio-aumento {
            color: #dc3545;
            font-weight: bold;
        }
        .precio-disminucion {
            color: #28a745;
            font-weight: bold;
        }
        .badge-cambio {
            font-size: 0.85rem;
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
                            <a class="nav-link" href="distribuciones.php">
                                <i class="bi bi-arrow-up-circle"></i> Distribuciones
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="reportes.php">
                                <i class="bi bi-file-earmark-bar-graph"></i> Reportes
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="ver_historial_precios.php">
                                <i class="bi bi-clock-history"></i> Historial Precios
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="bi bi-clock-history"></i> Historial de Precios de Compra
                    </h1>
                    <button class="btn btn-outline-success btn-sm" onclick="exportarHistorial()">
                        <i class="bi bi-file-earmark-excel"></i> Exportar
                    </button>
                </div>

                <!-- Filtros -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label for="producto_id" class="form-label">Producto</label>
                                <select class="form-select" id="producto_id" name="producto_id">
                                    <option value="">Todos los productos</option>
                                    <?php 
                                    $proveedor_actual = '';
                                    foreach ($productos as $prod): 
                                        if ($proveedor_actual != $prod['proveedor']):
                                            if ($proveedor_actual != '') echo '</optgroup>';
                                            $proveedor_actual = $prod['proveedor'];
                                            echo '<optgroup label="' . htmlspecialchars($proveedor_actual) . '">';
                                        endif;
                                    ?>
                                        <option value="<?php echo $prod['id']; ?>" <?php echo $producto_id == $prod['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($prod['descripcion']); ?>
                                        </option>
                                    <?php 
                                    endforeach;
                                    if ($proveedor_actual != '') echo '</optgroup>';
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="fecha_desde" class="form-label">Desde</label>
                                <input type="date" class="form-control" id="fecha_desde" name="fecha_desde" value="<?php echo $fecha_desde; ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="fecha_hasta" class="form-label">Hasta</label>
                                <input type="date" class="form-control" id="fecha_hasta" name="fecha_hasta" value="<?php echo $fecha_hasta; ?>">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-search"></i> Filtrar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Tabla de historial -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            Cambios de Precios (<?php echo count($historial); ?> registros)
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($historial) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover table-sm">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Fecha</th>
                                            <th>Producto</th>
                                            <th>Proveedor</th>
                                            <th class="text-end">Precio Anterior</th>
                                            <th class="text-end">Precio Nuevo</th>
                                            <th class="text-center">Cambio</th>
                                            <th class="text-center">Margen %</th>
                                            <th>Motivo</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($historial as $registro): ?>
                                            <?php
                                            $diferencia = $registro['precio_compra_nuevo'] - $registro['precio_compra_anterior'];
                                            $porcentaje = $registro['precio_compra_anterior'] > 0 ? 
                                                         (($diferencia / $registro['precio_compra_anterior']) * 100) : 0;
                                            $clase = $diferencia > 0 ? 'precio-aumento' : ($diferencia < 0 ? 'precio-disminucion' : '');
                                            ?>
                                            <tr>
                                                <td>
                                                    <small><?php echo date('d/m/Y H:i', strtotime($registro['fecha_cambio'])); ?></small>
                                                </td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($registro['descripcion']); ?></strong>
                                                </td>
                                                <td><?php echo htmlspecialchars($registro['proveedor']); ?></td>
                                                <td class="text-end">
                                                    $<?php echo number_format($registro['precio_compra_anterior'], 2); ?>
                                                </td>
                                                <td class="text-end">
                                                    $<?php echo number_format($registro['precio_compra_nuevo'], 2); ?>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge badge-cambio <?php echo $diferencia > 0 ? 'bg-danger' : ($diferencia < 0 ? 'bg-success' : 'bg-secondary'); ?>">
                                                        <?php echo $diferencia > 0 ? '+' : ''; ?>$<?php echo number_format($diferencia, 2); ?>
                                                        (<?php echo $diferencia > 0 ? '+' : ''; ?><?php echo number_format($porcentaje, 1); ?>%)
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge <?php 
                                                        $margen = $registro['margen_ganancia'];
                                                        echo $margen >= 30 ? 'bg-success' : ($margen >= 15 ? 'bg-warning text-dark' : 'bg-danger');
                                                    ?>">
                                                        <?php echo number_format($registro['margen_ganancia'], 1); ?>%
                                                    </span>
                                                </td>
                                                <td>
                                                    <small class="text-muted"><?php echo htmlspecialchars($registro['motivo']); ?></small>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> No se encontraron registros de cambios de precios.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function exportarHistorial() {
            const tabla = document.querySelector('table');
            if (!tabla) return;
            
            let csv = 'Fecha,Producto,Proveedor,Precio Anterior,Precio Nuevo,Cambio $,Cambio %,Margen %,Motivo\n';
            
            const filas = tabla.querySelectorAll('tbody tr');
            filas.forEach(fila => {
                const celdas = fila.querySelectorAll('td');
                const datos = [];
                
                celdas.forEach((celda, index) => {
                    let texto = celda.textContent.trim();
                    if (index === 5 || index === 6) {
                        // Extraer solo el valor numérico de los badges
                        texto = texto.replace(/[^0-9.\-+%]/g, '');
                    }
                    datos.push('"' + texto.replace(/"/g, '""') + '"');
                });
                
                csv += datos.join(',') + '\n';
            });
            
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'historial_precios_' + new Date().toISOString().split('T')[0] + '.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    </script>
</body>
</html>