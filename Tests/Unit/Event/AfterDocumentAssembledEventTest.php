<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Event;

use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\AfterDocumentAssembledEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Model\Document;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AfterDocumentAssembledEvent.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(AfterDocumentAssembledEvent::class)]
class AfterDocumentAssembledEventTest extends TestCase
{
    /**
     * Tests that all constructor arguments are correctly stored and returned
     * by their respective getter methods. Verifies that getDocument(), getIndexer(),
     * getIndexingService(), and getRecord() each return the exact same instance
     * or value that was passed to the constructor.
     */
    #[Test]
    public function gettersReturnConstructorValues(): void
    {
        $documentMock        = $this->createMock(Document::class);
        $indexerMock         = $this->createMock(IndexerInterface::class);
        $indexingServiceMock = $this->createMock(IndexingService::class);
        $record              = ['uid' => 1, 'title' => 'Test'];

        $event = new AfterDocumentAssembledEvent(
            $documentMock,
            $indexerMock,
            $indexingServiceMock,
            $record
        );

        self::assertSame($documentMock, $event->getDocument());
        self::assertSame($indexerMock, $event->getIndexer());
        self::assertSame($indexingServiceMock, $event->getIndexingService());
        self::assertSame($record, $event->getRecord());
    }
}
