<?php

declare(strict_types=1);

use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') or die();

ExtensionUtility::registerPlugin(
    'AisteaSeo',
    'Checker',
    'LLL:EXT:aistea_seo/Resources/Private/Language/locallang_checker.xlf:plugin.title',
    'module-aisteaseo',
    'plugins',
    'LLL:EXT:aistea_seo/Resources/Private/Language/locallang_checker.xlf:plugin.description',
);
