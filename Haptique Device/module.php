<?php
declare(strict_types=1);

class HaptiqueDevice extends IPSModuleStrict
{
    public function GetCompatibleParents(): string
    {
        return json_encode([
            'type' => 'connect',
            'moduleIDs' => ['{F7A0DD2E-7684-95C0-64C2-D2A9DC47577B}'] // Parent: MQTT Client (gleich wie im Configurator)
        ]);
    }
    public function Create(): void
    {
        // Never delete this line!
        parent::Create();

        // Device identity
        $this->RegisterPropertyString('RemoteID', '');
        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyString('DeviceName', '');
        $this->RegisterPropertyInteger('ScriptCategory', 0);

        // Commands cache
        $this->RegisterAttributeString('Commands', '[]');
        $this->RegisterAttributeString('CommandIndexToId', '{}');

        // Variables
        $this->RegisterVariableInteger('Command', 'Command', '', 10);
        $this->EnableAction('Command');

        $this->RegisterVariableString('LastCommand', 'Last Command', '', 20);

        $this->SetVisualizationType(0); // 1 Tile

        //we will wait until the kernel is ready
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
    }

    public function Destroy(): void
    {
        // Never delete this line!
        parent::Destroy();
        $this->SetTimerInterval('DeferredSubscribe', 0);
    }

    public function ApplyChanges(): void
    {
        // Never delete this line!
        parent::ApplyChanges();

        // Nur Haptique Topics durchlassen
        $this->SetReceiveDataFilter('.*"Topic"\s*:\s*"Haptique\\/.*".*');

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        // Profil jedes Mal sicher neu aufbauen (z.B. nach Instanz-Neustart)
        $this->RebuildCommandProfileFromAttribute();
    }

    public function StartGetCommands(): void
    {
        $this->SendDebug(__FUNCTION__, 'CALLED', 0);
        // Subscribe auf /detail bzw. /commands (je nach Firmware/Doc)
        $remoteId   = trim($this->ReadPropertyString('RemoteID'));
        $deviceName = $this->ReadPropertyString('DeviceName'); // NICHT trimmen, falls Spaces relevant sind

        if ($remoteId === '' || $deviceName === '') {
            $this->SendDebug(__FUNCTION__, 'RemoteID oder DeviceName leer – kein Subscribe', 0);
            return;
        }

        $baseTopic = 'Haptique/' . $remoteId . '/device/' . $deviceName;

        // $topicDetail   = $baseTopic . '/detail';
        $topicCommands = $baseTopic . '/commands';

        // $this->SendDebug('Subscribe to Topic', $topicDetail, 0);
        // $this->SubscribeTopic($topicDetail);

        // 1) subscribe so we receive retained + future updates
        $this->SendDebug('Subscribe to Topic', $topicCommands, 0);
        $this->SubscribeTopic($topicCommands);

        // 2) RS90 scheint erst nach einem (leeren) publish die Daten rauszugeben
        $this->Publish($topicCommands, '', 0, false);
        // $this->Publish($topicCommands, '', 0, true);

    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        //Never delete this line!
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
            $this->SendDebug("MessageSink", "🔄 Kernel Ready", 0);
            $this->StartGetCommands();
        }

        if ($Message == IPS_KERNELSTARTED) {
            $this->SendDebug("MessageSink", "🔄 Kernel Started", 0);
            $this->StartGetCommands();
        }

        if ($Message == IM_CHANGESTATUS && $Data[0] == IS_ACTIVE) {
            $this->SendDebug("MessageSink", "🔄 Instanz aktiv", 0);
            $this->StartGetCommands();
        }
    }

    public function GetVisualizationTileNO()
    {
        $scriptFileName = 'tileHandler.js';

        // Rufe die Methode auf, die sowohl das HTML als auch das JavaScript verarbeitet und den Platzhalter ersetzt
        $htmlContent = $this->FillHTMLAndScriptTemplate($scriptFileName);

        // HTML-Inhalt zurückgeben, inklusive des Initialisierungs-Skripts
        return $htmlContent;
    }

    private function FillHTMLAndScriptTemplate($scriptFileName)
    {
        // HTML-Datei aus dem Modul-Verzeichnis laden
        $htmlTemplate = file_get_contents(__DIR__ . '/module.html');

        // JavaScript-Datei laden
        $scriptToLoad = file_get_contents(__DIR__ . '/assets/' . $scriptFileName);

        // Ersetze Platzhalter im HTML-Template mit den dynamischen Inhalten und dem eingebundenen JavaScript-Code
        $htmlContent = str_replace(
            '{{SCRIPT_TO_LOAD}}',
            $scriptToLoad,
            $htmlTemplate
        );

        // Debug-Nachrichten für den HTML- und JavaScript-Inhalt

        $this->SendDebug('FillHTMLAndScriptTemplate', 'HTML-Content: ' . $htmlContent, 0);

        return $htmlContent;
    }


    // ---------- MQTT helpers ----------

    private function SubscribeTopic(string $topic): void
    {
        // MQTT SUBSCRIBE packet (PacketType 8)
        $packet = [
            'DataID'          => '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}',
            'PacketType'      => 8,
            'Topic'           => $topic,
            'Payload'         => '',
            'QualityOfService'=> 0,
            'Retain'          => false
        ];

        $this->SendDebug(__FUNCTION__, json_encode($packet, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
        if (!$this->HasActiveParent()) {
            $this->SendDebug('MQTT', 'Skip send - no active parent', 0);
            return;
        }
        $this->SendDataToParent(json_encode($packet));
    }

    private function Publish(string $topic, string $payload = '', int $qos = 0, bool $retain = false): void
    {
        // MQTT PUBLISH packet (PacketType 3)
        $packet = [
            'DataID'          => '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}',
            'PacketType'      => 3,
            'Topic'           => $topic,
            'Payload'         => $payload,
            'QualityOfService'=> $qos,
            'Retain'          => $retain
        ];

        $this->SendDebug('PublishMQTT', json_encode($packet, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);

        if (!$this->HasActiveParent()) {
            $this->SendDebug('MQTT', 'Skip publish - no active parent', 0);
            return;
        }

        $this->SendDataToParent(json_encode($packet));
    }

    // ---------- Receiving ----------

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data)) {
            $this->SendDebug(__FUNCTION__, 'Invalid JSONString received', 0);
            return '';
        }

        $topic = $data['Topic'] ?? '';
        $payload = $data['Payload'] ?? '';

        if (!is_string($topic) || $topic === '') {
            $this->SendDebug(__FUNCTION__, 'Missing or invalid Topic', 0);
            return '';
        }

        if (!is_string($payload)) {
            $payload = (string) $payload;
        }

        $this->SendDebug(__FUNCTION__, 'Topic: ' . $topic, 0);
        $this->SendDebug(__FUNCTION__, 'PayloadRaw: ' . $payload, 0);

        // Symcon MQTT payloads often arrive as hex-encoded strings.
        // Decode them when possible so JSON/text payloads can be processed normally.
        $decodedPayload = $payload;
        if ($decodedPayload !== '' && ctype_xdigit($decodedPayload) && (strlen($decodedPayload) % 2 === 0)) {
            $binPayload = hex2bin($decodedPayload);
            if ($binPayload !== false) {
                $decodedPayload = $binPayload;
                $this->SendDebug(__FUNCTION__, 'PayloadHexDecoded: ' . $decodedPayload, 0);
            }
        }

        $remoteId = trim($this->ReadPropertyString('RemoteID'));
        $deviceName = $this->ReadPropertyString('DeviceName');

        if ($remoteId === '' || $deviceName === '') {
            $this->SendDebug(__FUNCTION__, 'RemoteID or DeviceName missing', 0);
            return '';
        }

        $baseTopic = 'Haptique/' . $remoteId . '/device/' . $deviceName;
        $detailTopic = $baseTopic . '/detail';
        $commandsTopic = $baseTopic . '/commands';

        // Expected topics: /detail or /commands
        if ($topic === $detailTopic || $topic === $commandsTopic) {
            $arr = json_decode($decodedPayload, true);
            if (!is_array($arr)) {
                $this->SendDebug(__FUNCTION__, 'Commands payload is not a JSON array after decode', 0);
                return '';
            }

            // Persist + build variable profile
            $this->WriteAttributeString('Commands', json_encode($arr));
            $this->BuildCommandProfile($arr);

            // Build rows for the configuration-form list
            $rows = [];
            foreach ($arr as $cmd) {
                if (!is_array($cmd)) {
                    continue;
                }
                $rows[] = [
                    'CommandName' => (string) ($cmd['name'] ?? $cmd['Name'] ?? ''),
                    'CommandID'   => (string) ($cmd['id'] ?? $cmd['Id'] ?? '')
                ];
            }

            // Live update of the open instance configuration form
            $this->UpdateFormField('Commands', 'values', json_encode($rows));

            $this->SendDebug(__FUNCTION__, 'Commands received from ' . $topic . ' (' . count($arr) . ')', 0);
            return '';
        }

        return '';
    }

    // ---------- Command dropdown + mapping ----------

    private function ProfileName(): string
    {
        return 'Haptique.Command.' . $this->InstanceID;
    }

    private function BuildCommandProfile(array $commands): void
    {
        $profile = $this->ProfileName();

        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, VARIABLETYPE_INTEGER);
        }

        // Alte Associations löschen
        $vp = IPS_GetVariableProfile($profile);
        foreach ($vp['Associations'] as $a) {
            IPS_SetVariableProfileAssociation($profile, (int)$a['Value'], '', '', 0);
        }

        $indexToId = [];

        $i = 1;
        foreach ($commands as $cmd) {
            if (!is_array($cmd)) {
                continue;
            }
            $id   = (string)($cmd['id'] ?? $cmd['Id'] ?? '');
            $name = (string)($cmd['name'] ?? $cmd['Name'] ?? '');

            if ($id === '' || $name === '') {
                continue;
            }

            IPS_SetVariableProfileAssociation($profile, $i, $name, '', 0);
            $indexToId[(string)$i] = $id;
            $i++;
        }

        $this->WriteAttributeString('CommandIndexToId', json_encode($indexToId));

        // Variable an Profil binden
        IPS_SetVariableCustomProfile($this->GetIDForIdent('Command'), $profile);

        $this->SendDebug(__FUNCTION__, 'Commands=' . count($indexToId), 0);
    }

    private function RebuildCommandProfileFromAttribute(): void
    {
        $raw = $this->ReadAttributeString('Commands');
        $arr = json_decode($raw, true);
        if (is_array($arr)) {
            $this->BuildCommandProfile($arr);
        }
    }

    public function RequestAction($Ident, $Value): void
    {
        if ($Ident !== 'Command') {
            return;
        }

        $remoteId   = trim($this->ReadPropertyString('RemoteID'));
        $deviceName = $this->ReadPropertyString('DeviceName');

        if ($remoteId === '' || $deviceName === '') {
            $this->SendDebug(__FUNCTION__, 'RemoteID/DeviceName fehlt', 0);
            return;
        }

        $map = json_decode($this->ReadAttributeString('CommandIndexToId'), true);
        if (!is_array($map)) {
            $map = [];
        }

        $idx = (string)(int)$Value;
        $cmdId = (string)($map[$idx] ?? '');

        if ($cmdId === '') {
            $this->SendDebug(__FUNCTION__, 'Kein CommandID für Index ' . $idx, 0);
            return;
        }

        // Trigger command
        $topic = 'Haptique/' . $remoteId . '/device/' . $deviceName . '/trigger';

        // Doku sagt Payload: JSON {commandID} – in der Praxis i.d.R. string/roh OK.
        // Wenn nötig, kannst du hier json_encode($cmdId) senden.
        $this->Publish($topic, $cmdId, 0, false);

        SetValueString($this->GetIDForIdent('LastCommand'), $cmdId);
        SetValueInteger($this->GetIDForIdent('Command'), (int)$Value);

        $this->SendDebug(__FUNCTION__, "Triggered $deviceName => $cmdId", 0);
    }

    public function CreateScripts()
    {
        $categoryID = $this->ReadPropertyInteger('ScriptCategory');

        // Überprüfen, ob die Kategorie existiert
        if (!$categoryID) {
            echo $this->Translate('Category not found');
            return;
        }

        /*

        foreach ($methods as $methodName) {
            // Überprüfen, ob das Skript bereits existiert
            $scriptID = @IPS_GetObjectIDByIdent($methodName, $categoryID);
            if (!$scriptID) {
                // Skript erstellen
                $scriptID = IPS_CreateScript(0); // PHP-Skript
                IPS_SetParent($scriptID, $categoryID);
                IPS_SetName($scriptID, $methodName);
                IPS_SetIdent($scriptID, $methodName);

                // Skriptinhalt hinzufügen
                $scriptContent = "<?php\n\n// " . $methodName . " Befehl\nCRSX_" . $methodName . "(" . $this->InstanceID . ");";
                IPS_SetScriptContent($scriptID, $scriptContent);
                $this->SendDebug($this->Translate('Create Scripts'), 'Script ' . $methodName . ' created successfully', 0);
            }
        }
        $this->SendDebug($this->Translate('Create Scripts'), 'Scripts created successfully', 0);
        */
    }

    // ---------- UI ----------

    public function GetConfigurationForm(): string
    {
        $commands = json_decode($this->ReadAttributeString('Commands'), true);
        if (!is_array($commands)) {
            $commands = [];
        }

        $rows = [];
        foreach ($commands as $cmd) {
            if (!is_array($cmd)) {
                continue;
            }
            $rows[] = [
                'CommandName' => (string)($cmd['name'] ?? $cmd['Name'] ?? ''),
                'CommandID'   => (string)($cmd['id'] ?? $cmd['Id'] ?? '')
            ];
        }

        $form = [
            'elements' => [
                [
                    'type'    => 'RowLayout',
                    'items'   => [
                        [
                            'type'    => 'Image',
                            'name'    => 'CantataLogo',
                            'image'  => 'data:image/svg+xml;base64, PHN2ZyB3aWR0aD0iMTU4IiBoZWlnaHQ9IjU2IiB2aWV3Qm94PSIwIDAgMTU4IDU2IiBmaWxsPSJub25lIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPgo8cGF0aCBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGNsaXAtcnVsZT0iZXZlbm9kZCIgZD0iTTQ2LjU1NjUgNDguMjczQzQ0LjQ3NTkgNTAuMTcxNiA0Mi4xODcyIDUxLjcwNiAzOS43Njg0IDUyLjg1MDRDMzkuNTg2NCA1Mi45NTQ0IDM5LjM1MjMgNTIuODUwNCAzOS4yNzQzIDUyLjY0MjNMMzIuMDE4MSAzNS4yNDMxQzMxLjk2NjEgMzUuMTEzIDMyLjAxODEgMzQuOTU3IDMyLjE0ODEgMzQuOTA1QzMyLjQwODIgMzQuNzQ4OSAzMi42NjgzIDM0LjU2NjkgMzIuOTI4NCAzNC4zODQ4QzMzLjAzMjQgMzQuMzA2OCAzMy4xODg1IDM0LjMwNjggMzMuMjkyNSAzNC40MTA4TDQ2LjYwODUgNDcuNzI2OEM0Ni43MTI1IDQ3Ljg4MjkgNDYuNzEyNSA0OC4xNDMgNDYuNTU2NSA0OC4yNzNaTTMwLjI3NTYgMzUuOTk3M0MzMC4yMjM2IDM1Ljg2NzMgMzAuMDY3NSAzNS43ODkyIDI5LjkzNzUgMzUuODQxM0MyOS42MjU0IDM1LjkxOTMgMjkuMzEzMyAzNS45OTczIDI5LjAyNzIgMzYuMDQ5M0MyOC44OTcyIDM2LjA3NTMgMjguNzkzMSAzNi4xNzk0IDI4Ljc5MzEgMzYuMzM1NEwyOC44NDUyIDU1LjE5MTFDMjguODQ1MiA1NS4zOTkxIDI5LjAyNzIgNTUuNTgxMiAyOS4yMzUzIDU1LjU1NTJDMzEuOTE0MSA1NS40MjUxIDM0LjYxODkgNTQuODc5IDM3LjI3MTcgNTMuOTE2N0MzNy40Nzk4IDUzLjgzODcgMzcuNTU3OCA1My42MzA2IDM3LjQ3OTcgNTMuNDIyNUwzMC4yNzU2IDM1Ljk5NzNaTTI2LjcxMjUgMzYuMDQ5M0MyNi40MDA0IDM2LjAyMzMgMjYuMDg4MyAzNS45NDUzIDI1Ljc3NjIgMzUuODY3M0MyNS42NDYyIDM1Ljg0MTMgMjUuNDkwMSAzNS44OTMzIDI1LjQzODEgMzYuMDIzM0wxOC4yNiA1My40NzQ2QzE4LjE4MTkgNTMuNjgyNiAxOC4yODYgNTMuODkwNyAxOC40NjggNTMuOTY4N0MyMS4wMTY4IDU0Ljg3OSAyMy42OTU2IDU1LjM5OTEgMjYuNTMwNSA1NS41MjkyQzI2LjczODUgNTUuNTI5MiAyNi45MjA2IDU1LjM3MzEgMjYuOTIwNiA1NS4xNjUxVjM2LjMzNTRDMjYuOTQ2NiAzNi4yMDU0IDI2Ljg0MjYgMzYuMDc1MyAyNi43MTI1IDM2LjA0OTNaTTIzLjYxNzYgMzUuMDA5QzIzLjMzMTUgMzQuODUzIDIzLjA3MTQgMzQuNjcwOSAyMi44Mzc0IDM0LjQ4ODlDMjIuNzMzMyAzNC40MTA4IDIyLjU3NzMgMzQuNDEwOCAyMi40NzMyIDM0LjUxNDlMOS4xODMyNSA0Ny44ODI5QzkuMDI3MiA0OC4wMzg5IDkuMDI3MiA0OC4yNzMgOS4yMDkyNSA0OC40MjlDMTEuMjExOSA1MC4yMjM2IDEzLjUwMDUgNTEuNzU4IDE2LjA0OTMgNTIuOTU0NEMxNi4yMzE0IDUzLjAzMjQgMTYuNDY1NCA1Mi45NTQ0IDE2LjU0MzUgNTIuNzQ2M0wyMy43NzM2IDM1LjM0NzFDMjMuNzk5NiAzNS4yNDMxIDIzLjc0NzYgMzUuMDg3IDIzLjYxNzYgMzUuMDA5Wk0yMS4xNzI5IDMyLjg3NjRDMjAuOTY0OCAzMi42MTYzIDIwLjgwODcgMzIuMzU2MiAyMC42NTI3IDMyLjA5NjFDMjAuNTc0NyAzMS45NjYxIDIwLjQ0NDYgMzEuOTQwMSAyMC4zMTQ2IDMxLjk2NjFMMi45MTUzNiAzOS4yMjIzQzIuNzA3MyAzOS4zMDAzIDIuNjI5MjggMzkuNTM0NCAyLjcwNzMgMzkuNzE2NEMzLjg1MTY0IDQyLjEzNTIgNS4zODYxIDQ0LjQ0OTggNy4yODQ2NyA0Ni41MDQ1QzcuNDQwNzIgNDYuNjYwNSA3LjY3NDc5IDQ2LjY2MDUgNy44MzA4NCA0Ni41MDQ1TDIxLjE3MjkgMzMuMTg4NUMyMS4yNTA5IDMzLjEzNjQgMjEuMjUwOSAzMi45ODA0IDIxLjE3MjkgMzIuODc2NFpNMTkuNzE2NCAyOS45NjM1QzE5LjYzODQgMjkuNjUxNCAxOS41NjA0IDI5LjMzOTMgMTkuNTA4NCAyOS4wNTMyQzE5LjQ4MjMgMjguOTIzMiAxOS4zNzgzIDI4LjgxOTEgMTkuMjIyMyAyOC44MTkxTDAuMzY2NTk2IDI4Ljg3MTJDMC4xNTg1MzQgMjguODcxMiAtMC4wMjM1MjMyIDI5LjA1MzIgMC4wMDI0ODQ1OCAyOS4yNjEzQzAuMTMyNTI0IDMxLjk0MDEgMC42Nzg2ODkgMzQuNjQ0OSAxLjY0MDk4IDM3LjI5NzdDMS43MTkgMzcuNTA1OCAxLjkyNzA2IDM3LjU4MzggMi4xMzUxMiAzNy41MDU4TDE5LjUzNDQgMzAuMzAxNkMxOS42OTA0IDMwLjI0OTYgMTkuNzQyNCAzMC4wOTM1IDE5LjcxNjQgMjkuOTYzNVpNMC4zOTI2MDIgMjYuOTQ2NkgxOS4yMjIzQzE5LjM1MjMgMjYuOTQ2NiAxOS40ODIzIDI2Ljg0MjYgMTkuNTA4NCAyNi43MTI1QzE5LjUzNDQgMjYuNDAwNCAxOS42MTI0IDI2LjA4ODMgMTkuNjkwNCAyNS43NzYyQzE5LjcxNjQgMjUuNjQ2MiAxOS42NjQ0IDI1LjQ5MDEgMTkuNTM0NCAyNS40MzgxTDIuMDgzMTEgMTguMjZDMS44NzUwNSAxOC4xODE5IDEuNjY2OTkgMTguMjg2IDEuNTg4OTYgMTguNDY4QzAuNjc4NjkgMjEuMDE2OCAwLjE1ODUzMyAyMy42OTU2IDAuMDI4NDk0IDI2LjUzMDVDMC4wMDI0ODYxNyAyNi43NjQ1IDAuMTg0NTM5IDI2Ljk0NjYgMC4zOTI2MDIgMjYuOTQ2NlpNMi43ODUzMiAxNi41MTc0TDIwLjE4NDUgMjMuNzQ3NkMyMC4zMTQ2IDIzLjc5OTYgMjAuNDcwNiAyMy43NDc2IDIwLjU0ODcgMjMuNjE3NkMyMC43MDQ3IDIzLjMzMTUgMjAuODg2OCAyMy4wNzE0IDIxLjA2ODggMjIuODM3NEMyMS4xNDY4IDIyLjczMzMgMjEuMTQ2OCAyMi41NzczIDIxLjA0MjggMjIuNDczMkw3LjY3NDc5IDkuMTgzMjVDNy41MTg3NCA5LjAyNzIgNy4yODQ2OCA5LjAyNzIgNy4xMjg2MyA5LjIwOTI1QzUuMzM0MDkgMTEuMjExOSAzLjc5OTYzIDEzLjUwMDUgMi42MDMyNyAxNi4wNDkzQzIuNDk5MjQgMTYuMjA1NCAyLjYwMzI3IDE2LjQzOTQgMi43ODUzMiAxNi41MTc0Wk0yMi4zMTcyIDIxLjE0NjhDMjIuNDIxMiAyMS4yNTA5IDIyLjU3NzMgMjEuMjUwOSAyMi42ODEzIDIxLjE3MjlDMjIuOTQxNCAyMC45NjQ4IDIzLjIwMTUgMjAuODA4NyAyMy40NjE1IDIwLjYyNjdDMjMuNTkxNiAyMC41NDg3IDIzLjYxNzYgMjAuNDE4NiAyMy41OTE2IDIwLjI4ODZMMTYuMzM1NCAyLjg4OTM1QzE2LjI1NzQgMi42ODEyOSAxNi4wMjMzIDIuNjAzMjcgMTUuODQxMiAyLjY4MTI5QzEzLjQyMjUgMy44MjU2MyAxMS4xMDc4IDUuMzYwMSA5LjA1MzIxIDcuMjU4NjdDOC44OTcxNiA3LjQxNDcyIDguODk3MTYgNy42NDg3OCA5LjA1MzIxIDcuODA0ODNMMjIuMzE3MiAyMS4xNDY4Wk0yNS4yNTYxIDE5LjU2MDRDMjUuMzA4MSAxOS42OTA0IDI1LjQ2NDEgMTkuNzY4NCAyNS41OTQyIDE5LjcxNjRDMjUuOTA2MyAxOS42Mzg0IDI2LjIxODQgMTkuNTYwNCAyNi41MDQ1IDE5LjUwODNDMjYuNjM0NSAxOS40ODIzIDI2LjczODUgMTkuMzc4MyAyNi43Mzg1IDE5LjIyMjNMMjYuNjg2NSAwLjM2NjU5NkMyNi42ODY1IDAuMTU4NTM0IDI2LjUwNDQgLTAuMDIzNTIzMiAyNi4yOTY0IDAuMDAyNDg0NThDMjMuNjE3NiAwLjEzMjUyNCAyMC45MTI4IDAuNjc4Njg5IDE4LjI2IDEuNjQwOThDMTguMDUxOSAxLjcxOSAxNy45NzM5IDEuOTI3MDYgMTguMDUxOSAyLjEzNTEyTDI1LjI1NjEgMTkuNTYwNFpNMjkuNzgxNCAxOS42NjQ0QzI5LjkxMTUgMTkuNjkwNCAzMC4wNjc1IDE5LjYzODQgMzAuMTE5NSAxOS41MDgzTDM3LjI5NzcgMi4wNTcxQzM3LjM3NTcgMS44NDkwNCAzNy4yNzE3IDEuNjQwOTggMzcuMDg5NiAxLjU2Mjk1QzM0LjU0MDkgMC42NTI2OCAzMS44NjIxIDAuMTMyNTI0IDI5LjAyNzIgMC4wMDI0ODQ1OEMyOC44MTkxIDAuMDAyNDg0NTggMjguNjM3MSAwLjE1ODUzNCAyOC42MzcxIDAuMzY2NTk2VjE5LjE5NjNDMjguNjM3MSAxOS4zNTIzIDI4Ljc0MTEgMTkuNDU2MyAyOC44OTcyIDE5LjQ4MjNDMjkuMTU3MiAxOS41MzQ0IDI5LjQ2OTMgMTkuNjEyNCAyOS43ODE0IDE5LjY2NDRaTTMxLjk0MDEgMjAuNTQ4N0MzMi4yMjYyIDIwLjcwNDcgMzIuNDg2MyAyMC44ODY4IDMyLjcyMDMgMjEuMDY4OEMzMi44MjQ0IDIxLjE0NjggMzIuOTgwNCAyMS4xNDY4IDMzLjA4NDQgMjEuMDQyOEw0Ni4zNzQ0IDcuNjc0NzlDNDYuNTMwNSA3LjUxODc0IDQ2LjUzMDUgNy4yODQ2OCA0Ni4zNzQ0IDcuMTI4NjNDNDQuMzcxOCA1LjMzNDA5IDQyLjA4MzEgMy43OTk2MyAzOS41MzQ0IDIuNjAzMjdDMzkuMzUyMyAyLjUyNTI0IDM5LjExODIgMi42MDMyNyAzOS4wNDAyIDIuODExMzNMMzEuODEgMjAuMjEwNkMzMS43NTggMjAuMzE0NiAzMS44MSAyMC40NzA2IDMxLjk0MDEgMjAuNTQ4N1oiIGZpbGw9InVybCgjcGFpbnQwX2xpbmVhcl8xMDFfNjUpIi8+CjxwYXRoIGQ9Ik00NC4zOTc4IDU1LjU1NTJDNDQuMzQ1OCA1NS41MDMyIDQ0LjI2NzggNTUuNTAzMiA0NC4yMTU4IDU1LjQ3NzJDNDMuNjE3NiA1NS4yNjkxIDQzLjIwMTUgNTQuOTA1IDQyLjk5MzQgNTQuMzA2OEM0Mi41NzczIDUzLjE4ODUgNDMuMjc5NSA1MS45NjYxIDQ0LjQyMzggNTEuNzU4QzQ1LjQxMjEgNTEuNTc2IDQ2LjI3MDQgNTIuMTQ4MiA0Ni41ODI1IDUzLjAwNjRDNDYuNjA4NSA1My4xMTA1IDQ2LjYzNDUgNTMuMjE0NSA0Ni42NjA1IDUzLjMxODVDNDYuNjYwNSA1My4zNzA1IDQ2LjY4NjUgNTMuNDIyNSA0Ni42ODY1IDUzLjQ3NDZDNDYuNjg2NSA1My42MDQ2IDQ2LjY4NjUgNTMuNzM0NiA0Ni42ODY1IDUzLjg2NDdDNDYuNjg2NSA1My45MTY3IDQ2LjY2MDUgNTMuOTY4NyA0Ni42NjA1IDUzLjk5NDdDNDYuNjYwNSA1NC4wOTg3IDQ2LjYzNDUgNTQuMjAyOCA0Ni41ODI1IDU0LjMwNjhDNDYuMzQ4NCA1NC45MzEgNDUuOTA2MyA1NS4zMjExIDQ1LjI1NjEgNTUuNTAzMkM0NS4yMDQxIDU1LjUwMzIgNDUuMTc4MSA1NS41MjkyIDQ1LjEyNjEgNTUuNTU1MkM0NC44NjYgNTUuNTU1MiA0NC42MzE5IDU1LjU1NTIgNDQuMzk3OCA1NS41NTUyWk00Ni4zNzQ0IDUzLjY1NjZDNDYuNDAwNCA1Mi44NTA0IDQ1Ljc1MDIgNTIuMDQ0MSA0NC43ODc5IDUyLjAxODFDNDMuOTAzNyA1Mi4wMTgxIDQzLjE3NTUgNTIuNzIwMyA0My4xNzU1IDUzLjYzMDZDNDMuMTc1NSA1NC42MTg5IDQzLjk4MTcgNTUuMjQzMSA0NC43NjE5IDU1LjI0MzFDNDUuNjQ2MiA1NS4yNjkxIDQ2LjM3NDQgNTQuNTQwOSA0Ni4zNzQ0IDUzLjY1NjZaIiBmaWxsPSIjRkJCMDNDIi8+CjxwYXRoIGQ9Ik00NC4xNjM4IDUyLjc5ODRDNDQuMzQ1OCA1Mi43NzI0IDQ0LjkxOCA1Mi43NzI0IDQ1LjA0OCA1Mi43OTg0QzQ1LjEgNTIuNzk4NCA0NS4xMjYxIDUyLjgyNDQgNDUuMTc4MSA1Mi44NTA0QzQ1LjM2MDEgNTIuOTI4NCA0NS40NjQyIDUzLjA4NDQgNDUuNDY0MiA1My4yNjY1QzQ1LjQ5MDIgNTMuNDc0NiA0NS40MTIxIDUzLjYzMDYgNDUuMjMwMSA1My43NjA2QzQ1LjIwNDEgNTMuNzg2NyA0NS4xNTIxIDUzLjgxMjcgNDUuMSA1My44Mzg3QzQ1LjEyNjEgNTMuODY0NyA0NS4xMjYxIDUzLjkxNjcgNDUuMTUyMSA1My45NDI3QzQ1LjIzMDEgNTQuMDcyNyA0NS4zMDgxIDU0LjIyODggNDUuNDEyMSA1NC4zNTg4QzQ1LjQzODEgNTQuMzg0OCA0NS40MzgxIDU0LjQzNjkgNDUuNDY0MiA1NC40ODg5QzQ1LjMwODEgNTQuNTE0OSA0NS4xNzgxIDU0LjUxNDkgNDUuMDIyIDU0LjQ4ODlDNDQuOTQ0IDU0LjM1ODggNDQuODkyIDU0LjI1NDggNDQuODE0IDU0LjEyNDhDNDQuNzg4IDU0LjA0NjcgNDQuNzM1OSA1My45OTQ3IDQ0LjY4MzkgNTMuOTE2N0M0NC42NTc5IDUzLjg5MDcgNDQuNjMxOSA1My44NjQ3IDQ0LjYwNTkgNTMuODY0N0M0NC41NTM5IDUzLjg2NDcgNDQuNTUzOSA1My45MTY3IDQ0LjU1MzkgNTMuOTQyN0M0NC41NTM5IDU0LjA3MjcgNDQuNTUzOSA1NC4yMjg4IDQ0LjU1MzkgNTQuMzU4OEM0NC41NTM5IDU0LjM4NDggNDQuNTUzOSA1NC40MzY5IDQ0LjU1MzkgNTQuNDg4OUM0NC40MjM4IDU0LjUxNDkgNDQuMjkzOCA1NC41MTQ5IDQ0LjE2MzggNTQuNDg4OUM0NC4xMzc4IDU0LjMzMjggNDQuMTM3OCA1My4zMTg1IDQ0LjEzNzggNTIuOTAyNEM0NC4xMzc4IDUyLjg3NjQgNDQuMTM3OCA1Mi44NTA0IDQ0LjE2MzggNTIuNzk4NFpNNDQuNTUzOSA1My41MjY2QzQ0LjY4MzkgNTMuNTc4NiA0NC44MTQgNTMuNTc4NiA0NC45NDQgNTMuNTI2NkM0NS4wMjIgNTMuNTAwNiA0NS4wNDggNTMuNDIyNSA0NS4wNDggNTMuMzQ0NUM0NS4wNDggNTMuMjY2NSA0NS4wMjIgNTMuMTg4NSA0NC45NDQgNTMuMTYyNUM0NC44MTQgNTMuMTM2NSA0NC43MDk5IDUzLjExMDUgNDQuNTc5OSA1My4xNjI1QzQ0LjU1MzkgNTMuMjkyNSA0NC41NTM5IDUzLjM5NjUgNDQuNTUzOSA1My41MjY2WiIgZmlsbD0iI0ZCQjAzQyIvPgo8cGF0aCBkPSJNNTAuOTc3OCAzMS4yMzc5TDQ3LjQxNDcgMjcuNjc0OFYxNi45NTk2SDYyLjU3NzNMNjAuNzgyOCAyMC41MjI3SDUwLjk1MThWMjUuODgwM0w1Mi43NDY0IDI3LjY3NDhINjEuNjY3VjMxLjIzNzlINTAuOTc3OFpNNzQuMTc2OCAyMC41MjI3VjI3LjMxMDdMNjguODE5MiAyNC4wODU3VjI4LjA5MDlMNzcuNzM5OSAzMy40NDg1VjE2LjkzMzZINjMuNDYxNlYzMS4yMTE5SDY3LjAyNDZWMjAuNDk2Nkw3NC4xNzY4IDIwLjUyMjdaTTc5LjUzNDQgMTQuMjgwOFYzMS4yMzc5SDgzLjA5NzVWMjMuMjAxNUw5My44MTI3IDMzLjkxNjdWMTYuOTU5Nkg5MC4yNDk2VjI0Ljk5Nkw3OS41MzQ0IDE0LjI4MDhaTTk1LjU4MTIgMTYuOTU5NlYyMC41MjI3SDEwMC45MzlWMzIuMTIyMUwxMDQuNTAyIDMwLjMyNzZWMjAuNTIyN0gxMDguOTc1TDExMC43NDQgMTYuOTU5Nkg5NS41ODEyWk0xMjIuMzQzIDIwLjUyMjdWMjcuMzEwN0wxMTYuOTg2IDI0LjA4NTdWMjguMDkwOUwxMjUuOTA2IDMzLjQ0ODVWMTYuOTMzNkgxMTEuNjU0VjMxLjIxMTlIMTE1LjIxN1YyMC40OTY2TDEyMi4zNDMgMjAuNTIyN1pNMTI3LjcwMSAxNi45NTk2VjIwLjUyMjdIMTMzLjA1OVYzMi4xMjIxTDEzNi42MjIgMzAuMzI3NlYyMC41MjI3SDE0MS4wNjlMMTQyLjgzNyAxNi45NTk2SDEyNy43MDFaTTE1NC40MzcgMjAuNTIyN1YyNy4zMTA3TDE0OS4wNzkgMjQuMDg1N1YyOC4wOTA5TDE1OCAzMy40NDg1VjE2LjkzMzZIMTQzLjcyMlYzMS4yMTE5SDE0Ny4yODVWMjAuNDk2NkwxNTQuNDM3IDIwLjUyMjdaIiBmaWxsPSJ3aGl0ZSIvPgo8cGF0aCBkPSJNNTAuNjY1NyAzOS41MzQ0SDUxLjM0MTlWMzkuNTYwNEM1MS4zNDE5IDQwLjA1NDUgNTEuMTg1OSA0MC40NzA3IDUwLjgyMTggNDAuODA4OEM1MC40NTc3IDQxLjE0NjkgNDkuOTg5NSA0MS4zMDI5IDQ5LjM5MTMgNDEuMzAyOUM0OC43OTMyIDQxLjMwMjkgNDguMzI1IDQxLjA5NDggNDcuOTYwOSA0MC42Nzg3QzQ3LjU5NjggNDAuMjYyNiA0Ny4zODg3IDM5LjcxNjQgNDcuMzg4NyAzOS4wNjYyVjM4LjEyOTlDNDcuMzg4NyAzNy40Nzk4IDQ3LjU3MDggMzYuOTMzNiA0Ny45NjA5IDM2LjUxNzVDNDguMzI1IDM2LjEwMTMgNDguODE5MiAzNS44OTMzIDQ5LjQxNzMgMzUuODkzM0M1MC4wMTU1IDM1Ljg5MzMgNTAuNDgzNyAzNi4wNDkzIDUwLjg0NzggMzYuMzYxNEM1MS4yMTE5IDM2LjY3MzUgNTEuMzY3OSAzNy4wODk2IDUxLjM2NzkgMzcuNjA5OFYzNy42MzU4SDUwLjY2NTdDNTAuNjY1NyAzNy4yNzE3IDUwLjU2MTcgMzYuOTU5NiA1MC4zMjc2IDM2Ljc1MTVDNTAuMTE5NiAzNi41NDM1IDQ5LjgwNzUgMzYuNDM5NCA0OS40MTczIDM2LjQzOTRDNDkuMDI3MiAzNi40Mzk0IDQ4LjcxNTEgMzYuNTk1NSA0OC40ODExIDM2LjkwNzZDNDguMjQ3IDM3LjIxOTcgNDguMTE3IDM3LjYwOTggNDguMTE3IDM4LjEwMzlWMzkuMDQwMkM0OC4xMTcgMzkuNTM0NCA0OC4yNDcgMzkuOTI0NSA0OC40ODExIDQwLjIzNjZDNDguNzE1MSA0MC41NDg3IDQ5LjAyNzIgNDAuNzA0NyA0OS40MTczIDQwLjcwNDdDNDkuODA3NSA0MC43MDQ3IDUwLjExOTYgNDAuNjAwNyA1MC4zMjc2IDQwLjM5MjZDNTAuNTYxNyA0MC4yMTA2IDUwLjY2NTcgMzkuODk4NSA1MC42NjU3IDM5LjUzNDRaIiBmaWxsPSJ3aGl0ZSIvPgo8cGF0aCBkPSJNNTIuOTAyNCAzOS4yMjIzVjM5LjMwMDNDNTIuOTAyNCAzOS43NDI0IDUyLjk4MDQgNDAuMDgwNSA1My4xNjI1IDQwLjM0MDZDNTMuMzQ0NSA0MC42MDA3IDUzLjYwNDYgNDAuNzMwNyA1My45NDI3IDQwLjczMDdDNTQuMjgwOCA0MC43MzA3IDU0LjU0MDkgNDAuNjAwNyA1NC43MjMgNDAuMzQwNkM1NC45MDUgNDAuMDgwNSA1NC45ODMgMzkuNzQyNCA1NC45ODMgMzkuMzAwM1YzOS4yMjIzQzU0Ljk4MyAzOC44MDYyIDU0LjkwNSAzOC40NDIgNTQuNzIzIDM4LjE4MkM1NC41NDA5IDM3LjkyMTkgNTQuMjgwOCAzNy43OTE4IDUzLjk0MjcgMzcuNzkxOEM1My42MDQ2IDM3Ljc5MTggNTMuMzQ0NSAzNy45MjE5IDUzLjE4ODUgMzguMTgyQzUyLjk4MDQgMzguNDQyIDUyLjkwMjQgMzguODA2MiA1Mi45MDI0IDM5LjIyMjNaTTUyLjE3NDIgMzkuMzAwM1YzOS4yMjIzQzUyLjE3NDIgMzguNjI0MSA1Mi4zMzAyIDM4LjE1NiA1Mi42NDIzIDM3Ljc5MThDNTIuOTU0NCAzNy40Mjc3IDUzLjM3MDUgMzcuMjQ1NyA1My45MTY3IDM3LjI0NTdDNTQuNDYyOSAzNy4yNDU3IDU0Ljg3OSAzNy40Mjc3IDU1LjE5MTEgMzcuNzkxOEM1NS41MDMyIDM4LjE1NiA1NS42NTkyIDM4LjYyNDEgNTUuNjU5MiAzOS4yMjIzVjM5LjMwMDNDNTUuNjU5MiAzOS44OTg1IDU1LjUwMzIgNDAuMzY2NiA1NS4xOTExIDQwLjczMDdDNTQuODc5IDQxLjA5NDggNTQuNDYyOSA0MS4yNzY5IDUzLjkxNjcgNDEuMjc2OUM1My4zNzA1IDQxLjI3NjkgNTIuOTU0NCA0MS4wOTQ4IDUyLjY0MjMgNDAuNzMwN0M1Mi4zNTYyIDQwLjM2NjYgNTIuMTc0MiAzOS44OTg1IDUyLjE3NDIgMzkuMzAwM1oiIGZpbGw9IndoaXRlIi8+CjxwYXRoIGQ9Ik01Ny40Mjc4IDQxLjE5ODlINTYuNzI1NVYzNy4yOTc3SDU3LjM0OTdMNTcuNDAxNyAzNy44MTc5QzU3LjY2MTggMzcuNDI3NyA1OC4wNTE5IDM3LjIxOTcgNTguNTk4MSAzNy4yMTk3QzU5LjExODMgMzcuMjE5NyA1OS40ODI0IDM3LjQ1MzcgNTkuNjY0NCAzNy45NDc5QzU5LjkyNDUgMzcuNDc5OCA2MC4zNDA2IDM3LjIxOTcgNjAuODYwOCAzNy4yMTk3QzYxLjI3NjkgMzcuMjE5NyA2MS41ODkgMzcuMzQ5NyA2MS44MjMxIDM3LjYzNThDNjIuMDU3MSAzNy45MjE5IDYyLjE2MTIgMzguMzEyIDYyLjE2MTIgMzguODU4MlY0MS4xOTg5SDYxLjQ1OVYzOC44NTgyQzYxLjQ1OSAzOC40OTQxIDYxLjQwNjkgMzguMjA4IDYxLjI3NjkgMzguMDUxOUM2MS4xNDY5IDM3Ljg5NTkgNjAuOTY0OCAzNy43OTE4IDYwLjcwNDcgMzcuNzkxOEM2MC40NDQ3IDM3Ljc5MTggNjAuMjM2NiAzNy44Njk5IDYwLjEwNjYgMzguMDUxOUM1OS45NTA1IDM4LjIwOCA1OS44NzI1IDM4LjQxNiA1OS44NDY1IDM4LjcwMjFWNDEuMTk4OUg1OS4xMTgzVjM4Ljg1ODJDNTkuMTE4MyAzOC40OTQxIDU5LjA0MDIgMzguMjM0IDU4LjkxMDIgMzguMDUxOUM1OC43ODAyIDM3Ljg2OTkgNTguNTcyMSAzNy43OTE4IDU4LjMxMiAzNy43OTE4QzU3Ljg5NTkgMzcuNzkxOCA1Ny42MDk4IDM3Ljk3MzkgNTcuNDUzOCAzOC4zMTJWNDEuMTk4OUg1Ny40Mjc4WiIgZmlsbD0id2hpdGUiLz4KPHBhdGggZD0iTTY0LjA1OTggNDEuMTk4OUg2My4zNTc1VjM3LjI5NzdINjMuOTgxN0w2NC4wMzM3IDM3LjgxNzlDNjQuMjkzOCAzNy40Mjc3IDY0LjY4MzkgMzcuMjE5NyA2NS4yMzAxIDM3LjIxOTdDNjUuNzUwMyAzNy4yMTk3IDY2LjExNDQgMzcuNDUzNyA2Ni4yOTY0IDM3Ljk0NzlDNjYuNTU2NSAzNy40Nzk4IDY2Ljk3MjYgMzcuMjE5NyA2Ny40OTI4IDM3LjIxOTdDNjcuOTA4OSAzNy4yMTk3IDY4LjIyMSAzNy4zNDk3IDY4LjQ1NTEgMzcuNjM1OEM2OC42ODkxIDM3LjkyMTkgNjguNzkzMiAzOC4zMTIgNjguNzkzMiAzOC44NTgyVjQxLjE5ODlINjguMDkxVjM4Ljg1ODJDNjguMDkxIDM4LjQ5NDEgNjguMDM5IDM4LjIwOCA2Ny45MDg5IDM4LjA1MTlDNjcuNzc4OSAzNy44OTU5IDY3LjU5NjggMzcuNzkxOCA2Ny4zMzY3IDM3Ljc5MThDNjcuMDc2NyAzNy43OTE4IDY2Ljg2ODYgMzcuODY5OSA2Ni43Mzg2IDM4LjA1MTlDNjYuNTgyNSAzOC4yMDggNjYuNTA0NSAzOC40MTYgNjYuNDc4NSAzOC43MDIxVjQxLjE5ODlINjUuNzUwM1YzOC44NTgyQzY1Ljc1MDMgMzguNDk0MSA2NS42NzIyIDM4LjIzNCA2NS41NDIyIDM4LjA1MTlDNjUuNDEyMiAzNy44Njk5IDY1LjIwNDEgMzcuNzkxOCA2NC45NDQgMzcuNzkxOEM2NC41Mjc5IDM3Ljc5MTggNjQuMjQxOCAzNy45NzM5IDY0LjA4NTggMzguMzEyVjQxLjE5ODlINjQuMDU5OFoiIGZpbGw9IndoaXRlIi8+CjxwYXRoIGQ9Ik03MS4zMTU5IDQxLjI3NjlDNzAuODczOCA0MS4yNzY5IDcwLjUzNTcgNDEuMTQ2OSA3MC4zMDE2IDQwLjg2MDhDNzAuMDY3NiA0MC41NzQ3IDY5Ljk2MzUgNDAuMTU4NiA2OS45NjM1IDM5LjU4NjRWMzcuMjk3N0g3MC42NjU3VjM5LjYxMjRDNzAuNjY1NyA0MC4wMjg1IDcwLjcxNzggNDAuMzE0NiA3MC44NDc4IDQwLjQ3MDdDNzAuOTc3OCA0MC42MjY3IDcxLjE1OTkgNDAuNzA0NyA3MS40MiA0MC43MDQ3QzcxLjkxNDEgNDAuNzA0NyA3Mi4yNTIyIDQwLjQ5NjcgNzIuNDM0MyA0MC4xMDY1VjM3LjI5NzdINzMuMTM2NVY0MS4xOTg5SDcyLjUxMjNMNzIuNDYwMyA0MC42MjY3QzcyLjIwMDIgNDEuMDY4OCA3MS44MTAxIDQxLjI3NjkgNzEuMzE1OSA0MS4yNzY5WiIgZmlsbD0id2hpdGUiLz4KPHBhdGggZD0iTTc0LjMzMjggMzcuMjk3N0g3NC45NTdMNzUuMDA5IDM3Ljg2OTlDNzUuMjY5MSAzNy40Mjc3IDc1LjY1OTIgMzcuMjE5NyA3Ni4xNzk0IDM3LjIxOTdDNzYuNTk1NSAzNy4yMTk3IDc2LjkzMzYgMzcuMzQ5NyA3Ny4xNjc3IDM3LjU4MzhDNzcuNDAxOCAzNy44NDM5IDc3LjUwNTggMzguMjA4IDc3LjUwNTggMzguNzI4MVY0MS4xOTg5SDc2LjgwMzZWMzguNzU0MUM3Ni44MDM2IDM4LjQxNiA3Ni43MjU2IDM4LjE4MiA3Ni41OTU1IDM4LjAyNTlDNzYuNDY1NSAzNy44Njk5IDc2LjI1NzQgMzcuODE3OSA3NS45NzEzIDM3LjgxNzlDNzUuNzYzMyAzNy44MTc5IDc1LjU4MTIgMzcuODY5OSA3NS40MjUyIDM3Ljk3MzlDNzUuMjY5MSAzOC4wNzc5IDc1LjEzOTEgMzguMjA4IDc1LjAzNTEgMzguMzlWNDEuMjI0OUg3NC4zMzI4VjM3LjI5NzdaIiBmaWxsPSJ3aGl0ZSIvPgo8cGF0aCBkPSJNNzkuNDgyNCAzNy4yOTc3VjQxLjE5ODlINzguNzU0MlYzNy4yOTc3SDc5LjQ4MjRaTTc5LjQ4MjQgMzUuNTgxMlYzNi4zMDk0SDc4Ljc1NDJWMzUuNTgxMkg3OS40ODI0WiIgZmlsbD0id2hpdGUiLz4KPHBhdGggZD0iTTgxLjI3NjkgMzkuMzI2M0M4MS4yNzY5IDM5Ljc0MjQgODEuMzU0OSA0MC4wODA1IDgxLjUxMSA0MC4zMTQ2QzgxLjY2NyA0MC41NzQ3IDgxLjk1MzEgNDAuNzA0NyA4Mi4zMTcyIDQwLjcwNDdDODIuNTUxMyA0MC43MDQ3IDgyLjc1OTQgNDAuNjI2NyA4Mi45NDE0IDQwLjQ5NjdDODMuMTIzNSA0MC4zNDA2IDgzLjIwMTUgNDAuMTg0NiA4My4yMDE1IDM5Ljk1MDVIODMuODUxN1YzOS45NzY1QzgzLjg3NzcgNDAuMzE0NiA4My43MjE3IDQwLjYyNjcgODMuNDA5NiA0MC44ODY4QzgzLjA5NzUgNDEuMTQ2OSA4Mi43MzM0IDQxLjI3NjkgODIuMzE3MiA0MS4yNzY5QzgxLjc3MTEgNDEuMjc2OSA4MS4zMjg5IDQxLjA5NDggODEuMDE2OSA0MC43MzA3QzgwLjcwNDggNDAuMzY2NiA4MC41NDg3IDM5Ljg5ODUgODAuNTQ4NyAzOS4zMjYzVjM5LjE3MDNDODAuNTQ4NyAzOC41OTgxIDgwLjcwNDggMzguMTI5OSA4MS4wMTY5IDM3Ljc2NThDODEuMzI4OSAzNy40MDE3IDgxLjc3MTEgMzcuMjE5NyA4Mi4zMTcyIDM3LjIxOTdDODIuNzU5NCAzNy4yMTk3IDgzLjE0OTUgMzcuMzQ5NyA4My40MzU2IDM3LjYwOThDODMuNzIxNyAzNy44Njk5IDgzLjg3NzcgMzguMjA4IDgzLjg1MTcgMzguNTk4MVYzOC42MjQxSDgzLjIwMTVDODMuMjAxNSAzOC4zOSA4My4xMjM1IDM4LjE4MiA4Mi45NDE0IDM4LjAyNTlDODIuNzg1NCAzNy44Njk5IDgyLjU1MTMgMzcuNzY1OCA4Mi4zMTcyIDM3Ljc2NThDODIuMDU3MiAzNy43NjU4IDgxLjg3NTEgMzcuODE3OSA4MS42OTMgMzcuOTQ3OUM4MS41MzcgMzguMDc3OSA4MS40MzMgMzguMjM0IDgxLjM1NDkgMzguNDQyQzgxLjMwMjkgMzguNjUwMSA4MS4yNTA5IDM4Ljg4NDIgODEuMjUwOSAzOS4xNDQzVjM5LjMyNjNIODEuMjc2OVoiIGZpbGw9IndoaXRlIi8+CjxwYXRoIGQ9Ik04NS45MzIzIDQxLjI3NjlDODUuNTE2MiA0MS4yNzY5IDg1LjIwNDEgNDEuMTcyOSA4NC45OTYgNDAuOTY0OEM4NC43ODggNDAuNzU2NyA4NC42ODM5IDQwLjQ3MDcgODQuNjgzOSA0MC4xMDY1Qzg0LjY4MzkgMzkuNzQyNCA4NC44NCAzOS40NTYzIDg1LjEyNjEgMzkuMjIyM0M4NS40MzgyIDM5LjAxNDIgODUuODI4MyAzOC45MTAyIDg2LjM0ODUgMzguOTEwMkg4Ny4xMjg3VjM4LjUyMDFDODcuMTI4NyAzOC4yODYgODcuMDUwNyAzOC4xMDM5IDg2LjkyMDYgMzcuOTczOUM4Ni43OTA2IDM3Ljg0MzkgODYuNTgyNSAzNy43NjU4IDg2LjM0ODUgMzcuNzY1OEM4Ni4xMTQ0IDM3Ljc2NTggODUuOTMyMyAzNy44MTc5IDg1Ljc3NjMgMzcuOTQ3OUM4NS42MjAyIDM4LjA1MTkgODUuNTY4MiAzOC4yMDggODUuNTY4MiAzOC4zNjRIODQuODkyVjM4LjMzOEM4NC44NjYgMzguMDUxOSA4NS4wMjIxIDM3Ljc5MTggODUuMzA4MSAzNy41NTc4Qzg1LjU5NDIgMzcuMzIzNyA4NS45NTgzIDM3LjIxOTcgODYuNDAwNSAzNy4yMTk3Qzg2Ljg0MjYgMzcuMjE5NyA4Ny4yMDY3IDM3LjMyMzcgODcuNDY2OCAzNy41NTc4Qzg3LjcyNjkgMzcuNzkxOCA4Ny44NTY5IDM4LjEwMzkgODcuODU2OSAzOC41MjAxVjQwLjM5MjZDODcuODU2OSA0MC43MDQ3IDg3Ljg4MjkgNDAuOTY0OCA4Ny45NjA5IDQxLjE3MjlIODcuMjMyN0M4Ny4xODA3IDQwLjkxMjggODcuMTU0NyA0MC43MzA3IDg3LjE1NDcgNDAuNTc0N0M4Ny4wMjQ2IDQwLjc4MjggODYuODQyNiA0MC45Mzg4IDg2LjYzNDUgNDEuMDQyOEM4Ni40MDA1IDQxLjIyNDkgODYuMTY2NCA0MS4yNzY5IDg1LjkzMjMgNDEuMjc2OVpNODUuMzg2MiA0MC4xMzI2Qzg1LjM4NjIgNDAuMzE0NiA4NS40MzgyIDQwLjQ0NDYgODUuNTQyMiA0MC41NDg3Qzg1LjY0NjIgNDAuNjUyNyA4NS44MjgzIDQwLjcwNDcgODYuMDYyNCA0MC43MDQ3Qzg2LjI5NjQgNDAuNzA0NyA4Ni41MDQ1IDQwLjY1MjcgODYuNzEyNiA0MC41MjI3Qzg2LjkyMDYgNDAuMzkyNiA4Ny4wNTA3IDQwLjIzNjYgODcuMTI4NyA0MC4wNTQ1VjM5LjQwNDNIODYuMzIyNEM4Ni4wMzYzIDM5LjQwNDMgODUuODAyMyAzOS40ODI0IDg1LjY0NjIgMzkuNjEyNEM4NS40OTAyIDM5Ljc0MjQgODUuMzg2MiAzOS45MjQ1IDg1LjM4NjIgNDAuMTMyNloiIGZpbGw9IndoaXRlIi8+CjxwYXRoIGQ9Ik04OS4yNjEzIDM2LjM2MTRIODkuOTg5NlYzNy4yOTc3SDkwLjcxNzhWMzcuODE3OUg4OS45ODk2VjQwLjE4NDZDODkuOTg5NiA0MC41MjI3IDkwLjExOTYgNDAuNjc4NyA5MC40MDU3IDQwLjY3ODdDOTAuNDgzNyA0MC42Nzg3IDkwLjU4NzcgNDAuNjUyNyA5MC42NjU4IDQwLjYyNjdMOTAuNzY5OCA0MS4xMjA4QzkwLjYzOTggNDEuMjI0OSA5MC40NTc3IDQxLjI3NjkgOTAuMjIzNiA0MS4yNzY5Qzg5LjkxMTUgNDEuMjc2OSA4OS43MDM1IDQxLjE5ODkgODkuNTIxNCA0MS4wMTY4Qzg5LjM2NTQgNDAuODM0OCA4OS4yNjEzIDQwLjU3NDcgODkuMjYxMyA0MC4yMTA2VjM3Ljg0MzlIODguNjM3MVYzNy4zMjM3SDg5LjI2MTNWMzYuMzYxNFoiIGZpbGw9IndoaXRlIi8+CjxwYXRoIGQ9Ik05Mi41MzgzIDM3LjI5NzdWNDEuMTk4OUg5MS44MzYxVjM3LjI5NzdIOTIuNTM4M1pNOTIuNTM4MyAzNS41ODEyVjM2LjMwOTRIOTEuODM2MVYzNS41ODEySDkyLjUzODNaIiBmaWxsPSJ3aGl0ZSIvPgo8cGF0aCBkPSJNOTQuMzMyOSAzOS4yMjIzVjM5LjMwMDNDOTQuMzMyOSAzOS43NDI0IDk0LjQxMDkgNDAuMDgwNSA5NC41OTI5IDQwLjM0MDZDOTQuNzc1IDQwLjYwMDcgOTUuMDM1MSA0MC43MzA3IDk1LjM3MzIgNDAuNzMwN0M5NS43MTEzIDQwLjczMDcgOTUuOTcxNCA0MC42MDA3IDk2LjE1MzQgNDAuMzQwNkM5Ni4zMzU1IDQwLjA4MDUgOTYuNDEzNSAzOS43NDI0IDk2LjQxMzUgMzkuMzAwM1YzOS4yMjIzQzk2LjQxMzUgMzguODA2MiA5Ni4zMzU1IDM4LjQ0MiA5Ni4xNTM0IDM4LjE4MkM5NS45NzE0IDM3LjkyMTkgOTUuNzExMyAzNy43OTE4IDk1LjM3MzIgMzcuNzkxOEM5NS4wMzUxIDM3Ljc5MTggOTQuNzc1IDM3LjkyMTkgOTQuNjE4OSAzOC4xODJDOTQuNDM2OSAzOC40NDIgOTQuMzMyOSAzOC44MDYyIDk0LjMzMjkgMzkuMjIyM1pNOTMuNjMwNiAzOS4zMDAzVjM5LjIyMjNDOTMuNjMwNiAzOC42MjQxIDkzLjc4NjcgMzguMTU2IDk0LjA5ODggMzcuNzkxOEM5NC40MTA5IDM3LjQyNzcgOTQuODI3IDM3LjI0NTcgOTUuMzczMiAzNy4yNDU3Qzk1LjkxOTMgMzcuMjQ1NyA5Ni4zMzU1IDM3LjQyNzcgOTYuNjQ3NSAzNy43OTE4Qzk2Ljk1OTYgMzguMTU2IDk3LjExNTcgMzguNjI0MSA5Ny4xMTU3IDM5LjIyMjNWMzkuMzAwM0M5Ny4xMTU3IDM5Ljg5ODUgOTYuOTU5NiA0MC4zNjY2IDk2LjY0NzUgNDAuNzMwN0M5Ni4zMzU1IDQxLjA5NDggOTUuOTE5MyA0MS4yNzY5IDk1LjM3MzIgNDEuMjc2OUM5NC44MjcgNDEuMjc2OSA5NC40MTA5IDQxLjA5NDggOTQuMDk4OCA0MC43MzA3QzkzLjc4NjcgNDAuMzY2NiA5My42MzA2IDM5Ljg5ODUgOTMuNjMwNiAzOS4zMDAzWiIgZmlsbD0id2hpdGUiLz4KPHBhdGggZD0iTTk4LjE1NiAzNy4yOTc3SDk4Ljc4MDJMOTguODMyMiAzNy44Njk5Qzk5LjA5MjMgMzcuNDI3NyA5OS40ODI0IDM3LjIxOTcgMTAwLjAwMyAzNy4yMTk3QzEwMC40MTkgMzcuMjE5NyAxMDAuNzU3IDM3LjM0OTcgMTAwLjk5MSAzNy41ODM4QzEwMS4yMjUgMzcuODQzOSAxMDEuMzI5IDM4LjIwOCAxMDEuMzI5IDM4LjcyODFWNDEuMTk4OUgxMDAuNjI3VjM4Ljc1NDFDMTAwLjYyNyAzOC40MTYgMTAwLjU0OSAzOC4xODIgMTAwLjQxOSAzOC4wMjU5QzEwMC4yODkgMzcuODY5OSAxMDAuMDgxIDM3LjgxNzkgOTkuNzk0NSAzNy44MTc5Qzk5LjU4NjQgMzcuODE3OSA5OS40MDQ0IDM3Ljg2OTkgOTkuMjQ4MyAzNy45NzM5Qzk5LjA5MjMgMzguMDc3OSA5OC45NjIyIDM4LjIwOCA5OC44NTgyIDM4LjM5VjQxLjIyNDlIOTguMTU2VjM3LjI5NzdaIiBmaWxsPSJ3aGl0ZSIvPgo8cGF0aCBkPSJNMTA1LjMwOCAzOS42OTA0QzEwNS4zMDggNDAuMDI4NSAxMDUuNDM4IDQwLjI2MjYgMTA1LjY3MiA0MC40NDQ2QzEwNS45MDYgNDAuNjI2NyAxMDYuMjE4IDQwLjcwNDcgMTA2LjU4MyA0MC43MDQ3QzEwNi45NDcgNDAuNzA0NyAxMDcuMjMzIDQwLjYyNjcgMTA3LjQ0MSA0MC40NzA3QzEwNy42NDkgNDAuMzE0NiAxMDcuNzUzIDQwLjEwNjUgMTA3Ljc1MyAzOS44NzI1QzEwNy43NTMgMzkuNjEyNCAxMDcuNjQ5IDM5LjQwNDMgMTA3LjQ2NyAzOS4yNDgzQzEwNy4yODUgMzkuMDkyMiAxMDYuOTczIDM4Ljk2MjIgMTA2LjUwNSAzOC44NTgyQzEwNS4zMzQgMzguNTcyMSAxMDQuNzM2IDM4LjA1MTkgMTA0LjczNiAzNy4yOTc3QzEwNC43MzYgMzYuODgxNiAxMDQuODkyIDM2LjU0MzUgMTA1LjIzIDM2LjI4MzRDMTA1LjU2OCAzNi4wMjMzIDEwNi4wMSAzNS44NjczIDEwNi41NTcgMzUuODY3M0MxMDcuMTAzIDM1Ljg2NzMgMTA3LjU0NSAzNi4wMjMzIDEwNy45MDkgMzYuMzM1NEMxMDguMjQ3IDM2LjY0NzUgMTA4LjQyOSAzNy4wMTE2IDEwOC40MDMgMzcuNDUzOFYzNy40Nzk4SDEwNy43MjdDMTA3LjcyNyAzNy4xNjc3IDEwNy42MjMgMzYuOTA3NiAxMDcuNDE1IDM2LjcyNTVDMTA3LjIwNyAzNi41NDM1IDEwNi45MjEgMzYuNDM5NCAxMDYuNTU3IDM2LjQzOTRDMTA2LjE5MiAzNi40Mzk0IDEwNS45MDYgMzYuNTE3NSAxMDUuNzI0IDM2LjY3MzVDMTA1LjU0MiAzNi44Mjk2IDEwNS40MzggMzcuMDM3NiAxMDUuNDM4IDM3LjI3MTdDMTA1LjQzOCAzNy41MDU4IDEwNS41NDIgMzcuNzEzOCAxMDUuNzUgMzcuODY5OUMxMDUuOTU4IDM4LjAyNTkgMTA2LjI5NiAzOC4xNTYgMTA2LjczOSAzOC4yNkMxMDcuODU3IDM4LjU0NjEgMTA4LjQyOSAzOS4wNjYyIDEwOC40MjkgMzkuODcyNUMxMDguNDI5IDQwLjI4ODYgMTA4LjI0NyA0MC42MjY3IDEwNy45MDkgNDAuODg2OEMxMDcuNTcxIDQxLjE0NjkgMTA3LjEwMyA0MS4yNzY5IDEwNi41NTcgNDEuMjc2OUMxMDYuMTkyIDQxLjI3NjkgMTA1Ljg4IDQxLjIyNDkgMTA1LjU2OCA0MS4wOTQ4QzEwNS4yNTYgNDAuOTY0OCAxMDUuMDIyIDQwLjc4MjcgMTA0Ljg0IDQwLjU0ODdDMTA0LjY1OCA0MC4zMTQ2IDEwNC41OCA0MC4wMjg1IDEwNC41OCAzOS43MTY0VjM5LjY5MDRIMTA1LjMwOFoiIGZpbGw9IndoaXRlIi8+CjxwYXRoIGQ9Ik0xMTAuMDk0IDM5LjIyMjNWMzkuMzAwM0MxMTAuMDk0IDM5Ljc0MjQgMTEwLjE3MiA0MC4wODA1IDExMC4zNTQgNDAuMzQwNkMxMTAuNTM2IDQwLjYwMDcgMTEwLjc5NiA0MC43MzA3IDExMS4xMzQgNDAuNzMwN0MxMTEuNDcyIDQwLjczMDcgMTExLjczMiA0MC42MDA3IDExMS45MTQgNDAuMzQwNkMxMTIuMDk2IDQwLjA4MDUgMTEyLjE3NCAzOS43NDI0IDExMi4xNzQgMzkuMzAwM1YzOS4yMjIzQzExMi4xNzQgMzguODA2MiAxMTIuMDk2IDM4LjQ0MiAxMTEuOTE0IDM4LjE4MkMxMTEuNzMyIDM3LjkyMTkgMTExLjQ3MiAzNy43OTE4IDExMS4xMzQgMzcuNzkxOEMxMTAuNzk2IDM3Ljc5MTggMTEwLjUzNiAzNy45MjE5IDExMC4zOCAzOC4xODJDMTEwLjE3MiAzOC40NDIgMTEwLjA5NCAzOC44MDYyIDExMC4wOTQgMzkuMjIyM1pNMTA5LjM2NSAzOS4zMDAzVjM5LjIyMjNDMTA5LjM2NSAzOC42MjQxIDEwOS41MjEgMzguMTU2IDEwOS44MzQgMzcuNzkxOEMxMTAuMTQ2IDM3LjQyNzcgMTEwLjU2MiAzNy4yNDU3IDExMS4xMDggMzcuMjQ1N0MxMTEuNjU0IDM3LjI0NTcgMTEyLjA3IDM3LjQyNzcgMTEyLjM4MiAzNy43OTE4QzExMi42OTQgMzguMTU2IDExMi44NSAzOC42MjQxIDExMi44NSAzOS4yMjIzVjM5LjMwMDNDMTEyLjg1IDM5Ljg5ODUgMTEyLjY5NCA0MC4zNjY2IDExMi4zODIgNDAuNzMwN0MxMTIuMDcgNDEuMDk0OCAxMTEuNjU0IDQxLjI3NjkgMTExLjEwOCA0MS4yNzY5QzExMC41NjIgNDEuMjc2OSAxMTAuMTQ2IDQxLjA5NDggMTA5LjgzNCA0MC43MzA3QzEwOS41NDcgNDAuMzY2NiAxMDkuMzY1IDM5Ljg5ODUgMTA5LjM2NSAzOS4zMDAzWiIgZmlsbD0id2hpdGUiLz4KPHBhdGggZD0iTTExNC42NzEgMzUuNTgxMlY0MS4xOTg5SDExMy45NjlWMzUuNTgxMkgxMTQuNjcxWiIgZmlsbD0id2hpdGUiLz4KPHBhdGggZD0iTTExNy4yNzIgNDEuMjc2OUMxMTYuODMgNDEuMjc2OSAxMTYuNDkyIDQxLjE0NjkgMTE2LjI1NyA0MC44NjA4QzExNi4wMjMgNDAuNTc0NyAxMTUuOTE5IDQwLjE1ODYgMTE1LjkxOSAzOS41ODY0VjM3LjI5NzdIMTE2LjYyMlYzOS42MTI0QzExNi42MjIgNDAuMDI4NSAxMTYuNjc0IDQwLjMxNDYgMTE2LjgwNCA0MC40NzA3QzExNi45MzQgNDAuNjI2NyAxMTcuMTE2IDQwLjcwNDcgMTE3LjM3NiA0MC43MDQ3QzExNy44NyA0MC43MDQ3IDExOC4yMDggNDAuNDk2NyAxMTguMzkgNDAuMTA2NVYzNy4yOTc3SDExOS4wOTJWNDEuMTk4OUgxMTguNDQyTDExOC4zOSA0MC42MjY3QzExOC4xNTYgNDEuMDY4OCAxMTcuNzY2IDQxLjI3NjkgMTE3LjI3MiA0MS4yNzY5WiIgZmlsbD0id2hpdGUiLz4KPHBhdGggZD0iTTEyMC41MjMgMzYuMzYxNEgxMjEuMjUxVjM3LjI5NzdIMTIxLjk3OVYzNy44MTc5SDEyMS4yNTFWNDAuMTg0NkMxMjEuMjUxIDQwLjUyMjcgMTIxLjM4MSA0MC42Nzg3IDEyMS42NjcgNDAuNjc4N0MxMjEuNzQ1IDQwLjY3ODcgMTIxLjg0OSA0MC42NTI3IDEyMS45MjcgNDAuNjI2N0wxMjIuMDMxIDQxLjEyMDhDMTIxLjkwMSA0MS4yMjQ5IDEyMS43MTkgNDEuMjc2OSAxMjEuNDg1IDQxLjI3NjlDMTIxLjE5OSA0MS4yNzY5IDEyMC45NjUgNDEuMTk4OSAxMjAuNzgzIDQxLjAxNjhDMTIwLjYyNyA0MC44MzQ4IDEyMC41MjMgNDAuNTc0NyAxMjAuNTIzIDQwLjIxMDZWMzcuODQzOUgxMTkuODk5VjM3LjMyMzdIMTIwLjUyM1YzNi4zNjE0WiIgZmlsbD0id2hpdGUiLz4KPHBhdGggZD0iTTEyMy44IDM3LjI5NzdWNDEuMTk4OUgxMjMuMDk4VjM3LjI5NzdIMTIzLjhaTTEyMy44IDM1LjU4MTJWMzYuMzA5NEgxMjMuMDk4VjM1LjU4MTJIMTIzLjhaIiBmaWxsPSJ3aGl0ZSIvPgo8cGF0aCBkPSJNMTI1LjU5NCAzOS4yMjIzVjM5LjMwMDNDMTI1LjU5NCAzOS43NDI0IDEyNS42NzIgNDAuMDgwNSAxMjUuODU0IDQwLjM0MDZDMTI2LjAzNiA0MC42MDA3IDEyNi4yOTYgNDAuNzMwNyAxMjYuNjM1IDQwLjczMDdDMTI2Ljk3MyA0MC43MzA3IDEyNy4yMzMgNDAuNjAwNyAxMjcuNDE1IDQwLjM0MDZDMTI3LjU5NyA0MC4wODA1IDEyNy42NzUgMzkuNzQyNCAxMjcuNjc1IDM5LjMwMDNWMzkuMjIyM0MxMjcuNjc1IDM4LjgwNjIgMTI3LjU3MSAzOC40NDIgMTI3LjQxNSAzOC4xODJDMTI3LjIzMyAzNy45MjE5IDEyNi45NzMgMzcuNzkxOCAxMjYuNjM1IDM3Ljc5MThDMTI2LjI5NiAzNy43OTE4IDEyNi4wMzYgMzcuOTIxOSAxMjUuODggMzguMTgyQzEyNS42NzIgMzguNDQyIDEyNS41OTQgMzguODA2MiAxMjUuNTk0IDM5LjIyMjNaTTEyNC44OTIgMzkuMzAwM1YzOS4yMjIzQzEyNC44OTIgMzguNjI0MSAxMjUuMDQ4IDM4LjE1NiAxMjUuMzYgMzcuNzkxOEMxMjUuNjcyIDM3LjQyNzcgMTI2LjA4OCAzNy4yNDU3IDEyNi42MzUgMzcuMjQ1N0MxMjcuMTgxIDM3LjI0NTcgMTI3LjU5NyAzNy40Mjc3IDEyNy45MDkgMzcuNzkxOEMxMjguMjIxIDM4LjE1NiAxMjguMzc3IDM4LjYyNDEgMTI4LjM3NyAzOS4yMjIzVjM5LjMwMDNDMTI4LjM3NyAzOS44OTg1IDEyOC4yMjEgNDAuMzY2NiAxMjcuOTA5IDQwLjczMDdDMTI3LjU5NyA0MS4wOTQ4IDEyNy4xODEgNDEuMjc2OSAxMjYuNjM1IDQxLjI3NjlDMTI2LjA4OCA0MS4yNzY5IDEyNS42NzIgNDEuMDk0OCAxMjUuMzYgNDAuNzMwN0MxMjUuMDQ4IDQwLjM2NjYgMTI0Ljg5MiAzOS44OTg1IDEyNC44OTIgMzkuMzAwM1oiIGZpbGw9IndoaXRlIi8+CjxwYXRoIGQ9Ik0xMjkuNDE3IDM3LjI5NzdIMTMwLjA0MkwxMzAuMDk0IDM3Ljg2OTlDMTMwLjM1NCAzNy40Mjc3IDEzMC43NDQgMzcuMjE5NyAxMzEuMjY0IDM3LjIxOTdDMTMxLjY4IDM3LjIxOTcgMTMyLjAxOCAzNy4zNDk3IDEzMi4yNTIgMzcuNTgzOEMxMzIuNDg2IDM3Ljg0MzkgMTMyLjU5IDM4LjIwOCAxMzIuNTkgMzguNzI4MVY0MS4xOTg5SDEzMS44ODhWMzguNzU0MUMxMzEuODg4IDM4LjQxNiAxMzEuODEgMzguMTgyIDEzMS42OCAzOC4wMjU5QzEzMS41NSAzNy44Njk5IDEzMS4zNDIgMzcuODE3OSAxMzEuMDU2IDM3LjgxNzlDMTMwLjg0OCAzNy44MTc5IDEzMC42NjYgMzcuODY5OSAxMzAuNTEgMzcuOTczOUMxMzAuMzU0IDM4LjA3NzkgMTMwLjIyNCAzOC4yMDggMTMwLjEyIDM4LjM5VjQxLjIyNDlIMTI5LjQxN1YzNy4yOTc3WiIgZmlsbD0id2hpdGUiLz4KPHBhdGggZD0iTTEzNS4yNDMgNDAuNzMwN0MxMzUuNTAzIDQwLjczMDcgMTM1LjY4NSA0MC42Nzg3IDEzNS44NDEgNDAuNTc0N0MxMzUuOTk3IDQwLjQ3MDcgMTM2LjA0OSA0MC4zNDA2IDEzNi4wNDkgNDAuMTg0NkMxMzYuMDQ5IDQwLjAyODUgMTM1Ljk5NyAzOS44OTg1IDEzNS44NjcgMzkuNzk0NUMxMzUuNzM3IDM5LjY5MDQgMTM1LjUyOSAzOS42MTI0IDEzNS4xOTEgMzkuNTM0NEMxMzQuNjk3IDM5LjQzMDMgMTM0LjMzMyAzOS4yNzQzIDEzNC4wOTkgMzkuMTE4MkMxMzMuODY1IDM4LjkzNjIgMTMzLjc2MSAzOC43MDIxIDEzMy43NjEgMzguMzlDMTMzLjc2MSAzOC4wNzc5IDEzMy44OTEgMzcuODE3OSAxMzQuMTc3IDM3LjU4MzhDMTM0LjQzNyAzNy4zNDk3IDEzNC44MDEgMzcuMjQ1NyAxMzUuMjQzIDM3LjI0NTdDMTM1LjY4NSAzNy4yNDU3IDEzNi4wMjMgMzcuMzQ5NyAxMzYuMzA5IDM3LjU4MzhDMTM2LjU3IDM3LjgxNzkgMTM2LjcgMzguMTAzOSAxMzYuNyAzOC40NDJWMzguNDY4MUgxMzYuMDIzQzEzNi4wMjMgMzguMjg2IDEzNS45NDUgMzguMTI5OSAxMzUuODE1IDM3Ljk5OTlDMTM1LjY1OSAzNy44Njk5IDEzNS40NzcgMzcuNzkxOCAxMzUuMjQzIDM3Ljc5MThDMTM1LjAwOSAzNy43OTE4IDEzNC44MjcgMzcuODQzOSAxMzQuNjk3IDM3Ljk0NzlDMTM0LjU2NyAzOC4wNTE5IDEzNC41MTUgMzguMTgyIDEzNC41MTUgMzguMzM4QzEzNC41MTUgMzguNDk0MSAxMzQuNTY3IDM4LjYyNDEgMTM0LjY3MSAzOC43MDIxQzEzNC43NzUgMzguNzgwMSAxMzUuMDA5IDM4Ljg1ODIgMTM1LjMyMSAzOC45MzYyQzEzNS44MTUgMzkuMDQwMiAxMzYuMTc5IDM5LjE5NjMgMTM2LjQ0IDM5LjM3ODNDMTM2LjY3NCAzOS41NjA0IDEzNi44MDQgMzkuNzk0NCAxMzYuODA0IDQwLjEwNjVDMTM2LjgwNCA0MC40NDQ2IDEzNi42NzQgNDAuNzMwNyAxMzYuMzg3IDQwLjkzODhDMTM2LjEwMSA0MS4xNDY5IDEzNS43MzcgNDEuMjUwOSAxMzUuMjY5IDQxLjI1MDlDMTM0LjgwMSA0MS4yNTA5IDEzNC40MTEgNDEuMTIwOCAxMzQuMTI1IDQwLjg4NjhDMTMzLjgzOSA0MC42NTI3IDEzMy43MDkgNDAuMzQwNiAxMzMuNzA5IDQwLjAwMjVWMzkuOTc2NUgxMzQuMzg1QzEzNC40MTEgNDAuMjEwNiAxMzQuNDg5IDQwLjM5MjYgMTM0LjY0NSA0MC41MjI3QzEzNC43NzUgNDAuNjc4NyAxMzQuOTgzIDQwLjczMDcgMTM1LjI0MyA0MC43MzA3WiIgZmlsbD0id2hpdGUiLz4KPGRlZnM+CjxsaW5lYXJHcmFkaWVudCBpZD0icGFpbnQwX2xpbmVhcl8xMDFfNjUiIHgxPSIwLjAwOTg0NDgyIiB5MT0iMjcuNzc4OCIgeDI9IjQ2LjY2NTgiIHkyPSIyNy43Nzg4IiBncmFkaWVudFVuaXRzPSJ1c2VyU3BhY2VPblVzZSI+CjxzdG9wIHN0b3AtY29sb3I9IiNGNUIwMUIiLz4KPHN0b3Agb2Zmc2V0PSIxIiBzdG9wLWNvbG9yPSIjRjJBMDFGIi8+CjwvbGluZWFyR3JhZGllbnQ+CjwvZGVmcz4KPC9zdmc+Cg==',
                            'width'   => '250px', // passt die Breite der Grafik an
                        ],
                        [
                            'type'    => 'Image',
                            'name'    => 'CantataText',
                            'image'  => 'data:image/svg+xml;base64, PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4NDQo8IS0tIEdlbmVyYXRvcjogQWRvYmUgSWxsdXN0cmF0b3IgMjYuMC4yLCBTVkcgRXhwb3J0IFBsdWctSW4gLiBTVkcgVmVyc2lvbjogNi4wMCBCdWlsZCAwKSAgLS0+DQ0KPHN2ZyB2ZXJzaW9uPSIxLjEiIGlkPSJMYXllcl8xIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHhtbG5zOnhsaW5rPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5L3hsaW5rIiB4PSIwcHgiIHk9IjBweCINDQoJIHZpZXdCb3g9IjAgMCAyMTcuMSA0MC44IiBzdHlsZT0iZW5hYmxlLWJhY2tncm91bmQ6bmV3IDAgMCAyMTcuMSA0MC44OyIgeG1sOnNwYWNlPSJwcmVzZXJ2ZSI+DQ0KPGc+DQ0KCTxwb2x5Z29uIHBvaW50cz0iMzEuOSw2LjkgMjksMTIuOSAxMCwxMi45IDEwLDI0LjcgMTIuMiwyNy44IDMxLDI3LjggMzEsMzMuOCAxMCwzMy43IDQsMjYuNCA0LDYuOSAJIi8+DQ0KCTxwb2x5Z29uIHBvaW50cz0iMzUsMzMuOCAzNSw2LjkgNjAuOSw2LjkgNjAuOSwzNi44IDQ0LjksMjcuNyA0NC45LDIwLjggNTUuMSwyNy4xIDU1LjEsMTIuNyA0MSwxMi43IDQxLDMzLjggCSIvPg0NCgk8cG9seWdvbiBwb2ludHM9IjY0LjksMzMuNyA2NC45LDIgODUuMSwyMS45IDg1LjEsNi45IDkxLjcsNi45IDkxLjcsMzguOCA3MiwxOC45IDcyLDMzLjcgCSIvPg0NCgk8cG9seWdvbiBwb2ludHM9Ijk2LDYuOCA5NiwxMy45IDEwNS45LDEzLjkgMTA1LjksMzUgMTEzLDMxLjYgMTEzLDEzLjggMTIwLjksMTMuOCAxMjQuMiw2LjggCSIvPg0NCgk8cG9seWdvbiBwb2ludHM9IjEyNS45LDMzLjcgMTI1LjksNi44IDE1NC4xLDYuOCAxNTMuOCwzNy43IDEzNS45LDI3LjYgMTM1LjksMjAuOCAxNDcuMSwyNyAxNDcsMTIuOSAxMzIuOSwxMi44IDEzMi45LDMzLjcgCSIvPg0NCgk8cG9seWdvbiBwb2ludHM9IjE1Nyw2LjQgMTU3LDEzLjUgMTY3LDEzLjUgMTY3LDM0LjYgMTc0LDMxLjIgMTc0LDEzLjQgMTgxLjksMTMuNCAxODUuMiw2LjQgCSIvPg0NCgk8cG9seWdvbiBwb2ludHM9IjE4Ni45LDMzLjMgMTg2LjksNi40IDIxNS4xLDYuNCAyMTQuOCwzNy4zIDE5NywyNy4yIDE5NywyMC40IDIwOC4xLDI2LjYgMjA4LDEyLjUgMTkzLjksMTIuNCAxOTMuOSwzMy4zIAkiLz4NDQo8L2c+DQ0KPC9zdmc+DQ0K',
                            'width'   => '250px', // passt die Breite der Grafik an
                        ]
                    ]
                ],
                [
                    'type'    => 'SelectCategory',
                    'name'    => 'ScriptCategory',
                    'caption' => $this->Translate('Category for Script Creation'),
                    'value'   => $this->ReadPropertyInteger('ScriptCategory')
                ],
                [
                    'type'    => 'Button',
                    'caption' => $this->Translate('Create Scripts'),
                    'onClick' => 'CRSX_CreateScripts($id);'
                ],
                [
                    'type' => 'Label',
                    'caption' => $this->Translate('Haptique Device Configuration')
                ],
                [
                    'type' => 'ValidationTextBox',
                    'name' => 'DeviceName',
                    'caption' => $this->Translate('Device Name'),
                    'value' => $this->ReadPropertyString('DeviceName'),
                    'enabled' => true
                ],
                [
                    'type' => 'ValidationTextBox',
                    'name' => 'DeviceID',
                    'caption' => $this->Translate('Device ID'),
                    'value' => $this->ReadPropertyString('DeviceID'),
                    'enabled' => true
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'EnableDebug',
                    'caption' => $this->Translate('Enable Debugging')
                ],
                [
                    'type'    => 'List',
                    'name'    => 'Commands',
                    'caption' => 'Commands',
                    'rowCount'=> 15,
                    'columns' => [
                        ['caption' => 'Name', 'name' => 'CommandName', 'width' => 'auto'],
                        ['caption' => 'ID',   'name' => 'CommandID',   'width' => '400px'],
                    ],
                    'values'  => $rows
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'Resubscribe /detail',
                    'onClick' => 'CRSX_StartGetCommands(' . $this->InstanceID . ');'
                ]
            ],
            'actions' => [

            ],
            'status' => [
                ['code' => IS_CREATING, 'icon' => 'gear', 'caption' => $this->Translate('Device is being created')],
                ['code' => IS_ACTIVE, 'icon' => 'active', 'caption' => $this->Translate('Device connected and active')],
                ['code' => IS_DELETING, 'icon' => 'inactive', 'caption' => $this->Translate('Device is being deleted')],
                ['code' => IS_INACTIVE, 'icon' => 'inactive', 'caption' => $this->Translate('Device inactive')],
                ['code' => IS_NOTCREATED, 'icon' => 'error', 'caption' => $this->Translate('Device not created or error occurred')],
            ]
        ];

        return json_encode($form);
    }
}