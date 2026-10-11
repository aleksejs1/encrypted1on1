<?php

namespace App\Org;

use App\Entity\User;

/** What an import would do: OrgStructureImport::plan()'s answer. Nothing is applied yet. */
final readonly class OrgImportPlan
{
    /**
     * @param list<array{User, ?User}>                         $changes   each a person and their new manager, null for none
     * @param int                                              $unchanged people whose row repeats what is stored
     * @param list<array{row: int, problem: OrgImportProblem}> $problems  by the row's index in the request, in order
     */
    public function __construct(
        public int $rows,
        public array $changes,
        public int $unchanged,
        public array $problems,
    ) {
    }

    /** With a blocking problem nothing may be applied, the valid rows included. */
    public function isBlocked(): bool
    {
        foreach ($this->problems as ['problem' => $problem]) {
            if ('blocking' === $problem->severity()) {
                return true;
            }
        }

        return false;
    }

    /**
     * `rows` and the three problem counts are rows of the request; `changes` and
     * `unchanged` are people, since a row given twice is one assignment. They only add
     * up to `rows` for a request that names nobody twice.
     *
     * @return array{rows: int, changes: int, unchanged: int, warnings: int, errors: int, blocking: int}
     */
    public function counts(): array
    {
        $bySeverity = ['warning' => 0, 'error' => 0, 'blocking' => 0];
        foreach ($this->problems as ['problem' => $problem]) {
            ++$bySeverity[$problem->severity()];
        }

        return [
            'rows' => $this->rows,
            'changes' => \count($this->changes),
            'unchanged' => $this->unchanged,
            'warnings' => $bySeverity['warning'],
            'errors' => $bySeverity['error'],
            'blocking' => $bySeverity['blocking'],
        ];
    }
}
