<?php
require_once 'config.php';
require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/phone.php';
date_default_timezone_set('America/Sao_Paulo');

/**
 * =========================================================
 * CONFIG
 * =========================================================
 */
const OPENING_TIME = '09:00';
const CLOSING_TIME = '20:00';
const SLOT_INTERVAL_MINUTES = 30;
const MAX_BOOKING_DAYS_AHEAD = 90;
const ASSISTANT_WHATSAPP = '5511947173110';

$salonLogoCandidates = [
    'assets/logo-salao',
    'assets/logo-salao.png',
    'assets/logo-salao.jpg',
    'assets/logo-salao.jpeg',
    'assets/logo-salao.webp',
    'assets/logo',
    'assets/logo.png',
    'assets/logo.jpg',
    'assets/logo.jpeg',
    'assets/logo.webp',
];

/**
 * =========================================================
 * HELPERS
 * =========================================================
 */
function respondJson(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function normalizeText(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = str_replace(
        ['á','à','ã','â','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','õ','ô','ö','ú','ù','û','ü','ç'],
        ['a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c'],
        $value
    );
    return $value;
}

function slugifyName(string $value): string
{
    $value = normalizeText($value);
    $value = preg_replace('/[^a-z0-9]+/u', '-', $value);
    return trim($value, '-');
}

function normalizePhone(string $phone): string
{
    return preg_replace('/\D+/', '', $phone ?? '');
}

function formatMoney($value): string
{
    return 'R$ ' . number_format((float)$value, 2, ',', '.');
}

function timeToMinutes(string $time): int
{
    $time = substr($time, 0, 5);
    [$h, $m] = explode(':', $time);
    return ((int)$h * 60) + (int)$m;
}

function minutesToHour(int $minutes): string
{
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    return str_pad((string)$hours, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)$mins, 2, '0', STR_PAD_LEFT);
}

function minutesToDisplayHour(int $minutes): string
{
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;

    if ($mins === 0) {
        return $hours . 'h';
    }

    return $hours . 'h' . str_pad((string)$mins, 2, '0', STR_PAD_LEFT);
}

function minutesToSqlTime(int $minutes): string
{
    return minutesToHour($minutes) . ':00';
}

function buildSlots(string $start, string $end, int $interval): array
{
    $slots = [];
    $startMin = timeToMinutes($start);
    $endMin = timeToMinutes($end);

    for ($min = $startMin; $min <= $endMin; $min += $interval) {
        $slots[] = minutesToHour($min);
    }

    return $slots;
}

function initialsFromName(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';

    foreach ($parts as $part) {
        if ($part !== '') {
            $initials .= mb_substr($part, 0, 1, 'UTF-8');
        }
        if (mb_strlen($initials, 'UTF-8') >= 2) {
            break;
        }
    }

    return mb_strtoupper($initials ?: 'AP', 'UTF-8');
}

function svgAvatarDataUri(string $name): string
{
    $initials = initialsFromName($name);
    $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="320" height="320" viewBox="0 0 320 320">
  <defs>
    <linearGradient id="g" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#f3dfa0"/>
      <stop offset="50%" stop-color="#d4af37"/>
      <stop offset="100%" stop-color="#9f7510"/>
    </linearGradient>
  </defs>
  <rect width="320" height="320" rx="160" fill="#0c0c0c"/>
  <circle cx="160" cy="160" r="148" fill="none" stroke="url(#g)" stroke-width="10"/>
  <text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle"
        font-family="Arial, Helvetica, sans-serif" font-size="110" font-weight="700" fill="#f7f3ea">{$initials}</text>
</svg>
SVG;

    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

function fileUrlIfExists(string $path): ?string
{
    $full = __DIR__ . '/' . ltrim($path, '/');
    return file_exists($full) ? $path : null;
}

function pickFirstExisting(array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        $hit = fileUrlIfExists($candidate);
        if ($hit !== null) {
            return $hit;
        }
    }
    return null;
}

function canonicalProfessionalName(string $dbName): string
{
    $normalized = normalizeText($dbName);

    if (strpos($normalized, 'puchetti') !== false) {
        return 'André Puchetti';
    }

    if (strpos($normalized, 'amaro') !== false) {
        return 'André Amaro';
    }

    $words = preg_split('/\s+/', trim($dbName));
    $words = array_map(function ($w) {
        return mb_convert_case($w, MB_CASE_TITLE, 'UTF-8');
    }, $words);

    return implode(' ', $words);
}

function professionalPhotoUrl(array $professional): string
{
    $dbName = $professional['nome'] ?? '';
    $normalized = normalizeText($dbName);
    $slug = slugifyName($dbName);
    $dbPhoto = trim((string)($professional['foto'] ?? ''));

    if ($dbPhoto !== '') {
        if (preg_match('/^https?:\/\//i', $dbPhoto)) {
            return $dbPhoto;
        }

        $hit = fileUrlIfExists($dbPhoto);
        if ($hit !== null) {
            return $hit;
        }
    }

    $candidates = [];

    if (strpos($normalized, 'puchetti') !== false) {
        $candidates = array_merge($candidates, [
            'assets/profissionais/andre-puchetti',
            'assets/profissionais/andre-puchetti.png',
            'assets/profissionais/andre-puchetti.jpg',
            'assets/profissionais/andre-puchetti.jpeg',
            'assets/profissionais/andre-puchetti.webp',
        ]);
    }

    if (strpos($normalized, 'amaro') !== false) {
        $candidates = array_merge($candidates, [
            'assets/profissionais/andre-amaro',
            'assets/profissionais/andre-amaro.png',
            'assets/profissionais/andre-amaro.jpg',
            'assets/profissionais/andre-amaro.jpeg',
            'assets/profissionais/andre-amaro.webp',
        ]);
    }

    $candidates = array_merge($candidates, [
        "assets/profissionais/{$slug}",
        "assets/profissionais/{$slug}.png",
        "assets/profissionais/{$slug}.jpg",
        "assets/profissionais/{$slug}.jpeg",
        "assets/profissionais/{$slug}.webp",
    ]);

    $hit = pickFirstExisting($candidates);
    if ($hit !== null) {
        return $hit;
    }

    return svgAvatarDataUri(canonicalProfessionalName($dbName));
}

function dateIsValid(string $date): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt && $dt->format('Y-m-d') === $date;
}

function isSunday(string $date): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $date, new DateTimeZone('America/Sao_Paulo'));
    return $dt && (int)$dt->format('w') === 0;
}

function bookingDateMessage(string $date): ?string
{
    $today = date('Y-m-d');
    $maxDate = date('Y-m-d', strtotime('+' . MAX_BOOKING_DAYS_AHEAD . ' days'));

    if ($date < $today || $date > $maxDate) {
        return 'Data fora do intervalo permitido.';
    }

    if (isSunday($date)) {
        return 'Não abrimos aos domingos. Escolha outra data.';
    }

    return null;
}

function safeDateBr(string $date): string
{
    return date('d/m/Y', strtotime($date));
}

function hasColumn(mysqli $conn, string $table, string $column): bool
{
    $tableEsc = $conn->real_escape_string($table);
    $columnEsc = $conn->real_escape_string($column);
    $sql = "SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'";
    $res = $conn->query($sql);
    return $res && $res->num_rows > 0;
}

function inferServiceCategory(string $serviceName): string
{
    $name = normalizeText($serviceName);

    if (strpos($name, 'masculino') !== false) {
        return 'masculino';
    }

    if (strpos($name, 'feminino') !== false) {
        return 'feminino';
    }

    $femaleKeywords = [
        'escova',
        'babyliss',
        'progressiva',
        'mechas',
        'coloracao',
        'coloração',
        'hidratacao',
        'hidratação',
        'selagem',
        'botox feminino',
        'franja',
        'cachos',
        'feminino'
    ];

    foreach ($femaleKeywords as $keyword) {
        if (strpos($name, normalizeText($keyword)) !== false) {
            return 'feminino';
        }
    }

    $maleKeywords = [
        'barba',
        'degrade',
        'degradê',
        'pezinho',
        'navalha',
        'pigmentacao',
        'pigmentação',
        'botox masculino',
        'luzes masculino',
        'sobrancelha masculina',
        'masculino'
    ];

    foreach ($maleKeywords as $keyword) {
        if (strpos($name, normalizeText($keyword)) !== false) {
            return 'masculino';
        }
    }

    return 'indefinido';
}

function servicePublico(array $service): string
{
    $publico = trim((string)($service['publico'] ?? ''));
    if (in_array($publico, ['masculino', 'feminino', 'ambos'], true)) {
        return $publico;
    }

    $categoriaInferida = inferServiceCategory($service['nome'] ?? '');
    return in_array($categoriaInferida, ['masculino', 'feminino'], true) ? $categoriaInferida : 'ambos';
}

function serviceCategories(array $service): array
{
    $publico = servicePublico($service);

    if ($publico === 'ambos') {
        return ['masculino', 'feminino'];
    }

    return [$publico];
}

function serviceAllowedProfessionalIds(array $service): array
{
    $raw = trim((string)($service['profissionais_ids'] ?? 'todos'));
    if ($raw === '' || $raw === 'todos') {
        return [];
    }

    $ids = [];
    foreach (explode(',', $raw) as $id) {
        $idInt = (int)$id;
        if ($idInt > 0) {
            $ids[] = $idInt;
        }
    }

    return array_values(array_unique($ids));
}

function serviceCanBeBookedByProfessional(array $service, int $professionalId): bool
{
    $ids = serviceAllowedProfessionalIds($service);
    return empty($ids) || in_array($professionalId, $ids, true);
}

function analyticsTableExists(mysqli $conn): bool
{
    $res = $conn->query("SHOW TABLES LIKE 'analytics_eventos'");
    return $res && $res->num_rows > 0;
}

function servicoPrecisaAnalise(string $nome): bool
{
    $nome = normalizeText($nome);

    return strpos($nome, 'botox feminino') !== false
        || strpos($nome, 'progressiva feminino') !== false
        || strpos($nome, 'platinado feminino') !== false
        || strpos($nome, 'escova feminino') !== false
        || strpos($nome, 'hidratacao feminino') !== false
        || strpos($nome, 'hidratação feminino') !== false;
}

function serviceRequiresAnalysis(array $service): bool
{
    if (array_key_exists('precisa_analise', $service)) {
        return (int)$service['precisa_analise'] === 1;
    }

    return servicoPrecisaAnalise($service['nome'] ?? '');
}

function servicePriority(string $serviceName): int
{
    $name = normalizeText($serviceName);

    if (strpos($name, 'corte') !== false) return 1;
    if (strpos($name, 'cabelo') !== false) return 2;
    if (strpos($name, 'barba') !== false) return 3;
    if (strpos($name, 'combo') !== false) return 4;

    if (servicoPrecisaAnalise($serviceName)) return 999;

    return 50;
}

function servicePriceLabel(array $service): string
{
    if (!empty($service['precisa_analise'])) {
        return 'Valor após análise';
    }

    return formatMoney($service['preco']);
}

function getServiceById(mysqli $conn, int $serviceId): ?array
{
    $selectAnalise = hasColumn($conn, 'servicos', 'precisa_analise') ? ', precisa_analise' : '';
    $selectPublico = hasColumn($conn, 'servicos', 'publico') ? ', publico' : ", 'ambos' AS publico";
    $selectProfissionais = hasColumn($conn, 'servicos', 'profissionais_ids') ? ', profissionais_ids' : ", 'todos' AS profissionais_ids";
    $stmt = $conn->prepare("SELECT id, nome, duracao, preco{$selectAnalise}{$selectPublico}{$selectProfissionais} FROM servicos WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $serviceId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result ? $result->fetch_assoc() : null;
}

function getProfessionalById(mysqli $conn, int $professionalId): ?array
{
    $hasFoto = hasColumn($conn, 'profissionais', 'foto');

    if ($hasFoto) {
        $stmt = $conn->prepare("SELECT id, nome, foto FROM profissionais WHERE id = ? LIMIT 1");
    } else {
        $stmt = $conn->prepare("SELECT id, nome, '' AS foto FROM profissionais WHERE id = ? LIMIT 1");
    }

    $stmt->bind_param('i', $professionalId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result ? $result->fetch_assoc() : null;
}

function getBlockedIntervals(mysqli $conn, int $professionalId, string $date): array
{
    $intervals = [];

    $stmtPontual = $conn->prepare("
        SELECT hora_inicio, hora_fim
        FROM bloqueios
        WHERE profissional_id = ?
          AND data = ?
    ");
    $stmtPontual->bind_param('is', $professionalId, $date);
    $stmtPontual->execute();
    $resPontual = $stmtPontual->get_result();

    while ($row = $resPontual->fetch_assoc()) {
        if (!empty($row['hora_inicio']) && !empty($row['hora_fim'])) {
            $intervals[] = [
                'start' => timeToMinutes($row['hora_inicio']),
                'end'   => timeToMinutes($row['hora_fim']),
            ];
        }
    }

    $weekday = (int)date('N', strtotime($date));

    $stmtRec = $conn->prepare("
        SELECT hora_inicio, hora_fim, dias_semana
        FROM bloqueios_recorrentes
        WHERE profissional_id = ?
          AND ativo = 1
          AND data_inicio <= ?
          AND (data_fim IS NULL OR data_fim >= ?)
    ");
    $stmtRec->bind_param('iss', $professionalId, $date, $date);
    $stmtRec->execute();
    $resRec = $stmtRec->get_result();

    while ($row = $resRec->fetch_assoc()) {
        $dias = array_map('intval', explode(',', (string)$row['dias_semana']));

        if (in_array($weekday, $dias, true)) {
            if (!empty($row['hora_inicio']) && !empty($row['hora_fim'])) {
                $intervals[] = [
                    'start' => timeToMinutes($row['hora_inicio']),
                    'end'   => timeToMinutes($row['hora_fim']),
                ];
            }
        }
    }

    return $intervals;
}

function getAppointmentIntervals(mysqli $conn, int $professionalId, string $date): array
{
    $intervals = [];
    $hasHoraFim = hasColumn($conn, 'agendamentos', 'hora_fim');

    if ($hasHoraFim) {
        $stmt = $conn->prepare("
            SELECT ag.hora, ag.hora_fim, s.duracao
            FROM agendamentos ag
            INNER JOIN servicos s ON s.id = ag.servico_id
            WHERE ag.profissional_id = ?
              AND ag.data = ?
              AND ag.status IN ('confirmado', 'pendente')
        ");
    } else {
        $stmt = $conn->prepare("
            SELECT ag.hora, s.duracao
            FROM agendamentos ag
            INNER JOIN servicos s ON s.id = ag.servico_id
            WHERE ag.profissional_id = ?
              AND ag.data = ?
              AND ag.status IN ('confirmado', 'pendente')
        ");
    }

    $stmt->bind_param('is', $professionalId, $date);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $start = timeToMinutes($row['hora']);

        if ($hasHoraFim && !empty($row['hora_fim'])) {
            $end = timeToMinutes($row['hora_fim']);
        } else {
            $duration = max(5, (int)$row['duracao']);
            $end = $start + $duration;
        }

        $intervals[] = [
            'start' => $start,
            'end'   => $end,
        ];
    }

    return $intervals;
}

function intervalOverlaps(int $startA, int $endA, int $startB, int $endB): bool
{
    return ($startA < $endB) && ($endA > $startB);
}

function generateAvailability(mysqli $conn, int $professionalId, int $serviceDuration, string $date): array
{
    $slots = buildSlots(OPENING_TIME, CLOSING_TIME, SLOT_INTERVAL_MINUTES);
    $blocked = getBlockedIntervals($conn, $professionalId, $date);
    $appointments = getAppointmentIntervals($conn, $professionalId, $date);

    $openingMin = timeToMinutes(OPENING_TIME);
    $closingMin = timeToMinutes(CLOSING_TIME);
    $now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
    $today = $now->format('Y-m-d');

    $result = [];

    foreach ($slots as $slot) {
        $slotStart = timeToMinutes($slot);
        $slotEnd = $slotStart + $serviceDuration;
        $available = true;
        $reason = '';

        if ($slotStart < $openingMin || $slotEnd > $closingMin) {
            $available = false;
            $reason = 'fora_do_expediente';
        }

        if ($available && $date === $today) {
            $slotDateTime = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $slot, new DateTimeZone('America/Sao_Paulo'));
            if ($slotDateTime && $slotDateTime < $now) {
                $available = false;
                $reason = 'horario_passado';
            }
        }

        if ($available) {
            foreach ($blocked as $block) {
                if (intervalOverlaps($slotStart, $slotEnd, $block['start'], $block['end'])) {
                    $available = false;
                    $reason = 'bloqueado';
                    break;
                }
            }
        }

        if ($available) {
            foreach ($appointments as $appointment) {
                if (intervalOverlaps($slotStart, $slotEnd, $appointment['start'], $appointment['end'])) {
                    $available = false;
                    $reason = 'ocupado';
                    break;
                }
            }
        }

        $result[] = [
            'time' => $slot,
            'label' => minutesToDisplayHour($slotStart),
            'available' => $available,
            'status' => $available ? 'available' : 'unavailable',
            'reason' => $reason,
        ];
    }

    return $result;
}

/**
 * =========================================================
 * AJAX - ANALYTICS INTERNO
 * =========================================================
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'analytics_event') {
    if (!analyticsTableExists($conn)) {
        respondJson(['ok' => true]);
    }

    $eventosPermitidos = [
        'page_view',
        'inicio_fluxo',
        'nome_informado',
        'telefone_informado',
        'categoria_escolhida',
        'profissional_escolhido',
        'servico_escolhido',
        'data_escolhida',
        'horario_escolhido',
        'revisao_aberta',
        'agendamento_finalizado',
        'pedido_analise_enviado',
    ];

    $evento = trim($_POST['evento'] ?? '');
    if (!in_array($evento, $eventosPermitidos, true)) {
        respondJson(['ok' => false], 422);
    }

    $sessao = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_POST['sessao'] ?? '');
    if ($sessao === '') {
        $sessao = bin2hex(random_bytes(12));
    }
    $sessao = substr($sessao, 0, 80);

    $etapa = max(0, min(20, (int)($_POST['etapa'] ?? 0)));
    $categoria = trim($_POST['categoria'] ?? '');
    $profissionalId = (int)($_POST['profissional_id'] ?? 0);
    $servicoId = (int)($_POST['servico_id'] ?? 0);
    $dataEscolhida = trim($_POST['data'] ?? '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataEscolhida)) {
        $dataEscolhida = null;
    }

    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    $stmtAnalytics = $conn->prepare("
        INSERT INTO analytics_eventos
            (sessao, evento, etapa, categoria, profissional_id, servico_id, data_escolhida, user_agent, criado_em)
        VALUES
            (?, ?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), ?, ?, NOW())
    ");
    $stmtAnalytics->bind_param(
        'ssisiiss',
        $sessao,
        $evento,
        $etapa,
        $categoria,
        $profissionalId,
        $servicoId,
        $dataEscolhida,
        $userAgent
    );
    $stmtAnalytics->execute();

    respondJson(['ok' => true]);
}

/**
 * =========================================================
 * AJAX - HORÁRIOS
 * =========================================================
 */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'slots') {
    $professionalId = (int)($_GET['profissional_id'] ?? 0);
    $serviceId = (int)($_GET['servico_id'] ?? 0);
    $date = trim($_GET['data'] ?? '');

    if ($professionalId <= 0 || $serviceId <= 0 || !dateIsValid($date)) {
        respondJson([
            'ok' => false,
            'message' => 'Parâmetros inválidos.'
        ], 422);
    }

    $dateError = bookingDateMessage($date);
    if ($dateError !== null) {
        respondJson([
            'ok' => false,
            'message' => $dateError
        ], 422);
    }

    $service = getServiceById($conn, $serviceId);
    $professional = getProfessionalById($conn, $professionalId);

    if (!$service || !$professional) {
        respondJson([
            'ok' => false,
            'message' => 'Serviço ou profissional não encontrado.'
        ], 404);
    }

    $serviceCategory = servicePublico($service);
    $professionalPublicName = canonicalProfessionalName($professional['nome']);

    if ($serviceCategory === 'feminino' && $professionalPublicName !== 'André Puchetti') {
        respondJson([
            'ok' => false,
            'message' => 'Serviços femininos podem ser agendados apenas com André Puchetti.'
        ], 422);
    }

    if (!serviceCanBeBookedByProfessional($service, $professionalId)) {
        respondJson([
            'ok' => false,
            'message' => 'Esse serviço não está disponível para o profissional escolhido.'
        ], 422);
    }

    $slots = generateAvailability($conn, $professionalId, max(5, (int)$service['duracao']), $date);

    respondJson([
        'ok' => true,
        'slots' => $slots,
        'service_duration' => (int)$service['duracao'],
    ]);
}

/**
 * =========================================================
 * AJAX - AGENDAR
 * =========================================================
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'analysis_request') {
    $name = trim($_POST['nome'] ?? '');
    $phone = normalizePhone($_POST['telefone'] ?? '');
    $professionalId = (int)($_POST['profissional_id'] ?? 0);
    $serviceId = (int)($_POST['servico_id'] ?? 0);
    $category = trim($_POST['categoria'] ?? '');

    if ($name === '' || $phone === '' || $professionalId <= 0 || $serviceId <= 0) {
        respondJson([
            'ok' => false,
            'message' => 'Preencha nome, WhatsApp, profissional e serviço.'
        ], 422);
    }

    $professional = getProfessionalById($conn, $professionalId);
    $service = getServiceById($conn, $serviceId);

    if (!$professional || !$service || !serviceRequiresAnalysis($service)) {
        respondJson([
            'ok' => false,
            'message' => 'Pedido de análise inválido.'
        ], 422);
    }

    $serviceCategory = servicePublico($service);
    $professionalPublicName = canonicalProfessionalName($professional['nome']);

    if ($serviceCategory === 'feminino' && $professionalPublicName !== 'André Puchetti') {
        respondJson([
            'ok' => false,
            'message' => 'Serviços femininos podem ser agendados apenas com André Puchetti.'
        ], 422);
    }

    if (!serviceCanBeBookedByProfessional($service, $professionalId)) {
        respondJson([
            'ok' => false,
            'message' => 'Esse serviço não está disponível para o profissional escolhido.'
        ], 422);
    }

    $stmt = $conn->prepare("
        INSERT INTO pedidos_analise (nome, telefone, profissional_id, servico_id, categoria, status)
        VALUES (?, ?, ?, ?, ?, 'pendente')
    ");
    $stmt->bind_param('ssiis', $name, $phone, $professionalId, $serviceId, $category);
    $stmt->execute();

    respondJson([
        'ok' => true,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'book') {
    $name = trim($_POST['nome'] ?? '');
    $phone = normalizePhone($_POST['telefone'] ?? '');
    $professionalId = (int)($_POST['profissional_id'] ?? 0);
    $serviceId = (int)($_POST['servico_id'] ?? 0);
    $date = trim($_POST['data'] ?? '');
    $time = trim($_POST['hora'] ?? '');

    if ($name === '' || $phone === '' || $professionalId <= 0 || $serviceId <= 0 || !dateIsValid($date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
        respondJson([
            'ok' => false,
            'message' => 'Preencha todos os campos corretamente.'
        ], 422);
    }

    $dateError = bookingDateMessage($date);
    if ($dateError !== null) {
        respondJson([
            'ok' => false,
            'message' => $dateError
        ], 422);
    }

    $professional = getProfessionalById($conn, $professionalId);
    $service = getServiceById($conn, $serviceId);

    if (!$professional || !$service) {
        respondJson([
            'ok' => false,
            'message' => 'Profissional ou serviço não encontrado.'
        ], 404);
    }

    $serviceCategory = servicePublico($service);
    $professionalPublicName = canonicalProfessionalName($professional['nome']);

    if ($serviceCategory === 'feminino' && $professionalPublicName !== 'André Puchetti') {
        respondJson([
            'ok' => false,
            'message' => 'Serviços femininos podem ser agendados apenas com André Puchetti.'
        ], 422);
    }

    if (!serviceCanBeBookedByProfessional($service, $professionalId)) {
        respondJson([
            'ok' => false,
            'message' => 'Esse serviço não está disponível para o profissional escolhido.'
        ], 422);
    }

    if (serviceRequiresAnalysis($service)) {
        respondJson([
            'ok' => false,
            'message' => 'Esse serviço requer análise prévia do profissional para definir o valor total.'
        ], 422);
    }

    $availability = generateAvailability($conn, $professionalId, max(5, (int)$service['duracao']), $date);
    $chosenSlot = null;

    foreach ($availability as $slot) {
        if ($slot['time'] === $time) {
            $chosenSlot = $slot;
            break;
        }
    }

    if (!$chosenSlot || !$chosenSlot['available']) {
        respondJson([
            'ok' => false,
            'message' => 'Esse horário não está mais disponível. Escolha outro.'
        ], 409);
    }

    $hasHoraFim = hasColumn($conn, 'agendamentos', 'hora_fim');
    $horaInicioMinutes = timeToMinutes($time);
    $horaFimMinutes = $horaInicioMinutes + max(5, (int)$service['duracao']);

    $conn->begin_transaction();

    try {
        $clienteId = null;

        $clienteResult = obterOuCriarClientePorWhatsapp($conn, $name, $phone);

        if (!$clienteResult['ok']) {
            throw new RuntimeException($clienteResult['error'] ?: 'Não foi possível cadastrar o cliente agora.');
        }

        $clienteId = (int)$clienteResult['id'];
        $cliente = $clienteResult['cliente'] ?: buscarClientePorWhatsapp($conn, telefoneWhatsapp($phone));

        $horaBanco = $time . ':00';
        $status = 'confirmado';

        if ($hasHoraFim) {
            $horaFimBanco = minutesToSqlTime($horaFimMinutes);

            $stmtInsertAgendamento = $conn->prepare("
                INSERT INTO agendamentos (cliente_id, profissional_id, servico_id, data, hora, hora_fim, status)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtInsertAgendamento->bind_param(
                'iiissss',
                $clienteId,
                $professionalId,
                $serviceId,
                $date,
                $horaBanco,
                $horaFimBanco,
                $status
            );
        } else {
            $stmtInsertAgendamento = $conn->prepare("
                INSERT INTO agendamentos (cliente_id, profissional_id, servico_id, data, hora, status)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmtInsertAgendamento->bind_param(
                'iiisss',
                $clienteId,
                $professionalId,
                $serviceId,
                $date,
                $horaBanco,
                $status
            );
        }

        $stmtInsertAgendamento->execute();
        $conn->commit();

        if (!empty($cliente['email'] ?? '')) {
            agenda_send_booking_email($cliente['email'], $name, [
                'profissional' => canonicalProfessionalName($professional['nome']),
                'servico' => $service['nome'],
                'data' => safeDateBr($date),
                'hora' => minutesToDisplayHour($horaInicioMinutes),
            ], 'created');
        }

        respondJson([
            'ok' => true,
            'message' => 'Agendamento confirmado com sucesso.',
            'booking' => [
                'nome' => $name,
                'telefone' => $phone,
                'profissional' => canonicalProfessionalName($professional['nome']),
                'servico' => $service['nome'],
                'preco' => formatMoney($service['preco']),
                'data' => safeDateBr($date),
                'hora' => minutesToDisplayHour($horaInicioMinutes),
            ],
            'assistant_whatsapp' => ASSISTANT_WHATSAPP,
        ]);
    } catch (Throwable $e) {
        error_log('Erro ao concluir agendamento publico: ' . $e->getMessage());
        $conn->rollback();

        respondJson([
            'ok' => false,
            'message' => 'Não foi possível concluir o agendamento agora. Tente novamente.'
        ], 500);
    }
}

/**
 * =========================================================
 * INITIAL DATA
 * =========================================================
 */
$professionals = [];
$services = [];
$salonLogo = pickFirstExisting($salonLogoCandidates);

$sqlProfessionals = "SELECT id, nome" . (hasColumn($conn, 'profissionais', 'foto') ? ", foto" : ", '' AS foto") . " FROM profissionais ORDER BY nome ASC";
$resultProfessionals = $conn->query($sqlProfessionals);
if ($resultProfessionals && $resultProfessionals->num_rows > 0) {
    while ($row = $resultProfessionals->fetch_assoc()) {
        $nomePublico = canonicalProfessionalName($row['nome']);

        $row['nome_publico'] = $nomePublico;
        $row['foto_publica'] = professionalPhotoUrl($row);

        if ($nomePublico === 'André Puchetti') {
            $row['categorias_atendidas'] = ['masculino', 'feminino'];
        } elseif ($nomePublico === 'André Amaro') {
            $row['categorias_atendidas'] = ['masculino'];
        } else {
            $row['categorias_atendidas'] = ['masculino'];
        }

        $professionals[] = $row;
    }
}

$selectServiceAnalise = hasColumn($conn, 'servicos', 'precisa_analise') ? ', precisa_analise' : ', 0 AS precisa_analise';
$selectServicePublico = hasColumn($conn, 'servicos', 'publico') ? ', publico' : ", 'ambos' AS publico";
$selectServiceProfissionais = hasColumn($conn, 'servicos', 'profissionais_ids') ? ', profissionais_ids' : ", 'todos' AS profissionais_ids";
$selectServiceAtivo = hasColumn($conn, 'servicos', 'ativo') ? ' WHERE ativo = 1' : '';
$sqlServices = "SELECT id, nome, duracao, preco{$selectServiceAnalise}{$selectServicePublico}{$selectServiceProfissionais} FROM servicos{$selectServiceAtivo}";
$resultServices = $conn->query($sqlServices);
if ($resultServices && $resultServices->num_rows > 0) {
    while ($row = $resultServices->fetch_assoc()) {
        $row['categoria'] = servicePublico($row);
        $row['categorias'] = serviceCategories($row);
        $row['profissionais_ids_array'] = serviceAllowedProfessionalIds($row);
        $row['prioridade'] = servicePriority($row['nome']);
        $row['precisa_analise'] = serviceRequiresAnalysis($row);
        $row['preco_label'] = servicePriceLabel($row);
        $services[] = $row;
    }
}

usort($services, function ($a, $b) {
    if ($a['categoria'] === $b['categoria']) {
        if ($a['prioridade'] === $b['prioridade']) {
            return strcasecmp($a['nome'], $b['nome']);
        }
        return $a['prioridade'] <=> $b['prioridade'];
    }
    return strcasecmp($a['categoria'], $b['categoria']);
});

$today = date('Y-m-d');
$maxDate = date('Y-m-d', strtotime('+' . MAX_BOOKING_DAYS_AHEAD . ' days'));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Agende seu horário | André Puchetti Hair Stylist</title>
<?php render_seo_meta(
    'Agende seu horário | André Puchetti Hair Stylist',
    'Agende online seu horário no Salão André Puchetti em poucos passos. Escolha profissional, serviço, data e horário com praticidade.',
    ['favicon_path' => 'assets/logo-salao.png']
); ?>
  <style>
    :root {
      --bg: #070707;
      --text: #f7f3ea;
      --text-soft: rgba(247,243,234,0.75);
      --text-muted: rgba(247,243,234,0.52);
      --gold: #d4af37;
      --gold-soft: #f3dfa0;
      --green: #20c997;
      --red: #ff5f6d;
      --content-width: 860px;
    }

    * { box-sizing: border-box; }

    html, body {
      margin: 0;
      padding: 0;
      min-height: 100%;
      background:
        radial-gradient(circle at 10% 20%, rgba(212,175,55,0.10), transparent 22%),
        radial-gradient(circle at 90% 10%, rgba(212,175,55,0.08), transparent 18%),
        linear-gradient(180deg, #050505 0%, #0a0a0a 44%, #0d0d0d 100%);
      color: var(--text);
      font-family: Inter, Arial, sans-serif;
      overflow-x: hidden;
    }

    body::before {
      content: "";
      position: fixed;
      inset: 0;
      pointer-events: none;
      opacity: .11;
      background-image:
        linear-gradient(rgba(212,175,55,0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(212,175,55,0.04) 1px, transparent 1px);
      background-size: 34px 34px;
      mask-image: linear-gradient(to bottom, rgba(0,0,0,.95), transparent 95%);
      z-index: 0;
    }

    .page {
      position: relative;
      z-index: 2;
      min-height: 100vh;
      width: 100%;
      padding: 18px 16px 40px;
      display: flex;
      justify-content: center;
    }

    .flow-shell {
      width: 100%;
      max-width: var(--content-width);
      display: flex;
      flex-direction: column;
      gap: 18px;
    }

    .brand-top {
      display: flex;
      align-items: center;
      justify-content: center;
      padding-top: 4px;
    }

    .brand-logo {
      width: min(190px, 42vw);
      height: auto;
      object-fit: contain;
      filter: drop-shadow(0 10px 28px rgba(0,0,0,.28));
    }

    .brand-logo.fallback {
      width: 90px;
      height: 90px;
      border-radius: 50%;
      border: 1px solid rgba(212,175,55,.22);
      background: linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.02));
      display: grid;
      place-items: center;
      color: var(--gold-soft);
      font-weight: 800;
      letter-spacing: .08em;
      text-transform: uppercase;
      font-size: 12px;
      text-align: center;
      line-height: 1.35;
      padding: 12px;
    }

    .progress-wrap {
      margin-bottom: 12px;
    }

    .progress-top {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 12px;
      margin-bottom: 10px;
    }

    .progress-step {
      color: var(--text-muted);
      font-size: 12px;
      font-weight: 700;
    }

    .progress-bar {
      width: 100%;
      height: 8px;
      border-radius: 999px;
      background: rgba(255,255,255,.06);
      overflow: hidden;
      border: 1px solid rgba(255,255,255,.06);
    }

    .progress-fill {
      width: 10%;
      height: 100%;
      border-radius: inherit;
      background:
        linear-gradient(115deg, rgba(255,255,255,0) 0%, rgba(255,255,255,.42) 18%, rgba(255,255,255,0) 34%),
        linear-gradient(90deg, #9f6f09 0%, #d4af37 34%, #fff1b5 52%, #d4af37 70%, #8f6408 100%);
      background-size: 130px 100%, 220% 100%;
      transition: width .35s ease;
      box-shadow: 0 0 24px rgba(212,175,55,.28);
      animation: liquidGold 2.4s linear infinite;
    }

    @keyframes liquidGold {
      0% {
        background-position: -140px 0, 0% 50%;
      }
      100% {
        background-position: 220px 0, 100% 50%;
      }
    }

    .step-stage {
      position: relative;
      min-height: 66vh;
    }

    .step {
      display: none;
      min-height: 100%;
      padding: 6px 0 4px;
      transform: translateY(10px);
      opacity: 0;
      transition: opacity .28s ease, transform .28s ease;
    }

    .step.active {
      display: flex;
      flex-direction: column;
      justify-content: flex-start;
      align-items: center;
      text-align: center;
      opacity: 1;
      transform: translateY(0);
      animation: premiumFade .28s ease;
    }

    @keyframes premiumFade {
      from { opacity: 0; transform: translateY(12px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .eyebrow {
      color: var(--gold-soft);
      font-size: 12px;
      font-weight: 800;
      letter-spacing: .18em;
      text-transform: uppercase;
      margin-bottom: 14px;
    }

    .title {
      margin: 0;
      font-size: clamp(2rem, 8vw, 4.5rem);
      line-height: .95;
      letter-spacing: -.055em;
      font-weight: 900;
      max-width: 920px;
      text-align: center;
    }

    .title span {
      display: block;
      background: linear-gradient(90deg, #fff4cc 0%, #d4af37 55%, #fff0a8 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      color: transparent;
    }

    .description {
      margin-top: 16px;
      color: var(--text-soft);
      line-height: 1.72;
      font-size: 1rem;
      max-width: 740px;
      text-align: center;
    }

    .input-wrap {
      margin-top: 28px;
      max-width: 760px;
      width: 100%;
    }

    .main-input,
    .main-date {
      width: 100%;
      min-height: 68px;
      border-radius: 22px;
      border: 1px solid rgba(255,255,255,.08);
      background: rgba(255,255,255,.04);
      color: var(--text);
      padding: 0 20px;
      font-size: 1.12rem;
      outline: none;
      transition: .22s ease;
      appearance: none;
      -webkit-appearance: none;
    }

    .main-input:focus,
    .main-date:focus {
      border-color: rgba(212,175,55,.34);
      box-shadow: 0 0 0 4px rgba(212,175,55,.08);
    }

    .hint {
      margin-top: 12px;
      color: var(--text-muted);
      font-size: .95rem;
      line-height: 1.6;
      text-align: center;
    }

    .professionals-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 18px;
      margin-top: 28px;
      max-width: 760px;
      width: 100%;
    }

    .professional-card {
      border: 0;
      background: transparent;
      color: var(--text);
      cursor: pointer;
      padding: 0;
      text-align: center;
      transition: .22s ease;
      opacity: .94;
    }

    .professional-card:hover {
      transform: translateY(-3px);
    }

    .professional-avatar-wrap {
      display: flex;
      justify-content: center;
      margin-bottom: 12px;
    }

    .professional-avatar {
      width: 116px;
      height: 116px;
      border-radius: 50%;
      object-fit: cover;
      border: 2px solid rgba(255,255,255,.08);
      background: #111;
      box-shadow: 0 16px 30px rgba(0,0,0,.22);
      transition: .22s ease;
    }

    .professional-card.selected .professional-avatar {
      border-color: rgba(212,175,55,.85);
      box-shadow:
        0 18px 34px rgba(0,0,0,.24),
        0 0 0 4px rgba(212,175,55,.12);
    }

    .professional-name {
      font-size: 1.06rem;
      font-weight: 800;
      line-height: 1.35;
    }

    .category-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
      margin-top: 28px;
      max-width: 760px;
    }

    .category-card {
      width: 100%;
      text-align: center;
      border: 1px solid rgba(255,255,255,.08);
      background: rgba(255,255,255,.04);
      color: var(--text);
      border-radius: 22px;
      padding: 18px;
      cursor: pointer;
      transition: .22s ease;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 14px;
    }

    .category-card:hover {
      transform: translateY(-2px);
      border-color: rgba(212,175,55,.24);
    }

    .category-card.selected {
      border-color: rgba(212,175,55,.52);
      background: linear-gradient(180deg, rgba(212,175,55,.12), rgba(255,255,255,.04));
      box-shadow: 0 12px 26px rgba(0,0,0,.18);
    }

    .category-icon {
      width: 52px;
      height: 52px;
      border-radius: 16px;
      display: grid;
      place-items: center;
      background: rgba(255,255,255,.04);
      border: 1px solid rgba(255,255,255,.08);
      flex-shrink: 0;
    }

    .category-icon svg {
      width: 28px;
      height: 28px;
      stroke: var(--gold-soft);
    }

    .category-title {
      font-size: 1rem;
      font-weight: 800;
      line-height: 1.4;
    }

    .services-list {
      display: grid;
      gap: 12px;
      margin-top: 28px;
      max-width: 760px;
      width: 100%;
    }

    .service-card {
      width: 100%;
      text-align: center;
      border: 1px solid rgba(255,255,255,.08);
      background: rgba(255,255,255,.04);
      color: var(--text);
      border-radius: 20px;
      padding: 18px;
      cursor: pointer;
      transition: .22s ease;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-direction: column;
      gap: 18px;
    }

    .service-card:hover {
      transform: translateY(-2px);
      border-color: rgba(212,175,55,.24);
    }

    .service-card.selected {
      border-color: rgba(212,175,55,.52);
      background: linear-gradient(180deg, rgba(212,175,55,.12), rgba(255,255,255,.04));
      box-shadow: 0 12px 26px rgba(0,0,0,.18);
    }

    .service-name {
      font-size: 1rem;
      font-weight: 800;
      line-height: 1.45;
    }

    .service-price {
      color: var(--gold-soft);
      font-size: 1rem;
      font-weight: 900;
      white-space: nowrap;
      flex-shrink: 0;
    }

    .service-analysis-note {
      margin-top: 6px;
      color: var(--gold-soft);
      font-size: 12px;
      font-weight: 700;
      line-height: 1.4;
    }

    .services-empty {
      margin-top: 22px;
      max-width: 760px;
      width: 100%;
      padding: 16px;
      border-radius: 18px;
      background: rgba(255,95,109,.10);
      border: 1px solid rgba(255,95,109,.16);
      color: #ffd8dd;
      line-height: 1.6;
      text-align: center;
    }

    .service-help-card {
      margin-top: 18px;
      max-width: 760px;
      padding: 18px;
      border: 1px solid rgba(212,175,55,.20);
      background: linear-gradient(180deg, rgba(212,175,55,.10), rgba(255,255,255,.035));
      border-radius: 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 18px;
      text-decoration: none;
      color: var(--text);
      transition: .22s ease;
      text-align: center;
    }

    .service-help-card:hover {
      transform: translateY(-2px);
      border-color: rgba(212,175,55,.40);
      box-shadow: 0 14px 28px rgba(0,0,0,.16);
    }

    .service-help-text {
      display: grid;
      gap: 4px;
      line-height: 1.45;
    }

    .service-help-title {
      font-weight: 900;
      font-size: 1rem;
    }

    .service-help-description {
      color: var(--muted);
      font-size: .92rem;
      font-weight: 600;
    }

    .service-help-action {
      flex-shrink: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      min-height: 42px;
      padding: 0 16px;
      border-radius: 999px;
      background: #25d366;
      color: #062312;
      font-weight: 900;
      white-space: nowrap;
    }

    .service-help-action svg {
      width: 18px;
      height: 18px;
      stroke: currentColor;
      fill: none;
      stroke-width: 2;
      stroke-linecap: round;
      stroke-linejoin: round;
    }

    .date-box {
      margin-top: 28px;
      display: grid;
      gap: 12px;
      max-width: 760px;
      width: 100%;
    }

    .date-note {
      padding: 14px 16px;
      border-radius: 18px;
      border: 1px solid rgba(255,255,255,.06);
      background: rgba(255,255,255,.03);
      color: var(--text-soft);
      line-height: 1.65;
      font-size: .96rem;
    }

    .slot-grid {
      margin-top: 28px;
      display: grid;
      grid-template-columns: repeat(3, minmax(92px, 1fr));
      gap: 10px;
      max-width: 760px;
      width: 100%;
      max-height: 50vh;
      overflow: auto;
      padding-right: 2px;
    }

    .slot-date-switcher {
      margin-top: 22px;
      max-width: 760px;
      display: grid;
      grid-template-columns: 48px minmax(0, 1fr) 48px;
      align-items: center;
      gap: 10px;
      width: 100%;
    }

    .slot-date-btn {
      width: 48px;
      height: 48px;
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,.10);
      background: rgba(255,255,255,.05);
      color: var(--gold-soft);
      display: grid;
      place-items: center;
      cursor: pointer;
      transition: .18s ease;
    }

    .slot-date-btn:hover:not(:disabled) {
      transform: translateY(-1px);
      border-color: rgba(212,175,55,.36);
      background: rgba(212,175,55,.10);
    }

    .slot-date-btn:disabled {
      opacity: .38;
      cursor: not-allowed;
    }

    .slot-date-btn svg {
      width: 22px;
      height: 22px;
      stroke: currentColor;
      fill: none;
      stroke-width: 2.3;
      stroke-linecap: round;
      stroke-linejoin: round;
    }

    .slot-date-current {
      min-height: 58px;
      border-radius: 18px;
      border: 1px solid rgba(212,175,55,.22);
      background: rgba(212,175,55,.08);
      display: grid;
      place-items: center;
      padding: 10px 14px;
      text-align: center;
    }

    .slot-date-label {
      color: var(--muted);
      font-size: 12px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .06em;
    }

    .slot-date-value {
      margin-top: 3px;
      color: var(--text);
      font-size: 1.04rem;
      font-weight: 900;
      line-height: 1.25;
    }

    .slot-grid::-webkit-scrollbar {
      width: 8px;
    }

    .slot-grid::-webkit-scrollbar-thumb {
      background: rgba(255,255,255,.12);
      border-radius: 999px;
    }

    .slot {
      min-height: 58px;
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,.08);
      background: rgba(255,255,255,.04);
      color: var(--text);
      font-size: 1rem;
      font-weight: 800;
      cursor: pointer;
      transition: .18s ease;
      position: relative;
      overflow: hidden;
    }

    .slot.available {
      background: linear-gradient(180deg, rgba(32,201,151,.15), rgba(32,201,151,.08));
      border-color: rgba(32,201,151,.28);
      color: #cffff0;
    }

    .slot.available:hover {
      transform: translateY(-1px);
      box-shadow: 0 10px 20px rgba(0,0,0,.16);
      border-color: rgba(32,201,151,.40);
    }

    .slot.available.selected {
      background: linear-gradient(180deg, rgba(212,175,55,.26), rgba(212,175,55,.14));
      border-color: rgba(212,175,55,.55);
      color: #fff6cf;
      transform: translateY(-1px);
      box-shadow:
        0 14px 26px rgba(0,0,0,.22),
        0 0 0 2px rgba(212,175,55,.16);
    }

    .slot.available.selected::after {
      content: "✓";
      position: absolute;
      right: 12px;
      top: 10px;
      font-size: 13px;
      font-weight: 900;
      color: #fff6cf;
    }

    .slot.unavailable {
      background: linear-gradient(180deg, rgba(255,95,109,.14), rgba(255,95,109,.08));
      border-color: rgba(255,95,109,.22);
      color: rgba(255,230,233,.72);
      cursor: not-allowed;
      opacity: .72;
    }

    .slot-legend {
      margin-top: 12px;
      display: flex;
      gap: 14px;
      flex-wrap: wrap;
      justify-content: center;
      color: var(--text-soft);
      font-size: .92rem;
    }

    .slot-legend span {
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }

    .dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
    }

    .dot.green { background: var(--green); }
    .dot.red { background: var(--red); }

    .summary-box {
      margin-top: 28px;
      display: grid;
      gap: 12px;
      max-width: 760px;
      width: 100%;
    }

    .summary-item {
      border-radius: 18px;
      border: 1px solid rgba(255,255,255,.06);
      background: rgba(255,255,255,.03);
      padding: 15px 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
    }

    .summary-meta {
      display: flex;
      flex-direction: column;
      gap: 4px;
      min-width: 0;
      text-align: center;
    }

    .summary-label {
      color: var(--gold-soft);
      font-size: 11px;
      font-weight: 800;
      letter-spacing: .12em;
      text-transform: uppercase;
    }

    .summary-value {
      font-size: 1rem;
      font-weight: 700;
      color: var(--text);
      line-height: 1.5;
      word-break: break-word;
    }

    .summary-edit {
      border: 0;
      background: rgba(255,255,255,.06);
      color: var(--text);
      border-radius: 12px;
      min-height: 38px;
      padding: 0 14px;
      font-weight: 800;
      cursor: pointer;
      flex-shrink: 0;
      transition: .2s ease;
    }

    .summary-edit:hover {
      transform: translateY(-1px);
    }

    .success-box {
      margin-top: 28px;
      display: grid;
      gap: 14px;
      max-width: 760px;
      width: 100%;
    }

    .success-panel {
      padding: 18px;
      border-radius: 22px;
      border: 1px solid rgba(32,201,151,.18);
      background: linear-gradient(180deg, rgba(32,201,151,.10), rgba(255,255,255,.03));
      color: #defdf2;
      line-height: 1.75;
    }

    .footer-mini-brand {
      margin-top: 30px;
      display: flex;
      align-items: center;
      justify-content: center;
      opacity: .88;
    }

    .footer-mini-brand img {
      width: 82px;
      height: auto;
      object-fit: contain;
      opacity: .92;
    }

    .actions {
      display: flex;
      gap: 10px;
      align-items: center;
      justify-content: center;
      margin-top: 30px;
      flex-wrap: wrap;
      max-width: 760px;
      width: 100%;
    }

    .actions.left-only {
      justify-content: center;
    }

    .btn {
      min-height: 58px;
      border-radius: 18px;
      border: 1px solid transparent;
      padding: 0 20px;
      font-size: 1rem;
      font-weight: 800;
      cursor: pointer;
      transition: .22s ease;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      text-align: center;
      width: 100%;
    }

    .btn-primary {
      background: linear-gradient(90deg, #f7e7af 0%, #d4af37 55%, #aa7c0a 100%);
      color: #181510;
      box-shadow: 0 16px 28px rgba(0,0,0,.22);
    }

    .btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 20px 30px rgba(0,0,0,.28);
    }

    .btn-secondary {
      background: rgba(255,255,255,.04);
      color: var(--text);
      border-color: rgba(255,255,255,.08);
    }

    .btn-secondary:hover {
      border-color: rgba(212,175,55,.24);
      transform: translateY(-1px);
    }

    .loading-box,
    .error-box {
      margin-top: 18px;
      border-radius: 18px;
      padding: 16px;
      font-size: .96rem;
      line-height: 1.6;
      max-width: 760px;
    }

    .loading-box {
      background: rgba(255,255,255,.03);
      border: 1px solid rgba(255,255,255,.06);
      color: var(--text-soft);
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .loading-spinner {
      width: 18px;
      height: 18px;
      border-radius: 50%;
      border: 2px solid rgba(255,255,255,.18);
      border-top-color: var(--gold);
      animation: spin .8s linear infinite;
      flex-shrink: 0;
    }

    @keyframes spin {
      to { transform: rotate(360deg); }
    }

    .error-box {
      background: rgba(255,95,109,.10);
      border: 1px solid rgba(255,95,109,.16);
      color: #ffd8dd;
    }

    .hidden {
      display: none !important;
    }

    @media (min-width: 760px) {
      .page {
        padding: 34px 22px 50px;
      }

      .professionals-grid {
        grid-template-columns: repeat(2, minmax(0, 230px));
        gap: 38px;
      }

      .slot-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }

      .btn {
        width: auto;
        min-width: 190px;
      }
    }

    @media (max-width: 480px) {
      .professional-avatar {
        width: 98px;
        height: 98px;
      }

      .category-grid,
      .professionals-grid {
        grid-template-columns: 1fr;
      }

      .slot-grid {
        grid-template-columns: repeat(3, minmax(82px, 1fr));
        gap: 9px;
      }

      .service-card {
        padding: 16px;
      }

      .service-help-card {
        align-items: flex-start;
        flex-direction: column;
        padding: 16px;
      }

      .service-help-action {
        width: 100%;
      }

      .title {
        font-size: clamp(1.9rem, 10vw, 3rem);
      }
    }

    @media (max-width: 350px) {
      .slot-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }
  </style>
</head>
<body>
  <div class="page">
    <div class="flow-shell">
      <div class="brand-top">
        <?php if ($salonLogo): ?>
          <img src="<?= htmlspecialchars($salonLogo); ?>" alt="Logo do salão" class="brand-logo">
        <?php else: ?>
          <div class="brand-logo fallback">André Puchetti</div>
        <?php endif; ?>
      </div>

      <div class="progress-wrap">
        <div class="progress-top">
          <div class="progress-step" id="progressStep">Passo 1 de 9</div>
        </div>
        <div class="progress-bar">
          <div class="progress-fill" id="progressFill"></div>
        </div>
      </div>

      <div class="step-stage">
        <section class="step active" data-step="0">
          <div class="eyebrow">Olá, tudo bem?</div>
          <h1 class="title"><span>Agende seu horário</span> em poucos segundos.</h1>
          <p class="description">
            Uma experiência simples, bonita e rápida para você escolher categoria, profissional, serviço, data e horário com total clareza.
          </p>

          <div class="actions left-only">
            <button type="button" class="btn btn-primary" id="startFlowBtn">Começar agendamento</button>
          </div>
        </section>

        <section class="step" data-step="1">
          <div class="eyebrow">Passo 1</div>
          <h2 class="title"><span>Qual é o seu nome?</span></h2>
          <p class="description">Queremos deixar seu atendimento mais pessoal desde o início.</p>

          <div class="input-wrap">
            <input type="text" id="nomeInput" class="main-input" placeholder="Digite seu nome" autocomplete="name" maxlength="100">
            <p class="hint">Toque em continuar ou pressione Enter.</p>
          </div>

          <div class="actions">
            <button type="button" class="btn btn-secondary back-btn-step">Voltar</button>
            <button type="button" class="btn btn-primary" id="goPhoneBtn">Continuar</button>
          </div>
        </section>

        <section class="step" data-step="2">
          <div class="eyebrow">Passo 2</div>
          <h2 class="title"><span>Qual é o seu WhatsApp?</span></h2>
          <p class="description">Assim conseguimos identificar seu agendamento com mais segurança.</p>

          <div class="input-wrap">
            <input type="text" id="telefoneInput" class="main-input" placeholder="(11) 99999-9999" autocomplete="tel" maxlength="16" inputmode="numeric">
            <p class="hint">Usaremos esse número para localizar ou cadastrar seu atendimento.</p>
          </div>

          <div class="actions">
            <button type="button" class="btn btn-secondary back-btn-step">Voltar</button>
            <button type="button" class="btn btn-primary" id="goCategoryBtn">Continuar</button>
          </div>
        </section>

        <section class="step" data-step="3">
          <div class="eyebrow">Passo 3</div>
          <h2 class="title"><span>Qual tipo de atendimento você procura?</span></h2>
          <p class="description">Escolha a categoria para seguir com o profissional certo.</p>

          <div class="category-grid">
            <button type="button" class="category-card" data-category="masculino">
              <div class="category-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M14 5h5v5"></path>
                  <path d="M10 14L19 5"></path>
                  <circle cx="8" cy="16" r="5"></circle>
                </svg>
              </div>
              <div class="category-title">Serviços masculinos</div>
            </button>

            <button type="button" class="category-card" data-category="feminino">
              <div class="category-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                  <circle cx="12" cy="8" r="4"></circle>
                  <path d="M12 12v8"></path>
                  <path d="M9 17h6"></path>
                </svg>
              </div>
              <div class="category-title">Serviços femininos</div>
            </button>
          </div>

          <div class="actions">
            <button type="button" class="btn btn-secondary back-btn-step">Voltar</button>
            <button type="button" class="btn btn-primary" id="goProfessionalBtn">Continuar</button>
          </div>
        </section>

        <section class="step" data-step="4">
          <div class="eyebrow">Passo 4</div>
          <h2 class="title"><span>Com quem você quer agendar?</span></h2>
          <p class="description">Mostramos apenas os profissionais disponíveis para essa categoria.</p>

          <div class="professionals-grid" id="professionalGrid"></div>
          <div class="services-empty hidden" id="professionalsEmpty">
            Não encontramos profissionais para essa categoria.
          </div>

          <div class="actions">
            <button type="button" class="btn btn-secondary back-btn-step">Voltar</button>
            <button type="button" class="btn btn-primary" id="goServiceBtn">Continuar</button>
          </div>
        </section>

        <section class="step" data-step="5">
          <div class="eyebrow">Passo 5</div>
          <h2 class="title"><span>Qual serviço você deseja?</span></h2>
          <p class="description">Escolha a opção ideal para o seu atendimento.</p>

          <div class="services-list" id="serviceGrid"></div>
          <div class="services-empty hidden" id="servicesEmpty">
            Não encontramos serviços nessa categoria.
          </div>
          <a href="https://wa.me/<?= htmlspecialchars(ASSISTANT_WHATSAPP); ?>" target="_blank" rel="noopener noreferrer" class="service-help-card" id="serviceHelpWhatsapp">
            <span class="service-help-text">
              <span class="service-help-title">Não encontrou o que queria fazer?</span>
              <span class="service-help-description">Chame no WhatsApp para receber orientação antes de agendar.</span>
            </span>
            <span class="service-help-action">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5A8.48 8.48 0 0 1 21 11v.5Z"></path>
              </svg>
              WhatsApp
            </span>
          </a>

          <div class="actions" id="serviceActions">
            <button type="button" class="btn btn-secondary back-btn-step">Voltar</button>
            <button type="button" class="btn btn-primary" id="goDateBtn">Continuar</button>
          </div>
        </section>

        <section class="step" data-step="6">
          <div class="eyebrow">Passo 6</div>
          <h2 class="title"><span>Qual dia você prefere?</span></h2>
          <p class="description">Escolha a melhor data para o seu atendimento.</p>

          <div class="date-box">
            <input type="date" id="dataInput" class="main-date" min="<?= htmlspecialchars($today); ?>" max="<?= htmlspecialchars($maxDate); ?>">
            <div class="date-note">
              Depois da data, vamos mostrar os horários disponíveis em tempo real. Aos domingos não há atendimento.
            </div>
          </div>

          <div class="actions">
            <button type="button" class="btn btn-secondary back-btn-step">Voltar</button>
            <button type="button" class="btn btn-primary" id="goTimeBtn">Ver horários</button>
          </div>
        </section>

        <section class="step" data-step="7">
          <div class="eyebrow">Passo 7</div>
          <h2 class="title"><span>Qual horário funciona melhor?</span></h2>
          <p class="description">Verde está disponível. Vermelho está indisponível ou bloqueado.</p>

          <div class="slot-date-switcher" aria-label="Trocar data dos horários">
            <button type="button" class="slot-date-btn" id="prevSlotDateBtn" aria-label="Ver dia anterior">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="m15 18-6-6 6-6"></path>
              </svg>
            </button>
            <div class="slot-date-current">
              <div>
                <div class="slot-date-label">Data escolhida</div>
                <div class="slot-date-value" id="slotDateLabel">--/--/----</div>
              </div>
            </div>
            <button type="button" class="slot-date-btn" id="nextSlotDateBtn" aria-label="Ver próximo dia">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="m9 18 6-6-6-6"></path>
              </svg>
            </button>
          </div>

          <div class="loading-box hidden" id="slotsLoading">
            <span class="loading-spinner"></span>
            <span>Carregando horários disponíveis...</span>
          </div>

          <div class="error-box hidden" id="slotsError"></div>

          <div class="slot-grid" id="slotGrid"></div>

          <div class="slot-legend">
            <span><i class="dot green"></i> Disponível</span>
            <span><i class="dot red"></i> Indisponível</span>
          </div>

          <div class="actions">
            <button type="button" class="btn btn-secondary back-btn-step">Voltar</button>
            <button type="button" class="btn btn-primary" id="goReviewBtn">Continuar</button>
          </div>
        </section>

        <section class="step" data-step="8">
          <div class="eyebrow">Passo 8</div>
          <h2 class="title"><span>Confirme seu agendamento</span></h2>
          <p class="description">Revise os dados abaixo antes de finalizar. Se quiser, você pode editar qualquer etapa.</p>

          <div class="summary-box" id="reviewSummary"></div>

          <div class="error-box hidden" id="submitError"></div>

          <div class="actions">
            <button type="button" class="btn btn-secondary back-btn-step">Voltar</button>
            <button type="button" class="btn btn-primary" id="confirmBookingBtn">Confirmar agendamento</button>
          </div>
        </section>

        <section class="step" data-step="9">
          <div class="eyebrow">Tudo certo por aqui</div>
          <h2 class="title"><span>Seu horário está agendado.</span></h2>
          <p class="description">Seu pedido foi confirmado com sucesso. Se precisar de qualquer ajuda adicional, fale com nossa assistente.</p>

          <div class="success-box">
            <div class="success-panel" id="successSummary"></div>
          </div>

          <div class="actions left-only">
            <a href="https://wa.me/<?= htmlspecialchars(ASSISTANT_WHATSAPP); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary" id="assistantWhatsappBtn">
              Falar com a assistente
            </a>
          </div>

          <div class="footer-mini-brand">
            <?php if ($salonLogo): ?>
              <img src="<?= htmlspecialchars($salonLogo); ?>" alt="Logo do salão">
            <?php endif; ?>
          </div>
        </section>
      </div>
    </div>
  </div>

  <script>
    const allProfessionals = <?= json_encode(array_map(function($professional) {
      return [
        'id' => (int)$professional['id'],
        'nome' => $professional['nome_publico'],
        'foto' => $professional['foto_publica'],
        'categorias' => $professional['categorias_atendidas'],
      ];
    }, $professionals), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    const allServices = <?= json_encode(array_map(function($service) {
      return [
        'id' => (int)$service['id'],
        'nome' => $service['nome'],
        'duracao' => (int)$service['duracao'],
        'preco' => (float)$service['preco'],
        'preco_label' => $service['preco_label'],
        'categoria' => $service['categoria'],
        'categorias' => $service['categorias'],
        'profissionais_ids' => $service['profissionais_ids_array'],
        'prioridade' => (int)$service['prioridade'],
        'precisa_analise' => (bool)$service['precisa_analise'],
      ];
    }, $services), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    const state = {
      step: 0,
      nome: '',
      telefone: '',
      categoria: '',
      categoriaLabel: '',
      profissionalId: '',
      profissionalNome: '',
      servicoId: '',
      servicoNome: '',
      servicoPrecoLabel: '',
      servicoDuracao: 0,
      servicoPrecisaAnalise: false,
      data: '',
      hora: '',
      slots: [],
      booking: null
    };

    const totalProgressSteps = 9;

    const progressStep = document.getElementById('progressStep');
    const progressFill = document.getElementById('progressFill');
    const steps = document.querySelectorAll('.step');

    const nomeInput = document.getElementById('nomeInput');
    const telefoneInput = document.getElementById('telefoneInput');
    const dataInput = document.getElementById('dataInput');

    const categoryCards = document.querySelectorAll('.category-card');

    const professionalGrid = document.getElementById('professionalGrid');
    const professionalsEmpty = document.getElementById('professionalsEmpty');
    const serviceGrid = document.getElementById('serviceGrid');
    const servicesEmpty = document.getElementById('servicesEmpty');
    const serviceHelpWhatsapp = document.getElementById('serviceHelpWhatsapp');
    const slotGrid = document.getElementById('slotGrid');
    const slotDateLabel = document.getElementById('slotDateLabel');
    const prevSlotDateBtn = document.getElementById('prevSlotDateBtn');
    const nextSlotDateBtn = document.getElementById('nextSlotDateBtn');
    const slotsLoading = document.getElementById('slotsLoading');
    const slotsError = document.getElementById('slotsError');
    const submitError = document.getElementById('submitError');
    const reviewSummary = document.getElementById('reviewSummary');
    const successSummary = document.getElementById('successSummary');
    const goDateBtn = document.getElementById('goDateBtn');
    const assistantWhatsappBtn = document.getElementById('assistantWhatsappBtn');
    const analyticsSessionKey = 'agenda_analytics_session';
    let analyticsSession = localStorage.getItem(analyticsSessionKey);

    if (!analyticsSession) {
      analyticsSession = `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
      localStorage.setItem(analyticsSessionKey, analyticsSession);
    }

    function trackAnalytics(evento, extras = {}) {
      try {
        const payload = new URLSearchParams({
          ajax: 'analytics_event',
          evento,
          sessao: analyticsSession,
          etapa: String(state.step || 0),
          categoria: state.categoria || '',
          profissional_id: state.profissionalId || '',
          servico_id: state.servicoId || '',
          data: state.data || '',
          ...extras
        });

        fetch('index.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: payload.toString(),
          keepalive: true
        }).catch(() => {});
      } catch (error) {}
    }

    function showStep(stepIndex) {
      state.step = stepIndex;

      steps.forEach((step, index) => {
        step.classList.toggle('active', index === stepIndex);
      });

      const progressIndex = Math.max(1, Math.min(totalProgressSteps, stepIndex + 1));
      progressStep.textContent = `Passo ${progressIndex} de ${totalProgressSteps}`;
      progressFill.style.width = `${(progressIndex / totalProgressSteps) * 100}%`;

      window.scrollTo({ top: 0, behavior: 'smooth' });

      if (stepIndex === 1) setTimeout(() => nomeInput.focus(), 90);
      if (stepIndex === 2) setTimeout(() => telefoneInput.focus(), 90);
      if (stepIndex === 4) renderFilteredProfessionals();
      if (stepIndex === 5) renderFilteredServices();
      if (stepIndex === 6) setTimeout(() => dataInput.focus(), 90);
      if (stepIndex === 7) renderSlotDateSwitcher();
      if (stepIndex === 8) renderReview();
      if (stepIndex === 9) renderSuccessWhatsapp();
    }

    function goBack() {
      if (state.step > 0) {
        showStep(state.step - 1);
      }
    }

    document.querySelectorAll('.back-btn-step').forEach(btn => {
      btn.addEventListener('click', goBack);
    });

    trackAnalytics('page_view', { etapa: '0' });

    document.getElementById('startFlowBtn').addEventListener('click', () => {
      trackAnalytics('inicio_fluxo', { etapa: '1' });
      showStep(1);
    });

    function validateName() {
      const value = nomeInput.value.trim();
      if (value.length < 2) {
        alert('Digite seu nome para continuar.');
        nomeInput.focus();
        return false;
      }
      state.nome = value;
      return true;
    }

    function validatePhone() {
      const digits = telefoneInput.value.replace(/\D/g, '');
      if (digits.length < 10) {
        alert('Digite um WhatsApp válido para continuar.');
        telefoneInput.focus();
        return false;
      }
      state.telefone = digits;
      return true;
    }

    function validateCategory() {
      if (!state.categoria) {
        alert('Escolha uma categoria para continuar.');
        return false;
      }
      return true;
    }

    function validateProfessional() {
      if (!state.profissionalId) {
        alert('Escolha um profissional para continuar.');
        return false;
      }
      return true;
    }

    function validateService() {
      if (!state.servicoId) {
        alert('Escolha um serviço para continuar.');
        return false;
      }
      return true;
    }

    function validateDate() {
      const value = dataInput.value;
      if (!value) {
        alert('Escolha uma data para continuar.');
        dataInput.focus();
        return false;
      }
      if (isSundayDate(value)) {
        alert('Não abrimos aos domingos. Escolha outra data.');
        dataInput.focus();
        return false;
      }
      state.data = value;
      return true;
    }

    function validateTime() {
      if (!state.hora) {
        alert('Escolha um horário para continuar.');
        return false;
      }
      return true;
    }

    document.getElementById('goPhoneBtn').addEventListener('click', () => {
      if (!validateName()) return;
      trackAnalytics('nome_informado', { etapa: '1' });
      showStep(2);
    });

    document.getElementById('goCategoryBtn').addEventListener('click', () => {
      if (!validatePhone()) return;
      trackAnalytics('telefone_informado', { etapa: '2' });
      showStep(3);
    });

    document.getElementById('goProfessionalBtn').addEventListener('click', () => {
      if (!validateCategory()) return;
      trackAnalytics('categoria_escolhida', { etapa: '3' });
      showStep(4);
    });

    document.getElementById('goServiceBtn').addEventListener('click', () => {
      if (!validateProfessional()) return;
      trackAnalytics('profissional_escolhido', { etapa: '4' });
      showStep(5);
    });

    document.getElementById('goDateBtn').addEventListener('click', async () => {
      if (!validateService()) return;
      trackAnalytics('servico_escolhido', { etapa: '5' });

      if (state.servicoPrecisaAnalise) {
        try {
          const payload = new URLSearchParams({
            ajax: 'analysis_request',
            nome: state.nome,
            telefone: state.telefone,
            profissional_id: state.profissionalId,
            servico_id: state.servicoId,
            categoria: state.categoriaLabel
          });

          await fetch('index.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
              'X-Requested-With': 'XMLHttpRequest'
            },
            body: payload.toString()
          });
        } catch (e) {}

        trackAnalytics('pedido_analise_enviado', { etapa: '5' });

        const params = new URLSearchParams({
          servico: state.servicoNome,
          categoria: state.categoriaLabel,
          profissional: state.profissionalNome,
          nome: state.nome,
          telefone: state.telefone
        });

        window.location.href = `analise-servico.php?${params.toString()}`;
        return;
      }

      showStep(6);
    });

    document.getElementById('goTimeBtn').addEventListener('click', async () => {
      if (!validateDate()) return;
      trackAnalytics('data_escolhida', { etapa: '6' });
      showStep(7);
      await loadSlots();
    });

    document.getElementById('goReviewBtn').addEventListener('click', () => {
      if (!validateTime()) return;
      trackAnalytics('horario_escolhido', { etapa: '7' });
      showStep(8);
      trackAnalytics('revisao_aberta', { etapa: '8' });
    });

    nomeInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        document.getElementById('goPhoneBtn').click();
      }
    });

    telefoneInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        document.getElementById('goCategoryBtn').click();
      }
    });

    dataInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        document.getElementById('goTimeBtn').click();
      }
    });

    prevSlotDateBtn.addEventListener('click', () => changeSlotDate(-1));
    nextSlotDateBtn.addEventListener('click', () => changeSlotDate(1));

    telefoneInput.addEventListener('input', () => {
      let v = telefoneInput.value.replace(/\D/g, '').slice(0, 11);

      if (v.length > 10) {
        v = v.replace(/^(\d{2})(\d{5})(\d{0,4}).*/, '($1) $2-$3');
      } else if (v.length > 6) {
        v = v.replace(/^(\d{2})(\d{4})(\d{0,4}).*/, '($1) $2-$3');
      } else if (v.length > 2) {
        v = v.replace(/^(\d{2})(\d{0,5})/, '($1) $2');
      } else if (v.length > 0) {
        v = v.replace(/^(\d*)/, '($1');
      }

      telefoneInput.value = v;
    });

    categoryCards.forEach(card => {
      card.addEventListener('click', () => {
        categoryCards.forEach(item => item.classList.remove('selected'));
        card.classList.add('selected');

        state.categoria = card.dataset.category;
        state.categoriaLabel = state.categoria === 'feminino' ? 'Serviços femininos' : 'Serviços masculinos';

        state.profissionalId = '';
        state.profissionalNome = '';
        state.servicoId = '';
        state.servicoNome = '';
        state.servicoPrecoLabel = '';
        state.servicoDuracao = 0;
        state.servicoPrecisaAnalise = false;
        state.hora = '';

        setTimeout(() => {
          showStep(4);
        }, 180);
      });
    });

    function renderFilteredProfessionals() {
      professionalGrid.innerHTML = '';
      professionalsEmpty.classList.add('hidden');

      const filtered = allProfessionals.filter(prof => Array.isArray(prof.categorias) && prof.categorias.includes(state.categoria));

      if (!filtered.length) {
        professionalsEmpty.classList.remove('hidden');
        return;
      }

      filtered.forEach(prof => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'professional-card';
        btn.innerHTML = `
          <div class="professional-avatar-wrap">
            <img src="${prof.foto}" alt="${prof.nome}" class="professional-avatar">
          </div>
          <div class="professional-name">${prof.nome}</div>
        `;

        btn.addEventListener('click', () => {
          professionalGrid.querySelectorAll('.professional-card').forEach(item => item.classList.remove('selected'));
          btn.classList.add('selected');

          state.profissionalId = String(prof.id);
          state.profissionalNome = prof.nome;
          state.servicoId = '';
          state.servicoNome = '';
          state.servicoPrecoLabel = '';
          state.servicoDuracao = 0;
          state.servicoPrecisaAnalise = false;
          state.hora = '';

          setTimeout(() => {
            showStep(5);
          }, 180);
        });

        professionalGrid.appendChild(btn);
      });
    }

    function renderFilteredServices() {
      serviceGrid.innerHTML = '';
      servicesEmpty.classList.add('hidden');
      renderServiceHelpWhatsapp();

      const filtered = allServices.filter(service => {
        const categorias = Array.isArray(service.categorias) ? service.categorias : [service.categoria];
        const profissionais = Array.isArray(service.profissionais_ids) ? service.profissionais_ids.map(String) : [];

        return categorias.includes(state.categoria)
          && (!profissionais.length || profissionais.includes(String(state.profissionalId)));
      });

      if (!filtered.length) {
        servicesEmpty.classList.remove('hidden');
        return;
      }

      filtered.forEach(service => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'service-card';
        btn.innerHTML = `
          <div>
            <div class="service-name">${service.nome}</div>
            ${service.precisa_analise ? '<div class="service-analysis-note">Requer análise profissional para avaliar comprimento, volume e técnica.</div>' : ''}
          </div>
          <div class="service-price">${service.preco_label}</div>
        `;

        btn.addEventListener('click', () => {
          serviceGrid.querySelectorAll('.service-card').forEach(item => item.classList.remove('selected'));
          btn.classList.add('selected');

          state.servicoId = String(service.id);
          state.servicoNome = service.nome;
          state.servicoPrecoLabel = service.preco_label;
          state.servicoDuracao = parseInt(service.duracao || 0, 10);
          state.servicoPrecisaAnalise = !!service.precisa_analise;
          state.hora = '';

          setTimeout(() => {
            goDateBtn.scrollIntoView({
              behavior: 'smooth',
              block: 'center'
            });
          }, 100);
        });

        serviceGrid.appendChild(btn);
      });
    }

    function buildServiceHelpMessage() {
      const lines = [
        'Olá! Não encontrei exatamente o serviço que queria fazer no agendamento online.',
        '',
        `Nome: ${state.nome}`,
        `WhatsApp: ${telefoneInput.value.trim()}`,
        `Categoria escolhida: ${state.categoriaLabel || 'Não informada'}`,
        `Profissional de interesse: ${state.profissionalNome || 'Não informado'}`,
        '',
        'Pode me orientar, por favor?'
      ];

      return encodeURIComponent(lines.join('\n'));
    }

    function renderServiceHelpWhatsapp() {
      serviceHelpWhatsapp.href = `https://wa.me/<?= htmlspecialchars(ASSISTANT_WHATSAPP); ?>?text=${buildServiceHelpMessage()}`;
    }

    function parseLocalDate(dateString) {
      const [year, month, day] = dateString.split('-').map(Number);
      return new Date(year, month - 1, day);
    }

    function formatLocalDate(date) {
      const year = date.getFullYear();
      const month = String(date.getMonth() + 1).padStart(2, '0');
      const day = String(date.getDate()).padStart(2, '0');
      return `${year}-${month}-${day}`;
    }

    function addDaysToDateString(dateString, days) {
      const date = parseLocalDate(dateString);
      date.setDate(date.getDate() + days);
      return formatLocalDate(date);
    }

    function isSundayDate(dateString) {
      return parseLocalDate(dateString).getDay() === 0;
    }

    function renderSlotDateSwitcher() {
      if (!state.data) return;

      slotDateLabel.textContent = humanDate(state.data);
      prevSlotDateBtn.disabled = Boolean(dataInput.min && state.data <= dataInput.min);
      nextSlotDateBtn.disabled = Boolean(dataInput.max && state.data >= dataInput.max);
    }

    async function changeSlotDate(direction) {
      if (!state.data) return;

      let nextDate = addDaysToDateString(state.data, direction);

      if ((dataInput.min && nextDate < dataInput.min) || (dataInput.max && nextDate > dataInput.max)) {
        return;
      }

      if (isSundayDate(nextDate)) {
        nextDate = addDaysToDateString(nextDate, direction);

        if ((dataInput.min && nextDate < dataInput.min) || (dataInput.max && nextDate > dataInput.max)) {
          return;
        }
      }

      state.data = nextDate;
      dataInput.value = nextDate;
      state.hora = '';
      renderSlotDateSwitcher();
      trackAnalytics('data_trocada_no_horario', { etapa: '7', direcao: direction > 0 ? 'proximo' : 'anterior' });
      await loadSlots();
    }

    async function loadSlots() {

      state.hora = '';
      slotGrid.innerHTML = '';
      slotsError.classList.add('hidden');
      renderSlotDateSwitcher();
      slotsLoading.classList.remove('hidden');

      try {
        const params = new URLSearchParams({
          ajax: 'slots',
          profissional_id: state.profissionalId,
          servico_id: state.servicoId,
          data: state.data
        });

        const response = await fetch(`index.php?${params.toString()}`, {
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        });

        const json = await response.json();

        if (!response.ok || !json.ok) {
          throw new Error(json.message || 'Não foi possível carregar os horários.');
        }

        state.slots = json.slots || [];
        renderSlots();
      } catch (error) {
        slotsError.textContent = error.message || 'Erro ao carregar horários.';
        slotsError.classList.remove('hidden');
      } finally {
        slotsLoading.classList.add('hidden');
      }
    }

    function renderSlots() {
      slotGrid.innerHTML = '';

      if (!state.slots.length) {
        slotsError.textContent = 'Não encontramos horários para essa combinação.';
        slotsError.classList.remove('hidden');
        return;
      }

      state.slots.forEach(slot => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `slot ${slot.available ? 'available' : 'unavailable'}`;
        btn.textContent = slot.label;
        btn.disabled = !slot.available;

        if (slot.available) {
          btn.addEventListener('click', () => {
            state.hora = slot.time;
            document.querySelectorAll('.slot.available').forEach(el => el.classList.remove('selected'));
            btn.classList.add('selected');

            setTimeout(() => {
              showStep(8);
            }, 220);
          });
        }

        slotGrid.appendChild(btn);
      });
    }

    function humanDate(dateString) {
      const [year, month, day] = dateString.split('-');
      return `${day}/${month}/${year}`;
    }

    function displayHourFromState() {
      const parts = state.hora.split(':');
      const total = (parseInt(parts[0], 10) * 60) + parseInt(parts[1], 10);
      return total % 60 === 0 ? `${parts[0].replace(/^0/, '')}h` : `${parts[0].replace(/^0/, '')}h${parts[1]}`;
    }

    function renderReview() {
      const items = [
        { label: 'Nome', value: state.nome, step: 1 },
        { label: 'WhatsApp', value: telefoneInput.value.trim(), step: 2 },
        { label: 'Categoria', value: state.categoriaLabel, step: 3 },
        { label: 'Profissional', value: state.profissionalNome, step: 4 },
        { label: 'Serviço', value: `${state.servicoNome} • ${state.servicoPrecoLabel}`, step: 5 },
        { label: 'Data', value: humanDate(state.data), step: 6 },
        { label: 'Horário', value: displayHourFromState(), step: 7 },
      ];

      reviewSummary.innerHTML = '';
      submitError.classList.add('hidden');
      submitError.textContent = '';

      items.forEach(item => {
        const row = document.createElement('div');
        row.className = 'summary-item';
        row.innerHTML = `
          <div class="summary-meta">
            <div class="summary-label">${item.label}</div>
            <div class="summary-value">${item.value}</div>
          </div>
          <button type="button" class="summary-edit" data-step="${item.step}">Editar</button>
        `;
        reviewSummary.appendChild(row);
      });

      reviewSummary.querySelectorAll('.summary-edit').forEach(btn => {
        btn.addEventListener('click', () => {
          const step = parseInt(btn.dataset.step, 10);

          if (step === 7) {
            showStep(7);
            if (state.slots.length === 0) {
              loadSlots();
            } else {
              renderSlots();
            }
          } else {
            showStep(step);
          }
        });
      });
    }

    function buildWhatsappMessage() {
      const lines = [
        'Olá! Acabei de agendar um horário.',
        '',
        `Nome: ${state.nome}`,
        `WhatsApp: ${telefoneInput.value.trim()}`,
        `Categoria: ${state.categoriaLabel}`,
        `Profissional: ${state.profissionalNome}`,
        `Serviço: ${state.servicoNome}`,
        `Data: ${humanDate(state.data)}`,
        `Hora: ${displayHourFromState()}`,
      ];

      return encodeURIComponent(lines.join('\n'));
    }

    function renderSuccessWhatsapp() {
      const message = buildWhatsappMessage();
      assistantWhatsappBtn.href = `https://wa.me/<?= htmlspecialchars(ASSISTANT_WHATSAPP); ?>?text=${message}`;
    }

    document.getElementById('confirmBookingBtn').addEventListener('click', async () => {
      submitError.classList.add('hidden');
      submitError.textContent = '';

      const payload = new URLSearchParams({
        ajax: 'book',
        nome: state.nome,
        telefone: state.telefone,
        profissional_id: state.profissionalId,
        servico_id: state.servicoId,
        data: state.data,
        hora: state.hora
      });

      const button = document.getElementById('confirmBookingBtn');
      const originalText = button.textContent;
      button.disabled = true;
      button.textContent = 'Confirmando...';

      try {
        const response = await fetch('index.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: payload.toString()
        });

        const json = await response.json();

        if (!response.ok || !json.ok) {
          throw new Error(json.message || 'Não foi possível confirmar o agendamento.');
        }

        state.booking = json.booking || null;
        trackAnalytics('agendamento_finalizado', { etapa: '9' });

        successSummary.innerHTML = `
          <strong style="display:block;font-size:1.05rem;margin-bottom:8px;">Agendamento confirmado com sucesso.</strong>
          <div><strong>Nome:</strong> ${json.booking.nome}</div>
          <div><strong>WhatsApp:</strong> ${telefoneInput.value.trim()}</div>
          <div><strong>Categoria:</strong> ${state.categoriaLabel}</div>
          <div><strong>Profissional:</strong> ${json.booking.profissional}</div>
          <div><strong>Serviço:</strong> ${json.booking.servico}</div>
          <div><strong>Valor:</strong> ${json.booking.preco}</div>
          <div><strong>Data:</strong> ${json.booking.data}</div>
          <div><strong>Horário:</strong> ${json.booking.hora}</div>
          <div style="margin-top:10px;opacity:.88;">Para mais dúvidas, fale com nossa assistente humana.</div>
        `;

        showStep(9);
      } catch (error) {
        submitError.textContent = error.message || 'Erro ao confirmar o agendamento.';
        submitError.classList.remove('hidden');
      } finally {
        button.disabled = false;
        button.textContent = originalText;
      }
    });

    showStep(0);
  </script>
</body>
</html>
