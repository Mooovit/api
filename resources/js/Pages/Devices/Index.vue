<template>
    <app-layout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Devices
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <!-- Freshly minted secret: rendered exactly once (flash) -->
                <div v-if="$page.props.flash.device_token"
                     class="bg-green-50 border border-green-200 rounded-lg p-4 sm:p-6">
                    <h3 class="font-semibold text-green-800">
                        Device token "{{ mintedName }}" created
                    </h3>
                    <p class="mt-1 text-sm text-green-700">
                        Copy it now — it is shown only once.
                    </p>
                    <div class="mt-3 flex items-start gap-4">
                        <div class="flex-1 space-y-2">
                            <code class="block bg-white border border-green-300 rounded px-3 py-2 text-sm break-all select-all">
                                {{ $page.props.flash.device_token }}
                            </code>
                            <jet-button type="button" @click="copyToken">Copy</jet-button>
                        </div>
                        <div class="flex-shrink-0 text-center">
                            <qrcode-vue :value="$page.props.flash.device_token" size="120" level="M"
                                        render-as="svg" class="bg-white border border-green-300 rounded p-2" />
                            <div class="mt-1 text-xs text-green-600">scan on the device</div>
                        </div>
                    </div>
                </div>

                <!-- Enrollment code flash -->
                <div v-if="$page.props.flash.device_code"
                     class="bg-blue-50 border border-blue-200 rounded-lg p-4 sm:p-6">
                    <h3 class="font-semibold text-blue-800">Enrollment code minted</h3>
                    <div class="mt-3 flex items-start gap-4">
                        <div class="flex-1 space-y-2">
                            <code class="block bg-white border border-blue-300 rounded px-3 py-2 text-lg tracking-widest text-center select-all">
                                {{ $page.props.flash.device_code.code }}
                            </code>
                            <jet-button type="button" @click="copyCode">Copy</jet-button>
                            <p class="text-sm text-blue-700">
                                Single use, expires {{ formatDateTime($page.props.flash.device_code.expires_at) }}.
                            </p>
                        </div>
                        <div class="flex-shrink-0 text-center">
                            <qrcode-vue :value="$page.props.flash.device_code.code" size="120" level="M"
                                        render-as="svg" class="bg-white border border-blue-300 rounded p-2" />
                            <div class="mt-1 text-xs text-blue-600">scan on the device</div>
                        </div>
                    </div>
                </div>

                <jet-action-message :on="flashSuccess" class="mr-3">
                    {{ $page.props.flash.success }}
                </jet-action-message>

                <!-- Mint a device token -->
                <jet-form-section @submitted="mintToken">
                    <template #title>
                        Device token
                    </template>

                    <template #description>
                        Mint a token for a warehouse device. It carries the
                        full item/status/location/label ability set and uses
                        your permissions.
                    </template>

                    <template #form>
                        <div class="col-span-6 sm:col-span-4">
                            <jet-label for="token_name" value="Device name" />
                            <jet-input id="token_name" type="text" v-model="tokenForm.name"
                                       class="mt-1 block w-full" placeholder="Bluebird #3"
                                       autocomplete="off" />
                            <jet-input-error :message="tokenForm.errors.name" class="mt-2" />
                        </div>
                    </template>

                    <template #actions>
                        <jet-action-message :on="tokenForm.recentlySuccessful" class="mr-3">
                            Minted.
                        </jet-action-message>

                        <jet-button :class="{ 'opacity-25': tokenForm.processing }"
                                    :disabled="tokenForm.processing">
                            Mint token
                        </jet-button>
                    </template>
                </jet-form-section>

                <!-- Enrollment codes -->
                <jet-form-section @submitted="mintCode">
                    <template #title>
                        Enrollment code
                    </template>

                    <template #description>
                        A one-time code that enrolls a NEW device without
                        typing your password on it (the app's "enroll" screen).
                        Codes live 15 minutes.
                    </template>

                    <template #form>
                        <div class="col-span-6">
                            <table class="w-full text-sm" v-if="codes.length">
                                <thead>
                                    <tr class="text-left text-gray-500">
                                        <th class="py-2 pr-4 font-medium">Code</th>
                                        <th class="py-2 pr-4 font-medium">Expires</th>
                                        <th class="py-2 font-medium"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="code in codes" :key="code.code">
                                        <td class="py-2 pr-4 font-mono tracking-widest">{{ code.code }}</td>
                                        <td class="py-2 pr-4 text-gray-600">{{ formatDateTime(code.expires_at) }}</td>
                                        <td class="py-2 text-right">
                                            <jet-secondary-button type="button" @click="showCodeQr(code)">
                                                Show QR
                                            </jet-secondary-button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <p v-else class="text-sm text-gray-500">
                                No active enrollment code.
                            </p>
                        </div>
                    </template>

                    <template #actions>
                        <jet-button type="button" :class="{ 'opacity-25': codeForm.processing }"
                                    :disabled="codeForm.processing" @click="mintCode">
                            Mint code
                        </jet-button>
                    </template>
                </jet-form-section>

                <!-- Fleet -->
                <jet-form-section>
                    <template #title>
                        Your devices
                    </template>

                    <template #description>
                        Tokens minted by or for you. "Last seen" is the last
                        API call the device made.
                    </template>

                    <template #form>
                        <div class="col-span-6 overflow-x-auto">
                            <div class="flex justify-end mb-2" v-if="tokens.length">
                                <jet-danger-button type="button" @click="confirmingRevokeAll = true">
                                    Revoke all
                                </jet-danger-button>
                            </div>
                            <table class="w-full text-sm" v-if="tokens.length">
                                <thead>
                                    <tr class="text-left text-gray-500">
                                        <th class="py-2 pr-4 font-medium">Name</th>
                                        <th class="py-2 pr-4 font-medium">Team</th>
                                        <th class="py-2 pr-4 font-medium">Last seen</th>
                                        <th class="py-2 pr-4 font-medium">Created</th>
                                        <th class="py-2 font-medium"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="token in tokens" :key="token.id" class="border-t border-gray-100">
                                        <td class="py-2 pr-4 font-medium text-gray-800">{{ token.name }}</td>
                                        <td class="py-2 pr-4">
                                            <select :value="token.current_team_id || ''"
                                                    class="border-gray-300 focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50 rounded-md shadow-sm text-sm"
                                                    @change="switchTeam(token, $event.target.value)">
                                                <option value="">Follow my default</option>
                                                <option v-for="team in $page.props.user.all_teams" :key="team.id" :value="team.id">
                                                    {{ team.name }}
                                                </option>
                                            </select>
                                        </td>
                                        <td class="py-2 pr-4 text-gray-600">{{ formatDateTime(token.last_used_at) }}</td>
                                        <td class="py-2 pr-4 text-gray-600">{{ formatDate(token.created_at) }}</td>
                                        <td class="py-2 text-right">
                                            <jet-danger-button type="button" @click="confirmRevoke(token)">
                                                Revoke
                                            </jet-danger-button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <p v-else class="text-sm text-gray-500">
                                No device tokens yet.
                            </p>
                        </div>
                    </template>
                </jet-form-section>
            </div>
        </div>

        <!-- Revoke confirmation -->
        <jet-confirmation-modal :show="tokenToRevoke !== null" @close="tokenToRevoke = null">
            <template #title>
                Revoke device token
            </template>

            <template #content>
                Revoke "{{ tokenToRevoke ? tokenToRevoke.name : '' }}"? The
                device will stop working (401) on its next call.
            </template>

            <template #footer>
                <jet-secondary-button @click="tokenToRevoke = null">
                    Cancel
                </jet-secondary-button>

                <jet-danger-button class="ml-2" :class="{ 'opacity-25': revokeForm.processing }"
                                   :disabled="revokeForm.processing" @click="revoke">
                    Revoke
                </jet-danger-button>
            </template>
        </jet-confirmation-modal>

        <!-- Revoke-all confirmation -->
        <jet-confirmation-modal :show="confirmingRevokeAll" @close="confirmingRevokeAll = false">
            <template #title>
                Revoke all device tokens
            </template>

            <template #content>
                Revoke ALL {{ tokens.length }} device tokens? Every device in
                the fleet stops working (401) on its next call and must be
                re-enrolled. This cannot be undone.
            </template>

            <template #footer>
                <jet-secondary-button @click="confirmingRevokeAll = false">
                    Cancel
                </jet-secondary-button>

                <jet-danger-button class="ml-2" :class="{ 'opacity-25': revokeForm.processing }"
                                   :disabled="revokeForm.processing" @click="revokeAll">
                    Revoke all
                </jet-danger-button>
            </template>
        </jet-confirmation-modal>

        <!-- Enrollment code QR -->
        <jet-dialog-modal :show="codeQr !== null" @close="codeQr = null">
            <template #title>
                Enrollment code {{ codeQr ? codeQr.code : '' }}
            </template>

            <template #content>
                <div class="flex flex-col items-center">
                    <qrcode-vue v-if="codeQr" :value="codeQr.code" size="192" level="M"
                                render-as="svg" class="bg-white border border-gray-200 rounded p-2" />
                    <p class="mt-3 text-sm text-gray-600">
                        Scan on the device's enroll screen — single use, expires
                        {{ codeQr ? formatDateTime(codeQr.expires_at) : '' }}.
                    </p>
                </div>
            </template>

            <template #footer>
                <jet-secondary-button @click="codeQr = null">
                    Close
                </jet-secondary-button>
            </template>
        </jet-dialog-modal>
    </app-layout>
</template>

<script>
    import AppLayout from '@/Layouts/AppLayout'
    import QrcodeVue from 'qrcode.vue'
    import JetActionMessage from '@/Jetstream/ActionMessage'
    import JetButton from '@/Jetstream/Button'
    import JetConfirmationModal from '@/Jetstream/ConfirmationModal'
    import JetDangerButton from '@/Jetstream/DangerButton'
    import JetDialogModal from '@/Jetstream/DialogModal'
    import JetFormSection from '@/Jetstream/FormSection'
    import JetInput from '@/Jetstream/Input'
    import JetInputError from '@/Jetstream/InputError'
    import JetLabel from '@/Jetstream/Label'
    import JetSecondaryButton from '@/Jetstream/SecondaryButton'

    export default {
        components: {
            AppLayout,
            QrcodeVue,
            JetActionMessage,
            JetButton,
            JetConfirmationModal,
            JetDangerButton,
            JetDialogModal,
            JetFormSection,
            JetInput,
            JetInputError,
            JetLabel,
            JetSecondaryButton,
        },

        props: ['tokens', 'codes'],

        data() {
            return {
                tokenForm: this.$inertia.form({
                    name: '',
                }),
                codeForm: this.$inertia.form({}),
                revokeForm: this.$inertia.form({}),
                tokenToRevoke: null,
                mintedName: null,
                confirmingRevokeAll: false,
                codeQr: null,
            }
        },

        computed: {
            flashSuccess() {
                return this.$page.props.flash.success ? true : false;
            },
        },

        methods: {
            mintToken() {
                this.mintedName = this.tokenForm.name;
                this.tokenForm.post(route('devices.tokens.store'), {
                    preserveScroll: true,
                    onSuccess: () => this.tokenForm.reset(),
                });
            },

            mintCode() {
                this.codeForm.post(route('devices.codes.store'), {
                    preserveScroll: true,
                });
            },

            confirmRevoke(token) {
                this.tokenToRevoke = token;
            },

            revoke() {
                this.revokeForm.delete(route('devices.tokens.destroy', { token: this.tokenToRevoke.id }), {
                    preserveScroll: true,
                    onSuccess: () => (this.tokenToRevoke = null),
                });
            },

            revokeAll() {
                this.revokeForm.delete(route('devices.tokens.destroyAll'), {
                    preserveScroll: true,
                    onSuccess: () => (this.confirmingRevokeAll = false),
                });
            },

            showCodeQr(code) {
                this.codeQr = code;
            },

            switchTeam(token, teamId) {
                this.$inertia.post(
                    route('devices.tokens.team', { token: token.id }),
                    { team_id: teamId || null },
                    { preserveScroll: true }
                );
            },

            copyToken() {
                navigator.clipboard.writeText(this.$page.props.flash.device_token);
            },

            copyCode() {
                navigator.clipboard.writeText(this.$page.props.flash.device_code.code);
            },

            formatDateTime(value) {
                return value ? new Date(value).toLocaleString() : '—';
            },

            formatDate(value) {
                return value ? new Date(value).toLocaleDateString() : '—';
            },
        },
    }
</script>
