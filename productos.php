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
            case 'crear':
                try {
                    $stmt = $db->prepare("INSERT INTO productos (proveedor, descripcion, precio_venta) VALUES (?, ?, ?)");
                    $stmt->execute([
                        $_POST['proveedor'],
                        $_POST['descripcion'],
                        $_POST['precio_venta']
                    ]);
                    $mensaje = "Producto creado exitosamente.";
                    $tipo_mensaje = "success";
                } catch (Exception $e) {
                    $mensaje = "Error al crear el producto: " . $e->getMessage();
                    $tipo_mensaje = "danger";
                }
                break;

            case 'editar':
                try {
                    $stmt = $db->prepare("UPDATE productos SET proveedor = ?, descripcion = ?, precio_venta = ? WHERE id = ?");
                    $stmt->execute([
                        $_POST['proveedor'],
                        $_POST['descripcion'],
                        $_POST['precio_venta'],
                        $_POST['id']
                    ]);
                    $mensaje = "Producto actualizado exitosamente.";
                    $tipo_mensaje = "success";
                } catch (Exception $e) {
                    $mensaje = "Error al actualizar el producto: " . $e->getMessage();
                    $tipo_mensaje = "danger";
                }
                break;

            case 'eliminar':
                try {
                    // Verificar si el producto tiene existencia
                    $stmt_check = $db->prepare("SELECT existencia FROM productos WHERE id = ?");
                    $stmt_check->execute([$_POST['id']]);
                    $producto = $stmt_check->fetch();
                    
                    if ($producto && $producto['existencia'] > 0) {
                        $mensaje = "No se puede eliminar el producto porque tiene existencia en inventario.";
                        $tipo_mensaje = "warning";
                    } else {
                        $stmt = $db->prepare("DELETE FROM productos WHERE id = ?");
                        $stmt->execute([$_POST['id']]);
                        $mensaje = "Producto eliminado exitosamente.";
                        $tipo_mensaje = "success";
                    }
                } catch (Exception $e) {
                    $mensaje = "Error al eliminar el producto: " . $e->getMessage();
                    $tipo_mensaje = "danger";
                }
                break;
        }
    }
}

// Obtener productos con paginación
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? $_GET['search'] : '';
$proveedor_filter = isset($_GET['proveedor']) ? $_GET['proveedor'] : '';

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

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Contar total de productos
$count_query = "SELECT COUNT(*) as total FROM productos $where_clause";
$stmt_count = $db->prepare($count_query);
$stmt_count->execute($params);
$total_productos = $stmt_count->fetch()['total'];
$total_pages = ceil($total_productos / $limit);

// Obtener productos
$query = "SELECT * FROM productos $where_clause ORDER BY fecha_creacion DESC LIMIT $limit OFFSET $offset";
$stmt = $db->prepare($query);
$stmt->execute($params);
$productos = $stmt->fetchAll();

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
    <title>Gestión de Productos - Sistema de Inventario</title>
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
        .table-actions {
            white-space: nowrap;
        }
        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
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
                            <a class="nav-link active" href="productos.php">
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
                    </ul>
                </div>
            </nav>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Gestión de Productos</h1>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalProducto">
                        <i class="bi bi-plus-lg"></i> Nuevo Producto
                    </button>
                </div>

                <!-- Mensajes -->
                <?php if (!empty($mensaje)): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($mensaje); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Filtros y búsqueda -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label for="search" class="form-label">Buscar</label>
                                <input type="text" class="form-control" id="search" name="search" 
                                       value="<?php echo htmlspecialchars($search); ?>" 
                                       placeholder="Buscar por descripción o proveedor...">
                            </div>
                            <div class="col-md-4">
                                <label for="proveedor" class="form-label">Proveedor</label>
                                <select class="form-select" id="proveedor" name="proveedor">
                                    <option value="">Todos los proveedores</option>
                                    <?php foreach ($proveedores as $proveedor): ?>
                                        <option value="<?php echo htmlspecialchars($proveedor['proveedor']); ?>"
                                                <?php echo $proveedor_filter == $proveedor['proveedor'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($proveedor['proveedor']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-outline-primary me-2">
                                    <i class="bi bi-search"></i> Buscar
                                </button>
                                <a href="productos.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-x-lg"></i> Limpiar
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Tabla de productos -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Lista de Productos (<?php echo $total_productos; ?> total)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($productos) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>ID</th>
                                            <th>Proveedor</th>
                                            <th>Descripción</th>
                                            <th>Precio Venta</th>
                                            <th>Existencia</th>
                                            <th>Fecha Creación</th>
                                            <th class="table-actions">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($productos as $producto): ?>
                                            <tr>
                                                <td><?php echo $producto['id']; ?></td>
                                                <td><?php echo htmlspecialchars($producto['proveedor']); ?></td>
                                                <td><?php echo htmlspecialchars($producto['descripcion']); ?></td>
                                                <td>$<?php echo number_format($producto['precio_venta'], 2); ?></td>
                                                <td>
                                                    <span class="badge <?php echo $producto['existencia'] == 0 ? 'bg-danger' : ($producto['existencia'] < 10 ? 'bg-warning' : 'bg-success'); ?>">
                                                        <?php echo $producto['existencia']; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('d/m/Y H:i', strtotime($producto['fecha_creacion'])); ?></td>
                                                <td class="table-actions">
                                                    <button type="button" class="btn btn-sm btn-outline-primary" 
                                                            onclick="editarProducto(<?php echo htmlspecialchars(json_encode($producto)); ?>)">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                                            onclick="eliminarProducto(<?php echo $producto['id']; ?>, '<?php echo htmlspecialchars($producto['descripcion']); ?>')">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Paginación -->
                            <?php if ($total_pages > 1): ?>
                                <nav aria-label="Navegación de productos">
                                    <ul class="pagination justify-content-center">
                                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&proveedor=<?php echo urlencode($proveedor_filter); ?>">Anterior</a>
                                        </li>
                                        
                                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&proveedor=<?php echo urlencode($proveedor_filter); ?>"><?php echo $i; ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        
                                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&proveedor=<?php echo urlencode($proveedor_filter); ?>">Siguiente</a>
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
            </main>
        </div>
    </div>

    <!-- Modal para crear/editar producto -->
    <div class="modal fade" id="modalProducto" tabindex="-1" aria-labelledby="modalProductoLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalProductoLabel">Nuevo Producto</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="formProducto" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="accion" value="crear">
                        <input type="hidden" name="id" id="producto_id">
                        
                        <div class="mb-3">
                            <label for="proveedor" class="form-label">Proveedor <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="proveedor_modal" name="proveedor" required 
                                   maxlength="255" placeholder="Nombre del proveedor">
                        </div>
                        
                        <div class="mb-3">
                            <label for="descripcion" class="form-label">Descripción <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="descripcion_modal" name="descripcion" required 
                                      rows="3" placeholder="Descripción del producto"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="precio_venta" class="form-label">Precio de Venta <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="precio_venta_modal" name="precio_venta" 
                                       required min="0" step="0.01" placeholder="0.00">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" id="btnGuardar">Guardar Producto</button>
                    </div>
                </form>
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
                    <p>¿Está seguro que desea eliminar el producto <strong id="descripcion_eliminar"></strong>?</p>
                    <p class="text-danger">Esta acción no se puede deshacer.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="accion" value="eliminar">
                        <input type="hidden" name="id" id="id_eliminar">
                        <button type="submit" class="btn btn-danger">Eliminar</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editarProducto(producto) {
            document.getElementById('modalProductoLabel').textContent = 'Editar Producto';
            document.getElementById('accion').value = 'editar';
            document.getElementById('producto_id').value = producto.id;
            document.getElementById('proveedor_modal').value = producto.proveedor;
            document.getElementById('descripcion_modal').value = producto.descripcion;
            document.getElementById('precio_venta_modal').value = producto.precio_venta;
            document.getElementById('btnGuardar').textContent = 'Actualizar Producto';
            
            const modal = new bootstrap.Modal(document.getElementById('modalProducto'));
            modal.show();
        }

        function eliminarProducto(id, descripcion) {
            document.getElementById('id_eliminar').value = id;
            document.getElementById('descripcion_eliminar').textContent = descripcion;
            
            const modal = new bootstrap.Modal(document.getElementById('modalEliminar'));
            modal.show();
        }

        // Limpiar formulario al cerrar modal
        document.getElementById('modalProducto').addEventListener('hidden.bs.modal', function () {
            document.getElementById('formProducto').reset();
            document.getElementById('modalProductoLabel').textContent = 'Nuevo Producto';
            document.getElementById('accion').value = 'crear';
            document.getElementById('producto_id').value = '';
            document.getElementById('btnGuardar').textContent = 'Guardar Producto';
        });

        // Validación del formulario
        document.getElementById('formProducto').addEventListener('submit', function(e) {
            const proveedor = document.getElementById('proveedor_modal').value.trim();
            const descripcion = document.getElementById('descripcion_modal').value.trim();
            const precio = document.getElementById('precio_venta_modal').value;

            if (!proveedor || !descripcion || !precio || precio <= 0) {
                e.preventDefault();
                alert('Por favor, complete todos los campos correctamente.');
                return false;
            }
        });
    </script>
</body>
</html>