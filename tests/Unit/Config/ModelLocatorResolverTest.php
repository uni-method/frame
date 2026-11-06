<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{Action, ActionType, Config, Model};

/**
 * Model locator for references and filters by relation: model → read action →
 * impl.defaultLocator.
 *
 * Локатор модели для ссылок и фильтров по связи: модель → read-действие →
 * impl.defaultLocator.
 */
final class ModelLocatorResolverTest extends TestCase
{
    public function testModelLocatorWinsOverReadActionAndDefault(): void
    {
        $config = $this->config(modelLocator: "model", readLocator: "read", default: "default");

        self::assertSame("model", $config->locatorForModel("post"));
    }

    public function testReadActionLocatorWinsOverDefault(): void
    {
        $config = $this->config(readLocator: "read", default: "default");

        self::assertSame("read", $config->locatorForModel("post"));
    }

    public function testDefaultLocatorForModelWithoutOwn(): void
    {
        $config = $this->config(default: "default");

        self::assertSame("default", $config->locatorForModel("post"));
    }

    public function testNoLocator(): void
    {
        self::assertNull($this->config()->locatorForModel("post"));
    }

    /**
     * Update/delete locators are not used: the result does not depend on what
     * other actions the model has and in what order.
     *
     * Локаторы update/delete не используются: результат не зависит от того,
     * какие ещё действия есть у модели и в каком порядке.
     */
    public function testLocatorsOfOtherActionsAreIgnored(): void
    {
        $config = new Config(
            models: [new Model(class: "App\\Post", type: "post")],
            actions: [
                new Action(type: ActionType::Delete, model: "post", entityLocator: "lock"),
                new Action(type: ActionType::Update, model: "post", entityLocator: "lock"),
            ],
        );

        self::assertNull($config->locatorForModel("post"));
    }

    /**
     * One model's locator is not substituted for another.
     *
     * Локатор одной модели не подставляется для другой.
     */
    public function testLocatorOfAnotherModelIsIgnored(): void
    {
        $config = new Config(
            models: [
                new Model(class: "App\\Post", type: "post", entityLocator: "posts"),
                new Model(class: "App\\Writer", type: "writer"),
            ],
            actions: [new Action(type: ActionType::Read, model: "post", entityLocator: "posts")],
        );

        self::assertNull($config->locatorForModel("writer"));
    }

    private function config(
        ?string $modelLocator = null,
        ?string $readLocator = null,
        ?string $default = null,
    ): Config {
        return new Config(
            models: [new Model(class: "App\\Post", type: "post", entityLocator: $modelLocator)],
            actions: $readLocator === null
                ? []
                : [new Action(type: ActionType::Read, model: "post", entityLocator: $readLocator)],
            defaultLocator: $default,
        );
    }
}
