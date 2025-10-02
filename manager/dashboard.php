<?php
session_start();
if (!isset($_SESSION['manager_id'])) {
    header('Location: ../pages/login_manager.php');
    exit();
}
?>
<?php include_once '../components/header.php'; ?>

<main class="container py-5">
    <h2 class="mb-4 text-center">Pannello di Gestione Manager</h2>
    <div class="row row-cols-1 row-cols-md-3 g-4">
        <div class="col">
            <a href="gestione_negozi.php" class="card dashboard-card text-decoration-none shadow-sm">
                <span class="dashboard-icon icon-green"><i class="bi bi-shop"></i></span>
                Gestisci Negozi
            </a>
        </div>
        <div class="col">
            <a href="gestione_disponibilita.php" class="card dashboard-card text-decoration-none shadow-sm">
                <span class="dashboard-icon icon-gray"><i class="bi bi-archive"></i></span>
                Gestisci Disponibilità
            </a>
        </div>
        <div class="col">
            <a href="gestione_prodotti.php" class="card dashboard-card text-decoration-none shadow-sm">
                <span class="dashboard-icon icon-orange"><i class="bi bi-box-seam"></i></span>
                Gestisci Prodotti
            </a>
        </div>
        <div class="col">
            <a href="gestione_fornitori.php" class="card dashboard-card text-decoration-none shadow-sm">
                <span class="dashboard-icon icon-purple"><i class="bi bi-truck"></i></span>
                Gestisci Fornitori
            </a>
        </div>
        <div class="col">
            <a href="gestione_ordini.php" class="card dashboard-card text-decoration-none shadow-sm">
                <span class="dashboard-icon icon-teal"><i class="bi bi-clipboard-data"></i></span>
                Gestisci Ordini
            </a>
        </div>
        <div class="col">
            <a href="gestione_manager.php" class="card dashboard-card text-decoration-none shadow-sm">
                <span class="dashboard-icon icon-blue"><i class="bi bi-person-badge"></i></span>
                Gestisci Manager
            </a>
        </div>
        <div class="col">
            <a href="gestione_clienti.php" class="card dashboard-card text-decoration-none shadow-sm">
                <span class="dashboard-icon icon-yellow"><i class="bi bi-people"></i></span>
                Gestisci Clienti
            </a>
        </div>
        <div class="col">
            <a href="statistiche.php" class="card dashboard-card text-decoration-none shadow-sm">
                <span class="dashboard-icon icon-red"><i class="bi bi-bar-chart-line"></i></span>
                Statistiche
            </a>
        </div>
    </div>
</main>
<?php include_once '../components/footer.php'; ?>