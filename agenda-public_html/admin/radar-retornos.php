<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-shell.php';
require_once __DIR__ . '/../includes/radar.php';

date_default_timezone_set('America/Sao_Paulo');
radar_ensure_schema($conn);

$profissionalFiltro = usuarioEhAdmin() ? ($_GET['profissional_id'] ?? 'todos') : (string)(usuarioProfissionalId() ?? 0);
$stateFilter = $_GET['filtro'] ?? 'pendentes';
$q = trim($_GET['q'] ?? '');
$flash = $_GET['flash'] ?? '';
$msg = $_GET['msg'] ?? '';

$profissionais = [];
$resProf = $conn->query("SELECT id, nome FROM profissionais ORDER BY nome ASC");
while ($resProf && $row = $resProf->fetch_assoc()) {
    $profissionais[] = $row;
}

$profId = $profissionalFiltro !== 'todos' ? (int)$profissionalFiltro : null;
$allItems = retorno_manual_fetch($conn, ['profissional_id' => $profId, 'limit' => 500]);
$summary = retorno_manual_summary($allItems);
$items = retorno_manual_fetch($conn, [
    'profissional_id' => $profId,
    'state' => $stateFilter,
    'q' => $q,
    'limit' => 160,
]);

$selectedProfForForm = $profId ?: (int)($profissionais[0]['id'] ?? 0);

admin_shell_start('Central de Lembretes | André Puchetti', 'radar');
?>
<style>
  .reminder-hero{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:18px}.reminder-hero h1{margin:0;color:#fff0bd;font-size:clamp(2rem,7vw,4rem);line-height:.95}.reminder-hero p{margin:8px 0 0;color:rgba(247,243,234,.72)}
  .flash{padding:12px 14px;border-radius:16px;margin-bottom:14px;font-weight:900}.flash.sucesso{background:rgba(32,201,151,.12);color:#d9fff1}.flash.erro{background:rgba(255,95,109,.13);color:#ffd8dd}
  .summary-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-bottom:14px}.summary-card{border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.04);border-radius:16px;padding:14px;color:#f7f3ea;text-decoration:none}.summary-card strong{display:block;color:#fff;font-size:1.8rem}.summary-card span{color:rgba(247,243,234,.72);font-weight:900}.summary-card.hot{border-color:rgba(212,175,55,.25);background:rgba(212,175,55,.10)}
  .panel{border:1px solid rgba(255,255,255,.08);background:rgba(18,18,18,.78);border-radius:18px;padding:16px;margin-bottom:14px}.panel h2{margin:0 0 12px;color:#fff0bd;font-size:1.35rem}.form-grid{display:grid;gap:10px}.field{display:grid;gap:6px}.field label{font-size:.72rem;letter-spacing:.12em;text-transform:uppercase;color:#fff0bd;font-weight:950}.field input,.field select,.field textarea{min-height:44px;border:1px solid rgba(255,255,255,.1);background:#101010;color:#f7f3ea;border-radius:12px;padding:0 12px;font-weight:800}.field textarea{min-height:82px;padding:12px;resize:vertical}.submit-btn{min-height:48px;border:0;border-radius:14px;background:#d4af37;color:#17130b;font-weight:950;padding:0 18px}
  .toolbar{display:flex;align-items:end;gap:10px;flex-wrap:wrap}.toolbar .field input,.toolbar .field select{min-width:190px}.toolbar button{min-height:44px;border:1px solid rgba(212,175,55,.28);background:rgba(212,175,55,.14);color:#fff0bd;border-radius:12px;padding:0 14px;font-weight:950}
  .list{display:grid;gap:10px}.card{border:1px solid rgba(255,255,255,.08);background:rgba(18,18,18,.82);border-radius:18px;padding:14px}.card-top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.name{font-size:1.08rem;font-weight:950;color:#fff0bd}.state{font-weight:950}.state.atrasado,.state.chamar{color:#ffb8bf}.state.hoje{color:#fff0bd}.state.amanha,.state.em_breve{color:#b9dcff}.state.em_dia,.state.contatado{color:#d9fff1}.meta{margin-top:7px;color:rgba(247,243,234,.7);line-height:1.45;font-size:.92rem}.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.actions button,.actions a{min-height:42px;border:0;border-radius:12px;font-weight:950;padding:0 12px;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}.wa{background:#25d366;color:#062312}.secondary{background:rgba(255,255,255,.06);color:#f7f3ea}.danger{background:rgba(255,95,109,.12);color:#ffd8dd}.empty{padding:28px;border:1px solid rgba(255,255,255,.08);border-radius:18px;text-align:center;color:rgba(247,243,234,.72)}.empty strong{display:block;color:#fff0bd;font-size:1.15rem;margin-bottom:6px}
  .wa-preview{position:fixed;inset:0;background:rgba(0,0,0,.72);display:none;align-items:flex-end;justify-content:center;z-index:50}.wa-preview.open{display:flex}.wa-box{width:100%;max-width:540px;background:#101010;border:1px solid rgba(255,255,255,.1);border-radius:22px 22px 0 0;padding:18px}.wa-box h3{margin:0 0 12px;color:#fff0bd}.wa-box textarea{width:100%;min-height:170px;border:1px solid rgba(255,255,255,.1);background:#080808;color:#f7f3ea;border-radius:14px;padding:12px;font-size:15px;line-height:1.45}.wa-actions{display:grid;gap:8px;margin-top:12px}.wa-actions a,.wa-actions button{min-height:48px;border:0;border-radius:14px;font-weight:950;text-decoration:none;display:grid;place-items:center}.wa-actions a{background:#25d366;color:#062312}.wa-actions button{background:rgba(255,255,255,.06);color:#f7f3ea}
  @media(min-width:760px){.summary-grid{grid-template-columns:repeat(4,minmax(0,1fr))}.form-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.field.span-2{grid-column:span 2}.field.full{grid-column:1/-1}.wa-preview{align-items:center}.wa-box{border-radius:22px}}
  @media(max-width:640px){.toolbar,.toolbar .field,.toolbar .field input,.toolbar .field select,.toolbar button,.submit-btn{width:100%}.card-top{display:block}.actions button,.actions a{flex:1}}
</style>

<div class="reminder-hero">
  <div>
    <h1>Central de Lembretes</h1>
    <p>Cadastre clientes recorrentes e seja avisado antes da hora ideal de chamar.</p>
  </div>
</div>

<?php if ($msg): ?><div class="flash <?= htmlspecialchars($flash); ?>"><?= htmlspecialchars($msg); ?></div><?php endif; ?>

<div class="summary-grid">
  <a class="summary-card hot" href="?filtro=pendentes<?= usuarioEhAdmin() ? '&profissional_id=' . urlencode((string)$profissionalFiltro) : ''; ?>"><span>Para chamar</span><strong><?= (int)$summary['pendentes']; ?></strong></a>
  <a class="summary-card" href="?filtro=hoje<?= usuarioEhAdmin() ? '&profissional_id=' . urlencode((string)$profissionalFiltro) : ''; ?>"><span>Hoje</span><strong><?= (int)$summary['hoje']; ?></strong></a>
  <a class="summary-card" href="?filtro=em_breve<?= usuarioEhAdmin() ? '&profissional_id=' . urlencode((string)$profissionalFiltro) : ''; ?>"><span>Em breve</span><strong><?= (int)$summary['em_breve']; ?></strong></a>
  <a class="summary-card" href="?filtro=em_dia<?= usuarioEhAdmin() ? '&profissional_id=' . urlencode((string)$profissionalFiltro) : ''; ?>"><span>Em dia</span><strong><?= (int)$summary['em_dia']; ?></strong></a>
</div>

<section class="panel">
  <h2>Cadastrar recorrente</h2>
  <form class="form-grid" method="post" action="radar-action.php">
    <?= csrf_input(); ?>
    <input type="hidden" name="radar_action" value="salvar">
    <input type="hidden" name="return_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES); ?>">
    <div class="field"><label>Nome</label><input name="cliente_nome" required placeholder="Jefferson"></div>
    <div class="field"><label>WhatsApp</label><input name="cliente_telefone" required placeholder="(11) 99999-9999"></div>
    <div class="field"><label>Profissional</label>
      <?php if (usuarioEhAdmin()): ?>
        <select name="profissional_id" required>
          <?php foreach ($profissionais as $prof): ?><option value="<?= (int)$prof['id']; ?>" <?= (int)$selectedProfForForm === (int)$prof['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($prof['nome']); ?></option><?php endforeach; ?>
        </select>
      <?php else: ?>
        <input type="hidden" name="profissional_id" value="<?= (int)(usuarioProfissionalId() ?? 0); ?>">
        <input value="<?= htmlspecialchars($_SESSION['usuario_nome'] ?? 'Profissional'); ?>" disabled>
      <?php endif; ?>
    </div>
    <div class="field"><label>Frequência</label><input type="number" name="frequencia_dias" min="1" max="120" value="15" required></div>
    <div class="field"><label>Avisar com</label><input type="number" name="avisar_com_dias" min="1" max="120" value="13" required></div>
    <div class="field"><label>Último atendimento</label><input type="date" name="ultimo_atendimento" value="<?= date('Y-m-d'); ?>" required></div>
    <div class="field full"><label>Observação</label><textarea name="observacao" placeholder="Ex.: prefere final da tarde"></textarea></div>
    <button class="submit-btn" type="submit">Salvar lembrete</button>
  </form>
</section>

<section class="panel">
  <form class="toolbar" method="get">
    <div class="field"><label>Buscar</label><input type="search" name="q" value="<?= htmlspecialchars($q); ?>" placeholder="Nome ou WhatsApp"></div>
    <div class="field"><label>Situação</label>
      <select name="filtro">
        <?php foreach (['pendentes'=>'Para chamar','hoje'=>'Hoje','amanha'=>'Amanhã','em_breve'=>'Em breve','em_dia'=>'Em dia','todos'=>'Todos'] as $key => $label): ?>
          <option value="<?= htmlspecialchars($key); ?>" <?= $stateFilter === $key ? 'selected' : ''; ?>><?= htmlspecialchars($label); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if (usuarioEhAdmin()): ?>
      <div class="field"><label>Profissional</label>
        <select name="profissional_id">
          <option value="todos">Todos</option>
          <?php foreach ($profissionais as $prof): ?><option value="<?= (int)$prof['id']; ?>" <?= (string)$profissionalFiltro === (string)$prof['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($prof['nome']); ?></option><?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <button type="submit">Aplicar</button>
  </form>
</section>

<?php if (!$items): ?>
  <div class="empty"><strong>Nenhum lembrete por aqui.</strong>Cadastre um cliente recorrente ou ajuste os filtros.</div>
<?php else: ?>
  <div class="list">
    <?php foreach ($items as $item): ?>
      <article class="card">
        <div class="card-top">
          <div>
            <div class="name"><?= htmlspecialchars($item['cliente_nome']); ?></div>
            <div class="meta"><?= htmlspecialchars($item['profissional_nome']); ?> · <?= htmlspecialchars(formatarTelefoneExibicao($item['cliente_telefone'])); ?></div>
          </div>
          <div class="state <?= htmlspecialchars($item['estado_key']); ?>"><?= htmlspecialchars($item['estado_label']); ?></div>
        </div>
        <div class="meta">
          Frequência: a cada <?= (int)$item['frequencia_dias']; ?> dias · Avisar com <?= (int)$item['avisar_com_dias']; ?> dias<br>
          Último atendimento: <?= radar_date_br($item['ultimo_atendimento']); ?> · Aviso: <?= radar_date_br($item['data_aviso']); ?> · Hora ideal: <?= radar_date_br($item['data_corte_prevista']); ?>
          <?php if (!empty($item['observacao'])): ?><br><?= htmlspecialchars($item['observacao']); ?><?php endif; ?>
        </div>
        <div class="actions">
          <button class="wa js-wa" type="button" data-id="<?= (int)$item['id']; ?>" data-name="<?= htmlspecialchars($item['cliente_nome'], ENT_QUOTES); ?>" data-phone="<?= htmlspecialchars($item['whatsapp_phone'], ENT_QUOTES); ?>" data-message="<?= htmlspecialchars($item['whatsapp_message'], ENT_QUOTES); ?>" <?= !$item['whatsapp_phone'] ? 'disabled' : ''; ?>>WhatsApp</button>
          <form method="post" action="radar-action.php"><?= csrf_input(); ?><input type="hidden" name="return_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES); ?>"><input type="hidden" name="id" value="<?= (int)$item['id']; ?>"><button class="secondary" name="radar_action" value="contatado">Contatado</button></form>
          <form method="post" action="radar-action.php"><?= csrf_input(); ?><input type="hidden" name="return_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES); ?>"><input type="hidden" name="id" value="<?= (int)$item['id']; ?>"><input type="hidden" name="dias" value="1"><button class="secondary" name="radar_action" value="lembrar">Amanhã</button></form>
          <form method="post" action="radar-action.php"><?= csrf_input(); ?><input type="hidden" name="return_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES); ?>"><input type="hidden" name="id" value="<?= (int)$item['id']; ?>"><input type="hidden" name="dias" value="7"><button class="secondary" name="radar_action" value="lembrar">7 dias</button></form>
          <form method="post" action="radar-action.php" onsubmit="return confirm('Desativar este lembrete?');"><?= csrf_input(); ?><input type="hidden" name="return_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES); ?>"><input type="hidden" name="id" value="<?= (int)$item['id']; ?>"><button class="danger" name="radar_action" value="desativar">Desativar</button></form>
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
const wa=document.getElementById('waPreview'), waText=document.getElementById('waText'), waOpen=document.getElementById('waOpen'), waTitle=document.getElementById('waTitle'), waRadarId=document.getElementById('waRadarId');
document.querySelectorAll('.js-wa').forEach(btn=>btn.addEventListener('click',()=>{waTitle.textContent='Mensagem para '+btn.dataset.name;waText.value=btn.dataset.message;waRadarId.value=btn.dataset.id;waOpen.href='https://wa.me/'+btn.dataset.phone+'?text='+encodeURIComponent(waText.value);wa.classList.add('open')}));
waText.addEventListener('input',()=>{const current=document.querySelector('.js-wa[data-id="'+waRadarId.value+'"]');if(current)waOpen.href='https://wa.me/'+current.dataset.phone+'?text='+encodeURIComponent(waText.value)});
document.getElementById('waClose').onclick=()=>wa.classList.remove('open');wa.addEventListener('click',e=>{if(e.target===wa)wa.classList.remove('open')});
</script>
<?php admin_shell_end(); ?>
