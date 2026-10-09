<?php

const TELEGRAM_PRODUCTS_BUTTON = 'خدمات مجازی';

require_once __DIR__ . '/telegram_products_identity.php';

function telegramProductsEnsureColumn($table, $column, $definition)
{
    global $pdo;

    $allowed = [
        'telegram_product_categories',
        'telegram_products',
        'telegram_product_stock',
        'telegram_product_orders',
        'telegram_product_fields',
        'telegram_product_discounts',
        'telegram_product_identity',
    ];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException('Invalid virtual services table.');
    }
    $quotedColumn = $pdo->quote($column);
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$quotedColumn}");
    if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
        try {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        } catch (PDOException $e) {
            $mysqlCode = (int) ($e->errorInfo[1] ?? 0);
            if ($mysqlCode !== 1060) {
                throw $e;
            }
        }
    }
}

function telegramProductsEnsureSchema()
{
    global $pdo;

    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_product_categories (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        button_style VARCHAR(20) NOT NULL DEFAULT 'primary',
        button_emoji_id VARCHAR(30) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_products (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        category_id INT UNSIGNED NOT NULL,
        title VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        description TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        price BIGINT UNSIGNED NOT NULL,
        delivery_type VARCHAR(20) NOT NULL DEFAULT 'manual',
        input_label VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        agent_scope VARCHAR(50) NOT NULL DEFAULT 'all',
        button_style VARCHAR(20) NOT NULL DEFAULT 'success',
        button_emoji_id VARCHAR(30) NULL,
        low_stock_threshold INT UNSIGNED NOT NULL DEFAULT 3,
        max_per_user INT UNSIGNED NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tg_products_category (category_id, is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_product_groups (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        category_id INT UNSIGNED NOT NULL,
        title VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        description TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        button_style VARCHAR(20) NOT NULL DEFAULT 'primary',
        button_emoji_id VARCHAR(30) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tg_groups_category (category_id, is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_product_stock (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id INT UNSIGNED NOT NULL,
        payload TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'available',
        sold_to VARCHAR(200) NULL,
        order_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        sold_at DATETIME NULL,
        INDEX idx_tg_stock_available (product_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_product_orders (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(200) NOT NULL,
        product_id INT UNSIGNED NOT NULL,
        product_title VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        price BIGINT UNSIGNED NOT NULL,
        delivery_type VARCHAR(20) NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        customer_input TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        delivery_payload TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        paid_at DATETIME NULL,
        delivered_at DATETIME NULL,
        refunded_at DATETIME NULL,
        INDEX idx_tg_orders_user (user_id, created_at),
        INDEX idx_tg_orders_status (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_product_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_product_fields (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id INT UNSIGNED NOT NULL,
        label VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        field_type VARCHAR(20) NOT NULL DEFAULT 'text',
        is_required TINYINT(1) NOT NULL DEFAULT 1,
        min_length INT UNSIGNED NOT NULL DEFAULT 0,
        max_length INT UNSIGNED NOT NULL DEFAULT 500,
        validation_pattern VARCHAR(500) NULL,
        options_json TEXT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tg_fields_product (product_id, is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_product_discounts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(80) NOT NULL UNIQUE,
        discount_type VARCHAR(20) NOT NULL DEFAULT 'percent',
        discount_value BIGINT UNSIGNED NOT NULL,
        max_discount BIGINT UNSIGNED NOT NULL DEFAULT 0,
        min_purchase BIGINT UNSIGNED NOT NULL DEFAULT 0,
        product_id INT UNSIGNED NULL,
        group_id INT UNSIGNED NULL,
        category_id INT UNSIGNED NULL,
        usage_limit INT UNSIGNED NOT NULL DEFAULT 0,
        per_user_limit INT UNSIGNED NOT NULL DEFAULT 1,
        used_count INT UNSIGNED NOT NULL DEFAULT 0,
        starts_at DATETIME NULL,
        expires_at DATETIME NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tg_discount_active (code, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_product_discount_redemptions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        discount_id INT UNSIGNED NOT NULL,
        order_id BIGINT UNSIGNED NOT NULL UNIQUE,
        user_id VARCHAR(200) NOT NULL,
        amount BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tg_discount_user (discount_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_product_loyalty (
        user_id VARCHAR(200) PRIMARY KEY,
        points BIGINT UNSIGNED NOT NULL DEFAULT 0,
        lifetime_earned BIGINT UNSIGNED NOT NULL DEFAULT 0,
        lifetime_spent BIGINT UNSIGNED NOT NULL DEFAULT 0,
        updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_product_warranties (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED NOT NULL,
        user_id VARCHAR(200) NOT NULL,
        reason TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        admin_id VARCHAR(200) NULL,
        admin_note TEXT NULL,
        replacement_payload TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved_at DATETIME NULL,
        INDEX idx_tg_warranty_status (status, created_at),
        INDEX idx_tg_warranty_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_product_deliveries (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED NOT NULL,
        delivery_type VARCHAR(30) NOT NULL,
        payload TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        admin_id VARCHAR(200) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tg_delivery_order (order_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_product_admin_roles (
        admin_id VARCHAR(200) PRIMARY KEY,
        role_key VARCHAR(30) NOT NULL DEFAULT 'manager',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_product_identity (
        user_id VARCHAR(64) CHARACTER SET ascii PRIMARY KEY,
        phone VARCHAR(20) NULL,
        phone_verified_at DATETIME NULL,
        full_name VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        national_id_last4 CHAR(4) NULL,
        document_file_id VARCHAR(255) NULL,
        document_kind VARCHAR(10) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'none',
        submitted_at DATETIME NULL,
        reviewed_at DATETIME NULL,
        reviewer_id VARCHAR(200) NULL,
        INDEX idx_tg_identity_review (status, submitted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    telegramProductsEnsureColumn('telegram_products', 'input_label', "VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER `delivery_type`");
    telegramProductsEnsureColumn('telegram_products', 'group_id', "INT UNSIGNED NULL AFTER `category_id`");
    telegramProductsEnsureColumn('telegram_products', 'agent_scope', "VARCHAR(50) NOT NULL DEFAULT 'all' AFTER `input_label`");
    telegramProductsEnsureColumn('telegram_product_categories', 'button_style', "VARCHAR(20) NOT NULL DEFAULT 'primary' AFTER `title`");
    telegramProductsEnsureColumn('telegram_product_categories', 'button_emoji_id', "VARCHAR(30) NULL AFTER `button_style`");
    telegramProductsEnsureColumn('telegram_products', 'button_style', "VARCHAR(20) NOT NULL DEFAULT 'success' AFTER `agent_scope`");
    telegramProductsEnsureColumn('telegram_products', 'button_emoji_id', "VARCHAR(30) NULL AFTER `button_style`");
    telegramProductsEnsureColumn('telegram_products', 'low_stock_threshold', "INT UNSIGNED NOT NULL DEFAULT 3 AFTER `button_emoji_id`");
    telegramProductsEnsureColumn('telegram_products', 'max_per_user', "INT UNSIGNED NOT NULL DEFAULT 0 AFTER `low_stock_threshold`");
    telegramProductsEnsureColumn('telegram_products', 'product_mode', "VARCHAR(20) NOT NULL DEFAULT 'legacy' AFTER `delivery_type`");
    telegramProductsEnsureColumn('telegram_products', 'auth_mode', "VARCHAR(20) NOT NULL DEFAULT 'none' AFTER `product_mode`");
    telegramProductsEnsureColumn('telegram_product_identity', 'document_kind', 'VARCHAR(10) NULL AFTER document_file_id');
    telegramProductsEnsureColumn('telegram_products', 'warranty_days', "INT UNSIGNED NOT NULL DEFAULT 0 AFTER `max_per_user`");
    telegramProductsEnsureColumn('telegram_products', 'max_resends', "INT UNSIGNED NOT NULL DEFAULT 1 AFTER `warranty_days`");
    // Older installations may already have these tables with an incomplete
    // schema. CREATE TABLE IF NOT EXISTS does not add missing core columns.
    telegramProductsEnsureColumn('telegram_product_stock', 'product_id', 'INT UNSIGNED NULL');
    telegramProductsEnsureColumn('telegram_product_fields', 'product_id', 'INT UNSIGNED NULL');
    telegramProductsEnsureColumn('telegram_product_orders', 'product_id', 'INT UNSIGNED NULL');
    telegramProductsEnsureColumn('telegram_product_discounts', 'product_id', 'INT UNSIGNED NULL');
    telegramProductsEnsureColumn('telegram_product_orders', 'customer_input', "TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER `status`");
    telegramProductsEnsureColumn('telegram_product_orders', 'refunded_at', "DATETIME NULL AFTER `delivered_at`");
    telegramProductsEnsureColumn('telegram_product_orders', 'original_price', "BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `price`");
    telegramProductsEnsureColumn('telegram_product_orders', 'discount_amount', "BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `original_price`");
    telegramProductsEnsureColumn('telegram_product_orders', 'discount_code', "VARCHAR(80) NULL AFTER `discount_amount`");
    telegramProductsEnsureColumn('telegram_product_orders', 'points_used', "BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `discount_code`");
    telegramProductsEnsureColumn('telegram_product_orders', 'points_earned', "BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `points_used`");
    telegramProductsEnsureColumn('telegram_product_orders', 'resend_count', "INT UNSIGNED NOT NULL DEFAULT 0 AFTER `points_earned`");
    telegramProductsEnsureColumn('telegram_product_orders', 'warranty_until', "DATETIME NULL AFTER `refunded_at`");
    telegramProductsEnsureColumn('telegram_product_orders', 'pending_alerted_at', "DATETIME NULL AFTER `warranty_until`");
    telegramProductsEnsureColumn('telegram_product_discounts', 'group_id', "INT UNSIGNED NULL AFTER `product_id`");
    $pdo->exec("UPDATE telegram_products SET product_mode = IF(delivery_type = 'auto', 'stock', 'form') WHERE product_mode = 'legacy'");
    $pdo->exec('UPDATE telegram_product_orders SET original_price = price WHERE original_price = 0 AND price > 0');

    $defaults = [
        'enabled' => '1',
        'store_title' => 'فروشگاه خدمات مجازی',
        'home_text' => 'از فهرست زیر دسته خدمات موردنظر را انتخاب کنید.',
        'category_text' => 'محصول موردنظر را انتخاب کنید.',
        'checkout_text' => 'لطفاً اطلاعات سفارش را بررسی و پرداخت را تأیید کنید.',
        'manual_pending_text' => 'پرداخت انجام شد و سفارش برای بررسی و تحویل ادمین ثبت شد.',
        'auto_success_text' => 'خرید با موفقیت انجام شد و محصول شما آماده است.',
        'out_of_stock_text' => 'موجودی این محصول در حال حاضر به پایان رسیده است.',
        'disabled_text' => 'بخش خدمات مجازی در حال حاضر غیرفعال است.',
        'loyalty_enabled' => '1',
        'loyalty_spend_per_point' => '10000',
        'loyalty_point_value' => '1000',
        'loyalty_max_percent' => '20',
        'pending_alert_hours' => '3',
        'daily_summary_enabled' => '1',
        'invoice_price_lock_minutes' => '10',
    ];
    $stmt = $pdo->prepare('INSERT IGNORE INTO telegram_product_settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ($defaults as $key => $value) {
        $stmt->execute([$key, $value]);
    }
    try {
        $pdo->prepare("INSERT IGNORE INTO textbot (id_text, text) VALUES ('text_virtual_services', 'خدمات مجازی')")->execute();
    } catch (Throwable $e) {
        error_log('Virtual services text migration skipped: ' . $e->getMessage());
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS topicid (
            report VARCHAR(500) PRIMARY KEY NOT NULL,
            idreport TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->prepare("INSERT IGNORE INTO topicid (report, idreport) VALUES ('virtualservices', '0'), ('virtualservices_error', '0'), ('virtualservices_alerts', '0')")->execute();
    } catch (Throwable $e) {
        error_log('Virtual services topic migration skipped: ' . $e->getMessage());
    }

    $ready = true;
}

function telegramProductsSetting($key, $default = '')
{
    global $pdo;

    telegramProductsEnsureSchema();

    $stmt = $pdo->prepare('SELECT setting_value FROM telegram_product_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function telegramProductsSetSetting($key, $value)
{
    global $pdo;

    telegramProductsEnsureSchema();
    $stmt = $pdo->prepare('INSERT INTO telegram_product_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->execute([$key, $value]);
}

function telegramProductsInvoicePriceLocked(array $order, $minutes = null)
{
    if ($minutes === null) {
        $minutes = (int) telegramProductsSetting('invoice_price_lock_minutes', '10');
    }
    $minutes = max(1, min(60, (int) $minutes));
    $createdAt = strtotime((string) ($order['created_at'] ?? ''));
    return $createdAt !== false && $createdAt >= time() - ($minutes * 60);
}

function telegramProductsButtonText()
{
    global $datatextbot;

    return !empty($datatextbot['text_virtual_services'])
        ? (string) $datatextbot['text_virtual_services']
        : TELEGRAM_PRODUCTS_BUTTON;
}

function telegramProductsEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function telegramProductsActorIsPremium()
{
    global $update;

    $from = null;
    if (is_array($update)) {
        $from = $update['callback_query']['from'] ?? $update['message']['from'] ?? $update['edited_message']['from'] ?? null;
    } elseif (is_object($update)) {
        $from = $update->callback_query->from ?? $update->message->from ?? $update->edited_message->from ?? null;
    }
    if (is_array($from)) {
        return !empty($from['is_premium']);
    }
    return is_object($from) && !empty($from->is_premium);
}

function telegramProductsWithoutPremiumEmoji($value)
{
    return preg_replace('/<tg-emoji\s+emoji-id=["\']\d{5,30}["\']>.*?<\/tg-emoji>/us', '', (string) $value);
}

function telegramProductsSafeCustomText($value)
{
    $value = (string) $value;
    if (!telegramProductsActorIsPremium()) {
        return telegramProductsEscape(telegramProductsWithoutPremiumEmoji($value));
    }
    $tokens = [];
    $value = preg_replace_callback('/<tg-emoji\s+emoji-id=["\'](\d{5,30})["\']>(.*?)<\/tg-emoji>/us', function ($match) use (&$tokens) {
        $token = '%%TG_EMOJI_' . count($tokens) . '%%';
        $tokens[$token] = '<tg-emoji emoji-id="' . $match[1] . '">' . telegramProductsEscape(strip_tags($match[2])) . '</tg-emoji>';
        return $token;
    }, $value);
    $value = telegramProductsEscape($value);
    foreach ($tokens as $token => $html) {
        $value = str_replace($token, $html, $value);
    }
    return $value;
}

function telegramProductsPlainText($value)
{
    $value = preg_replace('/<tg-emoji\s+emoji-id=["\']\d+["\']>(.*?)<\/tg-emoji>/us', '$1', (string) $value);
    return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function telegramProductsStyledButton($text, $callbackData, $style = null, $emojiId = null)
{
    $button = [
        'text' => telegramProductsPlainText($text),
        'callback_data' => (string) $callbackData,
    ];
    if (in_array($style, ['primary', 'success', 'danger'], true)) {
        $button['style'] = $style;
    }
    if (telegramProductsActorIsPremium() && preg_match('/^\d{5,30}$/', (string) $emojiId)) {
        $button['icon_custom_emoji_id'] = (string) $emojiId;
    }
    return $button;
}

function telegramProductsActionButton($text, $callbackData, $style = 'primary', $slot = 'action')
{
    $emoji = [
        'primary' => '5280962371207077415',
        'success' => '5350481089817232086',
        'action' => '5348090777308251395',
        'navigation' => '5348418461838098123',
    ][$slot] ?? '5348090777308251395';
    return telegramProductsStyledButton($text, $callbackData, $style, $emoji);
}

function telegramProductsPublicFailureReason($error)
{
    $message = mb_strtolower((string) ($error instanceof Throwable ? $error->getMessage() : $error), 'UTF-8');
    if (preg_match('/موجودی.*(تمام|پایان)|out.of.stock|stock/', $message)) {
        return 'موجودی محصول به پایان رسیده است.';
    }
    if (preg_match('/موجودی|balance|insufficient|credit/', $message)) {
        return 'موجودی کیف پول برای انجام این خرید کافی نیست.';
    }
    if (preg_match('/قیمت|price|غیرفعال|inactive|تغییر کرده/', $message)) {
        return 'اطلاعات یا قیمت محصول تغییر کرده است؛ لطفاً سفارش تازه‌ای ثبت کنید.';
    }
    if (preg_match('/سقف|limit|max|تعداد مجاز/', $message)) {
        return 'سقف مجاز این خرید تکمیل شده است.';
    }
    if (preg_match('/نام کاربری|username|recipient|گیرنده/', $message)) {
        return 'اطلاعات گیرنده معتبر نیست یا حساب موردنظر پیدا نشد.';
    }
    if (preg_match('/timeout|timed out|network|connection|اتصال|شبکه|temporar|database|sql|server|http/', $message)) {
        return 'پردازش سفارش به‌دلیل اختلال موقت سرویس کامل نشد.';
    }
    return 'پردازش سفارش در این لحظه کامل نشد.';
}

function telegramProductsFailureCard($reason, $orderId = null, $moneySafe = true)
{
    $text = "<b>خرید تکمیل نشد</b>\n\n<blockquote><b>دلیل:</b> " . telegramProductsEscape($reason);
    if ($orderId !== null) {
        $text .= "\n<b>شماره پیگیری:</b> <code>#" . (int) $orderId . '</code>';
    }
    $text .= '</blockquote>';
    if ($moneySafe) {
        $text .= "\n\nمبلغی از کیف پول شما کسر نشد.";
    }
    return $text;
}

function telegramProductsMoney($amount)
{
    return number_format((int) $amount, 0) . ' تومان';
}

function telegramProductsIsAdmin($userId)
{
    global $admin_ids;

    $adminIds = array_map('strval', is_array($admin_ids) ? $admin_ids : []);
    return in_array((string) $userId, $adminIds, true);
}

function telegramProductsApiSucceeded($response)
{
    return is_array($response) && !empty($response['ok']);
}

function telegramProductsCompatibleMarkup($replyMarkup)
{
    if ($replyMarkup === null || $replyMarkup === '') {
        return $replyMarkup;
    }
    $markup = is_string($replyMarkup) ? json_decode($replyMarkup, true) : $replyMarkup;
    if (!is_array($markup)) {
        return $replyMarkup;
    }
    foreach (($markup['inline_keyboard'] ?? []) as $rowIndex => $row) {
        foreach ((array) $row as $buttonIndex => $button) {
            if (!is_array($button)) {
                continue;
            }
            unset($button['style'], $button['icon_custom_emoji_id']);
            $markup['inline_keyboard'][$rowIndex][$buttonIndex] = $button;
        }
    }
    return json_encode($markup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function telegramProductsEnsureReportTopic($reportKey, $topicName, $force = false)
{
    global $pdo, $setting;

    $channelId = (string) ($setting['Channel_Report'] ?? '');
    if ($channelId === '' || $channelId === '0') {
        return 0;
    }
    $stmt = $pdo->prepare('INSERT IGNORE INTO topicid (report, idreport) VALUES (?, ?)');
    $stmt->execute([$reportKey, '0']);
    $stmt = $pdo->prepare('SELECT idreport FROM topicid WHERE report = ?');
    $stmt->execute([$reportKey]);
    $threadId = (int) $stmt->fetchColumn();
    if ($threadId > 0) {
        return $threadId;
    }
    if ($force && $threadId < 0) {
        $stmt = $pdo->prepare("UPDATE topicid SET idreport = '0' WHERE report = ?");
        $stmt->execute([$reportKey]);
        $threadId = 0;
    }
    if ($threadId < 0) {
        return 0;
    }

    $response = telegram('createForumTopic', [
        'chat_id' => $channelId,
        'name' => $topicName,
    ]);
    $createdId = (int) ($response['result']['message_thread_id'] ?? 0);
    $stmt = $pdo->prepare('UPDATE topicid SET idreport = ? WHERE report = ?');
    $stmt->execute([$createdId > 0 ? (string) $createdId : '-1', $reportKey]);
    return $createdId;
}

function telegramProductsReport($type, $text)
{
    global $setting;

    try {
        $isError = in_array($type, ['error', 'stock'], true);
        $isAlert = $type === 'alert';
        $reportKey = $isAlert ? 'virtualservices_alerts' : ($isError ? 'virtualservices_error' : 'virtualservices');
        $topicName = $isAlert ? 'هشدارهای خدمات مجازی' : ($isError ? 'خطاهای خدمات مجازی' : 'خدمات مجازی');
        $threadId = telegramProductsEnsureReportTopic(
            $reportKey,
            $topicName
        );
        $channelId = (string) ($setting['Channel_Report'] ?? '');
        if ($threadId < 1 || $channelId === '' || $channelId === '0') {
            return false;
        }
        $response = telegram('sendMessage', [
            'chat_id' => $channelId,
            'message_thread_id' => $threadId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);
        if (!is_array($response) || empty($response['ok'])) {
            global $pdo;
            $stmt = $pdo->prepare("UPDATE topicid SET idreport = '0' WHERE report = ?");
            $stmt->execute([$reportKey]);
            error_log('Virtual services topic report was rejected: ' . json_encode($response, JSON_UNESCAPED_UNICODE));
            return false;
        }
        return true;
    } catch (Throwable $e) {
        error_log('Virtual services report failed: ' . $e->getMessage());
        return false;
    }
}

function telegramProductsReply($text, $replyMarkup = null, $preferEdit = true)
{
    global $from_id, $message_id, $datain;

    if ($preferEdit && $datain !== '' && intval($message_id) > 0) {
        $response = Editmessagetext($from_id, $message_id, $text, $replyMarkup, 'HTML');
        if (telegramProductsApiSucceeded($response)) {
            return $response;
        }
        $compatibleMarkup = telegramProductsCompatibleMarkup($replyMarkup);
        if ($compatibleMarkup !== $replyMarkup) {
            $response = Editmessagetext($from_id, $message_id, $text, $compatibleMarkup, 'HTML');
            if (telegramProductsApiSucceeded($response)) {
                return $response;
            }
        }
        $description = is_array($response) ? (string) ($response['description'] ?? '') : '';
        if (stripos($description, 'message is not modified') !== false) {
            return $response;
        }
        return sendmessage($from_id, $text, $compatibleMarkup ?? $replyMarkup, 'HTML');
    }

    $response = sendmessage($from_id, $text, $replyMarkup, 'HTML');
    if (telegramProductsApiSucceeded($response)) {
        return $response;
    }
    $compatibleMarkup = telegramProductsCompatibleMarkup($replyMarkup);
    if ($compatibleMarkup !== $replyMarkup) {
        return sendmessage($from_id, $text, $compatibleMarkup, 'HTML');
    }
    return $response;
}

function telegramProductsShowHome()
{
    global $pdo, $user;

    $stmt = $pdo->prepare("SELECT c.id, c.title, c.button_style, c.button_emoji_id, COUNT(p.id) AS product_count
        FROM telegram_product_categories c
        LEFT JOIN telegram_products p ON p.category_id = c.id AND p.is_active = 1
            AND (p.agent_scope = 'all' OR FIND_IN_SET(?, p.agent_scope) > 0)
        WHERE c.is_active = 1
        GROUP BY c.id, c.title, c.button_style, c.button_emoji_id, c.sort_order
        HAVING COUNT(p.id) > 0
        ORDER BY c.sort_order, c.id");
    $stmt->execute([$user['agent'] ?? 'f']);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rows = [];
    foreach ($categories as $category) {
        $rows[] = [telegramProductsStyledButton(
            $category['title'] . ' (' . $category['product_count'] . ')',
            'tgp_cat_' . $category['id'],
            $category['button_style'],
            $category['button_emoji_id']
        )];
    }
    if (function_exists('telegramFragmentAddHomeButton')) {
        telegramFragmentAddHomeButton($rows);
    }
    $rows[] = [telegramProductsActionButton('سفارش‌های من', 'tgp_orders', 'primary', 'action')];
    $rows[] = [telegramProductsActionButton('بازگشت به منوی اصلی', 'tgp_main', 'danger', 'navigation')];

    $text = '<b>' . telegramProductsSafeCustomText(telegramProductsSetting('store_title', telegramProductsButtonText())) . "</b>\n\n";
    $text .= telegramProductsSafeCustomText(telegramProductsSetting('home_text', 'دسته موردنظر را انتخاب کنید.'));
    $fragmentEnabled = function_exists('telegramFragmentSetting') && telegramFragmentSetting('enabled', '0') === '1';
    if (!$categories && !$fragmentEnabled) {
        $text = '<b>' . telegramProductsSafeCustomText(telegramProductsSetting('store_title', telegramProductsButtonText())) . "</b>\n\nدر حال حاضر محصول فعالی ثبت نشده است.";
    }

    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramProductsShowCategory($categoryId)
{
    global $pdo, $user;

    $stmt = $pdo->prepare('SELECT id, title, button_style, button_emoji_id FROM telegram_product_categories WHERE id = ? AND is_active = 1');
    $stmt->execute([(int) $categoryId]);
    $category = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$category) {
        telegramProductsShowHome();
        return;
    }

    $stmt = $pdo->prepare("SELECT g.*,
        (SELECT COUNT(*) FROM telegram_products p WHERE p.group_id = g.id AND p.is_active = 1
            AND (p.agent_scope = 'all' OR FIND_IN_SET(?, p.agent_scope) > 0)) AS plan_count
        FROM telegram_product_groups g
        WHERE g.category_id = ? AND g.is_active = 1
        HAVING plan_count > 0
        ORDER BY g.sort_order, g.id");
    $stmt->execute([$user['agent'] ?? 'f', (int) $categoryId]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT p.*,
        (SELECT COUNT(*) FROM telegram_product_stock s WHERE s.product_id = p.id AND s.status = 'available') AS stock_count
        FROM telegram_products p
        WHERE p.category_id = ? AND p.group_id IS NULL AND p.is_active = 1
            AND (p.agent_scope = 'all' OR FIND_IN_SET(?, p.agent_scope) > 0)
        ORDER BY p.sort_order, p.id");
    $stmt->execute([(int) $categoryId, $user['agent'] ?? 'f']);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rows = [];
    foreach ($groups as $group) {
        $rows[] = [telegramProductsStyledButton(
            $group['title'] . ' (' . $group['plan_count'] . ' پلن)',
            'tgp_group_' . $group['id'],
            $group['button_style'],
            $group['button_emoji_id']
        )];
    }
    foreach ($products as $product) {
        if ($product['delivery_type'] === 'auto' && (int) $product['stock_count'] === 0) {
            continue;
        }
        $rows[] = [telegramProductsStyledButton(
            $product['title'] . ' - ' . telegramProductsMoney($product['price']),
            'tgp_view_' . $product['id'],
            $product['button_style'],
            $product['button_emoji_id']
        )];
    }
    $rows[] = [telegramProductsActionButton('بازگشت', 'tgp_home', 'danger', 'navigation')];

    $text = '<b>' . telegramProductsSafeCustomText($category['title']) . "</b>\n\n";
    $text .= telegramProductsSafeCustomText(telegramProductsSetting('category_text', 'محصول موردنظر را انتخاب کنید.'));
    if (count($rows) === 1) {
        $text .= "\n\nمحصول موجودی در این دسته وجود ندارد.";
    }
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramProductsShowGroup($groupId)
{
    global $pdo, $user;

    $stmt = $pdo->prepare('SELECT g.*, c.title AS category_title FROM telegram_product_groups g JOIN telegram_product_categories c ON c.id=g.category_id WHERE g.id=? AND g.is_active=1 AND c.is_active=1');
    $stmt->execute([(int) $groupId]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$group) { telegramProductsShowHome(); return; }

    $stmt = $pdo->prepare("SELECT p.*,
        (SELECT COUNT(*) FROM telegram_product_stock s WHERE s.product_id=p.id AND s.status='available') AS stock_count
        FROM telegram_products p
        WHERE p.group_id=? AND p.is_active=1
            AND (p.agent_scope='all' OR FIND_IN_SET(?,p.agent_scope)>0)
        ORDER BY p.sort_order,p.id");
    $stmt->execute([(int) $groupId, $user['agent'] ?? 'f']);
    $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rows = [];
    foreach ($plans as $plan) {
        if ($plan['delivery_type'] === 'auto' && (int) $plan['stock_count'] === 0) continue;
        $rows[] = [telegramProductsStyledButton(
            $plan['title'] . ' - ' . telegramProductsMoney($plan['price']),
            'tgp_view_' . $plan['id'],
            $plan['button_style'],
            $plan['button_emoji_id']
        )];
    }
    $rows[] = [telegramProductsActionButton('بازگشت', 'tgp_cat_' . $group['category_id'], 'danger', 'navigation')];
    $text = '<b>' . telegramProductsSafeCustomText($group['title']) . "</b>\n\n";
    if (!empty($group['description'])) $text .= telegramProductsSafeCustomText($group['description']) . "\n\n";
    $text .= 'پلن موردنظر را انتخاب کنید.';
    if (count($rows) === 1) $text .= "\n\nدر حال حاضر پلن قابل خریدی وجود ندارد.";
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramProductsGetProduct($productId)
{
    global $pdo, $user;

    $stmt = $pdo->prepare("SELECT p.*,
        (SELECT COUNT(*) FROM telegram_product_stock s WHERE s.product_id = p.id AND s.status = 'available') AS stock_count
        FROM telegram_products p
        WHERE p.id = ? AND p.is_active = 1
            AND (p.agent_scope = 'all' OR FIND_IN_SET(?, p.agent_scope) > 0)");
    $stmt->execute([(int) $productId, $user['agent'] ?? 'f']);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function telegramProductsShowProduct($productId)
{
    $product = telegramProductsGetProduct($productId);
    if (!$product) {
        telegramProductsReply('این محصول در دسترس نیست.', json_encode(['inline_keyboard' => [[telegramProductsActionButton('بازگشت', 'tgp_home', 'danger', 'navigation')]]], JSON_UNESCAPED_UNICODE));
        return;
    }

    $delivery = $product['delivery_type'] === 'auto' ? 'تحویل خودکار و فوری' : 'ثبت فرم و تحویل توسط ادمین';
    $text = "<b>جزئیات محصول</b>\n\n";
    $text .= '<blockquote><b>محصول:</b> ' . telegramProductsSafeCustomText($product['title']) . "\n";
    if (!empty($product['description'])) {
        $text .= telegramProductsSafeCustomText($product['description']) . "\n";
    }
    $text .= '<b>قیمت:</b> ' . telegramProductsMoney($product['price']) . "\n";
    $text .= '<b>نوع تحویل:</b> ' . $delivery;
    if (($product['auth_mode'] ?? 'none') !== 'none') {
        $text .= "\n<b>احراز هویت:</b> " . telegramProductsIdentityModeLabel($product['auth_mode']);
    }
    if ($product['delivery_type'] === 'auto') {
        $text .= "\n<b>موجودی:</b> " . (int) $product['stock_count'];
    }
    if (!empty($product['input_label']) && ($product['product_mode'] ?? 'legacy') !== 'form') {
        $text .= "\n<b>اطلاعات لازم:</b> " . telegramProductsSafeCustomText($product['input_label']);
    }
    if (($product['product_mode'] ?? '') === 'form' && function_exists('telegramProductsFields')) {
        $fields = telegramProductsFields($product['id']);
        if ($fields) {
            $labels = [];
            foreach ($fields as $field) $labels[] = telegramProductsPlainText($field['label']) . ((int) $field['is_required'] ? ' (الزامی)' : ' (اختیاری)');
            $text .= "\n<b>فرم سفارش:</b> " . telegramProductsEscape(implode('، ', $labels));
        }
    }
    if ((int) ($product['warranty_days'] ?? 0) > 0) {
        $text .= "\n<b>گارانتی:</b> " . (int) $product['warranty_days'] . ' روز پس از تحویل';
    }
    if ((int) ($product['max_resends'] ?? 0) > 0) {
        $text .= "\n<b>ارسال مجدد:</b> تا " . (int) $product['max_resends'] . ' مرتبه';
    }
    if ((int) ($product['max_per_user'] ?? 0) > 0) {
        $text .= "\n<b>سقف خرید هر کاربر:</b> " . (int) $product['max_per_user'];
    }
    $text .= '</blockquote>';

    $rows = [];
    if ($product['delivery_type'] !== 'auto' || (int) $product['stock_count'] > 0) {
        $rows[] = [telegramProductsActionButton('خرید با موجودی کیف پول', 'tgp_buy_' . $product['id'], 'success', 'success')];
    }
    $backCallback = !empty($product['group_id']) ? 'tgp_group_' . $product['group_id'] : 'tgp_cat_' . $product['category_id'];
    $rows[] = [['text' => 'بازگشت', 'callback_data' => $backCallback, 'style' => 'danger']];
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramProductsCreateDraft($productId, $customerInput = null)
{
    global $pdo, $from_id;

    $product = telegramProductsGetProduct($productId);
    if (!$product || ($product['delivery_type'] === 'auto' && (int) $product['stock_count'] < 1)) {
        telegramProductsReply(telegramProductsSafeCustomText(telegramProductsSetting('out_of_stock_text', 'این محصول در حال حاضر قابل خرید نیست.')), null);
        return;
    }
    if (!telegramProductsIdentityGate($product)) return;

    $maxPerUser = (int) ($product['max_per_user'] ?? 0);
    if ($maxPerUser > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM telegram_product_orders WHERE user_id = ? AND product_id = ? AND status IN ('paid_pending', 'delivered')");
        $stmt->execute([$from_id, $product['id']]);
        if ((int) $stmt->fetchColumn() >= $maxPerUser) {
            telegramProductsReply('سقف خرید مجاز شما برای این محصول تکمیل شده است.', null);
            return;
        }
    }

    $stmt = $pdo->prepare("INSERT INTO telegram_product_orders
        (user_id, product_id, product_title, price, original_price, delivery_type, customer_input, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
    $stmt->execute([$from_id, $product['id'], $product['title'], $product['price'], $product['price'], $product['delivery_type'], $customerInput]);
    $orderId = $pdo->lastInsertId();
    telegramProductsCheckout($orderId);
}

function telegramProductsPayOrder($orderId)
{
    global $pdo, $from_id;

    $paymentCommitted = false;
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT * FROM telegram_product_orders WHERE id = ? AND user_id = ? FOR UPDATE');
        $stmt->execute([(int) $orderId, $from_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order || $order['status'] !== 'pending') {
            $pdo->rollBack();
            telegramProductsReply('این سفارش قبلاً پردازش شده یا معتبر نیست.', null);
            return;
        }

        $stmt = $pdo->prepare('SELECT * FROM telegram_products WHERE id = ? FOR UPDATE');
        $stmt->execute([$order['product_id']]);
        $currentProduct = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$currentProduct || (int) $currentProduct['is_active'] !== 1) {
            $pdo->rollBack();
            telegramProductsReply('این محصول غیرفعال شده است و مبلغی کسر نشد.', null);
            return;
        }
        $authMode = $currentProduct['auth_mode'] ?? 'none';
        if ($authMode !== 'none' && !telegramProductsIdentitySatisfied($authMode, telegramProductsIdentityGet($from_id, true))) {
            $pdo->rollBack();
            telegramProductsReply('احراز هویت این پلن هنوز تکمیل یا تأیید نشده است؛ مبلغی کسر نشد.', json_encode(['inline_keyboard' => [[telegramProductsActionButton('وضعیت احراز', 'tgp_identity_start_' . $order['product_id'], 'primary', 'action')]]], JSON_UNESCAPED_UNICODE));
            return;
        }
        $originalPrice = (int) ($order['original_price'] ?: $order['price']);
        $priceLocked = telegramProductsInvoicePriceLocked($order);
        $priceChanged = (int) $currentProduct['price'] !== $originalPrice;
        if ($currentProduct['delivery_type'] !== $order['delivery_type'] || ($priceChanged && !$priceLocked)) {
            $stmt = $pdo->prepare("UPDATE telegram_product_orders SET status = 'cancelled' WHERE id = ?");
            $stmt->execute([$order['id']]);
            $pdo->commit();
            telegramProductsReply('قیمت یا روش تحویل محصول تغییر کرده است. لطفاً سفارش تازه‌ای ثبت کنید؛ مبلغی کسر نشد.', json_encode(['inline_keyboard' => [[telegramProductsActionButton('مشاهده محصول', 'tgp_view_' . $order['product_id'], 'primary', 'action')]]], JSON_UNESCAPED_UNICODE));
            return;
        }

        $paymentProduct = $currentProduct;
        if ($priceLocked) {
            $paymentProduct['price'] = $originalPrice;
        }
        [$financialOk, $financial] = telegramProductsPreparePayment($order, $paymentProduct);
        if (!$financialOk) {
            $pdo->rollBack();
            telegramProductsReply($financial, json_encode(['inline_keyboard' => [[telegramProductsActionButton('بازگشت به فاکتور', 'tgp_checkout_' . $order['id'], 'danger', 'navigation')]]], JSON_UNESCAPED_UNICODE));
            return;
        }
        $order = $financial['order'];

        $stmt = $pdo->prepare('SELECT Balance, agent, maxbuyagent FROM user WHERE id = ? FOR UPDATE');
        $stmt->execute([$from_id]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['Balance' => 0, 'agent' => 'f', 'maxbuyagent' => 0];
        $balance = (int) $wallet['Balance'];
        $creditLimit = $wallet['agent'] === 'n2' ? (int) $wallet['maxbuyagent'] : 0;
        $canPay = $balance >= (int) $order['price']
            || ($creditLimit > 0 && ($balance - (int) $order['price']) >= -$creditLimit);
        if (!$canPay) {
            $pdo->rollBack();
            telegramProductsReply('موجودی کیف پول برای این خرید کافی نیست.', json_encode(['inline_keyboard' => [[telegramProductsActionButton('افزایش موجودی', 'account', 'success', 'success')], [telegramProductsActionButton('بازگشت به فاکتور', 'tgp_checkout_' . $order['id'], 'danger', 'navigation')]]], JSON_UNESCAPED_UNICODE));
            return;
        }
        $maxPerUser = (int) $currentProduct['max_per_user'];
        if ($maxPerUser > 0) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM telegram_product_orders WHERE user_id = ? AND product_id = ? AND id != ? AND status IN ('paid_pending', 'delivered')");
            $stmt->execute([$from_id, $order['product_id'], $order['id']]);
            if ((int) $stmt->fetchColumn() >= $maxPerUser) {
                $pdo->rollBack();
                telegramProductsReply('سقف خرید مجاز شما برای این محصول تکمیل شده است و مبلغی کسر نشد.', null);
                return;
            }
        }

        $stock = null;
        if ($order['delivery_type'] === 'auto') {
            $stmt = $pdo->prepare("SELECT * FROM telegram_product_stock WHERE product_id = ? AND status = 'available' ORDER BY id LIMIT 1 FOR UPDATE");
            $stmt->execute([$order['product_id']]);
            $stock = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$stock) {
                $pdo->rollBack();
                telegramProductsReply('موجودی این محصول تمام شده و مبلغی از کیف پول کسر نشد.', null);
                return;
            }
        }

        $stmt = $pdo->prepare('UPDATE user SET Balance = Balance - ? WHERE id = ?');
        $stmt->execute([(int) $order['price'], $from_id]);

        if ($stock) {
            $stmt = $pdo->prepare("UPDATE telegram_product_stock SET status = 'sold', sold_to = ?, order_id = ?, sold_at = NOW() WHERE id = ?");
            $stmt->execute([$from_id, $order['id'], $stock['id']]);
            $stmt = $pdo->prepare("UPDATE telegram_product_orders SET status = 'delivered', delivery_payload = ?, paid_at = NOW(), delivered_at = NOW() WHERE id = ?");
            $stmt->execute([$stock['payload'], $order['id']]);
        } else {
            $stmt = $pdo->prepare("UPDATE telegram_product_orders SET status = 'paid_pending', paid_at = NOW() WHERE id = ?");
            $stmt->execute([$order['id']]);
        }

        telegramProductsFinalizePayment($order, $currentProduct, $financial, $stock ? $stock['payload'] : null);

        $newBalance = $balance - (int) $order['price'];
        $pdo->commit();
        $paymentCommitted = true;
        if (function_exists('clearSelectCache')) {
            clearSelectCache('user');
        }

        if ($stock) {
            $remainingStock = 0;
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM telegram_product_stock WHERE product_id = ? AND status = 'available'");
            $stmt->execute([$order['product_id']]);
            $remainingStock = (int) $stmt->fetchColumn();
            $text = "<b>خرید با موفقیت انجام شد</b>\n\n";
            $text .= '<blockquote><b>سفارش:</b> <code>#' . (int) $order['id'] . "</code>\n";
            $text .= '<b>محصول:</b> ' . telegramProductsEscape($order['product_title']) . "\n";
            $text .= '<b>مبلغ:</b> ' . telegramProductsMoney($order['price']) . "\n";
            $text .= '<b>مانده کیف پول:</b> ' . telegramProductsMoney($newBalance) . '</blockquote>';
            $text .= "\n\n" . telegramProductsSafeCustomText(telegramProductsSetting('auto_success_text', 'محصول شما آماده تحویل است.'));
            $text .= "\n\n<b>اطلاعات تحویل:</b>\n<code>" . telegramProductsEscape($stock['payload']) . '</code>';
            telegramProductsReply($text, json_encode(['inline_keyboard' => [[telegramProductsActionButton('سفارش‌های من', 'tgp_orders', 'primary', 'action')], [telegramProductsActionButton('بازگشت به فروشگاه', 'tgp_home', 'danger', 'navigation')]]], JSON_UNESCAPED_UNICODE));
            telegramProductsReport('sale', "<b>خرید خودکار خدمات مجازی</b>\n\n<b>سفارش:</b> <code>#{$order['id']}</code>\n<b>کاربر:</b> <code>" . telegramProductsEscape($from_id) . "</code>\n<b>محصول:</b> " . telegramProductsEscape($order['product_title']) . "\n<b>مبلغ:</b> " . telegramProductsMoney($order['price']) . "\n<b>مانده کیف پول:</b> " . telegramProductsMoney($newBalance) . "\n<b>موجودی باقی‌مانده:</b> {$remainingStock}");
            $product = telegramProductsGetProduct($order['product_id']);
            if ($product && $remainingStock <= (int) ($product['low_stock_threshold'] ?? 0)) {
                telegramProductsReport('stock', "<b>هشدار موجودی خدمات مجازی</b>\n\nمحصول <b>" . telegramProductsEscape($order['product_title']) . "</b> فقط <code>{$remainingStock}</code> موجودی قابل فروش دارد.");
            }
            return;
        }

        telegramProductsNotifyAdmins($order['id'], $order['product_title'], $from_id, $order['price'], $order['customer_input'] ?? '');
        telegramProductsReport('sale', "<b>سفارش دستی جدید خدمات مجازی</b>\n\n<b>سفارش:</b> <code>#{$order['id']}</code>\n<b>کاربر:</b> <code>" . telegramProductsEscape($from_id) . "</code>\n<b>محصول:</b> " . telegramProductsEscape($order['product_title']) . "\n<b>مبلغ:</b> " . telegramProductsMoney($order['price']) . "\n<b>مانده کیف پول:</b> " . telegramProductsMoney($newBalance) . (!empty($order['customer_input']) ? "\n<b>اطلاعات مشتری:</b> <code>" . telegramProductsEscape($order['customer_input']) . '</code>' : ''));
        $pendingText = "<b>پرداخت با موفقیت ثبت شد</b>\n\n<blockquote><b>شماره سفارش:</b> <code>#{$order['id']}</code>\n<b>محصول:</b> " . telegramProductsEscape($order['product_title']) . "\n<b>مبلغ:</b> " . telegramProductsMoney($order['price']) . "\n<b>وضعیت:</b> در انتظار تحویل</blockquote>\n\n";
        $pendingText .= telegramProductsSafeCustomText(telegramProductsSetting('manual_pending_text', 'سفارش برای بررسی و تحویل ثبت شد.'));
        telegramProductsReply($pendingText, json_encode(['inline_keyboard' => [[telegramProductsActionButton('سفارش‌های من', 'tgp_orders', 'primary', 'action')], [telegramProductsActionButton('بازگشت به فروشگاه', 'tgp_home', 'danger', 'navigation')]]], JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Telegram products checkout failed: ' . $e->getMessage());
        $reason = telegramProductsPublicFailureReason($e);
        telegramProductsReport('error', "<b>پردازش ناموفق خدمات مجازی</b>\n\n<b>سفارش:</b> <code>#" . (int) $orderId . "</code>\n<b>دلیل:</b> " . telegramProductsEscape($reason));
        if ($paymentCommitted) {
            $text = "<b>پرداخت ثبت شده است</b>\n\n<blockquote><b>شماره پیگیری:</b> <code>#" . (int) $orderId . "</code>\n<b>وضعیت:</b> نتیجه سفارش از بخش سفارش‌های من قابل مشاهده است.</blockquote>";
            $rows = [[telegramProductsActionButton('مشاهده سفارش', 'tgp_order_' . (int) $orderId, 'primary', 'action')], [telegramProductsActionButton('بازگشت به فروشگاه', 'tgp_home', 'danger', 'navigation')]];
            telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
        } else {
            $rows = [[telegramProductsActionButton('تلاش دوباره', 'tgp_pay_' . (int) $orderId, 'primary', 'action')], [telegramProductsActionButton('بازگشت به فروشگاه', 'tgp_home', 'danger', 'navigation')]];
            telegramProductsReply(telegramProductsFailureCard($reason, $orderId, true), json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
        }
    }
}

function telegramProductsNotifyAdmins($orderId, $title, $userId, $price, $customerInput = '')
{
    global $admin_ids;

    $text = "<b>سفارش دستی جدید محصولات تلگرامی</b>\n\n";
    $text .= '<b>شماره سفارش:</b> <code>' . (int) $orderId . "</code>\n";
    $text .= '<b>کاربر:</b> <code>' . telegramProductsEscape($userId) . "</code>\n";
    $text .= '<b>محصول:</b> ' . telegramProductsEscape($title) . "\n";
    $text .= '<b>مبلغ:</b> ' . telegramProductsMoney($price) . "\n\n";
    if ($customerInput !== '') {
        $text .= '<b>اطلاعات کاربر:</b> <code>' . telegramProductsEscape($customerInput) . "</code>\n\n";
    }
    $text .= 'برای مدیریت سفارش وارد بخش «خدمات مجازی» پنل ادمین شوید.';
    foreach ((array) $admin_ids as $adminId) {
        sendmessage($adminId, $text, null, 'HTML');
    }
}

function telegramProductsShowOrders()
{
    global $pdo, $from_id;

    $stmt = $pdo->prepare('SELECT id, product_title, price, status, created_at FROM telegram_product_orders WHERE user_id = ? ORDER BY id DESC LIMIT 10');
    $stmt->execute([$from_id]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $statusLabels = [
        'pending' => 'در انتظار پرداخت',
        'paid_pending' => 'در انتظار تحویل ادمین',
        'delivered' => 'تحویل‌شده',
        'cancelled' => 'لغوشده',
        'refunded' => 'لغو و بازپرداخت‌شده',
    ];
    $text = "<b>سفارش‌های محصولات تلگرامی</b>\n\n";
    if (!$orders) {
        $text .= 'هنوز سفارشی ثبت نکرده‌اید.';
    }
    $rows = [];
    foreach ($orders as $order) {
        $status = $statusLabels[$order['status']] ?? $order['status'];
        $text .= '#' . $order['id'] . ' - ' . telegramProductsEscape($order['product_title']);
        $text .= "\n" . telegramProductsMoney($order['price']) . ' - ' . $status . "\n\n";
        $rows[] = [telegramProductsActionButton('مشاهده سفارش #' . $order['id'], 'tgp_order_' . $order['id'], 'primary', 'action')];
    }
    $rows[] = [telegramProductsActionButton('بازگشت', 'tgp_home', 'danger', 'navigation')];
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramProductsShowOrder($orderId)
{
    global $pdo, $from_id;

    $stmt = $pdo->prepare('SELECT o.*, p.max_resends FROM telegram_product_orders o LEFT JOIN telegram_products p ON p.id = o.product_id WHERE o.id = ? AND o.user_id = ?');
    $stmt->execute([(int) $orderId, $from_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        telegramProductsReply('این سفارش پیدا نشد.', json_encode(['inline_keyboard' => [[telegramProductsActionButton('بازگشت', 'tgp_orders', 'danger', 'navigation')]]], JSON_UNESCAPED_UNICODE));
        return;
    }
    $labels = [
        'pending' => 'در انتظار پرداخت',
        'paid_pending' => 'در انتظار تحویل ادمین',
        'delivered' => 'تحویل‌شده',
        'cancelled' => 'لغوشده',
        'refunded' => 'لغو و بازپرداخت‌شده',
    ];
    $text = "<b>سفارش #{$order['id']}</b>\n\n";
    $text .= '<b>محصول:</b> ' . telegramProductsEscape($order['product_title']) . "\n";
    $text .= '<b>مبلغ:</b> ' . telegramProductsMoney($order['price']) . "\n";
    if ((int) ($order['discount_amount'] ?? 0) > 0) {
        $text .= '<b>تخفیف:</b> ' . telegramProductsMoney($order['discount_amount']) . "\n";
    }
    if ((int) ($order['points_earned'] ?? 0) > 0) {
        $text .= '<b>امتیاز دریافتی:</b> ' . (int) $order['points_earned'] . "\n";
    }
    $text .= '<b>وضعیت:</b> ' . ($labels[$order['status']] ?? telegramProductsEscape($order['status'])) . "\n";
    if (!empty($order['customer_input'])) {
        $text .= "<b>اطلاعات سفارش:</b>\n" . telegramProductsFormatCustomerInput($order['customer_input']) . "\n";
    }
    if (!empty($order['delivery_payload'])) {
        $text .= "\n<b>اطلاعات تحویل:</b>\n<code>" . telegramProductsEscape($order['delivery_payload']) . '</code>';
    }
    $rows = [];
    if ($order['status'] === 'pending') {
        $rows[] = [telegramProductsActionButton('بازگشت به فاکتور', 'tgp_checkout_' . $order['id'], 'primary', 'action')];
    }
    if ($order['status'] === 'delivered' && !empty($order['delivery_payload']) && (int) $order['resend_count'] < (int) ($order['max_resends'] ?? 0)) {
        $rows[] = [telegramProductsActionButton('ارسال مجدد اطلاعات تحویل', 'tgp_selfresend_' . $order['id'], 'primary', 'action')];
    }
    if ($order['status'] === 'delivered' && !empty($order['warranty_until']) && strtotime($order['warranty_until']) >= time()) {
        $text .= "\n<b>گارانتی تا:</b> <code>" . telegramProductsEscape($order['warranty_until']) . '</code>';
        $rows[] = [telegramProductsActionButton('ثبت درخواست گارانتی', 'tgp_warranty_' . $order['id'], 'primary', 'action')];
    }
    $rows[] = [telegramProductsActionButton('بازگشت', 'tgp_orders', 'danger', 'navigation')];
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramProductsAdminCommand($text)
{
    global $pdo, $from_id;

    if (strpos($text, '/tg_') !== 0) {
        return false;
    }
    if (!telegramProductsIsAdmin($from_id)) {
        sendmessage($from_id, 'شما اجازه اجرای این دستور را ندارید.', null, 'HTML');
        return true;
    }

    if (function_exists('telegramProductsAdminCan')) {
        $required = null;
        if (preg_match('/^\/tg_(add_category|add_product|add_stock|products|product_)/', $text)) $required = 'catalog';
        if (preg_match('/^\/tg_(orders|deliver)/', $text)) $required = 'orders';
        if ($required && !telegramProductsAdminCan($from_id, $required)) {
            sendmessage($from_id, 'شما برای اجرای این دستور دسترسی لازم را ندارید.', null, 'HTML');
            return true;
        }
    }

    if ($text === '/tg_help' || $text === '/tg_products_admin') {
        $help = "<b>مدیریت محصولات تلگرامی</b>\n\n";
        $help .= "<code>/tg_add_category عنوان دسته</code>\n";
        $help .= "<code>/tg_add_product شناسه‌دسته|عنوان|قیمت|auto یا manual|توضیحات</code>\n";
        $help .= "<code>/tg_add_stock شناسه‌محصول|کد یا لینک تحویل</code>\n";
        $help .= "<code>/tg_products</code>\n<code>/tg_orders</code>\n";
        $help .= "<code>/tg_deliver شماره‌سفارش|متن تحویل</code>\n";
        $help .= "<code>/tg_product_on شناسه</code>\n<code>/tg_product_off شناسه</code>";
        sendmessage($from_id, $help, null, 'HTML');
        return true;
    }

    if (preg_match('/^\/tg_add_category\s+(.+)$/u', $text, $match)) {
        $stmt = $pdo->prepare('INSERT INTO telegram_product_categories (title) VALUES (?)');
        $stmt->execute([trim($match[1])]);
        sendmessage($from_id, 'دسته با شناسه <code>' . $pdo->lastInsertId() . '</code> ساخته شد.', null, 'HTML');
        return true;
    }

    if (preg_match('/^\/tg_add_product\s+(.+)$/us', $text, $match)) {
        $parts = array_map('trim', explode('|', $match[1], 5));
        if (count($parts) !== 5 || !ctype_digit($parts[0]) || !ctype_digit($parts[2]) || !in_array($parts[3], ['auto', 'manual'], true)) {
            sendmessage($from_id, 'فرمت دستور صحیح نیست. دستور <code>/tg_help</code> را ببینید.', null, 'HTML');
            return true;
        }
        $stmt = $pdo->prepare('INSERT INTO telegram_products (category_id, title, price, delivery_type, description) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([(int) $parts[0], $parts[1], (int) $parts[2], $parts[3], $parts[4]]);
        sendmessage($from_id, 'محصول با شناسه <code>' . $pdo->lastInsertId() . '</code> ساخته شد.', null, 'HTML');
        return true;
    }

    if (preg_match('/^\/tg_add_stock\s+(\d+)\|(.+)$/us', $text, $match)) {
        $stmt = $pdo->prepare('INSERT INTO telegram_product_stock (product_id, payload) VALUES (?, ?)');
        $stmt->execute([(int) $match[1], trim($match[2])]);
        sendmessage($from_id, 'موجودی خودکار اضافه شد.', null, 'HTML');
        return true;
    }

    if ($text === '/tg_products') {
        $rows = $pdo->query('SELECT p.id, p.title, p.price, p.delivery_type, p.is_active, c.title AS category_title FROM telegram_products p LEFT JOIN telegram_product_categories c ON c.id = p.category_id ORDER BY p.id DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC);
        $output = "<b>فهرست محصولات</b>\n\n";
        foreach ($rows as $row) {
            $output .= '#' . $row['id'] . ' ' . telegramProductsEscape($row['title']) . ' | ' . telegramProductsMoney($row['price']);
            $output .= ' | ' . $row['delivery_type'] . ' | ' . ((int) $row['is_active'] === 1 ? 'فعال' : 'غیرفعال') . "\n";
        }
        sendmessage($from_id, $output, null, 'HTML');
        return true;
    }

    if ($text === '/tg_orders') {
        $stmt = $pdo->query("SELECT id, user_id, product_title, price FROM telegram_product_orders WHERE status = 'paid_pending' ORDER BY id LIMIT 30");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $output = "<b>سفارش‌های منتظر تحویل</b>\n\n";
        if (!$rows) {
            $output .= 'سفارشی در انتظار تحویل نیست.';
        }
        foreach ($rows as $row) {
            $output .= '#' . $row['id'] . ' | کاربر ' . telegramProductsEscape($row['user_id']) . ' | ' . telegramProductsEscape($row['product_title']) . ' | ' . telegramProductsMoney($row['price']) . "\n";
        }
        sendmessage($from_id, $output, null, 'HTML');
        return true;
    }

    if (preg_match('/^\/tg_deliver\s+(\d+)\|(.+)$/us', $text, $match)) {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM telegram_product_orders WHERE id = ? AND status = 'paid_pending' FOR UPDATE");
        $stmt->execute([(int) $match[1]]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            $pdo->rollBack();
            sendmessage($from_id, 'سفارش منتظر تحویلی با این شناسه پیدا نشد.', null, 'HTML');
            return true;
        }
        $delivery = trim($match[2]);
        $stmt = $pdo->prepare("UPDATE telegram_product_orders SET status = 'delivered', delivery_payload = ?, delivered_at = NOW() WHERE id = ?");
        $stmt->execute([$delivery, $order['id']]);
        $pdo->commit();
        $message = "سفارش شما تحویل شد.\n\n<b>محصول:</b> " . telegramProductsEscape($order['product_title']);
        $message .= "\n<b>اطلاعات تحویل:</b>\n<code>" . telegramProductsEscape($delivery) . '</code>';
        sendmessage($order['user_id'], $message, null, 'HTML');
        sendmessage($from_id, 'سفارش تحویل و برای کاربر ارسال شد.', null, 'HTML');
        return true;
    }

    if (preg_match('/^\/tg_product_(on|off)\s+(\d+)$/', $text, $match)) {
        $stmt = $pdo->prepare('UPDATE telegram_products SET is_active = ? WHERE id = ?');
        $stmt->execute([$match[1] === 'on' ? 1 : 0, (int) $match[2]]);
        sendmessage($from_id, 'وضعیت محصول بروزرسانی شد.', null, 'HTML');
        return true;
    }

    sendmessage($from_id, 'دستور شناخته نشد. <code>/tg_help</code>', null, 'HTML');
    return true;
}

function telegramProductsHandleRequest()
{
    global $from_id, $callback_query_id;

    try {
        return telegramProductsHandleRequestInternal();
    } catch (Throwable $e) {
        error_log('Virtual services user handler error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        if ($callback_query_id) {
            telegram('answerCallbackQuery', [
                'callback_query_id' => $callback_query_id,
                'text' => 'خطا در بارگذاری خدمات مجازی. دوباره تلاش کنید.',
                'show_alert' => true,
            ]);
        }
        sendmessage($from_id, "<b>خدمات مجازی موقتاً در دسترس نیست</b>\n\nلطفاً چند لحظه دیگر دوباره تلاش کنید.", null, 'HTML');
        return true;
    }
}

function telegramProductsHandleRequestInternal()
{
    global $text, $datain, $from_id, $callback_query_id, $keyboard, $user, $setting;

    $buttonText = telegramProductsButtonText();
    $isInputStep = strpos((string) ($user['step'] ?? ''), 'tg_product_input_') === 0;
    $isFeatureStep = strpos((string) ($user['step'] ?? ''), 'tgp_') === 0;
    $isProductRequest = $text === $buttonText
        || $text === TELEGRAM_PRODUCTS_BUTTON
        || strpos($text, '/tg_') === 0
        || strpos($datain, 'tgp_') === 0
        || $isInputStep
        || $isFeatureStep;
    if (!$isProductRequest) {
        return false;
    }

    telegramProductsEnsureSchema();
    if (function_exists('telegramProductsMaybeRunAlerts')) telegramProductsMaybeRunAlerts();

    if ($callback_query_id) {
        telegram('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
    }

    $isFeatureContinuation = preg_match('/^tgp_form(opt|skip)_/', $datain) === 1;
    if ($isFeatureStep && $datain !== '' && !$isFeatureContinuation) {
        step('home', $from_id);
        $user['step'] = 'home';
    }
    if (function_exists('telegramFragmentHandleUserRequest') && telegramFragmentHandleUserRequest()) {
        return true;
    }
    if (telegramProductsIdentityHandleUser()) {
        return true;
    }
    if (function_exists('telegramProductsFeatureUserHandle') && telegramProductsFeatureUserHandle()) {
        return true;
    }

    if (telegramProductsAdminCommand($text)) {
        return true;
    }
    if (telegramProductsSetting('enabled', '1') !== '1') {
        sendmessage($from_id, telegramProductsSafeCustomText(telegramProductsSetting('disabled_text', 'بخش خدمات مجازی در حال حاضر غیرفعال است.')), $keyboard, 'HTML');
        step('home', $from_id);
        return true;
    }
    if (isset($setting['keyboardmain']) && !check_active_btn($setting['keyboardmain'], 'text_virtual_services') && ($text === $buttonText || $text === TELEGRAM_PRODUCTS_BUTTON)) {
        sendmessage($from_id, 'این دکمه غیرفعال است.', $keyboard, 'HTML');
        return true;
    }
    if ($isInputStep && $datain !== '') {
        step('home', $from_id);
        $user['step'] = 'home';
        $isInputStep = false;
    }
    if ($isInputStep) {
        $productId = (int) str_replace('tg_product_input_', '', $user['step']);
        $product = telegramProductsGetProduct($productId);
        if (!$product) {
            step('home', $from_id);
            sendmessage($from_id, 'محصول موردنظر دیگر در دسترس نیست.', $keyboard, 'HTML');
            return true;
        }
        $customerInput = trim((string) $text);
        if ($customerInput === '' || mb_strlen($customerInput, 'UTF-8') > 500) {
            sendmessage($from_id, 'اطلاعات واردشده معتبر نیست. حداکثر ۵۰۰ کاراکتر ارسال کنید.', null, 'HTML');
            return true;
        }
        step('home', $from_id);
        telegramProductsCreateDraft($productId, $customerInput);
        return true;
    }
    if ($text === $buttonText || $text === TELEGRAM_PRODUCTS_BUTTON || $datain === 'tgp_home') {
        telegramProductsShowHome();
        return true;
    }
    if ($datain === 'tgp_main') {
        global $message_id;
        if ($message_id) {
            deletemessage($from_id, $message_id);
        }
        sendmessage($from_id, 'به منوی اصلی بازگشتید.', $keyboard, 'HTML');
        return true;
    }
    if ($datain === 'tgp_orders') {
        telegramProductsShowOrders();
        return true;
    }
    if (preg_match('/^tgp_order_(\d+)$/', $datain, $match)) {
        telegramProductsShowOrder($match[1]);
        return true;
    }
    if (preg_match('/^tgp_cat_(\d+)$/', $datain, $match)) {
        telegramProductsShowCategory($match[1]);
        return true;
    }
    if (preg_match('/^tgp_group_(\d+)$/', $datain, $match)) {
        telegramProductsShowGroup($match[1]);
        return true;
    }
    if (preg_match('/^tgp_view_(\d+)$/', $datain, $match)) {
        telegramProductsShowProduct($match[1]);
        return true;
    }
    if (preg_match('/^tgp_buy_(\d+)$/', $datain, $match)) {
        $product = telegramProductsGetProduct($match[1]);
        if (!$product) {
            telegramProductsReply('این محصول در دسترس نیست.', null);
            return true;
        }
        if (!telegramProductsIdentityGate($product)) return true;
        if (($product['product_mode'] ?? '') === 'form' && function_exists('telegramProductsStartForm') && telegramProductsFields($product['id'])) {
            telegramProductsStartForm($product);
            return true;
        }
        if (($product['product_mode'] ?? 'form') !== 'stock' && !empty($product['input_label'])) {
            step('tg_product_input_' . $product['id'], $from_id);
            $prompt = '<b>' . telegramProductsSafeCustomText($product['input_label']) . "</b>\n\nاطلاعات را با دقت ارسال کنید.";
            telegramProductsReply($prompt, json_encode(['inline_keyboard' => [[telegramProductsActionButton('انصراف', 'tgp_view_' . $product['id'], 'danger', 'navigation')]]], JSON_UNESCAPED_UNICODE));
            return true;
        }
        telegramProductsCreateDraft($match[1], null);
        return true;
    }
    if (preg_match('/^tgp_pay_(\d+)$/', $datain, $match)) {
        telegramProductsPayOrder($match[1]);
        return true;
    }

    return true;
}
