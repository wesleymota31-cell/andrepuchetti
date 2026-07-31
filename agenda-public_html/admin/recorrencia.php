<?php
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/admin-shell.php';

date_default_timezone_set('America/Sao_Paulo');

if (!isset($_GET['cliente_id'])) {
    header('Location: clientes.php');
    exit;
}

$clienteId = (int) $_GET['cliente_id'];

function limparTelefone(string $tel): string {
    $numero = preg_replace('/\D+/', '', $tel);
    if (!$numero) return '';
    if (strlen($numero) === 10 || strlen($numero) === 11) {
        return '55' . $numero;
    }
    return $numero;
}

function formatarTelefoneExibicao(string $tel): string {
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

function existeBloqueio($conn, int $profissionalId, string $data, string $hora): bool {
    $stmt = $conn->prepare("
        SELECT id
        FROM bloqueios
        WHERE profissional_id = ?
          AND data = ?
          AND ? >= hora_inicio
          AND ? < hora_fim
        LIMIT 1
    ");
    $stmt->bind_param('isss', $profissionalId, $data, $hora, $hora);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res && $res->num_rows > 0;
}

function proximaDataRecorrencia(string $dataAtual, string $frequencia): ?string {
    switch ($frequencia) {
        case 'semanal':
            return date('Y-m-d', strtotime($dataAtual . ' +7 days'));
        case 'quinzenal':
            return date('Y-m-d', strtotime($dataAtual . ' +14 days'));
        case 'mensal':
            return date('Y-m-d', strtotime($dataAtual . ' +1 month'));
        case 'anual':
            return date('Y-m-d', strtotime($dataAtual . ' +1 year'));
        default:
            return null;
    }
}

/**
 * Procura um agendamento ativo exatamente no mesmo slot.
 * Retorna:
 * - o agendamento do mesmo cliente, se existir
 * - senão, qualquer conflito de outro cliente
 * - senão, null
 */
function buscarAgendamentoNoMesmoSlot($conn, int $profissionalId, int $clienteId, string $data, string $hora): ?array {
    $stmtMesmoCliente = $conn->prepare("
        SELECT id, cliente_id, recorrencia_id, is_recorrente, status
        FROM agendamentos
        WHERE profissional_id = ?
          AND cliente_id = ?
          AND data = ?
          AND hora = ?
          AND status IN ('confirmado', 'pendente')
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmtMesmoCliente->bind_param('iiss', $profissionalId, $clienteId, $data, $hora);
    $stmtMesmoCliente->execute();
    $resMesmoCliente = $stmtMesmoCliente->get_result();
    $mesmoCliente = $resMesmoCliente ? $resMesmoCliente->fetch_assoc() : null;

    if ($mesmoCliente) {
        return $mesmoCliente;
    }

    $stmtOutro = $conn->prepare("
        SELECT id, cliente_id, recorrencia_id, is_recorrente, status
        FROM agendamentos
        WHERE profissional_id = ?
          AND data = ?
          AND hora = ?
          AND status IN ('confirmado', 'pendente')
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmtOutro->bind_param('iss', $profissionalId, $data, $hora);
    $stmtOutro->execute();
    $resOutro = $stmtOutro->get_result();
    $outro = $resOutro ? $resOutro->fetch_assoc() : null;

    return $outro ?: null;
}

$stmtCliente = $conn->prepare("SELECT id, nome, telefone FROM clientes WHERE id = ? LIMIT 1");
$stmtCliente->bind_param('i', $clienteId);
$stmtCliente->execute();
$resCliente = $stmtCliente->get_result();
$cliente = $resCliente ? $resCliente->fetch_assoc() : null;

if (!$cliente) {
    header('Location: clientes.php');
    exit;
}

$clientesJson = [];
$resClientesJson = $conn->query("SELECT id, telefone FROM clientes");
if ($resClientesJson && $resClientesJson->num_rows > 0) {
    while ($row = $resClientesJson->fetch_assoc()) {
        $clientesJson[] = $row;
    }
}

$profissionais = [];
$resProfissionais = $conn->query("SELECT id, nome FROM profissionais ORDER BY nome ASC");
if ($resProfissionais && $resProfissionais->num_rows > 0) {
    while ($row = $resProfissionais->fetch_assoc()) {
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

$stmtRecAtual = $conn->prepare("
    SELECT id, profissional_id, servico_id, frequencia, data_inicio, data_fim, hora, ativo
    FROM recorrencias
    WHERE cliente_id = ?
      AND ativo = 1
    ORDER BY id DESC
    LIMIT 1
");
$stmtRecAtual->bind_param('i', $clienteId);
$stmtRecAtual->execute();
$resRecAtual = $stmtRecAtual->get_result();
$recorrenciaAtual = $resRecAtual ? $resRecAtual->fetch_assoc() : null;

$erro = '';
$sucesso = '';
$previewDatas = [];

$nomeForm = $cliente['nome'];
$telefoneForm = formatarTelefoneExibicao($cliente['telefone']);
$profissionalIdForm = $recorrenciaAtual['profissional_id'] ?? '';
$servicoIdForm = $recorrenciaAtual['servico_id'] ?? '';
$frequenciaForm = $recorrenciaAtual['frequencia'] ?? 'nenhuma';
$dataInicioForm = $recorrenciaAtual['data_inicio'] ?? '';
$dataFimForm = $recorrenciaAtual['data_fim'] ?? '';
$horaForm = isset($recorrenciaAtual['hora']) ? substr($recorrenciaAtual['hora'], 0, 5) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nomeForm = trim($_POST['nome'] ?? '');
    $telefoneForm = trim($_POST['telefone'] ?? '');
    $telefoneLimpo = limparTelefone($telefoneForm);

    $profissionalIdForm = trim($_POST['profissional_id'] ?? '');
    $servicoIdForm = trim($_POST['servico_id'] ?? '');
    $frequenciaForm = trim($_POST['frequencia'] ?? 'nenhuma');
    $dataInicioForm = trim($_POST['data_inicio'] ?? '');
    $dataFimForm = trim($_POST['data_fim'] ?? '');
    $horaForm = trim($_POST['hora'] ?? '');

    if ($nomeForm === '' || $telefoneLimpo === '') {
        $erro = 'Preencha nome e WhatsApp.';
    } else {
        $stmtDup = $conn->prepare("
            SELECT id, nome
            FROM clientes
            WHERE telefone = ?
              AND id <> ?
            LIMIT 1
        ");
        $stmtDup->bind_param('si', $telefoneLimpo, $clienteId);
        $stmtDup->execute();
        $resDup = $stmtDup->get_result();

        if ($resDup && $resDup->num_rows > 0) {
            $clienteExistente = $resDup->fetch_assoc();
            $erro = 'Já existe outro cliente com esse WhatsApp: ' . $clienteExistente['nome'] . '.';
        } else {
            try {
                $conn->begin_transaction();

                $stmtUpdateCliente = $conn->prepare("
                    UPDATE clientes
                    SET nome = ?, telefone = ?
                    WHERE id = ?
                    LIMIT 1
                ");
                $stmtUpdateCliente->bind_param('ssi', $nomeForm, $telefoneLimpo, $clienteId);
                $stmtUpdateCliente->execute();

                $recorrenciaIdAtual = $recorrenciaAtual ? (int)$recorrenciaAtual['id'] : 0;

                if ($frequenciaForm === 'nenhuma') {
                    if ($recorrenciaIdAtual > 0) {
                        $stmtDeleteAg = $conn->prepare("
                            DELETE FROM agendamentos
                            WHERE recorrencia_id = ?
                        ");
                        $stmtDeleteAg->bind_param('i', $recorrenciaIdAtual);
                        $stmtDeleteAg->execute();

                        $stmtDeleteRec = $conn->prepare("DELETE FROM recorrencias WHERE id = ?");
                        $stmtDeleteRec->bind_param('i', $recorrenciaIdAtual);
                        $stmtDeleteRec->execute();
                    }

                    $conn->commit();
                    $sucesso = 'Cliente atualizado e recorrência excluída por completo.';
                } else {
                    if ($profissionalIdForm === '' || $servicoIdForm === '' || $dataInicioForm === '' || $horaForm === '') {
                        throw new Exception('Para recorrência, preencha profissional, serviço, data de início e horário.');
                    }

                    if ($dataFimForm === '') {
                        $dataFimForm = date('Y-m-d', strtotime($dataInicioForm . ' +6 months'));
                    }

                    if ($dataFimForm < $dataInicioForm) {
                        throw new Exception('A data final não pode ser menor que a data inicial.');
                    }

                    $profissionalId = (int)$profissionalIdForm;
                    $servicoId = (int)$servicoIdForm;
                    $horaBanco = $horaForm . ':00';

                    if ($recorrenciaIdAtual > 0) {
                        // Limpa apenas o futuro da série atual
                        $stmtDeleteAgSerie = $conn->prepare("
                            DELETE FROM agendamentos
                            WHERE recorrencia_id = ?
                              AND data >= CURDATE()
                        ");
                        $stmtDeleteAgSerie->bind_param('i', $recorrenciaIdAtual);
                        $stmtDeleteAgSerie->execute();

                        $stmtUpdateRec = $conn->prepare("
                            UPDATE recorrencias
                            SET profissional_id = ?, servico_id = ?, frequencia = ?, data_inicio = ?, data_fim = ?, hora = ?, ativo = 1
                            WHERE id = ?
                        ");
                        $stmtUpdateRec->bind_param(
                            'iissssi',
                            $profissionalId,
                            $servicoId,
                            $frequenciaForm,
                            $dataInicioForm,
                            $dataFimForm,
                            $horaBanco,
                            $recorrenciaIdAtual
                        );
                        $stmtUpdateRec->execute();

                        $recorrenciaId = $recorrenciaIdAtual;
                    } else {
                        $stmtRec = $conn->prepare("
                            INSERT INTO recorrencias
                            (cliente_id, profissional_id, servico_id, frequencia, data_inicio, data_fim, hora, ativo)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                        ");
                        $stmtRec->bind_param(
                            'iiissss',
                            $clienteId,
                            $profissionalId,
                            $servicoId,
                            $frequenciaForm,
                            $dataInicioForm,
                            $dataFimForm,
                            $horaBanco
                        );

                        if (!$stmtRec->execute()) {
                            throw new Exception('Não foi possível salvar a recorrência.');
                        }

                        $recorrenciaId = (int)$stmtRec->insert_id;
                    }

                    $dataAtual = $dataInicioForm;

                    while ($dataAtual && $dataAtual <= $dataFimForm) {
                        $temBloqueio = existeBloqueio($conn, $profissionalId, $dataAtual, $horaBanco);

                        if (!$temBloqueio) {
                            $existente = buscarAgendamentoNoMesmoSlot($conn, $profissionalId, $clienteId, $dataAtual, $horaBanco);

                            if ($existente) {
                                // Se é do mesmo cliente, reaproveita e marca como recorrente
                                if ((int)$existente['cliente_id'] === $clienteId) {
                                    $status = 'confirmado';
                                    $isRecorrente = 1;

                                    $stmtUpdateAg = $conn->prepare("
                                        UPDATE agendamentos
                                        SET servico_id = ?, status = ?, recorrencia_id = ?, is_recorrente = ?
                                        WHERE id = ?
                                    ");
                                    $stmtUpdateAg->bind_param(
                                        'isiii',
                                        $servicoId,
                                        $status,
                                        $recorrenciaId,
                                        $isRecorrente,
                                        $existente['id']
                                    );
                                    $stmtUpdateAg->execute();

                                    if (count($previewDatas) < 8) {
                                        $previewDatas[] = date('d/m/Y', strtotime($dataAtual)) . ' às ' . substr($horaBanco, 0, 5);
                                    }
                                }
                                // Se é de outro cliente, não insere para não empilhar
                            } else {
                                $status = 'confirmado';
                                $isRecorrente = 1;

                                $stmtAg = $conn->prepare("
                                    INSERT INTO agendamentos
                                    (cliente_id, profissional_id, servico_id, data, hora, status, recorrencia_id, is_recorrente)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                                ");
                                $stmtAg->bind_param(
                                    'iiisssii',
                                    $clienteId,
                                    $profissionalId,
                                    $servicoId,
                                    $dataAtual,
                                    $horaBanco,
                                    $status,
                                    $recorrenciaId,
                                    $isRecorrente
                                );
                                $stmtAg->execute();

                                if (count($previewDatas) < 8) {
                                    $previewDatas[] = date('d/m/Y', strtotime($dataAtual)) . ' às ' . substr($horaBanco, 0, 5);
                                }
                            }
                        }

                        $dataAtual = proximaDataRecorrencia($dataAtual, $frequenciaForm);
                    }

                    $conn->commit();
                    $sucesso = 'Cliente e recorrência atualizados com sucesso.';

                    $stmtRecAtual = $conn->prepare("
                        SELECT id, profissional_id, servico_id, frequencia, data_inicio, data_fim, hora, ativo
                        FROM recorrencias
                        WHERE cliente_id = ?
                          AND ativo = 1
                        ORDER BY id DESC
                        LIMIT 1
                    ");
                    $stmtRecAtual->bind_param('i', $clienteId);
                    $stmtRecAtual->execute();
                    $resRecAtual = $stmtRecAtual->get_result();
                    $recorrenciaAtual = $resRecAtual ? $resRecAtual->fetch_assoc() : null;
                }
            } catch (Throwable $e) {
                $conn->rollback();
                $erro = $e->getMessage() ?: 'Não foi possível atualizar a recorrência.';
            }
        }
    }
}

admin_shell_start('Recorrência | André Puchetti', 'clientes');
?>
<style>
  .hero { margin-bottom: 24px; }
  .hero h1 {
    margin: 0 0 10px;
    font-size: clamp(2rem, 4vw, 3.4rem);
    line-height: .95;
    letter-spacing: -.05em;
    font-weight: 900;
  }
  .hero h1 span {
    display: block;
    background: linear-gradient(90deg,#fff4cc 0%,#d4af37 55%,#fff0a8 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    color: transparent;
  }
  .hero p {
    margin: 0;
    color: rgba(247,243,234,.78);
    line-height: 1.8;
    max-width: 760px;
  }

  .card {
    max-width: 980px;
    padding: 24px;
    border-radius: 28px;
    background: linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03));
    border: 1px solid rgba(255,255,255,.08);
    box-shadow: 0 20px 60px rgba(0,0,0,.45);
  }

  .card h2 { margin: 0 0 8px; font-size: 1.4rem; letter-spacing: -.03em; }
  .card-subtitle { margin: 0 0 20px; color: rgba(247,243,234,.55); line-height: 1.75; }

  .client-chip {
    display: inline-flex;
    align-items: center;
    min-height: 40px;
    padding: 0 14px;
    border-radius: 999px;
    background: rgba(212,175,55,.08);
    border: 1px solid rgba(212,175,55,.18);
    color: #f0d77a;
    font-weight: 800;
    margin-bottom: 18px;
  }

  .alert {
    margin-bottom: 16px;
    padding: 14px 16px;
    border-radius: 14px;
    font-size: .95rem;
    line-height: 1.7;
    font-weight: 600;
  }
  .alert.error { background: rgba(255,95,109,.10); border: 1px solid rgba(255,95,109,.20); color: #ffd7dc; }
  .alert.success { background: rgba(32,201,151,.10); border: 1px solid rgba(32,201,151,.22); color: #d8fff2; }

  .preview-box {
    margin-bottom: 16px;
    padding: 14px 16px;
    border-radius: 14px;
    background: rgba(212,175,55,.08);
    border: 1px solid rgba(212,175,55,.18);
    color: rgba(247,243,234,.78);
    line-height: 1.7;
  }
  .preview-box strong {
    display: block;
    color: #f0d77a;
    margin-bottom: 8px;
  }

  .section-title {
    margin: 20px 0 12px;
    color: #f0d77a;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .14em;
    text-transform: uppercase;
  }

  .field { margin-bottom: 16px; }
  .field label {
    display: block;
    margin-bottom: 8px;
    color: #f0d77a;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .14em;
    text-transform: uppercase;
  }
  .field input, .field select {
    width: 100%;
    min-height: 54px;
    border-radius: 16px;
    border: 1px solid rgba(255,255,255,.08);
    background: rgba(255,255,255,.04);
    color: #f7f3ea;
    padding: 0 16px;
    outline: none;
    font-size: 1rem;
  }

  .field-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
  }

  .status-inline {
    margin-top: 8px;
    display: none;
    min-height: 38px;
    align-items: center;
    padding: 0 14px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .05em;
    text-transform: uppercase;
  }
  .status-inline.show { display: inline-flex; }
  .status-inline.ok {
    background: rgba(32,201,151,.12);
    color: #d8fff2;
    border: 1px solid rgba(32,201,151,.20);
  }
  .status-inline.warn {
    background: rgba(255,95,109,.12);
    color: #ffd9de;
    border: 1px solid rgba(255,95,109,.22);
  }

  .recorrencia-box {
    margin-top: 8px;
    padding: 18px;
    border-radius: 20px;
    background: rgba(212,175,55,.06);
    border: 1px solid rgba(212,175,55,.14);
  }

  .recorrencia-box.hidden {
    display: none;
  }

  .submit-btn {
    width: 100%;
    min-height: 58px;
    border: none;
    cursor: pointer;
    border-radius: 18px;
    background: linear-gradient(90deg,#c8a22a 0%,#f2d778 50%,#cfa72d 100%);
    color: #1a1405;
    font-size: 1rem;
    font-weight: 900;
    letter-spacing: .02em;
  }

  .helper {
    margin-top: 14px;
    color: rgba(247,243,234,.55);
    line-height: 1.7;
    font-size: .94rem;
  }

  @media (max-width: 980px) {
    .field-row { grid-template-columns: 1fr; }
  }
</style>

<div class="hero">
  <h1>Configurar <span>cliente e recorrência</span></h1>
  <p>
    Edite os dados do cliente e defina se ele será recorrente ou não. Se escolher sem recorrência, o cliente continua cadastrado normalmente.
  </p>
</div>

<div class="card">
  <h2>Configuração do cliente</h2>
  <p class="card-subtitle">Atualize nome, WhatsApp e a lógica de recorrência do atendimento.</p>

  <div class="client-chip">
    Cliente atual: <?= htmlspecialchars($cliente['nome']); ?> • <?= htmlspecialchars(formatarTelefoneExibicao($cliente['telefone'])); ?>
  </div>

  <?php if ($erro): ?>
    <div class="alert error"><?= htmlspecialchars($erro); ?></div>
  <?php endif; ?>

  <?php if ($sucesso): ?>
    <div class="alert success"><?= htmlspecialchars($sucesso); ?></div>
  <?php endif; ?>

  <?php if (!empty($previewDatas)): ?>
    <div class="preview-box">
      <strong>Primeiras datas geradas/atualizadas</strong>
      <?= htmlspecialchars(implode(' • ', $previewDatas)); ?>
    </div>
  <?php endif; ?>

  <form method="POST">
    <div class="section-title">Dados do cliente</div>

    <div class="field">
      <label for="nome">Nome</label>
      <input type="text" id="nome" name="nome" value="<?= htmlspecialchars($nomeForm); ?>" required>
    </div>

    <div class="field">
      <label for="telefone">WhatsApp</label>
      <input type="text" id="telefone" name="telefone" value="<?= htmlspecialchars($telefoneForm); ?>" required>
      <div class="status-inline" id="telefoneStatus"></div>
    </div>

    <div class="section-title">Recorrência</div>

    <div class="field">
      <label for="frequencia">Frequência</label>
      <select id="frequencia" name="frequencia" required>
        <option value="nenhuma" <?= $frequenciaForm === 'nenhuma' ? 'selected' : ''; ?>>Sem recorrência</option>
        <option value="semanal" <?= $frequenciaForm === 'semanal' ? 'selected' : ''; ?>>Semanal</option>
        <option value="quinzenal" <?= $frequenciaForm === 'quinzenal' ? 'selected' : ''; ?>>Quinzenal</option>
        <option value="mensal" <?= $frequenciaForm === 'mensal' ? 'selected' : ''; ?>>Mensal</option>
        <option value="anual" <?= $frequenciaForm === 'anual' ? 'selected' : ''; ?>>Anual</option>
      </select>
    </div>

    <div class="recorrencia-box <?= $frequenciaForm === 'nenhuma' ? 'hidden' : ''; ?>" id="recorrenciaBox">
      <div class="field">
        <label for="profissional_id">Profissional</label>
        <select id="profissional_id" name="profissional_id">
          <option value="">Selecione</option>
          <?php foreach ($profissionais as $prof): ?>
            <option value="<?= (int)$prof['id']; ?>" <?= (string)$profissionalIdForm === (string)$prof['id'] ? 'selected' : ''; ?>>
              <?= htmlspecialchars($prof['nome']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label for="servico_id">Serviço</label>
        <select id="servico_id" name="servico_id">
          <option value="">Selecione</option>
          <?php foreach ($servicos as $serv): ?>
            <option value="<?= (int)$serv['id']; ?>" <?= (string)$servicoIdForm === (string)$serv['id'] ? 'selected' : ''; ?>>
              <?= htmlspecialchars($serv['nome']); ?> • <?= (int)$serv['duracao']; ?> min
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field-row">
        <div class="field">
          <label for="data_inicio">Data de início</label>
          <input type="date" id="data_inicio" name="data_inicio" value="<?= htmlspecialchars($dataInicioForm); ?>">
        </div>

        <div class="field">
          <label for="data_fim">Data final</label>
          <input type="date" id="data_fim" name="data_fim" value="<?= htmlspecialchars($dataFimForm); ?>">
        </div>
      </div>

      <div class="field">
        <label for="hora">Horário</label>
        <input type="time" id="hora" name="hora" value="<?= htmlspecialchars($horaForm); ?>">
      </div>

      <div class="helper">
        Se a data final não for preenchida, o sistema assume 6 meses automaticamente.
      </div>
    </div>

    <div style="margin-top:20px;">
      <button type="submit" class="submit-btn">Salvar alterações</button>
    </div>
  </form>
</div>

<script>
  const telefoneInput = document.getElementById('telefone');
  const telefoneStatus = document.getElementById('telefoneStatus');
  const frequenciaSelect = document.getElementById('frequencia');
  const recorrenciaBox = document.getElementById('recorrenciaBox');

  const clienteAtualId = <?= (int)$clienteId; ?>;
  const clientesExistentes = <?= json_encode(array_map(function($c) {
      return [
          'id' => (int)$c['id'],
          'telefone' => preg_replace('/\D+/', '', $c['telefone'])
      ];
  }, $clientesJson), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

  function limparNumeroJS(valor) {
    return valor.replace(/\D+/g, '');
  }

  function normalizarComparacao(valor) {
    let comparavel = limparNumeroJS(valor);
    if (comparavel.length === 10 || comparavel.length === 11) {
      comparavel = '55' + comparavel;
    }
    return comparavel;
  }

  function verificarDuplicataTelefone() {
    const comparavel = normalizarComparacao(telefoneInput.value);
    telefoneStatus.className = 'status-inline';
    telefoneStatus.textContent = '';

    if (!comparavel) return;

    const existeOutro = clientesExistentes.some(c => String(c.id) !== String(clienteAtualId) && c.telefone === comparavel);

    if (existeOutro) {
      telefoneStatus.classList.add('show', 'warn');
      telefoneStatus.textContent = 'WhatsApp já usado por outro cliente';
    } else {
      telefoneStatus.classList.add('show', 'ok');
      telefoneStatus.textContent = 'Número disponível';
    }
  }

  function toggleRecorrenciaBox() {
    recorrenciaBox.classList.toggle('hidden', frequenciaSelect.value === 'nenhuma');
  }

  telefoneInput.addEventListener('input', verificarDuplicataTelefone);
  frequenciaSelect.addEventListener('change', toggleRecorrenciaBox);

  verificarDuplicataTelefone();
  toggleRecorrenciaBox();
</script>
<?php admin_shell_end(); ?>
