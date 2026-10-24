<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

// Apenas admin tem acesso
if (($_SESSION['usuario_perfil'] ?? '') !== 'admin') {
    flash('erro', 'Apenas o Instrutor tem permissão para acessar esta área.');
    header('Location: ../index.php');
    exit;
}

$page_title = 'Painel do Instrutor';

// ========================================================
// AÇÕES DO INSTRUTOR (POST)
// ========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // ====================================================
    // AVANÇAR RODADA — delegação única para fecharRodadaAtual()
    // (motor transacional em includes/functions.php §8)
    // ====================================================
    if ($acao === 'avancar_rodada') {
        $evento_nome = trim((string) ($_POST['evento_nome'] ?? 'Mercado Estável'));
        $evento_desc = trim((string) ($_POST['evento_desc'] ?? 'Condições normais de mercado.'));

        // Sanitização: campos vazios caem no padrão
        if ($evento_nome === '') {
            $evento_nome = 'Mercado Estável';
        }
        if ($evento_desc === '') {
            $evento_desc = 'Condições normais de mercado.';
        }

        // Toda a lógica transacional está em includes/functions.php
        $resultado = fecharRodadaAtual($pdo, $evento_nome, $evento_desc);

        // Garante que a resposta sempre tem a chave 'message'
        flash(
            !empty($resultado['success']) ? 'sucesso' : 'erro',
            $resultado['message'] ?? 'Erro desconhecido ao fechar rodada.'
        );
        header('Location: instrutor.php');
        exit;
    }

    // (outros ifs de ação do instrutor, se houver, entram aqui)
}

// ========================================================
// CONSULTAS DA PÁGINA
// ========================================================
$rodada_atual = $pdo->query("
    SELECT * FROM jogo_rodadas
    WHERE status = 'aberta'
    ORDER BY numero_rodada DESC, id DESC
    LIMIT 1
")->fetch();

// ★ Inclui falidas para que o instrutor veja tudo
$equipes = $pdo->query("
    SELECT e.*,
           (SELECT COUNT(*) FROM ordens_producao
            WHERE equipe_id = e.id AND status = 'finalizada') AS ops_feitas,
           (SELECT COUNT(*) FROM ordens_producao
            WHERE equipe_id = e.id AND status IN ('planejada', 'em_producao')) AS ops_abertas
    FROM equipes e
    WHERE e.ativo = 1 OR e.falida = 1
    ORDER BY e.falida ASC, e.score_total DESC, e.id ASC
")->fetchAll();

// Resumo financeiro agregado
$total_caixa_geral = 0.0;
$qtd_saudaveis     = 0;
$qtd_em_risco      = 0;
$qtd_falidas       = 0;

foreach ($equipes as $eqX) {
    $total_caixa_geral += (float) $eqX['caixa_disponivel'];

    if ((int) $eqX['falida'] === 1) {
        $qtd_falidas++;
    } elseif ((int) ($eqX['rodadas_no_vermelho'] ?? 0) > 0 || (float) $eqX['caixa_disponivel'] < 0) {
        $qtd_em_risco++;
    } else {
        $qtd_saudaveis++;
    }
}

$mensagem_sucesso = lerFlash('sucesso');
$mensagem_erro    = lerFlash('erro');

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="container-fluid px-4 pb-4">

    <!-- ========================================================= -->
    <!-- CABEÇALHO DA PÁGINA                                        -->
    <!-- ========================================================= -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h2 class="fw-bold mb-1 text-dark">
                <i class="bi bi-controller text-danger me-2"></i>Painel do Mestre / Instrutor
            </h2>
            <p class="text-muted mb-0">
                Controle do avanço das rodadas, eventos econômicos e ranking da turma.
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="ranking.php" target="_blank" class="btn btn-outline-warning text-dark fw-bold shadow-sm">
                <i class="bi bi-tv me-1"></i> Abrir Telão do Ranking
            </a>
            <button class="btn btn-danger shadow-sm fw-bold"
                    data-bs-toggle="modal" data-bs-target="#modalAvancar">
                <i class="bi bi-fast-forward-fill me-1"></i> Encerrar Rodada & Avançar
            </button>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- ALERTAS FLASH                                              -->
    <!-- ========================================================= -->
    <?php if (!empty($mensagem_sucesso)): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> <?= htmlspecialchars($mensagem_sucesso) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($mensagem_erro)): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($mensagem_erro) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- ========================================================= -->
    <!-- RESUMO FINANCEIRO GERAL (4 cards)                          -->
    <!-- ========================================================= -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card card-custom p-3 bg-white h-100 shadow-sm">
                <span class="text-muted small text-uppercase fw-semibold">Caixa Geral da Turma</span>
                <h4 class="fw-bold my-1 <?= $total_caixa_geral < 0 ? 'text-danger' : 'text-success' ?>">
                    R$ <?= number_format($total_caixa_geral, 2, ',', '.') ?>
                </h4>
                <small class="text-muted">Somatório dos caixas das equipes</small>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card card-custom p-3 bg-white h-100 shadow-sm border-start border-4 border-success">
                <span class="text-muted small text-uppercase fw-semibold">Equipes Saudáveis</span>
                <h4 class="fw-bold my-1 text-success"><?= $qtd_saudaveis ?></h4>
                <small class="text-muted">Caixa positivo e sem histórico de vermelho</small>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card card-custom p-3 bg-white h-100 shadow-sm border-start border-4 border-warning">
                <span class="text-muted small text-uppercase fw-semibold">Em Risco de Falência</span>
                <h4 class="fw-bold my-1 text-warning-emphasis"><?= $qtd_em_risco ?></h4>
                <small class="text-muted">1 rodada no vermelho ou caixa negativo</small>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card card-custom p-3 bg-white h-100 shadow-sm border-start border-4 border-danger">
                <span class="text-muted small text-uppercase fw-semibold">Equipes Falidas</span>
                <h4 class="fw-bold my-1 text-danger"><?= $qtd_falidas ?></h4>
                <small class="text-muted">Fora da competição</small>
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- STATUS DA RODADA ATUAL                                     -->
    <!-- ========================================================= -->
    <?php if ($rodada_atual): ?>
        <div class="card card-custom bg-white p-3 mb-4 border-start border-4 border-danger shadow-sm">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                <div>
                    <span class="badge bg-danger text-white text-uppercase px-2 py-1 mb-1">
                        Status da Partida
                    </span>
                    <h4 class="fw-bold my-0">
                        Rodada <?= (int) $rodada_atual['numero_rodada'] ?>
                        (<?= htmlspecialchars($rodada_atual['mes_ano']) ?>)
                    </h4>
                    <div class="small text-muted mt-1">
                        <strong>Cenário Ativo:</strong>
                        <?= htmlspecialchars($rodada_atual['evento_titulo']) ?>
                        &bull;
                        <?= htmlspecialchars($rodada_atual['evento_descricao']) ?>
                        &bull;
                        <strong>Fator Demanda:</strong>
                        <?= number_format((float) $rodada_atual['fator_demanda'], 2, ',', '.') ?>×
                    </div>
                </div>
                <div class="text-end">
                    <span class="badge bg-success-subtle text-success fs-6 px-3 py-2 border border-success-subtle">
                        <i class="bi bi-play-circle me-1"></i> Rodada em Andamento
                    </span>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-warning border-0 shadow-sm mb-4">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <strong>Nenhuma rodada aberta.</strong>
            Use o botão "Encerrar Rodada & Avançar" — se não existir rodada anterior, o sistema criará a primeira automaticamente.
        </div>
    <?php endif; ?>

    <!-- ========================================================= -->
    <!-- TABELA DE DESEMPENHO DAS EQUIPES                           -->
    <!-- ========================================================= -->
    <div class="card card-custom bg-white p-4 shadow-sm">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold mb-0">
                <i class="bi bi-people me-2 text-primary"></i>Desempenho das Equipes
            </h5>
            <span class="badge bg-light text-dark border">
                <?= count($equipes) ?> equipe(s) cadastrada(s)
            </span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light small text-uppercase">
                    <tr>
                        <th style="width: 70px;">Posição</th>
                        <th>Equipe</th>
                        <th>Código de Acesso</th>
                        <th>OPs Finalizadas</th>
                        <th>OPs na Fila</th>
                        <th>Caixa Atual</th>
                        <th>Score</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($equipes as $idx => $eq):
                        $is_falida   = (int) $eq['falida'] === 1;
                        $rodadas_neg = (int) ($eq['rodadas_no_vermelho'] ?? 0);
                        $caixa_neg   = (float) $eq['caixa_disponivel'] < 0;

                        // Classe da linha: falida > vermelho > normal
                        $row_class = '';
                        if ($is_falida) {
                            $row_class = 'table-danger';
                        } elseif ($rodadas_neg > 0 || $caixa_neg) {
                            $row_class = 'table-warning';
                        }
                    ?>
                        <tr class="<?= $row_class ?>">
                            <td class="fw-bold fs-5 text-center">
                                <?php if ($is_falida): ?>
                                    <span class="text-muted">—</span>
                                <?php else: ?>
                                    <?= $idx + 1 ?>º
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge me-1"
                                      style="background-color: <?= htmlspecialchars($eq['cor_hex']) ?>;">
                                    &nbsp;
                                </span>
                                <strong><?= htmlspecialchars($eq['nome']) ?></strong>
                                <?php if ($is_falida): ?>
                                    <i class="bi bi-emoji-dizzy text-danger ms-1" title="Equipe falida"></i>
                                <?php endif; ?>
                            </td>
                            <td>
                                <code class="fw-bold text-success user-select-all">
                                    <?= htmlspecialchars($eq['codigo_acesso']) ?>
                                </code>
                            </td>
                            <td>
                                <span class="badge bg-success">
                                    <?= (int) $eq['ops_feitas'] ?> finalizadas
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-warning text-dark">
                                    <?= (int) $eq['ops_abertas'] ?> pendentes
                                </span>
                            </td>
                            <td class="<?= $caixa_neg ? 'text-danger fw-bold' : '' ?>">
                                R$ <?= number_format((float) $eq['caixa_disponivel'], 2, ',', '.') ?>
                            </td>
                            <td class="fw-bold fs-5 text-primary">
                                <?= (int) $eq['score_total'] ?> pts
                            </td>
                            <td>
                                <?php if ($is_falida): ?>
                                    <span class="badge bg-danger">
                                        <i class="bi bi-x-octagon-fill me-1"></i> FALIDA
                                    </span>
                                <?php elseif ($rodadas_neg > 0): ?>
                                    <span class="badge bg-warning text-dark">
                                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                        <?= $rodadas_neg ?>/2 no vermelho
                                    </span>
                                <?php elseif ($caixa_neg): ?>
                                    <span class="badge bg-warning text-dark">
                                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                        Caixa negativo (1/2)
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-success">
                                        <i class="bi bi-check-circle-fill me-1"></i> Saudável
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Legenda didática -->
        <div class="border-top mt-3 pt-3 small text-muted">
            <strong><i class="bi bi-info-circle me-1"></i>Como funciona o controle financeiro:</strong>
            <ul class="mb-0 mt-1 ps-3">
                <li><span class="badge bg-success">Saudável</span> — Caixa positivo e sem histórico de vermelho na rodada.</li>
                <li><span class="badge bg-warning text-dark">X/2 no vermelho</span> — A equipe fechou <strong>1 rodada</strong> com caixa negativo. Se repetir na próxima, falirá.</li>
                <li><span class="badge bg-danger">FALIDA</span> — Duas rodadas consecutivas no vermelho. A equipe sai da competição (não produz nem vende mais).</li>
            </ul>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: AVANÇAR RODADA                                         -->
<!-- ============================================================ -->
<div class="modal fade" id="modalAvancar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="instrutor.php" class="modal-content">
            <input type="hidden" name="acao" value="avancar_rodada">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-danger">
                    <i class="bi bi-fast-forward me-2"></i>Avançar para a Próxima Rodada
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning small mb-3">
                    Ao confirmar, o sistema vai:
                    <ol class="mb-0 mt-1 ps-3">
                        <li>Vender o estoque de PA até o limite da meta de cada equipe</li>
                        <li>Creditar a receita no caixa e registrar o Kardex</li>
                        <li>Apurar <strong>OTIF, Aderência, Yield e Penalidades de Setup</strong></li>
                        <li>Calcular o score da rodada (operacional + bônus de lucro)</li>
                        <li><strong class="text-danger">Avaliar falência</strong> (2 rodadas no vermelho = equipe falida)</li>
                        <li>Encerrar a rodada atual e abrir a próxima</li>
                    </ol>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Evento da Próxima Rodada:</label>
                    <select name="evento_nome" id="selectEvento"
                            class="form-select" onchange="atualizarDescricaoEvento()" required>
                        <option value="Mercado Estável">Mercado Estável (sem surpresas)</option>
                        <option value="Boom de Lavanda">Boom de Demanda: Lavanda em alta (+60%)</option>
                        <option value="Pico Natalino">Pico Natalino: Demanda geral explosiva (+80%)</option>
                        <option value="Atraso do Fornecedor">Crise de Insumos: fornecedor atrasa (+10%)</option>
                        <option value="Fiscalização BPF">Auditoria Sanitária: setup penalizado</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Instrução / Mensagem aos Alunos:</label>
                    <textarea name="evento_desc" id="campoDescEvento"
                              class="form-control" rows="3">Mercado opera sob condições normais de abastecimento.</textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-danger fw-bold">
                    <i class="bi bi-check2-circle me-1"></i> Fechar Rodada Agora
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- SCRIPT: Descrição dinâmica do evento                          -->
<!-- ============================================================ -->
<script>
function atualizarDescricaoEvento() {
    const sel  = document.getElementById('selectEvento').value;
    const desc = document.getElementById('campoDescEvento');
    const mapa = {
        'Mercado Estável':      'Mercado opera sob condições normais de abastecimento.',
        'Boom de Lavanda':      'Tendência viral fez a procura por Lavanda crescer 60%! Quem não tiver estoque perderá vendas no OTIF.',
        'Pico Natalino':        'Fim de ano! Todas as fragrâncias em alta. O gargalo de moldagem será o desafio da rodada.',
        'Atraso do Fornecedor': 'Greve nas distribuidoras atrasou entregas de base glicerinada. Compras serão mais caras.',
        'Fiscalização BPF':     'Vigilância Sanitária no chão de fábrica! Trocas sem sanitização pesada serão multadas em dobro.'
    };
    desc.value = mapa[sel] || '';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>