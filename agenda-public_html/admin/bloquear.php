<?php
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/admin-shell.php';

date_default_timezone_set('America/Sao_Paulo');

/**
 * =========================
 * HELPERS
 * =========================
 */
function redirectBloqueiosMes(string $mes, string $profissionalId, string $flash, string $msg): void
{
    header(
        'Location: bloquear.php?mes=' . urlencode($mes) .
        '&profissional_id=' . urlencode($profissionalId) .
        '&flash=' . urlencode($flash) .
        '&msg=' . urlencode($msg)
    );
    exit;
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

function timeToMinutes(string $time): int
{
    $time = substr($time, 0, 5);
    [$h, $m] = explode(':', $time);
    return ((int)$h * 60) + (int)$m;
}

function normalizarDiasSemana(array $dias): array
{
    $validos = ['0', '1', '2', '3', '4', '5', '6'];
    $filtrados = array_values(array_unique(array_filter($dias, function ($dia) use ($validos) {
        return in_array((string)$dia, $validos, true);
    })));
    sort($filtrados);
    return $filtrados;
}

function diasSemanaParaBancoRecorrente(array $dias): string
{
    $diasBanco = array_map(function ($dia) {
        return (string)$dia === '0' ? '7' : (string)$dia;
    }, $dias);

    $diasBanco = array_values(array_unique($diasBanco));
    sort($diasBanco);
    return implode(',', $diasBanco);
}

function parseDiasSemana(?string $valor): array
{
    if (!$valor) return [];
    $partes = array_map(function ($dia) {
        $dia = trim((string)$dia);
        return $dia === '7' ? '0' : $dia;
    }, explode(',', $valor));
    return normalizarDiasSemana($partes);
}

function diasSemanaParaTexto(array $dias): string
{
    $mapa = [
        '0' => 'Dom',
        '1' => 'Seg',
        '2' => 'Ter',
        '3' => 'Qua',
        '4' => 'Qui',
        '5' => 'Sex',
        '6' => 'Sáb',
    ];

    $labels = [];
    foreach ($dias as $dia) {
        if (isset($mapa[(string)$dia])) {
            $labels[] = $mapa[(string)$dia];
        }
    }

    return implode(', ', $labels);
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

function hasColumn(array $columns, string $name): bool
{
    return in_array($name, $columns, true);
}

function getAllowProfessionalColumn(array $columns): ?string
{
    return findFirstExistingColumn($columns, [
        'permite_profissional',
        'allow_professional_booking',
        'permite_agendamento_profissional',
        'libera_profissional',
        'somente_profissional'
    ]);
}

function formatDateBr(string $date): string
{
    $ts = strtotime($date);
    if (!$ts) return $date;
    return date('d/m/Y', $ts);
}

function monthKey(string $date): string
{
    $ts = strtotime($date);
    if (!$ts) return '';
    return date('Y-m', $ts);
}

function intervalosSobrepostos(string $inicioA, string $fimA, string $inicioB, string $fimB): bool
{
    return timeToMinutes($inicioA) < timeToMinutes($fimB)
        && timeToMinutes($fimA) > timeToMinutes($inicioB);
}

function datasRecorrentesNoMes(string $mes, array $diasSemana): array
{
    $datas = [];
    if (empty($diasSemana)) return $datas;

    $inicio = strtotime($mes . '-01');
    if (!$inicio) return $datas;

    $diasNoMes = (int)date('t', $inicio);
    $ano = date('Y', $inicio);
    $numMes = date('m', $inicio);

    for ($dia = 1; $dia <= $diasNoMes; $dia++) {
        $data = sprintf('%04d-%02d-%02d', $ano, $numMes, $dia);
        $dw = (string)date('w', strtotime($data));
        if (in_array($dw, $diasSemana, true)) {
            $datas[] = $data;
        }
    }

    return $datas;
}

function datasNoIntervalo(string $dataInicio, string $dataFim): array
{
    $datas = [];
    $inicio = strtotime($dataInicio);
    $fim = strtotime($dataFim);

    if (!$inicio || !$fim || $fim < $inicio) {
        return $datas;
    }

    for ($ts = $inicio; $ts <= $fim; $ts = strtotime('+1 day', $ts)) {
        $datas[] = date('Y-m-d', $ts);
    }

    return $datas;
}

function usuarioPodeGerenciarBloqueio(): bool
{
    return true;
}

/**
 * =========================
 * CONTEXTO
 * =========================
 */
$mesSelecionado = $_GET['mes'] ?? date('Y-m');
$profissionalFiltro = $_GET['profissional_id'] ?? 'todos';
$flash = $_GET['flash'] ?? '';
$msg = $_GET['msg'] ?? '';

if (!preg_match('/^\d{4}-\d{2}$/', $mesSelecionado)) {
    $mesSelecionado = date('Y-m');
}

$timestampMes = strtotime($mesSelecionado . '-01');
if (!$timestampMes) {
    $mesSelecionado = date('Y-m');
    $timestampMes = strtotime($mesSelecionado . '-01');
}

$mesAnterior = date('Y-m', strtotime('-1 month', $timestampMes));
$mesProximo = date('Y-m', strtotime('+1 month', $timestampMes));

$anoAtual = (int)date('Y', $timestampMes);
$mesAtual = (int)date('m', $timestampMes);
$primeiroDiaMes = date('Y-m-01', $timestampMes);
$primeiroDiaSemana = (int)date('w', strtotime($primeiroDiaMes));
$diasNoMes = (int)date('t', $timestampMes);

$nomeMeses = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];

$listaHorarios = horasDisponiveis(7, 22);

/**
 * =========================
 * METADADOS DA TABELA BLOQUEIOS
 * =========================
 */
$bloqueiosColumns = getTableColumns($conn, 'bloqueios');

$colunaObservacao = findFirstExistingColumn($bloqueiosColumns, [
    'observacao', 'observacoes', 'descricao', 'motivo', 'nota'
]);

$colunaDiasSemana = findFirstExistingColumn($bloqueiosColumns, [
    'dias_semana', 'dias', 'dias_repeticao', 'dias_recorrencia'
]);

$colunaIsRecorrente = hasColumn($bloqueiosColumns, 'is_recorrente') ? 'is_recorrente' : null;
$colunaPermiteProfissional = getAllowProfessionalColumn($bloqueiosColumns);

$temObservacao = $colunaObservacao !== null;
$temDiasSemana = $colunaDiasSemana !== null;
$temIsRecorrente = $colunaIsRecorrente !== null;
$temPermiteProfissional = $colunaPermiteProfissional !== null;

/**
 * =========================
 * PROFISSIONAIS
 * =========================
 */
$profissionais = [];
$resProf = $conn->query("SELECT id, nome FROM profissionais ORDER BY id ASC");
if ($resProf && $resProf->num_rows > 0) {
    while ($row = $resProf->fetch_assoc()) {
        $profissionais[] = $row;
    }
}

/**
 * =========================
 * PROCESSAMENTO POST - NOVO BLOQUEIO
 * =========================
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'novo_bloqueio') {
    $profissionalId = (int)($_POST['profissional_id'] ?? 0);
    $dataPost = trim($_POST['data'] ?? '');
    $dataFimPost = trim($_POST['data_fim'] ?? '');
    $horaInicio = trim($_POST['hora_inicio'] ?? '');
    $horaFim = trim($_POST['hora_fim'] ?? '');
    $observacao = trim($_POST['observacao'] ?? '');
    $diasSemana = normalizarDiasSemana($_POST['dias_semana'] ?? []);
    $permitirProfissional = isset($_POST['permitir_profissional']) ? 1 : 0;

    $returnMes = trim($_POST['return_mes'] ?? $mesSelecionado);
    $returnProfissionalId = trim($_POST['return_profissional_id'] ?? $profissionalFiltro);

    if ($profissionalId <= 0 || $dataPost === '' || $horaInicio === '' || $horaFim === '') {
        redirectBloqueiosMes($returnMes, $returnProfissionalId, 'erro', 'Preencha profissional, data, hora inicial e hora final.');
    }

    $validDate = DateTime::createFromFormat('Y-m-d', $dataPost);
    $validDateFim = $dataFimPost !== '' ? DateTime::createFromFormat('Y-m-d', $dataFimPost) : null;
    $validTimeStart = DateTime::createFromFormat('H:i', $horaInicio);
    $validTimeEnd = DateTime::createFromFormat('H:i', $horaFim);

    if (
        !$validDate || $validDate->format('Y-m-d') !== $dataPost ||
        ($dataFimPost !== '' && (!$validDateFim || $validDateFim->format('Y-m-d') !== $dataFimPost)) ||
        !$validTimeStart || $validTimeStart->format('H:i') !== $horaInicio ||
        !$validTimeEnd || $validTimeEnd->format('H:i') !== $horaFim
    ) {
        redirectBloqueiosMes($returnMes, $returnProfissionalId, 'erro', 'Data ou horário inválidos.');
    }

    $dataFimEfetiva = $dataFimPost !== '' ? $dataFimPost : $dataPost;

    if ($dataFimEfetiva < $dataPost) {
        redirectBloqueiosMes($returnMes, $returnProfissionalId, 'erro', 'A data final não pode ser anterior à data inicial.');
    }

    if (count(datasNoIntervalo($dataPost, $dataFimEfetiva)) > 370) {
        redirectBloqueiosMes($returnMes, $returnProfissionalId, 'erro', 'O período máximo para bloqueios temporários é de 370 dias.');
    }

    $inicioMin = timeToMinutes($horaInicio);
    $fimMin = timeToMinutes($horaFim);

    if ($fimMin <= $inicioMin) {
        redirectBloqueiosMes($returnMes, $returnProfissionalId, 'erro', 'A hora final precisa ser maior que a hora inicial.');
    }

    if ($inicioMin < (7 * 60) || $fimMin > (22 * 60)) {
        redirectBloqueiosMes($returnMes, $returnProfissionalId, 'erro', 'O bloqueio precisa estar dentro do intervalo da agenda.');
    }

    $stmtProf = $conn->prepare("SELECT id FROM profissionais WHERE id = ? LIMIT 1");
    $stmtProf->bind_param('i', $profissionalId);
    $stmtProf->execute();
    $profissionalExiste = $stmtProf->get_result()->fetch_assoc();

    if (!$profissionalExiste) {
        redirectBloqueiosMes($returnMes, $returnProfissionalId, 'erro', 'Profissional não encontrado.');
    }

    $isRecorrente = !empty($diasSemana) ? 1 : 0;
    $diasSemanaString = $isRecorrente ? implode(',', $diasSemana) : '';

    $horaInicioBanco = $horaInicio . ':00';
    $horaFimBanco = $horaFim . ':00';

    if ($isRecorrente) {
        $diasSemanaBancoTeste = diasSemanaParaBancoRecorrente($diasSemana);
        $diasParaTeste = array_filter(explode(',', $diasSemanaBancoTeste));

        $stmtRecorrentesAtivos = $conn->prepare("
            SELECT hora_inicio, hora_fim, dias_semana
            FROM bloqueios_recorrentes
            WHERE profissional_id = ?
              AND ativo = 1
              AND data_inicio <= ?
              AND (data_fim IS NULL OR data_fim >= ?)
        ");
        $stmtRecorrentesAtivos->bind_param('iss', $profissionalId, $dataFimEfetiva, $dataPost);
        $stmtRecorrentesAtivos->execute();
        $resRecorrentesAtivos = $stmtRecorrentesAtivos->get_result();

        while ($bloq = $resRecorrentesAtivos->fetch_assoc()) {
            $diasExistentes = array_filter(explode(',', (string)$bloq['dias_semana']));
            $diasEmComum = array_intersect($diasParaTeste, $diasExistentes);
            if (!empty($diasEmComum) && intervalosSobrepostos($horaInicioBanco, $horaFimBanco, $bloq['hora_inicio'], $bloq['hora_fim'])) {
                redirectBloqueiosMes($returnMes, $returnProfissionalId, 'erro', 'Já existe um bloqueio recorrente sobreposto nesse período.');
            }
        }

        if ($dataFimPost !== '') {
            $stmtBloqPontualRec = $conn->prepare("
                SELECT data
                FROM bloqueios
                WHERE profissional_id = ?
                  AND data BETWEEN ? AND ?
                  AND hora_inicio < ?
                  AND hora_fim > ?
            ");
            $stmtBloqPontualRec->bind_param('issss', $profissionalId, $dataPost, $dataFimEfetiva, $horaFimBanco, $horaInicioBanco);
        } else {
            $stmtBloqPontualRec = $conn->prepare("
                SELECT data
                FROM bloqueios
                WHERE profissional_id = ?
                  AND data >= ?
                  AND hora_inicio < ?
                  AND hora_fim > ?
            ");
            $stmtBloqPontualRec->bind_param('isss', $profissionalId, $dataPost, $horaFimBanco, $horaInicioBanco);
        }

        $stmtBloqPontualRec->execute();
        $resBloqPontualRec = $stmtBloqPontualRec->get_result();
        while ($bloqPontual = $resBloqPontualRec->fetch_assoc()) {
            $diaBancoPontual = (string)date('N', strtotime($bloqPontual['data']));
            if (in_array($diaBancoPontual, $diasParaTeste, true)) {
                redirectBloqueiosMes($returnMes, $returnProfissionalId, 'erro', 'Já existe bloqueio pontual sobreposto nesse período.');
            }
        }
    } else {
        $datasPontuais = datasNoIntervalo($dataPost, $dataFimEfetiva);

        $stmtBloqPontual = $conn->prepare("
            SELECT id
            FROM bloqueios
            WHERE profissional_id = ?
              AND data BETWEEN ? AND ?
              AND hora_inicio < ?
              AND hora_fim > ?
            LIMIT 1
        ");
        $stmtBloqPontual->bind_param('issss', $profissionalId, $dataPost, $dataFimEfetiva, $horaFimBanco, $horaInicioBanco);
        $stmtBloqPontual->execute();
        if ($stmtBloqPontual->get_result()->fetch_assoc()) {
            redirectBloqueiosMes($returnMes, $returnProfissionalId, 'erro', 'Já existe bloqueio pontual sobreposto nesse período.');
        }

        $stmtBloqRecorrente = $conn->prepare("
            SELECT id
            FROM bloqueios_recorrentes
            WHERE profissional_id = ?
              AND ativo = 1
              AND data_inicio <= ?
              AND (data_fim IS NULL OR data_fim >= ?)
              AND FIND_IN_SET(?, dias_semana)
              AND hora_inicio < ?
              AND hora_fim > ?
            LIMIT 1
        ");

        foreach ($datasPontuais as $dataTeste) {
            $diaSemanaBancoTeste = (string)date('N', strtotime($dataTeste));
            $stmtBloqRecorrente->bind_param('isssss', $profissionalId, $dataTeste, $dataTeste, $diaSemanaBancoTeste, $horaFimBanco, $horaInicioBanco);
            $stmtBloqRecorrente->execute();
            if ($stmtBloqRecorrente->get_result()->fetch_assoc()) {
                redirectBloqueiosMes($returnMes, $returnProfissionalId, 'erro', 'Já existe bloqueio recorrente sobreposto nesse período.');
            }
        }
    }

    if ($isRecorrente) {
        $diasSemanaBanco = diasSemanaParaBancoRecorrente($diasSemana);
        $dataFimBanco = $dataFimPost !== '' ? $dataFimPost : null;

        $stmtInsertRecorrente = $conn->prepare("
            INSERT INTO bloqueios_recorrentes
                (profissional_id, frequencia, data_inicio, data_fim, hora_inicio, hora_fim, dia_inteiro, ativo, dias_semana)
            VALUES
                (?, 'semanal', ?, ?, ?, ?, 0, 1, ?)
        ");
        $stmtInsertRecorrente->bind_param('isssss', $profissionalId, $dataPost, $dataFimBanco, $horaInicioBanco, $horaFimBanco, $diasSemanaBanco);

        if (!$stmtInsertRecorrente->execute()) {
            redirectBloqueiosMes($returnMes, $returnProfissionalId, 'erro', 'Não foi possível salvar o bloqueio recorrente agora.');
        }

        redirectBloqueiosMes(monthKey($dataPost) ?: $returnMes, $returnProfissionalId, 'sucesso', 'Bloqueio recorrente salvo com sucesso.');
    }

    $fields = ['profissional_id', 'data', 'hora_inicio', 'hora_fim'];
    $placeholders = ['?', '?', '?', '?'];
    $types = 'isss';

    if ($temObservacao) {
        $fields[] = "`{$colunaObservacao}`";
        $placeholders[] = '?';
        $types .= 's';
    }

    if ($temIsRecorrente) {
        $fields[] = $colunaIsRecorrente;
        $placeholders[] = '?';
        $types .= 'i';
    }

    if ($temDiasSemana) {
        $fields[] = "`{$colunaDiasSemana}`";
        $placeholders[] = '?';
        $types .= 's';
    }

    if ($temPermiteProfissional) {
        $fields[] = "`{$colunaPermiteProfissional}`";
        $placeholders[] = '?';
        $types .= 'i';
    }

    $sqlInsert = "INSERT INTO bloqueios (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmtInsert = $conn->prepare($sqlInsert);

    $conn->begin_transaction();

    try {
        foreach ($datasPontuais as $dataPontual) {
            $values = [$profissionalId, $dataPontual, $horaInicioBanco, $horaFimBanco];

            if ($temObservacao) {
                $values[] = $observacao;
            }

            if ($temIsRecorrente) {
                $values[] = $isRecorrente;
            }

            if ($temDiasSemana) {
                $values[] = $diasSemanaString;
            }

            if ($temPermiteProfissional) {
                $values[] = $permitirProfissional;
            }

            $stmtInsert->bind_param($types, ...$values);

            if (!$stmtInsert->execute()) {
                throw new RuntimeException('Falha ao inserir bloqueio.');
            }
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        redirectBloqueiosMes($returnMes, $returnProfissionalId, 'erro', 'Não foi possível salvar o bloqueio agora.');
    }

    $totalCriados = count($datasPontuais);
    $msgSucesso = $totalCriados > 1
        ? "{$totalCriados} bloqueios temporários salvos com sucesso."
        : 'Bloqueio salvo com sucesso.';

    redirectBloqueiosMes(monthKey($dataPost) ?: $returnMes, $returnProfissionalId, 'sucesso', $msgSucesso);
}

/**
 * =========================
 * PROCESSAMENTO POST - REMOVER BLOQUEIO
 * =========================
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'remover_bloqueio') {
    $bloqueioId = (int)($_POST['bloqueio_id'] ?? 0);
    $bloqueioTipo = trim($_POST['bloqueio_tipo'] ?? 'pontual');
    $returnMes = trim($_POST['return_mes'] ?? $mesSelecionado);
    $returnProfissionalId = trim($_POST['return_profissional_id'] ?? $profissionalFiltro);

    if ($bloqueioId <= 0) {
        redirectBloqueiosMes($returnMes, $returnProfissionalId, 'erro', 'Bloqueio inválido.');
    }

    if ($bloqueioTipo === 'recorrente') {
        $stmtDelete = $conn->prepare("DELETE FROM bloqueios_recorrentes WHERE id = ? LIMIT 1");
    } else {
        $stmtDelete = $conn->prepare("DELETE FROM bloqueios WHERE id = ? LIMIT 1");
    }
    $stmtDelete->bind_param('i', $bloqueioId);

    if (!$stmtDelete->execute()) {
        redirectBloqueiosMes($returnMes, $returnProfissionalId, 'erro', 'Não foi possível remover o bloqueio.');
    }

    redirectBloqueiosMes($returnMes, $returnProfissionalId, 'sucesso', 'Bloqueio removido com sucesso.');
}

/**
 * =========================
 * CARREGAR BLOQUEIOS
 * =========================
 */
$bloqueiosBrutos = [];

$sqlBloqueios = "
    SELECT
        b.id,
        b.profissional_id,
        b.data,
        b.hora_inicio,
        b.hora_fim,
        " . ($temObservacao ? "b.`{$colunaObservacao}`" : "''") . " AS observacao,
        " . ($temIsRecorrente ? "b.`{$colunaIsRecorrente}`" : "0") . " AS is_recorrente,
        " . ($temDiasSemana ? "b.`{$colunaDiasSemana}`" : "''") . " AS dias_semana,
        " . ($temPermiteProfissional ? "b.`{$colunaPermiteProfissional}`" : "0") . " AS permite_profissional,
        p.nome AS profissional_nome
    FROM bloqueios b
    INNER JOIN profissionais p ON p.id = b.profissional_id
";

if ($profissionalFiltro !== 'todos') {
    $sqlBloqueios .= " WHERE b.profissional_id = ? ";
}

$sqlBloqueios .= " ORDER BY b.data ASC, b.hora_inicio ASC, b.id DESC";

if ($profissionalFiltro !== 'todos') {
    $stmtBloqueios = $conn->prepare($sqlBloqueios);
    $profIdInt = (int)$profissionalFiltro;
    $stmtBloqueios->bind_param('i', $profIdInt);
    $stmtBloqueios->execute();
    $resBloqueios = $stmtBloqueios->get_result();
} else {
    $resBloqueios = $conn->query($sqlBloqueios);
}

if ($resBloqueios && $resBloqueios->num_rows > 0) {
    while ($row = $resBloqueios->fetch_assoc()) {
        $row['tipo_origem'] = 'pontual';
        $bloqueiosBrutos[] = $row;
    }
}

$sqlBloqueiosRecorrentes = "
    SELECT
        br.id,
        br.profissional_id,
        br.data_inicio AS data,
        br.data_inicio,
        br.data_fim,
        br.hora_inicio,
        br.hora_fim,
        '' AS observacao,
        1 AS is_recorrente,
        br.dias_semana,
        0 AS permite_profissional,
        p.nome AS profissional_nome
    FROM bloqueios_recorrentes br
    INNER JOIN profissionais p ON p.id = br.profissional_id
    WHERE br.ativo = 1
      AND br.data_inicio <= ?
      AND (br.data_fim IS NULL OR br.data_fim >= ?)
";

$ultimoDiaMes = date('Y-m-t', $timestampMes);
$primeiroDiaMesSql = date('Y-m-01', $timestampMes);

if ($profissionalFiltro !== 'todos') {
    $sqlBloqueiosRecorrentes .= " AND br.profissional_id = ? ";
}

$sqlBloqueiosRecorrentes .= " ORDER BY br.hora_inicio ASC, br.id DESC";

$stmtBloqueiosRecorrentes = $conn->prepare($sqlBloqueiosRecorrentes);
if ($profissionalFiltro !== 'todos') {
    $profIdInt = (int)$profissionalFiltro;
    $stmtBloqueiosRecorrentes->bind_param('ssi', $ultimoDiaMes, $primeiroDiaMesSql, $profIdInt);
} else {
    $stmtBloqueiosRecorrentes->bind_param('ss', $ultimoDiaMes, $primeiroDiaMesSql);
}
$stmtBloqueiosRecorrentes->execute();
$resBloqueiosRecorrentes = $stmtBloqueiosRecorrentes->get_result();

if ($resBloqueiosRecorrentes && $resBloqueiosRecorrentes->num_rows > 0) {
    while ($row = $resBloqueiosRecorrentes->fetch_assoc()) {
        $row['tipo_origem'] = 'recorrente';
        $bloqueiosBrutos[] = $row;
    }
}

/**
 * =========================
 * DISTRIBUIR BLOQUEIOS NO MÊS
 * =========================
 */
$bloqueiosPorDia = [];
$totalBloqueiosMes = 0;
$totalRecorrentesMes = 0;
$totalPontuaisMes = 0;
$diasComBloqueio = [];

foreach ($bloqueiosBrutos as $bloqueio) {
    $isRecorrente = (int)($bloqueio['is_recorrente'] ?? 0) === 1;
    $bloqueio['dias_semana_array'] = parseDiasSemana($bloqueio['dias_semana'] ?? '');

    if ($isRecorrente && !empty($bloqueio['dias_semana_array'])) {
        $datasDoMes = datasRecorrentesNoMes($mesSelecionado, $bloqueio['dias_semana_array']);
        foreach ($datasDoMes as $dataOcorrencia) {
            if (!empty($bloqueio['data_inicio']) && $dataOcorrencia < $bloqueio['data_inicio']) {
                continue;
            }

            if (!empty($bloqueio['data_fim']) && $dataOcorrencia > $bloqueio['data_fim']) {
                continue;
            }

            $item = $bloqueio;
            $item['data_exibicao'] = $dataOcorrencia;
            $bloqueiosPorDia[$dataOcorrencia][] = $item;
            $totalBloqueiosMes++;
            $totalRecorrentesMes++;
            $diasComBloqueio[$dataOcorrencia] = true;
        }
    } else {
        if (($bloqueio['data'] ?? '') !== '' && monthKey($bloqueio['data']) === $mesSelecionado) {
            $item = $bloqueio;
            $item['data_exibicao'] = $bloqueio['data'];
            $bloqueiosPorDia[$bloqueio['data']][] = $item;
            $totalBloqueiosMes++;
            $totalPontuaisMes++;
            $diasComBloqueio[$bloqueio['data']] = true;
        }
    }
}

$totalDiasComBloqueio = count($diasComBloqueio);

foreach ($bloqueiosPorDia as $dataDia => $itens) {
    usort($itens, function ($a, $b) {
        return strcmp($a['hora_inicio'], $b['hora_inicio']);
    });
    $bloqueiosPorDia[$dataDia] = $itens;
}
ksort($bloqueiosPorDia);

admin_shell_start('Calendário de Bloqueios', 'bloqueios');
?>
<style>
  :root{
    --gold:#d4af37;
    --text:#f7f3ea;
    --text-soft:rgba(247,243,234,.66);
  }

  .hero{margin-bottom:18px;position:relative}
  .hero::after{content:'';position:absolute;inset:auto 0 -14px 0;height:1px;background:linear-gradient(90deg,transparent,rgba(212,175,55,.18),transparent)}
  .hero h1{margin:0 0 12px;font-size:clamp(2rem,4vw,3.4rem);line-height:.92;letter-spacing:-.06em;font-weight:900}
  .hero h1 span{display:block;background:linear-gradient(90deg,#fff4cc 0%,#d4af37 45%,#fff0a8 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;color:transparent}
  .hero p{margin:0;color:var(--text-soft);line-height:1.8;max-width:840px;font-size:1rem}

  .page-grid{display:grid;grid-template-columns:380px 1fr;gap:18px;align-items:start}
  .glass{background:linear-gradient(180deg,rgba(255,255,255,.055),rgba(255,255,255,.03)),radial-gradient(circle at top left,rgba(212,175,55,.06),transparent 36%);border:1px solid rgba(255,255,255,.08);box-shadow:0 22px 60px rgba(0,0,0,.38),inset 0 1px 0 rgba(255,255,255,.04);border-radius:26px;backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);overflow:hidden}
  .form-card{padding:18px;position:sticky;top:20px}
  .card-title{margin:0 0 6px;font-size:1.2rem;font-weight:900;letter-spacing:-.03em}
  .card-subtitle{margin:0 0 18px;color:var(--text-soft);line-height:1.7;font-size:.95rem}
  .field-grid{display:grid;gap:14px}
  .field-row-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .field label{display:block;margin-bottom:8px;color:#f0d77a;font-size:11px;font-weight:900;letter-spacing:.16em;text-transform:uppercase}
  .field input,.field select,.field textarea{width:100%;min-height:48px;border-radius:16px;border:1px solid rgba(255,255,255,.08);background:linear-gradient(180deg,rgba(255,255,255,.045),rgba(255,255,255,.03));color:var(--text);padding:0 14px;outline:none;font-size:.97rem;box-sizing:border-box}
  .field textarea{min-height:96px;padding:14px;resize:vertical}
  .days-box,.toggle-box{padding:14px;border-radius:18px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06)}
  .days-title{margin:0 0 10px;color:#f0d77a;font-size:11px;font-weight:900;letter-spacing:.16em;text-transform:uppercase}
  .days-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}
  .day-check,.toggle-check{position:relative}
  .day-check input,.toggle-check input{position:absolute;opacity:0;pointer-events:none}
  .day-check label{min-height:42px;display:flex;align-items:center;justify-content:center;border-radius:14px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06);color:rgba(247,243,234,.82);font-size:.9rem;font-weight:800;cursor:pointer;transition:.18s ease;margin:0;letter-spacing:0;text-transform:none}
  .day-check input:checked + label{background:linear-gradient(180deg,rgba(212,175,55,.20),rgba(255,255,255,.05));border-color:rgba(212,175,55,.28);color:#fff1bf}
  .toggle-check label{display:flex;gap:12px;align-items:flex-start;cursor:pointer;margin:0;text-transform:none;letter-spacing:0}
  .toggle-ui{width:52px;height:30px;border-radius:999px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.08);position:relative;flex:0 0 auto;transition:.2s ease;margin-top:2px}
  .toggle-ui::after{content:'';position:absolute;top:3px;left:3px;width:22px;height:22px;border-radius:50%;background:#fff;transition:.2s ease}
  .toggle-check input:checked + label .toggle-ui{background:linear-gradient(90deg,rgba(212,175,55,.45),rgba(212,175,55,.75));border-color:rgba(212,175,55,.24)}
  .toggle-check input:checked + label .toggle-ui::after{left:25px}
  .toggle-copy strong{display:block;font-size:.95rem;margin-bottom:4px}
  .toggle-copy span{display:block;color:rgba(247,243,234,.62);font-size:.88rem;line-height:1.5}
  .helper-note{margin-top:8px;color:rgba(247,243,234,.58);font-size:.88rem;line-height:1.6}
  .block-preview{display:none;padding:14px;border-radius:18px;background:rgba(212,175,55,.08);border:1px solid rgba(212,175,55,.18);color:#fff1bf;line-height:1.55;font-size:.92rem}
  .block-preview.show{display:block}
  .block-preview strong{display:block;margin-bottom:4px}
  .submit-btn{width:100%;min-height:54px;border:none;cursor:pointer;border-radius:18px;background:linear-gradient(90deg,#c8a22a 0%,#f2d778 50%,#cfa72d 100%);color:#1a1405;font-size:1rem;font-weight:900;letter-spacing:.02em}

  .visual-side{display:grid;gap:16px}
  .top-filters{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;padding:16px;border-radius:24px;background:linear-gradient(180deg,rgba(255,255,255,.05),rgba(255,255,255,.025)),radial-gradient(circle at top left,rgba(212,175,55,.08),transparent 42%);border:1px solid rgba(255,255,255,.08);box-shadow:0 22px 60px rgba(0,0,0,.40),inset 0 1px 0 rgba(255,255,255,.04);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px)}
  .filter-group{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
  .filter-label{color:#f0d77a;font-size:11px;font-weight:900;letter-spacing:.18em;text-transform:uppercase}
  .pro-select{min-height:48px;min-width:220px;border-radius:16px;border:1px solid rgba(255,255,255,.08);background:linear-gradient(180deg,rgba(255,255,255,.045),rgba(255,255,255,.03));color:var(--text);padding:0 14px;outline:none;font-size:.98rem;box-sizing:border-box}
  .flash-message{padding:14px 16px;border-radius:16px;font-weight:700;border:1px solid rgba(255,255,255,.08);box-shadow:0 18px 40px rgba(0,0,0,.22)}
  .flash-message.sucesso{background:rgba(32,201,151,.12);color:#b8ffe8;border-color:rgba(32,201,151,.28)}
  .flash-message.erro{background:rgba(255,95,109,.12);color:#ffd5da;border-color:rgba(255,95,109,.28)}

  .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
  .stat-card{padding:16px;border-radius:22px;background:linear-gradient(180deg,rgba(255,255,255,.05),rgba(255,255,255,.03));border:1px solid rgba(255,255,255,.08);box-shadow:0 18px 40px rgba(0,0,0,.24)}
  .stat-label{color:#f0d77a;font-size:11px;font-weight:900;letter-spacing:.16em;text-transform:uppercase;margin-bottom:8px}
  .stat-value{font-size:1.9rem;font-weight:900;letter-spacing:-.04em;line-height:1}

  .calendar-card{padding:16px}
  .calendar-header{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px}
  .calendar-heading{margin:0;font-size:1.2rem;font-weight:900;letter-spacing:-.03em}
  .calendar-subtitle{margin:4px 0 0;color:var(--text-soft);font-size:.93rem}

  .weekdays,.month-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:10px}
  .weekday{text-align:center;font-size:11px;color:#f0d77a;letter-spacing:.16em;text-transform:uppercase;font-weight:900;padding:8px 0}

  .calendar-day{min-height:120px;border-radius:20px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);padding:12px;position:relative;transition:.18s ease;display:flex;flex-direction:column;gap:8px}
  .calendar-day.empty{visibility:hidden}
  .calendar-day.has-blocks{cursor:pointer;background:linear-gradient(180deg,rgba(255,255,255,.05),rgba(255,255,255,.03)),radial-gradient(circle at top left,rgba(212,175,55,.07),transparent 60%)}
  .calendar-day.has-blocks:hover{transform:translateY(-2px);border-color:rgba(212,175,55,.22);box-shadow:0 14px 26px rgba(0,0,0,.16)}
  .calendar-day.today{outline:1px solid rgba(212,175,55,.22)}
  .day-number{font-size:1rem;font-weight:900;letter-spacing:-.02em}
  .day-badges{display:flex;flex-wrap:wrap;gap:6px}
  .mini-badge{display:inline-flex;align-items:center;justify-content:center;min-height:22px;padding:0 8px;border-radius:999px;font-size:10px;font-weight:900;letter-spacing:.06em;text-transform:uppercase;border:1px solid transparent;width:max-content}
  .mini-badge.total{background:rgba(180,180,180,.12);color:#ececec;border-color:rgba(180,180,180,.18)}
  .mini-badge.rec{background:rgba(212,175,55,.14);color:#ffe9a8;border-color:rgba(212,175,55,.22)}
  .mini-badge.pro{background:rgba(32,201,151,.14);color:#cefff2;border-color:rgba(32,201,151,.22)}
  .day-preview{margin-top:auto;display:grid;gap:5px}
  .preview-line{font-size:.78rem;line-height:1.25;color:rgba(247,243,234,.74);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .empty-state{padding:22px;border-radius:20px;border:1px dashed rgba(255,255,255,.10);background:rgba(255,255,255,.02);color:rgba(247,243,234,.62);text-align:center;line-height:1.8}

  .modal-overlay{position:fixed;inset:0;background:rgba(5,5,5,.72);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);display:flex;align-items:center;justify-content:center;padding:14px;opacity:0;visibility:hidden;transition:.28s ease;z-index:9999;overflow-y:auto}
  .modal-overlay.active{opacity:1;visibility:visible}
  .modal{width:100%;max-width:760px;padding:26px;border-radius:26px;background:linear-gradient(180deg,rgba(255,255,255,.08),rgba(255,255,255,.04));border:1px solid rgba(255,255,255,.10);box-shadow:0 24px 70px rgba(0,0,0,.40);transform:translateY(8px) scale(.98);transition:.28s ease;max-height:calc(100vh - 28px);overflow-y:auto}
  .modal-overlay.active .modal{transform:translateY(0) scale(1)}
  .modal-top{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px;position:sticky;top:0;z-index:2;background:linear-gradient(180deg,rgba(18,18,18,.96),rgba(18,18,18,.88));padding-bottom:10px}
  .modal-title{margin:0;font-size:1.25rem;font-weight:900;letter-spacing:-.03em}
  .close-btn{width:42px;height:42px;border-radius:12px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.04);color:#fff;cursor:pointer;font-size:1.2rem}
  .modal-list{display:grid;gap:12px}
  .block-item{padding:16px;border-radius:20px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06)}
  .block-top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:10px}
  .block-title{font-size:1rem;font-weight:900;letter-spacing:-.02em;margin:0 0 4px}
  .block-time{color:#f0d77a;font-size:.86rem;font-weight:800}
  .block-badges{display:flex;flex-wrap:wrap;gap:6px}
  .badge{display:inline-flex;align-items:center;min-height:26px;padding:0 10px;border-radius:999px;font-size:10px;font-weight:900;letter-spacing:.07em;text-transform:uppercase;border:1px solid transparent}
  .badge.blocked{background:rgba(180,180,180,.10);color:#ececec;border-color:rgba(180,180,180,.18)}
  .badge.rec{background:rgba(212,175,55,.14);color:#ffe9a8;border-color:rgba(212,175,55,.22)}
  .badge.pro{background:rgba(32,201,151,.14);color:#cefff2;border-color:rgba(32,201,151,.22)}
  .block-meta{display:grid;gap:8px;margin-bottom:12px}
  .block-meta-line{color:rgba(247,243,234,.82);line-height:1.5;font-size:.93rem}
  .block-meta-line strong{color:#f0d77a}
  .block-actions{display:flex;gap:10px;flex-wrap:wrap}
  .action-link{min-height:42px;padding:0 14px;border-radius:14px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;font-weight:800;border:1px solid rgba(255,255,255,.08);cursor:pointer}
  .action-link.primary{background:linear-gradient(180deg, rgba(212,175,55,.22), rgba(212,175,55,.14));color:#fff4cc;border-color:rgba(212,175,55,.22)}
  .remove-form{margin:0}
  .remove-btn{min-height:42px;padding:0 14px;border:none;cursor:pointer;border-radius:14px;background:linear-gradient(180deg, rgba(255,95,109,.22), rgba(255,95,109,.14));color:#ffe3e7;font-weight:800;border:1px solid rgba(255,95,109,.22)}

  @media (max-width:1150px){.page-grid{grid-template-columns:1fr}.form-card{position:static}}
  @media (max-width:980px){.stats-grid{grid-template-columns:1fr 1fr}}
  @media (max-width:700px){
    .hero h1{font-size:2.2rem}
    .hero p{font-size:.94rem;line-height:1.65}
    .form-card,.calendar-card{padding:14px;border-radius:20px}
    .top-filters{display:grid;grid-template-columns:1fr;padding:12px;border-radius:20px}
    .filter-group{display:grid;grid-template-columns:1fr;width:100%;gap:8px}
    .pro-select{width:100%;min-width:0}
    .field-row-2{grid-template-columns:1fr}
    .days-grid{grid-template-columns:repeat(2,1fr)}
    .weekdays,.month-grid{grid-template-columns:repeat(7,minmax(0,1fr));gap:5px}
    .weekday{font-size:9px;letter-spacing:.08em;padding:6px 0}
    .calendar-day{min-height:54px;border-radius:13px;padding:7px;gap:4px;justify-content:flex-start}
    .calendar-day.empty{visibility:visible;opacity:.18;pointer-events:none}
    .calendar-day.has-blocks{background:linear-gradient(180deg,rgba(212,175,55,.14),rgba(255,255,255,.035));border-color:rgba(212,175,55,.20)}
    .calendar-day.has-blocks:hover{transform:none;box-shadow:none}
    .calendar-day.today{outline:1px solid rgba(240,215,122,.45)}
    .day-number{font-size:.86rem;line-height:1}
    .day-badges{margin-top:auto;gap:3px}
    .mini-badge{min-height:16px;padding:0 5px;font-size:8px;letter-spacing:.02em}
    .mini-badge.rec,.mini-badge.pro{width:7px;height:7px;min-height:7px;padding:0;border-radius:50%;font-size:0}
    .day-preview{display:none}
    .calendar-subtitle{font-size:.88rem;line-height:1.5}
    .stats-grid{grid-template-columns:1fr}
    .block-actions{flex-direction:column}
    .action-link,.remove-btn{width:100%}
    .modal-overlay{align-items:flex-start;padding:10px}
    .modal{max-width:100%;width:100%;padding:18px;border-radius:20px;max-height:calc(100vh - 20px)}
  }
</style>

<section class="hero">
  <h1><span>Calendário de Bloqueios</span></h1>
  <p>
    Visualize os dias com bloqueios cadastrados e clique em um dia para ver os detalhes.
  </p>
</section>

<div class="page-grid">
  <aside class="glass form-card">
    <h3 class="card-title">Novo bloqueio</h3>
    <p class="card-subtitle">
      Crie bloqueios pontuais ou recorrentes. Se marcar dias da semana, o sistema entende como recorrência.
    </p>

    <form method="POST" class="field-grid">
      <input type="hidden" name="acao" value="novo_bloqueio">
      <input type="hidden" name="return_mes" value="<?= htmlspecialchars($mesSelecionado); ?>">
      <input type="hidden" name="return_profissional_id" value="<?= htmlspecialchars($profissionalFiltro); ?>">

      <div class="field">
        <label for="profissional_id">Profissional</label>
        <select name="profissional_id" id="profissional_id" required>
          <option value="">Selecione</option>
          <?php foreach ($profissionais as $prof): ?>
            <option value="<?= (int)$prof['id']; ?>" <?= $profissionalFiltro !== 'todos' && (string)$prof['id'] === (string)$profissionalFiltro ? 'selected' : ''; ?>>
              <?= htmlspecialchars($prof['nome']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field-row-2">
        <div class="field">
          <label for="data">Data inicial</label>
          <input type="date" name="data" id="data" value="<?= htmlspecialchars($primeiroDiaMes); ?>" required>
        </div>

        <div class="field">
          <label for="data_fim">Data final</label>
          <input type="date" name="data_fim" id="data_fim">
        </div>
      </div>
      <div class="helper-note" style="margin-top:-8px">
        Sem data final, bloqueia apenas a data inicial. Com data final, o bloqueio vale pelo período escolhido.
      </div>

      <div class="field-row-2">
        <div class="field">
          <label for="hora_inicio">Hora inicial</label>
          <select name="hora_inicio" id="hora_inicio" required>
            <option value="">Selecione</option>
            <?php foreach ($listaHorarios as $hora): ?>
              <option value="<?= htmlspecialchars($hora); ?>"><?= htmlspecialchars($hora); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label for="hora_fim">Hora final</label>
          <select name="hora_fim" id="hora_fim" required>
            <option value="">Selecione</option>
            <?php foreach ($listaHorarios as $hora): ?>
              <option value="<?= htmlspecialchars($hora); ?>"><?= htmlspecialchars($hora); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="days-box">
        <div class="days-title">Dias da semana</div>
        <div class="days-grid">
          <?php
          $diasMap = [
              '1' => 'Seg',
              '2' => 'Ter',
              '3' => 'Qua',
              '4' => 'Qui',
              '5' => 'Sex',
              '6' => 'Sáb',
              '0' => 'Dom',
          ];
          foreach ($diasMap as $valor => $label):
          ?>
            <div class="day-check">
              <input type="checkbox" name="dias_semana[]" id="dia_<?= $valor; ?>" value="<?= $valor; ?>">
              <label for="dia_<?= $valor; ?>"><?= $label; ?></label>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="helper-note">
          Se marcar dias da semana, o bloqueio vira recorrência interna. A data final limita até quando ela aparece.
        </div>
      </div>

      <?php if ($temPermiteProfissional): ?>
        <div class="toggle-box">
          <div class="toggle-check">
            <input type="checkbox" name="permitir_profissional" id="permitir_profissional" value="1">
            <label for="permitir_profissional">
              <span class="toggle-ui"></span>
              <span class="toggle-copy">
                <strong>Permitir marcação manual do profissional</strong>
                <span>Só profissional/admin poderá marcar nesse horário. Clientes continuam bloqueados.</span>
              </span>
            </label>
          </div>
        </div>
      <?php endif; ?>

      <div class="field">
        <label for="observacao">Observação</label>
        <textarea name="observacao" id="observacao" placeholder="Ex.: almoço, reunião, descanso, atendimento externo..."></textarea>
      </div>

      <div class="block-preview" id="blockPreview"></div>

      <button type="submit" class="submit-btn">Salvar bloqueio</button>
    </form>
  </aside>

  <section class="visual-side">
    <?php if ($flash && $msg): ?>
      <div class="flash-message <?= $flash === 'sucesso' ? 'sucesso' : 'erro'; ?>">
        <?= htmlspecialchars($msg); ?>
      </div>
    <?php endif; ?>

    <form method="GET" class="top-filters">
      <div class="filter-group">
        <a class="pro-select" style="display:flex;align-items:center;justify-content:center;text-decoration:none;min-width:120px;" href="?mes=<?= $mesAnterior; ?>&profissional_id=<?= urlencode($profissionalFiltro); ?>">Mês anterior</a>
        <a class="pro-select" style="display:flex;align-items:center;justify-content:center;text-decoration:none;min-width:90px;" href="?mes=<?= date('Y-m'); ?>&profissional_id=<?= urlencode($profissionalFiltro); ?>">Atual</a>
        <a class="pro-select" style="display:flex;align-items:center;justify-content:center;text-decoration:none;min-width:120px;" href="?mes=<?= $mesProximo; ?>&profissional_id=<?= urlencode($profissionalFiltro); ?>">Próximo mês</a>
      </div>

      <div class="filter-group">
        <span class="filter-label">Mês</span>
        <input
          type="month"
          name="mes"
          value="<?= htmlspecialchars($mesSelecionado); ?>"
          class="pro-select"
          onchange="this.form.submit()"
          style="min-width:180px;"
        >
      </div>

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
    </form>

    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-label">Dias com bloqueio</div>
        <div class="stat-value"><?= (int)$totalDiasComBloqueio; ?></div>
      </div>

      <div class="stat-card">
        <div class="stat-label">Bloqueios no mês</div>
        <div class="stat-value"><?= (int)$totalBloqueiosMes; ?></div>
      </div>

      <div class="stat-card">
        <div class="stat-label">Recorrentes</div>
        <div class="stat-value"><?= (int)$totalRecorrentesMes; ?></div>
      </div>

      <div class="stat-card">
        <div class="stat-label">Pontuais</div>
        <div class="stat-value"><?= (int)$totalPontuaisMes; ?></div>
      </div>
    </div>

    <div class="glass calendar-card">
      <div class="calendar-header">
        <div>
          <h3 class="calendar-heading"><?= $nomeMeses[$mesAtual]; ?> <?= $anoAtual; ?></h3>
          <p class="calendar-subtitle">Clique em um dia destacado para ver os bloqueios.</p>
        </div>
      </div>

      <?php if (empty($bloqueiosPorDia)): ?>
        <div class="empty-state">
          Nenhum bloqueio encontrado neste mês para esta visualização.
        </div>
      <?php else: ?>
        <div class="weekdays">
          <div class="weekday">Dom</div>
          <div class="weekday">Seg</div>
          <div class="weekday">Ter</div>
          <div class="weekday">Qua</div>
          <div class="weekday">Qui</div>
          <div class="weekday">Sex</div>
          <div class="weekday">Sáb</div>
        </div>

        <div class="month-grid">
          <?php for ($i = 0; $i < $primeiroDiaSemana; $i++): ?>
            <div class="calendar-day empty"></div>
          <?php endfor; ?>

          <?php for ($dia = 1; $dia <= $diasNoMes; $dia++): ?>
            <?php
              $dataDia = sprintf('%04d-%02d-%02d', $anoAtual, $mesAtual, $dia);
              $itensDia = $bloqueiosPorDia[$dataDia] ?? [];
              $hasBlocks = !empty($itensDia);
              $isToday = $dataDia === date('Y-m-d');

              $countRec = 0;
              $countPro = 0;
              foreach ($itensDia as $itemTmp) {
                  if ((int)($itemTmp['is_recorrente'] ?? 0) === 1) $countRec++;
                  if ((int)($itemTmp['permite_profissional'] ?? 0) === 1) $countPro++;
              }
            ?>
            <div
              class="calendar-day <?= $hasBlocks ? 'has-blocks' : ''; ?> <?= $isToday ? 'today' : ''; ?> js-open-day-modal"
              data-date="<?= htmlspecialchars($dataDia); ?>"
              <?= $hasBlocks ? '' : 'data-empty="1"' ?>
            >
              <div class="day-number"><?= $dia; ?></div>

              <?php if ($hasBlocks): ?>
                <div class="day-badges">
                  <div class="mini-badge total"><?= count($itensDia); ?> bloq.</div>
                  <?php if ($countRec > 0): ?>
                    <div class="mini-badge rec"><?= $countRec; ?> rec.</div>
                  <?php endif; ?>
                  <?php if ($countPro > 0): ?>
                    <div class="mini-badge pro"><?= $countPro; ?> pro</div>
                  <?php endif; ?>
                </div>

                <div class="day-preview">
                  <?php $previewCount = 0; foreach ($itensDia as $previewItem): if ($previewCount >= 2) break; ?>
                    <div class="preview-line">
                      <?= htmlspecialchars(substr($previewItem['hora_inicio'], 0, 5)); ?> · <?= htmlspecialchars($previewItem['profissional_nome']); ?>
                    </div>
                  <?php $previewCount++; endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>
</div>

<div class="modal-overlay" id="dayModalOverlay">
  <div class="modal">
    <div class="modal-top">
      <h3 class="modal-title" id="dayModalTitle">Bloqueios do dia</h3>
      <button class="close-btn" id="closeDayModal" type="button">×</button>
    </div>

    <div class="modal-list" id="dayModalList"></div>
  </div>
</div>

<script>
  const bloqueiosPorDia = <?= json_encode($bloqueiosPorDia, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  const allowManageBlock = <?= usuarioPodeGerenciarBloqueio() ? 'true' : 'false'; ?>;
  const formBloqueio = document.querySelector('form.field-grid');
  const previewBloqueio = document.getElementById('blockPreview');

  const dayModalOverlay = document.getElementById('dayModalOverlay');
  const closeDayModal = document.getElementById('closeDayModal');
  const dayModalTitle = document.getElementById('dayModalTitle');
  const dayModalList = document.getElementById('dayModalList');

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

  function formatDateBrJs(dateStr) {
    const parts = dateStr.split('-');
    if (parts.length !== 3) return dateStr;
    return `${parts[2]}/${parts[1]}/${parts[0]}`;
  }

  function updateBlockPreview() {
    if (!formBloqueio || !previewBloqueio) return;

    const profissional = formBloqueio.querySelector('#profissional_id');
    const data = formBloqueio.querySelector('#data')?.value || '';
    const dataFim = formBloqueio.querySelector('#data_fim')?.value || '';
    const inicio = formBloqueio.querySelector('#hora_inicio')?.value || '';
    const fim = formBloqueio.querySelector('#hora_fim')?.value || '';
    const dias = Array.from(formBloqueio.querySelectorAll('input[name="dias_semana[]"]:checked')).map(input => {
      const label = formBloqueio.querySelector(`label[for="${input.id}"]`);
      return label ? label.textContent.trim() : input.value;
    });

    if (!profissional?.value || !data || !inicio || !fim) {
      previewBloqueio.classList.remove('show');
      previewBloqueio.innerHTML = '';
      return;
    }

    const profNome = profissional.options[profissional.selectedIndex]?.textContent.trim() || 'Profissional';
    const periodoTexto = dataFim ? `${formatDateBrJs(data)} até ${formatDateBrJs(dataFim)}` : formatDateBrJs(data);
    const recTexto = dias.length ? `Toda semana em: ${dias.join(', ')} · ${periodoTexto}` : `Período: ${periodoTexto}`;
    previewBloqueio.innerHTML = `
      <strong>Prévia do bloqueio</strong>
      ${escapeHtml(profNome)}<br>
      ${escapeHtml(inicio)} às ${escapeHtml(fim)}<br>
      ${escapeHtml(recTexto)}
    `;
    previewBloqueio.classList.add('show');
  }

  if (formBloqueio) {
    formBloqueio.querySelectorAll('input, select, textarea').forEach(el => {
      el.addEventListener('change', updateBlockPreview);
      el.addEventListener('input', updateBlockPreview);
    });
    updateBlockPreview();
  }

  function openDayModal(dateStr) {
    const itens = bloqueiosPorDia[dateStr] || [];
    dayModalTitle.textContent = `Bloqueios de ${formatDateBrJs(dateStr)}`;
    dayModalList.innerHTML = '';

    if (!itens.length) {
      dayModalList.innerHTML = `<div class="empty-state">Nenhum bloqueio neste dia.</div>`;
    } else {
      itens.forEach(item => {
        const recorrente = Number(item.is_recorrente || 0) === 1;
        const permiteProfissional = Number(item.permite_profissional || 0) === 1;
        const observacao = item.observacao && item.observacao.trim() !== '' ? item.observacao : 'Sem observação';

        const diasTexto = (() => {
          const mapa = { '0':'Dom', '1':'Seg', '2':'Ter', '3':'Qua', '4':'Qui', '5':'Sex', '6':'Sáb' };
          if (!item.dias_semana_array || !item.dias_semana_array.length) return '';
          return item.dias_semana_array.map(d => mapa[String(d)] || d).join(', ');
        })();

        const badges = [
          `<span class="badge blocked">Bloqueio</span>`,
          recorrente ? `<span class="badge rec">Recorrente</span>` : '',
          permiteProfissional ? `<span class="badge pro">Profissional liberado</span>` : ''
        ].join('');

        const diasHtml = recorrente && diasTexto
          ? `<div class="block-meta-line"><strong>Dias:</strong> ${escapeHtml(diasTexto)}</div>`
          : '';

        const periodoHtml = recorrente
          ? `<div class="block-meta-line"><strong>Período:</strong> ${escapeHtml(formatDateBrJs(item.data_inicio || item.data_exibicao))}${item.data_fim ? ` até ${escapeHtml(formatDateBrJs(item.data_fim))}` : ' sem data final'}</div>`
          : '';

        const regraProfHtml = permiteProfissional
          ? `<div class="block-meta-line"><strong>Regra:</strong> Somente profissional/admin pode marcar manualmente nesse horário.</div>`
          : '';

        const agendarUrl = `agenda-visual.php?data=${encodeURIComponent(item.data_exibicao)}&profissional_id=${encodeURIComponent(item.profissional_id)}&abrir_bloqueio_agendamento=1&override_bloqueio=1&bloqueio_id=${encodeURIComponent(item.id)}&hora=${encodeURIComponent(String(item.hora_inicio).substring(0,5))}&hora_fim=${encodeURIComponent(String(item.hora_fim).substring(0,5))}`;

        const removeHtml = allowManageBlock ? `
          <form method="POST" class="remove-form" onsubmit="return confirm('Deseja remover este bloqueio?');">
            <input type="hidden" name="acao" value="remover_bloqueio">
            <input type="hidden" name="bloqueio_id" value="${Number(item.id)}">
            <input type="hidden" name="bloqueio_tipo" value="${escapeHtml(item.tipo_origem || 'pontual')}">
            <input type="hidden" name="return_mes" value="<?= htmlspecialchars($mesSelecionado, ENT_QUOTES); ?>">
            <input type="hidden" name="return_profissional_id" value="<?= htmlspecialchars($profissionalFiltro, ENT_QUOTES); ?>">
            <button type="submit" class="remove-btn">Remover bloqueio</button>
          </form>
        ` : '';

        const actionHtml = `
          <div class="block-actions">
            <a href="${agendarUrl}" class="action-link primary">Agendar neste horário</a>
            ${removeHtml}
          </div>
        `;

        dayModalList.innerHTML += `
          <div class="block-item">
            <div class="block-top">
              <div>
                <div class="block-title">${escapeHtml(item.profissional_nome || 'Profissional')}</div>
                <div class="block-time">${escapeHtml(String(item.hora_inicio).substring(0,5))} às ${escapeHtml(String(item.hora_fim).substring(0,5))}</div>
              </div>
              <div class="block-badges">${badges}</div>
            </div>

            <div class="block-meta">
              ${periodoHtml}
              ${diasHtml}
              ${regraProfHtml}
              <div class="block-meta-line"><strong>Observação:</strong> ${escapeHtml(observacao)}</div>
            </div>

            ${actionHtml}
          </div>
        `;
      });
    }

    dayModalOverlay.classList.add('active');
    document.body.style.overflow = 'hidden';
  }

  function closeDayModalFn() {
    dayModalOverlay.classList.remove('active');
    document.body.style.overflow = '';
  }

  document.querySelectorAll('.js-open-day-modal').forEach(el => {
    el.addEventListener('click', () => {
      const date = el.dataset.date || '';
      if (!date) return;
      if (el.dataset.empty === '1') return;
      openDayModal(date);
    });
  });

  closeDayModal.addEventListener('click', closeDayModalFn);

  dayModalOverlay.addEventListener('click', (e) => {
    if (e.target === dayModalOverlay) closeDayModalFn();
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeDayModalFn();
  });
</script>
<?php admin_shell_end(); ?>
