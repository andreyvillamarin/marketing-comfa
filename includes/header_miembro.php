<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Tareas</title>
    <link rel="icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js'></script>
</head>
<body>
    <nav id="sidebar-nav" class="member-sidebar">
        <button id="close-btn">&times;</button>
        <div class="sidebar-header"><h3>Mis Tareas</h3></div>
        <a href="<?php echo BASE_URL; ?>/miembro/index.php">Ver Tareas</a>
        <a href="<?php echo BASE_URL; ?>/miembro/tareas_completadas.php">Tareas Completadas</a>
        <a href="<?php echo BASE_URL; ?>/miembro/analiticas.php">Analíticas</a>
        <a href="<?php echo BASE_URL; ?>/logout.php" class="logout-btn">Cerrar Sesión</a>
    </nav>
    <div class="main-content">
        <header>
            <button id="hamburger-btn"><i class="fas fa-bars"></i></button>
            <h1>Mis Tareas</h1>
        </header>
        <main class="container">