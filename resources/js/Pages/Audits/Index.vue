<template>
    <app-layout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Audits — {{ team.name }}
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <!-- Filter -->
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-4 sm:p-6">
                    <div class="sm:flex sm:items-center sm:justify-between">
                        <div>
                            <h3 class="font-semibold text-gray-800">Stocktake results</h3>
                            <p class="text-sm text-gray-500">
                                Snapshots submitted from the mobile app (MV-050).
                                Missing items are a declared count only — the
                                expected list stays on the device.
                            </p>
                        </div>
                        <div class="mt-3 sm:mt-0">
                            <label for="location-filter" class="sr-only">Location</label>
                            <select id="location-filter" v-model="selectedLocation"
                                    class="block w-full sm:w-64 border-gray-300 focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50 rounded-md shadow-sm">
                                <option :value="null">All locations</option>
                                <option v-for="location in locations" :key="location.id" :value="location.id">
                                    {{ location.name }}
                                </option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Audit list -->
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-4 sm:p-6">
                    <table class="w-full text-sm" v-if="audits.data.length">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="py-2 pr-4 font-medium">When</th>
                                <th class="py-2 pr-4 font-medium">Location</th>
                                <th class="py-2 pr-4 font-medium">By</th>
                                <th class="py-2 pr-4 font-medium">Result</th>
                                <th class="py-2 font-medium text-right">Detail</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="audit in audits.data" :key="audit.id">
                                <tr class="border-t border-gray-100">
                                    <td class="py-2 pr-4 font-medium text-gray-800">{{ formatDateTime(audit.created_at) }}</td>
                                    <td class="py-2 pr-4 text-gray-600">{{ audit.location ? audit.location.name : '—' }}</td>
                                    <td class="py-2 pr-4 text-gray-600">{{ audit.user ? audit.user.name : '—' }}</td>
                                    <td class="py-2 pr-4">
                                        <span v-if="isClean(audit)"
                                              class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-50 text-green-700">
                                            clean · {{ audit.found_count }} found
                                        </span>
                                        <span v-else class="inline-flex flex-wrap items-center gap-1 text-xs">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full font-medium bg-green-50 text-green-700">
                                                {{ audit.found_count }} found
                                            </span>
                                            <span v-if="audit.missing_count"
                                                  class="inline-flex items-center px-2 py-0.5 rounded-full font-medium bg-red-50 text-red-700">
                                                {{ audit.missing_count }} missing
                                            </span>
                                            <span v-if="audit.extra_count"
                                                  class="inline-flex items-center px-2 py-0.5 rounded-full font-medium bg-amber-50 text-amber-700">
                                                {{ audit.extra_count }} extra
                                            </span>
                                            <span v-if="audit.unknown_count"
                                                  class="inline-flex items-center px-2 py-0.5 rounded-full font-medium bg-gray-100 text-gray-700">
                                                {{ audit.unknown_count }} unknown
                                            </span>
                                        </span>
                                    </td>
                                    <td class="py-2 text-right">
                                        <button type="button" @click="toggle(audit.id)"
                                                class="text-indigo-600 hover:underline text-sm">
                                            {{ expandedId === audit.id ? 'hide' : 'view' }}
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="expandedId === audit.id" class="border-t border-gray-100 bg-gray-50">
                                    <td colspan="5" class="py-3 px-2">
                                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                            <!-- Found -->
                                            <div>
                                                <h4 class="text-sm font-semibold text-green-700">Found ({{ audit.found.length }})</h4>
                                                <ul class="mt-1 text-sm space-y-0.5">
                                                    <li v-for="item in audit.found" :key="'f-' + item.id">
                                                        <span :class="item.deleted ? 'text-gray-400 line-through' : 'text-gray-700'">
                                                            {{ item.name || shortId(item.id) }}
                                                        </span>
                                                        <span v-if="item.deleted"
                                                              class="ml-1 text-xs font-medium text-amber-600">(deleted)</span>
                                                    </li>
                                                    <li v-if="!audit.found.length" class="text-gray-400">none</li>
                                                </ul>
                                            </div>

                                            <!-- Extra -->
                                            <div>
                                                <h4 class="text-sm font-semibold text-amber-700">Extra ({{ audit.extra.length }})</h4>
                                                <ul class="mt-1 text-sm space-y-0.5">
                                                    <li v-for="item in audit.extra" :key="'e-' + item.id">
                                                        <span :class="item.deleted ? 'text-gray-400 line-through' : 'text-gray-700'">
                                                            {{ item.name || shortId(item.id) }}
                                                        </span>
                                                        <span v-if="item.deleted"
                                                              class="ml-1 text-xs font-medium text-amber-600">(deleted)</span>
                                                    </li>
                                                    <li v-if="!audit.extra.length" class="text-gray-400">none</li>
                                                </ul>
                                            </div>

                                            <!-- Missing -->
                                            <div>
                                                <h4 class="text-sm font-semibold text-red-700">Missing ({{ audit.missing.length }})</h4>
                                                <ul class="mt-1 text-sm space-y-0.5" v-if="audit.missing.length">
                                                    <li v-for="item in audit.missing" :key="'m-' + item.id">
                                                        <span :class="item.deleted ? 'text-gray-400 line-through' : 'text-gray-700'">
                                                            {{ item.name || shortId(item.id) }}
                                                        </span>
                                                        <span v-if="item.deleted"
                                                              class="ml-1 text-xs font-medium text-amber-600">(deleted)</span>
                                                    </li>
                                                </ul>
                                                <p v-else-if="!audit.missing_count" class="mt-1 text-sm text-gray-400">none</p>
                                                <p v-else class="mt-1 text-sm text-gray-500">
                                                    Count declared by the device ({{ audit.missing_count }}) — this
                                                    audit predates the missing-item list.
                                                </p>
                                            </div>

                                            <!-- Unknown codes -->
                                            <div>
                                                <h4 class="text-sm font-semibold text-gray-700">Unknown codes ({{ audit.unknown_codes.length }})</h4>
                                                <ul class="mt-1 text-sm space-y-0.5 font-mono">
                                                    <li v-for="code in audit.unknown_codes" :key="'u-' + code" class="text-gray-700">
                                                        {{ code }}
                                                    </li>
                                                    <li v-if="!audit.unknown_codes.length" class="text-gray-400 font-sans">none</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <p v-else class="text-sm text-gray-500">
                        No audits yet — submit one from the mobile app.
                    </p>

                    <!-- Pagination -->
                    <div v-if="audits.last_page > 1" class="mt-4 flex items-center justify-between text-sm">
                        <jet-secondary-button type="button" :disabled="audits.current_page <= 1"
                                              @click="goToPage(audits.current_page - 1)">
                            Previous
                        </jet-secondary-button>
                        <span class="text-gray-500">Page {{ audits.current_page }} of {{ audits.last_page }}</span>
                        <jet-secondary-button type="button" :disabled="audits.current_page >= audits.last_page"
                                              @click="goToPage(audits.current_page + 1)">
                            Next
                        </jet-secondary-button>
                    </div>
                </div>
            </div>
        </div>
    </app-layout>
</template>

<script>
    import AppLayout from '@/Layouts/AppLayout'
    import JetSecondaryButton from '@/Jetstream/SecondaryButton'

    export default {
        components: {
            AppLayout,
            JetSecondaryButton,
        },

        props: ['team', 'locations', 'filtered_location', 'audits'],

        data() {
            return {
                selectedLocation: this.filtered_location,
                expandedId: null,
            }
        },

        watch: {
            selectedLocation(value) {
                this.expandedId = null;
                this.$inertia.visit(route('audits.index', { team: this.team.id }), {
                    data: value ? { location: value } : {},
                    preserveState: true,
                    preserveScroll: true,
                });
            },
        },

        methods: {
            toggle(id) {
                this.expandedId = this.expandedId === id ? null : id;
            },

            goToPage(page) {
                this.$inertia.visit(route('audits.index', { team: this.team.id }), {
                    data: {
                        page,
                        ...(this.selectedLocation ? { location: this.selectedLocation } : {}),
                    },
                    preserveState: true,
                    preserveScroll: true,
                });
            },

            formatDateTime(value) {
                return value ? new Date(value).toLocaleString() : '—';
            },

            isClean(audit) {
                return audit.missing_count === 0 && audit.extra_count === 0 && audit.unknown_count === 0;
            },

            shortId(id) {
                return id ? String(id).substring(0, 8) : '';
            },
        },
    }
</script>
