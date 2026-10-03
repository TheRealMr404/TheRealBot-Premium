<?php

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
#----------------[  admin section  ]------------------#

$textadmin = ["panel", "/panel", "پنل مدیریت", "ادمین", "👨‍💼 پنل مدیریت"];
if (!in_array($from_id, $admin_idsmain) and !in_array($from_id, $admin_ids)) {
    return;
}
if (in_array($text, $textadmin) || $datain == "admin") {
    $text_admin = "Version Bot : $version
Panel Admin";
    sendmessage($from_id, $text_admin, $keyboardadmin, 'HTML');
    step("home", $from_id);
    return;
}
if ($text == "بازگشت به منوی ادمین") {
    sendmessage($from_id, "به منوی ادمین بازگشتید", $keyboardadmin, 'HTML');
    step("home", $from_id);
    return;
}
if ($text == "💳 مدیریت درگاه‌ها" || $datain === 'rsgw_back') {
    $view = resellerGatewayAdminView($setting);
    if ($datain === 'rsgw_back') {
        Editmessagetext($from_id, $message_id, $view['text'], $view['keyboard'], 'HTML');
    } else {
        sendmessage($from_id, $view['text'], $view['keyboard'], 'HTML');
    }
    step('home', $from_id);
} elseif (preg_match('/^rsgw_toggle_(card|zarinpal|aqayepardakht|nowpayments)$/', $datain, $gatewayMatch)) {
    $gatewayKey = $gatewayMatch[1];
    $currentlyEnabled = (bool) $setting['payment_gateways'][$gatewayKey]['enabled'];
    if (!$currentlyEnabled && !resellerGatewayIsAvailable($gatewayKey, $setting)) {
        telegram('answerCallbackQuery', [
            'callback_query_id' => $callback_query_id,
            'text' => 'ابتدا اطلاعات اتصال این درگاه را در همین ربات تکمیل کنید.',
            'show_alert' => true,
        ]);
        return;
    }
    $setting['payment_gateways'][$gatewayKey]['enabled'] = !$currentlyEnabled;
    $setting = resellerBotSaveSettings($ApiToken, $setting);
    $view = resellerGatewayAdminView($setting);
    Editmessagetext($from_id, $message_id, $view['text'], $view['keyboard'], 'HTML');
} elseif (preg_match('/^rsgw_move_(card|zarinpal|aqayepardakht|nowpayments)_(up|down)$/', $datain, $gatewayMatch)) {
    $gatewayKey = $gatewayMatch[1];
    $direction = $gatewayMatch[2];
    $catalog = resellerGatewayCatalog($setting);
    $keys = array_column($catalog, 'key');
    $currentIndex = array_search($gatewayKey, $keys, true);
    $targetIndex = $direction === 'up' ? $currentIndex - 1 : $currentIndex + 1;
    if ($currentIndex !== false && isset($keys[$targetIndex])) {
        $targetKey = $keys[$targetIndex];
        $currentOrder = $setting['payment_gateways'][$gatewayKey]['order'];
        $setting['payment_gateways'][$gatewayKey]['order'] = $setting['payment_gateways'][$targetKey]['order'];
        $setting['payment_gateways'][$targetKey]['order'] = $currentOrder;
        $setting = resellerBotSaveSettings($ApiToken, $setting);
    }
    $view = resellerGatewayAdminView($setting);
    Editmessagetext($from_id, $message_id, $view['text'], $view['keyboard'], 'HTML');
} elseif (preg_match('/^rsgw_edit_(card|zarinpal|aqayepardakht|nowpayments)$/', $datain, $gatewayMatch)) {
    $view = resellerGatewayEditorView($setting, $gatewayMatch[1]);
    Editmessagetext($from_id, $message_id, $view['text'], $view['keyboard'], 'HTML');
} elseif (preg_match('/^rsgw_style_(card|zarinpal|aqayepardakht|nowpayments)$/', $datain, $gatewayMatch)) {
    $gatewayKey = $gatewayMatch[1];
    $styles = ['primary', 'success', 'danger'];
    $currentIndex = array_search($setting['payment_gateways'][$gatewayKey]['style'], $styles, true);
    $setting['payment_gateways'][$gatewayKey]['style'] = $styles[(($currentIndex === false ? 0 : $currentIndex) + 1) % count($styles)];
    $setting = resellerBotSaveSettings($ApiToken, $setting);
    $view = resellerGatewayEditorView($setting, $gatewayKey);
    Editmessagetext($from_id, $message_id, $view['text'], $view['keyboard'], 'HTML');
} elseif (preg_match('/^rsgw_title_(card|zarinpal|aqayepardakht|nowpayments)$/', $datain, $gatewayMatch)) {
    savedata('clear', 'reseller_gateway', $gatewayMatch[1]);
    sendmessage($from_id, 'نام نمایشی جدید درگاه را ارسال کنید. حداکثر ۴۰ کاراکتر.', $backadmin, 'HTML');
    step('reseller_gateway_title', $from_id);
} elseif ($user['step'] === 'reseller_gateway_title') {
    $stepData = json_decode($user['Processing_value'], true);
    $gatewayKey = $stepData['reseller_gateway'] ?? '';
    $newTitle = trim(strip_tags((string) $text));
    if (!isset($setting['payment_gateways'][$gatewayKey]) || $newTitle === '' || mb_strlen($newTitle, 'UTF-8') > 40) {
        sendmessage($from_id, '❌ نام درگاه باید بین ۱ تا ۴۰ کاراکتر باشد.', $backadmin, 'HTML');
        return;
    }
    $setting['payment_gateways'][$gatewayKey]['title'] = $newTitle;
    $setting = resellerBotSaveSettings($ApiToken, $setting);
    sendmessage($from_id, '✅ نام درگاه ذخیره شد.', $keyboardadmin, 'HTML');
    step('home', $from_id);
} elseif (preg_match('/^rsgw_emoji_set_(card|zarinpal|aqayepardakht|nowpayments)$/', $datain, $gatewayMatch)) {
    savedata('clear', 'reseller_gateway', $gatewayMatch[1]);
    sendmessage($from_id, 'شناسه عددی ایموجی پریمیوم را ارسال کنید.', $backadmin, 'HTML');
    step('reseller_gateway_emoji', $from_id);
} elseif ($user['step'] === 'reseller_gateway_emoji') {
    $stepData = json_decode($user['Processing_value'], true);
    $gatewayKey = $stepData['reseller_gateway'] ?? '';
    if (!isset($setting['payment_gateways'][$gatewayKey]) || !preg_match('/^\d{5,30}$/', (string) $text)) {
        sendmessage($from_id, '❌ شناسه ایموجی معتبر نیست.', $backadmin, 'HTML');
        return;
    }
    $emojiCheck = telegram('getCustomEmojiStickers', [
        'custom_emoji_ids' => json_encode([(string) $text]),
    ]);
    if (!is_array($emojiCheck) || empty($emojiCheck['ok']) || empty($emojiCheck['result'])) {
        sendmessage($from_id, '❌ این شناسه ایموجی در تلگرام معتبر نیست.', $backadmin, 'HTML');
        return;
    }
    $setting['payment_gateways'][$gatewayKey]['emoji_id'] = (string) $text;
    $setting = resellerBotSaveSettings($ApiToken, $setting);
    sendmessage($from_id, '✅ ایموجی درگاه ذخیره شد.', $keyboardadmin, 'HTML');
    step('home', $from_id);
} elseif (preg_match('/^rsgw_emoji_clear_(card|zarinpal|aqayepardakht|nowpayments)$/', $datain, $gatewayMatch)) {
    $setting['payment_gateways'][$gatewayMatch[1]]['emoji_id'] = '';
    $setting = resellerBotSaveSettings($ApiToken, $setting);
    $view = resellerGatewayEditorView($setting, $gatewayMatch[1]);
    Editmessagetext($from_id, $message_id, $view['text'], $view['keyboard'], 'HTML');
} elseif (preg_match('/^rsgw_credential_(zarinpal|aqayepardakht|nowpayments)$/', $datain, $gatewayMatch)) {
    savedata('clear', 'reseller_gateway', $gatewayMatch[1]);
    $labels = [
        'zarinpal' => 'مرچنت آیدی زرین‌پال',
        'aqayepardakht' => 'PIN آقای پرداخت',
        'nowpayments' => 'API Key سرویس NOWPayments',
    ];
    sendmessage($from_id, 'مقدار <b>' . $labels[$gatewayMatch[1]] . '</b> را ارسال کنید. پیام شما پس از ثبت حذف می‌شود.', $backadmin, 'HTML');
    step('reseller_gateway_credential', $from_id);
} elseif ($user['step'] === 'reseller_gateway_credential') {
    $stepData = json_decode($user['Processing_value'], true);
    $gatewayKey = $stepData['reseller_gateway'] ?? '';
    $credentialField = resellerGatewayCredentialField($gatewayKey);
    $credential = trim((string) $text);
    if ($credentialField === '' || !preg_match('/^[A-Za-z0-9._-]{8,255}$/', $credential)) {
        sendmessage($from_id, 'اطلاعات اتصال معتبر نیست. مقدار را بدون فاصله و به‌صورت کامل ارسال کنید.', $backadmin, 'HTML');
        return;
    }
    deletemessage($from_id, $message_id);
    $setting['payment_gateways'][$gatewayKey][$credentialField] = $credential;
    $setting = resellerBotSaveSettings($ApiToken, $setting);
    $view = resellerGatewayEditorView($setting, $gatewayKey);
    sendmessage($from_id, 'اطلاعات اتصال با موفقیت و به‌صورت اختصاصی ذخیره شد.\n\n' . $view['text'], $view['keyboard'], 'HTML');
    step('home', $from_id);
} elseif ($datain === 'rsgw_ipn_nowpayments') {
    savedata('clear', 'reseller_gateway', 'nowpayments');
    sendmessage($from_id, 'IPN Secret سرویس NOWPayments را ارسال کنید. این پیام پس از ثبت حذف می‌شود.', $backadmin, 'HTML');
    step('reseller_nowpayments_ipn_secret', $from_id);
} elseif ($user['step'] === 'reseller_nowpayments_ipn_secret') {
    $secret = trim((string) $text);
    if (strlen($secret) < 8 || strlen($secret) > 255 || preg_match('/\s/', $secret)) {
        sendmessage($from_id, 'IPN Secret معتبر نیست.', $backadmin, 'HTML');
        return;
    }
    deletemessage($from_id, $message_id);
    $setting['payment_gateways']['nowpayments']['ipn_secret'] = $secret;
    $setting = resellerBotSaveSettings($ApiToken, $setting);
    $view = resellerGatewayEditorView($setting, 'nowpayments');
    sendmessage($from_id, 'IPN Secret ذخیره شد.\n\n' . $view['text'], $view['keyboard'], 'HTML');
    step('home', $from_id);
} elseif (preg_match('/^rsgw_credential_clear_(zarinpal|aqayepardakht|nowpayments)$/', $datain, $gatewayMatch)) {
    $gatewayKey = $gatewayMatch[1];
    $credentialField = resellerGatewayCredentialField($gatewayKey);
    $setting['payment_gateways'][$gatewayKey][$credentialField] = '';
    if ($gatewayKey === 'nowpayments') {
        $setting['payment_gateways'][$gatewayKey]['ipn_secret'] = '';
    }
    $setting['payment_gateways'][$gatewayKey]['enabled'] = false;
    $setting = resellerBotSaveSettings($ApiToken, $setting);
    $view = resellerGatewayEditorView($setting, $gatewayKey);
    Editmessagetext($from_id, $message_id, $view['text'], $view['keyboard'], 'HTML');
} elseif (preg_match('/^rsgw_(min|max|cashback)_(card|zarinpal|aqayepardakht|nowpayments)$/', $datain, $gatewayMatch)) {
    savedata('clear', 'reseller_gateway_field', $gatewayMatch[1]);
    savedata('save', 'reseller_gateway', $gatewayMatch[2]);
    $prompt = $gatewayMatch[1] === 'cashback'
        ? 'درصد کش‌بک را از ۰ تا ۱۰۰ ارسال کنید.'
        : 'مبلغ را به تومان و فقط به‌صورت عدد ارسال کنید.';
    sendmessage($from_id, $prompt, $backadmin, 'HTML');
    step('reseller_gateway_numeric_setting', $from_id);
} elseif ($user['step'] === 'reseller_gateway_numeric_setting') {
    $stepData = json_decode($user['Processing_value'], true);
    $gatewayKey = $stepData['reseller_gateway'] ?? '';
    $field = $stepData['reseller_gateway_field'] ?? '';
    if (!isset($setting['payment_gateways'][$gatewayKey]) || !in_array($field, ['min', 'max', 'cashback'], true)) {
        sendmessage($from_id, 'درخواست تنظیمات معتبر نیست.', $keyboardadmin, 'HTML');
        step('home', $from_id);
        return;
    }
    if ($field === 'cashback') {
        if (!is_numeric($text) || (float) $text < 0 || (float) $text > 100) {
            sendmessage($from_id, 'درصد کش‌بک باید بین ۰ تا ۱۰۰ باشد.', $backadmin, 'HTML');
            return;
        }
        $setting['payment_gateways'][$gatewayKey]['cashback_percent'] = round((float) $text, 2);
    } else {
        if (!ctype_digit((string) $text) || (int) $text < 1000 || (int) $text > 1000000000) {
            sendmessage($from_id, 'مبلغ باید عددی و بین ۱٬۰۰۰ تا ۱٬۰۰۰٬۰۰۰٬۰۰۰ تومان باشد.', $backadmin, 'HTML');
            return;
        }
        $amountField = $field === 'min' ? 'min_amount' : 'max_amount';
        $otherField = $field === 'min' ? 'max_amount' : 'min_amount';
        if (($field === 'min' && (int) $text > (int) $setting['payment_gateways'][$gatewayKey][$otherField])
            || ($field === 'max' && (int) $text < (int) $setting['payment_gateways'][$gatewayKey][$otherField])) {
            sendmessage($from_id, 'حداقل مبلغ نمی‌تواند از حداکثر بیشتر باشد.', $backadmin, 'HTML');
            return;
        }
        $setting['payment_gateways'][$gatewayKey][$amountField] = (int) $text;
    }
    $setting = resellerBotSaveSettings($ApiToken, $setting);
    $view = resellerGatewayEditorView($setting, $gatewayKey);
    sendmessage($from_id, 'تنظیمات درگاه ذخیره شد.\n\n' . $view['text'], $view['keyboard'], 'HTML');
    step('home', $from_id);
} elseif ($text == "🎨 شخصی‌سازی ربات") {
    $statusText = $setting['bot_enabled'] ? 'روشن' : 'در حالت تعمیرات';
    $summary = "🎨 <b>شخصی‌سازی ربات نماینده</b>\n\n"
        . "وضعیت: {$statusText}\n"
        . 'حداقل شارژ: ' . number_format($setting['min_deposit']) . " تومان\n"
        . 'حداکثر شارژ: ' . number_format($setting['max_deposit']) . " تومان\n"
        . 'اعلان پرداخت به ادمین‌ها: ' . ($setting['notify_admin_payment'] ? 'روشن' : 'خاموش') . "\n"
        . 'مقصد گزارش: ' . ($setting['report_chat_id'] !== '' ? "<code>{$setting['report_chat_id']}</code>" : 'تنظیم نشده');
    sendmessage($from_id, $summary, $keyboard_reseller_brand, 'HTML');
} elseif ($text == "⏸ غیرفعال‌کردن ربات" || $text == "▶️ فعال‌کردن ربات") {
    $setting['bot_enabled'] = !$setting['bot_enabled'];
    $setting = resellerBotSaveSettings($ApiToken, $setting);
    sendmessage($from_id, $setting['bot_enabled'] ? '✅ ربات برای کاربران فعال شد.' : '⏸ ربات در حالت تعمیرات قرار گرفت.', $keyboardadmin, 'HTML');
    step('home', $from_id);
} elseif ($text == "📝 متن خوش‌آمدگویی") {
    sendmessage($from_id, "متن جدید را ارسال کنید. برای نمایش نام کاربر از <code>{name}</code> استفاده کنید.", $backadmin, 'HTML');
    step('reseller_welcome_text', $from_id);
} elseif ($user['step'] === 'reseller_welcome_text') {
    $newText = trim(strip_tags((string) $text));
    if ($newText === '' || mb_strlen($newText, 'UTF-8') > 1000) {
        sendmessage($from_id, '❌ متن باید بین ۱ تا ۱۰۰۰ کاراکتر باشد.', $backadmin, 'HTML');
        return;
    }
    $setting['welcome_text'] = $newText;
    resellerBotSaveSettings($ApiToken, $setting);
    sendmessage($from_id, '✅ متن خوش‌آمدگویی ذخیره شد.', $keyboard_reseller_brand, 'HTML');
    step('home', $from_id);
} elseif ($text == "🧾 متن انتخاب درگاه") {
    sendmessage($from_id, "متن مرحله انتخاب درگاه را ارسال کنید. از <code>{amount}</code> برای مبلغ شارژ استفاده کنید.", $backadmin, 'HTML');
    step('reseller_payment_intro_text', $from_id);
} elseif ($user['step'] === 'reseller_payment_intro_text') {
    $newText = trim(strip_tags((string) $text));
    if ($newText === '' || mb_strlen($newText, 'UTF-8') > 700) {
        sendmessage($from_id, '❌ متن باید بین ۱ تا ۷۰۰ کاراکتر باشد.', $backadmin, 'HTML');
        return;
    }
    $setting['payment_intro_text'] = $newText;
    resellerBotSaveSettings($ApiToken, $setting);
    sendmessage($from_id, '✅ متن انتخاب درگاه ذخیره شد.', $keyboard_reseller_brand, 'HTML');
    step('home', $from_id);
} elseif ($text == "✅ متن پرداخت موفق") {
    sendmessage($from_id, "متن تأیید پرداخت را ارسال کنید.\n\nمتغیرهای مجاز:\n<code>{amount}</code> مبلغ پرداخت\n<code>{cashback}</code> مبلغ کش‌بک\n<code>{credit}</code> مجموع مبلغ و کش‌بک\n<code>{balance}</code> موجودی جدید\n<code>{method}</code> روش پرداخت\n<code>{order}</code> کد پیگیری", $backadmin, 'HTML');
    step('reseller_payment_success_text', $from_id);
} elseif ($user['step'] === 'reseller_payment_success_text') {
    $newText = trim(strip_tags((string) $text));
    if ($newText === '' || mb_strlen($newText, 'UTF-8') > 1000) {
        sendmessage($from_id, '❌ متن باید بین ۱ تا ۱۰۰۰ کاراکتر باشد.', $backadmin, 'HTML');
        return;
    }
    $setting['payment_success_text'] = $newText;
    resellerBotSaveSettings($ApiToken, $setting);
    sendmessage($from_id, '✅ متن پرداخت موفق ذخیره شد.', $keyboard_reseller_brand, 'HTML');
    step('home', $from_id);
} elseif ($text == "🔔 اعلان پرداخت ادمین‌ها" || $text == "🔕 اعلان پرداخت ادمین‌ها") {
    $setting['notify_admin_payment'] = !$setting['notify_admin_payment'];
    $setting = resellerBotSaveSettings($ApiToken, $setting);
    sendmessage($from_id, $setting['notify_admin_payment'] ? '✅ اعلان پرداخت به ادمین‌ها فعال شد.' : '🔕 اعلان پرداخت به ادمین‌ها غیرفعال شد.', $keyboard_reseller_brand, 'HTML');
    step('home', $from_id);
} elseif ($text == "🛠 متن حالت تعمیرات") {
    sendmessage($from_id, 'متنی را ارسال کنید که هنگام غیرفعال‌بودن ربات به کاربران نمایش داده شود.', $backadmin, 'HTML');
    step('reseller_maintenance_text', $from_id);
} elseif ($user['step'] === 'reseller_maintenance_text') {
    $newText = trim(strip_tags((string) $text));
    if ($newText === '' || mb_strlen($newText, 'UTF-8') > 500) {
        sendmessage($from_id, '❌ متن باید بین ۱ تا ۵۰۰ کاراکتر باشد.', $backadmin, 'HTML');
        return;
    }
    $setting['maintenance_text'] = $newText;
    resellerBotSaveSettings($ApiToken, $setting);
    sendmessage($from_id, '✅ متن حالت تعمیرات ذخیره شد.', $keyboard_reseller_brand, 'HTML');
    step('home', $from_id);
} elseif ($text == "⬇️ حداقل مبلغ شارژ") {
    sendmessage($from_id, 'حداقل مبلغ شارژ را به تومان و فقط به‌صورت عدد ارسال کنید.', $backadmin, 'HTML');
    step('reseller_min_deposit', $from_id);
} elseif ($user['step'] === 'reseller_min_deposit') {
    if (!ctype_digit((string) $text) || (int) $text < 1000 || (int) $text > $setting['max_deposit']) {
        sendmessage($from_id, '❌ حداقل شارژ باید عددی، حداقل ۱۰۰۰ تومان و کمتر از سقف فعلی باشد.', $backadmin, 'HTML');
        return;
    }
    $setting['min_deposit'] = (int) $text;
    resellerBotSaveSettings($ApiToken, $setting);
    sendmessage($from_id, '✅ حداقل مبلغ شارژ ذخیره شد.', $keyboard_reseller_brand, 'HTML');
    step('home', $from_id);
} elseif ($text == "⬆️ حداکثر مبلغ شارژ") {
    sendmessage($from_id, 'حداکثر مبلغ شارژ را به تومان و فقط به‌صورت عدد ارسال کنید.', $backadmin, 'HTML');
    step('reseller_max_deposit', $from_id);
} elseif ($user['step'] === 'reseller_max_deposit') {
    if (!ctype_digit((string) $text) || (int) $text < $setting['min_deposit'] || (int) $text > 1000000000) {
        sendmessage($from_id, '❌ سقف شارژ باید از حداقل فعلی بیشتر و حداکثر یک میلیارد تومان باشد.', $backadmin, 'HTML');
        return;
    }
    $setting['max_deposit'] = (int) $text;
    resellerBotSaveSettings($ApiToken, $setting);
    sendmessage($from_id, '✅ حداکثر مبلغ شارژ ذخیره شد.', $keyboard_reseller_brand, 'HTML');
    step('home', $from_id);
} elseif ($text == "📬 مقصد گزارش‌ها" || $text == "📬 گزارش ربات") {
    sendmessage($from_id, "آیدی عددی کاربر، گروه یا کانال گزارش را ارسال کنید. برای حذف، عدد <code>0</code> را بفرستید.\n\nربات باید در مقصد موردنظر دسترسی ارسال پیام داشته باشد.", $backadmin, 'HTML');
    step('reseller_report_chat', $from_id);
} elseif ($user['step'] === 'reseller_report_chat') {
    if (!preg_match('/^-?\d+$/', (string) $text)) {
        sendmessage($from_id, '❌ آیدی مقصد معتبر نیست.', $backadmin, 'HTML');
        return;
    }
    if ((string) $text !== '0') {
        $testReport = telegram('sendmessage', [
            'chat_id' => $text,
            'text' => '✅ اتصال گزارش‌های ربات نماینده با موفقیت برقرار شد.',
        ]);
        if (!is_array($testReport) || empty($testReport['ok'])) {
            sendmessage($from_id, '❌ ارسال پیام آزمایشی ناموفق بود. دسترسی ربات در مقصد را بررسی کنید.', $backadmin, 'HTML');
            return;
        }
    }
    $setting['report_chat_id'] = (string) $text === '0' ? '' : (string) $text;
    resellerBotSaveSettings($ApiToken, $setting);
    sendmessage($from_id, '✅ مقصد گزارش‌ها ذخیره شد.', $keyboard_reseller_brand, 'HTML');
    step('home', $from_id);
} elseif ($text == "📞 تنظیم نام کاربری پشتیبانی") {
    sendmessage($from_id, "📌 نام کاربری جدید خود را بدون @ ارسال کنید", $backadmin, 'HTML');
    step("getusernamesupport", $from_id);
} elseif ($user['step'] == "getusernamesupport") {
    $supportUsername = ltrim(trim((string) $text), '@');
    if (!preg_match('/^[A-Za-z0-9_]{5,32}$/', $supportUsername)) {
        sendmessage($from_id, '❌ نام کاربری پشتیبانی معتبر نیست.', $backadmin, 'HTML');
        return;
    }
    sendmessage($from_id, "✅ نام کاربری پشتیبانی برای شما با موفقیت تنظیم گردید.", $keyboardadmin, 'HTML');
    step("home", $from_id);
    $setting['support_username'] = $supportUsername;
    update("botsaz", "setting", json_encode($setting), "bot_token", $ApiToken);
} elseif ($text == "🔋 قیمت حجم") {
    sendmessage($from_id, "📌 قیمت هر گیگ حجم را ارسال نمایید. 
قیمت پایه حجم. : {$setting['minpricevolume']} تومان
قیمت فعلی حجم. : {$setting['pricevolume']} تومان", $backadmin, 'HTML');
    step("getpricvolumeadmin", $from_id);
} elseif ($user['step'] == "getpricvolumeadmin") {
    if (!ctype_digit($text)) {
        sendmessage($from_id, $textbotlang['Admin']['agent']['invalidvlue'], $backadmin, 'HTML');
        return;
    }
    if (intval($text) < intval($setting['minpricevolume'])) {
        sendmessage($from_id, "❌ قیمت حجم باید بزرگ تر از قیمت پایه حجم باشد.", $backadmin, 'HTML');
        return;
    }
    sendmessage($from_id, "✅ قیمت حجم با موفقیت تنظیم گردید.", $keyboardprice, 'HTML');
    step("home", $from_id);
    $setting['pricevolume'] = $text;
    update("botsaz", "setting", json_encode($setting), "bot_token", $ApiToken);
} elseif ($text == "⌛️ قیمت زمان") {
    sendmessage($from_id, "
📌 قیمت هر روز زمان را ارسال نمایید.
 قیمت پایه زمان. : {$setting['minpricetime']} تومان
قیمت فعلی شما : {$setting['pricetime']} تومان", $backadmin, 'HTML');
    step("getpricvtimeadmin", $from_id);
} elseif ($user['step'] == "getpricvtimeadmin") {
    if (!ctype_digit($text)) {
        sendmessage($from_id, $textbotlang['Admin']['agent']['invalidvlue'], $backadmin, 'HTML');
        return;
    }
    if (intval($text) < intval($setting['minpricetime'])) {
        sendmessage($from_id, "❌ قیمت زمان باید بزرگ تر از قیمت پایه زمان باشد.", $backadmin, 'HTML');
        return;
    }
    sendmessage($from_id, "✅ قیمت زمان با موفقیت تنظیم گردید.", $keyboardprice, 'HTML');
    step("home", $from_id);
    $setting['pricetime'] = $text;
    update("botsaz", "setting", json_encode($setting), "bot_token", $ApiToken);
} elseif (preg_match('/Confirm_pay_(\w+)/', $datain, $dataget)) {
    $order_id = $dataget[1];
    $Confirm_pay = json_encode([
        'inline_keyboard' => [
            [],
            [
                ['text' => "✅ تایید شده", 'callback_data' => "confirmpaid"],
            ]
        ]
    ]);
    $Payment_report = select("Payment_report", "*", "id_order", $order_id, "select");
    if ($Payment_report == false) {
        telegram('answerCallbackQuery', array(
            'callback_query_id' => $callback_query_id,
            'text' => "تراکنش حذف شده است",
            'show_alert' => true,
            'cache_time' => 5,
        ));
        return;
    }
    if (!hash_equals((string) $ApiToken, (string) ($Payment_report['bottype'] ?? ''))
        || !in_array((string) ($Payment_report['Payment_Method'] ?? ''), ['cart to cart', 'arze digital offline'], true)) {
        telegram('answerCallbackQuery', [
            'callback_query_id' => $callback_query_id,
            'text' => 'این تراکنش متعلق به این ربات نیست.',
            'show_alert' => true,
        ]);
        return;
    }
    $format_price_cart = number_format($Payment_report['price']);
    $Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");
    if ($Payment_report['payment_Status'] == "paid" || $Payment_report['payment_Status'] == "reject") {
        telegram('answerCallbackQuery', array(
            'callback_query_id' => $callback_query_id,
            'text' => $textbotlang['Admin']['Payment']['reviewedpayment'],
            'show_alert' => true,
            'cache_time' => 5,
        ));
        $textconfrom = "✅. پرداخت توسط ادمین دیگری تایید شده
👤 شناسه کاربر: <code>{$Balance_id['id']}</code>
🛒 کد پیگیری پرداخت: {$Payment_report['id_order']}
⚜️ نام کاربری: @{$Balance_id['username']}
    💸 مبلغ پرداختی: $format_price_cart تومان
";
        Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
        return;
    }
    if (!DirectPaymentbot($order_id)) {
        telegram('answerCallbackQuery', [
            'callback_query_id' => $callback_query_id,
            'text' => 'ثبت پرداخت ناموفق بود. دوباره تلاش کنید.',
            'show_alert' => true,
        ]);
        return;
    }
    $Payment_report['price'] = number_format($Payment_report['price']);
    $text_report = "📣 نماینده رسیبد پرداخت کارت به کارت را تایید کرد.
        
اطلاعات :
👤آیدی عددی  ادمین تایید کننده : $from_id
💰 مبلغ پرداخت : {$Payment_report['price']}
👤 ایدی عددی کاربر : <code>{$Payment_report['id_user']}</code>
👤 نام کاربری کاربر : @{$Balance_id['username']} 
        کد پیگیری پرداحت : $order_id";
    if (strlen($settingmain['Channel_Report']) > 0) {
        telegram('sendmessage', [
            'chat_id' => $settingmain['Channel_Report'],
            'message_thread_id' => $paymentreports,
            'text' => $text_report,
            'parse_mode' => "HTML"
        ]);
    }
    update("Payment_report", "payment_Status", "paid", "id_order", $Payment_report['id_order']);
    update("user", "Processing_value_one", "none", "id", $Balance_id['id']);
    update("user", "Processing_value_tow", "none", "id", $Balance_id['id']);
    update("user", "Processing_value_four", "none", "id", $Balance_id['id']);
} elseif (preg_match('/reject_pay_(\w+)/', $datain, $datagetr)) {
    $id_order = $datagetr[1];
    $Payment_report = select("Payment_report", "*", "id_order", $id_order, "select");
    if ($Payment_report == false) {
        telegram('answerCallbackQuery', array(
            'callback_query_id' => $callback_query_id,
            'text' => "تراکنش حذف شده است",
            'show_alert' => true,
            'cache_time' => 5,
        ));
        return;
    }
    if (!hash_equals((string) $ApiToken, (string) ($Payment_report['bottype'] ?? ''))
        || !in_array((string) ($Payment_report['Payment_Method'] ?? ''), ['cart to cart', 'arze digital offline'], true)) {
        telegram('answerCallbackQuery', [
            'callback_query_id' => $callback_query_id,
            'text' => 'این تراکنش متعلق به این ربات نیست.',
            'show_alert' => true,
        ]);
        return;
    }
    update("user", "Processing_value", $Payment_report['id_user'], "id", $from_id);
    update("user", "Processing_value_one", $id_order, "id", $from_id);
    if ($Payment_report['payment_Status'] == "reject" || $Payment_report['payment_Status'] == "paid") {
        telegram('answerCallbackQuery', array(
            'callback_query_id' => $callback_query_id,
            'text' => $textbotlang['Admin']['Payment']['reviewedpayment'],
            'show_alert' => true,
            'cache_time' => 5,
        ));
        return;
    }
    update("Payment_report", "payment_Status", "reject", "id_order", $id_order);

    sendmessage($from_id, $textbotlang['Admin']['Payment']['Reasonrejecting'], $backadmin, 'HTML');
    step('reject-dec', $from_id);
    Editmessagetext($from_id, $message_id, $text_inline, null);
} elseif ($user['step'] == "reject-dec") {
    $Payment_report = select("Payment_report", "*", "id_order", $user['Processing_value_one'], "select");
    if (!$Payment_report
        || !hash_equals((string) $ApiToken, (string) ($Payment_report['bottype'] ?? ''))
        || !in_array((string) ($Payment_report['Payment_Method'] ?? ''), ['cart to cart', 'arze digital offline'], true)) {
        sendmessage($from_id, '❌ درخواست رد پرداخت معتبر نیست.', $keyboardadmin, 'HTML');
        step('home', $from_id);
        return;
    }
    $rejectReason = trim(strip_tags((string) $text));
    if ($rejectReason === '' || mb_strlen($rejectReason, 'UTF-8') > 1000) {
        sendmessage($from_id, '❌ دلیل رد باید بین ۱ تا ۱۰۰۰ کاراکتر باشد.', $backadmin, 'HTML');
        return;
    }
    $safeRejectReason = htmlspecialchars($rejectReason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    update("Payment_report", "dec_not_confirmed", $rejectReason, "id_order", $user['Processing_value_one']);
    $text_reject = "❌ کاربر گرامی پرداخت شما به دلیل زیر رد گردید.
✍️ $safeRejectReason
🛒 کد پیگیری پرداخت: {$user['Processing_value_one']}
                ";
    sendmessage($from_id, $textbotlang['Admin']['Payment']['Rejected'], $keyboardadmin, 'HTML');
    sendmessage($user['Processing_value'], $text_reject, null, 'HTML');
    step('home', $from_id);
    $text_report = "❌ یک ادمین رسید پرداخت کارت به کارت را رد کرد.
        
اطلاعات :
👤آیدی عددی  ادمین تایید کننده : $from_id
نام کاربری ادمین تایید کننده : @$username
💰 مبلغ پرداخت : {$Payment_report['price']}
دلیل رد کردن : $safeRejectReason
👤 ایدی عددی کاربر: {$Payment_report['id_user']}";
    if (strlen($settingmain['Channel_Report']) > 0) {
        telegram('sendmessage', [
            'chat_id' => $settingmain['Channel_Report'],
            'message_thread_id' => $paymentreports,
            'text' => $text_report,
            'parse_mode' => "HTML"
        ]);
    }
} elseif ($text == "👨‍🔧  مدیریت ادمین ها") {
    $keyboardadmin = ['inline_keyboard' => []];
    foreach ($admin_ids as $admin) {
        $keyboardadmin['inline_keyboard'][] = [
            ['text' => "❌", 'callback_data' => "removeadmin_" . $admin],
            ['text' => $admin, 'callback_data' => "adminlist"],
        ];
    }
    $keyboardadmin['inline_keyboard'][] = [
        ['text' => "👨‍💻 اضافه کردن ادمین", 'callback_data' => "addnewadmin"],
    ];
    $keyboardadmin = json_encode($keyboardadmin);
    sendmessage($from_id, "📌 در بخش زیر می توانید لیست ادمین ها را مشاهده کنید همچنین با زدن دکمه ضربدر می توانید یک ادمین را حذف کنید", $keyboardadmin, 'HTML');
} elseif ($datain == "addnewadmin") {
    sendmessage($from_id, $textbotlang['Admin']['manageadmin']['getid'], $backadmin, 'HTML');
    step('addadmin', $from_id);
} elseif ($user['step'] == "addadmin") {
    if (!ctype_digit($text)) {
        sendmessage($from_id, $textbotlang['Admin']['agent']['invalidvlue'], $backadmin, 'HTML');
        return;
    }
    sendmessage($from_id, $textbotlang['Admin']['manageadmin']['addadminset'], $keyboardadmin, 'HTML');
    sendmessage($text, $textbotlang['Admin']['manageadmin']['adminedsenduser'], null, 'HTML');
    step('home', $from_id);
    $admin_ids[] = (string) $text;
    $admin_ids = array_values(array_unique($admin_ids));
    update("botsaz", "admin_ids", json_encode($admin_ids), "bot_token", $ApiToken);
} elseif (preg_match('/removeadmin_(\w+)/', $datain, $dataget)) {
    $idadmin = $dataget[1];
    $count = 0;
    foreach ($admin_ids as $admin) {
        if ($admin == $idadmin) {
            unset($admin_ids[$count]);
            break;
        }
        $count += 1;
    }
    unset($admin_ids[$idadmin]);
    $admin_ids = array_values($admin_ids);
    update("botsaz", "admin_ids", json_encode($admin_ids), "bot_token", $ApiToken);
    sendmessage($from_id, "✅ ادمین با موفقیت حذف گردید", null, 'HTML');
} elseif ($text == "🔍 جستجو کاربر") {
    sendmessage($from_id, $textbotlang['Admin']['ManageUser']['GetIdUserunblock'], $backadmin, 'HTML');
    step('show_info', $from_id);
} elseif ($user['step'] == "show_info" || strpos($text, "/user ") !== false) {
    if (explode(" ", $text)[0] == "/user") {
        $id_user = explode(" ", $text)[1];
    } else {
        $id_user = $text;
    }
    if (!in_array($id_user, $users_ids)) {
        sendmessage($from_id, $textbotlang['Admin']['not-user'], null, 'HTML');
        return;
    }
    $date = date("Y-m-d");
    $dayListSell = mysqli_fetch_assoc(mysqli_query($connect, "SELECT COUNT(*) FROM invoice WHERE (status = 'active' OR status = 'end_of_time'  OR status = 'end_of_volume' OR status = 'sendedwarn' OR Status = 'send_on_hold') AND id_user = '$id_user' AND bottype = '$ApiToken'"));
    $balanceall = mysqli_fetch_assoc(mysqli_query($connect, "SELECT SUM(price) FROM Payment_report WHERE payment_Status = 'paid' AND id_user = '$id_user' AND Payment_Method != 'low balance by admin' AND bottype = '$ApiToken'"));
    $subbuyuser = mysqli_fetch_assoc(mysqli_query($connect, "SELECT SUM(price_product) FROM invoice WHERE (status = 'active' OR status = 'end_of_time'  OR status = 'end_of_volume' OR status = 'sendedwarn' OR Status = 'send_on_hold') AND id_user = '$id_user' AND bottype = '$ApiToken'"));
    $invoicecount = mysqli_fetch_assoc(mysqli_query($connect, "SELECT count(*) FROM invoice WHERE (status = 'active' OR status = 'end_of_time'  OR status = 'end_of_volume' OR status = 'sendedwarn' OR Status = 'send_on_hold') AND id_user = '$id_user' AND bottype = '$ApiToken'"))['count(*)'];
    if ($invoicecount == 0) {
        $sumvolume['SUM(Volume)'] = 0;
    } else {
        $sumvolume = mysqli_fetch_assoc(mysqli_query($connect, "SELECT SUM(Volume) FROM invoice WHERE (status = 'active' OR status = 'end_of_time'  OR status = 'end_of_volume' OR status = 'sendedwarn' OR Status = 'send_on_hold') AND id_user = '$id_user' AND name_product != 'سرویس تست'"));
    }
    $user = select("user", "*", "id", $id_user, "select");
    $roll_Status = [
        '1' => $textbotlang['Admin']['ManageUser']['Acceptedphone'],
        '0' => $textbotlang['Admin']['ManageUser']['Failedphone'],
    ][$user['roll_Status']];
    if ($subbuyuser['SUM(price_product)'] == null)
        $subbuyuser['SUM(price_product)'] = 0;
    $user['Balance'] = number_format($user['Balance']);
    if ($user['register'] != "none") {
        if ($user['register'] == null)
            return;
        $userjoin = jdate('Y/m/d H:i:s', $user['register']);
    } else {
        $userjoin = "نامشخص";
    }
    if ($user['last_message_time'] == null) {
        $lastmessage = "";
    } else {
        $lastmessage = jdate('Y/m/d H:i:s', $user['last_message_time']);
    }
    $datefirst = time() - 86400;
    $desired_date_time_start = time() - 3600;
    $month_date_time_start = time() - 2592000;
    $sql = "SELECT * FROM invoice WHERE time_sell > :requestedDate AND (status = 'active' OR status = 'end_of_time'  OR status = 'end_of_volume' OR status = 'sendedwarn' OR Status = 'send_on_hold') AND name_product != 'سرویس تست' AND id_user = :id_user AND bottype = '$ApiToken'";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':id_user', $id_user);
    $stmt->bindParam(':requestedDate', $desired_date_time_start);
    $stmt->execute();
    $listhours = $stmt->rowCount();
    $sql = "SELECT SUM(price_product) FROM invoice WHERE time_sell > :requestedDate AND (Status = 'active' OR Status = 'end_of_time'  OR Status = 'end_of_volume' OR status = 'sendedwarn' OR Status = 'send_on_hold') AND name_product != 'سرویس تست' AND id_user = :id_user AND bottype = '$ApiToken'";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':id_user', $id_user);
    $stmt->bindParam(':requestedDate', $desired_date_time_start);
    $stmt->execute();
    $suminvoicehours = $stmt->fetchColumn();
    if ($suminvoicehours == null) {
        $suminvoicehours = "0";
    }
    $sql = "SELECT * FROM invoice WHERE time_sell > :requestedDate AND (status = 'active' OR status = 'end_of_time'  OR status = 'end_of_volume' OR status = 'sendedwarn' OR Status = 'send_on_hold') AND name_product != 'سرویس تست' AND id_user = :id_user AND bottype = '$ApiToken'";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':id_user', $id_user);
    $stmt->bindParam(':requestedDate', $month_date_time_start);
    $stmt->execute();
    $listmonth = $stmt->rowCount();
    $sql = "SELECT SUM(price_product) FROM invoice WHERE time_sell > :requestedDate AND (Status = 'active' OR Status = 'end_of_time'  OR Status = 'end_of_volume' OR status = 'sendedwarn' OR Status = 'send_on_hold') AND name_product != 'سرویس تست' AND id_user = :id_user AND bottype = '$ApiToken'";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':id_user', $id_user);
    $stmt->bindParam(':requestedDate', $month_date_time_start);
    $stmt->execute();
    $suminvoicemonth = $stmt->fetchColumn();
    $keyboardmanage = json_encode([
        'inline_keyboard' => [
            [
                ['text' => "افزایش موجودی", 'callback_data' => 'addbalanceuser_' . $text],
                ['text' => "کم کردن موجودی", 'callback_data' => 'lowbalanceuser_' . $text],
            ],
        ]
    ]);
    $userbalance = number_format(json_decode(file_get_contents("data/$id_user/$id_user.json"), true)['Balance']);
    if ($suminvoicemonth == null) {
        $suminvoicemonth = "0";
    }
    $textinfouser = "👀 اطلاعات کاربر:

🔗 اطلاعات کاربری کاربر

⭕️ وضعیت کاربر : {$user['User_Status']}
⭕️ نام کاربری کاربر : @{$user['username']}
⭕️ آیدی عددی کاربر :  <a href = \"tg://user?id=$id_user\">$id_user</a>
⭕️ زمان عضویت کاربر : $userjoin
⭕️ آخرین زمان  استفاده کاربر از ربات : $lastmessage
⭕️ محدودیت اکانت تست :  {$user['limit_usertest']} 
⭕️  مجموع حجم خریداری شده فعال ( برای آمار دقیق حجم باید کرون روشن باشد): {$sumvolume['SUM(Volume)']}

💎 گزارشات مالی

🔰 موجودی کاربر : $userbalance
🔰 تعداد خرید کل کاربر : {$dayListSell['COUNT(*)']}
🔰️ مبلغ کل پرداختی  :  {$balanceall['SUM(price)']}
🔰 جمع کل خرید : {$subbuyuser['SUM(price_product)']}
🔰 تعداد فروش یک ساعت گذشته : $listhours عدد
🔰 مجموع فروش یک ساعت گذشته : $suminvoicehours تومان
🔰 تعداد فروش یک ماه گذشته : $listmonth عدد
🔰 مجموع فروش یک ماه گذشته : $suminvoicemonth تومان
";
    sendmessage($from_id, $textinfouser, $keyboardmanage, 'HTML');
    sendmessage($from_id, $textbotlang['users']['selectoption'], $keyboardadmin, 'HTML');
    step('home', $from_id);
} elseif (preg_match('/addbalanceuser_(\w+)/', $datain, $dataget)) {
    $iduser = $dataget[1];
    update("user", "Processing_value", $iduser, "id", $from_id);
    sendmessage($from_id, $textbotlang['Admin']['ManageUser']['addbalanceuserdec'], $backadmin, 'html');
    step('addbalanceusercurrent', $from_id);
} elseif ($user['step'] == "addbalanceusercurrent") {
    if (!ctype_digit($text)) {
        sendmessage($from_id, $textbotlang['Admin']['Balance']['Invalidprice'], $backadmin, 'HTML');
        return;
    }
    if ($text > 100000000) {
        sendmessage($from_id, "❌ حداکثر مبلغ 100 میلیون تومان می باشد", $backadmin, 'HTML');
        return;
    }
    $dateacc = date('Y/m/d H:i:s');
    $randomString = bin2hex(random_bytes(5));
    $stmt = $connect->prepare("INSERT INTO Payment_report (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice,bottype) VALUES (?,?,?,?,?,?,?,?)");
    $payment_Status = "paid";
    $Payment_Method = "add balance by admin";
    $invoice = null;
    $stmt->bind_param("ssssssss", $user['Processing_value'], $randomString, $dateacc, $text, $payment_Status, $Payment_Method, $invoice, $ApiToken);
    $stmt->execute();
    sendmessage($from_id, $textbotlang['Admin']['ManageUser']['addbalanced'], $keyboardadmin, 'html');
    $userbalance = json_decode(file_get_contents("data/{$user['Processing_value']}/{$user['Processing_value']}.json"), true);
    $Balance_add_user = $userbalance['Balance'] + $text;
    $userbalance['Balance'] = $Balance_add_user;
    file_put_contents("data/{$user['Processing_value']}/{$user['Processing_value']}.json", json_encode($userbalance));
    $heibalanceuser = number_format($text, 0);
    $textadd = "💎 کاربر عزیز مبلغ $heibalanceuser تومان به موجودی کیف پول تان اضافه گردید.";
    sendmessage($user['Processing_value'], $textadd, null, 'HTML');
    step('home', $from_id);
} elseif (preg_match('/lowbalanceuser_(\w+)/', $datain, $dataget)) {
    $iduser = $dataget[1];
    update("user", "Processing_value", $iduser, "id", $from_id);
    sendmessage($from_id, $textbotlang['Admin']['ManageUser']['lowbalanceuserdec'], $backadmin, 'html');
    step('addbalanceuser', $from_id);
} elseif ($user['step'] == "addbalanceuser") {
    if (!ctype_digit($text)) {
        sendmessage($from_id, $textbotlang['Admin']['Balance']['Invalidprice'], $backadmin, 'HTML');
        return;
    }
    if ($text > 100000000) {
        sendmessage($from_id, "❌ حداکثر مبلغ 100 میلیون تومان می باشد", $backadmin, 'HTML');
        return;
    }
    $dateacc = date('Y/m/d H:i:s');
    $randomString = bin2hex(random_bytes(5));
    $stmt = $connect->prepare("INSERT INTO Payment_report (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice,bottype) VALUES (?,?,?,?,?,?,?,?)");
    $payment_Status = "paid";
    $Payment_Method = "low balance by admin";
    $invoice = null;
    $stmt->bind_param("ssssssss", $user['Processing_value'], $randomString, $dateacc, $text, $payment_Status, $Payment_Method, $invoice, $ApiToken);
    $stmt->execute();
    sendmessage($from_id, $textbotlang['Admin']['ManageUser']['lowbalanced'], $keyboardadmin, 'html');
    $userbalance = json_decode(file_get_contents("data/{$user['Processing_value']}/{$user['Processing_value']}.json"), true);
    $Balance_add_user = intval($userbalance['Balance']) - intval($text);
    $userbalance['Balance'] = $Balance_add_user;
    file_put_contents("data/{$user['Processing_value']}/{$user['Processing_value']}.json", json_encode($userbalance));
    $lowbalanceuser = number_format($text, 0);
    $textkam = "❌ کاربر عزیز مبلغ $lowbalanceuser تومان از  موجودی کیف پول تان کسر گردید.";
    sendmessage($user['Processing_value'], $textkam, null, 'HTML');
    step('home', $from_id);
    $statistics = select("user", "*", "bottype", $ApiToken, "count");
    $Balance_user_afters = number_format(select("user", "*", "id", $user['Processing_value'], "select")['Balance']);
} elseif ($text == "📊 آمار ربات") {
    $statistics = select("user", "*", "bottype", $ApiToken, "count");
    $testStatement = $pdo->prepare("SELECT COUNT(*) FROM invoice WHERE name_product = 'سرویس تست' AND bottype = :bot_token");
    $testStatement->execute([':bot_token' => $ApiToken]);
    $count_usertest = (int) $testStatement->fetchColumn();
    $salesStatement = $pdo->prepare(
        "SELECT COUNT(DISTINCT id_user) AS buyer_count,
                COUNT(*) AS invoice_count,
                COALESCE(SUM(price_product), 0) AS total_price
         FROM invoice
         WHERE status IN ('active', 'end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold')
           AND name_product != 'سرویس تست'
           AND bottype = :bot_token"
    );
    $salesStatement->execute([':bot_token' => $ApiToken]);
    $salesStats = $salesStatement->fetch(PDO::FETCH_ASSOC);
    $statisticsorder = (int) ($salesStats['buyer_count'] ?? 0);
    $invoice = (int) ($salesStats['invoice_count'] ?? 0);
    $invoicesum = number_format((int) ($salesStats['total_price'] ?? 0));
    $paymentStatement = $pdo->prepare(
        "SELECT Payment_Method, COUNT(*) AS payment_count, COALESCE(SUM(price), 0) AS payment_total
         FROM Payment_report
         WHERE payment_Status = 'paid'
           AND bottype = :bot_token
           AND Payment_Method IN ('cart to cart', 'zarinpal', 'aqayepardakht', 'nowpayment')
         GROUP BY Payment_Method"
    );
    $paymentStatement->execute([':bot_token' => $ApiToken]);
    $paymentRows = $paymentStatement->fetchAll(PDO::FETCH_ASSOC);
    $paymentTitles = [
        'cart to cart' => 'کارت‌به‌کارت',
        'zarinpal' => 'زرین‌پال',
        'aqayepardakht' => 'آقای پرداخت',
        'nowpayment' => 'NOWPayments',
    ];
    $paymentTotal = 0;
    $paymentCount = 0;
    $paymentDetails = '';
    foreach ($paymentRows as $paymentRow) {
        $paymentTotal += (int) $paymentRow['payment_total'];
        $paymentCount += (int) $paymentRow['payment_count'];
        $paymentTitle = $paymentTitles[$paymentRow['Payment_Method']] ?? $paymentRow['Payment_Method'];
        $paymentDetails .= "\n• {$paymentTitle}: " . number_format((int) $paymentRow['payment_total'])
            . ' تومان (' . number_format((int) $paymentRow['payment_count']) . ' تراکنش)';
    }
    if ($paymentDetails === '') {
        $paymentDetails = "\n• هنوز پرداخت موفقی ثبت نشده است.";
    }
    $statisticsall = "
📊 آمار کلی ربات  

📌 تعداد کاربران : $statistics نفر
📌 تعداد کاربرانی که خرید داشتند : $statisticsorder نفر
📌 تعداد اکانت های تست گرفته شده : $count_usertest نفر
📌 تعداد فروش کل : $invoice عدد
📌 جمع فروش کل : $invoicesum تومان

💳 پرداخت‌های کیف پول: " . number_format($paymentCount) . " تراکنش
💰 مجموع واریزی کیف پول: " . number_format($paymentTotal) . " تومان
{$paymentDetails}
";
    sendmessage($from_id, $statisticsall, null, 'HTML');
} elseif ($text == "💰 تنظیم قیمت محصول") {
    if (!is_file('product.json')) {
        file_put_contents('product.json', "{}");
    }
    $product = [];
    $getdataproduct = mysqli_query($connect, "SELECT * FROM product WHERE agent = '{$userbot['agent']}'");
    while ($row = mysqli_fetch_assoc($getdataproduct)) {
        $panel = select("marzban_panel", "*", "name_panel", $row['Location'], "select");
        if (in_array($panel['name_panel'], $hide_panel))
            continue;
        $product[] = [$row['name_product']];
    }
    $list_product = [
        'keyboard' => [],
        'resize_keyboard' => true,
    ];
    $list_product['keyboard'][] = [
        ['text' => "بازگشت به منوی ادمین"],
    ];
    foreach ($product as $button) {
        $list_product['keyboard'][] = [
            ['text' => $button[0]]
        ];
    }
    $json_list_product_list_admin = json_encode($list_product);
    sendmessage($from_id, "از لیست زیر محصولی که می خواهید قیمت تنظیم نمایید را انتخاب کنید", $json_list_product_list_admin, 'HTML');
    step("selectproductprice", $from_id);
} elseif ($user['step'] == "selectproductprice") {
    $product = select("product", "*", "name_product", $text, "select");
    if ($product == false) {
        sendmessage($from_id, "❌ محصول انتخابی وجود ندارد.", null, 'HTML');
        return;
    }
    savedata("clear", "code_product", $product['code_product']);
    step("getpriceproduct", $from_id);
    if (intval($userbot['pricediscount']) != 0) {
        $resultper = ($product['price_product'] * $userbot['pricediscount']) / 100;
        $product['price_product'] = $product['price_product'] - $resultper;
    }
    sendmessage($from_id, "📌  قیمت خود را ارسال کنید
قیمت پایه :{$product['price_product']}", $backadmin, 'HTML');
} elseif ($user['step'] == "getpriceproduct") {
    $userdata = json_decode($user['Processing_value'], true);
    $product = select("product", "*", "code_product", $userdata['code_product'], "select");
    if (!ctype_digit($text)) {
        sendmessage($from_id, $textbotlang['Admin']['agent']['invalidvlue'], null, 'HTML');
        return;
    }
    if (intval($text) < intval($product['price_product'])) {
        sendmessage($from_id, "❌ قیمت شما کوچیک تر از قیمت پایه است.", null, 'HTML');
        return;
    }
    $productlist = json_decode(file_get_contents('product.json'), true);
    $productlist[$product['code_product']] = intval($text);
    file_put_contents('product.json', json_encode($productlist));
    step("home", $from_id);
    sendmessage($from_id, "✅ قیمت با موفقیت تنظیم گردید.", $keyboardprice, 'HTML');
} elseif ($text == "💰 تنظیمات فروشگاه") {
    sendmessage($from_id, "📌 یک گزینه را انتخاب کنید.", $keyboardprice, 'HTML');
} elseif ($text == "⚙️ وضعیت قابلیت ها") {
    $status_custom = [
        '1' => $textbotlang['Admin']['Status']['statuson'],
        '0' => $textbotlang['Admin']['Status']['statusoff']
    ][$setting['show_product']];
    $status_note = [
        '1' => $textbotlang['Admin']['Status']['statuson'],
        '0' => $textbotlang['Admin']['Status']['statusoff']
    ][$setting['active_step_note']];
    $Bot_Status = json_encode([
        'inline_keyboard' => [
            [
                ['text' => $textbotlang['Admin']['Status']['statussubject'], 'callback_data' => "subjectde"],
                ['text' => $textbotlang['Admin']['Status']['subject'], 'callback_data' => "subject"],
            ],
            [
                ['text' => $status_custom, 'callback_data' => "editstsuts-statusvolume-{$setting['show_product']}"],
                ['text' => "🛍 فروش  حجم دلخواه", 'callback_data' => "statuscustomvolume"],
            ],
            [
                ['text' => $status_note, 'callback_data' => "editstsuts-statusnote-{$setting['active_step_note']}"],
                ['text' => "✏️ یادداشت ", 'callback_data' => "statusnote"],
            ]
        ]
    ]);
    sendmessage($from_id, "در این بخش می توانید قابلیت های زیر را خاموش یا روشن کنید", $Bot_Status, 'HTML');
} elseif (preg_match('/^editstsuts-(.*)-(.*)/', $datain, $dataget)) {
    $type = $dataget[1];
    $value = $dataget[2];
    if ($type == "statusvolume") {
        if ($value == false) {
            $valuenew = true;
        } else {
            $valuenew = false;
        }
        $setting['show_product'] = $valuenew;
        update("botsaz", "setting", json_encode($setting), "bot_token", $ApiToken);
    } elseif ($type == "statusnote") {
        if ($value == false) {
            $valuenew = true;
        } else {
            $valuenew = false;
        }
        $setting['active_step_note'] = $valuenew;
        update("botsaz", "setting", json_encode($setting), "bot_token", $ApiToken);
    }
    $dataBase = select("botsaz", "*", "bot_token", $ApiToken, "select");
    $setting = json_decode($dataBase['setting'], true);
    $status_custom = [
        '1' => $textbotlang['Admin']['Status']['statuson'],
        '0' => $textbotlang['Admin']['Status']['statusoff']
    ][$setting['show_product']];
    $status_note = [
        '1' => $textbotlang['Admin']['Status']['statuson'],
        '0' => $textbotlang['Admin']['Status']['statusoff']
    ][$setting['active_step_note']];
    $Bot_Status = json_encode([
        'inline_keyboard' => [
            [
                ['text' => $textbotlang['Admin']['Status']['statussubject'], 'callback_data' => "subjectde"],
                ['text' => $textbotlang['Admin']['Status']['subject'], 'callback_data' => "subject"],
            ],
            [
                ['text' => $status_custom, 'callback_data' => "editstsuts-statusvolume-{$setting['show_product']}"],
                ['text' => "🛍 فروش  حجم دلخواه", 'callback_data' => "statuscustomvolume"],
            ],
            [
                ['text' => $status_note, 'callback_data' => "editstsuts-statusnote-{$setting['active_step_note']}"],
                ['text' => "✏️ یادداشت ", 'callback_data' => "statusnote"],
            ]
        ]
    ]);
    Editmessagetext($from_id, $message_id, "در این بخش می توانید قابلیت های زیر را خاموش یا روشن کنید", $Bot_Status);
} elseif ($text == "📝 تنظیم متون") {
    sendmessage($from_id, "📌 برای تغییر متن یکی از گزینه های زیر را انتخاب نمایید", $keyboard_change_price, 'HTML');
} elseif ($text == "💎 متن کارت") {
    sendmessage($from_id, "📌 جهت تنظیم متن شماره کارت متن جدید را ارسال نمایید. توضیحات فعلی :", $backadmin, 'HTML');
    sendmessage($from_id, $setting['cart_info'], $backadmin, 'HTML');
    step("getcartinfo", $from_id);
} elseif ($user['step'] == "getcartinfo") {
    sendmessage($from_id, "✅ توضیحات با موفقیت ذخیره گردید.", $keyboard_change_price, 'HTML');
    $setting['cart_info'] = $text;
    update("botsaz", "setting", json_encode($setting), "bot_token", $ApiToken);
    step("home", $from_id);
} elseif ($text == "🛍 دکمه خرید") {
    sendmessage($from_id, "📌 جهت تنظیم متن جدید را ارسال نمایید. توضیحات فعلی :", $backadmin, 'HTML');
    sendmessage($from_id, $text_bot_var['btn_keyboard']['buy'], $backadmin, 'HTML');
    step("gettext_buy", $from_id);
} elseif ($user['step'] == "gettext_buy") {
    sendmessage($from_id, "✅ متن با موفقیت ذخیره گردید.", $keyboard_change_price, 'HTML');
    $text_bot_var['btn_keyboard']['buy'] = $text;
    file_put_contents('text.json', json_encode($text_bot_var, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    step("home", $from_id);
} elseif ($text == "🔑 دکمه تست") {
    sendmessage($from_id, "📌 جهت تنظیم متن جدید را ارسال نمایید. توضیحات فعلی :", $backadmin, 'HTML');
    sendmessage($from_id, $text_bot_var['btn_keyboard']['test'], $backadmin, 'HTML');
    step("gettext_test", $from_id);
} elseif ($user['step'] == "gettext_test") {
    sendmessage($from_id, "✅ متن با موفقیت ذخیره گردید.", $keyboard_change_price, 'HTML');
    $text_bot_var['btn_keyboard']['test'] = $text;
    file_put_contents('text.json', json_encode($text_bot_var, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    step("home", $from_id);
} elseif ($text == "🛒 دکمه سرویس های من") {
    sendmessage($from_id, "📌 جهت تنظیم متن جدید را ارسال نمایید. توضیحات فعلی :", $backadmin, 'HTML');
    sendmessage($from_id, $text_bot_var['btn_keyboard']['my_service'], $backadmin, 'HTML');
    step("gettext_my_service", $from_id);
} elseif ($user['step'] == "gettext_my_service") {
    sendmessage($from_id, "✅ متن با موفقیت ذخیره گردید.", $keyboard_change_price, 'HTML');
    $text_bot_var['btn_keyboard']['my_service'] = $text;
    file_put_contents('text.json', json_encode($text_bot_var, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    step("home", $from_id);
} elseif ($text == "👤 دکمه حساب کاربری") {
    sendmessage($from_id, "📌 جهت تنظیم متن جدید را ارسال نمایید. توضیحات فعلی :", $backadmin, 'HTML');
    sendmessage($from_id, $text_bot_var['btn_keyboard']['wallet'], $backadmin, 'HTML');
    step("gettext_wallet", $from_id);
} elseif ($user['step'] == "gettext_wallet") {
    sendmessage($from_id, "✅ متن با موفقیت ذخیره گردید.", $keyboard_change_price, 'HTML');
    $text_bot_var['btn_keyboard']['wallet'] = $text;
    file_put_contents('text.json', json_encode($text_bot_var, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    step("home", $from_id);
} elseif ($text == "☎️ متن دکمه پشتیبانی") {
    sendmessage($from_id, "📌 جهت تنظیم متن جدید را ارسال نمایید. توضیحات فعلی :", $backadmin, 'HTML');
    sendmessage($from_id, $text_bot_var['btn_keyboard']['support'], $backadmin, 'HTML');
    step("gettext_support", $from_id);
} elseif ($user['step'] == "gettext_support") {
    sendmessage($from_id, "✅ متن با موفقیت ذخیره گردید.", $keyboard_change_price, 'HTML');
    $text_bot_var['btn_keyboard']['support'] = $text;
    file_put_contents('text.json', json_encode($text_bot_var, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    step("home", $from_id);
} elseif ($text == "💸 متن مرحله افزایش موجودی") {
    sendmessage($from_id, "📌 جهت تنظیم متن جدید را ارسال نمایید. توضیحات فعلی :", $backadmin, 'HTML');
    sendmessage($from_id, $text_bot_var['text_account']['add_balance'], $backadmin, 'HTML');
    step("gettext_add_balance", $from_id);
} elseif ($user['step'] == "gettext_add_balance") {
    sendmessage($from_id, "✅ متن با موفقیت ذخیره گردید.", $keyboard_change_price, 'HTML');
    $text_bot_var['text_account']['add_balance'] = $text;
    file_put_contents('text.json', json_encode($text_bot_var, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    step("home", $from_id);
} elseif ($text == "📣 جوین اجباری") {
    sendmessage($from_id, "📌 کانال خود را جهت تنظیم جوین اجباری ارسال کنید
⚠️ ربات باید ادمین کانال باشد در غیراینصورت این قابلیت فعال نخواهد شد
⚠️ نام کاربری کانال باید بدون @ ارسال شود", $backadmin, 'HTML');
    step("get_channel_id", $from_id);
} elseif ($user['step'] == "get_channel_id") {
    sendmessage($from_id, "✅ کانال با موفقیت ذخیره گردید.", $keyboardadmin, 'HTML');
    $setting['channel'] = $text;
    update("botsaz", "setting", json_encode($setting), "bot_token", $ApiToken);
    step("home", $from_id);
} elseif ($text == "✏️ تنظیم نام محصول") {
    if (!is_file('product_name.json')) {
        file_put_contents('product_name.json', "{}");
    }
    $product = [];
    $getdataproduct = mysqli_query($connect, "SELECT * FROM product WHERE agent = '{$userbot['agent']}'");
    while ($row = mysqli_fetch_assoc($getdataproduct)) {
        $panel = select("marzban_panel", "*", "name_panel", $row['Location'], "select");
        if (in_array($panel['name_panel'], $hide_panel))
            continue;
        $product[] = [$row['name_product']];
    }
    $list_product = [
        'keyboard' => [],
        'resize_keyboard' => true,
    ];
    foreach ($product as $button) {
        $list_product['keyboard'][] = [
            ['text' => $button[0]]
        ];
    }
    $list_product['keyboard'][] = [
        ['text' => "بازگشت به منوی ادمین"],
    ];
    $json_list_product_list_admin = json_encode($list_product);
    sendmessage($from_id, "از لیست زیر محصولی که می خواهید نام تنظیم نمایید را انتخاب کنید", $json_list_product_list_admin, 'HTML');
    step("get_product_for_edit_name", $from_id);
} elseif ($user['step'] == "get_product_for_edit_name") {
    $product = select("product", "*", "name_product", $text, "select");
    if ($product == false) {
        sendmessage($from_id, "❌ محصول انتخابی وجود ندارد.", null, 'HTML');
        return;
    }
    savedata("clear", "code_product", $product['code_product']);
    step("get_new_name", $from_id);
    sendmessage($from_id, "📌  نام خود را ارسال کنید", $backadmin, 'HTML');
} elseif ($user['step'] == "get_new_name") {
    $userdata = json_decode($user['Processing_value'], true);
    $product = select("product", "*", "code_product", $userdata['code_product'], "select");
    $productlist = json_decode(file_get_contents('product_name.json'), true);
    $productlist[$product['code_product']] = $text;
    file_put_contents('product_name.json', json_encode($productlist));
    step("home", $from_id);
    sendmessage($from_id, "✅ نام با موفقیت تنظیم گردید.", $keyboardprice, 'HTML');
}
