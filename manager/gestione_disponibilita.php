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

// Recupera il negozio gestito dal manager loggato
$negozio_id = null;
$negozio_nome = '';
$negozio_citta = '';
$manager_mail = $_SESSION['manager_id'];
$res = pg_query_params($db, "SELECT n.id, i.citta, i.indirizzo FROM greenify.negozio n JOIN greenify.indirizzo i ON n.indirizzo_id = i.id WHERE n.manager_mail = $1 AND n.aperto = true", [$manager_mail]);
if ($res && pg_num_rows($res) > 0) {
    $row = pg_fetch_assoc($res);
    $negozio_id = $row['id'];
    $negozio_nome = $row['indirizzo'];
    $negozio_citta = $row['citta'];
    pg_free_result($res);
} else {
    if ($res) pg_free_result($res);
}

// Azioni: modifica/elimina disponibilità
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $negozio_id) {
    if (isset($_POST['edit_disponibilita'])) {
        $prodotto_id = intval($_POST['prodotto_id']);
        $quantita = isset($_POST['quantita']) ? intval($_POST['quantita']) : 0;
        $prezzo = floatval($_POST['prezzo']);
        if ($quantita < 0 || $prezzo < 0) {
            $alert_msg = "Quantità e prezzo devono essere valori positivi.";
            $alert_type = 'danger';
        } else {
            // BUG: il campo quantità è disabilitato nel form, quindi non viene inviato nel POST
            // FIX: recupera la quantità attuale dal DB se non è presente nel POST
            if (!isset($_POST['quantita'])) {
                $qta_res = pg_query_params($db, "SELECT quantita FROM greenify.dispone WHERE negozio_id=$1 AND prodotto_id=$2", [$negozio_id, $prodotto_id]);
                if ($qta_res && ($row = pg_fetch_assoc($qta_res))) {
                    $quantita = $row['quantita'];
                }
                if ($qta_res) pg_free_result($qta_res);
            }
            $q = pg_query_params($db, "UPDATE greenify.dispone SET quantita=$1, prezzo=$2 WHERE negozio_id=$3 AND prodotto_id=$4", [$quantita, $prezzo, $negozio_id, $prodotto_id]);
            if ($q) {
                $alert_msg = "Disponibilità aggiornata!";
                $alert_type = 'success';
            } else {
                $alert_msg = "Errore nell'aggiornamento della disponibilità.";
                $alert_type = 'danger';
            }
        }
    } elseif (isset($_POST['delete_disponibilita'])) {
        $prodotto_id = intval($_POST['prodotto_id']);
        $q = pg_query_params($db, "DELETE FROM greenify.dispone WHERE negozio_id=$1 AND prodotto_id=$2", [$negozio_id, $prodotto_id]);
        if ($q) {
            $alert_msg = "Disponibilità eliminata!";
            $alert_type = 'success';
        } else {
            $alert_msg = "Errore nell'eliminazione della disponibilità.";
            $alert_type = 'danger';
        }
    }
}


// Recupera prodotti disponibili per il negozio gestito dal manager
$disponibilita = [];
if ($negozio_id) {
    $sql = "SELECT d.prodotto_id, p.nome, p.descrizione, d.quantita, d.prezzo
            FROM greenify.dispone d
            JOIN greenify.prodotto p ON d.prodotto_id = p.id
            WHERE d.negozio_id = $1
            ORDER BY p.nome";
    $res = pg_query_params($db, $sql, [$negozio_id]);
    if ($res) {
        while ($row = pg_fetch_assoc($res)) {
            $disponibilita[] = $row;
        }
        pg_free_result($res);
    }
}
if ($db) close_pg_connection($db);
?>
<?php include_once '../components/header.php'; ?>

<main class="container py-5">
    <h2>Gestione Disponibilità</h2>

    <?php if ($alert_msg): ?>
        <div class="alert alert-<?= $alert_type ?> alert-dismissible fade show" role="alert">
            <?= $alert_msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
        </div>
    <?php endif; ?>

    <?php if ($negozio_id): ?>
        <div class="card mb-4">
            <div class="card-header">
                Prodotti in vendita nel tuo negozio: <b><?= htmlspecialchars($negozio_citta) ?>, <?= htmlspecialchars($negozio_nome) ?></b>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Descrizione</th>
                                <th>Quantità</th>
                                <th>Prezzo <span class="ms-1">€</span></th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($disponibilita as $d):
                                $is_low = ($d['quantita'] > 0 && $d['quantita'] <= 5);
                                $is_zero = ($d['quantita'] == 0);
                            ?>
                                <tr<?= $is_zero ? ' style="background-color: #ffeaea;"' : ($is_low ? ' style="background-color: #fffbe6;"' : '') ?>>
                                    <td>
                                        <?= htmlspecialchars($d['nome']) ?>
                                        <?php if ($is_zero): ?>
                                            <span class="badge bg-danger ms-2">Esaurito</span>
                                        <?php elseif ($is_low): ?>
                                            <span class="badge bg-warning text-dark ms-2">Bassa disponibilità</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($d['descrizione']) ?></td>
                                    <td>
                                        <span<?= $is_zero ? ' class="text-danger fw-bold"' : ($is_low ? ' class="text-warning fw-bold"' : '') ?>>
                                            <?= htmlspecialchars($d['quantita']) ?>
                                            </span>
                                    </td>
                                    <td><?= htmlspecialchars(number_format($d['prezzo'], 2, ',', '')) ?> €</td>
                                    <td>
                                        <!-- Modifica -->
                                        <button
                                            class="btn btn-sm btn-outline-primary btn-edit-disponibilita"
                                            data-prodotto_id="<?= $d['prodotto_id'] ?>"
                                            data-nome="<?= htmlspecialchars($d['nome']) ?>"
                                            data-quantita="<?= $d['quantita'] ?>"
                                            data-prezzo="<?= $d['prezzo'] ?>">Modifica</button>
                                        <!-- Elimina -->
                                        <form method="post" action="" style="display:inline;" class="form-delete-disponibilita">
                                            <input type="hidden" name="prodotto_id" value="<?= $d['prodotto_id'] ?>">
                                            <button type="button" class="btn btn-sm btn-outline-danger btn-show-delete-modal" data-nome="<?= htmlspecialchars($d['nome']) ?>">Elimina</button>
                                        </form>
                                    </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($disponibilita)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center">Nessun prodotto disponibile per questo negozio.</td>
                                    </tr>
                                <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-warning mt-4 text-center">Non hai un negozio associato o il negozio non è attivo.</div>
    <?php endif; ?>

    <!-- Modal modifica disponibilità -->
    <div class="modal fade" id="modalEditDisponibilita" tabindex="-1" aria-labelledby="modalEditDisponibilitaLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form method="post" action="">
                <input type="hidden" name="edit_disponibilita" value="1">
                <input type="hidden" name="prodotto_id" id="edit_prodotto_id">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalEditDisponibilitaLabel">Modifica Disponibilità</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info" role="alert">
                            Per modificare i dettagli del prodotto vai alla <a href="gestione_prodotti.php" class="alert-link">pagina di gestione prodotti</a>.
                        </div>
                        <div class="mb-3">
                            <label for="edit_nome" class="form-label">Nome prodotto</label>
                            <input type="text" class="form-control" id="edit_nome" name="edit_nome" disabled>
                        </div>
                        <div class="mb-3">
                            <label for="edit_quantita" class="form-label">Quantità</label>
                            <input type="number" class="form-control" id="edit_quantita" name="quantita" min="0" disabled>
                        </div>
                        <div class="mb-3">
                            <label for="edit_prezzo" class="form-label">Prezzo (€)</label>
                            <input type="number" step="0.01" class="form-control" id="edit_prezzo" name="prezzo" min="0" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-primary">Salva Modifiche</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal conferma eliminazione disponibilità -->
    <div class="modal fade" id="modalDeleteDisponibilita" tabindex="-1" aria-labelledby="modalDeleteDisponibilitaLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="" id="formDeleteDisponibilita">
                    <input type="hidden" name="prodotto_id" id="delete_prodotto_id">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalDeleteDisponibilitaLabel">Conferma eliminazione</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                    </div>
                    <div class="modal-body">
                        <p>Sei sicuro di voler eliminare la disponibilità per il prodotto <b id="delete_nome"></b>?</p>
                        <p class="text-danger mb-0">Questa operazione non può essere annullata.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" name="delete_disponibilita" value="1" class="btn btn-danger">Elimina</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var editBtns = document.querySelectorAll('.btn-edit-disponibilita');
            editBtns.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    document.getElementById('edit_prodotto_id').value = btn.getAttribute('data-prodotto_id');
                    document.getElementById('edit_nome').value = btn.getAttribute('data-nome');
                    document.getElementById('edit_quantita').value = btn.getAttribute('data-quantita');
                    document.getElementById('edit_prezzo').value = btn.getAttribute('data-prezzo');
                    var modal = new bootstrap.Modal(document.getElementById('modalEditDisponibilita'));
                    modal.show();
                });
            });

            // Gestione popup conferma eliminazione
            document.querySelectorAll('.btn-show-delete-modal').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var form = btn.closest('.form-delete-disponibilita');
                    var prodottoId = form.querySelector('input[name="prodotto_id"]').value;
                    var nome = btn.getAttribute('data-nome');
                    document.getElementById('delete_prodotto_id').value = prodottoId;
                    document.getElementById('delete_nome').textContent = nome;
                    var modal = new bootstrap.Modal(document.getElementById('modalDeleteDisponibilita'));
                    modal.show();
                });
            });
        });
    </script>
</main>
<?php include_once '../components/footer.php'; ?>