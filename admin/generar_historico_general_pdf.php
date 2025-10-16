<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/funciones.php';
require_once '../libs/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'admin') {
    header("Location: " . BASE_URL . "/admin/login.php");
    exit();
}

$fecha_inicio = $_GET['fecha_inicio'] ?? null;
$fecha_fin = $_GET['fecha_fin'] ?? null;

if (!$fecha_inicio || !$fecha_fin) {
    die("Por favor, especifique un rango de fechas.");
}

$fecha_fin_sql = $fecha_fin . ' 23:59:59';

$sql = "
    SELECT
        t.*,
        u_creador.nombre_completo as creador,
        GROUP_CONCAT(DISTINCT u_asignado.nombre_completo SEPARATOR ', ') as miembros_asignados
    FROM
        tareas t
    JOIN
        usuarios u_creador ON t.id_admin_creador = u_creador.id_usuario
    LEFT JOIN
        tareas_asignadas ta ON t.id_tarea = ta.id_tarea
    LEFT JOIN
        usuarios u_asignado ON ta.id_usuario = u_asignado.id_usuario
    WHERE
        t.fecha_creacion BETWEEN ? AND ?
    GROUP BY
        t.id_tarea
    ORDER BY
        t.fecha_creacion ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$fecha_inicio, $fecha_fin_sql]);
$tareas = $stmt->fetchAll();

// --- Lógica para calcular resúmenes ---
$resumen_creador = [];
$resumen_miembro = [];
$resumen_negocio = [];

function inicializar_resumen() {
    return ['total_tareas' => 0, 'total_piezas' => 0, 'Digital' => 0, 'Impreso' => 0];
}

foreach ($tareas as $tarea) {
    $piezas = (int)$tarea['numero_piezas'];
    $tipo_trabajo = $tarea['tipo_trabajo'];

    // Resumen por creador
    $creador = $tarea['creador'];
    if (!isset($resumen_creador[$creador])) {
        $resumen_creador[$creador] = inicializar_resumen();
    }
    $resumen_creador[$creador]['total_tareas']++;
    $resumen_creador[$creador]['total_piezas'] += $piezas;
    if (in_array($tipo_trabajo, ['Digital', 'Impreso'])) {
        $resumen_creador[$creador][$tipo_trabajo] += $piezas;
    }

    // Resumen por negocio
    if (!empty($tarea['negocio'])) {
        $negocio = $tarea['negocio'];
        if (!isset($resumen_negocio[$negocio])) {
            $resumen_negocio[$negocio] = inicializar_resumen();
        }
        $resumen_negocio[$negocio]['total_tareas']++;
        $resumen_negocio[$negocio]['total_piezas'] += $piezas;
        if (in_array($tipo_trabajo, ['Digital', 'Impreso'])) {
            $resumen_negocio[$negocio][$tipo_trabajo] += $piezas;
        }
    }

    // Resumen por miembro asignado
    if (!empty($tarea['miembros_asignados'])) {
        $miembros = explode(', ', $tarea['miembros_asignados']);
        foreach ($miembros as $miembro) {
            $miembro = trim($miembro);
            if (!empty($miembro)) {
                if (!isset($resumen_miembro[$miembro])) {
                    $resumen_miembro[$miembro] = inicializar_resumen();
                }
                $resumen_miembro[$miembro]['total_tareas']++;
                $resumen_miembro[$miembro]['total_piezas'] += $piezas;
                if (in_array($tipo_trabajo, ['Digital', 'Impreso'])) {
                    $resumen_miembro[$miembro][$tipo_trabajo] += $piezas;
                }
            }
        }
    }
}
// --- Fin de la lógica de resúmenes ---

$html = '
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Histórico General de Tareas</title>
    <style>
        body { font-family: sans-serif; margin: 20px; }
        h1, h2 { text-align: center; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 10px; }
        th, td { border: 1px solid #ddd; padding: 6px; text-align: left; }
        th { background-color: #f2f2f2; }
        .icon-prioridad-alta { color: red; }
        .icon-prioridad-media { color: orange; }
        .icon-prioridad-baja { color: green; }
    </style>
</head>
<body>
    <h1>Histórico General de Tareas</h1>
    <h2>Periodo: ' . htmlspecialchars(date('d/m/Y', strtotime($fecha_inicio))) . ' - ' . htmlspecialchars(date('d/m/Y', strtotime($fecha_fin))) . '</h2>
    <table>
        <thead>
            <tr>
                <th>Nombre Tarea</th>
                <th>Creador</th>
                <th>Miembro Asignado</th>
                <th>Negocio</th>
                <th>Fecha Creación</th>
                <th>Fecha Vencimiento</th>
                <th>Estado</th>
                <th>Prioridad</th>
            </tr>
        </thead>
        <tbody>';

if (empty($tareas)) {
    $html .= '<tr><td colspan="8" style="text-align:center;">No se encontraron tareas en el período seleccionado.</td></tr>';
} else {
    foreach ($tareas as $tarea) {
        $html .= '
            <tr>
                <td>' . e($tarea['nombre_tarea']) . '</td>
                <td>' . e($tarea['creador']) . '</td>
                <td>' . e($tarea['miembros_asignados'] ?? 'N/A') . '</td>
                <td>' . e($tarea['negocio'] ?? 'N/A') . '</td>
                <td>' . date('d/m/Y', strtotime($tarea['fecha_creacion'])) . '</td>
                <td>' . date('d/m/Y', strtotime($tarea['fecha_vencimiento'])) . '</td>
                <td>' . e(ucfirst($tarea['estado'])) . '</td>
                <td class="icon-prioridad-' . e($tarea['prioridad']) . '">' . e(ucfirst($tarea['prioridad'])) . '</td>
            </tr>';
    }
}

$html .= '
        </tbody>
    </table>
    <div class="summary-container" style="margin-top: 30px; page-break-inside: avoid;">
        <h2>Resúmenes del Periodo</h2>
        <table style="width: 100%; margin-bottom: 20px;">
            <thead><tr><th>Resumen por Creador</th><th>Tareas Creadas</th><th>Nº Piezas</th><th>Digital</th><th>Impreso</th></tr></thead>
            <tbody>';
foreach ($resumen_creador as $nombre => $data) {
    $html .= '<tr><td>' . e($nombre) . '</td><td>' . $data['total_tareas'] . '</td><td>' . $data['total_piezas'] . '</td><td>' . $data['Digital'] . '</td><td>' . $data['Impreso'] . '</td></tr>';
}
$html .= '
            </tbody>
        </table>
        <table style="width: 100%; margin-bottom: 20px;">
            <thead><tr><th>Resumen por Miembro Asignado</th><th>Tareas Asignadas</th><th>Nº Piezas</th><th>Digital</th><th>Impreso</th></tr></thead>
            <tbody>';
foreach ($resumen_miembro as $nombre => $data) {
    $html .= '<tr><td>' . e($nombre) . '</td><td>' . $data['total_tareas'] . '</td><td>' . $data['total_piezas'] . '</td><td>' . $data['Digital'] . '</td><td>' . $data['Impreso'] . '</td></tr>';
}
$html .= '
            </tbody>
        </table>
        <table style="width: 100%;">
             <thead><tr><th>Resumen por Negocio</th><th>Tareas</th><th>Nº Piezas</th><th>Digital</th><th>Impreso</th></tr></thead>
             <tbody>';
foreach ($resumen_negocio as $nombre => $data) {
    $html .= '<tr><td>' . e($nombre) . '</td><td>' . $data['total_tareas'] . '</td><td>' . $data['total_piezas'] . '</td><td>' . $data['Digital'] . '</td><td>' . $data['Impreso'] . '</td></tr>';
}
$html .= '
            </tbody>
        </table>
    </div>
</body>
</html>';

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream("historico_general_tareas_" . date("Y-m-d") . ".pdf", ["Attachment" => 1]);
