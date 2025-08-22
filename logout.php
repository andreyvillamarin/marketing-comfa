<?php
require_once 'includes/config.php';

// Destruye todas las variables de sesión.
$_SESSION = array();

// Borra la cookie de sesión.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

// Redirigir usando la URL base para mayor seguridad.
header("Location: " . BASE_URL . "/miembro/login.php");
exit();
?>