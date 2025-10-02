<?php // nav_buttons.php - pulsanti dinamici della navbar
require_once __DIR__ . '/../conf/conf.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$isUser = isset($_SESSION['user_id']);
$isManager = isset($_SESSION['manager_id']);
?>
<ul class="navbar-nav align-items-center gap-2 fixed-nav-icons">
    <?php if ($isManager): ?>
        <?php if (!defined('CURRENT_PAGE') || CURRENT_PAGE !== 'dashboard.php'): ?>
            <li class="nav-item">
                <a class="nav-link" href="<?= MANAGER_PATH ?>/dashboard.php" title="Home" style="background-color:#198754;border-radius:50%;padding:0.2em;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="38" height="38" fill="#fff" viewBox="0 0 24 24">
                        <path d="M3 11.5L12 4l9 7.5" stroke="#198754" stroke-width="2" fill="none" />
                        <rect x="7" y="13" width="10" height="7" rx="2" fill="#198754" />
                        <rect x="7" y="13" width="10" height="7" rx="2" stroke="#145c32" stroke-width="1" />
                    </svg>
                </a>
            </li>
        <?php endif; ?>
        <li class="nav-item">
            <a class="nav-link" href="<?= PAGES_PATH  ?>/logout.php" title="Logout">
                <svg xmlns="http://www.w3.org/2000/svg" width="38" height="38" fill="none" viewBox="0 0 24 24">
                    <rect x="3" y="16" width="18" height="5" rx="2.5" fill="#d9534f" />
                    <path d="M16 13v-2H7V8l-5 4 5 4v-3h9Zm3-10H5c-1.1 0-2 .9-2 2v6h2V5h14v14H5v-4H3v6c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2Z" fill="#dc3545" />
                </svg>
            </a>
        </li>
    <?php elseif ($isUser): ?>
        <li class="nav-item">
            <a class="nav-link" href="<?= ROOT_PATH ?>/index.php" title="Home" style="background-color:#198754;border-radius:50%;padding:0.2em;">
                <svg xmlns="http://www.w3.org/2000/svg" width="38" height="38" fill="#fff" viewBox="0 0 24 24">
                    <path d="M3 11.5L12 4l9 7.5" stroke="#198754" stroke-width="2" fill="none" />
                    <rect x="7" y="13" width="10" height="7" rx="2" fill="#198754" />
                    <rect x="7" y="13" width="10" height="7" rx="2" stroke="#145c32" stroke-width="1" />
                </svg>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= PAGES_PATH ?>/logout.php" title="Logout">
                <svg xmlns="http://www.w3.org/2000/svg" width="38" height="38" fill="none" viewBox="0 0 24 24">
                    <rect x="3" y="16" width="18" height="5" rx="2.5" fill="#d9534f" />
                    <path d="M16 13v-2H7V8l-5 4 5 4v-3h9Zm3-10H5c-1.1 0-2 .9-2 2v6h2V5h14v14H5v-4H3v6c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2Z" fill="#dc3545" />
                </svg>
            </a>
        </li>
    <?php else: ?>
        <?php if (CURRENT_PAGE !== 'login.php'): ?>
            <li class="nav-item">
                <a class="nav-link" href="<?= PAGES_PATH ?>/login.php" title="Login">
                    <svg xmlns="http://www.w3.org/2000/svg" width="38" height="38" fill="none" viewBox="0 0 24 24">
                        <circle cx="12" cy="8" r="6" fill="#198754" stroke="#145c32" stroke-width="2" />
                        <rect x="3" y="16" width="18" height="5" rx="2.5" fill="#145c32" />
                        <path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z" fill="#fff" fill-opacity=".2" />
                    </svg>
                </a>
            </li>
        <?php endif; ?>
        <?php if (CURRENT_PAGE !== 'register.php'): ?>
            <li class="nav-item">
                <a class="nav-link" href="<?= PAGES_PATH ?>/register.php" title="Registrati">
                    <svg xmlns="http://www.w3.org/2000/svg" width="38" height="38" fill="none" viewBox="0 0 24 24">
                        <circle cx="12" cy="8" r="6" fill="#145c32" stroke="#198754" stroke-width="2" />
                        <rect x="3" y="16" width="18" height="5" rx="2.5" fill="#198754" />
                        <path d="M19 8v2h-2v2h-2v-2h-2V8h2V6h2v2h2Z" fill="#fff" />
                    </svg>
                </a>
            </li>
        <?php endif; ?>
    <?php endif; ?>

    <?php
    // Mostra pulsante account per utente e manager (se non già su account.php)
    if (($isUser || $isManager) && (CURRENT_PAGE !== 'account.php')): ?>
        <li class="nav-item">
            <a class="nav-link" href="<?= PAGES_PATH ?>/account.php" title="Gestione Account">
                <svg xmlns="http://www.w3.org/2000/svg" width="38" height="38" fill="none" viewBox="0 0 24 24">
                    <circle cx="12" cy="8" r="6" fill="#198754" stroke="#145c32" stroke-width="2" />
                    <rect x="3" y="16" width="18" height="5" rx="2.5" fill="#145c32" />
                </svg>
            </a>
        </li>
    <?php endif; ?>
</ul>