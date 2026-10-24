<?php
/**
 * Flora Sabão — Constantes de Negócio e Parâmetros de PCP
 * Baseado no Estudo de Caso Oficial Flora Sabão
 */

declare(strict_types=1);

// Capacidade Fabril (Pág. 1 e 4 do PDF)
define('CAPACIDADE_MENSAL_BARRAS', 2500);
define('CAPACIDADE_DIARIA_BARRAS', 120);
define('TAMANHO_LOTE_PADRAO', 100); // Lotes padrão de 100 barras

// Tempos Padrão de Processamento por Posto (Lote de 100 barras - Pág. 4)
define('TEMPOS_POSTOS_MIN', [
    1 => ['nome' => 'Separação e Pesagem', 'tempo' => 30],
    2 => ['nome' => 'Mistura e Preparação', 'tempo' => 50],
    3 => ['nome' => 'Moldagem (GARGALO)', 'tempo' => 70], // Posto Crítico
    4 => ['nome' => 'Desmoldagem e Acabamento', 'tempo' => 40],
    5 => ['nome' => 'Inspeção e Embalagem', 'tempo' => 60]
]);

// Tempo de ciclo do gargalo (Posto 3)
define('TEMPO_GARGALO_LOTE_MIN', 70);

// Parâmetros de Troca de Lote (Setup)
define('TEMPO_SETUP_PADRAO_MIN', 20);
define('TEMPO_SETUP_LIMPEZA_PESADA_MIN', 45); // Quando a regra suave -> forte é violada

/**
 * REGRA DE OURO OFICIAL (Pág. 4 do PDF):
 * 1º Maracujá (aroma mais suave)
 * 2º Pitanga
 * 3º Lavanda
 * 4º Rosas (aroma mais forte)
 */
define('ORDEM_AROMAS_RECOMENDADA', [
    'MAR' => 1, // Maracujá (suave)
    'PIT' => 2, // Pitanga (moderado)
    'LAV' => 3, // Lavanda (floral marcante)
    'ROS' => 4  // Rosas (forte e marcante)
]);

// Status de Ordens de Produção
define('STATUS_OP', [
    'planejada' => ['label' => 'Planejada', 'badge' => 'secondary'],
    'em_producao' => ['label' => 'Em Produção', 'badge' => 'warning'],
    'finalizada' => ['label' => 'Finalizada', 'badge' => 'success'],
    'cancelada' => ['label' => 'Cancelada', 'badge' => 'danger']
]);