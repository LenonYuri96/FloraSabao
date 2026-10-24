<?php
declare(strict_types=1);

/**
 * Flora Sabão — Painel de Controle da Produção (Dashboard Principal)
 * ==============================================================================
 * Sistema Integrado de PCP & Business Game Multi-Equipes
 *
 * Módulos integrados nesta tela:
 *  1. Identificação da Equipe Ativa e Rodada do Jogo
 *  2. Quadro Dinâmico de Metas S&OP (Demandas Aleatórias Calibradas)
 *  3. Alerta de Sazonalidade & Briefing do Caso de Estudo
 *  4. Barras de Progresso de Entrega por Fragrância (MAR, PIT, LAV, ROS)
 *  5. Checklist de Objetivos Operacionais da Rodada
 *  6. KPIs de Chão de Fábrica (Capacidade, Refugo, OPs e Almoxarifado)
 *  7. Diagnóstico Rápido da Estação de Fusão (Tacho Limpo vs. Impregnado)
 *  8. Tabela de Ordens Recentes e Posição dos 4 Sabonetes em Linha
 */

// 🔐 GUARDA DE AUTENTICAÇÃO — deve vir PRIMEIRO
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/includes/functions.php';

$page_title = 'Painel do PCP & S&OP';

// =========================================================
// 1. IDENTIFICAÇÃO MULTI-TENANT E CONTEXTO DO JOGO
// =========================================================
$equipe_id = function_exists('getEquipeAtivaId') ? getEquipeAtivaId() : 1;
if ($equipe_id <= 0) {
    $equipe_id = 1; // Fallback de segurança para não travar a visualização
}

// Dados cadastrais e estado da equipe com proteção de integridade
$equipe_bruta = function_exists('equipeAtual') ? equipeAtual($pdo) : null;
$equipe_dados = array_merge([
    'id'                 => $equipe_id,
    'nome'               => 'Equipe ' . $equipe_id,
    'codigo_acesso'      => 'EQ' . $equipe_id,
    'cor_hex'            => '#2d5a3f',
    'caixa_disponivel'   => 15000.00,
    'score_total'        => 0,
    'tacho_ultimo_aroma' => 'LIMPO',
    'tacho_higienizado'  => 1
], is_array($equipe_bruta) ? $equipe_bruta : []);

// Rodada ativa da simulação
$rodada_bruta = function_exists('getRodadaAtiva') ? getRodadaAtiva($pdo) : null;
$rodada_ativa = array_merge([
    'id'            => 1,
    'numero_rodada' => 1,
    'mes_ano'       => 'Mês 01',
    'status'        => 'ativa'
], is_array($rodada_bruta) ? $rodada_bruta : []);

// =========================================================
// 2. DEMANDAS DINÂMICAS DA RODADA (S&OP ALEATÓRIO CALIBRADO)
// =========================================================
$plano_demanda_raw = function_exists('getDemandasRodada') ? getDemandasRodada($pdo, $equipe_id) : [];
$plano_demanda = array_merge([
    'total_meta'         => 0,
    'cenario_titulo'     => 'Plano Operacional S&OP Padrão',
    'cenario_alerta'     => 'Monitore os limites do gargalo (Posto 3) e siga o sequenciamento suave -> intenso para evitar contaminação.',
    'horas_gargalo_meta' => 0.0,
    'percentual_geral'   => 0.0,
    'itens'              => []
], is_array($plano_demanda_raw) ? $plano_demanda_raw : []);

// =========================================================
// 3. CONSULTAS DE MÉTRICAS E INDICADORES DA EQUIPE
// =========================================================

// 3.1 Total de OPs ativas da equipe (planejadas ou em produção)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM ordens_producao WHERE equipe_id = ? AND status IN ('planejada', 'em_producao')");
$stmt->execute([$equipe_id]);
$total_ops_ativas = (int) $stmt->fetchColumn();

// 3.2 Volume produzido e refugos no mês/rodada atual (com fallback para simulação contínua)
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(quantidade_produzida), 0) AS total_produzido,
           COALESCE(SUM(quantidade_refugo), 0) AS total_refugo
    FROM ordens_producao 
    WHERE equipe_id = ?
      AND MONTH(data_programada) = MONTH(CURRENT_DATE()) 
      AND YEAR(data_programada) = YEAR(CURRENT_DATE())
");
$stmt->execute([$equipe_id]);
$metr_prod = $stmt->fetch();
$total_produzido = (int) ($metr_prod['total_produzido'] ?? 0);
$total_refugo    = (int) ($metr_prod['total_refugo'] ?? 0);

// Fallback: se não houver registros no mês corrente do calendário do servidor, computa o total geral da equipe
if ($total_produzido === 0 && $total_refugo === 0) {
    $stmt_fallback = $pdo->prepare("
        SELECT COALESCE(SUM(quantidade_produzida), 0) AS total_produzido,
               COALESCE(SUM(quantidade_refugo), 0) AS total_refugo
        FROM ordens_producao 
        WHERE equipe_id = ?
    ");
    $stmt_fallback->execute([$equipe_id]);
    $metr_fb = $stmt_fallback->fetch();
    if ($metr_fb && ((int)$metr_fb['total_produzido'] > 0 || (int)$metr_fb['total_refugo'] > 0)) {
        $total_produzido = (int) $metr_fb['total_produzido'];
        $total_refugo    = (int) $metr_fb['total_refugo'];
    }
}

$capacidade_teto  = defined('CAPACIDADE_MENSAL_BARRAS') && CAPACIDADE_MENSAL_BARRAS > 0 ? (int)CAPACIDADE_MENSAL_BARRAS : 2500;
$total_processado = $total_produzido + $total_refugo;
$taxa_refugo      = $total_processado > 0 ? round(($total_refugo / $total_processado) * 100, 2) : 0.0;
$perc_capacidade  = min(100.0, round(($total_produzido / $capacidade_teto) * 100, 1));

// 3.3 Insumos em estado crítico (estoque atual <= estoque mínimo)
$stmt = $pdo->prepare("
    SELECT id, codigo, nome, unidade, estoque_atual, estoque_minimo, custo_unitario,
           (estoque_atual * custo_unitario) AS valor_imobilizado
    FROM insumos
    WHERE equipe_id = ? AND estoque_atual <= estoque_minimo
    ORDER BY (estoque_atual / NULLIF(estoque_minimo, 0)) ASC
");
$stmt->execute([$equipe_id]);
$insumos_criticos = $stmt->fetchAll() ?: [];

// 3.4 Saldo dos 4 Produtos Acabados da equipe
$stmt = $pdo->prepare("
    SELECT id, codigo, nome, aroma, intensidade_aroma, estoque_atual, estoque_minimo, preco_venda,
           (estoque_atual * preco_venda) AS valor_potencial
    FROM produtos 
    WHERE equipe_id = ? 
    ORDER BY FIELD(codigo, 'MAR', 'PIT', 'LAV', 'ROS'), id ASC
");
$stmt->execute([$equipe_id]);
$produtos = $stmt->fetchAll() ?: [];
$total_barras_estoque   = array_sum(array_column($produtos, 'estoque_atual'));
$valor_total_estoque_pa = array_sum(array_column($produtos, 'valor_potencial'));

// 3.5 Faturamento bruto das barras boas aprovadas
$faturamento_realizado = 0.0;
foreach ($produtos as $p) {
    $cod = (string)($p['codigo'] ?? '');
    $entregue_sku = (int) ($plano_demanda['itens'][$cod]['entregue'] 
                           ?? $plano_demanda['itens'][strtoupper($cod)]['entregue'] 
                           ?? 0);
    $faturamento_realizado += ($entregue_sku * (float)($p['preco_venda'] ?? 0));
}

// 3.6 Lista das últimas 8 Ordens de Produção da equipe
$stmt = $pdo->prepare("
    SELECT op.id, op.numero, op.quantidade_planejada, op.quantidade_produzida, op.quantidade_refugo,
           op.data_programada, op.status, op.observacoes,
           p.nome AS produto_nome, p.codigo AS produto_codigo
    FROM ordens_producao op
    JOIN produtos p ON op.produto_id = p.id
    WHERE op.equipe_id = ?
    ORDER BY op.data_programada DESC, op.id DESC
    LIMIT 8
");
$stmt->execute([$equipe_id]);
$ordens = $stmt->fetchAll() ?: [];

// 3.7 Estado do Tacho Principal (Controle de BPF e Sanitização)
$tacho_ultimo_aroma = strtoupper(trim((string)($equipe_dados['tacho_ultimo_aroma'] ?? 'LIMPO')));
$tacho_higienizado  = ((int)($equipe_dados['tacho_higienizado'] ?? 1) === 1) || ($tacho_ultimo_aroma === 'LIMPO');

// Dicionário de status com fallback seguro
$status_map = defined('STATUS_OP') && is_array(STATUS_OP) ? STATUS_OP : [
    'planejada'   => ['label' => 'Planejada', 'badge' => 'secondary'],
    'em_producao' => ['label' => 'Em Produção', 'badge' => 'primary'],
    'concluida'   => ['label' => 'Concluída', 'badge' => 'success'],
    'cancelada'   => ['label' => 'Cancelada', 'badge' => 'danger'],
    'bloqueada'   => ['label' => 'Bloqueada', 'badge' => 'warning']
];

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="container-fluid px-4 pb-4">
    <!-- ========================================================= -->
    <!-- CABEÇALHO SUPERIOR DA PÁGINA                               -->
    <!-- ========================================================= -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge text-white px-3 py-1 font-monospace shadow-sm" style="background-color: <?= htmlspecialchars((string)$equipe_dados['cor_hex']) ?>;">
                    <i class="bi bi-shield-shaded me-1"></i><?= htmlspecialchars((string)$equipe_dados['nome']) ?>
                </span>
                <span class="badge bg-secondary-subtle text-secondary border px-2 py-1">
                    <?= htmlspecialchars((string)($rodada_ativa['mes_ano'] ?? 'Mês 01')) ?> (Rodada <?= (int)($rodada_ativa['numero_rodada'] ?? 1) ?>)
                </span>
                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                    <i class="bi bi-circle-fill me-1 small"></i>Linha de Produção Ativa
                </span>
            </div>
            <h2 class="fw-bold mb-1 text-dark">
                <i class="bi bi-speedometer2 text-success me-2"></i>Painel Integrado de PCP & Manufatura
            </h2>
            <p class="text-muted mb-0">Gestão integrada de carteira de pedidos (S&OP), capacidade do gargalo, setup e controle de qualidade.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="pages/ia_consultor.php" class="btn btn-outline-success shadow-sm fw-bold">
                <i class="bi bi-robot me-1 text-success"></i> Copiloto IA
            </a>
            <a href="pages/ranking.php" target="_blank" class="btn btn-outline-warning text-dark fw-bold shadow-sm">
                <i class="bi bi-trophy-fill me-1 text-warning"></i> Leaderboard
            </a>
            <a href="pages/ordens_producao.php" class="btn btn-success shadow-sm fw-bold">
                <i class="bi bi-plus-circle me-1"></i> Emitir Nova OP
            </a>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- 🎯 QUADRO DE METAS S&OP: CARTEIRA DE PEDIDOS DINÂMICA      -->
    <!-- ========================================================= -->
    <div class="card card-custom p-4 bg-white mb-4 shadow-sm border-start border-4 border-primary">
        <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-start gap-3 mb-3">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge bg-primary text-white text-uppercase px-2 py-1 font-monospace">
                        <i class="bi bi-bullseye me-1"></i>S&OP da Rodada
                    </span>
                    <span class="badge bg-light text-dark border">
                        Meta Total da Carteira: <strong><?= number_format((float)$plano_demanda['total_meta'], 0, ',', '.') ?> barras</strong>
                    </span>
                </div>
                <h4 class="fw-bold text-dark my-1">
                    <?= htmlspecialchars((string)$plano_demanda['cenario_titulo']) ?>
                </h4>
                <div class="alert alert-info py-2 px-3 small mb-0 mt-2 border-0 bg-info-subtle text-dark">
                    <strong><i class="bi bi-info-circle-fill me-1 text-primary"></i>Briefing de Mercado:</strong> 
                    <?= htmlspecialchars((string)$plano_demanda['cenario_alerta']) ?>
                </div>
            </div>

            <!-- Resumo Financeiro e Operacional da Bancada -->
            <div class="d-flex flex-wrap gap-2 bg-light p-3 rounded-3 border text-center align-self-start">
                <div class="px-2 border-end">
                    <span class="text-muted small text-uppercase d-block fw-semibold">Score PCP</span>
                    <h3 class="fw-bold text-success my-0"><?= (int)$equipe_dados['score_total'] ?> <small class="fs-6 text-muted">pts</small></h3>
                </div>
                <div class="px-2 border-end">
                    <span class="text-muted small text-uppercase d-block fw-semibold">Caixa Disponível</span>
                    <h4 class="fw-bold text-dark my-0">R$ <?= number_format((float)$equipe_dados['caixa_disponivel'], 2, ',', '.') ?></h4>
                </div>
                <div class="px-2 border-end">
                    <span class="text-muted small text-uppercase d-block fw-semibold">Horas no Gargalo</span>
                    <h4 class="fw-bold text-warning-emphasis my-0"><?= number_format((float)$plano_demanda['horas_gargalo_meta'], 1, ',', '.') ?>h <small class="fs-6 text-muted">Posto 3</small></h4>
                </div>
                <div class="px-2">
                    <span class="text-muted small text-uppercase d-block fw-semibold">Progresso da Demanda</span>
                    <h4 class="fw-bold text-primary my-0"><?= number_format((float)$plano_demanda['percentual_geral'], 1, ',', '.') ?>%</h4>
                </div>
            </div>
        </div>

        <!-- Barras de Progresso das Fragrâncias -->
        <h6 class="fw-bold text-muted small text-uppercase mb-2">Demandas Confirmadas de Clientes (Lotes Múltiplos de 50 barras):</h6>
        <div class="row g-3">
            <?php if (empty($plano_demanda['itens'])): ?>
                <div class="col-12">
                    <div class="alert alert-light border text-muted small mb-0">
                        <i class="bi bi-info-circle me-1"></i> Nenhuma demanda individual cadastrada para a rodada em vigor.
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($plano_demanda['itens'] as $cod => $item): 
                    $cor_badge     = !empty($item['cor']) ? htmlspecialchars((string)$item['cor']) : 'primary';
                    $aroma_nome    = !empty($item['aroma']) ? htmlspecialchars((string)$item['aroma']) : htmlspecialchars((string)$cod);
                    $item_meta     = (int)($item['meta'] ?? 0);
                    $item_entregue = (int)($item['entregue'] ?? 0);
                    $item_faltam   = isset($item['faltam']) ? (int)$item['faltam'] : max(0, $item_meta - $item_entregue);
                    $item_perc     = isset($item['percentual']) ? (float)$item['percentual'] : ($item_meta > 0 ? round(($item_entregue / $item_meta) * 100, 1) : 0.0);
                    $item_perc     = max(0.0, min(100.0, $item_perc));
                ?>
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="border rounded-3 p-3 bg-light h-100 shadow-sm position-relative">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="badge bg-<?= $cor_badge ?> font-monospace"><?= htmlspecialchars((string)$cod) ?> (<?= $aroma_nome ?>)</span>
                            <strong class="text-dark small"><?= number_format($item_perc, 1, ',', '.') ?>% entregue</strong>
                        </div>
                        <strong class="text-dark d-block text-truncate"><?= htmlspecialchars((string)($item['nome'] ?? $cod)) ?></strong>
                        <div class="d-flex justify-content-between text-muted small mt-2 mb-1">
                            <span>Meta: <strong><?= number_format($item_meta, 0, ',', '.') ?> un</strong></span>
                            <span>Pronto: <strong class="text-success"><?= number_format($item_entregue, 0, ',', '.') ?> un</strong></span>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-<?= $cor_badge ?>" role="progressbar" style="width: <?= $item_perc ?>%" aria-valuenow="<?= $item_perc ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                        <?php if ($item_faltam > 0): ?>
                            <small class="text-danger d-block mt-2 fw-semibold">
                                <i class="bi bi-clock-history me-1"></i>Faltam <?= number_format($item_faltam, 0, ',', '.') ?> un para fechar carteira
                            </small>
                        <?php else: ?>
                            <small class="text-success d-block mt-2 fw-bold">
                                <i class="bi bi-check-circle-fill me-1"></i>Demanda 100% faturada!
                            </small>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Checklist de Objetivos de Aprovação da Rodada -->
        <div class="border-top mt-3 pt-3">
            <h6 class="fw-bold text-dark small text-uppercase mb-2">Checklist de Desempenho Operacional para Vencer a Rodada:</h6>
            <div class="row g-2 small">
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="p-2 border rounded bg-white d-flex align-items-center gap-2">
                        <i class="bi <?= ((float)$plano_demanda['percentual_geral'] >= 100.0) ? 'bi-check-circle-fill text-success fs-5' : 'bi-circle text-muted fs-5' ?>"></i>
                        <div><strong>Atender 100% da Demanda</strong> <small class="text-muted d-block">Sem corte de faturamento</small></div>
                    </div>
                </div>
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="p-2 border rounded bg-white d-flex align-items-center gap-2">
                        <i class="bi <?= ((float)$plano_demanda['percentual_geral'] >= 90.0) ? 'bi-check-circle-fill text-success fs-5' : 'bi-truck text-primary fs-5' ?>"></i>
                        <div><strong>OTIF &ge; 90%</strong> <small class="text-muted d-block">Entregas no prazo acordado</small></div>
                    </div>
                </div>
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="p-2 border rounded bg-white d-flex align-items-center gap-2">
                        <i class="bi <?= ($taxa_refugo <= 3.0) ? 'bi-check-circle-fill text-success fs-5' : 'bi-trash text-danger fs-5' ?>"></i>
                        <div><strong>Taxa de Refugo &le; 3,0%</strong> <small class="text-muted d-block">Atual: <?= number_format($taxa_refugo, 2, ',', '.') ?>%</small></div>
                    </div>
                </div>
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="p-2 border rounded bg-white d-flex align-items-center gap-2">
                        <i class="bi <?= $tacho_higienizado ? 'bi-check-circle-fill text-success fs-5' : 'bi-stopwatch text-warning fs-5' ?>"></i>
                        <div><strong>Zero Contaminação</strong> <small class="text-muted d-block"><?= $tacho_higienizado ? 'Tacho em conformidade' : 'POP-04 pendente' ?></small></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- 🧼 DIAGNÓSTICO DO TACHO & ALERTAS DE ESTOQUE               -->
    <!-- ========================================================= -->
    <div class="row g-3 mb-4">
        <!-- Diagnóstico do Tacho Principal -->
        <div class="col-12 col-lg-6">
            <div class="card card-custom p-3 bg-white h-100 shadow-sm border-start border-4 <?= $tacho_higienizado ? 'border-success' : 'border-danger' ?>">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-3">
                        <div class="fs-2">
                            <i class="bi <?= $tacho_higienizado ? 'bi-check-circle-fill text-success' : 'bi-exclamation-octagon-fill text-danger' ?>"></i>
                        </div>
                        <div>
                            <span class="text-muted small text-uppercase fw-bold">Estação de Fusão & Tacho Principal</span>
                            <h6 class="fw-bold my-0 <?= $tacho_higienizado ? 'text-success' : 'text-danger' ?>">
                                <?= $tacho_higienizado ? 'Tacho Higienizado & Neutro' : 'ALERTA: Tacho Sujo com [' . htmlspecialchars($tacho_ultimo_aroma) . ']' ?>
                            </h6>
                            <small class="text-muted">
                                <?= $tacho_higienizado ? 'Liberado para qualquer aroma sem risco de contaminação cruzada.' : 'Fabricar aroma mais suave causará refugo de 50% pelo CQ!' ?>
                            </small>
                        </div>
                    </div>
                    <div>
                        <a href="pages/ordens_producao.php" class="btn btn-sm <?= $tacho_higienizado ? 'btn-outline-success' : 'btn-danger' ?> fw-semibold">
                            <?= $tacho_higienizado ? '<i class="bi bi-eye me-1"></i>Ver Linha' : '<i class="bi bi-droplet-half me-1"></i>Sanitizar (POP-04)' ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alerta de Insumos Críticos no Almoxarifado -->
        <div class="col-12 col-lg-6">
            <div class="card card-custom p-3 bg-white h-100 shadow-sm border-start border-4 <?= count($insumos_criticos) > 0 ? 'border-danger' : 'border-success' ?>">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-3">
                        <div class="fs-2">
                            <i class="bi <?= count($insumos_criticos) > 0 ? 'bi-bell-fill text-danger' : 'bi-shield-check text-success' ?>"></i>
                        </div>
                        <div>
                            <span class="text-muted small text-uppercase fw-bold">Almoxarifado & Insumos</span>
                            <h6 class="fw-bold my-0 <?= count($insumos_criticos) > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= count($insumos_criticos) > 0 ? count($insumos_criticos) . ' insumo(s) abaixo do estoque de segurança!' : 'Estoque 100% equilibrado' ?>
                            </h6>
                            <small class="text-muted">
                                <?= count($insumos_criticos) > 0 ? 'Risco iminente de parada na linha por falta de matérias-primas.' : 'Todas as matérias-primas atendem ao ponto de pedido.' ?>
                            </small>
                        </div>
                    </div>
                    <div>
                        <a href="pages/estoque.php" class="btn btn-sm <?= count($insumos_criticos) > 0 ? 'btn-outline-danger' : 'btn-outline-secondary' ?> fw-semibold">
                            <i class="bi bi-boxes me-1"></i>Abrir Estoque
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- 📊 4 CARDS DE KPIS PRIMÁRIOS                              -->
    <!-- ========================================================= -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-custom p-3 bg-white h-100 shadow-sm border-start border-4 border-primary">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">OPs em Andamento</span>
                        <h3 class="fw-bold my-1 text-dark"><?= number_format($total_ops_ativas, 0, ',', '.') ?></h3>
                        <small class="text-muted">Lotes ativos na fábrica</small>
                    </div>
                    <div class="bg-primary-subtle text-primary p-3 rounded-3">
                        <i class="bi bi-gear-wide-connected fs-4"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-custom p-3 bg-white h-100 shadow-sm border-start border-4 border-success">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Produzido no Mês</span>
                        <h3 class="fw-bold my-1 text-success"><?= number_format($total_produzido, 0, ',', '.') ?> <small class="fs-6 text-muted">barras</small></h3>
                        <small class="text-muted"><?= number_format($perc_capacidade, 1, ',', '.') ?>% do teto mensal (<?= number_format($capacidade_teto, 0, ',', '.') ?> un)</small>
                    </div>
                    <div class="bg-success-subtle text-success p-3 rounded-3">
                        <i class="bi bi-box-seam fs-4"></i>
                    </div>
                </div>
                <div class="progress mt-2" style="height: 5px;">
                    <div class="progress-bar bg-success" role="progressbar" style="width: <?= $perc_capacidade ?>%" aria-valuenow="<?= $perc_capacidade ?>" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-custom p-3 bg-white h-100 shadow-sm border-start border-4 <?= ($taxa_refugo <= 3.0) ? 'border-success' : 'border-danger' ?>">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Perdas / Refugo</span>
                        <h3 class="fw-bold my-1 <?= ($taxa_refugo <= 3.0) ? 'text-success' : 'text-danger' ?>">
                            <?= number_format($total_refugo, 0, ',', '.') ?> <small class="fs-6 text-muted">barras (<?= number_format($taxa_refugo, 2, ',', '.') ?>%)</small>
                        </h3>
                        <small class="text-muted">Meta oficial &le; 3,0%</small>
                    </div>
                    <div class="<?= ($taxa_refugo <= 3.0) ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' ?> p-3 rounded-3">
                        <i class="bi bi-trash fs-4"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-custom p-3 bg-white h-100 shadow-sm border-start border-4 border-info">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Faturamento Faturado</span>
                        <h3 class="fw-bold my-1 text-dark">R$ <?= number_format($faturamento_realizado, 2, ',', '.') ?></h3>
                        <small class="text-muted">Valor das barras prontas</small>
                    </div>
                    <div class="bg-info-subtle text-info-emphasis p-3 rounded-3">
                        <i class="bi bi-cash-stack fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- TABELA DE ORDENS RECENTES & PAINEL LATERAL DE PRODUTOS    -->
    <!-- ========================================================= -->
    <div class="row g-4">
        <!-- Tabela: Ordens de Produção Recentes da Equipe -->
        <div class="col-12 col-lg-8">
            <div class="card card-custom p-4 bg-white h-100 shadow-sm">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-list-task me-2 text-primary"></i>Ordens de Produção Recentes da Equipe
                    </h5>
                    <a href="pages/ordens_producao.php" class="btn btn-sm btn-outline-secondary">Gerenciar Todas as OPs</a>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-uppercase">
                            <tr>
                                <th>OP</th>
                                <th>Fragrância</th>
                                <th>Data Prog.</th>
                                <th>Quantidade</th>
                                <th>Status</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($ordens)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="bi bi-inbox fs-2 d-block mb-2 text-muted"></i>
                                    Nenhuma Ordem de Produção emitida até o momento.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($ordens as $op):
                                $op_status = (string)($op['status'] ?? '');
                                $st = $status_map[$op_status] ?? ['label' => ucfirst($op_status), 'badge' => 'secondary'];
                                $data_prog = !empty($op['data_programada']) ? date('d/m/Y', strtotime((string)$op['data_programada'])) : '-';
                                ?>
                            <tr>
                                <td class="fw-bold font-monospace text-dark"><?= htmlspecialchars((string)($op['numero'] ?? 'OP-' . $op['id'])) ?></td>
                                <td>
                                    <span class="badge bg-light text-dark border me-1 font-monospace"><?= htmlspecialchars((string)($op['produto_codigo'] ?? '')) ?></span>
                                    <strong><?= htmlspecialchars((string)($op['produto_nome'] ?? '')) ?></strong>
                                </td>
                                <td><?= htmlspecialchars($data_prog) ?></td>
                                <td>
                                    <strong><?= number_format((int)$op['quantidade_produzida'], 0, ',', '.') ?></strong> / <?= number_format((int)$op['quantidade_planejada'], 0, ',', '.') ?> un
                                    <?php if ((int)$op['quantidade_refugo'] > 0): ?>
                                        <small class="text-danger d-block">(<?= number_format((int)$op['quantidade_refugo'], 0, ',', '.') ?> refugo)</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= htmlspecialchars((string)$st['badge']) ?> badge-status">
                                        <?= htmlspecialchars((string)$st['label']) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="pages/ordens_producao.php" class="btn btn-sm btn-outline-primary" title="Abrir Ordens de Produção">
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

        <!-- Painel Lateral: Posição dos 4 Produtos Oficiais -->
        <div class="col-12 col-lg-4">
            <div class="card card-custom p-4 bg-white h-100 shadow-sm">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-flower2 me-2 text-success"></i>Fragrâncias Oficiais
                    </h5>
                    <span class="badge bg-light text-dark border">100g</span>
                </div>

                <div class="list-group list-group-flush mb-3">
                    <?php if (empty($produtos)): ?>
                        <div class="text-center py-4 text-muted small">
                            Nenhum produto acabado cadastrado para esta equipe.
                        </div>
                    <?php else: ?>
                        <?php foreach ($produtos as $p):
                            $estoque_atual  = (int)($p['estoque_atual'] ?? 0);
                            $estoque_minimo = (int)($p['estoque_minimo'] ?? 0);
                            $alerta_estoque = ($estoque_atual <= $estoque_minimo);
                            ?>
                        <div class="list-group-item px-0 py-3 border-bottom">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <div>
                                    <span class="badge bg-secondary font-monospace"><?= htmlspecialchars((string)$p['codigo']) ?></span>
                                    <strong class="text-dark ms-1"><?= htmlspecialchars((string)$p['nome']) ?></strong>
                                    <div class="small text-muted mt-1">
                                        Intensidade: <span class="badge bg-secondary-subtle text-secondary"><?= ucfirst(htmlspecialchars((string)$p['intensidade_aroma'])) ?></span>
                                        &bull; R$ <?= number_format((float)$p['preco_venda'], 2, ',', '.') ?>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <span class="fw-bold fs-6 <?= $alerta_estoque ? 'text-danger' : 'text-dark' ?>">
                                        <?= number_format($estoque_atual, 0, ',', '.') ?> un
                                    </span>
                                    <div class="small text-muted">Mín: <?= number_format($estoque_minimo, 0, ',', '.') ?> un</div>
                                </div>
                            </div>
                            <?php if ($alerta_estoque): ?>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle small mt-1">
                                    <i class="bi bi-arrow-down-circle me-1"></i>Abaixo do estoque de segurança
                                </span>
                            <?php else: ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle small mt-1">
                                    <i class="bi bi-check-circle me-1"></i>Estoque equilibrado
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="d-grid gap-2">
                    <a href="pages/programacao.php" class="btn btn-outline-success fw-semibold">
                        <i class="bi bi-diagram-3 me-1"></i> Sequenciador (Regra de Ouro)
                    </a>
                    <a href="pages/estoque.php" class="btn btn-outline-secondary fw-semibold">
                        <i class="bi bi-boxes me-1"></i> Controle de Estoque & Kardex
                    </a>
                    <a href="pages/relatorios.php" class="btn btn-outline-primary fw-semibold">
                        <i class="bi bi-graph-up-arrow me-1"></i> Relatório de Indicadores PCP
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';