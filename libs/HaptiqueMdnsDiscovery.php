<?php

declare(strict_types=1);

class HaptiqueMdnsDiscovery
{
    private const DNSSD_MODULE_ID = '{780B2D48-916C-4D59-AD35-5A429B2355A5}';
    private const CANTATA_SERVICE_TYPE = '_cantata-integration._tcp';
    private const HOME_ASSISTANT_SERVICE_TYPE = '_home-assistant._tcp';
    private const CANTATA_SERVICE_NAME = 'Symcon';
    private const HOME_ASSISTANT_SERVICE_NAME = 'Symcon Home Assistant';

    private object $module;

    public function __construct(object $module)
    {
        $this->module = $module;
    }

    public function register(int $port, string $driverVersion, bool $registerHomeAssistant): void
    {
        $mdnsID = $this->getDnsSdInstanceID();
        if ($mdnsID === 0) {
            $this->debug('DISCOVERY', '❌ No DNS-SD Control instance found', 3);
            return;
        }

        $entries = $this->readServices($mdnsID);
        $entries = $this->removeManagedEntries($entries);

        $firstName = $this->getSymconFirstName();
        $hostIP = $this->getHostIP();

        $entries[] = $this->buildCantataEntry($port, $driverVersion, $firstName);

        if ($registerHomeAssistant) {
            $entries[] = $this->buildHomeAssistantEntry($port, $driverVersion, $firstName, $hostIP);
        }

        $this->writeServices($mdnsID, $entries);
        $this->debug('DISCOVERY', '📡 mDNS services registered', 4, [
            'port' => $port,
            'host_ip' => $hostIP,
            'services' => [
                self::CANTATA_SERVICE_TYPE,
                $registerHomeAssistant ? self::HOME_ASSISTANT_SERVICE_TYPE : null
            ]
        ]);
    }

    public function unregister(): void
    {
        $mdnsID = $this->getDnsSdInstanceID();
        if ($mdnsID === 0) {
            return;
        }

        $entries = $this->removeManagedEntries($this->readServices($mdnsID));
        $this->writeServices($mdnsID, $entries);
        $this->debug('DISCOVERY', '🧹 Managed mDNS services unregistered', 4);
    }

    private function buildCantataEntry(int $port, string $driverVersion, string $firstName): array
    {
        return [
            'Name' => self::CANTATA_SERVICE_NAME,
            'RegType' => self::CANTATA_SERVICE_TYPE,
            'Domain' => '',
            'Host' => '',
            'Port' => $port,
            'TXTRecords' => [
                ['Value' => 'name=Symcon von ' . $firstName],
                ['Value' => 'ver=' . $driverVersion],
                ['Value' => 'developer=Fonzo'],
                ['Value' => 'pwd=true']
            ]
        ];
    }

    private function buildHomeAssistantEntry(int $port, string $driverVersion, string $firstName, string $hostIP): array
    {
        $baseUrl = $hostIP !== '' ? 'http://' . $hostIP . ':' . $port : '';

        $txt = [
            ['Value' => 'location_name=Symcon HA von ' . $firstName],
            ['Value' => 'uuid=symcon-rs90-ha-' . $this->getInstanceID()],
            ['Value' => 'version=' . $driverVersion],
            ['Value' => 'requires_api_password=false']
        ];

        if ($baseUrl !== '') {
            $txt[] = ['Value' => 'base_url=' . $baseUrl];
            $txt[] = ['Value' => 'internal_url=' . $baseUrl];
        }

        return [
            'Name' => self::HOME_ASSISTANT_SERVICE_NAME,
            'RegType' => self::HOME_ASSISTANT_SERVICE_TYPE,
            'Domain' => '',
            'Host' => '',
            'Port' => $port,
            'TXTRecords' => $txt
        ];
    }

    private function removeManagedEntries(array $entries): array
    {
        return array_values(array_filter($entries, static function (array $entry): bool {
            $name = (string)($entry['Name'] ?? '');
            $type = (string)($entry['RegType'] ?? '');

            if ($name === self::CANTATA_SERVICE_NAME && $type === self::CANTATA_SERVICE_TYPE) {
                return false;
            }

            if ($name === self::CANTATA_SERVICE_NAME && $type === '_uc-integration._tcp') {
                return false;
            }

            if ($name === self::HOME_ASSISTANT_SERVICE_NAME && $type === self::HOME_ASSISTANT_SERVICE_TYPE) {
                return false;
            }

            return true;
        }));
    }

    private function getDnsSdInstanceID(): int
    {
        $ids = @IPS_GetInstanceListByModuleID(self::DNSSD_MODULE_ID);
        if (!is_array($ids) || $ids === []) {
            return 0;
        }

        return (int)$ids[0];
    }

    private function readServices(int $mdnsID): array
    {
        $services = json_decode((string)IPS_GetProperty($mdnsID, 'Services'), true);
        return is_array($services) ? $services : [];
    }

    private function writeServices(int $mdnsID, array $entries): void
    {
        IPS_SetProperty($mdnsID, 'Services', json_encode(array_values($entries)));
        IPS_ApplyChanges($mdnsID);
    }

    private function getHostIP(): string
    {
        if (!function_exists('Sys_GetNetworkInfo')) {
            return '';
        }

        $network = @Sys_GetNetworkInfo();
        if (!is_array($network)) {
            return '';
        }

        foreach ($network as $device) {
            $ips = $device['IP'] ?? [];
            if (!is_array($ips)) {
                $ips = [$ips];
            }

            foreach ($ips as $ip) {
                $ip = trim((string)$ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !str_starts_with($ip, '127.')) {
                    return $ip;
                }
            }
        }

        return '';
    }

    private function getSymconFirstName(): string
    {
        $email = @IPS_GetLicensee();
        if (!is_string($email) || $email === '' || strpos($email, '@') === false) {
            return 'Symcon';
        }

        $username = explode('@', $email)[0];
        $parts = preg_split('/[\._\-]/', $username);
        $first = $parts[0] ?? 'Symcon';

        return ucfirst(strtolower((string)$first));
    }

    private function getInstanceID(): int
    {
        return (int)($this->module->InstanceID ?? 0);
    }

    private function debug(string $topic, string $message, int $level = 4, $data = ''): void
    {
        if (method_exists($this->module, 'HA_Debug')) {
            $this->module->HA_Debug($topic, $message, $level, $data);
        }
    }
}
