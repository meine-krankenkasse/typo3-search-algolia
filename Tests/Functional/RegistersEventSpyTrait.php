<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional;

use Symfony\Component\DependencyInjection\ContainerInterface;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;

/**
 * Registers a listener in the container of a functional test that records the
 * events of a class, so that a test can tell which events were dispatched.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
trait RegistersEventSpyTrait
{
    /**
     * Registers a listener that records every dispatched event of the class.
     *
     * @param class-string $eventClass The class of the events to record
     *
     * @return object{events: list<object>} The spy holding the recorded events
     */
    private function registerEventSpy(string $eventClass): object
    {
        $spy = new class {
            /**
             * The events dispatched to this listener, in dispatch order.
             *
             * @var list<object>
             */
            public array $events = [];

            /**
             * Records an event.
             *
             * @param object $event The dispatched event
             *
             * @return void
             */
            public function __invoke(object $event): void
            {
                $this->events[] = $event;
            }
        };

        $container = $this->getContainer();

        if (!($container instanceof ContainerInterface)) {
            self::fail('The test container cannot register a service');
        }

        $serviceId = 'event-spy-' . $eventClass;
        $container->set($serviceId, $spy);

        $this->get(ListenerProvider::class)
            ->addListener($eventClass, $serviceId);

        return $spy;
    }
}
