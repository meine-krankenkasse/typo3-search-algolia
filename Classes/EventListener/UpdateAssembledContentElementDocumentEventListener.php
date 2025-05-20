<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\EventListener;

use MeineKrankenkasse\Typo3SearchAlgolia\Event\AfterDocumentAssembledEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\ContentIndexer;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Event listener for enhancing content element documents with site-specific information.
 *
 * This listener responds to AfterDocumentAssembledEvent events that are dispatched
 * after a content element document has been assembled. It enhances the document by:
 * - Adding the site domain to the document's 'site' field
 * - Adding a URL to the content element (including anchor) to the document's 'url' field
 *
 * These enhancements make content element search results more useful by providing
 * the necessary information to generate proper links to the content elements,
 * allowing users to navigate directly to the specific content element on a page
 * when clicking on a search result.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
readonly class UpdateAssembledContentElementDocumentEventListener
{
    /**
     * TYPO3 site finder service for retrieving site information.
     *
     * This property stores the SiteFinder service that is used to retrieve
     * site information based on page IDs. It's essential for determining
     * the site domain and generating URLs to content elements, which are
     * added to the document for proper linking in search results.
     *
     * @var SiteFinder
     */
    private SiteFinder $siteFinder;

    /**
     * Initializes the event listener with the site finder service.
     *
     * This constructor injects the TYPO3 SiteFinder service that is used
     * to retrieve site information based on page IDs. The site finder is
     * essential for determining the site domain and generating URLs to
     * content elements, which are added to the document for proper linking
     * in search results.
     *
     * @param SiteFinder $siteFinder The TYPO3 site finder service
     */
    public function __construct(SiteFinder $siteFinder)
    {
        $this->siteFinder = $siteFinder;
    }

    /**
     * Processes the document assembled event and enhances content element documents.
     *
     * This method is automatically called by the event dispatcher when an
     * AfterDocumentAssembledEvent is dispatched. It performs the following tasks:
     *
     * 1. Verifies that the event is for a content element document (using instanceof ContentIndexer)
     * 2. Extracts the document, record data, page ID, and content element ID from the event
     * 3. Retrieves the site information for the page using the SiteFinder
     * 4. Adds the site domain to the document's 'site' field
     * 5. If a valid Site object is found, adds a URL to the content element (including anchor)
     *    to the document's 'url' field
     *
     * These enhancements make content element search results more useful by providing
     * the necessary information to generate proper links to the content elements.
     *
     * @param AfterDocumentAssembledEvent $event The document assembled event containing the document and record data
     *
     * @return void
     */
    public function __invoke(AfterDocumentAssembledEvent $event): void
    {
        if (!($event->getIndexer() instanceof ContentIndexer)) {
            return;
        }

        $document         = $event->getDocument();
        $record           = $event->getRecord();
        $pageId           = $record['pid'];
        $site             = $this->getSite($pageId);
        $contentElementId = $record['uid'];

        // Set content element related fields
        $document->setField(
            'site',
            $this->getSiteDomain($site)
        );

        if ($site instanceof Site) {
            $document->setField(
                'url',
                $this->getPageUrl(
                    $site,
                    $pageId,
                    $contentElementId
                )
            );
        }
    }

    /**
     * Retrieves the site object for a specific page ID.
     *
     * This helper method uses the SiteFinder service to retrieve the Site object
     * that corresponds to the given page ID. The Site object contains information
     * about the site configuration, including the domain and routing settings,
     * which are needed to generate proper URLs to content elements.
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
     * content element belongs to in search results.
     *
     * This information is particularly useful in multi-site installations
     * where the same content element ID might exist on different sites,
     * ensuring that search results link to the correct site.
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
     * Generates a URL to a specific content element on a page.
     *
     * This helper method creates a complete URL that points directly to a
     * content element on a page. It uses the site's router to generate the
     * base URL to the page, then appends an anchor fragment (#c[elementId])
     * that browsers will use to scroll to the specific content element when
     * the page loads.
     *
     * The generated URL is used to populate the 'url' field in the document,
     * which allows search results to link directly to the specific content
     * element rather than just to the page, providing a better user experience
     * by taking users directly to the relevant content they searched for.
     *
     * @param Site $site             The site object containing routing information
     * @param int  $pageId           The ID of the page containing the content element
     * @param int  $contentElementId The ID of the content element to link to
     *
     * @return string The complete URL to the content element, including the anchor fragment
     */
    private function getPageUrl(Site $site, int $pageId, int $contentElementId): string
    {
        return (string) $site
            ->getRouter()
            ->generateUri(
                $pageId,
                [],
                '#c' . $contentElementId
            );
    }
}
