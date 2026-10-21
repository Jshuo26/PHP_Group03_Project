<?php

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/recaptcha.php';
require_once __DIR__ . '/../includes/input.php';
require_once __DIR__ . '/../includes/otp.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/password_policy.php';

$errors  = [];
$passwordPolicy = getPasswordPolicy($pdo);
$recaptchaSiteKey = trim((string) ($config['RECAPTCHA_SITE_KEY'] ?? ''));
$recaptchaSecretKey = trim((string) ($config['RECAPTCHA_SECRET_KEY'] ?? ''));
$recaptchaConfigured = $recaptchaSiteKey !== ''
    && $recaptchaSecretKey !== ''
    && $recaptchaSiteKey !== 'your_site_key_here'
    && $recaptchaSecretKey !== 'your_secret_key_here';

$old = [
    'first_name'    => '',
    'last_name'     => '',
    'email'         => '',
    'mobile_number' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    requireCsrfToken();
    $first_name       = trim(requestString($_POST, 'first_name'));
    $last_name        = trim(requestString($_POST, 'last_name'));
    $full_name        = trim($first_name . ' ' . $last_name);
    $email            = trim(requestString($_POST, 'email'));
    $mobile_number    = trim(requestString($_POST, 'mobile_number'));
    $password         = requestString($_POST, 'password');
    $confirm_password = requestString($_POST, 'confirm_password');

    $old['first_name']    = $first_name;
    $old['last_name']     = $last_name;
    $old['email']         = $email;
    $old['mobile_number'] = $mobile_number;

    foreach ([['First name', $first_name], ['Last name', $last_name]] as [$nameLabel, $nameValue]) {
        if ($nameValue === '') {
            $errors[] = $nameLabel . ' is required.';
        } elseif (mb_strlen($nameValue) > 75) {
            $errors[] = $nameLabel . ' must not exceed 75 characters.';
        } elseif (!preg_match('/^[\p{L}\p{M}\s.\'\-]+$/u', $nameValue)) {
            $errors[] = $nameLabel . ' may only contain letters, spaces, periods, apostrophes, and hyphens.';
        }
    }

    if (mb_strlen($full_name) > 150) {
        $errors[] = 'Your full name must not exceed 150 characters.';
    }

    $profilePicture = $_FILES['profile_picture'] ?? null;
    $profilePictureMime = null;
    if ($profilePicture && $profilePicture['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($profilePicture['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'The profile picture could not be uploaded. Please try again.';
        } elseif ((int) $profilePicture['size'] > 2 * 1024 * 1024) {
            $errors[] = 'The profile picture must be 2 MB or smaller.';
        } else {
            $imageInfo = @getimagesize($profilePicture['tmp_name']);
            $fileInfo = new finfo(FILEINFO_MIME_TYPE);
            $profilePictureMime = $fileInfo->file($profilePicture['tmp_name']);
            if (!$imageInfo || !in_array($profilePictureMime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                $errors[] = 'Please upload a valid JPG, PNG, or WebP image.';
            }
        }
    }

    if ($email === '') {
        $errors[] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($mobile_number !== '') {
        $mobile_check = preg_replace('/[\s\-]/', '', $mobile_number);

        if (!preg_match('/^(\+63|0)9[0-9]{9}$/', $mobile_check)) {
            $errors[] = 'Please enter a valid mobile number, for example 09123456789.';
        }
    }

    foreach (validatePasswordAgainstPolicy($password, $passwordPolicy) as $passwordError) {
        $errors[] = $passwordError;
    }

    if ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match.';
    }

    if (!$recaptchaConfigured) {
        $errors[] = 'Registration is unavailable until the reCAPTCHA site and secret keys are configured.';
    } elseif (!verifyRecaptcha(
        requestString($_POST, 'g-recaptcha-response'),
        $recaptchaSecretKey
    )) {
        $errors[] = 'Please confirm that you are not a robot.';
    }

    if (empty($errors)) {

        $first_name = preg_replace('/\s+/', ' ', strip_tags($first_name));
        $last_name = preg_replace('/\s+/', ' ', strip_tags($last_name));
        $full_name = $first_name . ' ' . $last_name;
        $email = strtolower(filter_var($email, FILTER_SANITIZE_EMAIL));
        $mobile_number = preg_replace('/[\s\-]/', '', $mobile_number);
    }

    if (empty($errors)) {

        $stmt = $pdo->prepare(
            'SELECT user_id, account_status FROM users WHERE email = ? LIMIT 1'
        );

        $stmt->execute([$email]);

        $existing = $stmt->fetch();

        if ($existing) {

            if ($existing['account_status'] === 'pending') {
                $errors[] = 'This email is already registered but not verified yet. '
                          . 'Log in with your password to get a new code.';
            } else {
                $errors[] = 'An account with this email already exists.';
            }
        }
    }

    $newUserId = 0;

    if (empty($errors)) {

        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $profilePicturePath = null;
        $savedProfilePicture = null;

        try {
            if ($profilePicture && $profilePicture['error'] === UPLOAD_ERR_OK) {
                $extension = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                ][$profilePictureMime];
                $uploadDirectory = __DIR__ . '/../uploads/profile-pictures';
                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
                    throw new RuntimeException('Could not create profile picture directory.');
                }
                $fileName = bin2hex(random_bytes(16)) . '.' . $extension;
                $savedProfilePicture = $uploadDirectory . '/' . $fileName;
                if (!move_uploaded_file($profilePicture['tmp_name'], $savedProfilePicture)) {
                    throw new RuntimeException('Could not save profile picture.');
                }
                $profilePicturePath = '/uploads/profile-pictures/' . $fileName;
            }

            $stmt = $pdo->prepare(
                "INSERT INTO users
                (first_name, last_name, full_name, email, mobile_number, profile_picture_path, password_hash, account_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')"
            );

            $stmt->execute([
                $first_name,
                $last_name,
                $full_name,
                $email,
                $mobile_number !== '' ? $mobile_number : null,
                $profilePicturePath,
                $password_hash
            ]);

            $newUserId = (int) $pdo->lastInsertId();

        } catch (Throwable $e) {
            if ($savedProfilePicture && is_file($savedProfilePicture)) {
                unlink($savedProfilePicture);
            }

            if ($e->getCode() === '23000') {
                $errors[] = 'An account with this email already exists.';
            } else {
                error_log('Registration failed: ' . $e->getMessage());
                $errors[] = 'Registration failed. Please try again later.';
            }
        }
    }

    if (empty($errors) && $newUserId > 0) {

        $sent = false;

        try {

            $code  = createOtp($pdo, $newUserId, 'registration');
            $token = createActivationToken($pdo, $newUserId);

            $sent = sendOtpEmail(
                $config,
                $email,
                $full_name,
                $code,
                'verify your email address',
                activationUrl($config, $token)
            );

        } catch (PDOException $e) {
            error_log('OTP creation failed: ' . $e->getMessage());
        }

        session_regenerate_id(true);

        $_SESSION['verify_user_id'] = $newUserId;

        $_SESSION['verify_notice'] = $sent
            ? 'We sent a 6-digit code to ' . maskEmail($email) . '. Enter it below to activate your account.'
            : 'Your account was created, but we could not send the email. Click "Resend code" to try again.';

        header('Location: /auth/verify_email.php');
        exit;
    }

}

include __DIR__ . '/../includes/header.php';
?>

<section class="container auth-section register-page-section">
    <div class="register-card">
        <div class="register-heading">
            <p class="register-kicker">JOIN OUR COMMUNITY</p>
            <h1 class="card-title">Create an Account</h1>
            <p>Set up your account to discover and enjoy Filipino stories.</p>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="form-errors" role="alert">
                <?php foreach ($errors as $error): ?>
                    <p><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="/auth/register.php" enctype="multipart/form-data" id="registerForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">

            <div class="register-field-row register-name-row">
                <div class="form-group">
                    <label for="first_name">First Name <span class="required" aria-hidden="true">*</span></label>
                    <input class="form-input" type="text" id="first_name" name="first_name" maxlength="75" autocomplete="given-name" placeholder="Juan" required value="<?php echo htmlspecialchars($old['first_name'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <span class="register-field-divider" aria-hidden="true"></span>
                <div class="form-group">
                    <label for="last_name">Last Name <span class="required" aria-hidden="true">*</span></label>
                    <input class="form-input" type="text" id="last_name" name="last_name" maxlength="75" autocomplete="family-name" placeholder="Dela Cruz" required value="<?php echo htmlspecialchars($old['last_name'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>

            <div class="register-field-row register-contact-row">
                <div class="form-group">
                    <label for="email">Email Address <span class="required" aria-hidden="true">*</span></label>
                    <input class="form-input" type="email" id="email" name="email" maxlength="255" autocomplete="email" placeholder="juandelacruz@example.com" required value="<?php echo htmlspecialchars($old['email'], ENT_QUOTES, 'UTF-8'); ?>">
                    <small>We'll send a 6-digit verification code.</small>
                </div>
                <span class="register-field-divider" aria-hidden="true"></span>
                <div class="form-group">
                    <label for="mobile_number">Contact Number <span class="register-optional">(optional)</span></label>
                    <input class="form-input" type="tel" id="mobile_number" name="mobile_number" maxlength="20" placeholder="09XXXXXXXXX" autocomplete="tel" value="<?php echo htmlspecialchars($old['mobile_number'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>

            <div class="form-group register-profile-group">
                <label for="profile_picture">Profile Picture <span class="register-optional">(optional)</span></label>
                <div class="register-upload-control">
                    <div class="register-avatar-preview" id="profilePicturePreview" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c.5-3.5 3.2-5.5 7-5.5s6.5 2 7 5.5" stroke-linecap="round"/></svg>
                    </div>
                    <div class="register-upload-details">
                        <input class="register-file-input" type="file" id="profile_picture" name="profile_picture" accept="image/jpeg,image/png,image/webp">
                        <small>JPG, PNG, or WebP. Maximum file size: 2 MB.</small>
                    </div>
                </div>
            </div>

            <div class="form-group register-password-group">
                <label for="password">Password <span class="required" aria-hidden="true">*</span></label>
                <div class="password-wrapper">
                    <input class="form-input" type="password" id="password" name="password" autocomplete="new-password" placeholder="Create a strong password" required>
                    <button type="button" class="password-toggle" data-target="password" aria-label="Show password" aria-pressed="false">
                        <svg class="icon-closed" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                        <svg class="icon-open" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path fill-rule="evenodd" d="M1.323 11.447C2.811 6.976 7.028 3.75 12.001 3.75c4.97 0 9.185 3.223 10.675 7.69.12.362.12.752 0 1.113-1.487 4.471-5.705 7.697-10.677 7.697-4.97 0-9.186-3.223-10.675-7.69a1.762 1.762 0 0 1 0-1.113ZM17.25 12a5.25 5.25 0 1 1-10.5 0 5.25 5.25 0 0 1 10.5 0Z" clip-rule="evenodd"/></svg>
                    </button>
                </div>
                    <small class="register-password-help">Maximum <?php echo PASSWORD_MAX_LENGTH; ?> characters.</small>
                    <ul class="password-checklist" id="passwordChecklist">
                        <?php foreach (passwordRules($passwordPolicy) as $rule): ?>
                            <li data-rule="<?php echo htmlspecialchars($rule['key'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-min="<?php echo (int) $rule['min']; ?>"><?php echo htmlspecialchars($rule['label'], ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                    </ul>
            </div>

            <div class="form-group register-confirm-group">
                <label for="confirm_password">Confirm Password <span class="required" aria-hidden="true">*</span></label>
                <div class="password-wrapper">
                    <input class="form-input" type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" placeholder="Re-enter your password" required>
                    <button type="button" class="password-toggle" data-target="confirm_password" aria-label="Show password" aria-pressed="false">
                        <svg class="icon-closed" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                        <svg class="icon-open" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path fill-rule="evenodd" d="M1.323 11.447C2.811 6.976 7.028 3.75 12.001 3.75c4.97 0 9.185 3.223 10.675 7.69.12.362.12.752 0 1.113-1.487 4.471-5.705 7.697-10.677 7.697-4.97 0-9.186-3.223-10.675-7.69a1.762 1.762 0 0 1 0-1.113ZM17.25 12a5.25 5.25 0 1 1-10.5 0 5.25 5.25 0 0 1 10.5 0Z" clip-rule="evenodd"/></svg>
                    </button>
                </div>
            </div>

            <div class="register-bottom-row">
                <div class="register-recaptcha">
                    <?php if ($recaptchaConfigured): ?>
                        <div class="g-recaptcha" data-sitekey="<?php echo htmlspecialchars($recaptchaSiteKey, ENT_QUOTES, 'UTF-8'); ?>"></div>
                    <?php else: ?>
                        <p class="form-errors" role="alert">Registration is temporarily unavailable because reCAPTCHA is not configured.</p>
                    <?php endif; ?>
                </div>
                <p class="register-login-prompt">Already have an account? <a href="/auth/login.php">Log in</a></p>
            </div>

            <button type="submit" class="btn btn-primary btn-block register-submit" <?php echo !$recaptchaConfigured ? 'disabled' : ''; ?>>Create Account</button>
        </form>
    </div>
</section>

<?php if ($recaptchaConfigured): ?>
<script src="https://www.google.com/recaptcha/api.js" async defer></script>
<?php endif; ?>

<script src="/js/register.js" defer></script>

<?php include __DIR__ . '/../includes/footer.php'; ?>