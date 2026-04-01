<?php
declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:aistea_seo/Resources/Private/Language/locallang.xlf:tx_aisteaseo_domain_model_report',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'default_sortby' => 'crdate DESC',
        'iconfile' => 'EXT:aistea_seo/Resources/Public/Icons/module-aisteaseo.svg',
        'searchFields' => 'title, base_url',
    ],
    'types' => [
        '1' => [
            'showitem' => 'title, base_url, max_pages, --div--;Status, status, overall_score, pages_crawled, progress_pages, queued_at, started_at, finished_at, last_crawled_url, error_message',
        ],
    ],
    'columns' => [
        'title' => [
            'label' => 'LLL:EXT:aistea_seo/Resources/Private/Language/locallang.xlf:tx_aisteaseo_domain_model_report.title',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'required' => true,
            ],
        ],
        'base_url' => [
            'label' => 'LLL:EXT:aistea_seo/Resources/Private/Language/locallang.xlf:tx_aisteaseo_domain_model_report.base_url',
            'config' => [
                'type' => 'input',
                'size' => 80,
                'max' => 500,
                'required' => true,
                'eval' => 'trim',
            ],
        ],
        'status' => [
            'label' => 'LLL:EXT:aistea_seo/Resources/Private/Language/locallang.xlf:tx_aisteaseo_domain_model_report.status',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'New', 'value' => 0],
                    ['label' => 'Running', 'value' => 1],
                    ['label' => 'Completed', 'value' => 2],
                    ['label' => 'Failed', 'value' => 3],
                    ['label' => 'Queued', 'value' => 4],
                ],
                'default' => 0,
            ],
        ],
        'pages_crawled' => [
            'label' => 'LLL:EXT:aistea_seo/Resources/Private/Language/locallang.xlf:tx_aisteaseo_domain_model_report.pages_crawled',
            'config' => [
                'type' => 'number',
                'size' => 10,
                'default' => 0,
                'readOnly' => true,
            ],
        ],
        'progress_pages' => [
            'label' => 'LLL:EXT:aistea_seo/Resources/Private/Language/locallang.xlf:tx_aisteaseo_domain_model_report.progress_pages',
            'config' => [
                'type' => 'number',
                'size' => 10,
                'default' => 0,
                'readOnly' => true,
            ],
        ],
        'max_pages' => [
            'label' => 'LLL:EXT:aistea_seo/Resources/Private/Language/locallang.xlf:tx_aisteaseo_domain_model_report.max_pages',
            'config' => [
                'type' => 'number',
                'size' => 10,
                'default' => 50,
                'range' => ['lower' => 1, 'upper' => 200],
            ],
        ],
        'overall_score' => [
            'label' => 'LLL:EXT:aistea_seo/Resources/Private/Language/locallang.xlf:tx_aisteaseo_domain_model_report.overall_score',
            'config' => [
                'type' => 'number',
                'size' => 5,
                'default' => 0,
                'readOnly' => true,
            ],
        ],
        'queued_at' => [
            'label' => 'LLL:EXT:aistea_seo/Resources/Private/Language/locallang.xlf:tx_aisteaseo_domain_model_report.queued_at',
            'config' => [
                'type' => 'datetime',
                'format' => 'datetime',
                'readOnly' => true,
                'default' => 0,
            ],
        ],
        'started_at' => [
            'label' => 'LLL:EXT:aistea_seo/Resources/Private/Language/locallang.xlf:tx_aisteaseo_domain_model_report.started_at',
            'config' => [
                'type' => 'datetime',
                'format' => 'datetime',
                'readOnly' => true,
                'default' => 0,
            ],
        ],
        'finished_at' => [
            'label' => 'LLL:EXT:aistea_seo/Resources/Private/Language/locallang.xlf:tx_aisteaseo_domain_model_report.finished_at',
            'config' => [
                'type' => 'datetime',
                'format' => 'datetime',
                'readOnly' => true,
                'default' => 0,
            ],
        ],
        'last_crawled_url' => [
            'label' => 'LLL:EXT:aistea_seo/Resources/Private/Language/locallang.xlf:tx_aisteaseo_domain_model_report.last_crawled_url',
            'config' => [
                'type' => 'input',
                'size' => 80,
                'max' => 1000,
                'readOnly' => true,
            ],
        ],
        'error_message' => [
            'label' => 'LLL:EXT:aistea_seo/Resources/Private/Language/locallang.xlf:tx_aisteaseo_domain_model_report.error_message',
            'config' => [
                'type' => 'text',
                'cols' => 60,
                'rows' => 3,
                'readOnly' => true,
            ],
        ],
    ],
];
