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

// Recupera il negozio gestito dal manager
$negozio = null;
$manager_mail = $_SESSION['manager_id'];
$res = pg_query_params($db, "SELECT n.id, i.citta, i.indirizzo FROM greenify.negozio n JOIN greenify.indirizzo i ON n.indirizzo_id = i.id WHERE n.manager_mail = $1 AND n.aperto = true", [$manager_mail]);
if ($res && pg_num_rows($res) > 0) {
    $negozio = pg_fetch_assoc($res);
    $negozio_id = $negozio['id'];
} else {
    $negozio_id = null;
}
pg_free_result($res);

// Funzione per recuperare tutti i prodotti ordinabili e i fornitori con quantità/prezzo
function get_prodotti_fornitori($db)
{
    $prodotti = [];
    $fornitori_per_prodotto = [];
    $max_quantita_per_prodotto = [];
    $res = pg_query($db, "SELECT * FROM greenify.prodotti_fornitori");
    if ($res) {
        while ($row = pg_fetch_assoc($res)) {
            $pid = $row['prodotto_id'];
            if (!isset($prodotti[$pid])) {
                $prodotti[$pid] = [
                    'id' => $pid,
                    'nome' => $row['nome'],
                    'descrizione' => $row['descrizione']
                ];
            }
            $fornitori_per_prodotto[$pid][] = [
                'fornitore_piva' => $row['fornitore_piva'],
                'costo' => (float)$row['costo'],
                'quantita' => (int)$row['quantita']
            ];
            // Calcola la quantità massima ordinabile per ogni prodotto
            if (!isset($max_quantita_per_prodotto[$pid]) || $row['quantita'] > $max_quantita_per_prodotto[$pid]) {
                $max_quantita_per_prodotto[$pid] = (int)$row['quantita'];
            }
        }
        pg_free_result($res);
    }
    return [$prodotti, $fornitori_per_prodotto, $max_quantita_per_prodotto];
}

// Recupera prodotti ordinabili e fornitori all'avvio
list($prodotti, $fornitori_per_prodotto, $max_quantita_per_prodotto) = get_prodotti_fornitori($db);

// Gestione ordine
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fn_crea_ordine']) && $negozio_id) {
    $prodotti_ordine = $_POST['prodotto'] ?? [];
    $quantita_ordine = $_POST['quantita'] ?? [];
    $fornitori_usati = [];
    $errore = false;
    $totale_ordine = 0;

    foreach ($prodotti_ordine as $idx => $prodotto_id) {
        $prodotto_id = intval($prodotto_id);
        $qta = intval($quantita_ordine[$idx]);
        if ($qta <= 0) continue;

        $sql = "SELECT f.fornitore_piva, f.costo, f.quantita
                FROM greenify.fornisce f
                JOIN greenify.fornitore fo ON f.fornitore_piva = fo.p_iva
                WHERE f.prodotto_id = $1 AND fo.attivo = true AND f.quantita >= $2
                ORDER BY f.costo ASC
                LIMIT 1";
        $res = pg_query_params($db, $sql, [$prodotto_id, $qta]);
        if ($res && pg_num_rows($res) > 0) {
            $fornitore = pg_fetch_assoc($res);
            $fornitori_usati[$fornitore['fornitore_piva']][] = [
                'prodotto_id' => $prodotto_id,
                'quantita' => $qta,
                'prezzo' => $fornitore['costo']
            ];
            $totale_ordine += $fornitore['costo'] * $qta;
        } else {
            $alert_msg = "Nessun fornitore disponibile con quantità sufficiente per il prodotto selezionato.";
            $alert_type = 'danger';
            $errore = true;
            break;
        }
        if ($res) pg_free_result($res);
    }

    if (!$errore && count($fornitori_usati) > 0) {
        foreach ($fornitori_usati as $fornitore_piva => $prodotti_da_ordinare) {
            $prodotti_json = json_encode($prodotti_da_ordinare);

            $data_consegna = null;
            $res_consegna = pg_query($db, "SELECT greenify.fn_calcola_data_consegna_random(CURRENT_DATE) AS data_consegna");
            if ($res_consegna && ($row_consegna = pg_fetch_assoc($res_consegna))) {
                $data_consegna = $row_consegna['data_consegna'];
            }
            if ($res_consegna) pg_free_result($res_consegna);

            $ordine_id = null;
            $ordine_res = pg_query_params(
                $db,
                "SELECT greenify.fn_crea_ordine($1, $2, $3::json) AS id",
                [$negozio_id, $fornitore_piva, $prodotti_json]
            );
            if ($ordine_res && ($ordine_row = pg_fetch_assoc($ordine_res))) {
                $ordine_id = $ordine_row['id'];
            }
            if ($ordine_res) pg_free_result($ordine_res);

            if ($ordine_id && $data_consegna) {
                $upd_res = pg_query_params(
                    $db,
                    "UPDATE greenify.ordine SET data_consegna = $1 WHERE id = $2",
                    [$data_consegna, $ordine_id]
                );
                if ($upd_res) pg_free_result($upd_res);
            }

            if (!$ordine_id) {
                $alert_msg = "Errore nella creazione dell'ordine.";
                $alert_type = 'danger';
                $errore = true;
                break;
            }
        }
        if (!$errore) {
            $alert_msg = "Ordine effettuato con successo! Totale: " . number_format($totale_ordine, 2, ',', '') . " €";
            $alert_type = 'success';
        }
    }

    // Dopo aver gestito l'ordine, aggiorna la lista prodotti ordinabili
    list($prodotti, $fornitori_per_prodotto, $max_quantita_per_prodotto) = get_prodotti_fornitori($db);
}


$storico = [];
$sql = "SELECT * FROM greenify.fn_storico_ordini_negozio($1, NULL::varchar)";
$params = [$negozio_id];
$res = pg_query_params($db, $sql, $params);
if ($res) {
    while ($row = pg_fetch_assoc($res)) {
        if (!isset($row['totale']) || $row['totale'] === null) {
            $res_tot = pg_query_params($db, "SELECT greenify.totale_ordine($1) AS totale", [$row['id']]);
            $row['totale'] = ($res_tot && $tot = pg_fetch_assoc($res_tot)) ? $tot['totale'] : 0;
            if ($res_tot) pg_free_result($res_tot);
        }
        $storico[] = $row;
    }
    pg_free_result($res);
}
?>
<?php include_once '../components/header.php'; ?>

<main class="container py-5">
    <h2>Gestione Ordini Fornitori</h2>

    <?php if ($alert_msg): ?>
        <div id="alert-top-right" class="alert alert-<?= $alert_type ?> alert-dismissible fade show position-fixed" role="alert"
            style="top: 70px; right: 24px; min-width: 320px; z-index: 1055; display: none;">
            <?= $alert_msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
        </div>
    <?php endif; ?>

    <?php if ($negozio_id): ?>
        <div class="d-flex flex-column align-items-center w-100">
            <div class="w-100 d-flex justify-content-start mb-4">
                <button id="btn-toggle-nuovo-ordine" class="btn btn-lg btn-success d-flex align-items-center gap-2 shadow" type="button" style="font-size:1.2rem;">
                    <i class="bi bi-plus-circle" style="font-size:1.5rem;"></i>
                    <span>Nuovo ordine</span>
                </button>
            </div>
            <!-- Form nuovo ordine a scomparsa -->
            <div id="nuovo-ordine-card" class="card mb-5 p-4" style="display:none; max-width: 700px; width: 100%;">
                <div class="card-header bg-primary text-white mb-3 rounded">
                    <strong>Nuovo ordine per il negozio:</strong> <?= htmlspecialchars($negozio['citta']) ?>, <?= htmlspecialchars($negozio['indirizzo']) ?>
                </div>
                <div class="card-body">
                    <form method="post" action="" id="ordineForm" autocomplete="off">
                        <div class="mb-3 fw-bold">Seleziona i prodotti da ordinare</div>
                        <div class="row g-3 align-items-end mb-4">
                            <div class="col-md-5">
                                <label class="form-label">Prodotto</label>
                                <select class="form-select" id="prodotto_select">
                                    <option value="">-- Seleziona prodotto --</option>
                                    <?php foreach ($prodotti as $p): ?>
                                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nome']) ?><?= $p['descrizione'] ? ' - ' . htmlspecialchars($p['descrizione']) : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Prezzo cad.</label>
                                <input type="text" class="form-control" id="prezzo_cad_input" disabled placeholder="€">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Quantità</label>
                                <input type="number" class="form-control" id="quantita_input" min="1">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="button" class="btn btn-success w-100" id="btn-add-articolo">Aggiungi</button>
                            </div>
                        </div>
                        <div id="prodotti-ordine-list"></div>
                    </form>
                </div>
            </div>

            <div id="card-articoli-ordine" class="card mb-5 p-4" style="display:none; max-width: 700px; width: 100%;">
                <div class="card-header bg-info text-dark mb-3 rounded">
                    <strong>Articoli da ordinare</strong>
                </div>
                <div class="card-body">
                    <div class="table-responsive" style="padding: 24px 0 24px 0;">
                        <table class="table mb-0" style="background: #fff;">
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Quantità</th>
                                    <th>Prezzo cad.</th>
                                    <th>Prezzo totale</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="riepilogo-articoli">
                                <tr>
                                    <td colspan="5" class="text-muted text-center">Nessun articolo aggiunto.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <hr class="my-3">
                    <div class="d-flex justify-content-between fw-bold px-3 pb-2">
                        <span>Totale ordine:</span>
                        <span id="totale-ordine">0,00 €</span>
                    </div>
                    <div class="px-3 pb-3">
                        <button type="submit" form="ordineForm" name="fn_crea_ordine" value="1" class="btn btn-primary w-100">Effettua Ordine</button>
                    </div>
                </div>
            </div>

            <!-- Storico ordini -->
            <div class="border border-3 rounded-4 shadow-sm p-4 mb-5 bg-white w-100 d-flex justify-content-center" style="max-width:2200px;">
                <div class="w-100" style="max-width: 2000px; margin: 0 auto;">
                    <div class="bg-secondary text-white px-4 py-3 mb-4 rounded-3 text-center">
                        <strong>Storico ordini precedenti</strong>
                    </div>
                    <div style="padding: 0;">
                        <table class="table table-striped mb-0 text-center align-middle" id="tabella-negozi" style="width:100%;">
                            <colgroup>
                                <col style="width: 220px;">
                                <col style="width: 220px;">
                                <col style="width: 300px;">
                                <col style="width: 900px;">
                                <col style="width: 220px;">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th>Data richiesta</th>
                                    <th>Data consegna</th>
                                    <th>Fornitore</th>
                                    <th>Articoli ordinati</th>
                                    <th>Totale ordine</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                if (empty($storico)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">Nessun ordine precedente.</td>
                                    </tr>
                                    <?php else:
                                    foreach ($storico as $ordine):
                                        $articoli = json_decode($ordine['articoli'], true) ?: [];
                                        $totale = $ordine['totale'] ?? 0;
                                    ?>
                                        <tr>
                                            <td style="white-space: nowrap;">
                                                <?= htmlspecialchars(date('d/m/Y H:i', strtotime($ordine['data_ordine']))) ?>
                                            </td>
                                            <td style="white-space: nowrap;">
                                                <?= $ordine['data_consegna'] ? htmlspecialchars(date('d/m/Y', strtotime($ordine['data_consegna']))) : '-' ?>
                                            </td>
                                            <td style="white-space: nowrap;">
                                                <?= htmlspecialchars($ordine['nome']) ?>
                                                <span class="text-muted small ms-2"><?= htmlspecialchars($ordine['fornitore_piva']) ?></span>
                                            </td>
                                            <td>
                                                <?php foreach ($articoli as $a):
                                                    $subtot = $a['quantita'] * $a['prezzo'];
                                                ?>
                                                    <div style="display: flex; align-items: center; margin-bottom: 4px;">
                                                        <span style="min-width:220px; font-weight:500;"><?= htmlspecialchars($a['nome']) ?></span>
                                                        <span style="margin: 0 12px; color: #bbb;">|</span>
                                                        <span><?= $a['quantita'] ?> x <?= number_format($a['prezzo'], 2, ',', '') ?> € = <strong><?= number_format($subtot, 2, ',', '') ?> €</strong></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </td>
                                            <td><strong><?= number_format($totale, 2, ',', '') ?> €</strong></td>
                                        </tr>
                                <?php
                                    endforeach;
                                endif;
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-warning mt-4 text-center">Non hai un negozio associato o il negozio non è attivo.</div>
    <?php endif; ?>

    <script>
        // Prezzi prodotti per JS
        // Calcola prezzi dinamici per ogni prodotto in base alla quantità
        var fornitoriPerProdotto = <?= json_encode($fornitori_per_prodotto, JSON_NUMERIC_CHECK) ?>;
        var maxQuantitaPerProdotto = <?= json_encode($max_quantita_per_prodotto, JSON_NUMERIC_CHECK) ?>;
        var articoli = [];

        // Bottone mostra/nascondi form nuovo ordine
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('btn-toggle-nuovo-ordine').addEventListener('click', function() {
                var card = document.getElementById('nuovo-ordine-card');
                var articoliCard = document.getElementById('card-articoli-ordine');
                var isVisible = window.getComputedStyle(card).display !== 'none';
                if (isVisible) {
                    card.style.display = 'none';
                    articoliCard.style.display = 'none';
                } else {
                    card.style.display = '';
                    if (articoli.length > 0) {
                        articoliCard.style.display = '';
                    }
                }
            });

            document.getElementById('prodotto_select').addEventListener('change', aggiornaPrezzoCad);

            // Fix: aggiungi articolo e aggiorna riepilogo
            document.getElementById('btn-add-articolo').addEventListener('click', function(e) {
                e.preventDefault();
                var prodSel = document.getElementById('prodotto_select');
                var qtaInput = document.getElementById('quantita_input');
                var prodotto_id = prodSel.value;
                var quantita = parseInt(qtaInput.value);

                // Calcola la quantità massima disponibile per il prodotto
                var maxQta = maxQuantitaPerProdotto[prodotto_id] || 1;

                // Calcola la quantità già presente negli articoli da ordinare
                var foundIdx = articoli.findIndex(a => a.prodotto_id === prodotto_id);
                var gia_ordinata = foundIdx !== -1 ? articoli[foundIdx].quantita : 0;

                // Non permettere di superare la quantità massima
                if (!prodotto_id || !quantita || quantita < 1 || (quantita + gia_ordinata) > maxQta) {
                    prodSel.classList.add('is-invalid');
                    qtaInput.classList.add('is-invalid');
                    setTimeout(function() {
                        prodSel.classList.remove('is-invalid');
                        qtaInput.classList.remove('is-invalid');
                    }, 1200);
                    return;
                }

                // Se già presente, somma la quantità
                if (foundIdx !== -1) {
                    articoli[foundIdx].quantita += quantita;
                } else {
                    articoli.push({
                        prodotto_id: prodotto_id,
                        quantita: quantita
                    });
                }

                document.getElementById('card-articoli-ordine').style.display = '';
                aggiornaRiepilogo();
                prodSel.value = '';
                qtaInput.value = '';
                document.getElementById('prezzo_cad_input').value = '';
            });

            aggiornaPrezzoCad();
            aggiornaRiepilogo();
        });

        function aggiornaPrezzoCad() {
            var prodSel = document.getElementById('prodotto_select');
            var qtaInput = document.getElementById('quantita_input');
            var prezzoInput = document.getElementById('prezzo_cad_input');
            var prodotto_id = prodSel.value;
            var qta = parseInt(qtaInput.value) || 1;
            var fornitori = fornitoriPerProdotto[prodotto_id] || [];
            var maxQta = maxQuantitaPerProdotto[prodotto_id] || 1;
            qtaInput.max = maxQta;
            qtaInput.placeholder = "max " + maxQta;
            // Trova il fornitore con prezzo minore che ha almeno la quantità richiesta
            var migliore = null;
            fornitori.forEach(function(f) {
                if (f.quantita >= qta) {
                    if (!migliore || f.costo < migliore.costo) {
                        migliore = f;
                    }
                }
            });
            if (migliore) {
                prezzoInput.value = parseFloat(migliore.costo).toFixed(2).replace('.', ',') + ' €';
            } else {
                prezzoInput.value = '';
            }
        }

        function aggiornaRiepilogo() {
            var tbody = document.getElementById('riepilogo-articoli');
            var totale = 0;
            var html = '';
            if (articoli.length === 0) {
                html = '<tr><td colspan="5" class="text-muted text-center">Nessun articolo aggiunto.</td></tr>';
                document.getElementById('card-articoli-ordine').style.display = 'none';
            } else {
                document.getElementById('card-articoli-ordine').style.display = '';
                articoli.forEach(function(a, idx) {
                    // Calcola il prezzo per la quantità corrente (dinamico)
                    var fornitori = fornitoriPerProdotto[a.prodotto_id] || [];
                    var migliore = null;
                    fornitori.forEach(function(f) {
                        if (f.quantita >= a.quantita) {
                            if (!migliore || f.costo < migliore.costo) {
                                migliore = f;
                            }
                        }
                    });
                    var prezzo = migliore ? parseFloat(migliore.costo) : 0;
                    var nome = (<?php echo json_encode(array_map(function ($p) {
                                    return $p['nome'];
                                }, $prodotti)); ?>)[a.prodotto_id] || '';
                    var maxQta = maxQuantitaPerProdotto[a.prodotto_id] || 1;
                    var subtot = prezzo * a.quantita;
                    totale += subtot;
                    html += '<tr>' +
                        '<td>' + nome + '</td>' +
                        '<td>' +
                        '<div class="input-group input-group-sm" style="width:110px;">' +
                        '<button type="button" class="btn btn-outline-secondary btn-sm btn-qta-decr" data-idx="' + idx + '" ' + (a.quantita <= 1 ? 'disabled' : '') + '>-</button>' +
                        '<input type="number" min="1" max="' + maxQta + '" class="form-control form-control-sm input-qta-articolo" data-idx="' + idx + '" value="' + a.quantita + '" style="width:50px;display:inline-block;text-align:center;">' +
                        '<button type="button" class="btn btn-outline-secondary btn-sm btn-qta-incr" data-idx="' + idx + '" ' + (a.quantita >= maxQta ? 'disabled' : '') + '>+</button>' +
                        '</div>' +
                        '</td>' +
                        '<td>' + prezzo.toFixed(2).replace('.', ',') + ' €</td>' +
                        '<td>' + subtot.toFixed(2).replace('.', ',') + ' €</td>' +
                        '<td><button type="button" class="btn btn-sm btn-link text-danger btn-remove-articolo" data-idx="' + idx + '" title="Rimuovi"><span style="font-size:1.2em;">&#10006;</span></button></td>' +
                        '</tr>';
                });
            }
            tbody.innerHTML = html;
            document.getElementById('totale-ordine').innerText = totale.toFixed(2).replace('.', ',') + ' €';

            // Aggiorna hidden fields per submit
            var list = document.getElementById('prodotti-ordine-list');
            list.innerHTML = '';
            articoli.forEach(function(a) {
                var inputProd = document.createElement('input');
                inputProd.type = 'hidden';
                inputProd.name = 'prodotto[]';
                inputProd.value = a.prodotto_id;
                list.appendChild(inputProd);
                var inputQta = document.createElement('input');
                inputQta.type = 'hidden';
                inputQta.name = 'quantita[]';
                inputQta.value = a.quantita;
                list.appendChild(inputQta);
            });

            // Handler per rimuovere articoli
            document.querySelectorAll('.btn-remove-articolo').forEach(function(btn) {
                btn.onclick = function() {
                    var idx = parseInt(btn.getAttribute('data-idx'));
                    articoli.splice(idx, 1);
                    aggiornaRiepilogo();
                };
            });

            // Handler per modificare quantità con input
            document.querySelectorAll('.input-qta-articolo').forEach(function(input) {
                input.onchange = function() {
                    var idx = parseInt(input.getAttribute('data-idx'));
                    var maxQta = maxQuantitaPerProdotto[articoli[idx].prodotto_id] || 1;
                    var val = parseInt(input.value);
                    if (val > 0 && val <= maxQta) {
                        articoli[idx].quantita = val;
                        aggiornaRiepilogo();
                    } else {
                        input.value = articoli[idx].quantita;
                    }
                };
            });

            // Handler per bottone +/-
            document.querySelectorAll('.btn-qta-incr').forEach(function(btn) {
                btn.onclick = function() {
                    var idx = parseInt(btn.getAttribute('data-idx'));
                    var maxQta = maxQuantitaPerProdotto[articoli[idx].prodotto_id] || 1;
                    if (articoli[idx].quantita < maxQta) {
                        articoli[idx].quantita++;
                        aggiornaRiepilogo();
                    }
                };
            });
            document.querySelectorAll('.btn-qta-decr').forEach(function(btn) {
                btn.onclick = function() {
                    var idx = parseInt(btn.getAttribute('data-idx'));
                    if (articoli[idx].quantita > 1) {
                        articoli[idx].quantita--;
                        aggiornaRiepilogo();
                    }
                };
            });
        }

        // JS: aggiorna il campo quantità massima e prezzo cad quando selezioni un prodotto
        var prodottiMaxQta = <?= json_encode(array_column($prodotti, 'max_quantita', 'id')) ?>;
        var prodottiPrezzoMin = <?= json_encode(array_column($prodotti, 'prezzo_min', 'id')) ?>;
        document.addEventListener('DOMContentLoaded', function() {
            var prodSel = document.getElementById('prodotto_select');
            var qtaInput = document.getElementById('quantita_input');
            var prezzoInput = document.getElementById('prezzo_cad_input');
            prodSel.addEventListener('change', function() {
                var prodotto_id = prodSel.value;
                var max = prodottiMaxQta[prodotto_id] || 1;
                var prezzo = prodottiPrezzoMin[prodotto_id] || '';
                qtaInput.max = max;
                qtaInput.value = 1;
                qtaInput.placeholder = "max " + max;
                prezzoInput.value = prezzo ? (parseFloat(prezzo).toFixed(2).replace('.', ',') + ' €') : '';
            });
        });

        // Mostra l'alert in alto a destra e lo nasconde dopo 5 secondi
        document.addEventListener('DOMContentLoaded', function() {
            var alertTopRight = document.getElementById('alert-top-right');
            if (alertTopRight) {
                alertTopRight.style.display = '';
                setTimeout(function() {
                    if (alertTopRight) {
                        alertTopRight.classList.remove('show');
                        alertTopRight.classList.add('hide');
                    }
                }, 5000);
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            var prodSel = document.getElementById('prodotto_select');
            var qtaInput = document.getElementById('quantita_input');
            var prezzoInput = document.getElementById('prezzo_cad_input');

            function aggiornaPrezzoEQuantita() {
                var prodotto_id = prodSel.value;
                var qta = parseInt(qtaInput.value) || 1;
                var fornitori = fornitoriPerProdotto[prodotto_id] || [];
                var maxQta = maxQuantitaPerProdotto[prodotto_id] || 1;
                // Limita la quantità massima
                qtaInput.max = maxQta;
                if (qta > maxQta) {
                    qtaInput.value = maxQta;
                    qta = maxQta;
                }
                qtaInput.placeholder = "max " + maxQta;
                // Trova il fornitore con prezzo minore che ha almeno la quantità richiesta
                var migliore = null;
                fornitori.forEach(function(f) {
                    if (f.quantita >= qta) {
                        if (!migliore || f.costo < migliore.costo) {
                            migliore = f;
                        }
                    }
                });
                // Imposta il prezzo
                if (migliore) {
                    prezzoInput.value = parseFloat(migliore.costo).toFixed(2).replace('.', ',') + ' €';
                } else {
                    prezzoInput.value = '';
                }
            }

            prodSel.addEventListener('change', function() {
                qtaInput.value = 1;
                aggiornaPrezzoEQuantita();
            });
            qtaInput.addEventListener('input', aggiornaPrezzoEQuantita);
        });
    </script>
</main>
<?php include_once '../components/footer.php'; ?>