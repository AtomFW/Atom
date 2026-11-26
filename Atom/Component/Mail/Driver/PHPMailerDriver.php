<?php

declare(strict_types=1);

namespace Atom\Component\Mail\Driver;

use Atom\Component\Mail\Interface\MailerDriverInterface;
use PHPMailer\PHPMailer\PHPMailer;
use SensitiveParameter;
use Throwable;

/**
 * PHPMailer-backed MailerDriverInterface implementation.
 */
final class PHPMailerDriver implements MailerDriverInterface
{
    private PHPMailer $mail;
    private ?string $error = null;

    public function __construct()
    {
        $this->mail = new PHPMailer(true);
        $this->mail->CharSet = 'UTF-8';
        $this->mail->SMTPAutoTLS = true;
        $this->mail->SMTPKeepAlive = false;
        $this->mail->Timeout = 60;
    }

    #[\Override]
    public function setCharSet(string $charset): void
    {
        $this->mail->CharSet = $charset;
    }

    #[\Override]
    public function setFrom(string $address, string $name = ''): void
    {
        $this->mail->setFrom($address, $name);
    }

    #[\Override]
    public function addAddress(string $address, string $name = ''): void
    {
        $this->mail->addAddress($address, $name);
    }

    #[\Override]
    public function addReplyTo(string $address, string $name = ''): void
    {
        $this->mail->addReplyTo($address, $name);
    }

    #[\Override]
    public function addCC(string $address, string $name = ''): void
    {
        $this->mail->addCC($address, $name);
    }

    #[\Override]
    public function addBCC(string $address, string $name = ''): void
    {
        $this->mail->addBCC($address, $name);
    }

    #[\Override]
    public function setSubject(string $subject): void
    {
        $this->mail->Subject = $subject;
    }

    #[\Override]
    public function setBody(string $body): void
    {
        $this->mail->Body = $body;
    }

    #[\Override]
    public function setAltBody(string $alt): void
    {
        $this->mail->AltBody = $alt;
    }

    #[\Override]
    public function setIsHtml(bool $isHtml): void
    {
        $this->mail->isHTML($isHtml);
    }

    #[\Override]
    public function addAttachment(string $path, ?string $name = null): void
    {
        $this->mail->addAttachment($path, $name ?? '');
    }

    #[\Override]
    public function clearAddresses(): void
    {
        $this->mail->clearAddresses();
        $this->mail->clearCCs();
        $this->mail->clearBCCs();
    }

    #[\Override]
    public function clearAttachments(): void
    {
        $this->mail->clearAttachments();
    }

    #[\Override]
    public function addCustomHeader(string $name, string $value): void
    {
        $this->mail->addCustomHeader($name, $value);
    }

    #[\Override]
    public function setPriority(int $priority): void
    {
        $this->mail->Priority = \clamp($priority, 1, 5);
    }

    #[\Override]
    public function setReturnPath(string $address): void
    {
        $this->mail->Sender = $address;
    }

    #[\Override]
    public function setDKIM(#[SensitiveParameter] array $options): void
    {
        if (!empty($options['private_key_file']) && !empty($options['domain']) && !empty($options['selector'])) {
            $this->mail->DKIM_domain = (string) $options['domain'];
            $this->mail->DKIM_private = (string) $options['private_key_file'];
            $this->mail->DKIM_selector = (string) $options['selector'];
            if (!empty($options['passphrase'])) {
                $this->mail->DKIM_passphrase = (string) $options['passphrase'];
            }
        }
    }

    #[\Override]
    public function setMailerOptions(#[SensitiveParameter] array $options): void
    {
        if (($options['isSMTP'] ?? false) === true) {
            $this->mail->isSMTP();
        }
        if (!empty($options['host'])) {
            $this->mail->Host = (string) $options['host'];
        }
        if (isset($options['smtpAuth'])) {
            $this->mail->SMTPAuth = (bool) $options['smtpAuth'];
        }
        if (!empty($options['username'])) {
            $this->mail->Username = (string) $options['username'];
        }
        if (!empty($options['password'])) {
            $this->mail->Password = (string) $options['password'];
        }
        if (!empty($options['port'])) {
            $this->mail->Port = \clamp((int) $options['port'], 1, 65535);
        }
        if (!empty($options['encryption'])) {
            $this->mail->SMTPSecure = (string) $options['encryption'];
        }
        if (isset($options['timeout'])) {
            $this->mail->Timeout = \clamp((int) $options['timeout'], 1, 3600);
        }
        if (isset($options['smtpOptions']) && \is_array($options['smtpOptions'])) {
            $this->mail->SMTPOptions = $options['smtpOptions'];
        }
        if (!empty($options['debug'])) {
            $this->mail->SMTPDebug = (int) $options['debug'];
        }
    }

    #[\Override]
    public function send(): bool
    {
        $this->error = null;
        try {
            return (bool) $this->mail->send();
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    #[\Override]
    public function getErrorInfo(): ?string
    {
        return $this->error ?? ($this->mail->ErrorInfo !== '' ? $this->mail->ErrorInfo : null);
    }
}

if (!\class_exists('Atom\\Mail\\Driver\\PHPMailerDriver', false)) {
    \class_alias(PHPMailerDriver::class, 'Atom\\Mail\\Driver\\PHPMailerDriver');
}
