<?php

namespace ITC\Weixin\Payment;

use OutOfBoundsException;
use UnexpectedValueException;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ResponseInterface as HttpResponseInterface;
use GuzzleHttp\ClientInterface as HttpClientInterface;
use GuzzleHttp\Client as HttpClient;
use ITC\Weixin\Payment\Contracts\Client as ClientInterface;
use ITC\Weixin\Payment\Contracts\Message as MessageInterface;
use ITC\Weixin\Payment\Contracts\HashGenerator as HashGeneratorInterface;
use ITC\Weixin\Payment\Contracts\Serializer as SerializerInterface;
use ITC\Weixin\Payment\Contracts\Command as CommandInterface;

class Client implements ClientInterface
{
    private $app_id;
    private $mch_id;
    private $secret;
    private $public_key_path;
    private $private_key_path;

    private $logger;
    private $http;
    private $hashgen;
    private $serializer;
    private $cache;

    private $commands = [];

    /**
     * @param array $config
     *
     * @return ITC\Weixin\Payment\Client
     */
    public static function instance(array $config = [])
    {
        $client = new static($config);

        $client->register(Command\CreateUnifiedOrder::class);
        $client->register(Command\OrderQuery::class);
        $client->register(Command\CashCoupon\SendRedpack::class);
        $client->register(Command\CashCoupon\GetHbinfo::class);

        return $client;
    }

    /**
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $this->configure($config);
    }

    /**
     * Configure the instance
     * @param array $config
     * @return void
     */
    public function configure(array $config = [])
    {
        $props = [
            'app_id', 'mch_id', 'secret', 'public_key_path',
            'private_key_path',
        ];

        foreach ($props as $prop)
        {
            if (isset($config[$prop]))
            {
                $this->$prop = $config[$prop];
            }
        }

        !empty($config['secure']) && $this->secure();
    }

    /**
     * @param Psr\Log\LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param void
     *
     * @return Psr\Log\LoggerInterface $logger
     */
    public function getLogger()
    {
        if (!$this->logger) {
            $this->logger = new DummyLogger();
        }

        return $this->logger;
    }

    /**
     * @param GuzzleHttp\ClientInterface $client
     */
    public function setHttpClient(HttpClientInterface $client)
    {
        $this->http = $client;
    }

    /**
     * @param void
     *
     * @return GuzzleHttp\ClientInterface
     */
    public function getHttpClient()
    {
        if (!$this->http) {

            $options = [];

            if ($this->public_key_path) {
                $options['ssl_key'] = $this->public_key_path;
            }
            if ($this->private_key_path) {
                $options['cert'] = $this->private_key_path;
            }

            $this->setHttpClient(new HttpClient($options));
        }

        return $this->http;
    }

    /**
     * @param ITC\Weixin\Contracts\HashGenerator $hashgen
     */
    public function setHashGenerator(HashGeneratorInterface $hashgen)
    {
        $this->hashgen = $hashgen;
    }

    /**
     * @param void
     *
     * @return ITC\Weixin\Contracts\HashGenerator
     */
    public function getHashGenerator()
    {
        if (!$this->hashgen) {
            $this->setHashGenerator(new HashGenerator($this->secret));
        }

        return $this->hashgen;
    }

    /**
     * @param ITC\Weixin\Contracts\Serializer
     */
    public function setSerializer(SerializerInterface $serializer)
    {
        $this->serializer = $serializer;
    }

    /**
     * @param void
     *
     * @return ITC\Weixin\Contracts\SerializerInterface
     */
    public function getSerializer()
    {
        if (!$this->serializer) {
            $this->setSerializer(new XmlSerializer());
        }

        return $this->serializer;
    }

    /**
     * @param void
     * @codeCoverageIgnore
     */
    public function secure($secure = true)
    {
        $this->secure = $secure;

        return $this;
    }

    /**
     * @param mixed $data
     * @param array $required
     *
     * @return ITC\Weixin\Payment\Contracts\Message $message
     */
    public function message($data = null, $required = null)
    {
        $serializer = $this->getSerializer();
        $hashgen = $this->getHashGenerator();

        if (is_string($data) && $data) {
            $data = $serializer->unserialize($data);
        }

        $message = new Message\Message($data);
        $message->setSerializer($serializer);
        $message->setHashGenerator($hashgen);

        if ($required) {
            foreach ($required as $k => $v) {
                $message->set($v, $this->$k);
            }
        }

        return $message;
    }

    /**
     * @param mixed $data
     * @param array $required
     *
     * @return ITC\Weixin\Payment\Contracts\Message $message
     */
    public function createMessage($data = null, $required = null)
    {
        $log = $this->getLogger();
        $log->warning(__METHOD__.' is deprecated; use Client::message instead');

        return $this->message($data, $required);
    }

    /**
     * @param string                               $url
     * @param ITC\Weixin\Payment\Contracts\Message $message
     * @param Psr\Http\Message\ResponseInterface   $response
     *
     * @return ITC\Weixin\Payment\Contracts\Message
     */
    public function post($url, MessageInterface $message, HttpResponseInterface &$response = null)
    {
        $log = $this->getLogger();
        $serializer = $this->getSerializer();

        $this->prepare($message);

        $reqbody = $serializer->serialize($message->toArray());

        // send a POST request (it's always a POST)
        $response = $this->getHttpClient()->post($url, ['body' => $reqbody]);
        $status = (int) $response->getStatusCode();
        $resbody = $response->getBody();

        $log->info("[$status] POST $url", ['method' => __METHOD__]);
        $log->debug('  req: '.$reqbody, ['method' => __METHOD__]);
        $log->debug('  res: '.$resbody, ['method' => __METHOD__]);

        if ($status < 200 || $status >= 300) {
            $msg = 'got unexpected HTTP response status '.$status;
            $log->error($msg, ['method' => __METHOD__]);
            throw new UnexpectedValueException($msg);
        }

        $data = $serializer->unserialize($resbody);

        return $this->createMessage($data);
    }

    /**
     * Returns the Command identified by the supplied name.
     *
     * @param string $name
     *
     * @return ITC\Weixin\Payment\Contracts\Command
     */
    public function command($name)
    {
        if (!isset($this->commands[$name])) {
            throw new OutOfBoundsException('unknown command: '.$name);
        }

        if (is_object($this->commands[$name])) {
            return $this->commands[$name];
        }

        if (is_string($this->commands[$name])) {
            $command = $this->commands[$name];
            $command = $command::make();
            $command->setClient($this);
            $this->commands[$name] = $command;

            return $this->commands[$name];
        }

        throw new OutOfBoundsException('unknown command: '.$name);
    }

    /**
     * Registers a Command on the client instance.
     *
     * @param ITC\Weixin\Payment\Contracts\Command|string $command
     */
    public function register($command)
    {
        if (is_object($command) && is_subclass_of($command, CommandInterface::class)) {
            $command->setClient($this);
        } elseif (is_string($command) && class_exists($command)) {
            $interfaces = class_implements($command);
            if (!$interfaces || !in_array(CommandInterface::class, $interfaces)) {
                throw new OutOfBoundsException('unknown command: '.$command);
            }
        } else {
            throw new OutOfBoundsException('unknown command');
        }

        $this->commands[$command::name()] = $command;
    }

    /**
     * Prepares a message for outbound transport.
     *
     * @param ITC\Weixin\Payment\Contracts\Message $message
     */
    private function prepare(MessageInterface $message)
    {
        $message->get('nonce_str') || $message->set('nonce_str', static::nonce());
        $message->sign();
    }

    /**
     * @param array $query
     *
     * @return JsonSerializable
     */
    public function jsapize(array $query, $nonce = null, $timestamp = null)
    {
        $message = $this->createMessage();

        $message->set('appId', $this->app_id);
        $message->set('nonceStr', ($nonce ? $nonce : static::nonce()));
        $message->set('timeStamp', (string) ($timestamp ? $timestamp : time()));
        $message->set('package', $query);
        $message->set('signType', 'MD5');
        $message->set('paySign', $this->hashgen->hash($message->toArray()));

        return $message;
    }

    /**
     * Generates a pseudo-random nonce string.
     *
     * @param void
     *
     * @return string
     */
    protected static function nonce()
    {
        $data = openssl_random_pseudo_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return bin2hex($data);
    }
}
