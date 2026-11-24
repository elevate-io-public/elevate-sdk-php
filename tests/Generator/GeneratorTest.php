<?php

declare(strict_types=1);

namespace Elevate\Tests\Generator;

use Elevate\Generator\Generator;
use Elevate\Generator\GeneratorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class GeneratorTest extends TestCase
{
    /**
     * Compares the output with tests/Generator/Expected, which the code style check, the static analysis and
     * TransportTest then cover. Run with UPDATE_EXPECTED=1 to write the directory again.
     */
    public function testGeneratesTheVersionedOperationsAndTheModelsThatTheyReach(): void
    {
        $specification = Yaml::parseFile(__DIR__.'/specification.yaml');
        self::assertIsArray($specification);
        $files = self::generator(array_filter($specification, \is_string(...), \ARRAY_FILTER_USE_KEY))->generate();

        $pattern = __DIR__.'/Expected/{,Model/,Operations/}*.php';
        if ('1' === getenv('UPDATE_EXPECTED')) {
            array_map(unlink(...), glob($pattern, \GLOB_BRACE) ?: []);
            foreach ($files as $path => $contents) {
                @mkdir(\dirname(__DIR__.'/Expected/'.$path), 0o755, true);
                file_put_contents(__DIR__.'/Expected/'.$path, $contents);
            }
        }

        $expected = [];
        foreach (glob($pattern, \GLOB_BRACE) ?: [] as $file) {
            $expected[substr($file, \strlen(__DIR__.'/Expected/'))] = file_get_contents($file);
        }
        ksort($expected);
        self::assertSame($expected, $files);
        self::assertArrayNotHasKey('Model/Unused.php', $files);
        self::assertArrayNotHasKey('Operations/Info.php', $files);
    }

    public function testGeneratesNothingWithoutVersionedOperations(): void
    {
        self::assertSame([], self::generator(['paths' => ['/health' => ['get' => ['operationId' => 'getHealth']]]])->generate());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>, string}>
     */
    public static function unsupported(): iterable
    {
        $string = ['type' => 'string'];
        $reference = static fn (string $name): array => ['$ref' => '#/components/schemas/'.$name];
        $returns = static fn (array $schema): array => ['responses' => ['200' => ['content' => ['application/json' => ['schema' => $schema]]]]];

        yield 'an operation without a tag' => [['tags' => []], [], 'first tag of getThing is not a string'];
        yield 'a tag that makes no property name' => [['tags' => ['my-things']], [], 'tag of getThing named my-things'];
        yield 'an inline response schema' => [$returns(['type' => 'object']), [], 'getThing response is not a reference'];
        yield 'a header parameter' => [['parameters' => [['in' => 'header', 'name' => 'X-Reader', 'schema' => $string]]], [], 'parameter X-Reader of getThing is not supported'];
        yield 'a parameter that makes no variable name' => [['parameters' => [['in' => 'query', 'name' => 'page-size', 'schema' => $string]]], [], 'named page-size'];
        yield 'a path parameter that is not a string' => [['parameters' => [['in' => 'path', 'name' => 'id', 'schema' => ['type' => 'integer']]]], [], 'path parameter id of getThing is not a string'];
        yield 'an inline object property' => [$returns($reference('Thing')), ['Thing' => ['type' => 'object', 'properties' => ['details' => ['type' => 'object', 'properties' => ['year' => $string]]]]], 'inline object at Thing.details'];
        yield 'a nullable property' => [$returns($reference('Thing')), ['Thing' => ['type' => 'object', 'properties' => ['amount' => ['type' => 'number', 'nullable' => true]]]], 'Thing.amount is nullable'];
        yield 'a property that makes no variable name' => [$returns($reference('Thing')), ['Thing' => ['type' => 'object', 'properties' => ['first-name' => $string]]], 'property of Thing named first-name'];
        yield 'an allOf schema' => [$returns($reference('Thing')), ['Thing' => ['allOf' => [$reference('Other')]], 'Other' => ['type' => 'object']], 'Thing uses allOf'];
        yield 'a oneOf schema without a discriminator' => [$returns($reference('Thing')), ['Thing' => ['type' => 'object', 'properties' => ['part' => $reference('Part')]], 'Part' => ['oneOf' => [$reference('Other')]], 'Other' => ['type' => 'object']], 'discriminator of Part maps no value to Other'];
        yield 'an enum value that makes no case name' => [['parameters' => [['in' => 'query', 'name' => 'format', 'schema' => $reference('Format')]]], ['Format' => ['type' => 'string', 'enum' => ['e-book']]], 'value e-book of the enum Format'];
        yield 'two enum values with one case name' => [['parameters' => [['in' => 'query', 'name' => 'format', 'schema' => $reference('Format')]]], ['Format' => ['type' => 'string', 'enum' => ['DONE', 'done']]], 'make the case name Done'];
        yield 'a reference without a target' => [$returns($reference('Missing')), [], 'has no target'];
    }

    /**
     * @param array<string, mixed> $operation
     * @param array<string, mixed> $schemas
     */
    #[DataProvider('unsupported')]
    public function testRefusesWhatItCannotGenerate(array $operation, array $schemas, string $message): void
    {
        $generator = self::generator([
            'paths' => ['/v1/things' => ['get' => $operation + ['operationId' => 'getThing', 'tags' => ['things']]]],
            'components' => ['schemas' => $schemas],
        ]);

        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage($message);

        $generator->generate();
    }

    /**
     * @param array<string, mixed> $specification
     */
    private static function generator(array $specification): Generator
    {
        return new Generator($specification, 'Elevate\Tests\Generator\Expected', 'Elevate\Api');
    }
}
