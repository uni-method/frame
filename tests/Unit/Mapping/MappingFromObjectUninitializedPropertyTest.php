<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{Attribute, Config, Model};
use UniMethod\Frame\Mapping\{AttributeAccessor, MappingFromObject};
use UniMethod\Frame\Mutator\MutatorRegistry;
use UniMethod\Frame\Tests\Unit\Mapping\Stub\TypedProfile;
use UniMethod\Frame\Type\{Caster, TypeSpecParser};

/**
 * The public properties cache is built per class (Reflection), not from the first
 * encountered instance: an object with an uninitialized typed
 * property must not "poison" the output for all subsequent objects of the class.
 *
 * Кэш публичных свойств строится по классу (Reflection), а не по первому
 * встреченному экземпляру: объект с неинициализированным типизированным
 * свойством не должен «отравлять» выдачу для всех последующих объектов класса.
 */
final class MappingFromObjectUninitializedPropertyTest extends TestCase
{
    public function testUninitializedPropertyOnFirstInstanceDoesNotPoisonCache(): void
    {
        $mapper = $this->mapper();

        // The first one — bio is not initialized: null in the output
        //
        // Первый — bio не инициализировано: в выдаче null
        $first = $mapper->map("profile", new TypedProfile(id: "1"), []);
        self::assertObjectHasProperty("bio", $first);
        self::assertNull($first->bio);

        // The second one — bio is set: the field must be in the output with its value
        //
        // Второй — bio задано: поле обязано быть в выдаче со значением
        $second = new TypedProfile(id: "2");
        $second->bio = "hello";

        $mapped = $mapper->map("profile", $second, []);

        self::assertSame("hello", $mapped->bio);
    }

    private function mapper(): MappingFromObject
    {
        $config = new Config(
            models: [
                new Model(
                    class: TypedProfile::class,
                    type: "profile",
                    attributes: [
                        new Attribute(name: "id", type: "string|null"),
                        new Attribute(name: "bio", type: "string|null"),
                    ],
                ),
            ],
        );

        return new MappingFromObject(
            new MutatorRegistry([], new AttributeAccessor(), [], $config),
            new TypeSpecParser(),
            new Caster(),
            $config,
        );
    }
}
