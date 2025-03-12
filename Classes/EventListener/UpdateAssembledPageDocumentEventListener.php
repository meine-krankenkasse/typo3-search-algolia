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
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\PageIndexer;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequestFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Typolink\LinkFactory;
use TYPO3\CMS\Frontend\Typolink\UnableToLinkException;

/**
 * Class UpdateAssembledPageDocumentEventListener.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class UpdateAssembledPageDocumentEventListener
{
    /**
     * @var SiteFinder
     */
    private SiteFinder $siteFinder;

    /**
     * @var ServerRequestFactory
     */
    private ServerRequestFactory $serverRequestFactory;

    /**
     * @var LinkFactory
     */
    private LinkFactory $linkFactory;

    /**
     * Constructor.
     *
     * @param SiteFinder           $siteFinder
     * @param ServerRequestFactory $serverRequestFactory
     * @param LinkFactory          $linkFactory
     */
    public function __construct(
        SiteFinder $siteFinder,
        ServerRequestFactory $serverRequestFactory,
        LinkFactory $linkFactory,
    ) {
        $this->siteFinder           = $siteFinder;
        $this->serverRequestFactory = $serverRequestFactory;
        $this->linkFactory          = $linkFactory;
    }

    /**
     * Invoke the event listener.
     *
     * @param AfterDocumentAssembledEvent $event
     */
    public function __invoke(AfterDocumentAssembledEvent $event): void
    {
        if (!($event->getIndexer() instanceof PageIndexer)) {
            return;
        }

        $document = $event->getDocument();
        $record   = $event->getRecord();
        $pageId   = $record['uid'];

        // Set page related fields
        $document
            ->setField('site', $this->getSiteDomain($pageId))
            ->setField('url', $this->getPageUrl($pageId))
            ->setField('created', $record['crdate'])
            ->setField('changed', $record['SYS_LASTCHANGED']);
    }

    /**
     * Returns the site identified by the given page ID.
     *
     * @param int $pageId
     *
     * @return Site
     */
    private function getSite(int $pageId): Site
    {
        return $this->siteFinder->getSiteByPageId($pageId);
    }

    /**
     * Returns the domain of the site identified by the given page ID.
     *
     * @param int $pageId
     *
     * @return string
     */
    private function getSiteDomain(int $pageId): string
    {
        return $this->getSite($pageId)->getBase()->getHost();
    }

    /**
     * Returns a new ContentObjectRenderer instance.
     *
     * @return ContentObjectRenderer
     */
    private function getContentObjectRenderer(): ContentObjectRenderer
    {
        return GeneralUtility::makeInstance(ContentObjectRenderer::class);
    }

    /**
     * Creates and returns a new ServerRequest instance.
     *
     * @param int $pageId
     *
     * @return ServerRequestInterface
     */
    private function createServerRequest(int $pageId): ServerRequestInterface
    {
        return $this->serverRequestFactory
            ->createServerRequest(
                'GET',
                $this->getSite($pageId)->getBase()
            )
            ->withAttribute(
                'applicationType',
                SystemEnvironmentBuilder::REQUESTTYPE_FE
            );
    }

    /**
     * Returns the page URL.
     *
     * @param int $pageId
     *
     * @return string
     *
     * @throws UnableToLinkException
     */
    private function getPageUrl(int $pageId): string
    {
        $contentObject = $this->getContentObjectRenderer();
        $contentObject->setRequest($this->createServerRequest($pageId));
        $contentObject->start([]);

        return $this->linkFactory
            ->create(
                '',
                [
                    'parameter'                 => $pageId,
                    'linkAccessRestrictedPages' => '1',
                ],
                $contentObject
            )
            ->getUrl();
    }
}
