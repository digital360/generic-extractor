<?php
namespace Keboola\GenericExtractor\Authentication;

use	Keboola\Juicer\Exception\ApplicationException,
	Keboola\Juicer\Exception\UserException,
	Keboola\Juicer\Client\RestClient;
use	Keboola\GenericExtractor\Subscriber\UrlSignature,
	Keboola\Code\Builder;
use	Keboola\Utils\Utils,
	Keboola\Utils\Exception\JsonDecodeException;

/**
 * Authentication method using query parameters
 */
class Query implements AuthInterface
{
	/**
	 * @var array
	 */
	protected $query;
	/**
	 * @var array
	 */
	protected $attrs;
	/**
	 * @var Builder
	 */
	protected $builder;

	public function __construct(Builder $builder, $attrs, $definitions)
	{
		$this->query = $definitions;
		$this->attrs = $attrs;
		$this->builder = $builder;
	}

	/**
	 * @param RestClient $client
	 */
	public function authenticateClient(RestClient $client)
	{
		$sub = new UrlSignature();
		// Create array of objects instead of arrays from YML
		$q = (array) Utils::arrayToObject($this->query);
		$sub->setSignatureGenerator(
			function () use ($q) {
				$query = [];
				foreach($q as $key => $value) {
					$query[$key] = is_scalar($value)
						? $value
						: $this->builder->run($value, ['attr' => $this->attrs]);
				}
				return $query;
			}
		);
		$client->getClient()->getEmitter()->attach($sub);
	}
}
