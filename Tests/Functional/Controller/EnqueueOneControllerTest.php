<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Controller;

use MeineKrankenkasse\Typo3SearchAlgolia\Controller\EnqueueOneController;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordUpdateEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\RegistersEventSpyTrait;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Throwable;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;

use function file_put_contents;
use function is_array;
use function is_string;

/**
 * Functional tests for EnqueueOneController.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(EnqueueOneController::class)]
final class EnqueueOneControllerTest extends AbstractFunctionalTestCase
{
    use RegistersEventSpyTrait;

    /**
     * Imports the backend users and creates a file in a real storage.
     *
     * @return void
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/be_users_without_rights.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/sys_filemounts_storage_root.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/be_groups_metadata_editors.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/be_users_metadata_editors.csv');

        file_put_contents($this->instancePath . '/fileadmin/test.pdf', 'content');
    }

    /**
     * Tests that a backend user without the permission to edit the metadata of
     * the file is rejected by the route itself, although the context menu
     * would not have offered the entry to that user.
     */
    #[Test]
    public function mainActionRejectsUserWithoutMetadataPermission(): void
    {
        $spy = $this->registerEventSpy(DataHandlerRecordUpdateEvent::class);

        $response = $this->callRoute(2, 'test.pdf');

        self::assertSame(403, $response);
        self::assertSame([], $spy->events, 'Nothing is dispatched for the refused user');
    }

    /**
     * Tests that a backend user who may edit the metadata of the file is not
     * rejected, so that the rejection above is decided by the permissions.
     */
    #[Test]
    public function mainActionAcceptsUserWithMetadataPermission(): void
    {
        $spy = $this->registerEventSpy(DataHandlerRecordUpdateEvent::class);

        $response = $this->callRoute(1, 'test.pdf');

        self::assertSame(200, $response);
        self::assertCount(1, $spy->events, 'The update of the metadata record is dispatched once');
    }

    /**
     * Tests that an editor who is no admin passes the route when the file lies
     * in a writable file mount and the user may modify the metadata table, so
     * that the decision is made by the permissions and not by the admin flag.
     */
    #[Test]
    public function mainActionAcceptsAnEditorWithAFileMountAndTheTableRight(): void
    {
        $spy = $this->registerEventSpy(DataHandlerRecordUpdateEvent::class);

        self::assertSame(200, $this->callRoute(4, 'test.pdf'));
        self::assertCount(1, $spy->events);
    }

    /**
     * Tests that an editor with a file mount but without the right to modify
     * the metadata table is refused.
     */
    #[Test]
    public function mainActionRefusesAnEditorWithoutTheTableRight(): void
    {
        $spy = $this->registerEventSpy(DataHandlerRecordUpdateEvent::class);

        self::assertSame(403, $this->callRoute(5, 'test.pdf'));
        self::assertSame([], $spy->events);
    }

    /**
     * Tests that an editor who may modify the metadata table but has no file mount
     * is refused, so that the table right alone does not open the route for a file
     * the user has no access to.
     */
    #[Test]
    public function mainActionRefusesAnEditorWithTheTableRightButWithoutAFileMount(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/be_groups_editor_without_mount.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/be_users_editor_without_mount.csv');

        $spy = $this->registerEventSpy(DataHandlerRecordUpdateEvent::class);

        self::assertSame(403, $this->callRoute(3, 'test.pdf'));
        self::assertSame([], $spy->events);
    }

    /**
     * Tests that a target that cannot be resolved to a file is answered like a
     * refused one, so that the answer does not tell whether it exists.
     */
    #[Test]
    public function mainActionAnswersAnUnknownTargetLikeARefusedOne(): void
    {
        $spy = $this->registerEventSpy(DataHandlerRecordUpdateEvent::class);

        self::assertSame(403, $this->callRoute(1, 'does-not-exist.pdf'));
        self::assertSame([], $spy->events);
    }

    /**
     * Tests that a folder and a target that is not a string are refused as well.
     */
    #[Test]
    public function mainActionRefusesAFolderAndAnArrayTarget(): void
    {
        self::assertSame(403, $this->callRoute(1, ''));
        self::assertSame(403, $this->callRouteWithTarget(1, ['test.pdf']));
    }

    /**
     * Tests that a file that is not indexed yet is not indexed by the route,
     * not even for an admin. Resolving it would write its file record before
     * the permissions are looked at.
     */
    #[Test]
    public function mainActionDoesNotIndexAFileForTheRequest(): void
    {
        file_put_contents($this->instancePath . '/fileadmin/unindexed.pdf', 'content');

        $spy = $this->registerEventSpy(DataHandlerRecordUpdateEvent::class);

        self::assertSame(403, $this->callRouteWithTarget(1, 'unindexed.pdf', false, false));
        self::assertSame([], $spy->events);
        self::assertFalse(
            $this->fetchFirstRowByFieldValue('sys_file', 'name', 'unindexed.pdf'),
            'No file record was written for the request'
        );
    }

    /**
     * Tests that a target that is not a combined identifier is refused, although
     * it names a file of a storage. TYPO3 resolves such a path through the
     * file system and indexes the file, which the route must not trigger.
     */
    #[Test]
    public function mainActionRefusesATargetThatIsNotACombinedIdentifier(): void
    {
        $spy = $this->registerEventSpy(DataHandlerRecordUpdateEvent::class);

        self::assertSame(403, $this->callRouteWithTarget(1, 'fileadmin/test.pdf', true));
        self::assertSame([], $spy->events);
    }

    /**
     * Tests that a target in a storage that does not exist is answered with 403
     * as well, instead of failing.
     */
    #[Test]
    public function mainActionRefusesATargetInAStorageThatDoesNotExist(): void
    {
        $spy = $this->registerEventSpy(DataHandlerRecordUpdateEvent::class);

        self::assertSame(403, $this->callRouteWithTarget(1, '9999:/test.pdf', true));
        self::assertSame([], $spy->events);
    }

    /**
     * Calls the route as the given backend user for a file of the new storage.
     *
     * @param int    $backendUserUid The UID of the backend user to call the route as
     * @param string $fileName       The name of the file in the storage root
     *
     * @return int The status code of the response
     */
    private function callRoute(
        int $backendUserUid,
        string $fileName,
    ): int {
        return $this->callRouteWithTarget($backendUserUid, $fileName);
    }

    /**
     * Calls the route as the given backend user with the given raw target.
     *
     * @param int             $backendUserUid The UID of the backend user to call the route as
     * @param string|string[] $target         The file name in the storage root, or a raw value for the target
     * @param bool            $isRawTarget    Whether a string target is sent as it is, without the storage in front
     * @param bool            $isFileIndexed  Whether the file of a string target is indexed before the call
     *
     * @return int The status code of the response
     */
    private function callRouteWithTarget(
        int $backendUserUid,
        string|array $target,
        bool $isRawTarget = false,
        bool $isFileIndexed = true,
    ): int {
        $backendUser     = $this->setUpBackendUser($backendUserUid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        // The permissions and file mounts of a user are only added to a storage in a backend
        // request, so without it the storage of this test would answer for an admin
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://example.com/', 'POST'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        $storageUid = $this->get(StorageRepository::class)
            ->createLocalStorage('fileadmin', 'fileadmin', 'relative', '', true);

        // The file list indexes a file when it shows it, so the context menu only
        // meets files that are indexed. Listing a file here does the same.
        if (
            $isFileIndexed
            && is_string($target)
            && !$isRawTarget
        ) {
            try {
                $this->get(ResourceFactory::class)->getStorageObject($storageUid)->getFile('/' . $target);
            } catch (Throwable) {
                // A file that does not exist stays unindexed
            }
        }

        $queryParams = (is_array($target) || $isRawTarget)
            ? ['target' => $target]
            : ['target' => $storageUid . ':/' . $target];

        $request = (new ServerRequest('https://example.com/', 'POST'))
            ->withAttribute('route', new Route('/module/meine-krankenkasse/typo3-search/enqueueOne', []))
            ->withQueryParams($queryParams);

        return $this->get(EnqueueOneController::class)
            ->mainAction($request)
            ->getStatusCode();
    }
}
