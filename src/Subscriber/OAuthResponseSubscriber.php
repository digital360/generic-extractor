<?php

namespace Keboola\GenericExtractor\Subscriber;

use GuzzleHttp\Client;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Event\SubscriberInterface;
use Keboola\GenericExtractor\Configuration\Extractor;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/**
 * Might better be able to work with ANY type of auth, and tweak the request accordingly
 */
class OAuthResponseSubscriber implements SubscriberInterface
{

    private $_response_token;

    public function getEvents()
    {
        return ['complete' => ['onComplete', RequestEvents::LATE]];
    }

    public function onComplete(CompleteEvent $event)
    {
        // look for refresh token in response
        $jsonResponse = $event->getResponse()->getBody()->getContents();
        $responseArr = json_decode($jsonResponse, true);
        if (isset($responseArr['refresh_token'])) {
            $this->_response_token = $jsonResponse;
            $this->updateConfig();
        }
    }

    public function getEncrypted(string $string)
    {
        $client = new Client();
        $r = $client->post(
            'https://encryption.keboola.com/encrypt',
            [
                'headers' => [
                    'content-type' => 'text/plain',
                ],
                'query' => [
                    'componentId' => 'engineroom.ex-generic',
                    'projectId' => '6198',
                ],
                'body' => $string,
            ]
        );

        return $r->getBody()->getContents();
    }

    public function updateConfig()
    {
        // load the original config file
        $logger = new Logger("logger");
        $stream = fopen('php://stdout', 'r');
        $logger->pushHandler(new StreamHandler($stream));
        $configuration = new Extractor('/data', $logger);
        $configFile = $configuration->getFullConfigArray();

        $encryptedTokens = $this->getEncrypted($this->_response_token);
        $encryptedAppSecret = $this->getEncrypted(
            $configFile['authorization']['oauth_api']['credentials']['#appSecret']
        );

        $authData = [];
        $authData['authorization']['oauth_api']['credentials']['#data'] = $encryptedTokens;
        $authData['authorization']['oauth_api']['credentials']['#appSecret'] = $encryptedAppSecret;

        echo '====================================';
        print_r(json_encode(array_merge($configFile, $authData)));
        echo "\n\n\n";

        $client = new Client();
        $r = $client->put(
            'https://connection.keboola.com/v2/storage/components/engineroom.ex-generic/configs/603911685',
            [
                'headers' => [
                    'content-type' => 'application/x-www-form-urlencoded',
                    'X-StorageApi-Token' => $configFile['config']['componentToken'],
                ],
                'body' => 'configuration='.urlencode(json_encode(array_merge($configFile, $authData))).'&changeDescription=Updated via api',
            ]
        );


        echo $r->getBody()->getContents();
    }
}
