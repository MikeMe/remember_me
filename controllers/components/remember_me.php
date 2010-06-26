<?php
/**
 * RememberMe Component - Token driven AutoLogin Component for CakePHP
 *
 * http://www.jotlab.com
 * http://www.github/voidet/remember_me
 *
 **/

class RememberMeComponent extends Object {

/**
 * Include the neccessary components for RememberMe to function with
 */
	public $components = array('Auth', 'Cookie', 'Session');

/**
	* @param array $settings overrides default settings for token fieldnames and data fields
	* @return false
*/
	function initialize(&$Controller, $settings = array()) {
		$defaults = array(
			'timeout' => '+1 month',
			'field_name' => 'remember_me',
			'token_field' => 'token',
			'token_salt' => 'token_salt'
		);
		$this->Controller = &$Controller;
		$this->settings = array_merge($defaults, $settings);
	}

/**
	* initializeModel method loads the required model if not previously loaded
	* @return false
	*/
	private function initializeModel() {
		if (!isset($this->userModel)) {
			App::import('Model', $this->Auth->userModel);
			$this->userModel = new $this->Auth->userModel();
		}
	}

/**
	* tokenSupports checks to see whether or not the current setup supports tokenizing or tokenizing with series
	* @param type specifies which field & setting is functional
	* @return bool
	*/
	protected function tokenSupports($type = '') {
		$this->initializeModel();
		if ($this->userModel->schema($this->settings[$type]) && !empty($this->settings[$type])) {
			return true;
		}
	}

/**
	* generateHash is a simple uuid to SHA1 with salt handler
	* @return string(40)
	*/
	public function generateHash() {
		return Security::hash(String::uuid(), null, true);
	}

/**
	* setRememberMe checks to see if a user cookie should be initially set, if so dispatches it to the writeCookie method
	* @param array containing the models data without model as the key
	* @return false
	*/
	public function setRememberMe($userData) {
		if ($this->Auth->user()) {
			if (!empty($userData[$this->settings['field_name']])) {
				$this->writeCookie($this->Auth->user());
			}
		}
	}

/**
	* writeTokenCookie stores token information and username in a cookie for future cross referencing
	* @param tokens array holds token and token salt
	* @return false
	*/
	private function writeTokenCookie($tokens = array(), $userData = array()) {
		$cookieData[$this->Auth->fields['username']] = $userData[$this->Auth->userModel][$this->Auth->fields['username']];
		$cookieData[$this->settings['token_field']] = $tokens[$this->Auth->userModel][$this->settings['token_field']];
		if ($this->tokenSupports('token_salt')) {
			$cookieData[$this->settings['token_salt']] = $tokens[$this->Auth->userModel][$this->settings['token_salt']];
		}
		$this->Cookie->write($this->Cookie->name, $cookieData, true, $this->settings['timeout']);
	}

/**
	* setupUser Simple dispatcher for setting up extra userScope params and checking the user's cookie
	* @return false
	*/
	public function setupUser() {
		$this->setUserScope();
		$this->checkUser();
	}

/**
	* setUserScope public method must be called manually in beforeFilter
	* It will then add in extra userscope conditions to authorise a user against
	* param
	* @return false
	*/
	protected function setUserScope() {
		if ($this->Cookie->read($this->Cookie->name) &&
				empty($this->Controller->data[$this->Auth->userModel][$this->settings['field_name']]) && $this->tokenSupports('token_field')) {
			$tokenField = $this->Auth->userModel.'.'.$this->settings['token_field'];
			$cookieData = $this->Cookie->read($this->Cookie->name);
			unset($this->Auth->userScope[$tokenField]);
			$this->Auth->userScope += array($tokenField => $cookieData[$this->settings['token_field']]);
		}
	}

/**
	* checkUser is used to firstly check if a valid cookie exists and if so reestablish their session
	* and secondly update the timeout expiry to stay current to the defined expiry time in relation to last user action.
	* @return false
	*/
	public function checkUser() {
		if ($this->Cookie->read($this->Cookie->name) && !$this->Session->check($this->Auth->sessionKey)) {
			if ($this->tokenSupports('token_field')) {
				$userData = $this->checkTokens();
				if ($userData) {
					$this->setUserScope();
					$this->Auth->login($userData[$this->Auth->userModel]['id']);
				}
			} else {
				$cookieData = unserialize($this->Cookie->read($this->Cookie->name));
				$this->Auth->login($cookieData);
			}
		}

		if ($this->Cookie->read($this->Cookie->name) && $this->Session->check($this->Auth->sessionKey)) {
			$this->rewriteCookie();
		}
	}

/**
	* checkTokens A method determining whether or not the user matches the information in its RememberMe cookie
	* @return array
	*/
	public function checkTokens() {
		if ($this->tokenSupports('token_field')) {
			$this->initializeModel();
			$fields = $this->setTokenFields();
			$cookieData = $this->Cookie->read($this->Cookie->name);
			if (is_array($cookieData) && array_values($fields) === array_keys($cookieData)) {
				$user = $this->getUserByTokens($cookieData);
				if (!empty($user) && $this->tokenSupports('token_salt') && $this->handleHijack($cookieData, $user)) {
					return false;
				} elseif (empty($user)) {
					$this->logout();
				} else {
					$this->writeCookie($user);
					return $user;
				}
			}
		}
	}

/**
	* writeCookie Tests if a token should be used or failover to basic cookie auth
	* if token method then generate tokens and assign them to a user then save
	* @return false
	*/
	private function writeCookie($userData = array()) {
		if ($this->tokenSupports('token_field')) {
			$tokens = $this->makeToken($userData);
			$this->userModel->id = $userData[$this->Auth->userModel]['id'];
			if ($this->userModel->id && $this->userModel->save($tokens)) {
				$this->writeTokenCookie($tokens, $userData);
			}
		} else {
			foreach ($this->setBasicCookieFields() as $keyField) {
				$cookieFields[] = $this->Controller->data['User'][$keyField];
			}
			$this->Cookie->write($this->Cookie->name, serialize($this->Controller->data), true, $this->settings['timeout']);
		}
	}

/**
	* logout clears user Cookie, Session and flushes tokens & salt from the database then redirects to logout action.
	* @return false
	*/
	public function logout($user = array()) {
		if ($this->tokenSupports('token_field')) {
			if (empty($user) && $this->Auth->user()) {
				$user = $this->Auth->user();
			}
			$this->clearTokens($user[$this->Auth->userModel]['id']);
		}
		$this->Cookie->destroy();
		$this->Session->destroy();
		$this->Controller->redirect($this->Auth->logout());
	}

/**
	* rewriteCookie updates the timeout of the cookie from last action
	* @return false
	*/
	public function rewriteCookie() {
		$cookieData = $this->Cookie->read($this->Cookie->name);
		$this->Cookie->write($this->Cookie->name, $cookieData, true, $this->settings['timeout']);
	}

/**
	* setBasicCookieFields a method for specifying fields used by AUth
	* @return array
	*/
	private function setBasicCookieFields() {
		$fields = array($this->Auth->fields['username'], $this->Auth->fields['password']);
		return $fields;
	}

/**
	* setTokenFields a method for specifying token based fields
	* @return array
	*/
	private function setTokenFields() {
		$fields = array($this->Auth->fields['username'], $this->settings['token_field']);
		if ($this->tokenSupports('token_salt')) {
			$fields[] = $this->settings['token_salt'];
		}
		return $fields;
	}

/**
	* prepForOr Used for turning token and authScope conditions into a queryable array
	* @return array
	*/
	private function prepForOr($data) {
		$query['username'] = $data[$this->Auth->fields['username']];
		$query['OR'][$this->settings['token_field']] = $data[$this->settings['token_field']];
		if ($this->tokenSupports('token_salt')) {
			$query['OR'][$this->settings['token_salt']] = $data[$this->settings['token_salt']];
		}
		$conditions = array_merge($query, $this->Auth->userScope);
		return $conditions;
	}

/**
	* getUserByTokens returns user information based on authScope and cookie information
	* @return array
	*/
	public function getUserByTokens($cookieData) {
		$this->initializeModel();
		$fields = array('id');
		$fields = array_merge($fields, $this->setTokenFields());
		return $this->userModel->find('first', array('fields' => array_values($fields), 'conditions' => $this->prepForOr($cookieData), 'recursive' => -1));
	}

/**
	* handleHijack Tests to see whether or not the presented cookie data matches that of in the database
	* if it doesnt call the logout function which will clear the thief and victim
	* @return bool
	*/
	private function handleHijack($cookieData, $user) {
		if (($cookieData[$this->settings['token_salt']] == $user[$this->Auth->userModel][$this->settings['token_salt']] &&
			$cookieData[$this->settings['token_field']] != $user[$this->Auth->userModel][$this->settings['token_field']]) ||
			($cookieData[$this->settings['token_salt']] != $user[$this->Auth->userModel][$this->settings['token_salt']])) {
				$this->logout($user);
				return true;
			}
	}

/**
	* clearTokens Clears user's token and token salt fields
	* @return false
	*/
	public function clearTokens($id = '') {
		$this->initializeModel();
		$this->userModel->id = $id;
		$userOverride[$this->Auth->userModel][$this->settings['token_field']] = null;
		if ($this->tokenSupports('token_salt')) {
			$userOverride[$this->Auth->userModel][$this->settings['token_salt']] = null;
		}
		if ($id) {
			$this->userModel->save($userOverride);
		}
	}

/**
	* makeToken sets token and token salts to an array used for future saving
	* @return array
	*/
	private function makeToken($user = array()) {
		if (!empty($user) && !empty($this->settings['token_field'])) {
			$this->initializeModel();
			if ($this->tokenSupports('token_field')) {
				if ($this->tokenSupports('token_salt')) {
					if ($this->Cookie->read($this->Cookie->name.'.'.$this->settings['token_salt'])) {
						$tokens[$this->Auth->userModel][$this->settings['token_salt']] = $this->Cookie->read($this->Cookie->name.'.'.$this->settings['token_salt']);
					} else {
						$tokens[$this->Auth->userModel][$this->settings['token_salt']] = $this->generateHash();
					}
				}
				$tokens[$this->Auth->userModel][$this->settings['token_field']] = $this->generateHash();
				return $tokens;
			}
		}
	}

}

?>