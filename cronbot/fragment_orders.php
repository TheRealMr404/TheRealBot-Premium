<?php

chdir(dirname(__DIR__));
require_once 'config.php';
require_once 'botapi.php';
require_once 'jdf.php';
require_once 'function.php';
require_once 'telegram_products.php';
require_once 'telegram_products_features.php';
require_once 'telegram_products_admin.php';
require_once 'telegram_fragment.php';

try {
    $setting = select('setting', '*');
    telegramProductsEnsureSchema();
    telegramFragmentEnsureSchema();
    // سفارش‌های پرداخت‌شده اولویت دارند؛ دریافت نرخ (که ممکن است کند یا ناموفق باشد) بعد از آن‌ها انجام می‌شود.
    telegramFragmentProcessPendingOrders(5);
    try {
        telegramFragmentRefreshRateIfDue();
    } catch (Throwable $e) {
        telegramFragmentLogFailure('scheduled Nobitex rate', $e);
    }
} catch (Throwable $e) {
    error_log('Fragment cron worker failed: ' . $e->getMessage());
    exit(1);
}
