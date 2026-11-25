<?php

declare(strict_types=1);

namespace Elevate\SystemTests\Support;

use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Response as ResponseSpecification;
use cebe\openapi\spec\Schema;
use Elevate\Http\Request;
use Elevate\Http\Response;
use League\OpenAPIValidation\PSR7\Exception\Validation\InvalidParameter;
use League\OpenAPIValidation\PSR7\Exception\Validation\RequiredParameterMissing;
use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\PathFinder;
use League\OpenAPIValidation\PSR7\SpecFinder;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use League\OpenAPIValidation\Schema\Exception\KeywordMismatch;
use Nyholm\Psr7\Response as PsrResponse;
use Nyholm\Psr7\ServerRequest;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads an OpenAPI specification for the fake: it finds the operation of a request, checks a request and a response
 * with league/openapi-psr7-validator, and builds a response that a response schema accepts.
 *
 * It reads each object schema as closed, where the specification leaves it open: a property that the specification
 * does not name is then a ContractViolation.
 */
final readonly class Specification
{
    private function __construct(private OpenApi $document, private ValidatorBuilder $validators)
    {
    }

    /**
     * Reads the specification that the SDK is generated from.
     */
    public static function pinned(): self
    {
        $file = \dirname(__DIR__, 2).'/openapi/openapi-spec.yaml';
        $yaml = file_get_contents($file);
        if (false === $yaml) {
            throw new \RuntimeException(\sprintf('The specification file %s is not readable.', $file));
        }

        return self::fromYaml($yaml);
    }

    public static function fromYaml(string $yaml): self
    {
        $validators = (new ValidatorBuilder())->fromJson(json_encode(self::closed(Yaml::parse($yaml)), \JSON_THROW_ON_ERROR));

        return new self($validators->getResponseValidator()->getSchema(), $validators);
    }

    /**
     * @return list<Operation>
     */
    public function operations(): array
    {
        $operations = [];
        foreach ($this->document->paths->getPaths() as $path => $item) {
            foreach ($item->getOperations() as $method => $operation) {
                $operations[] = new Operation($operation->operationId, new OperationAddress((string) $path, (string) $method), $operation);
            }
        }

        return $operations;
    }

    /**
     * Finds the operation that a request addresses.
     *
     * @param string $path the path of the request, still URL-encoded
     *
     * @return array{Operation, array<string, string>}|null the operation and the decoded path parameters by name
     */
    public function match(string $method, string $path): ?array
    {
        $addresses = (new PathFinder($this->document, $path, strtolower($method)))->search();
        if ([] === $addresses) {
            return null;
        }
        foreach ($this->operations() as $operation) {
            if ((string) $operation->address === (string) $addresses[0]) {
                $parameters = [];
                foreach ($operation->address->parseParams($path) as $name => $value) {
                    $parameters[(string) $name] = rawurldecode(\is_scalar($value) ? (string) $value : '');
                }

                return [$operation, $parameters];
            }
        }

        return null;
    }

    /**
     * @return list<string>|null the scopes that the operation needs, or null when it takes no access token
     */
    public function scopes(Operation $operation): ?array
    {
        $requirements = $operation->specification->security;
        if ([] === $requirements) {
            return null;
        }
        $requirement = (array) reset($requirements)->getSerializableData();
        $scopes = $requirement['oauth2'] ?? throw new ContractViolation(\sprintf('The operation %s has no oauth2 security.', $operation->id));

        return \is_array($scopes) ? array_values(array_map(self::text(...), $scopes)) : [];
    }

    public function successStatus(Operation $operation): int
    {
        foreach (array_keys($operation->specification->responses?->getResponses() ?? []) as $statusCode) {
            if ($statusCode >= 200 && $statusCode < 300) {
                return (int) $statusCode;
            }
        }

        throw new ContractViolation(\sprintf('The operation %s has no success response.', $operation->id));
    }

    /**
     * Checks a request against the parameters and the request body of its operation.
     *
     * @return list<array{code: string, path: string}> the schema keyword that a value fails, and the path of that value
     */
    public function violations(Operation $operation, Request $request): array
    {
        parse_str((string) parse_url($request->url, \PHP_URL_QUERY), $query);
        $known = array_keys((new SpecFinder($this->document))->findQuerySpecs($operation->address));
        foreach (array_keys($query) as $name) {
            if (!\in_array($name, $known, true)) {
                throw new ContractViolation(\sprintf('The specification of %s has no query parameter "%s".', $operation->id, $name));
            }
        }
        if (null === $operation->specification->requestBody && '' !== $request->body) {
            throw new ContractViolation(\sprintf('The operation %s takes no request body.', $operation->id));
        }

        try {
            $this->validators->getRoutedRequestValidator()->validate(
                $operation->address,
                (new ServerRequest($request->method, $request->url, $request->headers, $request->body))->withQueryParams($query),
            );
        } catch (ValidationFailed $failure) {
            return [self::violation($operation, $failure)];
        }

        return [];
    }

    /**
     * Throws a ContractViolation at a response that the specification of the operation does not describe.
     */
    public function check(Operation $operation, Response $response): void
    {
        try {
            $this->validators->getResponseValidator()->validate($operation->address, new PsrResponse($response->statusCode, $response->headers, $response->body));
        } catch (ValidationFailed $failure) {
            throw new ContractViolation(\sprintf('The response of %s with status %d does not match the specification: %s', $operation->id, $response->statusCode, self::describe($failure)), 0, $failure);
        }
    }

    /**
     * Builds the body of a response of the operation, with every property that the response schema names.
     *
     * A string holds the name of its property, and each integer is its minimum plus a count that grows by one with
     * every integer built. A value that a model reads from the key of another property therefore differs from the
     * expected one.
     *
     * @return array<array-key, mixed>|null the body, or null when the response has none
     */
    public function example(Operation $operation, int $statusCode): ?array
    {
        $response = $operation->specification->responses?->getResponse((string) $statusCode);
        if (null === $response) {
            throw new ContractViolation(\sprintf('The operation %s has no response with status %d.', $operation->id, $statusCode));
        }
        $content = $response instanceof ResponseSpecification ? $response->content : [];
        if ([] === $content) {
            return null;
        }
        $count = 0;
        $body = self::build(self::schema(($content['application/json'] ?? null)?->schema), 'value', $count);

        return \is_array($body) ? $body : throw new ContractViolation(\sprintf('The response of %s is not an object.', $operation->id));
    }

    /**
     * @param string $name  the name of the property that holds the value
     * @param int    $count the integers built so far
     */
    private static function build(Schema $schema, string $name, int &$count): mixed
    {
        if (isset($schema->enum[0])) {
            return $schema->enum[0];
        }

        switch ($schema->type) {
            case 'object':
                $object = [];
                foreach ($schema->properties as $property => $propertySchema) {
                    $object[$property] = self::build(self::schema($propertySchema), (string) $property, $count);
                }
                if ($schema->additionalProperties instanceof Schema) {
                    $object['key'] = self::build($schema->additionalProperties, $name, $count);
                }

                return [] === $object ? throw new ContractViolation('The fake cannot build an object without properties.') : $object;
            case 'array':
                return [self::build(self::schema($schema->items), $name, $count)];
            case 'string':
                if ('' !== (string) $schema->pattern) {
                    throw new ContractViolation('The fake cannot build a string for a pattern.');
                }

                return match ((string) $schema->format) {
                    'uuid' => \sprintf('%08x-0000-4000-8000-000000000000', crc32($name)),
                    'uri' => 'https://elevate.example.test/'.$name,
                    '' => str_pad($name, $schema->minLength ?? 0, 't'),
                    default => throw new ContractViolation(\sprintf('The fake cannot build a string of the format "%s".', $schema->format)),
                };
            case 'integer':
                return (int) ($schema->minimum ?? 0) + ++$count;
            case 'boolean':
                return true;
            default:
                throw new ContractViolation('The fake cannot build a value for a schema without a supported type.');
        }
    }

    private static function schema(mixed $schema): Schema
    {
        return $schema instanceof Schema ? $schema : throw new ContractViolation('The specification has no schema where the fake needs one.');
    }

    /**
     * @return array{code: string, path: string}
     */
    private static function violation(Operation $operation, ValidationFailed $failure): array
    {
        $path = [];
        for ($cause = $failure; null !== $cause; $cause = $cause->getPrevious()) {
            if ($cause instanceof RequiredParameterMissing) {
                return ['code' => 'required', 'path' => $cause->name()];
            }
            if ($cause instanceof InvalidParameter) {
                $path = [$cause->name()];
            }
            if ($cause instanceof KeywordMismatch) {
                // Takes the path in the body, or else the name of the parameter: the library names a parameter twice.
                $path = array_map(self::text(...), $cause->dataBreadCrumb()?->buildChain() ?? []) ?: $path;
                if ('additionalProperties' === $cause->keyword()) {
                    break;
                }

                return ['code' => $cause->keyword(), 'path' => implode('.', $path)];
            }
        }

        throw new ContractViolation(\sprintf('The request of %s does not match the specification: %s', $operation->id, self::describe($failure)), 0, $failure);
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private static function describe(\Throwable $failure): string
    {
        $messages = [];
        for ($cause = $failure; null !== $cause; $cause = $cause->getPrevious()) {
            $messages[] = $cause->getMessage();
        }

        return implode(' ', $messages);
    }

    /**
     * Closes each object schema that names its properties and leaves `additionalProperties` out.
     */
    private static function closed(mixed $node): mixed
    {
        if (!\is_array($node)) {
            return $node;
        }
        if ('object' === ($node['type'] ?? null) && isset($node['properties']) && !\array_key_exists('additionalProperties', $node)) {
            $node['additionalProperties'] = false;
        }

        return array_map(self::closed(...), $node);
    }
}
