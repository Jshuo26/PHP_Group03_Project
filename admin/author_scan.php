<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/input.php';
require_once __DIR__ . '/../includes/author_verification.php';

function scanJson(int $code, array $payload): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)) {
    scanJson(403, ['ok' => false, 'message' => 'You do not have permission to do that.']);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    scanJson(405, ['ok' => false, 'message' => 'Use POST.']);
}
requireRole(['admin', 'staff']);
requireCsrfToken();

const SCAN_LABELS = [
    'verified'  => 'Verified author',
    'ambiguous' => 'Ambiguous: several verified authors share this name',
    'pending'   => 'Already awaiting review',
    'rejected'  => 'Previously rejected',
    'possible'  => 'Possible match: needs review',
    'unknown'   => 'Unknown author: needs review',
];

$raw = requestString($_POST, 'author');
if (strlen($raw) > 1000) {
    scanJson(422, ['ok' => false, 'message' => 'Author field is too long.']);
}

$submitted = array_filter(
    array_map('trim', preg_split('/[;\r\n]+/', $raw)),
    static fn ($part) => $part !== ''
);
$names = parseAuthorNames($raw);
$error = validateAuthorNames($names);
if ($error !== null) {
    scanJson(422, ['ok' => false, 'message' => $error]);
}

$results = [];
$targets = [];
$verifiedCount = 0;

try {
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
            $author = [
                'author_id' => (int) $a['author_id'],
                'full_name' => $a['full_name'],
                'verification_status' => $a['verification_status'],
            ];
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
            $author = [
                'author_id' => (int) $a['author_id'],
                'full_name' => $a['full_name'],
                'verification_status' => $a['verification_status'],
            ];
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
            'input'            => $name,
            'normalized'       => normalizeAuthorName($name),
            'status'           => $status,
            'label'            => SCAN_LABELS[$status],
            'will_be_verified' => $status === 'verified',
            'author'           => $author,
            'candidates'       => $candidates,
            'possible'         => $possible,
            'duplicate_of'     => $duplicateOf,
        ];
    }
} catch (Throwable $e) {
    error_log('Author scan failed: ' . $e->getMessage());
    scanJson(500, ['ok' => false, 'message' => 'Unable to scan authors right now.']);
}

$total = count($results);
scanJson(200, [
    'ok'                => true,
    'advisory'          => true,
    'authors'           => $results,
    'summary'           => [
        'total'        => $total,
        'verified'     => $verifiedCount,
        'needs_review' => $total - $verifiedCount,
    ],
    'duplicates_removed' => max(0, count($submitted) - $total),
    'can_auto_approve'  => $total > 0 && $verifiedCount === $total,
]);