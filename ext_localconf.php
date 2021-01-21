<?php
defined('TYPO3_MODE') || die();

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['id_stock_pictures']['connectors']['unsplash'] = \Ideative\IdUnsplashConnector\Connector\UnsplashConnector::class;
