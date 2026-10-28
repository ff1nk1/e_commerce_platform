<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelSetList;
use Tools\Rector\AddDeclareStrictTypesRector;
use Tools\Rector\FinalizeClassesWithoutChildrenRector;

require_once __DIR__.'/tools/rector/AddDeclareStrictTypesRector.php';
require_once __DIR__.'/tools/rector/FinalizeClassesWithoutChildrenRector.php';

/*
 * Shared Rector config. Run from a service directory:
 *   ./vendor/bin/rector process --config ../rector.php
 *
 * Paths follow the working directory so one file covers every service.
 */
$serviceRoot = getcwd();

return RectorConfig::configure()
    ->withPaths([
        $serviceRoot.'/app',
        $serviceRoot.'/tests',
        $serviceRoot.'/database',
        $serviceRoot.'/routes',
    ])
    ->withSkip([
        $serviceRoot.'/vendor',
        $serviceRoot.'/storage',
        $serviceRoot.'/bootstrap/cache',
    ])
    ->withComposerBased(laravel: true)
    ->withSets([
        LaravelSetList::LARAVEL_ARRAYACCESS_TO_METHOD_CALL,
        LaravelSetList::LARAVEL_ARRAY_STR_FUNCTION_TO_STATIC_CALL,
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_CONTAINER_STRING_TO_FULLY_QUALIFIED_NAME,
        LaravelSetList::LARAVEL_ELOQUENT_MAGIC_METHOD_TO_QUERY_BUILDER,
        LaravelSetList::LARAVEL_FACADE_ALIASES_TO_FULL_NAMES,
        LaravelSetList::LARAVEL_FACTORIES,
        LaravelSetList::LARAVEL_IF_HELPERS,
        LaravelSetList::LARAVEL_TESTING,
        LaravelSetList::LARAVEL_TYPE_DECLARATIONS,
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
    )
    ->withRules([
        AddDeclareStrictTypesRector::class,
        FinalizeClassesWithoutChildrenRector::class,
    ]);
