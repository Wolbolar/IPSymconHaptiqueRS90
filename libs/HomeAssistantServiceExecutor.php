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

            $targetId = (int)($entity['target_id'] ?? 0);
            if (!$this->executeSingle($domain, $service, $entityId, $targetId)) {
                $this->debug('CMD', '⚠️ HA service unsupported', 3, [
                    'domain' => $domain,
                    'service' => $service,
                    'entity_id' => $entityId,
                    'target_id' => $targetId
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

    private function executeSingle(string $domain, string $service, string $entityId, int $targetId): bool
    {
        if ($targetId <= 0) {
            return false;
        }

        if (($domain === 'light' || $domain === 'switch') && ($service === 'turn_on' || $service === 'turn_off')) {
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
