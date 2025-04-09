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
 * The demand object which holds all information to get the correct records.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class QueueDemand
{
    /**
     * @var int
     */
    private int $indexingService = 0;

    /**
     * The UIDs of the selected indexers.
     *
     * @var string[]
     */
    private array $indexingServices = [];

    /**
     * @return int
     */
    public function getIndexingService(): int
    {
        return $this->indexingService;
    }

    /**
     * @param int $indexingService
     *
     * @return QueueDemand
     */
    public function setIndexingService(int $indexingService): QueueDemand
    {
        $this->indexingService = $indexingService;

        return $this;
    }

    /**
     * @return string[]
     */
    public function getIndexingServices(): array
    {
        return $this->indexingServices;
    }

    /**
     * @param string[] $indexingServices
     *
     * @return QueueDemand
     */
    public function setIndexingServices(array $indexingServices): QueueDemand
    {
        $this->indexingServices = $indexingServices;

        return $this;
    }
}
