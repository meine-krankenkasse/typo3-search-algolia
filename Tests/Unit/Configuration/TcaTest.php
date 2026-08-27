<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;

/**
 * Unit tests for the extension's TCA configuration files.
 *
 * Guards against the l10n_parent copy-paste regression fixed in 918dfbc,
 * where a domain model's TCA pointed 'allowed' at an unrelated foreign
 * table instead of itself. jscpd happened to catch that one by accident;
 * this test catches it directly.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
// Exercises TCA config arrays, not a class.
#[CoversNothing]
final class TcaTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function tableNameProvider(): array
    {
        return [
            'IndexingService' => ['tx_typo3searchalgolia_domain_model_indexingservice'],
            'SearchEngine'    => ['tx_typo3searchalgolia_domain_model_searchengine'],
        ];
    }

    /**
     * Tests that a domain model's l10n_parent field is configured to allow
     * only records from its own table, not a foreign one.
     */
    #[Test]
    #[DataProvider('tableNameProvider')]
    public function l10nParentAllowsOnlyItsOwnTable(string $tableName): void
    {
        $tca = require dirname(__DIR__, 3) . '/Configuration/TCA/' . $tableName . '.php';

        self::assertSame(
            $tableName,
            $tca['columns']['l10n_parent']['config']['allowed'] ?? null,
        );
    }
}
