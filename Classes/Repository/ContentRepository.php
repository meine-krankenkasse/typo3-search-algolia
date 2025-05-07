<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Repository;

use Doctrine\DBAL\Exception;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The content element repository.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
readonly class ContentRepository
{
    /**
     * @var ConnectionPool
     */
    private ConnectionPool $connectionPool;

    /**
     * Constructor.
     *
     * @param ConnectionPool $connectionPool
     */
    public function __construct(ConnectionPool $connectionPool)
    {
        $this->connectionPool = $connectionPool;
    }

    /**
     * Finds all records for given page ID. Returns only the given columns for each record.
     *
     * @param int      $pageId              The UID of the page to be processed
     * @param string[] $columns
     * @param string[] $contentElementTypes A list of content elements used to filter the result
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws Exception
     */
    public function findAllByPid(int $pageId, array $columns, array $contentElementTypes = []): array
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable('tt_content');

        $constraints = [
            $queryBuilder->expr()->eq(
                'pid',
                $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)
            ),
        ];

        if ($contentElementTypes !== []) {
            // Filter by CType
            $constraints[] = $queryBuilder->expr()->in(
                'CType',
                $queryBuilder->quoteArrayBasedValueListToStringList($contentElementTypes)
            );
        }

        return $queryBuilder
            ->select(...$columns)
            ->from('tt_content')
            ->where(...$constraints)
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
