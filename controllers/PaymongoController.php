<?php

require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/paymongo.php';
require_once __DIR__ . '/../includes/payment_schema.php';

class PaymongoController
{
    private PDO $pdo;
    private array $config;

    public function __construct(PDO $pdo, array $config)
    {
        $this->pdo = $pdo;
        $this->config = $config;
    }

    public function assertReady(): void
    {
        paymongoTestKeys($this->config);
        paymongoWebhookSecret($this->config);
        if (!paymongoSchemaReady($this->pdo)) {
            throw new RuntimeException('Apply database/migrations/004_paymongo_hosted_checkout.sql to the application database before using PayMongo.');
        }
    }

    public function startOrResumeCheckout(int $orderId, int $userId): string
    {
        $this->assertReady();
        $order = $this->getOwnedOrder($orderId, $userId);
        if ($order['payment_method'] !== 'gcash') {
            throw new RuntimeException('This order is not configured for PayMongo checkout.');
        }
        $this->validateCheckoutAmount($order['total_amount']);
        if ($order['status'] === 'cancelled' || $order['payment_status'] === 'cancelled') {
            throw new RuntimeException('This order was cancelled. Place a new order to try again.');
        }
        if ($order['payment_status'] === 'paid') {
            return '/payments/return.php?order_id=' . $orderId;
        }

        $payment = $this->latestPayment($orderId);
        if ($payment && !empty($payment['checkout_session_id'])) {
            $response = paymongoApiRequest(
                $this->config,
                'GET',
                '/v2/checkout_sessions/' . rawurlencode((string) $payment['checkout_session_id'])
            );
            $session = $response['data'] ?? null;
            if (!is_array($session) || !$this->sessionMatchesOrder($session, $order, $payment)) {
                throw new RuntimeException('The existing PayMongo checkout could not be verified. Please try again later.');
            }
            if ($this->hasPaidPayment($session)) {
                $this->applyVerifiedCheckout($session, null);
                return '/payments/return.php?order_id=' . $orderId;
            }
            $sessionStatus = (string) ($session['attributes']['status'] ?? '');
            if ($sessionStatus === 'active') {
                $url = (string) ($session['attributes']['checkout_url'] ?? $payment['checkout_url'] ?? '');
                if (paymongoValidateCheckoutUrl($url)) {
                    return $url;
                }
                throw new RuntimeException('The existing PayMongo checkout URL is invalid. Please contact support.');
            }
            if ($sessionStatus === 'expired') {
                $this->markExpired((int) $payment['payment_id'], $orderId, $userId);
                $order['payment_status'] = 'expired';
            } else {
                throw new RuntimeException('PayMongo returned an unexpected checkout status. Please try again later.');
            }
        }

        [$payment, $lineItems, $totalCents] = $this->preparePaymentAttempt($order, $userId);
        $methodType = 'gcash';
        $baseUrl = rtrim((string) ($this->config['APP_URL'] ?? ''), '/');
        $baseParts = parse_url($baseUrl);
        $isLocal = is_array($baseParts)
            && in_array(strtolower((string) ($baseParts['host'] ?? '')), ['localhost', '127.0.0.1'], true);
        if (!is_array($baseParts) || !isset($baseParts['host'])
            || (($baseParts['scheme'] ?? '') !== 'https' && !($isLocal && ($baseParts['scheme'] ?? '') === 'http'))) {
            throw new RuntimeException('APP_URL must be an HTTPS site URL (HTTP is allowed only for localhost sandbox testing).');
        }

        $returnUrl = $baseUrl . '/payments/return.php?order_id=' . $orderId;
        $payload = [
            'data' => [
                'attributes' => [
                    'line_items' => $lineItems,
                    'payment_method_types' => [$methodType],
                    'success_url' => $returnUrl,
                    'cancel_url' => $returnUrl . '&cancelled=1',
                    'reference_number' => (string) $order['order_number'],
                    'metadata' => [
                        'order_id' => (string) $orderId,
                        'order_number' => (string) $order['order_number'],
                        'customer_id' => (string) $userId,
                    ],
                    'pass_on_fees' => false,
                ],
            ],
        ];

        try {
            $response = paymongoApiRequest(
                $this->config,
                'POST',
                '/v2/checkout_sessions',
                $payload,
                (string) $payment['idempotency_key']
            );
        } catch (PaymongoApiException $e) {
            if (!$e->uncertain) {
                $this->markInitiationFailed((int) $payment['payment_id'], $orderId, $userId);
            }
            throw new RuntimeException(
                $e->uncertain
                    ? 'PayMongo did not confirm whether checkout was created. Retry from My Orders to safely resume the same request.'
                    : 'PayMongo could not start checkout. Your order is saved; retry from My Orders or contact support.',
                0,
                $e
            );
        }

        $session = $response['data'] ?? null;
        $sessionId = is_array($session) ? (string) ($session['id'] ?? '') : '';
        $attributes = is_array($session) && is_array($session['attributes'] ?? null)
            ? $session['attributes']
            : [];
        $checkoutUrl = (string) ($attributes['checkout_url'] ?? '');
        $validationFailures = [];
        if (!is_array($session)) {
            $validationFailures[] = 'session data';
        } else {
            if (($session['type'] ?? '') !== 'checkout_session') {
                $validationFailures[] = 'resource type';
            }
            if (!preg_match('/^cs_[A-Za-z0-9]+$/', $sessionId)) {
                $validationFailures[] = 'session ID format';
            }
            if (($attributes['livemode'] ?? null) !== false) {
                $validationFailures[] = 'sandbox mode';
            }
            if (isset($attributes['reference_number'])
                && (string) $attributes['reference_number'] !== (string) $order['order_number']) {
                $validationFailures[] = 'order reference';
            }
            if (!paymongoValidateCheckoutUrl($checkoutUrl)) {
                $validationFailures[] = 'checkout URL';
            }
        }
        if ($validationFailures) {
            error_log('PayMongo checkout response failed validation: ' . implode(', ', $validationFailures) . '.');
            throw new RuntimeException(
                'PayMongo response validation failed: ' . implode(', ', $validationFailures) . '. Your order remains pending.'
            );
        }

        $this->saveCheckoutSession($payment, $order, $userId, $sessionId, $checkoutUrl);
        return $checkoutUrl;
    }

    public function refreshPaymentStatus(int $orderId, int $userId): array
    {
        $this->assertReady();
        $order = $this->getOwnedOrder($orderId, $userId);
        $payment = $this->latestPayment($orderId);
        if (!$payment || empty($payment['checkout_session_id'])) {
            return ['payment_status' => $order['payment_status'], 'session_status' => null];
        }
        $response = paymongoApiRequest(
            $this->config,
            'GET',
            '/v2/checkout_sessions/' . rawurlencode((string) $payment['checkout_session_id'])
        );
        $session = $response['data'] ?? null;
        if (!is_array($session) || !$this->sessionMatchesOrder($session, $order, $payment)) {
            throw new RuntimeException('PayMongo could not verify this checkout. Its payment remains pending.');
        }
        if ($this->hasPaidPayment($session)) {
            $this->applyVerifiedCheckout($session, null);
        } elseif (($session['attributes']['status'] ?? '') === 'expired') {
            $this->markExpired((int) $payment['payment_id'], $orderId, $userId);
        }
        $fresh = $this->getOwnedOrder($orderId, $userId);
        return [
            'payment_status' => $fresh['payment_status'],
            'session_status' => (string) ($session['attributes']['status'] ?? 'unknown'),
        ];
    }

    public function cancelUnpaidOrder(int $orderId, int $userId): void
    {
        $this->assertReady();
        $order = $this->getOwnedOrder($orderId, $userId);
        if (!in_array($order['payment_method'], ['gcash', 'maya'], true)
            || $order['status'] !== 'pending' || $order['payment_status'] === 'paid') {
            throw new RuntimeException('Only unpaid, pending PayMongo orders can be cancelled here.');
        }

        $payment = $this->latestPayment($orderId);
        $expiredSessionId = null;
        if ($payment && !empty($payment['checkout_session_id'])) {
            $sessionPath = '/v2/checkout_sessions/' . rawurlencode((string) $payment['checkout_session_id']);
            try {
                $response = paymongoApiRequest($this->config, 'POST', $sessionPath . '/expire');
                $session = $response['data'] ?? null;
            } catch (PaymongoApiException $e) {
                if ($e->uncertain || $e->status < 400 || $e->status >= 500) {
                    throw new RuntimeException('PayMongo could not confirm cancellation. The order was left unchanged; refresh and try again.');
                }
                $response = paymongoApiRequest($this->config, 'GET', $sessionPath);
                $session = $response['data'] ?? null;
            }
            if (!is_array($session) || !$this->sessionMatchesOrder($session, $order, $payment)) {
                throw new RuntimeException('PayMongo could not verify this checkout. The order was left unchanged.');
            }
            if ($this->hasPaidPayment($session)) {
                $this->applyVerifiedCheckout($session, null);
                throw new RuntimeException('PayMongo confirmed payment, so this order was not cancelled.');
            }
            if (($session['attributes']['status'] ?? '') !== 'expired') {
                throw new RuntimeException('A payment is still in progress. Wait for PayMongo to confirm its outcome before cancelling.');
            }
            $expiredSessionId = (string) $payment['checkout_session_id'];
        }

        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT status, payment_status, payment_method FROM orders WHERE order_id = ? AND user_id = ? FOR UPDATE');
            $lock->execute([$orderId, $userId]);
            $lockedOrder = $lock->fetch();
            if (!$lockedOrder || $lockedOrder['status'] !== 'pending'
                || !in_array($lockedOrder['payment_method'], ['gcash', 'maya'], true)
                || $lockedOrder['payment_status'] === 'paid') {
                throw new RuntimeException('Order state changed while cancellation was being confirmed. Refresh My Orders.');
            }
            $paymentLock = $this->pdo->prepare(
                'SELECT payment_id, status, checkout_session_id FROM payments
                  WHERE order_id = ? AND gateway = ? ORDER BY payment_id DESC FOR UPDATE'
            );
            $paymentLock->execute([$orderId, 'paymongo']);
            $lockedPayments = $paymentLock->fetchAll();
            foreach ($lockedPayments as $lockedPayment) {
                if ($lockedPayment['status'] === 'paid') {
                    throw new RuntimeException('PayMongo confirmed payment or the payment attempt changed. The order was not cancelled.');
                }
                $lockedSessionId = (string) ($lockedPayment['checkout_session_id'] ?? '');
                if ($lockedSessionId !== ''
                    && !in_array($lockedPayment['status'], ['expired', 'cancelled'], true)
                    && $lockedSessionId !== $expiredSessionId) {
                    throw new RuntimeException('A checkout session changed while cancellation was being confirmed. Refresh and retry the verified cancellation.');
                }
            }
            $itemsStmt = $this->pdo->prepare('SELECT edition_id, quantity FROM order_items WHERE order_id = ? FOR UPDATE');
            $itemsStmt->execute([$orderId]);
            $restore = $this->pdo->prepare('UPDATE book_editions SET stock_quantity = stock_quantity + ? WHERE edition_id = ?');
            foreach ($itemsStmt->fetchAll() as $item) {
                $restore->execute([(int) $item['quantity'], (int) $item['edition_id']]);
            }
            $this->pdo->prepare("UPDATE orders SET status = 'cancelled', payment_status = 'cancelled' WHERE order_id = ?")
                ->execute([$orderId]);
            auditLogRequired($this->pdo, 'order_cancelled', 'Customer cancelled an unpaid PayMongo order.', 'orders', $userId, 'order', $orderId, 'success', 'customer');
            foreach ($lockedPayments as $lockedPayment) {
                if ($lockedPayment['status'] === 'pending') {
                    $this->pdo->prepare("UPDATE payments SET status = 'cancelled' WHERE payment_id = ? AND status = 'pending'")
                        ->execute([$lockedPayment['payment_id']]);
                    auditLogRequired($this->pdo, 'payment_cancelled', 'Customer cancelled an unpaid PayMongo checkout.', 'payments', $userId, 'payment', (int) $lockedPayment['payment_id'], 'info', 'customer');
                }
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function processVerifiedWebhook(array $event): string
    {
        $eventId = (string) ($event['data']['id'] ?? '');
        $attributes = $event['data']['attributes'] ?? null;
        if (!preg_match('/^evt_[A-Za-z0-9]+$/', $eventId) || !is_array($attributes)) {
            throw new UnexpectedValueException('Webhook event envelope is invalid.');
        }
        if (($attributes['type'] ?? '') !== 'checkout_session.payment.paid') {
            return 'ignored';
        }
        if (($attributes['livemode'] ?? null) !== false) {
            throw new UnexpectedValueException('A live-mode event was rejected by the sandbox endpoint.');
        }
        $session = $attributes['data'] ?? null;
        if (!is_array($session)) {
            throw new UnexpectedValueException('Webhook checkout data is missing.');
        }
        return $this->applyVerifiedCheckout($session, $eventId);
    }

    private function applyVerifiedCheckout(array $session, ?string $eventId): string
    {
        $sessionId = (string) ($session['id'] ?? '');
        $attrs = $session['attributes'] ?? [];
        if (($session['type'] ?? '') !== 'checkout_session'
            || !preg_match('/^cs_[A-Za-z0-9]+$/', $sessionId)
            || ($attrs['livemode'] ?? null) !== false) {
            throw new UnexpectedValueException('Checkout session is not a valid PayMongo test resource.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT p.payment_id, p.order_id, p.payment_method, p.status AS payment_status,
                    p.checkout_session_id, o.order_number, o.total_amount, o.payment_status AS order_payment_status
               FROM payments p JOIN orders o ON o.order_id = p.order_id
              WHERE p.gateway = ? AND p.checkout_session_id = ? LIMIT 1'
        );
        $stmt->execute(['paymongo', $sessionId]);
        $map = $stmt->fetch();
        if (!$map || (string) ($attrs['reference_number'] ?? '') !== (string) $map['order_number']) {
            throw new UnexpectedValueException('Checkout session did not match a local order.');
        }
        $metadata = $attrs['metadata'] ?? [];
        if (isset($metadata['order_id']) && (string) $metadata['order_id'] !== (string) $map['order_id']) {
            throw new UnexpectedValueException('Checkout order metadata did not match.');
        }
        $paidPayment = null;
        foreach (($attrs['payments'] ?? []) as $candidate) {
            if (($candidate['attributes']['status'] ?? '') === 'paid') {
                if ($paidPayment !== null) {
                    throw new UnexpectedValueException('Checkout contained more than one paid payment.');
                }
                $paidPayment = $candidate;
            }
        }
        $intent = $attrs['payment_intent']['attributes'] ?? [];
        if (!$paidPayment || !is_array($intent) || ($intent['status'] ?? '') !== 'succeeded'
            || (array_key_exists('livemode', $intent) && $intent['livemode'] !== false)) {
            throw new UnexpectedValueException('Checkout has no verified successful payment.');
        }
        $paymentAttrs = $paidPayment['attributes'] ?? [];
        $expectedCents = (int) round((float) $map['total_amount'] * 100);
        $currency = strtoupper((string) ($paymentAttrs['currency'] ?? ''));
        $amount = (int) ($paymentAttrs['amount'] ?? -1);
        $intentAmount = (int) ($intent['amount'] ?? -1);
        $lineItems = $attrs['line_items'] ?? [];
        $lineTotal = 0;
        foreach ($lineItems as $line) {
            if (strtoupper((string) ($line['currency'] ?? '')) !== 'PHP'
                || (int) ($line['amount'] ?? 0) < 0 || (int) ($line['quantity'] ?? 0) < 1) {
                throw new UnexpectedValueException('Checkout line item values are invalid.');
            }
            $lineTotal += (int) $line['amount'] * (int) $line['quantity'];
        }
        $sourceType = (string) ($paymentAttrs['source']['type'] ?? '');
        $expectedSource = $map['payment_method'] === 'maya' ? 'paymaya' : 'gcash';
        if ($currency !== 'PHP' || $amount !== $expectedCents || $intentAmount !== $expectedCents
            || $lineTotal !== $expectedCents || $sourceType !== $expectedSource
            || ($intent['currency'] ?? '') !== 'PHP'
            || (string) ($paymentAttrs['payment_intent_id'] ?? '') !== (string) ($attrs['payment_intent']['id'] ?? '')) {
            throw new UnexpectedValueException('Payment amount, currency, method, or order association did not match.');
        }

        $this->pdo->beginTransaction();
        try {
            // Lock in the same order as cancellation: order first, then payment attempt.
            $lockedOrderStmt = $this->pdo->prepare(
                'SELECT order_id, order_number, status, payment_method, payment_status, total_amount
                   FROM orders WHERE order_id = ? FOR UPDATE'
            );
            $lockedOrderStmt->execute([$map['order_id']]);
            $lockedOrder = $lockedOrderStmt->fetch();
            $lockedPaymentStmt = $this->pdo->prepare(
                'SELECT payment_id, order_id, payment_method, status AS payment_status, checkout_session_id, manual_review_required
                   FROM payments WHERE gateway = ? AND checkout_session_id = ? FOR UPDATE'
            );
            $lockedPaymentStmt->execute(['paymongo', $sessionId]);
            $lockedPayment = $lockedPaymentStmt->fetch();
            if (!$lockedOrder || !$lockedPayment
                || (int) $lockedPayment['order_id'] !== (int) $lockedOrder['order_id']
                || (string) $lockedOrder['order_number'] !== (string) ($attrs['reference_number'] ?? '')
                || (string) $lockedOrder['payment_method'] !== (string) $lockedPayment['payment_method']
                || (int) round((float) $lockedOrder['total_amount'] * 100) !== $expectedCents) {
                throw new UnexpectedValueException('Local checkout attempt disappeared.');
            }

            $reconciliationRequired = $lockedOrder['status'] === 'cancelled'
                || $lockedOrder['payment_status'] === 'cancelled'
                || in_array($lockedPayment['payment_status'], ['cancelled', 'expired', 'failed'], true);
            if ($eventId !== null) {
                try {
                    $this->pdo->prepare(
                        'INSERT INTO payment_webhook_events (event_id, event_type, payment_id, outcome)
                         VALUES (?, ?, ?, ?)'
                    )->execute([
                        $eventId,
                        'checkout_session.payment.paid',
                        $lockedPayment['payment_id'],
                        $reconciliationRequired ? 'manual_review' : 'processed',
                    ]);
                } catch (PDOException $e) {
                    if ((string) $e->getCode() === '23000') {
                        $this->pdo->rollBack();
                        return 'duplicate';
                    }
                    throw $e;
                }
            }

            $updatePayment = $this->pdo->prepare(
                "UPDATE payments SET status = 'paid', provider_payment_id = ?, provider_intent_id = ?,
                        gateway_transaction_id = ?, paid_at = COALESCE(paid_at, NOW()),
                        manual_review_required = GREATEST(manual_review_required, ?)
                  WHERE payment_id = ?"
            );
            $updatePayment->execute([
                (string) ($paidPayment['id'] ?? ''),
                (string) ($attrs['payment_intent']['id'] ?? ''),
                $sessionId,
                $reconciliationRequired ? 1 : 0,
                $lockedPayment['payment_id'],
            ]);
            if ($lockedOrder['payment_status'] !== 'paid') {
                // Record provider-confirmed money even if cancellation won the race.
                // Keep a cancelled order cancelled; the audit/event record requires manual resolution.
                $this->pdo->prepare("UPDATE orders SET payment_status = 'paid' WHERE order_id = ?")
                    ->execute([$lockedOrder['order_id']]);
            }
            if ($lockedPayment['payment_status'] !== 'paid' || $lockedOrder['payment_status'] !== 'paid'
                || ($reconciliationRequired && !(bool) $lockedPayment['manual_review_required'])) {
                auditLogRequired(
                    $this->pdo,
                    'payment_succeeded',
                    $reconciliationRequired
                        ? 'PayMongo confirmed payment after the order or payment attempt was cancelled/expired. Manual reconciliation is required; no refund was issued.'
                        : 'PayMongo confirmed the order payment.',
                    'payments',
                    null,
                    'payment',
                    (int) $lockedPayment['payment_id'],
                    $reconciliationRequired ? 'info' : 'success',
                    'system'
                );
            }
            if ($eventId !== null) {
                $this->pdo->prepare('UPDATE payment_webhook_events SET processed_at = NOW() WHERE event_id = ?')
                    ->execute([$eventId]);
            }
            $this->pdo->commit();
            return $reconciliationRequired ? 'manual_review' : 'processed';
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function getOwnedOrder(int $orderId, int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT order_id, user_id, order_number, status, total_amount, payment_method, payment_status
               FROM orders WHERE order_id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$orderId, $userId]);
        $order = $stmt->fetch();
        if (!$order) {
            throw new RuntimeException('Order not found.');
        }
        return $order;
    }

    private function latestPayment(int $orderId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM payments WHERE order_id = ? AND gateway = ? ORDER BY payment_id DESC LIMIT 1'
        );
        $stmt->execute([$orderId, 'paymongo']);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function preparePaymentAttempt(array $order, int $userId): array
    {
        $itemsStmt = $this->pdo->prepare(
            'SELECT b.title, oi.quantity, oi.unit_price
               FROM order_items oi
               JOIN book_editions e ON e.edition_id = oi.edition_id
               JOIN books b ON b.book_id = e.book_id
              WHERE oi.order_id = ? ORDER BY oi.order_item_id'
        );
        $itemsStmt->execute([$order['order_id']]);
        $items = $itemsStmt->fetchAll();
        if (!$items) {
            throw new RuntimeException('This order has no payable items.');
        }
        $lineItems = [];
        $sum = 0;
        foreach ($items as $item) {
            $unitCents = (int) round((float) $item['unit_price'] * 100);
            $qty = (int) $item['quantity'];
            $sum += $unitCents * $qty;
            $lineItems[] = [
                'name' => mb_substr((string) $item['title'], 0, 120),
                'amount' => $unitCents,
                'currency' => 'PHP',
                'quantity' => $qty,
            ];
        }
        $totalCents = $this->validateCheckoutAmount($order['total_amount']);
        if ($sum > $totalCents) {
            throw new RuntimeException('This order total is outside PayMongo sandbox limits or does not match its items.');
        }
        if ($sum < $totalCents) {
            $lineItems[] = [
                'name' => 'Shipping and other order charges',
                'amount' => $totalCents - $sum,
                'currency' => 'PHP',
                'quantity' => 1,
            ];
        }

        $this->pdo->beginTransaction();
        try {
            $lockOrder = $this->pdo->prepare('SELECT payment_status, status FROM orders WHERE order_id = ? AND user_id = ? FOR UPDATE');
            $lockOrder->execute([$order['order_id'], $userId]);
            $lockedOrder = $lockOrder->fetch();
            if (!$lockedOrder || $lockedOrder['status'] === 'cancelled' || $lockedOrder['payment_status'] === 'paid') {
                throw new RuntimeException('This order can no longer be paid.');
            }
            $last = $this->latestPayment((int) $order['order_id']);
            if ($last && empty($last['checkout_session_id']) && $last['status'] === 'pending' && !empty($last['idempotency_key'])) {
                $payment = $last;
            } else {
                $key = bin2hex(random_bytes(16)) . '-' . (int) $order['order_id'];
                $insert = $this->pdo->prepare(
                    "INSERT INTO payments
                        (order_id, gateway, payment_method, gateway_transaction_id, amount, status,
                         checkout_session_id, idempotency_key, currency)
                     VALUES (?, 'paymongo', ?, NULL, ?, 'pending', NULL, ?, 'PHP')"
                );
                $insert->execute([$order['order_id'], $order['payment_method'], $order['total_amount'], $key]);
                $payment = [
                    'payment_id' => (int) $this->pdo->lastInsertId(),
                    'order_id' => (int) $order['order_id'],
                    'payment_method' => $order['payment_method'],
                    'status' => 'pending',
                    'idempotency_key' => $key,
                ];
                $this->pdo->prepare("UPDATE orders SET payment_status = 'pending' WHERE order_id = ?")
                    ->execute([$order['order_id']]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [$payment, $lineItems, $totalCents];
    }

    private function validateCheckoutAmount($totalAmount): int
    {
        if (!is_numeric($totalAmount)) {
            throw new RuntimeException('This order total cannot be processed. Please contact support.');
        }

        $totalCents = (int) round((float) $totalAmount * 100);
        if ($totalCents < 100 || $totalCents > 999999999) {
            throw new RuntimeException('This order total is outside PayMongo sandbox limits.');
        }
        if ($totalCents > 10000000) {
            throw new RuntimeException('GCash and Maya checkout is limited to ₱100,000 per order. Choose cash on delivery or contact support.');
        }

        return $totalCents;
    }

    private function saveCheckoutSession(array $payment, array $order, int $userId, string $sessionId, string $checkoutUrl): void
    {
        $this->pdo->beginTransaction();
        try {
            $created = false;
            $stmt = $this->pdo->prepare(
                "UPDATE payments SET checkout_session_id = ?, checkout_url = ?, gateway_transaction_id = ?
                  WHERE payment_id = ? AND order_id = ? AND status = 'pending'"
            );
            try {
                $stmt->execute([$sessionId, $checkoutUrl, $sessionId, $payment['payment_id'], $order['order_id']]);
            } catch (PDOException $e) {
                if ((string) $e->getCode() === '23000') {
                    error_log('PayMongo returned a checkout session already linked to another payment attempt.');
                    throw new RuntimeException(
                        'PayMongo returned a checkout session that is already linked to another order. This order remains unpaid; contact support before retrying.',
                        0,
                        $e
                    );
                }
                throw $e;
            }
            $created = $stmt->rowCount() === 1;
            if ($stmt->rowCount() !== 1) {
                $check = $this->pdo->prepare('SELECT checkout_session_id FROM payments WHERE payment_id = ? AND order_id = ?');
                $check->execute([$payment['payment_id'], $order['order_id']]);
                if ($check->fetchColumn() !== $sessionId) {
                    throw new RuntimeException('The checkout session could not be saved to the order.');
                }
            }
            if ($created) {
                auditLogRequired(
                    $this->pdo,
                    'payment_initiated',
                    'PayMongo hosted checkout session created for an order.',
                    'payments',
                    $userId,
                    'payment',
                    (int) $payment['payment_id'],
                    'info',
                    'customer'
                );
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function sessionMatchesOrder(array $session, array $order, array $payment): bool
    {
        $attrs = $session['attributes'] ?? [];
        return ($session['type'] ?? '') === 'checkout_session'
            && ($session['id'] ?? '') === ($payment['checkout_session_id'] ?? '')
            && ($attrs['livemode'] ?? null) === false
            && (string) ($attrs['reference_number'] ?? '') === (string) $order['order_number'];
    }

    private function hasPaidPayment(array $session): bool
    {
        foreach (($session['attributes']['payments'] ?? []) as $payment) {
            if (($payment['attributes']['status'] ?? '') === 'paid') {
                return true;
            }
        }
        return false;
    }

    private function markExpired(int $paymentId, int $orderId, int $userId): void
    {
        $this->pdo->beginTransaction();
        try {
            $orderStmt = $this->pdo->prepare('SELECT status, payment_status FROM orders WHERE order_id = ? FOR UPDATE');
            $orderStmt->execute([$orderId]);
            $order = $orderStmt->fetch();
            $stmt = $this->pdo->prepare('SELECT status FROM payments WHERE payment_id = ? AND order_id = ? FOR UPDATE');
            $stmt->execute([$paymentId, $orderId]);
            $payment = $stmt->fetch();
            if ($order && $payment && $order['status'] !== 'cancelled'
                && !in_array($payment['status'], ['paid', 'expired', 'cancelled'], true)
                && !in_array($order['payment_status'], ['paid', 'cancelled'], true)) {
                $this->pdo->prepare("UPDATE payments SET status = 'expired' WHERE payment_id = ?")
                    ->execute([$paymentId]);
                $this->pdo->prepare("UPDATE orders SET payment_status = 'expired' WHERE order_id = ? AND payment_status <> 'paid'")
                    ->execute([$orderId]);
                auditLogRequired(
                    $this->pdo,
                    'payment_expired',
                    'PayMongo reported that the checkout session expired.',
                    'payments',
                    0,
                    'payment',
                    $paymentId,
                    'info',
                    'system'
                );
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function markInitiationFailed(int $paymentId, int $orderId, int $userId): void
    {
        $this->pdo->beginTransaction();
        try {
            $markedFailed = false;
            $orderStmt = $this->pdo->prepare('SELECT payment_status FROM orders WHERE order_id = ? FOR UPDATE');
            $orderStmt->execute([$orderId]);
            $order = $orderStmt->fetch();
            $paymentStmt = $this->pdo->prepare('SELECT status, checkout_session_id FROM payments WHERE payment_id = ? AND order_id = ? FOR UPDATE');
            $paymentStmt->execute([$paymentId, $orderId]);
            $payment = $paymentStmt->fetch();
            if ($order && $payment && $order['payment_status'] !== 'paid'
                && $payment['status'] === 'pending' && empty($payment['checkout_session_id'])) {
                $this->pdo->prepare("UPDATE payments SET status = 'failed' WHERE payment_id = ?")
                    ->execute([$paymentId]);
                $this->pdo->prepare("UPDATE orders SET payment_status = 'failed' WHERE order_id = ?")
                    ->execute([$orderId]);
                $markedFailed = true;
            }
            if ($markedFailed) {
                auditLogRequired(
                    $this->pdo,
                    'payment_failed',
                    'PayMongo rejected checkout session creation.',
                    'payments',
                    0,
                    'payment',
                    $paymentId,
                    'failure',
                    'system'
                );
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('Unable to persist PayMongo initiation failure.');
        }
    }
}