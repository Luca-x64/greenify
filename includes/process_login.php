<?php
// process_login.php - Login utente
require_once __DIR__ . '/../db/connector.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        setcookie('login_error', 'Compila tutti i campi.', time() + 5, '/');
        header('Location: ../pages/login.php');
        exit();
    }

    $db = open_pg_connection();
    if (!$db) {
        setcookie('login_error', 'Errore di connessione al database.', time() + 5, '/');
        header('Location: ../pages/login.php');
        exit();
    }

    // 1. Prova login manager (priorità a manager)
    $sql = "SELECT u.mail, u.password, u.attivo
            FROM greenify.utente u
            INNER JOIN greenify.manager m ON u.mail = m.mail
            WHERE u.mail = $1";
    $res = pg_query_params($db, $sql, [$email]);
    if ($res === false) {
        setcookie('login_error', 'Errore database (manager).', time() + 5, '/');
        header('Location: ../pages/login.php');
        exit();
    }
    $user = pg_fetch_assoc($res);

    if ($user) {
        if (!password_verify($password, $user['password'])) {
            setcookie('login_error', 'Password errata.', time() + 5, '/');
            setcookie('login_email', $email, time() + 5, '/');
            header('Location: ../pages/login.php');
            exit();
        }
        if ($user['attivo'] !== 't' && $user['attivo'] !== true && $user['attivo'] != 1) {
            // Recupera la data di licenziamento del manager
            $res_lic = pg_query_params($db, "SELECT data_licenziamento FROM greenify.manager WHERE mail = $1", [$user['mail']]);
            $data_lic = null;
            if ($res_lic && $row_lic = pg_fetch_assoc($res_lic)) {
                $data_lic = $row_lic['data_licenziamento'];
            }
            close_pg_connection($db);
            // Passa la data come parametro GET se disponibile
            if ($data_lic) {
                header('Location: ../pages/licenziato.php?data=' . urlencode($data_lic));
            } else {
                header('Location: ../pages/licenziato.php');
            }
            exit();
        }
        // Login manager OK
        $_SESSION['manager_id'] = $user['mail'];
        close_pg_connection($db);
        header('Location: ../manager/dashboard.php');
        exit();
    }

    // 2. Prova login cliente
    $sql = "SELECT u.mail, u.password, u.attivo
            FROM greenify.utente u
            INNER JOIN greenify.cliente c ON u.mail = c.mail
            WHERE u.mail = $1";
    $res = pg_query_params($db, $sql, [$email]);
    if ($res === false) {
        setcookie('login_error', 'Errore database (cliente).', time() + 5, '/');
        header('Location: ../pages/login.php');
        exit();
        exit();
    }
    $user = pg_fetch_assoc($res);

    if ($user) {
        if (!password_verify($password, $user['password'])) {
            setcookie('login_error', 'Password errata.', time() + 5, '/');
            setcookie('login_email', $email, time() + 5, '/');
            header('Location: ../pages/login.php');
            exit();
        }
        if ($user['attivo'] !== 't' && $user['attivo'] !== true && $user['attivo'] != 1) {
            close_pg_connection($db);
            header('Location: ../pages/landing_inactive.php');
            exit();
        }
        // Login cliente OK
        $_SESSION['user_id'] = $user['mail'];
        close_pg_connection($db);
        header('Location: ../index.php');
        exit();
    }

    // 3. Nessun utente trovato
    setcookie('login_error', 'Email non trovata.', time() + 5, '/');
    setcookie('login_email', $email, time() + 5, '/');
    header('Location: ../pages/login.php');
    exit();
}
