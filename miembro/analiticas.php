<?php $page_title = 'Mi Informe de Tareas'; ?>
<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/funciones.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'miembro') {
    header("Location: " . BASE_URL . "/miembro/login.php");
    exit();
}

$id_miembro_filtro = $_SESSION['user_id'];
$fecha_inicio = $_GET['fecha_inicio'] ?? null;
$fecha_fin = $_GET['fecha_fin'] ?? null;

$listados = [];
$error = '';
$datos_encontrados = false;

if ($fecha_inicio && $fecha_fin) {
    try {
        $fecha_fin_sql = $fecha_fin . ' 23:59:59';
        
        $query_tareas = "
            SELECT
                t.nombre_tarea,
                uc.nombre_completo AS creador,
                t.fecha_creacion,
                t.fecha_vencimiento,
                t.numero_piezas,
                t.negocio,
                (SELECT MAX(fecha_comentario) FROM comentarios_tarea WHERE id_tarea = t.id_tarea AND comentario LIKE 'He Finalizado esta Tarea') AS fecha_finalizada_usuario,
                (CASE
                    WHEN t.estado = 'completada' AND (SELECT MAX(fecha_comentario) FROM comentarios_tarea WHERE id_tarea = t.id_tarea AND comentario LIKE 'He Finalizado esta Tarea') <= t.fecha_vencimiento THEN 'A tiempo'
                    WHEN t.estado = 'completada' AND (SELECT MAX(fecha_comentario) FROM comentarios_tarea WHERE id_tarea = t.id_tarea AND comentario LIKE 'He Finalizado esta Tarea') > t.fecha_vencimiento THEN 'Con retraso'
                    ELSE 'Pendiente'
                END) AS cumplimiento
            FROM tareas t
            JOIN usuarios uc ON t.id_admin_creador = uc.id_usuario
            JOIN tareas_asignadas ta ON t.id_tarea = ta.id_tarea
            WHERE t.fecha_creacion BETWEEN ? AND ? AND ta.id_usuario = ?
            ORDER BY t.fecha_creacion DESC
        ";
        
        $stmt_tareas = $pdo->prepare($query_tareas);
        $stmt_tareas->execute([$fecha_inicio, $fecha_fin_sql, $id_miembro_filtro]);
        $listados['informe_tareas'] = $stmt_tareas->fetchAll();

        if (!empty($listados['informe_tareas'])) {
            $datos_encontrados = true;
            $summary = [
                'completadas' => 0,
                'pendientes' => 0,
                'vencidas' => 0,
                'total_piezas' => 0,
                'por_negocio' => [],
            ];
            foreach ($listados['informe_tareas'] as $tarea) {
                if ($tarea['cumplimiento'] === 'A tiempo' || $tarea['cumplimiento'] === 'Con retraso') {
                    $summary['completadas']++;
                } else {
                    $summary['pendientes']++;
                    if (strtotime($tarea['fecha_vencimiento']) < time()) {
                        $summary['vencidas']++;
                    }
                }
                $summary['total_piezas'] += $tarea['numero_piezas'];
                if (!empty($tarea['negocio'])) {
                    if (!isset($summary['por_negocio'][$tarea['negocio']])) {
                        $summary['por_negocio'][$tarea['negocio']] = 0;
                    }
                    $summary['por_negocio'][$tarea['negocio']]++;
                }
            }
        }
    } catch(PDOException $e) {
        $error = "Error al generar el informe: " . $e->getMessage();
    }
}

include '../includes/header_miembro.php';
?>
<?php if (isset($error) && !empty($error)): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<div class="card">
    <form action="analiticas.php" method="GET">
        <h3><i class="fas fa-filter"></i> Generar Informe de Tareas</h3>
        <div style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end;">
            <div class="form-group" style="flex: 1 1 150px;">
                <label for="fecha_inicio">Desde:</label>
                <input type="date" name="fecha_inicio" id="fecha_inicio" value="<?php echo e($fecha_inicio); ?>" required>
            </div>
            <div class="form-group" style="flex: 1 1 150px;">
                <label for="fecha_fin">Hasta:</label>
                <input type="date" name="fecha_fin" id="fecha_fin" value="<?php echo e($fecha_fin); ?>" required>
            </div>
            <button type="submit" class="btn"><i class="fas fa-search"></i> Generar</button>
            <a href="analiticas.php" class="btn btn-secondary"><i class="fas fa-eraser"></i> Limpiar</a>
        </div>
    </form>
    <?php if ($datos_encontrados): ?>
        <div style="border-top: 1px solid #eee; margin-top: 20px; padding-top: 20px; text-align: right;">
            <a href="generar_informe_tareas_pdf.php?fecha_inicio=<?php echo e($fecha_inicio); ?>&fecha_fin=<?php echo e($fecha_fin); ?>" class="btn btn-success" target="_blank"><i class="fas fa-file-pdf"></i> Descargar Informe en PDF</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($datos_encontrados): ?>
    <div class="card" style="margin-top: 20px;">
        <h3><i class="fas fa-tasks"></i> Informe de Tareas</h3>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Creador</th>
                        <th>Nombre Tarea</th>
                        <th>No Piezas</th>
                        <th>Negocio</th>
                        <th>Fecha Creación</th>
                        <th>Fecha Vencimiento</th>
                        <th>Fecha Finalización</th>
                        <th>Cumplimiento</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($listados['informe_tareas'] as $tarea): ?>
                        <tr>
                            <td><?php echo e($tarea['creador']); ?></td>
                            <td><?php echo e($tarea['nombre_tarea']); ?></td>
                            <td><?php echo e($tarea['numero_piezas']); ?></td>
                            <td><?php echo e($tarea['negocio']); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($tarea['fecha_creacion'])); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($tarea['fecha_vencimiento'])); ?></td>
                            <td><?php echo $tarea['fecha_finalizada_usuario'] ? date('d/m/Y', strtotime($tarea['fecha_finalizada_usuario'])) : 'N/A'; ?></td>
                            <td><?php echo e($tarea['cumplimiento']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="summary" style="margin-top: 20px; text-align: right;">
            <strong>Total Tareas Completadas:</strong> <?php echo $summary['completadas']; ?><br>
            <strong>Total Tareas Pendientes:</strong> <?php echo $summary['pendientes']; ?><br>
            <strong>Total Tareas Vencidas:</strong> <?php echo $summary['vencidas']; ?><br>
            <hr>
            <strong>Total de Piezas:</strong> <?php echo $summary['total_piezas']; ?><br>
            <strong>Tareas por Negocio:</strong><br>
            <?php if (empty($summary['por_negocio'])): ?>
                <span>No hay datos de negocio.</span>
            <?php else: ?>
                <?php foreach ($summary['por_negocio'] as $negocio => $cantidad): ?>
                    - <?php echo e($negocio); ?>: <?php echo $cantidad; ?><br>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
<?php elseif ($fecha_inicio && $fecha_fin): ?>
    <div class="alert alert-info" style="margin-top:20px;">No se encontraron datos para el rango de fechas seleccionados.</div>
<?php endif; ?>

<?php include '../includes/footer_miembro.php'; ?>
