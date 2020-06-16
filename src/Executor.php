<?php

namespace Keboola\GenericExtractor;

use Keboola\GenericExtractor\Configuration\Extractor;
use Keboola\GenericExtractor\Exception\ApplicationException;
use Keboola\GenericExtractor\Exception\UserException;
use Keboola\Juicer\Config\Config;
use Keboola\Juicer\Parser\Json;
use Keboola\Temp\Temp;
use Monolog\Handler\AbstractHandler;
use Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Class Executor manages multiple configurations (created by iterations) and executes
 * GenericExtractor for each of them.
 */
class Executor
{
    /**
     * @var Logger
     */
    private $logger;

    /**
     * Executor constructor.
     * @param  Logger  $logger
     */
    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param  bool  $debug
     */
    private function setLogLevel($debug)
    {
        /** @var AbstractHandler $handler */
        foreach ($this->logger->getHandlers() as $handler) {
            if ($handler instanceof AbstractHandler) {
                if ($debug) {
                    $handler->setLevel($this->logger::DEBUG);
                } else {
                    $handler->setLevel($this->logger::INFO);
                }
            }
        }
    }

    public function run()
    {
        $temp = new Temp();

        $arguments = getopt("d::", ["data::"]);
        if (!isset($arguments["data"])) {
            throw new UserException('Data folder not set.');
        }

        $configuration = new Extractor($arguments['data'], $this->logger);
        $configs = $configuration->getMultipleConfigs();

        $sshProxy = null;
        if ($configuration->getSshProxy() !== null) {
            $sshProxy = $this->createSshTunnel($configuration->getSshProxy());
        }

        $metadata = $configuration->getMetadata();
        $metadata['time']['previousStart'] =
            empty($metadata['time']['previousStart']) ? 0 : $metadata['time']['previousStart'];
        $metadata['time']['currentStart'] = time();
        $cacheStorage = $configuration->getCache();

        $results = [];
        /** @var Config[] $configs */
        foreach ($configs as $config) {
            $this->setLogLevel($config->getAttribute('debug'));
            $api = $configuration->getApi($config->getAttributes());

            if (!empty($config->getAttribute('outputBucket'))) {
                $outputBucket = $config->getAttribute('outputBucket');
            } elseif ($config->getAttribute('id')) {
                $outputBucket = 'ex-api-'.$api->getName()."-".$config->getAttribute('id');
            } else {
                $outputBucket = "__kbc_default";
            }

            $extractor = new GenericExtractor(
                $temp,
                $this->logger,
                $api,
                $sshProxy
            );

            if ($cacheStorage) {
                $extractor->enableCache($cacheStorage);
            }

            if (!empty($results[$outputBucket])) {
                $extractor->setParser($results[$outputBucket]['parser']);
            }
            $extractor->setMetadata($metadata);

            $extractor->run($config);

            $metadata = $extractor->getMetadata();

            $results[$outputBucket]['parser'] = $extractor->getParser();
            $results[$outputBucket]['incremental'] = $config->getAttribute('incrementalOutput');
        }

        foreach ($results as $bucket => $result) {
            $this->logger->debug("Processing results for {$bucket}.");
            /** @var Json $parser */
            $parser = $result['parser'];
            $configuration->storeResults(
                $parser->getResults(),
                $bucket == "__kbc_default" ? null : $bucket,
                true,
                $result['incremental']
            );

            // move files and flatten file structure
            $folderFinder = new Finder();
            $fs = new Filesystem();
            $folders = $folderFinder->directories()->in($arguments['data']."/out/tables")->depth(0);
            foreach ($folders as $folder) {
                /** @var SplFileInfo $folder */
                $filesFinder = new Finder();
                $files = $filesFinder->files()->in($folder->getPathname())->depth(0);
                /** @var SplFileInfo $file */
                foreach ($files as $file) {
                    $destination =
                        $arguments['data']."/out/tables/".basename($folder->getPathname()).
                        ".".basename($file->getPathname());
                    // maybe move will be better?
                    $fs->rename($file->getPathname(), $destination);
                }
            }
            $fs->remove($folders);
        }

        MissingTableHelper::checkConfigs($configs, $arguments['data'], $configuration);
        $metadata['time']['previousStart'] = $metadata['time']['currentStart'];
        unset($metadata['time']['currentStart']);
        $configuration->saveConfigMetadata($metadata);


        #######################################3
        // load creds to state.json
        $credsFileName = $arguments['data'].DIRECTORY_SEPARATOR.'out'.DIRECTORY_SEPARATOR.'creds.json';
        if (!file_exists($credsFileName)) {
            throw new ApplicationException("Configuration file '$credsFileName' not found.");
        }
        $credsData = json_decode(file_get_contents($credsFileName), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ApplicationException("Creds file is not a valid JSON: ".json_last_error_msg());
        }

        // load state file
        try {
            $stateFileName = $arguments['data'].DIRECTORY_SEPARATOR.'in'.DIRECTORY_SEPARATOR.'state.json';
            $stateFileData = json_decode(file_get_contents($stateFileName), true);
        } catch (ApplicationException $e) {
            // state file is optional so only log the error
            $this->logger->warning("State file not found ".$e->getMessage());
            $stateFileData = [];
        }
        $stateFileData['configuration']['authorization']['oauth_api']['credentials'] = $credsData;

        $configuration->saveConfigMetadata($stateFileData);
        #################################################3
    }

    private function createSshTunnel($sshConfig): string
    {
        $tunnelParams = [
            'user' => $sshConfig['user'],
            'sshHost' => $sshConfig['host'],
            'sshPort' => $sshConfig['port'],
            'localPort' => 33006,
            'privateKey' => $sshConfig['#privateKey'],
        ];
        $this->logger->info("Creating SSH tunnel to '".$tunnelParams['sshHost']."'");
        (new SSH())->openTunnel($tunnelParams);

        return sprintf('socks5h://127.0.0.1:%s', $tunnelParams['localPort']);
    }
}
