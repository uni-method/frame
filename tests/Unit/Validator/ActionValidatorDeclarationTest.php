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
use UniMethod\Frame\Tests\Unit\Stub\{
    Address,
    Post,
    RecordingLocator,
    RecordingSaver,
    Writer,
};
use UniMethod\Frame\Validator\ActionValidator;
use UniMethod\Frame\Validator\Exception\{
    DeclaredRelationMissingException,
    UndeclaredRelationException,
};

/**
 * ActionValidator::validateDeclaration: on create/update `?included=` is
 * the input declaration, the body must match it.
 *
 * ActionValidator::validateDeclaration: на create/update `?included=` —
 * декларация входа, тело обязано ей соответствовать.
 */
final class ActionValidatorDeclarationTest extends TestCase
{
    /**
     * A relation in the body without a declaration → UndeclaredRelationException (422).
     *
     * Связь в теле без декларации → UndeclaredRelationException (422).
     */
    public function testUndeclaredRelationInBodyIsRejected(): void
    {
        $validator = $this->validator([
            "author" => new WritableConfig(RelationWriteMode::Create),
        ]);

        $body = (object) ["title" => "T", "author" => (object) ["name" => "A"]];

        $this->expectException(UndeclaredRelationException::class);
        $this->expectExceptionMessageMatches("/Relation 'author' of model 'post'/");

        $validator->validateDeclaration(ActionType::Create, "post", $body, "");
    }

    /**
     * A declared path must be in the body → DeclaredRelationMissingException (422).
     *
     * Задекларированный путь обязан быть в теле → DeclaredRelationMissingException (422).
     */
    public function testDeclaredRelationMissingInBodyIsRejected(): void
    {
        $validator = $this->validator([
            "author" => new WritableConfig(RelationWriteMode::Create),
        ]);

        $this->expectException(DeclaredRelationMissingException::class);

        $validator->validateDeclaration(ActionType::Create, "post", (object) ["title" => "T"], "author");
    }

    /**
     * A declared relation in the body is accepted; `null` under a declaration is
     * a valid unlinking.
     *
     * Задекларированная связь в теле принимается; `null` под декларацией —
     * валидное отвязывание.
     */
    public function testDeclaredRelationPresentInBodyIsAccepted(): void
    {
        $validator = $this->validator([
            "author" => new WritableConfig(RelationWriteMode::Create),
        ]);

        $validator->validateDeclaration(
            ActionType::Create,
            "post",
            (object) ["title" => "T", "author" => (object) ["name" => "A"]],
            "author",
        );
        $validator->validateDeclaration(
            ActionType::Create,
            "post",
            (object) ["title" => "T", "author" => null],
            "author",
        );

        $this->addToAssertionCount(1);
    }

    /**
     * A nested path is declared separately: `author` does not declare
     * `author.address`, even if both paths are in writable.
     *
     * Вложенный путь декларируется отдельно: `author` не декларирует
     * `author.address`, даже если оба пути в writable.
     */
    public function testNestedPathInBodyMustBeDeclared(): void
    {
        $validator = $this->validator([
            "author" => new WritableConfig(RelationWriteMode::Create),
            "author.address" => new WritableConfig(RelationWriteMode::Create),
        ]);

        $body = (object) [
            "title" => "T",
            "author" => (object) [
                "name" => "A",
                "address" => (object) ["city" => "Nicosia"],
            ],
        ];

        $this->expectException(UndeclaredRelationException::class);

        $validator->validateDeclaration(ActionType::Create, "post", $body, "author");
    }

    /**
     * post → author (writer) → address.
     *
     * @param array<string, WritableConfig> $writable
     */
    private function validator(array $writable): ActionValidator
    {
        $config = new Config(
            models: [
                new Model(
                    class: Post::class,
                    type: "post",
                    attributes: [
                        new Attribute(name: "id", type: "string|null"),
                        new Attribute(name: "title", type: "string"),
                    ],
                    relations: [
                        new Relation(name: "author", type: Relation::MANY_TO_ONE, targetModel: "writer"),
                    ],
                ),
                new Model(
                    class: Writer::class,
                    type: "writer",
                    attributes: [
                        new Attribute(name: "id", type: "string|null"),
                        new Attribute(name: "name", type: "string"),
                    ],
                    relations: [
                        new Relation(name: "address", type: Relation::ONE_TO_ONE, targetModel: "address"),
                    ],
                ),
                new Model(
                    class: Address::class,
                    type: "address",
                    attributes: [
                        new Attribute(name: "id", type: "string|null"),
                        new Attribute(name: "city", type: "string"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::Create,
                    model: "post",
                    writable: $writable,
                    entityLocator: "loc",
                    entitySaver: "saver",
                ),
                new Action(type: ActionType::Read, model: "writer", entityLocator: "loc"),
            ],
            locators: ["loc" => RecordingLocator::class],
            savers: ["saver" => RecordingSaver::class],
        );

        $validator = new ActionValidator(new TreeBuilder());
        $validator->setConfig($config);

        return $validator;
    }
}
