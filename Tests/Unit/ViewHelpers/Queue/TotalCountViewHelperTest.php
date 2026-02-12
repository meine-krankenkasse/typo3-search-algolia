<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\ViewHelpers\Queue;

use MeineKrankenkasse\Typo3SearchAlgolia\ViewHelpers\Queue\TotalCountViewHelper;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;

/**
 * Unit tests for TotalCountViewHelper.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(TotalCountViewHelper::class)]
class TotalCountViewHelperTest extends UnitTestCase
{
    private TotalCountViewHelper $viewHelper;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->viewHelper = new TotalCountViewHelper();
        $this->viewHelper->setRenderingContext(
            $this->createMock(RenderingContextInterface::class)
        );
        $this->viewHelper->initializeArguments();
    }

    /**
     * Tests that render() returns the sum of all 'count' values from the statistics
     * array. Given three entries with counts 10, 5, and 3, the method should return 18.
     */
    #[Test]
    public function renderReturnsSumOfAllCounts(): void
    {
        $this->viewHelper->setArguments([
            'statistics' => [
                ['count' => 10, 'tableName' => 'pages'],
                ['count' => 5, 'tableName' => 'tt_content'],
                ['count' => 3, 'tableName' => 'sys_file_metadata'],
            ],
        ]);

        self::assertSame(18, $this->viewHelper->render());
    }

    /**
     * Tests that render() returns zero when the statistics array is provided
     * but empty, meaning there are no table entries to sum up.
     */
    #[Test]
    public function renderReturnsZeroForEmptyStatistics(): void
    {
        $this->viewHelper->setArguments([
            'statistics' => [],
        ]);

        self::assertSame(0, $this->viewHelper->render());
    }

    /**
     * Tests that render() returns zero when the 'statistics' key is not present
     * in the arguments array at all, handling the missing argument gracefully.
     */
    #[Test]
    public function renderReturnsZeroWhenStatisticsNotSet(): void
    {
        $this->viewHelper->setArguments([]);

        self::assertSame(0, $this->viewHelper->render());
    }

    /**
     * Tests that render() correctly returns the count value when the statistics
     * array contains only a single entry, verifying it does not require multiple
     * entries to produce a result.
     */
    #[Test]
    public function renderReturnsSingleCount(): void
    {
        $this->viewHelper->setArguments([
            'statistics' => [
                ['count' => 42, 'tableName' => 'pages'],
            ],
        ]);

        self::assertSame(42, $this->viewHelper->render());
    }
}
