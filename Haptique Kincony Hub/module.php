<?php

declare(strict_types=1);
	class HaptiqueKinconyHub extends IPSModuleStrict
	{
        public function GetCompatibleParents(): string
        {
            // Erzwinge eine eigene WebSocket-Instanz, falls keine existiert
            return json_encode([
                'type' => 'require',
                'moduleIDs' => [
                    '{D68FD31F-0E90-7019-F16C-1949BD3079EF}' // WebSocket Client
                ]
            ]);
        }

        public function Create(): void
        {
			//Never delete this line!
			parent::Create();


            $this->RegisterPropertyString("host", "");
            $this->RegisterPropertyString("token", "");
            $this->RegisterPropertyInteger("Port", 3777);
            $this->RegisterPropertyInteger("timeout", 5);
            $this->RegisterPropertyBoolean("verifyTls", true);

            // Status polling interval in seconds (0 = disabled)
            $this->RegisterPropertyInteger('StatusInterval', 60);

            // Read-only status attributes (populated by getStatus())
            $this->RegisterAttributeString('StatusMode', '');
            $this->RegisterAttributeString('StatusHostname', '');
            $this->RegisterAttributeString('StatusInstance', '');
            $this->RegisterAttributeString('StatusSSID', '');
            $this->RegisterAttributeString('StatusMAC', '');
            $this->RegisterAttributeString('StatusFirmware', '');
            $this->RegisterAttributeString('StatusStaIp', '');
            $this->RegisterAttributeString('StatusLastUpdate', '');
            // WebHook download secret (used to protect file downloads)
            $this->RegisterAttributeString('DownloadSecret', '');

            // Variable: hub reachable (based on last status poll)
            $this->RegisterVariableBoolean('HubOnline', $this->Translate('Hub online'), '~Alert', 1);
            $this->SetValue('HubOnline', false);

            // Timer for cyclic status polling
            $this->RegisterTimer('PollStatus', 0, 'CRSXKH_PollStatus($_IPS[\'TARGET\']);');

            //We need to call the RegisterHook function on Kernel READY
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
		}

		public function Destroy(): void
        {
            // Debug-Information zur Überprüfung, dass Destroy aufgerufen wird
            $this->SendDebug('Destroy', 'Destroy-Methode wird aufgerufen', 0);

            // Webhook löschen, falls dieser existiert
            $this->UnregisterHook('haptique_kinconyhub/' . $this->InstanceID . '/download');

            //Never delete this line!
			parent::Destroy();
		}

        public function ApplyChanges(): void
        {
            //Never delete this line!
            parent::ApplyChanges();

            // Ensure we have a persistent download secret
            $secret = (string)$this->ReadAttributeString('DownloadSecret');
            if ($secret === '') {
                try {
                    $secret = bin2hex(random_bytes(16));
                } catch (Throwable $e) {
                    // Fallback if random_bytes is unavailable
                    $secret = md5(uniqid((string)$this->InstanceID, true));
                }
                $this->WriteAttributeString('DownloadSecret', $secret);
            }

            //Only call this in READY state. On startup the WebHook instance might not be available yet
            if (IPS_GetKernelRunlevel() == KR_READY) {
                $this->RegisterHook('haptique_kinconyhub/' . $this->InstanceID . '/download');
            }

            // Set module status explicitly
            if ($this->ReadPropertyString("host") !== '') {
                $this->SetStatus(IS_ACTIVE);
            } else {
                $this->SetStatus(IS_INACTIVE);
            }

            // Configure cyclic status polling (0 = disabled)
            $intervalSec = (int)$this->ReadPropertyInteger('StatusInterval');
            if ($this->ReadPropertyString('host') === '' || $intervalSec <= 0) {
                $this->SetTimerInterval('PollStatus', 0);
            } else {
                // Keep it at least 15s to avoid spamming the hub
                if ($intervalSec < 15) {
                    $intervalSec = 15;
                }
                $this->SetTimerInterval('PollStatus', $intervalSec * 1000);
            }
        }

        public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
        {
            //Never delete this line!
            parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

            if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
                $this->SendDebug(__FUNCTION__, '✅ Kernel READY – sende Initial-Events', 0);
                $this->RegisterHook('haptique_kinconyhub/' . $this->InstanceID . '/download');
            }
        }


        /**
         * WebHook handler (download exported files from /media subfolder).
         *
         * URL pattern:
         *   /hook/haptique_kinconyhub/<InstanceID>/download?file=<filename>&token=<secret>
         */
        public function ProcessHookData(): void
        {
            $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
            $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
            $this->SendDebug('WEBHOOK', 'QUERY_STRING=' . $qs, 0);
            // Some Symcon environments do not include the query string in REQUEST_URI.
            // Therefore we primarily rely on $_GET for parameters.
            $this->SendDebug('WEBHOOK', 'GET=' . json_encode($_GET, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
            // --- Debug: incoming request overview (do NOT leak full token/secret) ---
            $this->SendDebug('WEBHOOK', 'REQUEST_URI=' . $uri, 0);
            $this->SendDebug('WEBHOOK', 'SERVER_ADDR=' . ((string)($_SERVER['SERVER_ADDR'] ?? '')) . ' REMOTE_ADDR=' . ((string)($_SERVER['REMOTE_ADDR'] ?? '')), 0);
            $this->SendDebug('WEBHOOK', 'HTTPS=' . ((string)($_SERVER['HTTPS'] ?? '')) . ' HTTP_HOST=' . ((string)($_SERVER['HTTP_HOST'] ?? '')) . ' SERVER_PORT=' . ((string)($_SERVER['SERVER_PORT'] ?? '')), 0);

            $parsed = parse_url($uri);
            $path = (string)($parsed['path'] ?? '');

            // Prefer QUERY_STRING / $_GET over REQUEST_URI parsing, as REQUEST_URI may be missing the query part.
            $query = (string)($parsed['query'] ?? '');
            if ($query === '' && $qs !== '') {
                $query = $qs;
            }

            $params = [];
            if (!empty($_GET)) {
                $params = $_GET;
            } elseif ($query !== '') {
                parse_str($query, $params);
            }

            $this->SendDebug('WEBHOOK', 'path=' . $path . ' query=' . $query . ' (params_source=' . (!empty($_GET) ? '$_GET' : ($query !== '' ? 'QUERY_STRING' : 'none')) . ')', 0);

            // Only handle our instance-specific hook (must match the registered hook exactly)
            $expected = '/hook/haptique_kinconyhub/' . $this->InstanceID . '/download';
            if ($path !== $expected) {
                $this->SendDebug('WEBHOOK', '❌ Path mismatch. expected=' . $expected . ' got=' . $path, 0);
                http_response_code(404);
                echo 'Not found';
                return;
            }
            $this->SendDebug('WEBHOOK', '✅ Path matches expected hook', 0);

            $file = isset($params['file']) ? (string)$params['file'] : '';
            $token = isset($params['token']) ? (string)$params['token'] : '';
            $this->SendDebug('WEBHOOK', 'params[file]=' . $file . ' params[token_prefix]=' . ($token !== '' ? substr($token, 0, 6) . '…' : 'EMPTY') . ' len=' . strlen($token), 0);

            // Basic auth via stored secret
            $secret = (string)$this->ReadAttributeString('DownloadSecret');
            $this->SendDebug('WEBHOOK', 'secret_prefix=' . substr($secret, 0, 6) . '… len=' . strlen($secret), 0);

            if ($secret === '') {
                $this->SendDebug('WEBHOOK', '❌ Forbidden: DownloadSecret attribute is empty', 0);
                http_response_code(403);
                echo 'Forbidden';
                return;
            }

            if ($token === '') {
                $this->SendDebug('WEBHOOK', '❌ Forbidden: token parameter is missing/empty', 0);
                http_response_code(403);
                echo 'Forbidden';
                return;
            }

            if (!hash_equals($secret, $token)) {
                $this->SendDebug('WEBHOOK', '❌ Forbidden: token mismatch (secret_prefix=' . substr($secret, 0, 6) . '… vs token_prefix=' . substr($token, 0, 6) . '…)', 0);
                http_response_code(403);
                echo 'Forbidden';
                return;
            }

            $this->SendDebug('WEBHOOK', '✅ Token OK', 0);

            if ($file === '') {
                $this->SendDebug('WEBHOOK', '❌ Missing file parameter', 0);
                http_response_code(400);
                echo 'Missing file parameter';
                return;
            }

            // Prevent directory traversal
            $file = basename($file);
            $this->SendDebug('WEBHOOK', 'sanitized file=' . $file, 0);

            // Location: /media/KinconyExports/<file>
            $kernelDir = IPS_GetKernelDir();
            $mediaDir = rtrim($kernelDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'media';
            $subDir = $mediaDir . DIRECTORY_SEPARATOR . 'KinconyExports';
            $fullPath = $subDir . DIRECTORY_SEPARATOR . $file;
            $this->SendDebug('WEBHOOK', 'resolved fullPath=' . $fullPath, 0);

            if (!is_file($fullPath)) {
                $this->SendDebug('WEBHOOK', '❌ File not found at fullPath=' . $fullPath, 0);
                http_response_code(404);
                echo 'File not found';
                return;
            }
            $this->SendDebug('WEBHOOK', '✅ File exists, starting download', 0);

            // Try to set a helpful content-type based on extension
            $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $contentType = 'application/octet-stream';
            if ($ext === 'json') {
                $contentType = 'application/json; charset=utf-8';
            } elseif ($ext === 'csv') {
                $contentType = 'text/csv; charset=utf-8';
            } elseif ($ext === 'txt') {
                $contentType = 'text/plain; charset=utf-8';
            }

            header('Content-Type: ' . $contentType);
            header('Content-Disposition: attachment; filename="' . $file . '"');
            header('Content-Length: ' . (string)filesize($fullPath));
            $this->SendDebug('WEBHOOK', 'sending headers contentType=' . $contentType . ' size=' . (string)filesize($fullPath), 0);

            // Output file
            readfile($fullPath);
        }

        /**
         * Helper: returns the instance-specific download URL (relative).
         * You can build an absolute URL by prefixing your Symcon base URL.
         */
        public function GetDownloadUrl(string $filename): string
        {
            $filename = basename($filename);
            $secret = (string)$this->ReadAttributeString('DownloadSecret');
            return '/hook/haptique_kinconyhub/' . $this->InstanceID . '/download?file=' . rawurlencode($filename) . '&token=' . rawurlencode($secret);
        }

        public function ForwardData($JSONString): string
        {
            $this->SendDebug(__FUNCTION__, $JSONString, 0);

            $data = json_decode($JSONString, true);
            if (!is_array($data) || !isset($data['Buffer'])) {
                return json_encode([
                    'error' => 'Invalid ForwardData envelope'
                ]);
            }

            $payload = json_decode($data['Buffer'], true);
            if (!is_array($payload)) {
                return json_encode([
                    'error' => 'Invalid payload JSON'
                ]);
            }

            try {
                $type = $payload['type'] ?? '';

                switch ($type) {
                    case 'getDownloadUrl':
                        return $this->HandleGetDownloadUrl($payload);
                    case 'ir':
                        return $this->HandleIrCommand($payload);

                    case 'rf':
                        return $this->HandleRfCommand($payload);

                    default:
                        return json_encode([
                            'error' => 'Unsupported payload type: ' . $type
                        ]);
                }
            } catch (Throwable $e) {
                $this->SendDebug('ForwardData Exception', $e->getMessage(), 0);
                return json_encode([
                    'error' => $e->getMessage()
                ]);
            }
        }

        /**
         * Handle request from child instances to get a full download URL for an exported file.
         * Expected payload:
         *  - fileName: string
         *  - deviceInstanceId: int (optional, used for logging)
         * Returns JSON: {"url":"..."} or {"error":"..."}
         */
        private function HandleGetDownloadUrl(array $payload): string
        {
            $fileName = (string)($payload['fileName'] ?? '');
            $childId  = (int)($payload['deviceInstanceId'] ?? 0);

            $this->SendDebug('HandleGetDownloadUrl', 'request from child=' . $childId . ' fileName=' . $fileName, 0);

            if (trim($fileName) === '') {
                return json_encode(['error' => 'Missing fileName']);
            }

            // Build absolute URL for the download
            $url = $this->BuildChildDownloadUrl($childId, $fileName);
            if ($url === '') {
                return json_encode(['error' => 'Failed to build download URL']);
            }

            return json_encode(['url' => $url], JSON_UNESCAPED_SLASHES);
        }

        /**
         * Build a full (absolute) URL to download a file via this splitter's webhook.
         * The webhook is implemented by this instance in ProcessHookData().
         */
        public function BuildChildDownloadUrl(int $childInstanceId, string $fileName): string
        {
            // Sanitize file name to avoid traversal
            $fileName = basename($fileName);

            // Relative part (includes token)
            $relative = $this->GetDownloadUrl($fileName);

            // Best-effort base URL discovery
            $baseUrl = $this->DetectWebHookBaseUrl();

            $this->SendDebug('BuildChildDownloadUrl', json_encode([
                'child' => $childInstanceId,
                'file' => $fileName,
                'baseUrl' => $baseUrl,
                'relative' => $relative
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
            $this->SendDebug('BuildChildDownloadUrl', 'finalUrl=' . (rtrim($baseUrl, '/') . $relative), 0);

            if ($baseUrl === '') {
                // Fallback: return relative URL if base could not be determined
                return $relative;
            }

            return rtrim($baseUrl, '/') . $relative;
        }

        /**
         * Try to detect a usable base URL (scheme://host:port) for the Symcon webhook.
         * This is best-effort; if it fails, BuildChildDownloadUrl will return a relative URL.
         */
        private function DetectWebHookBaseUrl(): string
        {
            $scheme = 'http';
            $port = 3777; // default WebFront/WebHook port

            // Try to read port/SSL settings from the WebHook Control instance *safely*.
            // IPS_GetProperty throws warnings if a property does not exist in the instance schema.
            // Therefore we read the full configuration JSON and check keys.
            try {
                $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
                if (count($ids) > 0) {
                    $whId = $ids[0];
                    $cfgJson = IPS_GetConfiguration($whId);
                    $cfg = json_decode($cfgJson, true);
                    if (is_array($cfg)) {
                        if (isset($cfg['Port']) && is_numeric($cfg['Port']) && (int)$cfg['Port'] > 0) {
                            $port = (int)$cfg['Port'];
                        }

                        // Different Symcon versions may use different SSL property names
                        foreach (['EnableSSL', 'UseSSL', 'SSL'] as $sslKey) {
                            if (!array_key_exists($sslKey, $cfg)) {
                                continue;
                            }
                            $v = $cfg[$sslKey];
                            if (is_bool($v) && $v) {
                                $scheme = 'https';
                                break;
                            }
                            if (is_numeric($v) && (int)$v === 1) {
                                $scheme = 'https';
                                break;
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                // ignore and keep defaults
            }

            // Determine host/IP of the Symcon server.
            // Prefer Sys_GetNetworkInfo (reliable on Symcon), fallback to PHP hostname resolution.
            $host = '';
            try {
                $network = Sys_GetNetworkInfo();
                if (is_array($network)) {
                    foreach ($network as $device) {
                        $ip = (string)($device['IP'] ?? '');
                        // Pick the first plausible IPv4 that is not loopback / APIPA
                        if ($ip !== '' && $ip !== '127.0.0.1' && $ip !== 'localhost' && strpos($ip, '169.254.') !== 0) {
                            $host = $ip;
                            break;
                        }
                    }
                }
            } catch (Throwable $e) {
                // ignore
            }

            if ($host === '') {
                try {
                    $host = gethostbyname(gethostname());
                    if (!is_string($host)) {
                        $host = '';
                    }
                } catch (Throwable $e) {
                    $host = '';
                }
            }

            // If hostname resolution fails or returns localhost, try server_addr when available
            if ($host === '' || $host === '127.0.0.1' || $host === 'localhost') {
                $serverAddr = (string)($_SERVER['SERVER_ADDR'] ?? '');
                if ($serverAddr !== '') {
                    $host = $serverAddr;
                }
            }

            if ($host === '') {
                return '';
            }

            return $scheme . '://' . $host . ':' . $port;
        }

        private function HandleIrCommand(array $payload): string
        {
            $code = trim((string)($payload['code'] ?? ''));
            $format = strtoupper(trim((string)($payload['codeFormat'] ?? 'RAW')));
            $freqHz = (int)($payload['frequency'] ?? 38000);
            $repeat = (int)($payload['repeat'] ?? 1);
            $duty = (int)($payload['duty'] ?? 33);
            $deviceName = (string)($payload['deviceName'] ?? '');
            $commandName = (string)($payload['commandName'] ?? '');

            if ($code === '') {
                throw new Exception('IR code is empty');
            }

            if ($repeat < 1) {
                $repeat = 1;
            }

            $freqKhz = (int)round($freqHz / 1000);
            if ($freqKhz < 1) {
                $freqKhz = 38;
            }

            $this->SendDebug('IR Dispatch', json_encode([
                'device' => $deviceName,
                'command' => $commandName,
                'format' => $format,
                'freqKhz' => $freqKhz,
                'repeat' => $repeat
            ]), 0);

            if ($format !== 'RAW') {
                throw new Exception('Only RAW format is supported in splitter');
            }

            // Use existing HTTP method in splitter
            $result = $this->sendIrRawCsv($code, $freqKhz, $duty, $repeat);

            $this->SendDebug('IR Result', json_encode($result), 0);

            return json_encode($result);
        }

        private function HandleRfCommand(array $payload): string
        {
            $code = (int)($payload['code'] ?? 0);
            $bits = (int)($payload['bits'] ?? 24);
            $protocol = (int)($payload['protocol'] ?? 1);
            $repeat = (int)($payload['repeat'] ?? 8);
            $deviceName = (string)($payload['deviceName'] ?? '');
            $commandName = (string)($payload['commandName'] ?? '');

            if ($code <= 0) {
                throw new Exception('RF code is missing/invalid');
            }
            if ($bits <= 0) {
                $bits = 24;
            }
            if ($protocol <= 0) {
                $protocol = 1;
            }
            if ($repeat < 1) {
                $repeat = 1;
            }

            $this->SendDebug('RF Dispatch', json_encode([
                'device' => $deviceName,
                'command' => $commandName,
                'code' => $code,
                'bits' => $bits,
                'protocol' => $protocol,
                'repeat' => $repeat
            ]), 0);

            $result = $this->sendRf($code, $bits, $protocol, $repeat);

            $this->SendDebug('RF Result', json_encode($result), 0);

            return json_encode($result);
        }

        public function ReceiveData($JSONString): string
        {
            $this->SendDebug(__FUNCTION__, "Received JSON: " . $JSONString, 0);
            $data = json_decode($JSONString, true);

            if ($data === null) {
                $this->SendDebug(__FUNCTION__, "❌ Invalid JSON data received!", 0);
                return '';
            }

            // Prüfen, ob ein Buffer existiert
            if (!isset($data["Buffer"])) {
                $this->SendDebug(__FUNCTION__, "⚠️ No Buffer found in received data!", 0);
                return '';
            }

            // Buffer-Daten dekodieren
            $buffer = json_decode($data["Buffer"], true);

            if ($buffer === null) {
                $this->SendDebug(__FUNCTION__, "❌ Failed to decode Buffer JSON!", 0);
                return '';
            }

            // 🟢 WICHTIG: Weiterleiten der Daten
            $this->SendDataToChildren(json_encode([
                'DataID' => '{EFA2DD6A-05BC-CCD8-CCE7-406880F9E98A}',
                'Buffer' => $data["Buffer"]
            ]));
            return '';
        }

        /**
         * Timer callback: poll hub status cyclically.
         * Updates HubOnline variable and status attributes.
         */
        public function PollStatus(): void
        {
            $host = trim((string)$this->ReadPropertyString('host'));
            if ($host === '') {
                $this->SetValue('HubOnline', false);
                return;
            }

            try {
                $this->FetchHubStatus();
                // FetchHubStatus() already updates attributes via UpdateStatusAttributes()
                $this->WriteAttributeString('StatusLastUpdate', date('Y-m-d H:i:s'));
                $this->SetValue('HubOnline', true);
                $this->SendDebug('PollStatus', '✅ Hub reachable, status updated', 0);
            } catch (Throwable $e) {
                $this->SetValue('HubOnline', false);
                $this->WriteAttributeString('StatusLastUpdate', date('Y-m-d H:i:s'));
                $this->SendDebug('PollStatus', '❌ Hub unreachable: ' . $e->getMessage(), 0);
            }
        }

        /* ---------------------------
         * High-level API methods
         * --------------------------- */

        /** GET /api/status */
        public function FetchHubStatus(): array
        {
            $status = $this->requestJson("GET", "/api/status");

            // Update read-only attributes for display in the config form
            $this->UpdateStatusAttributes($status);

            // Optional: UI refresh trigger (harmless)
            // (If IPS doesn't refresh labels automatically, this often helps)
            // $this->UpdateFormField('CantataLogo', 'visible', true);

            return $status;
        }

        /**
         * Store selected status fields as read-only attributes.
         * These are shown in the configuration form.
         */
        private function UpdateStatusAttributes(array $status): void
        {
            $map = [
                'mode'      => 'StatusMode',
                'hostname'  => 'StatusHostname',
                'instance'  => 'StatusInstance',
                'sta_ssid'  => 'StatusSSID',
                'mac'       => 'StatusMAC',
                'fw_ver'    => 'StatusFirmware',
                'sta_ip'    => 'StatusStaIp',
            ];

            foreach ($map as $field => $attr) {
                if (array_key_exists($field, $status)) {
                    $val = $status[$field];
                    $this->SendDebug($attr, $val, 0);
                    // Normalize null/bool
                    if ($val === null || $val === false) {
                        $val = '';
                    } elseif ($val === true) {
                        $val = '1';
                    }

                    $this->WriteAttributeString($attr, (string)$val);
                }
            }
            // Mark last successful status update
            $this->WriteAttributeString('StatusLastUpdate', date('Y-m-d H:i:s'));
        }

        /** GET /api/ir/last */
        public function getLastIrCapture(): array
        {
            return $this->requestJson("GET", "/api/ir/last");
        }

        /**
         * POST /api/ir/send
         *
         * Typical fields (based on repo tooling/examples):
         * - raw: array<int> durations in microseconds (mark/space sequence)
         * - freq_khz: int (e.g. 38)
         * - duty: int percent (e.g. 33)
         * - repeat: int
         */
        public function sendIrRaw(array $durationsUs, int $freqKhz = 38, int $dutyPercent = 33, int $repeat = 1): array
        {
            $durationsUs = $this->sanitizeIntArray($durationsUs);

            $payload = [
                "raw"      => $durationsUs,
                "freq_khz" => $freqKhz,
                "duty"     => $dutyPercent,
                "repeat"   => $repeat,
            ];

            return $this->requestJson("POST", "/api/ir/send", $payload);
        }

        /**
         * Convenience: raw from comma-separated string like "9000,4500,560,560,..."
         */
        public function sendIrRawCsv(string $csv, int $freqKhz = 38, int $dutyPercent = 33, int $repeat = 1): array
        {
            $parts = array_filter(array_map('trim', explode(',', $csv)), fn($v) => $v !== '');
            $durations = array_map('intval', $parts);
            return $this->sendIrRaw($durations, $freqKhz, $dutyPercent, $repeat);
        }

        /**
         * POST /api/rf/send
         *
         * Typical fields (based on repo tooling/examples):
         * - code: int
         * - bits: int (e.g. 24)
         * - protocol: int (e.g. 1)
         * - repeat: int
         */
        public function sendRf(int $code, int $bits = 24, int $protocol = 1, int $repeat = 8): array
        {
            $payload = [
                "code"     => $code,
                "bits"     => $bits,
                "protocol" => $protocol,
                "repeat"   => $repeat,
            ];

            return $this->requestJson("POST", "/api/rf/send", $payload);
        }

        /** POST /api/wifi/save */
        public function saveWifi(string $ssid, string $password): array
        {
            $payload = [
                "ssid" => $ssid,
                "pass" => $password,
            ];

            return $this->requestJson("POST", "/api/wifi/save", $payload);
        }

        /* ---------------------------
         * Core HTTP helpers
         * --------------------------- */

        private function requestJson(string $method, string $path, ?array $jsonBody = null): array
        {
            $resp = $this->request($method, $path, $jsonBody);
            $body = $resp["body"];

            // Some endpoints might return "OK" or plain text; try JSON first.
            $decoded = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }

            // Fallback: wrap plain text into a structure
            return [
                "_raw" => $body,
                "_http" => [
                    "status" => $resp["status"],
                    "headers" => $resp["headers"]
                ]
            ];
        }

        /**
         * @return array{status:int, headers:array<string,string>, body:string}
         */
        private function request(string $method, string $path, ?array $jsonBody = null): array
        {
            $url = "http://" .$this->ReadPropertyString("host") . $path;

            $ch = curl_init($url);
            if ($ch === false) {
                throw new Exception("curl_init failed");
            }

            $headers = [
                "Accept: application/json",
                "Content-Type: application/json",
            ];

            $authToken = trim($this->ReadPropertyString("token"));
            $timeoutSec = $this->ReadPropertyInteger("timeout");
            $verifyTls = $this->ReadPropertyBoolean("verifyTls");

            if ($authToken !== '') {
                $headers[] = "Authorization: Bearer " . $authToken;
            }

            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutSec);

            if (stripos($url, "https://") === 0) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifyTls);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifyTls ? 2 : 0);
            }

            if ($jsonBody !== null) {
                $payload = json_encode($jsonBody, JSON_UNESCAPED_SLASHES);
                if ($payload === false) {
                    throw new Exception("Failed to encode JSON body: " . json_last_error_msg());
                }
                $headers[] = "Content-Type: application/json";
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }

            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $raw = curl_exec($ch);
            if ($raw === false) {
                $err = curl_error($ch);
                $code = curl_errno($ch);
                curl_close($ch);
                throw new Exception("HTTP request failed ($code): $err");
            }

            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);

            $rawHeaders = substr($raw, 0, $headerSize);
            $body = substr($raw, $headerSize);

            $parsedHeaders = $this->parseHeaders($rawHeaders);

            if ($status < 200 || $status >= 300) {
                $snippet = mb_substr(trim($body), 0, 300);

                if ($status === 401 || $status === 403) {
                    throw new Exception("Hub API authorization failed (HTTP $status). Check Bearer token. Body: $snippet");
                }

                throw new Exception("Hub API returned HTTP $status for $method $path. Body: $snippet");
            }

            return [
                "status" => $status,
                "headers" => $parsedHeaders,
                "body" => $body
            ];
        }

        private function parseHeaders(string $rawHeaders): array
        {
            $headers = [];
            $lines = preg_split("/\r\n|\n|\r/", trim($rawHeaders));
            if (!is_array($lines)) return $headers;

            foreach ($lines as $line) {
                if (strpos($line, ":") === false) continue;
                [$k, $v] = explode(":", $line, 2);
                $headers[trim($k)] = trim($v);
            }
            return $headers;
        }

        private function sanitizeIntArray(array $arr): array
        {
            $out = [];
            foreach ($arr as $v) {
                if (is_numeric($v)) {
                    $iv = (int)$v;
                    if ($iv > 0) $out[] = $iv;
                }
            }
            if (count($out) < 2) {
                throw new Exception("IR raw array seems too short/invalid");
            }
            return $out;
        }

        public function GetConfigurationForParent(): string
        {
            $host = $this->ReadPropertyString("host");

            if (empty($host)) {
                $this->SendDebug($this->Translate('WebSocket Configuration'), $this->Translate('Host is missing!'), 0);
                return json_encode([]);
            }

            // Generate correct WebSocket URL
            $wsUrl = "ws://{$host}:81";

            // Konfiguration für den WebSocket-Client
            $config = [
                'URL' => $wsUrl,  // WebSocket-URL
                'VerifyCertificate' => false  // SSL-Zertifikate NICHT prüfen
            ];

            $this->SendDebug($this->Translate('WebSocket Configuration'), json_encode($config), 0);
            return json_encode($config);
        }

        public function GetConfigurationForm(): string
        {
            $Form = json_encode([
                'elements' => $this->FormElements(),
                'actions' => $this->FormActions(),
                'status' => $this->FormStatus()
            ]);

            $this->SendDebug('FORM', $Form, 0);
            $secret = (string)$this->ReadAttributeString('DownloadSecret');
            $this->SendDebug('WEBHOOK', '/hook/haptique_kinconyhub/' . $this->InstanceID . '/download (token=' . substr($secret, 0, 6) . '…)', 0);
            return $Form;
        }

        /**
         * Definiert die Formularelemente für die Konfiguration.
         *
         * @return array
         */
        protected function FormElements(): array
        {
            return [
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
                    'type' => 'ValidationTextBox',
                    'name' => 'host',
                    'caption' => 'Host (IPv4)'
                ],
                [
                    'type' => 'ValidationTextBox',
                    'name' => 'token',
                    'caption' => 'Token'
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'StatusInterval',
                    'caption' => 'Status polling interval (seconds)',
                    'minimum' => 0,
                    'maximum' => 3600,
                    'digits'  => 0
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Status (read-only)',
                    'items'   => [
                        [
                            'type'  => 'RowLayout',
                            'items' => [
                                [
                                    'type'    => 'ValidationTextBox',
                                    'caption' => 'Hub online',
                                    'enabled' => false,
                                    'value'   => (IPS_VariableExists(@$this->GetIDForIdent('HubOnline')) && (bool)$this->GetValue('HubOnline')) ? 'Yes' : 'No'
                                ],
                                [
                                    'type'    => 'ValidationTextBox',
                                    'caption' => 'Last update',
                                    'enabled' => false,
                                    'value'   => $this->ReadAttributeString('StatusLastUpdate')
                                ]
                            ]
                        ],
                        [
                            'type'  => 'RowLayout',
                            'items' => [
                                ['type' => 'ValidationTextBox', 'caption' => 'Mode',  'enabled' => false,   'value' => $this->ReadAttributeString('StatusMode')],
                                ['type' => 'ValidationTextBox', 'caption' => 'Hostname', 'enabled' => false, 'value' => $this->ReadAttributeString('StatusHostname')],
                                ['type' => 'ValidationTextBox', 'caption' => 'Instance', 'enabled' => false, 'value' => $this->ReadAttributeString('StatusInstance')],
                            ]
                        ],
                        [
                            'type'  => 'RowLayout',
                            'items' => [
                                ['type' => 'ValidationTextBox', 'caption' => 'SSID', 'enabled' => false,    'value' => $this->ReadAttributeString('StatusSSID')],
                                ['type' => 'ValidationTextBox', 'caption' => 'MAC', 'enabled' => false,     'value' => $this->ReadAttributeString('StatusMAC')],
                                ['type' => 'ValidationTextBox', 'caption' => 'FW', 'enabled' => false,      'value' => $this->ReadAttributeString('StatusFirmware')],
                            ]
                        ],
                        [
                            'type'  => 'RowLayout',
                            'items' => [
                                ['type' => 'ValidationTextBox', 'caption' => 'STA IP', 'enabled' => false,  'value' => $this->ReadAttributeString('StatusStaIp')],
                            ]
                        ]
                    ]
                ],
            ];
        }

        /**
         * Definiert die Aktionen im Konfigurationsformular.
         *
         * @return array
         */
        protected function FormActions(): array
        {
            return [
                [
                    'type'    => 'Button',
                    'caption' => 'Update status now',
                    'onClick' => 'CRSXKH_FetchHubStatus($id);'
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'Poll status (sets Hub online)',
                    'onClick' => 'CRSXKH_PollStatus($id);'
                ]
            ];
        }

        /**
         * Gibt den Status für das Formular zurück.
         *
         * @return array
         */
        protected function FormStatus(): array
        {
            return [
                ['code' => IS_CREATING, 'icon' => 'inactive', 'caption' => 'Creating instance...'],
                ['code' => IS_ACTIVE, 'icon' => 'active', 'caption' => 'Splitter instance is active.'],
                ['code' => IS_INACTIVE, 'icon' => 'inactive', 'caption' => 'Splitter instance is inactive.']
            ];
        }
	}