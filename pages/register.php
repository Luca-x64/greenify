<?php
// register.php - Pagina di registrazione utente
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: account.php');
    exit();
}

require_once __DIR__ . '/../db/connector.php';
require_once __DIR__ . '/../includes/functions.php';

$msg = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $telefono = trim($_POST['telefono'] ?? '');
    $cf = strtoupper(trim($_POST['codice_fiscale'] ?? ''));
    $nome = trim($_POST['nome'] ?? '');
    $cognome = trim($_POST['cognome'] ?? '');
    $data_nascita = !empty($_POST['data_nascita']) ? $_POST['data_nascita'] : null;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = "Email non valida.";
        $msg_type = 'danger';
    } elseif (!preg_match('/^[A-Z0-9]{16}$/', $cf)) {
        $msg = "Codice fiscale non valido (16 caratteri alfanumerici).";
        $msg_type = 'danger';
    } elseif (!preg_match('/^[0-9]{10}$/', $telefono)) {
        $msg = "Telefono non valido (10 cifre).";
        $msg_type = 'danger';
    } elseif (strlen($password) < 6) {
        $msg = "La password deve essere di almeno 6 caratteri.";
        $msg_type = 'danger';
    } else {
        $db = open_pg_connection();
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $res = registra_utente_cliente($db, $email, $password_hash, $telefono, $cf, $nome, $cognome, $data_nascita);
        if (!$res['success']) {
            $err = $res['error'];
            if (stripos($err, 'duplicate key') !== false && stripos($err, 'cliente_pkey') !== false) {
                $msg = "Codice fiscale già registrato.";
            } elseif (stripos($err, 'duplicate key') !== false && stripos($err, 'utente_pkey') !== false) {
                $msg = "Email già registrata.";
            } elseif (stripos($err, 'duplicate key') !== false && stripos($err, 'cliente_mail_key') !== false) {
                $msg = "Email già associata a un cliente.";
            } else {
                $msg = "Errore registrazione: " . htmlspecialchars($err);
            }
            $msg_type = 'danger';
        } else {
            // Login automatico e redirect
            $_SESSION['register_success'] = true;
            header('Location: ../index.php');
            exit;
        }
        close_pg_connection($db);
    }
}
?>

<?php include_once '../components/header.php'; ?>

<body class="d-flex flex-column min-vh-100">
    <main class="container py-4 d-flex justify-content-center align-items-center" style="min-height:60vh;">
        <div class="card shadow-sm" style="max-width: 400px; width:100%;">
            <div class="card-body">
                <h2 class="mb-4 text-center">Registrazione Utente</h2>
                <form method="post" action="">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <input type="text" class="form-control" placeholder="Nome *" name="nome" required maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <input type="text" class="form-control" placeholder="Cognome *" name="cognome" required maxlength="100">
                        </div>
                    </div>
                    <div class="row g-2 mt-2">
                        <div class="col-md-6">
                            <input type="email" class="form-control" placeholder="Email *" name="email" required maxlength="255">
                        </div>
                        <div class="col-md-6">
                            <input type="text" class="form-control" placeholder="Telefono *" name="telefono" required pattern="[0-9]{10}" maxlength="10" minlength="10" title="Inserisci 10 cifre numeriche">
                        </div>
                    </div>
                    <div class="row g-2 mt-2">
                        <div class="col-md-6">
                            <input type="text" class="form-control" placeholder="Codice Fiscale *" name="codice_fiscale" required pattern="[A-Za-z0-9]{16}" maxlength="16" minlength="16" title="16 caratteri alfanumerici">
                        </div>
                        <div class="col-md-6">
                            <input type="password" class="form-control" placeholder="Password *" name="password" required minlength="6">
                        </div>
                    </div>
                    <div class="row g-2 mt-2">
                        <div class="col-md-6">
                            <input type="date" class="form-control" placeholder="Data di nascita" name="data_nascita">
                        </div>
                    </div>
                    <p class="mb-3 text-muted" style="font-size:0.95em">
                        I campi contrassegnati con <span class="text-danger">*</span> sono obbligatori.
                    </p>
                    <button type="submit" class="btn btn-success w-100">Registrati</button>
                </form>
                <?php if (isset($_GET['error'])): ?>
                    <div class="alert alert-danger text-center py-2" id="regAlert">
                        <?= htmlspecialchars($_GET['error']) ?>
                    </div>
                <?php endif; ?>
                <?php if ($msg): ?>
                    <div class="alert alert-<?= $msg_type ?> text-center py-2" id="regAlert">
                        <?= htmlspecialchars($msg) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <script>
        window.addEventListener('DOMContentLoaded', function() {
            var alert = document.querySelector('.alert-danger');
            if (alert) setTimeout(function() {
                alert.style.display = 'none';
            }, 5000);
        });
    </script>
</body>
<?php include_once '../components/footer.php'; ?>