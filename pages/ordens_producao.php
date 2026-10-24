<?php
declare(strict_types=1);

/**
 * Flora Sabão — Ordens de Produção (Business Game)
 * =========================================================
 * Multi-equipe · Multi-rodada · Anti-travamento
 * + ESTAÇÃO DE HIGIENIZAÇÃO POP-04 INTEGRADA (sem API externa)
 * + CONTROLE FINANCEIRO (caixa, custo de MP, falência)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';

$page_title = 'Ordens de Produção (OP)';
$mensagem_sucesso = '';
$mensagem_erro    = '';
$mensagem_aviso   = '';
$mensagem_info    = '';

// =========================================================
// 🛡️ GUARD DE EQUIPE
// =========================================================
$equipe_id = getEquipeAtivaId();
if ($equipe_id <= 0) {
    flash('erro', 'Sua conta não está vinculada a uma equipe. Contate o instrutor.');
    header('Location: ' . (file_exists('pages') ? '' : '../') . 'index.php');
    exit;
}

$equipe = equipeAtual($pdo);

// =========================================================
// 💰 FUNÇÕES FINANCEIRAS
// =========================================================
if (!function_exists('custoBarraProduto')) {
    function custoBarraProduto(PDO $pdo, int $equipe_id, int $produto_id): float
    {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(ft.qtd_por_barra * i.custo_unitario), 0)
            FROM ficha_tecnica ft
            JOIN insumos i ON ft.insumo_id = i.id
            WHERE ft.produto_id = ? AND ft.equipe_id = ?
        ");
        $stmt->execute([$produto_id, $equipe_id]);
        return (float) $stmt->fetchColumn();
    }
}

if (!function_exists('podeProduzir')) {
    function podeProduzir(PDO $pdo, int $equipe_id, int $produto_id, int $qtd): array
    {
        $stmt = $pdo->prepare("SELECT caixa_disponivel, falida FROM equipes WHERE id = ?");
        $stmt->execute([$equipe_id]);
        $eq = $stmt->fetch() ?: ['caixa_disponivel' => 0, 'falida' => 0];
        $caixa  = (float) $eq['caixa_disponivel'];
        $falida = (int) $eq['falida'] === 1;

        $custo_barra = custoBarraProduto($pdo, $equipe_id, $produto_id);
        $custo_total = $custo_barra * $qtd;

        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(
                op.quantidade_planejada * (
                    SELECT COALESCE(SUM(ft.qtd_por_barra * i.custo_unitario), 0)
                    FROM ficha_tecnica ft
                    JOIN insumos i ON ft.insumo_id = i.id
                    WHERE ft.produto_id = op.produto_id
                      AND ft.equipe_id = op.equipe_id
                )
            ), 0)
            FROM ordens_producao op
            WHERE op.equipe_id = ?
              AND op.status IN ('planejada', 'em_producao')
        ");
        $stmt->execute([$equipe_id]);
        $comprometido = (float) $stmt->fetchColumn();

        $disponivel = $caixa - $comprometido;
        $falta      = max(0, $custo_total - $disponivel);

        return [
            'pode'         => (!$falida && $custo_total <= $disponivel),
            'falida'       => $falida,
            'custo_barra'  => $custo_barra,
            'custo_total'  => $custo_total,
            'caixa'        => $caixa,
            'comprometido' => $comprometido,
            'disponivel'   => $disponivel,
            'falta'        => $falta,
        ];
    }
}

// =========================================================
// 🎬 RECUPERAÇÃO ROBUSTA DA RODADA
// =========================================================
$rodada_ativa = getRodadaAtiva($pdo);

if ($rodada_ativa === null) {
    $stmt_fb = $pdo->query("
        SELECT * FROM jogo_rodadas
        ORDER BY numero_rodada DESC, id DESC
        LIMIT 1
    ");
    $rodada_ativa = $stmt_fb->fetch() ?: null;
}

if ($rodada_ativa === null) {
    try {
        $pdo->exec("
            INSERT INTO jogo_rodadas
                (numero_rodada, mes_ano, status, evento_titulo, evento_descricao, fator_demanda)
            VALUES
                (1, 'Mês 01', 'aberta',
                 'Rodada Inicial',
                 'Rodada criada automaticamente pelo sistema para liberar o jogo.',
                 1.00)
        ");
        $rodada_ativa = $pdo->query("
            SELECT * FROM jogo_rodadas ORDER BY id DESC LIMIT 1
        ")->fetch();
        $mensagem_aviso = 'Uma rodada inicial foi criada automaticamente.';
    } catch (Throwable $e) {
        $mensagem_erro = 'Não foi possível preparar uma rodada. Contate o instrutor.';
    }
}

$tem_rodada = ($rodada_ativa !== null);

// =========================================================
// ⚙️ MAPAS AUXILIARES
// =========================================================
$ordem_oficial = ORDEM_AROMAS_RECOMENDADA ?? ['MAR' => 1, 'PIT' => 2, 'LAV' => 3, 'ROS' => 4];

$escala_intensidade = ['suave' => 1, 'moderado' => 2, 'intenso' => 3];

$badge_intensidade = [
    'suave'    => 'bg-info text-dark',
    'moderado' => 'bg-warning text-dark',
    'intenso'  => 'bg-danger text-white',
];

$nome_aroma_amigavel = [
    'LAV' => 'Lavanda (Suave)',
    'ROS' => 'Rosas (Moderado)',
    'PIT' => 'Pitanga (Moderado)',
    'MAR' => 'Maracujá (Intenso)',
    'LIMPO' => 'Tacho Neutro (Limpo)',
];

$custo_pop04        = defined('CUSTO_SANITIZACAO_POP04') ? (float) CUSTO_SANITIZACAO_POP04 : 15.00;
$custo_refugo_barra = defined('CUSTO_ESTIMADO_REFUGO_BARRA') ? (float) CUSTO_ESTIMADO_REFUGO_BARRA : 3.20;
$penalidade_cq_pts  = 80;
$taxa_cq_rejeicao   = 0.50;

// =========================================================
// 🧠 FUNÇÕES LOCAIS
// =========================================================
function simularBOM(PDO $pdo, int $equipe_id, int $produto_id, int $qtd): array
{
    $stmt = $pdo->prepare("
        SELECT ft.insumo_id, ft.qtd_por_barra, i.nome, i.unidade,
               i.estoque_atual, i.estoque_minimo
        FROM ficha_tecnica ft
        JOIN insumos i ON ft.insumo_id = i.id
        WHERE ft.produto_id = ? AND ft.equipe_id = ?
        ORDER BY i.nome
    ");
    $stmt->execute([$produto_id, $equipe_id]);
    $ingredientes = $stmt->fetchAll();

    $itens = [];
    $ruptura = false;

    foreach ($ingredientes as $ing) {
        $necessario = (float) $ing['qtd_por_barra'] * $qtd;
        $disponivel = (float) $ing['estoque_atual'];
        $saldo      = $disponivel - $necessario;
        $falta      = $saldo < 0;
        if ($falta) $ruptura = true;

        $itens[] = [
            'nome'       => $ing['nome'],
            'unidade'    => $ing['unidade'],
            'qtd_barra'  => (float) $ing['qtd_por_barra'],
            'necessario' => $necessario,
            'disponivel' => $disponivel,
            'saldo'      => $saldo,
            'falta'      => $falta,
        ];
    }

    return ['itens' => $itens, 'ruptura' => $ruptura, 'total' => count($itens)];
}

function proximoNumeroOP(PDO $pdo, int $equipe_id, int $numero_rodada): string
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ordens_producao WHERE equipe_id = ?");
    $stmt->execute([$equipe_id]);
    $seq = (int) $stmt->fetchColumn() + 1;
    return sprintf('OP-R%d-E%d-%03d', $numero_rodada, $equipe_id, $seq);
}

function avaliarCompatibilidadeTacho(
    string $ultimo_aroma,
    bool $higienizado,
    string $aroma_alvo,
    array $escala_oficial,
    array $nome_aroma
): array {
    if ($higienizado) {
        return ['precisa_sanitizar' => false, 'motivo' => 'Tacho limpo — pronto para qualquer aroma.'];
    }
    $ordem_ant = $escala_oficial[$ultimo_aroma] ?? 0;
    $ordem_atu = $escala_oficial[$aroma_alvo] ?? 0;
    if ($ordem_ant > $ordem_atu) {
        return [
            'precisa_sanitizar' => true,
            'motivo' => sprintf(
                'Contaminação certa: %s → %s. Sanitização POP-04 obrigatória.',
                $nome_aroma[$ultimo_aroma] ?? $ultimo_aroma,
                $nome_aroma[$aroma_alvo] ?? $aroma_alvo
            ),
        ];
    }
    return [
        'precisa_sanitizar' => false,
        'motivo' => sprintf(
            'Compatível: %s → %s (transição segura na curva olfativa).',
            $nome_aroma[$ultimo_aroma] ?? $ultimo_aroma,
            $nome_aroma[$aroma_alvo] ?? $aroma_alvo
        ),
    ];
}

// =========================================================
// 🧼 ESTADO ATUAL DO TACHO + CAIXA
// =========================================================
$stmt_tacho = $pdo->prepare("
    SELECT tacho_ultimo_aroma, tacho_higienizado, caixa_disponivel, falida, rodadas_no_vermelho
    FROM equipes WHERE id = ?
");
$stmt_tacho->execute([$equipe_id]);
$tacho = $stmt_tacho->fetch() ?: [
    'tacho_ultimo_aroma' => 'LIMPO',
    'tacho_higienizado'  => 1,
    'caixa_disponivel'   => 0,
    'falida'             => 0,
    'rodadas_no_vermelho'=> 0,
];

$tacho_aroma       = $tacho['tacho_ultimo_aroma'] ?? 'LIMPO';
$tacho_higienizado = (int) ($tacho['tacho_higienizado'] ?? 1) === 1;
$caixa_atual       = (float) ($tacho['caixa_disponivel'] ?? 0);
$equipe_falida     = (int) ($tacho['falida'] ?? 0) === 1;
$rodadas_vermelho  = (int) ($tacho['rodadas_no_vermelho'] ?? 0);

// =========================================================
// 📝 PROCESSAMENTO DE POSTS
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // 0. SANITIZAÇÃO
    if ($acao === 'executar_sanitizacao') {
        if ($tacho_higienizado) {
            $mensagem_info = 'O tacho já está higienizado. Nenhuma ação foi necessária.';
        } elseif ($caixa_atual < $custo_pop04) {
            $mensagem_erro = sprintf(
                'Caixa insuficiente para o kit POP-04. Necessário: R$ %s. Disponível: R$ %s.',
                number_format($custo_pop04, 2, ',', '.'),
                number_format($caixa_atual, 2, ',', '.')
            );
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("
                    UPDATE equipes
                    SET caixa_disponivel = caixa_disponivel - ?,
                        tacho_higienizado = 1,
                        tacho_ultimo_aroma = 'LIMPO'
                    WHERE id = ? AND tacho_higienizado = 0
                ")->execute([$custo_pop04, $equipe_id]);
                $pdo->commit();

                $stmt_tacho->execute([$equipe_id]);
                $tacho = $stmt_tacho->fetch();
                $tacho_aroma       = $tacho['tacho_ultimo_aroma'] ?? 'LIMPO';
                $tacho_higienizado = (int) ($tacho['tacho_higienizado'] ?? 1) === 1;
                $caixa_atual       = (float) ($tacho['caixa_disponivel'] ?? 0);

                $mensagem_sucesso = sprintf(
                    '🧼 <strong>POP-04 concluído com sucesso!</strong> Tacho sanitizado. Kit debitado: R$ %s. Saldo atual: R$ %s.',
                    number_format($custo_pop04, 2, ',', '.'),
                    number_format($caixa_atual, 2, ',', '.')
                );
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $mensagem_erro = 'Erro ao executar POP-04: ' . $e->getMessage();
            }
        }
    }

    // 1. CRIAR OP
    if ($acao === 'criar_op') {
        $produto_id       = (int) ($_POST['produto_id'] ?? 0);
        $qtd_planejada    = (int) ($_POST['quantidade_planejada'] ?? 0);
        $data_prog        = trim($_POST['data_programada'] ?? '');
        $obs              = trim($_POST['observacoes'] ?? '');
        $permitir_ruptura = !empty($_POST['permitir_ruptura']);

        if (!$tem_rodada) {
            $mensagem_erro = 'Não há rodada registrada. Não é possível criar OPs.';
        } elseif ($produto_id <= 0 || $qtd_planejada <= 0 || empty($data_prog)) {
            $mensagem_erro = 'Preencha produto, quantidade e data válidos.';
        } elseif ($qtd_planejada % 50 !== 0) {
            $mensagem_erro = 'Lotes devem ser múltiplos de 50 barras.';
        } elseif (strtotime($data_prog) === false) {
            $mensagem_erro = 'Data programada inválida.';
        } elseif (strtotime($data_prog) < strtotime(date('Y-m-d'))) {
            $mensagem_erro = 'A data programada não pode ser retroativa.';
        } else {
            $chk = $pdo->prepare("SELECT id, codigo FROM produtos WHERE id = ? AND equipe_id = ? AND ativo = 1");
            $chk->execute([$produto_id, $equipe_id]);
            $prod = $chk->fetch();

            if (!$prod) {
                $mensagem_erro = 'Produto inválido para a sua equipe.';
            } else {
                $fin = podeProduzir($pdo, $equipe_id, $produto_id, $qtd_planejada);

                if ($fin['falida']) {
                    $mensagem_erro = '🚫 Sua equipe está <strong>FALIDA</strong>. Não é possível emitir novas OPs. Aguarde o instrutor iniciar um novo jogo.';
                } elseif (!$fin['pode']) {
                    $mensagem_erro = sprintf(
                        '💰 <strong>Caixa insuficiente.</strong> Custo desta OP: <strong>R$ %s</strong> (%d barras × R$ %s/barra). Disponível para produção: <strong>R$ %s</strong> (caixa atual R$ %s − OPs em andamento R$ %s). <strong>Faltam R$ %s.</strong> Reduza a quantidade do lote ou finalize OPs em andamento antes de emitir esta.',
                        number_format($fin['custo_total'], 2, ',', '.'),
                        $qtd_planejada,
                        number_format($fin['custo_barra'], 2, ',', '.'),
                        number_format($fin['disponivel'], 2, ',', '.'),
                        number_format($fin['caixa'], 2, ',', '.'),
                        number_format($fin['comprometido'], 2, ',', '.'),
                        number_format($fin['falta'], 2, ',', '.')
                    );
                } else {
                    $sim = simularBOM($pdo, $equipe_id, $produto_id, $qtd_planejada);
                    if ($sim['ruptura'] && !$permitir_ruptura) {
                        $nomes = [];
                        foreach ($sim['itens'] as $it) {
                            if ($it['falta']) {
                                $nomes[] = sprintf('%s (falta %.2f %s)', $it['nome'], abs($it['saldo']), $it['unidade']);
                            }
                        }
                        $mensagem_erro = '⚠️ <strong>Ruptura projetada de insumos:</strong> ' . htmlspecialchars(implode(', ', $nomes)) . '. Marque a opção <em>"Criar mesmo assim"</em> para prosseguir, ou reabasteça o estoque antes.';
                    } else {
                        try {
                            $numero_op = proximoNumeroOP($pdo, $equipe_id, (int) $rodada_ativa['numero_rodada']);
                            $pdo->prepare("
                                INSERT INTO ordens_producao
                                    (equipe_id, rodada_id, numero, produto_id,
                                     quantidade_planejada, data_programada, status, observacoes)
                                VALUES (?, ?, ?, ?, ?, ?, 'planejada', ?)
                            ")->execute([
                                $equipe_id, (int) $rodada_ativa['id'], $numero_op,
                                $produto_id, $qtd_planejada, $data_prog, $obs
                            ]);

                            $mensagem_sucesso = sprintf(
                                '✅ OP <strong>%s</strong> criada com sucesso! Lote de <strong>%d barras</strong> de <strong>%s</strong>. Custo de MP a debitar na finalização: <strong>R$ %s</strong>. Saldo restante para novas OPs: <strong>R$ %s</strong>.',
                                htmlspecialchars($numero_op), $qtd_planejada,
                                htmlspecialchars($prod['codigo']),
                                number_format($fin['custo_total'], 2, ',', '.'),
                                number_format($fin['disponivel'] - $fin['custo_total'], 2, ',', '.')
                            );

                            if ($sim['ruptura']) {
                                $mensagem_aviso = '⚠️ A OP foi criada com <strong>ruptura projetada de insumos</strong>. Reponha o estoque antes de finalizar a produção.';
                            }

                            $stmt_tacho->execute([$equipe_id]);
                            $tacho = $stmt_tacho->fetch();
                            $caixa_atual = (float) ($tacho['caixa_disponivel'] ?? 0);
                        } catch (PDOException $e) {
                            $mensagem_erro = ($e->getCode() == 23000)
                                ? 'Já existe uma OP com esse número. Recarregue a página.'
                                : 'Erro ao criar OP: ' . $e->getMessage();
                        }
                    }
                }
            }
        }
    }

    // 2. INICIAR OP
    if ($acao === 'iniciar_op') {
        $op_id = (int) ($_POST['op_id'] ?? 0);
        if ($op_id <= 0) {
            $mensagem_erro = 'OP inválida.';
        } elseif ($equipe_falida) {
            $mensagem_erro = '🚫 Sua equipe está <strong>FALIDA</strong>. Não é possível iniciar novas produções.';
        } else {
            $stmt_op_info = $pdo->prepare("
                SELECT op.*, p.codigo AS produto_codigo, p.nome AS produto_nome
                FROM ordens_producao op
                JOIN produtos p ON op.produto_id = p.id
                WHERE op.id = ? AND op.equipe_id = ? AND op.status = 'planejada'
            ");
            $stmt_op_info->execute([$op_id, $equipe_id]);
            $op_info = $stmt_op_info->fetch();

            if (!$op_info) {
                $mensagem_erro = 'OP não encontrada ou não está no status "planejada".';
            } else {
                $avaliacao = avaliarCompatibilidadeTacho(
                    $tacho_aroma, $tacho_higienizado,
                    $op_info['produto_codigo'], $ordem_oficial, $nome_aroma_amigavel
                );

                if ($avaliacao['precisa_sanitizar'] && empty($_POST['confirmado_contaminacao'])) {
                    $mensagem_erro = sprintf(
                        '⚠️ ATENÇÃO: %s Se iniciar agora e finalizar sem sanitizar, o CQ reprovará 50%% do lote (-R$ %.2f e -%d pts). Volte e execute o POP-04 antes, ou marque "Assumir risco".',
                        htmlspecialchars($avaliacao['motivo']),
                        $custo_refugo_barra * $op_info['quantidade_planejada'] * $taxa_cq_rejeicao,
                        $penalidade_cq_pts
                    );
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE ordens_producao
                        SET status = 'em_producao', data_inicio = NOW()
                        WHERE id = ? AND equipe_id = ? AND status = 'planejada'
                    ");
                    $stmt->execute([$op_id, $equipe_id]);

                    if ($stmt->rowCount() > 0) {
                        $mensagem_sucesso = 'OP iniciada — status <strong>Em Produção</strong>.';
                        if ($avaliacao['precisa_sanitizar']) {
                            $mensagem_aviso = 'Você assumiu o risco de contaminação. Sanitize antes de finalizar ou o CQ reprovará 50% do lote.';
                        }
                    } else {
                        $mensagem_erro = 'Não foi possível iniciar a OP.';
                    }
                }
            }
        }
    }

    // 3. FINALIZAR OP
    if ($acao === 'finalizar_op') {
        $op_id         = (int) ($_POST['op_id'] ?? 0);
        $qtd_boas_info = (int) ($_POST['quantidade_produzida'] ?? 0);
        $qtd_ref_info  = (int) ($_POST['quantidade_refugo'] ?? 0);

        if ($op_id <= 0 || ($qtd_boas_info + $qtd_ref_info) <= 0) {
            $mensagem_erro = 'A soma de barras boas + refugo deve ser maior que zero.';
        } elseif ($qtd_boas_info < 0 || $qtd_ref_info < 0) {
            $mensagem_erro = 'Quantidades não podem ser negativas.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt_op = $pdo->prepare("
                    SELECT op.*, p.codigo AS produto_codigo, p.nome AS produto_nome, p.intensidade_aroma
                    FROM ordens_producao op
                    JOIN produtos p ON op.produto_id = p.id
                    WHERE op.id = ? AND op.equipe_id = ? FOR UPDATE
                ");
                $stmt_op->execute([$op_id, $equipe_id]);
                $op = $stmt_op->fetch();

                if (!$op) throw new Exception('OP não encontrada.');
                if ($op['status'] === 'finalizada') throw new Exception('Esta OP já foi finalizada.');
                if ($op['status'] === 'cancelada') throw new Exception('OP cancelada não pode ser finalizada.');
                if ($op['status'] !== 'em_producao') throw new Exception('A OP precisa estar "Em Produção".');

                $stmt_eq = $pdo->prepare("
                    SELECT tacho_ultimo_aroma, tacho_higienizado, caixa_disponivel, falida
                    FROM equipes WHERE id = ? FOR UPDATE
                ");
                $stmt_eq->execute([$equipe_id]);
                $eq_tacho = $stmt_eq->fetch();

                if ((int) ($eq_tacho['falida'] ?? 0) === 1) {
                    throw new Exception('Sua equipe está FALIDA. Não é possível finalizar OPs.');
                }

                $ultimo_aroma   = $eq_tacho['tacho_ultimo_aroma'] ?? 'LIMPO';
                $higienizado_eq = (int) ($eq_tacho['tacho_higienizado'] ?? 1) === 1;

                $ordem_ant = $ordem_oficial[$ultimo_aroma] ?? 0;
                $ordem_atu = $ordem_oficial[$op['produto_codigo']] ?? 0;

                $houve_contaminacao = false;
                $penalidade_financeira = 0.0;
                $barras_contaminadas = 0;
                $qtd_produzida = $qtd_boas_info;
                $qtd_refugo    = $qtd_ref_info;

                if (!$higienizado_eq && $ordem_ant > $ordem_atu) {
                    $houve_contaminacao = true;
                    $barras_contaminadas = (int) round($qtd_produzida * $taxa_cq_rejeicao);
                    $qtd_produzida -= $barras_contaminadas;
                    $qtd_refugo    += $barras_contaminadas;
                    $penalidade_financeira = $barras_contaminadas * $custo_refugo_barra;

                    $pdo->prepare("
                        UPDATE equipes
                        SET caixa_disponivel = caixa_disponivel - ?,
                            score_total = GREATEST(0, score_total - ?)
                        WHERE id = ?
                    ")->execute([$penalidade_financeira, $penalidade_cq_pts, $equipe_id]);
                }

                $total_processado = $qtd_produzida + $qtd_refugo;

                $stmt_bom = $pdo->prepare("
                    SELECT ft.insumo_id, ft.qtd_por_barra, i.nome, i.estoque_atual, i.unidade
                    FROM ficha_tecnica ft
                    JOIN insumos i ON ft.insumo_id = i.id
                    WHERE ft.produto_id = ? AND ft.equipe_id = ?
                ");
                $stmt_bom->execute([$op['produto_id'], $equipe_id]);
                $ingredientes = $stmt_bom->fetchAll();

                if (empty($ingredientes)) throw new Exception('Ficha Técnica não configurada.');

                $faltando = [];
                foreach ($ingredientes as $ing) {
                    $nec = (float) $ing['qtd_por_barra'] * $total_processado;
                    if ($nec > (float) $ing['estoque_atual'] + 0.0001) {
                        $faltando[] = sprintf('%s (precisa %.4f, tem %.4f)', $ing['nome'], $nec, (float) $ing['estoque_atual']);
                    }
                }
                if (!empty($faltando)) {
                    throw new Exception('Estoque insuficiente: ' . implode('; ', $faltando));
                }

                foreach ($ingredientes as $ing) {
                    $qtd_cons = (float) $ing['qtd_por_barra'] * $total_processado;

                    $pdo->prepare("
                        UPDATE insumos SET estoque_atual = estoque_atual - ?
                        WHERE id = ? AND equipe_id = ?
                    ")->execute([$qtd_cons, $ing['insumo_id'], $equipe_id]);

                    $pdo->prepare("
                        INSERT INTO mov_estoque
                            (equipe_id, tipo, tipo_item, insumo_id, quantidade, op_id, motivo)
                        VALUES (?, 'saida', 'insumo', ?, ?, ?, ?)
                    ")->execute([$equipe_id, $ing['insumo_id'], $qtd_cons, $op_id, "Consumo OP {$op['numero']}"]);
                }

                if ($qtd_produzida > 0) {
                    $pdo->prepare("
                        UPDATE produtos SET estoque_atual = estoque_atual + ?
                        WHERE id = ? AND equipe_id = ?
                    ")->execute([$qtd_produzida, $op['produto_id'], $equipe_id]);

                    $pdo->prepare("
                        INSERT INTO mov_estoque
                            (equipe_id, tipo, tipo_item, produto_id, quantidade, op_id, motivo)
                        VALUES (?, 'entrada', 'produto', ?, ?, ?, ?)
                    ")->execute([$equipe_id, $op['produto_id'], $qtd_produzida, $op_id, "Entrada PA {$op['numero']}"]);
                }

                // ★ 3.5-bis: DEBITA CUSTO DA MP
                $custo_barra_op = custoBarraProduto($pdo, $equipe_id, (int) $op['produto_id']);
                $custo_total_op = $custo_barra_op * $total_processado;

                $pdo->prepare("
                    UPDATE equipes SET caixa_disponivel = caixa_disponivel - ?
                    WHERE id = ?
                ")->execute([$custo_total_op, $equipe_id]);

                $pdo->prepare("
                    INSERT INTO mov_estoque
                        (equipe_id, tipo, tipo_item, produto_id, quantidade, op_id, motivo)
                    VALUES (?, 'saida', 'produto', ?, 0, ?, ?)
                ")->execute([
                    $equipe_id, $op['produto_id'], $op_id,
                    sprintf("Custo MP OP %s debitado: R$ %s", $op['numero'], number_format($custo_total_op, 2, ',', '.'))
                ]);

                $pdo->prepare("
                    UPDATE equipes
                    SET tacho_ultimo_aroma = ?, tacho_higienizado = 0
                    WHERE id = ?
                ")->execute([$op['produto_codigo'], $equipe_id]);

                $pdo->prepare("
                    UPDATE ordens_producao
                    SET quantidade_produzida = ?, quantidade_refugo = ?,
                        data_finalizacao = NOW(), status = 'finalizada'
                    WHERE id = ? AND equipe_id = ?
                ")->execute([$qtd_produzida, $qtd_refugo, $op_id, $equipe_id]);

                $pdo->commit();

                if ($houve_contaminacao) {
                    $mensagem_erro = sprintf(
                        '🚨 <strong>LAUDO DO CQ: LOTE CONTAMINADO!</strong> Você fabricou <strong>%s</strong> em tacho impregnado com <strong>%s</strong> sem sanitizar. <strong>%d barras viraram refugo</strong>. Prejuízo de <strong>R$ %s</strong> debitado do caixa (multa CQ) + <strong>R$ %s</strong> de custo de MP da OP finalizada. <strong>-%d pts</strong> no score!',
                        htmlspecialchars($op['produto_nome']),
                        htmlspecialchars($nome_aroma_amigavel[$ultimo_aroma] ?? $ultimo_aroma),
                        $barras_contaminadas,
                        number_format($penalidade_financeira, 2, ',', '.'),
                        number_format($custo_total_op, 2, ',', '.'),
                        $penalidade_cq_pts
                    );
                } else {
                    $mensagem_sucesso = sprintf(
                        '✅ OP <strong>%s</strong> finalizada! <strong>%d barras</strong> estocadas com aprovação total do CQ. Custo de MP debitado do caixa: <strong>R$ %s</strong>.',
                        htmlspecialchars($op['numero']), $qtd_produzida, number_format($custo_total_op, 2, ',', '.')
                    );
                }

                $stmt_tacho->execute([$equipe_id]);
                $tacho = $stmt_tacho->fetch();
                $tacho_aroma       = $tacho['tacho_ultimo_aroma'] ?? 'LIMPO';
                $tacho_higienizado = (int) ($tacho['tacho_higienizado'] ?? 1) === 1;
                $caixa_atual       = (float) ($tacho['caixa_disponivel'] ?? 0);
                $equipe_falida     = (int) ($tacho['falida'] ?? 0) === 1;
                $rodadas_vermelho  = (int) ($tacho['rodadas_no_vermelho'] ?? 0);

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $mensagem_erro = $e->getMessage();
            }
        }
    }

    // 4. CANCELAR OP
    if ($acao === 'cancelar_op') {
        $op_id = (int) ($_POST['op_id'] ?? 0);
        if ($op_id <= 0) {
            $mensagem_erro = 'OP inválida.';
        } else {
            $stmt = $pdo->prepare("
                UPDATE ordens_producao SET status = 'cancelada'
                WHERE id = ? AND equipe_id = ? AND status IN ('planejada', 'em_producao')
            ");
            $stmt->execute([$op_id, $equipe_id]);

            if ($stmt->rowCount() > 0) {
                $mensagem_sucesso = 'OP cancelada. O custo reservado volta a ficar disponível no caixa.';
            } else {
                $mensagem_erro = 'Não foi possível cancelar esta OP.';
            }
        }
    }

    // 5. DUPLICAR OP
    if ($acao === 'duplicar_op') {
        $op_id      = (int) ($_POST['op_id'] ?? 0);
        $delta_dias = (int) ($_POST['delta_dias'] ?? 1);

        if ($op_id <= 0 || !$tem_rodada) {
            $mensagem_erro = 'Não é possível duplicar esta OP.';
        } elseif ($equipe_falida) {
            $mensagem_erro = '🚫 Sua equipe está <strong>FALIDA</strong>. Não é possível duplicar OPs.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM ordens_producao WHERE id = ? AND equipe_id = ?");
            $stmt->execute([$op_id, $equipe_id]);
            $orig = $stmt->fetch();

            if (!$orig) {
                $mensagem_erro = 'OP de origem não encontrada.';
            } elseif ($orig['status'] === 'finalizada') {
                $mensagem_erro = 'Não é possível duplicar uma OP finalizada.';
            } else {
                $fin = podeProduzir($pdo, $equipe_id, (int) $orig['produto_id'], (int) $orig['quantidade_planejada']);

                if (!$fin['pode']) {
                    $mensagem_erro = sprintf(
                        '💰 <strong>Caixa insuficiente para duplicar.</strong> Custo da nova OP: R$ %s. Disponível: R$ %s. Faltam R$ %s.',
                        number_format($fin['custo_total'], 2, ',', '.'),
                        number_format($fin['disponivel'], 2, ',', '.'),
                        number_format($fin['falta'], 2, ',', '.')
                    );
                } else {
                    try {
                        $numero_novo = proximoNumeroOP($pdo, $equipe_id, (int) $rodada_ativa['numero_rodada']);
                        $nova_data   = date('Y-m-d', strtotime($orig['data_programada']) + max(1, $delta_dias) * 86400);

                        $pdo->prepare("
                            INSERT INTO ordens_producao
                                (equipe_id, rodada_id, numero, produto_id,
                                 quantidade_planejada, data_programada, status, observacoes)
                            VALUES (?, ?, ?, ?, ?, ?, 'planejada', ?)
                        ")->execute([
                            $equipe_id, (int) $rodada_ativa['id'], $numero_novo,
                            (int) $orig['produto_id'], (int) $orig['quantidade_planejada'],
                            $nova_data, 'Duplicada de ' . $orig['numero']
                        ]);

                        $mensagem_sucesso = sprintf(
                            'OP <strong>%s</strong> duplicada com sucesso! Custo reservado: R$ %s.',
                            htmlspecialchars($numero_novo),
                            number_format($fin['custo_total'], 2, ',', '.')
                        );
                    } catch (Throwable $e) {
                        $mensagem_erro = 'Erro ao duplicar: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

// =========================================================
// 📊 CONSULTAS DA LISTAGEM
// =========================================================
$filtro_status  = $_GET['status'] ?? 'todos';
$filtro_produto = (int) ($_GET['produto_id'] ?? 0);
$busca          = trim($_GET['q'] ?? '');

$status_validos = ['todos', 'planejada', 'em_producao', 'finalizada', 'cancelada'];
if (!in_array($filtro_status, $status_validos, true)) $filtro_status = 'todos';

$sql = "SELECT op.*, p.nome AS produto_nome, p.codigo AS produto_codigo,
               p.aroma, p.intensidade_aroma, p.preco_venda
        FROM ordens_producao op
        JOIN produtos p ON op.produto_id = p.id
        WHERE op.equipe_id = ?";
$params = [$equipe_id];

if ($filtro_status !== 'todos') { $sql .= " AND op.status = ?"; $params[] = $filtro_status; }
if ($filtro_produto > 0) { $sql .= " AND op.produto_id = ?"; $params[] = $filtro_produto; }
if ($busca !== '') {
    $sql .= " AND (op.numero LIKE ? OR p.nome LIKE ? OR p.codigo LIKE ?)";
    $like = '%' . $busca . '%';
    array_push($params, $like, $like, $like);
}
$sql .= " ORDER BY FIELD(op.status, 'em_producao','planejada','finalizada','cancelada'),
          op.data_programada ASC, op.id ASC";

$stmt_ops = $pdo->prepare($sql);
$stmt_ops->execute($params);
$ordens = $stmt_ops->fetchAll();

$stmt_prods = $pdo->prepare("
    SELECT id, codigo, nome, aroma, intensidade_aroma, preco_venda
    FROM produtos WHERE equipe_id = ? AND ativo = 1
    ORDER BY CASE codigo WHEN 'MAR' THEN 1 WHEN 'PIT' THEN 2
                          WHEN 'LAV' THEN 3 WHEN 'ROS' THEN 4 ELSE 99 END, nome
");
$stmt_prods->execute([$equipe_id]);
$produtos_select = $stmt_prods->fetchAll();

// Resumo financeiro
$comprometido_total = 0.0;
foreach ($ordens as $opX) {
    if (!in_array($opX['status'], ['planejada', 'em_producao'], true)) continue;
    $comprometido_total += custoBarraProduto($pdo, $equipe_id, (int) $opX['produto_id'])
        * (int) $opX['quantidade_planejada'];
}
$caixa_livre = $caixa_atual - $comprometido_total;

// Métricas
$metricas = [
    'planejadas' => 0, 'em_producao' => 0, 'finalizadas' => 0,
    'canceladas' => 0, 'total' => 0, 'boas' => 0,
    'refugo' => 0, 'planejado_un' => 0,
];

if ($tem_rodada) {
    $stmt_met = $pdo->prepare("
        SELECT COUNT(*) AS total,
            SUM(CASE WHEN status = 'planejada'   THEN 1 ELSE 0 END) AS planejadas,
            SUM(CASE WHEN status = 'em_producao' THEN 1 ELSE 0 END) AS em_producao,
            SUM(CASE WHEN status = 'finalizada'  THEN 1 ELSE 0 END) AS finalizadas,
            SUM(CASE WHEN status = 'cancelada'   THEN 1 ELSE 0 END) AS canceladas,
            COALESCE(SUM(quantidade_produzida), 0) AS boas,
            COALESCE(SUM(quantidade_refugo), 0)    AS refugo,
            COALESCE(SUM(quantidade_planejada), 0) AS planejado_un
        FROM ordens_producao WHERE equipe_id = ? AND rodada_id = ?
    ");
    $stmt_met->execute([$equipe_id, (int) $rodada_ativa['id']]);
    $row_met = $stmt_met->fetch();
    if ($row_met) foreach ($metricas as $k => $_) $metricas[$k] = (int) ($row_met[$k] ?? 0);
}

$total_processado_rodada = $metricas['boas'] + $metricas['refugo'];
$taxa_refugo_rodada = $total_processado_rodada > 0
    ? round(($metricas['refugo'] / $total_processado_rodada) * 100, 2) : 0.0;
$aderencia_rodada = $metricas['planejado_un'] > 0
    ? round(min(100, ($metricas['boas'] / $metricas['planejado_un']) * 100), 1) : 0.0;
$yield_rodada = $total_processado_rodada > 0
    ? round(($metricas['boas'] / $total_processado_rodada) * 100, 1) : 100.0;

// Timeline
$timeline = [];
foreach ($ordens as $op) {
    if (!in_array($op['status'], ['planejada', 'em_producao'], true)) continue;
    $timeline[$op['data_programada']][] = $op;
}
ksort($timeline);

$dias_timeline = count($timeline);
$max_carga_dia = 0;
foreach ($timeline as $ops_dia) $max_carga_dia = max($max_carga_dia, count($ops_dia));

// Diagnóstico preditivo
$proximas_ops_ativas = array_filter($ordens, function ($op) {
    return in_array($op['status'], ['planejada', 'em_producao'], true);
});

$proxima_op_contaminacao = null;
foreach ($proximas_ops_ativas as $op) {
    $aval = avaliarCompatibilidadeTacho($tacho_aroma, $tacho_higienizado, $op['produto_codigo'], $ordem_oficial, $nome_aroma_amigavel);
    if ($aval['precisa_sanitizar']) { $proxima_op_contaminacao = $op; break; }
}

$stmt_hist_sanit = $pdo->prepare("SELECT COUNT(*) FROM ordens_producao WHERE equipe_id = ? AND status = 'finalizada'");
$stmt_hist_sanit->execute([$equipe_id]);
$total_ops_finalizadas = (int) $stmt_hist_sanit->fetchColumn();

// =========================================================
// 🎨 RENDER
// =========================================================
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<style>
    .op-row-atrasada    { background-color: #fff3cd !important; }
    .op-row-cancelada   { opacity: .65; }
    .timeline-dot { width: 12px; height: 12px; border-radius: 50%; display: inline-block; margin-right: 6px; }
    .timeline-linha { border-left: 3px solid #dee2e6; padding-left: 18px; margin-left: 6px; }
    .btn-group-sm .btn { padding: .2rem .45rem; font-size: .78rem; }
    .tacho-card-critical { animation: pulse-border 1.6s ease-in-out infinite; }
    @keyframes pulse-border {
        0%, 100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.4); }
        50%      { box-shadow: 0 0 0 8px rgba(220, 53, 69, 0); }
    }
    .pop04-step { border-left: 4px solid #dee2e6; padding: 8px 12px; margin-bottom: 6px; transition: all .15s ease; }
    .pop04-step.done { border-left-color: #198754; background: #f8f9fa; }
    .sanitization-progress { height: 10px; border-radius: 5px; overflow: hidden; }
    .caixa-card { background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border-left: 4px solid #0d6efd; }
    @media print {
        .btn-group, .card-custom:hover { box-shadow: none !important; }
        .no-print { display: none !important; }
    }
</style>

<div class="container-fluid px-4 pb-4">

    <!-- ALERTA DE FALÊNCIA -->
    <?php if ($equipe_falida): ?>
        <div class="alert alert-danger border-0 shadow-lg d-flex align-items-center mb-4">
            <i class="bi bi-x-octagon-fill fs-1 me-3"></i>
            <div>
                <h4 class="fw-bold mb-1">🚫 EQUIPE FALIDA</h4>
                <p class="mb-0 small">O caixa ficou negativo por 2 rodadas consecutivas. A equipe está fora da competição. Nenhuma OP pode ser emitida, iniciada, duplicada ou finalizada até o instrutor reiniciar o jogo.</p>
            </div>
        </div>
    <?php elseif ($caixa_atual < 0): ?>
        <div class="alert alert-warning border-0 shadow-sm d-flex align-items-center mb-4">
            <i class="bi bi-exclamation-triangle-fill fs-1 me-3 text-warning"></i>
            <div>
                <h5 class="fw-bold mb-1">⚠️ RISCO DE FALÊNCIA</h5>
                <p class="mb-0 small">
                    Caixa negativo: <strong>R$ <?= number_format($caixa_atual, 2, ',', '.') ?></strong>.
                    <?php if ($rodadas_vermelho >= 1): ?>
                        Já são <strong><?= $rodadas_vermelho ?> rodada(s) no vermelho</strong>. Se fechar a próxima rodada negativa, a equipe será <strong>declarada falida</strong>.
                    <?php else: ?>
                        Finalize a próxima rodada com lucro para regularizar o caixa.
                    <?php endif; ?>
                </p>
            </div>
        </div>
    <?php endif; ?>

    <!-- CABEÇALHO -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h2 class="fw-bold mb-1 text-dark"><i class="bi bi-clipboard2-check text-success me-2"></i>Ordens de Produção (OP)</h2>
            <p class="text-muted mb-0">Emissão, controle e apontamento de lotes com explosão automática de materiais.</p>
        </div>
        <div class="d-flex align-items-center flex-wrap gap-2">
            <?php if ($equipe): ?>
                <span class="badge bg-white text-dark border shadow-sm py-2 px-3">
                    <i class="bi bi-people-fill me-1" style="color: <?= h($equipe['cor_hex'] ?? '#2d5a3f') ?>;"></i>
                    <?= h($equipe['nome']) ?>
                </span>
            <?php endif; ?>
            <?php if ($rodada_ativa): ?>
                <span class="badge bg-success-subtle text-success border border-success-subtle py-2 px-3">
                    <i class="bi bi-calendar-event me-1"></i> R<?= (int) $rodada_ativa['numero_rodada'] ?> · <?= h($rodada_ativa['mes_ano'] ?? '') ?>
                </span>
            <?php endif; ?>
            <?php if ($equipe_falida): ?>
                <button class="btn btn-secondary shadow-sm" disabled><i class="bi bi-slash-circle me-1"></i> Produção Bloqueada</button>
            <?php else: ?>
                <button class="btn btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#modalNovaOP">
                    <i class="bi bi-plus-circle me-1"></i> Emitir Nova OP
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- 💰 PAINEL FINANCEIRO -->
    <div class="card caixa-card p-3 mb-4 shadow-sm">
        <div class="row g-3 align-items-center">
            <div class="col-12 col-md-3 border-end">
                <span class="text-muted small text-uppercase fw-bold d-block">Caixa Atual</span>
                <h4 class="fw-bold my-1 <?= $caixa_atual < 0 ? 'text-danger' : 'text-success' ?>">R$ <?= number_format($caixa_atual, 2, ',', '.') ?></h4>
                <small class="text-muted">Disponível na conta da equipe</small>
            </div>
            <div class="col-12 col-md-3 border-end">
                <span class="text-muted small text-uppercase fw-bold d-block">Comprometido em OPs</span>
                <h4 class="fw-bold my-1 text-warning-emphasis">R$ <?= number_format($comprometido_total, 2, ',', '.') ?></h4>
                <small class="text-muted">Custo de MP das OPs planejadas/em produção</small>
            </div>
            <div class="col-12 col-md-3 border-end">
                <span class="text-muted small text-uppercase fw-bold d-block">Caixa Livre</span>
                <h4 class="fw-bold my-1 <?= $caixa_livre < 0 ? 'text-danger' : 'text-primary' ?>">R$ <?= number_format($caixa_livre, 2, ',', '.') ?></h4>
                <small class="text-muted">Quanto ainda pode investir em novas OPs</small>
            </div>
            <div class="col-12 col-md-3">
                <span class="text-muted small text-uppercase fw-bold d-block">Status Financeiro</span>
                <?php if ($equipe_falida): ?>
                    <span class="badge bg-danger fs-6 px-3 py-2">🚫 FALIDA</span>
                <?php elseif ($rodadas_vermelho > 0): ?>
                    <span class="badge bg-warning text-dark fs-6 px-3 py-2">⚠️ <?= $rodadas_vermelho ?>/2 no vermelho</span>
                <?php elseif ($caixa_atual < 0): ?>
                    <span class="badge bg-warning text-dark fs-6 px-3 py-2">⚠️ Risco (1 rodada no vermelho)</span>
                <?php else: ?>
                    <span class="badge bg-success fs-6 px-3 py-2">✅ Saudável</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 🧼 TACHO -->
    <div class="card card-custom p-3 bg-white mb-4 shadow-sm border-start border-4 <?= $tacho_higienizado ? 'border-success' : 'border-danger tacho-card-critical' ?>" id="cardStatusTacho">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="fs-1">
                    <?php if ($tacho_higienizado): ?>
                        <i class="bi bi-check-circle-fill text-success"></i>
                    <?php else: ?>
                        <i class="bi bi-exclamation-octagon-fill text-danger"></i>
                    <?php endif; ?>
                </div>
                <div>
                    <span class="text-muted small text-uppercase fw-bold">Estação de Fusão & Tacho Principal</span>
                    <?php if ($tacho_higienizado): ?>
                        <h5 class="fw-bold my-0 text-success">Tacho 100% Higienizado e Neutro</h5>
                        <small class="text-muted">Pronto para fabricar qualquer fragrância sem risco de contaminação cruzada.</small>
                    <?php else: ?>
                        <h5 class="fw-bold my-0 text-danger">ALERTA: Tacho impregnado com [<?= h($nome_aroma_amigavel[$tacho_aroma] ?? $tacho_aroma) ?>]</h5>
                        <small class="text-muted">Fabricar um aroma mais suave agora causará <strong>reprovação do lote pelo CQ</strong> (-50% da produção).</small>
                    <?php endif; ?>
                </div>
            </div>
            <div class="d-flex flex-column align-items-end gap-2">
                <div class="small text-muted">Caixa disponível: <strong>R$ <?= number_format($caixa_atual, 2, ',', '.') ?></strong></div>
                <?php if ($tacho_higienizado): ?>
                    <button type="button" class="btn btn-outline-success shadow-sm btn-sm" disabled><i class="bi bi-check2 me-1"></i> Equipamento Limpo</button>
                <?php else: ?>
                    <button type="button" class="btn btn-danger shadow-sm fw-bold px-3 py-2" data-bs-toggle="modal" data-bs-target="#modalSanitizacao">
                        <i class="bi bi-droplet-half me-1"></i> Executar POP-04 (Sanitizar)
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($proxima_op_contaminacao && !$tacho_higienizado): ?>
            <hr class="my-3">
            <div class="alert alert-warning border-0 mb-0 small">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong>Atenção preditiva:</strong> A próxima OP <code><?= h($proxima_op_contaminacao['numero']) ?></code> (<?= h($proxima_op_contaminacao['produto_nome']) ?>) violará a Regra de Ouro. <strong>Sanitize o tacho antes</strong> ou o CQ rejeitará 50% do lote.
            </div>
        <?php endif; ?>
    </div>

    <!-- BANNER DA RODADA -->
    <?php if ($rodada_ativa): ?>
        <div class="alert alert-success border-0 shadow-sm d-flex align-items-start mb-4" role="alert">
            <i class="bi bi-calendar-event-fill fs-3 me-3 text-success"></i>
            <div class="flex-grow-1">
                <strong class="d-block">Rodada <?= (int) $rodada_ativa['numero_rodada'] ?> · <?= h($rodada_ativa['mes_ano'] ?? '') ?> · <?= h($rodada_ativa['evento_titulo'] ?? '—') ?></strong>
                <span class="small"><?= h($rodada_ativa['evento_descricao'] ?? 'Sem descrição de evento.') ?></span>
            </div>
            <div class="d-flex flex-column align-items-end">
                <span class="badge bg-success-subtle text-success border border-success-subtle">Fator: <?= number_format((float) ($rodada_ativa['fator_demanda'] ?? 1), 2, ',', '.') ?>×</span>
                <small class="text-muted mt-1">Status: <strong><?= h($rodada_ativa['status'] ?? '—') ?></strong></small>
            </div>
        </div>
    <?php endif; ?>

    <!-- ALERTAS FLASH -->
    <?php if (!empty($mensagem_sucesso)): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> <?= $mensagem_sucesso ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($mensagem_erro)): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= $mensagem_erro ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($mensagem_aviso)): ?>
        <div class="alert alert-info alert-dismissible fade show border-0 shadow-sm" role="alert">
            <i class="bi bi-info-circle-fill me-2"></i> <?= $mensagem_aviso ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($mensagem_info)): ?>
        <div class="alert alert-secondary alert-dismissible fade show border-0 shadow-sm" role="alert">
            <i class="bi bi-info-circle me-2"></i> <?= $mensagem_info ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- CARDS DE MÉTRICAS -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3 col-xl-2"><div class="card card-custom p-3 h-100 text-center"><span class="text-muted small text-uppercase fw-semibold">Planejadas</span><h3 class="fw-bold my-1 text-secondary"><?= $metricas['planejadas'] ?></h3><small class="text-muted">aguardando</small></div></div>
        <div class="col-6 col-lg-3 col-xl-2"><div class="card card-custom p-3 h-100 text-center"><span class="text-muted small text-uppercase fw-semibold">Em Produção</span><h3 class="fw-bold my-1 text-warning"><?= $metricas['em_producao'] ?></h3><small class="text-muted">no chão</small></div></div>
        <div class="col-6 col-lg-3 col-xl-2"><div class="card card-custom p-3 h-100 text-center"><span class="text-muted small text-uppercase fw-semibold">Finalizadas</span><h3 class="fw-bold my-1 text-success"><?= $metricas['finalizadas'] ?></h3><small class="text-muted">concluídas</small></div></div>
        <div class="col-6 col-lg-3 col-xl-2"><div class="card card-custom p-3 h-100 text-center"><span class="text-muted small text-uppercase fw-semibold">Canceladas</span><h3 class="fw-bold my-1 text-danger"><?= $metricas['canceladas'] ?></h3><small class="text-muted">abortadas</small></div></div>
        <div class="col-6 col-lg-3 col-xl-2"><div class="card card-custom p-3 h-100 text-center"><span class="text-muted small text-uppercase fw-semibold">Boas (un)</span><h3 class="fw-bold my-1 text-dark"><?= number_format($metricas['boas'], 0, ',', '.') ?></h3><small class="text-muted">aprovadas</small></div></div>
        <div class="col-6 col-lg-3 col-xl-2"><div class="card card-custom p-3 h-100 text-center"><span class="text-muted small text-uppercase fw-semibold">Refugo</span><h3 class="fw-bold my-1 <?= $metricas['refugo'] > 0 ? 'text-danger' : 'text-muted' ?>"><?= number_format($metricas['refugo'], 0, ',', '.') ?></h3><small class="text-muted">perdas</small></div></div>
    </div>

    <!-- KPIs SECUNDÁRIOS -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-4"><div class="card card-custom p-3 h-100 bg-white"><div class="d-flex justify-content-between align-items-center mb-2"><span class="text-muted small text-uppercase fw-semibold">Aderência ao Plano</span><span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?= $aderencia_rodada ?>%</span></div><div class="progress" style="height: 8px;"><div class="progress-bar bg-primary" style="width: <?= min(100, $aderencia_rodada) ?>%"></div></div><small class="text-muted mt-2"><?= number_format($metricas['boas'], 0, ',', '.') ?> de <?= number_format($metricas['planejado_un'], 0, ',', '.') ?> un planejadas</small></div></div>
        <div class="col-12 col-md-4"><div class="card card-custom p-3 h-100 bg-white"><div class="d-flex justify-content-between align-items-center mb-2"><span class="text-muted small text-uppercase fw-semibold">Taxa de Refugo</span><span class="badge bg-<?= $taxa_refugo_rodada <= 3 ? 'success-subtle text-success' : 'danger-subtle text-danger' ?> border"><?= number_format($taxa_refugo_rodada, 2, ',', '.') ?>%</span></div><div class="progress" style="height: 8px;"><div class="progress-bar <?= $taxa_refugo_rodada <= 3 ? 'bg-success' : 'bg-danger' ?>" style="width: <?= min(100, $taxa_refugo_rodada * 10) ?>%"></div></div><small class="text-muted mt-2">Meta oficial ≤ 3,0%</small></div></div>
        <div class="col-12 col-md-4"><div class="card card-custom p-3 h-100 bg-white"><div class="d-flex justify-content-between align-items-center mb-2"><span class="text-muted small text-uppercase fw-semibold">Yield de Qualidade</span><span class="badge bg-success-subtle text-success border border-success-subtle"><?= $yield_rodada ?>%</span></div><div class="progress" style="height: 8px;"><div class="progress-bar bg-success" style="width: <?= $yield_rodada ?>%"></div></div><small class="text-muted mt-2">Boas / (Boas + Refugo)</small></div></div>
    </div>

    <!-- FILTROS -->
    <div class="card card-custom bg-white p-3 mb-4">
        <form method="GET" action="ordens_producao.php" class="row g-2 align-items-end no-print">
            <div class="col-12 col-md-6">
                <label class="form-label small fw-semibold text-muted mb-1">Filtrar por Status</label>
                <div class="d-flex flex-wrap gap-1">
                    <a href="?status=todos&produto_id=<?= $filtro_produto ?>&q=<?= urlencode($busca) ?>" class="btn btn-sm <?= $filtro_status === 'todos' ? 'btn-dark' : 'btn-outline-secondary' ?>">Todas (<?= $metricas['total'] ?>)</a>
                    <a href="?status=planejada&produto_id=<?= $filtro_produto ?>&q=<?= urlencode($busca) ?>" class="btn btn-sm <?= $filtro_status === 'planejada' ? 'btn-secondary text-white' : 'btn-outline-secondary' ?>">Planejadas (<?= $metricas['planejadas'] ?>)</a>
                    <a href="?status=em_producao&produto_id=<?= $filtro_produto ?>&q=<?= urlencode($busca) ?>" class="btn btn-sm <?= $filtro_status === 'em_producao' ? 'btn-warning text-dark' : 'btn-outline-warning text-dark' ?>">Em Produção (<?= $metricas['em_producao'] ?>)</a>
                    <a href="?status=finalizada&produto_id=<?= $filtro_produto ?>&q=<?= urlencode($busca) ?>" class="btn btn-sm <?= $filtro_status === 'finalizada' ? 'btn-success' : 'btn-outline-success' ?>">Finalizadas (<?= $metricas['finalizadas'] ?>)</a>
                    <a href="?status=cancelada&produto_id=<?= $filtro_produto ?>&q=<?= urlencode($busca) ?>" class="btn btn-sm <?= $filtro_status === 'cancelada' ? 'btn-danger' : 'btn-outline-danger' ?>">Canceladas (<?= $metricas['canceladas'] ?>)</a>
                </div>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold text-muted mb-1">Produto</label>
                <select name="produto_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="0">Todos os produtos</option>
                    <?php foreach ($produtos_select as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= $filtro_produto === (int) $p['id'] ? 'selected' : '' ?>>[<?= h($p['codigo']) ?>] <?= h($p['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="status" value="<?= h($filtro_status) ?>">
                <input type="hidden" name="q" value="<?= h($busca) ?>">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label small fw-semibold text-muted mb-1">Buscar OP</label>
                <input type="text" name="q" class="form-control form-control-sm" value="<?= h($busca) ?>" placeholder="OP-R1-E1-001">
            </div>
            <div class="col-12 col-md-1">
                <button type="submit" class="btn btn-sm btn-dark w-100"><i class="bi bi-funnel"></i></button>
            </div>
        </form>
    </div>

    <!-- LISTA DE OPs -->
    <div class="d-flex justify-content-between align-items-center mb-2 no-print">
        <h5 class="fw-bold mb-0"><i class="bi bi-list-task me-2"></i>Lista de Ordens (<?= count($ordens) ?>)</h5>
        <div class="btn-group btn-group-sm">
            <button class="btn btn-outline-success" id="btnExportarCSV"><i class="bi bi-download me-1"></i> CSV</button>
            <button class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i> Imprimir</button>
        </div>
    </div>

    <div class="card card-custom bg-white p-3 mb-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="tabelaOPs">
                <thead class="table-light small text-uppercase">
                    <tr>
                        <th style="width: 140px;">Número OP</th>
                        <th>Fragrância</th>
                        <th style="width: 105px;">Programada</th>
                        <th style="width: 85px;" class="text-end">Planejado</th>
                        <th style="width: 160px;" class="text-center">Apontado</th>
                        <th style="width: 130px;">Status</th>
                        <th style="width: 260px;" class="text-end no-print">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ordens)): ?>
                        <tr><td colspan="7" class="text-center py-5 text-muted"><i class="bi bi-inbox fs-2 d-block mb-2"></i>Nenhuma ordem de produção encontrada.<br><small>Clique em <strong>Emitir Nova OP</strong> para começar.</small></td></tr>
                    <?php else: ?>
                        <?php foreach ($ordens as $op):
                            $st = STATUS_OP[$op['status']] ?? ['label' => $op['status'], 'badge' => 'secondary'];
                            $is_atrasada = in_array($op['status'], ['planejada', 'em_producao'], true) && strtotime($op['data_programada']) < strtotime(date('Y-m-d'));
                            $row_class = '';
                            if ($is_atrasada) $row_class = 'op-row-atrasada';
                            if ($op['status'] === 'cancelada') $row_class = 'op-row-cancelada';
                            $diag_tacho = avaliarCompatibilidadeTacho($tacho_aroma, $tacho_higienizado, $op['produto_codigo'], $ordem_oficial, $nome_aroma_amigavel);
                            $mostrar_alerta_tacho = $diag_tacho['precisa_sanitizar'] && in_array($op['status'], ['planejada'], true);
                            $custo_op = custoBarraProduto($pdo, $equipe_id, (int) $op['produto_id']) * (int) $op['quantidade_planejada'];
                        ?>
                            <tr class="<?= $row_class ?>">
                                <td class="fw-bold font-monospace text-dark small">
                                    <?= h($op['numero']) ?>
                                    <?php if (!empty($op['observacoes'])): ?><i class="bi bi-info-circle text-muted ms-1" title="<?= h($op['observacoes']) ?>"></i><?php endif; ?>
                                    <?php if ($is_atrasada): ?><span class="badge bg-danger d-block mt-1" style="font-size:.65rem;">Atrasada</span><?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-1">
                                        <span class="badge bg-light text-dark border font-monospace"><?= h($op['produto_codigo']) ?></span>
                                        <span class="badge <?= $badge_intensidade[$op['intensidade_aroma']] ?? 'bg-secondary' ?>" style="font-size:.68rem;"><?= ucfirst(h($op['intensidade_aroma'])) ?></span>
                                    </div>
                                    <strong class="d-block mt-1"><?= h($op['produto_nome']) ?></strong>
                                    <small class="text-muted"><?= h($op['aroma']) ?> · Custo MP: <strong>R$ <?= number_format($custo_op, 2, ',', '.') ?></strong></small>
                                </td>
                                <td class="small"><?= date('d/m/Y', strtotime($op['data_programada'])) ?></td>
                                <td class="fw-bold text-end"><?= number_format((int) $op['quantidade_planejada'], 0, ',', '.') ?><small class="text-muted d-block">un</small></td>
                                <td class="text-center">
                                    <?php if ($op['status'] === 'finalizada'): ?>
                                        <div class="small"><span class="text-success fw-bold"><?= number_format((int) $op['quantidade_produzida'], 0, ',', '.') ?> boas</span><?php if ((int) $op['quantidade_refugo'] > 0): ?><br><span class="text-danger"><?= number_format((int) $op['quantidade_refugo'], 0, ',', '.') ?> refugo</span><?php endif; ?></div>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $st['badge'] ?> badge-status"><?= $st['label'] ?></span>
                                    <?php if ($mostrar_alerta_tacho): ?>
                                        <span class="badge bg-warning text-dark d-block mt-1" style="font-size:.65rem;" title="<?= h($diag_tacho['motivo']) ?>"><i class="bi bi-exclamation-triangle-fill me-1"></i>Tacho incompatível</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end no-print">
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-secondary btn-ver-bom" data-op-id="<?= (int) $op['id'] ?>" data-op-num="<?= h($op['numero']) ?>" data-prod-id="<?= (int) $op['produto_id'] ?>" data-qtd="<?= (int) $op['quantidade_planejada'] ?>" title="Explosão de Materiais"><i class="bi bi-diagram-2"></i></button>
                                        <button type="button" class="btn btn-outline-info btn-detalhes" data-op-id="<?= (int) $op['id'] ?>" data-op-num="<?= h($op['numero']) ?>" data-prod-nome="<?= h($op['produto_nome']) ?>" data-prod-cod="<?= h($op['produto_codigo']) ?>" data-qtd="<?= (int) $op['quantidade_planejada'] ?>" data-data="<?= date('d/m/Y', strtotime($op['data_programada'])) ?>" data-status="<?= h($st['label']) ?>" data-obs="<?= h($op['observacoes'] ?? '') ?>" title="Detalhes da OP"><i class="bi bi-eye"></i></button>
                                        <?php if ($op['status'] === 'planejada' && !$equipe_falida): ?>
                                            <button type="button" class="btn btn-outline-warning text-dark btn-iniciar" data-op-id="<?= (int) $op['id'] ?>" data-op-num="<?= h($op['numero']) ?>" data-tacho-incompat="<?= $mostrar_alerta_tacho ? '1' : '0' ?>" data-tacho-motivo="<?= h($diag_tacho['motivo']) ?>" title="Iniciar Fabricação"><i class="bi bi-play-fill"></i></button>
                                        <?php endif; ?>
                                        <?php if ($op['status'] === 'em_producao' && !$equipe_falida): ?>
                                            <button type="button" class="btn btn-outline-success btn-apontar" data-op-id="<?= (int) $op['id'] ?>" data-op-num="<?= h($op['numero']) ?>" data-qtd="<?= (int) $op['quantidade_planejada'] ?>" title="Apontar Produção"><i class="bi bi-check2-circle"></i></button>
                                        <?php endif; ?>
                                        <?php if (in_array($op['status'], ['planejada', 'em_producao'], true) && !$equipe_falida): ?>
                                            <form method="POST" action="ordens_producao.php" class="d-inline" onsubmit="return confirm('Duplicar a OP <?= h($op['numero']) ?> para +1 dia?');">
                                                <input type="hidden" name="acao" value="duplicar_op">
                                                <input type="hidden" name="op_id" value="<?= (int) $op['id'] ?>">
                                                <input type="hidden" name="delta_dias" value="1">
                                                <button type="submit" class="btn btn-outline-primary" title="Duplicar OP"><i class="bi bi-files"></i></button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if (in_array($op['status'], ['planejada', 'em_producao'], true)): ?>
                                            <form method="POST" action="ordens_producao.php" class="d-inline" onsubmit="return confirm('Cancelar definitivamente a OP <?= h($op['numero']) ?>?');">
                                                <input type="hidden" name="acao" value="cancelar_op">
                                                <input type="hidden" name="op_id" value="<?= (int) $op['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger" title="Cancelar OP"><i class="bi bi-x-circle"></i></button>
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

    <!-- TIMELINE -->
    <?php if ($dias_timeline > 0): ?>
        <div class="card card-custom bg-white p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0"><i class="bi bi-calendar-week me-2 text-primary"></i>Timeline de Produção (Próximos Dias)</h5>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?= $dias_timeline ?> dia(s) programado(s) · carga máx. <?= $max_carga_dia ?> OP(s)/dia</span>
            </div>
            <div class="timeline-linha">
                <?php foreach ($timeline as $dia => $ops_dia):
                    $total_un = array_sum(array_column($ops_dia, 'quantidade_planejada'));
                    $is_hoje  = $dia === date('Y-m-d');
                ?>
                    <div class="mb-3">
                        <div class="d-flex align-items-center mb-2">
                            <span class="timeline-dot <?= $is_hoje ? 'bg-success' : 'bg-primary' ?>"></span>
                            <strong class="<?= $is_hoje ? 'text-success' : 'text-dark' ?>"><?= date('d/m/Y', strtotime($dia)) ?> <?= $is_hoje ? '(hoje)' : '' ?></strong>
                            <span class="badge bg-light text-dark border ms-2 small"><?= count($ops_dia) ?> OP(s) · <?= number_format($total_un, 0, ',', '.') ?> un</span>
                        </div>
                        <div class="d-flex flex-wrap gap-1 ms-4">
                            <?php foreach ($ops_dia as $opdia):
                                $st_dia = STATUS_OP[$opdia['status']] ?? ['label' => $opdia['status'], 'badge' => 'secondary'];
                            ?>
                                <span class="badge bg-<?= $st_dia['badge'] ?>-subtle text-<?= $st_dia['badge'] ?> border border-<?= $st_dia['badge'] ?>-subtle" style="font-size:.72rem;"><i class="bi bi-tag me-1"></i><?= h($opdia['numero']) ?> · <?= h($opdia['produto_codigo']) ?> · <?= number_format((int) $opdia['quantidade_planejada'], 0, ',', '.') ?>un</span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- PAINEL EDUCATIVO -->
    <div class="card card-custom bg-white p-4 mb-4 border-start border-4 border-danger">
        <h5 class="fw-bold text-dark mb-3"><i class="bi bi-shield-check text-danger me-2"></i>Como o Controle de Qualidade (CQ) Avalia seus Lotes</h5>
        <div class="row g-3">
            <div class="col-12 col-md-4"><div class="p-3 bg-light rounded-3 h-100"><strong class="text-dark d-block mb-1"><i class="bi bi-check-circle-fill text-success me-1"></i> Lote Aprovado</strong><p class="small text-muted mb-0">Tacho <strong>higienizado</strong> <u>ou</u> transição entre aromas <strong>compatível</strong>. 100% das barras boas são estocadas.</p></div></div>
            <div class="col-12 col-md-4"><div class="p-3 bg-light rounded-3 h-100"><strong class="text-dark d-block mb-1"><i class="bi bi-x-octagon-fill text-danger me-1"></i> Lote Contaminado</strong><p class="small text-muted mb-0">Tacho <strong>impregnado</strong> com aroma mais forte. O CQ descarta <strong>50% das barras boas</strong> como refugo.</p></div></div>
            <div class="col-12 col-md-4"><div class="p-3 bg-light rounded-3 h-100"><strong class="text-dark d-block mb-1"><i class="bi bi-cash-coin text-warning me-1"></i> Penalidades Aplicadas</strong><p class="small text-muted mb-0">Por lote rejeitado:<br>• Prejuízo de <strong>R$ <?= number_format($custo_refugo_barra, 2, ',', '.') ?></strong> por barra perdida<br>• <strong>-<?= $penalidade_cq_pts ?> pts</strong> no score geral</p></div></div>
        </div>
    </div>

    <!-- POP-04 PASSO A PASSO -->
    <div class="card card-custom bg-white p-4 mb-4">
        <h5 class="fw-bold text-dark mb-3"><i class="bi bi-book text-primary me-2"></i>POP-04 — Protocolo Operacional Padrão de Sanitização</h5>
        <div class="row g-3">
            <div class="col-12 col-md-3"><div class="p-3 bg-light rounded-3 h-100 border"><div class="d-flex align-items-center mb-2"><span class="badge bg-primary rounded-circle p-2 me-2" style="width:26px;height:26px;">1</span><strong class="small">Drenagem Mecânica</strong></div><p class="small text-muted mb-0">Raspagem completa do resíduo sólido com espátula de inox.</p></div></div>
            <div class="col-12 col-md-3"><div class="p-3 bg-light rounded-3 h-100 border"><div class="d-flex align-items-center mb-2"><span class="badge bg-primary rounded-circle p-2 me-2" style="width:26px;height:26px;">2</span><strong class="small">Desengordurante</strong></div><p class="small text-muted mb-0">Lavagem com solução a 60°C por toda a superfície interna.</p></div></div>
            <div class="col-12 col-md-3"><div class="p-3 bg-light rounded-3 h-100 border"><div class="d-flex align-items-center mb-2"><span class="badge bg-primary rounded-circle p-2 me-2" style="width:26px;height:26px;">3</span><strong class="small">Álcool 70%</strong></div><p class="small text-muted mb-0">Desinfecção por contato com álcool 70% (mín. 10 minutos).</p></div></div>
            <div class="col-12 col-md-3"><div class="p-3 bg-light rounded-3 h-100 border"><div class="d-flex align-items-center mb-2"><span class="badge bg-primary rounded-circle p-2 me-2" style="width:26px;height:26px;">4</span><strong class="small">Registro</strong></div><p class="small text-muted mb-0">Sistema marca o tacho como neutro. Custo: R$ <?= number_format($custo_pop04, 2, ',', '.') ?>.</p></div></div>
        </div>
    </div>

    <!-- FLUXO -->
    <div class="card card-custom bg-white p-4 mb-4">
        <h6 class="fw-bold text-dark mb-3"><i class="bi bi-info-circle text-primary me-2"></i>Fluxo Operacional da OP</h6>
        <div class="row g-3">
            <div class="col-12 col-md-3"><div class="p-2 bg-light rounded-2 h-100 small"><strong class="text-dark d-block mb-1">1. Planejada</strong>OP criada. Custo de MP <strong>reservado</strong> (bloqueado do caixa livre). Pode ser cancelada (libera a reserva) ou duplicada.</div></div>
            <div class="col-12 col-md-3"><div class="p-2 bg-light rounded-2 h-100 small"><strong class="text-dark d-block mb-1">2. Em Produção</strong>Chão de fábrica consome insumos. Aguarda apontamento final.</div></div>
            <div class="col-12 col-md-3"><div class="p-2 bg-light rounded-2 h-100 small"><strong class="text-dark d-block mb-1">3. Apontar</strong>Boas + Refugo. CQ valida o tacho. Baixa de insumos + <strong>débito do custo de MP do caixa</strong>.</div></div>
            <div class="col-12 col-md-3"><div class="p-2 bg-light rounded-2 h-100 small"><strong class="text-dark d-block mb-1">4. Finalizada</strong>Estoque atualizado. Lucro apurado ao fechar a rodada (vendas).</div></div>
        </div>
    </div>

    <!-- HISTÓRICO -->
    <div class="card card-custom bg-white p-4">
        <div class="row g-3 align-items-center">
            <div class="col-12 col-md-6">
                <span class="text-muted small text-uppercase fw-bold d-block">Histórico Geral da Equipe</span>
                <div class="d-flex gap-3 mt-2 flex-wrap">
                    <div><span class="text-muted small d-block">OPs finalizadas</span><strong class="fs-5"><?= $total_ops_finalizadas ?></strong></div>
                    <div><span class="text-muted small d-block">Última fragrância</span><strong class="fs-6"><?= h($nome_aroma_amigavel[$tacho_aroma] ?? $tacho_aroma) ?></strong></div>
                    <div><span class="text-muted small d-block">Estado do tacho</span><span class="badge <?= $tacho_higienizado ? 'bg-success' : 'bg-danger' ?>"><?= $tacho_higienizado ? 'Higienizado' : 'Impregnado' ?></span></div>
                </div>
            </div>
            <div class="col-12 col-md-6 text-md-end">
                <div class="small text-muted"><i class="bi bi-info-circle me-1"></i>Cada sanitização custa <strong>R$ <?= number_format($custo_pop04, 2, ',', '.') ?></strong> e libera o tacho para qualquer aroma.</div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAIS                                                         -->
<!-- ============================================================ -->

<!-- MODAL: NOVA OP -->
<div class="modal fade" id="modalNovaOP" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="ordens_producao.php" class="modal-content" id="formNovaOP">
            <input type="hidden" name="acao" value="criar_op">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-clipboard2-plus text-success me-2"></i>Emitir Ordem de Produção</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if ($rodada_ativa): ?>
                    <div class="alert alert-light border small mb-3"><i class="bi bi-calendar-event me-1 text-success"></i>Vinculada à rodada <strong>R<?= (int) $rodada_ativa['numero_rodada'] ?></strong> (<?= h($rodada_ativa['mes_ano'] ?? '') ?>)</div>
                <?php endif; ?>

                <div class="alert alert-primary border-0 small mb-3">
                    <i class="bi bi-cash-coin me-1"></i><strong>Caixa livre para novas OPs:</strong> R$ <?= number_format($caixa_livre, 2, ',', '.') ?>
                    <br><small class="text-muted">O custo da MP será descontado ao finalizar a OP.</small>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Fragrância (ordem oficial: MAR → PIT → LAV → ROS):</label>
                    <select name="produto_id" id="novo_produto" class="form-select" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($produtos_select as $p): ?>
                            <option value="<?= (int) $p['id'] ?>" data-codigo="<?= h($p['codigo']) ?>" data-preco="<?= (float) $p['preco_venda'] ?>">[<?= h($p['codigo']) ?>] <?= h($p['nome']) ?> — <?= h($p['aroma']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold">Quantidade Planejada:</label>
                        <input type="number" step="50" min="50" name="quantidade_planejada" id="novo_qtd" class="form-control" value="100" required>
                        <small class="text-muted">Múltiplos de 50 barras.</small>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold">Data Programada:</label>
                        <input type="date" name="data_programada" id="novo_data" class="form-control" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Observações:</label>
                    <textarea name="observacoes" class="form-control" rows="2" placeholder="Ex: Higienização prévia obrigatória..."></textarea>
                </div>

                <div id="previewBomNova" class="d-none">
                    <div class="alert alert-info py-2 small mb-2"><i class="bi bi-search me-1"></i>Verificação de materiais para a quantidade informada:</div>
                    <div id="previewBomNovaConteudo"></div>
                </div>

                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" name="permitir_ruptura" id="permitirRuptura" value="1">
                    <label class="form-check-label small" for="permitirRuptura">Criar mesmo com <strong>ruptura projetada</strong> de insumos.</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success"><i class="bi bi-check2 me-1"></i> Gravar Ordem</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: APONTAMENTO -->
<div class="modal fade" id="modalApontar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="ordens_producao.php" class="modal-content" id="formApontar">
            <input type="hidden" name="acao" value="finalizar_op">
            <input type="hidden" name="op_id" id="apontar_op_id" value="">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-check2-circle text-success me-2"></i>Apontamento Final de Produção</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 small mb-3">OP: <strong id="apontar_op_num"></strong> · Planejado: <strong id="apontar_op_qtd"></strong> barras</div>
                <p class="small text-muted mb-3">O sistema dará entrada nas barras boas, <strong>baixará os insumos</strong> proporcionais ao total processado e <strong>debitará o custo de MP do caixa</strong> da equipe.</p>
                <div class="row g-3 mb-3">
                    <div class="col-6"><label class="form-label fw-semibold text-success">Barras Aprovadas:</label><input type="number" step="1" min="0" name="quantidade_produzida" id="apontar_boas" class="form-control form-control-lg fw-bold" required></div>
                    <div class="col-6"><label class="form-label fw-semibold text-danger">Refugo (perdas):</label><input type="number" step="1" min="0" name="quantidade_refugo" id="apontar_refugo" class="form-control form-control-lg fw-bold" value="0" required></div>
                </div>
                <?php if (!$tacho_higienizado): ?>
                    <div class="alert alert-warning py-2 small mb-3"><i class="bi bi-exclamation-triangle-fill me-1"></i><strong>ATENÇÃO:</strong> O tacho está impregnado com <strong><?= h($nome_aroma_amigavel[$tacho_aroma] ?? $tacho_aroma) ?></strong>. Se este lote for de aroma mais suave, o CQ descartará 50% das barras.</div>
                <?php endif; ?>
                <div class="alert alert-warning py-2 small mb-0 d-none" id="alertaDivergencia"><i class="bi bi-exclamation-triangle-fill me-1"></i><span id="alertaDivergenciaTxt"></span></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Voltar</button>
                <button type="submit" class="btn btn-success"><i class="bi bi-box-arrow-in-down me-1"></i> Concluir e Baixar Estoque</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: INICIAR OP -->
<div class="modal fade" id="modalIniciarOP" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="ordens_producao.php" class="modal-content" id="formIniciarOP">
            <input type="hidden" name="acao" value="iniciar_op">
            <input type="hidden" name="op_id" id="iniciar_op_id" value="">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-play-circle text-warning me-2"></i>Iniciar Ordem de Produção</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">OP: <strong id="iniciar_op_num"></strong></p>
                <div class="alert alert-danger py-2 small mb-3 d-none" id="alertaContaminacao">
                    <i class="bi bi-exclamation-octagon-fill me-1"></i><strong>ATENÇÃO CQ:</strong> <span id="alertaContaminacaoTxt"></span>
                    <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="confirmado_contaminacao" value="1" id="confirmarRisco"><label class="form-check-label small" for="confirmarRisco"><strong>Assumir o risco</strong> e iniciar mesmo assim.</label></div>
                </div>
                <p class="small text-muted mb-0">Ao iniciar, a OP passa para <strong>Em Produção</strong> e o chão de fábrica começa a consumir insumos.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-warning fw-semibold text-dark" id="btnConfirmarInicio"><i class="bi bi-play-fill me-1"></i> Confirmar e Iniciar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: BOM -->
<div class="modal fade" id="modalBomExplosion" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-diagram-2 text-primary me-2"></i>Explosão de Materiais (BOM)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="conteudoBomExplosion">
                <div class="text-center py-4"><div class="spinner-border text-success" role="status"></div><p class="small text-muted mt-2">Calculando...</p></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: DETALHES -->
<div class="modal fade" id="modalDetalhes" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-info-circle text-info me-2"></i>Detalhes da OP</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Número</dt><dd class="col-sm-8 fw-bold font-monospace" id="det_op_num">—</dd>
                    <dt class="col-sm-4">Produto</dt><dd class="col-sm-8" id="det_op_prod">—</dd>
                    <dt class="col-sm-4">Quantidade</dt><dd class="col-sm-8" id="det_op_qtd">—</dd>
                    <dt class="col-sm-4">Programada</dt><dd class="col-sm-8" id="det_op_data">—</dd>
                    <dt class="col-sm-4">Status</dt><dd class="col-sm-8"><span class="badge bg-secondary" id="det_op_status">—</span></dd>
                    <dt class="col-sm-4">Observações</dt><dd class="col-sm-8 small text-muted" id="det_op_obs">—</dd>
                </dl>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button></div>
        </div>
    </div>
</div>

<!-- MODAL: SANITIZAÇÃO -->
<div class="modal fade" id="modalSanitizacao" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="ordens_producao.php" class="modal-content" id="formSanitizacao">
            <input type="hidden" name="acao" value="executar_sanitizacao">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-shield-shaded me-2"></i>POP-04: Sanitização Térmica & Álcool 70%</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning small mb-3"><i class="bi bi-exclamation-triangle-fill me-1"></i>A esterilização consome <strong>R$ <?= number_format($custo_pop04, 2, ',', '.') ?></strong> em insumos de limpeza e neutraliza o tacho para o próximo lote.</div>
                <div class="mb-3 small"><strong>Caixa atual:</strong> R$ <?= number_format($caixa_atual, 2, ',', '.') ?> <?php if ($caixa_atual < $custo_pop04): ?><span class="badge bg-danger ms-1">Insuficiente</span><?php endif; ?></div>
                <h6 class="fw-bold mb-2">Etapas Obrigatórias de BPF:</h6>
                <div class="list-group list-group-flush small mb-3">
                    <div class="list-group-item px-0 d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill text-success"></i>1. Drenagem mecânica e raspagem de espátula de inox</div>
                    <div class="list-group-item px-0 d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill text-success"></i>2. Lavagem com solução desengordurante a 60°C</div>
                    <div class="list-group-item px-0 d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill text-success"></i>3. Desinfecção por contato com Álcool 70% (mín. 10 min)</div>
                </div>
                <div id="progressoSanitizacao" class="d-none">
                    <span class="small text-muted fw-bold d-block mb-1">Sanitizando tacho...</span>
                    <div class="sanitization-progress progress"><div class="progress-bar progress-bar-striped progress-bar-animated bg-danger" id="barraProgressoSanit" style="width: 0%"></div></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                <button type="submit" class="btn btn-danger fw-bold" id="btnConfirmarLimpeza" <?= $caixa_atual < $custo_pop04 ? 'disabled' : '' ?>>
                    <i class="bi bi-check2 me-1"></i> Confirmar e Pagar Kit (R$ <?= number_format($custo_pop04, 2, ',', '.') ?>)
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- JAVASCRIPT                                                    -->
<!-- ============================================================ -->
<script>
document.addEventListener('DOMContentLoaded', function () {

    // =========================================================
    // 1. MODAL DE APONTAMENTO
    // =========================================================
    const modalApontarEl = document.getElementById('modalApontar');
    const modalApontar = modalApontarEl ? bootstrap.Modal.getOrCreateInstance(modalApontarEl) : null;

    const elBoas       = document.getElementById('apontar_boas');
    const elRefugo     = document.getElementById('apontar_refugo');
    const elQtdPl      = document.getElementById('apontar_op_qtd');
    const alertaDiv    = document.getElementById('alertaDivergencia');
    const alertaDivTxt = document.getElementById('alertaDivergenciaTxt');

    function validarApontamento() {
        if (!elBoas || !elRefugo) return;
        const boas   = parseInt(elBoas.value || '0', 10);
        const refugo = parseInt(elRefugo.value || '0', 10);
        const plan   = parseInt((elQtdPl.textContent || '0').replace(/\D+/g, ''), 10) || 0;
        const total  = boas + refugo;

        if (total === 0) {
            alertaDiv.classList.remove('d-none');
            alertaDivTxt.textContent = 'Informe pelo menos 1 barra processada.';
            return;
        }
        if (total > plan * 1.5) {
            alertaDiv.classList.remove('d-none');
            alertaDivTxt.textContent = 'Atenção: total (' + total + ') excede 150% do plano (' + plan + ').';
            return;
        }
        alertaDiv.classList.add('d-none');
    }

    if (elBoas)   elBoas.addEventListener('input', validarApontamento);
    if (elRefugo) elRefugo.addEventListener('input', validarApontamento);

    document.querySelectorAll('.btn-apontar').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('apontar_op_id').value = this.dataset.opId;
            document.getElementById('apontar_op_num').textContent = this.dataset.opNum;
            elQtdPl.textContent = this.dataset.qtd;
            elBoas.value = this.dataset.qtd;
            elRefugo.value = 0;
            alertaDiv.classList.add('d-none');
            if (modalApontar) modalApontar.show();
        });
    });

    // =========================================================
    // 2. MODAL DE INICIAR OP
    // =========================================================
    const modalIniciarEl = document.getElementById('modalIniciarOP');
    const modalIniciar = modalIniciarEl ? bootstrap.Modal.getOrCreateInstance(modalIniciarEl) : null;

    const alertaContaminacao    = document.getElementById('alertaContaminacao');
    const alertaContaminacaoTxt = document.getElementById('alertaContaminacaoTxt');

    document.querySelectorAll('.btn-iniciar').forEach(btn => {
        btn.addEventListener('click', function () {
            const incompat = this.dataset.tachoIncompat === '1';
            document.getElementById('iniciar_op_id').value = this.dataset.opId;
            document.getElementById('iniciar_op_num').textContent = this.dataset.opNum;

            if (incompat) {
                alertaContaminacao.classList.remove('d-none');
                alertaContaminacaoTxt.textContent = this.dataset.tachoMotivo;
            } else {
                alertaContaminacao.classList.add('d-none');
            }
            if (modalIniciar) modalIniciar.show();
        });
    });

    // =========================================================
    // 3. MODAL DE BOM
    // =========================================================
    const modalBomEl = document.getElementById('modalBomExplosion');
    const modalBom = modalBomEl ? bootstrap.Modal.getOrCreateInstance(modalBomEl) : null;

    document.querySelectorAll('.btn-ver-bom').forEach(btn => {
        btn.addEventListener('click', function () {
            const prodId = this.dataset.prodId;
            const opQtd  = parseInt(this.dataset.qtd, 10) || 1;
            const opNum  = this.dataset.opNum;
            const container = document.getElementById('conteudoBomExplosion');

            container.innerHTML = `
                <div class="alert alert-secondary py-2 small mb-3">
                    Simulação para <strong>${opNum}</strong> · <strong>${opQtd} barras</strong>
                </div>
                <div class="text-center py-4">
                    <div class="spinner-border text-success" role="status"></div>
                    <p class="small text-muted mt-2">Calculando...</p>
                </div>
            `;
            if (modalBom) modalBom.show();

            fetch(`../api/op_api.php?action=get_bom&produto_id=${prodId}&qtd=${opQtd}`)
                .then(r => r.json())
                .then(data => {
                    if (!data.success || !data.items || !data.items.length) {
                        container.innerHTML = `
                            <div class="alert alert-warning py-3">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                Ficha Técnica não configurada para este produto.
                            </div>
                        `;
                        return;
                    }
                    let linhas = '', ruptura = false;
                    data.items.forEach(item => {
                        const nec = parseFloat(item.qtd_por_barra) * opQtd;
                        const est = parseFloat(item.estoque_atual);
                        const sal = est - nec;
                        const falta = sal < 0;
                        if (falta) ruptura = true;
                        linhas += `
                            <tr class="${falta ? 'table-danger' : ''}">
                                <td><strong>${item.nome}</strong></td>
                                <td class="font-monospace">${parseFloat(item.qtd_por_barra).toFixed(4)} ${item.unidade}</td>
                                <td class="font-monospace fw-bold">${nec.toFixed(4)} ${item.unidade}</td>
                                <td class="font-monospace">${est.toFixed(4)} ${item.unidade}</td>
                                <td class="font-monospace fw-bold ${falta ? 'text-danger' : 'text-success'}">
                                    ${sal.toFixed(4)} ${item.unidade}
                                </td>
                                <td>${falta
                                    ? '<span class="badge bg-danger">Ruptura</span>'
                                    : '<span class="badge bg-success">OK</span>'}</td>
                            </tr>
                        `;
                    });
                    container.innerHTML = `
                        <div class="alert ${ruptura ? 'alert-danger' : 'alert-success'} py-2 small mb-3">
                            ${ruptura
                                ? '<i class="bi bi-exclamation-octagon-fill me-1"></i> <strong>Risco:</strong> insumos insuficientes.'
                                : '<i class="bi bi-check-circle-fill me-1"></i> <strong>Tudo certo:</strong> materiais suficientes.'}
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle mb-0">
                                <thead class="table-light small text-uppercase">
                                    <tr>
                                        <th>Insumo</th>
                                        <th>Consumo/Barra</th>
                                        <th>Demanda</th>
                                        <th>Estoque</th>
                                        <th>Saldo</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>${linhas}</tbody>
                            </table>
                        </div>
                    `;
                })
                .catch(() => {
                    container.innerHTML = `
                        <div class="alert alert-danger py-2 small">
                            Erro ao carregar BOM. Verifique a conexão.
                        </div>
                    `;
                });
        });
    });

    // =========================================================
    // 4. MODAL DE DETALHES
    // =========================================================
    const modalDetalhesEl = document.getElementById('modalDetalhes');
    const modalDetalhes = modalDetalhesEl ? bootstrap.Modal.getOrCreateInstance(modalDetalhesEl) : null;

    document.querySelectorAll('.btn-detalhes').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('det_op_num').textContent = this.dataset.opNum;
            document.getElementById('det_op_prod').textContent = `[${this.dataset.prodCod}] ${this.dataset.prodNome}`;
            document.getElementById('det_op_qtd').textContent = `${this.dataset.qtd} barras`;
            document.getElementById('det_op_data').textContent = this.dataset.data;
            document.getElementById('det_op_status').textContent = this.dataset.status;
            document.getElementById('det_op_obs').textContent = this.dataset.obs || '(sem observações)';
            if (modalDetalhes) modalDetalhes.show();
        });
    });

    // =========================================================
    // 5. PREVIEW DE BOM NA CRIAÇÃO DE OP
    // =========================================================
    const novoProd    = document.getElementById('novo_produto');
    const novoQtd     = document.getElementById('novo_qtd');
    const previewBox  = document.getElementById('previewBomNova');
    const previewCont = document.getElementById('previewBomNovaConteudo');

    function carregarPreview() {
        if (!novoProd || !novoQtd) return;
        const pid = novoProd.value;
        const qtd = parseInt(novoQtd.value || '0', 10);
        if (!pid || qtd <= 0) {
            previewBox.classList.add('d-none');
            return;
        }
        previewBox.classList.remove('d-none');
        previewCont.innerHTML = '<div class="text-center small text-muted py-1">Verificando...</div>';

        fetch(`../api/op_api.php?action=get_bom&produto_id=${pid}&qtd=${qtd}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success || !data.items || !data.items.length) {
                    previewCont.innerHTML = '<div class="alert alert-warning py-2 small mb-0">BOM não configurada.</div>';
                    return;
                }
                let ruptura = false, linhas = '';
                data.items.forEach(it => {
                    const nec = parseFloat(it.qtd_por_barra) * qtd;
                    const sal = parseFloat(it.estoque_atual) - nec;
                    const falta = sal < 0;
                    if (falta) ruptura = true;
                    linhas += `
                        <tr class="${falta ? 'table-danger' : ''}">
                            <td class="small">${it.nome}</td>
                            <td class="small font-monospace">${nec.toFixed(3)} ${it.unidade}</td>
                            <td class="small font-monospace ${falta ? 'text-danger fw-bold' : ''}">${sal.toFixed(3)} ${it.unidade}</td>
                        </tr>
                    `;
                });
                previewCont.innerHTML = `
                    <div class="alert ${ruptura ? 'alert-danger' : 'alert-success'} py-1 small mb-2">
                        ${ruptura ? '⚠️ Há ruptura projetada.' : '✅ Estoque suficiente.'}
                    </div>
                    <table class="table table-sm table-bordered align-middle mb-0" style="font-size:.82rem;">
                        <thead class="table-light">
                            <tr><th>Insumo</th><th>Necessário</th><th>Saldo</th></tr>
                        </thead>
                        <tbody>${linhas}</tbody>
                    </table>
                `;
            })
            .catch(() => {
                previewCont.innerHTML = '<div class="alert alert-danger py-2 small mb-0">Erro ao consultar BOM.</div>';
            });
    }

    if (novoProd) novoProd.addEventListener('change', carregarPreview);
    if (novoQtd)  novoQtd.addEventListener('input', carregarPreview);

    // =========================================================
    // 6. SANITIZAÇÃO POP-04 (progresso visual)
    // =========================================================
    const formSanit          = document.getElementById('formSanitizacao');
    const btnConfirmarSanit  = document.getElementById('btnConfirmarLimpeza');
    const barraSanit         = document.getElementById('barraProgressoSanit');
    const boxProgresso       = document.getElementById('progressoSanitizacao');

    if (formSanit) {
        formSanit.addEventListener('submit', function (e) {
            if (btnConfirmarSanit && btnConfirmarSanit.disabled) {
                e.preventDefault();
                return;
            }
            if (boxProgresso) boxProgresso.classList.remove('d-none');
            if (barraSanit) {
                let progresso = 0;
                const intervalo = setInterval(() => {
                    progresso += 10;
                    if (progresso >= 90) clearInterval(intervalo);
                    barraSanit.style.width = progresso + '%';
                }, 100);
                setTimeout(() => { barraSanit.style.width = '100%'; }, 1200);
            }
        });
    }

    // =========================================================
    // 7. EXPORTAÇÃO CSV
    // =========================================================
    const btnCSV = document.getElementById('btnExportarCSV');
    if (btnCSV) {
        btnCSV.addEventListener('click', function () {
            const tabela = document.getElementById('tabelaOPs');
            if (!tabela) return;
            const linhasCSV = [];

            linhasCSV.push(['OP', 'Produto', 'Codigo', 'Programada',
                            'Planejado', 'Boas', 'Refugo', 'Status'].join(';'));

            tabela.querySelectorAll('tbody tr').forEach(tr => {
                const tds = tr.querySelectorAll('td');
                if (tds.length < 7) return;
                const op    = tds[0].innerText.trim().split('\n')[0];
                const prod  = tds[1].innerText.trim().split('\n').slice(-2).join(' ').replace(/\s+/g, ' ');
                const cod   = tds[1].querySelector('.badge')?.innerText.trim() || '';
                const data  = tds[2].innerText.trim();
                const qtd   = tds[3].innerText.trim().replace(/\D/g, '');
                const apont = tds[4].innerText.trim().replace(/\s+/g, ' ');
                const st    = tds[5].innerText.trim();
                linhasCSV.push([op, prod, cod, data, qtd, apont, '', st].join(';'));
            });

            const csv  = linhasCSV.join('\n');
            const blob = new Blob(["\uFEFF" + csv], { type: 'text/csv;charset=utf-8;' });
            const url  = URL.createObjectURL(blob);
            const a    = document.createElement('a');
            a.href = url;
            a.download = 'ops_' + new Date().toISOString().slice(0, 10) + '.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        });
    }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';