<?php
declare(strict_types=1);

/**
 * Flora Sabão — API do Jogo (Business Game)
 * =========================================================
 * Endpoints disponíveis:
 *
 *   POST ?action=fechar_rodada
 *        Body: evento_nome (str), evento_desc (str)
 *        → Encerra rodada (vendas + KPIs + falência) e abre a próxima
 *        → Retorna JSON { success, rodada_encerrada, nova_rodada, novo_mes, fator_demanda, message }
 *
 *   GET  ?action=leaderboard
 *        → Ranking ao vivo para o telão (polling a cada 4s)
 *        → Retorna JSON { success, rodada, ranking[] }
 *
 * Dependências obrigatórias em includes/functions.php:
 *   - fecharRodadaAtual(PDO, string, string, ?float): array
 *   - custoBarraProduto(PDO, int, int): float
 *   - getDemandasRodada(PDO, int): array
 *   - avaliarSaudeFinanceira(PDO, int): array
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// =============================================================
// 1. FECHAR RODADA (POST) — delega tudo para fecharRodadaAtual()
// =============================================================
if ($action === 'fechar_rodada') {

    // Exige POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'Este endpoint exige método POST.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Sanitização dos parâmetros (nunca passam vazios para o motor)
    $evento_nome = trim((string) ($_POST['evento_nome'] ?? 'Mercado Estável'));
    $evento_desc = trim((string) ($_POST['evento_desc'] ?? 'Condições normais de mercado.'));

    if ($evento_nome === '') {
        $evento_nome = 'Mercado Estável';
    }
    if ($evento_desc === '') {
        $evento_desc = 'Condições normais de mercado.';
    }

    // Executa o motor do jogo (transacional e idempotente)
    $resultado = fecharRodadaAtual($pdo, $evento_nome, $evento_desc);

    // Garante estrutura mínima de retorno
    if (!is_array($resultado)) {
        $resultado = [
            'success' => false,
            'message' => 'Resposta inválida do motor de fechamento de rodada.',
        ];
    }

    if (empty($resultado['success'])) {
        http_response_code(500);
    }

    echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
    exit;
}

// =============================================================
// 2. LEADERBOARD (GET) — ranking ao vivo para o telão
// =============================================================
if ($action === 'leaderboard') {
    try {
        // Metadados da rodada em andamento
        $rodada = $pdo->query("
            SELECT `numero_rodada`, `mes_ano`, `evento_titulo`
            FROM `jogo_rodadas`
            WHERE `status` = 'aberta'
            ORDER BY `numero_rodada` DESC, `id` DESC
            LIMIT 1
        ")->fetch();

        // Ranking consolidado (inclui equipes falidas ao final)
        $stmt = $pdo->query("
            SELECT
                e.`id`,
                e.`nome`,
                e.`cor_hex`,
                e.`caixa_disponivel`,
                e.`score_total`,
                e.`falida`,
                e.`rodadas_no_vermelho`,
                COALESCE(SUM(r.`lucro_liquido`), 0) AS `lucro_acumulado`,
                COALESCE(SUM(r.`score_rodada`), 0)  AS `pontos_acumulados`
            FROM `equipes` e
            LEFT JOIN `ranking_rodadas` r ON r.`equipe_id` = e.`id`
            WHERE e.`ativo` = 1 OR e.`falida` = 1
            GROUP BY e.`id`
            ORDER BY e.`falida` ASC, e.`score_total` DESC, e.`id` ASC
        ");
        $ranking = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'rodada'  => $rodada ?: null,
            'ranking' => $ranking,
        ], JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Throwable $e) {
        error_log('[jogo_api.php] leaderboard: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Erro ao carregar leaderboard: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// =============================================================
// 3. AÇÃO NÃO SUPORTADA
// =============================================================
http_response_code(400);
echo json_encode([
    'success' => false,
    'message' => 'Ação não suportada. Use ?action=fechar_rodada (POST) ou ?action=leaderboard (GET).',
], JSON_UNESCAPED_UNICODE);
exit;