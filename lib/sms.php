<?php
declare(strict_types=1);

/** True when an SMS provider is configured, so mobile OTP can be sent. */
function sms_enabled(): bool
{
    return config('sms.driver', 'none') !== 'none';
}

/**
 * Send an SMS. Drivers:
 *  - none: SMS disabled; staff verify mobile numbers manually in the admin panel.
 *  - log:  writes to the upload dir's sms.log (local development).
 *  - semaphore: Semaphore.co (PH), roughly PHP 0.50 per message.
 */
function send_sms(string $mobile, string $message): bool
{
    $driver = config('sms.driver', 'none');
    if ($driver === 'none') return false;
    if ($driver === 'log') {
        file_put_contents(upload_dir() . '/sms.log', date('c') . " $mobile: $message\n", FILE_APPEND);
        return true;
    }
    if ($driver === 'semaphore') {
        $ch = curl_init('https://api.semaphore.co/api/v4/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POSTFIELDS => http_build_query(array_filter([
                'apikey' => config('sms.api_key'),
                'number' => preg_replace('/^\+63/', '0', $mobile),
                'message' => $message,
                'sendername' => config('sms.sender') ?: null,
            ])),
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) error_log("semaphore sms failed ($code): $res");
        return $code === 200;
    }
    return false;
}
