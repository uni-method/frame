<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{ActionType, Attribute, Config, Model, PhaseType, Relation};
use UniMethod\Frame\Exception\{ConfigAlreadySetException, ConfigNotSetException};
use UniMethod\Frame\Mapping\{
    AttributeAccessor,
    MappingFromObject,
    MappingToListAction,
    MappingToObject,
};
use UniMethod\Frame\Mutator\MutatorRegistry;
use UniMethod\Frame\Persistence\ListLocators;
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Tests\Unit\Stub\{Post, Writer};
use UniMethod\Frame\Type\{Caster, TypeSpecParser};
use UniMethod\Frame\Validator\ActionValidator;

/**
 * The config of services is set once — by the constructor or the first setConfig().
 * Setting it again — ConfigAlreadySetException: in a long-lived worker
 * rebuilding the service's indexes in the middle of someone else's request is not allowed.
 *
 * Config у сервисов задаётся один раз — конструктором или первым setConfig().
 * Повторная установка — ConfigAlreadySetException: в долгоживущем воркере
 * перестройка индексов сервиса посреди чужого запроса недопустима.
 */
final class ServiceConfigOnceTest extends TestCase
{
    public function testMappingToObjectAcceptsConfigOnce(): void
    {
        $config = $this->config();
        $service = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            new ListLocators([]),
            new AttributeAccessor(),
            $config,
        );

        $this->expectException(ConfigAlreadySetException::class);
        $service->setConfig($config);
    }

    public function testMappingFromObjectAcceptsConfigOnce(): void
    {
        $config = $this->config();
        $service = new MappingFromObject(
            new MutatorRegistry([], new AttributeAccessor()),
            new TypeSpecParser(),
            new Caster(),
            $config,
        );

        $this->expectException(ConfigAlreadySetException::class);
        $service->setConfig($config);
    }

    public function testMappingToListActionAcceptsConfigOnce(): void
    {
        $config = $this->config();
        $service = new MappingToListAction(new TypeSpecParser(), null, new Caster(), $config);

        $this->expectException(ConfigAlreadySetException::class);
        $service->setConfig($config);
    }

    public function testActionValidatorAcceptsConfigOnce(): void
    {
        $config = $this->config();
        $service = new ActionValidator(new TreeBuilder(), $config);

        $this->expectException(ConfigAlreadySetException::class);
        $service->setConfig($config);
    }

    public function testMutatorRegistryAcceptsConfigOnce(): void
    {
        $config = $this->config();
        $service = new MutatorRegistry([], new AttributeAccessor(), [], $config);

        $this->expectException(ConfigAlreadySetException::class);
        $service->setConfig($config);
    }

    public function testSetConfigTwiceIsRejectedEvenWithoutConstructorConfig(): void
    {
        $config = $this->config();
        $service = new ActionValidator(new TreeBuilder());
        $service->setConfig($config);

        $this->expectException(ConfigAlreadySetException::class);
        $service->setConfig($config);
    }

    public function testMutatorRegistryWithoutConfigFailsExplicitlyOnRelations(): void
    {
        $config = $this->config();
        $registry = new MutatorRegistry([], new AttributeAccessor());

        $this->expectException(ConfigNotSetException::class);

        // Descending through a relation requires a config (findModelByType)
        //
        // Спуск по связи требует конфига (findModelByType)
        $registry->applyPhase(
            PhaseType::BeforeOutput,
            new Post(id: "1", author: new Writer(id: "2")),
            $config->models[0],
            ActionType::Read,
            ["author" => []],
        );
    }

    private function config(): Config
    {
        return new Config(
            models: [
                new Model(
                    class: Post::class,
                    type: "post",
                    attributes: [new Attribute(name: "id", type: "string|null")],
                    relations: [
                        new Relation(name: "author", type: Relation::MANY_TO_ONE, targetModel: "writer"),
                    ],
                ),
                new Model(
                    class: Writer::class,
                    type: "writer",
                    attributes: [new Attribute(name: "id", type: "string|null")],
                ),
            ],
        );
    }
}
