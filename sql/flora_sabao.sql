-- ========================================================
-- Simulador PCP — Flora Sabão
-- Script de Criação e Carga Inicial (Seed)
-- ========================================================
CREATE DATABASE IF NOT EXISTS `flora_sabao` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `flora_sabao`;

-- Desativar verificação de chaves para limpeza limpa
SET
    FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `mov_estoque`;

DROP TABLE IF EXISTS `ordens_producao`;

DROP TABLE IF EXISTS `ficha_tecnica`;

DROP TABLE IF EXISTS `insumos`;

DROP TABLE IF EXISTS `produtos`;

SET
    FOREIGN_KEY_CHECKS = 1;

-- --------------------------------------------------------
-- Tabela: PRODUTOS (Fragrâncias acabadas)
-- --------------------------------------------------------
CREATE TABLE `produtos` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `codigo` VARCHAR(10) NOT NULL UNIQUE,
    `nome` VARCHAR(50) NOT NULL,
    `aroma` VARCHAR(50) NOT NULL,
    `peso_barra_g` DECIMAL(6, 2) NOT NULL DEFAULT 100.00,
    `intensidade_aroma` ENUM('suave', 'moderado', 'intenso') NOT NULL DEFAULT 'moderado',
    `estoque_atual` INT NOT NULL DEFAULT 0,
    `estoque_minimo` INT NOT NULL DEFAULT 50,
    `preco_venda` DECIMAL(10, 2) NOT NULL DEFAULT 14.90,
    `ativo` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- --------------------------------------------------------
-- Tabela: INSUMOS (Matéria-prima e embalagens)
-- --------------------------------------------------------
CREATE TABLE `insumos` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `codigo` VARCHAR(10) NOT NULL UNIQUE,
    `nome` VARCHAR(80) NOT NULL,
    `unidade` VARCHAR(10) NOT NULL,
    -- 'kg', 'L', 'un'
    `estoque_atual` DECIMAL(10, 4) NOT NULL DEFAULT 0.0000,
    `estoque_minimo` DECIMAL(10, 4) NOT NULL DEFAULT 0.0000,
    `custo_unitario` DECIMAL(10, 2) NOT NULL DEFAULT 0.00
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- --------------------------------------------------------
-- Tabela: FICHA TÉCNICA (Bill of Materials - BOM por barra)
-- --------------------------------------------------------
CREATE TABLE `ficha_tecnica` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `produto_id` INT NOT NULL,
    `insumo_id` INT NOT NULL,
    `qtd_por_barra` DECIMAL(10, 4) NOT NULL,
    -- Consumo unitário
    FOREIGN KEY (`produto_id`) REFERENCES `produtos`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`insumo_id`) REFERENCES `insumos`(`id`) ON DELETE RESTRICT,
    UNIQUE KEY `uk_prod_insumo` (`produto_id`, `insumo_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- --------------------------------------------------------
-- Tabela: ORDENS DE PRODUÇÃO (OP)
-- --------------------------------------------------------
CREATE TABLE `ordens_producao` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `numero` VARCHAR(20) NOT NULL UNIQUE,
    `produto_id` INT NOT NULL,
    `quantidade_planejada` INT NOT NULL,
    `quantidade_produzida` INT NOT NULL DEFAULT 0,
    `quantidade_refugo` INT NOT NULL DEFAULT 0,
    `data_programada` DATE NOT NULL,
    `data_inicio` DATETIME NULL,
    `data_finalizacao` DATETIME NULL,
    `status` ENUM(
        'planejada',
        'em_producao',
        'finalizada',
        'cancelada'
    ) NOT NULL DEFAULT 'planejada',
    `observacoes` TEXT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`produto_id`) REFERENCES `produtos`(`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- --------------------------------------------------------
-- Tabela: MOVIMENTAÇÃO DE ESTOQUE
-- --------------------------------------------------------
CREATE TABLE `mov_estoque` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tipo` ENUM('entrada', 'saida') NOT NULL,
    `tipo_item` ENUM('insumo', 'produto') NOT NULL,
    `insumo_id` INT NULL,
    `produto_id` INT NULL,
    `quantidade` DECIMAL(10, 4) NOT NULL,
    `op_id` INT NULL,
    `data_mov` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `motivo` VARCHAR(100) NOT NULL,
    -- 'Produção OP-001', 'Compra NF 123', 'Ajuste inventário'
    FOREIGN KEY (`insumo_id`) REFERENCES `insumos`(`id`),
    FOREIGN KEY (`produto_id`) REFERENCES `produtos`(`id`),
    FOREIGN KEY (`op_id`) REFERENCES `ordens_producao`(`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- ========================================================
-- CARGA INICIAL (SEED)
-- ========================================================
-- 1. Produtos
INSERT INTO
    `produtos` (
        `id`,
        `codigo`,
        `nome`,
        `aroma`,
        `peso_barra_g`,
        `intensidade_aroma`,
        `estoque_atual`,
        `estoque_minimo`,
        `preco_venda`
    )
VALUES
    (
        1,
        'PIT',
        'Sabonete Artesanal Pitanga',
        'Pitanga Tropical',
        100.00,
        'moderado',
        40,
        50,
        15.00
    ),
    (
        2,
        'LAV',
        'Sabonete Artesanal Lavanda',
        'Lavanda Francesa',
        100.00,
        'suave',
        60,
        50,
        16.50
    ),
    (
        3,
        'ROS',
        'Sabonete Artesanal Rosas',
        'Rosas Nobres',
        100.00,
        'moderado',
        20,
        50,
        16.50
    ),
    (
        4,
        'MAR',
        'Sabonete Artesanal Maracujá',
        'Maracujá Cítrico',
        100.00,
        'intenso',
        15,
        50,
        15.00
    );

-- 2. Insumos (Matérias-Primas e Embalagens)
INSERT INTO
    `insumos` (
        `id`,
        `codigo`,
        `nome`,
        `unidade`,
        `estoque_atual`,
        `estoque_minimo`,
        `custo_unitario`
    )
VALUES
    (
        1,
        'BASE-GLIC',
        'Base Glicerinada Vegetal Branca',
        'kg',
        120.0000,
        30.0000,
        24.50
    ),
    (
        2,
        'EXT-ALOE',
        'Extrato Glicólico de Aloe Vera',
        'L',
        5.0000,
        1.5000,
        68.00
    ),
    (
        3,
        'ESS-PIT',
        'Essência Concentrada Pitanga',
        'L',
        2.0000,
        0.8000,
        95.00
    ),
    (
        4,
        'ESS-LAV',
        'Essência Concentrada Lavanda',
        'L',
        1.2000,
        0.8000,
        110.00
    ),
    (
        5,
        'ESS-ROS',
        'Essência Concentrada Rosas',
        'L',
        0.6000,
        0.8000,
        125.00
    ),
    -- Alerta de estoque baixo para a dinâmica de aula!
    (
        6,
        'ESS-MAR',
        'Essência Concentrada Maracujá',
        'L',
        2.5000,
        0.8000,
        92.00
    ),
    (
        7,
        'LAURIL',
        'Lauril Éter Sulfato (espumante)',
        'L',
        4.0000,
        1.0000,
        18.00
    ),
    (
        8,
        'EMB-KRAFT',
        'Cinta Papel Kraft Personalizada',
        'un',
        800.0000,
        200.0000,
        0.45
    ),
    (
        9,
        'CELOF-BIO',
        'Película Protetora Biodegradável',
        'un',
        850.0000,
        200.0000,
        0.20
    );

-- 3. Ficha Técnica Padrão por Barra (100g)
-- PITANGA
INSERT INTO
    `ficha_tecnica` (`produto_id`, `insumo_id`, `qtd_por_barra`)
VALUES
    (1, 1, 0.0920),
    -- 92g de base
    (1, 2, 0.0020),
    -- 2ml extrato
    (1, 3, 0.0040),
    -- 4ml essência pitanga
    (1, 7, 0.0020),
    -- 2ml lauril
    (1, 8, 1.0000),
    -- 1 cinta
    (1, 9, 1.0000);

-- 1 película
-- LAVANDA
INSERT INTO
    `ficha_tecnica` (`produto_id`, `insumo_id`, `qtd_por_barra`)
VALUES
    (2, 1, 0.0920),
    (2, 2, 0.0020),
    (2, 4, 0.0040),
    -- essência lavanda
    (2, 7, 0.0020),
    (2, 8, 1.0000),
    (2, 9, 1.0000);

-- ROSAS
INSERT INTO
    `ficha_tecnica` (`produto_id`, `insumo_id`, `qtd_por_barra`)
VALUES
    (3, 1, 0.0920),
    (3, 2, 0.0020),
    (3, 5, 0.0040),
    -- essência rosas
    (3, 7, 0.0020),
    (3, 8, 1.0000),
    (3, 9, 1.0000);

-- MARACUJÁ
INSERT INTO
    `ficha_tecnica` (`produto_id`, `insumo_id`, `qtd_por_barra`)
VALUES
    (4, 1, 0.0920),
    (4, 2, 0.0020),
    (4, 6, 0.0040),
    -- essência maracujá
    (4, 7, 0.0020),
    (4, 8, 1.0000),
    (4, 9, 1.0000);

-- 4. OPs Iniciais para simulação imediata
INSERT INTO
    `ordens_producao` (
        `numero`,
        `produto_id`,
        `quantidade_planejada`,
        `quantidade_produzida`,
        `quantidade_refugo`,
        `data_programada`,
        `status`,
        `observacoes`
    )
VALUES
    (
        'OP-2026-001',
        2,
        100,
        100,
        2,
        CURDATE() - INTERVAL 2 DAY,
        'finalizada',
        'Produção concluída sem desvios graves.'
    ),
    (
        'OP-2026-002',
        1,
        150,
        0,
        0,
        CURDATE(),
        'em_producao',
        'Em processo de corte e secagem.'
    ),
    (
        'OP-2026-003',
        3,
        200,
        0,
        0,
        CURDATE() + INTERVAL 1 DAY,
        'planejada',
        'Atenção ao estoque crítico de essência de rosas!'
    );