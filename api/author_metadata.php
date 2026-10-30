<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function authorMetadataRespond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function authorMetadataGet(string $url, string $userAgent): ?array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: ' . $userAgent],
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if (!is_string($body) || $status < 200 || $status >= 300) return null;
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId < 1) authorMetadataRespond(401, ['success' => false, 'message' => 'Your staff session ended. Sign in again.']);
$accessStmt = $pdo->prepare('SELECT role, account_status FROM users WHERE user_id = ? LIMIT 1');
$accessStmt->execute([$userId]);
$access = $accessStmt->fetch();
if (!$access || $access['account_status'] !== 'active') authorMetadataRespond(401, ['success' => false, 'message' => 'Your staff account is no longer active.']);
if (!in_array($access['role'], ['admin', 'staff'], true)) authorMetadataRespond(403, ['success' => false, 'message' => 'Only staff and administrators can search author profiles.']);
if (empty($_SESSION['mfa_verified'])) authorMetadataRespond(401, ['success' => false, 'message' => 'Your staff session needs MFA verification.']);

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input) || !validateCsrfToken((string) ($input['csrf_token'] ?? ''))) {
    authorMetadataRespond(403, ['success' => false, 'message' => 'Security token expired. Refresh and try again.']);
}
$action = (string) ($input['action'] ?? 'search');
$config = $GLOBALS['config'] ?? [];
$contact = trim((string) (getenv('OPEN_LIBRARY_CONTACT') ?: ($config['OPEN_LIBRARY_CONTACT'] ?? '')));
$userAgent = 'AklAAAtAuthorCatalog/1.0' . ($contact !== '' ? ' (' . $contact . ')' : ' (educational catalog lookup)');

if ($action === 'details') {
    $key = trim((string) ($input['key'] ?? ''));
    if (!preg_match('~^/authors/OL[0-9]+A$~D', $key)) authorMetadataRespond(400, ['success' => false, 'message' => 'Choose a valid Open Library author record.']);
    $data = authorMetadataGet('https://openlibrary.org' . $key . '.json', $userAgent);
    if (!$data) authorMetadataRespond(502, ['success' => false, 'message' => 'Open Library could not load that author profile.']);
    $bio = $data['bio'] ?? '';
    if (is_array($bio)) $bio = (string) ($bio['value'] ?? '');
    $photoId = '';
    foreach (($data['photos'] ?? []) as $photo) {
        if (is_numeric($photo) && (int) $photo > 0) { $photoId = (string) (int) $photo; break; }
    }
    authorMetadataRespond(200, [
        'success' => true,
        'name' => (string) ($data['name'] ?? ''),
        'bio' => is_string($bio) ? trim($bio) : '',
        'photo_url' => $photoId !== '' ? 'https://covers.openlibrary.org/a/id/' . rawurlencode($photoId) . '-M.jpg?default=false' : '',
        'source_url' => 'https://openlibrary.org' . $key,
    ]);
}

if ($action !== 'search') authorMetadataRespond(400, ['success' => false, 'message' => 'Unknown author lookup action.']);
$name = trim((string) ($input['name'] ?? ''));
if ($name === '' || mb_strlen($name) > 150) authorMetadataRespond(400, ['success' => false, 'message' => 'Enter an author name up to 150 characters.']);

$openLibrary = authorMetadataGet('https://openlibrary.org/search/authors.json?' . http_build_query(['q' => $name, 'limit' => 6]), $userAgent);
$authors = [];
foreach (($openLibrary['docs'] ?? []) as $doc) {
    $key = (string) ($doc['key'] ?? '');
    if (!preg_match('~^/authors/OL[0-9]+A$~D', $key)) continue;
    $authors[] = [
        'key' => $key,
        'name' => (string) ($doc['name'] ?? ''),
        'work_count' => (int) ($doc['work_count'] ?? 0),
        'top_work' => (string) ($doc['top_work'] ?? ''),
        'source_url' => 'https://openlibrary.org' . $key,
    ];
}

$works = [];
$apiKey = trim((string) (getenv('GOOGLE_BOOKS_API_KEY') ?: ($config['GOOGLE_BOOKS_API_KEY'] ?? '')));
if ($apiKey !== '') {
    $url = 'https://www.googleapis.com/books/v1/volumes?' . http_build_query([
        'q' => 'inauthor:"' . $name . '"', 'maxResults' => 5, 'printType' => 'books', 'key' => $apiKey,
    ]);
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: aklaaat-author-catalog/1.0'],
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $google = is_string($body) && $status >= 200 && $status < 300 ? json_decode($body, true) : null;
    foreach (($google['items'] ?? []) as $item) {
        $info = $item['volumeInfo'] ?? [];
        $bookAuthors = is_array($info['authors'] ?? null) ? $info['authors'] : [];
        $works[] = ['title' => (string) ($info['title'] ?? ''), 'authors' => array_values(array_filter($bookAuthors, 'is_string')), 'source_url' => (string) ($info['infoLink'] ?? '')];
    }
}

authorMetadataRespond(200, [
    'success' => true,
    'open_library_authors' => $authors,
    'google_books_works' => $works,
    'google_books_available' => $apiKey !== '',
    'message' => $apiKey === '' ? 'Open Library profiles are available. Google Books cross-check is not configured.' : '',
]);
