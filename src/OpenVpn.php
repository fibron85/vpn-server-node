<?php

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2019, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace LC\Node;

use DateTime;
use DateTimeZone;
use LC\Node\HttpClient\ServerClient;
use RuntimeException;

class OpenVpn
{
    // CentOS
    const LIBEXEC_DIR = '/usr/libexec/vpn-server-node';

    /** @var string */
    private $vpnConfigDir;

    /**
     * @param string $vpnConfigDir
     */
    public function __construct($vpnConfigDir)
    {
        FileIO::createDir($vpnConfigDir, 0700);
        $this->vpnConfigDir = $vpnConfigDir;
    }

    /**
     * @param string $vpnTlsDir
     * @param string $commonName
     *
     * @return void
     */
    public function generateKeys(ServerClient $serverClient, $vpnTlsDir, $commonName)
    {
        FileIO::createDir($vpnTlsDir, 0700);
        $certData = $serverClient->postRequireArray('add_server_certificate', ['common_name' => $commonName]);

        $certFileMapping = [
            'ca' => sprintf('%s/ca.crt', $vpnTlsDir),
            'certificate' => sprintf('%s/server.crt', $vpnTlsDir),
            'private_key' => sprintf('%s/server.key', $vpnTlsDir),
            'tls_crypt' => sprintf('%s/tls-crypt.key', $vpnTlsDir),
        ];

        foreach ($certFileMapping as $k => $v) {
            FileIO::writeFile($v, $certData[$k], 0600);
        }
    }

    /**
     * @param string $vpnUser
     * @param string $vpnGroup
     *
     * @return void
     */
    public function writeProfiles(ServerClient $serverClient, $vpnUser, $vpnGroup)
    {
        $profileList = $serverClient->getRequireArray('profile_list');

        $profileIdList = array_keys($profileList);
        foreach ($profileIdList as $profileId) {
            $profileConfigData = $profileList[$profileId];
            $profileConfigData['_user'] = $vpnUser;
            $profileConfigData['_group'] = $vpnGroup;
            $profileConfig = new ProfileConfig($profileConfigData);
            $this->writeProfile($profileId, $profileConfig);

            // generate a CN based on date and profile, instance
            $dateTime = new DateTime('now', new DateTimeZone('UTC'));
            $dateString = $dateTime->format('YmdHis');
            $cn = sprintf('%s.%s', $dateString, $profileId);
            $vpnTlsDir = sprintf('%s/tls/%s', $this->vpnConfigDir, $profileId);

            $this->generateKeys($serverClient, $vpnTlsDir, $cn);
        }
    }

    /**
     * @param string $profileId
     *
     * @return void
     */
    public function writeProfile($profileId, ProfileConfig $profileConfig)
    {
        $range = new IP($profileConfig->getItem('range'));
        $range6 = new IP($profileConfig->getItem('range6'));
        $processCount = \count($profileConfig->getItem('vpnProtoPorts'));

        $allowedProcessCount = [1, 2, 4, 8, 16];
        if (!\in_array($processCount, $allowedProcessCount, true)) {
            throw new RuntimeException('"vpnProtoPorts" must contain 1,2,4,8 or 16 entries');
        }
        $splitRange = $range->split($processCount);
        $splitRange6 = $range6->split($processCount);

        $managementIp = $profileConfig->getItem('managementIp');
        $profileNumber = $profileConfig->getItem('profileNumber');

        $processConfig = [
            'managementIp' => $managementIp,
        ];

        for ($i = 0; $i < $processCount; ++$i) {
            list($proto, $port) = self::getProtoPort($profileConfig->getItem('vpnProtoPorts'), $profileConfig->getItem('listen'))[$i];
            $processConfig['range'] = $splitRange[$i];
            $processConfig['range6'] = $splitRange6[$i];
            $processConfig['dev'] = sprintf('tun-%d-%d', $profileConfig->getItem('profileNumber'), $i);
            $processConfig['proto'] = $proto;
            $processConfig['port'] = $port;
            $processConfig['local'] = $profileConfig->getItem('listen');
            $processConfig['managementPort'] = 11940 + $this->toPort($profileNumber, $i);
            $processConfig['configName'] = sprintf(
                '%s-%d.conf',
                $profileId,
                $i
            );

            $this->writeProcess($profileId, $profileConfig, $processConfig);
        }
    }

    /**
     * @param string $listenAddress
     * @param string $proto
     *
     * @return string
     */
    private static function getFamilyProto($listenAddress, $proto)
    {
        $v6 = false !== strpos($listenAddress, ':');
        if ('udp' === $proto) {
            return $v6 ? 'udp6' : 'udp';
        }
        if ('tcp' === $proto) {
            return $v6 ? 'tcp6-server' : 'tcp-server';
        }

        throw new RuntimeException('only "tcp" and "udp" are supported as protocols');
    }

    /**
     * @param string $listenAddress
     *
     * @return array
     */
    private static function getProtoPort(array $vpnProcesses, $listenAddress)
    {
        $convertedPortProto = [];

        foreach ($vpnProcesses as $vpnProcess) {
            list($proto, $port) = explode('/', $vpnProcess);
            $convertedPortProto[] = [self::getFamilyProto($listenAddress, $proto), $port];
        }

        return $convertedPortProto;
    }

    /**
     * @param string $profileId
     *
     * @return void
     */
    private function writeProcess($profileId, ProfileConfig $profileConfig, array $processConfig)
    {
        $tlsDir = sprintf('tls/%s', $profileId);

        $rangeIp = new IP($processConfig['range']);
        $range6Ip = new IP($processConfig['range6']);

        // static options
        $serverConfig = [
            'verb 3',
            'dev-type tun',
            sprintf('user %s', $profileConfig->getItem('_user')),
            sprintf('group %s', $profileConfig->getItem('_group')),
            'topology subnet',
            'persist-key',
            'persist-tun',
            'remote-cert-tls client',
            'tls-version-min 1.2',
            'tls-cipher TLS-ECDHE-RSA-WITH-AES-256-GCM-SHA384',
            'dh none', // Only ECDHE
            'ncp-ciphers AES-256-GCM',  // only AES-256-GCM
            'cipher AES-256-GCM',       // only AES-256-GCM
            'auth none',
            sprintf('client-connect %s/client-connect', self::LIBEXEC_DIR),
            sprintf('client-disconnect %s/client-disconnect', self::LIBEXEC_DIR),
            sprintf('ca %s/ca.crt', $tlsDir),
            sprintf('cert %s/server.crt', $tlsDir),
            sprintf('key %s/server.key', $tlsDir),
            sprintf('server %s %s', $rangeIp->getNetwork(), $rangeIp->getNetmask()),
            sprintf('server-ipv6 %s', $range6Ip->getAddressPrefix()),
            sprintf('max-clients %d', $rangeIp->getNumberOfHosts() - 1),
            'script-security 2',
            sprintf('dev %s', $processConfig['dev']),
            sprintf('port %d', $processConfig['port']),
            sprintf('management %s %d', $processConfig['managementIp'], $processConfig['managementPort']),
            sprintf('setenv PROFILE_ID %s', $profileId),
            sprintf('proto %s', $processConfig['proto']),
            sprintf('local %s', $processConfig['local']),
        ];

        if (!$profileConfig->getItem('enableLog')) {
            $serverConfig[] = 'log /dev/null';
        }

        if ('tcp-server' === $processConfig['proto'] || 'tcp6-server' === $processConfig['proto']) {
            $serverConfig[] = 'tcp-nodelay';
        }

        if ('udp' === $processConfig['proto'] || 'udp6' === $processConfig['proto']) {
            // notify the clients to reconnect to the exact same OpenVPN process
            // when the OpenVPN process restarts...
            $serverConfig[] = 'keepalive 10 60';
            $serverConfig[] = 'explicit-exit-notify 1';
            // also ask the clients on UDP to tell us when they leave...
            // https://github.com/OpenVPN/openvpn/commit/422ecdac4a2738cd269361e048468d8b58793c4e
            $serverConfig[] = 'push "explicit-exit-notify 1"';
        }

        if ('tls-crypt' === $profileConfig->getItem('tlsProtection')) {
            $serverConfig[] = sprintf('tls-crypt %s/tls-crypt.key', $tlsDir);
        }

        // Routes
        $serverConfig = array_merge($serverConfig, self::getRoutes($profileConfig));

        // DNS
        $serverConfig = array_merge($serverConfig, self::getDns($rangeIp, $range6Ip, $profileConfig));

        // Client-to-client
        $serverConfig = array_merge($serverConfig, self::getClientToClient($profileConfig));

        sort($serverConfig, SORT_STRING);

        $serverConfig = array_merge(
            [
                '#',
                '# OpenVPN Server Configuration',
                '#',
                '# ******************************************',
                '# * THIS FILE IS GENERATED, DO NOT MODIFY! *',
                '# ******************************************',
                '#',
            ],
            $serverConfig
        );

        $configFile = sprintf('%s/%s', $this->vpnConfigDir, $processConfig['configName']);

        FileIO::writeFile($configFile, implode(PHP_EOL, $serverConfig), 0600);
    }

    /**
     * @return array
     */
    private static function getRoutes(ProfileConfig $profileConfig)
    {
        if ($profileConfig->getItem('defaultGateway')) {
            $redirectFlags = ['def1', 'ipv6'];
            if ($profileConfig->hasItem('blockLan') && $profileConfig->getItem('blockLan')) {
                $redirectFlags[] = 'block-local';
            }

            return [
                sprintf('push "redirect-gateway %s"', implode(' ', $redirectFlags)),
            ];
        }

        // Always set a route to the remote host through the client's default
        // gateway to avoid problems when the "split routes" pushed also
        // contain a range with the public IP address of the VPN server.
        // When connecting to a VPN server _over_ IPv6, OpenVPN takes care of
        // this all by itself by setting a /128 through the client's original
        // IPv6 gateway
        $routeConfig = [
            'push "route remote_host 255.255.255.255 net_gateway"',
        ];

        // there may be some routes specified, push those, and not the default
        foreach ($profileConfig->getSection('routes')->toArray() as $route) {
            $routeIp = new IP($route);
            if (6 === $routeIp->getFamily()) {
                // IPv6
                $routeConfig[] = sprintf('push "route-ipv6 %s"', $routeIp->getAddressPrefix());
            } else {
                // IPv4
                $routeConfig[] = sprintf('push "route %s %s"', $routeIp->getAddress(), $routeIp->getNetmask());
            }
        }

        return $routeConfig;
    }

    /**
     * @return array
     */
    private static function getDns(IP $rangeIp, IP $range6Ip, ProfileConfig $profileConfig)
    {
        $dnsEntries = [];
        if ($profileConfig->getItem('defaultGateway')) {
            // prevent DNS leakage on Windows when VPN is default gateway
            $dnsEntries[] = 'push "block-outside-dns"';
        }
        $dnsList = $profileConfig->getSection('dns')->toArray();
        foreach ($dnsList as $dnsAddress) {
            // replace the macros by IP addresses (LOCAL_DNS)
            if ('@GW4@' === $dnsAddress) {
                $dnsAddress = $rangeIp->getFirstHost();
            }
            if ('@GW6@' === $dnsAddress) {
                $dnsAddress = $range6Ip->getFirstHost();
            }
            $dnsEntries[] = sprintf('push "dhcp-option DNS %s"', $dnsAddress);
        }

        return $dnsEntries;
    }

    /**
     * @return array
     */
    private static function getClientToClient(ProfileConfig $profileConfig)
    {
        if (!$profileConfig->getItem('clientToClient')) {
            return [];
        }

        $rangeIp = new IP($profileConfig->getItem('range'));
        $range6Ip = new IP($profileConfig->getItem('range6'));

        return [
            'client-to-client',
            sprintf('push "route %s %s"', $rangeIp->getAddress(), $rangeIp->getNetmask()),
            sprintf('push "route-ipv6 %s"', $range6Ip->getAddressPrefix()),
        ];
    }

    /**
     * @param int $profileNumber
     * @param int $processNumber
     *
     * @return int
     */
    private function toPort($profileNumber, $processNumber)
    {
        // we have 2^16 - 11940 ports available for management ports, so let's
        // say we have 2^14 ports available to distribute over profiles and
        // processes, let's take 12 bits, so we have 64 profiles with each 64
        // processes...
        return ($profileNumber - 1 << 6) | $processNumber;
    }
}
