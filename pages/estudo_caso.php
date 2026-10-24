<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';

$page_title = 'Estudo de Caso & Ferramentas Lean';

// -------------------------------------------------------------
// DADOS DA CURVA ABC OFICIAL (Pág. 6 do PDF)
// -------------------------------------------------------------
$itens_abc = [
    ['insumo' => 'Base de Sabão Neutra (Kg)',   'custo_unit' => 15.00,  'consumo' => 1500, 'total' => 22500.00],
    ['insumo' => 'Embalagens (Caixas)',          'custo_unit' => 0.50,   'consumo' => 7000, 'total' => 3500.00],
    ['insumo' => 'Óleo Essencial de Lavanda',    'custo_unit' => 80.00,  'consumo' => 40,   'total' => 3200.00],
    ['insumo' => 'Óleo Essencial de Rosas',      'custo_unit' => 100.00, 'consumo' => 20,   'total' => 2000.00],
    ['insumo' => 'Óleo Essencial de Maracujá',   'custo_unit' => 50.00,  'consumo' => 35,   'total' => 1750.00],
    ['insumo' => 'Óleo Essencial de Pitanga',    'custo_unit' => 40.00,  'consumo' => 30,   'total' => 1200.00],
    ['insumo' => 'Corantes Naturais',            'custo_unit' => 10.00,  'consumo' => 50,   'total' => 500.00]
];

$valor_total_anual = array_sum(array_column($itens_abc, 'total')); // R$ 34.650,00

// Ordenar decrescente pelo valor total
usort($itens_abc, fn($a, $b) => $b['total'] <=> $a['total']);

$acumulado_valor = 0.0;
foreach ($itens_abc as &$it) {
    $acumulado_valor += $it['total'];
    $it['perc_individual'] = ($it['total'] / $valor_total_anual) * 100;
    $it['perc_acumulado']  = ($acumulado_valor / $valor_total_anual) * 100;

    // Regra Pareto ABC: até ~70% = A, até ~90% = B, restante = C
    if ($it['perc_acumulado'] <= 70) {
        $it['classe'] = 'A';
        $it['badge'] = 'danger';
    } elseif ($it['perc_acumulado'] <= 92) {
        $it['classe'] = 'B';
        $it['badge'] = 'warning text-dark';
    } else {
        $it['classe'] = 'C';
        $it['badge'] = 'info text-dark';
    }
}
unset($it);

// -------------------------------------------------------------
// DADOS DA DEMANDA HISTÓRICA DO ANO ANTERIOR (Pág. 3 do PDF)
// -------------------------------------------------------------
$meses = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
$demanda = [
    'Pitanga'  => [450, 420, 480, 500, 550, 490, 520, 510, 530, 560, 600, 700],
    'Lavanda'  => [320, 300, 350, 400, 600, 420, 450, 430, 460, 480, 700, 850],
    'Rosas'    => [280, 270, 300, 350, 580, 380, 400, 390, 420, 450, 650, 800],
    'Maracujá' => [410, 400, 430, 450, 480, 440, 470, 460, 480, 500, 550, 620]
];

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="container-fluid px-4 pb-4">
    <!-- Cabeçalho -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-1 text-dark">
                <i class="bi bi-mortarboard text-success me-2"></i>Estudo de Caso Flora Sabão & Lean
            </h2>
            <p class="text-muted mb-0">Parametrizações oficiais: Curva ABC de Insumos, Tempos Padrão, Demanda e Soluções
                de Qualidade.</p>
        </div>
    </div>

    <!-- Navegação em Abas -->
    <ul class="nav nav-pills mb-4" id="pills-tab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active fw-semibold" id="tab-abc" data-bs-toggle="pill" data-bs-target="#pane-abc"
                type="button">
                <i class="bi bi-pie-chart me-1"></i> Curva ABC (Pareto)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-semibold" id="tab-demanda" data-bs-toggle="pill" data-bs-target="#pane-demanda"
                type="button">
                <i class="bi bi-graph-up me-1"></i> Demanda Histórica & Estoque Inicial
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-semibold" id="tab-postos" data-bs-toggle="pill" data-bs-target="#pane-postos"
                type="button">
                <i class="bi bi-stopwatch me-1"></i> Balanceamento & Gargalo (70 min)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-semibold" id="tab-lean" data-bs-toggle="pill" data-bs-target="#pane-lean"
                type="button">
                <i class="bi bi-check2-circle me-1"></i> Soluções Lean (5S, Jidoka, Poka-Yoke)
            </button>
        </li>
    </ul>

    <div class="tab-content" id="pills-tabContent">
        <!-- ABA 1: CURVA ABC -->
        <div class="tab-pane fade show active" id="pane-abc">
            <div class="row g-3 mb-4">
                <div class="col-12 col-md-4">
                    <div class="card card-custom p-3 bg-white h-100 border-start border-4 border-danger">
                        <span class="text-muted small text-uppercase fw-semibold">Classe A (Críticos / Alto
                            Valor)</span>
                        <h4 class="fw-bold my-1 text-danger">Base de Sabão Neutra</h4>
                        <small class="text-muted">Representa <strong>64,9%</strong> do orçamento anual em
                            insumos.</small>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card card-custom p-3 bg-white h-100 border-start border-4 border-warning">
                        <span class="text-muted small text-uppercase fw-semibold">Classe B (Controle
                            Intermediário)</span>
                        <h4 class="fw-bold my-1 text-warning-emphasis">Caixas, Lavanda e Rosas</h4>
                        <small class="text-muted">Somam ~25% do valor financeiro imobilizado.</small>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card card-custom p-3 bg-white h-100 border-start border-4 border-info">
                        <span class="text-muted small text-uppercase fw-semibold">Classe C (Baixo Impacto
                            Financeiro)</span>
                        <h4 class="fw-bold my-1 text-info">Maracujá, Pitanga e Corantes</h4>
                        <small class="text-muted">Menos de 10% do custo anual total.</small>
                    </div>
                </div>
            </div>

            <div class="card card-custom bg-white p-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0 text-dark">Matriz de Classificação ABC de Insumos</h5>
                    <span class="badge bg-light text-dark border">Consumo Total Anual: R$
                        <?= number_format($valor_total_anual, 2, ',', '.') ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-uppercase">
                            <tr>
                                <th>Insumo</th>
                                <th>Custo Unit. (R$)</th>
                                <th>Consumo Anual</th>
                                <th>Valor Total Anual</th>
                                <th>% Individual</th>
                                <th>% Acumulado</th>
                                <th class="text-center">Classe</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($itens_abc as $it): ?>
                            <tr>
                                <td class="fw-bold"><?= htmlspecialchars($it['insumo']) ?></td>
                                <td>R$ <?= number_format($it['custo_unit'], 2, ',', '.') ?></td>
                                <td><?= number_format($it['consumo'], 0, ',', '.') ?></td>
                                <td class="fw-bold text-dark">R$ <?= number_format($it['total'], 2, ',', '.') ?></td>
                                <td><?= number_format($it['perc_individual'], 1, ',', '.') ?>%</td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="progress flex-grow-1" style="height: 6px;">
                                            <div class="progress-bar bg-success"
                                                style="width: <?= $it['perc_acumulado'] ?>%"></div>
                                        </div>
                                        <small
                                            class="text-muted"><?= number_format($it['perc_acumulado'], 1, ',', '.') ?>%</small>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $it['badge'] ?> px-3 py-1 fs-6">Classe
                                        <?= $it['classe'] ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ABA 2: DEMANDA HISTÓRICA -->
        <div class="tab-pane fade" id="pane-demanda">
            <div class="card card-custom bg-white p-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0 text-dark">Demanda Histórica do Ano Anterior (unidades/mês)</h5>
                    <span class="badge bg-success">Estoque Inicial em Janeiro: 150 unidades por fragrância</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm text-center align-middle mb-0">
                        <thead class="table-light small text-uppercase">
                            <tr>
                                <th class="text-start">Fragrância</th>
                                <?php foreach ($meses as $m): ?>
                                <th><?= substr($m, 0, 3) ?></th>
                                <?php endforeach; ?>
                                <th class="table-secondary">Total Anual</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($demanda as $frag => $valores): ?>
                            <tr>
                                <td class="fw-bold text-start"><?= $frag ?></td>
                                <?php foreach ($valores as $val): ?>
                                <td><?= $val ?></td>
                                <?php endforeach; ?>
                                <td class="fw-bold table-secondary">
                                    <?= number_format(array_sum($valores), 0, ',', '.') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="alert alert-info py-2 small mt-3 mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong>Picos de Sazonalidade:</strong> Note os aumentos em <strong>Maio (Dia das Mães)</strong> e
                    <strong>Novembro/Dezembro (Natal e Festas)</strong>.
                </div>
            </div>
        </div>

        <!-- ABA 3: TEMPOS E GARGALO -->
        <div class="tab-pane fade" id="pane-postos">
            <div class="card card-custom bg-white p-3">
                <h5 class="fw-bold mb-3 text-dark">Tempos Padrão do Processo por Lote (100 Barras)</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-uppercase">
                            <tr>
                                <th>Posto</th>
                                <th>Etapa Produtiva</th>
                                <th>Tempo Médio</th>
                                <th>Ocupação</th>
                                <th>Diagnóstico</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (TEMPOS_POSTOS_MIN as $posto => $info):
                                $is_gargalo = ($info['tempo'] === TEMPO_GARGALO_LOTE_MIN);
                            ?>
                            <tr class="<?= $is_gargalo ? 'table-warning fw-bold' : '' ?>">
                                <td>Posto <?= $posto ?></td>
                                <td><?= $info['nome'] ?></td>
                                <td class="font-monospace fs-6"><?= $info['tempo'] ?> min</td>
                                <td>
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar <?= $is_gargalo ? 'bg-danger' : 'bg-success' ?>"
                                            style="width: <?= ($info['tempo'] / TEMPO_GARGALO_LOTE_MIN) * 100 ?>%">
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($is_gargalo): ?>
                                    <span class="badge bg-danger"><i class="bi bi-cone-striped me-1"></i>GARGALO DA
                                        LINHA</span>
                                    <?php else: ?>
                                    <span class="badge bg-light text-dark border">Folga:
                                        <?= TEMPO_GARGALO_LOTE_MIN - $info['tempo'] ?> min</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="alert alert-warning py-2 small mt-3 mb-0">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    <strong>Capacidade Máxima Teórica:</strong> O Posto 3 (Moldagem) dita o ritmo da fábrica inteira (1
                    lote a cada 70 minutos). Em uma jornada diária de 8 horas produtivas, a capacidade máxima é de ~680
                    barras/dia.
                </div>
            </div>
        </div>

        <!-- ABA 4: LEAN & QUALIDADE -->
        <div class="tab-pane fade" id="pane-lean">
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <div class="card card-custom p-3 bg-white h-100">
                        <h6 class="fw-bold text-success"><i class="bi bi-recycle me-1"></i> Programa 5S (Organização)
                        </h6>
                        <p class="small text-muted mb-2"><strong>Problema:</strong> Bancadas desorganizadas e insumos
                            vencidos gerando perdas e risco de contaminação.</p>
                        <p class="small text-dark mb-0"><strong>Solução:</strong> Implementação do <em>Seiri</em>
                            (Descarte de vencidos), <em>Seiton</em> (Identificação por cores para cada fragrância) e
                            <em>Seiketsu</em> (Checklist diário de inspeção antes de iniciar o lote).</p>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card card-custom p-3 bg-white h-100">
                        <h6 class="fw-bold text-primary"><i class="bi bi-gear-wide-connected me-1"></i> Jidoka (Corte
                            Automático)</h6>
                        <p class="small text-muted mb-2"><strong>Problema:</strong> 5% dos sabonetes são cortados fora
                            do padrão (muito grandes ou pequenos).</p>
                        <p class="small text-dark mb-0"><strong>Solução:</strong> Instalação de sensor óptico
                            dimensional na saída da lâmina de corte. Se a barra desviar de 100g ± 3g, a linha para
                            imediatamente (autonomação) com aviso sonoro.</p>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card card-custom p-3 bg-white h-100">
                        <h6 class="fw-bold text-danger"><i class="bi bi-shield-check me-1"></i> Poka Yoke (Moldes à
                            Prova de Erros)</h6>
                        <p class="small text-muted mb-2"><strong>Problema:</strong> Vazamento da massa líquida por
                            desalinhamento dos moldes (3x por turno).</p>
                        <p class="small text-dark mb-0"><strong>Solução:</strong> Guia mecânica de encaixe com
                            pino-trava obrigatório. A válvula de vazamento só abre se o molde estiver 100% encaixado na
                            posição correta.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';