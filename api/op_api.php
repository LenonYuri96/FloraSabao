<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$equipe_id = getEquipeAtivaId();

if ($action === 'get_bom') {
    $produto_id = (int) ($_GET['produto_id'] ?? 0);
    $qtd = (int) ($_GET['qtd'] ?? 1);

    if ($produto_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Produto inválido']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT ft.qtd_por_barra, i.nome, i.unidade, i.estoque_atual
        FROM ficha_tecnica ft
        JOIN insumos i ON ft.insumo_id = i.id
        WHERE ft.produto_id = ? AND ft.equipe_id = ?
        ORDER BY i.nome ASC
    ");
    $stmt->execute([$produto_id, $equipe_id]);
    $items = $stmt->fetchAll();

    echo json_encode(['success' => true, 'items' => $items]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Ação não suportada']);