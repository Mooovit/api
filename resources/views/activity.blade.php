<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Laravel') }} - Live Activity</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Axios for API calls -->
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <style>
        .activity-item {
            transition: all 0.2s ease;
        }
        .activity-item:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .pulse-animation {
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .fade-in {
            animation: fadeIn 0.3s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .slide-in-top {
            animation: slideInTop 0.4s ease-out;
        }
        @keyframes slideInTop {
            from { 
                opacity: 0; 
                transform: translateY(-20px) scale(0.95); 
                max-height: 0;
                margin-bottom: 0;
            }
            to { 
                opacity: 1; 
                transform: translateY(0) scale(1); 
                max-height: 200px;
                margin-bottom: 1rem;
            }
        }
        .new-item {
            border-left-color: #10b981 !important;
            background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%) !important;
        }
        .item-tab {
            color: #6b7280;
            background: transparent;
        }
        .item-tab:hover {
            background: #f3f4f6;
            color: #374151;
        }
        .item-tab.active {
            background: #6366f1;
            color: white;
        }
        .item-tab.active:hover {
            background: #5b5cf6;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-100 to-indigo-200 min-h-screen">
    <!-- Header -->
    <div class="bg-white/90 backdrop-blur shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <a href="/kanban/status" class="text-indigo-600 hover:text-indigo-800 transition">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Kanban
                    </a>
                    <div class="h-6 w-px bg-gray-300"></div>
                    <h1 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-history text-indigo-600 mr-3"></i>
                        Live Activity
                    </h1>
                </div>
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-2 text-sm text-gray-600">
                        <div class="w-2 h-2 bg-green-500 rounded-full animate-pulse" title="Auto-refreshing"></div>
                        <span>Real-time updates</span>
                    </div>
                    <button onclick="refreshActivity()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg font-medium transition">
                        <i class="fas fa-sync-alt mr-2"></i>Refresh
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="max-w-7xl mx-auto px-6 py-8">
        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white/90 backdrop-blur rounded-xl shadow-lg p-6">
                <div class="flex items-center">
                    <div class="p-3 bg-blue-100 rounded-full">
                        <i class="fas fa-chart-line text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">Total Changes</h3>
                        <p class="text-2xl font-bold text-blue-600" id="total-changes">{{ count($enhancedHistory) }}</p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white/90 backdrop-blur rounded-xl shadow-lg p-6">
                <div class="flex items-center">
                    <div class="p-3 bg-green-100 rounded-full">
                        <i class="fas fa-clock text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">Last Update</h3>
                        <p class="text-lg font-bold text-green-600" id="last-update">
                            @if(count($enhancedHistory) > 0)
                                {{ $enhancedHistory->first()->changed_at->diffForHumans() }}
                            @else
                                No recent activity
                            @endif
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white/90 backdrop-blur rounded-xl shadow-lg p-6">
                <div class="flex items-center">
                    <div class="p-3 bg-purple-100 rounded-full">
                        <i class="fas fa-users text-purple-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">Team</h3>
                        <p class="text-lg font-bold text-purple-600">{{ $user->current_team->name ?? 'Team' }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Activity Feed -->
        <div class="bg-white/90 backdrop-blur rounded-xl shadow-lg">
            <div class="p-6 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-bold text-gray-800">
                        <i class="fas fa-stream text-indigo-600 mr-2"></i>
                        Activity Feed
                    </h2>
                    <div class="flex items-center gap-3">
                        <select id="filterType" class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" onchange="filterActivity()">
                            <option value="">All Changes</option>
                            <option value="status_id">Status Changes</option>
                            <option value="location_id">Location Changes</option>
                            <option value="parent_id">Parent Changes</option>
                        </select>
                        <button onclick="clearFilter()" class="text-gray-600 hover:text-indigo-600 transition">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="p-6">
                <div id="activity-container" class="space-y-4 max-h-[70vh] overflow-y-auto">
                    @forelse($enhancedHistory as $change)
                    <div class="activity-item bg-gray-50 rounded-lg p-4 border-l-4 border-indigo-200 fade-in" data-field="{{ $change->field_name }}">
                        <div class="flex items-start justify-between">
                            <div class="flex-1">
                                <div class="flex items-center gap-3 mb-2">
                                    <div class="w-8 h-8 bg-indigo-100 rounded-full flex items-center justify-center">
                                        @switch($change->field_name)
                                            @case('status_id')
                                                <i class="fas fa-tasks text-indigo-600 text-sm"></i>
                                                @break
                                            @case('location_id')
                                                <i class="fas fa-map-marker-alt text-indigo-600 text-sm"></i>
                                                @break
                                            @case('parent_id')
                                                <i class="fas fa-box text-indigo-600 text-sm"></i>
                                                @break
                                            @default
                                                <i class="fas fa-edit text-indigo-600 text-sm"></i>
                                        @endswitch
                                    </div>
                                    <div>
                                        <h3 class="font-semibold text-gray-800">{{ $change->item->name }}</h3>
                                        <p class="text-sm text-gray-600">{{ ucfirst(str_replace('_', ' ', $change->field_name)) }} changed</p>
                                    </div>
                                </div>
                                
                                @if($change->old_value_name && $change->new_value_name)
                                <div class="flex items-center gap-2 ml-11">
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm bg-red-100 text-red-800">
                                        <i class="fas fa-minus-circle mr-2"></i>{{ $change->old_value_name }}
                                    </span>
                                    <i class="fas fa-arrow-right text-gray-400"></i>
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm bg-green-100 text-green-800">
                                        <i class="fas fa-plus-circle mr-2"></i>{{ $change->new_value_name }}
                                    </span>
                                </div>
                                @endif
                                
                                <div class="flex items-center gap-4 mt-3 ml-11 text-xs text-gray-500">
                                    <div class="flex items-center">
                                        <i class="fas fa-clock mr-1"></i>
                                        {{ $change->changed_at->format('M j, Y \a\t g:i A') }}
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-stopwatch mr-1"></i>
                                        {{ $change->changed_at->diffForHumans() }}
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex flex-col items-end gap-2">
                                <div class="bg-gray-100 px-3 py-1 rounded-full text-xs font-medium text-gray-700">
                                    {{ $change->user->name }}
                                </div>
                                <button onclick="viewItemDetails('{{ $change->item->id }}')" class="text-indigo-600 hover:text-indigo-800 text-sm transition">
                                    <i class="fas fa-eye mr-1"></i>View Item
                                </button>
                            </div>
                        </div>
                    </div>
                    @empty
                    <div class="text-center py-12">
                        <i class="fas fa-history text-4xl text-gray-400 mb-4"></i>
                        <h3 class="text-lg font-semibold text-gray-600 mb-2">No Activity Yet</h3>
                        <p class="text-gray-500">Recent changes to items will appear here</p>
                    </div>
                    @endforelse
                </div>
                
                <div id="activity-loading" class="hidden text-center py-8">
                    <i class="fas fa-spinner fa-spin text-2xl text-gray-400 mb-2"></i>
                    <p class="text-gray-600">Loading more activity...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Item Details Modal -->
    <div id="itemDetailsModal" class="fixed inset-0 z-50 hidden modal bg-black/40">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-4xl max-h-[90vh] overflow-hidden flex flex-col">
                <!-- Header -->
                <div class="p-6 border-b border-gray-200 flex-shrink-0">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-indigo-100 rounded-lg flex items-center justify-center mr-4">
                                <i class="fas fa-cube text-indigo-600 text-xl"></i>
                            </div>
                            <div>
                                <h3 class="text-xl font-bold text-gray-800" id="itemModalTitle">Item Details</h3>
                                <p class="text-sm text-gray-600" id="itemModalSubtitle">Loading...</p>
                            </div>
                        </div>
                        <button onclick="closeItemDetailsModal()" class="text-gray-400 hover:text-gray-600 text-xl p-2 hover:bg-gray-100 rounded-lg transition">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Tab Navigation -->
                <div class="px-6 py-3 border-b border-gray-200 flex-shrink-0">
                    <div class="flex space-x-1">
                        <button onclick="switchItemTab('overview')" id="overviewTab" class="item-tab active px-4 py-2 rounded-lg text-sm font-medium transition">
                            <i class="fas fa-info-circle mr-2"></i>Overview
                        </button>
                        <button onclick="switchItemTab('contents')" id="contentsTab" class="item-tab px-4 py-2 rounded-lg text-sm font-medium transition">
                            <i class="fas fa-cubes mr-2"></i>Contents <span id="contentsCount" class="ml-1 bg-gray-200 text-gray-700 px-2 py-0.5 rounded-full text-xs">0</span>
                        </button>
                        <button onclick="switchItemTab('history')" id="historyTab" class="item-tab px-4 py-2 rounded-lg text-sm font-medium transition">
                            <i class="fas fa-history mr-2"></i>History
                        </button>
                    </div>
                </div>
                
                <!-- Content Area -->
                <div class="flex-1 overflow-y-auto">
                    <div id="itemDetailsContent" class="p-6">
                        <!-- Content will be loaded dynamically -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Auto-refresh activity every second
        let refreshInterval;
        
        function startAutoRefresh() {
            refreshInterval = setInterval(refreshActivity, 1000);
        }
        
        function stopAutoRefresh() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
            }
        }
        
        function refreshActivity() {
            const loading = document.getElementById('activity-loading');
            loading.classList.remove('hidden');
            
            // Fetch latest activity data via AJAX
            axios.get('/kanban/history', {
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    'Accept': 'application/json',
                }
            })
            .then(response => {
                updateActivityFeed(response.data);
                updateStats(response.data);
                loading.classList.add('hidden');
            })
            .catch(error => {
                console.error('Error refreshing activity:', error);
                loading.classList.add('hidden');
            });
        }
        
        // Store the last known activity IDs to track new items
        let lastKnownActivityIds = new Set();
        
        function updateActivityFeed(history) {
            const container = document.getElementById('activity-container');
            const currentFilter = document.getElementById('filterType').value;
            
            if (history.length === 0) {
                container.innerHTML = `
                    <div class="text-center py-12">
                        <i class="fas fa-history text-4xl text-gray-400 mb-4"></i>
                        <h3 class="text-lg font-semibold text-gray-600 mb-2">No Activity Yet</h3>
                        <p class="text-gray-500">Recent changes to items will appear here</p>
                    </div>
                `;
                return;
            }
            
            // If this is the first load, populate the entire list
            if (lastKnownActivityIds.size === 0) {
                container.innerHTML = history.map(change => createActivityItemHTML(change, currentFilter, false)).join('');
                lastKnownActivityIds = new Set(history.map(change => change.id));
                return;
            }
            
            // Find new items that weren't in the last update
            const newItems = history.filter(change => !lastKnownActivityIds.has(change.id));
            
            if (newItems.length > 0) {
                // Add new items to the top with animation
                newItems.reverse().forEach(change => {
                    const itemHTML = createActivityItemHTML(change, currentFilter, true);
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = itemHTML;
                    const newElement = tempDiv.firstElementChild;
                    
                    // Insert at the beginning
                    container.insertBefore(newElement, container.firstChild);
                    
                    // Remove the new-item styling after animation completes
                    setTimeout(() => {
                        newElement.classList.remove('new-item');
                    }, 2000);
                });
                
                // Update our tracking set
                lastKnownActivityIds = new Set(history.map(change => change.id));
                
                // Remove old items if we have too many (keep last 100)
                const allItems = container.querySelectorAll('.activity-item');
                if (allItems.length > 100) {
                    for (let i = 100; i < allItems.length; i++) {
                        allItems[i].remove();
                    }
                }
            }
            
            // Update timestamps for existing items
            updateExistingTimestamps(history);
        }
        
        function createActivityItemHTML(change, currentFilter, isNew) {
            const fieldIcon = getFieldIcon(change.field_name);
            const isVisible = !currentFilter || change.field_name === currentFilter;
            const animationClass = isNew ? 'slide-in-top new-item' : 'fade-in';
            
            return `
                <div class="activity-item bg-gray-50 rounded-lg p-4 border-l-4 border-indigo-200 ${animationClass} ${isVisible ? '' : 'hidden'}" 
                     data-field="${change.field_name}" data-change-id="${change.id}">
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <div class="flex items-center gap-3 mb-2">
                                <div class="w-8 h-8 bg-indigo-100 rounded-full flex items-center justify-center">
                                    <i class="${fieldIcon} text-indigo-600 text-sm"></i>
                                </div>
                                <div>
                                    <h3 class="font-semibold text-gray-800">${change.item.name}</h3>
                                    <p class="text-sm text-gray-600">${change.field_name.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())} changed</p>
                                </div>
                            </div>
                            
                            ${change.old_value_name && change.new_value_name ? `
                            <div class="flex items-center gap-2 ml-11">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm bg-red-100 text-red-800">
                                    <i class="fas fa-minus-circle mr-2"></i>${change.old_value_name}
                                </span>
                                <i class="fas fa-arrow-right text-gray-400"></i>
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm bg-green-100 text-green-800">
                                    <i class="fas fa-plus-circle mr-2"></i>${change.new_value_name}
                                </span>
                            </div>
                            ` : ''}
                            
                            <div class="flex items-center gap-4 mt-3 ml-11 text-xs text-gray-500">
                                <div class="flex items-center">
                                    <i class="fas fa-clock mr-1"></i>
                                    <span class="absolute-time">${new Date(change.changed_at).toLocaleDateString('en-US', { 
                                        month: 'short', 
                                        day: 'numeric', 
                                        year: 'numeric',
                                        hour: 'numeric',
                                        minute: '2-digit'
                                    })}</span>
                                </div>
                                <div class="flex items-center">
                                    <i class="fas fa-stopwatch mr-1"></i>
                                    <span class="relative-time" data-timestamp="${change.changed_at}">${getTimeAgo(change.changed_at)}</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex flex-col items-end gap-2">
                            <div class="bg-gray-100 px-3 py-1 rounded-full text-xs font-medium text-gray-700">
                                ${change.user.name}
                            </div>
                            <button onclick="viewItemDetails('${change.item.id}')" class="text-indigo-600 hover:text-indigo-800 text-sm transition">
                                <i class="fas fa-eye mr-1"></i>View Item
                            </button>
                        </div>
                    </div>
                </div>
            `;
        }
        
        function updateExistingTimestamps(history) {
            // Update relative timestamps for existing items
            const existingItems = document.querySelectorAll('.activity-item');
            existingItems.forEach(item => {
                const relativeTimeSpan = item.querySelector('.relative-time');
                if (relativeTimeSpan) {
                    const timestamp = relativeTimeSpan.dataset.timestamp;
                    if (timestamp) {
                        relativeTimeSpan.textContent = getTimeAgo(timestamp);
                    }
                }
            });
        }
        
        function updateStats(history) {
            document.getElementById('total-changes').textContent = history.length;
            
            if (history.length > 0) {
                document.getElementById('last-update').textContent = getTimeAgo(history[0].changed_at);
            }
        }
        
        function getFieldIcon(fieldName) {
            switch(fieldName) {
                case 'status_id':
                    return 'fas fa-tasks';
                case 'location_id':
                    return 'fas fa-map-marker-alt';
                case 'parent_id':
                    return 'fas fa-box';
                default:
                    return 'fas fa-edit';
            }
        }
        
        function getTimeAgo(dateString) {
            const now = new Date();
            const date = new Date(dateString);
            const diffInSeconds = Math.floor((now - date) / 1000);
            
            if (diffInSeconds < 60) {
                return `${diffInSeconds} seconds ago`;
            } else if (diffInSeconds < 3600) {
                const minutes = Math.floor(diffInSeconds / 60);
                return `${minutes} minute${minutes !== 1 ? 's' : ''} ago`;
            } else if (diffInSeconds < 86400) {
                const hours = Math.floor(diffInSeconds / 3600);
                return `${hours} hour${hours !== 1 ? 's' : ''} ago`;
            } else {
                const days = Math.floor(diffInSeconds / 86400);
                return `${days} day${days !== 1 ? 's' : ''} ago`;
            }
        }
        
        function filterActivity() {
            const filterType = document.getElementById('filterType').value;
            const activities = document.querySelectorAll('.activity-item');
            
            activities.forEach(activity => {
                if (!filterType || activity.dataset.field === filterType) {
                    activity.style.display = 'block';
                } else {
                    activity.style.display = 'none';
                }
            });
        }
        
        function clearFilter() {
            document.getElementById('filterType').value = '';
            filterActivity();
        }
        
        // Store current item data for tabs
        let currentItemData = null;
        
        function viewItemDetails(itemId) {
            const modal = document.getElementById('itemDetailsModal');
            const content = document.getElementById('itemDetailsContent');
            
            // Reset to overview tab
            switchItemTab('overview');
            
            // Show loading state
            document.getElementById('itemModalTitle').textContent = 'Loading...';
            document.getElementById('itemModalSubtitle').textContent = 'Fetching item details';
            content.innerHTML = `
                <div class="text-center py-12">
                    <i class="fas fa-spinner fa-spin text-3xl text-gray-400 mb-4"></i>
                    <p class="text-gray-600">Loading item details...</p>
                </div>
            `;
            
            modal.classList.remove('hidden');
            
            // Fetch item details
            axios.get(`/kanban/item/${itemId}`)
                .then(response => {
                    currentItemData = response.data;
                    const item = currentItemData.item;
                    const children = currentItemData.children || [];
                    
                    // Update header
                    document.getElementById('itemModalTitle').textContent = item.name || 'Unnamed Item';
                    document.getElementById('itemModalSubtitle').textContent = `ID: ${item.id} • ${item.team ? item.team.name : 'No Team'}`;
                    document.getElementById('contentsCount').textContent = children.length;
                    
                    // Show overview tab by default
                    showOverviewTab();
                })
                .catch(error => {
                    document.getElementById('itemModalTitle').textContent = 'Error';
                    document.getElementById('itemModalSubtitle').textContent = 'Failed to load item';
                    content.innerHTML = `
                        <div class="text-center py-12">
                            <i class="fas fa-exclamation-triangle text-3xl text-red-400 mb-4"></i>
                            <h3 class="text-lg font-semibold text-gray-800 mb-2">Error Loading Item</h3>
                            <p class="text-gray-600">Unable to fetch item details. Please try again.</p>
                            <button onclick="viewItemDetails('${itemId}')" class="mt-4 bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg transition">
                                <i class="fas fa-retry mr-2"></i>Retry
                            </button>
                        </div>
                    `;
                });
        }
        
        function switchItemTab(tabName) {
            // Update tab buttons
            document.querySelectorAll('.item-tab').forEach(tab => tab.classList.remove('active'));
            document.getElementById(tabName + 'Tab').classList.add('active');
            
            // Show appropriate content
            if (!currentItemData) return;
            
            switch(tabName) {
                case 'overview':
                    showOverviewTab();
                    break;
                case 'contents':
                    showContentsTab();
                    break;
                case 'history':
                    showHistoryTab();
                    break;
            }
        }
        
        function showOverviewTab() {
            const item = currentItemData.item;
            const content = document.getElementById('itemDetailsContent');
            
            content.innerHTML = `
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <!-- Basic Information Card -->
                    <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-xl p-6 border border-blue-100">
                        <h4 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                            <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center mr-3">
                                <i class="fas fa-info-circle text-blue-600"></i>
                            </div>
                            Basic Information
                        </h4>
                        <div class="space-y-4">
                            <div class="flex items-center justify-between p-3 bg-white rounded-lg shadow-sm">
                                <span class="text-gray-600 font-medium">Name</span>
                                <span class="font-semibold text-gray-800">${item.name || 'N/A'}</span>
                            </div>
                            <div class="flex items-center justify-between p-3 bg-white rounded-lg shadow-sm">
                                <span class="text-gray-600 font-medium">ID</span>
                                <span class="font-mono text-sm bg-gray-100 px-3 py-1 rounded-lg">${item.id || 'N/A'}</span>
                            </div>
                            <div class="flex items-center justify-between p-3 bg-white rounded-lg shadow-sm">
                                <span class="text-gray-600 font-medium">Status</span>
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${item.status ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-600'}">
                                    <i class="fas fa-tasks mr-2"></i>${item.status ? item.status.name : 'No status'}
                                </span>
                            </div>
                            <div class="flex items-center justify-between p-3 bg-white rounded-lg shadow-sm">
                                <span class="text-gray-600 font-medium">Location</span>
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${item.location ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'}">
                                    <i class="fas fa-map-marker-alt mr-2"></i>${item.location ? item.location.name : 'No location'}
                                </span>
                            </div>
                            ${item.parent ? `
                            <div class="flex items-center justify-between p-3 bg-white rounded-lg shadow-sm">
                                <span class="text-gray-600 font-medium">Parent Box</span>
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-purple-100 text-purple-800 cursor-pointer hover:bg-purple-200 transition" onclick="viewItemDetails('${item.parent.id}')">
                                    <i class="fas fa-box mr-2"></i>${item.parent.name}
                                </span>
                            </div>
                            ` : ''}
                        </div>
                    </div>
                    
                    <!-- Timeline Card -->
                    <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-xl p-6 border border-green-100">
                        <h4 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                            <div class="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center mr-3">
                                <i class="fas fa-calendar text-green-600"></i>
                            </div>
                            Timeline
                        </h4>
                        <div class="space-y-4">
                            <div class="flex items-center justify-between p-3 bg-white rounded-lg shadow-sm">
                                <span class="text-gray-600 font-medium">Created</span>
                                <span class="text-gray-800">${item.created_at ? new Date(item.created_at).toLocaleDateString('en-US', { 
                                    year: 'numeric', 
                                    month: 'short', 
                                    day: 'numeric',
                                    hour: '2-digit',
                                    minute: '2-digit'
                                }) : 'N/A'}</span>
                            </div>
                            <div class="flex items-center justify-between p-3 bg-white rounded-lg shadow-sm">
                                <span class="text-gray-600 font-medium">Last Updated</span>
                                <span class="text-gray-800">${item.updated_at ? new Date(item.updated_at).toLocaleDateString('en-US', { 
                                    year: 'numeric', 
                                    month: 'short', 
                                    day: 'numeric',
                                    hour: '2-digit',
                                    minute: '2-digit'
                                }) : 'N/A'}</span>
                            </div>
                            <div class="flex items-center justify-between p-3 bg-white rounded-lg shadow-sm">
                                <span class="text-gray-600 font-medium">Team</span>
                                <span class="text-gray-800">${item.team ? item.team.name : 'N/A'}</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                ${item.labels && item.labels.length > 0 ? `
                <div class="mt-8">
                    <div class="bg-gradient-to-br from-purple-50 to-pink-50 rounded-xl p-6 border border-purple-100">
                        <h4 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                            <div class="w-8 h-8 bg-purple-100 rounded-lg flex items-center justify-center mr-3">
                                <i class="fas fa-tags text-purple-600"></i>
                            </div>
                            Labels
                        </h4>
                        <div class="flex flex-wrap gap-3">
                            ${item.labels.map(label => `
                                <span class="inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium shadow-sm" 
                                      style="background-color: ${label.color}; color: ${label.text_color || '#ffffff'};">
                                    <i class="fas fa-tag mr-2"></i>${label.name}
                                </span>
                            `).join('')}
                        </div>
                    </div>
                </div>
                ` : ''}
            `;
        }
        
        function showContentsTab() {
            const children = currentItemData.children || [];
            const content = document.getElementById('itemDetailsContent');
            
            if (children.length === 0) {
                content.innerHTML = `
                    <div class="text-center py-16">
                        <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-cubes text-3xl text-gray-400"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-800 mb-2">No Contents</h3>
                        <p class="text-gray-600">This item doesn't contain any other items.</p>
                    </div>
                `;
                return;
            }
            
            content.innerHTML = `
                <div class="mb-6">
                    <h4 class="text-lg font-semibold text-gray-800 mb-2">Contents Overview</h4>
                    <p class="text-gray-600">This item contains <strong>${children.length}</strong> item${children.length !== 1 ? 's' : ''}.</p>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    ${children.map(child => `
                        <div class="bg-white rounded-xl p-4 border border-gray-200 hover:shadow-lg transition-all duration-200 cursor-pointer hover:border-indigo-300" onclick="viewItemDetails('${child.id}')">
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-10 h-10 bg-indigo-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-cube text-indigo-600"></i>
                                </div>
                                <i class="fas fa-external-link-alt text-gray-400 hover:text-indigo-600 transition"></i>
                            </div>
                            <h5 class="font-semibold text-gray-800 mb-1">${child.name}</h5>
                            <p class="text-xs text-gray-500 mb-2">ID: ${child.id}</p>
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-gray-600">Click to view details</span>
                                <span class="text-indigo-600">→</span>
                            </div>
                        </div>
                    `).join('')}
                </div>
            `;
        }
        
        function showHistoryTab() {
            const history = currentItemData.history || [];
            const content = document.getElementById('itemDetailsContent');
            
            if (history.length === 0) {
                content.innerHTML = `
                    <div class="text-center py-16">
                        <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-history text-3xl text-gray-400"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-800 mb-2">No History</h3>
                        <p class="text-gray-600">No recent changes have been recorded for this item.</p>
                    </div>
                `;
                return;
            }
            
            content.innerHTML = `
                <div class="mb-6">
                    <h4 class="text-lg font-semibold text-gray-800 mb-2">Change History</h4>
                    <p class="text-gray-600">Recent changes to this item (showing last ${history.length} changes).</p>
                </div>
                
                <div class="space-y-4">
                    ${history.map(change => `
                        <div class="bg-white rounded-xl p-4 border border-gray-200 hover:shadow-md transition-shadow">
                            <div class="flex items-start justify-between">
                                <div class="flex items-start space-x-3 flex-1">
                                    <div class="w-8 h-8 bg-indigo-100 rounded-lg flex items-center justify-center flex-shrink-0 mt-1">
                                        <i class="fas fa-edit text-indigo-600 text-sm"></i>
                                    </div>
                                    <div class="flex-1">
                                        <h5 class="font-medium text-gray-800 mb-1">
                                            ${change.field_name.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())} Changed
                                        </h5>
                                        ${change.old_value_name && change.new_value_name ? `
                                        <div class="flex items-center gap-3 mb-2">
                                            <span class="inline-flex items-center px-3 py-1 rounded-lg text-sm bg-red-50 text-red-800 border border-red-200">
                                                <i class="fas fa-minus-circle mr-2"></i>${change.old_value_name}
                                            </span>
                                            <i class="fas fa-arrow-right text-gray-400"></i>
                                            <span class="inline-flex items-center px-3 py-1 rounded-lg text-sm bg-green-50 text-green-800 border border-green-200">
                                                <i class="fas fa-plus-circle mr-2"></i>${change.new_value_name}
                                            </span>
                                        </div>
                                        ` : ''}
                                        <div class="flex items-center gap-4 text-sm text-gray-500">
                                            <span class="flex items-center">
                                                <i class="fas fa-clock mr-1"></i>
                                                ${new Date(change.changed_at).toLocaleDateString('en-US', { 
                                                    month: 'short', 
                                                    day: 'numeric',
                                                    hour: '2-digit',
                                                    minute: '2-digit'
                                                })}
                                            </span>
                                            <span class="flex items-center">
                                                <i class="fas fa-user mr-1"></i>
                                                ${change.user.name}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `).join('')}
                </div>
            `;
        }
        
        function closeItemDetailsModal() {
            document.getElementById('itemDetailsModal').classList.add('hidden');
        }
        
        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            startAutoRefresh();
        });
        
        // Stop auto-refresh when page is hidden
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                stopAutoRefresh();
            } else {
                startAutoRefresh();
            }
        });
    </script>
</body>
</html>
