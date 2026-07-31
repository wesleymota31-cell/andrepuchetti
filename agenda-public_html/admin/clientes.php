<?php
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/admin-shell.php';
require_once '../includes/phone.php';

date_default_timezone_set('America/Sao_Paulo');

function responderJsonClientes(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
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

function existeConflitoAgendamento($conn, int $profissionalId, string $data, string $hora): bool {
    $stmt = $conn->prepare("
        SELECT id
        FROM agendamentos
        WHERE profissional_id = ?
          AND data = ?
          AND hora = ?
          AND status IN ('confirmado', 'pendente')
        LIMIT 1
    ");
    $stmt->bind_param('iss', $profissionalId, $data, $hora);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res && $res->num_rows > 0;
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

$erro = '';
$sucesso = '';

$abrirModalCriar = false;
$abrirModalEditar = false;

$nomeForm = '';
$telefoneForm = '';
$recorrenteForm = false;
$profissionalForm = '';
$servicoForm = '';
$frequenciaForm = 'semanal';
$dataInicioForm = '';
$horaForm = '';

$editarClienteId = '';
$editarNomeForm = '';
$editarTelefoneForm = '';

$profissionais = [];
$resProf = $conn->query("SELECT id, nome FROM profissionais ORDER BY nome ASC");
if ($resProf && $resProf->num_rows > 0) {
    while ($row = $resProf->fetch_assoc()) {
        $profissionais[] = $row;
    }
}

$servicos = [];
$resServ = $conn->query("SELECT id, nome, duracao FROM servicos ORDER BY nome ASC");
if ($resServ && $resServ->num_rows > 0) {
    while ($row = $resServ->fetch_assoc()) {
        $servicos[] = $row;
    }
}

normalizarTelefonesClientesExistentes($conn);

/**
 * EDITAR CLIENTE INLINE - NOME / TELEFONE
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'editar_cliente_inline') {
    $clienteId = (int)($_POST['cliente_id'] ?? 0);
    $campo = trim($_POST['campo'] ?? '');
    $valorOriginal = trim($_POST['valor'] ?? '');

    if ($clienteId <= 0 || !in_array($campo, ['nome', 'telefone'], true)) {
        responderJsonClientes(['ok' => false, 'message' => 'Dados inválidos para edição.'], 400);
    }

    if ($campo === 'nome') {
        if ($valorOriginal === '') {
            responderJsonClientes(['ok' => false, 'message' => 'O nome não pode ficar vazio.'], 422);
        }

        $stmtUpInline = $conn->prepare("UPDATE clientes SET nome = ? WHERE id = ? LIMIT 1");
        $stmtUpInline->bind_param('si', $valorOriginal, $clienteId);

        if (!$stmtUpInline->execute()) {
            responderJsonClientes(['ok' => false, 'message' => 'Não foi possível atualizar o nome.'], 500);
        }

        responderJsonClientes([
            'ok' => true,
            'campo' => 'nome',
            'valor' => $valorOriginal,
            'valor_formatado' => $valorOriginal,
            'message' => 'Nome atualizado com sucesso.'
        ]);
    }

    if ($campo === 'telefone') {
        $telefoneNormalizadoInline = normalizarTelefoneCliente($valorOriginal);

        if (!$telefoneNormalizadoInline['valid']) {
            responderJsonClientes(['ok' => false, 'message' => $telefoneNormalizadoInline['error']], 422);
        }

        $clienteExistenteInline = buscarClientePorWhatsapp($conn, $telefoneNormalizadoInline['whatsapp_phone'], $clienteId);
        if ($clienteExistenteInline) {
            responderJsonClientes(['ok' => false, 'message' => 'Esse WhatsApp já está cadastrado para ' . $clienteExistenteInline['nome'] . '.'], 409);
        }

        $stmtUpInline = $conn->prepare("UPDATE clientes SET telefone = ? WHERE id = ? LIMIT 1");
        $stmtUpInline->bind_param('si', $telefoneNormalizadoInline['display_phone'], $clienteId);

        if (!$stmtUpInline->execute()) {
            responderJsonClientes(['ok' => false, 'message' => 'Não foi possível atualizar o WhatsApp.'], 500);
        }

        responderJsonClientes([
            'ok' => true,
            'campo' => 'telefone',
            'valor' => $telefoneNormalizadoInline['display_phone'],
            'valor_formatado' => $telefoneNormalizadoInline['display_phone'],
            'whatsapp' => $telefoneNormalizadoInline['whatsapp_phone'],
            'message' => 'WhatsApp atualizado com sucesso.'
        ]);
    }
}

/**
 * EDITAR CLIENTE
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'editar_cliente') {
    $editarClienteId = (int)($_POST['cliente_id'] ?? 0);
    $editarNomeForm = trim($_POST['nome'] ?? '');
    $editarTelefoneForm = trim($_POST['telefone'] ?? '');
    $telefoneNormalizado = normalizarTelefoneCliente($editarTelefoneForm);

    if ($editarClienteId <= 0 || $editarNomeForm === '' || !$telefoneNormalizado['valid']) {
        $erro = $telefoneNormalizado['valid'] ? 'Preencha nome e WhatsApp para editar o cliente.' : $telefoneNormalizado['error'];
        $abrirModalEditar = true;
    } else {
        $clienteExistente = buscarClientePorWhatsapp($conn, $telefoneNormalizado['whatsapp_phone'], $editarClienteId);
        if ($clienteExistente) {
            $erro = 'Já existe outro cliente com esse WhatsApp: ' . $clienteExistente['nome'] . '.';
            $abrirModalEditar = true;
        } else {
            $stmtUp = $conn->prepare("
                UPDATE clientes
                SET nome = ?, telefone = ?
                WHERE id = ?
                LIMIT 1
            ");
            $stmtUp->bind_param('ssi', $editarNomeForm, $telefoneNormalizado['display_phone'], $editarClienteId);

            if ($stmtUp->execute()) {
                $sucesso = 'Cliente atualizado com sucesso.';
            } else {
                $erro = 'Não foi possível atualizar o cliente.';
                $abrirModalEditar = true;
            }
        }
    }
}

/**
 * EXCLUIR CLIENTE
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir_cliente') {
    $clienteIdExcluir = (int)($_POST['cliente_id'] ?? 0);

    if ($clienteIdExcluir <= 0) {
        $erro = 'Cliente inválido para exclusão.';
    } else {
        $stmtRec = $conn->prepare("SELECT COUNT(*) AS total FROM recorrencias WHERE cliente_id = ?");
        $stmtRec->bind_param('i', $clienteIdExcluir);
        $stmtRec->execute();
        $totalRec = (int)($stmtRec->get_result()->fetch_assoc()['total'] ?? 0);

        $stmtAgRec = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM agendamentos
            WHERE cliente_id = ?
              AND (recorrencia_id IS NOT NULL OR is_recorrente = 1)
        ");
        $stmtAgRec->bind_param('i', $clienteIdExcluir);
        $stmtAgRec->execute();
        $totalAgRec = (int)($stmtAgRec->get_result()->fetch_assoc()['total'] ?? 0);

        if ($totalRec > 0 || $totalAgRec > 0) {
            $erro = 'Este cliente não pode ser excluído porque já possui recorrências vinculadas.';
        } else {
            $conn->begin_transaction();

            try {
                $stmtDelAg = $conn->prepare("DELETE FROM agendamentos WHERE cliente_id = ?");
                $stmtDelAg->bind_param('i', $clienteIdExcluir);
                $stmtDelAg->execute();

                $stmtDel = $conn->prepare("DELETE FROM clientes WHERE id = ? LIMIT 1");
                $stmtDel->bind_param('i', $clienteIdExcluir);
                $stmtDel->execute();

                if ($stmtDel->affected_rows > 0) {
                    $conn->commit();
                    $sucesso = 'Cliente excluído com sucesso.';
                } else {
                    $conn->rollback();
                    $erro = 'Não foi possível excluir o cliente.';
                }
            } catch (Throwable $e) {
                $conn->rollback();
                $erro = 'Não foi possível excluir o cliente.';
            }
        }
    }
}

/**
 * CRIAR CLIENTE
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar_cliente') {
    $nomeForm = trim($_POST['nome'] ?? '');
    $telefoneForm = trim($_POST['telefone'] ?? '');
    $telefoneNormalizado = normalizarTelefoneCliente($telefoneForm);

    $recorrenteForm = isset($_POST['recorrente']);
    $profissionalForm = trim($_POST['profissional'] ?? '');
    $servicoForm = trim($_POST['servico'] ?? '');
    $frequenciaForm = trim($_POST['frequencia'] ?? 'semanal');
    $dataInicioForm = trim($_POST['data_inicio'] ?? '');
    $horaForm = trim($_POST['hora'] ?? '');

    if ($nomeForm === '' || !$telefoneNormalizado['valid']) {
        $erro = $telefoneNormalizado['valid'] ? 'Preencha nome e WhatsApp.' : $telefoneNormalizado['error'];
        $abrirModalCriar = true;
    } else {
        $clienteExistente = buscarClientePorWhatsapp($conn, $telefoneNormalizado['whatsapp_phone']);
        if ($clienteExistente) {
            $erro = 'Já existe um cliente com esse WhatsApp: ' . $clienteExistente['nome'] . '.';
            $abrirModalCriar = true;
        } else {
            $stmtIns = $conn->prepare("INSERT INTO clientes (nome, telefone) VALUES (?, ?)");
            $stmtIns->bind_param('ss', $nomeForm, $telefoneNormalizado['display_phone']);

            if ($stmtIns->execute()) {
                $clienteId = $stmtIns->insert_id;

                if ($recorrenteForm) {
                    if ($profissionalForm === '' || $servicoForm === '' || $dataInicioForm === '' || $horaForm === '') {
                        $erro = 'Para cliente recorrente, preencha profissional, serviço, data de início e horário.';
                        $abrirModalCriar = true;
                    } else {
                        $profissionalId = (int)$profissionalForm;
                        $servicoId = (int)$servicoForm;
                        $dataFim = date('Y-m-d', strtotime($dataInicioForm . ' +6 months'));

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
                            $dataFim,
                            $horaForm
                        );

                        if ($stmtRec->execute()) {
                            $recorrenciaId = $stmtRec->insert_id;
                            $dataAtual = $dataInicioForm;
                            $horaBanco = $horaForm . ':00';

                            while ($dataAtual && $dataAtual <= $dataFim) {
                                $temConflito = existeConflitoAgendamento($conn, $profissionalId, $dataAtual, $horaBanco);
                                $temBloqueio = existeBloqueio($conn, $profissionalId, $dataAtual, $horaBanco);

                                if (!$temConflito && !$temBloqueio) {
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
                                }

                                $dataAtual = proximaDataRecorrencia($dataAtual, $frequenciaForm);
                            }

                            $sucesso = 'Cliente e recorrência criados com sucesso.';
                        } else {
                            $erro = 'Cliente criado, mas não foi possível salvar a recorrência.';
                            $abrirModalCriar = true;
                        }
                    }
                } else {
                    $sucesso = 'Cliente criado com sucesso.';
                }
            } else {
                $erro = 'Não foi possível criar o cliente.';
                $abrirModalCriar = true;
            }
        }
    }
}

$busca = trim($_GET['busca'] ?? '');

$pagina = max(1, (int)($_GET['pagina'] ?? 1));
$limite = (int)($_GET['limite'] ?? 10);
$limitesPermitidos = [10, 50, 100];

if (!in_array($limite, $limitesPermitidos, true)) {
    $limite = 10;
}

$ordem = trim($_GET['ordem'] ?? 'az');
$ordensPermitidas = ['az', 'za', 'recentes', 'antigos'];

if (!in_array($ordem, $ordensPermitidas, true)) {
    $ordem = 'az';
}

$orderSql = 'c.nome ASC';

switch ($ordem) {
    case 'za':
        $orderSql = 'c.nome DESC';
        break;
    case 'recentes':
        $orderSql = 'c.id DESC';
        break;
    case 'antigos':
        $orderSql = 'c.id ASC';
        break;
    case 'az':
    default:
        $orderSql = 'c.nome ASC';
        break;
}

$whereSql = '';
$paramsWhere = [];
$typesWhere = '';

if ($busca !== '') {
    $whereSql = " WHERE c.nome LIKE ? OR c.telefone LIKE ? ";
    $buscaLike = '%' . $busca . '%';
    $paramsWhere[] = $buscaLike;
    $paramsWhere[] = $buscaLike;
    $typesWhere .= 'ss';

    $buscaTelefone = normalizarTelefoneCliente($busca);
    if ($buscaTelefone['valid']) {
        $whereSql = " WHERE c.nome LIKE ? OR c.telefone LIKE ? OR c.telefone LIKE ? ";
        $paramsWhere[] = '%' . $buscaTelefone['display_phone'] . '%';
        $typesWhere .= 's';
    }
}

$sqlTotal = "SELECT COUNT(*) AS total FROM clientes c" . $whereSql;
$stmtTotal = $conn->prepare($sqlTotal);

if (!empty($paramsWhere)) {
    $stmtTotal->bind_param($typesWhere, ...$paramsWhere);
}

$stmtTotal->execute();
$totalClientes = (int)($stmtTotal->get_result()->fetch_assoc()['total'] ?? 0);
$totalPaginas = max(1, (int)ceil($totalClientes / $limite));

if ($pagina > $totalPaginas) {
    $pagina = $totalPaginas;
}

$offset = ($pagina - 1) * $limite;

$sql = "
    SELECT
        c.id,
        c.nome,
        c.telefone,
        EXISTS(
            SELECT 1
            FROM recorrencias r
            WHERE r.cliente_id = c.id
              AND r.ativo = 1
            LIMIT 1
        ) AS is_recorrente,
        (
            SELECT MAX(CONCAT(ag1.data, ' ', ag1.hora))
            FROM agendamentos ag1
            WHERE ag1.cliente_id = c.id
        ) AS ultimo_agendamento,
        (
            SELECT CONCAT(ag2.data, ' ', ag2.hora)
            FROM agendamentos ag2
            WHERE ag2.cliente_id = c.id
              AND ag2.status IN ('confirmado', 'pendente')
              AND CONCAT(ag2.data, ' ', ag2.hora) >= NOW()
            ORDER BY ag2.data ASC, ag2.hora ASC
            LIMIT 1
        ) AS proximo_agendamento,
        (
            SELECT p.nome
            FROM agendamentos ag3
            INNER JOIN profissionais p ON ag3.profissional_id = p.id
            WHERE ag3.cliente_id = c.id
              AND ag3.status IN ('confirmado', 'pendente')
              AND CONCAT(ag3.data, ' ', ag3.hora) >= NOW()
            ORDER BY ag3.data ASC, ag3.hora ASC
            LIMIT 1
        ) AS proximo_profissional
    FROM clientes c
" . $whereSql . "
    ORDER BY {$orderSql}
    LIMIT ? OFFSET ?
";

$params = $paramsWhere;
$types = $typesWhere . 'ii';
$params[] = $limite;
$params[] = $offset;

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$clientes = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $clientes[] = $row;
    }
}

$inicioExibicao = $totalClientes > 0 ? ($offset + 1) : 0;
$fimExibicao = min($offset + $limite, $totalClientes);

$queryBase = [
    'busca' => $busca,
    'limite' => $limite,
    'ordem' => $ordem,
];

$montarUrlPagina = function(int $paginaDestino) use ($queryBase): string {
    return '?' . http_build_query(array_merge($queryBase, ['pagina' => $paginaDestino]));
};

$telefonesExistentes = [];
$resTelefones = $conn->query("SELECT telefone FROM clientes");
if ($resTelefones && $resTelefones->num_rows > 0) {
    while ($rowTel = $resTelefones->fetch_assoc()) {
        $telefoneWhatsapp = telefoneWhatsapp($rowTel['telefone'] ?? '');
        if ($telefoneWhatsapp !== '') {
            $telefonesExistentes[] = $telefoneWhatsapp;
        }
    }
}
$telefonesExistentes = array_values(array_unique($telefonesExistentes));

$clientesJson = array_map(function($c) {
    $whatsappPhone = telefoneWhatsapp($c['telefone']);

    return [
        'id' => (int)$c['id'],
        'nome' => $c['nome'],
        'telefone' => $c['telefone'],
        'telefone_formatado' => formatarTelefoneExibicao($c['telefone']),
        'whatsapp_phone' => $whatsappPhone,
        'config_url' => 'recorrencia.php?cliente_id=' . (int)$c['id'],
    ];
}, $clientes);

admin_shell_start('Clientes | André Puchetti', 'clientes');
?>
<style>
  .hero { margin-bottom: 24px; }
  .hero-top { display:flex; justify-content:space-between; align-items:end; gap:16px; flex-wrap:wrap; }
  .hero h1 { margin:0 0 10px; font-size:clamp(2rem,4vw,3.5rem); line-height:.95; letter-spacing:-.05em; font-weight:900; }
  .hero h1 span {
    display:block;
    background:linear-gradient(90deg,#fff4cc 0%,#d4af37 55%,#fff0a8 100%);
    -webkit-background-clip:text;
    -webkit-text-fill-color:transparent;
    background-clip:text;
    color:transparent;
  }
  .hero p { margin:0; color:rgba(247,243,234,.78); line-height:1.8; max-width:780px; }

  .btn-add {
    min-height:52px; padding:0 18px; border-radius:16px; border:none; cursor:pointer;
    font-weight:900; letter-spacing:.02em;
    background:linear-gradient(90deg,#c8a22a 0%,#f2d778 50%,#cfa72d 100%);
    color:#1a1405; box-shadow:0 16px 34px rgba(212,175,55,.18); transition:.22s ease;
  }
  .btn-add:hover { transform:translateY(-2px); box-shadow:0 22px 40px rgba(212,175,55,.24); }

  .search-box, .table-wrap, .mobile-cards-wrap {
    border-radius:24px;
    background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03));
    border:1px solid rgba(255,255,255,.08);
    box-shadow:0 20px 60px rgba(0,0,0,.45);
    backdrop-filter:blur(18px);
    -webkit-backdrop-filter:blur(18px);
  }

  .search-box { margin-bottom:18px; padding:18px; }
  .search-box form { display:grid; grid-template-columns:minmax(260px,1fr) 170px 190px auto; gap:12px; align-items:end; }
  .filter-field { display:grid; gap:8px; }
  .filter-field label { color:#f0d77a; font-size:11px; font-weight:900; letter-spacing:.14em; text-transform:uppercase; }
  .filter-field select {
    width:100%; min-height:50px; border-radius:14px;
    border:1px solid rgba(255,255,255,.08); background:rgba(255,255,255,.04);
    color:#f7f3ea; padding:0 14px; outline:none;
  }
  .search-box input {
    flex:1; min-width:240px; min-height:50px; border-radius:14px;
    border:1px solid rgba(255,255,255,.08); background:rgba(255,255,255,.04);
    color:#f7f3ea; padding:0 14px; outline:none;
  }
  .search-box button {
    min-height:50px; border:none; cursor:pointer; border-radius:14px; padding:0 18px;
    background:linear-gradient(90deg,#c8a22a 0%,#f2d778 50%,#cfa72d 100%);
    color:#1a1405; font-weight:900;
  }

  .table-wrap { overflow:auto; }
  table { width:100%; border-collapse:collapse; min-width:1280px; }
  thead th {
    text-align:left; padding:18px 16px; font-size:12px; color:#f0d77a;
    letter-spacing:.14em; text-transform:uppercase; border-bottom:1px solid rgba(255,255,255,.08);
  }
  tbody td {
    padding:18px 16px; border-bottom:1px solid rgba(255,255,255,.06);
    color:rgba(247,243,234,.78); vertical-align:top;
  }
  tbody tr:hover { background:rgba(255,255,255,.02); }


  .editable-box {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    max-width: 360px;
    min-height: 44px;
    padding: 8px 42px 8px 12px;
    border-radius: 14px;
    background: rgba(255,255,255,.035);
    border: 1px solid rgba(255,255,255,.07);
    transition: .2s ease;
  }

  .editable-box:hover,
  .editable-box.editing {
    border-color: rgba(212,175,55,.24);
    background: rgba(212,175,55,.055);
  }

  .editable-text {
    display: block;
    color: #f7f3ea;
    font-weight: 800;
    line-height: 1.35;
    word-break: break-word;
    overflow-wrap: anywhere;
  }

  .editable-box[data-campo="telefone"] .editable-text {
    color: rgba(247,243,234,.78);
    font-weight: 700;
  }

  .editable-input {
    display: none;
    width: 100%;
    min-height: 34px;
    border: none;
    outline: none;
    border-radius: 10px;
    background: rgba(0,0,0,.24);
    color: #f7f3ea;
    padding: 0 10px;
    font-size: .95rem;
    font-weight: 800;
  }

  .editable-box.editing .editable-text { display: none; }
  .editable-box.editing .editable-input { display: block; }

  .editable-actions {
    position: absolute;
    top: 50%;
    right: 7px;
    transform: translateY(-50%);
    display: flex;
    align-items: center;
    gap: 4px;
  }

  .inline-edit-btn,
  .inline-save-btn,
  .inline-cancel-btn {
    width: 30px;
    height: 30px;
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,.08);
    background: rgba(255,255,255,.05);
    color: #f0d77a;
    cursor: pointer;
    display: inline-grid;
    place-items: center;
    font-size: 13px;
    line-height: 1;
    transition: .18s ease;
  }

  .inline-edit-btn:hover,
  .inline-save-btn:hover,
  .inline-cancel-btn:hover {
    transform: translateY(-1px);
    border-color: rgba(212,175,55,.28);
  }

  .inline-save-btn {
    color: #d8fff2;
    background: rgba(32,201,151,.12);
    border-color: rgba(32,201,151,.22);
  }

  .inline-cancel-btn {
    color: #ffd9de;
    background: rgba(255,95,109,.10);
    border-color: rgba(255,95,109,.20);
  }

  .editable-box .inline-save-btn,
  .editable-box .inline-cancel-btn { display: none; }
  .editable-box.editing .inline-edit-btn { display: none; }
  .editable-box.editing .inline-save-btn,
  .editable-box.editing .inline-cancel-btn { display: inline-grid; }

  .editable-feedback {
    display: none;
    margin-top: 6px;
    color: rgba(247,243,234,.58);
    font-size: 11px;
    line-height: 1.4;
  }

  .editable-feedback.show { display: block; }
  .editable-feedback.ok { color: #b8ffe8; }
  .editable-feedback.error { color: #ffd9de; }

  .whats-btn, .menu-trigger, .card-btn {
    display:inline-flex; align-items:center; justify-content:center; min-height:38px; padding:0 14px;
    border-radius:999px; text-decoration:none; font-size:12px; font-weight:800;
    letter-spacing:.05em; text-transform:uppercase; transition:.25s ease; cursor:pointer; font-family:inherit;
  }

  .whats-btn, .card-btn.whats {
    background:rgba(32,201,151,.12); color:#d8fff2; border:1px solid rgba(32,201,151,.20);
  }
  .whats-btn:hover, .card-btn.whats:hover {
    transform:translateY(-2px); background:rgba(32,201,151,.18);
  }

  .card-btn.config {
    background:rgba(212,175,55,.12); color:#fff2bf; border:1px solid rgba(212,175,55,.20);
  }
  .card-btn.config:hover {
    transform:translateY(-2px); background:rgba(212,175,55,.18);
  }

  .menu-trigger {
    min-width:42px; min-height:42px; padding:0;
    border:1px solid rgba(255,255,255,.08); background:rgba(255,255,255,.05);
    color:#f7f3ea; cursor:pointer; font-size:20px; line-height:1; text-transform:none; letter-spacing:0;
  }
  .menu-trigger:hover { transform:translateY(-2px); border-color:rgba(212,175,55,.22); }

  .floating-menu {
    position:fixed; min-width:220px; padding:10px; border-radius:18px;
    background:rgba(15,15,15,.98); border:1px solid rgba(255,255,255,.08);
    box-shadow:0 20px 50px rgba(0,0,0,.45);
    opacity:0; visibility:hidden; transform:translateY(6px); transition:.18s ease; z-index:99999;
  }
  .floating-menu.open { opacity:1; visibility:visible; transform:translateY(0); }

  .floating-link, .floating-form button {
    width:100%; min-height:44px; border-radius:12px; display:flex; align-items:center; justify-content:flex-start;
    padding:0 14px; background:rgba(255,255,255,.04); color:#f7f3ea;
    border:1px solid rgba(255,255,255,.06); text-decoration:none; font-weight:700; cursor:pointer; transition:.18s ease;
  }
  .floating-link:hover, .floating-form button:hover { background:rgba(255,255,255,.08); }
  .floating-form { margin-top:8px; }

  .badge-yes, .badge-no {
    display:inline-flex; align-items:center; justify-content:center; min-height:30px; padding:0 12px;
    border-radius:999px; font-size:11px; font-weight:800; letter-spacing:.08em; text-transform:uppercase;
  }
  .badge-yes {
    background:rgba(212,175,55,.14); color:#fff0b3; border:1px solid rgba(212,175,55,.26);
  }
  .badge-no {
    background:rgba(255,255,255,.07); color:rgba(247,243,234,.72); border:1px solid rgba(255,255,255,.08);
  }

  .muted { color:rgba(247,243,234,.55); font-size:.92rem; }
  .empty { padding:32px 24px; color:rgba(247,243,234,.55); line-height:1.8; }

  .flash {
    margin-bottom:16px; padding:14px 16px; border-radius:14px; font-size:.95rem; line-height:1.7; font-weight:600;
  }
  .flash.error { background:rgba(255,95,109,.10); border:1px solid rgba(255,95,109,.20); color:#ffd7dc; }
  .flash.success { background:rgba(32,201,151,.10); border:1px solid rgba(32,201,151,.22); color:#d8fff2; }

  .mobile-cards-wrap { display:none; padding:16px; }
  .mobile-cards { display:grid; gap:14px; }
  .client-card {
    padding:18px; border-radius:22px; background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.07);
  }
  .client-card-top {
    display:flex; justify-content:space-between; gap:12px; align-items:start; margin-bottom:12px;
  }
  .client-card h3 { margin:0 0 4px; font-size:1.05rem; color:#f7f3ea; }
  .client-card-phone { color:rgba(247,243,234,.68); font-size:.95rem; }
  .card-grid { display:grid; gap:8px; margin-bottom:14px; }
  .card-item {
    padding:10px 12px; border-radius:14px; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.06);
  }
  .card-item small {
    display:block; margin-bottom:4px; color:#f0d77a; font-size:10px; letter-spacing:.12em; font-weight:800; text-transform:uppercase;
  }
  .card-item span, .card-item strong { color:#f7f3ea; line-height:1.45; font-size:.96rem; }
  .card-actions { display:flex; gap:10px; flex-wrap:wrap; }
  .card-btn { flex:1; min-width:120px; }
  button.card-btn { border:1px solid rgba(32,201,151,.20); font-family:inherit; }


  .list-meta {
    margin:0 0 14px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
    color:rgba(247,243,234,.62);
    font-size:.94rem;
  }

  .pagination {
    margin:18px 0 0;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
  }

  .pagination-info {
    color:rgba(247,243,234,.62);
    font-size:.94rem;
  }

  .pagination-links {
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
  }

  .page-link {
    min-width:42px;
    min-height:42px;
    padding:0 12px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:14px;
    text-decoration:none;
    background:rgba(255,255,255,.04);
    border:1px solid rgba(255,255,255,.08);
    color:#f7f3ea;
    font-weight:800;
    transition:.18s ease;
  }

  .page-link:hover {
    transform:translateY(-1px);
    border-color:rgba(212,175,55,.24);
    background:rgba(255,255,255,.07);
  }

  .page-link.active {
    background:linear-gradient(90deg,#c8a22a 0%,#f2d778 50%,#cfa72d 100%);
    color:#1a1405;
    border-color:rgba(212,175,55,.26);
  }

  .page-link.disabled {
    opacity:.35;
    pointer-events:none;
  }

  .modal-overlay {
    position:fixed; inset:0; background:rgba(5,5,5,.72); backdrop-filter:blur(12px);
    -webkit-backdrop-filter:blur(12px); display:flex; align-items:center; justify-content:center;
    padding:20px; opacity:0; visibility:hidden; transition:.28s ease; z-index:9999;
  }
  .modal-overlay.active { opacity:1; visibility:visible; }
  .modal {
    width:100%; max-width:620px; padding:26px; border-radius:28px;
    background:linear-gradient(180deg, rgba(255,255,255,.08), rgba(255,255,255,.04));
    border:1px solid rgba(255,255,255,.10); box-shadow:0 24px 70px rgba(0,0,0,.40);
    transform:translateY(8px) scale(.98); transition:.28s ease; position:relative; overflow:hidden;
  }
  .modal-overlay.active .modal { transform:translateY(0) scale(1); }
  .modal::before {
    content:""; position:absolute; inset:0; pointer-events:none;
    background:linear-gradient(135deg, rgba(212,175,55,.16), transparent 35%, transparent 65%, rgba(212,175,55,.08));
  }
  .modal-top {
    display:flex; justify-content:space-between; align-items:start; gap:12px; margin-bottom:18px; position:relative; z-index:1;
  }
  .modal-title { margin:0; font-size:1.7rem; line-height:1; letter-spacing:-.04em; }
  .modal-subtitle { margin:8px 0 0; color:rgba(247,243,234,.62); line-height:1.7; }
  .close-btn {
    width:42px; height:42px; border:none; cursor:pointer; border-radius:12px;
    background:rgba(255,255,255,.06); color:#f7f3ea; font-size:1.2rem;
  }
  .modal-form { position:relative; z-index:1; }
  .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
  .field { margin-bottom:14px; }
  .field.full { grid-column:1 / -1; }
  .field label {
    display:block; margin-bottom:8px; color:#f0d77a; font-size:12px; font-weight:800;
    letter-spacing:.14em; text-transform:uppercase;
  }
  .field input, .field select {
    width:100%; min-height:54px; border-radius:16px; border:1px solid rgba(255,255,255,.08);
    background:rgba(255,255,255,.04); color:#f7f3ea; padding:0 16px; outline:none; font-size:1rem;
  }
  .toggle-card {
    display:flex; gap:12px; align-items:center; min-height:58px; padding:0 16px;
    border-radius:16px; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.08); margin:6px 0 18px;
  }
  .toggle-card input[type="checkbox"] {
    width:18px; height:18px; accent-color:#d4af37;
  }
  .toggle-card strong { display:block; color:#f7f3ea; font-size:.98rem; }
  .toggle-card span { display:block; color:rgba(247,243,234,.55); font-size:.9rem; }
  .rec-box {
    display:none; margin-top:6px; padding:18px; border-radius:20px;
    background:rgba(212,175,55,.06); border:1px solid rgba(212,175,55,.14);
  }
  .rec-box.active { display:block; }

  .status-inline {
    margin-top:8px; display:none; min-height:38px; align-items:center; padding:0 14px;
    border-radius:999px; font-size:12px; font-weight:800; letter-spacing:.05em; text-transform:uppercase;
  }
  .status-inline.show { display:inline-flex; }
  .status-inline.ok {
    background:rgba(32,201,151,.12); color:#d8fff2; border:1px solid rgba(32,201,151,.20);
  }
  .status-inline.warn {
    background:rgba(255,95,109,.12); color:#ffd9de; border:1px solid rgba(255,95,109,.22);
  }

  .modal-actions { display:flex; gap:12px; margin-top:20px; flex-wrap:wrap; }
  .modal-btn {
    flex:1; min-width:180px; min-height:54px; border-radius:16px; border:none; cursor:pointer;
    font-weight:900; letter-spacing:.02em; transition:.22s ease;
  }
  .modal-btn.primary {
    background:linear-gradient(90deg,#c8a22a 0%,#f2d778 50%,#cfa72d 100%);
    color:#1a1405; box-shadow:0 16px 34px rgba(212,175,55,.18);
  }
  .modal-btn.primary:hover { transform:translateY(-2px); }
  .modal-btn.secondary {
    background:rgba(255,255,255,.05); color:#f7f3ea; border:1px solid rgba(255,255,255,.08);
  }

  .whatsapp-modal .modal { max-width:760px; }
  .whatsapp-client {
    display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;
    margin:0 0 18px; padding:14px 16px; border-radius:18px;
    background:rgba(32,201,151,.08); border:1px solid rgba(32,201,151,.18); position:relative; z-index:1;
  }
  .whatsapp-client strong { display:block; color:#f7f3ea; font-size:1rem; }
  .whatsapp-client span { display:block; margin-top:3px; color:rgba(247,243,234,.62); font-weight:700; }
  .message-options {
    display:grid; gap:12px; position:relative; z-index:1;
  }
  .message-option {
    width:100%; border:none; cursor:pointer; text-align:left; border-radius:18px;
    padding:16px; background:rgba(255,255,255,.045); color:#f7f3ea;
    border:1px solid rgba(255,255,255,.08); display:grid; grid-template-columns:44px 1fr;
    align-items:center; gap:12px; transition:.22s ease;
    font-family:inherit;
  }
  .message-option:hover {
    transform:translateY(-2px); border-color:rgba(212,175,55,.26);
    background:linear-gradient(180deg, rgba(212,175,55,.10), rgba(255,255,255,.045));
  }
  .message-icon {
    width:44px; height:44px; border-radius:14px; display:grid; place-items:center;
    background:rgba(212,175,55,.12); border:1px solid rgba(212,175,55,.20); color:#f2d778;
  }
  .message-icon svg { width:21px; height:21px; fill:none; stroke:currentColor; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
  .message-copy { display:grid; gap:7px; }
  .message-copy strong { font-size:1rem; line-height:1.25; }
  .message-copy span { color:rgba(247,243,234,.62); line-height:1.55; font-size:.92rem; }

  @media (max-width:980px) {
    .table-wrap { display:none; }
    .mobile-cards-wrap { display:block; }
  }
  @media (min-width:981px) {
    .mobile-cards-wrap { display:none; }
  }
  @media (max-width:760px) {
    .editable-box {
      max-width: 100%;
      width: 100%;
      min-height: 46px;
    }

    .search-box form { grid-template-columns:1fr; }
    .form-grid { grid-template-columns:1fr; }
    .hero-top { align-items:stretch; }
    .btn-add { width:100%; }
  }
</style>

<div class="hero">
  <div class="hero-top">
    <div>
      <h1>Base de <span>clientes</span></h1>
      <p>
        Aqui você visualiza os clientes do salão, configura recorrência e cadastra novos contatos manualmente sem sair da tela.
      </p>
    </div>

    <button class="btn-add" type="button" id="openModalBtn">+ Adicionar cliente</button>
  </div>
</div>

<?php if ($erro): ?>
  <div class="flash error"><?= htmlspecialchars($erro); ?></div>
<?php endif; ?>

<?php if ($sucesso): ?>
  <div class="flash success"><?= htmlspecialchars($sucesso); ?></div>
<?php endif; ?>

<div class="search-box">
  <form method="GET">
    <div class="filter-field">
      <label for="busca">Buscar</label>
      <input type="text" id="busca" name="busca" placeholder="Buscar por nome ou telefone" value="<?= htmlspecialchars($busca); ?>">
    </div>

    <div class="filter-field">
      <label for="limite">Exibir</label>
      <select id="limite" name="limite">
        <option value="10" <?= $limite === 10 ? 'selected' : ''; ?>>10 por página</option>
        <option value="50" <?= $limite === 50 ? 'selected' : ''; ?>>50 por página</option>
        <option value="100" <?= $limite === 100 ? 'selected' : ''; ?>>100 por página</option>
      </select>
    </div>

    <div class="filter-field">
      <label for="ordem">Ordenar</label>
      <select id="ordem" name="ordem">
        <option value="az" <?= $ordem === 'az' ? 'selected' : ''; ?>>A–Z</option>
        <option value="za" <?= $ordem === 'za' ? 'selected' : ''; ?>>Z–A</option>
        <option value="recentes" <?= $ordem === 'recentes' ? 'selected' : ''; ?>>Mais recentes</option>
        <option value="antigos" <?= $ordem === 'antigos' ? 'selected' : ''; ?>>Mais antigos</option>
      </select>
    </div>

    <input type="hidden" name="pagina" value="1">
    <button type="submit">Aplicar filtros</button>
  </form>
</div>

<div class="list-meta">
  <div>
    <?php if ($totalClientes > 0): ?>
      Exibindo <?= (int)$inicioExibicao; ?>–<?= (int)$fimExibicao; ?> de <?= (int)$totalClientes; ?> cliente<?= $totalClientes === 1 ? '' : 's'; ?>.
    <?php else: ?>
      Nenhum cliente encontrado.
    <?php endif; ?>
  </div>

  <?php if ($busca !== ''): ?>
    <div>Busca ativa: <strong><?= htmlspecialchars($busca); ?></strong></div>
  <?php endif; ?>
</div>

<div class="table-wrap">
  <?php if (!empty($clientes)): ?>
    <table>
      <thead>
        <tr>
          <th>Cliente</th>
          <th>Telefone</th>
          <th>Último agendamento</th>
          <th>Próximo agendamento</th>
          <th>Profissional</th>
          <th>Recorrente</th>
          <th>WhatsApp</th>
          <th>Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($clientes as $cliente): ?>
          <?php $clienteWhatsapp = telefoneWhatsapp($cliente['telefone']); ?>
          <tr>
            <td>
              <div
                class="editable-box js-inline-edit"
                data-cliente-id="<?= (int)$cliente['id']; ?>"
                data-campo="nome"
                data-original="<?= htmlspecialchars($cliente['nome'], ENT_QUOTES); ?>"
              >
                <span class="editable-text"><?= htmlspecialchars($cliente['nome']); ?></span>
                <input class="editable-input" type="text" value="<?= htmlspecialchars($cliente['nome'], ENT_QUOTES); ?>">
                <div class="editable-actions">
                  <button type="button" class="inline-edit-btn" aria-label="Editar nome">✎</button>
                  <button type="button" class="inline-save-btn" aria-label="Salvar nome">✓</button>
                  <button type="button" class="inline-cancel-btn" aria-label="Cancelar edição">×</button>
                </div>
              </div>
              <div class="editable-feedback"></div>
            </td>
            <td>
              <div
                class="editable-box js-inline-edit"
                data-cliente-id="<?= (int)$cliente['id']; ?>"
                data-campo="telefone"
                data-original="<?= htmlspecialchars($cliente['telefone'], ENT_QUOTES); ?>"
              >
                <span class="editable-text"><?= htmlspecialchars(formatarTelefoneExibicao($cliente['telefone'])); ?></span>
                <input class="editable-input" type="text" value="<?= htmlspecialchars(formatarTelefoneExibicao($cliente['telefone']), ENT_QUOTES); ?>">
                <div class="editable-actions">
                  <button type="button" class="inline-edit-btn" aria-label="Editar telefone">✎</button>
                  <button type="button" class="inline-save-btn" aria-label="Salvar telefone">✓</button>
                  <button type="button" class="inline-cancel-btn" aria-label="Cancelar edição">×</button>
                </div>
              </div>
              <div class="editable-feedback"></div>
            </td>
            <td>
              <?php if (!empty($cliente['ultimo_agendamento'])): ?>
                <?= date('d/m/Y H:i', strtotime($cliente['ultimo_agendamento'])); ?>
              <?php else: ?>
                <span class="muted">Sem histórico</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($cliente['proximo_agendamento'])): ?>
                <?= date('d/m/Y H:i', strtotime($cliente['proximo_agendamento'])); ?>
              <?php else: ?>
                <span class="muted">Nada agendado</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($cliente['proximo_profissional'])): ?>
                <?= htmlspecialchars($cliente['proximo_profissional']); ?>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ((int)$cliente['is_recorrente'] === 1): ?>
                <span class="badge-yes">Sim</span>
              <?php else: ?>
                <span class="badge-no">Não</span>
              <?php endif; ?>
            </td>
            <td>
              <button
                type="button"
                class="whats-btn js-whatsapp-message"
                data-cliente-id="<?= (int)$cliente['id']; ?>"
                data-cliente-nome="<?= htmlspecialchars($cliente['nome'], ENT_QUOTES); ?>"
                data-cliente-telefone="<?= htmlspecialchars(formatarTelefoneExibicao($cliente['telefone']), ENT_QUOTES); ?>"
                data-cliente-whatsapp="<?= htmlspecialchars($clienteWhatsapp, ENT_QUOTES); ?>"
              >
                WhatsApp
              </button>
            </td>
            <td>
              <button
                type="button"
                class="menu-trigger js-action-trigger"
                data-cliente-id="<?= (int)$cliente['id']; ?>"
                data-cliente-nome="<?= htmlspecialchars($cliente['nome'], ENT_QUOTES); ?>"
                data-cliente-telefone="<?= htmlspecialchars(formatarTelefoneExibicao($cliente['telefone']), ENT_QUOTES); ?>"
                data-config-url="recorrencia.php?cliente_id=<?= (int)$cliente['id']; ?>"
              >⋮</button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <div class="empty">Nenhum cliente encontrado.</div>
  <?php endif; ?>
</div>

<div class="mobile-cards-wrap">
  <?php if (!empty($clientes)): ?>
    <div class="mobile-cards">
      <?php foreach ($clientes as $cliente): ?>
        <?php $clienteWhatsapp = telefoneWhatsapp($cliente['telefone']); ?>
        <div class="client-card">
          <div class="client-card-top">
            <div>
              <div
                class="editable-box js-inline-edit"
                data-cliente-id="<?= (int)$cliente['id']; ?>"
                data-campo="nome"
                data-original="<?= htmlspecialchars($cliente['nome'], ENT_QUOTES); ?>"
              >
                <span class="editable-text"><?= htmlspecialchars($cliente['nome']); ?></span>
                <input class="editable-input" type="text" value="<?= htmlspecialchars($cliente['nome'], ENT_QUOTES); ?>">
                <div class="editable-actions">
                  <button type="button" class="inline-edit-btn" aria-label="Editar nome">✎</button>
                  <button type="button" class="inline-save-btn" aria-label="Salvar nome">✓</button>
                  <button type="button" class="inline-cancel-btn" aria-label="Cancelar edição">×</button>
                </div>
              </div>
              <div class="editable-feedback"></div>

              <div
                class="editable-box js-inline-edit client-card-phone"
                data-cliente-id="<?= (int)$cliente['id']; ?>"
                data-campo="telefone"
                data-original="<?= htmlspecialchars($cliente['telefone'], ENT_QUOTES); ?>"
                style="margin-top:8px;"
              >
                <span class="editable-text"><?= htmlspecialchars(formatarTelefoneExibicao($cliente['telefone'])); ?></span>
                <input class="editable-input" type="text" value="<?= htmlspecialchars(formatarTelefoneExibicao($cliente['telefone']), ENT_QUOTES); ?>">
                <div class="editable-actions">
                  <button type="button" class="inline-edit-btn" aria-label="Editar telefone">✎</button>
                  <button type="button" class="inline-save-btn" aria-label="Salvar telefone">✓</button>
                  <button type="button" class="inline-cancel-btn" aria-label="Cancelar edição">×</button>
                </div>
              </div>
              <div class="editable-feedback"></div>
            </div>

            <button
              type="button"
              class="menu-trigger js-action-trigger"
              data-cliente-id="<?= (int)$cliente['id']; ?>"
              data-cliente-nome="<?= htmlspecialchars($cliente['nome'], ENT_QUOTES); ?>"
              data-cliente-telefone="<?= htmlspecialchars(formatarTelefoneExibicao($cliente['telefone']), ENT_QUOTES); ?>"
              data-config-url="recorrencia.php?cliente_id=<?= (int)$cliente['id']; ?>"
            >⋮</button>
          </div>

          <div class="card-grid">
            <div class="card-item">
              <small>Próximo agendamento</small>
              <span>
                <?php if (!empty($cliente['proximo_agendamento'])): ?>
                  <?= date('d/m/Y H:i', strtotime($cliente['proximo_agendamento'])); ?>
                <?php else: ?>
                  <span class="muted">Nada agendado</span>
                <?php endif; ?>
              </span>
            </div>

            <div class="card-item">
              <small>Profissional</small>
              <span>
                <?php if (!empty($cliente['proximo_profissional'])): ?>
                  <?= htmlspecialchars($cliente['proximo_profissional']); ?>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </span>
            </div>

            <div class="card-item">
              <small>Recorrente</small>
              <span>
                <?php if ((int)$cliente['is_recorrente'] === 1): ?>
                  <span class="badge-yes">Sim</span>
                <?php else: ?>
                  <span class="badge-no">Não</span>
                <?php endif; ?>
              </span>
            </div>
          </div>

          <div class="card-actions">
            <button
              type="button"
              class="card-btn whats js-whatsapp-message"
              data-cliente-id="<?= (int)$cliente['id']; ?>"
              data-cliente-nome="<?= htmlspecialchars($cliente['nome'], ENT_QUOTES); ?>"
              data-cliente-telefone="<?= htmlspecialchars(formatarTelefoneExibicao($cliente['telefone']), ENT_QUOTES); ?>"
              data-cliente-whatsapp="<?= htmlspecialchars($clienteWhatsapp, ENT_QUOTES); ?>"
            >
              WhatsApp
            </button>

            <a class="card-btn config" href="recorrencia.php?cliente_id=<?= (int)$cliente['id']; ?>">
              Recorrência
            </a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="empty">Nenhum cliente encontrado.</div>
  <?php endif; ?>
</div>


<?php if ($totalPaginas > 1): ?>
  <div class="pagination">
    <div class="pagination-info">
      Página <?= (int)$pagina; ?> de <?= (int)$totalPaginas; ?>
    </div>

    <div class="pagination-links">
      <a class="page-link <?= $pagina <= 1 ? 'disabled' : ''; ?>" href="<?= $pagina > 1 ? htmlspecialchars($montarUrlPagina($pagina - 1)) : '#'; ?>">‹</a>

      <?php
        $inicioPagina = max(1, $pagina - 2);
        $fimPagina = min($totalPaginas, $pagina + 2);

        if ($inicioPagina > 1):
      ?>
        <a class="page-link" href="<?= htmlspecialchars($montarUrlPagina(1)); ?>">1</a>
        <?php if ($inicioPagina > 2): ?>
          <span class="page-link disabled">…</span>
        <?php endif; ?>
      <?php endif; ?>

      <?php for ($p = $inicioPagina; $p <= $fimPagina; $p++): ?>
        <a class="page-link <?= $p === $pagina ? 'active' : ''; ?>" href="<?= htmlspecialchars($montarUrlPagina($p)); ?>">
          <?= (int)$p; ?>
        </a>
      <?php endfor; ?>

      <?php if ($fimPagina < $totalPaginas): ?>
        <?php if ($fimPagina < $totalPaginas - 1): ?>
          <span class="page-link disabled">…</span>
        <?php endif; ?>
        <a class="page-link" href="<?= htmlspecialchars($montarUrlPagina($totalPaginas)); ?>"><?= (int)$totalPaginas; ?></a>
      <?php endif; ?>

      <a class="page-link <?= $pagina >= $totalPaginas ? 'disabled' : ''; ?>" href="<?= $pagina < $totalPaginas ? htmlspecialchars($montarUrlPagina($pagina + 1)) : '#'; ?>">›</a>
    </div>
  </div>
<?php endif; ?>

<div id="floatingMenu" class="floating-menu">
  <button type="button" id="floatingEditBtn" class="floating-link">Editar nome e telefone</button>
  <a href="#" id="floatingConfigLink" class="floating-link" style="margin-top:8px;">Configurar recorrência</a>

  <form method="POST" class="floating-form" onsubmit="return confirm('Deseja realmente excluir este cliente?');">
    <input type="hidden" name="acao" value="excluir_cliente">
    <input type="hidden" name="cliente_id" id="floatingClienteId" value="">
    <button type="submit">Excluir cliente</button>
  </form>
</div>

<div class="modal-overlay <?= $abrirModalCriar ? 'active' : ''; ?>" id="clienteModal">
  <div class="modal">
    <div class="modal-top">
      <div>
        <h2 class="modal-title">Novo cliente</h2>
        <p class="modal-subtitle">
          Cadastre rapidamente um novo contato. Se for cliente fixo, já configure a recorrência aqui.
        </p>
      </div>
      <button type="button" class="close-btn" id="closeModalBtn">×</button>
    </div>

    <form method="POST" class="modal-form">
      <input type="hidden" name="acao" value="criar_cliente">

      <div class="form-grid">
        <div class="field full">
          <label for="nome">Nome</label>
          <input type="text" id="nome" name="nome" value="<?= htmlspecialchars($nomeForm); ?>" required>
        </div>

        <div class="field full">
          <label for="telefone">WhatsApp</label>
          <input type="text" id="telefone" name="telefone" value="<?= htmlspecialchars($telefoneForm); ?>" required>
          <div class="status-inline" id="telefoneStatus"></div>
        </div>
      </div>

      <label class="toggle-card" for="recorrente">
        <input type="checkbox" id="recorrente" name="recorrente" <?= $recorrenteForm ? 'checked' : ''; ?>>
        <div>
          <strong>Cliente recorrente</strong>
          <span>Marque se esse cliente já entra com horário fixo recorrente.</span>
        </div>
      </label>

      <div class="rec-box <?= $recorrenteForm ? 'active' : ''; ?>" id="recBox">
        <div class="form-grid">
          <div class="field">
            <label for="profissional">Profissional</label>
            <select id="profissional" name="profissional">
              <option value="">Selecione</option>
              <?php foreach ($profissionais as $prof): ?>
                <option value="<?= (int)$prof['id']; ?>" <?= (string)$profissionalForm === (string)$prof['id'] ? 'selected' : ''; ?>>
                  <?= htmlspecialchars($prof['nome']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label for="servico">Serviço</label>
            <select id="servico" name="servico">
              <option value="">Selecione</option>
              <?php foreach ($servicos as $serv): ?>
                <option value="<?= (int)$serv['id']; ?>" <?= (string)$servicoForm === (string)$serv['id'] ? 'selected' : ''; ?>>
                  <?= htmlspecialchars($serv['nome']); ?> • <?= (int)$serv['duracao']; ?> min
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label for="frequencia">Frequência</label>
            <select id="frequencia" name="frequencia">
              <option value="semanal" <?= $frequenciaForm === 'semanal' ? 'selected' : ''; ?>>Semanal</option>
              <option value="quinzenal" <?= $frequenciaForm === 'quinzenal' ? 'selected' : ''; ?>>Quinzenal</option>
              <option value="mensal" <?= $frequenciaForm === 'mensal' ? 'selected' : ''; ?>>Mensal</option>
            </select>
          </div>

          <div class="field">
            <label for="data_inicio">Data de início</label>
            <input type="date" id="data_inicio" name="data_inicio" value="<?= htmlspecialchars($dataInicioForm); ?>">
          </div>

          <div class="field full">
            <label for="hora">Horário</label>
            <input type="time" id="hora" name="hora" value="<?= htmlspecialchars($horaForm); ?>">
          </div>
        </div>
      </div>

      <div class="modal-actions">
        <button type="submit" class="modal-btn primary">Salvar cliente</button>
        <button type="button" class="modal-btn secondary" id="cancelModalBtn">Cancelar</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-overlay <?= $abrirModalEditar ? 'active' : ''; ?>" id="editarClienteModal">
  <div class="modal">
    <div class="modal-top">
      <div>
        <h2 class="modal-title">Editar nome e telefone</h2>
        <p class="modal-subtitle">
          Atualize o nome e o WhatsApp do cliente sem mexer na recorrência.
        </p>
      </div>
      <button type="button" class="close-btn" id="closeEditModalBtn">×</button>
    </div>

    <form method="POST" class="modal-form">
      <input type="hidden" name="acao" value="editar_cliente">
      <input type="hidden" name="cliente_id" id="edit_cliente_id" value="<?= htmlspecialchars($editarClienteId); ?>">

      <div class="form-grid">
        <div class="field full">
          <label for="edit_nome">Nome</label>
          <input type="text" id="edit_nome" name="nome" value="<?= htmlspecialchars($editarNomeForm); ?>" required>
        </div>

        <div class="field full">
          <label for="edit_telefone">WhatsApp</label>
          <input type="text" id="edit_telefone" name="telefone" value="<?= htmlspecialchars($editarTelefoneForm); ?>" required>
          <div class="status-inline" id="editTelefoneStatus"></div>
        </div>
      </div>

      <div class="modal-actions">
        <button type="submit" class="modal-btn primary">Salvar alterações</button>
        <button type="button" class="modal-btn secondary" id="cancelEditModalBtn">Cancelar</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-overlay whatsapp-modal" id="whatsappMessageModal">
  <div class="modal">
    <div class="modal-top">
      <div>
        <h2 class="modal-title">Mensagem automática</h2>
        <p class="modal-subtitle">
          Escolha a comunicação ideal e o WhatsApp abre com o texto pronto para envio.
        </p>
      </div>
      <button type="button" class="close-btn" id="closeWhatsappModalBtn">×</button>
    </div>

    <div class="whatsapp-client">
      <div>
        <strong id="whatsappClienteNome">Cliente</strong>
        <span id="whatsappClienteTelefone">+55 (00) 00000-0000</span>
      </div>
      <span>WhatsApp Business prioritário</span>
    </div>

    <div class="message-options" id="whatsappMessageOptions"></div>
  </div>
</div>

<script>
  const clientesData = <?= json_encode($clientesJson, JSON_UNESCAPED_UNICODE); ?>;

  const modal = document.getElementById('clienteModal');
  const openModalBtn = document.getElementById('openModalBtn');
  const closeModalBtn = document.getElementById('closeModalBtn');
  const cancelModalBtn = document.getElementById('cancelModalBtn');
  const recorrenteCheck = document.getElementById('recorrente');
  const recBox = document.getElementById('recBox');
  const telefoneInput = document.getElementById('telefone');
  const telefoneStatus = document.getElementById('telefoneStatus');

  const editModal = document.getElementById('editarClienteModal');
  const closeEditModalBtn = document.getElementById('closeEditModalBtn');
  const cancelEditModalBtn = document.getElementById('cancelEditModalBtn');
  const editClienteIdInput = document.getElementById('edit_cliente_id');
  const editNomeInput = document.getElementById('edit_nome');
  const editTelefoneInput = document.getElementById('edit_telefone');
  const editTelefoneStatus = document.getElementById('editTelefoneStatus');

  const floatingMenu = document.getElementById('floatingMenu');
  const floatingConfigLink = document.getElementById('floatingConfigLink');
  const floatingClienteId = document.getElementById('floatingClienteId');
  const floatingEditBtn = document.getElementById('floatingEditBtn');
  const whatsappMessageModal = document.getElementById('whatsappMessageModal');
  const closeWhatsappModalBtn = document.getElementById('closeWhatsappModalBtn');
  const whatsappClienteNome = document.getElementById('whatsappClienteNome');
  const whatsappClienteTelefone = document.getElementById('whatsappClienteTelefone');
  const whatsappMessageOptions = document.getElementById('whatsappMessageOptions');

  const telefonesExistentes = <?= json_encode($telefonesExistentes, JSON_UNESCAPED_UNICODE); ?>;
  const whatsappTemplates = [
    {
      id: 'pos_atendimento',
      title: '\u{2728} Obrigado pela visita',
      preview: 'Pós atendimento com pedido de avaliação no Google.',
      message: `Fala, {nome_cliente}! \u{2728}

Obrigado por escolher o Salão André Puchetti.
Foi incrível receber você hoje \u{1F64C}

Esperamos que tenha curtido a experiência.
E se puder nos ajudar, deixa sua avaliação no Google \u{1F49B}

Sua opinião fortalece muito nosso trabalho e ajuda novas pessoas a conhecerem nosso salão.

\u{2B50} Avalie aqui:
https://search.google.com/local/writereview?placeid=ChIJC0QVNFtdzpQR16hv15tj2gM`
    },
    {
      id: 'boas_vindas',
      title: '\u{1F525} Bem-vindo ao André Puchetti',
      preview: 'Primeiro contato para receber o cliente com cuidado.',
      message: `Olá, {nome_cliente}! \u{2728}

Seja muito bem-vindo ao Salão André Puchetti.
É um prazer ter você com a gente \u{1F64C}

Aqui cada detalhe é pensado pra entregar uma experiência diferenciada, desde o atendimento até o resultado final.

Qualquer dúvida, horário ou agendamento, pode chamar aqui \u{1F60A}`
    },
    {
      id: 'confirmacao_agendamento',
      title: '\u{1F4C5} Agendamento confirmado',
      preview: 'Confirma o horário e orienta em caso de imprevisto.',
      message: `Fala, {nome_cliente}! \u{2728}

Seu horário foi confirmado com sucesso no Salão André Puchetti \u{1F64C}

Estamos te esperando no dia e horário agendados.
Qualquer imprevisto ou necessidade de alteração, é só chamar aqui no WhatsApp \u{1F60A}

Vai ser um prazer receber você \u{1F525}`
    }
  ];

  let currentFloatingClientId = null;
  let currentWhatsappClient = null;

  function abrirModal() {
    modal.classList.add('active');
  }

  function fecharModal() {
    modal.classList.remove('active');
  }

  function abrirEditModal(clienteId, nome, telefone) {
    editClienteIdInput.value = clienteId;
    editNomeInput.value = nome;
    editTelefoneInput.value = telefone;
    verificarDuplicataTelefoneEdicao();
    editModal.classList.add('active');
  }

  function fecharEditModal() {
    editModal.classList.remove('active');
  }

  function toggleRecorrencia() {
    recBox.classList.toggle('active', recorrenteCheck.checked);
  }

  function limparNumeroJS(valor) {
    return valor.replace(/\D+/g, '');
  }

  function normalizePhoneJS(value) {
    let digits = String(value || '').replace(/\D+/g, '');

    while (digits.length > 13 && digits.slice(0, 4) === '5555') {
      digits = digits.slice(2);
    }

    let national = '';

    if (digits.slice(0, 2) === '55') {
      national = digits.slice(2);
    } else if (digits.length === 10 || digits.length === 11) {
      national = digits;
      digits = '55' + digits;
    }

    if (!national || ![10, 11].includes(national.length)) {
      return { valid: false, displayPhone: value, whatsappPhone: '' };
    }

    const ddd = national.slice(0, 2);
    const subscriber = national.slice(2);
    const formattedSubscriber = subscriber.length === 9
      ? `${subscriber.slice(0, 5)}-${subscriber.slice(5)}`
      : `${subscriber.slice(0, 4)}-${subscriber.slice(4)}`;

    return {
      valid: true,
      displayPhone: `+55 (${ddd}) ${formattedSubscriber}`,
      whatsappPhone: `55${national}`
    };
  }

  function normalizarComparacao(valor) {
    return normalizePhoneJS(valor).whatsappPhone;
  }

  function verificarDuplicataTelefone() {
    const normalizado = normalizePhoneJS(telefoneInput.value);
    const comparavel = normalizado.whatsappPhone;
    telefoneStatus.className = 'status-inline';
    telefoneStatus.textContent = '';

    if (!limparNumeroJS(telefoneInput.value)) return;

    if (!normalizado.valid) {
      telefoneStatus.classList.add('show', 'warn');
      telefoneStatus.textContent = 'Número inválido';
      return;
    }

    if (telefonesExistentes.includes(comparavel)) {
      telefoneStatus.classList.add('show', 'warn');
      telefoneStatus.textContent = 'WhatsApp já cadastrado';
    } else {
      telefoneStatus.classList.add('show', 'ok');
      telefoneStatus.textContent = 'Cliente novo';
    }
  }

  function verificarDuplicataTelefoneEdicao() {
    const normalizado = normalizePhoneJS(editTelefoneInput.value);
    const comparavel = normalizado.whatsappPhone;
    editTelefoneStatus.className = 'status-inline';
    editTelefoneStatus.textContent = '';

    if (!limparNumeroJS(editTelefoneInput.value)) return;

    if (!normalizado.valid) {
      editTelefoneStatus.classList.add('show', 'warn');
      editTelefoneStatus.textContent = 'Número inválido';
      return;
    }

    const clienteAtual = clientesData.find(c => String(c.id) === String(editClienteIdInput.value));
    const telefoneAtual = clienteAtual ? normalizarComparacao(clienteAtual.telefone) : '';

    const existeOutro = telefonesExistentes.includes(comparavel) && comparavel !== telefoneAtual;

    if (existeOutro) {
      editTelefoneStatus.classList.add('show', 'warn');
      editTelefoneStatus.textContent = 'WhatsApp já usado por outro cliente';
    } else {
      editTelefoneStatus.classList.add('show', 'ok');
      editTelefoneStatus.textContent = 'Número disponível';
    }
  }

  function closeFloatingMenu() {
    floatingMenu.classList.remove('open');
    currentFloatingClientId = null;
  }

  function openFloatingMenu(trigger) {
    const rect = trigger.getBoundingClientRect();
    const configUrl = trigger.dataset.configUrl || '#';
    const clienteId = trigger.dataset.clienteId || '';
    const clienteNome = trigger.dataset.clienteNome || '';
    const clienteTelefone = trigger.dataset.clienteTelefone || '';

    currentFloatingClientId = clienteId;

    floatingConfigLink.href = configUrl;
    floatingClienteId.value = clienteId;

    floatingEditBtn.onclick = function() {
      closeFloatingMenu();
      abrirEditModal(clienteId, clienteNome, clienteTelefone);
    };

    floatingMenu.style.top = (rect.bottom + 8) + 'px';
    floatingMenu.style.left = Math.max(12, rect.right - 220) + 'px';

    floatingMenu.classList.add('open');

    requestAnimationFrame(() => {
      const menuRect = floatingMenu.getBoundingClientRect();

      if (menuRect.right > window.innerWidth - 12) {
        floatingMenu.style.left = (window.innerWidth - menuRect.width - 12) + 'px';
      }

      if (menuRect.bottom > window.innerHeight - 12) {
        floatingMenu.style.top = Math.max(12, rect.top - menuRect.height - 8) + 'px';
      }
    });
  }

  document.querySelectorAll('.js-action-trigger').forEach(btn => {
    btn.addEventListener('click', function(e) {
      e.stopPropagation();

      const sameClientOpen = floatingMenu.classList.contains('open') && currentFloatingClientId === this.dataset.clienteId;
      closeFloatingMenu();

      if (!sameClientOpen) {
        openFloatingMenu(this);
      }
    });
  });

  document.addEventListener('click', function(e) {
    if (!e.target.closest('#floatingMenu')) {
      closeFloatingMenu();
    }
  });

  window.addEventListener('resize', closeFloatingMenu);
  window.addEventListener('scroll', closeFloatingMenu, true);

  openModalBtn.addEventListener('click', abrirModal);
  closeModalBtn.addEventListener('click', fecharModal);
  cancelModalBtn.addEventListener('click', fecharModal);

  closeEditModalBtn.addEventListener('click', fecharEditModal);
  cancelEditModalBtn.addEventListener('click', fecharEditModal);
  closeWhatsappModalBtn.addEventListener('click', fecharWhatsappModal);

  modal.addEventListener('click', function(e) {
    if (e.target === modal) {
      fecharModal();
    }
  });

  editModal.addEventListener('click', function(e) {
    if (e.target === editModal) {
      fecharEditModal();
    }
  });

  whatsappMessageModal.addEventListener('click', function(e) {
    if (e.target === whatsappMessageModal) {
      fecharWhatsappModal();
    }
  });

  recorrenteCheck.addEventListener('change', toggleRecorrencia);
  telefoneInput.addEventListener('input', verificarDuplicataTelefone);
  editTelefoneInput.addEventListener('input', verificarDuplicataTelefoneEdicao);

  function setInlineFeedback(wrapper, message, type) {
    const feedback = wrapper.nextElementSibling && wrapper.nextElementSibling.classList.contains('editable-feedback')
      ? wrapper.nextElementSibling
      : null;

    if (!feedback) return;

    if (!message) {
      feedback.className = 'editable-feedback';
      feedback.textContent = '';
      return;
    }

    feedback.className = 'editable-feedback show ' + (type || '');
    feedback.textContent = message;

    if (type === 'ok') {
      setTimeout(() => {
        feedback.className = 'editable-feedback';
        feedback.textContent = '';
      }, 1800);
    }
  }

  function startInlineEdit(wrapper) {
    const input = wrapper.querySelector('.editable-input');
    wrapper.classList.add('editing');
    if (input) {
      input.focus();
      input.select();
    }
  }

  function formatPhoneForDisplay(value) {
    return normalizePhoneJS(value).displayPhone;
  }

  function fecharWhatsappModal() {
    whatsappMessageModal.classList.remove('active');
    currentWhatsappClient = null;
  }

  function renderWhatsappOptions() {
    whatsappMessageOptions.innerHTML = '';

    whatsappTemplates.forEach(template => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'message-option';
      button.dataset.templateId = template.id;
      button.innerHTML = `<span class="message-icon" aria-hidden="true">${getWhatsappTemplateIcon(template.id)}</span><span class="message-copy"><strong>${template.title}</strong><span>${template.preview}</span></span>`;
      whatsappMessageOptions.appendChild(button);
    });
  }

  function getWhatsappTemplateIcon(templateId) {
    const icons = {
      pos_atendimento: '<svg viewBox="0 0 24 24"><path d="M12 3l2.2 4.5 4.9.7-3.5 3.4.8 4.8L12 14.1 7.6 16.4l.8-4.8-3.5-3.4 4.9-.7L12 3Z"/><path d="M4 21h16"/></svg>',
      boas_vindas: '<svg viewBox="0 0 24 24"><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-7"/><path d="M2 7h20v5H2Z"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 1 1 2.1-3.8C10.4 4.4 12 7 12 7Z"/><path d="M12 7h4.5a2.5 2.5 0 1 0-2.1-3.8C13.6 4.4 12 7 12 7Z"/></svg>',
      confirmacao_agendamento: '<svg viewBox="0 0 24 24"><path d="M8 2v4"/><path d="M16 2v4"/><path d="M3 10h18"/><path d="M5 4h14a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z"/><path d="m9 16 2 2 4-5"/></svg>'
    };

    return icons[templateId] || icons.boas_vindas;
  }

  function abrirWhatsappModal(trigger) {
    const phone = normalizePhoneJS(trigger.dataset.clienteWhatsapp || trigger.dataset.clienteTelefone || '');

    if (!phone.valid) {
      alert('Este cliente está com WhatsApp inválido. Corrija o telefone antes de enviar mensagem.');
      return;
    }

    currentWhatsappClient = {
      id: trigger.dataset.clienteId || '',
      nome: trigger.dataset.clienteNome || 'cliente',
      displayPhone: phone.displayPhone,
      whatsappPhone: phone.whatsappPhone
    };

    whatsappClienteNome.textContent = currentWhatsappClient.nome;
    whatsappClienteTelefone.textContent = currentWhatsappClient.displayPhone;
    whatsappMessageModal.classList.add('active');
  }

  function enviarMensagemWhatsapp(templateId) {
    if (!currentWhatsappClient) return;

    const template = whatsappTemplates.find(item => item.id === templateId);
    if (!template) return;

    const message = template.message.replaceAll('{nome_cliente}', currentWhatsappClient.nome);
    const whatsappPhone = currentWhatsappClient.whatsappPhone;
    fecharWhatsappModal();

    if (window.openAdminWhatsapp) {
      window.openAdminWhatsapp(whatsappPhone, message);
      return;
    }

    window.open(`https://wa.me/${whatsappPhone}?text=${encodeURIComponent(message)}`, '_blank', 'noopener');
  }

  function cancelInlineEdit(wrapper) {
    const input = wrapper.querySelector('.editable-input');
    const original = wrapper.dataset.original || '';
    const campo = wrapper.dataset.campo || '';

    if (input) {
      input.value = campo === 'telefone' ? formatPhoneForDisplay(original) : original;
    }

    wrapper.classList.remove('editing');
    setInlineFeedback(wrapper, '', '');
  }

  async function saveInlineEdit(wrapper) {
    const input = wrapper.querySelector('.editable-input');
    const text = wrapper.querySelector('.editable-text');
    const clienteId = wrapper.dataset.clienteId || '';
    const campo = wrapper.dataset.campo || '';
    const valor = input ? input.value.trim() : '';
    const valorAnterior = wrapper.dataset.original || '';

    if (!clienteId || !campo || !valor) {
      setInlineFeedback(wrapper, 'Preencha o campo antes de salvar.', 'error');
      return;
    }

    setInlineFeedback(wrapper, 'Salvando...', '');

    const formData = new URLSearchParams();
    formData.append('acao', 'editar_cliente_inline');
    formData.append('cliente_id', clienteId);
    formData.append('campo', campo);
    formData.append('valor', valor);

    try {
      const response = await fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData.toString()
      });

      const json = await response.json();

      if (!response.ok || !json.ok) {
        setInlineFeedback(wrapper, json.message || 'Não foi possível salvar.', 'error');
        return;
      }

      if (text) text.textContent = json.valor_formatado || valor;
      if (input) input.value = json.valor_formatado || valor;

      wrapper.dataset.original = json.valor || valor;
      wrapper.classList.remove('editing');
      setInlineFeedback(wrapper, json.message || 'Atualizado com sucesso.', 'ok');

      if (campo === 'nome') {
        const clienteAtual = clientesData.find(c => String(c.id) === String(clienteId));
        if (clienteAtual) clienteAtual.nome = json.valor || valor;

        document.querySelectorAll(`.js-action-trigger[data-cliente-id="${clienteId}"]`).forEach(btn => {
          btn.dataset.clienteNome = json.valor || valor;
        });
        document.querySelectorAll(`.js-whatsapp-message[data-cliente-id="${clienteId}"]`).forEach(btn => {
          btn.dataset.clienteNome = json.valor || valor;
        });
      }

      if (campo === 'telefone') {
        const telefoneAnterior = normalizePhoneJS(valorAnterior).whatsappPhone;
        const telefoneNovo = json.whatsapp || '';
        const telefoneAnteriorIndex = telefonesExistentes.indexOf(telefoneAnterior);

        if (telefoneAnteriorIndex >= 0) telefonesExistentes.splice(telefoneAnteriorIndex, 1);
        if (telefoneNovo && !telefonesExistentes.includes(telefoneNovo)) telefonesExistentes.push(telefoneNovo);

        const clienteAtual = clientesData.find(c => String(c.id) === String(clienteId));
        if (clienteAtual) {
          clienteAtual.telefone = json.valor || valor;
          clienteAtual.telefone_formatado = json.valor_formatado || json.valor || valor;
          clienteAtual.whatsapp_phone = telefoneNovo;
        }

        document.querySelectorAll(`.js-action-trigger[data-cliente-id="${clienteId}"]`).forEach(btn => {
          btn.dataset.clienteTelefone = json.valor || valor;
        });

        document.querySelectorAll(`.js-whatsapp-message[data-cliente-id="${clienteId}"]`).forEach(btn => {
          btn.dataset.clienteTelefone = json.valor_formatado || json.valor || valor;
          btn.dataset.clienteWhatsapp = json.whatsapp || '';
        });
      }
    } catch (error) {
      setInlineFeedback(wrapper, 'Erro ao salvar. Tente novamente.', 'error');
    }
  }

  document.querySelectorAll('.js-inline-edit').forEach(wrapper => {
    const editBtn = wrapper.querySelector('.inline-edit-btn');
    const saveBtn = wrapper.querySelector('.inline-save-btn');
    const cancelBtn = wrapper.querySelector('.inline-cancel-btn');
    const input = wrapper.querySelector('.editable-input');

    if (editBtn) {
      editBtn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        startInlineEdit(wrapper);
      });
    }

    if (saveBtn) {
      saveBtn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        saveInlineEdit(wrapper);
      });
    }

    if (cancelBtn) {
      cancelBtn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        cancelInlineEdit(wrapper);
      });
    }

    if (input) {
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          saveInlineEdit(wrapper);
        }

        if (e.key === 'Escape') {
          e.preventDefault();
          cancelInlineEdit(wrapper);
        }
      });
    }
  });

  document.querySelectorAll('.js-whatsapp-message').forEach(btn => {
    btn.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      abrirWhatsappModal(this);
    });
  });

  whatsappMessageOptions.addEventListener('click', function(e) {
    const option = e.target.closest('.message-option');
    if (!option) return;

    enviarMensagemWhatsapp(option.dataset.templateId);
  });

  renderWhatsappOptions();
  toggleRecorrencia();
  verificarDuplicataTelefone();

  if (editClienteIdInput.value) {
    verificarDuplicataTelefoneEdicao();
  }
</script>
<?php admin_shell_end(); ?>
