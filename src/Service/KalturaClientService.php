<?php

namespace Drupal\kaltura_transcoder\Service;

use Kaltura\Client\Client;
use Kaltura\Client\Configuration as KalturaConfiguration;
use Kaltura\Client\Enum\SessionType as KalturaSessionType;
use Kaltura\Client\Type\FilterPager;
use Kaltura\Client\Type\FlavorAssetFilter;
use Kaltura\Client\Type\MediaEntryFilter;
use Kaltura\Client\Type\MediaListResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Site\Settings;

class KalturaClientService {

  protected mixed $partnerId;
  protected mixed $adminSecret;
  protected mixed $userId;
  protected Client $client;

  public function __construct() {
    $config = Settings::get('kaltura');
    $this->partnerId = $config['partner_id'];
    $this->adminSecret = $config['admin_secret'];
    $this->userId = $config['user_id'];

    $kalturaConfig = new KalturaConfiguration($this->partnerId);
    $kalturaConfig->setServiceUrl('https://www.kaltura.com/');
    $this->client = new Client($kalturaConfig);

    $ks = $this->client->getSessionService()
      ->start($this->adminSecret, $this->userId, KalturaSessionType::ADMIN, $this->partnerId);
    $this->client->setKS($ks);
  }

  public function getClient(): Client {
    return $this->client;
  }

  public function getVideos($cat_id = '345421172', $page_size = 200): MediaListResponse {
    $filter = new MediaEntryFilter();
    $filter->categoriesIdsMatchAnd = $cat_id;

    $pager = new FilterPager();
    $pager->pageSize = $page_size;

    return $this->client->getMediaService()->listAction($filter, $pager);
  }

  public function getFlavors(string $entryId): JsonResponse {
    if (empty($entryId)) {
      return new JsonResponse(['error' => 'Entry ID is required'], 400);
    }

    try {
      $filter = new FlavorAssetFilter();
      $filter->entryIdEqual = $entryId;
      $pager = new FilterPager();
      $pager->pageSize = 500;
      $result = $this->client->getFlavorAssetService()->listAction($filter, $pager);

      $flavors = array_map(function ($flavorAsset) {
        return [
          'id' => $flavorAsset->id,
          'status' => $flavorAsset->status,
          'flavorParamsId' => $flavorAsset->flavorParamsId,
          'width' => $flavorAsset->width,
          'height' => $flavorAsset->height,
          'bitrate' => $flavorAsset->bitrate,
          'frameRate' => $flavorAsset->frameRate,
          'fileSize' => $flavorAsset->fileSize,
          'containerFormat' => $flavorAsset->containerFormat,
        ];
      }, $result->objects);

      return new JsonResponse(['flavors' => $flavors]);
    } catch (\Exception $e) {
      return new JsonResponse(['error' => 'Failed to retrieve flavor assets: ' . $e->getMessage()], 500);
    }
  }

}
