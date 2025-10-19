<?php $page_title = 'Tareas Completadas'; ?>
<?php
require_once '../includes/config.php'; require_once '../includes/db.php'; require_once '../includes/funciones.php';
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_rol'], ['admin', 'analista'])) { header("Location: login.php"); exit(); }
$id_usuario_actual = $_SESSION['user_id']; $rol_usuario_actual = $_SESSION['user_rol']; $mensaje = ''; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_seleccionados'])) {
    if ($rol_usuario_actual === 'admin') {
        $ids_tareas = isset($_POST['ids_tareas']) ? $_POST['ids_tareas'] : [];
        if (!empty($ids_tareas)) { try { $placeholders = implode(',', array_fill(0, count($ids_tareas), '?')); $stmt = $pdo->prepare("DELETE FROM tareas WHERE id_tarea IN ($placeholders)"); $stmt->execute($ids_tareas); $mensaje = "Tareas seleccionadas eliminadas."; } catch (PDOException $e) { $error = "Error al eliminar las tareas."; }
        } else { $error = "No se selecciono ninguna tarea."; }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archivar_seleccionados'])) {
    if ($rol_usuario_actual === 'admin') {
        $ids_tareas = isset($_POST['ids_tareas']) ? $_POST['ids_tareas'] : [];
        if (!empty($ids_tareas)) {
            try {
                $placeholders = implode(',', array_fill(0, count($ids_tareas), '?'));
                $stmt = $pdo->prepare("UPDATE tareas SET archivada = 1 WHERE id_tarea IN ($placeholders)");
                $stmt->execute($ids_tareas);
                $mensaje = "Tareas seleccionadas archivadas.";
            } catch (PDOException $e) {
                $error = "Error al archivar las tareas.";
            }
        } else {
            $error = "No se seleccionó ninguna tarea.";
        }
    }
}
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
$where_clauses = ["t.estado = 'completada'", "t.archivada = 0"];

if ($rol_usuario_actual === 'analista') {
    $where_clauses[] = "t.id_admin_creador = ?";
    $params[] = $id_usuario_actual;
}

if (!empty($_GET['q'])) {
    $search_term = '%' . $_GET['q'] . '%';
    $where_clauses[] = "(t.nombre_tarea LIKE ? OR u_creador.nombre_completo LIKE ? OR u_asignado.nombre_completo LIKE ? OR t.negocio LIKE ?)";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if (!empty($_GET['fecha_creacion'])) {
    $where_clauses[] = "DATE(t.fecha_creacion) = ?";
    $params[] = $_GET['fecha_creacion'];
}

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(' AND ', $where_clauses);
}

$sql .= " GROUP BY t.id_tarea ORDER BY t.fecha_vencimiento DESC"; // Order by most recent
try { $stmt = $pdo->prepare($sql); $stmt->execute($params); $tareas = $stmt->fetchAll(); } 
catch(PDOException $e) { die("Error al recuperar las tareas: " . $e->getMessage()); }
include '../includes/header_admin.php';
?>
<?php if ($mensaje): ?><div class="alert alert-success"><?php echo e($mensaje); ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<div class="card" id="filter-container">
    <h3><i class="fas fa-filter"></i> Filtrar Tareas Completadas</h3>
    <div class="filter-controls">
        <div class="form-group search-group">
            <label for="q">Buscar:</label>
            <input type="text" id="q" name="q" placeholder="Buscar por tarea, creador, miembro o negocio...">
        </div>
        <div class="form-group date-group">
            <label for="fecha_creacion">Fecha Creación:</label>
            <input type="date" id="fecha_creacion" name="fecha_creacion">
        </div>
        <button id="clear-filters-btn" class="btn btn-secondary">Limpiar</button>
    </div>
</div>
<form action="tareas_completadas.php" method="POST">
    <?php if ($rol_usuario_actual === 'admin'): ?>
    <button type="submit" name="archivar_seleccionados" class="btn btn-secondary" onclick="return confirm('¿Estás seguro de que deseas archivar las tareas seleccionadas?');" style="margin-top: 20px; margin-left: 10px; background-color: #6c757d; border-color: #6c757d;"><i class="fas fa-archive"></i> Archivar Seleccionados</button>
    <button type="submit" name="eliminar_seleccionados" class="btn btn-danger" onclick="return confirm('Estas Seguro?');" style="margin-top: 20px;"><i class="fas fa-trash-can"></i> Eliminar Seleccionados</button>
    <?php endif; ?>
    <div class="table-wrapper">
        <table class="tabla-tareas">
            <thead>
                <tr>
                    <?php if ($rol_usuario_actual === 'admin'): ?><th><input type="checkbox" id="selectAll"></th><?php endif; ?>
                    <th>Nombre Tarea</th>
                    <th>Creador</th>
                    <th>Miembro Asignado</th>
                    <th>Negocio</th>
                    <th>Fecha Creación</th>
                    <th>Fecha Vencimiento</th>
                    <th>Estado</th>
                    <th>Prioridad</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody id="task-table-body">
                <?php if (empty($tareas)): ?>
                    <tr><td colspan="<?php echo ($rol_usuario_actual === 'admin') ? '10' : '9'; ?>" style="text-align:center;">No se encontraron tareas completadas.</td></tr>
                <?php else: ?>
                    <?php foreach ($tareas as $tarea): ?>
                        <tr>
                            <?php if ($rol_usuario_actual === 'admin'): ?><td><input type="checkbox" name="ids_tareas[]" value="<?php echo $tarea['id_tarea']; ?>"></td><?php endif; ?>
                            <td><?php echo e($tarea['nombre_tarea']); ?></td>
                            <td><?php echo e($tarea['creador']); ?></td>
                            <td><?php echo e($tarea['miembros_asignados'] ?? 'N/A'); ?></td>
                            <td><?php echo e($tarea['negocio'] ?? 'N/A'); ?></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($tarea['fecha_creacion'])); ?></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($tarea['fecha_vencimiento'])); ?></td>
                            <td>
                                <?php echo mostrar_estado_tarea($tarea); ?>
                            </td>
                            <td>
                                <?php
                                $prioridad_clase = e($tarea['prioridad']); $prioridad_texto = ucfirst($prioridad_clase); $prioridad_icono = 'fa-circle-info';
                                if ($prioridad_clase == 'alta') $prioridad_icono = 'fa-triangle-exclamation'; if ($prioridad_clase == 'media') $prioridad_icono = 'fa-circle-exclamation';
                                echo "<span class='icon-text icon-prioridad-{$prioridad_clase}'><i class='fas {$prioridad_icono}'></i> {$prioridad_texto}</span>";
                                ?>
                            </td>
                            <td class="actions"><a href="editar_tarea.php?id=<?php echo $tarea['id_tarea']; ?>" class="btn btn-warning"><i class="fas fa-pencil-alt"></i> Ver/Editar</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('q');
    const dateInput = document.getElementById('fecha_creacion');
    const tableBody = document.getElementById('task-table-body');
    const clearButton = document.getElementById('clear-filters-btn');
    
    let debounceTimer;

    function fetchTasks() {
        const q = searchInput.value;
        const fecha = dateInput.value;
        
        const params = new URLSearchParams({
            q: q,
            fecha_creacion: fecha,
            tipo_pagina: 'completadas' // Para que el API sepa qué tipo de tareas buscar
        });

        // Muestra un indicador de carga
        tableBody.innerHTML = '<tr><td colspan="9" style="text-align:center;">Cargando...</td></tr>';

        fetch(`../api/filtrar_tareas.php?${params.toString()}`)
            .then(response => response.text())
            .then(html => {
                tableBody.innerHTML = html;
            })
            .catch(error => {
                console.error('Error al filtrar tareas:', error);
                tableBody.innerHTML = '<tr><td colspan="9" style="text-align:center;">Error al cargar los datos.</td></tr>';
            });
    }

    searchInput.addEventListener('keyup', function () {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(fetchTasks, 300); // Espera 300ms después de la última tecla
    });

    dateInput.addEventListener('change', fetchTasks);

    clearButton.addEventListener('click', function() {
        searchInput.value = '';
        dateInput.value = '';
        fetchTasks();
    });
});
</script>

<?php include '../includes/footer_admin.php'; ?>
