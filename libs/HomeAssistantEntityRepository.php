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

    public function getMediaPlayerCover(string $entityId): ?array
    {
        foreach ($this->getData('GetMediaPlayers') as $mediaPlayer) {
            $controlVarID = (int)($mediaPlayer['ControlVariable'] ?? 0);
            $mediaPlayerObjectID = $this->getConfiguredOrParentInstanceID($mediaPlayer, 'InstanceID', ['ControlVariable'], (int)($mediaPlayer['Mediaplayer_ID'] ?? 0));
            if ($controlVarID <= 0 || $mediaPlayerObjectID <= 0 || $entityId !== 'media_player.' . $mediaPlayerObjectID) {
                continue;
            }

            $cover = $this->readOptionalString($mediaPlayer['CoverVariable'] ?? null, '');
            if ($cover === '') {
                $coverMediaID = (int)($mediaPlayer['CoverMediaID'] ?? 0);
                if ($coverMediaID <= 0) {
                    return null;
                }

                return $this->resolveMediaPlayerCoverMedia($coverMediaID);
            }

            return $this->resolveMediaPlayerCover($cover);
        }

        return null;
    }

    public function getEntities(): array
    {
        $now = date('Y-m-d\TH:i:s.000000P');
        $entities = [];

        foreach ($this->getData('GetLights') as $light) {
            $switchVarID = (int)($light['SwitchVariable'] ?? 0);
            $lightObjectID = $this->getConfiguredOrParentInstanceID($light, 'InstanceID', ['SwitchVariable'], (int)($light['Light_ID'] ?? 0));
            if ($switchVarID <= 0 || $lightObjectID <= 0) {
                continue;
            }

            $name = (string)($light['Name'] ?? ('Light ' . $lightObjectID));
            $isOn = $this->readBoolState($switchVarID);
            $state = $isOn ? 'on' : 'off';
            $entity = $this->buildEntity(
                'light',
                'light.' . $lightObjectID,
                $lightObjectID,
                $state,
                array_merge([
                    'friendly_name' => $name,
                    'manufacturer' => (string)($light['Manufacturer'] ?? ''),
                    'model' => (string)($light['Model'] ?? ''),
                ], $this->buildLightAttributes($light, $isOn)),
                $now
            );
            $entity['targets'] = [
                'instance' => $lightObjectID,
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

        foreach ($this->getData('GetHumiditySensors') as $sensor) {
            $varID = (int)($sensor['HumidityVariable'] ?? 0);
            if ($varID <= 0) {
                continue;
            }

            $entities[] = $this->buildEntity(
                'sensor',
                'sensor.' . $varID,
                $varID,
                (string)$this->readNumericValue($varID, 0),
                [
                    'device_class' => 'humidity',
                    'unit_of_measurement' => '%',
                    'state_class' => 'measurement',
                    'friendly_name' => (string)($sensor['Name'] ?? ('Humidity ' . $varID))
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
                'sensor',
                'sensor.' . $varID,
                $varID,
                $this->readBoolState($varID) ? 'detected' : 'clear',
                [
                    'device_class' => 'motion',
                    'state_class' => 'measurement',
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
                (string)$this->readNumericValue($varID, 0),
                [
                    'state_class' => 'measurement',
                    'unit_of_measurement' => 'lx',
                    'device_class' => 'illuminance',
                    'friendly_name' => (string)($sensor['Name'] ?? ('Illuminance ' . $varID))
                ],
                $now
            );
        }

        foreach ($this->getData('GetMediaPlayers') as $mediaPlayer) {
            $controlVarID = (int)($mediaPlayer['ControlVariable'] ?? 0);
            $mediaPlayerObjectID = $this->getConfiguredOrParentInstanceID($mediaPlayer, 'InstanceID', ['ControlVariable'], (int)($mediaPlayer['Mediaplayer_ID'] ?? 0));
            if ($controlVarID <= 0 || $mediaPlayerObjectID <= 0) {
                continue;
            }

            $entityId = 'media_player.' . $mediaPlayerObjectID;
            $entity = $this->buildEntity(
                'media_player',
                $entityId,
                $mediaPlayerObjectID,
                $this->readMediaPlayerState($mediaPlayer),
                $this->buildMediaPlayerAttributes($mediaPlayer, $entityId),
                $now
            );
            $entity['targets'] = [
                'media_player' => $mediaPlayerObjectID,
                'control' => $controlVarID,
                'playback_state' => (int)($mediaPlayer['PlaybackStateVariable'] ?? 0),
                'volume' => (int)($mediaPlayer['VolumeVariable'] ?? 0),
                'mute' => (int)($mediaPlayer['MuteVariable'] ?? 0),
                'position' => (int)($mediaPlayer['PositionVariable'] ?? 0),
                'elapsed' => (int)($mediaPlayer['ElapsedVariable'] ?? 0),
                'duration' => (int)($mediaPlayer['DurationVariable'] ?? 0),
                'previous' => (int)($mediaPlayer['NextPreviousVariable'] ?? ($mediaPlayer['PreviousVariable'] ?? 0)),
                'play_pause' => $controlVarID,
                'next' => (int)($mediaPlayer['NextPreviousVariable'] ?? ($mediaPlayer['NextVariable'] ?? 0)),
                'shuffle' => (int)($mediaPlayer['ShuffleVariable'] ?? 0),
                'repeat' => (int)($mediaPlayer['RepeatVariable'] ?? 0)
            ];
            $this->debug('ENTITY', '🧭 HA media player mapping built', 4, [
                'entity_id' => $entityId,
                'name' => (string)($mediaPlayer['Name'] ?? ('Media Player ' . $mediaPlayerObjectID)),
                'targets' => $entity['targets']
            ]);
            $entities[] = $entity;
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

    private function readMediaPlayerState(array $mediaPlayer): string
    {
        $stateVariable = (int)($mediaPlayer['PlaybackStateVariable'] ?? 0);
        if ($stateVariable > 0) {
            $state = $this->readMediaPlayerStateFromVariable($stateVariable);
            if ($state !== null) {
                return $state;
            }
        }

        $controlVariable = (int)($mediaPlayer['ControlVariable'] ?? 0);
        if ($controlVariable > 0) {
            $state = $this->readMediaPlayerStateFromVariable($controlVariable);
            if ($state !== null) {
                return $state;
            }
        }

        return 'paused';
    }

    private function buildMediaPlayerAttributes(array $mediaPlayer, string $entityId): array
    {
        $volumeLevel = $this->readMediaPlayerVolume($mediaPlayer['VolumeVariable'] ?? null);
        $isMuted = $this->readOptionalBool($mediaPlayer['MuteVariable'] ?? null) ?? false;
        $title = $this->readOptionalString($mediaPlayer['TitleVariable'] ?? null, 'Chillout Ibiza');
        $artist = $this->readOptionalString($mediaPlayer['ArtistVariable'] ?? null, 'Lounge Music Cafe');
        $source = $this->readOptionalString($mediaPlayer['SourceVariable'] ?? null, 'Line-in');
        $cover = $this->readOptionalString($mediaPlayer['CoverVariable'] ?? null, '');
        $coverMediaID = (int)($mediaPlayer['CoverMediaID'] ?? 0);
        $duration = $this->readOptionalInt($mediaPlayer['DurationVariable'] ?? null) ?? 167;
        $position = $this->readMediaPlayerPosition($mediaPlayer, $duration);
        $shuffle = $this->readOptionalBool($mediaPlayer['ShuffleVariable'] ?? null) ?? false;
        $repeat = $this->readMediaPlayerRepeat($mediaPlayer['RepeatVariable'] ?? null);

        $attributes = [
            'source_list' => [$source],
            'group_members' => [$entityId],
            'volume_level' => $volumeLevel,
            'is_volume_muted' => $isMuted,
            'media_content_type' => 'music',
            'media_duration' => $duration,
            'media_position' => $position,
            'media_position_updated_at' => gmdate('Y-m-d\TH:i:s.uP'),
            'media_title' => $title,
            'media_artist' => $artist,
            'media_album_name' => '',
            'shuffle' => $shuffle,
            'repeat' => $repeat,
            'queue_position' => 1,
            'queue_size' => 1,
            'device_class' => 'speaker',
            'friendly_name' => (string)($mediaPlayer['Name'] ?? $entityId),
            'supported_features' => 4127295
        ];

        if ($cover !== '' || $coverMediaID > 0) {
            $attributes['entity_picture'] = $this->buildMediaPlayerCoverUrl($cover !== '' ? $cover : 'media:' . $coverMediaID, $entityId);
        }

        return $attributes;
    }

    private function readMediaPlayerStateFromVariable(int $variableID): ?string
    {
        $value = $this->readValue($variableID);
        $state = strtolower(trim((string)$value));
        if (in_array($state, ['playing', 'paused', 'idle', 'standby', 'off', 'unknown', 'unavailable'], true)) {
            return $state;
        }

        $profileName = $this->readProfileAssociationName($variableID, $value);
        if ($profileName !== null) {
            $state = strtolower(trim($profileName));
            if (in_array($state, ['playing', 'play', 'media_play'], true)) {
                return 'playing';
            }

            if (in_array($state, ['paused', 'pause', 'media_pause'], true)) {
                return 'paused';
            }

            if (in_array($state, ['idle', 'stop', 'stopped'], true)) {
                return 'idle';
            }

            if (in_array($state, ['standby', 'off'], true)) {
                return $state;
            }
        }

        if (is_numeric($value)) {
            return match ((int)$value) {
                0 => 'playing',
                1 => 'paused',
                2 => 'idle',
                3 => 'off',
                default => null
            };
        }

        return null;
    }

    private function readMediaPlayerPosition(array $mediaPlayer, int $duration): int
    {
        $elapsed = $this->readOptionalInt($mediaPlayer['ElapsedVariable'] ?? null);
        if ($elapsed !== null) {
            return max(0, $elapsed);
        }

        $position = $this->readOptionalInt($mediaPlayer['PositionVariable'] ?? null);
        if ($position === null) {
            return 0;
        }

        if ($duration > 0 && $position >= 0 && $position <= 100) {
            return (int)round(($position / 100) * $duration);
        }

        return max(0, $position);
    }

    private function buildMediaPlayerCoverUrl(string $cover, string $entityId): string
    {
        return '/api/media_player_proxy/' . rawurlencode($entityId) . '?token=symcon-rs90&cache=' . substr(sha1($cover), 0, 16);
    }

    private function resolveMediaPlayerCover(string $cover): ?array
    {
        if (preg_match('#^https?://#i', $cover) === 1) {
            $body = $this->fetchRemoteMediaPlayerCover($cover);
            if ($body !== null) {
                return [
                    'body' => $body,
                    'content_type' => $this->guessImageContentTypeFromBinary($body)
                ];
            }
        }

        if (preg_match('#^data:(image/[a-zA-Z0-9.+-]+);base64,(.+)$#s', $cover, $matches) === 1) {
            $decoded = base64_decode($matches[2], true);
            if (is_string($decoded)) {
                return [
                    'body' => $decoded,
                    'content_type' => $matches[1]
                ];
            }
        }

        if (is_file($cover) && is_readable($cover)) {
            $body = @file_get_contents($cover);
            if (is_string($body)) {
                return [
                    'body' => $body,
                    'content_type' => $this->guessImageContentType($cover)
                ];
            }
        }

        $decoded = base64_decode($cover, true);
        if (is_string($decoded) && $decoded !== '') {
            return [
                'body' => $decoded,
                'content_type' => $this->guessImageContentTypeFromBinary($decoded)
            ];
        }

        return null;
    }

    private function fetchRemoteMediaPlayerCover(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'ignore_errors' => true,
                'header' => "User-Agent: IP-Symcon-Haptique-HA-Emulator\r\nAccept: image/*,*/*;q=0.8\r\n"
            ]
        ]);

        $body = @file_get_contents($url, false, $context);
        if (!is_string($body) || $body === '') {
            $this->debug('ENTITY', '⚠️ HA remote media player cover fetch failed', 3, [
                'url_preview' => mb_substr($url, 0, 300)
            ]);
            return null;
        }

        $this->debug('ENTITY', '🖼️ HA remote media player cover fetched', 4, [
            'bytes' => strlen($body),
            'content_type' => $this->guessImageContentTypeFromBinary($body),
            'url_preview' => mb_substr($url, 0, 300)
        ]);

        return $body;
    }

    private function resolveMediaPlayerCoverMedia(int $mediaID): ?array
    {
        if (!function_exists('IPS_MediaExists') || !@IPS_MediaExists($mediaID)) {
            return null;
        }

        $content = @IPS_GetMediaContent($mediaID);
        if (!is_string($content) || $content === '') {
            return null;
        }

        $decoded = base64_decode($content, true);
        if (!is_string($decoded) || $decoded === '') {
            return null;
        }

        return [
            'body' => $decoded,
            'content_type' => $this->guessImageContentTypeFromBinary($decoded)
        ];
    }

    private function guessImageContentType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/octet-stream'
        };
    }

    private function guessImageContentTypeFromBinary(string $body): string
    {
        if (str_starts_with($body, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        if (str_starts_with($body, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }

        if (str_starts_with($body, 'GIF87a') || str_starts_with($body, 'GIF89a')) {
            return 'image/gif';
        }

        if (substr($body, 0, 4) === 'RIFF' && substr($body, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return 'application/octet-stream';
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

    private function getConfiguredOrParentInstanceID(array $config, string $instanceKey, array $variableKeys, int $fallbackID = 0): int
    {
        $configuredID = (int)($config[$instanceKey] ?? 0);
        if ($this->isSymconInstanceID($configuredID)) {
            return $configuredID;
        }

        foreach ($variableKeys as $variableKey) {
            $variableID = (int)($config[$variableKey] ?? 0);
            $parentID = $this->getVariableParentInstanceID($variableID);
            if ($parentID > 0) {
                return $parentID;
            }
        }

        if ($this->isSymconInstanceID($fallbackID)) {
            return $fallbackID;
        }

        return 0;
    }

    private function getVariableParentInstanceID(int $variableID): int
    {
        if ($variableID <= 0 || !function_exists('IPS_VariableExists') || !@IPS_VariableExists($variableID)) {
            return 0;
        }

        if (!function_exists('IPS_GetParent')) {
            return 0;
        }

        $parentID = (int)@IPS_GetParent($variableID);
        for ($i = 0; $i < 20 && $parentID > 0; $i++) {
            if ($this->isSymconInstanceID($parentID)) {
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

    private function isSymconInstanceID(int $objectID): bool
    {
        if ($objectID <= 0) {
            return false;
        }

        if (function_exists('IPS_InstanceExists')) {
            return @IPS_InstanceExists($objectID);
        }

        return $objectID > 0;
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

    private function readOptionalBool($variableID): ?bool
    {
        $variableID = (int)$variableID;
        if ($variableID <= 0) {
            return null;
        }

        return (bool)$this->readValue($variableID);
    }

    private function readOptionalString($variableID, string $fallback): string
    {
        $variableID = (int)$variableID;
        if ($variableID <= 0) {
            return $fallback;
        }

        $value = $this->readValue($variableID);
        if ($value === 'unknown' || $value === null) {
            return $fallback;
        }

        return (string)$value;
    }

    private function readMediaPlayerVolume($variableID): float
    {
        $variableID = (int)$variableID;
        if ($variableID <= 0) {
            return 0.5;
        }

        $value = $this->readValue($variableID);
        if (!is_numeric($value)) {
            return 0.5;
        }

        $volume = (float)$value;
        if ($volume > 1.0) {
            $volume /= 100;
        }

        return max(0.0, min(1.0, $volume));
    }

    private function readMediaPlayerRepeat($variableID): string
    {
        $variableID = (int)$variableID;
        if ($variableID <= 0) {
            return 'off';
        }

        $value = $this->readValue($variableID);
        if (is_bool($value)) {
            return $value ? 'all' : 'off';
        }

        if (is_numeric($value)) {
            return ((int)$value) === 0 ? 'off' : 'all';
        }

        $repeat = strtolower(trim((string)$value));
        return in_array($repeat, ['off', 'one', 'all'], true) ? $repeat : 'off';
    }

    private function readProfileAssociationName(int $variableID, $value): ?string
    {
        if (!function_exists('IPS_VariableExists') || !@IPS_VariableExists($variableID)) {
            return null;
        }

        $variable = @IPS_GetVariable($variableID);
        if (!is_array($variable)) {
            return null;
        }

        $profileName = (string)($variable['VariableCustomProfile'] ?? '');
        if ($profileName === '') {
            $profileName = (string)($variable['VariableProfile'] ?? '');
        }

        if ($profileName === '' || !function_exists('IPS_VariableProfileExists') || !@IPS_VariableProfileExists($profileName)) {
            return null;
        }

        $profile = @IPS_GetVariableProfile($profileName);
        if (!is_array($profile) || !is_array($profile['Associations'] ?? null)) {
            return null;
        }

        foreach ($profile['Associations'] as $association) {
            if (!is_array($association)) {
                continue;
            }

            if ((string)($association['Value'] ?? '') === (string)$value) {
                return (string)($association['Name'] ?? '');
            }
        }

        return null;
    }

    private function readNumericValue(int $variableID, $fallback)
    {
        $value = $this->readValue($variableID);
        return is_numeric($value) ? $value : $fallback;
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
