<?php
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/admin-shell.php';

date_default_timezone_set('America/Sao_Paulo');

/**
 * =========================
 * HELPERS
 * =========================
 */
function brMoney(float $value): string {
    return 'R$ ' . number_format($value, 2, ',', '.');
}

function brDate(string $date): string {
    $ts = strtotime($date);
    return $ts ? date('d/m/Y', $ts) : $date;
}

function safePercent(int $part, int $total): float {
    if ($total <= 0) return 0;
    return round(($part / $total) * 100, 1);
}

function telefoneWhatsappRelatorio(string $tel): string {
    $numero = preg_replace('/\D+/', '', $tel);
    if ($numero === '') return '';
    if (strlen($numero) === 10 || strlen($numero) === 11) {
        return '55' . $numero;
    }
    return $numero;
}

function formatPhoneRelatorio(string $tel): string {
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

function profissionalFotoRelatorio(string $nome): string {
    $normalized = strtolower(trim(iconv('UTF-8', 'ASCII//TRANSLIT', $nome)));

    if (strpos($normalized, 'amaro') !== false) {
        return '../assets/profissionais/andre-amaro.png';
    }

    if (strpos($normalized, 'puchetti') !== false) {
        return '../assets/profissionais/andre-puchetti.png';
    }

    return '../assets/profissionais/andre-puchetti.png';
}

function tableExistsRelatorio(mysqli $conn, string $table): bool {
    $tableEsc = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$tableEsc}'");
    return $res && $res->num_rows > 0;
}

function getPeriodoDatas(): array {
    $periodo = $_GET['periodo'] ?? 'mes';
    $hoje = date('Y-m-d');

    switch ($periodo) {
        case 'hoje':
            $inicio = $hoje;
            $fim = $hoje;
            break;

        case 'semana':
            $inicio = date('Y-m-d', strtotime('monday this week'));
            $fim = date('Y-m-d', strtotime('sunday this week'));
            break;

        case 'ultimos_30':
            $inicio = date('Y-m-d', strtotime('-30 days'));
            $fim = $hoje;
            break;

        case 'personalizado':
            $inicio = $_GET['inicio'] ?? date('Y-m-01');
            $fim = $_GET['fim'] ?? $hoje;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $inicio)) $inicio = date('Y-m-01');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fim)) $fim = $hoje;
            break;

        case 'mes':
        default:
            $periodo = 'mes';
            $inicio = date('Y-m-01');
            $fim = date('Y-m-t');
            break;
    }

    if (strtotime($inicio) > strtotime($fim)) {
        [$inicio, $fim] = [$fim, $inicio];
    }

    return [$periodo, $inicio, $fim];
}

[$periodo, $inicio, $fim] = getPeriodoDatas();

$profissionalFiltro = $_GET['profissional_id'] ?? 'todos';
$tipoFiltro = $_GET['tipo'] ?? 'todos';

$profissionais = [];
$resProf = $conn->query("SELECT id, nome FROM profissionais ORDER BY nome ASC");
if ($resProf && $resProf->num_rows > 0) {
    while ($row = $resProf->fetch_assoc()) {
        $profissionais[] = $row;
    }
}

/**
 * =========================
 * QUERY BASE
 * =========================
 */
$sql = "
    SELECT
        ag.id,
        ag.data,
        ag.hora,
        ag.status,
        ag.is_recorrente,
        ag.profissional_id,
        c.id AS cliente_id,
        c.nome AS cliente_nome,
        c.telefone AS cliente_telefone,
        p.nome AS profissional_nome,
        s.id AS servico_id,
        s.nome AS servico_nome,
        s.preco AS servico_preco,
        s.duracao AS servico_duracao
    FROM agendamentos ag
    INNER JOIN clientes c ON c.id = ag.cliente_id
    INNER JOIN profissionais p ON p.id = ag.profissional_id
    INNER JOIN servicos s ON s.id = ag.servico_id
    WHERE ag.data BETWEEN ? AND ?
";

$params = [$inicio, $fim];
$types = 'ss';

if ($profissionalFiltro !== 'todos') {
    $sql .= " AND ag.profissional_id = ? ";
    $params[] = (int)$profissionalFiltro;
    $types .= 'i';
}

if ($tipoFiltro === 'avulsos') {
    $sql .= " AND (ag.is_recorrente IS NULL OR ag.is_recorrente = 0) ";
} elseif ($tipoFiltro === 'recorrentes') {
    $sql .= " AND ag.is_recorrente = 1 ";
}

$sql .= " ORDER BY ag.data ASC, ag.hora ASC ";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$agendamentos = [];
if ($res && $res->num_rows > 0) {
    while ($row = $res->fetch_assoc()) {
        $agendamentos[] = $row;
    }
}

/**
 * =========================
 * CÁLCULOS
 * =========================
 */
$totalAtendimentos = 0;
$totalConfirmados = 0;
$totalPendentes = 0;
$totalCancelados = 0;
$totalAvulsos = 0;
$totalRecorrentes = 0;
$previsaoAvulsos = 0.0;

$porProfissional = [];
$porDia = [];
$porServico = [];
$porHorario = [];
$clientesRanking = [];
$clientesSumidos = [];

foreach ($profissionais as $prof) {
    if ($profissionalFiltro !== 'todos' && (string)$prof['id'] !== (string)$profissionalFiltro) {
        continue;
    }

    $porProfissional[(int)$prof['id']] = [
        'id' => (int)$prof['id'],
        'nome' => $prof['nome'],
        'total' => 0,
        'confirmados' => 0,
        'pendentes' => 0,
        'cancelados' => 0,
        'avulsos' => 0,
        'recorrentes' => 0,
        'previsao_avulsos' => 0.0,
    ];
}

foreach ($agendamentos as $ag) {
    $totalAtendimentos++;

    $status = $ag['status'];
    $isRecorrente = (int)$ag['is_recorrente'] === 1;
    $profId = (int)$ag['profissional_id'];
    $valor = (float)$ag['servico_preco'];

    if ($status === 'confirmado') $totalConfirmados++;
    if ($status === 'pendente') $totalPendentes++;
    if ($status === 'cancelado') $totalCancelados++;

    if ($isRecorrente) {
        $totalRecorrentes++;
    } else {
        $totalAvulsos++;
        if ($status !== 'cancelado') {
            $previsaoAvulsos += $valor;
        }
    }

    if (!isset($porProfissional[$profId])) {
        $porProfissional[$profId] = [
            'id' => $profId,
            'nome' => $ag['profissional_nome'],
            'total' => 0,
            'confirmados' => 0,
            'pendentes' => 0,
            'cancelados' => 0,
            'avulsos' => 0,
            'recorrentes' => 0,
            'previsao_avulsos' => 0.0,
        ];
    }

    $porProfissional[$profId]['total']++;
    if ($status === 'confirmado') $porProfissional[$profId]['confirmados']++;
    if ($status === 'pendente') $porProfissional[$profId]['pendentes']++;
    if ($status === 'cancelado') $porProfissional[$profId]['cancelados']++;

    if ($isRecorrente) {
        $porProfissional[$profId]['recorrentes']++;
    } else {
        $porProfissional[$profId]['avulsos']++;
        if ($status !== 'cancelado') {
            $porProfissional[$profId]['previsao_avulsos'] += $valor;
        }
    }

    $diaKey = $ag['data'];
    if (!isset($porDia[$diaKey])) {
        $porDia[$diaKey] = 0;
    }
    $porDia[$diaKey]++;

    $servKey = $ag['servico_nome'];
    if (!isset($porServico[$servKey])) {
        $porServico[$servKey] = 0;
    }
    $porServico[$servKey]++;

    $horaKey = substr($ag['hora'], 0, 5);
    if (!isset($porHorario[$horaKey])) {
        $porHorario[$horaKey] = 0;
    }
    $porHorario[$horaKey]++;

    $clienteId = (int)$ag['cliente_id'];
    if (!isset($clientesRanking[$clienteId])) {
        $clientesRanking[$clienteId] = [
            'id' => $clienteId,
            'nome' => $ag['cliente_nome'],
            'telefone' => $ag['cliente_telefone'],
            'total' => 0,
            'ultimo' => null,
        ];
    }

    $clientesRanking[$clienteId]['total']++;
    if ($clientesRanking[$clienteId]['ultimo'] === null || strtotime($ag['data']) > strtotime($clientesRanking[$clienteId]['ultimo'])) {
        $clientesRanking[$clienteId]['ultimo'] = $ag['data'];
    }
}

arsort($porDia);
arsort($porServico);
arsort($porHorario);

usort($clientesRanking, function($a, $b) {
    return $b['total'] <=> $a['total'];
});

$topClientes = array_slice($clientesRanking, 0, 8);
$topServicos = array_slice($porServico, 0, 8, true);
$topHorarios = array_slice($porHorario, 0, 8, true);

$taxaCancelamento = safePercent($totalCancelados, max(1, $totalAtendimentos));

/**
 * Clientes sumidos: clientes com último agendamento há 30+ dias e sem próximo confirmado/pendente.
 */
$sqlSumidos = "
    SELECT
        c.id,
        c.nome,
        c.telefone,
        MAX(ag.data) AS ultimo_agendamento
    FROM clientes c
    INNER JOIN agendamentos ag ON ag.cliente_id = c.id
    WHERE ag.status IN ('confirmado', 'pendente')
    GROUP BY c.id, c.nome, c.telefone
    HAVING ultimo_agendamento <= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ORDER BY ultimo_agendamento ASC
    LIMIT 8
";
$resSumidos = $conn->query($sqlSumidos);
if ($resSumidos && $resSumidos->num_rows > 0) {
    while ($row = $resSumidos->fetch_assoc()) {
        $clientesSumidos[] = $row;
    }
}

/**
 * Funil do agendamento público: eventos anônimos, sem nome/telefone do cliente.
 */
$analyticsDisponivel = tableExistsRelatorio($conn, 'analytics_eventos');
$eventLabels = [
    'page_view' => 'Acessaram',
    'inicio_fluxo' => 'Começaram',
    'categoria_escolhida' => 'Categoria',
    'profissional_escolhido' => 'Profissional',
    'servico_escolhido' => 'Serviço',
    'data_escolhida' => 'Data',
    'horario_escolhido' => 'Horário',
    'agendamento_finalizado' => 'Finalizaram',
    'pedido_analise_enviado' => 'Análise',
];
$eventOrder = array_keys($eventLabels);
$funilEventos = array_fill_keys($eventOrder, 0);
$funilServicos = [];
$funilProfissionais = [];

if ($analyticsDisponivel) {
    $sqlFunil = "
        SELECT evento, COUNT(DISTINCT sessao) AS total
        FROM analytics_eventos
        WHERE DATE(criado_em) BETWEEN ? AND ?
    ";
    $paramsFunil = [$inicio, $fim];
    $typesFunil = 'ss';

    if ($profissionalFiltro !== 'todos') {
        $sqlFunil .= " AND profissional_id = ? ";
        $paramsFunil[] = (int)$profissionalFiltro;
        $typesFunil .= 'i';
    }

    $sqlFunil .= " GROUP BY evento ";
    $stmtFunil = $conn->prepare($sqlFunil);
    $stmtFunil->bind_param($typesFunil, ...$paramsFunil);
    $stmtFunil->execute();
    $resFunil = $stmtFunil->get_result();
    while ($resFunil && $row = $resFunil->fetch_assoc()) {
        if (isset($funilEventos[$row['evento']])) {
            $funilEventos[$row['evento']] = (int)$row['total'];
        }
    }

    $sqlFunilServicos = "
        SELECT s.nome, COUNT(DISTINCT ae.sessao) AS total
        FROM analytics_eventos ae
        INNER JOIN servicos s ON s.id = ae.servico_id
        WHERE ae.evento = 'servico_escolhido'
          AND DATE(ae.criado_em) BETWEEN ? AND ?
    ";
    $paramsFunilServicos = [$inicio, $fim];
    $typesFunilServicos = 'ss';
    if ($profissionalFiltro !== 'todos') {
        $sqlFunilServicos .= " AND ae.profissional_id = ? ";
        $paramsFunilServicos[] = (int)$profissionalFiltro;
        $typesFunilServicos .= 'i';
    }
    $sqlFunilServicos .= " GROUP BY s.id, s.nome ORDER BY total DESC LIMIT 3 ";
    $stmtFunilServicos = $conn->prepare($sqlFunilServicos);
    $stmtFunilServicos->bind_param($typesFunilServicos, ...$paramsFunilServicos);
    $stmtFunilServicos->execute();
    $resFunilServicos = $stmtFunilServicos->get_result();
    while ($resFunilServicos && $row = $resFunilServicos->fetch_assoc()) {
        $funilServicos[] = $row;
    }

    $sqlFunilProfissionais = "
        SELECT p.nome, COUNT(DISTINCT ae.sessao) AS total
        FROM analytics_eventos ae
        INNER JOIN profissionais p ON p.id = ae.profissional_id
        WHERE ae.evento = 'profissional_escolhido'
          AND DATE(ae.criado_em) BETWEEN ? AND ?
    ";
    $paramsFunilProf = [$inicio, $fim];
    $typesFunilProf = 'ss';
    if ($profissionalFiltro !== 'todos') {
        $sqlFunilProfissionais .= " AND ae.profissional_id = ? ";
        $paramsFunilProf[] = (int)$profissionalFiltro;
        $typesFunilProf .= 'i';
    }
    $sqlFunilProfissionais .= " GROUP BY p.id, p.nome ORDER BY total DESC LIMIT 3 ";
    $stmtFunilProf = $conn->prepare($sqlFunilProfissionais);
    $stmtFunilProf->bind_param($typesFunilProf, ...$paramsFunilProf);
    $stmtFunilProf->execute();
    $resFunilProf = $stmtFunilProf->get_result();
    while ($resFunilProf && $row = $resFunilProf->fetch_assoc()) {
        $funilProfissionais[] = $row;
    }
}

$funilBase = max(1, (int)$funilEventos['page_view']);
$funilFinalizados = (int)$funilEventos['agendamento_finalizado'];
$funilAnalises = (int)$funilEventos['pedido_analise_enviado'];
$funilConversao = safePercent($funilFinalizados + $funilAnalises, $funilBase);

admin_shell_start('Relatórios | André Puchetti', 'relatorios');
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

  .reports-hero {
    margin-bottom: 22px;
    position: relative;
  }

  .reports-hero::after {
    content:"";
    position:absolute;
    inset:auto 0 -12px 0;
    height:1px;
    background:linear-gradient(90deg, transparent, rgba(212,175,55,.22), transparent);
  }

  .hero-row {
    display:flex;
    justify-content:space-between;
    align-items:flex-end;
    gap:18px;
    flex-wrap:wrap;
  }

  .reports-hero h1 {
    margin:0 0 10px;
    font-size:clamp(2.1rem,5vw,3.8rem);
    line-height:.92;
    letter-spacing:-.06em;
    font-weight:900;
  }

  .reports-hero h1 span {
    display:block;
    background:linear-gradient(90deg,#fff4cc 0%,#d4af37 55%,#fff0a8 100%);
    -webkit-background-clip:text;
    -webkit-text-fill-color:transparent;
    background-clip:text;
    color:transparent;
  }

  .reports-hero p {
    margin:0;
    max-width:820px;
    color:var(--soft);
    line-height:1.7;
  }

  .filters-card,
  .metric-card,
  .panel,
  .empty-state {
    border-radius:24px;
    background:
      linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03)),
      radial-gradient(circle at top left, rgba(212,175,55,.055), transparent 42%);
    border:1px solid rgba(255,255,255,.08);
    box-shadow:
      0 18px 48px rgba(0,0,0,.38),
      inset 0 1px 0 rgba(255,255,255,.04);
    backdrop-filter:blur(18px);
    -webkit-backdrop-filter:blur(18px);
  }

  .filters-card {
    padding:16px;
    margin-bottom:18px;
  }

  .filters-form {
    display:grid;
    grid-template-columns: 1.1fr 1fr 1fr 1fr 1fr auto;
    gap:12px;
    align-items:end;
  }

  .field label {
    display:block;
    color:var(--gold-soft);
    font-size:10px;
    font-weight:900;
    letter-spacing:.14em;
    text-transform:uppercase;
    margin-bottom:7px;
  }

  .field input,
  .field select {
    width:100%;
    min-height:46px;
    border-radius:14px;
    border:1px solid rgba(255,255,255,.08);
    background:rgba(255,255,255,.04);
    color:var(--text);
    padding:0 13px;
    outline:none;
  }

  .filter-btn {
    min-height:46px;
    border:none;
    border-radius:14px;
    cursor:pointer;
    padding:0 18px;
    background:linear-gradient(90deg,#c8a22a 0%,#f2d778 50%,#cfa72d 100%);
    color:#1a1405;
    font-weight:900;
    white-space:nowrap;
  }

  .stats-grid {
    display:grid;
    grid-template-columns: repeat(5, 1fr);
    gap:14px;
    margin-bottom:20px;
  }

  .metric-card {
    padding:18px;
    min-height:130px;
    position:relative;
    overflow:hidden;
  }

  .metric-card::before {
    content:"";
    position:absolute;
    width:120px;
    height:120px;
    right:-70px;
    top:-70px;
    border-radius:50%;
    background:rgba(212,175,55,.08);
    filter:blur(4px);
  }

  .metric-card small {
    display:block;
    color:var(--gold-soft);
    font-size:10px;
    letter-spacing:.16em;
    text-transform:uppercase;
    font-weight:900;
    margin-bottom:9px;
  }

  .metric-card strong {
    display:block;
    font-size:clamp(1.45rem,3vw,2rem);
    letter-spacing:-.04em;
    line-height:1;
    margin-bottom:9px;
    color:var(--text);
  }

  .metric-card p {
    margin:0;
    color:var(--muted);
    line-height:1.5;
    font-size:.88rem;
  }

  .metric-card.highlight {
    background:
      linear-gradient(180deg, rgba(212,175,55,.12), rgba(255,255,255,.03)),
      radial-gradient(circle at top left, rgba(212,175,55,.18), transparent 42%);
    border-color:rgba(212,175,55,.18);
  }

  .funnel-panel {
    padding:18px;
    margin-bottom:20px;
  }

  .funnel-top {
    display:grid;
    grid-template-columns:1.2fr .8fr;
    gap:18px;
    align-items:start;
  }

  .funnel-title h2 {
    margin:0 0 7px;
    font-size:clamp(1.35rem,3vw,2rem);
    letter-spacing:-.04em;
  }

  .funnel-title p {
    margin:0;
    color:var(--muted);
    line-height:1.55;
  }

  .funnel-summary {
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:10px;
  }

  .funnel-mini {
    padding:12px;
    border-radius:17px;
    background:rgba(255,255,255,.035);
    border:1px solid rgba(255,255,255,.06);
  }

  .funnel-mini span {
    display:block;
    color:var(--gold-soft);
    font-size:9px;
    font-weight:900;
    letter-spacing:.14em;
    text-transform:uppercase;
    margin-bottom:7px;
  }

  .funnel-mini strong {
    display:block;
    font-size:1.35rem;
    letter-spacing:-.04em;
  }

  .funnel-flow {
    margin-top:16px;
    display:grid;
    gap:9px;
  }

  .funnel-step {
    display:grid;
    grid-template-columns:145px 1fr 56px;
    gap:12px;
    align-items:center;
  }

  .funnel-step-label {
    color:rgba(247,243,234,.84);
    font-weight:900;
    font-size:.92rem;
  }

  .funnel-bar-wrap {
    height:14px;
    overflow:hidden;
    border-radius:999px;
    background:rgba(255,255,255,.055);
    border:1px solid rgba(255,255,255,.06);
  }

  .funnel-bar {
    height:100%;
    border-radius:999px;
    background:linear-gradient(90deg,#20c997,#f2d778);
    min-width:2px;
  }

  .funnel-step-value {
    color:#fff1bf;
    font-weight:900;
    text-align:right;
  }

  .funnel-side {
    margin-top:16px;
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:12px;
  }

  .funnel-list {
    padding:13px;
    border-radius:18px;
    background:rgba(255,255,255,.03);
    border:1px solid rgba(255,255,255,.06);
  }

  .funnel-list h3 {
    margin:0 0 10px;
    font-size:1rem;
    letter-spacing:-.03em;
  }

  .funnel-list-row {
    display:flex;
    justify-content:space-between;
    gap:10px;
    color:rgba(247,243,234,.78);
    font-size:.9rem;
    padding:7px 0;
    border-top:1px solid rgba(255,255,255,.055);
  }

  .funnel-list-row strong {
    color:#fff1bf;
  }

  .section-title {
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
    margin:24px 0 14px;
  }

  .section-title h2 {
    margin:0;
    font-size:clamp(1.45rem,3vw,2.1rem);
    letter-spacing:-.045em;
    line-height:1;
  }

  .section-title p {
    margin:0;
    color:var(--muted);
    line-height:1.55;
  }

  .panels-grid {
    display:grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap:16px;
  }

  .panel {
    padding:16px;
    overflow:hidden;
  }

  .panel-header {
    display:flex;
    justify-content:space-between;
    gap:12px;
    align-items:flex-start;
    margin-bottom:14px;
    padding-bottom:12px;
    border-bottom:1px solid rgba(255,255,255,.07);
  }

  .panel-header h3 {
    margin:0 0 6px;
    font-size:1.2rem;
    letter-spacing:-.035em;
  }

  .panel-header p {
    margin:0;
    color:var(--muted);
    font-size:.9rem;
    line-height:1.45;
  }

  .rank-list {
    display:grid;
    gap:10px;
  }

  .rank-item {
    display:grid;
    grid-template-columns: 42px 1fr auto;
    gap:12px;
    align-items:center;
    padding:12px;
    border-radius:17px;
    background:rgba(255,255,255,.035);
    border:1px solid rgba(255,255,255,.06);
  }

  .rank-num {
    width:38px;
    height:38px;
    display:grid;
    place-items:center;
    border-radius:13px;
    background:rgba(212,175,55,.12);
    border:1px solid rgba(212,175,55,.18);
    color:#fff1bf;
    font-weight:900;
  }

  .rank-main strong {
    display:block;
    color:var(--text);
    line-height:1.35;
  }

  .rank-main span {
    display:block;
    color:var(--muted);
    font-size:.88rem;
    line-height:1.45;
  }

  .rank-value {
    color:#fff1bf;
    font-weight:900;
    white-space:nowrap;
    text-align:right;
  }

  .professional-grid {
    display:grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap:16px;
  }

  .pro-performance-card {
    position:relative;
    overflow:hidden;
    min-height:330px;
    padding:18px;
    border-radius:26px;
    background:
      linear-gradient(180deg, rgba(255,255,255,.065), rgba(255,255,255,.03)),
      radial-gradient(circle at 18% 12%, rgba(212,175,55,.16), transparent 36%);
    border:1px solid rgba(255,255,255,.08);
    box-shadow:
      0 18px 48px rgba(0,0,0,.38),
      inset 0 1px 0 rgba(255,255,255,.04);
  }

  .pro-performance-card::after {
    content:"";
    position:absolute;
    width:220px;
    height:220px;
    right:-95px;
    bottom:-95px;
    border-radius:999px;
    background:rgba(212,175,55,.08);
    filter:blur(4px);
    pointer-events:none;
  }

  .pro-visual-top {
    position:relative;
    z-index:1;
    display:grid;
    grid-template-columns: 108px 1fr;
    gap:16px;
    align-items:center;
    margin-bottom:16px;
  }

  .pro-avatar-wrap {
    position:relative;
    width:108px;
    height:108px;
    border-radius:28px;
    overflow:hidden;
    background:rgba(255,255,255,.04);
    border:1px solid rgba(212,175,55,.18);
    box-shadow:0 18px 38px rgba(0,0,0,.30);
  }

  .pro-avatar-wrap img {
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
  }

  .pro-avatar-wrap::after {
    content:"";
    position:absolute;
    inset:0;
    background:linear-gradient(180deg, transparent 35%, rgba(0,0,0,.42));
    pointer-events:none;
  }

  .pro-title h3 {
    margin:0 0 8px;
    font-size:1.35rem;
    letter-spacing:-.04em;
    line-height:1;
  }

  .pro-title p {
    margin:0;
    color:var(--muted);
    font-size:.9rem;
    line-height:1.5;
  }

  .pro-gauge-row {
    position:relative;
    z-index:1;
    display:grid;
    grid-template-columns: 170px 1fr;
    gap:18px;
    align-items:center;
  }

  .gauge {
    --value: 0;
    position:relative;
    width:160px;
    height:160px;
    border-radius:999px;
    display:grid;
    place-items:center;
    background:
      conic-gradient(from 225deg, #d4af37 calc(var(--value) * 2.7deg), rgba(255,255,255,.08) 0 270deg, transparent 270deg 360deg);
  }

  .gauge::before {
    content:"";
    position:absolute;
    width:118px;
    height:118px;
    border-radius:999px;
    background:linear-gradient(180deg, rgba(10,10,10,.96), rgba(18,18,18,.94));
    border:1px solid rgba(255,255,255,.06);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.04);
  }

  .gauge-inner {
    position:relative;
    z-index:2;
    text-align:center;
  }

  .gauge-inner strong {
    display:block;
    font-size:2rem;
    line-height:1;
    letter-spacing:-.05em;
    color:#fff1bf;
  }

  .gauge-inner span {
    display:block;
    margin-top:6px;
    color:rgba(247,243,234,.58);
    font-size:.75rem;
    font-weight:800;
    letter-spacing:.08em;
    text-transform:uppercase;
  }

  .pro-metrics {
    display:grid;
    gap:9px;
  }

  .pro-metric-line {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    min-height:42px;
    padding:0 12px;
    border-radius:15px;
    background:rgba(255,255,255,.035);
    border:1px solid rgba(255,255,255,.06);
  }

  .pro-metric-line span {
    color:var(--muted);
    font-size:.86rem;
    line-height:1.35;
  }

  .pro-metric-line strong {
    color:var(--text);
    font-size:.98rem;
    white-space:nowrap;
  }

  .pro-note {
    position:relative;
    z-index:1;
    margin-top:14px;
    padding:12px 14px;
    border-radius:17px;
    background:rgba(212,175,55,.08);
    border:1px solid rgba(212,175,55,.14);
    color:rgba(247,243,234,.72);
    line-height:1.5;
    font-size:.88rem;
  }

  .whats-link {
    display:inline-flex;
    min-height:30px;
    align-items:center;
    justify-content:center;
    padding:0 11px;
    border-radius:999px;
    background:rgba(32,201,151,.10);
    color:#d8fff2;
    border:1px solid rgba(32,201,151,.18);
    text-decoration:none;
    font-size:10px;
    font-weight:900;
    letter-spacing:.07em;
    text-transform:uppercase;
    white-space:nowrap;
  }

  .empty-state {
    padding:26px 22px;
    color:var(--muted);
    line-height:1.7;
  }

  .bar-wrap {
    height:8px;
    margin-top:10px;
    border-radius:999px;
    background:rgba(255,255,255,.06);
    overflow:hidden;
  }

  .bar {
    height:100%;
    border-radius:999px;
    background:linear-gradient(90deg,#c8a22a,#f2d778);
    width:0%;
  }

  @media (max-width:1200px) {
    .stats-grid {
      grid-template-columns: repeat(2, 1fr);
    }

    .filters-form {
      grid-template-columns: repeat(2, 1fr);
    }

    .filter-btn {
      width:100%;
    }
  }

  @media (max-width:900px) {
    .funnel-top,
    .funnel-side {
      grid-template-columns:1fr;
    }

    .panels-grid {
      grid-template-columns:1fr;
    }

    .professional-grid {
      grid-template-columns:1fr;
    }

    .pro-gauge-row {
      grid-template-columns: 150px 1fr;
    }

    .gauge {
      width:142px;
      height:142px;
    }

    .gauge::before {
      width:104px;
      height:104px;
    }
  }

  @media (max-width:680px) {
    .hero-row {
      align-items:stretch;
    }

    .stats-grid,
    .filters-form,
    .funnel-summary {
      grid-template-columns:1fr;
    }

    .funnel-step {
      grid-template-columns:1fr;
      gap:7px;
    }

    .funnel-step-value {
      text-align:left;
    }

    .rank-item {
      grid-template-columns: 1fr;
    }

    .rank-num {
      width:max-content;
      min-width:38px;
    }

    .rank-value {
      text-align:left;
    }

    .pro-visual-top,
    .pro-gauge-row {
      grid-template-columns:1fr;
    }

    .pro-avatar-wrap {
      width:96px;
      height:96px;
    }

    .gauge {
      margin-inline:auto;
    }
  }
</style>

<div class="reports-hero">
  <div class="hero-row">
    <div>
      <h1>Relatórios <span>inteligentes</span></h1>
      <p>
        Acompanhe atendimentos, avulsos, recorrentes, profissionais, clientes e cancelamentos sem misturar pacotes mensais com previsão de avulsos.
      </p>
    </div>
  </div>
</div>

<div class="filters-card">
  <form method="GET" class="filters-form">
    <div class="field">
      <label for="periodo">Período</label>
      <select name="periodo" id="periodo">
        <option value="hoje" <?= $periodo === 'hoje' ? 'selected' : ''; ?>>Hoje</option>
        <option value="semana" <?= $periodo === 'semana' ? 'selected' : ''; ?>>Esta semana</option>
        <option value="mes" <?= $periodo === 'mes' ? 'selected' : ''; ?>>Este mês</option>
        <option value="ultimos_30" <?= $periodo === 'ultimos_30' ? 'selected' : ''; ?>>Últimos 30 dias</option>
        <option value="personalizado" <?= $periodo === 'personalizado' ? 'selected' : ''; ?>>Personalizado</option>
      </select>
    </div>

    <div class="field">
      <label for="inicio">Início</label>
      <input type="date" name="inicio" id="inicio" value="<?= htmlspecialchars($inicio); ?>">
    </div>

    <div class="field">
      <label for="fim">Fim</label>
      <input type="date" name="fim" id="fim" value="<?= htmlspecialchars($fim); ?>">
    </div>

    <div class="field">
      <label for="profissional_id">Profissional</label>
      <select name="profissional_id" id="profissional_id">
        <option value="todos">Todos</option>
        <?php foreach ($profissionais as $prof): ?>
          <option value="<?= (int)$prof['id']; ?>" <?= (string)$profissionalFiltro === (string)$prof['id'] ? 'selected' : ''; ?>>
            <?= htmlspecialchars($prof['nome']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="tipo">Tipo</label>
      <select name="tipo" id="tipo">
        <option value="todos" <?= $tipoFiltro === 'todos' ? 'selected' : ''; ?>>Todos</option>
        <option value="avulsos" <?= $tipoFiltro === 'avulsos' ? 'selected' : ''; ?>>Avulsos</option>
        <option value="recorrentes" <?= $tipoFiltro === 'recorrentes' ? 'selected' : ''; ?>>Recorrentes</option>
      </select>
    </div>

    <button type="submit" class="filter-btn">Aplicar filtros</button>
  </form>
</div>

<div class="stats-grid">
  <div class="metric-card">
    <small>Período analisado</small>
    <strong><?= brDate($inicio); ?> — <?= brDate($fim); ?></strong>
    <p>Dados filtrados conforme profissional e tipo selecionados.</p>
  </div>

  <div class="metric-card">
    <small>Total de atendimentos</small>
    <strong><?= (int)$totalAtendimentos; ?></strong>
    <p>Quantidade total de registros no período.</p>
  </div>

  <div class="metric-card">
    <small>Avulsos</small>
    <strong><?= (int)$totalAvulsos; ?></strong>
    <p>Atendimentos não recorrentes.</p>
  </div>

  <div class="metric-card">
    <small>Recorrentes / pacotes</small>
    <strong><?= (int)$totalRecorrentes; ?></strong>
    <p>Clientes de pacote mensal ficam separados.</p>
  </div>

  <div class="metric-card highlight">
    <small>Previsão de avulsos</small>
    <strong><?= brMoney($previsaoAvulsos); ?></strong>
    <p>Não inclui recorrentes/pacotes nem cancelados.</p>
  </div>
</div>

<section class="panel funnel-panel">
  <div class="funnel-top">
    <div class="funnel-title">
      <h2>Funil do agendamento</h2>
      <p>Leitura leve do caminho do cliente no agendamento público. Os eventos são anônimos e ajudam a ver onde o fluxo ganha ou perde pessoas.</p>
    </div>

    <div class="funnel-summary">
      <div class="funnel-mini">
        <span>Acessos</span>
        <strong><?= (int)$funilEventos['page_view']; ?></strong>
      </div>
      <div class="funnel-mini">
        <span>Finalizaram</span>
        <strong><?= (int)$funilFinalizados; ?></strong>
      </div>
      <div class="funnel-mini">
        <span>Conversão</span>
        <strong><?= $funilConversao; ?>%</strong>
      </div>
    </div>
  </div>

  <?php if (!$analyticsDisponivel): ?>
    <div class="empty-state" style="margin-top:16px">O funil será exibido assim que a tabela de eventos estiver disponível.</div>
  <?php else: ?>
    <div class="funnel-flow">
      <?php foreach ($eventOrder as $eventKey): ?>
        <?php
          $valorEtapa = (int)$funilEventos[$eventKey];
          $larguraEtapa = min(100, max(2, safePercent($valorEtapa, $funilBase)));
        ?>
        <div class="funnel-step">
          <div class="funnel-step-label"><?= htmlspecialchars($eventLabels[$eventKey]); ?></div>
          <div class="funnel-bar-wrap">
            <div class="funnel-bar" style="width:<?= $larguraEtapa; ?>%"></div>
          </div>
          <div class="funnel-step-value"><?= $valorEtapa; ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="funnel-side">
      <div class="funnel-list">
        <h3>Mais escolhidos no funil</h3>
        <?php if (!empty($funilServicos)): ?>
          <?php foreach ($funilServicos as $item): ?>
            <div class="funnel-list-row">
              <span><?= htmlspecialchars($item['nome']); ?></span>
              <strong><?= (int)$item['total']; ?></strong>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="funnel-list-row"><span>Sem serviços rastreados ainda.</span><strong>0</strong></div>
        <?php endif; ?>
      </div>

      <div class="funnel-list">
        <h3>Profissionais no funil</h3>
        <?php if (!empty($funilProfissionais)): ?>
          <?php foreach ($funilProfissionais as $item): ?>
            <div class="funnel-list-row">
              <span><?= htmlspecialchars($item['nome']); ?></span>
              <strong><?= (int)$item['total']; ?></strong>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="funnel-list-row"><span>Sem profissionais rastreados ainda.</span><strong>0</strong></div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</section>

<div class="section-title">
  <div>
    <h2>Por profissional</h2>
    <p>Velocímetro de participação, avulsos, recorrentes e previsão avulsa.</p>
  </div>
</div>

<?php if (!empty($porProfissional)): ?>
  <div class="professional-grid">
    <?php foreach ($porProfissional as $prof): ?>
      <?php
        $percentProf = safePercent((int)$prof['total'], max(1, $totalAtendimentos));
        $fotoProfissional = profissionalFotoRelatorio($prof['nome']);
        $textoResumo = (int)$prof['total'] === 1 ? '1 atendimento no período filtrado.' : (int)$prof['total'] . ' atendimentos no período filtrado.';
      ?>
      <div class="pro-performance-card">
        <div class="pro-visual-top">
          <div class="pro-avatar-wrap">
            <img src="<?= htmlspecialchars($fotoProfissional); ?>" alt="<?= htmlspecialchars($prof['nome']); ?>">
          </div>

          <div class="pro-title">
            <h3><?= htmlspecialchars($prof['nome']); ?></h3>
            <p><?= htmlspecialchars($textoResumo); ?></p>
          </div>
        </div>

        <div class="pro-gauge-row">
          <div class="gauge" style="--value: <?= min(100, max(0, $percentProf)); ?>;">
            <div class="gauge-inner">
              <strong><?= (int)$prof['total']; ?></strong>
              <span>Atend.</span>
            </div>
          </div>

          <div class="pro-metrics">
            <div class="pro-metric-line">
              <span>Participação no período</span>
              <strong><?= $percentProf; ?>%</strong>
            </div>

            <div class="pro-metric-line">
              <span>Avulsos</span>
              <strong><?= (int)$prof['avulsos']; ?></strong>
            </div>

            <div class="pro-metric-line">
              <span>Recorrentes / pacotes</span>
              <strong><?= (int)$prof['recorrentes']; ?></strong>
            </div>

            <div class="pro-metric-line">
              <span>Cancelados</span>
              <strong><?= (int)$prof['cancelados']; ?></strong>
            </div>

            <div class="pro-metric-line">
              <span>Previsão avulsa</span>
              <strong><?= brMoney((float)$prof['previsao_avulsos']); ?></strong>
            </div>
          </div>
        </div>

        <div class="pro-note">
          A previsão avulsa considera apenas atendimentos não recorrentes e não cancelados. Pacotes mensais ficam separados.
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="empty-state">Nenhum profissional encontrado neste filtro.</div>
<?php endif; ?>

<div class="section-title">
  <div>
    <h2>Leituras estratégicas</h2>
    <p>Serviços, horários, clientes e cancelamentos para ajudar na operação.</p>
  </div>
</div>

<div class="panels-grid">
  <section class="panel">
    <div class="panel-header">
      <div>
        <h3>Serviços mais pedidos</h3>
        <p>Top serviços dentro do período filtrado.</p>
      </div>
    </div>

    <?php if (!empty($topServicos)): ?>
      <div class="rank-list">
        <?php $i = 1; foreach ($topServicos as $servico => $qtd): ?>
          <div class="rank-item">
            <div class="rank-num"><?= $i; ?></div>
            <div class="rank-main">
              <strong><?= htmlspecialchars($servico); ?></strong>
              <span><?= safePercent((int)$qtd, max(1, $totalAtendimentos)); ?>% dos atendimentos</span>
            </div>
            <div class="rank-value"><?= (int)$qtd; ?></div>
          </div>
        <?php $i++; endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty-state">Sem dados de serviços neste período.</div>
    <?php endif; ?>
  </section>

  <section class="panel">
    <div class="panel-header">
      <div>
        <h3>Horários mais usados</h3>
        <p>Ajuda a identificar picos e horários de maior demanda.</p>
      </div>
    </div>

    <?php if (!empty($topHorarios)): ?>
      <div class="rank-list">
        <?php $i = 1; foreach ($topHorarios as $horario => $qtd): ?>
          <div class="rank-item">
            <div class="rank-num"><?= $i; ?></div>
            <div class="rank-main">
              <strong><?= htmlspecialchars($horario); ?></strong>
              <span><?= safePercent((int)$qtd, max(1, $totalAtendimentos)); ?>% dos atendimentos</span>
            </div>
            <div class="rank-value"><?= (int)$qtd; ?></div>
          </div>
        <?php $i++; endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty-state">Sem dados de horários neste período.</div>
    <?php endif; ?>
  </section>

  <section class="panel">
    <div class="panel-header">
      <div>
        <h3>Clientes que mais voltam</h3>
        <p>Ranking de clientes com mais atendimentos no período.</p>
      </div>
    </div>

    <?php if (!empty($topClientes)): ?>
      <div class="rank-list">
        <?php $i = 1; foreach ($topClientes as $cliente): ?>
          <?php $whats = telefoneWhatsappRelatorio($cliente['telefone']); ?>
          <div class="rank-item">
            <div class="rank-num"><?= $i; ?></div>
            <div class="rank-main">
              <strong><?= htmlspecialchars($cliente['nome']); ?></strong>
              <span>Último: <?= brDate($cliente['ultimo']); ?> · <?= formatPhoneRelatorio($cliente['telefone']); ?></span>
            </div>
            <div class="rank-value">
              <?= (int)$cliente['total']; ?>
              <?php if ($whats): ?>
                <br><a class="whats-link" href="https://wa.me/<?= htmlspecialchars($whats); ?>" target="_blank" rel="noopener noreferrer">WhatsApp</a>
              <?php endif; ?>
            </div>
          </div>
        <?php $i++; endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty-state">Sem clientes neste período.</div>
    <?php endif; ?>
  </section>

  <section class="panel">
    <div class="panel-header">
      <div>
        <h3>Clientes sumidos</h3>
        <p>Clientes com último atendimento há 30+ dias.</p>
      </div>
    </div>

    <?php if (!empty($clientesSumidos)): ?>
      <div class="rank-list">
        <?php $i = 1; foreach ($clientesSumidos as $cliente): ?>
          <?php $whats = telefoneWhatsappRelatorio($cliente['telefone']); ?>
          <div class="rank-item">
            <div class="rank-num"><?= $i; ?></div>
            <div class="rank-main">
              <strong><?= htmlspecialchars($cliente['nome']); ?></strong>
              <span>Último atendimento: <?= brDate($cliente['ultimo_agendamento']); ?></span>
            </div>
            <div class="rank-value">
              <?php if ($whats): ?>
                <a class="whats-link" href="https://wa.me/<?= htmlspecialchars($whats); ?>?text=<?= urlencode('Olá! Tudo bem? Sentimos sua falta por aqui. Quer agendar um novo horário?'); ?>" target="_blank" rel="noopener noreferrer">Chamar</a>
              <?php else: ?>
                —
              <?php endif; ?>
            </div>
          </div>
        <?php $i++; endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty-state">Nenhum cliente sumido encontrado.</div>
    <?php endif; ?>
  </section>

  <section class="panel">
    <div class="panel-header">
      <div>
        <h3>Status do período</h3>
        <p>Resumo operacional para entender qualidade da agenda.</p>
      </div>
    </div>

    <div class="rank-list">
      <div class="rank-item">
        <div class="rank-num">✓</div>
        <div class="rank-main">
          <strong>Confirmados</strong>
          <span>Atendimentos confirmados no período.</span>
        </div>
        <div class="rank-value"><?= (int)$totalConfirmados; ?></div>
      </div>

      <div class="rank-item">
        <div class="rank-num">!</div>
        <div class="rank-main">
          <strong>Pendentes</strong>
          <span>Atendimentos ainda pendentes.</span>
        </div>
        <div class="rank-value"><?= (int)$totalPendentes; ?></div>
      </div>

      <div class="rank-item">
        <div class="rank-num">×</div>
        <div class="rank-main">
          <strong>Cancelados</strong>
          <span>Taxa de cancelamento: <?= $taxaCancelamento; ?>%</span>
        </div>
        <div class="rank-value"><?= (int)$totalCancelados; ?></div>
      </div>
    </div>
  </section>

  <section class="panel">
    <div class="panel-header">
      <div>
        <h3>Dias mais fortes</h3>
        <p>Dias com mais volume no período filtrado.</p>
      </div>
    </div>

    <?php if (!empty($porDia)): ?>
      <div class="rank-list">
        <?php $i = 1; foreach (array_slice($porDia, 0, 8, true) as $dia => $qtd): ?>
          <div class="rank-item">
            <div class="rank-num"><?= $i; ?></div>
            <div class="rank-main">
              <strong><?= brDate($dia); ?></strong>
              <span><?= safePercent((int)$qtd, max(1, $totalAtendimentos)); ?>% dos atendimentos</span>
            </div>
            <div class="rank-value"><?= (int)$qtd; ?></div>
          </div>
        <?php $i++; endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty-state">Sem dados por dia neste período.</div>
    <?php endif; ?>
  </section>
</div>

<script>
  const periodoSelect = document.getElementById('periodo');
  const inicioInput = document.getElementById('inicio');
  const fimInput = document.getElementById('fim');

  function syncCustomDates() {
    const isCustom = periodoSelect.value === 'personalizado';
    inicioInput.disabled = !isCustom;
    fimInput.disabled = !isCustom;
    inicioInput.style.opacity = isCustom ? '1' : '.55';
    fimInput.style.opacity = isCustom ? '1' : '.55';
  }

  periodoSelect.addEventListener('change', syncCustomDates);
  syncCustomDates();
</script>

<?php admin_shell_end(); ?>
