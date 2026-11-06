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
use UniMethod\Frame\Mapping\Exception\MissingIdAttributeException;
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Tests\Unit\Stub\{Post, RecordingLocator, RecordingSaver, Writer};
use UniMethod\Frame\Validator\ActionValidator;

/**
 * A reference to a model without an `id` attribute is a configuration error already in the validator,
 * before calling the locator. The loader will not let such a config through
 * (validateModelIds); the check covers manual assembly of Config.
 *
 * Ссылка на модель без атрибута `id` — ошибка конфигурации уже на валидаторе,
 * до обращения к локатору. Загрузчик такой конфиг не пропустит
 * (validateModelIds); проверка закрывает ручную сборку Config.
 */
final class ActionValidatorMissingIdTest extends TestCase
{
    public function testReferenceToModelWithoutIdIsConfigurationError(): void
    {
        $config = new Config(
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
                    // no id
                    //
                    // нет id
                    attributes: [new Attribute(name: "name", type: "string|null")],
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::Create,
                    model: "post",
                    writable: ["author" => new WritableConfig(RelationWriteMode::Reference)],
                    entitySaver: "saver",
                ),
                new Action(type: ActionType::Read, model: "writer", entityLocator: "loc"),
            ],
            locators: ["loc" => RecordingLocator::class],
            savers: ["saver" => RecordingSaver::class],
        );

        $validator = new ActionValidator(new TreeBuilder(), $config);

        $this->expectException(MissingIdAttributeException::class);
        $this->expectExceptionMessage(
            "Model 'writer' has no 'id' attribute but is used as a reference target " .
            "(ActionValidator, relation 'author')",
        );

        $validator->validateStd(
            ActionType::Create,
            "post",
            (object) ["author" => (object) ["id" => "1"]],
            "author",
        );
    }
}
