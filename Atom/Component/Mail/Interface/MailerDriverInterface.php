<?php

declare(strict_types=1);

namespace Atom\Mail;

/*
 * MailerProxy
 *
 * - Wrapper that exposes a PHPMailer-like API while delegating actual sending to a driver.
 * - Supported drivers: "phpmailer" (PHPMailer\PHPMailer\PHPMailer), "symfony" (Symfony Mailer).
 * - You can swap driver at runtime via setBackend('phpmailer'|'symfony') or pass desired backend into constructor.
 */

// --- driver interface
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
    public function setDKIM(array $options): void;
    public function setMailerOptions(array $options): void;
    public function send(): bool;
    public function getErrorInfo(): ?string;
}
