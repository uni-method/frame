<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
use stdClass;
use UniMethod\Frame\Config\{
    Action,
    ActionType,
    Attribute,
    Config,
    Model,
    Relation,
};
use UniMethod\Frame\Integration\{ApiService, ConstraintCheckerInterface};
use UniMethod\Frame\Json\{ConvertToJson, ConvertToStdClass};
use UniMethod\Frame\Mapping\{
    MappingFromObject,
    MappingToListAction,
    MappingToObject,
};
use UniMethod\Frame\Mutator\MutatorRegistry;
use UniMethod\Frame\Persistence\{ListLocators, ListRemovers, ListSavers};
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Tests\Unit\Stub\{Post, RecordingLocator, Writer};
use UniMethod\Frame\Validator\ActionValidator;

/**
 * Common handling of request parameters in ApiService::execute.
 *
 * Общая обработка параметров запроса в ApiService::execute.
 */
final class ApiServiceTest extends TestCase
{
    /**
     * `?included[]=author` (an array from parse_str) is the same list of paths as
     * `?included=author`.
     *
     * `?included[]=author` (массив из parse_str) — тот же список путей, что
     * `?included=author`.
     */
    public function testIncludedPassedAsArrayIsListOfPaths(): void
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
                    relations: [
                        new Relation(name: "author", type: Relation::MANY_TO_ONE, targetModel: "writer"),
                    ],
                ),
                new Model(
                    class: Writer::class,
                    type: "writer",
                    attributes: [new Attribute(name: "id", type: "string|null")],
                ),
            ],
            actions: [
                new Action(type: ActionType::Read, model: "post", entityLocator: "loc"),
            ],
            locators: ["loc" => RecordingLocator::class],
        );

        $locator = new RecordingLocator(new Post(id: "1", title: "T"));

        $constraintChecker = $this->createStub(ConstraintCheckerInterface::class);
        $constraintChecker->method("check")->willReturn([]);

        $mappingFromObject = $this->createStub(MappingFromObject::class);
        $mappingFromObject->method("map")->willReturn(new stdClass());

        $service = new ApiService(
            constraintChecker: $constraintChecker,
            config: $config,
            treeBuilder: new TreeBuilder(),
            convertToStdClass: new ConvertToStdClass(),
            convertToJson: new ConvertToJson(),
            mappingFromObject: $mappingFromObject,
            mappingToObject: $this->createStub(MappingToObject::class),
            mappingToListAction: $this->createStub(MappingToListAction::class),
            actionValidator: $this->createStub(ActionValidator::class),
            locators: new ListLocators(["loc" => $locator]),
            savers: new ListSavers([]),
            removers: new ListRemovers([]),
            manipulators: $this->createStub(MutatorRegistry::class),
        );

        $result = $service->execute(
            modelType: "post",
            actionType: ActionType::Read,
            queryParams: ["included" => ["author"]],
            id: "1",
        );

        $this->assertSame(200, $result->statusCode);
        $this->assertSame(["author"], $locator->lastInclude);
    }
}
