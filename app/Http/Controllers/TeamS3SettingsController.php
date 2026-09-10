<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\TeamS3Config;
use App\Services\BackupService;
use App\Services\S3BackupClientFactory;
use Aws\Exception\AwsException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * S3 offload configuration (server UI) — the API-021 features behind a web
 * page, per team: the credential form (probed with a real HeadBucket before
 * anything persists — dead credentials never land in the DB), the current
 * config without its secret, the applicable retention rules (local last-7
 * always; bucket lifecycle rules when the store answers), and the bucket
 * object listing. The secret is encrypted at rest and never rendered.
 */
class TeamS3SettingsController extends Controller
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
     * Team-level authorization for the web routes.
     *
     * @param Request $request
     * @param Team $team
     * @param string $permission 'item:read' or 'item:write'
     * @return void
     * @throws AuthorizationException
     */
    private function authorizeTeam(Request $request, Team $team, string $permission): void
    {
        $user = $request->user();

        if (!$user->belongsToTeam($team)
            || !$user->hasTeamPermission($team, $permission)
            || !$user->tokenCan($permission)
        ) {
            throw new AuthorizationException();
        }
    }

    /**
     * The page props: config without its secret, the retention rules, and
     * the bucket listing (an upstream problem becomes a displayed error,
     * not a crash — the page stays usable to fix the config).
     *
     * @param Team $team
     * @return array
     */
    private function props(Team $team): array
    {
        $config = $team->s3Config()->first();

        $objects = null;
        $lifecycle = null;
        $bucketError = null;

        if ($config) {
            try {
                $client = $this->s3->forConfig($config);
                $objects = collect($client->listObjects($config->prefix))
                    ->map(fn (array $object) => [
                        'key' => $object['key'],
                        'size' => $object['size'],
                        'last_modified' => $object['last_modified']->toISOString(),
                    ])
                    ->values();
                $lifecycle = $client->getLifecycle();
            } catch (Throwable $e) {
                $bucketError = $e->getMessage();
            }
        }

        return [
            'team' => $team->only(['id', 'name']),
            'retention' => BackupService::RETENTION,
            'config' => $config ? [
                'bucket' => $config->bucket,
                'region' => $config->region,
                'endpoint' => $config->endpoint,
                'prefix' => $config->prefix,
            ] : null,
            'objects' => $objects,
            'lifecycle' => $lifecycle,
            'bucket_error' => $bucketError,
        ];
    }

    /**
     * GET /teams/{team}/backups/s3.
     *
     * @param Request $request
     * @param Team $team
     * @return Response
     * @throws AuthorizationException
     */
    public function show(Request $request, Team $team): Response
    {
        $this->authorizeTeam($request, $team, 'item:read');

        return Inertia::render('Backups/S3', $this->props($team));
    }

    /**
     * POST /teams/{team}/backups/s3 — upsert the team's S3 credentials,
     * probed with HeadBucket first (an upstream failure shows the driver
     * message on the `bucket` field and persists nothing).
     *
     * @param Request $request
     * @param Team $team
     * @return RedirectResponse
     * @throws AuthorizationException
     */
    public function store(Request $request, Team $team): RedirectResponse
    {
        $this->authorizeTeam($request, $team, 'item:write');

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
            return Redirect::back()->withErrors(['bucket' => $e->getMessage()]);
        }

        $config = $team->s3Config()->firstOrNew();
        $config->forceFill($data);
        $config->save();

        return Redirect::route('backups.s3.show', $team)->with('success', 'S3 configuration saved.');
    }

    /**
     * DELETE /teams/{team}/backups/s3 — drop the config; already-copied
     * bucket objects stay (disaster recovery — the bucket is the user's
     * domain).
     *
     * @param Request $request
     * @param Team $team
     * @return RedirectResponse
     * @throws AuthorizationException
     */
    public function destroy(Request $request, Team $team): RedirectResponse
    {
        $this->authorizeTeam($request, $team, 'item:write');

        optional($team->s3Config()->first())->delete();

        return Redirect::route('backups.s3.show', $team)->with('success', 'S3 configuration removed.');
    }
}
