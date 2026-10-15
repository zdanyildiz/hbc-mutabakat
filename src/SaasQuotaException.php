<?php

declare(strict_types=1);

namespace App;

/**
 * Exception thrown when a tenant's OCR quota or subscription state blocks processing (fail-closed Quota Gate).
 */
class SaasQuotaException extends \RuntimeException
{
    public const CODE_QUOTA_EXCEEDED = 'QUOTA_EXCEEDED';
    public const CODE_SUBSCRIPTION_EXPIRED = 'SUBSCRIPTION_EXPIRED';
    public const CODE_PENDING_PAYMENT = 'PENDING_PAYMENT';
    public const CODE_TENANT_SUSPENDED = 'TENANT_SUSPENDED';
    public const CODE_NO_SUBSCRIPTION = 'NO_SUBSCRIPTION';

    public function __construct(
        string $message,
        private readonly string $errorCode = self::CODE_QUOTA_EXCEEDED,
        private readonly bool $upgradeRequired = true,
        private readonly ?int $tenantId = null,
        private readonly string $redirectUrl = 'index.php?view=upgrade#saasPlansSection',
        int $code = 402,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function isUpgradeRequired(): bool
    {
        return $this->upgradeRequired;
    }

    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    public function getRedirectUrl(): string
    {
        return $this->redirectUrl;
    }

    /**
     * @return array{
     *     success: bool,
     *     upgrade_required: bool,
     *     error_code: string,
     *     message: string,
     *     tenant_id: int|null,
     *     redirect_url: string
     * }
     */
    public function toPayload(): array
    {
        return [
            'success' => false,
            'upgrade_required' => $this->upgradeRequired,
            'error_code' => $this->errorCode,
            'message' => $this->getMessage(),
            'tenant_id' => $this->tenantId,
            'redirect_url' => $this->redirectUrl,
        ];
    }
}
