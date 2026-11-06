<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()->in([__DIR__ . "/src", __DIR__ . "/tests"]);

return (new Config())
    ->setRules([
        "@PSR12" => true,
        "group_import" => true,
        "single_import_per_statement" => false,
        "ordered_imports" => true,
        "no_unused_imports" => true,
    ])
    ->setFinder($finder);
