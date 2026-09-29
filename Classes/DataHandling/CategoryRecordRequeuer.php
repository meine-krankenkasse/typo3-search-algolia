<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\DataHandling;

use Doctrine\DBAL\ArrayParameterType;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\CollectCategoryRecordsEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\CategoryRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepositoryInterface;
use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Exception\Page\PageNotFoundException;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function array_key_exists;

/**
 * Requeues the records of a system category after the category changed.
 *
 * Indexed documents can carry values derived from their categories (e.g. the
 * category titles, or a ranking value configured on the category). Changing a
 * category does not touch the assigned records themselves, so without this
 * their documents would keep the outdated values until each record is edited
 * or the whole index is rebuilt.
 *
 * The records are only requeued, not removed from the search index first, so
 * they stay searchable until the queue worker has indexed them again.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final class CategoryRecordRequeuer implements CategoryRecordRequeuerInterface
{
    /**
     * The records assigned to a category before it was saved, keyed by the
     * category UID and then by table.
     *
     * @var array<int, array<string, list<int>>>
     */
    private array $rememberedRecordUids = [];

    /**
     * Constructor.
     *
     * @param CategoryRepository       $categoryRepository The repository resolving category assignments and subcategories
     * @param RecordHandlerInterface   $recordHandler      The record handler providing the responsible indexers
     * @param PageRepositoryInterface  $pageRepository     The repository resolving the root page of a page tree
     * @param EventDispatcherInterface $eventDispatcher    The dispatcher for the CollectCategoryRecordsEvent
     * @param ConnectionPool           $connectionPool     The connection pool for looking up the records' pages
     */
    public function __construct(
        private readonly CategoryRepository $categoryRepository,
        private readonly RecordHandlerInterface $recordHandler,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Remembers the records currently assigned to a category and its subcategories.
     *
     * @param int $categoryUid The UID of the category about to be saved
     *
     * @return void
     */
    #[Override]
    public function rememberAssignedRecords(int $categoryUid): void
    {
        $this->rememberedRecordUids[$categoryUid] = $this->findAssignedRecordUids($categoryUid);
    }

    /**
     * Drops the records remembered for a category.
     *
     * @param int $categoryUid The UID of the category
     *
     * @return void
     */
    #[Override]
    public function forgetRememberedRecords(int $categoryUid): void
    {
        unset($this->rememberedRecordUids[$categoryUid]);
    }

    /**
     * Requeues the records of a changed category.
     *
     * @param int $categoryUid The UID of the changed category
     *
     * @return void
     */
    #[Override]
    public function requeue(int $categoryUid): void
    {
        $collectEvent = new CollectCategoryRecordsEvent(
            $categoryUid,
            $this->findAssignedRecordUids($categoryUid),
        );

        foreach ($this->rememberedRecordUids[$categoryUid] ?? [] as $tableName => $recordUids) {
            $collectEvent->addRecordUids($tableName, $recordUids);
        }

        $this->forgetRememberedRecords($categoryUid);

        $this->eventDispatcher->dispatch($collectEvent);

        foreach ($collectEvent->getRecordUids() as $tableName => $recordUids) {
            // Assignments left behind by a removed extension point to a table TYPO3 no longer knows
            if (!isset($GLOBALS['TCA'][$tableName])) {
                continue;
            }

            $this->requeueRecords($tableName, $recordUids);
        }
    }

    /**
     * Returns the records assigned to a category and its subcategories.
     *
     * @param int $categoryUid The UID of the category
     *
     * @return array<string, list<int>> The record UIDs, keyed by table name
     */
    private function findAssignedRecordUids(int $categoryUid): array
    {
        return $this->categoryRepository->findRecordUidsByCategories(
            [
                $categoryUid,
                ...$this->categoryRepository->findDescendantUids($categoryUid),
            ]
        );
    }

    /**
     * Requeues records of one table through the indexers responsible for the
     * page tree each record belongs to. Like the full queue rebuild, the
     * indexers leave out pages below a hidden subpage of a recursively
     * selected page tree.
     *
     * @param string    $tableName  The table the records belong to
     * @param list<int> $recordUids The UIDs of the records to requeue
     *
     * @return void
     */
    private function requeueRecords(
        string $tableName,
        array $recordUids,
    ): void {
        foreach ($this->groupByRootPage($tableName, $recordUids) as $rootPageId => $rootPageRecordUids) {
            $indexerInstanceGenerator = $this->recordHandler
                ->createIndexerGenerator(
                    $rootPageId,
                    $tableName
                );

            foreach ($indexerInstanceGenerator as $indexerInstance) {
                $indexerInstance
                    ->withExcludeHiddenPages(true)
                    ->dequeueMultiple($rootPageRecordUids)
                    ->enqueueMultiple($rootPageRecordUids);
            }
        }
    }

    /**
     * Groups records by the root page of the page tree they belong to.
     *
     * The records are looked up in one query, and each distinct parent page is
     * resolved to its root page only once, so a category assigned to many
     * pages below few parents costs few queries. Records that no longer exist,
     * or whose page tree can no longer be resolved, are left out.
     *
     * @param string    $tableName  The table the records belong to
     * @param list<int> $recordUids The UIDs of the records to group
     *
     * @return array<int, list<int>> The record UIDs, keyed by root page UID
     */
    private function groupByRootPage(
        string $tableName,
        array $recordUids,
    ): array {
        $isPageTable          = ($tableName === 'pages');
        $rootPageIdsByPid     = [];
        $recordUidsByRootPage = [];

        foreach ($this->findRecordsWithPid($tableName, $recordUids, $isPageTable) as $record) {
            // A site root page is the root of its own page tree
            if (
                $isPageTable
                && ((int) $record['is_siteroot'] === 1)
            ) {
                $recordUidsByRootPage[(int) $record['uid']][] = (int) $record['uid'];

                continue;
            }

            $pid = (int) $record['pid'];

            if (!array_key_exists($pid, $rootPageIdsByPid)) {
                $rootPageIdsByPid[$pid] = $this->resolveRootPageId($pid);
            }

            if ($rootPageIdsByPid[$pid] === null) {
                continue;
            }

            $recordUidsByRootPage[$rootPageIdsByPid[$pid]][] = (int) $record['uid'];
        }

        return $recordUidsByRootPage;
    }

    /**
     * Returns the UID and parent page of the given records that still exist.
     *
     * @param string    $tableName   The table the records belong to
     * @param list<int> $recordUids  The UIDs of the records to look up
     * @param bool      $isPageTable Whether the table is the pages table, whose site root flag is needed too
     *
     * @return list<array<string, int|string|null>> The records, ordered by UID
     */
    private function findRecordsWithPid(
        string $tableName,
        array $recordUids,
        bool $isPageTable,
    ): array {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable($tableName);

        $queryBuilder
            ->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $fields = $isPageTable ? ['uid', 'pid', 'is_siteroot'] : ['uid', 'pid'];

        return $queryBuilder
            ->select(...$fields)
            ->from($tableName)
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter(
                        $recordUids,
                        ArrayParameterType::INTEGER
                    )
                )
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Resolves the root page of the page tree a page belongs to.
     *
     * @param int $pageId The UID of the page
     *
     * @return int|null The root page UID, or null if the page tree cannot be resolved
     */
    private function resolveRootPageId(int $pageId): ?int
    {
        try {
            return $this->pageRepository->getRootPageId($pageId);
        } catch (PageNotFoundException) {
            return null;
        }
    }
}
