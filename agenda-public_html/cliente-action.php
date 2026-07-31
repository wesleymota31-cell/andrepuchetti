<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/client-auth.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/phone.php';

date_default_timezone_set('America/Sao_Paulo');

function ca_redirect(string $flash, string $msg): void
{
    header('Location: cliente.php?flash=' . urlencode($flash) . '&msg=' . urlencode($msg));
    exit;
}

function ca_time_to_minutes(string $time): int
{
    $time = substr($time, 0, 5);
    [$h, $m] = explode(':', $time);
    return ((int)$h * 60) + (int)$m;
}

function ca_minutes_to_sql(int $minutes): string
{
    return str_pad((string)floor($minutes / 60), 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)($minutes % 60), 2, '0', STR_PAD_LEFT) . ':00';
}

function ca_date_br(string $date): string
{
    return date('d/m/Y', strtotime($date));
}

function ca_hour_br(string $time): string
{
    $minutes = ca_time_to_minutes($time);
    $h = floor($minutes / 60);
    $m = $minutes % 60;
    return $m === 0 ? $h . 'h' : $h . 'h' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
}

function ca_valid_date(string $date): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt && $dt->format('Y-m-d') === $date && (int)$dt->format('w') !== 0;
}

function ca_conflict_exists(mysqli $conn, int $profissionalId, string $data, string $inicio, string $fim, int $ignoreId = 0): bool
{
    $stmt = $conn->prepare("
        SELECT ag.id, ag.hora, ag.hora_fim, s.duracao
        FROM agendamentos ag
        INNER JOIN servicos s ON s.id = ag.servico_id
        WHERE ag.profissional_id = ?
          AND ag.data = ?
          AND ag.status IN ('confirmado', 'pendente')
          AND ag.id <> ?
    ");
    $stmt->bind_param('isi', $profissionalId, $data, $ignoreId);
    $stmt->execute();
    $res = $stmt->get_result();
    $iniMin = ca_time_to_minutes($inicio);
    $fimMin = ca_time_to_minutes($fim);

    while ($row = $res->fetch_assoc()) {
        $agIni = ca_time_to_minutes($row['hora']);
        $agFim = !empty($row['hora_fim']) ? ca_time_to_minutes($row['hora_fim']) : $agIni + max(15, (int)$row['duracao']);
        if ($iniMin < $agFim && $fimMin > $agIni) {
            return true;
        }
    }

    $stmtBl = $conn->prepare("SELECT id FROM bloqueios WHERE profissional_id = ? AND data = ? AND hora_inicio < ? AND hora_fim > ? LIMIT 1");
    $stmtBl->bind_param('isss', $profissionalId, $data, $fim, $inicio);
    $stmtBl->execute();
    if ($stmtBl->get_result()->fetch_assoc()) {
        return true;
    }

    $weekday = (string)date('N', strtotime($data));
    $stmtRec = $conn->prepare("
        SELECT id FROM bloqueios_recorrentes
        WHERE profissional_id = ?
          AND ativo = 1
          AND data_inicio <= ?
          AND (data_fim IS NULL OR data_fim >= ?)
          AND FIND_IN_SET(?, dias_semana)
          AND hora_inicio < ?
          AND hora_fim > ?
        LIMIT 1
    ");
    $stmtRec->bind_param('isssss', $profissionalId, $data, $data, $weekday, $fim, $inicio);
    $stmtRec->execute();
    return (bool)$stmtRec->get_result()->fetch_assoc();
}

function ca_booking_payload(mysqli $conn, int $agendamentoId): ?array
{
    $stmt = $conn->prepare("
        SELECT ag.data, ag.hora, c.nome, c.email, p.nome AS profissional, s.nome AS servico
        FROM agendamentos ag
        INNER JOIN clientes c ON c.id = ag.cliente_id
        INNER JOIN profissionais p ON p.id = ag.profissional_id
        INNER JOIN servicos s ON s.id = ag.servico_id
        WHERE ag.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $agendamentoId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) return null;
    return [
        'to' => $row['email'],
        'name' => $row['nome'],
        'booking' => [
            'profissional' => $row['profissional'],
            'servico' => $row['servico'],
            'data' => ca_date_br($row['data']),
            'hora' => ca_hour_br($row['hora']),
        ],
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_validate($_POST['csrf_token'] ?? null)) {
    ca_redirect('erro', 'Ação expirada. Tente novamente.');
}

$cliente = exigir_cliente($conn);
$action = $_POST['action'] ?? '';

if ($action === 'profile') {
    $nome = trim($_POST['nome'] ?? '');
    $email = normalize_email($_POST['email'] ?? '');
    $telefoneNormalizado = normalizarTelefoneCliente($_POST['telefone'] ?? '');
    $telefone = $telefoneNormalizado['valid'] ? $telefoneNormalizado['display_phone'] : '';
    $senha = $_POST['senha'] ?? '';

    if ($nome === '' || $telefone === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ca_redirect('erro', 'Preencha nome, WhatsApp e e-mail corretamente.');
    }

    $stmtEmail = $conn->prepare("SELECT id FROM clientes WHERE email = ? AND id <> ? LIMIT 1");
    $stmtEmail->bind_param('si', $email, $cliente['id']);
    $stmtEmail->execute();
    if ($stmtEmail->get_result()->fetch_assoc()) {
        ca_redirect('erro', 'Esse e-mail já está em uso por outro cliente.');
    }

    if ($senha !== '') {
        if (strlen($senha) < 6) {
            ca_redirect('erro', 'A nova senha precisa ter pelo menos 6 caracteres.');
        }
        $hash = password_hash($senha, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE clientes SET nome = ?, telefone = ?, email = ?, senha = ? WHERE id = ? LIMIT 1");
        $stmt->bind_param('ssssi', $nome, $telefone, $email, $hash, $cliente['id']);
    } else {
        $stmt = $conn->prepare("UPDATE clientes SET nome = ?, telefone = ?, email = ? WHERE id = ? LIMIT 1");
        $stmt->bind_param('sssi', $nome, $telefone, $email, $cliente['id']);
    }

    $stmt->execute();
    $_SESSION['cliente_nome'] = $nome;
    $_SESSION['cliente_email'] = $email;
    ca_redirect('sucesso', 'Perfil atualizado com sucesso.');
}

if ($action === 'cancel') {
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $conn->prepare("SELECT id FROM agendamentos WHERE id = ? AND cliente_id = ? AND status <> 'cancelado' LIMIT 1");
    $stmt->bind_param('ii', $id, $cliente['id']);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) {
        ca_redirect('erro', 'Agendamento não encontrado.');
    }
    $emailData = ca_booking_payload($conn, $id);
    $stmtUp = $conn->prepare("UPDATE agendamentos SET status = 'cancelado' WHERE id = ? AND cliente_id = ? LIMIT 1");
    $stmtUp->bind_param('ii', $id, $cliente['id']);
    $stmtUp->execute();
    if ($emailData && !empty($emailData['to'])) {
        agenda_send_booking_email($emailData['to'], $emailData['name'], $emailData['booking'], 'cancelled');
    }
    ca_redirect('sucesso', 'Agendamento cancelado com sucesso.');
}

if ($action === 'book' || $action === 'reschedule') {
    $profissionalId = (int)($_POST['profissional_id'] ?? 0);
    $servicoId = (int)($_POST['servico_id'] ?? 0);
    $data = trim($_POST['data'] ?? '');
    $hora = trim($_POST['hora'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($profissionalId <= 0 || $servicoId <= 0 || !ca_valid_date($data) || !preg_match('/^\d{2}:\d{2}$/', $hora)) {
        ca_redirect('erro', 'Escolha serviço, data e horário válidos.');
    }

    $bookingDateTime = DateTime::createFromFormat('Y-m-d H:i', $data . ' ' . $hora, new DateTimeZone('America/Sao_Paulo'));
    if (!$bookingDateTime || $bookingDateTime < new DateTime('now', new DateTimeZone('America/Sao_Paulo'))) {
        ca_redirect('erro', 'Escolha uma data e horário futuros.');
    }

    $stmtServico = $conn->prepare("SELECT id, nome, duracao FROM servicos WHERE id = ? LIMIT 1");
    $stmtServico->bind_param('i', $servicoId);
    $stmtServico->execute();
    $servico = $stmtServico->get_result()->fetch_assoc();
    if (!$servico) {
        ca_redirect('erro', 'Serviço não encontrado.');
    }

    $inicio = $hora . ':00';
    $fim = ca_minutes_to_sql(ca_time_to_minutes($hora) + max(5, (int)$servico['duracao']));
    if (ca_time_to_minutes($inicio) < 9 * 60 || ca_time_to_minutes($fim) > 20 * 60) {
        ca_redirect('erro', 'Escolha um horário dentro do expediente.');
    }

    if (ca_conflict_exists($conn, $profissionalId, $data, $inicio, $fim, $action === 'reschedule' ? $id : 0)) {
        ca_redirect('erro', 'Esse horário não está mais disponível.');
    }

    if ($action === 'reschedule') {
        $stmtCheck = $conn->prepare("SELECT id FROM agendamentos WHERE id = ? AND cliente_id = ? AND status <> 'cancelado' LIMIT 1");
        $stmtCheck->bind_param('ii', $id, $cliente['id']);
        $stmtCheck->execute();
        if (!$stmtCheck->get_result()->fetch_assoc()) {
            ca_redirect('erro', 'Agendamento não encontrado.');
        }
        $stmtUp = $conn->prepare("UPDATE agendamentos SET profissional_id = ?, servico_id = ?, data = ?, hora = ?, hora_fim = ?, status = 'confirmado' WHERE id = ? AND cliente_id = ? LIMIT 1");
        $stmtUp->bind_param('iisssii', $profissionalId, $servicoId, $data, $inicio, $fim, $id, $cliente['id']);
        $stmtUp->execute();
        $emailData = ca_booking_payload($conn, $id);
        if ($emailData && !empty($emailData['to'])) {
            agenda_send_booking_email($emailData['to'], $emailData['name'], $emailData['booking'], 'rescheduled');
        }
        ca_redirect('sucesso', 'Agendamento remarcado com sucesso.');
    }

    $status = 'confirmado';
    $stmtIns = $conn->prepare("INSERT INTO agendamentos (cliente_id, profissional_id, servico_id, data, hora, hora_fim, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmtIns->bind_param('iiissss', $cliente['id'], $profissionalId, $servicoId, $data, $inicio, $fim, $status);
    $stmtIns->execute();
    $novoId = (int)$stmtIns->insert_id;
    $emailData = ca_booking_payload($conn, $novoId);
    if ($emailData && !empty($emailData['to'])) {
        agenda_send_booking_email($emailData['to'], $emailData['name'], $emailData['booking'], 'created');
    }
    ca_redirect('sucesso', 'Agendamento criado com sucesso.');
}

ca_redirect('erro', 'Ação inválida.');
