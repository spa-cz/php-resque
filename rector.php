<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

// NOTE: When editing this file, make sure to order line groups alphabetically (so git can diff it nicely)
// use statements, and each group inside withRules()

// PHP upgrades
use Rector\Php82\Rector\Encapsed\VariableInStringInterpolationFixerRector;
use Rector\Php84\Rector\Param\ExplicitNullableParamTypeRector;

// Code Quality / Style
use Rector\CodeQuality\Rector\Class_\CompleteDynamicPropertiesRector;
use Rector\CodingStyle\Rector\FuncCall\ConsistentImplodeRector;

// Migrate rules one at a time
$rules = [
    
    // PHP 8.2
    VariableInStringInterpolationFixerRector::class,

    // PHP 8.4
    ExplicitNullableParamTypeRector::class,

    // Code Quality / Style
    CompleteDynamicPropertiesRector::class,
    ConsistentImplodeRector::class,
];

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/lib/Resque',
    ])
    /* ->withFileExtensions([
        'php',
        'phtml',
    ]) */
    ->withPhpVersion(\Rector\ValueObject\PhpVersion::PHP_84)
    /* ->withPreparedSets(
        deadCode: true,
    ) */
    /* ->withPhpSets(
        php84: true,
    ) */
    ->withRules($rules);
