<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Limpa dados da sessão
$_SESSION = [];

// Expira o cookie de sessão
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

session_destroy();

// Cria nova sessão só para carregar a flash de logout
session_start();
$_SESSION['flash_sucesso'] = 'Sessão encerrada com sucesso.';

header('Location: login.php');
exit;