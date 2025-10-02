<?php
function open_pg_connection() {
    include_once(__DIR__ . '/../conf/conf.php');
    $connection = "host=" . myHost .
                  " dbname=" . myDb .
                  " user=" . myUser .
                  " password=" . myPassword;
    return pg_connect($connection);
}

function close_pg_connection($database) {
    if (
        (is_object($database) && $database instanceof \PgSql\Connection) ||
        (is_resource($database) && get_resource_type($database) === 'pgsql link')
    ) {
        return pg_close($database);
    }
    return true;
}

?>
