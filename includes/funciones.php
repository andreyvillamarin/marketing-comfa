<?php
// Usar las clases del namespace de PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// --- INICIO DE LA MODIFICACIÓN ---
// En lugar de cargar el autoload de Composer, cargamos los archivos manualmente.
require_once __DIR__ . '/../libs/phpmailer/PHPMailer.php';
require_once __DIR__ . '/../libs/phpmailer/SMTP.php';
require_once __DIR__ . '/../libs/phpmailer/Exception.php';
// --- FIN DE LA MODIFICACIÓN ---


function enviar_email($destinatario_email, $destinatario_nombre, $asunto, $cuerpo_html) {
    // La configuración se obtiene de config.php, que debe estar incluido antes de llamar a esta función
    $mail = new PHPMailer(true);
    try {
        // Configuración del servidor (esta parte no cambia)
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // Equivalente a 'tls'
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->Encoding   = 'base64';

        // Remitente y Destinatarios (esta parte no cambia)
        $mail->setFrom(EMAIL_FROM, EMAIL_FROM_NAME);
        $mail->addAddress($destinatario_email, $destinatario_nombre);

        // Contenido (esta parte no cambia)
        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body    = $cuerpo_html;
        $mail->AltBody = strip_tags($cuerpo_html);

        $mail->send();
        return true;
    } catch (Exception $e) {
        // En producción, es mejor registrar este error que mostrarlo
        error_log("Error al enviar email a {$destinatario_email}: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Función para proteger la salida en HTML y prevenir ataques XSS.
 * @param string $string La cadena a sanear.
 * @return string La cadena saneada.
 */
function e($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

function mostrar_estado_tarea($tarea) {
    $estado_clase = e($tarea['estado']);
    $estado_texto = ucfirst(str_replace('_', ' ', $estado_clase));
    $estado_icono = 'fa-clock';
    $color = '';

    if ($estado_clase == 'finalizada_usuario') {
        $estado_icono = 'fa-check';
    } elseif ($estado_clase == 'cerrada') {
        $estado_icono = 'fa-check-double';
    }

    if ($tarea['estado'] === 'pendiente') {
        if (strtotime($tarea['fecha_vencimiento']) < time()) {
            $estado_texto = 'Vencida';
            $color = 'style="color: red;"';
        } else {
            $estado_texto = 'Pendiente';
            $color = 'style="color: orange;"';
        }
    }

    return "<span class='icon-text icon-estado-{$estado_clase}' {$color}><i class='fas {$estado_icono}'></i> {$estado_texto}</span>";
}

function notificar_evento_tarea($id_tarea, $evento, $id_usuario_accion, $datos_adicionales = []) {
    global $pdo;

    // Obtener información de la tarea
    $stmt_tarea = $pdo->prepare("SELECT * FROM tareas WHERE id_tarea = ?");
    $stmt_tarea->execute([$id_tarea]);
    $tarea = $stmt_tarea->fetch();
    if (!$tarea) return;

    // Obtener información del usuario que realizó la acción
    $stmt_usuario_accion = $pdo->prepare("SELECT * FROM usuarios WHERE id_usuario = ?");
    $stmt_usuario_accion->execute([$id_usuario_accion]);
    $usuario_accion = $stmt_usuario_accion->fetch();
    if (!$usuario_accion) return;

    // Obtener todos los usuarios relacionados con la tarea
    $stmt_usuarios = $pdo->prepare("
        SELECT u.id_usuario, u.nombre_completo, u.email, u.rol,
               CASE WHEN t.id_admin_creador = u.id_usuario THEN 1 ELSE 0 END as es_creador,
               CASE WHEN ta.id_usuario IS NOT NULL THEN 1 ELSE 0 END as es_asignado
        FROM usuarios u
        LEFT JOIN tareas t ON u.id_usuario = t.id_admin_creador AND t.id_tarea = :id_tarea1
        LEFT JOIN tareas_asignadas ta ON u.id_usuario = ta.id_usuario AND ta.id_tarea = :id_tarea2
        WHERE t.id_tarea = :id_tarea3 OR ta.id_tarea = :id_tarea4
        GROUP BY u.id_usuario
    ");
    $stmt_usuarios->execute([':id_tarea1' => $id_tarea, ':id_tarea2' => $id_tarea, ':id_tarea3' => $id_tarea, ':id_tarea4' => $id_tarea]);
    $usuarios_relacionados = $stmt_usuarios->fetchAll(PDO::FETCH_ASSOC);

    // Obtener todos los administradores
    $stmt_admins = $pdo->query("SELECT id_usuario, nombre_completo, email, rol FROM usuarios WHERE rol = 'admin'");
    $administradores = $stmt_admins->fetchAll(PDO::FETCH_ASSOC);

    $notificaciones = [];

    // Lógica de notificación
    foreach ($usuarios_relacionados as $usuario) {
        if ($usuario['id_usuario'] == $id_usuario_accion) continue; // No notificar a quien hizo la acción

        $enviar = false;
        switch ($evento) {
            case 'tarea_creada':
                if ($usuario['rol'] == 'admin' && $usuario_accion['rol'] == 'analista') $enviar = true;
                if ($usuario['es_asignado']) $enviar = true;
                break;
            case 'tarea_editada':
                if ($usuario['rol'] == 'admin' && $usuario_accion['rol'] == 'analista') $enviar = true;
                if ($usuario['es_asignado']) $enviar = true;
                break;
            case 'nuevo_comentario':
                if ($usuario['rol'] == 'admin') $enviar = true;
                if ($usuario['rol'] == 'analista' && ($usuario_accion['rol'] == 'miembro' || $usuario_accion['rol'] == 'admin' || ($usuario_accion['rol'] == 'analista' && $usuario['id_usuario'] != $id_usuario_accion))) $enviar = true;
                if ($usuario['rol'] == 'miembro' && ($usuario_accion['rol'] == 'admin' || $usuario_accion['rol'] == 'analista')) $enviar = true;
                break;
            case 'miembro_finaliza':
                if ($usuario['rol'] == 'admin' || ($usuario['rol'] == 'analista' && $usuario['es_creador'])) $enviar = true;
                break;
            case 'admin_completa':
                 if ($usuario['es_asignado']) $enviar = true;
                break;
            case 'tarea_reabierta':
                if ($usuario['es_asignado']) $enviar = true;
                break;
            case 'tarea_devuelta_a_pendiente':
                if ($usuario['rol'] == 'admin') $enviar = true;
                break;
        }
        if ($enviar) {
            $notificaciones[$usuario['email']] = $usuario;
        }
    }
    
    // Lógica de notificación para administradores no directamente relacionados con la tarea
    if (in_array($evento, ['tarea_creada', 'tarea_editada', 'nuevo_comentario', 'miembro_finaliza'])) {
        foreach ($administradores as $admin) {
            if ($admin['id_usuario'] == $id_usuario_accion) continue;
            
            $enviar_admin = false;
            if ($usuario_accion['rol'] == 'analista' && ($evento == 'tarea_creada' || $evento == 'tarea_editada' || $evento == 'nuevo_comentario')) {
                $enviar_admin = true;
            }
            if ($usuario_accion['rol'] == 'miembro' && ($evento == 'nuevo_comentario' || $evento == 'miembro_finaliza')) {
                $enviar_admin = true;
            }

            if ($enviar_admin) {
                $notificaciones[$admin['email']] = $admin;
            }
        }
    }


    foreach ($notificaciones as $destinatario) {
        $asunto = '';
        $cuerpo = '';

        switch ($evento) {
            case 'tarea_creada':
                $asunto = "Nueva Tarea Creada: " . $tarea['nombre_tarea'];
                $cuerpo = "<p>Hola ".e($destinatario['nombre_completo']).",</p>";
                $cuerpo .= "<p>El usuario ".e($usuario_accion['nombre_completo'])." ha creado una nueva tarea: <strong>".e($tarea['nombre_tarea'])."</strong>.</p>";
                break;
            case 'tarea_editada':
                $asunto = "Tarea Actualizada: " . $tarea['nombre_tarea'];
                $cuerpo = "<p>Hola ".e($destinatario['nombre_completo']).",</p>";
                $cuerpo .= "<p>El usuario ".e($usuario_accion['nombre_completo'])." ha actualizado la tarea: <strong>".e($tarea['nombre_tarea'])."</strong>.</p>";
                if (!empty($datos_adicionales['detalles'])) {
                    $cuerpo .= "<p><strong>Detalles de la actualización:</strong></p>";
                    $cuerpo .= $datos_adicionales['detalles'];
                }
                break;
            case 'nuevo_comentario':
                $asunto = "Nuevo Comentario en: " . $tarea['nombre_tarea'];
                $cuerpo = "<p>Hola ".e($destinatario['nombre_completo']).",</p>";
                $cuerpo .= "<p>".e($usuario_accion['nombre_completo'])." ha comentado en la tarea: <strong>".e($tarea['nombre_tarea'])."</strong>.</p>";
                break;
            case 'miembro_finaliza':
                $asunto = "Tarea Finalizada por Miembro: " . $tarea['nombre_tarea'];
                $cuerpo = "<p>Hola ".e($destinatario['nombre_completo']).",</p>";
                $cuerpo .= "<p>El miembro ".e($usuario_accion['nombre_completo'])." ha marcado como finalizada la tarea: <strong>".e($tarea['nombre_tarea'])."</strong>.</p>";
                break;
            case 'admin_completa':
                $asunto = "Tarea Completada: " . $tarea['nombre_tarea'];
                $cuerpo = "<p>Hola ".e($destinatario['nombre_completo']).",</p>";
                $cuerpo .= "<p>Un administrador ha completado la tarea: <strong>".e($tarea['nombre_tarea'])."</strong>.</p>";
                break;
            case 'tarea_reabierta':
                $asunto = "Tarea Reabierta: " . $tarea['nombre_tarea'];
                $cuerpo = "<p>Hola ".e($destinatario['nombre_completo']).",</p>";
                $cuerpo .= "<p>Un administrador ha reabierto la tarea: <strong>".e($tarea['nombre_tarea'])."</strong>.</p>";
                break;
            case 'tarea_devuelta_a_pendiente':
                $asunto = "Tarea Devuelta a Pendiente: " . e($tarea['nombre_tarea']);
                $cuerpo = "<p>Hola ".e($destinatario['nombre_completo']).",</p>";
                $cuerpo .= "<p>El ".e($usuario_accion['rol'])." ".e($usuario_accion['nombre_completo'])." ha cambiado el estado de la tarea <strong>".e($tarea['nombre_tarea'])."</strong> de 'Finalizada por Usuario' a 'Pendiente'.</p>";
                break;
        }

        // --- INICIO: Añadir enlace a la tarea ---
        $task_url = BASE_URL;
        if ($destinatario['rol'] === 'miembro') {
            $task_url .= '/miembro/tarea.php?id=' . $id_tarea;
        } else { // admin o analista
            $task_url .= '/admin/editar_tarea.php?id=' . $id_tarea;
        }
        $cuerpo .= '<p style="margin-top: 20px;"><a href="' . $task_url . '" style="display: inline-block; padding: 12px 20px; background-color: #007bff; color: #ffffff; text-decoration: none; border-radius: 5px; font-weight: bold;">Ver Tarea</a></p>';
        // --- FIN: Añadir enlace a la tarea ---

        $cuerpo .= "<p>Si el botón no funciona, copia y pega esta URL en tu navegador: " . $task_url . "</p>";
        enviar_email($destinatario['email'], $destinatario['nombre_completo'], $asunto, $cuerpo);
    }
}