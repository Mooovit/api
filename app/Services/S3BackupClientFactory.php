<?php

namespace App\Services;

use App\Models\TeamS3Config;
use Aws\S3\S3Client;

/**
 * Builds an S3BackupClient from a team's stored credentials (API-021).
 * Credentials live only in the DB row (secret decrypted at build time via
 * the model's `encrypted` cast) — never in config files or the environment.
 * Injected everywhere an S3 client is needed so tests can bind a fake
 * factory and never touch the network.
 */
class S3BackupClientFactory
{
    /**
     * Build a client for one team's config.
     *
     * @param TeamS3Config $config
     * @return S3BackupClient
     */
    public function forConfig(TeamS3Config $config): S3BackupClient
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => $config->region,
            'credentials' => [
                'key' => $config->access_key,
                'secret' => $config->secret_key,
            ],
            'endpoint' => $config->endpoint,
            /* S3-compatible stores (minio & co) want path-style addressing */
            'use_path_style_endpoint' => (bool) $config->endpoint,
        ]);

        return new S3BackupClient($client, $config->bucket);
    }
}
