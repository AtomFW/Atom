<?php

declare(strict_types=1);

namespace Atom\Component\Mail;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Random\Engine\Secure;
use Random\Randomizer;
use SensitiveParameter;

/**
 * PHPMailer wrapper with secret redaction in logs, test mode, and cryptographically safe message headers.
 */
final class MailerProxy extends PHPMailer
{
    protected LoggerInterface $logger;

    /** @var array<string, mixed> */
    protected array $opts = [];

    protected bool $testMode = false;
    protected ?string $lastError = null;

    /** @var array{address?: string, name?: string}|null */
    protected ?array $from = null;

    /**
     * @param array<string, mixed>|object|null $options
     */
    public function __construct(
        #[SensitiveParameter] array|object|null $options = null,
        ?LoggerInterface $logger = null,
        bool $exceptions = true,
    ) {
        parent::__construct($exceptions);

        $this->logger = $logger ?? new NullLogger();
        $this->opts = $this->normalizeOptions($options);

        $this->CharSet = (string) ($this->opts['charset'] ?? 'UTF-8');
        $this->Timeout = (int) ($this->opts['timeout'] ?? 60);
        $this->SMTPKeepAlive = (bool) ($this->opts['smtp_keepalive'] ?? true);

        if (!empty($this->opts['mailer']) && \is_string($this->opts['mailer'])) {
            $this->setTransport($this->opts['mailer']);
        }

        if (!empty($this->opts['smtp']) && \is_array($this->opts['smtp'])) {
            $this->configureSMTP($this->opts['smtp']);
        }

        if (!empty($this->opts['dkim']) && \is_array($this->opts['dkim'])) {
            $this->setDKIM($this->opts['dkim']);
        }

        if (!empty($this->opts['testMode']) || !empty($this->opts['test_mode'])) {
            $this->setTestMode(true);
        }

        if (!empty($this->opts['from']) && \is_array($this->opts['from'])) {
            $this->from = $this->opts['from'];
        }

        $this->logger->debug('PHPMailerWrapper initialized', ['opts' => $this->redactSecrets($this->opts)]);
    }

    /**
     * @param array<string, mixed>|object|null $options
     * @return array<string, mixed>
     */
    protected function normalizeOptions(#[SensitiveParameter] array|object|null $options): array
    {
        if ($options === null) {
            return [];
        }

        if (\is_object($options)) {
            $options = (array) $options;
        }

        return \array_replace_recursive([
            'mailer' => $options['mailer'] ?? $options['transport'] ?? null,
            'charset' => $options['charset'] ?? 'UTF-8',
            'timeout' => $options['timeout'] ?? 60,
            'smtp' => $options['smtp'] ?? ($options['smtp_config'] ?? []),
            'dkim' => $options['dkim'] ?? [],
            'smtp_keepalive' => $options['smtp_keepalive'] ?? true,
            'smtp_options' => $options['smtp_options'] ?? [],
            'test_mode' => $options['test_mode'] ?? false,
        ], $options);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function redactSecrets(#[SensitiveParameter] array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $lower = \strtolower((string) $k);
            if (\str_contains($lower, 'pass') || \str_contains($lower, 'secret') || \str_contains($lower, 'key')) {
                $out[$k] = '[REDACTED]';
            } elseif (\is_array($v)) {
                $out[$k] = $this->redactSecrets($v);
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    public function setTransport(string $mode): void
    {
        $mode = \strtolower($mode);
        match ($mode) {
            'smtp' => $this->isSMTP(),
            'sendmail' => $this->isSendmail(),
            default => $this->isMail(),
        };
        $this->logger->info('Transport set', ['transport' => $mode]);
    }

    /**
     * @param array<string, mixed>|object $cfg
     */
    public function configureSMTP(#[SensitiveParameter] array|object $cfg): void
    {
        if (\is_object($cfg)) {
            $cfg = (array) $cfg;
        }

        $this->isSMTP();
        $this->Host = (string) ($cfg['host'] ?? $cfg['smtpHost'] ?? $this->Host);
        $this->Port = (int) ($cfg['port'] ?? $cfg['smtpPort'] ?? $this->Port);
        $this->SMTPAuth = isset($cfg['smtpAuth']) ? (bool) $cfg['smtpAuth'] : $this->SMTPAuth;

        $username = $cfg['username'] ?? $cfg['user'] ?? null;
        if (\is_string($username) && $username !== '') {
            $this->Username = $username;
        }

        if (!empty($cfg['password']) && \is_string($cfg['password'])) {
            $this->Password = $cfg['password'];
        }

        if (!empty($cfg['encryption'])) {
            $enc = \strtolower((string) $cfg['encryption']);
            if ($enc === 'ssl' || $enc === 'tls') {
                $this->SMTPSecure = $enc;
            }
        }

        if (isset($cfg['timeout'])) {
            $this->Timeout = (int) $cfg['timeout'];
        }

        if (isset($cfg['keepalive'])) {
            $this->SMTPKeepAlive = (bool) $cfg['keepalive'];
        }

        if (!empty($cfg['smtpOptions']) && \is_array($cfg['smtpOptions'])) {
            $this->SMTPOptions = $cfg['smtpOptions'];
        }

        if (isset($cfg['localDomain']) || isset($cfg['hostname'])) {
            $this->Hostname = (string) ($cfg['localDomain'] ?? $cfg['hostname'] ?? $this->Hostname);
        }

        if (isset($cfg['charset'])) {
            $this->CharSet = (string) $cfg['charset'];
        }

        $this->opts['smtp'] = $this->redactSecrets($cfg);

        $this->logger->info('SMTP configured', [
            'host' => $this->Host,
            'port' => $this->Port,
            'auth' => $this->SMTPAuth,
        ]);
    }

    /**
     * @param array<string, mixed> $opts
     */
    public function setDKIM(#[SensitiveParameter] array $opts): void
    {
        if (empty($opts['domain']) || empty($opts['selector']) || empty($opts['private_key_file'])) {
            $this->logger->warning('DKIM options incomplete; skipping DKIM configuration', [
                'opts' => $this->redactSecrets($opts),
            ]);
            return;
        }

        $this->DKIM_domain = (string) $opts['domain'];
        $this->DKIM_selector = (string) $opts['selector'];
        $this->DKIM_private = (string) $opts['private_key_file'];
        if (!empty($opts['passphrase'])) {
            $this->DKIM_passphrase = (string) $opts['passphrase'];
        }
        if (!empty($opts['identity'])) {
            $this->DKIM_identity = (string) $opts['identity'];
        }

        $this->opts['dkim'] = $this->redactSecrets($opts);
        $this->logger->info('DKIM configured', [
            'domain' => $opts['domain'],
            'selector' => $opts['selector'],
        ]);
    }

    public function setTestMode(bool $on = true): void
    {
        $this->testMode = $on;
        $this->SMTPDebug = $on ? 2 : 0;
        $this->opts['test_mode'] = $on;
        $this->logger->info('Test mode ' . ($on ? 'enabled' : 'disabled'));
    }

    public function isTestMode(): bool
    {
        return $this->testMode;
    }

    #[\Override]
    public function send(): bool
    {
        $this->lastError = null;

        if ($this->testMode) {
            $this->logger->debug('Test mode: send simulated', [
                'from' => $this->From,
                'to' => $this->getToAddresses(),
                'subject' => $this->Subject,
            ]);
            return true;
        }

        try {
            return (bool) parent::send();
        } catch (PHPMailerException $e) {
            $this->lastError = $e->getMessage();
            $this->logger->warning('Email not sent', ['error' => $this->lastError]);
            $this->logger->error('PHPMailer exception on send', ['exception' => $e]);
            return false;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->logger->error('Unexpected exception on send', ['exception' => $e]);
            return false;
        }
    }

    public function getLastError(): ?string
    {
        return $this->lastError ?? ($this->ErrorInfo !== '' ? $this->ErrorInfo : null);
    }

    /**
     * @param array<int, array<string, mixed>>|null $addStringAttachments
     * @param array<int, array<string, mixed>>|null $addEmbeddedImage
     */
    public function sendHtml(
        string $to,
        string $subject,
        string $html,
        ?string $from = null,
        ?string $alt = null,
        ?string $addReplyTo = null,
        ?array $addStringAttachments = null,
        ?array $addEmbeddedImage = null,
    ): bool {
        return $this->prepareAndSend(
            $to,
            $subject,
            $html,
            true,
            $from,
            $alt ?? \strip_tags($html),
            $addReplyTo,
            $addStringAttachments,
            $addEmbeddedImage,
        );
    }

    /**
     * @param array<int, array<string, mixed>>|null $addStringAttachments
     * @param array<int, array<string, mixed>>|null $addEmbeddedImage
     */
    public function sendText(
        string $to,
        string $subject,
        string $text,
        ?string $from = null,
        ?string $alt = null,
        ?string $addReplyTo = null,
        ?array $addStringAttachments = null,
        ?array $addEmbeddedImage = null,
    ): bool {
        return $this->prepareAndSend(
            $to,
            $subject,
            $text,
            false,
            $from,
            $alt,
            $addReplyTo,
            $addStringAttachments,
            $addEmbeddedImage,
        );
    }

    /**
     * @param array<int, array<string, mixed>>|null $addStringAttachments
     * @param array<int, array<string, mixed>>|null $addEmbeddedImage
     */
    private function prepareAndSend(
        string $to,
        string $subject,
        string $body,
        bool $isHtml,
        ?string $from,
        ?string $alt,
        ?string $addReplyTo,
        ?array $addStringAttachments,
        ?array $addEmbeddedImage,
    ): bool {
        $this->clearAddresses();
        $this->clearAttachments();

        if ($from !== null) {
            [$addr, $name] = $this->splitAddressAndName($from);
            $this->setFrom($addr, $name);
        } elseif ($this->from !== null && !empty($this->from['address'])) {
            $this->setFrom((string) $this->from['address'], (string) ($this->from['name'] ?? ''));
        }

        [$addr, $name] = $this->splitAddressAndName($to);
        $this->addAddress($addr, $name);

        $this->isHTML($isHtml);
        $this->Subject = $subject;
        $this->Body = $body;

        if ($alt !== null) {
            $this->AltBody = $alt;
        }

        if ($addReplyTo !== null) {
            $this->addReplyTo($addReplyTo);
        }

        if ($addStringAttachments !== null) {
            foreach ($addStringAttachments as $attachment) {
                $this->addStringAttachment(
                    (string) ($attachment['content'] ?? ''),
                    (string) ($attachment['filename'] ?? 'attachment.bin'),
                    (string) ($attachment['encoding'] ?? self::ENCODING_BASE64),
                    (string) ($attachment['type'] ?? ''),
                    (string) ($attachment['disposition'] ?? 'attachment'),
                );
            }
        }

        if ($addEmbeddedImage !== null) {
            foreach ($addEmbeddedImage as $attachment) {
                $this->addEmbeddedImage(
                    (string) ($attachment['filename'] ?? ''),
                    (string) ($attachment['cid'] ?? ''),
                    (string) ($attachment['content'] ?? ''),
                    (string) ($attachment['encoding'] ?? self::ENCODING_BASE64),
                    (string) ($attachment['type'] ?? ''),
                    (string) ($attachment['disposition'] ?? 'inline'),
                );
            }
        }

        $identity = (new Randomizer(new Secure()))->getInt(40000, 44444);
        $this->XMailer = ' ';
        $this->addCustomHeader('X-Priority', '3 (Normal)');
        $this->addCustomHeader('X-Atom', 'true');
        $this->addCustomHeader('X-Mailer', 'With Atom Framework');
        $this->addCustomHeader('X-Identity', (string) $identity);
        $this->addCustomHeader('T-Attechment', 'true');

        return $this->send();
    }

    /**
     * @param array<int, array<string, mixed>> $envelopes
     * @return array{ok: int, failed: int}
     */
    public function sendLoop(array $envelopes): array
    {
        $ok = 0;
        $failed = 0;
        $keepAlive = $this->SMTPKeepAlive;

        foreach ($envelopes as $env) {
            $this->resetForNext();

            if (!empty($env['from'])) {
                if (\is_array($env['from'])) {
                    $this->setFrom((string) ($env['from'][0] ?? ''), (string) ($env['from'][1] ?? ''));
                } else {
                    [$addr, $name] = $this->splitAddressAndName((string) $env['from']);
                    $this->setFrom($addr, $name);
                }
            }

            $tos = $env['to'] ?? [];
            if (!\is_array($tos)) {
                $tos = [$tos];
            }

            foreach ($tos as $t) {
                if (\is_array($t)) {
                    $this->addAddress((string) ($t[0] ?? ''), (string) ($t[1] ?? ''));
                } else {
                    [$addr, $name] = $this->splitAddressAndName((string) $t);
                    $this->addAddress($addr, $name);
                }
            }

            if (!empty($env['headers']) && \is_array($env['headers'])) {
                foreach ($env['headers'] as $hn => $hv) {
                    $this->addCustomHeader((string) $hn, (string) $hv);
                }
            }

            $this->Subject = (string) ($env['subject'] ?? '');
            $this->isHTML((bool) ($env['is_html'] ?? false));
            $this->Body = (string) ($env['body'] ?? '');

            if (!empty($env['attachments']) && \is_array($env['attachments'])) {
                foreach ($env['attachments'] as $att) {
                    if (\is_array($att) && !empty($att[0])) {
                        $this->addAttachment((string) $att[0], (string) ($att[1] ?? ''));
                    }
                }
            }

            if ($this->send()) {
                $ok++;
            } else {
                $failed++;
            }

            if (!$keepAlive) {
                $this->closeSmtp();
            }
        }

        if ($keepAlive) {
            $this->closeSmtp();
        }

        return ['ok' => $ok, 'failed' => $failed];
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function splitAddressAndName(string $input): array
    {
        if (\preg_match('/^(.*)<(.+?)>$/', $input, $m) === 1) {
            return [\trim($m[2]), \trim($m[1], "\"' ")];
        }

        return [\trim($input), ''];
    }

    public function resetForNext(): void
    {
        try {
            $this->clearAddresses();
            $this->clearCCs();
            $this->clearBCCs();
            $this->clearReplyTos();
            $this->clearAllRecipients();
            $this->clearAttachments();
            $this->clearCustomHeaders();
        } catch (\Throwable $e) {
            $this->logger->warning('clear* failed on resetForNext', ['exception' => $e]);
        }
    }

    public function closeSmtp(): void
    {
        try {
            $this->smtpClose();
        } catch (\Throwable $e) {
            $this->logger->warning('smtpClose failed', ['exception' => $e]);
        }
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function getToAddresses(): array
    {
        $list = [];
        try {
            foreach (parent::getToAddresses() as $a) {
                $list[] = \is_array($a) ? (string) ($a[0] ?? '') : (string) $a;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('getToAddresses failed', ['exception' => $e]);
        }

        return $list;
    }

    public function __destruct()
    {
        $this->closeSmtp();
    }
}
