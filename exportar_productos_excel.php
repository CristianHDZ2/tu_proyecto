<?php
header('Content-Type: application/json');
require_once 'config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Obtener filtros si existen
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
    
    // Obtener TODOS los productos (sin límite de paginación)
    $query = "SELECT 
        id, 
        proveedor, 
        descripcion, 
        precio_compra,
        precio_venta, 
        existencia,
        margen_ganancia,
        fecha_creacion 
    FROM productos 
    $where_clause 
    ORDER BY proveedor, descripcion";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular estadísticas generales
    $total_productos = count($productos);
    $total_existencia = 0;
    $valor_total_compra = 0;
    $valor_total_venta = 0;
    
    // Estadísticas por proveedor
    $estadisticas_proveedor = [];
    
    foreach ($productos as &$producto) {
        $total_existencia += $producto['existencia'];
        $valor_total_compra += ($producto['precio_compra'] * $producto['existencia']);
        $valor_total_venta += ($producto['precio_venta'] * $producto['existencia']);
        
        // Formatear fecha
        $producto['fecha_creacion_formato'] = date('d/m/Y H:i', strtotime($producto['fecha_creacion']));
        
        // Calcular valor en inventario
        $producto['valor_compra_inventario'] = $producto['precio_compra'] * $producto['existencia'];
        $producto['valor_venta_inventario'] = $producto['precio_venta'] * $producto['existencia'];
        
        // Agrupar por proveedor
        $proveedor = $producto['proveedor'];
        if (!isset($estadisticas_proveedor[$proveedor])) {
            $estadisticas_proveedor[$proveedor] = [
                'cantidad_productos' => 0,
                'stock_total' => 0,
                'valor_compra_total' => 0,
                'valor_venta_total' => 0,
                'margen_promedio' => 0,
                'suma_margenes' => 0
            ];
        }
        
        $estadisticas_proveedor[$proveedor]['cantidad_productos']++;
        $estadisticas_proveedor[$proveedor]['stock_total'] += $producto['existencia'];
        $estadisticas_proveedor[$proveedor]['valor_compra_total'] += $producto['valor_compra_inventario'];
        $estadisticas_proveedor[$proveedor]['valor_venta_total'] += $producto['valor_venta_inventario'];
        $estadisticas_proveedor[$proveedor]['suma_margenes'] += $producto['margen_ganancia'];
    }
    
    // Calcular margen promedio por proveedor
    foreach ($estadisticas_proveedor as $proveedor => &$stats) {
        $stats['margen_promedio'] = $stats['cantidad_productos'] > 0 
            ? $stats['suma_margenes'] / $stats['cantidad_productos'] 
            : 0;
        unset($stats['suma_margenes']); // Eliminar campo temporal
        
        // Calcular ganancia potencial
        $stats['ganancia_potencial'] = $stats['valor_venta_total'] - $stats['valor_compra_total'];
    }
    
    // Ordenar estadísticas por valor de venta (descendente)
    uasort($estadisticas_proveedor, function($a, $b) {
        return $b['valor_venta_total'] <=> $a['valor_venta_total'];
    });
    
    echo json_encode([
        'success' => true,
        'productos' => $productos,
        'estadisticas' => [
            'total_productos' => $total_productos,
            'total_existencia' => $total_existencia,
            'valor_total_compra' => $valor_total_compra,
            'valor_total_venta' => $valor_total_venta,
            'ganancia_potencial_total' => $valor_total_venta - $valor_total_compra,
            'por_proveedor' => $estadisticas_proveedor
        ],
        'filtros_aplicados' => [
            'busqueda' => $search,
            'proveedor' => $proveedor_filter
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error al obtener productos: ' . $e->getMessage()
    ]);
}
?>