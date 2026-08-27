<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Fixtures\Controller;

use MeineKrankenkasse\Typo3SearchAlgolia\Controller\AbstractBaseModuleController;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Extbase\Mvc\RequestInterface;

/**
 * Test subject for AbstractBaseModuleController.
 *
 * Exposes the protected errorAction() entry point and the protected
 * request/moduleTemplate properties, so a test can drive errorAction()
 * directly without going through the full Extbase dispatch machinery
 * (initializeAction() pulls in backend user/page permission dependencies
 * unrelated to the controller-name resolution being tested).
 */
class AbstractBaseModuleControllerTestSubject extends AbstractBaseModuleController
{
    public function setRequestForTest(RequestInterface $request): void
    {
        $this->request = $request;
    }

    public function setModuleTemplateForTest(ModuleTemplate $moduleTemplate): void
    {
        $this->moduleTemplate = $moduleTemplate;
    }

    public function callErrorAction(): ResponseInterface
    {
        return $this->errorAction();
    }
}
