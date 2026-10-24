<?php
/**
 * Flora Sabão — Barra de Navegação
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Detecta se está na raiz ou em subpasta (/pages/)
$url_prefix = file_exists('pages') ? '' : '../';
$current_page = basename($_SERVER['PHP_SELF']);
?>
<nav class="navbar navbar-expand-lg navbar-dark navbar-flora shadow-sm mb-4">
    <div class="container-fluid px-4">
        <!-- Logo / Marca -->
        <a class="navbar-brand fw-bold d-flex align-items-center" href="<?= $url_prefix ?>index.php">
            <i class="bi bi-flower1 fs-4 me-2 text-warning"></i>
            <span>Flora Sabão <small class="fw-light opacity-75 fs-6">| PCP Lab</small></span>
        </a>

        <!-- Botão Mobile -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navFlora"
            aria-controls="navFlora" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Menu Colapsável -->
        <div class="collapse navbar-collapse" id="navFlora">
            <!-- Links Principais -->
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'index.php' ? 'active fw-semibold' : '' ?>"
                        href="<?= $url_prefix ?>index.php">
                        <i class="bi bi-speedometer2 me-1"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'ordens_producao.php' ? 'active fw-semibold' : '' ?>"
                        href="<?= $url_prefix ?>pages/ordens_producao.php">
                        <i class="bi bi-clipboard2-check me-1"></i> Ordens de Produção
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'programacao.php' ? 'active fw-semibold' : '' ?>"
                        href="<?= $url_prefix ?>pages/programacao.php">
                        <i class="bi bi-calendar3 me-1"></i> Sequenciamento & Setup
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'estoque.php' ? 'active fw-semibold' : '' ?>"
                        href="<?= $url_prefix ?>pages/estoque.php">
                        <i class="bi bi-boxes me-1"></i> Estoque & Kardex
                    </a>
                </li>
                <!-- Dropdown de Cadastros e Ficha Técnica -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= in_array($current_page, ['produtos.php', 'insumos.php']) ? 'active fw-semibold' : '' ?>"
                        href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-gear me-1"></i> Cadastros & BOM
                    </a>
                    <ul class="dropdown-menu shadow-sm">
                        <li>
                            <a class="dropdown-item <?= $current_page === 'produtos.php' ? 'active fw-semibold' : '' ?>"
                                href="<?= $url_prefix ?>pages/produtos.php">
                                <i class="bi bi-flower2 me-2 text-success"></i> Produtos & Ficha Técnica
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item <?= $current_page === 'insumos.php' ? 'active fw-semibold' : '' ?>"
                                href="<?= $url_prefix ?>pages/insumos.php">
                                <i class="bi bi-droplet me-2 text-success"></i> Catálogo de Insumos
                            </a>
                        </li>
                    </ul>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'relatorios.php' ? 'active fw-semibold' : '' ?>"
                        href="<?= $url_prefix ?>pages/relatorios.php">
                        <i class="bi bi-graph-up-arrow me-1"></i> Indicadores PCP
                    </a>
                </li>
            </ul>

            <!-- Usuário Logado / Autenticação (Alinhado à Direita DENTRO da Navbar) -->
            <div class="d-flex align-items-center gap-2 mt-2 mt-lg-0">
                <?php if (isset($_SESSION['usuario_nome'])): ?>
                    <span class="badge bg-light text-success fw-semibold py-2 px-3 border shadow-sm">
                        <i class="bi bi-person-circle me-1"></i> <?= htmlspecialchars($_SESSION['usuario_nome']) ?>
                        <span class="opacity-75">(<?= strtoupper($_SESSION['usuario_perfil'] ?? 'USER') ?>)</span>
                    </span>
                    <a href="<?= $url_prefix ?>logout.php" class="btn btn-sm btn-outline-light" title="Encerrar Sessão">
                        <i class="bi bi-box-arrow-right me-1"></i> Sair
                    </a>
                <?php else: ?>
                    <a href="<?= $url_prefix ?>login.php" class="btn btn-sm btn-light text-success fw-semibold shadow-sm">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Entrar
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>