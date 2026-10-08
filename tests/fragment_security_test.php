<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/telegram_products.php';
require_once dirname(__DIR__) . '/telegram_fragment.php';
require_once dirname(__DIR__) . '/fragment-kit/php/FragmentKit.php';

function expectTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$update = ['message' => ['from' => ['id' => 1, 'is_premium' => false]]];
$custom = '<tg-emoji emoji-id="5280962371207077415">X</tg-emoji> عنوان';
expectTrue(telegramProductsSafeCustomText($custom) === ' عنوان', 'Custom emoji must be hidden for non-premium users.');
expectTrue(!isset(telegramProductsStyledButton('عنوان', 'test', 'primary', '5280962371207077415')['icon_custom_emoji_id']), 'Button custom emoji leaked to non-premium user.');

$update['message']['from']['is_premium'] = true;
expectTrue(str_contains(telegramProductsSafeCustomText($custom), 'tg-emoji'), 'Premium custom emoji was removed for premium user.');
expectTrue(isset(telegramProductsStyledButton('عنوان', 'test', 'primary', '5280962371207077415')['icon_custom_emoji_id']), 'Premium button custom emoji is missing.');

$internal = new RuntimeException('SQLSTATE connection failed at http://db:3306 secret=abc');
$card = telegramProductsFailureCard(telegramProductsPublicFailureReason($internal), 42, true);
expectTrue(!str_contains($card, 'SQLSTATE') && !str_contains($card, 'db:3306') && !str_contains($card, 'secret'), 'Generic purchase card leaked an internal error.');

$fragmentCard = telegramFragmentFailureCard(telegramFragmentSafeReason($internal), 51, false, false);
expectTrue(!str_contains($fragmentCard, 'SQLSTATE') && !str_contains($fragmentCard, 'db:3306'), 'Fragment purchase card leaked an internal error.');
$stored = telegramFragmentStoredError(new FragmentError('signer_down', 'Bearer top-secret http://signer:8787'));
expectTrue($stored === 'signer_down|سرویس پردازش تراکنش موقتاً در دسترس نیست.', 'Stored Fragment error is not sanitized.');

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
expectTrue(substr_count($installer, 'MIRZA_DOCKER_INSTANCE: \${BOT_SLUG}') >= 2, 'Docker app/worker instance identity is missing.');
expectTrue(str_contains($installer, 'MIRZA_FRAGMENT_SIGNER_URL: http://signer:8787'), 'Docker signer discovery is missing.');
expectTrue(str_contains($installer, 'fragment-egress:'), 'Isolated signer egress network is missing.');
expectTrue(substr_count($installer, 'USER node') >= 2, 'Signer container is not configured as an unprivileged user.');
expectTrue(!str_contains($installer, '${YOUR_BOT_TOKEN:0:10}'), 'Installer exposes part of the Telegram bot token.');
expectTrue(str_contains($signerSource, 'crypto.timingSafeEqual'), 'Signer token comparison is not timing safe.');
expectTrue(!str_contains($signerSource, '${token}\n('), 'Signer logs the bearer token.');
expectTrue(str_contains($signerSource, "{ mode: 0o600 }"), 'Signer state is not persisted with restrictive permissions.');
expectTrue(str_contains($adminSource, '$updateMessage = "✅ بروزرسانی ربات با موفقیت انجام شد.";'), 'Successful update message contains extra runtime details.');

foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
@rmdir($dir);
echo "fragment security tests: OK\n";
