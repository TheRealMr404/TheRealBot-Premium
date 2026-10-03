<?php

function readJsonFileIfExists($path, $default = [])
{
    if (!is_file($path)) {
        return $default;
    }

    $content = file_get_contents($path);
    if ($content === false || $content === '') {
        return $default;
    }

    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : $default;
}

function DirectPaymentbot($order_id,$image = 'images.jpg'){
    global $ApiToken,$Confirm_pay,$from_id,$message_id;
    $Payment_report = select("Payment_report", "*", "id_order", $order_id,"select");
    if (!$Payment_report
        || empty($Payment_report['bottype'])
        || !hash_equals((string) $ApiToken, (string) $Payment_report['bottype'])
        || !in_array((string) ($Payment_report['Payment_Method'] ?? ''), ['cart to cart', 'arze digital offline'], true)) {
        return false;
    }

    $result = resellerCompleteOnlinePayment($order_id, 'کارت‌به‌کارت');
    if (empty($result['ok'])) {
        return false;
    }

    $Balance_id = select("user", "*", "id", $Payment_report['id_user'],"select");
    $format_price_cart = number_format((int) $Payment_report['price']);
    if (($Payment_report['Payment_Method'] ?? '') === "cart to cart"
        || ($Payment_report['Payment_Method'] ?? '') === "arze digital offline") {
        $textconfrom = "⭕️ یک پرداخت جدید انجام شده است
        افزایش موجودی.
👤 شناسه کاربر: <code>{$Balance_id['id']}</code>
🛒 کد پیگیری پرداخت: {$Payment_report['id_order']}
⚜️ نام کاربری: @{$Balance_id['username']}
💸 مبلغ پرداختی: $format_price_cart تومان
✍️ توضیحات : {$Payment_report['dec_not_confirmed']}";
        Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
    }
    return true;
}
function channel_check($id_channel){
    global $from_id;
        $channel_link = array();
         $response = telegram('getChatMember',[
                'chat_id' => $id_channel,
                'user_id' => $from_id
                ]);
            if($response['ok']){
        if(!in_array($response['result']['status'], ['member', 'creator', 'administrator'])){
                $channel_link[] = $id_channel;
            }
        }
        
        if(count($channel_link) == 0){
            return [];
        }else{
            return $channel_link;
        }
}
