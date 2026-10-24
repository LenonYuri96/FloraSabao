<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$equipe_id = getEquipeAtivaId();
if ($equipe_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Equipe não identificada']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// 1. CONSULTAR STATUS ATUAL DO TACHO
if ($action === 'status_tacho') {
    $stmt = $pdo->prepare("SELECT tacho_ultimo_aroma, tacho_higienizado, caixa_disponivel FROM equipes WHERE id = ?");
    $stmt->execute([$equipe_id]);
    $tacho = $stmt->fetch();

    echo json_encode([
        'success' => true,
        'ultimo_aroma' => $tacho['tacho_ultimo_aroma'] ?? 'NENHUM',
        'higienizado' => (int) ($tacho['tacho_higienizado'] ?? 1) === 1,
        'caixa' => (float) ($tacho['caixa_disponivel'] ?? 0)
    ]);
    exit;
}

// 2. EXECUTAR HIGIENIZAÇÃO / PROTOCOLO POP-04
if ($action === 'executar_limpeza') {
    $custo_sanitizante = 15.00; // Custo do Álcool 70% e material descartável

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT caixa_disponivel FROM equipes WHERE id = ? FOR UPDATE");
        $stmt->execute([$equipe_id]);
        $caixa = (float) $stmt->fetchColumn();

        if ($caixa < $custo_sanitizante) {
            throw new Exception("Saldo em caixa insuficiente para adquirir o kit de sanitização (R$ {$custo_sanitizante}).");
        }

        // Debita o custo do kit de limpeza e marca o tacho como 100% limpo e neutro
        $pdo->prepare("
            UPDATE equipes 
            SET tacho_higienizado = 1,
                tacho_ultimo_aroma = 'LIMPO',
                caixa_disponivel = caixa_disponivel - ?
            WHERE id = ?
        ")->execute([$custo_sanitizante, $equipe_id]);

        // Registra a saída no Kardex/Movimentações financeiras
        $pdo->prepare("
            INSERT INTO mov_estoque (equipe_id, tipo, tipo_item, quantidade, motivo)
            VALUES (?, 'saida', 'insumo', 1.0000, 'Kit Sanitização Pesada Tacho (Álcool 70% e Desengordurante)')
        ")->execute([$equipe_id]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Protocolo POP-04 concluído! Tacho 100% esterilizado e neutro para qualquer fragrância.',
            'novo_caixa' => $caixa - $custo_sanitizante
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Ação não suportada']);