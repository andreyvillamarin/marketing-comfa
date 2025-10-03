<?php
// Este script se debe ejecutar una vez al día mediante un Cron Job.

// Establecer la ruta base del proyecto
// Esto es importante porque el script se ejecuta desde la línea de comandos del servidor
$base_path = dirname(__DIR__);

require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/funciones.php';

echo "Iniciando script de recordatorios...\n";

try {
    // Seleccionamos todas las asignaciones que tienen un recordatorio configurado
    // y cuya tarea aún no está cerrada.
    $stmt = $pdo->query("
        SELECT 
            ta.id_usuario, 
            ta.notificacion_dias_antes,
            t.id_tarea,
            t.nombre_tarea,
            t.fecha_vencimiento,
            u.email,
            u.nombre_completo
        FROM tareas_asignadas ta
        JOIN tareas t ON ta.id_tarea = t.id_tarea
        JOIN usuarios u ON ta.id_usuario = u.id_usuario
        WHERE ta.notificacion_dias_antes IS NOT NULL
        AND t.estado != 'cerrada'
        AND u.recibe_notificaciones = 1
    ");

    $asignaciones = $stmt->fetchAll();
    $fecha_actual = new DateTime();
    $fecha_actual->setTime(0, 0, 0); // Ignoramos la hora para comparar solo fechas

    echo "Se encontraron " . count($asignaciones) . " asignaciones con recordatorios configurados.\n";

    foreach ($asignaciones as $asignacion) {
        $fecha_vencimiento = new DateTime($asignacion['fecha_vencimiento']);
        $fecha_vencimiento->setTime(0, 0, 0);

        // Clonamos la fecha de vencimiento para no modificarla y restamos los días
        $fecha_recordatorio = clone $fecha_vencimiento;
        $fecha_recordatorio->modify('-' . $asignacion['notificacion_dias_antes'] . ' days');

        echo "Procesando tarea #" . $asignacion['id_tarea'] . " para " . $asignacion['nombre_completo'] . ". Fecha recordatorio: " . $fecha_recordatorio->format('Y-m-d') . "...\n";

        // Si la fecha calculada para el recordatorio es hoy, enviamos el email
        if ($fecha_recordatorio == $fecha_actual) {
            echo "¡ENVIANDO CORREO A " . $asignacion['email'] . "!\n";
            
            $asunto = "Recordatorio: La tarea '" . $asignacion['nombre_tarea'] . "' vence pronto";
            $cuerpo_html = "
                <h1>Recordatorio de Tarea</h1>
                <p>Hola " . e($asignacion['nombre_completo']) . ",</p>
                <p>Este es un recordatorio automático de que la siguiente tarea está próxima a su fecha de vencimiento:</p>
                <hr>
                <p><strong>Tarea:</strong> " . e($asignacion['nombre_tarea']) . "</p>
                <p><strong>Fecha de Vencimiento:</strong> " . date('d/m/Y H:i', strtotime($asignacion['fecha_vencimiento'])) . "</p>
                <hr>
                <p>Por favor, asegúrate de completarla a tiempo. Puedes ver los detalles en la plataforma:</p>
                <p><a href='" . BASE_URL . "/miembro/tarea.php?id=" . $asignacion['id_tarea'] . "' style='display:inline-block; padding:10px 15px; background-color:#007bff; color:white; text-decoration:none; border-radius:5px;'>Ver Tarea</a></p>
            ";

            enviar_email($asignacion['email'], $asignacion['nombre_completo'], $asunto, $cuerpo_html);
        }
    }

    echo "Script finalizado.\n";

} catch (Exception $e) {
    // Guardar el error en el log de errores de PHP
    error_log("Error en script de recordatorios: " . $e->getMessage());
}
?>