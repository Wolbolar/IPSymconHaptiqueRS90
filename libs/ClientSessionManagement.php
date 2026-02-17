<?php

trait ClientSessionTrait
{
    private function getKey(string $ip, int $port): string
    {
        return $ip . ':' . $port;
    }

    private function readSessions(): array
    {
        return json_decode($this->ReadAttributeString('client_sessions'), true) ?: [];
    }

    private function writeSessions(array $sessions): void
    {
        $this->WriteAttributeString('client_sessions', json_encode($sessions));
    }

    /**
     * Add or update a client session by IP, updating port and last_seen.
     */
    private function addOrUpdateClientSession(string $clientIP, int $clientPort): void
    {
        $sessions = json_decode($this->ReadAttributeString('client_sessions'), true) ?? [];
        if (!isset($sessions[$clientIP])) {
            $sessions[$clientIP] = [
                'port' => $clientPort,
                'authenticated' => false,
                'subscribed' => false,
                'last_seen' => time()
            ];
        } else {
            $sessions[$clientIP]['port'] = $clientPort;
            $sessions[$clientIP]['last_seen'] = time();
        }
        $this->writeSessions($sessions);
    }

    /**
     * Authenticate a client session by IP, updating the session state.
     */
    private function authenticateClient(string $clientIP, int $clientPort, $token): bool
    {
        $sessions = $this->readSessions();

        // Always operate with IP as unique key
        if (!isset($sessions[$clientIP])) {
            $sessions[$clientIP] = [
                'port' => $clientPort,
                'authenticated' => false,
                'subscribed' => false,
                'last_seen' => time()
            ];
        }

        // Validate token (replace with your actual logic)
        $validToken = $this->ReadAttributeString('token');
        $isValid = ($token && $token === $validToken);

        $sessions[$clientIP]['authenticated'] = $isValid;
        $sessions[$clientIP]['port'] = $clientPort;
        $sessions[$clientIP]['last_seen'] = time();

        $this->writeSessions($sessions);
        return $isValid;
    }

    /**
     * Subscribe a client to events by IP.
     */
    private function subscribeClientToEvents(string $clientIP, int $clientPort): void
    {
        $sessions = $this->readSessions();
        if (!isset($sessions[$clientIP])) {
            $sessions[$clientIP] = [
                'port' => $clientPort,
                'authenticated' => false,
                'subscribed' => false,
                'last_seen' => time()
            ];
        }
        $sessions[$clientIP]['subscribed'] = true;
        $sessions[$clientIP]['port'] = $clientPort;
        $sessions[$clientIP]['last_seen'] = time();
        $this->writeSessions($sessions);
    }

    /**
     * Return all client sessions as an array.
     */
    private function getAllClientSessions(): array
    {
        return json_decode($this->ReadAttributeString('client_sessions'), true) ?? [];
    }

    /**
     * Check if a client is authenticated by IP.
     */
    private function isClientAuthenticated(string $clientIP): bool
    {
        $sessions = $this->getAllClientSessions();
        return !empty($sessions[$clientIP]['authenticated']);
    }

    /**
     * Check if a client is subscribed by IP.
     */
    private function isClientSubscribed(string $clientIP): bool
    {
        $sessions = $this->getAllClientSessions();
        return !empty($sessions[$clientIP]['subscribed']);
    }

    private function getAllClients(): array
    {
        return array_keys($this->readSessions());
    }

    private function resetClients(): void
    {
        $this->writeSessions([]);
    }
}
