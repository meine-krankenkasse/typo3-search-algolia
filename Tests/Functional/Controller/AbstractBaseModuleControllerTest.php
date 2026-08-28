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
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\RequestInterface;
use TYPO3Fluid\Fluid\View\Exception\InvalidTemplateResourceException;

/**
 * Functional tests for AbstractBaseModuleController.
 *
 * As functional tests, these resolve a real ModuleTemplate/Fluid view through
 * the real DI container instead of mocking it (ModuleTemplate is a final
 * TYPO3 class and cannot be mocked at all - and mocking it was exactly what
 * let the original bug in 37992fc ship unnoticed, per that fix commit's own
 * message).
 *
 * errorActionResolvesTheRequestedControllerNameIntoTheTemplatePath() is the
 * actual regression proof for 37992fc: it names a controller whose Error
 * template does not exist and asserts the resulting exception names exactly
 * that controller, proving the request's controller name (not the abstract
 * base class's own name) drives template resolution.
 * errorActionRendersTheConcreteControllersOwnErrorTemplate() is a
 * complementary smoke test that the real, shipped QueueModule/Error.html
 * template resolves and renders without error - it also errors against the
 * pre-37992fc code (missing AbstractBaseModule/Error.html), but would NOT
 * catch a near-miss regression where the resolved name is wrong-but-still-an-
 * existing-template (e.g. hardcoded to 'AdministrationModule/Error' instead
 * of resolved dynamically) - that scenario is only caught by
 * errorActionResolvesTheRequestedControllerNameIntoTheTemplatePath(), which
 * uses a name with no matching template at all.
 * errorActionFallsBackToAbstractBaseModuleErrorTemplateWithoutExtbaseAttribute()
 * covers the other branch (no ExtbaseRequestParameters attribute at all).
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

        // ModuleTemplate::prepareRender() also reads $GLOBALS['BE_USER'] (via
        // BackendUtility::getUpdateSignalDetails()), which needs an
        // authenticated user session, again not set up by a plain functional
        // test bootstrap.
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/be_users.csv');

        $this->setUpBackendUser(1);
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
     *
     * Also stubs the 'applicationType' and 'normalizedParams' attributes since
     * TYPO3 v14's PageRendererBackendSetupTrait now requires both to resolve
     * the backend favicon on the request passed into ModuleTemplateFactory::create().
     */
    private function createModuleRequest(?ExtbaseRequestParameters $extbaseRequestParameters): RequestInterface
    {
        $route = new Route(
            '/module/typo3-search-algolia/queue',
            [
                'packageName' => 'meine-krankenkasse/typo3-search-algolia',
            ],
        );

        $normalizedParams = NormalizedParams::createFromServerParams($_SERVER);

        $requestMock = self::createStub(RequestInterface::class);
        $requestMock
            ->method('getAttribute')
            ->willReturnMap([
                ['route', null, $route],
                ['extbase', null, $extbaseRequestParameters],
                ['applicationType', null, SystemEnvironmentBuilder::REQUESTTYPE_BE],
                ['normalizedParams', null, $normalizedParams],
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
     * Tests that errorAction() resolves the template name from the request's
     * ExtbaseRequestParameters controller name, not from this abstract base
     * class's own name ("AbstractBaseModule", the original bug's hardcoded
     * value). Uses a controller name with no matching Error template on disk
     * so the resulting exception message names exactly that controller,
     * proving the requested name - not a fallback - drove resolution. This
     * is the actual regression proof for 37992fc; a real controller name
     * like "QueueModule" cannot serve that purpose here because its
     * Error.html is byte-identical to AdministrationModule/Error.html, so
     * either would render successfully regardless of which one was picked.
     */
    #[Test]
    public function errorActionResolvesTheRequestedControllerNameIntoTheTemplatePath(): void
    {
        $extbaseRequestParameters = (new ExtbaseRequestParameters())
            ->setControllerName('NonExistentModule');

        $request = $this->createModuleRequest($extbaseRequestParameters);

        $subject = $this->createSubject();
        $subject->setRequestForTest($request);
        $subject->setModuleTemplateForTest(
            $this->get(ModuleTemplateFactory::class)->create($request),
        );

        $this->expectException(InvalidTemplateResourceException::class);
        $this->expectExceptionMessageMatches('#NonExistentModule/Error#');

        $subject->callErrorAction();
    }

    /**
     * Smoke test that the real, shipped QueueModule/Error.html template
     * actually exists and renders successfully through a real Fluid view.
     * Does not by itself discriminate the bug fixed in 37992fc, see the
     * class docblock;
     * errorActionResolvesTheRequestedControllerNameIntoTheTemplatePath()
     * carries that proof.
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
            $this->get(ModuleTemplateFactory::class)->create($request),
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
            $this->get(ModuleTemplateFactory::class)->create($request),
        );

        $this->expectException(InvalidTemplateResourceException::class);
        $this->expectExceptionMessageMatches('#AbstractBaseModule/Error#');

        $subject->callErrorAction();
    }
}
