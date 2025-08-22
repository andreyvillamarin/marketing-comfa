<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/funciones.php';
require_once '../libs/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'miembro') {
    header("Location: login.php");
    exit();
}

$id_miembro_filtro = $_SESSION['user_id'];
$fecha_inicio = $_GET['fecha_inicio'] ?? null;
$fecha_fin = $_GET['fecha_fin'] ?? null;

if (!$fecha_inicio || !$fecha_fin) {
    die("Por favor, especifique un rango de fechas.");
}

$fecha_fin_sql = $fecha_fin . ' 23:59:59';

$query_tareas = "
    SELECT
        t.nombre_tarea,
        uc.nombre_completo AS creador,
        t.fecha_creacion,
        t.fecha_vencimiento,
        t.numero_piezas,
        t.negocio,
        (SELECT MAX(fecha_comentario) FROM comentarios_tarea WHERE id_tarea = t.id_tarea AND comentario LIKE 'He Finalizado esta Tarea') AS fecha_finalizada_usuario,
        (CASE
            WHEN t.estado = 'completada' AND (SELECT MAX(fecha_comentario) FROM comentarios_tarea WHERE id_tarea = t.id_tarea AND comentario LIKE 'He Finalizado esta Tarea') <= t.fecha_vencimiento THEN 'A tiempo'
            WHEN t.estado = 'completada' AND (SELECT MAX(fecha_comentario) FROM comentarios_tarea WHERE id_tarea = t.id_tarea AND comentario LIKE 'He Finalizado esta Tarea') > t.fecha_vencimiento THEN 'Con retraso'
            ELSE 'Pendiente'
        END) AS cumplimiento
    FROM tareas t
    JOIN usuarios uc ON t.id_admin_creador = uc.id_usuario
    JOIN tareas_asignadas ta ON t.id_tarea = ta.id_tarea
    WHERE t.fecha_creacion BETWEEN ? AND ? AND ta.id_usuario = ?
    ORDER BY t.fecha_creacion DESC
";

$stmt_tareas = $pdo->prepare($query_tareas);
$stmt_tareas->execute([$fecha_inicio, $fecha_fin_sql, $id_miembro_filtro]);
$tareas = $stmt_tareas->fetchAll();

$summary = [
    'completadas' => 0,
    'pendientes' => 0,
    'vencidas' => 0,
    'total_piezas' => 0,
    'por_negocio' => [],
];
foreach ($tareas as $tarea) {
    if ($tarea['cumplimiento'] === 'A tiempo' || $tarea['cumplimiento'] === 'Con retraso') {
        $summary['completadas']++;
    } else {
        $summary['pendientes']++;
        if (strtotime($tarea['fecha_vencimiento']) < time()) {
            $summary['vencidas']++;
        }
    }
    $summary['total_piezas'] += $tarea['numero_piezas'];
    if (!empty($tarea['negocio'])) {
        if (!isset($summary['por_negocio'][$tarea['negocio']])) {
            $summary['por_negocio'][$tarea['negocio']] = 0;
        }
        $summary['por_negocio'][$tarea['negocio']]++;
    }
}

$miembro_nombre = $_SESSION['user_nombre'];

$html = '
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Informe de Tareas</title>
    <style>
        body { font-family: sans-serif; margin: 20px; }
        h1, h2, h3 { text-align: center; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 12px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .summary { margin-top: 20px; text-align: right; font-size: 12px; }
    </style>
</head>
<body>
    <h1>Informe de Mis Tareas</h1>
    <h2>Periodo: ' . date('d/m/Y', strtotime($fecha_inicio)) . ' - ' . date('d/m/Y', strtotime($fecha_fin)) . '</h2>
    <h3>Miembro: ' . e($miembro_nombre) . '</h3>
    <table>
        <thead>
            <tr>
                <th>Creador</th>
                <th>Nombre Tarea</th>
                <th>No Piezas</th>
                <th>Negocio</th>
                <th>Fecha Creación</th>
                <th>Fecha Vencimiento</th>
                <th>Fecha Finalización</th>
                <th>Cumplimiento</th>
            </tr>
        </thead>
        <tbody>';
foreach ($tareas as $tarea) {
    $html .= '
            <tr>
                <td>' . e($tarea['creador']) . '</td>
                <td>' . e($tarea['nombre_tarea']) . '</td>
                <td>' . e($tarea['numero_piezas']) . '</td>
                <td>' . e($tarea['negocio']) . '</td>
                <td>' . date('d/m/Y', strtotime($tarea['fecha_creacion'])) . '</td>
                <td>' . date('d/m/Y', strtotime($tarea['fecha_vencimiento'])) . '</td>
                <td>' . ($tarea['fecha_finalizada_usuario'] ? date('d/m/Y', strtotime($tarea['fecha_finalizada_usuario'])) : 'N/A') . '</td>
                <td>' . e($tarea['cumplimiento']) . '</td>
            </tr>';
}
$html .= '
        </tbody>
    </table>
    <div class="summary">
        <strong>Total Tareas Completadas:</strong> ' . $summary['completadas'] . '<br>
        <strong>Total Tareas Pendientes:</strong> ' . $summary['pendientes'] . '<br>
        <strong>Total Tareas Vencidas:</strong> ' . $summary['vencidas'] . '<br>
        <hr>
        <strong>Total de Piezas:</strong> ' . $summary['total_piezas'] . '<br>
        <strong>Tareas por Negocio:</strong><br>';
if (empty($summary['por_negocio'])) {
    $html .= '<span>No hay datos de negocio.</span>';
} else {
    foreach ($summary['por_negocio'] as $negocio => $cantidad) {
        $html .= '- ' . e($negocio) . ': ' . $cantidad . '<br>';
    }
}
$html .= '
    </div>
</body>
</html>';

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream("informe_mis_tareas_" . date("Y-m-d") . ".pdf", ["Attachment" => 1]);
