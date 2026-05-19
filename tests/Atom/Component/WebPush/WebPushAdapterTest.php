<?php

declare(strict_types=1);

namespace Tests\Atom\Component\WebPush;

use PHPUnit\Framework\TestCase;
use Atom\Component\WebPush\WebPushAdapter;

class NotificationBuilderTest extends TestCase
{
    public function testPayloadTextReturnsValidJson()
    {
        $result = WebPushAdapter::payloadText('Test Title', 'Test Body');
        $decoded = json_decode($result, true);
        
        $this->assertNotNull($decoded);
        $this->assertEquals('text', $decoded['type']);
        $this->assertEquals('Test Title', $decoded['title']);
        $this->assertEquals('Test Body', $decoded['body']);
    }

    public function testPayloadTextWithDescriptionReturnsValidJson()
    {
        $result = WebPushAdapter::payloadTextWithDescription('Test Title', 'Test Body', 'Test Description');
        $decoded = json_decode($result, true);
        
        $this->assertNotNull($decoded);
        $this->assertEquals('text_with_description', $decoded['type']);
        $this->assertEquals('Test Title', $decoded['title']);
        $this->assertEquals('Test Body', $decoded['body']);
        $this->assertEquals('Test Description', $decoded['description']);
    }

    public function testPayloadTextWithUrlReturnsValidJson()
    {
        $result = WebPushAdapter::payloadTextWithUrl('Test Title', 'Test Body', 'https://example.com');
        $decoded = json_decode($result, true);
        
        $this->assertNotNull($decoded);
        $this->assertEquals('text_with_url', $decoded['type']);
        $this->assertEquals('Test Title', $decoded['title']);
        $this->assertEquals('Test Body', $decoded['body']);
        $this->assertEquals('https://example.com', $decoded['url']);
    }

    public function testPayloadImageTextReturnsValidJson()
    {
        $result = WebPushAdapter::payloadImageText('Test Title', 'Test Body', 'https://example.com/image.jpg');
        $decoded = json_decode($result, true);
        
        $this->assertNotNull($decoded);
        $this->assertEquals('image_text', $decoded['type']);
        $this->assertEquals('Test Title', $decoded['title']);
        $this->assertEquals('Test Body', $decoded['body']);
        $this->assertEquals('https://example.com/image.jpg', $decoded['image']);
    }

    public function testPayloadRichReturnsValidJson()
    {
        $data = ['custom_field' => 'custom_value'];
        $result = WebPushAdapter::payloadRich($data);
        $decoded = json_decode($result, true);
        
        $this->assertNotNull($decoded);
        $this->assertEquals('rich', $decoded['type']);
        $this->assertEquals('custom_value', $decoded['custom_field']);
    }

    public function testPayloadSuccessReturnsValidJson()
    {
        $result = WebPushAdapter::payloadSuccess('Success Title', 'Success Body');
        $decoded = json_decode($result, true);
        
        $this->assertNotNull($decoded);
        $this->assertEquals('success', $decoded['type']);
        $this->assertEquals('Success Title', $decoded['title']);
        $this->assertEquals('Success Body', $decoded['body']);
    }

    public function testPayloadWarningReturnsValidJson()
    {
        $result = WebPushAdapter::payloadWarning('Warning Title', 'Warning Body');
        $decoded = json_decode($result, true);
        
        $this->assertNotNull($decoded);
        $this->assertEquals('warning', $decoded['type']);
        $this->assertEquals('Warning Title', $decoded['title']);
        $this->assertEquals('Warning Body', $decoded['body']);
    }

    public function testPayloadErrorReturnsValidJson()
    {
        $result = WebPushAdapter::payloadError('Error Title', 'Error Body');
        $decoded = json_decode($result, true);
        
        $this->assertNotNull($decoded);
        $this->assertEquals('error', $decoded['type']);
        $this->assertEquals('Error Title', $decoded['title']);
        $this->assertEquals('Error Body', $decoded['body']);
    }

    public function testPayloadSystemReturnsValidJson()
    {
        $result = WebPushAdapter::payloadSystem('System Title', 'System Body');
        $decoded = json_decode($result, true);
        
        $this->assertNotNull($decoded);
        $this->assertEquals('system', $decoded['type']);
        $this->assertEquals('System Title', $decoded['title']);
        $this->assertEquals('System Body', $decoded['body']);
    }

    public function testPayloadMarketingReturnsValidJson()
    {
        $result = WebPushAdapter::payloadMarketing('Marketing Title', 'Marketing Body');
        $decoded = json_decode($result, true);
        
        $this->assertNotNull($decoded);
        $this->assertEquals('marketing', $decoded['type']);
        $this->assertEquals('Marketing Title', $decoded['title']);
        $this->assertEquals('Marketing Body', $decoded['body']);
    }

    public function testPayloadChatMessageReturnsValidJson()
    {
        $result = WebPushAdapter::payloadChatMessage('Chat Title', 'Chat Message');
        $decoded = json_decode($result, true);
        
        $this->assertNotNull($decoded);
        $this->assertEquals('chat_message', $decoded['type']);
        $this->assertEquals('Chat Title', $decoded['title']);
        $this->assertEquals('Chat Message', $decoded['body']);
    }

    public function testPayloadOrderStatusChangedReturnsValidJson()
    {
        $result = WebPushAdapter::payloadOrderStatusChanged('12345', 'pending', 'shipped');
        $decoded = json_decode($result, true);
        
        $this->assertNotNull($decoded);
        $this->assertEquals('order_status_changed', $decoded['type']);
        $this->assertEquals('12345', $decoded['orderId']);
        $this->assertEquals('pending', $decoded['oldStatus']);
        $this->assertEquals('shipped', $decoded['newStatus']);
    }

    public function testEncodePayloadThrowsExceptionOnInvalidData()
    {
        // Test with circular reference to trigger exception
        $data = ['key' => 'value'];
        $data['self'] = &$data;
        
        $this->expectException(\RuntimeException::class);
        WebPushAdapter::encodePayload($data);
    }

    public function testPayloadTextWithUrlWithDescriptionReturnsValidJson()
    {
        $result = WebPushAdapter::payloadTextWithUrl('Test Title', 'Test Body', 'https://example.com', 'Test Description');
        $decoded = json_decode($result, true);
        
        $this->assertNotNull($decoded);
        $this->assertEquals('text_with_url', $decoded['type']);
        $this->assertEquals('Test Title', $decoded['title']);
        $this->assertEquals('Test Body', $decoded['body']);
        $this->assertEquals('https://example.com', $decoded['url']);
        $this->assertEquals('Test Description', $decoded['description']);
    }
}
