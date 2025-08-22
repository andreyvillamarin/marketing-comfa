<?php
require_once 'config.php';

try {
    // --- MODIFICACIÓN: Usamos charset=utf8mb4 ---
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

} catch (PDOException $e) {
    error_log("Error de conexión a la DB: " . $e->getMessage());
    die("Error: No se pudo conectar al sistema. Por favor, inténtelo más tarde.");
}
?>