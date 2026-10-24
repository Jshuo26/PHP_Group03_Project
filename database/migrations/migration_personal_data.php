<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$modes = array_values(array_intersect($argv, ['--dry-run', '--apply', '--verify']));
if (count($modes) > 1) {
    fwrite(STDERR, "Choose only one of --dry-run, --apply, or --verify.\n");
    exit(2);
}
$mode = $modes[0] ?? '--dry-run';
if ($mode === '--apply') {
    if (!in_array('--confirm-data-transformation', $argv, true)) {
        fwrite(STDERR, "Backfill requires --confirm-data-transformation after a separately approved maintenance plan.\n");
        exit(2);
    }
    fwrite(STDOUT, "Type APPLY PERSONAL DATA ENCRYPTION to begin the approved backfill: ");
    if (trim((string) fgets(STDIN)) !== 'APPLY PERSONAL DATA ENCRYPTION') {
        fwrite(STDERR, "Confirmation did not match; no records changed.\n");
        exit(2);
    }
}

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/crypto.php';

$tables = [
    'users' => [
        'id' => 'user_id',
        'cursor' => 'user_id',
        'fields' => ['first_name', 'last_name', 'full_name', 'email', 'mobile_number'],
    ],
    'addresses' => [
        'id' => 'address_id',
        'cursor' => 'address_id',
        'fields' => ['label', 'street', 'city', 'province', 'zip_code'],
    ],
    'orders' => [
        'id' => 'order_number',
        'cursor' => 'order_id',
        'fields' => ['shipping_name', 'shipping_mobile', 'shipping_street', 'shipping_city', 'shipping_province', 'shipping_zip'],
    ],
];

function migrationContext(string $table, string $field, string|int $recordId): string
{
    return $table . '.' . $field . ':' . (string) $recordId;
}

function migrationRows(PDO $pdo, string $table, array $definition, int $afterId): PDOStatement
{
    $columns = array_merge([$definition['cursor']], $definition['cursor'] !== $definition['id'] ? [$definition['id']] : [], $definition['fields']);
    if ($table === 'users') {
        $columns[] = 'email_lookup_hash';
    }
    $columns = array_values(array_unique($columns));
    $sql = 'SELECT ' . implode(', ', $columns)
        . ' FROM ' . $table . ' WHERE ' . $definition['cursor'] . ' > ?'
        . ' ORDER BY ' . $definition['cursor'] . ' LIMIT 250';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$afterId]);
    return $stmt;
}

try {
    $pdo->query('SELECT email_lookup_hash FROM users LIMIT 0');
    appEncryptionKey();

    $counts = [];
    $seenEmailHashes = [];
    foreach ($tables as $table => $definition) {
        $counts[$table] = ['rows' => 0, 'fields_to_encrypt' => 0, 'email_indexes_to_create' => 0];
        $lastId = 0;

        do {
            $stmt = migrationRows($pdo, $table, $definition, $lastId);
            $batch = $stmt->fetchAll();
            $stmt->closeCursor();
            $pdo->beginTransaction();

            foreach ($batch as $row) {
                $lastId = (int) $row[$definition['cursor']];
                $recordId = $row[$definition['id']];
                $counts[$table]['rows']++;
                $updates = [];
                $values = [];

                foreach ($definition['fields'] as $field) {
                    if ($row[$field] === null) {
                        continue;
                    }
                    $context = migrationContext($table, $field, $recordId);
                    $plain = decryptPersonalField((string) $row[$field], $context);
                    if ($plain === null) {
                        throw new RuntimeException('A personal-data value failed authenticated decryption.');
                    }

                    $isEncrypted = str_starts_with((string) $row[$field], 'enc:v1:');
                    if (!$isEncrypted) {
                        $counts[$table]['fields_to_encrypt']++;
                        if ($mode === '--apply') {
                            $updates[] = $field . ' = ?';
                            $values[] = encryptPersonalField($plain, $context);
                        }
                    }

                    if ($table === 'users' && $field === 'email') {
                        $lookupHash = emailLookupHash($plain);
                        if (isset($seenEmailHashes[$lookupHash]) && $seenEmailHashes[$lookupHash] !== (int) $recordId) {
                            throw new RuntimeException('Duplicate normalized email addresses must be resolved before migration.');
                        }
                        $seenEmailHashes[$lookupHash] = (int) $recordId;
                        if (($row['email_lookup_hash'] ?? null) === null) {
                            $counts[$table]['email_indexes_to_create']++;
                        }
                        if ($mode === '--verify' && !hash_equals((string) ($row['email_lookup_hash'] ?? ''), $lookupHash)) {
                            throw new RuntimeException('Email lookup index verification failed.');
                        }
                        if (($row['email_lookup_hash'] ?? null) !== null
                            && !hash_equals((string) $row['email_lookup_hash'], $lookupHash)) {
                            throw new RuntimeException('An existing email lookup index does not match its account.');
                        }
                        if ($mode === '--apply' && ($row['email_lookup_hash'] ?? null) === null) {
                            $updates[] = 'email_lookup_hash = ?';
                            $values[] = $lookupHash;
                        }
                    }

                    if ($mode === '--verify' && !$isEncrypted) {
                        throw new RuntimeException('Plaintext personal data remains after migration.');
                    }
                }

                if ($mode === '--apply' && $updates) {
                    $values[] = $recordId;
                    $update = $pdo->prepare(
                        'UPDATE ' . $table . ' SET ' . implode(', ', $updates)
                        . ' WHERE ' . $definition['id'] . ' = ?'
                    );
                    $update->execute($values);
                }
            }

            if ($mode === '--apply') {
                $pdo->commit();
            } else {
                $pdo->rollBack();
            }
        } while (count($batch) === 250);
    }

    foreach ($counts as $table => $count) {
        echo $table . ': ' . $count['rows'] . ' rows checked; '
            . $count['fields_to_encrypt'] . ' fields needing encryption; '
            . $count['email_indexes_to_create'] . ' email lookup hashes to create' . PHP_EOL;
    }
    echo $mode === '--apply' ? "Backfill committed. Run --verify before reopening the app.\n"
        : ($mode === '--verify' ? "Verification passed.\n" : "Dry run only; no records changed.\n");
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Personal-data migration stopped. No personal values or key material were written to output. Review server logs and restore from the verified backup if needed.\n");
    exit(1);
}