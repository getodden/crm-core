<?php

declare(strict_types=1);

namespace Odden\Core\Registry;

/**
 * What an action returned. It is plain data and a message, safe to hand back to a model: nothing in it is an instruction, and
 * `data` holds only what the action chose to return.
 */
final readonly class ActionResult
{
    public const string COMPLETED = 'completed';

    /** Held for a person to approve; nothing has run yet. */
    public const string PROPOSED = 'proposed';

    public const string FAILED = 'failed';

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array<string>>  $errors  Validation messages by input field.
     */
    public function __construct(
        public string $status,
        public array $data = [],
        public ?string $message = null,
        public array $errors = [],
        public int|string|null $proposalId = null,
        public ?string $subjectType = null,
        public int|string|null $subjectId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function completed(array $data = [], ?string $message = null, ?string $subjectType = null, int|string|null $subjectId = null): self
    {
        return new self(self::COMPLETED, $data, $message, subjectType: $subjectType, subjectId: $subjectId);
    }

    public static function proposed(int|string $proposalId, string $message): self
    {
        return new self(self::PROPOSED, message: $message, proposalId: $proposalId);
    }

    /**
     * @param  array<string, array<string>>  $errors
     */
    public static function failed(string $message, array $errors = []): self
    {
        return new self(self::FAILED, message: $message, errors: $errors);
    }

    public function ok(): bool
    {
        return $this->status === self::COMPLETED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'message' => $this->message,
            'data' => $this->data === [] ? null : $this->data,
            'errors' => $this->errors === [] ? null : $this->errors,
            'proposal_id' => $this->proposalId,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
