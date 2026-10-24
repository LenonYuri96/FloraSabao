<?php
/**
 * Flora Sabão — Footer Padrão
 */
$url_prefix = file_exists('pages') ? '' : '../';
$current_page = basename($_SERVER['PHP_SELF']);
?>
<footer class="bg-white border-top py-3 mt-5 text-muted small">
    <div class="container-fluid px-4 d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2">
        <div>
            <strong>🌿 Flora Sabão</strong> — Simulador Didático de Gestão de PCP &bull; Microempresa Cosmética
        </div>
        <div>
            Capacidade Fabril:
            <strong><?= defined('CAPACIDADE_MENSAL_BARRAS') ? CAPACIDADE_MENSAL_BARRAS : 2500 ?></strong> barras/mês
        </div>
    </div>
</footer>

<!-- Bootstrap 5 Bundle JS com Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Scripts Gerais da Aplicação -->
<script src="<?= $url_prefix ?>assets/js/main.js"></script>

<!-- Scripts Contextuais por Página -->
<?php if ($current_page === 'ordens_producao.php'): ?>
    <script src="<?= $url_prefix ?>assets/js/op.js"></script>
<?php elseif ($current_page === 'programacao.php'): ?>
    <script src="<?= $url_prefix ?>assets/js/programacao.js"></script>
<?php endif; ?>
</body>

</html>