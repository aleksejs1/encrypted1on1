<?php

namespace App\Dto;

use App\Org\OrgStructureImport;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * POST /api/admin/org-structure/import (GitHub issue #271). The rows are checked by
 * hand: an array of objects doesn't map onto typed constructor arguments.
 */
readonly class ImportOrgStructureRequest
{
    /** Longer than any real address (254 characters); bounds the work a row can cost. */
    private const MAX_EMAIL_BYTES = 320;

    public function __construct(
        public mixed $dryRun = null,
        public mixed $assignments = null,
    ) {
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        // Required and a real boolean: a missing or misspelled dryRun must never be
        // read as "apply".
        if (!\is_bool($this->dryRun)) {
            DtoViolation::add($context, 'dryRun', 'errors.missing_or_invalid_field', ['%field%' => 'dryRun']);
        }
        if (!\is_array($this->assignments) || !array_is_list($this->assignments)) {
            DtoViolation::add($context, 'assignments', 'errors.missing_or_invalid_field', ['%field%' => 'assignments']);

            return;
        }
        if (\count($this->assignments) > OrgStructureImport::MAX_ROWS) {
            DtoViolation::add($context, 'assignments', 'errors.org_import_too_many_rows', ['%max%' => (string) OrgStructureImport::MAX_ROWS]);

            return;
        }
        foreach ($this->assignments as $index => $row) {
            if (null === self::row($row)) {
                // The first bad row is enough: a file broken in one place is usually broken in all.
                DtoViolation::add($context, "assignments[{$index}]", 'errors.missing_or_invalid_field', ['%field%' => "assignments[{$index}]"]);

                return;
            }
        }
    }

    /**
     * Only after validation passed.
     *
     * @return list<array{employeeEmail: string, managerEmail: ?string}>
     */
    public function rows(): array
    {
        \assert(\is_array($this->assignments));
        $rows = [];
        foreach ($this->assignments as $row) {
            $valid = self::row($row);
            \assert(null !== $valid);
            $rows[] = $valid;
        }

        return $rows;
    }

    /**
     * A row as the import takes it, or null if it isn't one: employeeEmail a non-blank
     * string, managerEmail a string or null. The key itself is required: a misspelled
     * or unmapped manager column must not read as "clear everyone's manager".
     *
     * @return array{employeeEmail: string, managerEmail: ?string}|null
     */
    private static function row(mixed $row): ?array
    {
        if (!\is_array($row) || !\array_key_exists('managerEmail', $row)) {
            return null;
        }
        $employee = $row['employeeEmail'] ?? null;
        $manager = $row['managerEmail'];
        if (!self::isAddress($employee) || '' === trim($employee) || !(null === $manager || self::isAddress($manager))) {
            return null;
        }

        return ['employeeEmail' => $employee, 'managerEmail' => $manager];
    }

    /** @phpstan-assert-if-true string $value */
    private static function isAddress(mixed $value): bool
    {
        return \is_string($value) && \strlen($value) <= self::MAX_EMAIL_BYTES;
    }
}
