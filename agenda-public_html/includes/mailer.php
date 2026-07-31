<?php

function agenda_smtp_configured(): bool
{
    return defined('AGENDA_SMTP_HOST')
        && defined('AGENDA_SMTP_USER')
        && defined('AGENDA_SMTP_PASS')
        && AGENDA_SMTP_HOST !== ''
        && AGENDA_SMTP_USER !== ''
        && AGENDA_SMTP_PASS !== '';
}

function agenda_smtp_command($socket, string $command, array $okCodes): bool
{
    fwrite($socket, $command . "\r\n");
    $response = '';

    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    $code = substr($response, 0, 3);
    return in_array($code, $okCodes, true);
}

function agenda_send_email(string $to, string $subject, string $html, string $text = ''): bool
{
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $fromEmail = defined('AGENDA_MAIL_FROM') ? AGENDA_MAIL_FROM : 'no-reply@andrepuchetti.com.br';
    $fromName = defined('AGENDA_MAIL_FROM_NAME') ? AGENDA_MAIL_FROM_NAME : 'Salao Andre Puchetti';
    $text = $text !== '' ? $text : trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)));

    if (!agenda_smtp_configured()) {
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $fromName . ' <' . $fromEmail . '>',
            'Reply-To: ' . $fromEmail,
        ];
        return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, implode("\r\n", $headers));
    }

    $host = AGENDA_SMTP_HOST;
    $port = defined('AGENDA_SMTP_PORT') ? (int)AGENDA_SMTP_PORT : 465;
    $secure = defined('AGENDA_SMTP_SECURE') ? AGENDA_SMTP_SECURE : 'ssl';
    $target = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $socket = stream_socket_client($target, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);

    if (!$socket) {
        error_log('SMTP connect error: ' . $errstr);
        return false;
    }

    stream_set_timeout($socket, 20);
    fgets($socket, 515);

    $domain = $_SERVER['HTTP_HOST'] ?? 'andrepuchetti.com.br';
    $boundary = 'b_' . bin2hex(random_bytes(12));
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = [
        'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . ' <' . $fromEmail . '>',
        'To: <' . $to . '>',
        'Subject: ' . $encodedSubject,
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'Date: ' . date(DATE_RFC2822),
        'Message-ID: <' . bin2hex(random_bytes(10)) . '@andrepuchetti.com.br>',
    ];
    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($text));
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($html));
    $body .= "--{$boundary}--\r\n";
    $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;

    $ok = agenda_smtp_command($socket, 'EHLO ' . $domain, ['250'])
        && agenda_smtp_command($socket, 'AUTH LOGIN', ['334'])
        && agenda_smtp_command($socket, base64_encode(AGENDA_SMTP_USER), ['334'])
        && agenda_smtp_command($socket, base64_encode(AGENDA_SMTP_PASS), ['235'])
        && agenda_smtp_command($socket, 'MAIL FROM:<' . $fromEmail . '>', ['250'])
        && agenda_smtp_command($socket, 'RCPT TO:<' . $to . '>', ['250', '251'])
        && agenda_smtp_command($socket, 'DATA', ['354']);

    if ($ok) {
        fwrite($socket, $message . "\r\n.\r\n");
        $ok = false;
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $ok = substr($response, 0, 3) === '250';
    }

    agenda_smtp_command($socket, 'QUIT', ['221']);
    fclose($socket);
    return $ok;
}

function agenda_email_shell(string $title, string $name, string $content): string
{
    $safeName = htmlspecialchars($name ?: 'Tudo bem?', ENT_QUOTES, 'UTF-8');
    return '<!doctype html><html><body style="margin:0;background:#0b0b0b;font-family:Arial,Helvetica,sans-serif;color:#f7f1df;">'
        . '<div style="max-width:620px;margin:0 auto;padding:28px 18px;">'
        . '<div style="border:1px solid rgba(212,175,55,.28);border-radius:18px;background:#121212;padding:26px;">'
        . '<div style="font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:#d4af37;font-weight:700;">Andre Puchetti</div>'
        . '<h1 style="margin:12px 0 10px;font-size:28px;line-height:1.15;color:#fff4c8;">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<p style="margin:0 0 18px;color:#d8d0bd;font-size:16px;line-height:1.6;">Olá, ' . $safeName . '.</p>'
        . $content
        . '<p style="margin:24px 0 0;color:#9d9586;font-size:13px;line-height:1.5;">Mensagem automática do sistema de agendamento do Salão André Puchetti.</p>'
        . '</div></div></body></html>';
}

function agenda_send_booking_email(string $to, string $name, array $booking, string $type): bool
{
    $titles = [
        'created' => 'Agendamento confirmado',
        'rescheduled' => 'Agendamento reagendado',
        'cancelled' => 'Agendamento cancelado',
        'reminder_tomorrow' => 'Seu horário é amanhã',
        'reminder_today' => 'Seu horário é hoje',
    ];
    $subject = ($titles[$type] ?? 'Atualização do seu agendamento') . ' | Andre Puchetti';
    $content = '<div style="background:#181818;border-radius:14px;padding:16px;border:1px solid rgba(255,255,255,.08);">'
        . '<p style="margin:0 0 8px;color:#f7f1df;"><strong>Serviço:</strong> ' . htmlspecialchars($booking['servico'] ?? '', ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="margin:0 0 8px;color:#f7f1df;"><strong>Profissional:</strong> ' . htmlspecialchars($booking['profissional'] ?? '', ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="margin:0 0 8px;color:#f7f1df;"><strong>Data:</strong> ' . htmlspecialchars($booking['data'] ?? '', ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="margin:0;color:#f7f1df;"><strong>Horário:</strong> ' . htmlspecialchars($booking['hora'] ?? '', ENT_QUOTES, 'UTF-8') . '</p>'
        . '</div>';
    $content .= '<p style="margin:18px 0 0;color:#d8d0bd;line-height:1.6;">Qualquer dúvida, responda pelo WhatsApp da equipe.</p>';

    return agenda_send_email($to, $subject, agenda_email_shell($titles[$type] ?? 'Atualização do agendamento', $name, $content));
}

function agenda_send_client_welcome_email(string $to, string $name): bool
{
    $content = '<p style="margin:0 0 16px;color:#d8d0bd;line-height:1.6;">Sua Área do Cliente está pronta para acompanhar seus horários, criar novos procedimentos, remarcar ou cancelar quando precisar.</p>'
        . '<p style="margin:0 0 18px;color:#d8d0bd;line-height:1.6;">Use o mesmo e-mail e senha cadastrados para entrar sempre que quiser.</p>'
        . '<p style="margin:0;"><a href="https://agenda.andrepuchetti.com.br/cliente-login.php" style="display:inline-block;background:#d4af37;color:#17130b;text-decoration:none;font-weight:800;padding:13px 18px;border-radius:12px;">Acessar minha área</a></p>';

    return agenda_send_email($to, 'Bem-vindo a sua area do cliente | Andre Puchetti', agenda_email_shell('Área do Cliente criada', $name, $content));
}

function agenda_send_client_password_reset_email(string $to, string $name, string $url): bool
{
    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $content = '<p style="margin:0 0 16px;color:#d8d0bd;line-height:1.6;">Recebemos uma solicitação para criar uma nova senha de acesso à sua Área do Cliente.</p>'
        . '<p style="margin:0 0 18px;color:#d8d0bd;line-height:1.6;">O link abaixo é seguro e expira em 1 hora.</p>'
        . '<p style="margin:0 0 18px;"><a href="' . $safeUrl . '" style="display:inline-block;background:#d4af37;color:#17130b;text-decoration:none;font-weight:800;padding:13px 18px;border-radius:12px;">Criar nova senha</a></p>'
        . '<p style="margin:0;color:#9d9586;line-height:1.5;font-size:13px;">Se você não pediu essa alteração, pode ignorar este e-mail.</p>';
    $text = "Recebemos uma solicitação para criar uma nova senha.\nAcesse: " . $url . "\nEste link expira em 1 hora.";

    return agenda_send_email($to, 'Criar nova senha | Andre Puchetti', agenda_email_shell('Redefinir senha', $name, $content), $text);
}
