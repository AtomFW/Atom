<?php

declare(strict_types=1);

namespace Atom\Component\Mail\Driver;

use Atom\Component\Mail\Interface\MailerDriverInterface;
use SensitiveParameter;
use Throwable;

/**
 * Symfony Mailer-backed MailerDriverInterface implementation.
 */
final class SymfonyMailerDriver implements MailerDriverInterface
{
    /** @var array<string, mixed> */
    private array $data = [];
    private ?string $error = null;
    private ?object $mailer = null;
    private ?object $transport = null;
    private ?object $email = null;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(#[SensitiveParameter] array $options = [])
    {
        if (\class_exists('Symfony\\Component\\Mime\\Email')) {
            $emailClass = 'Symfony\\Component\\Mime\\Email';
            $this->email = new $emailClass();
        }

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

        $dsn = $options['dsn']
            ?? $options['MAILER_DSN']
            ?? (\function_exists('env') ? \env('MAILER_DSN', 'smtp://localhost') : 'smtp://localhost');

        if (\is_string($dsn) && $dsn !== '' && \class_exists('Symfony\\Component\\Mailer\\Transport')) {
            $this->initTransport($dsn);
        }
    }

    private function initTransport(#[SensitiveParameter] string $dsn): void
    {
        try {
            $transportClass = (string) ($this->data['transportClass'] ?? 'Symfony\\Component\\Mailer\\Transport');
            $mailerClass = (string) ($this->data['mailerClass'] ?? 'Symfony\\Component\\Mailer\\Mailer');
            if (!\class_exists($transportClass) || !\class_exists($mailerClass) || !\is_callable([$transportClass, 'fromDsn'])) {
                return;
            }
            $this->transport = ($transportClass . '::fromDsn')($dsn);
            $this->mailer = (new \ReflectionClass($mailerClass))->newInstance($this->transport);
        } catch (Throwable) {
            $this->transport = null;
            $this->mailer = null;
        }
    }

    #[\Override]
    public function setCharSet(string $charset): void
    {
        if ($this->email !== null && \method_exists($this->email, 'charset')) {
            $this->email->charset($charset);
        }
    }

    #[\Override]
    public function setFrom(string $address, string $name = ''): void
    {
        $this->data['from'] = [$address, $name];
        if ($this->email !== null) {
            $this->email->from($name === '' ? $address : \sprintf('%s <%s>', $name, $address));
        }
    }

    #[\Override]
    public function addAddress(string $address, string $name = ''): void
    {
        $this->data['to'][] = [$address, $name];
        if ($this->email !== null) {
            $this->email->addTo($name === '' ? $address : \sprintf('%s <%s>', $name, $address));
        }
    }

    #[\Override]
    public function addReplyTo(string $address, string $name = ''): void
    {
        $this->data['replyTo'] = [$address, $name];
        if ($this->email !== null) {
            $this->email->replyTo($name === '' ? $address : \sprintf('%s <%s>', $name, $address));
        }
    }

    #[\Override]
    public function addCC(string $address, string $name = ''): void
    {
        $this->data['cc'][] = [$address, $name];
        if ($this->email !== null) {
            $this->email->addCc($name === '' ? $address : \sprintf('%s <%s>', $name, $address));
        }
    }

    #[\Override]
    public function addBCC(string $address, string $name = ''): void
    {
        $this->data['bcc'][] = [$address, $name];
        if ($this->email !== null) {
            $this->email->addBcc($name === '' ? $address : \sprintf('%s <%s>', $name, $address));
        }
    }

    #[\Override]
    public function setSubject(string $subject): void
    {
        $this->data['subject'] = $subject;
        if ($this->email !== null) {
            $this->email->subject($subject);
        }
    }

    #[\Override]
    public function setBody(string $body): void
    {
        $this->data['body'] = $body;
        if ($this->email !== null) {
            if ($this->data['isHtml']) {
                $this->email->html($body);
            } else {
                $this->email->text($body);
            }
        }
    }

    #[\Override]
    public function setAltBody(string $alt): void
    {
        $this->data['alt'] = $alt;
        if ($this->email !== null) {
            $this->email->text($alt);
        }
    }

    #[\Override]
    public function setIsHtml(bool $isHtml): void
    {
        $this->data['isHtml'] = $isHtml;
        if (\is_string($this->data['body']) && $this->data['body'] !== '') {
            $this->setBody($this->data['body']);
        }
    }

    #[\Override]
    public function addAttachment(string $path, ?string $name = null): void
    {
        $this->data['attachments'][] = [$path, $name];
        if ($this->email !== null) {
            if ($name === null) {
                $this->email->attachFromPath($path);
            } else {
                $this->email->attachFromPath($path, $name);
            }
        }
    }

    #[\Override]
    public function clearAddresses(): void
    {
        if (\class_exists('Symfony\\Component\\Mime\\Email')) {
            $emailClass = 'Symfony\\Component\\Mime\\Email';
            $this->email = new $emailClass();
        }
        $this->data['to'] = [];
        $this->data['cc'] = [];
        $this->data['bcc'] = [];
    }

    #[\Override]
    public function clearAttachments(): void
    {
        $existing = $this->data;
        if (\class_exists('Symfony\\Component\\Mime\\Email')) {
            $emailClass = 'Symfony\\Component\\Mime\\Email';
            $this->email = new $emailClass();
        }
        $this->data['attachments'] = [];

        if (\is_array($existing['from']) && isset($existing['from'][0])) {
            $this->setFrom((string) $existing['from'][0], (string) ($existing['from'][1] ?? ''));
        }
        if (\is_string($existing['subject']) && $existing['subject'] !== '') {
            $this->setSubject($existing['subject']);
        }
        if (\is_string($existing['body']) && $existing['body'] !== '') {
            $this->setBody($existing['body']);
        }
    }

    #[\Override]
    public function addCustomHeader(string $name, string $value): void
    {
        $this->data['headers'][$name] = $value;
        if ($this->email !== null) {
            $this->email->getHeaders()->addTextHeader($name, $value);
        }
    }

    #[\Override]
    public function setPriority(int $priority): void
    {
        $clamped = \clamp($priority, 1, 5);
        $this->data['priority'] = $clamped;
        if ($this->email !== null) {
            $this->email->getHeaders()->addTextHeader('X-Priority', (string) $clamped);
        }
    }

    #[\Override]
    public function setReturnPath(string $address): void
    {
        $this->data['returnPath'] = $address;
        if ($this->email !== null) {
            $this->email->getHeaders()->addMailboxHeader('Return-Path', $address);
        }
    }

    #[\Override]
    public function setDKIM(#[SensitiveParameter] array $options): void
    {
        $this->data['dkim'] = $options;
    }

    #[\Override]
    public function setMailerOptions(#[SensitiveParameter] array $options): void
    {
        if (!empty($options['dsn']) && \is_string($options['dsn']) && \class_exists('Symfony\\Component\\Mailer\\Transport')) {
            $this->initTransport($options['dsn']);
        }
    }

    #[\Override]
    public function send(): bool
    {
        $this->error = null;

        if ($this->mailer === null || $this->email === null) {
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

    #[\Override]
    public function getErrorInfo(): ?string
    {
        return $this->error;
    }
}

if (!\class_exists('Atom\\Mail\\Driver\\SymfonyMailerDriver', false)) {
    \class_alias(SymfonyMailerDriver::class, 'Atom\\Mail\\Driver\\SymfonyMailerDriver');
}
