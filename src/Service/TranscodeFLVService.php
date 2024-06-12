<?php

namespace Drupal\kaltura_transcoder\Service;

use Kaltura\Client\Type\CategoryEntry;
use Kaltura\Client\Type\CategoryEntryFilter;
use Kaltura\Client\Type\FilterPager;
use Kaltura\Client\Type\FlavorAssetFilter;

class TranscodeFLVService {

  protected KalturaClientService $kalturaClientService;

  public function __construct(KalturaClientService $kalturaClientService) {
    $this->kalturaClientService = $kalturaClientService;
  }

  public function createFlavor(): array {
    $entriesWithFlvSource = $this->getEntriesWithFlvSource();
//    foreach ($entriesWithFlvSource as $entry) {
//      try {
//        $this->kalturaClientService->getClient()->flavorAsset->convert($entry['entryId'], 414701, 0);
//        echo "Converted entry {$entry['entryId']} from FLV\n";
//      } catch (\Exception $e) {
//        ray($entry);
//        return ['error' => 'Failed to create flavor asset: ' . $e->getMessage()];
//      }
//    }

    return ['message' => 'New flavors encoded'];
  }

  private function getEntriesWithFlvSource(): array {
    $entriesWithFlvSource = [];
    $result = $this->kalturaClientService->getVideos();

    foreach ($result->objects as $entry) {
      $flavorResult = $this->getFlavorAssets($entry->id);
      foreach ($flavorResult->objects as $flavorAsset) {
        if ($flavorAsset->fileExt === 'flv' && $flavorAsset->flavorParamsId == 0) {
          $this->addCategoryToEntry($entry->id, 345602532);
          $entriesWithFlvSource[] = [
            'entryId' => $entry->id,
            'name' => $entry->name,
            'flavorAssetId' => $flavorAsset->id,
            'flavorParamsId' => $flavorAsset->flavorParamsId,
          ];
          break;
        }
      }
    }

    return $entriesWithFlvSource;
  }

  private function getFlavorAssets(string $entryId) {
    $filter = new FlavorAssetFilter();
    $filter->entryIdEqual = $entryId;
    $pager = new FilterPager();
    $pager->pageSize = 200;

    return $this->kalturaClientService->getClient()->getFlavorAssetService()->listAction($filter, $pager);
  }

  private function addCategoryToEntry(string $entryId, int $categoryId): void {
    $categoryFilter = new CategoryEntryFilter();
    $categoryFilter->entryIdEqual = $entryId;
    $categoryFilter->categoryIdEqual = $categoryId;
    $categoryPager = new FilterPager();
    $categoryPager->pageSize = 1;

    $categoryResult = $this->kalturaClientService->getClient()->getCategoryEntryService()->listAction($categoryFilter, $categoryPager);
    if (count($categoryResult->objects) === 0) {
      $categoryEntry = new CategoryEntry();
      $categoryEntry->entryId = $entryId;
      $categoryEntry->categoryId = $categoryId;
      $this->kalturaClientService->getClient()->getCategoryEntryService()->add($categoryEntry);
    }
  }

}
