<?php
require_once '../config.php';
require_once '../includes/auth.php';

date_default_timezone_set('America/Sao_Paulo');

function redirectAgendaVisual(string $data, string $profissionalId, string $flash, string $msg): void
{
    header(
        'Location: agenda-visual.php?data=' . urlencode($data) .
        '&profissional_id=' . urlencode($profissionalId) .
        '&flash=' . urlencode($flash) .
        '&msg=' . urlencode($msg)
    );
    exit;
}

function timeToMinutes(string $time): int
{
    $time = substr($time, 0, 5);
    [$h, $m] = explode(':', $time);
    return ((int)$h * 60) + (int)$m;
}

function minutesToTime(int $minutes): string
{
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;

    return str_pad((string)$hours, 2, '0', STR_PAD_LEFT) . ':' .
           str_pad((string)$mins, 2, '0', STR_PAD_LEFT) . ':00';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectAgendaVisual(date('Y-m-d'), 'todos', 'erro', 'Requisição inválida.');
}

$id = (int)($_POST['id'] ?? 0);
$novaData = trim($_POST['data'] ?? '');
$novaHora = trim($_POST['hora'] ?? '');
$novaHoraFim = trim($_POST['hora_fim'] ?? '');
$returnData = trim($_POST['return_data'] ?? $novaData);
$returnProfissionalId = trim($_POST['return_profissional_id'] ?? 'todos');

if ($id <= 0 || $novaData === '' || $novaHora === '' || $novaHoraFim === '') {
    redirectAgendaVisual($returnData, $returnProfissionalId, 'erro', 'Preencha data, hora inicial e hora final para remarcar.');
}

$validDate = DateTime::createFromFormat('Y-m-d', $novaData);
$validTime = DateTime::createFromFormat('H:i', $novaHora);
$validTimeEnd = DateTime::createFromFormat('H:i', $novaHoraFim);

if (
    !$validDate || $validDate->format('Y-m-d') !== $novaData ||
    !$validTime || $validTime->format('H:i') !== $novaHora ||
    !$validTimeEnd || $validTimeEnd->format('H:i') !== $novaHoraFim
) {
    redirectAgendaVisual($returnData, $returnProfissionalId, 'erro', 'Data ou horário inválidos para a remarcação.');
}

$inicioMin = timeToMinutes($novaHora);
$fimMin = timeToMinutes($novaHoraFim);

if ($fimMin <= $inicioMin) {
    redirectAgendaVisual($returnData, $returnProfissionalId, 'erro', 'A hora final precisa ser maior que a hora inicial.');
}

if ($inicioMin < (7 * 60) || $fimMin > (22 * 60)) {
    redirectAgendaVisual($returnData, $returnProfissionalId, 'erro', 'O horário precisa estar dentro do intervalo da agenda.');
}

$agendamentoStmt = $conn->prepare("
    SELECT
        ag.id,
        ag.profissional_id,
        ag.servico_id,
        ag.status,
        c.nome AS cliente_nome,
        s.nome AS servico_nome,
        s.duracao AS servico_duracao
    FROM agendamentos ag
    INNER JOIN clientes c ON c.id = ag.cliente_id
    INNER JOIN servicos s ON s.id = ag.servico_id
    WHERE ag.id = ?
    LIMIT 1
");
$agendamentoStmt->bind_param('i', $id);
$agendamentoStmt->execute();
$agendamento = $agendamentoStmt->get_result()->fetch_assoc();

if (!$agendamento) {
    redirectAgendaVisual($returnData, $returnProfissionalId, 'erro', 'Agendamento não encontrado.');
}

$profissionalId = (int)$agendamento['profissional_id'];

if (!function_exists('podeEditarProfissional') || !podeEditarProfissional($profissionalId)) {
    redirectAgendaVisual($returnData, $returnProfissionalId, 'erro', 'Você não tem permissão para editar este profissional.');
}

if ($agendamento['status'] === 'cancelado') {
    redirectAgendaVisual($returnData, $returnProfissionalId, 'erro', 'Não é possível remarcar um agendamento cancelado.');
}

$now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
$bookingDateTime = DateTime::createFromFormat('Y-m-d H:i', $novaData . ' ' . $novaHora, new DateTimeZone('America/Sao_Paulo'));

if (!$bookingDateTime || $bookingDateTime < $now) {
    redirectAgendaVisual($returnData, $returnProfissionalId, 'erro', 'Não é possível remarcar para um horário que já passou.');
}

$horaInicioBanco = minutesToTime($inicioMin);
$horaFimBanco = minutesToTime($fimMin);

$stmtBloqueio = $conn->prepare(
    'SELECT id
     FROM bloqueios
     WHERE profissional_id = ?
       AND data = ?
       AND hora_inicio < ?
       AND hora_fim > ?
     LIMIT 1'
);
$stmtBloqueio->bind_param('isss', $profissionalId, $novaData, $horaFimBanco, $horaInicioBanco);
$stmtBloqueio->execute();
$bloqueio = $stmtBloqueio->get_result()->fetch_assoc();

if ($bloqueio) {
    redirectAgendaVisual($returnData, $returnProfissionalId, 'erro', 'Esse novo horário está bloqueado para este profissional.');
}

$stmtConflito = $conn->prepare(
    "SELECT ag.id, ag.hora, ag.hora_fim, s.duracao AS servico_duracao
     FROM agendamentos ag
     INNER JOIN servicos s ON s.id = ag.servico_id
     WHERE ag.profissional_id = ?
       AND ag.data = ?
       AND ag.status IN ('confirmado', 'pendente')
       AND ag.id <> ?"
);
$stmtConflito->bind_param('isi', $profissionalId, $novaData, $id);
$stmtConflito->execute();
$resConflito = $stmtConflito->get_result();

while ($ag = $resConflito->fetch_assoc()) {
    $agInicio = timeToMinutes($ag['hora']);
    $agFim = !empty($ag['hora_fim'])
        ? timeToMinutes($ag['hora_fim'])
        : ($agInicio + max(15, (int)($ag['servico_duracao'] ?? 30)));

    $hasConflict = ($inicioMin < $agFim) && ($fimMin > $agInicio);

    if ($hasConflict) {
        redirectAgendaVisual($returnData, $returnProfissionalId, 'erro', 'Já existe outro agendamento conflitante nesse novo período.');
    }
}

$stmtUpdate = $conn->prepare("
    UPDATE agendamentos
    SET data = ?, hora = ?, hora_fim = ?
    WHERE id = ?
");
$stmtUpdate->bind_param('sssi', $novaData, $horaInicioBanco, $horaFimBanco, $id);

if (!$stmtUpdate->execute()) {
    redirectAgendaVisual($returnData, $returnProfissionalId, 'erro', 'Não foi possível salvar a remarcação agora.');
}

redirectAgendaVisual(
    $novaData,
    (string)$profissionalId,
    'sucesso',
    'Agendamento de ' . $agendamento['cliente_nome'] . ' remarcado com sucesso.'
);
