<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/funciones.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_rol'], ['admin', 'analista'])) { header("Location: login.php"); exit(); }
if (!isset($_GET['id']) || !filter_var($_GET['id'], FILTER_VALIDATE_INT)) { header("Location: tareas.php"); exit(); }
$id_tarea = $_GET['id'];

$mensaje = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['actualizar_tarea'])) {
        $nombre_tarea = trim($_POST['nombre_tarea']);
        $descripcion = trim($_POST['descripcion']);
        $fecha_vencimiento = $_POST['fecha_vencimiento'];
        $prioridad = $_POST['prioridad'];
        $miembros_asignados_nuevos = isset($_POST['miembros_asignados']) ? $_POST['miembros_asignados'] : [];
        $numero_piezas = isset($_POST['numero_piezas']) && $_POST['numero_piezas'] !== '' ? (int)$_POST['numero_piezas'] : 0;
        $tipo_trabajo = trim($_POST['tipo_trabajo']);
        $negocio = isset($_POST['negocio']) ? trim($_POST['negocio']) : '';
        if (empty($nombre_tarea) || empty($fecha_vencimiento) || empty($prioridad) || empty($tipo_trabajo)) {
            $error = 'El nombre, la fecha de vencimiento, la prioridad y el tipo de trabajo son obligatorios.';
        } else {
            // Obtener datos originales para comparación
            $stmt_tarea_original = $pdo->prepare("SELECT * FROM tareas WHERE id_tarea = ?");
            $stmt_tarea_original->execute([$id_tarea]);
            $tarea_original = $stmt_tarea_original->fetch(PDO::FETCH_ASSOC);

            $stmt_originales = $pdo->prepare("SELECT id_usuario FROM tareas_asignadas WHERE id_tarea = ?");
            $stmt_originales->execute([$id_tarea]);
            $ids_miembros_originales = $stmt_originales->fetchAll(PDO::FETCH_COLUMN);

            $actualizacion_exitosa = false;
            $cambios = [];
            try {
                $pdo->beginTransaction();
                $stmt_update = $pdo->prepare("UPDATE tareas SET nombre_tarea = ?, descripcion = ?, fecha_vencimiento = ?, prioridad = ?, numero_piezas = ?, tipo_trabajo = ?, negocio = ? WHERE id_tarea = ?");
                $stmt_update->execute([$nombre_tarea, $descripcion, $fecha_vencimiento, $prioridad, $numero_piezas, $tipo_trabajo, $negocio, $id_tarea]);
                
                if ($nombre_tarea !== $tarea_original['nombre_tarea']) { $cambios[] = "<li><b>Nombre:</b> de '".e($tarea_original['nombre_tarea'])."' a '".e($nombre_tarea)."'</li>"; }
                if ($descripcion !== $tarea_original['descripcion']) { $cambios[] = "<li><b>Descripción:</b> fue modificada.</li>"; }
                if (strtotime($fecha_vencimiento) != strtotime($tarea_original['fecha_vencimiento'])) { $cambios[] = "<li><b>Fecha Vencimiento:</b> de '".date('d/m/Y H:i', strtotime($tarea_original['fecha_vencimiento']))."' a '".date('d/m/Y H:i', strtotime($fecha_vencimiento))."'</li>"; }
                if ($prioridad !== $tarea_original['prioridad']) { $cambios[] = "<li><b>Prioridad:</b> de '".e($tarea_original['prioridad'])."' a '".e($prioridad)."'</li>"; }
                if ($numero_piezas != $tarea_original['numero_piezas']) { $cambios[] = "<li><b>Nº Piezas:</b> de '".e($tarea_original['numero_piezas'])."' a '".e($numero_piezas)."'</li>"; }
                if ($tipo_trabajo !== $tarea_original['tipo_trabajo']) { $cambios[] = "<li><b>Tipo de Trabajo:</b> de '".e($tarea_original['tipo_trabajo'])."' a '".e($tipo_trabajo)."'</li>"; }
                if ($negocio !== $tarea_original['negocio']) { $cambios[] = "<li><b>Negocio:</b> de '".e($tarea_original['negocio'])."' a '".e($negocio)."'</li>"; }

                $ids_miembros_originales_sorted = $ids_miembros_originales; sort($ids_miembros_originales_sorted);
                $miembros_asignados_nuevos_sorted = $miembros_asignados_nuevos; sort($miembros_asignados_nuevos_sorted);

                if ($ids_miembros_originales_sorted != $miembros_asignados_nuevos_sorted) {
                    $cambios[] = "<li><b>Miembros asignados</b> fueron modificados.</li>";
                }

                $stmt_delete_asignados = $pdo->prepare("DELETE FROM tareas_asignadas WHERE id_tarea = ?");
                $stmt_delete_asignados->execute([$id_tarea]);
                if (!empty($miembros_asignados_nuevos)) {
                    $stmt_insert_asignados = $pdo->prepare("INSERT INTO tareas_asignadas (id_tarea, id_usuario) VALUES (?, ?)");
                    foreach ($miembros_asignados_nuevos as $id_miembro) { $stmt_insert_asignados->execute([$id_tarea, $id_miembro]); }
                }

                if (!empty($_FILES['recursos']['name'][0])) {
                    $stmt_recurso = $pdo->prepare("INSERT INTO recursos_tarea (id_tarea, nombre_archivo, ruta_archivo) VALUES (?, ?, ?)");
                    $upload_dir = __DIR__ . '/../uploads/';
                    foreach ($_FILES['recursos']['name'] as $key => $name) {
                        $tmp_name = $_FILES['recursos']['tmp_name'][$key]; $file_name = time() . '_' . basename($name); $target_file = $upload_dir . $file_name;
                        if (move_uploaded_file($tmp_name, $target_file)) { $stmt_recurso->execute([$id_tarea, $name, 'uploads/' . $file_name]); }
                    }
                }
                
                $pdo->commit();
                $actualizacion_exitosa = true;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $error = "Error al actualizar la tarea: " . $e->getMessage();
            }

            if ($actualizacion_exitosa) {
                $detalles_cambios = !empty($cambios) ? "<ul>" . implode('', $cambios) . "</ul>" : '';
                if (notificar_evento_tarea($id_tarea, 'tarea_editada', $_SESSION['user_id'], ['detalles' => $detalles_cambios])) {
                    $mensaje = "¡Tarea actualizada exitosamente!";
                } else {
                    $error = "La tarea fue actualizada, pero hubo un problema al enviar las notificaciones.";
                }
            }
        }
    }
    if (isset($_POST['agregar_comentario'])) {
        $comentario = trim($_POST['comentario']);
        $nombres_archivos = [];
        $rutas_archivos = [];
        $errores_archivos = [];
        $max_size = 5 * 1024 * 1024; // 5MB

        if (isset($_FILES['archivos_comentario']) && !empty($_FILES['archivos_comentario']['name'][0])) {
            foreach ($_FILES['archivos_comentario']['name'] as $key => $nombre_original) {
                if ($_FILES['archivos_comentario']['error'][$key] == UPLOAD_ERR_OK) {
                    $nombre_original = basename($nombre_original);
                    $archivo_tmp = $_FILES['archivos_comentario']['tmp_name'][$key];
                    $tamano_archivo = $_FILES['archivos_comentario']['size'][$key];
                    $extension = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));
                    $permitidos = ['pdf', 'jpg', 'jpeg', 'png'];

                    if (!in_array($extension, $permitidos)) {
                        $errores_archivos[] = "Archivo '{$nombre_original}' no permitido. Solo se aceptan: PDF, JPG, JPEG, PNG.";
                        continue;
                    }

                    if ($tamano_archivo > $max_size) {
                        $errores_archivos[] = "El archivo '{$nombre_original}' excede el tamaño máximo de 5MB.";
                        continue;
                    }
                    
                    $nombre_archivo_nuevo = time() . '_' . $nombre_original;
                    $ruta_destino = __DIR__ . '/../uploads/' . $nombre_archivo_nuevo;

                    if (move_uploaded_file($archivo_tmp, $ruta_destino)) {
                        $nombres_archivos[] = $nombre_archivo_nuevo;
                        $rutas_archivos[] = 'uploads/' . $nombre_archivo_nuevo;
                    } else {
                        $errores_archivos[] = "Error al mover el archivo '{$nombre_original}'.";
                    }
                }
            }
        }

        if (!empty($errores_archivos)) {
            $error = implode('<br>', $errores_archivos);
        } else {
            $nombre_archivo_db = !empty($nombres_archivos) ? implode(',', $nombres_archivos) : null;
            $ruta_archivo_db = !empty($rutas_archivos) ? implode(',', $rutas_archivos) : null;

            if (empty($comentario) && empty($ruta_archivo_db)) {
                $error = "Debes escribir un comentario o adjuntar al menos un archivo.";
            } else {
                $comentario_exitoso = false;
                try {
                    $pdo->beginTransaction();
                    $fecha_comentario = (new DateTime('now', new DateTimeZone('America/Bogota')))->format('Y-m-d H:i:s');
                    $stmt_insert = $pdo->prepare(
                        "INSERT INTO comentarios_tarea (id_tarea, id_usuario, comentario, nombre_archivo, ruta_archivo, fecha_comentario) 
                         VALUES (?, ?, ?, ?, ?, ?)"
                    );
                    $stmt_insert->execute([$id_tarea, $_SESSION['user_id'], $comentario, $nombre_archivo_db, $ruta_archivo_db, $fecha_comentario]);
                    $pdo->commit();
                    $comentario_exitoso = true;
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    $error = "No se pudo enviar el comentario: " . $e->getMessage();
                }

                if ($comentario_exitoso) {
                    if (notificar_evento_tarea($id_tarea, 'nuevo_comentario', $_SESSION['user_id'], ['comentario' => $comentario])) {
                        $mensaje = "Comentario enviado y notificado.";
                    } else {
                        $error = "El comentario fue agregado, pero hubo un problema al enviar las notificaciones.";
                    }
                }
            }
        }
    }
    if (isset($_POST['cerrar_tarea'])) {
        if ($_SESSION['user_rol'] === 'admin') {
            $cerrado_exitoso = false;
            try {
                $pdo->beginTransaction();
                $stmt_update = $pdo->prepare("UPDATE tareas SET estado = 'completada' WHERE id_tarea = ?");
                $stmt_update->execute([$id_tarea]);
                $pdo->commit();
                $cerrado_exitoso = true;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $error = "Error al completar la tarea: " . $e->getMessage();
            }

            if ($cerrado_exitoso) {
                if (notificar_evento_tarea($id_tarea, 'admin_completa', $_SESSION['user_id'])) {
                    $mensaje = "¡Tarea marcada como completada y notificaciones enviadas!";
                } else {
                    $error = "La tarea fue marcada como completada, pero hubo un problema al enviar las notificaciones.";
                }
            }
        } else {
            $error = "No tienes permiso para esta acción.";
        }
    }
    if (isset($_POST['reabrir_tarea'])) {
        if ($_SESSION['user_rol'] === 'admin') {
            $reabrir_exitoso = false;
            try {
                $stmt = $pdo->prepare("UPDATE tareas SET estado = 'pendiente' WHERE id_tarea = ?");
                $stmt->execute([$id_tarea]);
                $reabrir_exitoso = true;
            }
            catch (PDOException $e) { $error = "Error al reabrir la tarea."; }
            
            if ($reabrir_exitoso) {
                if (notificar_evento_tarea($id_tarea, 'tarea_reabierta', $_SESSION['user_id'])) {
                    $mensaje = "La tarea ha sido reabierta.";
                } else {
                    $error = "La tarea ha sido reabierta, pero hubo un problema al enviar las notificaciones.";
                }
            }
        } else { $error = "No tienes permiso para esta acción."; }
    }
    if (isset($_POST['cambiar_a_pendiente'])) {
        if (in_array($_SESSION['user_rol'], ['admin', 'analista'])) {
            $cambio_exitoso = false;
            try {
                $pdo->beginTransaction();
                $stmt_update = $pdo->prepare("UPDATE tareas SET estado = 'pendiente' WHERE id_tarea = ?");
                $stmt_update->execute([$id_tarea]);
                $rol_display = ($_SESSION['user_rol'] === 'admin') ? 'administrador' : 'analista';
                $comentario_sistema = "La tarea ha sido devuelta al estado 'Pendiente' por un(a) {$rol_display}.";
                $fecha_comentario = (new DateTime('now', new DateTimeZone('America/Bogota')))->format('Y-m-d H:i:s');
                $stmt_comentario = $pdo->prepare("INSERT INTO comentarios_tarea (id_tarea, id_usuario, comentario, fecha_comentario) VALUES (?, ?, ?, ?)");
                $stmt_comentario->execute([$id_tarea, $_SESSION['user_id'], $comentario_sistema, $fecha_comentario]);
                $pdo->commit();
                $cambio_exitoso = true;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $error = "Error al cambiar el estado de la tarea: " . $e->getMessage();
            }

            if ($cambio_exitoso) {
                if (notificar_evento_tarea($id_tarea, 'tarea_devuelta_a_pendiente', $_SESSION['user_id'])) {
                    $mensaje = "La tarea ha sido devuelta a estado 'Pendiente'.";
                } else {
                    $error = "La tarea ha sido devuelta a 'Pendiente', pero hubo un problema al enviar las notificaciones.";
                }
            }
        } else {
            $error = "No tienes permiso para esta acción.";
        }
    }
    if (isset($_POST['eliminar_tarea'])) {
        if ($_SESSION['user_rol'] === 'admin') {
            $pdo->beginTransaction();
            try {
                // 1. Eliminar archivos de recursos de la tarea
                $stmt_recursos = $pdo->prepare("SELECT ruta_archivo FROM recursos_tarea WHERE id_tarea = ?");
                $stmt_recursos->execute([$id_tarea]);
                $recursos = $stmt_recursos->fetchAll(PDO::FETCH_COLUMN);
                foreach ($recursos as $ruta) {
                    if (file_exists(__DIR__ . '/../' . $ruta)) {
                        unlink(__DIR__ . '/../' . $ruta);
                    }
                }

                // 2. Eliminar archivos de comentarios
                $stmt_com_archivos = $pdo->prepare("SELECT ruta_archivo FROM comentarios_tarea WHERE id_tarea = ? AND ruta_archivo IS NOT NULL");
                $stmt_com_archivos->execute([$id_tarea]);
                $rutas_comentarios = $stmt_com_archivos->fetchAll(PDO::FETCH_COLUMN);
                foreach ($rutas_comentarios as $ruta_csv) {
                    $archivos = explode(',', $ruta_csv);
                    foreach($archivos as $ruta) {
                        if (file_exists(__DIR__ . '/../' . $ruta)) {
                            unlink(__DIR__ . '/../' . $ruta);
                        }
                    }
                }

                // 3. Eliminar registros de la base de datos
                $pdo->prepare("DELETE FROM comentarios_tarea WHERE id_tarea = ?")->execute([$id_tarea]);
                $pdo->prepare("DELETE FROM recursos_tarea WHERE id_tarea = ?")->execute([$id_tarea]);
                $pdo->prepare("DELETE FROM tareas_asignadas WHERE id_tarea = ?")->execute([$id_tarea]);
                $pdo->prepare("DELETE FROM tareas WHERE id_tarea = ?")->execute([$id_tarea]);
                
                $pdo->commit();
                
                // Usar sesión para el mensaje porque vamos a redirigir
                $_SESSION['user_message'] = "La tarea y todos sus datos asociados han sido eliminados permanentemente.";
                header("Location: tareas.php");
                exit();

            } catch (Exception $e) {
                $pdo->rollBack();
                // Guardar el error en una variable para mostrarlo en la misma página
                $error = "Error al eliminar la tarea: " . $e->getMessage();
            }
        } else {
            $error = "No tienes permiso para esta acción.";
        }
    }
}
if (isset($_GET['eliminar_recurso']) && in_array($_SESSION['user_rol'], ['admin', 'analista'])) {
    $id_recurso_a_eliminar = filter_var($_GET['eliminar_recurso'], FILTER_VALIDATE_INT);
    if ($id_recurso_a_eliminar) {
        try {
            $stmt_recurso = $pdo->prepare("SELECT * FROM recursos_tarea WHERE id_recurso = ? AND id_tarea = ?");
            $stmt_recurso->execute([$id_recurso_a_eliminar, $id_tarea]);
            $recurso_a_eliminar = $stmt_recurso->fetch();

            if ($recurso_a_eliminar) {
                $ruta_fisica = __DIR__ . '/../' . $recurso_a_eliminar['ruta_archivo'];
                if (file_exists($ruta_fisica)) {
                    unlink($ruta_fisica);
                }
                $stmt_delete = $pdo->prepare("DELETE FROM recursos_tarea WHERE id_recurso = ?");
                $stmt_delete->execute([$id_recurso_a_eliminar]);
                header("Location: editar_tarea.php?id=$id_tarea&msg=recurso_eliminado");
                exit();
            }
        } catch (PDOException $e) {
            $error = "Error al eliminar el recurso.";
        }
    }
}
if(isset($_GET['msg']) && $_GET['msg'] == 'recurso_eliminado') { $mensaje = "Recurso eliminado."; }
$stmt = $pdo->prepare("SELECT * FROM tareas WHERE id_tarea = ?"); $stmt->execute([$id_tarea]); $tarea = $stmt->fetch();
if (!$tarea) { header("Location: tareas.php"); exit(); }
$page_title = 'Editando Tarea: ' . e($tarea['nombre_tarea']);
$usuarios_asignables = $pdo->query("SELECT id_usuario, nombre_completo, rol FROM usuarios WHERE rol IN ('miembro', 'analista') ORDER BY nombre_completo ASC")->fetchAll();
$stmt_asignados = $pdo->prepare("SELECT id_usuario FROM tareas_asignadas WHERE id_tarea = ?"); $stmt_asignados->execute([$id_tarea]); $ids_miembros_asignados = $stmt_asignados->fetchAll(PDO::FETCH_COLUMN);
$stmt_comentarios = $pdo->prepare("SELECT c.*, u.nombre_completo, u.rol FROM comentarios_tarea c JOIN usuarios u ON c.id_usuario = u.id_usuario WHERE c.id_tarea = ? ORDER BY c.fecha_comentario ASC"); $stmt_comentarios->execute([$id_tarea]); $lista_comentarios = $stmt_comentarios->fetchAll();
$recursos_stmt = $pdo->prepare("SELECT * FROM recursos_tarea WHERE id_tarea = ?"); $recursos_stmt->execute([$id_tarea]); $recursos = $recursos_stmt->fetchAll();
include '../includes/header_admin.php';
?>
<?php if ($mensaje): ?><div class="alert alert-success"><?php echo e($mensaje); ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<div style="margin-bottom: 20px; text-align: right;"><a href="generar_historial_pdf.php?id_tarea=<?php echo $id_tarea; ?>" class="btn btn-secondary" target="_blank"><i class="fas fa-file-pdf"></i> Descargar Historial</a></div>
<div class="grid-container">
    <div class="card">
        <h3>Detalles de la Tarea</h3>
        <?php if (isset($_SESSION['user_rol']) && $_SESSION['user_rol'] === 'admin'): ?>
        <div style="padding: 15px; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 20px; background-color: #f9f9f9;">
            <h4 style="margin-top:0;">Acciones de Estado</h4>
            <p><strong>Estado Actual:</strong> <?php echo mostrar_estado_tarea($tarea); ?></p>
            <div class="state-actions">
                <?php if ($tarea['estado'] === 'pendiente'): ?>
                    <form action="editar_tarea.php?id=<?php echo $id_tarea; ?>" method="POST" onsubmit="return confirm('¿Seguro?');" style="display:inline-block; margin:0;"><button type="submit" name="cerrar_tarea" class="btn btn-success"><i class="fas fa-check-double"></i> Confirmar y Completar</button></form>
                <?php elseif ($tarea['estado'] === 'finalizada_usuario'): ?>
                    <form action="editar_tarea.php?id=<?php echo $id_tarea; ?>" method="POST" onsubmit="return confirm('¿Seguro?');" style="display:inline-block; margin:0;"><button type="submit" name="cerrar_tarea" class="btn btn-success"><i class="fas fa-check-double"></i> Confirmar y Completar</button></form>
                    <form action="editar_tarea.php?id=<?php echo $id_tarea; ?>" method="POST" onsubmit="return confirm('¿Estás seguro de que quieres devolver esta tarea a pendiente?');" style="display:inline-block; margin:0; margin-left: 10px;"><button type="submit" name="cambiar_a_pendiente" class="btn btn-warning"><i class="fas fa-arrow-left"></i> Cambiar a Pendiente</button></form>
                <?php elseif ($tarea['estado'] === 'completada'): ?>
                    <form action="editar_tarea.php?id=<?php echo $id_tarea; ?>" method="POST" onsubmit="return confirm('¿Seguro?');" style="display:inline-block; margin:0;"><button type="submit" name="reabrir_tarea" class="btn btn-secondary"><i class="fas fa-undo"></i> Reabrir Tarea</button></form>
                <?php endif; ?>
                <hr style="margin: 15px 0;">
                <form action="editar_tarea.php?id=<?php echo $id_tarea; ?>" method="POST" onsubmit="return confirm('¡ADVERTENCIA! Esta acción es irreversible. ¿Estás seguro de que quieres eliminar esta tarea y todos sus datos asociados?');" style="display:inline-block; margin:0;">
                    <button type="submit" name="eliminar_tarea" class="btn btn-danger"><i class="fas fa-trash-can"></i> Eliminar Tarea</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <?php // Bloque de acciones específico para Analistas ?>
        <?php if (isset($_SESSION['user_rol']) && $_SESSION['user_rol'] === 'analista' && $tarea['estado'] === 'finalizada_usuario'): ?>
        <div style="padding: 15px; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 20px; background-color: #f9f9f9;">
            <h4 style="margin-top:0;">Acciones de Estado</h4>
            <p><strong>Estado Actual:</strong> <?php echo mostrar_estado_tarea($tarea); ?></p>
            <form action="editar_tarea.php?id=<?php echo $id_tarea; ?>" method="POST" onsubmit="return confirm('¿Estás seguro de que quieres devolver esta tarea a pendiente?');" style="display:inline-block; margin:0;">
                <button type="submit" name="cambiar_a_pendiente" class="btn btn-warning"><i class="fas fa-arrow-left"></i> Cambiar a Pendiente</button>
            </form>
        </div>
        <?php endif; ?>

        <form action="editar_tarea.php?id=<?php echo $id_tarea; ?>" method="POST" enctype="multipart/form-data">
            <div class="form-group"><label>Nombre (*)</label><input type="text" name="nombre_tarea" value="<?php echo e($tarea['nombre_tarea']); ?>" required></div>
            <div class="form-group"><label>Fecha Creación</label><input type="text" value="<?php echo date('d/m/Y H:i', strtotime($tarea['fecha_creacion'])); ?>" disabled></div>
            <div class="form-group"><label>Descripción</label><textarea id="descripcion" name="descripcion" rows="5"><?php echo e($tarea['descripcion']); ?></textarea></div>
            <div class="form-group"><label>Fecha Vencimiento (*)</label><input type="datetime-local" name="fecha_vencimiento" value="<?php echo date('Y-m-d\TH:i', strtotime($tarea['fecha_vencimiento'])); ?>" required></div>
            <div class="form-group"><label>Prioridad (*)</label><select name="prioridad" required><option value="baja" <?php if($tarea['prioridad'] == 'baja') echo 'selected'; ?>>Baja</option><option value="media" <?php if($tarea['prioridad'] == 'media') echo 'selected'; ?>>Media</option><option value="alta" <?php if($tarea['prioridad'] == 'alta') echo 'selected'; ?>>Alta</option></select></div>
            <div class="form-group">
                <label for="numero_piezas">Número de piezas</label>
                <input type="number" id="numero_piezas" name="numero_piezas" value="<?php echo e($tarea['numero_piezas'] ?? 0); ?>" min="0">
            </div>
            <div class="form-group">
                <label for="tipo_trabajo">Tipo de Trabajo (*)</label>
                <select id="tipo_trabajo" name="tipo_trabajo" required>
                    <option value="">Seleccione el tipo de trabajo</option>
                    <option value="Digital" <?php if(isset($tarea['tipo_trabajo']) && $tarea['tipo_trabajo'] == 'Digital') echo 'selected'; ?>>Digital</option>
                    <option value="Impreso" <?php if(isset($tarea['tipo_trabajo']) && $tarea['tipo_trabajo'] == 'Impreso') echo 'selected'; ?>>Impreso</option>
                </select>
            </div>
            <div class="form-group">
                <label for="negocio">Negocio</label>
                <select id="negocio" name="negocio">
                    <option value="">Seleccione un negocio</option>
                    <option value="Recreacion" <?php if(isset($tarea['negocio']) && $tarea['negocio'] == 'Recreacion') echo 'selected'; ?>>Recreación</option>
                    <option value="Educacion" <?php if(isset($tarea['negocio']) && $tarea['negocio'] == 'Educacion') echo 'selected'; ?>>Educación</option>
                    <option value="Gestion 4%" <?php if(isset($tarea['negocio']) && $tarea['negocio'] == 'Gestion 4%') echo 'selected'; ?>>Gestión 4%</option>
                    <option value="MPC" <?php if(isset($tarea['negocio']) && $tarea['negocio'] == 'MPC') echo 'selected'; ?>>MPC</option>
                    <option value="Credito" <?php if(isset($tarea['negocio']) && $tarea['negocio'] == 'Credito') echo 'selected'; ?>>Crédito</option>
                    <option value="Interna" <?php if(isset($tarea['negocio']) && $tarea['negocio'] == 'Interna') echo 'selected'; ?>>Interna</option>
                    <option value="Dirección" <?php if(isset($tarea['negocio']) && $tarea['negocio'] == 'Dirección') echo 'selected'; ?>>Dirección</option>
                    <option value="Infraestructura" <?php if(isset($tarea['negocio']) && $tarea['negocio'] == 'Infraestructura') echo 'selected'; ?>>Infraestructura</option>
                </select>
            </div>
            <hr><h4>Usuarios Asignados</h4>
            <div class="form-group"><div style="max-height: 150px; overflow-y: auto; border: 1px solid #ccc; padding: 10px;">
                <?php foreach ($usuarios_asignables as $usuario): ?><div><input type="checkbox" name="miembros_asignados[]" value="<?php echo $usuario['id_usuario']; ?>" id="usuario_<?php echo $usuario['id_usuario']; ?>" <?php if(in_array($usuario['id_usuario'], $ids_miembros_asignados)) echo 'checked'; ?>><label for="usuario_<?php echo $usuario['id_usuario']; ?>"><?php echo e($usuario['nombre_completo']); ?> (<?php echo e(ucfirst($usuario['rol'])); ?>)</label></div><?php endforeach; ?>
            </div></div>
            <hr><h4>Recursos</h4>
            <div class="form-group"><label>Recursos Actuales:</label><div class="resource-list">
                <?php if (empty($recursos)): ?>
                    <p style="grid-column: 1 / -1; text-align:center; color: #777;">No hay recursos.</p>
                <?php else: ?>
                    <?php foreach ($recursos as $recurso): ?>
                        <?php
                        $ruta_archivo = e($recurso['ruta_archivo']);
                        $nombre_archivo = e($recurso['nombre_archivo']);
                        $extension = strtolower(pathinfo($ruta_archivo, PATHINFO_EXTENSION));
                        $is_image = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                        ?>
                        <div class="resource-item" id="recurso-<?php echo $recurso['id_recurso']; ?>">
                            <a href="../<?php echo $ruta_archivo; ?>" target="_blank" class="resource-link" title="Ver <?php echo $nombre_archivo; ?>">
                                <?php if ($is_image): ?>
                                    <img src="../<?php echo $ruta_archivo; ?>" alt="<?php echo $nombre_archivo; ?>" class="preview-image">
                                <?php else: ?>
                                    <div class="file-icon"><i class="fas fa-file-alt"></i></div>
                                <?php endif; ?>
                                <span class="file-name"><?php echo $nombre_archivo; ?></span>
                            </a>
                            <?php if (in_array($_SESSION['user_rol'], ['admin', 'analista'])): ?>
                                <a href="editar_tarea.php?id=<?php echo $id_tarea; ?>&eliminar_recurso=<?php echo $recurso['id_recurso']; ?>"
                                   class="btn-delete-resource"
                                   onclick="return confirm('¿Estás seguro de que quieres eliminar este recurso?');">
                                    <i class="fas fa-trash-can"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div></div>
            <div class="form-group">
                <label>Añadir Nuevos:</label>
                <div class="file-input-wrapper">
                    <button type="button" class="btn-select-files"><i class="fas fa-paperclip"></i> Seleccionar Archivos</button>
                    <input type="file" name="recursos[]" multiple style="display: none;" id="recursos-input">
                </div>
                <div id="file-list" class="file-list-preview"></div>
            </div>
            <hr style="margin-top: 2rem;"><button type="submit" name="actualizar_tarea" class="btn btn-success"><i class="fas fa-save"></i> Guardar Cambios</button>
        </form>
    </div>
    <div class="card">
        <h3>Comentarios</h3>
        <?php if (!empty($lista_comentarios)): ?>
            <div class="chat-box">
                <?php foreach ($lista_comentarios as $comentario): ?>
                    <div class="comment <?php echo ($comentario['rol'] === 'admin' || $comentario['rol'] === 'analista') ? 'comment-admin' : 'comment-miembro'; ?>">
                        <p><strong><?php echo e($comentario['nombre_completo']); ?>:</strong></p>
                        <?php if (!empty($comentario['comentario'])): ?>
                            <p><?php echo nl2br(e($comentario['comentario'])); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($comentario['ruta_archivo'])): ?>
                            <div class="attachment">
                                <p><strong>Archivos adjuntos:</strong></p>
                                <?php
                                $rutas = explode(',', $comentario['ruta_archivo']);
                                $nombres = explode(',', $comentario['nombre_archivo']);
                                foreach ($rutas as $index => $ruta_archivo):
                                    $ruta_archivo_esc = e($ruta_archivo);
                                    $nombre_archivo_esc = e($nombres[$index] ?? 'Archivo');
                                    $extension = strtolower(pathinfo($ruta_archivo_esc, PATHINFO_EXTENSION));
                                    $is_image = in_array($extension, ['jpg', 'jpeg', 'png', 'gif']);
                                ?>
                                    <a href="../<?php echo $ruta_archivo_esc; ?>" target="_blank" class="resource-link" style="display: block; margin-bottom: 5px;">
                                        <?php if ($is_image): ?>
                                            <img src="../<?php echo $ruta_archivo_esc; ?>" alt="<?php echo $nombre_archivo_esc; ?>" style="max-width: 100px; max-height: 100px; border-radius: 5px; vertical-align: middle; margin-right: 10px;">
                                        <?php else: ?>
                                            <i class="fas fa-file-alt" style="margin-right: 10px;"></i>
                                        <?php endif; ?>
                                        <span><?php echo $nombre_archivo_esc; ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="meta"><?php echo date('d/m/Y H:i', strtotime($comentario['fecha_comentario'])); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p style="text-align: center; color: #777;">No hay comentarios.</p>
        <?php endif; ?>
        <form action="editar_tarea.php?id=<?php echo $id_tarea; ?>" method="POST" style="margin-top:20px;" enctype="multipart/form-data">
            <div class="form-group">
                <label for="comentario">Añadir Comentario:</label>
                <textarea name="comentario" id="comentario" rows="4"></textarea>
            </div>
            <div class="form-group">
                <label for="archivos_comentario">Adjuntar archivos (opcional, PDF o imagen, max 5MB por archivo):</label>
                <input type="file" name="archivos_comentario[]" id="archivos_comentario" accept=".pdf,.jpg,.jpeg,.png" multiple>
            </div>
            <button type="submit" name="agregar_comentario" class="btn">Enviar Comentario</button>
        </form>
    </div>
</div>

<style>
.file-input-wrapper {
    margin-bottom: 10px;
}
.btn-select-files {
    padding: 10px 15px;
    background-color: var(--secondary-color);
    color: white;
    border: none;
    border-radius: 5px;
    cursor: pointer;
}
.file-list-preview {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 10px;
}
.file-item {
    display: flex;
    align-items: center;
    background-color: #f0f0f0;
    border-radius: 5px;
    padding: 5px 10px;
    font-size: 0.9em;
}
.file-item .file-name {
    margin-right: 10px;
}
.file-item .btn-remove-file {
    background: none;
    border: none;
    color: var(--danger-color);
    cursor: pointer;
    font-size: 1.1em;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const fileInput = document.getElementById('recursos-input');
    const fileListContainer = document.getElementById('file-list');
    const selectFilesButton = document.querySelector('.btn-select-files');
    let selectedFiles = new DataTransfer();

    selectFilesButton.addEventListener('click', function() {
        fileInput.click();
    });

    fileInput.addEventListener('change', function() {
        for (const file of fileInput.files) {
            selectedFiles.items.add(file);
        }
        updateFileInput();
        renderFileList();
    });

    function renderFileList() {
        fileListContainer.innerHTML = '';
        for (let i = 0; i < selectedFiles.files.length; i++) {
            const file = selectedFiles.files[i];
            const fileItem = document.createElement('div');
            fileItem.className = 'file-item';
            
            const fileName = document.createElement('span');
            fileName.className = 'file-name';
            fileName.textContent = file.name;
            fileItem.appendChild(fileName);
            
            const removeButton = document.createElement('button');
            removeButton.className = 'btn-remove-file';
            removeButton.innerHTML = '&times;';
            removeButton.type = 'button';
            removeButton.onclick = function() {
                removeFile(i);
            };
            fileItem.appendChild(removeButton);
            
            fileListContainer.appendChild(fileItem);
        }
    }

    function removeFile(index) {
        const newFiles = new DataTransfer();
        for (let i = 0; i < selectedFiles.files.length; i++) {
            if (i !== index) {
                newFiles.items.add(selectedFiles.files[i]);
            }
        }
        selectedFiles = newFiles;
        updateFileInput();
        renderFileList();
    }

    function updateFileInput() {
        fileInput.files = selectedFiles.files;
    }
});
</script>

<?php include '../includes/footer_admin.php'; ?>
