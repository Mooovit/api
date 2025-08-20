<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Laravel') }} - Kanban Board</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Axios for API calls -->
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <!-- SortableJS for drag and drop -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <style>
        .kanban-board {
            min-height: 500px;
        }
        .kanban-item {
            transition: all 0.2s ease;
        }
        .kanban-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        .sortable-ghost {
            opacity: 0.4;
        }
        .sortable-chosen {
            transform: rotate(5deg);
        }
        .modal {
            backdrop-filter: blur(4px);
        }
        .sidebar {
            min-height: 100vh;
        }
        .sidebar-item {
            transition: all 0.2s ease;
        }
        .sidebar-item:hover {
            background: rgba(99, 102, 241, 0.1);
            border-left: 4px solid #6366f1;
        }
        .sidebar-item.active {
            background: rgba(99, 102, 241, 0.15);
            border-left: 4px solid #6366f1;
            color: #6366f1;
        }
        .scan-input {
            font-family: 'Courier New', monospace;
        }
        .pulse-animation {
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .management-tab.active {
            background: white;
            color: #6366f1;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        .management-tab {
            color: #6b7280;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-100 to-indigo-200 min-h-screen flex">
    <!-- Left Sidebar -->
    <div class="sidebar bg-white/90 backdrop-blur shadow-xl w-80 flex-shrink-0">
        <!-- Header -->
        <div class="p-6 border-b border-gray-200">
            <h1 class="text-xl font-bold text-gray-800">
                <i class="fas fa-tachometer-alt text-indigo-600 mr-2"></i>
                Dashboard
            </h1>
            <div class="text-sm text-gray-600 mt-1">
                {{ $user->current_team->name ?? 'Team' }}
            </div>
        </div>

        <!-- Navigation Menu -->
        <div class="p-4">
            <nav class="space-y-2">
                <!-- View Toggle -->
                <div class="mb-4">
                    <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Views</h3>
                    <a href="/kanban/status" class="sidebar-item flex items-center px-3 py-2 rounded-lg text-sm font-medium {{ $type === 'status' ? 'active' : 'text-gray-700 hover:text-indigo-600' }}">
                        <i class="fas fa-tasks mr-3"></i>
                        Status Board
                    </a>
                    <a href="/kanban/location" class="sidebar-item flex items-center px-3 py-2 rounded-lg text-sm font-medium {{ $type === 'location' ? 'active' : 'text-gray-700 hover:text-indigo-600' }}">
                        <i class="fas fa-map-marker-alt mr-3"></i>
                        Location Board
                    </a>
                </div>

                <!-- Quick Scan -->
                <div class="mb-4">
                    <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Quick Scan</h3>
                    <div class="bg-gray-50 rounded-lg p-3">
                        <label class="block text-xs font-medium text-gray-700 mb-2">Scan Item for Details</label>
                        <input type="text" id="quickScanInput" placeholder="Scan barcode..." 
                               class="scan-input w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                        <button onclick="quickScanItem()" class="w-full mt-2 bg-indigo-600 hover:bg-indigo-700 text-white py-2 px-3 rounded-md text-sm font-medium transition">
                            <i class="fas fa-search mr-2"></i>View Details
                        </button>
                    </div>
                </div>

                <!-- Batch Operations -->
                <div class="mb-4">
                    <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Batch Operations</h3>
                    <button onclick="openBatchModal()" class="sidebar-item w-full flex items-center px-3 py-2 rounded-lg text-sm font-medium text-gray-700 hover:text-indigo-600">
                        <i class="fas fa-layer-group mr-3"></i>
                        Batch Update {{ ucfirst($type) }}
                    </button>
                </div>

                <!-- Management -->
                <div class="mb-4">
                    <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Management</h3>
                    <button onclick="openManagementModal('location')" class="sidebar-item w-full flex items-center px-3 py-2 rounded-lg text-sm font-medium text-gray-700 hover:text-indigo-600">
                        <i class="fas fa-map-marker-alt mr-3"></i>
                        Manage Locations
                    </button>
                    <button onclick="openManagementModal('status')" class="sidebar-item w-full flex items-center px-3 py-2 rounded-lg text-sm font-medium text-gray-700 hover:text-indigo-600">
                        <i class="fas fa-tasks mr-3"></i>
                        Manage Statuses
                    </button>

                    <button onclick="openFilterModal()" class="sidebar-item w-full flex items-center px-3 py-2 rounded-lg text-sm font-medium text-gray-700 hover:text-indigo-600">
                        <i class="fas fa-filter mr-3"></i>
                        Filter View
                    </button>
                    <a href="/kanban/activity" class="sidebar-item w-full flex items-center px-3 py-2 rounded-lg text-sm font-medium text-gray-700 hover:text-indigo-600">
                        <i class="fas fa-history mr-3"></i>
                        Live Activity
                    </a>
                </div>
            </nav>
        </div>

        <!-- Live Stats -->
        <div class="p-4 border-t border-gray-200 mt-auto">
            <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Live Stats</h3>
            <div class="space-y-2">
                <div class="flex justify-between text-sm">
                    <span class="text-gray-600">Total {{ ucfirst($type) }}s:</span>
                    <span class="font-semibold">{{ $type === 'status' ? count($statuses) : count($locations) }}</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-600">Total Items:</span>
                    <span class="font-semibold" id="total-items">{{ collect(json_decode($jsonData, true))->sum(function($board) { return count($board['item']); }) }}</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-600">Recent Changes:</span>
                    <span class="font-semibold pulse-animation" id="recent-changes">{{ count($recentHistory) }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col h-screen overflow-hidden">
        <!-- Top Bar -->
        <div class="bg-white/80 backdrop-blur shadow-sm border-b border-gray-200 px-6 py-4 flex-shrink-0">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-gray-800">
                        {{ ucfirst($type) }} Board
                    </h2>
                    <p class="text-sm text-gray-600">Drag items between columns to update their {{ $type }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <button onclick="openSearchPage()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1 rounded-lg text-sm font-medium transition">
                        <i class="fas fa-search mr-2"></i>Search
                    </button>
                    <button onclick="refreshBoard()" class="text-gray-600 hover:text-indigo-600 transition">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                    <div class="text-sm text-gray-500" id="last-updated">
                        Last updated: <span id="update-time">{{ now()->format('H:i:s') }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Kanban Board -->
        <div class="flex-1 p-6 overflow-auto">
            <div class="bg-white/90 backdrop-blur rounded-xl shadow-xl p-6 min-h-full flex flex-col">
                <div id="kanban-container" class="flex gap-4 overflow-x-auto overflow-y-visible flex-1 min-h-0">
                        @foreach(json_decode($jsonData, true) as $board)
                        <div class="kanban-board bg-gray-50 rounded-lg p-4 min-w-[280px] flex-shrink-0" data-board-id="{{ $board['id'] }}">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="font-semibold text-gray-800">{{ $board['title'] }}</h3>
                                <span class="bg-indigo-100 text-indigo-800 text-xs font-medium px-2 py-1 rounded-full">
                                    {{ count($board['item']) }}
                                </span>
                            </div>
                            <div class="kanban-items space-y-2 max-h-96 overflow-y-auto" data-board-id="{{ $board['id'] }}">
                                @foreach($board['item'] as $item)
                                <div class="kanban-item bg-white p-3 rounded-lg shadow cursor-pointer border-l-4 border-indigo-400" 
                                     data-item-id="{{ $item['id'] }}" 
                                     data-status-id="{{ $item['status_id'] ?? '' }}"
                                     data-location-id="{{ $item['location_id'] ?? '' }}"
                                     data-parent-id="{{ $item['parent_id'] ?? '' }}"
                                     onclick="openItemDetails('{{ $item['id'] }}')">
                                    <div class="font-medium text-gray-800">{{ $item['title'] }}</div>
                                    
                                    <!-- Labels -->
                                    @if(!empty($item['labels']))
                                    <div class="flex flex-wrap gap-1 mt-2">
                                        @foreach($item['labels'] as $label)
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium" 
                                              style="background-color: {{ $label['color'] }}; color: {{ $label['text_color'] ?? '#ffffff' }};">
                                            <i class="fas fa-tag mr-1"></i>{{ $label['name'] }}
                                        </span>
                                        @endforeach
                                    </div>
                                    @endif
                                    
                                    <div class="flex items-center justify-between mt-2">
                                        <div class="flex flex-col space-y-1">
                                            @if($item['status_name'])
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                <i class="fas fa-tasks mr-1"></i>{{ $item['status_name'] }}
                                            </span>
                                            @endif
                                            @if($item['location_name'])
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                <i class="fas fa-map-marker-alt mr-1"></i>{{ $item['location_name'] }}
                                            </span>
                                            @endif
                                        </div>
                                        <div class="text-xs text-gray-400">
                                            <i class="fas fa-grip-vertical"></i>
                                        </div>
                                    </div>
                                </div>
                                @endforeach
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>


    </div>

    <!-- Batch Update Modal -->
    <div id="batchModal" class="fixed inset-0 z-50 hidden modal bg-black/40">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-bold text-gray-800">
                            <i class="fas fa-layer-group text-indigo-600 mr-2"></i>
                            Batch Update {{ ucfirst($type) }}
                        </h3>
                        <button onclick="closeBatchModal()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    
                    <div class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                                <select id="batchStatus" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                                    <option value="">Keep current status</option>
                                    @foreach($statuses as $status)
                                    <option value="{{ $status->id }}">{{ $status->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Location</label>
                                <select id="batchLocation" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                                    <option value="">Keep current location</option>
                                    @foreach($locations as $location)
                                    <option value="{{ $location->id }}">{{ $location->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Scan Items</label>
                            <input type="text" id="batchScanInput" placeholder="Scan items one by one..." 
                                   class="scan-input w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            <div class="text-xs text-gray-500 mt-1">Press Enter after each scan</div>
                        </div>
                        
                        <div id="scannedItems" class="hidden">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Scanned Items</label>
                            <div id="scannedItemsList" class="max-h-32 overflow-y-auto bg-gray-50 rounded-lg p-2 space-y-1">
                                <!-- Scanned items will appear here -->
                            </div>
                        </div>
                        
                        <div class="flex gap-3">
                            <button onclick="clearBatchItems()" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 py-2 px-4 rounded-lg font-medium transition">
                                <i class="fas fa-trash mr-2"></i>Clear Scanned Items
                            </button>
                            <div class="text-sm text-gray-600 flex items-center">
                                <i class="fas fa-info-circle mr-2"></i>
                                Items update automatically on scan
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Management Modal -->
    <div id="managementModal" class="fixed inset-0 z-50 hidden modal bg-black/40">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-xl font-bold text-gray-800">
                            <i class="fas fa-cog text-indigo-600 mr-2"></i>
                            Manage Statuses & Locations
                        </h3>
                        <button onclick="closeManagementModal()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    
                    <!-- Tab Navigation -->
                    <div class="flex mb-6 bg-gray-100 rounded-lg p-1">
                        <button onclick="switchManagementTab('status')" id="statusTab" class="flex-1 py-2 px-4 rounded-md text-sm font-medium transition management-tab active">
                            <i class="fas fa-tasks mr-2"></i>Statuses
                        </button>
                        <button onclick="switchManagementTab('location')" id="locationTab" class="flex-1 py-2 px-4 rounded-md text-sm font-medium transition management-tab">
                            <i class="fas fa-map-marker-alt mr-2"></i>Locations
                        </button>

                    </div>
                    
                    <!-- Status Management -->
                    <div id="statusManagement" class="management-content">
                        <!-- Add New Status -->
                        <div class="bg-gray-50 rounded-lg p-4 mb-6">
                            <h4 class="font-semibold text-gray-800 mb-3">Add New Status</h4>
                            <div class="flex gap-3">
                                <input type="text" id="newStatusName" placeholder="Enter status name" 
                                       class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                                <button onclick="createNewStatus()" class="bg-green-600 hover:bg-green-700 text-white py-2 px-4 rounded-lg font-medium transition">
                                    <i class="fas fa-plus mr-2"></i>Add
                                </button>
                            </div>
                        </div>
                        
                        <!-- Existing Statuses -->
                        <div>
                            <h4 class="font-semibold text-gray-800 mb-3">Existing Statuses</h4>
                            <div class="space-y-2">
                                @foreach($statuses as $status)
                                <div class="flex items-center justify-between p-3 bg-white border rounded-lg" data-id="{{ $status->id }}" data-type="status">
                                    <div class="flex-1">
                                        <input type="text" value="{{ $status->name }}" class="edit-input bg-transparent border-none p-0 font-medium text-gray-800 w-full" readonly>
                                    </div>
                                    <div class="flex gap-2">
                                        <button onclick="editItem('{{ $status->id }}', 'status')" class="text-blue-600 hover:text-blue-800">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button onclick="deleteItem('{{ $status->id }}', 'status')" class="text-red-600 hover:text-red-800">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    
                    <!-- Location Management -->
                    <div id="locationManagement" class="management-content hidden">
                        <!-- Add New Location -->
                        <div class="bg-gray-50 rounded-lg p-4 mb-6">
                            <h4 class="font-semibold text-gray-800 mb-3">Add New Location</h4>
                            <div class="flex gap-3">
                                <input type="text" id="newLocationName" placeholder="Enter location name" 
                                       class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                                <button onclick="createNewLocation()" class="bg-green-600 hover:bg-green-700 text-white py-2 px-4 rounded-lg font-medium transition">
                                    <i class="fas fa-plus mr-2"></i>Add
                                </button>
                            </div>
                        </div>
                        
                        <!-- Existing Locations -->
                        <div>
                            <h4 class="font-semibold text-gray-800 mb-3">Existing Locations</h4>
                            <div class="space-y-2">
                                @foreach($locations as $location)
                                <div class="flex items-center justify-between p-3 bg-white border rounded-lg" data-id="{{ $location->id }}" data-type="location">
                                    <div class="flex-1">
                                        <input type="text" value="{{ $location->name }}" class="edit-input bg-transparent border-none p-0 font-medium text-gray-800 w-full" readonly>
                                    </div>
                                    <div class="flex gap-2">
                                        <button onclick="editItem('{{ $location->id }}', 'location')" class="text-blue-600 hover:text-blue-800">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button onclick="deleteItem('{{ $location->id }}', 'location')" class="text-red-600 hover:text-red-800">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    

                </div>
            </div>
        </div>
    </div>

    <!-- Enhanced Filter Modal -->
    <div id="filterModal" class="fixed inset-0 z-50 hidden modal bg-black/40">
        <div class="flex items-center justify-center min-h-screen p-2">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-[98vw] h-[98vh] overflow-hidden">
                <div class="p-6 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-xl font-bold text-gray-800">
                            <i class="fas fa-filter text-indigo-600 mr-2"></i>
                            Detailed Filter View
                        </h3>
                        <button onclick="closeFilterModal()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    
                    <div class="mt-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Filter by {{ ucfirst($type) }}</label>
                        <select id="filterSelect" class="w-full max-w-md px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" onchange="applyDetailedFilter()">
                            <option value="">Show All Items</option>
                            @if($type === 'status')
                                @foreach($statuses as $status)
                                <option value="{{ $status->id }}">{{ $status->name }}</option>
                                @endforeach
                            @else
                                @foreach($locations as $location)
                                <option value="{{ $location->id }}">{{ $location->name }}</option>
                                @endforeach
                            @endif
                        </select>
                    </div>
                </div>
                
                <div class="p-6 overflow-y-auto max-h-[calc(95vh-200px)]">
                    <div id="filteredItemsContainer" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <!-- Filtered items will appear here -->
                    </div>
                    <div id="noFilterResults" class="hidden text-center py-12">
                        <i class="fas fa-search text-4xl text-gray-400 mb-4"></i>
                        <p class="text-gray-600">No items found for the selected filter</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Enhanced Item Details Modal -->
    <div id="itemDetailsModal" class="fixed inset-0 z-50 hidden modal bg-black/40">
        <div class="flex items-center justify-center min-h-screen p-2">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-[95vw] h-[95vh] overflow-y-auto">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-xl font-bold text-gray-800">
                            <i class="fas fa-cube text-indigo-600 mr-2"></i>
                            Item Details
                        </h3>
                        <button onclick="closeItemDetailsModal()" class="text-gray-400 hover:text-gray-600 text-xl">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    
                    <div id="itemDetailsContent" class="space-y-6">
                        <!-- Content will be loaded dynamically -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Search Modal -->
    <div id="searchModal" class="fixed inset-0 z-50 hidden modal bg-black/40">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-4xl max-h-[90vh] overflow-hidden">
                <div class="p-6 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-xl font-bold text-gray-800">
                            <i class="fas fa-search text-indigo-600 mr-2"></i>
                            Search Items & Boxes
                        </h3>
                        <button onclick="closeSearchModal()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    
                    <div class="mt-4">
                        <div class="relative">
                            <input type="text" id="searchInput" placeholder="Search by name, ID, or scan barcode..." 
                                   class="w-full px-4 py-3 pl-12 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-lg">
                            <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        </div>
                        <div class="flex gap-2 mt-3">
                            <button onclick="setSearchFilter('all')" class="search-filter-btn active px-3 py-1 rounded-full text-sm font-medium bg-indigo-100 text-indigo-800">
                                All Items
                            </button>
                            <button onclick="setSearchFilter('boxes')" class="search-filter-btn px-3 py-1 rounded-full text-sm font-medium bg-gray-100 text-gray-700 hover:bg-gray-200">
                                Boxes Only
                            </button>
                            <button onclick="setSearchFilter('items')" class="search-filter-btn px-3 py-1 rounded-full text-sm font-medium bg-gray-100 text-gray-700 hover:bg-gray-200">
                                Items Only
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="p-6 overflow-y-auto max-h-[calc(90vh-200px)]">
                    <div id="searchResults" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <!-- Search results will appear here -->
                    </div>
                    <div id="searchLoading" class="hidden text-center py-12">
                        <i class="fas fa-spinner fa-spin text-4xl text-gray-400 mb-4"></i>
                        <p class="text-gray-600">Searching...</p>
                    </div>
                    <div id="noSearchResults" class="hidden text-center py-12">
                        <i class="fas fa-search text-4xl text-gray-400 mb-4"></i>
                        <p class="text-gray-600">No items found matching your search</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Move Item Modal -->
    <div id="moveItemModal" class="fixed inset-0 z-50 hidden modal bg-black/40">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-md">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-bold text-gray-800">
                            <i class="fas fa-arrows-alt text-indigo-600 mr-2"></i>
                            Move Item
                        </h3>
                        <button onclick="closeMoveItemModal()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Move to Parent Box</label>
                            <div class="space-y-2">
                                <input type="text" id="moveToBoxName" placeholder="Type parent box name..." 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                                <div class="text-center text-gray-500">or</div>
                                <input type="text" id="moveToBoxScan" placeholder="Scan parent box barcode..." 
                                       class="scan-input w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                        </div>
                        
                        <div class="flex gap-3">
                            <button onclick="moveItemToParent()" class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white py-2 px-4 rounded-lg font-medium transition">
                                <i class="fas fa-arrow-right mr-2"></i>Move Item
                            </button>
                            <button onclick="removeFromParent()" class="px-4 py-2 bg-orange-100 hover:bg-orange-200 text-orange-700 rounded-lg font-medium transition">
                                Remove Parent
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>



    <!-- Audio elements for sound effects -->
    <audio id="successSound" preload="auto">
        <source src="{{ asset('success.mp3') }}" type="audio/mpeg">
    </audio>
    <audio id="errorSound" preload="auto">
        <source src="{{ asset('error.mp3') }}" type="audio/mpeg">
    </audio>
    <audio id="alreadySound" preload="auto">
        <source src="{{ asset('already.mp3') }}" type="audio/mpeg">
    </audio>
    <audio id="warningSound" preload="auto">
        <source src="{{ asset('warning-label.mp3') }}" type="audio/mpeg">
    </audio>

    <!-- Custom Kanban JS -->
    <script src="{{ asset('js/kanban.js') }}?v={{ time() }}"></script>
    
<script>

        
        // Initialize the kanban board when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            initializeKanban('{{ $type }}', '{{ $query["field"] }}');
            
            // Initialize real-time updates
            startRealTimeUpdates();
    });
</script>
</body>
</html>
