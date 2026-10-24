<?php

/**
 * Flora Sabão — Funções Auxiliares Globais (PCP Lab & Business Game)
 * =================================================================
 * Biblioteca central do simulador com suporte Multi-Turma / Multi-Game avançado.
 * Permite que um mesmo instrutor crie, gerencie, pause e alterne entre múltiplos
 * jogos/turmas em paralelo sem interferência mútua.
 *
 * Módulos integrados:
 *   §1  Formatação e UI (moeda, quantidade, badge de status)
 *   §2  Sessão, autenticação e perfis
 *   §3  Flash messages
 *   §4  Contexto multi-tenant de Jogos/Turmas (Multi-Game por Instrutor)
 *   §5  Contexto multi-tenant de Equipes (com sincronização de turma)
 *   §6  Gerenciador de Criação, Reset e Ciclo de Vida de Turmas
 *   §7  Contexto de rodada por Jogo (Business Game)
 *   §8  Gerador dinâmico de demanda (S&OP determinístico por equipe/rodada)
 *   §9  Controle financeiro (custo de MP, caixa, falência)
 *   §10 Encerramento de rodada transacional isolado por Jogo
 *   §11 Utilitários complementares (data, números, debug)
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
            'jogo_id'   => $_SESSION['jogo_id'] ?? null,
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

// ==============================================================================
// 4. CONTEXTO MULTI-TENANT DE JOGOS / TURMAS (MÚLTIPLOS JOGOS POR INSTRUTOR)
// ==============================================================================

if (!function_exists('getJogoAtivoId')) {
    /**
     * Retorna o ID do Jogo/Turma ativa no contexto atual:
     * - Aluno: herdado de forma imutável da sua equipe.
     * - Instrutor: selecionável via ?jogo_id=X, salvo em sessão, com fallback
     *   automático para o jogo ativo mais recente do próprio instrutor.
     */
    function getJogoAtivoId(?PDO $pdo = null): int
    {
        iniciarSessao();

        // 1. Aluno comum: sempre amarrado à sua equipe
        if (estaLogado() && !isAdmin()) {
            if (!empty($_SESSION['jogo_id']) && (int)$_SESSION['jogo_id'] > 0) {
                return (int)$_SESSION['jogo_id'];
            }
            $eq_id = (int)($_SESSION['equipe_id'] ?? $_SESSION['usuario_equipe_id'] ?? 0);
            if ($eq_id > 0 && $pdo instanceof PDO) {
                $stmt = $pdo->prepare("SELECT jogo_id FROM equipes WHERE id = ?");
                $stmt->execute([$eq_id]);
                $jid = (int)$stmt->fetchColumn();
                if ($jid > 0) {
                    $_SESSION['jogo_id'] = $jid;
                    return $jid;
                }
            }
        }

        // 2. Instrutor alternando turma via GET (?jogo_id=X)
        if (isAdmin() && isset($_GET['jogo_id']) && (int)$_GET['jogo_id'] > 0) {
            $novo_jid = (int)$_GET['jogo_id'];
            if ($pdo instanceof PDO) {
                selecionarJogoAtivo($pdo, $novo_jid);
            } else {
                $_SESSION['jogo_id'] = $novo_jid;
            }
            return $novo_jid;
        }

        // 3. Jogo já ativo na sessão do instrutor
        if (!empty($_SESSION['jogo_id']) && (int)$_SESSION['jogo_id'] > 0) {
            return (int)$_SESSION['jogo_id'];
        }

        // 4. Fallback dinâmico para instrutor: busca a turma ativa mais recente criada por ele
        if (isAdmin() && $pdo instanceof PDO) {
            $uid = (int)($_SESSION['usuario_id'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT id FROM jogos
                WHERE instrutor_id = ? AND status = 'ativo'
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$uid]);
            $meu_jogo = (int)$stmt->fetchColumn();
            if ($meu_jogo > 0) {
                $_SESSION['jogo_id'] = $meu_jogo;
                return $meu_jogo;
            }
        }

        return (int)($_SESSION['jogo_id'] ?? 1);
    }
}

if (!function_exists('setJogoAtivoId')) {
    function setJogoAtivoId(int $jogo_id): void
    {
        iniciarSessao();
        $_SESSION['jogo_id'] = $jogo_id;
    }
}

if (!function_exists('selecionarJogoAtivo')) {
    /**
     * Alterna com segurança o jogo ativo do instrutor e reajusta a equipe em visualização.
     */
    function selecionarJogoAtivo(PDO $pdo, int $jogo_id): bool
    {
        iniciarSessao();
        if (!isAdmin()) {
            return false;
        }

        $uid = (int)($_SESSION['usuario_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id FROM jogos WHERE id = ? AND (instrutor_id = ? OR ? = 1)");
        $stmt->execute([$jogo_id, $uid, $uid]);
        $valido = (int)$stmt->fetchColumn();

        if ($valido > 0) {
            $_SESSION['jogo_id'] = $jogo_id;

            // Sincroniza a equipe selecionada para a primeira equipe desta nova turma
            $stmt_eq = $pdo->prepare("SELECT id FROM equipes WHERE jogo_id = ? ORDER BY id ASC LIMIT 1");
            $stmt_eq->execute([$jogo_id]);
            $primeira_eq = (int)$stmt_eq->fetchColumn();
            if ($primeira_eq > 0) {
                $_SESSION['equipe_id_admin_view'] = $primeira_eq;
            }
            return true;
        }
        return false;
    }
}

if (!function_exists('jogoAtual')) {
    function jogoAtual(PDO $pdo, ?int $jogo_id = null): ?array
    {
        $jid = $jogo_id ?? getJogoAtivoId($pdo);
        if ($jid <= 0) {
            return null;
        }
        $stmt = $pdo->prepare("SELECT * FROM jogos WHERE id = ?");
        $stmt->execute([$jid]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}

if (!function_exists('jogosDoInstrutor')) {
    /**
     * Retorna todas as turmas/jogos pertencentes ao instrutor logado com KPIs consolidados.
     */
    function jogosDoInstrutor(PDO $pdo, ?int $instrutor_id = null): array
    {
        iniciarSessao();
        $uid = $instrutor_id ?? (int)($_SESSION['usuario_id'] ?? 0);
        if ($uid <= 0) {
            return [];
        }
        $stmt = $pdo->prepare("
            SELECT j.*,
                   (SELECT COUNT(*) FROM equipes WHERE jogo_id = j.id) AS total_equipes,
                   (SELECT COUNT(*) FROM equipes WHERE jogo_id = j.id AND falida = 1) AS total_falidas,
                   (SELECT numero_rodada FROM jogo_rodadas WHERE jogo_id = j.id AND status = 'aberta' LIMIT 1) AS rodada_atual,
                   (SELECT COUNT(*) FROM ordens_producao op JOIN equipes e ON op.equipe_id = e.id WHERE e.jogo_id = j.id AND op.status IN ('planejada','em_producao')) AS ops_em_aberto
            FROM jogos j
            WHERE j.instrutor_id = ?
            ORDER BY FIELD(j.status, 'ativo', 'pausado', 'encerrado'), j.id DESC
        ");
        $stmt->execute([$uid]);
        return $stmt->fetchAll();
    }
}

if (!function_exists('urlComJogo')) {
    function urlComJogo(string $url, ?int $jogo_id = null): string
    {
        $jid = $jogo_id ?? getJogoAtivoId();
        if ($jid <= 0) {
            return $url;
        }
        $sep = str_contains($url, '?') ? '&' : '?';
        return $url . $sep . 'jogo_id=' . $jid;
    }
}

if (!function_exists('resumoJogoAtual')) {
    /**
     * Retorna o resumo consolidado dos indicadores da turma em execução.
     */
    function resumoJogoAtual(PDO $pdo, ?int $jogo_id = null): array
    {
        $jid = $jogo_id ?? getJogoAtivoId($pdo);
        $jogo = jogoAtual($pdo, $jid);

        if (!$jogo) {
            return [
                'jogo'            => null,
                'total_equipes'   => 0,
                'total_falidas'   => 0,
                'rodada_numero'   => 1,
                'rodada_mes'      => 'Mês 01',
                'caixa_acumulado' => 0.0,
                'ops_ativas'      => 0,
            ];
        }

        $stmt = $pdo->prepare("
            SELECT 
                COUNT(id) AS total_eqs,
                COALESCE(SUM(CASE WHEN falida = 1 THEN 1 ELSE 0 END), 0) AS total_fal,
                COALESCE(SUM(caixa_disponivel), 0) AS soma_caixa
            FROM equipes
            WHERE jogo_id = ?
        ");
        $stmt->execute([$jid]);
        $eqData = $stmt->fetch() ?: ['total_eqs' => 0, 'total_fal' => 0, 'soma_caixa' => 0.0];

        $rodada = getRodadaAtiva($pdo, $jid);

        $stmt_ops = $pdo->prepare("
            SELECT COUNT(op.id)
            FROM ordens_producao op
            JOIN equipes e ON op.equipe_id = e.id
            WHERE e.jogo_id = ? AND op.status IN ('planejada', 'em_producao')
        ");
        $stmt_ops->execute([$jid]);
        $opsAtivas = (int)$stmt_ops->fetchColumn();

        return [
            'jogo'            => $jogo,
            'total_equipes'   => (int)$eqData['total_eqs'],
            'total_falidas'   => (int)$eqData['total_fal'],
            'rodada_numero'   => (int)($rodada['numero_rodada'] ?? 1),
            'rodada_mes'      => (string)($rodada['mes_ano'] ?? 'Mês 01'),
            'caixa_acumulado' => (float)$eqData['soma_caixa'],
            'ops_ativas'      => $opsAtivas,
        ];
    }
}

// ==============================================================================
// 5. CONTEXTO DE EQUIPE (Multi-Tenant Seguro com Sincronização de Turma)
// ==============================================================================

if (!function_exists('getEquipeAtivaId')) {
    function getEquipeAtivaId(?PDO $pdo = null): int
    {
        iniciarSessao();

        if (!estaLogado()) {
            return 0;
        }

        $perfil  = $_SESSION['usuario_perfil'] ?? '';
        $minhaEq = (int) ($_SESSION['equipe_id'] ?? $_SESSION['usuario_equipe_id'] ?? 0);

        // Aluno comum sempre cai na própria equipe
        if ($perfil !== 'admin') {
            return $minhaEq > 0 ? $minhaEq : 1;
        }

        // Instrutor inspecionando equipe via GET (?equipe_id=X)
        if (isset($_GET['equipe_id']) && (int) $_GET['equipe_id'] > 0) {
            $_SESSION['equipe_id_admin_view'] = (int) $_GET['equipe_id'];
            return (int) $_GET['equipe_id'];
        }

        // Se houver equipe em cache na sessão do instrutor, valida se pertence à turma ativa
        $eq_cache = (int) ($_SESSION['equipe_id_admin_view'] ?? 0);
        if ($eq_cache > 0 && $pdo instanceof PDO) {
            $jid_ativo = getJogoAtivoId($pdo);
            $stmt = $pdo->prepare("SELECT id FROM equipes WHERE id = ? AND jogo_id = ?");
            $stmt->execute([$eq_cache, $jid_ativo]);
            if ($stmt->fetchColumn()) {
                return $eq_cache;
            }
            // Se mudou de turma, busca a primeira equipe da nova turma
            $stmt_nova = $pdo->prepare("SELECT id FROM equipes WHERE jogo_id = ? ORDER BY id ASC LIMIT 1");
            $stmt_nova->execute([$jid_ativo]);
            $primeira = (int)$stmt_nova->fetchColumn();
            if ($primeira > 0) {
                $_SESSION['equipe_id_admin_view'] = $primeira;
                return $primeira;
            }
        }

        return $eq_cache > 0 ? $eq_cache : 1;
    }
}

if (!function_exists('equipeAtual')) {
    function equipeAtual(PDO $pdo): ?array
    {
        $eq_id = getEquipeAtivaId($pdo);
        if ($eq_id <= 0) {
            return null;
        }
        $stmt = $pdo->prepare("SELECT * FROM equipes WHERE id = ? AND (ativo = 1 OR falida = 1)");
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
    function equipesDisponiveis(PDO $pdo, ?int $jogo_id = null): array
    {
        if (!estaLogado()) {
            return [];
        }

        $jid = $jogo_id ?? getJogoAtivoId($pdo);

        if (isAdmin()) {
            $stmt = $pdo->prepare("
                SELECT id, jogo_id, nome, cor_hex, caixa_disponivel, score_total, falida, codigo_acesso
                FROM equipes 
                WHERE (ativo = 1 OR falida = 1) 
                  AND (jogo_id = ? OR ? = 0)
                ORDER BY falida ASC, score_total DESC, id ASC
            ");
            $stmt->execute([$jid, $jid]);
            return $stmt->fetchAll();
        }

        $eq = getEquipeAtivaId($pdo);
        $stmt = $pdo->prepare("SELECT id, jogo_id, nome, cor_hex, caixa_disponivel, score_total, falida, codigo_acesso FROM equipes WHERE id = ?");
        $stmt->execute([$eq]);
        return $stmt->fetchAll();
    }
}

// ==============================================================================
// 6. GERENCIADOR DE CRIAÇÃO, RESET E CICLO DE VIDA DE TURMAS / JOGOS
// ==============================================================================

if (!function_exists('criarNovoJogo')) {
    /**
     * Cria uma nova partida/turma completa para um instrutor,
     * inicializando rodada, equipes com senhas protegidas, catálogo de produtos, insumos e BOMs.
     */
    function criarNovoJogo(
        PDO $pdo,
        int $instrutor_id,
        string $nome_turma,
        string $pin_jogo,
        int $qtd_equipes = 6,
        float $caixa_inicial = 15000.00
    ): int {
        $pdo->beginTransaction();

        try {
            $pin_jogo = strtoupper(trim($pin_jogo));

            // 1. Cadastra a partida/turma
            $stmt = $pdo->prepare("
                INSERT INTO jogos (instrutor_id, pin_jogo, nome_turma, qtd_equipes, caixa_inicial, status)
                VALUES (?, ?, ?, ?, ?, 'ativo')
            ");
            $stmt->execute([$instrutor_id, $pin_jogo, trim($nome_turma), $qtd_equipes, $caixa_inicial]);
            $jogo_id = (int)$pdo->lastInsertId();

            // 2. Cria a Rodada 1 inicial aberta para esta turma
            $stmt_rodada = $pdo->prepare("
                INSERT INTO jogo_rodadas (jogo_id, numero_rodada, mes_ano, status, evento_titulo, evento_descricao, fator_demanda)
                VALUES (?, 1, 'Mês 01', 'aberta', 'Rodada de Aquecimento', 'Mercado padrão sem distorções.', 1.00)
            ");
            $stmt_rodada->execute([$jogo_id]);
            $rodada_id = (int)$pdo->lastInsertId();

            // 3. Configurações base das equipes
            $dados_equipes = [
                1 => ['nome' => 'Equipe Alfa',    'cor' => '#2d5a3f', 'tag' => 'ALFA'],
                2 => ['nome' => 'Equipe Beta',    'cor' => '#1e5084', 'tag' => 'BETA'],
                3 => ['nome' => 'Equipe Gama',    'cor' => '#b85d19', 'tag' => 'GAMA'],
                4 => ['nome' => 'Equipe Delta',   'cor' => '#6b2d84', 'tag' => 'DELTA'],
                5 => ['nome' => 'Equipe Épsilon', 'cor' => '#841e3d', 'tag' => 'EPSILON'],
                6 => ['nome' => 'Equipe Ômega',   'cor' => '#3d454a', 'tag' => 'OMEGA'],
            ];

            $insumos_padrao = [
                ['BASE-GLIC', 'Base Glicerinada Vegetal',       'kg', 120.0, 30.0, 24.50],
                ['EXT-ALOE',  'Extrato Glicólico Aloe Vera',     'L',   5.0,  1.5, 68.00],
                ['ESS-LAV',   'Essência Lavanda (Suave)',        'L',   1.2,  0.8, 110.00],
                ['ESS-ROS',   'Essência Rosas (Moderada)',       'L',   0.6,  0.8, 125.00],
                ['ESS-PIT',   'Essência Pitanga (Moderada)',     'L',   2.0,  0.8, 95.00],
                ['ESS-MAR',   'Essência Maracujá (Intensa)',     'L',   2.5,  0.8, 92.00],
                ['LAURIL',    'Lauril Éter Sulfato (Espumante)', 'L',   4.0,  1.0, 18.00],
                ['EMB-KRAFT', 'Cinta Papel Kraft',               'un', 800.0, 200.0, 0.45],
                ['CELOF-BIO', 'Película Biodegradável',          'un', 850.0, 200.0, 0.20],
            ];

            $produtos_padrao = [
                ['MAR', 'Sabonete Maracujá Cítrico', 'Maracujá', 'suave',    15.00, 'ESS-MAR'],
                ['PIT', 'Sabonete Pitanga Tropical', 'Pitanga',  'moderado', 15.00, 'ESS-PIT'],
                ['LAV', 'Sabonete Lavanda Francesa', 'Lavanda',  'moderado', 16.50, 'ESS-LAV'],
                ['ROS', 'Sabonete Rosas Nobres',     'Rosas',    'intenso',  16.50, 'ESS-ROS'],
            ];

            // Senha padrão criptografada em BCRYPT (senha: 123456)
            $senhaPadraoHash = '$2y$10$uDnEpQpkh9RO5tWwOrHtneLAuYMRDstRaKGZFyHubObDR0789OMO6';

            // 4. Cadastra as equipes da partida
            for ($i = 1; $i <= $qtd_equipes; $i++) {
                $conf = $dados_equipes[$i] ?? ['nome' => "Equipe $i", 'cor' => '#2d5a3f', 'tag' => "EQ$i"];

                // Código único de acesso do aluno: PIN-TAG-HEX (ex: ENG1-ALFA-4B9F)
                $codigo_acesso = strtoupper($pin_jogo . '-' . $conf['tag'] . '-' . substr(bin2hex(random_bytes(2)), 0, 4));

                $stmt_eq = $pdo->prepare("
                    INSERT INTO equipes (jogo_id, nome, codigo_acesso, cor_hex, caixa_disponivel, tacho_ultimo_aroma, tacho_higienizado)
                    VALUES (?, ?, ?, ?, ?, 'LIMPO', 1)
                ");
                $stmt_eq->execute([$jogo_id, $conf['nome'], $codigo_acesso, $conf['cor'], $caixa_inicial]);
                $equipe_id = (int)$pdo->lastInsertId();

                // Ranking inicial da equipe na rodada 1
                $pdo->prepare("INSERT INTO ranking_rodadas (rodada_id, equipe_id, score_rodada) VALUES (?, ?, 0)")
                    ->execute([$rodada_id, $equipe_id]);

                // Usuário vinculado à equipe para acesso
                $email_equipe = strtolower("{$pin_jogo}_eq{$i}@flora.com");
                $pdo->prepare("
                    INSERT INTO usuarios (equipe_id, nome, email, senha, perfil)
                    VALUES (?, ?, ?, ?, 'pcp')
                ")->execute([$equipe_id, $conf['nome'], $email_equipe, $senhaPadraoHash]);

                // Cadastra os insumos exclusivos da equipe
                $insumo_map = [];
                $stmt_ins = $pdo->prepare("
                    INSERT INTO insumos (equipe_id, codigo, nome, unidade, estoque_atual, estoque_minimo, custo_unitario)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($insumos_padrao as $ins) {
                    $stmt_ins->execute([$equipe_id, $ins[0], $ins[1], $ins[2], $ins[3], $ins[4], $ins[5]]);
                    $insumo_map[$ins[0]] = (int)$pdo->lastInsertId();
                }

                // Cadastra produtos e suas respectivas Fichas Técnicas (BOM)
                $stmt_prod = $pdo->prepare("
                    INSERT INTO produtos (equipe_id, codigo, nome, aroma, intensidade_aroma, estoque_atual, estoque_minimo, preco_venda)
                    VALUES (?, ?, ?, ?, ?, 150, 50, ?)
                ");
                $stmt_bom = $pdo->prepare("
                    INSERT INTO ficha_tecnica (equipe_id, produto_id, insumo_id, qtd_por_barra)
                    VALUES (?, ?, ?, ?)
                ");

                foreach ($produtos_padrao as $prod) {
                    $stmt_prod->execute([$equipe_id, $prod[0], $prod[1], $prod[2], $prod[3], $prod[4]]);
                    $prod_id = (int)$pdo->lastInsertId();

                    // Fórmulas fixas
                    $stmt_bom->execute([$equipe_id, $prod_id, $insumo_map['BASE-GLIC'], 0.0920]);
                    $stmt_bom->execute([$equipe_id, $prod_id, $insumo_map['EXT-ALOE'],  0.0020]);
                    $stmt_bom->execute([$equipe_id, $prod_id, $insumo_map['LAURIL'],    0.0020]);
                    $stmt_bom->execute([$equipe_id, $prod_id, $insumo_map['EMB-KRAFT'], 1.0000]);
                    $stmt_bom->execute([$equipe_id, $prod_id, $insumo_map['CELOF-BIO'], 1.0000]);

                    // Essência exclusiva de cada SKU
                    $stmt_bom->execute([$equipe_id, $prod_id, $insumo_map[$prod[5]],    0.0040]);
                }
            }

            $pdo->commit();

            // Ativa o novo jogo criado na sessão
            selecionarJogoAtivo($pdo, $jogo_id);

            return $jogo_id;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[criarNovoJogo] ' . $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('alternarStatusJogo')) {
    /**
     * Pausa, reativa ou encerra uma turma/jogo do instrutor.
     */
    function alternarStatusJogo(PDO $pdo, int $jogo_id, string $novo_status): bool
    {
        if (!in_array($novo_status, ['ativo', 'pausado', 'encerrado'], true)) {
            return false;
        }
        $stmt = $pdo->prepare("UPDATE jogos SET status = ? WHERE id = ?");
        return $stmt->execute([$novo_status, $jogo_id]);
    }
}

if (!function_exists('reiniciarJogoEspecifico')) {
    /**
     * Reseta SOMENTE o jogo/turma especificado, preservando intactas todas as outras turmas.
     */
    function reiniciarJogoEspecifico(PDO $pdo, int $jogo_id): bool
    {
        $pdo->beginTransaction();
        try {
            // 1. Busca IDs das equipes daquela turma
            $stmt = $pdo->prepare("SELECT id FROM equipes WHERE jogo_id = ?");
            $stmt->execute([$jogo_id]);
            $equipes_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($equipes_ids)) {
                $inQuery = implode(',', array_map('intval', $equipes_ids));

                // 2. Limpa movimentações e OPs apenas daquelas equipes
                $pdo->exec("DELETE FROM mov_estoque WHERE equipe_id IN ({$inQuery})");
                $pdo->exec("DELETE FROM ranking_rodadas WHERE equipe_id IN ({$inQuery})");
                $pdo->exec("DELETE FROM ordens_producao WHERE equipe_id IN ({$inQuery})");

                // 3. Reseta estados das equipes
                $pdo->prepare("
                    UPDATE equipes 
                    SET caixa_disponivel = 15000.00, score_total = 0,
                        tacho_ultimo_aroma = 'LIMPO', tacho_higienizado = 1,
                        rodadas_no_vermelho = 0, falida = 0, data_falencia = NULL
                    WHERE jogo_id = ?
                ")->execute([$jogo_id]);

                // 4. Reseta estoques de PA
                $pdo->exec("UPDATE produtos SET estoque_atual = 150, estoque_minimo = 50 WHERE equipe_id IN ({$inQuery})");

                // 5. Reseta insumos aos níveis iniciais
                $insumos_reset = [
                    'BASE-GLIC' => 120.0,
                    'EXT-ALOE' => 5.0,
                    'ESS-LAV' => 1.2,
                    'ESS-ROS'   => 0.6,
                    'ESS-PIT'  => 2.0,
                    'ESS-MAR' => 2.5,
                    'LAURIL'    => 4.0,
                    'EMB-KRAFT' => 800.0,
                    'CELOF-BIO' => 850.0
                ];
                $stmt_up_ins = $pdo->prepare("UPDATE insumos SET estoque_atual = ? WHERE equipe_id = ? AND codigo = ?");
                foreach ($equipes_ids as $eqId) {
                    foreach ($insumos_reset as $codIns => $saldoIni) {
                        $stmt_up_ins->execute([$saldoIni, $eqId, $codIns]);
                    }
                }
            }

            // 6. Limpa rodadas antigas e recria a Rodada 1 do jogo
            $pdo->prepare("DELETE FROM jogo_rodadas WHERE jogo_id = ?")->execute([$jogo_id]);
            $pdo->prepare("
                INSERT INTO jogo_rodadas (jogo_id, numero_rodada, mes_ano, status, evento_titulo, evento_descricao, fator_demanda)
                VALUES (?, 1, 'Mês 01', 'aberta', 'Rodada de Aquecimento', 'Jogo reiniciado com sucesso!', 1.00)
            ")->execute([$jogo_id]);
            $nova_rodada_id = (int)$pdo->lastInsertId();

            // 7. Recria ranking zerado na rodada 1
            if (!empty($equipes_ids)) {
                $stmt_rk = $pdo->prepare("INSERT INTO ranking_rodadas (rodada_id, equipe_id, score_rodada) VALUES (?, ?, 0)");
                foreach ($equipes_ids as $eqId) {
                    $stmt_rk->execute([$nova_rodada_id, $eqId]);
                }
            }

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[reiniciarJogoEspecifico] ' . $e->getMessage());
            return false;
        }
    }
}

// =========================================================
// 7. CONTEXTO DE RODADA (Business Game por Turma)
// =========================================================

if (!function_exists('getRodadaAtiva')) {
    /**
     * Retorna a rodada aberta do jogo ativo.
     * Suporta passagem de $jogo_id explícito ou inferência de sessão.
     */
    function getRodadaAtiva(PDO $pdo, ?int $jogo_id = null): array
    {
        $jid = $jogo_id ?? getJogoAtivoId($pdo);

        // 1. Tenta buscar rodada aberta do jogo específico
        $stmt = $pdo->prepare("
            SELECT *
            FROM jogo_rodadas
            WHERE status = 'aberta' AND (jogo_id = ? OR ? = 0)
            ORDER BY numero_rodada DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$jid, $jid]);
        $rodada = $stmt->fetch();

        // 2. Fallback caso a tabela ainda não possua rodadas cadastradas
        if (!$rodada) {
            $stmt_geral = $pdo->query("
                SELECT * FROM jogo_rodadas
                WHERE status = 'aberta'
                ORDER BY numero_rodada DESC, id DESC
                LIMIT 1
            ");
            $rodada = $stmt_geral->fetch();
        }

        if (!$rodada) {
            return [
                'id'               => 1,
                'jogo_id'          => $jid,
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
    function temRodadaAberta(PDO $pdo, ?int $jogo_id = null): bool
    {
        $r = getRodadaAtiva($pdo, $jogo_id);
        return !empty($r['id']);
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
// 8. GERADOR DINÂMICO DE DEMANDA & SAZONALIDADE (S&OP INTELIGENTE)
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
     * Retorna as metas de demanda calculadas dinamicamente com base na rodada da turma.
     */
    function getDemandasRodada(PDO $pdo, int $equipe_id, ?int $jogo_id = null): array
    {
        // Descobre o jogo da equipe se não informado
        if ($jogo_id === null) {
            $stmt_jq = $pdo->prepare("SELECT jogo_id FROM equipes WHERE id = ?");
            $stmt_jq->execute([$equipe_id]);
            $jogo_id = (int)$stmt_jq->fetchColumn();
            if ($jogo_id <= 0) {
                $jogo_id = getJogoAtivoId($pdo);
            }
        }

        $rodada       = getRodadaAtiva($pdo, $jogo_id);
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
// 9. CONTROLE FINANCEIRO — CUSTO DE PRODUÇÃO, CAIXA E FALÊNCIA
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
// 10. ENCERRAMENTO DE RODADA (MOTOR ISOLADO POR JOGO)
// =============================================================================

if (!function_exists('fecharRodadaAtual')) {
    /**
     * Flora Sabão — Encerramento de Rodada (Isolado por Turma/Jogo)
     * =============================================================
     * Executa em transação única e isolada para o $jogo_id especificado:
     *   1.  Processa VENDAS (estoque até a meta de cada produto da equipe)
     *   2.  Credita receita no caixa + registra Kardex
     *   3.  Apura KPIs operacionais (OTIF, Aderência, Refugo, Yield)
     *   4.  Calcula score (operacional + bônus de lucro, máx +400)
     *   5.  Atualiza ranking_rodadas e score_total
     *   6.  Avalia FALÊNCIA (2 rodadas no vermelho = equipe falida)
     *   7.  Encerra a rodada atual DAQUELE JOGO
     *   8.  Abre automaticamente a próxima rodada PARA AQUELE JOGO
     *
     * @param  PDO         $pdo
     * @param  string      $evento_nome
     * @param  string      $evento_desc
     * @param  float|null  $fator_demanda_forcado
     * @param  int|null    $jogo_id
     *
     * @return array{
     *     success: bool,
     *     message: string,
     *     rodada_encerrada?: int,
     *     nova_rodada?: int,
     *     novo_mes?: string,
     *     fator_demanda?: float,
     *     jogo_id?: int
     * }
     */
    function fecharRodadaAtual(
        PDO $pdo,
        string $evento_nome = 'Mercado Estável',
        string $evento_desc = 'Condições normais de mercado.',
        ?float $fator_demanda_forcado = null,
        ?int $jogo_id = null
    ): array {

        $jid = $jogo_id ?? getJogoAtivoId($pdo);

        // 0. Deduz o fator de demanda a partir do nome do evento se não forçado
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

            // 1. LOCK exclusivo da rodada aberta DO JOGO ESPECÍFICO
            $stmt_r = $pdo->prepare("
                SELECT * FROM `jogo_rodadas`
                WHERE `status` = 'aberta' AND (`jogo_id` = ? OR ? = 0)
                ORDER BY `numero_rodada` DESC, `id` DESC
                LIMIT 1
                FOR UPDATE
            ");
            $stmt_r->execute([$jid, $jid]);
            $rodada_atual = $stmt_r->fetch();

            if (!$rodada_atual) {
                throw new Exception(
                    'Nenhuma rodada aberta encontrada para esta turma. ' .
                        'Verifique se a turma está inicializada.'
                );
            }

            $rodada_id    = (int) $rodada_atual['id'];
            $jogo_real_id = (int) ($rodada_atual['jogo_id'] ?? $jid);

            // 2. Carrega APENAS as equipes DESTA TURMA/JOGO (ativas + falidas)
            $stmt_eqs = $pdo->prepare("
                SELECT `id`, `falida`, `caixa_disponivel`
                FROM `equipes`
                WHERE (`ativo` = 1 OR `falida` = 1)
                  AND (`jogo_id` = ? OR ? = 0)
            ");
            $stmt_eqs->execute([$jogo_real_id, $jogo_real_id]);
            $equipes = $stmt_eqs->fetchAll();

            // 3. PROCESSAMENTO POR EQUIPE DESTA TURMA
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
                $demandas          = getDemandasRodada($pdo, $eq_id, $jogo_real_id);
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
                    'total_ops' => 0,
                    'planejado' => 0,
                    'produzido' => 0,
                    'refugo'    => 0,
                    'ops_otif'  => 0,
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
                    $rodada_id,
                    $eq_id,
                    $score,
                    $lucro_rodada,
                    $otif,
                    $taxa_refugo,
                    $penalidades,
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

            // 4. ENCERRA rodada atual DA TURMA
            $pdo->prepare("
                UPDATE `jogo_rodadas`
                SET `status` = 'encerrada'
                WHERE `id` = ?
            ")->execute([$rodada_id]);

            // 5. ABRE próxima rodada VINCULADA AO JOGO
            $nova_num = (int) $rodada_atual['numero_rodada'] + 1;
            $novo_mes = 'Mês ' . str_pad((string) $nova_num, 2, '0', STR_PAD_LEFT);

            $pdo->prepare("
                INSERT INTO `jogo_rodadas`
                    (`jogo_id`, `numero_rodada`, `mes_ano`, `status`,
                     `evento_titulo`, `evento_descricao`, `fator_demanda`)
                VALUES (?, ?, ?, 'aberta', ?, ?, ?)
            ")->execute([$jogo_real_id, $nova_num, $novo_mes, $evento_nome, $evento_desc, $fator_demanda]);

            // 6. COMMIT
            $pdo->commit();

            return [
                'success'          => true,
                'rodada_encerrada' => (int) $rodada_atual['numero_rodada'],
                'nova_rodada'      => $nova_num,
                'novo_mes'         => $novo_mes,
                'fator_demanda'    => $fator_demanda,
                'jogo_id'          => $jogo_real_id,
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
// 11. UTILITÁRIOS COMPLEMENTARES (datas, números, debug)
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

// ==============================================================================
// 12. AUTO-INICIALIZADOR DO SISTEMA (EXECUTADO PELO LOGIN AUTOMATICAMENTE)
// ==============================================================================

if (!function_exists('garantirAmbienteInicializado')) {
    /**
     * Verifica e cria silenciosamente a infraestrutura do sistema ao acessar o login:
     * - Pastas obrigatórias
     * - Tabelas do banco de dados (Multi-Game)
     * - Usuário Instrutor mestre
     * - Primeira turma de demonstração (se o banco estiver vazio)
     * 
     * Executa em ~1ms se o ambiente já estiver pronto.
     */
    function garantirAmbienteInicializado(PDO $pdo): void
    {
        // 1. Verificação de Pastas Obrigatórias
        $raiz = dirname(__DIR__);
        $pastasObrigatorias = [
            $raiz . '/api',
            $raiz . '/assets',
            $raiz . '/assets/css',
            $raiz . '/assets/js',
            $raiz . '/config',
            $raiz . '/includes',
            $raiz . '/pages',
            $raiz . '/sql',
        ];
        foreach ($pastasObrigatorias as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
        }

        // 2. Verificação Rápida de Tabelas Existentes
        $stmt = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()");
        $tabelasExistentes = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $tabelasNecessarias = [
            'jogos',
            'jogo_rodadas',
            'equipes',
            'usuarios',
            'produtos',
            'insumos',
            'ficha_tecnica',
            'ordens_producao',
            'mov_estoque',
            'ranking_rodadas'
        ];

        $faltando = array_diff($tabelasNecessarias, $tabelasExistentes);

        // Se NÃO falta nenhuma tabela, faz o check super rápido de dados e encerra
        if (empty($faltando)) {
            $stmt_adm = $pdo->query("SELECT id FROM usuarios WHERE email = 'admin@flora.com' LIMIT 1");
            $adm_id = $stmt_adm->fetchColumn();

            $stmt_jg = $pdo->query("SELECT id FROM jogos LIMIT 1");
            $tem_jg = $stmt_jg->fetchColumn();

            if ($adm_id && $tem_jg) {
                return; // ⚡ TUDO PRONTO! Retorno instantâneo sem custo de processamento.
            }
        }

        // 3. Criação das Tabelas Faltantes (Estrutura Multi-Game)
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `jogos` (
                `id`             INT AUTO_INCREMENT PRIMARY KEY,
                `instrutor_id`   INT NOT NULL,
                `pin_jogo`       VARCHAR(15) NOT NULL UNIQUE,
                `nome_turma`     VARCHAR(100) NOT NULL,
                `instituicao`    VARCHAR(100) NULL,
                `qtd_equipes`    INT NOT NULL DEFAULT 6,
                `caixa_inicial`  DECIMAL(12,2) NOT NULL DEFAULT 15000.00,
                `status`         ENUM('ativo','pausado','encerrado') NOT NULL DEFAULT 'ativo',
                `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `jogo_rodadas` (
                `id`               INT AUTO_INCREMENT PRIMARY KEY,
                `jogo_id`          INT NOT NULL,
                `numero_rodada`    INT NOT NULL DEFAULT 1,
                `mes_ano`          VARCHAR(20) NOT NULL DEFAULT 'Mês 01',
                `status`           ENUM('aberta','processando','encerrada') NOT NULL DEFAULT 'aberta',
                `evento_titulo`    VARCHAR(100) NOT NULL DEFAULT 'Mercado Estável',
                `evento_descricao` TEXT NULL,
                `fator_demanda`    DECIMAL(4,2) NOT NULL DEFAULT 1.00,
                `created_at`       DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `equipes` (
                `id`                  INT AUTO_INCREMENT PRIMARY KEY,
                `jogo_id`             INT NOT NULL,
                `nome`                VARCHAR(60) NOT NULL,
                `codigo_acesso`       VARCHAR(40) NOT NULL UNIQUE,
                `cor_hex`             VARCHAR(10) NOT NULL DEFAULT '#2d5a3f',
                `caixa_disponivel`    DECIMAL(12,2) NOT NULL DEFAULT 15000.00,
                `score_total`         INT NOT NULL DEFAULT 0,
                `tacho_ultimo_aroma`  VARCHAR(10) NOT NULL DEFAULT 'LIMPO',
                `tacho_higienizado`   TINYINT(1) NOT NULL DEFAULT 1,
                `rodadas_no_vermelho` INT NOT NULL DEFAULT 0,
                `falida`              TINYINT(1) NOT NULL DEFAULT 0,
                `data_falencia`       DATETIME NULL,
                `ativo`               TINYINT(1) NOT NULL DEFAULT 1,
                `created_at`          DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `usuarios` (
                `id`         INT AUTO_INCREMENT PRIMARY KEY,
                `equipe_id`  INT NULL,
                `nome`       VARCHAR(80) NOT NULL,
                `email`      VARCHAR(100) NOT NULL UNIQUE,
                `senha`      VARCHAR(255) NOT NULL,
                `perfil`     ENUM('admin','pcp') NOT NULL DEFAULT 'pcp',
                `ativo`      TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `produtos` (
                `id`                INT AUTO_INCREMENT PRIMARY KEY,
                `equipe_id`         INT NOT NULL,
                `codigo`            VARCHAR(10) NOT NULL,
                `nome`              VARCHAR(60) NOT NULL,
                `aroma`             VARCHAR(60) NOT NULL,
                `peso_barra_g`      DECIMAL(6,2) NOT NULL DEFAULT 100.00,
                `intensidade_aroma` ENUM('suave','moderado','intenso') NOT NULL DEFAULT 'moderado',
                `estoque_atual`     INT NOT NULL DEFAULT 150,
                `estoque_minimo`    INT NOT NULL DEFAULT 50,
                `preco_venda`       DECIMAL(10,2) NOT NULL DEFAULT 15.00,
                `ativo`             TINYINT(1) NOT NULL DEFAULT 1,
                UNIQUE KEY `uk_eq_prod` (`equipe_id`, `codigo`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `insumos` (
                `id`             INT AUTO_INCREMENT PRIMARY KEY,
                `equipe_id`      INT NOT NULL,
                `codigo`         VARCHAR(15) NOT NULL,
                `nome`           VARCHAR(80) NOT NULL,
                `unidade`        VARCHAR(10) NOT NULL,
                `estoque_atual`  DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
                `estoque_minimo` DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
                `custo_unitario` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                UNIQUE KEY `uk_eq_insumo` (`equipe_id`, `codigo`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `ficha_tecnica` (
                `id`            INT AUTO_INCREMENT PRIMARY KEY,
                `equipe_id`     INT NOT NULL,
                `produto_id`    INT NOT NULL,
                `insumo_id`     INT NOT NULL,
                `qtd_por_barra` DECIMAL(10,4) NOT NULL,
                UNIQUE KEY `uk_bom` (`equipe_id`, `produto_id`, `insumo_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `ordens_producao` (
                `id`                   INT AUTO_INCREMENT PRIMARY KEY,
                `equipe_id`            INT NOT NULL,
                `rodada_id`            INT NOT NULL,
                `numero`               VARCHAR(30) NOT NULL,
                `produto_id`           INT NOT NULL,
                `quantidade_planejada` INT NOT NULL,
                `quantidade_produzida` INT NOT NULL DEFAULT 0,
                `quantidade_refugo`    INT NOT NULL DEFAULT 0,
                `data_programada`      DATE NOT NULL,
                `data_inicio`          DATETIME NULL,
                `data_finalizacao`     DATETIME NULL,
                `status`               ENUM('planejada','em_producao','finalizada','cancelada') NOT NULL DEFAULT 'planejada',
                `observacoes`          TEXT NULL,
                `created_at`           DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_eq_op` (`equipe_id`, `numero`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `mov_estoque` (
                `id`         INT AUTO_INCREMENT PRIMARY KEY,
                `equipe_id`  INT NOT NULL,
                `tipo`       ENUM('entrada','saida') NOT NULL,
                `tipo_item`  ENUM('insumo','produto') NOT NULL,
                `insumo_id`  INT NULL,
                `produto_id` INT NULL,
                `quantidade` DECIMAL(10,4) NOT NULL,
                `op_id`      INT NULL,
                `data_mov`   DATETIME DEFAULT CURRENT_TIMESTAMP,
                `motivo`     VARCHAR(120) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `ranking_rodadas` (
                `id`                INT AUTO_INCREMENT PRIMARY KEY,
                `rodada_id`         INT NOT NULL,
                `equipe_id`         INT NOT NULL,
                `score_rodada`      INT NOT NULL DEFAULT 0,
                `lucro_liquido`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `otif_perc`         DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
                `taxa_refugo`       DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
                `tempo_setup_total` INT NOT NULL DEFAULT 0,
                `penalidades`       INT NOT NULL DEFAULT 0,
                `created_at`        DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_rk_rodada_eq` (`rodada_id`, `equipe_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

        // 4. Cria o Usuário Instrutor se não existir
        $stmt_adm = $pdo->prepare("SELECT id FROM usuarios WHERE email = 'admin@flora.com' LIMIT 1");
        $stmt_adm->execute();
        $admin_id = (int)$stmt_adm->fetchColumn();

        if ($admin_id <= 0) {
            $senhaPadraoHash = password_hash('123456', PASSWORD_BCRYPT);
            $stmt_ins_adm = $pdo->prepare("
                INSERT INTO usuarios (equipe_id, nome, email, senha, perfil)
                VALUES (NULL, 'Instrutor do Jogo', 'admin@flora.com', ?, 'admin')
            ");
            $stmt_ins_adm->execute([$senhaPadraoHash]);
            $admin_id = (int)$pdo->lastInsertId();
        }

        // 5. Cria uma Turma de Demonstração se nenhuma existir
        $stmt_jg = $pdo->query("SELECT id FROM jogos LIMIT 1");
        if (!$stmt_jg->fetchColumn()) {
            criarNovoJogo($pdo, $admin_id, 'Turma Padrão (Demonstração)', 'TURMA-01', 6, 15000.00);
        }
    }
}
