<?php
// Landing page per utente non attivo
?>
<?php include_once '../components/header.php'; ?>
<main class="container py-5 d-flex flex-column align-items-center justify-content-center" style="min-height:60vh;">
    <div class="card shadow-sm p-4" style="max-width: 400px;">
        <h2 class="mb-3 text-center text-danger">Utente non attivo</h2>
        <p class="text-center mb-4">
            Il tuo account non è attivo.<br>
            Registrati per accedere ai servizi.
        </p>
        <div class="d-flex justify-content-center">
            <a href="register.php" class="btn btn-success">Registrati</a>
        </div>
    </div>
</main>
<?php include_once '../components/footer.php'; ?>