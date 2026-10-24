<?php
/**
 * Flora Sabão — Constantes de Negócio e Parâmetros de PCP
 */

declare(strict_types=1);

// Capacidade Fabril
define('CAPACIDADE_MENSAL_BARRAS', 2500);
define('CAPACIDADE_DIARIA_BARRAS', 120);
define('TAMANHO_LOTE_PADRAO', 50); // Lotes múltiplos de 50 barras

// Parâmetros de Setup (Troca de Lotes)
define('TEMPO_SETUP_PADRAO_MIN', 20); // Mesma família/aroma
define('TEMPO_SETUP_LIMPEZA_PESADA_MIN', 45); // Entre aromas fortes (evita contaminação)

// Escala de fragrâncias para sequenciamento (Suave -> Intenso reduz tempo de limpeza)
define('ORDEM_AROMAS_RECOMENDADA', [
    'LAV' => 1, // Lavanda (suave)
    'ROS' => 2, // Rosas (moderado floral)
    'PIT' => 3, // Pitanga (moderado frutal)
    'MAR' => 4  // Maracujá (cítrico intenso)
]);

// Status de Ordens de Produção
define('STATUS_OP', [
    'planejada'   => ['label' => 'Planejada',   'badge' => 'secondary'],
    'em_producao' => ['label' => 'Em Produção', 'badge' => 'warning'],
    'finalizada'  => ['label' => 'Finalizada',  'badge' => 'success'],
    'cancelada'   => ['label' => 'Cancelada',   'badge' => 'danger']
]);