<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/pasarguard.php';

function pgExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$apiKey = 'pg_key_12345678-1234-4abc-8def-1234567890ab';
pgExpect(pasarguardApiKey(['password_panel' => $apiKey]) === $apiKey, 'PasarGuard API key was not detected.');
pgExpect(pasarguardApiKey(['password_panel' => 'secret']) === '', 'A password was mistaken for an API key.');
$auth = pasarguardAuthenticate(['password_panel' => $apiKey]);
pgExpect(($auth['mode'] ?? '') === 'api_key' && ($auth['api_key'] ?? '') === $apiKey, 'API key authentication mode is incorrect.');
pgExpect(!pasarguardSwitchCredentials([], 'admin', 'secret')['ok'], 'Invalid panel was accepted for credential migration.');

$latestGroups = pasarguardExtractCollection([
    'groups' => [
        ['id' => 4, 'name' => 'premium', 'is_disabled' => false],
        ['id' => 7, 'name' => 'wireguard', 'is_disabled' => false],
    ],
    'total' => 2,
], ['groups', 'items', 'results']);
pgExpect(count($latestGroups) === 2 && (int) $latestGroups[1]['id'] === 7, 'Latest group response was not parsed.');

$raw = "vless://uuid@example.com:443#one\n"
    . "hysteria2://password@example.com:443#two\n";
$links = pasarguardParseSubscriptionLinks(base64_encode($raw));
pgExpect(count($links) === 2 && str_starts_with($links[0], 'vless://'), 'Base64 subscription response was not parsed.');

$jsonLinks = pasarguardParseSubscriptionLinks(json_encode(['links' => [
    'trojan://password@example.com:443#one',
    'ss://encoded@example.com:443#two',
]], JSON_UNESCAPED_SLASHES));
pgExpect(count($jsonLinks) === 2, 'JSON subscription response was not parsed.');

echo "PasarGuard compatibility tests passed.\n";
