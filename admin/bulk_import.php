<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/input.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/author_verification.php';

requireRole(['admin', 'staff']);

const BULK_IMPORT_MAX_BYTES = 2097152;
const BULK_IMPORT_MAX_ROWS = 500;

function bulkImportEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function bulkImportHeader(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = preg_replace('/[\s\-_]+/u', '', $value) ?? '';
    return $value;
}

function bulkImportRowsFromCsv(array $file, int $defaultQuantity, float $defaultMarkup, float $fallbackPrice): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        throw new RuntimeException('Choose a CSV file to upload.');
    }
    if ((int) ($file['size'] ?? 0) < 1 || (int) $file['size'] > BULK_IMPORT_MAX_BYTES) {
        throw new RuntimeException('The CSV must be smaller than 2 MB.');
    }

    $handle = fopen((string) $file['tmp_name'], 'rb');
    if ($handle === false) {
        throw new RuntimeException('The CSV could not be opened.');
    }
    try {
        $headers = fgetcsv($handle, 0, ',', '"', '\\');
        if (!is_array($headers) || !$headers) {
            throw new RuntimeException('The CSV is empty or has no header row.');
        }
        if (isset($headers[0])) {
            $headers[0] = preg_replace('/^\\xEF\\xBB\\xBF/', '', (string) $headers[0]) ?? (string) $headers[0];
        }
        $map = [];
        foreach ($headers as $index => $header) {
            $key = bulkImportHeader((string) $header);
            $field = match ($key) {
                'title', 'booktitle', 'name' => 'title',
                'author', 'authors', 'authorname', 'contributor' => 'author',
                'authordescription', 'authorbio', 'biography' => 'author_description',
                'isbn', 'isbn10', 'isbn13', 'barcode' => 'isbn',
                'quantity', 'stock', 'stockquantity', 'copies' => 'quantity',
                'cost', 'unitcost', 'purchasecost' => 'cost',
                'price', 'sellingprice', 'websiteprice' => 'price',
                'format', 'edition', 'binding' => 'format',
                'publisher', 'imprint' => 'publisher',
                'description', 'synopsis', 'summary' => 'description',
                'language' => 'language',
                default => null,
            };
            if ($field !== null && !isset($map[$field])) {
                $map[$field] = (int) $index;
            }
        }
        if (!isset($map['title'])) {
            throw new RuntimeException('The CSV needs a Title column. Download the sample template for supported columns.');
        }
        if (!isset($map['author'])) {
            throw new RuntimeException('The CSV needs an Author column as well as a Title column so similar books can be distinguished.');
        }

        $rows = [];
        $line = 1;
        while (($values = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $line++;
            if ($values === [null] || count(array_filter($values, static fn($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            if (count($rows) >= BULK_IMPORT_MAX_ROWS) {
                throw new RuntimeException('A batch can contain up to 500 books. Split larger files into separate batches.');
            }
            $value = static function (string $field) use ($map, $values): string {
                return isset($map[$field]) ? trim((string) ($values[$map[$field]] ?? '')) : '';
            };
            $missingFields = [];
            if ($value('title') === '') {
                $missingFields[] = 'the book title';
            }
            if ($value('author') === '') {
                $missingFields[] = 'the author';
            }
            if ($missingFields) {
                throw new RuntimeException('CSV row ' . $line . ' is incomplete. Add ' . implode(' and ', $missingFields) . ' so every book has a title and author, then upload the corrected CSV.');
            }
            $qtyRaw = $value('quantity');
            $quantity = $qtyRaw === '' ? $defaultQuantity : filter_var($qtyRaw, FILTER_VALIDATE_INT);
            if ($quantity === false || $quantity < 1 || $quantity > 9999999) {
                throw new RuntimeException('CSV row ' . $line . ' has an invalid quantity. Use a whole number from 1 to 9,999,999.');
            }
            $costRaw = preg_replace('/^[₱$\s]+/u', '', $value('cost')) ?? '';
            $priceRaw = preg_replace('/^[₱$\s]+/u', '', $value('price')) ?? '';
            foreach (['cost' => $costRaw, 'price' => $priceRaw] as $label => $amount) {
                if ($amount !== '' && (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $amount) || (float) $amount > 99999999)) {
                    throw new RuntimeException('CSV row ' . $line . ' has an invalid ' . $label . '.');
                }
            }
            $format = mb_strtolower($value('format'), 'UTF-8');
            $format = match ($format) {
                'hardcover', 'hardback', 'hardbound' => 'hardbound',
                'ebook', 'e-book', 'digital' => 'ebook',
                'paperback', 'softbound', 'softcover', 'soft cover' => 'paperback',
                default => '',
            };
            $cost = $costRaw === '' ? '' : number_format((float) $costRaw, 2, '.', '');
            if ($cost !== '') {
                $price = number_format((float) $cost * (1 + $defaultMarkup / 100), 2, '.', '');
                $priceSourceType = 'unit_cost';
                $priceSource = 'Unit cost + ' . number_format($defaultMarkup, 2) . '% markup';
            } elseif ($priceRaw !== '') {
                $price = number_format((float) $priceRaw, 2, '.', '');
                $priceSourceType = 'csv';
                $priceSource = 'Website price provided in CSV';
            } else {
                $price = number_format($fallbackPrice * (1 + $defaultMarkup / 100), 2, '.', '');
                $priceSourceType = 'fallback_baseline';
                $priceSource = '₱' . number_format($fallbackPrice, 2) . ' fallback price + ' . number_format($defaultMarkup, 2) . '% markup';
            }
            $rows[] = [
                'row' => $line,
                'title' => mb_substr($value('title'), 0, 255),
                'author' => mb_substr($value('author'), 0, 600),
                'author_description' => mb_substr($value('author_description'), 0, 10000),
                'isbn' => mb_substr(preg_replace('/[\s-]+/', '', $value('isbn')) ?? '', 0, 20),
                'publisher' => mb_substr($value('publisher'), 0, 150),
                'description' => $value('description'),
                'language' => $value('language'),
                'format' => $format,
                'quantity' => (int) $quantity,
                'unit_cost' => $cost,
                'markup_percent' => number_format($defaultMarkup, 2, '.', ''),
                'price' => $price,
                'fallback_price_basis' => number_format($fallbackPrice, 2, '.', ''),
                'csv_price' => $priceRaw === '' ? '' : number_format((float) $priceRaw, 2, '.', ''),
                'price_source_type' => $priceSourceType,
                'price_source' => $priceSource,
                'cover_url' => '',
                'cover_source_url' => '',
                'metadata_source_url' => '',
                'genre_ids' => [],
                'filipino_evidence_url' => '',
                'lookup_status' => 'not searched',
                'skip' => false,
            ];
        }
        if (!$rows) {
            throw new RuntimeException('The CSV has no book rows to prepare. Add at least one row with a title and author.');
        }
        return $rows;
    } finally {
        fclose($handle);
    }
}

/** Check submitted names against verified local identities while Prepare batch is running. */
function bulkImportCheckAuthors(PDO $pdo, array &$rows): void
{
    $authors = $pdo->query('SELECT author_id, full_name, verification_status FROM authors')->fetchAll();
    $aliases = [];
    try {
        foreach ($pdo->query('SELECT author_id, alias FROM author_aliases')->fetchAll() as $alias) {
            $aliases[(int) $alias['author_id']][] = (string) $alias['alias'];
        }
    } catch (Throwable $e) {
        // Older databases can still check canonical author names.
    }
    $nameIndex = [];
    foreach ($authors as $author) {
        foreach (array_merge([(string) $author['full_name']], $aliases[(int) $author['author_id']] ?? []) as $alternate) {
            $normalized = normalizeAuthorName($alternate);
            if ($normalized !== '') $nameIndex[$normalized][] = $author;
        }
    }
    foreach ($rows as &$row) {
        $checks = [];
        foreach (parseAuthorNames((string) ($row['author'] ?? '')) as $name) {
            $needle = normalizeAuthorName($name);
            $exact = $nameIndex[$needle] ?? [];
            $uniqueExact = [];
            foreach ($exact as $author) $uniqueExact[(int) $author['author_id']] = $author;
            $exact = array_values($uniqueExact);
            if (count($exact) === 1 && $exact[0]['verification_status'] === 'verified') {
                $checks[] = ['name' => $name, 'status' => 'verified', 'matched_name' => (string) $exact[0]['full_name']];
            } elseif (count($exact) === 1) {
                $checks[] = ['name' => $name, 'status' => (string) $exact[0]['verification_status'], 'matched_name' => (string) $exact[0]['full_name']];
            } elseif (count($exact) > 1) {
                $checks[] = ['name' => $name, 'status' => 'ambiguous'];
            } else {
                $possible = [];
                foreach ($nameIndex as $normalized => $records) {
                    if (authorNamesPossiblyMatch($needle, $normalized) === null) continue;
                    foreach ($records as $author) if ($author['verification_status'] === 'verified') $possible[(int) $author['author_id']] = $author;
                }
                if ($possible) {
                    $first = reset($possible);
                    $checks[] = ['name' => $name, 'status' => 'possible_match', 'matched_name' => (string) $first['full_name']];
                } else {
                    $checks[] = ['name' => $name, 'status' => 'needs_evidence'];
                }
            }
        }
        $row['filipino_author_checks'] = $checks;
        $row['author_check_complete'] = true;
    }
    unset($row);
}

$errors = [];
$batchId = (string) ($_GET['batch'] ?? '');
$batch = null;
$rows = [];
$success = (string) ($_SESSION['admin_flash'] ?? '');
unset($_SESSION['admin_flash']);

if (requestString($_GET, 'template') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="aklaaat-bulk-books-template.csv"');
    echo "title,author,author_description,isbn,format,quantity,cost,price,publisher,description,language\r\n";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $defaultQuantity = filter_var($_POST['default_quantity'] ?? '10', FILTER_VALIDATE_INT);
    $defaultMarkup = filter_var($_POST['default_markup'] ?? '25', FILTER_VALIDATE_FLOAT);
    $fallbackPrice = filter_var($_POST['fallback_price'] ?? '500', FILTER_VALIDATE_FLOAT);
    if ($defaultQuantity === false || $defaultQuantity < 1 || $defaultQuantity > 9999999) {
        $errors[] = 'Default quantity must be a whole number from 1 to 9,999,999.';
    }
    if ($defaultMarkup === false || $defaultMarkup < 0 || $defaultMarkup > 500) {
        $errors[] = 'Default markup must be between 0% and 500%.';
    }
    if ($fallbackPrice === false || $fallbackPrice < 0.01 || $fallbackPrice > 99999999) {
        $errors[] = 'Fallback price must be between ₱0.01 and ₱99,999,999.';
    }
    if (!$errors) {
        try {
            $rows = bulkImportRowsFromCsv($_FILES['csv_file'] ?? [], (int) $defaultQuantity, (float) $defaultMarkup, (float) $fallbackPrice);
            bulkImportCheckAuthors($pdo, $rows);
            $batchId = bin2hex(random_bytes(16));
            $insert = $pdo->prepare("INSERT INTO book_import_batches (batch_id, user_id, status, rows_json, row_count) VALUES (?, ?, 'review', ?, ?)");
            $insert->execute([$batchId, (int) $_SESSION['user_id'], json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), count($rows)]);
            auditLog($pdo, 'create', 'Created a bulk book import batch (' . count($rows) . ' rows, batch ' . substr($batchId, 0, 8) . ').', 'products', (int) $_SESSION['user_id'], 'book_import_batch', null, 'success', (string) $_SESSION['role']);
            header('Location: /admin/bulk_import.php?batch=' . rawurlencode($batchId));
            exit;
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            error_log('Bulk book batch creation failed: ' . $e->getMessage());
            $errors[] = 'Unable to prepare this batch. Check that the bulk import database migration has been applied.';
        }
    }
}

if ($batchId !== '') {
    if (!preg_match('/^[a-f0-9]{32}$/', $batchId)) {
        $errors[] = 'The import batch link is invalid.';
    } else {
        $stmt = $pdo->prepare('SELECT batch_id, user_id, status, rows_json, row_count, imported_count FROM book_import_batches WHERE batch_id = ?');
        $stmt->execute([$batchId]);
        $batch = $stmt->fetch();
        if (!$batch || (int) $batch['user_id'] !== (int) $_SESSION['user_id']) {
            $errors[] = 'This import batch was not found for your account.';
            $batch = null;
        } else {
            $rows = json_decode((string) $batch['rows_json'], true) ?: [];
        }
    }
}

$genres = $pdo->query("SELECT genre_id, genre_name FROM genres WHERE status = 'active' ORDER BY genre_name")->fetchAll();
include __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="/css/admin-bulk-import.css?v=<?= (int) filemtime(__DIR__ . '/../css/admin-bulk-import.css') ?>">
<section class="container bulk-import-page">
    <div class="bulk-hero">
        <div><p class="bulk-eyebrow">Product management</p><h1>Bulk add books</h1><p>Upload book titles and authors, review automatic matches, and import approved new books together.</p></div>
        <a class="btn btn-secondary" href="/admin/products.php">← Product management</a>
    </div>
    <?php if ($success !== ''): ?><div class="form-success" role="status"><p><?= bulkImportEscape($success) ?></p></div><?php endif; ?>

    <?php if ($batch): ?>
        <ol class="bulk-stepper" aria-label="Bulk import progress"><li class="is-complete"><span class="bulk-step-number">01</span><span>Upload</span></li><li class="<?= $batch['status'] === 'imported' ? 'is-complete' : 'is-current' ?>"><span class="bulk-step-number">02</span><span>Enrich &amp; review</span></li><li class="<?= $batch['status'] === 'imported' ? 'is-current' : '' ?>"><span class="bulk-step-number">03</span><span>Import</span></li></ol>
        <section class="bulk-controls card">
            <div><p class="bulk-eyebrow">Batch <?= bulkImportEscape(substr($batchId, 0, 8)) ?></p><h2><?= (int) $batch['row_count'] ?> book rows</h2><p><?= $batch['status'] === 'imported' ? 'This batch has already been applied; retrying it will not change stock again.' : 'Author checks run during preparation. Catalog and online metadata matching now starts automatically.' ?></p></div>
            <div class="bulk-actions">
                <button class="btn btn-primary bulk-button" type="button" id="import-ready" <?= $batch['status'] === 'imported' ? 'disabled' : '' ?>>Import Ready Books</button>
            </div>
        </section>
        <section class="bulk-scan-status bulk-toast is-info" id="bulk-scan-status" aria-label="Automatic matching progress" role="status" aria-live="polite" hidden>
            <span class="bulk-toast-icon" id="bulk-scan-icon" aria-hidden="true">i</span>
            <div class="bulk-toast-copy"><strong class="bulk-toast-title" id="bulk-scan-title">Checking catalog</strong><p class="bulk-toast-message bulk-message" id="bulk-message"></p><progress id="bulk-progress" value="0" max="1" aria-label="Books checked" hidden></progress></div>
        </section>
        <nav class="bulk-filters" id="bulk-filters" aria-label="Filter book rows"></nav>
        <p class="bulk-category-note" id="bulk-category-note" aria-live="polite"></p>
        <p class="bulk-inline-warning" id="bulk-duplicate-warning" role="alert" hidden></p>
        <div id="bulk-rows" class="bulk-rows"></div>
        <form id="bulk-import-form" method="post" action="/api/book_bulk_import.php" hidden>
            <input type="hidden" name="csrf_token" value="<?= bulkImportEscape(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="commit">
            <input type="hidden" name="batch_id" value="<?= bulkImportEscape($batchId) ?>">
            <input type="hidden" name="rows_json" id="rows-json">
        </form>
        <script>window.bulkImportState = <?= json_encode(['batchId' => $batchId, 'status' => $batch['status'], 'rows' => $rows, 'genres' => array_map(static fn($g) => ['id' => (int) $g['genre_id'], 'name' => $g['genre_name']], $genres), 'csrf' => generateCsrfToken()], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>;</script>
        <script src="/js/admin-bulk-import.js?v=<?= (int) filemtime(__DIR__ . '/../js/admin-bulk-import.js') ?>" defer></script>
    <?php else: ?>
        <ol class="bulk-stepper" aria-label="Bulk import progress"><li class="is-current"><span class="bulk-step-number">01</span><span>Upload</span></li><li><span class="bulk-step-number">02</span><span>Enrich &amp; review</span></li><li><span class="bulk-step-number">03</span><span>Import</span></li></ol>
        <div class="bulk-layout">
            <section class="card bulk-upload-card">
                <p class="bulk-eyebrow">Create a catalog batch</p><h2>Start with a simple list</h2>
                <p>Each CSV row must include the book title and author. ISBN, quantity, cost, format, and metadata can be filled or checked during review.</p>
                <form method="post" enctype="multipart/form-data" class="bulk-upload-form">
                    <input type="hidden" name="csrf_token" value="<?= bulkImportEscape(generateCsrfToken()) ?>">
                    <label class="bulk-file-drop" for="csv_file"><span class="bulk-file-icon" aria-hidden="true">↑</span><strong>Choose a CSV file</strong><small>Up to 500 rows · 2 MB max · UTF-8 or Excel CSV</small><input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required></label>
                    <div class="bulk-upload-errors bulk-toast" id="csv-preflight-notice" role="status" aria-live="polite" hidden><span class="bulk-toast-icon" aria-hidden="true">!</span><div class="bulk-toast-copy"><strong class="bulk-toast-title">CSV needs attention</strong><p class="bulk-toast-message"></p></div><button class="bulk-toast-close" type="button" aria-label="Dismiss notification">×</button></div>
                    <?php if ($errors): ?><div class="bulk-upload-errors bulk-toast is-error" id="csv-server-notice" role="alert" aria-live="assertive"><span class="bulk-toast-icon" aria-hidden="true">!</span><div class="bulk-toast-copy"><strong class="bulk-toast-title">CSV needs attention</strong><p class="bulk-toast-message"><?= bulkImportEscape(implode(' ', $errors)) ?></p></div><button class="bulk-toast-close" type="button" aria-label="Dismiss notification">×</button></div><?php endif; ?>
                    <div class="bulk-defaults">
                        <label><span class="bulk-default-label">Default quantity per book</span><input class="form-input" type="number" name="default_quantity" min="1" max="9999999" value="10" required><small>Used when a CSV row has no quantity.</small></label>
                        <label><span class="bulk-default-label">Markup (%)</span><input class="form-input" type="number" name="default_markup" min="0" max="500" step="0.01" value="25" required><small>Applied to cost, reference price, or fallback price.</small></label>
                        <label><span class="bulk-default-label">Fallback price basis (₱)</span><input class="form-input" type="number" name="fallback_price" min="0.01" max="99999999" step="0.01" value="500" required><small>Used when no cost, CSV price, or PHP online price is available.</small></label>
                    </div>
                    <div class="bulk-upload-actions">
                        <button class="btn btn-primary bulk-button" type="submit">
                            <span>Prepare Batch</span>
                            <svg class="bulk-button-icon bulk-button-icon-outline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6.878V6a2.25 2.25 0 0 1 2.25-2.25h7.5A2.25 2.25 0 0 1 18 6v.878m-12 0c.235-.083.487-.128.75-.128h10.5c.263 0 .515.045.75.128m-12 0A2.25 2.25 0 0 0 4.5 9v.878m13.5-3A2.25 2.25 0 0 1 19.5 9v.878m0 0a2.246 2.246 0 0 0-.75-.128H5.25c-.263 0-.515.045-.75.128m15 0A2.25 2.25 0 0 1 21 12v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6c0-.98.626-1.813 1.5-2.122" /></svg>
                            <svg class="bulk-button-icon bulk-button-icon-filled" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M5.566 4.657A4.505 4.505 0 0 1 6.75 4.5h10.5c.41 0 .806.055 1.183.157A3 3 0 0 0 15.75 3h-7.5a3 3 0 0 0-2.684 1.657ZM2.25 12a3 3 0 0 1 3-3h13.5a3 3 0 0 1 3 3v6a3 3 0 0 1-3 3H5.25a3 3 0 0 1-3-3v-6ZM5.25 7.5c-.41 0-.806.055-1.184.157A3 3 0 0 1 6.75 6h10.5a3 3 0 0 1 2.683 1.657A4.505 4.505 0 0 0 18.75 7.5H5.25Z" /></svg>
                        </button>
                        <a class="btn btn-secondary bulk-button" href="/admin/bulk_import.php?template=1">
                            <span>Download CSV Template</span>
                            <svg class="bulk-button-icon bulk-button-icon-outline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 8.25H7.5a2.25 2.25 0 0 0-2.25 2.25v9a2.25 2.25 0 0 0 2.25 2.25h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25H15M9 12l3 3m0 0 3-3m-3 3V2.25" /></svg>
                            <svg class="bulk-button-icon bulk-button-icon-filled" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 1.5a.75.75 0 0 1 .75.75V7.5h-1.5V2.25A.75.75 0 0 1 12 1.5ZM11.25 7.5v5.69l-1.72-1.72a.75.75 0 0 0-1.06 1.06l3 3a.75.75 0 0 0 1.06 0l3-3a.75.75 0 1 0-1.06-1.06l-1.72 1.72V7.5h3.75a3 3 0 0 1 3 3v9a3 3 0 0 1-3 3h-9a3 3 0 0 1-3-3v-9a3 3 0 0 1 3-3h3.75Z" /></svg>
                        </a>
                    </div>
                </form>
            </section>
            <div class="bulk-help-panels">
                <button class="bulk-help-card" type="button" data-dialog-open="workflow-dialog"><span class="bulk-help-icon" aria-hidden="true">↗</span><span class="bulk-help-copy"><small>How it works</small><strong>Automatic catalog workflow</strong><em>Prepare once, then review only the titles that need staff attention.</em><span class="bulk-help-overview">The system checks Filipino author records, detects books already in your catalog, and looks up matching details online. Confirm uncertain matches and required listing information before importing.</span><span class="bulk-help-action">Open the step-by-step workflow</span></span><span class="bulk-help-arrow" aria-hidden="true">→</span></button>
                <button class="bulk-help-card" type="button" data-dialog-open="columns-dialog"><span class="bulk-help-icon bulk-help-icon-alt" aria-hidden="true">▤</span><span class="bulk-help-copy"><small>CSV guide</small><strong>Columns we recognize</strong><em>Start with book title and author; add any purchasing details you already have.</em><span class="bulk-help-overview">Quantity, cost, format, ISBN, and book details are optional. The importer accepts common alternate column names and fills missing quantity from the default set to 10.</span><span class="bulk-help-action">View required and supported columns</span></span><span class="bulk-help-arrow" aria-hidden="true">→</span></button>
            </div>
        </div>
        <dialog class="bulk-dialog" id="workflow-dialog" aria-labelledby="workflow-dialog-title"><form method="dialog" class="bulk-dialog-close-wrap"><button class="bulk-dialog-close" aria-label="Close dialog">×</button></form><p class="bulk-eyebrow">From CSV to catalog</p><h2 id="workflow-dialog-title">Automatic catalog workflow</h2><ol class="bulk-dialog-steps"><li><strong>Upload and identify.</strong> Every row needs a book title and author. ISBN helps distinguish editions but is optional.</li><li><strong>Check Filipino author identities.</strong> During preparation, author names are matched with verified authors and known aliases. Possible name variations and unknown names are flagged for review; no match does not mean the author is foreign.</li><li><strong>Check for existing titles.</strong> The system compares ISBN, title, and author against the shop catalog. Existing books are shown as read-only and excluded from this new-book import.</li><li><strong>Find metadata automatically.</strong> Google Books is searched first, with Open Library as fallback. Strong matches fill available details; uncertain results wait for staff to choose the right book and edition.</li><li><strong>Complete and import.</strong> Only new books by verified Filipino authors with all required listing details can be imported. Existing catalog books and unknown authors remain outside the import until addressed.</li></ol><p class="bulk-note">Online metadata is a suggestion, not proof of Filipino identity, edition accuracy, or image-display permission. Review covers and bibliographic details before import.</p><div class="bulk-dialog-footer"><button class="btn btn-primary bulk-button" type="button" data-dialog-close>Got It</button></div></dialog>
        <dialog class="bulk-dialog" id="columns-dialog" aria-labelledby="columns-dialog-title"><form method="dialog" class="bulk-dialog-close-wrap"><button class="bulk-dialog-close" aria-label="Close dialog">×</button></form><p class="bulk-eyebrow">CSV reference</p><h2 id="columns-dialog-title">Columns we recognize</h2><p><strong class="bulk-required-copy">Required for every row:</strong> <code>title</code> and <code>author</code>.</p><p><strong>Optional:</strong> author description, ISBN, format, quantity, cost, price, publisher, description, and language. Common alternate headings include <code>Book Title</code>, <code>Author Name</code>, <code>Copies</code>, <code>Unit Cost</code>, and <code>Binding</code>.</p><p>Supplier spreadsheets use different layouts. The importer maps common alternate headings, ignores unrelated columns, and stops with a row-specific message if the title or author is missing.</p><div class="bulk-dialog-footer"><button class="btn btn-primary bulk-button" type="button" data-dialog-close>Got It</button></div></dialog>
    <?php endif; ?>
</section>
<script>
document.querySelectorAll('[data-dialog-open]').forEach(function (trigger) {
    trigger.addEventListener('click', function () { var dialog = document.getElementById(trigger.dataset.dialogOpen); if (dialog && dialog.showModal) dialog.showModal(); });
});
document.querySelectorAll('.bulk-dialog').forEach(function (dialog) {
    dialog.addEventListener('click', function (event) { if (event.target === dialog) dialog.close(); });
});
document.querySelectorAll('[data-dialog-close]').forEach(function (button) {
    button.addEventListener('click', function () { var dialog = button.closest('dialog'); if (dialog) dialog.close(); });
});
var bulkUploadForm = document.querySelector('.bulk-upload-form');
if (bulkUploadForm) {
    var csvInput = document.getElementById('csv_file');
    var csvNotice = document.getElementById('csv-preflight-notice');
    var prepareButton = bulkUploadForm.querySelector('button[type="submit"]');
    var noticeTimer = null;

    function hideCsvNotice(notice) {
        if (!notice) return;
        notice.classList.add('is-dismissing');
        window.setTimeout(function () { notice.hidden = true; notice.classList.remove('is-dismissing'); }, 250);
    }
    function showCsvNotice(text, type, notice) {
        notice = notice || csvNotice;
        if (!notice) return;
        if (notice === csvNotice && noticeTimer) window.clearTimeout(noticeTimer);
        var title = notice.querySelector('.bulk-toast-title');
        var message = notice.querySelector('.bulk-toast-message');
        var icon = notice.querySelector('.bulk-toast-icon');
        if (title) title.textContent = type === 'success' ? 'CSV is ready' : (type === 'info' ? 'Checking CSV' : 'CSV needs attention');
        if (message) message.textContent = text;
        if (icon) icon.textContent = type === 'success' ? '✓' : (type === 'info' ? 'i' : '!');
        notice.classList.remove('is-success', 'is-error', 'is-info', 'is-dismissing');
        notice.classList.add(type === 'success' ? 'is-success' : (type === 'info' ? 'is-info' : 'is-error'));
        notice.hidden = false;
        if (notice === csvNotice) noticeTimer = window.setTimeout(function () { hideCsvNotice(notice); }, 5000);
    }
    function parseCsvRecords(text) {
        text = text.replace(/^\uFEFF/, '');
        var records = [], record = [], cell = '', quoted = false;
        for (var i = 0; i < text.length; i++) {
            var char = text[i];
            if (quoted) {
                if (char === '"' && text[i + 1] === '"') { cell += '"'; i++; }
                else if (char === '"') quoted = false;
                else cell += char;
            } else if (char === '"' && cell === '') quoted = true;
            else if (char === ',') { record.push(cell); cell = ''; }
            else if (char === '\n' || char === '\r') {
                record.push(cell); records.push(record); record = []; cell = '';
                if (char === '\r' && text[i + 1] === '\n') i++;
            } else cell += char;
        }
        if (quoted) throw new Error('A quoted value is not closed. Check the CSV formatting and save the file again.');
        if (cell !== '' || record.length) { record.push(cell); records.push(record); }
        return records;
    }
    function inspectCsv(file) {
        return file.text().then(function (text) {
            var records = parseCsvRecords(text);
            if (!records.length) return { errors: ['The selected CSV is empty. Add a header row and at least one book.'] };
            var aliases = {
                title: ['title', 'booktitle', 'name'],
                author: ['author', 'authors', 'authorname', 'contributor']
            };
            var headers = records[0].map(function (header) { return String(header).trim().toLowerCase().replace(/[\s_-]+/g, ''); });
            var titleIndex = headers.findIndex(function (header) { return aliases.title.indexOf(header) !== -1; });
            var authorIndex = headers.findIndex(function (header) { return aliases.author.indexOf(header) !== -1; });
            var errors = [];
            if (titleIndex < 0) errors.push('Add a “Title” column to the CSV.');
            if (authorIndex < 0) errors.push('Add an “Author” column to the CSV.');
            if (errors.length) return { errors: errors };
            var books = records.slice(1), missing = { title: [], author: [], both: [] }, bookCount = 0;
            books.forEach(function (row, index) {
                if (!row.some(function (cell) { return String(cell).trim() !== ''; })) return;
                bookCount++;
                var title = String(row[titleIndex] || '').trim(), author = String(row[authorIndex] || '').trim();
                if (!title && !author) missing.both.push(index + 2);
                else if (!title) missing.title.push(index + 2);
                else if (!author) missing.author.push(index + 2);
            });
            if (!bookCount) errors.push('The CSV has no book rows. Add at least one title and author.');
            var missingCount = missing.title.length + missing.author.length + missing.both.length;
            if (missingCount) {
                function rowList(rows) {
                    var shown = rows.slice(0, 10).map(String);
                    var list = shown.length < 2 ? shown.join('') : shown.length === 2 ? shown.join(' and ') : shown.slice(0, -1).join(', ') + ', and ' + shown[shown.length - 1];
                    return 'CSV row' + (rows.length === 1 ? ' ' : 's ') + list + (rows.length > 10 ? ', and ' + (rows.length - 10) + ' more' : '');
                }
                var details = [];
                if (missing.both.length) details.push('Both the title and author are missing from ' + rowList(missing.both) + '.');
                if (missing.title.length) details.push('The book title is missing from ' + rowList(missing.title) + '.');
                if (missing.author.length) details.push('The author is missing from ' + rowList(missing.author) + '.');
                errors.push('Required details are missing. Each book needs a title and author. ' + details.join(' ') + ' Fill in the missing cells and upload again.');
            }
            if (bookCount > 500) errors.push('This CSV has ' + bookCount + ' rows. The limit is 500 books per batch.');
            return { errors: errors };
        });
    }
    function validateSelectedCsv() {
        var file = csvInput.files && csvInput.files[0];
        if (!file) return Promise.resolve({ errors: ['Choose a CSV file before preparing a batch.'] });
        if (file.size > 2097152) return Promise.resolve({ errors: ['File is over 2 MB. Choose a smaller CSV.'] });
        return inspectCsv(file).catch(function (error) { return { errors: [error.message || 'The CSV could not be checked. Verify its format and try again.'] }; });
    }
    function announceValidation(result) {
        if (result.errors.length) showCsvNotice(result.errors.join(' '), 'error');
        else showCsvNotice('All book rows have a title and author.', 'success');
    }

    csvInput.addEventListener('change', function () {
        if (!csvInput.files || !csvInput.files.length) return;
        showCsvNotice('Checking the CSV rows for book titles and authors…', 'info');
        validateSelectedCsv().then(announceValidation);
    });
    csvInput.addEventListener('invalid', function () { showCsvNotice('Choose a CSV file before preparing a batch.', 'error'); });
    bulkUploadForm.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!bulkUploadForm.reportValidity()) return;
        if (prepareButton) { prepareButton.disabled = true; prepareButton.querySelector('span').textContent = 'Checking CSV…'; }
        validateSelectedCsv().then(function (result) {
            if (result.errors.length) {
                announceValidation(result);
                if (prepareButton) { prepareButton.disabled = false; prepareButton.querySelector('span').textContent = 'Prepare Batch'; }
                return;
            }
            HTMLFormElement.prototype.submit.call(bulkUploadForm);
        });
    });
    var serverNotice = document.getElementById('csv-server-notice');
    if (serverNotice) window.setTimeout(function () { hideCsvNotice(serverNotice); }, 5000);
    document.querySelectorAll('.bulk-toast-close').forEach(function (button) {
        button.addEventListener('click', function () { hideCsvNotice(button.closest('.bulk-toast')); });
    });
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
