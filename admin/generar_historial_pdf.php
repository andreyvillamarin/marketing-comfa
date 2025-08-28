<?php
// Incluir archivos base
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/funciones.php';

// Cargar la librería Dompdf
require_once '../libs/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Comprobar sesión y permisos
if (!isset($_SESSION['user_id'])) {
    die("Acceso denegado. Debes iniciar sesión.");
}

// Validar el ID de la tarea
if (!isset($_GET['id_tarea']) || !filter_var($_GET['id_tarea'], FILTER_VALIDATE_INT)) {
    die("ID de tarea no válido.");
}
$id_tarea = $_GET['id_tarea'];
$id_usuario_actual = $_SESSION['user_id'];
$rol_usuario_actual = $_SESSION['user_rol'];

// --- Verificación de Seguridad ---
// Un miembro solo puede descargar el historial de una tarea a la que está asignado.
if ($rol_usuario_actual === 'miembro') {
    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM tareas_asignadas WHERE id_tarea = ? AND id_usuario = ?");
    $stmt_check->execute([$id_tarea, $id_usuario_actual]);
    if ($stmt_check->fetchColumn() == 0) {
        die("Permiso denegado. No estás asignado a esta tarea.");
    }
}

// --- Obtener todos los datos necesarios para el informe ---
try {
    // Detalles de la tarea, incluyendo el nombre del creador
    $stmt_tarea = $pdo->prepare("
        SELECT t.*, u.nombre_completo as nombre_creador 
        FROM tareas t 
        JOIN usuarios u ON t.id_admin_creador = u.id_usuario 
        WHERE t.id_tarea = ?
    ");
    $stmt_tarea->execute([$id_tarea]);
    $tarea = $stmt_tarea->fetch();
    if (!$tarea) die("La tarea no existe.");

    // Miembros asignados
    $stmt_asignados = $pdo->prepare("SELECT u.nombre_completo FROM usuarios u JOIN tareas_asignadas ta ON u.id_usuario = ta.id_usuario WHERE ta.id_tarea = ?");
    $stmt_asignados->execute([$id_tarea]);
    $miembros_asignados = $stmt_asignados->fetchAll(PDO::FETCH_COLUMN);

    // Comentarios
    $stmt_comentarios = $pdo->prepare("SELECT c.comentario, c.fecha_comentario, u.nombre_completo, u.rol FROM comentarios_tarea c JOIN usuarios u ON c.id_usuario = u.id_usuario WHERE c.id_tarea = ? ORDER BY c.fecha_comentario ASC");
    $stmt_comentarios->execute([$id_tarea]);
    $comentarios = $stmt_comentarios->fetchAll();

} catch (PDOException $e) {
    die("Error al obtener los datos para el informe: " . $e->getMessage());
}

// --- Construir el HTML para el PDF ---
$html = "
<!DOCTYPE html>
<html lang='es'>
<head>
    <meta charset='UTF-8'>
    <title>Historial de Tarea</title>
    <style>
        body { font-family: 'Helvetica', 'Arial', sans-serif; color: #333; font-size: 12px; }
        .header h1 { color: #0056b3; margin: 0; }
        .header p { margin: 5px 0; }
        .details-box, .section { border: 1px solid #ddd; padding: 15px; margin-top: 20px; border-radius: 5px; }
        h2 { border-bottom: 2px solid #eee; padding-bottom: 10px; margin-top: 0; color: #0056b3;}
        .details-box p { margin: 5px 0; }
        .details-box strong { display: inline-block; width: 150px; }
        .comment { border: 1px solid #f0f0f0; border-radius: 5px; padding: 10px; margin-bottom: 10px; }
        .comment-admin { background-color: #e9ecef; }
        .comment-miembro { background-color: #d1e7fd; }
        .comment-header { font-size: 0.9em; color: #555; margin-bottom: 5px; }
        .comment-body { white-space: pre-wrap; word-wrap: break-word; }
        .footer { position: fixed; bottom: -20px; left: 0px; right: 0px; height: 50px; text-align: center; font-size: 0.8em; color: #777; }
    </style>
</head>
<body>
    <div class='header'><h1>Historial de Tarea</h1></div>

    <div class='details-box'>
        <h2>Detalles de la Tarea</h2>
        <p><strong>ID de Tarea:</strong> T-" . e($tarea['id_tarea']) . "</p>
        <p><strong>Nombre:</strong> " . e($tarea['nombre_tarea']) . "</p>
        <p><strong>Creado por:</strong> " . e($tarea['nombre_creador']) . "</p>
        <p><strong>Fecha de Creación:</strong> " . date('d/m/Y H:i', strtotime($tarea['fecha_creacion'])) . "</p>
        <p><strong>Negocio:</strong> " . e($tarea['negocio'] ?? 'No especificado') . "</p>
        <p><strong>Número de Piezas:</strong> " . e($tarea['numero_piezas'] ?? 'No especificado') . "</p>
        <p><strong>Descripción:</strong></p>
        <div style='padding: 10px; border: 1px solid #eee; border-radius: 5px; background: #f9f9f9;'>" . $tarea['descripcion'] . "</div>
        <p><strong>Fecha de Vencimiento:</strong> " . date('d/m/Y H:i', strtotime($tarea['fecha_vencimiento'])) . "</p>
        <p><strong>Prioridad:</strong> " . ucfirst(e($tarea['prioridad'])) . "</p>
        <p><strong>Estado Actual:</strong> " . ucfirst(str_replace('_', ' ', e($tarea['estado']))) . "</p>
        <p><strong>Miembros Asignados:</strong> " . (!empty($miembros_asignados) ? implode(', ', $miembros_asignados) : 'Ninguno') . "</p>
    </div>

    <div class='section'>
        <h2>Historial de Comentarios</h2>";
        if(empty($comentarios)) {
            $html .= "<p>No hay comentarios en esta tarea.</p>";
        } else {
            foreach($comentarios as $c) {
                $clase_css = $c['rol'] === 'admin' ? 'comment-admin' : 'comment-miembro';
                $html .= "<div class='comment " . $clase_css . "'>
                            <div class='comment-header'>
                                <strong>" . e($c['nombre_completo']) . "</strong> (" . e($c['rol']) . ") - 
                                <span style='color:#777'>" . date('d/m/Y H:i', strtotime($c['fecha_comentario'])) . "</span>
                            </div>
                            <div class='comment-body'>" . nl2br(e($c['comentario'])) . "</div>
                          </div>";
            }
        }
$html .= "</div>
    <div class='footer'>Generado el " . date('d/m/Y H:i:s') . "</div>
</body>
</html>
";

// --- Instanciar Dompdf y generar el PDF ---
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream("Historial-Tarea-" . $id_tarea . ".pdf", ["Attachment" => true]);
?>