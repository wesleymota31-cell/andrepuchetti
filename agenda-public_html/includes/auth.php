<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/../config.php';

app_start_session();

if (!isset($_SESSION['usuario_id'])) {
    try_remember_login($conn);
}

if (!isset($_SESSION['usuario_id'])) {
    header('Location: /login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Ação expirada ou inválida.');
}

$usuarioId = (int) $_SESSION['usuario_id'];

$stmtAuth = $conn->prepare("
    SELECT id, nome, email, tipo, profissional_id
    FROM usuarios
    WHERE id = ?
    LIMIT 1
");
$stmtAuth->bind_param('i', $usuarioId);
$stmtAuth->execute();
$resultAuth = $stmtAuth->get_result();
$usuarioLogado = $resultAuth ? $resultAuth->fetch_assoc() : null;

if (!$usuarioLogado) {
    session_unset();
    session_destroy();
    header('Location: /login.php');
    exit;
}

$_SESSION['usuario_nome'] = $usuarioLogado['nome'];
$_SESSION['usuario_email'] = $usuarioLogado['email'];
$_SESSION['usuario_tipo'] = $usuarioLogado['tipo'];
$_SESSION['profissional_id'] = $usuarioLogado['profissional_id'];

function usuarioEhAdmin(): bool
{
    return isset($_SESSION['usuario_tipo']) && $_SESSION['usuario_tipo'] === 'admin';
}

function usuarioEhProfissional(): bool
{
    return isset($_SESSION['usuario_tipo']) && $_SESSION['usuario_tipo'] === 'profissional';
}

function usuarioProfissionalId(): ?int
{
    if (!isset($_SESSION['profissional_id']) || $_SESSION['profissional_id'] === null) {
        return null;
    }

    return (int) $_SESSION['profissional_id'];
}

function podeEditarProfissional(int $profissionalId): bool
{
    if (usuarioEhAdmin()) {
        return true;
    }

    $meuProfissionalId = usuarioProfissionalId();
    return $meuProfissionalId !== null && $meuProfissionalId === $profissionalId;
}

function podeVisualizarProfissional(int $profissionalId): bool
{
    if (usuarioEhAdmin()) {
        return true;
    }

    if (usuarioEhProfissional()) {
        return true;
    }

    return false;
}
