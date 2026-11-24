<?php

declare(strict_types=1);

namespace Elevate\Generator;

/**
 * Generates the operations trait and the model classes from an OpenAPI 3.0 specification.
 *
 * It reads the constructs that the elevate.io specification uses. It throws on a construct that would change the
 * generated code and that it does not read, such as allOf. An inline enum becomes a string.
 */
final class Generator
{
    private const array HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete'];
    private const string VERSIONED = '/v1/';
    private const string NOTICE = 'Generated from the OpenAPI specification. Do not edit it: run `composer generate`.';

    /** @var array<string, true> */
    private array $reachable = [];

    /**
     * @param array<string, mixed> $specification the decoded OpenAPI document
     * @param string               $namespace     the namespace of the Operations class; the operation classes and the
     *                                            models go one level below
     * @param string               $runtime       the namespace of Decode, Model and Transport
     */
    public function __construct(
        private readonly array $specification,
        private readonly string $namespace,
        private readonly string $runtime,
    ) {
    }

    /**
     * Generates every operation under /v1/, grouped by its first tag, and the models that the operations reach.
     *
     * @return array<string, string> the file contents by path, relative to the directory of the namespace
     */
    public function generate(): array
    {
        $this->reachable = [];
        $groups = [];
        foreach ($this->operations() as $operationId => $operation) {
            if (str_starts_with($operation['path'], self::VERSIONED)) {
                $tag = self::string(self::list($operation['definition']['tags'] ?? null)[0] ?? null, "first tag of {$operationId}");
                self::identifier($tag, "tag of {$operationId}");
                $groups[$tag][$operationId] = $operation;
            }
        }
        if ([] === $groups) {
            return [];
        }

        $files = ['Operations.php' => $this->operationsTrait(array_keys($groups))];
        foreach ($groups as $tag => $operations) {
            $files['Operations/'.ucfirst($tag).'.php'] = $this->group($tag, $operations);
        }
        // Each model marks the schemas of its properties as reachable, so the loop runs until it finds no new one.
        for ($done = []; [] !== ($pending = array_diff_key($this->reachable, $done));) {
            foreach (array_keys($pending) as $name) {
                $files['Model/'.$name.'.php'] = $this->model($name);
                $done[$name] = true;
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * @return array<string, array{method: string, path: string, definition: array<string, mixed>}>
     */
    private function operations(): array
    {
        $operations = [];
        foreach (self::object($this->specification['paths'] ?? null) as $path => $item) {
            foreach (self::object($item) as $method => $definition) {
                $definition = self::object($definition);
                $operationId = $definition['operationId'] ?? null;
                if (\in_array($method, self::HTTP_METHODS, true) && \is_string($operationId)) {
                    $definition['parameters'] = [...self::list(self::object($item)['parameters'] ?? null), ...self::list($definition['parameters'] ?? null)];
                    $operations[$operationId] = ['method' => strtoupper($method), 'path' => $path, 'definition' => $definition];
                }
            }
        }

        return $operations;
    }

    /**
     * @param array{method: string, path: string, definition: array<string, mixed>} $operation
     * @param array<string, true>                                                   $imports
     */
    private function method(string $operationId, array $operation, array &$imports): string
    {
        $definition = $operation['definition'];
        $required = [];
        $optional = [];
        $pathParameters = [];
        $query = [];
        foreach (self::list($definition['parameters'] ?? null) as $parameter) {
            $parameter = $this->resolve($parameter);
            $name = self::string($parameter['name'] ?? null, $operationId.' parameter name');
            $in = $parameter['in'] ?? null;
            if ('path' === $in || 'query' === $in) {
                self::identifier($name, "parameter of {$operationId}");
            }
            $type = $this->type(self::object($parameter['schema'] ?? null), $operationId.'.'.$name);
            if ('path' === $in) {
                if ('string' !== $type->kind) {
                    throw new GeneratorException("The path parameter {$name} of {$operationId} is not a string.");
                }
                $required[] = "string \${$name}";
                $pathParameters[] = "'{$name}' => \${$name}";
                continue;
            }
            if ('query' !== $in || !\in_array($type->kind, ['string', 'int', 'bool', 'enum'], true)) {
                throw new GeneratorException("The parameter {$name} of {$operationId} is not supported.");
            }
            $isRequired = true === ($parameter['required'] ?? false);
            if ('enum' === $type->kind) {
                $imports[$this->namespace.'\Model\\'.$type->php] = true;
                $query[] = "'{$name}' => \${$name}".($isRequired ? '' : '?').'->value';
            } else {
                $query[] = "'{$name}' => \${$name}";
            }
            if ($isRequired) {
                $required[] = "{$type->php} \${$name}";
            } else {
                $optional[] = "?{$type->php} \${$name} = null";
            }
        }

        $body = 'null';
        if (isset($definition['requestBody'])) {
            $bodyClass = $this->contentModel($this->resolve($definition['requestBody']), $operationId.' request body');
            $imports[$this->namespace.'\Model\\'.$bodyClass] = true;
            $required[] = "{$bodyClass} \$body";
            $body = '$body';
        }

        $responseClass = null;
        // Reads the array as it is: PHP turns the status keys into integers, which self::object drops.
        foreach (\is_array($definition['responses'] ?? null) ? $definition['responses'] : [] as $status => $response) {
            if (str_starts_with((string) $status, '2')) {
                $response = $this->resolve($response);
                if (isset($response['content'])) {
                    $responseClass = $this->contentModel($response, $operationId.' response');
                    $imports[$this->namespace.'\Model\\'.$responseClass] = true;
                }
                break;
            }
        }

        $scopes = [];
        foreach (self::list($definition['security'] ?? null) as $requirement) {
            foreach (self::object($requirement) as $names) {
                foreach (self::list($names) as $scope) {
                    $scopes[] = '`'.self::string($scope, $operationId.' scope').'`';
                }
            }
        }
        $documentation = self::lines(
            $definition['description'] ?? null,
            [] === $scopes ? '' : 'It needs the '.implode(' and ', $scopes).' scope'.(1 === \count($scopes) ? '' : 's').'.',
        );

        $arguments = implode(', ', [
            "'{$operation['method']}'",
            "'{$operation['path']}'",
            '['.implode(', ', $pathParameters).']',
            '['.implode(', ', $query).']',
            $body,
            null === $responseClass ? 'null' : $responseClass.'::class',
        ]);
        $lines = ['    /**'];
        foreach ([...$documentation, ...([] === $documentation ? [] : [''])] as $line) {
            $lines[] = rtrim('     * '.$line);
        }
        array_push(
            $lines,
            '     * @throws ApiException',
            '     * @throws ValidationException',
            '     * @throws TokenRequestException',
            '     * @throws TransportException',
            '     * @throws UnexpectedResponseException',
            '     */',
            "    public function {$operationId}(".implode(', ', [...$required, ...$optional]).'): '.($responseClass ?? 'void'),
            '    {',
            '        '.(null === $responseClass ? '' : 'return ')."\$this->transport->call({$arguments});",
            '    }',
        );

        return implode("\n", $lines);
    }

    /**
     * @param array<string, array{method: string, path: string, definition: array<string, mixed>}> $operations
     */
    private function group(string $tag, array $operations): string
    {
        $imports = [
            $this->runtime.'\Transport' => true,
            'Elevate\Exception\ApiException' => true,
            'Elevate\Exception\TokenRequestException' => true,
            'Elevate\Exception\TransportException' => true,
            'Elevate\Exception\UnexpectedResponseException' => true,
            'Elevate\Exception\ValidationException' => true,
        ];
        $methods = [];
        foreach ($operations as $operationId => $operation) {
            $methods[] = $this->method($operationId, $operation, $imports);
        }
        $methods = implode("\n\n", $methods);
        $class = ucfirst($tag);
        $groupNamespace = $this->namespace.'\Operations';

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$groupNamespace};

            {$this->uses(array_keys($imports), $groupNamespace)}
            {$this->classDocumentation(['description' => $this->tagDescription($tag)])}
            final readonly class {$class}
            {
                public function __construct(private Transport \$transport)
                {
                }

            {$methods}
            }

            PHP;
    }

    /**
     * @param list<string> $tags
     */
    private function operationsTrait(array $tags): string
    {
        $imports = [$this->runtime.'\Transport'];
        $properties = [];
        $assignments = [];
        foreach ($tags as $tag) {
            $class = ucfirst($tag);
            $imports[] = $this->namespace.'\Operations\\'.$class;
            $description = self::lines($this->tagDescription($tag));
            $properties[] = ([] === $description ? '' : "    /**\n".implode("\n", array_map(static fn (string $line): string => rtrim('     * '.$line), $description))."\n     */\n")
                ."    public readonly {$class} \${$tag};";
            $assignments[] = "        \$this->{$tag} = new {$class}(\$transport);";
        }
        $properties = implode("\n\n", $properties);
        $assignments = implode("\n", $assignments);
        $notice = self::NOTICE;

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$this->namespace};

            {$this->uses($imports, $this->namespace)}
            /**
             * The operations of the elevate.io API, grouped as the API specification tags them.
             *
             * Every operation throws:
             * - ApiException when elevate.io refuses the request;
             * - ValidationException when the request is invalid;
             * - TokenRequestException when elevate.io refuses a refresh, and `isInvalidGrant()` means the connection is revoked;
             * - TransportException when the request or a refresh fails in transit, or elevate.io fails with a status of 500 or more;
             * - UnexpectedResponseException when the SDK cannot read the response.
             *
             * {$notice}
             */
            abstract class Operations
            {
            {$properties}

                protected function __construct(Transport \$transport)
                {
            {$assignments}
                }
            }

            PHP;
    }

    private function tagDescription(string $tag): ?string
    {
        foreach (self::list($this->specification['tags'] ?? null) as $declared) {
            $declared = self::object($declared);
            if ($tag === ($declared['name'] ?? null) && \is_string($declared['description'] ?? null)) {
                return $declared['description'];
            }
        }

        return null;
    }

    private function model(string $name): string
    {
        $schema = $this->schema($name);
        if (isset($schema['enum'])) {
            return $this->enum($name, $schema);
        }
        if (isset($schema['oneOf'])) {
            return $this->union($name, $schema);
        }
        foreach (['anyOf', 'allOf'] as $keyword) {
            if (isset($schema[$keyword])) {
                throw new GeneratorException("The schema {$name} uses {$keyword}, which the generator does not support.");
            }
        }
        if ('object' !== ($schema['type'] ?? null)) {
            throw new GeneratorException("The schema {$name} is not an object.");
        }

        $requiredNames = self::list($schema['required'] ?? null);
        $properties = [];
        foreach (self::object($schema['properties'] ?? null) as $propertyName => $property) {
            self::identifier($propertyName, "property of {$name}");
            $type = $this->type(self::object($property), $name.'.'.$propertyName);
            $description = self::lines(self::object($property)['description'] ?? null);
            // Reads an enum of a response-only model as a string: a PHP enum would make every released version of
            // the SDK fail on a value that a later version of the API adds.
            if (true === ($schema['readOnly'] ?? false)) {
                if ('enum' === $type->kind) {
                    $description[] = "A value of {$type->php}, or one that a later version of the API added.";
                }
                $type = self::withoutEnums($type);
            }
            $properties[$propertyName] = [
                'type' => $type,
                'required' => \in_array($propertyName, $requiredNames, true),
                'description' => implode("\n", array_map(static fn (string $line): string => rtrim('         * '.$line), $description)),
            ];
        }
        // Sorts the required properties first: an optional constructor parameter cannot precede a required one.
        uasort($properties, static fn (array $left, array $right): int => $right['required'] <=> $left['required']);

        $unions = $this->unionsOf($name);
        $implements = implode(', ', [] === $unions ? ['Model'] : $unions);
        $imports = [] === $unions ? [$this->runtime.'\Model' => true] : [];
        $parameters = [];
        $decoders = [];
        $encoders = [];
        foreach ($properties as $propertyName => $property) {
            $type = $property['type'];
            $documentation = array_filter([$property['description'], $type->php === $type->doc ? '' : '         * @var '.$type->doc.($property['required'] ? '' : '|null')]);
            if ([] !== $documentation) {
                $parameters[] = "        /**\n".implode("\n         *\n", $documentation)."\n         */";
            }
            $parameters[] = '        public '.($property['required'] ? $type->php : '?'.$type->php)." \${$propertyName}".($property['required'] ? '' : ' = null').',';
            $decoders[] = '            '.($property['required']
                ? $this->decode($type, "\$data['{$propertyName}'] ?? null", $propertyName, 0)
                : "isset(\$data['{$propertyName}']) ? ".$this->decode($type, "\$data['{$propertyName}']", $propertyName, 0).' : null').',';
            // Casts a map to an object: json_encode writes an empty PHP array as a JSON array.
            $encoders[] = "            '{$propertyName}' => ".match (true) {
                'map' === $type->kind && $property['required'] => "(object) \$this->{$propertyName},",
                'map' === $type->kind => "null === \$this->{$propertyName} ? null : (object) \$this->{$propertyName},",
                // Drops the keys: json_encode writes an array with other keys than 0, 1, 2 as a JSON object.
                'list' === $type->kind && $property['required'] => "array_values(\$this->{$propertyName}), // @phpstan-ignore arrayValues.list",
                'list' === $type->kind => "null === \$this->{$propertyName} ? null : array_values(\$this->{$propertyName}), // @phpstan-ignore arrayValues.list",
                default => "\$this->{$propertyName},",
            };
            $imports[$this->runtime.'\Decode'] = true;
        }
        $hasOptional = \in_array(false, array_column($properties, 'required'), true);
        $encoded = [] === $encoders ? '[]' : "[\n".implode("\n", $encoders)."\n        ]";
        if ($hasOptional) {
            $encoded = "array_filter({$encoded}, static fn (mixed \$value): bool => null !== \$value)";
        }
        // Casts to an object: json_encode writes an empty PHP array as a JSON array.
        $encoded = '(object) '.$encoded;
        $constructor = [] === $parameters ? '' : "    public function __construct(\n".implode("\n", $parameters)."\n    ) {\n    }\n\n";
        $created = [] === $decoders ? 'new self()' : "new self(\n".implode("\n", $decoders)."\n        )";
        $modelNamespace = $this->namespace.'\Model';

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$modelNamespace};

            {$this->uses(array_keys($imports), $modelNamespace)}
            {$this->classDocumentation($schema)}
            final readonly class {$name} implements {$implements}
            {
            {$constructor}    public static function fromArray(array \$data): static
                {
                    return {$created};
                }

                public function jsonSerialize(): object
                {
                    return {$encoded};
                }
            }

            PHP;
    }

    /**
     * Generates the interface that the variants of a oneOf schema implement.
     *
     * @param array<string, mixed> $schema
     */
    private function union(string $name, array $schema): string
    {
        foreach (array_keys($this->variants($name, $schema)) as $variant) {
            $this->reachable[$variant] = true;
        }
        $modelNamespace = $this->namespace.'\Model';

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$modelNamespace};

            use {$this->runtime}\Model;

            {$this->classDocumentation($schema)}
            interface {$name} extends Model
            {
            }

            PHP;
    }

    /**
     * @param array<string, mixed> $schema a oneOf schema
     *
     * @return array<string, string> the discriminator value by variant schema
     */
    private function variants(string $name, array $schema): array
    {
        $mapping = self::object(self::object($schema['discriminator'] ?? null)['mapping'] ?? null);
        $variants = [];
        foreach (self::list($schema['oneOf'] ?? null) as $variant) {
            $variant = $this->schemaName(self::object($variant)['$ref'] ?? null);
            $value = array_search('#/components/schemas/'.$variant, $mapping, true);
            if (!\is_string($value)) {
                throw new GeneratorException("The discriminator of {$name} maps no value to {$variant}.");
            }
            $variants[$variant] = $value;
        }

        return $variants;
    }

    /**
     * @return list<string> the oneOf schemas that list the schema as a variant
     */
    private function unionsOf(string $name): array
    {
        $unions = [];
        foreach (self::object(self::object($this->specification['components'] ?? null)['schemas'] ?? null) as $candidate => $schema) {
            foreach (self::list(self::object($schema)['oneOf'] ?? null) as $variant) {
                if ('#/components/schemas/'.$name === (self::object($variant)['$ref'] ?? null)) {
                    $unions[] = $candidate;
                }
            }
        }

        return $unions;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function enum(string $name, array $schema): string
    {
        if ('string' !== ($schema['type'] ?? null)) {
            throw new GeneratorException("The enum {$name} is not a string enum.");
        }
        $cases = [];
        foreach (self::list($schema['enum']) as $value) {
            $value = self::string($value, $name.' enum value');
            if (1 !== preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $value)) {
                throw new GeneratorException("The value {$value} of the enum {$name} does not make a case name.");
            }
            $case = str_replace('_', '', ucwords(strtolower($value), '_'));
            if (isset($cases[$case])) {
                throw new GeneratorException("Two values of the enum {$name} make the case name {$case}.");
            }
            $cases[$case] = "    case {$case} = '{$value}';";
        }
        $cases = implode("\n", $cases);
        $modelNamespace = $this->namespace.'\Model';

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$modelNamespace};

            {$this->classDocumentation($schema)}
            enum {$name}: string
            {
            {$cases}
            }

            PHP;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function classDocumentation(array $schema): string
    {
        $description = implode("\n", array_map(static fn (string $line): string => rtrim(' * '.$line), self::lines($schema['description'] ?? null)));

        return "/**\n".('' === $description ? '' : "{$description}\n *\n").' * '.self::NOTICE."\n */";
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function type(array $schema, string $context): Type
    {
        if (true === ($schema['nullable'] ?? false)) {
            throw new GeneratorException("The schema at {$context} is nullable, which the generator does not support.");
        }
        if (isset($schema['$ref'])) {
            $name = $this->schemaName($schema['$ref']);
            $this->reachable[$name] = true;

            $target = $this->schema($name);

            return new Type(match (true) {
                isset($target['enum']) => 'enum',
                isset($target['oneOf']) => 'union',
                default => 'model',
            }, $name, $name);
        }
        $additional = $schema['additionalProperties'] ?? null;

        return match ($schema['type'] ?? null) {
            'string' => new Type('string', 'string', 'string'),
            'integer' => new Type('int', 'int', 'int'),
            'number' => new Type('float', 'float', 'float'),
            'boolean' => new Type('bool', 'bool', 'bool'),
            'array' => $this->container('list', self::object($schema['items'] ?? null), $context),
            'object' => \is_array($additional) && !isset($schema['properties'])
                ? $this->container('map', self::object($additional), $context)
                : throw new GeneratorException("The inline object at {$context} is not supported: move it to a named schema."),
            default => throw new GeneratorException("The schema at {$context} has no supported type."),
        };
    }

    /**
     * @param array<string, mixed> $inner
     */
    private function container(string $kind, array $inner, string $context): Type
    {
        $innerType = $this->type($inner, $context.'[]');

        return new Type($kind, 'array', 'list' === $kind ? "list<{$innerType->doc}>" : "array<string, {$innerType->doc}>", $innerType);
    }

    private static function withoutEnums(Type $type): Type
    {
        if ('enum' === $type->kind) {
            return new Type('string', 'string', 'string');
        }
        if (null === $type->inner) {
            return $type;
        }
        $inner = self::withoutEnums($type->inner);

        return new Type($type->kind, 'array', 'list' === $type->kind ? "list<{$inner->doc}>" : "array<string, {$inner->doc}>", $inner);
    }

    private function decode(Type $type, string $value, string $path, int $depth): string
    {
        if (null !== $type->inner) {
            $item = '$item'.(0 === $depth ? '' : $depth + 1);
            $inner = $this->decode($type->inner, $item, $path.'[]', $depth + 1);

            return "array_map(static fn (mixed {$item}): {$type->inner->php} => {$inner}, Decode::{$type->kind}({$value}, '{$path}'))";
        }

        return match ($type->kind) {
            'model' => "Decode::model({$type->php}::class, {$value}, '{$path}')",
            'enum' => "Decode::enum({$type->php}::class, {$value}, '{$path}')",
            'union' => $this->decodeUnion($type->php, $value, $path),
            default => "Decode::{$type->kind}({$value}, '{$path}')",
        };
    }

    private function decodeUnion(string $name, string $value, string $path): string
    {
        $schema = $this->schema($name);
        $classes = [];
        foreach ($this->variants($name, $schema) as $variant => $discriminatorValue) {
            $classes[] = "'".addcslashes($discriminatorValue, "'\\")."' => {$variant}::class";
        }
        $property = self::string(self::object($schema['discriminator'] ?? null)['propertyName'] ?? null, "discriminator property of {$name}");

        return 'Decode::union(['.implode(', ', $classes)."], '{$property}', {$value}, '{$path}')";
    }

    /**
     * @param array<string, mixed> $container a request body or a response
     */
    private function contentModel(array $container, string $context): string
    {
        $schema = self::object(self::object(self::object($container['content'] ?? null)['application/json'] ?? null)['schema'] ?? null);
        if (!isset($schema['$ref'])) {
            throw new GeneratorException("The {$context} is not a reference to a JSON schema.");
        }
        $name = $this->schemaName($schema['$ref']);
        if (isset($this->schema($name)['enum'])) {
            throw new GeneratorException("The {$context} is an enum, not an object.");
        }
        $this->reachable[$name] = true;

        return $name;
    }

    /**
     * @param list<string> $imports
     */
    private function uses(array $imports, string $namespace): string
    {
        // Leaves out the classes of the namespace itself: PHP resolves them without an import.
        $imports = array_filter($imports, static fn (string $import): bool => substr($import, 0, (int) strrpos($import, '\\')) !== $namespace);
        sort($imports);

        return implode('', array_map(static fn (string $import): string => "use {$import};\n", $imports));
    }

    /**
     * @return array<string, mixed>
     */
    private function resolve(mixed $node): array
    {
        $node = self::object($node);
        $reference = $node['$ref'] ?? null;
        if (!\is_string($reference)) {
            return $node;
        }
        if (!str_starts_with($reference, '#/')) {
            throw new GeneratorException("The reference {$reference} does not point into the specification.");
        }
        $target = $this->specification;
        foreach (explode('/', substr($reference, 2)) as $segment) {
            $target = self::object($target)[$segment] ?? throw new GeneratorException("The reference {$reference} has no target.");
        }

        return self::object($target);
    }

    private function schemaName(mixed $reference): string
    {
        $prefix = '#/components/schemas/';
        if (!\is_string($reference) || !str_starts_with($reference, $prefix)) {
            throw new GeneratorException('A schema reference does not point to a named schema.');
        }

        return substr($reference, \strlen($prefix));
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(string $name): array
    {
        return $this->resolve(['$ref' => '#/components/schemas/'.$name]);
    }

    /**
     * Returns the lines of a description, ending with a full stop as the code style requires of a summary.
     *
     * @return list<string>
     */
    private static function lines(mixed $description, string $suffix = ''): array
    {
        $text = trim(\is_string($description) ? $description : '');
        if ('' !== $text && 1 !== preg_match('/[.!?]$/', $text)) {
            $text .= '.';
        }
        // Breaks a comment terminator, which would end the docblock that the description goes into.
        $lines = '' === $text ? [] : array_map(trim(...), explode("\n", str_replace('*/', '* /', $text)));

        return '' === $suffix ? $lines : [...$lines, $suffix];
    }

    /**
     * @return array<string, mixed>
     */
    private static function object(mixed $node): array
    {
        return \is_array($node) ? array_filter($node, \is_string(...), \ARRAY_FILTER_USE_KEY) : [];
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $node): array
    {
        return \is_array($node) ? array_values($node) : [];
    }

    private static function identifier(string $name, string $context): void
    {
        if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new GeneratorException("The {$context} named {$name} does not make a PHP variable name.");
        }
    }

    private static function string(mixed $node, string $context): string
    {
        return \is_string($node) ? $node : throw new GeneratorException("The {$context} is not a string.");
    }
}
