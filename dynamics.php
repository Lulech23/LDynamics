<?php 

/********************************************************
 *														*
 * PHP D365 Lightweight API Wrapper						*
 * by Lulech23											*
 * 														*
 ******************************************************** 
 *														*
 * Repository: https://github.com/Lulech23/PHP-D365		*
 *  - Forked From: https://github.com/RobbeR/RDynamics	*
 * Version: v2.6.1										*
 *														*
 ********************************************************/

/* 
CLASS: DYNAMICS
*/

class Dynamics {
	// Initialize class properties
	private $config = [
		'api'				=> "",
		'baseUrl'			=> "",
		'authEndPoint'		=> "",
		'tokenEndPoint'		=> "",
		'crmApiEndPoint'	=> "",
		'clientID'			=> "",
		'clientSecret'		=> ""
	];

	// Initialize inner classes
	private $DynamicsRequest;

	// Initialize class constructor
	public function __construct($config) {
		// Assign class properties
		$this->config = $config;



		/* 
		CLASS: REQUEST
		*/
	
		$this->DynamicsRequest = new class($entity = null, $config = false) {
			// Initialize class properties
			private $entity;
			private $config;

			// Initialize inner classes
			private $DynamicsResponse;
		
			// Initialize class constructor
			public function __construct($entity, $config) {
				// Assign class properties
				$this->entity = $entity;
				$this->config = $config;

	

				/* 
				CLASS: RESPONSE
				*/
			
				$this->DynamicsResponse = new class($responseBody = "", $responseHeaders = [], $endpoint = "", $originMethod = "", $rawResponse = "", $responseCode = 418) {
					// Initialize class properties
					private $data = [];
					private $responseHeaders;
					private $endpoint;
					private $originMethod;
					private $rawResponse;
					private $responseCode;

					// Initialize inner classes
					private $DynamicsFormat;
			
					// Initialize class constructor
					public function __construct($responseBody, $responseHeaders, $endpoint, $originMethod, $rawResponse, $responseCode) {
						// Assign class properties
						$this->data = (($originMethod == "batch") ? $responseBody : json_decode($responseBody, true));
						$responseHeaders = (is_array($responseHeaders) ? $responseHeaders : explode("\r\n", $responseHeaders));
						foreach ($responseHeaders as $header) {
							$props = explode(": ", $header);
							if (count($props) > 1) {
								$this->responseHeaders[$props[0]] = $props[1];
							}
						}
						$this->endpoint = $endpoint;
						$this->originMethod = $originMethod;
						$this->rawResponse = $rawResponse;
						$this->responseCode = $responseCode;
		
		
		
						/* 
						CLASS: FORMATTER
						*/
					
						$this->DynamicsFormat = new class {
					
							/* CLASS FUNCTIONS - HELPERS */
					
							/**
							 * Recursively applies the PHP `array_filter` function to a multi-dimensional array. See original documentation 
							 * for details.
							 * 
							 * @param array		$array		The array to filter
							 * @param callable  $callback	The callback function to apply to each element. Must return `true` or `false`
							 * @param int		$mode		(optional) Determines whether to pass array key as argument to filter callback
							 * 
							 * @return array
							 */
					
							private function array_filter_recursive($array, $callback, $mode = 0) {
								foreach ($array as &$value) {
									if (is_array($value)) {
										$value = $this->array_filter_recursive($value, $callback, $mode);
									}
								}
								
								return array_filter($array, $callback, $mode);
							}
					
					
							/**
							 * Checks if the given key is an OData annotation and returns `true` or `false`.
							 *
							 * @param string	$key	The string to be checked
							 * 
							 * @return bool
							 */
							
							private function is_annotation($string) {
								// Return whether annotation pattern is in string (non-strict comparator required to handle all cases)
								return ((strpos($string, "@OData") == 0) && (strpos($string, "@Microsoft") == 0));
							}
					
							/* CLASS FUNCTIONS - FORMATTERS */
					
							/**
							 * Takes a key/value pair and returns an array with the results formatted into Microsoft EntityMetadata schema.
							 * Keys must include annotations where relevant.
							 * 
							 * Exceptions include top-level "@odata.*" properties, which are left unformatted for use with dedicated functions 
							 * (e.g. `$this->getNextLink()`).
							 * 
							 * @param string	$key	The associative array index to format, including annotation
							 * @param mixed		$value	The value to store in the formatted array
							 * 
							 * @return array
							 */
							
							private function format_attribute($key, $value) {
								$annotation = array_filter(explode("@", $key));
								
								if (count($annotation) > 1) {
									// Annotated attributes
									$key = preg_replace('/^_(.*?)_value/', '$1', reset($annotation));
									$annotation = end($annotation);
					
									switch ($annotation) {
										case 'OData.Community.Display.V1.FormattedValue':
											return [
												"FormattedValues" => [
													"$key" => $value
												]
											];
										break;
					
										case 'Microsoft.Dynamics.CRM.lookuplogicalname':
											return is_null($value) ? [] : [
												"Attributes" => [
													"$key" => [
														"LogicalName" => $value
													]
												]
											];
										break;
					
										case 'Microsoft.Dynamics.CRM.totalrecordcount':
											return [
												"TotalRecordCount" => $value				// <-- Need to identify schema name (only applies to FetchXML - break out to different parser?)
											];
										break;
					
										case 'Microsoft.Dynamics.CRM.totalrecordcountlimitexceeded':
											return [
												"TotalRecordCountLimitExceeded" => $value	// <-- Need to identify schema name (only applies to FetchXML - break out to different parser?)
											];
										break;
					
										case 'Microsoft.Dynamics.CRM.fetchxmlpagingcookie':
											return [
												"FetchXMLPagingCookie" => $value			// <-- Need to identify schema name (only applies to FetchXML - break out to different parser?)
											];
										break;
					
										case 'Microsoft.Dynamics.CRM.associatednavigationproperty':
										default: 
											return [];
										break;
									}
								} else {
									if (preg_match('/^_(.*?)_value/', $key)) {
										// Non-annotated ID attributes
										$key = preg_replace('/^_(.*?)_value/', '$1', $key);
										return is_null($value) ? [] : [
											"Attributes" => [
												"$key" => [
													"Id" => $value
												]
											]
										];
									} else {
										if (strpos($key, "@") === 0) {
											// OData attributes
											return [
												"$key" => $value
											];
										} else {
											// Other attributes
											return [
												"Attributes" => [
													"$key" => $value
												]
											];
										}
									}
								}
							}
					
					
							/**
							 * Recursively applies the `format_attribute` function to all key/value pairs of a multi-dimensional array. See 
							 * original documentation for details.
							 * 
							 * @param array	$array	The array to format
							 * 
							 * @return array
							 */
							
							private function format_attribute_recursive($array) {
								$formatted_array = [];
								foreach ($array as $key => $value) {
									if (is_array($value)) {
										// If $value is an array, recursively format it
										$formatted_array[$key] = $this->format_attribute_recursive($value);
									} else {
										// If $value is not an array, format it as usual
										$formatted_array = array_merge_recursive($formatted_array, $this->format_attribute($key, $value));
									}
								}
								return $formatted_array;
							}
					
							/* CLASS FUNCTIONS - FORMATS */
					
							/**
							* Basic formatter. Returns response data with all annotations stripped out, leaving only raw values.
							*
							* @param array  $data   The API response data to format
							*
							* @return array
							*/
					
							public function basic($data) {
								if (isset($data['value']) && is_array($data['value'])) {
									$data = $data['value'];
								}
								if (isset($data['Options']) && is_array($data['Options'])) {
									return $data;
								}
								if (is_array($data)) {
									return $this->array_filter_recursive($data, [$this, "is_annotation"], ARRAY_FILTER_USE_KEY);
								} else {
									return $data;
								}
							}
					
					
							/**
							* Advanced formatter. Returns response data with all annotations included and all values sorted by annotation type 
							* (e.g. raw values under "Attributes", logical values under "FormattedValues").
							*
							* @param array  $data  The API response data to format
							*
							* @return array
							*/
					
							public function advanced($data) {
								// Format option sets
								if (isset($data['Options']) && is_array($data['Options'])) {
									return $data;
								}
					
								// Format multiple (e.g. OData filter, FetchXML)
								if (isset($data['value']) && is_array($data['value'])) {
									foreach ($data['value'] as $r => $result) {
										$formatted_data = [];
										foreach($result as $key => $value) {
											/* // Format FetchXML <link-entity> attributes	<-- Needs research on official behavior
											if (str_contains($key, ".") && !str_contains($key, "@")) {
												$key = explode(".", $key, 2);
												$value = ["{$key[1]}" => $value];
												$key = $key[0];
											}*/

											// Format values
											if (!is_array($value)) {
												$formatted_data = array_merge_recursive($formatted_data, $this->format_attribute($key, $value));
											} else {
												$formatted_data = array_merge_recursive($formatted_data, ["$key" => $this->format_attribute_recursive($value)]);
											}
										}
										$data['value'][$r] = $formatted_data;
									}
									return $data['value'];
								}
					
								// Format single (e.g. OData GUID, OData expand)
								if (is_array($data)) {
									return $this->format_attribute_recursive($data);
								} else {
									return $data;
								}
							}
						};
					}
					
					/* CLASS FUNCTIONS - ERROR HANDLING */
			
					/**
					* Returns true if request didn't contain errors. False otherwise.
					*
					* @return bool
					*/
			
					public function isSuccess() {
						if ($this->originMethod == "batch") {
							return true;
						}
						
						if (is_null($this->data) || isset($this->data['error']) || (isset($this->responseCode) && ($this->responseCode >= 400))) {
							return false;
						} else {
							return true;
						}
					}
			
			
					/**
					* Returns true if request contained errors. False otherwise.
					*
					* @return bool
					*/
			
					public function isFail() {
						return !$this->isSuccess();
					}
			
			
					/**
					* Returns the error array if there was an error. Null otherwise.
					*
					* @return array
					*/
			
					public function getError() {
						if ($this->isSuccess()) {
							return null;
						}
			
						return $this->data['error'];
					}
			
			
					/**
					* Returns the error message if there was an error. Null otherwise.
					*
					* @return string
					*/
			
					public function getErrorMessage() {
						if ($this->isSuccess()) {
							return null;
						}
			
						return $this->data['error']['message'];
					}
			
					/* CLASS FUNCTIONS - DATA HANDLING */
			
					/**
					* Returns the response data of the request as an array.
					*
					* @param boolean	$format	(optional) Enables or disables advanced formatting of response data
					*
					* @return array
					*/
			
					public function getData($format = false) {
						if ($this->isFail()) {
							return [];
						}
						
						// Initialize new response formatter
						$DynamicsFormat = new $this->DynamicsFormat();

						// Return response data in the requested format
						return (($format ? $DynamicsFormat->advanced($this->data) : $DynamicsFormat->basic($this->data)) ?? []);
					}
			
			
					/**
					* Returns the original endpoint.
					*
					* @return string
					*/
			
					public function getEndpoint() {
						return $this->endpoint;
					}
			
			
					/**
					* Returns the ID of the newly created entity (only when the request type is insert (POST)). Empty string otherwise.
					*
					* @return string
					*/
			
					public function getGuidCreated() {
						$result = "";
						if (isset($this->responseHeaders['OData-EntityId'])) {
							$result = $this->responseHeaders['OData-EntityId'];
						}
			
						preg_match('/\(.*\)/', $result, $matches);
			
						if (count($matches) > 0) {
							$result = $matches[0];
							$result = str_replace(["(", ")"], "", $result);
						}
			
						return $result;
					}
			
			
					/**
					* Returns the response headers as an array.
					*
					* @return array
					*/
			
					public function getHeaders() {
						return $this->responseHeaders;
					}
			
			
					/**
					* Returns the next page URL if request data exceeded maximum row count. Empty string otherwise.
					*
					* @return string
					*/
			
					public function getNextLink() {
						if ($this->isFail()) {
							return "";
						}
			
						if (!is_array($this->data) || !isset($this->data['@odata.nextLink']) || !$this->data['@odata.nextLink']) {
							return "";
						}
			
						return $this->data['@odata.nextLink'];
					}
			
			
					/**
					* Returns response code.
					*
					* @return int
					*/
			
					public function getResponseCode() {
						return $this->responseCode;
					}
			
			
					/**
					* Returns raw response.
					*
					* @return string
					*/
			
					public function getRawResponse() {
						return $this->rawResponse;
					}
			
			
					/**
					* Returns the request total record count (if any). -1 otherwise.
					*
					* @return int
					*/
			
					public function getTotalRecordCount() {
						if ($this->isFail()) {
							return -1;
						}
			
						if (!is_array($this->data) || !isset($this->data['@Microsoft.Dynamics.CRM.totalrecordcount']) || !$this->data['@Microsoft.Dynamics.CRM.totalrecordcount']) {
							return -1;
						}
			
						return $this->data['@Microsoft.Dynamics.CRM.totalrecordcount'];
					}
			
			
					/**
					* Returns whether request data exceeded maximum record count.
					*
					* @return boolean
					*/
			
					public function getTotalRecordCountLimitExceeded() {
						if ($this->isFail()) {
							return false;
						}
			
						if (!is_array($this->data) || !isset($this->data['@Microsoft.Dynamics.CRM.totalrecordcountlimitexceeded']) || !$this->data['@Microsoft.Dynamics.CRM.totalrecordcountlimitexceeded']) {
							return false;
						}
			
						return $this->data['@Microsoft.Dynamics.CRM.totalrecordcountlimitexceeded'];
					}
				};
			}
		
			/* CLASS FUNCTIONS - API REQUESTS */
		
			/**
			 * Fetches an access token using client credentials.
			 *
			 * @return  array
			 */
		
			private function fetchToken() {
				$params = [
					'grant_type'	=> "client_credentials",
					'scope'			=> "{$this->config['baseUrl']}/.default",
					'client_id'		=> $this->config['clientID'],
					'client_secret'	=> $this->config['clientSecret'],
				];
		
				$curl = curl_init();
				$curlopts = [
					CURLOPT_URL				=> $this->config['tokenEndPoint'],
					CURLOPT_HEADER			=> false,
					CURLOPT_RETURNTRANSFER	=> true,
					CURLOPT_FOLLOWLOCATION	=> true,
					CURLOPT_CONNECTTIMEOUT	=> 3,
					CURLOPT_TIMEOUT			=> 12,
					CURLOPT_MAXREDIRS		=> 12,
					CURLOPT_SSL_VERIFYPEER	=> 1,
					CURLOPT_ENCODING		=> ""
				];
				$curlopts[CURLOPT_POST] = true;
				$curlopts[CURLOPT_POSTFIELDS] = $params;
				curl_setopt_array($curl, $curlopts);
		
				$response = curl_exec($curl);
				$response = json_decode($response, true);
				curl_close($curl);
		
				if (isset($response['error'])) {
					return [
						'success'		=> false,
						'error'			=> $response['error'],
						'description'	=> $response['error_description'],
						'access_token'	=> false
					];
				}
		
				return [
					'success'		=> true,
					'error'			=> false,
					'description'	=> false,
					'access_token'	=> $response['access_token']
				];
			}
		
		
			/**
			* Performs a cURL request to the Dynamics Web API.
			*
			* @param string $endpoint		The API endpoint without the API URL
			* @param string $method			The method of the request. Could be 'POST', 'PATCH', 'GET', 'DELETE'
			* @param mixed  $payload		On Insert/Update requests (POST/PATCH) the array of the fields to insert/update
			* @param array  $customHeaders	Extra headers from users. Default headers: Authorization, Content-type and Accept
			* @param string $originMethod	Could be 'insert', 'update', 'delete', 'select', 'execute'
			*
			* @return mixed
			*/
		
			private function performRequest($endpoint, $method, $payload, $customHeaders, $originMethod) {
				if (empty($this->config['api'])) {
					$this->config['api'] = "9.0";
				}
		
				try {
					$endpoint = str_replace(" ", "%20", $endpoint);
					$endpoint = str_replace("''", "%27", $endpoint);
		
					if (!preg_match('/^http(s)?\:\/\//', $endpoint)) {
						if (!preg_match('/^\//', $endpoint)) {
							$endpoint = "/$endpoint";
						}
		
						$request = "{$this->config['crmApiEndPoint']}api/data/v{$this->config['api']}$endpoint";
					} else {
						$request = $endpoint;
					}
		
					$curl = curl_init($request);
					curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
		
					$token = $this->fetchToken();
		
					if (!$token['success']) {
						return new $this->DynamicsResponse(json_encode([
							'error' => [
								'message' => "<strong>TOKEN ERROR</strong> ({$token['error']}): {$token['description']}"
							]
						]), [], 400, $this->config['tokenEndPoint'], "fetch_token");
					}
		
					$requestHeaders = [
						"Accept: application/json; charset=utf-8",
						"Authorization: Bearer {$token['access_token']}",
						"If-None-Match: null",
						"OData-MaxVersion: 4.0",
						"OData-Version: 4.0",
						"Prefer: respond-async, odata.include-annotations=*"
					];
		
					if ($originMethod != "batch") {
						$requestHeaders[] = "Content-Type: application/json";
					}
		
					if ($customHeaders && is_array($customHeaders)) {
						foreach ($customHeaders as $customHeader) {
							if (!preg_match('/^Authorization/i', $customHeader)) {
								$requestHeaders[] = $customHeader;
							}
						}
					}
					
					curl_setopt($curl, CURLOPT_HTTPHEADER, $requestHeaders);
					curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
					curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
					curl_setopt($curl, CURLOPT_VERBOSE, 1);
					curl_setopt($curl, CURLOPT_HEADER, 1);
					curl_setopt($curl, CURLOPT_ENCODING, "");
					
					if ((!empty($payload)) && in_array($originMethod, ['insert', 'update', 'batch'])) { // In case of insert and update methods
						if (is_array($payload)) {
							$payload = json_encode($payload);
						}
		
						curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
					}
		
					$response = curl_exec($curl);
					$rawResponse = $response;
					$responseCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		
					// Get response headers
					$headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
					$responseHeaders = substr($response, 0, $headerSize);
					$responseBody = substr($response, $headerSize);
					curl_close($curl);
		
					return new $this->DynamicsResponse($responseBody, $responseHeaders, $endpoint, $originMethod, $rawResponse, $responseCode);
				} catch (\Exception $e) {
					return false;
				}
			}
		
		
			/**
			* Performs a batch of multiple cURL requests to the Dynamics Web API.
			*
			* @param mixed	$payload	On Insert/Update requests (POST/PATCH) the array of the fields to insert/update
			* @param string	$batchID	A unique identifier for the batch operation (does not need to be a GUID)
			*
			* @return mixed
			*/
		
			public function performBatchRequest($payload, $batchID) {
				return $this->performRequest("/\$batch", "POST", $payload, [
					"Content-Type: multipart/mixed;boundary=$batchID"
				], "batch");
			}
			
			/* CLASS FUNCTIONS - QUERY OPERATIONS */
		
			/**
			* Querying entities
			*
			* @param string	$endpoint		The API endpoint without the API URL
			* @param mixed	$extraHeaders	Extra headers from users. Default headers: Authorization, Content-type and Accept
			*
			* @return mixed
			*/
		
			public function select($endpoint = "", $extraHeaders = false) {
				if (!preg_match('/^http(s)?\:\/\//', $endpoint)) {
					if (strpos($endpoint, "?") !== false) {
						$endpoint = "/{$this->entity}$endpoint";
					} else {
						$endpoint = "/{$this->entity}($endpoint)";
					}
				}
				
				return $this->performRequest($endpoint, "GET", false, $extraHeaders, "select");
			}
		
		
			/**
			* Inserting entities
			*
			* @param mixed	$payload		On Insert/Update requests (POST/PATCH) the array of the fields to insert/update
			* @param mixed	$extraHeaders	Extra headers from users. Default headers: Authorization, Content-type and Accept
			*
			* @return mixed
			*/
		
			public function insert($payload, $extraHeaders = false) {
				return $this->performRequest("/{$this->entity}", "POST", $payload, $extraHeaders, "insert");
			}
		
		
			/**
			* Updating entities
			*
			* @param string	$GUID		The GUID of the entity you want to update
			* @param mixed	$payload	On Insert/Update requests (POST/PATCH) the array of the fields to insert/update
			*
			* @return mixed
			*/
		
			public function update($GUID, $payload, $extraHeaders = false) {
				return $this->performRequest("/{$this->entity}($GUID)", "PATCH", $payload, $extraHeaders, "update");
			}
		
		
			/**
			* Deleting entities
			*
			* @param string	$GUID	The GUID of the entity you want to delete
			*
			* @return mixed
			*/
		
			public function delete($GUID, $extraHeaders = false) {
				return $this->performRequest("/{$this->entity}($GUID)", "DELETE", false, $extraHeaders, "delete");
			}
		
		
			/**
			* Executing functions
			*
			* @param string	$endpoint		The API endpoint without the API URL
			* @param mixed	$extraHeaders	Extra headers from users. Default headers: Authorization, Content-type and Accept
			*
			* @return mixed
			*/
		
			public function execute($endpoint = "", $extraHeaders = false) {
				if (!preg_match('/^http(s)?\:\/\//', $endpoint)) {
					if (strpos($endpoint, "(") !== false) {
						$endpoint = "/{$this->entity}$endpoint";
					} else {
						$endpoint = "/{$this->entity}($endpoint)";
					}
				}
				
				return $this->performRequest($endpoint, "GET", false, $extraHeaders, "execute");
			}
		};
	}

	/* CLASS FUNCTIONS - CORE */
		
	/**
	* Passthrough batch of multiple requests to new worker object
	*
	* @param mixed	$payload	On Insert/Update requests (POST/PATCH) the array of the fields to insert/update
	* @param string	$batchID	A unique identifier for the batch operation (does not need to be a GUID)
	*
	* @return object
	*/

	public function performBatchRequest($payload, $batchID) {
		$worker = new $this->DynamicsRequest("contacts", $this->config);
		return $worker->performBatchRequest($payload, $batchID);
	}
	
		
	/**
	* Passthrough undeclared properties to new worker object. Properties will be assumed as Dynamics
	* entities on which operations are to be performed.
	*
	* @param mixed	$prop	The property on which to operate (likely a Dynamics entity logical name)
	*
	* @return object
	*/

	public function __get($prop) {
		$worker = new $this->DynamicsRequest($prop, $this->config);
		return $worker;
	}
}
