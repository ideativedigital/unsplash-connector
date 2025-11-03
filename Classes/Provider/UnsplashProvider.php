<?php

namespace TYPO3\CMS\DAP\Unsplash\Provider;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use stdClass;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Filelist\Controller\DigitalAssetProvider\ProviderInterface;
use TYPO3\CMS\Filelist\Dto\DigitalAssetProvider\SearchResult;
use TYPO3\CMS\Filelist\Dto\DigitalAssetProvider\SearchResultItem;

class UnsplashProvider implements ProviderInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * URL of the Unsplash Search API
     */
    protected const SEARCH_ENDPOINT = 'https://api.unsplash.com/search/photos';

    /**
     * URL of the photo detail in Unsplash API
     */
    protected const DETAIL_ENDPOINT = 'https://api.unsplash.com/photos';

    protected mixed $extensionConfiguration;

    /**
     * @throws ExtensionConfigurationPathDoesNotExistException
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     */
    public function __construct()
    {
        $this->extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class)?->get('dap-unsplash');
        $this->setLogger(GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__));
    }

    /**
     * @param string $id
     * @return array
     * @throws GuzzleException
     */
    public function getFileUrlAndExtension(string $id): array
    {
        dump($id);
        try {
            $client = new Client();
            $apiUrl = self::DETAIL_ENDPOINT . '/' . $id;
            $response = $client->request('GET', $apiUrl, [
                'query' => [
                    'client_id' => $this->extensionConfiguration['unsplash_access_key']
                ],
            ]);
            $result = json_decode($response->getBody()->getContents(), false, 512, JSON_THROW_ON_ERROR);

            $fileUrl = !empty($result->urls->full) ? $result->urls->full : '';

            // Extract the file extension from the download URL
            $extension = '';
            if (preg_match('/&fm=([a-z0-9]+)/', $fileUrl, $matches)) {
                $extension = $matches[1] ?? '';
            }
            return [
                'url' => $fileUrl,
                'filename' => $this->getFileName($result),
                'extension' => $extension,
                'metadata' => [
                    'title' => $this->getFileTitle($result),
                    'alternative' => $this->getFileAlternativeTitle($result),
                    'description' => $this->getFileDescription($result),
                    'width' => $result->width ?? 0,
                    'height' => $result->height ?? 0,
                ],
            ];
        } catch (Exception $e) {
            $this->logger->critical($e->getMessage());
        }

        return [];
    }

    protected function getFileName(mixed $fileData): string
    {
        return $fileData->slug ?? '';
    }

    protected function getFileTitle(mixed $fileData): string
    {
        return $fileData->description ?? '';
    }

    protected function getFileAlternativeTitle(mixed $fileData): string
    {
        return $fileData->alt_description ?? '';
    }

    protected function getFileDescription(mixed $fileData): string
    {
        $fileUserFirstName = $fileData->user->first_name ?? '';
        $fileUserLastName = $fileData->user->last_name ?? '';
        $fileUserUrl = $fileData->user->links->html ?? '';
        if ($fileUserFirstName || $fileUserLastName) {
            $author = trim($fileUserFirstName . ' ' . $fileUserLastName);
            if ($fileUserUrl) {
                return sprintf('%s (%s)', $author, $fileUserUrl);
            }
            return $author;
        }
        return $fileUserUrl;
    }

    /**
     * @param array $searchParams
     * @return SearchResult
     */
    public function search(array $searchParams): SearchResult
    {
        /** @var SearchResult $result */
        $result = GeneralUtility::makeInstance(SearchResult::class);

        $searchParams['sort'] = 'relevance';
        $searchParams['per_page'] = 50;
        $searchParams['query'] = $searchParams['q'];

        $searchParams['client_id'] = $this->extensionConfiguration['unsplash_access_key'];

        unset($searchParams['q']);

        if (!empty($searchParams['query'])) {

            // Remove unset filters
            $searchParams = array_filter($searchParams, static function ($value) {
                return !empty($value);
            });

            $client = new Client();
            try {
                $response = $client->request('GET', self::SEARCH_ENDPOINT, [
                    'query' => $searchParams,
                ]);
                $rawResult = json_decode($response->getBody()->getContents(), false, 512, JSON_THROW_ON_ERROR);
                dump($rawResult);
                if ($rawResult) {
                    $result = $this->formatResults($rawResult, $searchParams);
                    $result->page = $searchParams['page'];
                }
            } catch (Exception $e) {
                $this->logger->critical($e->getMessage());
                $result->success = false;
                $result->message = $e->getMessage();
                dump($e->getMessage());
            }
        } else {
            $result->success = false;
            $result->message = LocalizationUtility::translate('LLL:EXT:dap-unsplash/Resources/Private/Language/locallang.xlf:error.query_required', 'dap-unsplash');
        }

        return $result;
    }

    /**
     * Converts the raw API results into a common format
     * @param \stdClass $rawData
     * @param array $searchParams
     * @return SearchResult
     */
    public function formatResults(\stdClass $rawData, array $searchParams): SearchResult
    {
        /** @var SearchResult $result */
        $result = GeneralUtility::makeInstance(SearchResult::class);

        $result->search = $searchParams;
        $result->totalCount = $rawData->total;

        foreach ($rawData->results as $item) {
            $resultItem = GeneralUtility::makeInstance(SearchResultItem::class);
            $resultItem->id = $item->id;
            $resultItem->url = $item->urls->regular;
            $resultItem->setFileName();
            $result->data[] = $resultItem;
        }
        return $result;
    }

    /**
     * Returns the list of available filters when using the Unsplash API
     * This array is then JSON encoded to be fed as a data attribute to the "Add media" button
     * @return string[]
     */
    public function getAvailableFilters(): array
    {
        return [
            'orientation' => [
                'label' => LocalizationUtility::translate('LLL:EXT:dap-unsplash/Resources/Private/Language/locallang.xlf:filter.orientation.label', 'dap-unsplash'),
                'options' => [
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:dap-unsplash/Resources/Private/Language/locallang.xlf:filter.orientation.I.any', 'dap-unsplash'),
                        'value' => ''
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:dap-unsplash/Resources/Private/Language/locallang.xlf:filter.orientation.I.landscape',
                            'dap-unsplash'),
                        'value' => 'landscape'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:dap-unsplash/Resources/Private/Language/locallang.xlf:filter.orientation.I.portrait',
                            'dap-unsplash'),
                        'value' => 'portrait'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:dap-unsplash/Resources/Private/Language/locallang.xlf:filter.orientation.I.squarish',
                            'dap-unsplash'),
                        'value' => 'squarish'
                    ],
                ]
            ],
            'color' => [
                'label' => LocalizationUtility::translate('LLL:EXT:dap-unsplash/Resources/Private/Language/locallang.xlf:filter.color.label', 'dap-unsplash'),
                'options' => [
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:dap-unsplash/Resources/Private/Language/locallang.xlf:filter.color.I.any', 'dap-unsplash'),
                        'value' => ''
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:dap-unsplash/Resources/Private/Language/locallang.xlf:filter.color.I.black_and_white',
                            'dap-unsplash'),
                        'value' => 'black_and_white'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:dap-unsplash/Resources/Private/Language/locallang.xlf:filter.color.I.white', 'dap-unsplash'),
                        'value' => 'white'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:dap-unsplash/Resources/Private/Language/locallang.xlf:filter.color.I.black', 'dap-unsplash'),
                        'value' => 'black'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:dap-unsplash/Resources/Private/Language/locallang.xlf:filter.color.I.blue', 'dap-unsplash'),
                        'value' => 'blue'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:dap-unsplash/Resources/Private/Language/locallang.xlf:filter.color.I.magenta', 'dap-unsplash'),
                        'value' => 'magenta'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:dap-unsplash/Resources/Private/Language/locallang.xlf:filter.color.I.green', 'dap-unsplash'),
                        'value' => 'green'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:dap-unsplash/Resources/Private/Language/locallang.xlf:filter.color.I.orange', 'dap-unsplash'),
                        'value' => 'orange'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:dap-unsplash/Resources/Private/Language/locallang.xlf:filter.color.I.purple', 'dap-unsplash'),
                        'value' => 'purple'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:dap-unsplash/Resources/Private/Language/locallang.xlf:filter.color.I.red', 'dap-unsplash'),
                        'value' => 'red'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:dap-unsplash/Resources/Private/Language/locallang.xlf:filter.color.I.teal', 'dap-unsplash'),
                        'value' => 'teal'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:dap-unsplash/Resources/Private/Language/locallang.xlf:filter.color.I.yellow', 'dap-unsplash'),
                        'value' => 'yellow'
                    ],
                ]
            ],
        ];
    }
}
   
