<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Joas Schilling <coding@schilljs.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OC\App\CodeChecker;

use OC\Hooks\BasicEmitter;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;

class InfoChecker extends BasicEmitter {

	/** @var IAppManager */
	private $appManager;

	public function __construct(IAppManager $appManager) {
		$this->appManager = $appManager;
	}

	/**
	 * @param string $appId
	 * @return array
	 */
	public function analyse($appId): array {
		try {
			$appPath = $this->appManager->getAppPath($appId);
		} catch (AppPathNotFoundException $e) {
			throw new \RuntimeException("No app with given id $appId known.");
		}

		$xml = new \DOMDocument();
		$xml->load($appPath . '/appinfo/info.xml');

		$schema = \OC::$SERVERROOT . '/resources/app-info.xsd';
		if ($this->appManager->isShipped($appId)) {
			// Shipped apps are allowed to have the public and default_enabled tags
			$schema = \OC::$SERVERROOT . '/resources/app-info-shipped.xsd';
		}

		$errors = [];
		if (!$xml->schemaValidate($schema)) {
			foreach (libxml_get_errors() as $error) {
				$errors[] = [
					'type' => 'parseError',
					'field' => $error->message,
				];
				$this->emit('InfoChecker', 'parseError', [$error->message]);
			}
		}

		return $errors;
	}
}
