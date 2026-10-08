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
cardReceiptExpect(str_contains($keyboard, 'بررسی رسید در گروه'), 'Group settings entry is missing.');
cardReceiptExpect(str_contains($admin, 'card_receipt_channel_input'), 'Channel configuration flow is missing.');
foreach (['admins', 'channel', 'group'] as $mode) {
    cardReceiptExpect(str_contains($admin, "cardReceiptReviewSaveSetting('card_receipt_review_mode', '" . $mode . "')"), 'Review mode is not selectable: ' . $mode);
}
cardReceiptExpect(str_contains($functions, "getPaySettingValue('card_receipt_review_mode', 'admins')"), 'Legacy admin delivery is not the default.');
cardReceiptExpect(str_contains($functions, "cardReceiptReviewGroupTarget()"), 'Group topic validation is missing.');
cardReceiptExpect(str_contains($functions, "cardReceiptReviewChatId()"), 'Channel validation is missing.');
cardReceiptExpect(str_contains($admin, "telegram('createForumTopic'"), 'Group topic creation is missing.');
cardReceiptExpect(str_contains($admin, "card_receipt_review_topic_id"), 'Topic id is not persisted.');
cardReceiptExpect(str_contains($index, 'cardReceiptSendToReviewDestination($photoid, $caption, $textsendrasid, $Confirm_pay)'), 'Card receipt destination delivery is missing.');
cardReceiptExpect(str_contains($index, 'if (!$sentToReviewDestination)'), 'Admin fallback is missing.');
cardReceiptExpect(str_contains($cron, 'if ($manualReview && $row[\'Payment_Method\'] === \'cart to cart\') continue;'), 'Automatic card approval is still active during manual review.');
cardReceiptExpect(str_contains($admin, 'cardReceiptMarkReviewedAtDestination($update, $datain'), 'Destination review actions are not finalized.');

echo "card receipt channel tests: OK\n";
