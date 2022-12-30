<?php
// TYPO3_MODE for TYPO3 v10 and below
defined('TYPO3_MODE') or defined('TYPO3') or die();

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['id_stock_pictures']['connectors']['unsplash'] = \Ideative\IdUnsplashConnector\Connector\UnsplashConnector::class;
