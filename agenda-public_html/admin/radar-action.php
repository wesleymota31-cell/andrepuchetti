<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/radar.php';

date_default_timezone_set('America/Sao_Paulo');
radar_ensure_schema($conn);

function radar_redirect_action(string $flash, string $msg): void
{
    $back = $_POST['return_to'] ?? $_SERVER['HTTP_REFERER'] ?? 'radar-retornos.php';
    $sep = str_contains($back, '?') ? '&' : '?';
    header('Location: ' . $back . $sep . 'flash=' . urlencode($flash) . '&msg=' . urlencode($msg));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_validate($_POST['csrf_token'] ?? null)) {
    radar_redirect_action('erro', 'Ação expirada. Tente novamente.');
}

$action = $_POST['radar_action'] ?? '';

if ($action === 'salvar') {
    $id = (int)($_POST['id'] ?? 0);
    $nome = trim($_POST['cliente_nome'] ?? '');
    $telefone = trim($_POST['cliente_telefone'] ?? '');
    $profissionalId = usuarioEhAdmin() ? (int)($_POST['profissional_id'] ?? 0) : (int)(usuarioProfissionalId() ?? 0);
    $frequencia = max(1, min(120, (int)($_POST['frequencia_dias'] ?? 15)));
    $avisar = max(1, min($frequencia, (int)($_POST['avisar_com_dias'] ?? max(1, $frequencia - 2))));
    $ultimo = trim($_POST['ultimo_atendimento'] ?? '');
    $observacao = trim($_POST['observacao'] ?? '');

    $validDate = DateTime::createFromFormat('Y-m-d', $ultimo);
    $phone = normalizarTelefoneCliente($telefone);

    if ($nome === '' || !$phone['valid'] || !$validDate || $validDate->format('Y-m-d') !== $ultimo || $profissionalId <= 0) {
        radar_redirect_action('erro', 'Preencha nome, WhatsApp, profissional e último atendimento.');
    }

    if (!podeEditarProfissional($profissionalId)) {
        radar_redirect_action('erro', 'Você não tem permissão para esse profissional.');
    }

    if ($id > 0) {
        $stmtCheck = $conn->prepare("SELECT profissional_id FROM retorno_manual WHERE id = ? LIMIT 1");
        $stmtCheck->bind_param('i', $id);
        $stmtCheck->execute();
        $current = $stmtCheck->get_result()->fetch_assoc();
        if (!$current || !podeEditarProfissional((int)$current['profissional_id'])) {
            radar_redirect_action('erro', 'Você não tem permissão para editar esse lembrete.');
        }

        $stmt = $conn->prepare("
            UPDATE retorno_manual
            SET cliente_nome = ?, cliente_telefone = ?, profissional_id = ?, frequencia_dias = ?, avisar_com_dias = ?, ultimo_atendimento = ?, observacao = ?, status_manual = NULL, adiado_para = NULL
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('ssiiissi', $nome, $phone['display_phone'], $profissionalId, $frequencia, $avisar, $ultimo, $observacao, $id);
        $stmt->execute();
        radar_redirect_action('sucesso', 'Lembrete atualizado.');
    }

    $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
    $stmt = $conn->prepare("
        INSERT INTO retorno_manual
            (cliente_nome, cliente_telefone, profissional_id, frequencia_dias, avisar_com_dias, ultimo_atendimento, observacao, criado_por)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('ssiiissi', $nome, $phone['display_phone'], $profissionalId, $frequencia, $avisar, $ultimo, $observacao, $usuarioId);
    $stmt->execute();
    radar_redirect_action('sucesso', 'Cliente recorrente cadastrado.');
}

$id = (int)($_POST['id'] ?? 0);
$stmt = $conn->prepare("SELECT id, profissional_id FROM retorno_manual WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$item = $stmt->get_result()->fetch_assoc();

if (!$item || !podeEditarProfissional((int)$item['profissional_id'])) {
    radar_redirect_action('erro', 'Você não tem permissão para alterar esse lembrete.');
}

if ($action === 'contatado') {
    $stmtUp = $conn->prepare("UPDATE retorno_manual SET status_manual = 'contatado', ultimo_contato_em = NOW(), adiado_para = NULL WHERE id = ? LIMIT 1");
    $stmtUp->bind_param('i', $id);
    $stmtUp->execute();
    radar_redirect_action('sucesso', 'Contato registrado.');
}

if ($action === 'lembrar') {
    $days = max(1, min(30, (int)($_POST['dias'] ?? 1)));
    $date = date('Y-m-d', strtotime('+' . $days . ' days'));
    $stmtUp = $conn->prepare("UPDATE retorno_manual SET status_manual = 'adiado', adiado_para = ? WHERE id = ? LIMIT 1");
    $stmtUp->bind_param('si', $date, $id);
    $stmtUp->execute();
    radar_redirect_action('sucesso', 'Lembrete adiado.');
}

if ($action === 'desativar') {
    $stmtUp = $conn->prepare("UPDATE retorno_manual SET ativo = 0 WHERE id = ? LIMIT 1");
    $stmtUp->bind_param('i', $id);
    $stmtUp->execute();
    radar_redirect_action('sucesso', 'Lembrete desativado.');
}

radar_redirect_action('erro', 'Ação inválida.');
