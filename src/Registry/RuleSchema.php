<?php

declare(strict_types=1);

namespace Odden\Core\Registry;

use Illuminate\Contracts\Validation\Rule;

/**
 * Turns an action's Laravel validation rules into a JSON schema for a model's tool definition. It reads the common string rules
 * (string, integer, numeric, boolean, array, email, date, in:, max:, min:, nullable) and ignores ones it does not know, so a
 * rule it cannot describe still validates the input but is not promised to the model.
 */
final class RuleSchema
{
    /**
     * @param  array<string, mixed>  $rules
     * @return array{type: string, properties: array<string, array<string, mixed>>, required: list<string>}
     */
    public static function for(array $rules): array
    {
        $properties = [];
        $required = [];

        foreach ($rules as $field => $fieldRules) {
            if (str_contains((string) $field, '.') || str_contains((string) $field, '*')) {
                continue;
            }

            $list = self::normalize($fieldRules);
            $schema = self::property($list);

            if (in_array('required', $list, true)) {
                $required[] = (string) $field;
            }

            $properties[(string) $field] = $schema;
        }

        return ['type' => 'object', 'properties' => $properties, 'required' => $required];
    }

    /**
     * @return list<string>
     */
    private static function normalize(mixed $fieldRules): array
    {
        $items = is_string($fieldRules) ? explode('|', $fieldRules) : (is_array($fieldRules) ? $fieldRules : [$fieldRules]);
        $list = [];

        foreach ($items as $item) {
            if (is_string($item)) {
                $list[] = $item;
            } elseif ($item instanceof \BackedEnum) {
                $list[] = 'in:'.$item->value;
            } elseif (is_object($item) && ! $item instanceof Rule && method_exists($item, '__toString')) {
                $list[] = (string) $item;
            }
        }

        return $list;
    }

    /**
     * @param  list<string>  $rules
     * @return array<string, mixed>
     */
    private static function property(array $rules): array
    {
        $type = 'string';
        $schema = [];

        foreach ($rules as $rule) {
            [$name, $argument] = array_pad(explode(':', $rule, 2), 2, null);

            switch ($name) {
                case 'integer':
                    $type = 'integer';
                    break;
                case 'numeric':
                    $type = 'number';
                    break;
                case 'boolean':
                    $type = 'boolean';
                    break;
                case 'array':
                    $type = 'array';
                    break;
                case 'email':
                    $schema['format'] = 'email';
                    break;
                case 'date':
                    $schema['format'] = 'date-time';
                    break;
                case 'in':
                    $schema['enum'] = explode(',', (string) $argument);
                    break;
                case 'max':
                    $schema[in_array('string', $rules, true) ? 'maxLength' : 'maximum'] = (int) $argument;
                    break;
                case 'min':
                    $schema[in_array('string', $rules, true) ? 'minLength' : 'minimum'] = (int) $argument;
                    break;
            }
        }

        return ['type' => $type] + $schema;
    }
}
