<?php
declare(strict_types=1);

return [
    \Aistea\AisteaSeo\Domain\Model\SeoReport::class => [
        'tableName' => 'tx_aisteaseo_domain_model_report',
        'properties' => [
            'baseUrl' => ['fieldName' => 'base_url'],
            'pagesCrawled' => ['fieldName' => 'pages_crawled'],
            'progressPages' => ['fieldName' => 'progress_pages'],
            'maxPages' => ['fieldName' => 'max_pages'],
            'overallScore' => ['fieldName' => 'overall_score'],
            'errorMessage' => ['fieldName' => 'error_message'],
            'queuedAt' => ['fieldName' => 'queued_at'],
            'startedAt' => ['fieldName' => 'started_at'],
            'finishedAt' => ['fieldName' => 'finished_at'],
            'lastCrawledUrl' => ['fieldName' => 'last_crawled_url'],
        ],
    ],
    \Aistea\AisteaSeo\Domain\Model\SeoPage::class => [
        'tableName' => 'tx_aisteaseo_domain_model_page',
        'properties' => [
            'statusCode' => ['fieldName' => 'status_code'],
            'pageType' => ['fieldName' => 'page_type'],
            'contentType' => ['fieldName' => 'content_type'],
            'redirectTarget' => ['fieldName' => 'redirect_target'],
            'redirectFinalUrl' => ['fieldName' => 'redirect_final_url'],
            'redirectHops' => ['fieldName' => 'redirect_hops'],
            'pageTitle' => ['fieldName' => 'page_title'],
            'titleLength' => ['fieldName' => 'title_length'],
            'metaDescription' => ['fieldName' => 'meta_description'],
            'metaDescriptionLength' => ['fieldName' => 'meta_description_length'],
            'h1Count' => ['fieldName' => 'h1_count'],
            'h1Text' => ['fieldName' => 'h1_text'],
            'h2Count' => ['fieldName' => 'h2_count'],
            'canonicalUrl' => ['fieldName' => 'canonical_url'],
            'robotsNoindex' => ['fieldName' => 'robots_noindex'],
            'robotsNofollow' => ['fieldName' => 'robots_nofollow'],
            'imagesTotal' => ['fieldName' => 'images_total'],
            'imagesMissingAlt' => ['fieldName' => 'images_missing_alt'],
            'linksInternal' => ['fieldName' => 'links_internal'],
            'linksExternal' => ['fieldName' => 'links_external'],
            'wordCount' => ['fieldName' => 'word_count'],
            'loadTime' => ['fieldName' => 'load_time'],
            'pageScore' => ['fieldName' => 'page_score'],
        ],
    ],
];
