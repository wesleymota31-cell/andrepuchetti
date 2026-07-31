<?php
require_once '../config.php';
require_once '../includes/auth.php';

if (!isset($_GET['id'])) {
    header('Location: bloquear.php');
    exit;
}

if (!csrf_validate($_GET['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Ação expirada ou inválida.');
}

$id = (int) $_GET['id'];
$tipo = trim($_GET['tipo'] ?? 'pontual');

if ($tipo === 'recorrente') {
    $stmtCheck = $conn->prepare("SELECT profissional_id FROM bloqueios_recorrentes WHERE id = ? LIMIT 1");
    $stmtCheck->bind_param('i', $id);
    $stmtCheck->execute();
    $bloqueio = $stmtCheck->get_result()->fetch_assoc();

    if (!$bloqueio || !function_exists('podeEditarProfissional') || !podeEditarProfissional((int)$bloqueio['profissional_id'])) {
        http_response_code(403);
        exit('Sem permissão para remover este bloqueio.');
    }

    $stmt = $conn->prepare("DELETE FROM bloqueios_recorrentes WHERE id = ?");
} else {
    $stmtCheck = $conn->prepare("SELECT profissional_id FROM bloqueios WHERE id = ? LIMIT 1");
    $stmtCheck->bind_param('i', $id);
    $stmtCheck->execute();
    $bloqueio = $stmtCheck->get_result()->fetch_assoc();

    if (!$bloqueio || !function_exists('podeEditarProfissional') || !podeEditarProfissional((int)$bloqueio['profissional_id'])) {
        http_response_code(403);
        exit('Sem permissão para remover este bloqueio.');
    }

    $stmt = $conn->prepare("DELETE FROM bloqueios WHERE id = ?");
}
$stmt->bind_param('i', $id);
$stmt->execute();

header('Location: bloquear.php');
exit;
