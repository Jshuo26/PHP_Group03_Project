<?php

function catalogVisibleBookSql(string $alias = 'b'): string
{
    $alias = preg_replace('/[^A-Za-z0-9_]/', '', $alias);

    return "($alias.status = 'active' AND $alias.review_status = 'approved')";
}

function bookIsPublic(PDO $pdo, int $bookId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM books b WHERE b.book_id = ? AND ' . catalogVisibleBookSql('b') . ' LIMIT 1'
    );
    $stmt->execute([$bookId]);

    return (bool) $stmt->fetchColumn();
}