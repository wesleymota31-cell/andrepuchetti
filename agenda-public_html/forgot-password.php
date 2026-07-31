<?php
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/seo.php';

app_start_session();

if (try_remember_login($conn)) {
    header('Location: admin/index.php');
    exit;
}

$mensagem = '';
$erro = '';
$emailValor = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $erro = 'Sessão expirada. Atualize a página e tente novamente.';
    } else {
        $email = normalize_email($_POST['email'] ?? '');
        $emailValor = $email;

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erro = 'Informe um e-mail válido.';
        } else {
            $stmt = $conn->prepare("SELECT id, nome, email FROM usuarios WHERE email = ? LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $usuario = $stmt->get_result()->fetch_assoc();

            if ($usuario) {
                $stmtDelete = $conn->prepare("DELETE FROM password_resets WHERE usuario_id = ?");
                $stmtDelete->bind_param('i', $usuario['id']);
                $stmtDelete->execute();

                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);
                $expiresAt = date('Y-m-d H:i:s', time() + 3600);

                $stmtInsert = $conn->prepare("
                    INSERT INTO password_resets (usuario_id, token_hash, expira_em)
                    VALUES (?, ?, ?)
                ");
                $stmtInsert->bind_param('iss', $usuario['id'], $tokenHash, $expiresAt);
                $stmtInsert->execute();

                $baseUrl = 'https://agenda.andrepuchetti.com.br';
                $resetUrl = $baseUrl . '/reset-password.php?token=' . urlencode($token);
                send_password_reset_email($usuario['email'], $usuario['nome'], $resetUrl);
            }

            $mensagem = 'Se este e-mail estiver cadastrado, enviaremos um link para redefinir a senha.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Esqueci a senha | André Puchetti</title>
<?php render_seo_meta(
    'Esqueci a senha | André Puchetti',
    'Recupere o acesso ao painel administrativo do sistema de agenda do Salão André Puchetti.',
    ['favicon_path' => 'assets/logo-salao.png', 'robots' => 'noindex, nofollow']
); ?>
  <style>
    :root { --bg:#070707; --text:#f7f3ea; --text-soft:rgba(247,243,234,.78); --gold:#d4af37; --gold-soft:#f0d77a; }
    *{box-sizing:border-box}
    body{margin:0;min-height:100vh;font-family:Inter,Arial,sans-serif;background:linear-gradient(180deg,#050505,#0d0d0d);display:flex;align-items:center;justify-content:center;padding:20px;color:var(--text)}
    .card{width:100%;max-width:460px;padding:30px 26px;border-radius:28px;background:linear-gradient(180deg,rgba(255,255,255,.06),rgba(255,255,255,.03));border:1px solid rgba(255,255,255,.08);box-shadow:0 20px 60px rgba(0,0,0,.45)}
    .badge{display:inline-flex;min-height:38px;align-items:center;padding:0 14px;border-radius:999px;background:rgba(212,175,55,.08);border:1px solid rgba(212,175,55,.18);color:var(--gold-soft);font-size:12px;font-weight:800;letter-spacing:.16em;text-transform:uppercase;margin-bottom:18px}
    h1{margin:0 0 10px;font-size:2rem;line-height:1;letter-spacing:-.04em}
    p{margin:0 0 22px;color:var(--text-soft);line-height:1.75}
    .alert{margin-bottom:16px;padding:14px 16px;border-radius:14px;font-size:.95rem}
    .alert.error{background:rgba(255,95,109,.10);border:1px solid rgba(255,95,109,.20);color:#ffd7dc}
    .alert.ok{background:rgba(32,201,151,.10);border:1px solid rgba(32,201,151,.20);color:#c7ffec}
    label{display:block;margin-bottom:8px;color:var(--gold-soft);font-size:12px;font-weight:800;letter-spacing:.14em;text-transform:uppercase}
    input{width:100%;min-height:56px;border-radius:16px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.04);color:var(--text);padding:0 16px;outline:none;font-size:1rem}
    input:focus{border-color:rgba(212,175,55,.38);box-shadow:0 0 0 4px rgba(212,175,55,.08)}
    button{width:100%;min-height:58px;border:none;cursor:pointer;border-radius:18px;background:linear-gradient(90deg,#c8a22a 0%,#f2d778 50%,#cfa72d 100%);color:#1a1405;font-size:1rem;font-weight:900;margin-top:16px}
    .link{display:inline-flex;margin-top:18px;color:var(--gold-soft);text-decoration:none;font-weight:800}
  </style>
</head>
<body>
  <div class="card">
    <div class="badge">Recuperação</div>
    <h1>Esqueci a senha</h1>
    <p>Informe seu e-mail. Se ele estiver cadastrado, enviaremos um link seguro para criar uma nova senha.</p>

    <?php if ($erro): ?><div class="alert error"><?= htmlspecialchars($erro); ?></div><?php endif; ?>
    <?php if ($mensagem): ?><div class="alert ok"><?= htmlspecialchars($mensagem); ?></div><?php endif; ?>

    <form method="POST">
      <?= csrf_input(); ?>
      <label for="email">E-mail</label>
      <input type="email" id="email" name="email" value="<?= htmlspecialchars($emailValor, ENT_QUOTES); ?>" autocomplete="email" required>
      <button type="submit">Enviar link de redefinição</button>
    </form>

    <a class="link" href="login.php">Voltar para o login</a>
  </div>
</body>
</html>
