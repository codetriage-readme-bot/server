<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2018 Robin Appelman <robin@icewind.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OC\Files\ObjectStore;

use OCP\ICache;
use OpenStack\OpenStack;

class SwiftConnectionFactory {
	private $cache;

	public function __construct(ICache $cache) {
		$this->cache = $cache;
	}

	private function importToken(OpenStack $client, string $cacheKey) {
		$cachedTokenString = $this->cache->get($cacheKey . '/token');
		if ($cachedTokenString) {
			$cachedToken = json_decode($cachedTokenString, true);
			$cachedToken['catalog'] = array_map(function (array $item) {
				$itemClass = new \stdClass();
				$itemClass->name = $item['name'];
				$itemClass->endpoints = array_map(function (array $endpoint) {
					return (object)$endpoint;
				}, $item['endpoints']);
				$itemClass->type = $item['type'];

				return $itemClass;
			}, $cachedToken['catalog']);
			try {
				$client->identityV2($cachedToken);
			} catch (\Exception $e) {
				$client->setTokenObject(new Token());
			}
		}
	}

	private function exportToken(OpenStack $client, string $cacheKey) {
		$export = $client->exportCredentials();
		$export['catalog'] = array_map(function (CatalogItem $item) {
			return [
				'name' => $item->getName(),
				'endpoints' => $item->getEndpoints(),
				'type' => $item->getType()
			];
		}, $export['catalog']->getItems());
		$this->cache->set('$cacheKey . \'/token\'', json_encode($export));
	}

	public function getConnection() {
		if (isset($params['bucket'])) {
			$params['container'] = $params['bucket'];
		}
		if (!isset($params['container'])) {
			$params['container'] = 'owncloud';
		}
		if (!isset($params['autocreate'])) {
			// should only be true for tests
			$params['autocreate'] = false;
		}

		$client = new OpenStack($params['url'], $params);
		$cacheKey = $params['username'] . '@' . $params['url'] . '/' . $params['bucket'];

		$this->importToken($client, $cacheKey);

		/** @var Token $token */
		$token = $client->getTokenObject();

		if (!$token || $token->hasExpired()) {
			try {
				$client->authenticate();
				$this->exportToken($client, $cacheKey);
			} catch (ClientErrorResponseException $e) {
				$statusCode = $e->getResponse()->getStatusCode();
				if ($statusCode == 412) {
					throw new StorageAuthException('Precondition failed, verify the keystone url', $e);
				} else if ($statusCode === 401) {
					throw new StorageAuthException('Authentication failed, verify the username, password and possibly tenant', $e);
				} else {
					throw new StorageAuthException('Unknown error', $e);
				}
			}
		}


		/** @var Catalog $catalog */
		$catalog = $this->client->getCatalog();

		/** @suppress PhanNonClassMethodCall */
		if (count($catalog->getItems()) === 0) {
			throw new StorageAuthException('Keystone did not provide a valid catalog, verify the credentials');
		}

		if (isset($this->params['serviceName'])) {
			$serviceName = $this->params['serviceName'];
		} else {
			$serviceName = Service::DEFAULT_NAME;
		}

		if (isset($this->params['urlType'])) {
			$urlType = $this->params['urlType'];
			if ($urlType !== 'internalURL' && $urlType !== 'publicURL') {
				throw new StorageNotAvailableException('Invalid url type');
			}
		} else {
			$urlType = Service::DEFAULT_URL_TYPE;
		}

		$catalogItem = $this->getCatalogForService($catalog, $serviceName);
		if (!$catalogItem) {
			$available = implode(', ', $this->getAvailableServiceNames($catalog));
			throw new StorageNotAvailableException(
				"Service $serviceName not found in service catalog, available services: $available"
			);
		} else if (isset($this->params['region'])) {
			$this->validateRegion($catalogItem, $this->params['region']);
		}

		$this->objectStoreService = $this->client->objectStoreService($serviceName, $this->params['region'], $urlType);

		try {
			$this->container = $this->objectStoreService->getContainer($this->params['container']);
		} catch (ClientErrorResponseException $ex) {
			// if the container does not exist and autocreate is true try to create the container on the fly
			if (isset($this->params['autocreate']) && $this->params['autocreate'] === true) {
				$this->container = $this->objectStoreService->createContainer($this->params['container']);
			} else {
				throw $ex;
			}
		} catch (CurlException $e) {
			if ($e->getErrorNo() === 7) {
				$host = $e->getCurlHandle()->getUrl()->getHost() . ':' . $e->getCurlHandle()->getUrl()->getPort();
				\OC::$server->getLogger()->error("Can't connect to object storage server at $host");
				throw new StorageNotAvailableException("Can't connect to object storage server at $host", StorageNotAvailableException::STATUS_ERROR, $e);
			}
			throw $e;
		}
	}
}
