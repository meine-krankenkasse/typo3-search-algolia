<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\EventListener;

use GuzzleHttp\Psr7\Uri;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\AfterDocumentAssembledEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\EventListener\UpdateAssembledContentElementDocumentEventListener;
use MeineKrankenkasse\Typo3SearchAlgolia\Model\Document;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\ContentIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\PageIndexer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Unit tests for UpdateAssembledContentElementDocumentEventListener.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(UpdateAssembledContentElementDocumentEventListener::class)]
class UpdateAssembledContentElementDocumentEventListenerTest extends TestCase
{
    #[Test]
    public function invokeDoesNothingForNonContentIndexer(): void
    {
        $siteFinderMock = $this->createMock(SiteFinder::class);
        $siteFinderMock->expects(self::never())
            ->method('getSiteByPageId');

        $indexerMock         = $this->createMock(PageIndexer::class);
        $indexingServiceMock = $this->createMock(IndexingService::class);
        $record              = ['pid' => 10, 'uid' => 42];
        $document            = new Document($indexerMock, $record);

        $event = new AfterDocumentAssembledEvent(
            $document,
            $indexerMock,
            $indexingServiceMock,
            $record
        );

        $listener = new UpdateAssembledContentElementDocumentEventListener($siteFinderMock);
        $listener($event);

        self::assertEmpty($document->getFields());
    }

    #[Test]
    public function invokeSetsSiteDomainAndUrlForContentElement(): void
    {
        $routerMock = $this->createMock(RouterInterface::class);
        $routerMock->method('generateUri')
            ->with(10, [], '#c42')
            ->willReturn(new Uri('https://www.example.com/page#c42'));

        $siteMock = $this->createMock(Site::class);
        $siteMock->method('getBase')
            ->willReturn(new Uri('https://www.example.com'));
        $siteMock->method('getRouter')
            ->willReturn($routerMock);

        $siteFinderMock = $this->createMock(SiteFinder::class);
        $siteFinderMock->method('getSiteByPageId')
            ->with(10)
            ->willReturn($siteMock);

        $indexerMock         = $this->createMock(ContentIndexer::class);
        $indexingServiceMock = $this->createMock(IndexingService::class);
        $record              = ['pid' => 10, 'uid' => 42];
        $document            = new Document($indexerMock, $record);

        $event = new AfterDocumentAssembledEvent(
            $document,
            $indexerMock,
            $indexingServiceMock,
            $record
        );

        $listener = new UpdateAssembledContentElementDocumentEventListener($siteFinderMock);
        $listener($event);

        self::assertSame('www.example.com', $document->getFields()['site']);
        self::assertSame('https://www.example.com/page#c42', $document->getFields()['url']);
    }

    #[Test]
    public function invokeUsesNullSiteWhenSiteNotFound(): void
    {
        $siteFinderMock = $this->createMock(SiteFinder::class);
        $siteFinderMock->method('getSiteByPageId')
            ->willThrowException(new SiteNotFoundException('Not found'));

        $indexerMock         = $this->createMock(ContentIndexer::class);
        $indexingServiceMock = $this->createMock(IndexingService::class);
        $record              = ['pid' => 10, 'uid' => 42];
        $document            = new Document($indexerMock, $record);

        $event = new AfterDocumentAssembledEvent(
            $document,
            $indexerMock,
            $indexingServiceMock,
            $record
        );

        $listener = new UpdateAssembledContentElementDocumentEventListener($siteFinderMock);
        $listener($event);

        // NullSite returns empty host
        self::assertSame('', $document->getFields()['site']);
        // No 'url' field since NullSite is not instanceof Site
        self::assertArrayNotHasKey('url', $document->getFields());
    }
}
