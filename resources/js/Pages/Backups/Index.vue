<template>
    <app-layout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Backups — {{ team.name }}
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <jet-action-message :on="flashSuccess" class="mr-3">
                    {{ $page.props.flash.success }}
                </jet-action-message>

                <!-- Validation / S3 copy errors -->
                <jet-validation-errors class="mb-4" />

                <!-- Create + links -->
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-4 sm:p-6">
                    <div class="sm:flex sm:items-center sm:justify-between">
                        <div>
                            <h3 class="font-semibold text-gray-800">Savepoints</h3>
                            <p class="text-sm text-gray-500">
                                The last {{ retention }} backups are kept per team<span v-if="s3_configured"> (new ones are also copied to the S3 bucket)</span>.
                            </p>
                        </div>
                        <div class="mt-3 sm:mt-0 flex items-center space-x-2">
                            <inertia-link :href="route('backups.schedules.show', { team: team.id })">
                                <jet-secondary-button type="button">Schedule</jet-secondary-button>
                            </inertia-link>
                            <inertia-link :href="route('backups.s3.show', { team: team.id })">
                                <jet-secondary-button type="button">S3 offload</jet-secondary-button>
                            </inertia-link>
                            <jet-button type="button" :class="{ 'opacity-25': createForm.processing }"
                                        :disabled="createForm.processing" @click="create">
                                Create backup now
                            </jet-button>
                        </div>
                    </div>
                </div>

                <!-- Backup list -->
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-4 sm:p-6">
                    <table class="w-full text-sm" v-if="backups.length">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="py-2 pr-4 font-medium">Created</th>
                                <th class="py-2 pr-4 font-medium">Size</th>
                                <th class="py-2 pr-4 font-medium">Items</th>
                                <th class="py-2 pr-4 font-medium">Locations</th>
                                <th class="py-2 pr-4 font-medium">Statuses</th>
                                <th class="py-2 pr-4 font-medium">Labels</th>
                                <th class="py-2 pr-4 font-medium">Bucket</th>
                                <th class="py-2 font-medium text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="backup in backups" :key="backup.id" class="border-t border-gray-100">
                                <td class="py-2 pr-4 font-medium text-gray-800">{{ formatDateTime(backup.created_at) }}</td>
                                <td class="py-2 pr-4 text-gray-600">{{ humanSize(backup.size) }}</td>
                                <td class="py-2 pr-4 text-gray-600">{{ backup.item_count }}</td>
                                <td class="py-2 pr-4 text-gray-600">{{ backup.location_count }}</td>
                                <td class="py-2 pr-4 text-gray-600">{{ backup.status_count }}</td>
                                <td class="py-2 pr-4 text-gray-600">{{ backup.label_count }}</td>
                                <td class="py-2 pr-4">
                                    <span v-if="backup.remote" class="inline-flex items-center text-green-600">
                                        <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                        copied
                                    </span>
                                    <span v-else class="text-gray-400">—</span>
                                </td>
                                <td class="py-2 text-right whitespace-nowrap">
                                    <a :href="backup.download_url">
                                        <jet-secondary-button type="button">Download</jet-secondary-button>
                                    </a>
                                    <jet-danger-button type="button" class="ml-2" @click="confirmDelete(backup)">
                                        Delete
                                    </jet-danger-button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="text-sm text-gray-500">
                        No backups yet — create the first one above.
                    </p>
                </div>

                <!-- Compare -->
                <jet-form-section @submitted="compare" v-if="backups.length >= 2">
                    <template #title>
                        Compare two savepoints
                    </template>

                    <template #description>
                        What happened going from the base savepoint to the
                        target one (per entity type: added / removed / changed).
                    </template>

                    <template #form>
                        <div class="col-span-6 sm:col-span-3">
                            <jet-label for="base_id" value="Base (from)" />
                            <select id="base_id" v-model="compareForm.base_id"
                                    class="mt-1 block w-full border-gray-300 focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50 rounded-md shadow-sm">
                                <option v-for="backup in backups" :key="backup.id" :value="backup.id">
                                    {{ formatDateTime(backup.created_at) }}
                                </option>
                            </select>
                            <jet-input-error :message="compareForm.errors.base_id" class="mt-2" />
                        </div>

                        <div class="col-span-6 sm:col-span-3">
                            <jet-label for="target_id" value="Target (to)" />
                            <select id="target_id" v-model="compareForm.target_id"
                                    class="mt-1 block w-full border-gray-300 focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50 rounded-md shadow-sm">
                                <option v-for="backup in backups" :key="backup.id" :value="backup.id">
                                    {{ formatDateTime(backup.created_at) }}
                                </option>
                            </select>
                            <jet-input-error :message="compareForm.errors.target_id" class="mt-2" />
                        </div>
                    </template>

                    <template #actions>
                        <jet-button :class="{ 'opacity-25': compareForm.processing }"
                                    :disabled="compareForm.processing">
                            Compare
                        </jet-button>
                    </template>
                </jet-form-section>

                <!-- Compare results -->
                <div v-if="compare" class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-4 sm:p-6 space-y-4">
                    <h3 class="font-semibold text-gray-800">Compare result</h3>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                        <div v-for="(result, type) in compare" :key="type"
                             class="border border-gray-200 rounded-lg p-3">
                            <div class="text-sm font-medium text-gray-500 capitalize">{{ type }}</div>
                            <div class="mt-1 text-sm">
                                <span class="text-green-600">+{{ result.counts.added }}</span>
                                <span class="text-red-600 ml-2">-{{ result.counts.removed }}</span>
                                <span class="text-yellow-600 ml-2">~{{ result.counts.changed }}</span>
                                <span class="text-gray-400 ml-2">={{ result.counts.unchanged }}</span>
                            </div>
                        </div>
                    </div>

                    <div v-for="(result, type) in compare" :key="type + '-detail'">
                        <h4 class="text-sm font-semibold text-gray-700 capitalize mt-2">{{ type }}</h4>
                        <ul class="mt-1 text-sm space-y-1">
                            <li v-for="row in result.added" :key="'a-' + row.id" class="text-green-700">
                                + {{ row.name || row.id }} <span class="text-gray-400">added</span>
                            </li>
                            <li v-for="row in result.removed" :key="'r-' + row.id" class="text-red-700">
                                - {{ row.name || row.id }} <span class="text-gray-400">removed</span>
                            </li>
                            <li v-for="row in result.changed" :key="'c-' + row.id" class="text-yellow-700">
                                ~ <span class="font-mono text-xs">{{ shortId(row.id) }}</span>
                                <span v-for="(change, field) in row.diff" :key="field" class="ml-2">
                                    {{ field }}: “{{ change.from || '∅' }}” → “{{ change.to || '∅' }}”;
                                </span>
                            </li>
                            <li v-if="!result.added.length && !result.removed.length && !result.changed.length"
                                class="text-gray-400">
                                nothing changed
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Delete confirmation -->
        <jet-confirmation-modal :show="backupToDelete !== null" @close="backupToDelete = null">
            <template #title>
                Delete backup
            </template>

            <template #content>
                Delete the savepoint from
                {{ backupToDelete ? formatDateTime(backupToDelete.created_at) : '' }}?
                The zip file is removed from the server. Bucket copies are
                kept.
            </template>

            <template #footer>
                <jet-secondary-button @click="backupToDelete = null">
                    Cancel
                </jet-secondary-button>

                <jet-danger-button class="ml-2" :class="{ 'opacity-25': deleteForm.processing }"
                                   :disabled="deleteForm.processing" @click="remove">
                    Delete
                </jet-danger-button>
            </template>
        </jet-confirmation-modal>
    </app-layout>
</template>

<script>
    import AppLayout from '@/Layouts/AppLayout'
    import JetActionMessage from '@/Jetstream/ActionMessage'
    import JetButton from '@/Jetstream/Button'
    import JetConfirmationModal from '@/Jetstream/ConfirmationModal'
    import JetDangerButton from '@/Jetstream/DangerButton'
    import JetFormSection from '@/Jetstream/FormSection'
    import JetInputError from '@/Jetstream/InputError'
    import JetLabel from '@/Jetstream/Label'
    import JetSecondaryButton from '@/Jetstream/SecondaryButton'
    import JetValidationErrors from '@/Jetstream/ValidationErrors'

    export default {
        components: {
            AppLayout,
            JetActionMessage,
            JetButton,
            JetConfirmationModal,
            JetDangerButton,
            JetFormSection,
            JetInputError,
            JetLabel,
            JetSecondaryButton,
            JetValidationErrors,
        },

        props: ['team', 'retention', 's3_configured', 'backups', 'compare'],

        data() {
            return {
                createForm: this.$inertia.form({}),
                deleteForm: this.$inertia.form({}),
                compareForm: this.$inertia.form({
                    base_id: this.backups.length > 1 ? this.backups[1].id : null,
                    target_id: this.backups.length ? this.backups[0].id : null,
                }),
                backupToDelete: null,
            }
        },

        computed: {
            flashSuccess() {
                return this.$page.props.flash.success ? true : false;
            },
        },

        methods: {
            create() {
                this.createForm.post(route('backups.store', { team: this.team.id }), {
                    preserveScroll: true,
                });
            },

            confirmDelete(backup) {
                this.backupToDelete = backup;
            },

            remove() {
                this.deleteForm.delete(
                    route('backups.destroy', { team: this.team.id, backup: this.backupToDelete.id }),
                    {
                        preserveScroll: true,
                        onSuccess: () => (this.backupToDelete = null),
                    }
                );
            },

            compare() {
                this.compareForm.post(route('backups.compare', { team: this.team.id }), {
                    preserveScroll: true,
                });
            },

            formatDateTime(value) {
                return value ? new Date(value).toLocaleString() : '—';
            },

            humanSize(bytes) {
                if (!bytes && bytes !== 0) return '—';
                if (bytes < 1024) return bytes + ' B';
                if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
                return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
            },

            shortId(id) {
                return id ? String(id).substring(0, 8) : '';
            },
        },
    }
</script>
