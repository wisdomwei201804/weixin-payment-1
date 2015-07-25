<?php namespace ITC\Weixin\Payment;

use RuntimeException;
use Psr\Http\Message\ResponseInterface as HttpResponse;
use GuzzleHttp\ClientInterface as HttpClientInterface;
use GuzzleHttp\Client as HttpClient;

use ITC\Weixin\Payment\Contracts\Client as ClientInterface;
use ITC\Weixin\Payment\Contracts\HashGenerator as HashGeneratorInterface;
use ITC\Weixin\Payment\Contracts\Serializer as SerializerInterface;
use ITC\Weixin\Payment\Contracts\Command as CommandInterface;
use ITC\Weixin\Payment\Util\UUID;


class Client implements ClientInterface {

    private $app_id;
    private $mch_id;
    private $secret;
    private $public_key_path;
    private $private_key_path;

    private $http;
    private $hashgen;
    private $serializer;
    private $cache;

    private $commands = [];

    /**
     * @param array $config
     */
    public function __construct(array $config=[])
    {
        $this->app_id = $config['app_id'];
        $this->mch_id = $config['mch_id'];
        $this->secret = $config['secret'];
        $this->public_key_path = $config['public_key_path'];
        $this->private_key_path = $config['private_key_path'];

        !empty($config['secure']) && $this->secure();
    }

    /**
     * @param void
     * @return GuzzleHttp\ClientInterface
     */
    public function getHttpClient()
    {
        // @codeCoverageIgnoreStart
        if (!$this->http)
        {
            $this->setHttpClient(new HttpClient());
        }
        // @codeCoverageIgnoreEnd

        return $this->http;
    }

    /**
     * @param GuzzleHttp\ClientInterface $client
     * @return void
     */
    public function setHttpClient(HttpClientInterface $client)
    {
        $this->http = $client;
    }

    /**
     * @param void
     * @return ITC\Weixin\Contracts\HashGenerator
     */
    public function getHashGenerator()
    {
        // @codeCoverageIgnoreStart
        if (!$this->hashgen)
        {
            $this->setHashGenerator(new HashGenerator($this->secret));
        }
        // @codeCoverageIgnoreEnd

        return $this->hashgen;
    }

    /**
     * @param ITC\Weixin\Contracts\HashGenerator $hashgen
     * @return void
     */
    public function setHashGenerator(HashGeneratorInterface $hashgen)
    {
        $this->hashgen = $hashgen;
    }

    /**
     * @param void
     * @return ITC\Weixin\Contracts\SerializerInterface
     */
    public function getSerializer()
    {
        // @codeCoverageIgnoreStart
        if (!$this->serializer)
        {
            $this->setSerializer(new XmlSerializer());
        }
        // @codeCoverageIgnoreEnd

        return $this->serializer;
    }

    /**
     * @param ITC\Weixin\Contracts\Serializer
     * @return void
     */
    public function setSerializer(SerializerInterface $serializer)
    {
        $this->serializer = $serializer;
    }

    /**
     * @param void
       @codeCoverageIgnore
     */
    public function secure($secure=true)
    {
        $this->secure = $secure;
        return $this;
    }

    /**
     * @param string $url
     * @param array $message
     * @param array $options
     * @param Psr\Http\Message\ResponseInterface $response
     * @return array
     */
    public function call($url, array $message, array $options=[], HttpResponse &$response=null)
    {
        $nonce = !empty($options['nonce']) ? $options['nonce'] : null;

        // sign the message
        $message = $this->sign($message, $nonce);

        // send a POST request (it's always a POST)
        $response = $this->getHttpClient()->post($url, [
            'body' => $this->getSerializer()->serialize($message),
        ]);

        $status = (int) $response->getStatusCode();

        if ($status < 200 || $status >= 300)
        {
            throw new UnexpectedValueException('got unexpected HTTP status '.$status);
        }

        // return the parsed response body
        return $this->getSerializer()->unserialize($response->getBody());
    }

    /**
     * Returns the Command identified by the supplied name
     * @param string $name
     * @return ITC\Weixin\Payment\Contracts\Command
     */
    public function command($name)
    {
        if (!isset($this->commands[$name]))
        {
            throw new RuntimeException('unknown command: '.$name);
        }

        return $this->commands[$name];
    }

    /**
     * Registers a Command on the client instance
     * @param ITC\Weixin\Payment\Contracts\Command $command
     * @return void
     */
    public function register(CommandInterface $command)
    {
        $command->setClient($this);

        $this->commands[$command->name()] = $command;
    }

    /**
     * @param array $message
     * @param string $nonce
     * @return array
     */
    public function sign(array $message, $nonce=null)
    {
        $message['appid'] = $this->app_id;
        $message['mch_id'] = $this->mch_id;
        $message['nonce_str'] = $nonce ? $nonce : UUID::v4();
        $message['sign'] = $this->getHashGenerator()->hash($message);

        return $message;
    }

}

