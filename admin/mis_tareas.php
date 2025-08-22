<?php $page_title = 'Mis Tareas Asignadas'; ?>
<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/funciones.php';

// Proteger página - SOLO ANALISTAS
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'analista') {
    header("Location: index.php"); 
    exit();
}
$id_usuario_actual = $_SESSION['user_id'];

// Consulta para obtener tareas creadas por un admin y asignadas a este analista
$sql = "
    SELECT t.*, u.nombre_completo as creador 
    FROM tareas t 
    JOIN usuarios u ON t.id_admin_creador = u.id_usuario
    WHERE u.rol = 'admin' AND t.id_tarea IN (
        SELECT ta.id_tarea FROM tareas_asignadas ta WHERE ta.id_usuario = ?
    )
";
$params = [$id_usuario_actual];

// (Aquí se podría añadir lógica de filtros si se desea en el futuro)

$sql .= " ORDER BY t.fecha_vencimiento ASC";
try { 
    $stmt = $pdo->prepare($sql); 
    $stmt->execute($params); 
    $tareas = $stmt->fetchAll(); 
} 
catch(PDOException $e) { die("Error al recuperar las tareas: " . $e->getMessage()); }

include '../includes/header_admin.php';
?>

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Nombre Tarea</th><th>Creador (Admin)</th><th>Fecha Vencimiento</th><th>Estado</th><th>Prioridad</th><th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tareas)): ?>
                    <tr><td colspan="6" style="text-align:center;">No tienes tareas asignadas por un administrador.</td></tr>
                <?php else: ?>
                    <?php foreach ($tareas as $tarea): ?>
                        <tr>
                            <td><?php echo e($tarea['nombre_tarea']); ?></td>
                            <td><?php echo e($tarea['creador']); ?></td>
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
                            <td class="actions">
                                <a href="editar_tarea.php?id=<?php echo $tarea['id_tarea']; ?>" class="btn btn-warning"><i class="fas fa-pencil-alt"></i> Ver/Editar</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../includes/footer_admin.php'; ?>