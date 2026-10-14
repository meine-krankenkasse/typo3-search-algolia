<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Configuration;

use MeineKrankenkasse\Typo3SearchAlgolia\Event\AfterDocumentAssembledEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\CreateUniqueDocumentIdEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeletedEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeleteEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeleteRefusedEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordMoveEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordUpdateEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Resource\AfterFileDeletedEventListener;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Resource\Event\AfterFileAddedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileCopiedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileDeletedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileMovedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileRenamedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileReplacedEvent;
use TYPO3\CMS\Core\Resource\Event\BeforeFileDeletedEvent;

use function array_keys;
use function array_map;
use function array_unique;
use function class_exists;
use function count;
use function is_array;
use function is_string;
use function ltrim;
use function method_exists;
use function str_starts_with;
use function strrpos;
use function substr;

/**
 * Checks that every event listener registration of the extension points at an
 * existing method of its class that accepts the registered event.
 *
 * A listener that is only wired through the service configuration stays silent
 * when its registration is wrong, and the unit tests that call the method
 * directly would not notice.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversNothing]
final class ServicesListenerRegistrationTest extends TestCase
{
    /**
     * Tests that each registration of an event of this extension names an
     * existing event class and an existing method, or the invokable default,
     * that takes the event, and that the registrations are exactly the
     * expected ones.
     */
    #[Test]
    public function everyEventListenerRegistrationMatchesItsMethodAndEvent(): void
    {
        $configuration = Yaml::parseFile(__DIR__ . '/../../../Configuration/Services.yaml');
        $services      = is_array($configuration['services'] ?? null) ? $configuration['services'] : [];

        $registrations = [];
        $identifiers   = [];

        foreach ($services as $className => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            if (!is_array($definition['tags'] ?? null)) {
                continue;
            }

            foreach ($definition['tags'] as $tag) {
                if (!is_array($tag)) {
                    continue;
                }

                if (($tag['name'] ?? null) !== 'event.listener') {
                    continue;
                }

                $registrations[] = $this->shortName($className)
                    . '::' . (isset($tag['method']) && is_string($tag['method']) ? $tag['method'] : '__invoke')
                    . ' => ' . (is_string($tag['event'] ?? null) ? $tag['event'] : '');

                $identifiers[] = is_string($tag['identifier'] ?? null) ? $tag['identifier'] : '';

                $eventClass = $tag['event'] ?? null;
                $method     = isset($tag['method']) && is_string($tag['method']) ? $tag['method'] : '__invoke';

                self::assertIsString($eventClass, $className . ' names its event');
                self::assertTrue(class_exists($className), $className . ' is an existing class');

                // The event of an optional extension may not be installed
                if (str_starts_with($eventClass, 'MeineKrankenkasse\\')) {
                    self::assertTrue(class_exists($eventClass), $className . ' names an existing event class');
                }

                self::assertTrue(
                    method_exists($className, $method),
                    $className . ' has the method ' . $method
                );

                $parameters = (new ReflectionMethod($className, $method))->getParameters();

                self::assertNotSame([], $parameters, $className . '::' . $method . ' takes the event');

                $type = $parameters[0]->getType();

                self::assertInstanceOf(ReflectionNamedType::class, $type);

                // A listener of an event of an optional extension may take a plain object
                self::assertContains(
                    $type->getName(),
                    [ltrim($eventClass, '\\'), 'object'],
                    $className . '::' . $method . ' accepts the registered event'
                );
            }
        }

        // A registration that is dropped or renamed shows up as a difference to this list
        $expected = [
            'CreateDefaultDocumentIdEventListener::__invoke'               => CreateUniqueDocumentIdEvent::class,
            'UpdateAssembledContentElementDocumentEventListener::__invoke' => AfterDocumentAssembledEvent::class,
            'UpdateAssembledFileDocumentEventListener::__invoke'           => AfterDocumentAssembledEvent::class,
            'UpdateAssembledPageDocumentEventListener::__invoke'           => AfterDocumentAssembledEvent::class,
            'RecordUpdateEventListener::__invoke'                          => DataHandlerRecordUpdateEvent::class,
            'RecordMoveEventListener::__invoke'                            => DataHandlerRecordMoveEvent::class,
            'RecordDeleteEventListener::__invoke'                          => DataHandlerRecordDeleteEvent::class,
            'RecordDeleteEventListener::onDeleted'                         => DataHandlerRecordDeletedEvent::class,
            'RecordDeleteEventListener::onRefused'                         => DataHandlerRecordDeleteRefusedEvent::class,
            'CategoryChangeEventListener::__invoke'                        => DataHandlerRecordUpdateEvent::class,
            'AfterFileAddedEventListener::__invoke'                        => AfterFileAddedEvent::class,
            'AfterFileDeletedEventListener::__invoke'                      => AfterFileDeletedEvent::class,
            'AfterFileDeletedEventListener::onBeforeDeleted'               => BeforeFileDeletedEvent::class,
            'AfterFileCopiedEventListener::__invoke'                       => AfterFileCopiedEvent::class,
            'AfterFileMovedEventListener::__invoke'                        => AfterFileMovedEvent::class,
            'AfterFileRenamedEventListener::__invoke'                      => AfterFileRenamedEvent::class,
            'AfterFileReplacedEventListener::__invoke'                     => AfterFileReplacedEvent::class,
            'AfterRecordPublishedEventListener::__invoke'                  => 'TYPO3\\CMS\\Workspaces\\Event\\AfterRecordPublishedEvent',
        ];

        self::assertEqualsCanonicalizing(
            array_map(
                static fn (string $registration, string $eventClass): string => $registration . ' => ' . $eventClass,
                array_keys($expected),
                $expected
            ),
            $registrations
        );

        // TYPO3 lets a duplicate identifier silently replace the earlier listener
        self::assertSame(
            count($identifiers),
            count(array_unique($identifiers)),
            'Every listener registration has its own identifier'
        );
    }

    /**
     * Tests that the listener of a deleted file runs after the core listener that
     * removes the metadata record, because the translations of the metadata are only
     * known to be deleted when the core listener ran.
     */
    #[Test]
    public function theDeletedFileListenerRunsAfterTheCoreListenerThatRemovesTheMetadata(): void
    {
        $configuration = Yaml::parseFile(__DIR__ . '/../../../Configuration/Services.yaml');
        $tags          = $configuration['services'][AfterFileDeletedEventListener::class]['tags'] ?? [];

        self::assertIsArray($tags);

        $after = null;

        foreach ($tags as $tag) {
            if (
                is_array($tag)
                && (($tag['event'] ?? null) === AfterFileDeletedEvent::class)
            ) {
                $after = $tag['after'] ?? null;
            }
        }

        self::assertSame('delete-processed-files-after-delete', $after);
    }

    /**
     * Returns the class name without its namespace.
     *
     * @param string $className The fully qualified class name
     *
     * @return string
     */
    private function shortName(string $className): string
    {
        $position = strrpos($className, '\\');

        return ($position === false) ? $className : substr($className, $position + 1);
    }
}
