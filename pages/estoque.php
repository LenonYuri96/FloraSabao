<?php
declare(strict_types=1);

// 🔐 GUARDA DE AUTENTICAÇÃO — deve vir PRIMEIRO
require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';

$page_title = 'Gestão de Estoque e Insumos';

// Mensagens de feedback (Flash Messages)
$mensagem_sucesso = '';
$mensagem_erro = '';

// ========================================================
// PROCESSAMENTO DE FORMULÁRIOS (POST)
// ========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // 1. Entrada / Saída Manual de Insumo
    if ($acao === 'movimentar_insumo') {
        $insumo_id = (int) ($_POST['insumo_id'] ?? 0);
        $tipo = $_POST['tipo'] ?? ''; // 'entrada' ou 'saida'
        $quantidade = (float) str_replace(',', '.', $_POST['quantidade'] ?? '0');
        $motivo = trim($_POST['motivo'] ?? '');

        if ($insumo_id <= 0 || !in_array($tipo, ['entrada', 'saida'], true) || $quantidade <= 0) {
            $mensagem_erro = 'Informe um insumo válido, a operação e uma quantidade maior que zero.';
        } elseif (empty($motivo)) {
            $mensagem_erro = 'O campo motivo/justificativa é obrigatório para fins de rastreabilidade no PCP.';
        } else {
            try {
                $pdo->beginTransaction();

                // Busca insumo atual
                $stmt = $pdo->prepare("SELECT nome, estoque_atual, unidade FROM insumos WHERE id = ? FOR UPDATE");
                $stmt->execute([$insumo_id]);
                $insumo = $stmt->fetch();

                if (!$insumo) {
                    throw new Exception('Insumo não localizado.');
                }

                if ($tipo === 'saida' && $quantidade > (float) $insumo['estoque_atual']) {
                    throw new Exception("Saldo insuficiente no estoque para saída. Saldo atual: {$insumo['estoque_atual']} {$insumo['unidade']}.");
                }

                // Atualiza estoque do insumo
                $sql_update = $tipo === 'entrada'
                    ? "UPDATE insumos SET estoque_atual = estoque_atual + ? WHERE id = ?"
                    : "UPDATE insumos SET estoque_atual = estoque_atual - ? WHERE id = ?";
                $stmt_up = $pdo->prepare($sql_update);
                $stmt_up->execute([$quantidade, $insumo_id]);

                // Registra na tabela mov_estoque
                $stmt_mov = $pdo->prepare("
                    INSERT INTO mov_estoque (tipo, tipo_item, insumo_id, quantidade, motivo) 
                    VALUES (?, 'insumo', ?, ?, ?)
                ");
                $stmt_mov->execute([$tipo, $insumo_id, $quantidade, $motivo]);

                $pdo->commit();
                $mensagem_sucesso = "Movimentação registrada com sucesso para o insumo \"{$insumo['nome']}\"!";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $mensagem_erro = $e->getMessage();
            }
        }
    }

    // 2. Ajuste Manual de Produto Acabado
    if ($acao === 'movimentar_produto') {
        $produto_id = (int) ($_POST['produto_id'] ?? 0);
        $tipo = $_POST['tipo'] ?? '';
        $quantidade = (int) ($_POST['quantidade'] ?? 0);
        $motivo = trim($_POST['motivo'] ?? '');

        if ($produto_id <= 0 || !in_array($tipo, ['entrada', 'saida'], true) || $quantidade <= 0) {
            $mensagem_erro = 'Informe a fragrância, operação e quantidade de barras válidas.';
        } elseif (empty($motivo)) {
            $mensagem_erro = 'Justifique o motivo do ajuste de estoque.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("SELECT nome, estoque_atual FROM produtos WHERE id = ? FOR UPDATE");
                $stmt->execute([$produto_id]);
                $produto = $stmt->fetch();

                if (!$produto) {
                    throw new Exception('Produto não encontrado.');
                }

                if ($tipo === 'saida' && $quantidade > (int) $produto['estoque_atual']) {
                    throw new Exception("Saldo insuficiente de barras acabadas. Saldo atual: {$produto['estoque_atual']} un.");
                }

                $sql_update = $tipo === 'entrada'
                    ? "UPDATE produtos SET estoque_atual = estoque_atual + ? WHERE id = ?"
                    : "UPDATE produtos SET estoque_atual = estoque_atual - ? WHERE id = ?";
                $stmt_up = $pdo->prepare($sql_update);
                $stmt_up->execute([$quantidade, $produto_id]);

                $stmt_mov = $pdo->prepare("
                    INSERT INTO mov_estoque (tipo, tipo_item, produto_id, quantidade, motivo) 
                    VALUES (?, 'produto', ?, ?, ?)
                ");
                $stmt_mov->execute([$tipo, $produto_id, $quantidade, $motivo]);

                $pdo->commit();
                $mensagem_sucesso = "Ajuste de estoque concluído para \"{$produto['nome']}\"!";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $mensagem_erro = $e->getMessage();
            }
        }
    }
}

// ========================================================
// CONSULTAS PARA CARGA DA PÁGINA
// ========================================================

// 1. Insumos com cálculo de valor financeiro imobilizado
$stmt_insumos = $pdo->query("
    SELECT id, codigo, nome, unidade, estoque_atual, estoque_minimo, custo_unitario,
           (estoque_atual * custo_unitario) AS valor_total,
           CASE 
               WHEN estoque_atual <= (estoque_minimo * 0.5) THEN 'CRITICO'
               WHEN estoque_atual <= estoque_minimo THEN 'ALERTA'
               ELSE 'NORMAL'
           END AS status_nivel
    FROM insumos
    ORDER BY (estoque_atual / NULLIF(estoque_minimo, 0)) ASC, nome ASC
");
$insumos = $stmt_insumos->fetchAll();

// Total financeiro em insumos
$total_capital_insumos = array_sum(array_column($insumos, 'valor_total'));

// 2. Produtos acabados
$stmt_produtos = $pdo->query("
    SELECT id, codigo, nome, aroma, intensidade_aroma, estoque_atual, estoque_minimo, preco_venda,
           (estoque_atual * preco_venda) AS valor_potencial,
           CASE 
               WHEN estoque_atual <= estoque_minimo THEN 'ALERTA'
               ELSE 'NORMAL'
           END AS status_nivel
    FROM produtos
    ORDER BY codigo ASC
");
$produtos = $stmt_produtos->fetchAll();
$total_barras_acabadas = array_sum(array_column($produtos, 'estoque_atual'));

// 3. Últimas 15 Movimentações no Estoque (Auditoria)
$stmt_movs = $pdo->query("
    SELECT m.id, m.tipo, m.tipo_item, m.quantidade, m.data_mov, m.motivo,
           i.nome AS insumo_nome, i.unidade AS insumo_unidade,
           p.nome AS produto_nome,
           op.numero AS op_numero
    FROM mov_estoque m
    LEFT JOIN insumos i ON m.insumo_id = i.id
    LEFT JOIN produtos p ON m.produto_id = p.id
    LEFT JOIN ordens_producao op ON m.op_id = op.id
    ORDER BY m.data_mov DESC, m.id DESC
    LIMIT 20
");
$movimentacoes = $stmt_movs->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="container-fluid px-4 pb-4">
    <!-- Cabeçalho -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h2 class="fw-bold mb-1 text-dark">
                <i class="bi bi-boxes text-success me-2"></i>Controle de Estoque & Matéria-Prima
            </h2>
            <p class="text-muted mb-0">Gestão de ponto de reposição, valor imobilizado e rastreamento de entradas e
                saídas.</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-success shadow-sm" data-bs-toggle="modal"
                data-bs-target="#modalAjusteProduto">
                <i class="bi bi-box-arrow-in-down me-1"></i> Ajustar Barra Acabada
            </button>
            <button class="btn btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#modalMovInsumo">
                <i class="bi bi-plus-circle me-1"></i> Entrada / Saída de Insumo
            </button>
        </div>
    </div>

    <!-- Alertas Flash -->
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

    <!-- Resumo dos Indicadores de Estoque -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-lg-3">
            <div class="card card-custom p-3 bg-white h-100">
                <span class="text-muted small text-uppercase fw-semibold">Capital em Matéria-Prima</span>
                <h4 class="fw-bold my-1 text-dark">R$ <?= number_format($total_capital_insumos, 2, ',', '.') ?></h4>
                <small class="text-muted"><?= count($insumos) ?> matérias-primas e embalagens</small>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
            <div class="card card-custom p-3 bg-white h-100">
                <span class="text-muted small text-uppercase fw-semibold">Barras Acabadas no Estoque</span>
                <h4 class="fw-bold my-1 text-primary"><?= number_format($total_barras_acabadas, 0, ',', '.') ?> <small
                        class="fs-6 text-muted">un</small></h4>
                <small class="text-muted">Distribuídas em 4 fragrâncias</small>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
            <div class="card card-custom p-3 bg-white h-100">
                <span class="text-muted small text-uppercase fw-semibold">Insumos em Alerta/Crítico</span>
                <?php
                $cont_alerta = count(array_filter($insumos, fn($i) => $i['status_nivel'] !== 'NORMAL'));
                ?>
                <h4 class="fw-bold my-1 <?= $cont_alerta > 0 ? 'text-danger' : 'text-success' ?>">
                    <?= $cont_alerta ?>
                </h4>
                <small class="text-muted">Abaixo do estoque de segurança</small>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
            <div class="card card-custom p-3 bg-white h-100">
                <span class="text-muted small text-uppercase fw-semibold">Política de Reposição</span>
                <h5 class="fw-bold my-1 text-dark">Lote Mínimo PCP</h5>
                <small class="text-muted">Giro quinzenal planejado</small>
            </div>
        </div>
    </div>

    <!-- Navegação por Abas (Tabs) -->
    <div class="card card-custom bg-white">
        <div class="card-header bg-white border-bottom-0 pt-3 px-4">
            <ul class="nav nav-pills card-header-pills" id="estoqueTab" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active fw-semibold" id="insumos-tab" data-bs-toggle="tab"
                        data-bs-target="#insumos-pane" type="button" role="tab">
                        <i class="bi bi-droplet-half me-1"></i> Matérias-Primas & Insumos (BOM)
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link fw-semibold" id="produtos-tab" data-bs-toggle="tab"
                        data-bs-target="#produtos-pane" type="button" role="tab">
                        <i class="bi bi-box2 me-1"></i> Produtos Acabados
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link fw-semibold" id="movs-tab" data-bs-toggle="tab" data-bs-target="#movs-pane"
                        type="button" role="tab">
                        <i class="bi bi-journal-text me-1"></i> Histórico / Kardex
                    </button>
                </li>
            </ul>
        </div>

        <div class="card-body p-4">
            <div class="tab-content" id="estoqueTabContent">

                <!-- ABA 1: MATÉRIAS-PRIMAS -->
                <div class="tab-pane fade show active" id="insumos-pane" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold mb-0 text-muted text-uppercase">Posição Geral de Insumos</h6>
                        <span class="text-muted small">Valores decimais com 4 casas (pesagem e fracionamento de
                            precisão)</span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light small text-uppercase">
                                <tr>
                                    <th>Cód.</th>
                                    <th>Insumo</th>
                                    <th>Estoque Atual</th>
                                    <th>Estoque Mínimo</th>
                                    <th>Custo Unit.</th>
                                    <th>Valor Total</th>
                                    <th>Status Reposição</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($insumos as $ins):
                                    $atual = (float) $ins['estoque_atual'];
                                    $min = (float) $ins['estoque_minimo'];
                                    $perc_seguranca = $min > 0 ? min(100, round(($atual / $min) * 100)) : 100;
                                    ?>
                                <tr>
                                    <td><span
                                            class="badge bg-light text-secondary border font-monospace"><?= htmlspecialchars($ins['codigo']) ?></span>
                                    </td>
                                    <td><strong><?= htmlspecialchars($ins['nome']) ?></strong></td>
                                    <td>
                                        <span
                                            class="fs-6 fw-bold <?= $ins['status_nivel'] !== 'NORMAL' ? 'text-danger' : 'text-dark' ?>">
                                            <?= number_format($atual, 4, ',', '.') ?>
                                        </span>
                                        <small class="text-muted"><?= $ins['unidade'] ?></small>
                                    </td>
                                    <td>
                                        <?= number_format($min, 4, ',', '.') ?> <small
                                            class="text-muted"><?= $ins['unidade'] ?></small>
                                    </td>
                                    <td>R$ <?= number_format((float) $ins['custo_unitario'], 2, ',', '.') ?></td>
                                    <td class="fw-semibold text-dark">R$
                                        <?= number_format((float) $ins['valor_total'], 2, ',', '.') ?>
                                    </td>
                                    <td>
                                        <?php if ($ins['status_nivel'] === 'CRITICO'): ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                                            <i class="bi bi-x-octagon me-1"></i> Crítico (< 50% do mín) </span>
                                                <?php elseif ($ins['status_nivel'] === 'ALERTA'): ?>
                                                <span
                                                    class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                                                    <i class="bi bi-exclamation-triangle me-1"></i> Ponto de Reposição
                                                </span>
                                                <?php else: ?>
                                                <span
                                                    class="badge bg-success-subtle text-success border border-success-subtle">
                                                    <i class="bi bi-check2-circle me-1"></i> Seguro
                                                </span>
                                                <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ABA 2: PRODUTOS ACABADOS -->
                <div class="tab-pane fade" id="produtos-pane" role="tabpanel">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light small text-uppercase">
                                <tr>
                                    <th>Cód.</th>
                                    <th>Fragrância</th>
                                    <th>Intensidade Aroma</th>
                                    <th>Estoque Físico</th>
                                    <th>Estoque de Segurança</th>
                                    <th>Preço Venda</th>
                                    <th>Valor Total em Estoque</th>
                                    <th>Situação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($produtos as $prod):
                                    $alerta = $prod['estoque_atual'] <= $prod['estoque_minimo'];
                                    ?>
                                <tr>
                                    <td><span
                                            class="badge bg-secondary font-monospace"><?= htmlspecialchars($prod['codigo']) ?></span>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($prod['nome']) ?></strong>
                                        <div class="small text-muted"><?= htmlspecialchars($prod['aroma']) ?></div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border">
                                            <?= ucfirst($prod['intensidade_aroma']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="fs-5 fw-bold <?= $alerta ? 'text-danger' : 'text-success' ?>">
                                            <?= $prod['estoque_atual'] ?>
                                        </span> <small class="text-muted">barras (100g)</small>
                                    </td>
                                    <td><?= $prod['estoque_minimo'] ?> barras</td>
                                    <td>R$ <?= number_format((float) $prod['preco_venda'], 2, ',', '.') ?></td>
                                    <td class="fw-semibold">R$
                                        <?= number_format((float) $prod['valor_potencial'], 2, ',', '.') ?>
                                    </td>
                                    <td>
                                        <?php if ($alerta): ?>
                                        <span
                                            class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                                            Requer Produção
                                        </span>
                                        <?php else: ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle">
                                            Atende Demanda
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ABA 3: HISTÓRICO DE MOVIMENTAÇÕES (KARDEX) -->
                <div class="tab-pane fade" id="movs-pane" role="tabpanel">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light small text-uppercase">
                                <tr>
                                    <th>Data / Hora</th>
                                    <th>Tipo</th>
                                    <th>Item</th>
                                    <th>Qtd.</th>
                                    <th>Referência / Motivo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($movimentacoes)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">Nenhuma movimentação registrada
                                        até o momento.</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($movimentacoes as $mov):
                                        $is_entrada = $mov['tipo'] === 'entrada';
                                        $item_nome = $mov['tipo_item'] === 'insumo'
                                            ? htmlspecialchars($mov['insumo_nome']) . " ({$mov['insumo_unidade']})"
                                            : htmlspecialchars($mov['produto_nome']) . " (barras)";
                                        ?>
                                <tr>
                                    <td class="small text-muted"><?= date('d/m/Y H:i', strtotime($mov['data_mov'])) ?>
                                    </td>
                                    <td>
                                        <span
                                            class="badge bg-<?= $is_entrada ? 'success-subtle text-success border border-success-subtle' : 'danger-subtle text-danger border border-danger-subtle' ?>">
                                            <i
                                                class="bi bi-arrow-<?= $is_entrada ? 'down-left' : 'up-right' ?> me-1"></i>
                                            <?= strtoupper($mov['tipo']) ?>
                                        </span>
                                    </td>
                                    <td><strong><?= $item_nome ?></strong></td>
                                    <td
                                        class="font-monospace fw-bold <?= $is_entrada ? 'text-success' : 'text-danger' ?>">
                                        <?= $is_entrada ? '+' : '-' ?>
                                        <?= number_format((float) $mov['quantidade'], 4, ',', '.') ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($mov['motivo']) ?>
                                        <?php if (!empty($mov['op_numero'])): ?>
                                        <span
                                            class="badge bg-light text-dark border ms-1"><?= htmlspecialchars($mov['op_numero']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL 1: ENTRADA / SAÍDA DE INSUMO                      -->
<!-- ======================================================== -->
<div class="modal fade" id="modalMovInsumo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="estoque.php" class="modal-content">
            <input type="hidden" name="acao" value="movimentar_insumo">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-droplet-half text-success me-2"></i>Movimentar Insumo /
                    Matéria-Prima</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Insumo:</label>
                    <select name="insumo_id" class="form-select" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($insumos as $ins): ?>
                        <option value="<?= $ins['id'] ?>">
                            <?= htmlspecialchars($ins['nome']) ?> (Atual:
                            <?= number_format((float) $ins['estoque_atual'], 4, ',', '.') ?> <?= $ins['unidade'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold">Tipo:</label>
                        <select name="tipo" class="form-select" required>
                            <option value="entrada">Entrada (+ Compra/Reposição)</option>
                            <option value="saida">Saída (- Descarte/Ajuste)</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Quantidade:</label>
                        <input type="number" step="0.0001" min="0.0001" name="quantidade" class="form-control"
                            placeholder="0.0000" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Motivo / Documento:</label>
                    <input type="text" name="motivo" class="form-control"
                        placeholder="Ex: NF 4029 - Fornecedor Glicerina / Descarte validade" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success"><i class="bi bi-check2 me-1"></i> Confirmar
                    Lançamento</button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL 2: AJUSTE DE PRODUTO ACABADO                      -->
<!-- ======================================================== -->
<div class="modal fade" id="modalAjusteProduto" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="estoque.php" class="modal-content">
            <input type="hidden" name="acao" value="movimentar_produto">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-box2 text-primary me-2"></i>Ajustar Estoque de Barras
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Fragrância:</label>
                    <select name="produto_id" class="form-select" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($produtos as $p): ?>
                        <option value="<?= $p['id'] ?>">
                            [<?= $p['codigo'] ?>] <?= htmlspecialchars($p['nome']) ?> (Atual: <?= $p['estoque_atual'] ?>
                            un)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold">Tipo:</label>
                        <select name="tipo" class="form-select" required>
                            <option value="entrada">Entrada (+ Inventário físico)</option>
                            <option value="saida">Saída (- Venda/Amostra/Avaria)</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Barras (unidades):</label>
                        <input type="number" step="1" min="1" name="quantidade" class="form-control"
                            placeholder="Qtd. inteira" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Motivo do Ajuste:</label>
                    <input type="text" name="motivo" class="form-control"
                        placeholder="Ex: Inventário de contagem física semanal" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i> Gravar Ajuste</button>
            </div>
        </form>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';