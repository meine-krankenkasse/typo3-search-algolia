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
 * Class UpdateAssembledContentElementDocumentEventListener.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class UpdateAssembledContentElementDocumentEventListener
{
    /**
     * @var SiteFinder
     */
    private readonly SiteFinder $siteFinder;

    /**
     * Constructor.
     *
     * @param SiteFinder $siteFinder
     */
    public function __construct(SiteFinder $siteFinder)
    {
        $this->siteFinder = $siteFinder;
    }

    /**
     * Invoke the event listener.
     *
     * @param AfterDocumentAssembledEvent $event
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
        $document
            ->setField('site', $this->getSiteDomain($site));

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
     * Returns the site identified by the given page ID.
     *
     * @param int $pageId
     *
     * @return SiteInterface
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
     * Returns the domain of the site identified by the given page ID.
     *
     * @param SiteInterface $site
     *
     * @return string
     */
    private function getSiteDomain(SiteInterface $site): string
    {
        return $site->getBase()->getHost();
    }

    /**
     * Returns the page URL including anchor to the content element.
     *
     * @param Site $site
     * @param int  $pageId
     * @param int  $contentElementId
     *
     * @return string
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
