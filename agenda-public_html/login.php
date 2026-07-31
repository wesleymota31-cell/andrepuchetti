<?php
require_once __DIR__ . '/includes/security.php';
require_once 'config.php';
require_once __DIR__ . '/includes/seo.php';

app_start_session();

if (try_remember_login($conn)) {
    header('Location: admin/index.php');
    exit;
}

$erro = '';
$emailValor = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $erro = 'Sessão expirada. Atualize a página e tente novamente.';
    } else {
    $email = normalize_email($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $lembrar = isset($_POST['lembrar']);
    $emailValor = $email;

    if ($email === '' || $senha === '') {
        $erro = 'Preencha e-mail e senha.';
    } else {
        $stmt = $conn->prepare("SELECT id, nome, email, senha FROM usuarios WHERE email = ? LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $usuario = $result->fetch_assoc();

            if (password_verify($senha, $usuario['senha'])) {
                login_user($conn, $usuario, $lembrar);

                header('Location: admin/index.php');
                exit;
            } else {
                $erro = 'E-mail ou senha inválidos.';
            }
        } else {
            $erro = 'E-mail ou senha inválidos.';
        }
    }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login Admin | André Puchetti</title>
<?php render_seo_meta(
    'Login Admin | André Puchetti',
    'Acesso restrito ao painel administrativo do sistema de agenda do Salão André Puchetti.',
    ['favicon_path' => 'assets/logo-salao.png', 'robots' => 'noindex, nofollow']
); ?>
  <style>
    :root {
      --bg: #070707;
      --text: #f7f3ea;
      --text-soft: rgba(247,243,234,0.78);
      --gold: #d4af37;
      --gold-soft: #f0d77a;
      --shadow: 0 20px 60px rgba(0,0,0,0.45);
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      min-height: 100vh;
      font-family: Inter, Arial, sans-serif;
      background:
        radial-gradient(circle at 15% 20%, rgba(212,175,55,0.10), transparent 22%),
        radial-gradient(circle at 85% 15%, rgba(212,175,55,0.08), transparent 20%),
        linear-gradient(180deg, #050505 0%, #090909 35%, #0d0d0d 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
      color: var(--text);
    }

    .card {
      width: 100%;
      max-width: 460px;
      padding: 30px 26px;
      border-radius: 28px;
      background: linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.03));
      border: 1px solid rgba(255,255,255,0.08);
      box-shadow: var(--shadow);
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
      position: relative;
      overflow: hidden;
    }

    .card::before {
      content: "";
      position: absolute;
      inset: 0;
      border-radius: inherit;
      padding: 1px;
      background: linear-gradient(135deg, rgba(212,175,55,0.28), rgba(255,255,255,0.03), rgba(212,175,55,0.12));
      -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
      -webkit-mask-composite: xor;
      mask-composite: exclude;
      pointer-events: none;
    }

    .badge {
      display: inline-flex;
      min-height: 38px;
      align-items: center;
      justify-content: center;
      padding: 0 14px;
      border-radius: 999px;
      background: rgba(212,175,55,0.08);
      border: 1px solid rgba(212,175,55,0.18);
      color: var(--gold-soft);
      font-size: 12px;
      font-weight: 800;
      letter-spacing: 0.16em;
      text-transform: uppercase;
      margin-bottom: 18px;
    }

    h1 {
      margin: 0 0 10px;
      font-size: 2rem;
      line-height: 1;
      letter-spacing: -0.04em;
    }

    p {
      margin: 0 0 22px;
      color: var(--text-soft);
      line-height: 1.75;
    }

    .alert {
      margin-bottom: 16px;
      padding: 14px 16px;
      border-radius: 14px;
      background: rgba(255,95,109,0.10);
      border: 1px solid rgba(255,95,109,0.20);
      color: #ffd7dc;
      font-size: 0.95rem;
    }

    .row-actions {
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:14px;
      flex-wrap:wrap;
      margin: 2px 0 18px;
    }

    .remember {
      display:inline-flex;
      align-items:center;
      gap:9px;
      color:var(--text-soft);
      font-size:.92rem;
      letter-spacing:0;
      text-transform:none;
      font-weight:700;
      margin:0;
    }

    .remember input {
      width:18px;
      min-height:18px;
      accent-color:var(--gold);
    }

    .link {
      color:var(--gold-soft);
      text-decoration:none;
      font-size:.92rem;
      font-weight:800;
    }

    .password-wrap {
      position:relative;
    }

    .password-wrap input {
      padding-right:58px;
    }

    .toggle-password {
      position:absolute;
      right:8px;
      top:50%;
      transform:translateY(-50%);
      width:42px;
      height:42px;
      min-height:42px;
      border-radius:12px;
      background:linear-gradient(180deg, rgba(255,255,255,.075), rgba(255,255,255,.035));
      border:1px solid rgba(212,175,55,.16);
      color:var(--gold-soft);
      box-shadow:none;
      display:grid;
      place-items:center;
      padding:0;
    }

    .toggle-password svg {
      width:20px;
      height:20px;
      fill:none;
      stroke:currentColor;
      stroke-width:1.9;
      stroke-linecap:round;
      stroke-linejoin:round;
      filter:drop-shadow(0 0 10px rgba(212,175,55,.16));
    }

    .field {
      margin-bottom: 14px;
    }

    label {
      display: block;
      margin-bottom: 8px;
      color: var(--gold-soft);
      font-size: 12px;
      font-weight: 800;
      letter-spacing: 0.14em;
      text-transform: uppercase;
    }

    input {
      width: 100%;
      min-height: 56px;
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,0.08);
      background: rgba(255,255,255,0.04);
      color: var(--text);
      padding: 0 16px;
      outline: none;
      font-size: 1rem;
    }

    input:focus {
      border-color: rgba(212,175,55,0.38);
      box-shadow: 0 0 0 4px rgba(212,175,55,0.08);
    }

    button {
      width: 100%;
      min-height: 58px;
      border: none;
      cursor: pointer;
      border-radius: 18px;
      background: linear-gradient(90deg, #c8a22a 0%, #f2d778 50%, #cfa72d 100%);
      color: #1a1405;
      font-size: 1rem;
      font-weight: 900;
      letter-spacing: 0.02em;
      box-shadow: 0 16px 34px rgba(212,175,55,0.18);
    }
  </style>
</head>
<body>
  <div class="card">
    <div class="badge">Área administrativa</div>
    <h1>Entrar</h1>
    <p>Acesse sua área interna para acompanhar a agenda e os atendimentos.</p>

    <?php if ($erro): ?>
      <div class="alert"><?= htmlspecialchars($erro); ?></div>
    <?php endif; ?>

    <form method="POST">
      <?= csrf_input(); ?>
      <div class="field">
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" placeholder="seu@email.com" value="<?= htmlspecialchars($emailValor, ENT_QUOTES); ?>" autocomplete="email" required>
      </div>

      <div class="field">
        <label for="senha">Senha</label>
        <div class="password-wrap">
          <input type="password" id="senha" name="senha" placeholder="Sua senha" autocomplete="current-password" required>
          <button class="toggle-password" type="button" id="toggleSenha" aria-label="Mostrar senha">
            <svg class="eye-open" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M2.5 12s3.3-6 9.5-6 9.5 6 9.5 6-3.3 6-9.5 6-9.5-6-9.5-6Z"/>
              <path d="M12 15.2a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4Z"/>
            </svg>
            <svg class="eye-closed" viewBox="0 0 24 24" aria-hidden="true" style="display:none">
              <path d="M3 3l18 18"/>
              <path d="M10.7 5.2A10.5 10.5 0 0 1 12 5c6.2 0 9.5 7 9.5 7a15.8 15.8 0 0 1-3.1 4.1"/>
              <path d="M6.5 6.8C3.8 8.6 2.5 12 2.5 12s3.3 7 9.5 7c1.8 0 3.3-.5 4.6-1.2"/>
              <path d="M9.9 9.9a3.2 3.2 0 0 0 4.2 4.2"/>
            </svg>
          </button>
        </div>
      </div>

      <div class="row-actions">
        <label class="remember" for="lembrar">
          <input type="checkbox" id="lembrar" name="lembrar" value="1">
          Lembrar acesso
        </label>
        <a class="link" href="forgot-password.php">Esqueci a senha</a>
      </div>

      <button type="submit">Entrar no painel</button>
    </form>
  </div>
  <script>
    const senhaInput = document.getElementById('senha');
    const toggleSenha = document.getElementById('toggleSenha');

    toggleSenha.addEventListener('click', () => {
      const showing = senhaInput.type === 'text';
      senhaInput.type = showing ? 'password' : 'text';
      toggleSenha.setAttribute('aria-label', showing ? 'Mostrar senha' : 'Ocultar senha');
      toggleSenha.querySelector('.eye-open').style.display = showing ? '' : 'none';
      toggleSenha.querySelector('.eye-closed').style.display = showing ? 'none' : '';
    });
  </script>
</body>
</html>
