<?php
/**
 * Flora Sabão — Funções Auxiliares Globais
 */
declare(strict_types=1);

// ---------------------------------------------------------
// FORMATAÇÃO
// ---------------------------------------------------------
function formatarMoeda(float $valor): string
{
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

function formatarQtd(float $qtd, int $decimais = 4): string
{
    return number_format($qtd, $decimais, ',', '.');
}

function h(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------
// BADGE DE STATUS DA OP
// ---------------------------------------------------------
function renderBadgeStatusOP(string $status): string
{
    $mapa = [
        'planejada' => ['label' => 'Planejada', 'class' => 'bg-secondary text-white'],
        'em_producao' => ['label' => 'Em Produção', 'class' => 'bg-warning text-dark'],
        'finalizada' => ['label' => 'Finalizada', 'class' => 'bg-success text-white'],
        'cancelada' => ['label' => 'Cancelada', 'class' => 'bg-danger text-white'],
    ];
    $info = $mapa[$status] ?? ['label' => ucfirst($status), 'class' => 'bg-light text-dark border'];
    return "<span class=\"badge {$info['class']} badge-status\">{$info['label']}</span>";
}

// ---------------------------------------------------------
// 🔐 SESSÃO / AUTENTICAÇÃO
// ---------------------------------------------------------
function estaLogado(): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return !empty($_SESSION['usuario_id']);
}

function usuarioAtual(): ?array
{
    if (!estaLogado()) {
        return null;
    }
    return [
        'id' => (int) $_SESSION['usuario_id'],
        'nome' => $_SESSION['usuario_nome'] ?? '',
        'email' => $_SESSION['usuario_email'] ?? '',
        'perfil' => $_SESSION['usuario_perfil'] ?? 'user',
    ];
}

/**
 * Verifica se o usuário logado possui um dos perfis exigidos.
 * Para admins, sempre libera.
 */
function possuiPerfil(array $perfisPermitidos): bool
{
    if (!estaLogado()) {
        return false;
    }
    $perfil = $_SESSION['usuario_perfil'] ?? '';
    return $perfil === 'admin' || in_array($perfil, $perfisPermitidos, true);
}

/**
 * Exige que o usuário esteja logado E tenha um dos perfis.
 * Redireciona com flash caso contrário.
 */
function exigirPerfis(array $perfisPermitidos): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $prefix = file_exists('pages') ? '' : '../';

    if (!estaLogado()) {
        $_SESSION['flash_erro'] = 'Faça login para acessar o sistema.';
        header('Location: ' . $prefix . 'login.php');
        exit;
    }

    if (!possuiPerfil($perfisPermitidos)) {
        $_SESSION['flash_erro'] = 'Seu perfil não tem permissão para acessar esta área.';
        header('Location: ' . $prefix . 'index.php');
        exit;
    }
}

// ---------------------------------------------------------
// 📢 FLASH MESSAGES
// ---------------------------------------------------------
function flash(string $tipo, string $mensagem): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash_' . $tipo] = $mensagem;
}

function lerFlash(string $tipo): ?string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $key = 'flash_' . $tipo;
    if (!isset($_SESSION[$key])) {
        return null;
    }
    $msg = $_SESSION[$key];
    unset($_SESSION[$key]);
    return $msg;
}