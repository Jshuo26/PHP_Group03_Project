<?php

require_once 'includes/session.php';
require_once 'includes/db.php';
require_once 'includes/csrf.php';
require_once 'includes/recaptcha.php';
require_once 'includes/input.php';

$errors  = [];
$success = '';
$recaptchaSiteKey = trim((string) ($config['RECAPTCHA_SITE_KEY'] ?? ''));
$recaptchaSecretKey = trim((string) ($config['RECAPTCHA_SECRET_KEY'] ?? ''));
$recaptchaConfigured = $recaptchaSiteKey !== ''
    && $recaptchaSecretKey !== ''
    && $recaptchaSiteKey !== 'your_site_key_here'
    && $recaptchaSecretKey !== 'your_secret_key_here';

$old = [
    'full_name'     => '',
    'email'         => '',
    'mobile_number' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    requireCsrfToken();
    $full_name        = trim(requestString($_POST, 'full_name'));
    $email            = trim(requestString($_POST, 'email'));
    $mobile_number    = trim(requestString($_POST, 'mobile_number'));
    $password         = requestString($_POST, 'password');
    $confirm_password = requestString($_POST, 'confirm_password');

    $old['full_name']     = $full_name;
    $old['email']         = $email;
    $old['mobile_number'] = $mobile_number;

    if ($full_name === '') {
        $errors[] = 'Full name is required.';
    } elseif (mb_strlen($full_name) < 2 || mb_strlen($full_name) > 150) {
        $errors[] = 'Full name must be between 2 and 150 characters.';
    } elseif (!preg_match('/^[\p{L}\p{M}\s.\'\-]+$/u', $full_name)) {
        $errors[] = 'Full name may only contain letters, spaces, periods, apostrophes, and hyphens.';
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

    if (strlen($password) < 12) {
        $errors[] = 'Password must be at least 12 characters long.';
    }

    if (strlen($password) > 72) {
        $errors[] = 'Password must not be longer than 72 characters.';
    }

    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter.';
    }

    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter.';
    }

    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one number.';
    }

    if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
        $errors[] = 'Password must contain at least one special character.';
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

        $full_name = strip_tags($full_name);
        $full_name = preg_replace('/\s+/', ' ', $full_name);
        $email = strtolower(filter_var($email, FILTER_SANITIZE_EMAIL));
        $mobile_number = preg_replace('/[\s\-]/', '', $mobile_number);
    }

    if (empty($errors)) {

        $stmt = $pdo->prepare(
            'SELECT user_id FROM users WHERE email = ? LIMIT 1'
        );

        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            $errors[] = 'An account with this email already exists.';
        }
    }

    if (empty($errors)) {

        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        try {

            $stmt = $pdo->prepare(
                'INSERT INTO users
                (full_name, email, mobile_number, password_hash)
                VALUES (?, ?, ?, ?)'
            );

            $stmt->execute([
                $full_name,
                $email,
                $mobile_number !== '' ? $mobile_number : null,
                $password_hash
            ]);

            $success = 'Registration successful! You can now log in.';

            $old = ['full_name' => '', 'email' => '', 'mobile_number' => ''];

        } catch (PDOException $e) {

            if ($e->getCode() === '23000') {
                $errors[] = 'An account with this email already exists.';
            } else {
                error_log('Registration failed: ' . $e->getMessage());
                $errors[] = 'Registration failed. Please try again later.';
            }
        }
    }

}

include 'includes/header.php';
?>

<section class="container">

    <div class="card">

        <h1 class="card-title">
            Create an Account
        </h1>

        <?php if (!empty($errors)): ?>

            <div class="form-errors">

                <?php foreach ($errors as $error): ?>

                    <p>
                        <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                    </p>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>


        <?php if ($success !== ''): ?>

            <div class="form-success">
                <p>
                    <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                </p>

                <a
                    href="login.php"
                    class="btn btn-primary"
                >
                    Go to Login
                </a>
            </div>

        <?php else: ?>

            <form method="POST" action="register.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>"
                >

                <div class="form-group">

                    <label for="full_name">
                        Full Name
                    </label>

                    <input
                        type="text"
                        id="full_name"
                        name="full_name"
                        maxlength="150"
                        autocomplete="name"
                        required
                        value="<?php echo htmlspecialchars($old['full_name'], ENT_QUOTES, 'UTF-8'); ?>"
                    >

                </div>


                <div class="form-group">

                    <label for="email">
                        Email
                    </label>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        maxlength="255"
                        autocomplete="email"
                        required
                        value="<?php echo htmlspecialchars($old['email'], ENT_QUOTES, 'UTF-8'); ?>"
                    >

                </div>


                <div class="form-group">

                    <label for="mobile_number">
                        Mobile Number (optional)
                    </label>

                    <input
                        type="tel"
                        id="mobile_number"
                        name="mobile_number"
                        maxlength="20"
                        placeholder="09XXXXXXXXX"
                        autocomplete="tel"
                        value="<?php echo htmlspecialchars($old['mobile_number'], ENT_QUOTES, 'UTF-8'); ?>"
                    >

                </div>


                <div class="form-group">

                    <label for="password">
                        Password
                    </label>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        autocomplete="new-password"
                        required
                    >

                    <small>
                        12 to 72 characters, including uppercase,
                        lowercase, number, and special character.
                    </small>
                </div>


                <div class="form-group">

                    <label for="confirm_password">
                        Confirm Password
                    </label>

                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        autocomplete="new-password"
                        required
                    >

                </div>

                <div class="form-group">
                    <?php if ($recaptchaConfigured): ?>
                        <div class="g-recaptcha"
                            data-sitekey="<?php echo htmlspecialchars($recaptchaSiteKey, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    <?php else: ?>
                        <p class="form-errors" role="alert">Registration is temporarily unavailable because reCAPTCHA is not configured.</p>
                    <?php endif; ?>
                </div>


                <button
                    type="submit"
                    class="btn btn-primary"
                    <?php echo !$recaptchaConfigured ? 'disabled' : ''; ?>
                >
                    Create Account
                </button>

            </form>

        <?php endif; ?>

    </div>

</section>

<?php if ($recaptchaConfigured): ?>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
<?php endif; ?>

<script src="/js/register.js" defer></script>

<?php include 'includes/footer.php'; ?>