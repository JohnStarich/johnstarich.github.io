<?php

class User {
	private $username;
	private $password;
	private $mysqli;

	function __construct($username=false, $password=false) {
		if($username !== false)
			$this->username = $username;
		if($password !== false)
			$this->password = $password;
		$this->startSecureSession();
	}

	function __destruct() {
		if(isset($this->mysqli))
			$this->mysqli->close();
	}

	public function login() {
		$mysqli = $this->getSQL();
		$query = 'SELECT user_id,user_password FROM users WHERE user_name=?;';
		if($stmt = $mysqli->prepare($query)) {
			$stmt->bind_param('s', $this->username);
			$stmt->execute();
			$stmt->bind_result($user_id, $db_password);
			$stmt->store_result();
			$stmt->fetch();
			if($stmt->num_rows == 1) {
				if($this->isBruteForceAttack($user_id)) {
					echo 'Too many failed log in attempts';
				}
				else {
					$query='INSERT INTO login_attempts (user_id, login_success) VALUES (?,?);';
					if($stmt = $mysqli->prepare($query)) {
						if(password_verify($this->password, $db_password)) {
							$one = 1;
							$stmt->bind_param('ii', $user_id, $one);
							$user_browser = $_SERVER['HTTP_USER_AGENT'];
							$user_ip = $_SESSION['REMOTE_ADDR'];
							$_SESSION['user_name'] = $this->username;
							//perhaps use the last timestamp from login attempts?
							$_SESSION['login_string'] = hash('sha512', $db_password . $user_browser . $user_ip);
							$stmt->execute();
							return true;
						}
						else {
							echo 'Password is incorrect';
							$zero = 0;
							$stmt->bind_param('ii', $user_id, $zero);
							$stmt->execute();
						}
					}
					// else {
					// 	echo 'No login attempt logged';
					// }
				}
			}
			else {
				echo 'User does not exist';
			}
		}
		return false;
	}

	public function loggedIn() {
		if(isset($_SESSION['user_name'], $_SESSION['login_string'])) {
			$mysqli = $this->getSQL();

			$username = $_SESSION['user_name'];
			$login_string = $_SESSION['login_string'];
			$user_browser = $_SERVER['HTTP_USER_AGENT'];
			$query = 'SELECT user_password FROM users WHERE user_name=?;';
			if($stmt = $mysqli->prepare($query)) {
				$stmt->bind_param('s', $username);
				$stmt->execute();
				$stmt->store_result();

				if($stmt->num_rows() == 1) {
					$stmt->bind_result($db_password);
					$stmt->fetch();
					//Get the user agent string of the user. Hash it with the user's password.
					$login_check = hash('sha512', $db_password . $user_browser);
					return $login_check == $login_string;
				}
			}
		}
		return false;
	}

	private function isBruteForceAttack($user_id) {
		$mysqli = $this->getSQL();
		$query='SELECT attempt_time FROM login_attempts
				WHERE login_success<>1
				AND user_id=?
				AND attempt_time > DATE_SUB(NOW(), interval 2 hour);';
		if($stmt = $mysqli->prepare($query)) {
			//check last two hours for more than 5 failed login attempts
			$stmt->bind_param('i', $user_id);
			$stmt->execute();
			$stmt->store_result();
			return $stmt->num_rows > 5;
		}
		return false;
	}

	public function addUser($email) {
		$mysqli = $this->getSQL();
		$password_hash = password_hash($this->password, PASSWORD_BCRYPT);
		$query='INSERT INTO users (user_name, user_password, user_email) VALUES (?,?,?)';
		if($stmt = $mysqli->prepare($query)) {
			$stmt->bind_param('sss', $this->username, $password_hash, $email);
			$stmt->execute();
			if($stmt->affected_rows > 0)
				echo 'User added: '.$this->username.' with password hash: '.$password_hash;
			else
				echo 'Could not add user: '.$this->username;
		}
	}

	private function getUserId() {
		$mysqli = $this->getSQL();
		$query='SELECT user_id FROM users WHERE user_name=?';
		if($stmt = $mysqli->prepare($query)) {
			$stmt->bind_param('s', $this->username);
			$stmt->execute();
			$stmt->bind_result($id);
			$stmt->store_result();
			$id = false;
			$stmt->fetch();
			return $id;
		}
		return false;
	}

	public function removeUser() {
		if(! $this->login()) {
			echo 'Invalid login.';
		}
		else {
			$mysqli = $this->getSQL();
			$password_hash = password_hash($this->password, PASSWORD_DEFAULT);
			$user_id = $this->getUserId();
			$query='DELETE FROM users WHERE user_id=?';
			if($stmt = $mysqli->prepare($query)) {
				$stmt->bind_param('i', $user_id);
				$stmt->execute();
				if($stmt->affected_rows > 0) {
					echo 'Removed user '.$this->username;
					$query='DELETE FROM login_attempts WHERE user_id=?;';
					if($stmt = $mysqli->prepare($query)) {
						$stmt->bind_param('i', $user_id);
						$stmt->execute();
						if($stmt->affected_rows == 0)
							echo 'Error removing login data.';
					}
				}
				else
					echo 'Could not remove user: '.$this->username;
			}
			else
				echo 'Error in removing user';
		}
	}

	private function validConnection($force_redirect=false) {
		if(empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] != 'on') {
			if($force_redirect === true)
				echo '<meta http-equiv="Location" content="'.secureUrl().'">';
			return false;
		}
		return true;
	}

	private function startSecureSession($redirect=false) {
		if($redirect === true)
			validConnection(true);
		if(session_id() == '') {
			$session_name='session';
			if(ini_set('session.use_only_cookies', 1) === FALSE) {
				header('Location: ?err=Could not initiate a safe session (ini_set)');
				exit();
			}
			$cookieParams = session_get_cookie_params();
			session_set_cookie_params(
				3600,
				'/',
				NULL,	//NULL for localhost. 'www.johnstarich.com' for just that domain. '.johnstarich.com' = all subdomains included
				true,	//secure
				true	//httponly (disable JavaScript)
			);
			session_name($session_name);
			session_start();
			session_regenerate_id();
			// $this->username = $_SESSION['user_name'];
		}
	}

	private function getSQL() {
		if(!isset($this->mysqli)) {
			$this->mysqli = new mysqli('mysql.solarprizm.com', 'johnstarich_www', 'Welcome1', 'johnstarich_www');
			if ($this->mysqli->connect_errno) {
			    echo 'Failed to connect to MySQL: (' . $this->mysqli->connect_errno . ') ' . $this->mysqli->connect_error;
			    echo $this->mysqli->host_info;
			    $error = true;
				die('Failed to connect to MySQL: (' . $this->mysqli->connect_errno . ') ' . $this->mysqli->connect_error);
			}
		}
		return $this->mysqli;
	}

	public function validUsername() {
		if(strlen($this->username) < 4)
			echo 'At least 4 characters long';
		else if(! preg_match('/^[A-Za-z][A-Za-z0-9]*(?:_[A-Za-z0-9]+)*$/',$this->username))
			echo 'Username cannot start with a number or symbol. It must only contain letters or numbers.';
		else if($this->getUserId() !== false) {
			echo 'This user name is already taken';
		}
		else
			return true;
		return false;
	}

	public function validPassword() {
		if(strlen($this->password) < 8)
			echo 'Password must be at least 8 characters long';
		else if(! preg_match('#[\W0-9]+#', $this->password))
			echo 'Password must contain one symbol or number';
		else if(! preg_match('#[a-z]+#', $this->password))
			echo 'Password must contain one lowercase letter';
		else if(! preg_match('#[A-Z]+#', $this->password))
			echo 'Password must contain one uppercase letter';
		else
			return true;
		return false;
	}

	static function secureUrl() {
		return 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
	}
}

?>