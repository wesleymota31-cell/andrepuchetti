<?php
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/admin-shell.php';

date_default_timezone_set('America/Sao_Paulo');

$data = $_GET['data'] ?? date('Y-m-d');
$profissionalFiltro = $_GET['profissional_id'] ?? 'todos';
$flash = $_GET['flash'] ?? '';
$msg = $_GET['msg'] ?? '';

$timestamp = strtotime($data);
if (!$timestamp) {
    $data = date('Y-m-d');
    $timestamp = strtotime($data);
}

$dataAnterior = date('Y-m-d', strtotime('-1 day', $timestamp));
$proximaData = date('Y-m-d', strtotime('+1 day', $timestamp));

$mesAtual = (int) date('m', $timestamp);
$anoAtual = (int) date('Y', $timestamp);
$primeiroDiaMes = date('Y-m-01', $timestamp);
$primeiroDiaSemana = (int) date('w', strtotime($primeiroDiaMes));
$diasNoMes = (int) date('t', strtotime($primeiroDiaMes));

$nomeMeses = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];

$inicioAgenda = 7;
$fimAgenda = 22;
$slotAltura = 42;
$totalSlots = ($fimAgenda - $inicioAgenda) * 2;
$alturaTimeline = $totalSlots * $slotAltura;

$profissionais = [];
$resProf = $conn->query("SELECT id, nome FROM profissionais ORDER BY id ASC");
if ($resProf && $resProf->num_rows > 0) {
    while ($row = $resProf->fetch_assoc()) {
        $profissionais[] = $row;
    }
}

$servicos = [];
$resServicos = $conn->query("SELECT id, nome, duracao, preco FROM servicos ORDER BY nome ASC");
if ($resServicos && $resServicos->num_rows > 0) {
    while ($row = $resServicos->fetch_assoc()) {
        $servicos[] = $row;
    }
}

$profissionaisExibidos = $profissionais;
if ($profissionalFiltro !== 'todos') {
    $profissionaisExibidos = array_values(array_filter($profissionais, function ($p) use ($profissionalFiltro) {
        return (string) $p['id'] === (string) $profissionalFiltro;
    }));
}

$agendamentos = [];
$sqlAg = "
    SELECT 
        ag.id,
        ag.profissional_id,
        ag.data,
        ag.hora,
        ag.hora_fim,
        ag.status,
        ag.is_recorrente,
        c.nome AS cliente_nome,
        c.telefone AS cliente_telefone,
        s.nome AS servico_nome,
        s.preco AS servico_preco,
        s.duracao AS servico_duracao
    FROM agendamentos ag
    INNER JOIN clientes c ON ag.cliente_id = c.id
    INNER JOIN servicos s ON ag.servico_id = s.id
    WHERE ag.data = ?
";
$params = [$data];
$types = 's';

if ($profissionalFiltro !== 'todos') {
    $sqlAg .= " AND ag.profissional_id = ? ";
    $params[] = (int) $profissionalFiltro;
    $types .= 'i';
}

$sqlAg .= " ORDER BY ag.hora ASC ";

$stmtAg = $conn->prepare($sqlAg);
$stmtAg->bind_param($types, ...$params);
$stmtAg->execute();
$resAg = $stmtAg->get_result();
if ($resAg && $resAg->num_rows > 0) {
    while ($row = $resAg->fetch_assoc()) {
        $agendamentos[] = $row;
    }
}

$bloqueios = [];
$sqlBl = "
    SELECT id, profissional_id, data, hora_inicio, hora_fim, is_recorrente
    FROM bloqueios
    WHERE data = ?
";
$paramsBl = [$data];
$typesBl = 's';

if ($profissionalFiltro !== 'todos') {
    $sqlBl .= " AND profissional_id = ? ";
    $paramsBl[] = (int) $profissionalFiltro;
    $typesBl .= 'i';
}

$sqlBl .= " ORDER BY hora_inicio ASC ";

$stmtBl = $conn->prepare($sqlBl);
$stmtBl->bind_param($typesBl, ...$paramsBl);
$stmtBl->execute();
$resBl = $stmtBl->get_result();
if ($resBl && $resBl->num_rows > 0) {
    while ($row = $resBl->fetch_assoc()) {
        $bloqueios[] = $row;
    }
}

function minutosDesdeInicio($hora, $inicioAgenda)
{
    $partes = explode(':', substr($hora, 0, 5));
    $h = (int) $partes[0];
    $m = (int) $partes[1];
    return (($h - $inicioAgenda) * 60) + $m;
}

function formatarTelefoneWhatsapp($telefone)
{
    $numero = preg_replace('/\D+/', '', $telefone);
    if ($numero === '') return '';
    if (strlen($numero) === 11 || strlen($numero) === 10) return '55' . $numero;
    return $numero;
}

function servicoPrecisaAnaliseRapido(string $nome): bool
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

function labelServicoRapido(array $servico): string
{
    $label = $servico['nome'] . ' — ' . (int)$servico['duracao'] . 'min';

    if (servicoPrecisaAnaliseRapido($servico['nome'])) {
        return $label . ' — Valor após análise';
    }

    return $label . ' — R$ ' . number_format((float)$servico['preco'], 2, ',', '.');
}

function horasDisponiveis($inicio = 7, $fim = 22)
{
    $horarios = [];
    for ($min = $inicio * 60; $min <= $fim * 60; $min += 5) {
        $h = floor($min / 60);
        $m = $min % 60;
        $horarios[] = str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) $m, 2, '0', STR_PAD_LEFT);
    }
    return $horarios;
}

$listaHorarios = horasDisponiveis($inicioAgenda, $fimAgenda);

$agPorProf = [];
foreach ($agendamentos as $ag) {
    $agPorProf[$ag['profissional_id']][] = $ag;
}

$blPorProf = [];
foreach ($bloqueios as $bl) {
    $blPorProf[$bl['profissional_id']][] = $bl;
}

admin_shell_start('Agenda Visual | André Puchetti', 'agenda_visual');
?>
<style>
  .hero { margin-bottom: 18px; }
  .hero h1 {
    margin: 0 0 10px;
    font-size: clamp(2rem, 4vw, 3.3rem);
    line-height: .95;
    letter-spacing: -.05em;
    font-weight: 900;
  }
  .hero h1 span {
    display:block;
    background: linear-gradient(90deg,#fff4cc 0%,#d4af37 55%,#fff0a8 100%);
    -webkit-background-clip:text;
    -webkit-text-fill-color:transparent;
    background-clip:text;
    color:transparent;
  }
  .hero p { margin:0; color:rgba(247,243,234,.78); line-height:1.8; max-width:780px; }

  .top-filters {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    flex-wrap:wrap;
    margin: 18px 0 20px;
    padding: 16px;
    border-radius: 22px;
    background: linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03));
    border: 1px solid rgba(255,255,255,.08);
    box-shadow: 0 20px 60px rgba(0,0,0,.45);
  }

  .filter-group {
    display:flex;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
  }

  .filter-label {
    color:#f0d77a;
    font-size:12px;
    font-weight:800;
    letter-spacing:.14em;
    text-transform:uppercase;
  }

  .pro-select {
    min-height: 48px;
    min-width: 220px;
    border-radius: 14px;
    border: 1px solid rgba(255,255,255,.08);
    background: rgba(255,255,255,.04);
    color: #f7f3ea;
    padding: 0 14px;
    outline: none;
    font-size: .98rem;
  }

  .flash-message {
    margin: 0 0 18px;
    padding: 14px 16px;
    border-radius: 16px;
    font-weight: 700;
    border: 1px solid rgba(255,255,255,.08);
    box-shadow: 0 18px 40px rgba(0,0,0,.28);
  }

  .flash-message.sucesso {
    background: rgba(32, 201, 151, 0.12);
    color: #b8ffe8;
    border-color: rgba(32, 201, 151, 0.28);
  }

  .flash-message.erro {
    background: rgba(255, 95, 109, 0.12);
    color: #ffd5da;
    border-color: rgba(255, 95, 109, 0.28);
  }

  .layout {
    display:grid;
    grid-template-columns: 240px 1fr;
    gap:18px;
    align-items:start;
  }

  .glass {
    background: linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03));
    border:1px solid rgba(255,255,255,.08);
    box-shadow:0 20px 60px rgba(0,0,0,.45);
    border-radius:24px;
    backdrop-filter:blur(18px);
    -webkit-backdrop-filter:blur(18px);
    overflow:hidden;
  }

  .sidebar-card { padding:16px; position:sticky; top:20px; }
  .side-title {
    margin:0 0 14px;
    font-size:1rem;
    color:#f0d77a;
    letter-spacing:.12em;
    text-transform:uppercase;
    font-weight:800;
  }

  .month-bar {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:8px;
    margin-bottom:14px;
  }

  .month-label { font-weight:800; text-align:center; flex:1; }

  .month-nav,
  .quick-btn {
    text-decoration:none;
    color:#f7f3ea;
    background:rgba(255,255,255,.04);
    border:1px solid rgba(255,255,255,.08);
    transition:.22s ease;
  }

  .month-nav {
    width:34px;
    height:34px;
    border-radius:10px;
    display:grid;
    place-items:center;
  }

  .weekdays,
  .month-grid {
    display:grid;
    grid-template-columns:repeat(7,1fr);
    gap:6px;
  }

  .weekday {
    text-align:center;
    font-size:10px;
    color:#f0d77a;
    letter-spacing:.12em;
    text-transform:uppercase;
    font-weight:800;
    padding:6px 0;
  }

  .day {
    min-height:36px;
    border-radius:12px;
    display:flex;
    align-items:center;
    justify-content:center;
    text-decoration:none;
    color:rgba(247,243,234,.78);
    background:rgba(255,255,255,.03);
    border:1px solid rgba(255,255,255,.05);
    font-size:.9rem;
    transition:.2s ease;
  }

  .day:hover { transform:translateY(-1px); border-color:rgba(212,175,55,.25); }
  .day.empty { visibility:hidden; }
  .day.active {
    background:linear-gradient(180deg, rgba(212,175,55,.18), rgba(255,255,255,.03));
    border-color:rgba(212,175,55,.35);
    color:#f0d77a;
    font-weight:800;
  }
  .day.today { outline:1px solid rgba(212,175,55,.20); }

  .quick-nav { margin-top:18px; display:grid; gap:10px; }
  .quick-btn {
    min-height:42px;
    border-radius:14px;
    display:flex;
    align-items:center;
    justify-content:center;
  }

  .main-card { padding:16px; }
  .toolbar {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:14px;
  }

  .date-switch {
    display:flex;
    gap:10px;
    align-items:center;
    flex-wrap:wrap;
  }

  .date-pill {
    min-height:40px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:0 14px;
    border-radius:999px;
    background:rgba(212,175,55,.08);
    border:1px solid rgba(212,175,55,.18);
    color:#f0d77a;
    font-weight:800;
  }

  .legend {
    display:flex;
    gap:12px;
    flex-wrap:wrap;
  }

  .legend-item {
    display:inline-flex;
    align-items:center;
    gap:8px;
    color:rgba(247,243,234,.55);
    font-size:.9rem;
  }

  .dot {
    width:12px;
    height:12px;
    border-radius:50%;
  }
  .dot.appointment { background:#20c997; }
  .dot.blocked { background:#9c9c9c; }
  .dot.canceled { background:#ff5f6d; }
  .dot.recurring { background:#d4af37; }

  .schedule-shell {
    overflow:auto;
    border-radius:20px;
    border:1px solid rgba(255,255,255,.08);
    background:rgba(255,255,255,.03);
  }

  .schedule {
    min-width: <?= count($profissionaisExibidos) <= 1 ? 420 : 720 ?>px;
    display:grid;
    grid-template-columns:72px repeat(<?= max(1, count($profissionaisExibidos)); ?>, minmax(220px,1fr));
    align-items:start;
  }

  .header-cell {
    position:sticky;
    top:0;
    z-index:3;
    min-height:60px;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:10px;
    border-bottom:1px solid rgba(255,255,255,.08);
    background:rgba(10,10,10,.88);
    backdrop-filter:blur(16px);
    -webkit-backdrop-filter:blur(16px);
    font-weight:800;
    color:#f7f3ea;
  }

  .header-time {
    color:#f0d77a;
    font-size:.82rem;
    letter-spacing:.12em;
    text-transform:uppercase;
  }

  .pro-head {
    flex-direction:column;
    gap:4px;
  }

  .pro-head small {
    color:#f0d77a;
    letter-spacing:.12em;
    text-transform:uppercase;
    font-size:10px;
  }

  .time-column,
  .pro-column {
    position:relative;
    height: <?= $alturaTimeline ?>px;
    border-right:1px solid rgba(255,255,255,.06);
    background:
      repeating-linear-gradient(
        to bottom,
        transparent 0,
        transparent <?= $slotAltura - 1 ?>px,
        rgba(255,255,255,.06) <?= $slotAltura - 1 ?>px,
        rgba(255,255,255,.06) <?= $slotAltura ?>px
      );
  }

  .pro-column {
    position: relative;
  }

  .time-label {
    position:absolute;
    left:0;
    width:100%;
    transform:translateY(-50%);
    text-align:center;
    color:rgba(247,243,234,.55);
    font-size:.78rem;
    font-weight:700;
  }

  .time-slot {
    position:absolute;
    left:8px;
    right:8px;
    border-radius:12px;
    border:1px dashed transparent;
    background:transparent;
    z-index:1;
    cursor:pointer;
    transition:.18s ease;
  }

  .time-slot:hover {
    background:rgba(212,175,55,.08);
    border-color:rgba(212,175,55,.22);
  }

  .time-slot::after {
    content:'+ Novo';
    position:absolute;
    right:10px;
    top:8px;
    font-size:11px;
    font-weight:800;
    letter-spacing:.04em;
    color:rgba(240,215,122,0);
    transition:.18s ease;
  }

  .time-slot:hover::after {
    color:rgba(240,215,122,.95);
  }

  .event {
    position:absolute;
    left:6px;
    right:6px;
    border-radius:14px;
    padding:8px 10px;
    cursor:pointer;
    box-shadow:0 10px 20px rgba(0,0,0,.20);
    overflow:hidden;
    transition:.22s ease;
    border:1px solid rgba(255,255,255,.08);
    z-index:3;
  }

  .event:hover {
    transform:translateY(-2px);
    box-shadow:0 14px 24px rgba(0,0,0,.26);
  }

  .event.appointment {
    background:linear-gradient(180deg, rgba(32,201,151,.20), rgba(32,201,151,.12));
    border-color:rgba(32,201,151,.22);
    color:#eafff8;
  }

  .event.canceled {
    background:linear-gradient(180deg, rgba(255,95,109,.18), rgba(255,95,109,.10));
    border-color:rgba(255,95,109,.20);
    color:#ffe5e9;
  }

  .event.blocked {
    background:linear-gradient(180deg, rgba(170,170,170,.18), rgba(120,120,120,.12));
    border-color:rgba(220,220,220,.14);
    color:#f2f2f2;
  }

  .event.recurring {
    box-shadow:
      0 0 0 1px rgba(212,175,55,.18),
      0 10px 20px rgba(0,0,0,.20),
      0 0 18px rgba(212,175,55,.08);
  }

  .event-rec-badge {
    display:inline-flex;
    min-height:20px;
    align-items:center;
    justify-content:center;
    padding:0 7px;
    border-radius:999px;
    background:rgba(212,175,55,.18);
    color:#fff3c6;
    border:1px solid rgba(212,175,55,.28);
    font-size:9px;
    font-weight:900;
    letter-spacing:.08em;
    text-transform:uppercase;
    margin-bottom:5px;
  }

  .event-time {
    font-size:10px;
    font-weight:900;
    letter-spacing:.08em;
    text-transform:uppercase;
    opacity:.95;
    margin-bottom:3px;
  }

  .event-title {
    font-size:.88rem;
    font-weight:800;
    line-height:1.15;
    margin-bottom:3px;
  }

  .event-sub {
    font-size:.76rem;
    line-height:1.3;
    opacity:.9;
  }

  .modal-overlay {
    position:fixed;
    inset:0;
    background:rgba(5,5,5,.72);
    backdrop-filter:blur(12px);
    -webkit-backdrop-filter:blur(12px);
    display:flex;
    align-items:center;
    justify-content:center;
    padding:20px;
    opacity:0;
    visibility:hidden;
    transition:.28s ease;
    z-index:9999;
  }
  .modal-overlay.active { opacity:1; visibility:visible; }

  .modal {
    width:100%;
    max-width:560px;
    padding:26px;
    border-radius:26px;
    background:linear-gradient(180deg, rgba(255,255,255,.08), rgba(255,255,255,.04));
    border:1px solid rgba(255,255,255,.10);
    box-shadow:0 24px 70px rgba(0,0,0,.40);
    transform:translateY(8px) scale(.98);
    transition:.28s ease;
  }
  .modal-overlay.active .modal { transform:translateY(0) scale(1); }

  .modal-top {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    margin-bottom:12px;
  }

  .modal-title {
    margin:0;
    font-size:1.25rem;
    font-weight:900;
    letter-spacing:-.02em;
  }

  .close-btn {
    width:42px;
    height:42px;
    border-radius:12px;
    border:1px solid rgba(255,255,255,.08);
    background:rgba(255,255,255,.04);
    color:#fff;
    cursor:pointer;
    font-size:1.2rem;
  }

  .modal-badges {
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-bottom:16px;
  }

  .badge {
    display:inline-flex;
    align-items:center;
    min-height:30px;
    padding:0 12px;
    border-radius:999px;
    font-size:11px;
    font-weight:900;
    letter-spacing:.08em;
    text-transform:uppercase;
    border:1px solid transparent;
  }

  .badge.confirmado {
    background:rgba(32,201,151,.12);
    color:#9ef0d3;
    border-color:rgba(32,201,151,.20);
  }

  .badge.cancelado {
    background:rgba(255,95,109,.12);
    color:#ffc0c8;
    border-color:rgba(255,95,109,.20);
  }

  .badge.bloqueio {
    background:rgba(180,180,180,.10);
    color:#ececec;
    border-color:rgba(180,180,180,.18);
  }

  .badge.recorrente {
    background:rgba(212,175,55,.14);
    color:#ffe9a8;
    border-color:rgba(212,175,55,.22);
  }

  .badge.visualizacao {
    background:rgba(255,255,255,.06);
    color:#f3efe3;
    border-color:rgba(255,255,255,.12);
  }

  .detail-grid {
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:12px;
  }

  .detail-item {
    padding:14px;
    border-radius:18px;
    background:rgba(255,255,255,.04);
    border:1px solid rgba(255,255,255,.06);
  }

  .detail-item small {
    display:block;
    color:#f0d77a;
    font-size:11px;
    letter-spacing:.10em;
    text-transform:uppercase;
    margin-bottom:6px;
    font-weight:800;
  }

  .detail-item span {
    color:#f7f3ea;
    font-weight:700;
    line-height:1.5;
  }

  .modal-actions {
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:18px;
  }

  .action-btn {
    min-height:44px;
    padding:0 16px;
    border-radius:14px;
    text-decoration:none;
    font-weight:800;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border:1px solid rgba(255,255,255,.08);
  }

  .action-btn.primary {
    background:linear-gradient(180deg, rgba(212,175,55,.22), rgba(212,175,55,.14));
    color:#fff4cc;
    border-color:rgba(212,175,55,.22);
  }

  .action-btn.danger {
    background:linear-gradient(180deg, rgba(255,95,109,.22), rgba(255,95,109,.14));
    color:#ffe3e7;
    border-color:rgba(255,95,109,.22);
  }

  .readonly-note {
    width:100%;
    padding:12px 14px;
    border-radius:14px;
    background:rgba(255,255,255,.04);
    border:1px solid rgba(255,255,255,.06);
    color:rgba(247,243,234,.74);
    line-height:1.6;
    font-size:.95rem;
  }

  .quick-form {
    display:grid;
    gap:14px;
    margin-top:6px;
  }

  .form-row {
    display:grid;
    gap:8px;
  }

  .form-row.two {
    grid-template-columns:1fr 1fr;
  }

  .form-row label {
    font-size:12px;
    font-weight:800;
    letter-spacing:.08em;
    text-transform:uppercase;
    color:#f0d77a;
  }

  .form-control {
    width:100%;
    min-height:48px;
    border-radius:14px;
    border:1px solid rgba(255,255,255,.08);
    background:rgba(255,255,255,.04);
    color:#f7f3ea;
    padding:0 14px;
    outline:none;
    font-size:.98rem;
  }

  .form-control:focus {
    border-color:rgba(212,175,55,.34);
    box-shadow:0 0 0 3px rgba(212,175,55,.08);
  }

  .form-hint {
    padding:12px 14px;
    border-radius:14px;
    background:rgba(255,255,255,.04);
    border:1px solid rgba(255,255,255,.06);
    color:rgba(247,243,234,.82);
    line-height:1.6;
  }

  .summary-inline {
    display:grid;
    gap:10px;
    margin-bottom:8px;
  }

  .summary-pill {
    display:inline-flex;
    align-items:center;
    gap:8px;
    min-height:40px;
    padding:0 14px;
    border-radius:999px;
    background:rgba(255,255,255,.04);
    border:1px solid rgba(255,255,255,.08);
    color:#f7f3ea;
    font-size:.92rem;
    font-weight:700;
  }

  @media (max-width: 980px) {
    .layout { grid-template-columns:1fr; }
    .sidebar-card { position:static; }
  }

  @media (max-width: 700px) {
    .detail-grid,
    .form-row.two {
      grid-template-columns:1fr;
    }
  }
</style>

<section class="hero">
  <h1><span>Agenda Visual</span></h1>
  <p>
    Visualize compromissos, bloqueios e recorrências em um painel claro e premium.
    Clique em qualquer horário para montar um agendamento com hora inicial e final livres.
  </p>
</section>

<form method="GET" class="top-filters">
  <div class="filter-group">
    <span class="filter-label">Profissional</span>
    <select name="profissional_id" class="pro-select" onchange="this.form.submit()">
      <option value="todos">Todos</option>
      <?php foreach ($profissionais as $prof): ?>
        <option value="<?= (int) $prof['id']; ?>" <?= $profissionalFiltro === (string) $prof['id'] ? 'selected' : ''; ?>>
          <?= htmlspecialchars($prof['nome']); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="filter-group">
    <span class="filter-label">Data</span>
    <input
      type="date"
      name="data"
      value="<?= htmlspecialchars($data); ?>"
      class="pro-select"
      onchange="this.form.submit()"
      style="min-width:180px;"
    >
  </div>
</form>

<?php if ($flash && $msg): ?>
  <div class="flash-message <?= $flash === 'sucesso' ? 'sucesso' : 'erro'; ?>">
    <?= htmlspecialchars($msg); ?>
  </div>
<?php endif; ?>

<div class="layout">
  <aside class="glass sidebar-card">
    <h3 class="side-title">Calendário</h3>

    <div class="month-bar">
      <a class="month-nav" href="?data=<?= date('Y-m-d', strtotime('-1 month', $timestamp)); ?>&profissional_id=<?= urlencode($profissionalFiltro); ?>">‹</a>
      <div class="month-label"><?= $nomeMeses[$mesAtual]; ?> <?= $anoAtual; ?></div>
      <a class="month-nav" href="?data=<?= date('Y-m-d', strtotime('+1 month', $timestamp)); ?>&profissional_id=<?= urlencode($profissionalFiltro); ?>">›</a>
    </div>

    <div class="weekdays">
      <div class="weekday">D</div>
      <div class="weekday">S</div>
      <div class="weekday">T</div>
      <div class="weekday">Q</div>
      <div class="weekday">Q</div>
      <div class="weekday">S</div>
      <div class="weekday">S</div>
    </div>

    <div class="month-grid">
      <?php for ($i = 0; $i < $primeiroDiaSemana; $i++): ?>
        <div class="day empty"></div>
      <?php endfor; ?>

      <?php for ($dia = 1; $dia <= $diasNoMes; $dia++): ?>
        <?php
          $dataDia = sprintf('%04d-%02d-%02d', $anoAtual, $mesAtual, $dia);
          $isActive = $dataDia === $data;
          $isToday = $dataDia === date('Y-m-d');
        ?>
        <a class="day <?= $isActive ? 'active' : ''; ?> <?= $isToday ? 'today' : ''; ?>" href="?data=<?= $dataDia; ?>&profissional_id=<?= urlencode($profissionalFiltro); ?>">
          <?= $dia; ?>
        </a>
      <?php endfor; ?>
    </div>

    <div class="quick-nav">
      <a class="quick-btn" href="?data=<?= $dataAnterior; ?>&profissional_id=<?= urlencode($profissionalFiltro); ?>">Dia anterior</a>
      <a class="quick-btn" href="?data=<?= date('Y-m-d'); ?>&profissional_id=<?= urlencode($profissionalFiltro); ?>">Hoje</a>
      <a class="quick-btn" href="?data=<?= $proximaData; ?>&profissional_id=<?= urlencode($profissionalFiltro); ?>">Próximo dia</a>
    </div>
  </aside>

  <main class="glass main-card">
    <div class="toolbar">
      <div class="date-switch">
        <a class="quick-btn" style="min-width:44px;" href="?data=<?= $dataAnterior; ?>&profissional_id=<?= urlencode($profissionalFiltro); ?>">‹</a>
        <div class="date-pill"><?= date('d/m/Y', $timestamp); ?></div>
        <a class="quick-btn" style="min-width:44px;" href="?data=<?= $proximaData; ?>&profissional_id=<?= urlencode($profissionalFiltro); ?>">›</a>
      </div>

      <div class="legend">
        <div class="legend-item"><span class="dot appointment"></span> Agendamento</div>
        <div class="legend-item"><span class="dot recurring"></span> Recorrente</div>
        <div class="legend-item"><span class="dot blocked"></span> Bloqueio</div>
        <div class="legend-item"><span class="dot canceled"></span> Cancelado</div>
      </div>
    </div>

    <div class="schedule-shell">
      <div class="schedule">
        <div class="header-cell header-time">Hora</div>
        <?php foreach ($profissionaisExibidos as $prof): ?>
          <div class="header-cell pro-head">
            <small>Profissional</small>
            <div><?= htmlspecialchars($prof['nome']); ?></div>
          </div>
        <?php endforeach; ?>

        <div class="time-column">
          <?php
            for ($h = $inicioAgenda; $h <= $fimAgenda; $h++) {
              $top = (($h - $inicioAgenda) * 2) * $slotAltura;
              if ($h < $fimAgenda) {
                echo '<div class="time-label" style="top:' . $top . 'px;">' . str_pad($h, 2, '0', STR_PAD_LEFT) . ':00</div>';
              }
            }
          ?>
        </div>

        <?php foreach ($profissionaisExibidos as $prof): ?>
          <div class="pro-column">
            <?php
              for ($slotMin = $inicioAgenda * 60; $slotMin < $fimAgenda * 60; $slotMin += 30):
                $slotHora = str_pad((string) floor($slotMin / 60), 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) ($slotMin % 60), 2, '0', STR_PAD_LEFT);
                $slotTop = (($slotMin - ($inicioAgenda * 60)) / 30) * $slotAltura;
                $canEditSlot = podeEditarProfissional((int) $prof['id']);
            ?>
              <?php if ($canEditSlot): ?>
                <div
                  class="time-slot js-open-quick-modal"
                  style="top: <?= $slotTop; ?>px; height: <?= $slotAltura - 2; ?>px;"
                  data-profissional-id="<?= (int) $prof['id']; ?>"
                  data-profissional="<?= htmlspecialchars($prof['nome']); ?>"
                  data-data="<?= htmlspecialchars($data); ?>"
                  data-data-formatada="<?= date('d/m/Y', strtotime($data)); ?>"
                  data-hora="<?= $slotHora; ?>"
                ></div>
              <?php endif; ?>
            <?php endfor; ?>

            <?php if (!empty($blPorProf[$prof['id']])): ?>
              <?php foreach ($blPorProf[$prof['id']] as $bl): ?>
                <?php
                  $inicioMin = minutosDesdeInicio($bl['hora_inicio'], $inicioAgenda);
                  $fimMin = minutosDesdeInicio($bl['hora_fim'], $inicioAgenda);
                  $top = max(0, ($inicioMin / 30) * $slotAltura);
                  $height = max(34, (($fimMin - $inicioMin) / 30) * $slotAltura - 4);
                  $canEdit = podeEditarProfissional((int) $prof['id']) ? '1' : '0';
                ?>
                <div class="event blocked <?= (int) $bl['is_recorrente'] === 1 ? 'recurring' : ''; ?> js-open-modal"
                     style="top: <?= $top; ?>px; height: <?= $height; ?>px;"
                     data-type="bloqueio"
                     data-title="Bloqueio de horário"
                     data-status="bloqueio"
                     data-profissional-id="<?= (int) $prof['id']; ?>"
                     data-profissional="<?= htmlspecialchars($prof['nome']); ?>"
                     data-data="<?= date('d/m/Y', strtotime($bl['data'])); ?>"
                     data-hora="<?= substr($bl['hora_inicio'],0,5); ?> às <?= substr($bl['hora_fim'],0,5); ?>"
                     data-extra="Período indisponível para novos agendamentos."
                     data-recorrente="<?= (int) $bl['is_recorrente'] === 1 ? '1' : '0'; ?>"
                     data-can-edit="<?= $canEdit; ?>"
                     data-delete-url="cancelar-bloqueio.php?id=<?= (int) $bl['id']; ?>&csrf_token=<?= urlencode(csrf_token()); ?>">
                  <?php if ((int) $bl['is_recorrente'] === 1): ?>
                    <div class="event-rec-badge">Rec.</div>
                  <?php endif; ?>
                  <div class="event-time"><?= substr($bl['hora_inicio'],0,5); ?> → <?= substr($bl['hora_fim'],0,5); ?></div>
                  <div class="event-title">Bloqueado</div>
                  <div class="event-sub">Horário indisponível</div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!empty($agPorProf[$prof['id']])): ?>
              <?php foreach ($agPorProf[$prof['id']] as $ag): ?>
                <?php
                  $inicioMin = minutosDesdeInicio($ag['hora'], $inicioAgenda);
                  $fimReal = !empty($ag['hora_fim'])
                    ? minutosDesdeInicio($ag['hora_fim'], $inicioAgenda)
                    : ($inicioMin + max(30, (int) $ag['servico_duracao']));
                  $top = max(0, ($inicioMin / 30) * $slotAltura);
                  $height = max(38, ((($fimReal - $inicioMin) / 30) * $slotAltura) - 4);
                  $statusClass = $ag['status'] === 'cancelado' ? 'canceled' : 'appointment';
                  $wa = formatarTelefoneWhatsapp($ag['cliente_telefone']);
                  $isRec = (int) $ag['is_recorrente'] === 1;
                  $canEdit = podeEditarProfissional((int) $prof['id']) ? '1' : '0';
                  $horaFimMostrar = !empty($ag['hora_fim']) ? substr($ag['hora_fim'], 0, 5) : '--:--';
                ?>
                <div class="event <?= $statusClass; ?> <?= $isRec ? 'recurring' : ''; ?> js-open-modal"
                     style="top: <?= $top; ?>px; height: <?= $height; ?>px;"
                     data-type="agendamento"
                     data-title="<?= htmlspecialchars($ag['cliente_nome']); ?>"
                     data-status="<?= htmlspecialchars($ag['status']); ?>"
                     data-profissional-id="<?= (int) $prof['id']; ?>"
                     data-profissional="<?= htmlspecialchars($prof['nome']); ?>"
                     data-data="<?= date('d/m/Y', strtotime($ag['data'])); ?>"
                     data-hora="<?= substr($ag['hora'],0,5); ?> às <?= $horaFimMostrar; ?>"
                     data-servico="<?= htmlspecialchars($ag['servico_nome']); ?>"
                     data-telefone="<?= htmlspecialchars($ag['cliente_telefone']); ?>"
                     data-valor="<?= servicoPrecisaAnaliseRapido($ag['servico_nome']) ? 'Valor após análise' : 'R$ ' . number_format((float) $ag['servico_preco'], 2, ',', '.'); ?>"
                     data-whatsapp="<?= htmlspecialchars($wa); ?>"
                     data-recorrente="<?= $isRec ? '1' : '0'; ?>"
                     data-can-edit="<?= $canEdit; ?>"
                     data-cancel-url="cancelar-agendamento.php?id=<?= (int) $ag['id']; ?>&data=<?= urlencode($data); ?>&csrf_token=<?= urlencode(csrf_token()); ?>">
                  <?php if ($isRec): ?>
                    <div class="event-rec-badge">Rec.</div>
                  <?php endif; ?>
                  <div class="event-time"><?= substr($ag['hora'],0,5); ?> → <?= $horaFimMostrar; ?></div>
                  <div class="event-title"><?= htmlspecialchars($ag['cliente_nome']); ?></div>
                  <div class="event-sub"><?= htmlspecialchars($ag['servico_nome']); ?></div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </main>
</div>

<div class="modal-overlay" id="modalOverlay">
  <div class="modal">
    <div class="modal-top">
      <h3 class="modal-title" id="modalTitle">Detalhes</h3>
      <button class="close-btn" id="closeModal" type="button">×</button>
    </div>

    <div class="modal-badges" id="modalBadges"></div>
    <div class="detail-grid" id="modalDetails"></div>
    <div class="modal-actions" id="modalActions"></div>
  </div>
</div>

<div class="modal-overlay" id="quickModalOverlay">
  <div class="modal">
    <div class="modal-top">
      <h3 class="modal-title">Novo agendamento</h3>
      <button class="close-btn" id="closeQuickModal" type="button">×</button>
    </div>

    <div class="summary-inline">
      <div class="summary-pill" id="quickResumoProfissional">Profissional: -</div>
      <div class="summary-pill" id="quickResumoDataHora">Data/Hora: -</div>
    </div>

    <form class="quick-form" action="criar-agendamento-rapido.php" method="POST">
      <input type="hidden" name="profissional_id" id="quickProfissionalId">
      <input type="hidden" name="data" id="quickData">
      <input type="hidden" name="return_data" value="<?= htmlspecialchars($data); ?>">
      <input type="hidden" name="return_profissional_id" value="<?= htmlspecialchars($profissionalFiltro); ?>">

      <div class="form-row">
        <label for="quickServico">Serviço</label>
        <select class="form-control" name="servico_id" id="quickServico" required>
          <option value="">Selecione o serviço</option>
          <?php foreach ($servicos as $serv): ?>
            <option
              value="<?= (int) $serv['id']; ?>"
              data-duracao="<?= (int) $serv['duracao']; ?>"
            >
              <?= htmlspecialchars(labelServicoRapido($serv)); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-row two">
        <div class="form-row">
          <label for="quickHora">Hora inicial</label>
          <select class="form-control" name="hora" id="quickHora" required>
            <option value="">Selecione</option>
            <?php foreach ($listaHorarios as $horaItem): ?>
              <option value="<?= htmlspecialchars($horaItem); ?>"><?= htmlspecialchars($horaItem); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-row">
          <label for="quickHoraFim">Hora final</label>
          <select class="form-control" name="hora_fim" id="quickHoraFim" required>
            <option value="">Selecione</option>
            <?php foreach ($listaHorarios as $horaItem): ?>
              <option value="<?= htmlspecialchars($horaItem); ?>"><?= htmlspecialchars($horaItem); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-row two">
        <div class="form-row">
          <label for="quickNome">Nome</label>
          <input class="form-control" type="text" name="nome" id="quickNome" placeholder="Nome do cliente" required>
        </div>

        <div class="form-row">
          <label for="quickTelefone">WhatsApp</label>
          <input class="form-control" type="text" name="telefone" id="quickTelefone" placeholder="(11) 99999-9999" required>
        </div>
      </div>

      <div class="form-hint">
        Agora você pode escolher livremente a hora inicial e a hora final. O sistema valida bloqueios e conflitos antes de salvar.
      </div>

      <div class="modal-actions">
        <button class="action-btn primary" type="submit">Salvar agendamento</button>
      </div>
    </form>
  </div>
</div>

<script>
  const modalOverlay = document.getElementById('modalOverlay');
  const modalTitle = document.getElementById('modalTitle');
  const modalBadges = document.getElementById('modalBadges');
  const modalDetails = document.getElementById('modalDetails');
  const modalActions = document.getElementById('modalActions');
  const closeModal = document.getElementById('closeModal');

  const quickModalOverlay = document.getElementById('quickModalOverlay');
  const closeQuickModal = document.getElementById('closeQuickModal');
  const quickProfissionalId = document.getElementById('quickProfissionalId');
  const quickData = document.getElementById('quickData');
  const quickHora = document.getElementById('quickHora');
  const quickHoraFim = document.getElementById('quickHoraFim');
  const quickNome = document.getElementById('quickNome');
  const quickTelefone = document.getElementById('quickTelefone');
  const quickServico = document.getElementById('quickServico');
  const quickResumoProfissional = document.getElementById('quickResumoProfissional');
  const quickResumoDataHora = document.getElementById('quickResumoDataHora');

  function somarMinutos(hora, minutos) {
    const partes = hora.split(':');
    let total = (parseInt(partes[0], 10) * 60) + parseInt(partes[1], 10) + minutos;

    const h = Math.floor(total / 60);
    const m = total % 60;

    return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
  }

  function abrirModalDetalhes(item) {
    const type = item.dataset.type || '';
    const title = item.dataset.title || 'Detalhes';
    const status = item.dataset.status || '';
    const profissional = item.dataset.profissional || '';
    const data = item.dataset.data || '';
    const hora = item.dataset.hora || '';
    const servico = item.dataset.servico || '';
    const telefone = item.dataset.telefone || '';
    const valor = item.dataset.valor || '';
    const whatsapp = item.dataset.whatsapp || '';
    const cancelUrl = item.dataset.cancelUrl || '';
    const deleteUrl = item.dataset.deleteUrl || '';
    const extra = item.dataset.extra || '';
    const recorrente = item.dataset.recorrente || '0';
    const canEdit = item.dataset.canEdit === '1';

    modalTitle.textContent = title;
    modalBadges.innerHTML = '';
    modalDetails.innerHTML = '';
    modalActions.innerHTML = '';

    const badge = document.createElement('div');
    badge.className = 'badge ' + (status === 'cancelado' ? 'cancelado' : (type === 'bloqueio' ? 'bloqueio' : 'confirmado'));
    badge.textContent = type === 'bloqueio' ? 'Bloqueio' : status;
    modalBadges.appendChild(badge);

    if (recorrente === '1') {
      const recBadge = document.createElement('div');
      recBadge.className = 'badge recorrente';
      recBadge.textContent = 'Recorrente';
      modalBadges.appendChild(recBadge);
    }

    if (!canEdit) {
      const viewBadge = document.createElement('div');
      viewBadge.className = 'badge visualizacao';
      viewBadge.textContent = 'Somente visualização';
      modalBadges.appendChild(viewBadge);
    }

    const details = [];
    details.push({ label: 'Profissional', value: profissional });
    details.push({ label: 'Data', value: data });
    details.push({ label: 'Horário', value: hora });

    if (type === 'agendamento') {
      details.push({ label: 'Serviço', value: servico });
      details.push({ label: 'Telefone', value: telefone });
      details.push({ label: 'Valor', value: valor });
    } else {
      details.push({ label: 'Informação', value: extra });
    }

    details.forEach(detail => {
      const div = document.createElement('div');
      div.className = 'detail-item';
      div.innerHTML = `<small>${detail.label}</small><span>${detail.value}</span>`;
      modalDetails.appendChild(div);
    });

    if (canEdit) {
      if (type === 'agendamento') {
        if (whatsapp) {
          const wa = document.createElement('a');
          wa.className = 'action-btn primary';
          wa.href = `https://wa.me/${whatsapp}`;
          wa.target = '_blank';
          wa.rel = 'noopener noreferrer';
          wa.textContent = 'Abrir WhatsApp';
          modalActions.appendChild(wa);
        }

        if (status !== 'cancelado' && cancelUrl) {
          const cancel = document.createElement('a');
          cancel.className = 'action-btn danger';
          cancel.href = cancelUrl;
          cancel.textContent = recorrente === '1' ? 'Excluir recorrência' : 'Cancelar agendamento';
          cancel.onclick = function () {
            return confirm(recorrente === '1'
              ? 'Este é um agendamento recorrente. Deseja cancelar a recorrência inteira?'
              : 'Deseja cancelar este agendamento?');
          };
          modalActions.appendChild(cancel);
        }
      }

      if (type === 'bloqueio' && deleteUrl) {
        const del = document.createElement('a');
        del.className = 'action-btn danger';
        del.href = deleteUrl;
        del.textContent = 'Remover bloqueio';
        del.onclick = function () {
          return confirm('Deseja remover este bloqueio?');
        };
        modalActions.appendChild(del);
      }
    } else {
      const note = document.createElement('div');
      note.className = 'readonly-note';
      note.textContent = 'Você pode visualizar a agenda deste profissional, mas não pode editar os itens dele.';
      modalActions.appendChild(note);
    }

    modalOverlay.classList.add('active');
  }

  function abrirModalRapido(item) {
    const profissionalId = item.dataset.profissionalId || '';
    const profissional = item.dataset.profissional || '';
    const data = item.dataset.data || '';
    const dataFormatada = item.dataset.dataFormatada || '';
    const hora = item.dataset.hora || '';

    quickProfissionalId.value = profissionalId;
    quickData.value = data;

    quickResumoProfissional.textContent = `Profissional: ${profissional}`;
    quickResumoDataHora.textContent = `Data: ${dataFormatada}`;

    quickServico.value = '';
    quickHora.value = hora || '';
    quickHoraFim.value = '';
    quickNome.value = '';
    quickTelefone.value = '';

    quickModalOverlay.classList.add('active');
    setTimeout(() => {
      if (quickHora.value) {
        quickServico.focus();
      } else {
        quickHora.focus();
      }
    }, 60);
  }

  function aplicarHoraFinalPeloServico() {
    const horaInicio = quickHora.value;
    const option = quickServico.options[quickServico.selectedIndex];
    if (!horaInicio || !option || !option.dataset.duracao) return;

    const duracao = parseInt(option.dataset.duracao, 10);
    if (isNaN(duracao)) return;

    const sugestaoFim = somarMinutos(horaInicio, duracao);

    const existeOpcao = Array.from(quickHoraFim.options).some(opt => opt.value === sugestaoFim);
    if (existeOpcao) {
      quickHoraFim.value = sugestaoFim;
    }
  }

  document.querySelectorAll('.js-open-modal').forEach(item => {
    item.addEventListener('click', (e) => {
      e.stopPropagation();
      abrirModalDetalhes(item);
    });
  });

  document.querySelectorAll('.js-open-quick-modal').forEach(item => {
    item.addEventListener('click', () => {
      abrirModalRapido(item);
    });
  });

  quickServico.addEventListener('change', aplicarHoraFinalPeloServico);
  quickHora.addEventListener('change', aplicarHoraFinalPeloServico);

  function fecharModalDetalhes() {
    modalOverlay.classList.remove('active');
  }

  function fecharModalRapido() {
    quickModalOverlay.classList.remove('active');
  }

  closeModal.addEventListener('click', fecharModalDetalhes);
  closeQuickModal.addEventListener('click', fecharModalRapido);

  modalOverlay.addEventListener('click', (e) => {
    if (e.target === modalOverlay) {
      fecharModalDetalhes();
    }
  });

  quickModalOverlay.addEventListener('click', (e) => {
    if (e.target === quickModalOverlay) {
      fecharModalRapido();
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      fecharModalDetalhes();
      fecharModalRapido();
    }
  });

  quickTelefone.addEventListener('input', () => {
    let v = quickTelefone.value.replace(/\D/g, '').slice(0, 11);

    if (v.length > 10) {
      v = v.replace(/^(\d{2})(\d{5})(\d{0,4}).*/, '($1) $2-$3');
    } else if (v.length > 6) {
      v = v.replace(/^(\d{2})(\d{4})(\d{0,4}).*/, '($1) $2-$3');
    } else if (v.length > 2) {
      v = v.replace(/^(\d{2})(\d{0,5})/, '($1) $2');
    } else if (v.length > 0) {
      v = v.replace(/^(\d*)/, '($1');
    }

    quickTelefone.value = v;
  });
</script>
<?php admin_shell_end(); ?>
