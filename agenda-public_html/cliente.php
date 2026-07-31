<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/client-auth.php';
require_once __DIR__ . '/includes/phone.php';

date_default_timezone_set('America/Sao_Paulo');

const CLIENT_WHATSAPP = '5511947173110';

function c_money($value): string { return 'R$ ' . number_format((float)$value, 2, ',', '.'); }
function c_hour(string $time): string {
    $time = substr($time, 0, 5);
    [$h, $m] = explode(':', $time);
    return (int)$m === 0 ? ((int)$h) . 'h' : ((int)$h) . 'h' . $m;
}
function c_date(string $date): string { return date('d/m/Y', strtotime($date)); }
function c_norm(string $value): string {
    $value = mb_strtolower(trim($value), 'UTF-8');
    return str_replace(['á','à','ã','â','é','ê','í','ó','õ','ô','ú','ç'], ['a','a','a','a','e','e','i','o','o','o','u','c'], $value);
}
function c_category(array $service): string {
    $publico = trim((string)($service['publico'] ?? ''));
    if (in_array($publico, ['masculino', 'feminino', 'ambos'], true)) return $publico;
    return preg_match('/feminin|mecha|luzes|color|progressiva|botox|hidrat/', c_norm($service['nome'] ?? '')) ? 'feminino' : 'masculino';
}
function c_service_professionals(array $service): array {
    $raw = trim((string)($service['profissionais_ids'] ?? 'todos'));
    if ($raw === '' || $raw === 'todos') return [];
    return array_values(array_filter(array_map('intval', explode(',', $raw))));
}
function c_related_client_ids(mysqli $conn, array $cliente): array {
    $ids = [(int)$cliente['id']];
    $email = normalize_email($cliente['email'] ?? '');
    $clientePhone = limparTelefone($cliente['telefone'] ?? '');
    $clienteName = c_norm($cliente['nome'] ?? '');
    $res = $conn->query("SELECT id, nome, telefone, email FROM clientes");

    while ($res && $row = $res->fetch_assoc()) {
        $id = (int)$row['id'];
        $rowPhone = limparTelefone($row['telefone'] ?? '');
        $rowEmail = normalize_email($row['email'] ?? '');
        $rowName = c_norm($row['nome'] ?? '');
        $sameFullNameWithMissingPhone = $clienteName !== '' &&
            strlen($clienteName) >= 8 &&
            $rowName === $clienteName &&
            ($clientePhone === '' || $rowPhone === '');

        if (
            ($clientePhone !== '' && $rowPhone !== '' && $rowPhone === $clientePhone) ||
            ($email !== '' && $rowEmail !== '' && $rowEmail === $email) ||
            $sameFullNameWithMissingPhone
        ) {
            $ids[] = $id;
        }
    }

    return array_values(array_unique(array_filter($ids)));
}
function c_fetch_appointments(mysqli $conn, array $clienteIds, string $mode, int $limit): array {
    $operator = $mode === 'past' ? '<' : '>=';
    $order = $mode === 'past' ? 'DESC' : 'ASC';
    $now = date('Y-m-d H:i:s');
    $placeholders = implode(',', array_fill(0, count($clienteIds), '?'));
    $types = str_repeat('i', count($clienteIds)) . 'si';
    $params = array_merge($clienteIds, [$now, $limit]);
    $sql = "
        SELECT ag.id, ag.data, ag.hora, ag.status, ag.profissional_id, ag.servico_id,
               ag.is_recorrente, p.nome AS profissional, s.nome AS servico
        FROM agendamentos ag
        INNER JOIN profissionais p ON p.id = ag.profissional_id
        INNER JOIN servicos s ON s.id = ag.servico_id
        WHERE ag.cliente_id IN ({$placeholders})
          AND COALESCE(ag.status, '') <> 'cancelado'
          AND TIMESTAMP(ag.data, ag.hora) {$operator} ?
        ORDER BY ag.data {$order}, ag.hora {$order}
        LIMIT ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $items = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
    }
    return $items;
}

$cliente = exigir_cliente($conn);
$flash = $_GET['flash'] ?? '';
$msg = $_GET['msg'] ?? '';
$today = date('Y-m-d');
$maxDate = date('Y-m-d', strtotime('+90 days'));

$profissionais = [];
$resProf = $conn->query("SELECT id, nome FROM profissionais ORDER BY nome ASC");
while ($resProf && $row = $resProf->fetch_assoc()) {
    $profissionais[] = ['id' => (int)$row['id'], 'nome' => $row['nome']];
}

$selectPublico = $conn->query("SHOW COLUMNS FROM servicos LIKE 'publico'")->num_rows ? ', publico' : ", 'ambos' AS publico";
$selectProf = $conn->query("SHOW COLUMNS FROM servicos LIKE 'profissionais_ids'")->num_rows ? ', profissionais_ids' : ", 'todos' AS profissionais_ids";
$whereAtivo = $conn->query("SHOW COLUMNS FROM servicos LIKE 'ativo'")->num_rows ? ' WHERE ativo = 1' : '';
$servicos = [];
$resServ = $conn->query("SELECT id, nome, duracao, preco{$selectPublico}{$selectProf} FROM servicos{$whereAtivo} ORDER BY nome ASC");
while ($resServ && $row = $resServ->fetch_assoc()) {
    $servicos[] = [
        'id' => (int)$row['id'],
        'nome' => $row['nome'],
        'duracao' => (int)$row['duracao'],
        'preco_label' => c_money($row['preco']),
        'categoria' => c_category($row),
        'profissionais_ids' => c_service_professionals($row),
    ];
}

$clienteIdsAgenda = c_related_client_ids($conn, $cliente);
$proximos = c_fetch_appointments($conn, $clienteIdsAgenda, 'future', 8);
$historico = c_fetch_appointments($conn, $clienteIdsAgenda, 'past', 6);
$proximo = $proximos[0] ?? null;
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Minha agenda | André Puchetti</title>
  <style>
    :root{--bg:#070707;--panel:#121212;--gold:#d4af37;--gold2:#fff0ae;--text:#fff8e7;--soft:#d8d0bd;--muted:#9d9586;--green:#20c997;--red:#ff5f6d}
    *{box-sizing:border-box}body{margin:0;background:#070707;color:var(--text);font-family:Inter,Arial,sans-serif;overflow-x:hidden}.hidden{display:none!important}.app{min-height:100vh;display:grid;grid-template-columns:260px minmax(0,1fr)}
    .sidebar{position:sticky;top:0;height:100vh;padding:24px 18px;border-right:1px solid rgba(255,255,255,.08);background:linear-gradient(180deg,#111,#090909)}.side-brand{display:flex;align-items:center;gap:12px;margin-bottom:28px}.side-brand img{width:58px}.side-brand strong{color:#fff0bd}.side-brand span{display:block;color:var(--muted);font-size:13px}
    .nav-menu{display:grid;gap:8px}.nav-item{min-height:52px;border:1px solid transparent;background:transparent;color:var(--soft);border-radius:16px;padding:0 14px;display:flex;align-items:center;gap:12px;font-weight:900;text-align:left}.nav-item:hover{background:rgba(255,255,255,.05);border-color:rgba(255,255,255,.08)}.nav-item.active{background:rgba(212,175,55,.14);border-color:rgba(212,175,55,.30);color:#fff0bd}.nav-item svg{width:21px;height:21px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex:0 0 auto}
    .main{min-width:0;padding:24px 28px 54px;background:radial-gradient(circle at top,#191507,#070707 42%)}.mobile-brand{display:none}.topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:28px}.top-title h1{margin:0;font-size:clamp(2rem,6vw,4.2rem);line-height:.95;color:#fff0bd}
    .avatar-wrap{position:relative}.avatar-btn{width:44px!important;height:44px!important;min-width:44px;min-height:44px;max-width:44px;max-height:44px;aspect-ratio:1/1;border-radius:50%;border:1px solid rgba(212,175,55,.30);background:linear-gradient(135deg,rgba(212,175,55,.24),rgba(255,255,255,.06));color:#fff0bd;font-weight:900;font-size:16px;padding:0!important;text-transform:uppercase;flex:0 0 44px;line-height:1}.avatar-menu{position:absolute;right:0;top:54px;width:190px;border:1px solid rgba(255,255,255,.10);background:#111;border-radius:16px;padding:10px;box-shadow:0 18px 44px rgba(0,0,0,.36);display:none;z-index:10}.avatar-menu.open{display:block}.avatar-menu strong{display:block;padding:8px 10px;color:#fff0bd}.avatar-menu a{display:block;padding:10px;border-radius:12px;color:var(--text);text-decoration:none;font-weight:900}.avatar-menu a:hover{background:rgba(255,255,255,.06)}
    .flash{margin:0 0 18px;padding:14px;border-radius:16px;text-align:center;font-weight:800}.flash.sucesso{background:rgba(32,201,151,.12);color:#d9fff1}.flash.erro{background:rgba(255,95,109,.13);color:#ffd8dd}
    .panel,.modal-card{border:1px solid rgba(255,255,255,.08);background:rgba(18,18,18,.84);border-radius:22px;padding:22px}.panel.featured{max-width:760px;border-color:rgba(212,175,55,.26);background:linear-gradient(180deg,rgba(212,175,55,.12),rgba(18,18,18,.88))}.panel h2,.panel h3{margin:0 0 10px;color:#fff0bd}.muted,.meta{color:var(--soft);line-height:1.65}.status{display:inline-flex;margin-top:12px;padding:7px 10px;border-radius:999px;background:rgba(32,201,151,.12);color:#d9fff1;font-weight:900;font-size:12px;text-transform:uppercase;letter-spacing:.08em}
    button,.btn{min-height:48px;border-radius:16px;border:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.05);color:var(--text);font-weight:900;padding:0 16px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;text-align:center}button:disabled{cursor:not-allowed;opacity:.45}.btn-primary{background:linear-gradient(90deg,#f7e7af,#d4af37,#a87908);color:#17130b;border:0}.btn-primary:disabled{background:rgba(255,255,255,.10);color:rgba(255,248,231,.55);box-shadow:none}.danger{color:#ffd8dd}.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}
    .whatsapp-block{margin-top:14px;max-width:760px;min-height:64px;border-radius:20px;background:linear-gradient(135deg,#25d366,#128c4a);color:#062312;text-decoration:none;font-weight:950;display:flex;align-items:center;justify-content:center;gap:10px;box-shadow:0 18px 34px rgba(18,140,74,.20)}.whatsapp-block svg{width:22px;height:22px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
    .section{display:none}.section.active{display:block;animation:fade .24s ease}@keyframes fade{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}.section-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}.section-head h2{margin:0;color:#fff0bd}.list{display:grid;gap:12px}.appt{display:flex;align-items:center;justify-content:space-between;gap:14px;border-top:1px solid rgba(255,255,255,.07);padding-top:14px}.appt:first-child{border-top:0;padding-top:0}.appt strong{color:#fff0bd}
    .modal{position:fixed;inset:0;background:rgba(0,0,0,.72);display:none;align-items:center;justify-content:center;padding:18px;z-index:30}.modal.open{display:flex}.modal-card{width:min(820px,100%);max-height:92vh;overflow:auto;box-shadow:0 26px 70px rgba(0,0,0,.45)}.modal-top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.modal-title{margin:0;color:#fff0bd;font-size:clamp(1.6rem,7vw,3rem)}.close{width:44px;height:44px;padding:0}.notice{padding:12px 14px;border-radius:16px;background:rgba(212,175,55,.10);border:1px solid rgba(212,175,55,.18);color:#fff0bd;line-height:1.5;margin:14px 0}
    .flow-step{display:none;text-align:center}.flow-step.active{display:block}.choice-grid,.slot-grid,.calendar-grid{display:grid;gap:10px;margin-top:18px}.choice-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.service-grid{display:grid;gap:10px;margin-top:18px}.choice,.service,.slot,.day{width:100%;min-height:58px}.service{flex-direction:column;gap:4px}.selected{border-color:rgba(212,175,55,.75)!important;background:rgba(212,175,55,.20)!important;box-shadow:0 0 0 2px rgba(212,175,55,.16)}.slot.available,.day.available{background:rgba(32,201,151,.12);border-color:rgba(32,201,151,.30)}.slot.available.selected{background:linear-gradient(180deg,rgba(212,175,55,.28),rgba(212,175,55,.14))!important;color:#fff6cf}.slot.unavailable,.day.unavailable{background:rgba(255,95,109,.12);color:#ffd8dd;opacity:.55;cursor:not-allowed}.calendar-grid{grid-template-columns:repeat(7,minmax(0,1fr))}.day{min-height:52px;padding:0;font-size:14px}.day-head{color:var(--muted);font-size:12px;font-weight:900;text-align:center}.calendar-nav{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:16px}.calendar-title{font-weight:900;color:#fff0bd}.booking-summary{margin-top:16px;padding:14px;border-radius:16px;border:1px solid rgba(212,175,55,.22);background:rgba(212,175,55,.09);color:#fff0bd;line-height:1.55;text-align:left}.booking-summary strong{color:#fff8e7}.nav{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:18px}
    @media(min-width:760px){.slot-grid{grid-template-columns:repeat(4,minmax(0,1fr))}.actions .btn,.actions button{width:auto}}
    @media(max-width:860px){.app{display:block}.sidebar{position:fixed;left:12px;right:12px;bottom:12px;top:auto;height:auto;z-index:20;border:1px solid rgba(255,255,255,.10);border-radius:22px;padding:8px;background:rgba(12,12,12,.94);backdrop-filter:blur(14px)}.side-brand{display:none}.mobile-head{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:24px}.mobile-brand{display:flex;align-items:center;gap:12px;min-width:0}.mobile-brand img{width:58px;flex:0 0 auto}.mobile-brand strong{display:block;color:#fff0bd;line-height:1.1}.mobile-brand span{display:block;color:var(--muted);font-size:13px}.mobile-head .avatar-wrap{flex:0 0 40px}.mobile-head .avatar-btn{width:40px!important;height:40px!important;min-width:40px;min-height:40px;max-width:40px;max-height:40px;flex-basis:40px;font-size:15px}.mobile-head .avatar-menu{top:48px}.topbar{display:block;margin-bottom:24px}.topbar>.avatar-wrap{display:none}.nav-menu{grid-template-columns:repeat(3,1fr);gap:6px}.nav-item{min-height:58px;justify-content:center;flex-direction:column;gap:4px;padding:6px 4px;font-size:11px;line-height:1.1;text-align:center;white-space:normal}.nav-item .desktop-label{display:none}.nav-item .mobile-label{display:inline}.nav-item svg{width:19px;height:19px}.main{padding:18px 16px 100px}.appt{align-items:flex-start;flex-direction:column}.choice-grid{grid-template-columns:1fr}.slot-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.calendar-grid{gap:6px}.day{min-height:44px;border-radius:12px}}
    @media(min-width:861px){.mobile-head{display:none}.nav-item .mobile-label{display:none}}
  </style>
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="side-brand"><img src="assets/logo-salao.png" alt="André Puchetti"><div><strong>André Puchetti</strong><span>Área do cliente</span></div></div>
    <nav class="nav-menu" aria-label="Menu do cliente">
      <button type="button" class="nav-item active" data-section="inicio"><svg viewBox="0 0 24 24"><path d="M3 10.5 12 3l9 7.5"></path><path d="M5 10v10h14V10"></path></svg><span>Início</span></button>
      <button type="button" class="nav-item" data-section="agendamentos"><svg viewBox="0 0 24 24"><path d="M8 2v4"></path><path d="M16 2v4"></path><rect x="3" y="4" width="18" height="18" rx="3"></rect><path d="M3 10h18"></path></svg><span class="desktop-label">Meus agendamentos</span><span class="mobile-label">Agenda</span></button>
      <button type="button" class="nav-item" data-action="booking"><svg viewBox="0 0 24 24"><path d="M8 2v4"></path><path d="M16 2v4"></path><rect x="3" y="4" width="18" height="18" rx="3"></rect><path d="M3 10h18"></path><path d="M12 14v5"></path><path d="M9.5 16.5h5"></path></svg><span class="desktop-label">Novo procedimento</span><span class="mobile-label">Criar</span></button>
    </nav>
  </aside>
  <main class="main">
  <div class="mobile-head">
    <div class="mobile-brand"><img src="assets/logo-salao.png" alt="André Puchetti"><div><strong>André Puchetti</strong><span>Área do cliente</span></div></div>
    <div class="avatar-wrap"><button type="button" class="avatar-btn js-avatar-btn"><?= htmlspecialchars(mb_substr(trim($cliente['nome']) ?: 'C', 0, 1, 'UTF-8')); ?></button><div class="avatar-menu"><strong>Olá, <?= htmlspecialchars(explode(' ', trim($cliente['nome']))[0] ?: $cliente['nome']); ?></strong><a href="cliente-logout.php">Sair</a></div></div>
  </div>
  <div class="topbar">
    <div class="top-title"><h1>Olá, <?= htmlspecialchars(explode(' ', trim($cliente['nome']))[0] ?: $cliente['nome']); ?></h1></div>
    <div class="avatar-wrap"><button type="button" class="avatar-btn js-avatar-btn"><?= htmlspecialchars(mb_substr(trim($cliente['nome']) ?: 'C', 0, 1, 'UTF-8')); ?></button><div class="avatar-menu"><strong>Olá, <?= htmlspecialchars(explode(' ', trim($cliente['nome']))[0] ?: $cliente['nome']); ?></strong><a href="cliente-logout.php">Sair</a></div></div>
  </div>
  <?php if ($msg): ?><div class="flash <?= htmlspecialchars($flash); ?>"><?= htmlspecialchars($msg); ?></div><?php endif; ?>

  <section class="section active" id="inicio">
      <article class="panel featured">
        <h2>Próximo agendamento</h2>
        <?php if ($proximo): ?>
          <div class="meta"><strong><?= htmlspecialchars($proximo['servico']); ?></strong><br><?= htmlspecialchars($proximo['profissional']); ?><br><?= c_date($proximo['data']); ?> às <?= c_hour($proximo['hora']); ?></div>
          <span class="status"><?= htmlspecialchars($proximo['status']); ?></span>
          <div class="actions">
            <button type="button" class="btn-primary" onclick='openReschedule(<?= htmlspecialchars(json_encode($proximo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>)'>Remarcar</button>
            <form method="post" action="cliente-action.php" onsubmit="return confirm('Cancelar este agendamento?');">
              <?= csrf_input(); ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$proximo['id']; ?>">
              <button type="submit" class="danger">Cancelar</button>
            </form>
          </div>
        <?php else: ?>
          <div class="muted">Você ainda não tem horários futuros. Crie um novo agendamento quando quiser.</div>
          <div class="actions"><button type="button" class="btn-primary" onclick="openBooking()">Novo procedimento</button></div>
        <?php endif; ?>
      </article>
      <a class="whatsapp-block" href="https://wa.me/<?= CLIENT_WHATSAPP; ?>" target="_blank" rel="noopener">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5A8.48 8.48 0 0 1 21 11v.5Z"></path></svg>
        WhatsApp
      </a>
  </section>

  <section class="section" id="agendamentos">
    <div class="section-head"><h2>Meus agendamentos</h2><button type="button" class="btn-primary" onclick="openBooking()">Novo procedimento</button></div>
    <div class="panel"><h3>Próximos horários</h3><div class="list">
      <?php if (!$proximos): ?><div class="muted">Nenhum agendamento futuro.</div><?php endif; ?>
      <?php foreach ($proximos as $ag): ?>
        <div class="appt"><div><strong><?= htmlspecialchars($ag['servico']); ?></strong><div class="meta"><?= htmlspecialchars($ag['profissional']); ?> · <?= c_date($ag['data']); ?> às <?= c_hour($ag['hora']); ?><?= (int)$ag['is_recorrente'] === 1 ? ' · recorrente' : ''; ?></div></div><div class="actions"><button type="button" onclick='openReschedule(<?= htmlspecialchars(json_encode($ag, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>)'>Remarcar</button><form method="post" action="cliente-action.php" onsubmit="return confirm('Cancelar este agendamento?');"><?= csrf_input(); ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$ag['id']; ?>"><button type="submit" class="danger">Cancelar</button></form></div></div>
      <?php endforeach; ?>
    </div><p class="muted">Mostrando somente os próximos 8 horários para manter a tela leve.</p></div>
    <div class="panel" style="margin-top:14px"><h3>Histórico recente</h3><div class="list">
      <?php if (!$historico): ?><div class="muted">Nenhum histórico recente.</div><?php endif; ?>
      <?php foreach ($historico as $ag): ?><div class="appt"><div><strong><?= htmlspecialchars($ag['servico']); ?></strong><div class="meta"><?= htmlspecialchars($ag['profissional']); ?> · <?= c_date($ag['data']); ?> às <?= c_hour($ag['hora']); ?></div></div></div><?php endforeach; ?>
    </div></div>
  </section>
  </main>
</div>

<div class="modal" id="bookingModal">
  <div class="modal-card">
    <div class="modal-top"><div><h2 class="modal-title" id="modalTitle">Novo procedimento</h2><div class="muted" id="modalSubtitle">Escolha o procedimento, a data e o melhor horário.</div></div><button type="button" class="close" onclick="closeModal()">×</button></div>
    <div class="notice hidden" id="recurrenceNotice">Esta alteração será aplicada somente a este agendamento. Os demais agendamentos recorrentes não serão modificados.</div>
    <div class="panel hidden" id="currentBooking" style="margin:14px 0"></div>
    <form method="post" action="cliente-action.php" id="bookingForm">
      <?= csrf_input(); ?>
      <input type="hidden" name="action" id="formAction" value="book">
      <input type="hidden" name="id" id="formBookingId" value="0">
      <input type="hidden" name="profissional_id" id="profissionalId">
      <input type="hidden" name="servico_id" id="servicoId">
      <input type="hidden" name="data" id="dataValue">
      <input type="hidden" name="hora" id="horaValue">
      <div class="flow-step active" data-flow="0"><h3>Qual tipo de atendimento?</h3><div class="choice-grid"><button type="button" class="choice" data-category="masculino">Serviços masculinos</button><button type="button" class="choice" data-category="feminino">Serviços femininos</button></div></div>
      <div class="flow-step" data-flow="1"><h3>Escolha o profissional</h3><div class="choice-grid" id="professionals"></div><div class="nav"><button type="button" onclick="showFlow(0)">Voltar</button></div></div>
      <div class="flow-step" data-flow="2"><h3>Escolha o serviço</h3><div class="service-grid" id="services"></div><div class="nav"><button type="button" onclick="showFlow(1)">Voltar</button></div></div>
      <div class="flow-step" data-flow="3"><h3>Para quando?</h3><div class="calendar-nav"><button type="button" onclick="changeMonth(-1)">‹</button><div class="calendar-title" id="monthTitle"></div><button type="button" onclick="changeMonth(1)">›</button></div><div class="calendar-grid" id="calendar"></div><div class="slot-grid" id="slots"></div><div class="booking-summary hidden" id="bookingSummary"></div><div class="nav"><button type="button" id="calendarBackBtn" onclick="calendarBack()">Voltar</button><button type="submit" class="btn-primary" id="confirmBookingBtn" disabled>Confirmar</button></div></div>
    </form>
  </div>
</div>

<script>
const professionals=<?= json_encode($profissionais, JSON_UNESCAPED_UNICODE); ?>;
const services=<?= json_encode($servicos, JSON_UNESCAPED_UNICODE); ?>;
const state={category:'',professionalId:'',serviceId:'',date:'',time:'',month:new Date(),mode:'book',id:0,current:null};
const modal=document.getElementById('bookingModal'), flowSteps=[...document.querySelectorAll('.flow-step')], profBox=document.getElementById('professionals'), servBox=document.getElementById('services'), slotsBox=document.getElementById('slots'), calBox=document.getElementById('calendar'), bookingSummary=document.getElementById('bookingSummary'), confirmBookingBtn=document.getElementById('confirmBookingBtn');
function showSection(id){document.querySelectorAll('.section').forEach(s=>s.classList.toggle('active',s.id===id));document.querySelectorAll('.nav-item[data-section]').forEach(b=>b.classList.toggle('active',b.dataset.section===id));window.scrollTo({top:0,behavior:'smooth'})}
document.querySelectorAll('.nav-item[data-section]').forEach(b=>b.onclick=()=>showSection(b.dataset.section));
document.querySelectorAll('.nav-item[data-action="booking"]').forEach(b=>b.onclick=()=>openBooking());
document.querySelectorAll('.js-avatar-btn').forEach(btn=>btn.addEventListener('click',()=>{document.querySelectorAll('.avatar-menu').forEach(menu=>{if(menu!==btn.nextElementSibling)menu.classList.remove('open')});btn.nextElementSibling.classList.toggle('open')}));
document.addEventListener('click',e=>{if(!e.target.closest('.avatar-wrap'))document.querySelectorAll('.avatar-menu').forEach(menu=>menu.classList.remove('open'))});
function resetFlow(){state.category='';state.professionalId='';state.serviceId='';state.date='';state.time='';state.current=null;document.getElementById('profissionalId').value='';document.getElementById('servicoId').value='';document.getElementById('dataValue').value='';document.getElementById('horaValue').value='';document.getElementById('currentBooking').classList.add('hidden');document.getElementById('currentBooking').innerHTML='';document.getElementById('recurrenceNotice').classList.add('hidden');document.querySelectorAll('.selected').forEach(x=>x.classList.remove('selected'));slotsBox.innerHTML='';calBox.innerHTML='';bookingSummary.classList.add('hidden');bookingSummary.innerHTML='';confirmBookingBtn.disabled=true;showFlow(0)}
function showFlow(n){flowSteps.forEach(s=>s.classList.toggle('active',s.dataset.flow==n))}
function openBooking(){resetFlow();state.mode='book';state.id=0;document.getElementById('formAction').value='book';document.getElementById('formBookingId').value='0';document.getElementById('modalTitle').textContent='Novo procedimento';document.getElementById('modalSubtitle').textContent='Escolha o procedimento, a data e o melhor horário.';modal.classList.add('open')}
function openReschedule(ag){resetFlow();state.mode='reschedule';state.id=ag.id;state.current=ag;state.professionalId=String(ag.profissional_id);state.serviceId=String(ag.servico_id);state.month=parseDate(ag.data);document.getElementById('profissionalId').value=state.professionalId;document.getElementById('servicoId').value=state.serviceId;document.getElementById('formAction').value='reschedule';document.getElementById('formBookingId').value=ag.id;document.getElementById('modalTitle').textContent='Remarcar agendamento';document.getElementById('modalSubtitle').textContent='Para quando você gostaria de remarcar este agendamento?';document.getElementById('currentBooking').innerHTML=`<h3>Agendamento atual</h3><div class="meta">${ag.servico}<br>${ag.profissional}<br>${br(ag.data)} às ${hour(ag.hora)}</div>`;document.getElementById('currentBooking').classList.remove('hidden');document.getElementById('recurrenceNotice').classList.toggle('hidden',String(ag.is_recorrente)!=='1');renderCalendar();showFlow(3);modal.classList.add('open')}
function closeModal(){modal.classList.remove('open')} modal.addEventListener('click',e=>{if(e.target===modal)closeModal()});
function calendarBack(){state.mode==='reschedule'?closeModal():showFlow(2)}
document.querySelectorAll('[data-category]').forEach(b=>b.onclick=()=>{state.category=b.dataset.category;document.querySelectorAll('[data-category]').forEach(x=>x.classList.remove('selected'));b.classList.add('selected');renderProfessionals();showFlow(1)});
function renderProfessionals(){profBox.innerHTML=professionals.map(p=>`<button type="button" class="choice" data-prof="${p.id}">${p.nome}</button>`).join('');profBox.querySelectorAll('[data-prof]').forEach(b=>b.onclick=()=>{state.professionalId=b.dataset.prof;document.getElementById('profissionalId').value=state.professionalId;renderServices();showFlow(2)})}
function renderServices(){const list=services.filter(s=>s.categoria===state.category||s.categoria==='ambos').filter(s=>!s.profissionais_ids.length||s.profissionais_ids.map(String).includes(String(state.professionalId)));servBox.innerHTML=list.map(s=>`<button type="button" class="service" data-serv="${s.id}"><strong>${s.nome}</strong><span>${s.preco_label}</span></button>`).join('')||'<p>Nenhum serviço encontrado.</p>';servBox.querySelectorAll('[data-serv]').forEach(b=>b.onclick=()=>{state.serviceId=b.dataset.serv;document.getElementById('servicoId').value=state.serviceId;state.month=new Date();renderCalendar();showFlow(3)})}
function parseDate(v){const [y,m,d]=v.split('-').map(Number);return new Date(y,m-1,d)} function fmtDate(d){return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`} function br(v){const [y,m,d]=v.split('-');return `${d}/${m}/${y}`} function hour(t){const [h,m]=t.slice(0,5).split(':');return Number(m)?`${Number(h)}h${m}`:`${Number(h)}h`}
async function hasSlots(date){const q=new URLSearchParams({ajax:'slots',profissional_id:state.professionalId,servico_id:state.serviceId,data:date});const r=await fetch(`index.php?${q}`);const j=await r.json();return !!(j.ok && (j.slots||[]).some(s=>s.available))}
async function renderCalendar(){state.date='';state.time='';slotsBox.innerHTML='';bookingSummary.classList.add('hidden');bookingSummary.innerHTML='';confirmBookingBtn.disabled=true;document.getElementById('dataValue').value='';document.getElementById('horaValue').value='';const y=state.month.getFullYear(),m=state.month.getMonth();document.getElementById('monthTitle').textContent=state.month.toLocaleDateString('pt-BR',{month:'long',year:'numeric'});calBox.innerHTML=['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'].map(d=>`<div class="day-head">${d}</div>`).join('');const first=new Date(y,m,1),last=new Date(y,m+1,0),today=fmtDate(new Date()),max='<?= $maxDate; ?>';for(let i=0;i<first.getDay();i++)calBox.insertAdjacentHTML('beforeend','<div></div>');for(let d=1;d<=last.getDate();d++){const date=fmtDate(new Date(y,m,d));const disabled=date<today||date>max||new Date(y,m,d).getDay()===0;const btn=document.createElement('button');btn.type='button';btn.className='day unavailable';btn.textContent=d;btn.disabled=true;calBox.appendChild(btn);if(!disabled){hasSlots(date).then(ok=>{btn.className=`day ${ok?'available':'unavailable'}`;btn.disabled=!ok;if(ok)btn.onclick=()=>selectDate(date,btn)})}}}
function changeMonth(dir){state.month.setMonth(state.month.getMonth()+dir);renderCalendar()}
async function selectDate(date,btn){state.date=date;state.time='';bookingSummary.classList.add('hidden');bookingSummary.innerHTML='';confirmBookingBtn.disabled=true;document.getElementById('dataValue').value=date;document.getElementById('horaValue').value='';calBox.querySelectorAll('.day').forEach(x=>x.classList.remove('selected'));btn.classList.add('selected');slotsBox.innerHTML='<p>Carregando horários...</p>';const q=new URLSearchParams({ajax:'slots',profissional_id:state.professionalId,servico_id:state.serviceId,data:date});const r=await fetch(`index.php?${q}`);const j=await r.json();slotsBox.innerHTML=(j.slots||[]).map(s=>`<button type="button" class="slot ${s.available?'available':'unavailable'}" ${s.available?'':'disabled'} data-time="${s.time}" data-label="${s.label}">${s.label}</button>`).join('')||'<p>Nenhum horário livre.</p>';slotsBox.querySelectorAll('[data-time]').forEach(b=>b.onclick=()=>selectTime(b))}
function currentService(){return services.find(s=>String(s.id)===String(state.serviceId))||{}}
function currentProfessional(){return professionals.find(p=>String(p.id)===String(state.professionalId))||{}}
function selectTime(btn){state.time=btn.dataset.time;document.getElementById('horaValue').value=state.time;slotsBox.querySelectorAll('.slot').forEach(x=>x.classList.remove('selected'));btn.classList.add('selected');confirmBookingBtn.disabled=false;const s=currentService(),p=currentProfessional();bookingSummary.innerHTML=`<strong>${state.mode==='reschedule'?'Resumo da remarcação':'Resumo do novo procedimento'}</strong><br>${s.nome||'Serviço selecionado'} com ${p.nome||'profissional selecionado'}<br>${br(state.date)} às ${btn.dataset.label||hour(state.time)}`;bookingSummary.classList.remove('hidden')}
document.getElementById('bookingForm').onsubmit=e=>{if(!state.professionalId||!state.serviceId||!state.date||!state.time){e.preventDefault();alert('Escolha uma data e um horário para continuar.');return}const s=currentService(),p=currentProfessional(),msg=state.mode==='reschedule'?`Confirmar remarcação para ${br(state.date)} às ${hour(state.time)}?\\n\\nServiço: ${s.nome||''}\\nProfissional: ${p.nome||''}`:`Confirmar novo procedimento para ${br(state.date)} às ${hour(state.time)}?\\n\\nServiço: ${s.nome||''}\\nProfissional: ${p.nome||''}`;if(!confirm(msg))e.preventDefault()};
</script>
</body>
</html>
