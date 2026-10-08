<?php
declare(strict_types=1);

function cardReceiptExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$functions = file_get_contents($root . '/function.php');
$index = file_get_contents($root . '/index.php');
$admin = file_get_contents($root . '/admin.php');
$cron = file_get_contents($root . '/cronbot/croncard.php');
$keyboard = file_get_contents($root . '/keyboard.php');

cardReceiptExpect(str_contains($keyboard, 'بررسی رسید در کانال'), 'Card settings entry is missing.');
cardReceiptExpect(str_contains($admin, 'card_receipt_channel_input'), 'Channel configuration flow is missing.');
cardReceiptExpect(str_contains($admin, "cardReceiptReviewSaveSetting('card_receipt_review_mode'"), 'Optional review mode is not saved.');
cardReceiptExpect(str_contains($functions, "getPaySettingValue('card_receipt_review_mode', 'admins')"), 'Legacy admin delivery is not the default.');
cardReceiptExpect(str_contains($functions, '($chat[\'type\'] ?? \'\') === \'channel\''), 'Channel callbacks are not restricted.');
cardReceiptExpect(str_contains($index, 'cardReceiptSendToReviewChannel($photoid, $caption, $textsendrasid, $Confirm_pay)'), 'Card receipt channel delivery is missing.');
cardReceiptExpect(str_contains($index, 'if (!$sentToReviewChannel)'), 'Admin fallback is missing.');
cardReceiptExpect(str_contains($cron, 'if ($channelReview && $row[\'Payment_Method\'] === \'cart to cart\') continue;'), 'Automatic card approval is still active in channel mode.');
cardReceiptExpect(str_contains($admin, 'cardReceiptMarkReviewedInChannel($update, $datain'), 'Channel review actions are not finalized.');

echo "card receipt channel tests: OK\n";
