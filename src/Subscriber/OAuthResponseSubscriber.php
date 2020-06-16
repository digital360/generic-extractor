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
            $this->updateStateWithConfig();
        }
    }

    private function updateStateWithConfig()
    {
        $this->list_data_dir();

        $dirPath = '/data'.DIRECTORY_SEPARATOR.'out';
        if (!is_dir($dirPath)) {
            mkdir($dirPath);
        }
        $data = $this->buildConfigArray();

        file_put_contents($dirPath.DIRECTORY_SEPARATOR.'state.json', json_encode($data));
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
        $this->list_data_dir();

        $client = new Client();
        $r = $client->put(
            'https://connection.keboola.com/v2/storage/components/engineroom.ex-generic/configs/603911685',
            [
                'headers' => [
                    'content-type' => 'application/x-www-form-urlencoded',
                    'X-StorageApi-Token' => $configFile['parameters']['componentToken'],
                ],
                'body' => 'configuration='.urlencode(json_encode($configFile)).'&changeDescription=Updated via api',
            ]
        );


        echo $r->getBody()->getContents();
    }

    /**
     * @return \Keboola\Juicer\Config\Config[]
     * @throws \Exception
     */
    private function buildConfigArray(): \Keboola\Juicer\Config\Config
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

        $credentials = [
            '#data' => $encryptedTokens,
            'appKey' => $configFile['authorization']['oauth_api']['credentials']['appKey'],
            '#appSecret' => $encryptedAppSecret,
        ];

        $configFile['authorization']['oauth_api']['credentials'] = $credentials;

        return $configFile;
    }

    private function list_data_dir()
    {
        echo '====================================';
        echo "\n\n\n";
        print_r(scandir('/data/in'));
        echo '====================================';
        echo "\n\n\n";
        print_r(scandir('/data/out'));
        echo '====================================';
        echo "\n\n\n";
        $configFile = $this->buildConfigArray();

        echo "\n\n\n";
        echo '====================================';
        echo "\n\n\n";
        print_r($configFile);
        echo "\n\n\n";
        echo '====================================';
        echo "\n\n\n";
    }
}
