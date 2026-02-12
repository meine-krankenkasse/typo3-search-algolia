<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Event;

use MeineKrankenkasse\Typo3SearchAlgolia\Event\CreateUniqueDocumentIdEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngineInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CreateUniqueDocumentIdEvent.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(CreateUniqueDocumentIdEvent::class)]
class CreateUniqueDocumentIdEventTest extends TestCase
{
    /**
     * Tests that all constructor arguments are correctly stored and returned
     * by their respective getter methods. Verifies that getSearchEngine() returns
     * the same mock instance, getTableName() returns 'pages', and getRecordUid()
     * returns 42, matching the values passed during construction.
     */
    #[Test]
    public function gettersReturnConstructorValues(): void
    {
        $searchEngineMock = $this->createMock(SearchEngineInterface::class);

        $event = new CreateUniqueDocumentIdEvent($searchEngineMock, 'pages', 42);

        self::assertSame($searchEngineMock, $event->getSearchEngine());
        self::assertSame('pages', $event->getTableName());
        self::assertSame(42, $event->getRecordUid());
    }

    /**
     * Tests that the document ID defaults to an empty string when no document ID
     * has been explicitly set. Verifies that getDocumentId() returns an empty
     * string immediately after constructing the event.
     */
    #[Test]
    public function documentIdIsEmptyByDefault(): void
    {
        $event = new CreateUniqueDocumentIdEvent(
            $this->createMock(SearchEngineInterface::class),
            'pages',
            1
        );

        self::assertSame('', $event->getDocumentId());
    }

    /**
     * Tests that setDocumentId() correctly stores the provided value and that
     * getDocumentId() subsequently returns that exact string. Uses a composite
     * document ID format ('typo3_search_algolia:pages:1') to verify the value
     * is stored and retrieved without modification.
     */
    #[Test]
    public function setDocumentIdStoresValue(): void
    {
        $event = new CreateUniqueDocumentIdEvent(
            $this->createMock(SearchEngineInterface::class),
            'pages',
            1
        );

        $event->setDocumentId('typo3_search_algolia:pages:1');

        self::assertSame('typo3_search_algolia:pages:1', $event->getDocumentId());
    }

    /**
     * Tests that setDocumentId() returns the event instance itself, enabling
     * fluent method chaining. Verifies that the return value of setDocumentId()
     * is the same object reference as the original event.
     */
    #[Test]
    public function setDocumentIdReturnsSelfForChaining(): void
    {
        $event = new CreateUniqueDocumentIdEvent(
            $this->createMock(SearchEngineInterface::class),
            'pages',
            1
        );

        $result = $event->setDocumentId('test');

        self::assertSame($event, $result);
    }
}
