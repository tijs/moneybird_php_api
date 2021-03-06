<?php

/**
 * Communicates with Moneybird through REST API
 * http://www.moneybird.nl/
 *
 * @todo Bij gebruik van api in invoice en contact object, controleren of object geldig is
 *
 * @author Sjors van der Pluijm <sjors@phpfreakz.nl>
 */

require_once (dirname(__FILE__).'/Exceptions.php');
require_once (dirname(__FILE__).'/Object.php');
require_once (dirname(__FILE__).'/Contact.php');
require_once (dirname(__FILE__).'/Invoice.php');
require_once (dirname(__FILE__).'/RecurringTemplate.php');

/**
 * Communicates with Moneybird through REST API
 * Main class for sending request to Moneybird
 */
class MoneybirdApi
{
	/**
	 * Client name at Moneybird (<clientname>.moneybird.nl)
	 *
	 * @access protected
	 * @var string
	 */
	protected $clientname;

	/**
	 * Curl connection resource with Moneybird
	 *
	 * @access protected
	 * @var resource
	 */
	protected $connection;

	/**
	 * Array of error messages
	 *
	 * @access protected
	 * @var array
	 */
	protected $errors;

	/**
	 * Holds debug info of last request
	 *
	 * @access protected
	 * @var array
	 */
	protected $lastRequest;

	/**
	 * Constructor
	 *
	 * @param string $clientname first part of Moneybird URL (<clientname>.moneybird.nl)
	 * @param string $username username for login
	 * @param string $password password for login
	 * @access public
	 * @throws MoneybirdConnectionErrorException
	 */
	public function __construct($clientname=null, $username=null, $password=null)
	{
		// Set defaults
		$this->clientname = $clientname != null ? $clientname : 'clientname';
		$username		 = $username   != null ? $username   : 'username';
		$password		 = $password   != null ? $password   : 'password';

		$this->baseUrl = '';
		$this->errors = array();
		$this->lastRequest = null;

		$this->initConnection($username, $password);
	}

	/**
	 * Returns an array based on the type:
	 * 0 => url-part for request
	 * 1 => classname to use
	 *
	 * @param string $type (contact|invoice|recurringTemplate)
	 * @throws MoneybirdUnknownTypeException
	 * @access protected
	 * @return array
	 */
	protected function typeInfo($type)
	{
		switch ($type)
		{
			case 'contact':
			case 'invoice':
				return array($type.'s', 'Moneybird'.ucfirst($type));
			break;

			case 'recurringTemplate':
				return array('recurring_templates', 'MoneybirdRecurringTemplate');
			break;

			default:
				throw new MoneybirdUnknownTypeException('Unknown type: '.$type);
			break;
		}
	}

	/**
	 * Connect with API
	 *
	 * @throws MoneybirdConnectionErrorException
	 * @access protected
	 */
	protected function initConnection($username, $password)
	{
		if (!$this->connection = curl_init())
		{
			throw new MoneybirdConnectionErrorException('Unable to connect to Moneybird Api');
		}
		else
		{
			$setopt = curl_setopt_array(
				$this->connection,
				array(
					CURLOPT_USERPWD		=> $username.':'.$password,
					CURLOPT_HTTPAUTH	   => CURLAUTH_BASIC,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_HTTPHEADER	 => array(
						'Content-Type: application/xml',
						'Accept: application/xml'
					),
				)
			);
			if (!$setopt)
			{
				throw new MoneybirdConnectionErrorException('Unable to set cURL options'.PHP_EOL.curl_error($this->connection));
			}
		}
	}

	/**
	 * Send a request to the API
	 *
	 * @param string $url request parameters
	 * @param string $method (GET|POST|PUT|DELETE)
	 * @param iMoneybirdObject $mbObject object to change
	 * @return SimpleXMLElement
	 * @access protected
	 * @throws MoneybirdAuthorizationRequiredException
	 * @throws MoneybirdNotAcceptedException
	 * @throws MoneybirdUnprocessableEntityException
	 * @throws MoneybirdInternalServerErrorException
	 * @throws MoneybirdUnknownResponseException
	 * @throws MoneybirdItemNotFoundException
	 * @throws MoneybirdConnectionErrorException
	 * @throws MoneybirdXmlErrorException
	 */
	protected function request($url, $method='GET', iMoneybirdObject $mbObject=null)
	{
		$url = '/'.$url;

		// If called from a contact, add contacts/:id
		$trace = debug_backtrace();
		$types = array('contact', 'invoice', 'recurringTemplate');
		foreach ($trace as $traceinfo)
		{
			if (isset($traceinfo['class']))
			{
				$refclass = new ReflectionClass($traceinfo['class']);
				foreach ($types as $type)
				{
					$interface = 'iMoneybird'.ucfirst($type);
					if ($refclass->isSubclassOf($interface) && intval($traceinfo['object']->id) > 0)
					{
						list($typegroup, $class) = $this->typeInfo($type);
						$prefix = '/'.$typegroup.'/'.$traceinfo['object']->id;

						// Add $prefix to URL, but not when it's already there
						// e.g. /invoices/:id/invoices/:id/... => /invoices/:id/...
						if (strpos($url, $prefix) !== 0)
						{
							$url = $prefix.$url;
						}
						break 2;
					}
				}
			}
		}

		$curlopts = array(
			CURLOPT_URL => 'https://'.$this->clientname.'.moneybird.nl'.$url.'.xml',
		);

		$this->errors = array();

		switch ($method)
		{
			case 'GET':
			default:
				$curlopts[CURLOPT_HTTPGET] = true;
			break;

			case 'POST':
				$curlopts[CURLOPT_POST] = true;
				$xml = $mbObject->toXML();
				$curlopts[CURLOPT_POSTFIELDS] = $xml;
			break;

			case 'PUT':
				$xml = $mbObject->toXML();

				$fh  = fopen('php://memory', 'rw');
				fwrite($fh, $xml);
				rewind($fh);

				$curlopts[CURLOPT_PUT]		= true;
				$curlopts[CURLOPT_INFILE]	 = $fh;
				$curlopts[CURLOPT_INFILESIZE] = strlen($xml);
			break;

			case 'DELETE':
				$curlopts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
			break;
		}

		$setopt = curl_setopt_array($this->connection, $curlopts);
		if (!$setopt)
		{
			throw new MoneybirdConnectionErrorException('Unable to set cURL options'.PHP_EOL.curl_error($this->connection));
		}

		$xmlstring = curl_exec($this->connection);
		$xmlresponse = null;
		if (false === $xmlstring)
		{
			throw new MoneybirdConnectionErrorException('Unable perform request: '.$url.PHP_EOL.curl_error($this->connection));
		}
		elseif (trim($xmlstring) != '')
		{
			$xmlresponse = simplexml_load_string($xmlstring);
		}

		$httpresponse = curl_getinfo($this->connection, CURLINFO_HTTP_CODE);
		switch ($httpresponse)
		{
			case 100: // Continue
			case 200: // OK		 Request was successful
			case 201: // Created 	Entity was created successful
			break;

			case 401: // Authorization required	 No authorization information provided with request
				$error = new MoneybirdAuthorizationRequiredException('No authorization information provided with request');
			break;

			case 404: // The entity or action is not found in the API
				$error = new MoneybirdItemNotFoundException('The entity or action is not found in the API');
			break;
			
			case 406: // Not accepted			   The action you are trying to perform is not available in the API
				$error = new MoneybirdNotAcceptedException('The action you are trying to perform is not available in the API');
			break;
			
			case 422: // Unprocessable entity	   Entity was not created because of errors in parameters. Errors are included in XML response.
				$error = new MoneybirdUnprocessableEntityException('Entity was not created or deleted because of errors in parameters. Errors are included in XML response.');
			break;
			
			case 500: // Internal server error	  Something went wrong while processing the request. MoneyBird is notified of the error.
				$error = new MoneybirdInternalServerErrorException('Something went wrong while processing the request. MoneyBird is notified of the error.');
			break;

			default:
				$error = new MoneybirdUnknownResponseException('Unknown response from Moneybird: '.$httpresponse);
			break;
		}

		// Store debuginfo of last request
		$this->lastRequest = array(
			'url'		   => $curlopts[CURLOPT_URL],
			'method'		=> $method,
			'http-response' => $httpresponse,
			'xml-send'	  => isset($xml)?$xml:''
		);

		// If $error exists, an exception needs to be thrown
		// Before throwing an exception, parse the errors from the xml
		if (isset($error))
		{
			if ($error instanceof MoneybirdUnprocessableEntityException)
			{
				foreach ($xmlresponse as $message)
				{
					$this->errors[] = $message;
				}
			}
			throw $error;
		}

		return $xmlresponse;
	}

	/**
	 * Get single Moneybird object
	 *
	 * @param integer $objectID id of object to retreive
	 * @param string $type (contact|invoice|recurringTemplate)
	 * @return iMoneybirdObject
	 * @access protected
	 * @throws MoneybirdUnknownTypeException
	 * @throws MoneybirdInvalidIdException
	 * @throws MoneybirdItemNotFoundException
	 */
	protected function getMbObject($objectID, $type)
	{
		if (!preg_match('/^[0-9]+$/D', $objectID))
		{
			throw new MoneybirdInvalidIdException('Invalid id: '.$objectID);
		}
		list($typegroup, $class) = $this->typeInfo($type);

		$response = $this->request($typegroup.'/'.$objectID);

		$object = new $class;
		$object->fromXML($response);
		$object->setApi($this);
		return $object;
	}

	/**
	 * Get all objects
	 *
	 * @return array
	 * @param string $type (contact|invoice|recurringTemplate)
	 * @param string|iiMoneybirdFilter $filter optional, filter results
	 * @access protected
	 * @throws MoneybirdInvalidIdException
	 */
	protected function getMbObjects($type, $filter=null)
	{
		list($typegroup, $class) = $this->typeInfo($type);

		$request = $typegroup;
		$method  = 'GET';
		if ($filter != null)
		{
			$isFilterObject = false;
			if (is_object($filter))
			{
				$refclass = new ReflectionClass($filter);
				$isFilterObject = $refclass->isSubclassOf('iMoneybirdFilter');
			}
			if ($isFilterObject)
			{
				$request .= '/filter/advanced';
				$method = 'POST';
			}
			else
			{
				$request .= '/filter/'.$filter;
				$filter = null;
			}
		}

		$foundObjects = $this->request(
			$request,
			$method,
			$filter
		);

		$objects = array();
		foreach ($foundObjects as $response)
		{
			$object = new $class;
			$object->fromXML($response);
			$object->setApi($this);
			$objects[] = $object;
		}
		return $objects;
	}

	/**
	 * Save an object
	 *
	 * @return iMoneybirdObject
	 * @access protected
	 * @throws MoneybirdInvalidIdException
	 * @param iMoneybirdObject $object object to save
	 * @param string $type (contact|invoice|recurringTemplate)
	 */
	protected function saveMbObject(iMoneybirdObject $object, $type)
	{
		list($typegroup, $class) = $this->typeInfo($type);

		if (intval($object->id) > 0)
		{
			// Update object
			$this->request(
				$typegroup.'/'.$object->id,
				'PUT',
				$object
			);

			return $this->getMbObject($object->id, $type);
		}
		else
		{
			// Insert object
			$response = $this->request(
				$typegroup,
				'POST',
				$object
			);

			$object = new $class;
			$object->fromXML($response);
			$object->setApi($this);
			return $object;
		}		
	}

	/**
	 * Delete object
	 *
	 * @access protected
	 * @throws MoneybirdInvalidIdException
	 * @param iMoneybirdObject $object object to delete
	 * @param string $type (contact|invoice|recurringTemplate)
	 */
	protected function deleteMbObject(iMoneybirdObject $object, $type)
	{
		list($typegroup, $class) = $this->typeInfo($type);
		$this->request($typegroup.'/'.$object->id, 'DELETE');
	}

	/**
	 * Get a contact by ID
	 *
	 * @param integer $contactID
	 * @return MoneybirdContact
	 * @access public
	 * @throws MoneybirdInvalidIdException
	 * @throws MoneybirdItemNotFoundException
	 */
	public function getContact($contactID)
	{
		return $this->getMbObject($contactID, 'contact');
	}

	/**
	 * Get all contacts
	 *
	 * @return array
	 * @access public
	 */
	public function getContacts()
	{
		return $this->getMbObjects('contact');
	}

	/**
	 * Save contact
	 *
	 * @return MoneybirdContact
	 * @param iMoneybirdContact $contact contact to save
	 * @access public
	 */
	public function saveContact(iMoneybirdContact $contact)
	{
		return $this->saveMbObject($contact, 'contact');
	}

	/**
	 * Delete contact
	 *
	 * @param iMoneybirdContact $contact contact to delete
	 * @access public
	 */
	public function deleteContact(iMoneybirdContact $contact)
	{
		$this->deleteMbObject($contact, 'contact');
	}

	/**
	 * Get invoice
	 *
	 * @param integer $invoiceID invoice to retreive
	 * @return MoneybirdInvoice
	 * @access public
	 * @throws MoneybirdInvalidIdException
	 * @throws MoneybirdItemNotFoundException
	 */
	public function getInvoice($invoiceID)
	{
		return $this->getMbObject($invoiceID, 'invoice');
	}

	/**
	 * Get all invoices
	 *
	 * @return array
	 * @param string|iMoneybirdFilter $filter optional, filter to apply
	 * @access public
	 * @throws MoneybirdUnknownFilterException
	 */
	public function getInvoices($filter=null)
	{
		$filters = array(
			'all', 'this_month', 'last_month', 'this_quarter', 'last_quarter',
			'this_year', 'draft', 'sent', 'open', 'late', 'paid'
		);

		$isFilterObject = false;
		if (is_object($filter))
		{
			$refclass = new ReflectionClass($filter);
			$isFilterObject = $refclass->isSubclassOf('iMoneybirdFilter');
		}
		if ($filter != null && !$isFilterObject &&
			!in_array($filter, $filters))
		{
			throw new MoneybirdUnknownFilterException('Unknown filter for invoices: '.
				$filter.'.'.PHP_EOL.'Available filters: '.implode(', ', $filters));
		}

		return $this->getMbObjects('invoice', $filter);
	}

	/**
	 * Save invoice
	 *
	 * @return MoneybirdInvoice
	 * @param iMoneybirdInvoice $invoice invoice to save
	 * @access public
	 */
	public function saveInvoice(iMoneybirdInvoice $invoice)
	{
		return $this->saveMbObject($invoice, 'invoice');
	}

	/**
	 * Delete invoice
	 *
	 * @param iMoneybirdInvoice $invoice invoice to delete
	 * @access public
	 */
	public function deleteInvoice(iMoneybirdInvoice $invoice)
	{
		$this->deleteMbObject($invoice, 'invoice');
	}

	/**
	 * Get template for recurring invoices
	 *
	 * @param integer $templateID template to retreive
	 * @return MoneybirdRecurringTemplate
	 * @access public
	 * @throws MoneybirdInvalidIdException
	 * @throws MoneybirdItemNotFoundException
	 */
	public function getRecurringTemplate($templateID)
	{
		return $this->getMbObject($templateID, 'recurringTemplate');
	}

	/**
	 * Get all templates for recurring invoices
	 *
	 * @return array
	 * @access public
	 */
	public function getRecurringTemplates()
	{
		return $this->getMbObjects('recurringTemplate');
	}

	/**
	 * Save template for recurring invoice
	 *
	 * @return MoneybirdRecurringTemplate
	 * @param iMoneybirdRecurringTemplate $template template to save
	 * @access public
	 */
	public function saveRecurringTemplate(iMoneybirdRecurringTemplate $template)
	{
		return $this->saveMbObject($template, 'recurringTemplate');
	}

	/**
	 * Delete template for recurring invoice
	 *
	 * @param iMoneybirdRecurringTemplate $template template to delete
	 * @access public
	 */
	public function deleteRecurringTemplate(iMoneybirdRecurringTemplate $template)
	{
		$this->deleteMbObject($template, 'recurringTemplate');
	}

	/**
	 * Send an invoice
	 *
	 * @access public
	 * @param iMoneybirdInvoice $invoice invoice to send
	 * @param MoneybirdInvoiceSendInformation $sendinfo optional information to send invoice
	 */
	public function sendInvoice(iMoneybirdInvoice $invoice, MoneybirdInvoiceSendInformation $sendinfo = null)
	{
		if (is_null($sendinfo))
		{
			$sendinfo = new MoneybirdInvoiceSendInformation;
		}

		if (intval($invoice->id) == 0)
		{
			// Save invoice first
			$invoice = $this->saveInvoice($invoice);
		}
		$sendinfo->invoice_id = $invoice->id;

		// Send
		$this->request(
			'invoices/'.$invoice->id.'/send_invoice',
			'PUT',
			$sendinfo
		);
	}
	
	/**
	 * Mark invoice as send
	 *
	 * @access public
	 * @param iMoneybirdInvoice $invoice subjected invoice
	 */
	public function markInvoiceAsSent(iMoneybirdInvoice $invoice)
	{
		$this->sendInvoice($invoice, new MoneybirdInvoiceSendInformation('hand'));
	}

	/**
	 * Send an invoice reminder
	 *
	 * @access public
	 * @param iMoneybirdInvoice $invoice invoice to send reminder of
	 * @param MoneybirdInvoiceSendInformation $sendinfo optional information to send reminder
	 */
	public function sendInvoiceReminder(iMoneybirdInvoice $invoice, MoneybirdInvoiceSendInformation $sendinfo = null)
	{
		if (is_null($sendinfo))
		{
			$sendinfo = new MoneybirdInvoiceSendInformation;
		}

		$sendinfo->invoice_id = $invoice->id;

		// Send
		$this->request(
			'invoices/'.$invoice->id.'/send_reminder',
			'PUT',
			$sendinfo
		);
	}

	/**
	 * Register invoice payment
	 *
	 * @access public
	 * @param iMoneybirdInvoice $invoice invoice to register payment for
	 * @param MoneybirdInvoicePayment $payment payment to register
	 */
	public function registerInvoicePayment(iMoneybirdInvoice $invoice, MoneybirdInvoicePayment $payment)
	{
		if (intval($invoice->id) == 0)
		{
			// Save invoice first
			$invoice = $this->saveInvoice($invoice);
		}
		$payment->invoice_id = $invoice->id;

		// Send
		$this->request(
			'invoices/'.$invoice->id.'/payments',
			'POST',
			$payment
		);
	}

	/**
	 * Get all invoices that need a reminder
	 *
	 * Example:
	 * $invoices = $api->getRemindableInvoices(array(
	 *	 'Herinnering' => 10,
	 *	 'Tweede herinnering' => 10,
	 *	 'Aanmaning' => 10,
	 *	 'Deurwaarder' => 0,
	 * ));
	 *
	 * @access public
	 * @return array
	 * @param array $documentDays Associative array with document titles as keys and days since last document as value
	 * @param DateTime $now
	 */
	public function getRemindableInvoices(array $documentDays, DateTime $now = null)
	{
		if (is_null($now))
		{
			$now = new DateTime();
		}

		$invoices = array();
		foreach ($this->getInvoices('open') as $invoice)
		{
			$reminders = array();
			foreach ($invoice->history as $history)
			{
				if (strpos($history->action, 'invoice_reminder') === 0)
				{
					$reminders[] = $history->created_at;
				}
			}
			
			$numReminders = count($reminders);
			$numDocumentDays = count($documentDays);
			if ($numReminders > $numDocumentDays - 1)
			{
				$numReminders = $numDocumentDays - 1;
			}
			$document = array_slice($documentDays, $numReminders, 1, true);

			if ($numReminders > 0)
			{
				$nextReminder = max($reminders);
			}
			else
			{
				$nextReminder = clone($invoice->invoice_date);
				$nextReminder->modify('+'.$invoice->due_date_interval.' day');
			}
			$nextReminder->modify('+'.current($document).' day');

			if ($nextReminder->format('Ymd') <= $now->format('Ymd'))
			{
				$invoice->nextReminder = $nextReminder;
				$invoice->reminder = key($document);
				$invoice->remindable = $numReminders < $numDocumentDays - 1;
				$invoices[] = $invoice;
			}
		}

		return $invoices;
	}

	/**
	 * Return the last errors
	 *
	 * @access public
	 * @return array
	 */
	public function getErrorMessages()
	{
		$errors = $this->errors;
		$this->errors = array();
		return $errors;
	}

	/**
	 * Prints info on last request
	 *
	 * @access public
	 */
	public function debug()
	{
		echo '====== DEBUG ======'.PHP_EOL;
		if (is_array($this->lastRequest))
		{
			foreach ($this->lastRequest as $key => $value)
			{
				echo $key.': '.$value.PHP_EOL;
			}
		}
	}
}