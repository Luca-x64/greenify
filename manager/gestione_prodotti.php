<?php
session_start();
if (!isset($_SESSION['manager_id'])) {
    header('Location: ../pages/login_manager.php');
    exit();
}
require_once __DIR__ . '/../db/connector.php';

$db = open_pg_connection();
$alert_msg = null;
$alert_type = 'info';

// Gestione inserimento prodotto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_prodotto'])) {
    $nome = trim($_POST['nome'] ?? '');
    $descrizione = trim($_POST['descrizione'] ?? '');

    if ($nome === '') {
        $alert_msg = "Il nome del prodotto è obbligatorio.";
        $alert_type = 'danger';
    } else {
        $q = pg_query_params($db, "INSERT INTO greenify.prodotto (nome, descrizione) VALUES ($1, $2)", [$nome, $descrizione]);
        if ($q) {
            $alert_msg = "Prodotto aggiunto con successo!";
            $alert_type = 'success';
        } else {
            $alert_msg = "Errore nell'aggiunta del prodotto.";
            $alert_type = 'danger';
        }
    }
}

// Gestione modifica prodotto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_prodotto'])) {
    $id = $_POST['edit_id'];
    $nome = trim($_POST['edit_nome']);
    $descrizione = trim($_POST['edit_descrizione']);
    if ($nome === '') {
        $alert_msg = "Il nome del prodotto è obbligatorio.";
        $alert_type = 'danger';
    } else {
        $q = pg_query_params($db, "UPDATE greenify.prodotto SET nome=$1, descrizione=$2 WHERE id=$3", [$nome, $descrizione, $id]);
        if ($q) {
            $alert_msg = "Prodotto modificato con successo!";
            $alert_type = 'success';
        } else {
            $alert_msg = "Errore nella modifica del prodotto.";
            $alert_type = 'danger';
        }
    }
}



// Recupera prodotti
$prodotti = [];
if ($db) {
    $sql = "SELECT id, nome, descrizione FROM greenify.prodotto ORDER BY id";
    $res = pg_query($db, $sql);
    if ($res) {
        while ($row = pg_fetch_assoc($res)) {
            $prodotti[] = $row;
        }
        pg_free_result($res);
    }
}
if ($db) close_pg_connection($db);
?>
<?php include_once '../components/header.php'; ?>

<main class="container py-5">
    <h2>Gestione Prodotti</h2>

    <!-- Messaggio di esito operazione -->
    <?php if ($alert_msg): ?>
        <div id="alert-top-right" class="alert alert-<?= $alert_type ?> alert-dismissible fade show position-fixed" style="top: 20px; right: 20px; z-index: 9999; min-width:300px;" role="alert">
            <?= $alert_msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
        </div>
        <script>
            setTimeout(function() {
                var alert = document.getElementById('alert-top-right');
                if (alert) alert.style.display = 'none';
            }, 5000);
        </script>
    <?php endif; ?>

    <!-- Bottone per mostrare il modal di aggiunta prodotto -->
    <button class="btn btn-success mb-3" type="button" data-bs-toggle="modal" data-bs-target="#modalAggiungiProdotto">
        Aggiungi Prodotto
    </button>

    <!-- MODAL AGGIUNGI PRODOTTO -->
    <div class="modal fade" id="modalAggiungiProdotto" tabindex="-1" aria-labelledby="modalAggiungiProdottoLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form method="post" action="">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalAggiungiProdottoLabel">Aggiungi Prodotto</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="nome" class="form-label">Nome *</label>
                            <input type="text" class="form-control" id="nome" name="nome" required maxlength="100">
                        </div>
                        <div class="mb-3">
                            <label for="descrizione" class="form-label">Descrizione</label>
                            <textarea class="form-control" id="descrizione" name="descrizione" maxlength="250" style="resize: none;"></textarea>
                        </div>
                        <input type="hidden" name="add_prodotto" value="1">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-primary">Salva Prodotto</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <!-- Tabella prodotti -->
    <div class="card">
        <div class="card-header">
            Lista Prodotti
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped mb-0" id="tabella-prodotti">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nome</th>
                            <th>Descrizione</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($prodotti as $p): ?>
                            <tr>
                                <td><?= htmlspecialchars($p['id']) ?></td>
                                <td><?= htmlspecialchars($p['nome']) ?></td>
                                <td><?= htmlspecialchars($p['descrizione']) ?></td>
                                <td>
                                    <button
                                        class="btn btn-sm btn-outline-primary btn-edit-prodotto"
                                        data-id="<?= htmlspecialchars($p['id']) ?>"
                                        data-nome="<?= htmlspecialchars($p['nome']) ?>"
                                        data-descrizione="<?= htmlspecialchars($p['descrizione']) ?>">Modifica</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($prodotti)): ?>
                            <tr>
                                <td colspan="4" class="text-center">Nessun prodotto trovato.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- MODAL MODIFICA PRODOTTO -->
    <div class="modal fade" id="editProdottoModal" tabindex="-1" aria-labelledby="editProdottoModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form method="post" action="">
                <input type="hidden" name="edit_prodotto" value="1">
                <input type="hidden" name="edit_id" id="edit_id">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editProdottoModalLabel">Modifica Prodotto</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_nome" class="form-label">Nome</label>
                            <input type="text" class="form-control" id="edit_nome" name="edit_nome" required maxlength="100">
                        </div>
                        <div class="mb-3">
                            <label for="edit_descrizione" class="form-label">Descrizione</label>
                            <textarea class="form-control" id="edit_descrizione" name="edit_descrizione" maxlength="250" style="resize: none;"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-primary">Conferma</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var editButtons = document.querySelectorAll('.btn-edit-prodotto');
            editButtons.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    document.getElementById('edit_id').value = btn.getAttribute('data-id');
                    document.getElementById('edit_nome').value = btn.getAttribute('data-nome');
                    document.getElementById('edit_descrizione').value = btn.getAttribute('data-descrizione');
                    var modal = new bootstrap.Modal(document.getElementById('editProdottoModal'));
                    modal.show();
                });
            });
        });
    </script>
</main>
<?php include_once '../components/footer.php'; ?>