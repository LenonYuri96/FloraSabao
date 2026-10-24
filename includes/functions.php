<?php
/**
 * Flora Sabão — Funções Auxiliares Globais (PCP Lab & Business Game)
 * =================================================================
 * Biblioteca central do simulador. Reúne:
 *   §1  Formatação e UI (moeda, quantidade, badge de status)
 *   §2  Sessão, autenticação e perfis
 *   §3  Flash messages
 *   §4  Contexto multi-tenant de equipe
 *   §5  Contexto de rodada (Business Game)
 *   §6  Gerador dinâmico de demanda (S&OP determinístico)
 *   §7  Controle financeiro (custo de MP, caixa, falência)
 *   §8  Encerramento de rodada (motor completo do jogo)
 *   §9  Utilitários complementares (data, números, debug)
 *
 * Compatível com PHP 8.0+
 */

declare(strict_types=1);

// =========================================================
// 1. FORMATAÇÃO E UI
// =========================================================

if (!function_exists('formatarMoeda')) {
    function formatarMoeda(float $valor): string
    {
        return 'R$ ' . number_format($valor, 2, ',', '.');
    }
}

if (!function_exists('formatarQtd')) {
    function formatarQtd(float $qtd, int $decimais = 4): string
    {
        return number_format($qtd, $decimais, ',', '.');
    }
}

if (!function_exists('h')) {
    function h(?string $str): string
    {
        return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('renderBadgeStatusOP')) {
    function renderBadgeStatusOP(string $status): string
    {
        $mapa = [
            'planejada'   => ['label' => 'Planejada',   'class' => 'bg-secondary text-white'],
            'em_producao' => ['label' => 'Em Produção', 'class' => 'bg-warning text-dark'],
            'finalizada'  => ['label' => 'Finalizada',  'class' => 'bg-success text-white'],
            'cancelada'   => ['label' => 'Cancelada',   'class' => 'bg-danger text-white'],
        ];
        $info = $mapa[$status] ?? ['label' => ucfirst($status), 'class' => 'bg-light text-dark border'];
        return "<span class=\"badge {$info['class']} badge-status\">{$info['label']}</span>";
    }
}

// =========================================================
// 2. SESSÃO E AUTENTICAÇÃO
// =========================================================

if (!function_exists('iniciarSessao')) {
    function iniciarSessao(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}

if (!function_exists('estaLogado')) {
    function estaLogado(): bool
    {
        iniciarSessao();
        return !empty($_SESSION['usuario_id']);
    }
}

if (!function_exists('usuarioAtual')) {
    function usuarioAtual(): ?array
    {
        if (!estaLogado()) {
            return null;
        }
        return [
            'id'        => (int) $_SESSION['usuario_id'],
            'nome'      => $_SESSION['usuario_nome']     ?? '',
            'email'     => $_SESSION['usuario_email']    ?? '',
            'perfil'    => $_SESSION['usuario_perfil']   ?? 'pcp',
            'equipe_id' => $_SESSION['equipe_id'] ?? $_SESSION['usuario_equipe_id'] ?? null,
        ];
    }
}

if (!function_exists('possuiPerfil')) {
    function possuiPerfil(array $perfisPermitidos): bool
    {
        if (!estaLogado()) {
            return false;
        }
        $perfil = $_SESSION['usuario_perfil'] ?? '';
        return $perfil === 'admin' || in_array($perfil, $perfisPermitidos, true);
    }
}

if (!function_exists('isAdmin')) {
    function isAdmin(): bool
    {
        return estaLogado() && ($_SESSION['usuario_perfil'] ?? '') === 'admin';
    }
}

if (!function_exists('exigirPerfis')) {
    function exigirPerfis(array $perfisPermitidos): void
    {
        iniciarSessao();
        $prefix = file_exists('pages') ? '' : '../';

        if (!estaLogado()) {
            $_SESSION['flash_erro'] = 'Faça login para acessar o sistema.';
            header('Location: ' . $prefix . 'login.php');
            exit;
        }

        if (!possuiPerfil($perfisPermitidos)) {
            $_SESSION['flash_erro'] = 'Seu perfil não tem permissão para acessar esta área.';
            header('Location: ' . $prefix . 'index.php');
            exit;
        }
    }
}

// =========================================================
// 3. FLASH MESSAGES
// =========================================================

if (!function_exists('flash')) {
    function flash(string $tipo, string $mensagem): void
    {
        iniciarSessao();
        $_SESSION['flash_' . $tipo] = $mensagem;
    }
}

if (!function_exists('lerFlash')) {
    function lerFlash(string $tipo): ?string
    {
        iniciarSessao();
        $key = 'flash_' . $tipo;
        if (!isset($_SESSION[$key])) {
            return null;
        }
        $msg = $_SESSION[$key];
        unset($_SESSION[$key]);
        return $msg;
    }
}

// =========================================================
// 4. CONTEXTO DE EQUIPE (Multi-Tenant Seguro)
// =========================================================

if (!function_exists('getEquipeAtivaId')) {
    function getEquipeAtivaId(): int
    {
        iniciarSessao();

        if (!estaLogado()) {
            return 0;
        }

        $perfil  = $_SESSION['usuario_perfil'] ?? '';
        $minhaEq = (int) ($_SESSION['equipe_id'] ?? $_SESSION['usuario_equipe_id'] ?? 0);

        // Aluno comum sempre cai na própria equipe (mínimo 1)
        if ($perfil !== 'admin') {
            return $minhaEq > 0 ? $minhaEq : 1;
        }

        // Instrutor pode inspecionar qualquer equipe via ?equipe_id=X
        if (isset($_GET['equipe_id']) && (int) $_GET['equipe_id'] > 0) {
            $_SESSION['equipe_id_admin_view'] = (int) $_GET['equipe_id'];
            return (int) $_GET['equipe_id'];
        }

        return (int) ($_SESSION['equipe_id_admin_view'] ?? 1);
    }
}

if (!function_exists('equipeAtual')) {
    function equipeAtual(PDO $pdo): ?array
    {
        $eq_id = getEquipeAtivaId();
        if ($eq_id <= 0) {
            return null;
        }
        $stmt = $pdo->prepare("SELECT * FROM equipes WHERE id = ? AND ativo = 1");
        $stmt->execute([$eq_id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}

if (!function_exists('filtroEquipe')) {
    function filtroEquipe(string $alias = ''): string
    {
        $eq = getEquipeAtivaId();
        if ($eq <= 0) {
            return '';
        }
        $prefix = $alias !== '' ? "{$alias}." : '';
        return " AND {$prefix}equipe_id = " . (int) $eq;
    }
}

if (!function_exists('urlComEquipe')) {
    function urlComEquipe(string $url): string
    {
        if (!isAdmin()) {
            return $url;
        }
        $eq = getEquipeAtivaId();
        if ($eq <= 0) {
            return $url;
        }
        $sep = str_contains($url, '?') ? '&' : '?';
        return $url . $sep . 'equipe_id=' . $eq;
    }
}

if (!function_exists('equipesDisponiveis')) {
    function equipesDisponiveis(PDO $pdo): array
    {
        if (!estaLogado()) {
            return [];
        }

        if (isAdmin()) {
            return $pdo->query("SELECT id, nome, cor_hex FROM equipes WHERE ativo = 1 ORDER BY id")->fetchAll();
        }

        $eq = getEquipeAtivaId();
        $stmt = $pdo->prepare("SELECT id, nome, cor_hex FROM equipes WHERE id = ? AND ativo = 1");
        $stmt->execute([$eq]);
        return $stmt->fetchAll();
    }
}

// =========================================================
// 5. CONTEXTO DE RODADA (Business Game)
// =========================================================

if (!function_exists('getRodadaAtiva')) {
    function getRodadaAtiva(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT *
            FROM jogo_rodadas
            WHERE status = 'aberta'
            ORDER BY numero_rodada DESC, id DESC
            LIMIT 1
        ");
        $rodada = $stmt->fetch();

        if (!$rodada) {
            return [
                'id'               => 1,
                'numero_rodada'    => 1,
                'mes_ano'          => 'Mês 01',
                'status'           => 'aberta',
                'evento_titulo'    => 'Mercado Padrão',
                'evento_descricao' => 'Planejamento regular sem distorções.',
                'fator_demanda'    => 1.00
            ];
        }
        return $rodada;
    }
}

if (!function_exists('temRodadaAberta')) {
    function temRodadaAberta(PDO $pdo): bool
    {
        return getRodadaAtiva($pdo) !== null;
    }
}

if (!function_exists('labelRodada')) {
    function labelRodada(?array $rodada): string
    {
        if (!$rodada) {
            return 'Sem rodada aberta';
        }
        $mes = $rodada['mes_ano']       ?? 'Mês ?';
        $tit = $rodada['evento_titulo'] ?? '—';
        return "{$mes} · {$tit}";
    }
}

// ==============================================================================
// 6. GERADOR DINÂMICO DE DEMANDA & SAZONALIDADE (S&OP INTELIGENTE)
// ==============================================================================

if (!function_exists('gerarNumeroDeterministico')) {
    /**
     * Função pseudo-aleatória determinística baseada em semente string (MD5).
     */
    function gerarNumeroDeterministico(string $semente, int $min, int $max): int
    {
        $hash    = md5($semente);
        $inteiro = hexdec(substr($hash, 0, 8));
        return ($inteiro % ($max - $min + 1)) + $min;
    }
}

if (!function_exists('getDemandasRodada')) {
    /**
     * Retorna as metas de demanda calculadas dinamicamente com base na rodada.
     */
    function getDemandasRodada(PDO $pdo, int $equipe_id): array
    {
        $rodada       = getRodadaAtiva($pdo);
        $rodada_id    = (int) ($rodada['id'] ?? 1);
        $num_rodada   = (int) ($rodada['numero_rodada'] ?? 1);
        $fator_evento = (float) ($rodada['fator_demanda'] ?? 1.00);

        // 5 Arquétipos de Mercado
        $arquetipos = [
            0 => [
                'tipo'   => 'pico_floral',
                'nome'   => '🌸 Campanha Especial: Picos Florais de Alta Demanda',
                'alerta' => 'Alta de pedidos para presentes. Lavanda e Rosas no topo! Atenção redobrada à higienização do tacho para não contaminar lotes suaves.',
                'pesos'  => ['MAR' => [200, 350], 'PIT' => [300, 450], 'LAV' => [600, 750], 'ROS' => [550, 700]],
            ],
            1 => [
                'tipo'   => 'onda_tropical',
                'nome'   => '☀️ Linha de Verão: Explosão de Cítricos e Frutas Tropicais',
                'alerta' => 'Alta intensa de Maracujá e Pitanga. Sequenciamento olfativo mais seguro, mas consumo agressivo de Base Glicerinada (Classe A)!',
                'pesos'  => ['MAR' => [600, 750], 'PIT' => [550, 700], 'LAV' => [250, 400], 'ROS' => [200, 350]],
            ],
            2 => [
                'tipo'   => 'gargalo_limite',
                'nome'   => '🎄 Fim de Ano: Pico Geral de Demanda (Teto de Capacidade)',
                'alerta' => 'Carteira cheia em todas as fragrâncias (total próximo a 2.400 barras). O Posto 3 (Moldagem) operará no limite das 8 horas. Zero tolerância a erros de setup!',
                'pesos'  => ['MAR' => [500, 650], 'PIT' => [500, 650], 'LAV' => [550, 700], 'ROS' => [500, 650]],
            ],
            3 => [
                'tipo'   => 'linha_luxo_rosas',
                'nome'   => '💎 Pedido Corporativo VIP: Foco no Sabonete de Rosas Nobres',
                'alerta' => 'Um grande hotel contratou um lote maciço de Rosas. Maior margem de lucro por barra, mas alto risco de impregnação olfativa no equipamento.',
                'pesos'  => ['MAR' => [250, 350], 'PIT' => [250, 400], 'LAV' => [350, 500], 'ROS' => [700, 850]],
            ],
            4 => [
                'tipo'   => 'mercado_fragmentado',
                'nome'   => '⚖️ Carteira Fragmentada: Lotes Múltiplos e Variados',
                'alerta' => 'Demanda dividida em partes proporcionais. Exige troca frequente de fôrmas e rigor absoluto na Regra de Ouro (Maracujá → Pitanga → Lavanda → Rosas).',
                'pesos'  => ['MAR' => [350, 500], 'PIT' => [350, 500], 'LAV' => [400, 550], 'ROS' => [350, 500]],
            ]
        ];

        $indice_arquetipo = gerarNumeroDeterministico("arq_rodada_{$rodada_id}_{$num_rodada}", 0, 4);
        $arquetipo_ativo  = $arquetipos[$indice_arquetipo];

        // Consulta quanto a equipe já produziu de barras boas nesta rodada
        $stmt_prod = $pdo->prepare("
            SELECT p.codigo, COALESCE(SUM(op.quantidade_produzida), 0) AS total_boas
            FROM produtos p
            LEFT JOIN ordens_producao op ON p.id = op.produto_id
                 AND op.equipe_id = ?
                 AND op.status = 'finalizada'
                 AND op.rodada_id = ?
            WHERE p.equipe_id = ?
            GROUP BY p.codigo
        ");
        $stmt_prod->execute([$equipe_id, $rodada_id, $equipe_id]);
        $produzido_map = $stmt_prod->fetchAll(PDO::FETCH_KEY_PAIR);

        $skus_config = [
            'MAR' => ['nome' => 'Maracujá Cítrico',  'cor' => 'warning', 'aroma' => 'Suave',    'ordem' => 1],
            'PIT' => ['nome' => 'Pitanga Tropical',  'cor' => 'danger',  'aroma' => 'Moderado', 'ordem' => 2],
            'LAV' => ['nome' => 'Lavanda Francesa',  'cor' => 'primary', 'aroma' => 'Floral',   'ordem' => 3],
            'ROS' => ['nome' => 'Rosas Nobres',      'cor' => 'dark',    'aroma' => 'Intenso',  'ordem' => 4],
        ];

        $itens          = [];
        $total_meta     = 0;
        $total_entregue = 0;

        foreach ($skus_config as $cod => $info) {
            $faixa = $arquetipo_ativo['pesos'][$cod];

            $base_sorteada = gerarNumeroDeterministico("meta_{$rodada_id}_{$num_rodada}_{$cod}", $faixa[0], $faixa[1]);
            $meta_ajustada = (int) round($base_sorteada * $fator_evento);
            $meta_final    = max(100, (int) (round($meta_ajustada / 50) * 50));

            $entregue    = (int) ($produzido_map[$cod] ?? 0);
            $saldo_falta = max(0, $meta_final - $entregue);
            $percentual  = $meta_final > 0 ? min(100, round(($entregue / $meta_final) * 100, 1)) : 100.0;

            $total_meta     += $meta_final;
            $total_entregue += $entregue;

            $itens[$cod] = [
                'codigo'     => $cod,
                'nome'       => $info['nome'],
                'cor'        => $info['cor'],
                'aroma'      => $info['aroma'],
                'ordem'      => $info['ordem'],
                'meta'       => $meta_final,
                'entregue'   => $entregue,
                'faltam'     => $saldo_falta,
                'percentual' => $percentual,
                'concluido'  => ($saldo_falta === 0)
            ];
        }

        $tempo_gargalo        = defined('TEMPO_GARGALO_LOTE_MIN') ? TEMPO_GARGALO_LOTE_MIN : 70;
        $minutos_gargalo_meta = (int) round(($total_meta / 100) * $tempo_gargalo);
        $horas_gargalo_meta   = round($minutos_gargalo_meta / 60, 1);

        return [
            'cenario_titulo'     => $arquetipo_ativo['nome'],
            'cenario_alerta'     => $arquetipo_ativo['alerta'],
            'total_meta'         => $total_meta,
            'total_entregue'     => $total_entregue,
            'percentual_geral'   => $total_meta > 0 ? min(100, round(($total_entregue / $total_meta) * 100, 1)) : 100.0,
            'horas_gargalo_meta' => $horas_gargalo_meta,
            'itens'              => $itens
        ];
    }
}

// =============================================================================
// 7. CONTROLE FINANCEIRO — CUSTO DE PRODUÇÃO, CAIXA E FALÊNCIA
// =============================================================================

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
        $eq     = $stmt->fetch() ?: ['caixa_disponivel' => 0, 'falida' => 0];
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

if (!function_exists('declararFalencia')) {
    function declararFalencia(PDO $pdo, int $equipe_id): void
    {
        $pdo->prepare("
            UPDATE equipes
            SET falida = 1,
                ativo  = 0,
                data_falencia = NOW()
            WHERE id = ?
        ")->execute([$equipe_id]);
    }
}

if (!function_exists('avaliarSaudeFinanceira')) {
    function avaliarSaudeFinanceira(PDO $pdo, int $equipe_id): array
    {
        $stmt = $pdo->prepare("
            SELECT caixa_disponivel, rodadas_no_vermelho, falida
            FROM equipes WHERE id = ?
        ");
        $stmt->execute([$equipe_id]);
        $eq = $stmt->fetch() ?: ['caixa_disponivel' => 0, 'rodadas_no_vermelho' => 0, 'falida' => 0];

        $caixa       = (float) $eq['caixa_disponivel'];
        $rodadas_neg = (int)   $eq['rodadas_no_vermelho'];
        $ja_falida   = (int)   $eq['falida'] === 1;

        if ($ja_falida) {
            return ['status' => 'falida', 'rodadas' => $rodadas_neg, 'caixa' => $caixa];
        }

        if ($caixa < 0) {
            $rodadas_neg++;
            $pdo->prepare("UPDATE equipes SET rodadas_no_vermelho = ? WHERE id = ?")
                ->execute([$rodadas_neg, $equipe_id]);

            if ($rodadas_neg >= 2) {
                declararFalencia($pdo, $equipe_id);
                return ['status' => 'falida', 'rodadas' => $rodadas_neg, 'caixa' => $caixa];
            }
            return ['status' => 'risco', 'rodadas' => $rodadas_neg, 'caixa' => $caixa];
        }

        if ($rodadas_neg > 0) {
            $pdo->prepare("UPDATE equipes SET rodadas_no_vermelho = 0 WHERE id = ?")
                ->execute([$equipe_id]);
        }
        return ['status' => 'ok', 'rodadas' => 0, 'caixa' => $caixa];
    }
}

// =============================================================================
// 8. ENCERRAMENTO DE RODADA (MOTOR DO BUSINESS GAME)
// =============================================================================

if (!function_exists('fecharRodadaAtual')) {
    /**
     * Flora Sabão — Encerramento de Rodada
     * =============================================================
     * Executa em transação única:
     *   1.  Processa VENDAS (estoque até o limite da meta, parcial ou total)
     *   2.  Credita receita no caixa + registra Kardex
     *   3.  Apura KPIs operacionais (OTIF, Aderência, Refugo, Yield)
     *   4.  Calcula score (operacional + bônus de lucro, máx +400)
     *   5.  Atualiza ranking_rodadas e score_total
     *   6.  Avalia FALÊNCIA (2 rodadas no vermelho = equipe falida)
     *   7.  Encerra a rodada atual
     *   8.  Abre automaticamente a próxima rodada
     *
     * @param  PDO         $pdo
     * @param  string      $evento_nome
     * @param  string      $evento_desc
     * @param  float|null  $fator_demanda_forcado
     *
     * @return array{
     *     success: bool,
     *     message: string,
     *     rodada_encerrada?: int,
     *     nova_rodada?: int,
     *     novo_mes?: string,
     *     fator_demanda?: float
     * }
     */
    function fecharRodadaAtual(
        PDO $pdo,
        string $evento_nome = 'Mercado Estável',
        string $evento_desc = 'Condições normais de mercado.',
        ?float $fator_demanda_forcado = null
    ): array {

        // 0. Deduz o fator de demanda a partir do nome do evento
        if ($fator_demanda_forcado === null) {
            $fator_demanda = 1.00;
            $evento_lower  = strtolower($evento_nome);

            if (str_contains($evento_lower, 'boom')) {
                $fator_demanda = 1.60;
            } elseif (str_contains($evento_lower, 'pico')) {
                $fator_demanda = 1.80;
            } elseif (str_contains($evento_lower, 'natal') || str_contains($evento_lower, 'final')) {
                $fator_demanda = 1.80;
            } elseif (str_contains($evento_lower, 'crise') || str_contains($evento_lower, 'atraso')) {
                $fator_demanda = 1.10;
            }
        } else {
            $fator_demanda = $fator_demanda_forcado;
        }

        try {
            $pdo->beginTransaction();

            // 1. LOCK da rodada aberta (evita duplo fechamento simultâneo)
            $stmt_r = $pdo->query("
                SELECT * FROM `jogo_rodadas`
                WHERE `status` = 'aberta'
                ORDER BY `numero_rodada` DESC, `id` DESC
                LIMIT 1
                FOR UPDATE
            ");
            $rodada_atual = $stmt_r->fetch();

            if (!$rodada_atual) {
                throw new Exception(
                    'Nenhuma rodada aberta para ser encerrada. ' .
                    'Verifique se o banco foi inicializado corretamente.'
                );
            }

            $rodada_id = (int) $rodada_atual['id'];

            // 2. Carrega TODAS as equipes (ativas + falidas)
            $equipes = $pdo->query("
                SELECT `id`, `falida`
                FROM `equipes`
                WHERE `ativo` = 1 OR `falida` = 1
            ")->fetchAll();

            // 3. PROCESSAMENTO POR EQUIPE
            foreach ($equipes as $eq) {
                $eq_id     = (int) $eq['id'];
                $ja_falida = (int) $eq['falida'] === 1;

                if ($ja_falida) {
                    $pdo->prepare("
                        INSERT INTO `ranking_rodadas`
                            (`rodada_id`, `equipe_id`, `score_rodada`, `lucro_liquido`)
                        VALUES (?, ?, 0, 0.00)
                        ON DUPLICATE KEY UPDATE
                            `score_rodada`  = 0,
                            `lucro_liquido` = 0
                    ")->execute([$rodada_id, $eq_id]);
                    continue;
                }

                // 3.1 VENDAS
                $demandas          = getDemandasRodada($pdo, $eq_id);
                $receita           = 0.0;
                $custo_v           = 0.0;
                $unidades_vendidas = 0;

                foreach ($demandas['itens'] as $cod => $item) {
                    $meta_produto = (int) $item['meta'];

                    $stmt_p = $pdo->prepare("
                        SELECT `id`, `preco_venda`, `estoque_atual`
                        FROM `produtos`
                        WHERE `equipe_id` = ? AND `codigo` = ?
                        LIMIT 1
                    ");
                    $stmt_p->execute([$eq_id, $cod]);
                    $p = $stmt_p->fetch();

                    if (!$p) continue;

                    $estoque  = (int) $p['estoque_atual'];
                    $vendidas = min($estoque, $meta_produto);

                    if ($vendidas <= 0) continue;

                    $preco_unit = (float) $p['preco_venda'];
                    $custo_unit = custoBarraProduto($pdo, $eq_id, (int) $p['id']);

                    $receita           += $vendidas * $preco_unit;
                    $custo_v           += $vendidas * $custo_unit;
                    $unidades_vendidas += $vendidas;

                    $pdo->prepare("
                        UPDATE `produtos`
                        SET `estoque_atual` = `estoque_atual` - ?
                        WHERE `id` = ? AND `equipe_id` = ?
                    ")->execute([$vendidas, (int) $p['id'], $eq_id]);

                    $pdo->prepare("
                        INSERT INTO `mov_estoque`
                            (`equipe_id`, `tipo`, `tipo_item`, `produto_id`, `quantidade`, `motivo`)
                        VALUES (?, 'saida', 'produto', ?, ?, ?)
                    ")->execute([
                        $eq_id,
                        (int) $p['id'],
                        $vendidas,
                        "Venda da Rodada {$rodada_atual['numero_rodada']}",
                    ]);
                }

                if ($receita > 0) {
                    $pdo->prepare("
                        UPDATE `equipes`
                        SET `caixa_disponivel` = `caixa_disponivel` + ?
                        WHERE `id` = ?
                    ")->execute([$receita, $eq_id]);
                }

                $lucro_rodada = $receita - $custo_v;

                // 3.2 KPIs
                $stmt = $pdo->prepare("
                    SELECT
                        COUNT(`id`)                                AS `total_ops`,
                        COALESCE(SUM(`quantidade_planejada`), 0)  AS `planejado`,
                        COALESCE(SUM(`quantidade_produzida`), 0)  AS `produzido`,
                        COALESCE(SUM(`quantidade_refugo`), 0)     AS `refugo`,
                        SUM(CASE
                            WHEN `status` = 'finalizada'
                             AND `quantidade_produzida` >= `quantidade_planejada`
                            THEN 1 ELSE 0
                        END)                                       AS `ops_otif`
                    FROM `ordens_producao`
                    WHERE `equipe_id` = ? AND `rodada_id` = ?
                ");
                $stmt->execute([$eq_id, $rodada_id]);
                $kpi = $stmt->fetch() ?: [
                    'total_ops' => 0, 'planejado' => 0, 'produzido' => 0,
                    'refugo'    => 0, 'ops_otif'  => 0,
                ];

                $total_ops  = (int) $kpi['total_ops'];
                $planejado  = (int) $kpi['planejado'];
                $produzido  = (int) $kpi['produzido'];
                $refugo     = (int) $kpi['refugo'];
                $ops_otif   = (int) $kpi['ops_otif'];
                $total_proc = $produzido + $refugo;

                $otif        = $total_ops  > 0 ? ($ops_otif  / $total_ops) * 100 : 0;
                $aderencia   = $planejado  > 0 ? min(100, ($produzido / $planejado) * 100) : 0;
                $taxa_refugo = $total_proc > 0 ? ($refugo / $total_proc) * 100 : 0;
                $yield       = max(0, 100 - ($taxa_refugo * 5));

                // 3.2-bis Penalidade de setup
                $stmt_setup = $pdo->prepare("
                    SELECT p.`intensidade_aroma`
                    FROM `ordens_producao` op
                    JOIN `produtos` p ON op.`produto_id` = p.`id`
                    WHERE op.`equipe_id` = ? AND op.`rodada_id` = ?
                    ORDER BY op.`data_programada` ASC, op.`id` ASC
                ");
                $stmt_setup->execute([$eq_id, $rodada_id]);
                $aromas = $stmt_setup->fetchAll(PDO::FETCH_COLUMN);

                $penalidades = 0;
                $pesos = ['suave' => 1, 'moderado' => 2, 'intenso' => 3];
                for ($i = 1; $i < count($aromas); $i++) {
                    $ant = $pesos[$aromas[$i - 1]] ?? 0;
                    $atu = $pesos[$aromas[$i]]     ?? 0;
                    if ($ant > $atu) $penalidades += 50;
                }

                // 3.3 Score
                $score_operacional = (int) round(
                    ($otif       * 2.5) +
                    ($aderencia  * 2.0) +
                    ($yield      * 2.5) -
                    $penalidades
                );
                $score_operacional = max(0, $score_operacional);

                $bonus_lucro = (int) min(400, max(0, round($lucro_rodada / 100)));
                $score       = $score_operacional + $bonus_lucro;

                // 3.4 UPSERT ranking_rodadas
                $pdo->prepare("
                    INSERT INTO `ranking_rodadas`
                        (`rodada_id`, `equipe_id`, `score_rodada`, `lucro_liquido`,
                         `otif_perc`, `taxa_refugo`, `penalidades`)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        `score_rodada`  = VALUES(`score_rodada`),
                        `lucro_liquido` = VALUES(`lucro_liquido`),
                        `otif_perc`     = VALUES(`otif_perc`),
                        `taxa_refugo`   = VALUES(`taxa_refugo`),
                        `penalidades`   = VALUES(`penalidades`)
                ")->execute([
                    $rodada_id, $eq_id, $score, $lucro_rodada,
                    $otif, $taxa_refugo, $penalidades,
                ]);

                // 3.5 Recalcula score_total
                $stmt_t = $pdo->prepare("
                    SELECT COALESCE(SUM(`score_rodada`), 0)
                    FROM `ranking_rodadas`
                    WHERE `equipe_id` = ?
                ");
                $stmt_t->execute([$eq_id]);
                $score_total = (int) $stmt_t->fetchColumn();

                $pdo->prepare("
                    UPDATE `equipes`
                    SET `score_total` = ?
                    WHERE `id` = ?
                ")->execute([$score_total, $eq_id]);

                // 3.6 Avalia falência
                $saude = avaliarSaudeFinanceira($pdo, $eq_id);

                if (($saude['status'] ?? '') === 'falida') {
                    $pdo->prepare("
                        INSERT INTO `mov_estoque`
                            (`equipe_id`, `tipo`, `tipo_item`, `quantidade`, `motivo`)
                        VALUES (?, 'saida', 'produto', 0, ?)
                    ")->execute([
                        $eq_id,
                        "🚫 FALÊNCIA declarada — caixa negativo por 2 rodadas consecutivas",
                    ]);
                }
            }

            // 4. ENCERRA rodada atual
            $pdo->prepare("
                UPDATE `jogo_rodadas`
                SET `status` = 'encerrada'
                WHERE `id` = ?
            ")->execute([$rodada_id]);

            // 5. ABRE próxima rodada
            $nova_num = (int) $rodada_atual['numero_rodada'] + 1;
            $novo_mes = 'Mês ' . str_pad((string) $nova_num, 2, '0', STR_PAD_LEFT);

            $pdo->prepare("
                INSERT INTO `jogo_rodadas`
                    (`numero_rodada`, `mes_ano`, `status`,
                     `evento_titulo`, `evento_descricao`, `fator_demanda`)
                VALUES (?, ?, 'aberta', ?, ?, ?)
            ")->execute([$nova_num, $novo_mes, $evento_nome, $evento_desc, $fator_demanda]);

            // 6. COMMIT
            $pdo->commit();

            return [
                'success'          => true,
                'rodada_encerrada' => (int) $rodada_atual['numero_rodada'],
                'nova_rodada'      => $nova_num,
                'novo_mes'         => $novo_mes,
                'fator_demanda'    => $fator_demanda,
                'message'          => sprintf(
                    'Rodada %d encerrada com sucesso! Nova rodada %d aberta (fator %.2fx).',
                    (int) $rodada_atual['numero_rodada'],
                    $nova_num,
                    $fator_demanda
                ),
            ];

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[fecharRodadaAtual] ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Erro ao fechar rodada: ' . $e->getMessage(),
            ];
        }
    }
}

// =============================================================================
// 9. UTILITÁRIOS COMPLEMENTARES (datas, números, debug)
// =============================================================================

if (!function_exists('mesAnoAtual')) {
    function mesAnoAtual(int $numero_rodada): string
    {
        return 'Mês ' . str_pad((string) $numero_rodada, 2, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('formatarPercentual')) {
    function formatarPercentual(float $valor, int $decimais = 1): string
    {
        return number_format($valor, $decimais, ',', '.') . '%';
    }
}

if (!function_exists('classeCorScore')) {
    function classeCorScore(int $score): string
    {
        if ($score >= 800) return 'text-success';
        if ($score >= 500) return 'text-primary';
        if ($score >= 200) return 'text-warning';
        return 'text-danger';
    }
}

if (!function_exists('safeInt')) {
    /**
     * Cast seguro para inteiro (evita null/false → 0 sem warnings).
     *
     * @param mixed $valor
     * @param int   $default
     */
    function safeInt(mixed $valor, int $default = 0): int
    {
        if ($valor === null || $valor === false || $valor === '') return $default;
        return (int) $valor;
    }
}

if (!function_exists('safeFloat')) {
    /**
     * Cast seguro para float (aceita vírgula ou ponto como separador decimal).
     *
     * @param mixed $valor
     * @param float $default
     */
    function safeFloat(mixed $valor, float $default = 0.0): float
    {
        if ($valor === null || $valor === false || $valor === '') return $default;
        if (is_string($valor)) {
            $valor = str_replace(',', '.', $valor);
        }
        return (float) $valor;
    }
}

if (!function_exists('debugLog')) {
    function debugLog(string $contexto, array $dados = []): void
    {
        if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
            return;
        }
        $msg = '[DEBUG] ' . $contexto . ' :: ' . json_encode($dados, JSON_UNESCAPED_UNICODE);
        error_log($msg);
    }
}