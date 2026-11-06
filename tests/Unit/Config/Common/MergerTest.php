<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Config\Common;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use stdClass;
use UniMethod\Frame\Config\{AccessConfig, ActionType};
use UniMethod\Frame\Config\Common\Impl\{
    ImplAction,
    ImplAttribute,
    ImplConfig,
    ImplListConfig,
    ImplModel,
    ImplRelation,
};
use UniMethod\Frame\Config\Common\Merger;
use UniMethod\Frame\Config\Common\Schema\{
    SchemaAction,
    SchemaAttribute,
    SchemaConfig,
    SchemaListConfig,
    SchemaModel,
    SchemaRelation,
    SchemaWriteMode,
};
use UniMethod\Frame\Config\ListAction\{
    FilterOperator,
    FilterRule,
    PaginationRule,
    SortDirection,
    SortRule,
};
use UniMethod\Frame\Config\{Mutators, RelationWriteMode, WritableConfig};

final class MergerTest extends TestCase
{
    private Merger $merger;

    protected function setUp(): void
    {
        $this->merger = new Merger();
    }

    public function testMergeEmptyConfigs(): void
    {
        $config = $this->merger->merge(new SchemaConfig(), new ImplConfig());

        $this->assertCount(0, $config->models);
        $this->assertCount(0, $config->actions);
        $this->assertSame([], $config->locators);
        $this->assertSame([], $config->savers);
        $this->assertSame([], $config->removers);
        $this->assertSame([], $config->mutators);
        $this->assertSame([], $config->relationMutators);
        $this->assertNull($config->userModel);
    }

    public function testMergeImplMetadataPassedThrough(): void
    {
        $config = $this->merger->merge(
            new SchemaConfig(),
            new ImplConfig(
                locators: ["doctrine" => "App\Locator\DoctrineLocator"],
                savers: ["doctrine" => "App\Saver\DoctrineSaver"],
                removers: ["doctrine" => "App\Remover\DoctrineRemover"],
                mutators: ["hasher" => "App\Mutator\Hasher"],
                relationMutators: ["rel" => "App\Mutator\RelMutator"],
                userModel: "user",
            ),
        );

        $this->assertSame(
            ["doctrine" => "App\Locator\DoctrineLocator"],
            $config->locators,
        );
        $this->assertSame(
            ["doctrine" => "App\Saver\DoctrineSaver"],
            $config->savers,
        );
        $this->assertSame(
            ["doctrine" => "App\Remover\DoctrineRemover"],
            $config->removers,
        );
        $this->assertSame(
            ["hasher" => "App\Mutator\Hasher"],
            $config->mutators,
        );
        $this->assertSame(
            ["rel" => "App\Mutator\RelMutator"],
            $config->relationMutators,
        );
        $this->assertSame("user", $config->userModel);
    }

    public function testMergeModelClassFromImpl(): void
    {
        $config = $this->merger->merge(
            new SchemaConfig(
                models: [
                    new SchemaModel(
                        type: "user",
                        attributes: [new SchemaAttribute("id", "int")],
                    ),
                ],
            ),
            new ImplConfig(
                models: [new ImplModel(type: "user", class: "App\Entity\User")],
            ),
        );

        $this->assertCount(1, $config->models);
        $this->assertSame("App\Entity\User", $config->models[0]->class);
        $this->assertSame("user", $config->models[0]->type);
    }

    public function testMergeModelWithNoImplHasEmptyClass(): void
    {
        $config = $this->merger->merge(
            new SchemaConfig(
                models: [
                    new SchemaModel(
                        type: "user",
                        attributes: [new SchemaAttribute("id", "int")],
                    ),
                ],
            ),
            new ImplConfig(),
        );

        $this->assertSame("", $config->models[0]->class);
    }

    public function testMergeModelCommentFromSchema(): void
    {
        $config = $this->merger->merge(
            new SchemaConfig(
                models: [
                    new SchemaModel(
                        type: "user",
                        attributes: [new SchemaAttribute("id", "int")],
                        comment: "Пользователь системы",
                    ),
                ],
            ),
            new ImplConfig(
                models: [new ImplModel(type: "user", class: "App\Entity\User")],
            ),
        );

        $this->assertSame("Пользователь системы", $config->models[0]->comment);
    }

    public function testMergeAttributeGetterSetterFromImpl(): void
    {
        $config = $this->merger->merge(
            new SchemaConfig(
                models: [
                    new SchemaModel(
                        type: "user",
                        attributes: [
                            new SchemaAttribute("id", "int"),
                            new SchemaAttribute("email", "string"),
                        ],
                    ),
                ],
            ),
            new ImplConfig(
                models: [
                    new ImplModel(
                        type: "user",
                        class: "App\Entity\User",
                        attributes: [
                            new ImplAttribute(
                                "id",
                                getter: "getId",
                                setter: false,
                            ),
                            new ImplAttribute(
                                "email",
                                getter: "getEmail",
                                setter: "setEmail",
                            ),
                        ],
                    ),
                ],
            ),
        );

        $attrs = $config->models[0]->attributes;
        $this->assertSame("getId", $attrs[0]->getter);
        $this->assertFalse($attrs[0]->setter);
        $this->assertSame("getEmail", $attrs[1]->getter);
        $this->assertSame("setEmail", $attrs[1]->setter);
    }

    public function testMergeAttributeWriteModeReadOnlyForcesFalseSetterAndNoConstructorParam(): void
    {
        $config = $this->merger->merge(
            new SchemaConfig(
                models: [
                    new SchemaModel(
                        type: "user",
                        attributes: [
                            new SchemaAttribute(
                                "id",
                                "int",
                                writeMode: SchemaWriteMode::ReadOnly,
                            ),
                        ],
                    ),
                ],
            ),
            new ImplConfig(
                models: [
                    new ImplModel(
                        type: "user",
                        class: "App\Entity\User",
                        attributes: [
                            new ImplAttribute(
                                "id",
                                getter: "getId",
                                setter: "setId",
                                constructorParam: true,
                            ),
                        ],
                    ),
                ],
            ),
        );

        $attr = $config->models[0]->attributes[0];
        $this->assertFalse($attr->setter);
        $this->assertFalse($attr->constructorParam);
    }

    public function testMergeAttributeWriteModeCreateOnlyForcesConstructorParam(): void
    {
        $config = $this->merger->merge(
            new SchemaConfig(
                models: [
                    new SchemaModel(
                        type: "order",
                        attributes: [
                            new SchemaAttribute(
                                "day",
                                "string",
                                writeMode: SchemaWriteMode::CreateOnly,
                            ),
                        ],
                    ),
                ],
            ),
            new ImplConfig(
                models: [
                    new ImplModel(
                        type: "order",
                        class: "App\Entity\Order",
                        attributes: [
                            new ImplAttribute("day", getter: "getDay"),
                        ],
                    ),
                ],
            ),
        );

        $attr = $config->models[0]->attributes[0];
        $this->assertFalse($attr->setter);
        $this->assertTrue($attr->constructorParam);
    }

    public function testMergeAttributeNoWriteModeUsesImplValues(): void
    {
        $config = $this->merger->merge(
            new SchemaConfig(
                models: [
                    new SchemaModel(
                        type: "user",
                        attributes: [new SchemaAttribute("email", "string")],
                    ),
                ],
            ),
            new ImplConfig(
                models: [
                    new ImplModel(
                        type: "user",
                        class: "App\Entity\User",
                        attributes: [
                            new ImplAttribute(
                                "email",
                                getter: "getEmail",
                                setter: "setEmail",
                                constructorParam: false,
                            ),
                        ],
                    ),
                ],
            ),
        );

        $attr = $config->models[0]->attributes[0];
        $this->assertSame("setEmail", $attr->setter);
        $this->assertFalse($attr->constructorParam);
    }

    public function testMergeAttributeMutatorsFromSchema(): void
    {
        $mutators = new Mutators(beforeOutput: ["hide"]);

        $config = $this->merger->merge(
            new SchemaConfig(
                models: [
                    new SchemaModel(
                        type: "user",
                        attributes: [
                            new SchemaAttribute(
                                "password",
                                "string",
                                mutators: $mutators,
                            ),
                        ],
                    ),
                ],
            ),
            new ImplConfig(
                models: [
                    new ImplModel(
                        type: "user",
                        class: "App\Entity\User",
                        attributes: [
                            new ImplAttribute(
                                "password",
                                getter: "getPassword",
                                setter: "setPassword",
                            ),
                        ],
                    ),
                ],
            ),
        );

        $this->assertSame(
            ["hide"],
            $config->models[0]->attributes[0]->mutators->beforeOutput,
        );
    }

    public function testMergeRelationFromSchemaAndImpl(): void
    {
        $config = $this->merger->merge(
            new SchemaConfig(
                models: [
                    new SchemaModel(
                        type: "user",
                        attributes: [new SchemaAttribute("id", "int")],
                        relations: [
                            new SchemaRelation(
                                "posts",
                                "oneToMany",
                                "post",
                                comment: "User posts",
                            ),
                        ],
                    ),
                    new SchemaModel(
                        type: "post",
                        attributes: [new SchemaAttribute("id", "int")],
                    ),
                ],
            ),
            new ImplConfig(
                models: [
                    new ImplModel(
                        type: "user",
                        class: "App\Entity\User",
                        relations: [
                            new ImplRelation(
                                "posts",
                                getter: "getPostsAsArray",
                                add: "addPost",
                            ),
                        ],
                    ),
                    new ImplModel(type: "post", class: "App\Entity\Post"),
                ],
            ),
        );

        $rel = $config->models[0]->relations[0];
        $this->assertSame("posts", $rel->name);
        $this->assertSame("oneToMany", $rel->type);
        $this->assertSame("post", $rel->targetModel);
        $this->assertSame("getPostsAsArray", $rel->getter);
        $this->assertSame("addPost", $rel->add);
        $this->assertSame("User posts", $rel->comment);
    }

    public function testMergeRelationWithNoImplHasNullGetterSetterAdd(): void
    {
        $config = $this->merger->merge(
            new SchemaConfig(
                models: [
                    new SchemaModel(
                        type: "user",
                        attributes: [new SchemaAttribute("id", "int")],
                        relations: [
                            new SchemaRelation(
                                "profile",
                                "oneToOne",
                                "profile",
                            ),
                        ],
                    ),
                    new SchemaModel(
                        type: "profile",
                        attributes: [new SchemaAttribute("id", "int")],
                    ),
                ],
            ),
            new ImplConfig(
                models: [
                    new ImplModel(type: "user", class: "App\Entity\User"),
                    new ImplModel(type: "profile", class: "App\Entity\Profile"),
                ],
            ),
        );

        $rel = $config->models[0]->relations[0];
        $this->assertNull($rel->getter);
        $this->assertNull($rel->setter);
        $this->assertNull($rel->add);
    }

    public function testMergeRelationMutatorsFromSchema(): void
    {
        $mutators = new Mutators(beforeOutput: ["myMutator"]);

        $config = $this->merger->merge(
            new SchemaConfig(
                models: [
                    new SchemaModel(
                        type: "user",
                        attributes: [new SchemaAttribute("id", "int")],
                        relations: [
                            new SchemaRelation(
                                "stat",
                                "oneToOne",
                                "stat",
                                mutators: $mutators,
                            ),
                        ],
                    ),
                    new SchemaModel(
                        type: "stat",
                        attributes: [new SchemaAttribute("id", "int")],
                    ),
                ],
            ),
            new ImplConfig(
                models: [
                    new ImplModel(type: "user", class: "App\Entity\User"),
                    new ImplModel(type: "stat", class: "App\Entity\Stat"),
                ],
            ),
        );

        $this->assertSame(
            ["myMutator"],
            $config->models[0]->relations[0]->mutators->beforeOutput,
        );
    }

    public function testMergeActionFromSchemaAndImpl(): void
    {
        $access = new AccessConfig(authRequired: true, roles: ["ROLE_ADMIN"]);

        $config = $this->merger->merge(
            new SchemaConfig(
                actions: [
                    new SchemaAction(
                        type: ActionType::Create,
                        model: "user",
                        access: $access,
                        include: ["profile"],
                        comment: "Create user",
                    ),
                ],
            ),
            new ImplConfig(
                actions: [
                    new ImplAction(
                        type: ActionType::Create,
                        model: "user",
                        entitySaver: "doctrine",
                    ),
                ],
            ),
        );

        $action = $config->actions[0];
        $this->assertSame(ActionType::Create, $action->type);
        $this->assertSame("user", $action->model);
        $this->assertSame("doctrine", $action->entitySaver);
        $this->assertSame(["profile"], $action->include);
        $this->assertSame("Create user", $action->comment);
        $this->assertSame(["ROLE_ADMIN"], $action->access->roles);
    }

    public function testMergeActionWithNoImplHasNullLocatorSaver(): void
    {
        $config = $this->merger->merge(
            new SchemaConfig(
                actions: [
                    new SchemaAction(type: ActionType::List, model: "user"),
                ],
            ),
            new ImplConfig(),
        );

        $action = $config->actions[0];
        $this->assertNull($action->entityLocator);
        $this->assertNull($action->entitySaver);
        $this->assertNull($action->entityRemover);
    }

    public function testMergeActionWritableFromSchema(): void
    {
        $writable = [
            "office" => new WritableConfig(RelationWriteMode::Reference),
        ];

        $config = $this->merger->merge(
            new SchemaConfig(
                actions: [
                    new SchemaAction(
                        type: ActionType::Create,
                        model: "link",
                        writable: $writable,
                    ),
                ],
            ),
            new ImplConfig(),
        );

        $this->assertSame($writable, $config->actions[0]->writable);
    }

    public function testMergeListActionConfig(): void
    {
        $pagination = new PaginationRule(maxSize: 100, defaultSize: 20);
        $sort = [
            new SortRule(
                path: "createdAt",
                defaultDirection: SortDirection::Desc,
            ),
        ];
        $filters = [
            new FilterRule(path: "status", operators: [FilterOperator::Equal]),
        ];

        $config = $this->merger->merge(
            new SchemaConfig(
                actions: [
                    new SchemaAction(
                        type: ActionType::List,
                        model: "link",
                        listConfig: new SchemaListConfig(
                            pagination: $pagination,
                            sort: $sort,
                            filters: $filters,
                        ),
                    ),
                ],
            ),
            new ImplConfig(
                actions: [
                    new ImplAction(
                        type: ActionType::List,
                        model: "link",
                        listConfig: new ImplListConfig(
                            class: stdClass::class,
                            repository: ArrayObject::class,
                        ),
                    ),
                ],
            ),
        );

        $listConfig = $config->actions[0]->config;
        $this->assertNotNull($listConfig);
        $this->assertSame(stdClass::class, $listConfig->class);
        $this->assertSame(ArrayObject::class, $listConfig->repository);
        $this->assertSame($pagination, $listConfig->pagination);
        $this->assertSame($sort, $listConfig->sort);
        $this->assertSame($filters, $listConfig->filters);
    }

    public function testMergeListConfigNullWhenNoSchemaListConfig(): void
    {
        $config = $this->merger->merge(
            new SchemaConfig(
                actions: [
                    new SchemaAction(type: ActionType::Create, model: "user"),
                ],
            ),
            new ImplConfig(
                actions: [
                    new ImplAction(
                        type: ActionType::Create,
                        model: "user",
                        entitySaver: "doctrine",
                        listConfig: new ImplListConfig(
                            class: stdClass::class,
                            repository: ArrayObject::class,
                        ),
                    ),
                ],
            ),
        );

        $this->assertNull($config->actions[0]->config);
    }

    public function testMergeMultipleModelsAndActions(): void
    {
        $config = $this->merger->merge(
            new SchemaConfig(
                models: [
                    new SchemaModel("user", [new SchemaAttribute("id", "int")]),
                    new SchemaModel("post", [new SchemaAttribute("id", "int")]),
                ],
                actions: [
                    new SchemaAction(ActionType::List, "user"),
                    new SchemaAction(ActionType::Create, "post"),
                    new SchemaAction(ActionType::Update, "post"),
                ],
            ),
            new ImplConfig(
                models: [
                    new ImplModel("user", "App\Entity\User"),
                    new ImplModel("post", "App\Entity\Post"),
                ],
                actions: [
                    new ImplAction(
                        ActionType::List,
                        "user",
                        entityLocator: "doctrine",
                    ),
                    new ImplAction(
                        ActionType::Create,
                        "post",
                        entitySaver: "doctrine",
                    ),
                    new ImplAction(
                        ActionType::Update,
                        "post",
                        entityLocator: "doctrine",
                        entitySaver: "doctrine",
                    ),
                ],
            ),
        );

        $this->assertCount(2, $config->models);
        $this->assertCount(3, $config->actions);
        $this->assertSame("doctrine", $config->actions[0]->entityLocator);
        $this->assertSame("doctrine", $config->actions[1]->entitySaver);
        $this->assertSame("doctrine", $config->actions[2]->entityLocator);
        $this->assertSame("doctrine", $config->actions[2]->entitySaver);
    }

    public function testMergeAttributeAndRelationCommentComeFromSchema(): void
    {
        $config = $this->merger->merge(
            new SchemaConfig(
                models: [
                    new SchemaModel(
                        type: "user",
                        attributes: [
                            new SchemaAttribute(
                                "id",
                                "int",
                                comment: "schema attr comment",
                            ),
                        ],
                        relations: [
                            new SchemaRelation(
                                name: "profile",
                                type: "oneToOne",
                                targetModel: "profile",
                                comment: "schema rel comment",
                            ),
                        ],
                    ),
                ],
            ),
            new ImplConfig(
                models: [
                    new ImplModel(
                        type: "user",
                        class: "App\Entity\User",
                        attributes: [new ImplAttribute("id", getter: "getId")],
                        relations: [
                            new ImplRelation("profile", getter: "getProfile"),
                        ],
                    ),
                ],
            ),
        );

        $model = $config->models[0];
        $this->assertSame(
            "schema attr comment",
            $model->attributes[0]->comment,
        );
        $this->assertSame("schema rel comment", $model->relations[0]->comment);
    }
}
