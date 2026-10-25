<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/input.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/audit.php';

requireRole(['admin', 'staff']);
$errors = [];
$success = (string) ($_SESSION['admin_flash'] ?? '');
unset($_SESSION['admin_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $customerId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $status = requestString($_POST, 'account_status');
    if (!$customerId || !in_array($status, ['active', 'suspended'], true)) {
        $errors[] = 'Select a valid customer and account status.';
    } else {
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'customer' FOR UPDATE");
            $lock->execute([$customerId]);
            if (!$lock->fetchColumn()) {
                throw new RuntimeException('Customer account not found.');
            }
            $pdo->prepare('UPDATE users SET account_status = ? WHERE user_id = ?')->execute([$status, $customerId]);
            auditLogRequired($pdo, 'update', 'Staff changed a customer account status to ' . $status . '.', 'account', (int) $_SESSION['user_id'], 'user', (int) $customerId, 'success', (string) $_SESSION['role']);
            $pdo->commit();
            $_SESSION['admin_flash'] = 'Customer account status updated.';
            header('Location: /admin/customers.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to update this customer account.';
        }
    }
}

$customers = $pdo->query(
    "SELECT u.user_id, u.first_name, u.last_name, u.full_name, u.email, u.mobile_number,
            u.account_status, u.created_at, COUNT(DISTINCT o.order_id) AS order_count,
            MAX(o.placed_at) AS last_order
       FROM users u LEFT JOIN orders o ON o.user_id = u.user_id
      WHERE u.role = 'customer'
      GROUP BY u.user_id, u.first_name, u.last_name, u.full_name, u.email, u.mobile_number, u.account_status, u.created_at
      ORDER BY u.created_at DESC LIMIT 500"
)->fetchAll();
foreach ($customers as &$customer) {
    $customer = decryptUserPersonalFields($customer);
}
unset($customer);
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
if ($q !== '') {
    $customers = array_values(array_filter($customers, static function (array $customer) use ($q): bool {
        return stripos((string) $customer['full_name'], $q) !== false
            || stripos((string) $customer['email'], $q) !== false
            || stripos((string) $customer['user_id'], $q) !== false;
    }));
}
include __DIR__ . '/../includes/header.php';
?>
<section class="container">
    <div class="card">
        <h1 class="card-title">Customer Management</h1>
        <p class="card-text">View customer accounts and order history. Staff and administrators can activate or suspend customer access.</p>
        <?php if ($errors): ?><div class="form-errors" role="alert"><?php foreach ($errors as $error): ?><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endforeach; ?></div><?php endif; ?>
        <?php if ($success !== ''): ?><div class="form-success" role="status"><p><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p></div><?php endif; ?>
        <form method="get" action="/admin/customers.php" class="inline-form" style="margin:16px 0;">
            <label for="customer-search">Find customer</label>
            <input class="form-input" id="customer-search" type="search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="Name, email, or ID">
            <button class="btn btn-primary" type="submit">Search</button>
            <a class="btn btn-secondary" href="/admin/customers.php">Reset</a>
        </form>
    </div>
    <?php if (!$customers): ?><div class="card"><p class="card-text">No customers match this search.</p></div><?php endif; ?>
    <?php foreach ($customers as $customer): ?>
        <article class="card admin-list-item">
            <h2><?= htmlspecialchars((string) $customer['full_name'], ENT_QUOTES, 'UTF-8') ?> <small>#<?= (int) $customer['user_id'] ?></small></h2>
            <p><strong>Email:</strong> <?= htmlspecialchars((string) $customer['email'], ENT_QUOTES, 'UTF-8') ?> · <strong>Mobile:</strong> <?= htmlspecialchars((string) ($customer['mobile_number'] ?? 'Not provided'), ENT_QUOTES, 'UTF-8') ?></p>
            <p><strong>Account:</strong> <?= htmlspecialchars(ucfirst((string) $customer['account_status']), ENT_QUOTES, 'UTF-8') ?> · <strong>Orders:</strong> <?= (int) $customer['order_count'] ?> · <strong>Last order:</strong> <?= htmlspecialchars((string) ($customer['last_order'] ?? 'None'), ENT_QUOTES, 'UTF-8') ?></p>
            <div class="inline-form">
                <a class="btn btn-secondary" href="/admin/orders.php?customer_id=<?= (int) $customer['user_id'] ?>">View customer orders</a>
                <form method="post" action="/admin/customers.php" class="inline-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="user_id" value="<?= (int) $customer['user_id'] ?>">
                    <label for="customer-status-<?= (int) $customer['user_id'] ?>">Account status</label>
                    <select class="form-input" id="customer-status-<?= (int) $customer['user_id'] ?>" name="account_status">
                        <?php foreach (['active' => 'Active', 'suspended' => 'Suspended'] as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $customer['account_status'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-primary" type="submit">Save status</button>
                </form>
            </div>
        </article>
    <?php endforeach; ?>
</section>
<?php include __DIR__ . '/../includes/footer.php'; ?>
