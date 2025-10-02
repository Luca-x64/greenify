<?php
$data = isset($_GET['data']) ? htmlspecialchars($_GET['data']) : 'sconosciuta';
include __DIR__ . '/../components/header.php';
?>
<main class="container text-center my-5">
    <h2 class="text-danger">Non sei più un Manager</h2>
    <p>Sei stato licenziato in data <b><?php echo $data; ?></b></p>
    <p>È stato un piacere lavorare con noi.</p>
</main>
<?php include __DIR__ . '/../components/footer.php'; ?>