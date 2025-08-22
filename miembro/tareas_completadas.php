<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/funciones.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'miembro') {
    header("Location: " . BASE_URL . "/miembro/login.php");
    exit();
}

$id_miembro = $_SESSION['user_id'];

try {
    // Se elimina la lógica de búsqueda del servidor. Ahora se hace en el cliente con JS.
    $sql = "
        SELECT t.*, u.nombre_completo as nombre_creador 
        FROM tareas t 
        JOIN tareas_asignadas ta ON t.id_tarea = ta.id_tarea 
        JOIN usuarios u ON t.id_admin_creador = u.id_usuario 
        WHERE ta.id_usuario = :id_miembro AND t.estado = 'completada'
        ORDER BY t.fecha_vencimiento DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id_miembro' => $id_miembro]);
    $tareas = $stmt->fetchAll();

} catch(PDOException $e) {
    die("Error al recuperar tus tareas completadas: " . $e->getMessage());
}

include '../includes/header_miembro.php';
?>

<h2>Mis Tareas Completadas</h2>
<p>Hola, <?php echo e($_SESSION['user_nombre']); ?>. Aquí puedes ver tus tareas que han sido completadas y cerradas.</p>

<div class="card" style="margin-bottom: 20px; padding: 15px;">
    <div class="form-group" style="margin: 0;">
        <input type="text" id="liveSearchInput" placeholder="Escribe para filtrar por tarea, negocio o creador..." style="width: 100%;">
    </div>
</div>

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Nombre Tarea</th>
                    <th>Creado por</th>
                    <th>Nº Piezas</th>
                    <th>Negocio</th>
                    <th>Fecha Vencimiento</th>
                    <th>Prioridad</th>
                    <th>Estado</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($tareas)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center;">No tienes ninguna tarea completada por el momento.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach($tareas as $tarea): ?>
                        <tr>
                            <td><?php echo e($tarea['nombre_tarea']); ?></td>
                            <td><?php echo e($tarea['nombre_creador']); ?></td>
                            <td><?php echo e($tarea['numero_piezas']); ?></td>
                            <td><?php echo e($tarea['negocio']); ?></td>
                            <td>
                                <span class="icon-text"><i class="fas fa-calendar-day"></i> <?php echo date('d/m/Y H:i', strtotime($tarea['fecha_vencimiento'])); ?></span>
                            </td>
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
                            <td>
                                <?php echo mostrar_estado_tarea($tarea); ?>
                            </td>
                            <td>
                                <a href="tarea.php?id=<?php echo $tarea['id_tarea']; ?>" class="btn btn-primary btn-sm">
                                    <i class="fas fa-eye"></i> Ver
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../includes/footer_miembro.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('liveSearchInput');
    const taskRows = document.querySelectorAll('.table-wrapper tbody tr');

    if (searchInput && taskRows.length > 0) {
        const noTasksRow = document.querySelector('.table-wrapper tbody tr td[colspan="8"]');

        searchInput.addEventListener('keyup', function() {
            const searchTerm = searchInput.value.toLowerCase().trim();
            let visibleCount = 0;

            taskRows.forEach(row => {
                if (row.contains(noTasksRow)) {
                    return; 
                }

                const taskName = row.cells[0].textContent.toLowerCase();
                const creatorName = row.cells[1].textContent.toLowerCase();
                const businessName = row.cells[3].textContent.toLowerCase();
                const searchableText = `${taskName} ${creatorName} ${businessName}`;

                if (searchableText.includes(searchTerm)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            if (noTasksRow) {
                if (visibleCount === 0 && searchTerm !== '') {
                    noTasksRow.parentElement.style.display = '';
                    noTasksRow.textContent = 'No se encontraron tareas que coincidan con la búsqueda.';
                } else if (visibleCount > 0) {
                     noTasksRow.parentElement.style.display = 'none';
                } else if (searchTerm === '') {
                    noTasksRow.textContent = 'No tienes ninguna tarea completada por el momento.';
                    if(taskRows.length > 1) {
                        noTasksRow.parentElement.style.display = 'none';
                    } else {
                        noTasksRow.parentElement.style.display = '';
                    }
                }
            }
        });
    }
});
</script>
