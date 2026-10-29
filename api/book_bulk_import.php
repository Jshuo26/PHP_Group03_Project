<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/author_verification.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/inventory_alerts.php';
require_once __DIR__ . '/../includes/upload_security.php';
require_once __DIR__ . '/../includes/catalog.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
requireRole(['admin', 'staff']);

function bulkApiRespond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bulkApiInput(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return $decoded;
    }
    return $_POST;
}

function bulkNormalizeText(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
}

function bulkValidIsbn(string $raw): bool
{
    $isbn = strtoupper(preg_replace('/[\s-]+/', '', trim($raw)) ?? '');
    if (preg_match('/^\d{13}$/', $isbn)) {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) $sum += (int) $isbn[$i] * ($i % 2 === 0 ? 1 : 3);
        return ((10 - ($sum % 10)) % 10) === (int) $isbn[12];
    }
    if (preg_match('/^\d{9}[\dX]$/', $isbn)) {
        $sum = 0;
        for ($i = 0; $i < 10; $i++) $sum += ($i + 1) * ($isbn[$i] === 'X' ? 10 : (int) $isbn[$i]);
        return $sum % 11 === 0;
    }
    return false;
}

function bulkIsAllowedCover(string $url): bool
{
    if ($url === '' || strlen($url) > 1000 || filter_var($url, FILTER_VALIDATE_URL) === false) return false;
    $parts = parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
        && !isset($parts['user']) && !isset($parts['pass'])
        && ($host === 'books.google.com' || $host === 'books.googleusercontent.com'
            || str_ends_with($host, '.books.googleusercontent.com'));
}

function bulkReusableCatalogCover(PDO $pdo, array $row): ?array
{
    $book = bulkFindExistingBook($pdo, $row);
    if (!$book) return null;
    $stmt = $pdo->prepare("SELECT cover_image_path, cover_source_url, cover_license_note FROM book_editions WHERE book_id = ? AND cover_image_path IS NOT NULL AND cover_image_path <> '' AND cover_license_note IS NOT NULL AND cover_license_note <> '' ORDER BY (format = ?) DESC, edition_id LIMIT 1");
    $stmt->execute([(int) $book['book_id'], (string) ($row['format'] ?? '')]);
    $cover = $stmt->fetch();
    return $cover ?: null;
}

function bulkFindExistingBook(PDO $pdo, array $row, bool $lock = false): ?array
{
    $isbn = trim((string) ($row['isbn'] ?? ''));
    if ($isbn !== '') {
        $stmt = $pdo->prepare('SELECT book_id, title, isbn, description, language, publisher, status, review_status FROM books WHERE isbn = ? LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$isbn]);
        $book = $stmt->fetch();
        if ($book) {
            $a = bulkNormalizeText((string) $book['title']);
            $b = bulkNormalizeText((string) $row['title']);
            if ($a !== $b && levenshtein($a, $b) > max(2, (int) (max(strlen($a), strlen($b)) * .12))) {
                throw new RuntimeException('ISBN ' . $isbn . ' is already attached to a different title. Review this row instead of merging it.');
            }
            return $book;
        }
    }

    $names = parseAuthorNames((string) ($row['author'] ?? ''));
    if (!$names) return null;
    $titleNeedle = bulkNormalizeText((string) $row['title']);
    $firstToken = (explode(' ', $titleNeedle)[0] ?? '');
    if (mb_strlen($firstToken) < 2) return null;
    $sql = 'SELECT b.book_id, b.title, b.isbn, b.description, b.language, b.publisher, b.status, b.review_status,
                   a.full_name AS author_name
              FROM books b JOIN book_authors ba ON ba.book_id = b.book_id JOIN authors a ON a.author_id = ba.author_id
             WHERE LOWER(b.title) LIKE ? LIMIT 300';
    $stmt = $pdo->prepare($sql . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute(['%' . $firstToken . '%']);
    $candidates = [];
    foreach ($stmt->fetchAll() as $candidate) {
        $bookId = (int) $candidate['book_id'];
        if (!isset($candidates[$bookId])) $candidates[$bookId] = ['book' => $candidate, 'authors' => []];
        $candidates[$bookId]['authors'][] = (string) $candidate['author_name'];
    }
    $matches = [];
    foreach ($candidates as $candidateData) {
        $candidate = $candidateData['book'];
        if (bulkNormalizeText((string) $candidate['title']) !== $titleNeedle) continue;
        $sameAuthor = false;
        $possibleAuthor = false;
        foreach ($names as $name) {
            $normalizedName = normalizeAuthorName($name);
            foreach ($candidateData['authors'] as $candidateName) {
                $normalizedCandidate = normalizeAuthorName($candidateName);
                if ($normalizedName === $normalizedCandidate) $sameAuthor = true;
                elseif (authorNamesPossiblyMatch($normalizedName, $normalizedCandidate) !== null) $possibleAuthor = true;
            }
        }
        if ($sameAuthor) {
            $inputIsbn = strtoupper(preg_replace('/[\s-]+/', '', trim((string) ($row['isbn'] ?? ''))) ?? '');
            $existingIsbn = strtoupper(preg_replace('/[\s-]+/', '', trim((string) ($candidate['isbn'] ?? ''))) ?? '');
            if ($inputIsbn !== '' && $existingIsbn !== '' && $inputIsbn !== $existingIsbn) {
                throw new RuntimeException('The title and author match a catalog book, but its ISBN differs. Verify whether this is a different edition before importing.');
            }
            unset($candidate['author_name']);
            $matches[] = $candidate;
        } elseif ($possibleAuthor) {
            throw new RuntimeException('The title matches an existing book with a possible author-name variation. Check the catalog and add the correct author alias before importing this row.');
        }
    }
    if (count($matches) > 1) throw new RuntimeException('Several catalog records match this title and author. Skip the row and resolve the duplicate manually.');
    return $matches[0] ?? null;
}

function bulkFindTitleCandidates(PDO $pdo, string $title): array
{
    $needle = bulkNormalizeText($title);
    $firstToken = explode(' ', $needle)[0] ?? '';
    if (mb_strlen($firstToken) < 2) return [];

    $stmt = $pdo->prepare(
        'SELECT b.book_id, b.title, b.isbn, b.description, b.language, b.publisher, b.metadata_source_url,
                a.full_name AS author_name, a.bio AS author_bio,
                e.format, e.stock_quantity, e.price, e.cover_image_path, e.cover_source_url, e.cover_license_note,
                g.genre_id, g.genre_name
           FROM books b
           LEFT JOIN book_authors ba ON ba.book_id = b.book_id
           LEFT JOIN authors a ON a.author_id = ba.author_id
           LEFT JOIN book_editions e ON e.book_id = b.book_id
           LEFT JOIN book_genres bg ON bg.book_id = b.book_id
           LEFT JOIN genres g ON g.genre_id = bg.genre_id
          WHERE LOWER(b.title) LIKE ?
          LIMIT 300'
    );
    $stmt->execute(['%' . $firstToken . '%']);
    $candidates = [];
    foreach ($stmt->fetchAll() as $row) {
        if (bulkNormalizeText((string) $row['title']) !== $needle) continue;
        $id = (int) $row['book_id'];
        if (!isset($candidates[$id])) {
            $candidates[$id] = [
                'book_id' => $id, 'title' => (string) $row['title'], 'isbn' => $row['isbn'],
                'description' => (string) ($row['description'] ?? ''),
                'language' => (string) ($row['language'] ?? ''),
                'publisher' => (string) ($row['publisher'] ?? ''),
                'metadata_source_url' => (string) ($row['metadata_source_url'] ?? ''),
                'authors' => [], 'author_bios' => [], 'editions' => [], 'genre_ids' => [],
            ];
        }
        if (!empty($row['author_name'])) {
            $authorName = (string) $row['author_name'];
            $candidates[$id]['authors'][$authorName] = true;
            if (trim((string) ($row['author_bio'] ?? '')) !== '') $candidates[$id]['author_bios'][$authorName] = (string) $row['author_bio'];
        }
        if (!empty($row['format'])) {
            $format = (string) $row['format'];
            $candidates[$id]['editions'][$format] = [
                'stock_quantity' => (int) $row['stock_quantity'],
                'price' => (float) $row['price'],
                'cover_url' => catalogCover((string) ($row['cover_image_path'] ?? '')),
                'cover_source_url' => (string) ($row['cover_source_url'] ?? ''),
                'cover_license_note' => (string) ($row['cover_license_note'] ?? ''),
            ];
        }
        if (!empty($row['genre_id'])) $candidates[$id]['genre_ids'][(int) $row['genre_id']] = (int) $row['genre_id'];
    }
    foreach ($candidates as &$candidate) {
        $candidate['authors'] = array_keys($candidate['authors']);
        $candidate['genre_ids'] = array_values($candidate['genre_ids']);
    }
    unset($candidate);
    return array_slice(array_values($candidates), 0, 5);
}

function bulkCatalogMatchResult(PDO $pdo, array $row): array
{
    $title = trim((string) ($row['title'] ?? ''));
    $author = trim((string) ($row['author'] ?? ''));
    $isbn = strtoupper(preg_replace('/[\s-]+/', '', trim((string) ($row['isbn'] ?? ''))) ?? '');
    $format = (string) ($row['format'] ?? '');
    if ($title === '') return ['success' => false, 'message' => 'Enter a title before matching.'];

    $authorDescription = '';
    $authorNames = parseAuthorNames($author);
    if (count($authorNames) === 1) {
        $authorMatches = findAuthorMatches($pdo, $authorNames[0]);
        if (count($authorMatches['exact']) === 1) $authorDescription = trim((string) ($authorMatches['exact'][0]['bio'] ?? ''));
    }

    $book = bulkFindExistingBook($pdo, ['title' => $title, 'author' => $author, 'isbn' => $isbn]);
    if (!$book) {
        return [
            'success' => true, 'match' => null, 'author_description' => $authorDescription,
            'possible_matches' => bulkFindTitleCandidates($pdo, $title),
        ];
    }

    $authorStmt = $pdo->prepare('SELECT a.full_name, a.bio FROM book_authors ba JOIN authors a ON a.author_id = ba.author_id WHERE ba.book_id = ? ORDER BY ba.author_id');
    $authorStmt->execute([(int) $book['book_id']]);
    $authors = $authorStmt->fetchAll();
    if ($authorDescription === '' && count($authors) === 1) $authorDescription = trim((string) ($authors[0]['bio'] ?? ''));
    $genreStmt = $pdo->prepare('SELECT genre_id FROM book_genres WHERE book_id = ?');
    $genreStmt->execute([(int) $book['book_id']]);
    $formatsStmt = $pdo->prepare('SELECT format FROM book_editions WHERE book_id = ? ORDER BY FIELD(format, \'paperback\', \'hardbound\', \'ebook\')');
    $formatsStmt->execute([(int) $book['book_id']]);
    $availableFormats = $formatsStmt->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array($format, ['paperback', 'hardbound', 'ebook'], true)) {
        $format = count($availableFormats) === 1 ? (string) $availableFormats[0] : (in_array('paperback', $availableFormats, true) ? 'paperback' : '');
    }
    $editionStmt = $pdo->prepare('SELECT edition_id, format, stock_quantity, price, cover_image_path, cover_source_url, cover_license_note FROM book_editions WHERE book_id = ? AND format = ? LIMIT 1');
    $editionStmt->execute([(int) $book['book_id'], $format]);
    $edition = $editionStmt->fetch();
    return [
        'success' => true,
        'match' => [
            'book_id' => (int) $book['book_id'], 'title' => $book['title'], 'isbn' => $book['isbn'],
            'description' => (string) ($book['description'] ?? ''),
            'language' => (string) ($book['language'] ?? ''),
            'publisher' => (string) ($book['publisher'] ?? ''),
            'author' => implode('; ', array_column($authors, 'full_name')),
            'genre_ids' => array_map('intval', $genreStmt->fetchAll(PDO::FETCH_COLUMN)),
            'suggested_format' => $format,
            'available_formats' => array_values($availableFormats),
            'edition_id' => $edition ? (int) $edition['edition_id'] : null,
            'stock_quantity' => $edition ? (int) $edition['stock_quantity'] : 0,
            'price' => $edition ? (float) $edition['price'] : null,
            'cover_url' => $edition ? catalogCover((string) ($edition['cover_image_path'] ?? '')) : '',
            'cover_source_url' => (string) ($edition['cover_source_url'] ?? ''),
            'cover_license_note' => (string) ($edition['cover_license_note'] ?? ''),
        ],
        'author_description' => $authorDescription,
    ];
}

function bulkCheckAuthorNames(PDO $pdo, array $rows): array
{
    $authors = $pdo->query('SELECT author_id, full_name, verification_status FROM authors')->fetchAll();
    $aliasesById = [];
    try {
        foreach ($pdo->query("SELECT author_id, alias FROM author_aliases")->fetchAll() as $alias) {
            $aliasesById[(int) $alias['author_id']][] = (string) $alias['alias'];
        }
    } catch (Throwable $e) {
        // Older local databases may not have the optional alias table yet.
    }
    $results = [];
    foreach ($rows as $index => $names) {
        if (!is_array($names)) continue;
        $checks = [];
        foreach (array_slice($names, 0, MAX_AUTHORS_PER_BOOK) as $name) {
            $name = trim((string) $name);
            if ($name === '') continue;
            $needle = normalizeAuthorName($name);
            $exact = [];
            $possible = [];
            foreach ($authors as $author) {
                $alternates = array_merge([(string) $author['full_name']], $aliasesById[(int) $author['author_id']] ?? []);
                foreach ($alternates as $alternate) {
                    $normalized = normalizeAuthorName($alternate);
                    if ($normalized === $needle) {
                        $exact[] = $author;
                        break;
                    }
                    if ($author['verification_status'] === 'verified' && authorNamesPossiblyMatch($needle, $normalized) !== null) $possible[] = $author;
                }
            }
            $uniqueExact = [];
            foreach ($exact as $author) $uniqueExact[(int) $author['author_id']] = $author;
            $exact = array_values($uniqueExact);
            $uniquePossible = [];
            foreach ($possible as $author) $uniquePossible[(int) $author['author_id']] = $author;
            $possible = array_values($uniquePossible);
            if (count($exact) === 1 && $exact[0]['verification_status'] === 'verified') {
                $checks[] = ['name' => $name, 'status' => 'verified', 'matched_name' => (string) $exact[0]['full_name']];
            } elseif (count($exact) === 1) {
                $checks[] = ['name' => $name, 'status' => (string) $exact[0]['verification_status'], 'matched_name' => (string) $exact[0]['full_name']];
            } elseif (count($exact) > 1) {
                $checks[] = ['name' => $name, 'status' => 'ambiguous', 'matches' => array_values(array_unique(array_column($exact, 'full_name')))];
            } elseif ($possible) {
                $checks[] = ['name' => $name, 'status' => 'possible_match', 'matches' => array_values(array_unique(array_column($possible, 'full_name')))];
            } else {
                $checks[] = ['name' => $name, 'status' => 'not_found'];
            }
        }
        $results[(int) $index] = $checks;
    }
    return $results;
}

function bulkValidateRow(PDO $pdo, array $row, int $index, array $genreIds, bool $hasUploadedCover = false): ?string
{
    if (!empty($row['skip'])) return null;
    $title = trim((string) ($row['title'] ?? ''));
    $author = trim((string) ($row['author'] ?? ''));
    $description = trim((string) ($row['description'] ?? ''));
    $isbn = strtoupper(preg_replace('/[\s-]+/', '', trim((string) ($row['isbn'] ?? ''))) ?? '');
    $format = (string) ($row['format'] ?? '');
    $language = trim((string) ($row['language'] ?? ''));
    $cover = trim((string) ($row['cover_url'] ?? ''));
    $qty = filter_var($row['quantity'] ?? null, FILTER_VALIDATE_INT);
    $price = filter_var($row['price'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($title === '' || mb_strlen($title) > 255) return 'Enter a title up to 255 characters.';
    if ($author === '' || mb_strlen($author) > 600) return 'Add at least one author (up to 5).';
    $names = parseAuthorNames($author);
    if (($authorError = validateAuthorNames($names)) !== null) return $authorError;
    if ($description === '') return 'Add a book description before importing.';
    if (strlen($description) > 65535) return 'Description is too long.';
    if ($isbn !== '' && !bulkValidIsbn($isbn)) return 'ISBN is not valid. Correct it or clear it if the title has no ISBN.';
    if (!in_array($format, ['paperback', 'hardbound', 'ebook'], true)) return 'Select a supported format.';
    if ($language === '' || mb_strlen($language) > 50) return 'Add the book language.';
    $savedCover = bulkReusableCatalogCover($pdo, $row);
    $canReuseSavedCover = !empty($row['reuse_catalog_cover']) && $savedCover !== null;
    if ((!bulkIsAllowedCover($cover) && !$hasUploadedCover && !$canReuseSavedCover) || (empty($row['cover_permission_confirmed']) && !$canReuseSavedCover)) return 'Choose a Google Books cover or upload a permitted cover file, then confirm it is allowed for this project.';
    if ($qty === false || $qty < 1 || $qty > 9999999) return 'Quantity must be a whole number from 1 to 9,999,999.';
    if ($price === false || $price <= 0 || $price > 99999999) return 'Enter a selling price greater than zero.';
    $selected = $row['genre_ids'] ?? [];
    if (!is_array($selected) || !$selected) return 'Choose at least one store genre.';
    $selected = array_values(array_unique(array_filter(array_map('intval', $selected), static fn($id) => $id > 0)));
    if (array_diff($selected, $genreIds)) return 'One or more selected genres are no longer active.';

    $authorMatches = [];
    foreach ($names as $name) {
        $matches = findAuthorMatches($pdo, $name);
        $authorMatches[] = ['name' => $name, 'verified' => count($matches['exact']) === 1];
    }
    $unknown = array_filter($authorMatches, static fn($a) => !$a['verified']);
    if ($unknown) {
        $evidence = trim((string) ($row['filipino_evidence_url'] ?? ''));
        if (filter_var($evidence, FILTER_VALIDATE_URL) === false || !in_array(strtolower((string) parse_url($evidence, PHP_URL_SCHEME)), ['http', 'https'], true) || strlen($evidence) > 500) {
            return 'Add a public source where the author self-identifies as Filipino. New/unverified authors stay hidden until approved.';
        }
    }
    return null;
}

function bulkApplyRow(PDO $pdo, array $row, int $userId): array
{
    $book = bulkFindExistingBook($pdo, $row, true);
    $format = (string) $row['format'];
    $quantity = (int) $row['quantity'];
    $genreIds = array_values(array_unique(array_map('intval', $row['genre_ids'])));
    $coverUrl = trim((string) $row['cover_url']);
    if (!bulkIsAllowedCover($coverUrl) && !empty($row['reuse_catalog_cover'])) {
        $savedCover = bulkReusableCatalogCover($pdo, $row);
        if ($savedCover) {
            $coverUrl = basename(str_replace('\\', '/', (string) $savedCover['cover_image_path']));
            $row['cover_source_url'] = $savedCover['cover_source_url'];
            $row['cover_license_note'] = $savedCover['cover_license_note'];
        }
    }
    $unitCost = trim((string) ($row['unit_cost'] ?? ''));
    $markup = (float) ($row['markup_percent'] ?? 25);
    $price = $unitCost !== '' ? round((float) $unitCost * (1 + $markup / 100), 2) : round((float) $row['price'], 2);
    if ($price <= 0 || $price > 99999999) throw new RuntimeException('The calculated selling price is outside the supported range.');

    $isNewBook = !$book;
    if ($isNewBook) {
        $stmt = $pdo->prepare("INSERT INTO books (title, description, isbn, language, publisher, status, review_status, metadata_source_url) VALUES (?, ?, ?, ?, ?, 'active', 'pending_review', ?)");
        $stmt->execute([
            trim((string) $row['title']), trim((string) $row['description']),
            (trim((string) $row['isbn']) !== '' ? strtoupper(preg_replace('/[\s-]+/', '', (string) $row['isbn'])) : null),
            trim((string) $row['language']), trim((string) ($row['publisher'] ?? '')) ?: null,
            trim((string) ($row['metadata_source_url'] ?? '')) ?: null,
        ]);
        $bookId = (int) $pdo->lastInsertId();
    } else {
        $bookId = (int) $book['book_id'];
        // Enrich only blank catalog fields; reviewed catalog text remains authoritative.
        $pdo->prepare('UPDATE books SET description = COALESCE(NULLIF(description, \'\'), ?), isbn = COALESCE(NULLIF(isbn, \'\'), ?), language = COALESCE(NULLIF(language, \'\'), ?), publisher = COALESCE(NULLIF(publisher, \'\'), ?), metadata_source_url = COALESCE(NULLIF(metadata_source_url, \'\'), ?) WHERE book_id = ?')
            ->execute([trim((string) $row['description']), trim((string) ($row['isbn'] ?? '')) !== '' ? strtoupper(preg_replace('/[\s-]+/', '', (string) $row['isbn'])) : null, trim((string) $row['language']), trim((string) ($row['publisher'] ?? '')) ?: null, trim((string) ($row['metadata_source_url'] ?? '')) ?: null, $bookId]);
    }

    $editionStmt = $pdo->prepare('SELECT edition_id, stock_quantity, cover_image_path FROM book_editions WHERE book_id = ? AND format = ? FOR UPDATE');
    $editionStmt->execute([$bookId, $format]);
    $edition = $editionStmt->fetch();
    $matched = $book !== null;
    if ($edition) {
        $editionId = (int) $edition['edition_id'];
        $pdo->prepare('UPDATE book_editions SET stock_quantity = stock_quantity + ?, cover_image_path = IF(cover_image_path IS NULL OR cover_image_path = \'\', ?, cover_image_path), cover_source_url = IF(cover_source_url IS NULL OR cover_source_url = \'\', ?, cover_source_url), cover_license_note = IF(cover_license_note IS NULL OR cover_license_note = \'\', ?, cover_license_note) WHERE edition_id = ?')
            ->execute([$quantity, $coverUrl, trim((string) ($row['cover_source_url'] ?? '')) ?: null, (string) ($row['cover_license_note'] ?? 'Staff confirmed cover use for the project demo.'), $editionId]);
        // Existing product prices are intentionally preserved; current inventory batches only restock them.
    } else {
        $pdo->prepare('INSERT INTO book_editions (book_id, format, price, stock_quantity, low_stock_threshold, cover_image_path, cover_source_url, cover_license_note) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$bookId, $format, number_format($price, 2, '.', ''), $quantity, getLowStockThreshold($pdo), $coverUrl, trim((string) ($row['cover_source_url'] ?? '')) ?: null, (string) ($row['cover_license_note'] ?? 'Staff confirmed cover use for the project demo.')]);
        $editionId = (int) $pdo->lastInsertId();
    }
    syncEditionLowStockAlert($pdo, $editionId);

    $genreInsert = $pdo->prepare('INSERT IGNORE INTO book_genres (book_id, genre_id) VALUES (?, ?)');
    foreach ($genreIds as $genreId) $genreInsert->execute([$bookId, $genreId]);
    $authorOutcome = linkBookAuthors($pdo, $bookId, parseAuthorNames((string) $row['author']), $userId);
    $primaryAuthor = $authorOutcome['authors'][0] ?? null;
    if ($primaryAuthor && trim((string) ($row['author_description'] ?? '')) !== '') {
        $pdo->prepare('UPDATE authors SET bio = COALESCE(NULLIF(bio, \'\'), ?) WHERE author_id = ?')
            ->execute([trim((string) $row['author_description']), (int) $primaryAuthor['author_id']]);
    }

    $evidenceUrl = trim((string) ($row['filipino_evidence_url'] ?? ''));
    foreach ($authorOutcome['authors'] as $linkedAuthor) {
        if ($linkedAuthor['status'] === 'verified' || $evidenceUrl === '') continue;
        $existingEvidence = $pdo->prepare('SELECT 1 FROM author_evidence WHERE author_id = ? AND source_url = ? LIMIT 1');
        $existingEvidence->execute([(int) $linkedAuthor['author_id'], $evidenceUrl]);
        if (!$existingEvidence->fetchColumn()) {
            $pdo->prepare('INSERT INTO author_evidence (author_id, source_url, added_by) VALUES (?, ?, ?)')->execute([(int) $linkedAuthor['author_id'], $evidenceUrl, $userId]);
        }
        $pdo->prepare('UPDATE authors SET self_declared_note = COALESCE(NULLIF(self_declared_note, \'\'), ?) WHERE author_id = ?')
            ->execute(['Author self-identification source recorded for bulk import.', (int) $linkedAuthor['author_id']]);
    }
    return ['book_id' => $bookId, 'edition_id' => $editionId, 'matched' => $matched, 'review_status' => $authorOutcome['review_status']];
}

$input = bulkApiInput();
$token = (string) ($input['csrf_token'] ?? '');
if (!validateCsrfToken($token)) bulkApiRespond(403, ['success' => false, 'message' => 'Security token expired. Refresh the page and try again.']);
$action = (string) ($input['action'] ?? '');

if ($action === 'check_authors') {
    $authorRows = $input['authors'] ?? null;
    if (!is_array($authorRows) || count($authorRows) > 500) bulkApiRespond(400, ['success' => false, 'message' => 'Author check batch is invalid.']);
    bulkApiRespond(200, ['success' => true, 'results' => bulkCheckAuthorNames($pdo, $authorRows)]);
}

if ($action === 'match') {
    try {
        $result = bulkCatalogMatchResult($pdo, $input);
        bulkApiRespond(!empty($result['success']) ? 200 : 400, $result);
    } catch (Throwable $e) {
        bulkApiRespond(200, ['success' => true, 'match' => null, 'notice' => $e->getMessage()]);
    }
}

if ($action === 'match_many') {
    $checkRows = $input['rows'] ?? null;
    if (!is_array($checkRows) || count($checkRows) > 500) bulkApiRespond(400, ['success' => false, 'message' => 'Catalog check batch is invalid.']);
    $results = [];
    foreach ($checkRows as $index => $row) {
        if (!is_array($row) || trim((string) ($row['title'] ?? '')) === '') continue;
        try {
            $results[(int) $index] = bulkCatalogMatchResult($pdo, $row);
        } catch (Throwable $e) {
            $results[(int) $index] = ['success' => true, 'match' => null, 'notice' => $e->getMessage()];
        }
    }
    bulkApiRespond(200, ['success' => true, 'results' => $results]);
}

if ($action !== 'commit') bulkApiRespond(400, ['success' => false, 'message' => 'Unknown bulk import action.']);
$batchId = (string) ($input['batch_id'] ?? '');
$rows = json_decode((string) ($input['rows_json'] ?? ''), true);
if (!preg_match('/^[a-f0-9]{32}$/', $batchId) || !is_array($rows) || count($rows) > 500) {
    bulkApiRespond(400, ['success' => false, 'message' => 'The batch payload is invalid. Refresh the batch and try again.']);
}

try {
    $batchStmt = $pdo->prepare('SELECT batch_id, user_id, status, row_count, rows_json FROM book_import_batches WHERE batch_id = ?');
    $batchStmt->execute([$batchId]);
    $batch = $batchStmt->fetch();
    if (!$batch || (int) $batch['user_id'] !== (int) $_SESSION['user_id']) bulkApiRespond(404, ['success' => false, 'message' => 'This batch does not belong to your account.']);
    if ($batch['status'] === 'imported') bulkApiRespond(200, ['success' => true, 'already_imported' => true, 'message' => 'This batch was already imported. No stock was changed again.']);
    $originalRows = json_decode((string) $batch['rows_json'], true) ?: [];
    if (count($originalRows) !== (int) $batch['row_count'] || count($rows) !== count($originalRows)) bulkApiRespond(400, ['success' => false, 'message' => 'The row count changed. Reload this batch before importing.']);

    $genreIds = array_map('intval', $pdo->query("SELECT genre_id FROM genres WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN));
    $issues = [];
    $coverUploads = [];
    foreach ($rows as $index => $row) {
        if (!is_array($row) || !empty($row['skip'])) continue;
        $fileKey = 'cover_' . (int) $index;
        if (!isset($_FILES[$fileKey]) || (int) ($_FILES[$fileKey]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        try {
            if ((int) ($_FILES[$fileKey]['size'] ?? 0) > 1572864) throw new RuntimeException('Each uploaded book cover must be 1.5 MB or smaller.');
            $coverUploads[(int) $index] = validatePublicImageUpload($_FILES[$fileKey], 'book cover', 1572864);
        } catch (RuntimeException $e) {
            $issues[] = ['index' => (int) $index, 'message' => $e->getMessage()];
        }
    }
    $seenIsbns = [];
    foreach ($rows as $index => $row) {
        if (!is_array($row)) { $issues[] = ['index' => $index, 'message' => 'Invalid row data.']; continue; }
        if (!empty($row['skip'])) continue;
        $isbn = strtoupper(preg_replace('/[\s-]+/', '', trim((string) ($row['isbn'] ?? ''))) ?? '');
        if ($isbn !== '' && isset($seenIsbns[$isbn])) {
            $issues[] = ['index' => $index, 'message' => 'This ISBN appears more than once in the batch. Keep one row and combine its quantity.'];
            continue;
        }
        if ($isbn !== '') $seenIsbns[$isbn] = true;
        $issue = bulkValidateRow($pdo, $row, $index, $genreIds, isset($coverUploads[$index]));
        if ($issue !== null) $issues[] = ['index' => $index, 'message' => $issue];
        if (!empty($row['unit_cost']) && (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', (string) $row['unit_cost']) || (float) $row['unit_cost'] < 0)) $issues[] = ['index' => $index, 'message' => 'Unit cost is invalid.'];
        if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', (string) ($row['markup_percent'] ?? '25')) || (float) ($row['markup_percent'] ?? 25) > 500) $issues[] = ['index' => $index, 'message' => 'Markup must be between 0% and 500%.'];
    }
    if ($issues) bulkApiRespond(422, ['success' => false, 'message' => 'Some rows need attention before this batch can be imported.', 'issues' => $issues]);

    $savedCoverPaths = [];
    if ($coverUploads) {
        try {
            $coverDirectory = __DIR__ . '/../images/book-covers';
            foreach ($coverUploads as $index => $image) {
                $saved = savePublicImageUpload($image, $coverDirectory);
                $savedCoverPaths[] = $saved['path'];
                $rows[$index]['cover_url'] = $saved['filename'];
                $rows[$index]['cover_source_url'] = '';
                $rows[$index]['cover_license_note'] = 'Staff uploaded a cover and confirmed permission for project display.';
            }
        } catch (Throwable $e) {
            foreach ($savedCoverPaths as $savedPath) if (is_file($savedPath)) @unlink($savedPath);
            throw $e;
        }
    }

    $pdo->beginTransaction();
    $lockBatch = $pdo->prepare('SELECT status, user_id FROM book_import_batches WHERE batch_id = ? AND user_id = ? FOR UPDATE');
    $lockBatch->execute([$batchId, (int) $_SESSION['user_id']]);
    $currentStatus = $lockBatch->fetchColumn();
    if ($currentStatus === 'imported') {
        $pdo->commit();
        foreach ($savedCoverPaths as $savedPath) if (is_file($savedPath)) @unlink($savedPath);
        bulkApiRespond(200, ['success' => true, 'already_imported' => true, 'message' => 'This batch was already imported. No stock was changed again.']);
    }
    $created = $restocked = $pendingReview = $skipped = 0;
    foreach ($rows as $row) {
        if (!empty($row['skip'])) { $skipped++; continue; }
        $result = bulkApplyRow($pdo, $row, (int) $_SESSION['user_id']);
        if ($result['matched']) $restocked++; else $created++;
        if ($result['review_status'] !== 'approved') $pendingReview++;
    }
    $summary = ['created' => $created, 'restocked' => $restocked, 'pending_review' => $pendingReview, 'skipped' => $skipped];
    $pdo->prepare("UPDATE book_import_batches SET status = 'imported', rows_json = ?, imported_count = ?, imported_at = CURRENT_TIMESTAMP, result_json = ? WHERE batch_id = ?")
        ->execute([json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $created + $restocked, json_encode($summary), $batchId]);
    auditLogRequired($pdo, 'create', 'Bulk book batch ' . substr($batchId, 0, 8) . ' imported: ' . $created . ' new, ' . $restocked . ' restocked, ' . $pendingReview . ' awaiting author review, ' . $skipped . ' skipped.', 'products', (int) $_SESSION['user_id'], 'book_import_batch', null, 'success', (string) $_SESSION['role']);
    $pdo->commit();
    bulkApiRespond(200, ['success' => true, 'message' => 'Bulk import complete.', 'summary' => $summary]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach (($savedCoverPaths ?? []) as $savedPath) if (is_file($savedPath)) @unlink($savedPath);
    error_log('Bulk book import failed: ' . $e->getMessage());
    bulkApiRespond(500, ['success' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'The batch could not be imported. No rows were committed. Check the database migration and try again.']);
}
