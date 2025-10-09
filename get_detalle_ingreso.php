<?php
header('Content-Type: application/json');
require_once 'config/database.php';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'ID de ingreso no especificado']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $ingreso_id = $_GET['id'];
    
    // Obtener datos del ingreso
    $stmt_ingreso = $db->prepare("SELECT 
        i.*,
        DATE_FORMAT(i.fecha_ingreso, '%d/%m/%Y') as fecha_ingreso,
        DATE_FORMAT(i.fecha_creacion, '%d/%m/%Y %H:%i') as fecha_creacion
    FROM ingresos i WHERE i.id = ?");
    $stmt_ingreso->execute([$ingreso_id]);
    $ingreso = $stmt_ingreso->fetch();
    
    if (!$ingreso) {
        echo json_encode(['success' => false, 'message' => 'Ingreso no encontrado']);
        exit;
    }
    
    // **MODIFICADO: Incluir precio_compra y precio_venta actual del producto para comparar**
    $stmt_detalles = $db->prepare("SELECT 
        di.*,
        p.descripcion,
        p.proveedor,
        p.precio_compra as precio_compra_actual,
        p.precio_venta,
        p.margen_ganancia as margen_actual
    FROM detalle_ingresos di 
    INNER JOIN productos p ON di.producto_id = p.id 
    WHERE di.ingreso_id = ?
    ORDER BY p.descripcion");
    $stmt_detalles->execute([$ingreso_id]);
    $detalles = $stmt_detalles->fetchAll();
    
    // **NUEVO: Agregar información de si el precio cambió desde este ingreso**
    foreach ($detalles as &$detalle) {
        $precio_ingreso = floatval($detalle['precio_compra']);
        $precio_actual = floatval($detalle['precio_compra_actual']);
        
        $detalle['precio_cambio'] = ($precio_ingreso != $precio_actual);
        $detalle['precio_diferencia'] = $precio_actual - $precio_ingreso;
        
        // Calcular el margen que había en ese momento
        if ($precio_ingreso > 0) {
            $precio_venta = floatval($detalle['precio_venta']);
            $detalle['margen_en_ingreso'] = (($precio_venta - $precio_ingreso) / $precio_ingreso) * 100;
        } else {
            $detalle['margen_en_ingreso'] = 0;
        }
    }
    
    echo json_encode([
        'success' => true,
        'ingreso' => $ingreso,
        'detalles' => $detalles
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error al obtener detalle del ingreso: ' . $e->getMessage()
    ]);
}
?>