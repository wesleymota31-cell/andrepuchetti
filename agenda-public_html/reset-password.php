<?php
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/seo.php';

app_start_session();

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$erro = '';
$mensagem = '';
$tokenValido = false;
$resetRecord = null;

if (is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token)) {
    $tokenHash = hash('sha256', $token);
    $stmt = $conn->prepare("
        SELECT pr.id, pr.usuario_id, pr.expira_em, u.nome, u.email
        FROM password_resets pr
        INNER JOIN usuarios u ON u.id = pr.usuario_id
        WHERE pr.token_hash = ?
        LIMIT 1
    ");
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $resetRecord = $stmt->get_result()->fetch_assoc();
    $tokenValido = $resetRecord && strtotime($resetRecord['expira_em']) >= time();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$tokenValido) {
        $erro = 'Link inválido ou expirado.';
    } elseif (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $erro = 'Sessão expirada. Atualize a página e tente novamente.';
    } else {
        $senha = $_POST['senha'] ?? '';
        $senhaConfirmacao = $_POST['senha_confirmacao'] ?? '';

        if (strlen($senha) < 8) {
            $erro = 'A nova senha precisa ter pelo menos 8 caracteres.';
        } elseif ($senha !== $senhaConfirmacao) {
            $erro = 'As senhas não conferem.';
        } else {
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            $stmtUpdate = $conn->prepare("UPDATE usuarios SET senha = ? WHERE id = ?");
            $stmtUpdate->bind_param('si', $hash, $resetRecord['usuario_id']);
            $stmtUpdate->execute();

            $stmtDelete = $conn->prepare("DELETE FROM password_resets WHERE usuario_id = ?");
            $stmtDelete->bind_param('i', $resetRecord['usuario_id']);
            $stmtDelete->execute();

            $stmtRemember = $conn->prepare("DELETE FROM usuario_remember_tokens WHERE usuario_id = ?");
            $stmtRemember->bind_param('i', $resetRecord['usuario_id']);
            $stmtRemember->execute();

            $mensagem = 'Senha atualizada com sucesso. Você já pode entrar com a nova senha.';
            $tokenValido = false;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Redefinir senha | André Puchetti</title>
<?php render_seo_meta(
    'Redefinir senha | André Puchetti',
    'Crie uma nova senha para acessar o painel administrativo do sistema de agenda do Salão André Puchetti.',
    ['favicon_path' => 'assets/logo-salao.png', 'robots' => 'noindex, nofollow']
); ?>
  <style>
    :root { --text:#f7f3ea; --text-soft:rgba(247,243,234,.78); --gold:#d4af37; --gold-soft:#f0d77a; }
    *{box-sizing:border-box}
    body{margin:0;min-height:100vh;font-family:Inter,Arial,sans-serif;background:linear-gradient(180deg,#050505,#0d0d0d);display:flex;align-items:center;justify-content:center;padding:20px;color:var(--text)}
    .card{width:100%;max-width:460px;padding:30px 26px;border-radius:28px;background:linear-gradient(180deg,rgba(255,255,255,.06),rgba(255,255,255,.03));border:1px solid rgba(255,255,255,.08);box-shadow:0 20px 60px rgba(0,0,0,.45)}
    .badge{display:inline-flex;min-height:38px;align-items:center;padding:0 14px;border-radius:999px;background:rgba(212,175,55,.08);border:1px solid rgba(212,175,55,.18);color:var(--gold-soft);font-size:12px;font-weight:800;letter-spacing:.16em;text-transform:uppercase;margin-bottom:18px}
    h1{margin:0 0 10px;font-size:2rem;line-height:1;letter-spacing:-.04em}
    p{margin:0 0 22px;color:var(--text-soft);line-height:1.75}
    .alert{margin-bottom:16px;padding:14px 16px;border-radius:14px;font-size:.95rem}
    .alert.error{background:rgba(255,95,109,.10);border:1px solid rgba(255,95,109,.20);color:#ffd7dc}
    .alert.ok{background:rgba(32,201,151,.10);border:1px solid rgba(32,201,151,.20);color:#c7ffec}
    .field{margin-bottom:14px}
    label{display:block;margin-bottom:8px;color:var(--gold-soft);font-size:12px;font-weight:800;letter-spacing:.14em;text-transform:uppercase}
    input{width:100%;min-height:56px;border-radius:16px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.04);color:var(--text);padding:0 16px;outline:none;font-size:1rem}
    input:focus{border-color:rgba(212,175,55,.38);box-shadow:0 0 0 4px rgba(212,175,55,.08)}
    button{width:100%;min-height:58px;border:none;cursor:pointer;border-radius:18px;background:linear-gradient(90deg,#c8a22a 0%,#f2d778 50%,#cfa72d 100%);color:#1a1405;font-size:1rem;font-weight:900;margin-top:6px}
    .link{display:inline-flex;margin-top:18px;color:var(--gold-soft);text-decoration:none;font-weight:800}
  </style>
</head>
<body>
  <div class="card">
    <div class="badge">Nova senha</div>
    <h1>Redefinir senha</h1>
    <p>Crie uma nova senha com pelo menos 8 caracteres.</p>

    <?php if ($erro || !$tokenValido && !$mensagem): ?>
      <div class="alert error"><?= htmlspecialchars($erro ?: 'Link inválido ou expirado.'); ?></div>
    <?php endif; ?>
    <?php if ($mensagem): ?><div class="alert ok"><?= htmlspecialchars($mensagem); ?></div><?php endif; ?>

    <?php if ($tokenValido): ?>
      <form method="POST">
        <?= csrf_input(); ?>
        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES); ?>">
        <div class="field">
          <label for="senha">Nova senha</label>
          <input type="password" id="senha" name="senha" autocomplete="new-password" required>
        </div>
        <div class="field">
          <label for="senha_confirmacao">Confirmar senha</label>
          <input type="password" id="senha_confirmacao" name="senha_confirmacao" autocomplete="new-password" required>
        </div>
        <button type="submit">Salvar nova senha</button>
      </form>
    <?php endif; ?>

    <a class="link" href="login.php">Voltar para o login</a>
  </div>
</body>
</html>
