<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/telegram_products.php';
require_once dirname(__DIR__) . '/telegram_fragment.php';
require_once dirname(__DIR__) . '/telegram_products_admin.php';
require_once dirname(__DIR__) . '/fragment-kit/php/FragmentKit.php';

function expectTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$update = ['message' => ['from' => ['id' => 1, 'is_premium' => false]]];
expectTrue(telegramFragmentUserIcon('5280962371207077415', '🛍') === '', 'Non-premium user must not receive a custom emoji tag.');
$adminKeyboard = json_decode(virtualServicesAdminKeyboard([[['text' => 'مدیریت', 'callback_data' => 'vsa_home', 'style' => 'primary', 'icon_custom_emoji_id' => '5280962371207077415']]]), true);
expectTrue(!isset($adminKeyboard['inline_keyboard'][0][0]['style'], $adminKeyboard['inline_keyboard'][0][0]['icon_custom_emoji_id']), 'Admin keyboard styling was not removed.');
$styledAdminButton = $adminKeyboard['inline_keyboard'][0][0];
expectTrue(!isset($styledAdminButton['style']) && !isset($styledAdminButton['icon_custom_emoji_id']) && $styledAdminButton['callback_data'] === 'vsa_home', 'Admin button should remain functional and plain.');
$custom = '<tg-emoji emoji-id="5280962371207077415">X</tg-emoji> عنوان';
expectTrue(telegramProductsSafeCustomText($custom) === ' عنوان', 'Custom emoji must be hidden for non-premium users.');
expectTrue(!isset(telegramProductsStyledButton('عنوان', 'test', 'primary', '5280962371207077415')['icon_custom_emoji_id']), 'Button custom emoji leaked to non-premium user.');

$update['message']['from']['is_premium'] = true;
expectTrue(str_contains(telegramFragmentUserIcon('5280962371207077415', '🛍'), 'tg-emoji'), 'Premium user purchase icon is missing.');
expectTrue(str_contains(telegramProductsSafeCustomText($custom), 'tg-emoji'), 'Premium custom emoji was removed for premium user.');
expectTrue(isset(telegramProductsStyledButton('عنوان', 'test', 'primary', '5280962371207077415')['icon_custom_emoji_id']), 'Premium button custom emoji is missing.');

$internal = new RuntimeException('SQLSTATE connection failed at http://db:3306 secret=abc');
$card = telegramProductsFailureCard(telegramProductsPublicFailureReason($internal), 42, true);
expectTrue(!str_contains($card, 'SQLSTATE') && !str_contains($card, 'db:3306') && !str_contains($card, 'secret'), 'Generic purchase card leaked an internal error.');

$fragmentCard = telegramFragmentFailureCard(telegramFragmentSafeReason($internal), 51, false, false);
expectTrue(!str_contains($fragmentCard, 'SQLSTATE') && !str_contains($fragmentCard, 'db:3306'), 'Fragment purchase card leaked an internal error.');
$stored = telegramFragmentStoredError(new FragmentError('signer_down', 'Bearer top-secret http://signer:8787'));
expectTrue($stored === 'signer_down|سرویس پردازش تراکنش موقتاً در دسترس نیست.', 'Stored Fragment error is not sanitized.');

$price = telegramFragmentPriceFromQuote(1.25, 300000, 10, 5000, 1000);
expectTrue($price['base'] === 375000 && $price['final'] === 418000 && $price['profit'] === 43000, 'Live price calculation is incorrect.');
expectTrue(telegramFragmentQuoteIsValid(['quote_age_seconds' => 0], 10), 'A new Fragment quote must be valid.');
expectTrue(telegramFragmentQuoteIsValid(['quote_age_seconds' => 600], 10), 'A Fragment quote must remain valid for the full ten minutes.');
expectTrue(!telegramFragmentQuoteIsValid(['quote_age_seconds' => 601], 10), 'An expired Fragment quote was accepted.');
expectTrue(telegramFragmentQuoteIsValid(['quote_age_seconds' => -2], 10), 'A database clock adjustment invalidated a new quote.');
expectTrue(telegramFragmentExtractNobitexRate(['status' => 'ok', 'stats' => ['gram-rls' => ['isClosed' => false, 'bestSell' => '3200000']]], 'rls') === 320000.0, 'Nobitex GRAM/RLS rate conversion is incorrect.');
expectTrue(telegramFragmentExtractNobitexRate(['status' => 'ok', 'asks' => [['3210000', '2']]], 'irt') === 321000.0, 'Nobitex orderbook rial-to-toman conversion is incorrect.');
$orderbookPrice = telegramFragmentPriceFromQuote(1.25, telegramFragmentExtractNobitexRate(['status' => 'ok', 'asks' => [['3210000', '2']]], 'irt'), 10, 5000, 1000);
expectTrue($orderbookPrice['base'] === 401250 && $orderbookPrice['final'] === 447000, 'Orderbook rate did not reach the final customer price correctly.');
expectTrue(telegramFragmentExtractNobitexRate(['status' => 'INVALID_CURRENCY', 'stats' => ['gram-rls' => ['bestSell' => '3200000']]], 'rls') === 0.0, 'Nobitex error response was accepted.');
expectTrue(telegramFragmentExtractNobitexRate(['stats' => ['gram-rls' => ['bestSell' => '3200000']]], 'rls') === 0.0, 'Nobitex response without a status was accepted.');
expectTrue(telegramFragmentExtractNobitexRate(['status' => 'ok', 'stats' => ['gram-rls' => ['isClosed' => true, 'bestSell' => '3200000']]], 'rls') === 0.0, 'Closed Nobitex market was accepted.');
expectTrue(telegramFragmentExtractNobitexRate(['status' => 'ok', 'asks' => [], 'lastTradePrice' => '3210000'], 'irt') === 0.0, 'Stale last trade was accepted without a sell offer.');
try {
    telegramFragmentPriceFromQuote(0, 300000, 10, 0, 1000);
    throw new RuntimeException('Invalid Fragment quote was accepted.');
} catch (RuntimeException $e) {
    expectTrue(str_starts_with($e->getMessage(), 'price_unavailable|'), 'Invalid quote returned an unsafe error.');
}

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mirza-fragment-test-' . bin2hex(random_bytes(4));
FragmentKit::boot([
    'dataDir' => $dir,
    'signerUrl' => 'http://signer:8787',
    'signerToken' => str_repeat('a', 32),
    'baseUrl' => 'https://fragment.com',
]);
putenv('MIRZA_DOCKER_INSTANCE=test-bot');
expectTrue(FragmentLive::signerTransportSecure(), 'Private Docker signer URL must be accepted inside a bot container.');
putenv('MIRZA_DOCKER_INSTANCE');
expectTrue(!FragmentLive::signerTransportSecure(), 'Docker signer URL must be rejected outside a bot container.');

Fragment::$config['signerUrl'] = 'http://signer:9999';
putenv('MIRZA_DOCKER_INSTANCE=test-bot');
expectTrue(!FragmentLive::signerTransportSecure(), 'Unexpected Docker signer port was accepted.');
Fragment::$config['signerUrl'] = 'https://signer.example.test';
expectTrue(FragmentLive::signerTransportSecure(), 'HTTPS signer URL was rejected.');
putenv('MIRZA_DOCKER_INSTANCE');

try {
    HttpClient::send('GET', 'file:///etc/passwd');
    throw new RuntimeException('Non-HTTP URL was accepted.');
} catch (InvalidArgumentException $e) {
    expectTrue(true, 'Expected URL validation exception.');
}

$installer = file_get_contents(dirname(__DIR__) . '/install.sh');
$signerSource = file_get_contents(dirname(__DIR__) . '/services/fragment-signer/server.js');
$adminSource = file_get_contents(dirname(__DIR__) . '/admin.php');
$fragmentSource = file_get_contents(dirname(__DIR__) . '/telegram_fragment.php');
$fragmentLiveSource = file_get_contents(dirname(__DIR__) . '/fragment-kit/php/FragmentLive.php');
$rateWorkerSource = file_get_contents(dirname(__DIR__) . '/cronbot/fragment_orders.php');
$virtualAdminSource = file_get_contents(dirname(__DIR__) . '/telegram_products_admin.php');
expectTrue(str_contains($fragmentSource, 'https://apiv2.nobitex.ir/market/stats?srcCurrency=gram&dstCurrency=rls'), 'Nobitex stats must use the current API host.');
expectTrue(str_contains($fragmentSource, 'https://apiv2.nobitex.ir/v3/orderbook/GRAMIRT'), 'Nobitex orderbook must use the current API host.');
expectTrue(!str_contains($fragmentSource, 'https://api.nobitex.ir/'), 'Legacy Nobitex API host is still used for Fragment pricing.');
expectTrue(str_contains($rateWorkerSource, 'telegramFragmentRefreshRateIfDue()'), 'The scheduled worker does not refresh the GRAM rate.');
expectTrue(str_contains($fragmentSource, '$now - 60'), 'The scheduled GRAM rate refresh is not limited to once a minute.');
expectTrue(substr_count($installer, 'MIRZA_DOCKER_INSTANCE: \${BOT_SLUG}') >= 2, 'Docker app/worker instance identity is missing.');
expectTrue(str_contains($installer, 'MIRZA_FRAGMENT_SIGNER_URL: http://signer:8787'), 'Docker signer discovery is missing.');
expectTrue(str_contains($installer, 'fragment-egress:'), 'Isolated signer egress network is missing.');
expectTrue(substr_count($installer, 'USER node') >= 2, 'Signer container is not configured as an unprivileged user.');
expectTrue(!str_contains($installer, '${YOUR_BOT_TOKEN:0:10}'), 'Installer exposes part of the Telegram bot token.');
expectTrue(str_contains($signerSource, 'crypto.timingSafeEqual'), 'Signer token comparison is not timing safe.');
expectTrue(!str_contains($signerSource, '${token}\n('), 'Signer logs the bearer token.');
expectTrue(str_contains($signerSource, "{ mode: 0o600 }"), 'Signer state is not persisted with restrictive permissions.');
expectTrue(str_contains($adminSource, '$updateMessage = "✅ بروزرسانی ربات با موفقیت انجام شد.";'), 'Successful update message contains extra runtime details.');
expectTrue(str_contains($fragmentSource, "'dryRun' => false"), 'Fragment purchases are not forced to real mode.');
expectTrue(!str_contains($fragmentSource, "telegramFragmentSetting('dry_run'"), 'Legacy dry-run setting still affects execution.');
expectTrue(str_contains($fragmentSource, 'tgp_fg_custom_stars') && str_contains($fragmentSource, 'stars_custom_min'), 'Custom Stars flow or limits are missing.');
expectTrue(str_contains($fragmentSource, 'vsa_fg_pricing') && str_contains($fragmentSource, 'profit_percent_stars'), 'Live pricing admin controls are missing.');
preg_match('/private static function quote\(.*?\/\* ---------- گیفت/s', $fragmentLiveSource, $premiumQuoteMatch);
$premiumQuoteSource = $premiumQuoteMatch[0] ?? '';
expectTrue(
    strpos($premiumQuoteSource, "if (\$kind === 'premium')") < strpos($premiumQuoteSource, 'self::find($page, $kind, $u, $amount)'),
    'Premium page state must be initialized before recipient lookup.'
);
expectTrue(str_contains($fragmentLiveSource, "fragment api ['"), 'Sanitized Fragment API diagnostics are missing.');
expectTrue(str_contains($virtualAdminSource, 'vsa_section_catalog') && str_contains($virtualAdminSource, 'vsa_section_settings'), 'Virtual services admin sections are missing.');

foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
@rmdir($dir);
echo "fragment security tests: OK\n";
