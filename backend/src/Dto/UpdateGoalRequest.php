<?php

namespace App\Dto;

use App\Entity\Goal;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

readonly class UpdateGoalRequest
{
    public const UNSET = '__NOT_SET__';

    public function __construct(
        public mixed $title = self::UNSET,
        public mixed $description = self::UNSET,
        public mixed $targetDate = self::UNSET,
        public mixed $status = self::UNSET,
    ) {
    }

    public function hasTitle(): bool
    {
        return self::UNSET !== $this->title;
    }

    public function hasDescription(): bool
    {
        return self::UNSET !== $this->description;
    }

    public function hasTargetDate(): bool
    {
        return self::UNSET !== $this->targetDate;
    }

    public function hasStatus(): bool
    {
        return self::UNSET !== $this->status;
    }

    #[Assert\Callback]
    public function validateTitle(ExecutionContextInterface $context): void
    {
        if (!$this->hasTitle()) {
            return;
        }

        if (!\is_string($this->title) || '' === trim($this->title)) {
            DtoViolation::add($context, 'title', 'errors.title_must_be_non_empty');

            return;
        }

        if (\mb_strlen($this->title) > 255) {
            DtoViolation::add($context, 'title', 'errors.title_too_long', ['%max%' => '255']);
        }
    }

    #[Assert\Callback]
    public function validateDescription(ExecutionContextInterface $context): void
    {
        if ($this->hasDescription() && null !== $this->description && !\is_string($this->description)) {
            DtoViolation::add($context, 'description', 'errors.description_must_be_string');
        }
    }

    #[Assert\Callback]
    public function validateTargetDate(ExecutionContextInterface $context): void
    {
        if (!$this->hasTargetDate() || null === $this->targetDate || '' === $this->targetDate) {
            return;
        }

        if (!\is_string($this->targetDate)) {
            DtoViolation::add($context, 'targetDate', 'errors.target_date_must_be_string_or_null');

            return;
        }

        try {
            new \DateTimeImmutable($this->targetDate);
        } catch (\Exception) {
            DtoViolation::add($context, 'targetDate', 'errors.target_date_must_be_valid_date');
        }
    }

    #[Assert\Callback]
    public function validateStatus(ExecutionContextInterface $context): void
    {
        if ($this->hasStatus() && (!\is_string($this->status) || !\in_array($this->status, Goal::STATUSES, true))) {
            DtoViolation::add($context, 'status', 'errors.status_must_be_one_of', ['%statuses%' => implode(', ', Goal::STATUSES)]);
        }
    }
}
