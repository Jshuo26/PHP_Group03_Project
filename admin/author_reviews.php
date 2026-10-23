<?php

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/input.php';
require_once __DIR__ . '/../includes/author_verification.php';

requireRole(AUTHOR_REVIEW_ROLES);

const AR_TABS = ['all' => 'All', 'needs' => 'Needs Review', 'rejected' => 'Rejected'];
const AR_STATUS_LABELS = [
    'verified' => 'Verified', 'pending_review' => 'Pending review', 'unverified' => 'Unverified',
    'unconfirmed_self_declared' => 'Self-declared (unconfirmed)', 'rejected' => 'Rejected',
];
const AR_ACTION_LABELS = [
    'verify' => 'Verify', 'reject' => 'Reject', 'needs_review' => 'Mark needs review',
    'revoke' => 'Revoke verification', 'reopen' => 'Reopen',
];
const AR_DECISION_LABELS = [
    'auto_verified' => 'Auto-verified', 'verified' => 'Verified', 'rejected' => 'Rejected',
    'needs_review' => 'Needs review', 'revoked' => 'Revoked', 'reopened' => 'Reopened',
];

function arEsc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function arPlaceholders(array $ids): string
{
    return implode(',', array_fill(0, count($ids), '?'));
}

$errors = [];
$old = null;
$success = $_SESSION['admin_flash'] ?? '';
unset($_SESSION['admin_flash']);

$tab = (string) ($_GET['tab'] ?? 'needs');
if (!isset(AR_TABS[$tab])) {
    $tab = 'needs';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $postTab = requestString($_POST, 'tab');
    if (isset(AR_TABS[$postTab])) {
        $tab = $postTab;
    }
    $decision = requestString($_POST, 'decision');
    $authorId = filter_input(INPUT_POST, 'author_id', FILTER_VALIDATE_INT) ?: 0;
    $notes = requestString($_POST, 'notes');
    $evidenceRaw = requestString($_POST, 'evidence');
    $old = ['author_id' => $authorId, 'notes' => $notes, 'evidence' => $evidenceRaw];

    if ($authorId < 1) {
        $errors[] = 'Select a valid author.';
    } elseif (strlen($evidenceRaw) > 6000) {
        $errors[] = 'The source list is too long.';
    } else {
        try {
            $affected = decideAuthor(
                $pdo, $authorId, $decision, (int) $_SESSION['user_id'], $notes,
                preg_split('/\r\n|\r|\n/', $evidenceRaw) ?: [], null
            );
            $approved = count(array_filter($affected, static fn ($s) => $s === 'approved'));
            $_SESSION['admin_flash'] = 'Decision saved: ' . (AR_ACTION_LABELS[$decision] ?? $decision)
                . '. Books affected: ' . count($affected) . ' (approved now: ' . $approved . ').';
            header('Location: /admin/author_reviews.php?tab=' . urlencode($tab));
            exit;
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            error_log('Author decision failed: ' . $e->getMessage());
            $errors[] = 'Unable to save the decision.';
        }
    }
}

$counts = ['all' => 0, 'needs' => 0, 'rejected' => 0];
foreach ($pdo->query('SELECT verification_status, COUNT(*) AS total FROM authors GROUP BY verification_status')->fetchAll() as $row) {
    $counts['all'] += (int) $row['total'];
    if (in_array($row['verification_status'], AUTHOR_NON_VERIFIED, true)) {
        $counts['needs'] += (int) $row['total'];
    } elseif ($row['verification_status'] === 'rejected') {
        $counts['rejected'] += (int) $row['total'];
    }
}

$sql = 'SELECT author_id, full_name, verification_status, verification_method, verified_at, self_declared_note
        FROM authors';
$params = [];
if ($tab === 'needs') {
    $sql .= ' WHERE verification_status IN (' . arPlaceholders(AUTHOR_NON_VERIFIED) . ') ORDER BY author_id ASC';
    $params = AUTHOR_NON_VERIFIED;
} elseif ($tab === 'rejected') {
    $sql .= " WHERE verification_status = 'rejected' ORDER BY author_id DESC";
} else {
    $sql .= ' ORDER BY author_id DESC';
}
$sql .= ' LIMIT 100';
$listStmt = $pdo->prepare($sql);
$listStmt->execute($params);
$authors = $listStmt->fetchAll();

$booksBy = $evidenceBy = $historyBy = $suggestBy = [];
if ($authors) {
    $ids = array_map(static fn ($a) => (int) $a['author_id'], $authors);
    $in = arPlaceholders($ids);

    $stmt = $pdo->prepare(
        "SELECT ba.author_id, b.book_id, b.title, b.status, b.review_status
         FROM book_authors ba JOIN books b ON b.book_id = ba.book_id
         WHERE ba.author_id IN ($in) ORDER BY b.title"
    );
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $booksBy[(int) $row['author_id']][] = $row;
    }

    $stmt = $pdo->prepare(
        "SELECT author_id, source_url, created_at FROM author_evidence
         WHERE author_id IN ($in) ORDER BY created_at DESC, evidence_id DESC"
    );
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $evidenceBy[(int) $row['author_id']][] = $row;
    }

    $stmt = $pdo->prepare(
        "SELECT author_id, book_id, decision, notes, reviewer_id, created_at FROM author_reviews
         WHERE author_id IN ($in) ORDER BY created_at DESC, review_id DESC"
    );
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $historyBy[(int) $row['author_id']][] = $row;
    }

    foreach ($authors as $author) {
        if ($author['verification_status'] === 'verified') {
            continue;
        }
        $id = (int) $author['author_id'];
        $m = findAuthorMatches($pdo, $author['full_name']);
        $suggestBy[$id] = [
            'verified' => array_values(array_filter($m['exact'], static fn ($x) => (int) $x['author_id'] !== $id)),
            'unfinished' => array_values(array_filter($m['unverified'], static fn ($x) => (int) $x['author_id'] !== $id)),
            'possible' => $m['possible'],
        ];
    }
}

include __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="/css/admin-author-review.css">
<section class="container">
    <div class="card">
        <h1 class="card-title">Author Reviews</h1>
        <p class="card-text">Verify authors before their books appear to customers. A book is approved only when every author is verified.</p>
        <?php if ($errors): ?><div class="form-errors" role="alert"><?php foreach ($errors as $error): ?><p><?= arEsc($error) ?></p><?php endforeach; ?></div><?php endif; ?>
        <?php if ($success !== ''): ?><div class="form-success" role="status"><p><?= arEsc($success) ?></p></div><?php endif; ?>
        <nav class="ar-tabs" aria-label="Author review tabs">
            <?php foreach (AR_TABS as $key => $label): ?>
                <a class="ar-tab<?= $tab === $key ? ' is-active' : '' ?>" href="/admin/author_reviews.php?tab=<?= arEsc($key) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>><?= arEsc($label) ?> <span class="ar-count"><?= (int) $counts[$key] ?></span></a>
            <?php endforeach; ?>
        </nav>
    </div>

    <?php if (!$authors): ?><div class="card"><p class="card-text">No authors in this tab.</p></div><?php endif; ?>

    <?php foreach ($authors as $author):
        $id = (int) $author['author_id'];
        $status = (string) $author['verification_status'];
        $isOpen = $old !== null && (int) $old['author_id'] === $id;
        $allowed = [];
        foreach (AUTHOR_TRANSITIONS as $name => $rule) {
            if (!in_array($status, $rule['from'], true)) {
                continue;
            }
            if ($name === 'revoke' && !in_array($_SESSION['role'] ?? '', AUTHOR_REVOKE_ROLES, true)) {
                continue;
            }
            $allowed[] = $name;
        }
        $sug = $suggestBy[$id] ?? null;
    ?>
        <article class="card ar-item" id="author-<?= $id ?>">
            <header class="ar-head">
                <h3><?= arEsc($author['full_name']) ?> <small>#<?= $id ?></small></h3>
                <span class="ar-badge ar-<?= arEsc($status) ?>"><?= arEsc(AR_STATUS_LABELS[$status] ?? $status) ?></span>
            </header>
            <?php if ($status === 'verified' && $author['verified_at']): ?>
                <p class="ar-meta">Verified <?= arEsc($author['verified_at']) ?><?= $author['verification_method'] ? ' (' . arEsc($author['verification_method']) . ')' : '' ?></p>
            <?php endif; ?>
            <?php if (!empty($author['self_declared_note'])): ?><p class="ar-meta">Self-declared note: <?= arEsc($author['self_declared_note']) ?></p><?php endif; ?>

            <h4>Books</h4>
            <?php if (empty($booksBy[$id])): ?><p class="ar-meta">No books linked.</p><?php else: ?>
                <ul class="ar-list"><?php foreach ($booksBy[$id] as $b): ?>
                    <li><?= arEsc($b['title']) ?> <small>(book #<?= (int) $b['book_id'] ?> · sale: <?= arEsc($b['status']) ?> · review: <?= arEsc(bookReviewLabel((string) $b['review_status'])) ?>)</small></li>
                <?php endforeach; ?></ul>
            <?php endif; ?>

            <?php if ($sug !== null): ?>
                <h4>Matching suggestions</h4>
                <?php if (!$sug['verified'] && !$sug['unfinished'] && !$sug['possible']): ?>
                    <p class="ar-meta">No similar authors found. Research this name before verifying.</p>
                <?php else: ?><ul class="ar-list">
                    <?php foreach ($sug['verified'] as $x): ?><li class="ar-sug-strong">Same name as verified author <?= arEsc($x['full_name']) ?> (#<?= (int) $x['author_id'] ?>). Possible duplicate record.</li><?php endforeach; ?>
                    <?php foreach ($sug['unfinished'] as $x): ?><li>Another record with this name: #<?= (int) $x['author_id'] ?> (<?= arEsc(AR_STATUS_LABELS[$x['verification_status']] ?? $x['verification_status']) ?>)</li><?php endforeach; ?>
                    <?php foreach ($sug['possible'] as $p): ?><li>Possible match: <?= arEsc($p['author']['full_name']) ?> (#<?= (int) $p['author']['author_id'] ?>), <?= arEsc($p['reason']) ?></li><?php endforeach; ?>
                </ul><?php endif; ?>
            <?php endif; ?>

            <h4>Evidence</h4>
            <?php if (empty($evidenceBy[$id])): ?><p class="ar-meta">No sources recorded.</p><?php else: ?>
                <ul class="ar-list"><?php foreach ($evidenceBy[$id] as $ev):
                    $scheme = strtolower((string) parse_url($ev['source_url'], PHP_URL_SCHEME)); ?>
                    <li><?php if (in_array($scheme, ['http', 'https'], true)): ?><a href="<?= arEsc($ev['source_url']) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= arEsc($ev['source_url']) ?></a><?php else: ?><?= arEsc($ev['source_url']) ?><?php endif; ?> <small><?= arEsc($ev['created_at']) ?></small></li>
                <?php endforeach; ?></ul>
            <?php endif; ?>

            <h4>Review history</h4>
            <?php if (empty($historyBy[$id])): ?><p class="ar-meta">No decisions yet.</p><?php else: ?>
                <ul class="ar-list"><?php foreach (array_slice($historyBy[$id], 0, 10) as $h): ?>
                    <li><strong><?= arEsc(AR_DECISION_LABELS[$h['decision']] ?? $h['decision']) ?></strong> · <?= arEsc($h['created_at']) ?> · <?= $h['reviewer_id'] ? 'User #' . (int) $h['reviewer_id'] : 'System' ?><?= $h['book_id'] ? ' · book #' . (int) $h['book_id'] : '' ?><?php if ($h['notes'] !== null && $h['notes'] !== ''): ?><br><span class="ar-note"><?= nl2br(arEsc($h['notes'])) ?></span><?php endif; ?></li>
                <?php endforeach; ?></ul>
            <?php endif; ?>

            <?php if ($allowed): ?>
                <details class="ar-actions"<?= $isOpen ? ' open' : '' ?>>
                    <summary>Review this author</summary>
                    <form method="post" action="/admin/author_reviews.php?tab=<?= arEsc($tab) ?>">
                        <input type="hidden" name="csrf_token" value="<?= arEsc(generateCsrfToken()) ?>">
                        <input type="hidden" name="author_id" value="<?= $id ?>">
                        <input type="hidden" name="tab" value="<?= arEsc($tab) ?>">
                        <div class="form-group"><label for="notes-<?= $id ?>">Notes / research</label><textarea class="form-input" id="notes-<?= $id ?>" name="notes" rows="3" maxlength="2000"><?= $isOpen ? arEsc($old['notes']) : '' ?></textarea></div>
                        <div class="form-group"><label for="evidence-<?= $id ?>">Source links (one per line, up to 10)</label><textarea class="form-input" id="evidence-<?= $id ?>" name="evidence" rows="3" placeholder="https://..."><?= $isOpen ? arEsc($old['evidence']) : '' ?></textarea></div>
                        <small>Verify needs at least one source link. Reject, Needs review and Revoke need notes.</small>
                        <div class="ar-buttons">
                            <?php foreach ($allowed as $name): ?>
                                <button class="btn <?= $name === 'verify' ? 'btn-primary' : 'btn-secondary' ?>" type="submit" name="decision" value="<?= arEsc($name) ?>"<?= $name === 'revoke' ? ' onclick="return confirm(\'Revoke verification? Linked books will be hidden until re-verified.\')"' : '' ?>><?= arEsc(AR_ACTION_LABELS[$name]) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </form>
                </details>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</section>
<?php include __DIR__ . '/../includes/footer.php'; ?>