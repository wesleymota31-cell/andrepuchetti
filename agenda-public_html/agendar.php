<?php
require_once 'config.php';
require_once __DIR__ . '/includes/seo.php';

if (!isset($_GET['profissional_id']) || !isset($_GET['servico_id'])) {
    header('Location: index.php');
    exit;
}

$profissional_id = (int) $_GET['profissional_id'];
$servico_id = (int) $_GET['servico_id'];

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

date_default_timezone_set('America/Sao_Paulo');

$dataSelecionada = isset($_GET['data']) ? $_GET['data'] : date('Y-m-d');
$timestampSelecionado = strtotime($dataSelecionada);

if (!$timestampSelecionado || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataSelecionada)) {
    $dataSelecionada = date('Y-m-d');
    $timestampSelecionado = strtotime($dataSelecionada);
}

$inicioMes = date('Y-m-01', $timestampSelecionado);
$primeiroDiaSemana = date('w', strtotime($inicioMes));
$diasNoMes = date('t', strtotime($inicioMes));
$mesAtual = (int) date('m', $timestampSelecionado);
$anoAtual = (int) date('Y', $timestampSelecionado);

$mesAnterior = date('Y-m-d', strtotime('-1 month', $timestampSelecionado));
$proximoMes = date('Y-m-d', strtotime('+1 month', $timestampSelecionado));

$nomeMeses = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];

$horariosBase = [
    '09:00', '09:30', '10:00', '10:30',
    '11:00', '11:30', '12:00', '12:30',
    '13:00', '13:30', '14:00', '14:30',
    '15:00', '15:30', '16:00', '16:30',
    '17:00', '17:30', '18:00', '18:30',
    '19:00', '19:30'
];

$bloqueados = [];
$stmtBloqueios = $conn->prepare("SELECT hora_inicio, hora_fim FROM bloqueios WHERE profissional_id = ? AND data = ?");
$stmtBloqueios->bind_param('is', $profissional_id, $dataSelecionada);
$stmtBloqueios->execute();
$resultBloqueios = $stmtBloqueios->get_result();

if ($resultBloqueios && $resultBloqueios->num_rows > 0) {
    while ($row = $resultBloqueios->fetch_assoc()) {
        $inicio = strtotime($row['hora_inicio']);
        $fim = strtotime($row['hora_fim']);

        foreach ($horariosBase as $hora) {
            $horaTimestamp = strtotime($hora . ':00');
            if ($horaTimestamp >= $inicio && $horaTimestamp < $fim) {
                $bloqueados[] = $hora;
            }
        }
    }
}

$diaSemanaBanco = (int) date('N', strtotime($dataSelecionada));
$stmtBloqueiosRecorrentes = $conn->prepare("
    SELECT hora_inicio, hora_fim
    FROM bloqueios_recorrentes
    WHERE profissional_id = ?
      AND ativo = 1
      AND data_inicio <= ?
      AND (data_fim IS NULL OR data_fim >= ?)
      AND FIND_IN_SET(?, dias_semana)
");
$diaSemanaBancoStr = (string)$diaSemanaBanco;
$stmtBloqueiosRecorrentes->bind_param('isss', $profissional_id, $dataSelecionada, $dataSelecionada, $diaSemanaBancoStr);
$stmtBloqueiosRecorrentes->execute();
$resultBloqueiosRecorrentes = $stmtBloqueiosRecorrentes->get_result();

if ($resultBloqueiosRecorrentes && $resultBloqueiosRecorrentes->num_rows > 0) {
    while ($row = $resultBloqueiosRecorrentes->fetch_assoc()) {
        $inicio = strtotime($row['hora_inicio']);
        $fim = strtotime($row['hora_fim']);

        foreach ($horariosBase as $hora) {
            $horaTimestamp = strtotime($hora . ':00');
            if ($horaTimestamp >= $inicio && $horaTimestamp < $fim) {
                $bloqueados[] = $hora;
            }
        }
    }
}

$ocupados = [];
$stmtAgendamentos = $conn->prepare("
    SELECT hora FROM agendamentos
    WHERE profissional_id = ?
      AND data = ?
      AND status IN ('confirmado', 'pendente')
");
$stmtAgendamentos->bind_param('is', $profissional_id, $dataSelecionada);
$stmtAgendamentos->execute();
$resultAgendamentos = $stmtAgendamentos->get_result();

if ($resultAgendamentos && $resultAgendamentos->num_rows > 0) {
    while ($row = $resultAgendamentos->fetch_assoc()) {
        $ocupados[] = substr($row['hora'], 0, 5);
    }
}

$agora = date('Y-m-d H:i');

function horarioDisponivel($hora, $ocupados, $bloqueados, $dataSelecionada, $agora) {
    $dataHora = $dataSelecionada . ' ' . $hora;

    if (in_array($hora, $ocupados)) {
        return false;
    }

    if (in_array($hora, $bloqueados)) {
        return false;
    }

    if ($dataHora < $agora) {
        return false;
    }

    return true;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Escolher horário | André Puchetti</title>
<?php render_seo_meta(
    'Escolher horário | André Puchetti',
    'Escolha o melhor horário disponível para seu atendimento no Salão André Puchetti.',
    ['favicon_path' => 'assets/logo-salao.png']
); ?>
  <style>
    :root {
      --bg: #070707;
      --bg-soft: #101010;
      --card: rgba(255, 255, 255, 0.06);
      --border: rgba(212, 175, 55, 0.18);
      --border-strong: rgba(212, 175, 55, 0.36);
      --text: #f7f3ea;
      --text-soft: rgba(247, 243, 234, 0.78);
      --text-muted: rgba(247, 243, 234, 0.55);
      --gold: #d4af37;
      --gold-soft: #f0d77a;
      --green: #20c997;
      --green-soft: rgba(32, 201, 151, 0.14);
      --red: #ff5f6d;
      --red-soft: rgba(255, 95, 109, 0.14);
      --shadow: 0 20px 60px rgba(0, 0, 0, 0.45);
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
      overflow-x: hidden;
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
      max-width: 1240px;
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
      -webkit-backdrop-filter: blur(14px);
      transition: 0.25s ease;
    }

    .back-btn:hover {
      transform: translateY(-2px);
      border-color: var(--border-strong);
      box-shadow: 0 0 20px rgba(212,175,55,0.08);
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
      -webkit-backdrop-filter: blur(12px);
    }

    .hero h1 {
      margin: 0;
      font-size: clamp(2.2rem, 5vw, 4.4rem);
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

    .summary-card {
      padding: 24px;
      margin-bottom: 22px;
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 16px;
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

    .calendar-nav {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      margin-bottom: 16px;
    }

    .month-title {
      font-size: 1.1rem;
      font-weight: 800;
      color: var(--text);
    }

    .month-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 42px;
      min-height: 42px;
      border-radius: 12px;
      background: rgba(255,255,255,0.04);
      border: 1px solid rgba(255,255,255,0.08);
      color: var(--text);
      text-decoration: none;
      font-weight: 800;
      transition: 0.25s ease;
    }

    .month-link:hover {
      border-color: var(--border-strong);
      box-shadow: 0 0 20px rgba(212,175,55,0.08);
    }

    .calendar-weekdays,
    .calendar-days {
      display: grid;
      grid-template-columns: repeat(7, 1fr);
      gap: 8px;
    }

    .calendar-weekdays {
      margin-bottom: 10px;
    }

    .weekday {
      text-align: center;
      color: var(--gold-soft);
      font-size: 11px;
      font-weight: 800;
      letter-spacing: 0.14em;
      text-transform: uppercase;
      padding: 8px 0;
    }

    .day-cell {
      min-height: 74px;
      border-radius: 16px;
      background: rgba(255,255,255,0.03);
      border: 1px solid rgba(255,255,255,0.06);
      display: flex;
      align-items: flex-start;
      justify-content: flex-start;
      padding: 12px;
      color: var(--text-soft);
      text-decoration: none;
      transition: 0.25s ease;
    }

    .day-cell:hover {
      transform: translateY(-2px);
      border-color: var(--border-strong);
    }

    .day-cell.empty {
      background: transparent;
      border: 1px dashed rgba(255,255,255,0.04);
      pointer-events: none;
    }

    .day-cell.selected {
      background: linear-gradient(180deg, rgba(212,175,55,0.18), rgba(255,255,255,0.03));
      border-color: rgba(212,175,55,0.40);
      color: var(--gold-soft);
      box-shadow: 0 0 0 1px rgba(212,175,55,0.12);
    }

    .day-cell.today {
      outline: 1px solid rgba(212,175,55,0.18);
    }

    .slots-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 12px;
      margin-top: 18px;
    }

    .slot {
      min-height: 58px;
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,0.08);
      display: flex;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 12px;
      font-weight: 800;
      font-size: 0.95rem;
      transition: 0.28s ease;
      text-decoration: none;
      position: relative;
      overflow: hidden;
    }

    .slot::before {
      content: "";
      position: absolute;
      inset: 0;
      background: linear-gradient(120deg, transparent 0%, rgba(255,255,255,0.07) 50%, transparent 100%);
      transform: translateX(-120%);
      transition: transform 0.5s ease;
    }

    .slot.available {
      color: #dffff6;
      background: linear-gradient(180deg, var(--green-soft), rgba(255,255,255,0.03));
      border-color: rgba(32, 201, 151, 0.26);
      box-shadow: 0 0 18px rgba(32, 201, 151, 0.06);
      cursor: pointer;
    }

    .slot.available:hover {
      transform: translateY(-2px);
      box-shadow: 0 0 24px rgba(32, 201, 151, 0.12);
      border-color: rgba(32, 201, 151, 0.34);
    }

    .slot.available:hover::before {
      transform: translateX(120%);
    }

    .slot.available.is-loading {
      transform: scale(0.98);
      opacity: 0.75;
      pointer-events: none;
    }

    .slot.unavailable {
      color: rgba(255,255,255,0.42);
      background: linear-gradient(180deg, var(--red-soft), rgba(255,255,255,0.02));
      border-color: rgba(255, 95, 109, 0.22);
      cursor: not-allowed;
    }

    .legend {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      margin-top: 18px;
    }

    .legend-item {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: var(--text-muted);
      font-size: 0.92rem;
    }

    .legend-dot {
      width: 12px;
      height: 12px;
      border-radius: 50%;
    }

    .legend-dot.available {
      background: var(--green);
      box-shadow: 0 0 12px rgba(32, 201, 151, 0.28);
    }

    .legend-dot.unavailable {
      background: var(--red);
      box-shadow: 0 0 12px rgba(255, 95, 109, 0.18);
    }

    .info-box {
      margin-top: 18px;
      padding: 18px;
      border-radius: 18px;
      background: linear-gradient(135deg, rgba(212,175,55,0.08), rgba(255,255,255,0.02));
      border: 1px solid rgba(212,175,55,0.16);
    }

    .info-box strong {
      display: block;
      margin-bottom: 6px;
      color: var(--text);
    }

    .info-box p {
      margin: 0;
      color: var(--text-muted);
      line-height: 1.75;
      font-size: 0.95rem;
    }

    /* Overlay bonito */
    .booking-overlay {
      position: fixed;
      inset: 0;
      z-index: 9999;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
      background: rgba(5, 5, 5, 0.72);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      opacity: 0;
      visibility: hidden;
      transition: 0.35s ease;
    }

    .booking-overlay.active {
      opacity: 1;
      visibility: visible;
    }

    .booking-modal {
      width: 100%;
      max-width: 460px;
      padding: 30px 28px;
      border-radius: 26px;
      background: linear-gradient(180deg, rgba(255,255,255,0.08), rgba(255,255,255,0.04));
      border: 1px solid rgba(255,255,255,0.10);
      box-shadow: 0 24px 70px rgba(0,0,0,0.40);
      text-align: center;
      position: relative;
      overflow: hidden;
    }

    .booking-modal::before {
      content: "";
      position: absolute;
      inset: 0;
      border-radius: inherit;
      padding: 1px;
      background: linear-gradient(135deg, rgba(212,175,55,0.38), rgba(255,255,255,0.04), rgba(212,175,55,0.20));
      -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
      -webkit-mask-composite: xor;
      mask-composite: exclude;
      pointer-events: none;
    }

    .loader-ring {
      width: 72px;
      height: 72px;
      margin: 0 auto 20px;
      border-radius: 50%;
      border: 3px solid rgba(255,255,255,0.08);
      border-top-color: var(--gold);
      animation: spin 0.9s linear infinite;
      box-shadow: 0 0 28px rgba(212,175,55,0.14);
    }

    .booking-modal h3 {
      margin: 0 0 10px;
      font-size: 1.55rem;
      letter-spacing: -0.03em;
      color: var(--text);
    }

    .booking-modal p {
      margin: 0;
      color: var(--text-soft);
      line-height: 1.8;
      font-size: 0.98rem;
    }

    .booking-selected {
      margin-top: 18px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 42px;
      padding: 0 16px;
      border-radius: 999px;
      background: rgba(212,175,55,0.10);
      border: 1px solid rgba(212,175,55,0.18);
      color: var(--gold-soft);
      font-size: 0.92rem;
      font-weight: 800;
      letter-spacing: 0.04em;
    }

    @keyframes spin {
      to { transform: rotate(360deg); }
    }

    @media (max-width: 980px) {
      .summary-card,
      .main-grid {
        grid-template-columns: 1fr;
      }

      .slots-grid {
        grid-template-columns: repeat(2, 1fr);
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

      .panel,
      .summary-card {
        padding: 20px;
      }

      .slots-grid {
        grid-template-columns: 1fr;
      }

      .day-cell {
        min-height: 58px;
        padding: 10px;
      }

      .booking-modal {
        padding: 24px 20px;
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

      <a href="index.php" class="back-btn">Voltar</a>
    </div>

    <div class="hero">
      <span class="hero-badge">Escolha a data e o horário</span>
      <h1>
        Selecione seu
        <span>atendimento</span>
      </h1>
      <p>
        Agora escolha o melhor dia e horário disponível para continuar seu agendamento.
      </p>
    </div>

    <div class="summary-card glass-card">
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
    </div>

    <div class="main-grid">
      <div class="panel glass-card">
        <h2>Calendário</h2>
        <p class="panel-subtitle">
          Escolha um dia para visualizar os horários disponíveis.
        </p>

        <div class="calendar-nav">
          <a class="month-link" href="?profissional_id=<?= $profissional_id; ?>&servico_id=<?= $servico_id; ?>&data=<?= $mesAnterior; ?>">‹</a>
          <div class="month-title"><?= $nomeMeses[$mesAtual]; ?> de <?= $anoAtual; ?></div>
          <a class="month-link" href="?profissional_id=<?= $profissional_id; ?>&servico_id=<?= $servico_id; ?>&data=<?= $proximoMes; ?>">›</a>
        </div>

        <div class="calendar-weekdays">
          <div class="weekday">Dom</div>
          <div class="weekday">Seg</div>
          <div class="weekday">Ter</div>
          <div class="weekday">Qua</div>
          <div class="weekday">Qui</div>
          <div class="weekday">Sex</div>
          <div class="weekday">Sáb</div>
        </div>

        <div class="calendar-days">
          <?php for ($i = 0; $i < $primeiroDiaSemana; $i++): ?>
            <div class="day-cell empty"></div>
          <?php endfor; ?>

          <?php for ($dia = 1; $dia <= $diasNoMes; $dia++): ?>
            <?php
              $dataDia = sprintf('%04d-%02d-%02d', $anoAtual, $mesAtual, $dia);
              $isSelected = ($dataDia === $dataSelecionada);
              $isToday = ($dataDia === date('Y-m-d'));
            ?>
            <a class="day-cell <?= $isSelected ? 'selected' : ''; ?> <?= $isToday ? 'today' : ''; ?>"
               href="?profissional_id=<?= $profissional_id; ?>&servico_id=<?= $servico_id; ?>&data=<?= $dataDia; ?>">
              <?= $dia; ?>
            </a>
          <?php endfor; ?>
        </div>
      </div>

      <div class="panel glass-card">
        <h2>Horários do dia</h2>
        <p class="panel-subtitle">
          <?= date('d/m/Y', strtotime($dataSelecionada)); ?> • Escolha um horário para continuar.
        </p>

        <div class="slots-grid">
          <?php foreach ($horariosBase as $hora): ?>
            <?php $disponivel = horarioDisponivel($hora, $ocupados, $bloqueados, $dataSelecionada, $agora); ?>

            <?php if ($disponivel): ?>
              <a class="slot available js-slot"
                 href="confirmar.php?profissional_id=<?= $profissional_id; ?>&servico_id=<?= $servico_id; ?>&data=<?= $dataSelecionada; ?>&hora=<?= $hora; ?>"
                 data-hora="<?= htmlspecialchars($hora); ?>">
                <?= $hora; ?>
              </a>
            <?php else: ?>
              <div class="slot unavailable"><?= $hora; ?></div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>

        <div class="legend">
          <div class="legend-item">
            <span class="legend-dot available"></span>
            Disponível
          </div>
          <div class="legend-item">
            <span class="legend-dot unavailable"></span>
            Indisponível
          </div>
        </div>

        <div class="info-box">
          <strong>Próximo passo</strong>
          <p>
            Ao clicar em um horário disponível, vamos abrir a etapa final de confirmação do agendamento.
          </p>
        </div>
      </div>
    </div>
  </div>

  <!-- Overlay loading -->
  <div class="booking-overlay" id="bookingOverlay">
    <div class="booking-modal">
      <div class="loader-ring"></div>
      <h3>Confirmando seu horário...</h3>
      <p>
        Estamos preparando a próxima etapa do seu agendamento com <?= htmlspecialchars($profissional['nome']); ?>.
      </p>
      <div class="booking-selected" id="selectedTimeText">Horário selecionado</div>
    </div>
  </div>

  <script>
    const slots = document.querySelectorAll('.js-slot');
    const overlay = document.getElementById('bookingOverlay');
    const selectedTimeText = document.getElementById('selectedTimeText');

    slots.forEach(slot => {
      slot.addEventListener('click', function (e) {
        e.preventDefault();

        const href = this.getAttribute('href');
        const hora = this.getAttribute('data-hora') || '';
        selectedTimeText.textContent = `Horário selecionado: ${hora}`;

        document.querySelectorAll('.js-slot').forEach(item => item.classList.remove('is-loading'));
        this.classList.add('is-loading');

        overlay.classList.add('active');

        setTimeout(() => {
          window.location.href = href;
        }, 2200);
      });
    });
  </script>
</body>
</html>
