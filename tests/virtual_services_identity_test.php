<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/telegram_products.php';
require_once dirname(__DIR__) . '/telegram_products_features.php';

function identityExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function update(...$args): void
{
    $GLOBALS['identityStepWrites'][] = $args;
}

function step($value, $userId): void
{
    $GLOBALS['identityStepWrites'][] = [$value, $userId];
}

function sendmessage($chatId, $message, $markup, $parseMode): array
{
    $GLOBALS['identitySentMessages'][] = [$chatId, $message, $markup, $parseMode];
    return ['ok' => true];
}

identityExpect(telegramProductsIdentityModeLabel('phone') === 'تأیید شماره', 'Phone mode label is missing.');
identityExpect(telegramProductsIdentityNormalizePhone('+989121234567') === '+989121234567', 'A valid shared phone was rejected.');
identityExpect(telegramProductsIdentityNormalizePhone('989121234567') === '+989121234567', 'Telegram contact without plus was rejected.');
identityExpect(telegramProductsIdentityNormalizePhone('+447911123456') === '', 'Foreign phone was accepted.');
identityExpect(telegramProductsIdentityNormalizePhone('09121234567') === '', 'Local phone without +98 was accepted.');
identityExpect(telegramProductsIdentityNormalizePhone('abc123') === '', 'Invalid phone was accepted.');
identityExpect(telegramProductsIdentityModeLabel('users') === 'احراز فقط مخصوص کاربران ایرانی', 'Users-only mode label is missing.');
$contactKeyboard = json_decode(telegramProductsIdentityStepKeyboard(true), true);
$otherKeyboard = json_decode(telegramProductsIdentityStepKeyboard(), true);
identityExpect(!empty($contactKeyboard['keyboard'][0][0]['request_contact']), 'Contact request button is missing.');
identityExpect(($contactKeyboard['keyboard'][1][0]['text'] ?? '') === 'انصراف', 'Contact cancellation button is missing.');
identityExpect(($otherKeyboard['keyboard'][0][0]['text'] ?? '') === 'انصراف', 'Step cancellation button is missing.');
identityExpect(telegramProductsIdentityCancelRequested('انصراف'), 'Cancellation text is not accepted.');
identityExpect(telegramProductsIdentityCancelRequested('/start'), 'Start command does not exit identity flow.');
identityExpect(!telegramProductsIdentityCancelRequested('علی رضایی'), 'Valid input was treated as cancellation.');
foreach (['contact', 'card', 'photo', 'name', 'national'] as $stage) {
    $from_id = '123';
    $datain = '';
    $text = 'انصراف';
    $user = ['step' => 'tgp_identity_' . $stage . '_7', 'Processing_value' => 'value'];
    $keyboard = '{"keyboard":[]}';
    $identitySentMessages = [];
    $identityStepWrites = [];
    identityExpect(telegramProductsIdentityHandleUser(), 'Cancellation was not handled in ' . $stage . '.');
    identityExpect($user['step'] === 'home', 'Identity step was not cleared in ' . $stage . '.');
    identityExpect(count($identitySentMessages) === 2, 'Main keyboard was not restored in ' . $stage . '.');
    identityExpect(count($identityStepWrites) === 2, 'Cancellation unexpectedly touched identity storage.');
}
identityExpect(telegramProductsIdentityModeForAgent('users', 'f') === 'phone', 'Ordinary users must verify phone.');
identityExpect(telegramProductsIdentityModeForAgent('users', 'n') === 'none', 'Representatives should be exempt.');
identityExpect(telegramProductsIdentityNationalIdValid('1234567891'), 'Valid national ID checksum was rejected.');
identityExpect(!telegramProductsIdentityNationalIdValid('1234567890'), 'Invalid national ID checksum was accepted.');
identityExpect(!telegramProductsIdentityNationalIdValid('1111111111'), 'Repeated national ID was accepted.');

$phone = ['phone' => '+989121234567', 'phone_verified_at' => '2026-10-08 12:00:00', 'status' => 'none'];
identityExpect(telegramProductsIdentitySatisfied('none', null), 'Optional identity mode should not block purchases.');
identityExpect(!telegramProductsIdentitySatisfied('phone', null), 'Missing phone was accepted.');
identityExpect(telegramProductsIdentitySatisfied('phone', $phone), 'Verified phone was rejected.');
identityExpect(!telegramProductsIdentitySatisfied('phone', ['phone' => '+447911123456', 'phone_verified_at' => '2026-10-08 12:00:00']), 'Foreign phone was accepted.');
identityExpect(!telegramProductsIdentitySatisfied('full', $phone), 'Phone-only identity was accepted for full mode.');
$phone['status'] = 'pending';
identityExpect(!telegramProductsIdentitySatisfied('full', $phone), 'Pending full identity was accepted.');
$phone['status'] = 'approved';
identityExpect(telegramProductsIdentitySatisfied('full', $phone), 'Approved full identity was rejected.');
identityExpect(telegramProductsAdminPermissionForRequest('vsa_identity_view_123') === 'identity', 'Identity review is not permission-protected.');
identityExpect(telegramProductsAdminPermissionForRequest('vsa_identity_set_2_full') === 'catalog', 'Identity mode editing is not catalog-protected.');

$source = file_get_contents(dirname(__DIR__) . '/telegram_products.php');
$fragmentSource = file_get_contents(dirname(__DIR__) . '/telegram_fragment.php');
$identitySource = file_get_contents(dirname(__DIR__) . '/telegram_products_identity.php');
identityExpect(str_contains($source, 'telegramProductsIdentityGate($product)'), 'Product purchase does not check identity.');
identityExpect(str_contains($source, 'telegramProductsIdentityGet($from_id, true)'), 'Payment does not lock the identity row.');
identityExpect(str_contains($fragmentSource, "telegramFragmentSetting('auth_mode', 'none')"), 'Fragment payment is not linked to virtual-services identity mode.');
identityExpect(str_contains($fragmentSource, 'telegramProductsIdentityGet($from_id, true)'), 'Fragment payment does not lock the identity row.');
identityExpect(!str_contains($fragmentSource, "telegramProductsIdentityGate(telegramProductsIdentityProduct('fg'))"), 'Fragment price is hidden behind identity verification.');
identityExpect(str_contains($identitySource, "telegramProductsEnsureReportTopic('virtualservices_identity'"), 'Identity topic is not created in the main report group.');
identityExpect(str_contains($identitySource, "'protect_content' => true"), 'Identity evidence is not protected in the report topic.');
identityExpect(str_contains($identitySource, "if (\$match[1] !== 'start')"), 'Legacy user deletion callback is not denied.');
identityExpect(substr_count($identitySource, "DELETE FROM telegram_product_identity") === 1, 'User-facing deletion path still exists.');
identityExpect(str_contains($identitySource, "status<>'approved'"), 'Verified identity is not protected from user updates.');
identityExpect(str_contains($source, 'identity_full_name_text') && str_contains($source, 'identity_national_id_text'), 'Customizable identity stage texts are missing.');
identityExpect(str_contains($source, 'card_photo_file_id') && str_contains($identitySource, 'vsa_identity_carddoc_'), 'Bank-card identity evidence is missing.');
identityExpect(str_contains($identitySource, 'مرحله ۳ از ۴') && str_contains($identitySource, 'مرحله ۴ از ۴'), 'Full name and national ID stages are missing.');
identityExpect(str_contains($identitySource, "'protect_content' => 'true'"), 'Identity evidence is not protected when shown to administrators.');
echo "virtual services identity tests: OK\n";
