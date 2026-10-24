<?php
declare(strict_types=1);

// 🔐 GUARDA DE AUTENTICAÇÃO — deve vir PRIMEIRO
require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
$page_title = 'Cadastro de Insumos & Matérias-Primas';
$mensagem_sucesso = '';
$mensagem_erro = '';

// PROCESSAMENTO DO FORMULÁRIO (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'salvar_insumo') {
        $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
        $nome = trim($_POST['nome'] ?? '');
        $unidade = trim($_POST['unidade'] ?? 'kg');
        $estoque_min = (float) str_replace(',', '.', $_POST['estoque_minimo'] ?? '0');
        $custo_unit = (float) str_replace(',', '.', $_POST['custo_unitario'] ?? '0');

        if (empty($codigo) || empty($nome) || $custo_unit <= 0) {
            $mensagem_erro = 'Preencha código, nome e custo unitário válido.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO insumos (codigo, nome, unidade, estoque_minimo, custo_unitario)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        nome = VALUES(nome),
                        unidade = VALUES(unidade),
                        estoque_minimo = VALUES(estoque_minimo),
                        custo_unitario = VALUES(custo_unitario)
                ");
                $stmt->execute([$codigo, $nome, $unidade, $estoque_min, $custo_unit]);
                $mensagem_sucesso = "Insumo {$codigo} gravado com sucesso!";
            } catch (PDOException $e) {
                $mensagem_erro = 'Erro ao salvar insumo: ' . $e->getMessage();
            }
        }
    }
}

$insumos = $pdo->query("SELECT * FROM insumos ORDER BY nome ASC")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="container-fluid px-4 pb-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h2 class="fw-bold mb-1 text-dark">
                <i class="bi bi-droplet-half text-success me-2"></i>Catálogo de Insumos & Custos
            </h2>
            <p class="text-muted mb-0">Parametrização de custos de compra e estoque de segurança para o cálculo do MRP.
            </p>
        </div>
        <div>
            <button class="btn btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#modalInsumo">
                <i class="bi bi-plus-circle me-1"></i> Novo Insumo / Atualizar Custo
            </button>
        </div>
    </div>

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

    <div class="card card-custom bg-white p-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light small text-uppercase">
                    <tr>
                        <th>Código</th>
                        <th>Descrição da Matéria-Prima</th>
                        <th>Unidade</th>
                        <th>Estoque Atual</th>
                        <th>Ponto de Pedido (Mínimo)</th>
                        <th>Custo Unitário</th>
                        <th>Total em Saldo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($insumos as $ins):
                        $total = (float) $ins['estoque_atual'] * (float) $ins['custo_unitario'];
                        ?>
                    <tr>
                        <td><span
                                class="badge bg-light text-secondary border font-monospace"><?= htmlspecialchars($ins['codigo']) ?></span>
                        </td>
                        <td><strong><?= htmlspecialchars($ins['nome']) ?></strong></td>
                        <td><span class="badge bg-light text-dark border"><?= $ins['unidade'] ?></span></td>
                        <td class="font-monospace fw-bold">
                            <?= number_format((float) $ins['estoque_atual'], 4, ',', '.') ?>
                        </td>
                        <td class="font-monospace text-muted">
                            <?= number_format((float) $ins['estoque_minimo'], 4, ',', '.') ?>
                        </td>
                        <td>R$ <?= number_format((float) $ins['custo_unitario'], 2, ',', '.') ?></td>
                        <td class="fw-semibold text-dark">R$ <?= number_format($total, 2, ',', '.') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL CADASTRAR/EDITAR INSUMO -->
<div class="modal fade" id="modalInsumo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="insumos.php" class="modal-content">
            <input type="hidden" name="acao" value="salvar_insumo">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square text-success me-2"></i>Parametrizar Insumo
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <label class="form-label fw-semibold">Código:</label>
                        <input type="text" name="codigo" class="form-control" placeholder="ESS-CHA" required>
                    </div>
                    <div class="col-8">
                        <label class="form-label fw-semibold">Descrição do Insumo:</label>
                        <input type="text" name="nome" class="form-control" placeholder="Essência Chá Branco" required>
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <label class="form-label fw-semibold">Unidade:</label>
                        <select name="unidade" class="form-select">
                            <option value="kg">kg (Quilo)</option>
                            <option value="L">L (Litro)</option>
                            <option value="un">un (Unidade)</option>
                        </select>
                    </div>
                    <div class="col-4">
                        <label class="form-label fw-semibold">Estoque Mínimo:</label>
                        <input type="number" step="0.0001" name="estoque_minimo" class="form-control"
                            placeholder="1.0000" required>
                    </div>
                    <div class="col-4">
                        <label class="form-label fw-semibold">Custo Unit. (R$):</label>
                        <input type="text" name="custo_unitario" class="form-control" placeholder="95,00" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                <button type="submit" class="btn btn-success"><i class="bi bi-save me-1"></i> Gravar Insumo</button>
            </div>
        </form>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';