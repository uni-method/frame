<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Validator;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\ActionType;
use UniMethod\Frame\Config\Common\Impl\{
    ImplAttribute,
    ImplConfig,
    ImplModel,
    ImplRelation,
};
use UniMethod\Frame\Config\Common\Schema\{
    SchemaAction,
    SchemaAttribute,
    SchemaConfig,
    SchemaModel,
    SchemaRelation,
};
use UniMethod\Frame\Validator\ConfigValidator;
use UniMethod\Frame\Validator\Exception\InvalidConfigStructureException;

final class ConfigValidatorNoMissingImplTest extends TestCase
{
    private ConfigValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ConfigValidator();
    }

    public function testPassesWhenAllSchemaHasImpl(): void
    {
        $this->expectNotToPerformAssertions();

        $schema = new SchemaConfig(
            models: [
                new SchemaModel(
                    type: "user",
                    attributes: [new SchemaAttribute(name: "id", type: "int")],
                    relations: [
                        new SchemaRelation(
                            name: "profile",
                            type: "oneToOne",
                            targetModel: "profile",
                        ),
                    ],
                ),
            ],
        );

        $impl = new ImplConfig(
            models: [
                new ImplModel(
                    type: "user",
                    class: "App\\User",
                    attributes: [
                        new ImplAttribute(name: "id", getter: "getId"),
                    ],
                    relations: [
                        new ImplRelation(name: "profile", getter: "getProfile"),
                    ],
                ),
            ],
        );

        $this->validator->validateNoMissingImpl($schema, $impl);
    }

    public function testPassesWithEmptySchema(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator->validateNoMissingImpl(
            new SchemaConfig(),
            new ImplConfig(),
        );
    }

    public function testFailsWhenSchemaModelNotInImpl(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "schema.models[user] has no corresponding model in impl",
        );

        $schema = new SchemaConfig(
            models: [
                new SchemaModel(
                    type: "user",
                    attributes: [new SchemaAttribute(name: "id", type: "int")],
                ),
            ],
        );

        $this->validator->validateNoMissingImpl($schema, new ImplConfig());
    }

    public function testFailsWhenSchemaAttributeNotInImpl(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "schema.models[user].attributes[email] has no corresponding attribute in impl",
        );

        $schema = new SchemaConfig(
            models: [
                new SchemaModel(
                    type: "user",
                    attributes: [
                        new SchemaAttribute(name: "id", type: "int"),
                        new SchemaAttribute(name: "email", type: "string"),
                    ],
                ),
            ],
        );

        $impl = new ImplConfig(
            models: [
                new ImplModel(
                    type: "user",
                    class: "App\\User",
                    attributes: [
                        new ImplAttribute(name: "id", getter: "getId"),
                    ],
                ),
            ],
        );

        $this->validator->validateNoMissingImpl($schema, $impl);
    }

    public function testFailsWhenSchemaRelationNotInImpl(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "schema.models[user].relations[profile] has no corresponding relation in impl",
        );

        $schema = new SchemaConfig(
            models: [
                new SchemaModel(
                    type: "user",
                    attributes: [new SchemaAttribute(name: "id", type: "int")],
                    relations: [
                        new SchemaRelation(
                            name: "profile",
                            type: "oneToOne",
                            targetModel: "profile",
                        ),
                    ],
                ),
            ],
        );

        $impl = new ImplConfig(
            models: [
                new ImplModel(
                    type: "user",
                    class: "App\\User",
                    attributes: [
                        new ImplAttribute(name: "id", getter: "getId"),
                    ],
                ),
            ],
        );

        $this->validator->validateNoMissingImpl($schema, $impl);
    }

    public function testActionsAreNotRequiredInImpl(): void
    {
        $this->expectNotToPerformAssertions();

        $schema = new SchemaConfig(
            models: [
                new SchemaModel(
                    type: "user",
                    attributes: [new SchemaAttribute(name: "id", type: "int")],
                ),
            ],
            actions: [new SchemaAction(type: ActionType::Read, model: "user")],
        );

        $impl = new ImplConfig(
            models: [
                new ImplModel(
                    type: "user",
                    class: "App\\User",
                    attributes: [
                        new ImplAttribute(name: "id", getter: "getId"),
                    ],
                ),
            ],
        );

        $this->validator->validateNoMissingImpl($schema, $impl);
    }
}
