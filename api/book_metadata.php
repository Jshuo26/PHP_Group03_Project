<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/catalog.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function bookMetadataRespond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId < 1) bookMetadataRespond(401, ['success' => false, 'message' => 'Your staff session ended. Sign in again and retry the lookup.']);
$accessStmt = $pdo->prepare('SELECT role, account_status FROM users WHERE user_id = ? LIMIT 1');
$accessStmt->execute([$userId]);
$access = $accessStmt->fetch();
if (!$access || $access['account_status'] !== 'active') bookMetadataRespond(401, ['success' => false, 'message' => 'Your staff account is no longer active. Sign in again or contact an administrator.']);
if (!in_array($access['role'], ['admin', 'staff'], true)) bookMetadataRespond(403, ['success' => false, 'message' => 'Only staff and administrators can search book metadata.']);
if (empty($_SESSION['mfa_verified'])) bookMetadataRespond(401, ['success' => false, 'message' => 'Your staff session needs MFA verification. Sign in again and retry the lookup.']);

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input) || !validateCsrfToken((string) ($input['csrf_token'] ?? ''))) {
    bookMetadataRespond(403, ['success' => false, 'message' => 'Security token expired. Refresh the page and try again.']);
}

$query = trim((string) ($input['query'] ?? ''));
if ($query === '' || mb_strlen($query) > 500) {
    bookMetadataRespond(400, ['success' => false, 'message' => 'Enter a shorter title or ISBN query.']);
}

$config = $GLOBALS['config'] ?? [];
$apiKey = trim((string) (getenv('GOOGLE_BOOKS_API_KEY') ?: ($config['GOOGLE_BOOKS_API_KEY'] ?? '')));
if ($apiKey === '') {
    bookMetadataRespond(503, ['success' => false, 'message' => 'Google Books lookup needs an API key. Add GOOGLE_BOOKS_API_KEY to config/config.php, then refresh this page.']);
}

$now = microtime(true);
$lastLookup = (float) ($_SESSION['google_books_last_lookup'] ?? 0);
if ($now - $lastLookup < 1.1) {
    bookMetadataRespond(429, ['success' => false, 'message' => 'Please wait briefly before the next Google Books lookup.']);
}
$_SESSION['google_books_last_lookup'] = $now;

$url = 'https://www.googleapis.com/books/v1/volumes?' . http_build_query([
    'q' => $query,
    'maxResults' => 5,
    'printType' => 'books',
    'key' => $apiKey,
]);
$curl = curl_init($url);
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: aklaaat-book-catalog-import/1.0'],
]);
$body = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$curlError = curl_error($curl);
curl_close($curl);

if (!is_string($body)) {
    error_log('Google Books lookup transport failed: ' . $curlError);
    bookMetadataRespond(502, ['success' => false, 'message' => 'Could not reach Google Books. Check the server connection and try again.']);
}

$data = json_decode($body, true);
if ($status === 429) {
    bookMetadataRespond(429, ['success' => false, 'message' => 'Google Books is rate limiting this project. Check the API key quota in Google Cloud, then retry later.']);
}
if ($status < 200 || $status >= 300 || !is_array($data)) {
    error_log('Google Books lookup returned HTTP ' . $status . '.');
    bookMetadataRespond(502, ['success' => false, 'message' => 'Google Books could not complete this lookup (HTTP ' . $status . ').']);
}

bookMetadataRespond(200, ['success' => true, 'items' => is_array($data['items'] ?? null) ? $data['items'] : []]);
