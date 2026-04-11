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
        $this->debug('CMD', '🎮 RequestAction', 4, [
            'entity_id' => $entityId,
            'target' => $target,
            'target_id' => $variableId,
            'value' => $value
        ]);
        RequestAction($variableId, $value);
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
