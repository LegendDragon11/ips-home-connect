<?php

require_once( dirname(dirname(__FILE__) ) . "/libs/tools/HomeConnectApi.php");
require_once( dirname(dirname(__FILE__) ) . "/libs/tools/tm/tm.php");
$data = json_decode( file_get_contents( dirname(dirname(__FILE__) ) . "/libs/tools/tm/data.json" ), true );


class HomeConnectDryer extends IPSModule {

      /** This function will be called on the creation of this Module
       * @return bool|void
       */
      public function Create()
      {
          // Overwrite ips function
          parent::Create();

          // Device Information, set by Configurator
          $this->RegisterPropertyString('name', '');
          $this->RegisterPropertyString('device', '');
          $this->RegisterPropertyString('company', '');
          $this->RegisterPropertyString('haId', '');

          // Refresh Settings [Set by User]
          $this->RegisterPropertyInteger("first_refresh", 1);
          $this->RegisterPropertyInteger("second_refresh", 1);
          $this->RegisterPropertyBoolean("refresh_on_off", true);
          // Notify Settings [Set by User]
          $this->RegisterPropertyInteger("notify_instance", 0);
          $this->RegisterPropertyString("notify_sound", "");
          $this->RegisterPropertyBoolean("notify_start", false);
          $this->RegisterPropertyBoolean("notify_stop", false);
          $this->RegisterPropertyBoolean("notify_finish", false);
          // Notify Settings [Set by User]
          $this->RegisterPropertyInteger("web_notify_instance", 0);
          $this->RegisterPropertyInteger("web_notify_Timeout", 10);
          $this->RegisterPropertyBoolean("web_notify_start", false);
          $this->RegisterPropertyBoolean("web_notify_stop", false);
          $this->RegisterPropertyBoolean("web_notify_finish", false);

          // Attribute for just one finish message
          $this->RegisterAttributeBoolean('finish_message_sent', false);

          // Check if the user wants to hide or show varaibles
          $this->RegisterPropertyBoolean("hide_show", true);

          // Check if the user wants to translate the mode varialbe
          $this->RegisterPropertyBoolean("mode_translate", true);

          // Turn on/off of Log messages
          $this->RegisterPropertyBoolean("log", false);

          // Remote start and Build list [Set by refresh function]
          $this->RegisterAttributeString("remoteControlAllowed", "Dein Gerät erlaubt keine Fernbedienung");
          $this->RegisterAttributeString("remoteStartAllowed", "Dein Gerät erlaub keinen Fernstart");
          $this->RegisterAttributeBoolean("first_start", true );

          // Register Timers [refresh Timer, Count down until start, Count down until program ends]
          $this->RegisterTimer($this->InstanceID . "-refresh", 300000, "HCDryer_refresh( $this->InstanceID );");
          $this->RegisterTimer("DownCountStart", 0, "HCDryer_DownCount($this->InstanceID, 'remainStartTime'");
          $this->RegisterTimer("DownCountProgram", 0, "HCDryer_DownCount($this->InstanceID, 'remainTime');");

          // Register Variable and Profiles [look in class]
          $this->registerProfiles();

          $this->RegisterVariableBoolean("remoteControl", "Remote control", "HC_RemoteStart", -2);
          $this->RegisterVariableInteger('LastRefresh', "Last Refresh", "UnixTimestamp", -2);
          $this->RegisterVariableInteger("state", "Geräte Zustand", "HC_State", 0);
          $this->RegisterVariableString("remainStartTime", "Start in", "", 1);
          $this->RegisterVariableInteger("mode", "Programm", "HC_DryerMode", 2);
          $this->RegisterVariableInteger("option", "Programm Option", "HC_DryerOption", 3);
          $this->RegisterVariableBoolean("remoteStart", "Remote start", "HC_RemoteStart", 4);
          $this->RegisterVariableBoolean("door", "Tür Zustand", "HC_DoorState", 5);
          $this->RegisterVariableString("remainTime", "Verbleibende Programm Zeit", "", 6);
          $this->RegisterVariableInteger("progress", "Fortschritt", "HC_Progress", 7);
          $this->RegisterVariableBoolean("start_stop", "Programm start/stop", "HC_StartStop", 8);

          // error codes
          $this->RegisterVariableInteger("info", "Info", "", 99 );

          // Enable Action for variables, for change reaction look up RequestAction();
          $this->EnableAction('start_stop');
          $this->EnableAction('mode');
          $this->EnableAction('option');
          $this->EnableAction('state');

          // Set Hide, the user can link the instance with no unimportant info
          IPS_SetHidden($this->GetIDForIdent("remoteControl"), true);
          IPS_SetHidden($this->GetIDForIdent('LastRefresh'), true);
          IPS_SetHidden($this->GetIDForIdent('info'), true);
          $this->Hide();
      }

      /** This function will be called by IP Symcon when the User change vars in the Module Interface
       * @return bool|void
       */
      public function ApplyChanges()
      {
          // Overwrite ips function
          parent::ApplyChanges();
      }


      //--------------------------------------------------< Reaction >----------------------------------------
      public function RequestAction($Ident, $Value)
      {
          switch ($Ident) {
              case 'state':
                  if ($this->GetValue("state") < 3) {
                      if ($Value) {
                          $this->SetActive(true);
                          $this->SetValue('state', 1);
                      } else {
                          $this->SetActive(false);
                          $this->SetValue('state', 0);
                      }
                  }
                  break;
              case 'mode':
                  $this->SetValue('mode', $Value);
                  break;
              case 'option':
                  $this->SetValue('option', $Value);
                  break;
              case 'start_stop':
                  if ($Value) {
                      $program = $this->GetListValue( true );
                      $option = $this->GetListValue( false );
                      $this->start($program, $option);
                      $this->SetValue('start_stop', true);
                  } else {
                      $this->stop();
                      $this->SetValue('start_stop', false);
                  }
          }

          $this->Hide();
      }
      //--------------------------------------------------< User functions >----------------------------------
      /** Function to refresh the device values
       * @return string could return error
       */
      public function refresh() {
          // log
          $this->_log( "Refreshing startet..." );
          //====================================================================================================================== Check Timer
          // Get current Hour
          $hour = date('G');

          // Check Refresh time set by the user. After that set the interval of the timer (fast or slow)
          if ( $hour >= $this->ReadPropertyInteger("first_refresh") && $hour <= $this->ReadPropertyInteger("second_refresh") ) {
              // Setting timer
              $this->SetTimerInterval($this->InstanceID . "-refresh", 300000 );
          } else {
              // Setting timer slow
              $this->SetTimerInterval($this->InstanceID . "-refresh", 900000 );
          }

          //====================================================================================================================== Refreshing
          // Check if the user activated the refresh function
          if ( $this->ReadPropertyBoolean("refresh_on_off") ) {
              try {
                  // Make a Api call to get the current status of the device (inactive, ready, delayed start, active)
                  $recall_api = Api("homeappliances/" . $this->ReadPropertyString("haId") . "/status");
              } catch (Exception $ex) {
                  $this->SetStatus( analyseEX($ex) );
                  return false;
              }
              // Build a Key => Value array with the getKeys function (look down in the code)
              $options_recall = $this->getKeys($recall_api, 'status');

              //================================================================================================================== Refreshing Permissions
              // Check if the RemoteControl active is [Set by Device]
              if ( $options_recall['BSH.Common.Status.RemoteControlActive'] ) {
                  $this->WriteAttributeString("remoteControlAllowed", "Dein Gerät erlaubt eine Fernbedienung");
                  $this->SetValue("remoteControl", true );
              } else {
                  $this->WriteAttributeString("remoteControlAllowed", "Dein Gerät erlaubt keine Fernbedienung");
                  $this->SetValue("remoteControl", false );
              }
              // Check if the RemoteControl is active [Set by User on device]
              if ( $options_recall['BSH.Common.Status.RemoteControlStartAllowed'] ) {
                  $this->WriteAttributeString("remoteStartAllowed", "Dein Gerät erlaub ein Fernstart" );
                  $this->SetValue("remoteStart", true );
              } else {
                  $this->WriteAttributeString("remoteStartAllowed", "Dein Gerät erlaub keinen Fernstart" );
                  $this->SetValue("remoteStart", false );
              }

              //================================================================================================================== Refreshing Device Program
              // Get door state and operation state from the Key => Value array (see above)
              $DoorState =  $this->HC( $options_recall['BSH.Common.Status.DoorState'] );
              $OperationState = $this->HC( $options_recall['BSH.Common.Status.OperationState'] );

              // Check if the device is active or in delayed start
              if ( $OperationState == 3 || $OperationState == 2 ) {
                  try {
                      // Api call to get the active program
                      $recallProgram = Api("homeappliances/" . $this->ReadPropertyString("haId") . "/programs/active");
                  } catch (Exception $ex) {
                      $this->SetStatus( analyseEX($ex) );
                      return false;
                  }
                  // Build a Key => Value array with the getKeys options (see bottom of the code)
                  $options = $this->getKeys($recallProgram, 'options');

                  // Set current program mode
                  $this->SetListValue( explode( ".", $recallProgram['data']['key'] )[3] );

                  // get remaining time (you can get this in state 2 or 3)
                  $this->SetValue("remainTime", gmdate("H:i:s", $options['BSH.Common.Option.RemainingProgramTime']) );
                  // Set Program progress
                  $this->SetValue("progress", $options['BSH.Common.Option.ProgramProgress'] );
                  $this->SetValue('start_stop', true );

                  switch ( $OperationState ) {
                      case 2:
                          // Set the remaining time until the device will start (out of the $options array)
                          $this->SetValue("remainStartTime", gmdate("H:i:s", $options['BSH.Common.Option.StartInRelative']) );
                          // Set counter timer, to count down
                          $this->SetTimerInterval('DownCountStart', 1001);
                          $this->SetTimerInterval('DownCountProgram', 0);
                          break;
                      case 3:
                          // Set counters for the left program time (because the device is active)
                          $this->SetTimerInterval('DownCountProgram', 1001);
                          $this->SetTimerInterval('DownCountStart', 0);
                          break;
                      default:
                          // Set counters off, no device information (safety feature)
                          $this->SetTimerInterval('DownCountStart', 0);
                          $this->SetTimerInterval('DownCountProgram', 0);
                  }
              } else {
                  try {
                      // Api call to set selected program on the device
                      $recallSelected = Api("homeappliances/" . $this->ReadPropertyString("haId") . "/programs/selected")['data'];
                  } catch (Exception $ex) {
                      $this->SetStatus( analyseEX($ex) );
                      return false;
                  }
                  $this->SetListValue( explode( ".", $recallSelected['key'] )[3] );

                  // Set default mode
                  $this->SetTimerInterval('DownCountStart', 0);
                  $this->SetTimerInterval('DownCountProgram', 0);
                  $this->SetValue("remainTime", "--:--:--");
                  $this->SetValue("remainStartTime", "--:--:--" );
                  $this->SetValue("progress", 0 );
                  $this->SetValue('start_stop', false );

                  $this->WriteAttributeBoolean( 'finish_message_sent', false);
              }

              //================================================================================================================== Check if device is done
              if ( $this->GetValue('state') == 3 && $OperationState != 3 && !$this->ReadAttributeBoolean('finish_message_sent' )) {
                  if ( $this->GetValue('state') == 3 && $this->ReadPropertyBoolean('notify_finish') || $this->ReadPropertyBoolean('web_notify_finish')  ) {
                      $this->SendNotify("Der " . $this->ReadPropertyString('name') . " ist mit dem Programm fertig!");
                  }
                  $this->WriteAttributeBoolean('finish_message_sent', true );
              }
              //================================================================================================================== Refreshing Basic Variables
              $this->SetValue("door", $DoorState );
              $this->SetValue("state", $OperationState );
              // Set last refresh ( user information)
              $this->SetValue( "LastRefresh", time() );
          } else {
              // For safety turn the counter timers off
              $this->SetTimerInterval('DownCountStart', 0);
              $this->SetTimerInterval('DownCountProgram', 0);
              // Set message sent to false (device is not active)
              $this->WriteAttributeBoolean( 'finish_message_sent', false);
          }

          //================================================================================================================== Settings for the first start after refresh
          if ( $this->ReadAttributeBoolean("first_start") ) {
              $this->BuildList("HC_DryerMode");
              $this->WriteAttributeBoolean("first_start", false );
          }

          // Let the function Hide() check if there variables to check or uncheck
          $this->Hide();
          // log
          $this->_log( "Refreshing end");
          return true;
      }

    /** Function to start Modes for the Dishwasher
     * @param string $mode Mode
     * @param string $option Delay in seconds until the device starts
     * @throws Exception
     */ //TODO: check right command
      public function start( string $mode, string $option ) {
          // log
          $this->_log( "Trying to start Device..." );
          // Refresh variables (like door state)
          $this->refresh();

          if ( $mode == "Mix" ) {
              if ( $option != "CupboardDry" && $option != "IronDry" ) {
                  // option not allowed for this mode
                  throw new Error('This Option is not allowed for this Mode!');
              }
          }

          // Build the json for the api
          $opt = '{"data":{"key":"LaundryCare.Dryer.Program.' . $mode . '","options":[{"key":"LaundryCare.Dryer.Option.DryingTarget","value":"LaundryCare.Dryer.EnumType.DryingTarget.' . $option . '"}]}}';

          //====================================================================================================================== Send start
          if ($this->GetValue("remoteStart")) {
              // Check Door state
              if (!$this->GetValue("door")) {
                  // Check if the device is on
                  if ($this->GetValue("state") == 1) {
                      try {
                          Api_put("homeappliances/" . $this->ReadPropertyString("haId") . "/programs/active", $opt);
                          // log
                          $this->_log( "Send start to HomeConnect" );
                          //============================================================ Check Notifications
                          if ($this->ReadPropertyBoolean("notify_start")) {
                              $this->SendNotify($this->ReadPropertyString("name") . " hat das Programm " . DishwasherTranslateMode($mode, true) . " gestarted!");
                          }
                          //============================================================ Check Notifications
                          // log
                          $this->_log( "Send start notify" );
                      } catch (Exception $ex) {
                          // log
                          $this->_log( "Start failed look for more information in the Log" );
                          $this->SetStatus( analyseEX($ex) );
                      }
                  } else {
                      // log
                      $this->_log( "Canceled (program running)" );
                      throw new Exception("state");
                  }
              } else {
                  // log
                  $this->_log( "Canceled (door open)" );
                  throw new Exception("door");
              }
          } else {
              // log
              $this->_log( "Canceled (no permission)" );
              throw new Exception("permission");
          }

          $this->refresh();
      }

    /**
     * Function to stop a running program
     * @throws Exception
     */
      public function stop() {
          // log
          $this->_log( "Trying to stop..." );
          // basic refresh for state and control permission
          $this->refresh();

          //====================================================================================================================== Send stop
          if ( $this->GetValue("remoteControl") ) {
              // log
              $this->_log( "Canceled (remote control not allowed)" );
              // Check if the device is running a program
              switch ( $this->GetValue("state") ) {
                  // stop running program
                  case 3:
                      try {
                          // log
                          $this->_log(  "Stopped while deice was running a program" );
                          // Send custom delete to stop current program
                          Api_delete("homeappliances/" . $this->ReadPropertyString("haId") . "/programs/active" );
                          $this->SetValue("state", 1 );
                          //============================================================ Check Notifications
                          if ($this->ReadPropertyBoolean("notify_stop")) {
                              $this->SendNotify($this->ReadPropertyString("name") . " hat das Programm gestoppt!");
                          }
                          //============================================================ Check Notifications
                      } catch (Exception $ex) {
                          // log
                          $this->_log( "Program didnt stop" );
                          $this->SetStatus( analyseEX($ex) );
                      }
                      break;
                  // stop delayed start
                  case 2:
                      // log
                      $this->_log( "Stopped while device was in mode 'Prepare for start'" );
                      // Turn the device off ( this stops the delayed start )
                      $this->SetActive(false);
                      break;
                  default:
                      // log
                      $this->_log( "Canceled (no program is running)" );
                      // throw logic exception for no reason to stop
                      throw new Exception("state" );
              }
          } else {
              // throw logic exception for no permission
              throw new Exception("permission");
          }
      }

    /**
     * Function to turn the dishwasher on
     * @param bool $state switch
     */
      public function SetActive( bool $state ) {
          if ( $state ) {
              // power on string for HomeConnect
              $power = '{"data": {"key": "BSH.Common.Setting.PowerState","value": "BSH.Common.EnumType.PowerState.On","type": "BSH.Common.EnumType.PowerState"}}';
          } else {
              // power off string for HomeConnect
              $power = '{"data": {"key": "BSH.Common.Setting.PowerState","value": "BSH.Common.EnumType.PowerState.Off","type": "BSH.Common.EnumType.PowerState"}}';}

          try {
              Api_put("homeappliances/" . $this->ReadPropertyString("haId") . "/settings/BSH.Common.Setting.PowerState", $power);
              // log
              $this->_log("Send On/off State to HomeConnect" );
          } catch (Exception $ex) {
              // log
              $this->_log("Failed to send Device state" );
              $this->SetStatus( analyseEX($ex) );
          }
      }

      public function test( $type ) {
          switch ($type) {
              // sending handy test message with ips function
              case "handy_message":
                  WFC_PushNotification( $this->ReadPropertyInteger("notify_instance"), "HomeConnect", "Test Message", $this->ReadPropertyString("notify_sound"), $this->InstanceID );
                  break;
              // sending web message with ips function
              case "web_message":
                  WFC_SendNotification( $this->ReadPropertyInteger("web_notify_instance"), "HomeConnect", "Test Message", "Power", $this->ReadPropertyInteger("web_notify_Timeout") );
                  break;
          }
      }

    //-----------------------------------------------------< Profiles >------------------------------
      /** This Function will register all Profiles for the Module
       */
      protected function registerProfiles() {
          // Generate Variable Profiles
          if (!IPS_VariableProfileExists('HC_State')) {
              IPS_CreateVariableProfile('HC_State', 1);
              IPS_SetVariableProfileIcon('HC_State', 'Power');
              IPS_SetVariableProfileValues("HC_State", 0, 2, 0 );
              IPS_SetVariableProfileAssociation("HC_State", 0, "Aus", "", 0x828282 );
              IPS_SetVariableProfileAssociation("HC_State", 1, "An", "", 0x22ff00 );
              IPS_SetVariableProfileAssociation("HC_State", 2, "Verzögerter Start", "", 0xfa8e00 );
              IPS_SetVariableProfileAssociation("HC_State", 3, "Program läuft", "", 0xfa3200 );
          }
          if (!IPS_VariableProfileExists("HC_DryerMode") ) {
              IPS_CreateVariableProfile("HC_DryerMode", 1);
              IPS_SetVariableProfileIcon("HC_DryerMode", 'Drops');
          }
          if (!IPS_VariableProfileExists("HC_DryerOption") ) {
              IPS_CreateVariableProfile("HC_DryerOption", 1);
              IPS_SetVariableProfileIcon("HC_DryerOption", 'TurnRight');
          }
          if (!IPS_VariableProfileExists('HC_Progress')) {
              IPS_CreateVariableProfile('HC_Progress', 1);
              IPS_SetVariableProfileIcon('HC_Progress', 'Hourglass');
              IPS_SetVariableProfileText("HC_Progress", "", "%");
          }
          if (!IPS_VariableProfileExists('HC_DoorState')) {
              IPS_CreateVariableProfile('HC_DoorState', 0);
              IPS_SetVariableProfileIcon('HC_DoorState', 'Lock');
              IPS_SetVariableProfileAssociation("HC_DoorState", false, "Geschlossen", "", 0x828282 );
              IPS_SetVariableProfileAssociation("HC_DoorState", true, "Offen", "", 0xcf0000 );
          }
          if (!IPS_VariableProfileExists('HC_StartStop')) {
              IPS_CreateVariableProfile('HC_StartStop', 0);
              IPS_SetVariableProfileIcon('HC_StartStop', 'Power');
              IPS_SetVariableProfileAssociation("HC_StartStop", false, "Stop", "", 0x828282 );
              IPS_SetVariableProfileAssociation("HC_StartStop", true, "Start", "", 0x11ff00 );
          }
          if (!IPS_VariableProfileExists('HC_RemoteStart')) {
              IPS_CreateVariableProfile('HC_RemoteStart', 0);
              IPS_SetVariableProfileIcon('HC_RemoteStart', 'Lock');
              IPS_SetVariableProfileAssociation("HC_RemoteStart", false, "Nicht erlaubt", "", 0xfa3200 );
              IPS_SetVariableProfileAssociation("HC_RemoteStart", true, "Erlaubt", "", 0x11ff00 );
          }
      }


    //-----------------------------------------------------< Setting Form.json >------------------------------
      /** This Function will set the IP Symcon Form.json
       * @return false|string Form json
       */
      public function GetConfigurationForm() {
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
                  "caption" => "Test Handy notify",
                  "onClick" => 'HCDryer_test( $id, "handy_message" );',
              ],
              [
                  "type" => "Button",
                  "caption" => "Test Webfront notify",
                  "onClick" => 'HCDryer_test( $id, "web_message" );',
              ],
              [
                  "type" => "Button",
                  "caption" => "Refresh",
                  "onClick" => 'HCDryer_refresh( $id );',
              ],
              [
                  "type" => "Button",
                  "caption" => "Profile refresh",
                  "onClick" => 'HCDryer_BuildList( $id, "HC_DryerMode");',
              ]
          ];
      }

      /**
       * @return array[] Form Elements
       */
      protected function FormElements() {
          return[
              [
                  "type" => "Image",
                  "image" => "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAfQAAACWCAYAAAAonXpvAAAF52lUWHRYTUw6Y29tLmFkb2JlLnhtcAAAAAAAPD94cGFja2V0IGJlZ2luPSLvu78iIGlkPSJXNU0wTXBDZWhpSHpyZVN6TlRjemtjOWQiPz4KPHg6eG1wbWV0YSB4bWxuczp4PSJhZG9iZTpuczptZXRhLyIgeDp4bXB0az0iWE1QIENvcmUgNS41LjAiPgogPHJkZjpSREYgeG1sbnM6cmRmPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5LzAyLzIyLXJkZi1zeW50YXgtbnMjIj4KICA8cmRmOkRlc2NyaXB0aW9uIHJkZjphYm91dD0iIgogICAgeG1sbnM6ZXhpZj0iaHR0cDovL25zLmFkb2JlLmNvbS9leGlmLzEuMC8iCiAgICB4bWxuczpwaG90b3Nob3A9Imh0dHA6Ly9ucy5hZG9iZS5jb20vcGhvdG9zaG9wLzEuMC8iCiAgICB4bWxuczp0aWZmPSJodHRwOi8vbnMuYWRvYmUuY29tL3RpZmYvMS4wLyIKICAgIHhtbG5zOnhtcD0iaHR0cDovL25zLmFkb2JlLmNvbS94YXAvMS4wLyIKICAgIHhtbG5zOnhtcE1NPSJodHRwOi8vbnMuYWRvYmUuY29tL3hhcC8xLjAvbW0vIgogICAgeG1sbnM6c3RFdnQ9Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC9zVHlwZS9SZXNvdXJjZUV2ZW50IyIKICAgIHhtbG5zOmRjPSJodHRwOi8vcHVybC5vcmcvZGMvZWxlbWVudHMvMS4xLyIKICAgZXhpZjpDb2xvclNwYWNlPSIxIgogICBleGlmOlBpeGVsWERpbWVuc2lvbj0iNTAwIgogICBleGlmOlBpeGVsWURpbWVuc2lvbj0iMTUwIgogICBwaG90b3Nob3A6Q29sb3JNb2RlPSIzIgogICBwaG90b3Nob3A6SUNDUHJvZmlsZT0ic1JHQiBJRUM2MTk2Ni0yLjEiCiAgIHRpZmY6SW1hZ2VMZW5ndGg9IjE1MCIKICAgdGlmZjpJbWFnZVdpZHRoPSI1MDAiCiAgIHRpZmY6UmVzb2x1dGlvblVuaXQ9IjIiCiAgIHRpZmY6WFJlc29sdXRpb249IjQwMC4wIgog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"
              ],
              [
                  "type" => "List",
                  "name" => "Gerät Information",
                  "caption" => "Informationen zu diesem Gerät [ Trockner ]",
                  "rowCount" => 1,
                  "add" => false,
                  "delete" => false,
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
                  ],
                  "values" => [
                      [
                          "name" => $this->ReadPropertyString("name"),
                          "device" => $this->ReadPropertyString( "device"),
                          "company" => $this->ReadPropertyString( "company"),
                          "haId" => $this->ReadPropertyString( "haId"),
                      ],
                  ],
              ],
              [
                  "type" => "ExpansionPanel",
                  "caption" => "Berechtigungen die von deinem Gerät gesetzt werden",
                  "items" => [
                      [
                          "type" => "Label",
                          "name" => "remoteControlAllowed",
                          "caption" => $this->ReadAttributeString('remoteControlAllowed'),
                      ],
                      [
                          "type" => "Label",
                          "name" => "remoteStartAllowed",
                          "caption" => $this->ReadAttributeString('remoteStartAllowed'),
                      ],
                  ],
              ],
              [
                  "type" => "ExpansionPanel",
                  "caption" => "Refreshing Data",
                  "items" => [
                      [
                          "type" => "Label",
                          "name" => "refresh Info",
                          "caption" => "Das System updated in dem Zeitraum alle 5min. Sonst nur 15min."
                      ],
                      [
                          "type" => "NumberSpinner",
                          "name" => "first_refresh",
                          "caption" => "Refreshen von " . $this->ReadPropertyInteger("first_refresh") . " Uhr",
                          "suffix" => "h",
                          "minimum" => "0",
                          "maximum" => "24",
                          "enabled" => true
                      ],
                      [
                          "type" => "NumberSpinner",
                          "name" => "second_refresh",
                          "caption" => "Bis " . $this->ReadPropertyInteger("second_refresh") . " Uhr",
                          "suffix" => "h",
                          "minimum" => "0",
                          "maximum" => "24",
                          "enabled" => true
                      ],
                      [
                          "type" => "CheckBox",
                          "name" => "refresh_on_off",
                          "caption" => "Refresh An/Aus",
                      ],
                  ],
              ],
              [
                  "type" => "ExpansionPanel",
                  "caption" => "Handy Notification Settings",
                  "items" => [
                      [
                          "type" => "SelectInstance",
                          "name" => "notify_instance",
                          "caption" => "Benachrichtigungs Instanz [Mobile / Handy]",
                      ],
                      [
                          "type" => "Select",
                          "name" => "notify_sound",
                          "caption" => "Benachrichtigungs Sound [nichts für den normalen]",
                          "options" => [
                              [ "caption" => "Normal", "value" => "" ],
                              [ "caption" => "alarm", "value" => "alarm" ],
                              [ "caption" => "bell", "value" => "bell" ],
                              [ "caption" => "boom", "value" => "boom" ],
                              [ "caption" => "buzzer", "value" => "buzzer" ],
                              [ "caption" => "connected", "value" => "connected" ],
                              [ "caption" => "dark", "value" => "dark" ],
                              [ "caption" => "digital", "value" => "digital" ],
                              [ "caption" => "drums", "value" => "drums" ],
                              [ "caption" => "duck", "value" => "duck" ],
                              [ "caption" => "full", "value" => "full" ],
                              [ "caption" => "happy", "value" => "happy" ],
                              [ "caption" => "horn", "value" => "horn" ],
                              [ "caption" => "inception", "value" => "inception" ],
                              [ "caption" => "kazoo", "value" => "kazoo" ],
                              [ "caption" => "roll", "value" => "roll" ],
                              [ "caption" => "siren", "value" => "siren" ],
                              [ "caption" => "space", "value" => "space" ],
                              [ "caption" => "trickling", "value" => "trickling" ],
                              [ "caption" => "turn", "value" => "turn" ],
                          ],
                      ],
                      [
                          "type" => "CheckBox",
                          "name" => "notify_start",
                          "caption" => "Startbenachrichtigung",
                      ],
                      [
                          "type" => "CheckBox",
                          "name" => "notify_stop",
                          "caption" => "Stopbenachrichtigung",
                      ],
                      [
                          "type" => "CheckBox",
                          "name" => "notify_finish",
                          "caption" => "Benachrichtigung wenn fertig",
                      ],
                  ],
              ],
              [
                  "type" => "ExpansionPanel",
                  "caption" => "Webfront Notification Settings",
                  "items" => [
                      [
                          "type" => "SelectInstance",
                          "name" => "web_notify_instance",
                          "caption" => "Benachrichtigungs Instanz [Mobile / Handy]",
                      ],
                      [
                          "type" => "NumberSpinner",
                          "name" => "web_notify_Timeout",
                          "caption" => "Nachrichten Timeout",
                          "suffix" => "sec",
                          "minimum" => "0",
                          "maximum" => "300",
                      ],
                      [
                          "type" => "CheckBox",
                          "name" => "web_notify_start",
                          "caption" => "Startbenachrichtigung",
                      ],
                      [
                          "type" => "CheckBox",
                          "name" => "web_notify_stop",
                          "caption" => "Stopbenachrichtigung",
                      ],
                      [
                          "type" => "CheckBox",
                          "name" => "web_notify_finish",
                          "caption" => "Benachrichtigung wenn fertig",
                      ],
                  ],
              ],
              [
                  "type" => "ExpansionPanel",
                  "caption" => "Variable Settings",
                  "items" => [
                      [
                          "type" => "CheckBox",
                          "name" => "hide_show",
                          "caption" => "Dynamisches ein-/ausblenden",
                      ],
                      [
                          "type" => "CheckBox",
                          "name" => "mode_translate",
                          "caption" => "Modus Profil übersetzen (Option aus um die Modi zu sehen die Gesetzt werden können)",
                      ],
                  ],
              ],
              [
                  "type" => "CheckBox",
                  "name" => "log",
                  "caption" => "LogMessages",
              ],
          ];
      }

      /**
       * @return array[] Form Status
       */
      protected function FormStatus() {
          return [
              [
                  'code'    => 101,
                  'icon'    => 'inactive',
                  'caption' => 'Creating instance.',
              ],
              [
                  'code'    => 102,
                  'icon'    => 'active',
                  'caption' => 'HomeConnect Dryer created.',
              ],
              [
                  'code'    => 104,
                  'icon'    => 'inactive',
                  'caption' => 'interface closed.',
              ],
              [
                  'code'    => 201,
                  'icon'    => 'error',
                  'caption' => 'Error is unknown   [ 201 ]',
              ],
              [
                  'code'    => 206,
                  'icon'    => 'error',
                  'caption' => 'User not authorized   [ 206 ]',
              ],
              [
                  'code'    => 207,
                  'icon'    => 'error',
                  'caption' => 'Client has not token   [ 207 ]',
              ],
              [
                  'code'    => 401,
                  'icon'    => 'error',
                  'caption' => 'Device is offline   [ 401 ]',
              ],
              [
                  'code'    => 402,
                  'icon'    => 'inactive',
                  'caption' => 'Program is unknown   [ 402 ]',
              ],
              [
                  'code'    => 403,
                  'icon'    => 'error',
                  'caption' => 'Cant start program   [ 403 ]',
              ],
              [
                  'code'    => 404,
                  'icon'    => 'error',
                  'caption' => 'Cant stop program   [ 404 ]',
              ],
              [
                  'code'    => 405,
                  'icon'    => 'inactive',
                  'caption' => 'Request failed   [ 405 ]',
              ],
              [
                  'code'    => 406,
                  'icon'    => 'inactive',
                  'caption' => 'Request limit reached   [ 406 ]',
              ],
              [
                  'code'    => 407,
                  'icon'    => 'error',
                  'caption' => 'HomeConnect cloud is offline   [ 407 ]',
              ],
              [
                  'code'    => 408,
                  'icon'    => 'error',
                  'caption' => 'HomeConnect error   [ 408 ]',
              ],
              [
                  'code'    => 409,
                  'icon'    => 'error',
                  'caption' => 'Permission is missing   [ 409 ]',
              ],
              [
                  'code'    => 410,
                  'icon'    => 'error',
                  'caption' => 'Operation state is unknown   [ 410 ]',
              ],
              [
                  'code'    => 411,
                  'icon'    => 'error',
                  'caption' => 'Remote Control not allowed   [ 411 ]',
              ],
              [
                  'code'    => 412,
                  'icon'    => 'error',
                  'caption' => 'Remote Start not allowed   [ 412 ]',
              ],
              [
                  'code'    => 413,
                  'icon'    => 'error',
                  'caption' => 'Device is locked   [ 413 ]',
              ],
              [
                  'code'    => 414,
                  'icon'    => 'error',
                  'caption' => 'Front Panel is open   [ 414 ]',
              ],
              [
                  'code'    => 415,
                  'icon'    => 'error',
                  'caption' => 'Door is open  [ 415 ]',
              ],
              [
                  'code'    => 416,
                  'icon'    => 'error',
                  'caption' => 'Meatprobe is plugged   [ 416 ]',
              ],
              [
                  'code'    => 417,
                  'icon'    => 'error',
                  'caption' => 'Battery Level Low   [ 417 ]',
              ],
              [
                  'code'    => 418,
                  'icon'    => 'error',
                  'caption' => 'Device is lifted   [ 418 ]',
              ],
              [
                  'code'    => 419,
                  'icon'    => 'error',
                  'caption' => 'Dust Box not inserted   [ 419 ]',
              ],
              [
                  'code'    => 420,
                  'icon'    => 'error',
                  'caption' => 'Already at Home   [ 420 ]',
              ],
              [
                  'code'    => 421,
                  'icon'    => 'error',
                  'caption' => 'Active Program   [ 421 ]',
              ],
          ];
      }


    //-----------------------------------------------------< Module Functions >------------------------------

      protected function Hide() {
          if ( $this->ReadPropertyBoolean("hide_show") ) {
              switch ($this->GetValue('state')) {
                  case 0:
                      IPS_SetHidden( $this->GetIDForIdent('remoteStart'), true );
                      IPS_SetHidden( $this->GetIDForIdent('door'), true );
                      IPS_SetHidden( $this->GetIDForIdent('remainTime'), true );
                      IPS_SetHidden( $this->GetIDForIdent('progress'), true );
                      IPS_SetHidden( $this->GetIDForIdent('remainStartTime'), true );
                      break;
                  case 1:
                      IPS_SetHidden( $this->GetIDForIdent('remoteStart'), false );
                      IPS_SetHidden( $this->GetIDForIdent('door'), false );
                      IPS_SetHidden( $this->GetIDForIdent('remainTime'), true );
                      IPS_SetHidden( $this->GetIDForIdent('progress'), true );
                      IPS_SetHidden( $this->GetIDForIdent('remainStartTime'), true );
                      break;
                  case 2:
                      IPS_SetHidden( $this->GetIDForIdent('remoteStart'), false );
                      IPS_SetHidden( $this->GetIDForIdent('door'), false );
                      IPS_SetHidden( $this->GetIDForIdent('remainTime'), true );
                      IPS_SetHidden( $this->GetIDForIdent('progress'), true );
                      IPS_SetHidden( $this->GetIDForIdent('remainStartTime'), false );
                      break;
                  case 3:
                      IPS_SetHidden( $this->GetIDForIdent('remoteStart'), true );
                      IPS_SetHidden( $this->GetIDForIdent('door'), true );
                      IPS_SetHidden( $this->GetIDForIdent('remainTime'), false );
                      IPS_SetHidden( $this->GetIDForIdent('progress'), false );
                      IPS_SetHidden( $this->GetIDForIdent('remainStartTime'), true );
                      break;
                  default:
                      IPS_SetHidden( $this->GetIDForIdent('remoteStart'), false );
                      IPS_SetHidden( $this->GetIDForIdent('door'), false );
                      IPS_SetHidden( $this->GetIDForIdent('remainTime'), false );
                      IPS_SetHidden( $this->GetIDForIdent('progress'), false );
                      IPS_SetHidden( $this->GetIDForIdent('remainStartTime'), false );
              }
          } else {
              IPS_SetHidden( $this->GetIDForIdent('remoteStart'), false );
              IPS_SetHidden( $this->GetIDForIdent('door'), false );
              IPS_SetHidden( $this->GetIDForIdent('remainTime'), false );
              IPS_SetHidden( $this->GetIDForIdent('progress'), false );
              IPS_SetHidden( $this->GetIDForIdent('remainStartTime'), false );
          }
      }

      /** Send Text
       * @param string $text Text in the Notification
       */
      protected function SendNotify( string $text ) {
          // Send notification for mobile devices (if on)
          if ( $this->ReadPropertyInteger("notify_instance") != 0 ) {
              WFC_PushNotification( $this->ReadPropertyInteger("notify_instance"), "HomeConnect", $text, $this->ReadPropertyString("notify_sound"), $this->InstanceID );
          }
          // Send notification for webfront (if on)
          if ( $this->ReadPropertyInteger("web_notify_instance") != 0 ) {
              WFC_SendNotification( $this->ReadPropertyInteger("web_notify_instance"), "HomeConnect", $text, "Power", $this->ReadPropertyInteger("web_notify_Timeout") );
          }
      }

      /** Function to set Profile of a Integer Var
       * @param string $profile Name of the profile
       */
      public function BuildList( string $profile ) {
          try {
              // make api call to get available programs on this device
              $programs = Api("homeappliances/" . $this->ReadPropertyString("haId") . "/programs/available")['data']['programs'];
          } catch (Exception $ex) {
              $this->SetStatus( analyseEX($ex) );
          }
          // count programs
          $programs_count = count( $programs );

          // build list with associations
          for ($i = 0; $i < $programs_count ; $i++) {
              if ( $this->ReadPropertyBoolean("mode_translate") ) {
                  IPS_SetVariableProfileAssociation($profile, $i, DryerTranslateMode( explode( ".",$programs[$i]["key"])[3], true ), "", 0x828282 );
              } else {
                  IPS_SetVariableProfileAssociation($profile, $i, explode( ".", $programs[$i]["key"])[3], "", 0x828282 );
              }
          }

          if ( $this->ReadPropertyBoolean("mode_translate") ) {
              IPS_SetVariableProfileAssociation("HC_DryerOption", 0, 'Eisen trocken', "", 0x828282 );
              IPS_SetVariableProfileAssociation("HC_DryerOption", 1, 'Schranktrocken', "", 0x828282 );
              IPS_SetVariableProfileAssociation("HC_DryerOption", 2, 'Schranktrocken plus', "", 0x828282 );
          } else {
              IPS_SetVariableProfileAssociation("HC_DryerOption", 0, 'IronDry', "", 0x828282 );
              IPS_SetVariableProfileAssociation("HC_DryerOption", 1, 'CupboardDry', "", 0x828282 );
              IPS_SetVariableProfileAssociation("HC_DryerOption", 2, 'CupboardDryPlus', "", 0x828282 );
          }
      }

      /** Function to set integer by name association
       * @param string $name
       */
      protected function SetListValue( string $name ) {
          // Get ID with Associations
          $profile = IPS_GetVariableProfile( "HC_DryerMode" )['Associations'];
          // count Associations
          $profile_count = count( $profile );
          // Make a list ( "Associations Name (mode)" => "Value of Associations")
          $profile_list = array();
          // Build list
          for ( $i = 0; $i < $profile_count; $i++ ) {
              $profile_list[$profile[$i]["Name"]] = $profile[$i]["Value"];
          }
          // Set Value to Associations name
          if ( $this->ReadPropertyBoolean("mode_translate")) {
              $name = DryerTranslateMode( $name, true );
          }
          $this->SetValue('mode', $profile_list[$name] );
      }

      /**Function to get name to association
       * @param bool type TRUE == DryerMode   FALSE == DryerOption
       * @return mixed return name
       */
      protected function GetListValue( bool $type ) {
          if ( $type ) {
              // Get ID with Associations
              $profile = IPS_GetVariableProfile( 'HC_DryerMode' )['Associations'];
              // count Associations
              $profile_count = count( $profile );
              // Make a list ( "Value of Association" => "Associations Name (mode)")
              $profile_list = array();
              // Build list
              for ( $i = 0; $i < $profile_count; $i++ ) {
                  $profile_list[$profile[$i]["Value"]] = $profile[$i]["Name"];
              }
              // Return mode name (string) to integer
              if ( $this->ReadPropertyBoolean("mode_translate") ) {
                  return DryerTranslateMode( $profile_list[$this->GetValue('mode')], false);
              }
              return $profile_list[$this->GetValue('mode')];
          } else {
              // Get ID with Associations
              $profile = IPS_GetVariableProfile( 'HC_DryerOption' )['Associations'];
              // count Associations
              $profile_count = count( $profile );
              // Make a list ( "Value of Association" => "Associations Name (mode)")
              $profile_list = array();
              // Build list
              for ( $i = 0; $i < $profile_count; $i++ ) {
                  $profile_list[$profile[$i]["Value"]] = $profile[$i]["Name"];
              }
              // Return mode name (string) to integer
              if ( $this->ReadPropertyBoolean("mode_translate") ) {
                  $dict = [
                      "Eisen trocken" => "IronDry",
                      "Schranktrocken" => "CupboardDry",
                      "Schranktrocken plus" => "CupboardDryPlus"
                  ];
                  return $dict[$profile_list[$this->GetValue('option')]];
              }
              return $profile_list[$this->GetValue('option')];
          }
      }

      /** Counting Seconds down
       * @param string $var_name
       */
      public function DownCount( string $var_name ) {
          // Counting down if device is in active or delayed start state
          if ( $this->GetValue('state') == 3 || $this->GetValue('state') == 2 ) {
              // get current timestamp
              $now = "1970-01-01 " . $this->GetValue( $var_name );
              // set timestamp in date format (after -1)
              $time = strtotime($now) + 3600;

              if ( $time >= 0 && $time < 28800 ) {
                  // set time
                  $set = gmdate("H:i:s", $time - 1);
                  // Set Value
                  $this->SetValue( $var_name, $set);
              } else {
                  // set no number
                  $this->SetValue( $var_name, "--:--:--");
                  // turn timer off (no reason to count down)
                  $this->SetTimerInterval('DownCountStart', 0);
                  $this->SetTimerInterval('DownCountProgram', 0);
                  // refresh data
                  $this->refresh();
              }
          } else {
              // set no number
              $this->SetValue( $var_name, "--:--:--");
              // turn timer off (no reason to count down)
              $this->SetTimerInterval('DownCountStart', 0);
              $this->SetTimerInterval('DownCountProgram', 0);
          }
      }

      /** Function to show all options of a running Device [for dev]
       * @param array $input input array after api call
       * @param string $row The next array options after data
       * @return mixed return array with KEY => VALUE
       */
      protected function getKeys( array $input, string $row ) {
          if ( isset( $input['data'] ) ) {
              // Get Options out of data
              $opt = $input['data'][$row];
              // no error appeared
              $this->SetStatus( 102 );

              // Define vars and length
              $options_count = count( $opt );
              $option_list[] = array();

              // Build options list
              for( $i = 0; $i < $options_count; $i++) {
                  // Get Data to set
                  $option_name = $opt[$i]['key'];
                  $option_value= $opt[$i]['value'];

                  $options_list[$option_name] = $option_value;
              }
              // Options list (KEY => VALUE)
              return $options_list;

          }
          return false;
      }

      /**
       * @param string $var that should be analyse
       * @return bool returns true or false for HomeConnect Api result
       */
      private function HC($var ) {
        // Return Variable to BSH Common type
        switch ( $var ) {
            //------------------------ DOOR
            case "BSH.Common.EnumType.DoorState.Open":
                return true;
            case "BSH.Common.EnumType.DoorState.Closed":
                return false;
            //------------------------ OPERATION STATE
            case "BSH.Common.EnumType.OperationState.Inactive":
                return 0;
            case "BSH.Common.EnumType.OperationState.Ready":
                return 1;
            case "BSH.Common.EnumType.OperationState.DelayedStart":
                return 2;
            case "BSH.Common.EnumType.OperationState.Run":
                return 3;
        }
        return 0;
    }

      /** Send logs to IP-Symcon
       * @param string $msg Message to send
       */
      protected function _log(string $msg) {
          if ( $this->ReadPropertyBoolean('log') ) {
              IPS_LogMessage("HomeConnectDryer", $msg);
          }
      }
  }
?>