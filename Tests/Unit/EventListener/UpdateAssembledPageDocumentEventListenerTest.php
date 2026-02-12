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
use MeineKrankenkasse\Typo3SearchAlgolia\ContentExtractor;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\AfterDocumentAssembledEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\EventListener\UpdateAssembledPageDocumentEventListener;
use MeineKrankenkasse\Typo3SearchAlgolia\Model\Document;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\CategoryLookupInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\ContentRepositoryInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\AbstractIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\ContentIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\PageIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\TypoScriptServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Unit tests for UpdateAssembledPageDocumentEventListener.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(UpdateAssembledPageDocumentEventListener::class)]
#[UsesClass(AfterDocumentAssembledEvent::class)]
#[UsesClass(Document::class)]
#[UsesClass(AbstractIndexer::class)]
#[UsesClass(ContentExtractor::class)]
#[UsesClass(PageRepository::class)]
class UpdateAssembledPageDocumentEventListenerTest extends TestCase
{
    /**
     * Tests that the listener does nothing when the indexer
     * is not a PageIndexer instance.
     */
    #[Test]
    public function invokeDoesNothingForNonPageIndexer(): void
    {
        $siteFinderMock = $this->createMock(SiteFinder::class);
        $siteFinderMock->expects(self::never())
            ->method('getSiteByPageId');

        $contentRepositoryMock  = $this->createMock(ContentRepositoryInterface::class);
        $categoryRepositoryMock = $this->createMock(CategoryLookupInterface::class);
        $typoScriptServiceMock  = $this->createMock(TypoScriptServiceInterface::class);

        $indexerMock         = $this->createMock(ContentIndexer::class);
        $indexingServiceMock = $this->createMock(IndexingService::class);
        $record              = ['uid' => 42, 'pid' => 10];
        $document            = new Document($indexerMock, $record);

        $event = new AfterDocumentAssembledEvent(
            $document,
            $indexerMock,
            $indexingServiceMock,
            $record
        );

        $listener = new UpdateAssembledPageDocumentEventListener(
            $siteFinderMock,
            $contentRepositoryMock,
            $categoryRepositoryMock,
            $typoScriptServiceMock,
        );
        $listener($event);

        self::assertEmpty($document->getFields());
    }

    /**
     * Tests that the listener sets the site domain and URL fields
     * on the document for a valid page record.
     */
    #[Test]
    public function invokeSetsSiteDomainAndUrlForPage(): void
    {
        $routerMock = $this->createMock(RouterInterface::class);
        $routerMock->method('generateUri')
            ->with(42)
            ->willReturn(new Uri('https://www.example.com/test-page'));

        $siteMock = $this->createMock(Site::class);
        $siteMock->method('getBase')
            ->willReturn(new Uri('https://www.example.com'));
        $siteMock->method('getRouter')
            ->willReturn($routerMock);

        $siteFinderMock = $this->createMock(SiteFinder::class);
        $siteFinderMock->method('getSiteByPageId')
            ->with(42)
            ->willReturn($siteMock);

        $categoryRepositoryMock = $this->createMock(CategoryLookupInterface::class);
        $categoryRepositoryMock->method('findAssignedToRecord')
            ->willReturn([]);

        $contentRepositoryMock = $this->createMock(ContentRepositoryInterface::class);
        $typoScriptServiceMock = $this->createMock(TypoScriptServiceInterface::class);

        $indexerMock = $this->createMock(PageIndexer::class);
        $indexerMock->method('getTable')
            ->willReturn('pages');

        $indexingServiceMock = $this->createMock(IndexingService::class);
        $indexingServiceMock->method('isIncludeContentElements')
            ->willReturn(false);

        $record   = ['uid' => 42, 'pid' => 0, 'SYS_LASTCHANGED' => 1700000000];
        $document = new Document($indexerMock, $record);

        $event = new AfterDocumentAssembledEvent(
            $document,
            $indexerMock,
            $indexingServiceMock,
            $record
        );

        $listener = new UpdateAssembledPageDocumentEventListener(
            $siteFinderMock,
            $contentRepositoryMock,
            $categoryRepositoryMock,
            $typoScriptServiceMock,
        );
        $listener($event);

        self::assertSame('www.example.com', $document->getFields()['site']);
        self::assertSame('https://www.example.com/test-page', $document->getFields()['url']);
        self::assertSame(1700000000, $document->getFields()['changed']);
    }

    /**
     * Tests that the listener falls back to a NullSite when the
     * SiteFinder throws a SiteNotFoundException.
     */
    #[Test]
    public function invokeUsesNullSiteWhenSiteNotFound(): void
    {
        $siteFinderMock = $this->createMock(SiteFinder::class);
        $siteFinderMock->method('getSiteByPageId')
            ->willThrowException(new SiteNotFoundException('Not found'));

        $categoryRepositoryMock = $this->createMock(CategoryLookupInterface::class);
        $categoryRepositoryMock->method('findAssignedToRecord')
            ->willReturn([]);

        $contentRepositoryMock = $this->createMock(ContentRepositoryInterface::class);
        $typoScriptServiceMock = $this->createMock(TypoScriptServiceInterface::class);

        $indexerMock = $this->createMock(PageIndexer::class);
        $indexerMock->method('getTable')
            ->willReturn('pages');

        $indexingServiceMock = $this->createMock(IndexingService::class);
        $indexingServiceMock->method('isIncludeContentElements')
            ->willReturn(false);

        $record   = ['uid' => 42, 'pid' => 0, 'SYS_LASTCHANGED' => 0];
        $document = new Document($indexerMock, $record);

        $event = new AfterDocumentAssembledEvent(
            $document,
            $indexerMock,
            $indexingServiceMock,
            $record
        );

        $listener = new UpdateAssembledPageDocumentEventListener(
            $siteFinderMock,
            $contentRepositoryMock,
            $categoryRepositoryMock,
            $typoScriptServiceMock,
        );
        $listener($event);

        // NullSite returns empty host
        self::assertSame('', $document->getFields()['site']);
        // No 'url' field since NullSite is not instanceof Site
        self::assertArrayNotHasKey('url', $document->getFields());
        // SYS_LASTCHANGED is 0, so 'changed' field should not be set
        self::assertArrayNotHasKey('changed', $document->getFields());
    }

    /**
     * Tests that the listener adds content element text to the document
     * when includeContentElements is enabled on the indexing service.
     */
    #[Test]
    public function invokeAddsContentElementsWhenIncludeContentEnabled(): void
    {
        $routerMock = $this->createMock(RouterInterface::class);
        $routerMock->method('generateUri')
            ->willReturn(new Uri('https://www.example.com/page'));

        $siteMock = $this->createMock(Site::class);
        $siteMock->method('getBase')
            ->willReturn(new Uri('https://www.example.com'));
        $siteMock->method('getRouter')
            ->willReturn($routerMock);

        $siteFinderMock = $this->createMock(SiteFinder::class);
        $siteFinderMock->method('getSiteByPageId')
            ->willReturn($siteMock);

        $categoryRepositoryMock = $this->createMock(CategoryLookupInterface::class);
        $categoryRepositoryMock->method('findAssignedToRecord')
            ->willReturn([]);

        $contentRepositoryMock = $this->createMock(ContentRepositoryInterface::class);
        $contentRepositoryMock
            ->expects(self::once())
            ->method('findAllByPid')
            ->with(42, ['bodytext'], [])
            ->willReturn([
                ['bodytext' => 'Hello World'],
                ['bodytext' => 'More content here'],
            ]);

        $typoScriptServiceMock = $this->createMock(TypoScriptServiceInterface::class);
        $typoScriptServiceMock->method('getFieldMappingByType')
            ->with('tt_content')
            ->willReturn(['bodytext' => 'bodytext']);

        $indexerMock = $this->createMock(PageIndexer::class);
        $indexerMock->method('getTable')
            ->willReturn('pages');

        $indexingServiceMock = $this->createMock(IndexingService::class);
        $indexingServiceMock->method('isIncludeContentElements')
            ->willReturn(true);
        $indexingServiceMock->method('getContentElementTypes')
            ->willReturn('');

        $record   = ['uid' => 42, 'pid' => 0, 'SYS_LASTCHANGED' => 0];
        $document = new Document($indexerMock, $record);

        $event = new AfterDocumentAssembledEvent(
            $document,
            $indexerMock,
            $indexingServiceMock,
            $record
        );

        $listener = new UpdateAssembledPageDocumentEventListener(
            $siteFinderMock,
            $contentRepositoryMock,
            $categoryRepositoryMock,
            $typoScriptServiceMock,
        );
        $listener($event);

        self::assertArrayHasKey('content', $document->getFields());
        self::assertStringContainsString('Hello World', $document->getFields()['content']);
        self::assertStringContainsString('More content here', $document->getFields()['content']);
    }

    /**
     * Tests that the listener does not add content when the
     * TypoScript field mapping for tt_content is empty.
     */
    #[Test]
    public function invokeDoesNotAddContentWhenFieldMappingEmpty(): void
    {
        $routerMock = $this->createMock(RouterInterface::class);
        $routerMock->method('generateUri')
            ->willReturn(new Uri('https://www.example.com/page'));

        $siteMock = $this->createMock(Site::class);
        $siteMock->method('getBase')
            ->willReturn(new Uri('https://www.example.com'));
        $siteMock->method('getRouter')
            ->willReturn($routerMock);

        $siteFinderMock = $this->createMock(SiteFinder::class);
        $siteFinderMock->method('getSiteByPageId')
            ->willReturn($siteMock);

        $categoryRepositoryMock = $this->createMock(CategoryLookupInterface::class);
        $categoryRepositoryMock->method('findAssignedToRecord')
            ->willReturn([]);

        $contentRepositoryMock = $this->createMock(ContentRepositoryInterface::class);
        $contentRepositoryMock
            ->expects(self::never())
            ->method('findAllByPid');

        $typoScriptServiceMock = $this->createMock(TypoScriptServiceInterface::class);
        $typoScriptServiceMock->method('getFieldMappingByType')
            ->with('tt_content')
            ->willReturn([]);

        $indexerMock = $this->createMock(PageIndexer::class);
        $indexerMock->method('getTable')
            ->willReturn('pages');

        $indexingServiceMock = $this->createMock(IndexingService::class);
        $indexingServiceMock->method('isIncludeContentElements')
            ->willReturn(true);

        $record   = ['uid' => 42, 'pid' => 0, 'SYS_LASTCHANGED' => 0];
        $document = new Document($indexerMock, $record);

        $event = new AfterDocumentAssembledEvent(
            $document,
            $indexerMock,
            $indexingServiceMock,
            $record
        );

        $listener = new UpdateAssembledPageDocumentEventListener(
            $siteFinderMock,
            $contentRepositoryMock,
            $categoryRepositoryMock,
            $typoScriptServiceMock,
        );
        $listener($event);

        // content field is not set (null removes field), or key does not exist
        self::assertArrayNotHasKey('content', $document->getFields());
    }
}
