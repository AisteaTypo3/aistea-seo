<?php
declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:aistea_seo/Resources/Private/Language/locallang.xlf:tx_aisteaseo_domain_model_page',
        'label' => 'url',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'default_sortby' => 'page_score ASC',
        'iconfile' => 'EXT:aistea_seo/Resources/Public/Icons/module-aisteaseo.svg',
        'hideTable' => true,
        'searchFields' => 'url, page_title',
    ],
    'types' => [
        '1' => [
            'showitem' => 'report, url, status_code, page_type, content_type, redirect_target, redirect_final_url, redirect_hops, page_title, meta_description, h1_text, page_score, issues',
        ],
    ],
    'columns' => [
        'report' => [
            'label' => 'Report',
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'url' => [
            'label' => 'URL',
            'config' => ['type' => 'input', 'size' => 80, 'readOnly' => true],
        ],
        'status_code' => [
            'label' => 'HTTP Status',
            'config' => ['type' => 'number', 'readOnly' => true],
        ],
        'page_type' => [
            'label' => 'Page Type',
            'config' => ['type' => 'input', 'size' => 20, 'readOnly' => true],
        ],
        'content_type' => [
            'label' => 'Content Type',
            'config' => ['type' => 'input', 'size' => 40, 'readOnly' => true],
        ],
        'redirect_target' => [
            'label' => 'Redirect Target',
            'config' => ['type' => 'input', 'size' => 80, 'readOnly' => true],
        ],
        'redirect_final_url' => [
            'label' => 'Redirect Final URL',
            'config' => ['type' => 'input', 'size' => 80, 'readOnly' => true],
        ],
        'redirect_hops' => [
            'label' => 'Redirect Hops',
            'config' => ['type' => 'number', 'readOnly' => true],
        ],
        'page_title' => [
            'label' => 'Page Title',
            'config' => ['type' => 'input', 'size' => 80, 'readOnly' => true],
        ],
        'meta_description' => [
            'label' => 'Meta Description',
            'config' => ['type' => 'text', 'cols' => 60, 'rows' => 2, 'readOnly' => true],
        ],
        'h1_text' => [
            'label' => 'H1',
            'config' => ['type' => 'input', 'size' => 80, 'readOnly' => true],
        ],
        'page_score' => [
            'label' => 'Score',
            'config' => ['type' => 'number', 'readOnly' => true],
        ],
        'issues' => [
            'label' => 'Issues (JSON)',
            'config' => ['type' => 'text', 'cols' => 60, 'rows' => 5, 'readOnly' => true],
        ],
    ],
];
