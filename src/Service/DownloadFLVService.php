<?php

namespace Drupal\kaltura_transcoder\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Kaltura\Client\Type\FlavorAssetFilter;
use Kaltura\Client\Type\FilterPager;

class DownloadFLVService {

  protected KalturaClientService $kalturaClientService;

  public function __construct(KalturaClientService $kalturaClientService) {
    $this->kalturaClientService = $kalturaClientService;
  }

  public function downloadFlavors(): array {
    try {
      $assetsToDownload = $this->getAssetsToDownload();
      ray($assetsToDownload);

      $i = 0;
      foreach ($assetsToDownload as $asset) {
        if ($i >= 0 && $i < 25) {
          $this->downloadAsset($asset);
        }
        $i++;
      }

      return ['message' => 'New flavor downloaded'];
    } catch (\Exception $e) {
      return ['error' => 'Failed to download flavors: ' . $e->getMessage()];
    }
  }

  private function getAssetsToDownload(): array {
    $assetsToDownload = [];
    $result = $this->kalturaClientService->getVideos('345602532', '200');
    foreach ($result->objects as $entry) {
      $flavorResult = $this->getFlavorAssets($entry->id);
      foreach ($flavorResult->objects as $flavorAsset) {
        if ($flavorAsset->fileExt === 'flv' && $flavorAsset->flavorParamsId == 0) {
          $assetsToDownload[$entry->id] = [
            'entryId' => $entry->id,
            'name' => $entry->name,
          ];
        }
      }

      foreach ($flavorResult->objects as $flavorAsset) {
        if ($flavorAsset->flavorParamsId == 414701) {
          if (isset($assetsToDownload[$entry->id])) {
            $assetsToDownload[$entry->id]['flavorAsset'] = $flavorAsset;
          }
        }
      }
    }
    return $assetsToDownload;
  }

  public function getFlavorAssets(string $entryId) {
    $filter = new FlavorAssetFilter();
    $filter->entryIdEqual = $entryId;
    $pager = new FilterPager();
    $pager->pageSize = 200;

    return $this->kalturaClientService->getClient()->getFlavorAssetService()->listAction($filter, $pager);
  }

  public function downloadAsset($asset): void {
//    ray($asset);
    $flavorAsset = $asset['flavorAsset'];
    $name = $asset['name'];
    $downloadUrl = $this->kalturaClientService->getClient()->getFlavorAssetService()->getUrl($flavorAsset->id);

    $uri = 'public://kaltura_videos/' . $name . '.' . $flavorAsset->fileExt;

    $fileSystem = \Drupal::service('file_system');
    $full_path = 'public://kaltura_videos';

    // Prepare the directory and ensure it exists with the correct permissions.
    if (!$fileSystem->prepareDirectory($full_path, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new \Exception('Directory ' . $full_path . ' could not be created or is not writable.');
    }

    $realPath = $fileSystem->realpath($uri);

    $fileHandle = fopen($realPath, 'wb');
    if ($fileHandle === FALSE) {
      throw new \Exception('Unable to open file for writing: ' . $realPath);
    }

    $handle = fopen($downloadUrl, 'rb');
    if ($handle === FALSE) {
      fclose($fileHandle);
      throw new \Exception('Unable to open stream for download URL: ' . $downloadUrl);
    }

    while (!feof($handle)) {
      $data = fread($handle, 8192);
      fwrite($fileHandle, $data);
    }

    fclose($handle);
    fclose($fileHandle);

    $fileStorage = \Drupal::entityTypeManager()->getStorage('file');
    $files = $fileStorage->loadByProperties(['uri' => $uri]);
    if (!empty($files)) {
      $file = reset($files);
    } else {
      $file = File::create([
        'uid' => 1,
        'filename' => basename($uri),
        'uri' => $uri,
        'status' => 1,
      ]);
      $file->save();
    }
  }

}
