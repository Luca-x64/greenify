<?php

session_start();
require_once __DIR__ . '/../includes/cart_functions.php';
require_once __DIR__ . '/../db/connector.php';
$db = open_pg_connection();

// Redirect se non loggato
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

include_once __DIR__ . '/../components/header.php';
$cart_items = get_cart_items();
$total = get_cart_total();

// --- Controllo tessera cliente ---
$user_has_tessera = false;
$cf = null;
$tessera_punti = 0;
$sconto_opzioni = [];
if (isset($_SESSION['user_id'])) {
    $user_mail = $_SESSION['user_id'];

    // Controllo se utente è attivo
    $res_attivo = pg_query_params($db, 'SELECT attivo FROM greenify.utente WHERE mail = $1', [$user_mail]);
    if ($res_attivo && ($row = pg_fetch_assoc($res_attivo))) {
        if (!$row['attivo'] || $row['attivo'] === 'f' || $row['attivo'] === false || $row['attivo'] == 0) {
            close_pg_connection($db);
            include __DIR__ . '/landing_inactive.php';
            exit();
        }
    }

    $res_cf = pg_query_params($db, 'SELECT cf FROM greenify.cliente WHERE mail = $1', [$user_mail]);
    if ($res_cf && pg_num_rows($res_cf) > 0) {
        $cf = pg_fetch_result($res_cf, 0, 0);
    }
    if ($cf) {
        // Cerca la tessera attiva più recente per il cliente 
        $res_tessera = pg_query_params($db, 'SELECT punti FROM greenify.tessera WHERE cliente_cf = $1 AND attiva = true ORDER BY data_scadenza DESC, id DESC LIMIT 1', [$cf]);
        if ($res_tessera && pg_num_rows($res_tessera) > 0) {
            $user_has_tessera = true;
            $tessera_punti = intval(pg_fetch_result($res_tessera, 0, 0));
            // Ottieni le opzioni sconto disponibili tramite funzione SQL
            $res_opz = pg_query_params($db, 'SELECT * FROM greenify.fn_opzioni_sconto_per_punti($1)', [$tessera_punti]);
            if ($res_opz) {
                while ($row = pg_fetch_assoc($res_opz)) {
                    $sconto_opzioni[] = [
                        'sconto_pct' => intval($row['sconto_pct']),
                        'punti_richiesti' => intval($row['punti_richiesti'])
                    ];
                }
            }
        }
    }
}

// --- GESTIONE RICHIESTA TESSERA, SCONTO E ORDINE ---
$msg = '';
$msg_type = '';
$ordine_appena_fatto = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['procedi_ordine'])) {
    $user_mail = $_SESSION['user_id'];
    $res_cf = pg_query_params($db, 'SELECT cf FROM greenify.cliente WHERE mail = $1', [$user_mail]);
    $cf = ($res_cf && pg_num_rows($res_cf) > 0) ? pg_fetch_result($res_cf, 0, 0) : null;

    $ordine_ok = false;
    $errore = '';
    $punti_ottenuti = 0; // <-- aggiungi questa variabile

    if ($cf && !empty($cart_items)) {
        $negozio_id = isset($_SESSION['negozio_id']) ? intval($_SESSION['negozio_id']) : null;

        // Inserisci la tessera PRIMA dell'ordine se richiesta e non esiste già
        if (isset($_POST['richiedi_tessera']) && !$user_has_tessera && $negozio_id) {
            $punti_iniziali = 0;
            $sql_tessera = 'SELECT greenify.fn_inserisci_tessera_e_rilascia($1::character varying, $2::bigint, $3::integer)';
            pg_query_params($db, $sql_tessera, [trim($cf), intval($negozio_id), intval($punti_iniziali)]);
            // Dopo questa insert, $user_has_tessera sarà true al prossimo caricamento pagina
        }

        // Calcola la disponibilità per ogni prodotto PRIMA del filtro
        $disponibilita = [];
        foreach ($cart_items as $id => $item) {
            $res_disp = pg_query_params($db, 'SELECT quantita FROM greenify.dispone WHERE negozio_id = $1 AND prodotto_id = $2', [$negozio_id, $id]);
            $disp = ($res_disp && $row = pg_fetch_assoc($res_disp)) ? intval($row['quantita']) : 0;
            $disponibilita[$id] = $disp;
        }
        // Se almeno un prodotto ha richiesta > disponibile, mostra errore dettagliato e non procedere
        $prodotti_non_disp = [];
        foreach ($cart_items as $id => $item) {
            $id_key = (string)$id;
            $disp = isset($disponibilita[$id_key]) ? $disponibilita[$id_key] : 0;
            if ($item['quantita'] > $disp) {
                $prodotti_non_disp[$id_key] = $item;
            }
        }
        if (!empty($prodotti_non_disp)) {
            $errore = "Quantità richiesta superiore alla disponibilità:<br>";
            foreach ($prodotti_non_disp as $id => $item) {
                $disp = isset($disponibilita[$id]) ? $disponibilita[$id] : 0;
                $errore .= "Prodotto <b>" . htmlspecialchars($item['nome']) . "</b> (ID " . $id . "): richiesto " . $item['quantita'] . ", disponibile " . $disp . "<br>";
            }
        } else {
            $prodotti = [];
            $quantita = [];
            $prezzi = [];
            foreach ($cart_items as $id => $item) {
                $prodotti[] = $id;
                $quantita[] = $item['quantita'];
                $prezzi[] = $item['prezzo'];
            }
            $prodotti_pg = '{' . implode(',', $prodotti) . '}';
            $quantita_pg = '{' . implode(',', $quantita) . '}';
            $prezzi_pg = '{' . implode(',', $prezzi) . '}';

            $sconto_pct = 0;
            if ($user_has_tessera && isset($_POST['applica_sconto']) && is_numeric($_POST['applica_sconto'])) {
                $sconto_pct = intval($_POST['applica_sconto']);
            }

            $sql = 'SELECT greenify.fn_inserisci_fattura_e_prodotti(
                $1::char(16),
                $2::bigint,
                $3::bigint[],
                $4::integer[],
                $5::greenify.prezzo_dom[],
                $6::integer
            )';
            $params = [
                str_pad($cf, 16),
                intval($negozio_id),
                $prodotti_pg,
                $quantita_pg,
                $prezzi_pg,
                intval($sconto_pct)
            ];
            $res = pg_query_params($db, $sql, $params);

            if ($res && $row = pg_fetch_row($res)) {
                $fattura_id = $row[0];
                // Recupera il totale pagato dalla fattura appena creata
                $totale_pagato = 0;
                $res_fatt = pg_query_params($db, 'SELECT totale_pagato FROM greenify.fattura WHERE id = $1', [$fattura_id]);
                if ($res_fatt && $fatt_row = pg_fetch_assoc($res_fatt)) {
                    $totale_pagato = floatval($fatt_row['totale_pagato']);
                }
                $ordine_ok = true;
                clear_cart();
                $ordine_appena_fatto = true;
                // Calcola i punti ottenuti: euro spesi dopo lo sconto
                $punti_ottenuti = intval(round($totale_pagato));
            } else {
                $errore = 'Errore inserimento fattura: ' . htmlspecialchars(pg_last_error($db));
            }
        }
    }

    if ($ordine_ok) {
        $msg_type = 'success';
        $msg = 'Ordine avvenuto con successo!';
        if ($user_has_tessera) {
            $msg .= "<br>Hai ottenuto <b>$punti_ottenuti</b> punti sulla tua tessera.";
        }
    } else {
        $msg_type = 'danger';
        $msg = $errore ?: 'Errore durante la creazione dell\'ordine.';
    }
    $cart_items = get_cart_items();
    $total = get_cart_total();
}
?>
<main class="container py-4" style="max-width:600px">
    <h2 class="mb-4">Riepilogo ordine</h2>
    <?php if ($msg): ?>
        <div id="order-alert" class="alert alert-<?= $msg_type ?> alert-dismissible fade show" role="alert">
            <?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
        </div>
    <?php endif; ?>
    <?php if (empty($cart_items) && !$ordine_appena_fatto): ?>
        <div class="alert alert-info">Il carrello è vuoto.</div>
    <?php elseif (!$ordine_appena_fatto): ?>
        <form method="post" id="ordineForm">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Prodotto</th>
                        <th>Quantità</th>
                        <th>Prezzo</th>
                        <th>Totale</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cart_items as $id => $item): ?>
                        <?php
                        // Recupera la disponibilità massima per ogni prodotto
                        $max_qty = 0;
                        if (isset($_SESSION['negozio_id'])) {
                            require_once __DIR__ . '/../db/connector.php';
                            $db = open_pg_connection();
                            $res = pg_query_params($db, 'SELECT quantita FROM greenify.dispone WHERE negozio_id = $1 AND prodotto_id = $2', [$_SESSION['negozio_id'], $id]);
                            if ($res && $row = pg_fetch_assoc($res)) {
                                $max_qty = intval($row['quantita']);
                            }
                            close_pg_connection($db);
                        }
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($item['nome']) ?></td>
                            <td class="d-flex align-items-center gap-1">
                                <button class="btn btn-outline-secondary btn-sm btn-cart-qty" data-id="<?= $id ?>" data-action="decr" type="button" <?= ($item['quantita'] <= 1) ? 'disabled' : '' ?> style="padding:0 6px;line-height:1;">-</button>
                                <span class="mx-1">x<?= $item['quantita'] ?></span>
                                <button class="btn btn-outline-secondary btn-sm btn-cart-qty" data-id="<?= $id ?>" data-action="incr" data-max="<?= $max_qty ?>" type="button" <?= ($max_qty > 0 && $item['quantita'] >= $max_qty) ? 'disabled' : '' ?> style="padding:0 6px;line-height:1;">+</button>
                            </td>
                            <td>€<?= number_format($item['prezzo'], 2, ',', '.') ?></td>
                            <td>€<?= number_format($item['prezzo'] * $item['quantita'], 2, ',', '.') ?></td>
                            <td>
                                <button class="btn btn-link text-danger btn-sm py-0 px-1 remove-item-btn" data-id="<?= $id ?>" data-action="remove" title="Rimuovi" style="font-size:1.2em;line-height:1;" type="button">&times;</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php
                    // Calcolo sconto e totale pagato
                    $sconto_pct = 0;
                    $sconto_euro = 0;
                    $totale_pagato = $total;
                    $punti_scelti = 0;
                    if ($user_has_tessera && isset($_POST['applica_sconto']) && is_numeric($_POST['applica_sconto'])) {
                        $sconto_pct = intval($_POST['applica_sconto']);
                        foreach ($sconto_opzioni as $opz) {
                            if ($opz['sconto_pct'] === $sconto_pct) {
                                $punti_scelti = $opz['punti_richiesti'];
                                break;
                            }
                        }
                        $totale_pagato = $total;
                        $sconto_euro = 0;
                    } else {
                        $totale_pagato = $total;
                        $sconto_euro = 0;
                        $punti_scelti = 0;
                    }
                    ?>
                    <!-- La riga sconto e totale verrà aggiornata da Js -->
                </tbody>
            </table>
            <div class="d-flex justify-content-between align-items-center mt-3">
                <span class="fs-5">Totale ordine:</span>
                <span class="fs-4 fw-bold text-success" id="totale-ordine-js">
                    €<?= number_format($total, 2, ',', '.') ?>
                </span>
            </div>
            <?php if ($user_has_tessera && !empty($sconto_opzioni)): ?>
                <div class="alert alert-info mt-3">
                    <b>Punti disponibili:</b> <?= $tessera_punti ?><br>
                    <b>Sconti disponibili:</b>
                    <?php foreach ($sconto_opzioni as $opz): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="applica_sconto" id="sconto<?= $opz['sconto_pct'] ?>" value="<?= $opz['sconto_pct'] ?>" <?= ($sconto_pct === $opz['sconto_pct']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="sconto<?= $opz['sconto_pct'] ?>">
                                Usa <?= $opz['punti_richiesti'] ?> punti: <?= $opz['sconto_pct'] ?>% di sconto
                            </label>
                        </div>
                    <?php endforeach; ?>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="applica_sconto" id="noSconto" value="0" <?= ($sconto_pct === 0) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="noSconto">Nessuno</label>
                    </div>
                </div>
            <?php endif; ?>
            <!-- Checkbox per richiedere la tessera solo se NON la possiedi già -->
            <?php if (isset($_SESSION['user_id']) && !$user_has_tessera): ?>
                <div class="form-check mb-3 mt-4">
                    <input class="form-check-input" type="checkbox" name="richiedi_tessera" id="richiediTessera">
                    <label class="form-check-label" for="richiediTessera">
                        Richiedi la tessera fedeltà
                    </label>
                </div>
            <?php endif; ?>
            <?php if ($user_has_tessera): ?>
                <div class="alert alert-success mt-4">
                    Procedendo con l'acquisto otterrai
                    <b id="punti-ottenuti-js">
                        <?php
                        // Mostra il valore iniziale (senza sconto, sarà aggiornato da JS)
                        echo intval($total);
                        ?>
                    </b>
                    punti sulla tua tessera fedeltà.
                </div>
            <?php endif; ?>
            <div class="d-flex justify-content-between align-items-center mt-4">
                <a href="../index.php" class="btn btn-outline-secondary">&laquo; Continua acquisti</a>
                <button type="submit" name="procedi_ordine" class="btn btn-success">Procedi all'acquisto</button>
            </div>
        </form>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                function handleCartButtonClick(e) {
                    const btn = e.target.closest('.btn-cart-qty, .remove-item-btn');
                    if (!btn) return;
                    e.preventDefault();
                    const id = btn.dataset.id;
                    const action = btn.dataset.action;
                    const max = parseInt(btn.closest('tr').querySelector('button[data-action="incr"]')?.dataset.max || "0");
                    if (action === 'incr') {
                        const qtyElem = btn.closest('td').querySelector('span.mx-1');
                        const qty = parseInt(qtyElem.textContent.replace('x', '')) || 1;
                        if (max && qty >= max) return;
                    }
                    const formData = new FormData(); //ajax per aggiornare il carrello senza ricaricare la pagina
                    formData.append('id', id);
                    formData.append('action', action);
                    fetch('../includes/cart_functions.php', {
                            method: 'POST',
                            body: formData,
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        })
                        .then(response => {
                            window.location.reload();
                        })
                        .catch(error => {
                            window.location.reload();
                        });
                }

                var tbody = document.querySelector('#ordineForm tbody');
                if (tbody) {
                    tbody.addEventListener('click', handleCartButtonClick);
                }

                // Calcolo totale ordine e sconto 
                function aggiornaTotaleOrdineJS() {
                    var total = <?= json_encode($total) ?>;
                    var sconto_pct = 0;
                    var radios = document.querySelectorAll('input[name="applica_sconto"]');
                    radios.forEach(function(radio) {
                        if (radio.checked) sconto_pct = parseInt(radio.value) || 0;
                    });
                    var sconto_euro = sconto_pct > 0 ? (total * sconto_pct / 100) : 0;
                    var totale_pagato = total - sconto_euro;
                    var totaleSpan = document.getElementById('totale-ordine-js');
                    if (sconto_pct > 0) {
                        totaleSpan.innerHTML =
                            '<span class="text-decoration-line-through text-danger me-2">€' + total.toFixed(2).replace('.', ',') + '</span>' +
                            '€' + totale_pagato.toFixed(2).replace('.', ',') +
                            '<span class="badge bg-success ms-2">-' + sconto_pct + '%</span>';
                    } else {
                        totaleSpan.innerHTML = '€' + total.toFixed(2).replace('.', ',');
                    }
                    // Aggiorna i punti ottenuti in base al totale pagato dopo lo sconto
                    var puntiSpan = document.getElementById('punti-ottenuti-js');
                    if (puntiSpan) {
                        puntiSpan.textContent = Math.round(totale_pagato);
                    }
                }

                // Aggiorna al cambio radio
                document.querySelectorAll('input[name="applica_sconto"]').forEach(function(radio) {
                    radio.addEventListener('change', aggiornaTotaleOrdineJS);
                });
                aggiornaTotaleOrdineJS();
            });
        </script>
    <?php endif; ?>
</main>
<?php include_once __DIR__ . '/../components/footer.php'; ?>
<?php close_pg_connection($db); ?>

