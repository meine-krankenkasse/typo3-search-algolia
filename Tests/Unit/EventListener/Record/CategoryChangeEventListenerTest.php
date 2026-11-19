<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\EventListener\Record;

use MeineKrankenkasse\Typo3SearchAlgolia\DataHandling\CategoryRecordRequeuerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordUpdateEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Record\CategoryChangeEventListener;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CategoryChangeEventListener.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(CategoryChangeEventListener::class)]
#[UsesClass(DataHandlerRecordUpdateEvent::class)]
final class CategoryChangeEventListenerTest extends TestCase
{
    /**
     * The requeuer the listener delegates to.
     */
    private CategoryRecordRequeuerInterface&MockObject $requeuerMock;

    /**
     * The listener under test.
     */
    private CategoryChangeEventListener $subject;

    /**
     * Creates the listener with a mocked requeuer.
     *
     * @return void
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->requeuerMock = $this->createMock(CategoryRecordRequeuerInterface::class);
        $this->subject      = new CategoryChangeEventListener($this->requeuerMock);
    }

    /**
     * A saved category requeues its records.
     */
    #[Test]
    public function requeuesTheRecordsOfAnUpdatedCategory(): void
    {
        $this->requeuerMock
            ->expects(self::once())
            ->method('requeue')
            ->with(39);

        ($this->subject)(
            new DataHandlerRecordUpdateEvent(
                'sys_category',
                39,
                ['title' => 'Service'],
            ),
        );
    }

    /**
     * Updates of any other table are left to the regular record handling.
     */
    #[Test]
    public function ignoresOtherTables(): void
    {
        $this->requeuerMock->expects(self::never())->method('requeue');

        ($this->subject)(
            new DataHandlerRecordUpdateEvent(
                'pages',
                39,
                ['title' => 'Service'],
            ),
        );
    }
}
