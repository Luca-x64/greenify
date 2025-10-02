<?php
session_start();
if (!isset($_SESSION['manager_id'])) {
    header('Location: ../pages/login_manager.php');
    exit();
}
require_once __DIR__ . '/../db/connector.php';

$negozio_nome = null;
$negozio_id = null;
$totale_vendite = null;
$totale_uscite = null;
$bilancio = null;
$error_msg = null;

// Gestione filtro periodo
$period_options = [
    30 => 'Ultimi 30 giorni',
    60 => 'Ultimi 60 giorni',
    120 => 'Ultimi 120 giorni',
    180 => 'Ultimi 180 giorni',
    365 => 'Ultimo anno'
];
$current_year = date('Y');
$years = range($current_year, $current_year - 10);

// Logica: se viene passato year, ignora period (e viceversa)
$year_selected = isset($_GET['year']) && $_GET['year'] !== '' && in_array($_GET['year'], $years) ? intval($_GET['year']) : null;
if ($year_selected) {
    $period = null;
} else {
    $period = isset($_GET['period']) && in_array($_GET['period'], array_keys($period_options)) ? intval($_GET['period']) : 30;
}

function get_statistiche_negozio_filtro($db, $negozio_id, $year, $period) {
    // La funzione SQL greenify.fn_statistiche_negozio applica i filtri su anno o ultimi X giorni
    $sql = "SELECT totale_vendite, totale_uscite FROM greenify.fn_statistiche_negozio($1, $2::int, $3::int)";
    $params = [
        $negozio_id,
        $year ?: null,
        $period ?: null
    ];
    $res = pg_query_params($db, $sql, $params);
    if ($res && $row = pg_fetch_assoc($res)) {
        pg_free_result($res);
        return $row;
    }
    return [
        'totale_vendite' => 0,
        'totale_uscite' => 0
    ];
}

function get_statistiche_negozio_totali($db, $negozio_id, $refresh = false) {
    // La view materializzata mostra sempre i totali storici (non filtrati)
    if ($refresh) {
        pg_query($db, "REFRESH MATERIALIZED VIEW greenify.vm_statistiche_negozio_totali");
    }
    $sql = "SELECT totale_vendite_all, totale_uscite_all FROM greenify.vm_statistiche_negozio_totali WHERE negozio_id = $1";
    $res = pg_query_params($db, $sql, [$negozio_id]);
    if ($res && $row = pg_fetch_assoc($res)) {
        pg_free_result($res);
        return $row;
    }
    return [
        'totale_vendite_all' => 0,
        'totale_uscite_all' => 0
    ];
}

try {
    $db = open_pg_connection();
    if (!$db) {
        throw new Exception("Errore di connessione al database.");
    }
    // Recupera negozio associato al manager loggato
    $sql = "SELECT n.id, i.citta, i.indirizzo
            FROM greenify.negozio n
            JOIN greenify.indirizzo i ON n.indirizzo_id = i.id
            WHERE n.manager_mail = $1";
    $res = pg_query_params($db, $sql, [$_SESSION['manager_id']]);
    if ($res) {
        if ($row = pg_fetch_assoc($res)) {
            $negozio_id = $row['id'];
            $negozio_nome = trim($row['citta'] . ', ' . $row['indirizzo']);
        } else {
            $error_msg = "Nessun negozio associato trovato per questo manager.";
        }
        pg_free_result($res);
    } else {
        $error_msg = "Errore nella query: " . htmlspecialchars(pg_last_error($db));
    }

    // Se abbiamo trovato il negozio, calcola il totale vendite e uscite filtrati
    if ($negozio_id) {
        // I filtri vengono rispettati dalla funzione SQL come prima
        $stat_filtro = get_statistiche_negozio_filtro($db, $negozio_id, $year_selected, $period);
        // Controlla se è richiesto il refresh
        $refresh_totali = isset($_GET['refresh_totali']) && $_GET['refresh_totali'] == '1';
        $stat_totali = get_statistiche_negozio_totali($db, $negozio_id, $refresh_totali);

        $totale_vendite = $stat_filtro['totale_vendite'];
        $totale_uscite = $stat_filtro['totale_uscite'];
        $totale_vendite_all = $stat_totali['totale_vendite_all'];
        $totale_uscite_all = $stat_totali['totale_uscite_all'];
        $bilancio = $totale_vendite - $totale_uscite;
        $bilancio_all = $totale_vendite_all - $totale_uscite_all;
    }
    // *** NON chiudere qui la connessione ***
} catch (Exception $e) {
    $error_msg = $e->getMessage();
}
?>
<?php include_once '../components/header.php'; ?>
<main class="container py-5">
    <h2 class="mb-4">
        Statistiche del negozio<?= $negozio_nome ? " in " . htmlspecialchars($negozio_nome) : "" ?>
    </h2>

    <!-- Filtro periodo + Bottone aggiorna totali -->
    <section class="mb-4">
        <form class="row g-2 align-items-end" method="get" action="">
            <div class="col-auto">
                <label for="period" class="form-label mb-0">Periodo:</label>
                <select name="period" id="period" class="form-select">
                    <option value="">-</option>
                    <?php foreach ($period_options as $days => $label): ?>
                        <option value="<?= $days ?>" <?= (isset($period) && $period == $days) ? ' selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <label for="year" class="form-label mb-0">Anno:</label>
                <select name="year" id="year" class="form-select">
                    <option value="">-</option>
                    <?php foreach ($years as $y): ?>
                        <option value="<?= $y ?>" <?= $year_selected == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary" name="apply_filter" value="1">Applica</button>
            </div>
            <div class="col text-end">
                <?php
                    $keep = ['period', 'year'];
                    foreach ($_GET as $k => $v) {
                        if (!in_array($k, $keep) && $k !== 'refresh_totali' && $k !== 'apply_filter') {
                            echo '<input type="hidden" name="'.htmlspecialchars($k).'" value="'.htmlspecialchars($v).'">';
                        }
                    }
                ?>
                <button type="submit" name="refresh_totali" value="1" class="btn btn-outline-secondary ms-2">
                    <i class="bi bi-arrow-clockwise"></i> Aggiorna totali
                </button>
            </div>
        </form>
        <script>
            // Se scegli anno, azzera periodo e viceversa (ma non disabilitare)
            document.getElementById('year').addEventListener('change', function() {
                if (this.value) {
                    document.getElementById('period').value = '';
                }
            });
            document.getElementById('period').addEventListener('change', function() {
                if (this.value) {
                    document.getElementById('year').value = '';
                }
            });
            // All'avvio, nessun campo disabilitato
        </script>
    </section>

    <?php if ($error_msg): ?>
        <div class="alert alert-danger"><?= $error_msg ?></div>
    <?php elseif ($negozio_nome): ?>
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="card shadow-lg text-center h-100 border-primary">
                    <div class="card-body">
                        <span class="display-6 text-primary"><i class="bi bi-bar-chart"></i></span>
                        <h6 class="card-title mt-2">Totale vendite (filtro)</h6>
                        <p class="card-text fs-4 fw-bold text-primary">
                            <?= number_format($totale_vendite, 2, ',', '.') ?> €
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card shadow-lg text-center h-100 border-danger">
                    <div class="card-body">
                        <span class="display-6 text-danger"><i class="bi bi-cart-x"></i></span>
                        <h6 class="card-title mt-2">Totale uscite (filtro)</h6>
                        <p class="card-text fs-4 fw-bold text-danger">
                            <?= number_format($totale_uscite, 2, ',', '.') ?> €
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card shadow-lg text-center h-100 border-success">
                    <div class="card-body">
                        <span class="display-6 text-success"><i class="bi bi-cash-coin"></i></span>
                        <h6 class="card-title mt-2">Totale vendite (sempre)</h6>
                        <p class="card-text fs-4 fw-bold text-success">
                            <?= number_format($totale_vendite_all, 2, ',', '.') ?> €
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card shadow-lg text-center h-100 border-warning">
                    <div class="card-body">
                        <span class="display-6 text-warning"><i class="bi bi-cash-stack"></i></span>
                        <h6 class="card-title mt-2">Totale uscite (sempre)</h6>
                        <p class="card-text fs-4 fw-bold text-warning">
                            <?= number_format($totale_uscite_all, 2, ',', '.') ?> €
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <div class="row g-4 mb-4">
            <div class="col-md-6">
                <div class="card shadow-lg text-center h-100 border-info">
                    <div class="card-body">
                        <span class="display-6 text-info"><i class="bi bi-graph-up-arrow"></i></span>
                        <h6 class="card-title mt-2">Bilancio (filtro)</h6>
                        <p class="card-text fs-3 fw-bold <?= $bilancio >= 0 ? 'text-success' : 'text-danger' ?>">
                            <?= number_format($bilancio, 2, ',', '.') ?> €
                            <?php if ($bilancio >= 0): ?>
                                <span class="ms-2 fw-bold">(Positivo)</span>
                            <?php else: ?>
                                <span class="ms-2 fw-bold">(Negativo)</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card shadow-lg text-center h-100 border-secondary">
                    <div class="card-body">
                        <span class="display-6 text-secondary"><i class="bi bi-graph-up"></i></span>
                        <h6 class="card-title mt-2">Bilancio (sempre)</h6>
                        <p class="card-text fs-3 fw-bold <?= $bilancio_all >= 0 ? 'text-success' : 'text-danger' ?>">
                            <?= number_format($bilancio_all, 2, ',', '.') ?> €
                            <?php if ($bilancio_all >= 0): ?>
                                <span class="ms-2 fw-bold">(Positivo)</span>
                            <?php else: ?>
                                <span class="ms-2 fw-bold">(Negativo)</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Storico Fatture -->
        <div class="border border-3 rounded-4 shadow-sm p-4 mb-5 bg-white w-100 d-flex justify-content-center" style="max-width:2200px;">
            <div class="w-100" style="max-width: 2000px; margin: 0 auto;">
                <div class="bg-secondary text-white px-4 py-3 mb-4 rounded-3 text-center">
                    <strong>Storico Fatture</strong>
                </div>
                <?php
           
                $fatture = [];
                if ($negozio_id) {
                    $fattura_sql = "SELECT f.*, cl.nome, cl.cognome
                        FROM greenify.fattura f
                        JOIN greenify.cliente cl ON cl.cf = f.cliente_cf
                        WHERE f.negozio_id = $1
                        ORDER BY f.data_acquisto DESC";
                    $res = pg_query_params($db, $fattura_sql, [$negozio_id]);
                    if ($res) {
                        while ($row = pg_fetch_assoc($res)) {
                            $fatture[] = $row;
                        }
                        pg_free_result($res);
                    }
                }
                ?>
                <div style="padding: 0;">
                    <table class="table table-striped mb-0 text-center align-middle" style="width:100%;">
                        <colgroup>
                            <col style="width: 180px;">
                            <col style="width: 320px;">
                            <col style="width: 120px;">
                            <col style="width: 120px;">
                            <col style="width: 900px;">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Data acquisto</th>
                                <th>Cliente / CF</th>
                                <th>Sconto (%)</th>
                                <th>Totale pagato</th>
                                <th>Prodotti acquistati</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($fatture)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted">Nessuna fattura trovata.</td>
                                </tr>
                                <?php else:
                                foreach ($fatture as $f):
                                    // Recupera prodotti della fattura
                                    $prodotti = [];
                                    $res_prod = pg_query_params($db, "SELECT p.nome, fp.quantita, fp.prezzo FROM greenify.fattura_contiene_prodotto fp JOIN greenify.prodotto p ON fp.prodotto_id = p.id WHERE fp.fattura_id = $1", [$f['id']]);
                                    if ($res_prod) {
                                        while ($rowp = pg_fetch_assoc($res_prod)) {
                                            $prodotti[] = $rowp;
                                        }
                                        pg_free_result($res_prod);
                                    }
                                ?>
                                    <tr>
                                        <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($f['data_acquisto']))) ?></td>
                                        <td>
                                            <?= htmlspecialchars($f['cognome'] . ' ' . $f['nome']) ?><br>
                                            <span class="text-muted small"><?= htmlspecialchars($f['cliente_cf']) ?></span>
                                        </td>
                                        <td><?= intval($f['sconto_pct']) ?>%</td>
                                        <td><strong><?= number_format($f['totale_pagato'], 2, ',', '') ?> €</strong></td>
                                        <td>
                                            <?php if (empty($prodotti)): ?>
                                                <span class="text-muted">Nessun prodotto</span>
                                            <?php else: ?>
                                                <?php foreach ($prodotti as $p): ?>
                                                    <div style="display: flex; align-items: center; margin-bottom: 4px;">
                                                        <span style="min-width:220px; font-weight:500;"><?= htmlspecialchars($p['nome']) ?></span>
                                                        <span style="margin: 0 12px; color: #bbb;">|</span>
                                                        <span><?= $p['quantita'] ?> x <?= number_format($p['prezzo'], 2, ',', '') ?> € = <strong><?= number_format($p['quantita'] * $p['prezzo'], 2, ',', '') ?> €</strong></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                            <?php endforeach;
                            endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <?php else: ?>
        <div class="alert alert-warning">Nessuna informazione disponibile.</div>
    <?php endif; ?>
</main>
<?php include_once '../components/footer.php'; ?>