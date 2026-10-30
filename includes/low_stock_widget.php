<?php
?>
<section class="admin-low-stock-panel" aria-labelledby="low-stock-title">
    <div class="admin-low-stock-heading">
        <div>
            <p class="admin-eyebrow">Inventory pulse</p>
            <h2 id="low-stock-title">Low-Stock Watch</h2>
        </div>
        <?php if ($lowStockReady): ?><span class="admin-alert-count <?= $activeLowStockCount > 0 ? 'has-alerts' : '' ?>"><?= number_format($activeLowStockCount) ?> active</span><?php endif; ?>
    </div>
    <p class="admin-low-stock-copy"><?php if (!$lowStockReady): ?>Apply the low-stock alerts migration to enable inventory notifications.<?php elseif ($activeLowStockCount === 0): ?>All editions are above their restock thresholds.<?php else: ?>Editions below their restock threshold are listed here.<?php endif; ?></p>
    <?php if ($lowStockReady && $activeLowStockItems): ?>
        <ul class="admin-low-stock-list">
            <?php foreach ($activeLowStockItems as $lowItem): ?>
                <li>
                    <div class="admin-low-stock-book">
                        <strong><?= htmlspecialchars((string) $lowItem['book_title'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <small><?= htmlspecialchars(ucfirst((string) $lowItem['edition_format']), ENT_QUOTES, 'UTF-8') ?> · threshold <?= (int) $lowItem['threshold_at_detection'] ?></small>
                    </div>
                    <span class="admin-stock-count <?= (int) $lowItem['current_stock'] === 0 ? 'is-empty' : '' ?>"><?= (int) $lowItem['current_stock'] ?> left</span>
                    <a href="/admin/products.php?edit=<?= (int) $lowItem['edition_id'] ?>">Restock</a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php elseif ($lowStockReady): ?>
        <div class="admin-stock-clear"><span aria-hidden="true">✓</span><p>No editions need restocking right now.</p></div>
    <?php endif; ?>
    <a class="admin-low-stock-link" href="/admin/low_stock.php">View all inventory alerts <span aria-hidden="true">→</span></a>
</section>
