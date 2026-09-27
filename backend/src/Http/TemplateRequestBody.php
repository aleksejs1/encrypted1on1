<?php

namespace App\Http;

use App\Entity\CustomTemplateVersion;
use App\Template\TemplateDefinitionValidator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reads and checks the body of a template create, update or archive (GitHub issue
 * #142) for AdminTemplateController, in place of a #[MapRequestPayload] DTO, for two
 * reasons (the second also keeps the admin gate ahead of any body error, #133 §8.2):
 * the definition needs one non-associative json_decode() to keep `{}` apart from `[]`
 * (see TemplateDefinitionValidator), and a DTO would be validated before update() gets
 * to check the template's own state first (#133 §8.1). Each check returns the response
 * to send when it fails, so the controller decides the order.
 */
final class TemplateRequestBody
{
    /**
     * Four times the definition's own cap: room for the name, description and
     * JSON escapes or indentation the canonical form trims away, while keeping
     * anyone from making the server parse megabytes per request.
     */
    public const MAX_BODY_BYTES = 4 * TemplateDefinitionValidator::MAX_DEFINITION_BYTES;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly TemplateDefinitionValidator $definitionValidator,
    ) {
    }

    /**
     * The request body as one JSON object, decoded without the associative flag;
     * otherwise the 400 (or 413) to return. A body over MAX_BODY_BYTES isn't read
     * past the cap, or decoded.
     */
    public function decode(Request $request): \stdClass|JsonResponse
    {
        // The declared length first, so a large body is refused unread; then at most
        // the cap is read, and one byte more means too large, in case the header was
        // absent or wrong.
        if ($request->server->getInt('CONTENT_LENGTH') > self::MAX_BODY_BYTES) {
            return $this->tooLarge();
        }
        $stream = $request->getContent(true);
        $content = stream_get_contents($stream, self::MAX_BODY_BYTES);
        if (!\is_string($content)) {
            return $this->invalidJson();
        }
        if (false !== fgetc($stream)) {
            return $this->tooLarge();
        }
        try {
            $body = json_decode($content, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $body = null;
        }

        return $body instanceof \stdClass ? $body : $this->invalidJson();
    }

    private function invalidJson(): JsonResponse
    {
        return new JsonResponse(['error' => $this->translator->trans('errors.invalid_json_body')], 400);
    }

    private function tooLarge(): JsonResponse
    {
        return new JsonResponse(['error' => $this->translator->trans('errors.template_body_too_large')], 413);
    }

    /**
     * The 400 for an invalid name or description, or null. They follow the same trim
     * and rejected-character rules as the texts inside the definition (#133 §5.2). A
     * missing description is '' on create, but an error on update, where it must not
     * silently clear the stored one.
     */
    public function textViolations(\stdClass $body, bool $descriptionRequired): ?JsonResponse
    {
        $violations = [];
        if (null !== TemplateDefinitionValidator::textProblem($body->name ?? null, CustomTemplateVersion::MAX_NAME_LENGTH)) {
            $violations[] = ['name', $this->translator->trans('errors.template_name_invalid', ['%max%' => CustomTemplateVersion::MAX_NAME_LENGTH])];
        }
        if (!property_exists($body, 'description')) {
            if ($descriptionRequired) {
                $violations[] = ['description', $this->translator->trans('errors.missing_or_invalid_field', ['%field%' => 'description'])];
            }
        } elseif (null !== TemplateDefinitionValidator::textProblem($body->description, CustomTemplateVersion::MAX_DESCRIPTION_LENGTH, allowEmpty: true)) {
            $violations[] = ['description', $this->translator->trans('errors.template_description_invalid', ['%max%' => CustomTemplateVersion::MAX_DESCRIPTION_LENGTH])];
        }

        return [] === $violations ? null : $this->violations($violations);
    }

    /**
     * A 400 in the shape JsonExceptionListener gives #[MapRequestPayload]'s
     * validation errors (`error` is every message, one per line), so the client
     * handles both the same way.
     *
     * @param non-empty-list<array{string, string}> $violations [property, translated message]
     */
    public function violations(array $violations): JsonResponse
    {
        return new JsonResponse([
            'error' => implode("\n", array_column($violations, 1)),
            'violations' => array_map(static fn (array $violation) => ['property' => $violation[0], 'message' => $violation[1]], $violations),
        ], 400);
    }

    /** A text field that passed textViolations(), trimmed as it's stored; '' when absent. */
    public static function text(\stdClass $body, string $field): string
    {
        $value = $body->{$field} ?? '';
        \assert(\is_string($value));

        return TemplateDefinitionValidator::trimText($value);
    }

    /**
     * The body's `definition` if it's valid; otherwise the 400 to return, with the
     * validator's `{path, code}` list for the editor.
     */
    public function validDefinition(\stdClass $body): \stdClass|JsonResponse
    {
        $definition = $body->definition ?? null;
        $errors = $this->definitionValidator->validate($definition);
        if ([] !== $errors || !$definition instanceof \stdClass) {
            return new JsonResponse([
                'error' => $this->translator->trans('errors.template_definition_invalid'),
                'errors' => $errors,
            ], 400);
        }

        return $definition;
    }
}
