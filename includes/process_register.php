<?php
// process_register.php - Registra un nuovo utente e cliente tramite funzione SQL atomica
session_start();
require_once __DIR__ . '/../db/connector.php';
require_once __DIR__ . '/functions.php';

// Validazione campi obbligatori
$required = ['email', 'password', 'telefono', 'cf', 'nome', 'cognome'];
foreach ($required as $field) {
    if (empty($_POST[$field])) {
        exit('Compila tutti i campi obbligatori.');
    }
}

$email = strtolower(trim($_POST['email']));
$password = password_hash($_POST['password'], PASSWORD_DEFAULT);
$telefono = trim($_POST['telefono']);
$cf = strtoupper(trim($_POST['cf']));
$nome = trim($_POST['nome']);
$cognome = trim($_POST['cognome']);
$data_nascita = !empty($_POST['data_nascita']) ? $_POST['data_nascita'] : null;

$db = open_pg_connection();
if (!$db) exit('Errore di connessione: ' . pg_last_error());

// Usa la funzione del DB
$res = registra_utente_cliente($db, $email, $password, $telefono, $cf, $nome, $cognome, $data_nascita);

if (!$res['success']) {
    close_pg_connection($db);
    exit('<b>Errore registrazione:</b> ' . htmlspecialchars($res['error']));
}

$_SESSION['user_id'] = $email;
$_SESSION['user_nome'] = $nome;
close_pg_connection($db);
header('Location: ../index.php');
exit;
