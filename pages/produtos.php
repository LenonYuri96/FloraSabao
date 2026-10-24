<?php
declare(strict_types=1);

/**
 * Flora Sabão — Produtos & Ficha Técnica (BOM)
 * ---------------------------------------------------------
 * Modo Híbrido:
 *  - Aluno (pcp)    : catálogo oficial, somente leitura
 *  - Instrutor (admin): edição completa (CRUD de produtos e BOM)
 *
 * Compatível com schema do Business Game:
 *  - ficha_tecnica.equipe_id (NOT NULL)
 *  - produtos.equipe_id (NOT NULL)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';

$page_title = 'Produtos & Ficha Técnica (BOM)';
$mensagem_sucesso = '';
$mensagem_erro    = '';

// ---------------------------------------------------------
// 🛡️ GUARD — precisa estar vinculado a uma equipe
// ---------------------------------------------------------
$equipe_id = getEquipeAtivaId();
if ($equipe_id <= 0) {
    flash('erro', 'Sua conta não está vinculada a uma equipe. Contate o instrutor.');
    header('Location: ' . (file_exists('pages') ? '' : '../') . 'index.php');
    exit;
}

$equipe   = equipeAtual($pdo);
$isAdmin  = isAdmin();

// ========================================================
// PROCESSAMENTO DE FORMULÁRIOS (ADMIN APENAS)
// ========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 🚧 Aluno não pode alterar nada — descarta qualquer POST "estranho"
    if (!$isAdmin) {
        flash('erro', 'Somente o instrutor pode alterar o catálogo de produtos.');
        header('Location: produtos.php');
        exit;
    }

    $acao = $_POST['acao'] ?? '';

    // ----------------------------------------------------
    // 1. Cadastrar Novo Produto
    // ----------------------------------------------------
    if ($acao === 'salvar_produto') {
        $codigo       = strtoupper(trim($_POST['codigo'] ?? ''));
        $nome         = trim($_POST['nome'] ?? '');
        $aroma        = trim($_POST['aroma'] ?? '');
        $intensidade  = $_POST['intensidade_aroma'] ?? 'moderado';
        $peso_g       = (float) ($_POST['peso_barra_g'] ?? 100);
        $estoque_min  = (int) ($_POST['estoque_minimo'] ?? 50);
        $preco_venda  = (float) str_replace(',', '.', (string)($_POST['preco_venda'] ?? '0'));

        if (empty($codigo) || empty($nome) || $preco_venda <= 0) {
            $mensagem_erro = 'Preencha código, nome e preço de venda válido.';
        } elseif (!in_array($intensidade, ['suave','moderado','intenso'], true)) {
            $mensagem_erro = 'Intensidade do aroma inválida.';
        } elseif ($peso_g <= 0 || $estoque_min < 0) {
            $mensagem_erro = 'Peso e estoque mínimo devem ser positivos.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO produtos
                        (equipe_id, codigo, nome, aroma, peso_barra_g, intensidade_aroma,
                         estoque_minimo, preco_venda)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $equipe_id, $codigo, $nome, $aroma, $peso_g,
                    $intensidade, $estoque_min, $preco_venda
                ]);
                $mensagem_sucesso = "Produto <strong>{$codigo}</strong> cadastrado com sucesso!";
            } catch (PDOException $e) {
                $mensagem_erro = ($e->getCode() == 23000)
                    ? 'Já existe um produto com este código nesta equipe.'
                    : 'Erro: ' . $e->getMessage();
            }
        }
    }

    // ----------------------------------------------------
    // 2. Adicionar Insumo na Ficha Técnica (BOM)
    // ----------------------------------------------------
    if ($acao === 'adicionar_insumo_bom') {
        $produto_id = (int) ($_POST['produto_id'] ?? 0);
        $insumo_id  = (int) ($_POST['insumo_id'] ?? 0);
        $qtd        = (float) str_replace(',', '.', (string)($_POST['qtd_por_barra'] ?? '0'));

        if ($produto_id <= 0 || $insumo_id <= 0 || $qtd <= 0) {
            $mensagem_erro = 'Informe o produto, insumo e quantidade por barra válida.';
        } else {
            // Confirma que produto e insumo pertencem à equipe
            $chkP = $pdo->prepare("SELECT id FROM produtos WHERE id = ? AND equipe_id = ?");
            $chkP->execute([$produto_id, $equipe_id]);
            $chkI = $pdo->prepare("SELECT id FROM insumos WHERE id = ? AND equipe_id = ?");
            $chkI->execute([$insumo_id, $equipe_id]);

            if (!$chkP->fetch() || !$chkI->fetch()) {
                $mensagem_erro = 'Produto ou insumo inválido para a sua equipe.';
            } else {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO ficha_tecnica (equipe_id, produto_id, insumo_id, qtd_por_barra)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE qtd_por_barra = VALUES(qtd_por_barra)
                    ");
                    $stmt->execute([$equipe_id, $produto_id, $insumo_id, $qtd]);
                    $mensagem_sucesso = 'Ficha Técnica atualizada com sucesso!';
                } catch (PDOException $e) {
                    $mensagem_erro = 'Erro ao atualizar BOM: ' . $e->getMessage();
                }
            }
        }
    }

    // ----------------------------------------------------
    // 3. Remover Insumo da Ficha Técnica
    // ----------------------------------------------------
    if ($acao === 'remover_insumo_bom') {
        $bom_id = (int) ($_POST['bom_id'] ?? 0);
        if ($bom_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM ficha_tecnica WHERE id = ? AND equipe_id = ?");
            $stmt->execute([$bom_id, $equipe_id]);
            $mensagem_sucesso = $stmt->rowCount() > 0
                ? 'Insumo removido da Ficha Técnica.'
                : 'Nenhum registro foi removido (já não existia).';
        }
    }

    // ----------------------------------------------------
    // 4. Ativar / Desativar Produto
    // ----------------------------------------------------
    if ($acao === 'toggle_produto') {
        $produto_id = (int) ($_POST['produto_id'] ?? 0);
        if ($produto_id > 0) {
            $pdo->prepare("
                UPDATE produtos SET ativo = 1 - ativo
                WHERE id = ? AND equipe_id = ?
            ")->execute([$produto_id, $equipe_id]);
            $mensagem_sucesso = 'Status do produto atualizado.';
        }
    }
}

// ========================================================
// CONSULTAS
// ========================================================

// 1. Insumos da equipe (para o modal de BOM — só admin usa)
$stmt_ins = $pdo->prepare("
    SELECT id, codigo, nome, unidade, custo_unitario
    FROM insumos
    WHERE equipe_id = ?
    ORDER BY nome ASC
");
$stmt_ins->execute([$equipe_id]);
$insumos_disponiveis = $stmt_ins->fetchAll();

// 2. Produtos da equipe com custo de MP calculado
//    Ordem oficial da Regra de Ouro: MAR → PIT → LAV → ROS
$stmt_prods = $pdo->prepare("
    SELECT p.*,
           COALESCE(SUM(ft.qtd_por_barra * i.custo_unitario), 0) AS custo_mp_barra,
           COUNT(ft.id) AS total_itens_bom
    FROM produtos p
    LEFT JOIN ficha_tecnica ft
           ON p.id = ft.produto_id AND ft.equipe_id = p.equipe_id
    LEFT JOIN insumos i ON ft.insumo_id = i.id
    WHERE p.equipe_id = ?
    GROUP BY p.id
    ORDER BY
        CASE p.codigo
            WHEN 'MAR' THEN 1
            WHEN 'PIT' THEN 2
            WHEN 'LAV' THEN 3
            WHEN 'ROS' THEN 4
            ELSE 99
        END,
        p.ativo DESC,
        p.id ASC
");
$stmt_prods->execute([$equipe_id]);
$produtos = $stmt_prods->fetchAll();

// 3. BOM detalhada por produto
$stmt_bom = $pdo->prepare("
    SELECT ft.id AS bom_id, ft.produto_id, ft.qtd_por_barra,
           i.nome AS insumo_nome, i.codigo AS insumo_codigo,
           i.unidade, i.custo_unitario,
           (ft.qtd_por_barra * i.custo_unitario) AS subtotal_custo
    FROM ficha_tecnica ft
    JOIN insumos i ON ft.insumo_id = i.id
    WHERE ft.equipe_id = ?
    ORDER BY ft.produto_id, i.nome
");
$stmt_bom->execute([$equipe_id]);
$boms_raw = $stmt_bom->fetchAll();

$fichas_tecnicas = [];
foreach ($boms_raw as $item) {
    $fichas_tecnicas[$item['produto_id']][] = $item;
}

// 4. Métricas agregadas (para os cards de topo)
$total_produtos_ativos   = 0;
$total_custo_medio       = 0.0;
$total_margem_media      = 0.0;
$total_itens_bom         = 0;
$count_custo             = 0;

foreach ($produtos as $p) {
    if (!(int)$p['ativo']) continue;
    $total_produtos_ativos++;
    $total_itens_bom += (int)$p['total_itens_bom'];

    $custo  = (float)$p['custo_mp_barra'];
    $preco  = (float)$p['preco_venda'];
    if ($preco > 0) {
        $total_custo_medio += $custo;
        $total_margem_media += (($preco - $custo) / $preco) * 100;
        $count_custo++;
    }
}
$custo_medio   = $count_custo > 0 ? $total_custo_medio / $count_custo : 0;
$margem_media  = $count_custo > 0 ? $total_margem_media / $count_custo : 0;

// Mapa de "Regra de Ouro" para exibir a posição de cada fragrância
$sequencia_oficial = ORDEM_AROMAS_RECOMENDADA;

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="container-fluid px-4 pb-4">

    <!-- ====================================================== -->
    <!-- CABEÇALHO DA PÁGINA                                     -->
    <!-- ====================================================== -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h2 class="fw-bold mb-1 text-dark">
                <i class="bi bi-flower1 text-success me-2"></i>
                Catálogo de Produtos & Ficha Técnica (BOM)
            </h2>
            <p class="text-muted mb-0">
                Engenharia dos produtos oficiais da Flora Sabão:
                consumo unitário de insumos, custo padrão da barra e margens.
            </p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php if ($equipe): ?>
                <span class="badge bg-white text-dark border shadow-sm py-2 px-3">
                    <i class="bi bi-people-fill me-1"
                       style="color: <?= h($equipe['cor_hex'] ?? '#2d5a3f') ?>;"></i>
                    <?= h($equipe['nome']) ?>
                </span>
            <?php endif; ?>

            <?php if ($isAdmin): ?>
                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle py-2 px-3">
                    <i class="bi bi-shield-lock me-1"></i> Modo Edição (Instrutor)
                </span>
                <button class="btn btn-success shadow-sm"
                        data-bs-toggle="modal" data-bs-target="#modalNovoProduto">
                    <i class="bi bi-plus-circle me-1"></i> Nova Fragrância
                </button>
            <?php else: ?>
                <span class="badge bg-success-subtle text-success border border-success-subtle py-2 px-3">
                    <i class="bi bi-lock-fill me-1"></i> Catálogo Oficial (Somente Leitura)
                </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- ====================================================== -->
    <!-- ALERTAS FLASH                                          -->
    <!-- ====================================================== -->
    <?php if (!empty($mensagem_sucesso)): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> <?= $mensagem_sucesso ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($mensagem_erro)): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= h($mensagem_erro) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- ====================================================== -->
    <!-- BANNER PEDAGÓGICO — REGRA DE OURO                       -->
    <!-- ====================================================== -->
    <div class="card card-custom bg-white p-3 mb-4 border-start border-4 border-success shadow-sm">
        <div class="d-flex align-items-start gap-3">
            <div class="fs-1 text-success"><i class="bi bi-diagram-3"></i></div>
            <div class="flex-grow-1">
                <h6 class="fw-bold text-dark mb-1">
                    Engenharia Padronizada para a Regra de Ouro
                </h6>
                <p class="small text-muted mb-2">
                    A fábrica trabalha com 4 SKUs calibrados para permitir o
                    <strong>sequenciamento olfativo ótimo</strong>:
                    sempre do aroma mais suave ao mais intenso.
                    Isso evita contaminação cruzada e reduz o tempo de setup.
                </p>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <?php foreach ($sequencia_oficial as $cod => $ordem): ?>
                        <span class="badge bg-light text-dark border">
                            <strong><?= (int)$ordem ?>º</strong> <?= h($cod) ?>
                        </span>
                        <?php if ((int)$ordem < count($sequencia_oficial)): ?>
                            <i class="bi bi-arrow-right text-muted"></i>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ====================================================== -->
    <!-- CARDS DE RESUMO DO CATÁLOGO                             -->
    <!-- ====================================================== -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card card-custom p-3 bg-white h-100 shadow-sm">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Produtos Ativos</span>
                        <h3 class="fw-bold my-1 text-dark">
                            <?= $total_produtos_ativos ?>
                        </h3>
                        <small class="text-muted">Fragrâncias em linha</small>
                    </div>
                    <div class="bg-primary-subtle text-primary p-3 rounded-3">
                        <i class="bi bi-box2 fs-4"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="card card-custom p-3 bg-white h-100 shadow-sm">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Custo Médio / Barra</span>
                        <h3 class="fw-bold my-1 text-danger">
                            R$ <?= number_format($custo_medio, 2, ',', '.') ?>
                        </h3>
                        <small class="text-muted">Média dos SKUs ativos</small>
                    </div>
                    <div class="bg-danger-subtle text-danger p-3 rounded-3">
                        <i class="bi bi-cash-coin fs-4"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="card card-custom p-3 bg-white h-100 shadow-sm">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Margem Média</span>
                        <h3 class="fw-bold my-1 text-success">
                            <?= number_format($margem_media, 1, ',', '.') ?>%
                        </h3>
                        <small class="text-muted">Margem de contribuição</small>
                    </div>
                    <div class="bg-success-subtle text-success p-3 rounded-3">
                        <i class="bi bi-graph-up-arrow fs-4"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="card card-custom p-3 bg-white h-100 shadow-sm">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Itens de BOM</span>
                        <h3 class="fw-bold my-1 text-info">
                            <?= $total_itens_bom ?>
                        </h3>
                        <small class="text-muted">Componentes configurados</small>
                    </div>
                    <div class="bg-info-subtle text-info p-3 rounded-3">
                        <i class="bi bi-list-check fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ====================================================== -->
    <!-- GRID DE PRODUTOS                                        -->
    <!-- ====================================================== -->
    <?php if (empty($produtos)): ?>
        <div class="card card-custom bg-white p-5 text-center">
            <i class="bi bi-inbox fs-1 text-muted mb-3"></i>
            <h5 class="fw-bold text-dark">Nenhum produto cadastrado</h5>
            <p class="text-muted mb-3">
                O catálogo está vazio para esta equipe.
                <?php if ($isAdmin): ?>
                    Use o botão <strong>"Nova Fragrância"</strong> para começar.
                <?php else: ?>
                    Aguarde o instrutor liberar o catálogo.
                <?php endif; ?>
            </p>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($produtos as $p):
                $ativo       = (int)$p['ativo'] === 1;
                $custo_barra = (float) $p['custo_mp_barra'];
                $preco_venda = (float) $p['preco_venda'];
                $margem_bruta = $preco_venda - $custo_barra;
                $margem_perc  = $preco_venda > 0
                    ? round(($margem_bruta / $preco_venda) * 100, 1)
                    : 0;
                $bom_itens   = $fichas_tecnicas[$p['id']] ?? [];
                $posicao_ouro = $sequencia_oficial[$p['codigo']] ?? null;

                // Cor de borda pela intensidade (didático)
                $border_color = [
                    'suave'    => '#0dcaf0',
                    'moderado' => '#ffc107',
                    'intenso'  => '#dc3545',
                ][$p['intensidade_aroma']] ?? '#6c757d';
                ?>
                <div class="col-12 col-xl-6">
                    <div class="card card-custom bg-white h-100 p-3 shadow-sm <?= !$ativo ? 'opacity-75' : '' ?>"
                         style="border-left: 4px solid <?= $border_color ?>;">

                        <!-- Cabeçalho do Produto -->
                        <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3">
                            <div class="flex-grow-1">
                                <div class="d-flex flex-wrap align-items-center gap-1 mb-1">
                                    <span class="badge bg-secondary font-monospace">
                                        <?= h($p['codigo']) ?>
                                    </span>
                                    <span class="badge bg-light text-dark border">
                                        Intensidade: <?= ucfirst(h($p['intensidade_aroma'])) ?>
                                    </span>
                                    <?php if ($posicao_ouro): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle">
                                            <i class="bi bi-trophy-fill me-1"></i><?= (int)$posicao_ouro ?>º na Regra de Ouro
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!$ativo): ?>
                                        <span class="badge bg-danger">Inativo</span>
                                    <?php endif; ?>
                                </div>
                                <h4 class="fw-bold my-1 text-dark"><?= h($p['nome']) ?></h4>
                                <div class="text-muted small">
                                    <i class="bi bi-droplet me-1"></i><?= h($p['aroma']) ?>
                                    &bull; Peso: <?= (int)$p['peso_barra_g'] ?>g
                                    <?php if ((int)$p['total_itens_bom'] < 6): ?>
                                        &bull;
                                        <span class="text-warning fw-semibold">
                                            <i class="bi bi-exclamation-triangle me-1"></i>
                                            BOM incompleta (<?= (int)$p['total_itens_bom'] ?>/6)
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="text-end flex-shrink-0 ms-2">
                                <span class="fs-4 fw-bold text-success">
                                    R$ <?= number_format($preco_venda, 2, ',', '.') ?>
                                </span>
                                <div class="small text-muted">Preço de Venda</div>
                            </div>
                        </div>

                        <!-- Indicadores Financeiros -->
                        <div class="row text-center g-2 mb-3 bg-light p-2 rounded-3 border">
                            <div class="col-4 border-end">
                                <span class="small text-muted d-block">Custo MP / Barra</span>
                                <strong class="text-danger">
                                    R$ <?= number_format($custo_barra, 2, ',', '.') ?>
                                </strong>
                            </div>
                            <div class="col-4 border-end">
                                <span class="small text-muted d-block">Margem Bruta</span>
                                <strong class="text-success">
                                    R$ <?= number_format($margem_bruta, 2, ',', '.') ?>
                                </strong>
                            </div>
                            <div class="col-4">
                                <span class="small text-muted d-block">Margem %</span>
                                <strong class="<?= $margem_perc >= 50 ? 'text-success' : ($margem_perc >= 30 ? 'text-warning' : 'text-danger') ?>">
                                    <?= number_format($margem_perc, 1, ',', '.') ?>%
                                </strong>
                            </div>
                        </div>

                        <!-- Ficha Técnica -->
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="fw-bold text-uppercase text-muted mb-0 small">
                                <i class="bi bi-list-check me-1"></i>
                                Ficha Técnica (Consumo p/ 1 Barra)
                            </h6>
                            <?php if ($isAdmin && $ativo): ?>
                                <button class="btn btn-sm btn-outline-success"
                                        data-bs-toggle="modal" data-bs-target="#modalAddBom"
                                        data-prod-id="<?= (int)$p['id'] ?>"
                                        data-prod-nome="<?= h($p['nome']) ?>">
                                    <i class="bi bi-plus me-1"></i> Insumo
                                </button>
                            <?php endif; ?>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0"
                                   style="font-size: 0.88rem;">
                                <thead class="table-light text-muted">
                                    <tr>
                                        <th>Insumo</th>
                                        <th>Consumo Unit.</th>
                                        <th>Custo Unit.</th>
                                        <th class="<?= $isAdmin ? '' : 'text-end' ?>">Subtotal</th>
                                        <?php if ($isAdmin): ?>
                                            <th class="text-end" style="width: 45px;">Ação</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($bom_itens)): ?>
                                        <tr>
                                            <td colspan="<?= $isAdmin ? 5 : 4 ?>"
                                                class="text-center text-muted py-2 small">
                                                Nenhum insumo configurado na Ficha Técnica.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($bom_itens as $bom): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= h($bom['insumo_nome']) ?></strong>
                                                    <small class="text-muted font-monospace d-block">
                                                        <?= h($bom['insumo_codigo']) ?>
                                                    </small>
                                                </td>
                                                <td class="font-monospace">
                                                    <?= number_format((float)$bom['qtd_por_barra'], 4, ',', '.') ?>
                                                    <?= h($bom['unidade']) ?>
                                                </td>
                                                <td class="text-muted">
                                                    R$ <?= number_format((float)$bom['custo_unitario'], 2, ',', '.') ?>
                                                </td>
                                                <td class="fw-semibold <?= $isAdmin ? '' : 'text-end' ?>">
                                                    R$ <?= number_format((float)$bom['subtotal_custo'], 3, ',', '.') ?>
                                                </td>
                                                <?php if ($isAdmin): ?>
                                                    <td class="text-end">
                                                        <form method="POST" action="produtos.php"
                                                              class="d-inline"
                                                              onsubmit="return confirm('Remover este insumo da Ficha Técnica?');">
                                                            <input type="hidden" name="acao" value="remover_insumo_bom">
                                                            <input type="hidden" name="bom_id"
                                                                   value="<?= (int)$bom['bom_id'] ?>">
                                                            <button type="submit"
                                                                    class="btn btn-link text-danger p-0 border-0"
                                                                    title="Remover">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                                <?php if (!empty($bom_itens)): ?>
                                    <tfoot class="table-light">
                                        <tr>
                                            <td colspan="<?= $isAdmin ? 4 : 3 ?>" class="text-end fw-bold small">
                                                Custo Total de MP:
                                            </td>
                                            <td class="<?= $isAdmin ? 'text-end' : 'text-end' ?> fw-bold text-danger">
                                                R$ <?= number_format($custo_barra, 3, ',', '.') ?>
                                            </td>
                                            <?php if ($isAdmin): ?><td></td><?php endif; ?>
                                        </tr>
                                    </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>

                        <!-- Ações do Admin (ativar/desativar) -->
                        <?php if ($isAdmin): ?>
                            <div class="d-flex justify-content-end gap-2 mt-3 pt-2 border-top">
                                <form method="POST" action="produtos.php" class="d-inline"
                                      onsubmit="return confirm('Alterar o status deste produto?');">
                                    <input type="hidden" name="acao" value="toggle_produto">
                                    <input type="hidden" name="produto_id" value="<?= (int)$p['id'] ?>">
                                    <button type="submit"
                                            class="btn btn-sm <?= $ativo ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                        <i class="bi bi-<?= $ativo ? 'pause-circle' : 'play-circle' ?> me-1"></i>
                                        <?= $ativo ? 'Desativar' : 'Reativar' ?>
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- ====================================================== -->
    <!-- NOTA PEDAGÓGICA FINAL                                   -->
    <!-- ====================================================== -->
    <div class="card card-custom bg-white p-4 mt-4 shadow-sm border-start border-4 border-info">
        <h6 class="fw-bold text-dark mb-2">
            <i class="bi bi-mortarboard text-info me-2"></i>
            Por que a Ficha Técnica importa?
        </h6>
        <div class="row g-3">
            <div class="col-12 col-md-4">
                <div class="p-2 bg-light rounded-2 h-100 small text-muted">
                    <strong class="text-dark d-block mb-1">MRP I / Explosão</strong>
                    A BOM é a base do cálculo de necessidades: para cada lote planejado,
                    o sistema multiplica a quantidade por barra pelo total a produzir.
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="p-2 bg-light rounded-2 h-100 small text-muted">
                    <strong class="text-dark d-block mb-1">Custo Padrão</strong>
                    Conhecer o custo real por barra permite definir preço, calcular margem
                    e comparar com o preço de mercado.
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="p-2 bg-light rounded-2 h-100 small text-muted">
                    <strong class="text-dark d-block mb-1">Rastreabilidade</strong>
                    Cada insumo deve ter código e unidade padronizados. Assim, o Kardex
                    e a auditoria conseguem rastrear o consumo por OP.
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($isAdmin): ?>
<!-- ======================================================== -->
<!-- MODAL: NOVO PRODUTO (ADMIN)                              -->
<!-- ======================================================== -->
<div class="modal fade" id="modalNovoProduto" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="produtos.php" class="modal-content">
            <input type="hidden" name="acao" value="salvar_produto">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-flower1 text-success me-2"></i>Nova Fragrância
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <label class="form-label fw-semibold">Código:</label>
                        <input type="text" name="codigo" class="form-control"
                               placeholder="ROM" maxlength="10" required>
                    </div>
                    <div class="col-8">
                        <label class="form-label fw-semibold">Nome Comercial:</label>
                        <input type="text" name="nome" class="form-control"
                               placeholder="Sabonete Alecrim" required>
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold">Aroma:</label>
                        <input type="text" name="aroma" class="form-control"
                               placeholder="Alecrim Silvestre" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Intensidade:</label>
                        <select name="intensidade_aroma" class="form-select">
                            <option value="suave">Suave</option>
                            <option value="moderado" selected>Moderado</option>
                            <option value="intenso">Intenso</option>
                        </select>
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <label class="form-label fw-semibold">Peso (g):</label>
                        <input type="number" step="1" min="1" name="peso_barra_g"
                               class="form-control" value="100" required>
                    </div>
                    <div class="col-4">
                        <label class="form-label fw-semibold">Estoque Mín. (barras):</label>
                        <input type="number" step="1" min="0" name="estoque_minimo"
                               class="form-control" value="50" required>
                    </div>
                    <div class="col-4">
                        <label class="form-label fw-semibold">Preço Venda (R$):</label>
                        <input type="text" name="preco_venda" class="form-control"
                               placeholder="15,00" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check2 me-1"></i> Cadastrar Produto
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL: ADICIONAR INSUMO NA BOM (ADMIN)                   -->
<!-- ======================================================== -->
<div class="modal fade" id="modalAddBom" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="produtos.php" class="modal-content">
            <input type="hidden" name="acao" value="adicionar_insumo_bom">
            <input type="hidden" name="produto_id" id="modal_bom_produto_id" value="">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-plus-circle text-success me-2"></i>
                    Adicionar Insumo à Ficha Técnica
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">
                    Produto: <strong id="modal_bom_produto_nome" class="text-dark"></strong>
                </p>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Insumo:</label>
                    <select name="insumo_id" class="form-select" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($insumos_disponiveis as $ins): ?>
                            <option value="<?= (int)$ins['id'] ?>">
                                <?= h($ins['nome']) ?> (<?= h($ins['unidade']) ?>)
                                — R$ <?= number_format((float)$ins['custo_unitario'], 2, ',', '.') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Quantidade p/ 1 barra de 100g:</label>
                    <input type="number" step="0.0001" min="0.0001"
                           name="qtd_por_barra" class="form-control"
                           placeholder="0.0920" required>
                    <small class="text-muted">
                        Ex.: <code>0.0920</code> para 92g de base, <code>0.0040</code> para 4ml de essência.
                    </small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-save me-1"></i> Salvar na BOM
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalAddBom = document.getElementById('modalAddBom');
    if (!modalAddBom) return;

    modalAddBom.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        if (!button) return;
        document.getElementById('modal_bom_produto_id').value =
            button.getAttribute('data-prod-id');
        document.getElementById('modal_bom_produto_nome').textContent =
            button.getAttribute('data-prod-nome');
    });
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>