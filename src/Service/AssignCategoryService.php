<?php

namespace Drupal\kaltura_transcoder\Service;

use Kaltura\Client\Type\CategoryEntry;
use Kaltura\Client\Type\CategoryEntryFilter;
use Kaltura\Client\Type\FilterPager;

/**
 * Service to assign a category to an array of entry IDs.
 */
class AssignCategoryService {

    protected KalturaClientService $kalturaClientService;

    public function __construct(KalturaClientService $kalturaClientService) {
        $this->kalturaClientService = $kalturaClientService;
    }

    /**
     * Assigns a category ID to an array of entry IDs.
     *
     * @param array $entryIds
     *   An array of entry IDs.
     * @param int $categoryId
     *   The category ID to assign.
     *
     * @return array
     *   An array with the status of each entry assignment.
     */
    public function assignCategory(array $entryIds, int $categoryId): array {
        $results = [];
        ray($categoryId);
        ray(count($entryIds));
        $success = [];
        $fail = [];
        $alreadyExists = [];
        foreach ($entryIds as $entryId) {
            try {
                // Check if the entry is already in the category
                $categoryFilter = new CategoryEntryFilter();
                $categoryFilter->entryIdEqual = $entryId;
                $categoryFilter->categoryIdEqual = $categoryId;
                $categoryPager = new FilterPager();
                $categoryPager->pageSize = 1;

                $categoryResult = $this->kalturaClientService->getClient()->getCategoryEntryService()->listAction($categoryFilter, $categoryPager);
                if (count($categoryResult->objects) === 0) {
                    // If the entry is not in the category, add it
                    $categoryEntry = new CategoryEntry();
                    $categoryEntry->entryId = $entryId;
                    $categoryEntry->categoryId = $categoryId;
                    $this->kalturaClientService->getClient()->getCategoryEntryService()->add($categoryEntry);
                    $results[$entryId] = 'success';
                    $success[] = $entryId;
                } else {
                    $results[$entryId] = 'already exists';
                    $alreadyExists[] = $entryId;
                }


            }
            catch (\Exception $e) {
                $fail[] = $entryId;
                $results[$entryId] = 'error: ' . $e->getMessage();
            }
        }

        ray(['success' => count($success)]);
        ray(['Exists' => count($alreadyExists)]);
        ray(['Fail' => count($fail)]);

        return $results;
    }
}
