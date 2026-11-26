<?php

declare(strict_types=1);

namespace Atom\Component\Task\Handlers;

use Atom\Component\Mail\Interface\MailerDriverInterface;
use Atom\Component\Task\Tasks\EmailTask;

/**
 * Stateless task handler for asynchronous EmailTask delivery.
 * Writes fallback audit logs strictly inside runtime/log/.
 */
final readonly class EmailHandler
{
    public function __construct(
        private ?MailerDriverInterface $mailer = null,
        private ?string $logFile = null,
    ) {}

    public function __invoke(EmailTask $task): void
    {
        if ($this->mailer !== null) {
            $this->mailer->clearAddresses();
            if ($task->from !== null && $task->from !== '') {
                $this->mailer->setFrom($task->from);
            }
            $this->mailer->addAddress($task->to);
            $this->mailer->setSubject($task->subject);
            $this->mailer->setBody($task->body);
            $this->mailer->send();
            return;
        }

        $targetLog = $this->logFile ?? (
            \dirname(__DIR__, 4)
            . \DIRECTORY_SEPARATOR . 'runtime'
            . \DIRECTORY_SEPARATOR . 'log'
            . \DIRECTORY_SEPARATOR . 'email.log'
        );

        $logDir = \dirname($targetLog);
        if (!\is_dir($logDir)) {
            \mkdir($logDir, 0750, true);
        }

        $line = \sprintf("[%s] Send to %s subject=%s\n", \date(\DATE_ATOM), $task->to, $task->subject);
        if (\file_put_contents($targetLog, $line, \FILE_APPEND | \LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write email task log to {$targetLog}");
        }
    }
}
