<?php

require_once __DIR__ . '/phone.php';

function radar_ensure_schema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS retorno_manual (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cliente_nome VARCHAR(160) NOT NULL,
            cliente_telefone VARCHAR(40) NOT NULL,
            profissional_id INT NOT NULL,
            frequencia_dias INT NOT NULL DEFAULT 15,
            avisar_com_dias INT NOT NULL DEFAULT 13,
            ultimo_atendimento DATE NOT NULL,
            observacao TEXT NULL,
            status_manual VARCHAR(30) NULL,
            ultimo_contato_em DATETIME NULL,
            adiado_para DATE NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            criado_por INT NULL,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_retorno_profissional (profissional_id, ativo),
            KEY idx_retorno_aviso (ativo, ultimo_atendimento, frequencia_dias, avisar_com_dias),
            KEY idx_retorno_telefone (cliente_telefone)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function radar_date_br(?string $date): string
{
    if (!$date) return '';
    return date('d/m/Y', strtotime($date));
}

function radar_first_name(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    return $parts[0] ?? $name;
}

function retorno_manual_target(array $row): array
{
    $last = $row['ultimo_atendimento'] ?? date('Y-m-d');
    $frequency = max(1, (int)($row['frequencia_dias'] ?? 15));
    $warn = max(1, min($frequency, (int)($row['avisar_com_dias'] ?? max(1, $frequency - 2))));
    $cutDate = date('Y-m-d', strtotime($last . ' +' . $frequency . ' days'));
    $notifyDate = date('Y-m-d', strtotime($last . ' +' . $warn . ' days'));

    if (!empty($row['adiado_para']) && $row['adiado_para'] > $notifyDate) {
        $notifyDate = $row['adiado_para'];
    }

    return ['cut_date' => $cutDate, 'notify_date' => $notifyDate];
}

function retorno_manual_state(array $row): array
{
    if ((int)($row['ativo'] ?? 1) !== 1) {
        return ['key' => 'inativo', 'label' => 'Inativo', 'priority' => 99];
    }

    if (($row['status_manual'] ?? '') === 'contatado' && !empty($row['ultimo_contato_em'])) {
        $days = (int)floor((time() - strtotime($row['ultimo_contato_em'])) / 86400);
        if ($days < 2) {
            return ['key' => 'contatado', 'label' => $days <= 0 ? 'Contatado hoje' : 'Contatado ontem', 'priority' => 7];
        }
    }

    $target = retorno_manual_target($row);
    $diff = (int)floor((strtotime($target['notify_date']) - strtotime(date('Y-m-d'))) / 86400);
    $cutDiff = (int)floor((strtotime($target['cut_date']) - strtotime(date('Y-m-d'))) / 86400);

    if ($cutDiff < 0) return ['key' => 'atrasado', 'label' => 'Corte atrasado ha ' . abs($cutDiff) . ' dia' . (abs($cutDiff) === 1 ? '' : 's'), 'priority' => 1];
    if ($diff < 0) return ['key' => 'chamar', 'label' => 'Hora de chamar', 'priority' => 2];
    if ($diff === 0) return ['key' => 'hoje', 'label' => 'Chamar hoje', 'priority' => 3];
    if ($diff === 1) return ['key' => 'amanha', 'label' => 'Chamar amanha', 'priority' => 4];
    if ($diff <= 7) return ['key' => 'em_breve', 'label' => 'Chamar em ' . $diff . ' dias', 'priority' => 5];

    return ['key' => 'em_dia', 'label' => 'Em dia', 'priority' => 8];
}

function retorno_manual_whatsapp_message(array $row): string
{
    $name = radar_first_name($row['cliente_nome'] ?? 'cliente');
    $professional = $row['profissional_nome'] ?? 'profissional';

    return "Olá, {$name}! Tudo bem?\n\nJá está chegando o período ideal para renovar seu corte com {$professional}. Quer que eu veja uma boa opção de horário para você?";
}

function retorno_manual_fetch(mysqli $conn, array $options = []): array
{
    radar_ensure_schema($conn);

    $sql = "
        SELECT rm.*, p.nome AS profissional_nome
        FROM retorno_manual rm
        INNER JOIN profissionais p ON p.id = rm.profissional_id
        WHERE rm.ativo = 1
    ";
    $params = [];
    $types = '';

    if (!empty($options['profissional_id'])) {
        $sql .= " AND rm.profissional_id = ?";
        $params[] = (int)$options['profissional_id'];
        $types .= 'i';
    }

    if (!empty($options['q'])) {
        $q = '%' . $options['q'] . '%';
        $sql .= " AND (rm.cliente_nome LIKE ? OR rm.cliente_telefone LIKE ? OR p.nome LIKE ?)";
        array_push($params, $q, $q, $q);
        $types .= 'sss';
    }

    $sql .= " ORDER BY rm.ultimo_atendimento DESC, rm.cliente_nome ASC LIMIT 500";
    $stmt = $conn->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();

    $items = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $target = retorno_manual_target($row);
        $state = retorno_manual_state($row);
        $row['data_corte_prevista'] = $target['cut_date'];
        $row['data_aviso'] = $target['notify_date'];
        $row['estado_key'] = $state['key'];
        $row['estado_label'] = $state['label'];
        $row['prioridade'] = $state['priority'];
        $row['whatsapp_phone'] = telefoneWhatsapp($row['cliente_telefone'] ?? '');
        $row['whatsapp_message'] = retorno_manual_whatsapp_message($row);

        if (!empty($options['state']) && $options['state'] !== 'todos') {
            $stateFilter = $options['state'];
            if ($stateFilter === 'pendentes' && !in_array($row['estado_key'], ['atrasado','chamar','hoje'], true)) continue;
            if ($stateFilter !== 'pendentes' && $stateFilter !== $row['estado_key']) continue;
        }

        $items[] = $row;
    }

    usort($items, fn($a, $b) => [$a['prioridade'], $a['data_aviso'], $a['cliente_nome']] <=> [$b['prioridade'], $b['data_aviso'], $b['cliente_nome']]);
    return array_slice($items, 0, (int)($options['limit'] ?? 100));
}

function retorno_manual_summary(array $items): array
{
    $summary = ['pendentes' => 0, 'hoje' => 0, 'em_breve' => 0, 'em_dia' => 0, 'atrasado' => 0];
    foreach ($items as $item) {
        if (in_array($item['estado_key'], ['atrasado','chamar','hoje'], true)) $summary['pendentes']++;
        if (isset($summary[$item['estado_key']])) $summary[$item['estado_key']]++;
    }
    return $summary;
}
