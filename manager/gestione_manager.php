<?php
session_start();
require_once __DIR__ . '/../db/connector.php';

$db = open_pg_connection();
$alert_msg = null;
$alert_type = 'info';

$managers = [];
$res = pg_query($db, "SELECT m.mail, u.attivo, u.telefono, m.data_assunzione, m.data_licenziamento,
                             n.id AS negozio_id, i.citta, i.indirizzo
                      FROM greenify.manager m
                      JOIN greenify.utente u ON m.mail = u.mail
                      LEFT JOIN greenify.negozio n ON n.manager_mail = m.mail AND n.aperto = true
                      LEFT JOIN greenify.indirizzo i ON n.indirizzo_id = i.id
                      ORDER BY m.data_assunzione DESC");
if ($res) {
    while ($row = pg_fetch_assoc($res)) {
        $managers[] = $row;
    }
    pg_free_result($res);
}

if ($db) close_pg_connection($db);
?>
<?php include_once '../components/header.php'; ?>

<main class="container py-5">
    <h2>Gestione Manager</h2>

    <?php if ($alert_msg): ?>
        <div class="alert alert-<?= $alert_type ?> alert-dismissible fade show" role="alert">
            <?= $alert_msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
        </div>
    <?php endif; ?>

    <!-- Tabella manager -->
    <div class="card">
        <div class="card-header">
            Lista Manager
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped mb-0">
                    <thead>
                        <tr>
                            <th>Email</th>
                            <th>Telefono</th>
                            <th>Negozio Gestito</th>
                            <th>Data Assunzione</th>
                            <th>Data Licenziamento</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($managers as $m): ?>
                            <tr>
                                <td><?= htmlspecialchars($m['mail']) ?></td>
                                <td><?= htmlspecialchars($m['telefono']) ?></td>
                                <td>
                                    <?php if (!empty($m['negozio_id'])): ?>
                                        <?= htmlspecialchars($m['citta']) ?>,
                                        <?= htmlspecialchars($m['indirizzo']) ?>
                                        <span class="text-muted small ms-1">(#<?= htmlspecialchars($m['negozio_id']) ?>)</span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($m['data_assunzione']) ?></td>
                                <td>
                                    <?php if (!empty($m['data_licenziamento'])): ?>
                                        <span class="badge bg-danger">Licenziato</span>
                                        <span><?= htmlspecialchars($m['data_licenziamento']) ?></span>
                                    <?php else: ?>
                                        <?= htmlspecialchars($m['data_licenziamento']) ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($managers)): ?>
                            <tr>
                                <td colspan="5" class="text-center">Nessun manager trovato.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>
<?php include_once '../components/footer.php'; ?>