<?php

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'tx-hotp-icon' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:mfa_hotp/Resources/Public/Icons/Extension.svg'
    ],
];
