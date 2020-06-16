<?php

namespace Keboola\GenericExtractor\Configuration;

use Doctrine\Common\Cache\FilesystemCache;
use GuzzleHttp\Subscriber\Cache\CacheStorage;
use Keboola\CsvTable\Table;
use Keboola\GenericExtractor\Configuration\Extractor\ConfigFile;
use Keboola\GenericExtractor\Configuration\Extractor\StateFile;
use Keboola\GenericExtractor\Exception\ApplicationException;
use Keboola\GenericExtractor\Exception\UserException;
use Keboola\Juicer\Config\Config;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

/**
 * Class Extractor provides interfaces for processing configuration files and
 * obtaining parts of GE extractor configuration.
 */
class Extractor
{
    const CACHE_TTL = 604800;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var array
     */
    private $config;

    /**
     * @var array
     */
    private $state;

    /**
     * @var string
     */
    private $dataDir;

    /**
     * Extractor constructor.
     * @param $dataDir
     * @param  LoggerInterface  $logger
     */
    public function __construct(string $dataDir, LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->config = $this->loadConfigFile($dataDir);
        $this->state = $this->loadStateFile($dataDir);
        $this->dataDir = $dataDir;
    }

    /**
     * @param  string  $dataDir
     * @param  string  $name
     * @return array
     */
    private function loadJSONFile(string $dataDir, string $name): array
    {
        $fileName = $dataDir.DIRECTORY_SEPARATOR.$name;
        if (!file_exists($fileName)) {
            throw new ApplicationException("Configuration file '$fileName' not found.");
        }
        $data = json_decode(file_get_contents($fileName), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ApplicationException("Configuration file is not a valid JSON: ".json_last_error_msg());
        }

        return $data;
    }


    /**
     * @param  string  $dataDir
     * @return array
     */
    private function loadConfigFile(string $dataDir): array
    {
        $this->logger->debug(print_r(json_decode(file_get_contents('/data/in/state.json'), true), true));

        $data = $this->loadJSONFile($dataDir, 'config.json');

        // merge creds file
        #######################################3
        // load creds to state.json
        $credsFileName = $dataDir.DIRECTORY_SEPARATOR.'in'.DIRECTORY_SEPARATOR.'state.json';
        if (file_exists($credsFileName)) {
            $credsData = json_decode(file_get_contents($credsFileName), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if (count($credsData) > 0) {
                    $data['authorization']['oauth_api'] = $credsData['custom'];
                    print_r($credsData);
                }
            }
        }

        #################################################3

        $processor = new Processor();
        try {
            $processor->processConfiguration(new ConfigFile(), $data);
        } catch (InvalidConfigurationException $e) {
            // TODO: create issue to make this strict
            //$this->logger->warning("Configuration file configuration is invalid: " . $e->getMessage());
        }

        $this->logger->debug(print_r($data, true));

        return $data;
    }

    /**
     * @param  string  $dataDir
     * @return array
     */
    private function loadStateFile(string $dataDir): array
    {
        try {
            $data = $this->loadJSONFile($dataDir, 'in'.DIRECTORY_SEPARATOR.'state.json');
        } catch (ApplicationException $e) {
            // state file is optional so only log the error
            $this->logger->warning("State file not found ".$e->getMessage());
            $data = [];
        }
        $processor = new Processor();
        try {
            $processor->processConfiguration(new StateFile(), $data);
        } catch (InvalidConfigurationException $e) {
            // TODO: create issue to make this strict
            //$this->logger->warning("State file configuration is invalid: " . $e->getMessage());
        }

        return $data;
    }

    /**
     * @return Config[]
     */
    public function getMultipleConfigs(): array
    {
        if (empty($this->config['parameters']['iterations'])) {
            return [$this->getConfig([])];
        }

        $configs = [];
        foreach ($this->config['parameters']['iterations'] as $params) {
            $configs[] = $this->getConfig($params);
        }

        return $configs;
    }

    /**
     * @return Config[]
     */
    public function getFullConfigArray()
    {
        return $this->config;
    }

    /**
     * @param  array  $params  Values to override those in the config
     * @return Config
     * @throws UserException
     */
    private function getConfig(array $params): Config
    {
        if (empty($this->config['parameters']['config'])) {
            throw new UserException("The 'config' section is required in the configuration.");
        }
        $configuration = array_replace($this->config['parameters']['config'], $params);

        return new Config($configuration);
    }

    public function getSshProxy(): ?array
    {
        if (isset($this->config['parameters']['sshProxy'])) {
            return $this->config['parameters']['sshProxy'];
        }

        return null;
    }

    /**
     * @return array
     */
    public function getMetadata(): array
    {
        return $this->state;
    }

    /**
     * @return CacheStorage|null
     */
    public function getCache(): ?CacheStorage
    {
        if (empty($this->config['parameters']['cache'])) {
            return null;
        }

        $ttl = !empty($this->config['parameters']['cache']['ttl']) ?
            (int)$this->config['parameters']['cache']['ttl'] : self::CACHE_TTL;

        return new CacheStorage(new FilesystemCache($this->dataDir.DIRECTORY_SEPARATOR.'cache'), null, $ttl);
    }

    /**
     * @param  array  $configAttributes
     * @return Api
     */
    public function getApi(array $configAttributes): Api
    {
        if (!empty($this->config['authorization'])) {
            $authorization = $this->config['authorization'];
        } else {
            $authorization = [];
        }
        if (empty($this->config['parameters']['api']) && !is_array($this->config['parameters']['api'])) {
            throw new UserException("The 'api' section is required in configuration.");
        }

        return new Api($this->logger, $this->config['parameters']['api'], $configAttributes, $authorization);
    }

    /**
     * @param  array  $data
     */
    public function saveConfigMetadata(array $data)
    {
        $dirPath = $this->dataDir.DIRECTORY_SEPARATOR.'out';
        if (!is_dir($dirPath)) {
            mkdir($dirPath);
        }

        // pull custom data out of the file and merge back
        $stateOutFile = $this->dataDir.DIRECTORY_SEPARATOR.'out'.DIRECTORY_SEPARATOR.'state.json';
        if (file_exists($stateOutFile)) {
            $customData = json_decode(file_get_contents($stateOutFile), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if (count($customData) > 0) {
                    $data['custom'] = $customData['custom'];
                }
            }
        }

        file_put_contents($dirPath.DIRECTORY_SEPARATOR.'state.json', json_encode($data));
    }

    /**
     * @param  Table[]  $csvFiles
     * @param  string  $bucketName
     * @param  bool  $sapiPrefix  whether to prefix the output bucket with "in.c-"
     * @param  bool  $incremental  Set the incremental flag in manifest
     * TODO: revisit this
     */
    public function storeResults(array $csvFiles, $bucketName = null, $sapiPrefix = true, $incremental = false)
    {
        $path = "{$this->dataDir}/out/tables/";

        if (!is_null($bucketName)) {
            $path .= $bucketName.'/';
            $bucketName = $sapiPrefix ? 'in.c-'.$bucketName : $bucketName;
        }

        if (!is_dir($path)) {
            mkdir($path, 0775, true);
            chown($path, fileowner("{$this->dataDir}/out/tables/"));
            chgrp($path, filegroup("{$this->dataDir}/out/tables/"));
        }

        foreach ($csvFiles as $key => $file) {
            $manifest = [];

            if (!is_null($bucketName)) {
                $manifest['destination'] = "{$bucketName}.{$key}";
            }

            $manifest['incremental'] = is_null($file->getIncremental())
                ? $incremental
                : $file->getIncremental();

            if (!empty($file->getPrimaryKey())) {
                $manifest['primary_key'] = $file->getPrimaryKey(true);
            }

            file_put_contents($path.$key.'.manifest', json_encode($manifest));
            copy($file->getPathname(), $path.$key);
        }
    }
}
