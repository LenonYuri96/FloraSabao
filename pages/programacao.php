<?php
declare(strict_types=1);

// 🔐 GUARDA DE AUTENTICAÇÃO — deve vir PRIMEIRO
require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
$page_title = 'Sequenciamento & Matriz de Setup';
$mensagem_sucesso = '';
$mensagem_erro = '';

// Mapeamento numérico da intensidade para cálculo de penalidade de setup
$escala_aroma = [
    'suave' => 1, // Ex: Lavanda
    'moderado' => 2, // Ex: Pitanga, Rosas
    'intenso' => 3  // Ex: Maracujá cítrico
];

// ---------------------------------------------------------
// REORDENAÇÃO AUTOMÁTICA OTIMIZADA (Heurística de Menor Setup)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'otimizar_sequencia') {
    // Busca todas as OPs ativas
    $stmt = $pdo->query("
        SELECT op.id, p.intensidade_aroma, p.codigo
        FROM ordens_producao op
        JOIN produtos p ON op.produto_id = p.id
        WHERE op.status IN ('planejada', 'em_producao')
    ");
    $ops_ativas = $stmt->fetchAll();

    // Ordena da menor intensidade para a maior (Suave -> Moderado -> Intenso)
    usort($ops_ativas, function ($a, $b) use ($escala_aroma) {
        $nivelA = $escala_aroma[$a['intensidade_aroma']] ?? 2;
        $nivelB = $escala_aroma[$b['intensidade_aroma']] ?? 2;
        if ($nivelA === $nivelB) {
            return strcmp($a['codigo'], $b['codigo']);
        }
        return $nivelA <=> $nivelB;
    });

    // Atualiza as datas programadas sequencialmente em passos diários
    $data_base = new DateTime();
    foreach ($ops_ativas as $item) {
        $stmt_up = $pdo->prepare("UPDATE ordens_producao SET data_programada = ? WHERE id = ?");
        $stmt_up->execute([$data_base->format('Y-m-d'), $item['id']]);
        $data_base->modify('+1 day');
    }

    $mensagem_sucesso = 'Heurística aplicada com sucesso! Lotes reordenados do aroma suave ao mais intenso para minimizar o tempo de setup.';
}

// ---------------------------------------------------------
// CONSULTA DA FILA DE PRODUÇÃO ATUAL
// ---------------------------------------------------------
$stmt = $pdo->query("
    SELECT op.id, op.numero, op.quantidade_planejada, op.data_programada, op.status,
           p.nome AS produto_nome, p.codigo AS produto_codigo, p.aroma, p.intensidade_aroma
    FROM ordens_producao op
    JOIN produtos p ON op.produto_id = p.id
    WHERE op.status IN ('planejada', 'em_producao')
    ORDER BY op.data_programada ASC, op.id ASC
");
$fila_producao = $stmt->fetchAll();

// ---------------------------------------------------------
// CÁLCULO DA MATRIZ DE TRANSIÇÃO E RISCOS DE CONTAMINAÇÃO
// ---------------------------------------------------------
$sequencia_analisada = [];
$total_tempo_setup_min = 0;
$total_riscos_contaminacao = 0;
$op_anterior = null;

foreach ($fila_producao as $op) {
    $setup_min = TEMPO_SETUP_PADRAO_MIN; // 20 min padrão
    $tipo_setup = 'padrao';
    $alerta_contaminacao = false;
    $motivo_setup = 'Primeiro lote da linha ou troca convencional.';

    if ($op_anterior !== null) {
        $nivel_ant = $escala_aroma[$op_anterior['intensidade_aroma']] ?? 2;
        $nivel_atual = $escala_aroma[$op['intensidade_aroma']] ?? 2;

        if ($op_anterior['produto_codigo'] === $op['produto_codigo']) {
            // Mesmo aroma: limpeza mínima de batelada
            $setup_min = 15;
            $tipo_setup = 'mesmo_produto';
            $motivo_setup = 'Mesmo produto: raspagem e limpeza de bico apenas.';
        } elseif ($nivel_ant > $nivel_atual) {
            // Risco Crítico: Aroma forte antes de um suave (ex: Maracujá -> Lavanda)
            $setup_min = TEMPO_SETUP_LIMPEZA_PESADA_MIN; // 45 min
            $tipo_setup = 'limpeza_pesada';
            $alerta_contaminacao = true;
            $total_riscos_contaminacao++;
            $motivo_setup = "Risco de Contaminação Cruzada! Transição de aroma {$op_anterior['intensidade_aroma']} ({$op_anterior['produto_codigo']}) para {$op['intensidade_aroma']} ({$op['produto_codigo']}). Exige sanitização pesada com álcool 70%.";
        } else {
            // Transição segura: Suave -> Mais forte
            $setup_min = 20;
            $tipo_setup = 'transicao_suave';
            $motivo_setup = "Transição segura na curva olfativa ({$op_anterior['intensidade_aroma']} -> {$op['intensidade_aroma']}).";
        }
    }

    $total_tempo_setup_min += $setup_min;

    $sequencia_analisada[] = [
        'op' => $op,
        'setup_min' => $setup_min,
        'tipo_setup' => $tipo_setup,
        'alerta_contaminacao' => $alerta_contaminacao,
        'motivo_setup' => $motivo_setup,
        'anterior' => $op_anterior
    ];

    $op_anterior = $op;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="container-fluid px-4 pb-4">
    <!-- Cabeçalho -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h2 class="fw-bold mb-1 text-dark">
                <i class="bi bi-calendar3 text-success me-2"></i>Sequenciamento de Produção & Matriz de Setup
            </h2>
            <p class="text-muted mb-0">Programação da linha artesanal para evitar contaminação cruzada e minimizar tempo
                ocioso.</p>
        </div>
        <div>
            <form method="POST" action="programacao.php" class="d-inline"
                onsubmit="return confirm('Deseja reorganizar automaticamente a fila de OPs pelo critério olfativo (Suave -> Intenso)?');">
                <input type="hidden" name="acao" value="otimizar_sequencia">
                <button type="submit" class="btn btn-warning shadow-sm fw-semibold text-dark">
                    <i class="bi bi-magic me-1"></i> Aplicar Heurística de Otimização (PCP)
                </button>
            </form>
        </div>
    </div>

    <!-- Alertas Flash -->
    <?php if (!empty($mensagem_sucesso)): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> <?= htmlspecialchars($mensagem_sucesso) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Painel Didático: A Regra de Ouro da Saboaria -->
    <div class="card card-custom bg-white p-3 mb-4 border-start border-4 border-success">
        <div class="d-flex align-items-start gap-3">
            <div class="fs-2 text-success"><i class="bi bi-lightbulb"></i></div>
            <div>
                <h6 class="fw-bold text-dark mb-1">Regra de Ouro do PCP em Cosméticos Artesanais</h6>
                <p class="small text-muted mb-0">
                    Sempre sequencie a produção dos <strong>aromas suaves para os aromas intensos</strong> (Lavanda
                    &rarr; Rosas &rarr; Pitanga &rarr; Maracujá).
                    Se um lote de aroma cítrico ou marcante (ex: Maracujá) for fabricado antes de um floral suave (ex:
                    Lavanda), o óleo essencial residual impregna os tachos de fusão e as espátulas de inox, exigindo
                    <strong>45 min de sanitização pesada</strong> para evitar contaminação olfativa cruzada.
                </p>
            </div>
        </div>
    </div>

    <!-- Cards de Resumo de Setup -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-4">
            <div class="card card-custom bg-white p-3 h-100">
                <span class="text-muted small text-uppercase fw-semibold">Tempo Total de Setup Projetado</span>
                <h3 class="fw-bold my-1 text-primary"><?= $total_tempo_setup_min ?> <small
                        class="fs-6 text-muted">minutos</small></h3>
                <small class="text-muted">Equivalente a <?= round($total_tempo_setup_min / 60, 1) ?> horas de paradas de
                    linha</small>
            </div>
        </div>
        <div class="col-12 col-sm-4">
            <div class="card card-custom bg-white p-3 h-100">
                <span class="text-muted small text-uppercase fw-semibold">Pontos Críticos de Contaminação</span>
                <h3 class="fw-bold my-1 <?= $total_riscos_contaminacao > 0 ? 'text-danger' : 'text-success' ?>">
                    <?= $total_riscos_contaminacao ?> ocorrência(s)
                </h3>
                <small
                    class="text-muted"><?= $total_riscos_contaminacao > 0 ? 'Lotes invertidos detectados' : 'Sequenciamento olfativo ideal' ?></small>
            </div>
        </div>
        <div class="col-12 col-sm-4">
            <div class="card card-custom bg-white p-3 h-100">
                <span class="text-muted small text-uppercase fw-semibold">Tamanho da Fila</span>
                <h3 class="fw-bold my-1 text-dark"><?= count($fila_producao) ?> <small class="fs-6 text-muted">lotes
                        programados</small></h3>
                <small class="text-muted">Ordem cronológica da fábrica</small>
            </div>
        </div>
    </div>

    <!-- Linha do Tempo Visual do Sequenciamento -->
    <div class="card card-custom bg-white p-4">
        <h5 class="fw-bold mb-3 text-dark">
            <i class="bi bi-diagram-3 me-2 text-secondary"></i>Linha de Sequenciamento e Troca de Lotes
        </h5>

        <?php if (empty($sequencia_analisada)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                Não há ordens de produção pendentes para sequenciamento.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead class="table-light small text-uppercase">
                        <tr>
                            <th style="width: 50px;">Seq.</th>
                            <th>Data</th>
                            <th>Ordem (OP)</th>
                            <th>Fragrância</th>
                            <th>Intensidade</th>
                            <th>Lote</th>
                            <th>Tempo Setup</th>
                            <th>Diagnóstico PCP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sequencia_analisada as $idx => $item):
                            $op = $item['op'];
                            ?>
                            <tr class="<?= $item['alerta_contaminacao'] ? 'table-danger' : '' ?>">
                                <td class="fw-bold text-center"><?= $idx + 1 ?>º</td>
                                <td><?= date('d/m/Y', strtotime($op['data_programada'])) ?></td>
                                <td class="fw-bold font-monospace"><?= htmlspecialchars($op['numero']) ?></td>
                                <td>
                                    <span
                                        class="badge bg-light text-dark border me-1"><?= htmlspecialchars($op['produto_codigo']) ?></span>
                                    <strong><?= htmlspecialchars($op['produto_nome']) ?></strong>
                                </td>
                                <td>
                                    <span
                                        class="badge bg-<?= $op['intensidade_aroma'] === 'intenso' ? 'danger' : ($op['intensidade_aroma'] === 'moderado' ? 'warning text-dark' : 'info text-dark') ?>">
                                        <?= ucfirst($op['intensidade_aroma']) ?>
                                    </span>
                                </td>
                                <td class="fw-semibold"><?= $op['quantidade_planejada'] ?> barras</td>
                                <td>
                                    <strong class="font-monospace <?= $item['setup_min'] > 20 ? 'text-danger' : 'text-dark' ?>">
                                        <?= $item['setup_min'] ?> min
                                    </strong>
                                </td>
                                <td>
                                    <?php if ($item['alerta_contaminacao']): ?>
                                        <span class="text-danger fw-semibold small d-block">
                                            <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= $item['motivo_setup'] ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-success small d-block">
                                            <i class="bi bi-check-circle me-1"></i> <?= $item['motivo_setup'] ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';