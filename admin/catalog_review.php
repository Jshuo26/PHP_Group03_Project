<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/catalog.php';
require_once __DIR__ . '/../includes/author_verification.php';

requireRole(['admin', 'staff']);
$records = [];
$batches = [];
$loadError = '';
try {
    $records = $pdo->query("SELECT b.book_id, e.edition_id, b.title, b.description, b.language, b.publisher, b.review_status,
            e.format, e.cover_image_path, e.stock_quantity, e.price,
            GROUP_CONCAT(DISTINCT a.full_name ORDER BY a.full_name SEPARATOR '; ') AS authors,
            GROUP_CONCAT(DISTINCT a.verification_status ORDER BY a.verification_status SEPARATOR ',') AS author_states,
            GROUP_CONCAT(DISTINCT g.genre_name ORDER BY g.genre_name SEPARATOR ', ') AS genres
        FROM books b JOIN book_editions e ON e.book_id = b.book_id
        LEFT JOIN book_authors ba ON ba.book_id = b.book_id
        LEFT JOIN authors a ON a.author_id = ba.author_id
        LEFT JOIN book_genres bg ON bg.book_id = b.book_id
        LEFT JOIN genres g ON g.genre_id = bg.genre_id
        GROUP BY b.book_id, e.edition_id, b.title, b.description, b.language, b.publisher, b.review_status,
                 e.format, e.cover_image_path, e.stock_quantity, e.price
        ORDER BY b.updated_at DESC, b.title")->fetchAll();
    $batchRows = $pdo->query("SELECT batch_id, status, rows_json, created_at FROM book_import_batches ORDER BY created_at DESC LIMIT 100")->fetchAll();
    foreach ($batchRows as $batch) {
        $rows = json_decode((string) $batch['rows_json'], true);
        if (!is_array($rows)) continue;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $checks = is_array($row['filipino_author_checks'] ?? null) ? $row['filipino_author_checks'] : [];
            $unknown = !empty($row['server_excluded_unknown_author']) || !empty($row['author_unknown']);
            foreach ($checks as $check) {
                if (!is_array($check) || !in_array((string) ($check['status'] ?? ''), ['needs_evidence', 'unknown', 'possible_match', 'pending', 'ambiguous'], true)) continue;
                $unknown = true;
            }
            $skipped = !empty($row['skip']);
            $description = trim((string) ($row['description'] ?? ''));
            $language = trim((string) ($row['language'] ?? ''));
            $genres = is_array($row['genre_ids'] ?? null) ? $row['genre_ids'] : [];
            $genrePlaceholder = (string) ($row['genre_assignment_source'] ?? '') === 'fallback';
            $placeholder = $description === '' || $description === 'A full synopsis will be added soon.'
                || $language === '' || $language === 'Other Languages' || !$genres || $genrePlaceholder;
            if (!$skipped && !$unknown && !$placeholder) continue;
            $reasons = [];
            if ($skipped) $reasons[] = 'Skipped / excluded from import';
            if ($unknown) $reasons[] = 'Author needs identity review';
            if ($description === '' || $description === 'A full synopsis will be added soon.') $reasons[] = 'Synopsis placeholder';
            if ($language === '' || $language === 'Other Languages') $reasons[] = 'Language placeholder';
            if (!$genres || $genrePlaceholder) $reasons[] = $genrePlaceholder ? 'Genre placeholder' : 'Genre needs review';
            $batches[] = [
                'batch_id' => (string) $batch['batch_id'], 'status' => (string) $batch['status'],
                'title' => (string) ($row['title'] ?? 'Untitled book'), 'author' => (string) ($row['author'] ?? 'Author unknown'),
                'reasons' => $reasons, 'row' => (int) ($row['row'] ?? 0),
            ];
        }
    }
} catch (Throwable $e) {
    error_log('Catalog review queue failed: ' . $e->getMessage());
    $loadError = 'The review queue could not load. Confirm that bulk import migrations are installed.';
}

$needs = [];
foreach ($records as $record) {
    $reasons = [];
    if ((string) $record['review_status'] !== 'approved') $reasons[] = 'Book not approved';
    if (str_contains((string) ($record['author_states'] ?? ''), 'pending_review') || str_contains((string) ($record['author_states'] ?? ''), 'unverified') || str_contains((string) ($record['author_states'] ?? ''), 'unconfirmed_self_declared')) $reasons[] = 'Author identity needs review';
    if (trim((string) ($record['description'] ?? '')) === '' || trim((string) $record['description']) === 'A full synopsis will be added soon.') $reasons[] = 'Synopsis placeholder';
    if ((string) $record['language'] === 'Other Languages') $reasons[] = 'Language placeholder';
    if (str_contains((string) ($record['genres'] ?? ''), 'Other / General')) $reasons[] = 'Genre placeholder';
    if (trim((string) ($record['cover_image_path'] ?? '')) === '') $reasons[] = 'No cover image';
    if ($reasons) $needs[] = ['record' => $record, 'reasons' => $reasons];
}
include __DIR__ . '/../includes/header.php';
?>
<main class="admin-products container">
    <section class="products-hero"><div><p class="products-eyebrow">FOLLOW-UP QUEUE</p><h1>Books that need attention</h1><p>Return to unverified authors, skipped rows, and catalog details that still use placeholders.</p></div><div class="products-actions"><a class="btn btn-secondary" href="/admin/products.php">← Product Management</a></div></section>
    <?php if ($loadError !== ''): ?><div class="form-errors" role="alert"><p><?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?></p></div><?php endif; ?>
    <section class="catalog-section"><div class="section-heading"><div><p class="products-eyebrow">SAVED CATALOG</p><h2>Incomplete or unverified listings</h2></div><span class="catalog-total"><?= count($needs) ?> records</span></div>
        <?php if (!$needs): ?><div class="card"><p class="card-text">No published catalog records need follow-up.</p></div><?php else: ?><div class="review-record-grid">
            <?php foreach ($needs as $item): $r = $item['record']; ?><article class="review-record"><div class="review-record-cover"><?php if (!empty($r['cover_image_path']) && catalogCover((string) $r['cover_image_path']) !== ''): ?><img src="<?= htmlspecialchars(catalogCover((string) $r['cover_image_path']), ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy"><?php else: ?><?= catalogCoverPlaceholder((string) $r['title'], 'admin-catalog-placeholder') ?><?php endif; ?></div><div class="review-record-info"><span class="catalog-format"><?= htmlspecialchars(ucfirst((string) $r['format']), ENT_QUOTES, 'UTF-8') ?></span><h3><?= htmlspecialchars((string) $r['title'], ENT_QUOTES, 'UTF-8') ?></h3><p><?= htmlspecialchars((string) ($r['authors'] ?: 'Author unknown'), ENT_QUOTES, 'UTF-8') ?></p><div class="review-reasons"><?php foreach ($item['reasons'] as $reason): ?><span><?= htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') ?></span><?php endforeach; ?></div><a class="btn btn-primary" href="/admin/products.php?edit=<?= (int) $r['edition_id'] ?>">Review &amp; Edit</a></div></article><?php endforeach; ?>
        </div><?php endif; ?>
    </section>
    <section class="catalog-section batch-followup"><div class="section-heading"><div><p class="products-eyebrow">BULK IMPORT HISTORY</p><h2>Skipped &amp; flagged rows</h2></div><a class="text-link" href="/admin/bulk_import.php">Open Bulk Catalog →</a></div>
        <?php if (!$batches): ?><div class="card"><p class="card-text">No skipped or flagged rows were found in recent bulk batches.</p></div><?php else: ?><div class="batch-followup-list">
            <?php foreach ($batches as $row): $prefill = '/admin/products.php?' . http_build_query(['title' => $row['title'], 'author' => $row['author']]); ?><article><div><strong><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></strong><span><?= htmlspecialchars($row['author'], ENT_QUOTES, 'UTF-8') ?></span><small>Batch <?= htmlspecialchars(substr($row['batch_id'], 0, 8), ENT_QUOTES, 'UTF-8') ?><?= $row['row'] > 0 ? ' · CSV row ' . $row['row'] : '' ?> · <?= htmlspecialchars(ucfirst($row['status']), ENT_QUOTES, 'UTF-8') ?></small></div><div class="review-reasons"><?php foreach ($row['reasons'] as $reason): ?><span><?= htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') ?></span><?php endforeach; ?></div><a class="btn btn-secondary" href="<?= htmlspecialchars($prefill, ENT_QUOTES, 'UTF-8') ?>">Re-enter for review</a></article><?php endforeach; ?>
        </div><?php endif; ?>
    </section>
</main>
<style>
.review-record-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1rem}.review-record{display:grid;grid-template-columns:118px minmax(0,1fr);gap:.9rem;padding:.9rem;border:1px solid #ead7c2;border-radius:14px;background:#fff}.review-record-cover{display:flex;align-items:center;justify-content:center;min-height:170px;border-radius:9px;background:#faf4ed}.review-record-cover img{max-width:100%;width:auto;height:165px;object-fit:contain;box-shadow:0 4px 12px #25140524}.review-record-info h3{margin:.5rem 0 .2rem;color:#3e2516;font-size:1rem}.review-record-info>p{margin:.1rem 0 .55rem;color:#755b47;font-size:.85rem}.review-reasons{display:flex;flex-wrap:wrap;gap:.3rem;margin:.45rem 0 .7rem}.review-reasons span{padding:.2rem .42rem;border-radius:999px;color:#805312;background:#fff2cf;font-size:.68rem;font-weight:700}.review-record-info .btn{display:inline-flex;text-decoration:none}.batch-followup-list{display:grid;gap:.65rem}.batch-followup-list article{display:grid;grid-template-columns:minmax(170px,1fr) minmax(220px,1.5fr) auto;align-items:center;gap:.8rem;padding:.8rem 1rem;border:1px solid #ead7c2;border-radius:12px;background:#fff}.batch-followup-list article>div:first-child{display:grid;gap:.14rem}.batch-followup-list article>div:first-child span,.batch-followup-list small{color:#765e4c;font-size:.82rem}.batch-followup-list small{font-size:.72rem}.batch-followup-list .review-reasons{margin:0}.batch-followup-list .btn{white-space:nowrap;text-decoration:none}@media(max-width:1000px){.review-record-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:680px){.review-record-grid{grid-template-columns:1fr}.review-record{grid-template-columns:90px minmax(0,1fr)}.review-record-cover{min-height:130px}.review-record-cover img{height:125px}.batch-followup-list article{grid-template-columns:1fr}.batch-followup-list .review-reasons{margin:.2rem 0}}
</style>
<?php include __DIR__ . '/../includes/footer.php'; ?>
