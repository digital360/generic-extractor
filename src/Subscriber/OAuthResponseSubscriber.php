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
            echo "\n\n";
            echo "INSIDE REFREH";
            echo "\n\n";
            print_r($responseArr);
            echo "\n\n";
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
            file_put_contents('/data/out/state.json', json_encode(['custom' => $data['auth']]));

            $this->updateStateFile($data['api_token'], $data['auth']);

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
        $stateFile = $configuration->getFullStateArray();

        if (getenv('APP_ENV') == 'dev') {
            $encryptedTokens = $this->_response_token;
        } else {
            $encryptedTokens = $this->getEncrypted($this->_response_token);
        }

        $authInfo = $configFile['authorization']['oauth_api']['credentials'];
        $newAuthData = ['#data' => $encryptedTokens];

        echo "encrypted Key";
        echo "\n";
        echo $newAuthData;
        echo "\n";
        echo "\n";
        return [
            'auth'      => ['credentials' => array_merge($authInfo, $newAuthData)],
            'api_token' => $configFile['parameters']['componentToken'] ?? ''
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

    public function updateStateFile(array $configFile, $newStateData)
    {
        $client = new Client();
        $r = $client->put(
            'https://connection.keboola.com/v2/storage/components/' . getenv('KBC_COMPONENTID') . '/configs/' . getenv('KBC_CONFIGID'),
            [
                'headers' => [
                    'content-type'       => 'application/x-www-form-urlencoded',
                    'X-StorageApi-Token' => $configFile['parameters']['componentToken'],
                ],
                'body'    => 'state=' . urlencode(json_encode(['component' => $newStateData]))
            ]
        );

        return $r->getBody()->getContents();
    }

    public function updateConfig(array $configFile, $encryptedTokens)
    {
        $newAuthInfo = [
            'credentials' => [
                '#data'      => $encryptedTokens,
                'appKey'     => $configFile['authorization']['oauth_api']['credentials']['appKey'],
                '#appSecret' => $configFile['authorization']['oauth_api']['credentials']['#appSecret'],
            ],
        ];

        $configFile['authorization']['oauth_api']['credentials']['#data'] = $encryptedTokens;

        $client = new Client();
        $client->put(
            'https://connection.keboola.com/v2/storage/components/' . getenv('KBC_COMPONENTID') . '/configs/' . getenv('KBC_CONFIGID'),
            [
                'headers' => [
                    'content-type'       => 'application/x-www-form-urlencoded',
                    'X-StorageApi-Token' => $configFile['parameters']['componentToken'],
                ],
                'body'    => 'configuration=' . urlencode(json_encode($configFile)) . '&changeDescription=Updated via api',
            ]
        );

        return $newAuthInfo;
    }
}
