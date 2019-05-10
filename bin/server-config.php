<?php

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2019, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

require_once dirname(__DIR__).'/vendor/autoload.php';
$baseDir = dirname(__DIR__);

use LC\Node\Config\NodeConfig;
use LC\Node\FileIO;
use LC\Node\HttpClient\CurlHttpClient;
use LC\Node\HttpClient\ServerClient;
use LC\Node\OpenVpn;

try {
    $configDir = sprintf('%s/config', $baseDir);
    $nodeConfig = NodeConfig::fromFile(sprintf('%s/config.php', $configDir));

    // auto detect user/group to use for OpenVPN process
    if (FileIO::exists('/etc/redhat-release')) {
        // RHEL/CentOS/Fedora
        echo 'OS Detected: RHEL/CentOS/Fedora...'.PHP_EOL;
        $libExecDir = '/usr/libexec/vpn-server-node';
        $vpnUser = 'openvpn';
        $vpnGroup = 'openvpn';
    } elseif (FileIO::exists('/etc/debian_version')) {
        // Debian/Ubuntu
        echo 'OS Detected: Debian/Ubuntu...'.PHP_EOL;
        $libExecDir = '/usr/lib/vpn-server-node';
        $vpnUser = 'nobody';
        $vpnGroup = 'nogroup';
    } else {
        throw new RuntimeException('only RHEL/CentOS/Fedora or Debian/Ubuntu supported');
    }

    $serverClient = new ServerClient(
        new CurlHttpClient(['vpn-server-node', FileIO::readFile(sprintf('%s/node-api.key', $configDir))]),
        $nodeConfig->getApiUrl()
    );

    $vpnConfigDir = sprintf('%s/openvpn-config', $baseDir);
    $o = new OpenVpn($vpnConfigDir, $libExecDir, $vpnUser, $vpnGroup);
    $o->writeProfiles($serverClient);
} catch (Exception $e) {
    echo sprintf('ERROR: %s', $e->getMessage()).PHP_EOL;
    exit(1);
}
