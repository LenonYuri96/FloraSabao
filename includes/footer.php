<?php
/**
 * Flora Sabão — Footer Padrão
 * ---------------------------------------------
 * Rodapé institucional com créditos do projeto.
 */
$url_prefix   = file_exists('pages') ? '' : '../';
$current_page = basename($_SERVER['PHP_SELF']);
$ano_atual    = date('Y');
?>
<footer class="bg-white border-top mt-5">
    <div class="container-fluid px-4 py-4">
        <div class="row g-4 align-items-start">

            <!-- ============================================== -->
            <!-- COLUNA 1: Identidade do Projeto                -->
            <!-- ============================================== -->
            <div class="col-12 col-lg-4 text-center text-lg-start">
                <div class="d-flex align-items-center justify-content-center justify-content-lg-start gap-2 mb-2">
                    <i class="bi bi-flower1 text-success fs-4"></i>
                    <strong class="text-dark fs-5">Flora Sabão</strong>
                </div>
                <p class="small text-muted mb-2">
                    Simulador Didático de Gestão de PCP &bull; Microempresa Cosmética
                </p>
                <span class="badge bg-success-subtle text-success border border-success-subtle small">
                    <i class="bi bi-boxes me-1"></i>
                    Capacidade Fabril:
                    <strong><?= defined('CAPACIDADE_MENSAL_BARRAS') ? (int) CAPACIDADE_MENSAL_BARRAS : 2500 ?></strong>
                    barras/mês
                </span>
            </div>

            <!-- ============================================== -->
            <!-- COLUNA 2: Créditos de Desenvolvimento         -->
            <!-- ============================================== -->
            <div class="col-12 col-lg-4 text-center">
                <div class="small text-uppercase text-muted fw-bold mb-2" style="letter-spacing: .05em;">
                    <i class="bi bi-code-slash me-1"></i> Desenvolvimento
                </div>
                <div class="d-inline-flex align-items-center gap-2 bg-light border rounded-pill px-3 py-2">
                    <span class="badge rounded-circle bg-primary p-2" style="width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center;">
                        <i class="bi bi-person-fill text-white"></i>
                    </span>
                    <div class="text-start">
                        <div class="fw-bold text-dark" style="font-size: .92rem;">Lenon Yuri</div>
                        <div class="text-muted" style="font-size: .78rem;">Desenvolvedor do Simulador</div>
                    </div>
                </div>
            </div>

            <!-- ============================================== -->
            <!-- COLUNA 3: Créditos de Idealização             -->
            <!-- ============================================== -->
            <div class="col-12 col-lg-4 text-center text-lg-end">
                <div class="small text-uppercase text-muted fw-bold mb-2" style="letter-spacing: .05em;">
                    <i class="bi bi-lightbulb-fill text-warning me-1"></i> Idealização do Jogo
                </div>
                <div class="d-inline-flex align-items-center gap-2 bg-light border rounded-pill px-3 py-2">
                    <span class="badge rounded-circle bg-warning p-2" style="width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center;">
                        <i class="bi bi-star-fill text-dark"></i>
                    </span>
                    <div class="text-start">
                        <div class="fw-bold text-dark" style="font-size: .92rem;">Caroline Pafovani</div>
                        <div class="text-muted" style="font-size: .78rem;">Idealizadora do Conceito</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================== -->
        <!-- Linha inferior: Copyright                      -->
        <!-- ============================================== -->
        <hr class="my-3 border-secondary-subtle opacity-25">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2 small text-muted">
            <div>
                <i class="bi bi-c-circle me-1"></i>
                <?= $ano_atual ?> <strong class="text-dark">Flora Sabão</strong> — Todos os direitos reservados.
            </div>
            <div class="text-center text-sm-end">
                <i class="bi bi-mortarboard-fill me-1"></i>
                Simulador acadêmico de PCP &bull;
                <span class="badge bg-light text-dark border border-secondary-subtle ms-1">v3.0</span>
            </div>
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