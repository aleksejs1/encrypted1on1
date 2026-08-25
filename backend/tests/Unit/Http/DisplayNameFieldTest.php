<?php

namespace App\Tests\Unit\Http;

use App\Http\DisplayNameField;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

class DisplayNameFieldTest extends TestCase
{
    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('translated');

        return $translator;
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $result = DisplayNameField::parse('  Alex Morgan  ', $this->translator());

        self::assertSame('Alex Morgan', $result);
    }

    public function testStripsBidiOverrideAndZeroWidthCharacters(): void
    {
        $result = DisplayNameField::parse("Alex\u{202E} Morgan\u{200B}", $this->translator());

        self::assertSame('Alex Morgan', $result);
    }

    public function testAcceptsA200CharacterMultiByteNameUnderTheCharacterCap(): void
    {
        $name = str_repeat('Ж', 200); // 200 chars, 400 bytes in UTF-8

        $result = DisplayNameField::parse($name, $this->translator());

        self::assertSame($name, $result);
    }

    public function testRejectsANonStringValue(): void
    {
        $result = DisplayNameField::parse(42, $this->translator());

        self::assertInstanceOf(JsonResponse::class, $result);
        self::assertSame(400, $result->getStatusCode());
    }

    public function testRejectsAValueOver255Characters(): void
    {
        $result = DisplayNameField::parse(str_repeat('x', 256), $this->translator());

        self::assertInstanceOf(JsonResponse::class, $result);
        self::assertSame(400, $result->getStatusCode());
    }

    /**
     * preg_replace() returns null on a PCRE failure — malformed UTF-8 (e.g. an
     * unpaired surrogate) is the only realistic cause given the /u modifier this
     * pattern uses. Must be rejected, not silently coerced to ''.
     */
    public function testRejectsInvalidUtf8InsteadOfSilentlyCoercingToEmptyString(): void
    {
        $invalidUtf8 = "\xB1\x31"; // a lone continuation byte — not valid UTF-8

        $result = DisplayNameField::parse($invalidUtf8, $this->translator());

        self::assertInstanceOf(JsonResponse::class, $result);
        self::assertSame(400, $result->getStatusCode());
    }
}
