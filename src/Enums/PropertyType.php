<?php

declare(strict_types=1);

namespace Odden\Core\Enums;

enum PropertyType: string
{
    case Text = 'text';
    case Number = 'number';
    case Boolean = 'boolean';
    case Select = 'select';
    case MultiSelect = 'multi_select';
    case Date = 'date';
    case DateTime = 'datetime';
    case Json = 'json';

    /**
     * Get a human-readable label for the property type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Number => 'Number',
            self::Boolean => 'Yes or no',
            self::Select => 'Choice',
            self::MultiSelect => 'Several choices',
            self::Date => 'Date',
            self::DateTime => 'Date and Time',
            self::Json => 'Structured data (JSON)',
        };
    }
}
