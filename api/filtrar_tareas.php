<?php
// api/filtrar_tareas.php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/funciones.php';

// --- Seguridad: Asegurarse de que solo usuarios logueados y con rol permitido accedan ---
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_rol'], ['admin', 'analista'])) {
    http_response_code(403); // Forbidden
    echo '<tr><td colspan="9" style="text-align:center;">Acceso denegado.</td></tr>';
    exit();
}

$id_usuario_actual = $_SESSION['user_id'];
$rol_usuario_actual = $_SESSION['user_rol'];

// --- Recoger y sanear parámetros de GET ---
$q = trim($_GET['q'] ?? '');
$estado = trim($_GET['estado'] ?? '');
$fecha_creacion = trim($_GET['fecha_creacion'] ?? '');
$tipo_pagina = trim($_GET['tipo_pagina'] ?? 'activas'); // 'activas' o 'completadas'

// --- Construcción de la consulta SQL ---
$sql = "
    SELECT
        t.*,
        u_creador.nombre_completo as creador,
        GROUP_CONCAT(DISTINCT u_asignado.nombre_completo SEPARATOR ', ') as miembros_asignados,
        t.negocio
    FROM
        tareas t
    JOIN
        usuarios u_creador ON t.id_admin_creador = u_creador.id_usuario
    LEFT JOIN
        tareas_asignadas ta ON t.id_tarea = ta.id_tarea
    LEFT JOIN
        usuarios u_asignado ON ta.id_usuario = u_asignado.id_usuario
";
$params = [];
$where_clauses = [];

// --- Lógica de filtrado ---

// Filtro base según la página (activas o completadas)
if ($tipo_pagina === 'completadas') {
    $where_clauses[] = "t.estado = 'completada'";
} else {
    $where_clauses[] = "t.estado != 'completada'";
}

// Filtro para analistas (solo ven sus tareas creadas)
if ($rol_usuario_actual === 'analista') {
    $where_clauses[] = "t.id_admin_creador = ?";
    $params[] = $id_usuario_actual;
}

// Filtro por término de búsqueda
if (!empty($q)) {
    $search_term = '%' . $q . '%';
    $where_clauses[] = "(t.nombre_tarea LIKE ? OR u_creador.nombre_completo LIKE ? OR u_asignado.nombre_completo LIKE ? OR t.negocio LIKE ?)";
    array_push($params, $search_term, $search_term, $search_term, $search_term);
}

// Filtro por estado (para la página de tareas activas)
if ($tipo_pagina === 'activas' && !empty($estado)) {
    if ($estado == 'vencida') {
        $where_clauses[] = "(t.estado = 'pendiente' AND t.fecha_vencimiento < CURDATE())";
    } else {
        $where_clauses[] = "t.estado = ?";
        $params[] = $estado;
    }
}

// Filtro por fecha de creación
if (!empty($fecha_creacion)) {
    $where_clauses[] = "DATE(t.fecha_creacion) = ?";
    $params[] = $fecha_creacion;
}

// --- Ensamblar y ejecutar la consulta ---
if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(' AND ', $where_clauses);
}

$order_by = ($tipo_pagina === 'completadas') ? "t.fecha_vencimiento DESC" : "t.fecha_vencimiento ASC";
$sql .= " GROUP BY t.id_tarea ORDER BY " . $order_by;

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tareas = $stmt->fetchAll();
} catch (PDOException $e) {
    // En un API, es mejor devolver un error y loguearlo
    http_response_code(500); // Internal Server Error
    error_log("Error en API de filtro: " . $e->getMessage());
    echo '<tr><td colspan="9" style="text-align:center;">Error al cargar las tareas.</td></tr>';
    exit();
}

// --- Generar y devolver el HTML de las filas de la tabla ---
if (empty($tareas)) {
    $mensaje = ($tipo_pagina === 'completadas') ? 'No se encontraron tareas completadas.' : 'No se encontraron tareas activas.';
    echo '<tr><td colspan="9" style="text-align:center;">' . $mensaje . '</td></tr>';
} else {
    foreach ($tareas as $tarea) {
        // La salida debe ser exactamente el HTML de un <tr>
        echo '<tr>';
        
        if ($rol_usuario_actual === 'admin') {
            echo '<td><input type="checkbox" name="ids_tareas[]" value="' . $tarea['id_tarea'] . '"></td>';
        }
        
        echo '<td>' . e($tarea['nombre_tarea']) . '</td>';
        echo '<td>' . e($tarea['creador']) . '</td>';
        echo '<td>' . e($tarea['miembros_asignados'] ?? 'N/A') . '</td>';
        echo '<td>' . e($tarea['negocio'] ?? 'N/A') . '</td>';
        echo '<td>' . e($tarea['tipo_trabajo'] ?? 'N/A') . '</td>';
        echo '<td>' . date('d/m/Y H:i', strtotime($tarea['fecha_creacion'])) . '</td>';
        echo '<td>' . date('d/m/Y H:i', strtotime($tarea['fecha_vencimiento'])) . '</td>';
        echo '<td>' . mostrar_estado_tarea($tarea) . '</td>';
        
        $prioridad_clase = e($tarea['prioridad']);
        $prioridad_texto = ucfirst($prioridad_clase);
        $prioridad_icono = 'fa-circle-info';
        if ($prioridad_clase == 'alta') $prioridad_icono = 'fa-triangle-exclamation';
        if ($prioridad_clase == 'media') $prioridad_icono = 'fa-circle-exclamation';
        echo "<td><span class='icon-text icon-prioridad-{$prioridad_clase}'><i class='fas {$prioridad_icono}'></i> {$prioridad_texto}</span></td>";
        
        echo '<td class="actions"><a href="editar_tarea.php?id=' . $tarea['id_tarea'] . '" class="btn btn-warning"><i class="fas fa-pencil-alt"></i> Ver/Editar</a></td>';
        
        echo '</tr>';
    }
}
?>
