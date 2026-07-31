<?php
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/admin-shell.php';

$podeEditarServicos = usuarioEhAdmin() || usuarioProfissionalId() === 1;

$flash = '';
$msg = '';

function servicosColumnExists(mysqli $conn, string $column): bool
{
    $columnEsc = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM servicos LIKE '{$columnEsc}'");
    return $res && $res->num_rows > 0;
}

function normalizarPublicoServico(string $publico): string
{
    return in_array($publico, ['masculino', 'feminino', 'ambos'], true) ? $publico : 'ambos';
}

function profissionalFotoServico(string $nome): string
{
    $normalizado = strtolower(trim($nome));
    if (strpos($normalizado, 'puchetti') !== false) {
        return '../assets/profissionais/andre-puchetti.png';
    }
    if (strpos($normalizado, 'amaro') !== false) {
        return '../assets/profissionais/andre-amaro.png';
    }
    return '../assets/profissionais/andre-puchetti.png';
}

function normalizarProfissionaisServico(array $profissionaisIds, array $profissionaisValidos, string $publico): string
{
    $puchettiId = 0;
    $validos = [];

    foreach ($profissionaisValidos as $prof) {
        $id = (int)$prof['id'];
        $validos[] = $id;
        if (stripos($prof['nome'], 'Puchetti') !== false) {
            $puchettiId = $id;
        }
    }

    if ($publico === 'feminino' && $puchettiId > 0) {
        return (string)$puchettiId;
    }

    $selecionados = [];
    foreach ($profissionaisIds as $id) {
        $idInt = (int)$id;
        if ($idInt > 0 && in_array($idInt, $validos, true)) {
            $selecionados[] = $idInt;
        }
    }

    $selecionados = array_values(array_unique($selecionados));
    sort($selecionados);

    return empty($selecionados) ? 'todos' : implode(',', $selecionados);
}

$temPublico = servicosColumnExists($conn, 'publico');
$temProfissionaisServico = servicosColumnExists($conn, 'profissionais_ids');

$profissionais = [];
$resProfissionais = $conn->query("SELECT id, nome FROM profissionais ORDER BY nome ASC");
if ($resProfissionais && $resProfissionais->num_rows > 0) {
    while ($row = $resProfissionais->fetch_assoc()) {
        $profissionais[] = $row;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_servico') {
    if (!$podeEditarServicos) {
        http_response_code(403);
        exit('Sem permissão para editar serviços.');
    }

    $id = (int)($_POST['servico_id'] ?? 0);
    $nome = trim($_POST['nome'] ?? '');
    $duracao = max(5, (int)($_POST['duracao'] ?? 0));
    $preco = str_replace(',', '.', preg_replace('/[^\d,\.]/', '', $_POST['preco'] ?? '0'));
    $precisaAnalise = isset($_POST['precisa_analise']) ? 1 : 0;
    $ativo = isset($_POST['ativo']) ? 1 : 0;

    if ($id <= 0 || $nome === '') {
        $flash = 'erro';
        $msg = 'Preencha nome e duração do serviço.';
    } else {
        $stmt = $conn->prepare("
            UPDATE servicos
            SET nome = ?, duracao = ?, preco = ?, precisa_analise = ?, ativo = ?
            WHERE id = ?
            LIMIT 1
        ");
        $precoFloat = (float)$preco;
        $stmt->bind_param('sidiii', $nome, $duracao, $precoFloat, $precisaAnalise, $ativo, $id);
        if ($stmt->execute()) {
            $flash = 'sucesso';
            $msg = 'Serviço atualizado com sucesso.';
        } else {
            $flash = 'erro';
            $msg = 'Não foi possível atualizar o serviço.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'adicionar_servico') {
    if (!$podeEditarServicos) {
        http_response_code(403);
        exit('Sem permissão para adicionar serviços.');
    }

    $nome = trim($_POST['nome'] ?? '');
    $duracao = max(5, (int)($_POST['duracao'] ?? 0));
    $preco = str_replace(',', '.', preg_replace('/[^\d,\.]/', '', $_POST['preco'] ?? '0'));
    $precisaAnalise = isset($_POST['precisa_analise']) ? 1 : 0;
    $ativo = isset($_POST['ativo']) ? 1 : 0;
    $publico = normalizarPublicoServico(trim($_POST['publico'] ?? 'ambos'));
    $profissionaisIds = normalizarProfissionaisServico($_POST['profissionais_ids'] ?? [], $profissionais, $publico);
    $precoFloat = (float)$preco;

    if ($nome === '' || $duracao <= 0) {
        $flash = 'erro';
        $msg = 'Preencha nome e duração do serviço.';
    } else {
        $fields = ['nome', 'duracao', 'preco', 'precisa_analise', 'ativo'];
        $placeholders = ['?', '?', '?', '?', '?'];
        $types = 'sidii';
        $values = [$nome, $duracao, $precoFloat, $precisaAnalise, $ativo];

        if ($temPublico) {
            $fields[] = 'publico';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $publico;
        }

        if ($temProfissionaisServico) {
            $fields[] = 'profissionais_ids';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $profissionaisIds;
        }

        $stmt = $conn->prepare("INSERT INTO servicos (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")");
        $stmt->bind_param($types, ...$values);

        if ($stmt->execute()) {
            $flash = 'sucesso';
            $msg = 'Serviço adicionado com sucesso.';
        } else {
            $flash = 'erro';
            $msg = 'Não foi possível adicionar o serviço.';
        }
    }
}

$servicos = [];
$res = $conn->query("SELECT id, nome, duracao, preco, precisa_analise, ativo FROM servicos ORDER BY ativo DESC, nome ASC");
if ($res && $res->num_rows > 0) {
    while ($row = $res->fetch_assoc()) {
        $servicos[] = $row;
    }
}

admin_shell_start('Serviços | André Puchetti', 'servicos');
?>
<style>
  .hero{margin-bottom:18px}
  .hero h1{margin:0 0 10px;font-size:clamp(2rem,4vw,3.2rem);letter-spacing:-.055em;line-height:.95}
  .hero h1 span{display:block;background:linear-gradient(90deg,#fff4cc,#d4af37 55%,#fff0a8);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;color:transparent}
  .hero p{margin:0;color:rgba(247,243,234,.72);line-height:1.7;max-width:820px}
  .flash{margin:0 0 16px;padding:14px 16px;border-radius:16px;font-weight:800}
  .flash.sucesso{background:rgba(32,201,151,.12);color:#b8ffe8;border:1px solid rgba(32,201,151,.24)}
  .flash.erro{background:rgba(255,95,109,.12);color:#ffd5da;border:1px solid rgba(255,95,109,.24)}
  .top-actions{display:flex;justify-content:flex-end;margin:0 0 14px}
  .add-btn{min-height:46px;border:0;border-radius:15px;background:linear-gradient(90deg,#c8a22a,#f2d778 50%,#cfa72d);color:#1a1405;font-weight:900;cursor:pointer;padding:0 18px}
  .services-grid{display:grid;gap:12px}
  .service-card{display:grid;grid-template-columns:1.4fr .5fr .6fr auto;gap:12px;align-items:end;padding:14px;border-radius:20px;background:linear-gradient(180deg,rgba(255,255,255,.055),rgba(255,255,255,.028));border:1px solid rgba(255,255,255,.08)}
  .field{display:grid;gap:7px}
  .field label,.toggles span{color:#f0d77a;font-size:10px;font-weight:900;letter-spacing:.14em;text-transform:uppercase}
  .field input,.field select{width:100%;min-height:44px;border-radius:14px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.04);color:#f7f3ea;padding:0 12px;outline:none}
  .field select option{background:#1f1d19;color:#f7f3ea}
  .toggles{display:grid;gap:8px}
  .check{display:flex;align-items:center;gap:8px;color:rgba(247,243,234,.82);font-weight:800;font-size:.9rem}
  .check input{accent-color:#d4af37}
  .save-btn{min-height:44px;border:0;border-radius:14px;background:linear-gradient(90deg,#c8a22a,#f2d778 50%,#cfa72d);color:#1a1405;font-weight:900;cursor:pointer;padding:0 16px}
  .readonly-note{margin:0 0 16px;padding:14px 16px;border-radius:16px;background:rgba(212,175,55,.08);border:1px solid rgba(212,175,55,.18);color:#fff1bf;line-height:1.6}
  .service-card.readonly{grid-template-columns:1.4fr .5fr .6fr .55fr}
  .readonly-pill{min-height:34px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;padding:0 10px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);font-size:12px;font-weight:900;color:rgba(247,243,234,.82)}
  input[readonly]{opacity:.82}
  input[disabled]{opacity:.75}
  .modal-overlay{position:fixed;inset:0;background:rgba(5,5,5,.72);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);display:flex;align-items:center;justify-content:center;padding:16px;opacity:0;visibility:hidden;transition:.22s ease;z-index:9999}
  .modal-overlay.open{opacity:1;visibility:visible}
  .modal{width:100%;max-width:620px;max-height:calc(100vh - 32px);overflow-y:auto;padding:22px;border-radius:24px;background:linear-gradient(180deg,rgba(35,33,28,.98),rgba(20,19,17,.98));border:1px solid rgba(212,175,55,.18);box-shadow:0 28px 80px rgba(0,0,0,.54),inset 0 1px 0 rgba(255,255,255,.06);transform:translateY(8px) scale(.98);transition:.22s ease}
  .modal-overlay.open .modal{transform:translateY(0) scale(1)}
  .modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:18px}
  .modal-title{margin:0;font-size:1.35rem;font-weight:900;letter-spacing:-.04em}
  .modal-copy{margin:5px 0 0;color:rgba(247,243,234,.66);line-height:1.55;font-size:.94rem}
  .close-btn{width:42px;height:42px;border-radius:14px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.04);color:#f7f3ea;font-size:1.2rem;cursor:pointer}
  .modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .modal-grid .field:first-child{grid-column:1/-1}
  .professionals-field{grid-column:1/-1;display:grid;gap:9px}
  .professionals-label{color:#f0d77a;font-size:10px;font-weight:900;letter-spacing:.14em;text-transform:uppercase}
  .professionals-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
  .professional-option{position:relative}
  .professional-option input{position:absolute;opacity:0;pointer-events:none}
  .professional-card{min-height:78px;border-radius:18px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.04);display:flex;align-items:center;gap:12px;padding:10px;cursor:pointer;transition:.18s ease}
  .professional-card img{width:54px;height:54px;border-radius:15px;object-fit:cover;border:1px solid rgba(212,175,55,.18);background:rgba(255,255,255,.04)}
  .professional-name{display:block;color:#f7f3ea;font-weight:900;font-size:.94rem;line-height:1.2}
  .professional-hint{display:block;margin-top:4px;color:rgba(247,243,234,.58);font-size:.78rem;line-height:1.35}
  .professional-option input:checked + .professional-card{border-color:rgba(212,175,55,.48);background:linear-gradient(180deg,rgba(212,175,55,.20),rgba(255,255,255,.045));box-shadow:0 14px 34px rgba(0,0,0,.22)}
  .professional-option input:disabled + .professional-card{opacity:.42;cursor:not-allowed}
  .modal-note{grid-column:1/-1;margin:0;color:rgba(247,243,234,.58);font-size:.84rem;line-height:1.55}
  .modal-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:16px}
  .ghost-btn{min-height:44px;border-radius:14px;border:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.04);color:#f7f3ea;font-weight:900;cursor:pointer;padding:0 16px}
  @media(max-width:900px){.service-card{grid-template-columns:1fr}.save-btn{width:100%}}
  @media(max-width:720px){.service-card{grid-template-columns:1fr}.top-actions{justify-content:stretch}.add-btn{width:100%}}
  @media(max-width:620px){.modal-overlay{align-items:flex-start;overflow-y:auto}.modal{padding:18px;border-radius:20px}.modal-grid,.professionals-grid{grid-template-columns:1fr}.modal-actions{display:grid}.ghost-btn,.modal-actions .save-btn{width:100%}}
</style>

<section class="hero">
  <h1>Gestão de <span>serviços</span></h1>
  <p>Controle duração, valor, disponibilidade e quais serviços precisam de análise profissional antes de informar preço.</p>
</section>

<?php if ($flash && $msg): ?>
  <div class="flash <?= htmlspecialchars($flash); ?>"><?= htmlspecialchars($msg); ?></div>
<?php endif; ?>

<?php if (!$podeEditarServicos): ?>
  <div class="readonly-note">
    Você pode consultar os serviços, duração, valores e quais exigem análise. Alterações ficam restritas ao administrador.
  </div>
<?php endif; ?>

<?php if ($podeEditarServicos): ?>
  <div class="top-actions">
    <button type="button" class="add-btn" id="openAddService">Adicionar serviço</button>
  </div>
<?php endif; ?>

<div class="services-grid">
  <?php foreach ($servicos as $servico): ?>
    <form method="POST" class="service-card <?= $podeEditarServicos ? '' : 'readonly'; ?>">
      <input type="hidden" name="acao" value="salvar_servico">
      <input type="hidden" name="servico_id" value="<?= (int)$servico['id']; ?>">
      <div class="field">
        <label>Nome</label>
        <input type="text" name="nome" value="<?= htmlspecialchars($servico['nome'], ENT_QUOTES); ?>" <?= $podeEditarServicos ? 'required' : 'readonly'; ?>>
      </div>
      <div class="field">
        <label>Duração</label>
        <input type="number" name="duracao" value="<?= (int)$servico['duracao']; ?>" min="5" step="5" <?= $podeEditarServicos ? 'required' : 'readonly'; ?>>
      </div>
      <div class="field">
        <label>Valor</label>
        <input type="text" name="preco" value="<?= (int)$servico['precisa_analise'] === 1 ? 'Valor após análise' : number_format((float)$servico['preco'], 2, ',', '.'); ?>" <?= $podeEditarServicos ? '' : 'readonly'; ?>>
      </div>
      <div class="toggles">
        <span>Opções</span>
        <label class="check"><input type="checkbox" name="precisa_analise" value="1" <?= (int)$servico['precisa_analise'] === 1 ? 'checked' : ''; ?> <?= $podeEditarServicos ? '' : 'disabled'; ?>> Requer análise</label>
        <label class="check"><input type="checkbox" name="ativo" value="1" <?= (int)$servico['ativo'] === 1 ? 'checked' : ''; ?> <?= $podeEditarServicos ? '' : 'disabled'; ?>> Ativo</label>
        <?php if ($podeEditarServicos): ?>
          <button class="save-btn" type="submit">Salvar</button>
        <?php else: ?>
          <span class="readonly-pill">Consulta</span>
        <?php endif; ?>
      </div>
    </form>
  <?php endforeach; ?>
</div>

<?php if ($podeEditarServicos): ?>
  <div class="modal-overlay" id="addServiceModal" aria-hidden="true">
    <form method="POST" class="modal" role="dialog" aria-modal="true" aria-labelledby="addServiceTitle">
      <input type="hidden" name="acao" value="adicionar_servico">
      <div class="modal-head">
        <div>
          <h2 class="modal-title" id="addServiceTitle">Adicionar serviço</h2>
          <p class="modal-copy">Cadastre o serviço e escolha a categoria. Serviços femininos continuam disponíveis apenas para André Puchetti.</p>
        </div>
        <button type="button" class="close-btn" id="closeAddService" aria-label="Fechar">×</button>
      </div>

      <div class="modal-grid">
        <div class="field">
          <label>Nome</label>
          <input type="text" name="nome" placeholder="Ex.: Corte + barba" required>
        </div>
        <div class="field">
          <label>Duração</label>
          <input type="number" name="duracao" value="30" min="5" step="5" required>
        </div>
        <div class="field">
          <label>Valor</label>
          <input type="text" name="preco" value="0,00">
        </div>
        <div class="field">
          <label>Categoria</label>
          <select name="publico" id="servicePublico">
            <option value="ambos">Masculino e feminino</option>
            <option value="masculino">Masculino</option>
            <option value="feminino">Feminino</option>
          </select>
        </div>
        <?php if ($temProfissionaisServico && !empty($profissionais)): ?>
          <div class="professionals-field">
            <span class="professionals-label">Profissional que atende</span>
            <div class="professionals-grid">
              <?php foreach ($profissionais as $prof): ?>
                <?php $isPuchetti = stripos($prof['nome'], 'Puchetti') !== false; ?>
                <label class="professional-option">
                  <input
                    type="checkbox"
                    name="profissionais_ids[]"
                    value="<?= (int)$prof['id']; ?>"
                    data-puchetti="<?= $isPuchetti ? '1' : '0'; ?>"
                  >
                  <span class="professional-card">
                    <img src="<?= htmlspecialchars(profissionalFotoServico($prof['nome'])); ?>" alt="<?= htmlspecialchars($prof['nome']); ?>">
                    <span>
                      <span class="professional-name"><?= htmlspecialchars($prof['nome']); ?></span>
                      <span class="professional-hint"><?= $isPuchetti ? 'Masculino e feminino' : 'Masculino'; ?></span>
                    </span>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
            <p class="modal-note">Se não selecionar ninguém, o serviço fica disponível para todos. Na categoria feminina, o sistema mantém apenas André Puchetti.</p>
          </div>
        <?php endif; ?>
        <div class="toggles">
          <span>Opções</span>
          <label class="check"><input type="checkbox" name="precisa_analise" value="1"> Requer análise</label>
          <label class="check"><input type="checkbox" name="ativo" value="1" checked> Ativo</label>
        </div>
      </div>

      <div class="modal-actions">
        <button type="button" class="ghost-btn" id="cancelAddService">Cancelar</button>
        <button class="save-btn" type="submit">Salvar serviço</button>
      </div>
    </form>
  </div>
<?php endif; ?>

<script>
  const openAddService = document.getElementById('openAddService');
  const addServiceModal = document.getElementById('addServiceModal');
  const closeAddService = document.getElementById('closeAddService');
  const cancelAddService = document.getElementById('cancelAddService');
  const servicePublico = document.getElementById('servicePublico');
  const professionalInputs = Array.from(document.querySelectorAll('input[name="profissionais_ids[]"]'));

  function setAddServiceModal(open) {
    if (!addServiceModal) return;
    addServiceModal.classList.toggle('open', open);
    addServiceModal.setAttribute('aria-hidden', open ? 'false' : 'true');
    document.body.style.overflow = open ? 'hidden' : '';
  }

  if (openAddService && addServiceModal) {
    openAddService.addEventListener('click', () => setAddServiceModal(true));
    closeAddService?.addEventListener('click', () => setAddServiceModal(false));
    cancelAddService?.addEventListener('click', () => setAddServiceModal(false));
    addServiceModal.addEventListener('click', (event) => {
      if (event.target === addServiceModal) setAddServiceModal(false);
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') setAddServiceModal(false);
    });
  }

  function syncProfessionalRules() {
    if (!servicePublico || !professionalInputs.length) return;

    const isFeminino = servicePublico.value === 'feminino';
    professionalInputs.forEach(input => {
      const isPuchetti = input.dataset.puchetti === '1';
      if (isFeminino) {
        input.checked = isPuchetti;
        input.disabled = !isPuchetti;
      } else {
        input.disabled = false;
      }
    });
  }

  servicePublico?.addEventListener('change', syncProfessionalRules);
  syncProfessionalRules();
</script>

<?php admin_shell_end(); ?>
