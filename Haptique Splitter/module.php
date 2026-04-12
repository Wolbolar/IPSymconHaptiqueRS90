<?php

declare(strict_types=1);

//include_once __DIR__ . '/helper/autoload.php';
//include_once __DIR__ . '/registry.php';
//include_once __DIR__ . '/capabilities/autoload.php';
//include_once __DIR__ . '/types/autoload.php';
//include_once __DIR__ . '/simulate.php';
require_once __DIR__ . '/../libs/WebSocketUtils.php';
require_once __DIR__ . '/../libs/DenonMarantzAVRModels.php';  // diverse Klassen
require_once __DIR__ . '/../libs/HomeAssistantEmulator.php';
require_once __DIR__ . '/../libs/HaptiqueMdnsDiscovery.php';

include_once __DIR__ . '/../libs/ClientSessionManagement.php';
require_once __DIR__ . '/../libs/DebugTrait.php';

use WebsocketHandler\WebSocketUtils;

class HaptiqueSplitter extends IPSModuleStrict
{
    use ClientSessionTrait;
    use DebugTrait;

    const DEFAULT_WS_PORT = 8123;

    const Socket_Data = 0;
    const Socket_Connected = 1;
    const Socket_Disconnected = 2;
    const Haptique_Driver_Version = "0.2.0";
    const BASE_URL = "https://app.cantatacs.com";

    private ?HomeAssistantEmulator $homeAssistantEmulator = null;
    private ?HaptiqueMdnsDiscovery $mdnsDiscovery = null;

    public function GetCompatibleParents(): string
    {
        return json_encode([
            'type' => 'connect',
            'moduleIDs' => ['{8062CF2B-600E-41D6-AD4B-1BA66C32D6ED}']
        ]);
    }

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyBoolean('EmulateHomeAssistant', true); // IP-Symcon sendet Antworten als Home Assistant true
        $this->RegisterPropertyBoolean('EmulateHomeAssistantDiscovery', true);
        $this->RegisterPropertyString('Token', '');
        $this->RegisterAttributeString('ClientIP', '');
        $this->RegisterAttributeInteger('ClientPort', 0);
        $this->RegisterAttributeString('DataID', '');
        $this->RegisterAttributeInteger('Type', 0);
        $this->RegisterAttributeString('HAWebSocketSubscriptions', '[]');
        $this->RegisterAttributeString('HAMediaPlayerVariableMap', '[]');
        $this->RegisterAttributeString('HAStateCache', '[]');
        $this->RegisterAttributeString('HAConfiguratorDataCache', '{}');
        $this->RegisterAttributeString('previousTree', '[]');
        $this->RegisterAttributeString('LastHarmonyPlayState', '');
        $this->RegisterAttributeString('AVDevicesTree', "[]");

        // --- Expert Debug / Debug Filtering ---
        $this->RegisterPropertyBoolean('expert_debug', false);
        $this->RegisterPropertyInteger('debug_level', 4); // 0=BASIC,1=ERROR,2=WARN,3=INFO,4=TRACE
        $this->RegisterPropertyBoolean('debug_filter_enabled', false);
        $this->RegisterPropertyString('debug_topics', ''); // comma-separated topics; empty = all
        $this->RegisterPropertyString('debug_entity_ids', ''); // comma-separated entity ids
        $this->RegisterPropertyString('debug_var_ids', ''); // comma-separated var/object ids
        $this->RegisterPropertyString('debug_client_ips', ''); // comma-separated IPs
        $this->RegisterPropertyString('debug_text_filter', ''); // substring or regex
        $this->RegisterPropertyBoolean('debug_text_is_regex', false);
        $this->RegisterPropertyBoolean('debug_strict_match', true); // require match when any filter is set
        $this->RegisterPropertyInteger('debug_throttle_ms', 0); // 0 disables throttling
        $this->RegisterPropertyString('debug_topics_cfg', '');
        $this->RegisterPropertyString('debug_filter_instances', '');
        $this->RegisterPropertyString('debug_client_ips_cfg', '');

        // Properties for expert settings
        $this->RegisterPropertyBoolean('extended_debug', true);

        // RS90 IP/Port Properties
        $this->RegisterPropertyString("RS90_IP", "192.168.55.147");
        $this->RegisterPropertyInteger("RS90_Port", 8080);

        // === Zugangsdaten/Token/Cookie für RS90 Cloud-Login ===
        $this->RegisterPropertyString('RS90_User', '');
        $this->RegisterPropertyString('RS90_Password', '');
        $this->RegisterAttributeString('RS90_AccessToken', '');
        $this->RegisterAttributeString('RS90_Cookie', '');
        $this->RegisterAttributeBoolean('RS90_LoginFailed', false);
        $this->RegisterAttributeString('RS90_LastError', '');

        $this->RegisterAttributeString('RS90_Dashboard', '');
        $this->RegisterAttributeString('RS90_ConnectedRemotes', '');

        $this->RegisterPropertyString('SequenceList', '[]');
        $this->RegisterPropertyString('RoomList', '[]');
        $this->RegisterPropertyString('DeviceDashboard', '[]');
        $this->RegisterPropertyString('custom_devices', '[]');
        $this->RegisterAttributeString('current_device_id', '');

        //We need to call the RegisterHook function on Kernel READY
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
    }

    /**
     * Aktualisiert oder speichert ein Custom-URL-Gerät inklusive aller URLs.
     * Hinweis: Die gesamte Gerätedefinition inkl. aller URLs muss übergeben werden.
     *
     * @param string $deviceId
     * @param string $deviceName
     * @param array $urlList
     * @param string $icon
     * @return array|null
     */
    public function RS90_StoreCustomURLDevice(string $deviceId, string $deviceName, array $urlList, string $icon = 'custom-urls.png'): ?array
    {
        $payload = [
            'id' => $deviceId,
            'name' => $deviceName,
            'urls' => $urlList,
            'icon' => $icon
        ];
        $result = $this->CallCantataAPI('/app/integration/custom-urls/store', $payload);
        return $result['data'] ?? null;
    }

    /**
     * Loggt sich per HTTP bei der RS90 Cloud ein und speichert AccessToken und Cookie als Attribute.
     * @return string AccessToken oder Fehlermeldung
     */
    public function RS90_Login(): string
    {
        $url = self::BASE_URL . "/app/auth/login/app";

        $user = trim($this->ReadPropertyString('RS90_User'));
        $password = (string) $this->ReadPropertyString('RS90_Password');

        $this->DebugLog(__FUNCTION__, 'Starting login for user: ' . $user . ' | password length: ' . strlen($password), 0);

        if ($user === '' || $password === '') {
            $message = 'RS90 login failed: user or password is empty';
            $this->WriteAttributeString('RS90_AccessToken', '');
            $this->WriteAttributeString('RS90_Cookie', '');
            $this->WriteAttributeBoolean('RS90_LoginFailed', true);
            $this->WriteAttributeString('RS90_LastError', $message);
            $this->DebugLog(__FUNCTION__, $message, 0);
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
            $this->DebugLog(__FUNCTION__, $message, 0);
            return 'ERROR: ' . $message;
        }

        $header = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        $this->DebugLog(__FUNCTION__, 'HTTP Code: ' . $httpCode, 0);
        $this->DebugLog(__FUNCTION__, 'Header length: ' . strlen($header) . ' | Body length: ' . strlen($body), 0);

        $data = json_decode($body, true);
        if (!is_array($data)) {
            $message = 'RS90 login failed: invalid JSON response';
            $this->WriteAttributeString('RS90_AccessToken', '');
            $this->WriteAttributeString('RS90_Cookie', '');
            $this->WriteAttributeBoolean('RS90_LoginFailed', true);
            $this->WriteAttributeString('RS90_LastError', $message);
            $this->DebugLog(__FUNCTION__, $message . ' | body: ' . $body, 0);
            return 'ERROR: ' . $message;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $apiMessage = (string)($data['message'] ?? 'Unexpected HTTP status');
            $message = 'RS90 login failed: HTTP ' . $httpCode . ' - ' . $apiMessage;
            $this->WriteAttributeString('RS90_AccessToken', '');
            $this->WriteAttributeString('RS90_Cookie', '');
            $this->WriteAttributeBoolean('RS90_LoginFailed', true);
            $this->WriteAttributeString('RS90_LastError', $message);
            $this->DebugLog(__FUNCTION__, $message, 0);
            return 'ERROR: ' . $message;
        }

        if (!isset($data['data']['accessToken']) || trim((string) $data['data']['accessToken']) === '') {
            $apiMessage = (string)($data['message'] ?? 'accessToken not found');
            $message = 'RS90 login failed: ' . $apiMessage;
            $this->WriteAttributeString('RS90_AccessToken', '');
            $this->WriteAttributeString('RS90_Cookie', '');
            $this->WriteAttributeBoolean('RS90_LoginFailed', true);
            $this->WriteAttributeString('RS90_LastError', $message);
            $this->DebugLog(__FUNCTION__, $message, 0);
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
            $this->DebugLog(__FUNCTION__, $message, 0);
            return 'ERROR: ' . $message;
        }

        $cookie = trim((string) $matches[1]);
        $this->WriteAttributeString('RS90_Cookie', $cookie);
        $this->WriteAttributeBoolean('RS90_LoginFailed', false);
        $this->WriteAttributeString('RS90_LastError', '');

        $this->DebugLog(__FUNCTION__, 'RS90 login successful | token length: ' . strlen($token) . ' | cookie length: ' . strlen($cookie), 0);
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

    public function RemoveCustomURLDevice(string $deviceId): array
    {
        $deviceId = trim($deviceId);
        if ($deviceId === '') {
            return [
                'Success' => false,
                'Message' => 'DeviceID missing',
                'Data' => null
            ];
        }

        $payload = ['id' => $deviceId];
        $this->DebugLog(__FUNCTION__ . ' Payload', json_encode($payload), 0);

        // Ensure we have a valid RS90 session
        $accessToken = trim($this->ReadAttributeString('RS90_AccessToken'));
        $cookie = trim($this->ReadAttributeString('RS90_Cookie'));

        if ($accessToken === '' || $cookie === '') {
            $this->DebugLog(__FUNCTION__, 'RS90 session missing -> calling RS90_Login()', 0);
            $loginResult = $this->RS90_Login();
            $this->DebugLog(__FUNCTION__, 'RS90_Login() result: ' . $loginResult, 0);
        }

        // First attempt
        $result = $this->CallCantataAPI('/app/device/remove', $payload);

        // If token expired/invalid, try one re-login and retry once
        if (is_array($result) && isset($result['success']) && $result['success'] === false) {
            $msg = (string)($result['message'] ?? '');
            if ($msg !== '' && stripos($msg, 'token') !== false) {
                $this->DebugLog(__FUNCTION__, 'Cantata API returned token-related error -> re-login and retry', 0);
                $loginResult = $this->RS90_Login();
                $this->DebugLog(__FUNCTION__, 'RS90_Login() result (retry): ' . $loginResult, 0);
                $result = $this->CallCantataAPI('/app/device/remove', $payload);
            }
        }

        if ($result === null) {
            return [
                'Success' => false,
                'Message' => 'CallCantataAPI returned null (network/auth error). Check RS90_Login debug and credentials.',
                'Data' => null
            ];
        }

        // Normalize response to our envelope
        $success = true;
        if (isset($result['success'])) {
            $success = (bool)$result['success'];
        } elseif (isset($result['Success'])) {
            $success = (bool)$result['Success'];
        }

        $data = $result['data'] ?? ($result['Data'] ?? $result);
        $message = (string)($result['message'] ?? ($result['Message'] ?? ''));

        $this->DebugLog(__FUNCTION__ . ' Result', json_encode($result), 0);

        return [
            'Success' => $success,
            'Message' => $message,
            'Data' => $data
        ];
    }

    private function StoreCustomURLDevice(array $devicePayload): array
    {
        // IMPORTANT:
        // - Property "Token" in this module is used for webhook/auth in the local emulator context.
        // - Cantata Cloud endpoints require the RS90 session (RS90_AccessToken + RS90_Cookie) obtained via RS90_Login().

        $this->DebugLog(__FUNCTION__ . ' Payload', json_encode($devicePayload), 0);

        // Ensure we have a valid RS90 session
        $accessToken = trim($this->ReadAttributeString('RS90_AccessToken'));
        $cookie = trim($this->ReadAttributeString('RS90_Cookie'));

        if ($accessToken === '' || $cookie === '') {
            $this->DebugLog(__FUNCTION__, 'RS90 session missing -> calling RS90_Login()', 0);
            $loginResult = $this->RS90_Login();
            $this->DebugLog(__FUNCTION__, 'RS90_Login() result: ' . $loginResult, 0);
        }

        // First attempt
        $result = $this->CallCantataAPI('/app/integration/custom-urls/store', $devicePayload);

        // If token expired/invalid, try one re-login and retry once
        if (is_array($result) && isset($result['success']) && $result['success'] === false) {
            $msg = (string)($result['message'] ?? '');
            if ($msg !== '' && stripos($msg, 'token') !== false) {
                $this->DebugLog(__FUNCTION__, 'Cantata API returned token-related error -> re-login and retry', 0);
                $loginResult = $this->RS90_Login();
                $this->DebugLog(__FUNCTION__, 'RS90_Login() result (retry): ' . $loginResult, 0);
                $result = $this->CallCantataAPI('/app/integration/custom-urls/store', $devicePayload);
            }
        }

        if ($result === null) {
            return [
                'Success' => false,
                'Message' => 'CallCantataAPI returned null (network/auth error). Check RS90_Login debug and credentials.',
                'Data' => null
            ];
        }

        // Normalize response to our envelope
        $success = true;
        if (isset($result['success'])) {
            $success = (bool)$result['success'];
        } elseif (isset($result['Success'])) {
            $success = (bool)$result['Success'];
        }

        $data = $result['data'] ?? ($result['Data'] ?? $result);
        $message = (string)($result['message'] ?? ($result['Message'] ?? ''));

        $this->DebugLog(__FUNCTION__ . ' Result', json_encode($result), 0);

        return [
            'Success' => $success,
            'Message' => $message,
            'Data' => $data
        ];
    }

    public function RS90_GetDashboard(): array
    {
        $data = $this->CallCantataAPI('/app/dashboard');
        if ($data === null) {
            return ["error" => "Fehler beim Abruf des Dashboards"];
        }
        $payload = $data['data'] ?? null;
        if ($payload !== null) {
            $this->WriteAttributeString('RS90_Dashboard', json_encode($payload));
            if (isset($payload['connectedRemotes'])) {
                $this->WriteAttributeString('RS90_ConnectedRemotes', json_encode($payload['connectedRemotes']));
            }
        }
        return $data;
    }

    /**
     * Hilfsmethode für generische POST-Anfragen an die Cantata API.
     * @param string $endpoint Teil-URL nach BASE_URL (z. B. '/app/sequence/list')
     * @param array $postData Optionaler Payload (wird als JSON gesendet)
     * @return array|null Antwort als assoziatives Array oder null bei Fehler
     */
    private function CallCantataAPI(string $endpoint, array $postData = []): ?array
    {
        $token = trim($this->ReadAttributeString('RS90_AccessToken'));
        $cookie = trim($this->ReadAttributeString('RS90_Cookie'));

        if ($token === '' || $cookie === '') {
            $this->DebugLog(__FUNCTION__, 'Missing RS90 token/cookie before API call -> trying RS90_Login()', 0);
            $loginResult = $this->RS90_Login();
            if (strpos($loginResult, 'ERROR:') === 0) {
                $this->DebugLog(__FUNCTION__, 'API call aborted because login failed: ' . $loginResult, 0);
                return null;
            }
            $token = trim($this->ReadAttributeString('RS90_AccessToken'));
            $cookie = trim($this->ReadAttributeString('RS90_Cookie'));
        }

        $url = self::BASE_URL . $endpoint;
        $this->DebugLog(__FUNCTION__, 'Calling endpoint: ' . $endpoint . ' | payload: ' . json_encode($postData), 0);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: */*',
            'Authorization: Bearer ' . $token,
            'Cookie: connect.sid=' . $cookie,
            'User-Agent: Symcon RS90'
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $message = 'Cantata API cURL error for ' . $endpoint . ': ' . $error;
            $this->WriteAttributeBoolean('RS90_LoginFailed', true);
            $this->WriteAttributeString('RS90_LastError', $message);
            $this->DebugLog(__FUNCTION__, $message, 0);
            return null;
        }

        $this->DebugLog(__FUNCTION__, 'HTTP Code: ' . $httpCode . ' | Response length: ' . strlen($response), 0);
        $this->DebugLog(__FUNCTION__, 'Response: ' . $response, 0);

        $data = json_decode($response, true);
        if (!is_array($data)) {
            $message = 'Cantata API invalid JSON response for ' . $endpoint;
            $this->WriteAttributeBoolean('RS90_LoginFailed', true);
            $this->WriteAttributeString('RS90_LastError', $message);
            $this->DebugLog(__FUNCTION__, $message, 0);
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $apiMessage = (string)($data['message'] ?? 'Unexpected HTTP status');
            $message = 'Cantata API call failed for ' . $endpoint . ': HTTP ' . $httpCode . ' - ' . $apiMessage;
            $this->WriteAttributeBoolean('RS90_LoginFailed', true);
            $this->WriteAttributeString('RS90_LastError', $message);
            $this->DebugLog(__FUNCTION__, $message, 0);
            return $data;
        }

        $this->WriteAttributeBoolean('RS90_LoginFailed', false);
        $this->WriteAttributeString('RS90_LastError', '');
        return $data;
    }

    /**
     * Ruft die Liste der Sequenzen von der Cantata Cloud ab.
     * @return array|null Sequenzdaten oder null bei Fehler
     */
    public function RS90_GetSequences(): ?array
    {
        $data = $this->CallCantataAPI('/app/sequence/list', ['isRoom' => 'false']);
        return $data['data'] ?? null;
    }

    /**
     * Ruft die Details (Geräte) einer bestimmten Sequenz anhand ihrer ID ab.
     * @param string $sequenceId
     * @return array|null
     */
    public function RS90_GetSequenceById(string $sequenceId): ?array
    {
        $data = $this->CallCantataAPI('/app/sequence/device/list', ['sequenceId' => $sequenceId]);
        return $data['data'] ?? null;
    }


    /**
     * Ruft die Liste der Befehle eines Geräts innerhalb einer Sequenz ab.
     * @param string $sequenceId
     * @param string $deviceId
     * @return array|null
     */
    public function RS90_GetSequenceDeviceCommands(string $sequenceId, string $deviceId): ?array
    {
        $data = $this->CallCantataAPI('/app/sequence/device/details', [
            'id' => $deviceId,
            'sequenceId' => $sequenceId
        ]);
        return $data['data'] ?? null;
    }

    /**
     * Ruft die Liste der Räume aus der Cantata Cloud ab.
     * @return array|null Raumdaten oder null bei Fehler
     */
    public function RS90_GetRooms(): ?array
    {
        $data = $this->CallCantataAPI('/app/room/list');
        return $data['data'] ?? null;
    }

    /**
     * Ruft die Details eines bestimmten Raums anhand seiner ID ab.
     * @param string $roomId
     * @return array|null Raumdetails oder null bei Fehler
     */
    public function RS90_GetRoomDetails(string $roomId): ?array
    {
        $data = $this->CallCantataAPI('/app/room/details', ['id' => $roomId]);
        return $data['data'] ?? null;
    }

    public function GetConnectedRS90Remotes(): array
    {
        $json = $this->ReadAttributeString('RS90_ConnectedRemotes');
        return json_decode($json, true);
    }

    public function RefreshConfigurationData(): void
    {
        $this->DebugLog(__FUNCTION__, 'Manual refresh of RS90 configuration data started', 0);

        $sequences = $this->GetFormCompatibleSequences();
        $rooms = $this->RS90_GetRoomsFormatted();
        $devices = $this->RS90_GetDeviceDashboardFormatted();
        $customDevices = $this->GetCustomURLDeviceListFormatted();

        @$this->UpdateFormField('SequenceList', 'values', is_array($sequences) ? $sequences : []);
        @$this->UpdateFormField('RoomList', 'values', is_array($rooms) ? $rooms : []);
        @$this->UpdateFormField('DeviceDashboard', 'values', is_array($devices) ? $devices : []);
        @$this->UpdateFormField('custom_devices', 'values', is_array($customDevices) ? $customDevices : []);

        if ($this->ReadAttributeBoolean('RS90_LoginFailed')) {
            $warning = '⚠️ RS90 login failed. Please verify the user name and password.';
            $detail = trim($this->ReadAttributeString('RS90_LastError'));
            if ($detail !== '') {
                $warning .= ' Details: ' . $detail;
            }
            @$this->UpdateFormField('RS90LoginWarning', 'visible', true);
            @$this->UpdateFormField('RS90LoginWarning', 'caption', $warning);
            $this->DebugLog(__FUNCTION__, $warning, 0);
        } else {
            @$this->UpdateFormField('RS90LoginWarning', 'visible', false);
        }

        $this->DebugLog(__FUNCTION__, 'Manual refresh of RS90 configuration data finished', 0);
    }


    /**
     * Ruft die Liste der verfügbaren RS90-Remotes über die v2-API ab.
     * @return array|null Liste der Remotes oder null bei Fehler
     */
    public function RS90_GetRemoteList(): ?array
    {
        $data = $this->CallCantataAPI('/app/v2/remote/list');
        return $data['data'] ?? null;
    }

    /**
     * Ruft das Geräte-Dashboard aus der Cantata Cloud ab.
     * @return array|null Gerätestatusdaten oder null bei Fehler
     */
    public function RS90_GetDeviceDashboard(): ?array
    {
        $data = $this->CallCantataAPI('/app/device/dashboard');
        return $data['data'] ?? null;
    }

    /**
     * Ruft die Liste aller gepaarten Geräte aus der Cantata Cloud ab.
     * @return array|null Liste der Geräte oder null bei Fehler
     */
    public function RS90_GetDeviceList(): ?array
    {
        $payload = [
            'source' => '',
            'pairedStatus' => 'PAIRED',
            'isExcludeReadOnlyDevice' => false
        ];
        $data = $this->CallCantataAPI('/app/device/list', $payload);
        return $data['data'] ?? null;
    }

    /**
     * Ruft die Liste der gepaarten Custom-URL-Geräte ab.
     * @return array|null Liste der Custom-URL-Geräte oder null bei Fehler
     */
    public function RS90_GetCustomURLDevices(): ?array
    {
        $payload = [
            'source' => 'CUSTOM-URLS',
            'pairedStatus' => 'PAIRED',
            'isExcludeReadOnlyDevice' => false
        ];
        $data = $this->CallCantataAPI('/app/device/list', $payload);
        return $data['data'] ?? null;
    }

    /**
     * Ruft die Liste der Befehle eines Geräts ab.
     * @param string $deviceId
     * @return array|null
     */
    public function RS90_GetDeviceCommands(string $deviceId): ?array
    {
        $data = $this->CallCantataAPI('/app/device/details', [
            'id' => $deviceId,
            'isSequenceSupported' => false
        ]);
        return $data['data'] ?? null;
    }

    /**
     * Sendet einen HTTP-Befehl an das RS90-Gerät.
     * @param string $endpoint
     * @return string Die HTTP-Antwort oder Fehlertext
     */
    private function SendCommandToRS90(string $endpoint): string
    {
        $ip = $this->ReadPropertyString("RS90_IP");
        $port = $this->ReadPropertyInteger("RS90_Port");
        $url = "http://{$ip}:{$port}/{$endpoint}";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            $this->DebugLog("RS90 HTTP Error", $error, 0);
            return "ERROR: " . $error;
        }

        $this->DebugLog("RS90 HTTP Response Code", $httpCode, 0);
        $this->DebugLog("RS90 HTTP Response", $response, 0);
        return $response;
    }


    /**
     * Beispielmethode: RS90 RGB ausschalten
     */
    public function RGBTurnOff(): string
    {
        $result = $this->SendCommandToRS90("turnoff");
        $this->DebugLog("TurnOff", $result, 0);
        return $result;
    }

    // === Additional RS90 HTTP endpoint methods ===

    public function RGBTurnOn()
    {
        return $this->SendCommandToRS90('turnon');
    }

    public function GetCurrentRGB()
    {
        return $this->SendCommandToRS90('getCurrentRGB');
    }

    public function StartCycleSingleColor()
    {
        return $this->SendCommandToRS90('cycle?repeat=3&color=255&interval=100');
    }

    public function StartRotateSingleColorClockwise()
    {
        return $this->SendCommandToRS90('rotateClockwise?repeat=3&color=255&interval=100');
    }

    public function StartRotateSingleColorCounterClockwise()
    {
        return $this->SendCommandToRS90('rotateCounterClockwise?repeat=3&color=255&interval=100');
    }

    public function StartRotatingColors()
    {
        return $this->SendCommandToRS90('startRotatingColors?repeat=3&colors=255,0,0,0,0,0,0,0&interval=100');
    }

    public function StartMultiColorControl()
    {
        $this->SendCommandToRS90('multiColorControl?repeat=3&matrix=255,0,0,0,0,0,0,0|0,255,0,0,0,0,0,0&interval=100');
    }

    public function StartCyclingColors()
    {
        return $this->SendCommandToRS90('cyclingColors?repeat=3&colors=255,0,0,0,0,0,0,0&interval=100');
    }

    public function StartCycleColors()
    {
        return $this->SendCommandToRS90('cycleColors?repeat=3&c1=255&c2=0&c3=0&c4=0&c5=0&c6=0&c7=0&c8=0&interval=100');
    }

    public function StartSequenceTest()
    {
        return $this->SendCommandToRS90('sequenceTest?isSettingTest=true');
    }

    public function ControlKeyBacklightOn()
    {
        return $this->SendCommandToRS90('backlight?enabled=true');
    }

    public function ControlKeyBacklightOff()
    {
        return $this->SendCommandToRS90('backlight?enabled=false');
    }

    public function TestServerConnection()
    {
        return $this->SendCommandToRS90('ping');
    }

    public function Destroy(): void
    {
        // Debug-Information zur Überprüfung, dass Destroy aufgerufen wird
        $this->DebugLog('Destroy', 'Destroy-Methode wird aufgerufen', 0);

        // Webhook löschen, falls dieser existiert
        $this->UnregisterHook('cantata');

        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        //Only call this in READY state. On startup the WebHook instance might not be available yet
        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->RegisterHook('cantata');
            $this->RegisterMdnsService();
            $this->RefreshHomeAssistantConfiguratorDataCache();
            $this->RegisterHomeAssistantMediaPlayerVariableSubscriptions();
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        //Never delete this line!
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
            $this->DebugLog(__FUNCTION__, '✅ Kernel READY – send Initial-Events', 0);
            $this->RegisterHook('cantata');
            $this->RegisterMdnsService();
            $this->RefreshHomeAssistantConfiguratorDataCache();
            $this->RegisterHomeAssistantMediaPlayerVariableSubscriptions();
        }

        if ($Message == IPS_KERNELSTARTED) {
            $this->DebugLog(__FUNCTION__, "🔄 Kernel Started", 0);
        }

        if ($Message == IM_CHANGESTATUS && $Data[0] == IS_ACTIVE) {
            $this->DebugLog(__FUNCTION__, "🔄 Instanz aktiv", 0);
        }

        if ($Message == VM_UPDATE) {
            $this->HandleHomeAssistantTrackedVariableUpdate($SenderID);
        }
    }

    private function RegisterHomeAssistantMediaPlayerVariableSubscriptions(): void
    {
        $previousMap = $this->ReadJsonAttributeArray('HAMediaPlayerVariableMap');
        foreach (array_keys($previousMap) as $variableID) {
            $variableID = (int)$variableID;
            if ($variableID > 0) {
                $this->UnregisterMessage($variableID, VM_UPDATE);
            }
        }

        $map = [];
        $stateCache = $this->ReadJsonAttributeArray('HAStateCache');
        foreach ($this->HA_GetConfiguratorData('GetMediaPlayers') as $mediaPlayer) {
            if (!is_array($mediaPlayer)) {
                continue;
            }

            $mediaPlayerObjectID = $this->ResolveHomeAssistantMediaPlayerObjectID($mediaPlayer);
            if ($mediaPlayerObjectID <= 0) {
                continue;
            }

            $entityID = 'media_player.' . $mediaPlayerObjectID;
            foreach ($this->GetHomeAssistantMediaPlayerStateVariableIDs($mediaPlayer) as $variableID) {
                if (!isset($map[(string)$variableID])) {
                    $map[(string)$variableID] = [];
                }

                if (!in_array($entityID, $map[(string)$variableID], true)) {
                    $map[(string)$variableID][] = $entityID;
                }
            }

            $state = $this->GetHomeAssistantState($entityID);
            if (is_array($state)) {
                $stateCache[$entityID] = $state;
            }
        }

        foreach (array_keys($map) as $variableID) {
            $this->RegisterMessage((int)$variableID, VM_UPDATE);
        }

        $this->WriteJsonAttributeArray('HAMediaPlayerVariableMap', $map);
        $this->WriteJsonAttributeArray('HAStateCache', $stateCache);
        $this->HA_Debug(self::TOPIC_WS, '🔔 HA media player variable subscriptions registered', self::LV_INFO, [
            'variable_count' => count($map),
            'entity_ids' => array_values(array_unique(array_merge([], ...array_values($map ?: [[]])))),
            'variables' => array_keys($map)
        ]);
    }

    private function HandleHomeAssistantTrackedVariableUpdate(int $senderID): void
    {
        $map = $this->ReadJsonAttributeArray('HAMediaPlayerVariableMap');
        $entityIDs = $map[(string)$senderID] ?? [];
        if (!is_array($entityIDs) || $entityIDs === []) {
            return;
        }

        $stateCache = $this->ReadJsonAttributeArray('HAStateCache');
        $oldStates = [];
        foreach ($entityIDs as $entityID) {
            $entityID = (string)$entityID;
            $oldStates[$entityID] = is_array($stateCache[$entityID] ?? null) ? $stateCache[$entityID] : null;
        }

        $this->HA_Debug(self::TOPIC_WS, '📣 HA tracked media player variable updated', self::LV_INFO, [
            'variable_id' => $senderID,
            'entity_ids' => $entityIDs
        ]);

        $this->BroadcastHomeAssistantStateChangedEvents($entityIDs, $oldStates);

        foreach ($entityIDs as $entityID) {
            $entityID = (string)$entityID;
            $newState = $this->GetHomeAssistantState($entityID);
            if (is_array($newState)) {
                $stateCache[$entityID] = $newState;
            }
        }
        $this->WriteJsonAttributeArray('HAStateCache', $stateCache);
    }

    private function ResolveHomeAssistantMediaPlayerObjectID(array $mediaPlayer): int
    {
        $configuredID = (int)($mediaPlayer['InstanceID'] ?? 0);
        if ($this->IsHomeAssistantInstanceID($configuredID)) {
            return $configuredID;
        }

        $controlVariableID = (int)($mediaPlayer['ControlVariable'] ?? 0);
        $parentID = $this->GetVariableParentInstanceID($controlVariableID);
        if ($parentID > 0) {
            return $parentID;
        }

        $fallbackID = (int)($mediaPlayer['Mediaplayer_ID'] ?? 0);
        return $this->IsHomeAssistantInstanceID($fallbackID) ? $fallbackID : 0;
    }

    private function GetHomeAssistantMediaPlayerStateVariableIDs(array $mediaPlayer): array
    {
        $keys = [
            'PlaybackStateVariable',
            'VolumeVariable',
            'MuteVariable',
            'CoverVariable',
            'SourceVariable',
            'TitleVariable',
            'ArtistVariable',
            'PositionVariable',
            'ElapsedVariable',
            'DurationVariable',
            'ShuffleVariable',
            'RepeatVariable'
        ];

        if ((int)($mediaPlayer['PlaybackStateVariable'] ?? 0) <= 0) {
            $keys[] = 'ControlVariable';
        }

        $variableIDs = [];
        foreach ($keys as $key) {
            $variableID = (int)($mediaPlayer[$key] ?? 0);
            if ($variableID <= 0) {
                continue;
            }

            if (function_exists('IPS_VariableExists') && !@IPS_VariableExists($variableID)) {
                continue;
            }

            $variableIDs[] = $variableID;
        }

        return array_values(array_unique($variableIDs));
    }

    private function GetVariableParentInstanceID(int $variableID): int
    {
        if ($variableID <= 0 || !function_exists('IPS_VariableExists') || !@IPS_VariableExists($variableID)) {
            return 0;
        }

        if (!function_exists('IPS_GetParent')) {
            return 0;
        }

        $parentID = (int)@IPS_GetParent($variableID);
        for ($i = 0; $i < 20 && $parentID > 0; $i++) {
            if ($this->IsHomeAssistantInstanceID($parentID)) {
                return $parentID;
            }

            $nextParentID = (int)@IPS_GetParent($parentID);
            if ($nextParentID <= 0 || $nextParentID === $parentID) {
                break;
            }

            $parentID = $nextParentID;
        }

        return 0;
    }

    private function IsHomeAssistantInstanceID(int $objectID): bool
    {
        if ($objectID <= 0) {
            return false;
        }

        if (function_exists('IPS_InstanceExists')) {
            return @IPS_InstanceExists($objectID);
        }

        return $objectID > 0;
    }

    private function ReadJsonAttributeArray(string $attributeName): array
    {
        try {
            $raw = $this->ReadAttributeString($attributeName);
        } catch (\Throwable $e) {
            $raw = $this->GetBuffer($attributeName);
        }

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function WriteJsonAttributeArray(string $attributeName, array $value): void
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $json = is_string($encoded) ? $encoded : '[]';
        try {
            $this->WriteAttributeString($attributeName, $json);
        } catch (\Throwable $e) {
            $this->SetBuffer($attributeName, $json);
        }
    }

    private function SendToAPI($apiCommand, $value)
    {
        // ClientIP und ClientPort auslesen (die wurden vorher in der Instanz gesetzt)
        $clientIP = $this->ReadAttributeString('ClientIP');
        $clientPort = $this->ReadAttributeInteger('ClientPort');

        // Prüfen, ob die Client-Daten vorhanden sind
        if ($clientIP !== '' && $clientPort > 0) {

            // Daten, die an den API-Server gesendet werden sollen
            $dataToSend = [
                'command' => $apiCommand,
                'value' => $value
            ];

            // Debug-Nachricht
            $this->DebugLog('API Command', 'Sende API-Befehl: ' . $apiCommand . ' mit Wert: ' . $value . ' an ' . $clientIP . ':' . $clientPort, 0);

            // JSON-Daten an den Parent (Socket) senden
            /*
                $result = $this->SendDataToParent(json_encode([
                    'DataID'    => '{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}', // Das ist die Message Sink ID für den Socket
                    'Buffer'    => json_encode($dataToSend),
                    'ClientIP'  => $clientIP,
                    'ClientPort'=> $clientPort
                ]));

                // Ergebnis prüfen
                if ($result === false) {
                    $this->DebugLog('API Send Error', 'Fehler beim Senden des API-Befehls', 0);
                } else {
                    $this->DebugLog('API Command Sent', 'Befehl erfolgreich gesendet: ' . $apiCommand, 0);
                }
                */
        } else {
            $this->DebugLog('Socket Error', 'Kein gültiger Client IP/Port konfiguriert', 0);
        }
    }

    public function ForwardData($JSONString): string
    {
        $data = json_decode($JSONString, true);
        $this->DebugLog(__FUNCTION__, 'ForwardData raw: ' . $JSONString, 0);

        if (!is_array($data)) {
            $err = 'Invalid JSON in ForwardData: ' . json_last_error_msg();
            $this->DebugLog(__FUNCTION__, $err, 0);
            return json_encode(['Success' => false, 'Error' => $err]);
        }

        // IPS sendet typischerweise:
        // [
        //   'DataID' => '...',
        //   'Buffer' => '...',        // manchmal
        //   'Payload' => [ ... ]      // bei deiner bisherigen Struktur
        // ]
        $payload = $data['Payload'] ?? null;

        // Optional: Falls du später "Buffer" nutzen willst (manche Module packen Payload in Buffer als JSON)
        if ($payload === null && isset($data['Buffer'])) {
            $tmp = json_decode($data['Buffer'], true);
            if (is_array($tmp)) {
                $payload = $tmp;
            }
        }

        if (!is_array($payload)) {
            $this->DebugLog(__FUNCTION__, 'Payload fehlt/ungültig', 0);
            return json_encode(['Success' => false, 'Error' => 'Payload fehlt/ungültig']);
        }

        $this->DebugLog(__FUNCTION__, 'Payload: ' . json_encode($payload), 0);

        // ------------------------------------------------------------
        // 1) RPC-Kommandos vom Konfigurator
        // ------------------------------------------------------------
        if (isset($payload['Command'])) {
            $cmd = (string)$payload['Command'];

            switch ($cmd) {

                // ====== Dein Start: Custom URL Devices ======
                case 'GetCustomURLDevices':
                    $result = $this->RS90_GetCustomURLDevices(); // liefert ?array
                    if ($result === null) {
                        return json_encode(['Success' => false, 'Error' => 'RS90_GetCustomURLDevices returned null']);
                    }
                    return json_encode(['Success' => true, 'Data' => $result]);

                case 'GetToken':
                    $token = $this->ReadPropertyString('Token');

                    $this->DebugLog(__FUNCTION__, 'Returning Token: ' . $token, 0);

                    return json_encode([
                        'Token' => $token
                    ]);

                case 'StoreCustomURLDevice':
                    // erwartet Payload: ['Device' => {...}]
                    $device = $payload['Device'] ?? null;
                    if (!is_array($device)) {
                        return json_encode([
                            'Success' => false,
                            'Message' => 'Missing Device payload',
                            'Data' => null
                        ]);
                    }

                    $result = $this->StoreCustomURLDevice($device);
                    return json_encode($result);

                case 'RemoveCustomURLDevice':
                    $deviceId = (string)($payload['DeviceID'] ?? '');
                    $result = $this->RemoveCustomURLDevice($deviceId);
                    return json_encode($result);

                // (Optional, gleich sinnvoll für später)
                case 'GetDeviceCommands':
                    if (empty($payload['DeviceID'])) {
                        return json_encode(['Success' => false, 'Error' => 'DeviceID fehlt']);
                    }
                    $result = $this->RS90_GetDeviceCommands((string)$payload['DeviceID']);
                    if ($result === null) {
                        return json_encode(['Success' => false, 'Error' => 'RS90_GetDeviceCommands returned null']);
                    }
                    return json_encode(['Success' => true, 'Data' => $result]);

                default:
                    $this->DebugLog(__FUNCTION__, 'Unknown RPC Command: ' . $cmd, 0);
                    return json_encode(['Success' => false, 'Error' => 'Unknown RPC Command: ' . $cmd]);
            }
        }

        // ------------------------------------------------------------
        // 2) Deine bisherigen Action-Handler (bestehende Logik)
        // ------------------------------------------------------------
        if (isset($payload['Action'])) {
            switch ($payload['Action']) {

                case 'LoadAVDevices':
                    $result = $this->LoadAVDevices();
                    return json_encode(['Success' => true, 'Data' => $result]);

                case 'SetPreviousTree':
                    if (!isset($payload['TreeData'])) {
                        return json_encode(['Success' => false, 'Error' => 'TreeData fehlt']);
                    }
                    $this->WriteAttributeString("previousTree", (string)$payload['TreeData']);
                    $this->DebugLog(__FUNCTION__, 'Vorheriger Tree gespeichert', 0);
                    return json_encode(['Success' => true]);

                default:
                    $this->DebugLog(__FUNCTION__, 'Unknown Action: ' . $payload['Action'], 0);
                    return json_encode(['Success' => false, 'Error' => 'Unknown Action: ' . $payload['Action']]);
            }
        }

        // ------------------------------------------------------------
        // 3) Dein bisheriges "Command/Value -> SendToAPI" (IO Richtung)
        // ------------------------------------------------------------
        if (isset($payload['Command']) && isset($payload['Value'])) {
            $this->SendToAPI((string)$payload['Command'], $payload['Value']);
            return json_encode(['Success' => true]);
        }

        return json_encode(['Success' => false, 'Error' => 'Kein gültiger Action oder Command angegeben']);
    }

    public function ReceiveData($JSONString): string
    {
        $this->DebugExtended(__FUNCTION__, '📥 Raw Data: ' . $JSONString, 0);

        $data = json_decode($JSONString, true);
        if (!is_array($data)) {
            $this->DebugExtended(__FUNCTION__, '❌ JSON-Parsing fehlgeschlagen: ' . json_last_error_msg(), 0);
            $this->DebugExtended(__FUNCTION__, '📥 Ursprünglicher JSON-String: ' . $JSONString, 0);
            return '';
        }
        $this->DebugExtended(__FUNCTION__, '✅ JSON erfolgreich dekodiert', 0);

        $payloadInfo = $this->DecodeHexSocketPayload((string)($data['Buffer'] ?? ''));
        $payload = $payloadInfo['payload'];

        // Extract and typecast Type from incoming JSON data
        $type = intval($data['Type'] ?? -1);
        switch ($type) {
            case self::Socket_Data: // Data
                $this->DebugExtended(__FUNCTION__, "🟢 Socket Type: Data", 0);
                break;
            case self::Socket_Connected: // Connected
                $this->DebugExtended(__FUNCTION__, "🟢 Socket Type: Connected", 0);
                break;
            case self::Socket_Disconnected: // Disconnected
                $this->DebugExtended(__FUNCTION__, "🟠 Socket Type: Disconnected", 0);
                if (isset($data['ClientIP'], $data['ClientPort'])) {
                    $this->RemoveHomeAssistantWebSocketSubscription((string)$data['ClientIP'], (int)$data['ClientPort']);
                }
                break;
            default:
                $this->DebugExtended(__FUNCTION__, "⚠️ Socket Type: Unbekannt ($type)", 0);
                break;
        }

        $clientIP = $data['ClientIP'];
        $clientPort = intval($data['ClientPort']);

        $this->WriteAttributeString('ClientIP', $clientIP);
        $this->WriteAttributeInteger('ClientPort', $clientPort);

        $this->HA_Debug(self::TOPIC_IO, '📥 Socket data received', self::LV_INFO, [
            'client' => $clientIP . ':' . $clientPort,
            'type' => $type,
            'encoding' => $payloadInfo['encoding'],
            'raw_length' => $payloadInfo['raw_length'],
            'payload_length' => strlen($payload),
            'payload_hex_preview' => bin2hex(substr($payload, 0, 120)),
            'payload_preview' => mb_substr($payload, 0, 1000)
        ]);

        if ($payload === '') {
            return '';
        }

        if ($this->IsHttpPayload($payload)) {
            $this->handleRequest($payload, $clientIP, $clientPort);
        } elseif ($this->IsWebSocketFrame($payload)) {
            $this->HandleHomeAssistantWebSocketFrame($payload, $clientIP, $clientPort);
        } else {
            $this->HA_Debug(self::TOPIC_IO, '⚠️ Socket payload is neither HTTP nor WebSocket', self::LV_WARN, [
                'client' => $clientIP . ':' . $clientPort,
                'payload_length' => strlen($payload),
                'payload_hex_preview' => bin2hex(substr($payload, 0, 120)),
                'payload_preview' => mb_substr($payload, 0, 1000)
            ]);
            $this->SendDataToChildren(json_encode([
                'DataID' => '{1025873A-EDF7-BF8E-0337-7C6409CAA9F4}',
                'Buffer' => $payload,
                'ClientIP' => $clientIP,
                'ClientPort' => $clientPort
            ]));
        }
        return '';
    }

    private function DecodeHexSocketPayload(string $hexPayload): array
    {
        $trimmed = trim($hexPayload);

        if ($trimmed === '') {
            return [
                'payload' => '',
                'encoding' => 'empty',
                'raw_length' => strlen($hexPayload)
            ];
        }

        if (strlen($trimmed) % 2 !== 0 || preg_match('/^[0-9A-Fa-f]+$/', $trimmed) !== 1) {
            $this->HA_Debug(self::TOPIC_IO, '⚠️ Socket buffer is not valid hex', self::LV_WARN, [
                'raw_length' => strlen($hexPayload),
                'raw_preview' => mb_substr($hexPayload, 0, 1000)
            ]);

            return [
                'payload' => '',
                'encoding' => 'invalid-hex',
                'raw_length' => strlen($hexPayload)
            ];
        }

        $decoded = @hex2bin($trimmed);
        if (!is_string($decoded)) {
            $this->HA_Debug(self::TOPIC_IO, '⚠️ hex2bin failed for socket buffer', self::LV_WARN, [
                'raw_length' => strlen($hexPayload),
                'raw_preview' => mb_substr($hexPayload, 0, 1000)
            ]);

            return [
                'payload' => '',
                'encoding' => 'hex-decode-failed',
                'raw_length' => strlen($hexPayload)
            ];
        }

        return [
            'payload' => $decoded,
            'encoding' => 'hex',
            'raw_length' => strlen($hexPayload)
        ];
    }

    private function IsHttpPayload(string $payload): bool
    {
        return preg_match('/^(GET|POST|PUT|PATCH|DELETE|OPTIONS|HEAD)\\s+/i', $payload) === 1;
    }

    private function IsWebSocketFrame(string $payload): bool
    {
        if (strlen($payload) < 2) {
            return false;
        }

        $opcode = ord($payload[0]) & 0x0F;
        return in_array($opcode, [
            \WebSocketUtils::OPCODE_CONTINUATION,
            \WebSocketUtils::OPCODE_TEXT,
            \WebSocketUtils::OPCODE_BINARY,
            \WebSocketUtils::OPCODE_CLOSE,
            \WebSocketUtils::OPCODE_PING,
            \WebSocketUtils::OPCODE_PONG
        ], true);
    }

    private function DebugLog(string $message, $data, int $format = 0, int $level = self::LV_INFO, string $topic = self::TOPIC_GEN): void
    {
        $this->Debug($message, $level, $topic, $data, $format);
    }

    private function DebugExtended(string $message, $data, int $format = 0, int $level = self::LV_TRACE, string $topic = self::TOPIC_EXT): void
    {
        if ($this->ReadPropertyBoolean('extended_debug')) {
            $this->Debug($message, $level, $topic, $data, $format);
        }
    }

    public function HA_GetConfiguratorData(string $method): array
    {
        $cache = $this->ReadJsonAttributeArray('HAConfiguratorDataCache');
        if (array_key_exists($method, $cache) && is_array($cache[$method])) {
            return $cache[$method];
        }

        $this->RefreshHomeAssistantConfiguratorDataCache();
        $cache = $this->ReadJsonAttributeArray('HAConfiguratorDataCache');
        if (array_key_exists($method, $cache) && is_array($cache[$method])) {
            return $cache[$method];
        }

        return [];
    }

    private function RefreshHomeAssistantConfiguratorDataCache(): void
    {
        $methods = [
            'GetAutomations',
            'GetSwitches',
            'GetLights',
            'GetTemperatureSensors',
            'GetBatterySensors',
            'GetHumiditySensors',
            'GetMotionSensors',
            'GetIlluminanceSensors',
            'GetMediaPlayers'
        ];

        $cache = [];
        foreach ($methods as $method) {
            $cache[$method] = $this->LoadHomeAssistantConfiguratorData($method);
        }

        $this->WriteJsonAttributeArray('HAConfiguratorDataCache', $cache);
        $this->HA_Debug(self::TOPIC_ENTITY, '📦 HA configurator data cache refreshed', self::LV_INFO, [
            'methods' => array_keys($cache),
            'counts' => array_map(static fn($items): int => is_array($items) ? count($items) : 0, $cache)
        ]);
    }

    private function LoadHomeAssistantConfiguratorData(string $method): array
    {
        $data = $this->GetDataFromConfigurator($method);
        if ($data !== []) {
            $this->HA_Debug(self::TOPIC_ENTITY, '📦 Configurator data from child response', self::LV_INFO, [
                'method' => $method,
                'count' => count($data)
            ]);
            return $data;
        }

        $fallback = $this->GetImportConfiguratorData($method);
        $this->HA_Debug(self::TOPIC_ENTITY, '📦 Configurator data from import configurator fallback', self::LV_INFO, [
            'method' => $method,
            'count' => count($fallback)
        ]);
        return $fallback;
    }

    public function HA_Debug(string $topic, string $message, int $level = self::LV_INFO, $data = ''): void
    {
        $topic = strtoupper(trim($topic));
        if ($topic === '') {
            $topic = self::TOPIC_HA;
        }

        if (!is_string($data)) {
            $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $data = $encoded === false ? '[unserializable]' : $encoded;
        }

        $this->Debug($message, $level, $topic, $data, 0);
    }

    private function HomeAssistantEmulator(): HomeAssistantEmulator
    {
        if ($this->homeAssistantEmulator === null) {
            $this->homeAssistantEmulator = new HomeAssistantEmulator($this);
        }

        return $this->homeAssistantEmulator;
    }

    private function MdnsDiscovery(): HaptiqueMdnsDiscovery
    {
        if ($this->mdnsDiscovery === null) {
            $this->mdnsDiscovery = new HaptiqueMdnsDiscovery($this);
        }

        return $this->mdnsDiscovery;
    }

    public function ProcessHookData(): void
    {
        $this->DebugLog(__FUNCTION__, 'Hook wurde aufgerufen', 0);
        // Methode und Anfrage-Art auslesen
        $method = $_SERVER['REQUEST_METHOD'];
        $this->DebugLog('ProcessHookData', 'Empfangene Methode: ' . $method, 0);

        // Prüfe den Token: Erst im Header, dann als URL-Parameter
        $token = '';

        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
            if (strpos($authHeader, 'Bearer ') === 0) {
                $token = substr($authHeader, 7); // Entfernt 'Bearer '
            }
        }

        if (empty($token) && isset($_GET['token'])) {
            $token = $_GET['token'];  // Falls kein Header-Token, Token aus der URL nehmen
        }

        $this->DebugLog(__FUNCTION__, "Empfangener Token: $token", 0);

        // Vergleiche den Token mit dem gespeicherten
        if ($token !== $this->ReadPropertyString('Token')) {
            $this->DebugLog(__FUNCTION__, 'Ungültiger Token: ' . $token, 0);
            header('HTTP/1.1 401 Unauthorized');
            echo json_encode(['error' => 'Unauthorized: Invalid Token']);
            return;
        }

        // Verarbeite POST oder GET-Anfragen
        if ($method === 'POST') {
            $data = file_get_contents('php://input');
            $json = json_decode($data, true);
            // Debug-Ausgabe nach JSON-Decode
            $this->DebugLog('Cantata JSON', print_r($json, true), 0);
            $this->DebugLog('Cantata Webhook (POST)', json_encode($json), 0);

            // Prüfen der übermittelten Parameter (action, device_id, state)
            if (isset($json['action'])) {
                $this->DebugLog('Cantata Webhook', 'Action: ' . $json['action'], 0);

                $action = $json['action'];
                // Batteriestatus-Bedingung
                if ($action === 'status_update' && isset($json['battery'])) {
                    $battery = (int)$json['battery'];
                    $status = [
                        'DeviceID' => $json['device_id'] ?? '',
                        'Battery' => $battery
                    ];
                    $this->DebugLog('Received Battery', json_encode($status), 0);
                    $this->SendDataToChildren(json_encode([
                        'DataID' => '{1025873A-EDF7-BF8E-0337-7C6409CAA9F4}',
                        'Buffer' => $status
                    ]));
                    return;
                }
                // Tastendruck-Bedingung
                if ($action === 'keypress') {
                    $this->DebugLog('Keypress', json_encode($json), 0);
                    $this->SendDataToChildren(json_encode([
                        'DataID' => '{1025873A-EDF7-BF8E-0337-7C6409CAA9F4}', // ID der Child-Instanz
                        'Buffer' => [
                            'DeviceID' => $json['device_id'],
                            'KeyNumber' => $json['keynumber'],
                            'KeyName' => $json['keyname']
                        ]
                    ]));
                    return;
                }
            } else {
                $this->DebugLog('Cantata Webhook', 'Keine Action übermittelt', 0);
            }

            if (isset($json['device_id'])) {
                $this->DebugLog('Cantata Webhook', 'Device ID: ' . $json['device_id'], 0);
            } else {
                $this->DebugLog('Cantata Webhook', 'Keine Device ID übermittelt', 0);
            }

            if (isset($json['state'])) {
                $this->DebugLog('Cantata Webhook', 'State: ' . $json['state'], 0);
            } else {
                $this->DebugLog('Cantata Webhook', 'Kein State übermittelt', 0);
            }

            if (isset($json['scene_id'], $json['button'])) {
                $sceneID = intval($json['scene_id']);
                $button = $json['button'];

                $this->DebugLog(__FUNCTION__, "Scene-Befehl empfangen: SceneID=$sceneID, Button=$button", 0);

                // Daten an die Child-Instanzen weiterleiten
                $this->SendDataToChildren(json_encode([
                    'DataID' => '{1025873A-EDF7-BF8E-0337-7C6409CAA9F4}', // Child-Instanz-GUID
                    'SceneID' => $sceneID,
                    'Button' => $button,
                ]));

                header('Content-Type: application/json');
                echo json_encode(['message' => 'Scene command processed']);
                return;
            } else {
                $this->DebugLog(__FUNCTION__, 'Ungültige POST-Daten', 0);
                header('HTTP/1.1 400 Bad Request');
                echo json_encode(['error' => 'Bad Request']);
            }
        } elseif ($method === 'GET') {
            $this->DebugLog('Cantata Webhook (GET)', 'HTML-Ausgabe für Cantata', 0);

            // Alle GET-Parameter auswerten und debuggen
            foreach ($_GET as $key => $value) {
                $this->DebugLog('Cantata Webhook (GET)', "Parameter: $key = $value", 0);
            }

            // IGNORE-Befehl abfangen
            if (isset($_GET['command']) && $_GET['command'] === 'ignore') {
                $this->DebugLog(__FUNCTION__, 'IGNORE-Befehl empfangen. Keine Verarbeitung erforderlich.', 0);

                // Antwort zurückgeben
                header('Content-Type: application/json');
                echo json_encode(['message' => 'IGNORE command processed successfully']);
                return;
            }

            // Szenen-Parameter prüfen
            if (isset($_GET['scene_id'], $_GET['button'])) {
                $sceneID = intval($_GET['scene_id']);
                $button = $_GET['button'];

                if ($sceneID > 0 && !empty($button)) {
                    $this->DebugLog(__FUNCTION__, "Szenen-Befehl erkannt: SceneID=$sceneID, Button=$button", 0);

                    // Daten an die Child-Instanzen weiterleiten
                    $this->SendDataToChildren(json_encode([
                        'DataID' => '{1025873A-EDF7-BF8E-0337-7C6409CAA9F4}', // Child-Instanz-GUID
                        'SceneID' => $sceneID,
                        'Button' => $button,
                    ]));

                    header('Content-Type: application/json');
                    echo json_encode(['message' => 'Scene command processed']);
                    return;
                } else {
                    $this->DebugLog(__FUNCTION__, "Ungültige Szenen-Parameter: SceneID=$sceneID, Button=$button", 0);
                    header('HTTP/1.1 400 Bad Request');
                    echo json_encode(['error' => 'Invalid scene parameters']);
                    return;
                }
            }

            // Command-Parameter prüfen
            if (isset($_GET['command'], $_GET['instance_id'], $_GET['device_category'])) {
                $command = $_GET['command'];
                $instanceId = intval($_GET['instance_id']);
                $deviceCategory = $_GET['device_category'];

                $this->DebugLog(__FUNCTION__, "Command erkannt: Command=$command, InstanceID=$instanceId, DeviceCategory=$deviceCategory", 0);

                // Command ausführen
                $response = $this->ExecuteCommandByInstance($instanceId, $command, $deviceCategory);

                // Rückgabe der Antwort
                header('Content-Type: application/json');
                echo json_encode($response);
                return;
            }

            // Ungültige Parameter
            $this->DebugLog(__FUNCTION__, 'Ungültige Parameter', 0);
            header('HTTP/1.1 400 Bad Request');
            echo json_encode(['error' => 'Invalid parameters']);
        }
    }

    private function ExecuteCommandByInstance($instanceId, $command, $deviceCategory)
    {
        $this->DebugLog(__FUNCTION__, "Aufruf mit InstanceID=$instanceId, Command=$command, DeviceCategory=$deviceCategory", 0);

        if ($deviceCategory === 'script') {
            // Prüfe, ob die Instanz-ID existiert und ein Skript ist
            if (IPS_ObjectExists($instanceId) && IPS_GetObject($instanceId)['ObjectType'] == 3) { // 3 steht für Skript
                $this->DebugLog(__FUNCTION__, "Skript erkannt. Ausführung starten für Objekt-ID: $instanceId", 0);
                try {
                    // Skript ausführen
                    IPS_RunScript($instanceId);
                    $this->DebugLog(__FUNCTION__, "Skript erfolgreich ausgeführt: Objekt-ID $instanceId", 0);
                    return ['message' => "Skript erfolgreich ausgeführt: Objekt-ID $instanceId"];
                } catch (Exception $e) {
                    $this->DebugLog(__FUNCTION__, "Fehler beim Ausführen des Skripts: " . $e->getMessage(), 0);
                    return ['error' => "Fehler beim Ausführen des Skripts: " . $e->getMessage()];
                }
            } else {
                $this->DebugLog(__FUNCTION__, "Ungültige Objekt-ID oder Objekt ist kein Skript: $instanceId", 0);
                return ['error' => "Ungültige Objekt-ID oder Objekt ist kein Skript: $instanceId"];
            }
        }

        // === Kincony Device Support ===
        if ($deviceCategory === 'kinconydevice') {
            if (!IPS_ObjectExists($instanceId)) {
                $this->DebugLog(__FUNCTION__, "Kincony: Ungültige InstanceID $instanceId", 0);
                return ['error' => "Kincony InstanceID '$instanceId' nicht gefunden"];
            }

            try {
                $this->DebugLog(__FUNCTION__, "Kincony: Sende Befehl '$command' an InstanceID $instanceId", 0);

                // Aufruf des Kincony-Moduls
                if (function_exists('CRSXKD_SendCommandByName')) {
                    CRSXKD_SendCommandByName($instanceId, $command);
                } else {
                    $this->DebugLog(__FUNCTION__, "Kincony-Funktion CRSXKD_SendCommandByName nicht gefunden", 0);
                    return ['error' => "CRSXKD_SendCommandByName nicht verfügbar"];
                }

                return ['message' => "Kincony-Befehl '$command' erfolgreich für Instanz '$instanceId' ausgeführt"];
            } catch (Exception $e) {
                $this->DebugLog(__FUNCTION__, "Fehler bei Kincony-Befehl: " . $e->getMessage(), 0);
                return ['error' => "Fehler bei Kincony-Befehl: " . $e->getMessage()];
            }
        }

        // Fallback für andere Kategorien (bestehender Code bleibt erhalten)
        // Prüfe, ob die Instanz-ID existiert
        $availableDevices = $this->GetAV_Devices(); // Ruft die gespeicherten AV-Geräte ab
        $targetDevice = null;

        // Finde das Zielgerät anhand der Instanz-ID
        foreach ($availableDevices as $device) {
            if ($device['InstanceID'] == $instanceId) {
                $targetDevice = $device;
                break;
            }
        }

        if ($targetDevice === null) {
            $this->DebugLog('ExecuteCommandByInstance', "Unbekannte Instanz-ID: $instanceId", 0);
            return ['error' => "Instanz-ID '$instanceId' nicht gefunden"];
        }

        // Gerätetyp überprüfen und Befehlsausführung starten
        if ($deviceCategory === 'avdevice' && $targetDevice['Gateway'] === 'Logitech Harmony Hub') {
            if ($command === 'PlayPauseToggle') {
                // Letzten bekannten Zustand abrufen
                $lastState = $this->ReadAttributeString('LastHarmonyPlayState') ?? 'paused'; // Standardwert

                // Nächsten Zustand bestimmen
                $nextCommand = ($lastState === 'playing') ? 'pause' : 'play';

                // Zustand speichern
                $this->WriteAttributeString('LastHarmonyState', ($nextCommand === 'play') ? 'playing' : 'paused');

                // Befehl ausführen
                $harmonyCommands = $this->GetHarmonyDeviceCommands($instanceId);
                $matchedCommand = null;

                foreach ($harmonyCommands as $harmonyCommand) {
                    if (strcasecmp($harmonyCommand['label'], $nextCommand) === 0) {
                        $matchedCommand = $harmonyCommand['command'];
                        break;
                    }
                }

                if ($matchedCommand !== null) {
                    $this->DebugLog('ExecuteCommandByInstance', "Harmony-Befehl ausführen: $matchedCommand für Instanz-ID: $instanceId", 0);
                    LHD_Send($instanceId, $matchedCommand); // Harmony-Befehl senden
                    return ['message' => "Befehl '$nextCommand' erfolgreich für Logitech Harmony Instanz '$instanceId' ausgeführt"];
                } else {
                    return ['error' => "Unbekannter Harmony-Befehl '$nextCommand'"];
                }
            } else {
                // Ruft die Harmony-Befehle für die spezifische Instanz ab
                $harmonyCommands = $this->GetHarmonyDeviceCommands($instanceId);

                // Sucht nach dem übergebenen Befehl im Harmony-Befehlssatz
                $matchedCommand = null;
                foreach ($harmonyCommands as $harmonyCommand) {
                    if (strcasecmp($harmonyCommand['label'], $command) === 0) {
                        $matchedCommand = $harmonyCommand['command'];
                        $this->DebugLog('Harmony Command found', $matchedCommand, 0);
                        break;
                    }
                }

                if ($matchedCommand !== null) {
                    // Führt den Harmony-Befehl aus
                    $this->DebugLog('ExecuteCommandByInstance', "Harmony-Befehl ausführen: $matchedCommand für Instanz-ID: $instanceId", 0);
                    LHD_Send($instanceId, $matchedCommand); // Logitech-Befehl senden
                    return ['message' => "Befehl '$command' erfolgreich für Logitech Harmony Instanz '$instanceId' ausgeführt"];
                } else {
                    $this->DebugLog('ExecuteCommandByInstance', "Unbekannter Harmony-Befehl: $command für Instanz-ID: $instanceId", 0);
                    return ['error' => "Unbekannter Befehl '$command' für Logitech Harmony Instanz '$instanceId'"];
                }
            }
        } elseif ($deviceCategory === 'avdevice') {
            // Andere AV-Gerätetypen (nicht Logitech Harmony) hier behandeln
            // Beispiel: Denon-AVR oder andere Geräte, für die spezifische IP-Symcon-Kommandos vorhanden sind
            switch ($targetDevice['DeviceType']) {
                case 'Denon AVR':
                    // Beispielhafte Umsetzung für Denon AVR
                    if ($command === 'MuteToggle') {
                        // Aktuellen Mute-Zustand abfragen
                        $isMuted = $this->GetDenonMuteState($instanceId);

                        // Nächsten Zustand bestimmen
                        $nextMuteState = !$isMuted;

                        // Mute-Befehl senden
                        $this->MainMute($instanceId, $nextMuteState);

                        return ['message' => "Befehl 'MuteToggle' erfolgreich für Denon AVR Instanz '$instanceId' ausgeführt"];
                    }
                    if ($command === 'volumeup') {
                        $this->MasterVolume($instanceId, "UP");
                        return ['message' => "Befehl 'volumeup' für Denon AVR Instanz '$instanceId' ausgeführt"];
                    } elseif ($command === 'volumedown') {
                        $this->MasterVolume($instanceId, "DOWN");
                        return ['message' => "Befehl 'volumedown' für Denon AVR Instanz '$instanceId' ausgeführt"];
                    } else {
                        return ['error' => "Unbekannter Befehl '$command' für Denon AVR Instanz '$instanceId'"];
                    }
                    break;

                case 'Sonos':
                    // Beispielhafte Umsetzung für Sonos
                    if ($command === 'play') {
                        SonosPlay($instanceId);
                        return ['message' => "Befehl 'play' für Sonos Instanz '$instanceId' ausgeführt"];
                    } elseif ($command === 'pause') {
                        SonosPause($instanceId);
                        return ['message' => "Befehl 'pause' für Sonos Instanz '$instanceId' ausgeführt"];
                    } else {
                        return ['error' => "Unbekannter Befehl '$command' für Sonos Instanz '$instanceId'"];
                    }
                    break;

                // Weitere Gerätetypen hier hinzufügen
                default:
                    return ['error' => "Gerätetyp '$targetDevice[DeviceType]' wird noch nicht unterstützt"];
            }
        } else {
            // Fallback für unbekannte Gerätetypen
            return ['error' => "Gerätetyp '$deviceCategory' wird nicht unterstützt"];
        }
    }

    public function GenerateToken()
    {
        // Token generieren (32-stelliger Hex-String)
        $token = bin2hex(random_bytes(16));

        // Den Token in das Formularfeld 'Token' dynamisch eintragen
        $this->UpdateFormField('Token', 'value', $token);

        // Token zurückgeben, falls noch eine weitere Verwendung nötig ist
        return $token;
    }

    private function processReceivedData($data, string $clientIP, int $clientPort)
    {
        $requestLine = strtok((string)$data, "\r\n") ?: '';
        $this->HA_Debug(self::TOPIC_HA, '🔎 Processing HA HTTP request', self::LV_INFO, [
            'request_line' => $requestLine,
            'length' => strlen((string)$data)
        ]);

        if (preg_match('#GET /api/websocket HTTP/1.1.*Sec-WebSocket-Key: ([^\r\n]+)#is', $data, $matches)) {
            $secWebSocketKey = trim($matches[1]);
            $this->HA_Debug(self::TOPIC_WS, '🔌 WebSocket upgrade request', self::LV_INFO, [
                'sec_websocket_key_length' => strlen($secWebSocketKey)
            ]);
            $this->sendWebSocketHandshakeResponse($secWebSocketKey, $clientIP, $clientPort);
            $this->SendHomeAssistantWebSocketJson([
                'type' => 'auth_required',
                'ha_version' => '2024.1.0'
            ], $clientIP, $clientPort, 'HA WS auth_required');
            return;
        }

        // Extrahiere den Header-Teil der Anfrage (Buffer)
        $requestLines = explode("\r\n", utf8_decode($data));
        $token = '';

        // Suche nach dem Authorization Header
        foreach ($requestLines as $line) {
            if (strpos($line, 'Authorization: Bearer ') === 0) {
                // Token extrahieren
                $token = substr($line, 22); // Entfernt 'Authorization: Bearer '
                $this->HA_Debug(self::TOPIC_AUTH, '🔑 Bearer token found', self::LV_INFO, [
                    'token_length' => strlen(trim($token))
                ]);

                // Vergleiche den Token mit dem gespeicherten
                $storedToken = $this->ReadPropertyString('Token');
                if (trim($token) !== trim($storedToken)) {
                    $this->HA_Debug(self::TOPIC_AUTH, '❌ Invalid bearer token', self::LV_WARN, [
                        'received_token_length' => strlen(trim($token)),
                        'stored_token_length' => strlen(trim($storedToken))
                    ]);
                    $this->sendHttpResponseObject(HaptiqueHttpResponse::error(401, 'Unauthorized'), $clientIP, $clientPort);
                    return;  // Ungültiger Token -> Verarbeitung abbrechen
                }
            }
        }

        // Die Haptique App fragt beim Pairing nach einem Token, sendet bei GET /api/states
        // in den bisherigen Tests aber keinen Bearer-Header. Wir lassen fehlende Bearer-
        // Tokens fuer die HA-Emulation daher durch und loggen es sichtbar.
        if (empty($token)) {
            $this->HA_Debug(self::TOPIC_AUTH, '⚠️ No bearer token found, continuing HA emulation request', self::LV_WARN, [
                'request_line' => $requestLine
            ]);
        }

        // Home Assistant Emulation oder IP-Symcon Antwort
        $emulation = $this->ReadPropertyBoolean('EmulateHomeAssistant');
        $this->HA_Debug(self::TOPIC_HA, '🏠 Home Assistant emulation mode', self::LV_INFO, [
            'enabled' => $emulation
        ]);
        if ($emulation) {
            // Antworte im Home Assistant Stil
            $this->sendHomeAssistantResponse($data, $clientIP, $clientPort);
        } else {
            // Antworte im IP-Symcon Stil
            $this->sendIPSymconResponse($data);
        }
    }

    private function buildWebSocketAcceptKey(string $key): string
    {
        $GUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"; // (RFC 6455).
        return base64_encode(sha1($key . $GUID, true));
    }

    private function sendWebSocketHandshakeResponse(string $secWebSocketKey, string $clientIP, int $clientPort): void
    {
        $acceptKey = $this->buildWebSocketAcceptKey($secWebSocketKey);
        $response = "HTTP/1.1 101 Switching Protocols\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "Sec-WebSocket-Accept: $acceptKey\r\n\r\n";

        $this->DebugLog(__FUNCTION__, 'Sende WebSocket Handshake-Antwort', 0);


        $this->SendToServerSocket($response, $clientIP, $clientPort, 'WS handshake');
    }

    private function HandleHomeAssistantWebSocketFrame(string $payload, string $clientIP, int $clientPort): void
    {
        $frame = \WebSocketUtils::UnpackData($payload);
        if ($frame === null) {
            $this->HA_Debug(self::TOPIC_WS, '⚠️ Invalid WebSocket frame', self::LV_WARN, [
                'client' => $clientIP . ':' . $clientPort,
                'payload_length' => strlen($payload),
                'payload_hex_preview' => bin2hex(substr($payload, 0, 80))
            ]);
            return;
        }

        $this->HA_Debug(self::TOPIC_WS, '📥 HA WebSocket frame received', self::LV_INFO, [
            'client' => $clientIP . ':' . $clientPort,
            'opcode' => $frame['opcode_name'],
            'fin' => $frame['fin'],
            'masked' => $frame['masked'],
            'payload_length' => $frame['payloadLen'],
            'raw_length' => strlen($payload),
            'raw_hex_preview' => bin2hex(substr($payload, 0, 120)),
            'payload_preview' => mb_substr((string)$frame['payload'], 0, 1000)
        ]);

        if ($frame['opcode'] === \WebSocketUtils::OPCODE_PING) {
            $this->SendToServerSocket(\WebSocketUtils::PackPong(), $clientIP, $clientPort, 'HA WS pong');
            return;
        }

        if ($frame['opcode'] === \WebSocketUtils::OPCODE_CLOSE) {
            $this->HA_Debug(self::TOPIC_WS, '🔌 HA WebSocket close received', self::LV_INFO, [
                'client' => $clientIP . ':' . $clientPort
            ]);
            $this->RemoveHomeAssistantWebSocketSubscription($clientIP, $clientPort);
            return;
        }

        if ($frame['opcode'] !== \WebSocketUtils::OPCODE_TEXT) {
            $this->HA_Debug(self::TOPIC_WS, '⚠️ Unsupported HA WebSocket opcode', self::LV_WARN, [
                'client' => $clientIP . ':' . $clientPort,
                'opcode' => $frame['opcode_name']
            ]);
            return;
        }

        $message = json_decode((string)$frame['payload'], true);
        if (!is_array($message)) {
            $this->HA_Debug(self::TOPIC_WS, '⚠️ HA WebSocket JSON decode failed', self::LV_WARN, [
                'client' => $clientIP . ':' . $clientPort,
                'error' => json_last_error_msg(),
                'payload_preview' => mb_substr((string)$frame['payload'], 0, 1000)
            ]);
            return;
        }

        $debugMessage = $message;
        if (isset($debugMessage['access_token'])) {
            $debugMessage['access_token'] = '[redacted, length=' . strlen((string)$message['access_token']) . ']';
        }
        $this->HA_Debug(self::TOPIC_WS, '📥 HA WebSocket message', self::LV_INFO, $debugMessage);

        try {
            $this->HandleHomeAssistantWebSocketMessage($message, $clientIP, $clientPort);
        } catch (\Throwable $e) {
            $this->HA_Debug(self::TOPIC_WS, '❌ HA WebSocket message handling failed', self::LV_ERROR, [
                'client' => $clientIP . ':' . $clientPort,
                'type' => $message['type'] ?? null,
                'id' => $message['id'] ?? null,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function HandleHomeAssistantWebSocketMessage(array $message, string $clientIP, int $clientPort): void
    {
        $type = (string)($message['type'] ?? '');
        $id = isset($message['id']) ? (int)$message['id'] : null;

        switch ($type) {
            case 'auth':
                $receivedToken = trim((string)($message['access_token'] ?? ''));
                $storedToken = trim($this->ReadPropertyString('Token'));
                if ($storedToken !== '' && $receivedToken !== $storedToken) {
                    $this->HA_Debug(self::TOPIC_AUTH, '❌ Invalid HA WebSocket token', self::LV_WARN, [
                        'client' => $clientIP . ':' . $clientPort,
                        'received_token_length' => strlen($receivedToken),
                        'stored_token_length' => strlen($storedToken)
                    ]);
                    $this->SendHomeAssistantWebSocketJson([
                        'type' => 'auth_invalid',
                        'message' => 'Invalid access token'
                    ], $clientIP, $clientPort, 'HA WS auth_invalid');
                    return;
                }

                $this->SendHomeAssistantWebSocketJson([
                    'type' => 'auth_ok',
                    'ha_version' => '2024.1.0'
                ], $clientIP, $clientPort, 'HA WS auth_ok');
                return;

            case 'get_states':
                $this->SendHomeAssistantWebSocketJson([
                    'id' => $id,
                    'type' => 'result',
                    'success' => true,
                    'result' => $this->GetHomeAssistantStates()
                ], $clientIP, $clientPort, 'HA WS get_states result');
                return;

            case 'subscribe_events':
                $eventType = (string)($message['event_type'] ?? 'state_changed');
                $this->RememberHomeAssistantWebSocketSubscription($clientIP, $clientPort, (int)$id, [
                    'mode' => 'events',
                    'event_type' => $eventType,
                    'entity_ids' => []
                ]);
                $this->SendHomeAssistantWebSocketJson([
                    'id' => $id,
                    'type' => 'result',
                    'success' => true,
                    'result' => null
                ], $clientIP, $clientPort, 'HA WS subscribe_events result');
                return;

            case 'subscribe_trigger':
                $entityIds = $this->ExtractEntityIdsFromHomeAssistantTrigger($message['trigger'] ?? null);
                $this->HA_Debug(self::TOPIC_WS, '🔔 HA WebSocket trigger subscribe request', self::LV_INFO, [
                    'client' => $clientIP . ':' . $clientPort,
                    'id' => $id,
                    'entity_ids' => $entityIds,
                    'trigger' => $message['trigger'] ?? null
                ]);
                $this->RememberHomeAssistantWebSocketSubscription($clientIP, $clientPort, (int)$id, [
                    'mode' => 'trigger',
                    'event_type' => 'state_changed',
                    'entity_ids' => $entityIds
                ]);
                $this->HA_Debug(self::TOPIC_WS, '🔔 HA WebSocket trigger subscribed', self::LV_INFO, [
                    'client' => $clientIP . ':' . $clientPort,
                    'id' => $id,
                    'entity_ids' => $entityIds
                ]);
                $this->SendHomeAssistantWebSocketJson([
                    'id' => $id,
                    'type' => 'result',
                    'success' => true,
                    'result' => null
                ], $clientIP, $clientPort, 'HA WS subscribe_trigger result');
                return;

            case 'ping':
                $this->SendHomeAssistantWebSocketJson([
                    'id' => $id,
                    'type' => 'pong'
                ], $clientIP, $clientPort, 'HA WS pong message');
                return;

            case 'call_service':
                $this->HandleHomeAssistantWebSocketServiceCall($message, $clientIP, $clientPort);
                return;

            default:
                $this->HA_Debug(self::TOPIC_WS, '⚠️ Unsupported HA WebSocket message type', self::LV_WARN, [
                    'client' => $clientIP . ':' . $clientPort,
                    'type' => $type,
                    'id' => $id
                ]);
                if ($id !== null) {
                    $this->SendHomeAssistantWebSocketJson([
                        'id' => $id,
                        'type' => 'result',
                        'success' => false,
                        'error' => [
                            'code' => 'unknown_command',
                            'message' => 'Unsupported message type: ' . $type
                        ]
                    ], $clientIP, $clientPort, 'HA WS unsupported result');
                }
        }
    }

    private function HandleHomeAssistantWebSocketServiceCall(array $message, string $clientIP, int $clientPort): void
    {
        $id = isset($message['id']) ? (int)$message['id'] : null;
        $domain = (string)($message['domain'] ?? '');
        $service = (string)($message['service'] ?? '');
        $serviceData = is_array($message['service_data'] ?? null) ? $message['service_data'] : [];

        if (isset($message['target']) && is_array($message['target'])) {
            $serviceData = array_merge($serviceData, $message['target']);
        }

        $entityIds = $this->NormalizeHomeAssistantEntityIds($serviceData['entity_id'] ?? null);
        $oldStates = [];
        foreach ($entityIds as $entityId) {
            $oldStates[$entityId] = $this->GetHomeAssistantState($entityId);
        }

        $body = json_encode($serviceData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            $body = '{}';
        }

        $rawRequest = "POST /api/services/" . rawurlencode($domain) . "/" . rawurlencode($service) . " HTTP/1.1\r\n" .
            "Content-Type: application/json\r\n" .
            "Content-Length: " . strlen($body) . "\r\n\r\n" .
            $body;

        $this->HA_Debug(self::TOPIC_CMD, '📥 HA WebSocket service call', self::LV_INFO, [
            'id' => $id,
            'domain' => $domain,
            'service' => $service,
            'entity_ids' => $entityIds,
            'service_data' => $serviceData
        ]);

        $response = $this->HomeAssistantEmulator()->handleRawHttpRequest($rawRequest);
        $responseBody = $response->getBody();
        $decodedResult = json_decode($responseBody, true);
        $success = $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;

        $this->SendHomeAssistantWebSocketJson([
            'id' => $id,
            'type' => 'result',
            'success' => $success,
            'result' => is_array($decodedResult) ? $decodedResult : $responseBody
        ], $clientIP, $clientPort, 'HA WS call_service result');

        if (!$success) {
            return;
        }

        foreach ($entityIds as $entityId) {
            $this->SendHomeAssistantStateChangedEvent(
                $entityId,
                is_array($oldStates[$entityId] ?? null) ? $oldStates[$entityId] : null,
                $this->GetHomeAssistantState($entityId),
                $clientIP,
                $clientPort
            );
        }
    }

    private function SendHomeAssistantWebSocketJson(array $payload, string $clientIP, int $clientPort, string $context): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            $json = '{"type":"error","message":"json_encode failed"}';
        }

        $this->HA_Debug(self::TOPIC_WS, '📤 HA WebSocket message sent', self::LV_INFO, [
            'context' => $context,
            'client' => $clientIP . ':' . $clientPort,
            'payload_preview' => mb_substr($json, 0, 1000),
            'payload_length' => strlen($json)
        ]);

        $this->SendToServerSocket(\WebSocketUtils::PackData($json), $clientIP, $clientPort, $context);
    }

    private function GetHomeAssistantStates(): array
    {
        return $this->HomeAssistantEmulator()->getStates();
    }

    private function GetHomeAssistantState(string $entityId): ?array
    {
        return $this->HomeAssistantEmulator()->getState($entityId);
    }

    private function SendHomeAssistantStateChangedEvent(string $entityId, ?array $oldState, ?array $newState, string $clientIP, int $clientPort): void
    {
        if ($newState === null) {
            return;
        }

        if (!$this->HasHomeAssistantStateChanged($oldState, $newState)) {
            $this->HA_Debug(self::TOPIC_WS, '⏭️ Skipping unchanged HA WebSocket state update', self::LV_INFO, [
                'client' => $clientIP . ':' . $clientPort,
                'entity_id' => $entityId
            ]);
            return;
        }

        $subscription = $this->GetHomeAssistantWebSocketSubscription($clientIP, $clientPort);
        if ($subscription === null) {
            $this->HA_Debug(self::TOPIC_WS, '⚠️ No HA WebSocket state_changed subscription found', self::LV_WARN, [
                'client' => $clientIP . ':' . $clientPort,
                'entity_id' => $entityId
            ]);
            return;
        }

        $this->SendHomeAssistantSubscriptionEvent($subscription, $entityId, $oldState, $newState, $clientIP, $clientPort);
    }

    private function SendHomeAssistantSubscriptionEvent(array $subscription, string $entityId, ?array $oldState, array $newState, string $clientIP, int $clientPort): void
    {
        if (!$this->HasHomeAssistantStateChanged($oldState, $newState)) {
            $this->HA_Debug(self::TOPIC_WS, '⏭️ Skipping unchanged HA WebSocket subscription event', self::LV_INFO, [
                'client' => $clientIP . ':' . $clientPort,
                'entity_id' => $entityId
            ]);
            return;
        }

        $subscriptionID = (int)($subscription['id'] ?? 0);
        if ($subscriptionID <= 0) {
            return;
        }

        $mode = (string)($subscription['mode'] ?? 'events');
        $subscribedEntityIds = is_array($subscription['entity_ids'] ?? null) ? $subscription['entity_ids'] : [];
        if ($subscribedEntityIds !== [] && !in_array($entityId, $subscribedEntityIds, true)) {
            return;
        }

        if ($mode === 'trigger') {
            $this->SendHomeAssistantTriggerEvent($subscriptionID, $entityId, $oldState, $newState, $clientIP, $clientPort);
            return;
        }

        $this->SendHomeAssistantWebSocketJson([
            'id' => $subscriptionID,
            'type' => 'event',
            'event' => [
                'event_type' => 'state_changed',
                'data' => [
                    'entity_id' => $entityId,
                    'old_state' => $oldState,
                    'new_state' => $newState
                ],
                'origin' => 'LOCAL',
                'time_fired' => date('c'),
                'context' => [
                    'id' => 'ctx_' . str_replace('.', '_', $entityId) . '_' . time(),
                    'parent_id' => null,
                    'user_id' => null
                ]
            ]
        ], $clientIP, $clientPort, 'HA WS state_changed event');
    }

    private function SendHomeAssistantTriggerEvent(int $subscriptionID, string $entityId, ?array $oldState, array $newState, string $clientIP, int $clientPort): void
    {
        $context = is_array($newState['context'] ?? null) ? $newState['context'] : [
            'id' => $this->CreateHomeAssistantContextID(),
            'parent_id' => null,
            'user_id' => null
        ];

        $this->SendHomeAssistantWebSocketJson([
            'id' => $subscriptionID,
            'type' => 'event',
            'event' => [
                'variables' => [
                    'trigger' => [
                        'id' => '0',
                        'idx' => '0',
                        'alias' => null,
                        'platform' => 'state',
                        'entity_id' => $entityId,
                        'from_state' => $oldState,
                        'to_state' => $newState,
                        'for' => null,
                        'attribute' => null,
                        'description' => 'state of ' . $entityId
                    ]
                ],
                'context' => $context
            ]
        ], $clientIP, $clientPort, 'HA WS trigger event');
    }

    private function BroadcastHomeAssistantStateChangedEvents(array $entityIds, array $oldStates): void
    {
        $subscriptions = $this->ReadHomeAssistantWebSocketSubscriptions();
        if ($subscriptions === []) {
            $this->HA_Debug(self::TOPIC_WS, '⚠️ No HA WebSocket subscriptions for REST state update', self::LV_WARN, [
                'entity_ids' => $entityIds
            ]);
            return;
        }

        $this->HA_Debug(self::TOPIC_WS, '📣 Broadcasting HA WebSocket state update', self::LV_INFO, [
            'entity_ids' => $entityIds,
            'subscription_count' => count($subscriptions),
            'clients' => array_keys($subscriptions)
        ]);

        foreach ($subscriptions as $client => $subscription) {
            if (!is_array($subscription)) {
                continue;
            }

            $mode = (string)($subscription['mode'] ?? 'events');
            $eventType = (string)($subscription['event_type'] ?? '');
            if ($mode === 'events' && $eventType !== '' && $eventType !== 'state_changed') {
                continue;
            }

            $separator = strrpos((string)$client, ':');
            if ($separator === false) {
                continue;
            }

            $clientIP = substr((string)$client, 0, $separator);
            $clientPort = (int)substr((string)$client, $separator + 1);
            foreach ($entityIds as $entityId) {
                $newState = $this->GetHomeAssistantState((string)$entityId);
                if ($newState === null) {
                    continue;
                }

                if (!$this->HasHomeAssistantStateChanged(is_array($oldStates[$entityId] ?? null) ? $oldStates[$entityId] : null, $newState)) {
                    $this->HA_Debug(self::TOPIC_WS, '⏭️ Skipping unchanged HA REST state update broadcast', self::LV_INFO, [
                        'client' => $clientIP . ':' . $clientPort,
                        'entity_id' => (string)$entityId
                    ]);
                    continue;
                }

                $this->SendHomeAssistantSubscriptionEvent(
                    $subscription,
                    (string)$entityId,
                    is_array($oldStates[$entityId] ?? null) ? $oldStates[$entityId] : null,
                    $newState,
                    $clientIP,
                    $clientPort
                );
            }
        }
    }

    private function HasHomeAssistantStateChanged(?array $oldState, array $newState): bool
    {
        if ($oldState === null) {
            return true;
        }

        return $this->GetComparableHomeAssistantState($oldState) !== $this->GetComparableHomeAssistantState($newState);
    }

    private function GetComparableHomeAssistantState(array $state): array
    {
        return [
            'state' => $state['state'] ?? null,
            'attributes' => $state['attributes'] ?? []
        ];
    }

    private function ExtractEntityIdsFromHomeAssistantServiceRequest(string $rawRequest): array
    {
        $parts = explode("\r\n\r\n", $rawRequest, 2);
        $headerBlock = $parts[0] ?? '';
        $body = $parts[1] ?? '';
        $requestLine = strtok($headerBlock, "\r\n") ?: '';

        if (preg_match('#^POST\s+/api/services/[a-zA-Z0-9_]+/[a-zA-Z0-9_]+\s+#', $requestLine) !== 1) {
            return [];
        }

        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return [];
        }

        $entityId = $payload['entity_id'] ?? ($payload['target']['entity_id'] ?? null);
        return $this->NormalizeHomeAssistantEntityIds($entityId);
    }

    private function ExtractEntityIdsFromHomeAssistantTrigger($trigger): array
    {
        if (!is_array($trigger)) {
            return [];
        }

        if ($this->IsListArray($trigger)) {
            $entityIds = [];
            foreach ($trigger as $singleTrigger) {
                if (is_array($singleTrigger)) {
                    $entityIds = array_merge($entityIds, $this->NormalizeHomeAssistantEntityIds($singleTrigger['entity_id'] ?? null));
                }
            }
            return array_values(array_unique($entityIds));
        }

        return $this->NormalizeHomeAssistantEntityIds($trigger['entity_id'] ?? null);
    }

    private function IsListArray(array $array): bool
    {
        if ($array === []) {
            return true;
        }

        return array_keys($array) === range(0, count($array) - 1);
    }

    private function NormalizeHomeAssistantEntityIds($entityIds): array
    {
        if (is_string($entityIds)) {
            return [$entityIds];
        }

        if (is_array($entityIds)) {
            return array_values(array_filter(array_map('strval', $entityIds), static fn(string $entityId): bool => $entityId !== ''));
        }

        return [];
    }

    private function RememberHomeAssistantWebSocketSubscription(string $clientIP, int $clientPort, int $subscriptionID, array $subscription): void
    {
        $subscriptions = $this->ReadHomeAssistantWebSocketSubscriptions();
        $subscription['id'] = $subscriptionID;
        $subscriptions[$clientIP . ':' . $clientPort] = $subscription;
        $encoded = json_encode($subscriptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->SetBuffer('HAWebSocketSubscriptions', is_string($encoded) ? $encoded : '[]');
    }

    private function GetHomeAssistantWebSocketSubscription(string $clientIP, int $clientPort): ?array
    {
        $subscription = $this->ReadHomeAssistantWebSocketSubscriptions()[$clientIP . ':' . $clientPort] ?? null;
        if (!is_array($subscription)) {
            return null;
        }

        $mode = (string)($subscription['mode'] ?? 'events');
        $eventType = (string)($subscription['event_type'] ?? '');
        if ($mode === 'events' && $eventType !== '' && $eventType !== 'state_changed') {
            return null;
        }

        return $subscription;
    }

    private function RemoveHomeAssistantWebSocketSubscription(string $clientIP, int $clientPort): void
    {
        $subscriptions = $this->ReadHomeAssistantWebSocketSubscriptions();
        unset($subscriptions[$clientIP . ':' . $clientPort]);
        $encoded = json_encode($subscriptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->SetBuffer('HAWebSocketSubscriptions', is_string($encoded) ? $encoded : '[]');
    }

    private function ReadHomeAssistantWebSocketSubscriptions(): array
    {
        $raw = $this->GetBuffer('HAWebSocketSubscriptions');
        if (!is_string($raw) || $raw === '') {
            try {
                $raw = $this->ReadAttributeString('HAWebSocketSubscriptions');
            } catch (\Throwable $e) {
                $raw = '[]';
            }
        }

        if (!is_string($raw) || $raw === '') {
            $raw = '[]';
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function CreateHomeAssistantContextID(): string
    {
        return strtoupper(bin2hex(random_bytes(13)));
    }

    private function handleRequest($data, string $clientIP, int $clientPort)
    {
        $bufferName = $this->GetHttpReceiveBufferName($clientIP, $clientPort);
        $buffer = $this->GetBuffer($bufferName);

        // Füge die neuen Daten zum Puffer hinzu
        $buffer .= $data;

        // Prüfe, ob das Ende der Anfrage erreicht ist (doppeltes CRLF)
        if (strpos($buffer, "\r\n\r\n") !== false) {
            $this->HA_Debug(self::TOPIC_IO, '🧩 Complete HTTP request buffered', self::LV_INFO, [
                'buffer_length' => strlen($buffer),
                'client' => $clientIP . ':' . $clientPort,
                'buffer_preview' => mb_substr($buffer, 0, 1000)
            ]);

            // Die vollständigen Daten sind nun im Buffer
            $this->processReceivedData($buffer, $clientIP, $clientPort);

            // Puffer leeren, da die Daten verarbeitet wurden
            $buffer = "";
        }

        // Speichere den aktuellen Puffer wieder ab
        $this->SetBuffer($bufferName, $buffer);
    }

    private function GetHttpReceiveBufferName(string $clientIP, int $clientPort): string
    {
        return 'ReceiveBuffer_' . substr(sha1($clientIP . ':' . $clientPort), 0, 16);
    }

    private function sendHomeAssistantResponse($data, string $clientIP, int $clientPort)
    {
        // Antworte im Home Assistant Stil
        $this->HA_Debug(self::TOPIC_HA, '🏠 Home Assistant request received');
        $entityIds = $this->ExtractEntityIdsFromHomeAssistantServiceRequest((string)$data);
        $oldStates = [];
        foreach ($entityIds as $entityId) {
            $oldStates[$entityId] = $this->GetHomeAssistantState($entityId);
        }

        $response = $this->HomeAssistantEmulator()->handleRawHttpRequest((string)$data);
        $this->sendHttpResponseObject($response, $clientIP, $clientPort);
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300 && $entityIds !== []) {
            $this->BroadcastHomeAssistantStateChangedEvents($entityIds, $oldStates);
        }
        return;

        // Zerlege die Anfrage in Header und Body
        list($headers, $body) = explode("\r\n\r\n", $data, 2);

        // Zerlege die erste Zeile der Anfrage, um die Methode und den Pfad zu bestimmen
        $requestLines = explode("\r\n", $headers);
        if (count($requestLines) > 0) {
            $requestLine = $requestLines[0];
            $requestParts = explode(' ', $requestLine);

            if (count($requestParts) >= 2) {
                $httpMethod = $requestParts[0]; // Die HTTP-Methode (z.B. GET, POST)
                $requestPath = $requestParts[1]; // Der angeforderte Pfad (z.B. /api/states)

                // Debugging der erkannten Methode und des Pfades
                $this->DebugLog(__FUNCTION__, 'HTTP Method: ' . $httpMethod, 0);
                $this->DebugLog(__FUNCTION__, 'Request Path: ' . $requestPath, 0);

                // Überprüfe, ob es sich um eine POST-Anfrage handelt, und ob ein Body vorhanden ist
                if ($httpMethod === 'POST' && !empty($body)) {
                    $this->DebugLog(__FUNCTION__, 'Request Body: ' . $body, 0);

                    // Versuche, den Body als JSON zu dekodieren
                    $jsonData = json_decode($body, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        // JSON erfolgreich dekodiert
                        if (isset($jsonData['entity_id'])) {
                            $entityId = $jsonData['entity_id'];
                            $this->DebugLog(__FUNCTION__, 'Entity ID erkannt: ' . $entityId, 0);

                            // Beispielbehandlung für Light on/off
                            if (strpos($requestPath, '/api/services/light/turn_on') !== false) {
                                $this->DebugLog('HaptiqueEmulator', 'Licht einschalten erkannt für ' . $entityId, 0);
                                $this->executeLightCommand($entityId, true);
                                return;
                            } elseif (strpos($requestPath, '/api/services/light/turn_off') !== false) {
                                $this->DebugLog('HaptiqueEmulator', 'Licht ausschalten erkannt für ' . $entityId, 0);
                                $this->executeLightCommand($entityId, false);
                                return;
                            } elseif (strpos($requestPath, '/api/services/switch/turn_on') !== false) {
                                $this->DebugLog('HaptiqueEmulator', 'Switch On erkannt für ' . $entityId, 0);
                                $this->executeSwitchCommand($entityId, true);
                                return;
                            } elseif (strpos($requestPath, '/api/services/switch/turn_off') !== false) {
                                $this->DebugLog('HaptiqueEmulator', 'Switch Off erkannt für ' . $entityId, 0);
                                $this->executeSwitchCommand($entityId, false);
                                return;
                            } elseif (strpos($requestPath, '/api/services/automation/trigger') !== false) {
                                $this->DebugLog('HaptiqueEmulator', 'Automation erkannt für ' . $entityId, 0);
                                $this->executeAutomation($entityId);
                                return;
                            } elseif (strpos($requestPath, '/api/services/media_player/media_play') !== false) {
                                $this->DebugLog('HaptiqueEmulator', 'Play-Befehl erkannt.', 0);
                                $this->executePlayCommand($entityId);
                                return;
                            } elseif ($httpMethod === 'POST' && strpos($requestPath, '/api/services/media_player/media_pause') !== false) {
                                $this->DebugLog('HaptiqueEmulator', 'Pause-Befehl erkannt.', 0);
                                $this->executePauseCommand($entityId);
                                return;
                            } elseif ($httpMethod === 'POST' && strpos($requestPath, '/api/services/media_player/media_next_track') !== false) {
                                $this->DebugLog('HaptiqueEmulator', 'Next Track-Befehl erkannt.', 0);
                                $this->executeNextCommand($entityId);
                                return;
                            } elseif ($httpMethod === 'POST' && strpos($requestPath, '/api/services/media_player/media_previous_track') !== false) {
                                $this->DebugLog('HaptiqueEmulator', 'Previous Track-Befehl erkannt.', 0);
                                $this->executePreviousCommand($entityId);
                                return;
                            }

                            // Führe basierend auf der entity_id die entsprechende Aktion aus
                            if ($requestPath === '/api/services/media_player/media_pause' && $entityId === 'media_player.denon_receiver') {
                                $this->DebugLog('HaptiqueEmulator', 'Pause-Befehl für Denon Receiver erkannt.', 0);
                                $this->executePauseCommand();
                                return;
                            }
                            // Hier können weitere if-Abfragen hinzugefügt werden, um andere Geräte oder Aktionen zu verarbeiten
                        } else {
                            $this->DebugLog('Error', 'Keine gültige entity_id im JSON-Body gefunden.', 0);
                        }
                    } else {
                        $this->DebugLog('Error', 'Ungültiger JSON-Body: ' . json_last_error_msg(), 0);
                    }
                }

                // Neue, präzisere Routing-Logik für GET /api/states
                if ($httpMethod === 'GET' && $requestPath === '/api/states') {
                    $this->DebugLog(__FUNCTION__, 'Request Type GET /api/states', 0);
                    $this->handleGetStates();
                    return;
                } elseif ($httpMethod === 'GET' && preg_match('#^/api/states/([a-zA-Z0-9._]+)$#', $requestPath, $matches)) {
                    $entityId = $matches[1];
                    $this->DebugLog(__FUNCTION__, 'Request State: ' . $entityId, 0);

                    // Einzelabfrage: Temperatur- oder Batteriesensor
                    if (preg_match('/^sensor\.(\d+)$/', $entityId, $sensorMatches)) {
                        $this->DebugLog(__FUNCTION__, 'Sensor Einzelabfrage erkannt: ' . $entityId, 0);
                        $this->GetSensorState((int)$sensorMatches[1]);
                        return;
                    }

                    // Einzelabfrage: Bewegungssensor
                    if (preg_match('@/api/states/binary_sensor\.(\d+)@', $entityId, $matches)) {
                        return $this->GetMotionSensorState((int)$matches[1]);
                    }

                    if (str_starts_with($entityId, 'light.')) {
                        $this->handleGetSingleStateLight($entityId);
                        return;
                    } elseif (str_starts_with($entityId, 'switch.')) {
                        $this->handleGetSingleStateSwitch($entityId);
                        return;
                    } elseif (str_starts_with($entityId, 'automation.')) {
                        $this->handleGetSingleStateAutomation($entityId);
                        return;
                    } elseif (str_starts_with($entityId, 'mediaplayer.')) {
                        $this->handleGetSingleStateMediaPlayer($entityId);
                        return;
                    } else {
                        $this->sendErrorResponse(404, "Entity '$entityId' not found or unsupported");
                    }
                    return;
                }

                // Weiteres Routing, wie gehabt
                if ($httpMethod === 'POST' && strpos($requestPath, '/api/template') !== false) {
                    $this->DebugLog(__FUNCTION__, 'Request Type POST', 0);
                    $this->DebugLog(__FUNCTION__, 'Call Template', 0);
                    $this->handlePostTemplate();
                    return;
                } elseif ($httpMethod === 'GET' && strpos($requestPath, '/api/states/light.hue_group') !== false) {
                    $this->DebugLog('HaptiqueEmulator', 'Lampenstatusanfrage erkannt.', 0);
                    $this->executeLightToggle();
                    return;
                } else {
                    $this->DebugLog(__FUNCTION__, 'Keine passende Route gefunden für Methode ' . $httpMethod . ' und Pfad ' . $requestPath, 0);
                    $this->sendErrorResponse(404, "Unknown request: $httpMethod $requestPath");
                }
            } else {
                $this->DebugLog('HaptiqueEmulator', 'Ungültige HTTP-Anfrage: ' . $data, 0);
            }
        }
    }

    /**
     * Einzelabfrage für einen Sensor-Status über /api/states/sensor.{VariableID}
     */
    private function GetSensorState(int $varID)
    {
        $temps = $this->GetDataFromConfigurator("GetTemperatureSensors");
        foreach ($temps as $sensor) {
            if ($sensor["TemperatureVariable"] == $varID) {
                $value = GetValue($varID);
                $response = [
                    'entity_id' => "sensor.$varID",
                    'state' => strval($value),
                    'attributes' => [
                        'device_class' => 'temperature',
                        'unit_of_measurement' => '°C',
                        'state_class' => 'measurement',
                        'friendly_name' => $sensor["Name"]
                    ],
                    'last_changed' => date("c"),
                    'last_updated' => date("c"),
                    'context' => ['id' => "id_sensor_$varID", 'parent_id' => null, 'user_id' => null]
                ];
                $this->sendHttpResponse(json_encode($response));
                return;
            }
        }

        $batteries = $this->GetDataFromConfigurator("GetBatterySensors");
        foreach ($batteries as $sensor) {
            if ($sensor["BatteryVariable"] == $varID) {
                $value = GetValue($varID);
                $response = [
                    'entity_id' => "sensor.$varID",
                    'state' => strval($value),
                    'attributes' => [
                        'device_class' => 'battery',
                        'unit_of_measurement' => '%',
                        'state_class' => 'measurement',
                        'friendly_name' => $sensor["Name"]
                    ],
                    'last_changed' => date("c"),
                    'last_updated' => date("c"),
                    'context' => ['id' => "id_battery_$varID", 'parent_id' => null, 'user_id' => null]
                ];
                $this->sendHttpResponse(json_encode($response));
                return;
            }
        }

        $this->sendErrorResponse(404, "Sensor with VariableID $varID not found");
    }

    private function GetMotionSensorState(int $varID)
    {
        $motions = $this->GetDataFromConfigurator("GetMotionSensors");
        foreach ($motions as $sensor) {
            if ($sensor["MotionVariable"] == $varID) {
                $value = GetValue($varID);
                $response = [
                    'entity_id' => "binary_sensor.$varID",
                    'state' => $value ? 'on' : 'off',
                    'attributes' => [
                        'device_class' => 'motion',
                        'friendly_name' => $sensor["Name"]
                    ],
                    'last_changed' => date("c"),
                    'last_updated' => date("c"),
                    'context' => ['id' => "id_motion_$varID", 'parent_id' => null, 'user_id' => null]
                ];
                $this->sendHttpResponse(json_encode($response));
                return;
            }
        }
        $this->sendErrorResponse(404, "Sensor with VariableID $varID not found");
    }

    private function handleGetSingleStateLight(string $entityId): void
    {
        $this->DebugLog(__FUNCTION__, "Anfrage für $entityId", 0);

        // Hole die Lichter aus dem Konfigurator
        $lights = $this->GetDataFromConfigurator("GetLights");
        foreach ($lights as $light) {
            $id = $light["SwitchVariable"];
            $name = $light["Name"];
            if ($entityId === "light.$id") {
                $value = GetValue($id);
                $now = date('c');
                $response = [
                    'entity_id' => $entityId,
                    'state' => $value ? 'on' : 'off',
                    'attributes' => [
                        'friendly_name' => $name,
                        'manufacturer' => $light["Manufacturer"] ?? '',
                        'model' => $light["Model"] ?? '',
                        'supported_features' => 41
                    ],
                    'last_changed' => $now,
                    'last_updated' => $now,
                    'context' => [
                        'id' => "id_light_$id",
                        'parent_id' => null,
                        'user_id' => null
                    ]
                ];
                $this->sendHttpResponse(json_encode($response));
                return;
            }
        }
        $this->sendErrorResponse(404, "Entity '$entityId' not found");
    }

    private function handleGetSingleStateSwitch(string $entityId): void
    {
        $this->DebugLog(__FUNCTION__, "Anfrage für $entityId", 0);
        // entityId wie 'switch.XXXXX'
        if (preg_match('/^switch\.(\d+)$/', $entityId, $matches)) {
            $variableID = (int)$matches[1];
            $this->GetSwitchState($variableID);
            return;
        }
        $this->sendErrorResponse(404, "Entity '$entityId' not found");
    }

    /**
     * Einzelabfrage für einen Switch-Status über /api/states/switch.{VariableID}
     */
    private function GetSwitchState(int $variableID)
    {
        $switches = $this->GetDataFromConfigurator("GetSwitches");

        foreach ($switches as $switch) {
            if ($switch["SwitchVariable"] == $variableID) {
                $value = GetValue($variableID);
                $now = date("c");

                $response = [
                    'entity_id' => "switch.$variableID",
                    'state' => $value ? 'on' : 'off',
                    'attributes' => [
                        'friendly_name' => $switch["Name"],
                        'manufacturer' => $switch["Manufacturer"] ?? '',
                        'model' => $switch["Model"] ?? ''
                    ],
                    'last_changed' => $now,
                    'last_updated' => $now,
                    'context' => [
                        'id' => "id_switch_$variableID",
                        'parent_id' => null,
                        'user_id' => null
                    ]
                ];
                $this->sendHttpResponse(json_encode($response));
                return;
            }
        }
        $this->sendErrorResponse(404, "Switch with VariableID $variableID not found");
    }

    private function handleGetSingleStateAutomation(string $entityId): void
    {
        $this->DebugLog(__FUNCTION__, "Anfrage für $entityId", 0);
        // Hole Automationen aus dem Konfigurator
        $automations = $this->GetDataFromConfigurator("GetAutomations");
        foreach ($automations as $automation) {
            $id = $automation["Automation_ID"];
            $name = $automation["Name"];
            $scriptID = $automation["ScriptID"];
            if ($entityId === "automation.$id") {
                $now = date('c');
                $response = [
                    'entity_id' => $entityId,
                    'state' => 'on', // Annahme: Immer aktiv, ggf. je nach Konfiguration anpassen
                    'attributes' => [
                        'friendly_name' => $name,
                        'supported_features' => 0
                    ],
                    'last_changed' => $now,
                    'last_updated' => $now,
                    'context' => [
                        'id' => "id_automation_$id",
                        'parent_id' => null,
                        'user_id' => null
                    ]
                ];
                $this->sendHttpResponse(json_encode($response));
                return;
            }
        }
        $this->sendErrorResponse(404, "Entity '$entityId' not found");
    }

    private function handleGetSingleStateMediaPlayer(string $entityId): void
    {
        $this->DebugLog(__FUNCTION__, "Anfrage für $entityId", 0);
        // Beispielhafter Mapper von entity_id auf VariableID
        $mapping = [
            'automation.19609' => 19609,
            'automation.51875' => 35490,
            // Weitere Geräte hier ergänzen...
        ];
        if (!isset($mapping[$entityId])) {
            $this->sendErrorResponse(404, "Entity '$entityId' not found");
            return;
        }
        $varId = $mapping[$entityId];
        // Automations typically have state "on" or "off" (enabled/disabled)
        $state = GetValue($varId) ? 'on' : 'off';
        $now = date('Y-m-d\TH:i:s.000000\Z');
        $response = [
            'entity_id' => $entityId,
            'state' => $state,
            'attributes' => [
                'friendly_name' => "Dummy Automation"
            ],
            'last_changed' => $now,
            'last_updated' => $now,
            'context' => [
                'id' => uniqid("ctx_"),
                'parent_id' => null,
                'user_id' => null
            ]
        ];
        $this->sendHttpResponse(json_encode($response));
    }


    private function sendIPSymconResponse($data)
    {
        // Antworte im IP-Symcon Stil
        $this->DebugLog('Response', 'IP-Symcon Response', 0);
    }

    public function RequestAction($Ident, $Value): void
    {
        switch ($Ident) {
            case "EditLightSwitch":
                $this->EditLightSwitch($Value);
                break;
            case "DeleteLightSwitch":
                $this->DeleteLightSwitch($Value);
                break;
            case "AddLightSwitch":
                $this->AddLightSwitch();
                break;
        }
    }

    private function generateDeviceListForRS90()
    {
        /* theoretische Grundlage

            [
                [
                    "InstanceID" => 42394,
                    "type" => "light",
                    "name" => "Hue Zone Coachlampen",
                    "manufacturer" => "Philips Hue",
                    "model" => "Hue Group"
                ],
                [
                    "InstanceID" => 57191,
                    "type" => "media_player",
                    "name" => "Denon AVC-8500HA",
                    "manufacturer" => "Denon",
                    "model" => "AVC-X8500HA"
                ],
                [
                    "InstanceID" => 25796,
                    "type" => "switch",
                    "name" => "Homematic IP Schalter",
                    "manufacturer" => "eQ-3",
                    "model" => "Generic Switch"
                ]
            ]

            */


        $selectedDevices = $this->ReadPropertyString('SelectedDevices'); // JSON aus der Konfiguration
        $devices = json_decode($selectedDevices, true);

        $responseDevices = [];

        foreach ($devices as $device) {
            $responseDevices[] = [
                "device_id" => $device['InstanceID'],
                "name" => $device['name'],
                "manufacturer" => $device['manufacturer'],
                "model" => $device['model'],
                "entities" => [
                    $device['type'] . "." . $device['InstanceID']
                ]
            ];
        }

        $response = json_encode($responseDevices);
        $this->DebugLog('Generated Device List', $response, 0);
        $this->SendResponse($response);
    }

    private function generateStatusResponseForRS90()
    {
        $selectedDevices = json_decode($this->ReadPropertyString('SelectedDevices'), true);

        $statusList = [];

        foreach ($selectedDevices as $device) {
            $state = $this->getDeviceState($device['InstanceID']); // Methode zum Abrufen des Status muss noch implementiert werden
            $attributes = $this->getDeviceAttributes($device['InstanceID'], $device['type']); // Methode zum Abruf der Attribut Liste muss noch implementiert werden

            $statusList[] = [
                "entity_id" => $device['type'] . "." . $device['InstanceID'],
                "state" => $state,
                "attributes" => $attributes
            ];
        }

        $response = json_encode($statusList);
        $this->DebugLog('Generated Status Response', $response, 0);
        $this->SendResponse($response);
    }

    // Führt Lichtbefehle aus (on/off) für bestimmte entity_ids
    private function executeLightCommand(string $entityId, bool $state): void
    {
        if (preg_match('/^light\.(\d+)$/', $entityId, $matches)) {
            $variableID = (int)$matches[1];
            $this->DebugLog("Light Service", "RequestAction ausgeführt für $entityId mit Status " . ($state ? 'an' : 'aus'), 0);
            RequestAction($variableID, $state);
            $this->sendAcknowledgementResponse();
        }
    }

    private function executePlayCommand(string $entityId)
    {
        $mapping = [
            'switch.25796' => 14753, // status var
            'switch.51875' => 35490,
            // Weitere Zuordnungen hier
        ];

        if (!isset($mapping[$entityId])) {
            $this->DebugLog(__FUNCTION__, "Keine Zuordnung gefunden für $entityId", 0);
            $this->sendErrorResponse(404, "Device not found");
            return;
        }

        $varId = $mapping[$entityId];
        $state = 2; // Varibalenprofil prüfen
        RequestAction($varId, $state);
        $this->DebugLog('HaptiqueEmulator', 'Play-Befehl ausgeführt.', 0);
        $this->sendAcknowledgementResponse();
    }

    private function executePauseCommand(string $entityId)
    {
        $mapping = [
            'switch.25796' => 14753, // status var
            'switch.51875' => 35490,
            // Weitere Zuordnungen hier
        ];

        if (!isset($mapping[$entityId])) {
            $this->DebugLog(__FUNCTION__, "Keine Zuordnung gefunden für $entityId", 0);
            $this->sendErrorResponse(404, "Device not found");
            return;
        }

        $varId = $mapping[$entityId];
        $state = 2; // Variablenprofil prüfen
        RequestAction($varId, $state);
        $this->DebugLog('HaptiqueEmulator', 'Pause-Befehl ausgeführt.', 0);
        $this->sendAcknowledgementResponse();
    }

    private function executeNextCommand(string $entityId)
    {
        $mapping = [
            'switch.25796' => 14753, // status var
            'switch.51875' => 35490,
            // Weitere Zuordnungen hier
        ];

        if (!isset($mapping[$entityId])) {
            $this->DebugLog(__FUNCTION__, "Keine Zuordnung gefunden für $entityId", 0);
            $this->sendErrorResponse(404, "Device not found");
            return;
        }

        $varId = $mapping[$entityId];
        $state = 2; // Variablenprofil prüfen
        RequestAction($varId, $state);
        $this->DebugLog('HaptiqueEmulator', 'Pause-Befehl ausgeführt.', 0);
        $this->sendAcknowledgementResponse();
    }

    private function executePreviousCommand(string $entityId)
    {
        $mapping = [
            'switch.25796' => 14753, // status var
            'switch.51875' => 35490,
            // Weitere Zuordnungen hier
        ];

        if (!isset($mapping[$entityId])) {
            $this->DebugLog(__FUNCTION__, "Keine Zuordnung gefunden für $entityId", 0);
            $this->sendErrorResponse(404, "Device not found");
            return;
        }

        $varId = $mapping[$entityId];
        $state = 2; // Variablenprofil prüfen
        RequestAction($varId, $state);
        $this->DebugLog('HaptiqueEmulator', 'Pause-Befehl ausgeführt.', 0);
        $this->sendAcknowledgementResponse();
    }

    private function executeSequence1(string $entityId)
    {
        // Sequenz 1
        $sequence1_Id = 57018;  // Apple TV Leinwand On
        // IPS_RunScript(32999);
        $denonPowerVarId = 19938;  // Beispiel: Power-Variable des Denon-Verstärkers
        RequestAction($denonPowerVarId, true);  // Verstärker previous
        $this->DebugLog('HaptiqueEmulator', 'Sequenz 1 wurde gestartet.', 0);
        $this->sendAcknowledgementResponse();
    }

    private function executeSequence2(string $entityId)
    {
        // Sequenz 2
        $sequence2_Id = 57018;  // Power Off
        // IPS_RunScript(43366);
        $denonPowerVarId = 19938;  // Beispiel: Power-Variable des Denon-Verstärkers
        RequestAction($denonPowerVarId, false);  // Verstärker next
        $this->DebugLog('HaptiqueEmulator', 'Sequenz 2 wurde gestartet.', 0);
        $this->sendAcknowledgementResponse();
    }

    private function executeSwitchCommand(string $entityId, $state)
    {
        if (preg_match('/^switch\.(\d+)$/', $entityId, $matches)) {
            $variableID = (int)$matches[1];
            $this->DebugLog("Switch Service", "RequestAction ausgeführt für $entityId mit Status " . ($state ? 'an' : 'aus'), 0);
            RequestAction($variableID, $state);
            $this->sendAcknowledgementResponse();
        }
    }

    private function executeAutomation(string $entityId)
    {
        if (preg_match('/^automation\.(\d+)$/', $entityId, $matches)) {
            $variableID = (int)$matches[1];
            $this->DebugLog("Automation Service", "Runscript  ausgeführt für " . $entityId, 0);
            IPS_RunScript($variableID);
            $this->sendAcknowledgementResponse();
        }
    }

    private function executeLightToggle()
    {
        // Hue-Lampe ein-/ausschalten (ID der Status-Variable verwenden)
        $hueLightVarId = 21033;  // Beispiel: Statusvariable der Hue-Lampe
        $currentState = GetValue($hueLightVarId);

        // Zustand der Lampe umschalten
        if ($currentState) {
            RequestAction($hueLightVarId, false);  // Lampe ausschalten
            $this->DebugLog('HaptiqueEmulator', 'Hue-Lampe wurde ausgeschaltet.', 0);
        } else {
            RequestAction($hueLightVarId, true);   // Lampe einschalten
            $this->DebugLog('HaptiqueEmulator', 'Hue-Lampe wurde eingeschaltet.', 0);
        }
        $this->sendAcknowledgementResponse();
    }

    private function handleGetStates()
    {
        $this->DebugLog(__FUNCTION__, 'Call /api/states', 0);
        // Hier die Variablen-IDs der Hue-Gruppe und des Denon-Verstärkers einsetzen

        $hueState = GetValue(21033);  // Statusvariable der Hue-Gruppe (Boolean: true=An, false=Aus), Coachlampen
        $denonState = GetValue(26702); // Statusvariable des Denon-Verstärkers (Boolean: true=An, false=Aus)
        $switchState = GetValue(14753);  // Statusvariable der Sonos Wohnzimmer Port Crossfade (Boolean: true=An, false=Aus), Sonos Wohnzimmer

        /*
            $hueState = true;  // Statusvariable der Hue-Gruppe (Boolean: true=An, false=Aus), Coachlampen
            $denonState = true; // Statusvariable des Denon-Verstärkers (Boolean: true=An, false=Aus)
            $switchState = true;  // Statusvariable der Sonos Wohnzimmer Port Crossfade (Boolean: true=An, false=Aus), Sonos Wohnzimmer
            */

        $response = json_encode($this->GetCompleteHAResponse());

        /*
            $response = json_encode([

                [
                    "entity_id" => "light.42394",
                    "state" => $hueState ? "on" : "off",
                    "attributes" => [
                        "friendly_name" => "Hue Light Group",
                        "supported_features" => 41,
                    ]
                ],
                [
                    "entity_id" => "media_player.57191",
                    "state" => $denonState ? "on" : "off",
                    "attributes" => [
                        "friendly_name" => "Denon AVC 8500HA",
                        "supported_features" => 909, // Angepasste Features des Denon-Receivers
                        "volume_level" => 30, // Lautstärkevariable (falls vorhanden)
                    ]
                ],
                [
                    "entity_id" => "switch.25796",
                    "state" => $switchState ? "on" : "off",
                    "attributes" => [
                        "friendly_name" => "Homematic IP Schalter"
                    ]
                ]
            ]);
            */


        // HTTP-Antwort erstellen
        $this->sendHttpResponse($response);
    }

    private function sendAcknowledgementResponse()
    {
        // Sende eine leere, aber erfolgreiche JSON-Antwort zurück
        $response = json_encode(["success" => true]);
        $this->sendHttpResponse($response);
    }

    private function handlePostTemplate()
    {
        $this->DebugLog(__FUNCTION__, 'HaptiqueEmulator Geräteabfrage gestartet', 0);

        // Daten aus den Properties laden
        /*
           // vorschlag

           $devices = []; // Sammlung der Geräteinformationen

            // Beispiel: Schleife über freigegebene Geräte (angenommen, du hast sie in einer Liste gespeichert)
            $deviceList = $this->GetFreigegebeneGeräte(); // Funktion, die die freigegebenen Geräte abruft

            foreach ($deviceList as $device) {
                $deviceId = $device['InstanceID']; // Oder eine andere ID
                $name = IPS_GetName($deviceId); // Beispiel: Name des Geräts in IP-Symcon
                $manufacturer = $this->GetDeviceManufacturer($deviceId); // Benutzerdefinierte Funktion, um Hersteller zu ermitteln
                $model = $this->GetDeviceModel($deviceId); // Benutzerdefinierte Funktion, um Modell zu ermitteln

                // Erhalte alle relevanten Variablen (Entities) unterhalb der Instanz
                $entities = [];
                $variableIds = IPS_GetChildrenIDs($deviceId);
                foreach ($variableIds as $varId) {
                    if (IPS_VariableExists($varId)) {
                        $entities[] = IPS_GetName($varId); // Verwende Variablennamen oder andere Identifikatoren
                    }
                }

                // Gerätedaten hinzufügen
                $devices[] = [
                    'device_id' => $deviceId,
                    'name' => $name,
                    'manufacturer' => $manufacturer,
                    'model' => $model,
                    'entities' => $entities
                ];
            }

            // Sende die JSON-Antwort
            $response = json_encode($devices);
            $this->DebugLog('Generated Response', $response, 0);
            $this->SendResponse($response); // Eine Funktion, die die Antwort über den Socket zurücksendet



          // test
            $lightSwitches = json_decode($this->ReadPropertyString('LightSwitches'), true);
            $switches = json_decode($this->ReadPropertyString('Switches'), true);
            $mediaPlayers = json_decode($this->ReadPropertyString('MediaPlayer'), true);

            // Erstelle eine leere Liste für die Geräteinformationen
            $devices = [];

            // Bearbeite die Lichtschalter (LightSwitches)
            foreach ($lightSwitches as $light) {
                $devices[] = [
                    "device_id" => "hue_group",  // Verwende statische, bekannte device_ids
                    "name" => $light['Name'], // Teste mit bekannten Namen
                    "manufacturer" => $light['Manufacturer'],  // Nutze die Manufacturer-Daten aus den Properties
                    "model" => $light['Modell'],  // Nutze die Model-Daten aus den Properties
                    "entities" => [
                        "light.hue_group"  // Statisch, um Kompatibilität zu testen
                    ]
                ];
            }

            // Bearbeite die Schalter (Switches)
            foreach ($switches as $switch) {
                $devices[] = [
                    "device_id" => "hue_light_switch",
                    "name" => $switch['Name'],
                    "manufacturer" => $switch['Manufacturer'],
                    "model" => $switch['Modell'],
                    "entities" => [
                        "switch.sonos_wohnzimmer_crossfade"
                    ]
                ];
            }

            // Bearbeite die Medienplayer (MediaPlayer)
            foreach ($mediaPlayers as $mediaPlayer) {
                $devices[] = [
                    "device_id" => "denon_receiver",
                    "name" => $mediaPlayer['Name'],
                    "manufacturer" => $mediaPlayer['Manufacturer'],
                    "model" => $mediaPlayer['Modell'],
                    "entities" => [
                        "media_player.denon_receiver"
                    ]
                ];
            }
           */

        // Bearbeite die Lichtschalter (LightSwitches)
        /*

             foreach ($lightSwitches as $light) {
                $devices[] = [
                    "device_id" => "light_switch_" . $light['SwitchVariable'],  // Verwende die Variable als device_id
                    "name" => $light['Name'],
                    "manufacturer" => $light['Manufacturer'],
                    "model" => $light['Modell'],
                    "entities" => [
                        "light." . $this->generateEntityName($light['Name'])
                    ]
                ];
            }

            // Bearbeite die Schalter (Switches)
            foreach ($switches as $switch) {
                $devices[] = [
                    "device_id" => "switch_" . $switch['SwitchVariable'],
                    "name" => $switch['Name'],
                    "manufacturer" => $switch['Manufacturer'],
                    "model" => $switch['Modell'],
                    "entities" => [
                        "switch." . $this->generateEntityName($switch['Name'])
                    ]
                ];
            }

            // Bearbeite die Medienplayer (MediaPlayer)
            foreach ($mediaPlayers as $mediaPlayer) {
                $devices[] = [
                    "device_id" => "media_player_" . $mediaPlayer['SwitchVariable'],
                    "name" => $mediaPlayer['Name'],
                    "manufacturer" => $mediaPlayer['Manufacturer'],
                    "model" => $mediaPlayer['Modell'],
                    "entities" => [
                        "media_player." . $this->generateEntityName($mediaPlayer['Name'])
                    ]
                ];
            }
            */

        // Konvertiere die Geräteliste in JSON
        // $response = json_encode($devices);
        /*
             * [
    {
        "device_id": "123456789",
        "name": "Living Room Light",
        "manufacturer": "Philips",
        "model": "Hue Bulb",
        "entities": [
            "light.living_room",
            "sensor.living_room_temperature"
        ]
    },
    {
        "device_id": "987654321",
        "name": "Bedroom Speaker",
        "manufacturer": "Sonos",
        "model": "Play:1",
        "entities": [
            "media_player.bedroom_speaker"
        ]
    }
]
             *
             */

        // Simuliere die Rückgabe der Geräteinformationen

        // gesamte Home Assistant Antwort zum Test

        // alte antwort

        $response = json_encode([
            [
                "device_id" => 42394,
                "name" => "Hue Zone Coachlampen",
                "manufacturer" => "Philips Hue",
                "model" => "Hue Group",
                "entities" => [
                    "light.42394"
                ]
            ],
            [
                "device_id" => 57191,
                "name" => "Denon AVC-8500HA",
                "manufacturer" => "Denon",
                "model" => "AVC-X8500HA",
                "entities" => [
                    "media_player.57191"
                ]
            ],
            [
                "device_id" => 25796,
                "name" => "Homematic IP Schalter",
                "manufacturer" => "eQ-3",
                "model" => "Generic Switch",
                "entities" => [
                    "switch.25796"
                ]
            ],
            [
                "device_id" => 56789,
                "name" => "Temperatur Sensor",
                "manufacturer" => "Netatmo",
                "model" => "Smart Weather Station",
                "entities" => [
                    "sensor.innenstation_temperatur"

                ]
            ],
            [
                "device_id" => 98765,
                "name" => "Helligkeits Sensor",
                "manufacturer" => "Netatmo",
                "model" => "Smart Weather Station",
                "entities" => [
                    "sensor.bewegungssensor_wohnzimmer_illuminance"
                ]
            ],
            [
                "device_id" => 87654,
                "name" => "Batterie Sensor",
                "manufacturer" => "Netatmo",
                "model" => "Smart Weather Station",
                "entities" => [
                    "sensor.bewegungssensor_wohnzimmer_battery"
                ]
            ]
        ]);


        // HTTP-Antwort erstellen
        $this->sendHttpResponse($response);
    }

    // Hilfsfunktion, um einen gültigen Entity-Namen zu erstellen
    private function generateEntityName($name)
    {
        // Konvertiert den Gerätenamen in Kleinbuchstaben und ersetzt Leerzeichen durch Unterstriche
        return strtolower(str_replace(' ', '_', $name));
    }

    private function sendHttpResponse($response)
    {
        // Erstelle den HTTP-Response-Header
        $httpResponse = "HTTP/1.1 200 OK\r\n";
        $httpResponse .= "Content-Type: application/json\r\n";
        $httpResponse .= "Content-Length: " . strlen($response) . "\r\n";
        $httpResponse .= "Connection: close\r\n";
        $httpResponse .= "\r\n";  // Ende der Header
        $httpResponse .= $response;  // JSON-Daten anhängen

        // Lies die ClientIP und den ClientPort aus den Attributen
        $ClientIP = $this->ReadAttributeString('ClientIP');
        $ClientPort = $this->ReadAttributeInteger('ClientPort');

        $this->SendToServerSocket($httpResponse, $ClientIP, $ClientPort, 'legacy HTTP response');

        // Debug-Ausgabe für die gesendete Antwort
        $this->DebugLog('HaptiqueEmulator', 'Antwort gesendet: ' . $httpResponse, 0);
    }

    private function sendHttpResponseObject(HaptiqueHttpResponse $response, ?string $clientIP = null, ?int $clientPort = null): void
    {
        $statusCode = $response->getStatusCode();
        $statusText = match ($statusCode) {
            200 => 'OK',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            404 => 'Not Found',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            default => 'Response'
        };

        $body = $response->getBody();
        $headers = $response->getHeaders();
        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'application/json';
        }

        $httpResponse = "HTTP/1.1 $statusCode $statusText\r\n";
        foreach ($headers as $name => $value) {
            $httpResponse .= $name . ': ' . $value . "\r\n";
        }
        $httpResponse .= 'Content-Length: ' . strlen($body) . "\r\n";
        $httpResponse .= "Connection: close\r\n\r\n";
        $httpResponse .= $body;

        $ClientIP = $clientIP ?? $this->ReadAttributeString('ClientIP');
        $ClientPort = $clientPort ?? $this->ReadAttributeInteger('ClientPort');

        $this->HA_Debug(self::TOPIC_IO, '📤 Preparing HA HTTP response wire', self::LV_INFO, [
            'status' => $statusCode . ' ' . $statusText,
            'client' => $ClientIP . ':' . $ClientPort,
            'content_length' => strlen($body),
            'wire_length' => strlen($httpResponse)
        ]);

        $this->SendToServerSocket($httpResponse, $ClientIP, $ClientPort, 'HA HTTP response');

        $this->HA_Debug(self::TOPIC_IO, '📤 HA HTTP response sent', self::LV_INFO, [
            'status' => $statusCode . ' ' . $statusText,
            'client' => $ClientIP . ':' . $ClientPort,
            'content_length' => strlen($body),
            'wire_length' => strlen($httpResponse),
            'wire_hex_length' => strlen(bin2hex($httpResponse)),
            'body_preview' => mb_substr($body, 0, 1000)
        ]);
    }

    private function sendErrorResponse(int $code, string $message): void
    {
        $statusText = match ($code) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            404 => 'Not Found',
            500 => 'Internal Server Error',
            default => 'Error'
        };

        $body = json_encode(['error' => $message]);

        $httpResponse = "HTTP/1.1 $code $statusText\r\n";
        $httpResponse .= "Content-Type: application/json\r\n";
        $httpResponse .= "Content-Length: " . strlen($body) . "\r\n";
        $httpResponse .= "Connection: close\r\n\r\n";
        $httpResponse .= $body;

        $ClientIP = $this->ReadAttributeString('ClientIP');
        $ClientPort = $this->ReadAttributeInteger('ClientPort');

        $this->SendToServerSocket($httpResponse, $ClientIP, $ClientPort, 'legacy error response');

        $this->DebugLog('HaptiqueEmulator', "Fehler gesendet: $code $statusText – $message", 0);
    }

    private function SendToServerSocket(string $payload, string $clientIP, int $clientPort, string $context): void
    {
        $packet = [
            'DataID' => '{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}',
            'Buffer' => bin2hex($payload),
            'ClientIP' => $clientIP,
            'ClientPort' => $clientPort,
            'Type' => self::Socket_Data
        ];

        $this->HA_Debug(self::TOPIC_IO, '📤 Sending payload to ServerSocket', self::LV_INFO, [
            'context' => $context,
            'client' => $clientIP . ':' . $clientPort,
            'payload_length' => strlen($payload),
            'buffer_hex_length' => strlen($packet['Buffer']),
            'payload_preview' => mb_substr($payload, 0, 1000)
        ]);

        $this->SendDataToParent(json_encode($packet));
    }

    protected function NewIDLightSwitch($LightSwitches)
    {
        $values = [];
        foreach ($LightSwitches as $target) {
            if ($target['ID'] == 0) {
                $target['ID'] = $this->generateIdentifier();
            }
            $values[] = $target;
        }
        $this->UpdateFormField('LightSwitches', 'values', json_encode($values));
    }

    public function generateIdentifier()
    {
        $newID = $this->ReadAttributeInteger('NewIDLighSwicth');
        $this->WriteAttributeInteger('NewIDLighSwicth', $newID + 1);
        return $newID;
        // return sprintf('{%04X%04X-%04X-%04X-%04X-%04X%04X%04X}', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));

    }

    private function GetSymconFirstName(): string
    {
        $email = @IPS_GetLicensee();
        if (empty($email) || strpos($email, '@') === false) {
            return 'Symcon';
        }
        $username = explode('@', $email)[0];

        // Trenne an Punkt, Unterstrich oder Bindestrich
        $parts = preg_split('/[\._\-]/', $username);

        // Nimm den ersten sinnvollen Teil
        $first = $parts[0] ?? 'Symcon';

        // Großschreibung des ersten Buchstabens
        $first = ucfirst(strtolower($first));

        return $first;
    }

    private function RegisterMdnsService()
    {
        $this->MdnsDiscovery()->register(
            self::DEFAULT_WS_PORT,
            self::Haptique_Driver_Version,
            $this->ReadPropertyBoolean('EmulateHomeAssistantDiscovery')
        );
    }

    private function UnregisterMdnsService()
    {
        $this->MdnsDiscovery()->unregister();
    }

    // Methode zur Überprüfung des Variablentyps
    public function CheckVariableType(int $variableID)
    {
        $variable = IPS_GetVariable($variableID);

        // Überprüfe, ob es eine Bool-Variable ist (Schalter)
        if ($variable['VariableType'] == 0) {
            return "OK";  // Typ passt
        } else {
            return "Falscher Typ";  // Typ passt nicht
        }
    }

    public function LoadHarmonyDevices2()
    {
        // Initialisiere das TreeData-Array mit 20 fiktiven Geräten
        $treeData = [];

        // Erstelle 20 Testgeräte
        for ($i = 1; $i <= 20; $i++) {
            $treeData[] = [
                'id' => $i,
                'parent' => 0,
                'InstanceID' => 10000 + $i,
                'DeviceName' => 'Testgerät ' . $i,
                'Manufacturer' => 'Testhersteller ' . $i,
                'Model' => 'Testmodell ' . $i,
                'DeviceType' => 'Testgerätetyp ' . $i,
                'Gateway' => 'Test Gateway',
                'CommandName' => '',
                'expanded' => false,
                'checked' => ['visible' => true, 'value' => false] // Checkboxen standardmäßig deaktivieren
            ];
        }

        // Größe der Daten berechnen und debuggen
        $treeDataSize = strlen(json_encode($treeData));
        $this->DebugLog('TreeData Size', "Größe von Test-AVDevicesTree: " . $treeDataSize . " Bytes", 0);

        // Ergebnis als TreeData zurückgeben
        return $treeData;
    }

    public function LoadAVDevices(): array
    {
        $this->DebugLog(__FUNCTION__, "started", 0);

        // Setze die GUIDs für die verschiedenen Geräte
        $harmonyGUID = '{B0B4D0C2-192E-4669-A624-5D5E72DBB555}';
        $denonGUID = '{DC733830-533B-43CD-98F5-23FC2E61287F}';

        // Array für das TreeView-Daten
        $treeData = [];

        // Start-ID für Knoten
        $id = 1;

        // Den gespeicherten Tree-Zustand laden (falls vorhanden)
        $previousTree = json_decode($this->ReadAttributeString('previousTree'), true) ?? [];

        // Harmony-Geräte abrufen, wenn vorhanden
        if (IPS_ModuleExists($harmonyGUID)) {
            $harmonyInstanceIDs = IPS_GetInstanceListByModuleID($harmonyGUID);
            foreach ($harmonyInstanceIDs as $instanceID) {
                $deviceNode = $this->CreateDeviceNode(
                    $instanceID,
                    'Logitech Harmony Hub',
                    $id,
                    0, // Root
                    [
                        'DeviceName' => IPS_GetProperty($instanceID, 'devicename') ?? 'Unbekanntes Gerät',
                        'Manufacturer' => IPS_GetProperty($instanceID, 'Manufacturer') ?? 'Unbekannter Hersteller',
                        'Model' => IPS_GetProperty($instanceID, 'model') ?? 'Unbekanntes Modell',
                        'DeviceType' => IPS_GetProperty($instanceID, 'deviceTypeDisplayName') ?? 'Unbekannter Typ',
                    ],
                    $previousTree
                );
                $treeData[] = $deviceNode;
                $id++;
            }
        }

        // Denon-Geräte abrufen, wenn vorhanden
        if (IPS_ModuleExists($denonGUID)) {
            $denonInstanceIDs = IPS_GetInstanceListByModuleID($denonGUID);
            foreach ($denonInstanceIDs as $instanceID) {
                $avrTypeDenon = intval(IPS_GetProperty($instanceID, 'AVRTypeDenon') ?? 0);
                $zone = IPS_GetProperty($instanceID, 'Zone') ?? 0;

                $zones = [
                    0 => 'Main Zone',
                    1 => 'Zone 2',
                    2 => 'Zone 3',
                    6 => 'Select Zone'
                ];
                $zoneName = $zones[$zone] ?? 'Unbekannte Zone';

                $avrCapabilities = $this->DenonAVRCapabilitiesById($avrTypeDenon);
                if ($avrCapabilities) {
                    $deviceNode = $this->CreateDeviceNode(
                        $instanceID,
                        'Denon AVR ' . $zoneName,
                        $id,
                        0, // Root
                        [
                            'DeviceName' => $avrCapabilities['Name'] ?? 'Unbekanntes AVR Gerät',
                            'Manufacturer' => $avrCapabilities['Manufacturer'] ?? 'Unbekannter Hersteller',
                            'Model' => $avrCapabilities['Name'] ?? 'Unbekanntes Modell',
                            'DeviceType' => 'Denon AVR',
                        ],
                        $previousTree
                    );
                    $treeData[] = $deviceNode;
                    $id++;
                }
            }
        }


        // Größe der Tree-Daten bestimmen und Debug-Nachricht senden
        $treeDataSize = strlen(json_encode($treeData));
        $this->DebugLog('TreeData Size', "Größe von AVDevicesTree: " . $treeDataSize . " Bytes", 0);

        // Die Daten im Attribut speichern
        $this->WriteAttributeString('AVDevicesTree', json_encode($treeData));

        // Das Ergebnis im Formular zurückgeben
        return $treeData;
    }

    private function CreateDeviceNode(int $instanceID, string $gateway, int $id, int $parent, array $properties, array $previousTree): array
    {
        // Checked-Status aus dem vorherigen Tree übernehmen
        $checkedValue = $this->getCheckedStatus($instanceID, $previousTree);

        $this->DebugLog('CreateDeviceNode', "Erstelle Knoten für Instanz " . $instanceID . " Checked: " . json_encode($checkedValue), 0);

        return [
            'id' => $id,
            'parent' => $parent,
            'InstanceID' => $instanceID,
            'DeviceName' => $properties['DeviceName'] ?? '',
            'Manufacturer' => $properties['Manufacturer'] ?? '',
            'Model' => $properties['Model'] ?? '',
            'DeviceType' => $properties['DeviceType'] ?? '',
            'CommandName' => $properties['CommandName'] ?? '',
            'Gateway' => $gateway,
            'expanded' => false,
            'checked' => $checkedValue
        ];
    }

    // Private Hilfsfunktion
    private function getCheckedStatus($instanceID, $previousTree)
    {
        foreach ($previousTree as $prevItem) {
            if (isset($prevItem['InstanceID']) && $prevItem['InstanceID'] == $instanceID) {
                return (bool)$prevItem['checked']; // Rückgabe als Boolean
            }
        }
        return false; // Standard-Checked-Wert auf false setzen
    }

    public function DenonAVRCapabilitiesById(int $id)
    {
        // AVR-Fähigkeiten mit der Methode getAVRCapabilitiesByAVRId abrufen
        $avrInstance = new AVR();
        $avrCapabilities = $avrInstance->getAVRCapabilitiesByAVRId($id);

        if ($avrCapabilities) {
            // Wenn Fähigkeiten gefunden wurden, gebe diese aus
            $this->DebugLog('AVR Capabilities', json_encode($avrCapabilities), 0);
            return $avrCapabilities;
        } else {
            // Fehlerbehandlung, falls keine Fähigkeiten gefunden werden
            $this->DebugLog('AVR Capabilities', 'Keine Fähigkeiten gefunden für ID: ' . $id, 0);
            return false;
        }
    }


    public function LoadHarmonyDevicesLARGE()
    {
        $harmonyGUID = '{B0B4D0C2-192E-4669-A624-5D5E72DBB555}';

        // Prüfen, ob bereits Daten in der Property gespeichert sind
        $propertyData = json_decode($this->ReadAttributeString('AVDevicesTree'), true);

        // Initialisiere das TreeData-Array neu
        $treeData = [];

        // Start-ID für Knoten
        $id = 1;

        // Hole die Harmony-Instanzen
        $instanceIDs = IPS_GetInstanceListByModuleID($harmonyGUID);

        // Zähler für die Geräte begrenzen auf zwei
        $deviceLimit = 2;
        $deviceCount = 0;

        foreach ($instanceIDs as $instanceID) {
            if ($deviceCount >= $deviceLimit) {
                break; // Beenden, wenn die Geräteanzahl erreicht wurde
            }

            $devicename = IPS_GetProperty($instanceID, 'devicename');
            $manufacturer = IPS_GetProperty($instanceID, 'Manufacturer');
            $model = IPS_GetProperty($instanceID, 'model');
            $deviceTypeDisplayName = IPS_GetProperty($instanceID, 'deviceTypeDisplayName');

            // Befehle holen
            $commandset = IPS_GetProperty($instanceID, 'commandset');
            $commands = json_decode($commandset, true);
            $extractedCommands = $this->GetHarmonyDeviceCommands($instanceID);

            foreach ($commands as $group) {
                foreach ($group['function'] as $function) {
                    $actionData = json_decode($function['action'], true);
                    if (isset($actionData['command'])) {
                        $extractedCommands[] = [
                            'label' => $function['label'],
                            'command' => $actionData['command']
                        ];
                    }
                }
            }

            // Parent-Knoten erstellen
            $deviceNode = [
                'id' => $id,
                'parent' => 0,
                'InstanceID' => $instanceID,
                'DeviceName' => $devicename,
                'Manufacturer' => $manufacturer,
                'Model' => $model,
                'DeviceType' => $deviceTypeDisplayName,
                'Gateway' => 'Logitech Harmony Hub',
                'CommandName' => '',
                'expanded' => false,
                'checked' => ['visible' => true, 'value' => false] // Checkboxen standardmäßig deaktivieren
            ];

            $treeData[] = $deviceNode;
            $parentId = $id;
            $id++;

            // Child-Knoten (Befehle)
            foreach ($extractedCommands as $command) {
                $treeData[] = [
                    'id' => $id,
                    'parent' => $parentId,
                    'CommandName' => $command['label'],
                    'Command' => $command['command'],
                    'expanded' => false,
                    // 'checked' => ['visible' => false, 'value' => false] // Checkbox unsichtbar bei den Befehlen
                ];
                $id++;
            }
            $deviceCount++; // Erhöhe den Geräte-Zähler
        }

        // Prüfen, ob gespeicherte Daten in der Property vorhanden sind
        if (!empty($propertyData)) {
            // Werte aus der Property in das TreeData übernehmen
            foreach ($propertyData as $savedNode) {
                foreach ($treeData as &$node) {
                    if ($node['InstanceID'] == $savedNode['InstanceID']) {
                        // Aktualisiere den Wert 'checked'
                        $node['checked'] = $savedNode['checked'];
                    }
                }
            }
        }

        $treeDataSize = strlen(json_encode($treeData));   // Größe der Daten bestimmen
        $this->DebugLog('TreeData Size', "Größe von AVDevicesTree: " . $treeDataSize . " Bytes", 0);


        // Das Ergebnis im Formular zurückgeben
        return $treeData;
    }

    public function GetHarmonyDeviceCommands(int $instanceID): array
    {
        // Befehle holen
        $commandset = IPS_GetProperty($instanceID, 'commandset');
        $commands = json_decode($commandset, true);
        $extractedCommands = [];

        foreach ($commands as $group) {
            foreach ($group['function'] as $function) {
                $actionData = json_decode($function['action'], true);
                if (isset($actionData['command'])) {
                    $extractedCommands[] = [
                        'label' => $function['label'],
                        'command' => $actionData['command']
                    ];
                }
            }
        }
        return $extractedCommands;
    }

    public function FormFieldUpdate(string $field, string $parameter, string $value)
    {
        $this->UpdateFormField($field, $parameter, $value);
    }

    public function GetAV_Devices()
    {
        $AVDevicesTree = $this->ReadAttributeString('AVDevicesTree');
        if (empty($AVDevicesTree)) {
            $this->DebugLog('GetAV_Devices', "Keine Geräte im Attribut gespeichert", 0);
            return [];
        }
        if ($AVDevicesTree == "[]") {
            $this->DebugLog('GetAV_Devices', "Geräte für Attribut abrufen", 0);
            $AVDevicesTreeArray = $this->LoadAVDevices();
            return $AVDevicesTreeArray;
        }

        $treeDataSize = strlen($AVDevicesTree);   // Größe der Daten bestimmen
        $this->DebugLog('TreeData Size', "Größe von AVDevicesTree: " . $treeDataSize . " Bytes", 0);
        $decoded = json_decode($AVDevicesTree, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function DeleteAV_Devices()
    {
        return $this->WriteAttributeString('AVDevicesTree', '[]');
    }

    public function GetFormField(string $fieldName)
    {
        // Hole das komplette Formular der Instanz
        $form = json_decode($this->GetConfigurationForm(), true);
        $formDataSize = strlen(json_encode($form));   // Größe der Daten bestimmen
        $this->DebugLog('TreeData Size', "Größe von AVDevicesTree: " . $formDataSize . " Bytes", 0);

        // Suche rekursiv nach dem Feld
        $field = $this->FindFieldInItems($form['elements'], $fieldName);
        $this->DebugLog('GetFormField', json_encode($field), 0);
        $fieldDataSize = strlen(json_encode($field));   // Größe der Daten bestimmen
        $this->DebugLog('TreeData Size', "Größe von AVDevicesTree: " . $fieldDataSize . " Bytes", 0);

        // Rückgabe des Feldes oder null, wenn es nicht gefunden wurde
        return $field;
    }

    private function FindFieldInItems(array $items, string $fieldName)
    {
        foreach ($items as $item) {
            // Wenn das Feld gefunden wurde, gib es zurück
            if (isset($item['name']) && $item['name'] === $fieldName) {
                return $item;
            }

            // Wenn das Item "items" enthält, rekursiv weiter durchsuchen
            if (isset($item['items']) && is_array($item['items'])) {
                $result = $this->FindFieldInItems($item['items'], $fieldName);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        // Wenn das Feld nicht gefunden wurde, gib null zurück
        return null;
    }

    /**
     * Bereitet die Sequenzliste formgerecht auf – inkl. true/false für Favoritenspalte.
     * @return array
     */
    public function GetFormCompatibleSequences(): array
    {
        $raw = $this->RS90_GetSequences();

        if (!is_array($raw)) {
            return [];  // Fehler abfangen: Rückgabe ist nicht wie erwartet
        }

        return array_map(function ($seq) {
            return [
                'name' => $seq['name'] ?? '',
                'favoriteInRemoteIds' => !empty($seq['favoriteInRemoteIds']) // true / false
            ];
        }, $raw);
    }

    /**
     * Gibt die Raumliste formatiert für das Formular zurück.
     * @return array
     */
    public function RS90_GetRoomsFormatted(): array
    {
        $rooms = $this->RS90_GetRooms();
        if (!is_array($rooms)) {
            $this->DebugLog(__FUNCTION__, 'RS90_GetRooms returned no array', 0);
            return [];
        }

        $result = [];
        foreach ($rooms as $room) {
            if (!is_array($room)) {
                continue;
            }
            $result[] = [
                'name' => $room['name'] ?? '',
                'id' => $room['id'] ?? ''
            ];
        }
        return $result;
    }

    /**
     * Gibt die Geräteübersicht formatiert für das Formular zurück.
     * @return array
     */
    public function RS90_GetDeviceDashboardFormatted(): array
    {
        $raw = $this->RS90_GetDeviceDashboard();
        if (!is_array($raw)) {
            $this->DebugLog(__FUNCTION__, 'RS90_GetDeviceDashboard returned no array', 0);
            return [];
        }

        $result = [];

        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $categories = [];
            $devices = isset($entry['devices']) && is_array($entry['devices']) ? $entry['devices'] : [];

            foreach ($devices as $device) {
                if (!is_array($device)) {
                    continue;
                }

                $categoryName = (string) ($device['categoryName'] ?? '');
                $count = (string) ($device['count'] ?? '');
                $categories[] = $categoryName . ($count !== '' ? ' (' . $count . ')' : '');
            }

            $result[] = [
                'label' => $entry['label'] ?? '',
                'isIntegrated' => $entry['isIntegrated'] ?? false,
                'categoryList' => implode(', ', $categories)
            ];
        }

        return $result;
    }


    /**
     * Gibt die Liste der Custom URL Geräte formatiert für das Formular zurück.
     * @return array
     */
    private function GetCustomURLDeviceListFormatted(): array
    {
        $deviceList = $this->RS90_GetCustomURLDevices();
        if (!is_array($deviceList)) {
            $this->DebugLog(__FUNCTION__, 'RS90_GetCustomURLDevices returned no array', 0);
            return [];
        }

        $records = isset($deviceList['records']) && is_array($deviceList['records']) ? $deviceList['records'] : [];
        $formattedDevices = [];

        foreach ($records as $device) {
            if (!is_array($device)) {
                continue;
            }

            $device_id = $device['id'] ?? '';
            $commands = [];

            $formattedDevices[] = [
                'name' => $device['name'] ?? '',
                'device_id' => $device_id,
                'base_url' => $device['ip'] ?? 'http://192.168.0.1',
                'commands' => $commands
            ];
        }

        $this->DebugLog(__FUNCTION__, json_encode($formattedDevices), 0);
        return $formattedDevices;
    }

    /** Dynamic values fetch for commands list
     * @param string $device_id
     * @return array
     */
    private function GetCommandListForm(): array
    {
        $device_id = $this->Get_Device_id();
        $this->DebugLog('GetCommandListForm', "Device ID: " . $device_id, 0);
        $values = [];
        $device_commands = $this->RS90_GetDeviceCommands($device_id);
        if (!is_array($device_commands) || !isset($device_commands['controls']) || !is_array($device_commands['controls'])) {
            return [];
        }
        foreach ($device_commands['controls'] as $control) {
            $refData = isset($control['referenceData']) && is_array($control['referenceData']) ? $control['referenceData'] : [];
            $method = strtoupper($control['referenceData']['method'] ?? '');
            $values[] = [
                'name' => $control['name'] ?? '',
                'method' => $refData['method'] ?? '',
            ];

            if (in_array($method, ['ADB', 'TELNET'])) {
                $values['adress_port'] = $control['referenceData']['ip'] ?? '';
                $values['command'] = $control['referenceData']['command'] ?? '';
            } elseif (in_array($method, ['POST', 'GET'])) {
                $values['url'] = $control['referenceData']['url'] ?? '';
            }

            if (in_array($method, ['ADB', 'TELNET'])) {
                $this->UpdateFormField('adress_port', 'visible', true);
                $this->UpdateFormField('command', 'visible', true);
                $this->UpdateFormField('url', 'visible', false);
            } elseif (in_array($method, ['POST', 'GET'])) {
                $this->UpdateFormField('adress_port', 'visible', false);
                $this->UpdateFormField('command', 'visible', false);
                $this->UpdateFormField('url', 'visible', true);
            } else {
                $this->UpdateFormField('adress_port', 'visible', false);
                $this->UpdateFormField('command', 'visible', false);
                $this->UpdateFormField('url', 'visible', false);
            }
        }
        $this->DebugLog('GetCommandListForm', "Values: " . json_encode($values), 0);
        return $values;
    }

    public function Set_Current_Device(string $device_id): string
    {
        $this->DebugLog("OnEdit", "trigger with device id " . $device_id, 0);
        $this->WriteAttributeString("current_device_id", $device_id);
        $this->DebugLog('Set_Device_id', json_encode($device_id), 0);
        return $device_id;
    }

    public function Get_Device_id(): string
    {
        $device_id = $this->ReadAttributeString("current_device_id");
        $this->DebugLog('Get_Device_id', json_encode($device_id), 0);
        return $device_id;
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
        $this->DebugLog('Form Size', "Configuration form size: " . $formSize . " bytes", 0);
        return json_encode($form);
    }

    /**
     * return form configurations on configuration step
     *
     * @return array
     */
    protected function FormHead(): array
    {

        $form = [
            [
                'type' => 'RowLayout',
                'items' => [
                    [
                        'type' => 'Image',
                        'name' => 'CantataLogo',
                        'image' => 'data:image/svg+xml;base64, PHN2ZyB3aWR0aD0iMTU4IiBoZWlnaHQ9IjU2IiB2aWV3Qm94PSIwIDAgMTU4IDU2IiBmaWxsPSJub25lIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPgo8cGF0aCBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGNsaXAtcnVsZT0iZXZlbm9kZCIgZD0iTTQ2LjU1NjUgNDguMjczQzQ0LjQ3NTkgNTAuMTcxNiA0Mi4xODcyIDUxLjcwNiAzOS43Njg0IDUyLjg1MDRDMzkuNTg2NCA1Mi45NTQ0IDM5LjM1MjMgNTIuODUwNCAzOS4yNzQzIDUyLjY0MjNMMzIuMDE4MSAzNS4yNDMxQzMxLjk2NjEgMzUuMTEzIDMyLjAxODEgMzQuOTU3IDMyLjE0ODEgMzQuOTA1QzMyLjQwODIgMzQuNzQ4OSAzMi42NjgzIDM0LjU2NjkgMzIuOTI4NCAzNC4zODQ4QzMzLjAzMjQgMzQuMzA2OCAzMy4xODg1IDM0LjMwNjggMzMuMjkyNSAzNC40MTA4TDQ2LjYwODUgNDcuNzI2OEM0Ni43MTI1IDQ3Ljg4MjkgNDYuNzEyNSA0OC4xNDMgNDYuNTU2NSA0OC4yNzNaTTMwLjI3NTYgMzUuOTk3M0MzMC4yMjM2IDM1Ljg2NzMgMzAuMDY3NSAzNS43ODkyIDI5LjkzNzUgMzUuODQxM0MyOS42MjU0IDM1LjkxOTMgMjkuMzEzMyAzNS45OTczIDI5LjAyNzIgMzYuMDQ5M0MyOC44OTcyIDM2LjA3NTMgMjguNzkzMSAzNi4xNzk0IDI4Ljc5MzEgMzYuMzM1NEwyOC44NDUyIDU1LjE5MTFDMjguODQ1MiA1NS4zOTkxIDI5LjAyNzIgNTUuNTgxMiAyOS4yMzUzIDU1LjU1NTJDMzEuOTE0MSA1NS40MjUxIDM0LjYxODkgNTQuODc5IDM3LjI3MTcgNTMuOTE2N0MzNy40Nzk4IDUzLjgzODcgMzcuNTU3OCA1My42MzA2IDM3LjQ3OTcgNTMuNDIyNUwzMC4yNzU2IDM1Ljk5NzNaTTI2LjcxMjUgMzYuMDQ5M0MyNi40MDA0IDM2LjAyMzMgMjYuMDg4MyAzNS45NDUzIDI1Ljc3NjIgMzUuODY3M0MyNS42NDYyIDM1Ljg0MTMgMjUuNDkwMSAzNS44OTMzIDI1LjQzODEgMzYuMDIzM0wxOC4yNiA1My40NzQ2QzE4LjE4MTkgNTMuNjgyNiAxOC4yODYgNTMuODkwNyAxOC40NjggNTMuOTY4N0MyMS4wMTY4IDU0Ljg3OSAyMy42OTU2IDU1LjM5OTEgMjYuNTMwNSA1NS41MjkyQzI2LjczODUgNTUuNTI5MiAyNi45MjA2IDU1LjM3MzEgMjYuOTIwNiA1NS4xNjUxVjM2LjMzNTRDMjYuOTQ2NiAzNi4yMDU0IDI2Ljg0MjYgMzYuMDc1MyAyNi43MTI1IDM2LjA0OTNaTTIzLjYxNzYgMzUuMDA5QzIzLjMzMTUgMzQuODUzIDIzLjA3MTQgMzQuNjcwOSAyMi44Mzc0IDM0LjQ4ODlDMjIuNzMzMyAzNC40MTA4IDIyLjU3NzMgMzQuNDEwOCAyMi40NzMyIDM0LjUxNDlMOS4xODMyNSA0Ny44ODI5QzkuMDI3MiA0OC4wMzg5IDkuMDI3MiA0OC4yNzMgOS4yMDkyNSA0OC40MjlDMTEuMjExOSA1MC4yMjM2IDEzLjUwMDUgNTEuNzU4IDE2LjA0OTMgNTIuOTU0NEMxNi4yMzE0IDUzLjAzMjQgMTYuNDY1NCA1Mi45NTQ0IDE2LjU0MzUgNTIuNzQ2M0wyMy43NzM2IDM1LjM0NzFDMjMuNzk5NiAzNS4yNDMxIDIzLjc0NzYgMzUuMDg3IDIzLjYxNzYgMzUuMDA5Wk0yMS4xNzI5IDMyLjg3NjRDMjAuOTY0OCAzMi42MTYzIDIwLjgwODcgMzIuMzU2MiAyMC42NTI3IDMyLjA5NjFDMjAuNTc0NyAzMS45NjYxIDIwLjQ0NDYgMzEuOTQwMSAyMC4zMTQ2IDMxLjk2NjFMMi45MTUzNiAzOS4yMjIzQzIuNzA3MyAzOS4zMDAzIDIuNjI5MjggMzkuNTM0NCAyLjcwNzMgMzkuNzE2NEMzLjg1MTY0IDQyLjEzNTIgNS4zODYxIDQ0LjQ0OTggNy4yODQ2NyA0Ni41MDQ1QzcuNDQwNzIgNDYuNjYwNSA3LjY3NDc5IDQ2LjY2MDUgNy44MzA4NCA0Ni41MDQ1TDIxLjE3MjkgMzMuMTg4NUMyMS4yNTA5IDMzLjEzNjQgMjEuMjUwOSAzMi45ODA0IDIxLjE3MjkgMzIuODc2NFpNMTkuNzE2NCAyOS45NjM1QzE5LjYzODQgMjkuNjUxNCAxOS41NjA0IDI5LjMzOTMgMTkuNTA4NCAyOS4wNTMyQzE5LjQ4MjMgMjguOTIzMiAxOS4zNzgzIDI4LjgxOTEgMTkuMjIyMyAyOC44MTkxTDAuMzY2NTk2IDI4Ljg3MTJDMC4xNTg1MzQgMjguODcxMiAtMC4wMjM1MjMyIDI5LjA1MzIgMC4wMDI0ODQ1OCAyOS4yNjEzQzAuMTMyNTI0IDMxLjk0MDEgMC42Nzg2ODkgMzQuNjQ0OSAxLjY0MDk4IDM3LjI5NzdDMS43MTkgMzcuNTA1OCAxLjkyNzA2IDM3LjU4MzggMi4xMzUxMiAzNy41MDU4TDE5LjUzNDQgMzAuMzAxNkMxOS42OTA0IDMwLjI0OTYgMTkuNzQyNCAzMC4wOTM1IDE5LjcxNjQgMjkuOTYzNVpNMC4zOTI2MDIgMjYuOTQ2NkgxOS4yMjIzQzE5LjM1MjMgMjYuOTQ2NiAxOS40ODIzIDI2Ljg0MjYgMTkuNTA4NCAyNi43MTI1QzE5LjUzNDQgMjYuNDAwNCAxOS42MTI0IDI2LjA4ODMgMTkuNjkwNCAyNS43NzYyQzE5LjcxNjQgMjUuNjQ2MiAxOS42NjQ0IDI1LjQ5MDEgMTkuNTM0NCAyNS40MzgxTDIuMDgzMTEgMTguMjZDMS44NzUwNSAxOC4xODE5IDEuNjY2OTkgMTguMjg2IDEuNTg4OTYgMTguNDY4QzAuNjc4NjkgMjEuMDE2OCAwLjE1ODUzMyAyMy42OTU2IDAuMDI4NDk0IDI2LjUzMDVDMC4wMDI0ODYxNyAyNi43NjQ1IDAuMTg0NTM5IDI2Ljk0NjYgMC4zOTI2MDIgMjYuOTQ2NlpNMi43ODUzMiAxNi41MTc0TDIwLjE4NDUgMjMuNzQ3NkMyMC4zMTQ2IDIzLjc5OTYgMjAuNDcwNiAyMy43NDc2IDIwLjU0ODcgMjMuNjE3NkMyMC43MDQ3IDIzLjMzMTUgMjAuODg2OCAyMy4wNzE0IDIxLjA2ODggMjIuODM3NEMyMS4xNDY4IDIyLjczMzMgMjEuMTQ2OCAyMi41NzczIDIxLjA0MjggMjIuNDczMkw3LjY3NDc5IDkuMTgzMjVDNy41MTg3NCA5LjAyNzIgNy4yODQ2OCA5LjAyNzIgNy4xMjg2MyA5LjIwOTI1QzUuMzM0MDkgMTEuMjExOSAzLjc5OTYzIDEzLjUwMDUgMi42MDMyNyAxNi4wNDkzQzIuNDk5MjQgMTYuMjA1NCAyLjYwMzI3IDE2LjQzOTQgMi43ODUzMiAxNi41MTc0Wk0yMi4zMTcyIDIxLjE0NjhDMjIuNDIxMiAyMS4yNTA5IDIyLjU3NzMgMjEuMjUwOSAyMi42ODEzIDIxLjE3MjlDMjIuOTQxNCAyMC45NjQ4IDIzLjIwMTUgMjAuODA4NyAyMy40NjE1IDIwLjYyNjdDMjMuNTkxNiAyMC41NDg3IDIzLjYxNzYgMjAuNDE4NiAyMy41OTE2IDIwLjI4ODZMMTYuMzM1NCAyLjg4OTM1QzE2LjI1NzQgMi42ODEyOSAxNi4wMjMzIDIuNjAzMjcgMTUuODQxMiAyLjY4MTI5QzEzLjQyMjUgMy44MjU2MyAxMS4xMDc4IDUuMzYwMSA5LjA1MzIxIDcuMjU4NjdDOC44OTcxNiA3LjQxNDcyIDguODk3MTYgNy42NDg3OCA5LjA1MzIxIDcuODA0ODNMMjIuMzE3MiAyMS4xNDY4Wk0yNS4yNTYxIDE5LjU2MDRDMjUuMzA4MSAxOS42OTA0IDI1LjQ2NDEgMTkuNzY4NCAyNS41OTQyIDE5LjcxNjRDMjUuOTA2MyAxOS42Mzg0IDI2LjIxODQgMTkuNTYwNCAyNi41MDQ1IDE5LjUwODNDMjYuNjM0NSAxOS40ODIzIDI2LjczODUgMTkuMzc4MyAyNi43Mzg1IDE5LjIyMjNMMjYuNjg2NSAwLjM2NjU5NkMyNi42ODY1IDAuMTU4NTM0IDI2LjUwNDQgLTAuMDIzNTIzMiAyNi4yOTY0IDAuMDAyNDg0NThDMjMuNjE3NiAwLjEzMjUyNCAyMC45MTI4IDAuNjc4Njg5IDE4LjI2IDEuNjQwOThDMTguMDUxOSAxLjcxOSAxNy45NzM5IDEuOTI3MDYgMTguMDUxOSAyLjEzNTEyTDI1LjI1NjEgMTkuNTYwNFpNMjkuNzgxNCAxOS42NjQ0QzI5LjkxMTUgMTkuNjkwNCAzMC4wNjc1IDE5LjYzODQgMzAuMTE5NSAxOS41MDgzTDM3LjI5NzcgMi4wNTcxQzM3LjM3NTcgMS44NDkwNCAzNy4yNzE3IDEuNjQwOTggMzcuMDg5NiAxLjU2Mjk1QzM0LjU0MDkgMC42NTI2OCAzMS44NjIxIDAuMTMyNTI0IDI5LjAyNzIgMC4wMDI0ODQ1OEMyOC44MTkxIDAuMDAyNDg0NTggMjguNjM3MSAwLjE1ODUzNCAyOC42MzcxIDAuMzY2NTk2VjE5LjE5NjNDMjguNjM3MSAxOS4zNTIzIDI4Ljc0MTEgMTkuNDU2MyAyOC44OTcyIDE5LjQ4MjNDMjkuMTU3MiAxOS41MzQ0IDI5LjQ2OTMgMTkuNjEyNCAyOS43ODE0IDE5LjY2NDRaTTMxLjk0MDEgMjAuNTQ4N0MzMi4yMjYyIDIwLjcwNDcgMzIuNDg2MyAyMC44ODY4IDMyLjcyMDMgMjEuMDY4OEMzMi44MjQ0IDIxLjE0NjggMzIuOTgwNCAyMS4xNDY4IDMzLjA4NDQgMjEuMDQyOEw0Ni4zNzQ0IDcuNjc0NzlDNDYuNTMwNSA3LjUxODc0IDQ2LjUzMDUgNy4yODQ2OCA0Ni4zNzQ0IDcuMTI4NjNDNDQuMzcxOCA1LjMzNDA5IDQyLjA4MzEgMy43OTk2MyAzOS41MzQ0IDIuNjAzMjdDMzkuMzUyMyAyLjUyNTI0IDM5LjExODIgMi42MDMyNyAzOS4wNDAyIDIuODExMzNMMzEuODEgMjAuMjEwNkMzMS43NTggMjAuMzE0NiAzMS44MSAyMC40NzA2IDMxLjk0MDEgMjAuNTQ4N1oiIGZpbGw9InVybCgjcGFpbnQwX2xpbmVhcl8xMDFfNjUpIi8+CjxwYXRoIGQ9Ik00NC4zOTc4IDU1LjU1NTJDNDQuMzQ1OCA1NS41MDMyIDQ0LjI2NzggNTUuNTAzMiA0NC4yMTU4IDU1LjQ3NzJDNDMuNjE3NiA1NS4yNjkxIDQzLjIwMTUgNTQuOTA1IDQyLjk5MzQgNTQuMzA2OEM0Mi41NzczIDUzLjE4ODUgNDMuMjc5NSA1MS45NjYxIDQ0LjQyMzggNTEuNzU4QzQ1LjQxMjEgNTEuNTc2IDQ2LjI3MDQgNTIuMTQ4MiA0Ni41ODI1IDUzLjAwNjRDNDYuNjA4NSA1My4xMTA1IDQ2LjYzNDUgNTMuMjE0NSA0Ni42NjA1IDUzLjMxODVDNDYuNjYwNSA1My4zNzA1IDQ2LjY4NjUgNTMuNDIyNSA0Ni42ODY1IDUzLjQ3NDZDNDYuNjg2NSA1My42MDQ2IDQ2LjY4NjUgNTMuNzM0NiA0Ni42ODY1IDUzLjg2NDdDNDYuNjg2NSA1My45MTY3IDQ2LjY2MDUgNTMuOTY4NyA0Ni42NjA1IDUzLjk5NDdDNDYuNjYwNSA1NC4wOTg3IDQ2LjYzNDUgNTQuMjAyOCA0Ni41ODI1IDU0LjMwNjhDNDYuMzQ4NCA1NC45MzEgNDUuOTA2MyA1NS4zMjExIDQ1LjI1NjEgNTUuNTAzMkM0NS4yMDQxIDU1LjUwMzIgNDUuMTc4MSA1NS41MjkyIDQ1LjEyNjEgNTUuNTU1MkM0NC44NjYgNTUuNTU1MiA0NC42MzE5IDU1LjU1NTIgNDQuMzk3OCA1NS41NTUyWk00Ni4zNzQ0IDUzLjY1NjZDNDYuNDAwNCA1Mi44NTA0IDQ1Ljc1MDIgNTIuMDQ0MSA0NC43ODc5IDUyLjAxODFDNDMuOTAzNyA1Mi4wMTgxIDQzLjE3NTUgNTIuNzIwMyA0My4xNzU1IDUzLjYzMDZDNDMuMTc1NSA1NC42MTg5IDQzLjk4MTcgNTUuMjQzMSA0NC43NjE5IDU1LjI0MzFDNDUuNjQ2MiA1NS4yNjkxIDQ2LjM3NDQgNTQuNTQwOSA0Ni4zNzQ0IDUzLjY1NjZaIiBmaWxsPSIjRkJCMDNDIi8+CjxwYXRoIGQ9Ik00NC4xNjM4IDUyLjc5ODRDNDQuMzQ1OCA1Mi43NzI0IDQ0LjkxOCA1Mi43NzI0IDQ1LjA0OCA1Mi43OTg0QzQ1LjEgNTIuNzk4NCA0NS4xMjYxIDUyLjgyNDQgNDUuMTc4MSA1Mi44NTA0QzQ1LjM2MDEgNTIuOTI4NCA0NS40NjQyIDUzLjA4NDQgNDUuNDY0MiA1My4yNjY1QzQ1LjQ5MDIgNTMuNDc0NiA0NS40MTIxIDUzLjYzMDYgNDUuMjMwMSA1My43NjA2QzQ1LjIwNDEgNTMuNzg2NyA0NS4xNTIxIDUzLjgxMjcgNDUuMSA1My44Mzg3QzQ1LjEyNjEgNTMuODY0NyA0NS4xMjYxIDUzLjkxNjcgNDUuMTUyMSA1My45NDI3QzQ1LjIzMDEgNTQuMDcyNyA0NS4zMDgxIDU0LjIyODggNDUuNDEyMSA1NC4zNTg4QzQ1LjQzODEgNTQuMzg0OCA0NS40MzgxIDU0LjQzNjkgNDUuNDY0MiA1NC40ODg5QzQ1LjMwODEgNTQuNTE0OSA0NS4xNzgxIDU0LjUxNDkgNDUuMDIyIDU0LjQ4ODlDNDQuOTQ0IDU0LjM1ODggNDQuODkyIDU0LjI1NDggNDQuODE0IDU0LjEyNDhDNDQuNzg4IDU0LjA0NjcgNDQuNzM1OSA1My45OTQ3IDQ0LjY4MzkgNTMuOTE2N0M0NC42NTc5IDUzLjg5MDcgNDQuNjMxOSA1My44NjQ3IDQ0LjYwNTkgNTMuODY0N0M0NC41NTM5IDUzLjg2NDcgNDQuNTUzOSA1My45MTY3IDQ0LjU1MzkgNTMuOTQyN0M0NC41NTM5IDU0LjA3MjcgNDQuNTUzOSA1NC4yMjg4IDQ0LjU1MzkgNTQuMzU4OEM0NC41NTM5IDU0LjM4NDggNDQuNTUzOSA1NC40MzY5IDQ0LjU1MzkgNTQuNDg4OUM0NC40MjM4IDU0LjUxNDkgNDQuMjkzOCA1NC41MTQ5IDQ0LjE2MzggNTQuNDg4OUM0NC4xMzc4IDU0LjMzMjggNDQuMTM3OCA1My4zMTg1IDQ0LjEzNzggNTIuOTAyNEM0NC4xMzc4IDUyLjg3NjQgNDQuMTM3OCA1Mi44NTA0IDQ0LjE2MzggNTIuNzk4NFpNNDQuNTUzOSA1My41MjY2QzQ0LjY4MzkgNTMuNTc4NiA0NC44MTQgNTMuNTc4NiA0NC45NDQgNTMuNTI2NkM0NS4wMjIgNTMuNTAwNiA0NS4wNDggNTMuNDIyNSA0NS4wNDggNTMuMzQ0NUM0NS4wNDggNTMuMjY2NSA0NS4wMjIgNTMuMTg4NSA0NC45NDQgNTMuMTYyNUM0NC44MTQgNTMuMTM2NSA0NC43MDk5IDUzLjExMDUgNDQuNTc5OSA1My4xNjI1QzQ0LjU1MzkgNTMuMjkyNSA0NC41NTM5IDUzLjM5NjUgNDQuNTUzOSA1My41MjY2WiIgZmlsbD0iI0ZCQjAzQyIvPgo8cGF0aCBkPSJNNTAuOTc3OCAzMS4yMzc5TDQ3LjQxNDcgMjcuNjc0OFYxNi45NTk2SDYyLjU3NzNMNjAuNzgyOCAyMC41MjI3SDUwLjk1MThWMjUuODgwM0w1Mi43NDY0IDI3LjY3NDhINjEuNjY3VjMxLjIzNzlINTAuOTc3OFpNNzQuMTc2OCAyMC41MjI3VjI3LjMxMDdMNjguODE5MiAyNC4wODU3VjI4LjA5MDlMNzcuNzM5OSAzMy40NDg1VjE2LjkzMzZINjMuNDYxNlYzMS4yMTE5SDY3LjAyNDZWMjAuNDk2Nkw3NC4xNzY4IDIwLjUyMjdaTTc5LjUzNDQgMTQuMjgwOFYzMS4yMzc5SDgzLjA5NzVWMjMuMjAxNUw5My44MTI3IDMzLjkxNjdWMTYuOTU5Nkg5MC4yNDk2VjI0Ljk5Nkw3OS41MzQ0IDE0LjI4MDhaTTk1LjU4MTIgMTYuOTU5NlYyMC41MjI3SDEwMC45MzlWMzIuMTIyMUwxMDQuNTAyIDMwLjMyNzZWMjAuNTIyN0gxMDguOTc1TDExMC43NDQgMTYuOTU5Nkg5NS41ODEyWk0xMjIuMzQzIDIwLjUyMjdWMjcuMzEwN0wxMTYuOTg2IDI0LjA4NTdWMjguMDkwOUwxMjUuOTA2IDMzLjQ0ODVWMTYuOTMzNkgxMTEuNjU0VjMxLjIxMTlIMTE1LjIxN1YyMC40OTY2TDEyMi4zNDMgMjAuNTIyN1pNMTI3LjcwMSAxNi45NTk2VjIwLjUyMjdIMTMzLjA1OVYzMi4xMjIxTDEzNi42MjIgMzAuMzI3NlYyMC41MjI3SDE0MS4wNjlMMTQyLjgzNyAxNi45NTk2SDEyNy43MDFaTTE1NC40MzcgMjAuNTIyN1YyNy4zMTA3TDE0OS4wNzkgMjQuMDg1N1YyOC4wOTA5TDE1OCAzMy40NDg1VjE2LjkzMzZIMTQzLjcyMlYzMS4yMTE5SDE0Ny4yODVWMjAuNDk2NkwxNTQuNDM3IDIwLjUyMjdaIiBmaWxsPSJ3aGl0ZSIvPgo8cGF0aCBkPSJNNTAuNjY1NyAzOS41MzQ0SDUxLjM0MTlWMzkuNTYwNEM1MS4zNDE5IDQwLjA1NDUgNTEuMTg1OSA0MC40NzA3IDUwLjgyMTggNDAuODA4OEM1MC40NTc3IDQxLjE0NjkgNDkuOTg5NSA0MS4zMDI5IDQ5LjM5MTMgNDEuMzAyOUM0OC43OTMyIDQxLjMwMjkgNDguMzI1IDQxLjA5NDggNDcuOTYwOSA0MC42Nzg3QzQ3LjU5NjggNDAuMjYyNiA0Ny4zODg3IDM5LjcxNjQgNDcuMzg4NyAzOS4wNjYyVjM4LjEyOTlDNDcuMzg4NyAzNy40Nzk4IDQ3LjU3MDggMzYuOTMzNiA0Ny45NjA5IDM2LjUxNzVDNDguMzI1IDM2LjEwMTMgNDguODE5MiAzNS44OTMzIDQ5LjQxNzMgMzUuODkzM0M1MC4wMTU1IDM1Ljg5MzMgNTAuNDgzNyAzNi4wNDkzIDUwLjg0NzggMzYuMzYxNEM1MS4yMTE5IDM2LjY3MzUgNTEuMzY3OSAzNy4wODk2IDUxLjM2NzkgMzcuNjA5OFYzNy42MzU4SDUwLjY2NTdDNTAuNjY1NyAzNy4yNzE3IDUwLjU2MTcgMzYuOTU5NiA1MC4zMjc2IDM2Ljc1MTVDNTAuMTE5NiAzNi41NDM1IDQ5LjgwNzUgMzYuNDM5NCA0OS40MTczIDM2LjQzOTRDNDkuMDI3MiAzNi40Mzk0IDQ4LjcxNTEgMzYuNTk1NSA0OC40ODExIDM2LjkwNzZDNDguMjQ3IDM3LjIxOTcgNDguMTE3IDM3LjYwOTggNDguMTE3IDM4LjEwMzlWMzkuMDQwMkM0OC4xMTcgMzkuNTM0NCA0OC4yNDcgMzkuOTI0NSA0OC40ODExIDQwLjIzNjZDNDguNzE1MSA0MC41NDg3IDQ5LjAyNzIgNDAuNzA0NyA0OS40MTczIDQwLjcwNDdDNDkuODA3NSA0MC43MDQ3IDUwLjExOTYgNDAuNjAwNyA1MC4zMjc2IDQwLjM5MjZDNTAuNTYxNyA0MC4yMTA2IDUwLjY2NTcgMzkuODk4NSA1MC42NjU3IDM5LjUzNDRaIiBmaWxsPSJ3aGl0ZSIvPgo8cGF0aCBkPSJNNTIuOTAyNCAzOS4yMjIzVjM5LjMwMDNDNTIuOTAyNCAzOS43NDI0IDUyLjk4MDQgNDAuMDgwNSA1My4xNjI1IDQwLjM0MDZDNTMuMzQ0NSA0MC42MDA3IDUzLjYwNDYgNDAuNzMwNyA1My45NDI3IDQwLjczMDdDNTQuMjgwOCA0MC43MzA3IDU0LjU0MDkgNDAuNjAwNyA1NC43MjMgNDAuMzQwNkM1NC45MDUgNDAuMDgwNSA1NC45ODMgMzkuNzQyNCA1NC45ODMgMzkuMzAwM1YzOS4yMjIzQzU0Ljk4MyAzOC44MDYyIDU0LjkwNSAzOC40NDIgNTQuNzIzIDM4LjE4MkM1NC41NDA5IDM3LjkyMTkgNTQuMjgwOCAzNy43OTE4IDUzLjk0MjcgMzcuNzkxOEM1My42MDQ2IDM3Ljc5MTggNTMuMzQ0NSAzNy45MjE5IDUzLjE4ODUgMzguMTgyQzUyLjk4MDQgMzguNDQyIDUyLjkwMjQgMzguODA2MiA1Mi45MDI0IDM5LjIyMjNaTTUyLjE3NDIgMzkuMzAwM1YzOS4yMjIzQzUyLjE3NDIgMzguNjI0MSA1Mi4zMzAyIDM4LjE1NiA1Mi42NDIzIDM3Ljc5MThDNTIuOTU0NCAzNy40Mjc3IDUzLjM3MDUgMzcuMjQ1NyA1My45MTY3IDM3LjI0NTdDNTQuNDYyOSAzNy4yNDU3IDU0Ljg3OSAzNy40Mjc3IDU1LjE5MTEgMzcuNzkxOEM1NS41MDMyIDM4LjE1NiA1NS42NTkyIDM4LjYyNDEgNTUuNjU5MiAzOS4yMjIzVjM5LjMwMDNDNTUuNjU5MiAzOS44OTg1IDU1LjUwMzIgNDAuMzY2NiA1NS4xOTExIDQwLjczMDdDNTQuODc5IDQxLjA5NDggNTQuNDYyOSA0MS4yNzY5IDUzLjkxNjcgNDEuMjc2OUM1My4zNzA1IDQxLjI3NjkgNTIuOTU0NCA0MS4wOTQ4IDUyLjY0MjMgNDAuNzMwN0M1Mi4zNTYyIDQwLjM2NjYgNTIuMTc0MiAzOS44OTg1IDUyLjE3NDIgMzkuMzAwM1oiIGZpbGw9IndoaXRlIi8+CjxwYXRoIGQ9Ik01Ny40Mjc4IDQxLjE5ODlINTYuNzI1NVYzNy4yOTc3SDU3LjM0OTdMNTcuNDAxNyAzNy44MTc5QzU3LjY2MTggMzcuNDI3NyA1OC4wNTE5IDM3LjIxOTcgNTguNTk4MSAzNy4yMTk3QzU5LjExODMgMzcuMjE5NyA1OS40ODI0IDM3LjQ1MzcgNTkuNjY0NCAzNy45NDc5QzU5LjkyNDUgMzcuNDc5OCA2MC4zNDA2IDM3LjIxOTcgNjAuODYwOCAzNy4yMTk3QzYxLjI3NjkgMzcuMjE5NyA2MS41ODkgMzcuMzQ5NyA2MS44MjMxIDM3LjYzNThDNjIuMDU3MSAzNy45MjE5IDYyLjE2MTIgMzguMzEyIDYyLjE2MTIgMzguODU4MlY0MS4xOTg5SDYxLjQ1OVYzOC44NTgyQzYxLjQ1OSAzOC40OTQxIDYxLjQwNjkgMzguMjA4IDYxLjI3NjkgMzguMDUxOUM2MS4xNDY5IDM3Ljg5NTkgNjAuOTY0OCAzNy43OTE4IDYwLjcwNDcgMzcuNzkxOEM2MC40NDQ3IDM3Ljc5MTggNjAuMjM2NiAzNy44Njk5IDYwLjEwNjYgMzguMDUxOUM1OS45NTA1IDM4LjIwOCA1OS44NzI1IDM4LjQxNiA1OS44NDY1IDM4LjcwMjFWNDEuMTk4OUg1OS4xMTgzVjM4Ljg1ODJDNTkuMTE4MyAzOC40OTQxIDU5LjA0MDIgMzguMjM0IDU4LjkxMDIgMzguMDUxOUM1OC43ODAyIDM3Ljg2OTkgNTguNTcyMSAzNy43OTE4IDU4LjMxMiAzNy43OTE4QzU3Ljg5NTkgMzcuNzkxOCA1Ny42MDk4IDM3Ljk3MzkgNTcuNDUzOCAzOC4zMTJWNDEuMTk4OUg1Ny40Mjc4WiIgZmlsbD0id2hpdGUiLz4KPHBhdGggZD0iTTY0LjA1OTggNDEuMTk4OUg2My4zNTc1VjM3LjI5NzdINjMuOTgxN0w2NC4wMzM3IDM3LjgxNzlDNjQuMjkzOCAzNy40Mjc3IDY0LjY4MzkgMzcuMjE5NyA2NS4yMzAxIDM3LjIxOTdDNjUuNzUwMyAzNy4yMTk3IDY2LjExNDQgMzcuNDUzNyA2Ni4yOTY0IDM3Ljk0NzlDNjYuNTU2NSAzNy40Nzk4IDY2Ljk3MjYgMzcuMjE5NyA2Ny40OTI4IDM3LjIxOTdDNjcuOTA4OSAzNy4yMTk3IDY4LjIyMSAzNy4zNDk3IDY4LjQ1NTEgMzcuNjM1OEM2OC42ODkxIDM3LjkyMTkgNjguNzkzMiAzOC4zMTIgNjguNzkzMiAzOC44NTgyVjQxLjE5ODlINjguMDkxVjM4Ljg1ODJDNjguMDkxIDM4LjQ5NDEgNjguMDM5IDM4LjIwOCA2Ny45MDg5IDM4LjA1MTlDNjcuNzc4OSAzNy44OTU5IDY3LjU5NjggMzcuNzkxOCA2Ny4zMzY3IDM3Ljc5MThDNjcuMDc2NyAzNy43OTE4IDY2Ljg2ODYgMzcuODY5OSA2Ni43Mzg2IDM4LjA1MTlDNjYuNTgyNSAzOC4yMDggNjYuNTA0NSAzOC40MTYgNjYuNDc4NSAzOC43MDIxVjQxLjE5ODlINjUuNzUwM1YzOC44NTgyQzY1Ljc1MDMgMzguNDk0MSA2NS42NzIyIDM4LjIzNCA2NS41NDIyIDM4LjA1MTlDNjUuNDEyMiAzNy44Njk5IDY1LjIwNDEgMzcuNzkxOCA2NC45NDQgMzcuNzkxOEM2NC41Mjc5IDM3Ljc5MTggNjQuMjQxOCAzNy45NzM5IDY0LjA4NTggMzguMzEyVjQxLjE5ODlINjQuMDU5OFoiIGZpbGw9IndoaXRlIi8+CjxwYXRoIGQ9Ik03MS4zMTU5IDQxLjI3NjlDNzAuODczOCA0MS4yNzY5IDcwLjUzNTcgNDEuMTQ2OSA3MC4zMDE2IDQwLjg2MDhDNzAuMDY3NiA0MC41NzQ3IDY5Ljk2MzUgNDAuMTU4NiA2OS45NjM1IDM5LjU4NjRWMzcuMjk3N0g3MC42NjU3VjM5LjYxMjRDNzAuNjY1NyA0MC4wMjg1IDcwLjcxNzggNDAuMzE0NiA3MC44NDc4IDQwLjQ3MDdDNzAuOTc3OCA0MC42MjY3IDcxLjE1OTkgNDAuNzA0NyA3MS40MiA0MC43MDQ3QzcxLjkxNDEgNDAuNzA0NyA3Mi4yNTIyIDQwLjQ5NjcgNzIuNDM0MyA0MC4xMDY1VjM3LjI5NzdINzMuMTM2NVY0MS4xOTg5SDcyLjUxMjNMNzIuNDYwMyA0MC42MjY3QzcyLjIwMDIgNDEuMDY4OCA3MS44MTAxIDQxLjI3NjkgNzEuMzE1OSA0MS4yNzY5WiIgZmlsbD0id2hpdGUiLz4KPHBhdGggZD0iTTc0LjMzMjggMzcuMjk3N0g3NC45NTdMNzUuMDA5IDM3Ljg2OTlDNzUuMjY5MSAzNy40Mjc3IDc1LjY1OTIgMzcuMjE5NyA3Ni4xNzk0IDM3LjIxOTdDNzYuNTk1NSAzNy4yMTk3IDc2LjkzMzYgMzcuMzQ5NyA3Ny4xNjc3IDM3LjU4MzhDNzcuNDAxOCAzNy44NDM5IDc3LjUwNTggMzguMjA4IDc3LjUwNTggMzguNzI4MVY0MS4xOTg5SDc2LjgwMzZWMzguNzU0MUM3Ni44MDM2IDM4LjQxNiA3Ni43MjU2IDM4LjE4MiA3Ni41OTU1IDM4LjAyNTlDNzYuNDY1NSAzNy44Njk5IDc2LjI1NzQgMzcuODE3OSA3NS45NzEzIDM3LjgxNzlDNzUuNzYzMyAzNy44MTc5IDc1LjU4MTIgMzcuODY5OSA3NS40MjUyIDM3Ljk3MzlDNzUuMjY5MSAzOC4wNzc5IDc1LjEzOTEgMzguMjA4IDc1LjAzNTEgMzguMzlWNDEuMjI0OUg3NC4zMzI4VjM3LjI5NzdaIiBmaWxsPSJ3aGl0ZSIvPgo8cGF0aCBkPSJNNzkuNDgyNCAzNy4yOTc3VjQxLjE5ODlINzguNzU0MlYzNy4yOTc3SDc5LjQ4MjRaTTc5LjQ4MjQgMzUuNTgxMlYzNi4zMDk0SDc4Ljc1NDJWMzUuNTgxMkg3OS40ODI0WiIgZmlsbD0id2hpdGUiLz4KPHBhdGggZD0iTTgxLjI3NjkgMzkuMzI2M0M4MS4yNzY5IDM5Ljc0MjQgODEuMzU0OSA0MC4wODA1IDgxLjUxMSA0MC4zMTQ2QzgxLjY2NyA0MC41NzQ3IDgxLjk1MzEgNDAuNzA0NyA4Mi4zMTcyIDQwLjcwNDdDODIuNTUxMyA0MC43MDQ3IDgyLjc1OTQgNDAuNjI2NyA4Mi45NDE0IDQwLjQ5NjdDODMuMTIzNSA0MC4zNDA2IDgzLjIwMTUgNDAuMTg0NiA4My4yMDE1IDM5Ljk1MDVIODMuODUxN1YzOS45NzY1QzgzLjg3NzcgNDAuMzE0NiA4My43MjE3IDQwLjYyNjcgODMuNDA5NiA0MC44ODY4QzgzLjA5NzUgNDEuMTQ2OSA4Mi43MzM0IDQxLjI3NjkgODIuMzE3MiA0MS4yNzY5QzgxLjc3MTEgNDEuMjc2OSA4MS4zMjg5IDQxLjA5NDggODEuMDE2OSA0MC43MzA3QzgwLjcwNDggNDAuMzY2NiA4MC41NDg3IDM5Ljg5ODUgODAuNTQ4NyAzOS4zMjYzVjM5LjE3MDNDODAuNTQ4NyAzOC41OTgxIDgwLjcwNDggMzguMTI5OSA4MS4wMTY5IDM3Ljc2NThDODEuMzI4OSAzNy40MDE3IDgxLjc3MTEgMzcuMjE5NyA4Mi4zMTcyIDM3LjIxOTdDODIuNzU5NCAzNy4yMTk3IDgzLjE0OTUgMzcuMzQ5NyA4My40MzU2IDM3LjYwOThDODMuNzIxNyAzNy44Njk5IDgzLjg3NzcgMzguMjA4IDgzLjg1MTcgMzguNTk4MVYzOC42MjQxSDgzLjIwMTVDODMuMjAxNSAzOC4zOSA4My4xMjM1IDM4LjE4MiA4Mi45NDE0IDM4LjAyNTlDODIuNzg1NCAzNy44Njk5IDgyLjU1MTMgMzcuNzY1OCA4Mi4zMTcyIDM3Ljc2NThDODIuMDU3MiAzNy43NjU4IDgxLjg3NTEgMzcuODE3OSA4MS42OTMgMzcuOTQ3OUM4MS41MzcgMzguMDc3OSA4MS40MzMgMzguMjM0IDgxLjM1NDkgMzguNDQyQzgxLjMwMjkgMzguNjUwMSA4MS4yNTA5IDM4Ljg4NDIgODEuMjUwOSAzOS4xNDQzVjM5LjMyNjNIODEuMjc2OVoiIGZpbGw9IndoaXRlIi8+CjxwYXRoIGQ9Ik04NS45MzIzIDQxLjI3NjlDODUuNTE2MiA0MS4yNzY5IDg1LjIwNDEgNDEuMTcyOSA4NC45OTYgNDAuOTY0OEM4NC43ODggNDAuNzU2NyA4NC42ODM5IDQwLjQ3MDcgODQuNjgzOSA0MC4xMDY1Qzg0LjY4MzkgMzkuNzQyNCA4NC44NCAzOS40NTYzIDg1LjEyNjEgMzkuMjIyM0M4NS40MzgyIDM5LjAxNDIgODUuODI4MyAzOC45MTAyIDg2LjM0ODUgMzguOTEwMkg4Ny4xMjg3VjM4LjUyMDFDODcuMTI4NyAzOC4yODYgODcuMDUwNyAzOC4xMDM5IDg2LjkyMDYgMzcuOTczOUM4Ni43OTA2IDM3Ljg0MzkgODYuNTgyNSAzNy43NjU4IDg2LjM0ODUgMzcuNzY1OEM4Ni4xMTQ0IDM3Ljc2NTggODUuOTMyMyAzNy44MTc5IDg1Ljc3NjMgMzcuOTQ3OUM4NS42MjAyIDM4LjA1MTkgODUuNTY4MiAzOC4yMDggODUuNTY4MiAzOC4zNjRIODQuODkyVjM4LjMzOEM4NC44NjYgMzguMDUxOSA4NS4wMjIxIDM3Ljc5MTggODUuMzA4MSAzNy41NTc4Qzg1LjU5NDIgMzcuMzIzNyA4NS45NTgzIDM3LjIxOTcgODYuNDAwNSAzNy4yMTk3Qzg2Ljg0MjYgMzcuMjE5NyA4Ny4yMDY3IDM3LjMyMzcgODcuNDY2OCAzNy41NTc4Qzg3LjcyNjkgMzcuNzkxOCA4Ny44NTY5IDM4LjEwMzkgODcuODU2OSAzOC41MjAxVjQwLjM5MjZDODcuODU2OSA0MC43MDQ3IDg3Ljg4MjkgNDAuOTY0OCA4Ny45NjA5IDQxLjE3MjlIODcuMjMyN0M4Ny4xODA3IDQwLjkxMjggODcuMTU0NyA0MC43MzA3IDg3LjE1NDcgNDAuNTc0N0M4Ny4wMjQ2IDQwLjc4MjggODYuODQyNiA0MC45Mzg4IDg2LjYzNDUgNDEuMDQyOEM4Ni40MDA1IDQxLjIyNDkgODYuMTY2NCA0MS4yNzY5IDg1LjkzMjMgNDEuMjc2OVpNODUuMzg2MiA0MC4xMzI2Qzg1LjM4NjIgNDAuMzE0NiA4NS40MzgyIDQwLjQ0NDYgODUuNTQyMiA0MC41NDg3Qzg1LjY0NjIgNDAuNjUyNyA4NS44MjgzIDQwLjcwNDcgODYuMDYyNCA0MC43MDQ3Qzg2LjI5NjQgNDAuNzA0NyA4Ni41MDQ1IDQwLjY1MjcgODYuNzEyNiA0MC41MjI3Qzg2LjkyMDYgNDAuMzkyNiA4Ny4wNTA3IDQwLjIzNjYgODcuMTI4NyA0MC4wNTQ1VjM5LjQwNDNIODYuMzIyNEM4Ni4wMzYzIDM5LjQwNDMgODUuODAyMyAzOS40ODI0IDg1LjY0NjIgMzkuNjEyNEM4NS40OTAyIDM5Ljc0MjQgODUuMzg2MiAzOS45MjQ1IDg1LjM4NjIgNDAuMTMyNloiIGZpbGw9IndoaXRlIi8+CjxwYXRoIGQ9Ik04OS4yNjEzIDM2LjM2MTRIODkuOTg5NlYzNy4yOTc3SDkwLjcxNzhWMzcuODE3OUg4OS45ODk2VjQwLjE4NDZDODkuOTg5NiA0MC41MjI3IDkwLjExOTYgNDAuNjc4NyA5MC40MDU3IDQwLjY3ODdDOTAuNDgzNyA0MC42Nzg3IDkwLjU4NzcgNDAuNjUyNyA5MC42NjU4IDQwLjYyNjdMOTAuNzY5OCA0MS4xMjA4QzkwLjYzOTggNDEuMjI0OSA5MC40NTc3IDQxLjI3NjkgOTAuMjIzNiA0MS4yNzY5Qzg5LjkxMTUgNDEuMjc2OSA4OS43MDM1IDQxLjE5ODkgODkuNTIxNCA0MS4wMTY4Qzg5LjM2NTQgNDAuODM0OCA4OS4yNjEzIDQwLjU3NDcgODkuMjYxMyA0MC4yMTA2VjM3Ljg0MzlIODguNjM3MVYzNy4zMjM3SDg5LjI2MTNWMzYuMzYxNFoiIGZpbGw9IndoaXRlIi8+CjxwYXRoIGQ9Ik05Mi41MzgzIDM3LjI5NzdWNDEuMTk4OUg5MS44MzYxVjM3LjI5NzdIOTIuNTM4M1pNOTIuNTM4MyAzNS41ODEyVjM2LjMwOTRIOTEuODM2MVYzNS41ODEySDkyLjUzODNaIiBmaWxsPSJ3aGl0ZSIvPgo8cGF0aCBkPSJNOTQuMzMyOSAzOS4yMjIzVjM5LjMwMDNDOTQuMzMyOSAzOS43NDI0IDk0LjQxMDkgNDAuMDgwNSA5NC41OTI5IDQwLjM0MDZDOTQuNzc1IDQwLjYwMDcgOTUuMDM1MSA0MC43MzA3IDk1LjM3MzIgNDAuNzMwN0M5NS43MTEzIDQwLjczMDcgOTUuOTcxNCA0MC42MDA3IDk2LjE1MzQgNDAuMzQwNkM5Ni4zMzU1IDQwLjA4MDUgOTYuNDEzNSAzOS43NDI0IDk2LjQxMzUgMzkuMzAwM1YzOS4yMjIzQzk2LjQxMzUgMzguODA2MiA5Ni4zMzU1IDM4LjQ0MiA5Ni4xNTM0IDM4LjE4MkM5NS45NzE0IDM3LjkyMTkgOTUuNzExMyAzNy43OTE4IDk1LjM3MzIgMzcuNzkxOEM5NS4wMzUxIDM3Ljc5MTggOTQuNzc1IDM3LjkyMTkgOTQuNjE4OSAzOC4xODJDOTQuNDM2OSAzOC40NDIgOTQuMzMyOSAzOC44MDYyIDk0LjMzMjkgMzkuMjIyM1pNOTMuNjMwNiAzOS4zMDAzVjM5LjIyMjNDOTMuNjMwNiAzOC42MjQxIDkzLjc4NjcgMzguMTU2IDk0LjA5ODggMzcuNzkxOEM5NC40MTA5IDM3LjQyNzcgOTQuODI3IDM3LjI0NTcgOTUuMzczMiAzNy4yNDU3Qzk1LjkxOTMgMzcuMjQ1NyA5Ni4zMzU1IDM3LjQyNzcgOTYuNjQ3NSAzNy43OTE4Qzk2Ljk1OTYgMzguMTU2IDk3LjExNTcgMzguNjI0MSA5Ny4xMTU3IDM5LjIyMjNWMzkuMzAwM0M5Ny4xMTU3IDM5Ljg5ODUgOTYuOTU5NiA0MC4zNjY2IDk2LjY0NzUgNDAuNzMwN0M5Ni4zMzU1IDQxLjA5NDggOTUuOTE5MyA0MS4yNzY5IDk1LjM3MzIgNDEuMjc2OUM5NC44MjcgNDEuMjc2OSA5NC40MTA5IDQxLjA5NDggOTQuMDk4OCA0MC43MzA3QzkzLjc4NjcgNDAuMzY2NiA5My42MzA2IDM5Ljg5ODUgOTMuNjMwNiAzOS4zMDAzWiIgZmlsbD0id2hpdGUiLz4KPHBhdGggZD0iTTk4LjE1NiAzNy4yOTc3SDk4Ljc4MDJMOTguODMyMiAzNy44Njk5Qzk5LjA5MjMgMzcuNDI3NyA5OS40ODI0IDM3LjIxOTcgMTAwLjAwMyAzNy4yMTk3QzEwMC40MTkgMzcuMjE5NyAxMDAuNzU3IDM3LjM0OTcgMTAwLjk5MSAzNy41ODM4QzEwMS4yMjUgMzcuODQzOSAxMDEuMzI5IDM4LjIwOCAxMDEuMzI5IDM4LjcyODFWNDEuMTk4OUgxMDAuNjI3VjM4Ljc1NDFDMTAwLjYyNyAzOC40MTYgMTAwLjU0OSAzOC4xODIgMTAwLjQxOSAzOC4wMjU5QzEwMC4yODkgMzcuODY5OSAxMDAuMDgxIDM3LjgxNzkgOTkuNzk0NSAzNy44MTc5Qzk5LjU4NjQgMzcuODE3OSA5OS40MDQ0IDM3Ljg2OTkgOTkuMjQ4MyAzNy45NzM5Qzk5LjA5MjMgMzguMDc3OSA5OC45NjIyIDM4LjIwOCA5OC44NTgyIDM4LjM5VjQxLjIyNDlIOTguMTU2VjM3LjI5NzdaIiBmaWxsPSJ3aGl0ZSIvPgo8cGF0aCBkPSJNMTA1LjMwOCAzOS42OTA0QzEwNS4zMDggNDAuMDI4NSAxMDUuNDM4IDQwLjI2MjYgMTA1LjY3MiA0MC40NDQ2QzEwNS45MDYgNDAuNjI2NyAxMDYuMjE4IDQwLjcwNDcgMTA2LjU4MyA0MC43MDQ3QzEwNi45NDcgNDAuNzA0NyAxMDcuMjMzIDQwLjYyNjcgMTA3LjQ0MSA0MC40NzA3QzEwNy42NDkgNDAuMzE0NiAxMDcuNzUzIDQwLjEwNjUgMTA3Ljc1MyAzOS44NzI1QzEwNy43NTMgMzkuNjEyNCAxMDcuNjQ5IDM5LjQwNDMgMTA3LjQ2NyAzOS4yNDgzQzEwNy4yODUgMzkuMDkyMiAxMDYuOTczIDM4Ljk2MjIgMTA2LjUwNSAzOC44NTgyQzEwNS4zMzQgMzguNTcyMSAxMDQuNzM2IDM4LjA1MTkgMTA0LjczNiAzNy4yOTc3QzEwNC43MzYgMzYuODgxNiAxMDQuODkyIDM2LjU0MzUgMTA1LjIzIDM2LjI4MzRDMTA1LjU2OCAzNi4wMjMzIDEwNi4wMSAzNS44NjczIDEwNi41NTcgMzUuODY3M0MxMDcuMTAzIDM1Ljg2NzMgMTA3LjU0NSAzNi4wMjMzIDEwNy45MDkgMzYuMzM1NEMxMDguMjQ3IDM2LjY0NzUgMTA4LjQyOSAzNy4wMTE2IDEwOC40MDMgMzcuNDUzOFYzNy40Nzk4SDEwNy43MjdDMTA3LjcyNyAzNy4xNjc3IDEwNy42MjMgMzYuOTA3NiAxMDcuNDE1IDM2LjcyNTVDMTA3LjIwNyAzNi41NDM1IDEwNi45MjEgMzYuNDM5NCAxMDYuNTU3IDM2LjQzOTRDMTA2LjE5MiAzNi40Mzk0IDEwNS45MDYgMzYuNTE3NSAxMDUuNzI0IDM2LjY3MzVDMTA1LjU0MiAzNi44Mjk2IDEwNS40MzggMzcuMDM3NiAxMDUuNDM4IDM3LjI3MTdDMTA1LjQzOCAzNy41MDU4IDEwNS41NDIgMzcuNzEzOCAxMDUuNzUgMzcuODY5OUMxMDUuOTU4IDM4LjAyNTkgMTA2LjI5NiAzOC4xNTYgMTA2LjczOSAzOC4yNkMxMDcuODU3IDM4LjU0NjEgMTA4LjQyOSAzOS4wNjYyIDEwOC40MjkgMzkuODcyNUMxMDguNDI5IDQwLjI4ODYgMTA4LjI0NyA0MC42MjY3IDEwNy45MDkgNDAuODg2OEMxMDcuNTcxIDQxLjE0NjkgMTA3LjEwMyA0MS4yNzY5IDEwNi41NTcgNDEuMjc2OUMxMDYuMTkyIDQxLjI3NjkgMTA1Ljg4IDQxLjIyNDkgMTA1LjU2OCA0MS4wOTQ4QzEwNS4yNTYgNDAuOTY0OCAxMDUuMDIyIDQwLjc4MjcgMTA0Ljg0IDQwLjU0ODdDMTA0LjY1OCA0MC4zMTQ2IDEwNC41OCA0MC4wMjg1IDEwNC41OCAzOS43MTY0VjM5LjY5MDRIMTA1LjMwOFoiIGZpbGw9IndoaXRlIi8+CjxwYXRoIGQ9Ik0xMTAuMDk0IDM5LjIyMjNWMzkuMzAwM0MxMTAuMDk0IDM5Ljc0MjQgMTEwLjE3MiA0MC4wODA1IDExMC4zNTQgNDAuMzQwNkMxMTAuNTM2IDQwLjYwMDcgMTEwLjc5NiA0MC43MzA3IDExMS4xMzQgNDAuNzMwN0MxMTEuNDcyIDQwLjczMDcgMTExLjczMiA0MC42MDA3IDExMS45MTQgNDAuMzQwNkMxMTIuMDk2IDQwLjA4MDUgMTEyLjE3NCAzOS43NDI0IDExMi4xNzQgMzkuMzAwM1YzOS4yMjIzQzExMi4xNzQgMzguODA2MiAxMTIuMDk2IDM4LjQ0MiAxMTEuOTE0IDM4LjE4MkMxMTEuNzMyIDM3LjkyMTkgMTExLjQ3MiAzNy43OTE4IDExMS4xMzQgMzcuNzkxOEMxMTAuNzk2IDM3Ljc5MTggMTEwLjUzNiAzNy45MjE5IDExMC4zOCAzOC4xODJDMTEwLjE3MiAzOC40NDIgMTEwLjA5NCAzOC44MDYyIDExMC4wOTQgMzkuMjIyM1pNMTA5LjM2NSAzOS4zMDAzVjM5LjIyMjNDMTA5LjM2NSAzOC42MjQxIDEwOS41MjEgMzguMTU2IDEwOS44MzQgMzcuNzkxOEMxMTAuMTQ2IDM3LjQyNzcgMTEwLjU2MiAzNy4yNDU3IDExMS4xMDggMzcuMjQ1N0MxMTEuNjU0IDM3LjI0NTcgMTEyLjA3IDM3LjQyNzcgMTEyLjM4MiAzNy43OTE4QzExMi42OTQgMzguMTU2IDExMi44NSAzOC42MjQxIDExMi44NSAzOS4yMjIzVjM5LjMwMDNDMTEyLjg1IDM5Ljg5ODUgMTEyLjY5NCA0MC4zNjY2IDExMi4zODIgNDAuNzMwN0MxMTIuMDcgNDEuMDk0OCAxMTEuNjU0IDQxLjI3NjkgMTExLjEwOCA0MS4yNzY5QzExMC41NjIgNDEuMjc2OSAxMTAuMTQ2IDQxLjA5NDggMTA5LjgzNCA0MC43MzA3QzEwOS41NDcgNDAuMzY2NiAxMDkuMzY1IDM5Ljg5ODUgMTA5LjM2NSAzOS4zMDAzWiIgZmlsbD0id2hpdGUiLz4KPHBhdGggZD0iTTExNC42NzEgMzUuNTgxMlY0MS4xOTg5SDExMy45NjlWMzUuNTgxMkgxMTQuNjcxWiIgZmlsbD0id2hpdGUiLz4KPHBhdGggZD0iTTExNy4yNzIgNDEuMjc2OUMxMTYuODMgNDEuMjc2OSAxMTYuNDkyIDQxLjE0NjkgMTE2LjI1NyA0MC44NjA4QzExNi4wMjMgNDAuNTc0NyAxMTUuOTE5IDQwLjE1ODYgMTE1LjkxOSAzOS41ODY0VjM3LjI5NzdIMTE2LjYyMlYzOS42MTI0QzExNi42MjIgNDAuMDI4NSAxMTYuNjc0IDQwLjMxNDYgMTE2LjgwNCA0MC40NzA3QzExNi45MzQgNDAuNjI2NyAxMTcuMTE2IDQwLjcwNDcgMTE3LjM3NiA0MC43MDQ3QzExNy44NyA0MC43MDQ3IDExOC4yMDggNDAuNDk2NyAxMTguMzkgNDAuMTA2NVYzNy4yOTc3SDExOS4wOTJWNDEuMTk4OUgxMTguNDQyTDExOC4zOSA0MC42MjY3QzExOC4xNTYgNDEuMDY4OCAxMTcuNzY2IDQxLjI3NjkgMTE3LjI3MiA0MS4yNzY5WiIgZmlsbD0id2hpdGUiLz4KPHBhdGggZD0iTTEyMC41MjMgMzYuMzYxNEgxMjEuMjUxVjM3LjI5NzdIMTIxLjk3OVYzNy44MTc5SDEyMS4yNTFWNDAuMTg0NkMxMjEuMjUxIDQwLjUyMjcgMTIxLjM4MSA0MC42Nzg3IDEyMS42NjcgNDAuNjc4N0MxMjEuNzQ1IDQwLjY3ODcgMTIxLjg0OSA0MC42NTI3IDEyMS45MjcgNDAuNjI2N0wxMjIuMDMxIDQxLjEyMDhDMTIxLjkwMSA0MS4yMjQ5IDEyMS43MTkgNDEuMjc2OSAxMjEuNDg1IDQxLjI3NjlDMTIxLjE5OSA0MS4yNzY5IDEyMC45NjUgNDEuMTk4OSAxMjAuNzgzIDQxLjAxNjhDMTIwLjYyNyA0MC44MzQ4IDEyMC41MjMgNDAuNTc0NyAxMjAuNTIzIDQwLjIxMDZWMzcuODQzOUgxMTkuODk5VjM3LjMyMzdIMTIwLjUyM1YzNi4zNjE0WiIgZmlsbD0id2hpdGUiLz4KPHBhdGggZD0iTTEyMy44IDM3LjI5NzdWNDEuMTk4OUgxMjMuMDk4VjM3LjI5NzdIMTIzLjhaTTEyMy44IDM1LjU4MTJWMzYuMzA5NEgxMjMuMDk4VjM1LjU4MTJIMTIzLjhaIiBmaWxsPSJ3aGl0ZSIvPgo8cGF0aCBkPSJNMTI1LjU5NCAzOS4yMjIzVjM5LjMwMDNDMTI1LjU5NCAzOS43NDI0IDEyNS42NzIgNDAuMDgwNSAxMjUuODU0IDQwLjM0MDZDMTI2LjAzNiA0MC42MDA3IDEyNi4yOTYgNDAuNzMwNyAxMjYuNjM1IDQwLjczMDdDMTI2Ljk3MyA0MC43MzA3IDEyNy4yMzMgNDAuNjAwNyAxMjcuNDE1IDQwLjM0MDZDMTI3LjU5NyA0MC4wODA1IDEyNy42NzUgMzkuNzQyNCAxMjcuNjc1IDM5LjMwMDNWMzkuMjIyM0MxMjcuNjc1IDM4LjgwNjIgMTI3LjU3MSAzOC40NDIgMTI3LjQxNSAzOC4xODJDMTI3LjIzMyAzNy45MjE5IDEyNi45NzMgMzcuNzkxOCAxMjYuNjM1IDM3Ljc5MThDMTI2LjI5NiAzNy43OTE4IDEyNi4wMzYgMzcuOTIxOSAxMjUuODggMzguMTgyQzEyNS42NzIgMzguNDQyIDEyNS41OTQgMzguODA2MiAxMjUuNTk0IDM5LjIyMjNaTTEyNC44OTIgMzkuMzAwM1YzOS4yMjIzQzEyNC44OTIgMzguNjI0MSAxMjUuMDQ4IDM4LjE1NiAxMjUuMzYgMzcuNzkxOEMxMjUuNjcyIDM3LjQyNzcgMTI2LjA4OCAzNy4yNDU3IDEyNi42MzUgMzcuMjQ1N0MxMjcuMTgxIDM3LjI0NTcgMTI3LjU5NyAzNy40Mjc3IDEyNy45MDkgMzcuNzkxOEMxMjguMjIxIDM4LjE1NiAxMjguMzc3IDM4LjYyNDEgMTI4LjM3NyAzOS4yMjIzVjM5LjMwMDNDMTI4LjM3NyAzOS44OTg1IDEyOC4yMjEgNDAuMzY2NiAxMjcuOTA5IDQwLjczMDdDMTI3LjU5NyA0MS4wOTQ4IDEyNy4xODEgNDEuMjc2OSAxMjYuNjM1IDQxLjI3NjlDMTI2LjA4OCA0MS4yNzY5IDEyNS42NzIgNDEuMDk0OCAxMjUuMzYgNDAuNzMwN0MxMjUuMDQ4IDQwLjM2NjYgMTI0Ljg5MiAzOS44OTg1IDEyNC44OTIgMzkuMzAwM1oiIGZpbGw9IndoaXRlIi8+CjxwYXRoIGQ9Ik0xMjkuNDE3IDM3LjI5NzdIMTMwLjA0MkwxMzAuMDk0IDM3Ljg2OTlDMTMwLjM1NCAzNy40Mjc3IDEzMC43NDQgMzcuMjE5NyAxMzEuMjY0IDM3LjIxOTdDMTMxLjY4IDM3LjIxOTcgMTMyLjAxOCAzNy4zNDk3IDEzMi4yNTIgMzcuNTgzOEMxMzIuNDg2IDM3Ljg0MzkgMTMyLjU5IDM4LjIwOCAxMzIuNTkgMzguNzI4MVY0MS4xOTg5SDEzMS44ODhWMzguNzU0MUMxMzEuODg4IDM4LjQxNiAxMzEuODEgMzguMTgyIDEzMS42OCAzOC4wMjU5QzEzMS41NSAzNy44Njk5IDEzMS4zNDIgMzcuODE3OSAxMzEuMDU2IDM3LjgxNzlDMTMwLjg0OCAzNy44MTc5IDEzMC42NjYgMzcuODY5OSAxMzAuNTEgMzcuOTczOUMxMzAuMzU0IDM4LjA3NzkgMTMwLjIyNCAzOC4yMDggMTMwLjEyIDM4LjM5VjQxLjIyNDlIMTI5LjQxN1YzNy4yOTc3WiIgZmlsbD0id2hpdGUiLz4KPHBhdGggZD0iTTEzNS4yNDMgNDAuNzMwN0MxMzUuNTAzIDQwLjczMDcgMTM1LjY4NSA0MC42Nzg3IDEzNS44NDEgNDAuNTc0N0MxMzUuOTk3IDQwLjQ3MDcgMTM2LjA0OSA0MC4zNDA2IDEzNi4wNDkgNDAuMTg0NkMxMzYuMDQ5IDQwLjAyODUgMTM1Ljk5NyAzOS44OTg1IDEzNS44NjcgMzkuNzk0NUMxMzUuNzM3IDM5LjY5MDQgMTM1LjUyOSAzOS42MTI0IDEzNS4xOTEgMzkuNTM0NEMxMzQuNjk3IDM5LjQzMDMgMTM0LjMzMyAzOS4yNzQzIDEzNC4wOTkgMzkuMTE4MkMxMzMuODY1IDM4LjkzNjIgMTMzLjc2MSAzOC43MDIxIDEzMy43NjEgMzguMzlDMTMzLjc2MSAzOC4wNzc5IDEzMy44OTEgMzcuODE3OSAxMzQuMTc3IDM3LjU4MzhDMTM0LjQzNyAzNy4zNDk3IDEzNC44MDEgMzcuMjQ1NyAxMzUuMjQzIDM3LjI0NTdDMTM1LjY4NSAzNy4yNDU3IDEzNi4wMjMgMzcuMzQ5NyAxMzYuMzA5IDM3LjU4MzhDMTM2LjU3IDM3LjgxNzkgMTM2LjcgMzguMTAzOSAxMzYuNyAzOC40NDJWMzguNDY4MUgxMzYuMDIzQzEzNi4wMjMgMzguMjg2IDEzNS45NDUgMzguMTI5OSAxMzUuODE1IDM3Ljk5OTlDMTM1LjY1OSAzNy44Njk5IDEzNS40NzcgMzcuNzkxOCAxMzUuMjQzIDM3Ljc5MThDMTM1LjAwOSAzNy43OTE4IDEzNC44MjcgMzcuODQzOSAxMzQuNjk3IDM3Ljk0NzlDMTM0LjU2NyAzOC4wNTE5IDEzNC41MTUgMzguMTgyIDEzNC41MTUgMzguMzM4QzEzNC41MTUgMzguNDk0MSAxMzQuNTY3IDM4LjYyNDEgMTM0LjY3MSAzOC43MDIxQzEzNC43NzUgMzguNzgwMSAxMzUuMDA5IDM4Ljg1ODIgMTM1LjMyMSAzOC45MzYyQzEzNS44MTUgMzkuMDQwMiAxMzYuMTc5IDM5LjE5NjMgMTM2LjQ0IDM5LjM3ODNDMTM2LjY3NCAzOS41NjA0IDEzNi44MDQgMzkuNzk0NCAxMzYuODA0IDQwLjEwNjVDMTM2LjgwNCA0MC40NDQ2IDEzNi42NzQgNDAuNzMwNyAxMzYuMzg3IDQwLjkzODhDMTM2LjEwMSA0MS4xNDY5IDEzNS43MzcgNDEuMjUwOSAxMzUuMjY5IDQxLjI1MDlDMTM0LjgwMSA0MS4yNTA5IDEzNC40MTEgNDEuMTIwOCAxMzQuMTI1IDQwLjg4NjhDMTMzLjgzOSA0MC42NTI3IDEzMy43MDkgNDAuMzQwNiAxMzMuNzA5IDQwLjAwMjVWMzkuOTc2NUgxMzQuMzg1QzEzNC40MTEgNDAuMjEwNiAxMzQuNDg5IDQwLjM5MjYgMTM0LjY0NSA0MC41MjI3QzEzNC43NzUgNDAuNjc4NyAxMzQuOTgzIDQwLjczMDcgMTM1LjI0MyA0MC43MzA3WiIgZmlsbD0id2hpdGUiLz4KPGRlZnM+CjxsaW5lYXJHcmFkaWVudCBpZD0icGFpbnQwX2xpbmVhcl8xMDFfNjUiIHgxPSIwLjAwOTg0NDgyIiB5MT0iMjcuNzc4OCIgeDI9IjQ2LjY2NTgiIHkyPSIyNy43Nzg4IiBncmFkaWVudFVuaXRzPSJ1c2VyU3BhY2VPblVzZSI+CjxzdG9wIHN0b3AtY29sb3I9IiNGNUIwMUIiLz4KPHN0b3Agb2Zmc2V0PSIxIiBzdG9wLWNvbG9yPSIjRjJBMDFGIi8+CjwvbGluZWFyR3JhZGllbnQ+CjwvZGVmcz4KPC9zdmc+Cg==',
                        'width' => '250px', // passt die Breite der Grafik an
                    ],
                    [
                        'type' => 'Image',
                        'name' => 'CantataText',
                        'image' => 'data:image/svg+xml;base64, PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4NDQo8IS0tIEdlbmVyYXRvcjogQWRvYmUgSWxsdXN0cmF0b3IgMjYuMC4yLCBTVkcgRXhwb3J0IFBsdWctSW4gLiBTVkcgVmVyc2lvbjogNi4wMCBCdWlsZCAwKSAgLS0+DQ0KPHN2ZyB2ZXJzaW9uPSIxLjEiIGlkPSJMYXllcl8xIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHhtbG5zOnhsaW5rPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5L3hsaW5rIiB4PSIwcHgiIHk9IjBweCINDQoJIHZpZXdCb3g9IjAgMCAyMTcuMSA0MC44IiBzdHlsZT0iZW5hYmxlLWJhY2tncm91bmQ6bmV3IDAgMCAyMTcuMSA0MC44OyIgeG1sOnNwYWNlPSJwcmVzZXJ2ZSI+DQ0KPGc+DQ0KCTxwb2x5Z29uIHBvaW50cz0iMzEuOSw2LjkgMjksMTIuOSAxMCwxMi45IDEwLDI0LjcgMTIuMiwyNy44IDMxLDI3LjggMzEsMzMuOCAxMCwzMy43IDQsMjYuNCA0LDYuOSAJIi8+DQ0KCTxwb2x5Z29uIHBvaW50cz0iMzUsMzMuOCAzNSw2LjkgNjAuOSw2LjkgNjAuOSwzNi44IDQ0LjksMjcuNyA0NC45LDIwLjggNTUuMSwyNy4xIDU1LjEsMTIuNyA0MSwxMi43IDQxLDMzLjggCSIvPg0NCgk8cG9seWdvbiBwb2ludHM9IjY0LjksMzMuNyA2NC45LDIgODUuMSwyMS45IDg1LjEsNi45IDkxLjcsNi45IDkxLjcsMzguOCA3MiwxOC45IDcyLDMzLjcgCSIvPg0NCgk8cG9seWdvbiBwb2ludHM9Ijk2LDYuOCA5NiwxMy45IDEwNS45LDEzLjkgMTA1LjksMzUgMTEzLDMxLjYgMTEzLDEzLjggMTIwLjksMTMuOCAxMjQuMiw2LjggCSIvPg0NCgk8cG9seWdvbiBwb2ludHM9IjEyNS45LDMzLjcgMTI1LjksNi44IDE1NC4xLDYuOCAxNTMuOCwzNy43IDEzNS45LDI3LjYgMTM1LjksMjAuOCAxNDcuMSwyNyAxNDcsMTIuOSAxMzIuOSwxMi44IDEzMi45LDMzLjcgCSIvPg0NCgk8cG9seWdvbiBwb2ludHM9IjE1Nyw2LjQgMTU3LDEzLjUgMTY3LDEzLjUgMTY3LDM0LjYgMTc0LDMxLjIgMTc0LDEzLjQgMTgxLjksMTMuNCAxODUuMiw2LjQgCSIvPg0NCgk8cG9seWdvbiBwb2ludHM9IjE4Ni45LDMzLjMgMTg2LjksNi40IDIxNS4xLDYuNCAyMTQuOCwzNy4zIDE5NywyNy4yIDE5NywyMC40IDIwOC4xLDI2LjYgMjA4LDEyLjUgMTkzLjksMTIuNCAxOTMuOSwzMy4zIAkiLz4NDQo8L2c+DQ0KPC9zdmc+DQ0K',
                        'width' => '250px', // passt die Breite der Grafik an
                    ]
                ]
            ],
            [
                'type' => 'ExpansionPanel',
                'caption' => $this->Translate('Security Configuration'),
                'items' => [
                    [
                        'type' => 'Label',
                        'caption' => $this->Translate('API Token Configuration')
                    ],
                    [
                        'type' => 'Label',
                        'caption' => $this->Translate('Please enter or generate a new API token.')
                    ],
                    [
                        'type' => 'ValidationTextBox',
                        'name' => 'Token',
                        'caption' => $this->Translate('API Token'),
                        'value' => $this->ReadPropertyString('Token')
                    ],
                    [
                        'type' => 'Button',
                        'caption' => $this->Translate('Generate new token'),
                        'onClick' => 'CRSS_GenerateToken($id);'
                    ],
                    [
                        'type' => 'CheckBox',
                        'name' => 'EmulateHomeAssistantDiscovery',
                        'caption' => 'Home Assistant Discovery emulieren'
                    ]
                ]
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'RS90_IP',
                'caption' => 'RS90 IP-Adresse'
            ],
            [
                'type' => 'NumberSpinner',
                'name' => 'RS90_Port',
                'caption' => 'RS90 Port'
            ],
            [
                'type' => 'ExpansionPanel',
                'caption' => 'RS90 Zugangsdaten',
                'items' => [
                    [
                        'type' => 'Label',
                        'name' => 'RS90LoginWarning',
                        'caption' => '⚠️ RS90 login failed. Please verify the user name and password.',
                        'visible' => $this->ReadAttributeBoolean('RS90_LoginFailed')
                    ],
                    [
                        'name' => 'RS90_User',
                        'type' => 'ValidationTextBox',
                        'caption' => 'E-Mail'
                    ],
                    [
                        'name' => 'RS90_Password',
                        'type' => 'PasswordTextBox',
                        'caption' => 'Passwort'
                    ],
                ]
            ],
            [
                'type' => 'ExpansionPanel',
                'caption' => 'Sequenzen',
                'items' => [
                    [
                        'type' => 'List',
                        'name' => 'SequenceList',
                        'caption' => 'Verfügbare Sequenzen',
                        'columns' => [
                            [
                                'caption' => 'Name',
                                'name' => 'name',
                                'width' => 'auto',
                                'edit' => [
                                    'type' => 'ValidationTextBox'
                                ]
                            ],
                            [
                                'caption' => 'Favorit',
                                'name' => 'favoriteInRemoteIds',
                                'width' => '100px',
                                'edit' => [
                                    'type' => 'CheckBox'
                                ]
                            ]
                        ],
                        'values' => $this->GetFormCompatibleSequences()
                    ]
                ]
            ],
            [
                'type' => 'ExpansionPanel',
                'caption' => 'Räume',
                'items' => [
                    [
                        'type' => 'List',
                        'name' => 'RoomList',
                        'caption' => 'Verfügbare Räume',
                        'rowCount' => 10,
                        'add' => false,
                        'delete' => false,
                        'columns' => [
                            [
                                'caption' => 'Name',
                                'name' => 'name',
                                'width' => 'auto'
                            ],
                            [
                                'caption' => 'ID',
                                'name' => 'id',
                                'width' => '400px'
                            ]
                        ],
                        'values' => $this->RS90_GetRoomsFormatted()
                    ]
                ]
            ],
            [
                'type' => 'ExpansionPanel',
                'caption' => 'Geräte',
                'items' => [
                    [
                        'type' => 'List',
                        'name' => 'DeviceDashboard',
                        'caption' => 'Geräteübersicht',
                        'rowCount' => 10,
                        'add' => false,
                        'delete' => false,
                        'columns' => [
                            [
                                'caption' => 'Quelle',
                                'name' => 'label',
                                'width' => '200px'
                            ],
                            [
                                'caption' => 'Integriert',
                                'name' => 'isIntegrated',
                                'width' => '100px',
                                'edit' => false
                            ],
                            [
                                'caption' => 'Kategorien',
                                'name' => 'categoryList',
                                'width' => 'auto'
                            ]
                        ],
                        'values' => $this->RS90_GetDeviceDashboardFormatted()
                    ]
                ]
            ],
            [
                'type' => 'ExpansionPanel',
                'caption' => '🌐 Custom URL Geräte',
                'items' => [
                    [
                        'type' => 'List',
                        'name' => 'custom_devices',
                        'caption' => 'Custom URL Geräte',
                        'rowCount' => 10,
                        'add' => true,
                        'delete' => true,
                        'columns' => [
                            [
                                'caption' => 'Name',
                                'name' => 'name',
                                'width' => '300px',
                                'edit' => [
                                    'type' => 'ValidationTextBox'
                                ],
                                'add' => 'Device Name',
                                'save' => false
                            ],
                            [
                                'caption' => 'Device ID',
                                'name' => 'device_id',
                                'width' => 'auto',
                                'add' => '',
                                'save' => true
                            ],
                            [
                                'caption' => 'Base URL',
                                'name' => 'base_url',
                                'width' => '250px',
                                'edit' => [
                                    'type' => 'ValidationTextBox'
                                ],
                                'add' => '',
                                'save' => true
                            ],
                            [
                                'caption' => 'Commands',
                                'name' => 'commands',
                                'width' => '300px',
                                // 'onEdit' => 'CRSS_Set_Current_Device($id, $custom_devices["device_id"]);',
                                'edit' => [
                                    'type' => 'List',
                                    'rowCount' => 10,
                                    'add' => true,
                                    'delete' => true,
                                    'columns' => [
                                        [
                                            'caption' => 'Name',
                                            'name' => 'name',
                                            'width' => '400px',
                                            'edit' => [
                                                'type' => 'ValidationTextBox'
                                            ],
                                            'save' => true
                                        ],
                                        [
                                            'caption' => 'Method',
                                            'name' => 'method',
                                            'width' => '100px',
                                            'edit' => [
                                                'type' => 'Select',
                                                'options' => [
                                                    ['caption' => 'ADB', 'value' => 'ADB'],
                                                    ['caption' => 'TELNET', 'value' => 'TELNET'],
                                                    ['caption' => 'GET', 'value' => 'GET'],
                                                    ['caption' => 'POST', 'value' => 'POST']
                                                ]
                                            ],
                                            'save' => true
                                        ],
                                        [
                                            'caption' => 'IP Address & Port',
                                            'name' => 'adress_port',
                                            'width' => 'auto',
                                            'edit' => [
                                                'type' => 'ValidationTextBox'
                                            ],
                                            'visible' => false,
                                            'save' => true
                                        ],
                                        [
                                            'caption' => 'Command',
                                            'name' => 'command',
                                            'width' => 'auto',
                                            'edit' => [
                                                'type' => 'ValidationTextBox'
                                            ],
                                            'visible' => false,
                                            'save' => true
                                        ],
                                        [
                                            'caption' => 'URL',
                                            'name' => 'url',
                                            'width' => 'auto',
                                            'edit' => [
                                                'type' => 'ValidationTextBox'
                                            ],
                                            'visible' => false,
                                            'save' => true
                                        ]
                                    ],
                                    'values' => [
                                        [
                                            'name' => 'Test Command 1',
                                            'method' => 'GET',
                                            'ip' => '192.168.0.100:80',
                                            'command' => 'test_command_1',
                                            'url' => 'http://example.com/command1'
                                        ],
                                        [
                                            'name' => 'Test Command 2',
                                            'method' => 'POST',
                                            'ip' => '192.168.0.101:8080',
                                            'command' => 'test_command_2',
                                            'url' => 'http://example.com/command2'
                                        ]
                                    ]
                                ],
                                'add' => [],
                                'save' => true
                            ]
                        ],
                        'values' => $this->GetCustomURLDeviceListFormatted()
                    ]
                ]
            ],
            [
                'type' => 'CheckBox',
                'name' => 'expert_debug',
                'caption' => '🧪 Expert Debug'
            ]
        ];

        // Show debug settings only when enabled
        if ($this->ReadPropertyBoolean('expert_debug')) {
            $form[] = [
                'type' => 'ExpansionPanel',
                'caption' => '🪲 Debugging',
                'items' => [
                    [
                        'type' => 'Label',
                        'caption' => 'Use filters to reduce debug output to specific entities/IDs/IPs. Example topics: HA, IO, AUTH, ENTITY, CMD, DISCOVERY.'
                    ],
                    [
                        'type' => 'Select',
                        'name' => 'debug_level',
                        'caption' => 'Minimum debug level',
                        'options' => [
                            ['caption' => 'BASIC', 'value' => self::LV_BASIC],
                            ['caption' => 'ERROR', 'value' => self::LV_ERROR],
                            ['caption' => 'WARN', 'value' => self::LV_WARN],
                            ['caption' => 'INFO', 'value' => self::LV_INFO],
                            ['caption' => 'TRACE', 'value' => self::LV_TRACE],
                        ]
                    ],
                    [
                        'type' => 'CheckBox',
                        'name' => 'debug_filter_enabled',
                        'caption' => 'Enable filters'
                    ],
                    // Available topics are built from DebugTrait::GetDebugTopicMasterList().
                    [
                        'type' => 'List',
                        'name' => 'debug_topics_cfg',
                        'caption' => 'Topics',
                        'rowCount' => 10,
                        'add' => false,
                        'delete' => false,
                        'columns' => [
                            [
                                'caption' => 'Show',
                                'name' => 'enabled',
                                'width' => '80px',
                                'add' => true,
                                'edit' => ['type' => 'CheckBox']
                            ],
                            [
                                'caption' => 'Topic',
                                'name' => 'topic',
                                'width' => '120px',
                                'add' => '',
                                'edit' => ['type' => 'Label']
                            ],
                            [
                                'caption' => 'Description',
                                'name' => 'description',
                                'width' => 'auto',
                                'add' => '',
                                'edit' => ['type' => 'Label']
                            ]
                        ],
                        'values' => $this->BuildDebugTopicsConfig()
                    ],
                    [
                        'type' => 'ValidationTextBox',
                        'name' => 'debug_var_ids',
                        'caption' => 'Var/Object IDs (CSV)'
                    ],
                    [
                        'type' => 'ValidationTextBox',
                        'name' => 'debug_text_filter',
                        'caption' => 'Text filter (substring or regex)'
                    ],
                    [
                        'type' => 'CheckBox',
                        'name' => 'debug_text_is_regex',
                        'caption' => 'Text filter is regex'
                    ],
                    [
                        'type' => 'CheckBox',
                        'name' => 'debug_strict_match',
                        'caption' => 'Log matches only (strict)'
                    ],
                    [
                        'type' => 'NumberSpinner',
                        'name' => 'debug_throttle_ms',
                        'caption' => 'Throttle (ms, 0=off)',
                        'minimum' => 0,
                        'maximum' => 60000
                    ]
                ]
            ];
        }

        return $form;
    }

    protected function FormActions(): array
    {
        $form = [
            [
                'type' => 'Button',
                'caption' => 'Refresh RS90 Data',
                'onClick' => 'CRSS_RefreshConfigurationData($id);'
            ]
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
            ['code' => IS_CREATING, 'icon' => 'gear', 'caption' => $this->Translate('Device is being created')],
            ['code' => IS_ACTIVE, 'icon' => 'active', 'caption' => $this->Translate('Device connected and active')],
            ['code' => IS_DELETING, 'icon' => 'inactive', 'caption' => $this->Translate('Device is being deleted')],
            ['code' => IS_INACTIVE, 'icon' => 'inactive', 'caption' => $this->Translate('Device inactive')],
            ['code' => IS_NOTCREATED, 'icon' => 'error', 'caption' => $this->Translate('Device not created or error occurred')]
        ];

        return $form;
    }

    //######################## Denon Commands #######################################
    //Power
    private function Power(int $instance_id, bool $Value): void
    { // false (Standby) oder true (On)
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::PW, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PW . $SubCommand);
    }

    //Mainzone Power
    private function MainZonePower(int $instance_id, bool $Value): void
    {
        // MainZone true (On) or false (Off)
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::ZM, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::ZM . $SubCommand);
    }

    //Mainzone Standby Setting
    private function MainzoneAutoStandbySetting(int $instance_id, int $Value): bool
    {
        // 0 (Off) / 15 / 30 / 60 (Minuten)
        switch ($Value) {
            case 0:
                $subcommand = DENON_API_Commands::STBYOFF;
                break;
            case 15:
                $subcommand = DENON_API_Commands::STBY15M;
                break;
            case 30:
                $subcommand = DENON_API_Commands::STBY30M;
                break;
            case 60:
                $subcommand = DENON_API_Commands::STBY60M;
                break;
            default:
                trigger_error(__FUNCTION__ . ': unsupported Value: ' . $Value);
                return false;
        }

        DAVRT_SendCommand($instance_id, DENON_API_Commands::STBY . $subcommand);

        return true;
    }

    //Mainzone EcoMode Setting
    private function MainzoneEcoModeSetting(int $instance_id, string $Value): void
    {
        // On / Auto / Off
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValueName(DENON_API_Commands::ECO, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::ECO . $SubCommand);
    }

    //Master Volume
    private function MasterVolume(int $instance_id, string $command): void
    {
        // "UP" or "DOWN"
        $payload = DENON_API_Commands::MV . $command;
        DAVRT_SendCommand($instance_id, $payload);
    }

    //Master Volume Step
    private function MasterVolumeStep(int $instance_id, string $command, float $step): void
    {
        // "UP" or "DOWN" , Step Schrittweite der Lautstärke Änderung Minimum 0.5
        if ($step < 1 || $step > 40) {
            $message = 'Schrittweite muss zwischen 1 und 40 liegen';
            echo $message;
            $this->DebugLog('Fehlerhafter Eingabewert:', $message, 0);
            return;
        }
        $valmax = 18;
        $valmin = -80;
        $currentvol = GetValueFloat($this->GetIDForIdent('MV'));
        $Value = $currentvol;
        if ($command === 'UP' && ($currentvol < ($valmax - $step))) {
            $Value = $currentvol + $step;
        }
        if ($command === 'DOWN' && ($currentvol > ($valmin + $step))) {
            $Value = $currentvol - $step;
        }

        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::MV, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::MV . $SubCommand);
    }

    //Master Volume Fix
    private function MasterVolumeFix(int $instance_id, float $Value): void
    {
        // float -80 bis 18 Schrittweite 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::MV, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::MV . $SubCommand);
    }

    //Master Volume Percent
    private function MasterVolumePercent(int $instance_id, int $percent): void
    {
        $Value = ((98 / 100) * $percent) - 80;
        $Value = round($Value * 2) / 2;

        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::MV, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::MV . $SubCommand);
    }

    private function GetDenonMuteState(int $instanceId): bool
    {
        // Überprüfen, ob die Variable mit dem Ident 'MU' existiert
        $variableId = @IPS_GetObjectIDByIdent('MU', $instanceId);
        if ($variableId === false) {
            // Debug-Meldung, falls die Variable nicht gefunden wurde
            $this->DebugLog(__FUNCTION__, "Variable mit Ident 'MU' nicht gefunden für Instanz-ID: $instanceId", 0);
            return false; // Standardwert: Nicht gemutet
        }

        // Überprüfen, ob die gefundene ID tatsächlich eine Variable ist
        if (IPS_VariableExists($variableId)) {
            // Wert der Variable auslesen
            $muteStatus = GetValueBoolean($variableId);
            $this->DebugLog(__FUNCTION__, "Mute-Status aus Variable: $muteStatus", 0);
            return $muteStatus;
        } else {
            // Debug-Meldung, falls die ID keine Variable ist
            $this->DebugLog(__FUNCTION__, "Die gefundene ID ist keine Variable: $variableId", 0);
            return false; // Standardwert: Nicht gemutet
        }
    }

    //Main Mute
    private function MainMute(int $instance_id, bool $Value): void
    {
        // false (Off) oder true (On)
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::MU, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::MU . $SubCommand);
    }

    //Input
    private function Input(int $instance_id, string $command): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::SI . $command);
    }

    //Surround Mode
    private function SurroundMode(int $instance_id, string $command): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::MS . $command);
    }

    //All Zone Stereo
    private function AllZoneStereo(int $instance_id, bool $Value): void
    {
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::MNZST, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::MNZST . $SubCommand);
    }

    //Get Display NSADisplay
    private function NSADisplay(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::NSA);
    }

    //Get Display NSEDisplay
    private function NSEDisplay(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::NSE);
    }

    //Dynamic Volume
    private function DynamicVolume(int $instance_id, string $Value): void
    {
        // Dynamic Volume: Midnight / Evening / Day
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValueName(DENON_API_Commands::PSDYNVOL, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSDYNVOL . $SubCommand);
    }

    //Dolby Volume
    private function DolbyVolume(int $instance_id, bool $Value): void
    {
        // Dolby Volume: true (On) or false (Off)
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::PSDOLVOL, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSDOLVOL . $SubCommand);
    }

    //Dolby Volume Modeler
    private function DolbyVolumeModeler(int $instance_id, string $Value): void
    {
        // Dolby Volume Modeler: Off / Half / Full
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValueName(DENON_API_Commands::PSVOLMOD, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSVOLMOD . $SubCommand);
    }

    //Dolby Volume Leveler
    private function DolbyVolumeLeveler(int $instance_id, string $Value): void
    {
        // Dolby Volume Leveler: Low / Middle / High
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValueName(DENON_API_Commands::PSVOLLEV, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSVOLLEV . $SubCommand);
    }

    //Dynamic Compressor
    private function DynamicCompressor(int $instance_id, string $Value): void
    {
        // Dynamic Compressor: Off / Low / Middle / High
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValueName(DENON_API_Commands::PSDCO, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSDCO . $SubCommand);
    }

    //Dynamic Range Compression
    private function DynamicRangeCompression(int $instance_id, string $Value): void
    {
        // Dynamic Range Compression: Off / Auto / Low / Middle / High
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValueName(DENON_API_Commands::PSDRC, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSDRC . $SubCommand);
    }

    //Audyssey DSX
    private function AudysseyDSX(int $instance_id, string $Value): void
    {
        // Audyssey DSX: Off / Wide / Height / Height/Wide
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValueName(DENON_API_Commands::PSDSX, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSDSX . $SubCommand);
    }

    //CinemaEQ
    private function CinemaEQ(int $instance_id, bool $Value): void
    {
        // CinemaEQ: true (On) or false (Off)
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CINEMAEQCOMMAND, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CINEMAEQCOMMAND . $SubCommand);
    }

    //Panorama
    private function Panorama(int $instance_id, bool $Value): void
    {
        // Panorama: true (On) or false (Off)
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::PSPAN, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSPAN . $SubCommand);
    }

    //Dynamic EQ
    private function DynamicEQ(int $instance_id, bool $Value): void
    {
        // Dynamic EQ: true (On) or false (Off)
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::PSDYNEQ, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSDYNEQ . $SubCommand);
    }

    //Channel Volume
    //Channel Volume Front Left
    private function ChannelVolumeFL(int $instance_id, float $Value): void
    {
        // Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVFL, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVFL . $SubCommand);
    }

    //Channel Volume Front Right
    private function ChannelVolumeFR(int $instance_id, float $Value): void
    {
        // Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVFR, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVFR . $SubCommand);
    }

    //Channel Volume Center
    private function ChannelVolumeC(int $instance_id, float $Value): void
    {
        // Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVC, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVC . $SubCommand);
    }

    //Channel Volume Subwoofer
    private function ChannelVolumeSW(int $instance_id, float $Value): void
    {
        // Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVSW, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVSW . $SubCommand);
    }

    //Channel Volume Subwoofer 2
    private function ChannelVolumeSW2(int $instance_id, float $Value): void
    {
        // Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVSW2, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVSW2 . $SubCommand);
    }

    //Channel Volume Surround Left
    private function ChannelVolumeSL(int $instance_id, float $Value): void
    {
        // Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVSL, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVSL . $SubCommand);
    }

    //Channel Volume Surround Right
    private function ChannelVolumeSR(int $instance_id, float $Value): void
    {
        // Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVSR, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVSR . $SubCommand);
    }

    //Channel Volume Surround Back Left
    private function ChannelVolumeSBL(int $instance_id, float $Value): void
    {
        // Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVSBL, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVSBL . $SubCommand);
    }

    //Channel Volume Surround Back Right
    private function ChannelVolumeSBR(int $instance_id, float $Value): void
    {
        // Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVSBR, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVSBR . $SubCommand);
    }

    //Channel Volume Surround Back
    private function ChannelVolumeSB(int $instance_id, float $Value): void
    {
        // Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVSB, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVSB . $SubCommand);
    }

    //Channel Volume Front Height Left
    private function ChannelVolumeFHL(int $instance_id, float $Value): void
    {
        // Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVFHL, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVFHL . $SubCommand);
    }

    //Channel Volume Front Height Right
    private function ChannelVolumeFHR(int $instance_id, float $Value): void
    {
        // Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVFHR, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVFHR . $SubCommand);
    }

    //Channel Volume Front Wide Left
    private function ChannelVolumeFWL(int $instance_id, float $Value): void
    {
        // Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVFWL, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVFWL . $SubCommand);
    }

    //Channel Volume Front Wide Right
    private function ChannelVolumeFWR(int $instance_id, float $Value): void
    {
        // Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVFWR, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVFWR . $SubCommand);
    }

    //Channel Volume Surround Height Left
    private function ChannelVolumeSHL(int $instance_id, float $Value): void
    {
        // Surround Height Left Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVSHL, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVSHL . $SubCommand);
    }

    //Channel Volume Surround Height Right
    private function ChannelVolumeSHR(int $instance_id, float $Value): void
    {
        // Surround Height Right Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVSHR, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVSHR . $SubCommand);
    }

    //Channel Volume Top Surround
    private function ChannelVolumeTS(int $instance_id, float $Value): void
    {
        // Top Surround Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVTS, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVTS . $SubCommand);
    }

    //Channel Volume Center Height
    private function ChannelVolumeCH(int $instance_id, float $Value): void
    {
        // Center Height Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVCH, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVCH . $SubCommand);
    }

    //Reset all Channel Volume
    private function ChannelVolumeZRL(int $instance_id): void
    {
        // Reset all channel volume status
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVZRL);
    }

    //Channel Volume Top Front Left
    private function ChannelVolumeTFL(int $instance_id, float $Value): void
    {
        // Top Front Left Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVTFL, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVTFL . $SubCommand);
    }

    //Channel Volume Top Front Right
    private function ChannelVolumeTFR(int $instance_id, float $Value): void
    {
        // Top Front Right Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVTFR, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVTFR . $SubCommand);
    }

    //Channel Volume Top Middle Left
    private function ChannelVolumeTML(int $instance_id, float $Value): void
    {
        // Top Middle Left Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVTML, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVTML . $SubCommand);
    }

    //Channel Volume Top Middle Right
    private function ChannelVolumeTMR(int $instance_id, float $Value): void
    {
        // Top Middle Right Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVTMR, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVTMR . $SubCommand);
    }

    //Channel Volume Top Rear Left
    private function ChannelVolumeTRL(int $instance_id, float $Value): void
    {
        // Top Rear Left Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVTRL, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVTRL . $SubCommand);
    }

    //Channel Volume Top Rear Right
    private function ChannelVolumeTRR(int $instance_id, float $Value): void
    {
        // Top Rear Right Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVTRR, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVTRR . $SubCommand);
    }

    //Channel Volume Rear Height Left
    private function ChannelVolumeRHL(int $instance_id, float $Value): void
    {
        // Rear Height Left Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVRHL, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVRHL . $SubCommand);
    }

    //Channel Volume Rear Height Right
    private function ChannelVolumeRHR(int $instance_id, float $Value): void
    {
        // Rear Height Right Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVRHR, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVRHR . $SubCommand);
    }

    //Channel Volume Front Dolby Left
    private function ChannelVolumeFDL(int $instance_id, float $Value): void
    {
        // Front Dolby Left Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVFDL, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVFDL . $SubCommand);
    }

    //Channel Volume Front Dolby Right
    private function ChannelVolumeFDR(int $instance_id, float $Value): void
    {
        // Front Dolby Right Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVFDR, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVFDR . $SubCommand);
    }

    //Channel Volume Surround Dolby Left
    private function ChannelVolumeSDL(int $instance_id, float $Value): void
    {
        // Surround Dolby Left Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVSDL, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVSDL . $SubCommand);
    }

    //Channel Volume Surround Dolby Right
    private function ChannelVolumeSDR(int $instance_id, float $Value): void
    {
        // Surround Dolby Right Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVSDR, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVSDR . $SubCommand);
    }

    //Channel Volume Back Dolby Left
    private function ChannelVolumeBDL(int $instance_id, float $Value): void
    {
        // Back Dolby Left Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVBDL, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVBDL . $SubCommand);
    }

    //Channel Volume Back Dolby Right
    private function ChannelVolumeBDR(int $instance_id, float $Value): void
    {
        // Back Dolby Right Range -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::CVBDR, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::CVBDR . $SubCommand);
    }

    //RecSelect
    private function RecSelect(int $instance_id, string $command): void
    {
        // NET/USB; USB; NAPSTER; LASTFM; FLICKR; FAVORITES; IRADIO; SERVER; SERVER;  USB/IPOD
        DAVRT_SendCommand($instance_id, DENON_API_Commands::SR . $command);
    }

    //Video Select
    private function VideoSelect(int $instance_id, string $command): void
    {
        // Video Select DVD , BD , TV , SAT/CBL , DVR ,GAME , AUX , DOCK , SOURCE, MPLAY
        $manufacturername = $this->GetManufacturerName();
        $AVRType = $this->GetAVRType($manufacturername);
        if ($command === 'AUX') {
            if (in_array(
                $AVRType,
                [
                    'AVR-X7200W',
                    'AVR-X5200W',
                    'AVR-X4100W',
                    'AVR-X3100W',
                    'AVR-X2000',
                    'AVR-X2100W',
                    'AVR-X2200W',
                    'AVR-X2300W',
                    'S900W',
                    'AVR-X7200WA',
                    'AVR-X6200W',
                    'AVR-X4200W',
                    'AVR-X3200W',
                    'AVR-X1200W']
            )) {
                $command = 'AUX1';
            } else {
                $command = 'V.AUX';
            }
        }
        $payload = DENON_API_Commands::SV . $command;
        DAVRT_SendCommand($instance_id, $payload);
    }

    //Subwoofer
    private function Subwoofer(int $instance_id, bool $Value): void
    {
        // Subwoofer true (On) or false (Off)
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::PSSWR, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSSWR . $SubCommand);
    }

    //Subwoofer ATT
    private function SubwooferATT(int $instance_id, bool $Value): void
    {
        // Subwoofer ATT true (On) or false (Off)
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::PSATT, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSATT . $SubCommand);
    }

    //Subwoofer Output Off
    private function SubwooferOutputOff(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::SSSPCSWF . DENON_API_Commands::NON);
    }

    //Subwoofer Output One
    private function SubwooferOutputOne(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::SSSPCSWF . DENON_API_Commands::SPONE);
    }

    //Subwoofer Output Two
    private function SubwooferOutputTwo(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::SSSPCSWF . DENON_API_Commands::SPTWO);
    }

    //Speaker Front Small
    private function SpeakerFrontSmall(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::SSSPCFRO . DENON_API_Commands::SMA);
    }

    //Speaker Front Large
    private function SpeakerFrontLarge(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::SSSPCFRO . DENON_API_Commands::LAR);
    }

    //Speaker Center Small
    private function SpeakerCenterSmall(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::SSSPCCEN . DENON_API_Commands::SMA);
    }

    //Speaker Center Large
    private function SpeakerCenterLarge(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::SSSPCCEN . DENON_API_Commands::LAR);
    }

    //Front Height
    private function FrontHeight(int $instance_id, bool $Value): void
    {
        // Front Height true (On) or false (Off)
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::PSFH, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSFH . $SubCommand);
    }

    //Tone CTRL
    private function ToneCTRL(int $instance_id, bool $Value): void
    {
        // Tone CTRL true (On) or false (Off)
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::TONECTRL, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::TONECTRL . $SubCommand);
    }

    //Audio Delay
    private function AudioDelay(int $instance_id, int $Value): void
    {
        // can be operated from 0 to 300
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::PSDELAY, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSDELAY . $SubCommand);
    }

    //Speaker Output Front
    private function SpeakerOutputFront(int $instance_id, string $Value): void
    {
        // Speaker Output Front Off / Wide / Height / Height/Wide
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValueName(DENON_API_Commands::PSSP, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSSP . $SubCommand);
    }

    //Auto Flag Detect Mode
    private function AutoFlagDetectMode(int $instance_id, bool $Value): void
    {
        // Auto Flag Detect Mode true (On) or false (Off)
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::PSAFD, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSAFD . $SubCommand);
    }

    //ASP
    private function ASP(int $instance_id, string $Value): void
    {
        // ASP Normal / Full
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValueName(DENON_API_Commands::VSASP, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::VSASP . $SubCommand);
    }

    //Audio Restorer
    private function AudioRestorer(int $instance_id, string $Value): void
    {
        // Audio Restorer Off / 64 / 96 / HQ
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValueName(DENON_API_Commands::PSRSTR, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSRSTR . $SubCommand);
    }

    //Center Image
    private function CenterImage(int $instance_id, float $Value): void
    {
        //Center Image can be operated from 0.0 to 1.0 Step 0.1
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::PSCEI, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSCEI . $SubCommand);
    }

    //Center Width
    private function CenterWidth(int $instance_id, float $Value): void
    {
        //Center Width can be operated from 0 to 7 Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::PSCEN, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSCEN . $SubCommand);
    }

    //Select Decode Mode
    private function SelectDecodeMode(int $instance_id, string $Value): void
    {
        // AUTO; HDMI; DIGITAL; ANALOG
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValueName(DENON_API_Commands::SD, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::SD . $SubCommand);
    }

    //Digital Input Mode
    private function DigitalInputMode(int $instance_id, string $Value): void
    {
        // Digital Input Mode Auto / PCM / DTS
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValueName(DENON_API_Commands::DC, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::DC . $SubCommand);
    }

    //Dimension
    private function Dimension(int $instance_id, int $Value): void
    {
        //Dimension can be operated from 0 to 6
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::PSDIM, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSDIM . $SubCommand);
    }

    //Effect Level
    private function EffectLevel(int $instance_id, float $Value): void
    {
        //Effect Level can be operated from 1 to 15 Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::PSEFF, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSEFF . $SubCommand);
    }

    //HDMI Audio Output
    private function HDMIAudioOutput(int $instance_id, string $Value): void
    {
        // HDMI Audio Output TV / AMP
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValueName(DENON_API_Commands::VSAUDIO, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::VSAUDIO . $SubCommand);
    }

    //Multi EQ Mode
    private function MultiEQMode(int $instance_id, string $Value): void
    {
        // Multi EQ Mode Audyssey / BYP.LR / Flat / Manual / Off
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValueName(DENON_API_Commands::PSMULTEQ, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSMULTEQ . $SubCommand);
    }

    //PLIIZHeightGain
    private function PLIIZHeightGain(int $instance_id, string $Value): void
    {
        // PLIIZHeightGain Low / Middle / High
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValueName(DENON_API_Commands::PSPHG, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSPHG . $SubCommand);
    }

    //Reference Level
    private function ReferenceLevel(int $instance_id, int $Value): void
    {
        // Reference Level 0 / 5 / 10 / 15
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::PSREFLEV, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSREFLEV . $SubCommand);
    }

    //Room Size
    private function RoomSize(int $instance_id, string $Value): void
    {
        // Room Size Small / Small/Medium / Medium / Medium/Large / Large
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValueName(DENON_API_Commands::PSRSZ, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSRSZ . $SubCommand);
    }

    //Stage Width
    private function StageWidth(int $instance_id, float $Value): void
    {
        //Stage Width can be operated from -10 to +10 Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValueName(DENON_API_Commands::PSSTW, (string)$Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSSTW . $SubCommand);
    }

    //Stage Height
    private function StageHeight(int $instance_id, float $Value): void
    {
        //Stage Height can be operated from -10 to +10 Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValueName(DENON_API_Commands::PSSTH, (string)$Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSSTH . $SubCommand);
    }

    //Surround Back Mode
    private function SurroundBackMode(int $instance_id, string $Value): void
    {
        // Surround Back Mode Off / On / Matrix / Cinema / Music
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValueName(DENON_API_Commands::PSSB, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSSB . $SubCommand);
    }

    //Surround Play Mode
    private function SurroundPlayMode(int $instance_id, string $Value): void
    {
        // Surround Play Mode Music / Cinema / Game / Pro Logic
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValueName(DENON_API_Commands::PSMODE, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSMODE . $SubCommand);
    }

    //Vertical Stretch
    private function VerticalStretch(int $instance_id, bool $Value): void
    {
        // VerticalStretch true (On) or false (Off)
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::VSVST, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::VSVST . $SubCommand);
    }

    //Contrast
    private function Contrast(int $instance_id, float $Value): void
    {
        // Contrast can be operated from -6 to 6 Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::PVCN, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PVCN . $SubCommand);
    }

    //Brightness
    private function Brightness(int $instance_id, float $Value): void
    {
        //Brightness can be operated from 0 to 12 Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::PVBR, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PVBR . $SubCommand);
    }

    //Chroma Level
    private function ChromaLevel(int $instance_id, float $Value): void
    {
        //Chroma Level can be operated from -6 to 6 Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::PVCM, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PVCM . $SubCommand);
    }

    //Digital Noise Reduction
    private function DigitalNoiseReduction(int $instance_id, string $Value): void
    {
        // Digital Noise Reduction Off / Low / Middle / High
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValueName(DENON_API_Commands::PVDNR, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PVDNR . $SubCommand);
    }

    //Enhancer
    private function Enhancer(int $instance_id, float $Value): void
    {
        //Enhancer can be operated from 0 to 12 Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::PVENH, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PVENH . $SubCommand);
    }

    //HDMI Monitor
    private function HDMIMonitor(int $instance_id, string $Value): void
    {
        // HDMI Monitor AUTO / Monitor 1 / Monitor 2
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValueName(DENON_API_Commands::VSMONI, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::VSMONI . $SubCommand);
    }

    //Hue
    private function Hue(int $instance_id, float $Value): void
    {
        //Enhancer can be operated from -6 to 6 Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::PVHUE, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PVHUE . $SubCommand);
    }

    //Resolution
    private function Resolution(int $instance_id, string $Value): void
    {
        // Resolution 480p/576p / 1080i / 720p / 1080p / 1080p:24Hz / Auto / 4K / 4K(60/50)
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValueName(DENON_API_Commands::VSSC, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::VSSC . $SubCommand);
    }

    //Resolution HDMI
    private function ResolutionHDMI(int $instance_id, string $Value): void
    {
        //Resolution HDMI 480p/576p / 1080i / 720p / 1080p / 1080p:24Hz / Auto / 4K / 4K(60/50)
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValueName(DENON_API_Commands::VSSCH, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::VSSCH . $SubCommand);
    }

    //Video Processing Mode
    private function VideoProcessingMode(int $instance_id, string $Value): void
    {
        // Video Processing Mode Auto / Game / Movie
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValueName(DENON_API_Commands::VSVPM, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::VSVPM . $SubCommand);
    }

    //GUI Menu
    private function GUIMenu(int $instance_id, bool $Value): void
    {
        // GUI Setup Menu true (On) or false (Off)
        if ($Value === false) {
            $subcommand = DENON_API_Commands::MNMENOFF;
        } else {
            $subcommand = DENON_API_Commands::MNMENON;
        }
        $payload = DENON_API_Commands::MNMEN . $subcommand;
        DAVRT_SendCommand($instance_id, $payload);
    }

    //GUI Source Select Menu
    private function GUISourceSelectMenu(int $instance_id, bool $Value): void
    {
        // GUI Source Select Menu true (On) or false (Off)
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::MNSRC, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::MNSRC . $SubCommand);
    }

    //PS
    private function ParameterSettings(int $instance_id, string $subcommand): void
    {
        // PS
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PS . $subcommand);
    }

    //Preset Analog Tuner
    private function SelectTunerPresetAnalog(int $instance_id, string $Value): void
    {
        // A1 - G8 00-55, 00=A1, 01=A2, B1=08, G8=55 , Up, Down
        if ($Value === 'Up') {
            $subcommand = DENON_API_Commands::TPANUP;
        } elseif ($Value === 'Down') {
            $subcommand = DENON_API_Commands::TPANDOWN;
        } else {
            $FunctionType = 'Range00to55';
            $subcommand = $this->GetCommandValueSend($Value, $FunctionType);
        }
        $payload = DENON_API_Commands::TPAN . $subcommand;
        DAVRT_SendCommand($instance_id, $payload);
    }

    //Preset Network Audio
    private function SelectPresetNetworkAudio(int $instance_id, bool $Value): void
    {
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::MNSRC, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::MNSRC . $SubCommand);
    }

    //####################### Cursor Steuerung ######################################

    //Cursor Up
    private function CursorUp(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::MN . DENON_API_Commands::MNCUP);
    }

    //Cursor Down
    private function CursorDown(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::MN . DENON_API_Commands::MNCDN);
    }

    //Cursor Left
    private function CursorLeft(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::MN . DENON_API_Commands::MNCLT);
    }

    //Cursor Right
    private function CursorRight(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::MN . DENON_API_Commands::MNCRT);
    }

    //Enter
    private function Enter(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::MN . DENON_API_Commands::MNENT);
    }

    //Cursor Return
    private function CursorReturn(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::MN . DENON_API_Commands::MNRTN);
    }

    //Levels

    //Bass Level
    private function BassLevel(int $instance_id, float $Value): void
    {
        // can be operated from -6 to +6, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::PSBAS, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSBAS . $SubCommand);
    }

    //Treble Level
    private function TrebleLevel(int $instance_id, float $Value): void
    {
        // can be operated from -6 to +6, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::PSTRE, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSTRE . $SubCommand);
    }

    //LFE Level
    private function LFELevel(int $instance_id, float $Value): void
    {
        // can be operated from 0 to -10, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::PSLFE, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::PSLFE . $SubCommand);
    }

    //Sleep
    private function SLEEP(int $instance_id, int $Value): void
    {
        // 0 ist aus bis 120 Step 10
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::SLP, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::SLP . $SubCommand);
    }

    //Network Audio Navigation
    private function NACursorUp(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::NSUP);
    }

    private function NACursorDown(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::NSDOWN);
    }

    private function NACursorLeft(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::NSLEFT);
    }

    private function NACursorRight(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::NSRIGHT);
    }

    private function NAEnter(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::NSENTER);
    }

    private function NAPlay(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::NSPLAY);
    }

    private function NAPause(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::NSPAUSE);
    }

    private function NAStop(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::NSSTOP);
    }

    private function NASkipPlus(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::NSSKIPPLUS);
    }

    private function NASkipMinus(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::NSSKIPMINUS);
    }

    private function NARepeatOne(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::NSREPEATONE);
    }

    private function NARepeatAll(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::NSREPEATALL);
    }

    private function NARepeatOff(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::NSREPEATOFF);
    }

    private function NARandomOn(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::NSRANDOMON);
    }

    private function NARandomOff(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::NSRANDOMOFF);
    }

    private function NAPageNext(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::NSPAGENEXT);
    }

    private function NAPagePrevious(int $instance_id): void
    {
        DAVRT_SendCommand($instance_id, DENON_API_Commands::NSPAGEPREV);
    }

    //####################### Zone 2 functions ######################################

    private function Z2_Volume(int $instance_id, string $command): void
    {
        // "UP" or "DOWN"
        DAVRT_SendCommand($instance_id, DENON_API_Commands::Z2 . $command);
    }

    private function Zone2VolumeFix(int $instance_id, float $Value): void
    {
        // 18(db) bis -80(db), Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::Z2VOL, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::Z2 . $SubCommand);
    }

    private function Zone2Power(int $instance_id, bool $Value): void
    {
        // Zone2 Power  true (On) or false (Off)
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::Z2POWER, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::Z2 . $SubCommand);
    }

    private function Zone2Mute(int $instance_id, bool $Value): void
    {
        // Zone2 Mute  true (On) or false (Off)
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::Z2MU, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::Z2MU . $SubCommand);
    }

    private function Zone2InputSource(int $instance_id, string $subcommand): void
    {
        // PHONO ; DVD ; HDP ; "TV/CBL" ; SAT ; "NET/USB" ; DVR ; TUNER
        DAVRT_SendCommand($instance_id, DENON_API_Commands::Z2 . $subcommand);
    }

    private function Zone2ChannelVolumeFL(int $instance_id, float $Value): void
    {
        // -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::Z2CVFL, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::Z2CVFL . $SubCommand);
    }

    private function Zone2ChannelVolumeFR(int $instance_id, float $Value): void
    {
        // -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::Z2CVFR, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::Z2CVFR . $SubCommand);
    }

    private function Zone2ChannelSetting(int $instance_id, string $Value): void
    {
        // Zone 2 Channel Setting: Stereo/Mono
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValueName(DENON_API_Commands::Z2CS, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::Z2CS . $SubCommand);
    }

    private function Zone2QuickSelect(int $instance_id, string $command): void
    {
        // Zone 2 Quickselect 1-5
        DAVRT_SendCommand($instance_id, DENON_API_Commands::Z2QUICK . $command);
    }

    //######################### Zone 3 Functions ####################################

    private function Z3_Volume(int $instance_id, string $command): void
    {
        // "UP" or "DOWN"
        DAVRT_SendCommand($instance_id, DENON_API_Commands::Z3 . $command);
    }

    private function Zone3VolumeFix(int $instance_id, float $Value): void
    {
        // 18(db) bis -80(db), Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::Z3VOL, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::Z3 . $SubCommand);
    }

    private function Zone3Power(int $instance_id, bool $Value): void
    {
        // Zone3 Power  true (On) or false (Off)
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::Z3POWER, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::Z3 . $SubCommand);
    }

    private function Zone3Mute(int $instance_id, bool $Value): void
    {
        // Zone3 Mute  true (On) or false (Off)
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::Z3MU, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::Z3MU . $SubCommand);
    }

    private function Zone3InputSource(int $instance_id, string $subcommand): void
    {
        // PHONO ; DVD ; HDP ; "TV/CBL" ; SAT ; "NET/USB" ; DVR ; TUNER
        DAVRT_SendCommand($instance_id, DENON_API_Commands::Z3 . $subcommand);
    }

    private function Zone3ChannelVolumeFL(int $instance_id, float $Value): void
    {
        // -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::Z3CVFL, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::Z3CVFL . $SubCommand);
    }

    private function Zone3ChannelVolumeFR(int $instance_id, float $Value): void
    {
        // -12 to 12, Step 0.5
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValue(DENON_API_Commands::Z3CVFR, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::Z3CVFR . $SubCommand);
    }

    private function Zone3ChannelSetting(int $instance_id, string $Value): void
    {
        // Zone 3 Channel Setting: Stereo/Mono
        $SubCommand = (new DENONIPSProfiles())->GetSubCommandOfValueName(DENON_API_Commands::Z3CS, $Value);
        DAVRT_SendCommand($instance_id, DENON_API_Commands::Z3CS . $SubCommand);
    }

    private function Zone3QuickSelect(int $instance_id, string $command): void
    {
        // Zone 3 Quickselect 1-5
        DAVRT_SendCommand($instance_id, DENON_API_Commands::Z3QUICK . $command);
    }

    private function GetCompleteHAResponse()
    {
        $now = date('Y-m-d\TH:i:s.000000P');
        $array = [];

        // Light
        $lights = $this->GetDataFromConfigurator("GetLights");
        foreach ($lights as $light) {
            $id = $light["Light_ID"];
            $name = $light["Name"];
            $switchVarID = $light["SwitchVariable"];
            $this->DebugLog("Light", "$name ($switchVarID)", 0);

            $value = GetValue($switchVarID); // Boolean on/off
            $array[] = [
                'entity_id' => "light." . $switchVarID,
                'state' => $value ? 'on' : 'off',
                'attributes' => [
                    'friendly_name' => $name,
                    'manufacturer' => $light["Manufacturer"] ?? '',
                    'model' => $light["Model"] ?? '',
                    'supported_features' => 41
                ],
                'last_changed' => $now,
                'last_updated' => $now,
                'context' => [
                    'id' => "id_light_" . $switchVarID,
                    'parent_id' => null,
                    'user_id' => null
                ]
            ];
        }

        // Switch
        $switches = $this->GetDataFromConfigurator("GetSwitches");
        foreach ($switches as $switch) {
            // $id = $switch["Switch_ID"];
            $name = $switch["Name"];
            $switchVarID = $switch["SwitchVariable"];
            $this->DebugLog("Switch", "$name ($switchVarID)", 0);

            $value = GetValue($switchVarID); // Boolean on/off
            $array[] = [
                'entity_id' => "switch." . $switchVarID,
                'state' => $value ? 'on' : 'off',
                'attributes' => [
                    'friendly_name' => $name,
                    'manufacturer' => $switch["Manufacturer"] ?? '',
                    'model' => $switch["Model"] ?? ''
                ],
                'last_changed' => $now,
                'last_updated' => $now,
                'context' => [
                    'id' => "id_switch_" . $switchVarID,
                    'parent_id' => null,
                    'user_id' => null
                ]
            ];
        }

        // Automation (from Configurator)
        $automationList = $this->GetDataFromConfigurator("GetAutomations");
        $this->DebugLog(__FUNCTION__, "Automations " . json_encode($automationList), 0);
        foreach ($automationList as $automation) {
            $this->DebugLog("Automation", json_encode($automation), 0);
            // $id = $automation["Automation_ID"];
            $script_id = $automation["ScriptID"];
            $name = $automation["Name"];

            $array[] = [
                'entity_id' => "automation." . $script_id,
                'state' => 'on',
                'attributes' => [
                    'friendly_name' => $name,
                    'supported_features' => 0
                ],
                'last_changed' => $now,
                'last_updated' => $now,
                'context' => [
                    'id' => "id_automation_" . $script_id,
                    'parent_id' => null,
                    'user_id' => null
                ]
            ];
        }

        // Temperature Sensors
        $temperature_sensors = $this->GetDataFromConfigurator("GetTemperatureSensors");
        foreach ($temperature_sensors as $sensor) {
            $variable_id = $sensor["TemperatureVariable"];
            // $id = $sensor["Sensor_ID"];
            $name = $sensor["Name"];
            $varID = $sensor["TemperatureVariable"];
            $value = GetValue($varID); // float °C

            $array[] = [
                'entity_id' => "sensor." . $variable_id,
                'state' => strval($value),
                'attributes' => [
                    'device_class' => 'temperature',
                    'unit_of_measurement' => '°C',
                    'state_class' => 'measurement',
                    'friendly_name' => $name
                ],
                'last_changed' => $now,
                'last_updated' => $now,
                'context' => [
                    'id' => "id_sensor_" . $variable_id,
                    'parent_id' => null,
                    'user_id' => null
                ]
            ];
        }

        $batteries = $this->GetDataFromConfigurator("GetBatterySensors");
        foreach ($batteries as $sensor) {
            $varID = $sensor["BatteryVariable"];
            $name = $sensor["Name"];
            $value = GetValue($varID); // Prozent

            $array[] = [
                'entity_id' => "sensor.$varID",
                'state' => strval($value),
                'attributes' => [
                    'device_class' => 'battery',
                    "battery_state" => "normal",
                    'unit_of_measurement' => '%',
                    'state_class' => 'measurement',
                    'friendly_name' => $name
                ],
                'last_changed' => $now,
                'last_updated' => $now,
                'context' => [
                    'id' => "id_battery_$varID",
                    'parent_id' => null,
                    'user_id' => null
                ]
            ];
        }

        $motions = $this->GetDataFromConfigurator("GetMotionSensors");
        foreach ($motions as $sensor) {
            $varID = $sensor["MotionVariable"];
            $name = $sensor["Name"];
            $value = GetValue($varID); // true/false

            $array[] = [
                'entity_id' => "binary_sensor.$varID",
                'state' => $value ? 'on' : 'off',
                'attributes' => [
                    'device_class' => 'motion',
                    'friendly_name' => $name
                ],
                'last_changed' => $now,
                'last_updated' => $now,
                'context' => [
                    'id' => "id_motion_$varID",
                    'parent_id' => null,
                    'user_id' => null
                ]
            ];
        }

        $illuminance_sensors = $this->GetDataFromConfigurator("GetIlluminanceSensors");
        foreach ($illuminance_sensors as $sensor) {
            $varID = $sensor["IlluminanceVariable"];
            $name = $sensor["Name"];
            $value = GetValue($varID);

            $array[] = [
                'entity_id' => "sensor.$varID",
                'state' => strval($value),
                'attributes' => [
                    "state_class" => "measurement",
                    "light_level" => 0,
                    "unit_of_measurement" => "lx",
                    "device_class" => "illuminance",
                    'friendly_name' => $name
                ],
                'last_changed' => $now,
                'last_updated' => $now,
                'context' => [
                    'id' => "id_motion_$varID",
                    'parent_id' => null,
                    'user_id' => null
                ]
            ];
        }

        // Media Player
        $media_players = $this->GetDataFromConfigurator("GetMediaPlayers");
        foreach ($media_players as $media_player) {
            $variable_id = $media_player["ControlVariable"];
            // $id = $media_player["Sensor_ID"];
            $name = $media_player["Name"];
            $value = GetValue($variable_id);

            $array[] = [
                'entity_id' => "media_player." . $variable_id,
                'state' => strval($value),
                'attributes' => [
                    'friendly_name' => $name,
                    'supported_features' => 4096,
                    'volume_level' => 0.5
                ],
                'last_changed' => $now,
                'last_updated' => $now,
                'context' => [
                    'id' => "id_media_" . $variable_id,
                    'parent_id' => null,
                    'user_id' => null
                ]
            ];
        }
        return $array;
    }

    private function GetDataFromConfigurator($method)
    {
        // An Childs weiterleiten
        $payload = json_encode([
            'DataID' => '{1025873A-EDF7-BF8E-0337-7C6409CAA9F4}',
            'Buffer' => $method
        ]);

        $data = $this->SendDataToChildren($payload);  // gibt Array mit 1 Element zurück
        $this->HA_Debug(self::TOPIC_ENTITY, '🔎 Configurator child request result', self::LV_INFO, [
            'method' => $method,
            'response_count' => is_array($data) ? count($data) : 0
        ]);

        // prüfen, ob Antwort da ist
        if (is_array($data) && isset($data[0])) {
            $decoded = json_decode($data[0], true);  // ← hier erfolgt die echte Umwandlung
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function GetImportConfiguratorData(string $method): array
    {
        $propertyByMethod = [
            'GetAutomations' => 'Automations',
            'GetSwitches' => 'Switches',
            'GetLights' => 'Lights',
            'GetTemperatureSensors' => 'TemperatureSensor',
            'GetBatterySensors' => 'BatterySensor',
            'GetHumiditySensors' => 'HumiditySensor',
            'GetMotionSensors' => 'MotionSensor',
            'GetIlluminanceSensors' => 'IlluminanceSensor',
            'GetMediaPlayers' => 'MediaPlayer'
        ];

        $property = $propertyByMethod[$method] ?? '';
        if ($property === '') {
            return [];
        }

        $importConfiguratorID = $this->FindConnectedImportConfiguratorID();
        if ($importConfiguratorID === 0) {
            $this->HA_Debug(self::TOPIC_ENTITY, '⚠️ No Haptique Import Configurator selected for fallback', self::LV_WARN, [
                'method' => $method
            ]);
            return [];
        }

        $raw = IPS_GetProperty($importConfiguratorID, $property);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->HA_Debug(self::TOPIC_ENTITY, '⚠️ Import Configurator property is not valid JSON', self::LV_WARN, [
                'instance_id' => $importConfiguratorID,
                'property' => $property,
                'raw_preview' => mb_substr($raw, 0, 1000)
            ]);
            return [];
        }

        return $decoded;
    }

    private function FindConnectedImportConfiguratorID(): int
    {
        $instanceIDs = @IPS_GetInstanceListByModuleID('{5D7C18DA-ED94-27CF-6863-0A307AC97175}');
        if (!is_array($instanceIDs) || $instanceIDs === []) {
            return 0;
        }

        $candidates = [];
        foreach ($instanceIDs as $instanceID) {
            $instance = @IPS_GetInstance((int)$instanceID);
            if (!is_array($instance)) {
                continue;
            }

            $connectionID = (int)($instance['ConnectionID'] ?? 0);
            $parentID = (int)($instance['ParentID'] ?? 0);
            $candidates[] = [
                'instance_id' => (int)$instanceID,
                'connection_id' => $connectionID,
                'parent_id' => $parentID
            ];

            if ($connectionID === $this->InstanceID || $parentID === $this->InstanceID) {
                return (int)$instanceID;
            }
        }

        if (count($instanceIDs) === 1) {
            $fallbackID = (int)$instanceIDs[0];
            $this->HA_Debug(self::TOPIC_ENTITY, '⚠️ Using only Haptique Import Configurator as fallback although parent link was not detected', self::LV_WARN, [
                'instance_id' => $fallbackID,
                'splitter_id' => $this->InstanceID,
                'candidates' => $candidates
            ]);
            return $fallbackID;
        }

        $this->HA_Debug(self::TOPIC_ENTITY, '⚠️ Multiple Haptique Import Configurators found, no parent match', self::LV_WARN, [
            'splitter_id' => $this->InstanceID,
            'candidates' => $candidates
        ]);
        return 0;
    }

}
