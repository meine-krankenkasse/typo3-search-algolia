<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Traits;

use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Traits\Fixtures\FileEligibilityTraitTestSubject;
use MeineKrankenkasse\Typo3SearchAlgolia\Traits\FileEligibilityTrait;
use Override;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\MetaDataAspect;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function array_reverse;

/**
 * Unit tests for FileEligibilityTrait.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversTrait(FileEligibilityTrait::class)]
final class FileEligibilityTraitTest extends TestCase
{
    private FileEligibilityTraitTestSubject $subject;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new FileEligibilityTraitTestSubject();
    }

    #[Override]
    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();

        parent::tearDown();
    }

    /**
     * Registers a MetaDataAspect mock to be returned for the next
     * `GeneralUtility::makeInstance(MetaDataAspect::class, ...)` call, i.e. the
     * *freshly reloaded* aspect used when the file's own cached aspect is missing
     * the 'no_search' field.
     *
     * @param array<string, int|float|string|null> $metaData
     */
    private function registerMetaDataAspectMock(array $metaData): void
    {
        $metaDataMock = self::createStub(MetaDataAspect::class);
        $metaDataMock
            ->method('get')
            ->willReturn($metaData);

        GeneralUtility::addInstance(MetaDataAspect::class, $metaDataMock);
    }

    /**
     * Stubs the given File mock's own (cached) getMetaData() aspect.
     *
     * @param array<string, int|float|string|null> $metaData
     */
    private function stubCachedMetaData(Stub&File $fileMock, array $metaData): void
    {
        $metaDataMock = self::createStub(MetaDataAspect::class);
        $metaDataMock
            ->method('get')
            ->willReturn($metaData);

        $fileMock
            ->method('getMetaData')
            ->willReturn($metaDataMock);
    }

    /**
     * Creates a mock File with all eligibility criteria met, including a
     * cached metadata aspect that already carries 'no_search', so no fresh
     * reload is needed. The file lies in a storage that is not browsable unless
     * another one is given.
     *
     * @param string               $extension The extension of the file
     * @param ResourceStorage|null $storage   The storage of the file
     */
    private function createEligibleFileMock(string $extension = 'pdf', ?ResourceStorage $storage = null): File&Stub
    {
        $fileMock = self::createStub(File::class);
        $fileMock
            ->method('getStorage')
            ->willReturn($storage ?? self::createStub(ResourceStorage::class));
        $fileMock
            ->method('isIndexed')
            ->willReturn(true);
        $fileMock
            ->method('getExtension')
            ->willReturn($extension);

        $this->stubCachedMetaData($fileMock, ['uid' => 1, 'no_search' => 0]);

        return $fileMock;
    }

    /**
     * Tests that isEligible() returns true when a File meets all eligibility criteria:
     * it is an instance of File, is indexed, has an allowed extension, has a metadata
     * UID, and is not marked with the no_search flag.
     */
    #[Test]
    public function isEligibleReturnsTrueWhenAllConditionsMet(): void
    {
        $file = $this->createEligibleFileMock();

        self::assertTrue($this->subject->callIsEligible($file, ['pdf', 'doc']));
    }

    /**
     * Tests that isEligible() returns false for a file that lies in a recycler folder or
     * below one, because TYPO3 moves a deleted file there and the file stays a member of
     * its collection, while a file in a plain folder stays eligible.
     *
     * @param list<string> $roles    The roles of the folders from the parent folder of the file up to the root
     * @param bool         $eligible Whether the file is eligible
     */
    #[Test]
    #[DataProvider('folderRolesOfAFile')]
    public function isEligibleLeavesOutFilesInARecyclerFolder(
        array $roles,
        bool $eligible,
    ): void {
        $storage = self::createStub(ResourceStorage::class);
        $storage->method('isBrowsable')->willReturn(true);

        // The folders are chained upwards, the root folder is its own parent
        $parent  = null;
        $folders = [];

        foreach (array_reverse($roles, true) as $level => $role) {
            $folder = $this->createMock(Folder::class);
            $folder->method('getRole')->willReturn($role);
            $folder->method('getIdentifier')->willReturn('/level' . $level . '/');
            $folder->method('getParentFolder')->willReturn($parent ?? $folder);

            $folders[$level] = $folder;
            $parent          = $folder;
        }

        $file = $this->createEligibleFileMock(storage: $storage);

        $file->method('getParentFolder')->willReturn($folders[0]);

        self::assertSame($eligible, $this->subject->callIsEligible($file, ['pdf']));
    }

    /**
     * Provides the roles of the folders above a file.
     *
     * @return iterable<string, array{list<string>, bool}>
     */
    public static function folderRolesOfAFile(): iterable
    {
        yield 'plain folders' => [['default', 'default'], true];
        yield 'the parent folder is the recycler' => [['recycler', 'default'], false];
        yield 'a recycler further up' => [['default', 'default', 'recycler', 'default'], false];
        yield 'the root folder is the recycler' => [['default', 'recycler'], false];
    }

    /**
     * Tests that the folders of a file are looked at without the permission check of the
     * user, because a folder above the file mount of an editor would throw, and that the
     * setting of the storage is put back as it was afterwards, also when a folder cannot
     * be looked at.
     *
     * @param bool $startState Whether the storage evaluates permissions before the check
     * @param bool $fails      Whether the lookup of the parent folder fails
     */
    #[Test]
    #[DataProvider('storageStatesAroundTheFolderLookup')]
    public function isEligibleLooksAtTheFoldersWithoutThePermissionCheckAndRestoresIt(
        bool $startState,
        bool $fails,
    ): void {
        $changes = [];

        $storage = self::createStub(ResourceStorage::class);
        $storage->method('isBrowsable')->willReturn(true);
        $storage->method('getEvaluatePermissions')->willReturn($startState);
        $storage
            ->method('setEvaluatePermissions')
            ->willReturnCallback(
                static function (bool $evaluate) use (&$changes): void {
                    $changes[] = $evaluate;
                },
            );

        $file = $this->createEligibleFileMock(storage: $storage);

        if ($fails) {
            $file
                ->method('getParentFolder')
                ->willThrowException(new FolderDoesNotExistException('Folder is gone', 1));
        } else {
            $folder = self::createStub(Folder::class);
            $folder->method('getRole')->willReturn('default');
            $folder->method('getIdentifier')->willReturn('/');
            $folder->method('getParentFolder')->willReturn($folder);

            $file->method('getParentFolder')->willReturn($folder);
        }

        self::assertTrue($this->subject->callIsEligible($file, ['pdf']), 'The file is judged by the other criteria');
        self::assertSame([false, $startState], $changes);
    }

    /**
     * Provides the state of the storage before the lookup and whether the lookup fails.
     *
     * @return iterable<string, array{bool, bool}>
     */
    public static function storageStatesAroundTheFolderLookup(): iterable
    {
        yield 'checks on, lookup works' => [true, false];
        yield 'checks on, lookup fails' => [true, true];
        yield 'checks off, lookup works' => [false, false];
        yield 'checks off, lookup fails' => [false, true];
    }

    /**
     * Tests that isEligible() returns false when the provided file object implements
     * FileInterface but is not an instance of the concrete File class.
     */
    #[Test]
    public function isEligibleReturnsFalseForNonFileInstance(): void
    {
        $fileMock = $this->createMock(FileInterface::class);

        self::assertFalse($this->subject->callIsEligible($fileMock, ['pdf']));
    }

    /**
     * Tests that isEligible() returns false when the File exists but is not indexed
     * in the FAL index, even though all other eligibility criteria are met.
     */
    #[Test]
    public function isEligibleReturnsFalseWhenNotIndexed(): void
    {
        $fileMock = self::createStub(File::class);
        $fileMock
            ->method('isIndexed')
            ->willReturn(false);
        $fileMock
            ->method('getExtension')
            ->willReturn('pdf');

        // Not indexed short-circuits before metadata is ever read.
        self::assertFalse($this->subject->callIsEligible($fileMock, ['pdf']));
    }

    /**
     * Tests that isEligible() returns false when the file's extension (e.g. 'jpg')
     * is not present in the list of allowed extensions (e.g. ['pdf', 'doc']).
     */
    #[Test]
    public function isEligibleReturnsFalseWhenExtensionNotAllowed(): void
    {
        $file = $this->createEligibleFileMock('jpg');

        self::assertFalse($this->subject->callIsEligible($file, ['pdf', 'doc']));
    }

    /**
     * Tests that isEligible() returns false when the file's metadata does not contain
     * a 'uid' entry, indicating the metadata record has not been properly created.
     */
    #[Test]
    public function isEligibleReturnsFalseWhenMetadataUidMissing(): void
    {
        $fileMock = self::createStub(File::class);
        $fileMock
            ->method('isIndexed')
            ->willReturn(true);
        $fileMock
            ->method('getExtension')
            ->willReturn('pdf');

        // 'no_search' is present, so no fresh reload is triggered; the missing
        // 'uid' alone must still make the file ineligible.
        $this->stubCachedMetaData($fileMock, ['no_search' => 0]);

        self::assertFalse($this->subject->callIsEligible($fileMock, ['pdf']));
    }

    /**
     * Tests that isEligible() returns false when the file's 'no_search' property
     * is set to 1, indicating the file has been explicitly excluded from search indexing.
     */
    #[Test]
    public function isEligibleReturnsFalseWhenMarkedNoSearch(): void
    {
        $fileMock = self::createStub(File::class);
        $fileMock
            ->method('isIndexed')
            ->willReturn(true);
        $fileMock
            ->method('getExtension')
            ->willReturn('pdf');

        $this->stubCachedMetaData($fileMock, ['uid' => 1, 'no_search' => 1]);

        self::assertFalse($this->subject->callIsEligible($fileMock, ['pdf']));
    }

    /**
     * Tests the actual bug this trait was fixed for (a7a8005): right after a file
     * is uploaded, its own cached metadata aspect only carries the fields extracted
     * during upload (e.g. 'uid'), not yet 'no_search', because the full
     * sys_file_metadata row had not been read into it. isEligible() must reload a
     * fresh aspect in that case and use ITS 'no_search' value, rather than treating
     * the missing field on the stale cached aspect as "not indexable".
     */
    #[Test]
    public function isEligibleReloadsFreshMetadataWhenCachedAspectIsMissingNoSearch(): void
    {
        $fileMock = self::createStub(File::class);
        $fileMock
            ->method('isIndexed')
            ->willReturn(true);
        $fileMock
            ->method('getExtension')
            ->willReturn('pdf');

        // Stale/partial cached aspect: 'uid' present, 'no_search' NOT (yet) read.
        $this->stubCachedMetaData($fileMock, ['uid' => 1]);
        // Freshly reloaded aspect: full row, including 'no_search' => 0.
        $this->registerMetaDataAspectMock(['uid' => 1, 'no_search' => 0]);

        self::assertTrue($this->subject->callIsEligible($fileMock, ['pdf']));
    }

    /**
     * Tests that isEligible() does NOT reload a fresh metadata aspect when the
     * file's own cached aspect already carries 'no_search' - reloading unconditionally
     * would cost an extra DB round-trip per file in a batch scan
     * (FileIndexer::initQueueItemRecords()) for no benefit. GeneralUtility::addInstance()
     * is deliberately NOT called here: if the fresh-reload path were (incorrectly)
     * taken anyway, GeneralUtility::makeInstance(MetaDataAspect::class, $file) would
     * construct a real MetaDataAspect and attempt a real database query, which fails
     * hard in this unit test's un-bootstrapped environment.
     */
    #[Test]
    public function isEligibleDoesNotReloadMetadataWhenCachedAspectAlreadyHasNoSearch(): void
    {
        $fileMock = self::createStub(File::class);
        $fileMock
            ->method('isIndexed')
            ->willReturn(true);
        $fileMock
            ->method('getExtension')
            ->willReturn('pdf');

        $this->stubCachedMetaData($fileMock, ['uid' => 1, 'no_search' => 0]);

        self::assertTrue($this->subject->callIsEligible($fileMock, ['pdf']));
    }

    /**
     * Tests that isExtensionAllowed() returns true when the file's extension
     * matches one of the entries in the provided list of allowed extensions.
     */
    #[Test]
    public function isExtensionAllowedReturnsTrueForMatchingExtension(): void
    {
        $fileMock = $this->createMock(FileInterface::class);
        $fileMock
            ->method('getExtension')
            ->willReturn('pdf');

        self::assertTrue($this->subject->callIsExtensionAllowed($fileMock, ['pdf', 'doc', 'txt']));
    }

    /**
     * Tests that isExtensionAllowed() returns false when the file's extension
     * does not match any entry in the provided list of allowed extensions.
     */
    #[Test]
    public function isExtensionAllowedReturnsFalseForNonMatchingExtension(): void
    {
        $fileMock = $this->createMock(FileInterface::class);
        $fileMock
            ->method('getExtension')
            ->willReturn('exe');

        self::assertFalse($this->subject->callIsExtensionAllowed($fileMock, ['pdf', 'doc', 'txt']));
    }

    /**
     * Tests that isExtensionAllowed() returns false when the list of allowed
     * extensions is empty, regardless of the file's actual extension.
     */
    #[Test]
    public function isExtensionAllowedReturnsFalseForEmptyExtensionList(): void
    {
        $fileMock = $this->createMock(FileInterface::class);
        $fileMock
            ->method('getExtension')
            ->willReturn('pdf');

        self::assertFalse($this->subject->callIsExtensionAllowed($fileMock, []));
    }

    /**
     * Tests that isIndexable() returns true when the file has the 'no_search'
     * property present and its value is zero, meaning the file is allowed to
     * be indexed for search.
     */
    #[Test]
    public function isIndexableReturnsTrueWhenNoSearchIsZero(): void
    {
        self::assertTrue($this->subject->callIsIndexable(['no_search' => 0]));
    }

    /**
     * Tests that isIndexable() returns false when the file has the 'no_search'
     * property set to 1, indicating the file should be excluded from search indexing.
     */
    #[Test]
    public function isIndexableReturnsFalseWhenNoSearchIsOne(): void
    {
        self::assertFalse($this->subject->callIsIndexable(['no_search' => 1]));
    }

    /**
     * Tests that isIndexable() returns false when the file does not have the
     * 'no_search' property at all, treating the absence of the property as
     * non-indexable.
     */
    #[Test]
    public function isIndexableReturnsFalseWhenNoSearchPropertyMissing(): void
    {
        self::assertFalse($this->subject->callIsIndexable([]));
    }

    /**
     * Provides the combinations of the permissions, with exactly one of
     * them missing in each denied row.
     *
     * @return array<string, array{bool, bool, bool, bool}>
     */
    public static function metadataPermissionProvider(): array
    {
        return [
            'all granted'               => [true, true, true, true],
            'action permission missing' => [false, true, true, false],
            'table modify missing'      => [true, false, true, false],
            'language access missing'   => [true, true, false, false],
        ];
    }

    /**
     * Tests that a user may only queue the metadata of a file by hand when the
     * file allows editing its metadata, the user may modify the metadata table
     * and has access to the default language, and that each of them is required.
     */
    #[Test]
    #[DataProvider('metadataPermissionProvider')]
    public function mayEditMetadataRequiresEachPermission(
        bool $actionPermission,
        bool $tableModify,
        bool $languageAccess,
        bool $expected,
    ): void {
        $fileMock = $this->createMock(File::class);
        $fileMock
            ->method('getStorage')
            ->willReturn($this->createStorageMock(false));
        $fileMock
            ->method('checkActionPermission')
            ->with('editMeta')
            ->willReturn($actionPermission);

        $backendUserMock = $this->createMock(BackendUserAuthentication::class);
        $backendUserMock
            ->method('check')
            ->with('tables_modify', 'sys_file_metadata')
            ->willReturn($tableModify);
        $backendUserMock
            ->method('checkLanguageAccess')
            ->with(0)
            ->willReturn($languageAccess);

        self::assertSame($expected, $this->subject->callMayEditMetadata($fileMock, $backendUserMock));
    }

    /**
     * Tests that a target that is not a file, such as a processed file, is
     * never allowed, whatever the user may do.
     */
    #[Test]
    public function mayEditMetadataRejectsATargetThatIsNotAFile(): void
    {
        $backendUserMock = self::createStub(BackendUserAuthentication::class);
        $backendUserMock
            ->method('check')
            ->willReturn(true);
        $backendUserMock
            ->method('checkLanguageAccess')
            ->willReturn(true);

        self::assertFalse(
            $this->subject->callMayEditMetadata(self::createStub(FileInterface::class), $backendUserMock),
        );
    }

    /**
     * Tests that a file of the fallback storage is not allowed, although the
     * action permission and the user rights would grant it, because the file
     * mounts of the user are not evaluated for that storage.
     */
    #[Test]
    public function mayEditMetadataRejectsAFileOfTheFallbackStorage(): void
    {
        $fileMock = self::createStub(File::class);
        $fileMock
            ->method('getStorage')
            ->willReturn($this->createStorageMock(true));
        $fileMock
            ->method('checkActionPermission')
            ->willReturn(true);

        $backendUserMock = self::createStub(BackendUserAuthentication::class);
        $backendUserMock
            ->method('check')
            ->willReturn(true);
        $backendUserMock
            ->method('checkLanguageAccess')
            ->willReturn(true);

        self::assertFalse($this->subject->callMayEditMetadata($fileMock, $backendUserMock));
    }

    /**
     * Creates a storage mock that is or is not the fallback storage.
     *
     * @param bool $isFallbackStorage Whether the storage is the fallback storage
     *
     * @return ResourceStorage
     */
    private function createStorageMock(bool $isFallbackStorage): ResourceStorage
    {
        $storageMock = self::createStub(ResourceStorage::class);
        $storageMock
            ->method('isFallbackStorage')
            ->willReturn($isFallbackStorage);

        return $storageMock;
    }
}
