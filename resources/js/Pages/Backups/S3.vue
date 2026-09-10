<template>
    <app-layout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                S3 backup offload — {{ team.name }}
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <jet-action-message :on="flashSuccess" class="mr-3">
                    {{ $page.props.flash.success }}
                </jet-action-message>

                <!-- Current state + rules -->
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-4 sm:p-6">
                    <h3 class="font-semibold text-gray-800">Retention rules</h3>
                    <div class="mt-2 text-sm text-gray-600 space-y-1">
                        <p>
                            <span class="font-medium">Local:</span>
                            the last {{ retention }} backups per team are kept.
                        </p>
                        <p v-if="config">
                            <span class="font-medium">S3:</span>
                            new backups are copied to
                            <span class="font-mono">{{ config.bucket }}</span>
                            <span class="font-mono text-gray-500">/{{ config.prefix }}</span>
                            — the bucket keeps them according to its own
                            lifecycle rules; local rotation never deletes
                            bucket copies.
                        </p>
                        <p v-else>
                            <span class="font-medium">S3:</span>
                            not configured — only the local last
                            {{ retention }} rule applies.
                        </p>
                        <template v-if="config">
                            <p v-if="lifecycle" class="pt-1">
                                <span class="font-medium">Bucket lifecycle rules:</span>
                            </p>
                            <ul v-if="lifecycle" class="list-disc list-inside font-mono text-xs">
                                <li v-for="(rule, index) in lifecycle" :key="index">
                                    {{ rule.ID || rule.Prefix || ('rule ' + (index + 1)) }}
                                    <span v-if="rule.Status" class="text-gray-500">[{{ rule.Status }}]</span>
                                    <span v-if="rule.Expiration" class="text-gray-500">
                                        <template v-if="rule.Expiration.Days">
                                            — expires after {{ rule.Expiration.Days }} days
                                        </template>
                                        <template v-else-if="rule.Expiration.Date">
                                            — expires {{ rule.Expiration.Date }}
                                        </template>
                                    </span>
                                </li>
                            </ul>
                            <p v-else class="text-gray-500">
                                The bucket reports no lifecycle rules (or the
                                store does not answer that call).
                            </p>
                        </template>
                    </div>
                </div>

                <!-- Upstream bucket error -->
                <div v-if="bucket_error" class="bg-red-50 border border-red-200 rounded-lg p-4 sm:p-6">
                    <h3 class="font-semibold text-red-800">Bucket error</h3>
                    <p class="mt-1 text-sm text-red-700 break-all">{{ bucket_error }}</p>
                </div>

                <!-- Config form -->
                <jet-form-section @submitted="save">
                    <template #title>
                        {{ config ? 'S3 credentials' : 'Configure S3' }}
                    </template>

                    <template #description>
                        Credentials are stored encrypted and verified against
                        the bucket before being saved. New backups are copied
                        to the bucket on creation.
                    </template>

                    <template #form>
                        <div class="col-span-6 sm:col-span-4">
                            <jet-label for="bucket" value="Bucket" />
                            <jet-input id="bucket" type="text" v-model="form.bucket"
                                       class="mt-1 block w-full" autocomplete="off" />
                            <jet-input-error :message="form.errors.bucket" class="mt-2" />
                        </div>

                        <div class="col-span-6 sm:col-span-4">
                            <jet-label for="region" value="Region" />
                            <jet-input id="region" type="text" v-model="form.region"
                                       class="mt-1 block w-full" placeholder="us-east-1"
                                       autocomplete="off" />
                            <jet-input-error :message="form.errors.region" class="mt-2" />
                        </div>

                        <div class="col-span-6 sm:col-span-4">
                            <jet-label for="access_key" value="Access key" />
                            <jet-input id="access_key" type="text" v-model="form.access_key"
                                       class="mt-1 block w-full" autocomplete="off" />
                            <jet-input-error :message="form.errors.access_key" class="mt-2" />
                        </div>

                        <div class="col-span-6 sm:col-span-4">
                            <jet-label for="secret_key" value="Secret key" />
                            <jet-input id="secret_key" type="password" v-model="form.secret_key"
                                       class="mt-1 block w-full" autocomplete="new-password" />
                            <jet-input-error :message="form.errors.secret_key" class="mt-2" />
                        </div>

                        <div class="col-span-6 sm:col-span-4">
                            <jet-label for="endpoint" value="Endpoint (optional)" />
                            <jet-input id="endpoint" type="text" v-model="form.endpoint"
                                       class="mt-1 block w-full"
                                       placeholder="https://s3.example.com — for S3-compatible stores"
                                       autocomplete="off" />
                            <jet-input-error :message="form.errors.endpoint" class="mt-2" />
                        </div>

                        <div class="col-span-6 sm:col-span-4">
                            <jet-label for="prefix" value="Prefix (optional)" />
                            <jet-input id="prefix" type="text" v-model="form.prefix"
                                       class="mt-1 block w-full" autocomplete="off" />
                            <jet-input-error :message="form.errors.prefix" class="mt-2" />
                        </div>
                    </template>

                    <template #actions>
                        <jet-action-message :on="form.recentlySuccessful" class="mr-3">
                            Saved.
                        </jet-action-message>

                        <jet-button :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                            {{ config ? 'Update & verify' : 'Save & verify' }}
                        </jet-button>

                        <jet-danger-button v-if="config" type="button"
                                           :class="{ 'opacity-25': removeForm.processing }"
                                           :disabled="removeForm.processing" class="ml-3"
                                           @click="remove">
                            Remove configuration
                        </jet-danger-button>
                    </template>
                </jet-form-section>

                <!-- Bucket objects -->
                <div v-if="config" class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-4 sm:p-6">
                    <h3 class="font-semibold text-gray-800">Objects in the bucket</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Under prefix <span class="font-mono">{{ config.prefix }}</span>,
                        newest first.
                    </p>

                    <table class="mt-3 w-full text-sm" v-if="objects && objects.length">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="py-2 pr-4 font-medium">Key</th>
                                <th class="py-2 pr-4 font-medium">Size</th>
                                <th class="py-2 font-medium">Last modified</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="object in objects" :key="object.key" class="border-t border-gray-100">
                                <td class="py-2 pr-4 font-mono text-xs break-all">{{ object.key }}</td>
                                <td class="py-2 pr-4 text-gray-600">{{ humanSize(object.size) }}</td>
                                <td class="py-2 text-gray-600">{{ formatDateTime(object.last_modified) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else-if="objects" class="mt-3 text-sm text-gray-500">
                        No objects under this prefix yet.
                    </p>
                    <p v-else-if="!bucket_error" class="mt-3 text-sm text-gray-500">
                        Listing unavailable.
                    </p>
                </div>
            </div>
        </div>
    </app-layout>
</template>

<script>
    import AppLayout from '@/Layouts/AppLayout'
    import JetActionMessage from '@/Jetstream/ActionMessage'
    import JetButton from '@/Jetstream/Button'
    import JetDangerButton from '@/Jetstream/DangerButton'
    import JetFormSection from '@/Jetstream/FormSection'
    import JetInput from '@/Jetstream/Input'
    import JetInputError from '@/Jetstream/InputError'
    import JetLabel from '@/Jetstream/Label'
    import JetSecondaryButton from '@/Jetstream/SecondaryButton'

    export default {
        components: {
            AppLayout,
            JetActionMessage,
            JetButton,
            JetDangerButton,
            JetFormSection,
            JetInput,
            JetInputError,
            JetLabel,
            JetSecondaryButton,
        },

        props: ['team', 'retention', 'config', 'objects', 'lifecycle', 'bucket_error'],

        data() {
            return {
                form: this.$inertia.form({
                    bucket: this.config ? this.config.bucket : '',
                    region: this.config ? this.config.region : '',
                    access_key: '',
                    secret_key: '',
                    endpoint: this.config ? this.config.endpoint : '',
                    prefix: this.config ? this.config.prefix : '',
                }),
                removeForm: this.$inertia.form({}),
            }
        },

        computed: {
            flashSuccess() {
                return this.$page.props.flash.success ? true : false;
            },
        },

        methods: {
            save() {
                this.form.post(route('backups.s3.store', { team: this.team.id }), {
                    preserveScroll: true,
                });
            },

            remove() {
                this.removeForm.delete(route('backups.s3.destroy', { team: this.team.id }), {
                    preserveScroll: true,
                });
            },

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
