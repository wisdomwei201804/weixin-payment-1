<?php namespace ITC\Weixin\Payment\Test;

use Mockery;
use ITC\Weixin\Payment\Contracts\Client as ClientInterface;
use ITC\Weixin\Payment\Contracts\WebServiceCall as CallInterface;
use ITC\Weixin\Payment\Api\CreateUnifiedOrder;

class CreateUnifiedOrderTest extends TestCase {

    public function setUp()
    {
        parent::setUp();

        $this->client = Mockery::mock(ClientInterface::class)->makePartial();
        $this->call = new CreateUnifiedOrder($this->client);
    }

    public function test_interface_compliance()
    {
        $this->assertTrue($this->call instanceof CallInterface);
    }

    public function test_execute()
    {
        $client = $this->client;
        $call = $this->call;

        $api_endpoint = 'http://foo/bar';
        $call->setUrl($api_endpoint);

        $params = [
            'appid' => 'WEIXIN_APP_ID',
            'out_trade_no' => 'DOMAIN_ORDER_ID',
            'body' => 'ACME Order DOMAIN_ORDER_ID',
            'total_fee' => 100,
            'spbill_create_ip' => '127.0.0.1',
            'notify_url' => 'http://mywebsite.com/payment/weixin/notify',
            'trade_type' => 'JSAPI',
            'openid' => 'wx_932509283mkjsdfijaef',
        ];

        $result_data = ['foo'=>1, 'bar'=>'two'];

        $client->shouldReceive('call')->withArgs([$api_endpoint, $params])
                                      ->andReturn($result_data);

        $this->assertEquals($result_data, $call->execute($params));

    }

}
