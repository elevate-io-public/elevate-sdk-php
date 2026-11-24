<?php

declare(strict_types=1);

/*
 * Writes src/Api/Operations.php, src/Api/Operations and src/Api/Model from openapi/openapi-spec.yaml.
 */

use Elevate\Generator\Generator;
use Symfony\Component\Yaml\Yaml;

require __DIR__.'/../vendor/autoload.php';

$root = dirname(__DIR__);
$specification = Yaml::parseFile($root.'/openapi/openapi-spec.yaml');
if (!is_array($specification)) {
    fwrite(\STDERR, "The specification is not a YAML mapping.\n");
    exit(1);
}

$files = (new Generator(array_filter($specification, is_string(...), \ARRAY_FILTER_USE_KEY), 'Elevate\Api', 'Elevate\Api'))->generate();
$target = $root.'/src/Api';
// Removes the previous output first: a schema that no operation reaches any more must not leave its class behind.
array_map(unlink(...), [...(glob($target.'/{Model,Operations}/*.php', \GLOB_BRACE) ?: []), ...(glob($target.'/Operations.php') ?: [])]);
foreach ($files as $path => $contents) {
    if (!is_dir(dirname($target.'/'.$path))) {
        mkdir(dirname($target.'/'.$path), 0o755, true);
    }
    file_put_contents($target.'/'.$path, $contents);
}
echo count($files)." files generated.\n";
