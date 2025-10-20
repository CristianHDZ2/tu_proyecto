<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: distribuciones.php');
    exit;
}

$distribucion_id = $_GET['id'];

// Obtener información de la distribución
$stmt_dist = $db->prepare("SELECT 
    d.*,
    DATE_FORMAT(d.fecha_inicio, '%d/%m/%Y') as fecha_inicio_formato,
    DATE_FORMAT(d.fecha_fin, '%d/%m/%Y') as fecha_fin_formato,
    DATE_FORMAT(d.fecha_creacion, '%d/%m/%Y %H:%i') as fecha_creacion_formato
FROM distribuciones d 
WHERE d.id = ? AND d.estado = 'activo'");
$stmt_dist->execute([$distribucion_id]);
$distribucion = $stmt_dist->fetch();

if (!$distribucion) {
    header('Location: distribuciones.php?mensaje=' . urlencode('Distribución no encontrada') . '&tipo=danger');
    exit;
}

// Obtener tablas de distribución agrupadas por fecha
$stmt_tablas = $db->prepare("SELECT 
    td.id,
    td.fecha_tabla,
    DATE_FORMAT(td.fecha_tabla, '%d/%m/%Y') as fecha_formato,
    DATE_FORMAT(td.fecha_tabla, '%Y-%m-%d') as fecha_orden,
    DAYNAME(td.fecha_tabla) as dia_semana_ingles,
    td.numero_tabla,
    td.total_tabla
FROM tablas_distribucion td 
WHERE td.distribucion_id = ? AND td.estado = 'activo'
ORDER BY td.fecha_tabla ASC, td.numero_tabla ASC");
$stmt_tablas->execute([$distribucion_id]);
$todas_tablas = $stmt_tablas->fetchAll();

// Traducción de días
$dias_traduccion = [
    'Sunday' => 'Domingo',
    'Monday' => 'Lunes',
    'Tuesday' => 'Martes',
    'Wednesday' => 'Miércoles',
    'Thursday' => 'Jueves',
    'Friday' => 'Viernes',
    'Saturday' => 'Sábado'
];

// Agrupar tablas por fecha
$tablas_por_fecha = [];
$total_general = 0;
$total_tablas = 0;
$total_productos_distribuidos = 0;

foreach ($todas_tablas as $tabla) {
    $fecha = $tabla['fecha_orden'];
    
    if (!isset($tablas_por_fecha[$fecha])) {
        $tablas_por_fecha[$fecha] = [
            'fecha_formato' => $tabla['fecha_formato'],
            'dia_semana' => $dias_traduccion[$tabla['dia_semana_ingles']] ?? $tabla['dia_semana_ingles'],
            'tablas' => [],
            'total_dia' => 0,
            'total_unidades_dia' => 0
        ];
    }
    
    // Obtener detalles de la tabla
    $stmt_detalles = $db->prepare("SELECT 
        dtd.*,
        p.descripcion,
        p.proveedor
    FROM detalle_tablas_distribucion dtd
    INNER JOIN productos p ON dtd.producto_id = p.id
    WHERE dtd.tabla_id = ?
    ORDER BY p.proveedor, p.descripcion");
    $stmt_detalles->execute([$tabla['id']]);
    $detalles = $stmt_detalles->fetchAll();
    
    // Calcular total de unidades en esta tabla
    $unidades_tabla = 0;
    foreach ($detalles as $detalle) {
        $unidades_tabla += $detalle['cantidad'];
        $total_productos_distribuidos += $detalle['cantidad'];
    }
    
    $tabla['detalles'] = $detalles;
    $tabla['total_unidades'] = $unidades_tabla;
    
    $tablas_por_fecha[$fecha]['tablas'][] = $tabla;
    $tablas_por_fecha[$fecha]['total_dia'] += $tabla['total_tabla'];
    $tablas_por_fecha[$fecha]['total_unidades_dia'] += $unidades_tabla;
    
    $total_general += $tabla['total_tabla'];
    $total_tablas++;
}

// Calcular estadísticas
$total_dias = count($tablas_por_fecha);
$promedio_tablas_por_dia = $total_dias > 0 ? $total_tablas / $total_dias : 0;
$promedio_valor_por_dia = $total_dias > 0 ? $total_general / $total_dias : 0;
$promedio_unidades_por_dia = $total_dias > 0 ? $total_productos_distribuidos / $total_dias : 0;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ver Distribución #<?php echo $distribucion_id; ?> - Sistema de Inventario</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* Reset y configuración base */
        * {
            box-sizing: border-box;
        }
        
        body {
            margin: 0;
            padding: 0;
            overflow-x: hidden;
            background-color: #f8f9fa;
        }
        
        /* Configuración del contenedor principal */
        .app-container {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }
        
        /* Sidebar - Desktop */
        .sidebar {
            background-color: #343a40;
            width: 250px;
            min-height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1000;
            overflow-y: auto;
            transition: transform 0.3s ease;
        }
        
        .sidebar .nav-link {
            color: #adb5bd;
            padding: 0.75rem 1rem;
            border-radius: 0.375rem;
            margin: 0.125rem 0.5rem;
            display: flex;
            align-items: center;
        }
        
        .sidebar .nav-link:hover {
            color: #fff;
            background-color: #495057;
        }
        
        .sidebar .nav-link.active {
            color: #fff;
            background-color: #0d6efd;
        }
        
        .sidebar .nav-link i {
            width: 20px;
            margin-right: 8px;
            text-align: center;
        }
        
        /* Contenido principal - Desktop */
        .main-content {
            flex: 1;
            margin-left: 250px;
            min-height: 100vh;
            width: calc(100% - 250px);
            padding: 1rem;
        }
        
        /* Header móvil */
        .mobile-header {
            display: none;
            background-color: #343a40;
            color: white;
            padding: 1rem;
            position: sticky;
            top: 0;
            z-index: 1001;
            width: 100%;
        }
        
        .mobile-header .d-flex {
            justify-content: space-between;
            align-items: center;
        }
        
        .mobile-header h5 {
            margin: 0;
            color: white;
        }
        
        .mobile-toggle {
            background: none;
            border: 1px solid #adb5bd;
            color: #adb5bd;
            padding: 0.5rem;
            border-radius: 0.25rem;
            cursor: pointer;
        }
        
        .mobile-toggle:hover {
            color: white;
            border-color: white;
        }
        
        /* Overlay para móviles */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }
        
        /* Botón cerrar en sidebar móvil */
        .sidebar-close {
            display: none;
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: none;
            border: none;
            color: #adb5bd;
            font-size: 1.5rem;
            cursor: pointer;
        }
        
        .sidebar-close:hover {
            color: white;
        }
        
        /* Estilos de las tarjetas de distribución */
        .dia-card {
            border-left: 4px solid #0d6efd;
            margin-bottom: 2rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        
        .tabla-item {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: all 0.2s ease;
        }
        
        .tabla-item:hover {
            background-color: #e9ecef;
            box-shadow: 0 0.125rem 0.5rem rgba(0, 0, 0, 0.1);
        }
        
        .producto-detalle {
            padding: 0.5rem;
            border-bottom: 1px solid #e9ecef;
        }
        
        .producto-detalle:last-child {
            border-bottom: none;
        }
        
        .stats-card {
            border: none;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border-left: 4px solid;
        }
        
        .stats-card.border-primary {
            border-left-color: #0d6efd !important;
        }
        
        .stats-card.border-success {
            border-left-color: #198754 !important;
        }
        
        .stats-card.border-info {
            border-left-color: #0dcaf0 !important;
        }
        
        .stats-card.border-warning {
            border-left-color: #ffc107 !important;
        }
        
        /* Botones de acción flotantes */
        .action-buttons {
            position: sticky;
            top: 1rem;
            z-index: 100;
            background-color: white;
            padding: 1rem;
            border-radius: 0.375rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            margin-bottom: 1rem;
        }
        
        /* Responsive Design */
        
        /* Tablets y pantallas medianas */
        @media (max-width: 991.98px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .sidebar-close {
                display: block;
            }
            
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 0.75rem;
            }
            
            .mobile-header {
                display: block;
            }
            
            .sidebar-overlay.show {
                display: block;
            }
        }
        
        /* Móviles */
        @media (max-width: 767.98px) {
            .main-content {
                padding: 0.5rem;
            }
            
            .h2 {
                font-size: 1.5rem;
            }
            
            .card-body {
                padding: 0.75rem;
            }
            
            .tabla-item {
                padding: 0.75rem;
            }
            
            .producto-detalle {
                padding: 0.25rem;
                font-size: 0.875rem;
            }
            
            .stats-card .h5 {
                font-size: 1rem;
            }
            
            .stats-card .display-6 {
                font-size: 1.5rem;
            }
        }
        
        /* Móviles pequeños */
        @media (max-width: 575.98px) {
            .main-content {
                padding: 0.25rem;
            }
            
            .mobile-header {
                padding: 0.75rem;
            }
            
            .card-body {
                padding: 0.5rem;
            }
            
            .tabla-item {
                padding: 0.5rem;
            }
            
            .btn {
                font-size: 0.875rem;
                padding: 0.375rem 0.75rem;
            }
            
            .badge {
                font-size: 0.7rem;
            }
        }
        
        /* Print styles */
        @media print {
            .sidebar,
            .mobile-header,
            .action-buttons,
            .no-print {
                display: none !important;
            }
            
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
            }
            
            .dia-card,
            .tabla-item {
                page-break-inside: avoid;
            }
        }
        
        /* Animaciones */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-in {
            animation: slideIn 0.3s ease-out;
        }
        
        /* Mejoras visuales */
        .content-wrapper {
            width: 100%;
            max-width: 100%;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Header móvil -->
        <div class="mobile-header">
            <div class="d-flex">
                <h5><i class="bi bi-calendar-check"></i> Ver Distribución</h5>
                <button class="mobile-toggle" id="mobileToggle">
                    <i class="bi bi-list"></i>
                </button>
            </div>
        </div>
        
        <!-- Overlay para móviles -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>
        
        <!-- Sidebar -->
        <nav class="sidebar" id="sidebar">
            <button class="sidebar-close" id="sidebarClose">
                <i class="bi bi-x-lg"></i>
            </button>
            
            <div class="pt-3">
                <div class="text-center mb-4">
                    <h4 class="text-white d-none d-lg-block">
                        <i class="bi bi-box-seam"></i> Inventario
                    </h4>
                    <h5 class="text-white d-lg-none">
                        <i class="bi bi-box-seam"></i> Inventario
                    </h5>
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
        
        <!-- Contenido principal -->
        <main class="main-content">
            <div class="content-wrapper">
                <!-- Botones de acción -->
                <div class="action-buttons no-print">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <a href="distribuciones.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Volver
                        </a>
                        <div class="btn-group">
                            <button onclick="window.print()" class="btn btn-primary">
                                <i class="bi bi-printer"></i> Imprimir
                            </button>
                            <button onclick="exportarExcel()" class="btn btn-success">
                                <i class="bi bi-file-earmark-excel"></i> Exportar
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Información de la distribución -->
                <div class="card mb-4 animate-in">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">
                            <i class="bi bi-calendar-check"></i> 
                            Distribución #<?php echo $distribucion_id; ?>
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 col-6 mb-3">
                                <strong><i class="bi bi-calendar-event"></i> Fecha Inicio:</strong><br>
                                <span class="text-primary"><?php echo $distribucion['fecha_inicio_formato']; ?></span>
                            </div>
                            <div class="col-md-3 col-6 mb-3">
                                <strong><i class="bi bi-calendar-event-fill"></i> Fecha Fin:</strong><br>
                                <span class="text-primary"><?php echo $distribucion['fecha_fin_formato']; ?></span>
                            </div>
                            <div class="col-md-3 col-6 mb-3">
                                <strong><i class="bi bi-diagram-3"></i> Tipo:</strong><br>
                                <span class="badge bg-<?php echo $distribucion['tipo_distribucion'] == 'completo' ? 'success' : 'warning'; ?> fs-6">
                                    <?php echo ucfirst($distribucion['tipo_distribucion']); ?>
                                </span>
                            </div>
                            <div class="col-md-3 col-6 mb-3">
                                <strong><i class="bi bi-clock-history"></i> Creada:</strong><br>
                                <small class="text-muted"><?php echo $distribucion['fecha_creacion_formato']; ?></small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Estadísticas resumidas -->
                <div class="row g-3 mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card stats-card border-primary h-100 animate-in">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted text-uppercase mb-2">Total Tablas</h6>
                                        <h2 class="mb-0 fw-bold"><?php echo number_format($total_tablas); ?></h2>
                                    </div>
                                    <div class="text-primary">
                                        <i class="bi bi-table fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card stats-card border-success h-100 animate-in">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted text-uppercase mb-2">Total Días</h6>
                                        <h2 class="mb-0 fw-bold"><?php echo number_format($total_dias); ?></h2>
                                        <small class="text-muted">
                                            ~<?php echo number_format($promedio_tablas_por_dia, 1); ?> tablas/día
                                        </small>
                                    </div>
                                    <div class="text-success">
                                        <i class="bi bi-calendar3 fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card stats-card border-info h-100 animate-in">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted text-uppercase mb-2">Total Unidades</h6>
                                        <h2 class="mb-0 fw-bold"><?php echo number_format($total_productos_distribuidos); ?></h2>
                                        <small class="text-muted">
                                            ~<?php echo number_format($promedio_unidades_por_dia, 1); ?> unidades/día
                                        </small>
                                    </div>
                                    <div class="text-info">
                                        <i class="bi bi-box-seam fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card stats-card border-warning h-100 animate-in">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted text-uppercase mb-2">Total Valor</h6>
                                        <h2 class="mb-0 fw-bold text-success">$<?php echo number_format($total_general, 2); ?></h2>
                                        <small class="text-muted">
                                            ~$<?php echo number_format($promedio_valor_por_dia, 2); ?>/día
                                        </small>
                                    </div>
                                    <div class="text-warning">
                                        <i class="bi bi-currency-dollar fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tablas de distribución por día -->
                <?php if (empty($tablas_por_fecha)): ?>
                    <div class="alert alert-warning animate-in">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>No hay tablas de distribución.</strong> Esta distribución no tiene tablas asociadas.
                    </div>
                <?php else: ?>
                    <h3 class="mb-4">
                        <i class="bi bi-calendar3"></i> Tablas de Distribución por Día
                    </h3>

                    <?php foreach ($tablas_por_fecha as $fecha => $dia_data): ?>
                        <div class="card dia-card animate-in">
                            <div class="card-header bg-light">
                                <div class="row align-items-center">
                                    <div class="col-md-6">
                                        <h5 class="mb-0">
                                            <i class="bi bi-calendar-day"></i>
                                            <?php echo $dia_data['dia_semana']; ?>, <?php echo $dia_data['fecha_formato']; ?>
                                        </h5>
                                    </div>
                                    <div class="col-md-6 text-md-end mt-2 mt-md-0">
                                        <span class="badge bg-primary me-2">
                                            <?php echo count($dia_data['tablas']); ?> tabla(s)
                                        </span>
                                        <span class="badge bg-info me-2">
                                            <?php echo number_format($dia_data['total_unidades_dia']); ?> unidades
                                        </span>
                                        <span class="badge bg-success">
                                            $<?php echo number_format($dia_data['total_dia'], 2); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php foreach ($dia_data['tablas'] as $tabla): ?>
                                    <div class="tabla-item">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <h6 class="mb-0">
                                                <i class="bi bi-file-earmark-text"></i>
                                                Tabla #<?php echo $tabla['numero_tabla']; ?>
                                            </h6>
                                            <div>
                                                <span class="badge bg-secondary me-1">
                                                    <?php echo count($tabla['detalles']); ?> productos
                                                </span>
                                                <span class="badge bg-info me-1">
                                                    <?php echo number_format($tabla['total_unidades']); ?> unidades
                                                </span>
                                                <span class="badge bg-success">
                                                    $<?php echo number_format($tabla['total_tabla'], 2); ?>
                                                </span>
                                            </div>
                                        </div>

                                        <div class="table-responsive">
                                            <table class="table table-sm table-hover mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Proveedor</th>
                                                        <th>Producto</th>
                                                        <th class="text-center">Cantidad</th>
                                                        <th class="text-end">Precio Unit.</th>
                                                        <th class="text-end">Subtotal</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($tabla['detalles'] as $detalle): ?>
                                                        <tr>
                                                            <td><small class="text-primary fw-medium"><?php echo htmlspecialchars($detalle['proveedor']); ?></small></td>
                                                            <td><small><?php echo htmlspecialchars($detalle['descripcion']); ?></small></td>
                                                            <td class="text-center"><span class="badge bg-secondary"><?php echo $detalle['cantidad']; ?></span></td>
                                                            <td class="text-end"><small>$<?php echo number_format($detalle['precio_venta'], 2); ?></small></td>
                                                            <td class="text-end fw-medium"><small>$<?php echo number_format($detalle['subtotal'], 2); ?></small></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sistema de sidebar responsivo (igual que en otros archivos)
        class ResponsiveSidebar {
            constructor() {
                this.sidebar = document.getElementById('sidebar');
                this.overlay = document.getElementById('sidebarOverlay');
                this.mobileToggle = document.getElementById('mobileToggle');
                this.sidebarClose = document.getElementById('sidebarClose');
                this.isOpen = false;
                this.isMobile = window.innerWidth < 992;
                
                this.init();
            }
            
            init() {
                this.bindEvents();
                this.checkScreenSize();
            }
            
            bindEvents() {
                if (this.mobileToggle) {
                    this.mobileToggle.addEventListener('click', () => this.toggleSidebar());
                }
                
                if (this.sidebarClose) {
                    this.sidebarClose.addEventListener('click', () => this.closeSidebar());
                }
                
                if (this.overlay) {
                    this.overlay.addEventListener('click', () => this.closeSidebar());
                }
                
                const sidebarLinks = this.sidebar?.querySelectorAll('.nav-link');
                sidebarLinks?.forEach(link => {
                    link.addEventListener('click', () => {
                        if (this.isMobile) {
                            this.closeSidebar();
                        }
                    });
                });
                
                window.addEventListener('resize', () => this.handleResize());
                
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && this.isOpen) {
                        this.closeSidebar();
                    }
                });
            }
            
            toggleSidebar() {
                if (this.isOpen) {
                    this.closeSidebar();
                } else {
                    this.openSidebar();
                }
            }
            
            openSidebar() {
                this.sidebar?.classList.add('show');
                this.overlay?.classList.add('show');
                document.body.style.overflow = 'hidden';
                this.isOpen = true;
                
                const icon = this.mobileToggle?.querySelector('i');
                if (icon) {
                    icon.className = 'bi bi-x-lg';
                }
            }
            
            closeSidebar() {
                this.sidebar?.classList.remove('show');
                this.overlay?.classList.remove('show');
                document.body.style.overflow = '';
                this.isOpen = false;
                
                const icon = this.mobileToggle?.querySelector('i');
                if (icon) {
                    icon.className = 'bi bi-list';
                }
            }
            
            handleResize() {
                const wasMobile = this.isMobile;
                this.isMobile = window.innerWidth < 992;
                
                if (wasMobile && !this.isMobile) {
                    this.closeSidebar();
                }
                
                this.checkScreenSize();
            }
            
            checkScreenSize() {
                if (!this.isMobile && this.isOpen) {
                    this.closeSidebar();
                }
            }
        }

        // Función para exportar a Excel
        function exportarExcel() {
            const distribucionId = <?php echo $distribucion_id; ?>;
            const fechaInicio = '<?php echo $distribucion['fecha_inicio_formato']; ?>';
            const fechaFin = '<?php echo $distribucion['fecha_fin_formato']; ?>';
            
            // Crear contenido CSV
            let csv = 'DISTRIBUCIÓN #' + distribucionId + '\n';
            csv += 'Período: ' + fechaInicio + ' al ' + fechaFin + '\n\n';
            csv += 'Día,Fecha,Número Tabla,Proveedor,Producto,Cantidad,Precio Unitario,Subtotal\n';
            
            // Obtener todas las tablas
            const diasCards = document.querySelectorAll('.dia-card');
            
            diasCards.forEach(diaCard => {
                const diaInfo = diaCard.querySelector('.card-header h5').textContent.trim();
                
                const tablas = diaCard.querySelectorAll('.tabla-item');
                
                tablas.forEach(tabla => {
                    const numeroTabla = tabla.querySelector('h6').textContent.match(/Tabla #(\d+)/)[1];
                    
                    const filas = tabla.querySelectorAll('tbody tr');
                    
                    filas.forEach(fila => {
                        const celdas = fila.querySelectorAll('td');
                        const proveedor = celdas[0].textContent.trim();
                        const producto = celdas[1].textContent.trim();
                        const cantidad = celdas[2].textContent.trim();
                        const precioUnit = celdas[3].textContent.trim().replace('$', '');
                        const subtotal = celdas[4].textContent.trim().replace('$', '');
                        
                        csv += `"${diaInfo}","","${numeroTabla}","${proveedor}","${producto}","${cantidad}","${precioUnit}","${subtotal}"\n`;
                    });
                });
            });
            
            // Agregar totales
            csv += '\n\nRESUMEN\n';
            csv += 'Total Tablas,<?php echo $total_tablas; ?>\n';
            csv += 'Total Días,<?php echo $total_dias; ?>\n';
            csv += 'Total Unidades,<?php echo $total_productos_distribuidos; ?>\n';
            csv += 'Total Valor,$<?php echo number_format($total_general, 2); ?>\n';
            
            // Descargar archivo
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'distribucion_' + distribucionId + '_' + new Date().toISOString().split('T')[0] + '.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            // Mostrar notificación
            mostrarNotificacion('Distribución exportada exitosamente', 'success');
        }

        // Función para mostrar notificaciones
        function mostrarNotificacion(mensaje, tipo = 'success') {
            // Crear contenedor de notificaciones si no existe
            let container = document.getElementById('notificaciones-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'notificaciones-container';
                container.style.cssText = `
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    z-index: 9999;
                    max-width: 350px;
                `;
                document.body.appendChild(container);
            }
            
            // Crear notificación
            const notificacion = document.createElement('div');
            notificacion.className = `alert alert-${tipo} alert-dismissible fade show`;
            notificacion.style.cssText = `
                box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
                animation: slideInRight 0.3s ease-out;
            `;
            notificacion.innerHTML = `
                <i class="bi bi-${tipo === 'success' ? 'check-circle' : 'exclamation-triangle'}"></i>
                ${mensaje}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            container.appendChild(notificacion);
            
            // Auto-cerrar después de 5 segundos
            setTimeout(() => {
                notificacion.classList.remove('show');
                setTimeout(() => notificacion.remove(), 300);
            }, 5000);
        }

        // Función para colapsar/expandir tablas
        function toggleTabla(elemento) {
            const tablaItem = elemento.closest('.tabla-item');
            const detalles = tablaItem.querySelector('.table-responsive');
            
            if (detalles.style.display === 'none') {
                detalles.style.display = 'block';
                elemento.querySelector('i').className = 'bi bi-chevron-up';
            } else {
                detalles.style.display = 'none';
                elemento.querySelector('i').className = 'bi bi-chevron-down';
            }
        }

        // Agregar botones de colapsar a cada tabla
        function agregarBotonesColapsar() {
            const tablas = document.querySelectorAll('.tabla-item h6');
            
            tablas.forEach(h6 => {
                if (!h6.querySelector('.btn-colapsar')) {
                    const btnColapsar = document.createElement('button');
                    btnColapsar.className = 'btn btn-sm btn-outline-secondary float-end btn-colapsar';
                    btnColapsar.innerHTML = '<i class="bi bi-chevron-up"></i>';
                    btnColapsar.onclick = function() { toggleTabla(this); };
                    h6.appendChild(btnColapsar);
                }
            });
        }

        // Función para filtrar por proveedor
        function filtrarPorProveedor() {
            const proveedor = prompt('Ingrese el nombre del proveedor a filtrar (dejar vacío para mostrar todos):');
            
            if (proveedor === null) return; // Usuario canceló
            
            const filas = document.querySelectorAll('.tabla-item tbody tr');
            let contadorOcultos = 0;
            
            filas.forEach(fila => {
                const proveedorFila = fila.querySelector('td:first-child').textContent.trim().toLowerCase();
                
                if (proveedor === '' || proveedorFila.includes(proveedor.toLowerCase())) {
                    fila.style.display = '';
                } else {
                    fila.style.display = 'none';
                    contadorOcultos++;
                }
            });
            
            // Ocultar tablas vacías
            const tablas = document.querySelectorAll('.tabla-item');
            tablas.forEach(tabla => {
                const filasVisibles = tabla.querySelectorAll('tbody tr[style=""]').length;
                if (filasVisibles === 0) {
                    tabla.style.display = 'none';
                } else {
                    tabla.style.display = '';
                }
            });
            
            if (proveedor !== '') {
                mostrarNotificacion(`Filtro aplicado: "${proveedor}". ${contadorOcultos} productos ocultos.`, 'info');
            } else {
                mostrarNotificacion('Filtro eliminado. Mostrando todos los productos.', 'info');
            }
        }

        // Función para buscar en toda la distribución
        function buscarEnDistribucion() {
            const termino = prompt('Buscar producto por descripción:');
            
            if (termino === null || termino.trim() === '') return;
            
            const filas = document.querySelectorAll('.tabla-item tbody tr');
            let encontrados = 0;
            
            // Remover resaltados previos
            document.querySelectorAll('.resaltado').forEach(el => {
                el.classList.remove('resaltado');
            });
            
            filas.forEach(fila => {
                const descripcion = fila.querySelector('td:nth-child(2)').textContent.trim().toLowerCase();
                
                if (descripcion.includes(termino.toLowerCase())) {
                    fila.style.backgroundColor = '#fff3cd';
                    fila.classList.add('resaltado');
                    encontrados++;
                    
                    // Expandir la tabla si estaba colapsada
                    const tabla = fila.closest('.tabla-item');
                    const detalles = tabla.querySelector('.table-responsive');
                    if (detalles.style.display === 'none') {
                        detalles.style.display = 'block';
                    }
                    
                    // Scroll al primer resultado
                    if (encontrados === 1) {
                        fila.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                } else {
                    fila.style.backgroundColor = '';
                }
            });
            
            if (encontrados > 0) {
                mostrarNotificacion(`Se encontraron ${encontrados} resultado(s) para "${termino}"`, 'success');
            } else {
                mostrarNotificacion(`No se encontraron resultados para "${termino}"`, 'warning');
            }
        }

        // Función para expandir/colapsar todos
        let todasExpandidas = true;
        function toggleTodas() {
            const detalles = document.querySelectorAll('.tabla-item .table-responsive');
            const botones = document.querySelectorAll('.btn-colapsar i');
            
            detalles.forEach((detalle, index) => {
                detalle.style.display = todasExpandidas ? 'none' : 'block';
                botones[index].className = todasExpandidas ? 'bi bi-chevron-down' : 'bi bi-chevron-up';
            });
            
            todasExpandidas = !todasExpandidas;
            
            const btnToggleTodas = document.getElementById('btnToggleTodas');
            if (btnToggleTodas) {
                btnToggleTodas.innerHTML = todasExpandidas ? 
                    '<i class="bi bi-arrows-collapse"></i> Colapsar Todas' : 
                    '<i class="bi bi-arrows-expand"></i> Expandir Todas';
            }
        }

        // Función para agregar botones adicionales
        function agregarBotonesAdicionales() {
            const actionButtons = document.querySelector('.action-buttons .d-flex');
            
            if (actionButtons && !document.getElementById('btnToggleTodas')) {
                const btnGroup = document.createElement('div');
                btnGroup.className = 'btn-group d-none d-md-block';
                btnGroup.innerHTML = `
                    <button id="btnToggleTodas" onclick="toggleTodas()" class="btn btn-outline-secondary">
                        <i class="bi bi-arrows-collapse"></i> Colapsar Todas
                    </button>
                    <button onclick="filtrarPorProveedor()" class="btn btn-outline-info">
                        <i class="bi bi-funnel"></i> Filtrar
                    </button>
                    <button onclick="buscarEnDistribucion()" class="btn btn-outline-warning">
                        <i class="bi bi-search"></i> Buscar
                    </button>
                `;
                
                // Insertar antes del btn-group de exportar/imprimir
                const lastBtnGroup = actionButtons.querySelector('.btn-group');
                actionButtons.insertBefore(btnGroup, lastBtnGroup);
            }
        }

        // Función para calcular y mostrar estadísticas por proveedor
        function mostrarEstadisticasProveedor() {
            const proveedores = {};
            
            // Recopilar datos por proveedor
            document.querySelectorAll('.tabla-item tbody tr').forEach(fila => {
                const proveedor = fila.querySelector('td:first-child').textContent.trim();
                const cantidad = parseInt(fila.querySelector('td:nth-child(3)').textContent.trim());
                const subtotal = parseFloat(fila.querySelector('td:nth-child(5)').textContent.trim().replace('$', '').replace(',', ''));
                
                if (!proveedores[proveedor]) {
                    proveedores[proveedor] = {
                        cantidad: 0,
                        subtotal: 0,
                        productos: 0
                    };
                }
                
                proveedores[proveedor].cantidad += cantidad;
                proveedores[proveedor].subtotal += subtotal;
                proveedores[proveedor].productos++;
            });
            
            // Crear tabla de estadísticas
            let html = '<h5>Estadísticas por Proveedor</h5><table class="table table-sm table-striped"><thead><tr><th>Proveedor</th><th class="text-center">Productos</th><th class="text-center">Unidades</th><th class="text-end">Total</th></tr></thead><tbody>';
            
            for (const [proveedor, datos] of Object.entries(proveedores)) {
                html += `<tr>
                    <td><strong>${proveedor}</strong></td>
                    <td class="text-center">${datos.productos}</td>
                    <td class="text-center">${datos.cantidad}</td>
                    <td class="text-end">$${datos.subtotal.toFixed(2)}</td>
                </tr>`;
            }
            
            html += '</tbody></table>';
            
            // Mostrar en modal
            const modal = new bootstrap.Modal(document.getElementById('modalEstadisticas'));
            document.getElementById('modalEstadisticasContent').innerHTML = html;
            modal.show();
        }

        // Crear modal para estadísticas
        function crearModalEstadisticas() {
            if (!document.getElementById('modalEstadisticas')) {
                const modalHTML = `
                    <div class="modal fade" id="modalEstadisticas" tabindex="-1">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Estadísticas de la Distribución</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body" id="modalEstadisticasContent">
                                    <!-- Contenido dinámico -->
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                document.body.insertAdjacentHTML('beforeend', modalHTML);
            }
        }

        // Agregar animación de scroll
        function agregarAnimacionScroll() {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '0';
                        entry.target.style.transform = 'translateY(20px)';
                        
                        setTimeout(() => {
                            entry.target.style.transition = 'all 0.5s ease-out';
                            entry.target.style.opacity = '1';
                            entry.target.style.transform = 'translateY(0)';
                        }, 100);
                        
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.1 });
            
            document.querySelectorAll('.dia-card').forEach(card => {
                observer.observe(card);
            });
        }

        // Agregar estilos dinámicos
        function agregarEstilosDinamicos() {
            const style = document.createElement('style');
            style.textContent = `
                @keyframes slideInRight {
                    from {
                        transform: translateX(100%);
                        opacity: 0;
                    }
                    to {
                        transform: translateX(0);
                        opacity: 1;
                    }
                }
                
                .resaltado {
                    animation: pulseHighlight 1s ease-in-out;
                }
                
                @keyframes pulseHighlight {
                    0%, 100% { background-color: #fff3cd; }
                    50% { background-color: #ffc107; }
                }
                
                .tabla-item tbody tr {
                    transition: background-color 0.3s ease;
                }
                
                .tabla-item tbody tr:hover {
                    background-color: #f8f9fa !important;
                }
                
                @media (max-width: 767.98px) {
                    #notificaciones-container {
                        top: 10px !important;
                        right: 10px !important;
                        left: 10px !important;
                        max-width: calc(100% - 20px) !important;
                    }
                }
            `;
            document.head.appendChild(style);
        }

        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            // Inicializar sidebar responsivo
            new ResponsiveSidebar();
            
            // Agregar funcionalidades adicionales
            agregarBotonesColapsar();
            agregarBotonesAdicionales();
            crearModalEstadisticas();
            agregarAnimacionScroll();
            agregarEstilosDinamicos();
            
            // Mostrar notificación de bienvenida
            setTimeout(() => {
                mostrarNotificacion('Distribución cargada exitosamente. Total: <?php echo $total_tablas; ?> tablas', 'success');
            }, 500);
            
            console.log('✅ Ver Distribución - Sistema cargado correctamente');
            console.log('📊 Estadísticas:', {
                totalTablas: <?php echo $total_tablas; ?>,
                totalDias: <?php echo $total_dias; ?>,
                totalUnidades: <?php echo $total_productos_distribuidos; ?>,
                totalValor: <?php echo $total_general; ?>
            });
        });

        // Prevenir pérdida de filtros al usar el botón atrás del navegador
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                location.reload();
            }
        });
    </script>
</body>
</html>