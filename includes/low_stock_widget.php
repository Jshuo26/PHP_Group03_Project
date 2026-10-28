<?php
?>
<div class="card" style="margin:20px 0;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
        <div>
            <h2 class="card-title">Low-stock alerts<?php if ($lowStockReady): ?> <span style="font-size:.85em;">(<?= number_format($activeLowStockCount) ?> active)</span><?php endif; ?></h2>
            <p class="card-text"><?php if (!$lowStockReady): ?>Apply the low-stock alerts migration to enable inventory notifications.<?php elseif ($activeLowStockCount === 0): ?>All editions are above the configured threshold.<?php else: ?>Review editions that need replenishment.<?php endif; ?></p>
        </div>
        <a class="btn btn-secondary" href="/admin/low_stock.php">View inventory alerts</a>
    </div>
    <?php if ($lowStockReady && $activeLowStockItems): ?>
        <ul style="margin:16px 0 0;padding-left:22px;">
            <?php foreach ($activeLowStockItems as $lowItem): ?>
                <li style="margin:8px 0;"><strong><?= htmlspecialchars((string) $lowItem['book_title'], ENT_QUOTES, 'UTF-8') ?></strong> · <?= htmlspecialchars(ucfirst((string) $lowItem['edition_format']), ENT_QUOTES, 'UTF-8') ?> · <?= (int) $lowItem['current_stock'] ?> left (threshold <?= (int) $lowItem['threshold_at_detection'] ?>) · <a href="/admin/products.php?edit=<?= (int) $lowItem['edition_id'] ?>">Restock</a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
