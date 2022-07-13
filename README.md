# PHP D365
Dynamics 365 Online CRM Web API - Lightweight PHP Connector

> *Based on RDynamics by RobbeR*

## What's New
* Updated to support OAuth 2.0
* Updated to support CRM API 9.1
* Unified `select` syntax with other operations
* Code and syntax cleanup

## Prerequisites
* Using PHP-D365 requires an **Azure Application** client ID and secret. Applications and endpoints needed for configuration can be found in your [Azure Admin Portal](https://aad.portal.azure.com/#blade/Microsoft_AAD_IAM/ActiveDirectoryMenuBlade/RegisteredApps).
* An **Application User** must exist in your Dynamics 365 environment to grant security roles to connected applications. Application Users can be configured in your [PowerApps Admin Portal](https://docs.microsoft.com/en-us/power-platform/admin/manage-application-users).

## Setup
* Place `Dynamics.php` anywhere on your server, and [`include` or `require`](https://www.w3schools.com/php/php_includes.asp)) the file where you want to make Dynamics API calls.
* Initialize your Dynamics 365 Configuration:
    <pre>
    $Dynamics = new Dynamics(array(
        'base_url'              => 'https://YOUR_CRM_INSTANCE.crm.dynamics.com',
        'authEndPoint'          => 'https://login.microsoftonline.com/YOUR_AZURE_TENANT_GUID/oauth2/v2.0/authorize',
        'tokenEndPoint'         => 'https://login.microsoftonline.com/YOUR_AZURE_TENANT_GUID/oauth2/v2.0/token',
        'crmApiEndPoint'        => 'https://YOUR_CRM_INSTANCE.api.crm.dynamics.com/',
        'clientID'              => '***', 
        'clientSecret'          => '***'
    ));
    </pre>
* Call `$Dynamics->YOUR_CRM_ENTITY->operation(...)`, where 'operation' can be `select`, `insert`, `update`, or `delete`. Response will contain multiple objects, including the requested data, metadata about the API call, and methods to handle them.
* Handle response with is\* and get\* methods included in the response object: 
    * Use `isSuccess()` and `isFail()` to test whether the API call succeeded.
    * Use `getData()`, `getHeaders()`, or `getErrorMessage()` to retrieve primary information about the response, where `getData()` will contain the queried object in a `select` operation.
        * If `getData()` exceeds the row limit set by Dynamics (default 5000), use `getNextLink()` to retrieve the API endpoint for calling the next page of rows.
    * Use `getRawResponse()`, `getEndpoint()`, `getError()`, and `getGuidCreated()` to retrieve additional information about the response (see examples below).

## Usage Examples

### Querying Contacts

    $contactsResponse = $Dynamics->contacts->select('00000000-0000-0000-0000-000000000000');

or

    $contactsResponse = $Dynamics->contacts->select('(00000000-0000-0000-0000-000000000000)?$select=fullname');
    
or

    $contactsResponse = $Dynamics->contacts->select('?$select=fullname');
    if($contactsResponse->isSuccess()) {
        // $contactsResponse->getData(); - Return data as array
    }
    else {
        // $contactsResponse->getErrorMessage(); - Return Dynamics Web API error message as string
    }
    
etc.

### Querying Contacts with Paging
(For >5000 results)

    $i = 1;
    do {
        if ((isset($contactsResponse) && $contactsResponse)) {
            $endpoint = $contactsResponse->getNextLink();
            if (!$endpoint) { // no next link defined, exiting
                break;
            }
        } else { // first loop
            $endpoint = '?$select=gendercode,fullname';
        }

        $contactsResponse = $Dynamics->contacts->select($endpoint);
        if ($contactsResponse->isSuccess()) {
            // $contactsResponse->getData(); - as array
            ++$i;
        } else {
           // $contactsResponse->getErrorMessage(); - or ->getError() to get the full error object (with error_code and more)
        }
    } while ($contactsResponse->getNextLink());

### Inserting Contacts

    $contactsResponse = $Dynamics->contacts->insert(array(
        "emailaddress1"     => "some_test_email"
    ));

    if ($contactsResponse->isSuccess()) {
        // $contactsResponse->getGuidCreated(); - Get the GUID of the created entity
    } else {
        // $contactsResponse->getErrorMessage(); - Get the error message as string
    }

### Updating Contacts

    $contactsResponse = $Dynamics->contacts->update('00000000-0000-0000-0000-000000000000', array(
        "emailaddress1" => "some_test_email"
    ));

    if ($contactsResponse->isSuccess()) {
        // $contactsResponse->getData(); - Get the response data
        // $contactsResponse->getHeaders(); - Get the response headers
    } else {
        // $contactsResponse->getErrorMessage(); - Get the error message as string
    }

### Deleting Contacts

    $contactsResponse = $Dynamics->contacts->delete('00000000-0000-0000-0000-000000000000');
    if ($contactsResponse->isSuccess()) {
        // $contactsResponse->getData(); - Get the response data
        // $contactsResponse->getHeaders(); - Get the response headers
    } else {
        // $contactsResponse->getErrorMessage(); - Get the error message as string
    }

### Running Batch Methods 
(max. 1000 requests per batch)

    $contactsResponse = $Dynamics->contacts->select('?$top=10');
    if ($contactsResponse->isSuccess()) {
        $customers = $contactsResponse->getData();
        $batchID = "batch_" . uniqid();
        $payload = '';
        $i = 1;

        foreach ($customers as $customer) {
            $customerID = $customer["contactid"];
            $payload .= <<<EOT
    --$batchID
    Content-Type: application/http
    Content-Transfer-Encoding: binary
    Content-ID: $i

    PATCH https://YOUT_CRM_INSTANCE.crm.dynamics.com/api/data/v9.1/contacts($customerID) HTTP/1.1
    Content-Type: application/json;type=entry

    {"ftpsiteurl": "ftp://..."}

    EOT;
            ++$i;
        }
        $payload .= "--" . $batchID . "--\n\n";
        $batchResponse = $Dynamics->performBatchRequest($payload, $batchID);

        // $contactsResponse->getData(); - Get the response data
        // $contactsResponse->getHeaders(); - Get the response headers
    } else {
        // $contactsResponse->cccccccccccccccc(); - Get the error message as string
    }
