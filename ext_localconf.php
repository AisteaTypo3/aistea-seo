<?php

defined('TYPO3') or die('Access denied.');

use Aistea\AisteaSeo\Controller\Frontend\CheckerController;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

ExtensionUtility::configurePlugin(
    'AisteaSeo',
    'Checker',
    [CheckerController::class => 'index'],
    [CheckerController::class => 'index'],
);

// The share link of a finished report (?audit=<token>) is a plain parameter without cHash.
$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = 'audit';
