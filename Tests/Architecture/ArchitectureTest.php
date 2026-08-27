<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Architecture rules enforced via PHPat, run as part of the PHPStan analysis.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final class ArchitectureTest
{
    /**
     * Domain models must stay persistence-ignorant, they must not depend
     * on repository classes.
     */
    public function testDomainModelsDoNotDependOnRepositories(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::inNamespace('MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model'),
                Selector::inNamespace('MeineKrankenkasse\Typo3SearchAlgolia\Model'),
            )
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::inNamespace('MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository'),
                Selector::inNamespace('MeineKrankenkasse\Typo3SearchAlgolia\Repository'),
            )
            ->because('domain models must remain persistence-ignorant.');
    }

    /**
     * Domain models must not depend on backend controllers, the dependency
     * direction is controller -> model, never the other way round.
     */
    public function testDomainModelsDoNotDependOnControllers(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::inNamespace('MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model'),
                Selector::inNamespace('MeineKrankenkasse\Typo3SearchAlgolia\Model'),
            )
            ->shouldNot()
            ->dependOn()
            ->classes(Selector::inNamespace('MeineKrankenkasse\Typo3SearchAlgolia\Controller'))
            ->because('models must not depend on the controller layer.');
    }

    /**
     * Production code must never depend on test code.
     */
    public function testClassesDoNotDependOnTests(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MeineKrankenkasse\Typo3SearchAlgolia'))
            ->excluding(Selector::inNamespace('MeineKrankenkasse\Typo3SearchAlgolia\Tests'))
            ->shouldNot()
            ->dependOn()
            ->classes(Selector::inNamespace('MeineKrankenkasse\Typo3SearchAlgolia\Tests'))
            ->because('production code must not depend on test code.');
    }

    /**
     * Everything named "*Interface" must actually be an interface.
     */
    public function testInterfaceSuffixIsAnInterface(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::classname('/Interface$/', true))
            ->should()
            ->beInterface()
            ->because('a class named "*Interface" is expected to actually be an interface.');
    }

    /**
     * Everything under Classes/Exception must actually be an exception.
     */
    public function testExceptionClassesAreThrowable(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MeineKrankenkasse\Typo3SearchAlgolia\Exception'))
            ->should()
            ->extend()
            ->classes(Selector::isException())
            ->because('classes in the Exception namespace must extend a base exception class.');
    }
}
