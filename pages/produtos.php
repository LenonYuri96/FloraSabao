<?php
declare(strict_types=1);

// 🔐 GUARDA DE AUTENTICAÇÃO — deve vir PRIMEIRO
require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
$page_title = 'Produtos & Ficha Técnica (BOM)';
$mensagem_sucesso = '';
$mensagem_erro = '';

// ========================================================
// PROCESSAMENTO DE FORMULÁRIOS (POST)
// ========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // 1. Cadastrar Novo Produto
    if ($acao === 'salvar_produto') {
        $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
        $nome = trim($_POST['nome'] ?? '');
        $aroma = trim($_POST['aroma'] ?? '');
        $intensidade = $_POST['intensidade_aroma'] ?? 'moderado';
        $peso_g = (float) ($_POST['peso_barra_g'] ?? 100);
        $estoque_min = (int) ($_POST['estoque_minimo'] ?? 50);
        $preco_venda = (float) str_replace(',', '.', $_POST['preco_venda'] ?? '0');

        if (empty($codigo) || empty($nome) || $preco_venda <= 0) {
            $mensagem_erro = 'Preencha código, nome e preço de venda válido.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO produtos (codigo, nome, aroma, peso_barra_g, intensidade_aroma, estoque_minimo, preco_venda)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$codigo, $nome, $aroma, $peso_g, $intensidade, $estoque_min, $preco_venda]);
                $mensagem_sucesso = "Produto {$codigo} cadastrado com sucesso!";
            } catch (PDOException $e) {
                $mensagem_erro = ($e->getCode() == 23000) ? 'Código de produto já cadastrado.' : 'Erro: ' . $e->getMessage();
            }
        }
    }

    // 2. Adicionar Insumo na Ficha Técnica (BOM)
    if ($acao === 'adicionar_insumo_bom') {
        $produto_id = (int) ($_POST['produto_id'] ?? 0);
        $insumo_id = (int) ($_POST['insumo_id'] ?? 0);
        $qtd = (float) str_replace(',', '.', $_POST['qtd_por_barra'] ?? '0');

        if ($produto_id <= 0 || $insumo_id <= 0 || $qtd <= 0) {
            $mensagem_erro = 'Informe o produto, insumo e quantidade por barra válida.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO ficha_tecnica (produto_id, insumo_id, qtd_por_barra)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE qtd_por_barra = VALUES(qtd_por_barra)
                ");
                $stmt->execute([$produto_id, $insumo_id, $qtd]);
                $mensagem_sucesso = 'Ficha Técnica atualizada com sucesso!';
            } catch (PDOException $e) {
                $mensagem_erro = 'Erro ao atualizar BOM: ' . $e->getMessage();
            }
        }
    }

    // 3. Remover Insumo da Ficha Técnica
    if ($acao === 'remover_insumo_bom') {
        $bom_id = (int) ($_POST['bom_id'] ?? 0);
        if ($bom_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM ficha_tecnica WHERE id = ?");
            $stmt->execute([$bom_id]);
            $mensagem_sucesso = 'Insumo removido da Ficha Técnica.';
        }
    }
}

// ========================================================
// CONSULTAS DA PÁGINA
// ========================================================

// 1. Insumos disponíveis para seleção na BOM
$insumos_disponiveis = $pdo->query("SELECT id, codigo, nome, unidade, custo_unitario FROM insumos ORDER BY nome ASC")->fetchAll();

// 2. Buscar Produtos e calcular Custo de Insumos por Barra
$stmt_prods = $pdo->query("
    SELECT p.*,
           COALESCE(SUM(ft.qtd_por_barra * i.custo_unitario), 0) AS custo_mp_barra,
           COUNT(ft.id) AS total_itens_bom
    FROM produtos p
    LEFT JOIN ficha_tecnica ft ON p.id = ft.produto_id
    LEFT JOIN insumos i ON ft.insumo_id = i.id
    WHERE p.ativo = 1
    GROUP BY p.id
    ORDER BY p.id ASC
");
$produtos = $stmt_prods->fetchAll();

// 3. Carregar Ficha Técnica detalhada agrupada por produto
$stmt_bom = $pdo->query("
    SELECT ft.id AS bom_id, ft.produto_id, ft.qtd_por_barra,
           i.nome AS insumo_nome, i.codigo AS insumo_codigo, i.unidade, i.custo_unitario,
           (ft.qtd_por_barra * i.custo_unitario) AS subtotal_custo
    FROM ficha_tecnica ft
    JOIN insumos i ON ft.insumo_id = i.id
    ORDER BY ft.produto_id, i.nome
");
$boms_raw = $stmt_bom->fetchAll();

$fichas_tecnicas = [];
foreach ($boms_raw as $item) {
    $fichas_tecnicas[$item['produto_id']][] = $item;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="container-fluid px-4 pb-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h2 class="fw-bold mb-1 text-dark">
                <i class="bi bi-flower1 text-success me-2"></i>Produtos & Estrutura de Materiais (BOM)
            </h2>
            <p class="text-muted mb-0">Engenharia do produto: consumo unitário de insumos, custo padrão da barra e
                margens.</p>
        </div>
        <div>
            <button class="btn btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#modalNovoProduto">
                <i class="bi bi-plus-circle me-1"></i> Cadastrar Nova Fragrância
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

    <!-- Lista de Produtos e suas Fichas Técnicas -->
    <div class="row g-4">
        <?php foreach ($produtos as $p):
            $custo_barra = (float) $p['custo_mp_barra'];
            $preco_venda = (float) $p['preco_venda'];
            $margem_bruta = $preco_venda - $custo_barra;
            $margem_perc = $preco_venda > 0 ? round(($margem_bruta / $preco_venda) * 100, 1) : 0;
            $bom_itens = $fichas_tecnicas[$p['id']] ?? [];
            ?>
            <div class="col-12 col-xl-6">
                <div class="card card-custom bg-white h-100 p-3">
                    <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3">
                        <div>
                            <span class="badge bg-secondary font-monospace"><?= htmlspecialchars($p['codigo']) ?></span>
                            <span class="badge bg-light text-dark border ms-1">Intensidade:
                                <?= ucfirst($p['intensidade_aroma']) ?></span>
                            <h4 class="fw-bold my-1 text-dark"><?= htmlspecialchars($p['nome']) ?></h4>
                            <div class="text-muted small"><i class="bi bi-droplet me-1"></i>Aroma:
                                <?= htmlspecialchars($p['aroma']) ?> | Peso: <?= $p['peso_barra_g'] ?>g
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="fs-4 fw-bold text-success">R$
                                <?= number_format($preco_venda, 2, ',', '.') ?></span>
                            <div class="small text-muted">Preço de Venda</div>
                        </div>
                    </div>

                    <!-- Indicadores Financeiros da Barra -->
                    <div class="row text-center g-2 mb-3 bg-light p-2 rounded-3">
                        <div class="col-4 border-end">
                            <span class="small text-muted d-block">Custo MP / Barra</span>
                            <strong class="text-danger">R$ <?= number_format($custo_barra, 2, ',', '.') ?></strong>
                        </div>
                        <div class="col-4 border-end">
                            <span class="small text-muted d-block">Margem Bruta</span>
                            <strong class="text-success">R$ <?= number_format($margem_bruta, 2, ',', '.') ?></strong>
                        </div>
                        <div class="col-4">
                            <span class="small text-muted d-block">Margem %</span>
                            <strong class="text-primary"><?= $margem_perc ?>%</strong>
                        </div>
                    </div>

                    <!-- Ficha Técnica (BOM) -->
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="fw-bold text-uppercase text-muted mb-0 small">
                            <i class="bi bi-list-check me-1"></i>Ficha Técnica (Consumo p/ 1 Barra de 100g)
                        </h6>
                        <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#modalAddBom"
                            data-prod-id="<?= $p['id'] ?>" data-prod-nome="<?= htmlspecialchars($p['nome']) ?>">
                            <i class="bi bi-plus me-1"></i> Insumo
                        </button>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0" style="font-size: 0.88rem;">
                            <thead class="table-light text-muted">
                                <tr>
                                    <th>Insumo</th>
                                    <th>Consumo Unitário</th>
                                    <th>Custo Unit.</th>
                                    <th>Custo/Barra</th>
                                    <th class="text-end">Ação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($bom_itens)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-2">Nenhum insumo configurado na Ficha
                                            Técnica.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($bom_itens as $bom): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($bom['insumo_nome']) ?></strong></td>
                                            <td class="font-monospace">
                                                <?= number_format((float) $bom['qtd_por_barra'], 4, ',', '.') ?>
                                                <?= $bom['unidade'] ?>
                                            </td>
                                            <td class="text-muted">R$
                                                <?= number_format((float) $bom['custo_unitario'], 2, ',', '.') ?>
                                            </td>
                                            <td class="fw-semibold">R$
                                                <?= number_format((float) $bom['subtotal_custo'], 3, ',', '.') ?>
                                            </td>
                                            <td class="text-end">
                                                <form method="POST" action="produtos.php" class="d-inline"
                                                    onsubmit="return confirm('Remover este insumo da Ficha Técnica?');">
                                                    <input type="hidden" name="acao" value="remover_insumo_bom">
                                                    <input type="hidden" name="bom_id" value="<?= $bom['bom_id'] ?>">
                                                    <button type="submit" class="btn btn-link text-danger p-0 border-0"
                                                        title="Excluir">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL: CADASTRAR PRODUTO                                -->
<!-- ======================================================== -->
<div class="modal fade" id="modalNovoProduto" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="produtos.php" class="modal-content">
            <input type="hidden" name="acao" value="salvar_produto">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-flower1 text-success me-2"></i>Novo Sabonete em Linha
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <label class="form-label fw-semibold">Código:</label>
                        <input type="text" name="codigo" class="form-control" placeholder="Ex: ROM" maxlength="10"
                            required>
                    </div>
                    <div class="col-8">
                        <label class="form-label fw-semibold">Nome Comercial:</label>
                        <input type="text" name="nome" class="form-control" placeholder="Sabonete Artesanal Alecrim"
                            required>
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold">Aroma:</label>
                        <input type="text" name="aroma" class="form-control" placeholder="Ex: Alecrim Silvestre"
                            required>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Intensidade do Aroma:</label>
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
                        <input type="number" step="1" name="peso_barra_g" class="form-control" value="100" required>
                    </div>
                    <div class="col-4">
                        <label class="form-label fw-semibold">Estoque Mínimo:</label>
                        <input type="number" step="1" name="estoque_minimo" class="form-control" value="50" required>
                    </div>
                    <div class="col-4">
                        <label class="form-label fw-semibold">Preço Venda (R$):</label>
                        <input type="text" name="preco_venda" class="form-control" placeholder="15,00" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success"><i class="bi bi-check2 me-1"></i> Cadastrar
                    Produto</button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL: ADICIONAR INSUMO NA BOM                           -->
<!-- ======================================================== -->
<div class="modal fade" id="modalAddBom" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="produtos.php" class="modal-content">
            <input type="hidden" name="acao" value="adicionar_insumo_bom">
            <input type="hidden" name="produto_id" id="modal_bom_produto_id" value="">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle text-success me-2"></i>Adicionar Insumo à
                    Ficha Técnica</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">Produto: <strong id="modal_bom_produto_nome"
                        class="text-dark"></strong></p>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Selecione o Insumo:</label>
                    <select name="insumo_id" class="form-select" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($insumos_disponiveis as $ins): ?>
                            <option value="<?= $ins['id'] ?>">
                                <?= htmlspecialchars($ins['nome']) ?> (<?= $ins['unidade'] ?>) - Custo: R$
                                <?= number_format((float) $ins['custo_unitario'], 2, ',', '.') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Quantidade p/ 1 barra de 100g:</label>
                    <input type="number" step="0.0001" min="0.0001" name="qtd_por_barra" class="form-control"
                        placeholder="Ex: 0.0920 para 92g" required>
                    <small class="text-muted">Use ponto ou vírgula decimal (ex: 0.0040 para 4ml de essência).</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                <button type="submit" class="btn btn-success"><i class="bi bi-save me-1"></i> Salvar na BOM</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const modalAddBom = document.getElementById('modalAddBom');
        if (modalAddBom) {
            modalAddBom.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                const prodId = button.getAttribute('data-prod-id');
                const prodNome = button.getAttribute('data-prod-nome');
                document.getElementById('modal_bom_produto_id').value = prodId;
                document.getElementById('modal_bom_produto_nome').textContent = prodNome;
            });
        }
    });
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';