<?php
// account.php - Pagina gestione account utente
session_start();
require_once __DIR__ . '/../db/connector.php';

$db = open_pg_connection();
$alert_msg = null;
$alert_type = 'info';

// Determina il tipo di utente loggato
$isUser = isset($_SESSION['user_id']);
$isManager = isset($_SESSION['manager_id']);

if (!$isUser && !$isManager) {
    header('Location: login.php');
    exit();
}

// Recupera dati attuali
if ($isUser) {
    $mail = $_SESSION['user_id'];
    $res = pg_query_params($db, "SELECT mail FROM greenify.utente WHERE mail=$1", [$mail]);
    $row = pg_fetch_assoc($res);
} elseif ($isManager) {
    $mail = $_SESSION['manager_id'];
    $res = pg_query_params($db, "SELECT mail FROM greenify.manager WHERE mail=$1", [$mail]);
    $row = pg_fetch_assoc($res);
}
$current_mail = $row ? $row['mail'] : '';

// Modifica mail
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_mail'])) {
    $new_mail = trim($_POST['new_mail']);
    if ($new_mail === '') {
        $alert_msg = "La mail non può essere vuota.";
        $alert_type = 'danger';
    } else {
        if ($isUser) {
            $q = pg_query_params($db, "UPDATE greenify.utente SET mail=$1 WHERE mail=$2", [$new_mail, $mail]);
            if ($q) {
                $_SESSION['user_id'] = $new_mail;
                $alert_msg = "Mail aggiornata!";
                $alert_type = 'success';
                $mail = $new_mail;
            } else {
                $alert_msg = "Errore nell'aggiornamento della mail.";
                $alert_type = 'danger';
            }
        } elseif ($isManager) {
            // Aggiorna SOLO la mail in utente (ON UPDATE CASCADE aggiorna anche manager)
            $q = pg_query_params($db, "UPDATE greenify.utente SET mail=$1 WHERE mail=$2", [$new_mail, $mail]);
            if ($q) {
                $_SESSION['manager_id'] = $new_mail;
                $alert_msg = "Mail aggiornata!";
                $alert_type = 'success';
                $mail = $new_mail;
            } else {
                $alert_msg = "Errore nell'aggiornamento della mail.";
                $alert_type = 'danger';
            }
        }
    }
}

// Modifica password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_password'])) {
    $old_password = trim($_POST['old_password']);
    $new_password = trim($_POST['new_password']);
    if ($old_password === '' || $new_password === '') {
        $alert_msg = "Inserisci sia la password attuale che quella nuova.";
        $alert_type = 'danger';
    } else {
        $ok = false;
        if ($isUser) {
            $res = pg_query_params($db, "SELECT password FROM greenify.utente WHERE mail=$1", [$mail]);
            $row = pg_fetch_assoc($res);
            if ($row && password_verify($old_password, $row['password'])) {
                $q = pg_query_params($db, "UPDATE greenify.utente SET password=$1 WHERE mail=$2", [password_hash($new_password, PASSWORD_DEFAULT), $mail]);
                $ok = $q ? true : false;
            }
        } elseif ($isManager) {
            // Aggiorna password anche in utente (ON UPDATE CASCADE non agisce sui dati, solo sulle chiavi)
            $res = pg_query_params($db, "SELECT password FROM greenify.utente WHERE mail=$1", [$mail]);
            $row = pg_fetch_assoc($res);
            if ($row && password_verify($old_password, $row['password'])) {
                $hash = password_hash($new_password, PASSWORD_DEFAULT);
                $q = pg_query_params($db, "UPDATE greenify.utente SET password=$1 WHERE mail=$2", [$hash, $mail]);
                $ok = $q ? true : false;
            }
        }
        if ($ok) {
            $alert_msg = "Password aggiornata!";
            $alert_type = 'success';
        } else {
            $alert_msg = "La password attuale non è corretta o errore nell'aggiornamento.";
            $alert_type = 'danger';
        }
    }
}

if ($db) close_pg_connection($db);
?>
<?php include_once '../components/header.php'; ?>

<main class="container py-5">
    <h2>Gestione Account</h2>

    <?php if ($alert_msg): ?>
        <div class="alert alert-<?= $alert_type ?> alert-dismissible fade show" role="alert">
            <?= $alert_msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
        </div>
    <?php endif; ?>

    <?php if ($isUser): ?>
        <?php
        // Riquadro tessera: saldo punti e opzioni sconto
        require_once __DIR__ . '/../db/connector.php';
        $db2 = open_pg_connection();
        $cf = null;
        $tessera_punti = null;
        $opzioni_sconto = [];
        $res_cf = pg_query_params($db2, 'SELECT cf FROM greenify.cliente WHERE mail = $1', [$mail]);
        if ($res_cf && pg_num_rows($res_cf) > 0) {
            $cf = pg_fetch_result($res_cf, 0, 0);
        }
        if ($cf) {
            $res_tessera = pg_query_params($db2, 'SELECT punti FROM greenify.tessera WHERE cliente_cf = $1 AND attiva = true', [$cf]);
            if ($res_tessera && pg_num_rows($res_tessera) > 0) {
                $tessera_punti = intval(pg_fetch_result($res_tessera, 0, 0));
                // Recupera opzioni sconto disponibili
                $res_opz = pg_query_params($db2, 'SELECT * FROM greenify.fn_opzioni_sconto_per_punti($1)', [$tessera_punti]);
                if ($res_opz) {
                    while ($row = pg_fetch_assoc($res_opz)) {
                        $opzioni_sconto[] = [
                            'sconto_pct' => intval($row['sconto_pct']),
                            'punti_richiesti' => intval($row['punti_richiesti'])
                        ];
                    }
                    pg_free_result($res_opz);
                }
            }
            if ($res_tessera) pg_free_result($res_tessera);
        }
        if ($db2) close_pg_connection($db2);
        ?>
        <div class="card mb-4" style="max-width:400px;">
            <div class="card-header bg-success text-white">
                Tessera Fedeltà
            </div>
            <div class="card-body">
                <?php if ($tessera_punti === null): ?>
                    <div class="text-danger">Non possiedi ancora una tessera.</div>
                <?php else: ?>
                    <div class="mb-2">
                        <b>Saldo punti:</b> <?= $tessera_punti ?>
                    </div>
                    <?php if (!empty($opzioni_sconto)): ?>
                        <div>
                            <b>Sconti disponibili:</b>
                            <ul class="mb-0">
                                <?php foreach ($opzioni_sconto as $opz): ?>
                                    <li><?= $opz['sconto_pct'] ?>% di sconto con <?= $opz['punti_richiesti'] ?> punti</li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php else: ?>
                        <div class="text-muted">Nessuno sconto disponibile al momento.</div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header">
            Modifica Email
        </div>
        <div class="card-body">
            <form method="post" action="">
                <div class="mb-3">
                    <label for="new_mail" class="form-label">Nuova Email</label>
                    <input type="email" class="form-control" id="new_mail" name="new_mail" required maxlength="255" value="<?= htmlspecialchars($mail) ?>">
                </div>
                <button type="submit" name="edit_mail" class="btn btn-primary">Aggiorna Email</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            Modifica Password
        </div>
        <div class="card-body">
            <form method="post" action="">
                <div class="mb-3">
                    <label for="old_password" class="form-label">Password attuale</label>
                    <input type="password" class="form-control" id="old_password" name="old_password" required maxlength="255">
                </div>
                <div class="mb-3">
                    <label for="new_password" class="form-label">Nuova Password</label>
                    <input type="password" class="form-control" id="new_password" name="new_password" required maxlength="255">
                </div>
                <button type="submit" name="edit_password" class="btn btn-primary">Aggiorna Password</button>
            </form>
        </div>
    </div>
</main>
<?php include_once '../components/footer.php'; ?>