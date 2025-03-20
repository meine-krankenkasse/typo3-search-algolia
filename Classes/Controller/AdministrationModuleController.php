<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Controller;

use Psr\Http\Message\ResponseInterface;

/**
 * AdministrationModuleController.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class AdministrationModuleController extends AbstractBaseModuleController
{
    /**
     * The default action to call.
     *
     * @return ResponseInterface
     */
    public function indexAction(): ResponseInterface
    {
        if (!$this->checkDatabaseAvailability()) {
            return $this->forwardFlashMessage('error.databaseAvailability');
        }

        return $this->moduleTemplate->renderResponse();
    }
}
