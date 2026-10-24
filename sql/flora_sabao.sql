-- ==============================================================================
-- FLORA SABAO - SIMULADOR PCP & BUSINESS GAME
-- Versao 3.0 - Multiplas Turmas/Jogos (PIN) + Falencia por Equipe
-- ------------------------------------------------------------------------------
-- Mudancas desta versao (tudo em CREATE TABLE, sem ALTER TABLE):
--   * Nova tabela `jogos` (turmas com PIN, instrutor responsavel, qtd de equipes)
--   * `equipes` agora tem `jogo_id` (FK para jogos) e nome unico DENTRO do jogo
--   * `jogo_rodadas` agora tem `jogo_id` (FK para jogos)
--   * Insercoes de dados reordenadas para respeitar a dependencia circular
--     usuarios <-> jogos <-> equipes
-- ==============================================================================

DROP DATABASE IF EXISTS `flora_sabao`;
CREATE DATABASE `flora_sabao`
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE `flora_sabao`;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------------------
-- 1. USUARIOS
--    Criada primeiro para permitir que `jogos.instrutor_id` referencie esta tabela.
--    A FK `usuarios.equipe_id -> equipes.id` e declarada aqui mesmo com
--    `equipes` ainda nao existindo: FOREIGN_KEY_CHECKS=0 permite a referencia
--    ser aceita e a constraint e validada quando a tabela `equipes` for criada.
-- ------------------------------------------------------------------------------
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

-- ------------------------------------------------------------------------------
-- 2. JOGOS / TURMAS
--    Cada instrutor pode ter varias turmas. O PIN e o identificador curto
--    usado para alunos entrarem na sessao correta.
-- ------------------------------------------------------------------------------
CREATE TABLE `jogos` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `instrutor_id`   INT NOT NULL,
    `pin_jogo`       VARCHAR(15) NOT NULL UNIQUE,
    `nome_turma`     VARCHAR(100) NOT NULL,
    `instituicao`    VARCHAR(100) NULL,
    `qtd_equipes`    INT NOT NULL DEFAULT 6,
    `caixa_inicial`  DECIMAL(12,2) NOT NULL DEFAULT 15000.00,
    `status`         ENUM('ativo','pausado','encerrado') NOT NULL DEFAULT 'ativo',
    `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`instrutor_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------------------------
-- 3. EQUIPES
--    * `jogo_id` vincula a equipe a uma turma especifica
--    * `nome` UNIQUE DENTRO DO JOGO (duas turmas podem ter "Equipe Alfa")
--    * `codigo_acesso` permanece UNIQUE GLOBAL (usado sozinho no login)
-- ------------------------------------------------------------------------------
CREATE TABLE `equipes` (
    `id`                  INT AUTO_INCREMENT PRIMARY KEY,
    `jogo_id`             INT NOT NULL,
    `nome`                VARCHAR(60) NOT NULL,
    `codigo_acesso`       VARCHAR(40) NOT NULL UNIQUE,
    `cor_hex`             VARCHAR(10) NOT NULL DEFAULT '#2d5a3f',
    `caixa_disponivel`    DECIMAL(12,2) NOT NULL DEFAULT 15000.00,
    `score_total`         INT NOT NULL DEFAULT 0,
    `tacho_ultimo_aroma`  VARCHAR(10) NOT NULL DEFAULT 'LAV',
    `tacho_higienizado`   TINYINT(1) NOT NULL DEFAULT 1,
    `rodadas_no_vermelho` INT NOT NULL DEFAULT 0,
    `falida`              TINYINT(1) NOT NULL DEFAULT 0,
    `data_falencia`       DATETIME NULL,
    `ativo`               TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`          DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`jogo_id`) REFERENCES `jogos`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uk_jogo_nome` (`jogo_id`, `nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------------------------
-- 4. RODADAS
--    Cada rodada pertence a um jogo especifico.
-- ------------------------------------------------------------------------------
CREATE TABLE `jogo_rodadas` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `jogo_id`          INT NOT NULL,
    `numero_rodada`    INT NOT NULL DEFAULT 1,
    `mes_ano`          VARCHAR(20) NOT NULL DEFAULT 'Mês 01',
    `status`           ENUM('aberta','processando','encerrada') NOT NULL DEFAULT 'aberta',
    `evento_titulo`    VARCHAR(100) NOT NULL DEFAULT 'Mercado Estavel',
    `evento_descricao` TEXT NULL,
    `fator_demanda`    DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    `created_at`       DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`jogo_id`) REFERENCES `jogos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------------------------
-- 5. PRODUTOS ACABADOS (por equipe, ja existente)
-- ------------------------------------------------------------------------------
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

-- ------------------------------------------------------------------------------
-- 6. INSUMOS (por equipe)
-- ------------------------------------------------------------------------------
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

-- ------------------------------------------------------------------------------
-- 7. FICHA TECNICA (BOM)
-- ------------------------------------------------------------------------------
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

-- ------------------------------------------------------------------------------
-- 8. ORDENS DE PRODUCAO
-- ------------------------------------------------------------------------------
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
    `status`               ENUM('planejada','em_producao','finalizada','cancelada')
                           NOT NULL DEFAULT 'planejada',
    `observacoes`          TEXT NULL,
    `created_at`           DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`equipe_id`)  REFERENCES `equipes`(`id`)       ON DELETE CASCADE,
    FOREIGN KEY (`rodada_id`)  REFERENCES `jogo_rodadas`(`id`),
    FOREIGN KEY (`produto_id`) REFERENCES `produtos`(`id`),
    UNIQUE KEY `uk_eq_op` (`equipe_id`, `numero`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------------------------
-- 9. KARDEX / MOVIMENTACAO DE ESTOQUE
-- ------------------------------------------------------------------------------
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

-- ------------------------------------------------------------------------------
-- 10. RANKING POR RODADA
-- ------------------------------------------------------------------------------
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

SET FOREIGN_KEY_CHECKS = 1;

-- ==============================================================================
-- CARGA INICIAL
-- ------------------------------------------------------------------------------
-- Ordem obrigatoria por causa da dependencia circular:
--   1) Instrutor (equipe_id NULL)     — nao depende de equipes
--   2) Jogo/Turma (instrutor_id = 1)  — depende do instrutor
--   3) Equipes (jogo_id = 1)          — depende do jogo
--   4) Alunos (equipe_id = 1..6)      — dependem das equipes
--   5) Rodada 1 (jogo_id = 1)         — depende do jogo
--   6) Catalogo (produtos/insumos/BOM) — dependem das equipes
--   7) Ranking inicial                 — depende de rodada + equipe
-- ==============================================================================

-- ------------------------------------------------------------------
-- 1) INSTRUTOR (ADMIN) — precisa vir ANTES do jogo
--    Senha padrao: 123456
-- ------------------------------------------------------------------
INSERT INTO `usuarios` (`id`, `equipe_id`, `nome`, `email`, `senha`, `perfil`)
VALUES
    (1, NULL, 'Instrutor do Jogo', 'admin@flora.com',
     '$2y$10$uDnEpQpkh9RO5tWwOrHtneLAuYMRDstRaKGZFyHubObDR0789OMO6',
     'admin');

-- ------------------------------------------------------------------
-- 2) JOGO / TURMA PADRAO
-- ------------------------------------------------------------------
INSERT INTO `jogos`
    (`id`, `instrutor_id`, `pin_jogo`, `nome_turma`, `instituicao`,
     `qtd_equipes`, `caixa_inicial`, `status`)
VALUES
    (1, 1, 'TURMA-01', 'Turma Padrao (Demonstracao)',
     'Instituicao de Ensino', 6, 15000.00, 'ativo');

-- ------------------------------------------------------------------
-- 3) 6 EQUIPES DA TURMA PADRAO
-- ------------------------------------------------------------------
INSERT INTO `equipes`
    (`id`, `jogo_id`, `nome`, `codigo_acesso`, `cor_hex`,
     `caixa_disponivel`, `score_total`, `tacho_ultimo_aroma`, `tacho_higienizado`)
VALUES
    (1, 1, 'Equipe Alfa (Verde)',    'ALFA-7A4B',    '#2d5a3f', 15000.00, 0, 'LAV', 1),
    (2, 1, 'Equipe Beta (Azul)',     'BETA-C8D2',    '#1e5084', 15000.00, 0, 'LAV', 1),
    (3, 1, 'Equipe Gama (Laranja)',  'GAMA-3E9F',    '#b85d19', 15000.00, 0, 'LAV', 1),
    (4, 1, 'Equipe Delta (Roxo)',    'DELTA-A1B5',   '#6b2d84', 15000.00, 0, 'LAV', 1),
    (5, 1, 'Equipe Epsilon (Vinho)', 'EPSILON-6C7D', '#841e3d', 15000.00, 0, 'LAV', 1),
    (6, 1, 'Equipe Omega (Grafite)', 'OMEGA-F2E4',   '#3d454a', 15000.00, 0, 'LAV', 1);

-- ------------------------------------------------------------------
-- 4) USUARIOS DAS EQUIPES (alunos) — dependem das equipes
-- ------------------------------------------------------------------
INSERT INTO `usuarios` (`equipe_id`, `nome`, `email`, `senha`, `perfil`) VALUES
    (1, 'Equipe Alfa',    'equipe1@flora.com', '$2y$10$uDnEpQpkh9RO5tWwOrHtneLAuYMRDstRaKGZFyHubObDR0789OMO6', 'pcp'),
    (2, 'Equipe Beta',    'equipe2@flora.com', '$2y$10$uDnEpQpkh9RO5tWwOrHtneLAuYMRDstRaKGZFyHubObDR0789OMO6', 'pcp'),
    (3, 'Equipe Gama',    'equipe3@flora.com', '$2y$10$uDnEpQpkh9RO5tWwOrHtneLAuYMRDstRaKGZFyHubObDR0789OMO6', 'pcp'),
    (4, 'Equipe Delta',   'equipe4@flora.com', '$2y$10$uDnEpQpkh9RO5tWwOrHtneLAuYMRDstRaKGZFyHubObDR0789OMO6', 'pcp'),
    (5, 'Equipe Epsilon', 'equipe5@flora.com', '$2y$10$uDnEpQpkh9RO5tWwOrHtneLAuYMRDstRaKGZFyHubObDR0789OMO6', 'pcp'),
    (6, 'Equipe Omega',   'equipe6@flora.com', '$2y$10$uDnEpQpkh9RO5tWwOrHtneLAuYMRDstRaKGZFyHubObDR0789OMO6', 'pcp');

-- ------------------------------------------------------------------
-- 5) RODADA 1 DA TURMA PADRAO
-- ------------------------------------------------------------------
INSERT INTO `jogo_rodadas`
    (`id`, `jogo_id`, `numero_rodada`, `mes_ano`, `status`,
     `evento_titulo`, `evento_descricao`, `fator_demanda`)
VALUES
    (1, 1, 1, 'Mês 01', 'aberta',
     'Rodada de Aquecimento',
     'Mercado padrao sem distorcoes. Familiarizem-se com a Ficha Tecnica e o Sequenciador.',
     1.00);

-- ------------------------------------------------------------------
-- 6) CATALOGO BASE DE PRODUTOS (4 SKUs por equipe)
-- ------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS `tmp_base_produtos`;
CREATE TEMPORARY TABLE `tmp_base_produtos` (
    `codigo` VARCHAR(10), `nome` VARCHAR(60), `aroma` VARCHAR(60),
    `intensidade` ENUM('suave','moderado','intenso'), `preco` DECIMAL(10,2)
);
INSERT INTO `tmp_base_produtos` VALUES
    ('LAV','Sabonete Lavanda Francesa','Lavanda','suave',16.50),
    ('ROS','Sabonete Rosas Nobres','Rosas','moderado',16.50),
    ('PIT','Sabonete Pitanga Tropical','Pitanga','moderado',15.00),
    ('MAR','Sabonete Maracuja Citrico','Maracuja','intenso',15.00);

INSERT INTO `produtos`
    (`equipe_id`, `codigo`, `nome`, `aroma`, `peso_barra_g`,
     `intensidade_aroma`, `estoque_atual`, `estoque_minimo`, `preco_venda`)
SELECT e.id, bp.codigo, bp.nome, bp.aroma, 100.00, bp.intensidade, 150, 50, bp.preco
FROM `equipes` e CROSS JOIN `tmp_base_produtos` bp;

-- ------------------------------------------------------------------
-- 7) CATALOGO BASE DE INSUMOS (9 itens por equipe)
-- ------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS `tmp_base_insumos`;
CREATE TEMPORARY TABLE `tmp_base_insumos` (
    `codigo` VARCHAR(15), `nome` VARCHAR(80), `unidade` VARCHAR(10),
    `est_ini` DECIMAL(10,4), `est_min` DECIMAL(10,4), `custo` DECIMAL(10,2)
);
INSERT INTO `tmp_base_insumos` VALUES
    ('BASE-GLIC','Base Glicerinada Vegetal','kg',120.0000,30.0000,24.50),
    ('EXT-ALOE','Extrato Glicolico Aloe Vera','L',5.0000,1.5000,68.00),
    ('ESS-LAV','Essencia Lavanda (Suave)','L',1.2000,0.8000,110.00),
    ('ESS-ROS','Essencia Rosas (Moderada)','L',0.6000,0.8000,125.00),
    ('ESS-PIT','Essencia Pitanga (Moderada)','L',2.0000,0.8000,95.00),
    ('ESS-MAR','Essencia Maracuja (Intensa)','L',2.5000,0.8000,92.00),
    ('LAURIL','Lauril Eter Sulfato (Espumante)','L',4.0000,1.0000,18.00),
    ('EMB-KRAFT','Cinta Papel Kraft','un',800.0000,200.0000,0.45),
    ('CELOF-BIO','Película Biodegradavel','un',850.0000,200.0000,0.20);

INSERT INTO `insumos`
    (`equipe_id`, `codigo`, `nome`, `unidade`, `estoque_atual`, `estoque_minimo`, `custo_unitario`)
SELECT e.id, bi.codigo, bi.nome, bi.unidade, bi.est_ini, bi.est_min, bi.custo
FROM `equipes` e CROSS JOIN `tmp_base_insumos` bi;

DROP TEMPORARY TABLE `tmp_base_produtos`;
DROP TEMPORARY TABLE `tmp_base_insumos`;

-- ------------------------------------------------------------------
-- 8) FICHA TECNICA (BOM) — itens comuns
-- ------------------------------------------------------------------
INSERT INTO `ficha_tecnica` (`equipe_id`, `produto_id`, `insumo_id`, `qtd_por_barra`)
SELECT p.equipe_id, p.id, i.id, 0.0920 FROM `produtos` p
JOIN `insumos` i ON p.equipe_id = i.equipe_id WHERE i.codigo = 'BASE-GLIC';

INSERT INTO `ficha_tecnica` (`equipe_id`, `produto_id`, `insumo_id`, `qtd_por_barra`)
SELECT p.equipe_id, p.id, i.id, 0.0020 FROM `produtos` p
JOIN `insumos` i ON p.equipe_id = i.equipe_id WHERE i.codigo = 'EXT-ALOE';

INSERT INTO `ficha_tecnica` (`equipe_id`, `produto_id`, `insumo_id`, `qtd_por_barra`)
SELECT p.equipe_id, p.id, i.id, 0.0020 FROM `produtos` p
JOIN `insumos` i ON p.equipe_id = i.equipe_id WHERE i.codigo = 'LAURIL';

INSERT INTO `ficha_tecnica` (`equipe_id`, `produto_id`, `insumo_id`, `qtd_por_barra`)
SELECT p.equipe_id, p.id, i.id, 1.0000 FROM `produtos` p
JOIN `insumos` i ON p.equipe_id = i.equipe_id WHERE i.codigo = 'EMB-KRAFT';

INSERT INTO `ficha_tecnica` (`equipe_id`, `produto_id`, `insumo_id`, `qtd_por_barra`)
SELECT p.equipe_id, p.id, i.id, 1.0000 FROM `produtos` p
JOIN `insumos` i ON p.equipe_id = i.equipe_id WHERE i.codigo = 'CELOF-BIO';

-- 9) FICHA TECNICA — essencia especifica por SKU
INSERT INTO `ficha_tecnica` (`equipe_id`, `produto_id`, `insumo_id`, `qtd_por_barra`)
SELECT p.equipe_id, p.id, i.id, 0.0040 FROM `produtos` p
JOIN `insumos` i ON p.equipe_id = i.equipe_id
WHERE p.codigo = 'LAV' AND i.codigo = 'ESS-LAV';

INSERT INTO `ficha_tecnica` (`equipe_id`, `produto_id`, `insumo_id`, `qtd_por_barra`)
SELECT p.equipe_id, p.id, i.id, 0.0040 FROM `produtos` p
JOIN `insumos` i ON p.equipe_id = i.equipe_id
WHERE p.codigo = 'ROS' AND i.codigo = 'ESS-ROS';

INSERT INTO `ficha_tecnica` (`equipe_id`, `produto_id`, `insumo_id`, `qtd_por_barra`)
SELECT p.equipe_id, p.id, i.id, 0.0040 FROM `produtos` p
JOIN `insumos` i ON p.equipe_id = i.equipe_id
WHERE p.codigo = 'PIT' AND i.codigo = 'ESS-PIT';

INSERT INTO `ficha_tecnica` (`equipe_id`, `produto_id`, `insumo_id`, `qtd_por_barra`)
SELECT p.equipe_id, p.id, i.id, 0.0040 FROM `produtos` p
JOIN `insumos` i ON p.equipe_id = i.equipe_id
WHERE p.codigo = 'MAR' AND i.codigo = 'ESS-MAR';

-- ------------------------------------------------------------------
-- 10) RANKING INICIAL ZERADO
-- ------------------------------------------------------------------
INSERT INTO `ranking_rodadas` (`rodada_id`, `equipe_id`, `score_rodada`)
SELECT 1, id, 0 FROM `equipes` WHERE jogo_id = 1;

-- ==============================================================================
-- VERIFICACAO
-- ==============================================================================
SELECT 'jogos'          AS tabela, COUNT(*) AS total FROM `jogos`
UNION ALL SELECT 'equipes',          COUNT(*) FROM `equipes`
UNION ALL SELECT 'usuarios',         COUNT(*) FROM `usuarios`
UNION ALL SELECT 'jogo_rodadas',     COUNT(*) FROM `jogo_rodadas`
UNION ALL SELECT 'produtos',         COUNT(*) FROM `produtos`
UNION ALL SELECT 'insumos',          COUNT(*) FROM `insumos`
UNION ALL SELECT 'ficha_tecnica',    COUNT(*) FROM `ficha_tecnica`
UNION ALL SELECT 'ranking_rodadas',  COUNT(*) FROM `ranking_rodadas`;

-- Contagens esperadas:
--   jogos           = 1
--   equipes         = 6
--   usuarios        = 7  (1 admin + 6 alunos)
--   jogo_rodadas    = 1
--   produtos        = 24 (4 SKUs x 6 equipes)
--   insumos         = 54 (9 itens x 6 equipes)
--   ficha_tecnica   = 144 (6 itens x 4 SKUs x 6 equipes)
--   ranking_rodadas = 6