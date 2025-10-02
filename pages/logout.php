<?php
// logout.php - Disconnette l'utente
session_start();
session_unset();
session_destroy();
header('Location: ../index.php');
exit();
