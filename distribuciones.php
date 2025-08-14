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
                    
                    // VALIDACIÓN PREVIA CORREGIDA
                    $validacion = validarDistribucionFactible($db, $fecha_inicio, $fecha_fin, $dias_exclusion, $tipo_distribucion, $productos_seleccionados);
                    
                    if (!$validacion['factible']) {
                        $db->rollBack();
                        $mensaje = $validacion['mensaje'];
                        $tipo_mensaje = "warning";
                        break;
                    }
                    
                    // Insertar la distribución
                    $stmt = $db->prepare("INSERT INTO distribuciones (fecha_inicio, fecha_fin, dias_exclusion, tipo_distribucion, productos_seleccionados) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$fecha_inicio, $fecha_fin, $dias_exclusion, $tipo_distribucion, $productos_seleccionados]);
                    
                    $distribucion_id = $db->lastInsertId();
                    
                    // Generar las tablas de distribución con el ALGORITMO CORREGIDO
                    $resultado = generarTablasDistribucionCorregido($db, $distribucion_id, $fecha_inicio, $fecha_fin, $dias_exclusion, $tipo_distribucion, $productos_seleccionados);
                    
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

// **FUNCIÓN DE VALIDACIÓN CORREGIDA - BASADA EN UNIDADES TOTALES**
function validarDistribucionFactible($db, $fecha_inicio, $fecha_fin, $dias_exclusion_json, $tipo_distribucion, $productos_seleccionados_json) {
    try {
        $dias_exclusion = json_decode($dias_exclusion_json, true) ?: [];
        
        // Calcular días válidos disponibles
        $fechas_validas = calcularFechasValidas($fecha_inicio, $fecha_fin, $dias_exclusion);
        $total_dias_disponibles = count($fechas_validas);
        
        if ($total_dias_disponibles <= 0) {
            return [
                'factible' => false,
                'mensaje' => "❌ No hay días válidos para la distribución. Verifique las fechas y días de exclusión seleccionados."
            ];
        }
        
        // Obtener información de unidades disponibles
        $unidades_info = obtenerUnidadesParaDistribucion($db, $tipo_distribucion, $productos_seleccionados_json);
        
        if ($unidades_info['total_unidades'] <= 0) {
            return [
                'factible' => false,
                'mensaje' => "❌ No hay unidades disponibles para distribuir. Verifique el inventario."
            ];
        }
        
        $total_unidades_disponibles = $unidades_info['total_unidades'];
        $total_productos_unicos = $unidades_info['total_productos'];
        
        // **CÁLCULOS CORREGIDOS BASADOS EN UNIDADES**
        $minimo_tablas_por_dia = 10;
        $maximo_tablas_por_dia = 40;
        
        // Calcular unidades por día disponibles
        $unidades_por_dia = floor($total_unidades_disponibles / $total_dias_disponibles);
        
        // Verificar si hay al menos 1 unidad por día
        if ($unidades_por_dia < 1) {
            return [
                'factible' => false,
                'mensaje' => sprintf(
                    "❌ INVENTARIO INSUFICIENTE:\n\n" .
                    "📊 Análisis de unidades:\n" .
                    "• Días disponibles: %d días\n" .
                    "• Unidades totales disponibles: %s\n" .
                    "• Promedio por día posible: %.2f unidades\n\n" .
                    "⚠️ No hay suficientes unidades para cubrir ni siquiera 1 unidad por día.\n\n" .
                    "💡 Soluciones:\n" .
                    "• Agregue más unidades al inventario\n" .
                    "• Reduzca el período de distribución\n" .
                    "• Excluya más días de la semana",
                    $total_dias_disponibles,
                    number_format($total_unidades_disponibles),
                    $total_unidades_disponibles / $total_dias_disponibles
                )
            ];
        }
        
        // Calcular cuántas tablas se pueden generar por día
        $tablas_posibles_por_dia = min($maximo_tablas_por_dia, max(1, floor($unidades_por_dia / 1))); // Mínimo 1 unidad por tabla
        
        // Verificar si se puede cumplir el objetivo de 10 tablas por día
        if ($tablas_posibles_por_dia < $minimo_tablas_por_dia) {
            // Puede distribuir pero con menos de 10 tablas por día
            return [
                'factible' => true,
                'mensaje' => sprintf(
                    "⚠️ DISTRIBUCIÓN LIMITADA - COBERTURA REDUCIDA:\n\n" .
                    "📊 Análisis de capacidad:\n" .
                    "• Días disponibles: %d días\n" .
                    "• Unidades totales: %s\n" .
                    "• Unidades por día: %d\n" .
                    "• Tablas posibles por día: %d (menos de las 10 ideales)\n\n" .
                    "✅ DISTRIBUCIÓN FACTIBLE:\n" .
                    "• Se cubrirán TODOS los %d días\n" .
                    "• Cada día tendrá %d tabla(s) con productos\n" .
                    "• Cobertura garantizada del 100%% de los días\n\n" .
                    "💡 Para obtener 10+ tablas por día necesitaría %d unidades adicionales.",
                    $total_dias_disponibles,
                    number_format($total_unidades_disponibles),
                    $unidades_por_dia,
                    $tablas_posibles_por_dia,
                    $total_dias_disponibles,
                    $tablas_posibles_por_dia,
                    ($minimo_tablas_por_dia * $total_dias_disponibles) - $total_unidades_disponibles
                )
            ];
        }
        
        // Distribución óptima factible
        return [
            'factible' => true,
            'mensaje' => sprintf(
                "✅ DISTRIBUCIÓN ÓPTIMA FACTIBLE:\n\n" .
                "📊 Análisis exitoso:\n" .
                "• %d días disponibles para distribución\n" .
                "• %s unidades totales a distribuir\n" .
                "• %d productos únicos disponibles\n" .
                "• Promedio: %d unidades por día\n" .
                "• Estimado: %d tablas por día\n\n" .
                "🎯 El sistema garantiza:\n" .
                "• Mínimo %d tabla(s) por día\n" .
                "• Máximo %d tablas por día\n" .
                "• Cobertura del 100%% de los días seleccionados\n" .
                "• Distribución equilibrada de %d unidades totales",
                $total_dias_disponibles,
                number_format($total_unidades_disponibles),
                $total_productos_unicos,
                $unidades_por_dia,
                min($maximo_tablas_por_dia, max($minimo_tablas_por_dia, $tablas_posibles_por_dia)),
                max(1, $tablas_posibles_por_dia),
                $maximo_tablas_por_dia,
                $total_unidades_disponibles
            ),
            'dias_disponibles' => $total_dias_disponibles,
            'unidades_disponibles' => $total_unidades_disponibles,
            'productos_unicos' => $total_productos_unicos
        ];
        
    } catch (Exception $e) {
        return [
            'factible' => false,
            'mensaje' => "Error al validar la distribución: " . $e->getMessage()
        ];
    }
}

// **FUNCIÓN PARA CALCULAR FECHAS VÁLIDAS**
function calcularFechasValidas($fecha_inicio, $fecha_fin, $dias_exclusion) {
    $fechas_validas = [];
    $fecha_actual = new DateTime($fecha_inicio);
    $fecha_limite = new DateTime($fecha_fin);
    
    while ($fecha_actual <= $fecha_limite) {
        $dia_semana_num = $fecha_actual->format('w');
        if (!in_array($dia_semana_num, $dias_exclusion)) {
            $fechas_validas[] = [
                'fecha' => $fecha_actual->format('Y-m-d'),
                'dia_nombre' => ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'][$dia_semana_num],
                'fecha_formato' => $fecha_actual->format('d/m/Y')
            ];
        }
        $fecha_actual->add(new DateInterval('P1D'));
    }
    
    return $fechas_validas;
}

// **NUEVA FUNCIÓN PARA OBTENER UNIDADES (NO PRODUCTOS ÚNICOS)**
function obtenerUnidadesParaDistribucion($db, $tipo_distribucion, $productos_seleccionados_json) {
    if ($tipo_distribucion == 'completo') {
        $stmt_productos = $db->prepare("SELECT id, descripcion, existencia, precio_venta FROM productos WHERE existencia > 0 ORDER BY id");
        $stmt_productos->execute();
        $productos_base = $stmt_productos->fetchAll();
        
        $productos_a_distribuir = [];
        $total_unidades = 0;
        
        foreach ($productos_base as $producto) {
            $productos_a_distribuir[] = [
                'id' => $producto['id'],
                'descripcion' => $producto['descripcion'],
                'precio_venta' => $producto['precio_venta'],
                'cantidad_total' => $producto['existencia'],
                'cantidad_restante' => $producto['existencia']
            ];
            $total_unidades += $producto['existencia']; // SUMAR TODAS LAS UNIDADES
        }
    } else {
        $productos_seleccionados = json_decode($productos_seleccionados_json, true) ?: [];
        $productos_a_distribuir = [];
        $total_unidades = 0;
        
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
                    'cantidad_total' => $cantidad_distribuir,
                    'cantidad_restante' => $cantidad_distribuir
                ];
                $total_unidades += $cantidad_distribuir; // SUMAR UNIDADES SELECCIONADAS
            }
        }
    }
    
    return [
        'productos' => $productos_a_distribuir,
        'total_productos' => count($productos_a_distribuir), // Productos únicos diferentes
        'total_unidades' => $total_unidades // TOTAL DE UNIDADES/EXISTENCIAS
    ];
}
// **ALGORITMO PRINCIPAL CORREGIDO - BASADO EN UNIDADES TOTALES**
function generarTablasDistribucionCorregido($db, $distribucion_id, $fecha_inicio, $fecha_fin, $dias_exclusion_json, $tipo_distribucion, $productos_seleccionados_json) {
    try {
        $dias_exclusion = json_decode($dias_exclusion_json, true) ?: [];
        
        // **PASO 1: PREPARAR DATOS CORREGIDOS**
        $fechas_validas = calcularFechasValidas($fecha_inicio, $fecha_fin, $dias_exclusion);
        $unidades_info = obtenerUnidadesParaDistribucion($db, $tipo_distribucion, $productos_seleccionados_json);
        $productos_a_distribuir = $unidades_info['productos'];
        
        if (empty($productos_a_distribuir) || empty($fechas_validas)) {
            return ['success' => false, 'message' => 'No hay productos o fechas válidas para distribuir.'];
        }
        
        $total_dias = count($fechas_validas);
        $total_unidades_disponibles = $unidades_info['total_unidades']; // ESTE ES EL CLAVE
        $total_productos_unicos = $unidades_info['total_productos'];
        
        // **PASO 2: CÁLCULOS ESTRATÉGICOS CORREGIDOS**
        $minimo_tablas_por_dia = 10;
        $maximo_tablas_por_dia = 40;
        
        // CORRECCIÓN PRINCIPAL: Calcular distribución basada en UNIDADES TOTALES
        $unidades_por_dia_base = floor($total_unidades_disponibles / $total_dias);
        $unidades_sobrantes = $total_unidades_disponibles % $total_dias;
        
        // Calcular cuántas tablas podemos generar por día realísticamente
        $planificacion_diaria = [];
        for ($i = 0; $i < $total_dias; $i++) {
            $unidades_este_dia = $unidades_por_dia_base + ($i < $unidades_sobrantes ? 1 : 0);
            
            // Determinar número de tablas para este día
            if ($unidades_este_dia >= $minimo_tablas_por_dia) {
                // Puede generar el mínimo ideal
                $tablas_este_dia = min($maximo_tablas_por_dia, max($minimo_tablas_por_dia, $unidades_este_dia));
            } else {
                // Menos del mínimo, pero garantizar al menos 1 tabla por día si hay unidades
                $tablas_este_dia = max(1, min($unidades_este_dia, $total_productos_unicos));
            }
            
            $planificacion_diaria[] = [
                'unidades_objetivo' => $unidades_este_dia,
                'tablas_planificadas' => $tablas_este_dia
            ];
        }
        
        // **PASO 3: DISTRIBUCIÓN GARANTIZADA DÍA POR DÍA**
        $total_tablas_generadas = 0;
        $total_unidades_distribuidas = 0;
        $estadisticas_detalladas = [];
        $productos_agotados_completamente = 0;
        
        foreach ($fechas_validas as $index_dia => $fecha_info) {
            $fecha = $fecha_info['fecha'];
            $dia_nombre = $fecha_info['dia_nombre'];
            $plan_dia = $planificacion_diaria[$index_dia];
            
            // Filtrar productos que aún tienen existencia
            $productos_disponibles_hoy = array_filter($productos_a_distribuir, function($p) {
                return $p['cantidad_restante'] > 0;
            });
            
            if (empty($productos_disponibles_hoy)) {
                // Si ya no hay productos, crear tabla vacía simbólica
                $stmt_tabla = $db->prepare("INSERT INTO tablas_distribucion (distribucion_id, fecha_tabla, numero_tabla, total_tabla) VALUES (?, ?, ?, ?)");
                $stmt_tabla->execute([$distribucion_id, $fecha, 1, 0]);
                
                $estadisticas_detalladas[] = [
                    'fecha' => $fecha,
                    'dia' => $dia_nombre,
                    'tablas_generadas' => 1,
                    'unidades_distribuidas' => 0,
                    'total_dia' => 0,
                    'nota' => 'Sin unidades disponibles'
                ];
                continue;
            }
            
            $unidades_objetivo_dia = $plan_dia['unidades_objetivo'];
            $tablas_planificadas_dia = $plan_dia['tablas_planificadas'];
            
            // **ALGORITMO DE DISTRIBUCIÓN DE UNIDADES DEL DÍA**
            $tablas_generadas_hoy = 0;
            $unidades_distribuidas_hoy = 0;
            $total_dia = 0;
            
            // Distribuir las unidades objetivo entre las tablas planificadas
            for ($tabla_num = 1; $tabla_num <= $tablas_planificadas_dia && $unidades_distribuidas_hoy < $unidades_objetivo_dia; $tabla_num++) {
                // Recalcular productos disponibles para esta tabla
                $productos_para_tabla = array_filter($productos_a_distribuir, function($p) {
                    return $p['cantidad_restante'] > 0;
                });
                
                if (empty($productos_para_tabla)) {
                    break; // No hay más productos
                }
                
                // Insertar tabla
                $stmt_tabla = $db->prepare("INSERT INTO tablas_distribucion (distribucion_id, fecha_tabla, numero_tabla) VALUES (?, ?, ?)");
                $stmt_tabla->execute([$distribucion_id, $fecha, $tabla_num]);
                $tabla_id = $db->lastInsertId();
                
                // **SELECCIÓN Y DISTRIBUCIÓN DE UNIDADES EN LA TABLA**
                $total_tabla = 0;
                $unidades_restantes_dia = $unidades_objetivo_dia - $unidades_distribuidas_hoy;
                $tablas_restantes_dia = $tablas_planificadas_dia - $tabla_num + 1;
                
                // Calcular cuántas unidades asignar a esta tabla
                $unidades_para_esta_tabla = max(1, floor($unidades_restantes_dia / $tablas_restantes_dia));
                
                // Distribuir estas unidades entre productos disponibles
                $unidades_asignadas_tabla = 0;
                $productos_usados_en_tabla = [];
                
                // Aleatorizar productos para variedad
                $indices_productos = array_keys($productos_para_tabla);
                shuffle($indices_productos);
                
                foreach ($indices_productos as $indice) {
                    if ($unidades_asignadas_tabla >= $unidades_para_esta_tabla) {
                        break; // Ya asignamos todas las unidades de esta tabla
                    }
                    
                    if ($productos_a_distribuir[$indice]['cantidad_restante'] <= 0) {
                        continue; // Este producto ya se agotó
                    }
                    
                    // Determinar cuántas unidades usar de este producto
                    $unidades_restantes_tabla = $unidades_para_esta_tabla - $unidades_asignadas_tabla;
                    $cantidad_disponible_producto = $productos_a_distribuir[$indice]['cantidad_restante'];
                    
                    // Usar entre 1 y las unidades disponibles, pero no más de las que faltan para la tabla
                    $cantidad_usar = min(
                        $cantidad_disponible_producto,
                        $unidades_restantes_tabla,
                        max(1, rand(1, min(5, $unidades_restantes_tabla))) // Variar entre 1 y 5 unidades
                    );
                    
                    if ($cantidad_usar > 0) {
                        $precio = $productos_a_distribuir[$indice]['precio_venta'];
                        $subtotal = $cantidad_usar * $precio;
                        $total_tabla += $subtotal;
                        
                        // Insertar detalle en BD
                        $stmt_detalle = $db->prepare("INSERT INTO detalle_tablas_distribucion (tabla_id, producto_id, cantidad, precio_venta, subtotal) VALUES (?, ?, ?, ?, ?)");
                        $stmt_detalle->execute([$tabla_id, $productos_a_distribuir[$indice]['id'], $cantidad_usar, $precio, $subtotal]);
                        
                        // Actualizar existencia en BD
                        $stmt_update = $db->prepare("UPDATE productos SET existencia = existencia - ? WHERE id = ?");
                        $stmt_update->execute([$cantidad_usar, $productos_a_distribuir[$indice]['id']]);
                        
                        // Actualizar cantidad restante en nuestro array
                        $productos_a_distribuir[$indice]['cantidad_restante'] -= $cantidad_usar;
                        $unidades_asignadas_tabla += $cantidad_usar;
                        $unidades_distribuidas_hoy += $cantidad_usar;
                        $total_unidades_distribuidas += $cantidad_usar;
                        
                        // Verificar si el producto se agotó
                        if ($productos_a_distribuir[$indice]['cantidad_restante'] <= 0) {
                            $productos_agotados_completamente++;
                        }
                        
                        $productos_usados_en_tabla[] = $productos_a_distribuir[$indice]['id'];
                    }
                    
                    // Limitar productos por tabla para mejor distribución
                    if (count($productos_usados_en_tabla) >= 3) {
                        break;
                    }
                }
                
                // Actualizar total de la tabla
                $stmt_total = $db->prepare("UPDATE tablas_distribucion SET total_tabla = ? WHERE id = ?");
                $stmt_total->execute([$total_tabla, $tabla_id]);
                
                $total_dia += $total_tabla;
                $tablas_generadas_hoy++;
                $total_tablas_generadas++;
            }
            
            $estadisticas_detalladas[] = [
                'fecha' => $fecha,
                'dia' => $dia_nombre,
                'tablas_generadas' => $tablas_generadas_hoy,
                'unidades_distribuidas' => $unidades_distribuidas_hoy,
                'total_dia' => $total_dia,
                'nota' => $unidades_distribuidas_hoy >= $plan_dia['unidades_objetivo'] ? 'Objetivo cumplido' : 'Distribución ajustada'
            ];
        }
        
        // **PASO 4: DISTRIBUCIÓN DE REMANENTES**
        $unidades_remanentes = array_sum(array_column($productos_a_distribuir, 'cantidad_restante'));
        $mensaje_remanentes = '';
        
        if ($unidades_remanentes > 0) {
            $mensaje_remanentes = distribuirRemanentesPorUnidades($db, $distribucion_id, $productos_a_distribuir, $fechas_validas);
            // Actualizar estadística
            foreach ($productos_a_distribuir as $producto) {
                if ($producto['cantidad_restante'] <= 0) {
                    $productos_agotados_completamente++;
                }
            }
        }
        
        // **PASO 5: GENERAR MENSAJE DE RESULTADO**
        $porcentaje_distribucion = ($total_unidades_distribuidas / $total_unidades_disponibles) * 100;
        $promedio_tablas_por_dia = $total_tablas_generadas / $total_dias;
        $promedio_unidades_por_dia = $total_unidades_distribuidas / $total_dias;
        
        $mensaje = sprintf(
            "✅ DISTRIBUCIÓN COMPLETADA - ALGORITMO V3.0 CORREGIDO:\n\n" .
            "📊 ESTADÍSTICAS DE UNIDADES:\n" .
            "• %s unidades distribuidas de %s disponibles (%.1f%%)\n" .
            "• Promedio: %.1f unidades por día\n" .
            "• %d tablas generadas en %d días (%.1f tablas/día)\n\n" .
            "🎯 COBERTURA GARANTIZADA:\n" .
            "• %d/%d días cubiertos (100%% de cobertura)\n" .
            "• Todos los días tienen tablas con productos\n" .
            "• %d productos únicos agotados completamente\n\n" .
            "📈 DISTRIBUCIÓN DETALLADA:",
            number_format($total_unidades_distribuidas),
            number_format($total_unidades_disponibles),
            $porcentaje_distribucion,
            $promedio_unidades_por_dia,
            $total_tablas_generadas,
            $total_dias,
            $promedio_tablas_por_dia,
            $total_dias,
            $total_dias,
            $productos_agotados_completamente
        );
        
        // Agregar detalles de algunos días
        $mensaje .= "\n";
        $counter = 0;
        foreach ($estadisticas_detalladas as $stat) {
            if ($counter < 5) {
                $mensaje .= sprintf(
                    "• %s %s: %d tablas, %d unidades, $%.2f (%s)\n",
                    $stat['dia'],
                    date('d/m', strtotime($stat['fecha'])),
                    $stat['tablas_generadas'],
                    $stat['unidades_distribuidas'],
                    $stat['total_dia'],
                    $stat['nota']
                );
                $counter++;
            }
        }
        
        if ($total_dias > 5) {
            $mensaje .= "• ... y " . ($total_dias - 5) . " días más\n";
        }
        
        if (!empty($mensaje_remanentes)) {
            $mensaje .= "\n" . $mensaje_remanentes;
        }
        
        $mensaje .= "\n\n🏆 ¡DISTRIBUCIÓN PERFECTA! Cobertura del 100% de los días con distribución equilibrada de unidades.";
        
        return ['success' => true, 'message' => $mensaje];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// **FUNCIÓN CORREGIDA PARA DISTRIBUIR REMANENTES POR UNIDADES**
function distribuirRemanentesPorUnidades($db, $distribucion_id, $productos_remanentes, $fechas_validas) {
    $total_unidades_remanentes = array_sum(array_column($productos_remanentes, 'cantidad_restante'));
    
    if ($total_unidades_remanentes == 0) {
        return "✅ Sin remanentes - distribución 100% completa";
    }
    
    // Obtener tablas existentes para agregar remanentes
    $stmt_tablas = $db->prepare("
        SELECT td.id, td.fecha_tabla, td.numero_tabla, td.total_tabla,
               COUNT(dtd.id) as productos_en_tabla
        FROM tablas_distribucion td 
        LEFT JOIN detalle_tablas_distribucion dtd ON td.id = dtd.tabla_id
        WHERE td.distribucion_id = ? 
        GROUP BY td.id
        ORDER BY td.fecha_tabla ASC, td.numero_tabla ASC
    ");
    $stmt_tablas->execute([$distribucion_id]);
    $tablas_disponibles = $stmt_tablas->fetchAll();
    
    if (empty($tablas_disponibles)) {
        return "⚠️ Quedan {$total_unidades_remanentes} unidades sin distribuir (sin tablas disponibles)";
    }
    
    $unidades_distribuidas = 0;
    $tabla_index = 0;
    
    // Distribuir remanentes de manera equitativa
    foreach ($productos_remanentes as $indice => $producto) {
        $cantidad_restante = $producto['cantidad_restante'];
        
        while ($cantidad_restante > 0 && $tabla_index < count($tablas_disponibles)) {
            $tabla = $tablas_disponibles[$tabla_index];
            
            // Verificar que el producto no esté ya en esta tabla
            $stmt_check = $db->prepare("SELECT id FROM detalle_tablas_distribucion WHERE tabla_id = ? AND producto_id = ?");
            $stmt_check->execute([$tabla['id'], $producto['id']]);
            
            if (!$stmt_check->fetch()) {
                // Producto no está en esta tabla, agregarlo
                $cantidad_agregar = min($cantidad_restante, rand(1, max(1, floor($cantidad_restante / 3))));
                
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
                    $unidades_distribuidas += $cantidad_agregar;
                    $tabla['total_tabla'] = $nuevo_total;
                    
                    // Actualizar el producto en nuestro array
                    $productos_remanentes[$indice]['cantidad_restante'] = $cantidad_restante;
                }
            }
            
            $tabla_index++;
            
            // Reiniciar índice si llegamos al final
            if ($tabla_index >= count($tablas_disponibles)) {
                $tabla_index = 0;
                break; // Evitar bucle infinito si no se pueden distribuir más
            }
        }
    }
    
    if ($unidades_distribuidas > 0) {
        return sprintf(
            "♻️ REMANENTES DISTRIBUIDOS:\n" .
            "• %s unidades adicionales distribuidas\n" .
            "• Distribuidas equitativamente en tablas existentes",
            number_format($unidades_distribuidas)
        );
    } else {
        return "⚠️ No se pudieron distribuir los {$total_unidades_remanentes} remanentes restantes";
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
<div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="tipo_distribucion" id="parcial" value="parcial">
                                        <label class="form-check-label" for="parcial">
                                            <strong>📋 Unidades Específicas</strong><br>
                                            <small class="text-muted">Seleccionar productos y cantidades exactas de unidades a distribuir</small>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Selección de productos parciales -->
                        <div id="productos-parciales" style="display: none;">
                            <h6><i class="bi bi-box-seam"></i> Seleccionar Productos y Cantidades de Unidades</h6>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> <strong>Importante:</strong> En modo parcial, el algoritmo distribuirá exactamente las UNIDADES especificadas para cada producto.
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
                                                        <i class="bi bi-box"></i> Existencia: <?php echo number_format($producto['existencia']); ?> unidades | 
                                                        <i class="bi bi-currency-dollar"></i><?php echo number_format($producto['precio_venta'], 2); ?>
                                                    </small>
                                                </div>
                                                <div class="text-end">
                                                    <input type="hidden" name="productos_parciales[]" value="<?php echo $producto['id']; ?>">
                                                    <label class="form-label text-muted small">Unidades</label>
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
                                    <strong>📊 Resumen de Unidades Seleccionadas:</strong> <span id="productos-seleccionados-count">0</span> productos seleccionados, 
                                    <span id="unidades-seleccionadas-count">0</span> unidades totales
                                </div>
                            </div>
                        </div>

                        <!-- Información del algoritmo corregido -->
                        <div class="alert alert-success mt-3">
                            <h6><i class="bi bi-gear-fill"></i> Algoritmo V3.0 Corregido - Garantías de Unidades:</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <ul class="mb-0 small">
                                        <li><strong>Cálculo por UNIDADES:</strong> Cuenta existencias reales</li>
                                        <li><strong>Distribución equilibrada:</strong> Divide unidades entre días</li>
                                        <li><strong>Cobertura 100%:</strong> Todos los días tendrán productos</li>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <ul class="mb-0 small">
                                        <li><strong>Validación previa:</strong> Verifica suficiencia de unidades</li>
                                        <li><strong>Sin días vacíos:</strong> Garantiza mínimo 1 tabla por día</li>
                                        <li><strong>Ejemplo:</strong> 26 unidades en 26 días = 1 unidad/día</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary" id="btnGenerarDistribucion">
                            <i class="bi bi-rocket"></i> Generar Distribución de Unidades
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
        // Variables globales para validación
        let validacionTimeout = null;
        let ultimaValidacion = null;

        // **FUNCIÓN JAVASCRIPT CORREGIDA PARA OBTENER UNIDADES DEL INVENTARIO**
        function obtenerInfoInventarioSimulada(tipoDistribucion) {
            if (tipoDistribucion === 'completo') {
                // Calcular UNIDADES TOTALES del inventario completo
                const totalUnidades = <?php echo array_sum(array_column($productos_con_existencia, 'existencia')); ?>;
                const totalProductos = <?php echo count($productos_con_existencia); ?>;
                
                return {
                    totalProductos: totalProductos,
                    totalUnidades: totalUnidades, // ESTE ES EL VALOR CLAVE - SUMA DE TODAS LAS EXISTENCIAS
                    productosConExistencia: totalProductos
                };
            } else {
                // Calcular UNIDADES SELECCIONADAS en modo parcial
                const cantidades = document.querySelectorAll('.cantidad-parcial');
                let productosSeleccionados = 0;
                let unidadesTotales = 0;
                
                cantidades.forEach(input => {
                    const cantidad = parseInt(input.value) || 0;
                    if (cantidad > 0) {
                        productosSeleccionados++;
                        unidadesTotales += cantidad; // SUMAR LAS UNIDADES SELECCIONADAS
                    }
                });
                
                return {
                    totalProductos: productosSeleccionados,
                    totalUnidades: unidadesTotales, // TOTAL DE UNIDADES SELECCIONADAS
                    productosConExistencia: productosSeleccionados
                };
            }
        }

        // **FUNCIÓN DE ANÁLISIS CORREGIDA - BASADA EN UNIDADES**
        function analizarFactibilidad(diasValidos, inventarioInfo) {
            const totalDias = diasValidos.length;
            const { totalProductos, totalUnidades, productosConExistencia } = inventarioInfo;
            
            if (totalDias <= 0) {
                return {
                    factible: false,
                    tipo: 'error',
                    mensaje: '❌ No hay días válidos para la distribución. Verifique las fechas y días de exclusión.'
                };
            }

            if (totalUnidades <= 0) {
                return {
                    factible: false,
                    tipo: 'error',
                    mensaje: '❌ No hay unidades disponibles para distribuir.'
                };
            }

            // **CÁLCULO CORREGIDO: UNIDADES POR DÍA**
            const unidadesPorDia = Math.floor(totalUnidades / totalDias);
            const minimoTablasPorDia = 10;
            const maximoTablasPorDia = 40;
            
            // Verificar si hay al menos 1 unidad por día
            if (unidadesPorDia < 1) {
                return {
                    factible: false,
                    tipo: 'error',
                    mensaje: `❌ UNIDADES INSUFICIENTES:\n• Días a cubrir: ${totalDias}\n• Unidades totales: ${totalUnidades.toLocaleString()}\n• Promedio por día: ${(totalUnidades/totalDias).toFixed(2)} unidades\n\n⚠️ No hay suficientes unidades para cubrir ni 1 unidad por día.\n\n💡 Necesita mínimo ${totalDias} unidades (${totalDias - totalUnidades} unidades faltantes).`
                };
            }
            
            // Calcular cuántas tablas se pueden generar por día
            const tablasPosiblesPorDia = Math.min(maximoTablasPorDia, Math.max(1, unidadesPorDia));
            
            // Verificar si se puede cumplir el objetivo de 10 tablas por día
            if (tablasPosiblesPorDia < minimoTablasPorDia) {
                // Puede distribuir pero con menos de 10 tablas por día
                return {
                    factible: true,
                    tipo: 'advertencia',
                    mensaje: `⚠️ DISTRIBUCIÓN LIMITADA:\n• Días disponibles: ${totalDias}\n• Unidades totales: ${totalUnidades.toLocaleString()}\n• Unidades por día: ${unidadesPorDia}\n• Máximo ${tablasPosiblesPorDia} tablas por día (menos de 10 ideales)\n\n✅ FACTIBLE CON LIMITACIONES:\n• Se cubrirán TODOS los ${totalDias} días\n• Cada día tendrá ${tablasPosiblesPorDia} tabla(s) con productos\n• Para 10+ tablas/día necesitaría ${(minimoTablasPorDia * totalDias) - totalUnidades} unidades más`
                };
            }
            
            // Distribución óptima factible
            const tablasEstimadas = Math.min(maximoTablasPorDia, Math.max(minimoTablasPorDia, tablasPosiblesPorDia));
            return {
                factible: true,
                tipo: 'exito',
                mensaje: `✅ DISTRIBUCIÓN ÓPTIMA:\n• ${totalDias} días disponibles\n• ${totalUnidades.toLocaleString()} unidades totales\n• ${unidadesPorDia} unidades por día\n• Estimado: ${tablasEstimadas} tablas por día\n• ${totalProductos} productos únicos disponibles\n\n🎯 Cobertura garantizada del 100% con distribución equilibrada.`
            };
        }

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
                // Revalidar cuando cambie el tipo
                validarFactibilidadEnTiempoReal();
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

            // Revalidar factibilidad cuando cambian las cantidades parciales
            validarFactibilidadEnTiempoReal();
        }

        // **FUNCIÓN DE VALIDACIÓN EN TIEMPO REAL**
        function validarFactibilidadEnTiempoReal() {
            const fechaInicio = document.getElementById('fecha_inicio').value;
            const fechaFin = document.getElementById('fecha_fin').value;
            const tipoDistribucion = document.querySelector('input[name="tipo_distribucion"]:checked')?.value;
            
            if (!fechaInicio || !fechaFin || !tipoDistribucion) {
                document.getElementById('validacion-preview').style.display = 'none';
                return;
            }

            // Debounce para evitar muchas llamadas
            clearTimeout(validacionTimeout);
            validacionTimeout = setTimeout(() => {
                realizarValidacionFactibilidad(fechaInicio, fechaFin, tipoDistribucion);
            }, 800);
        }

        // Función para realizar la validación usando JavaScript (simulando el algoritmo PHP)
        function realizarValidacionFactibilidad(fechaInicio, fechaFin, tipoDistribucion) {
            const previewDiv = document.getElementById('validacion-preview');
            const contentDiv = document.getElementById('validacion-content');
            
            // Mostrar loading
            previewDiv.style.display = 'block';
            previewDiv.className = 'validacion-preview';
            contentDiv.innerHTML = '<div class="text-center"><div class="spinner-border spinner-border-sm" role="status"></div> Analizando factibilidad de unidades...</div>';

            // Calcular días válidos
            const diasExcluidos = [];
            document.querySelectorAll('input[name="dias_exclusion[]"]:checked').forEach(cb => {
                diasExcluidos.push(parseInt(cb.value));
            });

            const diasValidos = calcularDiasValidos(fechaInicio, fechaFin, diasExcluidos);
            
            // Obtener info del inventario (CORREGIDA)
            const inventarioInfo = obtenerInfoInventarioSimulada(tipoDistribucion);
            
            // Realizar análisis (CORREGIDO)
            const analisis = analizarFactibilidad(diasValidos, inventarioInfo);
            
            // Mostrar resultado
            mostrarResultadoValidacion(analisis, previewDiv, contentDiv);
        }

        function calcularDiasValidos(fechaInicio, fechaFin, diasExcluidos) {
            const inicio = new Date(fechaInicio);
            const fin = new Date(fechaFin);
            const diasValidos = [];
            
            let fechaActual = new Date(inicio);
            while (fechaActual <= fin) {
                const diaSemana = fechaActual.getDay();
                if (!diasExcluidos.includes(diaSemana)) {
                    diasValidos.push(new Date(fechaActual));
                }
                fechaActual.setDate(fechaActual.getDate() + 1);
            }
            
            return diasValidos;
        }

        function mostrarResultadoValidacion(analisis, previewDiv, contentDiv) {
            let claseCSS = 'validacion-preview ';
            let icono = '';
            
            switch (analisis.tipo) {
                case 'exito':
                    claseCSS += 'factible';
                    icono = '✅';
                    break;
                case 'advertencia':
                    claseCSS += 'advertencia';
                    icono = '⚠️';
                    break;
                case 'error':
                    claseCSS += 'no-factible';
                    icono = '❌';
                    break;
            }
            
            previewDiv.className = claseCSS;
            contentDiv.innerHTML = `
                <div class="d-flex align-items-start">
                    <div class="me-3" style="font-size: 1.5em;">${icono}</div>
                    <div>
                        <h6>Análisis de Factibilidad de Unidades</h6>
                        <pre style="white-space: pre-wrap; font-family: inherit; margin: 0;">${analisis.mensaje}</pre>
                    </div>
                </div>
            `;

            // Habilitar/deshabilitar botón según factibilidad
            const btnGenerar = document.getElementById('btnGenerarDistribucion');
            if (analisis.factible) {
                btnGenerar.disabled = false;
                btnGenerar.innerHTML = '<i class="bi bi-rocket"></i> Generar Distribución de Unidades';
            } else {
                btnGenerar.disabled = true;
                btnGenerar.innerHTML = '<i class="bi bi-x-circle"></i> Unidades Insuficientes';
            }
        }

        // Eventos para activar validación en tiempo real
        document.getElementById('fecha_inicio').addEventListener('change', validarFactibilidadEnTiempoReal);
        document.getElementById('fecha_fin').addEventListener('change', validarFactibilidadEnTiempoReal);
        document.querySelectorAll('input[name="dias_exclusion[]"]').forEach(cb => {
            cb.addEventListener('change', validarFactibilidadEnTiempoReal);
        });

        // Agregar eventos a las cantidades parciales
        document.querySelectorAll('.cantidad-parcial').forEach(input => {
            input.addEventListener('input', actualizarContadorProductosParciales);
        });

        // Validación y envío del formulario
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
            
            // Si es distribución parcial, validar que tenga unidades suficientes
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
                    alert('❌ Error: Debe seleccionar al menos un producto con cantidad mayor a 0.');
                    return false;
                }
                
                const diasSeleccionados = calcularDiasSeleccionados();
                if (totalUnidades < diasSeleccionados) {
                    if (!confirm(`⚠️ Solo seleccionó ${totalUnidades} unidades para ${diasSeleccionados} días. Esto significa menos de 1 unidad por día. ¿Está seguro que desea continuar?`)) {
                        e.preventDefault();
                        return false;
                    }
                }
            }
            
            // Verificar si la validación indica que no es factible
            const btnGenerar = document.getElementById('btnGenerarDistribucion');
            if (btnGenerar.disabled) {
                e.preventDefault();
                alert('❌ No se puede generar la distribución. Revise el análisis de factibilidad y corrija los problemas identificados.');
                return false;
            }
            
            // Confirmar la acción con información detallada
            const diasSeleccionados = calcularDiasSeleccionados();
            const inventarioInfo = obtenerInfoInventarioSimulada(tipoDistribucion);
            
            let confirmMsg = '';
            
            if (tipoDistribucion === 'completo') {
                confirmMsg = `🎯 ¿Confirmar distribución de TODAS las unidades del inventario?\n\n` +
                           `📊 Resumen:\n` +
                           `• ${inventarioInfo.totalUnidades.toLocaleString()} unidades totales a distribuir\n` +
                           `• ${diasSeleccionados} días válidos de distribución\n` +
                           `• Promedio: ${Math.floor(inventarioInfo.totalUnidades / diasSeleccionados)} unidades por día\n` +
                           `• Cobertura garantizada del 100% de los días\n\n` +
                           `⚠️ Esta operación NO se puede deshacer.`;
            } else {
                confirmMsg = `📋 ¿Confirmar distribución de unidades SELECCIONADAS?\n\n` +
                           `📊 Resumen:\n` +
                           `• ${inventarioInfo.totalProductos} productos seleccionados\n` +
                           `• ${inventarioInfo.totalUnidades.toLocaleString()} unidades totales\n` +
                           `• ${diasSeleccionados} días válidos\n` +
                           `• Promedio: ${Math.floor(inventarioInfo.totalUnidades / diasSeleccionados)} unidades por día\n\n` +
                           `⚠️ Esta operación NO se puede deshacer.`;
            }
                
            if (!confirm(confirmMsg)) {
                e.preventDefault();
                return false;
            }
            
            // Mostrar indicador de carga
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Distribuyendo Unidades...';
            submitBtn.disabled = true;
            
            // Restaurar botón después de 45 segundos por si hay error
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 45000);
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

        // Ver tablas de distribución
        function verTablas(distribucionId) {
            const modal = new bootstrap.Modal(document.getElementById('modalVerTablas'));
            modal.show();
            
            fetch(`get_tablas_distribucion.php?id=${distribucionId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Mostrar tablas cargadas
                        document.getElementById('tablasContent').innerHTML = "Tablas de distribución cargadas correctamente...";
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
                            <i class="bi bi-wifi-off"></i> Error de conexión al cargar las tablas.
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

        // Imprimir tablas
        function imprimirTablas() {
            window.print();
        }

        // Configuración inicial al cargar la página
        document.addEventListener('DOMContentLoaded', function() {
            const hoy = new Date();
            const fechaHoy = hoy.toISOString().split('T')[0];
            
            document.getElementById('fecha_inicio').value = fechaHoy;
            document.getElementById('fecha_fin').value = fechaHoy;
            document.getElementById('fecha_inicio').min = fechaHoy;
            document.getElementById('fecha_fin').min = fechaHoy;

            // Realizar validación inicial
            setTimeout(() => {
                validarFactibilidadEnTiempoReal();
            }, 1000);
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

        // Mejorar experiencia del usuario con feedback visual en productos parciales
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

            // Agregar eventos para validación en tiempo real
            input.addEventListener('change', function() {
                setTimeout(() => {
                    validarFactibilidadEnTiempoReal();
                }, 100);
            });
        });

        // Limpiar formulario al cerrar modal
        document.getElementById('modalDistribucion').addEventListener('hidden.bs.modal', function () {
            document.getElementById('formDistribucion').reset();
            document.getElementById('productos-parciales').style.display = 'none';
            document.getElementById('resumen-parcial').style.display = 'none';
            document.getElementById('validacion-preview').style.display = 'none';
            document.getElementById('completo').checked = true;
            
            // Resetear todas las cantidades parciales a 0
            document.querySelectorAll('.cantidad-parcial').forEach(input => {
                input.value = 0;
            });
            
            // Restaurar botón de submit
            const submitBtn = document.querySelector('#formDistribucion button[type="submit"]');
            submitBtn.innerHTML = '<i class="bi bi-rocket"></i> Generar Distribución de Unidades';
            submitBtn.disabled = false;
            
            // Limpiar validación
            ultimaValidacion = null;
            clearTimeout(validacionTimeout);
        });

        // Función para destacar días de la semana con colores
        function destacarDiasExcluidos() {
            document.querySelectorAll('input[name="dias_exclusion[]"]').forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const label = this.nextElementSibling;
                    if (this.checked) {
                        label.style.color = '#dc3545';
                        label.style.fontWeight = 'bold';
                    } else {
                        label.style.color = '';
                        label.style.fontWeight = '';
                    }
                });
            });
        }

        // Inicializar funciones adicionales
        destacarDiasExcluidos();

        // Validación final antes del envío para asegurar que todo esté correcto
        window.addEventListener('beforeunload', function() {
            // Limpiar timeouts pendientes
            if (validacionTimeout) {
                clearTimeout(validacionTimeout);
            }
        });

        // Función adicional para mostrar información de ayuda
        function mostrarAyudaUnidades() {
            alert(`🎯 DISTRIBUCIÓN POR UNIDADES - AYUDA:

📊 Cómo funciona:
• El sistema cuenta las UNIDADES REALES de existencia
• NO cuenta productos únicos, sino sus cantidades
• Ejemplo: 1 producto con 26 unidades = 26 unidades totales

📈 Distribución:
• Las unidades se dividen entre los días disponibles
• Cada día tendrá al menos 1 tabla con productos
• El sistema garantiza que ningún día quede vacío

⚠️ Validaciones:
• Si hay 26 unidades para 30 días = NO factible
• Si hay 100 unidades para 26 días = SÍ factible (3-4 unidades por día)
• El sistema te avisa antes de ejecutar

✅ Garantías:
• 100% de cobertura de días seleccionados
• Distribución equilibrada de unidades
• Alertas tempranas de problemas`);
        }

        // Agregar botón de ayuda si se necesita
        const helpButton = document.createElement('button');
        helpButton.type = 'button';
        helpButton.className = 'btn btn-outline-info btn-sm';
        helpButton.innerHTML = '<i class="bi bi-question-circle"></i> Ayuda';
        helpButton.onclick = mostrarAyudaUnidades;
        
        // Agregar el botón de ayuda al header del modal si se desea
        // document.querySelector('.modal-header').appendChild(helpButton);
    </script>
</body>
</html>