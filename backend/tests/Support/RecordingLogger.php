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

    /** @var list<array{level: string, line: string}> */
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
        $this->records[] = ['level' => \is_string($level) ? $level : '', 'line' => strtr((string) $message, $replacements)];
    }

    /**
     * @return list<string> the lines logged at $level or above, oldest first
     */
    public function atLeast(string $level): array
    {
        $min = array_search($level, self::LEVELS, true);
        $lines = [];
        foreach ($this->records as $record) {
            if (array_search($record['level'], self::LEVELS, true) >= $min) {
                $lines[] = $record['line'];
            }
        }

        return $lines;
    }
}
