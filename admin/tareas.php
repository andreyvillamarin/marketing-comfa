<?php $page_title = 'Todas las Tareas'; ?>
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
$where_clauses = [];

if ($rol_usuario_actual === 'analista') {
    $where_clauses[] = "t.id_admin_creador = ?";
    $params[] = $id_usuario_actual;
}

if (!empty($_GET['q'])) {
    $where_clauses[] = "t.nombre_tarea LIKE ?";
    $params[] = '%' . $_GET['q'] . '%';
}

if (!empty($_GET['estado'])) {
    $where_clauses[] = "t.estado = ?";
    $params[] = $_GET['estado'];
}

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(' AND ', $where_clauses);
}

$sql .= " GROUP BY t.id_tarea ORDER BY t.fecha_vencimiento ASC";
try { $stmt = $pdo->prepare($sql); $stmt->execute($params); $tareas = $stmt->fetchAll(); } 
catch(PDOException $e) { die("Error al recuperar las tareas: " . $e->getMessage()); }
include '../includes/header_admin.php';
?>
<?php if ($mensaje): ?><div class="alert alert-success"><?php echo e($mensaje); ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<div class="card">
    <form action="tareas.php" method="GET">
        <h3><i class="fas fa-filter"></i> Filtrar Tareas</h3>
        <div class="form-group" style="display:inline-block; width: 40%;"><label for="q">Buscar por nombre:</label><input type="text" name="q" id="q" value="<?php echo e($_GET['q'] ?? ''); ?>"></div>
        <div class="form-group" style="display:inline-block; width: 30%;"><label for="estado">Filtrar por estado:</label>
            <select name="estado" id="estado">
                <option value="">Todos</option>
                <option value="pendiente" <?php echo (($_GET['estado'] ?? '') == 'pendiente') ? 'selected' : ''; ?>>Pendiente</option>
                <option value="finalizada_usuario" <?php echo (($_GET['estado'] ?? '') == 'finalizada_usuario') ? 'selected' : ''; ?>>Finalizada por Usuario</option>
                <option value="completada" <?php echo (($_GET['estado'] ?? '') == 'completada') ? 'selected' : ''; ?>>Completada</option>
            </select>
        </div>
        <button type="submit" class="btn"><i class="fas fa-search"></i> Filtrar</button>
        <a href="tareas.php" class="btn btn-secondary"><i class="fas fa-eraser"></i> Limpiar</a>
    </form>
</div>
<form action="tareas.php" method="POST">
    <?php if ($rol_usuario_actual === 'admin'): ?>
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
                    <th>Fecha Vencimiento</th>
                    <th>Estado</th>
                    <th>Prioridad</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tareas)): ?>
                    <tr><td colspan="<?php echo ($rol_usuario_actual === 'admin') ? '9' : '8'; ?>" style="text-align:center;">No se encontraron tareas.</td></tr>
                <?php else: ?>
                    <?php foreach ($tareas as $tarea): ?>
                        <tr>
                            <?php if ($rol_usuario_actual === 'admin'): ?><td><input type="checkbox" name="ids_tareas[]" value="<?php echo $tarea['id_tarea']; ?>"></td><?php endif; ?>
                            <td><?php echo e($tarea['nombre_tarea']); ?></td>
                            <td><?php echo e($tarea['creador']); ?></td>
                            <td><?php echo e($tarea['miembros_asignados'] ?? 'N/A'); ?></td>
                            <td><?php echo e($tarea['negocio'] ?? 'N/A'); ?></td>
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
<?php include '../includes/footer_admin.php'; ?>