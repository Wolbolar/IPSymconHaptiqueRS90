<?php

declare(strict_types=1);

include_once __DIR__ . '/../libs/SSDPTraits.php';

class HaptiqueRokuEmulator extends IPSModuleStrict
{
    // helper properties
    private $position = 0;

    private $MySerial = '';

    public function GetCompatibleParents(): string
    {
        return json_encode([
            'type' => 'require',
            'moduleIDs' => ['{8062CF2B-600E-41D6-AD4B-1BA66C32D6ED}'] // Server Socket
        ]);
    }
    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyInteger('ServerSocketPort', 8060);

        $this->MySerial = md5(openssl_random_pseudo_bytes(10));

        //we will wait until the kernel is ready
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();
        //  register profiles
        $this->RegisterProfileAssociation(
            'Haptique.FakeRokuIPS', 'Keyboard', '', '', 0, 1, 0, 0, 1, [
                [0, $this->Translate('Up'), '', -1],
                [1, $this->Translate('Down'), '', -1],
                [2, $this->Translate('Left'), '', -1],
                [3, $this->Translate('Right'), '', -1],
                [4, $this->Translate('Select'), '', -1],
                [5, $this->Translate('Back'), '', -1],
                [6, $this->Translate('Play'), '', -1],
                [7, $this->Translate('Reverse'), '', -1],
                [8, $this->Translate('Forward'), '', -1],
                [9, $this->Translate('Search'), '', -1],
                [10, $this->Translate('info'), '', -1],
                [11, $this->Translate('Home'), '', -1],
                [12, $this->Translate('Instant Replay'), '', -1], ]
        );

        $this->RegisterVariableInteger('KeyFakeRoku', 'Roku Emulator', 'Haptique.FakeRokuIPS', $this->_getPosition());
        $this->EnableAction('KeyFakeRoku');
        $this->RegisterVariableString('LastKeystrokeFakeRoku', 'Letzter Tastendruck', '', $this->_getPosition());
        $LastKeystrokeFakeRokuID = $this->GetIDForIdent('LastKeystrokeFakeRoku');
        IPS_SetIcon($LastKeystrokeFakeRokuID, 'Keyboard');
        $this->ValidateConfiguration();
    }

    public function GetConfigurationForParent(): string
    {
        $Config['Port'] = $this->ReadPropertyInteger('ServerSocketPort'); // Server Socket Port
        return json_encode($Config);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        $this->LogMessage('SenderID: ' . $SenderID . ', Message: ' . $Message . ', Data:' . json_encode($Data), KL_DEBUG);
        if ($Message == IPS_KERNELMESSAGE) {
            if ($Data[0] === KR_READY) {
                $this->CreateActivityProperties();
            }
        }
    }

    /*
     * RS90 support
        Navigation: keypress/home, keypress/up, keypress/down, keypress/left, keypress/right, keypress/select

        Playback: keypress/play, keypress/pause, keypress/forward, keypress/reverse

        App Launching: launch/<app_id>

        Text Input: keypress/Lit_<character> (for typing characters)

        Home/Menu Buttons: keypress/back, keypress/home, keypress/info, etc.
     */

    public function ReceiveData($JSONString): string
    {
        $data = json_decode($JSONString);
        $buffer = $data->Buffer;
        $this->SendDebug('RawBuffer', $buffer, 0);
        $Host = $data->ClientIP;
        $Port = $data->ClientPort;

        // Extrahiere die erste Zeile des HTTP-Requests
        $lines = explode("\r\n", $buffer);
        // Prüfe, ob erste Zeile vorhanden und nicht leer ist
        if (!isset($lines[0]) || trim($lines[0]) === '') {
            $this->SendDebug('ReceiveData', 'Leere Request-Zeile', 0);
            return '';
        }
        $requestLine = $lines[0]; // z.B. "GET /query/device-info HTTP/1.1"
        $parts = explode(' ', $requestLine);

        // HTTP-Request muss mindestens Methode, Pfad und Protokoll enthalten
        if (count($parts) < 3 || !isset($parts[0]) || !isset($parts[1]) || !isset($parts[2])) {
            $this->SendDebug('ReceiveData', 'Ungültige HTTP-Anfrage', 0);
            return '';
        }

        $method = strtoupper($parts[0]);
        $path = $parts[1];

        $this->SendDebug('ReceiveData', 'Methode: ' . $method . ', Pfad: ' . $path, 0);

        if ($method === 'GET') {
            switch ($path) {
                case '/query/device-info':
                    $this->RokuResponse($Host, $Port);
                    return '';
                case '/query/apps':
                    $this->SendToSocket($Host, $Port, "HTTP/1.1 200 OK\r\nContent-Type: text/xml\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
                    return '';
                case '/':
                    $this->RokuResponse($Host, $Port);
                    return '';
                default:
                    $this->SendDebug('ReceiveData', 'Unbekannter GET-Pfad: ' . $path, 0);
                    return '';
            }
        } elseif ($method === 'POST') {
            if (preg_match('#^/keypress/([^ ]+)#', $path, $matches)) {
                $command = $matches[1];
                $this->WriteValues($command);
            } elseif (preg_match('#^/launch/([^ ]+)#', $path, $matches)) {
                $appID = $matches[1];
                $this->SendDebug('ReceiveData', 'Launch request for App ID: ' . $appID, 0);
                $this->SendToSocket($Host, $Port, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
            } else {
                $this->SendDebug('ReceiveData', 'Unbekannter POST-Pfad: ' . $path, 0);
            }
        } else {
            $this->SendDebug('ReceiveData', 'Unbekannte Methode: ' . $method, 0);
        }
        return '';
    }

    public function RequestAction($Ident, $Value): void
    {
        $ObjID = $this->GetIDForIdent($Ident);
        // $lastkeyid = $this->GetIDForIdent("LastKeystrokeFakeRoku");
        SetValue($ObjID, $Value);
        //SetValue($lastkeyid, $keyval);
    }

    //Variablen anlegen
    public function SetupVariable(string $VarIdent, string $VarName, string $VarProfile)
    {
        $variablenID = $this->RegisterVariableInteger($VarIdent, $VarName, $VarProfile);
        $this->EnableAction($VarIdent);

        return $variablenID;
    }

    /*
     * Configuration Form
     */

    /**
     * build configuration form.
     *
     * @return string
     */
    public function GetConfigurationForm(): string
    {
        // update status, when configuration is not complete
        if (!$this->CheckConfiguration()) {
            $this->SetStatus(201);
        }

        // return current form
        return json_encode(
            [
                'elements' => $this->FormHead(),
                'actions'  => $this->FormActions(),
                'status'   => $this->FormStatus(), ]
        );
    }

    protected function WriteValues($data)
    {
        $data = ucfirst(strtolower($data));
        $this->SendDebug(__FUNCTION__, 'Roku Command: ' . $data, 0);
        // Prüfe, ob die Variable existiert, bevor sie gesetzt wird und logge den Wert
        $keyIdent = 'KeyFakeRoku';
        $keyId = @$this->GetIDForIdent($keyIdent);
        if ($keyId) {
            $this->SendDebug('WriteValues', 'Aktualisiere KeyFakeRoku mit: ' . $data, 0);
        } else {
            $this->SendDebug('WriteValues', 'KeyFakeRoku nicht gefunden!', 0);
        }
        if ($data == 'Up') {
            $this->SendDebug('WriteValues', 'Setze KeyFakeRoku: 0', 0);
            $this->SetValue('KeyFakeRoku', 0);
            $this->SetValue('LastKeystrokeFakeRoku', 'Up');
        } elseif ($data == 'Down') {
            $this->SendDebug('WriteValues', 'Setze KeyFakeRoku: 1', 0);
            $this->SetValue('KeyFakeRoku', 1);
            $this->SetValue('LastKeystrokeFakeRoku', 'Down');
        } elseif ($data == 'Left') {
            $this->SendDebug('WriteValues', 'Setze KeyFakeRoku: 2', 0);
            $this->SetValue('KeyFakeRoku', 2);
            $this->SetValue('LastKeystrokeFakeRoku', 'Left');
        } elseif ($data == 'Right') {
            $this->SendDebug('WriteValues', 'Setze KeyFakeRoku: 3', 0);
            $this->SetValue('KeyFakeRoku', 3);
            $this->SetValue('LastKeystrokeFakeRoku', 'Right');
        } elseif ($data == 'Select') {
            $this->SendDebug('WriteValues', 'Setze KeyFakeRoku: 4', 0);
            $this->SetValue('KeyFakeRoku', 4);
            $this->SetValue('LastKeystrokeFakeRoku', 'Select');
        } elseif ($data == 'Back') {
            $this->SendDebug('WriteValues', 'Setze KeyFakeRoku: 5', 0);
            $this->SetValue('KeyFakeRoku', 5);
            $this->SetValue('LastKeystrokeFakeRoku', 'Back');
        } elseif ($data == 'Play') {
            $this->SendDebug('WriteValues', 'Setze KeyFakeRoku: 6', 0);
            $this->SetValue('KeyFakeRoku', 6);
            $this->SetValue('LastKeystrokeFakeRoku', 'Play');
        } elseif ($data == 'Rev') {
            $this->SendDebug('WriteValues', 'Setze KeyFakeRoku: 7', 0);
            $this->SetValue('KeyFakeRoku', 7);
            $this->SetValue('LastKeystrokeFakeRoku', 'Rev');
        } elseif ($data == 'Fwd') {
            $this->SendDebug('WriteValues', 'Setze KeyFakeRoku: 8', 0);
            $this->SetValue('KeyFakeRoku', 8);
            $this->SetValue('LastKeystrokeFakeRoku', 'Fwd');
        } elseif ($data == 'Search') {
            $this->SendDebug('WriteValues', 'Setze KeyFakeRoku: 9', 0);
            $this->SetValue('KeyFakeRoku', 9);
            $this->SetValue('LastKeystrokeFakeRoku', 'Search');
        } elseif ($data == 'Info') {
            $this->SendDebug('WriteValues', 'Setze KeyFakeRoku: 10', 0);
            $this->SetValue('KeyFakeRoku', 10);
            $this->SetValue('LastKeystrokeFakeRoku', 'Info');
        } elseif ($data == 'Home') {
            $this->SendDebug('WriteValues', 'Setze KeyFakeRoku: 11', 0);
            $this->SetValue('KeyFakeRoku', 11);
            $this->SetValue('LastKeystrokeFakeRoku', 'Home');
        } elseif ($data == 'InstantReplay') {
            $this->SendDebug('WriteValues', 'Setze KeyFakeRoku: 12', 0);
            $this->SetValue('KeyFakeRoku', 12);
            $this->SetValue('LastKeystrokeFakeRoku', 'InstantReplay');
        }
    }


    protected function RokuResponse($Host, $Port)
    {
        $rokuresponse = '<root xmlns="urn:schemas-upnp-org:device-1-0">
<specVersion>
<major>1</major>
<minor>0</minor>
</specVersion>
<device>
<deviceType>urn:roku-com:device:player:1-0</deviceType>
<friendlyName>IP-Symcon (Roku Device)</friendlyName>
<manufacturer>IPSymconHaptique</manufacturer>
<manufacturerURL>https://github.com/Wolbolar/IPSymconHarmony</manufacturerURL>
<modelDescription>Roku Emulator IP-Symcon</modelDescription>
<modelName>IPS8</modelName>
<modelNumber>4200X</modelNumber>
<modelURL>https://github.com/Wolbolar/IPSymconHarmony</modelURL>
<serialNumber>' . $this->MySerial . '</serialNumber>
<UDN>uuid:roku:ecp:' . $this->MySerial . '</UDN>
<serviceList>
<service>
<serviceType>urn:roku-com:service:ecp:1</serviceType>
<serviceId>urn:roku-com:serviceId:ecp1-0</serviceId>
<controlURL/>
<eventSubURL/>
<SCPDURL>ecp_SCPD.xml</SCPDURL>
</service>
</serviceList>
</device>
</root>
';
        $Header[] = 'HTTP/1.1 200 OK';
        // $Header[] = "LOCATION: http://" . $this->GetIP() . ":".$this->ReadPropertyInteger("ServerSocketPort");
        // $Header[] = "Content-Type: application/xml; charset=utf-8";
        // $Header[] = "ST: roku:ecp";
        // $Header[] = "USN: uuid:roku:ecp:" . $this->MySerial;
        // $Header[] = "SERVER: Roku/1.0 UPnP/1.1";
        $Header[] = 'Content-Type: text/xml; charset=utf-8';
        $Header[] = 'Content-Length: ' . strlen($rokuresponse);
        $Header[] = 'Connection: Close';
        $Header[] = "\r\n";
        $Payload = implode("\r\n", $Header);
        $Payload .= '<?xml version="1.0" encoding="utf-8" ?>' . $rokuresponse;

        $result = $this->SendToSocket($Host, $Port, $Payload);

        return $result;
    }

    protected function SendToSocket($Host, $Port, $payload)
    {
        $SendData = [
            'DataID'     => '{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}',
            'Buffer'     => utf8_encode($payload),
            'ClientIP'   => $Host,
            'ClientPort' => $Port, ]; // Server Socket
        $this->SendDataToParent(json_encode($SendData));
        $this->SendDebug('SendData:', $payload, 0);
    }

    protected function GetIP()
    {
        $ssdpid = IPS_GetInstanceListByModuleID('{058CE601-4353-F473-EA14-A2B7B94628A0}')[0]; // SSDP;
        $instance = IPS_GetInstance($ssdpid);
        $parentssdp = $instance['ConnectionID'];
        $myIP = IPS_GetProperty($parentssdp, 'BindIP');

        return $myIP;
    }

    protected function GetHarmonyHubs()
    {
        $harmonyhubs = IPS_GetInstanceListByModuleID('{03B162DB-7A3A-41AE-A676-2444F16EBEDF}'); // Harmony Hub;
        return $harmonyhubs;
    }

    protected function GetSSDPRoku()
    {
        $ssdp_roku = IPS_GetInstanceListByModuleID('{058CE601-4353-F473-EA14-A2B7B94628A0}'); // SSDP Roku;
        return $ssdp_roku;
    }

    protected function GetHarmonyHubList()
    {
        $harmonyhubs = $this->GetHarmonyHubs();
        $options = [
            [
                'caption' => 'Please choose',
                'value'   => 0, ], ];
        foreach ($harmonyhubs as $harmonyhub) {
            $options[] = [
                'caption' => IPS_GetName($harmonyhub),
                'value'   => $harmonyhub, ];
        }

        return $options;
    }

    protected function GetHubActivities($HubID)
    {
        $activities = HarmonyHub_GetAvailableAcitivities($HubID);

        return $activities;
    }

    protected function GetHubActivitiesExpansionPanels($HubID, $form)
    {
        if (strlen(strval($HubID)) == 5) {
            $activities = $this->GetHubActivities($HubID);
            $number_activities = count($activities);
            if ($number_activities > 0) {
                foreach ($activities as $key => $activity) {
                    $form = array_merge_recursive(
                        $form, [
                            [
                                'type'    => 'ExpansionPanel',
                                'caption' => $key,
                                'items'   => [
                                    [
                                        'type'     => 'List',
                                        'name'     => $this->GetListName($HubID, $activity),
                                        'caption'  => 'Roku Emulator Keys',
                                        'rowCount' => 13,
                                        'add'      => false,
                                        'delete'   => false,
                                        'sort'     => [
                                            'column'    => 'command',
                                            'direction' => 'ascending', ],
                                        'columns'  => [
                                            [
                                                'name'    => 'command',
                                                'caption'   => 'command',
                                                'width'   => '200px',
                                                'save'    => true,
                                                'visible' => true, ],
                                            [
                                                'name'  => 'rokuscript',
                                                'caption' => 'script',
                                                'width' => 'auto',
                                                'save'  => true,
                                                'edit'  => [
                                                    'type' => 'SelectScript', ], ],
                                            [
                                                'name'    => 'key_id',
                                                'caption'   => 'Key ID',
                                                'width'   => 'auto',
                                                'save'    => true,
                                                'visible' => false, ], ],
                                        'values'   => [
                                            [
                                                'command' => 'Up',
                                                'key_id'  => 0, ],
                                            [
                                                'command' => 'Down',
                                                'key_id'  => 1, ],
                                            [
                                                'command' => 'Left',
                                                'key_id'  => 2, ],
                                            [
                                                'command' => 'Right',
                                                'key_id'  => 3, ],
                                            [
                                                'command' => 'Select',
                                                'key_id'  => 4, ],
                                            [
                                                'command' => 'Back',
                                                'key_id'  => 5, ],
                                            [
                                                'command' => 'Play',
                                                'key_id'  => 6, ],
                                            [
                                                'command' => 'Reverse',
                                                'key_id'  => 7, ],
                                            [
                                                'command' => 'Forward',
                                                'key_id'  => 8, ],
                                            [
                                                'command' => 'Search',
                                                'key_id'  => 9, ],
                                            [
                                                'command' => 'Info',
                                                'key_id'  => 10, ],
                                            [
                                                'command' => 'Home',
                                                'key_id'  => 11, ],
                                            [
                                                'command' => 'Instant Replay',
                                                'key_id'  => 12, ], ], ], ], ], ]
                    );
                }
            }
        }

        return $form;
    }

    protected function CreateActivityProperties()
    {
        $harmonyhubs = $this->GetHarmonyHubs();
        foreach ($harmonyhubs as $harmonyhub) {
            $activities = $this->GetHubActivities($harmonyhub);
            foreach ($activities as $key => $activity) {
                $activityId = is_numeric($activity) ? (int) $activity : 0;
                if ($activityId === 0) {
                    continue;
                }
                $this->RegisterPropertyString('rokukeys_' . $harmonyhub . '_' . abs($activityId), '[]');
            }
        }
    }

    protected function GetListName($HarmonyHubObjID, $HarmonyHubActivity)
    {
        $activityId = is_numeric($HarmonyHubActivity) ? (int) $HarmonyHubActivity : 0;
        $name = 'rokukeys_' . $HarmonyHubObjID . '_' . abs($activityId);

        return $name;
    }

    /**
     * register profiles.
     *
     * @param $Name
     * @param $Icon
     * @param $Prefix
     * @param $Suffix
     * @param $MinValue
     * @param $MaxValue
     * @param $StepSize
     * @param $Digits
     * @param $Vartype
     */
    protected function RegisterProfile($Name, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, $StepSize, $Digits, $Vartype)
    {
        if (!IPS_VariableProfileExists($Name)) {
            IPS_CreateVariableProfile($Name, $Vartype); // 0 boolean, 1 int, 2 float, 3 string,
        } else {
            $profile = IPS_GetVariableProfile($Name);
            if ($profile['ProfileType'] != $Vartype) {
                $this->_debug('profile', 'Variable profile type does not match for profile ' . $Name);
            }
        }

        IPS_SetVariableProfileIcon($Name, $Icon);
        IPS_SetVariableProfileText($Name, $Prefix, $Suffix);
        IPS_SetVariableProfileDigits($Name, $Digits); //  Nachkommastellen
        IPS_SetVariableProfileValues(
            $Name, $MinValue, $MaxValue, $StepSize
        ); // string $ProfilName, float $Minimalwert, float $Maximalwert, float $Schrittweite
    }

    /**
     * register profile association.
     *
     * @param $Name
     * @param $Icon
     * @param $Prefix
     * @param $Suffix
     * @param $MinValue
     * @param $MaxValue
     * @param $Stepsize
     * @param $Digits
     * @param $Vartype
     * @param $Associations
     */
    protected function RegisterProfileAssociation($Name, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, $Stepsize, $Digits, $Vartype, $Associations)
    {
        if (is_array($Associations) && count($Associations) === 0) {
            $MinValue = 0;
            $MaxValue = 0;
        }
        $this->RegisterProfile($Name, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, $Stepsize, $Digits, $Vartype);

        if (is_array($Associations)) {
            foreach ($Associations as $Association) {
                IPS_SetVariableProfileAssociation($Name, $Association[0], $Association[1], $Association[2], $Association[3]);
            }
        } else {
            $Associations = $this->$Associations;
            foreach ($Associations as $code => $association) {
                IPS_SetVariableProfileAssociation($Name, $code, $this->Translate($association), $Icon, -1);
            }
        }
    }

    /**
     * return form configurations on configuration step.
     *
     * @return array
     */
    protected function FormHead()
    {
        $form = [
            [
                'type'  => 'Label',
                'caption' => 'Roku Emulator IP-Symcon'
            ]
        ];

        return $form;
    }

    /**
     * return form actions by token.
     *
     * @return array
     */
    protected function FormActions()
    {
        $form = [];

        return $form;
    }

    /**
     * return from status.
     *
     * @return array
     */
    protected function FormStatus()
    {
        $form = [
            [
                'code'    => IS_CREATING,
                'icon'    => 'inactive',
                'caption' => 'Creating instance.', ],
            [
                'code'    => IS_ACTIVE,
                'icon'    => 'active',
                'caption' => 'Roku emulator device created.', ],
            [
                'code'    => IS_INACTIVE,
                'icon'    => 'inactive',
                'caption' => 'interface closed.', ],
            [
                'code'    => 201,
                'icon'    => 'inactive',
                'caption' => 'Please follow the instructions.', ],
            [
                'code'    => 202,
                'icon'    => 'error',
                'caption' => 'Device code must not be empty.', ],
            [
                'code'    => 203,
                'icon'    => 'error',
                'caption' => 'Device code has not the correct lenght.', ],
            [
                'code'    => 204,
                'icon'    => 'error',
                'caption' => 'no Harmony Hub selected.', ], ];

        return $form;
    }


    /**
     * Die folgenden Funktionen stehen automatisch zur Verfügung, wenn das Modul über die "Module Control" eingefügt wurden.
     * Die Funktionen werden, mit dem selbst eingerichteten Prefix, in PHP und JSON-RPC wiefolgt zur Verfügung gestellt:.
     */
    private function ValidateConfiguration()
    {
        $this->SetStatus(102);
    }

    /**
     * checks, if configuration is complete.
     *
     * @return bool
     */
    private function CheckConfiguration()
    {
        $ServerSocketPort = $this->ReadPropertyInteger('ServerSocketPort');
        if ($ServerSocketPort > 0) {
            return true;
        }

        if ($ServerSocketPort == 0) {
            $this->_debug('Roku Emulator', 'Please select a port');
            $this->SetStatus(202);

            return false;
        }

        return true;
    }

    /*
     * Helper methods
     */

    /**
     * send debug log.
     *
     * @param string $notification
     * @param string $message
     * @param int    $format       0 = Text, 1 = Hex
     */
    private function _debug(string $notification = null, string $message = null, $format = 0)
    {
        $this->SendDebug($notification, $message, $format);
    }

    /**
     * return incremented position.
     *
     * @return int
     */
    private function _getPosition()
    {
        $this->position++;

        return $this->position;
    }
}
