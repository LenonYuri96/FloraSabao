<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$equipe_id = getEquipeAtivaId();

if ($equipe_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Equipe não identificada']);
    exit;
}

// --------------------------------------------------------------------------
// 1. AUDITORIA AUTOMÁTICA DA FÁBRICA (Motor Especialista)
// --------------------------------------------------------------------------
if ($action === 'auditar_fabrica') {
    $diagnosticos = [];

    // 1.1 Diagnóstico de Sequenciamento Olfativo (Regra de Ouro)
    $stmt_seq = $pdo->prepare("
        SELECT op.numero, p.codigo, p.intensidade_aroma, op.data_programada
        FROM ordens_producao op
        JOIN produtos p ON op.produto_id = p.id
        WHERE op.equipe_id = ? AND op.status IN ('planejada', 'em_producao')
        ORDER BY op.data_programada ASC, op.id ASC
    ");
    $stmt_seq->execute([$equipe_id]);
    $fila = $stmt_seq->fetchAll();

    $violacoes = 0;
    for ($i = 1; $i < count($fila); $i++) {
        $ordemAnt = ORDEM_AROMAS_RECOMENDADA[$fila[$i - 1]['codigo']] ?? 2;
        $ordemAtu = ORDEM_AROMAS_RECOMENDADA[$fila[$i]['codigo']] ?? 2;

        if ($ordemAnt > $ordemAtu) {
            $violacoes++;
            $diagnosticos[] = [
                'tipo' => 'danger',
                'pilar' => 'Sequenciamento Olfativo (Regra de Ouro Violada)',
                'mensagem' => "Contaminação Cruzada: OP {$fila[$i-1]['numero']} ({$fila[$i-1]['codigo']}) programada antes de {$fila[$i]['numero']} ({$fila[$i]['codigo']}). Isso gera 45 min de sanitização pesada com álcool 70%!"
            ];
        }
    }
    if ($violacoes === 0 && count($fila) > 1) {
        $diagnosticos[] = [
            'tipo' => 'success',
            'pilar' => 'Sequenciamento Olfativo',
            'mensagem' => 'Perfeito! Seus lotes seguem a Regra de Ouro (Maracujá → Pitanga → Lavanda → Rosas), mantendo o setup mínimo em 20 min.'
        ];
    }

    // 1.2 Diagnóstico do Gargalo (Posto 3 - Moldagem = 70 min)
    $stmt_carga = $pdo->prepare("
        SELECT COALESCE(SUM(quantidade_planejada), 0) as total_barras
        FROM ordens_producao
        WHERE equipe_id = ? AND status IN ('planejada', 'em_producao')
    ");
    $stmt_carga->execute([$equipe_id]);
    $barras_fila = (int) $stmt_carga->fetchColumn();

    $minutos_gargalo = ($barras_fila / 100) * TEMPO_GARGALO_LOTE_MIN;
    $horas_gargalo = round($minutos_gargalo / 60, 1);

    if ($horas_gargalo > 35) {
        $diagnosticos[] = [
            'tipo' => 'warning',
            'pilar' => 'Teoria das Restrições (Gargalo no Posto 3)',
            'mensagem' => "Alerta de Sobrecarga: Fila de {$barras_fila} barras exige {$horas_gargalo}h no Posto de Moldagem (70 min/lote). Risco crítico de não cumprimento do prazo (OTIF)!"
        ];
    } else {
        $diagnosticos[] = [
            'tipo' => 'info',
            'pilar' => 'Teoria das Restrições (Gargalo no Posto 3)',
            'mensagem' => "Carga no gargalo equilibrada: demanda estimada de {$horas_gargalo}h de moldagem para processar os lotes pendentes."
        ];
    }

    // 1.3 Diagnóstico da Curva ABC (Item Classe A - Base Glicerinada)
    $stmt_base = $pdo->prepare("SELECT estoque_atual, estoque_minimo FROM insumos WHERE equipe_id = ? AND codigo = 'BASE-GLIC'");
    $stmt_base->execute([$equipe_id]);
    $base = $stmt_base->fetch();
    if ($base && (float)$base['estoque_atual'] <= (float)$base['estoque_minimo']) {
        $diagnosticos[] = [
            'tipo' => 'danger',
            'pilar' => 'Curva ABC (Item Classe A em Risco)',
            'mensagem' => "A Base Glicerinada (64,9% dos custos de insumo) está abaixo do ponto de reposição (" . number_format((float)$base['estoque_atual'], 2, ',', '.') . " kg). Emita compra urgente!"
        ];
    }

    echo json_encode(['success' => true, 'diagnosticos' => $diagnosticos]);
    exit;
}

// --------------------------------------------------------------------------
// 2. AVALIAÇÃO DE RESPOSTAS DOS DILEMAS DE ESTUDO DE CASO
// --------------------------------------------------------------------------
if ($action === 'resolver_dilema') {
    $dilema_id = (int) ($_POST['dilema_id'] ?? 0);
    $resposta = trim($_POST['resposta'] ?? '');

    $gabaritos = [
        1 => [
            'titulo' => 'Dilema do Gargalo no Posto 3',
            'conceito' => 'Teoria das Restrições (TOC)',
            'palavras_chave' => ['lote', 'gargalo', 'moldagem', '70', 'pulmao', 'capacidade', '100'],
            'resposta_modelo' => 'O Posto 3 (Moldagem) dita o ritmo da fábrica (70 min/lote). Deve-se programar lotes padrão de 100 barras e manter um pulmão de estoque antes dele para que nunca pare.'
        ],
        2 => [
            'titulo' => 'Dilema da Contaminação Olfativa',
            'conceito' => 'Setup e Sequenciamento',
            'palavras_chave' => ['maracuja', 'rosas', 'suave', 'pesada', '45', 'regra de ouro', 'sequencia'],
            'resposta_modelo' => 'A ordem correta é Maracujá -> Pitanga -> Lavanda -> Rosas. Produzir aromas fortes antes dos suaves exige sanitização pesada de 45 minutos com álcool 70%.'
        ],
        3 => [
            'titulo' => 'Dilema da Curva ABC',
            'conceito' => 'Gestão de Estoques (Pareto)',
            'palavras_chave' => ['classe a', 'glicerina', 'giro', 'rigoroso', 'seguranca', 'valor', '65'],
            'resposta_modelo' => 'A Base Glicerinada é Classe A e exige controle rigoroso e compras programadas, enquanto os corantes (Classe C) podem ser comprados em lotes maiores sem risco de imobilização excessiva.'
        ]
    ];

    if (!isset($gabaritos[$dilema_id])) {
        echo json_encode(['success' => false, 'message' => 'Dilema inválido']);
        exit;
    }

    $d = $gabaritos[$dilema_id];
    $texto_lower = mb_strtolower($resposta);
    $acertos = 0;

    foreach ($d['palavras_chave'] as $kw) {
        if (str_contains($texto_lower, $kw)) {
            $acertos++;
        }
    }

    $aprovado = ($acertos >= 2);
    $pontos_ganhos = $aprovado ? 50 : 0;

    if ($aprovado) {
        $pdo->prepare("UPDATE equipes SET score_total = score_total + ? WHERE id = ?")->execute([$pontos_ganhos, $equipe_id]);
        $msg = "Excelente raciocínio técnico! Você aplicou o conceito de {$d['conceito']} e garantiu +50 pontos no Score da sua equipe.";
    } else {
        $msg = "Análise incompleta. Dica técnica: {$d['resposta_modelo']}";
    }

    echo json_encode([
        'success' => true,
        'aprovado' => $aprovado,
        'pontos_ganhos' => $pontos_ganhos,
        'feedback' => $msg
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Ação não suportada']);