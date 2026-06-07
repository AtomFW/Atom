<?php

declare(strict_types=1);

namespace Tests\Atom\Component\Mail;

use Atom\Component\Mail\MailerProxy;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Psr\Log\NullLogger;

final class MailerProxyTest extends PHPUnitTestCase
{
    public function testConstructorRespectsTestModeOption(): void
    {
        $proxy = new MailerProxy(['testMode' => true], new NullLogger());
        $this->assertTrue($proxy->isTestMode());
    }

    public function testSetTransportSwitchesModes(): void
    {
        $proxy = new MailerProxy(null, new NullLogger());

        $proxy->setTransport('smtp');
        $this->assertSame('smtp', $proxy->Mailer);

        $proxy->setTransport('mail');
        $this->assertSame('mail', $proxy->Mailer);

        $proxy->setTransport('sendmail');
        $this->assertSame('sendmail', $proxy->Mailer);
    }

    public function testConfigureSMTPSetsCommonProperties(): void
    {
        $proxy = new MailerProxy(null, new NullLogger());
        $proxy->configureSMTP([
            'host' => 'smtp.example.com',
            'port' => 2525,
            'smtpAuth' => true,
            'username' => 'user',
            'password' => 'secret',
            'encryption' => 'tls',
            'timeout' => 30,
            'keepalive' => false,
            'smtpOptions' => ['ssl' => ['verify_peer' => false]],
            'localDomain' => 'local.test',
            'charset' => 'ISO-8859-1',
        ]);

        $this->assertSame('smtp', $proxy->Mailer);
        $this->assertSame('smtp.example.com', $proxy->Host);
        $this->assertSame(2525, $proxy->Port);
        $this->assertTrue($proxy->SMTPAuth);
        $this->assertSame('user', $proxy->Username);
        $this->assertSame('secret', $proxy->Password);
        $this->assertSame('tls', $proxy->SMTPSecure);
        $this->assertSame(30, $proxy->Timeout);
        $this->assertFalse($proxy->SMTPKeepAlive);
        $this->assertSame('local.test', $proxy->Hostname);
        $this->assertSame('ISO-8859-1', $proxy->CharSet);
        $this->assertArrayHasKey('ssl', $proxy->SMTPOptions);
    }

    public function testSetDKIMWithIncompleteOptionsDoesNotConfigure(): void
    {
        $proxy = new MailerProxy(null, new NullLogger());
        $proxy->setDKIM(['domain' => 'example.com']);

        // PHPMailer defaults should remain empty
        $this->assertSame('', (string)$proxy->DKIM_domain);
        $this->assertSame('', (string)$proxy->DKIM_selector);
        $this->assertSame('', (string)$proxy->DKIM_private);
    }

    public function testSetDKIMWithCompleteOptionsConfiguresAll(): void
    {
        $proxy = new MailerProxy(null, new NullLogger());
        $proxy->setDKIM([
            'domain' => 'example.com',
            'selector' => 'default',
            'private_key_file' => __FILE__,
            'passphrase' => 'pass',
            'identity' => 'id@example.com',
        ]);

        $this->assertSame('example.com', $proxy->DKIM_domain);
        $this->assertSame('default', $proxy->DKIM_selector);
        $this->assertSame(__FILE__, $proxy->DKIM_private);
        $this->assertSame('pass', $proxy->DKIM_passphrase);
        $this->assertSame('id@example.com', $proxy->DKIM_identity);
    }

    public function testSendHtmlBuildsMessageAndReturnsTrueInTestMode(): void
    {
        $proxy = new MailerProxy(
            [
            'test_mode' => true,
            'from' => [
                'address' => 'from@example.com',
                'name' => 'From Name'
                ]
            ],
            new NullLogger()
        );
        $proxy->setTestMode(true);

        $ok = $proxy->sendHtml(
            'John Doe <john@example.com>',
            'Hello',
            '<b>World</b>',
            null,
            'Alt body',
            'reply@example.com',
            [
                ['filename' => 'file.txt', 'content' => 'data', 'encoding' => 'base64', 'type' => 'text/plain']
            ]
        );

        $this->assertTrue($ok);
        $this->assertTrue($proxy->ContentType === 'text/html' || $proxy->ContentType === 'multipart/alternative');
        $this->assertSame('Hello', $proxy->Subject);
        $this->assertSame('<b>World</b>', $proxy->Body);
        $this->assertSame('Alt body', $proxy->AltBody);
        $this->assertNotEmpty($proxy->getCustomHeaders());
    }

    public function testSendTextBuildsMessageAndReturnsTrueInTestMode(): void
    {
        $proxy = new MailerProxy(
            [
            'test_mode' => true,
            'from' => [
                'address' => 'from@example.com',
                'name' => 'From Name'
                ]
            ],
            new NullLogger()
        );

        $proxy->setTestMode(true);

        $ok = $proxy->sendText(
            'john@example.com',
            'Hello',
            'Plain text body',
            null,
            null,
            'reply@example.com'
        );

        $this->assertTrue($ok);
        $this->assertSame('Hello', $proxy->Subject);
        $this->assertSame('Plain text body', $proxy->Body);
        $this->assertSame('text/plain', $proxy->ContentType);
    }

    public function testSendLoopCountsOkAndFailed(): void
    {
        $proxy = new MailerProxy(['test_mode' => true], new NullLogger());
        $proxy->setTestMode(true);

        $result = $proxy->sendLoop([
            [
                'to' => ['A User <a@example.com>'],
                'subject' => 'S1',
                'body' => 'B1',
                'is_html' => false,
                'headers' => ['X-Loop' => '1'],
            ],
            [
                'to' => ['b@example.com', ['c@example.com', 'C User']],
                'subject' => 'S2',
                'body' => '<b>B2</b>',
                'is_html' => true,
            ],
        ]);

        $this->assertSame(['ok' => 2, 'failed' => 0], $result);
    }

    public function testSplitAddressAndNameParsesCorrectly(): void
    {
        $ref = new \ReflectionClass(MailerProxy::class);
        $method = $ref->getMethod('splitAddressAndName');
        $method->setAccessible(true);

        $proxy = new MailerProxy(null, new NullLogger());

        $r1 = $method->invoke($proxy, 'John Doe <john@example.com>');
        $this->assertSame(['john@example.com', 'John Doe'], $r1);

        $r2 = $method->invoke($proxy, 'plain@example.com');
        $this->assertSame(['plain@example.com', ''], $r2);
    }

    public function testGetToAddressesSafeCall(): void
    {
        $proxy = new MailerProxy(['test_mode' => true], new NullLogger());
        $proxy->setTestMode(true);

        $proxy->sendText('to@example.com', 'S', 'B', 'from@example.com');
        $list = $proxy->getToAddresses();
        $this->assertIsArray($list);
    }
}
