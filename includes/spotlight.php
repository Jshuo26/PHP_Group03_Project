<?php

require_once __DIR__ . '/catalog_visibility.php';

const SPOTLIGHT_REST_DAYS = 7;

function pickSpotlightId(PDO $pdo, string $type, bool $verifiedOnly, bool $skipRecent): ?int
{
    $verified = $verifiedOnly ? "AND a.verification_status = 'verified'" : '';

    $having = $skipRecent
        ? 'HAVING MAX(ds.spotlight_date) IS NULL
              OR MAX(ds.spotlight_date) < DATE_SUB(CURDATE(), INTERVAL ' . SPOTLIGHT_REST_DAYS . ' DAY)'
        : '';

    $order = $skipRecent
        ? 'RAND()'
        : "COALESCE(MAX(ds.spotlight_date), '1970-01-01') ASC, RAND()";

    $activeGenre = "EXISTS (
        SELECT 1
        FROM book_genres bg
        INNER JOIN genres g ON g.genre_id = bg.genre_id AND g.status = 'active'
        WHERE bg.book_id = %s
    )";

    if ($type === 'author') {
        $sql = "SELECT a.author_id AS id
                FROM authors a
                INNER JOIN book_authors ba ON ba.author_id = a.author_id
                INNER JOIN books b ON b.book_id = ba.book_id
                INNER JOIN book_editions e
                        ON e.book_id = ba.book_id AND e.stock_quantity > 0
                LEFT JOIN daily_spotlight ds ON ds.author_id = a.author_id
                WHERE " . catalogVisibleBookSql('b') . " AND " . sprintf($activeGenre, 'ba.book_id') . " $verified
                GROUP BY a.author_id
                $having
                ORDER BY $order
                LIMIT 1";
    } else {
        $sql = "SELECT b.book_id AS id
                FROM books b
                INNER JOIN book_editions e
                        ON e.book_id = b.book_id AND e.stock_quantity > 0
                INNER JOIN book_authors ba ON ba.book_id = b.book_id
                INNER JOIN authors a ON a.author_id = ba.author_id
                LEFT JOIN daily_spotlight ds ON ds.book_id = b.book_id
                WHERE " . catalogVisibleBookSql('b') . " AND " . sprintf($activeGenre, 'b.book_id') . " $verified
                GROUP BY b.book_id
                $having
                ORDER BY $order
                LIMIT 1";
    }

    $id = $pdo->query($sql)->fetchColumn();

    return $id === false ? null : (int) $id;
}

function pickSpotlightWithFallback(PDO $pdo, string $type): ?int
{
    return pickSpotlightId($pdo, $type, true, true)     
        ?? pickSpotlightId($pdo, $type, false, true)    
        ?? pickSpotlightId($pdo, $type, true, false)   
        ?? pickSpotlightId($pdo, $type, false, false); 
}

function spotlightAuthorPhoto(?string $path): string
{
    $filename = basename(str_replace('\\', '/', trim((string) $path)));
    return $filename !== '' && $filename !== '.' ? '/images/authors/' . rawurlencode($filename) : '';
}

function fetchSpotlightRow(PDO $pdo): ?array
{
    $stmt = $pdo->query(
        "SELECT
            ds.spotlight_date,
            ds.book_id,
            ds.author_id,
            b.title,
            b.description,
            (SELECT GROUP_CONCAT(g.genre_name ORDER BY g.genre_name SEPARATOR '|')
               FROM book_genres bg
               INNER JOIN genres g ON g.genre_id = bg.genre_id AND g.status = 'active'
              WHERE bg.book_id = b.book_id) AS genre_names,
            (SELECT GROUP_CONCAT(a2.full_name ORDER BY a2.full_name SEPARATOR ', ')
               FROM book_authors ba2
               INNER JOIN authors a2 ON a2.author_id = ba2.author_id
              WHERE ba2.book_id = b.book_id) AS book_authors,
            (SELECT e.cover_image_path
               FROM book_editions e
              WHERE e.book_id = b.book_id
              ORDER BY e.edition_id
              LIMIT 1) AS cover,
            a.full_name AS author_name,
            a.bio AS author_bio,
            a.author_image_path AS author_image,
            (SELECT COUNT(DISTINCT ba3.book_id)
               FROM book_authors ba3
               INNER JOIN books b3 ON b3.book_id = ba3.book_id
              WHERE ba3.author_id = a.author_id
                AND " . catalogVisibleBookSql('b3') . ") AS author_book_count
         FROM daily_spotlight ds
         INNER JOIN books b ON b.book_id = ds.book_id
         INNER JOIN authors a ON a.author_id = ds.author_id
         WHERE ds.spotlight_date = CURDATE()
           AND " . catalogVisibleBookSql('b') . "
           AND a.verification_status = 'verified'
         LIMIT 1"
    );

    $row = $stmt->fetch();

    return $row ?: null;
}

function getTodaysSpotlight(PDO $pdo): ?array
{
    try {
        $row = fetchSpotlightRow($pdo);

        if ($row !== null) {
            return $row;
        }

        $authorId = pickSpotlightWithFallback($pdo, 'author');
        $bookId = pickSpotlightWithFallback($pdo, 'book');

        if ($authorId === null || $bookId === null) {
            return null;
        }
        
        $pdo->exec('DELETE FROM daily_spotlight WHERE spotlight_date = CURDATE()');
        $authorId = pickSpotlightWithFallback($pdo, 'author');

        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO daily_spotlight (spotlight_date, book_id, author_id)
             VALUES (CURDATE(), ?, ?)'
        );
        $stmt->execute([$bookId, $authorId]);

        return fetchSpotlightRow($pdo);
    } catch (PDOException $e) {
        error_log('Daily spotlight failed: ' . $e->getMessage());
        return null;
    }
}