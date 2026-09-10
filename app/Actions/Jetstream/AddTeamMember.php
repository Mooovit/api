<?php

namespace App\Actions\Jetstream;

use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\Contracts\AddsTeamMembers;
use Laravel\Jetstream\Events\AddingTeamMember;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Rules\Role;

class AddTeamMember implements AddsTeamMembers
{
    /**
     * Add a new team member to the given team.
     *
     * API-023 changes vs the stock Jetstream action:
     *  - only the addressed account may consume the signed accept link (see
     *    the guard at the top of add());
     *  - the account is looked up case-insensitively (an invitation may be
     *    addressed to Foo@X.com while the account registered as foo@x.com);
     *  - pending invitations for the member's address are pruned after a
     *    successful add, so stale links can't later fail with a confusing
     *    "already belongs to the team" error;
     *  - (via the SetCurrentTeamOnJoin listener on TeamMemberAdded) the new
     *    member's current team is repaired when NULL or dangling.
     *
     * @param  mixed  $user
     * @param  mixed  $team
     * @param  string  $email
     * @param  string|null  $role
     * @return void
     */
    public function add($user, $team, string $email, string $role = null)
    {
        /* API-023: the vendor accept controller calls this with the team
           OWNER as $user, while the session user is whoever clicked the
           signed link. Every other call path (direct add from the member
           manager) passes the session user itself. So a session user
           differing from $user proves the accept path — and only the
           addressed account may consume it. Short-circuit with a redirect
           + danger banner; nothing is mutated and the invitation stays
           pending. */
        $clicker = Auth::user();

        if ($clicker && $clicker->id !== $user->id
            && strtolower($clicker->email) !== strtolower($email)) {
            throw new HttpResponseException(
                redirect()->route('dashboard')->dangerBanner(
                    __('This invitation was sent to :email. Sign in with that account to accept it.', [
                        'email' => $email,
                    ])
                )
            );
        }

        Gate::forUser($user)->authorize('addTeamMember', $team);

        $this->validate($team, $email, $role);

        $newTeamMember = User::whereRaw('lower(email) = ?', [strtolower($email)])
            ->firstOrFail();

        AddingTeamMember::dispatch($team, $newTeamMember);

        $team->users()->attach(
            $newTeamMember, ['role' => $role]
        );

        TeamInvitation::where('team_id', $team->id)
            ->whereRaw('lower(email) = ?', [strtolower($email)])
            ->delete();

        TeamMemberAdded::dispatch($team, $newTeamMember);
    }

    /**
     * Validate the add member operation.
     *
     * @param  mixed  $team
     * @param  string  $email
     * @param  string|null  $role
     * @return void
     */
    protected function validate($team, string $email, ?string $role)
    {
        Validator::make([
            'email' => $email,
            'role' => $role,
        ], $this->rules(), [
            'email.exists' => __('We were unable to find a registered user with this email address.'),
        ])->after(
            $this->ensureUserIsNotAlreadyOnTeam($team, $email)
        )->validateWithBag('addTeamMember');
    }

    /**
     * Get the validation rules for adding a team member.
     *
     * @return array
     */
    protected function rules()
    {
        return array_filter([
            'email' => [
                'required',
                'email',
                /* API-023: case-insensitive `exists:users` — SQLite/MySQL
                   equality differs in case handling, the address is matched
                   lowercased everywhere since API-023. */
                function ($attribute, $value, $fail) {
                    if (! User::whereRaw('lower(email) = ?', [strtolower((string) $value)])->exists()) {
                        $fail(__('We were unable to find a registered user with this email address.'));
                    }
                },
            ],
            'role' => Jetstream::hasRoles()
                            ? ['required', 'string', new Role]
                            : null,
        ]);
    }

    /**
     * Ensure that the user is not already on the team.
     *
     * @param  mixed  $team
     * @param  string  $email
     * @return \Closure
     */
    protected function ensureUserIsNotAlreadyOnTeam($team, string $email)
    {
        return function ($validator) use ($team, $email) {
            $validator->errors()->addIf(
                $team->hasUserWithEmail($email),
                'email',
                __('This user already belongs to the team.')
            );
        };
    }
}
