<?php

declare(strict_types=1);

/**
 * Trait with public bridge methods so external helper classes can access
 * protected IPSModuleStrict APIs in a safe way.
 *
 * Usage in a module class:
 *   class Remote3IntegrationDriver extends IPSModuleStrict {
 *       use DebugTrait;
 *   }
 */
trait DebugTrait
{
    public function Ext_ReadPropertyString(string $name): string
    {
        return $this->ReadPropertyString($name);
    }

    public function Ext_ReadAttributeString(string $name): string
    {
        return $this->ReadAttributeString($name);
    }

    public function Ext_WriteAttributeString(string $name, string $value): void
    {
        $this->WriteAttributeString($name, $value);
    }

    public function Ext_ReadAttributeBoolean(string $name): bool
    {
        return $this->ReadAttributeBoolean($name);
    }

    public function Ext_WriteAttributeBoolean(string $name, bool $value): void
    {
        $this->WriteAttributeBoolean($name, $value);
    }


    /**
     * Reads client sessions for debug/UI purposes.
     *
     * If the module also uses ClientSessionTrait, prefer its readSessions() implementation.
     * Otherwise fall back to decoding the attribute directly.
     */
    private function Debug_ReadClientSessions(): array
    {
        // Prefer ClientSessionTrait implementation if present
        if (method_exists($this, 'readSessions')) {
            try {
                $res = $this->readSessions();
                if (is_array($res)) {
                    return $res;
                }
            } catch (Throwable $e) {
                // fall through to attribute decode
            }
        }

        $raw = (string)$this->ReadAttributeString('client_sessions');
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    // -----------------------------
    // Debug Levels (lowest = BASIC)
    // -----------------------------
    public const LV_BASIC = 1;
    public const LV_ERROR = 2;
    public const LV_WARN = 3;
    public const LV_INFO = 4;
    public const LV_TRACE = 5;

    // -----------------------------
    // Debug Topics
    // -----------------------------
    public const TOPIC_GEN = 'GEN';
    public const TOPIC_AUTH = 'AUTH';
    public const TOPIC_HOOK = 'HOOK';
    public const TOPIC_WS = 'WS';
    public const TOPIC_DEVICE = 'DEVICE';
    public const TOPIC_IO = 'IO';
    public const TOPIC_ENTITY = 'ENTITY';
    public const TOPIC_VM = 'VM';
    public const TOPIC_DISCOVERY = 'DISCOVERY';
    public const TOPIC_API = 'API';
    public const TOPIC_FORM = 'FORM';
    public const TOPIC_EXT = 'EXT';
    // Debug topics
    public const TOPIC_SETUP = 'SETUP';

    public const TOPIC_CMD = 'CMD';

    /**
     * Structured debug output with topic/level filtering and throttling.
     * Lowest level is BASIC (1). There is no OFF level.
     */
    public function Debug(string $Message, int $Level, string $Topic, string $Data, int $Format = 0): bool
    {
        // If expert debug is OFF: classic behavior, but respect debug_level threshold.
        if (!(bool)$this->ReadPropertyBoolean('expert_debug')) {
            $cfgLevel = (int)$this->ReadPropertyInteger('debug_level');
            if ($cfgLevel < self::LV_BASIC) {
                $cfgLevel = self::LV_BASIC;
            }
            if ($Level > $cfgLevel) {
                return false;
            }
            return parent::SendDebug($Message, $this->DebugDataToString($Data), $Format);
        }

        // Expert debug: apply topic + filters + throttle
        $topicUpper = strtoupper(trim($Topic));
        if ($topicUpper === '') {
            $topicUpper = self::TOPIC_GEN;
        }

        if (!$this->DebugFilterMatches($Message, $Data, $topicUpper, $Level)) {
            return false;
        }

        $thKey = $topicUpper . '|' . $Level . '|' . $Message . '|' . $this->DebugDataToString($Data);
        if (!$this->DebugThrottleAllow($thKey)) {
            return false;
        }

        // Make topic+level visible in the debug list (left column)
        $lvl = $this->DebugLevelToShortName($Level);
        $msgOut = '[' . $topicUpper . '|' . $lvl . '] ' . $Message;

        return parent::SendDebug($msgOut, $this->DebugDataToString($Data), $Format);
    }

    private function DebugLevelToShortName(int $level): string
    {
        return match ($level) {
            self::LV_BASIC => 'BASIC',
            self::LV_ERROR => 'ERROR',
            self::LV_WARN => 'WARN',
            self::LV_INFO => 'INFO',
            self::LV_TRACE => 'TRACE',
            default => (string)$level
        };
    }

    private function DebugDataToString($Data): string
    {
        if (is_string($Data)) {
            return $Data;
        }
        if (is_scalar($Data)) {
            return (string)$Data;
        }
        $json = json_encode($Data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? '[unserializable]' : $json;
    }

    private function GetDebugTopicMasterList(): array
    {
        return [
            self::TOPIC_GEN => 'General / module lifecycle',
            self::TOPIC_AUTH => 'Authentication / token / whitelist',
            self::TOPIC_HOOK => 'Webhook requests / responses',
            self::TOPIC_WS => 'WebSocket frames / low-level',
            self::TOPIC_DEVICE => 'Device',
            self::TOPIC_IO => 'Socket I/O / transport details',
            self::TOPIC_ENTITY => 'Entity updates sent to Remote 3',
            self::TOPIC_VM => 'Variable/MessageSink processing',
            self::TOPIC_DISCOVERY => 'Discovery / device mapping helpers',
            self::TOPIC_API => 'Remote API calls',
            self::TOPIC_FORM => 'Form/UI helpers / sessions list',
            self::TOPIC_EXT => 'Extended / verbose debug',
            self::TOPIC_SETUP => 'Driver setup flow (setup_driver / driver_setup_change / set_driver_user_data)',
            self::TOPIC_CMD => 'Incoming commands + responses (entity_command, action calls, result)',
        ];
    }

    private function BuildDebugTopicsConfig(): array
    {
        $raw = $this->ReadPropertyString('debug_topics_cfg');
        $cfg = json_decode($raw, true);

        $master = $this->GetDebugTopicMasterList();
        $result = [];

        // If config exists: use it
        $enabledByTopic = [];
        if (is_array($cfg)) {
            foreach ($cfg as $row) {
                if (!is_array($row)) continue;
                $t = strtoupper(trim((string)($row['topic'] ?? '')));
                if ($t === '') continue;
                $enabledByTopic[$t] = (bool)($row['enabled'] ?? true);
            }
        }

        // Build full list (all topics default enabled)
        foreach ($master as $topic => $desc) {
            $topic = strtoupper($topic);
            $result[] = [
                'enabled' => $enabledByTopic[$topic] ?? true,
                'topic' => $topic,
                'description' => $desc
            ];
        }

        return $result;
    }

    private function GetEnabledDebugTopics(): array
    {
        // If user never touched topics -> allow all (empty list means "no restriction")
        $rows = json_decode($this->ReadPropertyString('debug_topics_cfg'), true);
        if (!is_array($rows)) {
            return []; // allow all
        }

        $enabled = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $topic = strtoupper(trim((string)($row['topic'] ?? '')));
            if ($topic === '') continue;
            if ((bool)($row['enabled'] ?? true)) {
                $enabled[] = $topic;
            }
        }

        // If all are disabled (user error) -> treat as allow all (avoid "no debug at all")
        if (count($enabled) === 0) {
            return [];
        }

        return array_values(array_unique($enabled));
    }

    private function DebugFilterMatches(string $message, $data, string $topic, int $level): bool
    {
        // Level gate is ALWAYS applied
        $cfgLevel = (int)$this->ReadPropertyInteger('debug_level');
        if ($cfgLevel < self::LV_BASIC) {
            $cfgLevel = self::LV_BASIC;
        }
        if ($level > $cfgLevel) {
            return false;
        }

        // Topic gate (empty = allow all)
        $enabledTopics = $this->GetEnabledDebugTopics();
        if (!empty($enabledTopics)) {
            $topicUpper = strtoupper($topic);
            if (!in_array($topicUpper, $enabledTopics, true)) {
                return false;
            }
        }

        // If filters are disabled, we're done (after level/topic gating)
        if (!(bool)$this->ReadPropertyBoolean('debug_filter_enabled')) {
            return true;
        }

        // BASIC is always visible, even when filters are enabled.
        // (Level/topic selection still applies above.)
        if ($level === self::LV_BASIC) {
            return true;
        }

        $entityIds = $this->ParseCsvList((string)$this->ReadPropertyString('debug_entity_ids'));
        $varIds = $this->ParseCsvList((string)$this->ReadPropertyString('debug_var_ids'));
        $clientIps = $this->GetConfiguredClientIPs();

        $textFilter = (string)$this->ReadPropertyString('debug_text_filter');
        $textIsRegex = (bool)$this->ReadPropertyBoolean('debug_text_is_regex');
        $strict = (bool)$this->ReadPropertyBoolean('debug_strict_match');

        // Instance/device filter (select instance -> resolve mapped VarIDs)
        $instanceRows = json_decode($this->ReadPropertyString('debug_filter_instances'), true);
        if (is_array($instanceRows)) {
            foreach ($instanceRows as $row) {
                if (!is_array($row)) continue;
                $iid = (int)($row['instance_id'] ?? 0);
                if ($iid <= 0 || !IPS_InstanceExists($iid)) continue;

                foreach ($this->GetMappedVarIdsForInstance($iid) as $vid) {
                    $varIds[] = (string)$vid;
                }
            }
            $varIds = array_values(array_unique(array_filter($varIds, fn($v) => $v !== '')));
        }


        // Prepare haystack
        if (is_string($data)) {
            $dataStr = $data;
        } elseif (is_array($data) || is_object($data)) {
            $dataStr = json_encode($data, JSON_UNESCAPED_SLASHES);
        } else {
            $dataStr = (string)$data;
        }
        $haystack = $message . ' ' . $dataStr;

        $matches = [];

        if (!empty($entityIds)) {
            foreach ($entityIds as $e) {
                if ($e !== '' && strpos($haystack, $e) !== false) {
                    $matches[] = true;
                    break;
                }
            }
        }

        if (!empty($varIds)) {
            foreach ($varIds as $v) {
                if ($v !== '' && strpos($haystack, (string)$v) !== false) {
                    $matches[] = true;
                    break;
                }
            }
        }

        if (!empty($clientIps)) {
            foreach ($clientIps as $ip) {
                if ($ip !== '' && strpos($haystack, $ip) !== false) {
                    $matches[] = true;
                    break;
                }
            }
        }

        if (trim($textFilter) !== '') {
            if ($textIsRegex) {
                $ok = @preg_match($textFilter, $haystack) === 1;
                $matches[] = $ok;
            } else {
                $matches[] = (strpos($haystack, $textFilter) !== false);
            }
        }

        // If no actual filter set besides enabled -> allow (avoid hiding everything)
        $anyConfigured = !empty($entityIds) || !empty($varIds) || !empty($clientIps) || trim($textFilter) !== '';
        if (!$anyConfigured) {
            return true;
        }

        // Strict: require at least one match
        if ($strict) {
            return in_array(true, $matches, true);
        }

        return true;
    }

    private function DebugThrottleAllow(string $key): bool
    {
        $ms = (int)$this->ReadPropertyInteger('debug_throttle_ms');
        if ($ms <= 0) {
            return true;
        }

        $now = (int)floor(microtime(true) * 1000);
        $bufKey = 'dbg_throttle_' . md5($key);
        $last = (int)$this->GetBuffer($bufKey);

        if ($last > 0 && ($now - $last) < $ms) {
            return false;
        }

        $this->SetBuffer($bufKey, (string)$now);
        return true;
    }

    /**
     * Extracts known client session IPs for use in the whitelist select field.
     *
     * @return array Array of ['value' => IP, 'caption' => string]
     */
    private function GetKnownClientIPOptions(): array
    {
        $sessions = $this->Debug_ReadClientSessions();
        $options = [];

        foreach ($sessions as $clientKey => $info) {
            $clientKey = (string)$clientKey;
            $ip = $clientKey;
            $this->Debug(__FUNCTION__, self::LV_TRACE, self::TOPIC_FORM, '🔎 Option source key=' . $clientKey . ' (colons=' . substr_count($clientKey, ':') . ')', 0);

            // Key format: [IPv6]:port
            if (preg_match('/^\[(.+)]:(\d+)$/', $clientKey, $m)) {
                $ip = $m[1];
            } // Key format: IPv4:port (exactly one colon)
            elseif (substr_count($clientKey, ':') === 1 && preg_match('/^([^:]+):(\d+)$/', $clientKey, $m)) {
                $ip = $m[1];
            }
            // Otherwise: treat as pure IP (IMPORTANT: IPv6 contains many colons)

            $this->Debug(__FUNCTION__, self::LV_TRACE, self::TOPIC_FORM, '✅ Option parsed ip=' . $ip, 0);
            // Deduplicate
            $existingValues = array_column($options, 'value');
            if (!in_array($ip, $existingValues, true)) {
                $caption = $ip;
                if (is_array($info) && !empty($info['model'])) {
                    $caption .= ' (' . $info['model'] . ')';
                }
                $options[] = [
                    'caption' => $caption,
                    'value' => $ip
                ];
            }
        }

        return $options;
    }

    /**
     * Dumps raw and parsed client_sessions attribute and triggers GetKnownClientIPOptions debug.
     */
    public function DumpClientSessions(): void
    {
        $raw = $this->ReadAttributeString('client_sessions');
        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_FORM, '📦 client_sessions (raw)=' . $raw, 0);

        $parsed = $this->Debug_ReadClientSessions();
        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_FORM, '📦 client_sessions (parsed)=' . json_encode($parsed), 0);

        $this->GetKnownClientIPOptions(); // triggers detailed option logs
    }

    /**
     * Test method to manually trigger filtered debug output.
     * Can be called via IPS console or temporary button.
     */
    public function TestFilteredDebug(): void
    {
        $this->Debug(__FUNCTION__, self::LV_BASIC, self::TOPIC_GEN, '🧪 BASIC test output', 0);
        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_WS, '📤 Simulated transmit to 192.168.0.50:12345', 0);

        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_ENTITY, json_encode([
            'entity_id' => 'sensor_12345',
            'value' => 42,
            'unit' => '°C'
        ]), 0);

        $this->Debug(__FUNCTION__, self::LV_ERROR, self::TOPIC_AUTH, '❌ Simulated auth error', 0);
        $this->Debug(__FUNCTION__, self::LV_TRACE, self::TOPIC_VM, '🔁 High frequency event simulation', 0);

        $this->Debug(__FUNCTION__, self::LV_BASIC, self::TOPIC_GEN, '✅ TestFilteredDebug executed', 0);
    }

    private function BuildDebugClientIPsConfig(): array
    {
        $raw = (string)$this->ReadPropertyString('debug_client_ips_cfg');
        $cfg = json_decode($raw, true);

        $existing = [];
        if (is_array($cfg)) {
            foreach ($cfg as $row) {
                if (!is_array($row)) continue;
                $ip = trim((string)($row['ip'] ?? ''));
                if ($ip === '') continue;
                $existing[] = ['ip' => $ip];
            }
        }

        return $existing;
    }

    private function GetConfiguredClientIPs(): array
    {
        $ips = [];

        // New list-based config
        $rows = json_decode((string)$this->ReadPropertyString('debug_client_ips_cfg'), true);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $ip = trim((string)($row['ip'] ?? ''));
                if ($ip !== '') $ips[] = $ip;
            }
        }

        // Backward compatible: legacy CSV property (if still present)
        $legacy = (string)$this->ReadPropertyString('debug_client_ips');
        if ($legacy !== '') {
            $ips = array_merge($ips, $this->ParseCsvList($legacy));
        }

        $ips = array_values(array_unique(array_filter($ips, fn($v) => $v !== '')));
        return $ips;
    }
}
