<?php
/**
 * Flora Sabão — Conexão com o Banco de Dados (PDO)
 */

declare(strict_types=1);

$db_host = 'localhost';
$db_name = 'flora_sabao';
$db_user = 'root';
$db_pass = ''; // Altere se o seu MySQL local possuir senha
$db_port = 3306;

try {
    $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
    
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
} catch (PDOException $e) {
    // Para fins pedagógicos em sala, exibe o erro claro. Em produção real, logar em arquivo.
    die('<strong>Erro na conexão com o banco de dados:</strong> ' . htmlspecialchars($e->getMessage()));
}