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

function telegramFragmentEnsureColumn($table, $column, $definition)
{
    global $pdo;
    $allowed = ['telegram_fragment_orders'];
    if (!in_array($table, $allowed, true) || !preg_match('/^[a-z0-9_]+$/i', $column)) return;
    $quotedColumn = $pdo->quote($column);
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$quotedColumn}");
    if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
        try {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1060) throw $e;
        }
    }
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

    telegramFragmentEnsureColumn('telegram_fragment_orders', 'base_price', 'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER price');
    telegramFragmentEnsureColumn('telegram_fragment_orders', 'quote_ton', 'DECIMAL(20,9) NULL AFTER base_price');
    telegramFragmentEnsureColumn('telegram_fragment_orders', 'ton_rate', 'BIGINT UNSIGNED NULL AFTER quote_ton');
    telegramFragmentEnsureColumn('telegram_fragment_orders', 'profit_amount', 'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER ton_rate');
    telegramFragmentEnsureColumn('telegram_fragment_orders', 'pricing_mode', "VARCHAR(20) NOT NULL DEFAULT 'fixed' AFTER profit_amount");
    telegramFragmentEnsureColumn('telegram_fragment_orders', 'quoted_at', 'DATETIME NULL AFTER pricing_mode');

    $insert = $pdo->prepare('INSERT IGNORE INTO telegram_fragment_settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ([
        'enabled' => '0',
        'menu_title' => 'استارز و پریمیوم خودکار',
        'wallet_version' => 'v4r2',
        'daily_limit_ton' => '100',
        'per_tx_limit_ton' => '20',
        'low_balance_ton' => '2',
        'show_sender' => '0',
        'last_check' => 'هنوز انجام نشده',
        'last_check_ok' => '0',
        'live_pricing_enabled' => '1',
        'stars_custom_enabled' => '1',
        'stars_custom_min' => '50',
        'stars_custom_max' => '1000000',
        'profit_percent_stars' => '10',
        'profit_percent_premium' => '10',
        'profit_fixed_stars' => '0',
        'profit_fixed_premium' => '0',
        'price_rounding' => '1000',
        'quote_valid_minutes' => '10',
        'button_emoji_stars' => '5280962371207077415',
        'button_emoji_premium' => '5350481089817232086',
        'button_emoji_action' => '5348090777308251395',
        'button_emoji_navigation' => '5348418461838098123',
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
        'dryRun' => false,
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

function telegramFragmentErrorCode($error)
{
    if (is_object($error) && property_exists($error, 'errCode')) {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $error->errCode));
    }
    $message = (string) ($error instanceof Throwable ? $error->getMessage() : $error);
    if (preg_match('/^([a-z0-9_\-]{2,40})\|/i', $message, $match)) {
        return strtolower($match[1]);
    }
    return '';
}

function telegramFragmentSafeReason($error)
{
    $code = telegramFragmentErrorCode($error);
    $message = mb_strtolower((string) ($error instanceof Throwable ? $error->getMessage() : $error), 'UTF-8');
    $reasons = [
        'invalid_username' => 'نام کاربری گیرنده معتبر نیست.',
        'user_not_found' => 'حساب تلگرام گیرنده پیدا نشد؛ نام کاربری را بررسی کنید.',
        'already_premium' => 'حساب گیرنده در حال حاضر اشتراک پریمیوم فعال دارد.',
        'invalid_quantity' => 'تعداد استارز انتخاب‌شده معتبر نیست.',
        'invalid_months' => 'مدت اشتراک پریمیوم معتبر نیست.',
        'rate_limit' => 'Fragment موقتاً درخواست‌های زیادی دریافت کرده است؛ سفارش دوباره بررسی می‌شود.',
        'session_expired' => 'اتصال Fragment نیازمند تمدید نشست است.',
        'need_verify' => 'اتصال Fragment نیازمند تأیید دوباره است.',
        'login_failed' => 'ورود خودکار Fragment کامل نشد.',
        'blocked' => 'دسترسی سرور به Fragment موقتاً محدود شده است.',
        'signer_down' => 'سرویس پردازش تراکنش موقتاً در دسترس نیست.',
        'signer_auth' => 'ارتباط امن سرویس پردازش نیازمند بازبینی است.',
        'signer_missing' => 'سرویس پردازش تراکنش هنوز آماده نشده است.',
        'signer_error' => 'سرویس پردازش تراکنش پاسخ معتبر نداد.',
        'insecure_signer' => 'مسیر امن سرویس پردازش تکمیل نشده است.',
        'daily_limit' => 'سقف خرید روزانه کیف پول TON تکمیل شده است.',
        'amount_mismatch' => 'مبلغ اعلام‌شده با تراکنش یکسان نبود و خرید برای امنیت متوقف شد.',
        'insufficient_usdt' => 'موجودی کیف پول پرداخت برای این سفارش کافی نیست.',
        'confirm_pending' => 'تراکنش ارسال شده و هنوز در انتظار تأیید شبکه است.',
        'tx_failed' => 'تراکنش در شبکه تأیید نشد.',
        'page_changed' => 'ارتباط با Fragment نیازمند بازبینی است.',
        'bad_response' => 'پاسخ معتبر از Fragment دریافت نشد؛ سفارش دوباره بررسی می‌شود.',
        'bad_request' => 'Fragment درخواست خرید را نپذیرفت.',
        'fragment_error' => 'Fragment نتوانست درخواست خرید را پردازش کند.',
        'temporary_error' => 'پردازش خودکار سفارش در این لحظه کامل نشد.',
        'unavailable' => 'Fragment موقتاً در دسترس نیست.',
        'network' => 'ارتباط با Fragment موقتاً برقرار نشد.',
        'rate_unavailable' => 'نرخ لحظه‌ای GRAM موقتاً در دسترس نیست؛ چند لحظه دیگر دوباره تلاش کنید.',
        'market_closed' => 'بازار GRAM در نوبیتکس بسته است؛ پس از باز شدن بازار دوباره تلاش کنید.',
        'price_unavailable' => 'قیمت لحظه‌ای این محصول موقتاً قابل محاسبه نیست.',
    ];
    if (isset($reasons[$code])) return $reasons[$code];
    if (preg_match('/already.*premium|پریمیوم.*(فعال|دارد)/u', $message)) return $reasons['already_premium'];
    if (preg_match('/not found|پیدا نشد|username|recipient|گیرنده/u', $message)) return $reasons['user_not_found'];
    if (preg_match('/موجودی|balance|insufficient/u', $message)) return 'موجودی کیف پول پرداخت برای این سفارش کافی نیست.';
    if (preg_match('/سقف|limit|محدود/u', $message)) return 'یکی از محدودیت‌های ایمنی خرید تکمیل شده است.';
    if (preg_match('/سرویس.*(پردازش|امضا)|signer/u', $message)) return 'سرویس پردازش تراکنش موقتاً در دسترس نیست.';
    return 'پردازش خودکار سفارش در این لحظه کامل نشد.';
}

function telegramFragmentStoredError($error)
{
    $code = telegramFragmentErrorCode($error) ?: 'temporary_error';
    return mb_substr($code . '|' . telegramFragmentSafeReason($error), 0, 500);
}

function telegramFragmentLogFailure($context, $error, $orderId = null)
{
    $raw = (string) ($error instanceof Throwable ? $error->getMessage() : $error);
    $raw = preg_replace('/(authorization:\s*bearer|bearer|token|api[_-]?key|cookie|mnemonic)\s*[=:]\s*[^\s&]+/iu', '$1=***', $raw);
    error_log('Fragment ' . $context . ($orderId !== null ? ' #' . (int) $orderId : '') . ': ' . mb_substr($raw, 0, 1000));
}

function telegramFragmentFailureCard($reason, $orderId = null, $refunded = false, $pending = false)
{
    $title = $pending ? 'سفارش نیازمند بررسی است' : 'خرید تکمیل نشد';
    $text = '<b>' . $title . "</b>\n\n<blockquote><b>دلیل:</b> " . telegramFragmentEscape($reason);
    if ($orderId !== null) $text .= "\n<b>شماره پیگیری:</b> <code>#" . (int) $orderId . '</code>';
    $text .= '</blockquote>';
    if ($refunded) $text .= "\n\nمبلغ کامل به کیف پول شما بازگشت داده شد.";
    elseif ($pending) $text .= "\n\nسفارش محفوظ است و بدون پرداخت دوباره بررسی می‌شود.";
    else $text .= "\n\nمبلغی از کیف پول شما کسر نشد.";
    return $text;
}

function telegramFragmentMoney($value)
{
    return number_format((int) $value) . ' تومان';
}

function telegramFragmentButton($text, $callbackData, $style = 'primary', $emojiSlot = 'action')
{
    $setting = [
        'stars' => 'button_emoji_stars',
        'premium' => 'button_emoji_premium',
        'navigation' => 'button_emoji_navigation',
        'action' => 'button_emoji_action',
    ][$emojiSlot] ?? 'button_emoji_action';
    return telegramProductsStyledButton($text, $callbackData, $style, telegramFragmentSetting($setting, ''));
}

function telegramFragmentPriceFromQuote($totalTon, $tonTomanRate, $profitPercent, $fixedProfit, $rounding)
{
    $totalTon = (float) $totalTon;
    $tonTomanRate = (float) $tonTomanRate;
    $profitPercent = max(0, min(10000, (float) $profitPercent));
    $fixedProfit = max(0, (int) $fixedProfit);
    $rounding = max(1, (int) $rounding);
    if (!is_finite($totalTon) || !is_finite($tonTomanRate) || $totalTon <= 0 || $tonTomanRate <= 0) {
        throw new RuntimeException('price_unavailable|قیمت لحظه‌ای معتبر دریافت نشد.');
    }
    $base = (int) ceil($totalTon * $tonTomanRate);
    $beforeRound = (int) ceil($base * (1 + ($profitPercent / 100)) + $fixedProfit);
    $final = (int) (ceil($beforeRound / $rounding) * $rounding);
    return [
        'base' => $base,
        'final' => max(1, $final),
        'profit' => max(0, $final - $base),
    ];
}

function telegramFragmentExtractNobitexRate(array $payload, $marketType)
{
    if (strtolower((string) ($payload['status'] ?? '')) !== 'ok') {
        return 0.0;
    }
    if ($marketType === 'rls') {
        $market = [];
        foreach (['gram-rls', 'GRAM-RLS'] as $symbol) {
            if (is_array($payload['stats'][$symbol] ?? null)) {
                $market = $payload['stats'][$symbol];
                break;
            }
        }
        if (($market['isClosed'] ?? false) === true) return 0.0;
        $rialPrice = $market['bestSell'] ?? 0;
        return is_numeric($rialPrice) && (float) $rialPrice > 0 ? (float) $rialPrice / 10 : 0.0;
    }
    if ($marketType !== 'irt') return 0.0;
    $ask = $payload['asks'][0] ?? null;
    $rialPrice = is_array($ask) ? ($ask[0] ?? 0) : 0;
    return is_numeric($rialPrice) && (float) $rialPrice > 0 ? (float) $rialPrice / 10 : 0.0;
}

function telegramFragmentNobitexTonRate($force = false)
{
    static $requestRate = null;
    if (!$force && $requestRate !== null) return $requestRate;
    $cached = (float) telegramFragmentSetting('nobitex_gram_toman_v2', '0');
    $cachedAt = strtotime(telegramFragmentSetting('nobitex_gram_rate_at_v2', '1970-01-01 00:00:00')) ?: 0;
    if (!$force && $cached > 0 && $cachedAt >= time() - 60) return $requestRate = $cached;

    require_once __DIR__ . '/fragment-kit/php/HttpClient.php';
    $rate = 0.0;
    $marketClosed = false;
    try {
        $response = HttpClient::send('GET', 'https://api.nobitex.ir/market/stats?srcCurrency=gram&dstCurrency=rls', ['timeout' => 8]);
        if ((int) $response['status'] >= 200 && (int) $response['status'] < 300) {
            $payload = json_decode((string) $response['body'], true);
            if (is_array($payload)) {
                $market = $payload['stats']['gram-rls'] ?? $payload['stats']['GRAM-RLS'] ?? null;
                $marketClosed = strtolower((string) ($payload['status'] ?? '')) === 'ok'
                    && is_array($market) && ($market['isClosed'] ?? false) === true;
                $rate = telegramFragmentExtractNobitexRate($payload, 'rls');
            }
        }
    } catch (Throwable $e) {
        telegramFragmentLogFailure('nobitex rate', $e);
    }
    if ($marketClosed) throw new RuntimeException('market_closed|بازار GRAM در نوبیتکس بسته است.');
    if ($rate <= 0) {
        try {
            $response = HttpClient::send('GET', 'https://api.nobitex.ir/v3/orderbook/GRAMIRT', ['timeout' => 8]);
            if ((int) $response['status'] >= 200 && (int) $response['status'] < 300) {
                $payload = json_decode((string) $response['body'], true);
                if (is_array($payload)) $rate = telegramFragmentExtractNobitexRate($payload, 'irt');
            }
        } catch (Throwable $e) {
            telegramFragmentLogFailure('nobitex fallback rate', $e);
        }
    }
    if ($rate > 1000 && $rate < 10000000000) {
        telegramFragmentSetSetting('nobitex_gram_toman_v2', (string) round($rate, 2));
        telegramFragmentSetSetting('nobitex_gram_rate_at_v2', date('Y-m-d H:i:s'));
        // Existing consumers can still read the corrected value during a rolling update.
        telegramFragmentSetSetting('nobitex_gram_toman', (string) round($rate, 2));
        telegramFragmentSetSetting('nobitex_gram_rate_at', date('Y-m-d H:i:s'));
        telegramFragmentSetSetting('nobitex_ton_toman', (string) round($rate, 2));
        telegramFragmentSetSetting('nobitex_ton_rate_at', date('Y-m-d H:i:s'));
        return $requestRate = $rate;
    }
    if (!$force && $cached > 0 && $cachedAt >= time() - 120) return $requestRate = $cached;
    throw new RuntimeException('rate_unavailable|نرخ لحظه‌ای GRAM موقتاً در دسترس نیست.');
}

function telegramFragmentLivePrice($kind, $recipient, $amount)
{
    telegramFragmentBoot();
    $quote = FragmentKit::quote($kind, $recipient, (int) $amount);
    $ton = (float) ($quote['totalTon'] ?? 0);
    $rate = telegramFragmentNobitexTonRate();
    $percent = (float) telegramFragmentSetting('profit_percent_' . $kind, '10');
    $fixed = (int) telegramFragmentSetting('profit_fixed_' . $kind, '0');
    $price = telegramFragmentPriceFromQuote($ton, $rate, $percent, $fixed, telegramFragmentSetting('price_rounding', '1000'));
    return $price + ['total_ton' => $ton, 'ton_rate' => $rate, 'quote' => $quote];
}

function telegramFragmentCustomStarsBounds()
{
    $min = max(50, (int) telegramFragmentSetting('stars_custom_min', '50'));
    $max = min(1000000, max($min, (int) telegramFragmentSetting('stars_custom_max', '1000000')));
    return [$min, $max];
}

function telegramFragmentSetUserPayload(array $payload)
{
    global $pdo, $from_id, $user;
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare('UPDATE user SET Processing_value=? WHERE id=?')->execute([$json, (string) $from_id]);
    $user['Processing_value'] = $json;
}

function telegramFragmentUserPayload()
{
    global $user;
    $payload = json_decode((string) ($user['Processing_value'] ?? ''), true);
    return is_array($payload) ? $payload : [];
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
    array_unshift($rows, [telegramFragmentButton(telegramFragmentSetting('menu_title', 'استارز و پریمیوم خودکار'), 'tgp_fg_home', 'primary', 'premium')]);
}

function telegramFragmentShowHome()
{
    $rows = [
        [telegramFragmentButton('خرید استارز تلگرام', 'tgp_fg_kind_stars', 'primary', 'stars')],
        [telegramFragmentButton('خرید تلگرام پریمیوم', 'tgp_fg_kind_premium', 'success', 'premium')],
        [telegramFragmentButton('سفارش‌های من', 'tgp_fg_orders', 'primary', 'action')],
        [telegramFragmentButton('بازگشت', 'tgp_home', 'danger', 'navigation')],
    ];
    $text = "<b>خرید خودکار محصولات تلگرام</b>\n\n";
    $text .= "<blockquote>تحویل کاملاً خودکار پس از پرداخت\nقیمت‌گذاری لحظه‌ای و شفاف\nبدون نیاز به ورود به حساب شما</blockquote>\n\n";
    $text .= 'محصول موردنظر را انتخاب کنید.';
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramFragmentShowProducts($kind)
{
    $products = telegramFragmentProducts($kind, true);
    $rows = [];
    foreach ($products as $product) {
        $priceText = telegramFragmentSetting('live_pricing_enabled', '1') === '1' ? 'قیمت لحظه‌ای' : telegramFragmentMoney($product['price']);
        $rows[] = [telegramFragmentButton($product['title'] . ' • ' . $priceText, 'tgp_fg_p_' . $product['id'], $kind === 'stars' ? 'primary' : 'success', $kind)];
    }
    if ($kind === 'stars' && telegramFragmentSetting('stars_custom_enabled', '1') === '1') {
        $rows[] = [telegramFragmentButton('مقدار دلخواه استارز', 'tgp_fg_custom_stars', 'success', 'stars')];
    }
    $rows[] = [telegramFragmentButton('بازگشت', 'tgp_fg_home', 'danger', 'navigation')];
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
    $priceText = telegramFragmentSetting('live_pricing_enabled', '1') === '1' ? 'پس از بررسی گیرنده محاسبه می‌شود' : telegramFragmentMoney($product['price']);
    $text = "<b>جزئیات انتخاب شما</b>\n\n<blockquote><b>محصول:</b> " . telegramFragmentEscape($product['title']) . "\n{$kindText}\n<b>قیمت:</b> {$priceText}</blockquote>\n\nدر مرحله بعد نام کاربری گیرنده را وارد می‌کنید.";
    $rows = [
        [telegramFragmentButton('ادامه خرید', 'tgp_fg_buy_' . $product['id'], 'success', 'action')],
        [telegramFragmentButton('بازگشت', 'tgp_fg_kind_' . $product['kind'], 'danger', 'navigation')],
    ];
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramFragmentCreateDraft($productId, $recipient, $customAmount = null)
{
    global $pdo, $from_id;
    $product = $productId === null ? null : telegramFragmentProduct($productId, true);
    if ($productId !== null && !$product) {
        telegramProductsReply('پلن انتخاب‌شده دیگر در دسترس نیست.', null);
        return;
    }
    $recipient = strtolower(ltrim(trim((string) $recipient), '@'));
    if (!preg_match('/^[a-z][a-z0-9_]{4,31}$/', $recipient)) {
        telegramProductsReply("⚠️ نام کاربری معتبر نیست. دوباره بفرستید.\nنمونه: <code>username</code>", null);
        return false;
    }
    if ($product === null) {
        [$min, $max] = telegramFragmentCustomStarsBounds();
        $customAmount = (int) $customAmount;
        if ($customAmount < $min || $customAmount > $max || telegramFragmentSetting('stars_custom_enabled', '1') !== '1') {
            telegramProductsReply('مقدار استارز خارج از بازه مجاز است.', null);
            return false;
        }
        $product = ['id' => null, 'title' => number_format($customAmount) . ' استارز دلخواه', 'kind' => 'stars', 'amount' => $customAmount, 'price' => 0];
    }
    $pricing = ['base' => (int) $product['price'], 'final' => (int) $product['price'], 'profit' => 0, 'total_ton' => null, 'ton_rate' => null];
    $pricingMode = 'fixed';
    if (telegramFragmentSetting('live_pricing_enabled', '1') === '1' || $productId === null) {
        try {
            $pricing = telegramFragmentLivePrice($product['kind'], $recipient, (int) $product['amount']);
            $pricingMode = 'live';
        } catch (Throwable $e) {
            telegramFragmentLogFailure('live quote', $e);
            telegramProductsReply(telegramFragmentFailureCard(telegramFragmentSafeReason($e), null, false, false), json_encode(['inline_keyboard' => [[telegramFragmentButton('تلاش دوباره', $productId === null ? 'tgp_fg_custom_stars' : 'tgp_fg_p_' . $productId, 'primary', 'action')], [telegramFragmentButton('بازگشت', 'tgp_fg_home', 'danger', 'navigation')]]], JSON_UNESCAPED_UNICODE));
            return false;
        }
    }
    $idem = 'mirza-fg-' . substr(hash('sha256', $from_id . '|' . ($productId ?? 'custom') . '|' . microtime(true) . '|' . random_int(1, PHP_INT_MAX)), 0, 42);
    $stmt = $pdo->prepare("INSERT INTO telegram_fragment_orders
        (user_id,product_id,product_title,kind,recipient,product_amount,price,base_price,quote_ton,ton_rate,profit_amount,pricing_mode,quoted_at,idempotency_key)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?)");
    $stmt->execute([(string) $from_id, $product['id'], $product['title'], $product['kind'], $recipient, $product['amount'], $pricing['final'], $pricing['base'], $pricing['total_ton'], $pricing['ton_rate'], $pricing['profit'], $pricingMode, $idem]);
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
    $text = "<b>فاکتور نهایی خرید</b>\n\n<blockquote>";
    $text .= '<b>محصول:</b> ' . telegramFragmentEscape($order['product_title']) . "\n";
    $text .= '<b>پلن:</b> ' . $detail . "\n";
    $text .= '<b>گیرنده:</b> @' . telegramFragmentEscape($order['recipient']) . "\n";
    $text .= '<b>مبلغ نهایی:</b> ' . telegramFragmentMoney($order['price']) . "\n";
    if (($order['pricing_mode'] ?? '') === 'live') $text .= '<b>نوع قیمت:</b> لحظه‌ای و معتبر تا ' . (int) telegramFragmentSetting('quote_valid_minutes', '10') . " دقیقه\n";
    $text .= '<b>روش تحویل:</b> خودکار</blockquote>\n\n';
    $text .= 'گیرنده و مبلغ را بررسی کنید. خرید تکمیل‌شده قابل برگشت نیست.';
    $rows = [
        [telegramFragmentButton('تأیید و پرداخت', 'tgp_fg_pay_' . $order['id'], 'success', 'action')],
        [telegramFragmentButton('انصراف', 'tgp_fg_home', 'danger', 'navigation')],
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
    $paymentCommitted = false;
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
        $product = $order['product_id'] === null ? null : telegramFragmentProduct($order['product_id'], true);
        $livePricing = ($order['pricing_mode'] ?? '') === 'live';
        if ($order['product_id'] !== null && (!$product || (int) $product['amount'] !== (int) $order['product_amount'] || (!$livePricing && (int) $product['price'] !== (int) $order['price']))) {
            $pdo->rollBack();
            telegramProductsReply('اطلاعات این پلن تغییر کرده است؛ مبلغی کسر نشد. لطفاً سفارش تازه‌ای بسازید.', null);
            return;
        }
        if ($order['product_id'] === null) {
            [$min, $max] = telegramFragmentCustomStarsBounds();
            if ($order['kind'] !== 'stars' || (int) $order['product_amount'] < $min || (int) $order['product_amount'] > $max) {
                $pdo->rollBack();
                telegramProductsReply('مقدار سفارش دلخواه دیگر در بازه مجاز نیست؛ مبلغی کسر نشد.', null);
                return;
            }
        }
        if ($livePricing) {
            $validMinutes = max(1, min(60, (int) telegramFragmentSetting('quote_valid_minutes', '10')));
            $quotedAt = strtotime((string) ($order['quoted_at'] ?? $order['created_at']));
            if (!$quotedAt || $quotedAt < time() - ($validMinutes * 60)) {
                $pdo->rollBack();
                $back = $order['product_id'] === null ? 'tgp_fg_custom_stars' : 'tgp_fg_p_' . $order['product_id'];
                telegramProductsReply('اعتبار قیمت لحظه‌ای این فاکتور تمام شده است. برای دریافت قیمت تازه دوباره ادامه دهید.', json_encode(['inline_keyboard' => [[telegramFragmentButton('دریافت قیمت تازه', $back, 'primary', 'action')], [telegramFragmentButton('بازگشت', 'tgp_fg_home', 'danger', 'navigation')]]], JSON_UNESCAPED_UNICODE));
                return;
            }
        }
        $stmt = $pdo->prepare('SELECT Balance,agent,maxbuyagent FROM user WHERE id=? FOR UPDATE');
        $stmt->execute([(string) $from_id]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        $balance = (int) ($wallet['Balance'] ?? 0);
        $credit = ($wallet['agent'] ?? '') === 'n2' ? (int) ($wallet['maxbuyagent'] ?? 0) : 0;
        if ($balance < (int) $order['price'] && !($credit > 0 && $balance - (int) $order['price'] >= -$credit)) {
            $pdo->rollBack();
            $back = $order['product_id'] === null ? 'tgp_fg_custom_stars' : 'tgp_fg_p_' . $order['product_id'];
            $rows = [[telegramFragmentButton('افزایش موجودی', 'account', 'success', 'action')], [telegramFragmentButton('بازگشت', $back, 'danger', 'navigation')]];
            telegramProductsReply('موجودی کیف پول برای این خرید کافی نیست.', json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
            return;
        }
        $pdo->prepare('UPDATE user SET Balance=Balance-? WHERE id=?')->execute([(int) $order['price'], (string) $from_id]);
        $pdo->prepare("UPDATE telegram_fragment_orders SET wallet_debited=1,status='queued',paid_at=NOW(),next_attempt_at=NOW(),last_error=NULL WHERE id=?")->execute([$order['id']]);
        $pdo->commit();
        $paymentCommitted = true;
        if (function_exists('clearSelectCache')) clearSelectCache('user');
        $text = "<b>پرداخت با موفقیت ثبت شد</b>\n\n<blockquote><b>شماره سفارش:</b> <code>#{$order['id']}</code>\n<b>محصول:</b> " . telegramFragmentEscape($order['product_title']) . "\n<b>گیرنده:</b> @" . telegramFragmentEscape($order['recipient']) . "\n<b>مبلغ:</b> " . telegramFragmentMoney($order['price']) . "\n<b>وضعیت:</b> در صف خرید خودکار</blockquote>\n\nنتیجه سفارش پس از پردازش برای شما ارسال می‌شود.";
        $rows = [[telegramFragmentButton('مشاهده وضعیت', 'tgp_fg_order_' . $order['id'], 'primary', 'action')], [telegramFragmentButton('بازگشت', 'tgp_fg_home', 'danger', 'navigation')]];
        telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
        telegramProductsReport('sale', "<b>سفارش خودکار Fragment</b>\n\nسفارش: <code>#{$order['id']}</code>\nکاربر: <code>{$from_id}</code>\nگیرنده: @" . telegramFragmentEscape($order['recipient']) . "\nوضعیت: در صف پردازش");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        telegramFragmentLogFailure('payment', $e, $orderId);
        $reason = telegramFragmentSafeReason($e);
        if ($paymentCommitted) {
            $text = "<b>پرداخت ثبت شده است</b>\n\n<blockquote><b>شماره پیگیری:</b> <code>#" . (int) $orderId . "</code>\n<b>وضعیت:</b> سفارش در صف پردازش خودکار قرار دارد.</blockquote>\n\nبرای این سفارش دوباره پرداخت نکنید.";
            $rows = [[telegramFragmentButton('مشاهده وضعیت', 'tgp_fg_order_' . (int) $orderId, 'primary', 'action')], [telegramFragmentButton('بازگشت', 'tgp_fg_home', 'danger', 'navigation')]];
            telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
        } else {
            $rows = [[telegramFragmentButton('تلاش دوباره', 'tgp_fg_pay_' . (int) $orderId, 'primary', 'action')], [telegramFragmentButton('بازگشت', 'tgp_fg_home', 'danger', 'navigation')]];
            telegramProductsReply(telegramFragmentFailureCard($reason, $orderId, false, false), json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
        }
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
    if (in_array($order['status'], ['failed', 'review'], true) && !empty($order['last_error'])) {
        $text .= "\n<b>دلیل:</b> " . telegramFragmentEscape(telegramFragmentSafeReason($order['last_error']));
    }
    if ($order['status'] === 'failed' && (int) $order['wallet_refunded'] === 1) $text .= "\n\nمبلغ کامل به کیف پول برگشت داده شد.";
    $rows = [[telegramFragmentButton('تازه‌سازی', 'tgp_fg_order_' . $order['id'], 'primary', 'action')], [telegramFragmentButton('بازگشت', 'tgp_fg_orders', 'danger', 'navigation')]];
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
        $rows[] = [telegramFragmentButton('#' . $order['id'] . ' | ' . $order['product_title'] . ' | ' . telegramFragmentStatusLabel($order['status']), 'tgp_fg_order_' . $order['id'], 'primary', 'action')];
    }
    $rows[] = [telegramFragmentButton('بازگشت', 'tgp_fg_home', 'danger', 'navigation')];
    $text = "<b>سفارش‌های خودکار من</b>" . (!$rows || count($rows) === 1 ? "\n\nهنوز سفارشی ثبت نشده است." : '');
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

/** نقطه‌ی ورود سمت کاربر: جزئیات داخلی فقط در لاگ امن می‌ماند. */
function telegramFragmentHandleUserRequest()
{
    try {
        return telegramFragmentHandleUserRequestInner();
    } catch (Throwable $e) {
        telegramFragmentLogFailure('user flow', $e);
        try { telegramProductsReply(telegramFragmentFailureCard(telegramFragmentSafeReason($e), null, false, false), null); } catch (Throwable $ignored) { }
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
    if ($datain === '' && $state === 'tgp_fg_custom_amount') {
        [$min, $max] = telegramFragmentCustomStarsBounds();
        $amount = telegramFragmentDigits(trim((string) $text));
        if (!ctype_digit($amount) || (int) $amount < $min || (int) $amount > $max) {
            telegramProductsReply('تعداد استارز باید عددی بین <code>' . number_format($min) . '</code> تا <code>' . number_format($max) . '</code> باشد.', json_encode(['inline_keyboard' => [[telegramFragmentButton('انصراف', 'tgp_fg_kind_stars', 'danger', 'navigation')]]], JSON_UNESCAPED_UNICODE));
            return true;
        }
        telegramFragmentSetUserPayload(['amount' => (int) $amount]);
        step('tgp_fg_custom_recipient', $from_id);
        $user['step'] = 'tgp_fg_custom_recipient';
        telegramProductsReply("<b>گیرنده استارز</b>\n\nنام کاربری تلگرام گیرنده را بدون @ ارسال کنید.\nنمونه: <code>username</code>", json_encode(['inline_keyboard' => [[telegramFragmentButton('انصراف', 'tgp_fg_kind_stars', 'danger', 'navigation')]]], JSON_UNESCAPED_UNICODE));
        return true;
    }
    if ($datain === '' && $state === 'tgp_fg_custom_recipient') {
        $payload = telegramFragmentUserPayload();
        if (telegramFragmentCreateDraft(null, $text, (int) ($payload['amount'] ?? 0)) !== false) {
            step('home', $from_id);
            telegramFragmentSetUserPayload([]);
            $user['step'] = 'home';
        }
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
    if ($datain === 'tgp_fg_custom_stars') {
        if (telegramFragmentSetting('stars_custom_enabled', '1') !== '1') { telegramFragmentShowProducts('stars'); return true; }
        [$min, $max] = telegramFragmentCustomStarsBounds();
        step('tgp_fg_custom_amount', $from_id);
        $user['step'] = 'tgp_fg_custom_amount';
        telegramFragmentSetUserPayload([]);
        $message = "<b>مقدار دلخواه استارز</b>\n\nتعداد موردنظر را فقط به‌صورت عدد ارسال کنید.\n\n<blockquote><b>حداقل:</b> " . number_format($min) . " استارز\n<b>حداکثر:</b> " . number_format($max) . " استارز\n<b>قیمت:</b> لحظه‌ای پس از بررسی گیرنده</blockquote>";
        telegramProductsReply($message, json_encode(['inline_keyboard' => [[telegramFragmentButton('انصراف', 'tgp_fg_kind_stars', 'danger', 'navigation')]]], JSON_UNESCAPED_UNICODE));
        return true;
    }
    if (preg_match('/^tgp_fg_p_(\d+)$/', $datain, $m)) { telegramFragmentShowProduct($m[1]); return true; }
    if (preg_match('/^tgp_fg_buy_(\d+)$/', $datain, $m)) {
        $product = telegramFragmentProduct($m[1], true);
        if (!$product) { telegramProductsReply('پلن در دسترس نیست.', null); return true; }
        step('tgp_fg_recipient_' . $product['id'], $from_id);
        $user['step'] = 'tgp_fg_recipient_' . $product['id'];
        $rows = [[telegramFragmentButton('انصراف', 'tgp_fg_p_' . $product['id'], 'danger', 'navigation')]];
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
    $reason = telegramFragmentSafeReason($message !== '' ? $message : ($order['last_error'] ?? ''));
    $text = $status === 'completed'
        ? "<b>سفارش با موفقیت تکمیل شد</b>\n\n<blockquote><b>شماره سفارش:</b> <code>#{$order['id']}</code>\n<b>محصول:</b> " . telegramFragmentEscape($order['product_title']) . "\n<b>گیرنده:</b> @" . telegramFragmentEscape($order['recipient']) . "\n<b>وضعیت:</b> تحویل‌شده</blockquote>"
        : telegramFragmentFailureCard($reason, $order['id'], (int) ($order['wallet_refunded'] ?? 0) === 1, $status === 'review');
    sendmessage($order['user_id'], $text, json_encode(['inline_keyboard' => [[telegramFragmentButton('مشاهده سفارش', 'tgp_fg_order_' . $order['id'], 'primary', 'action')]]], JSON_UNESCAPED_UNICODE), 'HTML');
    $pdo->prepare('UPDATE telegram_fragment_orders SET notified_status=? WHERE id=?')->execute([$status, $order['id']]);
    telegramProductsReport($status === 'completed' ? 'sale' : 'error', "<b>گزارش Fragment</b>\n\nسفارش: <code>#{$order['id']}</code>\nکاربر: <code>" . telegramFragmentEscape($order['user_id']) . "</code>\nگیرنده: @" . telegramFragmentEscape($order['recipient']) . "\nوضعیت: " . telegramFragmentStatusLabel($status) . ($status === 'completed' ? '' : "\nدلیل: " . telegramFragmentEscape($reason)));
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
    if (telegramFragmentSetting('enabled', '0') !== '1') return 0;
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
                    $storedError = telegramFragmentStoredError($e);
                    $stmt->execute([$status, $storedError, $delay, $order['id']]);
                    if ($status === 'review') {
                        $order['wallet_refunded'] = 0;
                        $order['last_error'] = $storedError;
                        telegramFragmentNotifyOrder($order, 'review', $storedError);
                    }
                } else {
                    telegramFragmentRefundOrder($order['id'], telegramFragmentStoredError($e));
                    $stmt = $pdo->prepare('SELECT * FROM telegram_fragment_orders WHERE id=?');
                    $stmt->execute([$order['id']]);
                    telegramFragmentNotifyOrder($stmt->fetch(PDO::FETCH_ASSOC), 'failed', telegramFragmentStoredError($e));
                }
            } catch (InvalidArgumentException $e) {
                telegramFragmentRefundOrder($order['id'], telegramFragmentStoredError($e));
                $stmt = $pdo->prepare('SELECT * FROM telegram_fragment_orders WHERE id=?');
                $stmt->execute([$order['id']]);
                telegramFragmentNotifyOrder($stmt->fetch(PDO::FETCH_ASSOC), 'failed', telegramFragmentStoredError($e));
            } catch (Throwable $e) {
                $attempt = (int) $order['attempt_count'] + 1;
                $status = $attempt >= 12 ? 'review' : 'confirm_pending';
                $storedError = telegramFragmentStoredError($e);
                $stmt = $pdo->prepare("UPDATE telegram_fragment_orders SET status=?,last_error=?,next_attempt_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE) WHERE id=?");
                $stmt->execute([$status, $storedError, $order['id']]);
                telegramFragmentLogFailure('worker uncertain', $e, $order['id']);
                if ($status === 'review') {
                    $order['last_error'] = $storedError;
                    $order['wallet_refunded'] = 0;
                    telegramFragmentNotifyOrder($order, 'review', $storedError);
                }
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
    array_unshift($rows, [['text' => 'پریمیوم و استارز خودکار', 'callback_data' => 'vsa_fg_home', 'style' => 'primary']]);
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
            if (!empty($status['errors'])) $snapshot['error'] = telegramFragmentSafeReason(reset($status['errors']));
        } else {
            $snapshot['session'] = telegramFragmentSetting('last_session_ok', '0') === '1';
            $lastBalance = telegramFragmentSetting('last_balance', '');
            $snapshot['balance'] = $lastBalance === '' ? null : (float) $lastBalance;
        }
    } catch (Throwable $e) {
        telegramFragmentLogFailure('status snapshot', $e);
        $snapshot['error'] = telegramFragmentSafeReason($e);
    }
    return $snapshot;
}

function telegramFragmentAdminHome()
{
    global $pdo;
    telegramFragmentEnsureSchema();
    $status = telegramFragmentStatusSnapshot(false);
    $enabled = telegramFragmentSetting('enabled', '0') === '1';
    $showSender = telegramFragmentSetting('show_sender', '0') === '1';
    $low = (float) telegramFragmentSetting('low_balance_ton', '2');
    $plans = (int) $pdo->query('SELECT COUNT(*) FROM telegram_fragment_products')->fetchColumn();
    $queue = (int) $pdo->query("SELECT COUNT(*) FROM telegram_fragment_orders WHERE status IN ('queued','processing','confirm_pending','review')")->fetchColumn();
    $last = telegramFragmentSetting('last_check', 'هنوز انجام نشده');
    $text = "<b>اتصال Fragment</b>\n\n";
    $text .= "پکیج‌های پریمیوم و استارز پس از پرداخت کاربر، به‌صورت خودکار از Fragment و با کیف پول TON خریداری می‌شوند.\n\n<blockquote>";
    $text .= 'فروش خودکار: ' . ($enabled ? '✅ روشن' : '❌ خاموش') . "\n";
    $text .= "پردازش سفارش‌ها: ✅ واقعی\n";
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
        $text .= "\n\n<b>وضعیت بررسی:</b> " . telegramFragmentEscape($status['error']);
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
        [['text' => 'سقف‌های تراکنش', 'callback_data' => 'vsa_fg_limits']],
        [
            ['text' => 'تمدید نشست', 'callback_data' => 'vsa_fg_refresh'],
            ['text' => 'بررسی اتصال', 'callback_data' => 'vsa_fg_test'],
        ],
        [['text' => 'مدیریت پکیج‌ها', 'callback_data' => 'vsa_fg_products', 'style' => 'primary']],
        [['text' => 'قیمت‌گذاری و مقدار دلخواه', 'callback_data' => 'vsa_fg_pricing', 'style' => 'success']],
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
        if (!empty($status['errors'])) $text .= "\n\n<b>نتیجه بررسی:</b> " . telegramFragmentEscape(telegramFragmentSafeReason(reset($status['errors'])));
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
        telegramFragmentLogFailure('admin connection check', $e);
        virtualServicesAdminReply("<b>بررسی اتصال کامل نشد</b>\n\n<b>دلیل:</b> " . telegramFragmentEscape(telegramFragmentSafeReason($e)), $rows);
    }
}

function telegramFragmentAdminProducts()
{
    $products = telegramFragmentProducts(null, false);
    $live = telegramFragmentSetting('live_pricing_enabled', '1') === '1';
    $rows = [];
    foreach ($products as $product) {
        $price = $live ? 'لحظه‌ای' : telegramFragmentMoney($product['price']);
        $rows[] = [['text' => ($product['is_active'] ? '✅ ' : '❌ ') . $product['title'] . ' | ' . $price, 'callback_data' => 'vsa_fg_p_' . $product['id']]];
    }
    $rows[] = [
        ['text' => 'افزودن استارز', 'callback_data' => 'vsa_fg_add_stars', 'style' => 'primary'],
        ['text' => 'افزودن پریمیوم', 'callback_data' => 'vsa_fg_add_premium', 'style' => 'success'],
    ];
    $rows[] = [['text' => 'بازگشت', 'callback_data' => 'vsa_fg_home']];
    virtualServicesAdminReply("<b>مدیریت پکیج‌های Fragment</b>\n\nبرای ویرایش یا تغییر وضعیت، روی پکیج بزنید.", $rows);
}

function telegramFragmentAdminPricing($refreshRate = false)
{
    $enabled = telegramFragmentSetting('live_pricing_enabled', '1') === '1';
    $custom = telegramFragmentSetting('stars_custom_enabled', '1') === '1';
    [$min, $max] = telegramFragmentCustomStarsBounds();
    $starsPercent = (float) telegramFragmentSetting('profit_percent_stars', '10');
    $premiumPercent = (float) telegramFragmentSetting('profit_percent_premium', '10');
    $starsFixed = (int) telegramFragmentSetting('profit_fixed_stars', '0');
    $premiumFixed = (int) telegramFragmentSetting('profit_fixed_premium', '0');
    $rounding = (int) telegramFragmentSetting('price_rounding', '1000');
    $rate = (float) telegramFragmentSetting('nobitex_gram_toman_v2', '0');
    $rateAt = telegramFragmentSetting('nobitex_gram_rate_at_v2', 'هنوز دریافت نشده');
    $rateError = '';
    if ($refreshRate) {
        try {
            $rate = telegramFragmentNobitexTonRate(true);
            $rateAt = telegramFragmentSetting('nobitex_gram_rate_at_v2', date('Y-m-d H:i:s'));
        } catch (Throwable $e) {
            telegramFragmentLogFailure('admin rate refresh', $e);
            $rateError = telegramFragmentSafeReason($e);
        }
    }
    $text = "<b>قیمت‌گذاری خودکار Fragment</b>\n\n<blockquote>";
    $text .= 'قیمت لحظه‌ای: ' . ($enabled ? 'فعال' : 'غیرفعال') . "\n";
    $text .= 'نرخ GRAM: ' . ($rate > 0 ? telegramFragmentMoney((int) round($rate)) : 'دریافت نشده') . "\n";
    $text .= 'آخرین دریافت نرخ: ' . telegramFragmentEscape($rateAt) . "\n";
    $text .= 'سود استارز: ' . $starsPercent . '% + ' . telegramFragmentMoney($starsFixed) . "\n";
    $text .= 'سود پریمیوم: ' . $premiumPercent . '% + ' . telegramFragmentMoney($premiumFixed) . "\n";
    $text .= 'گرد کردن مبلغ: ' . telegramFragmentMoney($rounding) . "\n";
    $text .= 'استارز دلخواه: ' . ($custom ? 'فعال' : 'غیرفعال') . "\n";
    $text .= 'بازه دلخواه: ' . number_format($min) . ' تا ' . number_format($max) . ' استارز</blockquote>';
    if ($rateError !== '') $text .= "\n\n<b>نتیجه دریافت نرخ:</b> " . telegramFragmentEscape($rateError);
    $rows = [
        [['text' => 'قیمت لحظه‌ای: ' . ($enabled ? 'فعال' : 'غیرفعال'), 'callback_data' => 'vsa_fg_livepricing', 'style' => $enabled ? 'success' : 'danger']],
        [
            ['text' => 'سود استارز', 'callback_data' => 'vsa_fg_profit_stars'],
            ['text' => 'سود پریمیوم', 'callback_data' => 'vsa_fg_profit_premium'],
        ],
        [
            ['text' => 'بازه استارز دلخواه', 'callback_data' => 'vsa_fg_custom_limits'],
            ['text' => 'مقدار دلخواه: ' . ($custom ? 'روشن' : 'خاموش'), 'callback_data' => 'vsa_fg_custom_toggle'],
        ],
        [
            ['text' => 'گرد کردن قیمت', 'callback_data' => 'vsa_fg_rounding'],
            ['text' => 'دریافت نرخ GRAM', 'callback_data' => 'vsa_fg_rate_test'],
        ],
        [['text' => 'بازگشت', 'callback_data' => 'vsa_fg_home', 'style' => 'danger']],
    ];
    virtualServicesAdminReply($text, $rows);
}

function telegramFragmentAdminProduct($id)
{
    $product = telegramFragmentProduct($id, false);
    if (!$product) { telegramFragmentAdminProducts(); return; }
    $text = '<b>' . telegramFragmentEscape($product['title']) . "</b>\n\n";
    $text .= 'نوع: ' . ($product['kind'] === 'stars' ? 'استارز' : 'پریمیوم') . "\n";
    $text .= 'مقدار: <code>' . (int) $product['amount'] . "</code>\n";
    $text .= 'قیمت دستی: ' . telegramFragmentMoney($product['price']) . "\n";
    if (telegramFragmentSetting('live_pricing_enabled', '1') === '1') $text .= "قیمت فروش: لحظه‌ای + سود تنظیم‌شده\n";
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
    if ($order['last_error']) $text .= "\n\n<b>دلیل وضعیت فعلی:</b> " . telegramFragmentEscape(telegramFragmentSafeReason($order['last_error']));
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
            if (preg_match('/^vsa_fg_profit_(stars|premium)_input$/', $state, $m)) {
                $parts = array_map('trim', explode('|', telegramFragmentDigits((string) $text)));
                if (count($parts) !== 2 || !is_numeric($parts[0]) || !ctype_digit($parts[1])) throw new InvalidArgumentException('سود را با فرمت درصد | مبلغ ثابت ارسال کنید.');
                $percent = (float) $parts[0];
                $fixed = (int) $parts[1];
                if ($percent < 0 || $percent > 10000 || $fixed < 0 || $fixed > 1000000000) throw new InvalidArgumentException('مقدار سود خارج از بازه مجاز است.');
                telegramFragmentSetSetting('profit_percent_' . $m[1], (string) $percent);
                telegramFragmentSetSetting('profit_fixed_' . $m[1], (string) $fixed);
                virtualServicesAdminClearState(); telegramFragmentAdminPricing(); return true;
            }
            if ($state === 'vsa_fg_custom_limits_input') {
                $parts = array_map('trim', explode('|', telegramFragmentDigits((string) $text)));
                if (count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) throw new InvalidArgumentException('حداقل و حداکثر را با | جدا کنید.');
                $min = (int) $parts[0]; $max = (int) $parts[1];
                if ($min < 50 || $max > 1000000 || $min > $max) throw new InvalidArgumentException('بازه باید بین ۵۰ تا ۱۰۰۰۰۰۰ استارز و حداقل کمتر از حداکثر باشد.');
                telegramFragmentSetSetting('stars_custom_min', (string) $min);
                telegramFragmentSetSetting('stars_custom_max', (string) $max);
                virtualServicesAdminClearState(); telegramFragmentAdminPricing(); return true;
            }
            if ($state === 'vsa_fg_rounding_input') {
                $value = telegramFragmentDigits(trim((string) $text));
                if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 1000000) throw new InvalidArgumentException('مقدار گرد کردن باید بین ۱ تا ۱۰۰۰۰۰۰ تومان باشد.');
                telegramFragmentSetSetting('price_rounding', (string) (int) $value);
                virtualServicesAdminClearState(); telegramFragmentAdminPricing(); return true;
            }
        } catch (Throwable $e) {
            telegramFragmentLogFailure('admin save', $e);
            $reason = $e instanceof InvalidArgumentException ? $e->getMessage() : telegramFragmentSafeReason($e);
            virtualServicesAdminReply("<b>ذخیره نشد</b>\n\n<b>دلیل:</b> " . telegramFragmentEscape($reason), [[['text' => 'انصراف', 'callback_data' => 'vsa_fg_home']]]);
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
    if ($datain === 'vsa_fg_dryrun') { telegramFragmentAdminHome(); return true; }
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
    if ($datain === 'vsa_fg_pricing') { telegramFragmentAdminPricing(); return true; }
    if ($datain === 'vsa_fg_livepricing') { telegramFragmentSetSetting('live_pricing_enabled', telegramFragmentSetting('live_pricing_enabled', '1') === '1' ? '0' : '1'); telegramFragmentAdminPricing(); return true; }
    if ($datain === 'vsa_fg_custom_toggle') { telegramFragmentSetSetting('stars_custom_enabled', telegramFragmentSetting('stars_custom_enabled', '1') === '1' ? '0' : '1'); telegramFragmentAdminPricing(); return true; }
    if ($datain === 'vsa_fg_profit_stars' || $datain === 'vsa_fg_profit_premium') {
        $kind = substr($datain, strlen('vsa_fg_profit_'));
        virtualServicesAdminSetState('vsa_fg_profit_' . $kind . '_input');
        virtualServicesAdminReply("درصد سود و مبلغ ثابت را با | جدا کنید.\n\nنمونه: <code>12.5 | 5000</code>\nبرای حذف مبلغ ثابت، صفر بفرستید.", [[['text' => 'انصراف', 'callback_data' => 'vsa_fg_pricing', 'style' => 'danger']]]);
        return true;
    }
    if ($datain === 'vsa_fg_custom_limits') {
        virtualServicesAdminSetState('vsa_fg_custom_limits_input');
        virtualServicesAdminReply("حداقل و حداکثر استارز دلخواه را با | جدا کنید.\n\nنمونه: <code>50 | 100000</code>", [[['text' => 'انصراف', 'callback_data' => 'vsa_fg_pricing', 'style' => 'danger']]]);
        return true;
    }
    if ($datain === 'vsa_fg_rounding') {
        virtualServicesAdminSetState('vsa_fg_rounding_input');
        virtualServicesAdminReply("قیمت نهایی به مضرب این مبلغ رو به بالا گرد می‌شود.\n\nنمونه: <code>1000</code>", [[['text' => 'انصراف', 'callback_data' => 'vsa_fg_pricing', 'style' => 'danger']]]);
        return true;
    }
    if ($datain === 'vsa_fg_rate_test') { telegramFragmentAdminPricing(true); return true; }
    if ($datain === 'vsa_fg_orders') { telegramFragmentAdminOrders(); return true; }
    if ($datain === 'vsa_fg_help') {
        $text = "<b>راهنمای Fragment</b>\n\n۱. کیف پول TON را ثبت کنید.\n۲. نسخه درست کیف پول را انتخاب کنید.\n۳. با دکمه ورود Fragment، نشست را فعال کنید.\n۴. کلید TON RPC و سقف‌ها را تنظیم کنید.\n۵. نرخ لحظه‌ای و سود فروش را بررسی کنید، سپس فروش را روشن کنید.\n\nتمام سفارش‌ها واقعی هستند و با کلید یکتا اجرا می‌شوند؛ تکرار worker باعث پرداخت دوباره نمی‌شود.";
        virtualServicesAdminReply($text, [[['text' => 'بازگشت', 'callback_data' => 'vsa_fg_home']]]); return true;
    }
    if (preg_match('/^vsa_fg_add_(stars|premium)$/', $datain, $m)) {
        virtualServicesAdminSetState('vsa_fg_add_' . $m[1]);
        $sample = $m[1] === 'stars' ? 'پکیج ۵۰ استارزی | 50 | 150000' : 'پریمیوم ۳ ماهه | 3 | 650000';
        virtualServicesAdminReply("عنوان، مقدار و قیمت دستی را در یک پیام بفرستید:\n\n<code>{$sample}</code>\n\nدر صورت فعال بودن قیمت لحظه‌ای، این مبلغ فقط هنگام خاموش بودن قیمت‌گذاری خودکار استفاده می‌شود.", [[['text' => 'انصراف', 'callback_data' => 'vsa_fg_products']]]); return true;
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

