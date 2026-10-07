<?php
declare(strict_types=1);

/**
 * کیت مستقل «فروش خودکار فرگمنت» (استارز و پریمیوم) — بدون وابستگی به فروشگاه.
 *
 * FragmentLive.php همان کدی است که در فروشگاه با فرگمنت واقعی تست شده (دست‌نخورده). این فایل فقط چند کلاس کوچک
 * (Db، Fragment، FragmentError، HttpClient) را که FragmentLive لازم دارد با ذخیره‌ی ساده‌ی فایلی/SQLite فراهم می‌کند
 * و یک لایه‌ی ساده‌ی FragmentKit برای استفاده در پروژه‌ی خودتان می‌دهد.
 *
 *   require 'php/FragmentKit.php';
 *   FragmentKit::boot(require 'php/config.php');
 *   $q = FragmentKit::quote('stars', 'some_user', 50);
 *   $r = FragmentKit::buy('stars', 'some_user', 50, 'order-1234');   // idem: شناسه‌ی یکتای سفارش شما
 *
 * نیازمندی‌ها: PHP 8.0+ با cURL و pdo_sqlite، و سرویس امضای TON (پوشه‌ی signer، Node.js 18+).
 */

if (!defined('FRAGMENT_KIT')) define('FRAGMENT_KIT', 1);

/** خطای سرویس فرگمنت؛ retryable یعنی تلاش دوباره‌ی منطقی است (قطعی شبکه، محدودیت نرخ) */
final class FragmentError extends Exception
{
    public string $errCode;
    public bool $retryable;

    public function __construct(string $code, string $message, bool $retryable = false)
    {
        parent::__construct($message);
        $this->errCode = $code;
        $this->retryable = $retryable;
    }
}

function now_utc(string $modify = ''): string
{
    $d = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($modify !== '') $d = $d->modify($modify);
    return $d->format('Y-m-d H:i:s');
}

final class FragmentSim
{
    public static function normalizeUsername($v): string
    {
        $u = strtolower(ltrim(trim((string) $v), '@'));
        if (!preg_match('/^[a-z][a-z0-9_]{4,31}$/', $u)) throw new FragmentError('invalid_username', 'یوزرنیم تلگرام معتبر نیست (۵ تا ۳۲ نویسه‌ی انگلیسی، عدد یا _ و شروع با حرف).');
        return $u;
    }
}

/** ذخیره‌ی ساده: تنظیمات/وضعیت در فایل JSON (نه در دیتابیس) و درخواست‌های خرید در SQLite */
final class Db
{
    public static string $dir = '';
    private static ?PDO $pdo = null;

    public static function init(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException("پوشه‌ی داده ساخته نشد: {$dir}");
        self::$dir = rtrim($dir, '/\\');
        self::$pdo = new PDO('sqlite:' . self::$dir . '/kit.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        self::$pdo->exec('PRAGMA journal_mode = WAL');
        self::$pdo->exec('PRAGMA busy_timeout = 10000');
        self::$pdo->exec('CREATE TABLE IF NOT EXISTS fragment_reqs (
            id TEXT PRIMARY KEY, kind TEXT NOT NULL, username TEXT NOT NULL, months INTEGER, qty INTEGER, usd REAL NOT NULL, ton REAL NOT NULL,
            fee_ton REAL NOT NULL, total_nano INTEGER NOT NULL, expires_at INTEGER NOT NULL, used INTEGER NOT NULL DEFAULT 0, idem TEXT, result TEXT, created_at TEXT NOT NULL)');
        self::$pdo->exec('CREATE TABLE IF NOT EXISTS kit_tx (
            idem TEXT PRIMARY KEY, kind TEXT NOT NULL, username TEXT NOT NULL, amount INTEGER NOT NULL, req_id TEXT, tx_hash TEXT, total_ton REAL,
            status TEXT NOT NULL, error TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    }

    public static function setting(string $key)
    {
        $f = self::$dir . '/' . preg_replace('/[^a-z0-9_]/i', '', $key) . '.json';
        return is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    }

    public static function setSetting(string $key, $value): void
    {
        $f = self::$dir . '/' . preg_replace('/[^a-z0-9_]/i', '', $key) . '.json';
        file_put_contents($f, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($f, 0600);   // کوکی نشست فرگمنت داخل همین فایل است
    }

    public static function run(string $sql, array $p = []): int
    {
        $st = self::$pdo->prepare($sql);
        $st->execute($p);
        return $st->rowCount();
    }

    public static function one(string $sql, array $p = []): ?array
    {
        $st = self::$pdo->prepare($sql);
        $st->execute($p);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }

    public static function value(string $sql, array $p = [])
    {
        $st = self::$pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchColumn();
    }
}

/** تنظیمات = config.php (ثابت) + وضعیت ذخیره‌شده (کوکی نشست و hash صفحه‌ها) */
final class Fragment
{
    public static array $config = [];
    private const STATIC_KEYS = ['baseUrl', 'signerUrl', 'signerToken', 'dryRun', 'pollMs', 'proxy', 'paymentMethod', 'showSender'];

    public static function settings(): array
    {
        $saved = Db::setting('fragment');
        $live = is_array($saved['live'] ?? null) ? $saved['live'] : [];
        $defaults = ['baseUrl' => 'https://fragment.com', 'signerUrl' => '', 'signerToken' => '', 'dryRun' => true, 'pollMs' => 5000, 'cookies' => [], 'proxy' => '', 'paymentMethod' => 'ton', 'showSender' => false, 'hashes' => []];
        $live = array_replace($defaults, $live, array_intersect_key(self::$config, array_flip(self::STATIC_KEYS)));
        return ['mode' => 'live', 'enabled' => true, 'live' => $live];
    }

    public static function call(string $method, array $params = []): array
    {
        return FragmentLive::call($method, $params);
    }

    /** مدیریت ولت و تنظیمات سرویس امضا (پنل ادمین ربات از این‌ها استفاده می‌کند) */
    public static function signerConfig(): array
    {
        return FragmentLive::signerConfig();
    }

    public static function signerConfigSave(array $b): array
    {
        return FragmentLive::signerConfigSave($b);
    }

    public static function signerWalletRemove(): array
    {
        return FragmentLive::signerWalletRemove();
    }
}

require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/FragmentLive.php';

/** لایه‌ی ساده برای پروژه‌ی شما: قیمت‌گیری، خرید با کلید یکتا (هرگز دوبار پرداخت نمی‌شود) و ابزارهای راه‌اندازی */
final class FragmentKit
{
    /**
     * @param array{dataDir:string,signerUrl:string,signerToken?:string,baseUrl?:string,dryRun?:bool,pollMs?:int,proxy?:string,paymentMethod?:string,dailyLimitTon?:float} $config
     */
    public static function boot(array $config): void
    {
        if (empty($config['dataDir'])) throw new InvalidArgumentException('dataDir (پوشه‌ی ذخیره‌ی داده، خارج از پوشه‌ی عمومی وب) لازم است.');
        Db::init((string) $config['dataDir']);
        Fragment::$config = $config;
    }

    /** وضعیت سرویس امضا، ولت و صفحه‌ی فرگمنت (برای بررسی راه‌اندازی) */
    public static function status(): array
    {
        return Fragment::call('account.status');
    }

    /** ورود به فرگمنت با ولت (اگر نشست معتبر باشد دوباره وارد نمی‌شود) */
    public static function login(): array
    {
        return Fragment::call('login.start');
    }

    /** ورود دستی با کوکی‌های مرورگر؛ تعداد کوکی‌های ذخیره‌شده را برمی‌گرداند */
    public static function importCookies(string $cookieHeader): int
    {
        return FragmentLive::importCookies($cookieHeader);
    }

    /** @return array{reqId:string,usd:?float,ton:float,feeTon:float,totalTon:float,expiresIn:int,paymentMethod?:string} */
    public static function quote(string $kind, string $username, int $amount): array
    {
        self::check($kind, $amount);
        return Fragment::call($kind . '.quote', ['username' => FragmentSim::normalizeUsername($username)] + ($kind === 'premium' ? ['months' => $amount] : ['qty' => $amount]));
    }

    /**
     * خرید کامل: قیمت‌گیری ← سقف روزانه ← ارسال تراکنش ← تأیید روی شبکه.
     * $idem شناسه‌ی یکتای سفارش شما (حداکثر ۶۴ نویسه). با همان $idem تکرار کردن هیچ‌وقت دوباره پول نمی‌فرستد:
     * اگر قبلاً کامل شده همان نتیجه، و اگر پول رفته ولی تأیید نشده فقط تأیید بررسی می‌شود.
     *
     * @return array{status:string,txHash:string,totalTon:float,kind:string,username:string,amount:int,duplicate:bool}
     * @throws FragmentError  خطای دائمی یا retryable؛ در حالت dryRun کد dry_run (هیچ پولی نرفته)
     */
    public static function buy(string $kind, string $username, int $amount, string $idem): array
    {
        self::check($kind, $amount);
        if (!preg_match('/^[A-Za-z0-9._:\-]{1,64}$/', $idem)) throw new InvalidArgumentException('idem باید ۱ تا ۶۴ نویسه از حرف انگلیسی، عدد و . _ : - باشد.');
        $u = FragmentSim::normalizeUsername($username);
        $now = now_utc();
        $row = Db::one('SELECT * FROM kit_tx WHERE idem = ?', [$idem]);
        if ($row) {
            if ($row['kind'] !== $kind || $row['username'] !== $u || (int) $row['amount'] !== $amount) throw new InvalidArgumentException('این idem قبلاً برای سفارش دیگری استفاده شده است.');
            if ($row['status'] === 'completed') return self::result($row, true);
        } else {
            Db::run('INSERT INTO kit_tx (idem, kind, username, amount, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?)', [$idem, $kind, $u, $amount, 'pending', $now, $now]);
            $row = Db::one('SELECT * FROM kit_tx WHERE idem = ?', [$idem]);
        }
        try {
            if (!empty($row['tx_hash'])) {   // پول قبلاً فرستاده شده: فقط وضعیت را بررسی می‌کنیم
                self::awaitConfirm((string) $row['req_id'], (string) $row['tx_hash'], $kind);
            } else {
                $q = Fragment::call($kind . '.quote', ['username' => $u] + ($kind === 'premium' ? ['months' => $amount] : ['qty' => $amount]));
                Db::run('UPDATE kit_tx SET req_id = ?, total_ton = ?, updated_at = ? WHERE idem = ?', [$q['reqId'] ?? null, $q['totalTon'] ?? null, now_utc(), $idem]);
                $limit = (float) (Fragment::$config['dailyLimitTon'] ?? 0);
                if ($limit > 0) {
                    $since = gmdate('Y-m-d 00:00:00');
                    $spent = (float) Db::value("SELECT COALESCE(SUM(total_ton), 0) FROM kit_tx WHERE idem != ? AND tx_hash IS NOT NULL AND created_at >= ?", [$idem, $since]);
                    if ($spent + (float) ($q['totalTon'] ?? 0) > $limit) throw new FragmentError('daily_limit', "سقف خرج روزانه ({$limit} TON) پر شده است.");
                }
                $b = Fragment::call($kind . '.buy', ['reqId' => (string) ($q['reqId'] ?? ''), 'idem' => $idem]);
                Db::run('UPDATE kit_tx SET tx_hash = ?, total_ton = COALESCE(?, total_ton), updated_at = ? WHERE idem = ?', [$b['txHash'] ?? null, $b['totalTon'] ?? null, now_utc(), $idem]);
                if (empty($b['confirmed'])) self::awaitConfirm((string) ($q['reqId'] ?? ''), (string) ($b['txHash'] ?? ''), $kind);
            }
        } catch (FragmentError $e) {
            Db::run('UPDATE kit_tx SET status = ?, error = ?, updated_at = ? WHERE idem = ?', [$e->errCode === 'confirm_pending' ? 'pending' : 'failed', mb_substr($e->getMessage(), 0, 500), now_utc(), $idem]);
            throw $e;
        }
        Db::run("UPDATE kit_tx SET status = 'completed', error = NULL, updated_at = ? WHERE idem = ?", [now_utc(), $idem]);
        return self::result(Db::one('SELECT * FROM kit_tx WHERE idem = ?', [$idem]), false);
    }

    private static function check(string $kind, int $amount): void
    {
        if (!in_array($kind, ['stars', 'premium'], true)) throw new InvalidArgumentException('kind باید stars یا premium باشد.');
        if ($amount < 1) throw new InvalidArgumentException('مقدار نامعتبر است (استارز: تعداد؛ پریمیوم: ۳، ۶ یا ۱۲ ماه).');
    }

    private static function result(array $r, bool $dup): array
    {
        return ['status' => 'done', 'txHash' => (string) $r['tx_hash'], 'totalTon' => (float) $r['total_ton'], 'kind' => $r['kind'], 'username' => $r['username'], 'amount' => (int) $r['amount'], 'duplicate' => $dup];
    }

    private static function awaitConfirm(string $reqId, string $hash, string $kind): void
    {
        $polls = 8;
        $sleep = max(100, (int) (Fragment::settings()['live']['pollMs'] ?? 5000));
        for ($i = 0; $i < $polls; $i++) {
            $c = Fragment::call('order.check', ['reqId' => $reqId, 'txHash' => $hash, 'kind' => $kind]);
            if (!empty($c['confirmed'])) return;
            if (!empty($c['failed'])) throw new FragmentError('tx_failed', (string) ($c['message'] ?? 'تراکنش روی شبکه انجام نشد؛ وجهی کسر نشده است.'), true);
            if ($i < $polls - 1) usleep($sleep * 1000);
        }
        throw new FragmentError('confirm_pending', 'تراکنش فرستاده شد ولی هنوز روی شبکه تأیید نشده؛ بعداً همین فراخوانی را با همان idem تکرار کنید (پولی دوباره پرداخت نمی‌شود).', true);
    }
}
