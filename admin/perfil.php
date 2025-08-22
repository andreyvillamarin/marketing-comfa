<?php $page_title = 'Mi Perfil y Seguridad'; ?>
<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/funciones.php';

// Proteger página: solo para administradores y analistas
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_rol'], ['admin', 'analista'])) {
    header("Location: " . BASE_URL . "/admin/login.php");
    exit();
}

$mensaje = '';
$error = '';
$id_usuario = $_SESSION['user_id'];

// Obtener los datos actuales del usuario para mostrarlos en el formulario
$stmt_user = $pdo->prepare("SELECT nombre_completo, email FROM usuarios WHERE id_usuario = ?");
$stmt_user->execute([$id_usuario]);
$usuario_actual = $stmt_user->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- INICIO DE LA MODIFICACIÓN: LÓGICA PARA ACTUALIZAR PERFIL ---
    if (isset($_POST['actualizar_perfil'])) {
        $email_nuevo = trim($_POST['email']);

        if (empty($email_nuevo) || !filter_var($email_nuevo, FILTER_VALIDATE_EMAIL)) {
            $error = "Por favor, introduce una dirección de correo electrónico válida.";
        } else {
            // Verificar si el nuevo email ya está en uso por OTRO usuario
            $stmt_check = $pdo->prepare("SELECT id_usuario FROM usuarios WHERE email = ? AND id_usuario != ?");
            $stmt_check->execute([$email_nuevo, $id_usuario]);
            if ($stmt_check->fetch()) {
                $error = "Esa dirección de correo electrónico ya está en uso por otro usuario.";
            } else {
                // Actualizar el email en la base de datos
                try {
                    $stmt_update = $pdo->prepare("UPDATE usuarios SET email = ? WHERE id_usuario = ?");
                    $stmt_update->execute([$email_nuevo, $id_usuario]);
                    $mensaje = "Tu correo electrónico ha sido actualizado exitosamente.";
                    // Actualizamos la variable para que el formulario muestre el nuevo email
                    $usuario_actual['email'] = $email_nuevo;
                } catch (PDOException $e) {
                    $error = "Error al actualizar el correo electrónico.";
                }
            }
        }
    }
    // --- FIN DE LA MODIFICACIÓN ---

    // Lógica para cambiar la contraseña (se mantiene igual)
    if (isset($_POST['cambiar_password'])) {
        $pass_actual = $_POST['password_actual'] ?? '';
        $pass_nueva = $_POST['password_nuevo'] ?? '';
        $pass_confirmar = $_POST['password_confirmar'] ?? '';

        if (empty($pass_actual) || empty($pass_nueva) || empty($pass_confirmar)) {
            $error = "Todos los campos de contraseña son obligatorios para cambiarla.";
        } elseif ($pass_nueva !== $pass_confirmar) {
            $error = "La nueva contraseña y su confirmación no coinciden.";
        } elseif (strlen($pass_nueva) < 6) {
            $error = "La nueva contraseña debe tener al menos 6 caracteres.";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT password FROM usuarios WHERE id_usuario = ?");
                $stmt->execute([$id_usuario]);
                $user = $stmt->fetch();
                
                if ($user && password_verify($pass_actual, $user['password'])) {
                    $nuevo_hash = password_hash($pass_nueva, PASSWORD_DEFAULT);
                    $stmt_update = $pdo->prepare("UPDATE usuarios SET password = ? WHERE id_usuario = ?");
                    $stmt_update->execute([$nuevo_hash, $id_usuario]);
                    $mensaje = "¡Tu contraseña ha sido actualizada exitosamente!";
                } else {
                    $error = "La contraseña actual que ingresaste es incorrecta.";
                }
            } catch (PDOException $e) {
                $error = "Error de base de datos. Por favor, intenta de nuevo.";
                error_log($e->getMessage());
            }
        }
    }
}

include '../includes/header_admin.php';
?>

<?php if ($mensaje): ?><div class="alert alert-success"><?php echo e($mensaje); ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

<div class="grid-container">
    <div class="card">
        <h3><i class="fas fa-user-circle"></i> Mis Datos</h3>
        <form action="perfil.php" method="POST">
            <div class="form-group">
                <label for="nombre_completo">Nombre Completo</label>
                <input type="text" id="nombre_completo" value="<?php echo e($usuario_actual['nombre_completo']); ?>" disabled>
                <small>El nombre completo solo puede ser modificado por un Administrador desde la sección "Gestionar Equipo".</small>
            </div>
            <div class="form-group">
                <label for="email">Correo Electrónico (para notificaciones)</label>
                <input type="email" name="email" id="email" value="<?php echo e($usuario_actual['email']); ?>" required>
            </div>
            <button type="submit" name="actualizar_perfil" class="btn btn-primary"><i class="fas fa-save"></i> Guardar Correo</button>
        </form>
    </div>
    <div class="card">
        <h3><i class="fas fa-key"></i> Cambiar mi Contraseña</h3>
        <form action="perfil.php" method="POST">
            <div class="form-group">
                <label for="password_actual">Contraseña Actual</label>
                <input type="password" name="password_actual" id="password_actual" required>
            </div>
            <div class="form-group">
                <label for="password_nuevo">Nueva Contraseña</label>
                <input type="password" name="password_nuevo" id="password_nuevo" required>
            </div>
            <div class="form-group">
                <label for="password_confirmar">Confirmar Nueva Contraseña</label>
                <input type="password" name="password_confirmar" id="password_confirmar" required>
            </div>
            <button type="submit" name="cambiar_password" class="btn btn-success">Actualizar Contraseña</button>
        </form>
    </div>
</div>

<?php include '../includes/footer_admin.php'; ?>