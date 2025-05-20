<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\Dto;

/**
 * Data Transfer Object (DTO) for queue filtering and selection criteria.
 *
 * This class serves as a container for parameters that control which indexing
 * services should be used when refreshing the indexing queue. It:
 * - Stores the selected indexing service UID for single-service operations
 * - Maintains a list of indexing service UIDs for multi-service operations
 *
 * The QueueDemand object is typically populated from form submissions in the
 * queue module's user interface and then used to determine which indexing
 * services should be used to refresh the queue.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class QueueDemand
{
    /**
     * UID of a single selected indexing service.
     *
     * This property stores the unique identifier of a single indexing service
     * that has been selected for queue operations. It is typically used when
     * performing operations on a specific indexing service, such as refreshing
     * the queue for just one service configuration.
     *
     * @var int
     */
    private int $indexingService = 0;

    /**
     * Array of selected indexing service UIDs.
     *
     * This property stores an array of indexing service unique identifiers
     * that have been selected for batch queue operations. It is typically used
     * when performing operations on multiple indexing services at once, such as
     * refreshing the queue for several service configurations simultaneously.
     *
     * @var string[]
     */
    private array $indexingServices = [];

    /**
     * Returns the UID of a single selected indexing service.
     *
     * This getter method provides access to the unique identifier of a single
     * indexing service that has been selected for queue operations. This value
     * is typically used when performing operations on a specific indexing service,
     * such as refreshing the queue for just one service configuration.
     *
     * @return int The indexing service UID
     */
    public function getIndexingService(): int
    {
        return $this->indexingService;
    }

    /**
     * Sets the UID of a single selected indexing service.
     *
     * This setter method allows specifying which single indexing service should
     * be used for queue operations. Setting this value indicates that operations
     * should be performed on just one specific indexing service configuration.
     *
     * @param int $indexingService The indexing service UID
     *
     * @return QueueDemand The current instance for method chaining
     */
    public function setIndexingService(int $indexingService): QueueDemand
    {
        $this->indexingService = $indexingService;

        return $this;
    }

    /**
     * Returns the array of selected indexing service UIDs.
     *
     * This getter method provides access to the list of indexing service unique
     * identifiers that have been selected for batch queue operations. This array
     * is typically used when performing operations on multiple indexing services
     * at once, such as refreshing the queue for several service configurations.
     *
     * @return string[] Array of indexing service UIDs
     */
    public function getIndexingServices(): array
    {
        return $this->indexingServices;
    }

    /**
     * Sets the array of selected indexing service UIDs.
     *
     * This setter method allows specifying multiple indexing services that should
     * be used for batch queue operations. Setting this array indicates that
     * operations should be performed on multiple indexing service configurations
     * simultaneously, which is useful for bulk processing.
     *
     * @param string[] $indexingServices Array of indexing service UIDs
     *
     * @return QueueDemand The current instance for method chaining
     */
    public function setIndexingServices(array $indexingServices): QueueDemand
    {
        $this->indexingServices = $indexingServices;

        return $this;
    }
}
