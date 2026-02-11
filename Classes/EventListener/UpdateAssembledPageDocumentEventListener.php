<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\EventListener;

use Doctrine\DBAL\Exception;
use MeineKrankenkasse\Typo3SearchAlgolia\ContentExtractor;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\AfterDocumentAssembledEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\CategoryRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\ContentRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\ContentIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\PageIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\TypoScriptService;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Event listener for enhancing page documents with site-specific information and content.
 *
 * This listener responds to AfterDocumentAssembledEvent events that are dispatched
 * after a page document has been assembled. It enhances the document by adding
 * various page-specific fields:
 * - Site domain (for identifying which site the page belongs to)
 * - Last changed timestamp (for sorting and filtering by modification date)
 * - Page URL (for linking to the page in search results)
 * - Page content (optionally, by aggregating content from all content elements on the page)
 *
 * The content aggregation is particularly important for full-text search, as it allows
 * users to find pages based on the text within their content elements, not just the
 * page's own metadata. This content is only included if the indexing service is
 * configured to include content elements (isIncludeContentElements() returns true).
 *
 * These enhancements make page search results more useful by providing both
 * the necessary metadata for display and filtering, as well as the actual
 * content for comprehensive full-text search capabilities.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class UpdateAssembledPageDocumentEventListener implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    /**
     * The current document assembled event being processed.
     *
     * This property stores the AfterDocumentAssembledEvent that is currently
     * being processed by the listener. It's set in the __invoke method and
     * used by other methods, particularly getPageContent(), to access event
     * information like the indexing service configuration. This allows helper
     * methods to retrieve configuration values without needing to pass the
     * event as a parameter to each method.
     */
    private AfterDocumentAssembledEvent $event;

    /**
     * Initializes the event listener with required dependencies.
     *
     * This constructor injects the services needed for enhancing page documents:
     * - The SiteFinder service for retrieving site information and generating URLs
     * - The ContentRepository for accessing content elements on pages
     * - The TypoScriptService for retrieving configuration values
     *
     * These services work together to provide the necessary information for
     * enhancing page documents with site-specific information and content,
     * making search results more useful and comprehensive.
     *
     * @param SiteFinder         $siteFinder         The TYPO3 site finder service
     * @param ContentRepository  $contentRepository  The repository for accessing content elements
     * @param CategoryRepository $categoryRepository The repository for accessing system categories
     * @param TypoScriptService  $typoScriptService  The service for accessing TypoScript configuration
     */
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly ContentRepository $contentRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly TypoScriptService $typoScriptService,
    ) {
    }

    /**
     * Processes the document assembled event and enhances page documents.
     *
     * This method is automatically called by the event dispatcher when an
     * AfterDocumentAssembledEvent is dispatched. It performs the following tasks:
     *
     * 1. Verifies that the event is for a page document (using instanceof PageIndexer)
     * 2. Stores the event for later use by helper methods
     * 3. Extracts the document, record data, and page ID from the event
     * 4. Retrieves the site information for the page using the SiteFinder
     * 5. Adds various page-specific fields to the document:
     *    - Site domain (for identifying which site the page belongs to)
     *    - Last changed timestamp (for sorting and filtering by modification date)
     *    - Page URL (for linking to the page in search results)
     * 6. If the indexing service is configured to include content elements,
     *    adds the aggregated content from all content elements on the page
     *
     * These enhancements make page search results more useful by providing both
     * the necessary metadata for display and filtering, as well as the actual
     * content for comprehensive full-text search capabilities.
     *
     * @param AfterDocumentAssembledEvent $event The document assembled event containing the document and record data
     *
     * @return void
     */
    public function __invoke(AfterDocumentAssembledEvent $event): void
    {
        if (!($event->getIndexer() instanceof PageIndexer)) {
            return;
        }

        $this->event = $event;

        $document = $event->getDocument();
        $record   = $event->getRecord();
        $pageId   = (int)($record['uid'] ?? 0);

        // Skip if page ID is invalid
        if ($pageId === 0) {
            return;
        }

        $site     = $this->getSite($pageId);

        // Set page-related fields
        $document->setField(
            'site',
            $this->getSiteDomain($site)
        );

        // Get all assigned categories
        $categories = $this->categoryRepository->findAssignedToRecord(
            $this->event->getIndexer()->getTable(),
            $pageId
        );

        // Add categories
        // TODO Add categories as default to each document?
        $document->setField(
            'categories',
            array_unique(
                array_values(
                    array_column(
                        $categories,
                        'title',
                        'uid'
                    )
                )
            )
        );

        if (($record['SYS_LASTCHANGED'] ?? 0) !== 0) {
            $document->setField(
                'changed',
                $record['SYS_LASTCHANGED']
            );
        }

        if ($site instanceof Site) {
            $document->setField(
                'url',
                $this->getPageUrl(
                    $site,
                    $pageId
                )
            );
        }

        if ($event->getIndexingService()->isIncludeContentElements()) {
            try {
                $document->setField(
                    'content',
                    $this->getPageContent($pageId)
                );
            } catch (Exception $exception) {
                // Log the error but continue without content to avoid blocking the entire indexing process
                $this->logger?->warning(
                    'Failed to extract page content for indexing',
                    [
                        'pageId' => $pageId,
                        'exception' => $exception->getMessage(),
                        'trace' => $exception->getTraceAsString(),
                    ]
                );
            }
        }
    }

    /**
     * Retrieves the site object for a specific page ID.
     *
     * This helper method uses the SiteFinder service to retrieve the Site object
     * that corresponds to the given page ID. The Site object contains information
     * about the site configuration, including the domain and routing settings,
     * which are needed to generate proper URLs to pages.
     *
     * If the page ID doesn't correspond to any known site (e.g., if the page
     * doesn't exist or isn't part of a site configuration), a NullSite object
     * is returned as a fallback to prevent errors.
     *
     * @param int $pageId The ID of the page to find the site for
     *
     * @return SiteInterface The site object for the page, or a NullSite if not found
     */
    private function getSite(int $pageId): SiteInterface
    {
        try {
            return $this->siteFinder->getSiteByPageId($pageId);
        } catch (SiteNotFoundException) {
            return new NullSite();
        }
    }

    /**
     * Extracts the domain name from a site object.
     *
     * This helper method retrieves the domain name (host) from the base URL
     * of the provided site object. The domain name is used to populate the
     * 'site' field in the document, which helps identify which site the
     * page belongs to in search results.
     *
     * This information is particularly useful in multi-site installations
     * where the same page ID might exist on different sites, ensuring that
     * search results link to the correct site.
     *
     * @param SiteInterface $site The site object to extract the domain from
     *
     * @return string The domain name of the site
     */
    private function getSiteDomain(SiteInterface $site): string
    {
        return $site->getBase()->getHost();
    }

    /**
     * Generates a URL to a specific page.
     *
     * This helper method creates a complete URL that points to a page in the TYPO3
     * site. It uses the site's router to generate the URL, which ensures that the
     * URL is properly formatted according to the site's configuration, including
     * any language or routing settings.
     *
     * The generated URL is used to populate the 'url' field in the document,
     * which allows search results to link directly to the page, providing a
     * seamless user experience when navigating from search results to content.
     *
     * @param Site $site   The site object containing routing information
     * @param int  $pageId The ID of the page to generate a URL for
     *
     * @return string The complete URL to the page
     */
    private function getPageUrl(Site $site, int $pageId): string
    {
        return (string) $site
            ->getRouter()
            ->generateUri($pageId);
    }

    /**
     * Aggregates and processes the content from all content elements on a page.
     *
     * This helper method retrieves all content elements on the specified page,
     * extracts their content, and combines it into a single text string for
     * full-text indexing. The process involves:
     *
     * 1. Retrieving content elements from the database using the ContentRepository
     * 2. Filtering elements based on content element types if configured in the indexing service
     * 3. Extracting content from specific fields of each content element based on TypoScript configuration
     * 4. Cleaning and normalizing the content using ContentExtractor
     * 5. Combining all content into a single string with proper spacing between elements
     *
     * This aggregated content is essential for comprehensive full-text search,
     * allowing users to find pages based on the text within any content element
     * on the page, not just the page's own metadata.
     *
     * @param int $pageId The ID of the page to extract content from
     *
     * @return string|null The combined and processed content from all content elements, or null if empty
     *
     * @throws Exception If a database error occurs during content retrieval
     */
    private function getPageContent(int $pageId): ?string
    {
        // Get the default configured mapping fields
        $contentElementFields = $this->typoScriptService
            ->getFieldMappingByType(ContentIndexer::TABLE);

        if ($contentElementFields === []) {
            return null;
        }

        $contentElementTypes = GeneralUtility::trimExplode(
            ',',
            $this->event->getIndexingService()->getContentElementTypes(),
            true
        );

        $rows = $this->contentRepository
            ->findAllByPid(
                $pageId,
                array_keys($contentElementFields),
                $contentElementTypes
            );

        $content = '';

        foreach ($rows as $row) {
            foreach ($row as $fieldContent) {
                $content .= $fieldContent . "\n";
            }
        }

        $content = ContentExtractor::cleanHtml($content);

        return $content !== '' ? $content : null;
    }
}
