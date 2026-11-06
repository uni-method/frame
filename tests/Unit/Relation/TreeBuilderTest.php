<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Relation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{Attribute, Model, Relation, RelationWriteMode};
use UniMethod\Frame\Mapping\Exception\ModelNotFoundException;
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Validator\Exception\{
    UnknownRelationException,
    UnwritableRelationException,
};

final class TreeBuilderTest extends TestCase
{
    private TreeBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new TreeBuilder();
    }

    // ────────────────────────────────────────────────
    // parseIncludedString
    // ────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider("parseIncludedStringProvider")]
    public function testParseIncludedString(
        ?string $input,
        array $expected,
    ): void {
        self::assertSame(
            $expected,
            $this->builder->parseIncludedString($input),
        );
    }

    /**
     * @return array<string, array{0: string|null, 1: array<string, mixed>}>
     */
    public static function parseIncludedStringProvider(): array
    {
        return [
            "null" => [null, []],
            "empty" => ["", []],
            "whitespace" => ["   ", []],
            "single" => ["server", ["server" => []]],
            "multiple" => [
                "server,databases,applications",
                ["server" => [], "databases" => [], "applications" => []],
            ],
            "nested" => [
                "server,databases.backends,applications",
                [
                    "server" => [],
                    "databases" => ["backends" => []],
                    "applications" => [],
                ],
            ],
            "deep" => [
                "a.b.c,a.b.d,x.y",
                [
                    "a" => ["b" => ["c" => [], "d" => []]],
                    "x" => ["y" => []],
                ],
            ],
            "whitespace in paths" => [
                " server , databases.backends , applications ",
                [
                    "server" => [],
                    "databases" => ["backends" => []],
                    "applications" => [],
                ],
            ],
        ];
    }

    // ────────────────────────────────────────────────
    // buildSimpleTree
    // ────────────────────────────────────────────────

    /**
     * @param list<string> $paths
     * @param array<string, mixed> $expected
     */
    #[DataProvider("buildSimpleTreeProvider")]
    public function testBuildSimpleTree(array $paths, array $expected): void
    {
        self::assertSame($expected, $this->builder->buildSimpleTree($paths));
    }

    /**
     * @return array<string, array{0: list<string>, 1: array<string, mixed>}>
     */
    public static function buildSimpleTreeProvider(): array
    {
        return [
            "empty" => [[], []],
            "single" => [["profile"], ["profile" => []]],
            "multiple" => [
                ["profile", "posts", "comments"],
                ["profile" => [], "posts" => [], "comments" => []],
            ],
            "nested" => [
                ["profile", "posts.tags"],
                ["profile" => [], "posts" => ["tags" => []]],
            ],
            "complex" => [
                ["a.b.c", "a.b.d", "x.y"],
                [
                    "a" => ["b" => ["c" => [], "d" => []]],
                    "x" => ["y" => []],
                ],
            ],
        ];
    }

    // ────────────────────────────────────────────────
    // buildConfigTree
    // ────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $writable
     * @param array<string, mixed> $expected
     */
    #[DataProvider("buildConfigTreeProvider")]
    public function testBuildConfigTree(array $writable, array $expected): void
    {
        self::assertEquals(
            $expected,
            $this->builder->buildConfigTree($writable),
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function buildConfigTreeProvider(): array
    {
        return [
            "single path" => [
                ["profile" => RelationWriteMode::Create],
                ["profile" => ["__mode" => RelationWriteMode::Create]],
            ],
            "nested paths" => [
                [
                    "profile" => RelationWriteMode::Create,
                    "posts.tags" => RelationWriteMode::Reference,
                ],
                [
                    "profile" => ["__mode" => RelationWriteMode::Create],
                    "posts" => [
                        "tags" => [
                            "__mode" => RelationWriteMode::Reference,
                        ],
                    ],
                ],
            ],
            "multiple levels" => [
                [
                    "a.b.c" => RelationWriteMode::Reference,
                    "a.b" => RelationWriteMode::Create,
                    "x" => RelationWriteMode::Reference,
                ],
                [
                    "a" => [
                        "b" => [
                            "__mode" => RelationWriteMode::Create,
                            "c" => [
                                "__mode" => RelationWriteMode::Reference,
                            ],
                        ],
                    ],
                    "x" => [
                        "__mode" => RelationWriteMode::Reference,
                    ],
                ],
            ],
        ];
    }

    // ────────────────────────────────────────────────
    // findRelation
    // ────────────────────────────────────────────────

    /**
     * @param list<Relation> $relations
     */
    #[DataProvider("findRelationProvider")]
    public function testFindRelation(
        array $relations,
        string $name,
        ?Relation $expected,
    ): void {
        $model = new Model(class: "User", type: "user", relations: $relations);
        $result = $this->builder->findRelation($model, $name);
        self::assertSame($expected, $result);
    }

    /**
     * @return array<string, array{0: list<Relation>, 1: string, 2: Relation|null}>
     */
    public static function findRelationProvider(): array
    {
        $r1 = new Relation("profile", "oneToOne", "Profile");
        $r2 = new Relation("posts", "oneToMany", "Post");
        return [
            "found" => [[$r1, $r2], "posts", $r2],
            "not found" => [[$r1], "nonexistent", null],
            "empty list" => [[], "profile", null],
        ];
    }

    // ────────────────────────────────────────────────
    // getModeForPath
    // ────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $tree
     */
    #[DataProvider("getModeForPathProvider")]
    public function testGetModeForPath(
        array $tree,
        string $path,
        ?RelationWriteMode $expected,
    ): void {
        self::assertEquals(
            $expected,
            $this->builder->getModeForPath($tree, $path),
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: RelationWriteMode|null}>
     */
    public static function getModeForPathProvider(): array
    {
        return [
            "simple" => [
                ["profile" => ["__mode" => RelationWriteMode::Create]],
                "profile",
                RelationWriteMode::Create,
            ],
            "nested" => [
                [
                    "posts" => [
                        "__mode" => RelationWriteMode::Create,
                        "tags" => [
                            "__mode" => RelationWriteMode::Reference,
                        ],
                    ],
                ],
                "posts.tags",
                RelationWriteMode::Reference,
            ],
            "nonexistent" => [
                ["profile" => ["__mode" => RelationWriteMode::Create]],
                "nonexistent",
                null,
            ],
            "no mode" => [["profile" => []], "profile", null],
        ];
    }

    // ────────────────────────────────────────────────
    // isAttribute
    // ────────────────────────────────────────────────

    /**
     * @param list<Attribute> $attrs
     */
    #[DataProvider("isAttributeProvider")]
    public function testIsAttribute(
        array $attrs,
        string $name,
        bool $expected,
    ): void {
        $model = new Model(class: "User", type: "user", attributes: $attrs);
        self::assertSame($expected, $this->builder->isAttribute($model, $name));
    }

    /**
     * @return array<string, array{0: list<Attribute>, 1: string, 2: bool}>
     */
    public static function isAttributeProvider(): array
    {
        return [
            "existing" => [
                [new Attribute("id", "int"), new Attribute("name", "string")],
                "name",
                true,
            ],
            "nonexistent" => [
                [new Attribute("id", "int")],
                "nonexistent",
                false,
            ],
            "empty" => [[], "name", false],
        ];
    }

    // ────────────────────────────────────────────────
    // mergeTreesWithModes
    // ────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $requestedTree
     * @param array<string, mixed> $configTree
     * @param array<string, mixed> $expected
     */
    #[DataProvider("mergeTreesWithModesProvider")]
    public function testMergeTreesWithModes(
        array $requestedTree,
        array $configTree,
        array $expected,
    ): void {
        self::assertEquals(
            $expected,
            $this->builder->mergeTreesWithModes($requestedTree, $configTree),
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}>
     */
    public static function mergeTreesWithModesProvider(): array
    {
        return [
            "simple" => [
                ["profile" => []],
                ["profile" => ["__mode" => RelationWriteMode::Create]],
                ["profile" => ["__mode" => RelationWriteMode::Create]],
            ],
            "nested" => [
                ["posts" => ["tags" => []]],
                [
                    "posts" => [
                        "__mode" => RelationWriteMode::Create,
                        "tags" => [
                            "__mode" => RelationWriteMode::Reference,
                        ],
                    ],
                ],
                [
                    "posts" => [
                        "__mode" => RelationWriteMode::Create,
                        "tags" => [
                            "__mode" => RelationWriteMode::Reference,
                        ],
                    ],
                ],
            ],
            "skip missing" => [
                ["profile" => [], "missing" => []],
                ["profile" => ["__mode" => RelationWriteMode::Create]],
                ["profile" => ["__mode" => RelationWriteMode::Create]],
            ],
            "empty subtree" => [
                ["profile" => []],
                [
                    "profile" => [
                        "__mode" => RelationWriteMode::Create,
                        "nested" => [
                            "__mode" => RelationWriteMode::Reference,
                        ],
                    ],
                ],
                ["profile" => ["__mode" => RelationWriteMode::Create]],
            ],
        ];
    }

    // ────────────────────────────────────────────────
    // simpleSplitAndUnique
    // ────────────────────────────────────────────────

    /**
     * @param list<string> $expected
     */
    #[DataProvider("simpleSplitAndUniqueProvider")]
    public function testSimpleSplitAndUnique(
        ?string $input,
        array $expected,
    ): void {
        self::assertSame(
            $expected,
            $this->builder->simpleSplitAndUnique($input),
        );
    }

    /**
     * @return array<string, array{0: string|null, 1: list<string>}>
     */
    public static function simpleSplitAndUniqueProvider(): array
    {
        return [
            "null input" => [null, []],
            "empty string" => ["", []],
            "whitespace only" => ["   ", []],
            "single element" => ["server", ["server"]],
            "multiple elements" => [
                "server,db,cache",
                ["server", "db", "cache"],
            ],
            "duplicates" => ["a,b,a,c,b", ["a", "b", "c"]],
            "extra spaces" => [" a ,  b  , c ", ["a", "b", "c"]],
            "with empty entries" => ["a,,b, ,c", ["a", "b", "c"]],
        ];
    }

    // ────────────────────────────────────────────────
    // mergeArraysToUnique
    // ────────────────────────────────────────────────

    /**
     * @param list<string> $array1
     * @param list<string> $array2
     * @param list<string> $expected
     */
    #[DataProvider("mergeArraysToUniqueProvider")]
    public function testMergeArraysToUnique(
        array $array1,
        array $array2,
        array $expected,
    ): void {
        self::assertSame(
            $expected,
            $this->builder->mergeArraysToUnique($array1, $array2),
        );
    }

    /**
     * @return array<string, array{0: list<string>, 1: list<string>, 2: list<string>}>
     */
    public static function mergeArraysToUniqueProvider(): array
    {
        return [
            "both empty" => [[], [], []],
            "first empty" => [[], ["a", "b"], ["a", "b"]],
            "second empty" => [["x", "y"], [], ["x", "y"]],
            "no duplicates" => [["a"], ["b"], ["a", "b"]],
            "with duplicates" => [["a", "b"], ["b", "c"], ["a", "b", "c"]],
            "preserve order of first array" => [
                ["x", "y"],
                ["y", "z"],
                ["x", "y", "z"],
            ],
            "identical arrays" => [["a", "b"], ["a", "b"], ["a", "b"]],
        ];
    }

    // ────────────────────────────────────────────────
    // tests with exceptions / full logic
    // ────────────────────────────────────────────────
    //
    // ────────────────────────────────────────────────
    // тесты с исключениями / полная логика
    // ────────────────────────────────────────────────

    public function testValidateTreeSubsetPassesForValidTree(): void
    {
        $this->expectNotToPerformAssertions();

        $profileRelation = new Relation("profile", "oneToOne", "profile");
        $userModel = new Model(
            class: "User",
            type: "user",
            relations: [$profileRelation],
        );
        $profileModel = new Model(
            class: "Profile",
            type: "profile",
            relations: [],
        );
        $this->builder->validateTreeSubset(
            ["profile" => []],
            ["profile" => ["__mode" => RelationWriteMode::Create]],
            $userModel,
            ["profile" => $profileModel],
        );
    }

    public function testValidateTreeSubsetThrowsForUnknownRelation(): void
    {
        $model = new Model(class: "User", type: "user", relations: []);
        $this->expectException(UnknownRelationException::class);
        $this->builder->validateTreeSubset(
            ["nonexistent" => []],
            ["nonexistent" => []],
            $model,
            [],
        );
    }

    public function testValidateTreeSubsetThrowsForUnwritableRelation(): void
    {
        $relation = new Relation("profile", "oneToOne", "Profile");
        $model = new Model(class: "User", type: "user", relations: [$relation]);
        $this->expectException(UnwritableRelationException::class);
        $this->builder->validateTreeSubset(["profile" => []], [], $model, []);
    }

    public function testValidateTreeSubsetThrowsForMissingTargetModel(): void
    {
        $relation = new Relation("profile", "oneToOne", "profile");
        $model = new Model(class: "User", type: "user", relations: [$relation]);
        $this->expectException(ModelNotFoundException::class);
        $this->builder->validateTreeSubset(
            ["profile" => ["nested" => []]],
            ["profile" => ["nested" => []]],
            $model,
            [],
        );
    }

    public function testValidateTreeSubsetValidatesNestedRelations(): void
    {
        $this->expectNotToPerformAssertions();

        $posts = new Relation("posts", "oneToMany", "post");
        $tags = new Relation("tags", "manyToMany", "tag");
        $user = new Model(class: "User", type: "user", relations: [$posts]);
        $post = new Model(class: "Post", type: "post", relations: [$tags]);
        $tag = new Model(class: "Tag", type: "tag", relations: []);

        $this->builder->validateTreeSubset(
            ["posts" => ["tags" => []]],
            [
                "posts" => [
                    "__mode" => RelationWriteMode::Create,
                    "tags" => [
                        "__mode" => RelationWriteMode::Reference,
                    ],
                ],
            ],
            $user,
            ["post" => $post, "tag" => $tag],
        );
    }

    public function testValidateTreeSubsetSkipsModesKey(): void
    {
        $this->expectNotToPerformAssertions();

        $model = new Model(class: "User", type: "user", relations: []);
        $this->builder->validateTreeSubset(
            ["__mode" => RelationWriteMode::Create],
            [],
            $model,
            [],
        );
    }

    public function testValidatePathExists(): void
    {
        $this->expectNotToPerformAssertions();

        $relation = new Relation("profile", "oneToOne", "profile");
        $model = new Model(class: "User", type: "user", relations: [$relation]);
        $this->builder->validatePathExists($model, "profile", []);
    }

    public function testValidatePathExistsNested(): void
    {
        $this->expectNotToPerformAssertions();

        $posts = new Relation("posts", "oneToMany", "post");
        $tags = new Relation("tags", "manyToMany", "tag");
        $user = new Model(class: "User", type: "user", relations: [$posts]);
        $post = new Model(class: "Post", type: "post", relations: [$tags]);
        $this->builder->validatePathExists($user, "posts.tags", [
            "post" => $post,
        ]);
    }

    public function testValidatePathExistsThrowsForInvalidRelation(): void
    {
        $model = new Model(class: "User", type: "user", relations: []);
        $this->expectException(UnknownRelationException::class);
        $this->builder->validatePathExists($model, "nonexistent", []);
    }

    public function testValidatePathExistsThrowsForMissingModel(): void
    {
        $relation = new Relation("posts", "oneToMany", "post");
        $model = new Model(class: "User", type: "user", relations: [$relation]);
        $this->expectException(ModelNotFoundException::class);
        $this->builder->validatePathExists($model, "posts.tags", []);
    }
}
