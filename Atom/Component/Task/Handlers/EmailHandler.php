<?php
declare(strict_types=1);

namespace Atom\Component\Task\Handlers;

use Atom\Component\Task\Tasks\EmailTask;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Example handler that would send email.
 *
 * In a real app inject Mailer service. Here we only simulate send.
 */
#[AsMessageHandler]
final class EmailHandler
{
    public function __invoke(EmailTask $task): void
    {
        // TODO: integrate with mailer (e.g. Symfony Mailer or external API)
        // For demo: write to log / simulate
        file_put_contents(__DIR__.'/../../var/email.log', sprintf("[%s] Send to %s subject=%s\n", date('c'), $task->to, $task->subject), FILE_APPEND);
    }
}