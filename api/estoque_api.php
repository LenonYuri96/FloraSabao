<?php
/**
 * Flora Sabão — API de Gestão de Estoque & Matérias-Primas
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        // Retorna todos os insumos com status de estoque (Normal, Alerta ou Crítico)
        case 'listar_insumos':
            $stmt = $pdo->query("
                SELECT id, codigo, nome, unidade, estoque_atual, estoque_minimo, custo_unitario,
                       (estoque_atual * custo_unitario) AS valor_imobilizado,
                       CASE 
                           WHEN estoque_atual <= (estoque_minimo * 0.5) THEN 'CRITICO'
                           WHEN estoque_atual <= estoque_minimo THEN 'ALERTA'
                           ELSE 'NORMAL'
                       END AS status_nivel
                FROM insumos
                ORDER BY nome ASC
            ");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
            break;

        // Retorna apenas insumos que necessitam de pedido de compra urgente
        case 'insumos_criticos':
            $stmt = $pdo->query("
                SELECT codigo, nome, unidade, estoque_atual, estoque_minimo,
                       (estoque_minimo - estoque_atual) AS necessidade_compra
                FROM insumos
                WHERE estoque_atual <= estoque_minimo
                ORDER BY (estoque_atual / NULLIF(estoque_minimo, 0)) ASC
            ");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
            break;

        // Consulta saldo em tempo real de um insumo específico
        case 'consultar_saldo':
            $id = (int) ($_GET['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID do insumo inválido.']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT id, codigo, nome, unidade, estoque_atual, estoque_minimo FROM insumos WHERE id = ?");
            $stmt->execute([$id]);
            $insumo = $stmt->fetch();

            if (!$insumo) {
                echo json_encode(['success' => false, 'message' => 'Insumo não encontrado.']);
                exit;
            }

            echo json_encode(['success' => true, 'insumo' => $insumo]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Ação não suportada pelo endpoint de estoque.']);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro interno: ' . $e->getMessage()]);
}