<?php

declare(strict_types=1);

namespace RayaCash\Exception;

/**
 * La firma de un webhook no es válida: respondé 400 y no lo proceses. getHttpBody() trae el body
 * que llegó y getSigHeader(), el header Raya-Signature.
 */
class SignatureVerificationException extends RayaException
{
    private ?string $sigHeader;

    public function __construct(
        string $message,
        ?string $sigHeader = null,
        ?string $httpBody = null,
    ) {
        parent::__construct($message, httpBody: $httpBody);
        $this->sigHeader = $sigHeader;
    }

    /** El header Raya-Signature, tal como llegó. */
    public function getSigHeader(): ?string
    {
        return $this->sigHeader;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return parent::__debugInfo() + ['sig_header' => $this->sigHeader];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        return parent::__serialize() + ['sig_header' => $this->sigHeader];
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        parent::__unserialize($data);
        $this->sigHeader = is_string($data['sig_header'] ?? null) ? $data['sig_header'] : null;
    }
}
