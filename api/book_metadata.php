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
$title = trim((string) ($input['title'] ?? ''));
$author = trim((string) ($input['author'] ?? ''));
$isbn = strtoupper(preg_replace('/[\s-]+/', '', trim((string) ($input['isbn'] ?? ''))) ?? '');
$providerRequested = strtolower(trim((string) ($input['provider'] ?? 'google_books')));

function openLibraryItems(string $title, string $author, string $isbn, array $config): array
{
    $fields = 'key,title,author_name,first_publish_year,cover_i,isbn,publisher,language,first_sentence,subject,edition_key,number_of_pages_median';
    $params = ['fields' => $fields, 'limit' => 5];
    if ($isbn !== '') {
        $params['isbn'] = $isbn;
    } else {
        $params['title'] = $title;
        $params['author'] = $author;
    }
    $url = 'https://openlibrary.org/search.json?' . http_build_query($params);
    $contact = trim((string) (getenv('OPEN_LIBRARY_CONTACT') ?: ($config['OPEN_LIBRARY_CONTACT'] ?? '')));
    $userAgent = 'AklAAAtBookCatalogImport/1.0' . ($contact !== '' ? ' (' . $contact . ')' : ' (educational catalog lookup)');
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
    $error = curl_error($curl);
    curl_close($curl);
    if (!is_string($body) || $status < 200 || $status >= 300) {
        error_log('Open Library fallback failed (HTTP ' . $status . '): ' . $error);
        return [];
    }
    $payload = json_decode($body, true);
    if (!is_array($payload) || !is_array($payload['docs'] ?? null)) return [];
    $items = [];
    foreach (array_slice($payload['docs'], 0, 5) as $doc) {
        $identifiers = is_array($doc['isbn'] ?? null) ? $doc['isbn'] : [];
        $isbn13 = '';
        $isbn10 = '';
        foreach ($identifiers as $identifier) {
            $clean = strtoupper(preg_replace('/[\s-]+/', '', (string) $identifier) ?? '');
            if (preg_match('/^\d{13}$/', $clean) && $isbn13 === '') $isbn13 = $clean;
            elseif (preg_match('/^\d{9}[\dX]$/', $clean) && $isbn10 === '') $isbn10 = $clean;
        }
        $key = trim((string) ($doc['key'] ?? ''));
        $cover = !empty($doc['cover_i']) ? 'https://covers.openlibrary.org/b/id/' . rawurlencode((string) $doc['cover_i']) . '-L.jpg?default=false' : '';
        $description = $doc['first_sentence'] ?? '';
        if (is_array($description)) $description = implode(' ', array_map('strval', $description));
        $language = is_array($doc['language'] ?? null) ? (string) ($doc['language'][0] ?? '') : '';
        $items[] = [
            'id' => $key,
            'provider' => 'Open Library',
            'volumeInfo' => [
                'title' => (string) ($doc['title'] ?? ''),
                'authors' => is_array($doc['author_name'] ?? null) ? $doc['author_name'] : [],
                'publisher' => is_array($doc['publisher'] ?? null) ? (string) ($doc['publisher'][0] ?? '') : '',
                'publishedDate' => (string) ($doc['first_publish_year'] ?? ''),
                'description' => (string) $description,
                'language' => $language,
                'pageCount' => (int) ($doc['number_of_pages_median'] ?? 0),
                'categories' => is_array($doc['subject'] ?? null) ? array_slice($doc['subject'], 0, 10) : [],
                'industryIdentifiers' => array_values(array_filter([
                    $isbn13 !== '' ? ['type' => 'ISBN_13', 'identifier' => $isbn13] : null,
                    $isbn10 !== '' ? ['type' => 'ISBN_10', 'identifier' => $isbn10] : null,
                ])),
                'imageLinks' => $cover !== '' ? ['thumbnail' => $cover] : [],
                'infoLink' => $key !== '' ? 'https://openlibrary.org' . $key : 'https://openlibrary.org/search?' . http_build_query(['title' => (string) ($doc['title'] ?? $title)]),
                'editionKeys' => is_array($doc['edition_key'] ?? null) ? $doc['edition_key'] : [],
            ],
            'saleInfo' => [],
        ];
    }
    return $items;
}

$items = [];
$provider = 'Google Books';
$googleFailure = '';
if ($providerRequested !== 'open_library' && $apiKey !== '') {
    $now = microtime(true);
    $lastLookup = (float) ($_SESSION['google_books_last_lookup'] ?? 0);
    if ($now - $lastLookup < 1.1) {
        $googleFailure = 'Google Books lookup was rate limited; trying Open Library.';
    } else {
        $_SESSION['google_books_last_lookup'] = $now;
        $url = 'https://www.googleapis.com/books/v1/volumes?' . http_build_query([
            'q' => $query, 'maxResults' => 5, 'printType' => 'books', 'key' => $apiKey,
        ]);
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: aklaaat-book-catalog-import/1.0'],
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);
        $googleData = is_string($body) ? json_decode($body, true) : null;
        if ($status >= 200 && $status < 300 && is_array($googleData)) {
            $items = is_array($googleData['items'] ?? null) ? $googleData['items'] : [];
            foreach ($items as &$item) { $item['provider'] = 'Google Books'; }
            unset($item);
        } else {
            $googleFailure = 'Google Books was unavailable (HTTP ' . $status . ').';
            error_log('Google Books lookup failed: ' . $googleFailure . ' ' . $curlError);
        }
    }
} elseif ($providerRequested !== 'open_library') {
    $googleFailure = 'Google Books API key is not configured.';
}

if ($providerRequested === 'open_library' || !$items) {
    // Open Library asks clients to identify themselves and keep searches human-triggered and low-volume.
    $lastLookup = (float) ($_SESSION['open_library_last_lookup'] ?? 0);
    $wait = 1.05 - (microtime(true) - $lastLookup);
    if ($lastLookup > 0 && $wait > 0) usleep((int) ($wait * 1000000));
    $_SESSION['open_library_last_lookup'] = microtime(true);
    $items = openLibraryItems($title, $author, $isbn, $config);
    $provider = 'Open Library';
}

bookMetadataRespond(200, [
    'success' => true,
    'items' => $items,
    'provider' => $provider,
    'providers' => array_values(array_unique(array_map(static function (array $item): string { return (string) ($item['provider'] ?? $provider); }, $items))),
    'fallback_notice' => $provider === 'Open Library' ? ($googleFailure !== '' ? $googleFailure . ' Open Library was tried as fallback.' : 'No Google Books result; Open Library was tried as fallback.') : '',
]);
