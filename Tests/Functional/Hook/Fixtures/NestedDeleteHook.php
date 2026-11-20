<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Hook\Fixtures;

use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A DataHandler hook that runs a nested DataHandler for a second record while the
 * delete command of a first record is in progress, the way a container element
 * extension deletes its children.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final class NestedDeleteHook
{
    /**
     * The page whose delete command starts the nested command.
     */
    public static int $triggerUid = 0;

    /**
     * The page the nested command deletes.
     */
    public static int $nestedUid = 0;

    /**
     * Whether the nested command was started already.
     */
    private static bool $started = false;

    /**
     * Resets the state of the fixture.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$triggerUid = 0;
        self::$nestedUid  = 0;
        self::$started    = false;
    }

    /**
     * Starts the nested delete command once, before DataHandler deletes the record.
     *
     * @param string                         $table            The table of the record
     * @param int                            $id               The UID of the record
     * @param array<string, int|string|null> $recordToDelete   The record
     * @param bool                           $recordWasDeleted Whether a hook deleted the record already
     * @param DataHandler                    $dataHandler      The outer DataHandler
     *
     * @return void
     */
    public function processCmdmap_deleteAction(
        string $table,
        int $id,
        array $recordToDelete,
        bool &$recordWasDeleted,
        DataHandler $dataHandler,
    ): void {
        if (
            self::$started
            || ($table !== 'pages')
            || ($id !== self::$triggerUid)
        ) {
            return;
        }

        self::$started = true;

        $nested = GeneralUtility::makeInstance(DataHandler::class);
        $nested->start([], ['pages' => [self::$nestedUid => ['delete' => 1]]], $dataHandler->BE_USER);
        $nested->process_cmdmap();
    }
}
