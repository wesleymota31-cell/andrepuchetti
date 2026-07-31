<?php
require_once 'config.php';
require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/phone.php';

date_default_timezone_set('America/Sao_Paulo');

if (
    !isset($_GET['profissional_id']) ||
    !isset($_GET['servico_id']) ||
    !isset($_GET['data']) ||
    !isset($_GET['hora'])
) {
    header('Location: index.php');
    exit;
}

$profissional_id = (int) $_GET['profissional_id'];
$servico_id = (int) $_GET['servico_id'];
$dataSelecionada = $_GET['data'];
$horaSelecionada = $_GET['hora'];

$validDate = DateTime::createFromFormat('Y-m-d', $dataSelecionada);
$validTime = DateTime::createFromFormat('H:i', $horaSelecionada);

if (
    !$validDate || $validDate->format('Y-m-d') !== $dataSelecionada ||
    !$validTime || $validTime->format('H:i') !== $horaSelecionada
) {
    header('Location: index.php');
    exit;
}

$stmtProfissional = $conn->prepare("SELECT * FROM profissionais WHERE id = ? LIMIT 1");
$stmtProfissional->bind_param('i', $profissional_id);
$stmtProfissional->execute();
$profissional = $stmtProfissional->get_result()->fetch_assoc();

$stmtServico = $conn->prepare("SELECT * FROM servicos WHERE id = ? LIMIT 1");
$stmtServico->bind_param('i', $servico_id);
$stmtServico->execute();
$servico = $stmtServico->get_result()->fetch_assoc();

if (!$profissional || !$servico) {
    header('Location: index.php');
    exit;
}

$erro = '';
$sucesso = '';
$clienteNome = '';
$clienteTelefone = '';

function limparTelefone($telefone) {
    return preg_replace('/\D+/', '', $telefone);
}

function servicoPrecisaAnaliseConfirmar(string $nome): bool
{
    $nome = strtolower(trim($nome));
    $nome = strtr($nome, [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
        'é' => 'e', 'ê' => 'e',
        'í' => 'i',
        'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u',
        'ç' => 'c',
    ]);

    return strpos($nome, 'botox feminino') !== false
        || strpos($nome, 'progressiva feminino') !== false
        || strpos($nome, 'platinado feminino') !== false
        || strpos($nome, 'escova feminino') !== false
        || strpos($nome, 'hidratacao feminino') !== false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clienteNome = trim($_POST['nome'] ?? '');
    $clienteTelefone = limparTelefone($_POST['telefone'] ?? '');

    if ($clienteNome === '' || $clienteTelefone === '') {
        $erro = 'Preencha nome e WhatsApp para continuar.';
    } else {
        $stmtCheck = $conn->prepare("
            SELECT id FROM agendamentos
            WHERE profissional_id = ?
              AND data = ?
              AND hora = ?
              AND status IN ('confirmado', 'pendente')
            LIMIT 1
        ");
        $horaBanco = $horaSelecionada . ':00';
        $stmtCheck->bind_param('iss', $profissional_id, $dataSelecionada, $horaBanco);
        $stmtCheck->execute();
        $resultCheck = $stmtCheck->get_result();

        if ($resultCheck && $resultCheck->num_rows > 0) {
            $erro = 'Esse horário acabou de ser reservado. Escolha outro.';
        } else {
            $clienteResult = obterOuCriarClientePorWhatsapp($conn, $clienteNome, $clienteTelefone);

            if (!$clienteResult['ok']) {
                $erro = $clienteResult['error'] ?: 'Não foi possível cadastrar o cliente agora.';
            } else {
                $cliente_id = (int)$clienteResult['id'];

                $status = 'confirmado';
                $stmtAgendamento = $conn->prepare("
                    INSERT INTO agendamentos (cliente_id, profissional_id, servico_id, data, hora, status)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmtAgendamento->bind_param(
                    'iiisss',
                    $cliente_id,
                    $profissional_id,
                    $servico_id,
                    $dataSelecionada,
                    $horaBanco,
                    $status
                );

                if ($stmtAgendamento->execute()) {
                    $sucesso = 'Agendamento realizado com sucesso.';
                } else {
                    $erro = 'Não foi possível salvar o agendamento agora. Tente novamente.';
                }
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
  <title>Confirmar agendamento | André Puchetti</title>
<?php render_seo_meta(
    'Confirmar agendamento | André Puchetti',
    'Revise e confirme os dados do seu agendamento no Salão André Puchetti.',
    ['favicon_path' => 'assets/logo-salao.png', 'robots' => 'noindex, nofollow']
); ?>
  <style>
    :root {
      --bg: #070707;
      --card: rgba(255,255,255,0.06);
      --border: rgba(212,175,55,0.18);
      --border-strong: rgba(212,175,55,0.36);
      --text: #f7f3ea;
      --text-soft: rgba(247,243,234,0.78);
      --text-muted: rgba(247,243,234,0.55);
      --gold: #d4af37;
      --gold-soft: #f0d77a;
      --green: #20c997;
      --red: #ff5f6d;
      --shadow: 0 20px 60px rgba(0,0,0,0.45);
      --radius-xl: 28px;
      --radius-lg: 22px;
      --radius-md: 16px;
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      font-family: Inter, Arial, sans-serif;
      background:
        radial-gradient(circle at 15% 20%, rgba(212,175,55,0.10), transparent 22%),
        radial-gradient(circle at 85% 15%, rgba(212,175,55,0.08), transparent 20%),
        linear-gradient(180deg, #050505 0%, #090909 35%, #0d0d0d 100%);
      color: var(--text);
      min-height: 100vh;
    }

    .bg-grid {
      position: fixed;
      inset: 0;
      pointer-events: none;
      opacity: 0.18;
      background-image:
        linear-gradient(rgba(212,175,55,0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(212,175,55,0.04) 1px, transparent 1px);
      background-size: 42px 42px;
      mask-image: linear-gradient(to bottom, rgba(0,0,0,.85), transparent 95%);
      z-index: 0;
    }

    .container {
      width: 100%;
      max-width: 1120px;
      margin: 0 auto;
      padding: 40px 20px 70px;
      position: relative;
      z-index: 2;
    }

    .topbar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      margin-bottom: 32px;
    }

    .brand {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .brand small {
      color: var(--gold-soft);
      letter-spacing: 0.18em;
      font-size: 11px;
      font-weight: 800;
      text-transform: uppercase;
    }

    .brand strong {
      font-size: 1.2rem;
      font-weight: 800;
    }

    .back-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 44px;
      padding: 0 18px;
      border-radius: 999px;
      background: rgba(255,255,255,0.05);
      color: var(--text);
      border: 1px solid rgba(255,255,255,0.08);
      text-decoration: none;
      font-weight: 700;
      backdrop-filter: blur(14px);
    }

    .hero {
      text-align: center;
      margin-bottom: 28px;
    }

    .hero-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 40px;
      padding: 0 16px;
      border-radius: 999px;
      background: rgba(212,175,55,0.08);
      border: 1px solid rgba(212,175,55,0.18);
      color: var(--gold-soft);
      font-size: 12px;
      font-weight: 800;
      letter-spacing: 0.18em;
      text-transform: uppercase;
      margin-bottom: 18px;
      backdrop-filter: blur(12px);
    }

    .hero h1 {
      margin: 0;
      font-size: clamp(2.1rem, 5vw, 4.2rem);
      line-height: 0.96;
      letter-spacing: -0.05em;
      font-weight: 900;
    }

    .hero h1 span {
      display: block;
      background: linear-gradient(90deg, #fff4cc 0%, #d4af37 55%, #fff0a8 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      color: transparent;
    }

    .hero p {
      max-width: 760px;
      margin: 18px auto 0;
      color: var(--text-soft);
      font-size: 1rem;
      line-height: 1.85;
    }

    .glass-card {
      background: linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.03));
      border: 1px solid rgba(255,255,255,0.08);
      box-shadow: var(--shadow);
      border-radius: var(--radius-xl);
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
    }

    .main-grid {
      display: grid;
      grid-template-columns: 0.95fr 1.05fr;
      gap: 22px;
    }

    .panel {
      padding: 26px;
      position: relative;
      overflow: hidden;
    }

    .panel::before {
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

    .panel h2 {
      margin: 0 0 6px;
      font-size: 1.35rem;
      letter-spacing: -0.03em;
    }

    .panel-subtitle {
      margin: 0 0 22px;
      color: var(--text-muted);
      line-height: 1.75;
    }

    .summary-box {
      display: grid;
      gap: 14px;
    }

    .summary-item {
      padding: 18px;
      border-radius: var(--radius-lg);
      background: rgba(255,255,255,0.03);
      border: 1px solid rgba(255,255,255,0.07);
    }

    .summary-item small {
      display: block;
      color: var(--gold-soft);
      margin-bottom: 8px;
      font-size: 11px;
      letter-spacing: 0.16em;
      font-weight: 800;
      text-transform: uppercase;
    }

    .summary-item strong {
      display: block;
      font-size: 1rem;
      color: var(--text);
    }

    .alert {
      margin-bottom: 18px;
      padding: 16px 18px;
      border-radius: 16px;
      font-size: 0.95rem;
      line-height: 1.7;
      font-weight: 600;
    }

    .alert.error {
      background: rgba(255,95,109,0.10);
      border: 1px solid rgba(255,95,109,0.20);
      color: #ffd7dc;
    }

    .alert.success {
      background: rgba(32,201,151,0.10);
      border: 1px solid rgba(32,201,151,0.22);
      color: #d8fff2;
    }

    .field {
      margin-bottom: 16px;
    }

    .field label {
      display: block;
      margin-bottom: 8px;
      color: var(--gold-soft);
      font-size: 12px;
      font-weight: 800;
      letter-spacing: 0.14em;
      text-transform: uppercase;
    }

    .field input {
      width: 100%;
      min-height: 56px;
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,0.08);
      background: rgba(255,255,255,0.04);
      color: var(--text);
      padding: 0 16px;
      outline: none;
      font-size: 1rem;
      transition: 0.25s ease;
    }

    .field input:focus {
      border-color: rgba(212,175,55,0.38);
      box-shadow: 0 0 0 4px rgba(212,175,55,0.08);
    }

    .submit-btn {
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
      transition: transform .28s ease, box-shadow .28s ease;
      box-shadow: 0 16px 34px rgba(212,175,55,0.18);
    }

    .submit-btn:hover {
      transform: translateY(-3px);
      box-shadow: 0 22px 40px rgba(212,175,55,0.24);
    }

    .actions {
      display: flex;
      gap: 12px;
      margin-top: 14px;
    }

    .secondary-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 52px;
      padding: 0 18px;
      border-radius: 16px;
      background: rgba(255,255,255,0.04);
      color: var(--text);
      border: 1px solid rgba(255,255,255,0.08);
      text-decoration: none;
      font-weight: 700;
      flex: 1;
    }

    @media (max-width: 980px) {
      .main-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 640px) {
      .container {
        padding: 24px 16px 60px;
      }

      .topbar {
        flex-direction: column;
        align-items: flex-start;
      }

      .panel {
        padding: 20px;
      }

      .actions {
        flex-direction: column;
      }
    }
  </style>
</head>
<body>
  <div class="bg-grid"></div>

  <div class="container">
    <div class="topbar">
      <div class="brand">
        <small>Sistema de agendamento</small>
        <strong>André Puchetti</strong>
      </div>

      <a class="back-btn" href="agendar.php?profissional_id=<?= $profissional_id; ?>&servico_id=<?= $servico_id; ?>&data=<?= htmlspecialchars($dataSelecionada); ?>">Voltar</a>
    </div>

    <div class="hero">
      <span class="hero-badge">Confirmação do agendamento</span>
      <h1>
        Finalize seu
        <span>horário</span>
      </h1>
      <p>
        Preencha seus dados para confirmar o atendimento.
      </p>
    </div>

    <div class="main-grid">
      <div class="panel glass-card">
        <h2>Resumo do agendamento</h2>
        <p class="panel-subtitle">
          Confira os dados antes de concluir.
        </p>

        <div class="summary-box">
          <div class="summary-item">
            <small>Profissional</small>
            <strong><?= htmlspecialchars($profissional['nome']); ?></strong>
          </div>

          <div class="summary-item">
            <small>Serviço</small>
            <strong><?= htmlspecialchars($servico['nome']); ?></strong>
          </div>

          <div class="summary-item">
            <small>Duração</small>
            <strong><?= (int)$servico['duracao']; ?> minutos</strong>
          </div>

          <div class="summary-item">
            <small>Data</small>
            <strong><?= date('d/m/Y', strtotime($dataSelecionada)); ?></strong>
          </div>

          <div class="summary-item">
            <small>Horário</small>
            <strong><?= htmlspecialchars($horaSelecionada); ?></strong>
          </div>

          <div class="summary-item">
            <small>Valor</small>
            <strong>
              <?= servicoPrecisaAnaliseConfirmar($servico['nome'])
                ? 'Valor após análise'
                : 'R$ ' . number_format((float)$servico['preco'], 2, ',', '.'); ?>
            </strong>
          </div>
        </div>
      </div>

      <div class="panel glass-card">
        <h2>Seus dados</h2>
        <p class="panel-subtitle">
          Usamos essas informações para identificar seu agendamento.
        </p>

        <?php if ($erro): ?>
          <div class="alert error"><?= htmlspecialchars($erro); ?></div>
        <?php endif; ?>

        <?php if ($sucesso): ?>
          <div class="alert success"><?= htmlspecialchars($sucesso); ?></div>

          <div class="actions">
            <a class="secondary-btn" href="index.php">Novo agendamento</a>
            <a class="secondary-btn" href="agendar.php?profissional_id=<?= $profissional_id; ?>&servico_id=<?= $servico_id; ?>&data=<?= htmlspecialchars($dataSelecionada); ?>">Ver mais horários</a>
          </div>
        <?php else: ?>
          <form method="POST">
            <div class="field">
              <label for="nome">Nome</label>
              <input type="text" id="nome" name="nome" placeholder="Seu nome completo" value="<?= htmlspecialchars($clienteNome); ?>" required>
            </div>

            <div class="field">
              <label for="telefone">WhatsApp</label>
              <input type="text" id="telefone" name="telefone" placeholder="(11) 99999-9999" value="<?= htmlspecialchars($clienteTelefone); ?>" required>
            </div>

            <button type="submit" class="submit-btn">Confirmar agendamento</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>
