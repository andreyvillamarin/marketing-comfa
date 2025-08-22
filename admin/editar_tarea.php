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
        $negocio = isset($_POST['negocio']) ? trim($_POST['negocio']) : '';
        if (empty($nombre_tarea) || empty($fecha_vencimiento) || empty($prioridad)) {
            $error = 'El nombre, la fecha de vencimiento y la prioridad son obligatorios.';
        } else {
            $stmt_originales = $pdo->prepare("SELECT id_usuario FROM tareas_asignadas WHERE id_tarea = ?");
            $stmt_originales->execute([$id_tarea]);
            $ids_miembros_originales = $stmt_originales->fetchAll(PDO::FETCH_COLUMN);
            $pdo->beginTransaction();
            try {
                $stmt_update = $pdo->prepare("UPDATE tareas SET nombre_tarea = ?, descripcion = ?, fecha_vencimiento = ?, prioridad = ?, numero_piezas = ?, negocio = ? WHERE id_tarea = ?");
                $stmt_update->execute([$nombre_tarea, $descripcion, $fecha_vencimiento, $prioridad, $numero_piezas, $negocio, $id_tarea]);
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
                notificar_evento_tarea($id_tarea, 'tarea_editada', $_SESSION['user_id']);
                $mensaje = "¡Tarea actualizada exitosamente!";
            } catch (Exception $e) { $pdo->rollBack(); $error = "Error al actualizar la tarea: " . $e->getMessage(); }
        }
    }
    if (isset($_POST['agregar_comentario'])) {
        $comentario = trim($_POST['comentario']);
        if (!empty($comentario)) {
            $pdo->beginTransaction();
            try {
                $stmt_insert = $pdo->prepare("INSERT INTO comentarios_tarea (id_tarea, id_usuario, comentario) VALUES (?, ?, ?)");
                $stmt_insert->execute([$id_tarea, $_SESSION['user_id'], $comentario]);

                $pdo->commit();
                notificar_evento_tarea($id_tarea, 'nuevo_comentario', $_SESSION['user_id']);
                $mensaje = "Comentario agregado y notificado.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Error al agregar el comentario: " . $e->getMessage();
            }
        }
    }
    if (isset($_POST['cerrar_tarea'])) {
        if ($_SESSION['user_rol'] === 'admin') {
            $pdo->beginTransaction();
            try {
                $stmt_update = $pdo->prepare("UPDATE tareas SET estado = 'completada' WHERE id_tarea = ?");
                $stmt_update->execute([$id_tarea]);
                $pdo->commit();
                notificar_evento_tarea($id_tarea, 'admin_completa', $_SESSION['user_id']);
                $mensaje = "¡Tarea marcada como completada y notificaciones enviadas!";
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Error al completar la tarea: " . $e->getMessage();
            }
        } else {
            $error = "No tienes permiso para esta acción.";
        }
    }
    if (isset($_POST['reabrir_tarea'])) {
        if ($_SESSION['user_rol'] === 'admin') {
            try {
                $stmt = $pdo->prepare("UPDATE tareas SET estado = 'pendiente' WHERE id_tarea = ?");
                $stmt->execute([$id_tarea]);
                notificar_evento_tarea($id_tarea, 'tarea_reabierta', $_SESSION['user_id']);
                $mensaje = "La tarea ha sido reabierta.";
            }
            catch (PDOException $e) { $error = "Error al reabrir la tarea."; }
        } else { $error = "No tienes permiso para esta acción."; }
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
            <?php if ($tarea['estado'] === 'pendiente' || $tarea['estado'] === 'finalizada_usuario'): ?>
                <form action="editar_tarea.php?id=<?php echo $id_tarea; ?>" method="POST" onsubmit="return confirm('¿Seguro?');" style="margin:0;"><button type="submit" name="cerrar_tarea" class="btn btn-success"><i class="fas fa-check-double"></i> Confirmar y Completar</button></form>
            <?php elseif ($tarea['estado'] === 'completada'): ?>
                <form action="editar_tarea.php?id=<?php echo $id_tarea; ?>" method="POST" onsubmit="return confirm('¿Seguro?');" style="margin:0;"><button type="submit" name="reabrir_tarea" class="btn btn-secondary"><i class="fas fa-undo"></i> Reabrir Tarea</button></form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <form action="editar_tarea.php?id=<?php echo $id_tarea; ?>" method="POST" enctype="multipart/form-data">
            <div class="form-group"><label>Nombre (*)</label><input type="text" name="nombre_tarea" value="<?php echo e($tarea['nombre_tarea']); ?>" required></div>
            <div class="form-group"><label>Descripción</label><textarea name="descripcion" rows="5"><?php echo e($tarea['descripcion']); ?></textarea></div>
            <div class="form-group"><label>Fecha Vencimiento (*)</label><input type="datetime-local" name="fecha_vencimiento" value="<?php echo date('Y-m-d\TH:i', strtotime($tarea['fecha_vencimiento'])); ?>" required></div>
            <div class="form-group"><label>Prioridad (*)</label><select name="prioridad" required><option value="baja" <?php if($tarea['prioridad'] == 'baja') echo 'selected'; ?>>Baja</option><option value="media" <?php if($tarea['prioridad'] == 'media') echo 'selected'; ?>>Media</option><option value="alta" <?php if($tarea['prioridad'] == 'alta') echo 'selected'; ?>>Alta</option></select></div>
            <div class="form-group">
                <label for="numero_piezas">Número de piezas</label>
                <input type="number" id="numero_piezas" name="numero_piezas" value="<?php echo e($tarea['numero_piezas'] ?? 0); ?>" min="0">
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
                            <?php
                            $ruta_archivo = e($comentario['ruta_archivo']);
                            $nombre_archivo = e($comentario['nombre_archivo']);
                            $extension = strtolower(pathinfo($ruta_archivo, PATHINFO_EXTENSION));
                            $is_image = in_array($extension, ['jpg', 'jpeg', 'png', 'gif']);
                            ?>
                            <div class="attachment">
                                <p><strong>Archivo adjunto:</strong></p>
                                <a href="../<?php echo $ruta_archivo; ?>" target="_blank" class="resource-link">
                                    <?php if ($is_image): ?>
                                        <img src="../<?php echo $ruta_archivo; ?>" alt="<?php echo $nombre_archivo; ?>" style="max-width: 100px; max-height: 100px; border-radius: 5px; margin-top: 5px;">
                                    <?php else: ?>
                                        <i class="fas fa-file-alt"></i> <?php echo $nombre_archivo; ?>
                                    <?php endif; ?>
                                </a>
                            </div>
                        <?php endif; ?>
                        <div class="meta"><?php echo date('d/m/Y H:i', strtotime($comentario['fecha_comentario'])); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p style="text-align: center; color: #777;">No hay comentarios.</p>
        <?php endif; ?>
        <form action="editar_tarea.php?id=<?php echo $id_tarea; ?>" method="POST" style="margin-top:20px;">
            <div class="form-group"><label>Añadir comentario:</label><textarea name="comentario" required rows="4"></textarea></div>
            <button type="submit" name="agregar_comentario" class="btn">Enviar</button>
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