<?php

declare(strict_types=1);

namespace Atom\Mail;

use Throwable;
use Atom\Mail\MailerDriverInterface;
use Atom\Mail\Driver\PHPMailerDriver;
use Atom\Mail\Driver\SymfonyMailerDriver;

// -------------------------
// Proxy class (public API)
// -------------------------
final class MailerProxy
{
    private MailerDriverInterface $driver;
    private string $backend;
    private array $driverOptions = [];
    private ?string $lastError = null;

    /**
     * @param string $backend 'phpmailer' (default) or 'symfony'
     * @param array $driverOptions options passed to driver constructor
     */
    public function __construct(string $backend = 'phpmailer', array $driverOptions = [])
    {
        $this->driverOptions = $driverOptions;
        $this->setBackend($backend);
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
}
