<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Validator;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{
    Action,
    ActionType,
    Attribute,
    Model,
    Mutators,
    Relation,
};
use UniMethod\Frame\Config\Common\Impl\{
    ImplAction,
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
    SchemaWriteMode,
};
use UniMethod\Frame\Config\ListAction\{
    FilterOperator,
    FilterRule,
    ListActionConfig,
    SortRule,
};
use UniMethod\Frame\Tests\Unit\Config\Stub\{
    TestEntityLocator,
    TestEntityRemover,
    TestEntitySaver,
};
use UniMethod\Frame\Validator\ConfigValidator;
use UniMethod\Frame\Validator\Exception\InvalidConfigStructureException;

final class ConfigValidatorTest extends TestCase
{
    private ConfigValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ConfigValidator();
    }

    // -------------------------------------------------------------------------
    // validateModelIds
    // -------------------------------------------------------------------------

    public function testValidateModelIdsPassesWhenIdAttributeHasGetter(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator->validateModelIds([
            new Model(
                class: "App\\User",
                type: "user",
                attributes: [
                    new Attribute(name: "id", type: "string", getter: "getId"),
                ],
            ),
        ]);
    }

    public function testValidateModelIdsFailsWhenIdAttributeMissing(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Model 'user' must define an attribute 'id' with a getter",
        );

        $this->validator->validateModelIds([
            new Model(
                class: "App\\User",
                type: "user",
                attributes: [
                    new Attribute(
                        name: "name",
                        type: "string",
                        getter: "getName",
                    ),
                ],
            ),
        ]);
    }

    public function testValidateModelIdsPassesWhenIdGetterIsNull(): void
    {
        // null — getter auto-detection
        //
        // null — автоопределение getter-а
        $this->expectNotToPerformAssertions();

        $this->validator->validateModelIds([
            new Model(
                class: "App\\User",
                type: "user",
                attributes: [
                    new Attribute(name: "id", type: "string"),
                ],
            ),
        ]);
    }

    public function testValidateModelIdsFailsWhenIdGetterIsFalse(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Model 'user' must define an attribute 'id' with a getter",
        );

        $this->validator->validateModelIds([
            new Model(
                class: "App\\User",
                type: "user",
                attributes: [
                    new Attribute(name: "id", type: "string", getter: false),
                ],
            ),
        ]);
    }

    public function testValidateModelIdsFailsWhenIdGetterIsEmptyString(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Model 'user' must define an attribute 'id' with a getter",
        );

        $this->validator->validateModelIds([
            new Model(
                class: "App\\User",
                type: "user",
                attributes: [
                    new Attribute(name: "id", type: "string", getter: "   "),
                ],
            ),
        ]);
    }

    // -------------------------------------------------------------------------
    // validateBoolFilters
    // -------------------------------------------------------------------------

    public function testValidateBoolFiltersPassesForNonListAction(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator->validateBoolFilters(
            [],
            [new Action(type: ActionType::Read, model: "user")],
        );
    }

    public function testValidateBoolFiltersPassesForListActionWithNoConfig(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator->validateBoolFilters(
            [],
            [new Action(type: ActionType::List, model: "user")],
        );
    }

    public function testValidateBoolFiltersPassesForNonBoolAttribute(): void
    {
        $this->expectNotToPerformAssertions();

        $model = new Model(
            class: "App\\User",
            type: "user",
            attributes: [
                new Attribute(name: "id", type: "string", getter: "getId"),
                new Attribute(
                    name: "active",
                    type: "string",
                    getter: "getActive",
                ),
            ],
        );

        $config = $this->makeListConfig(
            filters: [$this->makeFilterRule("active", ["equal", "in"])],
        );

        $this->validator->validateBoolFilters(
            [$model],
            [
                new Action(
                    type: ActionType::List,
                    model: "user",
                    config: $config,
                ),
            ],
        );
    }

    public function testValidateBoolFiltersPassesWhenBoolAttributeUsesEqualOnly(): void
    {
        $this->expectNotToPerformAssertions();

        $model = new Model(
            class: "App\\User",
            type: "user",
            attributes: [
                new Attribute(name: "id", type: "string", getter: "getId"),
                new Attribute(name: "active", type: "bool", getter: "isActive"),
            ],
        );

        $config = $this->makeListConfig(
            filters: [$this->makeFilterRule("active", ["equal"])],
        );

        $this->validator->validateBoolFilters(
            [$model],
            [
                new Action(
                    type: ActionType::List,
                    model: "user",
                    config: $config,
                ),
            ],
        );
    }

    public function testValidateBoolFiltersFailsWhenBoolAttributeUsesNonEqualOperator(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Boolean filter 'active' in model 'user' supports only 'Equal' operator",
        );

        $model = new Model(
            class: "App\\User",
            type: "user",
            attributes: [
                new Attribute(name: "id", type: "string", getter: "getId"),
                new Attribute(name: "active", type: "bool", getter: "isActive"),
            ],
        );

        $config = $this->makeListConfig(
            filters: [$this->makeFilterRule("active", ["in"])],
        );

        $this->validator->validateBoolFilters(
            [$model],
            [
                new Action(
                    type: ActionType::List,
                    model: "user",
                    config: $config,
                ),
            ],
        );
    }

    public function testValidateBoolFiltersFailsForBooleanType(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Boolean filter 'active' in model 'user' supports only 'Equal' operator",
        );

        $model = new Model(
            class: "App\\User",
            type: "user",
            attributes: [
                new Attribute(name: "id", type: "string", getter: "getId"),
                new Attribute(
                    name: "active",
                    type: "boolean",
                    getter: "isActive",
                ),
            ],
        );

        $config = $this->makeListConfig(
            filters: [$this->makeFilterRule("active", ["in"])],
        );

        $this->validator->validateBoolFilters(
            [$model],
            [
                new Action(
                    type: ActionType::List,
                    model: "user",
                    config: $config,
                ),
            ],
        );
    }

    public function testValidateBoolFiltersSkipsUnknownAttribute(): void
    {
        $this->expectNotToPerformAssertions();

        $model = new Model(
            class: "App\\User",
            type: "user",
            attributes: [
                new Attribute(name: "id", type: "string", getter: "getId"),
            ],
        );

        $config = $this->makeListConfig(
            filters: [$this->makeFilterRule("nonexistent", ["in"])],
        );

        $this->validator->validateBoolFilters(
            [$model],
            [
                new Action(
                    type: ActionType::List,
                    model: "user",
                    config: $config,
                ),
            ],
        );
    }

    // -------------------------------------------------------------------------
    // validateLocators
    // -------------------------------------------------------------------------

    public function testValidateLocatorsPassesWhenAllLocatorsUsed(): void
    {
        $this->expectNotToPerformAssertions();

        $model = $this->makeModelWithId("user");
        /** @var array<string, class-string> $locators */
        $locators = ["userLocator" => TestEntityLocator::class,];

        $this->validator->validateLocators(
            [$model],
            [
                new Action(
                    type: ActionType::Read,
                    model: "user",
                    entityLocator: "userLocator",
                ),
            ],
            $locators,
        );
    }

    public function testValidateLocatorsPassesWithNoLocators(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator->validateLocators([], [], []);
    }

    public function testValidateLocatorsFailsWhenLocatorNotDeclared(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "references undefined locator 'missingLocator'",
        );

        $this->validator->validateLocators(
            [],
            [
                new Action(
                    type: ActionType::Read,
                    model: "user",
                    entityLocator: "missingLocator",
                ),
            ],
            [],
        );
    }

    public function testValidateLocatorsFailsWhenLocatorUnused(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Locator 'unusedLocator' is declared but not used by any action",
        );

        /** @var array<string, class-string> $locators */
        $locators = ["unusedLocator" => self::class,];

        $this->validator->validateLocators([], [], $locators);
    }

    public function testValidateLocatorsFailsWhenRelationTargetModelUnknown(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "refers to unknown target model 'nonexistent'",
        );

        $model = new Model(
            class: "App\\User",
            type: "user",
            attributes: [
                new Attribute(name: "id", type: "string", getter: "getId"),
            ],
            relations: [
                new Relation(
                    name: "profile",
                    type: "oneToOne",
                    targetModel: "nonexistent",
                ),
            ],
        );

        $this->validator->validateLocators([$model], [], []);
    }

    public function testValidateLocatorsFailsWhenEntityLocatorIsEmptyString(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage("must be a non-empty string");

        $this->validator->validateLocators(
            [],
            [
                new Action(
                    type: ActionType::Read,
                    model: "user",
                    entityLocator: "   ",
                ),
            ],
            [],
        );
    }

    // -------------------------------------------------------------------------
    // validateSavers
    // -------------------------------------------------------------------------

    public function testValidateSaversPassesWhenAllSaversUsed(): void
    {
        $this->expectNotToPerformAssertions();

        /** @var array<string, class-string> $savers */
        $savers = ["userSaver" => TestEntitySaver::class,];

        $this->validator->validateSavers(
            [
                new Action(
                    type: ActionType::Create,
                    model: "user",
                    entitySaver: "userSaver",
                ),
            ],
            $savers,
        );
    }

    public function testValidateSaversPassesWithNoSavers(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator->validateSavers([], []);
    }

    public function testValidateSaversFailsWhenSaverNotDeclared(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "references undefined saver 'missingSaver'",
        );

        $this->validator->validateSavers(
            [
                new Action(
                    type: ActionType::Create,
                    model: "user",
                    entitySaver: "missingSaver",
                ),
            ],
            [],
        );
    }

    public function testValidateSaversFailsWhenSaverUnused(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Saver 'unusedSaver' is declared but not used by any action",
        );

        /** @var array<string, class-string> $savers */
        $savers = ["unusedSaver" => self::class,];

        $this->validator->validateSavers([], $savers);
    }

    public function testValidateSaversFailsWhenEntitySaverIsEmptyString(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage("must be a non-empty string");

        $this->validator->validateSavers(
            [
                new Action(
                    type: ActionType::Create,
                    model: "user",
                    entitySaver: "   ",
                ),
            ],
            [],
        );
    }

    // -------------------------------------------------------------------------
    // validateRemovers
    // -------------------------------------------------------------------------

    public function testValidateRemoversPassesWhenAllRemoversUsed(): void
    {
        $this->expectNotToPerformAssertions();

        /** @var array<string, class-string> $removers */
        $removers = ["userRemover" => TestEntityRemover::class,];

        $this->validator->validateRemovers(
            [
                new Action(
                    type: ActionType::Delete,
                    model: "user",
                    entityRemover: "userRemover",
                ),
            ],
            $removers,
        );
    }

    public function testValidateRemoversPassesWithNoRemovers(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator->validateRemovers([], []);
    }

    public function testValidateRemoversFailsWhenRemoverNotDeclared(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "references undefined remover 'missingRemover'",
        );

        $this->validator->validateRemovers(
            [
                new Action(
                    type: ActionType::Delete,
                    model: "user",
                    entityRemover: "missingRemover",
                ),
            ],
            [],
        );
    }

    public function testValidateRemoversFailsWhenRemoverUnused(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Remover 'unusedRemover' is declared but not used by any action",
        );

        /** @var array<string, class-string> $removers */
        $removers = ["unusedRemover" => self::class,];

        $this->validator->validateRemovers([], $removers);
    }

    public function testValidateRemoversFailsWhenEntityRemoverIsEmptyString(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage("must be a non-empty string");

        $this->validator->validateRemovers(
            [
                new Action(
                    type: ActionType::Delete,
                    model: "user",
                    entityRemover: "   ",
                ),
            ],
            [],
        );
    }

    // -------------------------------------------------------------------------
    // validateMutators
    // -------------------------------------------------------------------------

    public function testValidateMutatorsPassesWithValidSetup(): void
    {
        $this->expectNotToPerformAssertions();

        $mutatorClass = $this->makeMutatorClass();

        $model = new Model(
            class: "App\\User",
            type: "user",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "string",
                    getter: "getId",
                    setter: "setId",
                    mutators: new Mutators(afterInput: ["myMutator"]),
                ),
            ],
        );

        $this->validator->validateMutators(
            [$model],
            ["myMutator" => $mutatorClass],
        );
    }

    public function testValidateMutatorsPassesWhenNoMutatorsUsed(): void
    {
        $this->expectNotToPerformAssertions();

        $model = $this->makeModelWithId("user");

        $this->validator->validateMutators([$model], []);
    }

    public function testValidateMutatorsFailsWhenClassDoesNotExist(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage("refers to undefined class");

        $mutators = ["myMutator" => "App\\NonExistentMutator"];
        $this->validator->validateMutators([], $mutators);
    }

    public function testValidateMutatorsFailsWhenClassDoesNotImplementInterface(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "must implement AttributeMutatorInterface",
        );

        $this->validator->validateMutators(
            [],
            ["myMutator" => \stdClass::class],
        );
    }

    public function testValidateMutatorsFailsWhenAttributeHasNoSetter(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "uses mutators but has no valid setter or mutatorSetter",
        );

        $mutatorClass = $this->makeMutatorClass();

        $model = new Model(
            class: "App\\User",
            type: "user",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "string",
                    getter: "getId",
                    mutators: new Mutators(afterInput: ["myMutator"]),
                ),
            ],
        );

        $this->validator->validateMutators(
            [$model],
            ["myMutator" => $mutatorClass],
        );
    }

    public function testValidateMutatorsFailsWhenAttributeSetterIsFalse(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "uses mutators but has no valid setter or mutatorSetter",
        );

        $mutatorClass = $this->makeMutatorClass();

        $model = new Model(
            class: "App\\User",
            type: "user",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "string",
                    getter: "getId",
                    setter: false,
                    mutators: new Mutators(afterInput: ["myMutator"]),
                ),
            ],
        );

        $this->validator->validateMutators(
            [$model],
            ["myMutator" => $mutatorClass],
        );
    }

    public function testValidateMutatorsPassesWhenSetterFalseButMutatorSetterProvided(): void
    {
        $this->expectNotToPerformAssertions();

        $mutatorClass = $this->makeMutatorClass();

        $model = new Model(
            class: "App\\User",
            type: "user",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "string",
                    getter: "getId",
                    setter: false,
                    mutatorSetter: "setIdForMutator",
                    mutators: new Mutators(beforeOutput: ["myMutator"]),
                ),
            ],
        );

        $this->validator->validateMutators(
            [$model],
            ["myMutator" => $mutatorClass],
        );
    }

    public function testValidateMutatorsPassesWhenSetterNullButMutatorSetterProvided(): void
    {
        $this->expectNotToPerformAssertions();

        $mutatorClass = $this->makeMutatorClass();

        $model = new Model(
            class: "App\\User",
            type: "user",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "string",
                    getter: "getId",
                    mutatorSetter: "setIdForMutator",
                    mutators: new Mutators(beforeOutput: ["myMutator"]),
                ),
            ],
        );

        $this->validator->validateMutators(
            [$model],
            ["myMutator" => $mutatorClass],
        );
    }

    public function testValidateMutatorsFailsWhenMutatorSetterPresentButNoMutators(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "declares mutatorSetter but has no mutators",
        );

        $model = new Model(
            class: "App\\User",
            type: "user",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "string",
                    getter: "getId",
                    setter: false,
                    mutatorSetter: "setIdForMutator",
                ),
            ],
        );

        $this->validator->validateMutators([$model], []);
    }

    public function testValidateMutatorsFailsWhenAttributeIsConstructorParam(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "uses mutators but is defined as constructorParam",
        );

        $mutatorClass = $this->makeMutatorClass();

        $model = new Model(
            class: "App\\User",
            type: "user",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "string",
                    getter: "getId",
                    setter: "setId",
                    constructorParam: true,
                    mutators: new Mutators(afterInput: ["myMutator"]),
                ),
            ],
        );

        $this->validator->validateMutators(
            [$model],
            ["myMutator" => $mutatorClass],
        );
    }

    public function testValidateMutatorsFailsWhenAliasNotDeclared(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "references undefined mutator alias 'undeclaredMutator'",
        );

        $mutatorClass = $this->makeMutatorClass();

        $model = new Model(
            class: "App\\User",
            type: "user",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "string",
                    getter: "getId",
                    setter: "setId",
                    mutators: new Mutators(
                        afterValidate: ["undeclaredMutator"],
                    ),
                ),
            ],
        );

        $this->validator->validateMutators(
            [$model],
            ["myMutator" => $mutatorClass],
        );
    }

    public function testValidateMutatorsChecksBeforeOutputPhase(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "references undefined mutator alias 'undeclaredMutator'",
        );

        $mutatorClass = $this->makeMutatorClass();

        $model = new Model(
            class: "App\\User",
            type: "user",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "string",
                    getter: "getId",
                    setter: "setId",
                    mutators: new Mutators(beforeOutput: ["undeclaredMutator"]),
                ),
            ],
        );

        $this->validator->validateMutators(
            [$model],
            ["myMutator" => $mutatorClass],
        );
    }

    public function testValidateMutatorsSkipsAttributesWithEmptyMutators(): void
    {
        $this->expectNotToPerformAssertions();

        $model = new Model(
            class: "App\\User",
            type: "user",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "string",
                    getter: "getId",
                    mutators: new Mutators(),
                ),
            ],
        );

        $this->validator->validateMutators([$model], []);
    }

    // -------------------------------------------------------------------------
    // validateRelationMutators
    // -------------------------------------------------------------------------

    public function testValidateRelationMutatorsPassesWithValidSetup(): void
    {
        $this->expectNotToPerformAssertions();

        $mutatorClass = $this->makeRelationMutatorClass();

        $model = new Model(
            class: "App\\User",
            type: "user",
            attributes: [
                new Attribute(name: "id", type: "string", getter: "getId"),
            ],
            relations: [
                new Relation(
                    name: "profile",
                    type: "oneToOne",
                    targetModel: "profile",
                    setter: "setProfile",
                    mutators: new Mutators(afterInput: ["myRelMutator"]),
                ),
            ],
        );

        $this->validator->validateRelationMutators(
            [$model],
            ["myRelMutator" => $mutatorClass],
        );
    }

    public function testValidateRelationMutatorsPassesWithNoMutators(): void
    {
        $this->expectNotToPerformAssertions();

        $model = $this->makeModelWithId("user");

        $this->validator->validateRelationMutators([$model], []);
    }

    public function testValidateRelationMutatorsFailsWhenClassDoesNotExist(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage("refers to undefined class");

        /** @var array<string, class-string|string> $relationMutators */
        $relationMutators = ["myMutator" => "App\\NonExistent"];

        $this->validator->validateRelationMutators([], $relationMutators);
    }

    public function testValidateRelationMutatorsFailsWhenClassDoesNotImplementInterface(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "must implement RelationMutatorInterface",
        );

        $this->validator->validateRelationMutators(
            [],
            ["myMutator" => \stdClass::class],
        );
    }

    public function testValidateRelationMutatorsFailsWhenSetterIsFalse(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "uses mutators but has setter explicitly disabled and no mutatorSetter",
        );

        $mutatorClass = $this->makeRelationMutatorClass();

        $model = new Model(
            class: "App\\User",
            type: "user",
            attributes: [
                new Attribute(name: "id", type: "string", getter: "getId"),
            ],
            relations: [
                new Relation(
                    name: "profile",
                    type: "oneToOne",
                    targetModel: "profile",
                    setter: false,
                    mutators: new Mutators(afterInput: ["myRelMutator"]),
                ),
            ],
        );

        $this->validator->validateRelationMutators(
            [$model],
            ["myRelMutator" => $mutatorClass],
        );
    }

    public function testValidateRelationMutatorsPassesWhenSetterFalseButMutatorSetterProvided(): void
    {
        $this->expectNotToPerformAssertions();

        $mutatorClass = $this->makeRelationMutatorClass();

        $model = new Model(
            class: "App\\User",
            type: "user",
            attributes: [
                new Attribute(name: "id", type: "string", getter: "getId"),
            ],
            relations: [
                new Relation(
                    name: "profile",
                    type: "oneToOne",
                    targetModel: "profile",
                    setter: false,
                    mutatorSetter: "setProfileForMutator",
                    mutators: new Mutators(beforeOutput: ["myRelMutator"]),
                ),
            ],
        );

        $this->validator->validateRelationMutators(
            [$model],
            ["myRelMutator" => $mutatorClass],
        );
    }

    public function testValidateRelationMutatorsFailsWhenMutatorSetterPresentButNoMutators(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "declares mutatorSetter but has no mutators",
        );

        $model = new Model(
            class: "App\\User",
            type: "user",
            attributes: [
                new Attribute(name: "id", type: "string", getter: "getId"),
            ],
            relations: [
                new Relation(
                    name: "profile",
                    type: "oneToOne",
                    targetModel: "profile",
                    setter: false,
                    mutatorSetter: "setProfileForMutator",
                ),
            ],
        );

        $this->validator->validateRelationMutators([$model], []);
    }

    public function testValidateRelationMutatorsFailsWhenConstructorParam(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "uses mutators but is defined as constructorParam",
        );

        $mutatorClass = $this->makeRelationMutatorClass();

        $model = new Model(
            class: "App\\User",
            type: "user",
            attributes: [
                new Attribute(name: "id", type: "string", getter: "getId"),
            ],
            relations: [
                new Relation(
                    name: "profile",
                    type: "oneToOne",
                    targetModel: "profile",
                    setter: "setProfile",
                    constructorParam: true,
                    mutators: new Mutators(afterInput: ["myRelMutator"]),
                ),
            ],
        );

        $this->validator->validateRelationMutators(
            [$model],
            ["myRelMutator" => $mutatorClass],
        );
    }

    public function testValidateRelationMutatorsFailsWhenAliasNotDeclared(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "references undefined relation mutator alias 'undeclared'",
        );

        $mutatorClass = $this->makeRelationMutatorClass();

        $model = new Model(
            class: "App\\User",
            type: "user",
            attributes: [
                new Attribute(name: "id", type: "string", getter: "getId"),
            ],
            relations: [
                new Relation(
                    name: "profile",
                    type: "oneToOne",
                    targetModel: "profile",
                    setter: "setProfile",
                    mutators: new Mutators(afterValidate: ["undeclared"]),
                ),
            ],
        );

        $this->validator->validateRelationMutators(
            [$model],
            ["myRelMutator" => $mutatorClass],
        );
    }

    public function testValidateRelationMutatorsChecksBeforeOutputPhase(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "references undefined relation mutator alias 'undeclared'",
        );

        $mutatorClass = $this->makeRelationMutatorClass();

        $model = new Model(
            class: "App\\User",
            type: "user",
            attributes: [
                new Attribute(name: "id", type: "string", getter: "getId"),
            ],
            relations: [
                new Relation(
                    name: "profile",
                    type: "oneToOne",
                    targetModel: "profile",
                    setter: "setProfile",
                    mutators: new Mutators(beforeOutput: ["undeclared"]),
                ),
            ],
        );

        $this->validator->validateRelationMutators(
            [$model],
            ["myRelMutator" => $mutatorClass],
        );
    }

    public function testValidateRelationMutatorsSkipsRelationsWithEmptyMutators(): void
    {
        $this->expectNotToPerformAssertions();

        $model = new Model(
            class: "App\\User",
            type: "user",
            attributes: [
                new Attribute(name: "id", type: "string", getter: "getId"),
            ],
            relations: [
                new Relation(
                    name: "profile",
                    type: "oneToOne",
                    targetModel: "profile",
                    mutators: new Mutators(),
                ),
            ],
        );

        $this->validator->validateRelationMutators([$model], []);
    }

    // -------------------------------------------------------------------------
    // validateUserModel
    // -------------------------------------------------------------------------

    public function testValidateUserModelPassesWhenClassImplementsInterface(): void
    {
        $this->expectNotToPerformAssertions();

        $userClass = $this->makeUserClass();

        $model = new Model(class: $userClass, type: "user");

        $this->validator->validateUserModel([$model], "user");
    }

    public function testValidateUserModelFailsWhenModelNotFound(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "UserModel 'admin' does not exist in the models list",
        );

        $this->validator->validateUserModel([], "admin");
    }

    public function testValidateUserModelFailsWhenClassDoesNotImplementInterface(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage("must implement UserInterface");

        $model = new Model(class: \stdClass::class, type: "user");

        $this->validator->validateUserModel([$model], "user");
    }

    // -------------------------------------------------------------------------
    // validateRepository
    // -------------------------------------------------------------------------

    public function testValidateRepositoryPassesWhenClassImplementsInterface(): void
    {
        $this->expectNotToPerformAssertions();

        $repoClass = $this->makeRepositoryClass();

        $this->validator->validateRepository($repoClass, "test.path");
    }

    public function testValidateRepositoryFailsWhenClassDoesNotExist(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage("does not exist");

        $this->validator->validateRepository(
            "App\\NonExistentRepository",
            "test.path",
        );
    }

    public function testValidateRepositoryFailsWhenClassDoesNotImplementInterface(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "must implement EntityCollectionInterface",
        );

        $this->validator->validateRepository(\stdClass::class, "test.path");
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------
    //
    // -------------------------------------------------------------------------
    // Хелперы
    // -------------------------------------------------------------------------

    private function makeModelWithId(string $type): Model
    {
        return new Model(
            class: "App\\" . ucfirst($type),
            type: $type,
            attributes: [
                new Attribute(name: "id", type: "string", getter: "getId"),
            ],
        );
    }

    /**
     * @param list<FilterRule> $filters
     * @param list<SortRule> $sort
     */
    private function makeListConfig(
        array $filters = [],
        array $sort = [],
    ): ListActionConfig {
        return new ListActionConfig(sort: $sort, filters: $filters);
    }

    /**
     * @param list<string> $operators
     */
    private function makeFilterRule(string $path, array $operators): FilterRule
    {
        $operatorObjects = array_map(
            fn (string $op) => FilterOperator::from($op),
            $operators,
        );

        return new FilterRule(path: $path, operators: $operatorObjects);
    }

    private function makeMutatorClass(): string
    {
        $class = "TestAttributeMutator_" . uniqid();

        eval(
            sprintf(
                '
            class %s implements \UniMethod\Frame\Mutator\AttributeMutatorInterface {
                public function apply(
                    \UniMethod\Frame\Config\PhaseType $phase,
                    object $entity,
                    \UniMethod\Frame\Config\Model $model,
                    string $attribute,
                    mixed $value,
                    \UniMethod\Frame\Config\ActionType $actionType
                ): mixed { return $value; }
            }
        ',
                $class,
            )
        );

        return $class;
    }

    private function makeRelationMutatorClass(): string
    {
        $class = "TestRelationMutator_" . uniqid();

        eval(
            sprintf(
                '
            class %s implements \UniMethod\Frame\Mutator\RelationMutatorInterface {
                public function apply(
                    \UniMethod\Frame\Config\PhaseType $phase,
                    object $entity,
                    \UniMethod\Frame\Config\Model $model,
                    string $relation,
                    mixed $value,
                    \UniMethod\Frame\Config\ActionType $actionType
                ): mixed { return $value; }
            }
        ',
                $class,
            )
        );

        return $class;
    }

    private function makeUserClass(): string
    {
        $class = "TestUserModel_" . uniqid();

        eval(
            sprintf(
                '
            class %s implements \UniMethod\Frame\Integration\UserInterface {
                public function getUserIdentifier(): string|int { return 1; }
                public function getRoles(): array { return []; }
                public function hasRole(string $role): bool { return false; }
            }
        ',
                $class,
            )
        );

        return $class;
    }

    private function makeRepositoryClass(): string
    {
        $class = "TestRepository_" . uniqid();

        eval(
            sprintf(
                '
            class %s implements \UniMethod\Frame\Persistence\EntityCollectionInterface {
                public function list(
                    ?object $filter = null,
                    ?object $sort = null,
                    ?object $pagination = null,
                    array $included = [],
                ): array { return []; }
                public function countForList(?object $filter = null): int { return 0; }
            }
        ',
                $class,
            )
        );

        return $class;
    }

    // -------------------------------------------------------------------------
    // validateNoOrphanedImpl
    // -------------------------------------------------------------------------

    public function testValidateNoOrphanedImplPassesWhenAllImplMatchSchema(): void
    {
        $this->expectNotToPerformAssertions();

        $schema = new SchemaConfig(
            models: [
                new SchemaModel(
                    type: "user",
                    attributes: [
                        new SchemaAttribute(name: "id", type: "string"),
                    ],
                    relations: [
                        new SchemaRelation(
                            name: "profile",
                            type: "oneToOne",
                            targetModel: "profile",
                        ),
                    ],
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
                    relations: [
                        new ImplRelation(name: "profile", getter: "getProfile"),
                    ],
                ),
            ],
            actions: [new ImplAction(type: ActionType::Read, model: "user")],
        );

        $this->validator->validateNoOrphanedImpl($schema, $impl);
    }

    public function testValidateNoOrphanedImplPassesWithEmptyImpl(): void
    {
        $this->expectNotToPerformAssertions();

        $schema = new SchemaConfig(
            models: [new SchemaModel(type: "user")],
            actions: [new SchemaAction(type: ActionType::Read, model: "user")],
        );

        $this->validator->validateNoOrphanedImpl($schema, new ImplConfig());
    }

    public function testValidateNoOrphanedImplFailsWhenImplModelNotInSchema(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "impl.models[ghost] has no corresponding model in schema",
        );

        $schema = new SchemaConfig(models: [new SchemaModel(type: "user")]);

        $impl = new ImplConfig(
            models: [new ImplModel(type: "ghost", class: "App\\Ghost")],
        );

        $this->validator->validateNoOrphanedImpl($schema, $impl);
    }

    public function testValidateNoOrphanedImplFailsWhenImplAttributeNotInSchema(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "impl.models[user].attributes[ghost] has no corresponding attribute in schema",
        );

        $schema = new SchemaConfig(
            models: [
                new SchemaModel(
                    type: "user",
                    attributes: [
                        new SchemaAttribute(name: "id", type: "string"),
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
                        new ImplAttribute(name: "ghost", getter: "getGhost"),
                    ],
                ),
            ],
        );

        $this->validator->validateNoOrphanedImpl($schema, $impl);
    }

    public function testValidateNoOrphanedImplFailsWhenImplRelationNotInSchema(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "impl.models[user].relations[ghost] has no corresponding relation in schema",
        );

        $schema = new SchemaConfig(models: [new SchemaModel(type: "user")]);

        $impl = new ImplConfig(
            models: [
                new ImplModel(
                    type: "user",
                    class: "App\\User",
                    relations: [
                        new ImplRelation(name: "ghost", getter: "getGhost"),
                    ],
                ),
            ],
        );

        $this->validator->validateNoOrphanedImpl($schema, $impl);
    }

    public function testValidateNoOrphanedImplFailsWhenImplActionNotInSchema(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "impl.actions[user][create] has no corresponding action in schema",
        );

        $schema = new SchemaConfig(
            models: [new SchemaModel(type: "user")],
            actions: [new SchemaAction(type: ActionType::Read, model: "user")],
        );

        $impl = new ImplConfig(
            actions: [new ImplAction(type: ActionType::Create, model: "user")],
        );

        $this->validator->validateNoOrphanedImpl($schema, $impl);
    }

    // -------------------------------------------------------------------------
    // validateNoOrphanedMutators
    // -------------------------------------------------------------------------

    public function testValidateNoOrphanedMutatorsPassesWhenAllUsed(): void
    {
        $this->expectNotToPerformAssertions();

        $model = new Model(
            class: "App\\User",
            type: "user",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "string",
                    getter: "getId",
                    setter: "setId",
                    mutators: new Mutators(afterInput: ["myMutator"]),
                ),
            ],
        );

        /** @var array<string, class-string> $mutators */
        $mutators = ["myMutator" => self::class,];

        $this->validator->validateNoOrphanedMutators([$model], $mutators);
    }

    public function testValidateNoOrphanedMutatorsPassesWithEmptyDictionary(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator->validateNoOrphanedMutators([], []);
    }

    public function testValidateNoOrphanedMutatorsFailsWhenDeclaredButUnused(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Mutator 'unusedMutator' is declared but not used by any attribute in any model",
        );

        $model = $this->makeModelWithId("user");

        /** @var array<string, class-string> $mutators */
        $mutators = ["unusedMutator" => self::class,];

        $this->validator->validateNoOrphanedMutators([$model], $mutators);
    }

    public function testValidateNoOrphanedMutatorsChecksAllPhases(): void
    {
        $this->expectNotToPerformAssertions();

        $model = new Model(
            class: "App\\User",
            type: "user",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "string",
                    getter: "getId",
                    setter: "setId",
                    mutators: new Mutators(
                        afterInput: ["a"],
                        afterValidate: ["b"],
                        beforeOutput: ["c"],
                    ),
                ),
            ],
        );

        /** @var array<string, class-string> $mutators */
        $mutators = ["a" => self::class, "b" => self::class, "c" => self::class,];

        $this->validator->validateNoOrphanedMutators([$model], $mutators);
    }

    // -------------------------------------------------------------------------
    // validateNoOrphanedRelationMutators
    // -------------------------------------------------------------------------

    public function testValidateNoOrphanedRelationMutatorsPassesWhenAllUsed(): void
    {
        $this->expectNotToPerformAssertions();

        $model = new Model(
            class: "App\\User",
            type: "user",
            attributes: [
                new Attribute(name: "id", type: "string", getter: "getId"),
            ],
            relations: [
                new Relation(
                    name: "profile",
                    type: "oneToOne",
                    targetModel: "profile",
                    setter: "setProfile",
                    mutators: new Mutators(afterInput: ["myRelMutator"]),
                ),
            ],
        );

        /** @var array<string, class-string> $relationMutators */
        $relationMutators = ["myRelMutator" => self::class,];

        $this->validator->validateNoOrphanedRelationMutators(
            [$model],
            $relationMutators,
        );
    }

    public function testValidateNoOrphanedRelationMutatorsPassesWithEmptyDictionary(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator->validateNoOrphanedRelationMutators([], []);
    }

    public function testValidateNoOrphanedRelationMutatorsFailsWhenDeclaredButUnused(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Relation mutator 'unusedRelMutator' is declared but not used by any relation in any model",
        );

        $model = $this->makeModelWithId("user");

        /** @var array<string, class-string> $relationMutators */
        $relationMutators = ["unusedRelMutator" => self::class,];

        $this->validator->validateNoOrphanedRelationMutators(
            [$model],
            $relationMutators,
        );
    }

    public function testValidateNoOrphanedRelationMutatorsChecksAllPhases(): void
    {
        $this->expectNotToPerformAssertions();

        $model = new Model(
            class: "App\\User",
            type: "user",
            attributes: [
                new Attribute(name: "id", type: "string", getter: "getId"),
            ],
            relations: [
                new Relation(
                    name: "profile",
                    type: "oneToOne",
                    targetModel: "profile",
                    setter: "setProfile",
                    mutators: new Mutators(
                        afterInput: ["a"],
                        afterValidate: ["b"],
                        beforeOutput: ["c"],
                    ),
                ),
            ],
        );

        /** @var array<string, class-string> $relationMutators */
        $relationMutators = [
            "a" => self::class,
            "b" => self::class,
            "c" => self::class,
        ];

        $this->validator->validateNoOrphanedRelationMutators(
            [$model],
            $relationMutators,
        );
    }

    // -------------------------------------------------------------------------
    // validateImplDictionaries
    // -------------------------------------------------------------------------

    public function testValidateImplDictionariesPassesWithValidAssocArrays(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator->validateImplDictionaries(
            new ImplConfig(
                locators: ["loc" => "App\\Loc"],
                savers: ["sav" => "App\\Sav"],
                removers: ["rem" => "App\\Rem"],
                mutators: ["mut" => "App\\Mut"],
                relationMutators: ["relMut" => "App\\RelMut"],
            ),
        );
    }

    public function testValidateImplDictionariesPassesWithEmptyArrays(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator->validateImplDictionaries(new ImplConfig());
    }

    public function testValidateImplDictionariesFailsWhenLocatorsIsList(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "impl.locators must be an associative array, not a list",
        );

        $this->validator->validateImplDictionaries(
            new ImplConfig(locators: ["App\\Loc"]),
        );
    }

    public function testValidateImplDictionariesFailsWhenSaversIsList(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "impl.savers must be an associative array, not a list",
        );

        $this->validator->validateImplDictionaries(
            new ImplConfig(savers: ["App\\Sav"]),
        );
    }

    public function testValidateImplDictionariesFailsWhenRemoversIsList(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "impl.removers must be an associative array, not a list",
        );

        $this->validator->validateImplDictionaries(
            new ImplConfig(removers: ["App\\Rem"]),
        );
    }

    public function testValidateImplDictionariesFailsWhenMutatorsIsList(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "impl.mutators must be an associative array, not a list",
        );

        $this->validator->validateImplDictionaries(
            new ImplConfig(mutators: ["App\\Mut"]),
        );
    }

    public function testValidateImplDictionariesFailsWhenRelationMutatorsIsList(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "impl.relationMutators must be an associative array, not a list",
        );

        $this->validator->validateImplDictionaries(
            new ImplConfig(relationMutators: ["App\\RelMut"]),
        );
    }

    public function testValidateImplDictionariesFailsWhenValueIsEmpty(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "impl.locators[loc] value must be a non-empty string class name",
        );

        $this->validator->validateImplDictionaries(
            new ImplConfig(locators: ["loc" => "   "]),
        );
    }

    // -------------------------------------------------------------------------
    // validateSchemaImplConsistency
    // -------------------------------------------------------------------------

    public function testValidateSchemaImplConsistencyPassesWhenNoWriteMode(): void
    {
        $this->expectNotToPerformAssertions();

        $schema = new SchemaConfig(
            models: [
                new SchemaModel(
                    type: "user",
                    attributes: [
                        new SchemaAttribute(
                            name: "id",
                            type: "string",
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
                        new ImplAttribute(
                            name: "id",
                            getter: "getId",
                            setter: "setId",
                            constructorParam: true,
                        ),
                    ],
                ),
            ],
        );

        $this->validator->validateSchemaImplConsistency($schema, $impl);
    }

    public function testValidateSchemaImplConsistencyPassesWhenImplAttributeMissing(): void
    {
        $this->expectNotToPerformAssertions();

        $schema = new SchemaConfig(
            models: [
                new SchemaModel(
                    type: "user",
                    attributes: [
                        new SchemaAttribute(
                            name: "id",
                            type: "string",
                            writeMode: SchemaWriteMode::ReadOnly,
                        ),
                    ],
                ),
            ],
        );

        $impl = new ImplConfig(
            models: [new ImplModel(type: "user", class: "App\\User")],
        );

        $this->validator->validateSchemaImplConsistency($schema, $impl);
    }

    public function testValidateSchemaImplConsistencyPassesReadOnlyWithNoSetterNoConstructorParam(): void
    {
        $this->expectNotToPerformAssertions();

        $schema = new SchemaConfig(
            models: [
                new SchemaModel(
                    type: "user",
                    attributes: [
                        new SchemaAttribute(
                            name: "id",
                            type: "string",
                            writeMode: SchemaWriteMode::ReadOnly,
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
                        new ImplAttribute(
                            name: "id",
                            getter: "getId",
                            setter: false,
                            constructorParam: false,
                        ),
                    ],
                ),
            ],
        );

        $this->validator->validateSchemaImplConsistency($schema, $impl);
    }

    public function testValidateSchemaImplConsistencyFailsReadOnlyWithSetter(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "declares a setter but schema writeMode is 'read-only'",
        );

        $schema = new SchemaConfig(
            models: [
                new SchemaModel(
                    type: "user",
                    attributes: [
                        new SchemaAttribute(
                            name: "id",
                            type: "string",
                            writeMode: SchemaWriteMode::ReadOnly,
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
                        new ImplAttribute(
                            name: "id",
                            getter: "getId",
                            setter: "setId",
                        ),
                    ],
                ),
            ],
        );

        $this->validator->validateSchemaImplConsistency($schema, $impl);
    }

    public function testValidateSchemaImplConsistencyFailsReadOnlyWithConstructorParam(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "declares constructorParam=true but schema writeMode is 'read-only'",
        );

        $schema = new SchemaConfig(
            models: [
                new SchemaModel(
                    type: "user",
                    attributes: [
                        new SchemaAttribute(
                            name: "id",
                            type: "string",
                            writeMode: SchemaWriteMode::ReadOnly,
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
                        new ImplAttribute(
                            name: "id",
                            getter: "getId",
                            constructorParam: true,
                        ),
                    ],
                ),
            ],
        );

        $this->validator->validateSchemaImplConsistency($schema, $impl);
    }

    public function testValidateSchemaImplConsistencyPassesCreateOnlyWithConstructorParamAndNoSetter(): void
    {
        $this->expectNotToPerformAssertions();

        $schema = new SchemaConfig(
            models: [
                new SchemaModel(
                    type: "user",
                    attributes: [
                        new SchemaAttribute(
                            name: "id",
                            type: "string",
                            writeMode: SchemaWriteMode::CreateOnly,
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
                        new ImplAttribute(
                            name: "id",
                            getter: "getId",
                            setter: false,
                            constructorParam: true,
                        ),
                    ],
                ),
            ],
        );

        $this->validator->validateSchemaImplConsistency($schema, $impl);
    }

    public function testValidateSchemaImplConsistencyFailsCreateOnlyWithSetter(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "declares a setter but schema writeMode is 'create-only'",
        );

        $schema = new SchemaConfig(
            models: [
                new SchemaModel(
                    type: "user",
                    attributes: [
                        new SchemaAttribute(
                            name: "id",
                            type: "string",
                            writeMode: SchemaWriteMode::CreateOnly,
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
                        new ImplAttribute(
                            name: "id",
                            getter: "getId",
                            setter: "setId",
                        ),
                    ],
                ),
            ],
        );

        $this->validator->validateSchemaImplConsistency($schema, $impl);
    }

    public function testValidateSchemaImplConsistencyPassesWriteWithSetter(): void
    {
        $this->expectNotToPerformAssertions();

        $schema = new SchemaConfig(
            models: [
                new SchemaModel(
                    type: "user",
                    attributes: [
                        new SchemaAttribute(
                            name: "id",
                            type: "string",
                            writeMode: SchemaWriteMode::Write,
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
                        new ImplAttribute(
                            name: "id",
                            getter: "getId",
                            setter: "setId",
                        ),
                    ],
                ),
            ],
        );

        $this->validator->validateSchemaImplConsistency($schema, $impl);
    }

    public function testValidateSchemaImplConsistencyFailsWriteWithConstructorParam(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "declares constructorParam=true but schema writeMode is 'write'",
        );

        $schema = new SchemaConfig(
            models: [
                new SchemaModel(
                    type: "user",
                    attributes: [
                        new SchemaAttribute(
                            name: "id",
                            type: "string",
                            writeMode: SchemaWriteMode::Write,
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
                        new ImplAttribute(
                            name: "id",
                            getter: "getId",
                            constructorParam: true,
                        ),
                    ],
                ),
            ],
        );

        $this->validator->validateSchemaImplConsistency($schema, $impl);
    }
}
