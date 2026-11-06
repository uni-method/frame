<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Relation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{Model, Relation, RelationWriteMode};
use UniMethod\Frame\Relation\TreeBuilder;

final class TreeBuilderSlopCodeCoverTest extends TestCase
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
            "nonexistent" => [["profile" => []], "profile.settings", null],
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
                        "tags" => ["__mode" => RelationWriteMode::Reference],
                    ],
                ],
                [
                    "posts" => [
                        "__mode" => RelationWriteMode::Create,
                        "tags" => ["__mode" => RelationWriteMode::Reference],
                    ],
                ],
            ],
            "missing in config" => [
                ["settings" => []],
                ["profile" => ["__mode" => RelationWriteMode::Create]],
                [],
            ],
        ];
    }
}
