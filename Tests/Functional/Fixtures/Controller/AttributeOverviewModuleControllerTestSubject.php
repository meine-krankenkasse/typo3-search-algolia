<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Fixtures\Controller;

use MeineKrankenkasse\Typo3SearchAlgolia\Controller\AttributeOverviewModuleController;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Extbase\Mvc\RequestInterface;

/**
 * Test subject for AttributeOverviewModuleController.
 *
 * Exposes the protected indexAction() entry point and the protected
 * request/moduleTemplate properties, so a test can drive indexAction()
 * directly with a real, DI-resolved controller instance without going
 * through the full Extbase dispatch machinery - mirrors the exact same
 * pattern already established for AbstractBaseModuleController by
 * AbstractBaseModuleControllerTestSubject (see that class for the
 * rationale: ModuleTemplate is final and cannot be mocked, so a real one
 * is built via ModuleTemplateFactory and injected here instead of relying
 * on initializeAction(), which additionally pulls in backend user/page
 * permission dependencies unrelated to indexAction()'s own behaviour).
 */
class AttributeOverviewModuleControllerTestSubject extends AttributeOverviewModuleController
{
    public function setRequestForTest(RequestInterface $request): void
    {
        $this->request = $request;
    }

    public function setModuleTemplateForTest(ModuleTemplate $moduleTemplate): void
    {
        $this->moduleTemplate = $moduleTemplate;
    }

    /**
     * Normally set by AbstractBaseModuleController::initializeAction() from
     * the request's 'id', which this test subject bypasses entirely (see
     * class docblock), so a test asserting on the rendered 'pageUid' (the
     * shared record-selector form's hidden 'id' field) must set it here
     * explicitly instead.
     */
    public function setPageUidForTest(int $pageUid): void
    {
        $this->pageUid = $pageUid;
    }

    public function callIndexAction(): ResponseInterface
    {
        return $this->indexAction();
    }
}
