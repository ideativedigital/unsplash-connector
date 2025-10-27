<?php

namespace Ideative\IdUnsplashConnector\Connector;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Ideative\IdStockPictures\ConnectorInterface;
use Ideative\IdStockPictures\Domain\Model\SearchResult;
use Ideative\IdStockPictures\Domain\Model\SearchResultItem;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class UnsplashConnector implements ConnectorInterface, LoggerAwareInterface
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

    protected array $extensionConfiguration;

    protected IconFactory $iconFactory;

    public function __construct()
    {
        // @todo : handle missing configuration
        $this->extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('unsplash-connector');
        $this->iconFactory = GeneralUtility::makeInstance(IconFactory::class);
        $this->setLogger(GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__));
    }

    /**
     * @param string $id
     * @return array
     * @throws GuzzleException
     */
    public function getFileUrlAndExtension(string $id): array
    {
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
     * @param array $params
     * @return SearchResult
     * @throws GuzzleException
     */
    public function search(array $params): SearchResult
    {
        /** @var SearchResult $result */
        $result = GeneralUtility::makeInstance(SearchResult::class);

        $params['sort'] = 'relevance';
        $params['per_page'] = 50;
        $params['query'] = $params['q'];

        $params['client_id'] = $this->extensionConfiguration['unsplash_access_key'];

        unset($params['q']);

        if (!empty($params['query'])) {

            // Remove unset filters
            $params = array_filter($params, static function ($value) {
                return !empty($value);
            });


            $client = new Client();
            try {
                $response = $client->request('GET', self::SEARCH_ENDPOINT, [
                    'query' => $params,
                ]);
                $rawResult = json_decode($response->getBody()->getContents(), false, 512, JSON_THROW_ON_ERROR);
                if ($rawResult) {
                    $result = $this->formatResults($rawResult, $params);
                    $result->page = $params['page'];
                }
            } catch (Exception $e) {
                $this->logger->critical($e->getMessage());
                $result->success = false;
                $result->message = $e->getMessage();
            }
        } else {
            $result->success = false;
            $result->message = LocalizationUtility::translate('LLL:EXT:unsplash-connector/Resources/Private/Language/locallang.xlf:error.query_required', 'unsplash-connector');
        }

        return $result;
    }

    /**
     * Converts the raw API results into a common format
     * @param \stdClass $rawData
     * @param array $params
     * @return SearchResult
     */
    public function formatResults(\stdClass $rawData, array $params): SearchResult
    {
        /** @var SearchResult $result */
        $result = GeneralUtility::makeInstance(SearchResult::class);

        $result->search = $params;
        $result->totalCount = $rawData->total;

        foreach ($rawData->results as $item) {
            $resultItem = GeneralUtility::makeInstance(SearchResultItem::class);
            $resultItem->id = $item->id;
            $resultItem->preview = $item->urls->regular;
            $result->data[] = $resultItem;
        }
        return $result;
    }

    /**
     * Returns the label of the "Add media" button
     * @return string|null
     */
    public function getAddButtonLabel(): ?string
    {
        return LocalizationUtility::translate('LLL:EXT:unsplash-connector/Resources/Private/Language/locallang.xlf:button.add_media', 'unsplash-connector');
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
                'label' => LocalizationUtility::translate('LLL:EXT:unsplash-connector/Resources/Private/Language/locallang.xlf:filter.orientation.label', 'unsplash-connector'),
                'options' => [
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:unsplash-connector/Resources/Private/Language/locallang.xlf:filter.orientation.I.any', 'unsplash-connector'),
                        'value' => ''
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:unsplash-connector/Resources/Private/Language/locallang.xlf:filter.orientation.I.landscape',
                            'unsplash-connector'),
                        'value' => 'landscape'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:unsplash-connector/Resources/Private/Language/locallang.xlf:filter.orientation.I.portrait',
                            'unsplash-connector'),
                        'value' => 'portrait'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:unsplash-connector/Resources/Private/Language/locallang.xlf:filter.orientation.I.squarish',
                            'unsplash-connector'),
                        'value' => 'squarish'
                    ],
                ]
            ],
            'color' => [
                'label' => LocalizationUtility::translate('LLL:EXT:unsplash-connector/Resources/Private/Language/locallang.xlf:filter.color.label', 'unsplash-connector'),
                'options' => [
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:unsplash-connector/Resources/Private/Language/locallang.xlf:filter.color.I.any', 'unsplash-connector'),
                        'value' => ''
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:unsplash-connector/Resources/Private/Language/locallang.xlf:filter.color.I.black_and_white',
                            'unsplash-connector'),
                        'value' => 'black_and_white'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:unsplash-connector/Resources/Private/Language/locallang.xlf:filter.color.I.white', 'unsplash-connector'),
                        'value' => 'white'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:unsplash-connector/Resources/Private/Language/locallang.xlf:filter.color.I.black', 'unsplash-connector'),
                        'value' => 'black'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:unsplash-connector/Resources/Private/Language/locallang.xlf:filter.color.I.blue', 'unsplash-connector'),
                        'value' => 'blue'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:unsplash-connector/Resources/Private/Language/locallang.xlf:filter.color.I.magenta', 'unsplash-connector'),
                        'value' => 'magenta'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:unsplash-connector/Resources/Private/Language/locallang.xlf:filter.color.I.green', 'unsplash-connector'),
                        'value' => 'green'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:unsplash-connector/Resources/Private/Language/locallang.xlf:filter.color.I.orange', 'unsplash-connector'),
                        'value' => 'orange'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:unsplash-connector/Resources/Private/Language/locallang.xlf:filter.color.I.purple', 'unsplash-connector'),
                        'value' => 'purple'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:unsplash-connector/Resources/Private/Language/locallang.xlf:filter.color.I.red', 'unsplash-connector'),
                        'value' => 'red'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:unsplash-connector/Resources/Private/Language/locallang.xlf:filter.color.I.teal', 'unsplash-connector'),
                        'value' => 'teal'
                    ],
                    [
                        'label' => LocalizationUtility::translate('LLL:EXT:unsplash-connector/Resources/Private/Language/locallang.xlf:filter.color.I.yellow', 'unsplash-connector'),
                        'value' => 'yellow'
                    ],
                ]
            ],
        ];
    }

    /**
     * Returns the markup for the icon of the "Add media" button
     * @return string
     */
    public function getAddButtonIcon(): string
    {
        return $this->iconFactory->getIcon('actions-online-media-add', IconSize::SMALL)->render();
    }

    /**
     * Returns the additional attributes added to the "Add media button", so they can be used in Javascript later
     * @return array
     */
    public function getAddButtonAttributes(): array
    {
        $buttonLabel = LocalizationUtility::translate('LLL:EXT:unsplash-connector/Resources/Private/Language/locallang.xlf:button.add_media', 'unsplash-connector');
        $submitButtonLabel = LocalizationUtility::translate('LLL:EXT:unsplash-connector/Resources/Private/Language/locallang.xlf:button.submit', 'unsplash-connector');
        $cancelLabel = LocalizationUtility::translate('LLL:EXT:unsplash-connector/Resources/Private/Language/locallang.xlf:button.cancel', 'unsplash-connector');
        $placeholderLabel = LocalizationUtility::translate('LLL:EXT:unsplash-connector/Resources/Private/Language/locallang.xlf:placeholder.search', 'unsplash-connector');
        return [
            'title' => $buttonLabel,
            'data-btn-submit' => $submitButtonLabel,
            'data-placeholder' => $placeholderLabel,
            'data-btn-cancel' => $cancelLabel
        ];
    }

}
