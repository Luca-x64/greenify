<?php
// login.php - Pagina di login utente
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: account.php');
    exit();
}
if (isset($_SESSION['manager_id'])) {
    header('Location: ../manager/dashboard.php');
    exit();
}
?>

<?php include_once '../components/header.php'; ?>

<body class="d-flex flex-column min-vh-100">
    <main class="container py-4 d-flex justify-content-center align-items-center" style="min-height:60vh;">
        <div class="card shadow-sm" style="max-width: 370px; width:100%;">
            <div class="card-body">
                <h2 class="mb-4 text-center">Login</h2>
                <?php
                if (isset($_COOKIE['login_error'])) {
                    echo '<div class="alert alert-danger text-center py-2">' . htmlspecialchars($_COOKIE['login_error']) . '</div>';
                    if ($_COOKIE['login_error'] === 'Email non trovata.') {
                        setcookie('login_email', '', time() - 3600, '/');
                    }
                    setcookie('login_error', '', time() - 3600, '/');
                }
                ?>
                <form method="post" action="../includes/process_login.php">
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" required
                            value="<?php
                                    // Mostra la mail solo se NON c'è errore "Email non trovata"
                                    if (isset($_COOKIE['login_error']) && $_COOKIE['login_error'] === 'Email non trovata.') {
                                        echo '';
                                    } elseif (!empty($_COOKIE['login_email'])) {
                                        echo htmlspecialchars($_COOKIE['login_email']);
                                    }
                                    ?>">
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    <button type="submit" class="btn btn-success w-100">Accedi</button>
                </form>
                <div class="mt-3 text-center">
                    <span>Non hai un account?</span>
                    <a href="register.php" class="link-success">Registrati qui</a>
                </div>
            </div>
        </div>
    </main>
    <script>
        window.addEventListener('DOMContentLoaded', function() {
            var alert = document.querySelector('.alert-danger');
            if (alert) setTimeout(function() {
                alert.style.display = 'none';
            }, 5000);
        });
    </script>
</body>
<?php include_once '../components/footer.php'; ?>