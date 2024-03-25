<?php

/* 
CLASS: DYNAMICS
*/

class Dynamics {
    private $config = array(
        'api'               => '',
        'baseUrl'           => '',
        'authEndPoint'      => '',
        'tokenEndPoint'     => '',
        'crmApiEndPoint'    => '',
        'clientID'          => '',
        'clientSecret'      => ''
    );

    public function __construct($config) {
        $this->config = $config;
    }

    public function performBatchRequest($payload, $batchID) {
        $worker = new DynamicsWorker('contacts', $this->config);
        return $worker->performBatchRequest($payload, $batchID);
    }

    public function __get($prop) {
        $worker = new DynamicsWorker($prop, $this->config);
        return $worker;
    }
}



/* 
CLASS: WORKER
*/

class DynamicsWorker {
    private $entity = null;
    private $config = false;

    private function fetchToken() {
        $params = array(
            'grant_type'    => 'client_credentials',
            'scope'         => $this->config['baseUrl'] . '/.default',
            'client_id'     => $this->config['clientID'],
            'client_secret' => $this->config['clientSecret'],
        );

        $curl = curl_init();
        $curlopts = [
            CURLOPT_URL             => $this->config['tokenEndPoint'],
            CURLOPT_HEADER          => false,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_CONNECTTIMEOUT  => 3,
            CURLOPT_TIMEOUT         => 12,
            CURLOPT_MAXREDIRS       => 12,
            CURLOPT_SSL_VERIFYPEER  => 0
        ];
        $curlopts[CURLOPT_POST] = true;
        $curlopts[CURLOPT_POSTFIELDS] = $params;
        curl_setopt_array($curl, $curlopts);

        $response = curl_exec($curl);
        $response = json_decode($response, true);

        if(isset($response['error'])) {
            return array(
                'success'       => false,
                'error'         => $response['error'],
                'description'   => $response['error_description'],
                'access_token'  => false
            );
        }

        return array(
            'success'       => true,
            'error'         => false,
            'description'   => false,
            'access_token'  => $response['access_token']
        );
    }


    /**
    * Perform a cURL request to the CRM Web API
    *
    * @param $endpoint - The API endpoint without the API URL
    * @param $method - The method of the request. Could be 'POST', 'PATCH', 'GET', 'DELETE'
    * @param $payload - On Insert/Update requests (POST/PATCH) the array of the fields to insert/update
    * @param $customHeaders - Extra headers from users. Default headers: Authorization, Content-type and Accept
    * @param $originMethod - Could be 'insert', 'update', 'delete', 'select', 'execute'
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
                    $endpoint = '/' . $endpoint;
                }

                $request = $this->config['crmApiEndPoint'] . "api/data/v". $this->config['api'] . $endpoint;
            } else {
                $request = $endpoint;
            }

            $curl = curl_init($request);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);

            $token = $this->fetchToken();

            if (!$token['success']) {
                return new DynamicsResponse(json_encode(array(
                    'error'     => array(
                        'message'   => '<strong>TOKEN ERROR</strong> (' . $token['error'] . '): ' . $token['description']
                    )
                )), array(), $this->config['tokenEndPoint'], "fetch_token");
            }

            $requestHeaders = array(
                'Accept: application/json; charset=utf-8',
                'Authorization: Bearer ' . $token['access_token'],
                'If-None-Match: null',
                'OData-MaxVersion: 4.0',
                'OData-Version: 4.0',
                'Prefer: respond-async, odata.include-annotations="*"'
            );

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
            
            if ((!empty($payload)) && in_array($originMethod, array('insert', 'update', 'batch'))) { // In case of insert and update methods
                if (is_array($payload)) {
                    $payload = json_encode($payload);
                }

                curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
            }

            $response = curl_exec($curl);
            $rawResponse = $response;

            // Get response headers
            $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
            $responseHeaders = substr($response, 0, $headerSize);
            $responseBody = substr($response, $headerSize);

            return new DynamicsResponse($responseBody, $responseHeaders, $endpoint, $originMethod, $rawResponse);
        } catch (\Exception $e) {
            return false;
        }
    }


    public function __construct($entity, $config) {
        $this->config = $config;
        $this->entity = $entity;
    }


    public function performBatchRequest($payload, $batchID) {
        return $this->performRequest('/$batch', 'POST', $payload, array(
            'Content-Type: multipart/mixed;boundary=' . $batchID
        ),  'batch');
    }


    /**
    * Querying entities
    *
    * @param $endpoint - The API endpoint without the API URL
    * @param $extraHeaders - Extra headers from users. Default headers: Authorization, Content-type and Accept
    */

    public function select($endpoint = '', $extraHeaders = false) {
        if (!preg_match('/^http(s)?\:\/\//', $endpoint)) {
            if (strpos($endpoint, '?') !== false) {
                $endpoint = '/' . $this->entity . $endpoint;
            } else {
                $endpoint = '/' . $this->entity . '(' . $endpoint . ')';
            }
        }
        
        return $this->performRequest($endpoint, 'GET', false, $extraHeaders, "select");
    }


    /**
    * Inserting entities
    *
    * @param $payload - On Insert/Update requests (POST/PATCH) the array of the fields to insert/update
    * @param $extraHeaders - Extra headers from users. Default headers: Authorization, Content-type and Accept
    */

    public function insert($payload, $extraHeaders = false) {
        return $this->performRequest('/' . $this->entity, 'POST', $payload, $extraHeaders, "insert");
    }


    /**
    * Updating entities
    *
    * @param $GUID - The GUID of the entity you want to update
    * @param $payload - On Insert/Update requests (POST/PATCH) the array of the fields to insert/update
    */

    public function update($GUID, $payload, $extraHeaders = false) {
        return $this->performRequest('/' . $this->entity . '(' . $GUID . ')', 'PATCH', $payload, $extraHeaders, "update");
    }


    /**
    * Deleting entities
    *
    * @param $GUID - The GUID of the entity you want to delete
    */

    public function delete($GUID, $extraHeaders = false) {
        return $this->performRequest('/' . $this->entity . '(' . $GUID . ')', 'DELETE', false, $extraHeaders, "delete");
    }


    /**
    * Executing functions
    *
    * @param $endpoint - The API endpoint without the API URL
    * @param $extraHeaders - Extra headers from users. Default headers: Authorization, Content-type and Accept
    */

    public function execute($endpoint = '', $extraHeaders = false) {
        if (!preg_match('/^http(s)?\:\/\//', $endpoint)) {
            if (strpos($endpoint, '(') !== false) {
                $endpoint = '/' . $this->entity . $endpoint;
            } else {
                $endpoint = '/' . $this->entity . '(' . $endpoint . ')';
            }
        }
        
        return $this->performRequest($endpoint, 'GET', false, $extraHeaders, "execute");
    }
}



/* 
CLASS: RESPONSE
*/

class DynamicsResponse {
    private $endpoint = '';
    private $rawResponse = '';
    private $data = false;
    private $responseHeaders = array();
    private $originMethod = '';


    public function __construct($responseBody, $respHeaders, $endpoint, $originMethod, $rawResponse = '') {
        $this->rawResponse = $rawResponse;

        if ($originMethod != "batch") {
            $this->data = json_decode($responseBody, true);
        } else {
            $this->data = $responseBody;
        }
        
        $this->endpoint = $endpoint;
        $this->originMethod = $originMethod;

        if (!is_array($respHeaders)) {
            $array = explode("\r\n", $respHeaders);
        } else {
            $array = $respHeaders;
        }
        foreach ($array as $h) {
            $r = explode(": ", $h);
            if (count($r) > 1) {
                $this->responseHeaders[$r[0]] = $r[1];
            }
        }
    }


    /* ERROR HANDLING */

    /**
    * Returns true if request didn't contain errors. False otherwise.
    *
    * @return bool
    */

    public function isSuccess() {
        if ($this->originMethod == "batch") {
            return true;
        }
        
        if (isset($this->data['error'])) {
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
    * Get the error array if there was an error. Null otherwise.
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
    * Get the error message if there was an error. Null otherwise.
    *
    * @return string
    */

    public function getErrorMessage() {
        if ($this->isSuccess()) {
            return null;
        }

        return $this->data['error']['message'];
    }


    /* DATA HANDLING */

    /**
    * Get the response data of the request as array.
    *
    * @param $format    Enables or disables formatting resulting data
    *                   (Optional; default disabled)
    * @return array
    */

    public function getData($format = false) {
        if (!$this->isSuccess()) {
            return [];
        }

        $DynamicsFormat = new DynamicsFormat();
        return ($format ? $DynamicsFormat->advanced($this->data) : $DynamicsFormat->basic($this->data));
    }


    /**
    * Get original endpoint
    *
    * @return string
    */

    public function getEndpoint() {
        return $this->endpoint;
    }


    /**
    * Get the ID of the newly created entity (only when the request type is insert (POST)). Empty string otherwise.
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
            $result = str_replace(array("(",")"), "", $result);
        }

        return $result;
    }


    /**
    * Get the response headers as an array.
    *
    * @return array
    */

    public function getHeaders() {
        return $this->responseHeaders;
    }


    /**
    * Get the request nextlink (if any). Empty string otherwise.
    *
    * @return string
    */

    public function getNextLink() {
        if (!$this->isSuccess()) {
            return "";
        }

        if (!is_array($this->data) || !isset($this->data['@odata.nextLink']) || !$this->data['@odata.nextLink']) {
            return "";
        }

        return $this->data['@odata.nextLink'];
    }


    /**
    * Get raw response
    *
    * @return string
    */

    public function getRawResponse() {
        return $this->rawResponse;
    }


    /**
    * Get the request total record count (if any). -1 otherwise.
    *
    * @return int
    */

    public function getTotalRecordCount() {
        if (!$this->isSuccess()) {
            return -1;
        }

        if (!is_array($this->data) || !isset($this->data['@Microsoft.Dynamics.CRM.totalrecordcount']) || !$this->data['@Microsoft.Dynamics.CRM.totalrecordcount']) {
            return -1;
        }

        return $this->data['@Microsoft.Dynamics.CRM.totalrecordcount'];
    }


    /**
    * Get the request total record count (if any). False otherwise.
    *
    * @return boolean
    */

    public function getTotalRecordCountLimitExceeded() {
        if (!$this->isSuccess()) {
            return false;
        }

        if (!is_array($this->data) || !isset($this->data['@Microsoft.Dynamics.CRM.totalrecordcountlimitexceeded']) || !$this->data['@Microsoft.Dynamics.CRM.totalrecordcountlimitexceeded']) {
            return false;
        }

        return $this->data['@Microsoft.Dynamics.CRM.totalrecordcountlimitexceeded'];
    }
}



/* 
CLASS: FORMATTER
*/

class DynamicsFormat {

    /* HELPER FUNCTIONS */

    /**
    * Performs PHP array_filter recursively
    *
    * @return array
    */

    private function array_filter_recursive($array, $callback, $mode) {
        foreach ($array as &$value) {
            if (is_array($value)) {
                $value = $this->array_filter_recursive($value, $callback, $mode);
            }
        }
        
        return array_filter($array, $callback, $mode);
    }


    /**
    * Returns true if the passed in key contains annotations. False otherwise.
    *
    * @return boolean
    */
    
    private function filter_exclude_annotations($key) {
        return ((strpos($key, '@OData') == 0) && (strpos($key, '@Microsoft') == 0)); // Use non-strict comparator (required to handle all cases)
    }


    /**
     * Takes a key/value pair and returns an array with the results formatted
     * into Microsoft EntityMetadata schema. Keys must include annotations
     * where relevant.
     * 
     * Exceptions include top-level "@odata.X" properties, which are left
     * unformatted for use with dedicated functions (e.g. `$this->getNextLink()`)
     * 
     * @param $key       The associative array index to format, including annotation
     * @param $value     The value to store in the formatted array
     * 
     * @return array
     */
    
    private function format_attribute($key, $value) {
        $annotation = array_filter(explode('@', $key));
        
        if (count($annotation) > 1) {
            /* ANNOTATED ATTRIBUTES */
            $key = preg_replace('/^_(.*?)_value/', '$1', reset($annotation));
            $annotation = end($annotation);

            switch ($annotation) {
                case 'OData.Community.Display.V1.FormattedValue':
                    return array(
                        "FormattedValues" => array(
                            "$key" => $value
                        )
                    );
                break;

                case 'Microsoft.Dynamics.CRM.lookuplogicalname':
                    return is_null($value) ? array() : array(
                        "Attributes" => array(
                            "$key" => array(
                                "LogicalName" => $value
                            )
                        )
                    );
                break;

                case 'Microsoft.Dynamics.CRM.totalrecordcount':
                    return array(
                        "TotalRecordCount" => $value    // <-- Need to identify schema name (only applies to FetchXML - break out to different parser?)
                    );
                break;

                case 'Microsoft.Dynamics.CRM.totalrecordcountlimitexceeded':
                    return array(
                        "TotalRecordCountLimitExceeded" => $value    // <-- Need to identify schema name (only applies to FetchXML - break out to different parser?)
                    );
                break;

                case 'Microsoft.Dynamics.CRM.fetchxmlpagingcookie':
                    return array(
                        "FetchXMLPagingCookie" => $value    // <-- Need to identify schema name (only applies to FetchXML - break out to different parser?)
                    );
                break;

                case 'Microsoft.Dynamics.CRM.associatednavigationproperty':
                default: 
                    return array();
                break;
            }
        } else {
            if (preg_match('/^_(.*?)_value/', $key)) {
                /* NON-ANNOTATED ID ATTRIBUTES */
                $key = preg_replace('/^_(.*?)_value/', '$1', $key);
                return is_null($value) ? array() : array(
                    "Attributes" => array(
                        "$key" => array(
                            "Id" => $value
                        )
                    )
                );
            } else {
                if (strpos($key, "@") === 0) {
                    /* ODATA ATTRIBUTES */
                    return array(
                        "$key" => $value
                    );
                } else {
                    /* OTHER ATTRIBUTES */
                    return array(
                        "Attributes" => array(
                            "$key" => $value
                        )
                    );
                }
            }
        }
    }


    /**
     * Performs private format_attribute function recursively
     * 
     * @param $array     The associative array to format, including annotations
     * 
     * @return array
     */
    
    private function format_attribute_recursive($array) {
        $formatted_array = array();
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


    /* FORMATS */

    /**
    * Returns response data with all annotations stripped out, leaving only
    * basic raw values.
    *
    * @param $data - The API response data to format
    * @return array
    */

    public function basic($data) {
        if (isset($data['value']) && is_array($data['value'])) {
            $data = $data['value'];
        }
        if (isset($data['Options']) && is_array($data['Options'])) {
            return $data;
        }
        
        return $this->array_filter_recursive($data, array($this, "filter_exclude_annotations"), ARRAY_FILTER_USE_KEY);
    }


    /**
    * Return response data with all annotations included and all values sorted
    * by annotation type (e.g. raw values under "Attributes", logical values
    * under "FormattedValues").
    *
    * @param $data - The API response data to format
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
                $formatted_data = array();
                foreach($result as $key => $value) {
                    if (!is_array($value)) {
                        $formatted_data = array_merge_recursive($formatted_data, $this->format_attribute($key, $value));
                    } else {
                        $formatted_data = array_merge_recursive($formatted_data, array("$key" => $this->format_attribute_recursive($value)));
                    }
                }
                $data['value'][$r] = $formatted_data;
            }
            return $data['value'];
        }

        // Format single (e.g. OData GUID, OData expand)
        return $this->format_attribute_recursive($data);
    }
}
