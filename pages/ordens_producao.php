<?php
declare(strict_types=1);

// 🔐 GUARDA DE AUTENTICAÇÃO — deve vir PRIMEIRO
require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
$page_title = 'Ordens de Produção (OP)';
$mensagem_sucesso = '';
$mensagem_erro = '';

// ========================================================
// PROCESSAMENTO DE AÇÕES DE PCP (POST)
// ========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // 1. CRIAR NOVA OP (Planejamento)
    if ($acao === 'criar_op') {
        $produto_id = (int) ($_POST['produto_id'] ?? 0);
        $qtd_planejada = (int) ($_POST['quantidade_planejada'] ?? 0);
        $data_prog = $_POST['data_programada'] ?? '';
        $obs = trim($_POST['observacoes'] ?? '');

        if ($produto_id <= 0 || $qtd_planejada <= 0 || empty($data_prog)) {
            $mensagem_erro = 'Selecione o produto, informe quantidade positiva e data válida.';
        } else {
            // Gerar número sequencial de OP (ex: OP-2026-004)
            $ano = date('Y', strtotime($data_prog));
            $stmt_count = $pdo->query("SELECT COUNT(*) + 1 FROM ordens_producao");
            $seq = str_pad((string) $stmt_count->fetchColumn(), 3, '0', STR_PAD_LEFT);
            $numero_op = "OP-{$ano}-{$seq}";

            $stmt = $pdo->prepare("
                INSERT INTO ordens_producao (numero, produto_id, quantidade_planejada, data_programada, status, observacoes)
                VALUES (?, ?, ?, ?, 'planejada', ?)
            ");
            $stmt->execute([$numero_op, $produto_id, $qtd_planejada, $data_prog, $obs]);
            $mensagem_sucesso = "Ordem de Produção {$numero_op} criada com sucesso!";
        }
    }

    // 2. INICIAR PRODUÇÃO (Liberar chão de fábrica)
    if ($acao === 'iniciar_op') {
        $op_id = (int) ($_POST['op_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE ordens_producao SET status = 'em_producao', data_inicio = NOW() WHERE id = ? AND status = 'planejada'");
        $stmt->execute([$op_id]);
        $mensagem_sucesso = 'Status da OP atualizado para "Em Produção".';
    }

    // 3. FINALIZAR OP (Apontamento de Boas + Refugo e Baixa Automática de Insumos)
    if ($acao === 'finalizar_op') {
        $op_id = (int) ($_POST['op_id'] ?? 0);
        $qtd_produzida = (int) ($_POST['quantidade_produzida'] ?? 0);
        $qtd_refugo = (int) ($_POST['quantidade_refugo'] ?? 0);

        if ($op_id <= 0 || ($qtd_produzida + $qtd_refugo) <= 0) {
            $mensagem_erro = 'A soma de barras boas e refugo deve ser maior que zero.';
        } else {
            try {
                $pdo->beginTransaction();

                // 3.1 Busca a OP com lock
                $stmt_op = $pdo->prepare("SELECT * FROM ordens_producao WHERE id = ? FOR UPDATE");
                $stmt_op->execute([$op_id]);
                $op = $stmt_op->fetch();

                if (!$op || $op['status'] === 'finalizada') {
                    throw new Exception('OP não encontrada ou já finalizada.');
                }

                $total_processado = $qtd_produzida + $qtd_refugo;

                // 3.2 Explosão da BOM para consumir os insumos do total processado (Boas + Perdas)
                $stmt_bom = $pdo->prepare("
                    SELECT ft.insumo_id, ft.qtd_por_barra, i.nome, i.estoque_atual, i.unidade
                    FROM ficha_tecnica ft
                    JOIN insumos i ON ft.insumo_id = i.id
                    WHERE ft.produto_id = ?
                ");
                $stmt_bom->execute([$op['produto_id']]);
                $ingredientes = $stmt_bom->fetchAll();

                if (empty($ingredientes)) {
                    throw new Exception('Esta fragrância não possui Ficha Técnica cadastrada. Configure a BOM antes de finalizar.');
                }

                // 3.3 Consome os insumos e gera registro no Kardex
                foreach ($ingredientes as $ing) {
                    $qtd_consumida = $ing['qtd_por_barra'] * $total_processado;

                    // Baixa o estoque do insumo
                    $stmt_baixa = $pdo->prepare("UPDATE insumos SET estoque_atual = estoque_atual - ? WHERE id = ?");
                    $stmt_baixa->execute([$qtd_consumida, $ing['insumo_id']]);

                    // Registra saída no histórico
                    $stmt_mov_ins = $pdo->prepare("
                        INSERT INTO mov_estoque (tipo, tipo_item, insumo_id, quantidade, op_id, motivo)
                        VALUES ('saida', 'insumo', ?, ?, ?, ?)
                    ");
                    $stmt_mov_ins->execute([$ing['insumo_id'], $qtd_consumida, $op_id, "Consumo Produção {$op['numero']}"]);
                }

                // 3.4 Incrementa o estoque do produto acabado (apenas barras boas aprovadas)
                if ($qtd_produzida > 0) {
                    $stmt_prod_up = $pdo->prepare("UPDATE produtos SET estoque_atual = estoque_atual + ? WHERE id = ?");
                    $stmt_prod_up->execute([$qtd_produzida, $op['produto_id']]);

                    // Registra entrada de produto acabado no histórico
                    $stmt_mov_p = $pdo->prepare("
                        INSERT INTO mov_estoque (tipo, tipo_item, produto_id, quantidade, op_id, motivo)
                        VALUES ('entrada', 'produto', ?, ?, ?, ?)
                    ");
                    $stmt_mov_p->execute([$op['produto_id'], $qtd_produzida, $op_id, "Entrada PA Lote {$op['numero']}"]);
                }

                // 3.5 Atualiza a OP
                $stmt_finaliza = $pdo->prepare("
                    UPDATE ordens_producao 
                    SET quantidade_produzida = ?, quantidade_refugo = ?, data_finalizacao = NOW(), status = 'finalizada'
                    WHERE id = ?
                ");
                $stmt_finaliza->execute([$qtd_produzida, $qtd_refugo, $op_id]);

                $pdo->commit();
                $mensagem_sucesso = "OP {$op['numero']} finalizada! Foram estocadas {$qtd_produzida} barras e os insumos foram baixados automaticamente.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $mensagem_erro = $e->getMessage();
            }
        }
    }

    // 4. CANCELAR OP
    if ($acao === 'cancelar_op') {
        $op_id = (int) ($_POST['op_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE ordens_producao SET status = 'cancelada' WHERE id = ? AND status != 'finalizada'");
        $stmt->execute([$op_id]);
        $mensagem_sucesso = 'Ordem de Produção cancelada.';
    }
}

// ========================================================
// CONSULTAS DA LISTAGEM
// ========================================================
$filtro_status = $_GET['status'] ?? 'todos';
$sql_where = $filtro_status !== 'todos' ? "WHERE op.status = " . $pdo->quote($filtro_status) : "";

$stmt_ops = $pdo->query("
    SELECT op.*, p.nome AS produto_nome, p.codigo AS produto_codigo, p.aroma, p.intensidade_aroma
    FROM ordens_producao op
    JOIN produtos p ON op.produto_id = p.id
    {$sql_where}
    ORDER BY op.data_programada DESC, op.id DESC
");
$ordens = $stmt_ops->fetchAll();

// Produtos para o formulário de nova OP
$produtos_select = $pdo->query("SELECT id, codigo, nome FROM produtos WHERE ativo = 1 ORDER BY nome")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="container-fluid px-4 pb-4">
    <!-- Topo -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h2 class="fw-bold mb-1 text-dark">
                <i class="bi bi-clipboard2-check text-success me-2"></i>Ordens de Produção (OP)
            </h2>
            <p class="text-muted mb-0">Programação de lotes, explosão de materiais (BOM) e apontamento de refugos.</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#modalNovaOP">
                <i class="bi bi-plus-circle me-1"></i> Emitir Nova OP
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

    <!-- Barra de Filtros -->
    <div class="card card-custom bg-white p-3 mb-4">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="text-muted small fw-semibold me-2 text-uppercase">Filtrar por Status:</span>
            <a href="ordens_producao.php?status=todos"
                class="btn btn-sm <?= $filtro_status === 'todos' ? 'btn-dark' : 'btn-outline-secondary' ?>">Todas</a>
            <a href="ordens_producao.php?status=planejada"
                class="btn btn-sm <?= $filtro_status === 'planejada' ? 'btn-secondary text-white' : 'btn-outline-secondary' ?>">Planejadas</a>
            <a href="ordens_producao.php?status=em_producao"
                class="btn btn-sm <?= $filtro_status === 'em_producao' ? 'btn-warning text-dark' : 'btn-outline-warning text-dark' ?>">Em
                Produção</a>
            <a href="ordens_producao.php?status=finalizada"
                class="btn btn-sm <?= $filtro_status === 'finalizada' ? 'btn-success' : 'btn-outline-success' ?>">Finalizadas</a>
            <a href="ordens_producao.php?status=cancelada"
                class="btn btn-sm <?= $filtro_status === 'cancelada' ? 'btn-danger' : 'btn-outline-danger' ?>">Canceladas</a>
        </div>
    </div>

    <!-- Tabela Geral de OPs -->
    <div class="card card-custom bg-white p-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light small text-uppercase">
                    <tr>
                        <th>Número OP</th>
                        <th>Fragrância</th>
                        <th>Programada</th>
                        <th>Planejado</th>
                        <th>Apontado (Boas / Refugo)</th>
                        <th>Status</th>
                        <th class="text-end">Ações de PCP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ordens)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">Nenhuma ordem de produção encontrada para os
                                filtros atuais.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($ordens as $op):
                            $st = STATUS_OP[$op['status']] ?? ['label' => $op['status'], 'badge' => 'secondary'];
                            ?>
                            <tr>
                                <td class="fw-bold font-monospace text-dark">
                                    <?= htmlspecialchars($op['numero']) ?>
                                    <?php if (!empty($op['observacoes'])): ?>
                                        <i class="bi bi-info-circle text-muted ms-1"
                                            title="<?= htmlspecialchars($op['observacoes']) ?>"></i>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span
                                        class="badge bg-light text-dark border me-1"><?= htmlspecialchars($op['produto_codigo']) ?></span>
                                    <strong><?= htmlspecialchars($op['produto_nome']) ?></strong>
                                </td>
                                <td><?= date('d/m/Y', strtotime($op['data_programada'])) ?></td>
                                <td class="fw-bold"><?= $op['quantidade_planejada'] ?> un</td>
                                <td>
                                    <?php if ($op['status'] === 'finalizada'): ?>
                                        <span class="text-success fw-bold"><?= $op['quantidade_produzida'] ?> boas</span>
                                        <?php if ($op['quantidade_refugo'] > 0): ?>
                                            / <span class="text-danger"><?= $op['quantidade_refugo'] ?> refugo</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $st['badge'] ?> badge-status"><?= $st['label'] ?></span>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <!-- Botão Ver Explosão de Materiais (BOM) -->
                                        <button type="button" class="btn btn-outline-secondary btn-ver-bom"
                                            data-op-id="<?= $op['id'] ?>" data-op-num="<?= $op['numero'] ?>"
                                            data-prod-id="<?= $op['produto_id'] ?>"
                                            data-qtd="<?= $op['quantidade_planejada'] ?>" title="Explosão de Insumos">
                                            <i class="bi bi-diagram-2"></i> BOM
                                        </button>

                                        <!-- Ação: Iniciar Produção -->
                                        <?php if ($op['status'] === 'planejada'): ?>
                                            <form method="POST" action="ordens_producao.php" class="d-inline">
                                                <input type="hidden" name="acao" value="iniciar_op">
                                                <input type="hidden" name="op_id" value="<?= $op['id'] ?>">
                                                <button type="submit" class="btn btn-outline-warning text-dark"
                                                    title="Iniciar Fabricação">
                                                    <i class="bi bi-play-fill"></i> Iniciar
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <!-- Ação: Finalizar / Apontar -->
                                        <?php if ($op['status'] === 'em_producao'): ?>
                                            <button type="button" class="btn btn-outline-success btn-apontar"
                                                data-op-id="<?= $op['id'] ?>" data-op-num="<?= $op['numero'] ?>"
                                                data-qtd="<?= $op['quantidade_planejada'] ?>" title="Apontar Produção">
                                                <i class="bi bi-check2-circle"></i> Apontar
                                            </button>
                                        <?php endif; ?>

                                        <!-- Ação: Cancelar -->
                                        <?php if (in_array($op['status'], ['planejada', 'em_producao'])): ?>
                                            <form method="POST" action="ordens_producao.php" class="d-inline"
                                                onsubmit="return confirm('Tem certeza que deseja cancelar esta OP?');">
                                                <input type="hidden" name="acao" value="cancelar_op">
                                                <input type="hidden" name="op_id" value="<?= $op['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger" title="Cancelar OP">
                                                    <i class="bi bi-x-circle"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL: NOVA OP                                           -->
<!-- ======================================================== -->
<div class="modal fade" id="modalNovaOP" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="ordens_producao.php" class="modal-content">
            <input type="hidden" name="acao" value="criar_op">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-clipboard2-plus text-success me-2"></i>Emitir Ordem de
                    Produção</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Fragrância (Produto):</label>
                    <select name="produto_id" class="form-select" required>
                        <option value="">Selecione o sabonete...</option>
                        <?php foreach ($produtos_select as $p): ?>
                            <option value="<?= $p['id'] ?>">[<?= $p['codigo'] ?>] <?= htmlspecialchars($p['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold">Quantidade Planejada:</label>
                        <input type="number" step="50" min="50" name="quantidade_planejada" class="form-control"
                            value="100" required>
                        <small class="text-muted">Lotes em múltiplos de 50 barras.</small>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Data Programada:</label>
                        <input type="date" name="data_programada" class="form-control" value="<?= date('Y-m-d') ?>"
                            required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Observações / Instruções PCP:</label>
                    <textarea name="observacoes" class="form-control" rows="2"
                        placeholder="Ex: Higienização prévia dos tachos obrigatória..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success"><i class="bi bi-check2 me-1"></i> Gravar Ordem</button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL: APONTAMENTO DE PRODUÇÃO & REFUGO                 -->
<!-- ======================================================== -->
<div class="modal fade" id="modalApontar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="ordens_producao.php" class="modal-content">
            <input type="hidden" name="acao" value="finalizar_op">
            <input type="hidden" name="op_id" id="apontar_op_id" value="">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-check2-circle text-success me-2"></i>Apontamento Final
                    de Produção</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 small mb-3">
                    Ordem: <strong id="apontar_op_num"></strong> | Planejado original: <strong
                        id="apontar_op_qtd"></strong> barras.
                </div>
                <p class="small text-muted mb-3">
                    Ao confirmar, o sistema dará entrada no estoque das barras boas e <strong>baixará automaticamente
                        todos os insumos</strong> proporcionais ao total processado (boas + refugo) via Ficha Técnica.
                </p>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold text-success">Barras Aprovadas (Boas):</label>
                        <input type="number" step="1" min="0" name="quantidade_produzida" id="apontar_boas"
                            class="form-control form-control-lg fw-bold" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold text-danger">Refugo (Perdas / Defeito):</label>
                        <input type="number" step="1" min="0" name="quantidade_refugo" id="apontar_refugo"
                            class="form-control form-control-lg fw-bold" value="0" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Voltar</button>
                <button type="submit" class="btn btn-success"><i class="bi bi-box-arrow-in-down me-1"></i> Concluir e
                    Baixar Estoque</button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL: EXPLOSÃO DE MATERIAIS DA OP (BOM EXPLOSION)       -->
<!-- ======================================================== -->
<div class="modal fade" id="modalBomExplosion" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-diagram-2 text-primary me-2"></i>Explosão da Lista de
                    Materiais (BOM)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="conteudoBomExplosion">
                <div class="text-center py-4">
                    <div class="spinner-border text-success" role="status"></div>
                    <p class="small text-muted mt-2">Calculando necessidades de insumos...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // 1. Script para Modal de Apontamento
        const modalApontar = new bootstrap.Modal(document.getElementById('modalApontar'));
        document.querySelectorAll('.btn-apontar').forEach(btn => {
            btn.addEventListener('click', function () {
                document.getElementById('apontar_op_id').value = this.dataset.opId;
                document.getElementById('apontar_op_num').textContent = this.dataset.opNum;
                document.getElementById('apontar_op_qtd').textContent = this.dataset.qtd;
                document.getElementById('apontar_boas').value = this.dataset.qtd;
                document.getElementById('apontar_refugo').value = 0;
                modalApontar.show();
            });
        });

        // 2. Script para Modal de Explosão de BOM (Calculado em tempo real)
        const modalBom = new bootstrap.Modal(document.getElementById('modalBomExplosion'));
        document.querySelectorAll('.btn-ver-bom').forEach(btn => {
            btn.addEventListener('click', function () {
                const prodId = this.dataset.prodId;
                const opQtd = parseInt(this.dataset.qtd, 10);
                const opNum = this.dataset.opNum;
                const container = document.getElementById('conteudoBomExplosion');

                container.innerHTML = `
                <div class="alert alert-secondary py-2 small mb-3">
                    Simulação para <strong>${opNum}</strong>: Lote de <strong>${opQtd} barras</strong>.
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-light small text-uppercase">
                            <tr>
                                <th>Insumo</th>
                                <th>Consumo / Barra</th>
                                <th>Necessidade Total</th>
                                <th>Estoque Atual</th>
                                <th>Saldo Projetado</th>
                                <th>Situação PCP</th>
                            </tr>
                        </thead>
                        <tbody id="tabelaBomBody">
                            <tr><td colspan="6" class="text-center py-3 text-muted">Carregando...</td></tr>
                        </tbody>
                    </table>
                </div>
            `;
                modalBom.show();

                // Fetch AJAX leve para obter a BOM do produto
                fetch(`../api/op_api.php?action=get_bom&produto_id=${prodId}&qtd=${opQtd}`)
                    .then(res => res.json())
                    .then(data => {
                        const tbody = document.getElementById('tabelaBomBody');
                        if (!data.success || data.items.length === 0) {
                            tbody.innerHTML =
                                '<tr><td colspan="6" class="text-center text-danger py-3">Ficha técnica não definida para este sabonete!</td></tr>';
                            return;
                        }

                        let html = '';
                        let ruptura = false;

                        data.items.forEach(item => {
                            const nec = item.qtd_por_barra * opQtd;
                            const saldo = item.estoque_atual - nec;
                            const falta = saldo < 0;
                            if (falta) ruptura = true;

                            html += `
                            <tr class="${falta ? 'table-danger' : ''}">
                                <td><strong>${item.nome}</strong></td>
                                <td class="font-monospace">${parseFloat(item.qtd_por_barra).toFixed(4)} ${item.unidade}</td>
                                <td class="font-monospace fw-bold">${nec.toFixed(4)} ${item.unidade}</td>
                                <td class="font-monospace">${parseFloat(item.estoque_atual).toFixed(4)} ${item.unidade}</td>
                                <td class="font-monospace fw-bold ${falta ? 'text-danger' : 'text-success'}">${saldo.toFixed(4)} ${item.unidade}</td>
                                <td>
                                    ${falta
                                    ? '<span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i> Ruptura! Falta material</span>'
                                    : '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i> Disponível</span>'}
                                </td>
                            </tr>
                        `;
                        });

                        tbody.innerHTML = html;
                    })
                    .catch(() => {
                        document.getElementById('tabelaBomBody').innerHTML =
                            '<tr><td colspan="6" class="text-center text-danger py-3">Erro ao processar cálculo da BOM.</td></tr>';
                    });
            });
        });
    });
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';