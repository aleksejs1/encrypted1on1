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
        if ($this->hasTitle() && (!\is_string($this->title) || '' === trim($this->title) || \mb_strlen($this->title) > 255)) {
            $context->buildViolation('Title must be a non-empty string up to 255 characters.')
                ->atPath('title')
                ->addViolation();
        }
    }

    #[Assert\Callback]
    public function validateDescription(ExecutionContextInterface $context): void
    {
        if ($this->hasDescription() && null !== $this->description && !\is_string($this->description)) {
            $context->buildViolation('Description must be a string or null.')
                ->atPath('description')
                ->addViolation();
        }
    }

    #[Assert\Callback]
    public function validateTargetDate(ExecutionContextInterface $context): void
    {
        if (!$this->hasTargetDate() || null === $this->targetDate || '' === $this->targetDate) {
            return;
        }

        if (!\is_string($this->targetDate)) {
            $context->buildViolation('Target date must be a string or null.')
                ->atPath('targetDate')
                ->addViolation();

            return;
        }

        try {
            new \DateTimeImmutable($this->targetDate);
        } catch (\Exception) {
            $context->buildViolation('Target date must be a valid date.')
                ->atPath('targetDate')
                ->addViolation();
        }
    }

    #[Assert\Callback]
    public function validateStatus(ExecutionContextInterface $context): void
    {
        if ($this->hasStatus() && (!\is_string($this->status) || !\in_array($this->status, Goal::STATUSES, true))) {
            $context->buildViolation('Status must be one of: '.implode(', ', Goal::STATUSES).'.')
                ->atPath('status')
                ->addViolation();
        }
    }
}
