<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/client-auth.php';
require_once __DIR__ . '/includes/phone.php';
require_once __DIR__ . '/includes/mailer.php';

date_default_timezone_set('America/Sao_Paulo');

$erro = '';
$modo = $_POST['modo'] ?? 'entrar';
$emailValor = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $erro = 'Sessão expirada. Tente novamente.';
    } else {
        $modo = $_POST['modo'] === 'criar' ? 'criar' : 'entrar';
        $email = normalize_email($_POST['email'] ?? '');
        $senha = $_POST['senha'] ?? '';
        $emailValor = $email;

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($senha) < 6) {
            $erro = 'Informe um e-mail válido e senha com pelo menos 6 caracteres.';
        } elseif ($modo === 'criar') {
            $nome = trim($_POST['nome'] ?? '');
            $telefoneNormalizado = normalizarTelefoneCliente($_POST['telefone'] ?? '');
            $telefone = $telefoneNormalizado['valid'] ? $telefoneNormalizado['display_phone'] : '';

            if ($nome === '' || $telefone === '') {
                $erro = 'Informe nome e WhatsApp para criar sua conta.';
            } else {
                $stmt = $conn->prepare("SELECT id FROM clientes WHERE email = ? LIMIT 1");
                $stmt->bind_param('s', $email);
                $stmt->execute();
                if ($stmt->get_result()->fetch_assoc()) {
                    $erro = 'Já existe uma conta com esse e-mail.';
                } else {
                    $hash = password_hash($senha, PASSWORD_DEFAULT);
                    $clienteResult = obterOuCriarClientePorWhatsapp($conn, $nome, $telefone, $email, $hash);

                    if (!$clienteResult['ok']) {
                        $erro = $clienteResult['error'] ?: 'Não foi possível criar sua conta agora.';
                    } else {
                        $clienteId = (int)$clienteResult['id'];
                        agenda_send_client_welcome_email($email, $nome);
                        cliente_login($conn, ['id' => $clienteId, 'nome' => $nome, 'email' => $email]);
                        header('Location: cliente.php');
                        exit;
                    }
                }
            }
        } else {
            $stmt = $conn->prepare("SELECT id, nome, email, senha FROM clientes WHERE email = ? LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $cliente = $stmt->get_result()->fetch_assoc();

            if ($cliente && !empty($cliente['senha']) && password_verify($senha, $cliente['senha'])) {
                cliente_login($conn, $cliente);
                header('Location: cliente.php');
                exit;
            }

            $erro = 'E-mail ou senha inválidos.';
        }
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Área do cliente | André Puchetti</title>
  <style>
    :root{--bg:#070707;--panel:#121212;--gold:#d4af37;--soft:#d8d0bd;--text:#fff8e7}
    *{box-sizing:border-box} body{margin:0;min-height:100vh;background:radial-gradient(circle at top,#181406,#070707 46%);color:var(--text);font-family:Inter,Arial,sans-serif;display:grid;place-items:center;padding:22px}
    .box{width:min(460px,100%);border:1px solid rgba(212,175,55,.22);background:rgba(18,18,18,.88);border-radius:24px;padding:26px;box-shadow:0 24px 60px rgba(0,0,0,.34)}
    .brand{display:grid;place-items:center;margin-bottom:18px}.brand img{width:96px}
    h1{margin:0;text-align:center;font-size:clamp(2rem,9vw,3.2rem);line-height:.98;color:#fff1bd}p{color:var(--soft);text-align:center;line-height:1.6}
    .tabs{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:20px 0}.tabs button{border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.04);color:var(--text);border-radius:14px;padding:12px;font-weight:800}.tabs button.active{background:rgba(212,175,55,.16);border-color:rgba(212,175,55,.38)}
    .field{display:grid;gap:8px;margin-top:14px}label{font-size:12px;color:#f1d989;font-weight:800;text-transform:uppercase;letter-spacing:.08em}input{width:100%;min-height:52px;border-radius:16px;border:1px solid rgba(255,255,255,.10);background:#0d0d0d;color:var(--text);padding:0 15px;font-size:16px}
    .password-wrap{position:relative}.password-wrap input{padding-right:54px}.toggle-password{position:absolute;right:8px;top:50%;transform:translateY(-50%);width:38px;height:38px;border:0;border-radius:12px;background:rgba(255,255,255,.06);color:#f1d989;display:grid;place-items:center;cursor:pointer}.toggle-password svg{width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}.login-options{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:14px;flex-wrap:wrap}.remember{display:flex;align-items:center;gap:8px;color:var(--soft);font-weight:800;font-size:14px}.remember input{width:18px;height:18px;min-height:18px;accent-color:var(--gold);padding:0}.forgot{color:#f1d989;text-decoration:none;font-weight:900;font-size:14px}.forgot:hover{text-decoration:underline}
    button[type=submit],.link-btn{width:100%;min-height:56px;border:0;border-radius:18px;margin-top:20px;background:linear-gradient(90deg,#f7e7af,#d4af37,#a87908);color:#181510;font-weight:900;font-size:16px;text-decoration:none;display:grid;place-items:center}
    .erro{margin-top:16px;padding:12px;border-radius:14px;background:rgba(255,95,109,.12);color:#ffd8dd;text-align:center}.hidden{display:none}
  </style>
</head>
<body>
  <main class="box">
    <div class="brand"><img src="assets/logo-salao.png" alt="André Puchetti"></div>
    <h1>Área do cliente</h1>
    <p>Entre para acompanhar, agendar, cancelar ou remarcar seus horários.</p>
    <div class="tabs">
      <button type="button" id="tabEntrar" class="<?= $modo !== 'criar' ? 'active' : ''; ?>">Entrar</button>
      <button type="button" id="tabCriar" class="<?= $modo === 'criar' ? 'active' : ''; ?>">Criar conta</button>
    </div>
    <?php if ($erro): ?><div class="erro"><?= htmlspecialchars($erro); ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_input(); ?>
      <input type="hidden" name="modo" id="modo" value="<?= htmlspecialchars($modo); ?>">
      <div id="extraCadastro" class="<?= $modo === 'criar' ? '' : 'hidden'; ?>">
        <div class="field"><label>Nome</label><input name="nome" autocomplete="name"></div>
        <div class="field"><label>WhatsApp</label><input name="telefone" id="telefone" autocomplete="tel" inputmode="numeric" maxlength="16" placeholder="(11) 99999-9999"></div>
      </div>
      <div class="field"><label>E-mail</label><input type="email" name="email" value="<?= htmlspecialchars($emailValor); ?>" autocomplete="email" required></div>
      <div class="field">
        <label>Senha</label>
        <div class="password-wrap">
          <input type="password" name="senha" id="senha" autocomplete="current-password" required>
          <button type="button" class="toggle-password" id="toggleSenha" aria-label="Mostrar senha">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"></path>
              <circle cx="12" cy="12" r="3"></circle>
            </svg>
          </button>
        </div>
      </div>
      <div class="login-options">
        <label class="remember"><input type="checkbox" id="lembrarDados"> Lembrar meus dados</label>
        <a class="forgot" href="cliente-forgot-password.php">Esqueci minha senha</a>
      </div>
      <button type="submit">Continuar</button>
    </form>
  </main>
  <script>
    const modo=document.getElementById('modo'), extra=document.getElementById('extraCadastro'), e=document.getElementById('tabEntrar'), c=document.getElementById('tabCriar'), telefone=document.getElementById('telefone'), senha=document.getElementById('senha'), toggleSenha=document.getElementById('toggleSenha'), lembrarDados=document.getElementById('lembrarDados'), emailInput=document.querySelector('input[name="email"]'), nomeInput=document.querySelector('input[name="nome"]');
    function setMode(v){modo.value=v; extra.classList.toggle('hidden',v!=='criar'); e.classList.toggle('active',v==='entrar'); c.classList.toggle('active',v==='criar')}
    e.onclick=()=>setMode('entrar'); c.onclick=()=>setMode('criar');

    try {
      const saved = JSON.parse(localStorage.getItem('cliente_login_dados') || '{}');
      if (saved.email) {
        emailInput.value = saved.email;
        lembrarDados.checked = true;
      }
      if (saved.nome && nomeInput) nomeInput.value = saved.nome;
      if (saved.telefone && telefone) telefone.value = saved.telefone;
    } catch (error) {}

    function formatPhone(value) {
      let digits = value.replace(/\D/g, '');

      if (digits.startsWith('55') && digits.length > 11) {
        digits = digits.slice(2);
      }

      digits = digits.slice(0, 11);

      if (digits.length > 10) {
        return digits.replace(/^(\d{2})(\d{5})(\d{0,4}).*/, '($1) $2-$3');
      }

      if (digits.length > 6) {
        return digits.replace(/^(\d{2})(\d{4})(\d{0,4}).*/, '($1) $2-$3');
      }

      if (digits.length > 2) {
        return digits.replace(/^(\d{2})(\d{0,5})/, '($1) $2');
      }

      if (digits.length > 0) {
        return digits.replace(/^(\d*)/, '($1');
      }

      return '';
    }

    telefone.addEventListener('input', () => {
      telefone.value = formatPhone(telefone.value);
    });

    toggleSenha.addEventListener('click', () => {
      const showing = senha.type === 'text';
      senha.type = showing ? 'password' : 'text';
      toggleSenha.setAttribute('aria-label', showing ? 'Mostrar senha' : 'Ocultar senha');
      toggleSenha.innerHTML = showing
        ? '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>'
        : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17.9 17.9A10.8 10.8 0 0 1 12 19C5.5 19 2 12 2 12a18.6 18.6 0 0 1 4.1-5.1"></path><path d="M9.9 4.2A10.4 10.4 0 0 1 12 4c6.5 0 10 8 10 8a18.7 18.7 0 0 1-2.2 3.2"></path><path d="M14.1 14.1a3 3 0 0 1-4.2-4.2"></path><path d="M3 3l18 18"></path></svg>';
    });

    document.querySelector('form').addEventListener('submit', () => {
      if (lembrarDados.checked) {
        localStorage.setItem('cliente_login_dados', JSON.stringify({
          email: emailInput.value.trim(),
          nome: nomeInput ? nomeInput.value.trim() : '',
          telefone: telefone ? telefone.value.trim() : ''
        }));
      } else {
        localStorage.removeItem('cliente_login_dados');
      }
    });
  </script>
</body>
</html>
