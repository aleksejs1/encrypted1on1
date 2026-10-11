<?php

namespace App\Org;

/**
 * Thrown by OrgStructure::assign(). Not an HTTP exception: the class has no translator,
 * so the controller catches it and answers 400 with the reason's translated message.
 */
final class OrgStructureException extends \DomainException
{
    public function __construct(public readonly OrgStructureError $reason)
    {
        parent::__construct($reason->value);
    }
}
