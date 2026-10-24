<?php
/**
 * Flora Sabão — API de Engenharia de Produtos & Ficha Técnica (BOM)
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        // Retorna todos os produtos ativos com custos de MP calculados dinamicamente
        case 'listar_produtos':
            $stmt = $pdo->query("
                SELECT p.id, p.codigo, p.nome, p.aroma, p.intensidade_aroma,
                       p.peso_barra_g, p.estoque_atual, p.estoque_minimo, p.preco_venda,
                       COALESCE(SUM(ft.qtd_por_barra * i.custo_unitario), 0) AS custo_mp_unitario
                FROM produtos p
                LEFT JOIN ficha_tecnica ft ON p.id = ft.produto_id
                LEFT JOIN insumos i ON ft.insumo_id = i.id
                WHERE p.ativo = 1
                GROUP BY p.id
                ORDER BY p.codigo ASC
            ");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
            break;

        // Detalhes completos da Ficha Técnica de um produto específico
        case 'ficha_tecnica':
            $produto_id = (int) ($_GET['produto_id'] ?? 0);
            if ($produto_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Produto não especificado.']);
                exit;
            }

            $stmt_prod = $pdo->prepare("SELECT id, codigo, nome, aroma, preco_venda FROM produtos WHERE id = ?");
            $stmt_prod->execute([$produto_id]);
            $produto = $stmt_prod->fetch();

            if (!$produto) {
                echo json_encode(['success' => false, 'message' => 'Produto não encontrado.']);
                exit;
            }

            $stmt_bom = $pdo->prepare("
                SELECT ft.id AS bom_id, ft.qtd_por_barra,
                       i.id AS insumo_id, i.codigo AS insumo_codigo, i.nome AS insumo_nome,
                       i.unidade, i.custo_unitario, i.estoque_atual,
                       (ft.qtd_por_barra * i.custo_unitario) AS subtotal_custo
                FROM ficha_tecnica ft
                JOIN insumos i ON ft.insumo_id = i.id
                WHERE ft.produto_id = ?
                ORDER BY i.nome ASC
            ");
            $stmt_bom->execute([$produto_id]);
            $materiais = $stmt_bom->fetchAll();

            $custo_total_mp = array_sum(array_column($materiais, 'subtotal_custo'));

            echo json_encode([
                'success' => true,
                'produto' => $produto,
                'custo_mp_barra' => $custo_total_mp,
                'margem_bruta' => (float) $produto['preco_venda'] - $custo_total_mp,
                'insumos' => $materiais
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Ação não reconhecida.']);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro interno: ' . $e->getMessage()]);
}