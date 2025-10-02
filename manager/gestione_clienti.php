<?php
session_start();
if (!isset($_SESSION['manager_id'])) {
    header('Location: ../pages/login_manager.php');
    exit();
}
require_once __DIR__ . '/../db/connector.php';
require_once __DIR__ . '/../includes/functions.php'; // Per la funzione registra_utente_cliente

$db = open_pg_connection();
$clienti = [];
$tessere_attive = [];
$alert_msg = null;
$alert_type = 'info';

// Funzione per caricare la lista clienti
function load_clienti($db)
{
    $clienti = [];
    $sql = "SELECT c.cf, c.nome, c.cognome, c.data_nascita, c.data_iscrizione, c.mail, u.telefono, u.attivo::int AS attivo
            FROM greenify.cliente c
            JOIN greenify.utente u ON c.mail = u.mail
            ORDER BY c.data_iscrizione DESC";
    $res = pg_query($db, $sql);
    if ($res) {
        while ($row = pg_fetch_assoc($res)) {
            $clienti[] = $row;
        }
        pg_free_result($res);
    }
    return $clienti;
}

// Funzione per caricare la lista tessere attive
function load_tessere_attive($db)
{
    $tessere_attive = [];
    $sql = "SELECT c.nome, c.cognome, t.id, t.punti, t.data_scadenza, n.indirizzo, t.attiva
            FROM greenify.tessera t
            JOIN greenify.cliente c ON t.cliente_cf = c.cf
            JOIN greenify.negozio n ON t.negozio_id = n.id
            ORDER BY t.id DESC";
    $res = pg_query($db, $sql);
    if ($res) {
        while ($row = pg_fetch_assoc($res)) {
            $tessere_attive[] = $row;
        }
        pg_free_result($res);
    }
    return $tessere_attive;
}

// --- AGGIORNAMENTO DATI CLIENTI E TESSERE ATTIVE ---
function aggiorna_liste(&$clienti, &$tessere_attive, $db)
{
    $clienti = load_clienti($db);
    $tessere_attive = load_tessere_attive($db);
}

// --- OPERAZIONI POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Attivazione/disattivazione utente
    if (isset($_POST['toggle_attivo']) && isset($_POST['mail_toggle'])) {
        $mail = $_POST['mail_toggle'];
        $toggle = $_POST['toggle_attivo'] === '1' ? 'true' : 'false';
        $q1 = pg_query_params($db, "UPDATE greenify.utente SET attivo = $1 WHERE mail = $2", [$toggle, $mail]);
        if ($q1) {
            $alert_msg = $toggle === 'true' ? "Utente attivato con successo." : "Utente disattivato con successo.";
            $alert_type = 'success';
        } else {
            $alert_msg = "Errore nell'aggiornamento dello stato utente.";
            $alert_type = 'danger';
        }
        aggiorna_liste($clienti, $tessere_attive, $db);
    }
    // Modifica cliente
    elseif (isset($_POST['edit_cliente'])) {
        $old_cf = $_POST['edit_cf_old'];
        $old_mail = $_POST['edit_mail_old'];
        $cf = strtoupper(trim($_POST['edit_cf']));
        $nome = trim($_POST['edit_nome']);
        $cognome = trim($_POST['edit_cognome']);
        $telefono = trim($_POST['edit_telefono']);
        $data_nascita = !empty($_POST['edit_data_nascita']) ? $_POST['edit_data_nascita'] : null;
        $mail = strtolower(trim($_POST['edit_mail']));

        // Validazione lato server 
        if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            $alert_msg = "Email non valida.";
            $alert_type = 'danger';
        } elseif (!preg_match('/^[A-Z0-9]{16}$/', $cf)) {
            $alert_msg = "Codice fiscale non valido (16 caratteri alfanumerici).";
            $alert_type = 'danger';
        } elseif (!preg_match('/^[0-9]{10}$/', $telefono)) {
            $alert_msg = "Telefono non valido (10 cifre).";
            $alert_type = 'danger';
        } else {
            $res = pg_query_params(
                $db,
                "SELECT greenify.fn_modifica_cliente($1, $2, $3, $4, $5, $6, $7, $8) AS ok",
                [$old_cf, $old_mail, $cf, $mail, $nome, $cognome, $telefono, $data_nascita]
            );
            if (!$res) {
                $alert_msg = "Errore SQL: " . htmlspecialchars(pg_last_error($db));
                $alert_type = 'danger';
            }
            $ok = $res && ($row = pg_fetch_assoc($res)) && $row['ok'];
            if ($res) pg_free_result($res);
            if ($ok) {
                $alert_msg = "Cliente modificato con successo!";
                $alert_type = 'success';
            } else {
                $alert_msg = "Errore nella modifica del cliente.";
                $alert_type = 'danger';
            }
            aggiorna_liste($clienti, $tessere_attive, $db);
        }
    }
    // Attivazione/disattivazione tessera
    elseif (isset($_POST['toggle_tessera_attiva']) && isset($_POST['tessera_id'])) {
        $tessera_id = $_POST['tessera_id'];
        $toggle = $_POST['toggle_tessera_attiva'] === '1' ? 'true' : 'false';
        $q = pg_query_params($db, "UPDATE greenify.tessera SET attiva = $1 WHERE id = $2", [$toggle, $tessera_id]);
        if ($q) {
            $alert_msg = $toggle === 'true' ? "Tessera attivata con successo." : "Tessera disattivata con successo.";
            $alert_type = 'success';
        } else {
            $alert_msg = "Errore nell'aggiornamento dello stato tessera.";
            $alert_type = 'danger';
        }
        aggiorna_liste($clienti, $tessere_attive, $db);
    }
    // Modifica punti tessera
    elseif (isset($_POST['edit_tessera_punti']) && isset($_POST['edit_tessera_id'])) {
        $tessera_id = $_POST['edit_tessera_id'];
        $punti = intval($_POST['edit_tessera_punti']);
        $q = pg_query_params($db, "UPDATE greenify.tessera SET punti = $1 WHERE id = $2", [$punti, $tessera_id]);
        if ($q) {
            $alert_msg = "Punti tessera aggiornati con successo.";
            $alert_type = 'success';
        } else {
            $alert_msg = "Errore nell'aggiornamento dei punti tessera.";
            $alert_type = 'danger';
        }
        // Solo tessere attive da aggiornare
        $tessere_attive = load_tessere_attive($db);
    }
}

if (empty($clienti)) {
    aggiorna_liste($clienti, $tessere_attive, $db);
}

// Gestione filtro tessere per negozio
$tessere_negozio = [];
$negozio_selezionato = null;
if (isset($_POST['negozio_select'])) {
    $negozio_selezionato = $_POST['negozio_select'];
    $sql = "SELECT t.id, t.punti, t.data_scadenza, t.attiva, c.nome, c.cognome
            FROM greenify.tessera t
            JOIN greenify.cliente c ON t.cliente_cf = c.cf
            WHERE t.negozio_id = $1
            ORDER BY t.id DESC";
    $res = pg_query_params($db, $sql, [$negozio_selezionato]);
    if ($res) {
        while ($row = pg_fetch_assoc($res)) {
            $tessere_negozio[] = $row;
        }
        pg_free_result($res);
    }
}

// Recupera clienti premium dalla view ClientiPremium
$clienti_premium = [];
if ($db) {
    $sql = 'SELECT * FROM greenify."ClientiPremium"';
    $res = pg_query($db, $sql);
    if ($res) {
        while ($row = pg_fetch_assoc($res)) {
            $clienti_premium[] = $row;
        }
        pg_free_result($res);
    }
}


// Recupera tutte le tessere
$tessere = [];
$sql = "SELECT * FROM greenify.tessere_complete";
$res = pg_query($db, $sql);
if ($res) {
    while ($row = pg_fetch_assoc($res)) {
        $tessere[] = $row;
    }
    pg_free_result($res);
}

// Filtro negozio selezionato per storico tessere
$negozio_id_storico = isset($_GET['storico_negozio_id']) ? intval($_GET['storico_negozio_id']) : null;

// Recupera storico tessere emesse SOLO da negozi chiusi, con info negozio e cliente
$storico_tessere = [];
$res = pg_query($db, "SELECT * FROM greenify.storico_tessere");
if ($res) {
    while ($row = pg_fetch_assoc($res)) {
        $storico_tessere[] = $row;
    }
    pg_free_result($res);
}

// Recupera negozi per la select e per i filtri
$negozi = [];
$sql = "SELECT n.id, i.citta, i.indirizzo
        FROM greenify.negozio n
        JOIN greenify.indirizzo i ON n.indirizzo_id = i.id
        ORDER BY i.citta, n.id";
$res_negozi = pg_query($db, $sql);
if ($res_negozi) {
    while ($row = pg_fetch_assoc($res_negozi)) {
        $negozi[] = $row;
    }
    pg_free_result($res_negozi);
}

if ($db) close_pg_connection($db);
?>
<?php include_once '../components/header.php'; ?>

<main class="container py-5">
    <h2>Gestione Clienti</h2>

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

    <!-- Tabella aggregata utenti/clienti -->
    <div class="card mb-4">
        <div class="card-header">
            Lista Clienti
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped mb-0" id="tabella-clienti">
                    <thead>
                        <tr>
                            <th>Codice Fiscale</th>
                            <th>Nome</th>
                            <th>Cognome</th>
                            <th>Email</th>
                            <th>Telefono</th>
                            <th>Data Nascita</th>
                            <th>Data Iscrizione</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clienti as $c): ?>
                            <tr<?= ($c['attivo'] == 0) ? ' style="opacity:0.5;background-color:#f8f9fa;"' : '' ?>>
                                <td><?= htmlspecialchars($c['cf']) ?></td>
                                <td><?= htmlspecialchars($c['nome']) ?></td>
                                <td><?= htmlspecialchars($c['cognome']) ?></td>
                                <td><?= htmlspecialchars($c['mail']) ?></td>
                                <td><?= htmlspecialchars($c['telefono']) ?></td>
                                <td><?= htmlspecialchars($c['data_nascita']) ?></td>
                                <td><?= htmlspecialchars($c['data_iscrizione']) ?></td>
                                <td>
                                    <?php if ($c['attivo'] == 1): ?>
                                        <button
                                            class="btn btn-sm btn-outline-primary btn-edit-cliente"
                                            data-cf="<?= htmlspecialchars($c['cf']) ?>"
                                            data-nome="<?= htmlspecialchars($c['nome']) ?>"
                                            data-cognome="<?= htmlspecialchars($c['cognome']) ?>"
                                            data-telefono="<?= htmlspecialchars($c['telefono']) ?>"
                                            data-data_nascita="<?= htmlspecialchars($c['data_nascita']) ?>"
                                            data-mail="<?= htmlspecialchars($c['mail']) ?>">Modifica</button>
                                    <?php endif; ?>
                                    <form method="post" action="" style="display:inline;">
                                        <input type="hidden" name="mail_toggle" value="<?= htmlspecialchars($c['mail']) ?>">
                                        <?php if ($c['attivo'] == 1): ?>
                                            <button type="submit" name="toggle_attivo" value="0" class="btn btn-sm btn-danger">Disattiva</button>
                                        <?php else: ?>
                                            <button type="submit" name="toggle_attivo" value="1" class="btn btn-sm btn-success">Attiva</button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($clienti)): ?>
                                <tr>
                                    <td colspan="8" class="text-center">Nessun cliente trovato.</td>
                                </tr>
                            <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- MODAL MODIFICA CLIENTE -->
    <div class="modal fade" id="editClienteModal" tabindex="-1" aria-labelledby="editClienteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form method="post" action="">
                <input type="hidden" name="edit_cliente" value="1">
                <input type="hidden" name="edit_cf_old" id="edit_cf_old">
                <input type="hidden" name="edit_mail_old" id="edit_mail_old">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editClienteModalLabel">Modifica Cliente</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_cf" class="form-label">Codice Fiscale</label>
                            <input type="text" class="form-control" id="edit_cf" name="edit_cf" required pattern="[A-Za-z0-9]{16}" maxlength="16" minlength="16" title="16 caratteri alfanumerici">
                        </div>
                        <div class="mb-3">
                            <label for="edit_nome" class="form-label">Nome</label>
                            <input type="text" class="form-control" id="edit_nome" name="edit_nome" required maxlength="100">
                        </div>
                        <div class="mb-3">
                            <label for="edit_cognome" class="form-label">Cognome</label>
                            <input type="text" class="form-control" id="edit_cognome" name="edit_cognome" required maxlength="100">
                        </div>
                        <div class="mb-3">
                            <label for="edit_mail" class="form-label">Email</label>
                            <input type="email" class="form-control" id="edit_mail" name="edit_mail" required maxlength="255">
                        </div>
                        <div class="mb-3">
                            <label for="edit_telefono" class="form-label">Telefono</label>
                            <input type="text" class="form-control" id="edit_telefono" name="edit_telefono" required pattern="[0-9]{10}" maxlength="10" minlength="10" title="Inserisci 10 cifre numeriche">
                        </div>
                        <div class="mb-3">
                            <label for="edit_data_nascita" class="form-label">Data di nascita</label>
                            <input type="date" class="form-control" id="edit_data_nascita" name="edit_data_nascita">
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
            var editButtons = document.querySelectorAll('.btn-edit-cliente');
            editButtons.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    document.getElementById('edit_cf').value = btn.getAttribute('data-cf');
                    document.getElementById('edit_cf_old').value = btn.getAttribute('data-cf');
                    document.getElementById('edit_nome').value = btn.getAttribute('data-nome');
                    document.getElementById('edit_cognome').value = btn.getAttribute('data-cognome');
                    document.getElementById('edit_telefono').value = btn.getAttribute('data-telefono');
                    document.getElementById('edit_data_nascita').value = btn.getAttribute('data-data_nascita');
                    document.getElementById('edit_mail').value = btn.getAttribute('data-mail');
                    document.getElementById('edit_mail_old').value = btn.getAttribute('data-mail');
                    var modal = new bootstrap.Modal(document.getElementById('editClienteModal'));
                    modal.show();
                });
            });
        });
    </script>
    <!-- Tessere Attive -->
    <div class="card mb-4">
        <div class="card-header bg-info text-white">
            Tessere
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped mb-0">
                    <thead>
                        <tr>
                            <th>ID Tessera</th>
                            <th>Cliente</th>
                            <th>Negozio</th>
                            <th>Punti</th>
                            <th>Scadenza</th>
                            <th>Stato</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tessere)): ?>
                            <tr>
                                <td colspan="7" class="text-center">Nessuna tessera trovata.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($tessere as $t): ?>
                                <tr<?= ($t['attiva'] === 'f' || $t['attiva'] === false || $t['attiva'] == 0) ? ' style="opacity:0.5;background-color:#f8f9fa;"' : '' ?>>
                                    <td><?= htmlspecialchars($t['id']) ?></td>
                                    <td><?= htmlspecialchars($t['nome']) ?> <?= htmlspecialchars($t['cognome']) ?></td>
                                    <td>
                                        <?= htmlspecialchars($t['citta']) ?>,
                                        <?= htmlspecialchars($t['indirizzo']) ?>
                                        <span class="text-muted small ms-1">(#<?= htmlspecialchars($t['negozio_id']) ?>)</span>
                                    </td>
                                    <td><?= htmlspecialchars($t['punti']) ?></td>
                                    <td><?= htmlspecialchars($t['data_scadenza']) ?></td>
                                    <td>
                                        <?php if ($t['attiva'] === 't' || $t['attiva'] === true || $t['attiva'] == 1): ?>
                                            <span class="badge bg-success">Attiva</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Disattivata</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $is_attiva = ($t['attiva'] === 't' || $t['attiva'] === true || $t['attiva'] == 1);
                                        ?>
                                        <?php if ($is_attiva): ?>
                                            <form method="post" action="" style="display:inline;">
                                                <input type="hidden" name="tessera_id" value="<?= htmlspecialchars($t['id']) ?>">
                                                <button type="submit" name="toggle_tessera_attiva" value="0" class="btn btn-sm btn-danger">Disattiva</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post" action="" style="display:inline;">
                                                <input type="hidden" name="tessera_id" value="<?= htmlspecialchars($t['id']) ?>">
                                                <button type="submit" name="toggle_tessera_attiva" value="1" class="btn btn-sm btn-success">Attiva</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Lista clienti premium -->
    <div class="card mb-4">
        <div class="card-header bg-warning text-dark d-flex align-items-center" style="gap:8px;">
            <i class="bi bi-star-fill" style="color:#ffc107;font-size:1.5em;"></i>
            Clienti Premium
        </div>
        <div class="card-body p-0">
            <div class="mb-3 px-3 pt-3" style="font-size:1.05em;">
                <span class="text-muted">
                    Sono considerati <b>clienti premium</b> quelli con più di 300 punti sulla tessera fedeltà.
                </span>
            </div>
            <?php if (empty($clienti_premium)): ?>
                <em class="d-block p-3">Nessun cliente premium trovato.</em>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped mb-0">
                        <thead>
                            <tr>
                                <th>CF</th>
                                <th>Nome</th>
                                <th>Cognome</th>
                                <th>Email</th>
                                <th>Punti</th>
                                <th>Negozio di emissione</th>
                                <th>Data di emissione</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clienti_premium as $cp): ?>
                                <tr>
                                    <td><?= htmlspecialchars($cp['cf']) ?></td>
                                    <td><?= htmlspecialchars($cp['nome']) ?></td>
                                    <td><?= htmlspecialchars($cp['cognome']) ?></td>
                                    <td><?= htmlspecialchars($cp['mail']) ?></td>
                                    <td><?= htmlspecialchars($cp['punti']) ?></td>
                                    <td>
                                        <?php
                                        // Cerca il negozio tra $negozi per mostrare città e id
                                        $negozio = null;
                                        foreach ($negozi as $n) {
                                            if ($n['id'] == $cp['negozio_id']) {
                                                $negozio = $n;
                                                break;
                                            }
                                        }
                                        if ($negozio) {
                                            echo htmlspecialchars($negozio['citta']) . ' <span class="text-muted small ms-1">(#' . htmlspecialchars($negozio['id']) . ')</span>';
                                        } else {
                                            echo htmlspecialchars($cp['citta_negozio_emissione']);
                                            if (isset($cp['negozio_id'])) {
                                                echo ' <span class="text-muted small ms-1">(#' . htmlspecialchars($cp['negozio_id']) . ')</span>';
                                            }
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        $dt = $cp['data_emissione'];
                                        if ($dt) {
                                            $dt_fmt = date('d/m/Y H:i', strtotime($dt));
                                            echo htmlspecialchars($dt_fmt);
                                        } else {
                                            echo '';
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Card Tessere per Negozio -->
    <div class="card mb-4">
        <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
            <span>Tessere per Negozio</span>
            <form method="post" class="d-flex align-items-center gap-2 mb-0">
                <label for="negozio_select" class="mb-0 me-2">Negozio:</label>
                <select name="negozio_select" id="negozio_select" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ($negozi as $n): ?>
                        <option value="<?= htmlspecialchars($n['id']) ?>" <?= (isset($negozio_selezionato) && $negozio_selezionato == $n['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($n['citta']) ?> (#<?= htmlspecialchars($n['id']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <div class="card-body">
            <?php if ($negozio_selezionato): ?>
                <?php if (empty($tessere_negozio)): ?>
                    <em>Nessuna tessera emessa da questo negozio.</em>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped">
                            <thead>
                                <tr>
                                    <th>ID Tessera</th>
                                    <th>Cliente</th>
                                    <th>Punti</th>
                                    <th>Scadenza</th>
                                    <th>Stato</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tessere_negozio as $t): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($t['id']) ?></td>
                                        <td><?= htmlspecialchars($t['nome']) ?> <?= htmlspecialchars($t['cognome']) ?></td>
                                        <td><?= htmlspecialchars($t['punti']) ?></td>
                                        <td><?= htmlspecialchars($t['data_scadenza']) ?></td>
                                        <td>
                                            <?php if ($t['attiva'] === 't' || $t['attiva'] === true || $t['attiva'] == 1): ?>
                                                <span class="badge bg-success">Attiva</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Disattivata</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <em>Seleziona un negozio per vedere le tessere emesse.</em>
            <?php endif; ?>
        </div>
    </div>

    <!-- Storico Tessere Emesse -->
    <div class="card mb-4">
        <div class="card-header bg-dark text-white">
            Storico Tessere Emesse (solo negozi chiusi)
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <?php if (empty($storico_tessere)): ?>
                    <em class="d-block p-3">Nessuna tessera storica trovata.</em>
                <?php else: ?>
                    <table class="table table-sm table-striped mb-0">
                        <thead>
                            <tr>
                                <th>ID Tessera</th>
                                <th>Cliente</th>
                                <th>Punti</th>
                                <th>Data Rilascio</th>
                                <th>Negozio</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($storico_tessere as $t): ?>
                                <tr>
                                    <td><?= htmlspecialchars($t['tessera_id']) ?></td>
                                    <td><?= htmlspecialchars($t['nome']) ?> <?= htmlspecialchars($t['cognome']) ?></td>
                                    <td><?= htmlspecialchars($t['punti']) ?></td>
                                    <td>
                                        <?php
                                        $dt = $t['data_rilascio'];
                                        if ($dt) {
                                            $dt_fmt = date('d/m/Y H:i', strtotime($dt));
                                            echo htmlspecialchars($dt_fmt);
                                        } else {
                                            echo '';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($t['citta']) ?> <span class="text-muted small ms-1">(#<?= htmlspecialchars($t['negozio_id']) ?>)</span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

</main>
<?php include_once '../components/footer.php'; ?>