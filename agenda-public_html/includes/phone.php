<?php

function normalizarTelefoneCliente(string $telefone): array
{
    $digits = preg_replace('/\D+/', '', trim($telefone));

    if ($digits === '') {
        return [
            'valid' => false,
            'display_phone' => '',
            'whatsapp_phone' => '',
            'error' => 'Informe um WhatsApp válido.'
        ];
    }

    while (strlen($digits) > 13 && substr($digits, 0, 4) === '5555') {
        $digits = substr($digits, 2);
    }

    if (substr($digits, 0, 2) === '55') {
        $national = substr($digits, 2);
    } elseif (strlen($digits) === 10 || strlen($digits) === 11) {
        $national = $digits;
        $digits = '55' . $digits;
    } else {
        return [
            'valid' => false,
            'display_phone' => '',
            'whatsapp_phone' => '',
            'error' => 'O WhatsApp deve conter DDD e número completo.'
        ];
    }

    if (!in_array(strlen($national), [10, 11], true)) {
        return [
            'valid' => false,
            'display_phone' => '',
            'whatsapp_phone' => '',
            'error' => 'O WhatsApp deve conter DDD e número completo.'
        ];
    }

    $ddd = substr($national, 0, 2);
    $subscriber = substr($national, 2);

    if ((int)$ddd < 11 || strlen($subscriber) < 8) {
        return [
            'valid' => false,
            'display_phone' => '',
            'whatsapp_phone' => '',
            'error' => 'Informe um WhatsApp com DDD válido.'
        ];
    }

    $displayNumber = strlen($subscriber) === 9
        ? substr($subscriber, 0, 5) . '-' . substr($subscriber, 5)
        : substr($subscriber, 0, 4) . '-' . substr($subscriber, 4);

    return [
        'valid' => true,
        'display_phone' => '+55 (' . $ddd . ') ' . $displayNumber,
        'whatsapp_phone' => '55' . $national,
        'error' => ''
    ];
}

function limparTelefone(string $telefone): string
{
    $normalizado = normalizarTelefoneCliente($telefone);
    return $normalizado['valid'] ? $normalizado['whatsapp_phone'] : '';
}

function formatarTelefoneExibicao(string $telefone): string
{
    $normalizado = normalizarTelefoneCliente($telefone);
    return $normalizado['valid'] ? $normalizado['display_phone'] : trim($telefone);
}

function telefoneWhatsapp(string $telefone): string
{
    return limparTelefone($telefone);
}

function buscarClientePorWhatsapp(mysqli $conn, string $whatsappPhone, int $ignorarClienteId = 0): ?array
{
    $whatsappPhone = limparTelefone($whatsappPhone);

    if ($whatsappPhone === '') {
        return null;
    }

    $res = $conn->query("SELECT id, nome, telefone, email FROM clientes ORDER BY id ASC");

    while ($res && $cliente = $res->fetch_assoc()) {
        $clienteId = (int)$cliente['id'];

        if ($ignorarClienteId > 0 && $clienteId === $ignorarClienteId) {
            continue;
        }

        if (telefoneWhatsapp($cliente['telefone'] ?? '') === $whatsappPhone) {
            return $cliente;
        }
    }

    return null;
}

function obterOuCriarClientePorWhatsapp(mysqli $conn, string $nome, string $telefone, string $email = '', string $senhaHash = ''): array
{
    $normalizado = normalizarTelefoneCliente($telefone);

    if (!$normalizado['valid']) {
        return ['ok' => false, 'id' => 0, 'cliente' => null, 'created' => false, 'error' => $normalizado['error']];
    }

    $nome = trim($nome);
    $email = function_exists('normalize_email') ? normalize_email($email) : strtolower(trim($email));
    $cliente = buscarClientePorWhatsapp($conn, $normalizado['whatsapp_phone']);

    if ($cliente) {
        $clienteId = (int)$cliente['id'];
        $nomeFinal = $nome !== '' ? $nome : (string)($cliente['nome'] ?? '');
        $telefoneFinal = $normalizado['display_phone'];

        if ($email !== '' && $senhaHash !== '') {
            $stmt = $conn->prepare("UPDATE clientes SET nome = ?, telefone = ?, email = ?, senha = ? WHERE id = ? LIMIT 1");
            $stmt->bind_param('ssssi', $nomeFinal, $telefoneFinal, $email, $senhaHash, $clienteId);
        } elseif ($email !== '') {
            $stmt = $conn->prepare("UPDATE clientes SET nome = ?, telefone = ?, email = ? WHERE id = ? LIMIT 1");
            $stmt->bind_param('sssi', $nomeFinal, $telefoneFinal, $email, $clienteId);
        } elseif ($senhaHash !== '') {
            $stmt = $conn->prepare("UPDATE clientes SET nome = ?, telefone = ?, senha = ? WHERE id = ? LIMIT 1");
            $stmt->bind_param('sssi', $nomeFinal, $telefoneFinal, $senhaHash, $clienteId);
        } else {
            $stmt = $conn->prepare("UPDATE clientes SET nome = ?, telefone = ? WHERE id = ? LIMIT 1");
            $stmt->bind_param('ssi', $nomeFinal, $telefoneFinal, $clienteId);
        }

        $stmt->execute();

        return ['ok' => true, 'id' => $clienteId, 'cliente' => $cliente, 'created' => false, 'error' => ''];
    }

    if ($senhaHash !== '' || $email !== '') {
        $stmt = $conn->prepare("INSERT INTO clientes (nome, telefone, email, senha) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('ssss', $nome, $normalizado['display_phone'], $email, $senhaHash);
    } else {
        $stmt = $conn->prepare("INSERT INTO clientes (nome, telefone) VALUES (?, ?)");
        $stmt->bind_param('ss', $nome, $normalizado['display_phone']);
    }

    if (!$stmt->execute()) {
        return ['ok' => false, 'id' => 0, 'cliente' => null, 'created' => false, 'error' => 'Não foi possível cadastrar o cliente agora.'];
    }

    return ['ok' => true, 'id' => (int)$stmt->insert_id, 'cliente' => null, 'created' => true, 'error' => ''];
}

function normalizarTelefonesClientesExistentes(mysqli $conn): int
{
    $corrigidos = 0;
    $res = $conn->query("SELECT id, telefone FROM clientes");

    if (!$res || $res->num_rows === 0) {
        return 0;
    }

    $stmt = $conn->prepare("UPDATE clientes SET telefone = ? WHERE id = ? LIMIT 1");

    while ($row = $res->fetch_assoc()) {
        $normalizado = normalizarTelefoneCliente($row['telefone'] ?? '');

        if (!$normalizado['valid']) {
            continue;
        }

        $stmtDup = $conn->prepare("SELECT id FROM clientes WHERE telefone = ? AND id <> ? LIMIT 1");
        $id = (int)$row['id'];
        $display = $normalizado['display_phone'];
        $stmtDup->bind_param('si', $display, $id);
        $stmtDup->execute();
        $resDup = $stmtDup->get_result();

        if ($resDup && $resDup->num_rows > 0) {
            continue;
        }

        if (($row['telefone'] ?? '') === $normalizado['display_phone']) {
            continue;
        }

        $stmt->bind_param('si', $display, $id);

        try {
            $executou = $stmt->execute();
        } catch (Throwable $e) {
            $executou = false;
        }

        if ($executou) {
            $corrigidos++;
        }
    }

    return $corrigidos;
}
