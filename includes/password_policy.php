<?php

const PASSWORD_LENGTH_FLOOR = 8;
const PASSWORD_MAX_LENGTH   = 72;
const PASSWORD_COUNT_MAX    = 10;

const PASSWORD_POLICY_DEFAULTS = [
    'password_min_length'  => 12,
    'uppercase_min_length' => 1,
    'lowercase_min_length' => 1,
    'number_min_length'    => 1,
    'special_min_length'   => 1,
];

function getPasswordPolicy(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $policy = PASSWORD_POLICY_DEFAULTS;

    try {
        $row = $pdo->query(
            'SELECT password_min_length, uppercase_min_length, lowercase_min_length,
                    number_min_length, special_min_length
             FROM security_policy
             WHERE policy_id = 1
             LIMIT 1'
        )->fetch();

        if ($row) {
            foreach ($policy as $field => $default) {
                $policy[$field] = (int) $row[$field];
            }
        }
    } catch (PDOException $e) {
        error_log('Password policy lookup failed: ' . $e->getMessage());
    }

    $policy['password_min_length'] = max(
        PASSWORD_LENGTH_FLOOR,
        min(PASSWORD_MAX_LENGTH, $policy['password_min_length'])
    );
    foreach (['uppercase_min_length', 'lowercase_min_length', 'number_min_length', 'special_min_length'] as $field) {
        $policy[$field] = max(0, min(PASSWORD_COUNT_MAX, $policy[$field]));
    }

    $cache = $policy;
    return $cache;
}

function validatePasswordAgainstPolicy(string $password, array $policy): array
{
    $errors = [];

    if (strlen($password) < $policy['password_min_length']) {
        $errors[] = 'Password must be at least ' . $policy['password_min_length'] . ' characters long.';
    }

    if (strlen($password) > PASSWORD_MAX_LENGTH) {
        $errors[] = 'Password must not be longer than ' . PASSWORD_MAX_LENGTH . ' characters.';
    }

    $checks = [
        ['uppercase_min_length', '/[A-Z]/',        'uppercase letter'],
        ['lowercase_min_length', '/[a-z]/',        'lowercase letter'],
        ['number_min_length',    '/[0-9]/',        'number'],
        ['special_min_length',   '/[^a-zA-Z0-9]/', 'special character'],
    ];

    foreach ($checks as [$field, $pattern, $noun]) {
        $required = $policy[$field];
        if ($required > 0 && preg_match_all($pattern, $password) < $required) {
            $errors[] = 'Password must contain at least ' . $required . ' ' . $noun . ($required === 1 ? '' : 's') . '.';
        }
    }

    return $errors;
}

function passwordRules(array $policy): array
{
    $rules = [[
        'key'   => 'length',
        'min'   => $policy['password_min_length'],
        'label' => 'At least ' . $policy['password_min_length'] . ' characters',
    ]];

    $types = [
        ['uppercase', 'uppercase_min_length', 'uppercase letter'],
        ['lowercase', 'lowercase_min_length', 'lowercase letter'],
        ['number',    'number_min_length',    'number'],
        ['special',   'special_min_length',   'special character'],
    ];

    foreach ($types as [$key, $field, $noun]) {
        $min = $policy[$field];
        if ($min > 0) {
            $rules[] = [
                'key'   => $key,
                'min'   => $min,
                'label' => 'At least ' . $min . ' ' . $noun . ($min === 1 ? '' : 's'),
            ];
        }
    }

    return $rules;
}

function passwordRulesSentence(array $policy): string
{
    $labels = array_map(static function ($rule) {
        return strtolower($rule['label']);
    }, passwordRules($policy));

    return 'Your password needs: ' . implode(', ', $labels)
        . '. Maximum ' . PASSWORD_MAX_LENGTH . ' characters.';
}