<?php

declare(strict_types=1);

namespace Atom\Component\WebPush;

use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush as NativeWebPush;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Thin compatibility layer over minishlink/web-push with typed payload builders.
 */
final class WebPushAdapter
{
    private NativeWebPush $inner;

    public function __construct(#[SensitiveParameter] mixed ...$args)
    {
        $this->inner = new NativeWebPush(...$args);
    }

    public function getInner(): NativeWebPush
    {
        return $this->inner;
    }

    /**
     * @param array<string, mixed> $defaultOptions
     */
    public function setDefaultOptions(array $defaultOptions): self
    {
        $this->inner->setDefaultOptions($defaultOptions);
        return $this;
    }

    public function setReuseVAPIDHeaders(bool $reuse = true): self
    {
        $this->inner->setReuseVAPIDHeaders($reuse);
        return $this;
    }

    public function setAutomaticPadding(bool|int $automaticPadding): self
    {
        $this->inner->setAutomaticPadding($automaticPadding);
        return $this;
    }

    public function queueNotification(Subscription $subscription, ?string $payload = null): self
    {
        $this->inner->queueNotification($subscription, $payload);
        return $this;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function sendOneNotification(
        Subscription $subscription,
        ?string $payload = null,
        array $options = [],
    ): MessageSentReport {
        return $this->inner->sendOneNotification($subscription, $payload, $options);
    }

    public function flush(?int $batchSize = null): \Generator
    {
        return $batchSize === null
            ? $this->inner->flush()
            : $this->inner->flush($batchSize);
    }

    /**
     * @param (callable(MessageSentReport): void)|null $callback
     * @return \Generator<int, MessageSentReport>
     */
    public function flushPooled(?callable $callback = null, ?int $batchSize = null, ?int $requestConcurrency = null): \Generator
    {
        /** @var list<MessageSentReport> $reports */
        $reports = [];
        $collector = static function (MessageSentReport $report) use (&$reports, $callback): void {
            if ($callback !== null) {
                $callback($report);
            }
            $reports[] = $report;
        };

        $this->inner->flushPooled($collector, $batchSize, $requestConcurrency);
        yield from $reports;
    }

    /**
     * @param array<int, mixed> $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (!\method_exists($this->inner, $name)) {
            throw new RuntimeException(\sprintf('Method %s::%s() does not exist.', $this->inner::class, $name));
        }

        return $this->inner->{$name}(...$arguments);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function subscription(#[SensitiveParameter] array $data): Subscription
    {
        return Subscription::create($data);
    }

    /**
     * @return array<string, string>
     */
    public static function generateVapidKeys(): array
    {
        return VAPID::createVapidKeys();
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function payloadText(string $title, string $body, array $extra = []): string
    {
        return self::encodePayload(\array_merge([
            'type' => 'text',
            'title' => $title,
            'body' => $body,
        ], $extra));
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function payloadTextWithDescription(
        string $title,
        string $body,
        string $description,
        array $extra = [],
    ): string {
        return self::encodePayload(\array_merge([
            'type' => 'text_with_description',
            'title' => $title,
            'body' => $body,
            'description' => $description,
        ], $extra));
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function payloadTextWithUrl(
        string $title,
        string $body,
        string $url,
        ?string $description = null,
        array $extra = [],
    ): string {
        return self::encodePayload(\array_merge([
            'type' => 'text_with_url',
            'title' => $title,
            'body' => $body,
            'description' => $description,
            'url' => $url,
        ], $extra));
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function payloadImageText(
        string $title,
        string $body,
        string $imageUrl,
        array $extra = [],
    ): string {
        return self::encodePayload(\array_merge([
            'type' => 'image_text',
            'title' => $title,
            'body' => $body,
            'image' => $imageUrl,
        ], $extra));
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function payloadRich(array $data): string
    {
        return self::encodePayload(\array_merge([
            'type' => 'rich',
        ], $data));
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function queueText(Subscription $subscription, string $title, string $body, array $extra = []): self
    {
        return $this->queueNotification($subscription, self::payloadText($title, $body, $extra));
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function queueTextWithDescription(
        Subscription $subscription,
        string $title,
        string $body,
        string $description,
        array $extra = [],
    ): self {
        return $this->queueNotification(
            $subscription,
            self::payloadTextWithDescription($title, $body, $description, $extra),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function queueTextWithUrl(
        Subscription $subscription,
        string $title,
        string $body,
        string $url,
        ?string $description = null,
        array $extra = [],
    ): self {
        return $this->queueNotification(
            $subscription,
            self::payloadTextWithUrl($title, $body, $url, $description, $extra),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function queueImageText(
        Subscription $subscription,
        string $title,
        string $body,
        string $imageUrl,
        array $extra = [],
    ): self {
        return $this->queueNotification(
            $subscription,
            self::payloadImageText($title, $body, $imageUrl, $extra),
        );
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, mixed> $options
     */
    public function sendText(
        Subscription $subscription,
        string $title,
        string $body,
        array $extra = [],
        array $options = [],
    ): MessageSentReport {
        return $this->sendOneNotification($subscription, self::payloadText($title, $body, $extra), $options);
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, mixed> $options
     */
    public function sendTextWithDescription(
        Subscription $subscription,
        string $title,
        string $body,
        string $description,
        array $extra = [],
        array $options = [],
    ): MessageSentReport {
        return $this->sendOneNotification(
            $subscription,
            self::payloadTextWithDescription($title, $body, $description, $extra),
            $options,
        );
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, mixed> $options
     */
    public function sendTextWithUrl(
        Subscription $subscription,
        string $title,
        string $body,
        string $url,
        ?string $description = null,
        array $extra = [],
        array $options = [],
    ): MessageSentReport {
        return $this->sendOneNotification(
            $subscription,
            self::payloadTextWithUrl($title, $body, $url, $description, $extra),
            $options,
        );
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, mixed> $options
     */
    public function sendImageText(
        Subscription $subscription,
        string $title,
        string $body,
        string $imageUrl,
        array $extra = [],
        array $options = [],
    ): MessageSentReport {
        return $this->sendOneNotification(
            $subscription,
            self::payloadImageText($title, $body, $imageUrl, $extra),
            $options,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function encodePayload(array $data): string
    {
        try {
            return \json_encode(
                $data,
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
            );
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to encode push payload as JSON.', 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function payloadSuccess(
        string $title,
        string $body,
        ?string $url = null,
        array $extra = [],
    ): string {
        return self::encodePayload(\array_merge([
            'type' => 'success',
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'icon' => $extra['icon'] ?? null,
            'badge' => $extra['badge'] ?? null,
            'tag' => $extra['tag'] ?? 'success',
            'data' => $extra['data'] ?? [],
        ], $extra));
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function payloadWarning(
        string $title,
        string $body,
        ?string $url = null,
        array $extra = [],
    ): string {
        return self::encodePayload(\array_merge([
            'type' => 'warning',
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'icon' => $extra['icon'] ?? null,
            'badge' => $extra['badge'] ?? null,
            'tag' => $extra['tag'] ?? 'warning',
            'data' => $extra['data'] ?? [],
        ], $extra));
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function payloadError(
        string $title,
        string $body,
        ?string $url = null,
        array $extra = [],
    ): string {
        return self::encodePayload(\array_merge([
            'type' => 'error',
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'icon' => $extra['icon'] ?? null,
            'badge' => $extra['badge'] ?? null,
            'tag' => $extra['tag'] ?? 'error',
            'data' => $extra['data'] ?? [],
        ], $extra));
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function payloadSystem(
        string $title,
        string $body,
        ?string $url = null,
        array $extra = [],
    ): string {
        return self::encodePayload(\array_merge([
            'type' => 'system',
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'icon' => $extra['icon'] ?? null,
            'badge' => $extra['badge'] ?? null,
            'tag' => $extra['tag'] ?? 'system',
            'silent' => $extra['silent'] ?? false,
            'data' => $extra['data'] ?? [],
        ], $extra));
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function payloadMarketing(
        string $title,
        string $body,
        ?string $url = null,
        array $extra = [],
    ): string {
        return self::encodePayload(\array_merge([
            'type' => 'marketing',
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'image' => $extra['image'] ?? null,
            'icon' => $extra['icon'] ?? null,
            'badge' => $extra['badge'] ?? null,
            'tag' => $extra['tag'] ?? 'marketing',
            'requireInteraction' => $extra['requireInteraction'] ?? true,
            'data' => $extra['data'] ?? [],
        ], $extra));
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function payloadChatMessage(
        string $title,
        string $body,
        ?string $url = null,
        array $extra = [],
    ): string {
        return self::encodePayload(\array_merge([
            'type' => 'chat_message',
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'icon' => $extra['icon'] ?? null,
            'avatar' => $extra['avatar'] ?? null,
            'badge' => $extra['badge'] ?? null,
            'tag' => $extra['tag'] ?? 'chat',
            'renotify' => $extra['renotify'] ?? true,
            'data' => $extra['data'] ?? [],
        ], $extra));
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function payloadOrderStatusChanged(
        string $orderId,
        string $oldStatus,
        string $newStatus,
        ?string $url = null,
        array $extra = [],
    ): string {
        return self::encodePayload(\array_merge([
            'type' => 'order_status_changed',
            'title' => $extra['title'] ?? 'Order status changed',
            'body' => $extra['body'] ?? \sprintf('Order #%s changed from %s to %s', $orderId, $oldStatus, $newStatus),
            'orderId' => $orderId,
            'oldStatus' => $oldStatus,
            'newStatus' => $newStatus,
            'url' => $url,
            'icon' => $extra['icon'] ?? null,
            'badge' => $extra['badge'] ?? null,
            'tag' => $extra['tag'] ?? 'order',
            'data' => $extra['data'] ?? [],
        ], $extra));
    }
}
