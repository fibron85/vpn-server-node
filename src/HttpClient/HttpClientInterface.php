<?php

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2019, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace LC\Node\HttpClient;

interface HttpClientInterface
{
    /**
     * @param string $requestUri
     *
     * @return array{0: int, 1: string}
     */
    public function get($requestUri);

    /**
     * @param string $requestUri
     * @param array  $postData
     *
     * @return array{0: int, 1: string}
     */
    public function post($requestUri, array $postData = []);
}
