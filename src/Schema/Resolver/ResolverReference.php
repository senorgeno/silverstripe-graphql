<?php


namespace SilverStripe\GraphQL\Schema\Resolver;


use SilverStripe\Core\Injector\Injectable;
use SilverStripe\GraphQL\Schema\Exception\SchemaBuilderException;
use SilverStripe\GraphQL\Schema\Schema;

class ResolverReference
{
    use Injectable;

    private $class;

    private $method;

    /**
     * @param string|array $callable
     * @throws SchemaBuilderException
     */
    public function __construct($callable)
    {
        Schema::invariant(
            is_array($callable) || (is_string($callable) && stristr($callable, '::') !== false),
            '%s accepts a valid callable in array or string form',
            __CLASS__
        );
        $callableArray = is_string($callable) ? explode('::', $callable) : $callable;
        Schema::invariant(
            is_callable($callableArray),
            'Callable string %s provided to %s is not valid',
            $callable,
            __CLASS__
        );

        list($class, $method) = $callable;
        $this->class = $class;
        $this->method = $method;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return [$this->class, $this->method];
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return sprintf('%s::%s', $this->class, $this->method);
    }

    /**
     * @return string
     */
    public function getClass(): string
    {
        return $this->class;
    }

    /**
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method;
    }
}
