<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/mailer.php';

date_default_timezone_set('America/Sao_Paulo');

$token = $_GET['token'] ?? '';
$expected = defined('AGENDA_CRON_TOKEN') ? AGENDA_CRON_TOKEN : '';

if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    exit('forbidden');
}

function cron_date_br(string $date): string
{
    return date('d/m/Y', strtotime($date));
}

function cron_hour_br(string $time): string
{
    $time = substr($time, 0, 5);
    [$h, $m] = explode(':', $time);
    return (int)$m === 0 ? ((int)$h) . 'h' : ((int)$h) . 'h' . $m;
}

$targets = [
    'reminder_today' => date('Y-m-d'),
    'reminder_tomorrow' => date('Y-m-d', strtotime('+1 day')),
];

$sent = 0;

foreach ($targets as $type => $date) {
    $stmt = $conn->prepare("
        SELECT ag.id, ag.data, ag.hora, c.nome, c.email, p.nome AS profissional, s.nome AS servico
        FROM agendamentos ag
        INNER JOIN clientes c ON c.id = ag.cliente_id
        INNER JOIN profissionais p ON p.id = ag.profissional_id
        INNER JOIN servicos s ON s.id = ag.servico_id
        LEFT JOIN email_logs el ON el.agendamento_id = ag.id AND el.tipo = ?
        WHERE ag.data = ?
          AND ag.status IN ('confirmado', 'pendente')
          AND c.email IS NOT NULL
          AND c.email <> ''
          AND el.id IS NULL
        ORDER BY ag.hora ASC
    ");
    $stmt->bind_param('ss', $type, $date);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $booking = [
            'profissional' => $row['profissional'],
            'servico' => $row['servico'],
            'data' => cron_date_br($row['data']),
            'hora' => cron_hour_br($row['hora']),
        ];

        if (agenda_send_booking_email($row['email'], $row['nome'], $booking, $type)) {
            $stmtLog = $conn->prepare("INSERT INTO email_logs (agendamento_id, tipo, enviado_em) VALUES (?, ?, NOW())");
            $stmtLog->bind_param('is', $row['id'], $type);
            $stmtLog->execute();
            $sent++;
        }
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'sent' => $sent], JSON_UNESCAPED_UNICODE);
