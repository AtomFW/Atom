<?php

declare(strict_types=1);

namespace Atom\Component\Mail;

use Throwable;
use Atom\Mail\MailerDriverInterface;
use Atom\Mail\Driver\PHPMailerDriver;
use Atom\Mail\Driver\SymfonyMailerDriver;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Envelope;
use Psr\Log\LoggerInterface;

/**
 * AdvancedMailer
 *
 * Features:
 * - Primary SMTP sending via PHPMailer (with SMTPKeepAlive support)
 * - Native mail() fallback
 * - SMTP connection pool (per-process, reused PHPMailer instances)
 * - Rate limiting (per-second / per-minute)
 * - Async batch via Symfony Messenger (dispatch MailSendJob messages) if MessageBusInterface provided
 * - Retry on failure with exponential backoff
 * - DKIM auto configuration (for PHPMailer)
 * - Test mode (simulate send = true)
 *
 * Notes:
 * - This class is optimized for reusing PHPMailer instances in long-running processes (workers).
 * - For huge scale use with concurrency, combine with a queue system (Symfony Messenger workers).
 */
final class MailerProxy
{
    /** @var array<int, PHPMailer> SMTP pool (reusable PHPMailer instances) */
    private static array $smtpPool = [];

    /** @var array statistics */
    private array $stats = [
        'sent' => 0,
        'failed' => 0,
        'retried' => 0,
        'dispatched' => 0,
    ];

    /** MessageBus (Symfony Messenger) optional — if provided, we dispatch MailSendJob for async sends */
    private ?MessageBusInterface $bus;

    /** PSR-3 logger optional */
    private ?LoggerInterface $logger;

    /** config */
    private array $options = [
        // SMTP defaults
        'smtp_host' => '127.0.0.1',
        'smtp_port' => 1025, // 25,
        'smtp_user' => null,
        'smtp_pass' => null,
        'smtp_encryption' => 'ssl', // 'ssl'|'tls' or null
        'smtp_auth' => false,
        'smtp_timeout' => 60,
        'smtp_keepalive' => false,

        // pool size (for reuse in-process)
        'pool_size' => 1,

        // rate limiter defaults
        'max_per_second' => 20,
        'max_per_minute' => 1000,

        // retry defaults
        'max_retries' => 3,
        'retry_backoff_seconds' => 2,

        // DKIM
        'dkim' => null, // ['domain'=>..,'selector'=>..,'private_key_file'=>..,'passphrase'=>..]

        // test mode
        'test_mode' => false,

        // native fallback header From
        'force_native_return_path' => false,
    ];

    /** Rate limiter state (in-memory). Structure: ['second' => [ts => count], 'minute' => [minuteKey => count]] */
    private static array $rateState = [
        'second_ts' => 0,
        'second_count' => 0,
        'minute_key' => 0,
        'minute_count' => 0,
    ];


    private MailerDriverInterface $driver;
    private string $backend;
    private array $driverOptions = [];
    private ?string $lastError = null;

    /**
     * Constructor
     *
     * @param MessageBusInterface|null $bus if provided, used for async dispatching (Symfony Messenger)
     * @param LoggerInterface|null $logger optional logger
     * @param array $opts optional overrides for options
     */
    public function __construct(?MessageBusInterface $bus = null, ?LoggerInterface $logger = null, array $opts = [])
    {
        $this->bus = $bus;
        $this->logger = $logger;
        $this->options = \array_merge($this->options, $opts);

        // initialize pool up to pool_size
        $this->ensurePoolSize((int)$this->options['pool_size']);
    }

    /**
     * Switch driver backend. Allowed: 'phpmailer'|'symfony'
     */
    public function setBackend(string $backend): void
    {
        $backend = strtolower($backend);
        $this->backend = $backend;
        if ($backend === 'phpmailer') {
            $this->driver = new PHPMailerDriver();
        } elseif ($backend === 'symfony') {
            $this->driver = new SymfonyMailerDriver($this->driverOptions);
        } else {
            throw new \InvalidArgumentException("Unsupported mail backend: {$backend}");
        }
    }

    // --- PHPMailer-like API methods (delegating to driver)
    public function setCharSet(string $charset): void { $this->driver->setCharSet($charset); }
    public function setFrom(string $address, string $name = ''): void { $this->driver->setFrom($address, $name); }
    public function addAddress(string $address, string $name = ''): void { $this->driver->addAddress($address, $name); }
    public function addReplyTo(string $address, string $name = ''): void { $this->driver->addReplyTo($address, $name); }
    public function addCC(string $address, string $name = ''): void { $this->driver->addCC($address, $name); }
    public function addBCC(string $address, string $name = ''): void { $this->driver->addBCC($address, $name); }
    public function setSubject(string $subject): void { $this->driver->setSubject($subject); }
    public function setBody(string $body): void { $this->driver->setBody($body); }
    public function setAltBody(string $alt): void { $this->driver->setAltBody($alt); }
    public function setIsHtml(bool $isHtml): void { $this->driver->setIsHtml($isHtml); }
    public function addAttachment(string $path, ?string $name = null): void { $this->driver->addAttachment($path, $name); }
    public function clearAddresses(): void { $this->driver->clearAddresses(); }
    public function clearAttachments(): void { $this->driver->clearAttachments(); }
    public function addCustomHeader(string $name, string $value): void { $this->driver->addCustomHeader($name, $value); }
    public function setPriority(int $priority): void { $this->driver->setPriority($priority); }
    public function setReturnPath(string $address): void { $this->driver->setReturnPath($address); }
    public function setDKIM(array $options): void { $this->driver->setDKIM($options); }
    public function setMailerOptions(array $options): void { $this->driver->setMailerOptions($options); }

    /**
     * Convenience: configure SMTP using array keys (host, username, password, port, encryption, smtpAuth, dsn)
     * For PHPMailer driver maps to ->isSMTP() and other props.
     * For Symfony driver builds DSN or uses provided 'dsn'.
     */
    public function configureSMTP(array $config): void
    {
        if ($this->backend === 'phpmailer') {
            $this->driver->setMailerOptions(array_merge($config, ['isSMTP' => true]));
        } else {
            // build DSN when possible: smtp://user:pass@host:port?encryption=ssl|tls
            if (!empty($config['dsn'])) {
                $this->driver->setMailerOptions(['dsn' => $config['dsn']]);
                return;
            }
            $user = $config['username'] ?? $config['user'] ?? null;
            $pass = $config['password'] ?? null;
            $host = $config['host'] ?? 'localhost';
            $port = $config['port'] ?? 25;
            $enc = $config['encryption'] ?? null;
            $auth = isset($config['smtpAuth']) ? (bool)$config['smtpAuth'] : ($user !== null);
            // build dsn
            $creds = $user !== null ? rawurlencode((string)$user) . ':' . rawurlencode((string)$pass) . '@' : '';
            $scheme = $enc === 'ssl' ? 'smtps' : 'smtp';
            $dsn = "{$scheme}://{$creds}{$host}:{$port}";
            $this->driver->setMailerOptions(['dsn' => $dsn]);
        }
    }

    public function send(): bool
    {
        $this->lastError = null;
        $ok = $this->driver->send();
        if (!$ok) {
            $this->lastError = $this->driver->getErrorInfo();
        }
        return $ok;
    }

    public function getErrorInfo(): ?string
    {
        return $this->lastError ?? $this->driver->getErrorInfo();
    }

    // --- helper sugar methods (common)
    public function sendHtml(string $to, string $subject, string $html, string $from = '', array $cc = [], array $bcc = []): bool
    {
        if ($from !== '') {
            [$addr, $name] = $this->splitAddressAndName($from);
            $this->setFrom($addr, $name);
        }
        [$taddr, $tname] = $this->splitAddressAndName($to);
        $this->addAddress($taddr, $tname);
        foreach ($cc as $c) { [$a,$n] = $this->splitAddressAndName($c); $this->addCC($a,$n); }
        foreach ($bcc as $b) { [$a,$n] = $this->splitAddressAndName($b); $this->addBCC($a,$n); }
        $this->setIsHtml(true);
        $this->setSubject($subject);
        $this->setBody($html);
        return $this->send();
    }

    public function sendText(string $to, string $subject, string $text, string $from = ''): bool
    {
        if ($from !== '') {
            [$addr, $name] = $this->splitAddressAndName($from);
            $this->setFrom($addr, $name);
        }
        [$taddr, $tname] = $this->splitAddressAndName($to);
        $this->addAddress($taddr, $tname);
        $this->setIsHtml(false);
        $this->setSubject($subject);
        $this->setBody($text);
        return $this->send();
    }

    private function splitAddressAndName(string $input): array
    {
        // Accept "Name <email@dom>" or "email@dom"
        if (preg_match('/(.*)<(.+?)>/', $input, $m)) {
            $name = trim($m[1], "\" ' ");
            $email = trim($m[2]);
            return [$email, $name];
        }
        return [trim($input), ''];
    }

    //// 

    // ---------------------------
    // Configuration helpers
    // ---------------------------

    public function setOption(string $key, mixed $value): void
    {
        $this->options[$key] = $value;
    }

    public function setSMTPConfig(array $config): void
    {
        foreach (['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_encryption','smtp_auth','smtp_timeout','smtp_keepalive'] as $k) {
            if (array_key_exists($k, $config)) $this->options[$k] = $config[$k];
        }
    }

    public function setRateLimit(int $perSecond, int $perMinute): void
    {
        $this->options['max_per_second'] = $perSecond;
        $this->options['max_per_minute'] = $perMinute;
    }

    public function setRetries(int $maxRetries, int $backoffSeconds = 2): void
    {
        $this->options['max_retries'] = $maxRetries;
        $this->options['retry_backoff_seconds'] = $backoffSeconds;
    }

    public function setOptionDKIM(array $dkimOptions): void
    {
        // validate keys minimally
        $this->options['dkim'] = $dkimOptions;
    }

    public function setTestMode(bool $on = true): void
    {
        $this->options['test_mode'] = $on;
    }

    // ---------------------------
    // Pool management
    // ---------------------------

    private function ensurePoolSize(int $size): void
    {
        $size = max(1, $size);
        $current = \count(self::$smtpPool);
        for ($i = $current; $i < $size; $i++) {
            $mailer = $this->createPHPMailer();
            if ($mailer !== null) {
                self::$smtpPool[] = $mailer;
            }
        }
    }

    private function createPHPMailer(): ?PHPMailer
    {
        try {
            $mail = new PHPMailer(true);
            $mail->SMTPAutoTLS = true;
            $mail->Timeout = (int)$this->options['smtp_timeout'];
            $mail->SMTPKeepAlive = (bool)$this->options['smtp_keepalive'];
            $mail->isSMTP();
            $mail->Host = (string)$this->options['smtp_host'];
            $mail->Port = (int)$this->options['smtp_port'];
            $mail->SMTPAuth = (bool)$this->options['smtp_auth'];
            if (!empty($this->options['smtp_user'])) {
                $mail->Username = (string)$this->options['smtp_user'];
            }
            if (!empty($this->options['smtp_pass'])) {
                $mail->Password = (string)$this->options['smtp_pass'];
            }
            if (!empty($this->options['smtp_encryption'])) {
                $enc = strtolower((string)$this->options['smtp_encryption']);
                if ($enc === 'ssl' || $enc === 'tls') {
                    $mail->SMTPSecure = $enc;
                }
            }
            // set DKIM if configured
            if (!empty($this->options['dkim']) && is_array($this->options['dkim'])) {
                $dk = $this->options['dkim'];
                if (!empty($dk['private_key_file']) && !empty($dk['domain']) && !empty($dk['selector'])) {
                    $mail->DKIM_domain = $dk['domain'];
                    $mail->DKIM_private = $dk['private_key_file'];
                    $mail->DKIM_selector = $dk['selector'];
                    if (!empty($dk['passphrase'])) $mail->DKIM_passphrase = $dk['passphrase'];
                }
            }
            // default charset
            $mail->CharSet = 'UTF-8';
            return $mail;
        } catch (Throwable $e) {
            if ($this->logger) $this->logger->error('createPHPMailer failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Acquire PHPMailer from pool (FIFO). Caller must not new() inside critical loops.
     */
    private function acquireMailer(): ?PHPMailer
    {
        if (empty(self::$smtpPool)) {
            $this->ensurePoolSize((int)$this->options['pool_size']);
        }
        // pop first
        $mailer = array_shift(self::$smtpPool) ?? null;
        if ($mailer === null) {
            // create on demand
            $mailer = $this->createPHPMailer();
        }
        return $mailer;
    }

    /**
     * Release mailer back to pool (keep alive connection if configured).
     */
    private function releaseMailer(?PHPMailer $mailer): void
    {
        if ($mailer === null) return;
        // keep pool bounded
        $max = max(1, (int)$this->options['pool_size']);
        if (count(self::$smtpPool) >= $max) {
            // close SMTP if too many
            try { $mailer->smtpClose(); } catch (Throwable) {}
            return;
        }
        self::$smtpPool[] = $mailer;
    }

    /**
     * Reset pool and close connections.
     */
    public function resetPool(): void
    {
        foreach (self::$smtpPool as $m) {
            try { $m->smtpClose(); } catch (Throwable) {}
        }
        self::$smtpPool = [];
    }

    // ---------------------------
    // Rate limiter
    // ---------------------------

    /**
     * Simple in-memory rate limiter. For multi-process correctness use PSR-6 cache or external store (Redis).
     * Returns true if allowed, false if limit reached.
     */
    private function rateAllow(): bool
    {
        $now = time();
        $sec = (int)$now;
        $minKey = (int)floor($now / 60);

        // second window
        if (self::$rateState['second_ts'] !== $sec) {
            self::$rateState['second_ts'] = $sec;
            self::$rateState['second_count'] = 0;
        }
        if (self::$rateState['minute_key'] !== $minKey) {
            self::$rateState['minute_key'] = $minKey;
            self::$rateState['minute_count'] = 0;
        }

        if (self::$rateState['second_count'] + 1 > (int)$this->options['max_per_second']) {
            return false;
        }
        if (self::$rateState['minute_count'] + 1 > (int)$this->options['max_per_minute']) {
            return false;
        }

        self::$rateState['second_count']++;
        self::$rateState['minute_count']++;
        return true;
    }

    // ---------------------------
    // Sending primitives
    // ---------------------------

    /**
     * Low-level send using PHPMailer instance.
     *
     * @param array $envelope - associative: ['from'=>['email','name'], 'to'=>[['email','name'],...], 'subject'=>string, 'body'=>string, 'is_html'=>bool, 'attachments'=>[['path','name'],...], 'headers'=>['X: v'], 'return_path'=>string|null]
     */
    private function sendViaSMTP(array $envelope, ?PHPMailer $mailer = null): bool
    {
        if ($this->options['test_mode']) {
            // simulate full flow until send
            if ($this->logger) $this->logger->debug('TEST MODE: simulate SMTP send');
            return true;
        }

        $owned = false;
        if ($mailer === null) {
            $mailer = $this->acquireMailer();
            $owned = true;
        }
        if ($mailer === null) {
            $this->stats['failed']++;
            return false;
        }

        // prepare mailer - clear previous recipients/attachments
        try {
            $mailer->clearAddresses();
            $mailer->clearCCs();
            $mailer->clearBCCs();
            $mailer->clearReplyTos();
            $mailer->clearAllRecipients();
            $mailer->clearAttachments();
        } catch (Throwable) {
            // ignore older versions
        }

        // set from
        [$fromEmail, $fromName] = $envelope['from'] ?? ['', ''];
        if ($fromEmail !== '') {
            try {
                if (!empty($fromName)) $mailer->setFrom($fromEmail, $fromName);
                else $mailer->setFrom($fromEmail);
            } catch (Throwable) {}
        }

        // return-path / sender
        if (!empty($envelope['return_path'])) {
            try {
                $mailer->Sender = $envelope['return_path'];
            } catch (Throwable) {}
        }

        // recipients
        foreach ($envelope['to'] ?? [] as $rcpt) {
            [$e,$n] = [$rcpt[0] ?? '', $rcpt[1] ?? ''];
            if ($e === '') continue;
            try { $mailer->addAddress($e, $n); } catch (Throwable) {}
        }
        foreach ($envelope['cc'] ?? [] as $rcpt) {
            [$e,$n] = [$rcpt[0] ?? '', $rcpt[1] ?? ''];
            if ($e === '') continue;
            try { $mailer->addCC($e, $n); } catch (Throwable) {}
        }
        foreach ($envelope['bcc'] ?? [] as $rcpt) {
            [$e,$n] = [$rcpt[0] ?? '', $rcpt[1] ?? ''];
            if ($e === '') continue;
            try { $mailer->addBCC($e, $n); } catch (Throwable) {}
        }

        // subject & body
        $mailer->Subject = (string)($envelope['subject'] ?? '');
        $isHtml = (bool)($envelope['is_html'] ?? false);
        if ($isHtml) {
            $mailer->isHTML(true);
            $mailer->Body = (string)($envelope['body'] ?? '');
            if (!empty($envelope['alt'])) $mailer->AltBody = (string)$envelope['alt'];
        } else {
            $mailer->isHTML(false);
            $mailer->Body = (string)($envelope['body'] ?? '');
        }

        // headers
        foreach ($envelope['headers'] ?? [] as $hn => $hv) {
            try { $mailer->addCustomHeader($hn, $hv); } catch (Throwable) {}
        }

        // attachments
        foreach ($envelope['attachments'] ?? [] as $att) {
            [$path,$name] = [$att[0] ?? '', $att[1] ?? null];
            if ($path === '') continue;
            try { $mailer->addAttachment($path, $name); } catch (Throwable) {}
        }

        // DKIM - PHPMailer already set globally if provided in createPHPMailer
        // send
        try {
            $ok = $mailer->send();
            if ($ok) {
                $this->stats['sent']++;
            } else {
                $this->stats['failed']++;
            }
        } catch (PHPMailerException $e) {
            $ok = false;
            $this->stats['failed']++;
            if ($this->logger) $this->logger->warning('PHPMailerException: ' . $e->getMessage());
        } catch (Throwable $e) {
            $ok = false;
            $this->stats['failed']++;
            if ($this->logger) $this->logger->error('SMTP send failed: ' . $e->getMessage());
        }

        // release or close
        if ($owned) {
            // if keepalive enabled, keep connection, else close it
            if ((bool)$this->options['smtp_keepalive']) {
                $this->releaseMailer($mailer);
            } else {
                try { $mailer->smtpClose(); } catch (Throwable) {}
            }
        }

        return (bool)$ok;
    }

    /**
     * Fallback sending using PHP mail()
     *
     * Note: we build headers and call mail(). Many hosts have limits; prefer SMTP when possible.
     */
    private function sendViaNative(array $envelope): bool
    {
        if ($this->options['test_mode']) {
            if ($this->logger) $this->logger->debug('TEST MODE: simulate native mail() send');
            return true;
        }

        $to = [];
        // Build an array of 'to' email addresses
        // We only care about the first element of each 'to' array
        // (the email address itself), so we ignore any additional elements
        // (typically the recipient's name)
        foreach ($envelope['to'] ?? [] as $recipient) {
            $to[] = \is_array($recipient) ? $recipient[0] : $recipient; // only care about the email address itself
        }
        $toHeader = implode(', ', $to);
        $subject = $envelope['subject'] ?? '';
        $body = $envelope['body'] ?? '';
        $headers = [];

        // From
        [$fromEmail,$fromName] = $envelope['from'] ?? ['', ''];
        if ($fromEmail !== '') {
            $headers[] = 'From: ' . (!empty($fromName) ? sprintf('%s <%s>', $fromName, $fromEmail) : $fromEmail);
        }
        // Reply-To
        if (!empty($envelope['reply_to'])) {
            [$re,$rn] = $envelope['reply_to'];
            $headers[] = 'Reply-To: ' . (!empty($rn) ? sprintf('%s <%s>',$rn,$re) : $re);
        }

        // Custom headers
        foreach ($envelope['headers'] ?? [] as $hn=>$hv) {
            $headers[] = sprintf('%s: %s', $hn, $hv);
        }
        // Content-type
        if (!empty($envelope['is_html'])) {
            $headers[] = 'MIME-Version: 1.0';
            $headers[] = 'Content-type: text/html; charset=utf-8';
        } else {
            $headers[] = 'Content-type: text/plain; charset=utf-8';
        }

        $headerStr = implode("\r\n", $headers);

        // Return-Path parameter on mail() supported by some SAPIs; use -f flag if requested
        $additionalParams = '';
        if (!empty($envelope['return_path'])) {
            $additionalParams = '-f' . escapeshellarg($envelope['return_path']);
        }

        $ok = false;
        try {
            $ok = mail($toHeader, $subject, $body, $headerStr, $additionalParams);
            if ($ok) $this->stats['sent']++; else $this->stats['failed']++;
        } catch (Throwable $e) {
            $ok = false;
            $this->stats['failed']++;
            if ($this->logger) $this->logger->error('native mail() failed: ' . $e->getMessage());
        }

        return (bool)$ok;
    }

    // ---------------------------
    // High-level send API
    // ---------------------------

    /**
     * Send a single email envelope. Envelope structure defined in sendViaSMTP/sendViaNative.
     *
     * If MessageBusInterface provided and $useQueue === true, dispatches MailSendJob to bus instead of direct send.
     * If test_mode enabled, simulate and return true.
     *
     * @param array $envelope
     * @param bool $useQueue
     * @return bool
     */
    public function sendOne(array $envelope, bool $useQueue = false): bool
    {
        // rate limit check
        if (!$this->rateAllow()) {
            // rate limit exceeded — either sleep briefly or return false (we choose to sleep small)
            usleep(250000); // 250ms
            if (!$this->rateAllow()) {
                if ($this->logger) $this->logger->warning('Rate limit exceeded');
                return false;
            }
        }

        // If queue requested and bus available -> dispatch
        if ($useQueue && $this->bus !== null) {
            // create a simple job object (MailSendJob) and dispatch
            $job = new MailSendJob($envelope, ['retries' => $this->options['max_retries']]);
            try {
                $this->bus->dispatch($job);
                $this->stats['dispatched']++;
                return true;
            } catch (Throwable $e) {
                if ($this->logger) $this->logger->error('Dispatch failed: ' . $e->getMessage());
                // fallback to sync send below
            }
        }

        // direct synchronous attempt with retry/backoff
        $attempt = 0;
        $max = (int)$this->options['max_retries'];
        $backoff = (int)$this->options['retry_backoff_seconds'];
        
        while ($attempt <= $max) {
            $attempt++;
            // Acquire mailer
            $mailer = $this->acquireMailer();
            $ok = $this->sendViaSMTP($envelope, $mailer);
var_dump($ok, "sadas s");
            if ($ok) {
                // success
                // ensure mailer remains in pool (release already done inside sendViaSMTP)
                return true;
            }
return true;
            // failed - release mailer and maybe try native fallback
            $this->releaseMailer($mailer);

            // fallback to native mail if SMTP fails and fallback is allowed
            if (!empty($this->options['smtp_auth']) || !empty($this->options['smtp_host'])) {
                // try native fallback only on last attempt
                if ($attempt > $max) {
                    $nativeOk = $this->sendViaNative($envelope);
                    if ($nativeOk) return true;
                }
            }

            // retry/backoff
            if ($attempt <= $max) {
                $this->stats['retried']++;
                $sleep = $backoff * ($attempt); // linear backoff
                if ($this->logger) $this->logger->info("Retry #{$attempt} after {$sleep}s");
                sleep((int)$sleep);
            }
        }

        return false;
    }

    /**
     * Send many envelopes in one call; reuses pool and respects rate limit and retries.
     *
     * @param array $envelopes array of envelope arrays
     * @param bool $useQueue if true and bus available, dispatch as jobs
     * @return array ['ok' => int, 'failed' => int]
     */
    public function sendMany(array $envelopes, bool $useQueue = false): array
    {
        $ok = 0;
        $failed = 0;

        // if async and bus exists -> dispatch all and return
        if ($useQueue && $this->bus !== null) {
            foreach ($envelopes as $env) {
                $job = new MailSendJob($env, ['retries' => $this->options['max_retries']]);
                try {
                    $this->bus->dispatch($job);
                    $this->stats['dispatched']++;
                    $ok++;
                } catch (Throwable $e) {
                    $failed++;
                    if ($this->logger) $this->logger->error('Dispatch failed in sendMany: ' . $e->getMessage());
                }
            }
            return ['ok' => $ok, 'failed' => $failed];
        }

        // synchronous loop - reuse mailer per envelope
        foreach ($envelopes as $env) {
            $r = $this->sendOne($env, false);
            if ($r) $ok++; else $failed++;
        }

        return ['ok' => $ok, 'failed' => $failed];
    }

    // ---------------------------
    // Utility / info
    // ---------------------------

    public function getStats(): array
    {
        return $this->stats;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function resetRateState(): void
    {
        self::$rateState = ['second_ts'=>0,'second_count'=>0,'minute_key'=>0,'minute_count'=>0];
    }
}
