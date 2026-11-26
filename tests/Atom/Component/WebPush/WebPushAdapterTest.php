<?php

declare(strict_types=1);

namespace Tests\Atom\Component\WebPush;

use PHPUnit\Framework\TestCase;
use Atom\Component\WebPush\WebPushAdapter;

/**
 * Test suite for the WebPushAdapter component.
 */
class WebPushAdapterTest extends TestCase
{
    /**
     * Test payload text returns valid json.
     */
    public function testPayloadTextReturnsValidJson()
    {
        $result = WebPushAdapter::payloadText('Test Title', 'Test Body');
        $decoded = json_decode($result, true);
        
        $this->assertNotNull($decoded);
        $this->assertEquals('text', $decoded['type']);
        $this->assertEquals('Test Title', $decoded['title']);
        $this->assertEquals('Test Body', $decoded['body']);
    }

    /**
     * Test payload text with description returns valid json.
     */
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

    /**
     * Test payload text with url returns valid json.
     */
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

    /**
     * Test payload image text returns valid json.
     */
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

    /**
     * Test payload rich returns valid json.
     */
    public function testPayloadRichReturnsValidJson()
    {
        $data = ['custom_field' => 'custom_value'];
        $result = WebPushAdapter::payloadRich($data);
        $decoded = json_decode($result, true);
        
        $this->assertNotNull($decoded);
        $this->assertEquals('rich', $decoded['type']);
        $this->assertEquals('custom_value', $decoded['custom_field']);
    }

    /**
     * Test payload success returns valid json.
     */
    public function testPayloadSuccessReturnsValidJson()
    {
        $result = WebPushAdapter::payloadSuccess('Success Title', 'Success Body');
        $decoded = json_decode($result, true);
        
        $this->assertNotNull($decoded);
        $this->assertEquals('success', $decoded['type']);
        $this->assertEquals('Success Title', $decoded['title']);
        $this->assertEquals('Success Body', $decoded['body']);
    }

    /**
     * Test payload warning returns valid json.
     */
    public function testPayloadWarningReturnsValidJson()
    {
        $result = WebPushAdapter::payloadWarning('Warning Title', 'Warning Body');
        $decoded = json_decode($result, true);
        
        $this->assertNotNull($decoded);
        $this->assertEquals('warning', $decoded['type']);
        $this->assertEquals('Warning Title', $decoded['title']);
        $this->assertEquals('Warning Body', $decoded['body']);
    }

    /**
     * Test payload error returns valid json.
     */
    public function testPayloadErrorReturnsValidJson()
    {
        $result = WebPushAdapter::payloadError('Error Title', 'Error Body');
        $decoded = json_decode($result, true);
        
        $this->assertNotNull($decoded);
        $this->assertEquals('error', $decoded['type']);
        $this->assertEquals('Error Title', $decoded['title']);
        $this->assertEquals('Error Body', $decoded['body']);
    }

    /**
     * Test payload system returns valid json.
     */
    public function testPayloadSystemReturnsValidJson()
    {
        $result = WebPushAdapter::payloadSystem('System Title', 'System Body');
        $decoded = json_decode($result, true);
        
        $this->assertNotNull($decoded);
        $this->assertEquals('system', $decoded['type']);
        $this->assertEquals('System Title', $decoded['title']);
        $this->assertEquals('System Body', $decoded['body']);
    }

    /**
     * Test payload marketing returns valid json.
     */
    public function testPayloadMarketingReturnsValidJson()
    {
        $result = WebPushAdapter::payloadMarketing('Marketing Title', 'Marketing Body');
        $decoded = json_decode($result, true);
        
        $this->assertNotNull($decoded);
        $this->assertEquals('marketing', $decoded['type']);
        $this->assertEquals('Marketing Title', $decoded['title']);
        $this->assertEquals('Marketing Body', $decoded['body']);
    }

    /**
     * Test payload chat message returns valid json.
     */
    public function testPayloadChatMessageReturnsValidJson()
    {
        $result = WebPushAdapter::payloadChatMessage('Chat Title', 'Chat Message');
        $decoded = json_decode($result, true);
        
        $this->assertNotNull($decoded);
        $this->assertEquals('chat_message', $decoded['type']);
        $this->assertEquals('Chat Title', $decoded['title']);
        $this->assertEquals('Chat Message', $decoded['body']);
    }

    /**
     * Test payload order status changed returns valid json.
     */
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

    /**
     * Test encode payload throws exception on invalid data.
     */
    public function testEncodePayloadThrowsExceptionOnInvalidData()
    {
        // Test with circular reference to trigger exception
        $data = ['key' => 'value'];
        $data['self'] = &$data;
        
        $method = new \ReflectionMethod(WebPushAdapter::class, 'encodePayload');
        
        $this->expectException(\RuntimeException::class);
        $method->invoke(null, $data);
    }

    /**
     * Test payload text with url with description returns valid json.
     */
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
