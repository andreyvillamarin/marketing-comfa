<?php
// Incluir archivos base
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/funciones.php';

// Cargar la librería Dompdf
require_once '../libs/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Comprobar sesión de admin
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'admin') {
    die("Acceso denegado.");
}

// Obtener y validar los parámetros del filtro
$id_miembro = $_POST['id_miembro'] ?? $_GET['id_miembro'] ?? null;
$fecha_inicio = $_POST['fecha_inicio'] ?? $_GET['fecha_inicio'] ?? null;
$fecha_fin = $_POST['fecha_fin'] ?? $_GET['fecha_fin'] ?? null;
$pieChartImage = $_POST['pieChartImage'] ?? null;
$barChartImage = $_POST['barChartImage'] ?? null;


if (!$id_miembro || !$fecha_inicio || !$fecha_fin) {
    die("Faltan parámetros para generar el informe.");
}

// Obtener los datos necesarios de la base de datos
try {
    // Info del miembro
    $stmt_miembro = $pdo->prepare("SELECT nombre_completo FROM usuarios WHERE id_usuario = ?");
    $stmt_miembro->execute([$id_miembro]);
    $nombre_miembro = $stmt_miembro->fetchColumn();

    // Listas de tareas (completadas, pendientes, vencidas)
    $listados = [];
    $fecha_fin_sql = $fecha_fin . ' 23:59:59';
    
    $stmt_completadas = $pdo->prepare("SELECT nombre_tarea, fecha_vencimiento FROM tareas t JOIN tareas_asignadas ta ON t.id_tarea = ta.id_tarea WHERE ta.id_usuario = ? AND t.estado = 'completada' AND t.fecha_vencimiento BETWEEN ? AND ? ORDER BY fecha_vencimiento DESC");
    $stmt_completadas->execute([$id_miembro, $fecha_inicio, $fecha_fin_sql]);
    $listados['completadas'] = $stmt_completadas->fetchAll();

    $stmt_pendientes = $pdo->prepare("SELECT nombre_tarea, fecha_vencimiento FROM tareas t JOIN tareas_asignadas ta ON t.id_tarea = ta.id_tarea WHERE ta.id_usuario = ? AND t.estado = 'pendiente' AND t.fecha_creacion BETWEEN ? AND ? ORDER BY fecha_vencimiento ASC");
    $stmt_pendientes->execute([$id_miembro, $fecha_inicio, $fecha_fin_sql]);
    $listados['pendientes'] = $stmt_pendientes->fetchAll();

    $stmt_vencidas = $pdo->prepare("SELECT nombre_tarea, fecha_vencimiento FROM tareas t JOIN tareas_asignadas ta ON t.id_tarea = ta.id_tarea WHERE ta.id_usuario = ? AND t.estado != 'cerrada' AND t.fecha_vencimiento < NOW()");
    $stmt_vencidas->execute([$id_miembro]);
    $listados['vencidas'] = $stmt_vencidas->fetchAll();

} catch (PDOException $e) {
    die("Error al obtener los datos para el informe: " . $e->getMessage());
}

// --- Construir el HTML para el PDF ---
$html = "
<!DOCTYPE html>
<html lang='es'>
<head>
    <meta charset='UTF-8'>
    <title>Informe de Rendimiento</title>
    <style>
        body { font-family: 'Helvetica', 'Arial', sans-serif; color: #333; }
        h1, h2, h3 { color: #0056b3; }
        .header { text-align: center; border-bottom: 2px solid #0056b3; padding-bottom: 10px; }
        .header h1 { margin: 0; }
        .header p { margin: 5px 0; }
        .section { margin-top: 25px; }
        .section h2 { background-color: #f2f2f2; padding: 10px; border-radius: 5px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #e9ecef; }
        .footer { position: fixed; bottom: 0; width: 100%; text-align: center; font-size: 0.8em; color: #777; }
    </style>
</head>
<body>
    <div class='header'>
        <h1>Informe de Rendimiento</h1>
        <p><strong>Miembro del Equipo:</strong> " . e($nombre_miembro) . "</p>
        <p><strong>Periodo Analizado:</strong> " . date('d/m/Y', strtotime($fecha_inicio)) . " al " . date('d/m/Y', strtotime($fecha_fin)) . "</p>
    </div>

    <div class='section'>
        <h2>Resumen Gráfico</h2>
        <table style='width: 100%; border: 0;'>
            <tr>
                <td style='width: 50%; text-align: center; border: 0;'>
                    <h3>Distribución por Estado</h3>
                    " . ($pieChartImage ? "<img src='" . $pieChartImage . "' style='max-width: 100%; height: auto;'>" : "Gráfico no disponible") . "
                </td>
                <td style='width: 50%; text-align: center; border: 0;'>
                    <h3>Tareas Gestionadas por Mes</h3>
                    " . ($barChartImage ? "<img src='" . $barChartImage . "' style='max-width: 100%; height: auto;'>" : "Gráfico no disponible") . "
                </td>
            </tr>
        </table>
    </div>

    <div class='section'>
        <h2>Tareas Completadas</h2>
        <table><thead><tr><th>Tarea</th><th>Fecha de Cierre</th></tr></thead><tbody>";
        if(empty($listados['completadas'])) $html .= "<tr><td colspan='2'>Ninguna</td></tr>";
        foreach($listados['completadas'] as $t) $html .= "<tr><td>" . e($t['nombre_tarea']) . "</td><td>" . date('d/m/Y', strtotime($t['fecha_vencimiento'])) . "</td></tr>";
$html .= "</tbody></table>
    </div>

    <div class='section'>
        <h2>Tareas Pendientes</h2>
        <table><thead><tr><th>Tarea</th><th>Fecha de Vencimiento</th></tr></thead><tbody>";
        if(empty($listados['pendientes'])) $html .= "<tr><td colspan='2'>Ninguna</td></tr>";
        foreach($listados['pendientes'] as $t) $html .= "<tr><td>" . e($t['nombre_tarea']) . "</td><td>" . date('d/m/Y', strtotime($t['fecha_vencimiento'])) . "</td></tr>";
$html .= "</tbody></table>
    </div>
    
    <div class='section'>
        <h2>Tareas Vencidas</h2>
        <table><thead><tr><th>Tarea</th><th>Fecha de Vencimiento</th></tr></thead><tbody>";
        if(empty($listados['vencidas'])) $html .= "<tr><td colspan='2'>Ninguna</td></tr>";
        foreach($listados['vencidas'] as $t) $html .= "<tr><td>" . e($t['nombre_tarea']) . "</td><td>" . date('d/m/Y', strtotime($t['fecha_vencimiento'])) . "</td></tr>";
$html .= "</tbody></table>
    </div>

    <div class='footer'>
        Generado el " . date('d/m/Y H:i:s') . "
    </div>
</body>
</html>
";

// --- Instanciar Dompdf y generar el PDF ---
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$dompdf = new Dompdf($options);

$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait'); // Tamaño del papel: A4, Orientación: vertical
$dompdf->render();

// Forzar la descarga del archivo PDF
$dompdf->stream("Informe-" . str_replace(' ', '_', $nombre_miembro) . ".pdf", ["Attachment" => true]);
?>