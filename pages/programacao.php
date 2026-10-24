<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';

$page_title = 'Sequenciamento & Matriz de Setup';
$mensagem_sucesso = '';
$mensagem_erro = '';

// Mapeamento oficial do estudo de caso: Maracujá (1) -> Pitanga (2) -> Lavanda (3) -> Rosas (4)
$escala_oficial = ORDEM_AROMAS_RECOMENDADA;

// ---------------------------------------------------------
// REORDENAÇÃO AUTOMÁTICA PELA REGRA DE OURO DA FLORA SABÃO
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'otimizar_sequencia') {
    $stmt = $pdo->query("
        SELECT op.id, p.codigo
        FROM ordens_producao op
        JOIN produtos p ON op.produto_id = p.id
        WHERE op.status IN ('planejada', 'em_producao')
    ");
    $ops_ativas = $stmt->fetchAll();

    // Ordenação estrita: Maracujá (1) -> Pitanga (2) -> Lavanda (3) -> Rosas (4)
    usort($ops_ativas, function ($a, $b) use ($escala_oficial) {
        $ordemA = $escala_oficial[$a['codigo']] ?? 99;
        $ordemB = $escala_oficial[$b['codigo']] ?? 99;
        return $ordemA <=> $ordemB;
    });

    $data_base = new DateTime();
    foreach ($ops_ativas as $item) {
        $stmt_up = $pdo->prepare("UPDATE ordens_producao SET data_programada = ? WHERE id = ?");
        $stmt_up->execute([$data_base->format('Y-m-d'), $item['id']]);
        $data_base->modify('+1 day');
    }

    $mensagem_sucesso = 'Regra de Ouro aplicada: Maracujá &rarr; Pitanga &rarr; Lavanda &rarr; Rosas. Tempo de setup minimizado!';
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
    $motivo_setup = 'Primeiro lote do sequenciamento ou troca balanceada.';

    if ($op_anterior !== null) {
        $pos_ant = $escala_oficial[$op_anterior['produto_codigo']] ?? 2;
        $pos_atual = $escala_oficial[$op['produto_codigo']] ?? 2;

        if ($op_anterior['produto_codigo'] === $op['produto_codigo']) {
            $setup_min = 15;
            $tipo_setup = 'mesmo_produto';
            $motivo_setup = 'Mesma fragrância: apenas raspagem e limpeza de bico de enchimento.';
        } elseif ($pos_ant > $pos_atual) {
            // Violou a Regra de Ouro (Ex: Rosas antes de Lavanda ou Maracujá)
            $setup_min = TEMPO_SETUP_LIMPEZA_PESADA_MIN; // 45 min
            $tipo_setup = 'limpeza_pesada';
            $alerta_contaminacao = true;
            $total_riscos_contaminacao++;
            $motivo_setup = "ALERTA DE CONTAMINAÇÃO: Produzindo aroma mais forte ({$op_anterior['produto_nome']}) antes de um mais suave ({$op['produto_nome']}). Exige 45 min de sanitização pesada com álcool 70%.";
        } else {
            $setup_min = 20;
            $tipo_setup = 'transicao_suave';
            $motivo_setup = "Transição correta na curva olfativa ({$op_anterior['produto_codigo']} &rarr; {$op['produto_codigo']}).";
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
            <p class="text-muted mb-0">Controle olfativo de bateladas para prevenir contaminação cruzada.</p>
        </div>
        <div>
            <form method="POST" action="programacao.php" class="d-inline"
                onsubmit="return confirm('Deseja reorganizar a fila conforme a Regra de Ouro (Maracujá -> Pitanga -> Lavanda -> Rosas)?');">
                <input type="hidden" name="acao" value="otimizar_sequencia">
                <button type="submit" class="btn btn-warning shadow-sm fw-semibold text-dark">
                    <i class="bi bi-magic me-1"></i> Aplicar Regra de Ouro (Maracujá &rarr; Rosas)
                </button>
            </form>
        </div>
    </div>

    <?php if (!empty($mensagem_sucesso)): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?= htmlspecialchars($mensagem_sucesso) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Painel Didático Oficial -->
    <div class="card card-custom bg-white p-3 mb-4 border-start border-4 border-success">
        <div class="d-flex align-items-start gap-3">
            <div class="fs-2 text-success"><i class="bi bi-lightbulb"></i></div>
            <div>
                <h6 class="fw-bold text-dark mb-1">A Regra de Ouro da Flora Sabão (Conforme Caso de Estudo)</h6>
                <p class="small text-muted mb-2">
                    Produzir as fragrâncias mais leves e neutras primeiro e só depois passar para as mais fortes e
                    marcantes.
                </p>
                <div class="d-flex flex-wrap align-items-center gap-2 small">
                    <span class="badge bg-warning text-dark px-2 py-1">1º Maracujá (Suave)</span>
                    <i class="bi bi-arrow-right text-muted"></i>
                    <span class="badge bg-danger-subtle text-danger border px-2 py-1">2º Pitanga</span>
                    <i class="bi bi-arrow-right text-muted"></i>
                    <span class="badge bg-primary-subtle text-primary border px-2 py-1">3º Lavanda</span>
                    <i class="bi bi-arrow-right text-muted"></i>
                    <span class="badge bg-dark text-white px-2 py-1">4º Rosas (Mais Forte)</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Indicadores de Setup -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-4">
            <div class="card card-custom bg-white p-3 h-100">
                <span class="text-muted small text-uppercase fw-semibold">Tempo Total de Setup</span>
                <h3 class="fw-bold my-1 text-primary"><?= $total_tempo_setup_min ?> <small
                        class="fs-6 text-muted">min</small></h3>
                <small class="text-muted">Paradas de linha: <?= round($total_tempo_setup_min / 60, 1) ?> horas</small>
            </div>
        </div>
        <div class="col-12 col-sm-4">
            <div class="card card-custom bg-white p-3 h-100">
                <span class="text-muted small text-uppercase fw-semibold">Riscos de Contaminação</span>
                <h3 class="fw-bold my-1 <?= $total_riscos_contaminacao > 0 ? 'text-danger' : 'text-success' ?>">
                    <?= $total_riscos_contaminacao ?> ocorrência(s)
                </h3>
                <small
                    class="text-muted"><?= $total_riscos_contaminacao > 0 ? 'Exigem 45 min de sanitização pesada' : 'Sequenciamento 100% ideal' ?></small>
            </div>
        </div>
        <div class="col-12 col-sm-4">
            <div class="card card-custom bg-white p-3 h-100">
                <span class="text-muted small text-uppercase fw-semibold">Tempo do Posto Gargalo</span>
                <h3 class="fw-bold my-1 text-dark"><?= TEMPO_GARGALO_LOTE_MIN ?> <small
                        class="fs-6 text-muted">min/lote</small></h3>
                <small class="text-muted">Posto 3: Moldagem dos sabonetes</small>
            </div>
        </div>
    </div>

    <!-- Tabela da Fila -->
    <div class="card card-custom bg-white p-4">
        <h5 class="fw-bold mb-3 text-dark">
            <i class="bi bi-diagram-3 me-2 text-secondary"></i>Linha de Sequenciamento de Lotes
        </h5>

        <?php if (empty($sequencia_analisada)): ?>
            <div class="text-center py-5 text-muted">Não há ordens de produção ativas na fila.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead class="table-light small text-uppercase">
                        <tr>
                            <th style="width: 50px;">Seq.</th>
                            <th>Data</th>
                            <th>Ordem (OP)</th>
                            <th>Fragrância</th>
                            <th>Lote</th>
                            <th>Tempo Setup</th>
                            <th>Diagnóstico de Setup</th>
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