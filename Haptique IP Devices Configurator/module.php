<?php

declare(strict_types=1);

class HaptiqueIPDevicesConfigurator extends IPSModuleStrict
{
    public function GetCompatibleParents(): string
    {
        return json_encode([
            'type' => 'connect',
            'moduleIDs' => ['{D5CC2243-C3B3-4C0D-1E07-81701A5FE120}'] // Parent: Haptique Splitter
        ]);
    }
    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        //we will wait until the kernel is ready
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        $this->RegisterAttributeString('Token', "");
        $this->RegisterPropertyString('IPDevicesTree', '[]');
        $this->RegisterPropertyString('TemplateVariables', '[]'); // Postman-Style Variablen
        $this->RegisterPropertyString('KinconyWizardDevices', '[]'); // Wizard list (Kincony devices)

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

        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->RunDeferredInitialization();
        }

        // Standardmäßig nach ApplyChanges aktiv setzen
        $this->SetStatus(IS_ACTIVE);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        //Never delete this line!
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
            $this->SendDebug("MessageSink", "🔄 Kernel Ready", 0);
        }

        if ($Message == IPS_KERNELSTARTED) {
            $this->SendDebug("MessageSink", "🔄 Kernel Started", 0);
            $this->RunDeferredInitialization();
        }

        if ($Message == IM_CHANGESTATUS && $Data[0] == IS_ACTIVE) {
            $this->SendDebug("MessageSink", "🔄 Instanz aktiv", 0);
        }
    }

    private function RunDeferredInitialization(): void
    {
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->SendDebug(__FUNCTION__, 'Kernel not ready yet - deferred initialization skipped', 0);
            return;
        }

        $instance = @IPS_GetInstance($this->InstanceID);
        $parentId = is_array($instance) && isset($instance['ConnectionID']) ? (int) $instance['ConnectionID'] : 0;

        if ($parentId <= 0 || !IPS_InstanceExists($parentId)) {
            $this->SendDebug(__FUNCTION__, 'No connected splitter available yet - deferred initialization skipped', 0);
            return;
        }

        // Sync Kincony Wizard selection with RS90 Custom URL devices after the user clicked "Änderungen übernehmen".
        try {
            $this->SyncKinconyWizardSelectionToRs90();
        } catch (Throwable $e) {
            $this->SendDebug(__FUNCTION__, 'SyncKinconyWizardSelectionToRs90 exception: ' . $e->getMessage(), 0);
            $this->SendDebug(__FUNCTION__, $e->getTraceAsString(), 0);

            // optional: Fehlerstatus setzen
            $this->SetStatus(IS_EBASE + 1);
        }
    }

    private function SendToSplitter(array $payload)
    {
        // Anfrage an den Splitter senden
        $request = [
            'DataID' => '{A04B56D8-C2A2-B7BB-11F1-523502DE2933}', // Splitter DataID
            'Payload' => $payload
        ];

        $this->SendDebug(__FUNCTION__ . ' Request', json_encode($request), 0);

        if (!$this->HasActiveParent()) {
            $this->SendDebug(__FUNCTION__, 'Skip send - no active parent', 0);
            return null;
        }

        $response = $this->SendDataToParent(json_encode($request));
        if (!is_string($response) || $response === '') {
            $this->SendDebug(__FUNCTION__ . ' Response', 'Empty/invalid response from parent', 0);
            return null;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            $this->SendDebug(__FUNCTION__ . ' Response', 'JSON decode failed: ' . $response, 0);
            return null;
        }

        return $decoded;
    }

    public function ReloadKinconyWizard()
    {
        // Force refresh (no stored values) and push into the UI list.
        $rows = $this->GetKinconyDeviceInstances();
        $json = json_encode($rows);
        if ($json === false) {
            $this->SendDebug(__FUNCTION__, 'json_encode failed: ' . json_last_error_msg(), 0);
            return;
        }

        // Update list in UI (pending apply)
        $this->UpdateFormField('KinconyWizardDevices', 'values', (string)$json);
        $this->SendDebug(__FUNCTION__, 'Kincony wizard list refreshed (' . count($rows) . ' devices)', 0);
    }

    private function GetCustomURLDevices(): array
    {
        // Anfrage an den Splitter: er soll die Custom-URL-Devices vom RS90 liefern
        $response = $this->SendToSplitter([
            'Command' => 'GetCustomURLDevices'
        ]);

        if (!is_array($response)) {
            $this->SendDebug(__FUNCTION__, 'No valid response from splitter', 0);
            return [];
        }

        // Envelope support
        if (isset($response['Success'])) {
            if (!$response['Success']) {
                $msg = isset($response['Message']) ? (string)$response['Message'] : 'Unknown error';
                $this->SendDebug(__FUNCTION__, 'Splitter returned Success=false: ' . $msg, 0);
                return [];
            }
            $deviceList = (isset($response['Data']) && is_array($response['Data'])) ? $response['Data'] : [];
        } elseif (isset($response['success'])) {
            if (!$response['success']) {
                $msg = isset($response['message']) ? (string)$response['message'] : 'Unknown error';
                $this->SendDebug(__FUNCTION__, 'Splitter returned success=false: ' . $msg, 0);
                return [];
            }
            $deviceList = (isset($response['data']) && is_array($response['data'])) ? $response['data'] : [];
        } else {
            $deviceList = $response;
        }

        if (!isset($deviceList['records']) || !is_array($deviceList['records'])) {
            $this->SendDebug(__FUNCTION__, 'Response does not contain records', 0);
            $this->SendDebug(__FUNCTION__ . ' RawResponse', json_encode($response), 0);
            return [];
        }

        return $deviceList['records'];
    }

    private function GetCustomURLDeviceNames(): array
    {
        $records = $this->GetCustomURLDevices();

        $result = [];
        foreach ($records as $record) {
            $name = (isset($record['name']) && is_string($record['name'])) ? $record['name'] : '';
            if ($name === '') {
                continue;
            }
            $id = (isset($record['id']) && is_string($record['id'])) ? $record['id'] : '';
            $result[] = [
                'id'   => $id,
                'name' => $name
            ];
        }

        usort($result, function ($a, $b) {
            return strcasecmp((string)$a['name'], (string)$b['name']);
        });

        return $result;
    }

    private function GetDeviceCommands(string $deviceId): array
    {
        if ($deviceId === '') {
            return [];
        }

        $response = $this->SendToSplitter([
            'Command'  => 'GetDeviceCommands',
            'DeviceID' => $deviceId
        ]);

        if (!is_array($response)) {
            $this->SendDebug(__FUNCTION__, 'No valid response from splitter', 0);
            return [];
        }

        // Envelope support
        if (isset($response['Success'])) {
            if (!$response['Success']) {
                $msg = isset($response['Message']) ? (string)$response['Message'] : 'Unknown error';
                $this->SendDebug(__FUNCTION__, 'Splitter returned Success=false: ' . $msg, 0);
                return [];
            }
            $data = (isset($response['Data']) && is_array($response['Data'])) ? $response['Data'] : [];
        } elseif (isset($response['success'])) {
            if (!$response['success']) {
                $msg = isset($response['message']) ? (string)$response['message'] : 'Unknown error';
                $this->SendDebug(__FUNCTION__, 'Splitter returned success=false: ' . $msg, 0);
                return [];
            }
            $data = (isset($response['data']) && is_array($response['data'])) ? $response['data'] : [];
        } else {
            $data = $response;
        }

        // Erwartet: ['controls' => [...]]
        if (!isset($data['controls']) || !is_array($data['controls'])) {
            return [];
        }

        return $data['controls'];
    }

    private function BuildDevicesCommandsTreeValues(): array
    {
        $records = $this->GetCustomURLDevices();
        if (empty($records)) {
            return [];
        }

        // Devices nach Name sortieren
        usort($records, function ($a, $b) {
            return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });

        $values = [];
        $rowId = 1; // Tree erwartet id > 0 (am besten int)

        foreach ($records as $device) {
            $deviceId   = (isset($device['id']) && is_string($device['id'])) ? $device['id'] : '';
            $deviceName = (isset($device['name']) && is_string($device['name'])) ? $device['name'] : '';
            if ($deviceId === '' || $deviceName === '') {
                continue;
            }

            $deviceRowId = $rowId++;

            // Device-Zeile (Root)
            $values[] = [
                'id'        => $deviceRowId,
                'parent'    => 0,
                'Name'      => $deviceName,
                'Type'      => 'Device',
                'RemoteID'  => $deviceId,
                'Method'    => '',
                'IP'        => '',
                'Command'   => '',
                'URL'       => '',
                'expanded'  => false,
                'editable'  => false,
                'deletable' => false
            ];

            // Commands holen
            $controls = $this->GetDeviceCommands($deviceId);
            if (!is_array($controls) || empty($controls)) {
                continue;
            }

            // Commands nach Name sortieren
            usort($controls, function ($a, $b) {
                return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
            });

            foreach ($controls as $control) {
                $controlName = (string)($control['name'] ?? '');
                if ($controlName === '') {
                    continue;
                }

                $ref = (isset($control['referenceData']) && is_array($control['referenceData'])) ? $control['referenceData'] : [];
                $method  = (string)($ref['method'] ?? '');
                $ip      = (string)($ref['ip'] ?? '');
                $command = (string)($ref['command'] ?? '');
                $url     = (string)($ref['url'] ?? '');

                $values[] = [
                    'id'        => $rowId++,
                    'parent'    => $deviceRowId,
                    'Name'      => $controlName,
                    'Type'      => 'Command',
                    'RemoteID'  => (isset($control['id']) && is_string($control['id'])) ? $control['id'] : '',
                    'Method'    => $method,
                    'IP'        => $ip,
                    'Command'   => $command,
                    'URL'       => $url,
                    // 'form' => $this->BuildCommandEditForm($method),
                    'expanded'  => false,
                    'editable'  => true,
                    'deletable' => true
                ];
            }
        }

        return $values;
    }

    public function OnCheckboxClick(bool $selected)
    {
        $this->SendDebug('OnCheckboxClick', 'trigged', 0);
        // TODO save values
        /*

        // Prüfe, ob die Struktur korrekt ankommt und rekursiv durchgehen
        $treeData = $this->TreeViewToArray($AVDevicesTree);
        $this->SendDebug('TreeView Data', json_encode($treeData), 0);

        // Den aktuell gespeicherten TreeView-Zustand auslesen
        $previousTree = json_decode($this->ReadAttributeString('AVDevicesTree'), true);
        $this->SendDebug('Previous Tree', json_encode($previousTree), 0);

        // Teste den Fall, ob die Bäume leer sind
        if (empty($treeData)) {
            $this->SendDebug('TreeData', 'TreeData ist leer', 0);
            return;
        }

        if (empty($previousTree)) {
            $this->SendDebug('PreviousTree', 'PreviousTree ist leer', 0);
            return;
        }

        // Vergleiche die neuen und alten Checkbox-Status basierend auf der InstanceID
        foreach ($treeData as $currentItem) {
            // Prüfen, ob 'InstanceID' existiert
            if (!isset($currentItem['InstanceID'])) {
                continue; // Überspringe diesen Eintrag, wenn keine 'InstanceID' vorhanden ist
            }

            $this->SendDebug('Vergleiche Eintrag', "Aktuelle InstanceID: " . $currentItem['InstanceID'], 0);

            // Die entsprechende 'InstanceID' im previousTree suchen
            $found = false;
            foreach ($previousTree as &$previousItem) {
                if (isset($previousItem['InstanceID']) && $previousItem['InstanceID'] == $currentItem['InstanceID']) {
                    $found = true;

                    // Debug-Ausgabe der `checked`-Werte
                    $this->SendDebug('Vergleich Checked-Wert', "Aktueller Wert: " . json_encode($currentItem['checked']) . " - Vorheriger Wert: " . json_encode($previousItem['checked']), 0);

                    // Prüfen, ob 'checked' ein Array ist und wie darauf zugegriffen wird
                    $checkedValue = is_array($currentItem['checked']) ? $currentItem['checked'] : $currentItem['checked'];

                    // Nun die 'checked'-Werte vergleichen
                    if ($checkedValue !== $previousItem['checked']) {
                        $this->SendDebug('Checkbox Changed', "Device: " . $currentItem['DeviceName'] . " - New Checked Value: " . $checkedValue, 0);

                        // Speichere die Änderung im previousTree
                        $previousItem['checked']['value'] = $checkedValue;
                    } else {
                        $this->SendDebug('No Change', "Device: " . $currentItem['DeviceName'] . " - Checked Value bleibt unverändert", 0);
                    }
                    break; // Beende die Schleife, wenn ein Match gefunden wurde
                }
            }

            if (!$found) {
                $this->SendDebug('No Matching Entry', "Kein passender Eintrag für InstanceID: " . $currentItem['InstanceID'] . " gefunden", 0);
            }
        }

        // Aktualisierten Tree-Zustand speichern
        $treeDataSize = strlen(json_encode($previousTree));   // Größe der Daten bestimmen
        $this->SendDebug('TreeData Size', "Größe von AVDevicesTree: " . $treeDataSize . " Bytes", 0);
        $this->WriteAttributeString('AVDevicesTree', json_encode($previousTree));
        */
    }

    private function TreeViewToArray($tree): array
    {
        $result = [];

        // Durch alle Knoten im TreeView iterieren
        foreach ($tree as $item) {
            $convertedItem = (array)$item; // Konvertiere jeden Knoten in ein Array
            $result[] = $convertedItem;

            // Rekursiv verarbeiten, falls Unterknoten vorhanden sind
            if (isset($item->children) && is_array($item->children)) {
                $convertedItem['children'] = $this->TreeViewToArray($item->children);
            }
        }

        return $result;
    }

    private function GetStoredScenes(): array
    {
        $scenes = json_decode($this->ReadPropertyString("Scenes"), true);
        return is_array($scenes) ? $scenes : [];
    }


    /**
     * Hilfsmethode, um den formatierten Wert aus einem Variablenprofil zu holen.
     * @param int $variableID Die ID der Zielvariable
     * @param mixed $value Der zugehörige Wert
     * @return string Der formatierte Wert
     */
    private function GetFormattedValue(int $variableID, $value): string
    {
        if (IPS_VariableExists($variableID)) {
            $variable = IPS_GetVariable($variableID);
            $profileName = $variable['VariableProfile'] ?? $variable['VariableCustomProfile'] ?? null;

            if ($profileName) {
                $profile = IPS_GetVariableProfile($profileName);
                foreach ($profile['Associations'] as $association) {
                    if ($association['Value'] == $value) {
                        return $association['Name'];
                    }
                }
            }
        }

        return ''; // Fallback: Kein formatierter Wert gefunden
    }

    private function GetTemplateVariables(): array
    {
        $raw = json_decode($this->ReadPropertyString('TemplateVariables'), true);
        if (!is_array($raw)) {
            return [];
        }

        $vars = [];
        foreach ($raw as $row) {
            $rawName  = isset($row['Name']) ? trim((string)$row['Name']) : '';
            $value    = isset($row['Value']) ? (string)$row['Value'] : '';

            if ($rawName === '') {
                continue;
            }

            // Accept either "Token" or "{{Token}}" (UI-friendly)
            $name = $rawName;
            if (preg_match('/^\{\{([A-Za-z0-9_\-\.]+)\}\}$/', $rawName, $m)) {
                $name = $m[1];
            }

            // Validate key (inner name)
            if (!preg_match('/^[A-Za-z0-9_\-\.]+$/', $name)) {
                $this->SendDebug(__FUNCTION__, "Invalid variable name ignored: " . $rawName, 0);
                continue;
            }

            $vars[$name] = $value;
        }

        return $vars;
    }

    private function ReplacePlaceholders(string $text, array $vars): string
    {
        if ($text === '' || empty($vars)) {
            return $text;
        }

        // ersetzt {{VarName}} mit Wert
        return preg_replace_callback('/\{\{([A-Za-z0-9_\-\.]+)\}\}/', function ($m) use ($vars) {
            $key = $m[1];
            return array_key_exists($key, $vars) ? $vars[$key] : $m[0]; // unbekannte bleiben wie sie sind
        }, $text);
    }

    /**
     * Safe property reader for foreign instances.
     * Returns $default if property does not exist or cannot be read.
     */
    private function SafeReadInstanceProperty(int $instanceId, string $property, $default = '')
    {
        try {
            // Cross-instance property read
            $val = IPS_GetProperty($instanceId, $property);
            if ($val === null) {
                return $default;
            }
            return $val;
        } catch (Throwable $e) {
            return $default;
        }
    }

    /**
     * Reads all Kincony device instances from the Symcon object tree.
     * Kincony devices module GUID (Instance ModuleID): {B4D9D3AE-1668-4832-71DA-831516138207}
     *
     * Returns: array of rows with Name, ObjectID, DeviceType, Selected
     */
    private function GetKinconyDeviceInstances(): array
    {
        $moduleId = '{B4D9D3AE-1668-4832-71DA-831516138207}';
        $this->SendDebug(__FUNCTION__, 'Start reading Kincony instances. ModuleID=' . $moduleId, 0);

        $ids = IPS_GetInstanceListByModuleID($moduleId);
        if (!is_array($ids)) {
            $this->SendDebug(__FUNCTION__, 'IPS_GetInstanceListByModuleID returned non-array', 0);
        } else {
            $this->SendDebug(__FUNCTION__, 'Found ' . count($ids) . ' instance(s) for Kincony module', 0);
        }

        if (!is_array($ids) || count($ids) === 0) {
            $this->SendDebug(__FUNCTION__, 'No Kincony instances found', 0);
            return [];
        }

        // Build RS90 index once, so we can show the correct "Selected" checkbox state.
        $rs90Index = $this->BuildKinconyRs90DeviceIndex();

        // Sort by instance name
        usort($ids, function (int $a, int $b): int {
            return strcasecmp(IPS_GetName($a), IPS_GetName($b));
        });

        $rows = [];
        foreach ($ids as $instId) {
            $instId = (int)$instId;
            if ($instId <= 0 || !IPS_InstanceExists($instId)) {
                continue;
            }

            $name = IPS_GetName($instId);
            $this->SendDebug(__FUNCTION__, 'Processing InstanceID=' . $instId . ' Name=' . $name, 0);

            // Deterministic Kincony device type detection:
            // Kincony stores the type in the instance property "deviceType" (string), e.g. "IR" or "RF".
            $deviceType = 'Unknown';
            $rawType = '';

            try {
                $rawType = (string)IPS_GetProperty($instId, 'deviceType');
            } catch (Throwable $e) {
                $this->SendDebug(__FUNCTION__, 'IPS_GetProperty(deviceType) threw for InstanceID=' . $instId . ': ' . $e->getMessage(), 0);
                $rawType = '';
            }

            $norm = strtoupper(trim($rawType));
            if ($norm === 'IR' || $norm === 'RF') {
                $deviceType = $norm;
            } else {
                $this->SendDebug(__FUNCTION__, 'deviceType missing/unexpected. raw=' . $rawType, 0);
            }

            $this->SendDebug(__FUNCTION__, 'Read deviceType property for InstanceID=' . $instId . ': raw=' . $rawType . ' normalized=' . $norm . ' => DeviceType=' . $deviceType, 0);

            $existsOnRs90 = isset($rs90Index[$instId]) && is_array($rs90Index[$instId]) && count($rs90Index[$instId]) > 0;

            $rows[] = [
                'Selected'   => $existsOnRs90,
                'Name'       => $name,
                'Object_ID'  => $instId,
                'DeviceType' => $deviceType
            ];
        }

        $this->SendDebug(__FUNCTION__, 'Returning ' . count($rows) . ' Kincony row(s) for Wizard', 0);
        return $rows;
    }

    /**
     * Builds values array for the Kincony list in the Wizard panel.
     * If there are pending (not applied) changes, we prefer the stored property values.
     */
    private function BuildKinconyWizardListValues(bool $useStoredValues): array
    {
        $this->SendDebug(__FUNCTION__, 'Called with useStoredValues=' . ($useStoredValues ? 'true' : 'false'), 0);
        if ($useStoredValues) {
            $rawString = $this->ReadPropertyString('KinconyWizardDevices');
            $this->SendDebug(__FUNCTION__, 'Reading stored KinconyWizardDevices property (length=' . strlen($rawString) . ')', 0);

            $raw = json_decode($rawString, true);
            if (!is_array($raw)) {
                $this->SendDebug(__FUNCTION__, 'Stored property invalid JSON or not array', 0);
                return [];
            }

            $this->SendDebug(__FUNCTION__, 'Returning ' . count($raw) . ' stored Kincony row(s)', 0);
            return $raw;
        }

        $rows = $this->GetKinconyDeviceInstances();
        $this->SendDebug(__FUNCTION__, 'Returning ' . count($rows) . ' freshly scanned Kincony row(s)', 0);
        return $rows;
    }



    public function OnEdit(): void
    {

        $this->SendDebug(__FUNCTION__, "started", 0);
    }

    public function AddDevice(): void
    {

        $this->SendDebug(__FUNCTION__, "started", 0);
    }

    public function AddCommand(string $Method): void
    {

        $this->SendDebug(__FUNCTION__, "Method= $Method", 0);
    }

    /**
     * Build an index of RS90 Custom URL devices that belong to Kincony instances.
     * Key: instance_id (int) -> Value: array of device IDs (string[])
     * Matching rule: any command URL contains device_category=kinconydevice and instance_id=<id>
     */
    private function BuildKinconyRs90DeviceIndex(): array
    {
        // Goal: build an index InstanceID => [RS90DeviceId, ...]
        // Optimized approach:
        // 1) Use the stored configuration tree (IPDevicesTree) to identify already-known Kincony devices WITHOUT calling RS90.
        // 2) Fetch RS90 device list once.
        // 3) Only for RS90 devices that are NOT already known in the tree, fetch commands and detect Kincony markers.

        $index = [];

        // --- Step 1: Build index from stored tree (no RS90 calls) ---
        // We try to fully resolve InstanceID -> RS90 DeviceID using the tree's parent relationships:
        // - Device rows:   id=<rowId>, Type=Device,  RemoteID=<RS90 device id>
        // - Command rows:  parent=<deviceRowId>, Type=Command, URL contains instance_id & device_category

        $knownRs90DeviceIds = [];
        $rowIdToDeviceRemoteId = []; // treeRowId(int) => rs90DeviceId(string)

        $treeRaw = $this->ReadPropertyString('IPDevicesTree');
        $tree = json_decode($treeRaw, true);

        if (is_array($tree)) {
            // 1) Collect device row mappings and known RS90 device ids
            foreach ($tree as $row) {
                if (!is_array($row)) {
                    continue;
                }

                if (isset($row['Type']) && (string)$row['Type'] === 'Device') {
                    $treeRowId = isset($row['id']) ? (int)$row['id'] : 0;
                    $rid = '';
                    if (isset($row['RemoteID']) && is_string($row['RemoteID'])) {
                        $rid = trim((string)$row['RemoteID']);
                    }

                    if ($rid !== '') {
                        $knownRs90DeviceIds[$rid] = true;
                    }
                    if ($treeRowId > 0 && $rid !== '') {
                        $rowIdToDeviceRemoteId[$treeRowId] = $rid;
                    }
                }
            }

            // 2) Detect Kincony marker from command rows and resolve the RS90 device id via parent
            foreach ($tree as $row) {
                if (!is_array($row)) {
                    continue;
                }

                if (!isset($row['Type']) || (string)$row['Type'] !== 'Command') {
                    continue;
                }

                $url = '';
                if (isset($row['URL']) && is_string($row['URL'])) {
                    $url = trim((string)$row['URL']);
                }
                if ($url === '') {
                    continue;
                }

                $params = $this->ParseUrlQueryParams($url);
                $cat = isset($params['device_category']) ? (string)$params['device_category'] : '';
                if ($cat !== 'kinconydevice') {
                    continue;
                }

                $iid = isset($params['instance_id']) ? (int)$params['instance_id'] : 0;
                if ($iid <= 0) {
                    continue;
                }

                $parentRowId = isset($row['parent']) ? (int)$row['parent'] : 0;
                $deviceIdFromTree = ($parentRowId > 0 && isset($rowIdToDeviceRemoteId[$parentRowId])) ? $rowIdToDeviceRemoteId[$parentRowId] : '';

                if (!isset($index[$iid])) {
                    $index[$iid] = [];
                }

                if ($deviceIdFromTree !== '' && !in_array($deviceIdFromTree, $index[$iid], true)) {
                    $index[$iid][] = $deviceIdFromTree;
                    $this->SendDebug(__FUNCTION__, 'Tree resolved Kincony InstanceID=' . $iid . ' -> RS90DeviceID=' . $deviceIdFromTree, 0);
                } else {
                    // We found a Kincony marker but cannot resolve the device id from the tree.
                    // This can happen if the tree structure was edited manually or missing parent info.
                    $this->SendDebug(__FUNCTION__, 'Tree detected kincony marker for InstanceID=' . $iid . ' (device id not resolved from tree; parent=' . $parentRowId . ')', 0);
                }
            }
        } else {
            $this->SendDebug(__FUNCTION__, 'IPDevicesTree property invalid or empty; falling back to RS90 scan only', 0);
        }

        $this->SendDebug(
            __FUNCTION__,
            'Tree scan: known RS90 device ids=' . count($knownRs90DeviceIds) . ' | resolved kincony instance(s)=' . count($index),
            0
        );

        // --- Step 2: Fetch RS90 device list once ---
        $devices = $this->GetCustomURLDevices();
        $this->SendDebug(__FUNCTION__, 'RS90 CustomURLDevices count=' . (is_array($devices) ? count($devices) : 0), 0);

        if (!is_array($devices) || count($devices) === 0) {
            $this->SendDebug(__FUNCTION__, 'No RS90 devices returned; returning tree-derived index only', 0);
            return $index;
        }

        // --- Step 3: Only scan devices not already known in the tree ---
        $scanned = 0;
        $matched = 0;

        foreach ($devices as $dev) {
            if (!is_array($dev)) {
                continue;
            }

            $devId = isset($dev['id']) ? (string)$dev['id'] : (isset($dev['ID']) ? (string)$dev['ID'] : '');
            $devId = trim($devId);
            if ($devId === '') {
                continue;
            }

            // If already present in the tree, do not fetch commands again.
            if (isset($knownRs90DeviceIds[$devId])) {
                continue;
            }

            $scanned++;

            // Fetch commands/controls for the RS90 device (expensive call -> only for unknown devices)
            $commands = $this->GetDeviceCommands($devId);
            if (!is_array($commands) || count($commands) === 0) {
                continue;
            }

            foreach ($commands as $c) {
                if (!is_array($c)) {
                    continue;
                }

                // RS90 returns commands as "controls" with referenceData.url
                $url = '';
                if (isset($c['referenceData']) && is_array($c['referenceData'])) {
                    $ref = $c['referenceData'];
                    if (isset($ref['url']) && is_string($ref['url'])) {
                        $url = trim((string)$ref['url']);
                    }
                }

                // Fallbacks
                if ($url === '' && isset($c['url']) && is_string($c['url'])) {
                    $url = trim((string)$c['url']);
                }
                if ($url === '' && isset($c['URL']) && is_string($c['URL'])) {
                    $url = trim((string)$c['URL']);
                }

                if ($url === '') {
                    continue;
                }

                $params = $this->ParseUrlQueryParams($url);
                $cat = isset($params['device_category']) ? (string)$params['device_category'] : '';
                if ($cat !== 'kinconydevice') {
                    continue;
                }

                $iid = isset($params['instance_id']) ? (int)$params['instance_id'] : 0;
                if ($iid <= 0) {
                    continue;
                }

                if (!isset($index[$iid])) {
                    $index[$iid] = [];
                }

                if (!in_array($devId, $index[$iid], true)) {
                    $index[$iid][] = $devId;
                }

                $matched++;

                // Once matched, stop scanning further commands for this device
                break;
            }
        }

        $this->SendDebug(
            __FUNCTION__,
            'Indexed Kincony RS90 devices for ' . count($index) . ' instance(s) | RS90 command fetches=' . $scanned . ' | new matches=' . $matched,
            0
        );

        return $index;
    }

    /**
     * Extract query params from a URL string.
     */
    private function ParseUrlQueryParams(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return [];
        }

        $query = '';
        $parts = @parse_url($url);
        if (is_array($parts) && isset($parts['query'])) {
            $query = (string)$parts['query'];
        } else {
            // If parse_url fails, try manual split
            $pos = strpos($url, '?');
            if ($pos !== false) {
                $query = substr($url, $pos + 1);
            }
        }

        $params = [];
        if ($query !== '') {
            parse_str($query, $params);
        }
        return is_array($params) ? $params : [];
    }

    /**
     * Find all existing RS90 Custom URL devices that belong to a Kincony instance.
     * A device belongs to the instance if ANY command URL contains:
     *   device_category=kinconydevice AND instance_id=<instanceId>
     */
    private function FindExistingKinconyCustomUrlDevices(int $instanceId): array
    {
        $this->SendDebug(__FUNCTION__, 'START for InstanceID=' . $instanceId, 0);

        $devices = $this->GetCustomURLDevices();
        $this->SendDebug(__FUNCTION__, 'CustomURLDevices count=' . (is_array($devices) ? count($devices) : 0), 0);

        $matches = [];

        foreach ($devices as $dev) {
            if (!is_array($dev)) {
                continue;
            }

            $devId = isset($dev['id']) ? (string)$dev['id'] : (isset($dev['ID']) ? (string)$dev['ID'] : '');
            if ($devId === '') {
                continue;
            }

            $this->SendDebug(__FUNCTION__, 'Checking RS90 DeviceID=' . $devId, 0);

            // Commands must be fetched per device
            $commands = $this->GetDeviceCommands($devId);
            $this->SendDebug(__FUNCTION__, 'DeviceID=' . $devId . ' commands count=' . (is_array($commands) ? count($commands) : 0), 0);

            if (!is_array($commands) || count($commands) === 0) {
                continue;
            }

            foreach ($commands as $c) {
                if (!is_array($c)) {
                    continue;
                }

                $url = '';

                // RS90 returns commands as "controls" with referenceData
                if (isset($c['referenceData']) && is_array($c['referenceData'])) {
                    $ref = $c['referenceData'];
                    $this->SendDebug(__FUNCTION__, 'DeviceID=' . $devId . ' referenceData keys=' . json_encode(array_keys($ref)), 0);

                    if (isset($ref['url']) && is_string($ref['url'])) {
                        $url = (string)$ref['url'];
                    }
                }

                // Fallback: some implementations may return url at top-level
                if ($url === '' && isset($c['url']) && is_string($c['url'])) {
                    $url = (string)$c['url'];
                }
                if ($url === '' && isset($c['URL']) && is_string($c['URL'])) {
                    $url = (string)$c['URL'];
                }

                if ($url === '') {
                    continue;
                }

                $this->SendDebug(__FUNCTION__, 'DeviceID=' . $devId . ' URL=' . $url, 0);

                $params = $this->ParseUrlQueryParams($url);
                $this->SendDebug(__FUNCTION__, 'DeviceID=' . $devId . ' ParsedParams=' . json_encode($params), 0);

                $cat = isset($params['device_category']) ? (string)$params['device_category'] : '';
                $iid = isset($params['instance_id']) ? (string)$params['instance_id'] : '';

                $this->SendDebug(__FUNCTION__, 'DeviceID=' . $devId . ' device_category=' . $cat . ' instance_id=' . $iid, 0);

                if ($cat === 'kinconydevice' && (int)$iid === $instanceId) {
                    $this->SendDebug(__FUNCTION__, 'MATCH found for InstanceID=' . $instanceId . ' on DeviceID=' . $devId, 0);
                    $matches[] = $dev;
                    break; // device match confirmed
                }
            }
        }

        $this->SendDebug(__FUNCTION__, 'END InstanceID=' . $instanceId . ' matches=' . count($matches), 0);
        return $matches;
    }

    /**
     * Ensure we have at most one RS90 device for a Kincony instance.
     * If duplicates exist, keep the first and delete the rest.
     * Returns the kept device id (or empty string if none exists).
     */
    private function EnsureSingleKinconyCustomUrlDevice(int $instanceId): string
    {
        $matches = $this->FindExistingKinconyCustomUrlDevices($instanceId);
        if (count($matches) === 0) {
            return '';
        }

        $keep = $matches[0];
        $keepId = isset($keep['id']) ? (string)$keep['id'] : (isset($keep['ID']) ? (string)$keep['ID'] : '');

        // Delete duplicates
        if (count($matches) > 1) {
            $this->SendDebug(__FUNCTION__, 'Duplicate RS90 devices detected for InstanceID=' . $instanceId . '. Keeping=' . $keepId . ' deleting=' . (count($matches) - 1), 0);

            for ($i = 1; $i < count($matches); $i++) {
                $dup = $matches[$i];
                $dupId = isset($dup['id']) ? (string)$dup['id'] : (isset($dup['ID']) ? (string)$dup['ID'] : '');
                if ($dupId === '') {
                    continue;
                }
                $this->SendDebug(__FUNCTION__, 'Deleting duplicate RS90 device id=' . $dupId . ' for InstanceID=' . $instanceId, 0);
                $delResp = $this->RemoveCustomURLDevice($dupId);
                $this->SendDebug(__FUNCTION__ . ' DeleteResponse', json_encode($delResp), 0);
            }
        }

        return $keepId;
    }

    public function OnMethodChanged(string $Method): void
    {
        $m = strtoupper(trim($Method));

        // Regeln:
        // GET/POST  -> Name + URL sichtbar, IP/Command aus
        // TELNET/ADB -> Name + IP + Command sichtbar, URL aus
        $showUrl   = in_array($m, ['GET', 'POST'], true);
        $showIpCmd = in_array($m, ['TELNET', 'ADB'], true);

        $this->UpdateFormField('IP',      'visible', $showIpCmd);
        $this->UpdateFormField('Command', 'visible', $showIpCmd);
        $this->UpdateFormField('URL',     'visible', $showUrl);


        $this->SendDebug(__FUNCTION__, "Method=$Method", 0);
    }

    /**
     * Wendet Variablenersetzung auf ein Command-Row-Array an, bevor du es an den Splitter/Server schickst.
     * Erwartet Keys: Method, IP, Command, URL (wie bei dir im Tree).
     */
    private function ApplyTemplatesToCommandRow(array $row): array
    {
        $vars = $this->GetTemplateVariables();

        foreach (['IP', 'Command', 'URL'] as $key) {
            if (isset($row[$key]) && is_string($row[$key])) {
                $row[$key] = $this->ReplacePlaceholders($row[$key], $vars);
            }
        }

        return $row;
    }

    public function PrefillTokenFromSplitter(): void
    {
        $this->SendDebug(__FUNCTION__, 'Requesting Token from Splitter', 0);

        $response = $this->SendToSplitter([
            'Command' => 'GetToken'
        ]);

        if (!is_array($response) || !isset($response['Token'])) {
            $this->SendDebug(__FUNCTION__, 'No valid Token response', 0);
            return;
        }

        $token = (string)$response['Token'];

        if ($token === '') {
            $this->SendDebug(__FUNCTION__, 'Token empty', 0);
            return;
        }

        $this->UpsertTemplateVariable('Token', $token);
    }

    /**
     * Liefert die IP-Symcon Connect URL (falls Connect-Modul vorhanden/aktiv).
     */
    private function GetConnectURL(): string
    {
        $ids = IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}');
        if (!is_array($ids) || count($ids) === 0) {
            $this->SendDebug(__FUNCTION__, 'Connect instance not found', 0);
            return '';
        }

        $instID = (int)$ids[0];

        try {
            if (IPS_GetKernelVersion() >= 5.2) {
                $url = CC_GetConnectURL($instID);
            } else {
                $url = CC_GetUrl($instID);
            }
        } catch (Throwable $e) {
            $this->SendDebug(__FUNCTION__, 'Exception: ' . $e->getMessage(), 0);
            return '';
        }

        return is_string($url) ? trim($url) : '';
    }

    /**
     * Returns the prefilled Symcon Connect Cantata webhook URL if Symcon Connect is available.
     * Returns empty string if no usable Connect URL exists.
     */
    private function GetPrefillSymconConnectCantataURL(): string
    {
        $connectUrl = $this->GetConnectURL();
        if ($connectUrl === '') {
            return '';
        }

        $connectUrl = trim($connectUrl);
        if ($connectUrl === '') {
            return '';
        }

        // Only accept proper HTTPS Connect URLs
        if (strpos($connectUrl, 'https://') !== 0) {
            $this->SendDebug(__FUNCTION__, 'Connect URL does not start with https:// : ' . $connectUrl, 0);
            return '';
        }

        // Normalize: ensure no trailing slash
        $connectUrl = rtrim($connectUrl, '/');

        return $connectUrl . '/hook/cantata?command=';
    }

    /**
     * Ermittelt eine sinnvolle lokale Host-IP von IP-Symcon (nicht die Console-Client-IP).
     */
    private function GetHostIP(): string
    {
        $network = Sys_GetNetworkInfo();
        if (!is_array($network) || empty($network)) {
            $this->SendDebug(__FUNCTION__, 'Sys_GetNetworkInfo returned empty', 0);
            return '';
        }

        $candidates = [];
        foreach ($network as $device) {
            if (!is_array($device)) {
                continue;
            }
            $ip = isset($device['IP']) ? (string)$device['IP'] : '';
            $ip = trim($ip);
            if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1') {
                continue;
            }
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            $candidates[] = $ip;
        }

        if (empty($candidates)) {
            $this->SendDebug(__FUNCTION__, 'No valid IP candidates found', 0);
            return '';
        }

        // Prefer private IPv4 ranges (typical LAN)
        foreach ($candidates as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                if (preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $ip)) {
                    return $ip;
                }
            }
        }

        // Otherwise take first candidate
        return (string)$candidates[0];
    }

    public function PrefillSymconCantataURL(): void
    {
        $this->SendDebug(__FUNCTION__, 'Detecting local Symcon IP via Sys_GetNetworkInfo()', 0);

        $host = $this->GetHostIP();
        if ($host === '') {
            $this->SendDebug(__FUNCTION__, 'Could not determine Symcon host IP', 0);
            return;
        }

        $url = "http://{$host}:3777/hook/cantata?command=";

        $this->SendDebug(__FUNCTION__, 'Prefill URL: ' . $url, 0);
        $this->UpsertTemplateVariable('SymconCantata', $url);
    }

    public function PrefillSymconConnectCantataURL(): void
    {
        $this->SendDebug(__FUNCTION__, 'Detecting Symcon Connect URL', 0);

        $url = $this->GetPrefillSymconConnectCantataURL();
        if ($url === '') {
            $this->SendDebug(__FUNCTION__, 'Connect URL not available', 0);
            return;
        }

        $this->SendDebug(__FUNCTION__, 'Prefill Connect URL: ' . $url, 0);
        $this->UpsertTemplateVariable('SymconConnectCantata', $url);
    }

    public function SymconWizard(int $instanceID, int $variableID): string
    {
        $this->SendDebug(__FUNCTION__, 'Wizard selection received', 0);
        $this->SendDebug(__FUNCTION__, 'InstanceID=' . $instanceID . ' VariableID=' . $variableID, 0);

        $instName = '';
        $varName  = '';

        if ($instanceID > 0 && IPS_InstanceExists($instanceID)) {
            $inst = IPS_GetInstance($instanceID);
            $instName = IPS_GetName($instanceID);
            $this->SendDebug(__FUNCTION__, 'Instance=' . json_encode($inst), 0);
        }

        if ($variableID > 0 && IPS_VariableExists($variableID)) {
            $varName = IPS_GetName($variableID);
            $var = IPS_GetVariable($variableID);

            $this->SendDebug(__FUNCTION__, 'Variable=' . json_encode($var), 0);

            $profileName = $var['VariableProfile'] ?? $var['VariableCustomProfile'] ?? '';

            if ($profileName !== '' && IPS_VariableProfileExists($profileName)) {
                $profile = IPS_GetVariableProfile($profileName);

                if (isset($profile['Associations']) && is_array($profile['Associations'])) {
                    foreach ($profile['Associations'] as $assoc) {
                        $this->SendDebug(
                            __FUNCTION__,
                            'Association: Value=' . $assoc['Value'] . ' Name=' . $assoc['Name'],
                            0
                        );
                    }
                }
            }
        }

        // Close popup and show confirmation dialog
        return 'MESSAGE:Wizard OK. Instance=' . $instName . ' (' . $instanceID . '), Variable=' . $varName . ' (' . $variableID . ')';
    }

    public function StoreCustomURLDevice(array $devicePayload): array
    {
        $this->SendDebug(__FUNCTION__, 'Sending StoreCustomURLDevice to Splitter', 0);
        $this->SendDebug(__FUNCTION__ . ' Payload', json_encode($devicePayload), 0);

        $response = $this->SendToSplitter([
            'Command' => 'StoreCustomURLDevice',
            'Device'  => $devicePayload
        ]);

        if (!is_array($response)) {
            $this->SendDebug(__FUNCTION__, 'Invalid response from Splitter', 0);
            return [
                'Success' => false,
                'Message' => 'Invalid response from Splitter',
                'Data'    => null
            ];
        }

        $this->SendDebug(__FUNCTION__ . ' Response', json_encode($response), 0);

        return $response;
    }

    public function RemoveCustomURLDevice(string $deviceId): array
    {
        $this->SendDebug(__FUNCTION__, 'Request remove DeviceID=' . $deviceId, 0);

        $response = $this->SendToSplitter([
            'Command'  => 'RemoveCustomURLDevice',
            'DeviceID' => $deviceId
        ]);

        if (!is_array($response)) {
            return [
                'Success' => false,
                'Message' => 'Invalid response from Splitter',
                'Data'    => null
            ];
        }

        return $response;
    }

    public function StoreCurrentTreeToSplitter(): array
    {
        $this->SendDebug(__FUNCTION__, 'Preparing payload from IPDevicesTree property', 0);

        $tree = json_decode($this->ReadPropertyString('IPDevicesTree'), true);
        if (!is_array($tree) || empty($tree)) {
            $this->SendDebug(__FUNCTION__, 'IPDevicesTree empty or invalid', 0);
            return [
                'Success' => false,
                'Message' => 'IPDevicesTree empty or invalid',
                'Data'    => null
            ];
        }

        // Expecting one root device for test purposes
        // You can later extend this to iterate over multiple devices
        $device = null;
        foreach ($tree as $row) {
            if (isset($row['Type']) && $row['Type'] === 'Device') {
                $device = [
                    'id'   => $row['RemoteID'] ?? '',
                    'name' => $row['Name'] ?? '',
                    'icon' => 'custom-urls.png',
                    'urls' => []
                ];
                break;
            }
        }

        if (!is_array($device)) {
            $this->SendDebug(__FUNCTION__, 'No Device row found in tree', 0);
            return [
                'Success' => false,
                'Message' => 'No Device row found in tree',
                'Data'    => null
            ];
        }

        // Collect commands belonging to this device
        foreach ($tree as $row) {
            if (!isset($row['Type']) || $row['Type'] !== 'Command') {
                continue;
            }

            $commandRow = $this->ApplyTemplatesToCommandRow($row);

            $urlEntry = [
                'id'     => $commandRow['RemoteID'] ?? '',
                'name'   => $commandRow['Name'] ?? '',
                'method' => $commandRow['Method'] ?? '',
                'url'    => $commandRow['URL'] ?? ''
            ];

            $device['urls'][] = $urlEntry;
        }

        $this->SendDebug(__FUNCTION__ . ' BuiltDevicePayload', json_encode($device), 0);

        return $this->StoreCustomURLDevice($device);
    }

    private function UpsertTemplateVariable(string $name, string $value): void
    {
        // IMPORTANT:
        // We do NOT write directly into the property here.
        // Instead we update the visible List field so the user still needs to click "Änderungen übernehmen".

        $raw = json_decode($this->ReadPropertyString('TemplateVariables'), true);
        if (!is_array($raw)) {
            $raw = [];
        }

        // Store display name with braces in the UI list (e.g. {{Token}})
        $key = $name;
        if (preg_match('/^\{\{([A-Za-z0-9_\-\.]+)\}\}$/', $name, $m)) {
            $key = $m[1];
        }
        $displayName = '{{' . $key . '}}';

        $found = false;
        foreach ($raw as &$row) {
            if (!isset($row['Name'])) {
                continue;
            }

            $existingRaw = trim((string)$row['Name']);
            $existingKey = $existingRaw;
            if (preg_match('/^\{\{([A-Za-z0-9_\-\.]+)\}\}$/', $existingRaw, $m)) {
                $existingKey = $m[1];
            }

            if ($existingKey === $key) {
                $row['Name']  = $displayName; // ensure consistent display
                $row['Value'] = $value;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $raw[] = [
                'Name'  => $displayName,
                'Value' => $value
            ];
        }

        // Update the List values in the configuration form.
        // This will mark the instance as "changed" and the user can decide when to apply.
        $json = json_encode($raw);
        if ($json === false) {
            $this->SendDebug(__FUNCTION__, 'json_encode failed: ' . json_last_error_msg(), 0);
            $this->SendDebug(__FUNCTION__ . ' Raw', print_r($raw, true), 0);
            return;
        }

        // Extra debug to pinpoint Symcon conversion issues
        $this->SendDebug(__FUNCTION__, 'Prepared JSON type=' . gettype($json) . ' size=' . strlen($json), 0);
        $this->SendDebug(__FUNCTION__ . ' PreparedJSON', $json, 0);

        try {
            // IMPORTANT: UpdateFormField expects a JSON string for complex properties like List.values
            $this->UpdateFormField('TemplateVariables', 'values', (string)$json);
            $this->SendDebug(__FUNCTION__, 'UpdateFormField TemplateVariables.values applied', 0);
        } catch (Throwable $e) {
            $this->SendDebug(__FUNCTION__, 'UpdateFormField exception: ' . $e->getMessage(), 0);
            $this->SendDebug(__FUNCTION__, $e->getTraceAsString(), 0);
        }

        $this->SendDebug(__FUNCTION__, "Template variable '{$displayName}' prepared (pending apply): '{$value}'", 0);
    }

    /**
     * Returns the TemplateVariables list for the form, enriched with predefined rows.
     * Existing property values win. Optional values are only added when available.
     */
    private function BuildTemplateVariablesListValues(): array
    {
        $raw = json_decode($this->ReadPropertyString('TemplateVariables'), true);
        if (!is_array($raw)) {
            $raw = [];
        }

        $rowsByKey = [];

        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rawName = isset($row['Name']) ? trim((string)$row['Name']) : '';
            if ($rawName === '') {
                continue;
            }

            $key = $rawName;
            if (preg_match('/^\{\{([A-Za-z0-9_\-\.]+)\}\}$/', $rawName, $m)) {
                $key = $m[1];
            }

            if (!preg_match('/^[A-Za-z0-9_\-\.]+$/', $key)) {
                continue;
            }

            $rowsByKey[$key] = [
                'Name'  => '{{' . $key . '}}',
                'Value' => isset($row['Value']) ? (string)$row['Value'] : ''
            ];
        }

        // Optional predefined variable: only add if Symcon Connect URL is really available.
        $connectCantataUrl = $this->GetPrefillSymconConnectCantataURL();
        if ($connectCantataUrl !== '' && !isset($rowsByKey['SymconConnectCantata'])) {
            $rowsByKey['SymconConnectCantata'] = [
                'Name'  => '{{SymconConnectCantata}}',
                'Value' => $connectCantataUrl
            ];
        }

        // Stable ordering for UI
        ksort($rowsByKey, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($rowsByKey);
    }

    public function UpdateVariableID(int $ID, string $value_name)
    {
        $this->SendDebug('UpdateVariableID', "Instance ID: " . $ID, 0);
        $this->UpdateFormField($value_name, 'variableID', $ID);
    }

    public function UpdateLabelValue(string $Value, int $VariableID, string $formatted_name)
    {
        // Debugging: Eingabewerte anzeigen
        $this->SendDebug('UpdateLabelValue', "Wert: " . $Value . " VariableID: " . $VariableID, 0);

        // Überprüfen, ob die Variable existiert
        if (!IPS_VariableExists($VariableID)) {
            $this->SendDebug('UpdateLabelValue', "Variable mit ID $VariableID existiert nicht.", 0);
            return;
        }

        // Variablenprofil ermitteln
        $variable = IPS_GetVariable($VariableID);
        $profileName = $variable['VariableProfile'] ?? $variable['VariableCustomProfile'];

        if (!$profileName) {
            $this->SendDebug('UpdateLabelValue', "Kein Profil für Variable mit ID $VariableID gefunden.", 0);
            return;
        }

        // Variablenprofil laden
        $profile = IPS_GetVariableProfile($profileName);

        // Standardwert für das Label setzen
        $formattedValue = '';

        // Den passenden Namen aus den Assoziationen suchen
        foreach ($profile['Associations'] as $association) {
            if ($association['Value'] == $Value) {
                $formattedValue = $association['Name'];
                break;
            }
        }

        // Debugging: Formatierten Wert anzeigen
        $this->SendDebug('UpdateLabelValue', "Formatiertes Label für $Value: " . $formattedValue, 0);

        // Feld für den formatierten Namen aktualisieren
        $this->UpdateFormField($formatted_name, 'caption', $formattedValue);

        // Berechnung des Labels für den Rohwert
        $raw_name = str_replace('FormatedValue', 'Value', $formatted_name) . 'Label';

        // Debugging: Berechnetes Feld anzeigen
        $this->SendDebug('UpdateLabelValue', "Rohwert-Feld: " . $raw_name, 0);

        // Feld für den Rohwert aktualisieren
        $this->UpdateFormField($raw_name, 'caption', $Value);
    }

    public function TestKinconyWizardOnEdit(bool $Selected, string $Name, int $Object_ID, string $DeviceType): void
    {
        $this->SendDebug(__FUNCTION__,
            "onEdit: Selected=" . ($Selected ? 'true' : 'false') .
            " ObjectID={$Object_ID} Name={$Name} DeviceType={$DeviceType}",
            0
        );
    }

    /**
     * Syncs the Kincony Wizard selection (property KinconyWizardDevices) with the RS90.
     * - If Selected=true and no RS90 device exists -> create/update the RS90 Custom URL device.
     * - If Selected=false and an RS90 device exists -> delete it (and clean duplicates).
     *
     * IMPORTANT: This runs in ApplyChanges() only, so we do not write to RS90 before the user commits.
     */
    private function SyncKinconyWizardSelectionToRs90(): void
    {
        $this->SendDebug(__FUNCTION__, 'Starting sync (ApplyChanges)', 0);

        $rows = json_decode($this->ReadPropertyString('KinconyWizardDevices'), true);
        if (!is_array($rows)) {
            $this->SendDebug(__FUNCTION__, 'KinconyWizardDevices property is not valid JSON/array', 0);
            return;
        }

        // Current RS90 state
        $rs90Index = $this->BuildKinconyRs90DeviceIndex();

        // 1) Base URL: prefer TemplateVariable {{SymconCantata}}, else fallback to local IP
        $vars = $this->GetTemplateVariables();
        $baseUrl = '';
        if (isset($vars['SymconCantata']) && is_string($vars['SymconCantata']) && trim($vars['SymconCantata']) !== '') {
            $baseUrl = trim((string)$vars['SymconCantata']);
        } else {
            $host = $this->GetHostIP();
            if ($host !== '') {
                $baseUrl = "http://{$host}:3777/hook/cantata?command=";
            }
        }
        if ($baseUrl === '') {
            $this->SendDebug(__FUNCTION__, 'No base URL available (SymconCantata missing and host IP not found).', 0);
            return;
        }

        // Normalize: make sure we have ?command= in the URL base
        if (!str_contains($baseUrl, '?command=')) {
            $baseUrl = rtrim($baseUrl, '?&');
            if (!str_contains($baseUrl, '?')) {
                $baseUrl .= '?command=';
            } else {
                $baseUrl .= '&command=';
            }
        }

        // 2) Token from Splitter
        $token = '';
        $tokenResp = $this->SendToSplitter(['Command' => 'GetToken']);
        if (is_array($tokenResp) && isset($tokenResp['Token']) && is_string($tokenResp['Token'])) {
            $token = trim($tokenResp['Token']);
        }
        if ($token === '') {
            $this->SendDebug(__FUNCTION__, 'Token from Splitter is empty/invalid. Aborting sync.', 0);
            return;
        }

        $createdOrUpdated = 0;
        $deleted = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $selected = isset($row['Selected']) ? (bool)$row['Selected'] : false;
            $instanceId = 0;
            if (isset($row['Object_ID'])) {
                $instanceId = (int)$row['Object_ID'];
            } elseif (isset($row['ObjectID'])) {
                // Backwards compatibility
                $instanceId = (int)$row['ObjectID'];
            }
            $name = isset($row['Name']) ? (string)$row['Name'] : '';

            if ($instanceId <= 0 || !IPS_InstanceExists($instanceId)) {
                $this->SendDebug(__FUNCTION__, 'Skipping row with invalid InstanceID=' . $instanceId, 0);
                continue;
            }

            // Determine current RS90 existence from index
            $existsOnRs90 = isset($rs90Index[$instanceId]) && is_array($rs90Index[$instanceId]) && count($rs90Index[$instanceId]) > 0;

            $this->SendDebug(__FUNCTION__, 'Row InstanceID=' . $instanceId . ' Selected=' . ($selected ? 'true' : 'false') . ' ExistsOnRS90=' . ($existsOnRs90 ? 'true' : 'false'), 0);

            // --- Unchecked: delete on RS90 (and clean duplicates) ---
            if (!$selected) {
                if ($existsOnRs90) {
                    // Ensure duplicates are removed first; returns the kept device id (if any)
                    $existingId = $this->EnsureSingleKinconyCustomUrlDevice($instanceId);
                    if ($existingId !== '') {
                        $this->SendDebug(__FUNCTION__, 'Deleting RS90 device DeviceID=' . $existingId . ' for InstanceID=' . $instanceId, 0);
                        $delResp = $this->RemoveCustomURLDevice($existingId);
                        $this->SendDebug(__FUNCTION__ . ' DeleteResponse', json_encode($delResp), 0);
                        $deleted++;
                    }

                    // Safety cleanup: if anything is still left, delete it too
                    $left = $this->FindExistingKinconyCustomUrlDevices($instanceId);
                    if (count($left) > 0) {
                        $this->SendDebug(__FUNCTION__, 'After delete, still found ' . count($left) . ' RS90 device(s) for InstanceID=' . $instanceId . ' -> cleaning up', 0);
                        foreach ($left as $ld) {
                            if (!is_array($ld)) {
                                continue;
                            }
                            $lid = isset($ld['id']) ? (string)$ld['id'] : (isset($ld['ID']) ? (string)$ld['ID'] : '');
                            if ($lid === '') {
                                continue;
                            }
                            $this->SendDebug(__FUNCTION__, 'Deleting remaining RS90 device id=' . $lid . ' for InstanceID=' . $instanceId, 0);
                            $r = $this->RemoveCustomURLDevice($lid);
                            $this->SendDebug(__FUNCTION__ . ' DeleteResponse', json_encode($r), 0);
                            $deleted++;
                        }
                    }
                }
                continue;
            }

            // --- Selected: create/update on RS90 ---
            // 3) Read deviceType deterministically
            $deviceType = '';
            try {
                $deviceType = (string)IPS_GetProperty($instanceId, 'deviceType');
            } catch (Throwable $e) {
                $this->SendDebug(__FUNCTION__, 'IPS_GetProperty(deviceType) threw for InstanceID=' . $instanceId . ': ' . $e->getMessage(), 0);
                $deviceType = '';
            }
            $deviceType = strtoupper(trim($deviceType));
            if ($deviceType !== 'IR' && $deviceType !== 'RF') {
                $this->SendDebug(__FUNCTION__, 'Unexpected deviceType for InstanceID=' . $instanceId . ': ' . $deviceType . ' -> default IR', 0);
                $deviceType = 'IR';
            }

            // 4) Read commands from the correct property
            $propName = ($deviceType === 'RF') ? 'rfCommands' : 'commands';
            $commandsPayload = '';
            try {
                $commandsPayload = (string)IPS_GetProperty($instanceId, $propName);
            } catch (Throwable $e) {
                $this->SendDebug(__FUNCTION__, "IPS_GetProperty({$propName}) threw for InstanceID={$instanceId}: " . $e->getMessage(), 0);
                $commandsPayload = '';
            }

            $this->SendDebug(__FUNCTION__, "InstanceID={$instanceId} Name={$name} Type={$deviceType} Prop={$propName} CommandsLen=" . strlen($commandsPayload), 0);

            $commandsArr = [];
            if (trim($commandsPayload) !== '') {
                $decoded = json_decode($commandsPayload, true);
                if (is_array($decoded)) {
                    $commandsArr = $decoded;
                } else {
                    $this->SendDebug(__FUNCTION__, "Commands JSON decode failed for InstanceID={$instanceId}: " . json_last_error_msg(), 0);
                }
            }

            // Normalize commands (Kincony property format)
            // Kincony stores commands as JSON array of objects, e.g.
            // [{"CommandName":"on","Command":"...","Repetition":3,"CommandAlias":"ein"}, ...]
            // We use CommandName as the technical key (used in the webhook URL), and CommandAlias as display name on RS90.
            $commands = []; // key => displayName

            foreach ($commandsArr as $cmd) {
                // Simple string list fallback
                if (is_string($cmd)) {
                    $key = trim($cmd);
                    if ($key !== '') {
                        $commands[$key] = $key;
                    }
                    continue;
                }

                if (!is_array($cmd)) {
                    continue;
                }

                // Kincony format
                $key = '';
                if (isset($cmd['CommandName'])) {
                    $key = trim((string)$cmd['CommandName']);
                } elseif (isset($cmd['name'])) {
                    $key = trim((string)$cmd['name']);
                } elseif (isset($cmd['command'])) {
                    // last resort (some modules use 'command' for the name)
                    $key = trim((string)$cmd['command']);
                } elseif (isset($cmd['caption'])) {
                    $key = trim((string)$cmd['caption']);
                }

                if ($key === '') {
                    continue;
                }

                $display = $key;
                if (isset($cmd['CommandAlias'])) {
                    $alias = trim((string)$cmd['CommandAlias']);
                    if ($alias !== '') {
                        $display = $alias;
                    }
                } elseif (isset($cmd['alias'])) {
                    $alias = trim((string)$cmd['alias']);
                    if ($alias !== '') {
                        $display = $alias;
                    }
                }

                $commands[$key] = $display;
            }

            if (empty($commands)) {
                $this->SendDebug(__FUNCTION__, "No commands found for InstanceID={$instanceId} (deviceType={$deviceType})", 0);
                // Helpful extra debug: show first chars of the raw JSON so we see the format immediately
                $this->SendDebug(__FUNCTION__, 'CommandsRawHead=' . substr($commandsPayload, 0, 200), 0);
                continue;
            }

            // Stable ordering for predictable diffs
            ksort($commands, SORT_NATURAL | SORT_FLAG_CASE);

            $deviceName = ($name !== '') ? $name : ('Kincony ' . $instanceId);

            $urls = [];
            foreach ($commands as $cmdKey => $displayName) {
                // IMPORTANT: PHP converts numeric string keys (e.g. "1") to int keys (1).
                // rawurlencode() requires a string, so always cast.
                $cmdKeyStr = (string)$cmdKey;

                // Debug to help diagnose key/type issues
                $this->SendDebug(__FUNCTION__, 'Building URL: cmdKeyType=' . gettype($cmdKey) . ' cmdKey=' . $cmdKeyStr . ' display=' . (string)$displayName, 0);

                $url = $baseUrl
                    . rawurlencode($cmdKeyStr)
                    . '&instance_id=' . $instanceId
                    . '&device_category=kinconydevice'
                    . '&token=' . rawurlencode((string)$token);

                $urls[] = [
                    'id'     => '',
                    'name'   => (string)$displayName,
                    'method' => 'GET',
                    'url'    => $url
                ];
            }

            // Idempotency: if a device exists, update it, else create it.
            $existingId = $this->EnsureSingleKinconyCustomUrlDevice($instanceId);
            if ($existingId !== '') {
                $this->SendDebug(__FUNCTION__, 'Updating existing RS90 device DeviceID=' . $existingId . ' for InstanceID=' . $instanceId, 0);
            } else {
                $this->SendDebug(__FUNCTION__, 'Creating new RS90 device for InstanceID=' . $instanceId, 0);
            }

            $payload = [
                'id'   => $existingId,
                'name' => $deviceName,
                'icon' => 'custom-urls.png',
                'urls' => $urls
            ];

            $this->SendDebug(__FUNCTION__ . ' Payload', json_encode($payload), 0);
            $resp = $this->StoreCustomURLDevice($payload);
            $this->SendDebug(__FUNCTION__ . ' StoreResponse', json_encode($resp), 0);

            $createdOrUpdated++;
        }

        $this->SendDebug(__FUNCTION__, 'Sync finished. created/updated=' . $createdOrUpdated . ' deleted=' . $deleted, 0);
    }



    public function GetConfigurationForm(): string
    {
        $this->SendDebug(__FUNCTION__, 'Konfigurator-Formular wird geladen', 0);

        // IMPORTANT:
        // We want the Tree to reflect the current state on the RS90.
        // However, if the user has unsaved changes in the form, we must NOT overwrite the UI values.
        // Therefore:
        // - If there are pending (not applied) changes -> let Symcon load values from configuration (property)
        // - Otherwise -> load fresh values from RS90 via the Splitter

        $hasPendingChanges = false;
        try {
            // IPS_HasChanges exists in the console context; if not, we just assume "no pending changes"
            if (function_exists('IPS_HasChanges')) {
                $hasPendingChanges = IPS_HasChanges($this->InstanceID);
            }
        } catch (Throwable $e) {
            $hasPendingChanges = false;
        }

        $useStoredValues = $hasPendingChanges;


        // If we have no pending changes, always fetch fresh values from RS90
        $devicesTreeValues = $useStoredValues ? [] : $this->BuildDevicesCommandsTreeValues();
        $kinconyWizardValues = $this->BuildKinconyWizardListValues($useStoredValues);
        $templateVariablesValues = $this->BuildTemplateVariablesListValues();

        $this->SendDebug(__FUNCTION__, 'Tree source=' . ($useStoredValues ? 'configuration(property)' : 'RS90 via splitter'), 0);

        $form = [
            "elements" => [
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
                    'type'    => 'Tree',
                    'name'    => 'IPDevicesTree',
                    'loadValuesFromConfiguration' => $useStoredValues,
                    'caption' => $this->Translate('Custom URL Devices & Commands'),
                    'rowCount' => 0,
                    'add'     => true,
                    'delete'  => true,
                    'sort'    => [
                        'column'    => 'Name',
                        'direction' => 'ascending'
                    ],
                    'columns' => [
                        [
                            'caption' => $this->Translate('Remote ID'),
                            'name'    => 'RemoteID',
                            'width'   => '240px',
                            'save'    => true,
                            'add'     => ''
                        ],
                        [
                            'caption' => $this->Translate('Name'),
                            'name'    => 'Name',
                            'width'   => '300px',
                            'save'    => true,
                            'add'     => '',
                            'edit'    => [
                                'type' => 'ValidationTextBox'
                            ]
                        ],
                        [
                            'caption' => $this->Translate('Type'),
                            'name'    => 'Type',
                            'width'   => '150px',
                            'save'    => true,
                            'add'     => ''
                        ],
                        [
                            'caption' => $this->Translate('Method'),
                            'name'    => 'Method',
                            'width'   => '90px',
                            'save'    => true,
                            'add'     => 'GET',
                            'edit'    => [
                                'type'    => 'Select',
                                'options' => [
                                    ['caption' => 'GET',    'value' => 'GET'],
                                    ['caption' => 'POST',   'value' => 'POST'],
                                    ['caption' => 'TELNET', 'value' => 'TELNET'],
                                    ['caption' => 'ADB',    'value' => 'ADB']
                                ]
                            ]
                        ],
                        [
                            'caption' => $this->Translate('IP'),
                            'name'    => 'IP',
                            'width'   => '190px',
                            'save'    => true,
                            'add'     => '',
                            'edit'    => [
                                'type' => 'ValidationTextBox'
                            ]
                        ],
                        [
                            'caption' => $this->Translate('Command'),
                            'name'    => 'Command',
                            'width'   => '320px',
                            'save'    => true,
                            'add'     => '',
                            'edit'    => [
                                'type' => 'ValidationTextBox'
                            ]
                        ],
                        [
                            'caption' => $this->Translate('URL'),
                            'name'    => 'URL',
                            'width'   => 'auto',
                            'save'    => true,
                            'add'     => '',
                            'edit'    => [
                                'type'     => 'ValidationTextBox',
                                // must start with http:// or https:// and contain at least one more character
                                'validate' => '^https?://.+'
                            ]
                        ]
                    ],
                    'form' => <<<'PHP'
$m = '';

// Debug helper for form-script context (not a module context!)
$__log = function (string $msg): void {
    IPS_LogMessage('CRIPC TreeForm', $msg);
};

// In Symcon list/tree form context, the current row is often an ArrayAccess/obj, not a pure PHP array.
// Therefore do NOT use is_array(). Safest: cast to array and/or access like array.
if (isset($IPDevicesTree)) {
    // Cast to array for debugging/keys and safe access
    $row = (array)$IPDevicesTree;
    $rowKeys = implode(',', array_keys($row));

    // Method can be read either directly from the row or via the $Method variable (if Symcon provides it)
    $mRaw = '';
    if (isset($row['Method'])) {
        $mRaw = (string)$row['Method'];
    } elseif (isset($IPDevicesTree['Method'])) {
        // In case $IPDevicesTree is ArrayAccess
        $mRaw = (string)$IPDevicesTree['Method'];
    } elseif (isset($Method)) {
        $mRaw = (string)$Method;
    }

    $m = strtoupper(trim($mRaw));
    $__log('Open edit form. keys=' . $rowKeys . ' methodRaw=' . $mRaw . ' method=' . $m);
} elseif (isset($Method)) {
    $m = strtoupper(trim((string)$Method));
    $__log('Open edit form. no $IPDevicesTree; method=' . $m);
} else {
    $__log('Open edit form. no $IPDevicesTree and no $Method');
}

// Regeln:
// GET/POST    -> Name + URL sichtbar, IP/Command aus
// TELNET/ADB  -> Name + IP + Command sichtbar, URL aus
$showUrl   = in_array($m, ['GET', 'POST'], true);
$showIpCmd = in_array($m, ['TELNET', 'ADB'], true);

// If method is empty/unknown, show all fields so the user is not stuck with missing inputs.
if (!$showUrl && !$showIpCmd) {
    $__log('Method empty/unknown -> show all fields');
    $showUrl = true;
    $showIpCmd = true;
}

return [
    [
        'type'    => 'ValidationTextBox',
        'name'    => 'Name',
        'caption' => 'Name'
    ],
    [
        'type'    => 'Select',
        'name'    => 'Method',
        'caption' => 'Method',
        'options' => [
            ['caption' => 'GET',    'value' => 'GET'],
            ['caption' => 'POST',   'value' => 'POST'],
            ['caption' => 'TELNET', 'value' => 'TELNET'],
            ['caption' => 'ADB',    'value' => 'ADB']
        ],
        // $Method is the current value of this Select field in Symcon form scripting
        'onChange' => 'CRIPC_OnMethodChanged($id, $Method);'
    ],
    [
        'type'    => 'ValidationTextBox',
        'name'    => 'IP',
        'caption' => 'IP',
        'visible' => $showIpCmd
    ],
    [
        'type'    => 'ValidationTextBox',
        'name'    => 'Command',
        'caption' => 'Befehl',
        'visible' => $showIpCmd
    ],
    [
        'type'     => 'ValidationTextBox',
        'name'     => 'URL',
        'caption'  => 'URL',
        'visible'  => $showUrl,
        // must start with http:// or https://
        'validate' => '^https?://.+'
    ]
];
PHP,
                    // 'onAdd'  => 'CRIPC_AddCommand($id, "GET");',
                    'onEdit' => 'CRIPC_OnEdit($id);',
                    'values' => $devicesTreeValues
                ],
                [
                    'type'  => 'RowLayout',
                    'items' => [
                        [
                            'type'    => 'Button',
                            'caption' => 'Befehle zu bestehenden Gerät hinzufügen',
                            'onClick' => 'CRIPC_AddCommand($id);'
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => 'Neues Gerät hinzufügen',
                            'onClick' => 'CRIPC_AddDevice($id);'
                        ]
                    ]
                ],
                [
                    'type' => 'ExpansionPanel',
                    'caption' => $this->Translate('Variables (Postman style)'),
                    'items' => [
                        [
                            'type'    => 'Label',
                            'caption' => $this->Translate('Define variables here and use them as {{VarName}} in IP/Command/URL fields. Example: BaseURL = http://192.168.55.110:3777')
                        ],
                        [
                            'type'     => 'List',
                            'name'     => 'TemplateVariables',
                            'rowCount' => 6,
                            'add'      => true,
                            'delete'   => true,
                            'columns'  => [
                                [
                                    'caption' => $this->Translate('Name'),
                                    'name'    => 'Name',
                                    'width'   => '220px',
                                    'add'     => '',
                                    'edit'    => [
                                        'type' => 'ValidationTextBox'
                                    ]
                                ],
                                [
                                    'caption' => $this->Translate('Value'),
                                    'name'    => 'Value',
                                    'width'   => 'auto',
                                    'add'     => '',
                                    'edit'    => [
                                        'type' => 'ValidationTextBox'
                                    ]
                                ]
                            ],
                            'values' => $templateVariablesValues
                        ],
                        [
                            'type'  => 'RowLayout',
                            'items' => [
                                [
                                    'type'    => 'Button',
                                    'caption' => 'Token vom Splitter übernehmen',
                                    'onClick' => 'CRIPC_PrefillTokenFromSplitter($id);'
                                ],
                                [
                                    'type'    => 'Button',
                                    'caption' => 'Symcon Cantata URL vorbelegen',
                                    'onClick' => 'CRIPC_PrefillSymconCantataURL($id);'
                                ],
                                [
                                    'type'    => 'Button',
                                    'caption' => 'Symcon Connect Cantata URL vorbelegen',
                                    'onClick' => 'CRIPC_PrefillSymconConnectCantataURL($id);'
                                ]
                            ]
                        ]
                    ]
                ],
                [
                    'type' => 'ExpansionPanel',
                    'caption' => $this->Translate('Symcon Geräte Wizard'),
                    'items' => [
                        [
                            'type'    => 'Label',
                            'caption' => $this->Translate('Wähle eine Instanz und eine Variable aus. Später werden daraus automatisch IP Control Devices & Commands generiert.')
                        ],
                        [
                            'type'    => 'PopupButton',
                            'caption' => $this->Translate('Wizard öffnen'),
                            'popup'   => [
                                'caption' => $this->Translate('Symcon Geräte Wizard'),
                                'closeCaption' => $this->Translate('Schließen'),
                                'items'   => [
                                    [
                                        'type'    => 'SelectInstance',
                                        'name'    => 'WizardInstance',
                                        'caption' => $this->Translate('Geräte Instanz')
                                    ],
                                    [
                                        'type'    => 'SelectVariable',
                                        'name'    => 'WizardVariable',
                                        'caption' => $this->Translate('Variable')
                                    ]
                                ],
                                'buttons' => [
                                    [
                                        'caption' => $this->Translate('Wizard Test'),
                                        'onClick' => 'CRIPC_SymconWizard($id, $WizardInstance, $WizardVariable);'
                                    ]
                                ]
                            ]
                        ],
                        [
                            'type'    => 'Label',
                            'caption' => $this->Translate('Gefundene Kincony Geräte im Objektbaum. Wähle Geräte aus, die als Custom IP Devices auf der RS90 angelegt werden sollen.')
                        ],
                        [
                            'type'     => 'List',
                            'name'     => 'KinconyWizardDevices',
                            'rowCount' => 8,
                            'add'      => false,
                            'delete'   => false,
                            'columns'  => [
                                [
                                    'caption' => $this->Translate('Auswählen'),
                                    'name'    => 'Selected',
                                    'width'   => '120px',
                                    'save'    => true,
                                    'edit'    => [
                                        'type' => 'CheckBox',
                                    ]
                                ],
                                [
                                    'caption' => $this->Translate('Name'),
                                    'name'    => 'Name',
                                    'width'   => '320px',
                                    'save'    => true
                                ],
                                [
                                    'caption' => $this->Translate('Objekt ID'),
                                    'name'    => 'Object_ID',
                                    'width'   => '120px',
                                    'save'    => true
                                ],
                                [
                                    'caption' => $this->Translate('Gerätetyp'),
                                    'name'    => 'DeviceType',
                                    'width'   => '120px',
                                    'save'    => true
                                ]
                            ],
                            'form' => <<<'PHP'
$row = [];
if (isset($KinconyWizardDevices)) {
    $row = (array)$KinconyWizardDevices;
}

$name = $row['Name'] ?? '';
$deviceType = $row['DeviceType'] ?? '';
$selected = $row['Selected'] ?? false;
$object_ID = $row['Object_ID'] ?? 0;
return [
    [
        'type'    => 'CheckBox',
        'name'    => 'Selected',
        'caption' => 'Auswählen'
    ],
    [
        'type'    => 'ValidationTextBox',
        'name'    => 'Name',
        'caption' => 'Name',
        'enabled' => false
    ],
    [
        'type'    => 'ValidationTextBox',
        'name'    => 'DeviceType',
        'caption' => 'Gerätetyp',
        'enabled' => false
    ],
    /*
    [
        'type'    => 'Label',
        'caption' => 'Objekt ID: ' . $object_ID
    ]
    */
];
PHP,
                            'onEdit' => 'CRIPC_TestKinconyWizardOnEdit($id, $KinconyWizardDevices["Selected"], $KinconyWizardDevices["Name"], $KinconyWizardDevices["Object_ID"], $KinconyWizardDevices["DeviceType"]);',
                            'values'  => $kinconyWizardValues
                        ],
                        [
                            'type'  => 'RowLayout',
                            'items' => [
                                [
                                    'type'    => 'Button',
                                    'caption' => $this->Translate('Kincony Geräte neu einlesen'),
                                    'onClick' => 'CRIPC_ReloadKinconyWizard($id);'
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            'status' => [
                ['code' => IS_CREATING, 'icon' => 'inactive', 'caption' => $this->Translate('Device is being created')],
                ['code' => IS_ACTIVE, 'icon' => 'active', 'caption' => $this->Translate('Device connected and active')],
                ['code' => IS_DELETING, 'icon' => 'inactive', 'caption' => $this->Translate('Device is being deleted')],
                ['code' => IS_INACTIVE, 'icon' => 'inactive', 'caption' => $this->Translate('Device inactive')],
                ['code' => IS_NOTCREATED, 'icon' => 'error', 'caption' => $this->Translate('Device not created or error occurred')],
            ]
        ];

        $json = json_encode($form);
        $formSize = is_string($json) ? strlen($json) : -1;
        $this->SendDebug('Form Size', "Größe der Konfigurationsform: " . $formSize . " Bytes", 0);

        if (!is_string($json)) {
            $this->SendDebug('Form JSON Error', 'json_encode failed: ' . json_last_error_msg(), 0);

            // Fallback: Always return valid JSON so the console can render something
            return json_encode([
                'elements' => [
                    [
                        'type'    => 'Label',
                        'caption' => $this->Translate('Fehler: Konfigurationsformular konnte nicht erzeugt werden: ') . json_last_error_msg()
                    ]
                ]
            ]);
        }

        return $json;
    }

}