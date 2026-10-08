<?php

function telegramProductsIdentityModeLabel($mode)
{
    return ['none' => 'بدون احراز', 'phone' => 'تأیید شماره', 'full' => 'احراز کامل با تأیید مدیر'][$mode] ?? 'بدون احراز';
}

function telegramProductsIdentityNormalizePhone($value)
{
    $phone = preg_replace('/[\s()\-]/', '', (string) $value);
    return preg_match('/^\+?[0-9]{10,15}$/', $phone) ? $phone : '';
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

function telegramProductsIdentitySatisfied($mode, $identity)
{
    if ($mode === 'none') return true;
    if (!is_array($identity) || empty($identity['phone_verified_at'])) return false;
    if ($mode === 'phone') return true;
    return $mode === 'full' && ($identity['status'] ?? '') === 'approved';
}

function telegramProductsIdentityProduct($productId, $activeOnly = true)
{
    global $pdo;
    if ((string) $productId === 'fg') {
        if (!function_exists('telegramFragmentSetting')) return null;
        return ['id' => 'fg', 'title' => 'استارز و پریمیوم خودکار', 'auth_mode' => telegramFragmentSetting('auth_mode', 'none'), 'is_active' => 1];
    }
    $stmt = $pdo->prepare('SELECT id,title,auth_mode,is_active FROM telegram_products WHERE id=?');
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

function telegramProductsIdentityStatus($product, $identity = null)
{
    global $from_id;
    if ($identity === null) $identity = telegramProductsIdentityGet($from_id);
    $mode = $product['auth_mode'] ?? 'none';
    $isFragment = (string) $product['id'] === 'fg';
    $buyCallback = $isFragment ? 'tgp_fg_home' : 'tgp_buy_' . $product['id'];
    $backCallback = $isFragment ? 'tgp_fg_home' : 'tgp_view_' . $product['id'];
    $status = !empty($identity['phone_verified_at']) ? 'شماره تأیید شده' : 'شماره تأیید نشده';
    if ($mode === 'full' && !empty($identity['phone_verified_at'])) {
        $status = ['pending' => 'در انتظار بررسی مدیر', 'approved' => 'تأیید شده', 'rejected' => 'رد شده؛ امکان ثبت دوباره دارید'][$identity['status'] ?? ''] ?? 'اطلاعات کامل ثبت نشده';
    }
    $text = "<b>احراز هویت خدمات مجازی</b>\n\n";
    $text .= '<b>پلن:</b> ' . telegramProductsEscape($product['title']) . "\n";
    $text .= '<b>نوع احراز:</b> ' . telegramProductsIdentityModeLabel($mode) . "\n";
    $text .= '<b>وضعیت:</b> ' . $status . "\n\n";
    $rows = [];
    if (telegramProductsIdentitySatisfied($mode, $identity)) {
        $text .= 'اکنون می‌توانید خرید را ادامه دهید.';
        $rows[] = [telegramProductsActionButton('ادامه خرید', $buyCallback, 'success', 'success')];
    } elseif ($mode === 'full' && ($identity['status'] ?? '') === 'pending') {
        $text .= 'پس از بررسی مدیر، نتیجه برای شما ارسال می‌شود. تا آن زمان مبلغی کسر نخواهد شد.';
    } else {
        $text .= $mode === 'phone' ? 'برای ادامه، شماره خود را از دکمه اشتراک مخاطب تأیید کنید.' : 'برای ادامه، شماره، نام و تصویر مدرک هویتی شما توسط مدیر بررسی می‌شود. می‌توانید اطلاعات ثبت‌شده را از همین بخش حذف کنید.';
        $rows[] = [telegramProductsActionButton('شروع احراز هویت', 'tgp_identity_start_' . $product['id'], 'primary', 'action')];
    }
    if ($identity) $rows[] = [telegramProductsActionButton('حذف اطلاعات احراز', 'tgp_identity_delete_' . $product['id'], 'danger', 'navigation')];
    $rows[] = [telegramProductsActionButton('بازگشت', $backCallback, 'danger', 'navigation')];
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramProductsIdentityGate(array $product)
{
    global $from_id;
    $mode = $product['auth_mode'] ?? 'none';
    if ($mode === 'none' || telegramProductsIdentitySatisfied($mode, telegramProductsIdentityGet($from_id))) return true;
    telegramProductsIdentityStatus($product);
    return false;
}

function telegramProductsIdentityAskContact($productId)
{
    global $from_id, $user;
    $key = (string) $productId;
    step('tgp_identity_contact_' . $key, $from_id);
    $user['step'] = 'tgp_identity_contact_' . $key;
    $keyboard = ['keyboard' => [[['text' => 'ارسال شماره من', 'request_contact' => true]]], 'resize_keyboard' => true, 'one_time_keyboard' => true];
    sendmessage($from_id, "<b>تأیید شماره</b>\n\nدکمه «ارسال شماره من» را بزنید. شماره تایپی یا مخاطب شخص دیگر پذیرفته نمی‌شود.", json_encode($keyboard, JSON_UNESCAPED_UNICODE), 'HTML');
}

function telegramProductsIdentityHandleUser()
{
    global $pdo, $from_id, $datain, $text, $user, $update, $Chat_type, $photo, $document;
    if (preg_match('/^tgp_identity_(start|delete|deleteconfirm)_(fg|\d+)$/', (string) $datain, $match)) {
        $product = telegramProductsIdentityProduct($match[2]);
        if (!$product) { telegramProductsReply('این پلن دیگر در دسترس نیست.', null); return true; }
        if ($match[1] === 'delete') {
            telegramProductsReply("<b>حذف اطلاعات احراز هویت</b>\n\nاطلاعات از پایگاه داده ربات حذف می‌شود و برای خرید پلن‌های نیازمند احراز باید دوباره مراحل را انجام دهید. پیام‌هایی که قبلاً در چت تلگرام ارسال شده‌اند ممکن است باقی بمانند.", json_encode(['inline_keyboard' => [[telegramProductsActionButton('بله، حذف شود', 'tgp_identity_deleteconfirm_' . $product['id'], 'danger', 'navigation')], [telegramProductsActionButton('انصراف', 'tgp_identity_start_' . $product['id'], 'primary', 'action')]]], JSON_UNESCAPED_UNICODE));
            return true;
        }
        if ($match[1] === 'deleteconfirm') {
            $pdo->prepare('DELETE FROM telegram_product_identity WHERE user_id=?')->execute([(string) $from_id]);
            telegramProductsIdentityClearStep();
            telegramProductsIdentityStatus($product, []);
            return true;
        }
        $identity = telegramProductsIdentityGet($from_id);
        if (telegramProductsIdentitySatisfied($product['auth_mode'], $identity) || ($product['auth_mode'] === 'full' && ($identity['status'] ?? '') === 'pending')) {
            telegramProductsIdentityStatus($product, $identity);
            return true;
        }
        if ($Chat_type !== 'private') { telegramProductsReply('احراز هویت فقط در گفت‌وگوی خصوصی با ربات انجام می‌شود.', null); return true; }
        if (empty($identity['phone_verified_at'])) telegramProductsIdentityAskContact($product['id']);
        else {
            step('tgp_identity_name_' . $product['id'], $from_id);
            $user['step'] = 'tgp_identity_name_' . $product['id'];
            telegramProductsReply("<b>نام و نام خانوادگی</b>\n\nنام قانونی خود را مطابق مدرک هویتی ارسال کنید.", null, false);
        }
        return true;
    }

    $step = (string) ($user['step'] ?? '');
    if (!preg_match('/^tgp_identity_(contact|name|national|photo)_(fg|\d+)$/', $step, $match) || $datain !== '') return false;
    $product = telegramProductsIdentityProduct($match[2]);
    if (!$product || ($product['auth_mode'] ?? 'none') === 'none') {
        telegramProductsIdentityClearStep();
        telegramProductsReply('شرایط احراز این پلن تغییر کرده است. دوباره وارد صفحه پلن شوید.', null, false);
        return true;
    }
    $stage = $match[1];
    if ($stage === 'contact') {
        $contact = $update['message']['contact'] ?? null;
        $phone = is_array($contact) && (string) ($contact['user_id'] ?? '') === (string) $from_id
            ? telegramProductsIdentityNormalizePhone($contact['phone_number'] ?? '') : '';
        if ($phone === '') {
            sendmessage($from_id, 'فقط شماره متعلق به همین حساب تلگرام را با دکمه «ارسال شماره من» بفرستید.', null, 'HTML');
            return true;
        }
        $pdo->prepare("INSERT INTO telegram_product_identity (user_id,phone,phone_verified_at,status) VALUES (?,?,NOW(),'none')
            ON DUPLICATE KEY UPDATE phone=VALUES(phone),phone_verified_at=NOW(),status='none',full_name=NULL,national_id_last4=NULL,document_file_id=NULL,document_kind=NULL,submitted_at=NULL,reviewed_at=NULL,reviewer_id=NULL")
            ->execute([(string) $from_id, $phone]);
        sendmessage($from_id, 'شماره شما تأیید شد.', json_encode(['remove_keyboard' => true]), 'HTML');
        if ($product['auth_mode'] === 'phone') {
            telegramProductsIdentityClearStep();
            telegramProductsIdentityStatus($product);
            return true;
        }
        step('tgp_identity_name_' . $product['id'], $from_id);
        $user['step'] = 'tgp_identity_name_' . $product['id'];
        telegramProductsReply("<b>نام و نام خانوادگی</b>\n\nنام قانونی خود را مطابق مدرک هویتی ارسال کنید.", null, false);
        return true;
    }
    if ($product['auth_mode'] !== 'full' || empty(telegramProductsIdentityGet($from_id)['phone_verified_at'])) {
        telegramProductsIdentityClearStep();
        telegramProductsIdentityStatus($product);
        return true;
    }
    if ($stage === 'name') {
        $name = trim((string) $text);
        if (!preg_match('/^[\p{L}\s\-]{3,100}$/u', $name)) {
            telegramProductsReply('نام و نام خانوادگی را با حروف، بین ۳ تا ۱۰۰ نویسه بفرستید.', null, false);
            return true;
        }
        $pdo->prepare("UPDATE telegram_product_identity SET full_name=?,status='none',document_file_id=NULL,document_kind=NULL WHERE user_id=?")
            ->execute([$name, (string) $from_id]);
        step('tgp_identity_national_' . $product['id'], $from_id);
        $user['step'] = 'tgp_identity_national_' . $product['id'];
        telegramProductsReply("<b>کد ملی</b>\n\nکد ملی ۱۰ رقمی خود را مطابق مدرک ارسال کنید. در پایگاه داده ربات فقط چهار رقم آخر آن نگهداری می‌شود.", null, false);
        return true;
    }
    if ($stage === 'national') {
        $nationalId = strtr(trim((string) $text), ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        if (!telegramProductsIdentityNationalIdValid($nationalId)) {
            telegramProductsReply('کد ملی معتبر نیست. یک کد ۱۰ رقمی صحیح بفرستید.', null, false);
            return true;
        }
        $pdo->prepare('UPDATE telegram_product_identity SET national_id_last4=? WHERE user_id=?')
            ->execute([substr($nationalId, -4), (string) $from_id]);
        $incomingMessageId = (int) ($update['message']['message_id'] ?? 0);
        if ($incomingMessageId > 0) deletemessage($from_id, $incomingMessageId);
        step('tgp_identity_photo_' . $product['id'], $from_id);
        $user['step'] = 'tgp_identity_photo_' . $product['id'];
        telegramProductsReply("<b>تصویر مدرک هویتی</b>\n\nیک عکس خوانا از مدرک متعلق به خودتان بفرستید. تصویر فقط برای بررسی مدیر نگهداری می‌شود.", null, false);
        return true;
    }
    $photoItem = is_array($photo) && $photo ? end($photo) : null;
    $documentItem = is_array($document) && in_array($document['mime_type'] ?? '', ['image/jpeg', 'image/png'], true) ? $document : null;
    $file = is_array($photoItem) ? $photoItem : $documentItem;
    $fileId = is_array($file) ? (string) ($file['file_id'] ?? '') : '';
    if ($fileId === '' || strlen($fileId) > 255 || (int) ($file['file_size'] ?? 0) > 10 * 1024 * 1024) {
        telegramProductsReply('فقط عکس یا فایل تصویری JPG/PNG تا ۱۰ مگابایت بفرستید.', null, false);
        return true;
    }
    $save = $pdo->prepare("UPDATE telegram_product_identity SET document_file_id=?,document_kind=?,status='pending',submitted_at=NOW(),reviewed_at=NULL,reviewer_id=NULL WHERE user_id=? AND full_name IS NOT NULL AND national_id_last4 IS NOT NULL");
    $save->execute([$fileId, is_array($photoItem) ? 'photo' : 'document', (string) $from_id]);
    if ($save->rowCount() !== 1) {
        telegramProductsIdentityClearStep();
        telegramProductsReply('اطلاعات احراز ناقص است. لطفاً دوباره از صفحه پلن شروع کنید.', null, false);
        return true;
    }
    telegramProductsIdentityClearStep();
    global $admin_ids;
    $owner = array_values((array) $admin_ids)[0] ?? null;
    if ($owner) sendmessage($owner, 'درخواست احراز هویت خدمات مجازی برای کاربر <code>' . telegramProductsEscape($from_id) . '</code> ثبت شد.', json_encode(['inline_keyboard' => [[['text' => 'بررسی درخواست', 'callback_data' => 'vsa_identity_view_' . $from_id]]]], JSON_UNESCAPED_UNICODE), 'HTML');
    telegramProductsIdentityStatus($product);
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
    if (!empty($identity['document_file_id'])) $rows[] = [['text' => 'نمایش مدرک', 'callback_data' => 'vsa_identity_doc_' . $userId]];
    $rows[] = [['text' => 'حذف اطلاعات احراز', 'callback_data' => 'vsa_identity_delete_' . $userId]];
    $rows[] = [['text' => 'بازگشت', 'callback_data' => 'vsa_identity_list']];
    virtualServicesAdminReply($text, $rows);
}

function telegramProductsIdentityHandleAdmin()
{
    global $pdo, $datain, $from_id, $user, $text;
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
            [['text' => 'احراز کامل + تأیید مدیر', 'callback_data' => 'vsa_identity_set_' . $m[1] . '_full']],
            [['text' => 'بازگشت', 'callback_data' => 'vsa_product_' . $m[1]]],
        ];
        virtualServicesAdminReply('<b>سطح احراز هویت</b>' . "\n\nپلن: " . telegramProductsEscape($product['title']) . "\nوضعیت فعلی: " . telegramProductsIdentityModeLabel($product['auth_mode']), $rows);
        return true;
    }
    if (preg_match('/^vsa_identity_set_(\d+)_(none|phone|full)$/', $datain, $m)) {
        $pdo->prepare('UPDATE telegram_products SET auth_mode=? WHERE id=?')->execute([$m[2], (int) $m[1]]);
        virtualServicesAdminProduct($m[1]);
        return true;
    }
    if (preg_match('/^vsa_identity_view_(\d+)$/', $datain, $m)) { telegramProductsIdentityAdminView($m[1]); return true; }
    if (preg_match('/^vsa_identity_doc_(\d+)$/', $datain, $m)) {
        $identity = telegramProductsIdentityGet($m[1]);
        if ($identity && !empty($identity['document_file_id'])) {
            $isDocument = ($identity['document_kind'] ?? '') === 'document';
            $result = telegram($isDocument ? 'sendDocument' : 'sendPhoto', ['chat_id' => $from_id, $isDocument ? 'document' : 'photo' => $identity['document_file_id'], 'protect_content' => 'true', 'caption' => 'مدرک کاربر ' . $m[1]]);
            if (empty($result['ok'])) virtualServicesAdminReply('نمایش مدرک ممکن نشد؛ درخواست را تأیید نکنید و از کاربر بخواهید دوباره ثبت کند.', [[['text' => 'بازگشت', 'callback_data' => 'vsa_identity_view_' . $m[1]]]]);
        } else {
            virtualServicesAdminReply('مدرکی برای این کاربر ثبت نشده است.', [[['text' => 'بازگشت', 'callback_data' => 'vsa_identity_view_' . $m[1]]]]);
        }
        return true;
    }
    if (preg_match('/^vsa_identity_approve_(\d+)$/', $datain, $m)) {
        virtualServicesAdminReply("<b>تأیید احراز هویت</b>\n\nآیا تصویر مدرک، نام و چهار رقم آخر کد ملی کاربر <code>" . telegramProductsEscape($m[1]) . '</code> را بررسی کرده‌اید؟', [[['text' => 'بله، تأیید شود', 'callback_data' => 'vsa_identity_approveconfirm_' . $m[1]]], [['text' => 'بازگشت', 'callback_data' => 'vsa_identity_view_' . $m[1]]]]);
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
            $stmt = $pdo->prepare("UPDATE telegram_product_identity SET status=?,reviewed_at=NOW(),reviewer_id=? WHERE user_id=? AND status='pending' AND phone_verified_at IS NOT NULL AND document_file_id IS NOT NULL");
            $stmt->execute([$status, (string) $from_id, $m[2]]);
            if ($stmt->rowCount() === 1) sendmessage($m[2], $status === 'approved' ? 'احراز هویت خدمات مجازی شما تأیید شد. اکنون می‌توانید خرید را ادامه دهید.' : 'درخواست احراز هویت شما تأیید نشد. اطلاعات را بررسی و دوباره ثبت کنید.', null, 'HTML');
        }
        telegramProductsIdentityAdminList();
        return true;
    }
    return true;
}
