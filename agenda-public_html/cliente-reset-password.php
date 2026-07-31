<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/security.php';

date_default_timezone_set('America/Sao_Paulo');

function cliente_reset_table(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS cliente_password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cliente_id INT NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            expira_em DATETIME NOT NULL,
            usado_em DATETIME NULL,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cliente_reset_token (token_hash),
            INDEX idx_cliente_reset_cliente (cliente_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

cliente_reset_table($conn);

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$tokenHash = hash('sha256', $token);
$erro = '';
$mensagem = '';
$reset = null;

if ($token !== '') {
    $stmt = $conn->prepare("
        SELECT r.id, r.cliente_id, c.nome
        FROM cliente_password_resets r
        INNER JOIN clientes c ON c.id = r.cliente_id
        WHERE r.token_hash = ?
          AND r.usado_em IS NULL
          AND r.expira_em >= NOW()
        LIMIT 1
    ");
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $reset = $stmt->get_result()->fetch_assoc();
}

if (!$reset) {
    $erro = 'Este link expirou ou já foi utilizado.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reset) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $erro = 'Sessão expirada. Tente novamente.';
    } else {
        $senha = $_POST['senha'] ?? '';
        $confirmar = $_POST['confirmar_senha'] ?? '';

        if (strlen($senha) < 6) {
            $erro = 'A senha precisa ter pelo menos 6 caracteres.';
        } elseif ($senha !== $confirmar) {
            $erro = 'As senhas não conferem.';
        } else {
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            $stmtUp = $conn->prepare("UPDATE clientes SET senha = ? WHERE id = ? LIMIT 1");
            $stmtUp->bind_param('si', $hash, $reset['cliente_id']);
            $stmtUp->execute();

            $stmtUsed = $conn->prepare("UPDATE cliente_password_resets SET usado_em = NOW() WHERE id = ? LIMIT 1");
            $stmtUsed->bind_param('i', $reset['id']);
            $stmtUsed->execute();
            $mensagem = 'Senha atualizada com sucesso. Você já pode entrar.';
            $reset = null;
        }
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Criar nova senha | André Puchetti</title>
  <style>
    *{box-sizing:border-box}body{margin:0;min-height:100vh;background:radial-gradient(circle at top,#181406,#070707 46%);color:#fff8e7;font-family:Inter,Arial,sans-serif;display:grid;place-items:center;padding:22px}.box{width:min(460px,100%);border:1px solid rgba(212,175,55,.22);background:rgba(18,18,18,.88);border-radius:24px;padding:26px;box-shadow:0 24px 60px rgba(0,0,0,.34)}.brand{display:grid;place-items:center;margin-bottom:18px}.brand img{width:96px}h1{margin:0;text-align:center;font-size:clamp(2rem,9vw,3rem);line-height:1;color:#fff1bd}p{color:#d8d0bd;text-align:center;line-height:1.6}.field{display:grid;gap:8px;margin-top:18px}label{font-size:12px;color:#f1d989;font-weight:800;text-transform:uppercase;letter-spacing:.08em}input{width:100%;min-height:52px;border-radius:16px;border:1px solid rgba(255,255,255,.10);background:#0d0d0d;color:#fff8e7;padding:0 15px;font-size:16px}button,.link{width:100%;min-height:56px;border:0;border-radius:18px;margin-top:18px;background:linear-gradient(90deg,#f7e7af,#d4af37,#a87908);color:#181510;font-weight:900;font-size:16px;text-decoration:none;display:grid;place-items:center}.link{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.10);color:#fff8e7}.msg,.erro{margin-top:16px;padding:12px;border-radius:14px;text-align:center;line-height:1.5}.msg{background:rgba(32,201,151,.12);color:#d9fff1}.erro{background:rgba(255,95,109,.12);color:#ffd8dd}
  </style>
</head>
<body>
  <main class="box">
    <div class="brand"><img src="assets/logo-salao.png" alt="André Puchetti"></div>
    <h1>Criar nova senha</h1>
    <?php if ($mensagem): ?><div class="msg"><?= htmlspecialchars($mensagem); ?></div><a class="link" href="cliente-login.php">Entrar agora</a><?php elseif ($reset): ?>
      <p>Olá, <?= htmlspecialchars(explode(' ', trim($reset['nome']))[0] ?: $reset['nome']); ?>. Escolha sua nova senha de acesso.</p>
      <?php if ($erro): ?><div class="erro"><?= htmlspecialchars($erro); ?></div><?php endif; ?>
      <form method="post">
        <?= csrf_input(); ?>
        <input type="hidden" name="token" value="<?= htmlspecialchars($token); ?>">
        <div class="field"><label>Nova senha</label><input type="password" name="senha" autocomplete="new-password" required></div>
        <div class="field"><label>Confirmar senha</label><input type="password" name="confirmar_senha" autocomplete="new-password" required></div>
        <button type="submit">Salvar nova senha</button>
      </form>
    <?php else: ?>
      <div class="erro"><?= htmlspecialchars($erro); ?></div>
      <a class="link" href="cliente-forgot-password.php">Pedir novo link</a>
    <?php endif; ?>
  </main>
</body>
</html>
