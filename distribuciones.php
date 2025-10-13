<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// **FUNCIONES AUXILIARES**

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

// **FUNCIÓN PARA OBTENER UNIDADES**
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
            $total_unidades += $producto['existencia'];
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
                $total_unidades += $cantidad_distribuir;
            }
        }
    }
    
    return [
        'productos' => $productos_a_distribuir,
        'total_productos' => count($productos_a_distribuir),
        'total_unidades' => $total_unidades
    ];
}

// **ALGORITMO MEJORADO V5 - DISTRIBUYE TODO EL INVENTARIO SIN SOBRANTES**
function generarTablasDistribucionV5($db, $distribucion_id, $fecha_inicio, $fecha_fin, $dias_exclusion_json, $tipo_distribucion, $productos_seleccionados_json) {
    try {
        $dias_exclusion = json_decode($dias_exclusion_json, true) ?: [];
        
        // **PASO 1: PREPARAR DATOS**
        $fechas_validas = calcularFechasValidas($fecha_inicio, $fecha_fin, $dias_exclusion);
        $unidades_info = obtenerUnidadesParaDistribucion($db, $tipo_distribucion, $productos_seleccionados_json);
        $productos_a_distribuir = $unidades_info['productos'];
        
        if (empty($productos_a_distribuir) || empty($fechas_validas)) {
            return ['success' => false, 'message' => 'No hay productos o fechas válidas para distribuir.'];
        }
        
        $total_dias = count($fechas_validas);
        $total_unidades_disponibles = $unidades_info['total_unidades'];
        $total_productos_unicos = $unidades_info['total_productos'];
        
        // **PASO 2: CREAR INVENTARIO DE CONTROL**
        $inventario_control = [];
        foreach ($productos_a_distribuir as $producto) {
            $inventario_control[$producto['id']] = [
                'cantidad_restante' => $producto['cantidad_restante'],
                'descripcion' => $producto['descripcion'],
                'precio_venta' => $producto['precio_venta'],
                'cantidad_original' => $producto['cantidad_total']
            ];
        }
        
        // **PASO 3: CALCULAR DISTRIBUCIÓN POR DÍA**
        // Distribuir las unidades de manera equilibrada pero con variación
        $unidades_por_dia_base = floor($total_unidades_disponibles / $total_dias);
        $unidades_sobrantes = $total_unidades_disponibles % $total_dias;
        
        $planificacion_diaria = [];
        for ($i = 0; $i < $total_dias; $i++) {
            // Asignar unidades base + sobrantes distribuidos al inicio
            $unidades_este_dia = $unidades_por_dia_base;
            if ($i < $unidades_sobrantes) {
                $unidades_este_dia++;
            }
            
            // Calcular cuántas tablas generar (variado entre días)
            // Mínimo 1 tabla, máximo 50 tablas
            if ($unidades_este_dia >= 50) {
                // Si hay muchas unidades, variar entre 20 y 50 tablas
                $tablas_este_dia = rand(20, 50);
            } elseif ($unidades_este_dia >= 20) {
                // Para cantidades medianas, variar proporcionalmente
                $tablas_este_dia = rand(10, min(40, $unidades_este_dia));
            } elseif ($unidades_este_dia >= 10) {
                // Para pocas unidades, variar entre 5 y 20
                $tablas_este_dia = rand(5, min(20, $unidades_este_dia));
            } else {
                // Para muy pocas unidades, crear entre 1 y el número de unidades
                $tablas_este_dia = rand(1, max(1, $unidades_este_dia));
            }
            
            // Asegurar que no haya más tablas que unidades disponibles
            $tablas_este_dia = min($tablas_este_dia, $unidades_este_dia);
            // Aplicar límite máximo de 50 tablas
            $tablas_este_dia = min($tablas_este_dia, 50);
            
            $planificacion_diaria[] = [
                'unidades_objetivo' => $unidades_este_dia,
                'tablas_planificadas' => $tablas_este_dia
            ];
        }
        // **PASO 4: DISTRIBUCIÓN DÍA POR DÍA**
        $total_tablas_generadas = 0;
        $total_unidades_distribuidas = 0;
        $estadisticas_detalladas = [];
        
        foreach ($fechas_validas as $index_dia => $fecha_info) {
            $fecha = $fecha_info['fecha'];
            $dia_nombre = $fecha_info['dia_nombre'];
            $plan_dia = $planificacion_diaria[$index_dia];
            
            // Filtrar productos disponibles
            $productos_disponibles_hoy = [];
            foreach ($inventario_control as $producto_id => $datos) {
                if ($datos['cantidad_restante'] > 0) {
                    $productos_disponibles_hoy[$producto_id] = $datos;
                }
            }
            
            if (empty($productos_disponibles_hoy)) {
                // Ya no hay productos disponibles
                continue;
            }
            
            $unidades_objetivo_dia = $plan_dia['unidades_objetivo'];
            $tablas_planificadas_dia = $plan_dia['tablas_planificadas'];
            
            // **DISTRIBUCIÓN DE TABLAS DEL DÍA**
            $tablas_generadas_hoy = 0;
            $unidades_distribuidas_hoy = 0;
            $total_dia = 0;
            
            // Calcular cuántas unidades por tabla aproximadamente
            $unidades_por_tabla_base = max(1, floor($unidades_objetivo_dia / $tablas_planificadas_dia));
            $unidades_sobrantes_dia = $unidades_objetivo_dia % $tablas_planificadas_dia;
            
            for ($tabla_num = 1; $tabla_num <= $tablas_planificadas_dia; $tabla_num++) {
                // Verificar si aún hay productos disponibles
                $productos_para_tabla = [];
                foreach ($inventario_control as $producto_id => $datos) {
                    if ($datos['cantidad_restante'] > 0) {
                        $productos_para_tabla[$producto_id] = $datos;
                    }
                }
                
                if (empty($productos_para_tabla)) {
                    break; // No hay más productos
                }
                
                // Calcular unidades para esta tabla
                $unidades_para_esta_tabla = $unidades_por_tabla_base;
                if ($tabla_num <= $unidades_sobrantes_dia) {
                    $unidades_para_esta_tabla++;
                }
                
                // Si es la última tabla del día, asignar todas las unidades restantes del día
                if ($tabla_num == $tablas_planificadas_dia) {
                    $unidades_para_esta_tabla = $unidades_objetivo_dia - $unidades_distribuidas_hoy;
                }
                
                // Asegurar que no asignamos más unidades de las disponibles
                $total_disponible_ahora = array_sum(array_column($productos_para_tabla, 'cantidad_restante'));
                $unidades_para_esta_tabla = min($unidades_para_esta_tabla, $total_disponible_ahora);
                
                if ($unidades_para_esta_tabla <= 0) {
                    break; // No hay unidades para asignar
                }
                
                // Insertar tabla
                $stmt_tabla = $db->prepare("INSERT INTO tablas_distribucion (distribucion_id, fecha_tabla, numero_tabla) VALUES (?, ?, ?)");
                $stmt_tabla->execute([$distribucion_id, $fecha, $tabla_num]);
                $tabla_id = $db->lastInsertId();
                
                // **DISTRIBUIR UNIDADES EN LA TABLA**
                $total_tabla = 0;
                $unidades_asignadas_tabla = 0;
                $productos_usados_en_tabla = [];
                
                // Aleatorizar productos para variedad
                $ids_productos_disponibles = array_keys($productos_para_tabla);
                shuffle($ids_productos_disponibles);
                
                foreach ($ids_productos_disponibles as $producto_id) {
                    if ($unidades_asignadas_tabla >= $unidades_para_esta_tabla) {
                        break; // Ya completamos las unidades de esta tabla
                    }
                    
                    if ($inventario_control[$producto_id]['cantidad_restante'] <= 0) {
                        continue; // Este producto ya se agotó
                    }
                    
                    // Verificar que no hayamos usado este producto en esta tabla
                    if (in_array($producto_id, $productos_usados_en_tabla)) {
                        continue; // No repetir producto en la misma tabla
                    }
                    
                    // Calcular cuántas unidades usar de este producto
                    $unidades_restantes_tabla = $unidades_para_esta_tabla - $unidades_asignadas_tabla;
                    $cantidad_disponible_producto = $inventario_control[$producto_id]['cantidad_restante'];
                    
                    // Usar entre 1 y 5 unidades por producto para mejor distribución
                    $cantidad_max_por_producto = min(5, $unidades_restantes_tabla, $cantidad_disponible_producto);
                    $cantidad_usar = rand(1, max(1, $cantidad_max_por_producto));
                    
                    // Si es el último producto necesario para completar la tabla, usar exactamente lo que falta
                    if ($unidades_restantes_tabla <= 5 || count($productos_usados_en_tabla) >= 10) {
                        $cantidad_usar = min($unidades_restantes_tabla, $cantidad_disponible_producto);
                    }
                    
                    if ($cantidad_usar > 0) {
                        $precio = $inventario_control[$producto_id]['precio_venta'];
                        $subtotal = $cantidad_usar * $precio;
                        $total_tabla += $subtotal;
                        
                        // Insertar detalle en BD
                        $stmt_detalle = $db->prepare("INSERT INTO detalle_tablas_distribucion (tabla_id, producto_id, cantidad, precio_venta, subtotal) VALUES (?, ?, ?, ?, ?)");
                        $stmt_detalle->execute([$tabla_id, $producto_id, $cantidad_usar, $precio, $subtotal]);
                        
                        // Actualizar existencia en BD
                        $stmt_update = $db->prepare("UPDATE productos SET existencia = existencia - ? WHERE id = ?");
                        $stmt_update->execute([$cantidad_usar, $producto_id]);
                        
                        // Actualizar inventario de control
                        $inventario_control[$producto_id]['cantidad_restante'] -= $cantidad_usar;
                        
                        // Actualizar contadores
                        $unidades_asignadas_tabla += $cantidad_usar;
                        $unidades_distribuidas_hoy += $cantidad_usar;
                        $total_unidades_distribuidas += $cantidad_usar;
                        
                        $productos_usados_en_tabla[] = $producto_id;
                    }
                    
                    // Limitar productos por tabla
                    if (count($productos_usados_en_tabla) >= 15) {
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
                'objetivo' => $plan_dia['unidades_objetivo']
            ];
        }
        
        // **PASO 5: VERIFICACIÓN FINAL - DISTRIBUIR REMANENTES**
        $unidades_remanentes = 0;
        $productos_con_remanentes = [];
        foreach ($inventario_control as $producto_id => $datos) {
            if ($datos['cantidad_restante'] > 0) {
                $unidades_remanentes += $datos['cantidad_restante'];
                $productos_con_remanentes[$producto_id] = $datos['cantidad_restante'];
            }
        }
        
        // **PASO 6: SI HAY REMANENTES, DISTRIBUIRLOS EN TODOS LOS DÍAS (MODIFICADO)**
        if ($unidades_remanentes > 0 && !empty($fechas_validas)) {
            
            $dias_totales_disponibles = count($fechas_validas);
            $indice_dia_rotacion = 0;
            $intentos_globales = 0;
            $max_intentos_seguros = 1000;
            
            while ($unidades_remanentes > 0 && $intentos_globales < $max_intentos_seguros) {
                $intentos_globales++;
                
                $fecha_info_actual = $fechas_validas[$indice_dia_rotacion];
                $fecha_distribucion = $fecha_info_actual['fecha'];
                
                $stmt_num_tabla = $db->prepare("SELECT MAX(numero_tabla) as max_num FROM tablas_distribucion WHERE distribucion_id = ? AND fecha_tabla = ?");
                $stmt_num_tabla->execute([$distribucion_id, $fecha_distribucion]);
                $resultado_max = $stmt_num_tabla->fetch();
                $numero_tabla_siguiente = ($resultado_max['max_num'] ?? 0) + 1;
                
                if ($numero_tabla_siguiente > 50) {
                    $indice_dia_rotacion = ($indice_dia_rotacion + 1) % $dias_totales_disponibles;
                    
                    $stmt_verificar_limite = $db->prepare("
                        SELECT COUNT(DISTINCT fecha_tabla) as dias_llenos 
                        FROM (
                            SELECT fecha_tabla, COUNT(*) as tablas_del_dia 
                            FROM tablas_distribucion 
                            WHERE distribucion_id = ? 
                            GROUP BY fecha_tabla 
                            HAVING tablas_del_dia >= 50
                        ) sub
                    ");
                    $stmt_verificar_limite->execute([$distribucion_id]);
                    $verificacion = $stmt_verificar_limite->fetch();
                    
                    if ($verificacion['dias_llenos'] >= $dias_totales_disponibles) {
                        break;
                    }
                    continue;
                }
                
                if (empty($productos_con_remanentes)) {
                    break;
                }
                
                $stmt_nueva_tabla = $db->prepare("INSERT INTO tablas_distribucion (distribucion_id, fecha_tabla, numero_tabla) VALUES (?, ?, ?)");
                $stmt_nueva_tabla->execute([$distribucion_id, $fecha_distribucion, $numero_tabla_siguiente]);
                $id_tabla_nueva = $db->lastInsertId();
                
                $total_monetario_tabla = 0;
                $unidades_en_esta_tabla = 0;
                $productos_ya_usados = [];
                
                $ids_productos_remanentes = array_keys($productos_con_remanentes);
                shuffle($ids_productos_remanentes);
                
                foreach ($ids_productos_remanentes as $id_prod) {
                    if (!isset($inventario_control[$id_prod]) || $inventario_control[$id_prod]['cantidad_restante'] <= 0) {
                        continue;
                    }
                    
                    if (in_array($id_prod, $productos_ya_usados)) {
                        continue;
                    }
                    
                    $stock_disponible = $inventario_control[$id_prod]['cantidad_restante'];
                    
                    if ($stock_disponible >= 100) {
                        $cantidad_a_distribuir = rand(20, min(30, $stock_disponible));
                    } elseif ($stock_disponible >= 50) {
                        $cantidad_a_distribuir = rand(15, min(25, $stock_disponible));
                    } elseif ($stock_disponible >= 20) {
                        $cantidad_a_distribuir = rand(10, min(20, $stock_disponible));
                    } elseif ($stock_disponible >= 10) {
                        $cantidad_a_distribuir = rand(5, min(15, $stock_disponible));
                    } else {
                        $cantidad_a_distribuir = $stock_disponible;
                    }
                    
                    if ($cantidad_a_distribuir > 0) {
                        $precio_unitario = $inventario_control[$id_prod]['precio_venta'];
                        $subtotal_producto = $cantidad_a_distribuir * $precio_unitario;
                        $total_monetario_tabla += $subtotal_producto;
                        
                        $stmt_detalle_nuevo = $db->prepare("INSERT INTO detalle_tablas_distribucion (tabla_id, producto_id, cantidad, precio_venta, subtotal) VALUES (?, ?, ?, ?, ?)");
                        $stmt_detalle_nuevo->execute([$id_tabla_nueva, $id_prod, $cantidad_a_distribuir, $precio_unitario, $subtotal_producto]);
                        
                        $stmt_actualizar_stock = $db->prepare("UPDATE productos SET existencia = existencia - ? WHERE id = ?");
                        $stmt_actualizar_stock->execute([$cantidad_a_distribuir, $id_prod]);
                        
                        $inventario_control[$id_prod]['cantidad_restante'] -= $cantidad_a_distribuir;
                        $unidades_remanentes -= $cantidad_a_distribuir;
                        $unidades_en_esta_tabla += $cantidad_a_distribuir;
                        $total_unidades_distribuidas += $cantidad_a_distribuir;
                        
                        $productos_ya_usados[] = $id_prod;
                        
                        if ($inventario_control[$id_prod]['cantidad_restante'] <= 0) {
                            unset($productos_con_remanentes[$id_prod]);
                        } else {
                            $productos_con_remanentes[$id_prod] = $inventario_control[$id_prod]['cantidad_restante'];
                        }
                    }
                    
                    if ($unidades_remanentes <= 0) break;
                    if (count($productos_ya_usados) >= 25) break;
                }
                
                $stmt_actualizar_total = $db->prepare("UPDATE tablas_distribucion SET total_tabla = ? WHERE id = ?");
                $stmt_actualizar_total->execute([$total_monetario_tabla, $id_tabla_nueva]);
                
                $total_tablas_generadas++;
                
                if ($unidades_en_esta_tabla <= 0) {
                    $stmt_borrar_vacia = $db->prepare("DELETE FROM tablas_distribucion WHERE id = ?");
                    $stmt_borrar_vacia->execute([$id_tabla_nueva]);
                    $total_tablas_generadas--;
                }
                
                $indice_dia_rotacion = ($indice_dia_rotacion + 1) % $dias_totales_disponibles;
            }
        }
        
        // Recalcular remanentes finales
        $unidades_remanentes_final = 0;
        foreach ($inventario_control as $producto_id => $datos) {
            if ($datos['cantidad_restante'] > 0) {
                $unidades_remanentes_final += $datos['cantidad_restante'];
            }
        }
        // **PASO 7: GENERAR MENSAJE DE RESULTADO**
        $porcentaje_distribucion = $total_unidades_disponibles > 0 ? 
            ($total_unidades_distribuidas / $total_unidades_disponibles) * 100 : 0;
        $promedio_tablas_por_dia = $total_dias > 0 ? $total_tablas_generadas / $total_dias : 0;
        $promedio_unidades_por_dia = $total_dias > 0 ? $total_unidades_distribuidas / $total_dias : 0;
        
        $mensaje = sprintf(
            "✅ DISTRIBUCIÓN COMPLETADA - ALGORITMO V5.0:\n\n" .
            "📊 ESTADÍSTICAS FINALES:\n" .
            "• %s de %s unidades distribuidas (%.2f%%)\n" .
            "• %d tablas generadas en %d días\n" .
            "• Promedio: %.1f tablas/día | %.1f unidades/día\n" .
            "• Remanentes: %d unidades\n\n" .
            "🎯 CARACTERÍSTICAS:\n" .
            "• Tablas variadas por día (no siempre la misma cantidad)\n" .
            "• Cada tabla tiene mínimo 1 producto\n" .
            "• Sin repetición de productos en misma tabla\n" .
            "• Límite máximo: 50 tablas por día\n\n" .
            "📈 PRIMEROS 5 DÍAS:",
            number_format($total_unidades_distribuidas),
            number_format($total_unidades_disponibles),
            $porcentaje_distribucion,
            $total_tablas_generadas,
            $total_dias,
            $promedio_tablas_por_dia,
            $promedio_unidades_por_dia,
            $unidades_remanentes_final
        );
        
        // Mostrar detalle de los primeros días
        $mensaje .= "\n";
        $contador = 0;
        foreach ($estadisticas_detalladas as $stat) {
            if ($contador < 5) {
                $mensaje .= sprintf(
                    "• %s %s: %d tablas | %d unidades | $%.2f\n",
                    $stat['dia'],
                    date('d/m', strtotime($stat['fecha'])),
                    $stat['tablas_generadas'],
                    $stat['unidades_distribuidas'],
                    $stat['total_dia']
                );
                $contador++;
            }
        }
        
        if ($total_dias > 5) {
            $mensaje .= "• ... y " . ($total_dias - 5) . " días más\n";
        }
        
        if ($unidades_remanentes_final > 0) {
            $mensaje .= sprintf(
                "\n⚠️ REMANENTES: %d unidades (%.2f%%) quedaron sin distribuir debido al límite de 50 tablas por día",
                $unidades_remanentes_final,
                ($unidades_remanentes_final / $total_unidades_disponibles) * 100
            );
        } else {
            $mensaje .= "\n\n🎉 ¡PERFECTO! TODO el inventario fue distribuido (0 remanentes)";
        }
        
        return ['success' => true, 'message' => $mensaje];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// **MANEJO DE PETICIONES POST**
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    if ($accion) {
        switch ($accion) {
            case 'crear_distribucion':
                try {
                    $db->beginTransaction();
                    
                    $fecha_inicio = $_POST['fecha_inicio'];
                    $fecha_fin = $_POST['fecha_fin'];
                    $dias_exclusion = $_POST['dias_exclusion'];
                    $tipo_distribucion = $_POST['tipo_distribucion'];
                    $productos_seleccionados = $_POST['productos_seleccionados'];
                    
                    $stmt = $db->prepare("INSERT INTO distribuciones (fecha_inicio, fecha_fin, dias_exclusion, tipo_distribucion, productos_seleccionados) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$fecha_inicio, $fecha_fin, $dias_exclusion, $tipo_distribucion, $productos_seleccionados]);
                    
                    $distribucion_id = $db->lastInsertId();
                    
                    // Generar las tablas de distribución con el ALGORITMO MEJORADO V5
                    $resultado = generarTablasDistribucionV5($db, $distribucion_id, $fecha_inicio, $fecha_fin, $dias_exclusion, $tipo_distribucion, $productos_seleccionados);
                    
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
                    $mensaje = "Distribución eliminada correctamente y existencias revertidas.";
                    $tipo_mensaje = "success";
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    $mensaje = "Error al eliminar la distribución: " . $e->getMessage();
                    $tipo_mensaje = "danger";
                }
                break;
        }
        
        header("Location: distribuciones.php?mensaje=" . urlencode($mensaje) . "&tipo=" . $tipo_mensaje);
        exit();
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
    <title>Gestión de Distribuciones - Sistema de Inventario</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .sidebar {
            height: 100vh;
            background-color: #343a40;
            position: fixed;
            top: 0;
            left: 0;
            overflow-y: auto;
        }
        .sidebar a {
            color: white;
            text-decoration: none;
            padding: 15px;
            display: block;
        }
        .sidebar a:hover {
            background-color: #495057;
        }
        .sidebar a.active {
            background-color: #007bff;
        }
        .content {
            margin-left: 250px;
            padding: 20px;
        }
        @media (max-width: 768px) {
            .sidebar {
                position: relative;
                height: auto;
                width: 100%;
            }
            .content {
                margin-left: 0;
            }
        }
        .table-responsive {
            overflow-x: auto;
        }
        .btn-group-sm > .btn {
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-2 d-md-block sidebar">
                <div class="sidebar-sticky">
                    <h5 class="text-white text-center py-3">Sistema Inventario</h5>
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a href="index.php"><i class="bi bi-house-door"></i> Inicio</a>
                        </li>
                        <li class="nav-item">
                            <a href="productos.php"><i class="bi bi-box-seam"></i> Productos</a>
                        </li>
                        <li class="nav-item">
                            <a href="ingresos.php"><i class="bi bi-cart-plus"></i> Ingresos</a>
                        </li>
                        <li class="nav-item">
                            <a href="distribuciones.php" class="active"><i class="bi bi-calendar-check"></i> Distribuciones</a>
                        </li>
                        <li class="nav-item">
                            <a href="reportes.php"><i class="bi bi-graph-up"></i> Reportes</a>
                        </li>
                        <li class="nav-item">
                            <a href="logout.php"><i class="bi bi-box-arrow-right"></i> Cerrar Sesión</a>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Contenido Principal -->
            <main class="col-md-10 ms-sm-auto px-md-4 content">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Gestión de Distribuciones</h1>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNuevaDistribucion">
                        <i class="bi bi-plus-circle"></i> Nueva Distribución
                    </button>
                </div>

                <?php if (isset($_GET['mensaje'])): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($_GET['tipo']); ?> alert-dismissible fade show" role="alert">
                        <?php echo nl2br(htmlspecialchars($_GET['mensaje'])); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Tabla de Distribuciones -->
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Fecha Inicio</th>
                                <th>Fecha Fin</th>
                                <th>Tipo</th>
                                <th>Total Tablas</th>
                                <th>Total $</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($distribuciones as $dist): ?>
                                <tr>
                                    <td><?php echo $dist['id']; ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($dist['fecha_inicio'])); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($dist['fecha_fin'])); ?></td>
                                    <td><span class="badge bg-<?php echo $dist['tipo_distribucion'] == 'completo' ? 'primary' : 'warning'; ?>"><?php echo ucfirst($dist['tipo_distribucion']); ?></span></td>
                                    <td><?php echo $dist['total_tablas'] ?? 0; ?></td>
                                    <td>$<?php echo number_format($dist['total_distribucion'] ?? 0, 2); ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="ver_distribucion.php?id=<?php echo $dist['id']; ?>" class="btn btn-info btn-sm">
                                                <i class="bi bi-eye"></i> Ver
                                            </a>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('¿Está seguro de eliminar esta distribución? Se revertirán las existencias.');">
                                                <input type="hidden" name="accion" value="eliminar_distribucion">
                                                <input type="hidden" name="distribucion_id" value="<?php echo $dist['id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">
                                                    <i class="bi bi-trash"></i> Eliminar
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Paginación -->
                <?php if ($total_pages > 1): ?>
                    <nav>
                        <ul class="pagination justify-content-center">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>

                <!-- Modal Nueva Distribución -->
                <div class="modal fade" id="modalNuevaDistribucion" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <form method="POST" id="formNuevaDistribucion">
                                <input type="hidden" name="accion" value="crear_distribucion">
                                <div class="modal-header">
                                    <h5 class="modal-title">Nueva Distribución</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Fecha Inicio</label>
                                            <input type="date" class="form-control" name="fecha_inicio" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Fecha Fin</label>
                                            <input type="date" class="form-control" name="fecha_fin" required>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Excluir Días de la Semana</label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" value="0" name="dias_excluir[]" id="domingo">
                                            <label class="form-check-label" for="domingo">Domingo</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" value="6" name="dias_excluir[]" id="sabado">
                                            <label class="form-check-label" for="sabado">Sábado</label>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Tipo de Distribución</label>
                                        <select class="form-select" name="tipo_distribucion" id="tipoDistribucion" required>
                                            <option value="completo">Inventario Completo</option>
                                            <option value="parcial">Productos Específicos</option>
                                        </select>
                                    </div>

                                    <div id="seleccionProductos" style="display:none;">
                                        <label class="form-label">Seleccionar Productos</label>
                                        <div class="list-group" style="max-height: 300px; overflow-y: auto;">
                                            <?php 
                                            $current_proveedor = '';
                                            foreach ($productos_con_existencia as $producto): 
                                                if ($current_proveedor != $producto['proveedor']):
                                                    if ($current_proveedor != '') echo '</div></div>';
                                                    $current_proveedor = $producto['proveedor'];
                                            ?>
                                                <div class="card mb-2">
                                                    <div class="card-header bg-secondary text-white">
                                                        <strong><?php echo htmlspecialchars($producto['proveedor']); ?></strong>
                                                    </div>
                                                    <div class="card-body p-2">
                                            <?php endif; ?>
                                                        <div class="form-check">
                                                            <input class="form-check-input producto-check" type="checkbox" 
                                                                   value="<?php echo $producto['id']; ?>" 
                                                                   data-existencia="<?php echo $producto['existencia']; ?>"
                                                                   id="prod_<?php echo $producto['id']; ?>">
                                                            <label class="form-check-label" for="prod_<?php echo $producto['id']; ?>">
                                                                <?php echo htmlspecialchars($producto['descripcion']); ?> 
                                                                <span class="badge bg-info"><?php echo $producto['existencia']; ?> unidades</span>
                                                            </label>
                                                            <input type="number" class="form-control form-control-sm mt-1 cantidad-producto" 
                                                                   min="1" max="<?php echo $producto['existencia']; ?>" 
                                                                   placeholder="Cantidad" style="display:none;" 
                                                                   data-producto-id="<?php echo $producto['id']; ?>">
                                                        </div>
                                            <?php endforeach; 
                                            if ($current_proveedor != '') echo '</div></div>';
                                            ?>
                                        </div>
                                        <div id="resumen-parcial" class="mt-3" style="display: none;">
                                            <div class="alert alert-success">
                                                <strong>📊 Resumen:</strong> <span id="productos-seleccionados-count">0</span> productos, 
                                                <span id="unidades-seleccionadas-count">0</span> unidades totales
                                            </div>
                                        </div>
                                    </div>

                                    <div class="alert alert-success mt-3">
                                        <h6><i class="bi bi-gear-fill"></i> Algoritmo V5.0 - Distribución Mejorada:</h6>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <ul class="mb-0 small">
                                                    <li><strong>✅ Distribución agresiva:</strong> Vacía TODO el inventario</li>
                                                    <li><strong>✅ Rotación en todos los días:</strong> No solo el último</li>
                                                    <li><strong>✅ Cantidades grandes:</strong> 10-30 unidades por producto</li>
                                                </ul>
                                            </div>
                                            <div class="col-md-6">
                                                <ul class="mb-0 small">
                                                    <li><strong>✅ Sin repeticiones:</strong> Productos únicos por tabla</li>
                                                    <li><strong>✅ Límite respetado:</strong> Máximo 50 tablas/día</li>
                                                    <li><strong>✅ Remanentes mínimos:</strong> Distribuye hasta agotar</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <input type="hidden" name="dias_exclusion" id="diasExclusionInput">
                                    <input type="hidden" name="productos_seleccionados" id="productosSeleccionadosInput">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                    <button type="submit" class="btn btn-primary">Crear Distribución</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Manejar tipo de distribución
        document.getElementById('tipoDistribucion').addEventListener('change', function() {
            const seleccion = document.getElementById('seleccionProductos');
            seleccion.style.display = this.value === 'parcial' ? 'block' : 'none';
        });

        // Manejar checkboxes de productos
        document.querySelectorAll('.producto-check').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const cantidadInput = this.parentElement.querySelector('.cantidad-producto');
                cantidadInput.style.display = this.checked ? 'block' : 'none';
                if (this.checked) {
                    cantidadInput.value = this.dataset.existencia;
                }
                actualizarResumen();
            });
        });

        // Actualizar contador cuando cambien las cantidades
        document.querySelectorAll('.cantidad-producto').forEach(input => {
            input.addEventListener('input', actualizarResumen);
        });

        function actualizarResumen() {
            let productosCount = 0;
            let unidadesCount = 0;
            
            document.querySelectorAll('.producto-check:checked').forEach(checkbox => {
                productosCount++;
                const cantidadInput = checkbox.parentElement.querySelector('.cantidad-producto');
                unidadesCount += parseInt(cantidadInput.value) || 0;
            });
            
            document.getElementById('productos-seleccionados-count').textContent = productosCount;
            document.getElementById('unidades-seleccionadas-count').textContent = unidadesCount;
            document.getElementById('resumen-parcial').style.display = productosCount > 0 ? 'block' : 'none';
        }

        // Enviar formulario
        document.getElementById('formNuevaDistribucion').addEventListener('submit', function(e) {
            // Días de exclusión
            const diasExcluir = [];
            document.querySelectorAll('input[name="dias_excluir[]"]:checked').forEach(cb => {
                diasExcluir.push(parseInt(cb.value));
            });
            document.getElementById('diasExclusionInput').value = JSON.stringify(diasExcluir);

            // Productos seleccionados (solo si es parcial)
            const tipoDistribucion = document.getElementById('tipoDistribucion').value;
            if (tipoDistribucion === 'parcial') {
                const productosSeleccionados = [];
                document.querySelectorAll('.producto-check:checked').forEach(checkbox => {
                    const productoId = checkbox.value;
                    const cantidadInput = checkbox.parentElement.querySelector('.cantidad-producto');
                    const cantidad = parseInt(cantidadInput.value) || 0;
                    
                    if (cantidad > 0) {
                        productosSeleccionados.push({
                            producto_id: parseInt(productoId),
                            cantidad: cantidad
                        });
                    }
                });
                
                if (productosSeleccionados.length === 0) {
                    e.preventDefault();
                    alert('Debe seleccionar al menos un producto con cantidad mayor a 0');
                    return false;
                }
                
                document.getElementById('productosSeleccionadosInput').value = JSON.stringify(productosSeleccionados);
            } else {
                document.getElementById('productosSeleccionadosInput').value = '[]';
            }
        });
    </script>
</body>
</html>