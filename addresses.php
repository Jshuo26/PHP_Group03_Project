<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/input.php';
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/audit.php';

requireRole(['customer']);

$userId = (int) $_SESSION['user_id'];
$errors = [];
$success = (string) ($_SESSION['address_flash_success'] ?? '');
unset($_SESSION['address_flash_success']);
$editingId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;
$formAddress = ['label' => '', 'recipient_name' => '', 'mobile_number' => '', 'street' => '', 'city' => '', 'province' => '', 'zip_code' => ''];

if ($editingId > 0) {
    $editStmt = $pdo->prepare('SELECT address_id, label, recipient_name, mobile_number, street, city, province, zip_code, is_default FROM addresses WHERE address_id = ? AND user_id = ? LIMIT 1');
    $editStmt->execute([$editingId, $userId]);
    $formAddress = $editStmt->fetch() ?: $formAddress;
    if ($formAddress['address_id'] ?? false) {
        $formAddress = decryptAddressPersonalFields($formAddress);
    } else {
        $editingId = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = requestString($_POST, 'action');
    $addressId = filter_input(INPUT_POST, 'address_id', FILTER_VALIDATE_INT) ?: 0;

    if ($action === 'save_address') {
        $formAddress = [
            'label' => trim(requestString($_POST, 'label')),
            'recipient_name' => trim(requestString($_POST, 'recipient_name')),
            'mobile_number' => preg_replace('/[\s-]/', '', trim(requestString($_POST, 'mobile_number'))),
            'street' => trim(requestString($_POST, 'street')),
            'city' => trim(requestString($_POST, 'city')),
            'province' => trim(requestString($_POST, 'province')),
            'zip_code' => trim(requestString($_POST, 'zip_code')),
        ];
        $limits = ['label' => 40, 'recipient_name' => 150, 'mobile_number' => 20, 'street' => 255, 'city' => 100, 'province' => 100, 'zip_code' => 10];
        foreach ($limits as $field => $limit) {
            if (mb_strlen($formAddress[$field]) > $limit) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' must be ' . $limit . ' characters or fewer.';
            }
        }
        foreach (['recipient_name' => 'Recipient name', 'mobile_number' => 'Mobile number', 'street' => 'Street address', 'city' => 'City', 'province' => 'Province', 'zip_code' => 'ZIP code'] as $field => $label) {
            if ($formAddress[$field] === '') {
                $errors[] = $label . ' is required.';
            }
        }
        if ($formAddress['zip_code'] !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9 -]{1,9}$/', $formAddress['zip_code'])) {
            $errors[] = 'Enter a valid ZIP or postal code.';
        }
        if ($formAddress['mobile_number'] !== '' && !preg_match('/^\+?[0-9]{8,15}$/', $formAddress['mobile_number'])) {
            $errors[] = 'Enter a valid mobile number.';
        }

        $isDefault = requestString($_POST, 'is_default') === '1';
        if (!$errors) {
            try {
                $pdo->beginTransaction();
                $isUpdate = $addressId > 0;
                if ($addressId > 0) {
                    $ownerStmt = $pdo->prepare('SELECT address_id FROM addresses WHERE address_id = ? AND user_id = ? FOR UPDATE');
                    $ownerStmt->execute([$addressId, $userId]);
                    if (!$ownerStmt->fetchColumn()) {
                        throw new RuntimeException('That saved address could not be found. Refresh and try again.');
                    }
                    $targetId = $addressId;
                } else {
                    $insert = $pdo->prepare('INSERT INTO addresses (user_id, label, recipient_name, mobile_number, street, city, province, zip_code, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)');
                    $insert->execute([$userId, '', '', '', '', '', '', '']);
                    $targetId = (int) $pdo->lastInsertId();
                    $addressId = $targetId;
                }

                $encrypted = [];
                foreach ($formAddress as $field => $value) {
                    $encrypted[$field] = encryptPersonalField($value, personalFieldContext('addresses', $field, $targetId));
                }
                $update = $pdo->prepare('UPDATE addresses SET label = ?, recipient_name = ?, mobile_number = ?, street = ?, city = ?, province = ?, zip_code = ? WHERE address_id = ? AND user_id = ?');
                $update->execute([$encrypted['label'], $encrypted['recipient_name'], $encrypted['mobile_number'], $encrypted['street'], $encrypted['city'], $encrypted['province'], $encrypted['zip_code'], $targetId, $userId]);

                if ($isDefault) {
                    $pdo->prepare('UPDATE addresses SET is_default = 0 WHERE user_id = ?')->execute([$userId]);
                    $pdo->prepare('UPDATE addresses SET is_default = 1 WHERE address_id = ? AND user_id = ?')->execute([$targetId, $userId]);
                } elseif ($isUpdate) {
                    $pdo->prepare('UPDATE addresses SET is_default = 0 WHERE address_id = ? AND user_id = ?')->execute([$targetId, $userId]);
                }

                auditLog($pdo, $isUpdate ? 'update' : 'create', 'Customer saved a shipping address.', 'account', $userId, 'address', $targetId, 'success', 'customer');
                $pdo->commit();
                $_SESSION['address_flash_success'] = 'Address saved. It is ready to use at checkout.';
                header('Location: /addresses.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Customer address save failed: ' . $e->getMessage());
                $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not save this address. Please try again.';
                $editingId = $addressId;
            }
        } else {
            $editingId = $addressId;
        }
    } elseif ($action === 'set_default' && $addressId > 0) {
        $pdo->beginTransaction();
        try {
            $owned = $pdo->prepare('SELECT address_id FROM addresses WHERE address_id = ? AND user_id = ? FOR UPDATE');
            $owned->execute([$addressId, $userId]);
            if (!$owned->fetchColumn()) {
                throw new RuntimeException('That saved address could not be found. Refresh and try again.');
            }
            $pdo->prepare('UPDATE addresses SET is_default = 0 WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('UPDATE addresses SET is_default = 1 WHERE address_id = ? AND user_id = ?')->execute([$addressId, $userId]);
            auditLog($pdo, 'update', 'Customer changed their default shipping address.', 'account', $userId, 'address', $addressId, 'success', 'customer');
            $pdo->commit();
            $_SESSION['address_flash_success'] = 'Default checkout address updated.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Customer default address update failed: ' . $e->getMessage());
            $_SESSION['address_flash_error'] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not update the default address.';
        }
        header('Location: /addresses.php');
        exit;
    } elseif ($action === 'delete_address' && $addressId > 0) {
        $pdo->beginTransaction();
        try {
            $defaultCheck = $pdo->prepare('SELECT is_default FROM addresses WHERE address_id = ? AND user_id = ? FOR UPDATE');
            $defaultCheck->execute([$addressId, $userId]);
            $wasDefault = (bool) $defaultCheck->fetchColumn();
            $delete = $pdo->prepare('DELETE FROM addresses WHERE address_id = ? AND user_id = ?');
            $delete->execute([$addressId, $userId]);
            if ($delete->rowCount() > 0 && $wasDefault) {
                $remaining = $pdo->prepare('SELECT address_id FROM addresses WHERE user_id = ? ORDER BY address_id DESC LIMIT 1');
                $remaining->execute([$userId]);
                $replacementId = $remaining->fetchColumn();
                if ($replacementId) {
                    $pdo->prepare('UPDATE addresses SET is_default = 0 WHERE user_id = ?')->execute([$userId]);
                    $pdo->prepare('UPDATE addresses SET is_default = 1 WHERE address_id = ? AND user_id = ?')->execute([(int) $replacementId, $userId]);
                }
            }
            if ($delete->rowCount() > 0) {
                auditLog($pdo, 'delete', 'Customer deleted a saved shipping address.', 'account', $userId, 'address', $addressId, 'success', 'customer');
                $_SESSION['address_flash_success'] = 'Address removed.';
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Customer address deletion failed: ' . $e->getMessage());
            $_SESSION['address_flash_error'] = 'Could not remove this address. Please try again.';
        }
        header('Location: /addresses.php');
        exit;
    }
}

$flashError = (string) ($_SESSION['address_flash_error'] ?? '');
unset($_SESSION['address_flash_error']);
$listStmt = $pdo->prepare('SELECT address_id, label, recipient_name, mobile_number, street, city, province, zip_code, is_default FROM addresses WHERE user_id = ? ORDER BY is_default DESC, address_id DESC');
$listStmt->execute([$userId]);
$addresses = array_map('decryptAddressPersonalFields', $listStmt->fetchAll());

include __DIR__ . '/includes/header.php';
?>

<div class="account-page-layout container">
    <aside class="account-sidebar">
        <nav class="account-sidebar-nav" aria-label="Account navigation">
            <p class="account-sidebar-section-label">Settings</p>
            <a href="/account/" class="account-sidebar-item"><span aria-hidden="true">♙</span>Account</a>
            <a href="/account/security.php" class="account-sidebar-item"><span aria-hidden="true">◇</span>Security</a>
            <a href="/addresses.php" class="account-sidebar-item active" aria-current="page"><span aria-hidden="true">⌖</span>Manage Addresses</a>
            <div class="account-sidebar-divider"></div>
            <a href="/account/orders.php" class="account-sidebar-item"><span aria-hidden="true">▤</span>Orders</a>
            <div class="account-sidebar-divider"></div>
            <button type="button" class="account-sidebar-item account-sidebar-logout account-logout-button"><span aria-hidden="true">↪</span>Logout</button>
        </nav>
    </aside>

        <main class="account-main-content addresses-page">
        <header class="addresses-header">
            <span class="addresses-eyebrow">Delivery Settings</span>
            <h1>Manage Addresses</h1>
            <p>Save your delivery locations once and use them again at checkout.</p>
        </header>

        <?php if ($success !== '' || $flashError !== '' || $errors): ?>
            <div class="address-notice <?= ($flashError !== '' || $errors) ? 'is-error' : 'is-success' ?>" role="status">
                <?= htmlspecialchars($flashError !== '' ? $flashError : ($errors ? implode(' ', $errors) : $success), ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="addresses-content-grid">
            <section class="addresses-list-panel" aria-labelledby="saved-addresses-heading">
                <div class="addresses-section-heading">
                    <div><span class="addresses-eyebrow">Your Places</span><h2 id="saved-addresses-heading">Saved Addresses</h2></div>
                    <span class="addresses-count"><?= count($addresses) ?> saved</span>
                </div>
                <?php if (!$addresses): ?>
                    <div class="addresses-empty"><span class="addresses-empty-icon" aria-hidden="true">⌖</span><h3>No saved addresses yet</h3><p>Add your first delivery address. You can mark it as the default for faster checkout.</p></div>
                <?php else: ?>
                    <div class="addresses-list">
                        <?php foreach ($addresses as $address): ?>
                            <article class="address-card <?= !empty($address['is_default']) ? 'default-address' : '' ?>">
                                <div class="address-card-header">
                                    <div class="address-card-title"><span class="address-card-pin" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 5.25-7.5 11.25-7.5 11.25S4.5 15.75 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/><circle cx="12" cy="10.5" r="2.25"/></svg></span><div><h3><?= htmlspecialchars($address['label'] ?: 'Delivery Address', ENT_QUOTES, 'UTF-8') ?></h3><p><?= htmlspecialchars($address['city'] . ', ' . $address['province'], ENT_QUOTES, 'UTF-8') ?></p></div></div>
                                    <?php if (!empty($address['is_default'])): ?><span class="default-address-badge">Default</span><?php endif; ?>
                                </div>
                                <p class="address-details"><strong><?= htmlspecialchars($address['recipient_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong><br><?= htmlspecialchars($address['mobile_number'] ?? '', ENT_QUOTES, 'UTF-8') ?><br><?= htmlspecialchars($address['street'], ENT_QUOTES, 'UTF-8') ?><br><?= htmlspecialchars($address['city'] . ', ' . $address['province'] . ' ' . $address['zip_code'], ENT_QUOTES, 'UTF-8') ?></p>
                                <div class="address-actions">
                                    <a class="btn btn-secondary" href="/addresses.php?edit=<?= (int) $address['address_id'] ?>">Edit</a>
                                    <?php if (empty($address['is_default'])): ?>
                                        <form class="address-action-form" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="set_default"><input type="hidden" name="address_id" value="<?= (int) $address['address_id'] ?>"><button class="btn btn-secondary" type="submit">Set As Default</button></form>
                                    <?php endif; ?>
                                    <form class="address-action-form" method="post" onsubmit="return confirm('Remove this saved address?')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="delete_address"><input type="hidden" name="address_id" value="<?= (int) $address['address_id'] ?>"><button class="btn address-delete-button" type="submit">Delete</button></form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="address-form-panel" aria-labelledby="address-form-heading">
                <div class="addresses-eyebrow">Address Book</div>
                <h2 id="address-form-heading"><?= $editingId ? 'Edit Address' : 'Add A New Address' ?></h2>
                <p class="address-form-intro">Save the recipient and delivery details together. Checkout will use this address by default.</p>
                <form method="post" class="address-form address-form-modern">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="save_address">
                    <?php if ($editingId): ?><input type="hidden" name="address_id" value="<?= (int) $editingId ?>"><?php endif; ?>
                    <div class="address-form-grid">
                        <div class="form-group"><label for="address_label">Address Label <span>Optional</span></label><input id="address_label" name="label" type="text" maxlength="40" placeholder="Home, Work, or Family" value="<?= htmlspecialchars($formAddress['label'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></div>
                        <div class="form-group"><label for="address_recipient">Recipient Name <b>*</b></label><input id="address_recipient" name="recipient_name" type="text" maxlength="150" autocomplete="name" required value="<?= htmlspecialchars($formAddress['recipient_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></div>
                        <div class="form-group"><label for="address_mobile">Mobile Number <b>*</b></label><input id="address_mobile" name="mobile_number" type="tel" maxlength="20" autocomplete="tel" required placeholder="09XXXXXXXXX" value="<?= htmlspecialchars($formAddress['mobile_number'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></div>
                        <div class="form-group form-group-full"><label for="address_street">Street Address <b>*</b></label><input id="address_street" name="street" type="text" maxlength="255" required placeholder="Unit, building, street, barangay" value="<?= htmlspecialchars($formAddress['street'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></div>
                        <div class="form-group"><label for="address_city">City / Municipality <b>*</b></label><input id="address_city" name="city" type="text" maxlength="100" required value="<?= htmlspecialchars($formAddress['city'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></div>
                        <div class="form-group"><label for="address_province">Province <b>*</b></label><input id="address_province" name="province" type="text" maxlength="100" required value="<?= htmlspecialchars($formAddress['province'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></div>
                        <div class="form-group"><label for="address_zip">ZIP Code <b>*</b></label><input id="address_zip" name="zip_code" type="text" maxlength="10" required placeholder="e.g. 1600" value="<?= htmlspecialchars($formAddress['zip_code'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></div>
                    </div>
                    <label class="address-default-choice"><input type="checkbox" name="is_default" value="1" <?= ($editingId && !empty($formAddress['is_default'])) || (!$editingId && !$addresses) ? 'checked' : '' ?>><span><strong>Use As My Default Address</strong><small>Checkout will fill this address automatically.</small></span></label>
                    <div class="address-form-actions"><button type="submit" class="btn btn-primary"><?= $editingId ? 'Save Changes' : 'Save Address' ?></button><?php if ($editingId): ?><a class="btn btn-secondary" href="/addresses.php">Cancel</a><?php endif; ?></div>
                </form>
            </section>
        </div>
    </main>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
