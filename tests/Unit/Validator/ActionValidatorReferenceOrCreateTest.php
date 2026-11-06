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
use UniMethod\Frame\Persistence\{
    EntityLocatorInterface,
    LocatorNotConfiguredException,
};
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Validator\ActionValidator;
use UniMethod\Frame\Validator\Exception\RelationModeNotAllowedException;

/**
 * ReferenceOrCreate mode: the relation accepts both a reference by id and a nested object.
 *
 * Режим ReferenceOrCreate: связь принимает и ссылку по id, и вложенный объект.
 */
final class ActionValidatorReferenceOrCreateTest extends TestCase
{
    private ActionValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ActionValidator(new TreeBuilder());
        $this->validator->setConfig(
            $this->config(["default" => EntityLocatorInterface::class]),
        );
    }

    public function testReferencePayloadIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) [
            "title" => "Полив",
            "taskType" => (object) ["id" => "tt-1"],
        ];

        $this->validator->validateStd(ActionType::Create, "rule", $data);
    }

    public function testCreatePayloadIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) [
            "title" => "Досветка",
            "taskType" => (object) [
                "code" => "lighting",
                "name" => "Освещение",
            ],
        ];

        $this->validator->validateStd(ActionType::Create, "rule", $data);
    }

    public function testIdWithExtraFieldsIsAcceptedAsCreate(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) [
            "title" => "Досветка",
            "taskType" => (object) [
                "id" => "tt-1",
                "code" => "lighting",
                "name" => "Освещение",
            ],
        ];

        $this->validator->validateStd(ActionType::Create, "rule", $data);
    }

    public function testCollectionMixesReferenceAndCreate(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) [
            "title" => "Смешанная",
            "taskTypes" => [
                (object) ["id" => "tt-1"],
                (object) ["code" => "lighting", "name" => "Освещение"],
            ],
        ];

        $this->validator->validateStd(ActionType::Create, "rule", $data);
    }

    public function testStrictReferenceRelationStillRejectsCreatePayload(): void
    {
        $data = (object) [
            "title" => "Полив",
            "version" => (object) ["label" => "v1"],
        ];

        $this->expectException(RelationModeNotAllowedException::class);
        $this->expectExceptionMessage(
            "Relation 'version' in model 'rule' does not allow mode 'create' for action 'create'.",
        );

        $this->validator->validateStd(ActionType::Create, "rule", $data);
    }

    public function testReferencePayloadStillRequiresConfiguredLocator(): void
    {
        $validator = new ActionValidator(new TreeBuilder());
        $validator->setConfig($this->config([]));

        $data = (object) [
            "title" => "Полив",
            "taskType" => (object) ["id" => "tt-1"],
        ];

        $this->expectException(LocatorNotConfiguredException::class);

        $validator->validateStd(ActionType::Create, "rule", $data);
    }

    public function testCreatePayloadDoesNotRequireLocator(): void
    {
        $this->expectNotToPerformAssertions();

        $validator = new ActionValidator(new TreeBuilder());
        $validator->setConfig($this->config([]));

        $data = (object) [
            "title" => "Досветка",
            "taskType" => (object) [
                "code" => "lighting",
                "name" => "Освещение",
            ],
        ];

        $validator->validateStd(ActionType::Create, "rule", $data);
    }

    /**
     * @param array<string, class-string<EntityLocatorInterface>> $locators
     */
    private function config(array $locators): Config
    {
        return new Config(
            models: [
                new Model(
                    class: "App\\Rule",
                    type: "rule",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                        new Attribute(
                            name: "title",
                            type: "string",
                            setter: "setTitle",
                        ),
                    ],
                    relations: [
                        new Relation(
                            name: "taskType",
                            type: "manyToOne",
                            targetModel: "taskType",
                            setter: "setTaskType",
                        ),
                        new Relation(
                            name: "taskTypes",
                            type: "oneToMany",
                            targetModel: "taskType",
                            setter: "setTaskTypes",
                        ),
                        new Relation(
                            name: "version",
                            type: "manyToOne",
                            targetModel: "version",
                            setter: "setVersion",
                        ),
                    ],
                ),
                new Model(
                    class: "App\\TaskType",
                    type: "taskType",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                        new Attribute(
                            name: "code",
                            type: "string",
                            setter: "setCode",
                        ),
                        new Attribute(
                            name: "name",
                            type: "string",
                            setter: "setName",
                        ),
                    ],
                    relations: [],
                ),
                new Model(
                    class: "App\\Version",
                    type: "version",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                        new Attribute(
                            name: "label",
                            type: "string",
                            setter: "setLabel",
                        ),
                    ],
                    relations: [],
                ),
            ],
            actions: [
                new Action(type: ActionType::Read, model: "taskType", entityLocator: "default"),
                new Action(type: ActionType::Read, model: "version", entityLocator: "default"),
                new Action(
                    type: ActionType::Create,
                    model: "rule",
                    writable: [
                        "taskType" => new WritableConfig(
                            mode: RelationWriteMode::ReferenceOrCreate,
                        ),
                        "taskTypes" => new WritableConfig(
                            mode: RelationWriteMode::ReferenceOrCreate,
                        ),
                        "version" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                        ),
                    ],
                    entityLocator: "default",
                ),
            ],
            locators: $locators,
        );
    }
}
