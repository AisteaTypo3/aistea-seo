<?php
$EM_CONF[$_EXTKEY] = [
    'title'           => 'AIstea SEO',
    'description'     => 'SEO analysis and reporting extension - crawls websites and generates reports similar to Screaming Frog SEO Spider',
    'category'        => 'module',
    'constraints' => [
        'depends' => [
            'typo3' => '14.0.0-14.99.99',
        ],
        'conflicts' => [],
    ],
    'autoload' => [
        'psr-4' => [
            'Aistea\\AisteaSeo\\' => 'Classes',
        ],
    ],
    'state'           => 'stable',
    'uploadfolder'    => 0,
    'createDirs'      => '',
    'clearCacheOnLoad' => 1,
    'author'          => 'Yannick Aister',
    'author_email'    => 'yannick.aister@aistea.me',
    'author_company'  => 'AIstea',
    'version'         => '1.0.0',
];
