<?php
/*+**********************************************************************************
 *	LabsMobile Implementation on vtiger 7 plugin
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

class SMSNotifier_LabsMobile_Provider implements SMSNotifier_ISMSProvider_Model {

	private $userName;
	private $password;
	private $parameters = array();

  const SERVICE_URI = 'http://api.labsmobile.com';
  
	private static $REQUIRED_PARAMETERS = array(
		array('name' => 'Sender', 'label' => 'Sender', 'type' => 'text'),
		array('name' => 'Charset', 'label' => 'Charset GSM or Unicode', 'type' => 'picklist', 'picklistvalues' => array('1' => 'GSM', '2' => 'Unicode'))
	);

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
        case self::SERVICE_AUTH: return self::SERVICE_URI . '/get/auth.php';
				case self::SERVICE_SEND:	return self::SERVICE_URI . '/get/send.php?';
				case self::SERVICE_QUERY:	return self::SERVICE_URI . '/get/ack.php?';
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
	 * Function to handle SMS Send operation
	 * @param <String> $message
	 * @param <Mixed> $toNumbers One or Array of numbers
	 */
	public function send($message, $toNumbers) {
		if(!is_array($toNumbers)) {
			$toNumbers = array($toNumbers);
    }
    
    $toNumbers = $this->cleanNumbers($toNumbers);
    $clientMessageReference = $this->generateClientMessageReference();
    $response = $this->sendMessage($clientMessageReference, $message, $toNumbers);
    return $this->processSendMessageResult($response, $clientMessageReference, $toNumbers);
  }
  
  private function generateClientMessageReference() {
		return uniqid();
  }
  
  private function cleanNumbers($numbers) {
		$pattern = '/[^\d]/';
		$replacement = '';
		return preg_replace($pattern, $replacement, $numbers);
  }
  
  private function sendMessage($clientMessageReference, $message, $tonumbers) {
    $sender = $this->getParameter('Sender', '');
    $charset = $this->getParameter('Charset', '');

    $serviceURL = $this->getServiceURL(self::SERVICE_SEND);
		$serviceURL = $serviceURL . 'username=' . urlencode($this->userName) . '&';
    $serviceURL = $serviceURL . 'password=' . urlencode($this->password) . '&';
    $serviceURL = $serviceURL . 'msisdn=' . urlencode(implode(',', $tonumbers)) . '&';
    $serviceURL = $serviceURL . 'message=' . urlencode(html_entity_decode($message)) . '&';
    $serviceURL = $serviceURL . 'sender=' . urlencode($sender) . '&';
    if($charset === '2') {
      $serviceURL = $serviceURL . 'ucs2=1&';
    }
    $serviceURL = $serviceURL . 'subid=' . urlencode($clientMessageReference);

		$httpClient = new Vtiger_Net_Client($serviceURL);
		return $httpClient->doPost(array());
  }
  
  private function processSendMessageResult($response, $clientMessageReference, $tonumbers) {
    
    $xmlNode = new SimpleXMLElement($response, LIBXML_NOCDATA);

    $results = array();
    foreach ($tonumbers as $number) {
      $result = array();
      $result['to'] = $number;

      if($xmlNode->code == 0) {
        $result['id'] = $clientMessageReference . '--' . $number; 
        $result['status'] = self::MSG_STATUS_PROCESSING;
      } else {
        $result['error'] = true; 
        $result['statusmessage'] = $xmlNode->message;
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
		$messageidSplit = split('--', $messageid);
		$clientMessageReference = trim($messageidSplit[0]);
		$number = trim($messageidSplit[1]);

		$response = $this->queryMessage($clientMessageReference, $number);
		return $this->processQueryMessageResult($response, $number);
  }
  
  private function queryMessage($clientMessageReference, $number) {
		$serviceURL = $this->getServiceURL(self::SERVICE_QUERY);
		$serviceURL = $serviceURL . 'username=' . urlencode($this->userName) . '&';
    $serviceURL = $serviceURL . 'password=' . urlencode($this->password) . '&';
    $serviceURL = $serviceURL . 'subid=' . urlencode($clientMessageReference) . '&';
		$serviceURL = $serviceURL . 'msisdn=' . urlencode($number);

		$httpClient = new Vtiger_Net_Client($serviceURL);
		return $httpClient->doPost(array());
  }
  
  private function processQueryMessageResult($response) {

    $xmlNode = new SimpleXMLElement($response, LIBXML_NOCDATA);
    
    $result = array();
    $result['needlookup'] = 1;

    switch($xmlNode->status) {
      case 'error':
        $result['error'] = true;
        $result['statusmessage'] = $xmlNode->desc;
        $result['status'] = self::MSG_STATUS_FAILED;
        break;
      case 'processed':
      case 'operator':
      case 'gateway':
        $result['error'] = false;
        $result['status'] = self::MSG_STATUS_PROCESSING;
        break;
      case 'handset':
        $result['error'] = false;
        $result['status'] = self::MSG_STATUS_DELIVERED;
        break;

    }

    return $result;
		
	}
}
?>
