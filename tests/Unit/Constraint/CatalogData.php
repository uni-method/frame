<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Constraint;

final class CatalogData
{
    /**
     * @return array{rules: array<string, array<string, mixed>>}
     */
    public static function full(): array
    {
        return [
            'rules' => [
                'notNull' => [
                    'scope' => 'value',
                    'kinds' => '*',
                    'relations' => true,
                    'requiresNullable' => true,
                    'params' => [],
                ],
                'notBlank' => ['scope' => 'value', 'kinds' => ['string'], 'relations' => false, 'params' => []],
                'length' => [
                    'scope' => 'value',
                    'kinds' => ['string'],
                    'relations' => false,
                    'params' => ['min' => 'int?', 'max' => 'int?'],
                ],
                'regex' => [
                    'scope' => 'value',
                    'kinds' => ['string'],
                    'relations' => false,
                    'params' => ['pattern' => 'string'],
                ],
                'email' => ['scope' => 'value', 'kinds' => ['string'], 'relations' => false, 'params' => []],
                'url' => ['scope' => 'value', 'kinds' => ['string'], 'relations' => false, 'params' => []],
                'range' => [
                    'scope' => 'value',
                    'kinds' => ['int', 'float', 'datetime'],
                    'relations' => false,
                    'params' => ['min' => 'scalar?', 'max' => 'scalar?'],
                ],
                'choice' => [
                    'scope' => 'value',
                    'kinds' => ['string', 'int', 'uuid'],
                    'relations' => false,
                    'params' => ['values' => 'scalar[]'],
                ],
                'equals' => [
                    'scope' => 'value',
                    'kinds' => ['string', 'int', 'bool', 'uuid'],
                    'relations' => false,
                    'params' => ['value' => 'scalar'],
                ],
                'count' => [
                    'scope' => 'collection',
                    'kinds' => '*',
                    'relations' => true,
                    'params' => ['min' => 'int?', 'max' => 'int?'],
                ],
                'uniqueItems' => [
                    'scope' => 'collection',
                    'kinds' => ['string', 'int', 'uuid'],
                    'relations' => false,
                    'params' => [],
                ],
            ],
        ];
    }
}
