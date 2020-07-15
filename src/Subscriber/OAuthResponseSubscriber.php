<?php

namespace Keboola\GenericExtractor\Subscriber;

use GuzzleHttp\Client;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Event\SubscriberInterface;

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
            $this->saveCredsfile();
        }
    }

    private function saveCredsfile()
    {
        $dirPath = '/data/out/';
        if (!is_dir($dirPath)) {
            mkdir($dirPath);
        }
        $data = $this->buildConfigArray();

        file_put_contents('/data/out/auth.json', json_encode(['new_data' => $data]));
    }

    /**
     * @throws \Exception
     */
    private function buildConfigArray(): string
    {
        if (getenv('APP_ENV') == 'dev') {
            $encryptedTokens = $this->_response_token;
        } else {
            $encryptedTokens = $this->getEncrypted($this->_response_token);
        }

        return $encryptedTokens;
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
