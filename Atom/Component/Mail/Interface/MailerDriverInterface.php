<?php

declare(strict_types=1);

namespace Atom\Component\Mail\Interface;

use SensitiveParameter;

/**
 * Unified mail transport driver contract.
 */
interface MailerDriverInterface
{
    public function setCharSet(string $charset): void;
    public function setFrom(string $address, string $name = ''): void;
    public function addAddress(string $address, string $name = ''): void;
    public function addReplyTo(string $address, string $name = ''): void;
    public function addCC(string $address, string $name = ''): void;
    public function addBCC(string $address, string $name = ''): void;
    public function setSubject(string $subject): void;
    public function setBody(string $body): void;
    public function setAltBody(string $alt): void;
    public function setIsHtml(bool $isHtml): void;
    public function addAttachment(string $path, ?string $name = null): void;
    public function clearAddresses(): void;
    public function clearAttachments(): void;
    public function addCustomHeader(string $name, string $value): void;
    public function setPriority(int $priority): void;
    public function setReturnPath(string $address): void;

    /**
     * @param array<string, mixed> $options
     */
    public function setDKIM(#[SensitiveParameter] array $options): void;

    /**
     * @param array<string, mixed> $options
     */
    public function setMailerOptions(#[SensitiveParameter] array $options): void;

    public function send(): bool;
    public function getErrorInfo(): ?string;
}

if (!\interface_exists('Atom\\Mail\\MailerDriverInterface', false)) {
    \class_alias(MailerDriverInterface::class, 'Atom\\Mail\\MailerDriverInterface');
}
