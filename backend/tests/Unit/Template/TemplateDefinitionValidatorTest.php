<?php

namespace App\Tests\Unit\Template;

use App\Template\TemplateDefinitionValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GitHub issue #141. The cases live in tests/Fixtures/template-definitions.json, shared
 * with frontend/src/anketa/templateDefinition.test.ts, so both validators are held to
 * exactly the same errors. How a case builds its definition, including the generated
 * and exact-byte-size ones, is mirrored line for line there.
 */
class TemplateDefinitionValidatorTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../../Fixtures/template-definitions.json';

    /**
     * Decoded without the associative flag, the way the validator takes a definition
     * (see its class docblock): objects as \stdClass.
     */
    private static function fixture(): \stdClass
    {
        $fixture = json_decode((string) file_get_contents(self::FIXTURE), false, 512, \JSON_THROW_ON_ERROR);
        \assert($fixture instanceof \stdClass);

        return $fixture;
    }

    private static function base(): \stdClass
    {
        $base = self::fixture()->base;
        \assert($base instanceof \stdClass);

        return $base;
    }

    /**
     * @return array<string, array{\stdClass}>
     */
    public static function caseProvider(): array
    {
        $cases = self::fixture()->cases;
        \assert(\is_array($cases));
        $provided = [];
        foreach ($cases as $case) {
            \assert($case instanceof \stdClass && \is_string($case->name));
            // A repeated name would silently replace the earlier case here only. Not an
            // assert(): CI's php.ini compiles those out.
            if (isset($provided[$case->name])) {
                throw new \LogicException('Duplicate fixture case name: '.$case->name);
            }
            $provided[$case->name] = [$case];
        }

        return $provided;
    }

    #[DataProvider('caseProvider')]
    public function testSharedCase(\stdClass $case): void
    {
        // The expected errors as associative arrays, the validator's return shape.
        $expected = json_decode((string) json_encode($case->errors), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame($expected, (new TemplateDefinitionValidator())->validate(self::definitionFor($case)));
    }

    public function testASizedDefinitionHasExactlyThatManyBytes(): void
    {
        $validator = new TemplateDefinitionValidator();
        foreach ([65536, 65537, 40000, 40001] as $bytes) {
            $definition = self::sizedDefinition($bytes);
            self::assertSame($bytes, \strlen($validator->canonicalJson($definition)));
        }
    }

    /** JSON can't carry invalid UTF-8, so this is the one case not in the fixture. */
    public function testInvalidUtf8IsRejected(): void
    {
        $definition = self::set(self::base(), 'employee/1/title', "a\xFFb");

        self::assertSame(
            [['path' => 'employee/1/title', 'code' => 'text_chars']],
            (new TemplateDefinitionValidator())->validate($definition),
        );
    }

    public function testCanonicalJsonTrimsEveryTextAndKeepsUnicodeUnescaped(): void
    {
        $definition = self::set(self::base(), 'employee/1/field/options/0/label', " Yes ж\t");
        $definition = self::set($definition, 'employee/1/field/label', "\n Label ж ");

        $validator = new TemplateDefinitionValidator();
        self::assertSame([], $validator->validate($definition));
        $canonical = json_decode($validator->canonicalJson($definition), false, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('How is your week, ž?', self::get($canonical, 'employee/1/title'));
        self::assertSame('Label ж', self::get($canonical, 'employee/1/field/label'));
        self::assertSame('Yes ж', self::get($canonical, 'employee/1/field/options/0/label'));
        self::assertStringContainsString('"title":"How is your week, ž?"', $validator->canonicalJson($definition));
        // A copy: the definition itself is left as it was.
        self::assertSame('  How is your week, ž?  ', self::get($definition, 'employee/1/title'));
    }

    /**
     * The one known difference from the frontend (see the validator's docblock): JS
     * can't tell 1.0 from 1, PHP decodes it to a float and rejects it. Pinned so neither
     * side is "fixed" into a loose comparison, which would let a 1.0 through into the
     * stored canonical JSON.
     */
    public function testSchemaVersionWrittenAsAFloatIsRejected(): void
    {
        foreach (['1.0', '1e0'] as $literal) {
            $json = (string) preg_replace('/"schemaVersion": 1\b/', '"schemaVersion": '.$literal, (string) json_encode(self::base(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
            self::assertStringContainsString('"schemaVersion": '.$literal, $json);

            self::assertSame(
                [['path' => 'schemaVersion', 'code' => 'schema_version']],
                (new TemplateDefinitionValidator())->validate(json_decode($json, false, 512, \JSON_THROW_ON_ERROR)),
            );
        }
    }

    /** Only a caller can make these (json_decode() never does), e.g. with array_filter(). */
    public function testANonListArrayIsNotAList(): void
    {
        $definition = self::base();
        \assert(\is_array($definition->employee));
        $definition->employee = [1 => $definition->employee[1]];

        self::assertSame(
            [['path' => 'employee', 'code' => 'type']],
            (new TemplateDefinitionValidator())->validate($definition),
        );

        $options = self::get(self::base(), 'employee/1/field/options');
        \assert(\is_array($options));
        $definition = self::set(self::base(), 'employee/1/field/options', [1 => $options[0], 2 => $options[1]]);
        self::assertSame(
            [['path' => 'employee/1/field/options', 'code' => 'type']],
            (new TemplateDefinitionValidator())->validate($definition),
        );
    }

    public function testValidatorHoldsNoStateBetweenCalls(): void
    {
        $definition = self::base();
        $validator = new TemplateDefinitionValidator();

        self::assertSame([], $validator->validate($definition));
        // A second call would report every id as a duplicate if the seen-ids set leaked.
        self::assertSame([], $validator->validate($definition));
    }

    private static function definitionFor(\stdClass $case): mixed
    {
        if (property_exists($case, 'definition')) {
            return $case->definition;
        }
        if (isset($case->generated)) {
            \assert(\is_int($case->generated));

            return self::generatedDefinition($case->generated);
        }
        if (isset($case->sized)) {
            \assert(\is_int($case->sized));
            $definition = self::sizedDefinition($case->sized);
            if (isset($case->padWithWhitespace)) {
                \assert(\is_string($case->padWithWhitespace));
                $text = self::get($definition, $case->padWithWhitespace);
                \assert(\is_string($text));
                $definition = self::set($definition, $case->padWithWhitespace, " \t".$text."\n  ");
            }

            return $definition;
        }
        $definition = self::base();
        foreach ((array) ($case->set ?? []) as $entry) {
            \assert(\is_array($entry) && \is_string($entry[0]));
            $definition = self::set($definition, $entry[0], $entry[1]);
        }
        foreach ((array) ($case->unset ?? []) as $path) {
            \assert(\is_string($path));
            $definition = self::unset($definition, $path);
        }

        return $definition;
    }

    /** A deep copy of $data, with the node at $path set to $value. */
    private static function set(mixed $data, string $path, mixed $value): mixed
    {
        return self::update($data, explode('/', $path), false, $value);
    }

    /** A deep copy of $data, without the object property at $path. */
    private static function unset(mixed $data, string $path): mixed
    {
        return self::update($data, explode('/', $path), true, null);
    }

    /**
     * @param list<string> $keys
     */
    private static function update(mixed $data, array $keys, bool $remove, mixed $value): mixed
    {
        $copy = json_decode((string) json_encode($data), false, 512, \JSON_THROW_ON_ERROR);
        $key = array_shift($keys);
        \assert(null !== $key);
        if (\is_array($copy)) {
            $copy[(int) $key] = [] === $keys ? $value : self::update($copy[(int) $key], $keys, $remove, $value);

            return $copy;
        }
        \assert($copy instanceof \stdClass);
        if ([] === $keys && $remove) {
            unset($copy->{$key});
        } else {
            $copy->{$key} = [] === $keys ? $value : self::update($copy->{$key}, $keys, $remove, $value);
        }

        return $copy;
    }

    private static function get(mixed $data, string $path): mixed
    {
        foreach (explode('/', $path) as $key) {
            $data = \is_array($data) ? $data[(int) $key] : ($data instanceof \stdClass ? $data->{$key} : null);
        }

        return $data;
    }

    /**
     * $blocksPerSide custom radio blocks per side, each with 12 options, every text 'a'.
     * Mirrored by generatedDefinition() in templateDefinition.test.ts.
     */
    private static function generatedDefinition(int $blocksPerSide): \stdClass
    {
        $definition = ['schemaVersion' => 1];
        foreach (['employee' => 'e', 'manager' => 'm'] as $side => $prefix) {
            $blocks = [];
            for ($i = 0; $i < $blocksPerSide; ++$i) {
                $options = [];
                for ($j = 0; $j < TemplateDefinitionValidator::MAX_OPTIONS; ++$j) {
                    $options[] = ['value' => \sprintf('o_%08d', $j), 'label' => 'a'];
                }
                $blocks[] = [
                    'kind' => 'custom',
                    'id' => \sprintf('c_%sb%08d', $prefix, $i),
                    'title' => 'a',
                    'field' => ['id' => \sprintf('c_%sf%08d', $prefix, $i), 'type' => 'radio', 'label' => 'a', 'options' => $options],
                ];
            }
            $definition[$side] = $blocks;
        }
        $object = json_decode((string) json_encode($definition), false, 512, \JSON_THROW_ON_ERROR);
        \assert($object instanceof \stdClass);

        return $object;
    }

    /**
     * generatedDefinition(30), padded with 'ж' (2 bytes in UTF-8) and at most one 'a' to
     * exactly $bytes bytes of canonical JSON: each text in traversal order is filled up to
     * its limit before the next. Mirrored by sizedDefinition() in templateDefinition.test.ts.
     */
    private static function sizedDefinition(int $bytes): \stdClass
    {
        $definition = self::generatedDefinition(TemplateDefinitionValidator::MAX_BLOCKS_PER_SIDE);
        $remaining = $bytes - \strlen((new TemplateDefinitionValidator())->canonicalJson($definition));
        self::assertGreaterThanOrEqual(0, $remaining, 'sizedDefinition() starts above the requested size');
        // Each text in traversal order, as [its holder object, its property, its limit];
        // padded in place (the objects are the definition's own).
        $texts = [];
        foreach (['employee', 'manager'] as $side) {
            \assert(\is_array($definition->{$side}));
            foreach ($definition->{$side} as $block) {
                \assert($block instanceof \stdClass && $block->field instanceof \stdClass && \is_array($block->field->options));
                $texts[] = [$block, 'title', TemplateDefinitionValidator::MAX_TITLE_LENGTH];
                $texts[] = [$block->field, 'label', TemplateDefinitionValidator::MAX_FIELD_LABEL_LENGTH];
                foreach ($block->field->options as $option) {
                    \assert($option instanceof \stdClass);
                    $texts[] = [$option, 'label', TemplateDefinitionValidator::MAX_OPTION_LABEL_LENGTH];
                }
            }
        }
        foreach ($texts as [$holder, $property, $max]) {
            $add = min($max - 1, intdiv($remaining, 2));
            $holder->{$property} = 'a'.str_repeat('ж', $add);
            $remaining -= 2 * $add;
        }
        if (1 === $remaining) {
            foreach ($texts as [$holder, $property, $max]) {
                \assert(\is_string($holder->{$property}));
                if (mb_strlen($holder->{$property}) < $max) {
                    $holder->{$property} .= 'a';
                    $remaining = 0;
                    break;
                }
            }
        }
        self::assertSame(0, $remaining, 'sizedDefinition() could not reach the requested size');

        return $definition;
    }
}
