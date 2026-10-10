<?php

function telegramProductsIdentityModeLabel($mode)
{
    return ['none' => 'بدون احراز', 'phone' => 'تأیید شماره', 'users' => 'احراز فقط مخصوص کاربران ایرانی', 'full' => 'احراز کامل با تأیید مدیر'][$mode] ?? 'بدون احراز';
}

function telegramProductsIdentityNormalizePhone($value)
{
    $phone = preg_replace('/[\s()\-]/', '', (string) $value);
    if (preg_match('/^989[0-9]{9}$/', $phone)) $phone = '+' . $phone;
    return preg_match('/^\+989[0-9]{9}$/', $phone) ? $phone : '';
}

function telegramProductsIdentityNationalIdValid($value)
{
    $value = (string) $value;
    if (!preg_match('/^[0-9]{10}$/', $value) || preg_match('/^(\d)\1{9}$/', $value)) return false;
    $sum = 0;
    for ($i = 0; $i < 9; $i++) $sum += (int) $value[$i] * (10 - $i);
    $remainder = $sum % 11;
    return (int) $value[9] === ($remainder < 2 ? $remainder : 11 - $remainder);
}

function telegramProductsIdentityGet($userId, $lock = false)
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM telegram_product_identity WHERE user_id=?' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([(string) $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function telegramProductsIdentityModeForAgent($mode, $agent)
{
    return $mode === 'users' ? ((string) $agent === 'f' ? 'phone' : 'none') : $mode;
}

function telegramProductsIdentityEffectiveMode($mode, $userId)
{
    global $pdo;
    if ($mode !== 'users') return $mode;
    $stmt = $pdo->prepare('SELECT agent FROM user WHERE id=?');
    $stmt->execute([(string) $userId]);
    return telegramProductsIdentityModeForAgent($mode, $stmt->fetchColumn() ?: 'f');
}

function telegramProductsIdentitySatisfied($mode, $identity, $userId = null)
{
    global $from_id;
    $mode = telegramProductsIdentityEffectiveMode($mode, $userId ?? $from_id);
    if ($mode === 'none') return true;
    if (!is_array($identity) || empty($identity['phone_verified_at'])) return false;
    if ($mode === 'phone') return (bool) preg_match('/^\+989[0-9]{9}$/', (string) ($identity['phone'] ?? ''));
    return $mode === 'full' && ($identity['status'] ?? '') === 'approved';
}

function telegramProductsIdentityProduct($productId, $activeOnly = true)
{
    global $pdo;
    if ((string) $productId === 'fg') {
        if (!function_exists('telegramFragmentSetting')) return null;
        return ['id' => 'fg', 'title' => 'استارز و پریمیوم خودکار', 'auth_mode' => telegramFragmentSetting('auth_mode', 'none'), 'is_active' => 1];
    }
    $stmt = $pdo->prepare('SELECT id,title,price,auth_mode,is_active FROM telegram_products WHERE id=?');
    $stmt->execute([(int) $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    return $product && (!$activeOnly || (int) $product['is_active'] === 1) ? $product : null;
}

function telegramProductsIdentityClearStep()
{
    global $from_id, $user;
    update('user', 'Processing_value', '0', 'id', $from_id);
    step('home', $from_id);
    $user['Processing_value'] = '0';
    $user['step'] = 'home';
}

function telegramProductsIdentityStepKeyboard($withContact = false)
{
    $rows = [];
    if ($withContact) $rows[] = [['text' => 'ارسال شماره من', 'request_contact' => true]];
    $rows[] = [['text' => 'انصراف']];
    return json_encode(['keyboard' => $rows, 'resize_keyboard' => true], JSON_UNESCAPED_UNICODE);
}

function telegramProductsIdentityText($key, $default)
{
    return telegramProductsSafeCustomText(telegramProductsSetting($key, $default));
}

function telegramProductsIdentityAskStage($stage, $productId)
{
    global $from_id, $user;
    $stages = [
        'card' => [
            'identity_card_photo_text',
            "<b>مرحله ۱ از ۴ - ارسال عکس کارت بانکی</b>\n\nیک عکس واضح از کارت بانکی متعلق به خودتان ارسال کنید. نام صاحب کارت و چهار رقم آخر کارت باید خوانا باشد؛ سایر ارقام را بپوشانید.",
        ],
        'photo' => [
            'identity_commitment_photo_text',
            "<b>مرحله ۲ از ۴ - ارسال فرم تعهد و مدرک</b>\n\nفرم تعهد را مطابق راهنمای مدیریت تکمیل کنید و تصویر واضح آن را همراه مدرک هویتی در یک قاب ارسال کنید.",
        ],
        'name' => [
            'identity_full_name_text',
            "<b>مرحله ۳ از ۴ - نام و نام خانوادگی</b>\n\nنام و نام خانوادگی قانونی خود را دقیقاً مطابق مدرک هویتی ارسال کنید.",
        ],
        'national' => [
            'identity_national_id_text',
            "<b>مرحله ۴ از ۴ - کد ملی</b>\n\nکد ملی ۱۰ رقمی خود را ارسال کنید. برای حفظ حریم خصوصی فقط چهار رقم آخر آن در پایگاه داده ربات نگهداری می‌شود.",
        ],
    ];
    if (!isset($stages[$stage])) return;
    step('tgp_identity_' . $stage . '_' . $productId, $from_id);
    $user['step'] = 'tgp_identity_' . $stage . '_' . $productId;
    telegramProductsReply(telegramProductsIdentityText($stages[$stage][0], $stages[$stage][1]), telegramProductsIdentityStepKeyboard(), false);
}

function telegramProductsIdentityIncomingImage()
{
    global $photo, $document;
    $photoItem = is_array($photo) && $photo ? end($photo) : null;
    $documentItem = is_array($document) && in_array($document['mime_type'] ?? '', ['image/jpeg', 'image/png'], true) ? $document : null;
    $file = is_array($photoItem) ? $photoItem : $documentItem;
    if (!is_array($file)) return null;
    $fileId = (string) ($file['file_id'] ?? '');
    if ($fileId === '' || strlen($fileId) > 255 || (int) ($file['file_size'] ?? 0) > 10 * 1024 * 1024) return null;
    return ['file_id' => $fileId, 'kind' => is_array($photoItem) ? 'photo' : 'document'];
}

function telegramProductsIdentityCancelRequested($value, $backText = '')
{
    $value = trim((string) $value);
    return in_array($value, ['انصراف', '/cancel', '/start', 'start'], true)
        || ($backText !== '' && $value === $backText);
}

function telegramProductsIdentityFinish($message)
{
    global $from_id, $keyboard;
    telegramProductsIdentityClearStep();
    sendmessage($from_id, $message, json_encode(['remove_keyboard' => true]), 'HTML');
    sendmessage($from_id, 'به منوی اصلی برگشتید.', $keyboard, 'HTML');
}

function telegramProductsIdentityReport($userId, $event, $productTitle = '')
{
    global $pdo, $setting;
    try {
        $groupId = (string) ($setting['Channel_Report'] ?? '');
        if ($groupId === '' || $groupId === '0') return false;
        $topicId = telegramProductsEnsureReportTopic('virtualservices_identity', 'احراز هویت خدمات مجازی');
        if ($topicId <= 0) return false;
        $identity = telegramProductsIdentityGet($userId);
        if (!$identity) return false;
        $stmt = $pdo->prepare('SELECT username FROM user WHERE id=?');
        $stmt->execute([(string) $userId]);
        $username = trim((string) $stmt->fetchColumn(), '@');
        $username = preg_match('/^[a-zA-Z][a-zA-Z0-9_]{4,31}$/', $username) ? '@' . $username : 'ثبت نشده';
        $status = ['none' => 'شماره تأیید شد', 'pending' => 'در انتظار بررسی', 'approved' => 'تأیید شد', 'rejected' => 'رد شد', 'deleted' => 'حذف توسط مدیر'][$event] ?? $event;
        $text = "<b>احراز هویت خدمات مجازی</b>\n\n";
        $text .= '<b>رویداد:</b> ' . telegramProductsEscape($status) . "\n";
        if ($productTitle !== '') $text .= '<b>محصول:</b> ' . telegramProductsEscape($productTitle) . "\n";
        $text .= '<b>نام و نام خانوادگی:</b> ' . telegramProductsEscape($identity['full_name'] ?: 'ثبت نشده') . "\n";
        $text .= '<b>آیدی تلگرام:</b> ' . telegramProductsEscape($username) . "\n";
        $text .= '<b>آیدی عددی:</b> <a href="tg://user?id=' . telegramProductsEscape($userId) . '">' . telegramProductsEscape($userId) . "</a>\n";
        $text .= '<b>شماره تأییدشده:</b> <code>' . telegramProductsEscape($identity['phone'] ?? '') . "</code>\n";
        if (!empty($identity['national_id_last4'])) $text .= '<b>چهار رقم آخر کد ملی:</b> <code>****' . telegramProductsEscape($identity['national_id_last4']) . "</code>\n";
        if (!empty($identity['submitted_at'])) $text .= '<b>زمان ثبت:</b> ' . telegramProductsEscape($identity['submitted_at']) . "\n";
        $target = ['chat_id' => $groupId, 'message_thread_id' => $topicId];
        $result = telegram('sendMessage', $target + ['text' => $text, 'parse_mode' => 'HTML', 'protect_content' => true]);
        if (empty($result['ok'])) {
            $pdo->prepare("UPDATE topicid SET idreport='0' WHERE report='virtualservices_identity'")->execute();
            return false;
        }
        $evidenceSent = true;
        if ($event === 'pending') {
            foreach ([['card_photo_file_id', 'card_photo_kind', 'عکس کارت بانکی'], ['document_file_id', 'document_kind', 'فرم تعهد و مدرک']] as [$fileField, $kindField, $label]) {
                if (empty($identity[$fileField])) continue;
                $isDocument = ($identity[$kindField] ?? '') === 'document';
                $method = $isDocument ? 'sendDocument' : 'sendPhoto';
                $field = $isDocument ? 'document' : 'photo';
                $sent = telegram($method, $target + [$field => $identity[$fileField], 'caption' => $label . ' | کاربر ' . $userId, 'protect_content' => true]);
                if (empty($sent['ok'])) {
                    $evidenceSent = false;
                    error_log('Identity evidence delivery failed: ' . $fileField . ' for user ' . $userId);
                }
            }
        }
        return $evidenceSent;
    } catch (Throwable $e) {
        error_log('Identity topic report failed: ' . $e->getMessage());
        return false;
    }
}

function telegramProductsIdentityCancel()
{
    telegramProductsIdentityFinish('احراز هویت لغو شد. اطلاعات احراز ثبت‌شده شما محفوظ است.');
}

function telegramProductsIdentityStatus($product, $identity = null)
{
    global $from_id;
    if ($identity === null) $identity = telegramProductsIdentityGet($from_id);
    $mode = $product['auth_mode'] ?? 'none';
    $effectiveMode = telegramProductsIdentityEffectiveMode($mode, $from_id);
    $isFragment = (string) $product['id'] === 'fg';
    $buyCallback = $isFragment ? 'tgp_fg_home' : 'tgp_buy_' . $product['id'];
    $backCallback = $isFragment ? 'tgp_fg_home' : 'tgp_view_' . $product['id'];
    $status = $effectiveMode === 'none' ? 'نیازی به احراز نیست' : (!empty($identity['phone_verified_at']) ? 'شماره تأیید شده' : 'شماره تأیید نشده');
    if ($mode === 'full' && !empty($identity['phone_verified_at'])) {
        $status = ['pending' => 'در انتظار بررسی مدیر', 'approved' => 'تأیید شده', 'rejected' => 'رد شده؛ امکان ثبت دوباره دارید'][$identity['status'] ?? ''] ?? 'اطلاعات کامل ثبت نشده';
    }
    $text = "<b>احراز هویت خدمات مجازی</b>\n\n";
    $text .= '<b>پلن:</b> ' . telegramProductsEscape($product['title']) . "\n";
    if (isset($product['price']) && (int) $product['price'] > 0) $text .= '<b>قیمت:</b> ' . telegramProductsMoney($product['price']) . "\n";
    $text .= '<b>نوع احراز:</b> ' . telegramProductsIdentityModeLabel($mode) . "\n";
    $text .= '<b>وضعیت:</b> ' . $status . "\n\n";
    $rows = [];
    if (telegramProductsIdentitySatisfied($mode, $identity)) {
        $text .= 'اکنون می‌توانید خرید را ادامه دهید.';
        $rows[] = [telegramProductsActionButton('ادامه خرید', $buyCallback, 'success', 'success')];
    } elseif ($mode === 'full' && ($identity['status'] ?? '') === 'pending') {
        $text .= 'پس از بررسی مدیر، نتیجه برای شما ارسال می‌شود. تا آن زمان مبلغی کسر نخواهد شد.';
    } else {
        $text .= $effectiveMode === 'phone' ? 'برای ادامه، شماره +98 متعلق به همین حساب را با دکمه اشتراک مخاطب تأیید کنید.' : 'ابتدا شماره +98 خود را تأیید کنید؛ سپس عکس کارت بانکی، فرم تعهد و مدرک، نام و نام خانوادگی و کد ملی را در چهار مرحله ثبت کنید. نتیجه پس از بررسی مدیر اعلام می‌شود.';
        $rows[] = [telegramProductsActionButton('شروع احراز هویت', 'tgp_identity_start_' . $product['id'], 'primary', 'action')];
    }
    $rows[] = [telegramProductsActionButton('بازگشت', $backCallback, 'danger', 'navigation')];
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramProductsIdentityGate(array $product)
{
    global $from_id;
    $mode = $product['auth_mode'] ?? 'none';
    if (telegramProductsIdentityEffectiveMode($mode, $from_id) === 'none' || telegramProductsIdentitySatisfied($mode, telegramProductsIdentityGet($from_id))) return true;
    telegramProductsIdentityStatus($product);
    return false;
}

function telegramProductsIdentityAskContact($productId)
{
    global $from_id, $user;
    $key = (string) $productId;
    step('tgp_identity_contact_' . $key, $from_id);
    $user['step'] = 'tgp_identity_contact_' . $key;
    $text = telegramProductsIdentityText('identity_contact_text', "<b>تأیید شماره همراه</b>\n\nبرای شروع احراز کامل، دکمه «ارسال شماره من» را بزنید. فقط شماره +98 متعلق به همین حساب تلگرام پذیرفته می‌شود.");
    sendmessage($from_id, $text, telegramProductsIdentityStepKeyboard(true), 'HTML');
}

function telegramProductsIdentityHandleUser()
{
    global $pdo, $from_id, $datain, $text, $user, $update, $Chat_type, $photo, $document;
    if (preg_match('/^tgp_identity_(start|cancel|delete|deleteconfirm)_(fg|\d+)$/', (string) $datain, $match)) {
        if ($match[1] === 'cancel') { telegramProductsIdentityCancel(); return true; }
        $product = telegramProductsIdentityProduct($match[2]);
        if (!$product) { telegramProductsReply('این پلن دیگر در دسترس نیست.', null); return true; }
        if ($match[1] !== 'start') { telegramProductsReply('حذف احراز هویت فقط توسط مدیر امکان‌پذیر است.', null); return true; }
        $identity = telegramProductsIdentityGet($from_id);
        $mode = telegramProductsIdentityEffectiveMode($product['auth_mode'], $from_id);
        if (($identity['status'] ?? '') === 'approved' || telegramProductsIdentitySatisfied($product['auth_mode'], $identity) || ($mode === 'full' && ($identity['status'] ?? '') === 'pending')) {
            telegramProductsIdentityStatus($product, $identity);
            return true;
        }
        if ($Chat_type !== 'private') { telegramProductsReply('احراز هویت فقط در گفت‌وگوی خصوصی با ربات انجام می‌شود.', null); return true; }
        if ($mode === 'full') {
            telegramProductsIdentityAskContact($product['id']);
        } else telegramProductsIdentityAskContact($product['id']);
        return true;
    }

    $step = (string) ($user['step'] ?? '');
    if (!preg_match('/^tgp_identity_(contact|card|photo|name|national)_(fg|\d+)$/', $step, $match) || $datain !== '') return false;
    global $textbotlang;
    if (telegramProductsIdentityCancelRequested($text, (string) ($textbotlang['users']['backbtn'] ?? ''))) {
        telegramProductsIdentityCancel();
        return true;
    }
    $product = telegramProductsIdentityProduct($match[2]);
    $mode = $product ? telegramProductsIdentityEffectiveMode($product['auth_mode'] ?? 'none', $from_id) : 'none';
    if (!$product || $mode === 'none') {
        telegramProductsIdentityFinish('شرایط احراز این پلن تغییر کرده است. دوباره وارد صفحه پلن شوید.');
        return true;
    }
    $stage = $match[1];
    $identity = telegramProductsIdentityGet($from_id);
    if ($Chat_type !== 'private') {
        telegramProductsReply('احراز هویت فقط در گفت‌وگوی خصوصی با ربات انجام می‌شود.', null, false);
        return true;
    }
    if (($identity['status'] ?? '') === 'approved' || ($mode === 'full' && ($identity['status'] ?? '') === 'pending')) {
        telegramProductsIdentityFinish('این درخواست قبلاً ثبت یا تأیید شده است.');
        telegramProductsIdentityStatus($product, $identity);
        return true;
    }
    if ($stage === 'contact') {
        $contact = $update['message']['contact'] ?? null;
        $phone = is_array($contact) && (string) ($contact['user_id'] ?? '') === (string) $from_id
            ? telegramProductsIdentityNormalizePhone($contact['phone_number'] ?? '') : '';
        if ($phone === '') {
            sendmessage($from_id, 'فقط شماره +98 متعلق به همین حساب تلگرام را با دکمه «ارسال شماره من» بفرستید.', null, 'HTML');
            return true;
        }
        $pdo->prepare("INSERT IGNORE INTO telegram_product_identity (user_id,status) VALUES (?,'none')")->execute([(string) $from_id]);
        $saveSql = $mode === 'full'
            ? "UPDATE telegram_product_identity SET phone=?,phone_verified_at=NOW(),full_name=NULL,national_id_last4=NULL,card_photo_file_id=NULL,card_photo_kind=NULL,document_file_id=NULL,document_kind=NULL,status='none',submitted_at=NULL,reviewed_at=NULL,reviewer_id=NULL WHERE user_id=? AND status<>'approved'"
            : "UPDATE telegram_product_identity SET phone=?,phone_verified_at=NOW() WHERE user_id=? AND status<>'approved'";
        $savePhone = $pdo->prepare($saveSql);
        $savePhone->execute([$phone, (string) $from_id]);
        if ($savePhone->rowCount() !== 1) {
            telegramProductsIdentityFinish('ثبت شماره کامل نشد. دوباره از صفحه پلن شروع کنید.');
            telegramProductsIdentityStatus($product);
            return true;
        }
        sendmessage($from_id, 'شماره شما تأیید شد.', json_encode(['remove_keyboard' => true]), 'HTML');
        if ($mode === 'phone') {
            telegramProductsIdentityReport($from_id, 'none', $product['title']);
            telegramProductsIdentityClearStep();
            global $keyboard;
            sendmessage($from_id, 'منوی اصلی', $keyboard, 'HTML');
            telegramProductsIdentityStatus($product);
            return true;
        }
        telegramProductsIdentityAskStage('card', $product['id']);
        return true;
    }
    if ($mode !== 'full' || empty($identity['phone_verified_at'])) {
        telegramProductsIdentityFinish('مرحله احراز نیازمند شروع دوباره است.');
        telegramProductsIdentityStatus($product);
        return true;
    }
    if ($stage === 'card' || $stage === 'photo') {
        if ($stage === 'photo' && empty($identity['card_photo_file_id'])) {
            telegramProductsIdentityFinish('مرحله احراز نیازمند شروع دوباره است.');
            telegramProductsIdentityStatus($product);
            return true;
        }
        $image = telegramProductsIdentityIncomingImage();
        if (!$image) {
            telegramProductsReply('فقط عکس یا فایل تصویری JPG/PNG تا ۱۰ مگابایت بفرستید.', null, false);
            return true;
        }
        if ($stage === 'card') {
            $pdo->prepare("UPDATE telegram_product_identity SET card_photo_file_id=?,card_photo_kind=? WHERE user_id=? AND status<>'approved'")
                ->execute([$image['file_id'], $image['kind'], (string) $from_id]);
            telegramProductsIdentityAskStage('photo', $product['id']);
        } else {
            $pdo->prepare("UPDATE telegram_product_identity SET document_file_id=?,document_kind=? WHERE user_id=? AND status<>'approved' AND card_photo_file_id IS NOT NULL")
                ->execute([$image['file_id'], $image['kind'], (string) $from_id]);
            telegramProductsIdentityAskStage('name', $product['id']);
        }
        return true;
    }
    if ($stage === 'name') {
        if (empty($identity['document_file_id'])) {
            telegramProductsIdentityFinish('مرحله احراز نیازمند شروع دوباره است.');
            telegramProductsIdentityStatus($product);
            return true;
        }
        $name = trim((string) $text);
        if (!preg_match('/^[\p{L}\s\-]{3,100}$/u', $name)) {
            telegramProductsReply('نام و نام خانوادگی را با حروف، بین ۳ تا ۱۰۰ نویسه بفرستید.', null, false);
            return true;
        }
        $pdo->prepare("UPDATE telegram_product_identity SET full_name=?,national_id_last4=NULL,status='none',submitted_at=NULL,reviewed_at=NULL,reviewer_id=NULL WHERE user_id=? AND status<>'approved' AND phone_verified_at IS NOT NULL AND card_photo_file_id IS NOT NULL AND document_file_id IS NOT NULL")
            ->execute([$name, (string) $from_id]);
        telegramProductsIdentityAskStage('national', $product['id']);
        return true;
    }
    if ($stage === 'national') {
        $nationalId = strtr(trim((string) $text), ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        if (!telegramProductsIdentityNationalIdValid($nationalId)) {
            telegramProductsReply('کد ملی معتبر نیست. یک کد ۱۰ رقمی صحیح بفرستید.', null, false);
            return true;
        }
        $save = $pdo->prepare("UPDATE telegram_product_identity SET national_id_last4=?,status='pending',submitted_at=NOW(),reviewed_at=NULL,reviewer_id=NULL WHERE user_id=? AND status<>'approved' AND full_name IS NOT NULL AND phone_verified_at IS NOT NULL AND card_photo_file_id IS NOT NULL AND document_file_id IS NOT NULL");
        $save->execute([substr($nationalId, -4), (string) $from_id]);
        $incomingMessageId = (int) ($update['message']['message_id'] ?? 0);
        if ($incomingMessageId > 0) deletemessage($from_id, $incomingMessageId);
        if ($save->rowCount() !== 1) {
            telegramProductsIdentityFinish('اطلاعات احراز ناقص است. لطفاً دوباره از صفحه پلن شروع کنید.');
            return true;
        }
        telegramProductsIdentityFinish('درخواست احراز برای بررسی مدیر ثبت شد.');
        $reported = telegramProductsIdentityReport($from_id, 'pending', $product['title']);
        global $admin_ids;
        $owner = array_values((array) $admin_ids)[0] ?? null;
        if (!$reported && $owner) sendmessage($owner, 'درخواست احراز هویت خدمات مجازی برای کاربر <code>' . telegramProductsEscape($from_id) . '</code> ثبت شد.', json_encode(['inline_keyboard' => [[['text' => 'بررسی درخواست', 'callback_data' => 'vsa_identity_view_' . $from_id]]]], JSON_UNESCAPED_UNICODE), 'HTML');
        telegramProductsIdentityStatus($product);
        return true;
    }
    return true;
}

function telegramProductsIdentityAdminList()
{
    global $pdo;
    $rows = [];
    $stmt = $pdo->query("SELECT user_id,submitted_at FROM telegram_product_identity WHERE status='pending' ORDER BY submitted_at ASC LIMIT 30");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $record) {
        $rows[] = [['text' => 'کاربر ' . $record['user_id'], 'callback_data' => 'vsa_identity_view_' . $record['user_id']]];
    }
    $rows[] = [['text' => 'جستجوی کاربر', 'callback_data' => 'vsa_identity_lookup']];
    $rows[] = [['text' => 'بازگشت', 'callback_data' => 'vsa_section_customers']];
    virtualServicesAdminReply('<b>درخواست‌های احراز هویت</b>' . (count($rows) === 2 ? "\n\nدرخواستی در انتظار بررسی نیست." : ''), $rows);
}

function telegramProductsIdentityAdminTexts()
{
    $items = [
        'contact' => 'تأیید شماره همراه',
        'card' => 'مرحله ۱: عکس کارت بانکی',
        'commitment' => 'مرحله ۲: فرم تعهد و مدرک',
        'name' => 'مرحله ۳: نام و نام خانوادگی',
        'national' => 'مرحله ۴: کد ملی',
    ];
    $rows = [];
    foreach ($items as $key => $label) {
        $rows[] = [['text' => $label, 'callback_data' => 'vsa_identity_text_' . $key]];
    }
    $rows[] = [['text' => 'بازگشت', 'callback_data' => 'vsa_settings']];
    virtualServicesAdminReply("<b>متن‌های احراز هویت کامل</b>\n\nمتن هر مرحله را جداگانه انتخاب و ویرایش کنید. ایموجی معمولی و پریمیوم پشتیبانی می‌شود.", $rows);
}

function telegramProductsIdentityAdminView($userId)
{
    global $from_id;
    $identity = telegramProductsIdentityGet($userId);
    if (!$identity) { telegramProductsIdentityAdminList(); return; }
    $text = "<b>بررسی احراز هویت</b>\n\n";
    $text .= 'کاربر: <code>' . telegramProductsEscape($userId) . "</code>\n";
    $text .= 'نام: ' . telegramProductsEscape($identity['full_name'] ?? '') . "\n";
    $text .= 'شماره: <code>' . telegramProductsEscape($identity['phone'] ?? '') . "</code>\n";
    $text .= 'چهار رقم آخر کد ملی: <code>' . telegramProductsEscape($identity['national_id_last4'] ?? '') . "</code>\n";
    $statusLabels = ['none' => 'اطلاعات ناقص', 'pending' => 'در انتظار بررسی', 'approved' => 'تأیید شده', 'rejected' => 'رد شده'];
    $text .= 'وضعیت: ' . ($statusLabels[$identity['status']] ?? 'نامشخص');
    $rows = [];
    if ($identity['status'] === 'pending') {
        $rows[] = [['text' => 'تأیید پس از بررسی', 'callback_data' => 'vsa_identity_approve_' . $userId], ['text' => 'رد درخواست', 'callback_data' => 'vsa_identity_reject_' . $userId]];
    }
    if (!empty($identity['card_photo_file_id'])) $rows[] = [['text' => 'نمایش عکس کارت بانکی', 'callback_data' => 'vsa_identity_carddoc_' . $userId]];
    if (!empty($identity['document_file_id'])) $rows[] = [['text' => 'نمایش فرم تعهد و مدرک', 'callback_data' => 'vsa_identity_doc_' . $userId]];
    $rows[] = [['text' => 'حذف اطلاعات احراز', 'callback_data' => 'vsa_identity_delete_' . $userId]];
    $rows[] = [['text' => 'بازگشت', 'callback_data' => 'vsa_identity_list']];
    virtualServicesAdminReply($text, $rows);
}

function telegramProductsIdentityHandleAdmin()
{
    global $pdo, $datain, $from_id, $user, $text;
    $textSettings = [
        'contact' => ['identity_contact_text', 'تأیید شماره همراه'],
        'card' => ['identity_card_photo_text', 'مرحله ۱: عکس کارت بانکی'],
        'commitment' => ['identity_commitment_photo_text', 'مرحله ۲: فرم تعهد و مدرک'],
        'name' => ['identity_full_name_text', 'مرحله ۳: نام و نام خانوادگی'],
        'national' => ['identity_national_id_text', 'مرحله ۴: کد ملی'],
    ];
    $currentStep = (string) ($user['step'] ?? '');
    if ($datain === '' && preg_match('/^vsa_identity_text_(contact|card|commitment|name|national)_edit$/', $currentStep, $textMatch)) {
        $value = trim((string) $text);
        if ($value === '' || mb_strlen($value, 'UTF-8') > 3500) {
            sendmessage($from_id, 'متن باید بین ۱ تا ۳۵۰۰ نویسه باشد.', null, 'HTML');
            return true;
        }
        telegramProductsSetSetting($textSettings[$textMatch[1]][0], virtualServicesAdminCustomText());
        virtualServicesAdminClearState();
        sendmessage($from_id, 'متن مرحله با موفقیت ذخیره شد.', null, 'HTML');
        telegramProductsIdentityAdminTexts();
        return true;
    }
    if ($datain === '' && ($user['step'] ?? '') === 'vsa_identity_lookup') {
        $userId = trim((string) $text);
        if (!preg_match('/^\d{1,20}$/', $userId)) {
            virtualServicesAdminReply('شناسه عددی کاربر را ارسال کنید.', [[['text' => 'انصراف', 'callback_data' => 'vsa_identity_list']]], false);
            return true;
        }
        virtualServicesAdminClearState();
        telegramProductsIdentityAdminView($userId);
        return true;
    }
    if (strpos((string) $datain, 'vsa_identity_') !== 0) return false;
    if ($datain === 'vsa_identity_texts') {
        virtualServicesAdminClearState();
        telegramProductsIdentityAdminTexts();
        return true;
    }
    if (preg_match('/^vsa_identity_text_(contact|card|commitment|name|national)$/', $datain, $m)) {
        $item = $textSettings[$m[1]];
        virtualServicesAdminSetState('vsa_identity_text_' . $m[1] . '_edit');
        $current = telegramProductsSetting($item[0], '');
        virtualServicesAdminReply('<b>' . telegramProductsEscape($item[1]) . "</b>\n\nمتن جدید را ارسال کنید.\n\n<b>متن فعلی:</b>\n" . telegramProductsSafeCustomText($current), [[['text' => 'انصراف', 'callback_data' => 'vsa_identity_texts']]], false);
        return true;
    }
    if ($datain === 'vsa_identity_list') { telegramProductsIdentityAdminList(); return true; }
    if ($datain === 'vsa_identity_lookup') {
        virtualServicesAdminSetState('vsa_identity_lookup');
        virtualServicesAdminReply('شناسه عددی کاربر را برای دیدن وضعیت احراز ارسال کنید.', [[['text' => 'انصراف', 'callback_data' => 'vsa_identity_list']]], false);
        return true;
    }
    if (preg_match('/^vsa_identity_mode_(\d+)$/', $datain, $m)) {
        $product = telegramProductsIdentityProduct($m[1], false);
        if (!$product) { virtualServicesAdminProducts(); return true; }
        $rows = [
            [['text' => 'بدون احراز', 'callback_data' => 'vsa_identity_set_' . $m[1] . '_none']],
            [['text' => 'فقط شماره', 'callback_data' => 'vsa_identity_set_' . $m[1] . '_phone']],
            [['text' => 'احراز فقط مخصوص کاربران ایرانی', 'callback_data' => 'vsa_identity_set_' . $m[1] . '_users']],
            [['text' => 'احراز کامل + تأیید مدیر', 'callback_data' => 'vsa_identity_set_' . $m[1] . '_full']],
            [['text' => 'بازگشت', 'callback_data' => 'vsa_product_' . $m[1]]],
        ];
        virtualServicesAdminReply('<b>سطح احراز هویت</b>' . "\n\nپلن: " . telegramProductsEscape($product['title']) . "\nوضعیت فعلی: " . telegramProductsIdentityModeLabel($product['auth_mode']), $rows);
        return true;
    }
    if (preg_match('/^vsa_identity_set_(\d+)_(none|phone|users|full)$/', $datain, $m)) {
        $pdo->prepare('UPDATE telegram_products SET auth_mode=? WHERE id=?')->execute([$m[2], (int) $m[1]]);
        virtualServicesAdminProduct($m[1]);
        return true;
    }
    if (preg_match('/^vsa_identity_view_(\d+)$/', $datain, $m)) { telegramProductsIdentityAdminView($m[1]); return true; }
    if (preg_match('/^vsa_identity_(carddoc|doc)_(\d+)$/', $datain, $m)) {
        $userId = $m[2];
        $identity = telegramProductsIdentityGet($userId);
        $isCard = $m[1] === 'carddoc';
        $fileField = $isCard ? 'card_photo_file_id' : 'document_file_id';
        $kindField = $isCard ? 'card_photo_kind' : 'document_kind';
        if ($identity && !empty($identity[$fileField])) {
            $isDocument = ($identity[$kindField] ?? '') === 'document';
            $caption = ($isCard ? 'کارت بانکی کاربر ' : 'فرم تعهد و مدرک کاربر ') . $userId;
            $result = telegram($isDocument ? 'sendDocument' : 'sendPhoto', ['chat_id' => $from_id, $isDocument ? 'document' : 'photo' => $identity[$fileField], 'protect_content' => 'true', 'caption' => $caption]);
            if (empty($result['ok'])) virtualServicesAdminReply('نمایش تصویر ممکن نشد؛ درخواست را تأیید نکنید و از کاربر بخواهید دوباره ثبت کند.', [[['text' => 'بازگشت', 'callback_data' => 'vsa_identity_view_' . $userId]]]);
        } else {
            virtualServicesAdminReply('تصویری برای این مرحله ثبت نشده است.', [[['text' => 'بازگشت', 'callback_data' => 'vsa_identity_view_' . $userId]]]);
        }
        return true;
    }
    if (preg_match('/^vsa_identity_approve_(\d+)$/', $datain, $m)) {
        virtualServicesAdminReply("<b>تأیید احراز هویت</b>\n\nآیا عکس کارت بانکی، فرم تعهد و مدرک، نام و چهار رقم آخر کد ملی کاربر <code>" . telegramProductsEscape($m[1]) . '</code> را بررسی کرده‌اید؟', [[['text' => 'بله، تأیید شود', 'callback_data' => 'vsa_identity_approveconfirm_' . $m[1]]], [['text' => 'بازگشت', 'callback_data' => 'vsa_identity_view_' . $m[1]]]]);
        return true;
    }
    if (preg_match('/^vsa_identity_delete_(\d+)$/', $datain, $m)) {
        virtualServicesAdminReply("<b>حذف اطلاعات هویتی</b>\n\nاطلاعات کاربر <code>" . telegramProductsEscape($m[1]) . '</code> حذف شود؟', [[['text' => 'بله، حذف شود', 'callback_data' => 'vsa_identity_deleteconfirm_' . $m[1]]], [['text' => 'انصراف', 'callback_data' => 'vsa_identity_view_' . $m[1]]]]);
        return true;
    }
    if (preg_match('/^vsa_identity_(approveconfirm|reject|deleteconfirm)_(\d+)$/', $datain, $m)) {
        if ($m[1] === 'deleteconfirm') {
            $delete = $pdo->prepare('DELETE FROM telegram_product_identity WHERE user_id=?');
            $delete->execute([$m[2]]);
            if ($delete->rowCount() === 1) sendmessage($m[2], 'اطلاعات احراز هویت شما از پایگاه داده ربات حذف شد. برای خرید پلن‌های نیازمند احراز، دوباره درخواست ثبت کنید.', null, 'HTML');
        } else {
            $status = $m[1] === 'approveconfirm' ? 'approved' : 'rejected';
            $stmt = $pdo->prepare("UPDATE telegram_product_identity SET status=?,reviewed_at=NOW(),reviewer_id=? WHERE user_id=? AND status='pending' AND phone_verified_at IS NOT NULL AND full_name IS NOT NULL AND national_id_last4 IS NOT NULL AND document_file_id IS NOT NULL");
            $stmt->execute([$status, (string) $from_id, $m[2]]);
            if ($stmt->rowCount() === 1) {
                sendmessage($m[2], $status === 'approved' ? 'احراز هویت خدمات مجازی شما تأیید شد. اکنون می‌توانید خرید را ادامه دهید.' : 'درخواست احراز هویت شما تأیید نشد. اطلاعات را بررسی و دوباره ثبت کنید.', null, 'HTML');
                telegramProductsIdentityReport($m[2], $status);
            }
        }
        telegramProductsIdentityAdminList();
        return true;
    }
    return true;
}
