<?php

namespace App\Dto;

use App\Entity\Anketa;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

readonly class CreateAnketaRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $counterpartId = '',

        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['employee', 'manager'])]
        public string $myRole = '',

        #[Assert\NotBlank]
        public string $meetingDate = '',

        #[Assert\NotBlank]
        public string $mySealedKey = '',

        #[Assert\NotBlank]
        public string $counterpartSealedKey = '',

        #[Assert\Positive]
        public ?int $periodicityDays = null,

        #[Assert\Type('string')]
        public ?string $outcomesBlob = null,

        #[Assert\Type('string')]
        public ?string $topicsBlob = null,

        public string $templateKey = Anketa::DEFAULT_TEMPLATE_KEY,

        // The company template (GitHub issue #144), with templateKey 'custom' and only
        // then. A template id, not a version: the server resolves it to the current one.
        #[Assert\Type('string')]
        public ?string $customTemplateId = null,
    ) {
    }

    #[Assert\Callback]
    public function validateMeetingDate(ExecutionContextInterface $context): void
    {
        if ('' !== $this->meetingDate) {
            try {
                new \DateTimeImmutable($this->meetingDate);
            } catch (\Exception) {
                DtoViolation::add($context, 'meetingDate', 'errors.meeting_date_must_be_valid_date');
            }
        }
    }

    // A bare #[Assert\Choice] here would silently never translate its error message —
    // this codebase's own myRole/SetLocaleRequest fields already have that exact gap
    // (a dead/never-referenced translation key). #[Assert\Callback] + DtoViolation::add()
    // is the one pattern actually proven to work, mirroring UpdateGoalRequest::validateStatus().
    #[Assert\Callback]
    public function validateTemplateKey(ExecutionContextInterface $context): void
    {
        if (!\in_array($this->templateKey, Anketa::TEMPLATE_KEYS, true)) {
            DtoViolation::add($context, 'templateKey', 'errors.template_key_must_be_one_of', ['%templateKeys%' => implode(', ', Anketa::TEMPLATE_KEYS)]);
        }
    }

    #[Assert\Callback]
    public function validateCustomTemplateId(ExecutionContextInterface $context): void
    {
        $hasId = null !== $this->customTemplateId && '' !== $this->customTemplateId;
        if ((Anketa::CUSTOM_TEMPLATE_KEY === $this->templateKey) !== $hasId) {
            DtoViolation::add($context, 'customTemplateId', 'errors.custom_template_id_mismatch');
        }
    }
}
