<?php $page_title = 'Analíticas del Equipo'; ?>
<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/funciones.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_rol'], ['admin', 'analista', 'miembro'])) {
    header("Location: " . BASE_URL . "/admin/login.php");
    exit();
}

$tipo_informe = $_GET['tipo_informe'] ?? 'individual';
$id_miembro_filtro = $_GET['id_miembro'] ?? null;
$fecha_inicio = $_GET['fecha_inicio'] ?? null;
$fecha_fin = $_GET['fecha_fin'] ?? null;

if ($_SESSION['user_rol'] === 'miembro' || $_SESSION['user_rol'] === 'analista') {
    $tipo_informe = 'informe_tareas';
}

if ($_SESSION['user_rol'] === 'miembro' || $_SESSION['user_rol'] === 'analista') {
    $id_miembro_filtro = $_SESSION['user_id'];
}

$miembros = $pdo->query("SELECT id_usuario, nombre_completo FROM usuarios WHERE rol != 'admin' ORDER BY nombre_completo ASC")->fetchAll();
$datos_graficos = [];
$listados = [];
$error = '';
$datos_encontrados = false;

if ($fecha_inicio && $fecha_fin) {
    try {
        $fecha_fin_sql = $fecha_fin . ' 23:59:59';

        if ($tipo_informe === 'individual' && $id_miembro_filtro) {
            // LÓGICA PARA INFORME INDIVIDUAL (SIN CAMBIOS)
            $stmt_pie = $pdo->prepare("SELECT estado, COUNT(*) as total FROM tareas t JOIN tareas_asignadas ta ON t.id_tarea = ta.id_tarea WHERE ta.id_usuario = ? AND t.fecha_creacion BETWEEN ? AND ? GROUP BY estado");
            $stmt_pie->execute([$id_miembro_filtro, $fecha_inicio, $fecha_fin_sql]);
            $data_pie = $stmt_pie->fetchAll();
            $datos_graficos['individual_pie'] = ['labels' => array_column($data_pie, 'estado'), 'data' => array_column($data_pie, 'total')];

            $stmt_bar = $pdo->prepare("SELECT DATE_FORMAT(fecha_vencimiento, '%Y-%m') as mes, COUNT(*) as total FROM tareas t JOIN tareas_asignadas ta ON t.id_tarea = ta.id_tarea WHERE ta.id_usuario = ? AND t.fecha_creacion BETWEEN ? AND ? GROUP BY mes ORDER BY mes ASC");
            $stmt_bar->execute([$id_miembro_filtro, $fecha_inicio, $fecha_fin_sql]);
            $data_bar = $stmt_bar->fetchAll();
            $datos_graficos['individual_bar'] = ['labels' => array_column($data_bar, 'mes'), 'data' => array_column($data_bar, 'total')];
            
            $stmt_completadas = $pdo->prepare("SELECT nombre_tarea FROM tareas t JOIN tareas_asignadas ta ON t.id_tarea = ta.id_tarea WHERE ta.id_usuario = ? AND t.estado = 'completada' AND t.fecha_creacion BETWEEN ? AND ?");
            $stmt_completadas->execute([$id_miembro_filtro, $fecha_inicio, $fecha_fin_sql]);
            $listados['completadas'] = $stmt_completadas->fetchAll();
            
            $stmt_vencidas = $pdo->prepare("SELECT nombre_tarea, fecha_vencimiento FROM tareas t JOIN tareas_asignadas ta ON t.id_tarea = ta.id_tarea WHERE ta.id_usuario = ? AND t.estado != 'completada' AND t.fecha_vencimiento < NOW() AND t.fecha_creacion BETWEEN ? AND ?");
            $stmt_vencidas->execute([$id_miembro_filtro, $fecha_inicio, $fecha_fin_sql]);
            $listados['vencidas'] = $stmt_vencidas->fetchAll();
            
            if(!empty($data_pie) || !empty($data_bar)) $datos_encontrados = true;

        } elseif ($tipo_informe === 'equipo') {
            // --- INICIO DE LA MODIFICACIÓN: CONSULTAS CORREGIDAS PARA INCLUIR ANALISTAS ---
            $roles_incluidos = ['miembro', 'analista'];

            $placeholders = implode(',', array_fill(0, count($roles_incluidos), '?'));

            $stmt_bar = $pdo->prepare("SELECT u.nombre_completo, COUNT(t.id_tarea) as total FROM tareas t JOIN tareas_asignadas ta ON t.id_tarea = ta.id_tarea JOIN usuarios u ON ta.id_usuario = u.id_usuario WHERE t.estado = 'completada' AND t.fecha_creacion BETWEEN ? AND ? AND u.rol IN ($placeholders) GROUP BY u.id_usuario ORDER BY total DESC");
            $stmt_bar->execute(array_merge([$fecha_inicio, $fecha_fin_sql], $roles_incluidos));
            $data_bar = $stmt_bar->fetchAll();
            $datos_graficos['equipo_bar'] = ['labels' => array_column($data_bar, 'nombre_completo'), 'data' => array_column($data_bar, 'total')];

            $stmt_pie = $pdo->prepare("SELECT u.nombre_completo, COUNT(t.id_tarea) as total FROM tareas t JOIN tareas_asignadas ta ON t.id_tarea = ta.id_tarea JOIN usuarios u ON ta.id_usuario = u.id_usuario WHERE t.fecha_creacion BETWEEN ? AND ? AND u.rol IN ($placeholders) GROUP BY u.id_usuario ORDER BY total DESC");
            $stmt_pie->execute(array_merge([$fecha_inicio, $fecha_fin_sql], $roles_incluidos));
            $data_pie = $stmt_pie->fetchAll();
            $datos_graficos['equipo_pie'] = ['labels' => array_column($data_pie, 'nombre_completo'), 'data' => array_column($data_pie, 'total')];

            $stmt_vencidas = $pdo->prepare("SELECT u.nombre_completo, COUNT(t.id_tarea) as total FROM tareas t JOIN tareas_asignadas ta ON t.id_tarea = ta.id_tarea JOIN usuarios u ON ta.id_usuario = u.id_usuario WHERE t.estado != 'completada' AND t.fecha_vencimiento < NOW() AND t.fecha_creacion BETWEEN ? AND ? AND u.rol IN ($placeholders) GROUP BY u.id_usuario ORDER BY total DESC");
            $stmt_vencidas->execute(array_merge([$fecha_inicio, $fecha_fin_sql], $roles_incluidos));
            $listados['vencidas'] = $stmt_vencidas->fetchAll();
            // --- FIN DE LA MODIFICACIÓN ---
            
            if(!empty($data_bar) || !empty($data_pie)) $datos_encontrados = true;
        
        } elseif ($tipo_informe === 'informe_tareas') {
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
                LEFT JOIN tareas_asignadas ta ON t.id_tarea = ta.id_tarea
            ";
            
            $params_tareas = [$fecha_inicio, $fecha_fin_sql];
            $where_tareas = " WHERE t.fecha_creacion BETWEEN ? AND ?";

            if ($id_miembro_filtro) {
                $where_tareas .= " AND ta.id_usuario = ?";
                $params_tareas[] = $id_miembro_filtro;
            }

            $query_tareas .= $where_tareas . " ORDER BY t.fecha_creacion DESC";
            $stmt_tareas = $pdo->prepare($query_tareas);
            $stmt_tareas->execute($params_tareas);
            $listados['informe_tareas'] = $stmt_tareas->fetchAll();

            if (!empty($listados['informe_tareas'])) {
                $datos_encontrados = true;
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
        } elseif ($tipo_informe === 'piezas_miembro') {
            $stmt = $pdo->prepare("
                SELECT u.nombre_completo, SUM(t.numero_piezas) as total_piezas
                FROM tareas t
                JOIN tareas_asignadas ta ON t.id_tarea = ta.id_tarea
                JOIN usuarios u ON ta.id_usuario = u.id_usuario
                WHERE t.fecha_creacion BETWEEN ? AND ?
                GROUP BY u.id_usuario
                ORDER BY total_piezas DESC
            ");
            $stmt->execute([$fecha_inicio, $fecha_fin_sql]);
            $data = $stmt->fetchAll();
            $datos_graficos['piezas_miembro_bar'] = ['labels' => array_column($data, 'nombre_completo'), 'data' => array_column($data, 'total_piezas')];
            if(!empty($data)) $datos_encontrados = true;

        } elseif ($tipo_informe === 'requerimientos_negocio') {
            $stmt = $pdo->prepare("
                SELECT negocio, COUNT(id_tarea) as total_tareas
                FROM tareas
                WHERE fecha_creacion BETWEEN ? AND ? AND negocio IS NOT NULL AND negocio != ''
                GROUP BY negocio
                ORDER BY total_tareas DESC
            ");
            $stmt->execute([$fecha_inicio, $fecha_fin_sql]);
            $data = $stmt->fetchAll();
            $datos_graficos['requerimientos_negocio_pie'] = ['labels' => array_column($data, 'negocio'), 'data' => array_column($data, 'total_tareas')];
            if(!empty($data)) $datos_encontrados = true;
        }
    } catch(PDOException $e) { $error = "Error al generar las analíticas: " . $e->getMessage(); }
}

include '../includes/header_admin.php';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/chartjs-plugin-datalabels/2.2.0/chartjs-plugin-datalabels.min.js"></script>
<?php if (isset($error) && !empty($error)): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<div class="card">
    <form action="analiticas.php" method="GET">
        <h3><i class="fas fa-filter"></i> Filtrar Datos</h3>
        <div style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end;">
            <?php if ($_SESSION['user_rol'] === 'admin'): ?>
            <div class="form-group" style="flex: 1 1 200px;">
                <label for="tipo_informe">Tipo de Informe:</label>
                <select name="tipo_informe" id="tipo_informe">
                    <option value="individual" <?php if($tipo_informe == 'individual') echo 'selected'; ?>>Rendimiento Individual</option>
                    <option value="equipo" <?php if($tipo_informe == 'equipo') echo 'selected'; ?>>Comparativa de Equipo</option>
                    <option value="informe_tareas" <?php if ($tipo_informe == 'informe_tareas') echo 'selected'; ?>>Informe de Tareas</option>
                    <option value="piezas_miembro" <?php if ($tipo_informe == 'piezas_miembro') echo 'selected'; ?>>Número de piezas por miembro</option>
                    <option value="requerimientos_negocio" <?php if ($tipo_informe == 'requerimientos_negocio') echo 'selected'; ?>>Requerimientos por negocio</option>
                    <option value="historico_general" <?php if ($tipo_informe == 'historico_general') echo 'selected'; ?>>Histórico General de Tareas</option>
                </select>
            </div>
            <?php endif; ?>
            
            <?php if ($_SESSION['user_rol'] === 'admin'): ?>
            <div class="form-group" style="flex: 1 1 200px;" id="miembro_selector_group">
                <label for="id_miembro">Seleccionar Usuario:</label>
                <select name="id_miembro" id="id_miembro">
                    <option value="">-- Todos --</option>
                    <?php foreach ($miembros as $miembro): ?>
                        <option value="<?php echo $miembro['id_usuario']; ?>" <?php echo ($id_miembro_filtro == $miembro['id_usuario']) ? 'selected' : ''; ?>>
                            <?php echo e($miembro['nombre_completo']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="form-group" style="flex: 1 1 150px;"><label for="fecha_inicio">Desde:</label><input type="date" name="fecha_inicio" id="fecha_inicio" value="<?php echo e($fecha_inicio); ?>" required></div>
            <div class="form-group" style="flex: 1 1 150px;"><label for="fecha_fin">Hasta:</label><input type="date" name="fecha_fin" id="fecha_fin" value="<?php echo e($fecha_fin); ?>" required></div>
            <button type="submit" class="btn"><i class="fas fa-search"></i> Generar</button>
            <a href="analiticas.php" class="btn btn-secondary"><i class="fas fa-eraser"></i> Limpiar</a>
        </div>
    </form>
    <?php if ($datos_encontrados && $tipo_informe === 'individual'): ?>
        <div style="border-top: 1px solid #eee; margin-top: 20px; padding-top: 20px; text-align: right;">
            <form id="pdfForm" action="generar_informe_pdf.php" method="POST" target="_blank" style="display: inline;">
                <input type="hidden" name="id_miembro" value="<?php echo e($id_miembro_filtro); ?>">
                <input type="hidden" name="fecha_inicio" value="<?php echo e($fecha_inicio); ?>">
                <input type="hidden" name="fecha_fin" value="<?php echo e($fecha_fin); ?>">
                <input type="hidden" name="pieChartImage" id="pieChartImage">
                <input type="hidden" name="barChartImage" id="barChartImage">
                <button type="submit" class="btn btn-success"><i class="fas fa-file-pdf"></i> Descargar Informe en PDF</button>
            </form>
        </div>
    <?php elseif ($datos_encontrados && $tipo_informe === 'informe_tareas'): ?>
        <div style="border-top: 1px solid #eee; margin-top: 20px; padding-top: 20px; text-align: right;">
            <a href="generar_informe_tareas_pdf.php?id_miembro=<?php echo e($id_miembro_filtro); ?>&fecha_inicio=<?php echo e($fecha_inicio); ?>&fecha_fin=<?php echo e($fecha_fin); ?>" class="btn btn-success" target="_blank"><i class="fas fa-file-pdf"></i> Descargar Informe en PDF</a>
        </div>
    <?php elseif ($tipo_informe === 'historico_general' && $fecha_inicio && $fecha_fin): ?>
        <div style="border-top: 1px solid #eee; margin-top: 20px; padding-top: 20px; text-align: right;">
            <a href="generar_historico_general_pdf.php?fecha_inicio=<?php echo e($fecha_inicio); ?>&fecha_fin=<?php echo e($fecha_fin); ?>" class="btn btn-success" target="_blank"><i class="fas fa-file-pdf"></i> Descargar Histórico en PDF</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($datos_encontrados): ?>
    <?php if ($tipo_informe === 'individual'): ?>
        <div class="analytics-grid" style="margin-top:20px;">
            <div class="chart-container"><h4><i class="fas fa-pie-chart"></i> Distribución por Estado</h4><canvas id="pieChart"></canvas></div>
            <div class="chart-container"><h4><i class="fas fa-chart-bar"></i> Tareas Gestionadas por Mes</h4><canvas id="barChart"></canvas></div>
            <div class="chart-container"><h4><i class="fas fa-check-double" style="color:var(--success-color)"></i> Tareas Completadas</h4><ul class="analytics-list"><?php if(empty($listados['completadas'])) echo "<li>No hay tareas completadas.</li>"; foreach($listados['completadas'] as $t) echo '<li><i class="fas fa-check"></i> '.e($t['nombre_tarea']).'</li>'; ?></ul></div>
            <div class="chart-container"><h4><i class="fas fa-exclamation-triangle" style="color:var(--danger-color)"></i> Tareas Vencidas</h4><ul class="analytics-list"><?php if(empty($listados['vencidas'])): ?><li><i class="fas fa-thumbs-up"></i> ¡Excelente! No hay tareas vencidas.</li><?php else: foreach($listados['vencidas'] as $t): ?><li class="task-overdue"><i class="fas fa-exclamation-circle"></i> <div><?php echo e($t['nombre_tarea']); ?><small style="display: block; color: #777;">Venció el: <?php echo date('d/m/Y', strtotime($t['fecha_vencimiento'])); ?></small></div></li><?php endforeach; endif; ?></ul></div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            Chart.register(ChartDataLabels);
            const pieCtx = document.getElementById('pieChart');
            const barCtx = document.getElementById('barChart');
            
            const pieData = { labels: <?php echo json_encode(array_map('ucfirst', $datos_graficos['individual_pie']['labels'])); ?>, datasets: [{ data: <?php echo json_encode($datos_graficos['individual_pie']['data']); ?>, backgroundColor: [ 'rgba(108, 117, 125, 0.7)', 'rgba(255, 193, 7, 0.7)', 'rgba(40, 167, 69, 0.7)' ] }]};
            const pieChart = new Chart(pieCtx, {
                type: 'pie',
                data: pieData,
                options: {
                    plugins: {
                        datalabels: {
                            formatter: (value, ctx) => {
                                let sum = 0;
                                let dataArr = ctx.chart.data.datasets[0].data;
                                dataArr.map(data => {
                                    sum += data;
                                });
                                let percentage = (value*100 / sum).toFixed(2)+"%";
                                return value + " (" + percentage + ")";
                            },
                            color: '#fff',
                        }
                    }
                }
            });
            
            const barData = { labels: <?php echo json_encode($datos_graficos['individual_bar']['labels']); ?>, datasets: [{ label: 'Tareas Gestionadas', data: <?php echo json_encode($datos_graficos['individual_bar']['data']); ?>, backgroundColor: 'rgba(54, 162, 235, 0.7)' }]};
            const barChart = new Chart(barCtx, { type: 'bar', data: barData, options: { scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } } });

            document.getElementById('pdfForm').addEventListener('submit', function(e) {
                document.getElementById('pieChartImage').value = pieChart.toBase64Image();
                document.getElementById('barChartImage').value = barChart.toBase64Image();
            });
        });
        </script>
    <?php elseif ($tipo_informe === 'equipo'): ?>
        <div class="analytics-grid" style="margin-top:20px;">
            <div class="chart-container"><h4><i class="fas fa-chart-bar"></i> Tareas Completadas por Usuario</h4><canvas id="teamBarChart"></canvas></div>
            <div class="chart-container"><h4><i class="fas fa-pie-chart"></i> Distribución de Carga de Trabajo</h4><canvas id="teamPieChart"></canvas></div>
            <div class="chart-container"><h4><i class="fas fa-exclamation-triangle" style="color:var(--danger-color)"></i> Ranking de Tareas Vencidas</h4>
                <table class="ranking-table"><?php if(empty($listados['vencidas'])): ?><tr><td>¡Excelente! No hay tareas vencidas.</td></tr><?php endif; ?><?php foreach($listados['vencidas'] as $item): ?><tr><td><?php echo e($item['nombre_completo']); ?></td><td class="rank-value"><?php echo e($item['total']); ?></td></tr><?php endforeach; ?></table>
            </div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const teamBarData = { labels: <?php echo json_encode($datos_graficos['equipo_bar']['labels']); ?>, datasets: [{ label: 'Tareas Completadas', data: <?php echo json_encode($datos_graficos['equipo_bar']['data']); ?>, backgroundColor: 'rgba(75, 192, 192, 0.7)' }]};
            new Chart(document.getElementById('teamBarChart'), { type: 'bar', data: teamBarData, options: { indexAxis: 'y', scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } } } });
            const teamPieData = { labels: <?php echo json_encode($datos_graficos['equipo_pie']['labels']); ?>, datasets: [{ data: <?php echo json_encode($datos_graficos['equipo_pie']['data']); ?> }]};
            new Chart(document.getElementById('teamPieChart'), { type: 'pie', data: teamPieData, options: { plugins: { legend: { position: 'right' } } } });
        });
        </script>
    <?php elseif ($tipo_informe === 'informe_tareas' && $datos_encontrados): ?>
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
                <?php if (empty($summary['por_negocio'])):
                    ?><span>No hay datos de negocio.</span><?php
                else:
                    ?>
                    <?php foreach ($summary['por_negocio'] as $negocio => $cantidad):
                        ?>- <?php echo e($negocio); ?>: <?php echo $cantidad; ?><br><?php
                    endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif ($tipo_informe === 'piezas_miembro'): ?>
        <div style="max-width: 800px; margin: 20px auto;">
            <div class="chart-container"><h4><i class="fas fa-chart-bar"></i> Número de piezas por Miembro</h4><canvas id="piezasMiembroBarChart"></canvas></div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const piezasMiembroData = { labels: <?php echo json_encode($datos_graficos['piezas_miembro_bar']['labels']); ?>, datasets: [{ label: 'Total Piezas', data: <?php echo json_encode($datos_graficos['piezas_miembro_bar']['data']); ?>, backgroundColor: 'rgba(255, 159, 64, 0.7)' }]};
            new Chart(document.getElementById('piezasMiembroBarChart'), { type: 'bar', data: piezasMiembroData, options: { scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } } });
        });
        </script>
    <?php elseif ($tipo_informe === 'requerimientos_negocio'): ?>
        <div style="max-width: 600px; margin: 20px auto;">
            <div class="chart-container"><h4><i class="fas fa-pie-chart"></i> Requerimientos por Negocio</h4><canvas id="requerimientosNegocioPieChart"></canvas></div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const requerimientosNegocioData = {
                labels: <?php echo json_encode($datos_graficos['requerimientos_negocio_pie']['labels']); ?>, 
                datasets: [{
                    data: <?php echo json_encode($datos_graficos['requerimientos_negocio_pie']['data']); ?>,
                    backgroundColor: ['rgba(255, 99, 132, 0.7)','rgba(54, 162, 235, 0.7)','rgba(255, 206, 86, 0.7)','rgba(75, 192, 192, 0.7)','rgba(153, 102, 255, 0.7)','rgba(255, 159, 64, 0.7)']
                }]
            };
            new Chart(document.getElementById('requerimientosNegocioPieChart'), { type: 'pie', data: requerimientosNegocioData });
        });
        </script>
    <?php endif; ?>
<?php elseif ($fecha_inicio && $fecha_fin): ?>
    <div class="alert alert-info" style="margin-top:20px;">No se encontraron datos para el tipo de informe y el rango de fechas seleccionados.</div>
<?php endif; ?>

<?php include '../includes/footer_admin.php'; ?>