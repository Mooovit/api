<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\TeamS3Config;
use App\Services\BackupService;
use App\Services\S3BackupClientFactory;
use Aws\Exception\AwsException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-team S3 offload credentials (API-021). The credentials act on the
 * request-context team (API-015's effectiveTeam). Upsert via POST (no PATCH
 * on this host), validated by a real connectivity check (HeadBucket) before
 * anything is persisted — dead credentials never land in the DB. The secret
 * is encrypted at rest (`encrypted` cast) and NEVER returned: the payload
 * shapes here are explicit, and the model also hides `secret_key`.
 */
class TeamS3Controller extends Controller
{
    /**
     * @var S3BackupClientFactory
     */
    private $s3;

    /**
     * @param S3BackupClientFactory $s3
     * @return void
     */
    public function __construct(S3BackupClientFactory $s3)
    {
        $this->s3 = $s3;
    }

    /**
     * Team-level authorization on the request-context team.
     *
     * @param Request $request
     * @param string $permission 'item:read' or 'item:write'
     * @return Team
     * @throws AuthorizationException
     */
    private function authorizeTeam(Request $request, string $permission): Team
    {
        $user = $request->user();
        $team = $user->effectiveTeam();

        if (!$team instanceof Team
            || !$user->hasTeamPermission($team, $permission)
            || !$user->tokenCan($permission)
        ) {
            throw new AuthorizationException();
        }

        return $team;
    }

    /**
     * The safe, client-facing shape — the secret (and the access key) are
     * configuration details the clients never need back.
     *
     * @param TeamS3Config $config
     * @return array
     */
    private function payload(TeamS3Config $config): array
    {
        return [
            'configured' => true,
            'bucket' => $config->bucket,
            'region' => $config->region,
            'endpoint' => $config->endpoint,
            'prefix' => $config->prefix,
        ];
    }

    /**
     * POST api/team/s3 — upsert the team's S3 credentials. The payload is
     * probed with a HeadBucket first; an upstream failure is a 422 on
     * `bucket` (a bad config is a user-input problem) and persists nothing.
     *
     * @param Request $request
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function store(Request $request): JsonResponse
    {
        $team = $this->authorizeTeam($request, 'item:write');

        $data = $request->validate([
            'bucket' => ['required', 'string', 'max:255'],
            'region' => ['required', 'string', 'max:64'],
            'access_key' => ['required', 'string', 'max:255'],
            'secret_key' => ['required', 'string'],
            'endpoint' => ['nullable', 'string', 'max:255'],
            'prefix' => ['nullable', 'string', 'max:255'],
        ]);

        $data['prefix'] = $data['prefix'] ?? "backups/{$team->id}";

        /* Probe before persisting — a model instance applies the encrypted
           cast so the factory reads the secret back exactly as saved */
        $probe = new TeamS3Config();
        $probe->forceFill($data);

        try {
            $this->s3->forConfig($probe)->headBucket();
        } catch (AwsException $e) {
            return response()->json(['bucket' => [$e->getMessage()]], 422);
        }

        $config = $team->s3Config()->firstOrNew();
        $config->forceFill($data);
        $config->save();

        return response()->json($this->payload($config));
    }

    /**
     * GET api/team/s3 — the team's config without its secret. Not
     * configured → 422 (mirrors the bucket listing behavior).
     *
     * @param Request $request
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function show(Request $request): JsonResponse
    {
        $team = $this->authorizeTeam($request, 'item:write');

        $config = $team->s3Config()->first();
        if (!$config) {
            return response()->json(
                ['s3' => ['No S3 bucket configured for this team.']],
                422
            );
        }

        return response()->json($this->payload($config));
    }

    /**
     * DELETE api/team/s3 — drop the config; the local retention (last 7)
     * applies again on the next backup. Already-copied bucket objects stay
     * (disaster recovery — the bucket is the user's domain).
     *
     * @param Request $request
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function destroy(Request $request): JsonResponse
    {
        $team = $this->authorizeTeam($request, 'item:write');

        optional($team->s3Config()->first())->delete();

        return response()->json(['success' => 'success']);
    }

    /**
     * GET api/team/s3/rules — the applicable retention rules, computed:
     * the local last-7 (always), and the bucket lifecycle rules fetched
     * from the store when S3 is configured (`null` when the bucket has
     * none or the store doesn't answer — not an error).
     *
     * @param Request $request
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function rules(Request $request): JsonResponse
    {
        $team = $this->authorizeTeam($request, 'item:read');

        $config = $team->s3Config()->first();

        return response()->json([
            'local_retention' => BackupService::RETENTION,
            's3_configured' => (bool) $config,
            's3_retention' => $config ? 'per bucket lifecycle' : null,
            'lifecycle' => $config
                ? $this->s3->forConfig($config)->getLifecycle()
                : null,
            'note' => $config
                ? sprintf(
                    'New backups are copied to the bucket; local copies still rotate at the last %d per team. Bucket copies follow the bucket lifecycle rules.',
                    BackupService::RETENTION
                )
                : sprintf(
                    'No S3 bucket configured — only the last %d backups per team are kept.',
                    BackupService::RETENTION
                ),
        ]);
    }
}
