USE `flora_sabao`;

CREATE TABLE IF NOT EXISTS `usuarios` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nome` VARCHAR(80) NOT NULL,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `senha` VARCHAR(255) NOT NULL,
    `perfil` ENUM('admin', 'pcp', 'producao', 'almoxarifado') NOT NULL DEFAULT 'pcp',
    `ativo` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- Hash verificado para a senha '123456':
-- $2y$10$uDnEpQpkh9RO5tWwOrHtneLAuYMRDstRaKGZFyHubObDR0789OMO6
INSERT INTO
    `usuarios` (`nome`, `email`, `senha`, `perfil`, `ativo`)
VALUES
    (
        'Coordenador PCP',
        'pcp@flora.com',
        '$2y$10$uDnEpQpkh9RO5tWwOrHtneLAuYMRDstRaKGZFyHubObDR0789OMO6',
        'pcp',
        1
    ),
    (
        'Líder de Produção',
        'producao@flora.com',
        '$2y$10$uDnEpQpkh9RO5tWwOrHtneLAuYMRDstRaKGZFyHubObDR0789OMO6',
        'producao',
        1
    ),
    (
        'Almoxarife / Compras',
        'estoque@flora.com',
        '$2y$10$uDnEpQpkh9RO5tWwOrHtneLAuYMRDstRaKGZFyHubObDR0789OMO6',
        'almoxarifado',
        1
    ),
    (
        'Gerente Geral',
        'admin@flora.com',
        '$2y$10$uDnEpQpkh9RO5tWwOrHtneLAuYMRDstRaKGZFyHubObDR0789OMO6',
        'admin',
        1
    ) ON DUPLICATE KEY
UPDATE
    `nome` =
VALUES
    (`nome`),
    `senha` =
VALUES
    (`senha`),
    `perfil` =
VALUES
    (`perfil`),
    `ativo` = 1;