<?php

require_once 'includes/session.php';
require_once 'includes/db.php';
require_once 'includes/csrf.php';
require_once 'includes/recaptcha.php';

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    requireCsrfToken();

    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $mobile_number = trim($_POST['mobile_number'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Full name validation
    if ($full_name === '') {
        $errors[] = 'Full name is required.';
    }

    // Email validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    // Password validation
    if (strlen($password) < 12) {
        $errors[] = 'Password must be at least 12 characters long.';
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

    // Confirm password
    if ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match.';
    }

    if (!verifyRecaptcha(
        $_POST['g-recaptcha-response'] ?? '',
        $config['RECAPTCHA_SECRET_KEY']
    )) {
    $errors[] = 'Please confirm that you are not a robot.';
    }

    // Check if email already exists
    if (empty($errors)) {

        $stmt = $pdo->prepare(
            'SELECT user_id FROM users WHERE email = ? LIMIT 1'
        );

        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            $errors[] = 'An account with this email already exists.';
        }
    }

    // Create account
    if (empty($errors)) {

        $password_hash = password_hash(
            $password,
            PASSWORD_DEFAULT
        );

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
                        <?php echo htmlspecialchars($error); ?>
                    </p>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>


        <?php if ($success !== ''): ?>

            <div class="form-success">
                <p>
                    <?php echo htmlspecialchars($success); ?>
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
                    value="<?php echo htmlspecialchars(generateCsrfToken()); ?>"
                >

                <div class="form-group">

                    <label for="full_name">
                        Full Name
                    </label>

                    <input
                        type="text"
                        id="full_name"
                        name="full_name"
                        required
                        value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>"
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
                        required
                        value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                    >

                </div>


                <div class="form-group">

                    <label for="mobile_number">
                        Mobile Number
                    </label>

                    <input
                        type="text"
                        id="mobile_number"
                        name="mobile_number"
                        value="<?php echo htmlspecialchars($_POST['mobile_number'] ?? ''); ?>"
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
                        required
                    >

                    <small>
                        At least 12 characters, including uppercase,
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
                        required
                    >

                </div>

                <div class="form-group">
                    <div class="g-recaptcha"
                        data-sitekey="<?php echo htmlspecialchars($config['RECAPTCHA_SITE_KEY']); ?>">
                    </div>
                </div>


                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Create Account
                </button>

            </form>

        <?php endif; ?>

    </div>

</section>

<script src="https://www.google.com/recaptcha/api.js" async defer></script>

<script src="/js/register.js" defer></script>

<?php include 'includes/footer.php'; ?>
