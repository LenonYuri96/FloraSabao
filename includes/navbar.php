<?php
/**
 * Flora Sabão — Barra de Navegação
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Detecta se está na raiz ou em subpasta (/pages/)
$url_prefix   = file_exists('pages') ? '' : '../';
$current_page = basename($_SERVER['PHP_SELF']);

// =============================================================
// ★ AJUSTE: o link "Dashboard" muda conforme o tipo de usuário
//   - admin  → painel do instrutor (pages/instrutor.php)
//   - equipe → painel da equipe (index.php)
// =============================================================
$is_admin_nav     = (($_SESSION['usuario_perfil'] ?? '') === 'admin');

$dashboard_href   = $is_admin_nav
    ? $url_prefix . 'pages/instrutor.php'
    : $url_prefix . 'index.php';

$dashboard_label  = $is_admin_nav ? 'Painel do Instrutor' : 'Dashboard';
$dashboard_icon   = $is_admin_nav ? 'bi-speedometer2'     : 'bi-speedometer2';

$dashboard_active = $is_admin_nav
    ? ($current_page === 'instrutor.php' ? 'active fw-semibold' : '')
    : ($current_page === 'index.php'     ? 'active fw-semibold' : '');
?>
<nav class="navbar navbar-expand-lg navbar-dark navbar-flora shadow-sm mb-4">
    <div class="container-fluid px-4">
        <!-- Logo / Marca -->
        <a class="navbar-brand fw-bold d-flex align-items-center" href="<?= $dashboard_href ?>">
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
                    <a class="nav-link <?= $dashboard_active ?>" href="<?= $dashboard_href ?>">
                        <i class="bi <?= $dashboard_icon ?> me-1"></i> <?= $dashboard_label ?>
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
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'estudo_caso.php' ? 'active fw-semibold' : '' ?>"
                        href="<?= $url_prefix ?>pages/estudo_caso.php">
                        <i class="bi bi-mortarboard me-1"></i> Estudo de Caso & Lean
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'ia_consultor.php' ? 'active fw-semibold' : '' ?>"
                        href="<?= $url_prefix ?>pages/ia_consultor.php">
                        <i class="bi bi-robot text-warning me-1"></i> Copiloto IA
                    </a>
                </li>
            </ul>

            <!-- Usuário Logado & Alternador de Equipes -->
            <div class="d-flex align-items-center gap-2 mt-2 mt-lg-0">
                <?php if (isset($_SESSION['usuario_nome'])): ?>
                    <?php if ($is_admin_nav): ?>
                        <!-- Menu do Instrutor: Alternador de Visualização -->
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-warning dropdown-toggle fw-semibold"
                                    type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-eye me-1"></i> Vendo: Equipe <?= getEquipeAtivaId() ?>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                <li>
                                    <h6 class="dropdown-header">Ver Dashboard de uma Equipe:</h6>
                                </li>
                                <?php for ($eq = 1; $eq <= 6; $eq++): ?>
                                    <li>
                                        <!-- ★ AJUSTE: link explícito para index.php com equipe_id
                                             (antes era só ?equipe_id=X, que não navegava) -->
                                        <a class="dropdown-item"
                                           href="<?= $url_prefix ?>index.php?equipe_id=<?= $eq ?>">
                                            🟢 Equipe <?= $eq ?> — Dashboard
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item fw-bold text-danger"
                                       href="<?= $url_prefix ?>pages/instrutor.php">
                                        <i class="bi bi-controller me-1"></i> Painel do Mestre
                                    </a>
                                </li>
                            </ul>
                        </div>
                    <?php else: ?>
                        <span class="badge bg-light text-success fw-semibold py-2 px-3 border shadow-sm">
                            <i class="bi bi-shield-shaded me-1"></i> <?= htmlspecialchars($_SESSION['usuario_nome']) ?>
                        </span>
                    <?php endif; ?>

                    <a href="<?= $url_prefix ?>logout.php" class="btn btn-sm btn-outline-light" title="Encerrar Sessão">
                        <i class="bi bi-box-arrow-right me-1"></i> Sair
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>