<?php
declare(strict_types=1);

/**
 * اتصال واقعی به fragment.com (حالت «live»).
 *
 * فرگمنت API رسمی ندارد؛ وب‌سایتش با یک API داخلی کار می‌کند (POST /api?hash=… با فیلد method) و همین‌جا همان مراحلی که
 * مرورگر انجام می‌دهد خودکار اجرا می‌شود. جریان و نام متدها/پارامترها با کلاینت متن‌باز و آزموده‌شده‌ی pyfragment
 * (github.com/bohd4nx/pyfragment، MIT) مقایسه و هم‌راستا شده است:
 *   ۱. نشست: کوکی‌های stel_ssid / stel_dt / stel_token / stel_ton_token (از مرورگری که با همان ولت وارد فرگمنت شده؛ پنل ← راه‌اندازی)
 *      یا ورود خودکار با ولت (TON Connect ton_proof ← checkTonProofAuth)
 *   ۲. searchStarsRecipient (quantity خالی) / searchPremiumGiftRecipient (months)
 *   ۳. updateStarsBuyState / updatePremiumState (mode=new) ← initBuyStarsRequest / initGiftPremiumRequest (قیمت، payment_method)
 *   ۴. getBuyStarsLink / getGiftPremiumLink ← پیام‌های تراکنش TON (account + device + transaction=1 + id + show_sender)
 *   ۵. امضا و ارسال تراکنش توسط «سرویس امضای TON» (tools/ton-signer)؛ کلید ولت فقط روی همان سرویس است
 *   ۶. confirm_method (boc تراکنش) ← پیگیری وضعیت صفحه (need_update) ← بررسی نشستن تراکنش روی شبکه
 *
 * همان پروتکل داخلی (account.status، premium.quote، …) را پیاده می‌کند تا بقیه‌ی سیستم (تحویل خودکار، تلاش دوباره، سقف‌ها) بدون تغییر کار کند.
 */
final class FragmentLive
{
    private const PAGES = ['stars' => '/stars/buy', 'premium' => '/premium/gift', 'gifts' => '/gifts'];
    private const STATE = ['stars' => 'updateStarsBuyState', 'premium' => 'updatePremiumState'];
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    private const NANO = 1_000_000_000;
    private const COOKIES = ['stel_ssid', 'stel_dt', 'stel_token', 'stel_ton_token'];
    private const USDT_MAX_GAS_NANO = 600_000_000;   // در پرداخت با USDT فقط کارمزد شبکه به‌صورت TON می‌رود

    private static ?array $info = null;

    /** @throws FragmentError */
    public static function call(string $method, array $p = []): array
    {
        return match ($method) {
            'account.status' => self::accountStatus(),
            'login.start', 'login.confirm' => self::login(),
            'logout' => self::logout(),
            'recipient.search' => self::search($p),
            'premium.quote' => self::quote('premium', $p),
            'stars.quote' => self::quote('stars', $p),
            'premium.buy' => self::buy('premium', $p),
            'stars.buy' => self::buy('stars', $p),
            'order.check' => self::orderCheck($p),
            'gifts.collections' => self::giftsCollections(),
            'gifts.search' => self::giftsSearch($p),
            'gifts.item' => self::giftsItem($p),
            'wallet.connect', 'wallet.disconnect', 'wallet.topup' => throw new FragmentError('unsupported', 'در حالت «اتصال واقعی» ولت را سرویس امضای TON مدیریت می‌کند (کلید ولت فقط روی همان سرویس می‌ماند).'),
            default => throw new FragmentError('unsupported', 'متد ناشناخته: ' . $method),
        };
    }

    /* ---------- تنظیمات و کوکی ---------- */
    private static function cfg(): array
    {
        return Fragment::settings()['live'];
    }

    private static function base(): string
    {
        return rtrim((string) self::cfg()['baseUrl'], '/');
    }

    /** چند کلید را داخل تنظیمات live ادغام می‌کند (کوکی‌ها، hash صفحه‌ها) بدون دست‌زدن به بقیه‌ی تنظیمات */
    private static function patchLive(array $kv): void
    {
        $s = Db::setting('fragment');
        $s = is_array($s) ? $s : [];
        $s['live'] = array_replace(is_array($s['live'] ?? null) ? $s['live'] : [], $kv);
        Db::setSetting('fragment', $s);
    }

    private static function saveCookies(array $jar): void
    {
        self::patchLive(['cookies' => $jar]);
    }

    private static function paymentMethod(): string
    {
        return ($m = (string) (self::cfg()['paymentMethod'] ?? 'ton')) === 'usdt_ton' ? 'usdt_ton' : 'ton';
    }

    /** درخواست HTTP به فرگمنت با نگه‌داری کوکی‌ها (و پراکسی اختیاری) */
    private static function http(string $method, string $url, ?array $form, array $headers, string $accept = 'application/json'): array
    {
        $cfg = self::cfg();
        $jar = (array) ($cfg['cookies'] ?? []);
        $h = ['User-Agent: ' . self::UA, 'Accept-Language: en-US,en;q=0.9'];
        if ($jar) $h[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "{$k}={$v}", array_keys($jar), $jar));
        $opt = ['headers' => array_merge($h, $headers), 'timeout' => 25, 'accept' => $accept] + ($form !== null ? ['form' => $form] : []);
        if ((string) ($cfg['proxy'] ?? '') !== '') $opt['proxy'] = (string) $cfg['proxy'];
        try {
            $r = HttpClient::send($method, $url, $opt);
        } catch (Throwable $e) {
            error_log('fragment network: ' . mb_substr($e->getMessage(), 0, 300));
            throw new FragmentError('network', 'اتصال به Fragment موقتاً برقرار نشد.', true);
        }
        $changed = false;
        foreach ($r['headers'] ?? [] as $line) {
            if (stripos($line, 'Set-Cookie:') !== 0) continue;
            $pair = explode(';', trim(substr($line, 11)), 2)[0];
            [$name, $val] = array_pad(explode('=', $pair, 2), 2, '');
            $name = trim($name); $val = trim($val);
            if ($name === '') continue;
            if ($val === '' || $val === 'deleted') unset($jar[$name]); else $jar[$name] = $val;
            $changed = true;
        }
        if ($changed) self::saveCookies($jar);
        if ($r['status'] >= 500) throw new FragmentError('unavailable', "فرگمنت پاسخ خطا داد (HTTP {$r['status']}).", true);
        if ($r['status'] === 403) throw new FragmentError('blocked', 'فرگمنت دسترسی این سرور را مسدود کرده است (HTTP 403). از آی‌پی یا پراکسی دیگری امتحان کنید (پنل ← راه‌اندازی ← پراکسی) یا کمی صبر کنید.');
        return $r;
    }

    /** صفحه‌ی خرید را می‌خواند: شناسه‌ی hash (در کش ذخیره می‌شود)، payload ورود با ولت و وضعیت ورود */
    private static function page(string $kind): array
    {
        $r = self::http('GET', self::base() . self::PAGES[$kind], null, ['Referer: ' . self::base() . '/'], 'text/html,application/xhtml+xml,*/*;q=0.8');
        if ($r['status'] === 429) throw new FragmentError('rate_limit', 'محدودیت نرخ فرگمنت؛ کمی بعد دوباره تلاش کنید.', true);
        $html = $r['body'];
        if (!preg_match('#/api\?hash=([0-9a-f]+)#', $html, $m)) {
            throw new FragmentError('page_changed', 'ساختار صفحه‌ی فرگمنت قابل‌خواندن نیست (شناسه‌ی API پیدا نشد). ممکن است فرگمنت تغییر کرده یا درخواست مسدود شده باشد.');
        }
        $hashes = (array) (self::cfg()['hashes'] ?? []);
        if (($hashes[$kind] ?? null) !== $m[1]) { $hashes[$kind] = $m[1]; self::patchLive(['hashes' => $hashes]); }
        $wallet = [];
        if (preg_match('#Wallet\.init\((\{.*?\})\)#s', $html, $w)) $wallet = json_decode($w[1], true) ?: [];
        return ['kind' => $kind, 'hash' => $m[1], 'proof' => (string) ($wallet['ton_proof'] ?? ''), 'loggedIn' => !empty($wallet['logged_in']), 'address' => $wallet['address'] ?? false];
    }

    /** hash کش‌شده‌ی صفحه (هر درخواست یک بار صفحه را نمی‌خواند) */
    private static function hashFor(string $kind): string
    {
        $h = (string) (((array) (self::cfg()['hashes'] ?? []))[$kind] ?? '');
        return $h !== '' ? $h : self::page($kind)['hash'];
    }

    /**
     * یک فراخوانی API فرگمنت. HTTP 429 تا ۳ بار با تأخیر تصادفی تکرار می‌شود و hash کهنه («Bad request») یک بار دوباره گرفته می‌شود.
     * $page فقط نوع صفحه (kind) را می‌دهد؛ hash همیشه از کش خوانده می‌شود.
     */
    private static function api(array $page, string $method, array $params = []): array
    {
        $kind = $page['kind'];
        $base = self::base();
        $u = parse_url($base);
        $origin = ($u['scheme'] ?? 'https') . '://' . ($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : '');
        $headers = ['X-Requested-With: XMLHttpRequest', 'Origin: ' . $origin, 'Referer: ' . $base . self::PAGES[$kind],
            'Sec-Fetch-Dest: empty', 'Sec-Fetch-Mode: cors', 'Sec-Fetch-Site: same-origin', 'Priority: u=1, i'];
        $tries = 0;
        $refreshed = false;
        while (true) {
            $r = self::http('POST', $base . '/api?hash=' . self::hashFor($kind), ['method' => $method] + $params, $headers, 'application/json, text/javascript, */*; q=0.01');
            if ($r['status'] === 429) {
                if (++$tries >= 3) throw new FragmentError('rate_limit', 'فرگمنت درخواست‌ها را محدود کرد (HTTP 429)؛ کمی بعد دوباره تلاش کنید.', true);
                usleep((int) ((1 + $tries + mt_rand(0, 500) / 1000) * 1_000_000));
                continue;
            }
            if ($r['status'] !== 200) throw new FragmentError('bad_response', "پاسخ غیرمنتظره از فرگمنت (HTTP {$r['status']}).", true);
            $d = json_decode($r['body'], true);
            if (!is_array($d)) throw new FragmentError('bad_response', 'پاسخ نامعتبر از فرگمنت دریافت شد.', true);
            if (!$refreshed && isset($d['error']) && stripos((string) $d['error'], 'bad request') !== false) {
                $refreshed = true;
                self::page($kind);   // hash کهنه بود؛ از صفحه‌ی تازه گرفته و در کش ذخیره می‌شود
                continue;
            }
            return $d;
        }
    }

    /** خطاهای فرگمنت را به خطای قابل‌فهم تبدیل می‌کند */
    private static function check(array $d): array
    {
        if (!empty($d['need_ton'])) throw new FragmentError('session_expired', 'ولت در Fragment وصل نیست یا نشست منقضی شده است؛ دکمه «ورود خودکار Fragment» را بزنید.');
        if (!empty($d['need_verify'])) throw new FragmentError('need_verify', 'فرگمنت تأیید اضافه (ورود تلگرام یا اتصال ولت) می‌خواهد؛ یک‌بار آن را دستی در مرورگر انجام دهید.');
        if (isset($d['error'])) throw self::mapError(trim(html_entity_decode(strip_tags((string) $d['error']), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        return $d;
    }

    private static function mapError(string $e): FragmentError
    {
        $l = strtolower($e);
        return match (true) {
            str_contains($l, 'no telegram users') || str_contains($l, 'assigned to a user') || str_contains($l, 'username assigned') || str_contains($l, 'not found') => new FragmentError('user_not_found', 'گیرنده پیدا نشد.'),
            str_contains($l, 'already subscribed') || (str_contains($l, 'already') && str_contains($l, 'premium')) => new FragmentError('already_premium', 'این حساب هم‌اکنون پریمیوم دارد.'),
            str_contains($l, 'too many') || str_contains($l, 'flood') => new FragmentError('rate_limit', 'Fragment موقتاً درخواست‌ها را محدود کرده است.', true),
            str_contains($l, 'access denied') => new FragmentError('session_expired', 'نشست Fragment نیازمند تمدید است.'),
            str_contains($l, 'bad request') => new FragmentError('bad_request', 'Fragment درخواست را نپذیرفت.', true),
            default => new FragmentError('fragment_error', 'Fragment نتوانست درخواست را پردازش کند.'),
        };
    }

    /* ---------- سرویس امضای TON ---------- */
    private static function signer(string $method, string $path, ?array $payload = null, int $timeout = 30): array
    {
        $l = self::cfg();
        if ((string) $l['signerUrl'] === '') throw new FragmentError('signer_missing', 'آدرس سرویس امضای TON تنظیم نشده است.');
        $headers = (string) $l['signerToken'] !== '' ? ['Authorization: Bearer ' . $l['signerToken']] : [];
        try {
            $r = HttpClient::send($method, rtrim((string) $l['signerUrl'], '/') . $path, ['headers' => $headers, 'timeout' => $timeout] + ($payload !== null ? ['json' => $payload] : []));
        } catch (Throwable $e) {
            error_log('fragment signer transport: ' . mb_substr($e->getMessage(), 0, 300));
            throw new FragmentError('signer_down', 'سرویس پردازش تراکنش موقتاً در دسترس نیست.', true);
        }
        $d = json_decode($r['body'], true);
        if ($r['status'] >= 400 || !is_array($d)) {
            $code = in_array($r['status'], [401, 403], true) ? 'signer_auth' : 'signer_error';
            $message = $code === 'signer_auth' ? 'ارتباط امن سرویس پردازش نیازمند بازبینی است.' : 'سرویس پردازش تراکنش پاسخ معتبر نداد.';
            throw new FragmentError($code, $message, $r['status'] >= 500 || $r['status'] === 503);
        }
        return $d;
    }

    /* ---------- مدیریت ولت و سرویس امضا از پنل (کلید ولت فقط به سرویس امضا فرستاده می‌شود، در دیتابیس فروشگاه ذخیره نمی‌شود) ---------- */

    /** آیا ارسال اطلاعات محرمانه به سرویس امضا امن است؟ */
    public static function signerTransportSecure(): bool
    {
        $u = parse_url((string) self::cfg()['signerUrl']);
        if (!$u || empty($u['host'])) return false;
        if (($u['scheme'] ?? '') === 'https') return true;
        if (($u['scheme'] ?? '') !== 'http') return false;

        $host = strtolower((string) $u['host']);
        if (in_array($host, ['127.0.0.1', 'localhost', '[::1]', '::1'], true)) return true;

        // The signer service is private inside the per-bot Docker network. It
        // is safe to use plain HTTP there because the endpoint is never
        // published on the host. Keep this exception deliberately narrow.
        $dockerInstance = strtolower(trim((string) getenv('MIRZA_DOCKER_INSTANCE')));
        $path = (string) ($u['path'] ?? '');
        $pathOk = $path === '' || $path === '/';
        return preg_match('/^[a-z][a-z0-9-]{1,30}$/', $dockerInstance) === 1
            && $host === 'signer'
            && (int) ($u['port'] ?? 0) === 8787
            && $pathOk
            && !isset($u['user'], $u['pass'], $u['query'], $u['fragment']);
    }

    /** @throws FragmentError */
    public static function signerConfig(): array
    {
        return self::signer('GET', '/config');
    }

    /** فقط فیلدهای مجاز به سرویس امضا می‌روند؛ پاسخ هیچ‌وقت عبارت بازیابی را برنمی‌گرداند */
    public static function signerConfigSave(array $b): array
    {
        $out = [];
        foreach (['mnemonic', 'endpoint', 'apiKey', 'walletVersion'] as $k) if (isset($b[$k]) && is_string($b[$k]) && trim($b[$k]) !== '') $out[$k] = trim($b[$k]);
        if (array_key_exists('endpoint', $b) && trim((string) $b['endpoint']) === '') $out['endpoint'] = '';
        if (!empty($b['clearApiKey'])) $out['clearApiKey'] = true;
        foreach (['maxTonPerTx', 'maxTonPerDay'] as $k) if (isset($b[$k]) && $b[$k] !== '') $out[$k] = $b[$k];
        if (!$out) throw new FragmentError('bad_request', 'چیزی برای ذخیره ارسال نشده است.');
        if ((isset($out['mnemonic']) || isset($out['apiKey'])) && !self::signerTransportSecure()) {
            throw new FragmentError('insecure_signer', 'برای ارسال کلید ولت یا کلید API، آدرس سرویس امضا باید https، loopback محلی یا شبکه داخلی امن Docker باشد.');
        }
        self::$info = null;
        return self::signer('POST', '/config', $out, 60);
    }

    public static function signerWalletRemove(): array
    {
        self::$info = null;
        return self::signer('DELETE', '/config/wallet');
    }

    /**
     * ورود دستی: کوکی‌های نشست fragment.com را از مرورگر (Cookie header) وارد می‌کند. مرورگر باید با همان ولتی وارد فرگمنت شده باشد
     * که سرویس امضا دارد (همان روشی که pyfragment استفاده می‌کند). فقط نام‌ها و مقدارهای امن پذیرفته می‌شوند و محتوای کوکی هرگز به پنل برنمی‌گردد.
     */
    public static function importCookies(string $raw): int
    {
        $jar = [];
        foreach (preg_split('/;\s*/', trim($raw)) ?: [] as $pair) {
            if ($pair === '') continue;
            [$name, $val] = array_pad(explode('=', $pair, 2), 2, '');
            $name = trim($name); $val = trim($val);
            if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $name) || !preg_match('/^[A-Za-z0-9_\-.%~+\/=]{0,2048}$/', $val)) throw new FragmentError('bad_request', "کوکی «{$name}» قالب نامعتبر دارد.");
            if ($val !== '') $jar[$name] = $val;
        }
        $missing = array_values(array_filter(self::COOKIES, fn($k) => empty($jar[$k])));
        if ($missing) {
            throw new FragmentError('bad_request', 'کوکی‌های لازم پیدا نشد: ' . implode('، ', $missing) . '. در مرورگر با همان ولت وارد fragment.com شوید و هر چهار کوکی (' . implode('، ', self::COOKIES) . ') را کپی کنید.');
        }
        if (count($jar) > 20) throw new FragmentError('bad_request', 'تعداد کوکی‌ها زیاد است.');
        self::saveCookies($jar);
        return count($jar);
    }

    private static function signerInfo(): array
    {
        return self::$info ??= self::signer('GET', '/info');
    }

    /* ---------- حساب و ورود ---------- */
    private static function accountStatus(): array
    {
        $l = self::cfg();
        $out = ['mode' => 'live', 'dryRun' => (bool) $l['dryRun'], 'session' => ['loggedIn' => false, 'phone' => '', 'name' => 'TON wallet', 'since' => null, 'expiresAt' => null],
            'wallet' => ['connected' => false, 'address' => '', 'balance' => 0.0], 'rates' => null, 'errors' => []];
        try {
            $i = self::signerInfo();
            $out['wallet'] = ['connected' => true, 'address' => (string) ($i['address'] ?? ''), 'balance' => round((float) ($i['balance'] ?? 0), 4), 'network' => $i['network'] ?? null, 'version' => $i['version'] ?? null];
        } catch (FragmentError $e) {
            $out['errors']['signer'] = $e->getMessage();
        }
        try {
            $p = self::page('stars');
            $out['session']['loggedIn'] = $p['loggedIn'];
            $out['session']['phone'] = is_string($p['address']) ? $p['address'] : (string) ($out['wallet']['address'] ?? '');
        } catch (FragmentError $e) {
            $out['errors']['fragment'] = $e->getMessage();
        }
        return $out;
    }

    /** ورود با ولت: ton_proof را سرویس امضا می‌سازد و به فرگمنت می‌دهیم */
    private static function login(): array
    {
        $page = self::page('stars');
        if ($page['loggedIn']) return ['session' => ['loggedIn' => true, 'already' => true]];
        if ($page['proof'] === '') throw new FragmentError('page_changed', 'payload ورود با ولت در صفحه‌ی فرگمنت پیدا نشد.');
        $host = (string) parse_url(self::base(), PHP_URL_HOST);
        $acc = self::signer('POST', '/ton-proof', ['payload' => $page['proof'], 'domain' => $host]);
        $j = fn($v) => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $r = self::check(self::api($page, 'checkTonProofAuth', ['account' => $j($acc['account'] ?? []), 'device' => $j($acc['device'] ?? []), 'proof' => $j($acc['proof'] ?? [])]));
        if (empty($r['verified'])) throw new FragmentError('login_failed', 'Fragment امضای ورود با ولت را نپذیرفت. نسخه کیف پول، IP سرور و درستی عبارت بازیابی را بررسی کنید.');
        if (!self::page('stars')['loggedIn']) throw new FragmentError('login_failed', 'ورود انجام شد ولی فرگمنت نشست را نگه نداشت؛ دوباره تلاش کنید.', true);
        return ['session' => ['loggedIn' => true]];
    }

    private static function logout(): array
    {
        try {
            $p = self::page('stars');
            if ($p['loggedIn']) self::api($p, 'tonLogOut');
        } catch (Throwable $e) { /* خروج محلی کافی است */ }
        self::saveCookies([]);
        return ['loggedOut' => true];
    }

    /** نشست آماده: اگر کوکی نشست (stel_ton_token) هست از همان استفاده می‌شود، وگرنه خودکار با ولت وارد می‌شود */
    private static function ensure(string $kind): array
    {
        $jar = (array) (self::cfg()['cookies'] ?? []);
        if (empty($jar['stel_ton_token'])) {
            self::login();
        }
        return ['kind' => $kind, 'hash' => self::hashFor($kind)];
    }

    /** اگر نشست وسط کار منقضی شد یک‌بار خودکار دوباره وارد می‌شود و همان کار را تکرار می‌کند */
    private static function withSession(string $kind, callable $fn)
    {
        try {
            return $fn(self::ensure($kind));
        } catch (FragmentError $e) {
            if ($e->errCode !== 'session_expired') throw $e;
            self::saveCookies([]);
            self::login();
            return $fn(['kind' => $kind, 'hash' => self::hashFor($kind)]);
        }
    }

    /* ---------- گیرنده، قیمت و خرید ---------- */
    private static function amountParams(string $kind, array $p): array
    {
        if ($kind === 'stars') {
            $qty = (int) ($p['qty'] ?? 0);
            if ($qty < 1) throw new FragmentError('invalid_quantity', 'تعداد استارز معتبر نیست.');
            return ['quantity' => $qty];
        }
        $months = (int) ($p['months'] ?? 0);
        if (!in_array($months, [3, 6, 12], true)) throw new FragmentError('invalid_months', 'مدت اشتراک باید ۳، ۶ یا ۱۲ ماه باشد.');
        return ['months' => $months];
    }

    /** جست‌وجوی گیرنده؛ برای استارز مثل مرورگر quantity خالی فرستاده می‌شود */
    private static function find(array $page, string $kind, string $username, array $amount): array
    {
        $params = ['query' => $username] + ($kind === 'stars' ? ['quantity' => ''] : ['months' => $amount['months']]);
        $r = self::check(self::api($page, $kind === 'stars' ? 'searchStarsRecipient' : 'searchPremiumGiftRecipient', $params));
        $f = $r['found'] ?? null;
        if (!is_array($f) || empty($f['recipient'])) throw new FragmentError('user_not_found', 'گیرنده پیدا نشد.');
        return $f;
    }

    /** مثل مرورگر: بعد از جست‌وجوی گیرنده، وضعیت صفحه (update*State با mode=new) صدا زده می‌شود */
    private static function pageState(array $page, string $kind, string $mode = 'new'): array
    {
        return self::api($page, self::STATE[$kind], ['mode' => $mode, 'lv' => 'false', 'dh' => (string) random_int(100_000_000, 2_147_483_647)]);
    }

    private static function clean($v): string
    {
        return trim(html_entity_decode(strip_tags((string) $v), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function search(array $p): array
    {
        $kind = in_array($p['kind'] ?? '', ['premium', 'stars'], true) ? $p['kind'] : 'stars';
        $u = FragmentSim::normalizeUsername($p['query'] ?? $p['username'] ?? '');
        $amount = $kind === 'stars' ? ['quantity' => max(1, (int) ($p['qty'] ?? 50))] : ['months' => in_array((int) ($p['months'] ?? 3), [3, 6, 12], true) ? (int) ($p['months'] ?? 3) : 3];
        $f = self::withSession($kind, fn(array $page) => self::find($page, $kind, $u, $amount));
        return ['found' => ['username' => $u, 'name' => self::clean($f['name'] ?? $u), 'premium' => false]];
    }

    private static function quote(string $kind, array $p): array
    {
        $u = FragmentSim::normalizeUsername($p['username'] ?? '');
        $amount = self::amountParams($kind, $p);
        $pm = self::paymentMethod();
        return self::withSession($kind, function (array $page) use ($kind, $u, $amount, $pm) {
            $f = self::find($page, $kind, $u, $amount);
            self::pageState($page, $kind);
            $init = self::check(self::api($page, $kind === 'stars' ? 'initBuyStarsRequest' : 'initGiftPremiumRequest', ['recipient' => $f['recipient']] + $amount + ['payment_method' => $pm]));
            $reqId = (string) ($init['req_id'] ?? '');
            // فرگمنت مبلغ‌های بزرگ را با جداکننده‌ی هزارگان می‌فرستد (مثل «1,000.5»)
            $amt = (float) str_replace(',', '', (string) ($init['amount'] ?? '0'));
            if ($reqId === '' || $amt <= 0) throw new FragmentError('bad_response', 'پاسخ قیمت‌گیری فرگمنت ناقص است.', true);
            $usdt = $pm === 'usdt_ton';
            $ton = $usdt ? 0.0 : $amt;
            Db::run('INSERT OR REPLACE INTO fragment_reqs (id, kind, username, months, qty, usd, ton, fee_ton, total_nano, expires_at, used, result, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,0,?,?)',
                [$reqId, $kind, $u, $amount['months'] ?? null, $amount['quantity'] ?? null, $usdt ? $amt : 0, $ton, 0, (int) round($ton * self::NANO), time() + 600, json_encode(['pm' => $pm]), now_utc()]);
            return ['reqId' => $reqId, 'recipient' => ['username' => $u, 'name' => self::clean($f['name'] ?? $u), 'premium' => false], 'usd' => $usdt ? $amt : null, 'ton' => $ton, 'feeTon' => 0.0, 'totalTon' => $ton, 'expiresIn' => 600, 'paymentMethod' => $pm];
        });
    }

    /* ---------- گیفت‌های NFT فرگمنت (فقط خواندن؛ عمومی و بدون نیاز به ورود) ---------- */
    /** HTML صفحه‌ی /gifts (فهرست مجموعه‌ها) و ذخیره‌ی hash API آن */
    private static function giftsCollections(): array
    {
        $r = self::http('GET', self::base() . self::PAGES['gifts'], null, ['Referer: ' . self::base() . '/'], 'text/html,application/xhtml+xml,*/*;q=0.8');
        if ($r['status'] === 429) throw new FragmentError('rate_limit', 'محدودیت نرخ فرگمنت؛ کمی بعد دوباره تلاش کنید.', true);
        if ($r['status'] !== 200) throw new FragmentError('bad_response', "پاسخ غیرمنتظره از فرگمنت (HTTP {$r['status']}).", true);
        if (preg_match('#/api\?hash=([0-9a-f]+)#', $r['body'], $m)) {
            $hashes = (array) (self::cfg()['hashes'] ?? []);
            if (($hashes['gifts'] ?? null) !== $m[1]) { $hashes['gifts'] = $m[1]; self::patchLive(['hashes' => $hashes]); }
        }
        return ['html' => (string) $r['body']];
    }

    /** جست‌وجوی گیفت‌ها (searchAuctions با type=gifts)؛ HTML کارت‌ها و شناسه‌ی صفحه‌ی بعد را برمی‌گرداند */
    private static function giftsSearch(array $p): array
    {
        $params = ['type' => 'gifts', 'query' => (string) ($p['query'] ?? '')];
        foreach (['collection', 'sort', 'filter', 'view'] as $k) if (isset($p[$k]) && $p[$k] !== '') $params[$k] = (string) $p[$k];
        if (isset($p['offset']) && $p['offset'] !== '') $params['offset_id'] = (string) (int) $p['offset'];
        $d = self::api(['kind' => 'gifts'], 'searchAuctions', $params);
        if (isset($d['error'])) throw self::mapError(self::clean($d['error']));
        $html = $d['html'] ?? (((string) ($d['body'] ?? '')) . ((string) ($d['foot'] ?? '')));
        return ['html' => (string) $html, 'next' => $d['next_offset_id'] ?? null];
    }

    /** صفحه‌ی یک گیفت (/gift/<slug>)؛ HTML کامل یا JSON با کلید h */
    private static function giftsItem(array $p): array
    {
        $slug = (string) ($p['slug'] ?? '');
        if (!preg_match('#^gift/[A-Za-z0-9_\-]{1,100}$#', $slug)) throw new FragmentError('invalid_slug', 'شناسه‌ی گیفت معتبر نیست.');
        $r = self::http('GET', self::base() . '/' . $slug, null, ['Referer: ' . self::base() . '/gifts'], 'text/html,application/xhtml+xml,*/*;q=0.8');
        if ($r['status'] === 429) throw new FragmentError('rate_limit', 'محدودیت نرخ فرگمنت؛ کمی بعد دوباره تلاش کنید.', true);
        if (in_array($r['status'], [301, 302, 303, 307, 308, 404], true)) throw new FragmentError('not_found', 'این گیفت پیدا نشد (ممکن است منتقل یا حذف شده باشد).');
        if ($r['status'] !== 200) throw new FragmentError('bad_response', "پاسخ غیرمنتظره از فرگمنت (HTTP {$r['status']}).", true);
        $j = json_decode((string) $r['body'], true);
        return ['html' => is_array($j) && isset($j['h']) ? (string) $j['h'] : (string) $r['body']];
    }

    private static function buy(string $kind, array $p): array
    {
        $reqId = (string) ($p['reqId'] ?? '');
        $row = Db::one('SELECT * FROM fragment_reqs WHERE id = ? AND kind = ?', [$reqId, $kind]);
        if (!$row) throw new FragmentError('bad_request', 'درخواست خرید پیدا نشد؛ دوباره قیمت بگیرید.');
        if ((int) $row['used']) throw new FragmentError('bad_request', 'این درخواست قبلاً استفاده شده است.');
        $meta = json_decode((string) ($row['result'] ?? ''), true);
        $pm = is_array($meta) && ($meta['pm'] ?? '') === 'usdt_ton' ? 'usdt_ton' : 'ton';
        $info = self::signerInfo();
        $page = self::ensure($kind);
        $j = fn($v) => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $link = self::check(self::api($page, $kind === 'stars' ? 'getBuyStarsLink' : 'getGiftPremiumLink',
            ['account' => $j($info['account'] ?? []), 'device' => $j($info['device'] ?? []), 'transaction' => 1, 'id' => $reqId, 'show_sender' => !empty(self::cfg()['showSender']) ? 1 : 0]));

        $tr = is_array($link['transaction'] ?? null) ? $link['transaction'] : [];
        $msgs = array_values(array_filter((array) ($tr['messages'] ?? []), 'is_array'));
        if (!$msgs || count($msgs) > 4) throw new FragmentError('bad_response', 'فرگمنت پیام تراکنش معتبری نداد.', true);
        $total = 0;
        foreach ($msgs as $m) {
            if (empty($m['address']) || !isset($m['amount']) || !preg_match('/^\d+$/', (string) $m['amount'])) throw new FragmentError('bad_response', 'پیام تراکنش فرگمنت قالب نامعتبر دارد.', true);
            $total += (int) $m['amount'];
        }
        // حفاظت: مبلغ واقعی تراکنش باید با قیمتی که فرگمنت اعلام کرده بخواند
        if ($pm === 'ton') {
            $expected = (int) $row['total_nano'];
            if ($total > (int) ($expected * 1.05) + 100_000_000) {
                throw new FragmentError('amount_mismatch', 'مبلغ تراکنش (' . round($total / self::NANO, 4) . ' TON) با قیمت اعلام‌شده (' . round($expected / self::NANO, 4) . ' TON) نمی‌خواند؛ خرید انجام نشد.');
            }
        } else {
            // پرداخت با USDT: مبلغ از کیف jetton کم می‌شود و فقط کارمزد شبکه (چند دهم TON) در پیام است
            if ($total > self::USDT_MAX_GAS_NANO) throw new FragmentError('amount_mismatch', 'کارمزد TON تراکنش USDT (' . round($total / self::NANO, 4) . ' TON) غیرعادی است؛ خرید انجام نشد.');
            $bal = (float) (self::signer('GET', '/usdt')['balance'] ?? 0);
            if ($bal + 1e-9 < (float) $row['usd']) throw new FragmentError('insufficient_usdt', "موجودی USDT ولت ({$bal}) برای این خرید ({$row['usd']} USDT) کافی نیست.");
        }
        $tonTotal = round($total / self::NANO, 4);
        $live = self::cfg();
        if (!empty($live['dryRun'])) {
            throw new FragmentError('dry_run', "حالت آزمایشی: تراکنش {$tonTotal} TON به " . ($msgs[0]['address'] ?? '') . ' ساخته شد ولی ارسال نشد. برای خرید واقعی «حالت آزمایشی» را خاموش کنید.');
        }

        $validUntil = (int) ($tr['validUntil'] ?? (time() + 300));
        $sent = self::signer('POST', '/send', [
            'messages' => array_map(fn($m) => ['address' => (string) $m['address'], 'amount' => (string) $m['amount'], 'payload' => (string) ($m['payload'] ?? '')], $msgs),
            'validUntil' => $validUntil, 'idem' => (string) ($p['idem'] ?? ''),
        ], 120);
        if (empty($sent['hash'])) throw new FragmentError('signer_error', 'سرویس امضای TON هش تراکنش را برنگرداند؛ وضعیت را در ولت بررسی کنید.', true);
        Db::run('UPDATE fragment_reqs SET used = 1, result = ? WHERE id = ?', [json_encode(['pm' => $pm, 'txHash' => $sent['hash']]), $reqId]);

        // تأیید به فرگمنت همان کاری است که مرورگر بعد از ارسال می‌کند؛ اگر خطا بدهد پول رفته و نباید خرید را «ناموفق» حساب کنیم
        if (!empty($link['confirm_method'])) {
            try {
                self::api($page, (string) $link['confirm_method'], ['account' => $j($info['account'] ?? []), 'device' => $j($info['device'] ?? []), 'boc' => (string) ($sent['boc'] ?? '')] + (array) ($link['confirm_params'] ?? []));
                self::waitFragmentState($page, $kind);
            } catch (Throwable $e) {
                error_log('fragment confirm_method failed');
            }
        }
        return ['txHash' => (string) $sent['hash'], 'sent' => true, 'confirmed' => false, 'totalTon' => $tonTotal, 'duplicate' => !empty($sent['duplicate']), 'paymentMethod' => $pm];
    }

    /** مثل مرورگر وضعیت صفحه را پیگیری می‌کند تا فرگمنت پرداخت را بپذیرد (need_update=false)؛ حداکثر چند ثانیه، خطا نادیده گرفته می‌شود */
    private static function waitFragmentState(array $page, string $kind): void
    {
        $mode = 'new';
        $deadline = time() + max(2, min(20, (int) ceil(((int) (self::cfg()['pollMs'] ?? 5000)) * 3 / 1000)));
        $dh = (string) random_int(100_000_000, 2_147_483_647);
        while (time() < $deadline) {
            $r = self::api($page, self::STATE[$kind], ['mode' => $mode, 'lv' => 'false', 'dh' => $dh]);
            $mode = (string) ($r['mode'] ?? $mode);
            if (array_key_exists('need_update', $r) && !$r['need_update']) return;
            usleep(2_000_000);
        }
    }

    /** آیا تراکنش روی شبکه نشسته است؟ (سرویس امضا با seqno ولت بررسی می‌کند) */
    private static function orderCheck(array $p): array
    {
        $st = self::signer('GET', '/status?hash=' . rawurlencode((string) ($p['txHash'] ?? '')));
        if (!empty($st['included'])) return ['confirmed' => true];
        if (!empty($st['expired'])) return ['confirmed' => false, 'failed' => true, 'message' => 'تراکنش تا پایان مهلت روی شبکه نشست نکرد؛ وجهی کسر نشده و می‌توان دوباره خرید.'];
        return ['confirmed' => false];
    }
}
