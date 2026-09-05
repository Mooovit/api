<template>
    <app-layout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Auto-backups — {{ team.name }}
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <jet-form-section @submitted="save">
                    <template #title>
                        Auto-backup schedule
                    </template>

                    <template #description>
                        One savepoint per team, created automatically by the
                        server. The last 7 backups are kept per team.
                    </template>

                    <template #form>
                        <div class="col-span-6 sm:col-span-4">
                            <jet-label for="frequency" value="Frequency" />

                            <select id="frequency" v-model="form.frequency"
                                    class="mt-1 block w-full border-gray-300 focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50 rounded-md shadow-sm">
                                <option v-for="frequency in frequencies" :key="frequency" :value="frequency">
                                    {{ frequency }}
                                </option>
                            </select>

                            <jet-input-error :message="form.errors.frequency" class="mt-2" />
                        </div>

                        <div class="col-span-6">
                            <label class="flex items-center">
                                <jet-checkbox v-model:checked="form.enabled" name="enabled" />
                                <span class="ml-2 text-sm text-gray-600">Enabled</span>
                            </label>

                            <jet-input-error :message="form.errors.enabled" class="mt-2" />
                        </div>

                        <div class="col-span-6 text-sm text-gray-600" v-if="schedule">
                            <p v-if="schedule.last_run_at">
                                Last run: {{ formatDateTime(schedule.last_run_at) }}
                            </p>
                            <p v-if="schedule.next_run_at">
                                Next run: {{ formatDateTime(schedule.next_run_at) }}
                            </p>
                            <p v-else>
                                Not scheduled (disabled).
                            </p>
                        </div>
                    </template>

                    <template #actions>
                        <jet-action-message :on="form.recentlySuccessful" class="mr-3">
                            Saved.
                        </jet-action-message>

                        <jet-button :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                            Save
                        </jet-button>

                        <jet-danger-button v-if="schedule" type="button"
                                           :class="{ 'opacity-25': form.processing }"
                                           :disabled="form.processing" class="ml-3"
                                           @click="remove">
                            Delete schedule
                        </jet-danger-button>
                    </template>
                </jet-form-section>
            </div>
        </div>
    </app-layout>
</template>

<script>
    import AppLayout from '@/Layouts/AppLayout'
    import JetActionMessage from '@/Jetstream/ActionMessage'
    import JetButton from '@/Jetstream/Button'
    import JetCheckbox from '@/Jetstream/Checkbox'
    import JetDangerButton from '@/Jetstream/DangerButton'
    import JetFormSection from '@/Jetstream/FormSection'
    import JetInputError from '@/Jetstream/InputError'
    import JetLabel from '@/Jetstream/Label'

    export default {
        components: {
            AppLayout,
            JetActionMessage,
            JetButton,
            JetCheckbox,
            JetDangerButton,
            JetFormSection,
            JetInputError,
            JetLabel,
        },

        props: ['team', 'frequencies', 'schedule'],

        data() {
            return {
                form: this.$inertia.form({
                    frequency: this.schedule ? this.schedule.frequency : 'daily',
                    enabled: this.schedule ? this.schedule.enabled : true,
                })
            }
        },

        methods: {
            save() {
                this.form.post(route('backups.schedules.store', { team: this.team.id }), {
                    preserveScroll: true,
                });
            },

            remove() {
                this.$inertia.delete(route('backups.schedules.destroy', { team: this.team.id }), {
                    preserveScroll: true,
                });
            },

            formatDateTime(value) {
                return new Date(value).toLocaleString();
            },
        },
    }
</script>
