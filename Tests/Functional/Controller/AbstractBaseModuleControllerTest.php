<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Controller;

use MeineKrankenkasse\Typo3SearchAlgolia\Controller\AbstractBaseModuleController;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Fixtures\Controller\AbstractBaseModuleControllerTestSubject;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\RequestInterface;
use TYPO3Fluid\Fluid\View\Exception\InvalidTemplateResourceException;

/**
 * Functional tests for AbstractBaseModuleController.
 *
 * Covers the errorAction() controller-name resolution fixed in 37992fc, found
 * only by actually loading the extension into a real running TYPO3 13.4.34
 * instance: the concrete controller's own template ("QueueModule/Error"), not
 * this abstract base class's name, must be resolved from the request. As a
 * functional test, this resolves a real ModuleTemplate/Fluid view through the
 * real DI container instead of mocking it (ModuleTemplate is a final TYPO3
 * class and cannot be mocked at all - and mocking it was exactly what let the
 * original bug ship unnoticed, per the fix commit's own message).
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(AbstractBaseModuleController::class)]
final class AbstractBaseModuleControllerTest extends AbstractFunctionalTestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // ModuleTemplate reads $GLOBALS['LANG'] directly (getLanguageService()),
        // which a plain functional test bootstrap does not populate on its own.
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');

        // ModuleTemplate's doc header also reads $GLOBALS['BE_USER'] (via
        // getModuleData(), which needs an initialized user session), again not
        // set up by a plain functional test bootstrap.
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/be_users.csv');

        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $backendUser->createUserSession(
            $this->getBackendUserRecordFromDatabase(1) ?? []
        );

        $GLOBALS['BE_USER'] = $backendUser;
    }

    private function createSubject(): AbstractBaseModuleControllerTestSubject
    {
        return new AbstractBaseModuleControllerTestSubject(
            $this->get(ModuleTemplateFactory::class),
            $this->get(IconFactory::class),
        );
    }

    /**
     * Builds a request carrying the 'route' attribute needed by
     * BackendViewFactory to resolve this extension's own template root
     * paths (mirrors AbstractBaseModuleController::updateRoutePackageName()),
     * plus the given 'extbase' attribute.
     */
    private function createModuleRequest(?ExtbaseRequestParameters $extbaseRequestParameters): RequestInterface
    {
        $route = new Route('/module/typo3-search-algolia/queue', [
            'packageName' => 'meine-krankenkasse/typo3-search-algolia',
        ]);

        $requestMock = self::createMock(RequestInterface::class);
        $requestMock
            ->method('getAttribute')
            ->willReturnMap([
                ['route', null, $route],
                ['extbase', null, $extbaseRequestParameters],
            ]);
        $requestMock
            ->method('getParsedBody')
            ->willReturn(null);
        $requestMock
            ->method('getQueryParams')
            ->willReturn([]);

        return $requestMock;
    }

    /**
     * Tests that errorAction() renders the concrete controller's own error
     * template (Resources/Private/Templates/QueueModule/Error.html) when the
     * request carries an ExtbaseRequestParameters attribute naming that
     * controller, resolved through a real Fluid view.
     */
    #[Test]
    public function errorActionRendersTheConcreteControllersOwnErrorTemplate(): void
    {
        $extbaseRequestParameters = (new ExtbaseRequestParameters())
            ->setControllerName('QueueModule');

        $request = $this->createModuleRequest($extbaseRequestParameters);

        $subject = $this->createSubject();
        $subject->setRequestForTest($request);
        $subject->setModuleTemplateForTest(
            $this->get(ModuleTemplateFactory::class)->create($request)
        );

        $response = $subject->callErrorAction();

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Tests that errorAction() falls back to resolving "AbstractBaseModule/Error"
     * when the request carries no ExtbaseRequestParameters attribute, rather
     * than passing an empty controller name to renderResponse(). No template
     * exists under that name (it was the original bug's own, wrong, hardcoded
     * value), so a real Fluid resolution attempt throws
     * InvalidTemplateResourceException naming exactly that path - proving the
     * fallback branch resolved to "AbstractBaseModule/Error", not silently to
     * something else (e.g. an empty string).
     */
    #[Test]
    public function errorActionFallsBackToAbstractBaseModuleErrorTemplateWithoutExtbaseAttribute(): void
    {
        $request = $this->createModuleRequest(null);

        $subject = $this->createSubject();
        $subject->setRequestForTest($request);
        $subject->setModuleTemplateForTest(
            $this->get(ModuleTemplateFactory::class)->create($request)
        );

        $this->expectException(InvalidTemplateResourceException::class);
        $this->expectExceptionMessageMatches('#AbstractBaseModule/Error#');

        $subject->callErrorAction();
    }
}
