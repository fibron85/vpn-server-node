<?php

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2019, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace LC\Node\Config;

class NodeConfig extends Config
{
    /**
     * @return string
     */
    public function getApiUrl()
    {
        return $this->requireString('apiUrl');
    }
}
