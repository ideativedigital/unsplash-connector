<?php

use TYPO3\CMS\DAP\Unsplash\Provider\UnsplashProvider;

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['TYPO3\CMS\Filelist\Controller\DigitalAssetProvider']['providers']['unsplash'] = UnsplashProvider::class;
