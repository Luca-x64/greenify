<?php
define("myHost", "localhost");
define("myUser", "greenify_admin");
define("myDb", "greenify_db");
define("myPassword", "Green42.");

define("ROOT_PATH", '/' . basename(dirname(__DIR__))); 
define("PAGES_PATH", ROOT_PATH . '/pages');
define("MANAGER_PATH", ROOT_PATH . '/manager');
define("INDEX_PATH", ROOT_PATH . '/index.php');
// define("DB_PATH", ROOT_PATH . '/db');
define("CURRENT_PAGE", basename($_SERVER['SCRIPT_NAME']));

?>
