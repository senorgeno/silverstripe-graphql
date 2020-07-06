<?php


namespace SilverStripe\GraphQL\Schema;


use GraphQL\Type\Definition\ResolveInfo;
use ArrayAccess;
use Closure;

class DefaultResolver
{
    /**
    * Note: this is copied and pasted from Executor::defaultFieldResolver(), but migrated
    * out of thirdparty so it will be easy to update if we need to.
     *
    * @param $source
    * @param $args
    * @param $context
    * @param ResolveInfo $info
    *
    * @return mixed|null
    */
    public static function defaultFieldResolver($source, $args, $context, ResolveInfo $info)
    {
        $fieldName = $info->fieldName;
        $property = null;

        if (is_array($source) || $source instanceof ArrayAccess) {
            if (isset($source[$fieldName])) {
                $property = $source[$fieldName];
            }
        } else if (is_object($source)) {
            if (isset($source->{$fieldName})) {
                $property = $source->{$fieldName};
            }
        }

        return $property instanceof Closure ? $property($source, $args, $context, $info) : $property;
    }
}