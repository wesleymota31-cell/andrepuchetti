<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/radar.php';

date_default_timezone_set('America/Sao_Paulo');
radar_ensure_schema($conn);

function radar_redirect_action(string $flash, string $msg): void
{
    $back = $_POST['return_to'] ?? $_SERVER['HTTP_REFERER'] ?? 'index.php';
    $sep = str_contains($back, '?') ? '&' : '?';
    header('Location: ' . $back . $sep . 'flash=' . urlencode($flash) . '&msg=' . urlencode($msg));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_validate($_POST['csrf_token'] ?? null)) {
    radar_redirect_action('erro', 'Ação expirada. Tente novamente.');
}

$id = (int)($_POST['id'] ?? 0);
$action = $_POST['radar_action'] ?? '';

$stmt = $conn->prepare("SELECT id, profissional_id FROM radar_retornos WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$item = $stmt->get_result()->fetch_assoc();

if (!$item || !podeEditarProfissional((int)$item['profissional_id'])) {
    radar_redirect_action('erro', 'Você não tem permissão para alterar este retorno.');
}

if ($action === 'contatado') {
    $stmtUp = $conn->prepare("UPDATE radar_retornos SET estado_manual = 'contatado', ultimo_contato_em = NOW(), tentativas = tentativas + 1 WHERE id = ? LIMIT 1");
    $stmtUp->bind_param('i', $id);
    $stmtUp->execute();
    radar_redirect_action('sucesso', 'Contato registrado.');
}

if ($action === 'aguardando') {
    $stmtUp = $conn->prepare("UPDATE radar_retornos SET estado_manual = 'aguardando_resposta', ultimo_contato_em = COALESCE(ultimo_contato_em, NOW()) WHERE id = ? LIMIT 1");
    $stmtUp->bind_param('i', $id);
    $stmtUp->execute();
    radar_redirect_action('sucesso', 'Cliente marcado como aguardando resposta.');
}

if ($action === 'lembrar') {
    $days = max(1, min(30, (int)($_POST['dias'] ?? 1)));
    $date = date('Y-m-d', strtotime('+' . $days . ' days'));
    $stmtUp = $conn->prepare("UPDATE radar_retornos SET estado_manual = 'adiado', adiado_para = ? WHERE id = ? LIMIT 1");
    $stmtUp->bind_param('si', $date, $id);
    $stmtUp->execute();
    radar_redirect_action('sucesso', 'Lembrete adiado.');
}

if ($action === 'escolher_data') {
    $date = trim($_POST['data'] ?? '');
    $valid = DateTime::createFromFormat('Y-m-d', $date);
    if (!$valid || $valid->format('Y-m-d') !== $date) {
        radar_redirect_action('erro', 'Escolha uma data válida.');
    }
    $stmtUp = $conn->prepare("UPDATE radar_retornos SET estado_manual = 'adiado', adiado_para = ? WHERE id = ? LIMIT 1");
    $stmtUp->bind_param('si', $date, $id);
    $stmtUp->execute();
    radar_redirect_action('sucesso', 'Retorno reagendado no Radar.');
}

if ($action === 'ignorar') {
    $date = date('Y-m-d', strtotime('+45 days'));
    $stmtUp = $conn->prepare("UPDATE radar_retornos SET estado_manual = 'ignorado', ignorado_ate = ? WHERE id = ? LIMIT 1");
    $stmtUp->bind_param('si', $date, $id);
    $stmtUp->execute();
    radar_redirect_action('sucesso', 'Cliente ignorado neste ciclo.');
}

if ($action === 'desativar') {
    $stmtUp = $conn->prepare("UPDATE radar_retornos SET estado_manual = 'desativado', lembretes_ativos = 0 WHERE id = ? LIMIT 1");
    $stmtUp->bind_param('i', $id);
    $stmtUp->execute();
    radar_redirect_action('sucesso', 'Lembretes desativados para este cliente.');
}

radar_redirect_action('erro', 'Ação inválida.');
