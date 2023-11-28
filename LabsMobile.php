<?php
/*+**********************************************************************************
 *	LabsMobile Implementation on vtiger 6 plugin
 *
 * From: alphanumeric string (max 11 chars) or numeric sender
 * 
 * IMPORTANT
 * 
 * In order to make SMSNotifier Module works, you have to:
 *  1. change line 35, in /modules/SMSNotifier/models/Provider.php
 *
 *    Change this:
 *  
 *	    if (!in_array($file, array('.', '..', 'MyProvider.php', 'CVS'))) {
 * 
 *    to this:
 *
 *		if (!in_array($file, array('.', '..', 'MyProvider.php', 'SMSProvider.php'))) {
 * 
 * 2. Copy this file into /modules/SMSNotifier/providers/
 *
 * 3. Configure your LabsMobile details (username/passsword).
 *    With you admin user go to SMSNotifier -> Server configuration ->  New Configuration. 
 *    Select LabsMobile as supplier, your username and password and the default sender.
 ************************************************************************************/
 
define('NET_ERROR', 'Network+error-impossible+to+send+the+message');

class SMSNotifier_LabsMobile_Provider implements SMSNotifier_ISMSProvider_Model {

	private $userName;
	private $password;
	private $parameters = array();

	const SERVICE_URI = 'https://api.labsmobile.com'; 
	private static $REQUIRED_PARAMETERS = array('From'); // parameters specific of LabsMobile

	/**
	 * Function to get provider name
	 * @return <String> provider name
	 */
	public function getName() {
		return 'LabsMobile';
	}

	/**
	 * Function to get required parameters other than (userName, password)
	 * @return <array> required parameters list
	 */
	public function getRequiredParams() {
		return self::$REQUIRED_PARAMETERS;
	}

	/**
	 * Function to get service URL to use for a given type
	 * @param <String> $type like SEND, PING, QUERY
	 */
	public function getServiceURL($type = false) {
		if($type) {
			switch(strtoupper($type)) {
				case self::SERVICE_AUTH: return  self::SERVICE_URI . '/';
				case self::SERVICE_SEND: return  self::SERVICE_URI . '/get/send.php';
				case self::SERVICE_QUERY: return self::SERVICE_URI . '/';
			}
		}
		return false;
	}

	/**
	 * Function to set authentication parameters
	 * @param <String> $userName
	 * @param <String> $password
	 */
	public function setAuthParameters($userName, $password) {
		$this->userName = $userName;
		$this->password = $password;
	}

	/**
	 * Function to set non-auth parameter.
	 * @param <String> $key
	 * @param <String> $value
	 */
	public function setParameter($key, $value) {
		$this->parameters[$key] = $value;
	}

	/**
	 * Function to get parameter value
	 * @param <String> $key
	 * @param <String> $defaultValue
	 * @return <String> value/$default value
	 */
	public function getParameter($key, $defaultValue = false) {
		if(isset($this->parameters[$key])) {
			return $this->parameters[$key];
		}
		return $defaultValue;
	}

	/**
	 * Function to prepare parameters
	 * @return <Array> parameters
	 */
	protected function prepareParameters() {
		$params = array('username' => $this->userName, 'password' => $this->password);
		foreach (self::$REQUIRED_PARAMETERS as $key) {
			$params[$key] = $this->getParameter($key);
		}
		return $params;
	}

	/**
	 * Function to handle SMS Send operation
	 * @param <String> $message
	 * @param <Mixed> $recipients One or Array of numbers
	 */
	public function send($message, $recipients) {
		if(!is_array($recipients)) {
			$recipients = array($recipients);
		}

		$params = $this->prepareParameters();

			foreach($recipients as $key => $value){
				if($params['Prefix'] && is_numeric($params['Prefix'])){
					$finalRecipient = $params['Prefix'].$value;				
				}
				else{
					$finalRecipient = $value;				
				}

				// strip all non numeric 
				$finalRecipient = preg_replace('/[^0-9]+/', '', $finalRecipient);

				// strip leading 0 and +
				$finalRecipient = ltrim($finalRecipient, '0+');
				$recipients[$key] = $finalRecipient;
			}

		$sender = $params['From'] ? $params['From'] : 'SMS';
		$response = $this->labsmobileGatewaySendSMS($params['username'],$params['password'],$recipients,$message,$sender);

		$results = array();
		foreach($recipients as $to) {
			$result = array(  'to' => $to );
			if('success' == $response['status']){
				$result['id'] = $response['id'] ? $response['id'] : $to;
				$result['status'] = self::MSG_STATUS_DISPATCHED;
				$result['error'] = false;
				$result['statusmessage'] = 'Sent';
			}
			else{
				$result['status'] = self::MSG_STATUS_FAILED;
				$result['error'] = true;
				$result['statusmessage'] = $result['message'];	
			}
			$results[] = $result;
		}

		return $results;
	}

	/**
	 * Function to get query for status using messgae id
	 * @param <Number> $messageId
	 */
	public function query($messageid) {
		$result = array( 'error' => false, 'needlookup' => 1 );
		$result['status'] = self::MSG_STATUS_DISPATCHED;
		$result['needlookup'] = 0;
		return $result;
	}
	
	function do_request($url, $data, $method = 'POST', $optional_headers = null){
		if(!function_exists('curl_init')) {
			$params = array(
					'http' => array(
							'method' => $method,
							'content' => $data
					)
			);
			if ($optional_headers !== null) {
				$params['http']['header'] = $optional_headers;
			}
			$ctx = stream_context_create($params);
			$fp = @fopen($url, 'rb', false, $ctx);
			if (!$fp) {
				return 'status=failed&message='.NET_ERROR;
			}
			$response = @stream_get_contents($fp);
			if ($response === false) {
				return 'status=failed&message='.NET_ERROR;
			}
			return $response;
		} else {
			$ch = curl_init();
			curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,10);
			curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
			curl_setopt($ch,CURLOPT_TIMEOUT,60);
			curl_setopt($ch,CURLOPT_USERAGENT,'Generic Client');
			if($method == 'POST'){
				curl_setopt($ch,CURLOPT_POSTFIELDS,$data);	
				curl_setopt($ch,CURLOPT_POST,true);
			}
			curl_setopt($ch,CURLOPT_URL,$url);
	
			if ($optional_headers !== null) {
				curl_setopt($ch,CURLOPT_HTTPHEADER,$optional_headers);
			}

			$response = curl_exec($ch);
			curl_close($ch);
			if(!$response){
				return 'status=failed&message='.NET_ERROR;
			} else {
				$ini_code = stripos($response, '<code>');
				$end_code = stripos($response, '</code>');
				$code = substr($response, $ini_code+6, $end_code-$ini_code-6);
				
				$ini_message = stripos($response, '<message>');
				$end_message = stripos($response, '</message>');
				$message = substr($response, $ini_message+6, $end_message-$ini_message-6);
				
				if($code == '0'){
					return 'status=success&message='.$message;
				} else {
					return 'status=failed&message='.$message;
				}
			}
			return $response;
		}
	}
	
	function labsmobileGatewaySendSMS($username,$password,$recipients,$text,$sender = 'SMS',$optional_headers=null) {
		$url = $this->getServiceUrl(self::SERVICE_SEND)."/";
	
		$parameters = 'username='.urlencode($username).'&'
			.'password='.urlencode($password).'&'
			.'message='.urlencode($text).'&'
			.'sender='.urlencode($sender).'&'
			.'msisdn='.implode(',',$recipients);

		$result = $this->do_request($url."?".$parameters, "", 'GET', $optional_headers);

		return parse_str($result);
	}

}
?>
