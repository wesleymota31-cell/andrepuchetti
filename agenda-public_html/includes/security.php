<?php

function app_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function app_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => app_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function csrf_token(): string
{
    app_start_session();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

function csrf_validate(?string $token): bool
{
    app_start_session();
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function remember_cookie_name(): string
{
    return 'agenda_remember';
}

function remember_cookie_options(int $expires): array
{
    return [
        'expires' => $expires,
        'path' => '/',
        'domain' => '',
        'secure' => app_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function issue_remember_token(mysqli $conn, int $usuarioId): void
{
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $validatorHash = hash('sha256', $validator);
    $expiresAt = date('Y-m-d H:i:s', time() + (60 * 60 * 24 * 30));

    $stmt = $conn->prepare("
        INSERT INTO usuario_remember_tokens (usuario_id, selector, token_hash, expira_em)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param('isss', $usuarioId, $selector, $validatorHash, $expiresAt);
    $stmt->execute();

    setcookie(remember_cookie_name(), $selector . ':' . $validator, remember_cookie_options(time() + (60 * 60 * 24 * 30)));
}

function clear_remember_token(mysqli $conn): void
{
    $cookie = $_COOKIE[remember_cookie_name()] ?? '';
    if (is_string($cookie) && strpos($cookie, ':') !== false) {
        [$selector] = explode(':', $cookie, 2);
        $stmt = $conn->prepare("DELETE FROM usuario_remember_tokens WHERE selector = ?");
        $stmt->bind_param('s', $selector);
        $stmt->execute();
    }

    setcookie(remember_cookie_name(), '', remember_cookie_options(time() - 3600));
}

function login_user(mysqli $conn, array $usuario, bool $remember = false): void
{
    app_start_session();
    session_regenerate_id(true);

    $_SESSION['usuario_id'] = (int)$usuario['id'];
    $_SESSION['usuario_nome'] = $usuario['nome'];
    $_SESSION['usuario_email'] = $usuario['email'];
    $_SESSION['login_at'] = time();

    if ($remember) {
        issue_remember_token($conn, (int)$usuario['id']);
    }
}

function try_remember_login(mysqli $conn): bool
{
    app_start_session();

    if (!empty($_SESSION['usuario_id'])) {
        return true;
    }

    $cookie = $_COOKIE[remember_cookie_name()] ?? '';
    if (!is_string($cookie) || strpos($cookie, ':') === false) {
        return false;
    }

    [$selector, $validator] = explode(':', $cookie, 2);
    if ($selector === '' || $validator === '') {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT rt.id, rt.usuario_id, rt.token_hash, rt.expira_em, u.nome, u.email
        FROM usuario_remember_tokens rt
        INNER JOIN usuarios u ON u.id = rt.usuario_id
        WHERE rt.selector = ?
        LIMIT 1
    ");
    $stmt->bind_param('s', $selector);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();

    if (!$record || strtotime($record['expira_em']) < time()) {
        clear_remember_token($conn);
        return false;
    }

    if (!hash_equals($record['token_hash'], hash('sha256', $validator))) {
        clear_remember_token($conn);
        return false;
    }

    login_user($conn, [
        'id' => $record['usuario_id'],
        'nome' => $record['nome'],
        'email' => $record['email'],
    ], true);

    return true;
}

function send_password_reset_email(string $to, string $name, string $url): bool
{
    require_once __DIR__ . '/mailer.php';

    $subject = 'Redefinir senha da agenda';
    $safeName = $name !== '' ? $name : 'Olá';
    $content = '<p style="margin:0 0 16px;color:#d8d0bd;line-height:1.6;">Recebemos uma solicitação para redefinir sua senha da agenda.</p>'
        . '<p style="margin:0 0 18px;color:#d8d0bd;line-height:1.6;">O link abaixo expira em 60 minutos.</p>'
        . '<p style="margin:0 0 18px;"><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:#d4af37;color:#17130b;text-decoration:none;font-weight:800;padding:13px 18px;border-radius:12px;">Redefinir senha</a></p>'
        . '<p style="margin:0;color:#9d9586;font-size:13px;line-height:1.5;">Se você não solicitou isso, ignore esta mensagem.</p>';
    $text = $safeName . "\n\nRecebemos uma solicitação para redefinir sua senha da agenda.\nAcesse: " . $url;

    return agenda_send_email($to, $subject, agenda_email_shell('Redefinir senha', $safeName, $content), $text);
}
