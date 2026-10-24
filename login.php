<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

// Se já está logado, vai direto para o dashboard
if (estaLogado()) {
    header('Location: index.php');
    exit;
}

// -------------------------------------------------------------
// AUTO-RECUPERAÇÃO: Garante tabela e usuários padrão no banco
// -------------------------------------------------------------
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `usuarios` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `nome` VARCHAR(80) NOT NULL,
            `email` VARCHAR(100) NOT NULL UNIQUE,
            `senha` VARCHAR(255) NOT NULL,
            `perfil` ENUM('admin', 'pcp', 'producao', 'almoxarifado') NOT NULL DEFAULT 'pcp',
            `ativo` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
    ");

    $qtdUsuarios = (int) $pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
    if ($qtdUsuarios === 0) {
        $hashPadrao = password_hash('123456', PASSWORD_DEFAULT);
        $stmtInit = $pdo->prepare("
            INSERT INTO usuarios (nome, email, senha, perfil, ativo) VALUES 
            ('Coordenador PCP', 'pcp@flora.com', ?, 'pcp', 1),
            ('Líder de Produção', 'producao@flora.com', ?, 'producao', 1),
            ('Almoxarife / Compras', 'estoque@flora.com', ?, 'almoxarifado', 1),
            ('Gerente Geral', 'admin@flora.com', ?, 'admin', 1)
        ");
        $stmtInit->execute([$hashPadrao, $hashPadrao, $hashPadrao, $hashPadrao]);
    }
} catch (Throwable $e) {
    // Caso haja erro de permissão no DDL, continua para tentar o login
}

$erro = lerFlash('erro');
$sucesso = lerFlash('sucesso');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $senha = (string) ($_POST['senha'] ?? '');

    if (empty($email) || empty($senha)) {
        $erro = 'Informe seu e-mail e senha cadastrados.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ? AND ativo = 1 LIMIT 1");
            $stmt->execute([$email]);
            $usuario = $stmt->fetch();

            $senhaValida = false;

            if ($usuario) {
                // 1. Testa verificação padrão Bcrypt
                if (password_verify($senha, $usuario['senha'])) {
                    $senhaValida = true;
                }
                // 2. Fallback: senha em texto plano digitada manualmente no banco
                elseif ($usuario['senha'] === $senha) {
                    $senhaValida = true;
                    $novoHash = password_hash($senha, PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE usuarios SET senha = ? WHERE id = ?")->execute([$novoHash, $usuario['id']]);
                }
                // 3. Fallback didático: senha padrão 123456 para contas modelo
                elseif ($senha === '123456' && in_array($email, ['pcp@flora.com', 'producao@flora.com', 'estoque@flora.com', 'admin@flora.com'], true)) {
                    $senhaValida = true;
                    $novoHash = password_hash('123456', PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE usuarios SET senha = ? WHERE id = ?")->execute([$novoHash, $usuario['id']]);
                }
            }

            if ($senhaValida && $usuario) {
                session_regenerate_id(true);

                $_SESSION['usuario_id']     = (int) $usuario['id'];
                $_SESSION['usuario_nome']   = $usuario['nome'];
                $_SESSION['usuario_email']  = $usuario['email'];
                $_SESSION['usuario_perfil'] = $usuario['perfil'];

                flash('sucesso', 'Bem-vindo(a), ' . $usuario['nome'] . '!');
                header('Location: index.php');
                exit;
            } else {
                $erro = 'Credenciais incorretas ou usuário inativo no sistema.';
            }
        } catch (PDOException $e) {
            $erro = 'Erro no banco de dados: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Flora Sabão PCP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
    body {
        background: linear-gradient(135deg, #1e3f2b 0%, #355e3b 100%);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    }

    .login-card {
        border-radius: 16px;
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.25);
        max-width: 440px;
        width: 100%;
    }
    </style>
</head>

<body>
    <div class="container p-3">
        <div class="card login-card mx-auto bg-white p-4">
            <div class="text-center mb-4">
                <div class="display-5 text-success mb-2"><i class="bi bi-flower1"></i></div>
                <h3 class="fw-bold text-dark mb-1">Flora Sabão</h3>
                <p class="text-muted small mb-0">Simulador Integrado de PCP & Manufatura Artesanal</p>
            </div>

            <?php if (!empty($sucesso)): ?>
            <div class="alert alert-success py-2 small mb-3 border-0">
                <i class="bi bi-check-circle-fill me-1"></i> <?= htmlspecialchars($sucesso) ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($erro)): ?>
            <div class="alert alert-danger py-2 small mb-3 border-0">
                <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= htmlspecialchars($erro) ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <div class="mb-3">
                    <label class="form-label fw-semibold small text-muted">E-mail Operacional</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="bi bi-envelope"></i></span>
                        <input type="email" name="email" id="campoEmail" class="form-control"
                            placeholder="nome@flora.com" required autofocus>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold small text-muted">Senha de Acesso</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="bi bi-lock"></i></span>
                        <input type="password" name="senha" id="campoSenha" class="form-control" placeholder="••••••••"
                            required>
                    </div>
                </div>

                <button type="submit" class="btn btn-success w-100 py-2 fw-semibold shadow-sm mb-3">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Entrar no Sistema
                </button>
            </form>

            <div class="border-top pt-3 text-center">
                <span class="small text-muted d-block mb-2 fw-semibold">Acesso Rápido para a Dinâmica:</span>
                <div class="btn-group btn-group-sm w-100 mb-2">
                    <button type="button" class="btn btn-outline-secondary"
                        onclick="preencher('pcp@flora.com')">PCP</button>
                    <button type="button" class="btn btn-outline-secondary"
                        onclick="preencher('producao@flora.com')">Produção</button>
                    <button type="button" class="btn btn-outline-secondary"
                        onclick="preencher('estoque@flora.com')">Estoque</button>
                    <button type="button" class="btn btn-outline-secondary"
                        onclick="preencher('admin@flora.com')">Admin</button>
                </div>
                <div class="text-muted small">Senha padrão: <code>123456</code></div>
            </div>
        </div>
    </div>

    <script>
    function preencher(email) {
        document.getElementById('campoEmail').value = email;
        document.getElementById('campoSenha').value = '123456';
    }
    </script>
</body>

</html>