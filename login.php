<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

if (estaLogado()) {
    $perfil = $_SESSION['usuario_perfil'] ?? 'pcp';
    header('Location: ' . ($perfil === 'admin' ? 'pages/instrutor.php' : 'index.php'));
    exit;
}

$erro              = '';
$sucesso           = '';
$codigos_exibir    = [];    // tabela de códigos a exibir (ver_codigos / reset)
$novos_codigos     = false; // se veio de reset (para destacar)

// =============================================================================
// AÇÕES: RESET DE JOGO E VER CÓDIGOS (ambas exigem credenciais do instrutor)
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --------------------------------------------------------------------
    // AÇÃO: RESET — encerra o jogo atual e inicia uma nova partida
    // --------------------------------------------------------------------
    if ($action === 'reset_jogo') {
        $admin_email = trim($_POST['admin_email'] ?? '');
        $admin_senha = $_POST['admin_senha'] ?? '';
        $confirmar   = !empty($_POST['confirmar_reset']);

        if (!$confirmar) {
            $erro = 'Marque a caixa de confirmação para reiniciar o jogo.';
        } elseif (empty($admin_email) || empty($admin_senha)) {
            $erro = 'Informe o e-mail e a senha do instrutor.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ? AND perfil = 'admin' AND ativo = 1 LIMIT 1");
            $stmt->execute([$admin_email]);
            $admin = $stmt->fetch();

            if (!$admin || !password_verify($admin_senha, $admin['senha'])) {
                $erro = 'Credenciais de instrutor inválidas.';
            } else {
                try {
                    $pdo->beginTransaction();

                    // 1. Limpa dados transacionais (ordem importa por causa das FKs)
                    $pdo->exec("DELETE FROM `mov_estoque`");
                    $pdo->exec("DELETE FROM `ranking_rodadas`");
                    $pdo->exec("DELETE FROM `ordens_producao`");
                    $pdo->exec("DELETE FROM `jogo_rodadas`");

                    // 2. Regenera códigos de acesso + reseta estado das equipes
                    $prefixos = [
                        1 => 'ALFA', 2 => 'BETA', 3 => 'GAMA',
                        4 => 'DELTA', 5 => 'EPSILON', 6 => 'OMEGA',
                    ];
                    $stmt_upd = $pdo->prepare("
                        UPDATE `equipes`
                        SET `codigo_acesso`      = ?,
                            `caixa_disponivel`   = 15000.00,
                            `score_total`        = 0,
                            `tacho_ultimo_aroma` = 'LAV',
                            `tacho_higienizado`  = 1
                        WHERE `id` = ?
                    ");
                    foreach ($prefixos as $eq_id => $prefix) {
                        $novo_codigo = $prefix . '-' . strtoupper(bin2hex(random_bytes(2)));
                        $stmt_upd->execute([$novo_codigo, $eq_id]);
                    }

                    // 3. Reseta estoques de produtos acabados
                    $pdo->exec("UPDATE `produtos` SET `estoque_atual` = 150, `estoque_minimo` = 50");

                    // 4. Reseta insumos aos valores iniciais
                    $insumos_base = [
                        ['BASE-GLIC',  120.0,  30.0,  24.50],
                        ['EXT-ALOE',     5.0,   1.5,  68.00],
                        ['ESS-LAV',      1.2,   0.8, 110.00],
                        ['ESS-ROS',      0.6,   0.8, 125.00],
                        ['ESS-PIT',      2.0,   0.8,  95.00],
                        ['ESS-MAR',      2.5,   0.8,  92.00],
                        ['LAURIL',       4.0,   1.0,  18.00],
                        ['EMB-KRAFT',  800.0, 200.0,   0.45],
                        ['CELOF-BIO',  850.0, 200.0,   0.20],
                    ];
                    $stmt_ins = $pdo->prepare("
                        UPDATE `insumos`
                        SET `estoque_atual`  = ?,
                            `estoque_minimo` = ?,
                            `custo_unitario` = ?
                        WHERE `equipe_id` = ? AND `codigo` = ?
                    ");
                    for ($eq = 1; $eq <= 6; $eq++) {
                        foreach ($insumos_base as $ib) {
                            $stmt_ins->execute([$ib[1], $ib[2], $ib[3], $eq, $ib[0]]);
                        }
                    }

                    // 5. Cria nova rodada
                    $pdo->exec("
                        INSERT INTO `jogo_rodadas`
                            (`numero_rodada`, `mes_ano`, `status`,
                             `evento_titulo`, `evento_descricao`, `fator_demanda`)
                        VALUES
                            (1, 'Mês 01', 'aberta',
                             'Nova Partida Iniciada',
                             'Jogo reiniciado com sucesso! Boa sorte a todas as equipes.',
                             1.00)
                    ");
                    $nova_rodada_id = (int) $pdo->lastInsertId();

                    // 6. Ranking zerado para a nova rodada
                    $pdo->exec("
                        INSERT INTO `ranking_rodadas` (`rodada_id`, `equipe_id`, `score_rodada`)
                        SELECT {$nova_rodada_id}, `id`, 0 FROM `equipes`
                    ");

                    $pdo->commit();

                    $sucesso = '🎉 Jogo reiniciado com sucesso! Os novos códigos de acesso estão abaixo.';
                    $novos_codigos = true;

                    // Carrega códigos para exibir
                    $codigos_exibir = $pdo->query("
                        SELECT `id`, `nome`, `codigo_acesso`, `cor_hex`
                        FROM `equipes` WHERE `ativo` = 1 ORDER BY `id`
                    ")->fetchAll();

                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $erro = 'Erro ao reiniciar o jogo: ' . $e->getMessage();
                }
            }
        }
    }

    // --------------------------------------------------------------------
    // AÇÃO: VER CÓDIGOS (sem reset) — para o instrutor consultar os códigos
    // --------------------------------------------------------------------
    elseif ($action === 'ver_codigos') {
        $admin_email = trim($_POST['admin_email'] ?? '');
        $admin_senha = $_POST['admin_senha'] ?? '';

        if (empty($admin_email) || empty($admin_senha)) {
            $erro = 'Informe o e-mail e a senha do instrutor.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ? AND perfil = 'admin' AND ativo = 1 LIMIT 1");
            $stmt->execute([$admin_email]);
            $admin = $stmt->fetch();

            if (!$admin || !password_verify($admin_senha, $admin['senha'])) {
                $erro = 'Credenciais de instrutor inválidas.';
            } else {
                $codigos_exibir = $pdo->query("
                    SELECT `id`, `nome`, `codigo_acesso`, `cor_hex`
                    FROM `equipes` WHERE `ativo` = 1 ORDER BY `id`
                ")->fetchAll();
                $sucesso = 'Códigos de acesso das equipes (distribua apenas para a equipe correspondente):';
            }
        }
    }

    // --------------------------------------------------------------------
    // AÇÃO: LOGIN DE ALUNO (via código de acesso)
    // --------------------------------------------------------------------
    elseif ($action === 'login_aluno') {
        $codigo = strtoupper(trim($_POST['codigo'] ?? ''));

        if (empty($codigo)) {
            $erro = 'Informe o código de acesso da sua equipe.';
        } else {
            $stmt = $pdo->prepare("
                SELECT e.id AS equipe_id, e.nome AS equipe_nome,
                       u.id AS user_id, u.nome, u.email
                FROM `equipes` e
                JOIN `usuarios` u
                  ON u.equipe_id = e.id
                 AND u.perfil = 'pcp'
                 AND u.ativo = 1
                WHERE e.codigo_acesso = ? AND e.ativo = 1
                LIMIT 1
            ");
            $stmt->execute([$codigo]);
            $team = $stmt->fetch();

            if ($team) {
                session_regenerate_id(true);
                $_SESSION['usuario_id']        = $team['user_id'];
                $_SESSION['usuario_nome']      = $team['nome'];
                $_SESSION['usuario_email']     = $team['email'];
                $_SESSION['usuario_perfil']    = 'pcp';
                $_SESSION['usuario_equipe_id'] = $team['equipe_id'];
                $_SESSION['equipe_id']         = $team['equipe_id'];

                flash('sucesso', "Bem-vindo, {$team['equipe_nome']}!");
                header('Location: index.php');
                exit;
            } else {
                $erro = '❌ Código de acesso inválido. Verifique com o instrutor.';
            }
        }
    }

    // --------------------------------------------------------------------
    // AÇÃO: LOGIN DE INSTRUTOR (e-mail + senha)
    // --------------------------------------------------------------------
    elseif ($action === 'login_instrutor') {
        $email = trim($_POST['email'] ?? '');
        $senha = $_POST['senha'] ?? '';

        if (empty($email) || empty($senha)) {
            $erro = 'Preencha o e-mail e a senha.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ? AND ativo = 1 AND perfil = 'admin' LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($senha, $user['senha'])) {
                session_regenerate_id(true);
                $_SESSION['usuario_id']        = $user['id'];
                $_SESSION['usuario_nome']      = $user['nome'];
                $_SESSION['usuario_email']     = $user['email'];
                $_SESSION['usuario_perfil']    = 'admin';
                $_SESSION['usuario_equipe_id'] = null;

                header('Location: pages/instrutor.php');
                exit;
            } else {
                $erro = 'Credenciais de instrutor inválidas.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Acesso à Simulação | Flora Sabão</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #132419 0%, #20402c 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            font-family: sans-serif;
        }
        .card-login { max-width: 620px; width: 100%; border-radius: 16px; }
        .code-badge {
            font-family: 'Courier New', monospace;
            font-size: 1.1rem;
            font-weight: 700;
            letter-spacing: 2px;
        }
    </style>
</head>
<body>
<div class="card card-login bg-white shadow-lg p-4">
    <div class="text-center mb-4">
        <h2 class="fw-bold text-success mb-1"><i class="bi bi-flower1 me-2"></i>Flora Sabão</h2>
        <p class="text-muted small mb-0">Competição de Planejamento e Controle da Produção (PCP)</p>
    </div>

    <?php if (!empty($erro)): ?>
        <div class="alert alert-danger py-2 small mb-3"><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>
    <?php if (!empty($sucesso) && !$novos_codigos): ?>
        <div class="alert alert-success py-2 small mb-3">
            <i class="bi bi-check-circle-fill me-1"></i><?= htmlspecialchars($sucesso) ?>
        </div>
    <?php endif; ?>

    <!-- ============================================================= -->
    <!-- ABAS PRINCIPAIS                                               -->
    <!-- ============================================================= -->
    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active fw-semibold" data-bs-toggle="tab"
                    data-bs-target="#tab-aluno" type="button" role="tab">
                <i class="bi bi-people-fill me-1"></i> Aluno / Equipe
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-semibold" data-bs-toggle="tab"
                    data-bs-target="#tab-instrutor" type="button" role="tab">
                <i class="bi bi-person-badge-fill me-1"></i> Instrutor
            </button>
        </li>
    </ul>

    <div class="tab-content">
        <!-- ABA ALUNO: LOGIN VIA CÓDIGO -->
        <div class="tab-pane fade show active" id="tab-aluno" role="tabpanel">
            <form method="POST" action="login.php">
                <input type="hidden" name="action" value="login_aluno">
                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase">
                        Código de Acesso da Equipe
                    </label>
                    <input type="text" name="codigo" class="form-control form-control-lg text-uppercase code-badge text-center"
                           placeholder="ALFA-XXXX"
                           autocomplete="off" autocapitalize="characters"
                           maxlength="20" required autofocus>
                    <small class="text-muted d-block mt-2">
                        <i class="bi bi-info-circle me-1"></i>
                        Peça o código ao seu instrutor. Cada equipe possui um código único.
                    </small>
                </div>
                <button type="submit" class="btn btn-success w-100 py-2 fw-bold">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Entrar na Equipe
                </button>
            </form>
        </div>

        <!-- ABA INSTRUTOR: E-MAIL + SENHA -->
        <div class="tab-pane fade" id="tab-instrutor" role="tabpanel">
            <form method="POST" action="login.php">
                <input type="hidden" name="action" value="login_instrutor">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">E-mail</label>
                    <input type="email" name="email" class="form-control"
                           placeholder="admin@flora.com" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Senha</label>
                    <input type="password" name="senha" class="form-control"
                           placeholder="••••••" required>
                </div>
                <button type="submit" class="btn btn-dark w-100 py-2 fw-bold">
                    <i class="bi bi-shield-lock-fill me-1"></i> Entrar como Instrutor
                </button>
            </form>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- GERENCIAMENTO DO JOGO (somente instrutor)                     -->
    <!-- ============================================================= -->
    <div class="border-top mt-4 pt-3">
        <div class="d-flex justify-content-between align-items-center">
            <button class="btn btn-sm btn-outline-secondary fw-semibold" type="button"
                    data-bs-toggle="collapse" data-bs-target="#painelAdmin" aria-expanded="false">
                <i class="bi bi-gear-fill me-1"></i> Gerenciar Jogo (Instrutor)
            </button>
            <span class="badge bg-light text-muted border small">Área Restrita</span>
        </div>

        <div class="collapse mt-3" id="painelAdmin">
            <div class="alert alert-warning small py-2 mb-3">
                <i class="bi bi-shield-exclamation me-1"></i>
                Esta área exige as credenciais do instrutor. Use-a para <strong>ver os códigos</strong>
                das equipes ou <strong>reiniciar o jogo</strong> (encerra a partida atual e cria uma nova).
            </div>

            <!-- Ver códigos (sem reset) -->
            <form method="POST" action="login.php" class="mb-3">
                <input type="hidden" name="action" value="ver_codigos">
                <div class="row g-2 mb-2">
                    <div class="col-7">
                        <input type="email" name="admin_email" class="form-control form-control-sm"
                               placeholder="E-mail do instrutor" required>
                    </div>
                    <div class="col-5">
                        <input type="password" name="admin_senha" class="form-control form-control-sm"
                               placeholder="Senha" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-sm btn-outline-primary w-100 fw-semibold">
                    <i class="bi bi-key-fill me-1"></i> Ver Códigos das Equipes
                </button>
            </form>

            <hr>

            <!-- Reset do jogo -->
            <form method="POST" action="login.php"
                  onsubmit="return confirm('ATENÇÃO: Isso encerrará o jogo atual e apagará TODAS as OPs, KPIs e histórico. Os códigos de acesso serão regenerados. Deseja continuar?');">
                <input type="hidden" name="action" value="reset_jogo">
                <div class="row g-2 mb-2">
                    <div class="col-7">
                        <input type="email" name="admin_email" class="form-control form-control-sm"
                               placeholder="E-mail do instrutor" required>
                    </div>
                    <div class="col-5">
                        <input type="password" name="admin_senha" class="form-control form-control-sm"
                               placeholder="Senha" required>
                    </div>
                </div>
                <div class="form-check mb-2 small">
                    <input type="checkbox" name="confirmar_reset" id="confirmarReset"
                           class="form-check-input" required>
                    <label class="form-check-label text-danger fw-semibold" for="confirmarReset">
                        Confirmo encerrar o jogo atual e iniciar uma nova partida do zero
                    </label>
                </div>
                <button type="submit" class="btn btn-sm btn-danger w-100 fw-bold">
                    <i class="bi bi-arrow-clockwise me-1"></i> 🔄 Encerrar e Iniciar Novo Jogo
                </button>
            </form>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- RESULTADO: TABELA DE CÓDIGOS                                  -->
    <!-- ============================================================= -->
    <?php if (!empty($codigos_exibir)): ?>
        <div class="border-top mt-4 pt-3">
            <?php if ($novos_codigos): ?>
                <div class="alert alert-success py-2 mb-3 small">
                    <i class="bi bi-check-circle-fill me-1"></i>
                    <?= htmlspecialchars($sucesso) ?>
                    <strong>Distribua os novos códigos abaixo!</strong>
                </div>
            <?php else: ?>
                <div class="alert alert-info py-2 mb-3 small">
                    <i class="bi bi-key-fill me-1"></i><?= htmlspecialchars($sucesso) ?>
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light small text-uppercase">
                        <tr>
                            <th style="width: 40%;">Equipe</th>
                            <th style="width: 60%;">Código de Acesso</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($codigos_exibir as $c): ?>
                        <tr>
                            <td>
                                <span class="badge me-1" style="background-color: <?= htmlspecialchars($c['cor_hex']) ?>;">
                                    &nbsp;
                                </span>
                                <strong><?= htmlspecialchars($c['nome']) ?></strong>
                            </td>
                            <td>
                                <code class="code-badge text-success user-select-all">
                                    <?= htmlspecialchars($c['codigo_acesso']) ?>
                                </code>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <small class="text-muted d-block mt-2">
                <i class="bi bi-shield-lock me-1"></i>
                Estes códigos são únicos e sigilosos. Entregue apenas ao líder de cada equipe.
            </small>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Força maiúsculas no campo de código
    const campoCodigo = document.querySelector('input[name="codigo"]');
    if (campoCodigo) {
        campoCodigo.addEventListener('input', (e) => {
            e.target.value = e.target.value.toUpperCase();
        });
    }
</script>
</body>
</html>