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

$mensaje = '';
$error = '';

// Lógica para finalizar la tarea
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finalizar_tarea'])) {
    $id_tarea = filter_input(INPUT_POST, 'id_tarea', FILTER_VALIDATE_INT);

    if ($id_tarea) {
        $pdo->beginTransaction();
        try {
            // 1. Actualizar el estado de la tarea
            $stmt_update = $pdo->prepare("UPDATE tareas SET estado = 'finalizada_usuario' WHERE id_tarea = ?");
            $stmt_update->execute([$id_tarea]);

            // 2. Insertar un comentario automático
            $comentario_sistema = 'La tarea ha sido marcada como finalizada por el analista.';
            $fecha_comentario = (new DateTime('now', new DateTimeZone('America/Bogota')))->format('Y-m-d H:i:s');
            $stmt_insert = $pdo->prepare("INSERT INTO comentarios_tarea (id_tarea, id_usuario, comentario, fecha_comentario) VALUES (?, ?, ?, ?)");
            $stmt_insert->execute([$id_tarea, $id_usuario_actual, $comentario_sistema, $fecha_comentario]);

            // 3. Notificar
            notificar_evento_tarea($id_tarea, 'miembro_finaliza', $id_usuario_actual);
            
            $pdo->commit();
            $mensaje = "¡Tarea finalizada! Se ha notificado al administrador.";

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error al finalizar la tarea: " . $e->getMessage();
        }
    } else {
        $error = "ID de tarea no válido.";
    }
}


// Consulta para obtener tareas asignadas a este analista (creadas por admin o por otro analista)
$sql = "
    SELECT t.*, u.nombre_completo as creador 
    FROM tareas t 
    JOIN usuarios u ON t.id_admin_creador = u.id_usuario
    WHERE t.id_tarea IN (
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

<?php if ($mensaje): ?><div class="alert alert-success"><?php echo e($mensaje); ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Nombre Tarea</th><th>Creador</th><th>Fecha Vencimiento</th><th>Estado</th><th>Prioridad</th><th>Acciones</th>
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
                                <?php if ($tarea['estado'] === 'pendiente'): ?>
                                    <form action="mis_tareas.php" method="POST" style="display:inline;" onsubmit="return confirm('¿Estás seguro de que quieres marcar esta tarea como finalizada?');">
                                        <input type="hidden" name="id_tarea" value="<?php echo $tarea['id_tarea']; ?>">
                                        <button type="submit" name="finalizar_tarea" class="btn btn-success"><i class="fas fa-check"></i> Finalizar</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../includes/footer_admin.php'; ?>
