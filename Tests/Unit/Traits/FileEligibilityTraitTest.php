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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\MetaDataAspect;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Unit tests for FileEligibilityTrait.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversTrait(FileEligibilityTrait::class)]
class FileEligibilityTraitTest extends TestCase
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
        $metaDataMock = $this->createMock(MetaDataAspect::class);
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
    private function stubCachedMetaData(MockObject&File $fileMock, array $metaData): void
    {
        $metaDataMock = $this->createMock(MetaDataAspect::class);
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
     * reload is needed.
     */
    private function createEligibleFileMock(string $extension = 'pdf'): File
    {
        $fileMock = $this->createMock(File::class);
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
        $fileMock = $this->createMock(File::class);
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
        $fileMock = $this->createMock(File::class);
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
        $fileMock = $this->createMock(File::class);
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
        $fileMock = $this->createMock(File::class);
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
        $fileMock = $this->createMock(File::class);
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
}
