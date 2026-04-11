<?php

declare(strict_types=1);

class HomeAssistantServiceExecutor
{
    private object $module;
    private HomeAssistantEntityRepository $repository;

    public function __construct(object $module, HomeAssistantEntityRepository $repository)
    {
        $this->module = $module;
        $this->repository = $repository;
    }

    public function execute(string $domain, string $service, array $payload): HaptiqueHttpResponse
    {
        $entityIds = $this->extractEntityIds($payload);
        if ($entityIds === []) {
            $this->debug('CMD', '❌ HA service call without entity_id', 3, [
                'domain' => $domain,
                'service' => $service,
                'payload' => $payload
            ]);
            return HaptiqueHttpResponse::error(400, 'Missing entity_id');
        }

        $changedStates = [];
        foreach ($entityIds as $entityId) {
            $entity = $this->repository->getEntity($entityId);
            if ($entity === null) {
                $this->debug('CMD', '❌ HA service entity not found', 3, [
                    'domain' => $domain,
                    'service' => $service,
                    'entity_id' => $entityId
                ]);
                return HaptiqueHttpResponse::error(404, "Entity '$entityId' not found");
            }

            if (!$this->executeSingle($domain, $service, $payload, $entity)) {
                $this->debug('CMD', '⚠️ HA service unsupported', 3, [
                    'domain' => $domain,
                    'service' => $service,
                    'entity_id' => $entityId,
                    'target_id' => (int)($entity['target_id'] ?? 0),
                    'payload' => $payload
                ]);
                return HaptiqueHttpResponse::error(501, "Unsupported service '$domain.$service' for '$entityId'");
            }

            $state = $this->repository->getState($entityId);
            if (is_array($state)) {
                $changedStates[] = $state;
            }
        }

        $this->debug('CMD', '✅ HA service call executed', 4, [
            'domain' => $domain,
            'service' => $service,
            'entity_ids' => $entityIds
        ]);
        return HaptiqueHttpResponse::json(200, $changedStates);
    }

    private function executeSingle(string $domain, string $service, array $payload, array $entity): bool
    {
        $entityId = (string)($entity['entity_id'] ?? '');
        $targetId = (int)($entity['target_id'] ?? 0);
        if ($targetId <= 0) {
            return false;
        }

        if ($domain === 'light' && ($service === 'turn_on' || $service === 'turn_off')) {
            return $this->executeLightService($service, $payload, $entity);
        }

        if ($domain === 'switch' && ($service === 'turn_on' || $service === 'turn_off')) {
            $state = $service === 'turn_on';
            $this->debug('CMD', '🎮 RequestAction', 4, [
                'entity_id' => $entityId,
                'target_id' => $targetId,
                'value' => $state
            ]);
            RequestAction($targetId, $state);
            return true;
        }

        if ($domain === 'automation' && $service === 'trigger') {
            $this->debug('CMD', '🎬 IPS_RunScript', 4, [
                'entity_id' => $entityId,
                'script_id' => $targetId
            ]);
            IPS_RunScript($targetId);
            return true;
        }

        if ($domain === 'media_player') {
            return $this->executeMediaPlayerService($service, $payload, $entity);
        }

        return false;
    }

    private function executeLightService(string $service, array $payload, array $entity): bool
    {
        $entityId = (string)($entity['entity_id'] ?? '');
        $targets = is_array($entity['targets'] ?? null) ? $entity['targets'] : [];
        $switchVariable = (int)($targets['switch'] ?? ($entity['target_id'] ?? 0));
        $brightnessVariable = (int)($targets['brightness'] ?? 0);
        $colorTemperatureVariable = (int)($targets['color_temperature'] ?? 0);

        if ($switchVariable <= 0) {
            return false;
        }

        $this->debug('CMD', '🧭 HA light target mapping', 4, [
            'entity_id' => $entityId,
            'friendly_name' => (string)($entity['state_response']['attributes']['friendly_name'] ?? ''),
            'switch_variable' => $switchVariable,
            'brightness_variable' => $brightnessVariable,
            'color_temperature_variable' => $colorTemperatureVariable,
            'payload' => $payload
        ]);

        if ($service === 'turn_off') {
            $this->requestAction($entityId, $switchVariable, false, 'switch');
            return true;
        }

        $this->requestAction($entityId, $switchVariable, true, 'switch');

        if (array_key_exists('brightness', $payload) && $brightnessVariable > 0) {
            $brightness = max(0, min(255, (int)round((float)$payload['brightness'])));
            $this->requestAction($entityId, $brightnessVariable, $brightness, 'brightness');
        }

        if (array_key_exists('color_temp_kelvin', $payload) && $colorTemperatureVariable > 0) {
            $kelvin = max(2000, min(6535, (int)round((float)$payload['color_temp_kelvin'])));
            $this->requestAction($entityId, $colorTemperatureVariable, $kelvin, 'color_temp_kelvin');
        } elseif (array_key_exists('color_temp', $payload) && $colorTemperatureVariable > 0) {
            $mired = max(1, (float)$payload['color_temp']);
            $kelvin = max(2000, min(6535, (int)round(1000000 / $mired)));
            $this->requestAction($entityId, $colorTemperatureVariable, $kelvin, 'color_temp');
        }

        return true;
    }

    private function requestAction(string $entityId, int $variableId, $value, string $target): void
    {
        $normalizedValue = $this->normalizeActionValue($variableId, $value);
        $this->debug('CMD', '🎮 RequestAction', 4, [
            'entity_id' => $entityId,
            'target' => $target,
            'target_id' => $variableId,
            'value' => $normalizedValue,
            'original_value' => $value
        ]);
        RequestAction($variableId, $normalizedValue);
    }

    private function normalizeActionValue(int $variableId, $value)
    {
        if (!function_exists('IPS_VariableExists') || !@IPS_VariableExists($variableId)) {
            return $value;
        }

        $variable = @IPS_GetVariable($variableId);
        if (!is_array($variable)) {
            return $value;
        }

        return match ((int)($variable['VariableType'] ?? -1)) {
            0 => $this->normalizeBoolActionValue($value),
            1 => $this->normalizeIntegerActionValue($variableId, $value),
            2 => is_numeric($value) ? (float)$value : 0.0,
            3 => is_bool($value) ? ($value ? 'on' : 'off') : (string)$value,
            default => $value
        };
    }

    private function normalizeBoolActionValue($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((float)$value) != 0.0;
        }

        $normalized = $this->normalizeProfileName((string)$value);
        if (in_array($normalized, ['off', 'false', 'no', 'none', 'disabled', 'disable'], true)) {
            return false;
        }

        if (in_array($normalized, ['on', 'true', 'yes', 'one', 'all', 'enabled', 'enable'], true)) {
            return true;
        }

        return (bool)$value;
    }

    private function normalizeIntegerActionValue(int $variableId, $value): int
    {
        if (is_string($value) && !is_numeric($value)) {
            $profileValue = $this->resolveIntegerProfileValue($variableId, [$value]);
            if ($profileValue !== null) {
                return $profileValue;
            }

            $normalized = $this->normalizeProfileName($value);
            if (in_array($normalized, ['off', 'false', 'no', 'none', 'previous', 'prev'], true)) {
                return 0;
            }

            if (in_array($normalized, ['on', 'true', 'yes', 'one', 'all', 'next'], true)) {
                return 1;
            }
        }

        return (int)round((float)$value);
    }

    private function executeMediaPlayerService(string $service, array $payload, array $entity): bool
    {
        $entityId = (string)($entity['entity_id'] ?? '');
        $targets = is_array($entity['targets'] ?? null) ? $entity['targets'] : [];

        $this->debug('CMD', '🧭 HA media player target mapping', 4, [
            'entity_id' => $entityId,
            'friendly_name' => (string)($entity['state_response']['attributes']['friendly_name'] ?? ''),
            'service' => $service,
            'targets' => $targets,
            'payload' => $payload
        ]);

        switch ($service) {
            case 'media_play':
                return $this->setMediaPlayerCommand(
                    $entityId,
                    (int)($targets['control'] ?? 0),
                    ['play', 'media_play'],
                    0,
                    'play'
                );

            case 'media_pause':
                return $this->setMediaPlayerCommand(
                    $entityId,
                    (int)($targets['control'] ?? 0),
                    ['pause', 'media_pause'],
                    1,
                    'pause'
                );

            case 'media_play_pause':
                return $this->setMediaPlayerCommand(
                    $entityId,
                    (int)($targets['control'] ?? ($targets['play_pause'] ?? 0)),
                    ['playpause', 'play_pause', 'play/pause', 'play / pause', 'media_play_pause'],
                    0,
                    'play_pause'
                );

            case 'media_next_track':
                return $this->setMediaPlayerCommand(
                    $entityId,
                    (int)($targets['next'] ?? 0),
                    ['next', 'next_track', 'media_next_track'],
                    1,
                    'next'
                );

            case 'media_previous_track':
                return $this->setMediaPlayerCommand(
                    $entityId,
                    (int)($targets['previous'] ?? 0),
                    ['previous', 'prev', 'previous_track', 'media_previous_track'],
                    0,
                    'previous'
                );

            case 'media_seek':
                $positionVariable = (int)($targets['position'] ?? 0);
                if ($positionVariable <= 0 || !array_key_exists('seek_position', $payload)) {
                    return false;
                }

                $positionPercent = $this->convertMediaSeekPositionToPercent($payload['seek_position'], $targets, $entity);
                $this->requestAction($entityId, $positionVariable, $positionPercent, 'position');
                return true;

            case 'volume_set':
                $volumeVariable = (int)($targets['volume'] ?? 0);
                if ($volumeVariable <= 0 || !array_key_exists('volume_level', $payload)) {
                    return false;
                }

                $volume = max(0.0, min(1.0, (float)$payload['volume_level']));
                $this->requestAction($entityId, $volumeVariable, (int)round($volume * 100), 'volume');
                return true;

            case 'volume_mute':
                $muteVariable = (int)($targets['mute'] ?? 0);
                if ($muteVariable <= 0 || !array_key_exists('is_volume_muted', $payload)) {
                    return false;
                }

                $this->requestAction($entityId, $muteVariable, (bool)$payload['is_volume_muted'], 'mute');
                return true;

            case 'shuffle_set':
                $shuffleVariable = (int)($targets['shuffle'] ?? 0);
                if ($shuffleVariable <= 0 || !array_key_exists('shuffle', $payload)) {
                    return false;
                }

                $this->requestAction($entityId, $shuffleVariable, (bool)$payload['shuffle'], 'shuffle');
                return true;

            case 'repeat_set':
                $repeatVariable = (int)($targets['repeat'] ?? 0);
                if ($repeatVariable <= 0 || !array_key_exists('repeat', $payload)) {
                    return false;
                }

                $this->requestAction($entityId, $repeatVariable, (string)$payload['repeat'], 'repeat');
                return true;
        }

        return false;
    }

    private function setMediaPlayerCommand(
        string $entityId,
        int $variableId,
        array $profileNames,
        int $fallbackValue,
        string $target
    ): bool
    {
        if ($variableId <= 0) {
            return false;
        }

        $value = $this->resolveIntegerProfileValue($variableId, $profileNames);
        if ($value === null) {
            $value = $fallbackValue;
            $this->debug('CMD', '⚠️ Media player profile value not found, using fallback', 3, [
                'entity_id' => $entityId,
                'target' => $target,
                'target_id' => $variableId,
                'fallback_value' => $fallbackValue,
                'searched_names' => $profileNames
            ]);
        }

        $this->requestAction($entityId, $variableId, $value, $target);
        return true;
    }

    private function convertMediaSeekPositionToPercent($seekPosition, array $targets, array $entity): float
    {
        $seekPosition = max(0.0, (float)$seekPosition);
        $duration = $this->readNumericVariable((int)($targets['duration'] ?? 0));

        if ($duration <= 0.0) {
            $attributes = is_array($entity['state_response']['attributes'] ?? null) ? $entity['state_response']['attributes'] : [];
            $duration = is_numeric($attributes['media_duration'] ?? null) ? (float)$attributes['media_duration'] : 0.0;
        }

        if ($duration <= 0.0) {
            $this->debug('CMD', '⚠️ Media seek duration missing, using seek position as percent', 3, [
                'entity_id' => (string)($entity['entity_id'] ?? ''),
                'seek_position' => $seekPosition,
                'duration_target_id' => (int)($targets['duration'] ?? 0)
            ]);
            return max(0.0, min(100.0, $seekPosition));
        }

        $percent = ($seekPosition / $duration) * 100.0;
        $this->debug('CMD', '🧮 Media seek converted to percent', 4, [
            'entity_id' => (string)($entity['entity_id'] ?? ''),
            'seek_position' => $seekPosition,
            'duration' => $duration,
            'percent' => $percent
        ]);

        return max(0.0, min(100.0, $percent));
    }

    private function readNumericVariable(int $variableId): float
    {
        if ($variableId <= 0 || !function_exists('IPS_VariableExists') || !@IPS_VariableExists($variableId)) {
            return 0.0;
        }

        $value = @GetValue($variableId);
        return is_numeric($value) ? (float)$value : 0.0;
    }

    private function resolveIntegerProfileValue(int $variableId, array $names): ?int
    {
        if (!function_exists('IPS_VariableExists') || !@IPS_VariableExists($variableId)) {
            return null;
        }

        $variable = @IPS_GetVariable($variableId);
        if (!is_array($variable) || (int)($variable['VariableType'] ?? -1) !== 1) {
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

        $normalizedNames = array_map([$this, 'normalizeProfileName'], $names);
        foreach ($profile['Associations'] as $association) {
            if (!is_array($association)) {
                continue;
            }

            $associationName = $this->normalizeProfileName((string)($association['Name'] ?? ''));
            if (in_array($associationName, $normalizedNames, true)) {
                return (int)($association['Value'] ?? 0);
            }
        }

        return null;
    }

    private function normalizeProfileName(string $name): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($name)) ?? '';
    }

    private function extractEntityIds(array $payload): array
    {
        $entityId = $payload['entity_id'] ?? ($payload['target']['entity_id'] ?? null);

        if (is_string($entityId) && $entityId !== '') {
            return [$entityId];
        }

        if (is_array($entityId)) {
            return array_values(array_filter($entityId, static fn($value): bool => is_string($value) && $value !== ''));
        }

        return [];
    }

    private function debug(string $topic, string $message, int $level = 4, $data = ''): void
    {
        if (method_exists($this->module, 'HA_Debug')) {
            $this->module->HA_Debug($topic, $message, $level, $data);
        }
    }
}
