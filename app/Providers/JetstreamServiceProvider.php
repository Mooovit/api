<?php

namespace App\Providers;

use App\Actions\Jetstream\AddTeamMember;
use App\Actions\Jetstream\CreateTeam;
use App\Actions\Jetstream\DeleteTeam;
use App\Actions\Jetstream\DeleteUser;
use App\Actions\Jetstream\InviteTeamMember;
use App\Actions\Jetstream\RemoveTeamMember;
use App\Actions\Jetstream\UpdateTeamName;
use Illuminate\Support\ServiceProvider;
use Laravel\Jetstream\Jetstream;

class JetstreamServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->configurePermissions();

        Jetstream::createTeamsUsing(CreateTeam::class);
        Jetstream::updateTeamNamesUsing(UpdateTeamName::class);
        Jetstream::addTeamMembersUsing(AddTeamMember::class);
        Jetstream::inviteTeamMembersUsing(InviteTeamMember::class);
        Jetstream::removeTeamMembersUsing(RemoveTeamMember::class);
        Jetstream::deleteTeamsUsing(DeleteTeam::class);
        Jetstream::deleteUsersUsing(DeleteUser::class);
    }

    /**
     * Configure the roles and permissions that are available within the application.
     *
     * @return void
     */
    protected function configurePermissions()
    {
        Jetstream::defaultApiTokenPermissions(['box:write', 'box:read', 'item:read', 'item:write']);

        Jetstream::role('admin', __('Administrator'), [
            'box:write',
            'location:write',
            'status:write',
            'item:write',
            'box:read',
            'location:read',
            'status:read',
            'item:read',
        ])->description(__('Administrator can do all actions'));

        Jetstream::role('Read Only', __('Viewer'), [
            'box:read',
            'location:read',
            'status:read',
            'item:read',
        ])->description(__('Viewer can do all read-only actions'));
    }

    protected function permissions() {
        Jetstream::permissions([
            'box:write',
            'box:read',
            'location:write',
            'location:read',
            'status:write',
            'status:read',
            'item:write',
            'item:read',
        ]);
    }
}
