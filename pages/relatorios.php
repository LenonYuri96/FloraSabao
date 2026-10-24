<?php
declare(strict_types=1);

// 🔐 GUARDA DE AUTENTICAÇÃO — deve vir PRIMEIRO
require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
$page_title = 'Indicadores de Desempenho do PCP';

// ---------------------------------------------------------
// FILTRO DE PERÍODO (Mês / Ano)
// ---------------------------------------------------------
$mes_filtro = (int) ($_GET['mes'] ?? date('n'));
$ano_filtro = (int) ($_GET['ano'] ?? date('Y'));

// ---------------------------------------------------------
// 1. APURAÇÃO DOS KPIS GERAIS
// ---------------------------------------------------------
$stmt_kpi = $pdo->prepare("
    SELECT 
        COUNT(id) AS total_ops,
        SUM(CASE WHEN status = 'finalizada' THEN 1 ELSE 0 END) AS ops_finalizadas,
        COALESCE(SUM(quantidade_planejada), 0) AS total_planejado,
        COALESCE(SUM(quantidade_produzida), 0) AS total_produzido,
        COALESCE(SUM(quantidade_refugo), 0) AS total_refugo,
        -- Cálculo de OTIF: Concluída no prazo E quantidade_produzida >= quantidade_planejada
        SUM(
            CASE 
                WHEN status = 'finalizada' 
                 AND DATE(data_finalizacao) <= data_programada 
                 AND quantidade_produzida >= quantidade_planejada 
                THEN 1 
                ELSE 0 
            END
        ) AS ops_otif
    FROM ordens_producao
    WHERE MONTH(data_programada) = ? AND YEAR(data_programada) = ?
");
$stmt_kpi->execute([$mes_filtro, $ano_filtro]);
$kpis = $stmt_kpi->fetch();

$total_ops = (int) $kpis['total_ops'];
$ops_finalizadas = (int) $kpis['ops_finalizadas'];
$total_planejado = (int) $kpis['total_planejado'];
$total_produzido = (int) $kpis['total_produzido'];
$total_refugo = (int) $kpis['total_refugo'];
$ops_otif = (int) $kpis['ops_otif'];
$total_processado = $total_produzido + $total_refugo;

// Indicador 1: Aderência ao Plano (%)
$aderencia_plano = $total_planejado > 0 ? round(($total_produzido / $total_planejado) * 100, 1) : 0;

// Indicador 2: Taxa de Refugo (%)
$taxa_refugo = $total_processado > 0 ? round(($total_refugo / $total_processado) * 100, 2) : 0;

// Indicador 3: OTIF (%)
$otif_perc = $ops_finalizadas > 0 ? round(($ops_otif / $ops_finalizadas) * 100, 1) : 0;

// Indicador 4: Taxa de Utilização da Capacidade Instalada (%)
$ocupacao_capacidade = round(($total_produzido / CAPACIDADE_MENSAL_BARRAS) * 100, 1);

// Custo estimado de refugo (perdas financeiras de MP) baseado em R$ 3,20/barra média
$custo_estimado_refugo = $total_refugo * 3.20;

// ---------------------------------------------------------
// 2. DESEMPENHO POR FRAGRÂNCIA (Linha de Produtos)
// ---------------------------------------------------------
$stmt_prod = $pdo->prepare("
    SELECT p.codigo, p.nome, p.aroma,
           COALESCE(SUM(op.quantidade_planejada), 0) AS plan,
           COALESCE(SUM(op.quantidade_produzida), 0) AS prod,
           COALESCE(SUM(op.quantidade_refugo), 0) AS refugo
    FROM produtos p
    LEFT JOIN ordens_producao op ON p.id = op.produto_id 
         AND MONTH(op.data_programada) = ? 
         AND YEAR(op.data_programada) = ?
    GROUP BY p.id
    ORDER BY prod DESC
");
$stmt_prod->execute([$mes_filtro, $ano_filtro]);
$desempenho_produtos = $stmt_prod->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="container-fluid px-4 pb-4">
    <!-- Topo -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h2 class="fw-bold mb-1 text-dark">
                <i class="bi bi-graph-up-arrow text-success me-2"></i>Indicadores & Relatórios de PCP
            </h2>
            <p class="text-muted mb-0">Avaliação de eficiência operacional, qualidade e cumprimento de metas da fábrica.
            </p>
        </div>
        <!-- Filtro de Mês/Ano -->
        <form method="GET" action="relatorios.php" class="d-flex align-items-center gap-2">
            <select name="mes" class="form-select form-select-sm">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m === $mes_filtro ? 'selected' : '' ?>>
                        <?= strftime('%B', mktime(0, 0, 0, $m, 1)) ?> (Mês
                        <?= str_pad((string) $m, 2, '0', STR_PAD_LEFT) ?>)
                    </option>
                <?php endfor; ?>
            </select>
            <input type="number" name="ano" class="form-control form-control-sm" value="<?= $ano_filtro ?>"
                style="width: 90px;">
            <button type="submit" class="btn btn-sm btn-dark"><i class="bi bi-funnel"></i></button>
        </form>
    </div>

    <!-- 4 Cartões Principais de Indicadores -->
    <div class="row g-3 mb-4">
        <!-- KPI 1: OTIF -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-custom p-3 bg-white h-100">
                <span class="text-muted small text-uppercase fw-semibold">OTIF (No Prazo & Completo)</span>
                <h3 class="fw-bold my-1 <?= $otif_perc >= 90 ? 'text-success' : 'text-warning' ?>">
                    <?= $otif_perc ?>%
                </h3>
                <small class="text-muted"><?= $ops_otif ?> de <?= $ops_finalizadas ?> OPs finalizadas sem
                    atraso/corte</small>
                <div class="progress mt-2" style="height: 5px;">
                    <div class="progress-bar <?= $otif_perc >= 90 ? 'bg-success' : 'bg-warning' ?>"
                        style="width: <?= $otif_perc ?>%"></div>
                </div>
            </div>
        </div>

        <!-- KPI 2: Aderência ao Plano -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-custom p-3 bg-white h-100">
                <span class="text-muted small text-uppercase fw-semibold">Aderência ao Plano</span>
                <h3 class="fw-bold my-1 text-primary"><?= $aderencia_plano ?>%</h3>
                <small class="text-muted"><?= $total_produzido ?> un produzidas de <?= $total_planejado ?>
                    planejadas</small>
                <div class="progress mt-2" style="height: 5px;">
                    <div class="progress-bar bg-primary" style="width: <?= min(100, $aderencia_plano) ?>%"></div>
                </div>
            </div>
        </div>

        <!-- KPI 3: Taxa de Refugo -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-custom p-3 bg-white h-100">
                <span class="text-muted small text-uppercase fw-semibold">Taxa de Refugo (Scrap)</span>
                <h3 class="fw-bold my-1 <?= $taxa_refugo <= 3 ? 'text-success' : 'text-danger' ?>">
                    <?= $taxa_refugo ?>%
                </h3>
                <small class="text-muted"><?= $total_refugo ?> barras perdidas (~R$
                    <?= number_format($custo_estimado_refugo, 2, ',', '.') ?>)</small>
                <div class="progress mt-2" style="height: 5px;">
                    <div class="progress-bar <?= $taxa_refugo <= 3 ? 'bg-success' : 'bg-danger' ?>"
                        style="width: <?= min(100, $taxa_refugo * 10) ?>%"></div>
                </div>
            </div>
        </div>

        <!-- KPI 4: Utilização da Capacidade -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-custom p-3 bg-white h-100">
                <span class="text-muted small text-uppercase fw-semibold">Uso da Capacidade</span>
                <h3 class="fw-bold my-1 text-dark"><?= $ocupacao_capacidade ?>%</h3>
                <small class="text-muted">Meta mensal de <?= number_format(CAPACIDADE_MENSAL_BARRAS, 0, ',', '.') ?>
                    barras</small>
                <div class="progress mt-2" style="height: 5px;">
                    <div class="progress-bar bg-dark" style="width: <?= min(100, $ocupacao_capacidade) ?>%"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabela Detalhada por Fragrância -->
    <div class="card card-custom bg-white p-4 mb-4">
        <h5 class="fw-bold mb-3 text-dark">
            <i class="bi bi-bar-chart-steps me-2 text-secondary"></i>Balanço de Produção por Fragrância no Mês
        </h5>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light small text-uppercase">
                    <tr>
                        <th>Cód.</th>
                        <th>Fragrância</th>
                        <th>Planejado</th>
                        <th>Produzido (Aprovado)</th>
                        <th>Refugo</th>
                        <th>Aderência</th>
                        <th>Rendimento (Yield)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($desempenho_produtos as $prod):
                        $plan = (int) $prod['plan'];
                        $prod_qtd = (int) $prod['prod'];
                        $ref = (int) $prod['refugo'];
                        $proc = $prod_qtd + $ref;
                        $ader = $plan > 0 ? round(($prod_qtd / $plan) * 100, 1) : 0;
                        $yield = $proc > 0 ? round(($prod_qtd / $proc) * 100, 1) : 100;
                        ?>
                        <tr>
                            <td><span
                                    class="badge bg-secondary font-monospace"><?= htmlspecialchars($prod['codigo']) ?></span>
                            </td>
                            <td><strong><?= htmlspecialchars($prod['nome']) ?></strong></td>
                            <td><?= number_format($plan, 0, ',', '.') ?> un</td>
                            <td class="fw-bold text-success"><?= number_format($prod_qtd, 0, ',', '.') ?> un</td>
                            <td class="<?= $ref > 0 ? 'text-danger fw-bold' : 'text-muted' ?>">
                                <?= number_format($ref, 0, ',', '.') ?> un
                            </td>
                            <td>
                                <span
                                    class="badge bg-<?= $ader >= 100 ? 'success' : ($ader > 0 ? 'warning text-dark' : 'secondary') ?>">
                                    <?= $ader ?>%
                                </span>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height: 6px;">
                                        <div class="progress-bar bg-success" style="width: <?= $yield ?>%"></div>
                                    </div>
                                    <small class="fw-semibold text-muted"><?= $yield ?>%</small>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Bloco de Diagnóstico e Recomendações para a Aula -->
    <div class="card card-custom bg-white p-4">
        <h5 class="fw-bold mb-3 text-dark">
            <i class="bi bi-chat-left-quote me-2 text-primary"></i>Diagnóstico Pedagógico do PCP
        </h5>
        <div class="row g-3">
            <div class="col-12 col-md-4">
                <div class="p-3 bg-light rounded-3 h-100">
                    <strong class="d-block text-dark mb-1">Qualidade & Refugo</strong>
                    <p class="small text-muted mb-0">
                        <?php if ($taxa_refugo > 3): ?>
                            A taxa de refugo ultrapassou a tolerância máxima (3%). Verifique a dosagem de lauril e a
                            temperatura de cura das fôrmas nas OPs do sabonete de Rosas e Maracujá.
                        <?php else: ?>
                            O nível de perdas do processo está controlado, mantendo a margem de contribuição dentro do
                            orçamento previsto.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="p-3 bg-light rounded-3 h-100">
                    <strong class="d-block text-dark mb-1">Nível de Serviço (OTIF)</strong>
                    <p class="small text-muted mb-0">
                        <?php if ($otif_perc < 85): ?>
                            O índice OTIF está sob pressão. Houve corte ou atraso em OPs devido a rupturas no estoque de
                            essências concentradas.
                        <?php else: ?>
                            Excelente pontualidade nas entregas sem cortes de lote no faturamento.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="p-3 bg-light rounded-3 h-100">
                    <strong class="d-block text-dark mb-1">Planejamento de Capacidade</strong>
                    <p class="small text-muted mb-0">
                        A fábrica atingiu <?= $ocupacao_capacidade ?>% da sua capacidade instalada.
                        <?= $ocupacao_capacidade < 70 ? 'Há folga de turnos disponível para absorver novos pedidos sazonais.' : 'A linha está próxima do gargalo de cura e embalagem manual.' ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';