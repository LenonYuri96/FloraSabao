<?php
declare(strict_types=1);

/**
 * Flora Sabão — Copiloto IA de PCP & Estudo de Caso
 * -------------------------------------------------------------
 * Diagnóstico inteligente em tempo real e Dilemas Lean/TOC.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

$page_title = 'Copiloto IA — Consultoria de PCP & Lean';

$equipe_id = getEquipeAtivaId();
$rodada_ativa = getRodadaAtiva($pdo);

// Busca dados da equipe ativa
$stmt_eq = $pdo->prepare("SELECT id, nome, cor_hex, score_total, caixa_disponivel FROM equipes WHERE id = ?");
$stmt_eq->execute([$equipe_id]);
$equipe_dados = $stmt_eq->fetch() ?: [
    'id' => $equipe_id,
    'nome' => 'Equipe ' . $equipe_id,
    'cor_hex' => '#2d5a3f',
    'score_total' => 0,
    'caixa_disponivel' => 15000.00
];

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="container-fluid px-4 pb-4">
    <!-- Cabeçalho -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge text-white px-3 py-1 font-monospace" style="background-color: <?= htmlspecialchars($equipe_dados['cor_hex']) ?>;">
                    <i class="bi bi-shield-shaded me-1"></i><?= htmlspecialchars($equipe_dados['nome']) ?>
                </span>
                <span class="badge bg-secondary-subtle text-secondary border px-2 py-1">
                    <?= htmlspecialchars($rodada_ativa['mes_ano'] ?? 'Mês 01') ?>
                </span>
                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                    <i class="bi bi-cpu me-1"></i>Motor Heurístico Ativo
                </span>
            </div>
            <h2 class="fw-bold mb-1 text-dark">
                <i class="bi bi-robot text-success me-2"></i>Copiloto IA de PCP & Lean Manufacturing
            </h2>
            <p class="text-muted mb-0">Auditoria automatizada do plano fabril baseada nas diretrizes do Estudo de Caso Flora Sabão.</p>
        </div>
        <div>
            <button class="btn btn-success shadow-sm fw-bold px-3 py-2" onclick="executarAuditoriaIA()">
                <i class="bi bi-magic me-1"></i> Auditar Minha Fábrica Agora
            </button>
        </div>
    </div>

    <div class="row g-4">
        <!-- Coluna da Esquerda: Diagnóstico da Manufatura -->
        <div class="col-12 col-lg-7">
            <div class="card card-custom bg-white p-4 h-100 shadow-sm">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-clipboard2-pulse me-2 text-danger"></i>Diagnóstico da Linha de Produção
                    </h5>
                    <span class="badge bg-light text-dark border">4 Pilares Lean</span>
                </div>
                <p class="small text-muted mb-3">
                    A IA analisa a carga do <strong>Posto 3 (Gargalo - 70 min)</strong>, a conformidade com a 
                    <strong>Regra de Ouro Olfativa</strong>, itens <strong>Classe A de Pareto</strong> e risco de <strong>OTIF</strong>.
                </p>

                <!-- Estado de Carregamento -->
                <div id="loadingIA" class="text-center py-5 d-none">
                    <div class="spinner-border text-success mb-2" role="status"></div>
                    <p class="small text-muted fw-semibold">Consultando base de dados e auditando parâmetros com a IA...</p>
                </div>

                <!-- Painel de Resultados da IA -->
                <div id="resultadoIA" class="d-flex flex-column gap-3">
                    <div class="alert alert-light border text-center py-5 text-muted">
                        <i class="bi bi-cpu fs-1 d-block mb-2 text-success opacity-75"></i>
                        <h6 class="fw-bold text-dark">Nenhuma auditoria executada nesta sessão.</h6>
                        <span class="small">Clique no botão <strong>"Auditar Minha Fábrica Agora"</strong> acima para gerar o relatório da sua equipe.</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Coluna da Direita: Desafios do Estudo de Caso -->
        <div class="col-12 col-lg-5">
            <div class="card card-custom bg-white p-4 h-100 shadow-sm border-start border-4 border-warning">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-mortarboard me-2 text-warning"></i>Dilemas do Caso de Estudo
                    </h5>
                    <span class="badge bg-warning text-dark">+50 pts por acerto</span>
                </div>
                <p class="small text-muted mb-3">
                    Responda aos dilemas práticos de engenharia de produção. Respostas aprovadas pela IA creditam pontos diretamente no score da equipe!
                </p>

                <div class="accordion" id="accordionDilemas">
                    <!-- Dilema 1: TOC e Posto Gargalo -->
                    <div class="accordion-item border rounded-3 mb-2 shadow-sm">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#dil1">
                                <i class="bi bi-hourglass-split text-danger me-2"></i>Dilema 1: Gargalo do Posto 3 (70 min)
                            </button>
                        </h2>
                        <div id="dil1" class="accordion-collapse collapse" data-bs-parent="#accordionDilemas">
                            <div class="accordion-body small bg-light">
                                <p class="text-dark mb-2">
                                    O Posto 3 (Moldagem) consome 70 minutos por lote de 100 barras, ditando a capacidade máxima da fábrica. 
                                    <strong>Qual a diretriz de PCP para evitar que esse posto gere atrasos nas entregas (OTIF)?</strong>
                                </p>
                                <textarea id="respDil1" class="form-control form-control-sm mb-2" rows="3" placeholder="Explique considerando tamanho de lote, pulmão de estoque ou programação..."></textarea>
                                <button class="btn btn-sm btn-dark w-100 fw-semibold" onclick="enviarRespostaDilema(1)">
                                    <i class="bi bi-send me-1"></i> Submeter Resposta para Avaliação da IA
                                </button>
                                <div id="feedbackDil1" class="mt-2"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Dilema 2: Sequenciamento Olfativo -->
                    <div class="accordion-item border rounded-3 mb-2 shadow-sm">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#dil2">
                                <i class="bi bi-arrow-repeat text-success me-2"></i>Dilema 2: Regra de Ouro Olfativa
                            </button>
                        </h2>
                        <div id="dil2" class="accordion-collapse collapse" data-bs-parent="#accordionDilemas">
                            <div class="accordion-body small bg-light">
                                <p class="text-dark mb-2">
                                    Por que produzir Rosas ou Lavanda antes de Maracujá eleva o tempo de troca de 20 para 45 minutos? 
                                    <strong>Qual é a ordem correta de fabricação?</strong>
                                </p>
                                <textarea id="respDil2" class="form-control form-control-sm mb-2" rows="3" placeholder="Indique a sequência correta e a justificativa técnica de sanitização..."></textarea>
                                <button class="btn btn-sm btn-dark w-100 fw-semibold" onclick="enviarRespostaDilema(2)">
                                    <i class="bi bi-send me-1"></i> Submeter Resposta para Avaliação da IA
                                </button>
                                <div id="feedbackDil2" class="mt-2"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Dilema 3: Curva ABC -->
                    <div class="accordion-item border rounded-3 mb-2 shadow-sm">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#dil3">
                                <i class="bi bi-pie-chart text-primary me-2"></i>Dilema 3: Priorização na Curva ABC
                            </button>
                        </h2>
                        <div id="dil3" class="accordion-collapse collapse" data-bs-parent="#accordionDilemas">
                            <div class="accordion-body small bg-light">
                                <p class="text-dark mb-2">
                                    No caso Flora Sabão, a Base Glicerinada representa 64,9% do orçamento anual de insumos. 
                                    <strong>Qual política de estoque deve ser aplicada a ela versus aos corantes (Classe C)?</strong>
                                </p>
                                <textarea id="respDil3" class="form-control form-control-sm mb-2" rows="3" placeholder="Diferencie o controle rigoroso da Classe A em relação à Classe C..."></textarea>
                                <button class="btn btn-sm btn-dark w-100 fw-semibold" onclick="enviarRespostaDilema(3)">
                                    <i class="bi bi-send me-1"></i> Submeter Resposta para Avaliação da IA
                                </button>
                                <div id="feedbackDil3" class="mt-2"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="alert alert-light border small text-muted mt-3 mb-0">
                    <i class="bi bi-info-circle me-1 text-primary"></i>
                    A pontuação obtida é somada em tempo real e reflete imediatamente no <strong>Leaderboard Geral</strong>.
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function executarAuditoriaIA() {
    const loading = document.getElementById('loadingIA');
    const resultado = document.getElementById('resultadoIA');
    loading.classList.remove('d-none');
    resultado.innerHTML = '';

    fetch('../api/ia_api.php?action=auditar_fabrica')
        .then(res => {
            if (!res.ok) throw new Error('Endpoint não encontrado ou erro de rede (status: ' + res.status + ')');
            return res.json();
        })
        .then(data => {
            loading.classList.add('d-none');
            if (!data.success || !data.diagnosticos || data.diagnosticos.length === 0) {
                resultado.innerHTML = '<div class="alert alert-info py-3"><i class="bi bi-check2-circle me-2"></i>Nenhum desvio crítico detectado na programação desta equipe.</div>';
                return;
            }

            let html = '';
            data.diagnosticos.forEach(d => {
                const icones = {
                    danger: 'bi-exclamation-octagon-fill text-danger',
                    warning: 'bi-exclamation-triangle-fill text-warning',
                    info: 'bi-info-circle-fill text-primary',
                    success: 'bi-check-circle-fill text-success'
                };
                const icone = icones[d.tipo] || 'bi-info-circle-fill';

                html += `
                    <div class="alert alert-${d.tipo} border shadow-sm mb-2">
                        <div class="d-flex align-items-start gap-2">
                            <i class="bi ${icone} fs-5 mt-1"></i>
                            <div>
                                <strong class="d-block mb-1">${d.pilar}</strong>
                                <span class="small">${d.mensagem}</span>
                            </div>
                        </div>
                    </div>
                `;
            });
            resultado.innerHTML = html;
        })
        .catch(err => {
            loading.classList.add('d-none');
            resultado.innerHTML = `
                <div class="alert alert-danger py-3">
                    <i class="bi bi-x-circle-fill me-2"></i>
                    <strong>Erro ao conectar com a IA:</strong> ${err.message}. 
                    Certifique-se de que o arquivo <code>api/ia_api.php</code> foi criado na pasta correta.
                </div>
            `;
        });
}

function enviarRespostaDilema(dilemaId) {
    const campo = document.getElementById('respDil' + dilemaId);
    const feedback = document.getElementById('feedbackDil' + dilemaId);
    const texto = campo.value.trim();

    if (!texto) {
        alert('Por favor, digite sua análise técnica antes de submeter.');
        return;
    }

    feedback.innerHTML = '<div class="text-muted small mt-2"><span class="spinner-border spinner-border-sm me-1"></span>Avaliando resposta com a IA...</div>';

    const formData = new FormData();
    formData.append('dilema_id', dilemaId);
    formData.append('resposta', texto);

    fetch('../api/ia_api.php?action=resolver_dilema', {
        method: 'POST',
        body: formData
    })
    .then(res => {
        if (!res.ok) throw new Error('Erro na comunicação com a API (status: ' + res.status + ')');
        return res.json();
    })
    .then(res => {
        feedback.innerHTML = `
            <div class="alert alert-${res.aprovado ? 'success' : 'warning'} py-2 small mb-0 mt-2">
                <strong>${res.aprovado ? '🎉 Aprovado (+ ' + res.pontos_ganhos + ' pts)!' : '⚠️ Atenção:'}</strong> ${res.feedback}
            </div>
        `;
    })
    .catch(err => {
        feedback.innerHTML = `<div class="alert alert-danger py-2 small mb-0 mt-2">${err.message}</div>`;
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>