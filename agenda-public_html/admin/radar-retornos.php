<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-shell.php';
require_once __DIR__ . '/../includes/radar.php';

date_default_timezone_set('America/Sao_Paulo');

$profissionalFiltro = usuarioEhAdmin() ? ($_GET['profissional_id'] ?? 'todos') : (string)(usuarioProfissionalId() ?? 0);
$stateFilter = $_GET['filtro'] ?? 'prioridades';
$typeFilter = $_GET['tipo'] ?? '';
$q = trim($_GET['q'] ?? '');
$flash = $_GET['flash'] ?? '';
$msg = $_GET['msg'] ?? '';

$profissionais = [];
$resProf = $conn->query("SELECT id, nome FROM profissionais ORDER BY nome ASC");
while ($resProf && $row = $resProf->fetch_assoc()) {
    $profissionais[] = $row;
}

$profId = $profissionalFiltro !== 'todos' ? (int)$profissionalFiltro : null;
$allItemsForSummary = radar_fetch_items($conn, ['profissional_id' => $profId, 'limit' => 250]);
$summary = radar_summary($allItemsForSummary);
$items = radar_fetch_items($conn, [
    'profissional_id' => $profId,
    'state' => $stateFilter,
    'type' => $typeFilter,
    'q' => $q,
    'limit' => 80,
]);

$summaryCards = [
    'atrasado' => ['label' => 'Atrasados', 'icon' => '!', 'class' => 'late', 'filter' => 'atrasados'],
    'semana' => ['label' => 'Esta semana', 'icon' => '7', 'class' => 'week', 'filter' => 'semana'],
    'risco' => ['label' => 'Atenção especial', 'icon' => '!', 'class' => 'risk', 'filter' => 'risco'],
];

function radar_chip_url(array $params): string
{
    $base = array_merge($_GET, $params);
    foreach ($base as $key => $value) {
        if ($value === '' || $value === null) unset($base[$key]);
    }
    return '?' . http_build_query($base);
}

function radar_fetch_upcoming_agenda(mysqli $conn, string $date, ?int $profissionalId): array
{
    $sql = "
        SELECT
            ag.id,
            ag.hora,
            c.nome AS cliente_nome,
            p.nome AS profissional_nome,
            s.nome AS servico_nome
        FROM agendamentos ag
        INNER JOIN clientes c ON c.id = ag.cliente_id
        INNER JOIN profissionais p ON p.id = ag.profissional_id
        INNER JOIN servicos s ON s.id = ag.servico_id
        WHERE ag.data = ?
          AND ag.status IN ('confirmado', 'pendente')
    ";
    $params = [$date];
    $types = 's';

    if ($profissionalId !== null) {
        $sql .= " AND ag.profissional_id = ?";
        $params[] = $profissionalId;
        $types .= 'i';
    }

    $sql .= " ORDER BY ag.hora ASC LIMIT 12";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    $result = $stmt->get_result();
    $rows = [];
    while ($result && $row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

function radar_agenda_url(string $date, ?int $profissionalId): string
{
    $params = ['data' => $date];
    if ($profissionalId !== null) $params['profissional_id'] = $profissionalId;
    return 'agenda-visual.php?' . http_build_query($params);
}

$todayDate = date('Y-m-d');
$tomorrowDate = date('Y-m-d', strtotime('+1 day'));
$focusBuckets = [
    'hoje' => radar_fetch_upcoming_agenda($conn, $todayDate, $profId),
    'amanha' => radar_fetch_upcoming_agenda($conn, $tomorrowDate, $profId),
];

admin_shell_start('Radar de Retornos | André Puchetti', 'radar');
?>
<style>
  .radar-hero{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:18px}.radar-hero h1{margin:0;color:#fff0bd;font-size:clamp(2rem,7vw,4rem);line-height:.95}.radar-hero p{margin:8px 0 0;color:rgba(247,243,234,.72)}
  .radar-toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px}.radar-toolbar input,.radar-toolbar select{min-height:44px;border:1px solid rgba(255,255,255,.1);background:#111;color:#f7f3ea;border-radius:12px;padding:0 12px}.radar-toolbar button{min-height:44px;border:0;border-radius:12px;background:#d4af37;color:#17130b;font-weight:900;padding:0 16px}
  .radar-flash{padding:12px 14px;border-radius:16px;margin-bottom:14px;font-weight:900}.radar-flash.sucesso{background:rgba(32,201,151,.12);color:#d9fff1}.radar-flash.erro{background:rgba(255,95,109,.13);color:#ffd8dd}
  .radar-focus-grid{display:grid;gap:12px;margin-bottom:12px}.focus-card{min-height:158px;border:1px solid rgba(212,175,55,.22);background:linear-gradient(145deg,rgba(212,175,55,.13),rgba(255,255,255,.035));border-radius:18px;padding:18px;text-decoration:none;color:#f7f3ea;display:flex;flex-direction:column;justify-content:space-between;overflow:hidden}.focus-card.tomorrow{background:linear-gradient(145deg,rgba(245,166,35,.12),rgba(255,255,255,.035))}.focus-kicker{font-size:.75rem;font-weight:950;letter-spacing:.14em;text-transform:uppercase;color:#fff0bd}.focus-number{font-size:clamp(2.6rem,8vw,4.2rem);line-height:.9;color:#fff;font-weight:950;margin:8px 0}.focus-empty{font-size:clamp(1.45rem,5vw,2.1rem);line-height:1.05;color:#fff;font-weight:950;margin:10px 0}.focus-label{color:rgba(247,243,234,.72);font-weight:800}.focus-names{display:flex;gap:6px;flex-wrap:wrap;margin-top:12px}.focus-names span{border:1px solid rgba(255,255,255,.1);background:rgba(0,0,0,.22);border-radius:9px;padding:6px 8px;font-size:.8rem;font-weight:900;color:rgba(247,243,234,.86)}.focus-time{color:#fff0bd;margin-right:4px}
  .radar-summary{display:flex;gap:10px;overflow-x:auto;padding:2px 0 12px;margin-bottom:10px;scroll-snap-type:x proximity}.summary-card{scroll-snap-align:start;min-width:148px;border-radius:14px;padding:13px;border:1px solid rgba(255,255,255,.08);text-decoration:none;color:#f7f3ea;background:rgba(255,255,255,.04)}.summary-card strong{display:block;font-size:1.45rem;color:#fff}.summary-card span{display:flex;align-items:center;gap:7px;color:rgba(247,243,234,.78);font-weight:900;font-size:.9rem}.summary-card .dot{width:22px;height:22px;border-radius:8px;display:grid;place-items:center;font-size:.75rem}.summary-card.late{background:rgba(255,95,109,.10);border-color:rgba(255,95,109,.22)}.summary-card.late .dot{background:#ff5f6d;color:#1b080b}.summary-card.week{background:rgba(50,145,255,.10);border-color:rgba(50,145,255,.22)}.summary-card.week .dot{background:#3291ff}.summary-card.risk{background:rgba(193,43,105,.13);border-color:rgba(193,43,105,.26)}.summary-card.risk .dot{background:#c12b69}
  .filter-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:2px 0 14px;padding:10px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.035);border-radius:16px}.filter-tabs{display:flex;gap:4px;overflow-x:auto}.filter-tab{white-space:nowrap;text-decoration:none;color:rgba(247,243,234,.72);border:1px solid transparent;background:transparent;border-radius:9px;padding:10px 12px;font-weight:900}.filter-tab:hover{background:rgba(255,255,255,.055);color:#f7f3ea}.filter-tab.active{background:rgba(212,175,55,.13);border-color:rgba(212,175,55,.28);color:#fff0bd}.filter-type select{min-height:40px;border:1px solid rgba(255,255,255,.1);background:#101010;color:#f7f3ea;border-radius:10px;padding:0 10px;font-weight:800}
  .radar-list{display:grid;gap:10px}.radar-card{border:1px solid rgba(255,255,255,.08);background:rgba(18,18,18,.82);border-radius:18px;padding:14px}.radar-top{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.radar-name{font-size:1.02rem;font-weight:950;color:#fff0bd}.type-badge{display:inline-flex;border-radius:999px;padding:5px 9px;font-size:.76rem;font-weight:950;border:1px solid rgba(255,255,255,.1);color:#f7f3ea}.type-badge.recorrente{background:rgba(32,201,151,.12)}.type-badge.avulso{background:rgba(245,166,35,.10)}.type-badge.novo{background:rgba(116,90,255,.13)}.type-badge.em_formacao{background:rgba(50,145,255,.12)}
  .state-line{margin-top:10px;font-weight:950;letter-spacing:.02em}.state-line.atrasado{color:#ffb8bf}.state-line.risco{color:#ff9ec3}.state-line.hoje{color:#d4ccff}.state-line.amanha{color:#ffd89a}.state-line.semana{color:#b9dcff}.state-line.contatado,.state-line.aguardando_resposta{color:#cbd5e1}.radar-meta{margin-top:6px;color:rgba(247,243,234,.7);line-height:1.45;font-size:.92rem}.radar-actions{display:flex;gap:8px;margin-top:12px;align-items:center}.whats-btn{flex:1;min-height:46px;border:0;border-radius:14px;background:linear-gradient(135deg,#25d366,#128c4a);color:#062312;font-weight:950}.whats-btn:disabled{background:rgba(255,255,255,.07);color:rgba(247,243,234,.45)}.menu-wrap{position:relative}.menu-btn{width:46px;height:46px;border-radius:14px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.05);color:#f7f3ea;font-size:1.25rem}.menu-panel{display:none;position:absolute;right:0;top:52px;width:220px;background:#101010;border:1px solid rgba(255,255,255,.1);border-radius:16px;padding:8px;z-index:5;box-shadow:0 20px 50px rgba(0,0,0,.35)}.menu-panel.open{display:grid}.menu-panel button,.menu-panel a{min-height:40px;border:0;background:transparent;color:#f7f3ea;text-align:left;border-radius:10px;padding:0 10px;text-decoration:none;font-weight:800}.menu-panel button:hover,.menu-panel a:hover{background:rgba(255,255,255,.06)}
  .empty-radar{padding:26px;border:1px solid rgba(255,255,255,.08);border-radius:18px;background:rgba(255,255,255,.04);text-align:center;color:rgba(247,243,234,.75)}.empty-radar strong{display:block;color:#fff0bd;font-size:1.2rem;margin-bottom:6px}
  .wa-preview{position:fixed;inset:0;background:rgba(0,0,0,.72);display:none;align-items:flex-end;justify-content:center;z-index:50}.wa-preview.open{display:flex}.wa-box{width:100%;max-width:540px;background:#101010;border:1px solid rgba(255,255,255,.1);border-radius:22px 22px 0 0;padding:18px}.wa-box h3{margin:0 0 12px;color:#fff0bd}.wa-box textarea{width:100%;min-height:170px;border:1px solid rgba(255,255,255,.1);background:#080808;color:#f7f3ea;border-radius:14px;padding:12px;font-size:15px;line-height:1.45}.wa-actions{display:grid;gap:8px;margin-top:12px}.wa-actions a,.wa-actions button{min-height:48px;border:0;border-radius:14px;font-weight:950;text-decoration:none;display:grid;place-items:center}.wa-actions a{background:#25d366;color:#062312}.wa-actions button{background:rgba(255,255,255,.06);color:#f7f3ea}
  @media(min-width:780px){.radar-focus-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.radar-list{grid-template-columns:repeat(2,minmax(0,1fr))}.wa-preview{align-items:center}.wa-box{border-radius:22px}.radar-toolbar input{min-width:260px}}
  @media(max-width:640px){.filter-bar{display:block}.filter-tabs{padding-bottom:8px}.filter-type select{width:100%}.radar-toolbar input,.radar-toolbar select,.radar-toolbar button{width:100%}}
</style>

<div class="radar-hero">
  <div>
    <h1>Radar de Retornos</h1>
    <p>Clientes que precisam da sua atenção</p>
  </div>
</div>

<?php if ($msg): ?><div class="radar-flash <?= htmlspecialchars($flash); ?>"><?= htmlspecialchars($msg); ?></div><?php endif; ?>

<form class="radar-toolbar" method="get">
  <input type="search" name="q" value="<?= htmlspecialchars($q); ?>" placeholder="Buscar cliente, telefone ou serviço">
  <input type="hidden" name="filtro" value="<?= htmlspecialchars($stateFilter); ?>">
  <input type="hidden" name="tipo" value="<?= htmlspecialchars($typeFilter); ?>">
  <?php if (usuarioEhAdmin()): ?>
    <select name="profissional_id">
      <option value="todos">Todos os profissionais</option>
      <?php foreach ($profissionais as $prof): ?>
        <option value="<?= (int)$prof['id']; ?>" <?= (string)$profissionalFiltro === (string)$prof['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($prof['nome']); ?></option>
      <?php endforeach; ?>
    </select>
  <?php endif; ?>
  <button type="submit">Filtrar</button>
</form>

<div class="radar-focus-grid">
  <?php foreach (['hoje' => ['label' => 'Agenda de hoje', 'date' => $todayDate], 'amanha' => ['label' => 'Agenda de amanhã', 'date' => $tomorrowDate]] as $key => $info): ?>
    <?php $bucket = $focusBuckets[$key]; ?>
    <a class="focus-card <?= $key === 'amanha' ? 'tomorrow' : 'today'; ?>" href="<?= htmlspecialchars(radar_agenda_url($info['date'], $profId)); ?>">
      <div>
        <div class="focus-kicker"><?= htmlspecialchars($info['label']); ?></div>
        <?php if ($bucket): ?>
          <div class="focus-number"><?= count($bucket); ?></div>
          <div class="focus-label"><?= count($bucket) === 1 ? 'agendamento marcado' : 'agendamentos marcados'; ?></div>
        <?php else: ?>
          <div class="focus-empty">Agenda livre</div>
          <div class="focus-label">Nenhum horário marcado para <?= $key === 'hoje' ? 'hoje' : 'amanhã'; ?></div>
        <?php endif; ?>
      </div>
      <?php if ($bucket): ?>
        <div class="focus-names">
          <?php foreach (array_slice($bucket, 0, 4) as $person): ?>
            <span><b class="focus-time"><?= htmlspecialchars(substr((string)$person['hora'], 0, 5)); ?></b><?= htmlspecialchars(radar_first_name($person['cliente_nome'])); ?></span>
          <?php endforeach; ?>
          <?php if (count($bucket) > 4): ?><span>+<?= count($bucket) - 4; ?></span><?php endif; ?>
        </div>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>

<div class="radar-summary">
  <?php foreach ($summaryCards as $key => $card): ?>
    <?php if (($summary[$key] ?? 0) <= 0) continue; ?>
    <a class="summary-card <?= $card['class']; ?>" href="<?= htmlspecialchars(radar_chip_url(['filtro' => $card['filter']])); ?>">
      <span><i class="dot"><?= htmlspecialchars($card['icon']); ?></i><?= htmlspecialchars($card['label']); ?></span>
      <strong><?= (int)$summary[$key]; ?></strong>
      <small><?= (int)$summary[$key] === 1 ? 'cliente' : 'clientes'; ?></small>
    </a>
  <?php endforeach; ?>
</div>

<div class="filter-bar">
  <div class="filter-tabs">
    <?php foreach (['prioridades'=>'Prioridades','hoje'=>'Hoje','amanha'=>'Amanhã','semana'=>'Esta semana','atrasados'=>'Atrasados','risco'=>'Atenção especial'] as $filter => $label): ?>
      <a class="filter-tab <?= $stateFilter === $filter ? 'active' : ''; ?>" href="<?= htmlspecialchars(radar_chip_url(['filtro' => $filter])); ?>"><?= htmlspecialchars($label); ?></a>
    <?php endforeach; ?>
  </div>
  <form class="filter-type" method="get">
    <input type="hidden" name="q" value="<?= htmlspecialchars($q); ?>">
    <input type="hidden" name="filtro" value="<?= htmlspecialchars($stateFilter); ?>">
    <?php if (usuarioEhAdmin()): ?><input type="hidden" name="profissional_id" value="<?= htmlspecialchars((string)$profissionalFiltro); ?>"><?php endif; ?>
    <select name="tipo" onchange="this.form.submit()">
      <option value="">Todos os tipos</option>
      <?php foreach (['recorrente'=>'Recorrentes','avulso'=>'Avulsos','novo'=>'Novos','em_formacao'=>'Em formação'] as $type => $label): ?>
        <option value="<?= htmlspecialchars($type); ?>" <?= $typeFilter === $type ? 'selected' : ''; ?>><?= htmlspecialchars($label); ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<?php if (!$items): ?>
  <div class="empty-radar"><strong>Tudo em dia por aqui!</strong>Nenhum cliente precisa de atenção agora. Os próximos retornos aparecerão automaticamente.</div>
<?php else: ?>
  <div class="radar-list">
    <?php foreach ($items as $item): ?>
      <article class="radar-card">
        <div class="radar-top">
          <div class="radar-name"><?= htmlspecialchars($item['cliente_nome']); ?></div>
          <span class="type-badge <?= htmlspecialchars($item['classificacao']); ?>"><?= htmlspecialchars($item['tipo_label']); ?></span>
        </div>
        <div class="state-line <?= htmlspecialchars($item['estado_key']); ?>"><?= htmlspecialchars($item['estado_label']); ?></div>
        <div class="radar-meta">
          <?= htmlspecialchars($item['servico_nome']); ?> · <?= htmlspecialchars($item['classificacao'] === 'recorrente' ? 'Retorna a cada ' . (int)$item['frequencia_dias'] . ' dias' : 'Sugestão de ' . (int)$item['frequencia_dias'] . ' dias'); ?><br>
          <?= usuarioEhAdmin() ? htmlspecialchars($item['profissional_nome']) . ' · ' : ''; ?>Último atendimento: <?= radar_date_br($item['ultimo_atendimento_data']); ?>
        </div>
        <?php if (!$item['whatsapp_phone']): ?><div class="radar-meta"><strong>Telefone não cadastrado.</strong></div><?php endif; ?>
        <div class="radar-actions">
          <button class="whats-btn js-wa" type="button" <?= !$item['whatsapp_phone'] ? 'disabled' : ''; ?>
            data-id="<?= (int)$item['id']; ?>"
            data-name="<?= htmlspecialchars($item['cliente_nome'], ENT_QUOTES); ?>"
            data-phone="<?= htmlspecialchars($item['whatsapp_phone'], ENT_QUOTES); ?>"
            data-message="<?= htmlspecialchars($item['whatsapp_message'], ENT_QUOTES); ?>">Chamar no WhatsApp</button>
          <div class="menu-wrap">
            <button class="menu-btn js-menu" type="button">⋮</button>
            <div class="menu-panel">
              <a href="agenda-visual.php?profissional_id=<?= (int)$item['profissional_id']; ?>&abrir_bloqueio_agendamento=1">Agendar cliente</a>
              <form method="post" action="radar-action.php"><?= csrf_input(); ?><input type="hidden" name="id" value="<?= (int)$item['id']; ?>"><input type="hidden" name="return_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES); ?>"><button name="radar_action" value="contatado">Marcar como contatado</button></form>
              <form method="post" action="radar-action.php"><?= csrf_input(); ?><input type="hidden" name="id" value="<?= (int)$item['id']; ?>"><input type="hidden" name="return_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES); ?>"><button name="radar_action" value="aguardando">Aguardando resposta</button></form>
              <form method="post" action="radar-action.php"><?= csrf_input(); ?><input type="hidden" name="id" value="<?= (int)$item['id']; ?>"><input type="hidden" name="return_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES); ?>"><input type="hidden" name="dias" value="1"><button name="radar_action" value="lembrar">Lembrar amanhã</button></form>
              <form method="post" action="radar-action.php"><?= csrf_input(); ?><input type="hidden" name="id" value="<?= (int)$item['id']; ?>"><input type="hidden" name="return_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES); ?>"><input type="hidden" name="dias" value="7"><button name="radar_action" value="lembrar">Lembrar em 7 dias</button></form>
              <form method="post" action="radar-action.php"><?= csrf_input(); ?><input type="hidden" name="id" value="<?= (int)$item['id']; ?>"><input type="hidden" name="return_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES); ?>"><button name="radar_action" value="ignorar">Ignorar neste ciclo</button></form>
              <form method="post" action="radar-action.php" onsubmit="return confirm('Desativar lembretes deste cliente?');"><?= csrf_input(); ?><input type="hidden" name="id" value="<?= (int)$item['id']; ?>"><input type="hidden" name="return_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES); ?>"><button name="radar_action" value="desativar">Desativar lembretes</button></form>
            </div>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="wa-preview" id="waPreview">
  <div class="wa-box">
    <h3 id="waTitle">Mensagem</h3>
    <textarea id="waText"></textarea>
    <div class="wa-actions">
      <a href="#" target="_blank" rel="noopener" id="waOpen">Abrir WhatsApp</a>
      <form method="post" action="radar-action.php" id="waContactForm">
        <?= csrf_input(); ?><input type="hidden" name="id" id="waRadarId"><input type="hidden" name="radar_action" value="contatado"><input type="hidden" name="return_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES); ?>">
        <button type="submit">Registrar contato</button>
      </form>
      <button type="button" id="waClose">Fechar</button>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('.js-menu').forEach(btn=>btn.addEventListener('click',e=>{e.stopPropagation();document.querySelectorAll('.menu-panel').forEach(p=>{if(p!==btn.nextElementSibling)p.classList.remove('open')});btn.nextElementSibling.classList.toggle('open')}));
document.addEventListener('click',()=>document.querySelectorAll('.menu-panel').forEach(p=>p.classList.remove('open')));
const wa=document.getElementById('waPreview'), waText=document.getElementById('waText'), waOpen=document.getElementById('waOpen'), waTitle=document.getElementById('waTitle'), waRadarId=document.getElementById('waRadarId');
document.querySelectorAll('.js-wa').forEach(btn=>btn.addEventListener('click',()=>{waTitle.textContent='Mensagem para '+btn.dataset.name;waText.value=btn.dataset.message;waRadarId.value=btn.dataset.id;waOpen.href='https://wa.me/'+btn.dataset.phone+'?text='+encodeURIComponent(waText.value);wa.classList.add('open')}));
waText.addEventListener('input',()=>{const current=document.querySelector('.js-wa[data-id="'+waRadarId.value+'"]');if(current)waOpen.href='https://wa.me/'+current.dataset.phone+'?text='+encodeURIComponent(waText.value)});
document.getElementById('waClose').onclick=()=>wa.classList.remove('open');wa.addEventListener('click',e=>{if(e.target===wa)wa.classList.remove('open')});
</script>
<?php admin_shell_end(); ?>
