<?php
// Copy this file to the folder ABOVE public_html on Hostinger and name it cafresco-config.php
// e.g. /home/<user>/domains/<domain>/cafresco-config.php
// Never commit the real file.
return [
    'app_url' => 'https://cafrescocafe.com',
    'app_key' => 'CHANGE-ME-to-a-long-random-string',   // used to hash verification tokens
    'setup_token' => 'CHANGE-ME-once',                   // used once at /setup.html, then blank it
    'timezone' => 'Asia/Manila',
    'debug' => false,

    'db' => [
        'host' => '127.0.0.1',            // on Hostinger the app connects locally
        'port' => 3306,
        'name' => 'u000000000_cafresco',
        'user' => 'u000000000_cafresco',
        'pass' => 'CHANGE-ME',
    ],

    // Payment proofs are stored here, outside the web root. Defaults to ../cafresco-uploads
    // 'upload_dir' => '/home/<user>/domains/<domain>/cafresco-uploads',

    'mail' => [
        'driver' => 'smtp',               // smtp | mail | log
        'host' => 'smtp.hostinger.com',
        'port' => 465,
        'user' => 'no-reply@cafrescocafe.com',
        'pass' => 'CHANGE-ME',
        'from' => 'no-reply@cafrescocafe.com',
        'from_name' => 'Cafresco',
    ],

    'sms' => [
        'driver' => 'none',               // none | semaphore | log
        'api_key' => '',
        'sender' => '',
    ],
];
