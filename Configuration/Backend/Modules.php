<?php
declare(strict_types=1);

use Aistea\AisteaSeo\Controller\Backend\ReportController;

return [
    'aistea_seo' => [
        'parent' => 'web',
        'position' => ['after' => 'web_info'],
        'access' => 'user',
        'path' => '/module/aistea/seo',
        'iconIdentifier' => 'module-aisteaseo',
        'labels' => [
            'title' => 'LLL:EXT:aistea_seo/Resources/Private/Language/locallang_module.xlf:module.title',
            'description' => 'LLL:EXT:aistea_seo/Resources/Private/Language/locallang_module.xlf:module.description',
            'shortDescription' => 'LLL:EXT:aistea_seo/Resources/Private/Language/locallang_module.xlf:module.shortDescription',
        ],
        'extensionName' => 'AisteaSeo',
        'controllerActions' => [
            ReportController::class => ['index', 'new', 'create', 'show', 'exportCsv', 'exportIssuesCsv', 'exportJson', 'analyze', 'delete'],
        ],
    ],
];
