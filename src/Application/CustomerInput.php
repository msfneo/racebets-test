<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\CountryCode;
use App\Domain\Exception\ValidationException;
use App\Domain\Gender;

/**
 * Validates and normalises the customer payload, collecting every problem
 * rather than failing on the first. Output is keyed by database column.
 */
final class CustomerInput
{
    private const FIELDS = ['gender', 'first_name', 'last_name', 'country', 'email'];

    private const MAX_NAME_LENGTH = 100;

    /** Matches the VARCHAR(190) column, sized for a unique index. */
    private const MAX_EMAIL_LENGTH = 190;

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{gender: string, first_name: string, last_name: string, country: string, email: string}
     *
     * @throws ValidationException
     */
    public static function forCreate(array $payload): array
    {
        $errors = self::rejectUnknownFields($payload);
        $values = [];

        foreach (self::FIELDS as $field) {
            if (!\array_key_exists($field, $payload) || $payload[$field] === null) {
                $errors[$field][] = 'This field is required.';

                continue;
            }

            self::validateField($field, $payload[$field], $values, $errors);
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        /** @var array{gender: string, first_name: string, last_name: string, country: string, email: string} $values */
        return $values;
    }

    /**
     * Any subset of the registration fields, at least one. bonus_percent is not
     * accepted: it would let a client rewrite their own bonus rate.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, string> only the fields actually present
     *
     * @throws ValidationException
     */
    public static function forUpdate(array $payload): array
    {
        $errors = self::rejectUnknownFields($payload);
        $values = [];

        foreach (self::FIELDS as $field) {
            if (!\array_key_exists($field, $payload)) {
                continue;
            }

            if ($payload[$field] === null) {
                $errors[$field][] = 'This field cannot be null.';

                continue;
            }

            self::validateField($field, $payload[$field], $values, $errors);
        }

        if ($values === [] && $errors === []) {
            $errors['_'][] = \sprintf(
                'Provide at least one field to update (%s).',
                \implode(', ', self::FIELDS),
            );
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $values;
    }

    /**
     * @param array<string, string>      $values
     * @param array<string, list<string>> $errors
     *
     * @param-out array<string, string>       $values
     * @param-out array<string, list<string>> $errors
     */
    private static function validateField(string $field, mixed $raw, array &$values, array &$errors): void
    {
        if (!\is_string($raw)) {
            $errors[$field][] = 'This field must be a string.';

            return;
        }

        $value = \trim($raw);

        match ($field) {
            'gender' => self::validateGender($value, $values, $errors),
            'first_name', 'last_name' => self::validateName($field, $value, $values, $errors),
            'country' => self::validateCountry($value, $values, $errors),
            'email' => self::validateEmail($value, $values, $errors),
            default => null,
        };
    }

    /**
     * @param array<string, string>       $values
     * @param array<string, list<string>> $errors
     */
    private static function validateGender(string $value, array &$values, array &$errors): void
    {
        $gender = Gender::tryFrom(\strtolower($value));

        if ($gender === null) {
            $errors['gender'][] = \sprintf('Must be one of: %s.', \implode(', ', Gender::values()));

            return;
        }

        $values['gender'] = $gender->value;
    }

    /**
     * @param array<string, string>       $values
     * @param array<string, list<string>> $errors
     */
    private static function validateName(string $field, string $value, array &$values, array &$errors): void
    {
        if ($value === '') {
            $errors[$field][] = 'This field cannot be empty.';

            return;
        }

        if (\mb_strlen($value) > self::MAX_NAME_LENGTH) {
            $errors[$field][] = \sprintf('Must be at most %d characters.', self::MAX_NAME_LENGTH);

            return;
        }

        $values[$field] = $value;
    }

    /**
     * @param array<string, string>       $values
     * @param array<string, list<string>> $errors
     */
    private static function validateCountry(string $value, array &$values, array &$errors): void
    {
        $country = CountryCode::normalise($value);

        if (!CountryCode::isValid($country)) {
            $errors['country'][] = 'Must be a valid ISO 3166-1 alpha-2 country code, e.g. "MT" or "DE".';

            return;
        }

        $values['country'] = $country;
    }

    /**
     * @param array<string, string>       $values
     * @param array<string, list<string>> $errors
     */
    private static function validateEmail(string $value, array &$values, array &$errors): void
    {
        // Lower cased so uniqueness does not depend on the server collation.
        $email = \mb_strtolower($value);

        if (\filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'][] = 'Must be a valid email address.';

            return;
        }

        if (\strlen($email) > self::MAX_EMAIL_LENGTH) {
            $errors['email'][] = \sprintf('Must be at most %d characters.', self::MAX_EMAIL_LENGTH);

            return;
        }

        $values['email'] = $email;
    }

    /**
     * Unknown keys are an error: dropping a misspelled "firstname" would look
     * like a successful update that did nothing.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, list<string>>
     */
    private static function rejectUnknownFields(array $payload): array
    {
        $unknown = \array_diff(\array_keys($payload), self::FIELDS);

        if ($unknown === []) {
            return [];
        }

        return ['_' => [\sprintf(
            'Unknown field(s): %s. Accepted fields: %s.',
            \implode(', ', $unknown),
            \implode(', ', self::FIELDS),
        )]];
    }
}
