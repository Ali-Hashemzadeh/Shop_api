<?php

declare(strict_types=1);

namespace Modules\Sms\Infrastructure\Drivers;

use Modules\Sms\Domain\Contracts\SmsProviderInterface;
use Modules\Sms\Domain\DTOs\SmsMessageDTO;
use Modules\Sms\Domain\DTOs\SmsResultDTO;

/**
 * In-memory provider for tests. Records every message instead of sending it,
 * so a test can assert on delivery without any network I/O. Registered as a
 * container singleton, so the instance the manager resolves is the same one a
 * test inspects.
 *
 * Set `shouldFail` to simulate a rejecting provider (which must produce a
 * failed delivery record rather than an exception).
 */
class FakeSmsProvider implements SmsProviderInterface
{
    /** @var list<SmsMessageDTO> */
    private array $sent = [];

    public bool $shouldFail = false;

    public string $failureMessage = 'Fake provider failure.';

    /** Simulate an unconfigured template: nothing sent, and that is fine. */
    public bool $shouldSkip = false;

    public string $skipReason = 'Fake provider has no template configured.';

    public function name(): string
    {
        return 'fake';
    }

    public function send(SmsMessageDTO $message): SmsResultDTO
    {
        if ($this->shouldSkip) {
            return SmsResultDTO::skipped($this->name(), $this->skipReason);
        }

        $this->sent[] = $message;

        if ($this->shouldFail) {
            return SmsResultDTO::failure($this->name(), $this->failureMessage);
        }

        return SmsResultDTO::success($this->name(), 'fake-'.count($this->sent));
    }

    /** @return list<SmsMessageDTO> */
    public function sent(): array
    {
        return $this->sent;
    }

    public function lastMessage(): ?SmsMessageDTO
    {
        return $this->sent === [] ? null : $this->sent[count($this->sent) - 1];
    }

    public function reset(): void
    {
        $this->sent = [];
        $this->shouldFail = false;
        $this->shouldSkip = false;
    }
}
