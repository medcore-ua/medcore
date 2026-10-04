<?php

/*
 *
 *                      _
 *   _ __ ___   ___  __| | ___ ___  _ __ ___       _   _  __ _
 *  | '_ ` _ \ / _ \/ _` |/ __/ _ \| '__/ _ \_____| | | |/ _` |
 *  | | | | | |  __/ (_| | (_| (_) | | |  __/_____| |_| | (_| |
 *  |_| |_| |_|\___|\__,_|\___\___/|_|  \___|      \__,_|\__,_|
 *
 * This program is free software: you can redistribute and/or modify
 * it under the terms of the CSSM Unlimited License v2.0.
 *
 * This license permits unlimited use, modification, and distribution
 * for any purpose while maintaining authorship attribution.
 *
 * The software is provided "as is" without warranty of any kind.
 *
 * @author MedCore Ukraine
 * @link https://medcore.pp.ua/
 *
 *
 */

namespace App\Infrastructure\Validation;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class SymfonyValidator
{
    private ValidatorInterface $validator;
    private ManagerRegistry $registry;
    private array $errors = [];

    public function __construct(ManagerRegistry $registry)
    {
        $this->registry = $registry;
        $this->validator = Validation::createValidator();
    }

    /**
     * Validate data against rules using Symfony Validator
     *
     * @param  array $data  Data to validate
     * @param  array $rules Validation rules in the format ['field' => ['rule1', 'rule2:param']]
     * @return bool  True if validation passes, false otherwise
     */
    public function validate(array $data, array $rules) : bool
    {
        $this->errors = [];

        $constraints = [];
        $uniqueRules = [];

        foreach ($rules as $field => $fieldRules) {
            $fieldConstraints = [];
            foreach ($fieldRules as $rule) {
                if (str_starts_with($rule, 'unique:')) {
                    $uniqueRules[$field] = $rule;
                } else {
                    $constraint = $this->parseRule($rule, $field);
                    if (null !== $constraint) {
                        $fieldConstraints[] = $constraint;
                    }
                }
            }
            if (! empty($fieldConstraints)) {
                $constraints[$field] = $fieldConstraints;
            }
        }

        // Create a simple data object for validation
        $dataObject = new class ($data) {
            public function __construct(private array $data)
            {
            }

            public function __get(string $name)
            {
                return $this->data[$name] ?? null;
            }
        };

        // Validate non-unique rules with Symfony Validator
        foreach ($constraints as $field => $fieldConstraints) {
            $value = $data[$field] ?? null;
            $violations = $this->validator->validate($value, $fieldConstraints);

            if (count($violations) > 0) {
                $this->errors[$field] = [];
                foreach ($violations as $violation) {
                    $this->errors[$field][] = $violation->getMessage();
                }
            }
        }

        // Validate unique rules manually
        foreach ($uniqueRules as $field => $rule) {
            $this->validateUniqueRule($data[$field] ?? null, $rule, $field);
        }

        return empty($this->errors);
    }

    private function validateUniqueRule(?string $value, string $rule, string $field) : void
    {
        if (null === $value || '' === $value) {
            return;
        }

        $params = explode(',', substr($rule, 7));
        $table = $params[0];
        $column = $params[1] ?? $field;
        $ignoreId = $params[2] ?? null;

        try {
            $conn = $this->registry->getConnection();
            $sql = "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = :value";
            $queryParams = ['value' => $value];

            if (null !== $ignoreId) {
                $sql .= " AND `id` != :ignore_id";
                $queryParams['ignore_id'] = $ignoreId;
            }

            $result = $conn->executeQuery($sql, $queryParams);

            if ($result->fetchOne() > 0) {
                $this->errors[$field][] = "Значення поля '{$field}' вже існує.";
            }
        } catch (\Exception $e) {
            // In testing environment, re-throw the exception for proper testing
            throw $e;
        }
    }

    private function parseRule(string $rule, string $field) : ?object
    {
        if ('required' === $rule) {
            return new Assert\NotBlank(['message' => "Поле '{$field}' обов'язкове для заповнення."]);
        }

        if ('email' === $rule) {
            return new Assert\Email(['message' => "Поле '{$field}' повинно містити дійсну електронну адресу."]);
        }

        if (str_starts_with($rule, 'min:')) {
            $minLength = (int)substr($rule, 4);
            return new Assert\Length([
                'min' => $minLength,
                'minMessage' => "Поле '{$field}' повинно містити не менше {$minLength} символів."
            ]);
        }

        if (str_starts_with($rule, 'max:')) {
            $maxLength = (int)substr($rule, 4);
            return new Assert\Length([
                'max' => $maxLength,
                'maxMessage' => "Поле '{$field}' повинно містити не більше {$maxLength} символів."
            ]);
        }

        if ('date' === $rule) {
            return new Assert\Date(['message' => "Поле '{$field}' повинно містити дійсну дату."]);
        }

        if ('datetime' === $rule) {
            return new Assert\Callback(function ($value, $context) use ($field) {
                if (empty($value)) {
                    return;
                }
                $dateTime = \DateTime::createFromFormat('Y-m-d H:i:s', $value);
                $dateTimeShort = \DateTime::createFromFormat('Y-m-d H:i', $value);
                if (! $dateTime && ! $dateTimeShort) {
                    $context->buildViolation("Поле '{$field}' повинно містити дійсні дату та час.")
                        ->addViolation();
                }
            });
        }

        if (str_starts_with($rule, 'in:')) {
            $options = explode(',', substr($rule, 3));
            return new Assert\Choice([
                'choices' => $options,
                'message' => "Поле '{$field}' повинно мати одне зі значень: " . implode(', ', $options) . "."
            ]);
        }

        if ('numeric' === $rule) {
            return new Assert\Regex([
                'pattern' => '/^[0-9]+(\.[0-9]+)?$/',
                'message' => "Поле '{$field}' повинно бути числом."
            ]);
        }

        if (str_starts_with($rule, 'min_value:')) {
            $minValue = (float)explode(':', $rule)[1];
            return new Assert\GreaterThanOrEqual([
                'value' => $minValue,
                'message' => "Поле '{$field}' повинно бути не менше {$minValue}."
            ]);
        }

        if (str_starts_with($rule, 'max_value:')) {
            $maxValue = (float)explode(':', $rule)[1];
            return new Assert\LessThanOrEqual([
                'value' => $maxValue,
                'message' => "Поле '{$field}' повинно бути не більше {$maxValue}."
            ]);
        }

        if ('array' === $rule) {
            return new Assert\Type(
                type: 'array',
                message: "Поле '{$field}' повинно бути масивом."
            );
        }

        if (str_starts_with($rule, 'unique:')) {
            // Unique validation is handled separately
            return null;
        }

        return null;
    }

    public function addError(string $field, string $message) : void
    {
        $this->errors[$field][] = $message;
    }

    public function hasErrors() : bool
    {
        return ! empty($this->errors);
    }

    public function getErrors() : array
    {
        return $this->errors;
    }
}
