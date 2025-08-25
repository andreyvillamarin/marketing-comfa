<?php $page_title = 'Crear Nueva Tarea'; ?>
<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/funciones.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_rol'], ['admin', 'analista'])) {
    header("Location: login.php");
    exit();
}

$mensaje = '';
$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nombre_tarea = trim($_POST['nombre_tarea'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $fecha_vencimiento = $_POST['fecha_vencimiento'] ?? '';
    $prioridad = $_POST['prioridad'] ?? '';
    $miembros_asignados = $_POST['miembros_asignados'] ?? [];
    $numero_piezas = isset($_POST['numero_piezas']) && $_POST['numero_piezas'] !== '' ? (int)$_POST['numero_piezas'] : 0;
    $negocio = isset($_POST['negocio']) ? trim($_POST['negocio']) : '';

    if (empty($nombre_tarea) || empty($fecha_vencimiento) || empty($prioridad) || empty($miembros_asignados)) {
        $error = 'Todos los campos marcados con * son obligatorios.';
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO tareas (nombre_tarea, descripcion, fecha_vencimiento, prioridad, id_admin_creador, estado, numero_piezas, negocio) VALUES (?, ?, ?, ?, ?, 'pendiente', ?, ?)");
            $stmt->execute([$nombre_tarea, $descripcion, $fecha_vencimiento, $prioridad, $_SESSION['user_id'], $numero_piezas, $negocio]);
            $id_tarea = $pdo->lastInsertId();
            $stmt_asignar = $pdo->prepare("INSERT INTO tareas_asignadas (id_tarea, id_usuario) VALUES (?, ?)");
            foreach ($miembros_asignados as $id_miembro) {
                $stmt_asignar->execute([$id_tarea, $id_miembro]);
            }
            if (!empty($_FILES['recursos']['name'][0])) {
                $stmt_recurso = $pdo->prepare("INSERT INTO recursos_tarea (id_tarea, nombre_archivo, ruta_archivo) VALUES (?, ?, ?)");
                $upload_dir = __DIR__ . '/../uploads/';
                foreach ($_FILES['recursos']['name'] as $key => $name) {
                    $tmp_name = $_FILES['recursos']['tmp_name'][$key];
                    $file_name = time() . '_' . basename($name);
                    $target_file = $upload_dir . $file_name;
                    if (move_uploaded_file($tmp_name, $target_file)) {
                        $stmt_recurso->execute([$id_tarea, $name, 'uploads/' . $file_name]);
                    }
                }
            }
            $pdo->commit();
            
            notificar_evento_tarea($id_tarea, 'tarea_creada', $_SESSION['user_id']);
            $mensaje = "Tarea creada y notificada exitosamente.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error al crear la tarea: " . $e->getMessage();
        }
    }
}

// --- INICIO DE LA MODIFICACIÓN: CONSULTA PARA OBTENER MIEMBROS Y ANALISTAS ---
$stmt_usuarios_asignables = $pdo->query("SELECT id_usuario, nombre_completo, rol FROM usuarios WHERE rol IN ('miembro', 'analista') ORDER BY nombre_completo ASC");
$usuarios_asignables = $stmt_usuarios_asignables->fetchAll();
// --- FIN DE LA MODIFICACIÓN ---

include '../includes/header_admin.php';
?>
<div class="card form-card">
    <form action="crear_tarea.php" method="POST" enctype="multipart/form-data">
        <?php if ($mensaje): ?><div class="alert alert-success"><?php echo e($mensaje); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
        <div class="form-group">
            <label for="nombre_tarea">Nombre de la Tarea (*)</label>
            <input type="text" id="nombre_tarea" name="nombre_tarea" required>
        </div>
        <div class="form-group">
            <label for="descripcion">Descripción</label>
            <textarea id="descripcion" name="descripcion" rows="4"></textarea>
        </div>
        <div class="form-group">
            <label for="fecha_vencimiento">Fecha de Vencimiento (*)</label>
            <input type="datetime-local" id="fecha_vencimiento" name="fecha_vencimiento" required>
        </div>
        <div class="form-group">
            <label for="prioridad">Prioridad (*)</label>
            <select id="prioridad" name="prioridad" required>
                <option value="baja">Baja</option>
                <option value="media" selected>Media</option>
                <option value="alta">Alta</option>
            </select>
        </div>
        <div class="form-group">
            <label for="numero_piezas">Número de piezas</label>
            <input type="number" id="numero_piezas" name="numero_piezas" value="0" min="0">
        </div>
        <div class="form-group">
            <label for="negocio">Negocio</label>
            <select id="negocio" name="negocio">
                <option value="">Seleccione un negocio</option>
                <option value="Recreacion">Recreación</option>
                <option value="Educacion">Educación</option>
                <option value="Gestion 4%">Gestión 4%</option>
                <option value="MPC">MPC</option>
                <option value="Credito">Crédito</option>
                <option value="Interna">Interna</option>
            </select>
        </div>
        <div class="form-group">
            <label>Asignar a Usuarios (*)</label>
            <div style="max-height: 150px; overflow-y: auto; border: 1px solid #ccc; padding: 10px;">
                <?php foreach ($usuarios_asignables as $usuario): ?>
                    <div>
                        <input type="checkbox" name="miembros_asignados[]" value="<?php echo $usuario['id_usuario']; ?>" id="usuario_<?php echo $usuario['id_usuario']; ?>">
                        <label for="usuario_<?php echo $usuario['id_usuario']; ?>"><?php echo e($usuario['nombre_completo']); ?> (<?php echo e(ucfirst($usuario['rol'])); ?>)</label>
                    </div>
                <?php endforeach; ?>
                </div>
        </div>
        <div class="form-group">
            <label for="recursos">Recursos Multimedia (opcional)</label>
            <div class="file-input-wrapper">
                <button type="button" class="btn-select-files"><i class="fas fa-paperclip"></i> Seleccionar Archivos</button>
                <input type="file" id="recursos" name="recursos[]" multiple style="display: none;">
            </div>
            <div id="file-list" class="file-list-preview"></div>
        </div>
        <button type="submit" class="btn btn-success"><i class="fas fa-plus-circle"></i> Crear Tarea</button>
    </form>
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
    const fileInput = document.getElementById('recursos');
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