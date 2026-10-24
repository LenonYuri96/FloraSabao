<?php
declare(strict_types=1);

// 🔐 GUARDA DE AUTENTICAÇÃO — deve vir PRIMEIRO
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';

$page_title = 'Indicadores de Desempenho do PCP';

// ---------------------------------------------------------
// 1. IDENTIFICAÇÃO MULTI-TENANT (Equipe Ativa e Rodada)
// ---------------------------------------------------------
$equipe_id = getEquipeAtivaId();
$rodada_ativa = getRodadaAtiva($pdo);

// Busca dados da equipe ativa
$stmt_eq = $pdo->prepare("SELECT id, nome, cor_hex, caixa_disponivel, score_total FROM equipes WHERE id = ?");
$stmt_eq->execute([$equipe_id]);
$equipe_dados = $stmt_eq->fetch() ?: [
    'id' => $equipe_id,
    'nome' => 'Equipe ' . $equipe_id,
    'cor_hex' => '#2d5a3f',
    'caixa_disponivel' => 15000.00,
    'score_total' => 0
];

// ---------------------------------------------------------
// 2. FILTRO DE PERÍODO (Mês / Ano)
// ---------------------------------------------------------
$mes_filtro = (int) ($_GET['mes'] ?? date('n'));
$ano_filtro = (int) ($_GET['ano'] ?? date('Y'));

$meses_nomes = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março',     4 => 'Abril',
    5 => 'Maio',    6 => 'Junho',      7 => 'Julho',     8 => 'Agosto',
    9 => 'Setembro',10 => 'Outubro',   11 => 'Novembro', 12 => 'Dezembro'
];

// ---------------------------------------------------------
// 3. APURAÇÃO DOS KPIS DA EQUIPE
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
    WHERE equipe_id = ? 
      AND MONTH(data_programada) = ? 
      AND YEAR(data_programada) = ?
");
$stmt_kpi->execute([$equipe_id, $mes_filtro, $ano_filtro]);
$kpis = $stmt_kpi->fetch();

$total_ops        = (int) $kpis['total_ops'];
$ops_finalizadas  = (int) $kpis['ops_finalizadas'];
$total_planejado  = (int) $kpis['total_planejado'];
$total_produzido  = (int) $kpis['total_produzido'];
$total_refugo     = (int) $kpis['total_refugo'];
$ops_otif         = (int) $kpis['ops_otif'];
$total_processado = $total_produzido + $total_refugo;

// Indicador 1: Aderência ao Plano (%)
$aderencia_plano = $total_planejado > 0 ? round(($total_produzido / $total_planejado) * 100, 1) : 0.0;

// Indicador 2: Taxa de Refugo (%)
$taxa_refugo = $total_processado > 0 ? round(($total_refugo / $total_processado) * 100, 2) : 0.0;

// Indicador 3: Rendimento Geral de Qualidade (Yield %)
$yield_qualidade = $total_processado > 0 ? round(($total_produzido / $total_processado) * 100, 1) : 100.0;

// Indicador 4: OTIF (%)
$otif_perc = $ops_finalizadas > 0 ? round(($ops_otif / $ops_finalizadas) * 100, 1) : 0.0;

// Indicador 5: Taxa de Utilização da Capacidade Instalada (%)
$ocupacao_capacidade = round(($total_produzido / CAPACIDADE_MENSAL_BARRAS) * 100, 1);

// Custo estimado de refugo (perdas financeiras de MP em média de R$ 3,20/barra)
$custo_estimado_refugo = $total_refugo * 3.20;

// ---------------------------------------------------------
// 4. DESEMPENHO POR FRAGRÂNCIA DA EQUIPE
// ---------------------------------------------------------
$stmt_prod = $pdo->prepare("
    SELECT p.codigo, p.nome, p.aroma, p.preco_venda,
           COALESCE(SUM(op.quantidade_planejada), 0) AS plan,
           COALESCE(SUM(op.quantidade_produzida), 0) AS prod,
           COALESCE(SUM(op.quantidade_refugo), 0) AS refugo
    FROM produtos p
    LEFT JOIN ordens_producao op ON p.id = op.produto_id 
         AND op.equipe_id = ?
         AND MONTH(op.data_programada) = ? 
         AND YEAR(op.data_programada) = ?
    WHERE p.equipe_id = ?
    GROUP BY p.id
    ORDER BY prod DESC
");
$stmt_prod->execute([$equipe_id, $mes_filtro, $ano_filtro, $equipe_id]);
$desempenho_produtos = $stmt_prod->fetchAll();

// Faturamento bruto projetado das barras boas produzidas
$faturamento_produzido = 0.0;
foreach ($desempenho_produtos as $dp) {
    $faturamento_produzido += ((int)$dp['prod'] * (float)$dp['preco_venda']);
}

// ---------------------------------------------------------
// 5. HISTÓRICO DAS RODADAS DO JOGO (Para esta Equipe)
// ---------------------------------------------------------
$stmt_rk = $pdo->prepare("
    SELECT rk.*, jr.numero_rodada, jr.mes_ano, jr.evento_titulo
    FROM ranking_rodadas rk
    JOIN jogo_rodadas jr ON rk.rodada_id = jr.id
    WHERE rk.equipe_id = ?
    ORDER BY jr.numero_rodada DESC
");
$stmt_rk->execute([$equipe_id]);
$historico_rodadas = $stmt_rk->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="container-fluid px-4 pb-4">
    <!-- Cabeçalho Superior -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge text-white px-3 py-1 font-monospace" style="background-color: <?= htmlspecialchars($equipe_dados['cor_hex']) ?>;">
                    <i class="bi bi-shield-shaded me-1"></i><?= htmlspecialchars($equipe_dados['nome']) ?>
                </span>
                <span class="badge bg-secondary-subtle text-secondary border px-2 py-1">
                    <?= htmlspecialchars($rodada_ativa['mes_ano']) ?> (Rodada <?= (int)$rodada_ativa['numero_rodada'] ?>)
                </span>
            </div>
            <h2 class="fw-bold mb-1 text-dark">
                <i class="bi bi-graph-up-arrow text-success me-2"></i>Indicadores & Relatórios de PCP
            </h2>
            <p class="text-muted mb-0">Avaliação de eficiência operacional, qualidade, cumprimento de metas e desempenho na simulação.</p>
        </div>

        <!-- Formulário de Filtro de Mês/Ano -->
        <form method="GET" action="relatorios.php" class="d-flex flex-wrap align-items-center gap-2 bg-white p-2 rounded-3 border shadow-sm">
            <?php if (isset($_GET['equipe_id'])): ?>
                <input type="hidden" name="equipe_id" value="<?= (int)$_GET['equipe_id'] ?>">
            <?php endif; ?>
            <label class="small fw-semibold text-muted mb-0"><i class="bi bi-calendar-event me-1"></i>Período:</label>
            <select name="mes" class="form-select form-select-sm" style="width: 140px;">
                <?php foreach ($meses_nomes as $num => $nome): ?>
                    <option value="<?= $num ?>" <?= $num === $mes_filtro ? 'selected' : '' ?>>
                        <?= $nome ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="number" name="ano" class="form-control form-control-sm" value="<?= $ano_filtro ?>" min="2020" max="2035" style="width: 85px;" required>
            <button type="submit" class="btn btn-sm btn-dark" title="Filtrar Período">
                <i class="bi bi-funnel-fill"></i>
            </button>
        </form>
    </div>

    <!-- 4 Cards Principais de Indicadores (KPIs Primários) -->
    <div class="row g-3 mb-4">
        <!-- KPI 1: OTIF -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-custom p-3 bg-white h-100 shadow-sm">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">OTIF (No Prazo & Completo)</span>
                        <h3 class="fw-bold my-1 <?= $otif_perc >= 90 ? 'text-success' : ($otif_perc >= 75 ? 'text-warning' : 'text-danger') ?>">
                            <?= $otif_perc ?>%
                        </h3>
                        <small class="text-muted"><?= $ops_otif ?> de <?= $ops_finalizadas ?> OPs finalizadas sem corte/atraso</small>
                    </div>
                    <div class="p-3 rounded-3 <?= $otif_perc >= 90 ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' ?>">
                        <i class="bi bi-check2-all fs-4"></i>
                    </div>
                </div>
                <div class="progress mt-2" style="height: 6px;">
                    <div class="progress-bar <?= $otif_perc >= 90 ? 'bg-success' : ($otif_perc >= 75 ? 'bg-warning' : 'bg-danger') ?>" style="width: <?= min(100, $otif_perc) ?>%"></div>
                </div>
            </div>
        </div>

        <!-- KPI 2: Aderência ao Plano -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-custom p-3 bg-white h-100 shadow-sm">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Aderência ao Plano</span>
                        <h3 class="fw-bold my-1 text-primary"><?= $aderencia_plano ?>%</h3>
                        <small class="text-muted"><?= number_format($total_produzido, 0, ',', '.') ?> de <?= number_format($total_planejado, 0, ',', '.') ?> un planejadas</small>
                    </div>
                    <div class="bg-primary-subtle text-primary p-3 rounded-3">
                        <i class="bi bi-bullseye fs-4"></i>
                    </div>
                </div>
                <div class="progress mt-2" style="height: 6px;">
                    <div class="progress-bar bg-primary" style="width: <?= min(100, $aderencia_plano) ?>%"></div>
                </div>
            </div>
        </div>

        <!-- KPI 3: Taxa de Refugo (Scrap) -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-custom p-3 bg-white h-100 shadow-sm">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Taxa de Refugo (Scrap)</span>
                        <h3 class="fw-bold my-1 <?= $taxa_refugo <= 3.0 ? 'text-success' : 'text-danger' ?>">
                            <?= $taxa_refugo ?>%
                        </h3>
                        <small class="text-muted"><?= number_format($total_refugo, 0, ',', '.') ?> barras perdidas (~R$ <?= number_format($custo_estimado_refugo, 2, ',', '.') ?>)</small>
                    </div>
                    <div class="<?= $taxa_refugo <= 3.0 ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' ?> p-3 rounded-3">
                        <i class="bi bi-trash fs-4"></i>
                    </div>
                </div>
                <div class="progress mt-2" style="height: 6px;">
                    <div class="progress-bar <?= $taxa_refugo <= 3.0 ? 'bg-success' : 'bg-danger' ?>" style="width: <?= min(100, $taxa_refugo * 10) ?>%"></div>
                </div>
            </div>
        </div>

        <!-- KPI 4: Utilização da Capacidade -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-custom p-3 bg-white h-100 shadow-sm">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Uso da Capacidade</span>
                        <h3 class="fw-bold my-1 text-dark"><?= $ocupacao_capacidade ?>%</h3>
                        <small class="text-muted">Teto mensal de <?= number_format(CAPACIDADE_MENSAL_BARRAS, 0, ',', '.') ?> barras</small>
                    </div>
                    <div class="bg-dark-subtle text-dark p-3 rounded-3">
                        <i class="bi bi-speedometer fs-4"></i>
                    </div>
                </div>
                <div class="progress mt-2" style="height: 6px;">
                    <div class="progress-bar bg-dark" style="width: <?= min(100, $ocupacao_capacidade) ?>%"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Linha Secundária de Indicadores Operacionais & Econômicos -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-4">
            <div class="card card-custom p-3 bg-white h-100 border-start border-4 border-success shadow-sm">
                <span class="text-muted small text-uppercase fw-semibold">Faturamento Bruto em Produção</span>
                <h4 class="fw-bold text-success my-1">R$ <?= number_format($faturamento_produzido, 2, ',', '.') ?></h4>
                <small class="text-muted">Valor potencial das barras aprovadas no período</small>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card card-custom p-3 bg-white h-100 border-start border-4 border-primary shadow-sm">
                <span class="text-muted small text-uppercase fw-semibold">Yield de Qualidade (Rendimento)</span>
                <h4 class="fw-bold text-primary my-1"><?= $yield_qualidade ?>%</h4>
                <small class="text-muted">Proporção de sabonetes sem defeitos de moldagem</small>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card card-custom p-3 bg-white h-100 border-start border-4 border-warning shadow-sm">
                <span class="text-muted small text-uppercase fw-semibold">Gargalo Crítico de Linha</span>
                <h4 class="fw-bold text-warning-emphasis my-1">Posto 3 (Moldagem)</h4>
                <small class="text-muted">Tempo de ciclo padrão: 70 min por lote de 100 barras</small>
            </div>
        </div>
    </div>

    <!-- Tabela Detalhada: Balanço de Produção por Fragrância -->
    <div class="card card-custom bg-white p-4 mb-4 shadow-sm">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-bar-chart-steps me-2 text-secondary"></i>Balanço de Produção por Fragrância no Período
                </h5>
                <small class="text-muted">Apuração detalhada de aderência e qualidade por SKU</small>
            </div>
            <span class="badge bg-light text-dark border px-3 py-2">
                <?= $meses_nomes[$mes_filtro] ?? 'Mês' ?> / <?= $ano_filtro ?>
            </span>
        </div>

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
                    <?php if (empty($desempenho_produtos)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">
                                Nenhum registro de produção encontrado para este período.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($desempenho_produtos as $prod):
                            $plan     = (int) $prod['plan'];
                            $prod_qtd = (int) $prod['prod'];
                            $ref      = (int) $prod['refugo'];
                            $proc     = $prod_qtd + $ref;
                            $ader     = $plan > 0 ? round(($prod_qtd / $plan) * 100, 1) : 0.0;
                            $yield    = $proc > 0 ? round(($prod_qtd / $proc) * 100, 1) : 100.0;
                            ?>
                            <tr>
                                <td>
                                    <span class="badge bg-secondary font-monospace"><?= htmlspecialchars($prod['codigo']) ?></span>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($prod['nome']) ?></strong>
                                    <small class="text-muted d-block"><?= htmlspecialchars($prod['aroma']) ?></small>
                                </td>
                                <td><?= number_format($plan, 0, ',', '.') ?> un</td>
                                <td class="fw-bold text-success"><?= number_format($prod_qtd, 0, ',', '.') ?> un</td>
                                <td class="<?= $ref > 0 ? 'text-danger fw-bold' : 'text-muted' ?>">
                                    <?= number_format($ref, 0, ',', '.') ?> un
                                </td>
                                <td>
                                    <span class="badge bg-<?= $ader >= 100 ? 'success' : ($ader > 0 ? 'warning text-dark' : 'secondary') ?>">
                                        <?= $ader ?>%
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="progress flex-grow-1" style="height: 6px;">
                                            <div class="progress-bar bg-success" style="width: <?= min(100, $yield) ?>%"></div>
                                        </div>
                                        <small class="fw-semibold text-muted"><?= $yield ?>%</small>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Tabela Histórica: Pontuações da Equipe nas Rodadas da Competição -->
    <?php if (!empty($historico_rodadas)): ?>
    <div class="card card-custom bg-white p-4 mb-4 shadow-sm border-start border-4 border-warning">
        <h5 class="fw-bold mb-3 text-dark">
            <i class="bi bi-trophy me-2 text-warning"></i>Histórico da Equipe no Business Game
        </h5>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light small text-uppercase">
                    <tr>
                        <th>Rodada</th>
                        <th>Período</th>
                        <th>Cenário de Mercado Enfrentado</th>
                        <th>Score Conquistado</th>
                        <th>OTIF</th>
                        <th>Refugo</th>
                        <th>Penalidades de Setup</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historico_rodadas as $hr): ?>
                    <tr>
                        <td class="fw-bold">Rodada <?= (int)$hr['numero_rodada'] ?></td>
                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($hr['mes_ano']) ?></span></td>
                        <td><?= htmlspecialchars($hr['evento_titulo'] ?? 'Mercado Estável') ?></td>
                        <td><strong class="text-success fs-6"><?= (int)$hr['score_rodada'] ?> pts</strong></td>
                        <td><?= number_format((float)$hr['otif_perc'], 1, ',', '.') ?>%</td>
                        <td class="<?= (float)$hr['taxa_refugo'] > 3.0 ? 'text-danger fw-bold' : '' ?>">
                            <?= number_format((float)$hr['taxa_refugo'], 2, ',', '.') ?>%
                        </td>
                        <td>
                            <?php if ((int)$hr['penalidades'] > 0): ?>
                                <span class="badge bg-danger">-<?= (int)$hr['penalidades'] ?> pts</span>
                            <?php else: ?>
                                <span class="badge bg-success">Zero falhas</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Bloco Didático: Diagnóstico Pedagógico do PCP & Boas Práticas Lean -->
    <div class="card card-custom bg-white p-4 shadow-sm">
        <h5 class="fw-bold mb-3 text-dark">
            <i class="bi bi-chat-left-quote me-2 text-primary"></i>Diagnóstico Pedagógico do PCP & Análise Operacional
        </h5>
        <div class="row g-3">
            <div class="col-12 col-md-4">
                <div class="p-3 bg-light rounded-3 h-100 border">
                    <strong class="d-block text-dark mb-1"><i class="bi bi-shield-check text-success me-1"></i>Qualidade & Refugo</strong>
                    <p class="small text-muted mb-0">
                        <?php if ($taxa_refugo > 3.0): ?>
                            <span class="text-danger fw-semibold">Alerta de Perdas:</span> A taxa de refugo (<?= $taxa_refugo ?>%) superou a meta de 3,0%. Avalie a dosagem de espumante e inspecione o alinhamento das fôrmas no Posto 3.
                        <?php else: ?>
                            <span class="text-success fw-semibold">Excelente:</span> As perdas de processo (<?= $taxa_refugo ?>%) estão dentro da tolerância orçada, preservando a margem de contribuição dos lotes.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="p-3 bg-light rounded-3 h-100 border">
                    <strong class="d-block text-dark mb-1"><i class="bi bi-truck text-primary me-1"></i>Nível de Serviço (OTIF)</strong>
                    <p class="small text-muted mb-0">
                        <?php if ($otif_perc < 85.0): ?>
                            <span class="text-danger fw-semibold">Atenção ao Prazo:</span> O índice OTIF de <?= $otif_perc ?>% indica cortes de quantidade ou atrasos de entrega causados por gargalos ou desabastecimento de essência.
                        <?php else: ?>
                            <span class="text-success fw-semibold">Pontualidade Alta:</span> OTIF de <?= $otif_perc ?>% reflete cumprimento pontual dos lotes sem cortes no faturamento.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="p-3 bg-light rounded-3 h-100 border">
                    <strong class="d-block text-dark mb-1"><i class="bi bi-sliders text-warning me-1"></i>Capacidade Instalada</strong>
                    <p class="small text-muted mb-0">
                        A linha de saboaria operou a <?= $ocupacao_capacidade ?>% da capacidade de <?= number_format(CAPACIDADE_MENSAL_BARRAS, 0, ',', '.') ?> barras/mês.
                        <?= $ocupacao_capacidade < 75.0 ? 'Há horas livres no tacho para absorver ordens adicionais de produção.' : 'A fábrica opera próxima do gargalo de cura e desmoldagem manual.' ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';