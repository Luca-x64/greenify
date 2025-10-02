<?php

/**
 * @param resource|\PgSql\Connection $db
 */
function registra_utente_cliente($db, $email, $password, $telefono, $cf, $nome, $cognome, $data_nascita = null)
{
    $sql = 'SELECT greenify.fn_inserisci_utente_cliente($1, $2, $3, $4, $5, $6, $7)';
    $params = [$email, $password, $telefono, $cf, $nome, $cognome, $data_nascita];
    $res = pg_query_params($db, $sql, $params);
    if (!$res) {
        return ['success' => false, 'error' => pg_last_error($db)];
    }
    $row = pg_fetch_row($res);
    pg_free_result($res);
    return ['success' => true, 'result' => $row[0] ?? null];
}

/**
 * Aggiorna gli orari di un negozio.
 * @param resource|\PgSql\Connection $db connessione pgsql
 * @param int $negozio_id
 * @param array $nuovi_orari array associativo [giorno => ['ora_inizio' => ..., 'ora_fine' => ...], ...]
 * @return bool true se successo, false altrimenti
 */
function aggiorna_orari_negozio($db, $negozio_id, $nuovi_orari)
{
    // Elimina tutti gli orari attuali
    $del = pg_query_params($db, "DELETE FROM greenify.orario WHERE negozio_id = $1", [$negozio_id]);
    if (!$del) return false;

    // Inserisci i nuovi orari
    foreach ($nuovi_orari as $giorno => $orari) {
        $ora_inizio = $orari['ora_inizio'];
        $ora_fine = $orari['ora_fine'];
        if ($ora_inizio && $ora_fine) {
            $ins = pg_query_params($db, "INSERT INTO greenify.orario (giorno, negozio_id, ora_inizio, ora_fine) VALUES ($1, $2, $3, $4)", [$giorno, $negozio_id, $ora_inizio, $ora_fine]);
            if (!$ins) return false;
        }
    }
    return true;
}
