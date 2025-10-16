<?php
// Configuración de la zona horaria
date_default_timezone_set('America/Bogota');

// Configuración de la Base de Datos
define('DB_HOST', 'localhost');
define('DB_USER', 'comfalink_webmaster');
define('DB_PASS', 'qdosnetwork1993');
define('DB_NAME', 'comfalink_marketing');

// URL base del proyecto
define('BASE_URL', 'https://comfa.link/marketing');

// ===================================================================
// INICIO DE LA SECCIÓN DEL ERROR
// Asegúrate de que esta sección quede exactamente así
// ===================================================================
// Configuración de Email (Brevo SMTP)
define('SMTP_HOST', '');
define('SMTP_USER', '');      // Línea 18
define('SMTP_PASS', '');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');
define('EMAIL_FROM', 'andreyvillamarin@gmail.com');
define('EMAIL_FROM_NAME', 'Gestor de Tareas Comfamiliar');
// ===================================================================
// FIN DE LA SECCIÓN DEL ERROR
// ===================================================================


// Configuración de Sesiones
ini_set('session.save_path', __DIR__ . '/../sessions');
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
?>