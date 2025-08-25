<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/funciones.php';

// Proteger página y obtener ID
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'admin') { header("Location: login.php"); exit(); }
if (!isset($_GET['id']) || !filter_var($_GET['id'], FILTER_VALIDATE_INT)) { header("Location: miembros.php"); exit(); }
$id_miembro = $_GET['id'];

$mensaje = '';
$error = '';

// Lógica para procesar el formulario de edición
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre_completo = trim($_POST['nombre_completo']);
    $email = trim($_POST['email']);
    $id_area = $_POST['id_area'];
    $password = $_POST['password'];
    // --- INICIO DE LA MODIFICACIÓN: OBTENER EL NUEVO ROL ---
    $rol = $_POST['rol'];
    // --- FIN DE LA MODIFICACIÓN ---

    if (empty($nombre_completo) || empty($email) || empty($id_area) || !in_array($rol, ['miembro', 'analista', 'admin'])) {
        $error = "Nombre, email, área y un rol válido son campos obligatorios.";
    } else {
        try {
            // --- INICIO DE LA MODIFICACIÓN: ACTUALIZAR LA CONSULTA SQL ---
            $sql = "UPDATE usuarios SET nombre_completo = ?, email = ?, id_area = ?, rol = ?";
            $params = [$nombre_completo, $email, $id_area, $rol];
            // --- FIN DE LA MODIFICACIÓN ---

            if (!empty($password)) {
                if (strlen($password) < 6) {
                    $error = "La nueva contraseña debe tener al menos 6 caracteres.";
                } else {
                    $sql .= ", password = ?";
                    $params[] = password_hash($password, PASSWORD_DEFAULT);
                }
            }

            if (empty($error)) {
                $sql .= " WHERE id_usuario = ?";
                $params[] = $id_miembro;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $mensaje = "¡Usuario actualizado exitosamente!";
            }

        } catch (PDOException $e) {
            $error = "Error al actualizar el usuario. El email ya podría estar en uso por otro usuario.";
        }
    }
}

// Obtener los datos actuales del miembro para pre-rellenar el formulario
$stmt_miembro = $pdo->prepare("SELECT * FROM usuarios WHERE id_usuario = ?");
$stmt_miembro->execute([$id_miembro]);
$miembro = $stmt_miembro->fetch();

if (!$miembro || ($miembro['id_usuario'] === $_SESSION['user_id'] && $miembro['rol'] === 'admin')) {
    // Redirigir si el miembro no existe o si es el admin principal intentando editarse a sí mismo
    header("Location: miembros.php");
    exit();
}


$page_title = 'Editando a: ' . e($miembro['nombre_completo']);
$areas = $pdo->query("SELECT * FROM areas ORDER BY nombre_area")->fetchAll();

include '../includes/header_admin.php';
?>

<p style="margin-top:0;"><a href="miembros.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Volver a la lista</a></p>

<div class="card form-card">
    <h3>Datos de <?php echo e($miembro['nombre_completo']); ?></h3>
    
    <?php if ($mensaje): ?><div class="alert alert-success"><?php echo e($mensaje); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

    <form action="editar_miembro.php?id=<?php echo $id_miembro; ?>" method="POST">
        <div class="form-group">
            <label for="nombre_completo">Nombre Completo</label>
            <input type="text" name="nombre_completo" value="<?php echo e($miembro['nombre_completo']); ?>" required>
        </div>
        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" name="email" value="<?php echo e($miembro['email']); ?>" required>
        </div>
        <div class="form-group">
            <label for="id_area">Área</label>
            <select name="id_area" required>
                <option value="">Seleccionar área...</option>
                <?php foreach ($areas as $area): ?>
                <option value="<?php echo $area['id_area']; ?>" <?php if($area['id_area'] == $miembro['id_area']) echo 'selected'; ?>>
                    <?php echo e($area['nombre_area']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="rol">Rol del Usuario</label>
            <select name="rol" id="rol" required>
                <option value="miembro" <?php if ($miembro['rol'] == 'miembro') echo 'selected'; ?>>Miembro de Equipo</option>
                <option value="analista" <?php if ($miembro['rol'] == 'analista') echo 'selected'; ?>>Analista</option>
                <option value="admin" <?php if ($miembro['rol'] == 'admin') echo 'selected'; ?>>Superadministrador</option>
            </select>
        </div>
        <hr>
        <div class="form-group">
            <label for="password">Restablecer Contraseña</label>
            <input type="text" name="password" placeholder="Dejar en blanco para no cambiar">
            <small style="display:block; margin-top:5px; color:#6c757d;">Si dejas este campo vacío, la contraseña actual del usuario no se modificará.</small>
        </div>
        <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Guardar Cambios</button>
    </form>
</div>

<?php include '../includes/footer_admin.php'; ?>