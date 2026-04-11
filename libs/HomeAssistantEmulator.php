<?php

declare(strict_types=1);

require_once __DIR__ . '/HaptiqueHttpResponse.php';
require_once __DIR__ . '/HomeAssistantEntityRepository.php';
require_once __DIR__ . '/HomeAssistantServiceExecutor.php';

class HomeAssistantEmulator
{
    private object $module;
    private HomeAssistantEntityRepository $repository;
    private HomeAssistantServiceExecutor $executor;

    public function __construct(object $module)
    {
        $this->module = $module;
        $this->repository = new HomeAssistantEntityRepository($module);
        $this->executor = new HomeAssistantServiceExecutor($module, $this->repository);
    }

    public function getStates(): array
    {
        return $this->repository->getStates();
    }

    public function getState(string $entityId): ?array
    {
        return $this->repository->getState($entityId);
    }

    public function getMediaPlayerCoverResponse(string $entityId): HaptiqueHttpResponse
    {
        $cover = $this->repository->getMediaPlayerCover($entityId);
        if ($cover === null) {
            return HaptiqueHttpResponse::error(404, "Cover for '$entityId' not found");
        }

        return new HaptiqueHttpResponse(200, (string)$cover['body'], [
            'Content-Type' => (string)$cover['content_type'],
            'Cache-Control' => 'no-cache'
        ]);
    }

    public function handleRawHttpRequest(string $rawRequest): HaptiqueHttpResponse
    {
        $request = $this->parseHttpRequest($rawRequest);
        if ($request === null) {
            $this->debug('HA', '❌ Invalid HA HTTP request', 2, [
                'raw_preview' => mb_substr($rawRequest, 0, 1000),
                'raw_length' => strlen($rawRequest)
            ]);
            return $this->respond(HaptiqueHttpResponse::error(400, 'Invalid HTTP request'), 'invalid_request');
        }

        $this->debug('HA', '📥 HA HTTP request', 4, [
            'method' => $request['method'],
            'path' => $request['path'],
            'query' => $request['query'],
            'headers' => $this->sanitizeHeaders($request['headers']),
            'body_length' => strlen($request['body']),
            'body_preview' => mb_substr($request['body'], 0, 1000)
        ]);

        if ($request['method'] === 'GET' && $request['path'] === '/api/states') {
            $states = $this->repository->getStates();
            $this->debug('ENTITY', '📦 HA state list built', 4, [
                'count' => count($states),
                'entity_ids' => array_column($states, 'entity_id')
            ]);
            return $this->respond(HaptiqueHttpResponse::json(200, $states), 'states_list');
        }

        if ($request['method'] === 'GET' && preg_match('#^/api/states/([a-zA-Z0-9._]+)$#', $request['path'], $matches)) {
            $state = $this->repository->getState($matches[1]);
            if ($state === null) {
                $this->debug('ENTITY', '❌ HA entity not found', 3, ['entity_id' => $matches[1]]);
                return $this->respond(HaptiqueHttpResponse::error(404, "Entity '{$matches[1]}' not found"), 'state_not_found');
            }

            $this->debug('ENTITY', '📦 HA single state built', 4, ['entity_id' => $matches[1]]);
            return $this->respond(HaptiqueHttpResponse::json(200, $state), 'single_state');
        }

        if ($request['method'] === 'GET' && preg_match('#^/api/media_player_proxy/([a-zA-Z0-9._%-]+)$#', $request['path'], $matches)) {
            $entityId = rawurldecode($matches[1]);
            $this->debug('ENTITY', '🖼️ HA media player cover requested', 4, [
                'entity_id' => $entityId
            ]);
            return $this->respond($this->getMediaPlayerCoverResponse($entityId), 'media_player_cover');
        }

        if ($request['method'] === 'POST' && preg_match('#^/api/services/([a-zA-Z0-9_]+)/([a-zA-Z0-9_]+)$#', $request['path'], $matches)) {
            $payload = $this->decodeJsonBody($request['body']);
            if ($payload === null) {
                $this->debug('CMD', '❌ Invalid HA service JSON body', 3, [
                    'path' => $request['path'],
                    'body_preview' => mb_substr($request['body'], 0, 1000)
                ]);
                return $this->respond(HaptiqueHttpResponse::error(400, 'Invalid JSON body'), 'invalid_service_body');
            }

            $this->debug('CMD', '🎮 HA service call', 4, [
                'domain' => $matches[1],
                'service' => $matches[2],
                'payload' => $payload
            ]);
            return $this->respond($this->executor->execute($matches[1], $matches[2], $payload), 'service_call');
        }

        if ($request['method'] === 'POST' && $request['path'] === '/api/template') {
            $devices = $this->repository->getDeviceList();
            $this->debug('ENTITY', '📦 HA template device list built', 4, [
                'count' => count($devices),
                'device_ids' => array_column($devices, 'device_id')
            ]);
            return $this->respond(HaptiqueHttpResponse::json(200, $devices), 'template_devices');
        }

        $this->debug('HA', '❌ Unsupported HA route', 3, [
            'method' => $request['method'],
            'path' => $request['path'],
            'body_preview' => mb_substr($request['body'], 0, 1000)
        ]);
        return $this->respond(HaptiqueHttpResponse::error(404, "Unknown request: {$request['method']} {$request['path']}"), 'unknown_route');
    }

    private function parseHttpRequest(string $rawRequest): ?array
    {
        $parts = explode("\r\n\r\n", $rawRequest, 2);
        $headerBlock = $parts[0] ?? '';
        $body = $parts[1] ?? '';
        $headerLines = explode("\r\n", $headerBlock);
        $requestLine = $headerLines[0] ?? '';
        $requestParts = explode(' ', $requestLine);

        if (count($requestParts) < 2) {
            return null;
        }

        $method = strtoupper((string)$requestParts[0]);
        $requestTarget = (string)$requestParts[1];
        $path = (string)parse_url($requestTarget, PHP_URL_PATH);
        $query = (string)(parse_url($requestTarget, PHP_URL_QUERY) ?? '');
        if ($method === '' || $path === '') {
            return null;
        }

        $headers = [];
        foreach (array_slice($headerLines, 1) as $line) {
            $separator = strpos($line, ':');
            if ($separator === false) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $separator)));
            $headers[$name] = trim(substr($line, $separator + 1));
        }

        return [
            'method' => $method,
            'path' => $path,
            'query' => $query,
            'headers' => $headers,
            'body' => $body
        ];
    }

    private function decodeJsonBody(string $body): ?array
    {
        if (trim($body) === '') {
            return [];
        }

        $payload = json_decode($body, true);
        return is_array($payload) ? $payload : null;
    }

    private function respond(HaptiqueHttpResponse $response, string $routeName): HaptiqueHttpResponse
    {
        $this->debug('HA', '📤 HA response prepared', 4, [
            'route' => $routeName,
            'status' => $response->getStatusCode(),
            'content_length' => strlen($response->getBody()),
            'body_preview' => mb_substr($response->getBody(), 0, 1000)
        ]);

        return $response;
    }

    private function sanitizeHeaders(array $headers): array
    {
        $sanitized = $headers;
        foreach (['authorization', 'cookie', 'x-ha-access'] as $sensitiveHeader) {
            if (isset($sanitized[$sensitiveHeader])) {
                $sanitized[$sensitiveHeader] = '[redacted, length=' . strlen((string)$headers[$sensitiveHeader]) . ']';
            }
        }

        return $sanitized;
    }

    private function debug(string $topic, string $message, int $level = 4, $data = ''): void
    {
        if (method_exists($this->module, 'HA_Debug')) {
            $this->module->HA_Debug($topic, $message, $level, $data);
        }
    }
}
