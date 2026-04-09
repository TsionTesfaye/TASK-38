<?php

declare(strict_types=1);

namespace App\Validation;

use App\Exception\InvalidEnumException;

class EnumValidator
{
    /**
     * Validate and convert a string to a backed enum value.
     *
     * @template T of \BackedEnum
     * @param string $value The raw input value
     * @param class-string<T> $enumClass The enum class to validate against
     * @param string $field The field name for the error response
     * @return T The validated enum instance
     * @throws InvalidEnumException if the value is not a valid case
     */
    public static function validate(string $value, string $enumClass, string $field): \BackedEnum
    {
        $result = $enumClass::tryFrom($value);
        if ($result === null) {
            $allowed = array_map(fn (\BackedEnum $c) => $c->value, $enumClass::cases());
            throw new InvalidEnumException($field, $allowed, $value);
        }
        return $result;
    }
}
