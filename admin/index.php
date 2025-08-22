<?php $page_title = 'Dashboard'; ?>
<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/funciones.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_rol'], ['admin', 'analista'])) { header("Location: login.php"); exit(); }
$id_usuario_actual = $_SESSION['user_id'];
$rol_usuario_actual = $_SESSION['user_rol'];

try {
    if ($rol_usuario_actual === 'admin') {
        $stmt_finalizadas = $pdo->prepare("SELECT t.*, GROUP_CONCAT(u.nombre_completo SEPARATOR ', ') as miembros_asignados FROM tareas t LEFT JOIN tareas_asignadas ta ON t.id_tarea=ta.id_tarea LEFT JOIN usuarios u ON ta.id_usuario=u.id_usuario WHERE t.estado = 'finalizada_usuario' GROUP BY t.id_tarea ORDER BY t.fecha_vencimiento ASC");
        $stmt_finalizadas->execute();
        $tareas_finalizadas = $stmt_finalizadas->fetchAll();
        $stmt_pendientes = $pdo->prepare("SELECT t.*, GROUP_CONCAT(u.nombre_completo SEPARATOR ', ') as miembros_asignados FROM tareas t LEFT JOIN tareas_asignadas ta ON t.id_tarea=ta.id_tarea LEFT JOIN usuarios u ON ta.id_usuario=u.id_usuario WHERE t.estado = 'pendiente' GROUP BY t.id_tarea ORDER BY t.fecha_vencimiento ASC");
        $stmt_pendientes->execute();
        $tareas_pendientes = $stmt_pendientes->fetchAll();
        $stmt_completadas = $pdo->prepare("SELECT t.*, GROUP_CONCAT(u.nombre_completo SEPARATOR ', ') as miembros_asignados FROM tareas t LEFT JOIN tareas_asignadas ta ON t.id_tarea=ta.id_tarea LEFT JOIN usuarios u ON ta.id_usuario=u.id_usuario WHERE t.estado = 'completada' GROUP BY t.id_tarea ORDER BY t.fecha_vencimiento DESC LIMIT 5");
        $stmt_completadas->execute();
        $tareas_completadas = $stmt_completadas->fetchAll();
    } else {
        $stmt_finalizadas = $pdo->prepare("SELECT t.*, GROUP_CONCAT(u.nombre_completo SEPARATOR ', ') as miembros_asignados FROM tareas t LEFT JOIN tareas_asignadas ta ON t.id_tarea=ta.id_tarea LEFT JOIN usuarios u ON ta.id_usuario=u.id_usuario WHERE t.estado = 'finalizada_usuario' AND t.id_admin_creador = ? GROUP BY t.id_tarea ORDER BY t.fecha_vencimiento ASC");
        $stmt_finalizadas->execute([$id_usuario_actual]);
        $tareas_finalizadas = $stmt_finalizadas->fetchAll();
        $stmt_pendientes_analista = $pdo->prepare("SELECT t.*, GROUP_CONCAT(u.nombre_completo SEPARATOR ', ') as miembros_asignados FROM tareas t LEFT JOIN tareas_asignadas ta ON t.id_tarea=ta.id_tarea LEFT JOIN usuarios u ON ta.id_usuario=u.id_usuario WHERE t.estado = 'pendiente' AND t.id_admin_creador = ? GROUP BY t.id_tarea ORDER BY t.fecha_vencimiento ASC");
        $stmt_pendientes_analista->execute([$id_usuario_actual]);
        $tareas_pendientes = $stmt_pendientes_analista->fetchAll();
        $stmt_admin_tasks = $pdo->prepare("SELECT t.*, c.nombre_completo as creador, GROUP_CONCAT(u.nombre_completo SEPARATOR ', ') as miembros_asignados FROM tareas t JOIN usuarios c ON t.id_admin_creador = c.id_usuario LEFT JOIN tareas_asignadas ta ON t.id_tarea=ta.id_tarea LEFT JOIN usuarios u ON ta.id_usuario=u.id_usuario WHERE c.rol = 'admin' AND t.id_tarea IN (SELECT ta_sub.id_tarea FROM tareas_asignadas ta_sub WHERE ta_sub.id_usuario = ?) GROUP BY t.id_tarea ORDER BY t.fecha_vencimiento DESC LIMIT 10");
        $stmt_admin_tasks->execute([$id_usuario_actual]);
        $tareas_de_admin = $stmt_admin_tasks->fetchAll();

        $stmt_completadas_analista = $pdo->prepare("SELECT t.*, GROUP_CONCAT(u.nombre_completo SEPARATOR ', ') as miembros_asignados FROM tareas t LEFT JOIN tareas_asignadas ta ON t.id_tarea=ta.id_tarea LEFT JOIN usuarios u ON ta.id_usuario=u.id_usuario WHERE t.estado = 'completada' AND t.id_admin_creador = ? GROUP BY t.id_tarea ORDER BY t.fecha_vencimiento DESC LIMIT 5");
        $stmt_completadas_analista->execute([$id_usuario_actual]);
        $tareas_completadas_analista = $stmt_completadas_analista->fetchAll();
    }
} catch(PDOException $e) { die("Error al obtener datos del dashboard: " . $e->getMessage()); }

include '../includes/header_admin.php';
?>
<p>Bienvenido, <?php echo e($_SESSION['user_nombre']); ?> (<strong><?php echo e(ucfirst($rol_usuario_actual)); ?></strong>).</p>
<div class="dashboard-container">
    <?php if ($rol_usuario_actual === 'admin'): ?>
        <div class="analytics-grid" style="grid-template-columns: 1fr 1fr;">
            <div class="card">
                <h3><i class="fas fa-check-double" style="color: var(--warning-color);"></i> Tareas para Revisar</h3>
                <div class="table-wrapper">
                    <table><thead><tr><th>Tarea</th><th>Miembro(s)</th><th>Acción</th></tr></thead><tbody>
                    <?php if(empty($tareas_finalizadas)): ?><tr><td colspan="3">No hay tareas para revisar.</td></tr><?php else: foreach($tareas_finalizadas as $tarea): ?><tr><td><?php echo e($tarea['nombre_tarea']); ?></td><td><?php echo e($tarea['miembros_asignados']); ?></td><td class="actions"><a href="editar_tarea.php?id=<?php echo $tarea['id_tarea']; ?>" class="btn btn-warning btn-sm"><i class="fas fa-eye"></i> Revisar</a></td></tr><?php endforeach; endif; ?>
                    </tbody></table>
                </div>
            </div>
            <div class="card">
                <h3><i class="fas fa-hourglass-half" style="color: var(--secondary-color);"></i> Próximas Tareas Pendientes</h3>
                <div class="table-wrapper">
                    <table><thead><tr><th>Tarea</th><th>Estado</th><th>Prioridad</th></tr></thead><tbody>
                    <?php if(empty($tareas_pendientes)): ?><tr><td colspan="3">No hay tareas pendientes.</td></tr><?php else: foreach($tareas_pendientes as $tarea): ?><tr><td><a href="editar_tarea.php?id=<?php echo $tarea['id_tarea']; ?>"><?php echo e($tarea['nombre_tarea']); ?></a></td><td><?php $fecha_actual = new DateTime(); $fecha_vencimiento = new DateTime($tarea['fecha_vencimiento']); $intervalo = $fecha_actual->diff($fecha_vencimiento); $dias_restantes = (int)$intervalo->format('%r%a'); if ($dias_restantes < 0) { echo '<span class="texto-vencido"><i class="fas fa-exclamation-circle"></i> Vencido</span>'; } elseif ($dias_restantes == 0) { echo '<span class="texto-urgente"><i class="fas fa-bell"></i> Vence hoy</span>'; } else { echo '<span class="texto-normal"><i class="far fa-clock"></i> ' . $dias_restantes . ' día(s)</span>'; } ?></td><td><?php $prioridad_clase = e($tarea['prioridad']); $prioridad_texto = ucfirst($prioridad_clase); $prioridad_icono = 'fa-circle-info'; if ($prioridad_clase == 'alta') $prioridad_icono = 'fa-triangle-exclamation'; if ($prioridad_clase == 'media') $prioridad_icono = 'fa-circle-exclamation'; echo "<span class='icon-text icon-prioridad-{$prioridad_clase}'><i class='fas {$prioridad_icono}'></i> {$prioridad_texto}</span>"; ?></td></tr><?php endforeach; endif; ?>
                    </tbody></table>
                </div>
            </div>
            <div class="card" style="grid-column: 1 / -1;">
                <h3><i class="fas fa-history" style="color: var(--success-color);"></i> Últimas 5 Tareas Completadas</h3>
                <div class="table-wrapper">
                    <table><thead><tr><th>Tarea</th><th>Miembro(s) Asignado(s)</th><th>Fecha de Vencimiento</th></tr></thead><tbody>
                    <?php if(empty($tareas_completadas)): ?><tr><td colspan="3">Aún no hay tareas completadas.</td></tr><?php else: foreach($tareas_completadas as $tarea): ?><tr><td><a href="editar_tarea.php?id=<?php echo $tarea['id_tarea']; ?>"><?php echo e($tarea['nombre_tarea']); ?></a></td><td><?php echo e($tarea['miembros_asignados']); ?></td><td><?php echo date('d/m/Y', strtotime($tarea['fecha_vencimiento'])); ?></td></tr><?php endforeach; endif; ?>
                    </tbody></table>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
             <h3><i class="fas fa-check-double" style="color: var(--warning-color);"></i> Tareas Reportadas como Finalizadas</h3>
            <div class="table-wrapper"><table><thead><tr><th>Tarea</th><th>Miembro(s)</th><th>Vencimiento</th><th>Acción</th></tr></thead><tbody>
            <?php if(empty($tareas_finalizadas)): ?><tr><td colspan="4">No hay tareas para revisar.</td></tr><?php else: foreach($tareas_finalizadas as $tarea): ?><tr><td><?php echo e($tarea['nombre_tarea']); ?></td><td><?php echo e($tarea['miembros_asignados']); ?></td><td><?php echo date('d/m/Y', strtotime($tarea['fecha_vencimiento'])); ?></td><td class="actions"><a href="editar_tarea.php?id=<?php echo $tarea['id_tarea']; ?>" class="btn btn-warning"><i class="fas fa-eye"></i> Revisar</a></td></tr><?php endforeach; endif; ?>
            </tbody></table></div>
        </div>
        <div class="card" style="margin-top: 2rem;">
            <h3><i class="fas fa-user-clock"></i> Tareas Pendientes Creadas por Mí</h3>
            <div class="table-wrapper"><table><thead><tr><th>Tarea</th><th>Miembro(s)</th><th>Vencimiento</th><th>Estado</th></tr></thead><tbody>
            <?php if(empty($tareas_pendientes)): ?><tr><td colspan="4">No hay tareas pendientes.</td></tr><?php else: foreach($tareas_pendientes as $tarea): ?><tr><td><a href="editar_tarea.php?id=<?php echo $tarea['id_tarea']; ?>"><?php echo e($tarea['nombre_tarea']); ?></a></td><td><?php echo e($tarea['miembros_asignados']); ?></td><td><?php echo date('d/m/Y', strtotime($tarea['fecha_vencimiento'])); ?></td><td><?php $fecha_actual = new DateTime(); $fecha_vencimiento = new DateTime($tarea['fecha_vencimiento']); $intervalo = $fecha_actual->diff($fecha_vencimiento); $dias_restantes = (int)$intervalo->format('%r%a'); if ($dias_restantes < 0) { echo '<span class="texto-vencido"><i class="fas fa-exclamation-circle"></i> Vencido</span>'; } elseif ($dias_restantes == 0) { echo '<span class="texto-urgente"><i class="fas fa-bell"></i> Vence hoy</span>'; } else { echo '<span class="texto-normal"><i class="far fa-clock"></i> ' . $dias_restantes . ' día(s)</span>'; } ?></td></tr><?php endforeach; endif; ?>
            </tbody></table></div>
        </div>
        <?php if (isset($tareas_de_admin)): ?>
        <div class="card" style="margin-top: 2rem;">
            <h3><i class="fas fa-user-shield"></i> Tareas Asignadas por Administrador</h3>
            <div class="table-wrapper"><table><thead><tr><th>Tarea</th><th>Creador</th><th>Miembros Asignados</th><th>Vencimiento</th></tr></thead><tbody>
            <?php if(empty($tareas_de_admin)): ?><tr><td colspan="4">No tienes tareas asignadas por un administrador.</td></tr><?php else: foreach($tareas_de_admin as $tarea): ?><tr><td><a href="editar_tarea.php?id=<?php echo $tarea['id_tarea']; ?>"><?php echo e($tarea['nombre_tarea']); ?></a></td><td><?php echo e($tarea['creador']); ?></td><td><?php echo e($tarea['miembros_asignados']); ?></td><td><?php echo date('d/m/Y', strtotime($tarea['fecha_vencimiento'])); ?></td></tr><?php endforeach; endif; ?>
            </tbody></table></div>
        </div>
        <?php endif; ?>
        <div class="card" style="margin-top: 2rem;">
            <h3><i class="fas fa-history" style="color: var(--success-color);"></i> Últimas 5 Tareas Completadas</h3>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>Tarea</th><th>Miembro(s) Asignado(s)</th><th>Fecha de Vencimiento</th></tr></thead>
                    <tbody>
                    <?php if(empty($tareas_completadas_analista)): ?>
                        <tr><td colspan="3">Aún no hay tareas completadas.</td></tr>
                    <?php else: ?>
                        <?php foreach($tareas_completadas_analista as $tarea): ?>
                            <tr>
                                <td><a href="editar_tarea.php?id=<?php echo $tarea['id_tarea']; ?>"><?php echo e($tarea['nombre_tarea']); ?></a></td>
                                <td><?php echo e($tarea['miembros_asignados']); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($tarea['fecha_vencimiento'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php include '../includes/footer_admin.php'; ?>