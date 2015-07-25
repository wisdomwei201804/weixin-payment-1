<?php namespace ITC\Weixin\Payment\Test;

use Mockery;
use Psr\Log\LoggerInterface;
use GuzzleHttp\ClientInterface as HttpClientInterface;
use ITC\Weixin\Payment\Contracts\Serializer as SerializerInterface;
use ITC\Weixin\Payment\Contracts\Command as CommandInterface;
use ITC\Weixin\Payment\Contracts\HashGenerator as HashGeneratorInterface;
use ITC\Weixin\Payment\Contracts\Client as ClientInterface;
use ITC\Weixin\Payment\Contracts\Message as MessageInterface;
use ITC\Weixin\Payment\Client;

class ClientTest extends TestCase {

    public function setUp()
    {
        parent::setUp();

        // mock all the dependencies
        $this->hashgen = Mockery::mock(HashGeneratorInterface::class)->makePartial();
        $this->serializer = Mockery::mock(SerializerInterface::class)->makePartial();
        $this->http = Mockery::mock(HttpClientInterface::class)->makePartial();
        $this->logger = Mockery::mock(LoggerInterface::class);

        foreach (['log', 'debug', 'info', 'notice', 'warning', 'error'] as $log_level)
        {
            $this->logger->shouldReceive($log_level)->withAnyArgs();
        }

        $this->app_id = 'WEIXIN_APP_ID';
        $this->mch_id = 'WEIXIN_MERCHANT_ID';
        $this->secret = 'WEIXIN_HASH_SECRET';

        $this->client = new Client([
            'app_id' => $this->app_id,
            'mch_id' => $this->mch_id,
            'secret' => $this->secret,
            'public_key_path' => '/path/to/public/key',
            'private_key_path' => '/path/to/private/key',
        ]);

        $this->client->setLogger($this->logger);
        $this->client->setHttpClient($this->http);
        $this->client->setSerializer($this->serializer);
        $this->client->setHashGenerator($this->hashgen);
    }

    public function test_interface_compliance()
    {
        $this->assertTrue($this->client instanceof ClientInterface);
    }

    public function test_command_access()
    {
        $client = $this->client;

        $command = Mockery::mock(CommandInterface::class)->makePartial();
        $command->shouldReceive('name')->once()->andReturn('arbitrary-command-name');
        $command->shouldReceive('setClient')->once()->withArgs([$client]);

        $client->register($command);

        $this->assertSame($command, $client->command('arbitrary-command-name'));
    }

    public function test_post()
    {
        $client = $this->client;
        $http = $this->http;
        $serializer = $this->serializer;
        $hashgen = $this->hashgen;

        $request_url = 'https://api.mch.weixin.qq.com/pay/unifiedorder';
        $nonce = 'NONCE_STR';
        $signature = 'REQUEST_SIGNATURE';

        $initial_data = [
            'openid' => 'WEIXIN_OPENID',
            'nonce_str' => $nonce,
        ];

        $request_data = array_merge($initial_data, [
            'openid' => 'WEIXIN_OPENID',
            'nonce_str' => $nonce,
            'appid' => $this->app_id,
            'mch_id' => $this->mch_id,
            'sign' => $signature,
        ]);

        // pre-request expectations
        $request_message = $client->createMessage($initial_data);
        $hashgen->shouldReceive('hash')->once()->andReturn($signature);
        $serializer->shouldReceive('serialize')->once()->withArgs([$request_data])->andReturn('SERIALIZED_DATA');

        // post-request expectations
        $response_xml = '<xml><foo>1</foo><bar>two</bar><sign>RESPONSE_MESSAGE_SIGNATURE</xml>';
        $response_data = ['foo'=>1, 'bar'=>'two', 'sign'=>'RESPONSE_MESSAGE_SIGNATURE'];
        $expected_response = Mockery::mock(HttpResponseInterface::class)->makePartial();
        $expected_response->shouldReceive('getStatusCode')->once()->andReturn(200);
        $expected_response->shouldReceive('getBody')->once()->andReturn($response_xml);
        $serializer->shouldReceive('unserialize')->once()->withArgs([$response_xml])->andReturn($response_data);

        $http->shouldReceive('post')->once()->withArgs([$request_url, ['body'=>'SERIALIZED_DATA']])->andReturn($expected_response);

        $actual_response = null; 
        $response_message = $this->client->post($request_url, $request_message, $actual_response);

        $this->assertSame($expected_response, $actual_response);
        $this->assertTrue($response_message instanceof MessageInterface);
       
        $this->assertSame(1, $response_message->get('foo'));
        $this->assertSame('two', $response_message->get('bar'));
        $this->assertSame('RESPONSE_MESSAGE_SIGNATURE', $response_message->get('sign'));
    }

}
