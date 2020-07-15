<?php

use Keboola\GenericExtractor\Configuration\Extractor;
use Keboola\GenericExtractor\Exception\UserException;
use Keboola\GenericExtractor\Executor;

require_once(__DIR__ . "/vendor/autoload.php");

// initialize logger
$logger = new Monolog\Logger("logger");
$stream = fopen('php://stdout', 'r');
$logger->pushHandler(new \Monolog\Handler\StreamHandler($stream));
//$logger->debug("Starting up");

function moveNewStateFile(\Monolog\Logger $logger)
{
    // do we have the new state.json in /out/ dir?
    $arguments = getopt("d::", ["data::"]);
    if (!isset($arguments["data"])) {
        throw new UserException('Data folder not set.');
    }

    $dataDir = $arguments["data"];

    // replace the state.json in /in/ dir
    // read the in/state.json
    $configuration = new Extractor($dataDir, $logger);
    echo "\n";
    echo 'Save new auth file';
    echo "\n";
    echo json_encode($configuration->getFullConfigArray(),true);
    echo "\n";
    $configuration->saveConfigMetadata($configuration->getFullConfigArray());
}

try {
    $executor = new Executor($logger);
    $executor->run();
} catch (Exception $e) {
    moveNewStateFile($logger);

    // trigger other exceptions based on the type
    switch ($e) {
        case $e instanceof UserException:
            $logger->error($e->getMessage(), (array)$e->getData());
            exit(1);
            break;


        case $e instanceof \Keboola\Juicer\Exception\UserException:
            $logger->error($e->getMessage(), (array)$e->getData());
            exit(1);
            break;


        case $e instanceof UserException:
            $logger->error($e->getMessage(), (array)$e->getData());
            exit($e->getCode() > 1 ? $e->getCode() : 2);
            break;

        default:
            if ($e instanceof \GuzzleHttp\Exception\RequestException
                && $e->getPrevious() instanceof UserException) {
                /** @var UserException $ex */
                $ex = $e->getPrevious();
                $logger->error($ex->getMessage(), (array)$ex->getData());
                exit(1);
            }
            $logger->error(
                $e->getMessage(),
                [
                    'errFile'   => $e->getFile(),
                    'errLine'   => $e->getLine(),
                    'trace'     => $e->getTrace(),
                    'exception' => get_class($e),
                ]
            );
            exit(2);
    }
}

$logger->info("Extractor finished successfully.");
exit(0);
