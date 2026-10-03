<?php

require_once 'includes/session.php';
require_once 'includes/db.php';
require_once 'includes/csrf.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    requireCsrfToken();

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($password === '') {
        $errors[] = 'Password is required.';
    }

    if (empty($errors)) {

        $stmt = $pdo->prepare(
            'SELECT user_id, full_name, email, password_hash, role, account_status
             FROM users
             WHERE email = ?
             LIMIT 1'
        );

        $stmt->execute([$email]);

        $user = $stmt->fetch();

        if (
            !$user ||
            !password_verify($password, $user['password_hash'])
        ) {
            $errors[] = 'Invalid email or password.';
        } elseif ($user['account_status'] !== 'active') {
            $errors[] = 'Your account is currently suspended.';
        } else {

            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];

            $_SESSION['last_activity'] = time();

            if ($user['role'] === 'admin') {
                header('Location: admin/index.php');
                exit;
            }

            if ($user['role'] === 'staff') {
                header('Location: admin/index.php');
                exit;
            }

            header('Location: index.php');
            exit;
        }
    }
}

include 'includes/header.php';
?>

<section class="container">

    <div class="card">

        <h1 class="card-title">
            Login
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


        <form method="POST" action="login.php">

            <input
                type="hidden"
                name="csrf_token"
                value="<?php echo htmlspecialchars(generateCsrfToken()); ?>"
            >


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

                <label for="password">
                    Password
                </label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    required
                >

            </div>


            <button
                type="submit"
                class="btn btn-primary"
            >
                Login
            </button>

        </form>


        <p>
            Don't have an account?
            <a href="register.php">
                Create an account
            </a>
        </p>

    </div>

</section>

<?php include 'includes/footer.php'; ?>