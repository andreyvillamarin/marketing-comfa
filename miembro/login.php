<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/funciones.php';

if (isset($_SESSION['user_id']) && isset($_SESSION['user_rol']) && $_SESSION['user_rol'] === 'miembro') {
    header("Location: " . BASE_URL . "/miembro/index.php");
    exit(); // Aseguramos que el script se detenga aquí
}

$error = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (empty($_POST['email']) || empty($_POST['password'])) {
        $error = "Por favor, ingrese email y contraseña.";
    } else {
        $email = trim($_POST['email']);
        $password = trim($_POST['password']);
        try {
            $stmt = $pdo->prepare("SELECT id_usuario, nombre_completo, password, rol FROM usuarios WHERE email = ? AND rol = 'miembro'");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id_usuario'];
                $_SESSION['user_rol'] = $user['rol'];
                $_SESSION['user_nombre'] = $user['nombre_completo'];
                header("Location: " . BASE_URL . "/miembro/index.php");
                exit(); // Aseguramos que el script se detenga aquí
            } else {
                $error = "Email o contraseña incorrectos.";
            }
        } catch (PDOException $e) {
            $error = "Error del sistema. Por favor, intente más tarde.";
            error_log($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Acceso de Miembros</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
    <div class="login-container">
        <img src="<?php echo BASE_URL; ?>/assets/img/logo.png" alt="Logo de la Empresa" class="login-logo">
        <h2>Acceso de Miembros</h2>
        <?php if (!empty($error)): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
        <form action="<?php echo BASE_URL; ?>/miembro/login.php" method="post" novalidate>
            <div class="form-group">
                <label for="email">Email:</label>
                <input type="email" name="email" id="email" required>
            </div>
            <div class="form-group">
                <label for="password">Contraseña:</label>
                <input type="password" name="password" id="password" required>
            </div>
            <button type="submit" class="btn" style="width:100%;">Ingresar</button>
            <p style="text-align:center; margin-top:15px;"><a href="<?php echo BASE_URL; ?>/admin/login.php">¿Eres administrador? Ingresa aquí</a></p>
        </form>
    </div>
</body>
</html>