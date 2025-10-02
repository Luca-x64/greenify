<?php
// filepath: /srv/http/greenify/manager/gestione_fornitori.php
session_start();
require_once __DIR__ . '/../db/connector.php';

$db = open_pg_connection();
$alert_msg = null;
$alert_type = 'info';

// Modifica fornitore
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_fornitore'])) {
    $old_p_iva = $_POST['edit_p_iva_old'];
    $new_p_iva = trim($_POST['edit_p_iva']);
    $nome = trim($_POST['edit_nome']);
    $indirizzo = trim($_POST['edit_indirizzo']);
    $citta = trim($_POST['edit_citta']);
    $telefono = trim($_POST['edit_telefono']);
    $email = trim($_POST['edit_email']);
    if ($new_p_iva === '' || $nome === '' || $indirizzo === '' || $citta === '' || $telefono === '' || $email === '') {
        $alert_msg = "Tutti i campi sono obbligatori.";
        $alert_type = 'danger';
    } elseif (strlen($new_p_iva) !== 11) {
        $alert_msg = "La Partita IVA deve essere composta da 11 caratteri.";
        $alert_type = 'danger';
    } else {
        // Usa la funzione SQL per modificare il fornitore (adatta il nome/parametri se necessario)
        $q = pg_query_params(
            $db,
            "SELECT greenify.fn_modifica_fornitore($1, $2, $3, $4, $5, $6, $7)",
            [$old_p_iva, $new_p_iva, $nome, $indirizzo, $citta, $telefono, $email]
        );
        if ($q) {
            $alert_msg = "Fornitore modificato con successo!";
            $alert_type = 'success';
        } else {
            $pg_err = pg_last_error($db);
            if (strpos($pg_err, 'unique') !== false || strpos(strtolower($pg_err), 'duplicate') !== false) {
                $alert_msg = "Errore: esiste già un fornitore con questa Partita IVA.";
            } else {
                $alert_msg = "Errore nella modifica del fornitore.";
            }
            $alert_type = 'danger';
        }
    }
}


// Attiva/disattiva fornitore
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_attivo'])) {
    $p_iva = $_POST['toggle_p_iva'];
    if (isset($_POST['attivo']) && $_POST['attivo'] === '1') {
        $attivo = 'false'; // Disattiva
    } else {
        $attivo = 'true'; // Attiva
    }
    $q = pg_query_params($db, "UPDATE greenify.fornitore SET attivo = $1 WHERE p_iva = $2", [$attivo, $p_iva]);
    if ($q) {
        $alert_msg = "Stato fornitore aggiornato!";
        $alert_type = 'success';
    } else {
        $alert_msg = "Errore nell'aggiornamento dello stato. disattiva          ";
        $alert_type = 'danger';
    }
}

// Aggiungi fornitore
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_fornitore'])) {
    $new_p_iva = trim($_POST['add_p_iva']);
    $nome = trim($_POST['add_nome']);
    $via = trim($_POST['add_via']);
    $citta = trim($_POST['add_citta']);
    $telefono = trim($_POST['add_telefono']);
    $email = trim($_POST['add_email']);
    if ($new_p_iva === '' || $nome === '' || $via === '' || $citta === '' || $telefono === '' || $email === '') {
        $alert_msg = "Tutti i campi sono obbligatori.";
        $alert_type = 'danger';
    } elseif (strlen($new_p_iva) !== 11) {
        $alert_msg = "La Partita IVA deve essere composta da 11 caratteri.";
        $alert_type = 'danger';
    } else {
        $q = pg_query_params(
            $db,
            "SELECT greenify.fn_inserisci_fornitore_con_indirizzo($1, $2, $3, $4, $5, $6)",
            [$new_p_iva, $nome, $via, $citta, $telefono, $email]
        );
        if ($q) {
            $alert_msg = "Fornitore aggiunto con successo!";
            $alert_type = 'success';
        } else {
            $pg_err = pg_last_error($db);
            if (strpos($pg_err, 'unique') !== false || strpos(strtolower($pg_err), 'duplicate') !== false) {
                $alert_msg = "Errore: esiste già un fornitore con questa Partita IVA.";
            } else {
                $alert_msg = "Errore nell'aggiunta del fornitore.";
            }
            $alert_type = 'danger';
            
        }
    }
}

// Recupera fornitori (join con indirizzo)
$fornitori = [];
$res = pg_query($db, "SELECT f.p_iva, f.nome, i.citta, i.indirizzo, f.telefono, f.email, f.attivo
                      FROM greenify.fornitore f
                      JOIN greenify.indirizzo i ON f.indirizzo_id = i.id
                      ORDER BY f.nome");
if ($res) {
    while ($row = pg_fetch_assoc($res)) {
        $fornitori[] = $row;
    }
    pg_free_result($res);
}
if ($db) close_pg_connection($db);
?>
<?php include_once '../components/header.php'; ?>

<main class="container py-5">
    <h2>Gestione Fornitori</h2>

    <?php if ($alert_msg): ?>
        <div class="alert alert-<?= $alert_type ?> alert-dismissible fade show" role="alert">
            <?= $alert_msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
        </div>
    <?php endif; ?>

    <!-- Bottone per aprire il modal aggiungi fornitore -->
    <button class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#addFornitoreModal">
        Aggiungi Fornitore
    </button>

    <!-- MODAL AGGIUNGI FORNITORE -->
    <div class="modal fade" id="addFornitoreModal" tabindex="-1" aria-labelledby="addFornitoreModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form method="post" action="">
                <input type="hidden" name="add_fornitore" value="1">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addFornitoreModalLabel">Aggiungi Fornitore</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="add_p_iva" class="form-label">Partita IVA *</label>
                            <input type="text" class="form-control" id="add_p_iva" name="add_p_iva" required maxlength="20">
                        </div>
                        <div class="mb-3">
                            <label for="add_nome" class="form-label">Nome *</label>
                            <input type="text" class="form-control" id="add_nome" name="add_nome" required maxlength="100">
                        </div>
                        <div class="mb-3">
                            <label for="add_via" class="form-label">Via *</label>
                            <input type="text" class="form-control" id="add_via" name="add_via" required maxlength="255">
                        </div>
                        <div class="mb-3">
                            <label for="add_citta" class="form-label">Città *</label>
                            <input type="text" class="form-control" id="add_citta" name="add_citta" required maxlength="100">
                        </div>
                        <div class="mb-3">
                            <label for="add_telefono" class="form-label">Telefono *</label>
                            <input type="text" class="form-control" id="add_telefono" name="add_telefono" required maxlength="10">
                        </div>
                        <div class="mb-3">
                            <label for="add_email" class="form-label">Email *</label>
                            <input type="email" class="form-control" id="add_email" name="add_email" required maxlength="255">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-primary">Aggiungi</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL MODIFICA FORNITORE -->
    <div class="modal fade" id="editFornitoreModal" tabindex="-1" aria-labelledby="editFornitoreModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form method="post" action="">
                <input type="hidden" name="edit_fornitore" value="1">
                <input type="hidden" name="edit_p_iva_old" id="edit_p_iva_old">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editFornitoreModalLabel">Modifica Fornitore</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_p_iva" class="form-label">Partita IVA *</label>
                            <input type="text" class="form-control" id="edit_p_iva" name="edit_p_iva" required maxlength="20">
                        </div>
                        <div class="mb-3">
                            <label for="edit_nome" class="form-label">Nome *</label>
                            <input type="text" class="form-control" id="edit_nome" name="edit_nome" required maxlength="100">
                        </div>
                        <div class="mb-3">
                            <label for="edit_citta" class="form-label">Città *</label>
                            <input type="text" class="form-control" id="edit_citta" name="edit_citta" required maxlength="100">
                        </div>
                        <div class="mb-3">
                            <label for="edit_indirizzo" class="form-label">Indirizzo *</label>
                            <input type="text" class="form-control" id="edit_indirizzo" name="edit_indirizzo" required maxlength="255">
                        </div>
                        <div class="mb-3">
                            <label for="edit_telefono" class="form-label">Telefono *</label>
                            <input type="text" class="form-control" id="edit_telefono" name="edit_telefono" required maxlength="10">
                        </div>
                        <div class="mb-3">
                            <label for="edit_email" class="form-label">Email *</label>
                            <input type="email" class="form-control" id="edit_email" name="edit_email" required maxlength="255">
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

    <!-- Tabella fornitori -->
    <div class="card">
        <div class="card-header">
            Lista Fornitori
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped mb-0">
                    <thead>
                        <tr>
                            <th>Partita IVA</th>
                            <th>Nome</th>
                            <th>Città</th>
                            <th>Indirizzo</th>
                            <th>Telefono</th>
                            <th>Email</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fornitori as $f): ?>
                            <tr>
                                <td><?= htmlspecialchars($f['p_iva']) ?></td>
                                <td><?= htmlspecialchars($f['nome']) ?></td>
                                <td><?= htmlspecialchars($f['citta']) ?></td>
                                <td><?= htmlspecialchars($f['indirizzo']) ?></td>
                                <td><?= htmlspecialchars($f['telefono']) ?></td>
                                <td><?= htmlspecialchars($f['email']) ?></td>
                                <td>
                                    <?php if ($f['attivo'] === 't' || $f['attivo'] === true): ?>
                                        <button
                                            class="btn btn-sm btn-outline-primary btn-edit-fornitore"
                                            data-p_iva="<?= htmlspecialchars($f['p_iva']) ?>"
                                            data-nome="<?= htmlspecialchars($f['nome']) ?>"
                                            data-citta="<?= htmlspecialchars($f['citta']) ?>"
                                            data-indirizzo="<?= htmlspecialchars($f['indirizzo']) ?>"
                                            data-telefono="<?= htmlspecialchars($f['telefono']) ?>"
                                            data-email="<?= htmlspecialchars($f['email']) ?>">Modifica</button>
                                        <form method="post" action="" style="display:inline;" class="form-disattiva-fornitore">
                                            <input type="hidden" name="toggle_p_iva" value="<?= htmlspecialchars($f['p_iva']) ?>">
                                            <input type="hidden" name="attivo" value="1">
                                            <button type="submit" name="toggle_attivo" value="1" class="btn btn-sm btn-outline-danger btn-disattiva-fornitore">
                                                Disattiva
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" action="" style="display:inline;">
                                            <input type="hidden" name="toggle_p_iva" value="<?= htmlspecialchars($f['p_iva']) ?>">
                                            <input type="hidden" name="attivo" value="0">
                                            <button type="submit" name="toggle_attivo" value="1" class="btn btn-sm btn-outline-success">
                                                Attiva
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($fornitori)): ?>
                            <tr>
                                <td colspan="7" class="text-center">Nessun fornitore trovato.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var editButtons = document.querySelectorAll('.btn-edit-fornitore');
            editButtons.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    document.getElementById('edit_p_iva').value = btn.getAttribute('data-p_iva');
                    document.getElementById('edit_p_iva_old').value = btn.getAttribute('data-p_iva');
                    document.getElementById('edit_nome').value = btn.getAttribute('data-nome');
                    document.getElementById('edit_citta').value = btn.getAttribute('data-citta');
                    document.getElementById('edit_indirizzo').value = btn.getAttribute('data-indirizzo');
                    document.getElementById('edit_telefono').value = btn.getAttribute('data-telefono');
                    document.getElementById('edit_email').value = btn.getAttribute('data-email');
                    var modal = new bootstrap.Modal(document.getElementById('editFornitoreModal'));
                    modal.show();
                });
            });
        });
    </script>
</main>
<?php include_once '../components/footer.php'; ?>
    </script>
</main>
<?php include_once '../components/footer.php'; ?>


