<?php

declare(strict_types=1);

namespace Atom\Exception\Interface\Generative;

interface GenerativeExceptionInterface
{
    public function __construct(string $format, ...$params);
}

// abstract class Generative implements GenerativeInterface, \Throwable
// {
//     private string $message;
//     private int $code = 0;
//     private ?\Throwable $previous = null;
//     private array $trace = [];
//     private string $file = '';
//     private int $line = 0;

//     public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
//     {
//         $this->message = $message;
//         $this->code = $code;
//         $this->previous = $previous;

//         $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
//         array_shift($trace); // remove current constructor frame
//         $this->trace = $trace;

//         if (isset($trace[0]['file'])) {
//             $this->file = $trace[0]['file'];
//             $this->line = $trace[0]['line'] ?? 0;
//         }
//     }

//     public function getMessage(): string { return $this->message; }
//     public function getCode(): int { return $this->code; }
//     public function getFile(): string { return $this->file; }
//     public function getLine(): int { return $this->line; }
//     public function getTrace(): array { return $this->trace; }
//     public function getPrevious(): ?\Throwable { return $this->previous; }
//     public function getTraceAsString(): string { return ''; }

//     public function __toString(): string
//     {
//         return static::class . ": [{$this->code}]: {$this->message}\n";
//     }
// }
