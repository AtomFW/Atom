<?php

declare(strict_types=1);

namespace Atom\Terminal;

use Time\Duration;

use function clamp;
use function fwrite;
use function hrtime;
use function is_resource;
use function round;
use function sprintf;
use function str_repeat;

/**
 * ANSI-capable CLI progress bar with nanosecond elapsed timing (`\Time\Duration`).
 */
final class ProgressBar
{
    private int $total;
    private int $current = 0;
    private int $width;
    private int $startedAtNs;

    /** @var resource|null */
    private mixed $stream;
    private bool $colors;
    private string $buffer = '';

    /**
     * @param resource|null $stream Optional writable stream (`null` buffers in memory only)
     */
    public function __construct(
        int $total,
        int $width = 28,
        mixed $stream = null,
        bool $colors = true,
    ) {
        $this->total = clamp($total, 1, 1_000_000_000);
        $this->width = clamp($width, 8, 100);
        $this->stream = is_resource($stream) ? $stream : null;
        $this->colors = $colors;
        $this->startedAtNs = hrtime(true);
    }

    public function start(string $label = ''): string
    {
        $this->startedAtNs = hrtime(true);
        $this->current = 0;
        return $this->emit($label, newline: false);
    }

    public function advance(int $step = 1, string $label = ''): string
    {
        $safeStep = clamp($step, 0, $this->total);
        $this->current = clamp($this->current + $safeStep, 0, $this->total);
        return $this->emit($label, newline: false);
    }

    public function setProgress(int $current, string $label = ''): string
    {
        $this->current = clamp($current, 0, $this->total);
        return $this->emit($label, newline: false);
    }

    public function finish(string $label = 'Completed'): string
    {
        $this->current = $this->total;
        return $this->emit($label, newline: true);
    }

    public function getCurrent(): int
    {
        return $this->current;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function getBuffer(): string
    {
        return $this->buffer;
    }

    public function elapsedDuration(): Duration
    {
        $elapsedNs = clamp(hrtime(true) - $this->startedAtNs, 0, \PHP_INT_MAX);
        $seconds = \intdiv($elapsedNs, 1_000_000_000);
        $nanos = $elapsedNs % 1_000_000_000;

        return new Duration($seconds, $nanos);
    }

    public function renderString(?int $current = null, string $label = ''): string
    {
        $cur = $current !== null ? clamp($current, 0, $this->total) : $this->current;
        $ratio = (float) $cur / (float) $this->total;
        $filled = (int) round($ratio * $this->width);
        $filled = clamp($filled, 0, $this->width);
        $empty = $this->width - $filled;
        $percent = (int) round($ratio * 100);

        $elapsedMs = round((hrtime(true) - $this->startedAtNs) / 1_000_000, 1);

        $filledBar = str_repeat('█', $filled);
        $emptyBar = str_repeat('░', $empty);

        if ($this->colors) {
            $barColor = $percent >= 100 ? "\033[32m" : "\033[36m";
            $barVisual = "{$barColor}{$filledBar}\033[90m{$emptyBar}\033[0m";
            $pctVisual = sprintf("\033[1m%3d%%\033[0m", $percent);
            $timeVisual = sprintf("\033[90m%.1fms\033[0m", $elapsedMs);
        } else {
            $barVisual = $filledBar . $emptyBar;
            $pctVisual = sprintf('%3d%%', $percent);
            $timeVisual = sprintf('%.1fms', $elapsedMs);
        }

        $suffix = $label !== '' ? ' — ' . $label : '';

        return sprintf(
            '[%s] %s (%d/%d) %s%s',
            $barVisual,
            $pctVisual,
            $cur,
            $this->total,
            $timeVisual,
            $suffix,
        );
    }

    private function emit(string $label, bool $newline): string
    {
        $line = $this->renderString($this->current, $label);
        $formatted = ($this->stream !== null ? "\r" : '') . $line . ($newline ? \PHP_EOL : '');
        $this->buffer .= $line . \PHP_EOL;

        if ($this->stream !== null) {
            fwrite($this->stream, $formatted);
        }

        return $line;
    }
}
