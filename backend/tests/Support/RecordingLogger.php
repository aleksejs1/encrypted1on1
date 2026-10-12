<?php

namespace App\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * Keeps what was logged, with `{placeholders}` filled in the way Symfony's own
 * fallback logger prints them, so a test can assert on the line an operator would read.
 */
final class RecordingLogger extends AbstractLogger
{
    private const array LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /** @var list<array{level: string, line: string, context: mixed[]}> */
    private array $records = [];

    /**
     * @param mixed[] $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            if (\is_scalar($value) || $value instanceof \Stringable) {
                $replacements['{'.$key.'}'] = (string) $value;
            }
        }
        $this->records[] = ['level' => \is_string($level) ? $level : '', 'line' => strtr((string) $message, $replacements), 'context' => $context];
    }

    /**
     * @return list<string> the lines logged at $level or above, oldest first
     */
    public function atLeast(string $level): array
    {
        return array_column($this->recordsAtLeast($level), 'line');
    }

    /**
     * @return list<mixed[]> the context of each line atLeast() returns, in the same order
     */
    public function contextsAtLeast(string $level): array
    {
        return array_column($this->recordsAtLeast($level), 'context');
    }

    /**
     * @return list<array{level: string, line: string, context: mixed[]}>
     */
    private function recordsAtLeast(string $level): array
    {
        $min = array_search($level, self::LEVELS, true);
        if (false === $min) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a log level.', $level));
        }

        return array_values(array_filter($this->records, static function (array $record) use ($min): bool {
            $index = array_search($record['level'], self::LEVELS, true);

            // A record whose level isn't one of LEVELS is kept: better seen than lost.
            return false === $index || $index >= $min;
        }));
    }
}
