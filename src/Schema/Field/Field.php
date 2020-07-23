<?php

namespace SilverStripe\GraphQL\Schema\Field;


use GraphQL\Error\SyntaxError;
use GraphQL\Language\AST\NameNode;
use GraphQL\Language\AST\NodeList;
use GraphQL\Language\Parser;
use GraphQL\Language\Token;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\GraphQL\Scaffolding\Interfaces\ConfigurationApplier;
use SilverStripe\GraphQL\Schema\Exception\SchemaBuilderException;
use SilverStripe\GraphQL\Schema\Interfaces\SchemaValidator;
use SilverStripe\GraphQL\Schema\Registry\ResolverRegistry;
use SilverStripe\GraphQL\Schema\Resolver\EncodedResolver;
use SilverStripe\GraphQL\Schema\Schema;
use SilverStripe\GraphQL\Schema\Type\EncodedType;
use SilverStripe\GraphQL\Schema\Type\TypeReference;
use SilverStripe\ORM\ArrayList;
use SilverStripe\View\ViewableData;
use ReflectionException;

class Field extends ViewableData implements ConfigurationApplier, SchemaValidator
{
    const DEFAULT_TYPE = 'String';

    /**
     * @var ResolverRegistry
     */
    private $resolverRegistry;

    /**
     * @var string
     */
    private $name;

    /**
     * @var Argument[]
     */
    private $args = [];

    /**
     * @var string|EncodedType
     */
    private $type;

    /**
     * @var string|null
     */
    private $description;

    /**
     * @var array|null
     */
    private $resolver;

    /**
     * @var array|null
     */
    private $defaultResolver;

    /**
     * @var array
     */
    private $resolverContext = [];

    /**
     * Field constructor.
     * @param string|array $name
     * @param array|string $config
     * @throws SchemaBuilderException
     * @throws ReflectionException
     */
    public function __construct(string $name, $config)
    {
        parent::__construct();
        $this->setResolverRegistry(Injector::inst()->get(ResolverRegistry::class));
        list ($name, $args) = static::parseName($name);
        $this->setName($name);
        $this->applyArgs($args);

        Schema::invariant(
            is_string($config) || is_array($config) || $config instanceof Field,
            'Config for field %s must be a string, array, or instance of %s. Got %s',
            $name,
            Field::class,
            gettype($config)
        );
        $appliedConfig = is_string($config) ? ['type' => $config] : $config;
        $this->applyConfig($appliedConfig);
    }

    /**
     * @param array $config
     * @throws SchemaBuilderException
     */
    public function applyConfig(array $config)
    {
        Schema::assertValidConfig($config, [
            'type',
            'args',
            'description',
            'resolver',
            'resolverContext',
            'defaultResolver',
        ]);

        $type = $config['type'] ?? null;
        if ($type) {
            $this->setType($type);
        }
        foreach (['resolver', 'defaultResolver'] as $key) {
            if (isset($config[$key])) {
                Schema::invariant(
                    $config[$key] === null || (is_array($config[$key]) && count($config[$key]) === 2),
                    'Resolvers must be an array tuple of class name, method name'
                );
            }
        }

        if (isset($config['description'])) {
            $this->setDescription($config['description']);
        }

        $this->applyArgs($config['args'] ?? []);

        if (isset($config['resolver'])) {
            $this->setResolver($config['resolver']);
        }
        if (isset($config['defaultResolver'])) {
            $this->setDefaultResolver($config['defaultResolver']);
        }
        if (isset($config['resolverContext'])) {
            $this->setResolverContext($config['resolverContext']);
        }
    }

    /**
     * @param array $args
     * @throws SchemaBuilderException
     * @return $this
     */
    public function applyArgs(array $args): self
    {
        Schema::assertValidConfig($args);
        foreach ($args as $argName => $config) {
            if ($config === false) {
                continue;
            }
            $this->addArg($argName, $config);
        }

        return $this;
    }

    /**
     * @param $arg
     * @param null $config
     * @return Field
     * @throws SchemaBuilderException
     */
    public function addArg($arg, $config = null): Field
    {
        Schema::invariant(
            is_string($arg) || $arg instanceof Argument,
            '%s::%s takes a string as an argument name or an instance of %s',
            __CLASS__,
            __FUNCTION__,
            Argument::class
        );
        $argObj = $arg instanceof Argument
            ? $arg
            : Argument::create($arg, $config);

        $this->args[$argObj->getName()] = $argObj;

        return $this;
    }

    /**
     * @param Field $field
     * @return Field
     */
    public function mergeWith(Field $field): Field
    {
        foreach ($field->getArgs() as $arg) {
            $this->args[$arg->getName()] = $arg;
        }

        return $this;
    }

    /**
     * @throws SchemaBuilderException
     */
    public function validate(): void
    {
        Schema::invariant(
            $this->getEncodedType(),
            'Field %s has no type defined',
            $this->getName()
        );
    }

    /**
     * @param string $type
     * @return EncodedType
     * @throws SchemaBuilderException
     */
    private function toEncodedType(string $type): EncodedType
    {
        try {
            $ref = TypeReference::create($type);
            $ast = $ref->toAST();
            return EncodedType::create($ast);
        } catch (SyntaxError $e) {
            throw new SchemaBuilderException(sprintf(
                'The type for field "%s" is invalid: "%s"',
                $this->name,
                $type
            ));
        }
    }


    /**
     * @param string $def
     * @throws SchemaBuilderException
     * @throws ReflectionException
     * @return array
     */
    public static function parseName(string $def): array
    {
        $name = null;
        $args = null;
        $pos = strpos($def, Token::PAREN_L);
        if ($pos === false) {
            $name = $def;
        } else {
            $name = substr($def, 0, $pos);
            $args = substr($def, $pos);
        }
        try {
            $nameNode = Parser::name($name);
            Schema::invariant(
                $nameNode instanceof NameNode,
                'Could not parse field name "%s"',
                $name
            );
            Schema::assertValidName($nameNode->value);
            $name = $nameNode->value;
        } catch (SyntaxError $e) {
            throw new SchemaBuilderException(sprintf(
                'The name "%s" is not formatted correctly',
                $name
            ));
        }

        if (!$args) {
            return [$name, []];
        }

        try {
            // Not the hack it appears to be!
            // This API is meant to be public, but there is a bug
            // related to strict typing https://github.com/webonyx/graphql-php/issues/698

            // Edit: this has now been fixed in https://github.com/webonyx/graphql-php/pull/693/
            // Remove this when the patch is in a stable release.

            $parser = new Parser($args, ['noLocation' => true]);
            $reflect = new \ReflectionClass(Parser::class);
            $expect = $reflect->getMethod('expect');
            $expect->setAccessible(true);
            $argMethod = $reflect->getMethod('parseArgumentsDefinition');
            $argMethod->setAccessible(true);
            $expect->invoke($parser, Token::SOF);
            $argsNode = $argMethod->invoke($parser);
            $expect->invoke($parser, Token::EOF);
            Schema::invariant(
                $argsNode instanceof NodeList,
                'Could not parse args on "%s"',
                $def
            );
            $argList = [];
            foreach ($argsNode as $arg) {
                $argName = $arg->name->value;
                $argList[$argName] = [
                    'type' => EncodedType::create($arg->type)
                ];
            }

            return [$name, $argList];
        } catch (SyntaxError $e) {
            throw new SchemaBuilderException(sprintf(
                'The arguments for %s are not formatted correctly',
                $name
            ));
        }
    }

    /**
     * @param $type
     * @return Field
     * @throws SchemaBuilderException
     */
    public function setType($type): Field
    {
        Schema::invariant(
            is_string($type) || $type instanceof EncodedType,
            '%s::%s must be a string or an instance of %s',
            __CLASS__,
            __FUNCTION__,
            EncodedType::class
        );

        $this->type = $type;

        return $this;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return Field
     */
    public function setName(string $name): Field
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return Argument[]
     */
    public function getArgs(): array
    {
        return $this->args;
    }

    /**
     * @return ArrayList
     */
    public function getArgList(): ArrayList
    {
        return ArrayList::create(array_values($this->args));
    }

    /**
     * @param Argument[] $args
     * @return Field
     */
    public function setArgs(array $args): Field
    {
        $this->args = $args;
        return $this;
    }

    /**
     * @return EncodedType
     * @throws SchemaBuilderException
     */
    public function getEncodedType(): EncodedType
    {
        return $this->type instanceof EncodedType ? $this->type : $this->toEncodedType($this->type);
    }

    /**
     * @param string|null $typeName
     * @return EncodedResolver
     * @throws SchemaBuilderException
     */
    public function getEncodedResolver(?string $typeName = null): EncodedResolver
    {
        if ($this->getResolver()) {
            $encodedResolver = EncodedResolver::create($this->getResolver());
        } else {
            $resolver = $this->getResolverRegistry()->findResolver(
                $typeName,
                $this->name,
                $this->getDefaultResolver()
            );
            $encodedResolver = EncodedResolver::create($resolver);
        }

        foreach ($this->getResolverContext() as $name => $value) {
            $encodedResolver->addContext($name, $value);
        }

        return $encodedResolver;
    }

    /**
     * @return string|null
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @param string|null $description
     * @return Field
     */
    public function setDescription(?string $description): Field
    {
        $this->description = $description;
        return $this;
    }

    /**
     * @return array|null
     */
    public function getResolver(): ?array
    {
        return $this->resolver;
    }

    /**
     * @param array|null $resolver
     * @return Field
     */
    public function setResolver(?array $resolver): Field
    {
        $this->resolver = $resolver;
        return $this;
    }

    /**
     * @return array|null
     */
    public function getDefaultResolver(): ?array
    {
        return $this->defaultResolver;
    }

    /**
     * @param array|null $defaultResolver
     * @return Field
     */
    public function setDefaultResolver(?array $defaultResolver): Field
    {
        $this->defaultResolver = $defaultResolver;
        return $this;
    }


    /**
     * @return ResolverRegistry
     */
    public function getResolverRegistry(): ResolverRegistry
    {
        return $this->resolverRegistry;
    }

    /**
     * @param ResolverRegistry $resolverRegistry
     * @return $this
     */
    public function setResolverRegistry(ResolverRegistry $resolverRegistry): Field
    {
        $this->resolverRegistry = $resolverRegistry;
        return $this;
    }

    /**
     * @return array|null
     */
    public function getResolverContext(): ?array
    {
        return $this->resolverContext;
    }

    /**
     * @param array|null $resolverContext
     * @return Field
     */
    public function setResolverContext(?array $resolverContext): Field
    {
        $this->resolverContext = $resolverContext;
        return $this;
    }

    /**
     * @param string $key
     * @param $value
     * @return Field
     */
    public function addResolverContext(string $key, $value): Field
    {
        $this->resolverContext[$key] = $value;

        return $this;
    }

}
