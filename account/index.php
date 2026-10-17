<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/input.php';

requireRole(['customer']);

$userId = (int) $_SESSION['user_id'];
$errors = [];
$success = '';

$profileStmt = $pdo->prepare(
    'SELECT full_name, email, mobile_number, profile_picture_path FROM users WHERE user_id = ?'
);
$profileStmt->execute([$userId]);
$profile = $profileStmt->fetch();

if (!$profile) {
    http_response_code(404);
    exit('Account not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    $action = requestString($_POST, 'action');

    if ($action === 'update_name') {
        $fullName = trim(requestString($_POST, 'full_name'));

        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 150) {
            $errors[] = 'Name must be between 2 and 150 characters.';
        }

        if (!$errors) {
            try {
                $updateStmt = $pdo->prepare(
                    'UPDATE users SET full_name = ? WHERE user_id = ?'
                );
                $updateStmt->execute([$fullName, $userId]);

                $_SESSION['full_name'] = $fullName;
                $profile['full_name'] = $fullName;
                $success = 'Full name updated successfully.';
            } catch (PDOException $e) {
                error_log('Name update failed: ' . $e->getMessage());
                $errors[] = 'Unable to update your full name right now.';
            }
        }
    }

    if ($action === 'update_email') {
        $email = strtolower(trim(requestString($_POST, 'email')));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $errors[] = 'Enter a valid email address (maximum 255 characters).';
        }

        if (!$errors) {
            $emailStmt = $pdo->prepare(
                'SELECT user_id FROM users WHERE email = ? AND user_id <> ?'
            );
            $emailStmt->execute([$email, $userId]);

            if ($emailStmt->fetch()) {
                $errors[] = 'That email address is already in use.';
            }
        }

        if (!$errors) {
            try {
                $updateStmt = $pdo->prepare(
                    'UPDATE users SET email = ? WHERE user_id = ?'
                );
                $updateStmt->execute([$email, $userId]);

                $_SESSION['email'] = $email;
                $profile['email'] = $email;
                $success = 'Email address updated successfully.';
            } catch (PDOException $e) {
                error_log('Email update failed: ' . $e->getMessage());
                $errors[] = 'Unable to update your email address right now.';
            }
        }
    }

    if ($action === 'update_mobile') {
        $mobileNumber = preg_replace(
            '/[\s-]/',
            '',
            trim(requestString($_POST, 'mobile_number'))
        );

        if (
            $mobileNumber !== '' &&
            !preg_match('/^(\+63|0)9[0-9]{9}$/', $mobileNumber)
        ) {
            $errors[] = 'Enter a valid Philippine mobile number, such as 09123456789.';
        }

        if (!$errors) {
            try {
                $updateStmt = $pdo->prepare(
                    'UPDATE users SET mobile_number = ? WHERE user_id = ?'
                );
                $updateStmt->execute([
                    $mobileNumber !== '' ? $mobileNumber : null,
                    $userId
                ]);

                $profile['mobile_number'] = $mobileNumber;
                $success = 'Mobile number updated successfully.';
            } catch (PDOException $e) {
                error_log('Mobile number update failed: ' . $e->getMessage());
                $errors[] = 'Unable to update your mobile number right now.';
            }
        }
    }

    if ($action === 'update_avatar') {
        $file = $_FILES['profile_picture'] ?? null;
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'Please choose an image file to upload.';
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Failed to upload image. Please try again.';
        } elseif ((int) $file['size'] > 2 * 1024 * 1024) {
            $errors[] = 'Profile picture must be 2 MB or smaller.';
        } else {
            $imageInfo = @getimagesize($file['tmp_name']);
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            $allowed = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
            ];
            if (!$imageInfo || !isset($allowed[$mime])) {
                $errors[] = 'Please upload a valid JPG, PNG, or WebP image.';
            } else {
                $uploadDir = __DIR__ . '/../uploads/profile-pictures';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $newFileName = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
                $destPath = $uploadDir . '/' . $newFileName;
                if (move_uploaded_file($file['tmp_name'], $destPath)) {
                    $newWebPath = '/uploads/profile-pictures/' . $newFileName;
                    if (!empty($profile['profile_picture_path'])) {
                        $oldFile = __DIR__ . '/..' . $profile['profile_picture_path'];
                        if (is_file($oldFile)) {
                            @unlink($oldFile);
                        }
                    }
                    try {
                        $upStmt = $pdo->prepare('UPDATE users SET profile_picture_path = ? WHERE user_id = ?');
                        $upStmt->execute([$newWebPath, $userId]);
                        $profile['profile_picture_path'] = $newWebPath;
                        $_SESSION['profile_picture_path'] = $newWebPath;
                        $success = 'Profile picture updated successfully.';
                    } catch (PDOException $e) {
                        error_log('Failed to update avatar: ' . $e->getMessage());
                        $errors[] = 'Could not update profile picture in database.';
                    }
                } else {
                    $errors[] = 'Could not save uploaded image.';
                }
            }
        }
    }

    if ($action === 'remove_avatar') {
        if (!empty($profile['profile_picture_path'])) {
            $oldFile = __DIR__ . '/..' . $profile['profile_picture_path'];
            if (is_file($oldFile)) {
                @unlink($oldFile);
            }
        }
        try {
            $rmStmt = $pdo->prepare('UPDATE users SET profile_picture_path = NULL WHERE user_id = ?');
            $rmStmt->execute([$userId]);
            $profile['profile_picture_path'] = null;
            $_SESSION['profile_picture_path'] = null;
            $success = 'Profile picture removed.';
        } catch (PDOException $e) {
            error_log('Failed to remove avatar: ' . $e->getMessage());
            $errors[] = 'Could not remove profile picture.';
        }
    }


    if (!$errors && $success !== '') {
        $profileStmt = $pdo->prepare(
            'SELECT full_name, email, mobile_number, profile_picture_path FROM users WHERE user_id = ?'
        );
        $profileStmt->execute([$userId]);
        $profile = $profileStmt->fetch();
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="account-page-layout container">

    <!-- ========== SIDEBAR ========== -->
    <aside class="account-sidebar">
        <nav class="account-sidebar-nav" aria-label="Account navigation">

            <p class="account-sidebar-section-label">Settings</p>

            <a href="/account/" class="account-sidebar-item active" aria-current="page">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="8" r="4"></circle>
                    <path d="M4 21a8 8 0 0 1 16 0"></path>
                </svg>
                Account
            </a>

            <a href="/account/security.php" class="account-sidebar-item">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                </svg>
                Security
            </a>

            <a href="/addresses.php" class="account-sidebar-item">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/>
                    <circle cx="12" cy="9" r="2.5"/>
                </svg>
                Manage Addresses
            </a>

            <div class="account-sidebar-divider"></div>

            <a href="/account/orders.php" class="account-sidebar-item">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
                    <rect x="9" y="3" width="6" height="4" rx="1"/>
                    <line x1="9" y1="12" x2="15" y2="12"/>
                    <line x1="9" y1="16" x2="13" y2="16"/>
                </svg>
                Orders
            </a>

            <div class="account-sidebar-divider"></div>

            <button type="button" class="account-sidebar-item account-sidebar-logout account-logout-button">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <polyline points="16 17 21 12 16 7"/>
                    <line x1="21" y1="12" x2="9" y2="12"/>
                </svg>
                Logout
            </button>

        </nav>
    </aside>

    <!-- ========== MAIN CONTENT ========== -->
    <div class="account-main-content">

        <div class="page-heading">
            <h1 class="page-title">My Account</h1>
            <p class="page-subtitle">
                Manage your personal information and contact details.
            </p>
        </div>

        <!-- Profile Header Block -->
        <div class="account-profile-header-card">
            <div class="account-profile-avatar-wrap">
                <div class="account-profile-avatar-circle" aria-hidden="true">
                    <?php if (!empty($profile['profile_picture_path']) && file_exists(__DIR__ . '/..' . $profile['profile_picture_path'])): ?>
                        <img 
                            src="<?= htmlspecialchars($profile['profile_picture_path'], ENT_QUOTES, 'UTF-8') ?>" 
                            alt="<?= htmlspecialchars($profile['full_name'], ENT_QUOTES, 'UTF-8') ?>"
                            class="account-profile-avatar-img"
                        >
                    <?php else: ?>
                        <svg xmlns="http://www.w3.org/2000/svg" width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="8" r="4"></circle>
                            <path d="M4 21a8 8 0 0 1 16 0"></path>
                        </svg>
                    <?php endif; ?>
                </div>
            </div>
            <div class="account-profile-details">
                <h2 class="account-profile-name"><?= htmlspecialchars($profile['full_name'], ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="account-profile-email"><?= htmlspecialchars($profile['email'], ENT_QUOTES, 'UTF-8') ?></p>
                <div class="account-profile-badges">
                    <span class="account-profile-badge">Customer Account</span>
                    <?php if (!empty($profile['mobile_number'])): ?>
                        <span class="account-profile-badge-subtle"><?= htmlspecialchars($profile['mobile_number'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($errors): ?>
            <div class="form-errors" role="alert">
                <?php foreach ($errors as $error): ?>
                    <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="form-success" role="status">
                <p><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        <?php endif; ?>

        <!-- Personal Information -->
        <div class="settings-section">
            <!-- Profile Picture Card -->
            <div class="settings-card" style="margin-bottom: 20px;">
                <div class="settings-card-header">
                    <h2>Profile Photo</h2>
                    <p>Upload a personal photo to display on your profile and header.</p>
                </div>
                <form method="post" action="/account/index.php" enctype="multipart/form-data" class="settings-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="update_avatar">
                    <div class="form-group">
                        <input type="file" id="profile_picture" name="profile_picture" accept="image/jpeg,image/png,image/webp" class="form-input" required>
                        <small style="color: var(--muted-text); font-size: 0.82rem; margin-top: 4px; display: block;">JPG, PNG, or WebP. Maximum size: 2 MB.</small>
                    </div>
                    <div style="display: flex; gap: 10px; align-items: center; margin-top: 8px;">
                        <button type="submit" class="btn btn-primary btn-sm">Save Photo</button>
                    </div>
                </form>
                <?php if (!empty($profile['profile_picture_path'])): ?>
                    <form method="post" action="/account/index.php" style="margin-top: 10px;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="remove_avatar">
                        <button type="submit" class="btn btn-secondary btn-sm" style="color: var(--danger-color); border-color: var(--danger-color);">Remove Photo</button>
                    </form>
                <?php endif; ?>
            </div>
            
            <div class="settings-section-heading">
                <h2>Personal Information</h2>
                <p>Keep your account information up to date.</p>
            </div>

            <div class="settings-card">
                <div class="settings-card-content">
                    <div class="settings-field-row">
                        <div class="settings-field-meta">
                            <h3>Full Name</h3>
                            <p class="settings-value">
                                <?= htmlspecialchars($profile['full_name'], ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        </div>

                        <form method="post" action="/account/index.php" class="settings-form">
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"
                            >
                            <input type="hidden" name="action" value="update_name">

                            <label for="full_name">Update full name</label>
                            <div class="settings-form-row">
                                <input
                                    class="form-input"
                                    type="text"
                                    id="full_name"
                                    name="full_name"
                                    maxlength="150"
                                    required
                                    value="<?= htmlspecialchars($profile['full_name'], ENT_QUOTES, 'UTF-8') ?>"
                                >
                                <button class="btn btn-primary" type="submit">
                                    Save
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="settings-card">
                <div class="settings-card-content">
                    <div class="settings-field-row">
                        <div class="settings-field-meta">
                            <h3>Email Address</h3>
                            <p class="settings-value">
                                <?= htmlspecialchars($profile['email'], ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        </div>

                        <form method="post" action="/account/index.php" class="settings-form">
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"
                            >
                            <input type="hidden" name="action" value="update_email">

                            <label for="email">Update email address</label>
                            <div class="settings-form-row">
                                <input
                                    class="form-input"
                                    type="email"
                                    id="email"
                                    name="email"
                                    maxlength="255"
                                    required
                                    value="<?= htmlspecialchars($profile['email'], ENT_QUOTES, 'UTF-8') ?>"
                                >
                                <button class="btn btn-primary" type="submit">
                                    Save
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="settings-card">
                <div class="settings-card-content">
                    <div class="settings-field-row">
                        <div class="settings-field-meta">
                            <h3>Mobile Number</h3>
                            <p class="settings-value">
                                <?= $profile['mobile_number']
                                    ? htmlspecialchars($profile['mobile_number'], ENT_QUOTES, 'UTF-8')
                                    : 'No mobile number added' ?>
                            </p>
                        </div>

                        <form method="post" action="/account/index.php" class="settings-form">
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"
                            >
                            <input type="hidden" name="action" value="update_mobile">

                            <label for="mobile_number">Update mobile number</label>
                            <div class="settings-form-row">
                                <input
                                    class="form-input"
                                    type="tel"
                                    id="mobile_number"
                                    name="mobile_number"
                                    maxlength="20"
                                    placeholder="09123456789"
                                    value="<?= htmlspecialchars((string) $profile['mobile_number'], ENT_QUOTES, 'UTF-8') ?>"
                                >
                                <button class="btn btn-primary" type="submit">
                                    Save
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /.account-main-content -->

</div><!-- /.account-page-layout -->

<?php include __DIR__ . '/../includes/footer.php'; ?>
