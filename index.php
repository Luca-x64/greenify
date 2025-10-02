<?php // index.php entrypoint 
?>
<?php
session_start();
require_once __DIR__ . '/db/connector.php';
include_once 'components/header.php';
require_once __DIR__ . '/includes/cart_functions.php';

// Gestione selezione negozio
if (isset($_POST['negozio_id'])) {
    $_SESSION['negozio_id'] = intval($_POST['negozio_id']);
}

// Permetti di cambiare negozio
if (isset($_GET['reset_negozio'])) {
    unset($_SESSION['negozio_id']);
    header('Location: index.php');
    exit;
}

$db = open_pg_connection();


if (!isset($_SESSION['negozio_id'])) {
    // Mostra la lista dei negozi
    $res = pg_query($db, 'SELECT n.id, i.citta, i.indirizzo, n.aperto FROM greenify.negozio n JOIN greenify.indirizzo i ON n.indirizzo_id = i.id');
?>
    <main class="container py-4" style="max-width:900px">
        <h2 class="mb-4">Scegli il negozio da cui ordinare</h2>
        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
            <?php
            if ($res && ($num_negozi = pg_num_rows($res)) > 0):
                while ($row = pg_fetch_assoc($res)):
                    $is_aperto = ($row['aperto'] === 't' || $row['aperto'] === true || $row['aperto'] == 1);
                    // Recupera orari per il negozio SOLO se aperto
                    $orari = [];
                    if ($is_aperto) {
                        $res_orari = pg_query_params($db, 'SELECT * FROM greenify.fn_orari_negozio($1)', [$row['id']]);
                        if ($res_orari !== false) {
                            while ($orario = pg_fetch_assoc($res_orari)) {
                                $orari[] = $orario;
                            }
                        }
                    }
            ?>
                    <div class="col">
                        <div class="card h-100 shadow-sm <?= !$is_aperto ? 'border-danger' : '' ?>">
                            <div class="card-body">
                                <h5 class="card-title mb-2">Greenify <?= htmlspecialchars($row['citta']) ?></h5>
                                <p class="card-text mb-2 small text-muted"><?= htmlspecialchars($row['indirizzo']) ?></p>
                                <?php if (!$is_aperto): ?>
                                    <div class="alert alert-danger py-1 px-2 mb-0 text-center" style="font-size:1.1em;">
                                        <b>CHIUSO DEFINITIVAMENTE</b>
                                    </div>
                                <?php elseif (!empty($orari)): ?>
                                    <div class="small text-muted mb-2">
                                        <b>Orari:</b>
                                        <ul class="mb-0 ps-3">
                                            <?php foreach ($orari as $o): ?>
                                                <li>
                                                    <?= htmlspecialchars($o['giorno']) ?>:
                                                    <?php if (is_null($o['ora_inizio'])): ?>
                                                        <span class="text-danger">Chiuso</span>
                                                    <?php else: ?>
                                                        <?= substr($o['ora_inizio'], 0, 5) ?> - <?= substr($o['ora_fine'], 0, 5) ?>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="card-footer bg-white border-0 text-end">
                                <?php if ($is_aperto): ?>
                                    <form method="post" class="mb-0">
                                        <button type="submit" name="negozio_id" value="<?= $row['id'] ?>" class="btn btn-success">Vedi prodotti</button>
                                    </form>
                                <?php else: ?>
                                    <button class="btn btn-secondary" disabled>Negozio chiuso</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
            <?php
                endwhile;
            endif;
            ?>
        </div>
    </main>
<?php
    include_once __DIR__ . '/components/footer.php';
    close_pg_connection($db);
    exit;
}

// Selezionato un negozio: mostra i prodotti di quel negozio (tabella dispone)
$negozio_id = $_SESSION['negozio_id'];
$res_negozio = pg_query_params($db, 'SELECT n.id, i.citta, i.indirizzo, n.aperto FROM greenify.negozio n JOIN greenify.indirizzo i ON n.indirizzo_id = i.id WHERE n.id = $1', [$negozio_id]);
$negozio = pg_fetch_assoc($res_negozio);
$is_aperto = ($negozio['aperto'] === 't' || $negozio['aperto'] === true || $negozio['aperto'] == 1);

// Se il negozio è chiuso, mostra solo info e blocca tutto il resto
if (!$is_aperto) {
?>
    <main class="flex-fill container py-4">
        <div class="alert alert-danger text-center mb-4">
            <h2>Negozio #<?= htmlspecialchars($negozio['id']) ?> - <?= htmlspecialchars($negozio['indirizzo']) ?></h2>
            <div class="fs-4 fw-bold">CHIUSO DEFINITIVAMENTE</div>
        </div>
        <div class="text-center">
            <a href="?reset_negozio=1" class="btn btn-outline-secondary btn-lg">Scegli un altro negozio</a>
        </div>
    </main>
<?php
    include_once __DIR__ . '/components/footer.php';
    close_pg_connection($db);
    exit;
}

// Verifica se l'utente è loggato per mostrare il carrello
$isUser = isset($_SESSION['user_id']);
?>
<main class="flex-fill container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2>Prodotti disponibili</h2>
            <div class="text-muted">
                Negozio #<?= htmlspecialchars($negozio['id']) ?> - <?= htmlspecialchars($negozio['citta']) ?>, <?= htmlspecialchars($negozio['indirizzo']) ?>
            </div>
        </div>
        <a href="?reset_negozio=1" class="btn btn-outline-secondary btn-sm">Cambia negozio</a>
    </div>
    <?php
    // Mostra i prodotti disponibili SOLO per il negozio scelto (tabella dispone)
    $query = '
        SELECT d.prodotto_id AS id, p.nome, p.descrizione, d.prezzo, d.quantita
        FROM greenify.dispone d
        INNER JOIN greenify.prodotto p ON d.prodotto_id = p.id
        WHERE d.negozio_id = $1
        ORDER BY p.nome
    ';
    $res_prodotti = pg_query_params($db, $query, [$negozio_id]);
    $prodotti = [];
    if ($res_prodotti) {
        while ($row = pg_fetch_assoc($res_prodotti)) {
            $prodotti[] = $row;
        }
    }
    ?>
    <div class="row row-cols-1 row-cols-md-3 row-cols-lg-4 g-4" id="prodottiRow">
        <?php foreach ($prodotti as $prodotto): ?>
            <div class="col">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title mb-2"><?= htmlspecialchars($prodotto['nome']) ?></h5>
                        <p class="card-text small text-muted"><?= htmlspecialchars($prodotto['descrizione']) ?></p>
                        <p class="card-text mb-2"><b>Prezzo: </b><span class="fw-bold text-success">€<?= number_format($prodotto['prezzo'], 2, ',', '.') ?></span></p>
                        <?php if (intval($prodotto['quantita']) < 1): ?>
                            <p class="card-text small mb-1 text-danger fw-bold">Esaurito</p>
                        <?php else: ?>
                            <p class="card-text small mb-1">Disponibili: <b><?= intval($prodotto['quantita']) ?></b></p>
                        <?php endif; ?>
                    </div>
                    <?php if ($isUser): ?>
                        <div class="card-footer bg-white border-0 text-end">
                            <?php if (intval($prodotto['quantita']) < 1): ?>
                                <button class="btn btn-secondary btn-sm w-100" disabled>Esaurito</button>
                            <?php else: ?>
                                <form method="post" action="includes/cart_functions.php" class="d-flex align-items-center justify-content-end gap-2 mb-0">
                                    <input type="hidden" name="action" value="add_to_cart">
                                    <input type="hidden" name="id_prodotto" value="<?= $prodotto['id'] ?>">
                                    <input type="hidden" name="nome" value="<?= htmlspecialchars($prodotto['nome'], ENT_QUOTES) ?>">
                                    <input type="hidden" name="prezzo" value="<?= $prodotto['prezzo'] ?>">
                                    <div class="input-group input-group-sm" style="width: 90px;">
                                        <button type="button" class="btn btn-outline-secondary btn-sm btn-qty" data-action="decr">-</button>
                                        <input type="number" name="quantita" class="form-control text-center" value="1" min="1" max="<?= intval($prodotto['quantita']) ?>" style="max-width:40px;">
                                        <button type="button" class="btn btn-outline-secondary btn-sm btn-qty" data-action="incr">+</button>
                                    </div>
                                    <button type="submit" class="btn btn-success btn-sm px-2" title="Aggiungi al carrello">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24">
                                            <path d="M7 18a2 2 0 1 0 0 4 2 2 0 0 0 0-4Zm10 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4ZM7.2 16h9.45c.9 0 1.7-.6 1.9-1.5l2.1-7.6c.2-.7-.3-1.4-1-1.4H6.21l-.94-4.1A1 1 0 0 0 4.3 1H1v2h2.3l3.6 15.59c.2.86 1 1.41 1.9 1.41h9.45v-2H7.2l-.2-.8ZM6.16 6h13.31l-1.71 6.2c-.13.47-.57.8-1.06.8H8.53l-2.37-7Z" fill="#fff" />
                                            <path d="M17 10V7h-2V5h-2v2h-2v2h2v2h2v-2h2Z" fill="#198754" />
                                        </svg>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if (empty($prodotti)): ?>
        <div class="alert alert-info mt-4">Nessun prodotto disponibile per questo negozio.</div>
    <?php endif; ?>
</main>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const row = document.getElementById('prodottiRow');
        const btn3 = document.getElementById('btn3');
        const btn4 = document.getElementById('btn4');
        if (btn3 && btn4) {
            btn3.addEventListener('click', function(e) {
                e.preventDefault();
                row.classList.remove('row-cols-lg-4');
                row.classList.add('row-cols-lg-3');
            });
            btn4.addEventListener('click', function(e) {
                e.preventDefault();
                row.classList.remove('row-cols-lg-3');
                row.classList.add('row-cols-lg-4');
            });
        }
        // Gestione + e - quantità nelle card
        document.querySelectorAll('.btn-qty').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const input = this.parentElement.querySelector('input[name="quantita"]');
                let val = parseInt(input.value) || 1;
                if (this.dataset.action === 'incr' && (!input.max || val < parseInt(input.max))) val++;
                if (this.dataset.action === 'decr' && val > 1) val--;
                input.value = val;
            });
        });
        // Disabilita tutti i form di aggiunta al carrello se negozio chiuso
        <?php if (!$is_aperto): ?>
            document.querySelectorAll('form[action="add_to_cart.php"]').forEach(function(form) {
                form.querySelectorAll('button[type="submit"], input, button').forEach(function(el) {
                    el.disabled = true;
                });
            });
        <?php endif; ?>
    });
</script>
<?php
// Mostra il carrello solo se utente loggato
if ($isUser) {
    require_once __DIR__ . '/includes/cart_functions.php';
?>
    <button id="cart-fab" class="shadow position-fixed">
        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="none" viewBox="0 0 24 24">
            <path d="M7 18a2 2 0 1 0 0 4 2 2 0 0 0 0-4Zm10 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4ZM7.2 16h9.45c.9 0 1.7-.6 1.9-1.5l2.1-7.6c.2-.7-.3-1.4-1-1.4H6.21l-.94-4.1A1 1 0 0 0 4.3 1H1v2h2.3l3.6 15.59c.2.86 1 1.41 1.9 1.41h9.45v-2H7.2l-.2-.8ZM6.16 6h13.31l-1.71 6.2c-.13.47-.57.8-1.06.8H8.53l-2.37-7Z" fill="#fff" />
        </svg>
        <?php $cart_count = get_cart_count();
        if ($cart_count > 0): ?>
            <span class="badge bg-danger rounded-pill"><?= $cart_count ?></span>
        <?php endif; ?>
    </button>
    <div id="cart-popup">
        <div class="p-3">
            <div class="mb-2 d-flex justify-content-between align-items-center">
                <b>Carrello</b>
                <button id="clear-cart-btn" class="btn btn-outline-danger btn-sm py-0 px-2" title="Svuota carrello" style="font-size:1.1em;line-height:1;">&times;</button>
            </div>
            <?php $cart_items = get_cart_items();
            if (empty($cart_items)): ?>
                <div class="text-center text-muted">Il carrello è vuoto</div>
            <?php else: ?>
                <?php foreach ($cart_items as $id => $item): ?>
                    <?php
                    // Recupera la quantità massima disponibile per il prodotto
                    $max_qty = null;
                    if (isset($_SESSION['negozio_id'])) {
                        require_once __DIR__ . '/db/connector.php';
                        $db_max = open_pg_connection();
                        $res_max = pg_query_params($db_max, 'SELECT quantita FROM greenify.dispone WHERE negozio_id = $1 AND prodotto_id = $2', [$_SESSION['negozio_id'], $id]);
                        if ($res_max && $row_max = pg_fetch_assoc($res_max)) {
                            $max_qty = intval($row_max['quantita']);
                        }
                        close_pg_connection($db_max);
                    }
                    ?>
                    <div class="d-flex justify-content-between align-items-center mb-1 small">
                        <span><?= htmlspecialchars($item['nome']) ?></span>
                        <span class="d-flex align-items-center gap-1">
                            <button class="btn btn-outline-secondary btn-sm btn-cart-qty"
                                data-id="<?= $id ?>" data-action="decr"
                                style="padding:0 6px;line-height:1;"
                                <?= ($item['quantita'] <= 1) ? 'disabled' : '' ?>>-</button>
                            <span class="mx-1">x<?= $item['quantita'] ?></span>
                            <button class="btn btn-outline-secondary btn-sm btn-cart-qty"
                                data-id="<?= $id ?>" data-action="incr"
                                style="padding:0 6px;line-height:1;"
                                <?= ($max_qty !== null && $item['quantita'] >= $max_qty) ? 'disabled' : '' ?>>+</button>
                            <span class="fw-bold ms-2">€<?= number_format($item['prezzo'] * $item['quantita'], 2, ',', '.') ?></span>
                            <button class="btn btn-link text-danger btn-sm py-0 px-1 remove-item-btn"
                                data-id="<?= $id ?>" data-action="remove" title="Rimuovi"
                                style="font-size:1.2em;line-height:1;">&times;</button>
                        </span>
                    </div>
                <?php endforeach; ?>
                <hr class="my-2">
                <div class="d-flex justify-content-between"><span>Totale:</span><span class="fw-bold">€<?= number_format(get_cart_total(), 2, ',', '.') ?></span></div>
                <div class="mt-2 text-end"><a href="pages/checkout.php" class="btn btn-success btn-sm">Vai all'ordine</a></div>
            <?php endif; ?>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const fab = document.getElementById('cart-fab');
            const popup = document.getElementById('cart-popup');
            fab.addEventListener('click', function() {
                popup.style.display = (popup.style.display === 'block') ? 'none' : 'block';
            });
            document.addEventListener('mousedown', function(e) {
                if (!popup.contains(e.target) && e.target !== fab && !fab.contains(e.target)) {
                    popup.style.display = 'none';
                }
            });

            // Aggiorna quantità o rimuovi prodotto
            document.querySelectorAll('.btn-cart-qty, .remove-item-btn').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const id = this.dataset.id;
                    const action = this.dataset.action;
                    fetch('includes/cart_functions.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: 'id=' + encodeURIComponent(id) + '&action=' + encodeURIComponent(action)
                    }).then(() => location.reload());
                });
            });
            // Svuota tutto il carrello
            const clearBtn = document.getElementById('clear-cart-btn');
            if (clearBtn) {
                clearBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    fetch('includes/cart_functions.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: 'clear_cart=1'
                    }).then(() => location.reload());
                });
            }
        });
    </script>
<?php } ?>

<!-- Mostra eventuale messaggio di errore se si tenta di aggiungere prodotti da negozi diversi -->
<?php if (isset($_GET['cart']) && $_GET['cart'] === 'wrongshop'): ?>
    <div class="alert alert-danger text-center mb-3">Non puoi aggiungere prodotti di negozi diversi nello stesso carrello. Svuota il carrello o completa l'ordine prima di cambiare negozio.</div>
<?php endif; ?>

<?php include_once 'components/footer.php'; ?>
<?php
close_pg_connection($db);
