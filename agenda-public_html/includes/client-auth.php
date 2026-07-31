<?php
require_once __DIR__ . '/security.php';

function cliente_login(mysqli $conn, array $cliente): void
{
    app_start_session();
    session_regenerate_id(true);
    $_SESSION['cliente_id'] = (int)$cliente['id'];
    $_SESSION['cliente_nome'] = $cliente['nome'];
    $_SESSION['cliente_email'] = $cliente['email'];
    $_SESSION['cliente_login_at'] = time();
}

function cliente_atual(mysqli $conn): ?array
{
    app_start_session();
    $id = (int)($_SESSION['cliente_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }

    $stmt = $conn->prepare("SELECT id, nome, telefone, email FROM clientes WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $cliente = $stmt->get_result()->fetch_assoc();

    if (!$cliente) {
        unset($_SESSION['cliente_id'], $_SESSION['cliente_nome'], $_SESSION['cliente_email'], $_SESSION['cliente_login_at']);
        return null;
    }

    return $cliente;
}

function exigir_cliente(mysqli $conn): array
{
    $cliente = cliente_atual($conn);
    if (!$cliente) {
        header('Location: cliente-login.php');
        exit;
    }
    return $cliente;
}
