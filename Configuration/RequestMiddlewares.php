<?php

declare(strict_types=1);

return [
    'frontend' => [
        'aistea/aistea-seo/checker-endpoint' => [
            'target' => \Aistea\AisteaSeo\Middleware\CheckerEndpoint::class,
            'after' => [
                'typo3/cms-frontend/site',
                'typo3/cms-frontend/backend-user-authentication',
            ],
            'before' => [
                'typo3/cms-frontend/base-redirect-resolver',
                'typo3/cms-frontend/page-resolver',
            ],
        ],
    ],
];
