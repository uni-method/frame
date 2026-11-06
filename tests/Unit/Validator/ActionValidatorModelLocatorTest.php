<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Validator;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{
    Action,
    ActionType,
    Attribute,
    Config,
    Model,
    Relation,
    RelationWriteMode,
    WritableConfig,
};
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Tests\Unit\Stub\{Post, RecordingLocator, Writer};
use UniMethod\Frame\Validator\ActionValidator;
use UniMethod\Frame\Validator\Exception\DtoReferencedException;

/**
 * A reference (`reference`) is allowed if the target model has a locator: its own,
 * of the read action, or impl.defaultLocator.
 *
 * Ссылка (`reference`) допустима, если у целевой модели есть локатор: свой,
 * read-действия или impl.defaultLocator.
 */
final class ActionValidatorModelLocatorTest extends TestCase
{
    public function testReferenceWithModelLocatorPasses(): void
    {
        $this->validator(writerLocator: "loc")->validateStd(ActionType::Create, "post", $this->body(), "author");

        $this->addToAssertionCount(1);
    }

    public function testReferenceWithDefaultLocatorPasses(): void
    {
        $this->validator(defaultLocator: "loc")->validateStd(ActionType::Create, "post", $this->body(), "author");

        $this->addToAssertionCount(1);
    }

    public function testReferenceWithoutLocatorIsConfigurationError(): void
    {
        $this->expectException(DtoReferencedException::class);
        $this->expectExceptionMessage("Set impl.models[writer].entityLocator");

        $this->validator()->validateStd(ActionType::Create, "post", $this->body(), "author");
    }

    private function body(): \stdClass
    {
        return (object) ["title" => "T", "author" => (object) ["id" => "5"]];
    }

    private function validator(?string $writerLocator = null, ?string $defaultLocator = null): ActionValidator
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
                    relations: [new Relation(name: "author", type: Relation::MANY_TO_ONE, targetModel: "writer")],
                ),
                new Model(
                    class: Writer::class,
                    type: "writer",
                    attributes: [new Attribute(name: "id", type: "string|null")],
                    entityLocator: $writerLocator,
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::Create,
                    model: "post",
                    writable: ["author" => new WritableConfig(RelationWriteMode::Reference)],
                ),
            ],
            locators: ["loc" => RecordingLocator::class],
            defaultLocator: $defaultLocator,
        );

        $validator = new ActionValidator(new TreeBuilder());
        $validator->setConfig($config);

        return $validator;
    }
}
