<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'HOTP MFA Provider',
    'description' => 'TYPO3 hmac-based one-time password provider',
    'category' => 'be',
    'author' => 'Oliver Bartsch',
    'author_email' => 'bo@cedev.de',
    'state' => 'stable',
    'clearCacheOnLoad' => true,
    'version' => '1.0.1',
    'constraints' => [
        'depends' => [
            'typo3' => '11.1.0-12.99.99',
        ],
        'conflicts' => [],
        'suggests' => []
    ],
    'autoload' => [
        'psr-4' => [
            'Bo\\Hotp\\' => 'Classes/',
        ]
    ],
];
