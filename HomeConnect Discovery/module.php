<?php

require_once( dirname(dirname(__FILE__) ) . "/libs/tools/HomeConnectApi.php");
require_once( dirname(dirname(__FILE__) ) . "/libs/tools/tm/tm.php");
$data = json_decode( file_get_contents( dirname(dirname(__FILE__) ) . "/libs/tools/tm/data.json" ), true );


class HomeConnectDiscovery extends IPSModule {

    /*
    * Internal function of SDK
    */
    public function Create()
    {
        // Overwrite ips function
        parent::Create();

        $this->RegisterPropertyString("auth_url", null);
    }
    /*
     * Internal function of SDK
     */
    public function ApplyChanges() {
        // Overwrite ips function
        parent::ApplyChanges();

        $this->ReloadForm();
    }

    //-----------------------------------------------------< Profiles >------------------------------

    /** Function for Authorization and Token
     * @param $opt
     * @return bool|mixed
     */
    public function tm($opt) {
        switch ($opt) {
            case "auth":
                try {
                    // authorize through a button
                    authorize($this->ReadPropertyString("auth_url"));
                } catch (Exception $ex) {
                    $this->analyseEX($ex);
                }
                break;
            case "token":
                try {
                    // refresh token with a button
                    return getToken("https://api.home-connect.com/security/oauth/token", "35C7EC3372C6EB5FB5378505AB9CE083D80A97713698ACB07B20C6E41E5E2CD5", "EC9B4140CB439DF1BEEE39860141077C92C553AC65FEE729B88B7092B745B1F7");
                } catch (Exception $ex) {
                    $this->analyseEX($ex);
                }
                break;
            case "get":
                // shows codes
                global $data;
                return "AuthCode: " . getAuthorizeCode() . "  /  Token: " . getAccessToken();
            case "reset":
                // reset the data.json
                resetData();
                break;
        }
    }

    /** Return Visible
     * @return bool
     */
    protected function visible() {
        return getAccessToken() == null;
    }


    //-----------------------------------------------------< Setting Form.json >------------------------------
    protected function GetDevices() {
        global $data;

        // Reading the auth Code and token from the data.json file
        $auth_code = getAuthorizeCode();
        $token = getAccessToken();

        // Send Api request
        try {
            // Send information that the token is now ready and after a new refresh the devices will show up
            if ( is_string( $auth_code ) && !is_string( $token ) ) {
                getToken("https://api.home-connect.com/security/oauth/token", "35C7EC3372C6EB5FB5378505AB9CE083D80A97713698ACB07B20C6E41E5E2CD5", "EC9B4140CB439DF1BEEE39860141077C92C553AC65FEE729B88B7092B745B1F7");
                return [['name' => 'Retry [Refresh]', 'device' => ' ', 'company' => ' ', 'haId' => 'Überprüfe ob du eingeloggt bist/Check if youre logged in', 'connected' => ' ', 'rowColor' => '#ff0000']];
            }
            // else get device list:
            $data = Api("homeappliances")['data']['homeappliances'];
        } catch(Exception $ex) {
            $this->analyseEX($ex);
            // Catch in case of error reset Data (most of the error caused by wrong auth code which can only get fixed by clearing the data.json file)
            resetData();
            // Return the User a information, what to do next
            return [[ 'name' => 'No Devices [Login]', 'device' => ' ', 'company' => ' ', 'haId' => 'Überprüfe ob du eingeloggt bist/Check if youre logged in', 'connected' => ' ', 'rowColor' => '#ff0000']];
        }

        // count the devices in the array
        $len = count($data);
        // define $devices
        $devices = [];
        // build array up
        for ($i = 0; $i < $len; $i++) {
            array_push($devices, $data[$i] );
        }

        // list of all instances with association: Name => InstanceID
        $instances = [];
        // list of oven modules
        $instances_device = array_merge( IPS_GetInstanceListByModuleID ('{4D8D592A-63C7-B2BD-243F-C6BF1DCAD66C}'), IPS_GetInstanceListByModuleID ('{CCE508B4-7A15-4541-06B0-03C9DA28A5F1}'));
        for( $i = 0; $i < count($instances_device); $i++ ) {
            $instances[ IPS_GetName($instances_device[$i]) ] = IPS_GetInstance($instances_device[$i])['InstanceID'];
        }

        $config_list = [];

        if (!empty($devices)) {
            foreach ($devices as $device) {
                $name = $device['name'];
                $brand = $device['brand'];
                $connected = $device['connected'];
                $type = $device['type'];
                $haId = $device['haId'];
                $instanceID = 0;
                $id = substr( $haId, -2 );

                // Search for matching module
                switch ( $type ) {
                    case "Oven":
                        $module = "{4D8D592A-63C7-B2BD-243F-C6BF1DCAD66C}";
                        break;
                    case "Dishwasher":
                        $module = "{CCE508B4-7A15-4541-06B0-03C9DA28A5F1}";
                        break;
                    default:
                        // TODO: correct error
                        echo "cant be added!";
                        $module = '{}';
                        break;
                }

                // get instance if exist
                if ( isset( $instances[$name] ) ) $instanceID = $instances[$name];

                $config_list[] = [
                    'name' => $name,
                    'device' => $type,
                    'company' => $brand,
                    'haId' => $haId,
                    'connected' => $connected,
                    'id' => $id,
                    'instanceID' => $instanceID,
                    'create'     => [
                        'moduleID'      => $module,
                        'configuration' => [
                            'name' => $name,
                            'device' => $type,
                            'company' => $brand,
                            'haId' => $haId,
                        ],
                    ],
                ];
            }
        }

        return  $config_list;
    }

    public function GetConfigurationForm()
    {
        // return current form
        $Form = json_encode([
            'elements' => $this->FormElements(),
            'actions'  => $this->FormActions(),
            'status'   => $this->FormStatus(),
        ]);
        $this->SendDebug('FORM', $Form, 0);
        $this->SendDebug('FORM', json_last_error_msg(), 0);

        return $Form;
    }

    /**
     * @return array[] Form Actions
     */
    protected function FormActions() {
        return[
            [
                "type" => "Button",
                "caption" => "Logout",
                "onClick" => 'HomeConnectDiscovery_tm( $id, "reset" );',
                'confirm' => 'Bist du sicher, dass du dich ausloggen willst.'
            ],
            [
                "type" => "Button",
                "caption" => "Login",
                "onClick" => 'HomeConnectDiscovery_tm( $id, "auth" );',
            ]
        ];
    }

    /**
     * @return array[] Form Elements
     */
    protected function FormElements() {
        $visible = $this->visible();

        return[
            [
                "type" => "Label",
                "name" => "loggedIn",
                "caption" => "Erfolgreich eingeloggt!",
                "visible" => !$visible,
            ],
            [
                "type" => "Label",
                "name" => "loginInfo",
                "caption" => "Logge dich bitte ein, indem du den Link in einem Browser öffnest. Wenn du fertig bist (der Browser keine Page mehr anzeigt) kopiere die ganze url und füge sie dann in das Eingabefeld ein.",
                "visible" => $visible,
            ],
            [
                "type" => "Label",
                "name" => "link",
                "caption" => "https://api.home-connect.com/security/oauth/authorize?response_type=code&client_id=35C7EC3372C6EB5FB5378505AB9CE083D80A97713698ACB07B20C6E41E5E2CD5&scope=&redirect_uri=http%3A%2F%2Flocalhost%3A8080",
                "visible" => $visible,
            ],
            [
                "type" => "ValidationTextBox",
                "name" => "auth_url",
                "caption" => "Url",
                "visible" => $visible,
            ],
            [
                "type" => "Label",
                "name" => "loginInfo2",
                "caption" => "Wenn du fertig bist, dann klicke auf login und aktualisiere das Modul",
                "visible" => $visible,
            ],
            [
                "type" => "Configurator",
                "name" => "Home-Connect Discovery",
                "caption" => "HomeConnect Discovery",
                "rowCount" => 14,
                "add" => false,
                "delete" => true,
                "columns" => [
                    [
                        "caption" => "Name",
                        "name" => "name",
                        "width" => "200px",
                        "add" => false,
                    ],
                    [
                        "caption" => "Device",
                        "name" => "device",
                        "width" => "120px",
                        "add" => false,
                    ],
                    [
                        "caption" => "Company",
                        "name" => "company",
                        "width" => "120px",
                        "add" => false,
                    ],
                    [
                        "caption" => "haId",
                        "name" => "haId",
                        "width" => "auto",
                        "add" => false,
                    ],
                    [
                        "caption" => "Connection",
                        "name" => "connected",
                        "width" => "100px",
                        "add" => false,
                    ],
                ],
                "values" => $this->GetDevices(),
            ],
        ];
    }

    /**
     * @return array[] Form Status
     */
    protected function FormStatus() {
        return[
            [
                'code'    => 101,
                'icon'    => 'inactive',
                'caption' => 'Creating instance.',
            ],
            [
                'code'    => 102,
                'icon'    => 'active',
                'caption' => 'HomeConnect Discovery created.',
            ],
            [
                'code'    => 104,
                'icon'    => 'inactive',
                'caption' => 'interface closed.',
            ],
            [
                'code'    => 201,
                'icon'    => 'error',
                'caption' => 'Unknown error   [ 201 ]',
            ],
            [
                'code'    => 206,
                'icon'    => 'error',
                'caption' => 'No Authorization/Login   [ 206 ]',
            ],
            [
                'code'    => 207,
                'icon'    => 'error',
                'caption' => 'No Token   [ 207 ]',
            ],
            [
                'code'    => 401,
                'icon'    => 'error',
                'caption' => 'Device is offline   [ 401 ]',
            ],
            [
                'code'    => 402,
                'icon'    => 'inactive',
                'caption' => 'Unknown Program   [ 402 ]',
            ],
            [
                'code'    => 403,
                'icon'    => 'error',
                'caption' => 'Program cant be started   [ 403 ]',
            ],
            [
                'code'    => 404,
                'icon'    => 'error',
                'caption' => 'Program cant be stopped   [ 404 ]',
            ],
            [
                'code'    => 405,
                'icon'    => 'inactive',
                'caption' => 'Request   [ 405 ]',
            ],
            [
                'code'    => 406,
                'icon'    => 'inactive',
                'caption' => 'Request/Send Limit is reached   [ 406 ]',
            ],
            [
                'code'    => 407,
                'icon'    => 'error',
                'caption' => 'HomeConnect Cloud is not available   [ 407 ]',
            ],
            [
                'code'    => 408,
                'icon'    => 'error',
                'caption' => 'Error from HomeConnect    [ 408 ]',
            ]
        ];
    }

    /** Function the check failed Api call/Token call for errors
     * @param $ex
     */
    protected function analyseEX( Exception $ex ) {
        // check the Exception and set error code
        switch ( $ex->getMessage() ) {
            // USER NOT LOGGED IN
            case 'No Authorization code present':
            case 'invalid_grant':
                $this->SetStatus( 206 );
                break;
            // TOKEN NOT PROVIDED
            case 'invalid_token':
            case 'missing or invalid request parameters':
                $this->SetStatus( 207 );
                break;
            // DEVICE NOT CONNECTED
            case 'SDK.Error.HomeAppliance.Connection.Initialization.Failed':
                $this->SetStatus( 401 );
                break;
            // WRONG REQUEST
            case 'SDK.Error.NoProgramActive':
            case 'SDK.Error.UnsupportedProgram':
                $this->SetStatus( 402 );
                break;
            // FAILED/WRONG REQUEST
            case 'invalid_request':
            case '404':
                $this->SetStatus( 405 );
                break;
            case '503':
                $this->SetStatus( 407 );
                break;
            case '500':
                $this->SetStatus( 408 );
                break;
            // ERROR...
            default:
                $this->SetStatus( 201 );
                IPS_LogMessage( $this->InstanceID,'Unknown HomeConnect Error: ' . $ex->getMessage() );
                break;
        }
    }

}
?>