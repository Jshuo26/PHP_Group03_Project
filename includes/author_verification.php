<?php

require_once __DIR__ . '/audit.php';

const AUTHOR_REVIEW_ROLES = ['admin', 'staff'];
const AUTHOR_REVOKE_ROLES = ['admin', 'staff'];
const AUTHOR_DELETE_ROLES = ['admin', 'staff'];
const MAX_AUTHORS_PER_BOOK = 5;
const AUTHOR_NAME_MAX      = 150;
const AUTHOR_NON_VERIFIED = ['pending_review', 'unverified', 'unconfirmed_self_declared'];

const AUTHOR_TRANSITIONS = [
    'verify'       => ['from' => ['pending_review', 'unverified', 'unconfirmed_self_declared'], 'to' => 'verified'],
    'reject'       => ['from' => ['pending_review', 'unverified', 'unconfirmed_self_declared'], 'to' => 'rejected'],
    'needs_review' => ['from' => ['pending_review', 'unverified', 'unconfirmed_self_declared'], 'to' => 'pending_review'],
    'revoke'       => ['from' => ['verified'],                                                  'to' => 'pending_review'],
    'reopen'       => ['from' => ['rejected'],                                                  'to' => 'pending_review'],
];

const AUTHOR_REVIEW_DECISION = [
    'verify' => 'verified', 'reject' => 'rejected', 'needs_review' => 'needs_review',
    'revoke' => 'revoked',  'reopen' => 'reopened',
];

const AUTHOR_AUDIT_ACTION = [
    'verify' => 'approve', 'reject' => 'reject', 'needs_review' => 'update',
    'revoke' => 'update',  'reopen' => 'update',
];

const AUTHOR_ACCENTS = [
    'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
    'ç' => 'c',
    'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
    'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
    'ñ' => 'n',
    'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
    'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
    'ý' => 'y', 'ÿ' => 'y',
];

const AUTHOR_HONORIFICS = ['dr', 'mr', 'mrs', 'ms', 'prof', 'atty', 'engr', 'fr', 'hon', 'sen', 'rep'];
function normalizeAuthorName(string $name): string
{
    $name = trim($name);

    if (substr_count($name, ',') === 1) {
        [$last, $first] = array_map('trim', explode(',', $name));
        if ($last !== '' && $first !== '' && !preg_match('/^(jr|sr|ii|iii|iv)\.?$/iu', $first)) {
            $name = $first . ' ' . $last;
        }
    }

    $name = mb_strtolower($name, 'UTF-8');
    $name = strtr($name, AUTHOR_ACCENTS);
    $name = preg_replace('/[.\'’`]+/u', '', $name);            // J.K. -> jk, O'Brien -> obrien
    $name = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $name);   // Dela-Cruz -> dela cruz
    $tokens = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);

    while (count($tokens) > 1 && in_array($tokens[0], AUTHOR_HONORIFICS, true)) {
        array_shift($tokens);
    }

    return implode(' ', $tokens);
}

function authorNamesPossiblyMatch(string $a, string $b): ?string
{
    if ($a === '' || $b === '' || $a === $b) {
        return null;
    }

    $ta = explode(' ', $a);
    $tb = explode(' ', $b);

    $sa = $ta;
    $sb = $tb;
    sort($sa);
    sort($sb);
    if ($sa === $sb) {
        return 'same words in a different order';
    }

    [$short, $long] = count($ta) <= count($tb) ? [$ta, $tb] : [$tb, $ta];
    if (count($short) >= 2) {
        $all = true;
        foreach ($short as $token) {
            $found = false;
            foreach ($long as $other) {
                if ($token === $other || (mb_strlen($token) === 1 && mb_substr($other, 0, 1) === $token)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $all = false;
                break;
            }
        }
        if ($all) {
            return count($short) === count($long) ? 'initial instead of a full name' : 'extra middle name or initial';
        }
    }

    $len = max(strlen($a), strlen($b));
    if ($len >= 8 && $len <= 200) {
        $limit = $len >= 14 ? 2 : 1;
        if (levenshtein($a, $b) <= $limit) {
            return 'possible spelling difference';
        }
    }

    return null;
}

function parseAuthorNames(string $raw): array
{
    $names = [];
    $seen = [];

    foreach (preg_split('/[;\r\n]+/', $raw) as $part) {
        $part = trim(preg_replace('/\s+/u', ' ', $part));
        if ($part === '') {
            continue;
        }
        $key = normalizeAuthorName($part);
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $names[] = $part;
    }

    return $names;
}

function validateAuthorNames(array $names): ?string
{
    if (!$names) {
        return 'At least one author name is required.';
    }
    if (count($names) > MAX_AUTHORS_PER_BOOK) {
        return 'A book can have at most ' . MAX_AUTHORS_PER_BOOK . ' authors.';
    }
    foreach ($names as $name) {
        if (mb_strlen($name) > AUTHOR_NAME_MAX) {
            return 'Each author name must not exceed ' . AUTHOR_NAME_MAX . ' characters.';
        }
    }

    return null;
}

function bookReviewLabel(string $status): string
{
    return [
        'approved'       => 'Approved',
        'pending_review' => 'Pending author verification',
        'rejected'       => 'Rejected',
    ][$status] ?? $status;
}

function findAuthorMatches(PDO $pdo, string $name): array
{
    $needle = normalizeAuthorName($name);
    $result = ['exact' => [], 'possible' => [], 'unverified' => []];

    if ($needle === '') {
        return $result;
    }

    $authors = $pdo->query('SELECT author_id, full_name, bio, verification_status FROM authors')->fetchAll();

    $aliasRows = $pdo->query(
        "SELECT al.author_id, al.alias
         FROM author_aliases al
         JOIN authors a ON a.author_id = al.author_id
         WHERE a.verification_status = 'verified'"
    )->fetchAll();

    $aliases = [];
    foreach ($aliasRows as $row) {
        $aliases[(int) $row['author_id']][] = $row['alias'];
    }

    foreach ($authors as $author) {
        $id = (int) $author['author_id'];
        $names = array_merge([$author['full_name']], $aliases[$id] ?? []);
        $verified = $author['verification_status'] === 'verified';

        $isExact = false;
        $reason = null;

        foreach ($names as $candidate) {
            $normalized = normalizeAuthorName($candidate);
            if ($normalized === $needle) {
                $isExact = true;
                break;
            }
            $reason = $reason ?? authorNamesPossiblyMatch($needle, $normalized);
        }

        if ($isExact) {
            if ($verified) {
                $result['exact'][] = $author;
            } elseif (in_array($author['verification_status'], AUTHOR_NON_VERIFIED, true)
                      || $author['verification_status'] === 'rejected') {
                $result['unverified'][] = $author;
            }
        } elseif ($verified && $reason !== null) {
            $result['possible'][] = ['author' => $author, 'reason' => $reason];
        }
    }

    return $result;
}

function resolveAuthor(PDO $pdo, string $name): array
{
    $matches = findAuthorMatches($pdo, $name);

    if (count($matches['exact']) === 1) {
        $author = $matches['exact'][0];
        return [
            'author_id' => (int) $author['author_id'],
            'name'      => $author['full_name'],
            'status'    => 'verified',
            'how'       => 'verified_exact',
        ];
    }

    $how = count($matches['exact']) > 1 ? 'ambiguous' : null;

    if ($matches['unverified']) {
        usort($matches['unverified'], static fn ($x, $y) => (int) $x['author_id'] <=> (int) $y['author_id']);
        $author = $matches['unverified'][0];
        $status = $author['verification_status'];

        if (in_array($status, ['unverified', 'unconfirmed_self_declared'], true)) {
            $pdo->prepare("UPDATE authors SET verification_status = 'pending_review' WHERE author_id = ?")
                ->execute([(int) $author['author_id']]);
            $status = 'pending_review';
        }

        return [
            'author_id' => (int) $author['author_id'],
            'name'      => $author['full_name'],
            'status'    => $status,
            'how'       => $how ?? 'existing_unverified',
        ];
    }

    $pdo->prepare("INSERT INTO authors (full_name, verification_status) VALUES (?, 'pending_review')")
        ->execute([$name]);

    return [
        'author_id' => (int) $pdo->lastInsertId(),
        'name'      => $name,
        'status'    => 'pending_review',
        'how'       => $how ?? 'new',
    ];
}

function refreshBookReviewStatus(PDO $pdo, int $bookId): string
{
    $stmt = $pdo->prepare(
        'SELECT a.verification_status
         FROM book_authors ba JOIN authors a ON a.author_id = ba.author_id
         WHERE ba.book_id = ?'
    );
    $stmt->execute([$bookId]);
    $statuses = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $genreStmt = $pdo->prepare('SELECT 1 FROM book_genres WHERE book_id = ? LIMIT 1');
    $genreStmt->execute([$bookId]);
    $hasGenre = (bool) $genreStmt->fetchColumn();

    if (!$statuses) {
        $new = 'pending_review';
    } elseif (in_array('rejected', $statuses, true)) {
        $new = 'rejected';
    } elseif ($hasGenre && count(array_unique($statuses)) === 1 && $statuses[0] === 'verified') {
        $new = 'approved';
    } else {
        $new = 'pending_review';
    }

    $pdo->prepare('UPDATE books SET review_status = ? WHERE book_id = ? AND review_status <> ?')
        ->execute([$new, $bookId, $new]);

    return $new;
}

function linkBookAuthors(PDO $pdo, int $bookId, array $names, int $userId): array
{
    $beforeStmt = $pdo->prepare('SELECT review_status FROM books WHERE book_id = ?');
    $beforeStmt->execute([$bookId]);
    $statusBefore = (string) $beforeStmt->fetchColumn();

    $prevStmt = $pdo->prepare(
        'SELECT a.author_id, a.full_name FROM book_authors ba
         JOIN authors a ON a.author_id = ba.author_id WHERE ba.book_id = ?'
    );
    $prevStmt->execute([$bookId]);
    $previous = [];
    foreach ($prevStmt->fetchAll() as $row) {
        $previous[(int) $row['author_id']] = $row['full_name'];
    }

    $resolved = [];
    $usedIds = [];

    foreach ($names as $name) {
        $needle = normalizeAuthorName($name);
        $author = null;
        foreach ($previous as $prevId => $prevName) {
            if (!isset($usedIds[$prevId]) && normalizeAuthorName($prevName) === $needle) {
                $statusStmt = $pdo->prepare('SELECT verification_status FROM authors WHERE author_id = ?');
                $statusStmt->execute([$prevId]);
                $author = ['author_id' => $prevId, 'name' => $prevName,
                           'status' => (string) $statusStmt->fetchColumn(), 'how' => 'kept'];
                break;
            }
        }

        $author = $author ?? resolveAuthor($pdo, $name);

        if (isset($usedIds[$author['author_id']])) {
            continue;
        }
        $usedIds[$author['author_id']] = true;
        $resolved[] = $author;
    }

    $pdo->prepare('DELETE FROM book_authors WHERE book_id = ?')->execute([$bookId]);
    $insert = $pdo->prepare('INSERT INTO book_authors (book_id, author_id) VALUES (?, ?)');

    foreach ($resolved as $author) {
        $insert->execute([$bookId, $author['author_id']]);

        if (isset($previous[$author['author_id']])) {
            continue;
        }

        if ($author['how'] === 'verified_exact') {
            $pdo->prepare(
                "INSERT INTO author_reviews (author_id, book_id, decision, notes, reviewer_id)
                 VALUES (?, ?, 'auto_verified', 'Name matched one verified author exactly.', ?)"
            )->execute([$author['author_id'], $bookId, $userId > 0 ? $userId : null]);

            auditLog($pdo, 'approve',
                'Book #' . $bookId . ': author "' . $author['name'] . '" (#' . $author['author_id']
                . ') reused automatically, already verified.', 'authors', $userId > 0 ? $userId : null);
        } else {
            auditLog($pdo, 'create',
                'Book #' . $bookId . ': author "' . $author['name'] . '" (#' . $author['author_id']
                . ') sent to review (' . $author['how'] . ').', 'authors', $userId > 0 ? $userId : null);
        }
    }

    $statusAfter = refreshBookReviewStatus($pdo, $bookId);

    if ($statusAfter !== $statusBefore) {
        auditLog($pdo, $statusAfter === 'approved' ? 'approve' : 'update',
            'Book #' . $bookId . ' review status: ' . $statusBefore . ' -> ' . $statusAfter . '.',
            'books', $userId > 0 ? $userId : null);
    }

    return ['review_status' => $statusAfter, 'authors' => $resolved];
}

function decideAuthor(
    PDO $pdo,
    int $authorId,
    string $decision,
    int $reviewerId,
    string $notes,
    array $evidence = [],
    ?int $bookId = null
): array {
    if (!isset(AUTHOR_TRANSITIONS[$decision])) {
        throw new RuntimeException('Unknown review decision.');
    }

    $notes = trim($notes);
    if (mb_strlen($notes) > 2000) {
        throw new RuntimeException('Notes must not exceed 2000 characters.');
    }

    $needsNotes = ['reject', 'needs_review', 'revoke'];
    if (in_array($decision, $needsNotes, true) && $notes === '') {
        throw new RuntimeException('Please write the reason or research notes for this decision.');
    }

    $cleanEvidence = [];
    foreach ($evidence as $url) {
        $url = trim((string) $url);
        if ($url === '') {
            continue;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (strlen($url) > 500 || filter_var($url, FILTER_VALIDATE_URL) === false
            || !in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Every source must be a valid http or https link (500 characters at most).');
        }
        $cleanEvidence[$url] = true;
    }
    $cleanEvidence = array_keys($cleanEvidence);
    if (count($cleanEvidence) > 10) {
        throw new RuntimeException('Add at most 10 sources.');
    }
    if ($decision === 'verify' && !$cleanEvidence) {
        throw new RuntimeException('Add at least one source link before verifying an author.');
    }

    $ownTransaction = !$pdo->inTransaction();

    try {
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }
        $roleStmt = $pdo->prepare("SELECT role FROM users WHERE user_id = ? AND account_status = 'active'");
        $roleStmt->execute([$reviewerId]);
        $role = (string) $roleStmt->fetchColumn();
        $allowedRoles = $decision === 'revoke' ? AUTHOR_REVOKE_ROLES : AUTHOR_REVIEW_ROLES;
        if (!in_array($role, $allowedRoles, true)) {
            throw new RuntimeException('You do not have permission to do that.');
        }

        $authorStmt = $pdo->prepare('SELECT full_name, verification_status FROM authors WHERE author_id = ? FOR UPDATE');
        $authorStmt->execute([$authorId]);
        $author = $authorStmt->fetch();
        if (!$author) {
            throw new RuntimeException('Author not found.');
        }

        $rule = AUTHOR_TRANSITIONS[$decision];
        if (!in_array($author['verification_status'], $rule['from'], true)) {
            throw new RuntimeException('That decision is not allowed for an author with status "'
                . $author['verification_status'] . '".');
        }

        if ($decision === 'verify') {
            $pdo->prepare(
                "UPDATE authors SET verification_status = 'verified', verification_method = 'manual_staff',
                        verified_by = ?, verified_at = NOW() WHERE author_id = ?"
            )->execute([$reviewerId, $authorId]);
        } else {
            $pdo->prepare(
                'UPDATE authors SET verification_status = ?, verified_by = NULL, verified_at = NULL,
                        verification_method = NULL WHERE author_id = ?'
            )->execute([$rule['to'], $authorId]);
        }

        $evidenceInsert = $pdo->prepare(
            'INSERT INTO author_evidence (author_id, source_url, added_by) VALUES (?, ?, ?)'
        );
        foreach ($cleanEvidence as $url) {
            $evidenceInsert->execute([$authorId, $url, $reviewerId]);
        }

        $pdo->prepare(
            'INSERT INTO author_reviews (author_id, book_id, decision, notes, reviewer_id) VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $authorId, $bookId, AUTHOR_REVIEW_DECISION[$decision], $notes !== '' ? $notes : null, $reviewerId,
        ]);

        $booksStmt = $pdo->prepare('SELECT book_id FROM book_authors WHERE author_id = ?');
        $booksStmt->execute([$authorId]);
        $affected = [];
        foreach ($booksStmt->fetchAll(PDO::FETCH_COLUMN) as $affectedId) {
            $affected[(int) $affectedId] = refreshBookReviewStatus($pdo, (int) $affectedId);
        }

        auditLog($pdo, AUTHOR_AUDIT_ACTION[$decision],
            'Author #' . $authorId . ' "' . $author['full_name'] . '": ' . $decision . ' ('
            . $author['verification_status'] . ' -> ' . $rule['to'] . '). Books affected: ' . count($affected) . '.',
            'authors', $reviewerId);

        if ($ownTransaction) {
            $pdo->commit();
        }

        return $affected;

    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

const AUTHOR_SCAN_LABELS = [
    'verified'  => 'Verified author',
    'ambiguous' => 'Ambiguous: several verified authors share this name',
    'pending'   => 'Already awaiting review',
    'rejected'  => 'Previously rejected',
    'possible'  => 'Possible match: needs review',
    'unknown'   => 'Unknown author: needs review',
];

function scanAuthorNames(PDO $pdo, array $names): array
{
    $results = [];
    $targets = [];
    $verifiedCount = 0;

    foreach ($names as $name) {
        $m = findAuthorMatches($pdo, $name);

        $possible = [];
        foreach ($m['possible'] as $p) {
            $possible[] = [
                'author_id' => (int) $p['author']['author_id'],
                'full_name' => $p['author']['full_name'],
                'reason'    => $p['reason'],
            ];
        }

        $author = null;
        $candidates = [];

        if (count($m['exact']) === 1) {
            $status = 'verified';
            $a = $m['exact'][0];
            $author = ['author_id' => (int) $a['author_id'], 'full_name' => $a['full_name'],
                       'verification_status' => $a['verification_status']];
        } elseif (count($m['exact']) > 1) {
            $status = 'ambiguous';
            foreach ($m['exact'] as $a) {
                $candidates[] = ['author_id' => (int) $a['author_id'], 'full_name' => $a['full_name']];
            }
        } elseif ($m['unverified']) {
            $list = $m['unverified'];
            usort($list, static fn ($x, $y) => (int) $x['author_id'] <=> (int) $y['author_id']);
            $a = $list[0];
            $status = $a['verification_status'] === 'rejected' ? 'rejected' : 'pending';
            $author = ['author_id' => (int) $a['author_id'], 'full_name' => $a['full_name'],
                       'verification_status' => $a['verification_status']];
        } elseif ($possible) {
            $status = 'possible';
        } else {
            $status = 'unknown';
        }

        $duplicateOf = null;
        if ($author !== null) {
            if (isset($targets[$author['author_id']])) {
                $duplicateOf = $targets[$author['author_id']];
            } else {
                $targets[$author['author_id']] = $name;
            }
        }
        if ($status === 'verified') {
            $verifiedCount++;
        }

        $results[] = [
            'input' => $name, 'status' => $status, 'label' => AUTHOR_SCAN_LABELS[$status],
            'will_be_verified' => $status === 'verified', 'author' => $author,
            'candidates' => $candidates, 'possible' => $possible, 'duplicate_of' => $duplicateOf,
        ];
    }

    $total = count($results);

    return [
        'ok'      => true,
        'authors' => $results,
        'summary' => ['total' => $total, 'verified' => $verifiedCount, 'needs_review' => $total - $verifiedCount],
        'can_auto_approve' => $total > 0 && $verifiedCount === $total,
    ];
}
