<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/input.php';
require_once __DIR__ . '/../includes/author_verification.php';
require_once __DIR__ . '/../includes/upload_security.php';

requireRole(AUTHOR_REVIEW_ROLES);

function adminAuthorEsc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function adminAuthorPhoto(?string $path): string
{
    $filename = basename(str_replace('\\', '/', (string) $path));
    return $filename !== '' ? '/images/authors/' . rawurlencode($filename) : '';
}

function saveOpenLibraryAuthorPortrait(string $url): string
{
    if (!preg_match('~^https://covers\.openlibrary\.org/a/id/[1-9][0-9]*-M\.jpg\?default=false$~D', $url)) {
        throw new RuntimeException('Choose an Open Library author portrait from the search results.');
    }
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Accept: image/jpeg'],
    ]);
    $bytes = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $type = strtolower(trim(explode(';', (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE))[0]));
    curl_close($curl);
    if (!is_string($bytes) || $status < 200 || $status >= 300 || strlen($bytes) < 100
        || strlen($bytes) > 2 * 1024 * 1024 || !in_array($type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new RuntimeException('That author portrait could not be downloaded safely. Upload a permitted image instead.');
    }
    $info = @getimagesizefromstring($bytes);
    if (!is_array($info) || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)
        || (int) ($info[0] ?? 0) > 5000 || (int) ($info[1] ?? 0) > 5000
        || (int) ($info[0] ?? 0) * (int) ($info[1] ?? 0) > 12000000) {
        throw new RuntimeException('The selected author portrait is not a supported image.');
    }
    $image = @imagecreatefromstring($bytes);
    if ($image === false) throw new RuntimeException('The selected author portrait could not be processed.');
    ob_start();
    try { $ok = imagejpeg($image, null, 88); $normalized = ob_get_contents(); }
    finally { ob_end_clean(); imagedestroy($image); }
    if (!$ok || !is_string($normalized) || $normalized === '' || strlen($normalized) > 2 * 1024 * 1024) {
        throw new RuntimeException('The selected author portrait could not be processed.');
    }
    $directory = __DIR__ . '/../images/authors';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('The author image folder is not writable.');
    }
    $filename = bin2hex(random_bytes(16)) . '.jpg';
    if (file_put_contents($directory . '/' . $filename, $normalized, LOCK_EX) !== strlen($normalized)) {
        @unlink($directory . '/' . $filename);
        throw new RuntimeException('The author portrait could not be saved.');
    }
    @chmod($directory . '/' . $filename, 0644);
    return $filename;
}

$success = (string) ($_SESSION['admin_flash'] ?? '');
unset($_SESSION['admin_flash']);
$errors = [];
$editOld = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = requestString($_POST, 'action');
    $authorId = filter_input(INPUT_POST, 'author_id', FILTER_VALIDATE_INT) ?: 0;
    if ($authorId < 1) {
        $errors[] = 'Select a valid author.';
    } elseif ($action === 'save_profile') {
        $bio = trim(requestString($_POST, 'bio'));
        $photoUrl = trim(requestString($_POST, 'photo_url'));
        $imageConfirmed = requestString($_POST, 'image_confirmed') === '1';
        $editOld = ['id' => $authorId, 'bio' => $bio, 'photo_url' => $photoUrl, 'confirmed' => $imageConfirmed];
        $hasPortraitUpload = isset($_FILES['portrait'])
            && (int) ($_FILES['portrait']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if (mb_strlen($bio) > 10000) $errors[] = 'Author bio must not exceed 10,000 characters.';
        if ($photoUrl !== '' && strlen($photoUrl) > 500) $errors[] = 'The selected image source is invalid.';
        if (($hasPortraitUpload || $photoUrl !== '') && !$imageConfirmed) $errors[] = 'Confirm that the portrait is appropriate and permitted for display.';
        $authorStmt = $pdo->prepare('SELECT full_name, author_image_path FROM authors WHERE author_id = ?');
        $authorStmt->execute([$authorId]);
        $author = $authorStmt->fetch();
        if (!$author) $errors[] = 'Author not found.';
        if (!$errors) {
            try {
                $imagePath = (string) ($author['author_image_path'] ?? '');
                if (isset($_FILES['portrait']) && (int) ($_FILES['portrait']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $image = validatePublicImageUpload($_FILES['portrait'], 'author portrait', 2 * 1024 * 1024);
                    $saved = savePublicImageUpload($image, __DIR__ . '/../images/authors');
                    $imagePath = $saved['filename'];
                } elseif ($photoUrl !== '') {
                    $imagePath = saveOpenLibraryAuthorPortrait($photoUrl);
                }
                $pdo->prepare('UPDATE authors SET bio = ?, author_image_path = ? WHERE author_id = ?')
                    ->execute([$bio !== '' ? $bio : null, $imagePath !== '' ? $imagePath : null, $authorId]);
                $auditDescription = 'Author profile updated: ' . $author['full_name'] . ' (#' . $authorId . ').';
                if ($photoUrl !== '') $auditDescription .= ' Portrait source: ' . $photoUrl;
                auditLog($pdo, 'update', $auditDescription, 'authors', (int) $_SESSION['user_id'], 'author', $authorId);
                $_SESSION['admin_flash'] = 'Author profile saved.';
                header('Location: /admin/authors.php');
                exit;
            } catch (Throwable $e) {
                error_log('Author profile update failed: ' . $e->getMessage());
                $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to save this author profile.';
            }
        }
    } else {
        $errors[] = 'Unknown author action.';
    }
}

$search = mb_substr(trim((string) ($_GET['search'] ?? '')), 0, 100);
$letter = strtoupper(trim((string) ($_GET['letter'] ?? '')));
$clientVisibleOnly = (string) ($_GET['client_visible'] ?? '') === '1';
if (!preg_match('/^[A-Z]$/', $letter)) $letter = '';
$sql = "SELECT a.author_id, a.full_name, a.bio, a.author_image_path, a.verification_status,
               COUNT(DISTINCT ba.book_id) AS book_count,
               (a.verification_status = 'verified' AND EXISTS (SELECT 1 FROM book_authors client_ba
                       JOIN books client_b ON client_b.book_id = client_ba.book_id
                       JOIN book_genres client_bg ON client_bg.book_id = client_b.book_id
                       JOIN genres client_g ON client_g.genre_id = client_bg.genre_id AND client_g.status = 'active'
                       JOIN book_editions client_e ON client_e.book_id = client_b.book_id
                       WHERE client_ba.author_id = a.author_id
                         AND client_b.status = 'active' AND client_b.review_status = 'approved')) AS client_visible
        FROM authors a LEFT JOIN book_authors ba ON ba.author_id = a.author_id";
$where = [];
$params = [];
if ($search !== '') { $where[] = 'a.full_name LIKE ?'; $params[] = '%' . $search . '%'; }
if ($letter !== '') { $where[] = 'UPPER(LEFT(a.full_name, 1)) = ?'; $params[] = $letter; }
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' GROUP BY a.author_id, a.full_name, a.bio, a.author_image_path, a.verification_status ORDER BY a.full_name';
if ($clientVisibleOnly) {
    $sql = str_replace(' ORDER BY a.full_name', ' HAVING client_visible = 1 ORDER BY a.full_name', $sql);
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$authors = $stmt->fetchAll();
$totalAuthors = $clientVisibleOnly
    ? (int) $pdo->query("SELECT COUNT(*) FROM authors a WHERE EXISTS (
          SELECT 1 FROM book_authors client_ba
          JOIN books client_b ON client_b.book_id = client_ba.book_id
          JOIN book_genres client_bg ON client_bg.book_id = client_b.book_id
          JOIN genres client_g ON client_g.genre_id = client_bg.genre_id AND client_g.status = 'active'
          JOIN book_editions client_e ON client_e.book_id = client_b.book_id
          WHERE client_ba.author_id = a.author_id
            AND client_b.status = 'active' AND client_b.review_status = 'approved'
      ) AND a.verification_status = 'verified'")->fetchColumn()
    : (int) $pdo->query('SELECT COUNT(*) FROM authors')->fetchColumn();
$csrf = generateCsrfToken();
$authorDetails = [];
if ($authors) {
    $authorIds = array_map(static fn (array $author): int => (int) $author['author_id'], $authors);
    $placeholders = implode(',', array_fill(0, count($authorIds), '?'));
    $evidenceStmt = $pdo->prepare(
        "SELECT author_id, source_url, created_at FROM author_evidence
         WHERE author_id IN ($placeholders) ORDER BY created_at DESC, evidence_id DESC"
    );
    $evidenceStmt->execute($authorIds);
    foreach ($evidenceStmt->fetchAll() as $evidence) {
        $authorDetails[(int) $evidence['author_id']]['evidence'][] = [
            'url' => (string) $evidence['source_url'], 'date' => (string) $evidence['created_at'],
        ];
    }

    $booksStmt = $pdo->prepare(
        "SELECT ba.author_id, b.book_id, b.title, b.description, b.isbn, b.language, b.publisher,
                e.format, e.price, e.stock_quantity
         FROM book_authors ba
         JOIN books b ON b.book_id = ba.book_id
         JOIN book_editions e ON e.book_id = b.book_id
         WHERE ba.author_id IN ($placeholders)
           AND b.status = 'active' AND b.review_status = 'approved'
         ORDER BY ba.author_id, b.title, e.format"
    );
    $booksStmt->execute($authorIds);
    foreach ($booksStmt->fetchAll() as $bookRow) {
        $id = (int) $bookRow['author_id'];
        $bookId = (int) $bookRow['book_id'];
        if (!isset($authorDetails[$id]['books'][$bookId])) {
            $authorDetails[$id]['books'][$bookId] = [
                'title' => (string) $bookRow['title'],
                'description' => (string) ($bookRow['description'] ?? ''),
                'isbn' => (string) ($bookRow['isbn'] ?? ''),
                'language' => (string) ($bookRow['language'] ?? ''),
                'publisher' => (string) ($bookRow['publisher'] ?? ''),
                'editions' => [],
            ];
        }
        $authorDetails[$id]['books'][$bookId]['editions'][] = [
            'format' => (string) $bookRow['format'],
            'price' => (string) $bookRow['price'],
            'stock' => (int) $bookRow['stock_quantity'],
        ];
    }
}
foreach ($authors as $author) {
    $id = (int) $author['author_id'];
    $authorDetails[$id] = array_merge($authorDetails[$id] ?? [], [
        'id' => $id,
        'name' => (string) $author['full_name'],
        'bio' => (string) ($author['bio'] ?? ''),
        'photo' => adminAuthorPhoto($author['author_image_path'] ?? null),
        'status' => (string) $author['verification_status'],
        'books' => array_values($authorDetails[$id]['books'] ?? []),
        'evidence' => array_values($authorDetails[$id]['evidence'] ?? []),
    ]);
}

function adminAuthorInitials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    return $initials !== '' ? $initials : '?';
}

include __DIR__ . '/../includes/header.php';
?>
<main class="container admin-authors-page">
    <section class="admin-authors-hero">
        <div><span class="admin-authors-eyebrow">AUTHOR DIRECTORY</span><h1>Manage Authors</h1><p>View author profiles, update biographies, and keep portraits current.</p></div>
        <div class="admin-authors-hero-actions"><span class="admin-authors-total"><?= count($authors) ?> shown <small>of <?= $totalAuthors ?> <?= $clientVisibleOnly ? 'client visible authors' : 'authors' ?></small></span><a class="authors-client-filter<?= $clientVisibleOnly ? ' is-active' : '' ?>" href="/admin/authors.php?<?= adminAuthorEsc(http_build_query(array_filter(['search' => $search, 'letter' => $letter, 'client_visible' => $clientVisibleOnly ? null : '1'], static fn ($value) => $value !== null && $value !== ''))) ?>" aria-pressed="<?= $clientVisibleOnly ? 'true' : 'false' ?>"><?= $clientVisibleOnly ? 'Show All Authors' : 'Client Visible Authors' ?></a></div>
    </section>

    <?php if ($success !== ''): ?><div class="admin-author-flash success" role="status"><?= adminAuthorEsc($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $error): ?><div class="admin-author-flash error" role="alert"><?= adminAuthorEsc($error) ?></div><?php endforeach; ?>

    <section class="admin-authors-directory" aria-label="Author directory">
        <form class="admin-authors-search" method="get" action="/admin/authors.php" role="search">
            <?php if ($clientVisibleOnly): ?><input type="hidden" name="client_visible" value="1"><?php endif; ?>
            <label class="visually-hidden" for="author-search">Search authors</label><span aria-hidden="true">⌕</span>
            <input id="author-search" type="search" name="search" maxlength="100" placeholder="Search by author name..." value="<?= adminAuthorEsc($search) ?>">
            <button type="submit" class="btn btn-primary">Search</button>
            <?php if ($search !== '' || $letter !== ''): ?><a class="admin-author-clear" href="/admin/authors.php<?= $clientVisibleOnly ? '?client_visible=1' : '' ?>">Clear</a><?php endif; ?>
        </form>
        <nav class="admin-authors-alphabet" aria-label="Filter authors alphabetically">
            <?php $params = array_filter(['search' => $search, 'client_visible' => $clientVisibleOnly ? '1' : null]); ?>
            <a class="<?= $letter === '' ? 'active' : '' ?>" href="/admin/authors.php<?= $params ? '?' . http_build_query($params) : '' ?>">All</a>
            <?php foreach (range('A', 'Z') as $initial): $letterParams = $params + ['letter' => $initial]; ?>
                <a class="<?= $letter === $initial ? 'active' : '' ?>" href="/admin/authors.php?<?= adminAuthorEsc(http_build_query($letterParams)) ?>"<?= $letter === $initial ? ' aria-current="page"' : '' ?>><?= $initial ?></a>
            <?php endforeach; ?>
        </nav>

        <?php if (!$authors): ?><div class="admin-authors-empty"><h2>No authors found</h2><p>Try another name or clear the current filters.</p></div>
        <?php else: ?><div class="admin-authors-grid">
            <?php foreach ($authors as $author): $id = (int) $author['author_id']; $photo = adminAuthorPhoto($author['author_image_path'] ?? null); $status = (string) $author['verification_status']; ?>
                <article class="admin-author-card" data-client-visible="<?= !empty($author['client_visible']) ? '1' : '0' ?>">
                    <button type="button" class="admin-author-open" data-author-id="<?= $id ?>" aria-haspopup="dialog" aria-label="View details for <?= adminAuthorEsc($author['full_name']) ?>">
                        <span class="admin-author-photo">
                            <?php if ($photo !== ''): ?><img src="<?= adminAuthorEsc($photo) ?>" alt="" loading="lazy"><?php else: ?><span aria-hidden="true"><?= adminAuthorEsc(adminAuthorInitials((string) $author['full_name'])) ?></span><?php endif; ?>
                            <b><?= (int) $author['book_count'] ?> <?= (int) $author['book_count'] === 1 ? 'book' : 'books' ?></b>
                        </span>
                        <span class="admin-author-content">
                            <span class="admin-author-heading"><span class="admin-author-name"><?= adminAuthorEsc($author['full_name']) ?></span><span class="admin-author-status status-<?= adminAuthorEsc($status) ?>"><?= adminAuthorEsc(str_replace('_', ' ', $status)) ?></span></span>
                            <span class="admin-author-bio"><?= adminAuthorEsc(trim((string) ($author['bio'] ?? '')) !== '' ? $author['bio'] : 'No author biography has been added yet.') ?></span>
                            <span class="admin-author-view-hint">View profile and available books <span aria-hidden="true">→</span></span>
                        </span>
                    </button>
                    <div class="admin-author-actions"><button type="button" class="btn btn-secondary" data-edit-author data-id="<?= $id ?>" data-name="<?= adminAuthorEsc($author['full_name']) ?>" data-bio="<?= adminAuthorEsc($author['bio'] ?? '') ?>" data-photo="<?= adminAuthorEsc($photo) ?>">Edit Profile</button></div>
                </article>
            <?php endforeach; ?>
        </div><?php endif; ?>
    </section>
</main>

<dialog class="admin-author-dialog author-details-dialog" id="author-details-dialog" aria-labelledby="author-details-title">
    <div class="admin-author-dialog-header"><div><span class="admin-authors-eyebrow">AUTHOR PROFILE</span><h2 id="author-details-title">Author details</h2></div><button type="button" class="dialog-close" data-close-dialog aria-label="Close"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg></button></div>
    <div class="admin-author-dialog-body" id="author-details-content"></div>
    <footer class="admin-author-dialog-footer"><button type="button" class="btn btn-secondary" data-close-dialog>Close</button><button type="button" class="btn btn-primary" id="edit-from-details">Edit Profile</button></footer>
</dialog>

<dialog class="admin-author-dialog" id="author-edit-dialog" aria-labelledby="author-edit-title">
    <form method="post" action="/admin/authors.php" enctype="multipart/form-data" class="admin-author-dialog-form" id="author-edit-form">
        <input type="hidden" name="csrf_token" value="<?= adminAuthorEsc($csrf) ?>"><input type="hidden" name="action" value="save_profile"><input type="hidden" name="author_id" id="edit-author-id"><input type="hidden" name="photo_url" id="edit-photo-url">
        <header class="admin-author-dialog-header"><div><span class="admin-authors-eyebrow">AUTHOR PROFILE</span><h2 id="author-edit-title">Edit Author</h2></div><button type="button" class="dialog-close" data-close-dialog aria-label="Close"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg></button></header>
        <div class="admin-author-dialog-body">
            <label>Author Name<input id="edit-author-name" type="text" readonly></label>
            <div class="admin-author-edit-grid"><label>Biography<textarea name="bio" id="edit-author-bio" rows="6" maxlength="10000" placeholder="Add a concise, sourced author biography."></textarea><small>Leave blank to remove the biography.</small></label>
                <div class="admin-author-image-editor"><label>Portrait Preview</label><div class="admin-author-edit-preview" id="edit-photo-preview"><span>Portrait unavailable</span></div><label>Upload a permitted portrait<input type="file" name="portrait" id="edit-author-file" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG, or WebP, up to 2 MB. Uploading overrides the selected online image.</small></label></div>
            </div>
            <label class="admin-author-image-confirm"><input type="checkbox" name="image_confirmed" value="1"> I confirmed this portrait is appropriate and permitted for this project’s display.</label>
            <section class="admin-author-lookup"><div class="admin-author-lookup-heading"><div><h3>Find author profile</h3><p>Open Library may provide an author bio and portrait. Google Books is used to cross-check published works; it does not provide author portraits.</p></div><button type="button" class="btn btn-primary" id="search-author-metadata">Search</button></div><div class="admin-author-lookup-message" id="author-lookup-message" aria-live="polite"></div><div id="author-lookup-results"></div></section>
        </div>
        <footer class="admin-author-dialog-footer"><button type="button" class="btn btn-secondary" data-close-dialog>Cancel</button><button type="submit" class="btn btn-primary">Save Changes</button></footer>
    </form>
</dialog>

<script>window.adminAuthorLookupState = <?= json_encode(['csrf' => $csrf, 'edit' => $editOld, 'authors' => $authorDetails], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;</script>
<script src="/js/admin-authors.js" defer></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
