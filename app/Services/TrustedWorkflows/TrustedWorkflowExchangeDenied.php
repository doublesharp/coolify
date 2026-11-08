<?php

namespace App\Services\TrustedWorkflows;

use RuntimeException;

/**
 * The exchange refused a token. The reason and detail go to the audit log, never to the caller.
 */
class TrustedWorkflowExchangeDenied extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $reason,
        public readonly int $status = 403,
        public readonly string $detail = '',
        public readonly array $context = [],
    ) {
        parent::__construct($reason);
    }
}
