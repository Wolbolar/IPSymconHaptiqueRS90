<?php

declare(strict_types=1);

class HomeAssistantEntityRepository
{
    private object $module;

    public function __construct(object $module)
    {
        $this->module = $module;
    }

    public function getStates(): array
    {
        $states = array_map(
            static fn(array $entity): array => $entity['state_response'],
            $this->getEntities()
        );

        $this->debug('ENTITY', '📦 HA states serialized', 4, [
            'count' => count($states),
            'entity_ids' => array_column($states, 'entity_id')
        ]);

        return $states;
    }

    public function getState(string $entityId): ?array
    {
        foreach ($this->getEntities() as $entity) {
            if ($entity['entity_id'] === $entityId) {
                return $entity['state_response'];
            }
        }

        return null;
    }

    public function getEntity(string $entityId): ?array
    {
        foreach ($this->getEntities() as $entity) {
            if ($entity['entity_id'] === $entityId) {
                return $entity;
            }
        }

        return null;
    }

    public function getDeviceList(): array
    {
        $devices = [];

        foreach ($this->getEntities() as $entity) {
            $attributes = $entity['state_response']['attributes'] ?? [];
            $targetId = (string)($entity['target_id'] ?? $entity['entity_id']);
            $domain = (string)$entity['domain'];
            $deviceId = $domain . '_' . $targetId;

            if (!isset($devices[$deviceId])) {
                $devices[$deviceId] = [
                    'device_id' => $deviceId,
                    'name' => (string)($attributes['friendly_name'] ?? $entity['entity_id']),
                    'manufacturer' => (string)($attributes['manufacturer'] ?? 'Symcon'),
                    'model' => (string)($attributes['model'] ?? $domain),
                    'entities' => []
                ];
            }

            $devices[$deviceId]['entities'][] = $entity['entity_id'];
        }

        $deviceList = array_values($devices);
        $this->debug('ENTITY', '📦 HA device list serialized', 4, [
            'count' => count($deviceList),
            'device_ids' => array_column($deviceList, 'device_id')
        ]);

        return $deviceList;
    }

    public function getEntities(): array
    {
        $now = date('Y-m-d\TH:i:s.000000P');
        $entities = [];

        foreach ($this->getData('GetLights') as $light) {
            $switchVarID = (int)($light['SwitchVariable'] ?? 0);
            if ($switchVarID <= 0) {
                continue;
            }

            $name = (string)($light['Name'] ?? ('Light ' . $switchVarID));
            $isOn = $this->readBoolState($switchVarID);
            $state = $isOn ? 'on' : 'off';
            $entity = $this->buildEntity(
                'light',
                'light.' . $switchVarID,
                $switchVarID,
                $state,
                array_merge([
                    'friendly_name' => $name,
                    'manufacturer' => (string)($light['Manufacturer'] ?? ''),
                    'model' => (string)($light['Model'] ?? ''),
                ], $this->buildLightAttributes($light, $isOn)),
                $now
            );
            $entity['targets'] = [
                'switch' => $switchVarID,
                'brightness' => (int)($light['BrightnessVariable'] ?? 0),
                'color_temperature' => (int)($light['ColorTemperatureVariable'] ?? 0)
            ];
            $this->debug('ENTITY', '🧭 HA light mapping built', 4, [
                'entity_id' => $entity['entity_id'],
                'name' => $name,
                'targets' => $entity['targets']
            ]);
            $entities[] = $entity;
        }

        foreach ($this->getData('GetSwitches') as $switch) {
            $switchVarID = (int)($switch['SwitchVariable'] ?? 0);
            if ($switchVarID <= 0) {
                continue;
            }

            $name = (string)($switch['Name'] ?? ('Switch ' . $switchVarID));
            $state = $this->readBoolState($switchVarID) ? 'on' : 'off';
            $entities[] = $this->buildEntity(
                'switch',
                'switch.' . $switchVarID,
                $switchVarID,
                $state,
                [
                    'friendly_name' => $name,
                    'manufacturer' => (string)($switch['Manufacturer'] ?? ''),
                    'model' => (string)($switch['Model'] ?? '')
                ],
                $now
            );
        }

        foreach ($this->getData('GetAutomations') as $automation) {
            $scriptID = (int)($automation['ScriptID'] ?? 0);
            if ($scriptID <= 0) {
                continue;
            }

            $name = (string)($automation['Name'] ?? ('Automation ' . $scriptID));
            $entities[] = $this->buildEntity(
                'automation',
                'automation.' . $scriptID,
                $scriptID,
                'on',
                [
                    'friendly_name' => $name,
                    'supported_features' => 0
                ],
                $now
            );
        }

        foreach ($this->getData('GetTemperatureSensors') as $sensor) {
            $varID = (int)($sensor['TemperatureVariable'] ?? 0);
            if ($varID <= 0) {
                continue;
            }

            $entities[] = $this->buildEntity(
                'sensor',
                'sensor.' . $varID,
                $varID,
                (string)$this->readValue($varID),
                [
                    'device_class' => 'temperature',
                    'unit_of_measurement' => "\u{00B0}C",
                    'state_class' => 'measurement',
                    'friendly_name' => (string)($sensor['Name'] ?? ('Temperature ' . $varID))
                ],
                $now
            );
        }

        foreach ($this->getData('GetBatterySensors') as $sensor) {
            $varID = (int)($sensor['BatteryVariable'] ?? 0);
            if ($varID <= 0) {
                continue;
            }

            $entities[] = $this->buildEntity(
                'sensor',
                'sensor.' . $varID,
                $varID,
                (string)$this->readValue($varID),
                [
                    'device_class' => 'battery',
                    'battery_state' => 'normal',
                    'unit_of_measurement' => '%',
                    'state_class' => 'measurement',
                    'friendly_name' => (string)($sensor['Name'] ?? ('Battery ' . $varID))
                ],
                $now
            );
        }

        foreach ($this->getData('GetMotionSensors') as $sensor) {
            $varID = (int)($sensor['MotionVariable'] ?? 0);
            if ($varID <= 0) {
                continue;
            }

            $entities[] = $this->buildEntity(
                'binary_sensor',
                'binary_sensor.' . $varID,
                $varID,
                $this->readBoolState($varID) ? 'on' : 'off',
                [
                    'device_class' => 'motion',
                    'friendly_name' => (string)($sensor['Name'] ?? ('Motion ' . $varID))
                ],
                $now
            );
        }

        foreach ($this->getData('GetIlluminanceSensors') as $sensor) {
            $varID = (int)($sensor['IlluminanceVariable'] ?? 0);
            if ($varID <= 0) {
                continue;
            }

            $entities[] = $this->buildEntity(
                'sensor',
                'sensor.' . $varID,
                $varID,
                (string)$this->readValue($varID),
                [
                    'state_class' => 'measurement',
                    'light_level' => 0,
                    'unit_of_measurement' => 'lx',
                    'device_class' => 'illuminance',
                    'friendly_name' => (string)($sensor['Name'] ?? ('Illuminance ' . $varID))
                ],
                $now
            );
        }

        foreach ($this->getData('GetMediaPlayers') as $mediaPlayer) {
            $controlVarID = (int)($mediaPlayer['ControlVariable'] ?? 0);
            if ($controlVarID <= 0) {
                continue;
            }

            $entities[] = $this->buildEntity(
                'media_player',
                'media_player.' . $controlVarID,
                $controlVarID,
                (string)$this->readValue($controlVarID),
                [
                    'friendly_name' => (string)($mediaPlayer['Name'] ?? ('Media Player ' . $controlVarID)),
                    'supported_features' => 4096,
                    'volume_level' => 0.5
                ],
                $now
            );
        }

        $this->debug('ENTITY', '🧱 HA entities collected', 4, [
            'count' => count($entities),
            'by_domain' => array_count_values(array_map(static fn(array $entity): string => (string)$entity['domain'], $entities)),
            'entity_ids' => array_column($entities, 'entity_id')
        ]);

        return $entities;
    }

    private function buildLightAttributes(array $light, bool $isOn): array
    {
        $brightness = $this->readOptionalInt($light['BrightnessVariable'] ?? null);
        if ($brightness !== null) {
            $brightness = max(0, min(255, $brightness));
        }

        $colorTempKelvin = $this->readOptionalInt($light['ColorTemperatureVariable'] ?? null);
        if ($colorTempKelvin !== null) {
            $colorTempKelvin = max(2000, min(6535, $colorTempKelvin));
        }

        $colorMode = $isOn ? 'color_temp' : null;
        $effectiveColorTempKelvin = $colorTempKelvin ?? 2700;

        return [
            'min_color_temp_kelvin' => 2000,
            'max_color_temp_kelvin' => 6535,
            'supported_color_modes' => [
                'color_temp',
                'xy'
            ],
            'color_mode' => $colorMode,
            'brightness' => $isOn ? ($brightness ?? 255) : null,
            'color_temp_kelvin' => $isOn ? $effectiveColorTempKelvin : null,
            'hs_color' => $isOn ? [28.235, 67.059] : null,
            'rgb_color' => $isOn ? [255, 167, 87] : null,
            'xy_color' => $isOn ? [0.459, 0.41] : null,
            'mode' => 'normal',
            'dynamics' => 'none',
            'supported_features' => 44
        ];
    }

    private function buildEntity(
        string $domain,
        string $entityId,
        int $targetId,
        string $state,
        array $attributes,
        string $now
    ): array {
        return [
            'domain' => $domain,
            'entity_id' => $entityId,
            'target_id' => $targetId,
            'state_response' => [
                'entity_id' => $entityId,
                'state' => $state,
                'attributes' => $attributes,
                'last_changed' => $now,
                'last_reported' => $now,
                'last_updated' => $now,
                'context' => [
                    'id' => 'id_' . str_replace('.', '_', $entityId),
                    'parent_id' => null,
                    'user_id' => null
                ]
            ]
        ];
    }

    private function getData(string $method): array
    {
        if (!method_exists($this->module, 'HA_GetConfiguratorData')) {
            return [];
        }

        $data = $this->module->HA_GetConfiguratorData($method);
        $this->debug('ENTITY', '🔎 Configurator data requested', 5, [
            'method' => $method,
            'count' => is_array($data) ? count($data) : 0
        ]);
        return is_array($data) ? $data : [];
    }

    private function readBoolState(int $variableID): bool
    {
        return (bool)$this->readValue($variableID);
    }

    private function readOptionalInt($variableID): ?int
    {
        $variableID = (int)$variableID;
        if ($variableID <= 0) {
            return null;
        }

        $value = $this->readValue($variableID);
        if (!is_numeric($value)) {
            return null;
        }

        return (int)round((float)$value);
    }

    private function readValue(int $variableID)
    {
        if (function_exists('IPS_VariableExists') && !@IPS_VariableExists($variableID)) {
            $this->debug('ENTITY', '⚠️ Symcon variable missing for HA entity', 3, [
                'variable_id' => $variableID
            ]);
            return 'unknown';
        }

        return @GetValue($variableID);
    }

    private function debug(string $topic, string $message, int $level = 4, $data = ''): void
    {
        if (method_exists($this->module, 'HA_Debug')) {
            $this->module->HA_Debug($topic, $message, $level, $data);
        }
    }
}
