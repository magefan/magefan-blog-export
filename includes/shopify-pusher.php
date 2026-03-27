<?php
/**
 * Copyright © Magefan (support@magefan.com). All rights reserved.
 * Please visit Magefan.com for license details (https://magefan.com/end-user-license-agreement).
 */

class MAGESHBL_ShopifyPusher
{
    protected $curl;

    public function execute(string $url, string $data, string $entity) {
        $args = [
            'body' => [
                'data' => $data,
                'entity' => $entity,
            ],
            'timeout' => 15,
        ];

        if (strpos($url,'magefan-blogimport') !== false) {
            $args['headers']['Content-Type'] = 'application/json';
            $dataArray = is_string($data) ? json_decode($data, true) : $data;

            $args['body'] = wp_json_encode([
                'data' => $dataArray,
                'entity' => $entity,
            ]);
        }

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            return wp_json_encode([
                'errorMessage' => $response->get_error_message(),
            ]);
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if (200 !== $response_code) {
            $response_body = wp_remote_retrieve_body($response); // Get the actual error message
            return wp_json_encode([
                'errorMessage' => 'Server Error (' . $response_code . '): ' . $response_body,
            ]);
        }

        $response_body = wp_remote_retrieve_body($response);

        return $response_body;
    }

}
