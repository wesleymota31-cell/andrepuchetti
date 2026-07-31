<?php
require_once '../config.php';
require_once '../includes/auth.php';

if (!isset($_GET['id']) || (int)$_GET['id'] <= 0) {
    header('Location: agenda-visual.php');
    exit;
}

if (!csrf_validate($_GET['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Ação expirada ou inválida.');
}

$id = (int) $_GET['id'];
$data = $_GET['data'] ?? date('Y-m-d');
$returnTo = ($_GET['return_to'] ?? '') === 'dashboard' ? 'dashboard' : 'agenda';

function redirectAfterCancellation(string $returnTo, string $data): void
{
    $page = $returnTo === 'dashboard' ? 'index.php' : 'agenda-visual.php';
    header('Location: ' . $page . '?data=' . urlencode($data));
    exit;
}

$stmtCheck = $conn->prepare("
    SELECT id, status, profissional_id, recorrencia_id, is_recorrente
    FROM agendamentos
    WHERE id = ?
    LIMIT 1
");
$stmtCheck->bind_param('i', $id);
$stmtCheck->execute();
$resultCheck = $stmtCheck->get_result();
$agendamento = $resultCheck ? $resultCheck->fetch_assoc() : null;

if (!$agendamento) {
    redirectAfterCancellation($returnTo, $data);
}

if (!function_exists('podeEditarProfissional') || !podeEditarProfissional((int)$agendamento['profissional_id'])) {
    http_response_code(403);
    exit('Sem permissão para cancelar este agendamento.');
}

if ((int)($agendamento['is_recorrente'] ?? 0) === 1 && !empty($agendamento['recorrencia_id'])) {
    $recorrenciaId = (int)$agendamento['recorrencia_id'];

    $conn->begin_transaction();

    try {
        $stmtSerie = $conn->prepare("DELETE FROM agendamentos WHERE recorrencia_id = ?");
        $stmtSerie->bind_param('i', $recorrenciaId);
        $stmtSerie->execute();

        $stmtRec = $conn->prepare("DELETE FROM recorrencias WHERE id = ? LIMIT 1");
        $stmtRec->bind_param('i', $recorrenciaId);
        $stmtRec->execute();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        http_response_code(500);
        exit('Não foi possível cancelar a recorrência.');
    }
} elseif ($agendamento['status'] !== 'cancelado') {
    $stmt = $conn->prepare("UPDATE agendamentos SET status = 'cancelado' WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
}

redirectAfterCancellation($returnTo, $data);
