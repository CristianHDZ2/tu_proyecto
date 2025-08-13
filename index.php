<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Inventario</title>
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
        .card-dashboard {
            transition: transform 0.2s;
        }
        .card-dashboard:hover {
            transform: translateY(-5px);
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
                            <a class="nav-link active" href="index.php">
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
                    </ul>
                </div>
            </nav>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Dashboard</h1>
                </div>

                <?php
                require_once 'config/database.php';
                
                $database = new Database();
                $db = $database->getConnection();

                // Obtener estadísticas básicas
                $stmt_productos = $db->prepare("SELECT COUNT(*) as total FROM productos");
                $stmt_productos->execute();
                $total_productos = $stmt_productos->fetch()['total'];

                $stmt_existencia = $db->prepare("SELECT SUM(existencia) as total FROM productos");
                $stmt_existencia->execute();
                $total_existencia = $stmt_existencia->fetch()['total'] ?? 0;

                $stmt_ingresos = $db->prepare("SELECT COUNT(*) as total FROM ingresos WHERE DATE(fecha_creacion) = CURDATE()");
                $stmt_ingresos->execute();
                $ingresos_hoy = $stmt_ingresos->fetch()['total'];

                $stmt_distribuciones = $db->prepare("SELECT COUNT(*) as total FROM distribuciones WHERE estado = 'activo'");
                $stmt_distribuciones->execute();
                $distribuciones_activas = $stmt_distribuciones->fetch()['total'];
                ?>

                <div class="row">
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card border-left-primary shadow h-100 py-2 card-dashboard">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                            Total Productos
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo $total_productos; ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-box fs-2 text-primary"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card border-left-success shadow h-100 py-2 card-dashboard">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                            Existencia Total
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($total_existencia); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-clipboard-data fs-2 text-success"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card border-left-info shadow h-100 py-2 card-dashboard">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                            Ingresos Hoy
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo $ingresos_hoy; ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-arrow-down-circle fs-2 text-info"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card border-left-warning shadow h-100 py-2 card-dashboard">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                            Distribuciones Activas
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo $distribuciones_activas; ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-arrow-up-circle fs-2 text-warning"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Productos con bajo stock -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Productos con Bajo Stock (menos de 10 unidades)</h5>
                            </div>
                            <div class="card-body">
                                <?php
                                $stmt_bajo_stock = $db->prepare("SELECT * FROM productos WHERE existencia < 10 ORDER BY existencia ASC");
                                $stmt_bajo_stock->execute();
                                $productos_bajo_stock = $stmt_bajo_stock->fetchAll();
                                
                                if (count($productos_bajo_stock) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Proveedor</th>
                                                    <th>Descripción</th>
                                                    <th>Existencia</th>
                                                    <th>Precio Venta</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($productos_bajo_stock as $producto): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($producto['proveedor']); ?></td>
                                                        <td><?php echo htmlspecialchars($producto['descripcion']); ?></td>
                                                        <td>
                                                            <span class="badge <?php echo $producto['existencia'] == 0 ? 'bg-danger' : 'bg-warning'; ?>">
                                                                <?php echo $producto['existencia']; ?>
                                                            </span>
                                                        </td>
                                                        <td>$<?php echo number_format($producto['precio_venta'], 2); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-info">
                                        <i class="bi bi-info-circle"></i> Todos los productos tienen stock suficiente.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>