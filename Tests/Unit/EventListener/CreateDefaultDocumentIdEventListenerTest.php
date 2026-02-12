<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\EventListener;

use MeineKrankenkasse\Typo3SearchAlgolia\Event\CreateUniqueDocumentIdEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\EventListener\CreateDefaultDocumentIdEventListener;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngineInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CreateDefaultDocumentIdEventListener.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(CreateDefaultDocumentIdEventListener::class)]
class CreateDefaultDocumentIdEventListenerTest extends TestCase
{
    /**
     * Tests that invoking the listener with a "pages" table and record UID 42
     * sets the document ID to the expected format "typo3_search_algolia:pages:42".
     * Verifies the listener correctly composes the document ID from the extension
     * name, table name, and record UID.
     */
    #[Test]
    public function invokesSetsDocumentIdInExpectedFormat(): void
    {
        $event = new CreateUniqueDocumentIdEvent(
            $this->createMock(SearchEngineInterface::class),
            'pages',
            42
        );

        $listener = new CreateDefaultDocumentIdEventListener();
        $listener($event);

        self::assertSame('typo3_search_algolia:pages:42', $event->getDocumentId());
    }

    /**
     * Tests that invoking the listener with the "tt_content" table and record UID 123
     * produces the document ID "typo3_search_algolia:tt_content:123". Verifies the
     * listener handles the content table name correctly in the composed document ID.
     */
    #[Test]
    public function invokesWithContentTable(): void
    {
        $event = new CreateUniqueDocumentIdEvent(
            $this->createMock(SearchEngineInterface::class),
            'tt_content',
            123
        );

        $listener = new CreateDefaultDocumentIdEventListener();
        $listener($event);

        self::assertSame('typo3_search_algolia:tt_content:123', $event->getDocumentId());
    }

    /**
     * Tests that invoking the listener with the "sys_file_metadata" table and record
     * UID 7 produces the document ID "typo3_search_algolia:sys_file_metadata:7".
     * Verifies the listener handles the file metadata table name correctly in the
     * composed document ID.
     */
    #[Test]
    public function invokesWithFileMetadataTable(): void
    {
        $event = new CreateUniqueDocumentIdEvent(
            $this->createMock(SearchEngineInterface::class),
            'sys_file_metadata',
            7
        );

        $listener = new CreateDefaultDocumentIdEventListener();
        $listener($event);

        self::assertSame('typo3_search_algolia:sys_file_metadata:7', $event->getDocumentId());
    }
}
