<?php

declare(strict_types=1);

return [
    'paths'     => ['docs'],
    'exclude'   => ['docs/node_modules'],
    'bootstrap' => 'vendor/autoload.php',
    'execution' => [
        'timeout'      => 30,
        'memory_limit' => '256M',
    ],
    'output' => [
        'normalize_whitespace' => true,
        'trim_trailing'        => true,
    ],
    'reporters' => [
        'console' => true,
        'json'    => null,
    ],
];
