<?php

namespace App\Services;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Illuminate\Support\Carbon;

/**
 * The S3 operations the backup feature needs (API-021), wrapped in one seam:
 * BackupService, BackupController and TeamS3Controller talk to this class —
 * never to the SDK directly — so tests fake the seam instead of the network.
 * Every method throws `AwsException` on upstream failure (the callers map
 * that to 422/502 as appropriate).
 */
class S3BackupClient
{
    /**
     * @var S3Client
     */
    private $client;

    /**
     * @var string
     */
    private $bucket;

    /**
     * @param S3Client $client
     * @param string $bucket
     * @return void
     */
    public function __construct(S3Client $client, string $bucket)
    {
        $this->client = $client;
        $this->bucket = $bucket;
    }

    /**
     * Cheap, definitive connectivity check (also validates credentials and
     * bucket existence/permission).
     *
     * @return void
     * @throws AwsException
     */
    public function headBucket(): void
    {
        $this->client->headBucket(['Bucket' => $this->bucket]);
    }

    /**
     * Upload one backup zip.
     *
     * @param string $key
     * @param string $bytes
     * @return void
     * @throws AwsException
     */
    public function put(string $key, string $bytes): void
    {
        $this->client->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => $bytes,
        ]);
    }

    /**
     * List the objects under a prefix, newest first.
     *
     * @param string $prefix
     * @return array<int, array{key: string, size: int, last_modified: Carbon}>
     * @throws AwsException
     */
    public function listObjects(string $prefix): array
    {
        $objects = [];

        $pages = $this->client->getPaginator('ListObjectsV2', [
            'Bucket' => $this->bucket,
            'Prefix' => $prefix,
        ]);

        foreach ($pages as $page) {
            foreach ($page->get('Contents') ?? [] as $object) {
                $objects[] = [
                    'key' => $object['Key'],
                    'size' => (int) $object['Size'],
                    'last_modified' => Carbon::instance($object['LastModified']),
                ];
            }
        }

        usort($objects, fn (array $a, array $b) => $b['last_modified'] <=> $a['last_modified']);

        return $objects;
    }

    /**
     * The bucket's lifecycle rules — the "bucket rules" display. `null`
     * means "no rules to show": no configuration on the bucket, or the
     * store/IAM doesn't answer the call — per the ticket that is not an
     * error, the UI just shows there are none.
     *
     * @return array|null
     */
    public function getLifecycle(): ?array
    {
        try {
            $result = $this->client->getBucketLifecycleConfiguration([
                'Bucket' => $this->bucket,
            ]);
        } catch (AwsException $e) {
            return null;
        }

        return $result->get('Rules') ?: null;
    }
}
