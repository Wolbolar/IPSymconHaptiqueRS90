<?php

declare(strict_types=1);

class HaptiqueSetupConfigurator extends IPSModuleStrict
{
    const BASE_URL = "https://app.cantatacs.com";
    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        // Setup-Zwischenspeicher bewusst als Attribute,
        // damit während des Wizards kein "Änderungen übernehmen" nötig ist
        $this->RegisterAttributeString('SetupCantataEmail', '');
        $this->RegisterAttributeString('SetupCantataPassword', '');
        $this->RegisterAttributeBoolean('SetupCantataLoginChecked', false);
        $this->RegisterAttributeBoolean('SetupCantataLoginValid', false);
        $this->RegisterAttributeString('SetupCantataLoginMessage', '');
        $this->RegisterAttributeString('SetupRS90IPAddress', '');
        $this->RegisterAttributeBoolean('SetupUseMQTT', false);
        $this->RegisterAttributeInteger('SetupMQTTServerMode', 0); // 0 = dieses Symcon, 1 = anderer MQTT Server
        $this->RegisterAttributeString('SetupMQTTUsername', '');
        $this->RegisterAttributeString('SetupMQTTPassword', '');
        $this->RegisterAttributeBoolean('SetupUseVoiceServices', false);
        $this->RegisterAttributeBoolean('SetupVoiceAlexa', false);
        $this->RegisterAttributeBoolean('SetupVoiceGoogle', false);
        $this->RegisterAttributeBoolean('SetupVoiceApple', false);

        $this->RegisterAttributeString('RS90_AccessToken', '');
        $this->RegisterAttributeString('RS90_Cookie', '');
        $this->RegisterAttributeBoolean('RS90_LoginFailed', false);
        $this->RegisterAttributeString('RS90_LastError', '');

        //we will wait until the kernel is ready
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
    }

    public function Destroy(): void
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->UpdateWizardVisibility();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        //Never delete this line!
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
            $this->SendDebug(__FUNCTION__, "🔄 Kernel Ready", 0);
        }

        if ($Message == IPS_KERNELSTARTED) {
            $this->SendDebug(__FUNCTION__, "🔄 Kernel Started", 0);
        }

        if ($Message == IM_CHANGESTATUS && $Data[0] == IS_ACTIVE) {
            $this->SendDebug(__FUNCTION__, "🔄 Instanz aktiv", 0);
        }
    }

    public function SaveCantataCredentials(string $email, string $password): void
    {
        $email = trim($email);
        $password = trim($password);

        $this->WriteAttributeString('SetupCantataEmail', $email);
        $this->WriteAttributeString('SetupCantataPassword', $password);

        if (!$this->IsValidEmail($email) || $password === '') {
            $this->WriteAttributeBoolean('SetupCantataLoginChecked', false);
            $this->WriteAttributeBoolean('SetupCantataLoginValid', false);
            $this->WriteAttributeString('SetupCantataLoginMessage', 'Bitte eine gültige E-Mail-Adresse und ein Passwort eingeben.');
            $this->SetStatus(200);
            $this->UpdateWizardVisibility();
            return;
        }

        $this->ValidateCantataLogin();
        $this->UpdateWizardVisibility();
    }

    public function ValidateCantataLogin(): void
    {
        $result = $this->RS90_Login();

        $this->WriteAttributeBoolean('SetupCantataLoginChecked', true);

        if (strpos($result, 'ERROR:') === 0) {
            $message = substr($result, 7);
            $this->WriteAttributeBoolean('SetupCantataLoginValid', false);
            $this->WriteAttributeString('SetupCantataLoginMessage', $message);
            $this->SetStatus(200);
            return;
        }

        $this->WriteAttributeBoolean('SetupCantataLoginValid', true);
        $this->WriteAttributeString('SetupCantataLoginMessage', 'Cantata Login erfolgreich.');
        $this->SetStatus(IS_ACTIVE);
    }

    public function SaveRS90IPAddress(string $ipAddress): void
    {
        $ipAddress = trim($ipAddress);
        $this->WriteAttributeString('SetupRS90IPAddress', $ipAddress);

        $this->SetStatus($this->IsValidIPv4($ipAddress) ? IS_ACTIVE : 200);
        $this->UpdateWizardVisibility();
    }

    public function SetUseMQTT(bool $useMQTT): void
    {
        $this->WriteAttributeBoolean('SetupUseMQTT', $useMQTT);
        $this->UpdateWizardVisibility();
    }

    public function SetMQTTServerMode(int $serverMode): void
    {
        if (!in_array($serverMode, [0, 1], true)) {
            $this->SetStatus(200);
            throw new Exception('Ungültige Auswahl für den MQTT Server Modus.');
        }

        $this->WriteAttributeInteger('SetupMQTTServerMode', $serverMode);
        $this->UpdateWizardVisibility();
    }

    public function SaveMQTTCredentials(string $username, string $password): void
    {
        $username = trim($username);
        $this->WriteAttributeString('SetupMQTTUsername', $username);

        $password = trim($password);
        $this->WriteAttributeString('SetupMQTTPassword', $password);

        $mqttPassword = $this->ReadAttributeString('SetupMQTTPassword');
        $hasMqttCredentials = ($username !== '') && ($mqttPassword !== '');

        $this->SetStatus($hasMqttCredentials ? IS_ACTIVE : 200);
        $this->UpdateWizardVisibility();
    }

    public function SetUseVoiceServices(bool $useVoiceServices): void
    {
        $this->WriteAttributeBoolean('SetupUseVoiceServices', $useVoiceServices);
        $this->UpdateWizardVisibility();
    }

    public function SaveVoiceServices(bool $alexa, bool $google, bool $apple): void
    {
        $this->WriteAttributeBoolean('SetupVoiceAlexa', $alexa);
        $this->WriteAttributeBoolean('SetupVoiceGoogle', $google);
        $this->WriteAttributeBoolean('SetupVoiceApple', $apple);
        $this->UpdateWizardVisibility();
    }

    protected function UpdateWizardVisibility(): void
    {
        $cantataEmail = $this->ReadAttributeString('SetupCantataEmail');
        $cantataPassword = $this->ReadAttributeString('SetupCantataPassword');
        $cantataLoginChecked = $this->ReadAttributeBoolean('SetupCantataLoginChecked');
        $cantataLoginValid = $this->ReadAttributeBoolean('SetupCantataLoginValid');
        $rs90Ip = $this->ReadAttributeString('SetupRS90IPAddress');
        $useMqtt = $this->ReadAttributeBoolean('SetupUseMQTT');
        $mqttUsername = $this->ReadAttributeString('SetupMQTTUsername');
        $mqttPassword = $this->ReadAttributeString('SetupMQTTPassword');
        $useVoiceServices = $this->ReadAttributeBoolean('SetupUseVoiceServices');

        $hasCantataCredentials = $this->IsValidEmail($cantataEmail) && ($cantataPassword !== '');
        $showPostLoginPanels = $hasCantataCredentials && $cantataLoginChecked && $cantataLoginValid;
        $hasRs90Ip = $this->IsValidIPv4($rs90Ip);
        $showMqttQuestion = $showPostLoginPanels && $hasRs90Ip;
        $showMqttPanel = $showMqttQuestion && $useMqtt;
        $hasMqttCredentials = $showMqttPanel && ($mqttUsername !== '') && ($mqttPassword !== '');
        $showMqttDeviceConfiguratorPanel = $hasMqttCredentials;
        $deviceConfiguratorInstanceID = $this->FindFirstInstanceByModuleName('Cantata RS90 Device Configurator');
        $showVoiceQuestion = $showMqttDeviceConfiguratorPanel && ($deviceConfiguratorInstanceID > 0);
        $showVoicePanel = $showVoiceQuestion && $useVoiceServices;
        $showCantataLoginError = $hasCantataCredentials && $cantataLoginChecked && !$cantataLoginValid;

        $this->UpdateFormField('CantataLoginErrorLabel', 'visible', $showCantataLoginError);
        $this->UpdateFormField('RS90IpPanel', 'visible', $showPostLoginPanels);
        $this->UpdateFormField('RS90IpPanel', 'expanded', $showPostLoginPanels);

        $this->UpdateFormField('PostLoginConfiguratorsPanel', 'visible', $showPostLoginPanels);
        $this->UpdateFormField('PostLoginConfiguratorsPanel', 'expanded', $showPostLoginPanels);

        $this->UpdateFormField('MQTTQuestionLabel', 'visible', $showMqttQuestion);
        $this->UpdateFormField('WizardUseMQTT', 'visible', $showMqttQuestion);

        $this->UpdateFormField('MQTTSettingsPanel', 'visible', $showMqttPanel);
        $this->UpdateFormField('MQTTSettingsPanel', 'expanded', $showMqttPanel);

        $this->UpdateFormField('MQTTDeviceConfiguratorPanel', 'visible', $showMqttDeviceConfiguratorPanel);
        $this->UpdateFormField('MQTTDeviceConfiguratorPanel', 'expanded', $showMqttDeviceConfiguratorPanel);
        $this->UpdateFormField('VoiceServicesHintLabel', 'visible', $showVoiceQuestion);
        $this->UpdateFormField('VoiceServicesQuestionLabel', 'visible', $showVoiceQuestion);
        $this->UpdateFormField('WizardUseVoiceServices', 'visible', $showVoiceQuestion);

        $this->UpdateFormField('VoiceServicesPanel', 'visible', $showVoicePanel);
        $this->UpdateFormField('VoiceServicesPanel', 'expanded', $showVoicePanel);
    }

    private function IsValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function IsValidIPv4(string $ipAddress): bool
    {
        return filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    private function FindModuleIDByName(string $moduleName): string
    {
        foreach (IPS_GetModuleList() as $moduleID) {
            $module = IPS_GetModule($moduleID);
            if (($module['ModuleName'] ?? '') === $moduleName) {
                return $moduleID;
            }
        }

        return '';
    }

    private function FindFirstInstanceByModuleName(string $moduleName): int
    {
        $moduleID = $this->FindModuleIDByName($moduleName);
        if ($moduleID === '') {
            return 0;
        }

        $instances = IPS_GetInstanceListByModuleID($moduleID);
        if (count($instances) === 0) {
            return 0;
        }

        return (int) $instances[0];
    }

    private function BuildConfiguratorValue(string $moduleName, array $location = []): array
    {
        $moduleID = $this->FindModuleIDByName($moduleName);
        $instanceID = $this->FindFirstInstanceByModuleName($moduleName);

        $value = [
            'name'       => $moduleName,
            'address'    => $moduleID === '' ? 'Modul nicht gefunden' : 'Konfigurator',
            'instanceID' => $instanceID
        ];

        if ($moduleID !== '') {
            $value['create'] = [
                'moduleID'      => $moduleID,
                'configuration' => [],
                'location'      => $location,
                'name'          => $moduleName
            ];
        }

        return $value;
    }

    /**
     * Loggt sich per HTTP bei der RS90 Cloud ein und speichert AccessToken und Cookie als Attribute.
     * @return string AccessToken oder Fehlermeldung
     */
    public function RS90_Login(): string
    {
        $url = self::BASE_URL . "/app/auth/login/app";

        $user = trim($this->ReadAttributeString('SetupCantataEmail'));
        $password = (string) $this->ReadAttributeString('SetupCantataPassword');

        $this->SendDebug(__FUNCTION__, 'Starting login for user: ' . $user . ' | password length: ' . strlen($password), 0);

        if ($user === '' || $password === '') {
            $message = 'RS90 login failed: user or password is empty';
            $this->WriteAttributeString('RS90_AccessToken', '');
            $this->WriteAttributeString('RS90_Cookie', '');
            $this->WriteAttributeBoolean('RS90_LoginFailed', true);
            $this->WriteAttributeString('RS90_LastError', $message);
            $this->SendDebug(__FUNCTION__, $message, 0);
            return 'ERROR: ' . $message;
        }

        $credentials = [
            'email' => $user,
            'password' => $password
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($credentials));
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: */*',
            'User-Agent: Symcon RS90'
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $message = 'RS90 login cURL error: ' . $error;
            $this->WriteAttributeString('RS90_AccessToken', '');
            $this->WriteAttributeString('RS90_Cookie', '');
            $this->WriteAttributeBoolean('RS90_LoginFailed', true);
            $this->WriteAttributeString('RS90_LastError', $message);
            $this->SendDebug(__FUNCTION__, $message, 0);
            return 'ERROR: ' . $message;
        }

        $header = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        $this->SendDebug(__FUNCTION__, 'HTTP Code: ' . $httpCode, 0);
        $this->SendDebug(__FUNCTION__, 'Header length: ' . strlen($header) . ' | Body length: ' . strlen($body), 0);

        $data = json_decode($body, true);
        if (!is_array($data)) {
            $message = 'RS90 login failed: invalid JSON response';
            $this->WriteAttributeString('RS90_AccessToken', '');
            $this->WriteAttributeString('RS90_Cookie', '');
            $this->WriteAttributeBoolean('RS90_LoginFailed', true);
            $this->WriteAttributeString('RS90_LastError', $message);
            $this->SendDebug(__FUNCTION__, $message . ' | body: ' . $body, 0);
            return 'ERROR: ' . $message;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $apiMessage = (string)($data['message'] ?? 'Unexpected HTTP status');
            $message = 'RS90 login failed: HTTP ' . $httpCode . ' - ' . $apiMessage;
            $this->WriteAttributeString('RS90_AccessToken', '');
            $this->WriteAttributeString('RS90_Cookie', '');
            $this->WriteAttributeBoolean('RS90_LoginFailed', true);
            $this->WriteAttributeString('RS90_LastError', $message);
            $this->SendDebug(__FUNCTION__, $message, 0);
            return 'ERROR: ' . $message;
        }

        if (!isset($data['data']['accessToken']) || trim((string) $data['data']['accessToken']) === '') {
            $apiMessage = (string)($data['message'] ?? 'accessToken not found');
            $message = 'RS90 login failed: ' . $apiMessage;
            $this->WriteAttributeString('RS90_AccessToken', '');
            $this->WriteAttributeString('RS90_Cookie', '');
            $this->WriteAttributeBoolean('RS90_LoginFailed', true);
            $this->WriteAttributeString('RS90_LastError', $message);
            $this->SendDebug(__FUNCTION__, $message, 0);
            return 'ERROR: ' . $message;
        }

        $token = trim((string) $data['data']['accessToken']);
        $this->WriteAttributeString('RS90_AccessToken', $token);

        if (!preg_match('/set-cookie:\\s*connect\\.sid=([^;]+);/i', $header, $matches)) {
            $message = 'RS90 login failed: connect.sid cookie not found';
            $this->WriteAttributeString('RS90_AccessToken', '');
            $this->WriteAttributeString('RS90_Cookie', '');
            $this->WriteAttributeBoolean('RS90_LoginFailed', true);
            $this->WriteAttributeString('RS90_LastError', $message);
            $this->SendDebug(__FUNCTION__, $message, 0);
            return 'ERROR: ' . $message;
        }

        $cookie = trim((string) $matches[1]);
        $this->WriteAttributeString('RS90_Cookie', $cookie);
        $this->WriteAttributeBoolean('RS90_LoginFailed', false);
        $this->WriteAttributeString('RS90_LastError', '');

        $this->SendDebug(__FUNCTION__, 'RS90 login successful | token length: ' . strlen($token) . ' | cookie length: ' . strlen($cookie), 0);
        return $token;
    }

    /**
     * Gibt den aktuellen RS90 AccessToken zurück (für Skripte).
     * @return string
     */
    public function GetRS90Token(): string
    {
        return $this->ReadAttributeString('RS90_AccessToken');
    }

    /**
     * build configuration form
     *
     * @return string
     */
    public function GetConfigurationForm(): string
    {
        // return current form
        $form = [
            'elements' => $this->FormHead(),
            'actions' => $this->FormActions(),
            'status' => $this->FormStatus()];

        $formSize = strlen(json_encode($form));  // Größe des Formulars in Bytes
        $this->SendDebug('Form Size', "Configuration form size: " . $formSize . " bytes", 0);
        return json_encode($form);
    }

    /**
     * return form configurations on configuration step
     *
     * @return array
     */
    protected function FormHead(): array
    {
        $cantataEmail = $this->ReadAttributeString('SetupCantataEmail');
        $cantataPassword = $this->ReadAttributeString('SetupCantataPassword');
        $cantataLoginChecked = $this->ReadAttributeBoolean('SetupCantataLoginChecked');
        $cantataLoginValid = $this->ReadAttributeBoolean('SetupCantataLoginValid');
        $cantataLoginMessage = $this->ReadAttributeString('SetupCantataLoginMessage');
        $rs90Ip = $this->ReadAttributeString('SetupRS90IPAddress');
        $useMqtt = $this->ReadAttributeBoolean('SetupUseMQTT');
        $mqttServerMode = $this->ReadAttributeInteger('SetupMQTTServerMode');
        $mqttUsername = $this->ReadAttributeString('SetupMQTTUsername');
        $mqttPassword = $this->ReadAttributeString('SetupMQTTPassword');
        $useVoiceServices = $this->ReadAttributeBoolean('SetupUseVoiceServices');
        $voiceAlexa = $this->ReadAttributeBoolean('SetupVoiceAlexa');
        $voiceGoogle = $this->ReadAttributeBoolean('SetupVoiceGoogle');
        $voiceApple = $this->ReadAttributeBoolean('SetupVoiceApple');

        $hasCantataCredentials = $this->IsValidEmail($cantataEmail) && ($cantataPassword !== '');
        $showCantataLoginError = $hasCantataCredentials && $cantataLoginChecked && !$cantataLoginValid;
        $showPostLoginPanels = $hasCantataCredentials && $cantataLoginChecked && $cantataLoginValid;
        $hasRs90Ip = $this->IsValidIPv4($rs90Ip);
        $showMqttQuestion = $showPostLoginPanels && $hasRs90Ip;
        $showMqttPanel = $showMqttQuestion && $useMqtt;
        $hasMqttCredentials = $showMqttPanel && ($mqttUsername !== '') && ($mqttPassword !== '');
        $showMqttDeviceConfiguratorPanel = $hasMqttCredentials;
        $deviceConfiguratorInstanceID = $this->FindFirstInstanceByModuleName('Cantata RS90 Device Configurator');
        $showVoiceQuestion = $showMqttDeviceConfiguratorPanel && ($deviceConfiguratorInstanceID > 0);
        $showVoicePanel = $showVoiceQuestion && $useVoiceServices;

        $form = [
            [
                'type'    => 'Label',
                'caption' => 'Dieser Setup Konfigurator führt Sie Schritt für Schritt durch den Setup Prozess des Moduls in Symcon.'
            ],
            [
                'type'    => 'Label',
                'caption' => 'Bitte füllen Sie zunächst die Cantata Zugangsdaten aus:'
            ],
            [
                'type'     => 'ExpansionPanel',
                'name'     => 'CantataCredentialsPanel',
                'caption'  => 'Cantata Benutzer Daten',
                'icon'     => 'Key',
                'expanded' => true,
                'items'    => [
                    [
                        'type'     => 'ValidationTextBox',
                        'name'     => 'WizardCantataEmail',
                        'caption'  => 'Cantata Email ID',
                        'value'    => $cantataEmail,
                        'validate' => '^.+@.+\..+$'
                    ],
                    [
                        'type'    => 'PasswordTextBox',
                        'name'    => 'WizardCantataPassword',
                        'caption' => 'Cantata Password',
                        'value'   => $cantataPassword
                    ],
                    [
                        'type'    => 'Button',
                        'caption' => 'Cantata Zugangsdaten übernehmen',
                        'onClick' => 'CRSSC_SaveCantataCredentials($id, $WizardCantataEmail, $WizardCantataPassword);'
                    ]
                ]
            ],
            [
                'type'    => 'Label',
                'name'    => 'CantataLoginErrorLabel',
                'caption' => $cantataLoginMessage,
                'visible' => $showCantataLoginError
            ],
            [
                'type'     => 'ExpansionPanel',
                'name'     => 'RS90IpPanel',
                'caption'  => 'RS90 IP Address',
                'icon'     => 'Network',
                'expanded' => $showPostLoginPanels,
                'visible'  => $showPostLoginPanels,
                'items'    => [
                    [
                        'type'     => 'ValidationTextBox',
                        'name'     => 'WizardRS90IPAddress',
                        'caption'  => 'RS90 IP Adresse',
                        'value'    => $rs90Ip,
                        'validate' => '^(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3}$'
                    ],
                    [
                        'type'    => 'Button',
                        'caption' => 'RS90 IP Adresse übernehmen',
                        'onClick' => 'CRSSC_SaveRS90IPAddress($id, $WizardRS90IPAddress);'
                    ]
                ]
            ],
            [
                'type'     => 'ExpansionPanel',
                'name'     => 'PostLoginConfiguratorsPanel',
                'caption'  => 'Cantata Konfiguratoren',
                'icon'     => 'Gear',
                'expanded' => $showPostLoginPanels,
                'visible'  => $showPostLoginPanels,
                'items'    => [
                    [
                        'type'    => 'Configurator',
                        'name'    => 'IPDevicesConfiguratorList',
                        'caption' => 'Cantata RS90 IP Devices Configurator',
                        'rowCount' => 1,
                        'values'  => [
                            $this->BuildConfiguratorValue('Cantata RS90 IP Devices Configurator', ['Cantata', 'RS90'])
                        ]
                    ],
                    [
                        'type'    => 'Configurator',
                        'name'    => 'ImportConfiguratorList',
                        'caption' => 'Cantata RS90 Import Configurator',
                        'rowCount' => 1,
                        'values'  => [
                            $this->BuildConfiguratorValue('Cantata RS90 Import Configurator', ['Cantata', 'RS90'])
                        ]
                    ]
                ]
            ],
            [
                'type'    => 'Label',
                'name'    => 'MQTTQuestionLabel',
                'caption' => 'Haben Sie MQTT auf der RS90 konfiguriert bzw. wollen Sie MQTT nutzen?',
                'visible' => $showMqttQuestion
            ],
            [
                'type'    => 'Select',
                'name'    => 'WizardUseMQTT',
                'caption' => 'MQTT Nutzung',
                'visible' => $showMqttQuestion,
                'value'   => $useMqtt,
                'options' => [
                    ['caption' => 'Nein', 'value' => false],
                    ['caption' => 'Ja',   'value' => true]
                ],
                'onChange' => 'CRSSC_SetUseMQTT($id, $WizardUseMQTT);'
            ],
            [
                'type'     => 'ExpansionPanel',
                'name'     => 'MQTTSettingsPanel',
                'caption'  => 'MQTT Einstellungen für die RS90',
                'icon'     => 'Database',
                'expanded' => $showMqttPanel,
                'visible'  => $showMqttPanel,
                'items'    => [
                    [
                        'type'    => 'Select',
                        'name'    => 'WizardMQTTServerMode',
                        'caption' => 'MQTT Server',
                        'value'   => $mqttServerMode,
                        'options' => [
                            ['caption' => 'Dieses Symcon dient als MQTT Server', 'value' => 0],
                            ['caption' => 'Ich benutze einen anderen MQTT Server', 'value' => 1]
                        ],
                        'onChange' => 'CRSSC_SetMQTTServerMode($id, $WizardMQTTServerMode);'
                    ],
                    [
                        'type'     => 'ValidationTextBox',
                        'name'     => 'WizardMQTTUsername',
                        'caption'  => 'MQTT Benutzername',
                        'value'    => $mqttUsername
                    ],
                    [
                        'type'    => 'PasswordTextBox',
                        'name'    => 'WizardMQTTPassword',
                        'caption' => 'MQTT Password',
                        'value'   => $mqttPassword
                    ],
                    [
                        'type'    => 'Button',
                        'caption' => 'MQTT Einstellungen übernehmen',
                        'onClick' => 'CRSSC_SaveMQTTCredentials($id, $WizardMQTTUsername, $WizardMQTTPassword);'
                    ]
                ]
            ],
            [
                'type'     => 'ExpansionPanel',
                'name'     => 'MQTTDeviceConfiguratorPanel',
                'caption'  => 'MQTT / Device Konfiguration',
                'icon'     => 'Gear',
                'expanded' => $showMqttDeviceConfiguratorPanel,
                'visible'  => $showMqttDeviceConfiguratorPanel,
                'items'    => [
                    [
                        'type'    => 'Configurator',
                        'name'    => 'DeviceConfiguratorList',
                        'caption' => 'Cantata RS90 Device Configurator',
                        'rowCount' => 1,
                        'values'  => [
                            $this->BuildConfiguratorValue('Cantata RS90 Device Configurator', ['Cantata', 'RS90'])
                        ]
                    ],
                    [
                        'type'    => 'Label',
                        'name'    => 'VoiceServicesHintLabel',
                        'caption' => 'Wenn Sie Sprachdienste nutzen möchten, legen Sie bitte zunächst im Device Configurator die Makros an, die später per Sprache verwendet werden sollen.',
                        'visible' => $showVoiceQuestion
                    ],
                    [
                        'type'    => 'Label',
                        'name'    => 'VoiceServicesQuestionLabel',
                        'caption' => 'Möchten Sie Sprachdienste nutzen?',
                        'visible' => $showVoiceQuestion
                    ],
                    [
                        'type'    => 'Select',
                        'name'    => 'WizardUseVoiceServices',
                        'caption' => 'Sprachdienste nutzen',
                        'visible' => $showVoiceQuestion,
                        'value'   => $useVoiceServices,
                        'options' => [
                            ['caption' => 'Nein', 'value' => false],
                            ['caption' => 'Ja', 'value' => true]
                        ],
                        'onChange' => 'CRSSC_SetUseVoiceServices($id, $WizardUseVoiceServices);'
                    ]
                ]
            ],
            [
                'type'     => 'ExpansionPanel',
                'name'     => 'VoiceServicesPanel',
                'caption'  => 'Sprachdienst Konfiguration',
                'icon'     => 'Mic',
                'expanded' => $showVoicePanel,
                'visible'  => $showVoicePanel,
                'items'    => [
                    [
                        'type'    => 'CheckBox',
                        'name'    => 'WizardVoiceAlexa',
                        'caption' => 'Alexa',
                        'value'   => $voiceAlexa
                    ],
                    [
                        'type'    => 'CheckBox',
                        'name'    => 'WizardVoiceGoogle',
                        'caption' => 'Google',
                        'value'   => $voiceGoogle
                    ],
                    [
                        'type'    => 'CheckBox',
                        'name'    => 'WizardVoiceApple',
                        'caption' => 'Apple',
                        'value'   => $voiceApple
                    ],
                    [
                        'type'    => 'Button',
                        'caption' => 'Sprachdienste übernehmen',
                        'onClick' => 'CRSSC_SaveVoiceServices($id, $WizardVoiceAlexa, $WizardVoiceGoogle, $WizardVoiceApple);'
                    ]
                ]
            ]
        ];

        return $form;
    }

    protected function FormActions(): array
    {
        $form = [
        ];
        return $form;
    }

    /**
     * return from status
     *
     * @return array
     */
    protected function FormStatus(): array
    {
        $form = [
            ['code' => IS_CREATING, 'icon' => 'inactive', 'caption' => $this->Translate('Device is being created')],
            ['code' => IS_ACTIVE, 'icon' => 'active', 'caption' => $this->Translate('Device connected and active')],
            ['code' => IS_DELETING, 'icon' => 'inactive', 'caption' => $this->Translate('Device is being deleted')],
            ['code' => IS_INACTIVE, 'icon' => 'inactive', 'caption' => $this->Translate('Device inactive')],
            ['code' => IS_NOTCREATED, 'icon' => 'error', 'caption' => $this->Translate('Device not created or an error occurred')],
            ['code' => 200, 'icon' => 'error',    'caption' => 'Ungültige Eingabe']
        ];

        return $form;
    }
}