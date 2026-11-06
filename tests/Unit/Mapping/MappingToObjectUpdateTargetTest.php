<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use stdClass;
use UniMethod\Frame\Config\{Action, ActionType, Attribute, Config, Model};
use UniMethod\Frame\Mapping\{AttributeAccessor, MappingToObject};
use UniMethod\Frame\Mapping\Exception\TargetTypeMismatchException;
use UniMethod\Frame\Persistence\ListLocators;
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Tests\Unit\Stub\{Post, RecordingLocator, RecordingSaver, Writer};
use UniMethod\Frame\Type\{Caster, TypeSpecParser};

/**
 * MappingToObject::map(..., target): for Update an already loaded entity
 * is written in place, the mapper does not go to storage.
 *
 * MappingToObject::map(..., target): для Update уже загруженная сущность
 * пишется на месте, маппер в хранилище не ходит.
 */
final class MappingToObjectUpdateTargetTest extends TestCase
{
    public function testUpdateWritesIntoGivenTargetWithoutLocating(): void
    {
        $locator = new RecordingLocator(new Post(id: "1", title: "From locator"));
        $mapper = $this->mapper($locator);

        $target = new Post(id: "1", title: "Before");
        $body = (object) ["title" => "After"];

        $entity = $mapper->map("post", $body, ActionType::Update, "", "1", [], $target);

        self::assertSame($target, $entity);
        self::assertSame("After", $target->title);
        self::assertSame(0, $locator->findCalls);
    }

    public function testUpdateWithoutTargetStillLocates(): void
    {
        $locator = new RecordingLocator(new Post(id: "1", title: "From locator"));
        $mapper = $this->mapper($locator);

        $entity = $mapper->map("post", (object) ["title" => "After"], ActionType::Update, "", "1");

        self::assertSame("After", $entity->title);
        self::assertSame(1, $locator->findCalls);
    }

    public function testTargetOfWrongClassIsConfigurationError(): void
    {
        $mapper = $this->mapper(new RecordingLocator());

        $this->expectException(TargetTypeMismatchException::class);

        $mapper->map("post", new stdClass(), ActionType::Update, "", "1", [], new Writer(id: "1"));
    }

    /**
     * The action locator is passed as an argument rather than stored on the service:
     * two consecutive updates of different models each use their own.
     *
     * Локатор действия передаётся аргументом, а не хранится на сервисе:
     * два последовательных update разных моделей используют каждый свой.
     */
    public function testSequentialUpdatesUseTheirOwnActionLocators(): void
    {
        $postLocator = new RecordingLocator(new Post(id: "1", title: "P"));
        $writerLocator = new RecordingLocator(new Writer(id: "2", name: "W"));

        $config = new Config(
            models: [
                new Model(
                    class: Post::class,
                    type: "post",
                    attributes: [
                        new Attribute(name: "id", type: "string|null"),
                        new Attribute(name: "title", type: "string|null"),
                    ],
                ),
                new Model(
                    class: Writer::class,
                    type: "writer",
                    attributes: [
                        new Attribute(name: "id", type: "string|null"),
                        new Attribute(name: "name", type: "string|null"),
                    ],
                ),
            ],
            actions: [
                new Action(type: ActionType::Update, model: "post", entityLocator: "postLoc", entitySaver: "saver"),
                new Action(type: ActionType::Update, model: "writer", entityLocator: "writerLoc", entitySaver: "saver"),
            ],
            locators: ["postLoc" => RecordingLocator::class, "writerLoc" => RecordingLocator::class],
            savers: ["saver" => RecordingSaver::class],
        );

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            new ListLocators(["postLoc" => $postLocator, "writerLoc" => $writerLocator]),
            new AttributeAccessor(),
            $config,
        );

        $post = $mapper->map("post", (object) ["title" => "P2"], ActionType::Update, "", "1");
        $writer = $mapper->map("writer", (object) ["name" => "W2"], ActionType::Update, "", "2");

        self::assertSame("P2", $post->title);
        self::assertSame("W2", $writer->name);
        self::assertSame(1, $postLocator->findCalls);
        self::assertSame(1, $writerLocator->findCalls);
    }

    private function mapper(RecordingLocator $locator): MappingToObject
    {
        $config = new Config(
            models: [
                new Model(
                    class: Post::class,
                    type: "post",
                    attributes: [
                        new Attribute(name: "id", type: "string|null"),
                        new Attribute(name: "title", type: "string|null"),
                    ],
                ),
            ],
            actions: [
                new Action(type: ActionType::Update, model: "post", entityLocator: "loc", entitySaver: "saver"),
            ],
            locators: ["loc" => RecordingLocator::class],
            savers: ["saver" => RecordingSaver::class],
        );

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            new ListLocators(["loc" => $locator]),
            new AttributeAccessor(),
        );
        $mapper->setConfig($config);

        return $mapper;
    }
}
