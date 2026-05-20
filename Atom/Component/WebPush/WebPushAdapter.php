<?php

declare(strict_types=1);

namespace Atom\Component\WebPush;

use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush as NativeWebPush;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Thin compatibility layer over minishlink/web-push.
 * Keeps the same public method names and adds convenient helpers/templates.
 */
final class WebPushAdapter
{
    private NativeWebPush $inner;

    /**
     * Create a new notification service instance
     * 
     * @param mixed ...$args Arguments to pass to the native web push constructor
     */
    public function __construct(...$args)
    {
        $this->inner = new NativeWebPush(...$args);
    }

    /**
     * Get the inner native web push instance
     * 
     * @return NativeWebPush The native web push instance
     */
    public function getInner(): NativeWebPush
    {
        return $this->inner;
    }

    /**
     * Set default options for all notifications sent by this service
     * 
     * @param array $defaultOptions The default options to use
     * @return self Fluent interface for method chaining
     */
    public function setDefaultOptions(array $defaultOptions): self
    {
        $this->inner->setDefaultOptions($defaultOptions);
        return $this;
    }

    /**
     * Set whether VAPID headers should be reused for multiple notifications
     * 
     * @param bool $reuse Whether to reuse VAPID headers (defaults to true)
     * @return self Fluent interface for method chaining
     */
    public function setReuseVAPIDHeaders(bool $reuse = true): self
    {
        $this->inner->setReuseVAPIDHeaders($reuse);
        return $this;
    }

    /**
     * Accepts bool or int just like the library's automatic padding concept.
     * Set whether automatic padding should be applied to notifications
     * 
     * @param bool|int $automaticPadding Whether to enable automatic padding (true/false or 1/0)
     * @return self Fluent interface for method chaining
     */
    public function setAutomaticPadding(bool|int $automaticPadding): self
    {
        $this->inner->setAutomaticPadding($automaticPadding);
        return $this;
    }

    /**
     * Queue a notification for delivery to a subscription
     * 
     * @param Subscription $subscription The subscription to send notification to
     * @param string|null $payload The notification payload to queue (null means use default)
     * @return self Fluent interface for method chaining
     */
    public function queueNotification(Subscription $subscription, ?string $payload = null): self
    {
        $this->inner->queueNotification($subscription, $payload);
        return $this;
    }

    /**
     * Send a single notification to a subscription
     * 
     * @param Subscription $subscription The subscription to send notification to
     * @param string|null $payload The notification payload to send (null means use default)
     * @param array $options Additional options for sending the notification
     * @return MessageSentReport Report of the message delivery
     */
    public function sendOneNotification(
        Subscription $subscription,
        ?string $payload = null,
        array $options = []
    ): MessageSentReport {
        return $this->inner->sendOneNotification($subscription, $payload, $options);
    }

    /**
     * Flush the notification queue with optional batch processing
     * 
     * @param int|null $batchSize The maximum number of notifications to process in this batch (null for all)
     * @return \Generator Generator that yields processed notifications
     */
    public function flush(?int $batchSize = null): \Generator
    {
        return $batchSize === null
            ? $this->inner->flush()
            : $this->inner->flush($batchSize);
    }

    /**
     * Flush the pooled notification queue with optional batch processing
     * 
     * @param int|null $batchSize The maximum number of notifications to process in this batch (null for all)
     * @return \Generator Generator that yields processed notifications
     */
    public function flushPooled(?int $batchSize = null): \Generator
    {
        return $batchSize === null
            ? $this->inner->flushPooled()
            : $this->inner->flushPooled($batchSize);
    }

    /**
     * Dynamically call methods on the inner notification service
     * 
     * @param string $name The name of the method to call
     * @param array $arguments The arguments to pass to the method
     * @return mixed The result of the method call
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (!method_exists($this->inner, $name)) {
            throw new RuntimeException(\sprintf('Method %s::%s() does not exist.', $this->inner::class, $name));
        }

        return $this->inner->{$name}(...$arguments);
    }

    /**
     * Create a new subscription with the given data
     * 
     * @param array $data The subscription data
     * @return Subscription The created subscription
     */
    public static function subscription(array $data): Subscription
    {
        return Subscription::create($data);
    }

    /**
     * Returns stdClass with:
     *   $keys->public
     *   $keys->private
     */
    public static function generateVapidKeys(): array
    {
        return VAPID::createVapidKeys();
    }

    /**
     * Build a basic text notification payload
     * 
     * @param string $title The title of the notification
     * @param string $body The main content of the notification
     * @param array $extra Additional fields to include in the payload
     * @return string JSON encoded payload
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
     * Build a text notification with description payload
     * 
     * @param string $title The title of the notification
     * @param string $body The main content of the notification
     * @param string $description The description text
     * @param array $extra Additional fields to include in the payload
     * @return string JSON encoded payload
     */
    public static function payloadTextWithDescription(
        string $title,
        string $body,
        string $description,
        array $extra = []
    ): string {
        return self::encodePayload(array_merge([
            'type' => 'text_with_description',
            'title' => $title,
            'body' => $body,
            'description' => $description,
        ], $extra));
    }

    /**
     * Build a text notification with URL payload
     * 
     * @param string $title The title of the notification
     * @param string $body The main content of the notification
     * @param string $url The URL to open when clicked
     * @param string|null $description Optional description text
     * @param array $extra Additional fields to include in the payload
     * @return string JSON encoded payload
     */
    public static function payloadTextWithUrl(
        string $title,
        string $body,
        string $url,
        ?string $description = null,
        array $extra = []
    ): string {
        return self::encodePayload(array_merge([
            'type' => 'text_with_url',
            'title' => $title,
            'body' => $body,
            'description' => $description,
            'url' => $url,
        ], $extra));
    }

    /**
     * Build an image + text notification payload
     * 
     * @param string $title The title of the notification
     * @param string $body The main content of the notification
     * @param string $imageUrl The URL of the image to display
     * @param array $extra Additional fields to include in the payload
     * @return string JSON encoded payload
     */
    public static function payloadImageText(
        string $title,
        string $body,
        string $imageUrl,
        array $extra = []
    ): string {
        return self::encodePayload(array_merge([
            'type' => 'image_text',
            'title' => $title,
            'body' => $body,
            'image' => $imageUrl,
        ], $extra));
    }

    /**
     * Build a rich notification payload with custom data
     * 
     * @param array $data The custom data for the rich notification
     * @return string JSON encoded payload
     */
    public static function payloadRich(array $data): string
    {
        return self::encodePayload(array_merge([
            'type' => 'rich',
        ], $data));
    }

    /**
     * Queue a text-only notification
     * 
     * @param Subscription $subscription The subscription to send notification to
     * @param string $title The title of the notification
     * @param string $body The main content of the notification
     * @param array $extra Additional fields to include in the payload
     * @return self Fluent interface for method chaining
     */
    public function queueText(Subscription $subscription, string $title, string $body, array $extra = []): self
    {
        return $this->queueNotification($subscription, self::payloadText($title, $body, $extra));
    }

    /**
     * Queue a text notification with description
     * 
     * @param Subscription $subscription The subscription to send notification to
     * @param string $title The title of the notification
     * @param string $body The main content of the notification
     * @param string $description The description text
     * @param array $extra Additional fields to include in the payload
     * @return self Fluent interface for method chaining
     */
    public function queueTextWithDescription(
        Subscription $subscription,
        string $title,
        string $body,
        string $description,
        array $extra = []
    ): self {
        return $this->queueNotification(
            $subscription,
            self::payloadTextWithDescription($title, $body, $description, $extra)
        );
    }

    /**
     * Queue a text notification with URL
     * 
     * @param Subscription $subscription The subscription to send notification to
     * @param string $title The title of the notification
     * @param string $body The main content of the notification
     * @param string $url The URL to open when clicked
     * @param string|null $description Optional description text
     * @param array $extra Additional fields to include in the payload
     * @return self Fluent interface for method chaining
     */
    public function queueTextWithUrl(
        Subscription $subscription,
        string $title,
        string $body,
        string $url,
        ?string $description = null,
        array $extra = []
    ): self {
        return $this->queueNotification(
            $subscription,
            self::payloadTextWithUrl($title, $body, $url, $description, $extra)
        );
    }

    /**
     * Queue an image + text notification
     * 
     * @param Subscription $subscription The subscription to send notification to
     * @param string $title The title of the notification
     * @param string $body The main content of the notification
     * @param string $imageUrl The URL of the image to display
     * @param array $extra Additional fields to include in the payload
     * @return self Fluent interface for method chaining
     */
    public function queueImageText(
        Subscription $subscription,
        string $title,
        string $body,
        string $imageUrl,
        array $extra = []
    ): self {
        return $this->queueNotification(
            $subscription,
            self::payloadImageText($title, $body, $imageUrl, $extra)
        );
    }

    /**
     * Send a text-only notification
     * 
     * @param Subscription $subscription The subscription to send notification to
     * @param string $title The title of the notification
     * @param string $body The main content of the notification
     * @param array $extra Additional fields to include in the payload
     * @param array $options Optional configuration for sending
     * @return MessageSentReport Report of the message delivery
     */
    public function sendText(
        Subscription $subscription,
        string $title,
        string $body,
        array $extra = [],
        array $options = []
    ): MessageSentReport {
        return $this->sendOneNotification($subscription, self::payloadText($title, $body, $extra), $options);
    }

    /**
     * Send a text notification with description
     * 
     * @param Subscription $subscription The subscription to send notification to
     * @param string $title The title of the notification
     * @param string $body The main content of the notification
     * @param string $description The description text
     * @param array $extra Additional fields to include in the payload
     * @param array $options Optional configuration for sending
     * @return MessageSentReport Report of the message delivery
     */
    public function sendTextWithDescription(
        Subscription $subscription,
        string $title,
        string $body,
        string $description,
        array $extra = [],
        array $options = []
    ): MessageSentReport {
        return $this->sendOneNotification(
            $subscription,
            self::payloadTextWithDescription($title, $body, $description, $extra),
            $options
        );
    }

    /**
     * Send a text notification with URL
     * 
     * @param Subscription $subscription The subscription to send notification to
     * @param string $title The title of the notification
     * @param string $body The main content of the notification
     * @param string $url The URL to open when clicked
     * @param string|null $description Optional description text
     * @param array $extra Additional fields to include in the payload
     * @param array $options Optional configuration for sending
     * @return MessageSentReport Report of the message delivery
     */
    public function sendTextWithUrl(
        Subscription $subscription,
        string $title,
        string $body,
        string $url,
        ?string $description = null,
        array $extra = [],
        array $options = []
    ): MessageSentReport {
        return $this->sendOneNotification(
            $subscription,
            self::payloadTextWithUrl($title, $body, $url, $description, $extra),
            $options
        );
    }

    /**
     * Send an image + text notification
     * 
     * @param Subscription $subscription The subscription to send notification to
     * @param string $title The title of the notification
     * @param string $body The main content of the notification
     * @param string $imageUrl The URL of the image to display
     * @param array $extra Additional fields to include in the payload
     * @param array $options Optional configuration for sending
     * @return MessageSentReport Report of the message delivery
     */
    public function sendImageText(
        Subscription $subscription,
        string $title,
        string $body,
        string $imageUrl,
        array $extra = [],
        array $options = []
    ): MessageSentReport {
        return $this->sendOneNotification(
            $subscription,
            self::payloadImageText($title, $body, $imageUrl, $extra),
            $options
        );
    }

    /**
     * Encode payload data into JSON string with error handling
     * 
     * @param array $data The data to encode
     * @return string JSON encoded string
     */
    private static function encodePayload(array $data): string
    {
        try {
            return json_encode(
                $data,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to encode push payload as JSON.', 0, $e);
        }
    }

    /**
     * Build a success notification payload
     * 
     * @param string $title The title of the notification
     * @param string $body The main content of the notification
     * @param string|null $url Optional URL to open when clicked
     * @param array $extra Additional fields to include in the payload
     * @return string JSON encoded payload
     */
    public static function payloadSuccess(
        string $title,
        string $body,
        ?string $url = null,
        array $extra = []
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
     * Build a warning notification payload
     * 
     * @param string $title The title of the notification
     * @param string $body The main content of the notification
     * @param string|null $url Optional URL to open when clicked
     * @param array $extra Additional fields to include in the payload
     * @return string JSON encoded payload
     */
    public static function payloadWarning(
        string $title,
        string $body,
        ?string $url = null,
        array $extra = []
    ): string {
        return self::encodePayload(array_merge([
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
     * Payload builder for error notifications.
     * 
     * This method creates a JSON payload specifically designed for error notifications.
     * It extends the base notification structure with error-specific fields and default values,
     * making it suitable for system alerts, error reporting, or user-facing error messages.
     * 
     * @param string $title The title of the error notification
     * @param string $body The main content/message of the error
     * @param string|null $url Optional URL to open when the notification is clicked (e.g., error details page)
     * @param array $extra Additional fields to include in the payload
     * @return string JSON encoded payload string ready for transmission
     */
    public static function payloadError(
        string $title,
        string $body,
        ?string $url = null,
        array $extra = []
    ): string {
        return self::encodePayload(array_merge([
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
     * Build a system notification payload
     * 
     * @param string $title The title of the notification
     * @param string $body The main content of the notification
     * @param string|null $url Optional URL to open when clicked
     * @param array $extra Additional fields to include in the payload
     * @return string JSON encoded payload
     */
    public static function payloadSystem(
        string $title,
        string $body,
        ?string $url = null,
        array $extra = []
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
     * Build a marketing notification payload
     * 
     * @param string $title The title of the notification
     * @param string $body The main content of the notification
     * @param string|null $url Optional URL to open when clicked
     * @param array $extra Additional fields to include in the payload
     * @return string JSON encoded payload
     */
    public static function payloadMarketing(
        string $title,
        string $body,
        ?string $url = null,
        array $extra = []
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
     * Build a chat message notification payload
     * 
     * @param string $title The title of the notification
     * @param string $body The main content of the notification
     * @param string|null $url Optional URL to open when clicked
     * @param array $extra Additional fields to include in the payload
     * @return string JSON encoded payload
     */
    public static function payloadChatMessage(
        string $title,
        string $body,
        ?string $url = null,
        array $extra = []
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
     * Build an order status change notification payload
     * 
     * @param string $orderId The order identifier
     * @param string $oldStatus The previous order status
     * @param string $newStatus The new order status
     * @param string|null $url Optional URL to open when clicked
     * @param array $extra Additional fields to include in the payload
     * @return string JSON encoded payload
     */
    public static function payloadOrderStatusChanged(
        string $orderId,
        string $oldStatus,
        string $newStatus,
        ?string $url = null,
        array $extra = []
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
