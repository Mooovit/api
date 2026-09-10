<template>
    <app-layout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight" v-if="team">
                Dashboard — {{ team.name }}
            </h2>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight" v-else>
                Dashboard
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <div v-if="!team" class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6 text-gray-600">
                    No team yet — create one from the team menu above.
                </div>

                <template v-else>
                    <!-- Key figures -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-4">
                            <div class="text-sm font-medium text-gray-500">Revision</div>
                            <div class="mt-1 text-2xl font-semibold text-gray-800">{{ revision }}</div>
                            <div class="text-xs text-gray-400">bumped on every change</div>
                        </div>
                        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-4">
                            <div class="text-sm font-medium text-gray-500">Last activity</div>
                            <div class="mt-1 text-sm font-medium text-gray-800">{{ formatDateTime(last_activity_at) }}</div>
                            <div class="text-xs text-gray-400">newest change or stocktake</div>
                        </div>
                        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-4">
                            <div class="text-sm font-medium text-gray-500">Devices last seen</div>
                            <div class="mt-1 text-sm font-medium text-gray-800">{{ formatDateTime(last_device_seen_at) }}</div>
                            <div class="text-xs text-gray-400">
                                <inertia-link class="text-indigo-600 hover:underline" :href="route('devices.index')">
                                    manage devices
                                </inertia-link>
                            </div>
                        </div>
                        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-4">
                            <div class="text-sm font-medium text-gray-500">Last backup</div>
                            <div class="mt-1 text-sm font-medium text-gray-800" v-if="last_backup">
                                {{ formatDateTime(last_backup.created_at) }}
                                <span v-if="last_backup.remote" class="text-green-600" title="copied to bucket">☁</span>
                            </div>
                            <div class="mt-1 text-sm font-medium text-gray-400" v-else>none yet</div>
                            <div class="text-xs text-gray-400">
                                <inertia-link class="text-indigo-600 hover:underline"
                                              :href="route('backups.index', { team: team.id })">
                                    manage backups
                                </inertia-link>
                            </div>
                        </div>
                    </div>

                    <!-- Counts + statuses -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-4 sm:p-6">
                            <h3 class="font-semibold text-gray-800">Inventory</h3>
                            <div class="mt-3 grid grid-cols-4 gap-4 text-center">
                                <div>
                                    <div class="text-2xl font-semibold text-gray-800">{{ counts.items }}</div>
                                    <div class="text-xs text-gray-500">items</div>
                                </div>
                                <div>
                                    <div class="text-2xl font-semibold text-gray-800">{{ counts.locations }}</div>
                                    <div class="text-xs text-gray-500">locations</div>
                                </div>
                                <div>
                                    <div class="text-2xl font-semibold text-gray-800">{{ counts.statuses }}</div>
                                    <div class="text-xs text-gray-500">statuses</div>
                                </div>
                                <div>
                                    <div class="text-2xl font-semibold text-gray-800">{{ counts.labels }}</div>
                                    <div class="text-xs text-gray-500">labels</div>
                                </div>
                            </div>

                            <div class="mt-4 flex flex-wrap gap-2" v-if="status_breakdown.length">
                                <span v-for="status in status_breakdown" :key="status.id"
                                      class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-700">
                                    {{ status.name }}
                                    <span class="ml-1 text-indigo-600">{{ status.items_count }}</span>
                                </span>
                            </div>
                        </div>

                        <!-- Savepoint stack state -->
                        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-4 sm:p-6">
                            <h3 class="font-semibold text-gray-800">Backups</h3>
                            <div class="mt-3 space-y-2 text-sm text-gray-600">
                                <p>
                                    <span class="font-medium text-gray-800">Last savepoint:</span>
                                    <template v-if="last_backup">
                                        {{ formatDateTime(last_backup.created_at) }}
                                        ({{ humanSize(last_backup.size) }}<span v-if="last_backup.remote">, bucket copy ✓</span>)
                                    </template>
                                    <template v-else>none yet</template>
                                </p>
                                <p>
                                    <span class="font-medium text-gray-800">Auto-backup schedule:</span>
                                    <template v-if="schedule">
                                        {{ schedule.frequency }},
                                        <span :class="schedule.enabled ? 'text-green-600' : 'text-gray-400'">
                                            {{ schedule.enabled ? 'enabled' : 'disabled' }}
                                        </span>
                                        <span v-if="schedule.next_run_at">
                                            — next run {{ formatDateTime(schedule.next_run_at) }}
                                        </span>
                                    </template>
                                    <template v-else>not configured</template>
                                    <inertia-link class="text-indigo-600 hover:underline ml-1"
                                                  :href="route('backups.schedules.show', { team: team.id })">
                                        edit
                                    </inertia-link>
                                </p>
                                <p>
                                    <span class="font-medium text-gray-800">S3 offload:</span>
                                    <template v-if="s3_configured">
                                        <span class="text-green-600">configured</span>
                                        <span class="font-mono text-gray-500"> ({{ s3_bucket }})</span>
                                    </template>
                                    <template v-else>
                                        <span class="text-gray-400">not configured</span>
                                    </template>
                                    <inertia-link class="text-indigo-600 hover:underline ml-1"
                                                  :href="route('backups.s3.show', { team: team.id })">
                                        configure
                                    </inertia-link>
                                </p>
                                <p>
                                    <span class="font-medium text-gray-800">Cold-storage sheet:</span>
                                    printable QR archive of the whole team
                                    <a class="text-indigo-600 hover:underline ml-1" target="_blank"
                                       :href="route('backups.cold-storage.pdf', { team: team.id })">
                                        print
                                    </a>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Latest items -->
                    <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-4 sm:p-6">
                        <h3 class="font-semibold text-gray-800">Latest items</h3>
                        <table class="mt-3 w-full text-sm" v-if="items.length">
                            <thead>
                                <tr class="text-left text-gray-500">
                                    <th class="py-2 pr-4 font-medium">Name</th>
                                    <th class="py-2 pr-4 font-medium">Status</th>
                                    <th class="py-2 pr-4 font-medium">Location</th>
                                    <th class="py-2 font-medium">Updated</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="item in items" :key="item.id" class="border-t border-gray-100">
                                    <td class="py-2 pr-4 font-medium text-gray-800">{{ item.name }}</td>
                                    <td class="py-2 pr-4">
                                        <span v-if="item.status"
                                              class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-50 text-indigo-700">
                                            {{ item.status }}
                                        </span>
                                        <span v-else class="text-gray-400">—</span>
                                    </td>
                                    <td class="py-2 pr-4 text-gray-600">{{ item.location || '—' }}</td>
                                    <td class="py-2 text-gray-600">{{ formatDateTime(item.updated_at) }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-else class="mt-3 text-sm text-gray-500">
                            No items yet.
                        </p>
                    </div>
                </template>
            </div>
        </div>
    </app-layout>
</template>

<script>
    import AppLayout from '@/Layouts/AppLayout'

    export default {
        components: {
            AppLayout,
        },

        props: [
            'team',
            'revision',
            'counts',
            'status_breakdown',
            'items',
            'last_activity_at',
            'last_device_seen_at',
            'last_backup',
            'schedule',
            's3_configured',
            's3_bucket',
        ],

        methods: {
            formatDateTime(value) {
                return value ? new Date(value).toLocaleString() : '—';
            },

            humanSize(bytes) {
                if (bytes < 1024) return bytes + ' B';
                if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
                return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
            },
        },
    }
</script>
