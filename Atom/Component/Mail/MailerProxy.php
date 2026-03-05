<?php

declare(strict_types=1);

namespace Atom\Component\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * PHPMailerWrapper
 *
 * - Subclass of PHPMailer to keep a single instantiation and extend with helpers.
 * - Constructor accepts options (array|object) and an optional PSR-3 logger.
 * - Provides convenient methods: configureSMTP, setDKIM, sendHtml, sendText, sendLoop, sendOnce (test mode aware), etc.
 *
 */
final class MailerProxy extends PHPMailer
{
    /** @var LoggerInterface */
    protected LoggerInterface $logger;

    /** @var array Normalized options */
    protected array $opts = [];

    /** @var bool If true, do not actually call network send; used for testing. */
    protected bool $testMode = false;

    /** @var string|null last error message */
    protected ?string $lastError = null;

    /** @var array|null from address and name */
    protected ?array $from = null;

    /**
     * @param array|object|null $options SMTP / behavior options (see configureSMTP)
     * @param LoggerInterface|null $logger PSR-3 logger; if null, NullLogger is used
     * @param bool $exceptions pass to PHPMailer constructor (enable exceptions)
     */
    public function __construct(
        array|object|null $options = null,
        ?LoggerInterface $logger = null,
        bool $exceptions = true
    ) {
        // Call parent constructor once; $exceptions toggles PHPMailer exceptions mode
        parent::__construct($exceptions);

        $this->logger = $logger ?? new NullLogger();
        $this->opts = $this->normalizeOptions($options);

        // sensible defaults
        $this->CharSet = $this->opts['charset'] ?? 'UTF-8';
        $this->Timeout = (int)($this->opts['timeout'] ?? 60);
        $this->SMTPKeepAlive = (bool)($this->opts['smtp_keepalive'] ?? true);

        // configure transport if requested in options
        if (!empty($this->opts['mailer'])) {
            $this->setTransport($this->opts['mailer']);
        }

        if (!empty($this->opts['smtp']) && \is_array($this->opts['smtp'])) {
            $this->configureSMTP($this->opts['smtp']);
        }

        // set DKIM if present
        if (!empty($this->opts['dkim']) && \is_array($this->opts['dkim'])) {
            $this->setDKIM($this->opts['dkim']);
        }

        // test mode
        if (!empty($this->opts['testMode'])) {
            $this->setTestMode(true);
        }

        if (!empty($this->opts['from'])) {
            $this->from = $this->opts['from'];
        }

        $this->logger->debug('PHPMailerWrapper initialized', ['opts' => $this->opts]);
    }

    // -------------------------
    // Options normalization
    // -------------------------
    protected function normalizeOptions(array|object|null $options): array
    {
        if ($options === null) {
            return [];
        }

        if (\is_object($options)) {
            $options = (array)$options;
        }

        // allowed / common keys with defaults
        $allowed = [
            'mailer', // 'smtp'|'mail'|'sendmail'
            'charset',
            'timeout',
            'smtp' => [], // nested smtp config
            'dkim' => [], // nested dkim config
            'smtp_keepalive' => true,
            'smtp_options' => [], // SMTPOptions
            'test_mode' => false,
        ];
        // merge without strict filtering — keep user keys
        return array_replace_recursive([
            'mailer' => $options['mailer'] ?? $options['transport'] ?? null,
            'charset' => $options['charset'] ?? 'UTF-8',
            'timeout' => $options['timeout'] ?? 60,
            'smtp' => $options['smtp'] ?? ($options['smtp_config'] ?? []),
            'dkim' => $options['dkim'] ?? [],
            'smtp_keepalive' => $options['smtp_keepalive'] ?? true,
            'smtp_options' => $options['smtp_options'] ?? [],
            'test_mode' => $options['test_mode'] ?? false,
        ], (array)$options);
    }

    // -------------------------
    // Transport helpers
    // -------------------------

    /**
     * Set transport: 'smtp'|'mail'|'sendmail'
     */
    public function setTransport(string $mode): void
    {
        $mode = strtolower($mode);
        switch ($mode) {
            case 'smtp':
                $this->isSMTP();
                break;
            case 'sendmail':
                $this->isSendmail();
                break;
            case 'mail':
            default:
                $this->isMail();
                break;
        }
        $this->logger->info('Transport set', ['transport' => $mode]);
    }

    /**
     * Configure SMTP options.
     *
     * Expected keys in $cfg:
     *   host, port, username, password, encryption ('ssl'|'tls'), smtp_auth (bool), timeout (int),
     *   keepalive (bool), smtp_options (array for PHPMailer's SMTPOptions)
     *
     * @param array|object $cfg
     */
    public function configureSMTP(array|object $cfg): void
    {
        if (\is_object($cfg)) {
            $cfg = (array)$cfg;
        }

        $this->isSMTP();
        $this->Host = $cfg['host'] ?? $cfg['smtpHost'] ?? $this->Host;
        $this->Port = (int)($cfg['port'] ?? $cfg['smtpPort'] ?? $this->Port);
        $this->SMTPAuth = isset($cfg['smtpAuth']) ? (bool)$cfg['smtpAuth'] : $this->SMTPAuth;

        if (!empty($cfg['username'] ?? $cfg['user'] ?? null)) {
            $this->Username = $cfg['username'] ?? $cfg['user'];
        }

        if (!empty($cfg['password'] ?? null)) {
            $this->Password = $cfg['password'];
        }

        if (!empty($cfg['encryption'] ?? null)) {
            $enc = strtolower((string)$cfg['encryption']);
            if ($enc === 'ssl' || $enc === 'tls') {
                $this->SMTPSecure = $enc;
            }
        }

        if (isset($cfg['timeout'])) {
            $this->Timeout = (int)$cfg['timeout'];
        }

        if (isset($cfg['keepalive'])) {
            $this->SMTPKeepAlive = (bool)$cfg['keepalive'];
        }

        if (!empty($cfg['smtpOptions']) && is_array($cfg['smtpOptions'])) {
            $this->SMTPOptions = $cfg['smtpOptions'];
        }

        if (isset($cfg["localDomain"]) || isset($cfg["hostname"])) {
            $this->Hostname = $cfg['localDomain'] ?? $cfg['hostname'] ?? $this->Hostname;
        }

        if (isset($cfg['charset'])) {
            $this->CharSet = $cfg['charset'];
        }

        // save to opts
        $this->opts['smtp'] = $cfg;

        $this->logger->info(
            'SMTP configured',
            [
                        'host' => $this->Host,
                        'port' => $this->Port,
                        'auth' => $this->SMTPAuth
                ]
        );
    }

    // -------------------------
    // DKIM
    // -------------------------

    /**
     * Set DKIM options for PHPMailer.
     *
     * $opts should include: domain, selector, private_key_file, passphrase (optional)
     */
    public function setDKIM(array $opts): void
    {
        // minimal validation and assignment to PHPMailer properties
        if (empty($opts['domain']) || empty($opts['selector']) || empty($opts['private_key_file'])) {
            $this->logger->warning('DKIM options incomplete; skipping DKIM configuration', ['opts' => $opts]);
            return;
        }
        $this->DKIM_domain = $opts['domain'];
        $this->DKIM_selector = $opts['selector'];
        $this->DKIM_private = $opts['private_key_file'];
        if (!empty($opts['passphrase'])) {
            $this->DKIM_passphrase = $opts['passphrase'];
        }
        if (!empty($opts['identity'])) {
            $this->DKIM_identity = $opts['identity'];
        }
        $this->opts['dkim'] = $opts;
        $this->logger->info('DKIM configured', ['domain' => $opts['domain'], 'selector' => $opts['selector']]);
    }

    // -------------------------
    // Test mode
    // -------------------------

    /**
     * Enable / disable test mode.
     * When enabled, send() will not call network and will return true (after logging).
     */
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

    // -------------------------
    // Sending overrides & helpers
    // -------------------------

    /**
     * Override send() to support test mode and consistent logging.
     *
     * @return bool
     */
    public function send(): bool
    {
        $this->lastError = null;

        // If test mode, simulate success but go through the motions
        if ($this->testMode) {
            $this->logger->debug('Test mode: send simulated', [
                'from' => $this->From,
                'to' => self::getToAddresses(),
                'subject' => $this->Subject,
            ]);
            return true;
        }

        try {
            $ok = parent::send();
            if ($ok) {
                $this->logger->info('Email sent', ['subject' => $this->Subject, 'to' => self::getToAddresses()]);
            } else {
                $this->lastError = $this->ErrorInfo;
                $this->logger->warning('Email not sent', ['error' => $this->lastError]);
            }
            return (bool)$ok;
        } catch (PHPMailerException $e) {
            $this->lastError = $e->getMessage();
            $this->logger->error('PHPMailer exception on send', ['exception' => $e]);
            return false;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->logger->error('Unexpected exception on send', ['exception' => $e]);
            return false;
        }
    }

    /**
     * Return last error message (if any).
     */
    public function getLastError(): ?string
    {
        return $this->lastError ?? ($this->ErrorInfo);
    }

    // -------------------------
    // Convenience send helpers
    // -------------------------

    /**
     * Send HTML email to single recipient (quick helper).
     *
     * @param string $to email or "Name <email>"
     * @param string $subject
     * @param string $html
     * @param string|null $from optional from "Name <email>"
     * @return bool
     */
    public function sendHtml(
        string $to,
        string $subject,
        string $html,
        ?string $from = null,
        ?string $alt = null,
        ?string $addReplyTo = null,
        ?array $addStringAttachments = null,
        ?array $addEmbeddedImage = null
    ): bool {
        $this->clearAddresses();
        $this->clearAttachments();

        if ($from !== null) {
            [$addr, $name] = $this->splitAddressAndName($from);
            $this->setFrom($addr, $name);
        } else {
            $this->setFrom($this->from["address"], $this->from["name"]);
        }

        [$addr, $name] = $this->splitAddressAndName($to);
        $this->addAddress($addr, $name);

        $this->isHTML(true);
        $this->Subject = $subject;
        $this->Body = $html;

        if ($alt !== null) {
            $this->AltBody = $alt;
        }

        if ($addReplyTo !== null) {
            $this->addReplyTo($addReplyTo);
        }

        if ($addStringAttachments !== null) {
            foreach ($addStringAttachments as $attachment) {
                $this->addStringAttachment(
                    $attachment['filename'],
                    $attachment['content'],
                    $attachment['encoding'],
                    $attachment['type'] ?? null,
                    $attachment['disposition'] ?? null
                );
            }
        }

        if ($addEmbeddedImage !== null) {
            foreach ($addEmbeddedImage as $attachment) {
                $this->addEmbeddedImage(
                    $attachment['filename'],
                    $attachment['content'],
                    $attachment['cid'],
                    $attachment['type'] ?? null,
                    $attachment['disposition'] ?? null
                );
            }
        }

        $this->XMailer = ' ';
        $this->addCustomHeader('X-Priority', '3 (Normal)');
        $this->addCustomHeader('X-Atom', "true");
        $this->addCustomHeader('X-Mailer', 'With Atom Framework');
        $this->addCustomHeader('X-Identity', (string)mt_rand(40000, 44444));
        $this->addCustomHeader('T-Attechment', "true");

        return $this->send();
    }

    /**
     * Send plain text email to single recipient (quick helper).
     */
    public function sendText(
        string $to,
        string $subject,
        string $text,
        ?string $from = null,
        ?string $alt = null,
        ?string $addReplyTo = null,
        ?array $addStringAttachments = null,
        ?array $addEmbeddedImage = null
    ): bool {
        $this->clearAddresses();
        $this->clearAttachments();

        if ($from !== null) {
            [$addr, $name] = $this->splitAddressAndName($from);
            $this->setFrom($addr, $name);
        } else {
            $this->setFrom($this->from["address"], $this->from["name"]);
        }

        [$addr, $name] = $this->splitAddressAndName($to);
        $this->addAddress($addr, $name);

        $this->isHTML(false);
        $this->Subject = $subject;
        $this->Body = $text;

        if ($alt !== null) {
            $this->AltBody = $alt;
        }

        if ($addReplyTo !== null) {
            $this->addReplyTo($addReplyTo);
        }

        if ($addStringAttachments !== null) {
            foreach ($addStringAttachments as $attachment) {
                $this->addStringAttachment(
                    $attachment['filename'],
                    $attachment['content'],
                    $attachment['encoding'],
                    $attachment['type'] ?? null,
                    $attachment['disposition'] ?? null
                );
            }
        }

        if ($addEmbeddedImage !== null) {
            foreach ($addEmbeddedImage as $attachment) {
                $this->addEmbeddedImage(
                    $attachment['filename'],
                    $attachment['content'],
                    $attachment['cid'],
                    $attachment['type'] ?? null,
                    $attachment['disposition'] ?? null
                );
            }
        }

        $this->XMailer = ' ';
        $this->addCustomHeader('X-Priority', '3 (Normal)');
        $this->addCustomHeader('X-Atom', "true");
        $this->addCustomHeader('X-Mailer', 'With Atom Framework');
        $this->addCustomHeader('X-Identity', (string)mt_rand(40000, 44444));
        $this->addCustomHeader('T-Attechment', "true");

        return $this->send();
    }

    /**
     * Send multiple envelopes reusing the same PHPMailer instance (no internal new() per message).
     *
     * Each envelope: [
     *   'to' => ['email' or 'Name <email>' or ['email','name'], ...],
     *   'subject' => string,
     *   'body' => string,
     *   'is_html' => bool (optional),
     *   'from' => 'Name <email>' or ['email','name'] (optional),
     *   'attachments' => [ ['path','name'] , ... ],
     *   'headers' => ['X-Header' => 'value', ...]
     * ]
     *
     * Returns array ['ok'=>int,'failed'=>int]
     */
    public function sendLoop(array $envelopes): array
    {
        $ok = 0;
        $failed = 0;

        // Keep SMTP connection alive across loop if SMTPKeepAlive true
        $keepAlive = $this->SMTPKeepAlive;

        foreach ($envelopes as $env) {
            // reset recipients/attachments
            try {
                $this->clearAddresses();
                $this->clearCCs();
                $this->clearBCCs();
                $this->clearReplyTos();
                $this->clearAllRecipients();
                $this->clearAttachments();
            } catch (\Throwable $e) {
                $this->logger->warning('clear* failed on resetForNext', [ 'exception' => $e]);
            }

            // from
            if (!empty($env['from'])) {
                if (\is_array($env['from'])) {
                    $this->setFrom($env['from'][0] ?? '', $env['from'][1] ?? '');
                } else {
                    [$addr,$name] = $this->splitAddressAndName((string)$env['from']);
                    $this->setFrom($addr, $name);
                }
            }

            // to
            $tos = $env['to'] ?? [];

            if (!\is_array($tos)) {
                $tos = [$tos];
            }

            foreach ($tos as $t) {
                if (\is_array($t)) {
                    $this->addAddress($t[0] ?? '', $t[1] ?? '');
                } else {
                    [$addr,$name] = $this->splitAddressAndName((string)$t);
                    $this->addAddress($addr, $name);
                }
            }

            // headers
            if (!empty($env['headers']) && \is_array($env['headers'])) {
                foreach ($env['headers'] as $hn => $hv) {
                    $this->addCustomHeader($hn, (string)$hv);
                }
            }

            // subject/body
            $this->Subject = (string)($env['subject'] ?? '');
            $isHtml = isset($env['is_html']) ? (bool)$env['is_html'] : false;
            $this->isHTML($isHtml);
            $this->Body = (string)($env['body'] ?? '');

            // attachments
            if (!empty($env['attachments']) && \is_array($env['attachments'])) {
                foreach ($env['attachments'] as $att) {
                    if (!empty($att[0])) {
                        $this->addAttachment($att[0], $att[1] ?? '');
                    }
                }
            }

            // send
            $sent = $this->send();
            if ($sent) {
                $ok++;
            } else {
                $failed++;
            }

            // If keepAlive disabled, close SMTP between messages
            if (!$keepAlive) {
                try {
                    $this->smtpClose();
                } catch (\Throwable $e) {
                    $this->logger->warning('smtpClose failed', ['exception' => $e]);
                }
            }
        }

        // If we kept alive, optionally close at the end (user can call closeSmtp manually)
        if ($keepAlive) {
            try {
                $this->smtpClose();
            } catch (\Throwable $e) {
                $this->logger->warning('smtpClose failed', ['exception' => $e]);
            }
        }

        return ['ok' => $ok, 'failed' => $failed];
    }

    // -------------------------
    // Utility methods
    // -------------------------

    /**
     * Split "Name <email>" or "email" into [email,name]
     */
    protected function splitAddressAndName(string $input): array
    {
        if (preg_match('/^(.*)<(.+?)>$/', $input, $m)) {
            $name = trim($m[1], "\" ' ");
            $email = trim($m[2]);
            return [$email, $name];
        }
        return [trim($input), ''];
    }

    /**
     * Clear recipients and attachments to prepare for next send.
     */
    public function resetForNext(): void
    {
        try {
            $this->clearAddresses();
            $this->clearCCs();
            $this->clearBCCs();
            $this->clearReplyTos();
            $this->clearAllRecipients();
            $this->clearAttachments();
            // clear custom headers if present
            if (method_exists($this, 'clearCustomHeaders')) {
                $this->clearCustomHeaders();
            }
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
     * Helper: return simplified list of recipients for logging
     */
    public function getToAddresses(): array
    {
        $list = [];
        try {
            if (!empty($this->getToAddresses())) {
                // some PHPMailer versions expose getToAddresses method
                $addresses = self::getToAddresses();
                foreach ($addresses as $a) {
                    $list[] = \is_array($a) ? ($a[0] ?? '') : (string)$a;
                }
            } else {
                // fallback: inspect property
                if (property_exists($this, 'to') && is_array($this->to)) {
                    foreach ($this->to as $a) {
                        if (\is_array($a)) {
                            $list[] = $a[0] ?? '';
                        }
                    }
                }
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
