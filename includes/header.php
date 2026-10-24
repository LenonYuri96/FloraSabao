<?php
/**
 * Flora Sabão — Header Padrão
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$page_title = $page_title ?? 'Simulador PCP';
$url_prefix = file_exists('pages') ? '' : '../';
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> | Flora Sabão PCP</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Flora Sabão Custom Design System -->
    <link href="<?= $url_prefix ?>assets/css/style.css" rel="stylesheet">
</head>

<body>