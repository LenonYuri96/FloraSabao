<?php
/**
 * Flora Sabão — Guarda Central de Autenticação
 * -------------------------------------------------
 * Deve ser incluído ANTES de qualquer consulta ao banco
 * ou envio de HTML em páginas protegidas.
 *
 * Redireciona para login.php se não houver sessão ativa.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/functions.php';

// Detecta se estamos no root ou em /pages/
// (reaproveita a mesma lógica já usada em navbar/header/footer)
$GLOBALS['FS_URL_PREFIX'] = file_exists('pages') ? '' : '../';

// 🚧 GUARDA: se não estiver logado, manda para o login
if (empty($_SESSION['usuario_id'])) {
    $_SESSION['flash_erro'] = 'Faça login para acessar o sistema.';
    header('Location: ' . $GLOBALS['FS_URL_PREFIX'] . 'login.php');
    exit;
}