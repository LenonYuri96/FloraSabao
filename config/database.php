<?php
/**
 * Flora Sabão — Conexão com o Banco de Dados com Auto-Criação
 */

declare(strict_types=1);

$db_host = 'localhost';
$db_name = 'flora_sabao';
$db_user = 'root';
$db_pass = ''; // Altere se o seu MySQL local possuir senha
$db_port = 3306;

// Declaração no escopo principal para evitar alertas de variáveis indefinidas no catch
$dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
} catch (PDOException $e) {
    // Se o banco ainda não existe no MySQL (código 1049), cria-o automaticamente
    if ((int)$e->getCode() === 1049 || str_contains($e->getMessage(), 'Unknown database')) {
        try {
            $pdo_root = new PDO("mysql:host={$db_host};port={$db_port};charset=utf8mb4", $db_user, $db_pass, $options);
            $pdo_root->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
            $pdo = new PDO($dsn, $db_user, $db_pass, $options);
        } catch (PDOException $eRoot) {
            die('<strong>Erro na inicialização automática do banco de dados:</strong> ' . htmlspecialchars($eRoot->getMessage()));
        }
    } else {
        die('<strong>Erro na conexão com o banco de dados:</strong> ' . htmlspecialchars($e->getMessage()));
    }
}