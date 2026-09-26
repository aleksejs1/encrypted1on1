<?php

namespace App\Template;

use App\Http\DisplayNameField;

/**
 * Validates a company template's definition (GitHub issue #141, part C1 of the custom
 * templates design in #133 §5.1): an ordered list of question blocks per side, each a
 * built-in question reused as-is or a custom question with one field and literal text.
 *
 * Mirrors `frontend/src/anketa/templateDefinition.ts`'s validateTemplateDefinition()
 * rule for rule, error for error: both return the same `{path, code}` list for the same
 * input, which the shared cases in tests/Fixtures/template-definitions.json check from
 * both test suites. A change to one must be made to the other.
 *
 * Takes the definition as json_decode($json) gives it *without* the associative flag:
 * JSON objects as \stdClass, lists as PHP arrays. Associative decoding can't tell `{}`
 * from `[]`, and turns `{"0": …}` into a list, which would make this validator accept
 * shapes the frontend rejects. A body json_decode() can't decode at all (invalid UTF-8,
 * a lone surrogate, an invalid property name, nesting past its depth limit) is the
 * caller's own error; validating the resulting null would report something the frontend
 * never does.
 *
 * One known difference: a `schemaVersion` written as any non-integer literal that JS
 * parses to the number 1 (`1.0`, `1e0`, even `1.0000000000000000001`) decodes to a
 * float here and is rejected, while JS accepts it. The frontend always sends `1`
 * (JSON.stringify writes integers that way), so only a hand-made request can hit it.
 *
 * The backend doesn't know the field ids inside built-in
 * blocks, and doesn't need to: custom ids have their own `c_` format, a built-in block
 * appears at most once, and the two sides' allowlists are disjoint.
 */
final class TemplateDefinitionValidator
{
    /**
     * The built-in question blocks a template may reuse, per side. Append-only: removing
     * an id would make already-saved template versions invalid. Must match
     * `{EMPLOYEE,MANAGER}_BUILTIN_QUESTION_IDS` in frontend/src/anketa/questions.ts,
     * which templateDefinition.test.ts cross-checks by reading this file.
     */
    public const EMPLOYEE_BUILTIN_QUESTION_IDS = ['mood', 'feelings', 'workload', 'growth', 'friction', 'achievements', 'discuss'];
    public const MANAGER_BUILTIN_QUESTION_IDS = ['periodSummary', 'feedback', 'support', 'employeeAchievements', 'managerDiscuss'];

    /** The canonical JSON encoding's size cap, in UTF-8 bytes. */
    public const MAX_DEFINITION_BYTES = 65536;
    public const MAX_BLOCKS_PER_SIDE = 30;
    public const MAX_TITLE_LENGTH = 200;
    public const MAX_FIELD_LABEL_LENGTH = 300;
    public const MAX_OPTION_LABEL_LENGTH = 100;
    public const MIN_OPTIONS = 2;
    public const MAX_OPTIONS = 12;

    // \z, not $: in PCRE, $ also matches before a trailing newline.
    private const ID_PATTERN = '/^c_[a-z0-9]{10}\z/';
    private const OPTION_VALUE_PATTERN = '/^o_[a-z0-9]{8}\z/';
    private const FIELD_TYPES = ['text', 'list', 'radio', 'checkboxes'];
    private const TYPES_WITH_OPTIONS = ['radio', 'checkboxes'];
    private const SIDES = ['employee', 'manager'];

    /** Only ASCII whitespace, the same explicit set as the frontend: space, \t, \n, \r, \v, \f. */
    private const ASCII_WHITESPACE = " \t\n\r\x0B\x0C";

    /**
     * Rejected in template text, on top of DisplayNameField::STRIP_PATTERN's C0/C1
     * controls and zero-width/bidi characters: the Unicode line and paragraph
     * separators, since every field is single-line. Rejected rather than stripped, so
     * both sides stay identical. Invalid UTF-8 (the frontend's lone surrogates) fails
     * either match and is rejected the same way.
     */
    private const LINE_SEPARATORS = '/[\x{2028}\x{2029}]/u';

    private const JSON_FLAGS = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_LINE_TERMINATORS | \JSON_THROW_ON_ERROR;

    /** @var list<array{path: string, code: string}> */
    private array $errors = [];

    /** @var array<string, true> */
    private array $seenIds = [];

    /** @var array<string, true> */
    private array $seenBuiltins = [];

    /**
     * Every violation of #133 §5.1, in the same fixed traversal order as the frontend.
     * Empty means valid. Strings are checked as they'll be stored, trimmed; the byte cap
     * applies to canonicalJson() and is checked only once everything else passes.
     *
     * @return list<array{path: string, code: string}>
     */
    public function validate(mixed $definition): array
    {
        try {
            $this->checkDefinition($definition);

            return $this->errors;
        } finally {
            // Nothing from this input outlives the call, even on an exception: the
            // service stays alive across requests in worker mode.
            $this->errors = [];
            $this->seenIds = [];
            $this->seenBuiltins = [];
        }
    }

    /**
     * The canonical encoding of a definition that passed validate(), with every text
     * trimmed: what gets stored, and what the byte cap measures. Same bytes as the
     * frontend's JSON.stringify(): unescaped Unicode, slashes and U+2028/U+2029.
     * Call it only after validate() passes: a text that isn't valid UTF-8 can't be
     * encoded at all.
     *
     * @throws \JsonException on a definition that validate() would reject
     */
    public function canonicalJson(\stdClass $definition): string
    {
        return json_encode(self::trimTexts($definition), self::JSON_FLAGS);
    }

    /**
     * A copy of $node with every `title` and `label` string trimmed. In a valid
     * definition those are exactly the texts: a custom block's title, its field's label
     * and its options' labels. Properties keep their order, so the byte count matches
     * the frontend's trimTemplateDefinition().
     */
    private static function trimTexts(mixed $node): mixed
    {
        if (\is_array($node)) {
            return array_map(self::trimTexts(...), $node);
        }
        if (!$node instanceof \stdClass) {
            return $node;
        }
        $copy = new \stdClass();
        foreach (get_object_vars($node) as $key => $value) {
            $copy->{$key} = \is_string($value) && \in_array($key, ['title', 'label'], true)
                ? trim($value, self::ASCII_WHITESPACE)
                : self::trimTexts($value);
        }

        return $copy;
    }

    private function checkDefinition(mixed $definition): void
    {
        if (!$this->checkObject($definition, '', ['schemaVersion', 'employee', 'manager'])) {
            return;
        }
        if (1 !== $definition->schemaVersion) {
            $this->fail('schemaVersion', 'schema_version');
        }
        foreach (self::SIDES as $side) {
            $this->checkSide($definition->{$side}, $side);
        }

        if ([] === $this->errors && \strlen($this->canonicalJson($definition)) > self::MAX_DEFINITION_BYTES) {
            $this->fail('', 'too_large');
        }
    }

    private function fail(string $path, string $code): void
    {
        $this->errors[] = ['path' => $path, 'code' => $code];
    }

    /**
     * A JSON object, then no unknown keys, then no missing ones; one error at most.
     *
     * @param list<string> $required
     * @param list<string> $optional
     *
     * @phpstan-assert-if-true =\stdClass $value
     */
    private function checkObject(mixed $value, string $path, array $required, array $optional = []): bool
    {
        if (!$value instanceof \stdClass) {
            $this->fail($path, 'type');

            return false;
        }
        $keys = array_map('strval', array_keys(get_object_vars($value)));
        if ([] !== array_diff($keys, [...$required, ...$optional])) {
            $this->fail($path, 'unknown_key');

            return false;
        }
        if ([] !== array_diff($required, $keys)) {
            $this->fail($path, 'missing_key');

            return false;
        }

        return true;
    }

    private function checkText(mixed $value, string $path, int $max): void
    {
        if (!\is_string($value)) {
            $this->fail($path, 'type');

            return;
        }
        $trimmed = trim($value, self::ASCII_WHITESPACE);
        // 1 is a rejected character; false is invalid UTF-8.
        if (0 !== preg_match(DisplayNameField::STRIP_PATTERN, $trimmed) || 0 !== preg_match(self::LINE_SEPARATORS, $trimmed)) {
            $this->fail($path, 'text_chars');

            return;
        }
        $length = mb_strlen($trimmed, 'UTF-8');
        if ($length < 1 || $length > $max) {
            $this->fail($path, 'text_length');
        }
    }

    private function checkId(mixed $value, string $path): void
    {
        if (!\is_string($value) || 1 !== preg_match(self::ID_PATTERN, $value)) {
            $this->fail($path, 'id_format');
        } elseif (isset($this->seenIds[$value])) {
            $this->fail($path, 'id_duplicate');
        } else {
            $this->seenIds[$value] = true;
        }
    }

    private function checkSide(mixed $blocks, string $side): void
    {
        // A non-associative json_decode() only makes lists; array_is_list() keeps a
        // caller's re-keyed array (array_filter()) from being stored as a JSON object.
        if (!\is_array($blocks) || !array_is_list($blocks)) {
            $this->fail($side, 'type');

            return;
        }
        if ([] === $blocks || \count($blocks) > self::MAX_BLOCKS_PER_SIDE) {
            $this->fail($side, 'block_count');

            return;
        }
        foreach ($blocks as $index => $block) {
            $this->checkBlock($block, $side.'/'.$index, $side);
        }
    }

    private function checkBlock(mixed $value, string $path, string $side): void
    {
        if (!$value instanceof \stdClass) {
            $this->fail($path, 'type');

            return;
        }
        $kind = $value->kind ?? null;
        if ('builtin' === $kind) {
            $this->checkBuiltinBlock($value, $path, $side);
        } elseif ('custom' === $kind) {
            if (!$this->checkObject($value, $path, ['kind', 'id', 'title', 'field'])) {
                return;
            }
            $this->checkId($value->id, $path.'/id');
            $this->checkText($value->title, $path.'/title', self::MAX_TITLE_LENGTH);
            $this->checkField($value->field, $path.'/field');
        } else {
            $this->fail($path.'/kind', 'kind');
        }
    }

    private function checkBuiltinBlock(\stdClass $value, string $path, string $side): void
    {
        if (!$this->checkObject($value, $path, ['kind', 'questionId'])) {
            return;
        }
        $allowlist = 'employee' === $side ? self::EMPLOYEE_BUILTIN_QUESTION_IDS : self::MANAGER_BUILTIN_QUESTION_IDS;
        $questionId = $value->questionId;
        if (!\is_string($questionId) || !\in_array($questionId, $allowlist, true)) {
            $this->fail($path.'/questionId', 'builtin_not_allowed');
        } elseif (isset($this->seenBuiltins[$questionId])) {
            $this->fail($path.'/questionId', 'builtin_repeated');
        } else {
            $this->seenBuiltins[$questionId] = true;
        }
    }

    private function checkField(mixed $value, string $path): void
    {
        if (!$this->checkObject($value, $path, ['id', 'type'], ['label', 'options'])) {
            return;
        }
        $this->checkId($value->id, $path.'/id');
        $type = $value->type;
        $validType = \is_string($type) && \in_array($type, self::FIELD_TYPES, true);
        if (!$validType) {
            $this->fail($path.'/type', 'field_type');
        }
        if (property_exists($value, 'label')) {
            $this->checkText($value->label, $path.'/label', self::MAX_FIELD_LABEL_LENGTH);
        }
        if ($validType) {
            $this->checkFieldOptions($value, $type, $path.'/options');
        }
    }

    /** A radio or checkboxes field needs options; any other type must have none. */
    private function checkFieldOptions(\stdClass $field, string $type, string $path): void
    {
        if (!\in_array($type, self::TYPES_WITH_OPTIONS, true)) {
            if (property_exists($field, 'options')) {
                $this->fail($path, 'options_forbidden');
            }

            return;
        }
        if (property_exists($field, 'options')) {
            $this->checkOptions($field->options, $path);
        } else {
            $this->fail($path, 'options_count');
        }
    }

    private function checkOptions(mixed $value, string $path): void
    {
        if (!\is_array($value) || !array_is_list($value)) {
            $this->fail($path, 'type');

            return;
        }
        if (\count($value) < self::MIN_OPTIONS || \count($value) > self::MAX_OPTIONS) {
            $this->fail($path, 'options_count');

            return;
        }
        $seenValues = [];
        foreach ($value as $index => $option) {
            $optionPath = $path.'/'.$index;
            if (!$this->checkObject($option, $optionPath, ['value', 'label'])) {
                continue;
            }
            $optionValue = $option->value;
            if (!\is_string($optionValue) || 1 !== preg_match(self::OPTION_VALUE_PATTERN, $optionValue)) {
                $this->fail($optionPath.'/value', 'option_value_format');
            } elseif (isset($seenValues[$optionValue])) {
                $this->fail($optionPath.'/value', 'option_value_duplicate');
            } else {
                $seenValues[$optionValue] = true;
            }
            $this->checkText($option->label, $optionPath.'/label', self::MAX_OPTION_LABEL_LENGTH);
        }
    }
}
