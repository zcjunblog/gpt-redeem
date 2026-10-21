<?php
// Copy to config.local.php and fill in values. Keep it outside public/.
return [
    'provider'=>'mock',
    'app_url'=>'https://recharge.example.com', // Exact origin, no trailing slash.
    'admin_user'=>'admin',
    'admin_hash'=>'REPLACE_WITH_PASSWORD_HASH', // Generate with scripts/password.php.
    'data_dir'=>__DIR__.'/data', // Writable by PHP-FPM for sessions and local files.
    'delay'=>6,
    'orbitcard'=>[
        'base_url'=>'https://orbitcard.cc',
        'plans'=>[
            'plus'=>['type'=>'gpt-plus','codes'=>['chatgptplusplan']],
            // Fill the exact permitted type and upstream planCode before selling Pro cards.
            'pro'=>['type'=>'','codes'=>[]],
        ],
    ],
    'database'=>[
        'driver'=>'mysql',
        'host'=>'127.0.0.1',
        'port'=>3306,
        'name'=>'gpt_redeem',
        'user'=>'gpt_redeem',
        'password'=>'REPLACE_WITH_DATABASE_PASSWORD',
    ],
];
