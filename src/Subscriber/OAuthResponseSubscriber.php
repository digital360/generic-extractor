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

    private $logger;
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
            $this->saveCredsfile();
        }
    }

    private function saveCredsfile()
    {
        $dirPath = '/data' . DIRECTORY_SEPARATOR;
        if (!is_dir($dirPath)) {
            mkdir($dirPath);
        }
        try {
            $data = $this->buildConfigArray();
            // update the out file
            file_put_contents('/data/out/state.json', json_encode(['custom' => $data]));

            // update the in file
            file_put_contents('/data/in/state.json', json_encode(['custom' => $data]));

            echo('TOKEN IS BEEN REFRESHED');
            echo("\n");
            print_r($this->_response_token);
        } catch (\Exception $e) {
            throw new \RuntimeException('Cannot save new auth data');
        }
    }

    /**
     * @throws \Exception
     */
    private function buildConfigArray(): array
    {
        // load the original config file
        $logger = new Logger("logger");
        $stream = fopen('php://stdout', 'r');
        $logger->pushHandler(new StreamHandler($stream));
        $configuration = new Extractor('/data', $logger);
        $configFile = $configuration->getFullConfigArray();

        if (getenv('APP_ENV') == 'dev') {
            $encryptedTokens = $this->_response_token;
        } else {
            $encryptedTokens = $this->getEncrypted($this->_response_token);
        }

        return [
            'credentials' => [
                '#data'      => $encryptedTokens,
                'appKey'     => $configFile['authorization']['oauth_api']['credentials']['appKey'],
                '#appSecret' => $configFile['authorization']['oauth_api']['credentials']['#appSecret'],
            ],
        ];
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
                'query'   => [
                    'componentId' => getenv('KBC_COMPONENTID'),
                    'projectId'   => getenv('KBC_PROJECTID'),
                ],
                'body'    => $string,
            ]
        );

        return $r->getBody()->getContents();
    }
}
