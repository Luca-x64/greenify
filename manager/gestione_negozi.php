<?php
session_start();
if (!isset($_SESSION['manager_id'])) {
    header('Location: ../pages/login_manager.php');
    exit();
}
require_once __DIR__ . '/../db/connector.php';
require_once __DIR__ . '/../includes/functions.php';

$db = open_pg_connection();
$alert_msg = null;
$alert_type = 'info';
$manager_id = $_SESSION['manager_id'];

// Recupera manager disponibili (che non gestiscono già un negozio)
function get_manager_disponibili($db, $current_manager_mail = null)
{
    $managers = [];
    if ($current_manager_mail) {
        $sql = "SELECT mail FROM greenify.\"ManagerAttivi\" WHERE (mail NOT IN (SELECT manager_mail FROM greenify.negozio WHERE manager_mail IS NOT NULL) OR mail = $1)";
        $params = [$current_manager_mail];
        $res = pg_query_params($db, $sql, $params);
    } else {
        $sql = "SELECT mail FROM greenify.\"ManagerAttivi\" WHERE mail NOT IN (SELECT manager_mail FROM greenify.negozio WHERE manager_mail IS NOT NULL)";
        $res = pg_query($db, $sql);
    }
    if ($res) {
        while ($row = pg_fetch_assoc($res)) {
            $managers[] = $row['mail'];
        }
        pg_free_result($res);
    }
    return $managers;
}

// Recupera manager disponibili per la select di aggiunta negozio
$manager_disponibili = get_manager_disponibili($db);

// Gestione modifica negozio 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_negozio'])) {
    $negozio_id = $_POST['edit_id'];
    $indirizzo = trim($_POST['edit_indirizzo']);
    $citta = trim($_POST['edit_citta']);
    $telefono = trim($_POST['edit_telefono']);
    if ($indirizzo === '' || $citta === '' || $telefono === '') {
        $alert_msg = "Tutti i campi sono obbligatori.";
        $alert_type = 'danger';
    } else {
        // Verifica che il manager sia quello assegnato
        $check = pg_query_params($db, "SELECT manager_mail FROM greenify.negozio WHERE id = $1", [$negozio_id]);
        $row = pg_fetch_assoc($check);
        if ($row && $row['manager_mail'] === $manager_id) {
            // Usa la funzione SQL per aggiornare indirizzo, città e telefono
            $q = pg_query_params(
                $db,
                "SELECT greenify.fn_modifica_negozio($1, $2, $3, $4)",
                [$negozio_id, $indirizzo, $citta, $telefono]
            );
            if ($q) {
                $alert_msg = "Negozio modificato con successo!";
                $alert_type = 'success';
            } else {
                $alert_msg = "Errore nella modifica del negozio.";
                $alert_type = 'danger';
            }
        } else {
            $alert_msg = "Non sei autorizzato a modificare questo negozio.";
            $alert_type = 'danger';
        }
    }
}

// Gestione modifica orari (solo per il manager assegnato)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_orari'])) {
    $negozio_id = $_POST['orari_negozio_id'];
    // Verifica che il manager sia quello assegnato
    $check = pg_query_params($db, "SELECT manager_mail FROM greenify.negozio WHERE id = $1", [$negozio_id]);
    $row = pg_fetch_assoc($check);
    if ($row && $row['manager_mail'] === $manager_id) {
        // Prepara nuovi orari da POST
        $nuovi_orari = [];
        foreach ($_POST['giorno'] as $i => $giorno) {
            $ora_inizio = $_POST['ora_inizio'][$i];
            $ora_fine = $_POST['ora_fine'][$i];
            if ($giorno && $ora_inizio && $ora_fine) {
                $nuovi_orari[$giorno] = [
                    'ora_inizio' => $ora_inizio,
                    'ora_fine' => $ora_fine
                ];
            }
        }
        if (aggiorna_orari_negozio($db, $negozio_id, $nuovi_orari)) {
            $alert_msg = "Orari aggiornati con successo!";
            $alert_type = 'success';
        } else {
            $alert_msg = "Errore nell'aggiornamento degli orari.";
            $alert_type = 'danger';
        }
    } else {
        $alert_msg = "Non sei autorizzato a modificare gli orari di questo negozio.";
        $alert_type = 'danger';
    }
}

// Gestione chiusura negozio
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['chiudi_negozio']) && isset($_POST['chiudi_id'])) {
    $negozio_id = $_POST['chiudi_id'];
    // Verifica che il manager sia quello assegnato
    $check = pg_query_params($db, "SELECT manager_mail FROM greenify.negozio WHERE id = $1", [$negozio_id]);
    $row = pg_fetch_assoc($check);
    if ($row && $row['manager_mail'] === $manager_id) {
        // Aggiorna solo il campo aperto, il trigger imposterà data_chiusura
        $q = pg_query_params($db, "UPDATE greenify.negozio SET aperto = false WHERE id = $1", [$negozio_id]);
        if ($q) {
            $alert_msg = "Negozio chiuso con successo!";
            $alert_type = 'success';
        } else {
            $alert_msg = "Errore nella chiusura del negozio.";
            $alert_type = 'danger';
        }
    } else {
        $alert_msg = "Non sei autorizzato a chiudere questo negozio.";
        $alert_type = 'danger';
    }
}

// Recupera negozi e orari
$negozi = [];
$res = pg_query($db, "SELECT n.id, i.citta, i.indirizzo, n.telefono, n.manager_mail, i.id AS indirizzo_id
                      FROM greenify.negozio n
                      JOIN greenify.indirizzo i ON n.indirizzo_id = i.id
                      ORDER BY n.id");
if ($res) {
    while ($row = pg_fetch_assoc($res)) {
        $negozi[] = $row;
    }
    pg_free_result($res);
}

// Orari per tutti i negozi
$orari_per_negozio = [];
foreach ($negozi as $n) {
    $res = pg_query_params($db, 'SELECT * FROM greenify.fn_orari_negozio($1)', [$n['id']]);
    if ($res) {
        while ($row = pg_fetch_assoc($res)) {
            $orari_per_negozio[$n['id']][] = $row;
        }
        pg_free_result($res);
    }
}
?>
<?php include_once '../components/header.php'; ?>

<main class="container py-5">
    <h2>Gestione Negozi</h2>

    <!-- Messaggio di esito operazione -->
    <?php if ($alert_msg): ?>
        <div id="alert-top-right" class="alert alert-<?= $alert_type ?> alert-dismissible fade show position-fixed" style="top: 20px; right: 20px; z-index: 9999; min-width:300px;" role="alert">
            <?= $alert_msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" style="filter: invert(1);"></button>
        </div>
        <script>
            setTimeout(function() {
                var alert = document.getElementById('alert-top-right');
                if (alert) alert.style.display = 'none';
            }, 5000);
        </script>
    <?php endif; ?>

    <!-- Tabella negozi -->
    <div class="card">
        <div class="card-header">
            Lista Negozi
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped mb-0" id="tabella-negozi">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Indirizzo</th>
                            <th>Telefono</th>
                            <th>Manager</th>
                            <th>Orari</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($negozi as $n): ?>
                            <tr>
                                <td><?= htmlspecialchars($n['id']) ?></td>
                                <td>
                                    <span class="negozio-citta" data-citta="<?= htmlspecialchars($n['citta']) ?>"><?= htmlspecialchars($n['citta']) ?></span>,
                                    <span class="negozio-indirizzo" data-indirizzo="<?= htmlspecialchars($n['indirizzo']) ?>"><?= htmlspecialchars($n['indirizzo']) ?></span>
                                </td>
                                <td><?= htmlspecialchars($n['telefono']) ?></td>
                                <td><?= htmlspecialchars($n['manager_mail']) ?></td>
                                <td>
                                    <?php if (!empty($orari_per_negozio[$n['id']])): ?>
                                        <span tabindex="0" class="badge bg-info text-dark orari-popover"
                                            data-bs-toggle="popover"
                                            data-bs-trigger="hover focus"
                                            data-bs-html="true"
                                            data-bs-content="<?php
                                                                $giorni_settimana = ['Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato', 'Domenica'];
                                                                $orari_assoc = [];
                                                                foreach ($orari_per_negozio[$n['id']] as $o) {
                                                                    $orari_assoc[$o['giorno']] = $o;
                                                                }
                                                                foreach ($giorni_settimana as $giorno) {
                                                                    echo htmlspecialchars($giorno) . ': ';
                                                                    if (
                                                                        !isset($orari_assoc[$giorno]) ||
                                                                        $orari_assoc[$giorno]['ora_inizio'] === null ||
                                                                        $orari_assoc[$giorno]['ora_fine'] === null ||
                                                                        $orari_assoc[$giorno]['ora_inizio'] === '' ||
                                                                        $orari_assoc[$giorno]['ora_fine'] === ''
                                                                    ) {
                                                                        echo 'Chiuso';
                                                                    } else {
                                                                        echo htmlspecialchars($orari_assoc[$giorno]['ora_inizio']) . ' - ' . htmlspecialchars($orari_assoc[$giorno]['ora_fine']);
                                                                    }
                                                                    echo '<br>';
                                                                }
                                                                ?>"
                                            style="cursor: pointer; pointer-events: auto;" onclick="event.preventDefault(); event.stopPropagation(); return false;">Visualizza</span>
                                    <?php else: ?>
                                        <span class="text-muted">Nessun orario</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    // Recupera stato aperto/chiuso
                                    $is_aperto = true;
                                    $res_aperto = pg_query_params($db, "SELECT aperto FROM greenify.negozio WHERE id = $1", [$n['id']]);
                                    if ($res_aperto && ($row_aperto = pg_fetch_assoc($res_aperto))) {
                                        $is_aperto = ($row_aperto['aperto'] === 't' || $row_aperto['aperto'] === true || $row_aperto['aperto'] == 1);
                                        pg_free_result($res_aperto);
                                    }
                                    ?>
                                    <?php if (!$is_aperto): ?>
                                        <button class="btn btn-sm btn-secondary" disabled>CHIUSO definitivamente</button>
                                    <?php elseif ($n['manager_mail'] === $manager_id): ?>
                                        <button
                                            class="btn btn-sm btn-outline-primary btn-edit-negozio"
                                            data-id="<?= htmlspecialchars($n['id']) ?>"
                                            data-indirizzo="<?= htmlspecialchars($n['indirizzo']) ?>"
                                            data-citta="<?= htmlspecialchars($n['citta']) ?>"
                                            data-telefono="<?= htmlspecialchars($n['telefono']) ?>">Modifica</button>
                                        <button
                                            class="btn btn-sm btn-outline-secondary btn-edit-orari"
                                            data-id="<?= htmlspecialchars($n['id']) ?>">Orari</button>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-danger btn-chiudi-negozio"
                                            data-id="<?= htmlspecialchars($n['id']) ?>"
                                            data-citta="<?= htmlspecialchars($n['citta']) ?>"
                                            data-indirizzo="<?= htmlspecialchars($n['indirizzo']) ?>"
                                        >Chiudi</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($negozi)): ?>
                            <tr>
                                <td colspan="6" class="text-center">Nessun negozio trovato.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- MODAL CONFERMA CHIUSURA NEGOZIO -->
    <div class="modal fade" id="modalConfermaChiusuraNegozio" tabindex="-1" aria-labelledby="modalConfermaChiusuraNegozioLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form method="post" action="">
                <input type="hidden" name="chiudi_id" id="chiudi_id_modal">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title" id="modalConfermaChiusuraNegozioLabel">Conferma chiusura definitiva</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                    </div>
                    <div class="modal-body">
                        <p>Sei sicuro di voler <b>chiudere definitivamente</b> il negozio:</p>
                        <div class="mb-2">
                            <span class="fw-bold" id="negozio_citta_modal"></span>,
                            <span class="fw-bold" id="negozio_indirizzo_modal"></span>
                        </div>
                        <div class="alert alert-warning mt-3 mb-0">
                            <b>Attenzione:</b> Questa operazione è irreversibile.<br>
                            Tutte le tessere emesse da questo negozio verranno disattivate.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" name="chiudi_negozio" value="1" class="btn btn-danger">Conferma chiusura</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Popover orari
            var popoverTriggerList = [].slice.call(document.querySelectorAll('.orari-popover'));
            popoverTriggerList.forEach(function(popoverTriggerEl) {
                new bootstrap.Popover(popoverTriggerEl);
                // Impedisci il click sul badge orari
                popoverTriggerEl.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                });
            });

            // Bottone aggiungi negozio: mostra modal solo se ci sono manager disponibili
            var btnAggiungi = document.getElementById('btnAggiungiNegozio');
            <?php if (empty($manager_disponibili)): ?>
                if (btnAggiungi) {
                    btnAggiungi.removeAttribute('data-bs-toggle');
                    btnAggiungi.removeAttribute('data-bs-target');
                    btnAggiungi.addEventListener('click', function(e) {
                        e.preventDefault();
                        var modal = new bootstrap.Modal(document.getElementById('modalNoManager'));
                        modal.show();
                        setTimeout(function() {
                            var m = bootstrap.Modal.getInstance(document.getElementById('modalNoManager'));
                            if (m) m.hide();
                        }, 5000);
                    });
                }
            <?php endif; ?>

            // Modifica negozio
            var editNegozioButtons = document.querySelectorAll('.btn-edit-negozio');
            var editNegozioModalEl = document.getElementById('editNegozioModal');
            var editNegozioModal = new bootstrap.Modal(editNegozioModalEl);

            editNegozioButtons.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    document.getElementById('edit_id').value = btn.getAttribute('data-id');
                    document.getElementById('edit_indirizzo').value = btn.getAttribute('data-indirizzo');
                    document.getElementById('edit_citta').value = btn.getAttribute('data-citta');
                    document.getElementById('edit_telefono').value = btn.getAttribute('data-telefono');
                    editNegozioModal.show();
                });
            });

            // Modifica orari - precompila i campi con gli orari esistenti
            var orariPerNegozio = <?=
                                    json_encode($orari_per_negozio, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)
                                    ?>;

            var editOrariButtons = document.querySelectorAll('.btn-edit-orari');
            editOrariButtons.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    if (btn.disabled) return;
                    var negozioId = btn.getAttribute('data-id');
                    document.getElementById('orari_negozio_id').value = negozioId;

                    // Reset campi
                    var oraInizioInputs = document.querySelectorAll('#editOrariModal .ora-inizio-orario');
                    var oraFineInputs = document.querySelectorAll('#editOrariModal .ora-fine-orario');
                    for (var i = 0; i < oraInizioInputs.length; i++) {
                        oraInizioInputs[i].value = '';
                        oraFineInputs[i].value = '';
                    }

                    // Precompila se ci sono orari (già ordinati lato SQL)
                    if (orariPerNegozio[negozioId]) {
                        var orari = orariPerNegozio[negozioId];
                        for (var i = 0; i < orari.length; i++) {
                            oraInizioInputs[i].value = orari[i]['ora_inizio'];
                            oraFineInputs[i].value = orari[i]['ora_fine'];
                        }
                    }
                    var modal = new bootstrap.Modal(document.getElementById('editOrariModal'));
                    modal.show();
                });
            });

            // Per ogni negozio, prepara la lista manager disponibili (incluso il manager attuale)
            var managerDisponibiliPerNegozio = {};
            <?php foreach ($negozi as $n): ?>
                managerDisponibiliPerNegozio[<?= json_encode($n['id']) ?>] = <?= json_encode(get_manager_disponibili($db, $n['manager_mail'])) ?>;
            <?php endforeach; ?>

            var editNegozioButtons = document.querySelectorAll('.btn-edit-negozio');
            editNegozioButtons.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var negozioId = btn.getAttribute('data-id');
                    document.getElementById('edit_id').value = negozioId;
                    document.getElementById('edit_indirizzo').value = btn.getAttribute('data-indirizzo');
                    document.getElementById('edit_citta').value = btn.getAttribute('data-citta');
                    document.getElementById('edit_telefono').value = btn.getAttribute('data-telefono');

                    var select = document.getElementById('edit_manager_mail');
                    select.innerHTML = '';
                    var managers = managerDisponibiliPerNegozio[negozioId] || [];
                    var currentManager = <?php
                                            $map = [];
                                            foreach ($negozi as $n) $map[$n['id']] = $n['manager_mail'];
                                            echo json_encode($map);
                                            ?>[negozioId];
                    managers.forEach(function(mail) {
                        var opt = document.createElement('option');
                        opt.value = mail;
                        opt.textContent = mail;
                        if (mail === currentManager) opt.selected = true;
                        select.appendChild(opt);
                    });
                    editNegozioModal.show();
                });
            });

            // Modal conferma chiusura negozio
            var chiudiButtons = document.querySelectorAll('.btn-chiudi-negozio');
            chiudiButtons.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    document.getElementById('chiudi_id_modal').value = btn.getAttribute('data-id');
                    document.getElementById('negozio_citta_modal').textContent = btn.getAttribute('data-citta');
                    document.getElementById('negozio_indirizzo_modal').textContent = btn.getAttribute('data-indirizzo');
                    var modal = new bootstrap.Modal(document.getElementById('modalConfermaChiusuraNegozio'));
                    modal.show();
                });
            });

        });
    </script>
</main>

<!-- MODAL EDITA NEGOZIO -->
<div class="modal fade" id="editNegozioModal" tabindex="-1" aria-labelledby="editNegozioModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editNegozioModalLabel">Modifica Negozio</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_indirizzo" class="form-label">Indirizzo *</label>
                        <input type="text" class="form-control" id="edit_indirizzo" name="edit_indirizzo" required maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label for="edit_citta" class="form-label">Città *</label>
                        <input type="text" class="form-control" id="edit_citta" name="edit_citta" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label for="edit_telefono" class="form-label">Telefono *</label>
                        <input type="text" class="form-control" id="edit_telefono" name="edit_telefono" required pattern="[0-9]{10}" maxlength="10" minlength="10" title="Inserisci 10 cifre numeriche">
                    </div>
                    <input type="hidden" name="edit_negozio" value="1">
                    <input type="hidden" id="edit_id" name="edit_id" value="">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary">Salva Modifiche</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- MODAL EDITA ORARI -->
<div class="modal fade" id="editOrariModal" tabindex="-1" aria-labelledby="editOrariModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editOrariModalLabel">Modifica Orari Negozio</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="alert alert-info py-2 mb-2" style="font-size:0.95em;">
                            Lascia vuoti i campi per i giorni di chiusura.
                        </div>
                        <?php
                        $giorni = ['Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato', 'Domenica'];
                        foreach ($giorni as $i => $giorno): ?>
                            <div class="row mb-2 align-items-center">
                                <div class="col-4">
                                    <input type="text" class="form-control" name="giorno[]" value="<?= $giorno ?>" readonly>
                                </div>
                                <div class="col-4">
                                    <input type="time" class="form-control ora-inizio-orario" name="ora_inizio[]">
                                </div>
                                <div class="col-4">
                                    <input type="time" class="form-control ora-fine-orario" name="ora_fine[]">
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="edit_orari" value="1">
                    <input type="hidden" name="orari_negozio_id" id="orari_negozio_id">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary">Salva Modifiche Orari</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php include_once '../components/footer.php'; ?>
<?php
if ($db) close_pg_connection($db);
?>