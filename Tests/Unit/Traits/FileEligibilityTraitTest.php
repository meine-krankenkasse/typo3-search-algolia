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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\MetaDataAspect;

/**
 * Unit tests for FileEligibilityTrait.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(FileEligibilityTrait::class)]
class FileEligibilityTraitTest extends TestCase
{
    private FileEligibilityTraitTestSubject $subject;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new FileEligibilityTraitTestSubject();
    }

    /**
     * Creates a mock File with all eligibility criteria met.
     */
    private function createEligibleFileMock(string $extension = 'pdf'): File
    {
        $metaDataMock = $this->createMock(MetaDataAspect::class);
        $metaDataMock
            ->method('offsetExists')
            ->with('uid')
            ->willReturn(true);

        $fileMock = $this->createMock(File::class);
        $fileMock
            ->method('isIndexed')
            ->willReturn(true);
        $fileMock
            ->method('getExtension')
            ->willReturn($extension);
        $fileMock
            ->method('getMetaData')
            ->willReturn($metaDataMock);
        $fileMock
            ->method('hasProperty')
            ->with('no_search')
            ->willReturn(true);
        $fileMock
            ->method('getProperty')
            ->with('no_search')
            ->willReturn(0);

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
        $metaDataMock = $this->createMock(MetaDataAspect::class);
        $metaDataMock
            ->method('offsetExists')
            ->willReturn(true);

        $fileMock = $this->createMock(File::class);
        $fileMock
            ->method('isIndexed')
            ->willReturn(false);
        $fileMock
            ->method('getExtension')
            ->willReturn('pdf');
        $fileMock
            ->method('getMetaData')
            ->willReturn($metaDataMock);
        $fileMock
            ->method('hasProperty')
            ->willReturn(true);
        $fileMock
            ->method('getProperty')
            ->willReturn(0);

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
        $metaDataMock = $this->createMock(MetaDataAspect::class);
        $metaDataMock
            ->method('offsetExists')
            ->with('uid')
            ->willReturn(false);

        $fileMock = $this->createMock(File::class);
        $fileMock
            ->method('isIndexed')
            ->willReturn(true);
        $fileMock
            ->method('getExtension')
            ->willReturn('pdf');
        $fileMock
            ->method('getMetaData')
            ->willReturn($metaDataMock);
        $fileMock
            ->method('hasProperty')
            ->willReturn(true);
        $fileMock
            ->method('getProperty')
            ->willReturn(0);

        self::assertFalse($this->subject->callIsEligible($fileMock, ['pdf']));
    }

    /**
     * Tests that isEligible() returns false when the file's 'no_search' property
     * is set to 1, indicating the file has been explicitly excluded from search indexing.
     */
    #[Test]
    public function isEligibleReturnsFalseWhenMarkedNoSearch(): void
    {
        $metaDataMock = $this->createMock(MetaDataAspect::class);
        $metaDataMock
            ->method('offsetExists')
            ->with('uid')
            ->willReturn(true);

        $fileMock = $this->createMock(File::class);
        $fileMock
            ->method('isIndexed')
            ->willReturn(true);
        $fileMock
            ->method('getExtension')
            ->willReturn('pdf');
        $fileMock
            ->method('getMetaData')
            ->willReturn($metaDataMock);
        $fileMock
            ->method('hasProperty')
            ->with('no_search')
            ->willReturn(true);
        $fileMock
            ->method('getProperty')
            ->with('no_search')
            ->willReturn(1);

        self::assertFalse($this->subject->callIsEligible($fileMock, ['pdf']));
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
        $fileMock = $this->createMock(FileInterface::class);
        $fileMock
            ->method('hasProperty')
            ->with('no_search')
            ->willReturn(true);
        $fileMock
            ->method('getProperty')
            ->with('no_search')
            ->willReturn(0);

        self::assertTrue($this->subject->callIsIndexable($fileMock));
    }

    /**
     * Tests that isIndexable() returns false when the file has the 'no_search'
     * property set to 1, indicating the file should be excluded from search indexing.
     */
    #[Test]
    public function isIndexableReturnsFalseWhenNoSearchIsOne(): void
    {
        $fileMock = $this->createMock(FileInterface::class);
        $fileMock
            ->method('hasProperty')
            ->with('no_search')
            ->willReturn(true);
        $fileMock
            ->method('getProperty')
            ->with('no_search')
            ->willReturn(1);

        self::assertFalse($this->subject->callIsIndexable($fileMock));
    }

    /**
     * Tests that isIndexable() returns false when the file does not have the
     * 'no_search' property at all, treating the absence of the property as
     * non-indexable.
     */
    #[Test]
    public function isIndexableReturnsFalseWhenNoSearchPropertyMissing(): void
    {
        $fileMock = $this->createMock(FileInterface::class);
        $fileMock
            ->method('hasProperty')
            ->with('no_search')
            ->willReturn(false);

        self::assertFalse($this->subject->callIsIndexable($fileMock));
    }
}
