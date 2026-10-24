<?php
/**
 * Flora Sabão — Constantes de Negócio, Parâmetros de PCP & Motor do Jogo
 * ----------------------------------------------------------------------
 * Baseado no Estudo de Caso Oficial Flora Sabão (PCP, Lean & TOC).
 * Centraliza regras de manufatura, capacidade em minutos, parâmetros econômicos
 * e o algoritmo de pontuação (Score) para o Business Game.
 */

declare(strict_types=1);

// ==============================================================================
// 1. CAPACIDADE FABRIL E POSTOS DE TRABALHO (TEORIA DAS RESTRIÇÕES - TOC)
// ==============================================================================
define('CAPACIDADE_MENSAL_BARRAS', 2500); // Teto de produção do estudo de caso
define('CAPACIDADE_DIARIA_BARRAS', 120);  // Meta diária de referência
define('TAMANHO_LOTE_PADRAO', 100);       // Lotes padrão de 100 barras (10kg de massa)
define('LOTE_MINIMO_MULTIPLO', 50);       // Mínimo fracionável para ordens de produção

/**
 * Tempos Padrão de Processamento por Lote de 100 barras (Pág. 4 do Estudo de Caso)
 * O Posto 3 (Moldagem) é o GARGALO DA FÁBRICA (70 min/lote = 0,7 min/barra).
 */
define('TEMPOS_POSTOS_MIN', [
    1 => ['nome' => 'Separação e Pesagem',      'tempo' => 30, 'gargalo' => false],
    2 => ['nome' => 'Mistura e Preparação',     'tempo' => 50, 'gargalo' => false],
    3 => ['nome' => 'Moldagem (GARGALO CRÍTICO)', 'tempo' => 70, 'gargalo' => true],
    4 => ['nome' => 'Desmoldagem e Acabamento', 'tempo' => 40, 'gargalo' => false],
    5 => ['nome' => 'Inspeção e Embalagem',    'tempo' => 60, 'gargalo' => false]
]);

// Tempo de ciclo unitário do gargalo (Posto 3)
define('TEMPO_GARGALO_LOTE_MIN', 70);
define('TEMPO_MOLDAGEM_POR_BARRA_MIN', 0.70); // 70 min / 100 barras

// ==============================================================================
// 2. JORNADA DE TRABALHO E CHÃO DE FÁBRICA (HORAS E MINUTOS)
// ==============================================================================
define('HORA_INICIO_TURNO', '08:00');        // Abertura da linha de produção
define('MINUTOS_TURNO_REGULAR', 480);        // 8 Horas de trabalho regular (08:00 às 17:00 com 1h de almoço)
define('HORA_TERMINO_REGULAR', '17:00');     // Encerramento sem hora extra
define('MINUTOS_INTERVALO_ALMOCO', 60);      // Pausa alimentar padrão (12:00 às 13:00)

define('LIMITE_HORA_EXTRA_MIN', 120);        // Máximo permitido: 2h extras por turno (600 min total)
define('CUSTO_MINUTO_HORA_EXTRA', 1.85);     // Custo adicional por minuto de operação após as 17:00 (R$ 111,00/h)

// ==============================================================================
// 3. ENGENHARIA DE SETUP E REGRA DE OURO OLFATIVA
// ==============================================================================
define('TEMPO_SETUP_MESMO_PRODUTO_MIN', 15);   // Limpeza leve entre lotes da mesma fragrância
define('TEMPO_SETUP_PADRAO_MIN', 20);          // Transição regular da suave para a intensa
define('TEMPO_SETUP_LIMPEZA_PESADA_MIN', 45);  // Sanitização pesada com álcool 70% se violar a ordem

/**
 * REGRA DE OURO OFICIAL (Pág. 4 do Estudo de Caso Flora Sabão):
 * 1º Maracujá (aroma suave e neutro)
 * 2º Pitanga (moderado frutal)
 * 3º Lavanda (floral marcante)
 * 4º Rosas (floral nobre, oleoso e intenso)
 */
define('ORDEM_AROMAS_RECOMENDADA', [
    'MAR' => 1, // Maracujá
    'PIT' => 2, // Pitanga
    'LAV' => 3, // Lavanda
    'ROS' => 4  // Rosas
]);

define('ESCALA_INTENSIDADE_AROMA', [
    'suave'    => 1,
    'moderado' => 2,
    'intenso'  => 3
]);

// ==============================================================================
// 4. PARÂMETROS ECONÔMICOS, FINANCEIROS E CUSTO DE PERDAS
// ==============================================================================
define('CAIXA_INICIAL_EQUIPE', 15000.00);      // Capital de giro inicial por equipe
define('CUSTO_ESTIMADO_REFUGO_BARRA', 3.20);   // Custo médio de MP perdido por barra refugada (R$)
define('MULTA_RUPTURA_ENTREGA_UN', 5.00);      // Penalidade contratual por barra não atendida no prazo
define('TAXA_CUSTO_POSSE_ESTOQUE_MENSAL', 0.02); // 2% a.m. de custo de carregamento sobre estoque parado

// ==============================================================================
// 5. GAMIFICAÇÃO: PESOS DO SCORE PCP (0 A 1000 PONTOS POR RODADA)
// ==============================================================================
define('SCORE_MAXIMO_RODADA', 1000);

// Pesos dos 4 Pilares da Manufatura no Fechamento da Rodada
define('PESO_SCORE_OTIF', 0.35);        // 35% - Nível de serviço e pontualidade
define('PESO_SCORE_ADERENCIA', 0.30);   // 30% - Cumprimento exato do plano sem super/subprodução
define('PESO_SCORE_YIELD', 0.20);       // 20% - Eficiência de qualidade e controle de refugo
define('PESO_SCORE_SETUP', 0.15);       // 15% - Otimização de tempos de troca de linha

// Penalidades e Bônus
define('PENALIDADE_CONTAMINACAO_SCORE', 50); // -50 pts por lote invertido (Rosas -> Suave)
define('PENALIDADE_RUPTURA_MP_SCORE', 50);   // -50 pts por tentar produzir sem matéria-prima
define('PENALIDADE_HORA_EXTRA_SCORE', 30);   // -30 pts se estourar o turno de 480 minutos
define('BONUS_DILEMA_CASO_ESTUDO', 50);      // +50 pts ao responder corretamente um dilema do caso

// ==============================================================================
// 6. METAS PEDAGÓGICAS E BENCHMARKS DE QUALIDADE (LEAN MANUFACTURING)
// ==============================================================================
define('META_OTIF_MINIMA', 90.0);        // Meta de Nível de Serviço: >= 90%
define('META_REFUGO_MAXIMA', 3.0);       // Tolerância Máxima de Scrap: <= 3%
define('META_ADERENCIA_MINIMA', 95.0);   // Meta de Aderência ao Plano: >= 95%
define('META_OCUPACAO_MINIMA', 70.0);    // Faixa ideal de ocupação: 70% a 92%
define('META_OCUPACAO_MAXIMA', 92.0);

// ==============================================================================
// 7. CLASSIFICAÇÃO ABC OFICIAL DE PARETO (Pág. 6 do Estudo de Caso)
// ==============================================================================
define('CURVA_ABC_OFICIAL', [
    'BASE-GLIC' => [
        'nome'      => 'Base Glicerinada Vegetal',
        'classe'    => 'A',
        'peso_orc'  => 64.9,
        'unidade'   => 'kg',
        'diretriz'  => 'Item Crítico (64,9% do orçamento). Monitoramento diário e compras Just-in-Time.'
    ],
    'EMB-KRAFT' => [
        'nome'      => 'Cinta de Papel Kraft',
        'classe'    => 'B',
        'peso_orc'  => 10.1,
        'unidade'   => 'un',
        'diretriz'  => 'Controle Intermediário. Compras quinzenais programadas.'
    ],
    'ESS-LAV'   => [
        'nome'      => 'Essência Concentrada de Lavanda',
        'classe'    => 'B',
        'peso_orc'  => 9.2,
        'unidade'   => 'L',
        'diretriz'  => 'Óleo Floral Nobre. Atenção redobrada para picos de Dia das Mães.'
    ],
    'ESS-ROS'   => [
        'nome'      => 'Essência Concentrada de Rosas',
        'classe'    => 'B',
        'peso_orc'  => 5.8,
        'unidade'   => 'L',
        'diretriz'  => 'Óleo Floral Marcante. Alto valor por litro; evitar sobras abertas.'
    ],
    'ESS-MAR'   => [
        'nome'      => 'Essência Concentrada de Maracujá',
        'classe'    => 'C',
        'peso_orc'  => 5.1,
        'unidade'   => 'L',
        'diretriz'  => 'Aroma Inicial. Compra em lotes maiores com folga de segurança.'
    ],
    'ESS-PIT'   => [
        'nome'      => 'Essência Concentrada de Pitanga',
        'classe'    => 'C',
        'peso_orc'  => 3.5,
        'unidade'   => 'L',
        'diretriz'  => 'Aroma Moderado Tropical. Estoque com giro constante.'
    ],
    'EXT-ALOE'  => [
        'nome'      => 'Extrato Glicólico de Aloe Vera',
        'classe'    => 'C',
        'peso_orc'  => 1.4,
        'unidade'   => 'L',
        'diretriz'  => 'Fitoterápico Hidratante. Consumo baixo de 2ml por barra.'
    ],
    'LAURIL'    => [
        'nome'      => 'Lauril Éter Sulfato (Espumante)',
        'classe'    => 'C',
        'peso_orc'  => 0.8,
        'unidade'   => 'L',
        'diretriz'  => 'Aditivo Tensoativo. Baixo custo; manter pulmão para não travar misturador.'
    ],
    'CELOF-BIO' => [
        'nome'      => 'Película Biodegradável',
        'classe'    => 'C',
        'peso_orc'  => 0.6,
        'unidade'   => 'un',
        'diretriz'  => 'Embalagem Primária. Compras em caixas fechadas de alto volume.'
    ]
]);

// ==============================================================================
// 8. ESPECIFICAÇÃO DOS 4 PRODUTOS OFICIAIS (BOM - BILL OF MATERIALS)
// ==============================================================================
define('PRODUTOS_OFICIAIS', [
    'MAR' => [
        'codigo'      => 'MAR',
        'nome'        => 'Sabonete Artesanal Maracujá',
        'aroma'       => 'Maracujá Cítrico',
        'intensidade' => 'suave',
        'ordem_setup' => 1,
        'peso_g'      => 100,
        'preco_venda' => 15.00,
        'essencia_cod'=> 'ESS-MAR'
    ],
    'PIT' => [
        'codigo'      => 'PIT',
        'nome'        => 'Sabonete Artesanal Pitanga',
        'aroma'       => 'Pitanga Tropical',
        'intensidade' => 'moderado',
        'ordem_setup' => 2,
        'peso_g'      => 100,
        'preco_venda' => 15.00,
        'essencia_cod'=> 'ESS-PIT'
    ],
    'LAV' => [
        'codigo'      => 'LAV',
        'nome'        => 'Sabonete Artesanal Lavanda',
        'aroma'       => 'Lavanda Francesa',
        'intensidade' => 'moderado',
        'ordem_setup' => 3,
        'peso_g'      => 100,
        'preco_venda' => 16.50,
        'essencia_cod'=> 'ESS-LAV'
    ],
    'ROS' => [
        'codigo'      => 'ROS',
        'nome'        => 'Sabonete Artesanal Rosas',
        'aroma'       => 'Rosas Nobres',
        'intensidade' => 'intenso',
        'ordem_setup' => 4,
        'peso_g'      => 100,
        'preco_venda' => 16.50,
        'essencia_cod'=> 'ESS-ROS'
    ]
]);

// ==============================================================================
// 9. CENÁRIOS E DILEMAS DE MERCADO (CONTROLE DO INSTRUTOR)
// ==============================================================================
define('CENARIOS_SIMULACAO_INSTRUTOR', [
    1 => [
        'titulo'        => 'Rodada de Aquecimento: Mercado Padrão',
        'descricao'     => 'Condições regulares de fornecimento e demanda estável. Ideal para familiarização com a BOM e a Regra de Ouro.',
        'fator_demanda' => 1.00,
        'impacto_custo' => 0.00
    ],
    2 => [
        'titulo'        => 'Pico Sazonal: Campanha Dia das Mães (Maio)',
        'descricao'     => 'A demanda das fragrâncias florais (Lavanda e Rosas) dispara 60%. Cuidado com a capacidade do Posto 3 (Gargalo de 70 min)!',
        'fator_demanda' => 1.60,
        'impacto_custo' => 0.00
    ],
    3 => [
        'titulo'        => 'Crise Logística: Escassez de Base Glicerinada',
        'descricao'     => 'Greve em refinarias químicas encarece a Base Glicerinada em +25%. O controle rígido da Classe A definirá o lucro da equipe.',
        'fator_demanda' => 1.00,
        'impacto_custo' => 0.25
    ],
    4 => [
        'titulo'        => 'Auditoria Sanitária (Boas Práticas de Fabricação)',
        'descricao'     => 'Fiscalização surpresa na fábrica. Contaminações cruzadas de aroma geram multas dobradas (-100 pts no score).',
        'fator_demanda' => 1.10,
        'impacto_custo' => 0.00
    ]
]);

// ==============================================================================
// 10. IDENTIDADE VISUAL DAS 6 EQUIPES CONCORRENTES
// ==============================================================================
define('EQUIPES_CONFIG', [
    1 => ['nome' => 'Equipe Alfa',    'cor_hex' => '#2d5a3f', 'login' => 'equipe1@flora.com'],
    2 => ['nome' => 'Equipe Beta',    'cor_hex' => '#1e5084', 'login' => 'equipe2@flora.com'],
    3 => ['nome' => 'Equipe Gama',    'cor_hex' => '#b85d19', 'login' => 'equipe3@flora.com'],
    4 => ['nome' => 'Equipe Delta',   'cor_hex' => '#6b2d84', 'login' => 'equipe4@flora.com'],
    5 => ['nome' => 'Equipe Épsilon', 'cor_hex' => '#841e3d', 'login' => 'equipe5@flora.com'],
    6 => ['nome' => 'Equipe Ômega',   'cor_hex' => '#3d454a', 'login' => 'equipe6@flora.com']
]);

// ==============================================================================
// 11. STATUS DAS ORDENS DE PRODUÇÃO (OP)
// ==============================================================================
define('STATUS_OP', [
    'planejada'   => [
        'label' => 'Planejada',
        'badge' => 'secondary',
        'icon'  => 'bi-calendar-check'
    ],
    'em_producao' => [
        'label' => 'Em Produção',
        'badge' => 'warning text-dark',
        'icon'  => 'bi-gear-wide-connected'
    ],
    'finalizada'  => [
        'label' => 'Finalizada',
        'badge' => 'success',
        'icon'  => 'bi-check2-circle'
    ],
    'cancelada'   => [
        'label' => 'Cancelada',
        'badge' => 'danger',
        'icon'  => 'bi-x-circle'
    ]
]);