<?php
/**
 * inscription.php — Inscription newsletter via Brevo
 *
 * Configuration requise (variables d'environnement):
 *   - BREVO_API_KEY : cle API Brevo
 *   - BREVO_LIST_ID : identifiant numerique de la liste newsletter
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

session_start();

define('RATE_LIMIT_SEC', 60);
define('MIN_DELAY_MS', 2000);

define('BREVO_API_URL', 'https://api.brevo.com/v3/contacts');

function env_value(string $key): string
{
    $value = getenv($key);
    if (is_string($value) && $value !== '') {
        return trim($value);
    }

    $serverValue = $_SERVER[$key] ?? $_SERVER['REDIRECT_' . $key] ?? '';
    if (is_string($serverValue) && $serverValue !== '') {
        return trim($serverValue);
    }

    $envValue = $_ENV[$key] ?? '';
    if (is_string($envValue) && $envValue !== '') {
        return trim($envValue);
    }

    return '';
}

function json_response(bool $success, string $message, int $status = 200): never
{
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Methode non autorisee.', 405);
}

if (!empty($_POST['website'])) {
    json_response(true, 'Inscription prise en compte.');
}

$loadTime = (int)($_POST['inscription_time'] ?? 0);
if ($loadTime === 0 || (time() * 1000 - $loadTime) < MIN_DELAY_MS) {
    json_response(false, 'Formulaire soumis trop rapidement. Veuillez reessayer.', 400);
}

if (
    isset($_SESSION['last_newsletter_send'])
    && (time() - (int)$_SESSION['last_newsletter_send']) < RATE_LIMIT_SEC
) {
    json_response(false, 'Merci de patienter une minute avant une nouvelle tentative.', 429);
}

$email = trim((string)($_POST['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
    json_response(false, 'Adresse e-mail invalide.', 400);
}

$consent = (string)($_POST['consent'] ?? '');
if ($consent !== '1') {
    json_response(false, 'Consentement RGPD requis.', 400);
}

$apiKey = env_value('BREVO_API_KEY');
$listIdRaw = env_value('BREVO_LIST_ID');
$listId = ctype_digit($listIdRaw) ? (int)$listIdRaw : 0;

if ($apiKey === '' || $listId <= 0) {
    error_log('newsletter: missing BREVO_API_KEY or BREVO_LIST_ID');
    json_response(false, 'Service indisponible pour le moment. Merci de reessayer plus tard.', 500);
}

$payload = [
    'email' => $email,
    'listIds' => [$listId],
    'updateEnabled' => true,
];

$body = json_encode($payload);
if ($body === false) {
    json_response(false, 'Erreur interne de preparation des donnees.', 500);
}

$headers = [
    'accept: application/json',
    'content-type: application/json',
    'api-key: ' . $apiKey,
];

$httpCode = 0;
$responseBody = '';

if (function_exists('curl_init')) {
    $ch = curl_init(BREVO_API_URL);
    if ($ch === false) {
        json_response(false, 'Erreur interne serveur.', 500);
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
    ]);

    $result = curl_exec($ch);
    if ($result === false) {
        $err = curl_error($ch);
        curl_close($ch);
        error_log('newsletter curl error: ' . $err);
        json_response(false, 'Impossible de contacter le service newsletter. Merci de reessayer.', 502);
    }

    $responseBody = (string)$result;
    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
} else {
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 15,
            'ignore_errors' => true,
        ],
    ]);

    $result = @file_get_contents(BREVO_API_URL, false, $context);
    if ($result === false) {
        json_response(false, 'Impossible de contacter le service newsletter. Merci de reessayer.', 502);
    }

    $responseBody = (string)$result;

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match('#HTTP/\S+\s+(\d{3})#', $headerLine, $m) === 1) {
                $httpCode = (int)$m[1];
                break;
            }
        }
    }
}

$decoded = json_decode($responseBody, true);

if ($httpCode >= 200 && $httpCode < 300) {
    $_SESSION['last_newsletter_send'] = time();
    json_response(true, 'Inscription confirmée. Merci et à bientôt.');
}

// Cas frequent: contact deja existant sur le compte/listes.
$brevoCode = is_array($decoded) ? (string)($decoded['code'] ?? '') : '';
if ($brevoCode === 'duplicate_parameter') {
    $_SESSION['last_newsletter_send'] = time();
    json_response(true, 'Cette adresse est deja inscrite a la newsletter.');
}

error_log('newsletter brevo error: status=' . $httpCode . ' body=' . $responseBody);
json_response(false, 'Inscription impossible pour le moment. Merci de reessayer plus tard.', 502);
