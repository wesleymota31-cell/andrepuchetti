<?php

require_once __DIR__ . '/phone.php';

function radar_ensure_schema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS radar_retornos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cliente_id INT NOT NULL,
            profissional_id INT NOT NULL,
            servico_id INT NOT NULL,
            atendimento_origem_id INT NOT NULL,
            previsao_data DATE NOT NULL,
            frequencia_dias INT NOT NULL DEFAULT 20,
            frequencia_origem VARCHAR(30) NOT NULL DEFAULT 'servico',
            classificacao VARCHAR(30) NOT NULL DEFAULT 'novo',
            estado_manual VARCHAR(30) NULL,
            ultimo_contato_em DATETIME NULL,
            tentativas INT NOT NULL DEFAULT 0,
            adiado_para DATE NULL,
            ignorado_ate DATE NULL,
            lembretes_ativos TINYINT(1) NOT NULL DEFAULT 1,
            agendamento_gerado_id INT NULL,
            concluido_em DATETIME NULL,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_radar_origem (atendimento_origem_id),
            KEY idx_radar_prof_previsao (profissional_id, previsao_data),
            KEY idx_radar_cliente_prof (cliente_id, profissional_id),
            KEY idx_radar_estado (estado_manual)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function radar_date_br(string $date): string
{
    return date('d/m/Y', strtotime($date));
}

function radar_first_name(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    return $parts[0] ?? $name;
}

function radar_service_frequency(string $serviceName): int
{
    $name = mb_strtolower($serviceName, 'UTF-8');
    $name = strtr($name, ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);

    if (preg_match('/barba/', $name) && !preg_match('/cabelo|corte/', $name)) return 10;
    if (preg_match('/cabelo\s*\+\s*barba|corte\s*\+\s*barba/', $name)) return 20;
    if (preg_match('/corte|cabelo/', $name)) return 20;
    if (preg_match('/sobrancelha/', $name)) return 20;
    if (preg_match('/progressiva|botox|luzes|mecha|color/', $name)) return 45;
    return 20;
}

function radar_intervals_for_client(mysqli $conn, int $clienteId, int $profissionalId, int $servicoId): array
{
    $stmt = $conn->prepare("
        SELECT data
        FROM agendamentos
        WHERE cliente_id = ?
          AND profissional_id = ?
          AND servico_id = ?
          AND COALESCE(status, '') <> 'cancelado'
          AND data <= CURDATE()
        ORDER BY data DESC, hora DESC
        LIMIT 5
    ");
    $stmt->bind_param('iii', $clienteId, $profissionalId, $servicoId);
    $stmt->execute();
    $dates = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $dates[] = $row['data'];
    }

    $intervals = [];
    for ($i = 0; $i < count($dates) - 1; $i++) {
        $current = strtotime($dates[$i]);
        $previous = strtotime($dates[$i + 1]);
        $days = (int)round(($current - $previous) / 86400);
        if ($days >= 7 && $days <= 90) {
            $intervals[] = $days;
        }
    }

    return $intervals;
}

function radar_frequency_for_appointment(mysqli $conn, array $ag): array
{
    if ((int)($ag['is_recorrente'] ?? 0) === 1) {
        return ['days' => 30, 'origin' => 'recorrencia'];
    }

    $intervals = radar_intervals_for_client($conn, (int)$ag['cliente_id'], (int)$ag['profissional_id'], (int)$ag['servico_id']);
    if (count($intervals) >= 2) {
        sort($intervals);
        $middle = array_slice($intervals, 0, 4);
        $days = (int)round(array_sum($middle) / count($middle));
        return ['days' => max(7, min(60, $days)), 'origin' => 'historico'];
    }

    return ['days' => radar_service_frequency($ag['servico_nome'] ?? ''), 'origin' => 'servico'];
}

function radar_classification(mysqli $conn, array $ag): string
{
    if ((int)($ag['is_recorrente'] ?? 0) === 1) return 'recorrente';

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM agendamentos
        WHERE cliente_id = ?
          AND profissional_id = ?
          AND COALESCE(status, '') <> 'cancelado'
          AND data <= CURDATE()
    ");
    $stmt->bind_param('ii', $ag['cliente_id'], $ag['profissional_id']);
    $stmt->execute();
    $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);

    if ($total <= 1) return 'novo';
    if ($total === 2) return 'em_formacao';

    $intervals = radar_intervals_for_client($conn, (int)$ag['cliente_id'], (int)$ag['profissional_id'], (int)$ag['servico_id']);
    if (count($intervals) >= 2 && (max($intervals) - min($intervals)) <= 12) return 'recorrente';

    return 'avulso';
}

function radar_sync_cycles(mysqli $conn, ?int $profissionalId = null): void
{
    radar_ensure_schema($conn);

    $sql = "
        SELECT ag.id, ag.cliente_id, ag.profissional_id, ag.servico_id, ag.data, ag.hora, ag.is_recorrente,
               s.nome AS servico_nome
        FROM agendamentos ag
        INNER JOIN (
            SELECT cliente_id, profissional_id, MAX(CONCAT(data, ' ', hora)) AS ultimo
            FROM agendamentos
            WHERE COALESCE(status, '') <> 'cancelado'
              AND data <= CURDATE()
            GROUP BY cliente_id, profissional_id
        ) ult ON ult.cliente_id = ag.cliente_id
             AND ult.profissional_id = ag.profissional_id
             AND ult.ultimo = CONCAT(ag.data, ' ', ag.hora)
        INNER JOIN servicos s ON s.id = ag.servico_id
        WHERE COALESCE(ag.status, '') <> 'cancelado'
    ";

    $params = [];
    $types = '';
    if ($profissionalId !== null) {
        $sql .= " AND ag.profissional_id = ?";
        $params[] = $profissionalId;
        $types .= 'i';
    }
    $sql .= " ORDER BY ag.data DESC LIMIT 500";

    $stmt = $conn->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($ag = $res->fetch_assoc()) {
        $freq = radar_frequency_for_appointment($conn, $ag);
        $classification = radar_classification($conn, $ag);
        $previsao = date('Y-m-d', strtotime($ag['data'] . ' +' . (int)$freq['days'] . ' days'));

        $stmtUp = $conn->prepare("
            INSERT INTO radar_retornos
                (cliente_id, profissional_id, servico_id, atendimento_origem_id, previsao_data, frequencia_dias, frequencia_origem, classificacao)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                cliente_id = VALUES(cliente_id),
                profissional_id = VALUES(profissional_id),
                servico_id = VALUES(servico_id),
                previsao_data = IF(estado_manual IN ('ignorado','adiado','aguardando_resposta','contatado'), previsao_data, VALUES(previsao_data)),
                frequencia_dias = VALUES(frequencia_dias),
                frequencia_origem = VALUES(frequencia_origem),
                classificacao = VALUES(classificacao)
        ");
        $stmtUp->bind_param(
            'iiiisiss',
            $ag['cliente_id'],
            $ag['profissional_id'],
            $ag['servico_id'],
            $ag['id'],
            $previsao,
            $freq['days'],
            $freq['origin'],
            $classification
        );
        $stmtUp->execute();
    }
}

function radar_has_future_booking(mysqli $conn, int $clienteId, int $profissionalId): ?array
{
    $stmt = $conn->prepare("
        SELECT id, data, hora
        FROM agendamentos
        WHERE cliente_id = ?
          AND profissional_id = ?
          AND COALESCE(status, '') <> 'cancelado'
          AND TIMESTAMP(data, hora) >= NOW()
        ORDER BY data ASC, hora ASC
        LIMIT 1
    ");
    $stmt->bind_param('ii', $clienteId, $profissionalId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function radar_state(mysqli $conn, array $row): array
{
    $manual = $row['estado_manual'] ?? '';
    if ($manual === 'desativado' || (int)$row['lembretes_ativos'] === 0) return ['key' => 'desativado', 'label' => 'Lembretes desativados', 'priority' => 99];
    if ($manual === 'ignorado' && !empty($row['ignorado_ate']) && $row['ignorado_ate'] >= date('Y-m-d')) return ['key' => 'ignorado', 'label' => 'Ignorado neste ciclo', 'priority' => 98];

    $future = radar_has_future_booking($conn, (int)$row['cliente_id'], (int)$row['profissional_id']);
    if ($future) return ['key' => 'agendado', 'label' => 'Novo agendamento realizado', 'priority' => 80];

    if ($manual === 'aguardando_resposta') return ['key' => 'aguardando_resposta', 'label' => 'Aguardando resposta', 'priority' => 7];
    if ($manual === 'contatado' && !empty($row['ultimo_contato_em'])) {
        $days = (int)floor((time() - strtotime($row['ultimo_contato_em'])) / 86400);
        if ($days < 2) return ['key' => 'contatado', 'label' => $days <= 0 ? 'Contatado hoje' : 'Contatado ontem', 'priority' => 8];
    }

    $target = !empty($row['adiado_para']) && $row['adiado_para'] > date('Y-m-d') ? $row['adiado_para'] : $row['previsao_data'];
    $diff = (int)floor((strtotime($target) - strtotime(date('Y-m-d'))) / 86400);

    if ($diff < -7) return ['key' => 'risco', 'label' => 'Cliente em risco', 'priority' => (string)$row['classificacao'] === 'recorrente' ? 1 : 5];
    if ($diff < 0) return ['key' => 'atrasado', 'label' => 'Atrasado ha ' . abs($diff) . ' dia' . (abs($diff) === 1 ? '' : 's'), 'priority' => (string)$row['classificacao'] === 'recorrente' ? 2 : 5];
    if ($diff === 0) return ['key' => 'hoje', 'label' => 'Retorno previsto para hoje', 'priority' => 3];
    if ($diff === 1) return ['key' => 'amanha', 'label' => 'Retorno previsto para amanha', 'priority' => 4];
    if ($diff <= 7) return ['key' => 'semana', 'label' => 'Retorno em ' . $diff . ' dias', 'priority' => 6];

    return ['key' => 'proximo', 'label' => 'Retorno em ' . $diff . ' dias', 'priority' => 9];
}

function radar_type_label(string $type): string
{
    return [
        'recorrente' => 'Recorrente',
        'avulso' => 'Avulso',
        'novo' => 'Novo',
        'em_formacao' => 'Em formação',
    ][$type] ?? 'Avulso';
}

function radar_whatsapp_message(array $row, string $stateKey): string
{
    $name = radar_first_name($row['cliente_nome'] ?? 'cliente');
    $professional = $row['profissional_nome'] ?? 'profissional';

    if ($stateKey === 'risco') {
        return "Olá, {$name}! Tudo bem?\n\nSentimos sua falta por aqui. {$professional} está com alguns horários disponíveis nos próximos dias. Quer que eu veja uma boa opção para você?";
    }

    if (($row['classificacao'] ?? '') === 'recorrente' && in_array($stateKey, ['atrasado'], true)) {
        return "Olá, {$name}! Tudo bem?\n\nJá passou um pouquinho do período em que você costuma renovar seu corte com {$professional}. Vamos deixar o visual em dia? Posso enviar os horários disponíveis.";
    }

    if (($row['classificacao'] ?? '') === 'recorrente') {
        return "Olá, {$name}! Tudo bem?\n\nJá está chegando a época de renovar seu corte com {$professional}. Temos alguns horários disponíveis nesta semana. Quer que eu envie as opções?";
    }

    return "Olá, {$name}! Tudo bem?\n\nJá faz algumas semanas desde o seu último atendimento com {$professional}. Gostaria de agendar novamente? Temos alguns horários disponíveis nesta semana.";
}

function radar_fetch_items(mysqli $conn, array $options = []): array
{
    radar_sync_cycles($conn, $options['profissional_id'] ?? null);

    $sql = "
        SELECT rr.*, c.nome AS cliente_nome, c.telefone AS cliente_telefone,
               p.nome AS profissional_nome, s.nome AS servico_nome, ag.data AS ultimo_atendimento_data
        FROM radar_retornos rr
        INNER JOIN clientes c ON c.id = rr.cliente_id
        INNER JOIN profissionais p ON p.id = rr.profissional_id
        INNER JOIN servicos s ON s.id = rr.servico_id
        INNER JOIN agendamentos ag ON ag.id = rr.atendimento_origem_id
        WHERE rr.concluido_em IS NULL
          AND rr.lembretes_ativos = 1
    ";
    $params = [];
    $types = '';

    if (!empty($options['profissional_id'])) {
        $sql .= " AND rr.profissional_id = ?";
        $params[] = (int)$options['profissional_id'];
        $types .= 'i';
    }
    if (!empty($options['q'])) {
        $q = '%' . $options['q'] . '%';
        $sql .= " AND (c.nome LIKE ? OR c.telefone LIKE ? OR s.nome LIKE ? OR p.nome LIKE ?)";
        array_push($params, $q, $q, $q, $q);
        $types .= 'ssss';
    }

    $sql .= " ORDER BY rr.previsao_data ASC LIMIT 250";
    $stmt = $conn->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];

    while ($row = $res->fetch_assoc()) {
        $state = radar_state($conn, $row);
        $row['estado_key'] = $state['key'];
        $row['estado_label'] = $state['label'];
        $row['prioridade'] = $state['priority'];
        $row['tipo_label'] = radar_type_label($row['classificacao']);
        $row['whatsapp_phone'] = telefoneWhatsapp($row['cliente_telefone'] ?? '');
        $row['whatsapp_message'] = radar_whatsapp_message($row, $state['key']);

        if (!empty($options['state']) && $options['state'] !== 'prioridades') {
            $filter = $options['state'];
            $map = ['atrasados' => 'atrasado', 'risco' => 'risco', 'hoje' => 'hoje', 'amanha' => 'amanha', 'semana' => 'semana'];
            if (($map[$filter] ?? $filter) !== $row['estado_key']) continue;
        }

        if (!empty($options['type']) && $options['type'] !== $row['classificacao']) continue;
        if (in_array($row['estado_key'], ['desativado','ignorado','agendado'], true) && empty($options['include_inactive'])) continue;
        $items[] = $row;
    }

    usort($items, fn($a, $b) => [$a['prioridade'], $a['previsao_data']] <=> [$b['prioridade'], $b['previsao_data']]);
    return array_slice($items, 0, (int)($options['limit'] ?? 50));
}

function radar_summary(array $items): array
{
    $summary = ['atrasado' => 0, 'hoje' => 0, 'amanha' => 0, 'semana' => 0, 'risco' => 0, 'contatado' => 0, 'agendado' => 0];
    foreach ($items as $item) {
        if (isset($summary[$item['estado_key']])) $summary[$item['estado_key']]++;
    }
    return $summary;
}
