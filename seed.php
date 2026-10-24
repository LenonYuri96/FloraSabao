<?php
/**
 * Flora Sabão — Seed & Auto-Instalador do Banco de Dados
 * ==============================================================================
 * Executável via Navegador (http://localhost/seed.php) ou via Terminal (php seed.php).
 * Cria o banco, as tabelas com suporte Multi-Turma, o instrutor e uma turma pronta para jogar.
 */

declare(strict_types=1);

// Configurações de Conexão com o MySQL
$db_host = 'localhost';
$db_name = 'flora_sabao';
$db_user = 'root';
$db_pass = ''; // Altere se o seu MySQL local tiver senha
$db_port = 3306;

$is_cli = (php_sapi_name() === 'cli');
$log = [];

function registrarLog(string $msg, string $tipo = 'info'): void {
    global $log, $is_cli;
    $log[] = ['msg' => $msg, 'tipo' => $tipo];
    if ($is_cli) {
        $prefix = match ($tipo) {
            'sucesso' => "[OK] ",
            'erro'    => "[ERRO] ",
            'aviso'   => "[AVISO] ",
            default   => "[INFO] ",
        };
        echo $prefix . strip_tags($msg) . PHP_EOL;
    }
}

try {
    // --------------------------------------------------------------------------
    // 1. CONEXÃO COM O SERVIDOR MYSQL (Sem banco selecionado para poder criá-lo)
    // --------------------------------------------------------------------------
    $dsn_root = "mysql:host={$db_host};port={$db_port};charset=utf8mb4";
    $pdo_root = new PDO($dsn_root, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo_root->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    registrarLog("Banco de dados <code>{$db_name}</code> verificado/criado com sucesso.", "sucesso");

    // Conecta diretamente ao banco flora_sabao
    $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // --------------------------------------------------------------------------
    // 2. ESTRUTURA DAS TABELAS (MULTI-GAME / MULTI-TURMA)
    // --------------------------------------------------------------------------
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

    $tabelas = [
        "DROP TABLE IF EXISTS `ranking_rodadas`;",
        "DROP TABLE IF EXISTS `mov_estoque`;",
        "DROP TABLE IF EXISTS `ordens_producao`;",
        "DROP TABLE IF EXISTS `ficha_tecnica`;",
        "DROP TABLE IF EXISTS `insumos`;",
        "DROP TABLE IF EXISTS `produtos`;",
        "DROP TABLE IF EXISTS `usuarios`;",
        "DROP TABLE IF EXISTS `equipes`;",
        "DROP TABLE IF EXISTS `jogo_rodadas`;",
        "DROP TABLE IF EXISTS `jogos`;",
    ];
    foreach ($tabelas as $sqlDrop) {
        $pdo->exec($sqlDrop);
    }

    // Tabela 1: JOGOS / TURMAS
    $pdo->exec("
        CREATE TABLE `jogos` (
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

    // Tabela 2: RODADAS (Vinculada ao jogo)
    $pdo->exec("
        CREATE TABLE `jogo_rodadas` (
            `id`               INT AUTO_INCREMENT PRIMARY KEY,
            `jogo_id`          INT NOT NULL,
            `numero_rodada`    INT NOT NULL DEFAULT 1,
            `mes_ano`          VARCHAR(20) NOT NULL DEFAULT 'Mês 01',
            `status`           ENUM('aberta','processando','encerrada') NOT NULL DEFAULT 'aberta',
            `evento_titulo`    VARCHAR(100) NOT NULL DEFAULT 'Mercado Estável',
            `evento_descricao` TEXT NULL,
            `fator_demanda`    DECIMAL(4,2) NOT NULL DEFAULT 1.00,
            `created_at`       DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`jogo_id`) REFERENCES `jogos`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Tabela 3: EQUIPES (Vinculada ao jogo)
    $pdo->exec("
        CREATE TABLE `equipes` (
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
            `created_at`          DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`jogo_id`) REFERENCES `jogos`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Tabela 4: USUARIOS
    $pdo->exec("
        CREATE TABLE `usuarios` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `equipe_id`  INT NULL,
            `nome`       VARCHAR(80) NOT NULL,
            `email`      VARCHAR(100) NOT NULL UNIQUE,
            `senha`      VARCHAR(255) NOT NULL,
            `perfil`     ENUM('admin','pcp') NOT NULL DEFAULT 'pcp',
            `ativo`      TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`equipe_id`) REFERENCES `equipes`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Tabela 5: PRODUTOS ACABADOS
    $pdo->exec("
        CREATE TABLE `produtos` (
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
            FOREIGN KEY (`equipe_id`) REFERENCES `equipes`(`id`) ON DELETE CASCADE,
            UNIQUE KEY `uk_eq_prod` (`equipe_id`, `codigo`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Tabela 6: INSUMOS
    $pdo->exec("
        CREATE TABLE `insumos` (
            `id`             INT AUTO_INCREMENT PRIMARY KEY,
            `equipe_id`      INT NOT NULL,
            `codigo`         VARCHAR(15) NOT NULL,
            `nome`           VARCHAR(80) NOT NULL,
            `unidade`        VARCHAR(10) NOT NULL,
            `estoque_atual`  DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
            `estoque_minimo` DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
            `custo_unitario` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            FOREIGN KEY (`equipe_id`) REFERENCES `equipes`(`id`) ON DELETE CASCADE,
            UNIQUE KEY `uk_eq_insumo` (`equipe_id`, `codigo`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Tabela 7: FICHA TÉCNICA (BOM)
    $pdo->exec("
        CREATE TABLE `ficha_tecnica` (
            `id`            INT AUTO_INCREMENT PRIMARY KEY,
            `equipe_id`     INT NOT NULL,
            `produto_id`    INT NOT NULL,
            `insumo_id`     INT NOT NULL,
            `qtd_por_barra` DECIMAL(10,4) NOT NULL,
            FOREIGN KEY (`equipe_id`)  REFERENCES `equipes`(`id`)  ON DELETE CASCADE,
            FOREIGN KEY (`produto_id`) REFERENCES `produtos`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`insumo_id`)  REFERENCES `insumos`(`id`)  ON DELETE CASCADE,
            UNIQUE KEY `uk_bom` (`equipe_id`, `produto_id`, `insumo_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Tabela 8: ORDENS DE PRODUÇÃO
    $pdo->exec("
        CREATE TABLE `ordens_producao` (
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
            FOREIGN KEY (`equipe_id`)  REFERENCES `equipes`(`id`)       ON DELETE CASCADE,
            FOREIGN KEY (`rodada_id`)  REFERENCES `jogo_rodadas`(`id`),
            FOREIGN KEY (`produto_id`) REFERENCES `produtos`(`id`),
            UNIQUE KEY `uk_eq_op` (`equipe_id`, `numero`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Tabela 9: KARDEX / MOVIMENTAÇÃO DE ESTOQUE
    $pdo->exec("
        CREATE TABLE `mov_estoque` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `equipe_id`  INT NOT NULL,
            `tipo`       ENUM('entrada','saida') NOT NULL,
            `tipo_item`  ENUM('insumo','produto') NOT NULL,
            `insumo_id`  INT NULL,
            `produto_id` INT NULL,
            `quantidade` DECIMAL(10,4) NOT NULL,
            `op_id`      INT NULL,
            `data_mov`   DATETIME DEFAULT CURRENT_TIMESTAMP,
            `motivo`     VARCHAR(120) NOT NULL,
            FOREIGN KEY (`equipe_id`)  REFERENCES `equipes`(`id`)         ON DELETE CASCADE,
            FOREIGN KEY (`insumo_id`)  REFERENCES `insumos`(`id`)         ON DELETE SET NULL,
            FOREIGN KEY (`produto_id`) REFERENCES `produtos`(`id`)        ON DELETE SET NULL,
            FOREIGN KEY (`op_id`)      REFERENCES `ordens_producao`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Tabela 10: RANKING POR RODADA
    $pdo->exec("
        CREATE TABLE `ranking_rodadas` (
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
            FOREIGN KEY (`rodada_id`) REFERENCES `jogo_rodadas`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`equipe_id`) REFERENCES `equipes`(`id`)      ON DELETE CASCADE,
            UNIQUE KEY `uk_rk_rodada_eq` (`rodada_id`, `equipe_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    registrarLog("Estrutura de tabelas Multi-Game criada com sucesso.", "sucesso");

    // --------------------------------------------------------------------------
    // 3. SEED DO INSTRUTOR PRINCIPAL (admin@flora.com / 123456)
    // --------------------------------------------------------------------------
    $senhaHash = password_hash('123456', PASSWORD_BCRYPT);
    $stmt_inst = $pdo->prepare("
        INSERT INTO `usuarios` (`equipe_id`, `nome`, `email`, `senha`, `perfil`)
        VALUES (NULL, 'Instrutor do Jogo', 'admin@flora.com', ?, 'admin')
    ");
    $stmt_inst->execute([$senhaHash]);
    $instrutor_id = (int)$pdo->lastInsertId();
    registrarLog("Instrutor mestre cadastrado: <strong>admin@flora.com</strong> (Senha: <code>123456</code>).", "sucesso");

    // --------------------------------------------------------------------------
    // 4. SEED DA TURMA DE DEMONSTRAÇÃO (PIN: TURMA-01)
    // --------------------------------------------------------------------------
    $pin_padrao    = 'TURMA-01';
    $nome_turma    = 'Turma Padrão (Demonstração)';
    $caixa_inicial = 15000.00;

    $stmt_jogo = $pdo->prepare("
        INSERT INTO `jogos` (`instrutor_id`, `pin_jogo`, `nome_turma`, `qtd_equipes`, `caixa_inicial`, `status`)
        VALUES (?, ?, ?, 6, ?, 'ativo')
    ");
    $stmt_jogo->execute([$instrutor_id, $pin_padrao, $nome_turma, $caixa_inicial]);
    $jogo_id = (int)$pdo->lastInsertId();

    // Rodada 1 para esta turma
    $stmt_rod = $pdo->prepare("
        INSERT INTO `jogo_rodadas` (`jogo_id`, `numero_rodada`, `mes_ano`, `status`, `evento_titulo`, `evento_descricao`, `fator_demanda`)
        VALUES (?, 1, 'Mês 01', 'aberta', 'Rodada de Aquecimento', 'Mercado padrão sem distorções. Familiarizem-se com o PCP.', 1.00)
    ");
    $stmt_rod->execute([$jogo_id]);
    $rodada_id = (int)$pdo->lastInsertId();

    // --------------------------------------------------------------------------
    // 5. SEED DAS 6 EQUIPES & CÓDIGOS DE ACESSO
    // --------------------------------------------------------------------------
    $equipes_dados = [
        1 => ['nome' => 'Equipe Alfa (Verde)',    'cor' => '#2d5a3f', 'codigo' => 'TURMA01-ALFA'],
        2 => ['nome' => 'Equipe Beta (Azul)',     'cor' => '#1e5084', 'codigo' => 'TURMA01-BETA'],
        3 => ['nome' => 'Equipe Gama (Laranja)',  'cor' => '#b85d19', 'codigo' => 'TURMA01-GAMA'],
        4 => ['nome' => 'Equipe Delta (Roxo)',    'cor' => '#6b2d84', 'codigo' => 'TURMA01-DELTA'],
        5 => ['nome' => 'Equipe Épsilon (Vinho)', 'cor' => '#841e3d', 'codigo' => 'TURMA01-EPSILON'],
        6 => ['nome' => 'Equipe Ômega (Grafite)', 'cor' => '#3d454a', 'codigo' => 'TURMA01-OMEGA'],
    ];

    // Catálogo Base de Insumos (9 Itens)
    $insumos_catalogo = [
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

    // Catálogo Base de Produtos (4 SKUs)
    $produtos_catalogo = [
        ['MAR', 'Sabonete Maracujá Cítrico', 'Maracujá', 'suave',    15.00, 'ESS-MAR'],
        ['PIT', 'Sabonete Pitanga Tropical', 'Pitanga',  'moderado', 15.00, 'ESS-PIT'],
        ['LAV', 'Sabonete Lavanda Francesa', 'Lavanda',  'moderado', 16.50, 'ESS-LAV'],
        ['ROS', 'Sabonete Rosas Nobres',     'Rosas',    'intenso',  16.50, 'ESS-ROS'],
    ];

    $codigos_gerados = [];

    $stmt_eq = $pdo->prepare("
        INSERT INTO `equipes` (`jogo_id`, `nome`, `codigo_acesso`, `cor_hex`, `caixa_disponivel`, `tacho_ultimo_aroma`, `tacho_higienizado`)
        VALUES (?, ?, ?, ?, ?, 'LIMPO', 1)
    ");

    $stmt_user = $pdo->prepare("
        INSERT INTO `usuarios` (`equipe_id`, `nome`, `email`, `senha`, `perfil`)
        VALUES (?, ?, ?, ?, 'pcp')
    ");

    $stmt_ins = $pdo->prepare("
        INSERT INTO `insumos` (`equipe_id`, `codigo`, `nome`, `unidade`, `estoque_atual`, `estoque_minimo`, `custo_unitario`)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt_prod = $pdo->prepare("
        INSERT INTO `produtos` (`equipe_id`, `codigo`, `nome`, `aroma`, `intensidade_aroma`, `estoque_atual`, `estoque_minimo`, `preco_venda`)
        VALUES (?, ?, ?, ?, ?, 150, 50, ?)
    ");

    $stmt_bom = $pdo->prepare("
        INSERT INTO `ficha_tecnica` (`equipe_id`, `produto_id`, `insumo_id`, `qtd_por_barra`)
        VALUES (?, ?, ?, ?)
    ");

    $stmt_rk = $pdo->prepare("
        INSERT INTO `ranking_rodadas` (`rodada_id`, `equipe_id`, `score_rodada`)
        VALUES (?, ?, 0)
    ");

    foreach ($equipes_dados as $numEq => $eqConf) {
        $stmt_eq->execute([$jogo_id, $eqConf['nome'], $eqConf['codigo'], $eqConf['cor'], $caixa_inicial]);
        $equipe_id = (int)$pdo->lastInsertId();

        $codigos_gerados[] = [
            'nome'   => $eqConf['nome'],
            'cor'    => $eqConf['cor'],
            'codigo' => $eqConf['codigo'],
        ];

        // Usuário padrão da equipe
        $email_eq = "equipe{$numEq}@flora.com";
        $stmt_user->execute([$equipe_id, $eqConf['nome'], $email_eq, $senhaHash]);

        // Insumos da equipe
        $insumo_map = [];
        foreach ($insumos_catalogo as $ins) {
            $stmt_ins->execute([$equipe_id, $ins[0], $ins[1], $ins[2], $ins[3], $ins[4], $ins[5]]);
            $insumo_map[$ins[0]] = (int)$pdo->lastInsertId();
        }

        // Produtos da equipe + Ficha Técnica (BOM)
        foreach ($produtos_catalogo as $prod) {
            $stmt_prod->execute([$equipe_id, $prod[0], $prod[1], $prod[2], $prod[3], $prod[4]]);
            $prod_id = (int)$pdo->lastInsertId();

            // Materiais comuns da barra de sabão
            $stmt_bom->execute([$equipe_id, $prod_id, $insumo_map['BASE-GLIC'], 0.0920]);
            $stmt_bom->execute([$equipe_id, $prod_id, $insumo_map['EXT-ALOE'],  0.0020]);
            $stmt_bom->execute([$equipe_id, $prod_id, $insumo_map['LAURIL'],    0.0020]);
            $stmt_bom->execute([$equipe_id, $prod_id, $insumo_map['EMB-KRAFT'], 1.0000]);
            $stmt_bom->execute([$equipe_id, $prod_id, $insumo_map['CELOF-BIO'], 1.0000]);
            
            // Essência específica da fragrância
            $stmt_bom->execute([$equipe_id, $prod_id, $insumo_map[$prod[5]],    0.0040]);
        }

        // Ranking inicial zerado para a rodada 1
        $stmt_rk->execute([$rodada_id, $equipe_id]);
    }

    registrarLog("Turma de demonstração inicializada com 6 equipes e Fichas Técnicas completas.", "sucesso");

} catch (Throwable $e) {
    registrarLog("Erro na execução: " . $e->getMessage(), "erro");
}

if ($is_cli) {
    exit(0);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador & Seed | Flora Sabão PCP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f7f4; color: #1f2d24; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .card-seed { border-radius: 16px; border: 1px solid #dfe8e2; box-shadow: 0 10px 30px rgba(30,77,53,0.08); }
        .code-pill { font-family: monospace; font-size: 1.05rem; font-weight: 700; letter-spacing: 1px; }
    </style>
</head>
<body class="py-5">
<div class="container" style="max-width: 820px;">
    <div class="card card-seed bg-white p-4 p-md-5">
        <div class="text-center mb-4">
            <i class="bi bi-flower1 text-success display-3"></i>
            <h2 class="fw-bold text-dark mt-2 mb-1">Flora Sabão — Inicializador do Sistema</h2>
            <p class="text-muted small">Instalação e população automática da base de dados com suporte Multi-Turma</p>
        </div>

        <!-- Log de Execução -->
        <h6 class="fw-bold text-uppercase text-muted small mb-2"><i class="bi bi-terminal me-1"></i>Etapas Concluídas:</h6>
        <div class="list-group mb-4">
            <?php foreach ($log as $l): ?>
                <div class="list-group-item d-flex align-items-center gap-2 small">
                    <?php if ($l['tipo'] === 'sucesso'): ?>
                        <i class="bi bi-check-circle-fill text-success fs-5"></i>
                    <?php elseif ($l['tipo'] === 'erro'): ?>
                        <i class="bi bi-x-circle-fill text-danger fs-5"></i>
                    <?php else: ?>
                        <i class="bi bi-info-circle-fill text-primary fs-5"></i>
                    <?php endif; ?>
                    <div><?= $l['msg'] ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Credenciais do Instrutor -->
        <div class="alert alert-success border-0 shadow-sm p-3 mb-4">
            <h5 class="fw-bold text-success mb-2"><i class="bi bi-shield-lock-fill me-2"></i>Acesso do Instrutor (Mestre)</h5>
            <div class="row g-2 small">
                <div class="col-sm-6">E-mail: <strong>admin@flora.com</strong></div>
                <div class="col-sm-6">Senha: <code>123456</code></div>
            </div>
        </div>

        <!-- Códigos de Acesso das Equipes -->
        <h5 class="fw-bold text-dark mb-2"><i class="bi bi-key-fill text-primary me-2"></i>Códigos de Acesso dos Alunos (Turma: TURMA-01)</h5>
        <p class="text-muted small mb-3">Entregue esses códigos aos grupos para que façam login direto na tela inicial:</p>

        <div class="table-responsive mb-4">
            <table class="table table-bordered table-sm align-middle">
                <th