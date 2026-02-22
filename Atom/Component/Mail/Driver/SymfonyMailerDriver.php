<?php

declare(strict_types=1);

namespace Atom\Mail\Driver;

use Throwable;
use Atom\Mail\MailerDriverInterface;

// -------------------------
// Symfony Mailer driver
// -------------------------
class SymfonyMailerDriver implements MailerDriverInterface
{
    private array $data = [];
    private ?string $error = null;
    private ?\Symfony\Component\Mailer\MailerInterface $mailer = null;
    private ?\Symfony\Component\Mailer\Transport\TransportInterface $transport = null;
    private ?\Symfony\Component\Mime\Email $email = null;

    public function __construct(array $options = [])
    {
        // $options may contain 'dsn' (MAILER_DSN) or direct transport config
        $this->email = new \Symfony\Component\Mime\Email();
        $this->data = [
            'from' => null,
            'to' => [],
            'cc' => [],
            'bcc' => [],
            'replyTo' => null,
            'subject' => null,
            'body' => null,
            'alt' => null,
            'isHtml' => false,
            'attachments' => [],
            'headers' => [],
            'returnPath' => null,
            'priority' => null,
        ];

        // transport: prefer explicit 'dsn' in options, else expect that Transport::fromDsn will be able to load from env
        $dsn = $options['dsn'] ?? $options['MAILER_DSN'] ?? null;
        if ($dsn !== null) {
            try {
                $this->transport = \Symfony\Component\Mailer\Transport::fromDsn($dsn);
                $this->mailer = new \Symfony\Component\Mailer\Mailer($this->transport);
            } catch (Throwable) {
                // leave null — send() will fail gracefully
                $this->transport = null;
                $this->mailer = null;
            }
        } else {
            // try default: application should have provided Mailer via DI; attempt to construct Transport from env MAILER_DSN
            try {
                $this->transport = \Symfony\Component\Mailer\Transport::fromDsn($_ENV['MAILER_DSN'] ?? ($_SERVER['MAILER_DSN'] ?? 'smtp://localhost'));
                $this->mailer = new \Symfony\Component\Mailer\Mailer($this->transport);
            } catch (Throwable) {
                $this->transport = null;
                $this->mailer = null;
            }
        }
    }

    public function setCharSet(string $charset): void
    {
        // Symfony Email uses UTF-8 by default; set charset on headers if necessary
        $this->email->charset($charset);
    }

    public function setFrom(string $address, string $name = ''): void
    {
        $this->data['from'] = [$address, $name];
        if ($name === '') {
            $this->email->from($address);
        } else {
            $this->email->from(sprintf('%s <%s>', $name, $address));
        }
    }

    public function addAddress(string $address, string $name = ''): void
    {
        $this->data['to'][] = [$address, $name];
        if ($name === '') $this->email->to($address);
        else $this->email->to(sprintf('%s <%s>', $name, $address));
    }

    public function addReplyTo(string $address, string $name = ''): void
    {
        $this->data['replyTo'] = [$address, $name];
        if ($name === '') $this->email->replyTo($address);
        else $this->email->replyTo(sprintf('%s <%s>', $name, $address));
    }

    public function addCC(string $address, string $name = ''): void
    {
        $this->data['cc'][] = [$address, $name];
        if ($name === '') $this->email->cc($address);
        else $this->email->cc(sprintf('%s <%s>', $name, $address));
    }

    public function addBCC(string $address, string $name = ''): void
    {
        $this->data['bcc'][] = [$address, $name];
        if ($name === '') $this->email->bcc($address);
        else $this->email->bcc(sprintf('%s <%s>', $name, $address));
    }

    public function setSubject(string $subject): void
    {
        $this->data['subject'] = $subject;
        $this->email->subject($subject);
    }

    public function setBody(string $body): void
    {
        $this->data['body'] = $body;
        if ($this->data['isHtml']) {
            $this->email->html($body);
        } else {
            $this->email->text($body);
        }
    }

    public function setAltBody(string $alt): void
    {
        $this->data['alt'] = $alt;
        if ($this->data['isHtml']) {
            $this->email->text($alt);
        } else {
            $this->email->text($alt);
        }
    }

    public function setIsHtml(bool $isHtml): void
    {
        $this->data['isHtml'] = $isHtml;
        // ensure body is set accordingly on next setBody call
        if (!empty($this->data['body'])) {
            $this->setBody($this->data['body']);
        }
    }

    public function addAttachment(string $path, ?string $name = null): void
    {
        $this->data['attachments'][] = [$path, $name];
        if ($name === null) {
            $this->email->attachFromPath($path);
        } else {
            $this->email->attachFromPath($path, $name);
        }
    }

    public function clearAddresses(): void
    {
        // Symfony Email is immutable-like; can't remove specific entries easily — recreate email
        $this->email = new \Symfony\Component\Mime\Email();
        // re-apply body/charset if present
        $this->data['to'] = [];
        $this->data['cc'] = [];
        $this->data['bcc'] = [];
    }

    public function clearAttachments(): void
    {
        // Symfony Email does not expose remove attachment, recreate and reapply headers/body
        $existing = $this->data;
        $this->email = new \Symfony\Component\Mime\Email();
        $this->data['attachments'] = [];
        // reapply from/subject/body
        if (!empty($existing['from'])) {
            [$addr, $nm] = $existing['from'];
            $this->setFrom($addr, $nm);
        }
        if (!empty($existing['subject'])) {
            $this->setSubject($existing['subject']);
        }
        if (!empty($existing['body'])) {
            $this->setBody($existing['body']);
        }
    }

    public function addCustomHeader(string $name, string $value): void
    {
        $this->data['headers'][$name] = $value;
        $this->email->getHeaders()->addTextHeader($name, $value);
    }

    public function setPriority(int $priority): void
    {
        // map priority 1..5 to X-Priority header
        $this->data['priority'] = $priority;
        $this->email->getHeaders()->addTextHeader('X-Priority', (string)$priority);
    }

    public function setReturnPath(string $address): void
    {
        $this->data['returnPath'] = $address;
        // Symfony Email supports return-path via header
        $this->email->getHeaders()->addMailboxHeader('Return-Path', $address);
    }

    public function setDKIM(array $options): void
    {
        // Symfony Mailer does not provide built-in DKIM signer by default in all setups.
        // You may implement signing via Mime/Signer or use third-party packages.
        // We provide no-op here but store options for potential external signer.
        $this->data['dkim'] = $options;
    }

    public function setMailerOptions(array $options): void
    {
        // allow setting DSN for transport or replacing Mailer
        if (!empty($options['dsn'])) {
            try {
                $this->transport = \Symfony\Component\Mailer\Transport::fromDsn($options['dsn']);
                $this->mailer = new \Symfony\Component\Mailer\Mailer($this->transport);
            } catch (Throwable) {
                $this->mailer = null;
                $this->transport = null;
            }
        }
    }

    public function send(): bool
    {
        $this->error = null;

        if ($this->mailer === null) {
            $this->error = 'Symfony Mailer not initialized (no transport).';
            return false;
        }

        try {
            $this->mailer->send($this->email);
            return true;
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function getErrorInfo(): ?string
    {
        return $this->error;
    }
}