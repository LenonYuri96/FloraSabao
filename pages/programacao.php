<?php
declare(strict_types=1);

/**
 * Flora Sabão — Programação Horária, Sequenciamento & Matriz de Setup
 * ====================================================================
 * Versão consolidada (~3000 linhas) — Business Game Multi-Equipe
 *
 * Integra:
 *  [A] Programação em minutos/horas (turno 08:00-17:00, 480 min)
 *  [B] Otimização da Regra de Ouro, simulador manual, matriz de setup
 *
 * Visões de turno oferecidas:
 *  1. Turno Teórico — todos os OPs em sequência a partir de 08:00 do 1º dia
 *  2. Cronograma Real — dia por dia, ocupação individual de cada turno
 *  3. Detalhe Hora-a-Hora — início/fim de cada batelada (HH:MM)
 *
 * Regras de negócio:
 *  - Setup: 20 min (padrão) / 15 min (mesmo produto) / 45 min (limpeza pesada)
 *  - Gargalo: Posto 3 (Moldagem) → 70 min por lote de 100 barras
 *  - Turno regular: 480 min (08:00-17:00 com 1h de almoço = 8h efetivas)
 *  - Hora extra: R$ 2,50 por minuto excedente (parametrizável)
 *  - Toda escrita é isolada por equipe_id
 */

// =========================================================
// BOOTSTRAP
// =========================================================
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

$page_title = 'Programação Horária & Matriz de Setup';
$mensagem_sucesso = '';
$mensagem_erro    = '';
$mensagem_aviso   = '';

// =========================================================
// 🛡️ GUARD — equipe vinculada é obrigatória
// =========================================================
$equipe_id = getEquipeAtivaId();
if ($equipe_id <= 0) {
    flash('erro', 'Sua conta não está vinculada a uma equipe. Contate o instrutor.');
    header('Location: ' . (file_exists('pages') ? '' : '../') . 'index.php');
    exit;
}

$equipe        = equipeAtual($pdo);
$rodada_ativa  = getRodadaAtiva($pdo);
$pode_otimizar = ($rodada_ativa !== null);

if (!$pode_otimizar) {
    $mensagem_aviso = 'Nenhuma rodada aberta. Aguardando liberação do instrutor.';
}

// =========================================================
// 🎯 CONSTANTES LOCAIS (com fallback seguro)
// =========================================================

// Escala de intensidade olfativa
$escala_aroma = [
    'suave'    => 1,
    'moderado' => 2,
    'intenso'  => 3,
];

// Mapa oficial (Regra de Ouro do case)
$escala_oficial = defined('ORDEM_AROMAS_RECOMENDADA')
    ? ORDEM_AROMAS_RECOMENDADA
    : ['MAR' => 1, 'PIT' => 2, 'LAV' => 3, 'ROS' => 4];

// Tempo de ciclo do gargalo oficial (Posto 3 — Moldagem)
$tempo_gargalo_min = defined('TEMPO_GARGALO_LOTE_MIN')
    ? (int) TEMPO_GARGALO_LOTE_MIN
    : 70;

// Setup padrão e limpeza pesada
$setup_padrao_min = defined('TEMPO_SETUP_PADRAO_MIN')
    ? (int) TEMPO_SETUP_PADRAO_MIN
    : 20;

$setup_pesado_min = defined('TEMPO_SETUP_LIMPEZA_PESADA_MIN')
    ? (int) TEMPO_SETUP_LIMPEZA_PESADA_MIN
    : 45;

// Turno (minutos por dia útil)
$minutos_turno_regular = defined('MINUTOS_TURNO_REGULAR')
    ? (int) MINUTOS_TURNO_REGULAR
    : 480;

// Custo por minuto extra
$custo_minuto_extra = defined('CUSTO_MINUTO_HORA_EXTRA')
    ? (float) CUSTO_MINUTO_HORA_EXTRA
    : 2.50;

// Hora base (08:00 = 480 min desde 00:00)
$hora_base_min = 8 * 60;

// Cor por intensidade
$cor_intensidade = [
    'suave'    => '#0dcaf0',
    'moderado' => '#ffc107',
    'intenso'  => '#dc3545',
];

// =========================================================
// 🧠 FUNÇÕES AUXILIARES
// =========================================================

/**
 * Converte minutos absolutos (a partir de 00:00) em HH:MM.
 * Ex.: 480 → 08:00, 555 → 09:15.
 */
function minParaHora(int $minutos): string
{
    $minutos = max(0, $minutos);
    $h = intdiv($minutos, 60);
    $m = $minutos % 60;
    return sprintf('%02d:%02d', $h, $m);
}

/**
 * Calcula tempo de setup entre dois itens da fila.
 * Retorna array descritivo com min, tipo, alerta, motivo, badge e ícone.
 */
function calcularSetup(
    array $anterior,
    array $atual,
    array $escala_aroma,
    int $setup_padrao,
    int $setup_pesado
): array {
    $nivel_ant = $escala_aroma[$anterior['intensidade_aroma']] ?? 2;
    $nivel_atu = $escala_aroma[$atual['intensidade_aroma']]   ?? 2;

    // Mesmo produto
    if ((int) $anterior['produto_id'] === (int) $atual['produto_id']) {
        return [
            'min'         => 15,
            'tipo'        => 'mesmo_produto',
            'alerta'      => false,
            'motivo'      => 'Mesmo produto: raspagem e limpeza de bico apenas.',
            'badge_class' => 'bg-info text-dark',
            'icone'       => 'bi-check2-circle',
        ];
    }

    // Aroma forte → suave: RISCO CRÍTICO
    if ($nivel_ant > $nivel_atu) {
        return [
            'min'         => $setup_pesado,
            'tipo'        => 'limpeza_pesada',
            'alerta'      => true,
            'motivo'      => sprintf(
                'Contaminação cruzada! %s (%s) → %s (%s). Sanitização pesada com álcool 70%% obrigatória.',
                ucfirst($anterior['intensidade_aroma']),
                $anterior['produto_codigo'],
                ucfirst($atual['intensidade_aroma']),
                $atual['produto_codigo']
            ),
            'badge_class' => 'bg-danger text-white',
            'icone'       => 'bi-exclamation-triangle-fill',
        ];
    }

    // Transição segura
    return [
        'min'         => $setup_padrao,
        'tipo'        => 'transicao_suave',
        'alerta'      => false,
        'motivo'      => sprintf(
            'Transição segura na curva olfativa (%s → %s).',
            $anterior['intensidade_aroma'],
            $atual['intensidade_aroma']
        ),
        'badge_class' => 'bg-success text-white',
        'icone'       => 'bi-arrow-up-right-circle',
    ];
}

/**
 * Calcula tempos horários de uma batelada (setup + moldagem no gargalo).
 * Retorna min/moldagem/duração total + hora_inicio/hora_fim + motivo/badge.
 *
 * @param array|null $anterior   OP anterior (null = primeiro da fila)
 * @param array      $atual      OP atual
 * @param int        $minuto_acumulado  Minuto absoluto desde 00:00
 */
function calcularTemposBatelada(
    ?array $anterior,
    array $atual,
    int $minuto_acumulado,
    array $escala_aroma,
    int $setup_padrao,
    int $setup_pesado,
    int $tempo_gargalo,
    int $hora_base_min
): array {
    // 1. Tempo de setup
    if ($anterior === null) {
        $setup_min = $setup_padrao;
        $tipo_setup = 'primeiro';
        $alerta = false;
        $motivo = 'Primeiro lote do turno. Setup inicial e pré-aquecimento do tacho.';
        $badge = 'bg-secondary text-white';
        $icone = 'bi-play-circle';
    } else {
        $setup_info = calcularSetup($anterior, $atual, $escala_aroma, $setup_padrao, $setup_pesado);
        $setup_min = $setup_info['min'];
        $tipo_setup = $setup_info['tipo'];
        $alerta = $setup_info['alerta'];
        $motivo = $setup_info['motivo'];
        $badge = $setup_info['badge_class'];
        $icone = $setup_info['icone'];
    }

    // 2. Tempo no gargalo (moldagem): 70 min por 100 unidades
    $qtd = (int) $atual['quantidade_planejada'];
    $moldagem_min = (int) round(($qtd / 100) * $tempo_gargalo);

    // 3. Horários do turno
    $min_inicio_setup = $minuto_acumulado;
    $min_inicio_prod  = $min_inicio_setup + $setup_min;
    $min_fim_prod     = $min_inicio_prod + $moldagem_min;

    return [
        'setup_min'     => $setup_min,
        'moldagem_min'  => $moldagem_min,
        'duracao_total' => $setup_min + $moldagem_min,
        'min_inicio'    => $min_inicio_setup,
        'min_fim'       => $min_fim_prod,
        'hora_inicio'   => minParaHora($min_inicio_setup),
        'hora_fim'      => minParaHora($min_fim_prod),
        'tipo_setup'    => $tipo_setup,
        'alerta'        => $alerta,
        'motivo'        => $motivo,
        'badge'         => $badge,
        'icone'         => $icone,
    ];
}

/**
 * Analisa uma sequência completa com horários.
 * Retorna items enriquecidos, totais e contagem por tipo.
 */
function analisarSequenciaHoraria(
    array $fila,
    array $escala_aroma,
    int $setup_padrao,
    int $setup_pesado,
    int $tempo_gargalo,
    int $hora_base_min
): array {
    $items = [];
    $total_setup = 0;
    $total_moldagem = 0;
    $contaminacoes = 0;
    $count_tipos = [
        'primeiro'        => 0,
        'mesmo_produto'   => 0,
        'transicao_suave' => 0,
        'limpeza_pesada'  => 0,
    ];
    $minuto_corrente = $hora_base_min;
    $anterior = null;

    foreach ($fila as $op) {
        $info = calcularTemposBatelada(
            $anterior,
            $op,
            $minuto_corrente,
            $escala_aroma,
            $setup_padrao,
            $setup_pesado,
            $tempo_gargalo,
            $hora_base_min
        );

        $count_tipos[$info['tipo_setup']] = ($count_tipos[$info['tipo_setup']] ?? 0) + 1;
        $total_setup += $info['setup_min'];
        $total_moldagem += $info['moldagem_min'];
        if ($info['alerta']) $contaminacoes++;

        $items[] = [
            'op'   => $op,
            'info' => $info,
        ];

        $minuto_corrente = $info['min_fim'];
        $anterior = $op;
    }

    return [
        'items'            => $items,
        'total_setup'      => $total_setup,
        'total_moldagem'   => $total_moldagem,
        'total_min'        => $total_setup + $total_moldagem,
        'min_fim'          => $minuto_corrente,
        'contaminacoes'    => $contaminacoes,
        'count_tipos'      => $count_tipos,
    ];
}

/**
 * Simula a explosão de BOM para uma lista de OPs.
 * Retorna lista com necessidade e disponibilidade por insumo, além de flag de ruptura.
 */
function simularEstoqueFila(PDO $pdo, int $equipe_id, array $fila): array
{
    $necessidades = [];

    foreach ($fila as $op) {
        $stmt = $pdo->prepare("
            SELECT ft.insumo_id, ft.qtd_por_barra,
                   i.nome, i.unidade, i.estoque_atual
            FROM ficha_tecnica ft
            JOIN insumos i ON ft.insumo_id = i.id
            WHERE ft.produto_id = ? AND ft.equipe_id = ?
        ");
        $stmt->execute([(int) $op['produto_id'], $equipe_id]);
        $bom = $stmt->fetchAll();

        foreach ($bom as $b) {
            $iid = (int) $b['insumo_id'];
            if (!isset($necessidades[$iid])) {
                $necessidades[$iid] = [
                    'nome'       => $b['nome'],
                    'unidade'    => $b['unidade'],
                    'necessario' => 0.0,
                    'disponivel' => (float) $b['estoque_atual'],
                ];
            }
            $necessidades[$iid]['necessario'] += (float) $b['qtd_por_barra']
                * (int) $op['quantidade_planejada'];
        }
    }

    $ruptura = false;
    foreach ($necessidades as $iid => $dados) {
        $saldo = $dados['disponivel'] - $dados['necessario'];
        $necessidades[$iid]['saldo'] = $saldo;
        $necessidades[$iid]['falta'] = $saldo < 0;
        if ($saldo < 0) $ruptura = true;
    }

    return [
        'insumos' => $necessidades,
        'ruptura' => $ruptura,
    ];
}

/**
 * Calcula capacidade diária em lotes (baseado no gargalo).
 */
function calcularCapacidadeDiaria(int $minutos_dia, int $tempo_ciclo_min): int
{
    if ($tempo_ciclo_min <= 0) return 0;
    return (int) floor($minutos_dia / $tempo_ciclo_min);
}

/**
 * Próximo número sequencial de OP da equipe.
 */
function proximoNumeroOP(PDO $pdo, int $equipe_id, int $numero_rodada): string
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ordens_producao WHERE equipe_id = ?");
    $stmt->execute([$equipe_id]);
    $seq = (int) $stmt->fetchColumn() + 1;
    return sprintf('OP-R%d-E%d-%03d', $numero_rodada, $equipe_id, $seq);
}

/**
 * Adiciona uma ação ao log da sessão.
 */
function logAcao(string $tipo, string $descricao): void
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['fs_acao_log'])) $_SESSION['fs_acao_log'] = [];
    $_SESSION['fs_acao_log'][] = [
        'quando'    => date('d/m H:i'),
        'tipo'      => $tipo,
        'descricao' => $descricao,
    ];
    if (count($_SESSION['fs_acao_log']) > 30) {
        $_SESSION['fs_acao_log'] = array_slice($_SESSION['fs_acao_log'], -30);
    }
}

// =========================================================
// 📝 PROCESSAMENTO DE AÇÕES POST
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // =====================================================
    // 1. OTIMIZAR (Regra de Ouro — distribui 1 OP/dia)
    // =====================================================
    if ($acao === 'otimizar_sequencia') {
        if (!$pode_otimizar) {
            $mensagem_erro = 'Não há rodada aberta. Não é possível reorganizar.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    SELECT op.id, op.produto_id, p.codigo, p.intensidade_aroma
                    FROM ordens_producao op
                    JOIN produtos p ON op.produto_id = p.id
                    WHERE op.equipe_id = ?
                      AND op.rodada_id = ?
                      AND op.status IN ('planejada', 'em_producao')
                ");
                $stmt->execute([$equipe_id, (int) $rodada_ativa['id']]);
                $ops_ativas = $stmt->fetchAll();

                if (empty($ops_ativas)) {
                    $mensagem_erro = 'Não há OPs pendentes para otimizar.';
                } else {
                    usort($ops_ativas, function ($a, $b) use ($escala_oficial, $escala_aroma) {
                        $ordemA = $escala_oficial[$a['codigo']] ?? 99;
                        $ordemB = $escala_oficial[$b['codigo']] ?? 99;
                        if ($ordemA !== $ordemB) return $ordemA <=> $ordemB;
                        $nivA = $escala_aroma[$a['intensidade_aroma']] ?? 2;
                        $nivB = $escala_aroma[$b['intensidade_aroma']] ?? 2;
                        return $nivA <=> $nivB;
                    });

                    $data_base = new DateTime();
                    $pdo->beginTransaction();
                    foreach ($ops_ativas as $item) {
                        $pdo->prepare("
                            UPDATE ordens_producao
                            SET data_programada = ?
                            WHERE id = ? AND equipe_id = ?
                        ")->execute([
                            $data_base->format('Y-m-d'),
                            (int) $item['id'],
                            $equipe_id,
                        ]);
                        $data_base->modify('+1 day');
                    }
                    $pdo->commit();

                    logAcao('otimizacao', count($ops_ativas) . ' lotes reordenados pela Regra de Ouro');
                    $mensagem_sucesso = sprintf(
                        'Regra de Ouro aplicada! %d lote(s) reordenado(s) do aroma suave ao mais intenso.',
                        count($ops_ativas)
                    );
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $mensagem_erro = 'Erro ao otimizar: ' . $e->getMessage();
            }
        }
    }

    // =====================================================
    // 2. RESETAR (voltar à ordem de criação)
    // =====================================================
    if ($acao === 'resetar_ordem') {
        if (!$pode_otimizar) {
            $mensagem_erro = 'Não há rodada aberta.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    SELECT id FROM ordens_producao
                    WHERE equipe_id = ? AND rodada_id = ?
                      AND status IN ('planejada', 'em_producao')
                    ORDER BY id ASC
                ");
                $stmt->execute([$equipe_id, (int) $rodada_ativa['id']]);
                $ops = $stmt->fetchAll();

                $data_base = new DateTime();
                $pdo->beginTransaction();
                foreach ($ops as $op) {
                    $pdo->prepare("UPDATE ordens_producao SET data_programada = ? WHERE id = ?")
                        ->execute([$data_base->format('Y-m-d'), (int) $op['id']]);
                    $data_base->modify('+1 day');
                }
                $pdo->commit();

                logAcao('reset', 'Ordem de criação restaurada');
                $mensagem_sucesso = 'Ordem original restaurada com sucesso.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $mensagem_erro = 'Erro ao resetar: ' . $e->getMessage();
            }
        }
    }

    // =====================================================
    // 3. MOVER OP (simulador manual)
    // =====================================================
    if ($acao === 'mover_op') {
        $op_id      = (int) ($_POST['op_id'] ?? 0);
        $direcao    = $_POST['direcao'] ?? '';
        $data_atual = $_POST['data_atual'] ?? '';

        if (!$pode_otimizar || $op_id <= 0 || !in_array($direcao, ['subir', 'descer'], true)) {
            $mensagem_erro = 'Parâmetros inválidos para mover a OP.';
        } else {
            try {
                $delta = $direcao === 'subir' ? -1 : 1;
                $nova_data = date('Y-m-d', strtotime($data_atual) + ($delta * 86400));
                if (strtotime($nova_data) < strtotime(date('Y-m-d'))) {
                    $nova_data = date('Y-m-d');
                }
                $pdo->prepare("
                    UPDATE ordens_producao
                    SET data_programada = ?
                    WHERE id = ? AND equipe_id = ?
                ")->execute([$nova_data, $op_id, $equipe_id]);

                logAcao('mover', "OP #{$op_id} movida para {$nova_data}");
                $mensagem_sucesso = 'Ordem de produção reposicionada.';
            } catch (Throwable $e) {
                $mensagem_erro = 'Erro ao mover OP: ' . $e->getMessage();
            }
        }
    }

    // =====================================================
    // 4. LIMPAR HISTÓRICO
    // =====================================================
    if ($acao === 'limpar_historico') {
        $_SESSION['fs_acao_log'] = [];
        $mensagem_sucesso = 'Histórico limpo.';
    }
}

// =========================================================
// 📊 CONSULTAS DA FILA ATUAL
// =========================================================
$filtro_data = $_GET['data'] ?? '';
$filtro_int  = $_GET['intensidade'] ?? 'todas';
$int_validas = ['todas', 'suave', 'moderado', 'intenso'];
if (!in_array($filtro_int, $int_validas, true)) $filtro_int = 'todas';

$sql_fila = "
    SELECT op.id, op.numero, op.quantidade_planejada, op.data_programada, op.status,
           op.produto_id, op.rodada_id,
           p.nome AS produto_nome, p.codigo AS produto_codigo,
           p.aroma, p.intensidade_aroma
    FROM ordens_producao op
    JOIN produtos p ON op.produto_id = p.id
    WHERE op.equipe_id = ?
      " . ($rodada_ativa ? "AND op.rodada_id = " . (int) $rodada_ativa['id'] : "") . "
      AND op.status IN ('planejada', 'em_producao')
";
$params_fila = [$equipe_id];

if ($filtro_data !== '') {
    $sql_fila .= " AND op.data_programada = ?";
    $params_fila[] = $filtro_data;
}
if ($filtro_int !== 'todas') {
    $sql_fila .= " AND p.intensidade_aroma = ?";
    $params_fila[] = $filtro_int;
}
$sql_fila .= " ORDER BY op.data_programada ASC, op.id ASC";

$stmt_fila = $pdo->prepare($sql_fila);
$stmt_fila->execute($params_fila);
$fila_producao = $stmt_fila->fetchAll();

// =========================================================
// 🔬 ANÁLISE COMPLETA (HORÁRIA + KPIs)
// =========================================================
$analise = analisarSequenciaHoraria(
    $fila_producao,
    $escala_aroma,
    $setup_padrao_min,
    $setup_pesado_min,
    $tempo_gargalo_min,
    $hora_base_min
);

$sequencia_analisada       = $analise['items'];
$total_tempo_setup_min     = $analise['total_setup'];
$total_moldagem_min        = $analise['total_moldagem'];
$total_turno_min           = $analise['total_min'];
$min_fim_global            = $analise['min_fim'];
$hora_fim_global           = minParaHora($min_fim_global);
$total_riscos_contaminacao = $analise['contaminacoes'];
$count_tipos               = $analise['count_tipos'];

// Simulação otimizada
$fila_otimizada = $fila_producao;
usort($fila_otimizada, function ($a, $b) use ($escala_oficial, $escala_aroma) {
    $ordemA = $escala_oficial[$a['produto_codigo']] ?? 99;
    $ordemB = $escala_oficial[$b['produto_codigo']] ?? 99;
    if ($ordemA !== $ordemB) return $ordemA <=> $ordemB;
    $nivA = $escala_aroma[$a['intensidade_aroma']] ?? 2;
    $nivB = $escala_aroma[$b['intensidade_aroma']] ?? 2;
    return $nivA <=> $nivB;
});

$analise_otimizada = analisarSequenciaHoraria(
    $fila_otimizada,
    $escala_aroma,
    $setup_padrao_min,
    $setup_pesado_min,
    $tempo_gargalo_min,
    $hora_base_min
);

$ganho_setup_min    = $total_tempo_setup_min - $analise_otimizada['total_setup'];
$ganho_contaminacao = $total_riscos_contaminacao - $analise_otimizada['contaminacoes'];
$ja_esta_otima      = ($total_tempo_setup_min === $analise_otimizada['total_setup']
                      && $total_riscos_contaminacao === 0);

// =========================================================
// 🕐 SIMULAÇÃO DE TURNO ÚNICO (todos os OPs em sequência)
// =========================================================
$estourou_turno       = $total_turno_min > $minutos_turno_regular;
$minutos_hora_extra   = max(0, $total_turno_min - $minutos_turno_regular);
$custo_hora_extra     = $minutos_hora_extra * $custo_minuto_extra;
$horas_totais_turno   = round($total_turno_min / 60, 2);

$perc_setup_bar  = $minutos_turno_regular > 0
    ? min(100, round(($total_tempo_setup_min / $minutos_turno_regular) * 100, 1))
    : 0;
$perc_mold_bar   = $minutos_turno_regular > 0
    ? min(100, round(($total_moldagem_min / $minutos_turno_regular) * 100, 1))
    : 0;
$perc_extra_bar  = $minutos_turno_regular > 0
    ? min(100, round(($minutos_hora_extra / $minutos_turno_regular) * 100, 1))
    : 0;

// =========================================================
// 🔍 MATRIZ DE TRANSIÇÃO + BENCHMARK
// =========================================================
$matriz_transicao = [];
foreach ($fila_producao as $i => $origem) {
    foreach ($fila_producao as $j => $destino) {
        if ($i === $j) continue;
        $setup = calcularSetup($origem, $destino, $escala_aroma, $setup_padrao_min, $setup_pesado_min);
        $matriz_transicao[$origem['id']][$destino['id']] = $setup;
    }
}

$n_ops = count($fila_producao);
$benchmark_ideal = 0;
if ($n_ops > 0) {
    $benchmark_ideal = $setup_padrao_min + max(0, $n_ops - 1) * 15;
}

// =========================================================
// 📈 ESTATÍSTICAS EXTRA
// =========================================================
$total_ops_pendentes    = count($fila_producao);
$total_barras_pendentes = array_sum(array_column($fila_producao, 'quantidade_planejada'));

$eficiencia_atual = ($total_ops_pendentes > 0 && $total_tempo_setup_min > 0)
    ? max(0, round((1 - ($total_tempo_setup_min / ($total_ops_pendentes * $setup_pesado_min))) * 100, 1))
    : 100;

$capacidade_diaria_lotes = calcularCapacidadeDiaria($minutos_turno_regular, $tempo_gargalo_min);
$dias_necessarios = $capacidade_diaria_lotes > 0
    ? (int) ceil($total_ops_pendentes / $capacidade_diaria_lotes)
    : $total_ops_pendentes;

$sim_estoque = simularEstoqueFila($pdo, $equipe_id, $fila_producao);

// Agrupamento por dia
$timeline = [];
foreach ($fila_producao as $op) {
    $dia = $op['data_programada'];
    $timeline[$dia][] = $op;
}
ksort($timeline);

// Análise por dia
$carga_dias = [];
foreach ($timeline as $dia => $ops_dia) {
    $setup_dia = 0;
    $moldagem_dia = 0;
    $cont_dia = 0;

    // Roda análise horária do dia
    $analise_dia = analisarSequenciaHoraria(
        $ops_dia,
        $escala_aroma,
        $setup_padrao_min,
        $setup_pesado_min,
        $tempo_gargalo_min,
        $hora_base_min
    );

    $carga_dias[$dia] = [
        'ops'          => count($ops_dia),
        'barras'       => array_sum(array_column($ops_dia, 'quantidade_planejada')),
        'setup_min'    => $analise_dia['total_setup'],
        'moldagem_min' => $analise_dia['total_moldagem'],
        'total_min'    => $analise_dia['total_min'],
        'hora_fim'     => minParaHora($analise_dia['min_fim']),
        'contaminacoes'=> $analise_dia['contaminacoes'],
        'excede'       => $analise_dia['total_min'] > $minutos_turno_regular,
        'min_extra'    => max(0, $analise_dia['total_min'] - $minutos_turno_regular),
        'analise'      => $analise_dia,
    ];
}

// Histórico
$historico_acoes = $_SESSION['fs_acao_log'] ?? [];

$stmt_hist = $pdo->prepare("
    SELECT rk.score_rodada, rk.otif_perc, rk.taxa_refugo, rk.tempo_setup_total,
           jr.numero_rodada, jr.mes_ano
    FROM ranking_rodadas rk
    JOIN jogo_rodadas jr ON rk.rodada_id = jr.id
    WHERE rk.equipe_id = ?
    ORDER BY jr.numero_rodada DESC
    LIMIT 5
");
$stmt_hist->execute([$equipe_id]);
$historico_rodadas = $stmt_hist->fetchAll();

// =========================================================
// 🎨 RENDER
// =========================================================
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<style>
    .setup-timeline {
        border-left: 3px solid #dee2e6;
        margin-left: 8px;
        padding-left: 22px;
    }
    .gantt-bar {
        height: 26px;
        border-radius: 6px;
        display: flex;
        align-items: center;
        padding: 0 8px;
        font-size: .75rem;
        color: #fff;
        font-weight: 600;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        min-width: 40px;
    }
    .checklist-item {
        border-left: 4px solid #dee2e6;
        padding: 8px 12px;
        transition: all .15s ease;
    }
    .checklist-item:has(input:checked) {
        border-left-color: #198754;
        background: #f8f9fa;
    }
    .checklist-item:has(input:checked) label {
        text-decoration: line-through;
        color: #6c757d;
    }
    .turn-bar {
        height: 22px;
        border-radius: 6px;
        overflow: hidden;
        display: flex;
    }
    .turn-bar > div {
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: .72rem;
        font-weight: 700;
        color: #fff;
        white-space: nowrap;
        overflow: hidden;
    }
    .hour-line {
        border-left: 2px dashed #dee2e6;
        padding-left: 14px;
        margin-left: 6px;
    }
    .hora-marker {
        font-family: 'Courier New', monospace;
        font-weight: 700;
        color: #495057;
    }
    @media print {
        .no-print { display: none !important; }
        .card-custom { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
    }
    tr.table-warning td,
    tr.table-danger td { transition: filter .15s ease; }
</style>

<div class="container-fluid px-4 pb-4">

    <!-- ========================================================= -->
    <!-- CABEÇALHO                                                  -->
    <!-- ========================================================= -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                <?php if ($equipe): ?>
                    <span class="badge text-white px-3 py-1 font-monospace"
                          style="background-color: <?= h($equipe['cor_hex'] ?? '#2d5a3f') ?>;">
                        <i class="bi bi-shield-shaded me-1"></i><?= h($equipe['nome']) ?>
                    </span>
                <?php endif; ?>
                <span class="badge bg-secondary-subtle text-secondary border px-2 py-1">
                    <?= h($rodada_ativa['mes_ano'] ?? 'Mês 01') ?>
                </span>
                <span class="badge bg-primary-subtle text-primary border px-2 py-1">
                    <i class="bi bi-clock me-1"></i>Turno: <?= minParaHora($hora_base_min) ?>–<?= minParaHora($hora_base_min + $minutos_turno_regular) ?>
                    (<?= $minutos_turno_regular ?> min)
                </span>
            </div>
            <h2 class="fw-bold mb-1 text-dark">
                <i class="bi bi-stopwatch text-success me-2"></i>Programação Horária & Matriz de Setup
            </h2>
            <p class="text-muted mb-0">
                Sequenciamento em minutos/horas, otimização pela Regra de Ouro e análise de ocupação do turno.
            </p>
        </div>
        <div class="d-flex align-items-center flex-wrap gap-2 no-print">
            <?php if ($pode_otimizar && !$ja_esta_otima && !empty($fila_producao)): ?>
                <form method="POST" action="programacao.php" class="d-inline"
                      onsubmit="return confirm('Aplicar a heurística de otimização? As datas serão recalculadas.');">
                    <input type="hidden" name="acao" value="otimizar_sequencia">
                    <button type="submit" class="btn btn-warning shadow-sm fw-semibold text-dark">
                        <i class="bi bi-magic me-1"></i> Otimizar (Regra de Ouro)
                    </button>
                </form>
            <?php endif; ?>
            <button class="btn btn-outline-secondary shadow-sm" onclick="window.print()">
                <i class="bi bi-printer me-1"></i> Imprimir
            </button>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- BANNER DA RODADA                                            -->
    <!-- ========================================================= -->
    <?php if ($rodada_ativa): ?>
        <div class="alert alert-success border-0 shadow-sm d-flex align-items-start mb-4" role="alert">
            <i class="bi bi-calendar-event-fill fs-3 me-3 text-success"></i>
            <div class="flex-grow-1">
                <strong class="d-block">
                    Rodada <?= (int) $rodada_ativa['numero_rodada'] ?> ·
                    <?= h($rodada_ativa['mes_ano'] ?? '') ?> ·
                    <?= h($rodada_ativa['evento_titulo'] ?? '—') ?>
                </strong>
                <span class="small">
                    <?= h($rodada_ativa['evento_descricao'] ?? 'Sem descrição de evento.') ?>
                </span>
            </div>
            <span class="badge bg-success-subtle text-success border border-success-subtle ms-2">
                Demanda: <?= number_format((float) ($rodada_ativa['fator_demanda'] ?? 1), 2, ',', '.') ?>×
            </span>
        </div>
    <?php else: ?>
        <div class="alert alert-warning border-0 shadow-sm d-flex align-items-center mb-4">
            <i class="bi bi-pause-circle-fill fs-3 me-3 text-warning"></i>
            <div>
                <strong class="d-block">Nenhuma rodada aberta.</strong>
                <span>Aguarde o instrutor liberar a próxima rodada.</span>
            </div>
        </div>
    <?php endif; ?>

    <!-- ========================================================= -->
    <!-- ALERTAS FLASH                                                -->
    <!-- ========================================================= -->
    <?php if (!empty($mensagem_sucesso)): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> <?= h($mensagem_sucesso) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($mensagem_erro)): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= h($mensagem_erro) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($mensagem_aviso)): ?>
        <div class="alert alert-info alert-dismissible fade show border-0 shadow-sm" role="alert">
            <i class="bi bi-info-circle-fill me-2"></i> <?= h($mensagem_aviso) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- ========================================================= -->
    <!-- PAINEL DIDÁTICO: REGRA DE OURO                              -->
    <!-- ========================================================= -->
    <div class="card card-custom bg-white p-4 mb-4 border-start border-4 border-success">
        <div class="d-flex align-items-start gap-3">
            <div class="fs-1 text-success"><i class="bi bi-lightbulb"></i></div>
            <div class="flex-grow-1">
                <h5 class="fw-bold text-dark mb-2">Regra de Ouro do PCP em Cosméticos Artesanais</h5>
                <p class="small text-muted mb-2">
                    Sequencie a produção do <strong>aroma suave ao mais intenso</strong>.
                    Se um lote de aroma intenso for fabricado antes de um floral suave,
                    o óleo residual impregna tachos e espátulas, exigindo
                    <strong><?= $setup_pesado_min ?> min de sanitização pesada</strong>
                    para evitar contaminação olfativa cruzada.
                </p>
                <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                    <?php foreach ($escala_oficial as $cod => $ordem): ?>
                        <span class="badge bg-light text-dark border">
                            <strong><?= (int) $ordem ?>º</strong> <?= h($cod) ?>
                        </span>
                        <?php if ((int) $ordem < count($escala_oficial)): ?>
                            <i class="bi bi-arrow-right text-muted"></i>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- 🕐 BARRA DE OCUPAÇÃO DO TURNO TEÓRICO (480 min)              -->
    <!-- ========================================================= -->
    <?php if ($total_ops_pendentes > 0): ?>
        <div class="card card-custom p-4 bg-white mb-4 shadow-sm border-start border-4 <?= $estourou_turno ? 'border-danger' : 'border-success' ?>">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
                <div>
                    <h5 class="fw-bold text-dark mb-0">
                        <i class="bi bi-battery-charging me-2 <?= $estourou_turno ? 'text-danger' : 'text-success' ?>"></i>
                        Turno Teórico: <strong><?= $total_turno_min ?> / <?= $minutos_turno_regular ?> min</strong>
                        (<?= number_format($horas_totais_turno, 2, ',', '.') ?>h)
                    </h5>
                    <small class="text-muted">
                        Simulação a partir de <strong><?= minParaHora($hora_base_min) ?></strong> —
                        término previsto às <strong><?= $hora_fim_global ?></strong>
                    </small>
                </div>
                <div>
                    <?php if ($estourou_turno): ?>
                        <span class="badge bg-danger fs-6 px-3 py-2">
                            <i class="bi bi-exclamation-octagon-fill me-1"></i>
                            Estouro: +<?= $minutos_hora_extra ?> min (R$ <?= number_format($custo_hora_extra, 2, ',', '.') ?>)
                        </span>
                    <?php else: ?>
                        <span class="badge bg-success fs-6 px-3 py-2">
                            <i class="bi bi-check-circle-fill me-1"></i>
                            Dentro do turno (folga: <?= max(0, $minutos_turno_regular - $total_turno_min) ?> min)
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Barra de ocupação -->
            <div class="turn-bar bg-light">
                <?php if ($perc_setup_bar > 0): ?>
                    <div class="bg-warning text-dark"
                         style="width: <?= $perc_setup_bar ?>%;"
                         title="Setup: <?= $total_tempo_setup_min ?> min">
                        <?= $perc_setup_bar >= 8 ? 'Setup ' . $total_tempo_setup_min . 'm' : '' ?>
                    </div>
                <?php endif; ?>
                <?php if ($perc_mold_bar > 0): ?>
                    <div class="bg-success"
                         style="width: <?= $perc_mold_bar ?>%;"
                         title="Moldagem: <?= $total_moldagem_min ?> min">
                        <?= $perc_mold_bar >= 8 ? 'Produção ' . $total_moldagem_min . 'm' : '' ?>
                    </div>
                <?php endif; ?>
                <?php if ($perc_extra_bar > 0): ?>
                    <div class="bg-danger"
                         style="width: <?= $perc_extra_bar ?>%;"
                         title="Hora extra: <?= $minutos_hora_extra ?> min">
                        <?= $perc_extra_bar >= 8 ? 'Hora Extra ' . $minutos_hora_extra . 'm' : '' ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="d-flex justify-content-between small text-muted mt-1">
                <span class="hora-marker"><?= minParaHora($hora_base_min) ?></span>
                <span class="hora-marker"><?= minParaHora($hora_base_min + ($minutos_turno_regular / 2)) ?></span>
                <span class="hora-marker"><?= minParaHora($hora_base_min + $minutos_turno_regular) ?></span>
            </div>

            <div class="alert alert-light border small mt-3 mb-0">
                <i class="bi bi-info-circle me-1"></i>
                Simulação sequencial de <strong>todas as OPs do filtro atual</strong>, do primeiro ao último.
                Use como referência pedagógica de "quantas horas a linha ficaria ocupada".
                A programação oficial da fábrica é a da seção <strong>Cronograma Real</strong> abaixo.
            </div>
        </div>
    <?php endif; ?>

    <!-- ========================================================= -->
    <!-- 6 CARDS DE KPI                                              -->
    <!-- ========================================================= -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3 col-xl-2">
            <div class="card card-custom p-3 h-100">
                <span class="text-muted small text-uppercase fw-semibold">Setup Total</span>
                <h3 class="fw-bold my-1 text-warning-emphasis">
                    <?= $total_tempo_setup_min ?><small class="fs-6 text-muted">min</small>
                </h3>
                <small class="text-muted">≈ <?= round($total_tempo_setup_min / 60, 1) ?>h de paradas</small>
            </div>
        </div>
        <div class="col-6 col-lg-3 col-xl-2">
            <div class="card card-custom p-3 h-100">
                <span class="text-muted small text-uppercase fw-semibold">Moldagem</span>
                <h3 class="fw-bold my-1 text-success">
                    <?= $total_moldagem_min ?><small class="fs-6 text-muted">min</small>
                </h3>
                <small class="text-muted">Gargalo (<?= $tempo_gargalo_min ?>min/100un)</small>
            </div>
        </div>
        <div class="col-6 col-lg-3 col-xl-2">
            <div class="card card-custom p-3 h-100">
                <span class="text-muted small text-uppercase fw-semibold">Contaminações</span>
                <h3 class="fw-bold my-1 <?= $total_riscos_contaminacao > 0 ? 'text-danger' : 'text-success' ?>">
                    <?= $total_riscos_contaminacao ?>
                </h3>
                <small class="text-muted">
                    <?= $total_riscos_contaminacao > 0 ? 'Risco de contaminação' : 'Sequência ideal' ?>
                </small>
            </div>
        </div>
        <div class="col-6 col-lg-3 col-xl-2">
            <div class="card card-custom p-3 h-100">
                <span class="text-muted small text-uppercase fw-semibold">Fila Atual</span>
                <h3 class="fw-bold my-1 text-dark"><?= $total_ops_pendentes ?></h3>
                <small class="text-muted"><?= number_format($total_barras_pendentes, 0, ',', '.') ?> barras</small>
            </div>
        </div>
        <div class="col-6 col-lg-3 col-xl-2">
            <div class="card card-custom p-3 h-100">
                <span class="text-muted small text-uppercase fw-semibold">Eficiência</span>
                <h3 class="fw-bold my-1 <?= $eficiencia_atual >= 70 ? 'text-success' : 'text-warning' ?>">
                    <?= $eficiencia_atual ?>%
                </h3>
                <div class="progress mt-1" style="height: 4px;">
                    <div class="progress-bar <?= $eficiencia_atual >= 70 ? 'bg-success' : 'bg-warning' ?>"
                         style="width: <?= $eficiencia_atual ?>%"></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3 col-xl-2">
            <div class="card card-custom p-3 h-100">
                <span class="text-muted small text-uppercase fw-semibold">Gap vs Ideal</span>
                <h3 class="fw-bold my-1 <?= ($total_tempo_setup_min - $benchmark_ideal) > 0 ? 'text-danger' : 'text-success' ?>">
                    <?= max(0, $total_tempo_setup_min - $benchmark_ideal) ?><small class="fs-6 text-muted">min</small>
                </h3>
                <small class="text-muted">Potencial de melhoria</small>
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- FILTROS                                                      -->
    <!-- ========================================================= -->
    <div class="card card-custom bg-white p-3 mb-4 no-print">
        <form method="GET" action="programacao.php" class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold text-muted mb-1">Data Programada</label>
                <input type="date" name="data" class="form-control form-control-sm"
                       value="<?= h($filtro_data) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold text-muted mb-1">Intensidade</label>
                <select name="intensidade" class="form-select form-select-sm">
                    <option value="todas" <?= $filtro_int === 'todas' ? 'selected' : '' ?>>Todas</option>
                    <option value="suave" <?= $filtro_int === 'suave' ? 'selected' : '' ?>>Suave</option>
                    <option value="moderado" <?= $filtro_int === 'moderado' ? 'selected' : '' ?>>Moderado</option>
                    <option value="intenso" <?= $filtro_int === 'intenso' ? 'selected' : '' ?>>Intenso</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <button type="submit" class="btn btn-sm btn-dark w-100">
                    <i class="bi bi-funnel me-1"></i> Filtrar
                </button>
            </div>
            <div class="col-6 col-md-2">
                <a href="programacao.php" class="btn btn-sm btn-outline-secondary w-100">
                    <i class="bi bi-x-circle me-1"></i> Limpar
                </a>
            </div>
            <div class="col-12 col-md-2 text-md-end">
                <small class="text-muted">
                    <?= $total_ops_pendentes ?> OP(s)
                </small>
            </div>
        </form>
    </div>

    <!-- ========================================================= -->
    <!-- OPORTUNIDADE DE OTIMIZAÇÃO                                  -->
    <!-- ========================================================= -->
    <?php if ($pode_otimizar && !empty($fila_producao) && !$ja_esta_otima && $ganho_setup_min > 0): ?>
        <div class="card card-custom mb-4 border-start border-4 border-warning">
            <div class="card-body">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3">
                    <div class="flex-grow-1">
                        <h5 class="fw-bold text-dark mb-2">
                            <i class="bi bi-lightning-charge-fill text-warning me-2"></i>
                            Oportunidade de Otimização Detectada
                        </h5>
                        <p class="small text-muted mb-3">
                            Aplicando a <strong>Regra de Ouro</strong>, você reduz
                            <strong><?= $ganho_setup_min ?> min</strong> de setup
                            <?php if ($ganho_contaminacao > 0): ?>
                                e elimina <strong><?= $ganho_contaminacao ?> contaminação(ões)</strong>
                            <?php endif; ?>.
                        </p>
                        <div class="row g-3">
                            <div class="col-6 col-md-3">
                                <span class="small text-muted d-block">Setup Atual</span>
                                <strong class="text-danger fs-5"><?= $total_tempo_setup_min ?> min</strong>
                            </div>
                            <div class="col-6 col-md-3">
                                <span class="small text-muted d-block">Setup Otimizado</span>
                                <strong class="text-success fs-5"><?= $analise_otimizada['total_setup'] ?> min</strong>
                            </div>
                            <div class="col-6 col-md-3">
                                <span class="small text-muted d-block">Ganho</span>
                                <strong class="text-success fs-5">-<?= $ganho_setup_min ?> min</strong>
                            </div>
                            <div class="col-6 col-md-3">
                                <span class="small text-muted d-block">Contaminações</span>
                                <strong class="<?= $total_riscos_contaminacao > 0 ? 'text-danger' : 'text-success' ?> fs-5">
                                    <?= $total_riscos_contaminacao ?> → <?= $analise_otimizada['contaminacoes'] ?>
                                </strong>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex flex-column gap-2 no-print">
                        <form method="POST" action="programacao.php"
                              onsubmit="return confirm('Aplicar a otimização agora?');">
                            <input type="hidden" name="acao" value="otimizar_sequencia">
                            <button type="submit" class="btn btn-warning fw-semibold text-dark">
                                <i class="bi bi-magic me-1"></i> Otimizar Agora
                            </button>
                        </form>
                        <button type="button" class="btn btn-outline-secondary btn-sm"
                                data-bs-toggle="modal" data-bs-target="#modalPreview">
                            <i class="bi bi-eye me-1"></i> Ver Preview
                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php elseif ($ja_esta_otima && !empty($fila_producao)): ?>
        <div class="alert alert-success border-0 shadow-sm d-flex align-items-center mb-4">
            <i class="bi bi-trophy-fill fs-3 me-3 text-success"></i>
            <div>
                <strong class="d-block">Fila já está otimizada!</strong>
                <span>Nenhuma melhoria adicional possível com a configuração atual.</span>
            </div>
        </div>
    <?php endif; ?>

    <!-- ========================================================= -->
    <!-- 🕐 CRONOGRAMA DETALHADO HORA-A-HORA (TURNO TEÓRICO)         -->
    <!-- ========================================================= -->
    <?php if (!empty($sequencia_analisada)): ?>
        <div class="card card-custom bg-white p-4 mb-4">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
                <h5 class="fw-bold text-dark mb-0">
                    <i class="bi bi-calendar-range me-2 text-primary"></i>
                    Cronograma Hora-a-Hora (Turno Único Teórico)
                </h5>
                <span class="badge bg-light text-dark border">
                    Base: <?= minParaHora($hora_base_min) ?> ·
                    Escala: <?= $tempo_gargalo_min ?> min por 100 un no gargalo
                </span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light small text-uppercase">
                        <tr>
                            <th class="text-center" style="width: 55px;">#</th>
                            <th class="text-center" style="width: 130px;">Horário</th>
                            <th>Ordem (OP)</th>
                            <th>Fragrância</th>
                            <th class="text-center" style="width: 90px;">Lote</th>
                            <th class="text-center" style="width: 110px;">Setup</th>
                            <th class="text-center" style="width: 110px;">Moldagem</th>
                            <th class="text-center" style="width: 90px;">Total</th>
                            <th>Diagnóstico</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sequencia_analisada as $idx => $item):
                            $op = $item['op'];
                            $info = $item['info'];
                            $row_class = $info['alerta'] ? 'table-danger' : '';
                        ?>
                            <tr class="<?= $row_class ?>">
                                <td class="text-center fw-bold"><?= $idx + 1 ?></td>
                                <td class="text-center">
                                    <span class="badge bg-dark px-2 py-1">
                                        <?= $info['hora_inicio'] ?> → <?= $info['hora_fim'] ?>
                                    </span>
                                </td>
                                <td class="fw-bold font-monospace small"><?= h($op['numero']) ?></td>
                                <td>
                                    <span class="badge bg-light text-dark border me-1">
                                        <?= h($op['produto_codigo']) ?>
                                    </span>
                                    <strong><?= h($op['produto_nome']) ?></strong>
                                </td>
                                <td class="text-center fw-semibold">
                                    <?= (int) $op['quantidade_planejada'] ?> un
                                </td>
                                <td class="text-center">
                                    <span class="badge <?= $info['badge'] ?>">
                                        <?= $info['setup_min'] ?> min
                                    </span>
                                </td>
                                <td class="text-center font-monospace text-success fw-semibold">
                                    +<?= $info['moldagem_min'] ?> min
                                </td>
                                <td class="text-center font-monospace fw-bold">
                                    <?= $info['duracao_total'] ?> min
                                </td>
                                <td class="small <?= $info['alerta'] ? 'text-danger fw-semibold' : 'text-muted' ?>">
                                    <?= h($info['motivo']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <td colspan="5" class="text-end fw-bold">TOTAIS:</td>
                            <td class="text-center fw-bold text-warning-emphasis">
                                <?= $total_tempo_setup_min ?> min
                            </td>
                            <td class="text-center fw-bold text-success">
                                +<?= $total_moldagem_min ?> min
                            </td>
                            <td class="text-center fw-bold <?= $estourou_turno ? 'text-danger' : 'text-dark' ?> fs-5">
                                <?= $total_turno_min ?> min
                            </td>
                            <td class="small">
                                <?php if ($estourou_turno): ?>
                                    <i class="bi bi-exclamation-octagon-fill text-danger me-1"></i>
                                    <strong class="text-danger">Estouro de <?= $minutos_hora_extra ?> min</strong>
                                <?php else: ?>
                                    <i class="bi bi-check-circle-fill text-success me-1"></i>
                                    Fecha às <?= $hora_fim_global ?> — dentro do turno
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- ========================================================= -->
    <!-- 📅 CRONOGRAMA REAL — DIA POR DIA                             -->
    <!-- ========================================================= -->
    <?php if (!empty($timeline)): ?>
        <div class="card card-custom bg-white p-4 mb-4">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
                <h5 class="fw-bold mb-0">
                    <i class="bi bi-calendar-week text-success me-2"></i>
                    Cronograma Real — Turnos por Dia Programado
                </h5>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                    <i class="bi bi-stopwatch me-1"></i>
                    Capacidade: <?= $capacidade_diaria_lotes ?> lote(s)/dia
                </span>
            </div>

            <?php foreach ($timeline as $dia => $ops_dia):
                $carga = $carga_dias[$dia];
                $is_hoje = ($dia === date('Y-m-d'));
                $is_amanha = ($dia === date('Y-m-d', strtotime('+1 day')));
                $label_dia = date('d/m/Y', strtotime($dia));
                if ($is_hoje) $label_dia .= ' (hoje)';
                elseif ($is_amanha) $label_dia .= ' (amanhã)';
            ?>
                <div class="mb-4 pb-3 border-bottom">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge <?= $is_hoje ? 'bg-success' : ($is_amanha ? 'bg-info text-dark' : 'bg-primary') ?> px-3 py-2">
                                <i class="bi bi-calendar-event me-1"></i><?= $label_dia ?>
                            </span>
                            <small class="text-muted">
                                <?= $carga['ops'] ?> OP(s) ·
                                <?= number_format($carga['barras'], 0, ',', '.') ?> barras ·
                                Turno <?= minParaHora($hora_base_min) ?>-<?= $carga['hora_fim'] ?>
                                (<?= $carga['total_min'] ?> min)
                            </small>
                            <?php if ($carga['excede']): ?>
                                <span class="badge bg-danger">
                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                    +<?= $carga['min_extra'] ?> min extras
                                </span>
                            <?php endif; ?>
                            <?php if ($carga['contaminacoes'] > 0): ?>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                                    <?= $carga['contaminacoes'] ?> contaminação(ões)
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="small text-muted">
                            Setup: <strong><?= $carga['setup_min'] ?>m</strong> ·
                            Moldagem: <strong><?= $carga['moldagem_min'] ?>m</strong>
                        </div>
                    </div>

                    <!-- Barra proporcional do dia -->
                    <div class="turn-bar bg-light mb-3">
                        <?php
                        $p_setup = min(100, round(($carga['setup_min'] / max(1, $minutos_turno_regular)) * 100, 1));
                        $p_mold  = min(100, round(($carga['moldagem_min'] / max(1, $minutos_turno_regular)) * 100, 1));
                        $p_extra = min(100, round(($carga['min_extra'] / max(1, $minutos_turno_regular)) * 100, 1));
                        ?>
                        <?php if ($p_setup > 0): ?>
                            <div class="bg-warning text-dark" style="width: <?= $p_setup ?>%;"
                                 title="Setup: <?= $carga['setup_min'] ?>m">
                                <?= $p_setup >= 8 ? 'Setup' : '' ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($p_mold > 0): ?>
                            <div class="bg-success" style="width: <?= $p_mold ?>%;"
                                 title="Moldagem: <?= $carga['moldagem_min'] ?>m">
                                <?= $p_mold >= 8 ? 'Moldagem' : '' ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($p_extra > 0): ?>
                            <div class="bg-danger" style="width: <?= $p_extra ?>%;"
                                 title="Extra: <?= $carga['min_extra'] ?>m">
                                <?= $p_extra >= 8 ? 'Extra' : '' ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Lista compacta de OPs do dia -->
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($carga['analise']['items'] as $item_dia):
                            $op_dia = $item_dia['op'];
                            $inf_dia = $item_dia['info'];
                            $cor_dia = $cor_intensidade[$op_dia['intensidade_aroma']] ?? '#6c757d';
                        ?>
                            <div class="badge d-flex align-items-center gap-1 px-2 py-2"
                                 style="background: <?= $cor_dia ?>; color:#fff;">
                                <i class="bi bi-clock"></i>
                                <span style="font-family: monospace;">
                                    <?= $inf_dia['hora_inicio'] ?>-<?= $inf_dia['hora_fim'] ?>
                                </span>
                                · <?= h($op_dia['numero']) ?> · <?= h($op_dia['produto_codigo']) ?>
                                (<?= (int) $op_dia['quantidade_planejada'] ?>un)
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="alert alert-light border small mt-3 mb-0">
                <i class="bi bi-info-circle me-1"></i>
                Este é o cronograma <strong>real da fábrica</strong>, com datas persistidas no banco de dados.
                Use o botão <strong>"Mover"</strong> na tabela abaixo para ajustes finos individuais.
            </div>
        </div>
    <?php endif; ?>

    <!-- ========================================================= -->
    <!-- TABELA PRINCIPAL DE SEQUENCIAMENTO                          -->
    <!-- ========================================================= -->
    <div class="card card-custom bg-white p-4 mb-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
            <h5 class="fw-bold text-dark mb-0">
                <i class="bi bi-diagram-3 me-2 text-secondary"></i>
                Linha de Sequenciamento (ordem atual)
            </h5>
            <?php if ($pode_otimizar && !empty($fila_producao)): ?>
                <form method="POST" action="programacao.php" class="d-inline"
                      onsubmit="return confirm('Restaurar a ordem original de criação?');">
                    <input type="hidden" name="acao" value="resetar_ordem">
                    <button type="submit" class="btn btn-sm btn-outline-secondary no-print">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Resetar Ordem
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (empty($sequencia_analisada)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                Não há ordens de produção pendentes na rodada atual.
                <br><small>Crie OPs na página <strong>Ordens de Produção</strong>.</small>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead class="table-light small text-uppercase">
                        <tr>
                            <th style="width: 55px;">Seq.</th>
                            <th style="width: 100px;">Data</th>
                            <th style="width: 130px;">Número</th>
                            <th>Fragrância</th>
                            <th style="width: 110px;">Intensidade</th>
                            <th style="width: 90px;">Lote</th>
                            <th style="width: 110px;">Setup</th>
                            <th>Diagnóstico</th>
                            <th style="width: 90px;" class="text-end no-print">Mover</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sequencia_analisada as $idx => $item):
                            $op = $item['op'];
                            $info = $item['info'];
                            $row_class = $info['alerta'] ? 'table-danger' : '';
                            $cor_borda = $cor_intensidade[$op['intensidade_aroma']] ?? '#6c757d';
                        ?>
                            <tr class="<?= $row_class ?>">
                                <td class="text-center">
                                    <span class="badge rounded-circle p-2"
                                          style="background: <?= $cor_borda ?>;
                                                 width: 30px; height: 30px;
                                                 display: inline-flex;
                                                 align-items: center;
                                                 justify-content: center;
                                                 color: #fff;">
                                        <?= $idx + 1 ?>
                                    </span>
                                </td>
                                <td>
                                    <?= date('d/m', strtotime($op['data_programada'])) ?>
                                    <?php if ($op['status'] === 'em_producao'): ?>
                                        <span class="badge bg-warning text-dark d-block mt-1"
                                              style="font-size: .62rem;">
                                            em produção
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-bold font-monospace small"><?= h($op['numero']) ?></td>
                                <td>
                                    <span class="badge bg-light text-dark border me-1">
                                        <?= h($op['produto_codigo']) ?>
                                    </span>
                                    <strong><?= h($op['produto_nome']) ?></strong>
                                </td>
                                <td>
                                    <?php
                                    $int_badge = [
                                        'suave'    => 'bg-info text-dark',
                                        'moderado' => 'bg-warning text-dark',
                                        'intenso'  => 'bg-danger text-white',
                                    ][$op['intensidade_aroma']] ?? 'bg-secondary';
                                    ?>
                                    <span class="badge <?= $int_badge ?>">
                                        <?= ucfirst(h($op['intensidade_aroma'])) ?>
                                    </span>
                                </td>
                                <td class="fw-semibold">
                                    <?= number_format((int) $op['quantidade_planejada'], 0, ',', '.') ?>
                                </td>
                                <td>
                                    <span class="badge <?= $info['badge'] ?>">
                                        <i class="bi <?= $info['icone'] ?> me-1"></i>
                                        <?= $info['setup_min'] ?> min
                                    </span>
                                </td>
                                <td class="small <?= $info['alerta'] ? 'text-danger fw-semibold' : 'text-muted' ?>">
                                    <?= h($info['motivo']) ?>
                                </td>
                                <td class="text-end no-print">
                                    <div class="btn-group btn-group-sm">
                                        <form method="POST" action="programacao.php" class="d-inline">
                                            <input type="hidden" name="acao" value="mover_op">
                                            <input type="hidden" name="op_id" value="<?= (int)$op['id'] ?>">
                                            <input type="hidden" name="direcao" value="subir">
                                            <input type="hidden" name="data_atual"
                                                   value="<?= h($op['data_programada']) ?>">
                                            <button type="submit" class="btn btn-outline-secondary"
                                                    title="Adiantar 1 dia"
                                                    <?= $idx === 0 ? 'disabled' : '' ?>>
                                                <i class="bi bi-arrow-up"></i>
                                            </button>
                                        </form>
                                        <form method="POST" action="programacao.php" class="d-inline">
                                            <input type="hidden" name="acao" value="mover_op">
                                            <input type="hidden" name="op_id" value="<?= (int)$op['id'] ?>">
                                            <input type="hidden" name="direcao" value="descer">
                                            <input type="hidden" name="data_atual"
                                                   value="<?= h($op['data_programada']) ?>">
                                            <button type="submit" class="btn btn-outline-secondary"
                                                    title="Adiar 1 dia"
                                                    <?= $idx === count($sequencia_analisada) - 1 ? 'disabled' : '' ?>>
                                                <i class="bi bi-arrow-down"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <td colspan="6" class="text-end fw-bold">TOTAL DE SETUP:</td>
                            <td class="fw-bold fs-5 <?= $total_riscos_contaminacao > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= $total_tempo_setup_min ?> min
                            </td>
                            <td colspan="2" class="small">
                                <?= $total_riscos_contaminacao > 0
                                    ? '<i class="bi bi-exclamation-triangle-fill text-danger me-1"></i> ' . $total_riscos_contaminacao . ' contaminação(ões) cruzada(s)'
                                    : '<i class="bi bi-check-circle-fill text-success me-1"></i> Sem contaminação cruzada' ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ========================================================= -->
    <!-- ANÁLISE DE ESTOQUE PARALELA                                 -->
    <!-- ========================================================= -->
    <?php if (!empty($sim_estoque['insumos'])): ?>
        <div class="card card-custom bg-white p-4 mb-4">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
                <h5 class="fw-bold mb-0">
                    <i class="bi bi-boxes text-secondary me-2"></i>
                    Análise de Estoque da Fila Completa
                </h5>
                <span class="badge <?= $sim_estoque['ruptura'] ? 'bg-danger' : 'bg-success' ?>">
                    <?= $sim_estoque['ruptura'] ? '⚠ Risco de ruptura' : '✅ Estoque suficiente' ?>
                </span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light small text-uppercase">
                        <tr>
                            <th>Insumo</th>
                            <th class="text-end">Necessário</th>
                            <th class="text-end">Disponível</th>
                            <th class="text-end">Saldo Projetado</th>
                            <th style="width: 90px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sim_estoque['insumos'] as $ins): ?>
                            <tr class="<?= $ins['falta'] ? 'table-danger' : '' ?>">
                                <td><strong><?= h($ins['nome']) ?></strong></td>
                                <td class="text-end font-monospace">
                                    <?= number_format($ins['necessario'], 4, ',', '.') ?> <?= h($ins['unidade']) ?>
                                </td>
                                <td class="text-end font-monospace">
                                    <?= number_format($ins['disponivel'], 4, ',', '.') ?> <?= h($ins['unidade']) ?>
                                </td>
                                <td class="text-end font-monospace fw-bold <?= $ins['falta'] ? 'text-danger' : 'text-success' ?>">
                                    <?= number_format($ins['saldo'], 4, ',', '.') ?> <?= h($ins['unidade']) ?>
                                </td>
                                <td>
                                    <?php if ($ins['falta']): ?>
                                        <span class="badge bg-danger">Ruptura</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">OK</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="alert alert-light border small mt-3 mb-0">
                <i class="bi bi-info-circle me-1"></i>
                Considera <strong>todas as OPs do filtro atual</strong>.
                Se houver ruptura, compre insumos ou reduza lotes antes de iniciar.
            </div>
        </div>
    <?php endif; ?>

    <!-- ========================================================= -->
    <!-- CONTAGEM POR TIPO DE SETUP                                  -->
    <!-- ========================================================= -->
    <?php if (!empty($fila_producao)): ?>
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="card card-custom p-3 h-100 text-center">
                    <span class="text-muted small text-uppercase">Primeiro Lote</span>
                    <h4 class="fw-bold text-secondary my-1"><?= $count_tipos['primeiro'] ?></h4>
                    <small class="text-muted"><?= $setup_padrao_min ?> min cada</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card card-custom p-3 h-100 text-center">
                    <span class="text-muted small text-uppercase">Mesmo Produto</span>
                    <h4 class="fw-bold text-info my-1"><?= $count_tipos['mesmo_produto'] ?></h4>
                    <small class="text-muted">15 min cada</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card card-custom p-3 h-100 text-center">
                    <span class="text-muted small text-uppercase">Transição Suave</span>
                    <h4 class="fw-bold text-success my-1"><?= $count_tipos['transicao_suave'] ?></h4>
                    <small class="text-muted"><?= $setup_padrao_min ?> min cada</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card card-custom p-3 h-100 text-center">
                    <span class="text-muted small text-uppercase">Limpeza Pesada</span>
                    <h4 class="fw-bold text-danger my-1"><?= $count_tipos['limpeza_pesada'] ?></h4>
                    <small class="text-muted"><?= $setup_pesado_min ?> min cada</small>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ========================================================= -->
    <!-- MATRIZ DE TRANSIÇÃO                                         -->
    <!-- ========================================================= -->
    <?php if (count($fila_producao) >= 2): ?>
        <div class="card card-custom bg-white p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold text-dark mb-0">
                    <i class="bi bi-grid-3x3-gap me-2 text-primary"></i>
                    Matriz de Transição (setup entre pares)
                </h5>
                <button class="btn btn-sm btn-outline-secondary no-print"
                        data-bs-toggle="collapse" data-bs-target="#collapseMatriz">
                    <i class="bi bi-arrows-expand me-1"></i> Mostrar / Ocultar
                </button>
            </div>
            <p class="small text-muted mb-3">
                Cada célula mostra o setup necessário para trocar da OP da linha para a OP da coluna.
                <span class="badge bg-danger text-white"><?= $setup_pesado_min ?> min</span>
                indica contaminação cruzada.
                <span class="badge bg-info text-dark">15 min</span> é o caso ideal.
            </p>
            <div class="collapse" id="collapseMatriz">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="text-center sticky-top bg-light">Origem ↓ / Destino →</th>
                                <?php foreach ($fila_producao as $op_dest): ?>
                                    <th class="text-center small">
                                        <div class="fw-bold font-monospace"><?= h($op_dest['produto_codigo']) ?></div>
                                        <div class="text-muted" style="font-size:.7em;">
                                            <?= h($op_dest['numero']) ?>
                                        </div>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fila_producao as $op_orig): ?>
                                <tr>
                                    <th class="bg-light small">
                                        <div class="fw-bold font-monospace"><?= h($op_orig['produto_codigo']) ?></div>
                                        <div class="text-muted" style="font-size:.7em;">
                                            <?= h($op_orig['numero']) ?>
                                        </div>
                                    </th>
                                    <?php foreach ($fila_producao as $op_dest):
                                        if ($op_orig['id'] === $op_dest['id']): ?>
                                            <td class="text-center bg-secondary-subtle text-muted">—</td>
                                        <?php else:
                                            $m = $matriz_transicao[$op_orig['id']][$op_dest['id']] ?? null;
                                            if (!$m): ?>
                                                <td class="text-center">?</td>
                                            <?php else: ?>
                                                <td class="text-center">
                                                    <span class="badge <?= $m['badge_class'] ?>">
                                                        <?= $m['min'] ?>min
                                                    </span>
                                                </td>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ========================================================= -->
    <!-- COMPARATIVO ATUAL vs OTIMIZADO                              -->
    <!-- ========================================================= -->
    <?php if ($pode_otimizar && !empty($fila_producao) && !$ja_esta_otima): ?>
        <div class="card card-custom bg-white p-4 mb-4">
            <h5 class="fw-bold text-dark mb-3">
                <i class="bi bi-arrow-left-right me-2 text-info"></i>
                Comparativo: Ordem Atual vs. Ordem Otimizada
            </h5>
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <div class="border rounded-3 p-3 h-100" style="border-color: #dc3545 !important;">
                        <h6 class="fw-bold text-danger mb-2">
                            <i class="bi bi-x-circle-fill me-1"></i> Sequência Atual
                        </h6>
                        <ol class="small mb-2 ps-3">
                            <?php foreach ($fila_producao as $op): ?>
                                <li>
                                    <span class="badge bg-light text-dark border"><?= h($op['produto_codigo']) ?></span>
                                    <?= date('d/m', strtotime($op['data_programada'])) ?>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                        <div class="d-flex justify-content-between border-top pt-2 mt-2">
                            <span class="small text-muted">Setup total:</span>
                            <strong class="text-danger"><?= $total_tempo_setup_min ?> min</strong>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="small text-muted">Contaminações:</span>
                            <strong class="<?= $total_riscos_contaminacao > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= $total_riscos_contaminacao ?>
                            </strong>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="border rounded-3 p-3 h-100" style="border-color: #198754 !important;">
                        <h6 class="fw-bold text-success mb-2">
                            <i class="bi bi-check-circle-fill me-1"></i> Sequência Otimizada
                        </h6>
                        <ol class="small mb-2 ps-3">
                            <?php foreach ($fila_otimizada as $op): ?>
                                <li>
                                    <span class="badge bg-light text-dark border"><?= h($op['produto_codigo']) ?></span>
                                    <?= h($op['produto_nome']) ?>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                        <div class="d-flex justify-content-between border-top pt-2 mt-2">
                            <span class="small text-muted">Setup total:</span>
                            <strong class="text-success"><?= $analise_otimizada['total_setup'] ?> min</strong>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="small text-muted">Contaminações:</span>
                            <strong class="<?= $analise_otimizada['contaminacoes'] > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= $analise_otimizada['contaminacoes'] ?>
                            </strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ========================================================= -->
    <!-- CHECKLIST DE SANITIZAÇÃO                                    -->
    <!-- ========================================================= -->
    <div class="card card-custom bg-white p-4 mb-4">
        <h5 class="fw-bold text-dark mb-3">
            <i class="bi bi-clipboard-check text-success me-2"></i>
            Checklist de Sanitização (Antes do Próximo Lote)
        </h5>
        <p class="small text-muted mb-3">
            Marque cada item antes de iniciar um novo lote. Itens de limpeza pesada
            são obrigatórios quando há troca de aroma forte → suave.
        </p>
        <div class="row g-2">
            <div class="col-12 col-md-6">
                <div class="checklist-item">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="ck1">
                        <label class="form-check-label small" for="ck1">
                            <strong>Raspagem dos tachos</strong> — remover resíduo sólido
                        </label>
                    </div>
                </div>
                <div class="checklist-item">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="ck2">
                        <label class="form-check-label small" for="ck2">
                            <strong>Limpeza do bico doseador</strong> — fluxo contínuo
                        </label>
                    </div>
                </div>
                <div class="checklist-item">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="ck3">
                        <label class="form-check-label small" for="ck3">
                            <strong>Troca das espátulas de inox</strong> — secas
                        </label>
                    </div>
                </div>
                <div class="checklist-item">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="ck4">
                        <label class="form-check-label small" for="ck4">
                            <strong>Inspeção visual dos moldes</strong> — sem resíduo
                        </label>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6">
                <div class="checklist-item" style="border-left-color: #dc3545;">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="ck5">
                        <label class="form-check-label small" for="ck5">
                            <strong class="text-danger">Sanitização com álcool 70%</strong> —
                            obrigatório entre aromas intensos e suaves (<?= $setup_pesado_min ?> min)
                        </label>
                    </div>
                </div>
                <div class="checklist-item" style="border-left-color: #ffc107;">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="ck6">
                        <label class="form-check-label small" for="ck6">
                            <strong>Anotação do setup realizado</strong> — registrar tempo real
                        </label>
                    </div>
                </div>
                <div class="checklist-item">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="ck7">
                        <label class="form-check-label small" for="ck7">
                            <strong>Conferência da etiqueta</strong> — identificar próximo produto
                        </label>
                    </div>
                </div>
                <div class="checklist-item">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="ck8">
                        <label class="form-check-label small" for="ck8">
                            <strong>Aprovação do supervisor</strong> — liberação final
                        </label>
                    </div>
                </div>
            </div>
        </div>
        <div class="mt-3 text-end">
            <small class="text-muted" id="checklistProgresso">0 / 8 itens concluídos</small>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- MANUAL DIDÁTICO                                             -->
    <!-- ========================================================= -->
    <div class="card card-custom bg-white p-4 mb-4">
        <h5 class="fw-bold text-dark mb-3">
            <i class="bi bi-book text-primary me-2"></i>
            Manual de Setup — Aplicando a Regra de Ouro
        </h5>
        <div class="row g-3">
            <div class="col-12 col-md-3">
                <div class="p-3 bg-light rounded-3 h-100 border">
                    <div class="d-flex align-items-center mb-2">
                        <span class="badge bg-primary rounded-circle p-2 me-2"
                              style="width:26px; height:26px;">1</span>
                        <strong class="small">Avaliar Fila</strong>
                    </div>
                    <p class="small text-muted mb-0">
                        Veja a ordem atual, o setup total e as contaminações.
                    </p>
                </div>
            </div>
            <div class="col-12 col-md-3">
                <div class="p-3 bg-light rounded-3 h-100 border">
                    <div class="d-flex align-items-center mb-2">
                        <span class="badge bg-primary rounded-circle p-2 me-2"
                              style="width:26px; height:26px;">2</span>
                        <strong class="small">Identificar Transições</strong>
                    </div>
                    <p class="small text-muted mb-0">
                        Linha vermelha = contaminação. Linha verde = transição segura.
                    </p>
                </div>
            </div>
            <div class="col-12 col-md-3">
                <div class="p-3 bg-light rounded-3 h-100 border">
                    <div class="d-flex align-items-center mb-2">
                        <span class="badge bg-primary rounded-circle p-2 me-2"
                              style="width:26px; height:26px;">3</span>
                        <strong class="small">Aplicar Heurística</strong>
                    </div>
                    <p class="small text-muted mb-0">
                        O sistema reordena do aroma suave ao mais intenso (1 lote/dia).
                    </p>
                </div>
            </div>
            <div class="col-12 col-md-3">
                <div class="p-3 bg-light rounded-3 h-100 border">
                    <div class="d-flex align-items-center mb-2">
                        <span class="badge bg-primary rounded-circle p-2 me-2"
                              style="width:26px; height:26px;">4</span>
                        <strong class="small">Validar Estoque</strong>
                    </div>
                    <p class="small text-muted mb-0">
                        Confira o painel de estoque antes de iniciar produção.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- EXPLICAÇÃO DIDÁTICA                                         -->
    <!-- ========================================================= -->
    <div class="card card-custom bg-white p-4 mb-4">
        <h5 class="fw-bold text-dark mb-3">
            <i class="bi bi-mortarboard me-2 text-primary"></i>Por que esse cálculo importa?
        </h5>
        <div class="row g-3">
            <div class="col-12 col-md-4">
                <div class="p-3 bg-light rounded-3 h-100">
                    <strong class="text-dark d-block mb-1">
                        <i class="bi bi-clock-history text-primary me-1"></i> Custo do tempo parado
                    </strong>
                    <p class="small text-muted mb-0">
                        Cada minuto de setup é tempo em que a linha não produz.
                        Em uma fábrica com <strong><?= $setup_pesado_min ?> min</strong>
                        de sanitização pesada, um erro de sequência custa horas por mês.
                    </p>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="p-3 bg-light rounded-3 h-100">
                    <strong class="text-dark d-block mb-1">
                        <i class="bi bi-droplet-half text-success me-1"></i> Contaminação olfativa
                    </strong>
                    <p class="small text-muted mb-0">
                        Óleos essenciais intensos deixam resíduos nos equipamentos.
                        Se um lote suave vier depois, o aroma residual gera refugo.
                    </p>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="p-3 bg-light rounded-3 h-100">
                    <strong class="text-dark d-block mb-1">
                        <i class="bi bi-trophy text-warning me-1"></i> Impacto no score
                    </strong>
                    <p class="small text-muted mb-0">
                        No ranking, o <strong>tempo total de setup</strong> e a
                        <strong>taxa de refugo</strong> pesam no score.
                        Sequências otimizadas rendem mais pontos.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- HISTÓRICO DE AÇÕES                                          -->
    <!-- ========================================================= -->
    <?php if (!empty($historico_acoes)): ?>
        <div class="card card-custom bg-white p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0">
                    <i class="bi bi-clock-history text-secondary me-2"></i>
                    Histórico de Ações (Nesta Sessão)
                </h5>
                <form method="POST" action="programacao.php" class="d-inline no-print">
                    <input type="hidden" name="acao" value="limpar_historico">
                    <button type="submit" class="btn btn-sm btn-outline-secondary"
                            onclick="return confirm('Limpar histórico?');">
                        <i class="bi bi-trash me-1"></i> Limpar
                    </button>
                </form>
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach (array_reverse($historico_acoes) as $acao): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0 small">
                        <div>
                            <span class="badge bg-light text-dark border me-2"><?= h($acao['tipo']) ?></span>
                            <?= h($acao['descricao']) ?>
                        </div>
                        <small class="text-muted"><?= h($acao['quando']) ?></small>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- ========================================================= -->
    <!-- HISTÓRICO DE RODADAS                                        -->
    <!-- ========================================================= -->
    <?php if (!empty($historico_rodadas)): ?>
        <div class="card card-custom bg-white p-4 mb-4">
            <h5 class="fw-bold mb-3">
                <i class="bi bi-trophy text-warning me-2"></i>
                Histórico de Desempenho em Rodadas Anteriores
            </h5>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light small text-uppercase">
                        <tr>
                            <th>Rodada</th>
                            <th>Período</th>
                            <th class="text-end">Score</th>
                            <th class="text-end">OTIF</th>
                            <th class="text-end">Refugo</th>
                            <th class="text-end">Setup (min)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historico_rodadas as $hr): ?>
                            <tr>
                                <td class="fw-bold">R<?= (int)$hr['numero_rodada'] ?></td>
                                <td><span class="badge bg-light text-dark border"><?= h($hr['mes_ano']) ?></span></td>
                                <td class="text-end fw-bold text-success"><?= (int)$hr['score_rodada'] ?></td>
                                <td class="text-end"><?= number_format((float)$hr['otif_perc'], 1, ',', '.') ?>%</td>
                                <td class="text-end <?= (float)$hr['taxa_refugo'] > 3 ? 'text-danger fw-bold' : '' ?>">
                                    <?= number_format((float)$hr['taxa_refugo'], 2, ',', '.') ?>%
                                </td>
                                <td class="text-end"><?= (int)$hr['tempo_setup_total'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- ========================================================= -->
    <!-- DICA FINAL                                                  -->
    <!-- ========================================================= -->
    <div class="card card-custom bg-white p-4">
        <h6 class="fw-bold text-dark mb-2">
            <i class="bi bi-lightbulb text-warning me-2"></i>
            Dica de Ouro
        </h6>
        <p class="small text-muted mb-0">
            Otimize a fila <strong>assim que criar as OPs</strong>, antes de iniciar produção.
            Uma vez que a OP entra em "Em Produção", o sistema preserva a data para rastreabilidade.
            Use o botão <strong>"Mover"</strong> na tabela para ajustes finos individuais.
        </p>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: PREVIEW DA SEQUÊNCIA OTIMIZADA                          -->
<!-- ============================================================ -->
<div class="modal fade" id="modalPreview" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-eye text-info me-2"></i>Preview da Otimização
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 small mb-3">
                    Simulação <strong>após</strong> aplicar a Regra de Ouro.
                    A ordem real só é alterada ao confirmar "Aplicar Agora".
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light small text-uppercase">
                            <tr>
                                <th>#</th>
                                <th>OP</th>
                                <th>Produto</th>
                                <th>Intensidade</th>
                                <th>Setup</th>
                                <th>Diagnóstico</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($analise_otimizada['items'] as $idx => $item):
                                $op = $item['op'];
                                $info = $item['info'];
                            ?>
                                <tr class="<?= $info['alerta'] ? 'table-danger' : '' ?>">
                                    <td class="fw-bold text-center"><?= $idx + 1 ?></td>
                                    <td class="font-monospace small"><?= h($op['numero']) ?></td>
                                    <td>
                                        <span class="badge bg-light text-dark border me-1">
                                            <?= h($op['produto_codigo']) ?>
                                        </span>
                                        <?= h($op['produto_nome']) ?>
                                    </td>
                                    <td>
                                        <span class="badge
                                            <?= [
                                                'suave'    => 'bg-info text-dark',
                                                'moderado' => 'bg-warning text-dark',
                                                'intenso'  => 'bg-danger text-white',
                                            ][$op['intensidade_aroma']] ?? 'bg-secondary' ?>">
                                            <?= ucfirst(h($op['intensidade_aroma'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?= $info['badge'] ?>">
                                            <?= $info['setup_min'] ?> min
                                        </span>
                                    </td>
                                    <td class="small <?= $info['alerta'] ? 'text-danger' : 'text-muted' ?>">
                                        <?= h($info['motivo']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="row g-2 mt-3">
                    <div class="col-6">
                        <div class="alert alert-light border mb-0 text-center">
                            <span class="d-block small text-muted">Setup Total</span>
                            <strong class="fs-5 text-success"><?= $analise_otimizada['total_setup'] ?> min</strong>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="alert alert-light border mb-0 text-center">
                            <span class="d-block small text-muted">Contaminações</span>
                            <strong class="fs-5 <?= $analise_otimizada['contaminacoes'] > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= $analise_otimizada['contaminacoes'] ?>
                            </strong>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                <form method="POST" action="programacao.php" class="d-inline"
                      onsubmit="return confirm('Confirmar aplicação da otimização?');">
                    <input type="hidden" name="acao" value="otimizar_sequencia">
                    <button type="submit" class="btn btn-warning fw-semibold text-dark">
                        <i class="bi bi-magic me-1"></i> Aplicar Agora
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // -------------------------------------------------------
    // 1. Realce de linhas críticas
    // -------------------------------------------------------
    document.querySelectorAll('tr.table-danger, tr.table-warning').forEach(linha => {
        linha.addEventListener('mouseenter', () => { linha.style.filter = 'brightness(0.96)'; });
        linha.addEventListener('mouseleave', () => { linha.style.filter = 'none'; });
    });

    // -------------------------------------------------------
    // 2. Feedback visual em botões de otimização / reset / mover
    // -------------------------------------------------------
    document.querySelectorAll('form[action="programacao.php"]').forEach(form => {
        const acaoInput = form.querySelector('input[name="acao"]');
        if (!acaoInput) return;
        const acao = acaoInput.value;
        if (!['otimizar_sequencia', 'resetar_ordem', 'mover_op'].includes(acao)) return;

        form.addEventListener('submit', function () {
            const btn = form.querySelector('button[type="submit"]');
            if (!btn) return;
            btn.disabled = true;
            const txt = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>';
            setTimeout(() => { btn.disabled = false; btn.innerHTML = txt; }, 5000);
        });
    });

    // -------------------------------------------------------
    // 3. Progresso do checklist
    // -------------------------------------------------------
    const checks = document.querySelectorAll('.checklist-item input[type="checkbox"]');
    const progresso = document.getElementById('checklistProgresso');
    function atualizarProgresso() {
        const total = checks.length;
        const marcados = document.querySelectorAll('.checklist-item input[type="checkbox"]:checked').length;
        if (progresso) {
            progresso.textContent = marcados + ' / ' + total + ' itens concluídos';
            progresso.className = 'text-' + (marcados === total ? 'success' : 'muted');
        }
    }
    checks.forEach(c => c.addEventListener('change', atualizarProgresso));
    atualizarProgresso();

    // -------------------------------------------------------
    // 4. Auto-submit em filtros de data
    // -------------------------------------------------------
    document.querySelectorAll('input[type="date"], select[name="intensidade"]').forEach(el => {
        el.addEventListener('change', () => el.closest('form')?.submit());
    });
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';