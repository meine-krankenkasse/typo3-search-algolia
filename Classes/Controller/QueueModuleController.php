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
use Psr\Http\Message\ServerRequestInterface;

/**
 * QueueModuleController.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class QueueModuleController extends AbstractBaseModuleController
{
    /**
     * The default action to call.
     *
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        return parent::indexAction($request);
    }

    /**
     * @return ResponseInterface
     */
    protected function htmlResponse(): ResponseInterface
    {
        return $this->moduleTemplate->renderResponse('QueueModule');
    }
}
