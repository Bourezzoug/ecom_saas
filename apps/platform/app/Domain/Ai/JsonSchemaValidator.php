<?php

namespace App\Domain\Ai;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;

/**
 * Validates PHP arrays against JSON Schema (draft 2020-12) and returns
 * human-readable errors such as "/items/0/question: Minimum string length is 5".
 * The messages are sent back to the model on the repair attempt, so they stay short.
 */
class JsonSchemaValidator
{
    private Validator $validator;

    public function __construct(int $maxErrors = 12)
    {
        $this->validator = new Validator;
        $this->validator->setMaxErrors($maxErrors);
        $this->validator->setStopAtFirstError(false);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string> Empty when valid.
     */
    public function errors(mixed $data, array $schema): array
    {
        $result = $this->validator->validate(
            is_object($data) ? $data : Helper::toJSON($data),
            (string) json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        if ($result->isValid()) {
            return [];
        }

        $errors = [];

        foreach ((new ErrorFormatter)->format($result->error(), true) as $path => $messages) {
            foreach ((array) $messages as $message) {
                $errors[] = ($path === '' ? '/' : $path).': '.$message;
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    public function passes(mixed $data, array $schema): bool
    {
        return $this->errors($data, $schema) === [];
    }
}
