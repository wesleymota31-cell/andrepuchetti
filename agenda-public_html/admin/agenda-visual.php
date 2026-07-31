<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-shell.php';
require_once __DIR__ . '/../includes/phone.php';
require_once __DIR__ . '/../includes/radar.php';

date_default_timezone_set('America/Sao_Paulo');

/**
 * =========================
 * HELPERS
 * =========================
 */
function redirectAgendaVisual(string $data, string $profissionalId, string $flash, string $msg): void
{
    header(
        'Location: agenda-visual.php?data=' . urlencode($data) .
        '&profissional_id=' . urlencode($profissionalId) .
        '&flash=' . urlencode($flash) .
        '&msg=' . urlencode($msg)
    );
    exit;
}

function respondJson(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function normalizePhone(string $phone): string
{
    return preg_replace('/\D+/', '', $phone ?? '');
}

function timeToMinutes(string $time): int
{
    $time = substr($time, 0, 5);
    [$h, $m] = explode(':', $time);
    return ((int)$h * 60) + (int)$m;
}

function minutesToTime(int $minutes): string
{
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;

    return str_pad((string)$hours, 2, '0', STR_PAD_LEFT) . ':' .
           str_pad((string)$mins, 2, '0', STR_PAD_LEFT) . ':00';
}

function minutosDesdeInicio($hora, $inicioAgenda): int
{
    $partes = explode(':', substr($hora, 0, 5));
    $h = (int)$partes[0];
    $m = (int)$partes[1];
    return (($h - $inicioAgenda) * 60) + $m;
}

function formatarTelefoneWhatsapp($telefone): string
{
    $numero = preg_replace('/\D+/', '', $telefone);
    if ($numero === '') return '';
    if (strlen($numero) === 11 || strlen($numero) === 10) return '55' . $numero;
    return $numero;
}

function servicoPrecisaAnaliseAgendaVisual(string $nome): bool
{
    $nome = strtolower(trim($nome));
    $nome = strtr($nome, [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
        'é' => 'e', 'ê' => 'e',
        'í' => 'i',
        'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u',
        'ç' => 'c',
    ]);

    return strpos($nome, 'botox feminino') !== false
        || strpos($nome, 'progressiva feminino') !== false
        || strpos($nome, 'platinado feminino') !== false
        || strpos($nome, 'escova feminino') !== false
        || strpos($nome, 'hidratacao feminino') !== false
        || strpos($nome, 'hidratação feminino') !== false;
}

function labelServicoAgendaVisual(array $servico): string
{
    $label = $servico['nome'] . ' — ' . (int)$servico['duracao'] . 'min';

    if (servicoPrecisaAnaliseAgendaVisual($servico['nome'])) {
        return $label . ' — Valor após análise';
    }

    return $label . ' — R$ ' . number_format((float)$servico['preco'], 2, ',', '.');
}

function horasDisponiveis(int $inicio = 7, int $fim = 22): array
{
    $horarios = [];

    for ($min = ($inicio * 60) + 30; $min <= $fim * 60; $min += 30) {
        $h = floor($min / 60);
        $m = $min % 60;
        $horarios[] = str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
    }

    return $horarios;
}

function getTableColumns(mysqli $conn, string $table): array
{
    $cols = [];
    $res = $conn->query("SHOW COLUMNS FROM `{$table}`");
    if ($res && $res->num_rows > 0) {
        while ($row = $res->fetch_assoc()) {
            $cols[] = $row['Field'];
        }
    }
    return $cols;
}

function findFirstExistingColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}


function getVisibleBlockSegments(array $bloqueio, array $agendamentosDoProfissional): array
{
    $bloqueioInicio = timeToMinutes($bloqueio['hora_inicio']);
    $bloqueioFim = timeToMinutes($bloqueio['hora_fim']);

    if ($bloqueioFim <= $bloqueioInicio) {
        return [];
    }

    $ocupacoes = [];

    foreach ($agendamentosDoProfissional as $agendamento) {
        $agInicio = timeToMinutes($agendamento['hora']);
        $agFim = !empty($agendamento['hora_fim'])
            ? timeToMinutes($agendamento['hora_fim'])
            : ($agInicio + max(30, (int)($agendamento['servico_duracao'] ?? 30)));

        $inicioSobreposto = max($bloqueioInicio, $agInicio);
        $fimSobreposto = min($bloqueioFim, $agFim);

        if ($inicioSobreposto < $fimSobreposto) {
            $ocupacoes[] = [$inicioSobreposto, $fimSobreposto];
        }
    }

    if (empty($ocupacoes)) {
        return [[
            'hora_inicio' => $bloqueio['hora_inicio'],
            'hora_fim' => $bloqueio['hora_fim'],
            'inicio_minutos' => $bloqueioInicio,
            'fim_minutos' => $bloqueioFim,
        ]];
    }

    usort($ocupacoes, function ($a, $b) {
        return $a[0] <=> $b[0];
    });

    $ocupacoesMescladas = [];
    foreach ($ocupacoes as $ocupacao) {
        if (empty($ocupacoesMescladas) || $ocupacao[0] > $ocupacoesMescladas[count($ocupacoesMescladas) - 1][1]) {
            $ocupacoesMescladas[] = $ocupacao;
        } else {
            $lastIndex = count($ocupacoesMescladas) - 1;
            $ocupacoesMescladas[$lastIndex][1] = max($ocupacoesMescladas[$lastIndex][1], $ocupacao[1]);
        }
    }

    $segmentos = [];
    $cursor = $bloqueioInicio;

    foreach ($ocupacoesMescladas as $ocupacao) {
        if ($cursor < $ocupacao[0]) {
            $segmentos[] = [
                'hora_inicio' => minutesToTime($cursor),
                'hora_fim' => minutesToTime($ocupacao[0]),
                'inicio_minutos' => $cursor,
                'fim_minutos' => $ocupacao[0],
            ];
        }

        $cursor = max($cursor, $ocupacao[1]);
    }

    if ($cursor < $bloqueioFim) {
        $segmentos[] = [
            'hora_inicio' => minutesToTime($cursor),
            'hora_fim' => minutesToTime($bloqueioFim),
            'inicio_minutos' => $cursor,
            'fim_minutos' => $bloqueioFim,
        ];
    }

    return $segmentos;
}

function mergeBlockingIntervalsForDisplay(array $bloqueios): array
{
    if (empty($bloqueios)) {
        return [];
    }

    usort($bloqueios, function ($a, $b) {
        $cmp = timeToMinutes($a['hora_inicio']) <=> timeToMinutes($b['hora_inicio']);
        if ($cmp !== 0) return $cmp;
        return timeToMinutes($a['hora_fim']) <=> timeToMinutes($b['hora_fim']);
    });

    $merged = [];

    foreach ($bloqueios as $bloqueio) {
        if (empty($merged)) {
            $merged[] = $bloqueio;
            continue;
        }

        $lastIndex = count($merged) - 1;
        $lastEnd = timeToMinutes($merged[$lastIndex]['hora_fim']);
        $currentStart = timeToMinutes($bloqueio['hora_inicio']);
        $currentEnd = timeToMinutes($bloqueio['hora_fim']);

        if ($currentStart <= $lastEnd) {
            if ($currentEnd > $lastEnd) {
                $merged[$lastIndex]['hora_fim'] = $bloqueio['hora_fim'];
            }

            $merged[$lastIndex]['is_recorrente'] = (
                (int)($merged[$lastIndex]['is_recorrente'] ?? 0) === 1 ||
                (int)($bloqueio['is_recorrente'] ?? 0) === 1
            ) ? 1 : 0;

            if (($bloqueio['tipo_origem'] ?? '') === 'recorrente') {
                $merged[$lastIndex]['tipo_origem'] = 'recorrente';
                $merged[$lastIndex]['id'] = $bloqueio['id'];
            }

            continue;
        }

        $merged[] = $bloqueio;
    }

    return $merged;
}

/**
 * =========================
 * AJAX - BUSCAR CLIENTE
 * =========================
 */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'buscar_cliente') {
    $telefone = normalizePhone($_GET['telefone'] ?? '');
    $query = trim($_GET['q'] ?? '');

    if ($query !== '') {
        $digits = normalizePhone($query);

        if (strlen($query) < 2 && strlen($digits) < 3) {
            respondJson([
                'ok' => true,
                'matches' => [],
            ]);
        }

        $likeNome = '%' . $query . '%';
        $likeTelefone = '%' . $digits . '%';
        $prefixNome = $query . '%';

        $stmtClientes = $conn->prepare("
            SELECT id, nome, telefone
            FROM clientes
            WHERE nome LIKE ?
               OR (? <> '' AND telefone LIKE ?)
            ORDER BY
                CASE WHEN nome LIKE ? THEN 0 ELSE 1 END,
                nome ASC
            LIMIT 8
        ");
        $stmtClientes->bind_param('ssss', $likeNome, $digits, $likeTelefone, $prefixNome);
        $stmtClientes->execute();
        $resClientes = $stmtClientes->get_result();

        $matches = [];
        if ($resClientes && $resClientes->num_rows > 0) {
            while ($row = $resClientes->fetch_assoc()) {
                $matches[] = [
                    'id' => (int)$row['id'],
                    'nome' => $row['nome'],
                    'telefone' => $row['telefone'],
                ];
            }
        }

        respondJson([
            'ok' => true,
            'matches' => $matches,
        ]);
    }

    if ($telefone === '' || strlen($telefone) < 10) {
        respondJson([
            'ok' => true,
            'found' => false,
        ]);
    }

    $stmtCliente = $conn->prepare("
        SELECT id, nome, telefone
        FROM clientes
        WHERE telefone = ?
        LIMIT 1
    ");
    $stmtCliente->bind_param('s', $telefone);
    $stmtCliente->execute();
    $cliente = $stmtCliente->get_result()->fetch_assoc();

    if (!$cliente) {
        respondJson([
            'ok' => true,
            'found' => false,
        ]);
    }

    $clienteId = (int)$cliente['id'];

    $stmtUltimo = $conn->prepare("
        SELECT
            p.nome AS profissional_nome,
            ag.data,
            ag.hora
        FROM agendamentos ag
        INNER JOIN profissionais p ON p.id = ag.profissional_id
        WHERE ag.cliente_id = ?
          AND ag.status IN ('confirmado', 'pendente')
        ORDER BY ag.data DESC, ag.hora DESC
        LIMIT 1
    ");
    $stmtUltimo->bind_param('i', $clienteId);
    $stmtUltimo->execute();
    $ultimo = $stmtUltimo->get_result()->fetch_assoc();

    respondJson([
        'ok' => true,
        'found' => true,
        'cliente' => [
            'id' => $clienteId,
            'nome' => $cliente['nome'],
            'telefone' => $cliente['telefone'],
        ],
        'ultimo_profissional' => $ultimo ? [
            'nome' => $ultimo['profissional_nome'],
            'data' => $ultimo['data'],
            'hora' => substr($ultimo['hora'], 0, 5),
        ] : null,
    ]);
}

/**
 * =========================
 * PROCESSAMENTO DO POST
 * =========================
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'agendamento_rapido') {
    try {
    $profissionalId = (int)($_POST['profissional_id'] ?? 0);
    $servicoId = (int)($_POST['servico_id'] ?? 0);
    $dataPost = trim($_POST['data'] ?? '');
    $hora = trim($_POST['hora'] ?? '');
    $horaFim = trim($_POST['hora_fim'] ?? '');
    $clienteSelecionadoId = (int)($_POST['cliente_id'] ?? 0);
    $nome = trim($_POST['nome'] ?? '');
    $telefone = normalizePhone($_POST['telefone'] ?? '');
    $returnData = trim($_POST['return_data'] ?? $dataPost);
    $returnProfissionalId = trim($_POST['return_profissional_id'] ?? (string)$profissionalId);
    $overrideBloqueioPost = (int)($_POST['override_bloqueio'] ?? 0);
    $bloqueioIdPost = (int)($_POST['bloqueio_id'] ?? 0);

    if (
        $profissionalId <= 0 ||
        $servicoId <= 0 ||
        $dataPost === '' ||
        $hora === '' ||
        $horaFim === '' ||
        $nome === '' ||
        ($telefone === '' && $clienteSelecionadoId <= 0)
    ) {
        redirectAgendaVisual($returnData, $returnProfissionalId, 'erro', 'Preencha serviço, nome, WhatsApp, hora inicial e hora final.');
    }

    if (!function_exists('podeEditarProfissional') || !podeEditarProfissional($profissionalId)) {
        redirectAgendaVisual($returnData, $returnProfissionalId, 'erro', 'Você não tem permissão para agendar para este profissional.');
    }

    $validDate = DateTime::createFromFormat('Y-m-d', $dataPost);
    $validTime = DateTime::createFromFormat('H:i', $hora);
    $validTimeEnd = DateTime::createFromFormat('H:i', $horaFim);

    if (
        !$validDate || $validDate->format('Y-m-d') !== $dataPost ||
        !$validTime || $validTime->format('H:i') !== $hora ||
        !$validTimeEnd || $validTimeEnd->format('H:i') !== $horaFim
    ) {
        redirectAgendaVisual($returnData, $returnProfissionalId, 'erro', 'Data ou horário inválidos.');
    }

    $inicioMin = timeToMinutes($hora);
    $fimMin = timeToMinutes($horaFim);

    if ($fimMin <= $inicioMin) {
        redirectAgendaVisual($returnData, $returnProfissionalId, 'erro', 'A hora final precisa ser maior que a hora inicial.');
    }

    if ($inicioMin < (7 * 60) || $fimMin > (22 * 60)) {
        redirectAgendaVisual($returnData, $returnProfissionalId, 'erro', 'O horário precisa estar dentro do intervalo da agenda.');
    }

    $now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
    $bookingDateTime = DateTime::createFromFormat('Y-m-d H:i', $dataPost . ' ' . $hora, new DateTimeZone('America/Sao_Paulo'));

    if (!$bookingDateTime || $bookingDateTime < $now) {
        redirectAgendaVisual($returnData, $returnProfissionalId, 'erro', 'Não é possível agendar em um horário que já passou.');
    }

    $stmtProf = $conn->prepare('SELECT id, nome FROM profissionais WHERE id = ? LIMIT 1');
    $stmtProf->bind_param('i', $profissionalId);
    $stmtProf->execute();
    $profissional = $stmtProf->get_result()->fetch_assoc();

    if (!$profissional) {
        redirectAgendaVisual($returnData, $returnProfissionalId, 'erro', 'Profissional não encontrado.');
    }

    $stmtServico = $conn->prepare('SELECT id, nome, duracao, preco FROM servicos WHERE id = ? LIMIT 1');
    $stmtServico->bind_param('i', $servicoId);
    $stmtServico->execute();
    $servico = $stmtServico->get_result()->fetch_assoc();

    if (!$servico) {
        redirectAgendaVisual($returnData, $returnProfissionalId, 'erro', 'Serviço não encontrado.');
    }

    $horaInicioBanco = minutesToTime($inicioMin);
    $horaFimBanco = minutesToTime($fimMin);

    /**
     * =========================
     * BLOQUEIOS (com exceção para profissional/admin quando permitido)
     * =========================
     */
    $bloqueiosColumnsAgenda = getTableColumns($conn, 'bloqueios');
    $colunaPermiteProfissionalAgenda = findFirstExistingColumn($bloqueiosColumnsAgenda, [
        'permite_profissional',
        'allow_professional_booking',
        'permite_agendamento_profissional',
        'libera_profissional',
        'somente_profissional'
    ]);

    $sqlBloqueio = "
        SELECT id,
               " . ($colunaPermiteProfissionalAgenda ? "`{$colunaPermiteProfissionalAgenda}`" : "0") . " AS permite_profissional
        FROM bloqueios
        WHERE profissional_id = ?
          AND data = ?
          AND hora_inicio < ?
          AND hora_fim > ?
        LIMIT 1
    ";

    $stmtBloqueio = $conn->prepare($sqlBloqueio);
    $stmtBloqueio->bind_param('isss', $profissionalId, $dataPost, $horaFimBanco, $horaInicioBanco);
    $stmtBloqueio->execute();
    $bloqueio = $stmtBloqueio->get_result()->fetch_assoc();

    /**
     * REGRA INTERNA DA AGENDA VISUAL:
     * Profissional/assistente/admin podem criar agendamento em cima de bloqueio.
     * O bloqueio continua existindo; o agendamento entra como exceção operacional.
     *
     * Importante: a permissão do usuário já foi validada acima com podeEditarProfissional().
     * Portanto, neste fluxo interno NÃO bloqueamos o salvamento por causa da tabela bloqueios.
     * A trava para cliente público deve ficar somente no index.php / fluxo público.
     */
    $isOverrideBloqueio = $overrideBloqueioPost === 1;
    $agendamentoSobreBloqueio = $bloqueio ? 1 : 0;

    /**
     * =========================
     * CONFLITO DE AGENDAMENTOS
     * =========================
     */
    $stmtConflito = $conn->prepare(
        "SELECT ag.id, ag.hora, ag.hora_fim, s.duracao AS servico_duracao
         FROM agendamentos ag
         INNER JOIN servicos s ON s.id = ag.servico_id
         WHERE ag.profissional_id = ?
           AND ag.data = ?
           AND ag.status IN ('confirmado', 'pendente')"
    );
    $stmtConflito->bind_param('is', $profissionalId, $dataPost);
    $stmtConflito->execute();
    $resConflito = $stmtConflito->get_result();

    while ($ag = $resConflito->fetch_assoc()) {
        $agInicio = timeToMinutes($ag['hora']);
        $agFim = !empty($ag['hora_fim'])
            ? timeToMinutes($ag['hora_fim'])
            : ($agInicio + max(15, (int)($ag['servico_duracao'] ?? 30)));

        $hasConflict = ($inicioMin < $agFim) && ($fimMin > $agInicio);
        if ($hasConflict) {
            redirectAgendaVisual($returnData, $returnProfissionalId, 'erro', 'Já existe um agendamento conflitante nesse período.');
        }
    }

    /**
     * =========================
     * CLIENTE
     * =========================
     */
    $clienteId = 0;

    if ($clienteSelecionadoId > 0) {
        $stmtClienteSelecionado = $conn->prepare('SELECT id, nome, telefone FROM clientes WHERE id = ? LIMIT 1');
        $stmtClienteSelecionado->bind_param('i', $clienteSelecionadoId);
        $stmtClienteSelecionado->execute();
        $clienteSelecionado = $stmtClienteSelecionado->get_result()->fetch_assoc();

        if ($clienteSelecionado) {
            $clienteId = (int)$clienteSelecionado['id'];
            $nomeFinal = $nome !== '' ? $nome : (string)$clienteSelecionado['nome'];

            if ($telefone !== '') {
                $telefoneNormalizado = normalizarTelefoneCliente($telefone);
                if ($telefoneNormalizado['valid']) {
                    $telefoneFinal = $telefoneNormalizado['display_phone'];
                    $stmtTelefoneUso = $conn->prepare('SELECT id FROM clientes WHERE telefone = ? AND id <> ? LIMIT 1');
                    $stmtTelefoneUso->bind_param('si', $telefoneFinal, $clienteId);
                    $stmtTelefoneUso->execute();
                    $telefoneJaUsado = $stmtTelefoneUso->get_result()->fetch_assoc();

                    if ($telefoneJaUsado) {
                        $stmtUpdateCliente = $conn->prepare('UPDATE clientes SET nome = ? WHERE id = ? LIMIT 1');
                        $stmtUpdateCliente->bind_param('si', $nomeFinal, $clienteId);
                    } else {
                        $stmtUpdateCliente = $conn->prepare('UPDATE clientes SET nome = ?, telefone = ? WHERE id = ? LIMIT 1');
                        $stmtUpdateCliente->bind_param('ssi', $nomeFinal, $telefoneFinal, $clienteId);
                    }

                    $stmtUpdateCliente->execute();
                } else {
                    $stmtUpdateCliente = $conn->prepare('UPDATE clientes SET nome = ? WHERE id = ? LIMIT 1');
                    $stmtUpdateCliente->bind_param('si', $nomeFinal, $clienteId);
                    $stmtUpdateCliente->execute();
                }
            } else {
                $stmtUpdateCliente = $conn->prepare('UPDATE clientes SET nome = ? WHERE id = ? LIMIT 1');
                $stmtUpdateCliente->bind_param('si', $nomeFinal, $clienteId);
                $stmtUpdateCliente->execute();
            }
        }
    }

    if ($clienteId <= 0) {
        $clienteResult = obterOuCriarClientePorWhatsapp($conn, $nome, $telefone);

        if (!$clienteResult['ok']) {
            redirectAgendaVisual($returnData, $returnProfissionalId, 'erro', $clienteResult['error'] ?: 'Não foi possível cadastrar o cliente agora.');
        }

        $clienteId = (int)$clienteResult['id'];
    }

    /**
     * =========================
     * SALVAR AGENDAMENTO
     * =========================
     */
    $status = 'confirmado';

    $stmtInsertAgendamento = $conn->prepare(
        'INSERT INTO agendamentos (cliente_id, profissional_id, servico_id, data, hora, hora_fim, status)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmtInsertAgendamento->bind_param(
        'iiissss',
        $clienteId,
        $profissionalId,
        $servicoId,
        $dataPost,
        $horaInicioBanco,
        $horaFimBanco,
        $status
    );

    if (!$stmtInsertAgendamento->execute()) {
        redirectAgendaVisual($returnData, $returnProfissionalId, 'erro', 'Não foi possível salvar o agendamento agora.');
    }

    redirectAgendaVisual(
        $returnData,
        $returnProfissionalId,
        'sucesso',
        'Agendamento criado com sucesso para ' . $nome . '.'
    );
    } catch (Throwable $e) {
        $erroTecnico = 'Erro agenda visual agendamento rapido: ' . $e->getMessage();
        error_log($erroTecnico);
        @file_put_contents(__DIR__ . '/../agenda-visual-errors.log', '[' . date('Y-m-d H:i:s') . '] ' . $erroTecnico . PHP_EOL, FILE_APPEND);
        $fallbackData = trim($_POST['return_data'] ?? $_POST['data'] ?? date('Y-m-d'));
        $fallbackProfissional = trim($_POST['return_profissional_id'] ?? $_POST['profissional_id'] ?? 'todos');
        redirectAgendaVisual($fallbackData, $fallbackProfissional, 'erro', 'Não foi possível salvar o agendamento agora. Detalhe: ' . $e->getMessage());
    }
}

/**
 * =========================
 * CARREGAMENTO DA TELA
 * =========================
 */
$data = $_GET['data'] ?? date('Y-m-d');
$profissionalFiltro = $_GET['profissional_id'] ?? (
    usuarioEhProfissional() && usuarioProfissionalId() !== null
        ? (string)usuarioProfissionalId()
        : 'todos'
);
$flash = $_GET['flash'] ?? '';
$msg = $_GET['msg'] ?? '';

$abrirBloqueioAgendamento = isset($_GET['abrir_bloqueio_agendamento']) && $_GET['abrir_bloqueio_agendamento'] == '1';
$overrideBloqueioGet = isset($_GET['override_bloqueio']) && $_GET['override_bloqueio'] == '1';
$bloqueioIdGet = (int)($_GET['bloqueio_id'] ?? 0);
$bloqueioHora = trim($_GET['hora'] ?? '');
$bloqueioHoraFim = trim($_GET['hora_fim'] ?? '');

$timestamp = strtotime($data);
if (!$timestamp) {
    $data = date('Y-m-d');
    $timestamp = strtotime($data);
}

$dataAnterior = date('Y-m-d', strtotime('-1 day', $timestamp));
$proximaData = date('Y-m-d', strtotime('+1 day', $timestamp));

$mesAtual = (int)date('m', $timestamp);
$anoAtual = (int)date('Y', $timestamp);
$primeiroDiaMes = date('Y-m-01', $timestamp);
$primeiroDiaSemana = (int)date('w', strtotime($primeiroDiaMes));
$diasNoMes = (int)date('t', strtotime($primeiroDiaMes));

$nomeMeses = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];

$radarDiaContagem = [];
$radarProfissionalId = $profissionalFiltro !== 'todos' ? (int)$profissionalFiltro : (usuarioEhProfissional() ? usuarioProfissionalId() : null);
$radarMesItens = radar_fetch_items($conn, ['profissional_id' => $radarProfissionalId, 'limit' => 250]);
foreach ($radarMesItens as $radarItem) {
    $radarData = !empty($radarItem['adiado_para']) && $radarItem['adiado_para'] > date('Y-m-d')
        ? $radarItem['adiado_para']
        : $radarItem['previsao_data'];
    if (date('Y-m', strtotime($radarData)) !== sprintf('%04d-%02d', $anoAtual, $mesAtual)) {
        continue;
    }
    $radarDiaContagem[$radarData] = ($radarDiaContagem[$radarData] ?? 0) + 1;
}

$inicioAgenda = 7;
$fimAgenda = 22;
$slotAltura = 54;
$totalSlots = ($fimAgenda - $inicioAgenda) * 2;
$alturaTimeline = $totalSlots * $slotAltura;

$profissionais = [];
$resProf = $conn->query("SELECT id, nome FROM profissionais ORDER BY id ASC");
if ($resProf && $resProf->num_rows > 0) {
    while ($row = $resProf->fetch_assoc()) {
        $profissionais[] = $row;
    }
}

$servicos = [];
$resServicos = $conn->query("SELECT id, nome, duracao, preco FROM servicos ORDER BY nome ASC");
if ($resServicos && $resServicos->num_rows > 0) {
    while ($row = $resServicos->fetch_assoc()) {
        $servicos[] = $row;
    }
}

$profissionaisExibidos = $profissionais;
if ($profissionalFiltro !== 'todos') {
    $profissionaisExibidos = array_values(array_filter($profissionais, function ($p) use ($profissionalFiltro) {
        return (string)$p['id'] === (string)$profissionalFiltro;
    }));
}

$agendamentos = [];
$sqlAg = "
    SELECT 
        ag.id,
        ag.profissional_id,
        ag.data,
        ag.hora,
        ag.hora_fim,
        ag.status,
        ag.is_recorrente,
        c.nome AS cliente_nome,
        c.telefone AS cliente_telefone,
        s.nome AS servico_nome,
        s.preco AS servico_preco,
        s.duracao AS servico_duracao
    FROM agendamentos ag
    INNER JOIN clientes c ON ag.cliente_id = c.id
    INNER JOIN servicos s ON ag.servico_id = s.id
    WHERE ag.data = ?
      AND ag.status IN ('confirmado','pendente')
";
$params = [$data];
$types = 's';

if ($profissionalFiltro !== 'todos') {
    $sqlAg .= " AND ag.profissional_id = ? ";
    $params[] = (int)$profissionalFiltro;
    $types .= 'i';
}

$sqlAg .= " ORDER BY ag.hora ASC ";

$stmtAg = $conn->prepare($sqlAg);
$stmtAg->bind_param($types, ...$params);
$stmtAg->execute();
$resAg = $stmtAg->get_result();
if ($resAg && $resAg->num_rows > 0) {
    while ($row = $resAg->fetch_assoc()) {
        $agendamentos[] = $row;
    }
}

$bloqueiosColumns = getTableColumns($conn, 'bloqueios');
$colunaIsRecorrenteBloq = findFirstExistingColumn($bloqueiosColumns, ['is_recorrente']) ?: null;
$weekdayBanco = (int)date('N', strtotime($data));

$bloqueios = [];
$sqlBl = "
    SELECT id, profissional_id, data, hora_inicio, hora_fim,
           " . ($colunaIsRecorrenteBloq ? "`{$colunaIsRecorrenteBloq}`" : "0") . " AS is_recorrente,
           'pontual' AS tipo_origem
    FROM bloqueios
    WHERE data = ?
";
$paramsBl = [$data];
$typesBl = 's';

if ($profissionalFiltro !== 'todos') {
    $sqlBl .= " AND profissional_id = ? ";
    $paramsBl[] = (int)$profissionalFiltro;
    $typesBl .= 'i';
}

$sqlBl .= " ORDER BY hora_inicio ASC ";

$stmtBl = $conn->prepare($sqlBl);
$stmtBl->bind_param($typesBl, ...$paramsBl);
$stmtBl->execute();
$resBl = $stmtBl->get_result();
if ($resBl && $resBl->num_rows > 0) {
    while ($row = $resBl->fetch_assoc()) {
        $bloqueios[] = $row;
    }
}

$sqlBlRec = "
    SELECT
        id,
        profissional_id,
        ? AS data,
        hora_inicio,
        hora_fim,
        1 AS is_recorrente,
        'recorrente' AS tipo_origem
    FROM bloqueios_recorrentes
    WHERE ativo = 1
      AND data_inicio <= ?
      AND (data_fim IS NULL OR data_fim >= ?)
      AND FIND_IN_SET(?, dias_semana)
";
$paramsBlRec = [$data, $data, $data, (string)$weekdayBanco];
$typesBlRec = 'ssss';

if ($profissionalFiltro !== 'todos') {
    $sqlBlRec .= " AND profissional_id = ? ";
    $paramsBlRec[] = (int)$profissionalFiltro;
    $typesBlRec .= 'i';
}

$sqlBlRec .= " ORDER BY hora_inicio ASC ";

$stmtBlRec = $conn->prepare($sqlBlRec);
$stmtBlRec->bind_param($typesBlRec, ...$paramsBlRec);
$stmtBlRec->execute();
$resBlRec = $stmtBlRec->get_result();
if ($resBlRec && $resBlRec->num_rows > 0) {
    while ($row = $resBlRec->fetch_assoc()) {
        $bloqueios[] = $row;
    }
}

$listaHorarios = horasDisponiveis($inicioAgenda, $fimAgenda);

$blPorProf = [];
foreach ($bloqueios as $bl) {
    $blPorProf[$bl['profissional_id']][] = $bl;
}

foreach ($blPorProf as $profId => $bloqueiosProfissional) {
    $blPorProf[$profId] = mergeBlockingIntervalsForDisplay($bloqueiosProfissional);
}

foreach ($agendamentos as &$agRef) {
    $agRef['is_bloqueio_excecao'] = 0;
    if (empty($blPorProf[$agRef['profissional_id']])) {
        continue;
    }

    $agInicioCheck = timeToMinutes($agRef['hora']);
    $agFimCheck = !empty($agRef['hora_fim'])
        ? timeToMinutes($agRef['hora_fim'])
        : ($agInicioCheck + max(30, (int)$agRef['servico_duracao']));

    foreach ($blPorProf[$agRef['profissional_id']] as $blCheck) {
        if (($blCheck['data'] ?? '') !== $agRef['data']) {
            continue;
        }

        $blInicioCheck = timeToMinutes($blCheck['hora_inicio']);
        $blFimCheck = timeToMinutes($blCheck['hora_fim']);
        $temSobreposicaoBloqueio = ($agInicioCheck < $blFimCheck) && ($agFimCheck > $blInicioCheck);

        if ($temSobreposicaoBloqueio) {
            $agRef['is_bloqueio_excecao'] = 1;
            break;
        }
    }
}
unset($agRef);

$agPorProf = [];
foreach ($agendamentos as $ag) {
    $agPorProf[$ag['profissional_id']][] = $ag;
}

admin_shell_start('Agenda Visual | André Puchetti', 'agenda_visual');
?>
<style>
  :root{
    --gold-1:#f7e7a6;
    --gold-2:#d4af37;
    --bg:#090909;
    --text:#f7f3ea;
    --text-soft:rgba(247,243,234,.66);
  }

  .hero { margin-bottom: 18px; position: relative; }
  .hero::after{
    content:'';
    position:absolute;
    inset:auto 0 -14px 0;
    height:1px;
    background:linear-gradient(90deg, transparent, rgba(212,175,55,.18), transparent);
  }
  .hero h1 {
    margin: 0 0 12px;
    font-size: clamp(2rem, 4vw, 3.5rem);
    line-height: .92;
    letter-spacing: -.06em;
    font-weight: 900;
  }
  .hero h1 span {
    display:block;
    background: linear-gradient(90deg,#fff4cc 0%,#d4af37 45%,#fff0a8 100%);
    -webkit-background-clip:text;
    -webkit-text-fill-color:transparent;
    background-clip:text;
    color:transparent;
  }
  .hero p {
    margin:0;
    color:var(--text-soft);
    line-height:1.8;
    max-width:780px;
    font-size:1rem;
  }

  .top-filters {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    flex-wrap:wrap;
    margin: 20px 0 22px;
    padding: 16px;
    border-radius: 24px;
    background:
      linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.025)),
      radial-gradient(circle at top left, rgba(212,175,55,.08), transparent 42%);
    border: 1px solid rgba(255,255,255,.08);
    box-shadow:
      0 22px 60px rgba(0,0,0,.40),
      inset 0 1px 0 rgba(255,255,255,.04);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
  }

  .filter-group {
    display:flex;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
  }

  .filter-label {
    color:#f0d77a;
    font-size:11px;
    font-weight:900;
    letter-spacing:.18em;
    text-transform:uppercase;
  }

  .pro-select {
    min-height: 48px;
    min-width: 220px;
    border-radius: 16px;
    border: 1px solid rgba(255,255,255,.08);
    background: linear-gradient(180deg, rgba(255,255,255,.045), rgba(255,255,255,.03));
    color: var(--text);
    padding: 0 14px;
    outline: none;
    font-size: .98rem;
  }

  .flash-message {
    margin: 0 0 18px;
    padding: 14px 16px;
    border-radius: 16px;
    font-weight: 700;
    border: 1px solid rgba(255,255,255,.08);
    box-shadow: 0 18px 40px rgba(0,0,0,.22);
  }
  .flash-message.sucesso {
    background: rgba(32, 201, 151, 0.12);
    color: #b8ffe8;
    border-color: rgba(32, 201, 151, 0.28);
  }
  .flash-message.erro {
    background: rgba(255, 95, 109, 0.12);
    color: #ffd5da;
    border-color: rgba(255, 95, 109, 0.28);
  }

  .layout {
    display:grid;
    grid-template-columns: 255px 1fr;
    gap:18px;
    align-items:start;
  }

  .glass {
    background:
      linear-gradient(180deg, rgba(255,255,255,.055), rgba(255,255,255,.03)),
      radial-gradient(circle at top left, rgba(212,175,55,.06), transparent 36%);
    border:1px solid rgba(255,255,255,.08);
    box-shadow:
      0 22px 60px rgba(0,0,0,.38),
      inset 0 1px 0 rgba(255,255,255,.04);
    border-radius:26px;
    backdrop-filter:blur(18px);
    -webkit-backdrop-filter:blur(18px);
    overflow:hidden;
  }

  .sidebar-card { padding:16px; position:sticky; top:20px; }
  .side-title {
    margin:0 0 14px;
    font-size:.96rem;
    color:#f0d77a;
    letter-spacing:.16em;
    text-transform:uppercase;
    font-weight:900;
  }

  .month-bar {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:8px;
    margin-bottom:14px;
  }

  .month-label {
    font-weight:900;
    text-align:center;
    flex:1;
    letter-spacing:-.02em;
  }

  .month-nav,
  .quick-btn {
    text-decoration:none;
    color:#f7f3ea;
    background:rgba(255,255,255,.04);
    border:1px solid rgba(255,255,255,.08);
    transition:.22s ease;
  }

  .month-nav {
    width:36px;
    height:36px;
    border-radius:12px;
    display:grid;
    place-items:center;
    font-weight:900;
  }

  .weekdays,
  .month-grid {
    display:grid;
    grid-template-columns:repeat(7,1fr);
    gap:6px;
  }

  .weekday {
    text-align:center;
    font-size:10px;
    color:#f0d77a;
    letter-spacing:.16em;
    text-transform:uppercase;
    font-weight:900;
    padding:6px 0;
  }

  .day {
    min-height:38px;
    border-radius:14px;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:2px;
    text-decoration:none;
    color:rgba(247,243,234,.78);
    background:rgba(255,255,255,.03);
    border:1px solid rgba(255,255,255,.05);
    font-size:.92rem;
    transition:.2s ease;
  }

  .day.empty { visibility:hidden; }
  .day.active {
    background:linear-gradient(180deg, rgba(212,175,55,.18), rgba(255,255,255,.04));
    border-color:rgba(212,175,55,.35);
    color:#f0d77a;
    font-weight:900;
  }
  .day.today { outline:1px solid rgba(212,175,55,.18); }
  .return-count {
    min-width:17px;
    height:17px;
    border-radius:999px;
    display:inline-grid;
    place-items:center;
    background:rgba(50,145,255,.18);
    border:1px solid rgba(50,145,255,.30);
    color:#b9dcff;
    font-size:10px;
    line-height:1;
    font-weight:950;
  }

  .quick-nav { margin-top:18px; display:grid; gap:10px; }
  .quick-btn {
    min-height:44px;
    border-radius:14px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:800;
  }

  .main-card { padding:16px; }

  .toolbar {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:14px;
    flex-wrap:wrap;
    margin-bottom:16px;
  }

  .date-switch {
    display:flex;
    gap:10px;
    align-items:center;
    flex-wrap:wrap;
  }

  .date-pill {
    min-height:42px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:0 15px;
    border-radius:999px;
    background: linear-gradient(180deg, rgba(212,175,55,.10), rgba(255,255,255,.035));
    border:1px solid rgba(212,175,55,.20);
    color:#f0d77a;
    font-weight:900;
  }

  .legend {
    display:flex;
    gap:10px;
    flex-wrap:wrap;
  }

  .legend-item {
    display:inline-flex;
    align-items:center;
    gap:8px;
    color:rgba(247,243,234,.55);
    font-size:.88rem;
    padding:8px 12px;
    border-radius:999px;
    background:rgba(255,255,255,.03);
    border:1px solid rgba(255,255,255,.05);
  }

  .dot {
    width:11px;
    height:11px;
    border-radius:50%;
  }
  .dot.appointment { background:#20c997; }
  .dot.blocked { background:#9c9c9c; }
  .dot.canceled { background:#ff5f6d; }
  .dot.recurring { background:#d4af37; }

  .schedule-shell {
    overflow:auto;
    border-radius:22px;
    border:1px solid rgba(255,255,255,.08);
    background:
      linear-gradient(180deg, rgba(255,255,255,.025), rgba(255,255,255,.015)),
      linear-gradient(90deg, rgba(212,175,55,.03), transparent 18%);
  }

  .schedule {
    min-width: <?= count($profissionaisExibidos) <= 1 ? 470 : 820 ?>px;
    display:grid;
    grid-template-columns:88px repeat(<?= max(1, count($profissionaisExibidos)); ?>, minmax(260px,1fr));
    align-items:start;
  }

  .header-cell {
    position:sticky;
    top:0;
    z-index:4;
    min-height:72px;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:12px;
    border-bottom:1px solid rgba(255,255,255,.08);
    background:
      linear-gradient(180deg, rgba(12,12,12,.96), rgba(12,12,12,.88)),
      radial-gradient(circle at top left, rgba(212,175,55,.08), transparent 40%);
    backdrop-filter:blur(16px);
    -webkit-backdrop-filter:blur(16px);
    font-weight:900;
    color:#f7f3ea;
  }

  .header-time {
    color:#f0d77a;
    font-size:.78rem;
    letter-spacing:.18em;
    text-transform:uppercase;
  }

  .pro-head {
    flex-direction:column;
    gap:5px;
    position:relative;
  }

  .pro-head small {
    color:#f0d77a;
    letter-spacing:.16em;
    text-transform:uppercase;
    font-size:10px;
    font-weight:900;
  }

  .pro-head div{
    font-size:1rem;
    letter-spacing:-.02em;
  }

  .time-column,
  .pro-column {
    position:relative;
    height: <?= $alturaTimeline ?>px;
    border-right:1px solid rgba(255,255,255,.05);
  }

  .time-column{
    background: linear-gradient(180deg, rgba(255,255,255,.015), rgba(255,255,255,.02));
  }

  .pro-column {
    background:
      repeating-linear-gradient(
        to bottom,
        transparent 0,
        transparent <?= $slotAltura - 1 ?>px,
        rgba(255,255,255,.045) <?= $slotAltura - 1 ?>px,
        rgba(255,255,255,.045) <?= $slotAltura ?>px
      );
  }

  .time-label {
    position:absolute;
    left:0;
    width:100%;
    transform:translateY(-50%);
    text-align:center;
    font-weight:900;
    letter-spacing:-.02em;
  }

  .time-label.full{
    color:rgba(247,243,234,.88);
    font-size:.92rem;
  }

  .time-label.half{
    color:rgba(212,175,55,.88);
    font-size:.84rem;
  }

  .time-slot {
    position:absolute;
    left:8px;
    right:8px;
    border-radius:14px;
    border:1px dashed transparent;
    background:transparent;
    z-index:1;
    cursor:pointer;
    transition:.18s ease;
  }

  .time-slot:hover {
    background: linear-gradient(180deg, rgba(212,175,55,.09), rgba(212,175,55,.05));
    border-color:rgba(212,175,55,.20);
  }

  .time-slot::after {
    content:'+ Novo';
    position:absolute;
    right:12px;
    top:50%;
    transform:translateY(-50%);
    font-size:11px;
    font-weight:900;
    letter-spacing:.06em;
    color:rgba(240,215,122,0);
    transition:.18s ease;
  }

  .time-slot:hover::after {
    color:rgba(240,215,122,.95);
  }

  .event {
    position:absolute;
    left:10px;
    right:10px;
    border-radius:18px;
    padding:10px 12px;
    cursor:pointer;
    overflow:hidden;
    transition:.22s ease;
    border:1px solid rgba(255,255,255,.08);
    z-index:3;
  }

  .event:hover { transform:translateY(-2px); }

  .event.appointment {
    z-index:4;
    background:
      linear-gradient(180deg, rgba(32,201,151,.22), rgba(19,101,81,.18)),
      radial-gradient(circle at top left, rgba(255,255,255,.08), transparent 42%);
    border-color:rgba(32,201,151,.28);
    color:#eafff8;
    box-shadow:0 14px 30px rgba(0,0,0,.22);
  }

  .event.exception {
    background:
      linear-gradient(180deg, rgba(32,201,151,.24), rgba(18,92,75,.18)),
      radial-gradient(circle at top left, rgba(212,175,55,.16), transparent 42%);
    border-color: rgba(212,175,55,.38);
    color: #f4fff9;
    box-shadow:
      0 0 0 1px rgba(212,175,55,.16),
      0 14px 30px rgba(0,0,0,.22),
      0 0 22px rgba(32,201,151,.10);
  }

  .event.exception::before {
    content:'';
    position:absolute;
    left:0;
    top:0;
    bottom:0;
    width:4px;
    background:linear-gradient(180deg, #f4d76f, #20c997);
    border-radius:18px 0 0 18px;
  }

  .event-exception-badge {
    display:inline-flex;
    min-height:20px;
    align-items:center;
    justify-content:center;
    padding:0 8px;
    border-radius:999px;
    background:rgba(212,175,55,.18);
    color:#fff3c6;
    border:1px solid rgba(212,175,55,.30);
    font-size:8px;
    font-weight:900;
    letter-spacing:.10em;
    text-transform:uppercase;
    margin-bottom:5px;
    width:max-content;
    max-width:100%;
  }

  .event--compact.event.exception,
  .event--medium.event.exception {
    padding-right:44px;
  }

  .event--compact .event-exception-badge,
  .event--medium .event-exception-badge {
    position:absolute;
    right:8px;
    top:7px;
    margin:0;
    min-height:18px;
    padding:0 7px;
    font-size:7px;
    letter-spacing:.08em;
  }

  .event.canceled {
    background: linear-gradient(180deg, rgba(255,95,109,.18), rgba(110,29,42,.14));
    border-color:rgba(255,95,109,.20);
    color:#ffe5e9;
  }

  .event.blocked {
    z-index:2;
    background: linear-gradient(180deg, rgba(155,155,155,.16), rgba(70,70,70,.12));
    border-color:rgba(220,220,220,.12);
    color:#f2f2f2;
  }

  .event.recurring {
    box-shadow:
      0 0 0 1px rgba(212,175,55,.16),
      0 14px 30px rgba(0,0,0,.22),
      0 0 24px rgba(212,175,55,.08);
  }

  .event-content{
    display:flex;
    flex-direction:column;
    gap:4px;
    height:100%;
    min-height:0;
  }

  .event-meta-row {
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
    margin-bottom:2px;
  }

  .event-rec-badge {
    display:inline-flex;
    min-height:20px;
    align-items:center;
    justify-content:center;
    padding:0 8px;
    border-radius:999px;
    background:rgba(212,175,55,.18);
    color:#fff3c6;
    border:1px solid rgba(212,175,55,.30);
    font-size:9px;
    font-weight:900;
    letter-spacing:.10em;
    text-transform:uppercase;
    width:max-content;
    max-width:100%;
  }

  .event-time {
    font-size:10px;
    font-weight:900;
    letter-spacing:.11em;
    text-transform:uppercase;
    opacity:.96;
    line-height:1.1;
    flex:0 0 auto;
  }

  .event-title {
    font-size:1.02rem;
    font-weight:900;
    line-height:1.02;
    letter-spacing:-.03em;
    word-break:break-word;
    overflow-wrap:anywhere;
  }

  .event-sub {
    font-size:.78rem;
    line-height:1.25;
    opacity:.92;
    word-break:break-word;
    overflow-wrap:anywhere;
  }

  .event--compact{
    padding:7px 9px;
  }

  .event--compact .event-rec-badge{
    min-height:15px;
    padding:0 6px;
    font-size:7px;
    margin:0;
    flex:0 0 auto;
  }

  .event--compact .event-time{
    font-size:8px;
    letter-spacing:.04em;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
  }

  .event--compact .event-meta-row{
    gap:5px;
    flex-wrap:nowrap;
    margin:0;
    min-height:15px;
  }

  .event--compact .event-title{
    font-size:.88rem;
    line-height:1;
    margin-bottom:0;
    display:-webkit-box;
    -webkit-line-clamp:1;
    -webkit-box-orient:vertical;
    overflow:hidden;
  }

  .event--compact .event-sub{
    display:none;
  }

  .event--medium .event-title{
    font-size:.96rem;
    line-height:1.04;
    display:-webkit-box;
    -webkit-line-clamp:2;
    -webkit-box-orient:vertical;
    overflow:hidden;
  }

  .event--medium .event-sub{
    font-size:.74rem;
    line-height:1.2;
    display:-webkit-box;
    -webkit-line-clamp:1;
    -webkit-box-orient:vertical;
    overflow:hidden;
  }

  .event--full .event-title{
    display:block;
  }

  .event--full .event-sub{
    display:block;
  }

  .client-lookup-note {
    padding: 12px 14px;
    border-radius: 14px;
    background: rgba(212,175,55,.08);
    border: 1px solid rgba(212,175,55,.18);
    color: #fff1bf;
    line-height: 1.6;
    font-size: .93rem;
  }

  .autocomplete-wrap {
    position: relative;
  }

  .client-suggestions {
    position: absolute;
    left: 0;
    right: 0;
    top: calc(100% + 6px);
    display: none;
    overflow: hidden;
    border-radius: 16px;
    border: 1px solid rgba(212,175,55,.22);
    background: rgba(20,20,20,.98);
    box-shadow: 0 18px 44px rgba(0,0,0,.45);
    z-index: 10020;
  }

  .client-suggestions.show {
    display: block;
  }

  .client-suggestion {
    width: 100%;
    border: 0;
    border-bottom: 1px solid rgba(255,255,255,.06);
    background: transparent;
    color: #f7f3ea;
    padding: 11px 13px;
    text-align: left;
    cursor: pointer;
    display: grid;
    gap: 3px;
  }

  .client-suggestion:last-child {
    border-bottom: 0;
  }

  .client-suggestion:hover,
  .client-suggestion:focus {
    background: rgba(212,175,55,.12);
    outline: none;
  }

  .client-suggestion strong {
    font-size: .95rem;
  }

  .client-suggestion span {
    color: rgba(247,243,234,.68);
    font-size: .84rem;
  }

  .modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(5,5,5,.72);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 14px;
    opacity: 0;
    visibility: hidden;
    transition: .28s ease;
    z-index: 9999;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
  }

  .modal-overlay.active {
    opacity: 1;
    visibility: visible;
  }

  .modal {
    width: 100%;
    max-width: 560px;
    padding: 26px;
    border-radius: 26px;
    background: linear-gradient(180deg, rgba(255,255,255,.08), rgba(255,255,255,.04));
    border: 1px solid rgba(255,255,255,.10);
    box-shadow: 0 24px 70px rgba(0,0,0,.40);
    transform: translateY(8px) scale(.98);
    transition: .28s ease;
    max-height: calc(100vh - 28px);
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
  }

  .modal-overlay.active .modal {
    transform: translateY(0) scale(1);
  }

  .modal-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
    position: sticky;
    top: 0;
    z-index: 2;
    background: linear-gradient(180deg, rgba(18,18,18,.96), rgba(18,18,18,.88));
    padding-bottom: 10px;
  }

  .modal-title {
    margin:0;
    font-size:1.25rem;
    font-weight:900;
    letter-spacing:-.02em;
  }

  .close-btn {
    width:42px;
    height:42px;
    border-radius:12px;
    border:1px solid rgba(255,255,255,.08);
    background:rgba(255,255,255,.04);
    color:#fff;
    cursor:pointer;
    font-size:1.2rem;
    flex-shrink: 0;
  }

  .modal-badges {
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-bottom:16px;
  }

  .badge {
    display:inline-flex;
    align-items:center;
    min-height:30px;
    padding:0 12px;
    border-radius:999px;
    font-size:11px;
    font-weight:900;
    letter-spacing:.08em;
    text-transform:uppercase;
    border:1px solid transparent;
  }

  .badge.confirmado {
    background:rgba(32,201,151,.12);
    color:#9ef0d3;
    border-color:rgba(32,201,151,.20);
  }

  .badge.cancelado {
    background:rgba(255,95,109,.12);
    color:#ffc0c8;
    border-color:rgba(255,95,109,.20);
  }

  .badge.bloqueio {
    background:rgba(180,180,180,.10);
    color:#ececec;
    border-color:rgba(180,180,180,.18);
  }

  .badge.recorrente {
    background:rgba(212,175,55,.14);
    color:#ffe9a8;
    border-color:rgba(212,175,55,.22);
  }

  .badge.visualizacao {
    background:rgba(255,255,255,.06);
    color:#f3efe3;
    border-color:rgba(255,255,255,.12);
  }

  .detail-grid {
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:12px;
  }

  .detail-item {
    padding:14px;
    border-radius:18px;
    background:rgba(255,255,255,.04);
    border:1px solid rgba(255,255,255,.06);
  }

  .detail-item small {
    display:block;
    color:#f0d77a;
    font-size:11px;
    letter-spacing:.10em;
    text-transform:uppercase;
    margin-bottom:6px;
    font-weight:800;
  }

  .detail-item span {
    color:#f7f3ea;
    font-weight:700;
    line-height:1.5;
  }

  .modal-actions {
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:18px;
  }

  .action-btn {
    min-height:44px;
    padding:0 16px;
    border-radius:14px;
    text-decoration:none;
    font-weight:800;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border:1px solid rgba(255,255,255,.08);
    cursor: pointer;
  }

  .action-btn.primary {
    background:linear-gradient(180deg, rgba(212,175,55,.22), rgba(212,175,55,.14));
    color:#fff4cc;
    border-color:rgba(212,175,55,.22);
  }

  .action-btn.danger {
    background:linear-gradient(180deg, rgba(255,95,109,.22), rgba(255,95,109,.14));
    color:#ffe3e7;
    border-color:rgba(255,95,109,.22);
  }

  .whatsapp-modal { z-index: 10020; }
  .whatsapp-modal .modal { max-width: 720px; }
  .modal-subtitle {
    margin: 6px 0 0;
    color: rgba(247,243,234,.62);
    line-height: 1.6;
  }
  .whatsapp-client {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
    margin:0 0 18px;
    padding:14px 16px;
    border-radius:18px;
    background:rgba(32,201,151,.08);
    border:1px solid rgba(32,201,151,.18);
  }
  .whatsapp-client strong { display:block; color:#f7f3ea; font-size:1rem; }
  .whatsapp-client span { display:block; margin-top:3px; color:rgba(247,243,234,.62); font-weight:700; }
  .message-options { display:grid; gap:12px; }
  .message-option {
    width:100%;
    border:none;
    cursor:pointer;
    text-align:left;
    border-radius:18px;
    padding:16px;
    background:rgba(255,255,255,.045);
    color:#f7f3ea;
    border:1px solid rgba(255,255,255,.08);
    display:grid;
    grid-template-columns:44px 1fr;
    align-items:center;
    gap:12px;
    transition:.22s ease;
    font-family:inherit;
  }
  .message-option:hover {
    transform:translateY(-2px);
    border-color:rgba(212,175,55,.26);
    background:linear-gradient(180deg, rgba(212,175,55,.10), rgba(255,255,255,.045));
  }
  .message-icon {
    width:44px;
    height:44px;
    border-radius:14px;
    display:grid;
    place-items:center;
    background:rgba(212,175,55,.12);
    border:1px solid rgba(212,175,55,.20);
    color:#f2d778;
  }
  .message-icon svg {
    width:21px;
    height:21px;
    fill:none;
    stroke:currentColor;
    stroke-width:2;
    stroke-linecap:round;
    stroke-linejoin:round;
  }
  .message-copy { display:grid; gap:7px; }
  .message-copy strong { font-size:1rem; line-height:1.25; }
  .message-copy span { color:rgba(247,243,234,.62); line-height:1.55; font-size:.92rem; }

  .readonly-note,
  .form-hint,
  .client-lookup-note {
    padding:12px 14px;
    border-radius:14px;
    border:1px solid rgba(255,255,255,.06);
    line-height:1.6;
  }

  .readonly-note,
  .form-hint {
    background:rgba(255,255,255,.04);
    color:rgba(247,243,234,.82);
  }

  .quick-form,
  .remarcar-form {
    display:grid;
    gap:14px;
    margin-top:12px;
  }

  .form-row {
    display:grid;
    gap:8px;
  }

  .form-row.two {
    grid-template-columns:1fr 1fr;
  }

  .form-row label {
    font-size:12px;
    font-weight:800;
    letter-spacing:.08em;
    text-transform:uppercase;
    color:#f0d77a;
  }

  .form-control {
    width:100%;
    min-height:48px;
    border-radius:14px;
    border:1px solid rgba(255,255,255,.08);
    background:rgba(255,255,255,.04);
    color:#f7f3ea;
    padding:0 14px;
    outline:none;
    font-size:.98rem;
  }

  .summary-inline {
    display:grid;
    gap:10px;
    margin-bottom:8px;
  }

  .summary-pill {
    display:inline-flex;
    align-items:center;
    gap:8px;
    min-height:42px;
    padding:0 14px;
    border-radius:999px;
    background:rgba(255,255,255,.04);
    border:1px solid rgba(255,255,255,.08);
    color:#f7f3ea;
    font-size:.92rem;
    font-weight:800;
  }

  .hidden {
    display: none !important;
  }

  @media (max-width: 980px) {
    .layout { grid-template-columns:1fr; }
    .sidebar-card { position:static; }
  }

  @media (max-width: 700px) {
    .schedule {
      min-width: 610px;
      grid-template-columns:72px repeat(<?= max(1, count($profissionaisExibidos)); ?>, minmax(250px,1fr));
    }

    .header-cell{ min-height:64px; }

    .event{
      left:6px;
      right:6px;
      padding:8px 9px;
      border-radius:16px;
    }

    .event-title{ font-size:.93rem; }
    .event-sub{ font-size:.72rem; }

    .event--compact{
      padding:6px 8px;
    }

    .event--compact .event-content{
      gap:3px;
    }

    .event--compact .event-title{
      font-size:.86rem;
      line-height:1.02;
    }

    .event--compact .event-time{ font-size:8px; }

    .time-label.full{ font-size:.84rem; }
    .time-label.half{ font-size:.78rem; }

    .modal-overlay {
      align-items: flex-start;
      padding: 10px;
    }

    .modal {
      max-width: 100%;
      width: 100%;
      padding: 18px;
      border-radius: 20px;
      max-height: calc(100vh - 20px);
      margin: 0;
    }

    .modal-title {
      font-size: 1.05rem;
      line-height: 1.2;
    }

    .detail-grid,
    .form-row.two {
      grid-template-columns:1fr;
    }

    .modal-actions {
      flex-direction: column;
    }

    .action-btn {
      width: 100%;
    }

    .summary-pill {
      min-height: 44px;
      line-height: 1.4;
      padding: 10px 14px;
    }
  }
</style>

<section class="hero">
  <h1><span>Agenda Visual Premium</span></h1>
  <p>
    Um calendário mais elegante, limpo e legível, com cards adaptativos para horários curtos e longos.
  </p>
</section>

<form method="GET" class="top-filters">
  <div class="filter-group">
    <span class="filter-label">Profissional</span>
    <select name="profissional_id" class="pro-select" onchange="this.form.submit()">
      <option value="todos">Todos</option>
      <?php foreach ($profissionais as $prof): ?>
        <option value="<?= (int)$prof['id']; ?>" <?= $profissionalFiltro === (string)$prof['id'] ? 'selected' : ''; ?>>
          <?= htmlspecialchars($prof['nome']); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="filter-group">
    <span class="filter-label">Data</span>
    <input
      type="date"
      name="data"
      value="<?= htmlspecialchars($data); ?>"
      class="pro-select"
      onchange="this.form.submit()"
      style="min-width:180px;"
    >
  </div>
</form>

<?php if ($flash && $msg): ?>
  <div class="flash-message <?= $flash === 'sucesso' ? 'sucesso' : 'erro'; ?>">
    <?= htmlspecialchars($msg); ?>
  </div>
<?php endif; ?>

<div class="layout">
  <aside class="glass sidebar-card">
    <h3 class="side-title">Calendário</h3>

    <div class="month-bar">
      <a class="month-nav" href="?data=<?= date('Y-m-d', strtotime('-1 month', $timestamp)); ?>&profissional_id=<?= urlencode($profissionalFiltro); ?>">‹</a>
      <div class="month-label"><?= $nomeMeses[$mesAtual]; ?> <?= $anoAtual; ?></div>
      <a class="month-nav" href="?data=<?= date('Y-m-d', strtotime('+1 month', $timestamp)); ?>&profissional_id=<?= urlencode($profissionalFiltro); ?>">›</a>
    </div>

    <div class="weekdays">
      <div class="weekday">D</div>
      <div class="weekday">S</div>
      <div class="weekday">T</div>
      <div class="weekday">Q</div>
      <div class="weekday">Q</div>
      <div class="weekday">S</div>
      <div class="weekday">S</div>
    </div>

    <div class="month-grid">
      <?php for ($i = 0; $i < $primeiroDiaSemana; $i++): ?>
        <div class="day empty"></div>
      <?php endfor; ?>

      <?php for ($dia = 1; $dia <= $diasNoMes; $dia++): ?>
        <?php
          $dataDia = sprintf('%04d-%02d-%02d', $anoAtual, $mesAtual, $dia);
          $isActive = $dataDia === $data;
          $isToday = $dataDia === date('Y-m-d');
        ?>
        <a class="day <?= $isActive ? 'active' : ''; ?> <?= $isToday ? 'today' : ''; ?>" href="?data=<?= $dataDia; ?>&profissional_id=<?= urlencode($profissionalFiltro); ?>" title="<?= (int)($radarDiaContagem[$dataDia] ?? 0); ?> retorno(s)">
          <span><?= $dia; ?></span>
          <?php if (!empty($radarDiaContagem[$dataDia])): ?><span class="return-count"><?= (int)$radarDiaContagem[$dataDia]; ?></span><?php endif; ?>
        </a>
      <?php endfor; ?>
    </div>

    <div class="quick-nav">
      <a class="quick-btn" href="?data=<?= $dataAnterior; ?>&profissional_id=<?= urlencode($profissionalFiltro); ?>">Dia anterior</a>
      <a class="quick-btn" href="?data=<?= date('Y-m-d'); ?>&profissional_id=<?= urlencode($profissionalFiltro); ?>">Hoje</a>
      <a class="quick-btn" href="?data=<?= $proximaData; ?>&profissional_id=<?= urlencode($profissionalFiltro); ?>">Próximo dia</a>
    </div>
  </aside>

  <main class="glass main-card">
    <div class="toolbar">
      <div class="date-switch">
        <a class="quick-btn" style="min-width:44px;" href="?data=<?= $dataAnterior; ?>&profissional_id=<?= urlencode($profissionalFiltro); ?>">‹</a>
        <div class="date-pill"><?= date('d/m/Y', $timestamp); ?></div>
        <a class="quick-btn" style="min-width:44px;" href="?data=<?= $proximaData; ?>&profissional_id=<?= urlencode($profissionalFiltro); ?>">›</a>
      </div>

      <div class="legend">
        <div class="legend-item"><span class="dot appointment"></span> Agendamento</div>
        <div class="legend-item"><span class="dot recurring"></span> Recorrente</div>
        <div class="legend-item"><span class="dot blocked"></span> Bloqueio</div>
        <div class="legend-item"><span class="dot canceled"></span> Cancelado</div>
      </div>
    </div>

    <div class="schedule-shell">
      <div class="schedule">
        <div class="header-cell header-time">Hora</div>
        <?php foreach ($profissionaisExibidos as $prof): ?>
          <div class="header-cell pro-head">
            <small>Profissional</small>
            <div><?= htmlspecialchars($prof['nome']); ?></div>
          </div>
        <?php endforeach; ?>

        <div class="time-column">
          <?php
            for ($slotMin = ($inicioAgenda * 60) + 30; $slotMin < $fimAgenda * 60; $slotMin += 30) {
              $horas = floor($slotMin / 60);
              $minutos = $slotMin % 60;
              $top = ((($slotMin - ($inicioAgenda * 60)) / 30) * $slotAltura) + ($slotAltura / 2);

              $horaText = str_pad((string)$horas, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)$minutos, 2, '0', STR_PAD_LEFT);
              $class = $minutos === 0 ? 'full' : 'half';

              echo '<div class="time-label ' . $class . '" style="top:' . $top . 'px;">' . $horaText . '</div>';
            }
          ?>
        </div>

        <?php foreach ($profissionaisExibidos as $prof): ?>
          <div class="pro-column">
            <?php
              for ($slotMin = ($inicioAgenda * 60) + 30; $slotMin < $fimAgenda * 60; $slotMin += 30):
                $slotHora = str_pad((string) floor($slotMin / 60), 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) ($slotMin % 60), 2, '0', STR_PAD_LEFT);
                $slotTop = (($slotMin - ($inicioAgenda * 60)) / 30) * $slotAltura;
                $canEditSlot = function_exists('podeEditarProfissional') && podeEditarProfissional((int)$prof['id']);
            ?>
              <?php if ($canEditSlot): ?>
                <div
                  class="time-slot js-open-quick-modal"
                  style="top: <?= $slotTop; ?>px; height: <?= $slotAltura - 2; ?>px;"
                  data-profissional-id="<?= (int)$prof['id']; ?>"
                  data-profissional="<?= htmlspecialchars($prof['nome']); ?>"
                  data-data="<?= htmlspecialchars($data); ?>"
                  data-data-formatada="<?= date('d/m/Y', strtotime($data)); ?>"
                  data-hora="<?= $slotHora; ?>"
                ></div>
              <?php endif; ?>
            <?php endfor; ?>

            <?php if (!empty($blPorProf[$prof['id']])): ?>
              <?php foreach ($blPorProf[$prof['id']] as $bl): ?>
                <?php
                  $segmentosBloqueio = getVisibleBlockSegments($bl, $agPorProf[$prof['id']] ?? []);
                  $canEdit = (function_exists('podeEditarProfissional') && podeEditarProfissional((int)$prof['id'])) ? '1' : '0';
                ?>
                <?php foreach ($segmentosBloqueio as $segmentoBloqueio): ?>
                  <?php
                    $inicioMin = minutosDesdeInicio($segmentoBloqueio['hora_inicio'], $inicioAgenda);
                    $fimMin = minutosDesdeInicio($segmentoBloqueio['hora_fim'], $inicioAgenda);
                    $top = max(0, ($inicioMin / 30) * $slotAltura);
                    $height = max(34, (($fimMin - $inicioMin) / 30) * $slotAltura - 6);
                    $blockVariant = $height <= 52 ? 'event--compact' : 'event--medium';
                  ?>
                  <div class="event blocked <?= (int)$bl['is_recorrente'] === 1 ? 'recurring' : ''; ?> <?= $blockVariant; ?> js-open-modal"
                       style="top: <?= $top; ?>px; height: <?= $height; ?>px;"
                       data-type="bloqueio"
                       data-title="Bloqueio de horário"
                       data-status="bloqueio"
                       data-profissional-id="<?= (int)$prof['id']; ?>"
                       data-profissional="<?= htmlspecialchars($prof['nome']); ?>"
                       data-data="<?= date('d/m/Y', strtotime($bl['data'])); ?>"
                       data-data-raw="<?= htmlspecialchars($bl['data']); ?>"
                       data-hora="<?= substr($segmentoBloqueio['hora_inicio'],0,5); ?> às <?= substr($segmentoBloqueio['hora_fim'],0,5); ?>"
                       data-hora-inicial="<?= substr($segmentoBloqueio['hora_inicio'],0,5); ?>"
                       data-hora-final="<?= substr($segmentoBloqueio['hora_fim'],0,5); ?>"
                       data-extra="Período indisponível para novos agendamentos."
                       data-recorrente="<?= (int)$bl['is_recorrente'] === 1 ? '1' : '0'; ?>"
                       data-can-edit="<?= $canEdit; ?>"
                       data-bloqueio-id="<?= (int)$bl['id']; ?>"
                       data-delete-url="cancelar-bloqueio.php?id=<?= (int)$bl['id']; ?>&tipo=<?= urlencode($bl['tipo_origem'] ?? 'pontual'); ?>&csrf_token=<?= urlencode(csrf_token()); ?>">
                    <div class="event-content">
                      <div class="event-meta-row">
                        <?php if ((int)$bl['is_recorrente'] === 1): ?>
                          <div class="event-rec-badge">Rec.</div>
                        <?php endif; ?>
                        <div class="event-time"><?= substr($segmentoBloqueio['hora_inicio'],0,5); ?> → <?= substr($segmentoBloqueio['hora_fim'],0,5); ?></div>
                      </div>
                      <div class="event-title">Bloqueado</div>
                      <div class="event-sub">Horário indisponível</div>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!empty($agPorProf[$prof['id']])): ?>
              <?php foreach ($agPorProf[$prof['id']] as $ag): ?>
                <?php
                  $inicioMin = minutosDesdeInicio($ag['hora'], $inicioAgenda);
                  $fimReal = !empty($ag['hora_fim'])
                    ? minutosDesdeInicio($ag['hora_fim'], $inicioAgenda)
                    : ($inicioMin + max(30, (int)$ag['servico_duracao']));
                  $top = max(0, ($inicioMin / 30) * $slotAltura);
                  $height = max(42, ((($fimReal - $inicioMin) / 30) * $slotAltura) - 6);
                  $statusClass = 'appointment';
                  $wa = telefoneWhatsapp($ag['cliente_telefone']);
                  $isRec = (int)$ag['is_recorrente'] === 1;
                  $isExcecaoBloqueio = (int)($ag['is_bloqueio_excecao'] ?? 0) === 1;
                  $canEdit = (function_exists('podeEditarProfissional') && podeEditarProfissional((int)$prof['id'])) ? '1' : '0';
                  $horaFimMostrar = !empty($ag['hora_fim']) ? substr($ag['hora_fim'], 0, 5) : '--:--';

                  $eventVariant = 'event--full';
                  if ($height <= 52) {
                      $eventVariant = 'event--compact';
                  } elseif ($height <= 88) {
                      $eventVariant = 'event--medium';
                  }
                ?>
                <div class="event <?= $statusClass; ?> <?= $isRec ? 'recurring' : ''; ?> <?= $isExcecaoBloqueio ? 'exception' : ''; ?> <?= $eventVariant; ?> js-open-modal"
                     style="top: <?= $top; ?>px; height: <?= $height; ?>px;"
                     data-type="agendamento"
                     data-id="<?= (int)$ag['id']; ?>"
                     data-title="<?= htmlspecialchars($ag['cliente_nome']); ?>"
                     data-status="<?= htmlspecialchars($ag['status']); ?>"
                     data-profissional-id="<?= (int)$prof['id']; ?>"
                     data-profissional="<?= htmlspecialchars($prof['nome']); ?>"
                     data-data="<?= date('d/m/Y', strtotime($ag['data'])); ?>"
                     data-data-raw="<?= htmlspecialchars($ag['data']); ?>"
                     data-hora="<?= substr($ag['hora'],0,5); ?> às <?= $horaFimMostrar; ?>"
                     data-hora-inicial="<?= substr($ag['hora'],0,5); ?>"
                     data-hora-final="<?= $horaFimMostrar; ?>"
                     data-servico="<?= htmlspecialchars($ag['servico_nome']); ?>"
                     data-telefone="<?= htmlspecialchars($ag['cliente_telefone']); ?>"
                     data-valor="<?= servicoPrecisaAnaliseAgendaVisual($ag['servico_nome']) ? 'Valor após análise' : 'R$ ' . number_format((float)$ag['servico_preco'], 2, ',', '.'); ?>"
                     data-whatsapp="<?= htmlspecialchars($wa); ?>"
                     data-recorrente="<?= $isRec ? '1' : '0'; ?>"
                     data-excecao-bloqueio="<?= $isExcecaoBloqueio ? '1' : '0'; ?>"
                     data-can-edit="<?= $canEdit; ?>"
                     title="<?= htmlspecialchars($ag['cliente_nome'] . ' • ' . $ag['servico_nome']); ?>"
                     data-cancel-url="cancelar-agendamento.php?id=<?= (int)$ag['id']; ?>&data=<?= urlencode($data); ?>&csrf_token=<?= urlencode(csrf_token()); ?>">
                  <div class="event-content">
                    <div class="event-meta-row">
                      <?php if ($isRec): ?>
                        <div class="event-rec-badge">Rec.</div>
                      <?php endif; ?>
                      <?php if ($isExcecaoBloqueio): ?>
                        <div class="event-exception-badge">Exceção</div>
                      <?php endif; ?>
                      <div class="event-time"><?= substr($ag['hora'],0,5); ?> → <?= $horaFimMostrar; ?></div>
                    </div>
                    <div class="event-title"><?= htmlspecialchars($ag['cliente_nome']); ?></div>
                    <div class="event-sub"><?= htmlspecialchars($ag['servico_nome']); ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </main>
</div>

<div class="modal-overlay" id="modalOverlay">
  <div class="modal">
    <div class="modal-top">
      <h3 class="modal-title" id="modalTitle">Detalhes</h3>
      <button class="close-btn" id="closeModal" type="button">×</button>
    </div>

    <div class="modal-badges" id="modalBadges"></div>
    <div class="detail-grid" id="modalDetails"></div>
    <div class="modal-actions" id="modalActions"></div>

    <form class="remarcar-form hidden" id="remarcarForm" action="remarcar-agendamento.php" method="POST">
      <input type="hidden" name="id" id="remarcarId">
      <input type="hidden" name="return_data" value="<?= htmlspecialchars($data); ?>">
      <input type="hidden" name="return_profissional_id" value="<?= htmlspecialchars($profissionalFiltro); ?>">

      <div class="form-row two">
        <div class="form-row">
          <label for="remarcarData">Nova data</label>
          <input class="form-control" type="date" name="data" id="remarcarData" required>
        </div>

        <div class="form-row">
          <label for="remarcarHora">Hora inicial</label>
          <select class="form-control" name="hora" id="remarcarHora" required>
            <option value="">Selecione</option>
            <?php foreach ($listaHorarios as $horaItem): ?>
              <option value="<?= htmlspecialchars($horaItem); ?>"><?= htmlspecialchars($horaItem); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-row">
        <label for="remarcarHoraFim">Hora final</label>
        <select class="form-control" name="hora_fim" id="remarcarHoraFim" required>
          <option value="">Selecione</option>
          <?php foreach ($listaHorarios as $horaItem): ?>
            <option value="<?= htmlspecialchars($horaItem); ?>"><?= htmlspecialchars($horaItem); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="modal-actions">
        <button type="submit" class="action-btn primary">Salvar remarcação</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-overlay" id="quickModalOverlay">
  <div class="modal">
    <div class="modal-top">
      <h3 class="modal-title">Novo agendamento</h3>
      <button class="close-btn" id="closeQuickModal" type="button">×</button>
    </div>

    <div class="summary-inline">
      <div class="summary-pill" id="quickResumoProfissional">Profissional: -</div>
      <div class="summary-pill" id="quickResumoDataHora">Data: -</div>
    </div>

    <form class="quick-form" action="agenda-visual.php" method="POST">
      <input type="hidden" name="acao" value="agendamento_rapido">
      <input type="hidden" name="profissional_id" id="quickProfissionalId">
      <input type="hidden" name="data" id="quickData">
      <input type="hidden" name="return_data" value="<?= htmlspecialchars($data); ?>">
      <input type="hidden" name="return_profissional_id" value="<?= htmlspecialchars($profissionalFiltro); ?>">
      <input type="hidden" name="override_bloqueio" id="quickOverrideBloqueio" value="0">
      <input type="hidden" name="bloqueio_id" id="quickBloqueioId" value="0">
      <input type="hidden" name="cliente_id" id="quickClienteId" value="0">

      <div class="form-row">
        <label for="quickServico">Serviço</label>
        <select class="form-control" name="servico_id" id="quickServico" required>
          <option value="">Selecione o serviço</option>
          <?php foreach ($servicos as $serv): ?>
            <option value="<?= (int)$serv['id']; ?>" data-duracao="<?= (int)$serv['duracao']; ?>">
              <?= htmlspecialchars(labelServicoAgendaVisual($serv)); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-row two">
        <div class="form-row">
          <label for="quickHora">Hora inicial</label>
          <select class="form-control" name="hora" id="quickHora" required>
            <option value="">Selecione</option>
            <?php foreach ($listaHorarios as $horaItem): ?>
              <option value="<?= htmlspecialchars($horaItem); ?>"><?= htmlspecialchars($horaItem); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-row">
          <label for="quickHoraFim">Hora final</label>
          <select class="form-control" name="hora_fim" id="quickHoraFim" required>
            <option value="">Selecione</option>
            <?php foreach ($listaHorarios as $horaItem): ?>
              <option value="<?= htmlspecialchars($horaItem); ?>"><?= htmlspecialchars($horaItem); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-row two">
        <div class="form-row">
          <label for="quickNome">Nome</label>
          <div class="autocomplete-wrap">
            <input class="form-control" type="text" name="nome" id="quickNome" placeholder="Nome do cliente" autocomplete="off" required>
            <div class="client-suggestions" id="quickClienteSugestoes"></div>
          </div>
        </div>

        <div class="form-row">
          <label for="quickTelefone">WhatsApp</label>
          <input class="form-control" type="text" name="telefone" id="quickTelefone" placeholder="(11) 99999-9999" required>
        </div>
      </div>

      <div class="client-lookup-note hidden" id="quickClienteInfo"></div>

      <div class="form-hint">
        Escolha hora inicial e hora final livremente. Nesta agenda interna, profissional/assistente podem salvar em cima de bloqueios; o sistema ainda valida conflitos com outros agendamentos.
      </div>

      <div class="modal-actions">
        <button class="action-btn primary" type="submit">Salvar agendamento</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-overlay whatsapp-modal" id="whatsappMessageModal">
  <div class="modal">
    <div class="modal-top">
      <div>
        <h3 class="modal-title">Mensagem automática</h3>
        <p class="modal-subtitle">Escolha a comunicação e o WhatsApp abre com o texto pronto.</p>
      </div>
      <button type="button" class="close-btn" id="closeWhatsappModalBtn">×</button>
    </div>

    <div class="whatsapp-client">
      <div>
        <strong id="whatsappClienteNome">Cliente</strong>
        <span id="whatsappClienteTelefone">+55 (00) 00000-0000</span>
      </div>
      <span>WhatsApp Business prioritário</span>
    </div>

    <div class="message-options" id="whatsappMessageOptions"></div>
  </div>
</div>

<script>
  const modalOverlay = document.getElementById('modalOverlay');
  const modalTitle = document.getElementById('modalTitle');
  const modalBadges = document.getElementById('modalBadges');
  const modalDetails = document.getElementById('modalDetails');
  const modalActions = document.getElementById('modalActions');
  const closeModal = document.getElementById('closeModal');

  const quickModalOverlay = document.getElementById('quickModalOverlay');
  const closeQuickModal = document.getElementById('closeQuickModal');
  const quickProfissionalId = document.getElementById('quickProfissionalId');
  const quickData = document.getElementById('quickData');
  const quickHora = document.getElementById('quickHora');
  const quickHoraFim = document.getElementById('quickHoraFim');
  const quickOverrideBloqueio = document.getElementById('quickOverrideBloqueio');
  const quickBloqueioId = document.getElementById('quickBloqueioId');
  const quickClienteId = document.getElementById('quickClienteId');
  const quickNome = document.getElementById('quickNome');
  const quickTelefone = document.getElementById('quickTelefone');
  const quickServico = document.getElementById('quickServico');
  const quickResumoProfissional = document.getElementById('quickResumoProfissional');
  const quickResumoDataHora = document.getElementById('quickResumoDataHora');
  const quickClienteInfo = document.getElementById('quickClienteInfo');
  const quickClienteSugestoes = document.getElementById('quickClienteSugestoes');
  const whatsappMessageModal = document.getElementById('whatsappMessageModal');
  const closeWhatsappModalBtn = document.getElementById('closeWhatsappModalBtn');
  const whatsappClienteNome = document.getElementById('whatsappClienteNome');
  const whatsappClienteTelefone = document.getElementById('whatsappClienteTelefone');
  const whatsappMessageOptions = document.getElementById('whatsappMessageOptions');

  const remarcarForm = document.getElementById('remarcarForm');
  const remarcarId = document.getElementById('remarcarId');
  const remarcarData = document.getElementById('remarcarData');
  const remarcarHora = document.getElementById('remarcarHora');
  const remarcarHoraFim = document.getElementById('remarcarHoraFim');

  let clienteLookupTimeout = null;
  let clienteSugestoesTimeout = null;
  let clienteSugestoesSeq = 0;
  let nomePreenchidoAutomaticamente = false;
  let currentWhatsappClient = null;
  const whatsappTemplates = [
    {
      id: 'pos_atendimento',
      title: '\u{2728} Obrigado pela visita',
      preview: 'Pós atendimento com pedido de avaliação no Google.',
      message: `Fala, {nome_cliente}! \u{2728}

Obrigado por escolher o Salão André Puchetti.
Foi incrível receber você hoje \u{1F64C}

Esperamos que tenha curtido a experiência.
E se puder nos ajudar, deixa sua avaliação no Google \u{1F49B}

Sua opinião fortalece muito nosso trabalho e ajuda novas pessoas a conhecerem nosso salão.

\u{2B50} Avalie aqui:
https://search.google.com/local/writereview?placeid=ChIJC0QVNFtdzpQR16hv15tj2gM`
    },
    {
      id: 'boas_vindas',
      title: '\u{1F525} Bem-vindo ao André Puchetti',
      preview: 'Primeiro contato para receber o cliente com cuidado.',
      message: `Olá, {nome_cliente}! \u{2728}

Seja muito bem-vindo ao Salão André Puchetti.
É um prazer ter você com a gente \u{1F64C}

Aqui cada detalhe é pensado pra entregar uma experiência diferenciada, desde o atendimento até o resultado final.

Qualquer dúvida, horário ou agendamento, pode chamar aqui \u{1F60A}`
    },
    {
      id: 'confirmacao_agendamento',
      title: '\u{1F4C5} Agendamento confirmado',
      preview: 'Confirma o horário e orienta em caso de imprevisto.',
      message: `Fala, {nome_cliente}! \u{2728}

Seu horário foi confirmado com sucesso no Salão André Puchetti \u{1F64C}

\u{1F4C5} Data: {data_agendamento}
\u{23F0} Horário: {hora_agendamento}
\u{1F488} Serviço: {servico_agendamento}
\u{1F464} Profissional: {profissional_agendamento}

Qualquer imprevisto ou necessidade de alteração, é só chamar aqui no WhatsApp \u{1F60A}

Vai ser um prazer receber você \u{1F525}`
    }
  ];

  function escapeHtml(text) {
    const map = {
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;'
    };
    return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
  }

  function somarMinutos(hora, minutos) {
    const partes = hora.split(':');
    let total = (parseInt(partes[0], 10) * 60) + parseInt(partes[1], 10) + minutos;
    const h = Math.floor(total / 60);
    const m = total % 60;
    return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
  }

  function travarBodyModal() {
    document.body.style.overflow = 'hidden';
  }

  function liberarBodyModal() {
    document.body.style.overflow = '';
  }

  function normalizePhoneJS(value) {
    let digits = String(value || '').replace(/\D+/g, '');

    while (digits.length > 13 && digits.slice(0, 4) === '5555') {
      digits = digits.slice(2);
    }

    let national = '';

    if (digits.slice(0, 2) === '55') {
      national = digits.slice(2);
    } else if (digits.length === 10 || digits.length === 11) {
      national = digits;
      digits = '55' + digits;
    }

    if (!national || ![10, 11].includes(national.length)) {
      return { valid: false, displayPhone: value, whatsappPhone: '' };
    }

    const ddd = national.slice(0, 2);
    const subscriber = national.slice(2);
    const formattedSubscriber = subscriber.length === 9
      ? `${subscriber.slice(0, 5)}-${subscriber.slice(5)}`
      : `${subscriber.slice(0, 4)}-${subscriber.slice(4)}`;

    return {
      valid: true,
      displayPhone: `+55 (${ddd}) ${formattedSubscriber}`,
      whatsappPhone: `55${national}`
    };
  }

  function renderWhatsappOptions() {
    whatsappMessageOptions.innerHTML = '';

    whatsappTemplates.forEach(template => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'message-option';
      button.dataset.templateId = template.id;
      button.innerHTML = `<span class="message-icon" aria-hidden="true">${getWhatsappTemplateIcon(template.id)}</span><span class="message-copy"><strong>${template.title}</strong><span>${template.preview}</span></span>`;
      whatsappMessageOptions.appendChild(button);
    });
  }

  function getWhatsappTemplateIcon(templateId) {
    const icons = {
      pos_atendimento: '<svg viewBox="0 0 24 24"><path d="M12 3l2.2 4.5 4.9.7-3.5 3.4.8 4.8L12 14.1 7.6 16.4l.8-4.8-3.5-3.4 4.9-.7L12 3Z"/><path d="M4 21h16"/></svg>',
      boas_vindas: '<svg viewBox="0 0 24 24"><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-7"/><path d="M2 7h20v5H2Z"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 1 1 2.1-3.8C10.4 4.4 12 7 12 7Z"/><path d="M12 7h4.5a2.5 2.5 0 1 0-2.1-3.8C13.6 4.4 12 7 12 7Z"/></svg>',
      confirmacao_agendamento: '<svg viewBox="0 0 24 24"><path d="M8 2v4"/><path d="M16 2v4"/><path d="M3 10h18"/><path d="M5 4h14a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z"/><path d="m9 16 2 2 4-5"/></svg>'
    };

    return icons[templateId] || icons.boas_vindas;
  }

  function abrirWhatsappModal(cliente) {
    const phone = normalizePhoneJS(cliente.whatsapp || cliente.telefone || '');

    if (!phone.valid) {
      alert('Este cliente está com WhatsApp inválido. Corrija o telefone antes de enviar mensagem.');
      return;
    }

    currentWhatsappClient = {
      nome: cliente.nome || 'cliente',
      displayPhone: phone.displayPhone,
      whatsappPhone: phone.whatsappPhone,
      data: cliente.data || '',
      hora: cliente.hora || '',
      servico: cliente.servico || '',
      profissional: cliente.profissional || ''
    };

    whatsappClienteNome.textContent = currentWhatsappClient.nome;
    whatsappClienteTelefone.textContent = currentWhatsappClient.displayPhone;
    whatsappMessageModal.classList.add('active');
    travarBodyModal();
  }

  function fecharWhatsappModal() {
    whatsappMessageModal.classList.remove('active');
    currentWhatsappClient = null;

    if (!modalOverlay.classList.contains('active') && !quickModalOverlay.classList.contains('active')) {
      liberarBodyModal();
    }
  }

  function enviarMensagemWhatsapp(templateId) {
    if (!currentWhatsappClient) return;

    const template = whatsappTemplates.find(item => item.id === templateId);
    if (!template) return;

    const message = template.message
      .replaceAll('{nome_cliente}', currentWhatsappClient.nome)
      .replaceAll('{data_agendamento}', currentWhatsappClient.data || 'data agendada')
      .replaceAll('{hora_agendamento}', currentWhatsappClient.hora || 'horário agendado')
      .replaceAll('{servico_agendamento}', currentWhatsappClient.servico || 'serviço agendado')
      .replaceAll('{profissional_agendamento}', currentWhatsappClient.profissional || 'profissional responsável');
    const whatsappPhone = currentWhatsappClient.whatsappPhone;
    fecharWhatsappModal();

    if (window.openAdminWhatsapp) {
      window.openAdminWhatsapp(whatsappPhone, message);
      return;
    }

    window.open(`https://wa.me/${whatsappPhone}?text=${encodeURIComponent(message)}`, '_blank', 'noopener');
  }

  function abrirModalDetalhes(item) {
    const type = item.dataset.type || '';
    const title = item.dataset.title || 'Detalhes';
    const status = item.dataset.status || '';
    const profissionalId = item.dataset.profissionalId || '';
    const profissional = item.dataset.profissional || '';
    const data = item.dataset.data || '';
    const dataRaw = item.dataset.dataRaw || '';
    const hora = item.dataset.hora || '';
    const horaInicial = item.dataset.horaInicial || '';
    const horaFinal = item.dataset.horaFinal || '';
    const servico = item.dataset.servico || '';
    const telefone = item.dataset.telefone || '';
    const valor = item.dataset.valor || '';
    const whatsapp = item.dataset.whatsapp || '';
    const cancelUrl = item.dataset.cancelUrl || '';
    const deleteUrl = item.dataset.deleteUrl || '';
    const extra = item.dataset.extra || '';
    const recorrente = item.dataset.recorrente || '0';
    const excecaoBloqueio = item.dataset.excecaoBloqueio === '1';
    const canEdit = item.dataset.canEdit === '1';
    const agendamentoId = item.dataset.id || '';
    const bloqueioId = item.dataset.bloqueioId || '0';

    modalTitle.textContent = title;
    modalBadges.innerHTML = '';
    modalDetails.innerHTML = '';
    modalActions.innerHTML = '';
    remarcarForm.classList.add('hidden');

    const badge = document.createElement('div');
    badge.className = 'badge ' + (status === 'cancelado' ? 'cancelado' : (type === 'bloqueio' ? 'bloqueio' : 'confirmado'));
    badge.textContent = type === 'bloqueio' ? 'Bloqueio' : status;
    modalBadges.appendChild(badge);

    if (recorrente === '1') {
      const recBadge = document.createElement('div');
      recBadge.className = 'badge recorrente';
      recBadge.textContent = 'Recorrente';
      modalBadges.appendChild(recBadge);
    }

    if (excecaoBloqueio) {
      const excBadge = document.createElement('div');
      excBadge.className = 'badge recorrente';
      excBadge.textContent = 'Exceção';
      modalBadges.appendChild(excBadge);
    }

    if (!canEdit) {
      const viewBadge = document.createElement('div');
      viewBadge.className = 'badge visualizacao';
      viewBadge.textContent = 'Somente visualização';
      modalBadges.appendChild(viewBadge);
    }

    const details = [];
    details.push({ label: 'Profissional', value: profissional });
    details.push({ label: 'Data', value: data });
    details.push({ label: 'Horário', value: hora });

    if (type === 'agendamento') {
      details.push({ label: 'Serviço', value: servico });
      details.push({ label: 'Telefone', value: telefone });
      details.push({ label: 'Valor', value: valor });
      if (excecaoBloqueio) {
        details.push({ label: 'Tipo', value: 'Agendamento criado como exceção sobre um bloqueio.' });
      }
    } else {
      details.push({ label: 'Informação', value: extra });
    }

    details.forEach(detail => {
      const div = document.createElement('div');
      div.className = 'detail-item';
      div.innerHTML = `<small>${detail.label}</small><span>${detail.value}</span>`;
      modalDetails.appendChild(div);
    });

    if (canEdit) {
      if (type === 'agendamento') {
        if (whatsapp) {
          const wa = document.createElement('button');
          wa.type = 'button';
          wa.className = 'action-btn primary';
          wa.textContent = 'Abrir WhatsApp';
          wa.addEventListener('click', function () {
            abrirWhatsappModal({
              nome: title,
              telefone,
              whatsapp,
              data,
              hora,
              servico,
              profissional
            });
          });
          modalActions.appendChild(wa);
        }

        const remarcarBtn = document.createElement('button');
        remarcarBtn.type = 'button';
        remarcarBtn.className = 'action-btn primary';
        remarcarBtn.textContent = 'Remarcar';
        remarcarBtn.addEventListener('click', () => {
          remarcarId.value = agendamentoId;
          remarcarData.value = dataRaw;
          remarcarHora.value = horaInicial;
          remarcarHoraFim.value = horaFinal !== '--:--' ? horaFinal : '';
          remarcarForm.classList.remove('hidden');
          setTimeout(() => {
            remarcarData.scrollIntoView({ behavior: 'smooth', block: 'center' });
            remarcarData.focus();
          }, 80);
        });
        modalActions.appendChild(remarcarBtn);

        if (status !== 'cancelado' && cancelUrl) {
          const cancel = document.createElement('a');
          cancel.className = 'action-btn danger';
          cancel.href = cancelUrl;
          cancel.textContent = recorrente === '1' ? 'Excluir recorrência' : 'Cancelar agendamento';
          cancel.onclick = function () {
            return confirm(recorrente === '1'
              ? 'Este é um agendamento recorrente. Deseja cancelar a recorrência inteira?'
              : 'Deseja cancelar este agendamento?');
          };
          modalActions.appendChild(cancel);
        }
      }

      if (type === 'bloqueio') {
        const exceptionBtn = document.createElement('button');
        exceptionBtn.type = 'button';
        exceptionBtn.className = 'action-btn primary';
        exceptionBtn.textContent = 'Agendar exceção';
        exceptionBtn.addEventListener('click', () => {
          fecharModalDetalhes();
          abrirModalRapido({
            dataset: {
              profissionalId,
              profissional,
              data: dataRaw,
              dataFormatada: data,
              hora: horaInicial,
              horaFim: horaFinal,
              overrideBloqueio: '1',
              bloqueioId
            }
          });
        });
        modalActions.appendChild(exceptionBtn);

        if (deleteUrl) {
          const del = document.createElement('a');
          del.className = 'action-btn danger';
          del.href = deleteUrl;
          del.textContent = 'Remover bloqueio';
          del.onclick = function () {
            return confirm('Deseja remover este bloqueio?');
          };
          modalActions.appendChild(del);
        }
      }
    } else {
      const note = document.createElement('div');
      note.className = 'readonly-note';
      note.textContent = 'Você pode visualizar a agenda deste profissional, mas não pode editar os itens dele.';
      modalActions.appendChild(note);
    }

    modalOverlay.classList.add('active');
    travarBodyModal();
    setTimeout(() => {
      const modalBox = modalOverlay.querySelector('.modal');
      if (modalBox) modalBox.scrollTop = 0;
    }, 30);
  }

  function abrirModalRapido(item) {
    const profissionalId = item.dataset.profissionalId || '';
    const profissional = item.dataset.profissional || '';
    const data = item.dataset.data || '';
    const dataFormatada = item.dataset.dataFormatada || '';
    const hora = item.dataset.hora || '';
    const horaFim = item.dataset.horaFim || '';
    const overrideBloqueio = item.dataset.overrideBloqueio || '0';
    const bloqueioId = item.dataset.bloqueioId || '0';

    quickProfissionalId.value = profissionalId;
    quickData.value = data;
    quickResumoProfissional.textContent = `Profissional: ${profissional}`;
    quickResumoDataHora.textContent = `Data: ${dataFormatada}`;
    quickServico.value = '';
    quickHora.value = hora || '';
    quickHoraFim.value = horaFim || '';
    if (quickOverrideBloqueio) quickOverrideBloqueio.value = overrideBloqueio;
    if (quickBloqueioId) quickBloqueioId.value = bloqueioId;
    quickNome.value = '';
    quickTelefone.value = '';
    esconderSugestoesClientes();
    quickClienteInfo.textContent = '';
    quickClienteInfo.classList.add('hidden');
    nomePreenchidoAutomaticamente = false;

    quickModalOverlay.classList.add('active');
    travarBodyModal();

    setTimeout(() => {
      const modalBox = quickModalOverlay.querySelector('.modal');
      if (modalBox) modalBox.scrollTop = 0;
      if (quickHora.value) quickServico.focus();
      else quickHora.focus();
    }, 60);
  }

  function aplicarHoraFinalPeloServico() {
    const horaInicio = quickHora.value;
    const option = quickServico.options[quickServico.selectedIndex];
    if (!horaInicio || !option || !option.dataset.duracao) return;

    const duracao = parseInt(option.dataset.duracao, 10);
    if (isNaN(duracao)) return;

    const sugestaoFim = somarMinutos(horaInicio, duracao);
    const existeOpcao = Array.from(quickHoraFim.options).some(opt => opt.value === sugestaoFim);

    if (existeOpcao) quickHoraFim.value = sugestaoFim;
  }

  async function buscarClientePorTelefone() {
    const telefone = quickTelefone.value.replace(/\D/g, '');

    if (telefone.length < 10) {
      quickClienteInfo.textContent = '';
      quickClienteInfo.classList.add('hidden');
      return;
    }

    try {
      const params = new URLSearchParams({ ajax: 'buscar_cliente', telefone });
      const response = await fetch(`agenda-visual.php?${params.toString()}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const json = await response.json();

      if (!response.ok || !json.ok) return;

      if (json.found && json.cliente) {
        quickClienteId.value = json.cliente.id || '0';
        quickNome.value = json.cliente.nome;
        nomePreenchidoAutomaticamente = true;

        let info = `Cliente encontrado: ${json.cliente.nome}.`;
        if (json.ultimo_profissional) {
          info += ` Último profissional: ${json.ultimo_profissional.nome}`;
          if (json.ultimo_profissional.data) {
            const partes = json.ultimo_profissional.data.split('-');
            if (partes.length === 3) {
              info += ` em ${partes[2]}/${partes[1]}/${partes[0]}`;
            }
          }
        }

        quickClienteInfo.textContent = info;
        quickClienteInfo.classList.remove('hidden');
      } else {
        quickClienteId.value = '0';
        if (nomePreenchidoAutomaticamente) quickNome.value = '';
        nomePreenchidoAutomaticamente = false;
        quickClienteInfo.textContent = 'Cliente novo. Você pode preencher o nome normalmente.';
        quickClienteInfo.classList.remove('hidden');
      }
    } catch (e) {}
  }

  function formatarTelefoneCliente(telefone) {
    let v = String(telefone || '').replace(/\D/g, '').slice(0, 11);

    if (v.length > 10) {
      return v.replace(/^(\d{2})(\d{5})(\d{0,4}).*/, '($1) $2-$3');
    }

    if (v.length > 6) {
      return v.replace(/^(\d{2})(\d{4})(\d{0,4}).*/, '($1) $2-$3');
    }

    if (v.length > 2) {
      return v.replace(/^(\d{2})(\d{0,5})/, '($1) $2');
    }

    if (v.length > 0) {
      return v.replace(/^(\d*)/, '($1');
    }

    return '';
  }

  function esconderSugestoesClientes() {
    if (!quickClienteSugestoes) return;
    quickClienteSugestoes.innerHTML = '';
    quickClienteSugestoes.classList.remove('show');
  }

  function selecionarCliente(cliente) {
    quickClienteId.value = cliente.id || '0';
    quickNome.value = cliente.nome || '';
    quickTelefone.value = formatarTelefoneCliente(cliente.telefone || '');
    nomePreenchidoAutomaticamente = true;
    esconderSugestoesClientes();

    quickClienteInfo.textContent = `Cliente selecionado: ${cliente.nome || ''}. Nome e WhatsApp preenchidos automaticamente.`;
    quickClienteInfo.classList.remove('hidden');
  }

  function renderizarSugestoesClientes(clientes) {
    if (!quickClienteSugestoes) return;

    if (!clientes.length) {
      esconderSugestoesClientes();
      return;
    }

    quickClienteSugestoes.innerHTML = clientes.map(cliente => `
      <button
        type="button"
        class="client-suggestion"
        data-id="${Number(cliente.id || 0)}"
        data-nome="${escapeHtml(cliente.nome || '')}"
        data-telefone="${escapeHtml(cliente.telefone || '')}"
      >
        <strong>${escapeHtml(cliente.nome || '')}</strong>
        <span>${escapeHtml(formatarTelefoneCliente(cliente.telefone || ''))}</span>
      </button>
    `).join('');

    quickClienteSugestoes.classList.add('show');
  }

  async function buscarSugestoesClientes() {
    const termo = quickNome.value.trim();
    const digits = termo.replace(/\D/g, '');
    const seq = ++clienteSugestoesSeq;

    if (termo.length < 2 && digits.length < 3) {
      esconderSugestoesClientes();
      return;
    }

    try {
      const params = new URLSearchParams({ ajax: 'buscar_cliente', q: termo });
      const response = await fetch(`agenda-visual.php?${params.toString()}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const json = await response.json();

      if (seq !== clienteSugestoesSeq) return;
      if (!response.ok || !json.ok || !Array.isArray(json.matches)) {
        esconderSugestoesClientes();
        return;
      }

      renderizarSugestoesClientes(json.matches);
    } catch (e) {
      esconderSugestoesClientes();
    }
  }

  document.querySelectorAll('.js-open-modal').forEach(item => {
    item.addEventListener('click', (e) => {
      e.stopPropagation();
      abrirModalDetalhes(item);
    });
  });

  document.querySelectorAll('.js-open-quick-modal').forEach(item => {
    item.addEventListener('click', () => {
      abrirModalRapido(item);
    });
  });

  quickServico.addEventListener('change', aplicarHoraFinalPeloServico);
  quickHora.addEventListener('change', aplicarHoraFinalPeloServico);

  quickNome.addEventListener('input', () => {
    quickClienteId.value = '0';
    nomePreenchidoAutomaticamente = false;
    if (clienteSugestoesTimeout) clearTimeout(clienteSugestoesTimeout);
    clienteSugestoesTimeout = setTimeout(() => {
      buscarSugestoesClientes();
    }, 250);
  });

  quickNome.addEventListener('blur', () => {
    setTimeout(esconderSugestoesClientes, 180);
  });

  if (quickClienteSugestoes) {
    quickClienteSugestoes.addEventListener('mousedown', (e) => {
      e.preventDefault();
    });

    quickClienteSugestoes.addEventListener('click', (e) => {
      const option = e.target.closest('.client-suggestion');
      if (!option) return;

      selecionarCliente({
        id: option.dataset.id || '',
        nome: option.dataset.nome || '',
        telefone: option.dataset.telefone || ''
      });
    });
  }

  function fecharModalDetalhes() {
    modalOverlay.classList.remove('active');
    remarcarForm.classList.add('hidden');
    if (!whatsappMessageModal.classList.contains('active')) {
      liberarBodyModal();
    }
  }

  function fecharModalRapido() {
    quickModalOverlay.classList.remove('active');
    esconderSugestoesClientes();
    if (!whatsappMessageModal.classList.contains('active')) {
      liberarBodyModal();
    }
  }

  closeModal.addEventListener('click', fecharModalDetalhes);
  closeQuickModal.addEventListener('click', fecharModalRapido);
  closeWhatsappModalBtn.addEventListener('click', fecharWhatsappModal);

  whatsappMessageModal.addEventListener('click', (e) => {
    if (e.target === whatsappMessageModal) fecharWhatsappModal();
  });

  whatsappMessageOptions.addEventListener('click', (e) => {
    const option = e.target.closest('.message-option');
    if (!option) return;

    enviarMensagemWhatsapp(option.dataset.templateId);
  });

  modalOverlay.addEventListener('click', (e) => {
    if (e.target === modalOverlay) fecharModalDetalhes();
  });

  quickModalOverlay.addEventListener('click', (e) => {
    if (e.target === quickModalOverlay) fecharModalRapido();
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      fecharWhatsappModal();
      fecharModalDetalhes();
      fecharModalRapido();
    }
  });

  quickTelefone.addEventListener('input', () => {
    quickClienteId.value = '0';
    let v = quickTelefone.value.replace(/\D/g, '').slice(0, 11);

    if (v.length > 10) {
      v = v.replace(/^(\d{2})(\d{5})(\d{0,4}).*/, '($1) $2-$3');
    } else if (v.length > 6) {
      v = v.replace(/^(\d{2})(\d{4})(\d{0,4}).*/, '($1) $2-$3');
    } else if (v.length > 2) {
      v = v.replace(/^(\d{2})(\d{0,5})/, '($1) $2');
    } else if (v.length > 0) {
      v = v.replace(/^(\d*)/, '($1');
    }

    quickTelefone.value = v;

    if (clienteLookupTimeout) clearTimeout(clienteLookupTimeout);
    clienteLookupTimeout = setTimeout(() => {
      buscarClientePorTelefone();
    }, 350);
  });

  renderWhatsappOptions();
</script>

<?php if ($abrirBloqueioAgendamento && $profissionalFiltro !== 'todos'): ?>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    const quickModalOverlay = document.getElementById('quickModalOverlay');
    const quickProfissionalId = document.getElementById('quickProfissionalId');
    const quickData = document.getElementById('quickData');
    const quickHora = document.getElementById('quickHora');
    const quickHoraFim = document.getElementById('quickHoraFim');
    const quickOverrideBloqueio = document.getElementById('quickOverrideBloqueio');
    const quickBloqueioId = document.getElementById('quickBloqueioId');
    const quickResumoProfissional = document.getElementById('quickResumoProfissional');
    const quickResumoDataHora = document.getElementById('quickResumoDataHora');
    const quickServico = document.getElementById('quickServico');

    const profissionalSelect = document.querySelector('select[name="profissional_id"]');
    const profissionalNome = profissionalSelect
      ? profissionalSelect.options[profissionalSelect.selectedIndex].textContent.trim()
      : 'Profissional';

    if (quickProfissionalId) quickProfissionalId.value = "<?= htmlspecialchars((string)$profissionalFiltro, ENT_QUOTES); ?>";
    if (quickData) quickData.value = "<?= htmlspecialchars($data, ENT_QUOTES); ?>";
    if (quickHora) quickHora.value = "<?= htmlspecialchars($bloqueioHora, ENT_QUOTES); ?>";
    if (quickHoraFim) quickHoraFim.value = "<?= htmlspecialchars($bloqueioHoraFim, ENT_QUOTES); ?>";
    if (quickOverrideBloqueio) quickOverrideBloqueio.value = "<?= $overrideBloqueioGet ? '1' : '0'; ?>";
    if (quickBloqueioId) quickBloqueioId.value = "<?= (int)$bloqueioIdGet; ?>";

    if (quickResumoProfissional) quickResumoProfissional.textContent = `Profissional: ${profissionalNome}`;
    if (quickResumoDataHora) quickResumoDataHora.textContent = `Data: <?= htmlspecialchars(date('d/m/Y', strtotime($data)), ENT_QUOTES); ?>`;

    if (quickModalOverlay) {
      quickModalOverlay.classList.add('active');
      document.body.style.overflow = 'hidden';
    }

    setTimeout(() => {
      if (quickServico) quickServico.focus();
    }, 80);
  });
</script>
<?php endif; ?>

<?php admin_shell_end(); ?>
