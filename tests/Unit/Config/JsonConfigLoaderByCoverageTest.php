<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\JsonConfigLoader;
use UniMethod\Frame\Config\ListAction\{FilterOperator, SortDirection};
use UniMethod\Frame\Validator\Exception\{
    InvalidConfigStructureException,
    MissingRequiredFieldException,
};

final class JsonConfigLoaderByCoverageTest extends TestCase
{
    private JsonConfigLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new JsonConfigLoader();
    }

    // ── helpers ───────────────────────────────────────────────────────────────
    //
    // ── хелперы ───────────────────────────────────────────────────────────────

    private function wrap(string $schemaBody, string $implBody = "{}"): string
    {
        return <<<JSON
        { "schema": {$schemaBody}, "impl": {$implBody} }
        JSON;
    }

    private function userImpl(): string
    {
        return '{ "models": { "user": { "class": "App\\\\User", "attributes": { "id": { "getter": "getId" } } } } }';
    }

    // ── model structure errors ────────────────────────────────────────────────
    //
    // ── ошибки структуры модели ───────────────────────────────────────────────

    public function testModelsAsArrayThrowsException(): void
    {
        $json = $this->wrap('{ "models": [{ "attributes": {} }] }');

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "schema.models must be an object, not an array",
        );
        $this->loader->loadFromString($json);
    }

    public function testEmptyModelKeyThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "": { "attributes": { "id": { "type": "int" } } } } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage("Model key must be a non-empty string");
        $this->loader->loadFromString($json);
    }

    public function testModelValueNotObjectThrowsException(): void
    {
        $json = $this->wrap('{ "models": { "user": "invalid" } }');

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage("schema.models[user] must be an object");
        $this->loader->loadFromString($json);
    }

    public function testRequiredFieldEmptyStringThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } } }',
            '{ "models": { "user": { "class": "", "attributes": { "id": { "getter": "getId" } } } } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Field 'class' in impl.models[user] must be a non-empty string",
        );
        $this->loader->loadFromString($json);
    }

    public function testAttributesAsArrayThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": [{ "type": "int" }] } } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "schema.models[user].attributes must be an object, not an array",
        );
        $this->loader->loadFromString($json);
    }

    public function testEmptyAttributeKeyThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "": { "type": "int" } } } } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Attribute key in schema.models[user] must be a non-empty string",
        );
        $this->loader->loadFromString($json);
    }

    public function testAttributeNotObjectThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": "invalid" } } } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "schema.models[user].attributes[id] must be an object",
        );
        $this->loader->loadFromString($json);
    }

    public function testRelationsAsArrayThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } }, "relations": [{ "type": "one", ' .
            '"targetModel": "user" }] } } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "schema.models[user].relations must be an object, not an array",
        );
        $this->loader->loadFromString($json);
    }

    public function testEmptyRelationKeyThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } }, "relations": { "": { "type": "one", ' .
            '"targetModel": "user" } } } } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Relation key in schema.models[user] must be a non-empty string",
        );
        $this->loader->loadFromString($json);
    }

    // ── impl attribute / relation errors ──────────────────────────────────────
    //
    // ── ошибки impl-атрибутов / связей ────────────────────────────────────────

    public function testInvalidConstructorParamCastsToFalse(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } } }',
            '{ "models": { "user": { "class": "App\\\\User", "attributes": { "id": { "getter": "getId", ' .
            '"constructorParam": "invalid" } } } } }',
        );

        $config = $this->loader->loadFromString($json);
        $this->assertFalse($config->models[0]->attributes[0]->constructorParam);
    }

    public function testGetterScalarIntThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } } }',
            '{ "models": { "user": { "class": "App\\\\User", "attributes": { "id": { "getter": 123 } } } } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Invalid identifier '123' in impl.models[user].attributes[id].getter",
        );
        $this->loader->loadFromString($json);
    }

    public function testGetterScalarTrueThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } } }',
            '{ "models": { "user": { "class": "App\\\\User", "attributes": { "id": { "getter": true } } } } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Invalid identifier '1' in impl.models[user].attributes[id].getter",
        );
        $this->loader->loadFromString($json);
    }

    public function testGetterValidIdentifierPasses(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } } }',
            '{ "models": { "user": { "class": "App\\\\User", "attributes": { "id": { "getter": "getId" } } } } }',
        );

        $config = $this->loader->loadFromString($json);
        $this->assertSame("getId", $config->models[0]->attributes[0]->getter);
    }

    public function testAttributeSetterWithInvalidIdentifierThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } } }',
            '{ "models": { "user": { "class": "App\\\\User", "attributes": { "id": { "getter": "getId", "setter": ' .
            '"set name" } } } } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Invalid identifier 'set name' in impl.models[user].attributes[id].setter",
        );
        $this->loader->loadFromString($json);
    }

    public function testRelationGetterWithInvalidIdentifierThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } }, "relations": { "profile": { "type": ' .
            '"manyToOne", "targetModel": "user" } } } } }',
            '{ "models": { "user": { "class": "App\\\\User", "attributes": { "id": { "getter": "getId" } }, ' .
            '"relations": { "profile": { "getter": "get profile" } } } } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Invalid identifier 'get profile' in impl.models[user].relations[profile].getter",
        );
        $this->loader->loadFromString($json);
    }

    public function testRelationSetterWithInvalidIdentifierThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } }, "relations": { "profile": { "type": ' .
            '"manyToOne", "targetModel": "user" } } } } }',
            '{ "models": { "user": { "class": "App\\\\User", "attributes": { "id": { "getter": "getId" } }, ' .
            '"relations": { "profile": { "setter": "set-profile" } } } } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Invalid identifier 'set-profile' in impl.models[user].relations[profile].setter",
        );
        $this->loader->loadFromString($json);
    }

    public function testRelationAddWithInvalidIdentifierThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } }, "relations": { "tags": { "type": ' .
            '"oneToMany", "targetModel": "user" } } } } }',
            '{ "models": { "user": { "class": "App\\\\User", "attributes": { "id": { "getter": "getId" } }, ' .
            '"relations": { "tags": { "add": "add tag" } } } } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Invalid identifier 'add tag' in impl.models[user].relations[tags].add",
        );
        $this->loader->loadFromString($json);
    }

    public function testRelationGetterFalseIsAllowed(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } }, "relations": { "profile": { "type": ' .
            '"manyToOne", "targetModel": "user" } } } } }',
            '{ "models": { "user": { "class": "App\\\\User", "attributes": { "id": { "getter": "getId" } }, ' .
            '"relations": { "profile": { "getter": false } } } } }',
        );

        $config = $this->loader->loadFromString($json);
        $this->assertFalse($config->models[0]->relations[0]->getter);
    }

    // ── schema action: include ────────────────────────────────────────────────
    //
    // ── schema-действие: include ──────────────────────────────────────────────

    public function testIncludeNullReturnsNull(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } }, "actions": { "user": { "read": { ' .
            '"include": null } } } }',
            $this->userImpl(),
        );

        $config = $this->loader->loadFromString($json);
        $this->assertNull($config->actions[0]->include);
    }

    public function testIncludeNotArrayThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } }, "actions": { "user": { "read": { ' .
            '"include": "profile" } } } }',
            $this->userImpl(),
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Field 'schema.actions[user][read].include' must be an array or null",
        );
        $this->loader->loadFromString($json);
    }

    public function testIncludeAssociativeArrayThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } }, "actions": { "user": { "read": { ' .
            '"include": { "profile": true } } } } }',
            $this->userImpl(),
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Field 'schema.actions[user][read].include' must be a list",
        );
        $this->loader->loadFromString($json);
    }

    public function testIncludeItemNotStringThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } }, "actions": { "user": { "read": { ' .
            '"include": [123] } } } }',
            $this->userImpl(),
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Item at index 0 in 'schema.actions[user][read].include' must be a string",
        );
        $this->loader->loadFromString($json);
    }

    public function testIncludeEmptyStringThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } }, "actions": { "user": { "read": { ' .
            '"include": ["   "] } } } }',
            $this->userImpl(),
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Item at index 0 in 'schema.actions[user][read].include' must be a non-empty string",
        );
        $this->loader->loadFromString($json);
    }

    public function testIncludeValidListReturnsArray(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } }, "actions": { "user": { "read": { ' .
            '"include": ["profile", "roles"] } } } }',
            $this->userImpl(),
        );

        $config = $this->loader->loadFromString($json);
        $this->assertSame(["profile", "roles"], $config->actions[0]->include);
    }

    // ── schema action: access ─────────────────────────────────────────────────
    //
    // ── schema-действие: access ───────────────────────────────────────────────

    public function testAccessNullReturnsNull(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } }, "actions": { "user": { "read": { ' .
            '"access": null } } } }',
            $this->userImpl(),
        );

        $config = $this->loader->loadFromString($json);
        $this->assertNull($config->actions[0]->access);
    }

    public function testAccessNotObjectThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } }, "actions": { "user": { "read": { ' .
            '"access": true } } } }',
            $this->userImpl(),
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Field 'schema.actions[user][read].access' must be an object or null",
        );
        $this->loader->loadFromString($json);
    }

    public function testAccessRolesNotListThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } }, "actions": { "user": { "read": { ' .
            '"access": { "roles": "admin" } } } } }',
            $this->userImpl(),
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Field 'schema.actions[user][read].access.roles' must be a list of strings",
        );
        $this->loader->loadFromString($json);
    }

    public function testAccessRolesAssociativeArrayThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } }, "actions": { "user": { "read": { ' .
            '"access": { "roles": { "admin": true } } } } } }',
            $this->userImpl(),
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Field 'schema.actions[user][read].access.roles' must be a list of strings",
        );
        $this->loader->loadFromString($json);
    }

    public function testAccessRoleNotStringThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } }, "actions": { "user": { "read": { ' .
            '"access": { "roles": [123] } } } } }',
            $this->userImpl(),
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Invalid role at index 0 in 'schema.actions[user][read].access.roles'",
        );
        $this->loader->loadFromString($json);
    }

    public function testAccessRoleEmptyStringThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } }, "actions": { "user": { "read": { ' .
            '"access": { "roles": ["   "] } } } } }',
            $this->userImpl(),
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Invalid role at index 0 in 'schema.actions[user][read].access.roles'",
        );
        $this->loader->loadFromString($json);
    }

    public function testTrivialAccessConfigReturnsNull(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } }, "actions": { "user": { "read": { ' .
            '"access": { "authRequired": false } } } } }',
            $this->userImpl(),
        );

        $config = $this->loader->loadFromString($json);
        $this->assertNull($config->actions[0]->access);
    }

    // ── schema action: sort ───────────────────────────────────────────────────
    //
    // ── schema-действие: sort ─────────────────────────────────────────────────

    public function testDefaultSortValueWithoutPathThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } }, "actions": { "user": { "list": { ' .
            '"config": { "sort": [ { "defaultValue": { "direction": "asc" } } ] } } } } }',
            $this->userImpl(),
        );

        $this->expectException(MissingRequiredFieldException::class);
        $this->expectExceptionMessage(
            "Missing required field 'path' in schema.actions[user][list].config.sort[0]",
        );
        $this->loader->loadFromString($json);
    }

    public function testDefaultSortValueWithEmptyPathThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } }, "actions": { "user": { "list": { ' .
            '"config": { "sort": [ { "path": "createdAt", "defaultValue": { "path": "", "direction": "asc" } } ] } } ' .
            '} } }',
            $this->userImpl(),
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Field 'path' in schema.actions[user][list].config.sort[0].defaultValue must be a non-empty string",
        );
        $this->loader->loadFromString($json);
    }

    public function testDefaultSortValueWithoutDirectionThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } }, "actions": { "user": { "list": { ' .
            '"config": { "sort": [ { "defaultValue": { "path": "createdAt" } } ] } } } } }',
            $this->userImpl(),
        );

        $this->expectException(MissingRequiredFieldException::class);
        $this->expectExceptionMessage(
            "Missing required field 'path' in schema.actions[user][list].config.sort[0]",
        );
        $this->loader->loadFromString($json);
    }

    public function testDefaultSortValueWithInvalidDirectionThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } }, "actions": { "user": { "list": { ' .
            '"config": { "sort": [ { "path": "createdAt", "defaultValue": { "path": "createdAt", "direction": ' .
            '"sideways" } } ] } } } } }',
            $this->userImpl(),
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Invalid sort direction 'sideways' in schema.actions[user][list].config.sort[0].defaultValue.direction",
        );
        $this->loader->loadFromString($json);
    }

    public function testValidDefaultSortValueIsParsed(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" }, "createdAt": { "type": "datetime" } } ' .
            '} }, "actions": { "user": { "list": { "config": { "sort": [ { "path": "createdAt", "defaultValue": { ' .
            '"path": "createdAt", "direction": "desc" } } ] } } } } }',
            '{ "models": { "user": { "class": "App\\\\User", "attributes": { "id": { "getter": "getId" }, ' .
            '"createdAt": { "getter": "getCreatedAt" } } } } }',
        );

        $config = $this->loader->loadFromString($json);
        $sortRule = $config->actions[0]->config->sort[0];

        $this->assertNotNull($sortRule->defaultValue);
        $this->assertSame("createdAt", $sortRule->defaultValue->path);
        $this->assertSame(
            SortDirection::Desc,
            $sortRule->defaultValue->direction,
        );
    }

    // ── locators ──────────────────────────────────────────────────────────────
    //
    // ── локаторы ──────────────────────────────────────────────────────────────

    public function testEmptyEntityLocatorThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } }, "actions": { "user": { "read": ' .
            '{} } } }',
            '{ "models": { "user": { "class": "App\\\\User", "attributes": { "id": { "getter": "getId" } } } }, ' .
            '"actions": { "user": { "read": { "entityLocator": "   " } } } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Invalid 'entityLocator' for action 'read' on model 'user': must be a non-empty string",
        );
        $this->loader->loadFromString($json);
    }

    public function testModelReferencesUndefinedLocatorThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } }, "actions": { "user": { "read": ' .
            '{} } } }',
            '{ "models": { "user": { "class": "App\\\\User", "attributes": { "id": { "getter": "getId" } } } }, ' .
            '"actions": { "user": { "read": { "entityLocator": "doctrine" } } }, "locators": { "other": ' .
            '"UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntityLocator" } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Action 'read' on model 'user' references undefined locator 'doctrine'",
        );
        $this->loader->loadFromString($json);
    }

    public function testRelationWithUnknownTargetModelThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } }, "relations": { "profile": { "type": ' .
            '"oneToOne", "targetModel": "profile" } } } } }',
            '{ "models": { "user": { "class": "App\\\\User", "attributes": { "id": { "getter": "getId" } }, ' .
            '"relations": { "profile": { "getter": "getProfile" } } } } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Relation 'profile' in model 'user' refers to unknown target model 'profile'",
        );
        $this->loader->loadFromString($json);
    }

    public function testUnusedLocatorThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } } }',
            '{ "models": { "user": { "class": "App\\\\User", "attributes": { "id": { "getter": "getId" } } } }, ' .
            '"locators": { "doctrine": "UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntityLocator" ' .
            '} }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Locator 'doctrine' is declared but not used by any action",
        );
        $this->loader->loadFromString($json);
    }

    public function testValidLocatorsConfigurationPasses(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } }, "relations": { "friend": { "type": ' .
            '"oneToOne", "targetModel": "user" } } } }, "actions": { "user": { "read": {} } } }',
            '{ "models": { "user": { "class": "App\\\\User", "attributes": { "id": { "getter": "getId" } }, ' .
            '"relations": { "friend": { "getter": "getFriend" } } } }, "actions": { "user": { "read": { ' .
            '"entityLocator": "doctrine" } } }, "locators": { "doctrine": ' .
            '"UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntityLocator" } }',
        );

        $config = $this->loader->loadFromString($json);
        $this->assertCount(1, $config->models);
        $this->assertSame("doctrine", $config->actions[0]->entityLocator);
    }

    // ── schema action: filter operators ──────────────────────────────────────
    //
    // ── schema-действие: операторы фильтров ─────────────────────────────────

    public function testFilterOperatorsNotListThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } }, "actions": { "user": { "list": { ' .
            '"config": { "filters": [ { "path": "active", "operators": { "eq": true } } ] } } } } }',
            $this->userImpl(),
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Field 'schema.actions[user][list].config.filters[0].operators' must be a list",
        );
        $this->loader->loadFromString($json);
    }

    public function testFilterOperatorItemNotStringThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } }, "actions": { "user": { "list": { ' .
            '"config": { "filters": [ { "path": "active", "operators": [123] } ] } } } } }',
            $this->userImpl(),
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Item at index 0 in 'schema.actions[user][list].config.filters[0].operators' must be a string",
        );
        $this->loader->loadFromString($json);
    }

    public function testInvalidFilterOperatorThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } }, "actions": { "user": { "list": { ' .
            '"config": { "filters": [ { "path": "active", "operators": ["sideways"] } ] } } } } }',
            $this->userImpl(),
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Invalid filter operator 'sideways' in schema.actions[user][list].config.filters[0].operators[0]",
        );
        $this->loader->loadFromString($json);
    }

    public function testValidFilterOperatorsAreParsed(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } }, "actions": { "user": { "list": { ' .
            '"config": { "filters": [ { "path": "active", "operators": ["equal"] } ] } } } } }',
            $this->userImpl(),
        );

        $config = $this->loader->loadFromString($json);
        $this->assertSame(
            [FilterOperator::Equal],
            $config->actions[0]->config->filters[0]->operators,
        );
    }

    public function testFilterDefaultValueWithoutOperatorThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } }, "actions": { "user": { "list": { ' .
            '"config": { "filters": [ { "path": "active", "operators": ["equal"], "defaultValue": { "value": true } ' .
            '} ] } } } } }',
            $this->userImpl(),
        );

        $this->expectException(MissingRequiredFieldException::class);
        $this->expectExceptionMessage(
            "Missing required field 'operator' in schema.actions[user][list].config.filters[0].defaultValue",
        );
        $this->loader->loadFromString($json);
    }

    public function testFilterDefaultValueWithInvalidOperatorThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } }, "actions": { "user": { "list": { ' .
            '"config": { "filters": [ { "path": "active", "operators": ["equal"], "defaultValue": { "operator": ' .
            '"sideways", "value": true } } ] } } } } }',
            $this->userImpl(),
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Invalid filter operator 'sideways' in schema.actions[user][list].config.filters[0].defaultValue.operator",
        );
        $this->loader->loadFromString($json);
    }

    public function testFilterDefaultValueWithoutValueThrowsException(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } }, "actions": { "user": { "list": { ' .
            '"config": { "filters": [ { "path": "active", "operators": ["equal"], "defaultValue": { "operator": ' .
            '"equal" } } ] } } } } }',
            $this->userImpl(),
        );

        $this->expectException(MissingRequiredFieldException::class);
        $this->expectExceptionMessage(
            "Missing required field 'value' in schema.actions[user][list].config.filters[0].defaultValue",
        );
        $this->loader->loadFromString($json);
    }

    public function testValidFilterDefaultValueIsParsed(): void
    {
        $json = $this->wrap(
            '{ "models": { "user": { "attributes": { "id": { "type": "int" } } } }, "actions": { "user": { "list": { ' .
            '"config": { "filters": [ { "path": "active", "operators": ["equal"], "defaultValue": { "operator": ' .
            '"equal", "value": true } } ] } } } } }',
            $this->userImpl(),
        );

        $config = $this->loader->loadFromString($json);
        $filter = $config->actions[0]->config->filters[0];

        $this->assertNotNull($filter->defaultValue);
        $this->assertSame(
            FilterOperator::Equal,
            $filter->defaultValue->operator,
        );
        $this->assertTrue($filter->defaultValue->value);
    }
}
