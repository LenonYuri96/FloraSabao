<?php
/**
 * Flora Sabão — Barra de Navegação (v3.2)
 * =============================================================
 * - Sticky top com sombra
 * - Menu mobile em "card escuro" com seções
 * - Labels curtos para não quebrar linha
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$url_prefix   = file_exists('pages') ? '' : '../';
$current_page = basename($_SERVER['PHP_SELF']);

$is_admin_nav     = (($_SESSION['usuario_perfil'] ?? '') === 'admin');
$equipe_ativa_id  = function_exists('getEquipeAtivaId') ? getEquipeAtivaId() : 1;

$dashboard_href   = $is_admin_nav
    ? $url_prefix . 'pages/instrutor.php'
    : $url_prefix . 'index.php';

// Encurtado para "Painel" para não ocupar muito espaço
$dashboard_label  = $is_admin_nav ? 'Painel' : 'Dashboard';

$dashboard_active = $is_admin_nav
    ? ($current_page === 'instrutor.php' ? 'active fw-semibold' : '')
    : ($current_page === 'index.php'     ? 'active fw-semibold' : '');

$eqs_nav = [];
if ($is_admin_nav && isset($pdo) && function_exists('equipesDisponiveis')) {
    try {
        $eqs_nav = equipesDisponiveis($pdo);
    } catch (Throwable $e) {
        $eqs_nav = [];
    }
}

$equipe_nome_atual = 'Equipe ' . $equipe_ativa_id;
foreach ($eqs_nav as $eqn) {
    if ((int) $eqn['id'] === $equipe_ativa_id) {
        $equipe_nome_atual = (string) $eqn['nome'];
        break;
    }
}
?>
<nav class="navbar navbar-expand-lg navbar-dark navbar-flora shadow-sm mb-4 sticky-top">
    <div class="container-fluid px-3 px-lg-4">

        <!-- MARCA -->
        <a class="navbar-brand fw-bold d-flex align-items-center gap-2 m-0" href="<?= $dashboard_href ?>">
            <i class="bi bi-flower1 fs-3 text-warning"></i>
            <div class="d-flex flex-column lh-1">
                <span style="font-size: 1.05rem; letter-spacing: -0.02em;">Flora Sabão</span>
                <small class="fw-light opacity-75" style="font-size: 0.62rem; letter-spacing: 0.1em;">PCP · BUSINESS GAME</small>
            </div>
        </a>

        <!-- BOTÃO HAMBÚRGUER -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navFlora"
            aria-controls="navFlora" aria-expanded="false" aria-label="Toggle navigation">
            <i class="bi bi-list fs-3"></i>
        </button>

        <!-- CONTEÚDO COLAPSÁVEL -->
        <div class="collapse navbar-collapse" id="navFlora">

            <!-- INFO DO USUÁRIO (apenas mobile) -->
            <div class="d-lg-none user-info-mobile mb-3 pb-3">
                <?php if (isset($_SESSION['usuario_nome'])): ?>
                    <div class="d-flex align-items-center gap-2">
                        <div class="rounded-circle bg-light bg-opacity-25 d-flex align-items-center justify-content-center"
                             style="width: 40px; height: 40px;">
                            <i class="bi bi-person-fill text-white fs-5"></i>
                        </div>
                        <div class="flex-grow-1 min-w-0">
                            <div class="fw-semibold text-white text-truncate" style="font-size: 0.92rem;">
                                <?= htmlspecialchars($_SESSION['usuario_nome']) ?>
                            </div>
                            <small class="text-white-50" style="font-size: 0.72rem;">
                                <?= $is_admin_nav ? 'Instrutor' : 'Equipe' ?>
                            </small>
                        </div>
                        <a href="<?= $url_prefix ?>logout.php" class="btn btn-sm btn-outline-light" title="Sair">
                            <i class="bi bi-box-arrow-right"></i>
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- MENU PRINCIPAL -->
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link <?= $dashboard_active ?>" href="<?= $dashboard_href ?>">
                        <i class="bi bi-speedometer2"></i>
                        <span><?= $dashboard_label ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'ordens_producao.php' ? 'active fw-semibold' : '' ?>"
                        href="<?= $url_prefix ?>pages/ordens_producao.php">
                        <i class="bi bi-clipboard2-check"></i>
                        <span>Ordens</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'programacao.php' ? 'active fw-semibold' : '' ?>"
                        href="<?= $url_prefix ?>pages/programacao.php">
                        <i class="bi bi-calendar3"></i>
                        <span>Programação</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'estoque.php' ? 'active fw-semibold' : '' ?>"
                        href="<?= $url_prefix ?>pages/estoque.php">
                        <i class="bi bi-boxes"></i>
                        <span>Estoque</span>
                    </a>
                </li>

                <!-- Dropdown: Cadastros -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= in_array($current_page, ['produtos.php', 'insumos.php']) ? 'active fw-semibold' : '' ?>"
                        href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-gear"></i>
                        <span>Cadastros</span>
                    </a>
                    <ul class="dropdown-menu shadow-sm">
                        <li>
                            <a class="dropdown-item <?= $current_page === 'produtos.php' ? 'active' : '' ?>"
                                href="<?= $url_prefix ?>pages/produtos.php">
                                <i class="bi bi-flower2 text-success"></i> Produtos & Ficha Técnica
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item <?= $current_page === 'insumos.php' ? 'active' : '' ?>"
                                href="<?= $url_prefix ?>pages/insumos.php">
                                <i class="bi bi-droplet text-success"></i> Catálogo de Insumos
                            </a>
                        </li>
                    </ul>
                </li>

                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'relatorios.php' ? 'active fw-semibold' : '' ?>"
                        href="<?= $url_prefix ?>pages/relatorios.php">
                        <i class="bi bi-graph-up-arrow"></i>
                        <span>Indicadores</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'estudo_caso.php' ? 'active fw-semibold' : '' ?>"
                        href="<?= $url_prefix ?>pages/estudo_caso.php">
                        <i class="bi bi-mortarboard"></i>
                        <span>Estudo</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'ia_consultor.php' ? 'active fw-semibold' : '' ?>"
                        href="<?= $url_prefix ?>pages/ia_consultor.php">
                        <i class="bi bi-robot text-warning"></i>
                        <span>IA</span>
                    </a>
                </li>
            </ul>

            <!-- ÁREA DO USUÁRIO — DESKTOP (lg+) -->
            <div class="d-none d-lg-flex align-items-center gap-2 mt-2 mt-lg-0">
                <?php if (isset($_SESSION['usuario_nome'])): ?>
                    <?php if ($is_admin_nav): ?>
                        <!-- Dropdown do Instrutor (desktop) -->
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-warning dropdown-toggle fw-semibold d-flex align-items-center gap-1"
                                    type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-eye"></i>
                                <!-- Mostra o nome completo da equipe e deixa o CSS truncar se necessário -->
                                <span class="text-truncate" style="max-width: 180px;">
                                    <?= htmlspecialchars($equipe_nome_atual) ?>
                                </span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                <li><h6 class="dropdown-header">Alternar Equipe</h6></li>
                                <?php if (empty($eqs_nav)): ?>
                                    <?php for ($eq = 1; $eq <= 6; $eq++): ?>
                                        <li>
                                            <a class="dropdown-item <?= $eq === $equipe_ativa_id ? 'active' : '' ?>"
                                               href="<?= $url_prefix ?>index.php?equipe_id=<?= $eq ?>">
                                                <i class="bi bi-people me-1"></i> Equipe <?= $eq ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                <?php else: ?>
                                    <?php foreach ($eqs_nav as $eqn):
                                        $eq_id_nav = (int) $eqn['id'];
                                        $is_current = ($eq_id_nav === $equipe_ativa_id);
                                    ?>
                                        <li>
                                            <a class="dropdown-item <?= $is_current ? 'active' : '' ?>"
                                               href="<?= $url_prefix ?>index.php?equipe_id=<?= $eq_id_nav ?>">
                                                <span class="badge me-1" style="background-color: <?= htmlspecialchars($eqn['cor_hex'] ?? '#2d5a3f') ?>;">
                                                    &nbsp;
                                                </span>
                                                <?= htmlspecialchars($eqn['nome']) ?>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item fw-bold text-danger" href="<?= $url_prefix ?>pages/instrutor.php">
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

                    <a href="<?= $url_prefix ?>logout.php"
                       class="btn btn-sm btn-outline-light d-flex align-items-center gap-1" title="Sair">
                        <i class="bi bi-box-arrow-right"></i>
                    </a>
                <?php endif; ?>
            </div>

            <!-- ÁREA DO INSTRUTOR — MOBILE -->
            <?php if ($is_admin_nav): ?>
                <div class="d-lg-none mt-3 pt-3 admin-mobile-footer">
                    <h6 class="text-white-50 small text-uppercase fw-bold mb-2" style="letter-spacing: 0.08em;">
                        <i class="bi bi-eye me-1"></i> Equipe em Visualização
                    </h6>
                    <div class="d-grid gap-1 mb-2" style="grid-template-columns: repeat(3, 1fr);">
                        <?php if (empty($eqs_nav)): ?>
                            <?php for ($eq = 1; $eq <= 6; $eq++):
                                $btn_class = ($eq === $equipe_ativa_id) ? 'btn-warning text-dark' : 'btn-outline-light';
                            ?>
                                <a class="btn btn-sm <?= $btn_class ?> fw-semibold" href="<?= $url_prefix ?>index.php?equipe_id=<?= $eq ?>">
                                    Eq. <?= $eq ?>
                                </a>
                            <?php endfor; ?>
                        <?php else: ?>
                            <?php foreach ($eqs_nav as $eqn):
                                $eq_id_nav = (int) $eqn['id'];
                                $btn_class = ($eq_id_nav === $equipe_ativa_id) ? 'btn-warning text-dark' : 'btn-outline-light';
                            ?>
                                <a class="btn btn-sm <?= $btn_class ?> fw-semibold text-truncate" href="<?= $url_prefix ?>index.php?equipe_id=<?= $eq_id_nav ?>">
                                    <?= htmlspecialchars($eqn['nome']) ?>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <a href="<?= $url_prefix ?>pages/instrutor.php" class="btn btn-sm btn-danger w-100 fw-bold mt-1">
                        <i class="bi bi-controller me-1"></i> Painel do Mestre
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</nav>