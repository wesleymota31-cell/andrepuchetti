<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/mailer.php';

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

$mensagem = '';
$erro = '';
$emailValor = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $erro = 'Sessão expirada. Tente novamente.';
    } else {
        $email = normalize_email($_POST['email'] ?? '');
        $emailValor = $email;

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erro = 'Informe um e-mail válido.';
        } else {
            cliente_reset_table($conn);
            $stmt = $conn->prepare("SELECT id, nome, email FROM clientes WHERE email = ? LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $cliente = $stmt->get_result()->fetch_assoc();

            if ($cliente) {
                $stmtDel = $conn->prepare("DELETE FROM cliente_password_resets WHERE cliente_id = ? OR expira_em < NOW()");
                $stmtDel->bind_param('i', $cliente['id']);
                $stmtDel->execute();

                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);
                $expiraEm = date('Y-m-d H:i:s', strtotime('+1 hour'));
                $stmtIns = $conn->prepare("INSERT INTO cliente_password_resets (cliente_id, token_hash, expira_em) VALUES (?, ?, ?)");
                $stmtIns->bind_param('iss', $cliente['id'], $tokenHash, $expiraEm);
                $stmtIns->execute();

                $baseUrl = 'https://agenda.andrepuchetti.com.br';
                agenda_send_client_password_reset_email($cliente['email'], $cliente['nome'], $baseUrl . '/cliente-reset-password.php?token=' . urlencode($token));
            }

            $mensagem = 'Se este e-mail estiver cadastrado, enviamos um link para criar uma nova senha.';
        }
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Esqueci minha senha | André Puchetti</title>
  <style>
    *{box-sizing:border-box}body{margin:0;min-height:100vh;background:radial-gradient(circle at top,#181406,#070707 46%);color:#fff8e7;font-family:Inter,Arial,sans-serif;display:grid;place-items:center;padding:22px}.box{width:min(460px,100%);border:1px solid rgba(212,175,55,.22);background:rgba(18,18,18,.88);border-radius:24px;padding:26px;box-shadow:0 24px 60px rgba(0,0,0,.34)}.brand{display:grid;place-items:center;margin-bottom:18px}.brand img{width:96px}h1{margin:0;text-align:center;font-size:clamp(2rem,9vw,3rem);line-height:1;color:#fff1bd}p{color:#d8d0bd;text-align:center;line-height:1.6}.field{display:grid;gap:8px;margin-top:18px}label{font-size:12px;color:#f1d989;font-weight:800;text-transform:uppercase;letter-spacing:.08em}input{width:100%;min-height:52px;border-radius:16px;border:1px solid rgba(255,255,255,.10);background:#0d0d0d;color:#fff8e7;padding:0 15px;font-size:16px}button,.link{width:100%;min-height:56px;border:0;border-radius:18px;margin-top:18px;background:linear-gradient(90deg,#f7e7af,#d4af37,#a87908);color:#181510;font-weight:900;font-size:16px;text-decoration:none;display:grid;place-items:center}.link{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.10);color:#fff8e7}.msg,.erro{margin-top:16px;padding:12px;border-radius:14px;text-align:center;line-height:1.5}.msg{background:rgba(32,201,151,.12);color:#d9fff1}.erro{background:rgba(255,95,109,.12);color:#ffd8dd}
  </style>
</head>
<body>
  <main class="box">
    <div class="brand"><img src="assets/logo-salao.png" alt="André Puchetti"></div>
    <h1>Esqueci minha senha</h1>
    <p>Informe seu e-mail para receber um link seguro e criar uma nova senha.</p>
    <?php if ($mensagem): ?><div class="msg"><?= htmlspecialchars($mensagem); ?></div><?php endif; ?>
    <?php if ($erro): ?><div class="erro"><?= htmlspecialchars($erro); ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_input(); ?>
      <div class="field"><label>E-mail</label><input type="email" name="email" value="<?= htmlspecialchars($emailValor); ?>" autocomplete="email" required></div>
      <button type="submit">Enviar link</button>
    </form>
    <a class="link" href="cliente-login.php">Voltar para login</a>
  </main>
</body>
</html>
