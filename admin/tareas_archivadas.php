<?php
$page_title = 'Tareas Archivadas';
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/funciones.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids_tareas = isset($_POST['ids_tareas']) ? $_POST['ids_tareas'] : [];

    if (!empty($ids_tareas)) {
        $placeholders = implode(',', array_fill(0, count($ids_tareas), '?'));

        if (isset($_POST['desarchivar_seleccionados'])) {
            try {
                $stmt = $pdo->prepare("UPDATE tareas SET archivada = 0 WHERE id_tarea IN ($placeholders)");
                $stmt->execute($ids_tareas);
                $mensaje = "Tareas seleccionadas desarchivadas.";
            } catch (PDOException $e) {
                $error = "Error al desarchivar las tareas.";
            }
        } elseif (isset($_POST['eliminar_seleccionados'])) {
            try {
                $stmt = $pdo->prepare("DELETE FROM tareas WHERE id_tarea IN ($placeholders)");
                $stmt->execute($ids_tareas);
                $mensaje = "Tareas seleccionadas eliminadas permanentemente.";
            } catch (PDOException $e) {
                $error = "Error al eliminar las tareas.";
            }
        }
    } else {
        $error = "No se seleccionó ninguna tarea.";
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
    WHERE
        t.archivada = 1
    GROUP BY
        t.id_tarea
    ORDER BY
        t.fecha_vencimiento DESC
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $tareas = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Error al recuperar las tareas archivadas: " . $e->getMessage());
}

include '../includes/header_admin.php';
?>

<?php if ($mensaje): ?><div class="alert alert-success"><?php echo e($mensaje); ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

<form action="tareas_archivadas.php" method="POST">
    <button type="submit" name="desarchivar_seleccionados" class="btn btn-primary" onclick="return confirm('¿Estás seguro de que deseas desarchivar las tareas seleccionadas?');" style="margin-top: 20px;"><i class="fas fa-box-open"></i> Desarchivar Seleccionados</button>
    <button type="submit" name="eliminar_seleccionados" class="btn btn-danger" onclick="return confirm('¿Estás seguro de que deseas eliminar permanentemente estas tareas? Esta acción no se puede deshacer.');" style="margin-top: 20px; margin-left: 10px;"><i class="fas fa-trash-can"></i> Eliminar Permanentemente</button>
    <div class="table-wrapper">
        <table class="tabla-tareas">
            <thead>
                <tr>
                    <th><input type="checkbox" id="selectAll"></th>
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
            <tbody>
                <?php if (empty($tareas)): ?>
                    <tr><td colspan="9" style="text-align:center;">No hay tareas archivadas.</td></tr>
                <?php else: ?>
                    <?php foreach ($tareas as $tarea): ?>
                        <tr>
                            <td><input type="checkbox" name="ids_tareas[]" value="<?php echo $tarea['id_tarea']; ?>"></td>
                            <td><?php echo e($tarea['nombre_tarea']); ?></td>
                            <td><?php echo e($tarea['creador']); ?></td>
                            <td><?php echo e($tarea['miembros_asignados'] ?? 'N/A'); ?></td>
                            <td><?php echo e($tarea['negocio'] ?? 'N/A'); ?></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($tarea['fecha_creacion'])); ?></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($tarea['fecha_vencimiento'])); ?></td>
                            <td><?php echo mostrar_estado_tarea($tarea); ?></td>
                            <td>
                                <?php
                                $prioridad_clase = e($tarea['prioridad']);
                                $prioridad_texto = ucfirst($prioridad_clase);
                                $prioridad_icono = 'fa-circle-info';
                                if ($prioridad_clase == 'alta') $prioridad_icono = 'fa-triangle-exclamation';
                                if ($prioridad_clase == 'media') $prioridad_icono = 'fa-circle-exclamation';
                                echo "<span class='icon-text icon-prioridad-{$prioridad_clase}'><i class='fas {$prioridad_icono}'></i> {$prioridad_texto}</span>";
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</form>

<?php include '../includes/footer_admin.php'; ?>
