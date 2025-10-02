<?php
if (session_status() === PHP_SESSION_NONE) session_start();


// --- FUNZIONI DI UTILITY ---

function add_to_cart($id_prodotto, $nome, $prezzo, $quantita)
{
    if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
    $id_prodotto = (string)$id_prodotto;

    // Recupera la disponibilità massima dal DB per il negozio selezionato
    $max_qty = null;
    if (isset($_SESSION['negozio_id'])) {
        require_once __DIR__ . '/../db/connector.php';
        $db = open_pg_connection();
        $res = pg_query_params($db, 'SELECT quantita FROM greenify.dispone WHERE negozio_id = $1 AND prodotto_id = $2', [$_SESSION['negozio_id'], $id_prodotto]);
        if ($res && $row = pg_fetch_assoc($res)) {
            $max_qty = intval($row['quantita']);
        }
        close_pg_connection($db);
    }

    $current_qty = isset($_SESSION['cart'][$id_prodotto]) ? $_SESSION['cart'][$id_prodotto]['quantita'] : 0;
    $new_qty = $current_qty + $quantita;

    if ($max_qty !== null && $new_qty > $max_qty) {
        $new_qty = $max_qty;
    }

    if ($current_qty > 0) {
        $_SESSION['cart'][$id_prodotto]['quantita'] = $new_qty;
    } else {
        $_SESSION['cart'][$id_prodotto] = [
            'nome' => $nome,
            'prezzo' => $prezzo,
            'quantita' => $new_qty
        ];
    }
}

function get_cart_count()
{
    if (!isset($_SESSION['cart'])) return 0;
    $tot = 0;
    foreach ($_SESSION['cart'] as $item) $tot += $item['quantita'];
    return $tot;
}

function get_cart_items()
{
    return isset($_SESSION['cart']) ? $_SESSION['cart'] : [];
}

function get_cart_total()
{
    $tot = 0;
    if (!isset($_SESSION['cart'])) return 0;
    foreach ($_SESSION['cart'] as $item) $tot += $item['prezzo'] * $item['quantita'];
    return $tot;
}

function clear_cart()
{
    unset($_SESSION['cart']);
}

// --- ENDPOINT UNIFICATO SOLO SE CHIAMATO DIRETTAMENTE ---

if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Aggiungi al carrello
    if (isset($_POST['action']) && $_POST['action'] === 'add_to_cart') {
        $id_prodotto = $_POST['id_prodotto'] ?? null;
        $nome = $_POST['nome'] ?? '';
        $prezzo = $_POST['prezzo'] ?? 0;
        $quantita = intval($_POST['quantita'] ?? 1);

        if ($id_prodotto && $quantita > 0) {
            add_to_cart($id_prodotto, $nome, $prezzo, $quantita);
        }
        // Redirect dopo aggiunta al carrello
        header('Location: ../index.php');
        exit;
    }

    // Aggiorna quantità o rimuovi prodotto
    if (isset($_POST['id'], $_POST['action']) && in_array($_POST['action'], ['incr', 'decr', 'remove'])) {
        $id = $_POST['id'];
        $action = $_POST['action'];

        if (!isset($_SESSION['cart'][$id])) exit;

        // Recupera la disponibilità massima dal DB per il negozio selezionato
        $max_qty = null;
        if (isset($_SESSION['negozio_id'])) {
            require_once __DIR__ . '/../db/connector.php';
            $db = open_pg_connection();
            $res = pg_query_params($db, 'SELECT quantita FROM greenify.dispone WHERE negozio_id = $1 AND prodotto_id = $2', [$_SESSION['negozio_id'], $id]);
            if ($res && $row = pg_fetch_assoc($res)) {
                $max_qty = intval($row['quantita']);
            }
            close_pg_connection($db);
        }

        switch ($action) {
            case 'incr':
                if ($max_qty !== null && $_SESSION['cart'][$id]['quantita'] < $max_qty) {
                    $_SESSION['cart'][$id]['quantita']++;
                }
                break;
            case 'decr':
                if ($_SESSION['cart'][$id]['quantita'] > 1) {
                    $_SESSION['cart'][$id]['quantita']--;
                }
                break;
            case 'remove':
                unset($_SESSION['cart'][$id]);
                break;
        }
        exit('OK');
    }

    // Svuota carrello
    if (isset($_POST['clear_cart'])) {
        clear_cart();
        exit('OK');
    }

    // Se chiamato direttamente senza POST valido
    http_response_code(403);
    exit('Accesso diretto non consentito.');
}
