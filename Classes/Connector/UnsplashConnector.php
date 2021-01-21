<?php

namespace Ideative\IdUnsplashConnector\Connector;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Ideative\IdStockPictures\ConnectorInterface;
use Ideative\IdStockPictures\Domain\Model\SearchResult;
use Ideative\IdStockPictures\Domain\Model\SearchResultItem;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class UnsplashConnector implements ConnectorInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * URL of the Unsplash Search API
     */
    const SEARCH_ENDPOINT = 'https://api.unsplash.com/search/photos';

    /**
     * URL of the photo detail in Unsplash API
     */
    const DETAIL_ENDPOINT = 'https://api.unsplash.com/photos';

    /**
     * @var array
     */
    protected $extensionConfiguration;

    public function __construct()
    {
        $this->extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('id_unsplash_connector');
        $this->setLogger(GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__));
    }

    /**
     * @param string $id
     * @return string|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getFileUrlAndExtension(string $id): array
    {
        try {
            $client = new Client();
            $url = self::DETAIL_ENDPOINT . '/' . $id;
            $response = $client->request('GET', $url, [
                'query' => [
                    'client_id' => $this->extensionConfiguration['unsplash_access_key']
                ],
            ]);
            $result = json_decode($response->getBody()->getContents());

            $url = !empty($result->urls->full) ? $result->urls->full : '';
            // Extract the file extension from the download URL
            if (preg_match('/&fm=([a-z0-9]+)/', $url, $matches)) {
                $extension = $matches[1] ?? '';
            }
        } catch (\Exception $e) {
            $this->logger->critical($e->getMessage());
        }

        return [
            'url' => $url,
            'extension' => $extension
        ];
    }


    /**
     * @param array $params
     * @return SearchResult
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function search(array $params)
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
            $params = array_filter($params, function ($value) {
                return !empty($value);
            });


            $client = new Client();
            try {
                $response = $client->request('GET', self::SEARCH_ENDPOINT, [
                    'query' => $params,
                ]);
                $rawResult = json_decode($response->getBody()->getContents());
                if ($rawResult) {
                    $result = $this->formatResults($rawResult, $params);
                    $result->page = $params['page'];
                }
            } catch (\Exception $e) {
                $this->logger->critical($e->getMessage());
                $result->success = false;
                $result->message = $e->getMessage();
            }
        } else {
            $result->success = false;
            $result->message = LocalizationUtility::translate('error.query_required', 'id_unsplash_connector');
        }

        return $result;
    }

    /**
     * Converts the raw API results into a common format
     * @param array $rawData
     * @param array $params
     */
    public function formatResults($rawData, $params)
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
    public function getAddButtonLabel()
    {
        return LocalizationUtility::translate('button.add_media', 'id_unsplash_connector');
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
                'label' => LocalizationUtility::translate('filter.orientation.label', 'id_unsplash_connector'),
                'options' => [
                    [
                        'label' => LocalizationUtility::translate('filter.orientation.I.any', 'id_unsplash_connector'),
                        'value' => ''
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.orientation.I.landscape',
                            'id_unsplash_connector'),
                        'value' => 'landscape'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.orientation.I.portrait',
                            'id_unsplash_connector'),
                        'value' => 'portrait'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.orientation.I.squarish',
                            'id_unsplash_connector'),
                        'value' => 'squarish'
                    ],
                ]
            ],
            'color' => [
                'label' => LocalizationUtility::translate('filter.color.label', 'id_unsplash_connector'),
                'options' => [
                    [
                        'label' => LocalizationUtility::translate('filter.color.I.any', 'id_unsplash_connector'),
                        'value' => ''
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.color.I.black_and_white',
                            'id_unsplash_connector'),
                        'value' => 'black_and_white'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.color.I.white', 'id_unsplash_connector'),
                        'value' => 'white'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.color.I.black', 'id_unsplash_connector'),
                        'value' => 'black'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.color.I.blue', 'id_unsplash_connector'),
                        'value' => 'blue'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.color.I.magenta', 'id_unsplash_connector'),
                        'value' => 'magenta'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.color.I.green', 'id_unsplash_connector'),
                        'value' => 'green'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.color.I.orange', 'id_unsplash_connector'),
                        'value' => 'orange'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.color.I.purple', 'id_unsplash_connector'),
                        'value' => 'purple'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.color.I.red', 'id_unsplash_connector'),
                        'value' => 'red'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.color.I.teal', 'id_unsplash_connector'),
                        'value' => 'teal'
                    ],
                    [
                        'label' => LocalizationUtility::translate('filter.color.I.yellow', 'id_unsplash_connector'),
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
        return '<span class="t3js-icon icon icon-size-small icon-state-default icon-actions-online-media-add" data-identifier="actions-unsplash-media-add">
                <span class="icon-markup">
                    <svg class="icon-color" role="img"><use xlink:href="/typo3/sysext/core/Resources/Public/Icons/T3Icons/sprites/actions.svg#actions-cloud" /></svg>
                </span>
            </span>';
    }

    /**
     * Returns the additional attributes added to the "Add media button", so they can be used in Javascript later
     * @return array
     */
    public function getAddButtonAttributes(): array
    {
        $buttonLabel = LocalizationUtility::translate('button.add_media', 'id_unsplash_connector');
        $submitButtonLabel = LocalizationUtility::translate('button.submit', 'id_unsplash_connector');
        $cancelLabel = LocalizationUtility::translate('button.cancel', 'id_unsplash_connector');
        $placeholderLabel = LocalizationUtility::translate('placeholder.search', 'id_unsplash_connector');
        return [
            'title' => $buttonLabel,
            'data-btn-submit' => $submitButtonLabel,
            'data-placeholder' => $placeholderLabel,
            'data-btn-cancel' => $cancelLabel
        ];
    }

}