<?php
/**
 *  Copyright (C) 2016 SURFnet.
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace SURFnet\VPN\Node;

use RuntimeException;
use SURFnet\VPN\Common\FileIO;
use SURFnet\VPN\Common\HttpClient\ServerClient;
use SURFnet\VPN\Common\ProfileConfig;

class OpenVpn
{
    /** @var string */
    private $vpnConfigDir;

    /** @var string */
    private $vpnTlsDir;

    public function __construct($vpnConfigDir, $vpnTlsDir)
    {
        FileIO::createDir($vpnConfigDir, 0700);
        $this->vpnConfigDir = $vpnConfigDir;
        FileIO::createDir($vpnTlsDir, 0700);
        $this->vpnTlsDir = $vpnTlsDir;
    }

    public function generateKeys(ServerClient $serverClient, $commonName, $dhSourceFile)
    {
        $certData = $serverClient->post('add_server_certificate', ['common_name' => $commonName]);

        $certFileMapping = [
            'ca' => sprintf('%s/ca.crt', $this->vpnTlsDir),
            'certificate' => sprintf('%s/server.crt', $this->vpnTlsDir),
            'private_key' => sprintf('%s/server.key', $this->vpnTlsDir),
            'ta' => sprintf('%s/ta.key', $this->vpnTlsDir),
        ];

        foreach ($certFileMapping as $k => $v) {
            FileIO::writeFile($v, $certData[$k], 0600);
        }

        // copy the DH parameter file
        $dhTargetFile = sprintf('%s/dh.pem', $this->vpnTlsDir);
        if (false === copy($dhSourceFile, $dhTargetFile)) {
            throw new RuntimeException('unable to copy DH file');
        }
    }

    public function writeProfile($instanceNumber, $instanceId, $profileId, ProfileConfig $profileConfig)
    {
        $range = new IP($profileConfig->getItem('range'));
        $range6 = new IP($profileConfig->getItem('range6'));
        $processCount = count($profileConfig->getItem('vpnProtoPorts'));

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
            $processConfig['dev'] = sprintf('tun-%d-%d-%d', $instanceNumber, $profileConfig->getItem('profileNumber'), $i);
            $processConfig['proto'] = $proto;
            $processConfig['port'] = $port;
            $processConfig['local'] = $profileConfig->getItem('listen');
            $processConfig['managementPort'] = 11940 + $this->toPort($instanceNumber, $profileNumber, $i);
            $processConfig['configName'] = sprintf(
                '%s-%s-%d.conf',
                $instanceId,
                $profileId,
                $i
            );

            $this->writeProcess($instanceId, $profileId, $profileConfig, $processConfig);
        }
    }

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

    private static function getProtoPort(array $vpnProcesses, $listenAddress)
    {
        $convertedPortProto = [];

        foreach ($vpnProcesses as $vpnProcess) {
            list($proto, $port) = explode('/', $vpnProcess);
            $convertedPortProto[] = [self::getFamilyProto($listenAddress, $proto), $port];
        }

        return $convertedPortProto;
    }

    private function writeProcess($instanceId, $profileId, ProfileConfig $profileConfig, array $processConfig)
    {
        $tlsDir = sprintf('tls/%s/%s', $instanceId, $profileId);

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
            'keepalive 10 60',
            'comp-lzo no',
            'remote-cert-tls client',
            'tls-version-min 1.2',

            // 2.4 only clients: 'tls-cipher TLS-ECDHE-RSA-WITH-AES-256-GCM-SHA384',
            'tls-cipher TLS-ECDHE-RSA-WITH-AES-256-GCM-SHA384:TLS-DHE-RSA-WITH-AES-256-GCM-SHA384',

            'auth SHA256',

            // 2.4 only clients: 'ncp-ciphers AES-256-GCM',
            // 2.4 only clients: 'cipher AES-256-GCM', // also should update the client config to set this, but ncp overrides --cipher
            'cipher AES-256-CBC',
            'client-connect /usr/libexec/vpn-server-node-client-connect',
            'client-disconnect /usr/libexec/vpn-server-node-client-disconnect',
            'push "comp-lzo no"',
            'push "explicit-exit-notify 3"',

            // we probably do NOT want this, it is up to the client to decide
            // about this!
            //'push "persist-key"',
            //'push "persist-tun"',

            sprintf('ca %s/ca.crt', $tlsDir),
            sprintf('cert %s/server.crt', $tlsDir),
            sprintf('key %s/server.key', $tlsDir),
            // 2.4 only clients: 'dh none',   // then we can also remove the complete DH stuff in the init stage!
            sprintf('dh %s/dh.pem', $tlsDir),
            sprintf('server %s %s', $rangeIp->getNetwork(), $rangeIp->getNetmask()),
            sprintf('server-ipv6 %s', $range6Ip->getAddressPrefix()),
            sprintf('max-clients %d', $rangeIp->getNumberOfHosts() - 1),
            sprintf('script-security %d', $profileConfig->getItem('twoFactor') ? 3 : 2),
            sprintf('dev %s', $processConfig['dev']),
            sprintf('port %d', $processConfig['port']),
            sprintf('management %s %d', $processConfig['managementIp'], $processConfig['managementPort']),
            sprintf('setenv INSTANCE_ID %s', $instanceId),
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
            // notify the clients to reconnect when restarting OpenVPN on the server
            // OpenVPN server >= 2.4
            $serverConfig[] = 'explicit-exit-notify 1';
        }

        if ($profileConfig->getItem('twoFactor')) {
            $serverConfig[] = 'auth-gen-token';  // Added in OpenVPN 2.4
            $serverConfig[] = 'auth-user-pass-verify /usr/libexec/vpn-server-node-verify-otp via-env';
        }

        if ($profileConfig->getItem('tlsCrypt')) {
            $serverConfig[] = sprintf('tls-crypt %s/ta.key', $tlsDir);
        } else {
            $serverConfig[] = sprintf('tls-auth %s/ta.key 0', $tlsDir);
        }

        // Routes
        $serverConfig = array_merge($serverConfig, self::getRoutes($profileConfig));

        // DNS
        $serverConfig = array_merge($serverConfig, self::getDns($profileConfig));

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

    private static function getRoutes(ProfileConfig $profileConfig)
    {
        $routeConfig = [];
        if ($profileConfig->getItem('defaultGateway')) {
            // For OpenVPN >= 2.4 client only support:
            //$routeConfig[] = 'push "redirect-gateway def1 ipv6"';

            $routeConfig[] = 'push "redirect-gateway def1 bypass-dhcp"';
            // for Windows clients we need this extra route to mark the TAP adapter as
            // trusted and as having "Internet" access to allow the user to set it to
            // "Home" or "Work" to allow accessing file shares and printers
            // NOTE: this will break OS X tunnelblick because on disconnect it will
            // remove all default routes, including the one set before the VPN
            // was brought up
            //$routeConfig[] = 'push "route 0.0.0.0 0.0.0.0"';

            // for iOS we need this OpenVPN 2.4 "ipv6" flag to redirect-gateway
            // See https://docs.openvpn.net/docs/openvpn-connect/openvpn-connect-ios-faq.html
            $routeConfig[] = 'push "redirect-gateway ipv6"';

            // we use 2000::/3 instead of ::/0 because it seems to break on native IPv6
            // networks where the ::/0 default route already exists
            // XXX: no longer needed in OpenVPN 2.4! But not all our clients are
            // up to date, e.g. NetAidKit...
            $routeConfig[] = 'push "route-ipv6 2000::/3"';
        } else {
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
        }

        return $routeConfig;
    }

    private static function getDns(ProfileConfig $profileConfig)
    {
        // only push DNS if we are the default route
        if (!$profileConfig->getItem('defaultGateway')) {
            return [];
        }

        $dnsEntries = [];
        foreach ($profileConfig->getSection('dns')->toArray() as $dnsAddress) {
            // also add DNS6 for OpenVPN >= 2.4beta2
            if (false !== strpos($dnsAddress, ':')) {
                $dnsEntries[] = sprintf('push "dhcp-option DNS6 %s"', $dnsAddress);
                continue;
            }
            $dnsEntries[] = sprintf('push "dhcp-option DNS %s"', $dnsAddress);
        }

        // prevent DNS leakage on Windows
        $dnsEntries[] = 'push "block-outside-dns"';

        return $dnsEntries;
    }

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

    private function toPort($instanceNumber, $profileNumber, $processNumber)
    {
        // convert an instanceNumber, $profileNumber and $processNumber to a management port

        // instanceId = 6 bits (max 64)
        // profileNumber = 4 bits (max 16)
        // processNumber = 4 bits  (max 16)
        return ($instanceNumber - 1 << 8) | ($profileNumber - 1 << 4) | ($processNumber);
    }
}
