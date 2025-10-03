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

// --- INICIO: Comprobar y añadir la columna recibe_notificaciones si no existe ---
$columna_notificaciones_existe = false;
try {
    $resultado = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'recibe_notificaciones'");
    $columna_notificaciones_existe = $resultado->rowCount() > 0;
} catch (PDOException $e) {
    error_log("Error al verificar la columna 'recibe_notificaciones': " . $e->getMessage());
}

if (!$columna_notificaciones_existe) {
    try {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN recibe_notificaciones TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 para sí, 0 para no'");
        $columna_notificaciones_existe = true; // La columna ahora debería existir
    } catch (PDOException $e) {
        error_log("Error al intentar añadir la columna 'recibe_notificaciones': " . $e->getMessage());
        $error = "No se pudo configurar la opción de notificaciones. Por favor, contacte al administrador.";
    }
}
// --- FIN: Comprobación de columna ---

// Obtener los datos actuales del usuario para mostrarlos en el formulario
$query_user = "SELECT nombre_completo, email" . ($columna_notificaciones_existe ? ", recibe_notificaciones" : "") . " FROM usuarios WHERE id_usuario = ?";
$stmt_user = $pdo->prepare($query_user);
$stmt_user->execute([$id_usuario]);
$usuario_actual = $stmt_user->fetch();

// Si la columna no existía y no se pudo crear, asignamos un valor por defecto para no romper la UI
if (!isset($usuario_actual['recibe_notificaciones'])) {
    $usuario_actual['recibe_notificaciones'] = 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Lógica para actualizar perfil
    if (isset($_POST['actualizar_perfil'])) {
        $email_nuevo = trim($_POST['email']);

        if (empty($email_nuevo) || !filter_var($email_nuevo, FILTER_VALIDATE_EMAIL)) {
            $error = "Por favor, introduce una dirección de correo electrónico válida.";
        } else {
            $stmt_check = $pdo->prepare("SELECT id_usuario FROM usuarios WHERE email = ? AND id_usuario != ?");
            $stmt_check->execute([$email_nuevo, $id_usuario]);
            if ($stmt_check->fetch()) {
                $error = "Esa dirección de correo electrónico ya está en uso por otro usuario.";
            } else {
                // Construir la consulta de actualización
                $sql_update = "UPDATE usuarios SET email = ?";
                $params = [$email_nuevo];
                
                if ($_SESSION['user_rol'] === 'admin' && $columna_notificaciones_existe) {
                    $recibe_notificaciones = isset($_POST['recibe_notificaciones']) ? 1 : 0;
                    $sql_update .= ", recibe_notificaciones = ?";
                    $params[] = $recibe_notificaciones;
                }
                
                $sql_update .= " WHERE id_usuario = ?";
                $params[] = $id_usuario;

                try {
                    $stmt_update = $pdo->prepare($sql_update);
                    $stmt_update->execute($params);
                    $mensaje = "Tu perfil ha sido actualizado exitosamente.";
                    
                    // Actualizamos las variables para que el formulario muestre los nuevos datos
                    $usuario_actual['email'] = $email_nuevo;
                    if ($_SESSION['user_rol'] === 'admin' && $columna_notificaciones_existe) {
                        $usuario_actual['recibe_notificaciones'] = $recibe_notificaciones;
                    }
                } catch (PDOException $e) {
                    $error = "Error al actualizar el perfil.";
                    error_log("Error en perfil.php al actualizar: " . $e->getMessage());
                }
            }
        }
    }

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

            <?php if ($_SESSION['user_rol'] === 'admin' && $columna_notificaciones_existe): ?>
            <div class="form-group checkbox-group">
                <input type="checkbox" name="recibe_notificaciones" id="recibe_notificaciones" value="1" <?php if (!empty($usuario_actual['recibe_notificaciones'])) echo 'checked'; ?>>
                <label for="recibe_notificaciones">Deseo recibir notificaciones por correo electrónico (recordatorios, comentarios, etc.)</label>
            </div>
            <?php endif; ?>

            <button type="submit" name="actualizar_perfil" class="btn btn-primary"><i class="fas fa-save"></i> Guardar Cambios</button>
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