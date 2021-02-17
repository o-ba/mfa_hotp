<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Hotp',
    'description' => 'TYPO3 hmac-based one-time password provider',
    'category' => 'be',
    'author' => 'Oliver Bartsch',
    'author_email' => 'bo@cedev.de',
    'state' => 'beta',
    'clearCacheOnLoad' => true,
    'version' => '0.0.1',
    'constraints' => [
        'depends' => [
            'typo3' => '11.1.0-11.99.99',
            'php' => '7.4.0-7.4.99'
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
