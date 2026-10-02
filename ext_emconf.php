<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Redirect Lifecycle',
    'description' => 'Automatic lifetime management for TYPO3 redirects',
    'category' => 'be',
    'author' => 'thegass',
    'state' => 'alpha',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'php' => '8.1.0-8.99.99',
            'typo3' => '12.4.0-14.99.99',
            'redirects' => '12.4.0-14.99.99',
            'scheduler' => '12.4.0-14.99.99',
        ],
        'suggests' => [
            'sluggi' => '14.15.0-14.99.99',
        ],
    ],
];
