<?php
declare(strict_types=1);

/** درخواست به سرویس‌های بیرونی (تلگرام، درگاه‌ها، پیامک) با cURL یا در نبود آن با stream */
final class HttpClient
{
    /**
     * @param array{json?:array,form?:array,raw?:string,headers?:array<int,string>,timeout?:int,contentType?:string,accept?:string} $opt
     * @return array{status:int, body:string, headers:array<int,string>}
     */
    public static function send(string $method, string $url, array $opt = []): array
    {
        $timeout = (int) ($opt['timeout'] ?? 20);
        $headers = ['Accept: ' . ($opt['accept'] ?? 'application/json')];
        $body = null;
        if (isset($opt['json'])) {
            $body = json_encode($opt['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        } elseif (isset($opt['form'])) {
            $body = http_build_query($opt['form'], '', '&');
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } elseif (isset($opt['raw'])) {
            $body = (string) $opt['raw'];
            if (!empty($opt['contentType'])) $headers[] = 'Content-Type: ' . $opt['contentType'];
        }
        foreach ($opt['headers'] ?? [] as $h) $headers[] = $h;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $respHeaders = [];
            curl_setopt_array($ch, [
                CURLOPT_HEADERFUNCTION => function ($c, string $line) use (&$respHeaders): int { $respHeaders[] = rtrim($line); return strlen($line); },
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            if (!empty($opt['proxy'])) curl_setopt($ch, CURLOPT_PROXY, (string) $opt['proxy']);   // http://, https://, socks5://, socks5h://
            $res = curl_exec($ch);
            if ($res === false) {
                $err = curl_error($ch);
                curl_close($ch);
                throw new RuntimeException("اتصال برقرار نشد: {$err}");
            }
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return ['status' => $status, 'body' => (string) $res, 'headers' => $respHeaders];
        }

        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $body ?? '',
            'timeout' => $timeout,
            'ignore_errors' => true,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        if ($res === false) throw new RuntimeException('اتصال برقرار نشد.');
        $status = 0;
        foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int) $m[1];
        return ['status' => $status, 'body' => $res, 'headers' => array_map('strval', $http_response_header ?? [])];
    }

    /** سازگار با نسخه قبلی: بدنه JSON اختیاری */
    public static function request(string $method, string $url, ?array $json = null, int $timeout = 20): array
    {
        return self::send($method, $url, ($json !== null ? ['json' => $json] : []) + ['timeout' => $timeout]);
    }

    public static function json(string $method, string $url, ?array $payload = null, int $timeout = 20, array $headers = []): array
    {
        $r = self::send($method, $url, ($payload !== null ? ['json' => $payload] : []) + ['timeout' => $timeout, 'headers' => $headers]);
        $data = json_decode($r['body'], true);
        return is_array($data) ? $data : [];
    }

    /** ارسال فرم x-www-form-urlencoded و خواندن پاسخ JSON */
    public static function form(string $method, string $url, array $fields, int $timeout = 20): array
    {
        $r = self::send($method, $url, ['form' => $fields, 'timeout' => $timeout]);
        $data = json_decode($r['body'], true);
        return is_array($data) ? $data : [];
    }
}
