<?php

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2019, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace LC\Node\Tests;

use LC\Node\HttpClient\ServerClient;
use LC\Node\OpenVpn;
use PHPUnit\Framework\TestCase;

class OpenVpnTest extends TestCase
{
    /** @var OpenVpn */
    private $openVpn;

    private $serverClient;

    private $tmpDir;

    public function setUp(): void
    {
        // create temporary directory
        $tmpDir = sprintf('%s/%s', sys_get_temp_dir(), bin2hex(random_bytes(16)));
        mkdir($tmpDir, 0700, true);
        $this->tmpDir = $tmpDir;
        $this->openVpn = new OpenVpn($tmpDir, '/usr/libexec/vpn-server-node', 'openvpn', 'openvpn');
        $this->serverClient = new ServerClient(
            new TestHttpClient(),
            'openVpnServerClient'
        );
    }

    public function testWriteProfiles()
    {
        $this->openVpn->writeProfiles($this->serverClient);
        $this->assertSame(
            trim(file_get_contents(sprintf('%s/default-0.conf', $this->tmpDir))),
            trim(file_get_contents(sprintf('%s/data/default-0.conf', __DIR__)))
        );
    }
}
