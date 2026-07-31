<?php
require_once 'config.php';
require_once __DIR__ . '/includes/seo.php';
date_default_timezone_set('America/Sao_Paulo');

const ASSISTANT_WHATSAPP = '5511947173110';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$servico = trim($_GET['servico'] ?? '');
$categoria = trim($_GET['categoria'] ?? '');
$profissional = trim($_GET['profissional'] ?? '');
$nome = trim($_GET['nome'] ?? '');
$telefone = preg_replace('/\D+/', '', $_GET['telefone'] ?? '');

if ($servico === '') {
    header('Location: index.php');
    exit;
}

$mensagemWhatsapp = "Olá! Quero fazer {$servico}. Vi que esse procedimento requer uma análise prévia do profissional para definição do valor total. Pode me orientar, por favor?";

if ($profissional !== '') {
    $mensagemWhatsapp .= " Tenho interesse em atendimento com {$profissional}.";
}

if ($nome !== '') {
    $mensagemWhatsapp .= " Meu nome é {$nome}.";
}

if ($telefone !== '') {
    $mensagemWhatsapp .= " Meu WhatsApp é {$telefone}.";
}

$whatsUrl = 'https://wa.me/' . ASSISTANT_WHATSAPP . '?text=' . urlencode($mensagemWhatsapp);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Serviço com análise | André Puchetti Hair Stylist</title>
<?php render_seo_meta(
    'Serviço com análise | André Puchetti Hair Stylist',
    'Alguns serviços do Salão André Puchetti precisam de análise prévia para orientar o atendimento e informar o valor final.',
    ['favicon_path' => 'assets/logo-salao.png', 'robots' => 'noindex, nofollow']
); ?>
  <style>
    :root {
      --bg: #070707;
      --text: #f7f3ea;
      --text-soft: rgba(247,243,234,0.75);
      --gold: #d4af37;
      --gold-soft: #f3dfa0;
      --green: #20c997;
    }
    * { box-sizing: border-box; }
    html, body { margin:0; padding:0; min-height:100%; background: radial-gradient(circle at 10% 20%, rgba(212,175,55,0.10), transparent 22%), radial-gradient(circle at 90% 10%, rgba(212,175,55,0.08), transparent 18%), linear-gradient(180deg, #050505 0%, #0a0a0a 44%, #0d0d0d 100%); color: var(--text); font-family: Inter, Arial, sans-serif; }
    body::before { content:""; position:fixed; inset:0; pointer-events:none; opacity:.11; background-image: linear-gradient(rgba(212,175,55,0.04) 1px, transparent 1px), linear-gradient(90deg, rgba(212,175,55,0.04) 1px, transparent 1px); background-size:34px 34px; mask-image: linear-gradient(to bottom, rgba(0,0,0,.95), transparent 95%); z-index:0; }
    .page { position:relative; z-index:2; min-height:100vh; width:100%; padding:22px 16px 40px; display:flex; justify-content:center; align-items:center; }
    .shell { width:100%; max-width:760px; }
    .card { background: linear-gradient(180deg, rgba(255,255,255,.055), rgba(255,255,255,.03)), radial-gradient(circle at top left, rgba(212,175,55,.08), transparent 42%); border:1px solid rgba(255,255,255,.08); box-shadow:0 22px 60px rgba(0,0,0,.40), inset 0 1px 0 rgba(255,255,255,.04); border-radius:28px; backdrop-filter:blur(18px); -webkit-backdrop-filter:blur(18px); padding:26px; overflow:hidden; }
    .eyebrow { color: var(--gold-soft); font-size:11px; font-weight:800; letter-spacing:.18em; text-transform:uppercase; margin-bottom:14px; }
    .title { margin:0; font-size:clamp(2rem, 6vw, 3.6rem); line-height:.94; letter-spacing:-.05em; font-weight:900; }
    .title span { display:block; background:linear-gradient(90deg, #fff4cc 0%, #d4af37 55%, #fff0a8 100%); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; color:transparent; }
    .description { margin-top:16px; color:var(--text-soft); line-height:1.75; font-size:1rem; max-width:620px; }
    .highlight-boxes { margin-top:26px; display:grid; gap:12px; }
    .info-box { border-radius:20px; border:1px solid rgba(255,255,255,.06); background:rgba(255,255,255,.03); padding:16px; }
    .info-label { color:var(--gold-soft); font-size:11px; font-weight:800; letter-spacing:.14em; text-transform:uppercase; margin-bottom:8px; }
    .info-value { font-size:1rem; font-weight:800; color:var(--text); line-height:1.55; word-break:break-word; }
    .badge { display:inline-flex; align-items:center; min-height:28px; padding:0 12px; border-radius:999px; font-size:11px; font-weight:900; letter-spacing:.08em; text-transform:uppercase; margin-top:10px; background:rgba(212,175,55,.14); color:#ffe9a8; border:1px solid rgba(212,175,55,.22); }
    .explanation { margin-top:22px; padding:18px; border-radius:22px; border:1px solid rgba(255,255,255,.06); background:rgba(255,255,255,.03); color:var(--text-soft); line-height:1.8; font-size:1rem; }
    .actions { margin-top:28px; display:flex; gap:12px; flex-wrap:wrap; }
    .btn { min-height:56px; border-radius:18px; padding:0 20px; font-size:1rem; font-weight:800; cursor:pointer; transition:.22s ease; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; text-align:center; width:100%; border:1px solid transparent; }
    .btn-whatsapp { background: linear-gradient(90deg, #28d367 0%, #20c997 100%); color:#06110c; box-shadow:0 16px 28px rgba(0,0,0,.22); }
    .btn-whatsapp:hover { transform:translateY(-2px); box-shadow:0 20px 30px rgba(0,0,0,.28); }
    .btn-back { background:rgba(255,255,255,.04); color:var(--text); border-color:rgba(255,255,255,.08); }
    .btn-back:hover { border-color:rgba(212,175,55,.24); transform:translateY(-1px); }
    @media (min-width:760px) { .card { padding:34px; } .actions { flex-wrap:nowrap; } .btn { width:auto; min-width:220px; } }
  </style>
</head>
<body>
  <div class="page">
    <div class="shell">
      <div class="card">
        <div class="eyebrow">Análise necessária</div>
        <h1 class="title"><span>Este serviço precisa</span>de avaliação profissional.</h1>
        <p class="description">Antes de confirmar esse atendimento, o profissional precisa analisar melhor o seu caso para orientar corretamente e informar o valor total final.</p>
        <div class="highlight-boxes">
          <div class="info-box">
            <div class="info-label">Serviço</div>
            <div class="info-value"><?php echo e($servico); ?></div>
            <div class="badge">Requer análise</div>
          </div>
          <?php if ($profissional !== ''): ?>
            <div class="info-box">
              <div class="info-label">Profissional</div>
              <div class="info-value"><?php echo e($profissional); ?></div>
            </div>
          <?php endif; ?>
          <?php if ($categoria !== ''): ?>
            <div class="info-box">
              <div class="info-label">Categoria</div>
              <div class="info-value"><?php echo e($categoria); ?></div>
            </div>
          <?php endif; ?>
        </div>
        <div class="explanation">Para esse tipo de procedimento, o valor só é definido depois da análise do profissional, porque pode variar conforme comprimento, volume, condição do cabelo e técnica necessária.</div>
        <div class="actions">
          <a href="<?php echo e($whatsUrl); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-whatsapp">Falar no WhatsApp</a>
          <a href="index.php" class="btn btn-back">Voltar</a>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
