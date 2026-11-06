<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Property\RemoveUnusedPrivatePropertyRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([__DIR__ . "/src", __DIR__ . "/tests"])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
    )
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    ->withPhpVersion(PhpVersion::PHP_83)
    ->withSkip([
        RemoveUnusedPrivatePropertyRector::class => [
            __DIR__ . "/tests/Unit/Mapping/AttributeAccessorTest.php",
        ],
    ]);
