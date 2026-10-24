<?php
declare(strict_types=1);

/**
 * Flora Sabão — Painel de Controle do Instrutor & Gestor de Turmas (Business Game)
 * ==============================================================================
 * Sistema Multi-Tenant e Multi-Turma:
 * Permite que um mesmo professor administre várias disciplinas e turmas em paralelo,
 * cada qual com seu ciclo de rodadas, carteira de clientes S&OP, equipes e estoque próprios.
 */

// 🔐 GUARDA DE AUTENTICAÇÃO E PERFIS
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

// Apenas usuários com perfil admin podem acessar este painel
if (!isAdmin()) {
    flash('erro', 'Apenas instrutores têm permissão para acessar o Painel de Controle de Jogos.');
    header('Location: ../index.php');
    exit;
}

$page_title   = 'Painel do Instrutor & Gestão de Turmas';
$instrutor_id = (int)($_SESSION['usuario_id'] ?? 0);

// ==============================================================================
// 1. PROCESSAMENTO DE FORMULÁRIOS E REQUISIÇÕES (POST)
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = trim((string)($_POST['acao'] ?? ''));

    // ----------------------------------------------------
    // AÇÃO 1: CRIAR NOVO JOGO / NOVA TURMA
    // ----------------------------------------------------
    if ($acao === 'criar_jogo') {
        $nome_turma    = trim((string)($_POST['nome_turma'] ?? ''));
        $pin_jogo      = strtoupper(trim((string)($_POST['pin_jogo'] ?? '')));
        $qtd_equipes   = max(2, min(6, (int)($_POST['qtd_equipes'] ?? 6)));
        $caixa_inicial = (float)str_replace(',', '.', (string)($_POST['caixa_inicial'] ?? '15000.00'));

        if (empty($nome_turma) || empty($pin_jogo)) {
            flash('erro', 'Informe o nome da turma e um PIN/Sigla válido.');
        } elseif (strlen($pin_jogo) < 3 || strlen($pin_jogo) > 12) {
            flash('erro', 'O PIN da turma deve ter entre 3 e 12 caracteres alfanuméricos.');
        } else {
            // Verifica duplicidade de PIN
            $stmt_chk = $pdo->prepare("SELECT id FROM jogos WHERE pin_jogo = ?");
            $stmt_chk->execute([$pin_jogo]);
            if ($stmt_chk->fetch()) {
                flash('erro', "O PIN \"{$pin_jogo}\" já está em uso por outra turma no sistema. Escolha outro identificador.");
            } else {
                try {
                    $novo_jogo_id = criarNovoJogo($pdo, $instrutor_id, $nome_turma, $pin_jogo, $qtd_equipes, $caixa_inicial);
                    flash('sucesso', "🎉 Turma \"{$nome_turma}\" (PIN: {$pin_jogo}) criada com sucesso! Foram geradas {$qtd_equipes} equipes com acesso independente.");
                    header('Location: instrutor.php?jogo_id=' . $novo_jogo_id);
                    exit;
                } catch (Throwable $e) {
                    flash('erro', 'Falha ao inicializar a turma: ' . $e->getMessage());
                }
            }
        }
        header('Location: instrutor.php');
        exit;
    }

    // ----------------------------------------------------
    // AÇÃO 2: SELECIONAR TURMA ATIVA PARA VISUALIZAÇÃO
    // ----------------------------------------------------
    if ($acao === 'selecionar_jogo') {
        $jogo_id = (int)($_POST['jogo_id'] ?? 0);
        if ($jogo_id > 0) {
            if (selecionarJogoAtivo($pdo, $jogo_id)) {
                flash('sucesso', 'Turma ativa alterada com sucesso.');
            } else {
                flash('erro', 'Não foi possível selecionar esta turma ou ela não pertence ao seu perfil.');
            }
        }
        header('Location: instrutor.php');
        exit;
    }

    // ----------------------------------------------------
    // AÇÃO 3: ALTERAR STATUS DA TURMA (ATIVO / PAUSADO / ENCERRADO)
    // ----------------------------------------------------
    if ($acao === 'alternar_status_jogo') {
        $jogo_id     = (int)($_POST['jogo_id'] ?? 0);
        $novo_status = trim((string)($_POST['novo_status'] ?? 'ativo'));

        if ($jogo_id > 0 && in_array($novo_status, ['ativo', 'pausado', 'encerrado'], true)) {
            // Verifica permissão do instrutor sobre este jogo
            $stmt_chk = $pdo->prepare("SELECT id FROM jogos WHERE id = ? AND (instrutor_id = ? OR ? = 1)");
            $stmt_chk->execute([$jogo_id, $instrutor_id, $instrutor_id]);
            if ($stmt_chk->fetch()) {
                alternarStatusJogo($pdo, $jogo_id, $novo_status);
                flash('sucesso', "O status da turma foi atualizado para \"" . strtoupper($novo_status) . "\".");
            } else {
                flash('erro', 'Permissão negada para alterar esta turma.');
            }
        }
        header('Location: instrutor.php');
        exit;
    }

    // ----------------------------------------------------
    // AÇÃO 4: REINICIAR SOMENTE ESTA TURMA ESPECÍFICA
    // ----------------------------------------------------
    if ($acao === 'reiniciar_jogo') {
        $jogo_id   = (int)($_POST['jogo_id'] ?? 0);
        $confirmar = !empty($_POST['confirmar_reset']);

        if (!$confirmar || $jogo_id <= 0) {
            flash('erro', 'É obrigatório marcar a caixa de confirmação para reiniciar a turma.');
        } else {
            $stmt_chk = $pdo->prepare("SELECT nome_turma, pin_jogo FROM jogos WHERE id = ? AND (instrutor_id = ? OR ? = 1)");
            $stmt_chk->execute([$jogo_id, $instrutor_id, $instrutor_id]);
            $jDados = $stmt_chk->fetch();

            if ($jDados) {
                if (reiniciarJogoEspecifico($pdo, $jogo_id)) {
                    flash('sucesso', "🔄 A turma \"{$jDados['nome_turma']}\" ({$jDados['pin_jogo']}) foi reiniciada do zero! As demais turmas do sistema não sofreram nenhuma alteração.");
                } else {
                    flash('erro', 'Ocorreu um erro interno ao tentar reiniciar os dados da turma.');
                }
            } else {
                flash('erro', 'Turma não localizada ou sem permissão de acesso.');
            }
        }
        header('Location: instrutor.php');
        exit;
    }

    // ----------------------------------------------------
    // AÇÃO 5: AVANÇAR RODADA DA TURMA SELECIONADA
    // ----------------------------------------------------
    if ($acao === 'avancar_rodada') {
        $jogo_id     = (int)($_POST['jogo_id'] ?? getJogoAtivoId($pdo));
        $evento_nome = trim((string)($_POST['evento_nome'] ?? 'Mercado Estável'));
        $evento_desc = trim((string)($_POST['evento_desc'] ?? 'Condições normais de mercado.'));

        if ($evento_nome === '') {
            $evento_nome = 'Mercado Estável';
        }
        if ($evento_desc === '') {
            $evento_desc = 'Condições normais de mercado.';
        }

        $resultado = fecharRodadaAtual($pdo, $evento_nome, $evento_desc, null, $jogo_id);

        if (!empty($resultado['success'])) {
            flash('sucesso', $resultado['message'] ?? 'Rodada encerrada com sucesso!');
        } else {
            flash('erro', $resultado['message'] ?? 'Erro desconhecido ao encerrar a rodada.');
        }
        header('Location: instrutor.php');
        exit;
    }
}

// ==============================================================================
// 2. CONSULTAS DE DADOS DO INSTRUTOR & TURMA SELECIONADA
// ==============================================================================

// Lista todas as turmas que pertencem a este instrutor
$minhas_turmas = jogosDoInstrutor($pdo, $instrutor_id);

// Identifica a turma ativa atual
$jogo_ativo_id = getJogoAtivoId($pdo);
$jogo_ativo    = jogoAtual($pdo, $jogo_ativo_id);

// Se a turma ativa na sessão não pertencer ao instrutor, assume a primeira dele
if (!$jogo_ativo && !empty($minhas_turmas)) {
    $jogo_ativo_id = (int)$minhas_turmas[0]['id'];
    selecionarJogoAtivo($pdo, $jogo_ativo_id);
    $jogo_ativo = $minhas_turmas[0];
}

// Rodada em andamento da turma ativa
$rodada_atual = $jogo_ativo_id > 0 ? getRodadaAtiva($pdo, $jogo_ativo_id) : null;

// Equipes pertencentes estritamente a esta turma
$equipes = [];
if ($jogo_ativo_id > 0) {
    $stmt_eq = $pdo->prepare("
        SELECT e.*,
               (SELECT COUNT(*) FROM ordens_producao WHERE equipe_id = e.id AND status = 'finalizada') AS ops_feitas,
               (SELECT COUNT(*) FROM ordens_producao WHERE equipe_id = e.id AND status IN ('planejada', 'em_producao')) AS ops_abertas,
               (SELECT COALESCE(SUM(quantidade_produzida), 0) FROM ordens_producao WHERE equipe_id = e.id AND status = 'finalizada') AS barras_feitas,
               (SELECT COALESCE(SUM(quantidade_refugo), 0) FROM ordens_producao WHERE equipe_id = e.id AND status = 'finalizada') AS barras_refugo
        FROM equipes e
        WHERE e.jogo_id = ?
        ORDER BY e.falida ASC, e.score_total DESC, e.id ASC
    ");
    $stmt_eq->execute([$jogo_ativo_id]);
    $equipes = $stmt_eq->fetchAll() ?: [];
}

// Métricas financeiras e de saúde da turma
$total_caixa_turma = 0.0;
$total_barras_turma = 0;
$total_refugo_turma = 0;
$qtd_saudaveis     = 0;
$qtd_em_risco      = 0;
$qtd_falidas       = 0;

foreach ($equipes as $eqX) {
    $total_caixa_turma  += (float)$eqX['caixa_disponivel'];
    $total_barras_turma += (int)$eqX['barras_feitas'];
    $total_refugo_turma += (int)$eqX['barras_refugo'];

    if ((int)$eqX['falida'] === 1) {
        $qtd_falidas++;
    } elseif ((int)($eqX['rodadas_no_vermelho'] ?? 0) > 0 || (float)$eqX['caixa_disponivel'] < 0) {
        $qtd_em_risco++;
    } else {
        $qtd_saudaveis++;
    }
}

$taxa_refugo_turma = ($total_barras_turma + $total_refugo_turma) > 0 
    ? round(($total_refugo_turma / ($total_barras_turma + $total_refugo_turma)) * 100, 2)
    : 0.0;

// Mensagens flash do sistema
$mensagem_sucesso = lerFlash('sucesso');
$mensagem_erro    = lerFlash('erro');

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="container-fluid px-4 pb-5">

    <!-- ========================================================= -->
    <!-- CABEÇALHO DO PAINEL & SELETOR DE TURMA                     -->
    <!-- ========================================================= -->
    <div class="card card-custom bg-white p-4 mb-4 shadow-sm border-start border-4 border-primary">
        <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 font-monospace">
                        <i class="bi bi-person-workspace me-1"></i>Painel do Professor
                    </span>
                    <span class="badge bg-light text-dark border">
                        <i class="bi bi-mortarboard me-1"></i><?= count($minhas_turmas) ?> Turma(s) Ativa(s)
                    </span>
                </div>
                <h2 class="fw-bold text-dark my-1">
                    <i class="bi bi-controller text-primary me-2"></i>Gestão de Turmas & Simulação PCP
                </h2>
                <p class="text-muted mb-0 small">
                    Administre o avanço das rodadas, crie novos cenários de demanda e forneça os códigos de acesso aos grupos.
                </p>
            </div>

            <!-- Seletor de Turma & Ações Principais -->
            <div class="d-flex flex-wrap align-items-center gap-2">
                <?php if (!empty($minhas_turmas)): ?>
                    <form method="POST" action="instrutor.php" class="d-inline-flex align-items-center gap-2 bg-light p-2 rounded-3 border">
                        <input type="hidden" name="acao" value="selecionar_jogo">
                        <label class="fw-semibold text-muted small mb-0"><i class="bi bi-mortarboard-fill text-primary me-1"></i>Turma:</label>
                        <select name="jogo_id" class="form-select form-select-sm fw-bold border-primary shadow-sm"
                                onchange="this.form.submit()" style="min-width: 250px;">
                            <?php foreach ($minhas_turmas as $tj): ?>
                                <option value="<?= (int)$tj['id'] ?>" <?= (int)$tj['id'] === $jogo_ativo_id ? 'selected' : '' ?>>
                                    [<?= htmlspecialchars((string)$tj['pin_jogo']) ?>] <?= htmlspecialchars((string)$tj['nome_turma']) ?>
                                    (<?= ucfirst((string)$tj['status']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php endif; ?>

                <button class="btn btn-success fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalNovoJogo">
                    <i class="bi bi-plus-circle me-1"></i> Nova Turma / Jogo
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- MENSAGENS FLASH                                            -->
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
    <!-- CASO O INSTRUTOR AINDA NÃO TENHA NENHUM JOGO CADASTRADO   -->
    <!-- ========================================================= -->
    <?php if (empty($minhas_turmas)): ?>
        <div class="card card-custom bg-white p-5 text-center my-4 shadow-sm border-2 border-dashed border-success">
            <div class="display-3 text-success mb-3">
                <i class="bi bi-flower1"></i>
            </div>
            <h3 class="fw-bold text-dark mb-2">Bem-vindo ao Flora Sabão PCP Lab!</h3>
            <p class="text-muted mx-auto mb-4" style="max-width: 650px;">
                Você ainda não tem nenhuma turma criada. Crie uma turma agora para gerar automaticamente
                as equipes, fórmulas da BOM, matérias-primas e o código de acesso para os alunos jogarem
                em uma linha de montagem totalmente isolada.
            </p>
            <div>
                <button class="btn btn-success btn-lg fw-bold shadow-sm px-4 py-2" data-bs-toggle="modal" data-bs-target="#modalNovoJogo">
                    <i class="bi bi-plus-circle me-2"></i> Criar Minha Primeira Turma
                </button>
            </div>
        </div>
    <?php else: ?>

        <!-- ========================================================= -->
        <!-- SUB-BARRA DE STATUS DA TURMA SELECIONADA                  -->
        <!-- ========================================================= -->
        <?php if ($jogo_ativo): ?>
            <div class="card card-custom bg-white p-3 mb-4 shadow-sm border-top border-4 border-<?= $jogo_ativo['status'] === 'ativo' ? 'success' : 'secondary' ?>">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="fs-5 fw-bold text-dark">
                            <?= htmlspecialchars((string)$jogo_ativo['nome_turma']) ?>
                        </span>
                        <span class="badge bg-primary font-monospace px-2 py-1 fs-6">
                            PIN: <?= htmlspecialchars((string)$jogo_ativo['pin_jogo']) ?>
                        </span>
                        <span class="badge bg-<?= $jogo_ativo['status'] === 'ativo' ? 'success' : ($jogo_ativo['status'] === 'pausado' ? 'warning text-dark' : 'danger') ?> text-uppercase">
                            <i class="bi bi-circle-fill me-1 small"></i><?= htmlspecialchars((string)$jogo_ativo['status']) ?>
                        </span>
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-sm btn-outline-primary fw-semibold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalCodigosAcesso">
                            <i class="bi bi-key-fill me-1"></i> Códigos dos Alunos
                        </button>
                        <a href="ranking.php?jogo_id=<?= $jogo_ativo_id ?>" target="_blank" class="btn btn-sm btn-outline-warning text-dark fw-bold shadow-sm">
                            <i class="bi bi-tv me-1"></i> Abrir Telão
                        </a>
                        <button class="btn btn-sm btn-outline-secondary fw-semibold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalGerenciarTurma">
                            <i class="bi bi-gear-fill me-1"></i> Configurar
                        </button>
                        <button class="btn btn-sm btn-danger fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalAvancar">
                            <i class="bi bi-fast-forward-fill me-1"></i> Fechar Rodada
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- ========================================================= -->
        <!-- 4 CARDS DE RESUMO DA TURMA SELECIONADA                     -->
        <!-- ========================================================= -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <div class="card card-custom p-3 bg-white h-100 shadow-sm border-start border-4 border-info">
                    <span class="text-muted small text-uppercase fw-semibold">Caixa Total da Turma</span>
                    <h3 class="fw-bold my-1 <?= $total_caixa_turma < 0 ? 'text-danger' : 'text-success' ?>">
                        R$ <?= number_format($total_caixa_turma, 2, ',', '.') ?>
                    </h3>
                    <small class="text-muted">Somatório das <?= count($equipes) ?> equipes desta turma</small>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card card-custom p-3 bg-white h-100 shadow-sm border-start border-4 border-success">
                    <span class="text-muted small text-uppercase fw-semibold">Equipes em Conformidade</span>
                    <h3 class="fw-bold my-1 text-success"><?= $qtd_saudaveis ?></h3>
                    <small class="text-muted">Operação saudável com caixa positivo</small>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card card-custom p-3 bg-white h-100 shadow-sm border-start border-4 border-warning">
                    <span class="text-muted small text-uppercase fw-semibold">Equipes em Risco</span>
                    <h3 class="fw-bold my-1 text-warning-emphasis"><?= $qtd_em_risco ?></h3>
                    <small class="text-muted">Caixa negativo (1 rodada no vermelho)</small>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card card-custom p-3 bg-white h-100 shadow-sm border-start border-4 border-danger">
                    <span class="text-muted small text-uppercase fw-semibold">Equipes Falidas</span>
                    <h3 class="fw-bold my-1 text-danger"><?= $qtd_falidas ?></h3>
                    <small class="text-muted">Fora da rodada por insolvência financeira</small>
                </div>
            </div>
        </div>

        <!-- ========================================================= -->
        <!-- STATUS DA RODADA EM ANDAMENTO DA TURMA                     -->
        <!-- ========================================================= -->
        <?php if ($rodada_atual): ?>
            <div class="card card-custom bg-white p-3 mb-4 border-start border-4 border-danger shadow-sm">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge bg-danger text-white text-uppercase px-2 py-1 font-monospace">
                                Rodada <?= (int)$rodada_atual['numero_rodada'] ?>
                            </span>
                            <span class="badge bg-light text-dark border">
                                <?= htmlspecialchars((string)$rodada_atual['mes_ano']) ?>
                            </span>
                            <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
                                Fator Demanda S&OP: <?= number_format((float)$rodada_atual['fator_demanda'], 2, ',', '.') ?>×
                            </span>
                        </div>
                        <h4 class="fw-bold text-dark my-1">
                            <?= htmlspecialchars((string)$rodada_atual['evento_titulo']) ?>
                        </h4>
                        <div class="small text-muted">
                            <i class="bi bi-info-circle me-1"></i>
                            <?= htmlspecialchars((string)($rodada_atual['evento_descricao'] ?? 'Condições regulares de mercado.')) ?>
                        </div>
                    </div>
                    <div>
                        <button class="btn btn-danger fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalAvancar">
                            <i class="bi bi-fast-forward me-1"></i> Encerrar Rodada <?= (int)$rodada_atual['numero_rodada'] ?>
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- ========================================================= -->
        <!-- TABELA DE DESEMPENHO DAS EQUIPES DESTA TURMA               -->
        <!-- ========================================================= -->
        <div class="card card-custom bg-white p-4 shadow-sm mb-4">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
                <div>
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-people-fill text-primary me-2"></i>Classificação das Equipes na Turma
                    </h5>
                    <small class="text-muted">Acompanhe pontuação acumulada, caixa em tempo real e ordens em andamento.</small>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                        <i class="bi bi-printer me-1"></i> Imprimir
                    </button>
                    <span class="badge bg-light text-dark border d-flex align-items-center">
                        Total: <?= count($equipes) ?> equipe(s)
                    </span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light small text-uppercase">
                        <tr>
                            <th style="width: 70px;">Posição</th>
                            <th>Equipe</th>
                            <th>Código de Acesso do Aluno</th>
                            <th class="text-center">OPs Prontas</th>
                            <th class="text-center">OPs em Fila</th>
                            <th>Caixa Líquido</th>
                            <th>Score PCP</th>
                            <th>Estado Operacional</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($equipes)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-4 text-muted">
                                    Nenhuma equipe cadastrada para esta turma.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($equipes as $idx => $eq):
                                $is_falida   = (int)$eq['falida'] === 1;
                                $rodadas_neg = (int)($eq['rodadas_no_vermelho'] ?? 0);
                                $caixa_neg   = (float)$eq['caixa_disponivel'] < 0;

                                $row_class = '';
                                if ($is_falida) {
                                    $row_class = 'table-danger';
                                } elseif ($rodadas_neg > 0 || $caixa_neg) {
                                    $row_class = 'table-warning';
                                }
                            ?>
                                <tr class="<?= $row_class ?>">
                                    <td class="fw-bold fs-5 text-center">
                                        <?= $is_falida ? '<span class="text-muted">—</span>' : ($idx + 1) . 'º' ?>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge" style="background-color: <?= htmlspecialchars((string)$eq['cor_hex']) ?>; width: 14px; height: 14px; border-radius: 50%;">
                                                &nbsp;
                                            </span>
                                            <div>
                                                <strong><?= htmlspecialchars((string)$eq['nome']) ?></strong>
                                                <small class="text-muted d-block font-monospace">ID #<?= (int)$eq['id'] ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <code class="fw-bold text-success user-select-all fs-6">
                                            <?= htmlspecialchars((string)$eq['codigo_acesso']) ?>
                                        </code>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-success-subtle text-success border border-success-subtle">
                                            <?= (int)$eq['ops_feitas'] ?> finalizadas
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                                            <?= (int)$eq['ops_abertas'] ?> ativas
                                        </span>
                                    </td>
                                    <td class="<?= $caixa_neg ? 'text-danger fw-bold' : 'text-dark fw-semibold' ?>">
                                        R$ <?= number_format((float)$eq['caixa_disponivel'], 2, ',', '.') ?>
                                    </td>
                                    <td class="fw-bold fs-5 text-primary">
                                        <?= (int)$eq['score_total'] ?> <small class="fs-6 text-muted">pts</small>
                                    </td>
                                    <td>
                                        <?php if ($is_falida): ?>
                                            <span class="badge bg-danger"><i class="bi bi-x-octagon-fill me-1"></i>FALIDA</span>
                                        <?php elseif ($rodadas_neg > 0): ?>
                                            <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= $rodadas_neg ?>/2 no vermelho</span>
                                        <?php elseif ($caixa_neg): ?>
                                            <span class="badge bg-warning text-dark">Caixa Negativo</span>
                                        <?php else: ?>
                                            <span class="badge bg-success"><i class="bi bi-check-circle-fill me-1"></i>Saudável</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a href="../index.php?equipe_id=<?= (int)$eq['id'] ?>&jogo_id=<?= $jogo_ativo_id ?>"
                                           class="btn btn-sm btn-outline-primary fw-semibold" title="Inspecionar Fábrica desta Equipe">
                                            <i class="bi bi-eye me-1"></i>Inspecionar
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Legenda didática do sistema financeiro -->
            <div class="border-top mt-3 pt-3 small text-muted">
                <strong><i class="bi bi-info-circle me-1"></i>Regras de Avaliação Financeira da Turma:</strong>
                <ul class="mb-0 mt-1 ps-3">
                    <li><span class="badge bg-success">Saudável</span> — Caixa positivo ao final do mês, apto a comprar matérias-primas e programar novos lotes.</li>
                    <li><span class="badge bg-warning text-dark">1/2 no vermelho</span> — A equipe encerrou a rodada com saldo devedor. Se mantiver o caixa negativo na próxima, entrará em recuperação judicial ou falência.</li>
                    <li><span class="badge bg-danger">FALIDA</span> — Caixa negativo em 2 rodadas consecutivas. A fábrica é interditada e a equipe para de produzir.</li>
                </ul>
            </div>
        </div>

    <?php endif; ?>

</div>

<!-- ============================================================ -->
<!-- MODAL: CRIAR NOVO JOGO / NOVA TURMA                           -->
<!-- ============================================================ -->
<div class="modal fade" id="modalNovoJogo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="instrutor.php" class="modal-content shadow-lg border-0">
            <input type="hidden" name="acao" value="criar_jogo">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-plus-circle me-2"></i>Criar Nova Turma / Jogo
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">
                    Cada turma criada opera em um ambiente <strong>100% isolado</strong>. O sistema gerará as equipes,
                    códigos de acesso dos alunos, rodada 1 e todo o catálogo padrão de insumos e produtos automaticamente.
                </p>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Nome da Turma / Disciplina:</label>
                    <input type="text" name="nome_turma" class="form-control"
                           placeholder="Ex: Engenharia de Produção - Turma Noite" required>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold">Sigla / PIN da Turma:</label>
                        <input type="text" name="pin_jogo" class="form-control font-monospace text-uppercase"
                               placeholder="Ex: ENG26" maxlength="10" required>
                        <small class="text-muted">Aparece nos códigos dos alunos.</small>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Qtd. de Equipes:</label>
                        <select name="qtd_equipes" class="form-select">
                            <option value="2">2 Equipes</option>
                            <option value="3">3 Equipes</option>
                            <option value="4">4 Equipes</option>
                            <option value="5">5 Equipes</option>
                            <option value="6" selected>6 Equipes (Alfa a Ômega)</option>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Caixa Inicial por Equipe (R$):</label>
                    <input type="text" name="caixa_inicial" class="form-control" value="15000,00" required>
                    <small class="text-muted">Capital de giro disponível para compra de insumos na rodada 1.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success fw-bold">
                    <i class="bi bi-check2-circle me-1"></i> Criar Turma Agora
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: CÓDIGOS DE ACESSO DOS ALUNOS                           -->
<!-- ============================================================ -->
<?php if ($jogo_ativo && !empty($equipes)): ?>
<div class="modal fade" id="modalCodigosAcesso" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-key-fill me-2"></i>Códigos de Acesso das Equipes — <?= htmlspecialchars((string)$jogo_ativo['nome_turma']) ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 small mb-3">
                    <i class="bi bi-info-circle-fill me-1"></i>
                    Entregue a cada grupo apenas o código da sua respectiva equipe. O aluno entra na tela de login,
                    digita o código e já acessa a fábrica da sua equipe nesta turma.
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle mb-0">
                        <thead class="table-light small text-uppercase">
                            <tr>
                                <th>Equipe</th>
                                <th>Código Único de Acesso</th>
                                <th class="text-center" style="width: 120px;">Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($equipes as $eq): ?>
                                <tr>
                                    <td>
                                        <span class="badge me-1" style="background-color: <?= htmlspecialchars((string)$eq['cor_hex']) ?>;">
                                            &nbsp;
                                        </span>
                                        <strong><?= htmlspecialchars((string)$eq['nome']) ?></strong>
                                    </td>
                                    <td>
                                        <input type="text" readonly
                                               class="form-control form-control-sm font-monospace fw-bold text-success bg-light"
                                               value="<?= htmlspecialchars((string)$eq['codigo_acesso']) ?>"
                                               id="cod_<?= (int)$eq['id'] ?>">
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                onclick="navigator.clipboard.writeText(document.getElementById('cod_<?= (int)$eq['id'] ?>').value); this.innerText='Copiado!'; setTimeout(() => this.innerText='Copiar', 2000);">
                                            Copiar
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: CONFIGURAR TURMA (PAUSAR / RESETAR)                    -->
<!-- ============================================================ -->
<div class="modal fade" id="modalGerenciarTurma" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-dark">
                    <i class="bi bi-gear-fill me-2"></i>Gerenciar Turma: <?= htmlspecialchars((string)$jogo_ativo['nome_turma']) ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Status da Turma -->
                <h6 class="fw-bold mb-2">Status da Partida:</h6>
                <form method="POST" action="instrutor.php" class="d-flex gap-2 mb-4">
                    <input type="hidden" name="acao" value="alternar_status_jogo">
                    <input type="hidden" name="jogo_id" value="<?= $jogo_ativo_id ?>">
                    <select name="novo_status" class="form-select form-select-sm">
                        <option value="ativo" <?= $jogo_ativo['status'] === 'ativo' ? 'selected' : '' ?>>🟢 Ativo (Alunos podem jogar)</option>
                        <option value="pausado" <?= $jogo_ativo['status'] === 'pausado' ? 'selected' : '' ?>>🟡 Pausado (Acesso temporariamente suspenso)</option>
                        <option value="encerrado" <?= $jogo_ativo['status'] === 'encerrado' ? 'selected' : '' ?>>🔴 Encerrado (Fim do semestre / competição)</option>
                    </select>
                    <button type="submit" class="btn btn-sm btn-dark">Salvar</button>
                </form>

                <hr>

                <!-- Reiniciar Apenas Esta Turma -->
                <h6 class="fw-bold text-danger mb-1">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>Reiniciar Esta Turma
                </h6>
                <p class="small text-muted mb-2">
                    Esta ação zera o histórico, apaga as OPs e reinicia a turma na <strong>Rodada 1</strong> com caixa de R$ 15.000,00.
                    <strong>As outras turmas do sistema não serão afetadas.</strong>
                </p>

                <form method="POST" action="instrutor.php"
                      onsubmit="return confirm('Deseja realmente reiniciar esta turma? Todas as OPs desta turma serão apagadas.');">
                    <input type="hidden" name="acao" value="reiniciar_jogo">
                    <input type="hidden" name="jogo_id" value="<?= $jogo_ativo_id ?>">

                    <div class="form-check mb-3 small">
                        <input class="form-check-input" type="checkbox" name="confirmar_reset" id="chkResetTurma" required>
                        <label class="form-check-label text-danger fw-semibold" for="chkResetTurma">
                            Confirmo que desejo reiniciar somente esta turma
                        </label>
                    </div>

                    <button type="submit" class="btn btn-danger btn-sm w-100 fw-bold">
                        <i class="bi bi-arrow-clockwise me-1"></i> Reiniciar Turma <?= htmlspecialchars((string)$jogo_ativo['pin_jogo']) ?>
                    </button>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: AVANÇAR RODADA DA TURMA ATIVA                          -->
<!-- ============================================================ -->
<div class="modal fade" id="modalAvancar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="instrutor.php" class="modal-content">
            <input type="hidden" name="acao" value="avancar_rodada">
            <input type="hidden" name="jogo_id" value="<?= $jogo_ativo_id ?>">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-fast-forward me-2"></i>Avançar Rodada — <?= htmlspecialchars((string)$jogo_ativo['nome_turma']) ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning small mb-3">
                    Ao confirmar, o sistema vai executar para as equipes desta turma:
                    <ol class="mb-0 mt-1 ps-3">
                        <li>Venda automática do estoque até o limite da meta de cada fragrância</li>
                        <li>Crédito das receitas no caixa</li>
                        <li>Apuração de OTIF, Aderência ao Plano, Refugo e Penalidades de Setup</li>
                        <li>Avaliação de falência (caixa negativo por 2 rodadas consecutivas)</li>
                        <li>Abertura da próxima rodada com o evento econômico selecionado</li>
                    </ol>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Cenário / Evento da Próxima Rodada:</label>
                    <select name="evento_nome" id="selectEvento" class="form-select" onchange="atualizarDescricaoEvento()" required>
                        <option value="Mercado Estável">Mercado Estável (sem distorções)</option>
                        <option value="Boom de Lavanda">Boom de Demanda: Lavanda Francesa em alta (+60%)</option>
                        <option value="Pico Natalino">Pico de Fim de Ano: Demanda geral explosiva (+80%)</option>
                        <option value="Atraso do Fornecedor">Crise de Insumos: Escassez de Base Glicerinada (+10%)</option>
                        <option value="Fiscalização BPF">Auditoria Sanitária: Tolerância zero para contaminação</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Mensagem / Briefing aos Alunos:</label>
                    <textarea name="evento_desc" id="campoDescEvento" class="form-control" rows="3">Mercado opera sob condições normais de abastecimento.</textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-danger fw-bold">
                    <i class="bi bi-check2-circle me-1"></i> Fechar Rodada Desta Turma
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function atualizarDescricaoEvento() {
    const sel  = document.getElementById('selectEvento').value;
    const desc = document.getElementById('campoDescEvento');
    const mapa = {
        'Mercado Estável':      'Mercado opera sob condições normais de abastecimento.',
        'Boom de Lavanda':      'Tendência viral fez a procura por Lavanda crescer 60%! Quem não tiver estoque perderá vendas no OTIF.',
        'Pico Natalino':        'Fim de ano! Todas as fragrâncias em alta. O gargalo de moldagem será o desafio da rodada.',
        'Atraso do Fornecedor': 'Greve nas distribuidoras atrasou entregas de base glicerinada. Compras serão mais caras.',
        'Fiscalização BPF':     'Vigilância Sanitária no chão de fábrica! Trocas de fragrância sem sanitização pesada serão multadas em dobro.'
    };
    desc.value = mapa[sel] || '';
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>