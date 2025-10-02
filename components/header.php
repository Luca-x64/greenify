<!-- header.php: contiene la navbar e l'apertura del body -->
<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Greenify - Ecommerce</title>
    <!-- Bootstrap CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php require_once __DIR__ . '/../conf/conf.php'; ?>
    <link rel="stylesheet" href="<?= ROOT_PATH ?>/assets/css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>

<body class="d-flex flex-column min-vh-100">
    <!-- Header/Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light bg-light shadow-sm">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="<?= ROOT_PATH ?>/index.php">
                <!-- Logo SVG -->
                <img src="https://img.icons8.com/ios-filled/50/198754/plant-under-sun.png" alt="Greenify Logo">
                <span class="fw-bold text-success fs-4">Greenify</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
                <?php include __DIR__ . '/nav_buttons.php'; ?>
            </div>
        </div>
    </nav>