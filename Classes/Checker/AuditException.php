<?php
declare(strict_types=1);

namespace Aistea\AisteaSeo\Checker;

/**
 * Expected audit failure; the message is a reason code that maps to the label error.<code>.
 */
final class AuditException extends \RuntimeException
{
    /**
     * @param list<int|string> $arguments
     */
    public function __construct(string $code, public readonly array $arguments = [])
    {
        parent::__construct($code);
    }
}
