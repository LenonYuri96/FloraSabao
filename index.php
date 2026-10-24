<?php
declare(strict_types=1);

// 🔐 GUARDA DE AUTENTICAÇÃO — deve vir PRIMEIRO
require_once __DIR__ . '/includes/auth.php';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';

$page_title = 'Painel do PCP';

// ---------------------------------------------------------
// CONSULTAS DO DASHBOARD (Métricas e Alertas)
// ---------------------------------------------------------

// 1. Total de OPs ativas (planejadas ou em produção)
$stmt = $pdo->query("SELECT COUNT(*) FROM ordens_producao WHERE status IN ('planejada', 'em_producao')");
$total_ops_ativas = (int) $stmt->fetchColumn();

// 2. Volume produzido no mês atual
$stmt = $pdo->query("
    SELECT COALESCE(SUM(quantidade_produzida), 0) AS total_produzido,
           COALESCE(SUM(quantidade_refugo), 0) AS total_refugo
    FROM ordens_producao 
    WHERE MONTH(data_programada) = MONTH(CURRENT_DATE()) 
      AND YEAR(data_programada) = YEAR(CURRENT_DATE())
");
$metr_prod = $stmt->fetch();
$total_produzido = (int) $metr_prod['total_produzido'];
$total_refugo = (int) $metr_prod['total_refugo'];

// Percentual de ocupação da capacidade mensal
$perc_capacidade = min(100, round(($total_produzido / CAPACIDADE_MENSAL_BARRAS) * 100, 1));

// 3. Insumos em estado crítico (estoque atual <= estoque mínimo)
$stmt = $pdo->query("
    SELECT codigo, nome, unidade, estoque_atual, estoque_minimo
    FROM insumos
    WHERE estoque_atual <= estoque_minimo
    ORDER BY (estoque_atual / estoque_minimo) ASC
");
$insumos_criticos = $stmt->fetchAll();

// 4. Saldo dos 4 Produtos Acabados
$stmt = $pdo->query("SELECT id, codigo, nome, aroma, intensidade_aroma, estoque_atual, estoque_minimo FROM produtos ORDER BY id ASC");
$produtos = $stmt->fetchAll();

// 5. Lista das últimas 5 Ordens de Produção
$stmt = $pdo->query("
    SELECT op.id, op.numero, op.quantidade_planejada, op.quantidade_produzida, 
           op.data_programada, op.status, op.observacoes,
           p.nome AS produto_nome, p.codigo AS produto_codigo
    FROM ordens_producao op
    JOIN produtos p ON op.produto_id = p.id
    ORDER BY op.data_programada ASC, op.id DESC
    LIMIT 6
");
$ordens = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="container-fluid px-4 pb-4">
    <!-- Cabeçalho da Página -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-1 text-dark">
                <i class="bi bi-speedometer2 text-success me-2"></i>Painel de Controle da Produção
            </h2>
            <p class="text-muted mb-0">Visão integrada de ordens, capacidade produtiva e riscos de insumo.</p>
        </div>
        <div>
            <a href="pages/ordens_producao.php" class="btn btn-success shadow-sm">
                <i class="bi bi-plus-circle me-1"></i> Nova Ordem de Produção
            </a>
        </div>
    </div>

    <!-- Alerta Didático de Estoque Crítico (se houver ruptura iminente) -->
    <?php if (!empty($insumos_criticos)): ?>
    <div class="alert alert-danger border-0 shadow-sm d-flex align-items-center mb-4" role="alert">
        <i class="bi bi-exclamation-triangle-fill fs-3 me-3 text-danger"></i>
        <div>
            <strong class="d-block">Atenção PCP: <?= count($insumos_criticos) ?> insumo(s) em nível crítico de
                reposição!</strong>
            <span>
                <?php
                    $nomes_criticos = array_map(function ($i) {
                        return "<strong>{$i['nome']}</strong> (" . number_format((float) $i['estoque_atual'], 2, ',', '.') . " {$i['unidade']})";
                    }, $insumos_criticos);
                    echo implode(', ', $nomes_criticos);
                    ?>. Verifique a lista antes de autorizar novos lotes.
            </span>
        </div>
    </div>
    <?php endif; ?>

    <!-- 4 Cards de Métricas (KPIs) -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-custom p-3 bg-white h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">OPs em Andamento</span>
                        <h3 class="fw-bold my-1 text-dark"><?= $total_ops_ativas ?></h3>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Fila de
                            Produção</span>
                    </div>
                    <div class="bg-primary-subtle text-primary p-3 rounded-3">
                        <i class="bi bi-gear-wide-connected fs-4"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-custom p-3 bg-white h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Produzido no Mês</span>
                        <h3 class="fw-bold my-1 text-success"><?= number_format($total_produzido, 0, ',', '.') ?> <small
                                class="fs-6 text-muted">barras</small></h3>
                        <small class="text-muted"><?= $perc_capacidade ?>% da capacidade mensal</small>
                    </div>
                    <div class="bg-success-subtle text-success p-3 rounded-3">
                        <i class="bi bi-box-seam fs-4"></i>
                    </div>
                </div>
                <div class="progress mt-2" style="height: 6px;">
                    <div class="progress-bar bg-success" style="width: <?= $perc_capacidade ?>%"></div>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-custom p-3 bg-white h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Perdas / Refugo</span>
                        <h3 class="fw-bold my-1 text-danger"><?= number_format($total_refugo, 0, ',', '.') ?> <small
                                class="fs-6 text-muted">barras</small></h3>
                        <small class="text-muted">Taxa de refugo:
                            <?= $total_produzido > 0 ? round(($total_refugo / ($total_produzido + $total_refugo)) * 100, 2) : 0 ?>%</small>
                    </div>
                    <div class="bg-danger-subtle text-danger p-3 rounded-3">
                        <i class="bi bi-trash fs-4"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-custom p-3 bg-white h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Insumos Críticos</span>
                        <h3 class="fw-bold my-1 <?= count($insumos_criticos) > 0 ? 'text-danger' : 'text-success' ?>">
                            <?= count($insumos_criticos) ?>
                        </h3>
                        <small
                            class="text-muted"><?= count($insumos_criticos) > 0 ? 'Exigem reposição urgente' : 'Estoque equilibrado' ?></small>
                    </div>
                    <div
                        class="<?= count($insumos_criticos) > 0 ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success' ?> p-3 rounded-3">
                        <i class="bi bi-bell fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Tabela: Ordens de Produção em Destaque -->
        <div class="col-12 col-lg-8">
            <div class="card card-custom p-3 bg-white h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-list-task me-2 text-secondary"></i>Ordens de Produção Recentes
                    </h5>
                    <a href="pages/ordens_producao.php" class="btn btn-sm btn-outline-secondary">Ver Todas</a>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-uppercase">
                            <tr>
                                <th>OP</th>
                                <th>Fragrância</th>
                                <th>Data Prog.</th>
                                <th>Qtd.</th>
                                <th>Status</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($ordens)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">Nenhuma Ordem de Produção
                                    cadastrada.</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($ordens as $op):
                                    $st = STATUS_OP[$op['status']] ?? ['label' => $op['status'], 'badge' => 'secondary'];
                                    ?>
                            <tr>
                                <td class="fw-bold text-dark"><?= htmlspecialchars($op['numero']) ?></td>
                                <td>
                                    <span
                                        class="badge bg-light text-dark border me-1"><?= htmlspecialchars($op['produto_codigo']) ?></span>
                                    <?= htmlspecialchars($op['produto_nome']) ?>
                                </td>
                                <td><?= date('d/m/Y', strtotime($op['data_programada'])) ?></td>
                                <td>
                                    <strong><?= $op['quantidade_produzida'] ?></strong> /
                                    <?= $op['quantidade_planejada'] ?> un
                                </td>
                                <td>
                                    <span class="badge bg-<?= $st['badge'] ?> badge-status">
                                        <?= $st['label'] ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="pages/ordens_producao.php?id=<?= $op['id'] ?>"
                                        class="btn btn-sm btn-outline-primary" title="Detalhes">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Painel Lateral: Fragrâncias e Estoque Acabado -->
        <div class="col-12 col-lg-4">
            <div class="card card-custom p-3 bg-white h-100">
                <h5 class="fw-bold mb-3 text-dark">
                    <i class="bi bi-flower2 me-2 text-success"></i>Fragrâncias em Linha
                </h5>

                <div class="list-group list-group-flush">
                    <?php foreach ($produtos as $p):
                        $alerta_estoque = $p['estoque_atual'] <= $p['estoque_minimo'];
                        ?>
                    <div class="list-group-item px-0 py-3 border-bottom">
                        <div class="d-flex justify-content-between align-items-start mb-1">
                            <div>
                                <strong class="text-dark"><?= htmlspecialchars($p['nome']) ?></strong>
                                <div class="small text-muted">
                                    Intensidade: <span
                                        class="badge bg-secondary-subtle text-secondary"><?= ucfirst($p['intensidade_aroma']) ?></span>
                                </div>
                            </div>
                            <div class="text-end">
                                <span class="fw-bold fs-6 <?= $alerta_estoque ? 'text-danger' : 'text-dark' ?>">
                                    <?= $p['estoque_atual'] ?> un
                                </span>
                                <div class="small text-muted">Mín: <?= $p['estoque_minimo'] ?> un</div>
                            </div>
                        </div>
                        <?php if ($alerta_estoque): ?>
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle small mt-1">
                            <i class="bi bi-arrow-down-circle me-1"></i>Abaixo do estoque mínimo
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="mt-3 pt-2">
                    <a href="pages/programacao.php" class="btn btn-outline-success w-100">
                        <i class="bi bi-diagram-3 me-1"></i> Ver Sequenciador de Setup
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';