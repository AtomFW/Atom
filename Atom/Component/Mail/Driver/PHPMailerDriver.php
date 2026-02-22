<?php

declare(strict_types=1);

namespace Atom\Mail\Driver;

use Throwable;
use Atom\Mail\MailerDriverInterface;

// -------------------------
// PHPMailer driver
// -------------------------
class PHPMailerDriver implements MailerDriverInterface
{
    private \PHPMailer\PHPMailer\PHPMailer $mail;
    private ?string $error = null;

    public function __construct()
    {
        // assumes Composer autoload; will throw if missing
        $this->mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        // defaults
        $this->mail->CharSet = 'UTF-8';
        $this->mail->SMTPAutoTLS = true;
        $this->mail->SMTPKeepAlive = false;
        $this->mail->Timeout = 60;
    }

    public function setCharSet(string $charset): void { $this->mail->CharSet = $charset; }
    public function setFrom(string $address, string $name = ''): void { $this->mail->setFrom($address, $name); }
    public function addAddress(string $address, string $name = ''): void { $this->mail->addAddress($address, $name); }
    public function addReplyTo(string $address, string $name = ''): void { $this->mail->addReplyTo($address, $name); }
    public function addCC(string $address, string $name = ''): void { $this->mail->addCC($address, $name); }
    public function addBCC(string $address, string $name = ''): void { $this->mail->addBCC($address, $name); }
    public function setSubject(string $subject): void { $this->mail->Subject = $subject; }
    public function setBody(string $body): void { $this->mail->Body = $body; }
    public function setAltBody(string $alt): void { $this->mail->AltBody = $alt; }
    public function setIsHtml(bool $isHtml): void { $this->mail->isHTML($isHtml); }
    public function addAttachment(string $path, ?string $name = null): void { $this->mail->addAttachment($path, $name ?? ''); }
    public function clearAddresses(): void { $this->mail->clearAddresses(); $this->mail->clearCCs(); $this->mail->clearBCCs(); }
    public function clearAttachments(): void { $this->mail->clearAttachments(); }
    public function addCustomHeader(string $name, string $value): void { $this->mail->addCustomHeader($name, $value); }
    public function setPriority(int $priority): void { $this->mail->Priority = $priority; }
    public function setReturnPath(string $address): void { $this->mail->Sender = $address; }
    public function setDKIM(array $options): void
    {
        // options: ['domain' => '', 'selector' => '', 'private_key_file' => '', 'passphrase' => '']
        if (!empty($options['private_key_file']) && !empty($options['domain']) && !empty($options['selector'])) {
            $this->mail->DKIM_domain = $options['domain'];
            $this->mail->DKIM_private = $options['private_key_file'];
            $this->mail->DKIM_selector = $options['selector'];
            if (!empty($options['passphrase'])) {
                $this->mail->DKIM_passphrase = $options['passphrase'];
            }
        }
    }

    public function setMailerOptions(array $options): void
    {
        // map common PHPMailer settings
        if (isset($options['isSMTP']) && $options['isSMTP'] === true) {
            $this->mail->isSMTP();
        }
        if (!empty($options['host'])) $this->mail->Host = $options['host'];
        if (isset($options['smtpAuth'])) $this->mail->SMTPAuth = (bool)$options['smtpAuth'];
        if (!empty($options['username'])) $this->mail->Username = $options['username'];
        if (!empty($options['password'])) $this->mail->Password = $options['password'];
        if (!empty($options['port'])) $this->mail->Port = (int)$options['port'];
        if (!empty($options['encryption'])) $this->mail->SMTPSecure = $options['encryption'];
        if (isset($options['timeout'])) $this->mail->Timeout = (int)$options['timeout'];
        if (isset($options['smtpOptions']) && is_array($options['smtpOptions'])) {
            $this->mail->SMTPOptions = $options['smtpOptions'];
        }
        if (isset($options['debug']) && $options['debug']) {
            $this->mail->SMTPDebug = (int)$options['debug'];
        }
    }

    public function send(): bool
    {
        $this->error = null;
        try {
            $res = $this->mail->send();
            return (bool)$res;
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            return false;
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function getErrorInfo(): ?string
    {
        if ($this->error !== null) return $this->error;
        // fallback to PHPMailer->ErrorInfo
        return $this->mail->ErrorInfo ?? null;
    }
}