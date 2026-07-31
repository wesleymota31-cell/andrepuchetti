<?php
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/admin-shell.php';
require_once '../includes/radar.php';

date_default_timezone_set('America/Sao_Paulo');

$data = $_GET['data'] ?? date('Y-m-d');
$filtroAvulsosProfissional = $_GET['avulso_profissional'] ?? 'todos';

$dataObj = DateTime::createFromFormat('Y-m-d', $data);
if (!$dataObj || $dataObj->format('Y-m-d') !== $data) {
    $data = date('Y-m-d');
    $dataObj = DateTime::createFromFormat('Y-m-d', $data);
}

$dataAnterior = (clone $dataObj)->modify('-1 day')->format('Y-m-d');
$proximaData = (clone $dataObj)->modify('+1 day')->format('Y-m-d');

$sql = "
    SELECT 
        ag.id,
        ag.data,
        ag.hora,
        ag.status,
        ag.is_recorrente,
        c.nome AS cliente_nome,
        c.telefone AS cliente_telefone,
        p.id AS profissional_id,
        p.nome AS profissional_nome,
        s.nome AS servico_nome,
        s.preco AS servico_preco
    FROM agendamentos ag
    INNER JOIN clientes c ON ag.cliente_id = c.id
    INNER JOIN profissionais p ON ag.profissional_id = p.id
    INNER JOIN servicos s ON ag.servico_id = s.id
    WHERE ag.data = ?
      AND ag.status IN ('confirmado', 'pendente')
    ORDER BY p.nome ASC, ag.hora ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $data);
$stmt->execute();
$result = $stmt->get_result();

$agendamentos = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $agendamentos[] = $row;
    }
}

$pedidosAnalise = [];
$resPedidosAnalise = $conn->query("
    SELECT
        pa.id,
        pa.nome,
        pa.telefone,
        pa.categoria,
        pa.criado_em,
        p.nome AS profissional_nome,
        s.nome AS servico_nome
    FROM pedidos_analise pa
    INNER JOIN profissionais p ON p.id = pa.profissional_id
    INNER JOIN servicos s ON s.id = pa.servico_id
    WHERE pa.status = 'pendente'
    ORDER BY pa.criado_em DESC
    LIMIT 8
");
if ($resPedidosAnalise && $resPedidosAnalise->num_rows > 0) {
    while ($row = $resPedidosAnalise->fetch_assoc()) {
        $pedidosAnalise[] = $row;
    }
}
$totalPedidosAnalise = count($pedidosAnalise);

$totalAgendamentos = count($agendamentos);
$totalRecorrentes = 0;
$totalAvulsos = 0;
$previsaoAvulsosTodos = 0.0;
$previsaoAvulsosFiltrada = 0.0;

$porProfissional = [];
$profissionaisFiltroAvulsos = [];

$agora = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
$hoje = date('Y-m-d');
$dataSelecionadaEhHoje = ($data === $hoje);

foreach ($agendamentos as $ag) {
    $profissionalId = (int)$ag['profissional_id'];

    if (!isset($porProfissional[$profissionalId])) {
        $porProfissional[$profissionalId] = [
            'id' => $profissionalId,
            'nome' => $ag['profissional_nome'],
            'total' => 0,
            'recorrentes' => 0,
            'avulsos' => 0,
            'previsao_avulsos' => 0.0,
            'proximo' => null,
            'agendamentos' => [],
        ];

        $profissionaisFiltroAvulsos[$profissionalId] = $ag['profissional_nome'];
    }

    $isRecorrente = (int)$ag['is_recorrente'] === 1;

    $porProfissional[$profissionalId]['total']++;
    $porProfissional[$profissionalId]['agendamentos'][] = $ag;

    if ($isRecorrente) {
        $totalRecorrentes++;
        $porProfissional[$profissionalId]['recorrentes']++;
    } else {
        $totalAvulsos++;
        $valorServico = (float)$ag['servico_preco'];
        $previsaoAvulsosTodos += $valorServico;
        $porProfissional[$profissionalId]['avulsos']++;
        $porProfissional[$profissionalId]['previsao_avulsos'] += $valorServico;
    }

    $dataHoraAgendamento = DateTime::createFromFormat(
        'Y-m-d H:i:s',
        $ag['data'] . ' ' . $ag['hora'],
        new DateTimeZone('America/Sao_Paulo')
    );

    if (!$dataHoraAgendamento) {
        $dataHoraAgendamento = DateTime::createFromFormat(
            'Y-m-d H:i',
            $ag['data'] . ' ' . substr($ag['hora'], 0, 5),
            new DateTimeZone('America/Sao_Paulo')
        );
    }

    $podeSerProximo = true;

    if ($dataSelecionadaEhHoje && $dataHoraAgendamento && $dataHoraAgendamento < $agora) {
        $podeSerProximo = false;
    }

    if ($podeSerProximo && $porProfissional[$profissionalId]['proximo'] === null) {
        $porProfissional[$profissionalId]['proximo'] = $ag;
    }
}

if ($filtroAvulsosProfissional !== 'todos') {
    $profIdFiltro = (int)$filtroAvulsosProfissional;
    $previsaoAvulsosFiltrada = $porProfissional[$profIdFiltro]['previsao_avulsos'] ?? 0.0;
} else {
    $previsaoAvulsosFiltrada = $previsaoAvulsosTodos;
}

$labelPrevisaoAvulsos = 'Todos os profissionais';
if ($filtroAvulsosProfissional !== 'todos') {
    $profIdFiltro = (int)$filtroAvulsosProfissional;
    $labelPrevisaoAvulsos = $profissionaisFiltroAvulsos[$profIdFiltro] ?? 'Profissional selecionado';
}

$radarProfissionalId = usuarioEhAdmin() ? null : usuarioProfissionalId();
$radarItemsAll = radar_fetch_items($conn, ['profissional_id' => $radarProfissionalId, 'limit' => 160]);
$radarResumo = radar_summary($radarItemsAll);
$radarPrioridades = array_slice($radarItemsAll, 0, 5);

function formatarTelefoneDashboard(string $tel): string {
    $numero = preg_replace('/\D+/', '', $tel);

    if (strlen($numero) === 13 && substr($numero, 0, 2) === '55') {
        $numero = substr($numero, 2);
    }

    if (strlen($numero) === 11) {
        return sprintf('(%s) %s-%s', substr($numero, 0, 2), substr($numero, 2, 5), substr($numero, 7));
    }

    if (strlen($numero) === 10) {
        return sprintf('(%s) %s-%s', substr($numero, 0, 2), substr($numero, 2, 4), substr($numero, 6));
    }

    return $tel;
}

function telefoneWhatsappDashboard(string $tel): string {
    $numero = preg_replace('/\D+/', '', $tel);
    if ($numero === '') return '';
    if (strlen($numero) === 10 || strlen($numero) === 11) {
        return '55' . $numero;
    }
    return $numero;
}

admin_shell_start('Dashboard | André Puchetti', 'agenda');
?>
<style>
  :root {
    --gold:#d4af37;
    --gold-soft:#f0d77a;
    --text:#f7f3ea;
    --muted:rgba(247,243,234,.62);
    --soft:rgba(247,243,234,.78);
    --green:#20c997;
    --red:#ff5f6d;
  }

  .dashboard-hero {
    margin-bottom: 22px;
    position: relative;
  }

  .dashboard-hero::after {
    content: "";
    position: absolute;
    inset: auto 0 -12px 0;
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(212,175,55,.20), transparent);
  }

  .hero-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 18px;
    flex-wrap: wrap;
  }

  .dashboard-hero h1 {
    margin: 0 0 10px;
    font-size: clamp(2.1rem, 5vw, 3.7rem);
    line-height: .92;
    letter-spacing: -.06em;
    font-weight: 900;
  }

  .dashboard-hero h1 span {
    display: block;
    background: linear-gradient(90deg,#fff4cc 0%,#d4af37 55%,#fff0a8 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    color: transparent;
  }

  .dashboard-hero p {
    margin: 0;
    max-width: 760px;
    color: var(--soft);
    line-height: 1.7;
  }

  .date-filter {
    border-radius: 22px;
    padding: 14px;
    background: linear-gradient(180deg, rgba(255,255,255,.055), rgba(255,255,255,.025));
    border: 1px solid rgba(255,255,255,.08);
    box-shadow: 0 18px 44px rgba(0,0,0,.32);
  }

  .date-filter form {
    display: flex;
    gap: 10px;
    align-items: center;
  }

  .date-filter input,
  .avulso-filter select {
    min-height: 46px;
    border-radius: 14px;
    border: 1px solid rgba(255,255,255,.08);
    background: rgba(255,255,255,.04);
    color: var(--text);
    padding: 0 14px;
    outline: none;
  }

  .date-filter button,
  .date-nav-link,
  .avulso-filter button {
    min-height: 46px;
    border: none;
    border-radius: 14px;
    cursor: pointer;
    padding: 0 18px;
    background: linear-gradient(90deg,#c8a22a 0%,#f2d778 50%,#cfa72d 100%);
    color: #1a1405;
    font-weight: 900;
  }

  .date-nav {
    display:flex;
    gap:8px;
    margin-top:10px;
  }

  .date-nav-link {
    flex:1;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    text-decoration:none;
    background:rgba(255,255,255,.05);
    color:var(--text);
    border:1px solid rgba(255,255,255,.08);
  }

  .stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 20px;
  }

  .radar-panel {
    border-radius: 24px;
    background: linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03));
    border: 1px solid rgba(255,255,255,.08);
    box-shadow: 0 18px 48px rgba(0,0,0,.34);
    padding: 16px;
    margin-bottom: 20px;
  }

  .radar-panel-head {
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:12px;
    margin-bottom:12px;
  }

  .radar-panel h2 {
    margin:0;
    color:#fff0bd;
    font-size:1.35rem;
  }

  .radar-panel p {
    margin:4px 0 0;
    color:var(--muted);
  }

  .radar-link {
    min-height:40px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:13px;
    padding:0 13px;
    color:#17130b;
    background:linear-gradient(90deg,#f7e7af,#d4af37,#a87908);
    text-decoration:none;
    font-weight:950;
    white-space:nowrap;
  }

  .radar-summary-row {
    display:flex;
    gap:10px;
    overflow-x:auto;
    padding-bottom:10px;
  }

  .radar-mini-card {
    min-width:132px;
    border-radius:16px;
    padding:12px;
    border:1px solid rgba(255,255,255,.08);
  }

  .radar-mini-card span {
    display:block;
    font-size:.8rem;
    font-weight:950;
    color:rgba(247,243,234,.74);
  }

  .radar-mini-card strong {
    display:block;
    margin-top:4px;
    font-size:1.45rem;
    color:#fff;
  }

  .radar-mini-card.late{background:rgba(255,95,109,.12);border-color:rgba(255,95,109,.25)}
  .radar-mini-card.today{background:rgba(116,90,255,.14);border-color:rgba(116,90,255,.28)}
  .radar-mini-card.tomorrow{background:rgba(245,166,35,.13);border-color:rgba(245,166,35,.28)}
  .radar-mini-card.week{background:rgba(50,145,255,.12);border-color:rgba(50,145,255,.25)}
  .radar-mini-card.risk{background:rgba(193,43,105,.16);border-color:rgba(193,43,105,.30)}

  .radar-priority-list {
    display:grid;
    gap:9px;
    margin-top:4px;
  }

  .radar-priority-item {
    display:flex;
    justify-content:space-between;
    gap:10px;
    align-items:center;
    border-top:1px solid rgba(255,255,255,.07);
    padding-top:10px;
  }

  .radar-priority-item:first-child {
    border-top:0;
    padding-top:0;
  }

  .radar-priority-item strong {
    display:block;
    color:#fff0bd;
  }

  .radar-priority-item small {
    display:block;
    color:var(--muted);
    line-height:1.45;
  }

  .radar-state {
    font-weight:950;
    color:#ffd8dd;
  }

  .radar-whats-mini {
    min-height:40px;
    border-radius:12px;
    padding:0 12px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    background:#25d366;
    color:#062312;
    text-decoration:none;
    font-weight:950;
    white-space:nowrap;
  }

  .metric-card,
  .professional-card,
  .analysis-panel,
  .empty-state {
    border-radius: 24px;
    background:
      linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03)),
      radial-gradient(circle at top left, rgba(212,175,55,.055), transparent 42%);
    border: 1px solid rgba(255,255,255,.08);
    box-shadow:
      0 18px 48px rgba(0,0,0,.38),
      inset 0 1px 0 rgba(255,255,255,.04);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
  }

  .metric-card {
    padding: 18px;
    min-height: 132px;
    position: relative;
    overflow: hidden;
  }

  .metric-card::before {
    content: "";
    position: absolute;
    width: 120px;
    height: 120px;
    right: -70px;
    top: -70px;
    border-radius: 50%;
    background: rgba(212,175,55,.08);
    filter: blur(4px);
  }

  .metric-card small {
    display: block;
    color: var(--gold-soft);
    font-size: 10px;
    letter-spacing: .16em;
    text-transform: uppercase;
    font-weight: 900;
    margin-bottom: 9px;
  }

  .metric-card strong {
    display: block;
    font-size: clamp(1.55rem, 3vw, 2.05rem);
    letter-spacing: -.04em;
    line-height: 1;
    margin-bottom: 9px;
    color: var(--text);
  }

  .metric-card p {
    margin: 0;
    color: var(--muted);
    line-height: 1.5;
    font-size: .88rem;
  }

  .metric-card.highlight {
    background:
      linear-gradient(180deg, rgba(212,175,55,.12), rgba(255,255,255,.03)),
      radial-gradient(circle at top left, rgba(212,175,55,.18), transparent 42%);
    border-color: rgba(212,175,55,.18);
  }

  .avulso-filter {
    margin-top: 14px;
    position: relative;
    z-index: 2;
  }

  .avulso-filter form {
    display: grid;
    gap: 8px;
  }

  .avulso-filter label {
    color: rgba(247,243,234,.68);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .10em;
    text-transform: uppercase;
  }

  .section-title {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin: 24px 0 14px;
  }

  .section-title h2 {
    margin: 0;
    font-size: clamp(1.45rem, 3vw, 2.1rem);
    letter-spacing: -.045em;
    line-height: 1;
  }

  .section-title p {
    margin: 0;
    color: var(--muted);
    line-height: 1.55;
  }

  .professionals-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0,1fr));
    gap: 16px;
  }

  .professional-card {
    padding: 16px;
    overflow: hidden;
  }

  .pro-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 14px;
    padding-bottom: 12px;
    border-bottom: 1px solid rgba(255,255,255,.07);
  }

  .pro-title-area h3 {
    margin: 0 0 6px;
    font-size: 1.25rem;
    letter-spacing: -.035em;
  }

  .pro-title-area p {
    margin: 0;
    color: var(--muted);
    font-size: .88rem;
    line-height: 1.45;
  }

  .pro-count {
    min-width: 54px;
    height: 54px;
    border-radius: 18px;
    display: grid;
    place-items: center;
    background: rgba(212,175,55,.12);
    border: 1px solid rgba(212,175,55,.18);
    color: #fff1bf;
    font-weight: 900;
    font-size: 1.25rem;
  }

  .accordion-toggle {
    display: none;
    width: 100%;
    border: none;
    background: transparent;
    color: inherit;
    padding: 0;
    text-align: left;
    cursor: pointer;
  }

  .accordion-chevron {
    display: none;
    width: 36px;
    height: 36px;
    border-radius: 12px;
    place-items: center;
    background: rgba(255,255,255,.05);
    border: 1px solid rgba(255,255,255,.08);
    color: #fff1bf;
    font-weight: 900;
    transition: .2s ease;
  }

  .professional-card.open .accordion-chevron {
    transform: rotate(180deg);
  }

  .mini-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 9px;
    margin: 14px 0;
  }

  .mini-stat {
    padding: 11px;
    border-radius: 16px;
    background: rgba(255,255,255,.035);
    border: 1px solid rgba(255,255,255,.06);
  }

  .mini-stat small {
    display: block;
    color: var(--gold-soft);
    font-size: 9px;
    font-weight: 900;
    letter-spacing: .11em;
    text-transform: uppercase;
    margin-bottom: 6px;
  }

  .mini-stat strong {
    display: block;
    font-size: 1.06rem;
  }

  .timeline {
    position: relative;
    display: grid;
    gap: 10px;
  }

  .timeline-scroll-note {
    display: none;
    margin: -2px 0 10px;
    color: rgba(247,243,234,.48);
    font-size: .82rem;
    line-height: 1.4;
  }

  .timeline-item {
    position: relative;
    display: grid;
    grid-template-columns: 68px 1fr;
    gap: 10px;
    align-items: stretch;
  }

  .time-pill {
    min-height: 48px;
    border-radius: 15px;
    display: grid;
    place-items: center;
    background: rgba(255,255,255,.045);
    border: 1px solid rgba(255,255,255,.07);
    color: #fff1bf;
    font-weight: 900;
    letter-spacing: -.02em;
  }

  .appointment-card {
    padding: 12px;
    border-radius: 17px;
    background: linear-gradient(180deg, rgba(255,255,255,.055), rgba(255,255,255,.025));
    border: 1px solid rgba(255,255,255,.07);
    transition: .2s ease;
  }

  .appointment-card:hover {
    transform: translateY(-2px);
    border-color: rgba(212,175,55,.20);
  }

  .appointment-top {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 4px;
  }

  .client-name {
    font-weight: 900;
    color: var(--text);
    line-height: 1.25;
    letter-spacing: -.02em;
  }

  .service-name {
    color: var(--muted);
    line-height: 1.4;
    font-size: .9rem;
  }

  .appointment-actions {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
    margin-top: 10px;
  }

  .status,
  .badge-rec,
  .badge-avulso,
  .action-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 28px;
    padding: 0 10px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 900;
    letter-spacing: .07em;
    text-transform: uppercase;
    text-decoration: none;
    white-space: nowrap;
  }

  .status.confirmado {
    background: rgba(32,201,151,.12);
    color: #d8fff2;
    border: 1px solid rgba(32,201,151,.2);
  }

  .status.pendente {
    background: rgba(212,175,55,.12);
    color: #fff2bf;
    border: 1px solid rgba(212,175,55,.2);
  }

  .status.cancelado {
    background: rgba(255,95,109,.12);
    color: #ffd9de;
    border: 1px solid rgba(255,95,109,.22);
  }

  .badge-rec {
    background: rgba(212,175,55,.14);
    color: #fff0b3;
    border: 1px solid rgba(212,175,55,.26);
  }

  .badge-avulso {
    background: rgba(255,255,255,.06);
    color: rgba(247,243,234,.78);
    border: 1px solid rgba(255,255,255,.08);
  }

  .action-link.whats {
    background: rgba(32,201,151,.10);
    color: #d8fff2;
    border: 1px solid rgba(32,201,151,.18);
  }

  .action-link.danger {
    background: rgba(255,95,109,.10);
    color: #ffd9de;
    border: 1px solid rgba(255,95,109,.2);
  }

  .action-link:hover {
    transform: translateY(-1px);
  }

  .empty-state {
    padding: 28px 22px;
    color: var(--muted);
    line-height: 1.7;
  }

  .analysis-panel {
    padding:16px;
    margin-bottom:20px;
  }

  .analysis-list {
    display:grid;
    gap:10px;
    margin-top:12px;
  }

  .analysis-item {
    display:grid;
    grid-template-columns:1fr auto;
    gap:12px;
    align-items:center;
    padding:12px;
    border-radius:16px;
    background:rgba(255,255,255,.035);
    border:1px solid rgba(255,255,255,.06);
  }

  .analysis-item strong {
    display:block;
    margin-bottom:4px;
  }

  .analysis-item span {
    color:var(--muted);
    font-size:.9rem;
    line-height:1.45;
  }

  @media (max-width: 1180px) {
    .stats-grid {
      grid-template-columns: repeat(2, 1fr);
    }

    .professionals-grid {
      grid-template-columns: 1fr;
    }
  }

  @media (max-width: 760px) {
    .hero-row {
      align-items: stretch;
    }

    .date-filter,
    .date-filter form,
    .date-filter input,
    .date-filter button {
      width: 100%;
    }

    .date-filter form {
      flex-direction: column;
      align-items: stretch;
    }

    .stats-grid {
      grid-template-columns: 1fr;
    }

    .metric-card {
      min-height: auto;
    }

    .professionals-grid {
      gap: 12px;
    }

    .professional-card {
      padding: 0;
      border-radius: 22px;
    }

    .accordion-toggle {
      display: block;
      padding: 15px;
    }

    .accordion-toggle .pro-header {
      border-bottom: none;
      padding-bottom: 0;
      align-items: center;
    }

    .accordion-chevron {
      display: grid;
    }

    .desktop-pro-header {
      display: none;
    }

    .accordion-content {
      max-height: 0;
      overflow: hidden;
      transition: max-height .32s ease, opacity .22s ease;
      opacity: 0;
      padding: 0 15px;
    }

    .professional-card.open .accordion-content {
      max-height: none;
      overflow: visible;
      opacity: 1;
      padding-bottom: 15px;
    }

    .professional-card.open .accordion-content .timeline-scroll-note {
      display: block;
    }

    .professional-card.open .accordion-content .timeline {
      max-height: 330px;
      overflow-y: auto;
      padding-right: 6px;
      -webkit-overflow-scrolling: touch;
      overscroll-behavior: contain;
    }

    .professional-card.open .accordion-content .timeline::-webkit-scrollbar {
      width: 6px;
    }

    .professional-card.open .accordion-content .timeline::-webkit-scrollbar-track {
      background: rgba(255,255,255,.04);
      border-radius: 999px;
    }

    .professional-card.open .accordion-content .timeline::-webkit-scrollbar-thumb {
      background: rgba(212,175,55,.35);
      border-radius: 999px;
    }

    .mini-stats {
      grid-template-columns: 1fr;
    }

    .timeline-item {
      grid-template-columns: 1fr;
    }

    .time-pill {
      width: max-content;
      min-width: 76px;
      min-height: 38px;
    }

    .appointment-actions {
      flex-direction: column;
      align-items: stretch;
    }

    .action-link,
    .status,
    .badge-rec,
    .badge-avulso {
      width: 100%;
      min-height: 36px;
    }
  }

  @media (min-width: 761px) {
    .accordion-content {
      display: block;
    }
  }
</style>

<div class="dashboard-hero">
  <div class="hero-row">
    <div>
      <h1>Central do <span>dia</span></h1>
      <p>
        Acompanhe os atendimentos ativos por profissional, veja os avulsos separados dos recorrentes e tenha uma leitura clara da operação.
      </p>
    </div>

    <div class="date-filter">
      <form method="GET">
        <input type="date" name="data" value="<?= htmlspecialchars($data); ?>" onchange="this.form.submit()">
        <input type="hidden" name="avulso_profissional" value="<?= htmlspecialchars($filtroAvulsosProfissional); ?>">
        <button type="submit">Filtrar agenda</button>
      </form>
      <div class="date-nav">
        <a class="date-nav-link" href="?data=<?= urlencode($dataAnterior); ?>&avulso_profissional=<?= urlencode($filtroAvulsosProfissional); ?>">Anterior</a>
        <a class="date-nav-link" href="?data=<?= urlencode(date('Y-m-d')); ?>&avulso_profissional=<?= urlencode($filtroAvulsosProfissional); ?>">Hoje</a>
        <a class="date-nav-link" href="?data=<?= urlencode($proximaData); ?>&avulso_profissional=<?= urlencode($filtroAvulsosProfissional); ?>">Próximo</a>
      </div>
    </div>
  </div>
</div>

<div class="stats-grid">
  <div class="metric-card">
    <small>Data selecionada</small>
    <strong><?= date('d/m/Y', strtotime($data)); ?></strong>
    <p>Visão consolidada dos profissionais neste dia.</p>
  </div>

  <div class="metric-card">
    <small>Total de atendimentos</small>
    <strong><?= (int)$totalAgendamentos; ?></strong>
    <p>Inclui avulsos e recorrentes/pacotes.</p>
  </div>

  <div class="metric-card">
    <small>Recorrentes / pacotes</small>
    <strong><?= (int)$totalRecorrentes; ?></strong>
    <p>Clientes de pacote mensal não entram na previsão de avulsos.</p>
  </div>

  <div class="metric-card">
    <small>Análises pendentes</small>
    <strong><?= (int)$totalPedidosAnalise; ?></strong>
    <p>Pedidos vindos de serviços sem preço fechado.</p>
  </div>

  <div class="metric-card highlight">
    <small>Previsão de avulsos</small>
    <strong>R$ <?= number_format($previsaoAvulsosFiltrada, 2, ',', '.'); ?></strong>
    <p><?= htmlspecialchars($labelPrevisaoAvulsos); ?> · não inclui recorrentes/pacotes.</p>

    <div class="avulso-filter">
      <form method="GET">
        <input type="hidden" name="data" value="<?= htmlspecialchars($data); ?>">
        <label for="avulso_profissional">Filtrar previsão</label>
        <select name="avulso_profissional" id="avulso_profissional">
          <option value="todos" <?= $filtroAvulsosProfissional === 'todos' ? 'selected' : ''; ?>>Todos</option>
          <?php foreach ($profissionaisFiltroAvulsos as $profId => $profNome): ?>
            <option value="<?= (int)$profId; ?>" <?= (string)$filtroAvulsosProfissional === (string)$profId ? 'selected' : ''; ?>>
              <?= htmlspecialchars($profNome); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button type="submit">Aplicar</button>
      </form>
    </div>
  </div>
</div>

<section class="radar-panel">
  <div class="radar-panel-head">
    <div>
      <h2>Radar de Retornos</h2>
      <p>Clientes que precisam da sua atenção</p>
    </div>
    <a class="radar-link" href="radar-retornos.php">Ver todos os retornos</a>
  </div>

  <div class="radar-summary-row">
    <?php
      $radarCardsDashboard = [
        ['key' => 'atrasado', 'label' => 'Atrasados', 'class' => 'late'],
        ['key' => 'hoje', 'label' => 'Hoje', 'class' => 'today'],
        ['key' => 'amanha', 'label' => 'Amanhã', 'class' => 'tomorrow'],
        ['key' => 'semana', 'label' => 'Esta semana', 'class' => 'week'],
        ['key' => 'risco', 'label' => 'Em risco', 'class' => 'risk'],
      ];
    ?>
    <?php foreach ($radarCardsDashboard as $card): ?>
      <?php if (($radarResumo[$card['key']] ?? 0) <= 0) continue; ?>
      <div class="radar-mini-card <?= htmlspecialchars($card['class']); ?>">
        <span><?= htmlspecialchars($card['label']); ?></span>
        <strong><?= (int)$radarResumo[$card['key']]; ?></strong>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if (!$radarPrioridades): ?>
    <div class="empty-state" style="padding:18px;margin-top:8px;">Tudo em dia por aqui! Nenhum cliente precisa de atenção agora.</div>
  <?php else: ?>
    <div class="radar-priority-list">
      <?php foreach ($radarPrioridades as $radar): ?>
        <div class="radar-priority-item">
          <div>
            <strong><?= htmlspecialchars($radar['cliente_nome']); ?> · <?= htmlspecialchars($radar['tipo_label']); ?></strong>
            <small><span class="radar-state"><?= htmlspecialchars($radar['estado_label']); ?></span><br><?= htmlspecialchars($radar['servico_nome']); ?> · <?= (int)$radar['frequencia_dias']; ?> dias</small>
          </div>
          <?php if (!empty($radar['whatsapp_phone'])): ?>
            <a class="radar-whats-mini" href="https://wa.me/<?= htmlspecialchars($radar['whatsapp_phone']); ?>?text=<?= urlencode($radar['whatsapp_message']); ?>" target="_blank" rel="noopener">WhatsApp</a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<?php if (!empty($pedidosAnalise)): ?>
  <section class="analysis-panel">
    <div class="section-title" style="margin:0 0 4px;">
      <div>
        <h2>Pedidos de análise</h2>
        <p>Clientes que chegaram por serviços que precisam de avaliação profissional.</p>
      </div>
    </div>
    <div class="analysis-list">
      <?php foreach ($pedidosAnalise as $pedido): ?>
        <?php $whatsPedido = telefoneWhatsappDashboard($pedido['telefone']); ?>
        <div class="analysis-item">
          <div>
            <strong><?= htmlspecialchars($pedido['nome']); ?></strong>
            <span>
              <?= htmlspecialchars($pedido['servico_nome']); ?> · <?= htmlspecialchars($pedido['profissional_nome']); ?>
              <?php if (!empty($pedido['categoria'])): ?> · <?= htmlspecialchars($pedido['categoria']); ?><?php endif; ?>
            </span>
          </div>
          <?php if ($whatsPedido): ?>
            <a class="action-link whats" href="https://wa.me/<?= htmlspecialchars($whatsPedido); ?>" target="_blank" rel="noopener noreferrer">WhatsApp</a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<div class="section-title">
  <div>
    <h2>Por profissional</h2>
    <p>No celular, toque no profissional para abrir ou fechar os horários.</p>
  </div>
</div>

<?php if ($totalAgendamentos > 0): ?>
  <div class="professionals-grid">
    <?php foreach ($porProfissional as $index => $prof): ?>
      <?php
        $cardAberto = $index === array_key_first($porProfissional);
      ?>
      <section class="professional-card <?= $cardAberto ? 'open' : ''; ?>">
        <button type="button" class="accordion-toggle js-pro-accordion">
          <div class="pro-header">
            <div class="pro-title-area">
              <h3><?= htmlspecialchars($prof['nome']); ?></h3>
              <?php if (!empty($prof['proximo'])): ?>
                <p>
                  Próximo: <?= htmlspecialchars(substr($prof['proximo']['hora'], 0, 5)); ?> —
                  <?= htmlspecialchars($prof['proximo']['cliente_nome']); ?>
                </p>
              <?php else: ?>
                <p><?= $dataSelecionadaEhHoje ? 'Sem próximos atendimentos para hoje.' : 'Sem próximo atendimento nesta data.'; ?></p>
              <?php endif; ?>
            </div>

            <div style="display:flex; align-items:center; gap:10px;">
              <div class="pro-count"><?= (int)$prof['total']; ?></div>
              <div class="accordion-chevron">⌄</div>
            </div>
          </div>
        </button>

        <div class="desktop-pro-header">
          <div class="pro-header">
            <div class="pro-title-area">
              <h3><?= htmlspecialchars($prof['nome']); ?></h3>
              <?php if (!empty($prof['proximo'])): ?>
                <p>
                  Próximo: <?= htmlspecialchars(substr($prof['proximo']['hora'], 0, 5)); ?> —
                  <?= htmlspecialchars($prof['proximo']['cliente_nome']); ?>
                </p>
              <?php else: ?>
                <p><?= $dataSelecionadaEhHoje ? 'Sem próximos atendimentos para hoje.' : 'Sem próximo atendimento nesta data.'; ?></p>
              <?php endif; ?>
            </div>

            <div class="pro-count"><?= (int)$prof['total']; ?></div>
          </div>
        </div>

        <div class="accordion-content">
          <div class="mini-stats">
            <div class="mini-stat">
              <small>Avulsos</small>
              <strong><?= (int)$prof['avulsos']; ?></strong>
            </div>

            <div class="mini-stat">
              <small>Recorrentes</small>
              <strong><?= (int)$prof['recorrentes']; ?></strong>
            </div>

            <div class="mini-stat">
              <small>Previsão avulsa</small>
              <strong>R$ <?= number_format((float)$prof['previsao_avulsos'], 2, ',', '.'); ?></strong>
            </div>
          </div>

          <div class="timeline-scroll-note">Role dentro da lista para ver os demais horários.</div>

          <div class="timeline">
            <?php foreach ($prof['agendamentos'] as $ag): ?>
              <?php
                $isRecorrente = (int)$ag['is_recorrente'] === 1;
                $whats = telefoneWhatsappDashboard($ag['cliente_telefone']);
              ?>
              <div class="timeline-item">
                <div class="time-pill"><?= htmlspecialchars(substr($ag['hora'], 0, 5)); ?></div>

                <div class="appointment-card">
                  <div class="appointment-top">
                    <div>
                      <div class="client-name"><?= htmlspecialchars($ag['cliente_nome']); ?></div>
                      <div class="service-name"><?= htmlspecialchars($ag['servico_nome']); ?></div>
                    </div>

                    <span class="status <?= htmlspecialchars($ag['status']); ?>">
                      <?= htmlspecialchars($ag['status']); ?>
                    </span>
                  </div>

                  <div class="appointment-actions">
                    <?php if ($isRecorrente): ?>
                      <span class="badge-rec">Recorrente</span>
                    <?php else: ?>
                      <span class="badge-avulso">Avulso</span>
                    <?php endif; ?>

                    <?php if ($whats): ?>
                      <a class="action-link whats" href="https://wa.me/<?= htmlspecialchars($whats); ?>" target="_blank" rel="noopener noreferrer">
                        WhatsApp
                      </a>
                    <?php endif; ?>

                    <a class="action-link danger"
                       href="cancelar-agendamento.php?id=<?= (int)$ag['id']; ?>&data=<?= urlencode($data); ?>&return_to=dashboard&csrf_token=<?= urlencode(csrf_token()); ?>"
                       onclick="return confirm('<?= $isRecorrente ? 'Este é um agendamento recorrente. Deseja cancelar a recorrência inteira?' : 'Deseja cancelar este agendamento?'; ?>');">
                      <?= $isRecorrente ? 'Excluir recorrência' : 'Cancelar'; ?>
                    </a>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="empty-state">
    Nenhum agendamento ativo encontrado para <?= date('d/m/Y', strtotime($data)); ?>.
  </div>
<?php endif; ?>

<script>
  document.querySelectorAll('.js-pro-accordion').forEach((button) => {
    button.addEventListener('click', () => {
      const card = button.closest('.professional-card');
      if (!card) return;

      const isOpen = card.classList.contains('open');

      document.querySelectorAll('.professional-card').forEach((item) => {
        item.classList.remove('open');
      });

      if (!isOpen) {
        card.classList.add('open');

        setTimeout(() => {
          card.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
          });
        }, 80);
      }
    });
  });
</script>

<?php admin_shell_end(); ?>
