<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/input.php';
require_once __DIR__ . '/../includes/audit.php';

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

function bulkImportRowsFromCsv(array $file, int $defaultQuantity, float $defaultMarkup): array
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
                'author', 'authors', 'contributor' => 'author',
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
            $price = $cost !== '' ? number_format((float) $cost * (1 + $defaultMarkup / 100), 2, '.', '')
                : ($priceRaw !== '' ? number_format((float) $priceRaw, 2, '.', '') : '');
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
            throw new RuntimeException('The CSV does not contain any book rows.');
        }
        return $rows;
    } finally {
        fclose($handle);
    }
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
    $defaultQuantity = filter_var($_POST['default_quantity'] ?? '1', FILTER_VALIDATE_INT);
    $defaultMarkup = filter_var($_POST['default_markup'] ?? '25', FILTER_VALIDATE_FLOAT);
    if ($defaultQuantity === false || $defaultQuantity < 1 || $defaultQuantity > 9999999) {
        $errors[] = 'Default quantity must be a whole number from 1 to 9,999,999.';
    }
    if ($defaultMarkup === false || $defaultMarkup < 0 || $defaultMarkup > 500) {
        $errors[] = 'Default markup must be between 0% and 500%.';
    }
    if (!$errors) {
        try {
            $rows = bulkImportRowsFromCsv($_FILES['csv_file'] ?? [], (int) $defaultQuantity, (float) $defaultMarkup);
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
        <div><p class="bulk-eyebrow">Product management</p><h1>Bulk add books</h1><p>Start with the titles you know. Look up metadata in one batch, resolve exceptions, then add approved books together.</p></div>
        <a class="btn btn-secondary" href="/admin/products.php">← Product management</a>
    </div>
    <?php if ($errors): ?><div class="form-errors" role="alert"><?php foreach ($errors as $error): ?><p><?= bulkImportEscape($error) ?></p><?php endforeach; ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="form-success" role="status"><p><?= bulkImportEscape($success) ?></p></div><?php endif; ?>

    <?php if ($batch): ?>
        <div class="bulk-stepbar"><span class="is-current">1 · Upload</span><span class="is-current">2 · Enrich &amp; review</span><span class="<?= $batch['status'] === 'imported' ? 'is-current' : '' ?>">3 · Import</span></div>
        <section class="bulk-controls card">
            <div><p class="bulk-eyebrow">Batch <?= bulkImportEscape(substr($batchId, 0, 8)) ?></p><h2><?= (int) $batch['row_count'] ?> book rows</h2><p><?= $batch['status'] === 'imported' ? 'This batch has already been applied; retrying it will not change stock again.' : 'Search the catalog, review matches, and import ready rows.' ?></p></div>
            <div class="bulk-actions">
                <button class="btn btn-secondary" type="button" id="lookup-all" <?= $batch['status'] === 'imported' ? 'disabled' : '' ?>>Find metadata for all</button>
                <button class="btn btn-primary" type="button" id="import-ready" <?= $batch['status'] === 'imported' ? 'disabled' : '' ?>>Import ready books</button>
            </div>
        </section>
        <div class="bulk-summary" id="bulk-summary" aria-live="polite"><span>Preparing rows…</span></div>
        <div id="bulk-rows" class="bulk-rows"></div>
        <div id="bulk-message" class="bulk-message" role="status" aria-live="polite"></div>
        <form id="bulk-import-form" method="post" action="/api/book_bulk_import.php" hidden>
            <input type="hidden" name="csrf_token" value="<?= bulkImportEscape(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="commit">
            <input type="hidden" name="batch_id" value="<?= bulkImportEscape($batchId) ?>">
            <input type="hidden" name="rows_json" id="rows-json">
        </form>
        <script>window.bulkImportState = <?= json_encode(['batchId' => $batchId, 'status' => $batch['status'], 'rows' => $rows, 'genres' => array_map(static fn($g) => ['id' => (int) $g['genre_id'], 'name' => $g['genre_name']], $genres), 'csrf' => generateCsrfToken()], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>;</script>
        <script src="/js/admin-bulk-import.js?v=<?= (int) filemtime(__DIR__ . '/../js/admin-bulk-import.js') ?>" defer></script>
    <?php else: ?>
        <div class="bulk-stepbar"><span class="is-current">1 · Upload</span><span>2 · Enrich &amp; review</span><span>3 · Import</span></div>
        <div class="bulk-layout">
            <section class="card bulk-upload-card">
                <p class="bulk-eyebrow">Create a catalog batch</p><h2>Start with a simple list</h2>
                <p>Only the title is required in the CSV. Add author, ISBN, quantity, or cost when you have them. The system searches for matching metadata after upload.</p>
                <form method="post" enctype="multipart/form-data" class="bulk-upload-form">
                    <input type="hidden" name="csrf_token" value="<?= bulkImportEscape(generateCsrfToken()) ?>">
                    <label class="bulk-file-drop" for="csv_file"><span class="bulk-file-icon" aria-hidden="true">↑</span><strong>Choose a CSV file</strong><small>Up to 500 rows · 2 MB max · UTF-8 or Excel CSV</small><input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required></label>
                    <div class="bulk-defaults">
                        <label>Default quantity per book<input class="form-input" type="number" name="default_quantity" min="1" max="9999999" value="1" required><small>Used only when the CSV has no quantity value.</small></label>
                        <label>Default markup (%)<input class="form-input" type="number" name="default_markup" min="0" max="500" step="0.01" value="25" required><small>Applied to unit cost, or to a Google Books retail reference when it is listed in PHP.</small></label>
                    </div>
                    <button class="btn btn-primary" type="submit">Prepare batch</button>
                    <a class="btn btn-secondary" href="/admin/bulk_import.php?template=1">Download CSV template</a>
                </form>
            </section>
            <aside class="card bulk-guide">
                <p class="bulk-eyebrow">What happens next</p><h2>Review only what needs attention</h2>
                <ol><li>Searches Google Books by ISBN or title and author.</li><li>Prefills descriptions, categories, edition details, and available covers.</li><li>Checks for existing books and duplicate ISBNs.</li><li>Holds missing, conflicting, or uncertain data for review.</li><li>Adds approved quantities to existing stock or creates new products.</li></ol>
                <p class="bulk-note">API metadata and covers are suggestions. Review the match and confirm you may display the cover. New authors remain hidden until approved in Author Reviews.</p>
            </aside>
        </div>
        <section class="card bulk-field-help"><h2>CSV columns we recognize</h2><p><strong>Required:</strong> title. <strong>Optional:</strong> author, author description, ISBN, format, quantity, cost, price, publisher, description, and language. Column names such as <code>Book Title</code>, <code>Copies</code>, <code>Unit Cost</code>, and <code>Binding</code> are also accepted.</p><p>Spreadsheet exports from suppliers vary. Upload a copy with a title column; the importer maps common alternative headings and ignores unrelated columns. You can review and correct each row before anything enters the catalog.</p></section>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/../includes/footer.php'; ?>
