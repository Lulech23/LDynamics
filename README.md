# PHP D365
Dynamics 365 Online CRM Web API - Lightweight PHP Connector

> *Based on RDynamics by RobbeR*

## What's New
* Updated to support OAuth 2.0
* Updated to support CRM API 9.1
* Unified `select` syntax with other operations
* Code and syntax cleanup

## Usage Examples
### Initializing
**Note:** See your [Azure Admin Portal](https://aad.portal.azure.com/#blade/Microsoft_AAD_IAM/ActiveDirectoryMenuBlade/RegisteredApps) for tenant ID. Create a new app registration for client credentials.

    $CRM = new Dynamics(array(
        'base_url'              => 'https://YOUR_CRM_INSTANCE.crm.dynamics.com',
        'authEndPoint'          => 'https://login.microsoftonline.com/YOUR_AZURE_TENANT_GUID/oauth2/v2.0/authorize',
        'tokenEndPoint'         => 'https://login.microsoftonline.com/YOUR_AZURE_TENANT_GUID/oauth2/v2.0/token',
        'crmApiEndPoint'        => 'https://YOUR_CRM_INSTANCE.api.crm.dynamics.com/',
        'clientID'              => '***', 
        'clientSecret'          => '***'
    ));

### Querying Contacts

    $contactsResponse = $CRM->contacts->select('00000000-0000-0000-0000-000000000000');

or

    $contactsResponse = $CRM->contacts->select('(00000000-0000-0000-0000-000000000000)?$select=fullname');
    
or

    $contactsResponse = $CRM->contacts->select('?$select=fullname');
    if($contactsResponse->isSuccess()) {
        // $contactsResponse->getData(); - Return data as array
    }
    else {
        // $contactsResponse->getErrorMessage(); - Return CRM Web API error message as string
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

        $contactsResponse = $CRM->contacts->select($endpoint);
        if ($contactsResponse->isSuccess()) {
            // $contactsResponse->getData(); - as array
            ++$i;
        } else {
           // $contactsResponse->getErrorMessage(); - or ->getError() to get the full error object (with error_code and more)
        }
    } while ($contactsResponse->getNextLink());

### Inserting Contacts

    $contactsResponse = $CRM->contacts->insert(array(
        "emailaddress1"     => "some_test_email"
    ));

    if ($contactsResponse->isSuccess()) {
        // $contactsResponse->getGuidCreated(); - Get the GUID of the created entity
    } else {
        // $contactsResponse->getErrorMessage(); - Get the error message as string
    }

### Updating Contacts

    $contactsResponse = $CRM->contacts->update('00000000-0000-0000-0000-000000000000', array(
        "emailaddress1" => "some_test_email"
    ));

    if ($contactsResponse->isSuccess()) {
        // $contactsResponse->getData(); - Get the response data
        // $contactsResponse->getHeaders(); - Get the response headers
    } else {
        // $contactsResponse->getErrorMessage(); - Get the error message as string
    }

### Deleting Contacts

    $contactsResponse = $CRM->contacts->delete('00000000-0000-0000-0000-000000000000');
    if ($contactsResponse->isSuccess()) {
        // $contactsResponse->getData(); - Get the response data
        // $contactsResponse->getHeaders(); - Get the response headers
    } else {
        // $contactsResponse->getErrorMessage(); - Get the error message as string
    }

### Running Batch Methods 
(max. 1000 requests per batch)

    $contactsResponse = $CRM->contacts->select('?$top=10');
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
    Content-Transfer-Encoding:binary
    Content-ID:$i

    PATCH https://YOUT_CRM_INSTANCE.crm.dynamics.com/api/data/v9.1/contacts($customerID) HTTP/1.1
    Content-Type: application/json;type=entry

    {"ftpsiteurl":"ftp://..."}

    EOT;
            ++$i;
        }
        $payload .= "--" . $batchID . "--\n\n";
        $batchResponse = $CRM->performBatchRequest($payload, $batchID);

        // $contactsResponse->getData(); - Get the response data
        // $contactsResponse->getHeaders(); - Get the response headers
    } else {
        // $contactsResponse->getErrorMessage(); - Get the error message as string
    }
