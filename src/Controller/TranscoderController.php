<?php

namespace Drupal\kaltura_transcoder\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\kaltura_transcoder\Service\AssignCategoryService;
use Drupal\kaltura_transcoder\Service\DownloadFLVService;
use Drupal\kaltura_transcoder\Service\KalturaClientService;
use Drupal\kaltura_transcoder\Service\TranscodeFLVService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for Kaltura Transcoder.
 */
class TranscoderController extends ControllerBase {

    protected TranscodeFLVService $transcodeFLVService;

    protected DownloadFLVService $downloadFLVService;

    protected KalturaClientService $kalturaClientService;

    protected AssignCategoryService $assignCategoryService;

    public function __construct(
        TranscodeFLVService        $transcodeFLVService,
        DownloadFLVService         $downloadFLVService,
        KalturaClientService       $kalturaClientService,
        AssignCategoryService      $assignCategoryService,
        EntityTypeManagerInterface $entityTypeManager
    ) {
        $this->transcodeFLVService = $transcodeFLVService;
        $this->downloadFLVService = $downloadFLVService;
        $this->kalturaClientService = $kalturaClientService;
        $this->assignCategoryService = $assignCategoryService;
        $this->entityTypeManager = $entityTypeManager;
    }

    public static function create(ContainerInterface $container): TranscoderController {
        return new static(
            $container->get('kaltura_transcoder.transcode_flv'),
            $container->get('kaltura_transcoder.download_flv'),
            $container->get('kaltura_transcoder.kaltura_client'),
            $container->get('kaltura_transcoder.assign_category'),
            $container->get('entity_type.manager')
        );
    }

    /**
     * Assigns a category to a list of entry IDs.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     *   The request object.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     *   A JSON response indicating the result of the operation.
     */
    public function assignCategoryToEntries(): JsonResponse {
        $entryIds = $this->evaluateKalturaVideos();
        $categoryId = 346022192; //$request->request->get('category_id');

        if (empty($entryIds)) {
            return new JsonResponse(['error' => 'Invalid input'], 400);
        }

        ray('Starting to assign category to entries.');

        $results = $this->assignCategoryService->assignCategory($entryIds, $categoryId);
        return new JsonResponse('Done assigning category to entries.');
    }

    /**
     * Evaluates Kaltura videos in paragraphs and extracts entry IDs.
     */
    private function evaluateKalturaVideos() {
        $query = $this->entityTypeManager->getStorage('paragraph')->getQuery();
        $query->accessCheck(TRUE); // Enable access check
        $query->condition('type', 'kaltura_embed')
            ->condition('field_kaltura.value', '%entry_id%', 'LIKE');
        $paragraph_ids = $query->execute();

        // Array to hold extracted entry IDs
        $entry_ids = [];

        if ($paragraph_ids) {
            $paragraphs = $this->entityTypeManager->getStorage('paragraph')->loadMultiple($paragraph_ids);

            foreach ($paragraphs as $paragraph) {
                // The text to check
                $text = $paragraph->get('field_kaltura')->value;

                // Define the regex patterns to match the entry_id in different tags
                $iframe_pattern = '/<iframe\s[^>]*src="https:\/\/cdnapisec\.kaltura\.com\/p\/[0-9]+\/sp\/[0-9]+\/embedIframeJs\/uiconf_id\/[0-9]+\/partner_id\/[0-9]+\?[^"]*entry_id=([a-zA-Z0-9_]+)&[^"]*"[^>]*>.*<\/iframe>/';
                $script_pattern = '/kWidget\.embed\(\{[^}]*"entry_id"\s*:\s*"([a-zA-Z0-9_]+)"/';
                $thumbnail_pattern = '/thumbnail\/entry_id\/([a-zA-Z0-9_]+)\//';

                // Check the text against the regex patterns
                if (preg_match($iframe_pattern, $text, $iframe_matches)) {
                    //                    $entry_ids[] = ['pid' => $paragraph->id(), 'entry_id' => $iframe_matches[1]];
                    $entry_ids[] = $iframe_matches[1];
                } elseif (preg_match($script_pattern, $text, $script_matches)) {
                    //                    $entry_ids[] = ['pid' => $paragraph->id(), 'entry_id' => $script_matches[1]];
                    $entry_ids[] = $script_matches[1];
                } elseif (preg_match($thumbnail_pattern, $text, $thumbnail_matches)) {
                    //                    $entry_ids[] = ['pid' => $paragraph->id(), 'entry_id' => $thumbnail_matches[1]];
                    $entry_ids[] = $thumbnail_matches[1];
                } else {
                    // Split the text into lines for further inspection
                    $lines = explode("\n", $text);
                    foreach ($lines as $line) {
                        // Check if the line contains entry_id
                        if (strpos($line, 'entry_id') !== FALSE) {
                            // Extract the entry_id using a regex
                            if (preg_match('/"entry_id"\s*:\s*"([a-zA-Z0-9_]+)"/', $line, $matches)) {
                                //                                $entry_ids[] = ['pid' => $paragraph->id(), 'entry_id' => $matches[1]];
                                $entry_ids[] = $matches[1];
                                break; // Exit the loop once entry_id is found
                            }
                        }
                    }
                }
            }

            // Check for duplicates in the paragraph IDs
            if ($this->hasDuplicates($paragraph_ids)) {
//                ray("Array has duplicates.");
            } else {
//                ray("Array does not have duplicates.");
            }
        }

        return $entry_ids;
    }

    /**
     * Checks if an array contains duplicate values.
     *
     * @param array $array
     *   The array to check.
     *
     * @return bool
     *   TRUE if duplicates are found, FALSE otherwise.
     */
    private function hasDuplicates(array $array): bool {
        $hash = [];
        foreach ($array as $item) {
            if (isset($hash[$item])) {
                return true;
            }
            $hash[$item] = true;
        }
        return false;
    }

    public function createFlavor(): JsonResponse {
        $response = $this->transcodeFLVService->createFlavor();
        return new JsonResponse($response);
    }

    /**
     * Download all videos in a specific category.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     *   The request object.
     * @param string $category_id
     *   The ID of the category to download.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     *   A JSON response indicating the result of the operation.
     */
    public function downloadCategory(Request $request, string $category_id): JsonResponse {
        try {
            // Use the DownloadFLVService to download videos in the specified category.
            $result = $this->kalturaClientService->getVideos($category_id);

            $assetsToDownload = [];
            $assetsToDownload2 = [];

            foreach ($result->objects as $entry) {
                $flavorParamIds = explode(',', $entry->flavorParamsIds);
                $addAsset = FALSE;
                foreach ($flavorParamIds as $flavorParamId) {
                    if ($flavorParamId == 0) {
                        $addAsset = TRUE;
                    }
                }

                $flavorResult = $this->downloadFLVService->getFlavorAssets($entry->id);
                if ($addAsset === TRUE) {
                    foreach ($flavorResult->objects as $flavorAsset) {
                        if ($flavorAsset->flavorParamsId == 0) {
                            $assetsToDownload[$entry->id] = [
                                'flavorAsset' => $flavorAsset,
                                'name' => $entry->name,
                            ];
                        }
                    }
                } else {
                    foreach ($flavorResult->objects as $flavorAsset) {
                        if ($flavorAsset->flavorParamsId == 414701) {
                            $assetsToDownload2[$entry->id] = [
                                'flavorAsset' => $flavorAsset,
                                'name' => $entry->name,
                            ];
                        }
                    }
                }
            }

            $assetsToDownload = array_values($assetsToDownload);

            // Get the 't' parameter from the request
            $t = $request->query->get('t', 0); // Default to 0 if 't' is not provided

            foreach ($assetsToDownload as $idx => $asset) {
                if ($idx >= $t && $idx < ($t + 70)) {
                    ray($asset['name']);
                    $this->downloadFLVService->downloadAsset($asset);
                }
            }

            //      foreach ($assetsToDownload2 as $asset) {
            //          $this->downloadFLVService->downloadAsset($asset);
            //      }

            return new JsonResponse(['message' => 'Videos downloaded successfully']);
        }
        catch (\Exception $e) {
            return new JsonResponse(['error' => 'Failed to download videos: ' . $e->getMessage()], 500);
        }
    }

    public function downloadFlavor(): JsonResponse {
        $response = ''; //$this->downloadFLVService->downloadFlavors();
        return new JsonResponse($response);
    }

    public function getFlavors(Request $request): JsonResponse {
        $entryId = $request->query->get('entry_id');
        $response = $this->kalturaClientService->getFlavors($entryId);
        return new JsonResponse($response);
    }

}
