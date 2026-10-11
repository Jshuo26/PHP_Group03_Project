<?php

function pickSpotlightId(PDO $pdo, string $type, bool $verifiedOnly): ?int
{
    $verified = $verifiedOnly ? "AND a.verification_status = 'verified'" : '';

    if ($type === 'author') {

        $sql = "SELECT a.author_id AS id
                FROM authors a
                INNER JOIN book_authors ba ON ba.author_id = a.author_id
                INNER JOIN book_editions e
                        ON e.book_id = ba.book_id AND e.stock_quantity > 0
                LEFT JOIN daily_spotlight ds ON ds.author_id = a.author_id
                WHERE 1 = 1 $verified
                GROUP BY a.author_id
                ORDER BY COALESCE(MAX(ds.spotlight_date), '1970-01-01') ASC,
                         RAND()
                LIMIT 1";

    } else {

        $sql = "SELECT b.book_id AS id
                FROM books b
                INNER JOIN book_editions e
                        ON e.book_id = b.book_id AND e.stock_quantity > 0
                INNER JOIN book_authors ba ON ba.book_id = b.book_id
                INNER JOIN authors a ON a.author_id = ba.author_id
                LEFT JOIN daily_spotlight ds ON ds.book_id = b.book_id
                WHERE 1 = 1 $verified
                GROUP BY b.book_id
                ORDER BY COALESCE(MAX(ds.spotlight_date), '1970-01-01') ASC,
                         RAND()
                LIMIT 1";
    }

    $id = $pdo->query($sql)->fetchColumn();

    return $id === false ? null : (int) $id;
}


function fetchSpotlightRow(PDO $pdo): ?array
{
    $stmt = $pdo->query(
        "SELECT
            ds.book_id,
            ds.author_id,
            b.title,
            b.description,
            g.genre_name,
            (SELECT GROUP_CONCAT(a2.full_name SEPARATOR ', ')
               FROM book_authors ba2
               INNER JOIN authors a2 ON a2.author_id = ba2.author_id
              WHERE ba2.book_id = b.book_id) AS book_authors,
            (SELECT e.cover_image_path
               FROM book_editions e
              WHERE e.book_id = b.book_id
              ORDER BY e.edition_id
              LIMIT 1) AS cover,
            a.full_name AS author_name,
            a.bio       AS author_bio,
            (SELECT b2.title
               FROM books b2
               INNER JOIN book_authors ba3 ON ba3.book_id = b2.book_id
              WHERE ba3.author_id = a.author_id
              ORDER BY b2.created_at DESC
              LIMIT 1) AS author_title
         FROM daily_spotlight ds
         INNER JOIN books   b ON b.book_id   = ds.book_id
         INNER JOIN genres  g ON g.genre_id  = b.genre_id
         INNER JOIN authors a ON a.author_id = ds.author_id
         WHERE ds.spotlight_date = CURDATE()
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

        $authorId = pickSpotlightId($pdo, 'author', true)
                 ?? pickSpotlightId($pdo, 'author', false);

        $bookId   = pickSpotlightId($pdo, 'book', true)
                 ?? pickSpotlightId($pdo, 'book', false);

        if ($authorId === null || $bookId === null) {
            return null;
        }

        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO daily_spotlight
             (spotlight_date, book_id, author_id)
             VALUES (CURDATE(), ?, ?)'
        );

        $stmt->execute([$bookId, $authorId]);

        return fetchSpotlightRow($pdo);

    } catch (PDOException $e) {

        error_log('Daily spotlight failed: ' . $e->getMessage());

        return null;
    }
}