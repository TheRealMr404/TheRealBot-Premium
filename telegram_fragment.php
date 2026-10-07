<?php

/**
 * Automatic Telegram Stars/Premium sales through the bundled Fragment Kit.
 * Wallet secrets stay in the local signer service and Fragment cookies stay
 * in a private directory outside the web root.
 */

function telegramFragmentDigits($value)
{
    return strtr((string) $value, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
}

function telegramFragmentEnsureSchema()
{
    global $pdo;
    static $ready = false;
    if ($ready) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_fragment_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_fragment_products (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        kind ENUM('stars','premium') NOT NULL,
        title VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        amount INT UNSIGNED NOT NULL,
        price BIGINT UNSIGNED NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_tfp_list (kind, is_active, sort_order, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_fragment_orders (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(200) NOT NULL,
        product_id BIGINT UNSIGNED NULL,
        product_title VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        kind ENUM('stars','premium') NOT NULL,
        recipient VARCHAR(64) NOT NULL,
        product_amount INT UNSIGNED NOT NULL,
        price BIGINT UNSIGNED NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'draft',
        idempotency_key VARCHAR(64) NOT NULL UNIQUE,
        wallet_debited TINYINT(1) NOT NULL DEFAULT 0,
        wallet_refunded TINYINT(1) NOT NULL DEFAULT 0,
        tx_hash VARCHAR(190) NULL,
        total_ton DECIMAL(20,9) NULL,
        attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
        next_attempt_at DATETIME NULL,
        last_error TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        notified_status VARCHAR(32) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        paid_at DATETIME NULL,
        completed_at DATETIME NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_tfo_user (user_id, created_at),
        INDEX idx_tfo_queue (status, next_attempt_at, updated_at),
        CONSTRAINT fk_tfo_product FOREIGN KEY (product_id) REFERENCES telegram_fragment_products(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $insert = $pdo->prepare('INSERT IGNORE INTO telegram_fragment_settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ([
        'enabled' => '0',
        'dry_run' => '1',
        'menu_title' => 'استارز و پریمیوم خودکار',
        'wallet_version' => 'v4r2',
        'daily_limit_ton' => '100',
        'per_tx_limit_ton' => '20',
        'low_balance_ton' => '2',
        'show_sender' => '0',
        'last_check' => 'هنوز انجام نشده',
        'last_check_ok' => '0',
    ] as $key => $value) {
        $insert->execute([$key, $value]);
    }
    $ready = true;
}

function telegramFragmentSetting($key, $default = '')
{
    global $pdo;
    telegramFragmentEnsureSchema();
    $stmt = $pdo->prepare('SELECT setting_value FROM telegram_fragment_settings WHERE setting_key=?');
    $stmt->execute([(string) $key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function telegramFragmentSetSetting($key, $value)
{
    global $pdo;
    telegramFragmentEnsureSchema();
    $stmt = $pdo->prepare('INSERT INTO telegram_fragment_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $stmt->execute([(string) $key, (string) $value]);
}

function telegramFragmentReadEnvFile($path)
{
    $values = [];
    if (!is_readable($path)) return $values;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if (!preg_match('/^[A-Z0-9_]+$/', $key)) continue;
        $value = trim($value);
        if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        }
        $values[$key] = $value;
    }
    return $values;
}

function telegramFragmentRuntimeConfig()
{
    global $dbname;
    static $config = null;
    if ($config !== null) return $config;
    $safeDb = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $dbname) ?: 'default';
    $env = [];
    foreach (["/etc/mirza/fragment-signer-{$safeDb}.env", '/etc/mirza/fragment-signer.env'] as $file) {
        $env = array_merge($env, telegramFragmentReadEnvFile($file));
    }
    $read = static function ($key, $default = '') use ($env) {
        $value = getenv($key);
        return ($value !== false && $value !== '') ? (string) $value : (string) ($env[$key] ?? $default);
    };
    $baseData = $read('MIRZA_FRAGMENT_DATA_DIR', '/var/lib/mirza-fragment/php-data');
    $config = [
        'dataDir' => rtrim($baseData, '/\\') . DIRECTORY_SEPARATOR . $safeDb,
        'signerUrl' => rtrim($read('MIRZA_FRAGMENT_SIGNER_URL', 'http://127.0.0.1:8787'), '/'),
        'signerToken' => $read('SIGNER_TOKEN'),
    ];
    return $config;
}

function telegramFragmentBoot()
{
    static $booted = false;
    if ($booted) return;
    $config = telegramFragmentRuntimeConfig();
    if ($config['signerToken'] === '') throw new RuntimeException('توکن سرویس امضا پیدا نشد. نصاب را یک‌بار اجرا کنید.');
    if (!is_dir($config['dataDir']) && !@mkdir($config['dataDir'], 0700, true) && !is_dir($config['dataDir'])) {
        throw new RuntimeException('پوشه امن داده‌های Fragment قابل استفاده نیست.');
    }
    require_once __DIR__ . '/fragment-kit/php/FragmentKit.php';
    FragmentKit::boot([
        'dataDir' => $config['dataDir'],
        'signerUrl' => $config['signerUrl'],
        'signerToken' => $config['signerToken'],
        'baseUrl' => 'https://fragment.com',
        'dryRun' => telegramFragmentSetting('dry_run', '1') === '1',
        'pollMs' => 3500,
        'paymentMethod' => 'ton',
        'dailyLimitTon' => (float) telegramFragmentSetting('daily_limit_ton', '100'),
        'showSender' => telegramFragmentSetting('show_sender', '0') === '1',
    ]);
    $booted = true;
}

function telegramFragmentEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function telegramFragmentMoney($value)
{
    return number_format((int) $value) . ' تومان';
}

function telegramFragmentProduct($id, $activeOnly = true)
{
    global $pdo;
    telegramFragmentEnsureSchema();
    $sql = 'SELECT * FROM telegram_fragment_products WHERE id=?' . ($activeOnly ? ' AND is_active=1' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([(int) $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function telegramFragmentProducts($kind = null, $activeOnly = true)
{
    global $pdo;
    telegramFragmentEnsureSchema();
    $where = [];
    $params = [];
    if (in_array($kind, ['stars', 'premium'], true)) {
        $where[] = 'kind=?';
        $params[] = $kind;
    }
    if ($activeOnly) $where[] = 'is_active=1';
    $sql = 'SELECT * FROM telegram_fragment_products' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY sort_order,id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function telegramFragmentAddHomeButton(array &$rows)
{
    if (telegramFragmentSetting('enabled', '0') !== '1') return;
    array_unshift($rows, [[
        'text' => '💎 ' . telegramFragmentSetting('menu_title', 'استارز و پریمیوم خودکار') . ' ⭐',
        'callback_data' => 'tgp_fg_home',
        'style' => 'primary',
    ]]);
}

function telegramFragmentShowHome()
{
    $rows = [
        [['text' => '⭐ خرید استارز تلگرام', 'callback_data' => 'tgp_fg_kind_stars', 'style' => 'primary']],
        [['text' => '💎 خرید تلگرام پریمیوم', 'callback_data' => 'tgp_fg_kind_premium', 'style' => 'success']],
        [['text' => '📦 سفارش‌های من', 'callback_data' => 'tgp_fg_orders']],
        [['text' => '🔙 بازگشت', 'callback_data' => 'tgp_home', 'style' => 'danger']],
    ];
    $text = "💎 <b>استارز و پریمیوم خودکار</b> ⭐\n\n";
    $text .= "<blockquote>⚡️ تحویل خودکار و سریع\n🔒 پرداخت امن از کیف پول ربات\n✅ بدون نیاز به ورود به حساب شما</blockquote>\n\n";
    $text .= 'سرویس موردنظر را انتخاب کنید 👇';
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramFragmentShowProducts($kind)
{
    $products = telegramFragmentProducts($kind, true);
    $rows = [];
    foreach ($products as $product) {
        $rows[] = [[
            'text' => ($kind === 'stars' ? '⭐ ' : '💎 ') . $product['title'] . ' • ' . telegramFragmentMoney($product['price']),
            'callback_data' => 'tgp_fg_p_' . $product['id'],
            'style' => $kind === 'stars' ? 'primary' : 'success',
        ]];
    }
    $rows[] = [['text' => '🔙 بازگشت', 'callback_data' => 'tgp_fg_home']];
    $title = $kind === 'stars' ? '⭐ پکیج‌های استارز' : '💎 پلن‌های تلگرام پریمیوم';
    $text = '<b>' . $title . "</b>\n\nپلن موردنظر را انتخاب کنید 👇";
    if (!$products) $text .= "\n\nدر حال حاضر پلن فعالی ثبت نشده است.";
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramFragmentShowProduct($id)
{
    $product = telegramFragmentProduct($id, true);
    if (!$product) {
        telegramProductsReply('این پلن دیگر در دسترس نیست.', null);
        return;
    }
    $kindText = $product['kind'] === 'stars'
        ? '<b>تعداد استارز:</b> ' . (int) $product['amount']
        : '<b>مدت اشتراک:</b> ' . (int) $product['amount'] . ' ماه';
    $text = '<b>' . telegramFragmentEscape($product['title']) . "</b>\n\n{$kindText}\n<b>مبلغ:</b> " . telegramFragmentMoney($product['price']);
    $rows = [
        [['text' => '🛒 ادامه خرید', 'callback_data' => 'tgp_fg_buy_' . $product['id'], 'style' => 'success']],
        [['text' => '🔙 بازگشت', 'callback_data' => 'tgp_fg_kind_' . $product['kind']]],
    ];
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramFragmentCreateDraft($productId, $recipient)
{
    global $pdo, $from_id;
    $product = telegramFragmentProduct($productId, true);
    if (!$product) {
        telegramProductsReply('پلن انتخاب‌شده دیگر در دسترس نیست.', null);
        return;
    }
    $recipient = strtolower(ltrim(trim((string) $recipient), '@'));
    if (!preg_match('/^[a-z][a-z0-9_]{4,31}$/', $recipient)) {
        telegramProductsReply("⚠️ نام کاربری معتبر نیست. دوباره بفرستید.\nنمونه: <code>username</code>", null);
        return false;
    }
    $idem = 'mirza-fg-' . substr(hash('sha256', $from_id . '|' . $productId . '|' . microtime(true) . '|' . random_int(1, PHP_INT_MAX)), 0, 42);
    $stmt = $pdo->prepare("INSERT INTO telegram_fragment_orders
        (user_id,product_id,product_title,kind,recipient,product_amount,price,idempotency_key)
        VALUES (?,?,?,?,?,?,?,?)");
    $stmt->execute([(string) $from_id, $product['id'], $product['title'], $product['kind'], $recipient, $product['amount'], $product['price'], $idem]);
    telegramFragmentShowCheckout((int) $pdo->lastInsertId());
    return true;
}

function telegramFragmentShowCheckout($orderId)
{
    global $pdo, $from_id;
    $stmt = $pdo->prepare('SELECT * FROM telegram_fragment_orders WHERE id=? AND user_id=?');
    $stmt->execute([(int) $orderId, (string) $from_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) return;
    $detail = $order['kind'] === 'stars' ? (int) $order['product_amount'] . ' استارز' : (int) $order['product_amount'] . ' ماه پریمیوم';
    $text = "<b>فاکتور خرید خودکار</b>\n\n";
    $text .= '<b>محصول:</b> ' . telegramFragmentEscape($order['product_title']) . "\n";
    $text .= '<b>پلن:</b> ' . $detail . "\n";
    $text .= '<b>گیرنده:</b> @' . telegramFragmentEscape($order['recipient']) . "\n";
    $text .= '<b>مبلغ:</b> ' . telegramFragmentMoney($order['price']) . "\n\n";
    $text .= 'نام کاربری را دقیق بررسی کنید؛ سفارش انجام‌شده قابل برگشت نیست.';
    $rows = [
        [['text' => '✅ تأیید و پرداخت', 'callback_data' => 'tgp_fg_pay_' . $order['id'], 'style' => 'success']],
        [['text' => '❌ انصراف', 'callback_data' => 'tgp_fg_home', 'style' => 'danger']],
    ];
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramFragmentPayOrder($orderId)
{
    global $pdo, $from_id;
    if (telegramFragmentSetting('enabled', '0') !== '1') {
        telegramProductsReply('فروش خودکار در حال حاضر غیرفعال است.', null);
        return;
    }
    if (telegramFragmentSetting('dry_run', '1') === '1') {
        telegramProductsReply('بخش خودکار هنوز در حالت آزمایشی است و مبلغی کسر نشد.', null);
        return;
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM telegram_fragment_orders WHERE id=? AND user_id=? FOR UPDATE');
        $stmt->execute([(int) $orderId, (string) $from_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order || $order['status'] !== 'draft') {
            $pdo->rollBack();
            telegramProductsReply('این سفارش قبلاً پرداخت شده یا معتبر نیست.', null);
            return;
        }
        $product = telegramFragmentProduct($order['product_id'], true);
        if (!$product || (int) $product['price'] !== (int) $order['price'] || (int) $product['amount'] !== (int) $order['product_amount']) {
            $pdo->rollBack();
            telegramProductsReply('قیمت یا وضعیت پلن تغییر کرده است؛ مبلغی کسر نشد.', null);
            return;
        }
        $stmt = $pdo->prepare('SELECT Balance,agent,maxbuyagent FROM user WHERE id=? FOR UPDATE');
        $stmt->execute([(string) $from_id]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        $balance = (int) ($wallet['Balance'] ?? 0);
        $credit = ($wallet['agent'] ?? '') === 'n2' ? (int) ($wallet['maxbuyagent'] ?? 0) : 0;
        if ($balance < (int) $order['price'] && !($credit > 0 && $balance - (int) $order['price'] >= -$credit)) {
            $pdo->rollBack();
            $rows = [[['text' => 'افزایش موجودی', 'callback_data' => 'account']], [['text' => 'بازگشت', 'callback_data' => 'tgp_fg_p_' . $order['product_id']]]];
            telegramProductsReply('موجودی کیف پول برای این خرید کافی نیست.', json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
            return;
        }
        $pdo->prepare('UPDATE user SET Balance=Balance-? WHERE id=?')->execute([(int) $order['price'], (string) $from_id]);
        $pdo->prepare("UPDATE telegram_fragment_orders SET wallet_debited=1,status='queued',paid_at=NOW(),next_attempt_at=NOW(),last_error=NULL WHERE id=?")->execute([$order['id']]);
        $pdo->commit();
        if (function_exists('clearSelectCache')) clearSelectCache('user');
        $text = "پرداخت با موفقیت ثبت شد و سفارش وارد صف خرید Fragment شد.\n\n<b>شماره سفارش:</b> <code>#{$order['id']}</code>";
        $rows = [[['text' => 'مشاهده وضعیت', 'callback_data' => 'tgp_fg_order_' . $order['id'], 'style' => 'primary']], [['text' => 'بازگشت', 'callback_data' => 'tgp_fg_home']]];
        telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
        telegramProductsReport('sale', "<b>سفارش خودکار Fragment</b>\n\nسفارش: <code>#{$order['id']}</code>\nکاربر: <code>{$from_id}</code>\nگیرنده: @" . telegramFragmentEscape($order['recipient']) . "\nوضعیت: در صف پردازش");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Fragment payment error: ' . $e->getMessage());
        telegramProductsReply('❌ ناموفق', null);
    }
}

function telegramFragmentStatusLabel($status)
{
    return [
        'draft' => 'در انتظار پرداخت',
        'queued' => 'در صف خرید',
        'processing' => 'در حال پردازش',
        'confirm_pending' => 'در انتظار تأیید شبکه',
        'review' => 'در حال بررسی',
        'completed' => 'تکمیل شده',
        'failed' => 'ناموفق',
    ][$status] ?? 'در حال پردازش';
}

function telegramFragmentShowOrder($id)
{
    global $pdo, $from_id;
    $stmt = $pdo->prepare('SELECT * FROM telegram_fragment_orders WHERE id=? AND user_id=?');
    $stmt->execute([(int) $id, (string) $from_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        telegramProductsReply('سفارش پیدا نشد.', null);
        return;
    }
    $text = "<b>سفارش #{$order['id']}</b>\n\n";
    $text .= '<b>محصول:</b> ' . telegramFragmentEscape($order['product_title']) . "\n";
    $text .= '<b>گیرنده:</b> @' . telegramFragmentEscape($order['recipient']) . "\n";
    $text .= '<b>مبلغ:</b> ' . telegramFragmentMoney($order['price']) . "\n";
    $text .= '<b>وضعیت:</b> ' . telegramFragmentStatusLabel($order['status']);
    if (!empty($order['tx_hash'])) $text .= "\n<b>شناسه تراکنش:</b> <code>" . telegramFragmentEscape($order['tx_hash']) . '</code>';
    if ($order['status'] === 'failed' && (int) $order['wallet_refunded'] === 1) $text .= "\n\nمبلغ کامل به کیف پول برگشت داده شد.";
    $rows = [[['text' => 'تازه‌سازی', 'callback_data' => 'tgp_fg_order_' . $order['id']]], [['text' => 'بازگشت', 'callback_data' => 'tgp_fg_orders']]];
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramFragmentShowOrders()
{
    global $pdo, $from_id;
    telegramFragmentEnsureSchema();
    $stmt = $pdo->prepare('SELECT id,product_title,status,created_at FROM telegram_fragment_orders WHERE user_id=? ORDER BY id DESC LIMIT 15');
    $stmt->execute([(string) $from_id]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $order) {
        $rows[] = [['text' => '#' . $order['id'] . ' | ' . $order['product_title'] . ' | ' . telegramFragmentStatusLabel($order['status']), 'callback_data' => 'tgp_fg_order_' . $order['id']]];
    }
    $rows[] = [['text' => 'بازگشت', 'callback_data' => 'tgp_fg_home']];
    $text = "<b>سفارش‌های خودکار من</b>" . (!$rows || count($rows) === 1 ? "\n\nهنوز سفارشی ثبت نشده است." : '');
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

/** نقطه‌ی ورود سمت کاربر: هر خطای داخلی فقط در لاگ می‌ماند و به کاربر فقط «ناموفق» نشان داده می‌شود */
function telegramFragmentHandleUserRequest()
{
    try {
        return telegramFragmentHandleUserRequestInner();
    } catch (Throwable $e) {
        error_log('Fragment user flow error: ' . $e->getMessage());
        try { telegramProductsReply('❌ ناموفق', null); } catch (Throwable $ignored) { }
        return true;
    }
}

function telegramFragmentHandleUserRequestInner()
{
    global $datain, $text, $user, $from_id;
    $state = (string) ($user['step'] ?? '');
    if (strpos((string) $datain, 'tgp_fg_') !== 0 && strpos($state, 'tgp_fg_') !== 0) return false;
    telegramFragmentEnsureSchema();
    if (telegramFragmentSetting('enabled', '0') !== '1') {
        telegramProductsReply('فروش خودکار استارز و پریمیوم در حال حاضر غیرفعال است.', null);
        step('home', $from_id);
        return true;
    }
    if ($datain === '' && preg_match('/^tgp_fg_recipient_(\d+)$/', $state, $match)) {
        // نام کاربری نامعتبر: کاربر در همان مرحله می‌ماند تا دوباره بفرستد
        if (telegramFragmentCreateDraft($match[1], $text) !== false) {
            step('home', $from_id);
            $user['step'] = 'home';
        }
        return true;
    }
    if ($datain !== '' && preg_match('/^tgp_fg_recipient_\d+$/', $state)) {
        step('home', $from_id);
        $user['step'] = 'home';
    }
    if ($datain === 'tgp_fg_home') { telegramFragmentShowHome(); return true; }
    if ($datain === 'tgp_fg_orders') { telegramFragmentShowOrders(); return true; }
    if (preg_match('/^tgp_fg_kind_(stars|premium)$/', $datain, $m)) { telegramFragmentShowProducts($m[1]); return true; }
    if (preg_match('/^tgp_fg_p_(\d+)$/', $datain, $m)) { telegramFragmentShowProduct($m[1]); return true; }
    if (preg_match('/^tgp_fg_buy_(\d+)$/', $datain, $m)) {
        $product = telegramFragmentProduct($m[1], true);
        if (!$product) { telegramProductsReply('پلن در دسترس نیست.', null); return true; }
        step('tgp_fg_recipient_' . $product['id'], $from_id);
        $user['step'] = 'tgp_fg_recipient_' . $product['id'];
        $rows = [[['text' => '❌ انصراف', 'callback_data' => 'tgp_fg_p_' . $product['id'], 'style' => 'danger']]];
        telegramProductsReply("👤 <b>نام کاربری گیرنده</b>\n\nنام کاربری تلگرام را بدون @ ارسال کنید.\nنمونه: <code>username</code>", json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
        return true;
    }
    if (preg_match('/^tgp_fg_pay_(\d+)$/', $datain, $m)) { telegramFragmentPayOrder($m[1]); return true; }
    if (preg_match('/^tgp_fg_order_(\d+)$/', $datain, $m)) { telegramFragmentShowOrder($m[1]); return true; }
    return true;
}

function telegramFragmentRefundOrder($orderId, $reason)
{
    global $pdo;
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM telegram_fragment_orders WHERE id=? FOR UPDATE');
        $stmt->execute([(int) $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($order && (int) $order['wallet_debited'] === 1 && (int) $order['wallet_refunded'] === 0 && empty($order['tx_hash'])) {
            $pdo->prepare('UPDATE user SET Balance=Balance+? WHERE id=?')->execute([(int) $order['price'], $order['user_id']]);
            $pdo->prepare("UPDATE telegram_fragment_orders SET wallet_refunded=1,status='failed',last_error=? WHERE id=?")->execute([mb_substr((string) $reason, 0, 1000), $order['id']]);
        } elseif ($order) {
            $pdo->prepare("UPDATE telegram_fragment_orders SET status='review',last_error=? WHERE id=?")->execute([mb_substr((string) $reason, 0, 1000), $order['id']]);
        }
        $pdo->commit();
        if (function_exists('clearSelectCache')) clearSelectCache('user');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function telegramFragmentNotifyOrder(array $order, $status, $message = '')
{
    global $pdo;
    if (($order['notified_status'] ?? '') === $status) return;
    $text = $status === 'completed'
        ? "✅ <b>سفارش با موفقیت تکمیل شد</b>\n\nسفارش <code>#{$order['id']}</code> برای @" . telegramFragmentEscape($order['recipient']) . ' انجام شد.'
        : "❌ <b>ناموفق</b>\n\nسفارش <code>#{$order['id']}</code> انجام نشد." . ((int) ($order['wallet_refunded'] ?? 0) === 1 ? "\nمبلغ کامل به کیف پول شما برگشت." : "\nدر حال بررسی توسط پشتیبانی هستیم.");
    sendmessage($order['user_id'], $text, json_encode(['inline_keyboard' => [[['text' => 'مشاهده سفارش', 'callback_data' => 'tgp_fg_order_' . $order['id']]]]], JSON_UNESCAPED_UNICODE), 'HTML');
    $pdo->prepare('UPDATE telegram_fragment_orders SET notified_status=? WHERE id=?')->execute([$status, $order['id']]);
    telegramProductsReport($status === 'completed' ? 'sale' : 'error', "<b>گزارش Fragment</b>\n\nسفارش: <code>#{$order['id']}</code>\nکاربر: <code>" . telegramFragmentEscape($order['user_id']) . "</code>\nگیرنده: @" . telegramFragmentEscape($order['recipient']) . "\nوضعیت: " . telegramFragmentStatusLabel($status) . ($message !== '' ? "\nجزئیات: <code>" . telegramFragmentEscape(mb_substr($message, 0, 600)) . '</code>' : ''));
}

function telegramFragmentMaybeBalanceAlert()
{
    $last = strtotime(telegramFragmentSetting('last_balance_check_at', '1970-01-01 00:00:00')) ?: 0;
    if ($last > time() - 900) return;
    telegramFragmentSetSetting('last_balance_check_at', date('Y-m-d H:i:s'));
    try {
        telegramFragmentBoot();
        $status = FragmentKit::status();
        $balance = isset($status['wallet']['balance']) ? (float) $status['wallet']['balance'] : null;
        telegramFragmentSetSetting('last_session_ok', !empty($status['session']['loggedIn']) ? '1' : '0');
        if ($balance !== null) telegramFragmentSetSetting('last_balance', (string) $balance);
        $low = (float) telegramFragmentSetting('low_balance_ton', '2');
        $lastAlert = strtotime(telegramFragmentSetting('last_low_balance_alert_at', '1970-01-01 00:00:00')) ?: 0;
        if ($low > 0 && $balance !== null && $balance < $low && $lastAlert < time() - 21600) {
            telegramFragmentSetSetting('last_low_balance_alert_at', date('Y-m-d H:i:s'));
            telegramProductsReport('alert', "<b>هشدار موجودی Fragment</b>\n\nموجودی فعلی: <code>{$balance} TON</code>\nحد هشدار: <code>{$low} TON</code>");
        }
    } catch (Throwable $e) {
        error_log('Fragment balance check failed: ' . $e->getMessage());
    }
}

function telegramFragmentProcessPendingOrders($limit = 3)
{
    global $pdo;
    telegramFragmentEnsureSchema();
    if (telegramFragmentSetting('enabled', '0') !== '1' || telegramFragmentSetting('dry_run', '1') === '1') return 0;
    $lock = (int) $pdo->query("SELECT GET_LOCK('mirza_fragment_worker',0)")->fetchColumn();
    if ($lock !== 1) return 0;
    $processed = 0;
    try {
        $pdo->exec("UPDATE telegram_fragment_orders SET status='queued',next_attempt_at=NOW(),last_error=CONCAT(COALESCE(last_error,''),' [recovered]') WHERE status='processing' AND updated_at < DATE_SUB(NOW(),INTERVAL 15 MINUTE)");
        telegramFragmentBoot();
        for ($i = 0; $i < max(1, min(20, (int) $limit)); $i++) {
            $pdo->beginTransaction();
            $stmt = $pdo->query("SELECT * FROM telegram_fragment_orders WHERE status IN ('queued','confirm_pending') AND (next_attempt_at IS NULL OR next_attempt_at<=NOW()) ORDER BY id LIMIT 1 FOR UPDATE");
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) { $pdo->commit(); break; }
            $pdo->prepare("UPDATE telegram_fragment_orders SET status='processing',attempt_count=attempt_count+1 WHERE id=?")->execute([$order['id']]);
            $pdo->commit();
            try {
                $result = FragmentKit::buy($order['kind'], $order['recipient'], (int) $order['product_amount'], $order['idempotency_key']);
                $stmt = $pdo->prepare("UPDATE telegram_fragment_orders SET status='completed',tx_hash=?,total_ton=?,last_error=NULL,completed_at=NOW(),next_attempt_at=NULL WHERE id=?");
                $stmt->execute([$result['txHash'] ?? null, $result['totalTon'] ?? null, $order['id']]);
                $order['status'] = 'completed';
                $order['tx_hash'] = $result['txHash'] ?? null;
                telegramFragmentNotifyOrder($order, 'completed');
            } catch (FragmentError $e) {
                $retry = $e->retryable || in_array($e->errCode, ['confirm_pending', 'tx_failed', 'signer_error'], true);
                if ($retry) {
                    $attempt = (int) $order['attempt_count'] + 1;
                    $status = $attempt >= 12 ? 'review' : 'confirm_pending';
                    $delay = min(3600, 60 * max(1, $attempt));
                    $stmt = $pdo->prepare('UPDATE telegram_fragment_orders SET status=?,last_error=?,next_attempt_at=DATE_ADD(NOW(),INTERVAL ? SECOND) WHERE id=?');
                    $stmt->execute([$status, mb_substr($e->getMessage(), 0, 1000), $delay, $order['id']]);
                    if ($status === 'review') {
                        $order['wallet_refunded'] = 0;
                        telegramFragmentNotifyOrder($order, 'review', $e->getMessage());
                    }
                } else {
                    telegramFragmentRefundOrder($order['id'], $e->getMessage());
                    $stmt = $pdo->prepare('SELECT * FROM telegram_fragment_orders WHERE id=?');
                    $stmt->execute([$order['id']]);
                    telegramFragmentNotifyOrder($stmt->fetch(PDO::FETCH_ASSOC), 'failed', $e->getMessage());
                }
            } catch (InvalidArgumentException $e) {
                telegramFragmentRefundOrder($order['id'], $e->getMessage());
                $stmt = $pdo->prepare('SELECT * FROM telegram_fragment_orders WHERE id=?');
                $stmt->execute([$order['id']]);
                telegramFragmentNotifyOrder($stmt->fetch(PDO::FETCH_ASSOC), 'failed', $e->getMessage());
            } catch (Throwable $e) {
                $stmt = $pdo->prepare("UPDATE telegram_fragment_orders SET status='confirm_pending',last_error=?,next_attempt_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE) WHERE id=?");
                $stmt->execute([mb_substr($e->getMessage(), 0, 1000), $order['id']]);
                error_log('Fragment worker uncertain error #' . $order['id'] . ': ' . $e->getMessage());
            }
            $processed++;
        }
    } finally {
        try { $pdo->query("SELECT RELEASE_LOCK('mirza_fragment_worker')"); } catch (Throwable $e) { }
    }
    telegramFragmentMaybeBalanceAlert();
    return $processed;
}

function telegramFragmentAdminHomeButton(array &$rows)
{
    array_unshift($rows, [['text' => '💎 پریمیوم و استارز خودکار ⭐', 'callback_data' => 'vsa_fg_home', 'style' => 'primary']]);
}

function telegramFragmentMasked($value)
{
    $value = trim((string) $value);
    if ($value === '') return 'ثبت نشده';
    return strlen($value) <= 18 ? telegramFragmentEscape($value) : telegramFragmentEscape(substr($value, 0, 9) . '...' . substr($value, -7));
}

function telegramFragmentStatusSnapshot($live = false)
{
    $snapshot = [
        'signer' => false, 'wallet' => false, 'address' => '', 'balance' => null,
        'version' => telegramFragmentSetting('wallet_version', 'v4r2'), 'api_key' => false,
        'session' => false, 'error' => '',
    ];
    try {
        telegramFragmentBoot();
        $cfg = Fragment::signerConfig();
        $snapshot['signer'] = true;
        $snapshot['wallet'] = !empty($cfg['configured']);
        $snapshot['address'] = (string) ($cfg['address'] ?? '');
        $snapshot['version'] = (string) ($cfg['walletVersion'] ?? $snapshot['version']);
        $snapshot['api_key'] = !empty($cfg['hasApiKey']);
        if ($live && $snapshot['wallet']) {
            $status = FragmentKit::status();
            $snapshot['session'] = !empty($status['session']['loggedIn']);
            $snapshot['balance'] = isset($status['wallet']['balance']) ? (float) $status['wallet']['balance'] : null;
            if (!empty($status['errors'])) $snapshot['error'] = implode(' | ', array_values($status['errors']));
        } else {
            $snapshot['session'] = telegramFragmentSetting('last_session_ok', '0') === '1';
            $lastBalance = telegramFragmentSetting('last_balance', '');
            $snapshot['balance'] = $lastBalance === '' ? null : (float) $lastBalance;
        }
    } catch (Throwable $e) {
        $snapshot['error'] = $e->getMessage();
    }
    return $snapshot;
}

function telegramFragmentAdminHome()
{
    global $pdo;
    telegramFragmentEnsureSchema();
    $status = telegramFragmentStatusSnapshot(false);
    $enabled = telegramFragmentSetting('enabled', '0') === '1';
    $dryRun = telegramFragmentSetting('dry_run', '1') === '1';
    $showSender = telegramFragmentSetting('show_sender', '0') === '1';
    $low = (float) telegramFragmentSetting('low_balance_ton', '2');
    $plans = (int) $pdo->query('SELECT COUNT(*) FROM telegram_fragment_products')->fetchColumn();
    $queue = (int) $pdo->query("SELECT COUNT(*) FROM telegram_fragment_orders WHERE status IN ('queued','processing','confirm_pending','review')")->fetchColumn();
    $last = telegramFragmentSetting('last_check', 'هنوز انجام نشده');
    $text = "💎 <b>اتصال Fragment</b>\n\n";
    $text .= "پکیج‌های پریمیوم و استارز پس از پرداخت کاربر، به‌صورت خودکار از Fragment و با کیف پول TON خریداری می‌شوند.\n\n<blockquote>";
    $text .= 'فروش خودکار: ' . ($enabled ? '✅ روشن' : '❌ خاموش') . "\n";
    $text .= 'حالت پردازش: ' . ($dryRun ? '🧪 آزمایشی' : '✅ واقعی') . "\n";
    $text .= 'سرویس امضا: ' . ($status['signer'] ? '✅ متصل' : '❌ قطع') . "\n";
    $text .= 'ورود در Fragment: ' . ($status['session'] ? '✅ تأیید شده' : '⚪ نیازمند بررسی') . "\n";
    $text .= 'کیف پول: ' . ($status['wallet'] ? '<code>' . telegramFragmentMasked($status['address']) . '</code>' : '❌ ثبت نشده') . "\n";
    if ($status['balance'] !== null) $text .= 'موجودی: <code>' . telegramFragmentEscape((string) $status['balance']) . " TON</code>\n";
    $text .= 'نسخه کیف پول: <code>' . telegramFragmentEscape(strtoupper($status['version'])) . "</code>\n";
    $text .= 'کلید TON RPC: ' . ($status['api_key'] ? '✅ ثبت شده' : '⚪ ثبت نشده') . "\n";
    $text .= 'نمایش نام فرستنده: ' . ($showSender ? '✅ روشن' : '❌ خاموش') . "\n";
    $text .= 'هشدار موجودی کمتر از: <code>' . ($low > 0 ? $low . ' TON' : 'خاموش') . "</code>\n";
    $text .= "پلن‌ها: <code>{$plans}</code> | سفارش‌های باز: <code>{$queue}</code>\n";
    $text .= 'آخرین بررسی: ' . telegramFragmentEscape($last) . '</blockquote>';
    if (!$status['signer'] && $status['error'] !== '') {
        $dockerInstance = preg_replace('/[^a-z0-9-]/', '', strtolower((string) getenv('MIRZA_DOCKER_INSTANCE')));
        $repairCommand = $dockerInstance !== ''
            ? 'sudo mirza bot-repair --id ' . $dockerInstance
            : 'sudo bash ' . __DIR__ . '/services/fragment-signer/install-service.sh';
        $text .= "

⚠️ <b>علت قطعی سرویس امضا:</b>
<code>" . telegramFragmentEscape(mb_substr($status['error'], 0, 300)) . "</code>

راه‌حل (روی سرور، یک‌بار): <code>" . telegramFragmentEscape($repairCommand) . "</code>";
    }
    $rows = [
        [['text' => 'فروش خودکار: ' . ($enabled ? 'روشن' : 'خاموش'), 'callback_data' => 'vsa_fg_toggle', 'style' => $enabled ? 'success' : 'danger']],
        [
            ['text' => 'کلید کیف پول', 'callback_data' => 'vsa_fg_wallet'],
            ['text' => 'ورود خودکار Fragment', 'callback_data' => 'vsa_fg_login'],
        ],
        [
            ['text' => 'نسخه: ' . strtoupper($status['version']), 'callback_data' => 'vsa_fg_version'],
            ['text' => 'کلید TON RPC', 'callback_data' => 'vsa_fg_tonkey'],
        ],
        [
            ['text' => 'نام فرستنده: ' . ($showSender ? 'روشن' : 'خاموش'), 'callback_data' => 'vsa_fg_sender'],
            ['text' => 'هشدار موجودی', 'callback_data' => 'vsa_fg_low'],
        ],
        [
            ['text' => 'حالت: ' . ($dryRun ? 'آزمایشی' : 'واقعی'), 'callback_data' => 'vsa_fg_dryrun'],
            ['text' => 'سقف‌های تراکنش', 'callback_data' => 'vsa_fg_limits'],
        ],
        [
            ['text' => 'تمدید نشست', 'callback_data' => 'vsa_fg_refresh'],
            ['text' => 'بررسی اتصال', 'callback_data' => 'vsa_fg_test'],
        ],
        [['text' => 'مدیریت پکیج‌ها', 'callback_data' => 'vsa_fg_products', 'style' => 'primary']],
        [['text' => 'سفارش‌های Fragment', 'callback_data' => 'vsa_fg_orders']],
        [['text' => 'راهنما', 'callback_data' => 'vsa_fg_help']],
        [['text' => 'بازگشت', 'callback_data' => 'vsa_home', 'style' => 'danger']],
    ];
    virtualServicesAdminReply($text, $rows);
}

function telegramFragmentAdminCheck($login = false)
{
    try {
        telegramFragmentBoot();
        if ($login) FragmentKit::login();
        $status = FragmentKit::status();
        $ok = !empty($status['wallet']['connected']) && !empty($status['session']['loggedIn']);
        telegramFragmentSetSetting('last_check', date('Y-m-d H:i:s'));
        telegramFragmentSetSetting('last_check_ok', $ok ? '1' : '0');
        telegramFragmentSetSetting('last_session_ok', !empty($status['session']['loggedIn']) ? '1' : '0');
        if (isset($status['wallet']['balance'])) telegramFragmentSetSetting('last_balance', (string) $status['wallet']['balance']);
        $balance = (float) ($status['wallet']['balance'] ?? 0);
        $low = (float) telegramFragmentSetting('low_balance_ton', '2');
        $text = $ok ? '<b>اتصال Fragment موفق است</b>' : '<b>بررسی اتصال کامل نشد</b>';
        $text .= "\n\nکیف پول: " . (!empty($status['wallet']['connected']) ? 'متصل' : 'نامتصل');
        $text .= "\nنشست Fragment: " . (!empty($status['session']['loggedIn']) ? 'فعال' : 'غیرفعال');
        $text .= "\nموجودی: <code>{$balance} TON</code>";
        if ($low > 0 && $balance < $low) $text .= "\n\n⚠️ موجودی از حد هشدار کمتر است.";
        if (!empty($status['errors'])) $text .= "\n\n<code>" . telegramFragmentEscape(implode(' | ', array_values($status['errors']))) . '</code>';
        virtualServicesAdminReply($text, [[['text' => 'بازگشت', 'callback_data' => 'vsa_fg_home']]]);
    } catch (Throwable $e) {
        telegramFragmentSetSetting('last_check', date('Y-m-d H:i:s'));
        telegramFragmentSetSetting('last_check_ok', '0');
        $rows = [];
        if ($login) {
            $rows[] = [['text' => 'تلاش مجدد برای ورود خودکار', 'callback_data' => 'vsa_fg_login', 'style' => 'success']];
            $rows[] = [['text' => 'روش جایگزین ورود', 'callback_data' => 'vsa_fg_login_fallback']];
        }
        $rows[] = [['text' => 'بازگشت', 'callback_data' => 'vsa_fg_home']];
        virtualServicesAdminReply("<b>اتصال ناموفق بود</b>\n\n<code>" . telegramFragmentEscape($e->getMessage()) . '</code>', $rows);
    }
}

function telegramFragmentAdminProducts()
{
    $products = telegramFragmentProducts(null, false);
    $rows = [];
    foreach ($products as $product) {
        $rows[] = [['text' => ($product['is_active'] ? '✅ ' : '❌ ') . $product['title'] . ' | ' . telegramFragmentMoney($product['price']), 'callback_data' => 'vsa_fg_p_' . $product['id']]];
    }
    $rows[] = [
        ['text' => 'افزودن استارز', 'callback_data' => 'vsa_fg_add_stars', 'style' => 'primary'],
        ['text' => 'افزودن پریمیوم', 'callback_data' => 'vsa_fg_add_premium', 'style' => 'success'],
    ];
    $rows[] = [['text' => 'بازگشت', 'callback_data' => 'vsa_fg_home']];
    virtualServicesAdminReply("<b>مدیریت پکیج‌های Fragment</b>\n\nبرای ویرایش یا تغییر وضعیت، روی پکیج بزنید.", $rows);
}

function telegramFragmentAdminProduct($id)
{
    $product = telegramFragmentProduct($id, false);
    if (!$product) { telegramFragmentAdminProducts(); return; }
    $text = '<b>' . telegramFragmentEscape($product['title']) . "</b>\n\n";
    $text .= 'نوع: ' . ($product['kind'] === 'stars' ? 'استارز' : 'پریمیوم') . "\n";
    $text .= 'مقدار: <code>' . (int) $product['amount'] . "</code>\n";
    $text .= 'قیمت: ' . telegramFragmentMoney($product['price']) . "\n";
    $text .= 'وضعیت: ' . ($product['is_active'] ? 'فعال' : 'غیرفعال');
    $rows = [
        [
            ['text' => 'تغییر قیمت', 'callback_data' => 'vsa_fg_price_' . $product['id']],
            ['text' => $product['is_active'] ? 'غیرفعال‌سازی' : 'فعال‌سازی', 'callback_data' => 'vsa_fg_togglep_' . $product['id']],
        ],
        [['text' => 'حذف پکیج', 'callback_data' => 'vsa_fg_delete_' . $product['id'], 'style' => 'danger']],
        [['text' => 'بازگشت', 'callback_data' => 'vsa_fg_products']],
    ];
    virtualServicesAdminReply($text, $rows);
}

function telegramFragmentAdminOrders()
{
    global $pdo;
    $orders = $pdo->query('SELECT * FROM telegram_fragment_orders ORDER BY id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
    $rows = [];
    foreach ($orders as $order) {
        $rows[] = [['text' => '#' . $order['id'] . ' | ' . $order['recipient'] . ' | ' . telegramFragmentStatusLabel($order['status']), 'callback_data' => 'vsa_fg_o_' . $order['id']]];
    }
    $rows[] = [['text' => 'بازگشت', 'callback_data' => 'vsa_fg_home']];
    virtualServicesAdminReply("<b>سفارش‌های Fragment</b>\n\nآخرین سفارش‌ها و وضعیت پردازش آن‌ها:", $rows);
}

function telegramFragmentAdminOrder($id)
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM telegram_fragment_orders WHERE id=?');
    $stmt->execute([(int) $id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) { telegramFragmentAdminOrders(); return; }
    $text = "<b>سفارش Fragment #{$order['id']}</b>\n\n";
    $text .= 'کاربر: <code>' . telegramFragmentEscape($order['user_id']) . "</code>\n";
    $text .= 'گیرنده: @' . telegramFragmentEscape($order['recipient']) . "\n";
    $text .= 'محصول: ' . telegramFragmentEscape($order['product_title']) . "\n";
    $text .= 'وضعیت: ' . telegramFragmentStatusLabel($order['status']) . "\n";
    $text .= 'تعداد تلاش: <code>' . (int) $order['attempt_count'] . '</code>';
    if ($order['last_error']) $text .= "\n\n<b>آخرین خطا:</b>\n<code>" . telegramFragmentEscape(mb_substr($order['last_error'], 0, 900)) . '</code>';
    $rows = [];
    if (in_array($order['status'], ['failed', 'review'], true) && (int) $order['wallet_refunded'] === 0) $rows[] = [['text' => 'ارسال مجدد به صف', 'callback_data' => 'vsa_fg_retry_' . $order['id'], 'style' => 'success']];
    if (in_array($order['status'], ['failed', 'review'], true) && (int) $order['wallet_debited'] === 1 && (int) $order['wallet_refunded'] === 0) {
        $rows[] = [['text' => 'بازگشت وجه به کیف پول کاربر', 'callback_data' => 'vsa_fg_refund_' . $order['id'], 'style' => 'danger']];
        $text .= "\n\n⚠️ پیش از بازگشت وجه، در کیف پول TON مطمئن شوید تراکنشی برای این سفارش خارج نشده است.";
    }
    $rows[] = [['text' => 'بازگشت', 'callback_data' => 'vsa_fg_orders']];
    virtualServicesAdminReply($text, $rows);
}

function telegramFragmentDeleteIncomingSecret()
{
    global $from_id, $message_id;
    if ($message_id) {
        try { deletemessage($from_id, $message_id); } catch (Throwable $e) { }
    }
}

function telegramFragmentAdminHandleRequest()
{
    global $datain, $text, $user, $from_id, $pdo;
    $state = (string) ($user['step'] ?? '');
    if (strpos((string) $datain, 'vsa_fg_') !== 0 && strpos($state, 'vsa_fg_') !== 0) return false;
    if (function_exists('telegramProductsAdminCan') && !telegramProductsAdminCan($from_id, 'settings')) {
        virtualServicesAdminReply('شما به این بخش دسترسی ندارید.', [[['text' => 'بازگشت', 'callback_data' => 'vsa_home']]]);
        return true;
    }
    telegramFragmentEnsureSchema();
    if ($datain !== '' && strpos($state, 'vsa_fg_') === 0) {
        virtualServicesAdminClearState();
        $state = 'home';
    }
    if ($datain === '' && strpos($state, 'vsa_fg_') === 0) {
        try {
            if ($state === 'vsa_fg_wallet_input') {
                telegramFragmentDeleteIncomingSecret();
                telegramFragmentBoot();
                $words = preg_split('/\s+/', strtolower(trim((string) $text))) ?: [];
                if (count($words) !== 24) throw new InvalidArgumentException('عبارت بازیابی باید دقیقاً ۲۴ کلمه انگلیسی باشد.');
                Fragment::signerConfigSave(['mnemonic' => implode(' ', $words), 'walletVersion' => telegramFragmentSetting('wallet_version', 'v4r2')]);
                virtualServicesAdminClearState();
                sendmessage($from_id, 'کیف پول به‌صورت رمزنگاری‌شده در سرویس امضا ثبت شد. پیام حاوی کلید هم حذف شد.', null, 'HTML');
                telegramFragmentAdminHome();
                return true;
            }
            if ($state === 'vsa_fg_cookie_input') {
                telegramFragmentDeleteIncomingSecret();
                telegramFragmentBoot();
                $count = FragmentKit::importCookies(trim((string) $text));
                virtualServicesAdminClearState();
                sendmessage($from_id, "نشست Fragment ذخیره شد ({$count} کوکی). پیام حاوی کوکی حذف شد.", null, 'HTML');
                telegramFragmentAdminCheck(false);
                return true;
            }
            if ($state === 'vsa_fg_tonkey_input') {
                telegramFragmentDeleteIncomingSecret();
                $key = trim((string) $text);
                if (strlen($key) < 8 || strlen($key) > 200) throw new InvalidArgumentException('کلید API معتبر نیست.');
                telegramFragmentBoot();
                Fragment::signerConfigSave(['apiKey' => $key]);
                virtualServicesAdminClearState();
                sendmessage($from_id, 'کلید TON RPC به‌صورت رمزنگاری‌شده ذخیره شد و پیام شما حذف شد.', null, 'HTML');
                telegramFragmentAdminHome();
                return true;
            }
            if (preg_match('/^vsa_fg_add_(stars|premium)$/', $state, $m)) {
                $parts = array_map('trim', explode('|', telegramFragmentDigits($text)));
                if (count($parts) !== 3 || $parts[0] === '' || !ctype_digit($parts[1]) || !ctype_digit($parts[2])) throw new InvalidArgumentException('فرمت اطلاعات صحیح نیست.');
                $amount = (int) $parts[1];
                $price = (int) $parts[2];
                if ($m[1] === 'stars' && ($amount < 50 || $amount > 1000000)) throw new InvalidArgumentException('تعداد استارز باید بین ۵۰ تا ۱۰۰۰۰۰۰ باشد.');
                if ($m[1] === 'premium' && !in_array($amount, [3, 6, 12], true)) throw new InvalidArgumentException('پریمیوم فقط برای ۳، ۶ یا ۱۲ ماه مجاز است.');
                if ($price < 1) throw new InvalidArgumentException('قیمت باید بیشتر از صفر باشد.');
                $nextSort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM telegram_fragment_products')->fetchColumn();
                $stmt = $pdo->prepare('INSERT INTO telegram_fragment_products (kind,title,amount,price,sort_order) VALUES (?,?,?,?,?)');
                $stmt->execute([$m[1], mb_substr($parts[0], 0, 190), $amount, $price, $nextSort]);
                virtualServicesAdminClearState();
                telegramFragmentAdminProducts();
                return true;
            }
            if (preg_match('/^vsa_fg_price_(\d+)$/', $state, $m)) {
                $price = telegramFragmentDigits(trim((string) $text));
                if (!ctype_digit($price) || (int) $price < 1) throw new InvalidArgumentException('قیمت معتبر را فقط به‌صورت عدد بفرستید.');
                $pdo->prepare('UPDATE telegram_fragment_products SET price=? WHERE id=?')->execute([(int) $price, (int) $m[1]]);
                virtualServicesAdminClearState();
                telegramFragmentAdminProduct($m[1]);
                return true;
            }
            if ($state === 'vsa_fg_low_input') {
                $value = telegramFragmentDigits(trim((string) $text));
                if (!is_numeric($value) || (float) $value < 0 || (float) $value > 100000) throw new InvalidArgumentException('مقدار معتبر وارد کنید.');
                telegramFragmentSetSetting('low_balance_ton', (string) (float) $value);
                virtualServicesAdminClearState(); telegramFragmentAdminHome(); return true;
            }
            if ($state === 'vsa_fg_limits_input') {
                $parts = array_map('trim', explode('|', telegramFragmentDigits($text)));
                if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1]) || (float) $parts[0] <= 0 || (float) $parts[1] <= 0 || (float) $parts[0] > (float) $parts[1]) throw new InvalidArgumentException('سقف‌ها معتبر نیستند.');
                telegramFragmentBoot();
                Fragment::signerConfigSave(['maxTonPerTx' => (float) $parts[0], 'maxTonPerDay' => (float) $parts[1]]);
                telegramFragmentSetSetting('per_tx_limit_ton', (string) (float) $parts[0]);
                telegramFragmentSetSetting('daily_limit_ton', (string) (float) $parts[1]);
                virtualServicesAdminClearState(); telegramFragmentAdminHome(); return true;
            }
        } catch (Throwable $e) {
            virtualServicesAdminReply("<b>ذخیره نشد</b>\n\n<code>" . telegramFragmentEscape($e->getMessage()) . '</code>', [[['text' => 'انصراف', 'callback_data' => 'vsa_fg_home']]]);
            return true;
        }
    }

    if ($datain === 'vsa_fg_home') { virtualServicesAdminClearState(); telegramFragmentAdminHome(); return true; }
    if ($datain === 'vsa_fg_toggle') {
        $enable = telegramFragmentSetting('enabled', '0') !== '1';
        if ($enable) {
            $status = telegramFragmentStatusSnapshot(false);
            if (!$status['signer'] || !$status['wallet']) {
                virtualServicesAdminReply('پیش از روشن‌کردن فروش، سرویس امضا و کیف پول را تنظیم کنید.', [[['text' => 'بازگشت', 'callback_data' => 'vsa_fg_home']]]);
                return true;
            }
        }
        telegramFragmentSetSetting('enabled', $enable ? '1' : '0'); telegramFragmentAdminHome(); return true;
    }
    if ($datain === 'vsa_fg_dryrun') {
        $real = telegramFragmentSetting('dry_run', '1') === '1';
        telegramFragmentSetSetting('dry_run', $real ? '0' : '1');
        telegramFragmentAdminHome(); return true;
    }
    if ($datain === 'vsa_fg_wallet') {
        virtualServicesAdminSetState('vsa_fg_wallet_input');
        virtualServicesAdminReply("<b>ثبت کیف پول TON</b>\n\nعبارت بازیابی ۲۴ کلمه‌ای را در یک پیام بفرستید.\n\nپیام فوراً حذف و کلید فقط به‌صورت رمزنگاری‌شده در سرویس محلی امضا نگهداری می‌شود.", [[['text' => 'انصراف', 'callback_data' => 'vsa_fg_home', 'style' => 'danger']]]);
        return true;
    }
    if ($datain === 'vsa_fg_login' || $datain === 'vsa_fg_autologin') { telegramFragmentAdminCheck(true); return true; }
    if ($datain === 'vsa_fg_login_fallback') {
        virtualServicesAdminReply("<b>روش جایگزین ورود</b>\n\nاین بخش فقط زمانی لازم است که Fragment ورود خودکار با ولت را برای IP سرور رد کند یا تأیید اضافی بخواهد.", [
            [['text' => 'ورود خودکار با ولت', 'callback_data' => 'vsa_fg_login', 'style' => 'success']],
            [['text' => 'ثبت نشست مرورگر', 'callback_data' => 'vsa_fg_cookie']],
            [['text' => 'بازگشت', 'callback_data' => 'vsa_fg_home']],
        ]); return true;
    }
    if ($datain === 'vsa_fg_cookie') {
        virtualServicesAdminSetState('vsa_fg_cookie_input');
        virtualServicesAdminReply("Cookie header ایجادشده بعد از ورود به <code>fragment.com</code> را در یک پیام بفرستید.\n\nپیام شما بلافاصله حذف می‌شود.", [[['text' => 'انصراف', 'callback_data' => 'vsa_fg_home', 'style' => 'danger']]]); return true;
    }
    if ($datain === 'vsa_fg_tonkey') {
        virtualServicesAdminSetState('vsa_fg_tonkey_input');
        virtualServicesAdminReply("کلید API سرویس TON RPC را ارسال کنید.\n\nپیام بلافاصله حذف و کلید به‌صورت رمزنگاری‌شده ذخیره می‌شود.", [[['text' => 'انصراف', 'callback_data' => 'vsa_fg_home']]]); return true;
    }
    if ($datain === 'vsa_fg_version') {
        $rows = [[['text' => 'V4R2', 'callback_data' => 'vsa_fg_setver_v4r2'], ['text' => 'V5R1', 'callback_data' => 'vsa_fg_setver_v5r1']], [['text' => 'بازگشت', 'callback_data' => 'vsa_fg_home']]];
        virtualServicesAdminReply('نسخه کیف پولی را انتخاب کنید که عبارت بازیابی به آن متعلق است.', $rows); return true;
    }
    if (preg_match('/^vsa_fg_setver_(v4r2|v5r1)$/', $datain, $m)) {
        telegramFragmentBoot(); Fragment::signerConfigSave(['walletVersion' => $m[1]]); telegramFragmentSetSetting('wallet_version', $m[1]); telegramFragmentAdminHome(); return true;
    }
    if ($datain === 'vsa_fg_sender') { telegramFragmentSetSetting('show_sender', telegramFragmentSetting('show_sender', '0') === '1' ? '0' : '1'); telegramFragmentAdminHome(); return true; }
    if ($datain === 'vsa_fg_low') { virtualServicesAdminSetState('vsa_fg_low_input'); virtualServicesAdminReply('حد هشدار موجودی TON را به‌صورت عدد بفرستید. عدد صفر هشدار را خاموش می‌کند.', [[['text' => 'انصراف', 'callback_data' => 'vsa_fg_home']]]); return true; }
    if ($datain === 'vsa_fg_limits') { virtualServicesAdminSetState('vsa_fg_limits_input'); virtualServicesAdminReply("سقف هر تراکنش و سقف روزانه TON را با | بفرستید.\n\nنمونه: <code>20 | 100</code>", [[['text' => 'انصراف', 'callback_data' => 'vsa_fg_home']]]); return true; }
    if ($datain === 'vsa_fg_test') { telegramFragmentAdminCheck(false); return true; }
    if ($datain === 'vsa_fg_refresh') { telegramFragmentAdminCheck(true); return true; }
    if ($datain === 'vsa_fg_products') { telegramFragmentAdminProducts(); return true; }
    if ($datain === 'vsa_fg_orders') { telegramFragmentAdminOrders(); return true; }
    if ($datain === 'vsa_fg_help') {
        $text = "<b>راهنمای Fragment</b>\n\n۱. کیف پول TON را ثبت کنید.\n۲. نسخه درست کیف پول را انتخاب کنید.\n۳. با دکمه ورود Fragment، نشست را فعال کنید.\n۴. کلید TON RPC و سقف‌ها را تنظیم کنید.\n۵. ابتدا در حالت آزمایشی اتصال را بررسی کرده، سپس حالت واقعی و فروش را روشن کنید.\n\nسفارش‌ها با کلید یکتا اجرا می‌شوند؛ تکرار worker باعث پرداخت دوباره نمی‌شود.";
        virtualServicesAdminReply($text, [[['text' => 'بازگشت', 'callback_data' => 'vsa_fg_home']]]); return true;
    }
    if (preg_match('/^vsa_fg_add_(stars|premium)$/', $datain, $m)) {
        virtualServicesAdminSetState('vsa_fg_add_' . $m[1]);
        $sample = $m[1] === 'stars' ? 'پکیج ۵۰ استارزی | 50 | 150000' : 'پریمیوم ۳ ماهه | 3 | 650000';
        virtualServicesAdminReply("عنوان، مقدار و قیمت تومانی را در یک پیام بفرستید:\n\n<code>{$sample}</code>", [[['text' => 'انصراف', 'callback_data' => 'vsa_fg_products']]]); return true;
    }
    if (preg_match('/^vsa_fg_p_(\d+)$/', $datain, $m)) { telegramFragmentAdminProduct($m[1]); return true; }
    if (preg_match('/^vsa_fg_togglep_(\d+)$/', $datain, $m)) { $pdo->prepare('UPDATE telegram_fragment_products SET is_active=1-is_active WHERE id=?')->execute([(int) $m[1]]); telegramFragmentAdminProduct($m[1]); return true; }
    if (preg_match('/^vsa_fg_price_(\d+)$/', $datain, $m)) { virtualServicesAdminSetState('vsa_fg_price_' . $m[1]); virtualServicesAdminReply('قیمت جدید را به تومان و فقط به‌صورت عدد بفرستید.', [[['text' => 'انصراف', 'callback_data' => 'vsa_fg_p_' . $m[1]]]]); return true; }
    if (preg_match('/^vsa_fg_delete_(\d+)$/', $datain, $m)) {
        $used = $pdo->prepare('SELECT COUNT(*) FROM telegram_fragment_orders WHERE product_id=?'); $used->execute([(int) $m[1]]);
        if ((int) $used->fetchColumn() > 0) $pdo->prepare('UPDATE telegram_fragment_products SET is_active=0 WHERE id=?')->execute([(int) $m[1]]);
        else $pdo->prepare('DELETE FROM telegram_fragment_products WHERE id=?')->execute([(int) $m[1]]);
        telegramFragmentAdminProducts(); return true;
    }
    if (preg_match('/^vsa_fg_o_(\d+)$/', $datain, $m)) { telegramFragmentAdminOrder($m[1]); return true; }
    if (preg_match('/^vsa_fg_refund_(\d+)$/', $datain, $m)) {
        $stmt = $pdo->prepare('SELECT status,wallet_debited,wallet_refunded FROM telegram_fragment_orders WHERE id=?');
        $stmt->execute([(int) $m[1]]);
        $o = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($o && in_array($o['status'], ['failed', 'review'], true) && (int) $o['wallet_debited'] === 1 && (int) $o['wallet_refunded'] === 0) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('SELECT * FROM telegram_fragment_orders WHERE id=? FOR UPDATE');
                $stmt->execute([(int) $m[1]]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($order && (int) $order['wallet_refunded'] === 0) {
                    $pdo->prepare('UPDATE user SET Balance=Balance+? WHERE id=?')->execute([(int) $order['price'], $order['user_id']]);
                    $pdo->prepare("UPDATE telegram_fragment_orders SET wallet_refunded=1,status='failed' WHERE id=?")->execute([$order['id']]);
                }
                $pdo->commit();
                if (function_exists('clearSelectCache')) clearSelectCache('user');
                if ($order) {
                    $order['wallet_refunded'] = 1;
                    $order['notified_status'] = '';
                    telegramFragmentNotifyOrder($order, 'failed');
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Fragment manual refund failed: ' . $e->getMessage());
            }
        }
        telegramFragmentAdminOrder($m[1]); return true;
    }
    if (preg_match('/^vsa_fg_retry_(\d+)$/', $datain, $m)) { $pdo->prepare("UPDATE telegram_fragment_orders SET status='queued',next_attempt_at=NOW(),last_error=NULL WHERE id=? AND wallet_refunded=0 AND status IN ('failed','review')")->execute([(int) $m[1]]); telegramFragmentAdminOrder($m[1]); return true; }
    return true;
}

