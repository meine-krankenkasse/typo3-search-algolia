<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Model;

use MeineKrankenkasse\Typo3SearchAlgolia\Model\Document;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Document.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(Document::class)]
class DocumentTest extends TestCase
{
    /**
     * Tests that the constructor correctly stores the provided IndexerInterface
     * instance and the record array. Verifies that getIndexer() returns the exact
     * same mock instance and getRecord() returns the exact same array passed
     * during construction.
     */
    #[Test]
    public function constructorStoresIndexerAndRecord(): void
    {
        $indexerMock = $this->createMock(IndexerInterface::class);
        $record      = ['uid' => 42, 'title' => 'Test'];

        $document = new Document($indexerMock, $record);

        self::assertSame($indexerMock, $document->getIndexer());
        self::assertSame($record, $document->getRecord());
    }

    /**
     * Tests that a newly constructed Document has no fields set initially.
     * Verifies that getFields() returns an empty array when no fields have
     * been added via setField().
     */
    #[Test]
    public function getFieldsReturnsEmptyArrayInitially(): void
    {
        $document = new Document(
            $this->createMock(IndexerInterface::class),
            []
        );

        self::assertSame([], $document->getFields());
    }

    /**
     * Tests that setField() correctly adds a new field to the document.
     * Verifies that after calling setField() with a key and value, getFields()
     * returns an associative array containing that key-value pair.
     */
    #[Test]
    public function setFieldAddsField(): void
    {
        $document = new Document(
            $this->createMock(IndexerInterface::class),
            []
        );

        $document->setField('title', 'Test Title');

        self::assertSame(['title' => 'Test Title'], $document->getFields());
    }

    /**
     * Tests that calling setField() with an existing key overwrites the previous
     * value. Verifies that after setting the same field name twice, getFields()
     * returns only the most recently assigned value.
     */
    #[Test]
    public function setFieldOverwritesExistingField(): void
    {
        $document = new Document(
            $this->createMock(IndexerInterface::class),
            []
        );

        $document->setField('title', 'Original');
        $document->setField('title', 'Updated');

        self::assertSame(['title' => 'Updated'], $document->getFields());
    }

    /**
     * Tests that calling setField() with a null value removes the field from
     * the document. Verifies that after setting a field and then setting it
     * to null, getFields() returns an empty array.
     */
    #[Test]
    public function setFieldWithNullRemovesField(): void
    {
        $document = new Document(
            $this->createMock(IndexerInterface::class),
            []
        );

        $document->setField('title', 'Test');
        $document->setField('title', null);

        self::assertSame([], $document->getFields());
    }

    /**
     * Tests that setField() returns the Document instance itself to support
     * a fluent interface. Verifies that the return value is the same object
     * reference as the original document.
     */
    #[Test]
    public function setFieldReturnsSelfForChaining(): void
    {
        $document = new Document(
            $this->createMock(IndexerInterface::class),
            []
        );

        $result = $document->setField('title', 'Test');

        self::assertSame($document, $result);
    }

    /**
     * Tests that removeField() correctly removes an existing field from the
     * document while leaving other fields intact. Verifies that after adding
     * two fields and removing one, only the remaining field is present in
     * the result of getFields().
     */
    #[Test]
    public function removeFieldRemovesExistingField(): void
    {
        $document = new Document(
            $this->createMock(IndexerInterface::class),
            []
        );

        $document->setField('title', 'Test');
        $document->setField('content', 'Body');
        $document->removeField('title');

        self::assertSame(['content' => 'Body'], $document->getFields());
    }

    /**
     * Tests that removeField() does not modify the fields collection when
     * called with a field name that does not exist. Verifies that the
     * existing fields remain unchanged after attempting to remove a
     * nonexistent field.
     */
    #[Test]
    public function removeFieldDoesNothingForMissingField(): void
    {
        $document = new Document(
            $this->createMock(IndexerInterface::class),
            []
        );

        $document->setField('title', 'Test');
        $document->removeField('nonexistent');

        self::assertSame(['title' => 'Test'], $document->getFields());
    }

    /**
     * Tests that removeField() returns the Document instance itself to support
     * a fluent interface. Verifies that the return value is the same object
     * reference as the original document.
     */
    #[Test]
    public function removeFieldReturnsSelfForChaining(): void
    {
        $document = new Document(
            $this->createMock(IndexerInterface::class),
            []
        );

        $result = $document->removeField('anything');

        self::assertSame($document, $result);
    }

    /**
     * Tests that setField() and removeField() can be chained together in a
     * fluent interface style. Verifies that after chaining multiple setField()
     * and removeField() calls, the resulting fields collection reflects all
     * additions and removals in the correct order.
     */
    #[Test]
    public function fluentInterfaceAllowsChaining(): void
    {
        $document = new Document(
            $this->createMock(IndexerInterface::class),
            []
        );

        $document
            ->setField('title', 'Test')
            ->setField('content', 'Body')
            ->removeField('title')
            ->setField('url', 'https://example.com');

        self::assertSame(
            [
                'content' => 'Body',
                'url'     => 'https://example.com',
            ],
            $document->getFields()
        );
    }

    /**
     * Tests that setField() accepts values of various types including string,
     * integer, float, boolean, and array. Verifies that each value type is
     * stored correctly and can be retrieved with its original type preserved.
     */
    #[Test]
    public function setFieldAcceptsVariousValueTypes(): void
    {
        $document = new Document(
            $this->createMock(IndexerInterface::class),
            []
        );

        $document
            ->setField('string', 'text')
            ->setField('int', 42)
            ->setField('float', 3.14)
            ->setField('bool', true)
            ->setField('array', ['a', 'b']);

        $fields = $document->getFields();

        self::assertSame('text', $fields['string']);
        self::assertSame(42, $fields['int']);
        self::assertSame(3.14, $fields['float']);
        self::assertTrue($fields['bool']);
        self::assertSame(['a', 'b'], $fields['array']);
    }
}
