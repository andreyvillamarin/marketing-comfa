<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? e($page_title) . ' - Panel de Control' : 'Panel de Control'; ?></title>
    <link rel="icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
    <nav id="sidebar-nav">
        <button id="close-btn">&times;</button> 
        <div class="sidebar-header"><h3>Gestor de Tareas</h3></div>
        <a href="<?php echo BASE_URL; ?>/admin/index.php">Dashboard</a>
        
        <?php if (isset($_SESSION['user_rol'])): ?>
            <?php if ($_SESSION['user_rol'] === 'admin'): ?>
                <a href="<?php echo BASE_URL; ?>/admin/crear_tarea.php">Crear Tarea</a>
                <a href="<?php echo BASE_URL; ?>/admin/tareas.php">Todas las Tareas</a>
                <a href="<?php echo BASE_URL; ?>/admin/miembros.php">Gestionar Equipo</a>
                <a href="<?php echo BASE_URL; ?>/admin/analiticas.php">Analíticas</a>
            <?php elseif ($_SESSION['user_rol'] === 'analista'): ?>
                <a href="<?php echo BASE_URL; ?>/admin/crear_tarea.php">Crear Tarea</a>
                <a href="<?php echo BASE_URL; ?>/admin/tareas.php">Tareas Creadas por Mí</a>
                <a href="<?php echo BASE_URL; ?>/admin/mis_tareas.php">Mis Tareas Asignadas</a>
                <a href="<?php echo BASE_URL; ?>/admin/analiticas.php">Analíticas</a>
            <?php endif; ?>
        <?php endif; ?>
        <a href="<?php echo BASE_URL; ?>/admin/perfil.php">Mi Perfil</a>
        <a href="<?php echo BASE_URL; ?>/logout.php" class="logout-btn">Cerrar Sesión</a>
    </nav>
    <div class="main-content">
        <header>
            <button id="hamburger-btn"><i class="fas fa-bars"></i></button>
            <h1><?php echo isset($page_title) ? e($page_title) : 'Panel de Control'; ?></h1>
        </header>
        <main class="container">