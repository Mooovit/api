// Global variables
let boardType = '';
let fieldName = '';
let sortableInstances = [];
let batchItems = [];
let currentItemId = null;
let realTimeInterval = null;
/* Live sync state (API-003/006): poll the revision, pull only the delta */
let lastRevision = 0;
let lastSyncAt = null;
/* API-026: the activity feed's incremental cursor (newest changed_at shown) */
let historyCursor = null;
let teamLabels = [];

// Function to determine if text should be white or black based on background color
function getTextColor(backgroundColor) {
    // Remove # if present
    const hex = backgroundColor.replace('#', '');
    
    // Convert to RGB
    const r = parseInt(hex.substr(0, 2), 16);
    const g = parseInt(hex.substr(2, 2), 16);
    const b = parseInt(hex.substr(4, 2), 16);
    
    // Calculate luminance using the relative luminance formula
    const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
    
    // Return white text for dark backgrounds, black text for light backgrounds
    return luminance > 0.5 ? '#000000' : '#ffffff';
}

console.log('Kanban.js loaded successfully');

// Test function to verify script loading
window.testKanban = function() {
    console.log('Kanban test function called');
    alert('Kanban script is working!');
};



// Initialize the kanban board
function initializeKanban(type, field, revision, builtAt) {
    console.log('Initializing kanban with type:', type, 'field:', field);
    boardType = type;
    fieldName = field;
    lastRevision = typeof revision === 'number' ? revision : parseInt(revision || 0, 10);
    lastSyncAt = builtAt || new Date().toISOString();

    initializeSortable();
    initializeEventListeners();
    console.log('Kanban initialization complete');
}

function initializeSortable() {
    const boards = document.querySelectorAll('.kanban-items');
    
    boards.forEach(board => {
        const sortable = Sortable.create(board, {
            group: 'kanban',
            animation: 150,
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            onEnd: function(evt) {
                const itemId = evt.item.dataset.itemId;
                const newBoardId = evt.to.dataset.boardId;
                const currentStatusId = evt.item.dataset.statusId;
                const currentLocationId = evt.item.dataset.locationId;
                
                // Update the appropriate field based on board type
                updateItemByDragDrop(itemId, newBoardId, currentStatusId, currentLocationId);
            }
        });
        sortableInstances.push(sortable);
    });
}

function initializeEventListeners() {
    // Quick scan input
    const quickScanInput = document.getElementById('quickScanInput');
    if (quickScanInput) {
        quickScanInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                quickScanItem();
            }
        });
    }

    // Batch scan input
    const batchScanInput = document.getElementById('batchScanInput');
    if (batchScanInput) {
        batchScanInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                addToBatch();
            }
        });
    }

    // Move item inputs
    const moveToBoxScan = document.getElementById('moveToBoxScan');
    if (moveToBoxScan) {
        moveToBoxScan.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                moveItemToParent();
            }
        });
    }
}

// Sound effects
function playSound(type) {
    try {
        const audio = document.getElementById(type + 'Sound');
        if (audio) {
            audio.currentTime = 0;
            audio.play().catch(e => console.log('Audio play failed:', e));
        }
    } catch (error) {
        console.log('Sound error:', error);
    }
}

// Enhanced notification system with sounds
function showNotification(message, type = 'info', playAudio = false, customSound = null) {
    // Play sound only when explicitly requested (for transport operations)
    if (playAudio) {
        if (customSound) {
            playSound(customSound);
        } else if (type === 'success') {
            playSound('success');
        } else if (type === 'error') {
            playSound('error');
        } else if (type === 'already') {
            playSound('already');
        }
    }

    const notification = document.createElement('div');
    notification.className = `fixed top-4 right-4 z-50 px-6 py-3 rounded-lg shadow-lg text-white font-medium transition-all duration-300 ${
        type === 'success' ? 'bg-green-500' : 
        type === 'error' ? 'bg-red-500' : 
        type === 'already' ? 'bg-orange-500' :
        'bg-blue-500'
    }`;
    
    // Add icon
    const icon = type === 'success' ? 'fas fa-check' : 
                 type === 'error' ? 'fas fa-exclamation-triangle' :
                 type === 'already' ? 'fas fa-info-circle' :
                 'fas fa-info';
    
    notification.innerHTML = `<i class="${icon} mr-2"></i>${message}`;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.opacity = '0';
        notification.style.transform = 'translateX(100%)';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

// Real-time updates (revision-based, API-003 + API-006 session flavor):
// poll GET /kanban/revision every 5s; only when the counter moved do we pull
// GET /kanban/delta?since=… (board) and — since API-026 — GET
// /kanban/history?after=… (activity feed). An unchanged revision issues NO
// history request at all.
let currentHistoryData = [];

function startRealTimeUpdates() {
    setLiveState('ok');
    realTimeInterval = setInterval(async () => {
        try {
            const moved = await pollRevision();
            if (moved) {
                refreshHistory();
            }
            updateLastUpdatedTime();
        } catch (error) {
            console.error('Live sync error:', error);
            setLiveState('down');
        }
    }, 5000);
}

function setLiveState(state) {
    const dot = document.getElementById('live-dot');
    const label = document.getElementById('live-label');
    const indicator = document.getElementById('live-indicator');
    if (!dot || !label || !indicator) return;

    if (state === 'down') {
        dot.className = 'w-2 h-2 rounded-full bg-red-500';
        label.textContent = 'Offline';
        indicator.classList.add('bg-red-50');
    } else {
        dot.className = 'w-2 h-2 rounded-full bg-green-500';
        label.textContent = 'Live';
        indicator.classList.remove('bg-red-50');
    }
}

function setRevisionBadge(revision) {
    const badge = document.getElementById('revision-badge');
    if (badge) badge.textContent = revision;
}

async function pollRevision() {
    const response = await axios.get('/kanban/revision', {
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Accept': 'application/json'
        }
    });

    const revision = response.data.revision;
    setRevisionBadge(revision);

    if (revision !== lastRevision) {
        await applyDelta();
        return true;
    }
    return false;
}

async function applyDelta() {
    const response = await axios.get('/kanban/delta', {
        params: { since: lastSyncAt },
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Accept': 'application/json'
        }
    });

    const data = response.data;
    const changed = data.changed || [];
    const deletedIds = data.deleted_ids || [];

    changed.forEach(row => {
        applyRowToBoard(row);
        /* Keep the path map fresh for the locations these rows moved to —
           locationDisplay() reads it, so a refreshed modal/card must not
           resolve a stale page-load path. */
        if (row.location_id && row.location_path) {
            window.KANBAN_LOCATION_PATHS = window.KANBAN_LOCATION_PATHS || {};
            window.KANBAN_LOCATION_PATHS[row.location_id] = row.location_path;
        }
    });
    deletedIds.forEach(id => removeCard(id));

    lastRevision = data.revision;
    lastSyncAt = new Date().toISOString();
    setRevisionBadge(data.revision);
    teamLabels = []; /* label catalogue may have changed (its CRUD bumps the revision) */
    updateTotalItems();

    if (changed.length || deletedIds.length) {
        showNotification(`Board updated — ${changed.length} changed, ${deletedIds.length} removed`, 'info');
        /* Keep an OPEN details modal truthful: refresh it when its item is
           among the changed rows (or one of the viewed item's children is),
           close it when its item was removed. */
        await refreshOpenItemDetailsIfAffected(changed, deletedIds);
    }
}

/**
 * Apply one delta row to the board: upsert into the column matching the
 * board's field, or drop the card (children and items without a value for
 * this board type belong to no column).
 */
function applyRowToBoard(row) {
    if (row.parent_id || !row[fieldName]) {
        removeCard(row.id);
        return;
    }

    const column = document.querySelector(`.kanban-items[data-board-id="${row[fieldName]}"]`);
    if (!column) {
        removeCard(row.id);
        return;
    }

    const existing = document.querySelector(`.kanban-item[data-item-id="${row.id}"]`);
    const previousColumn = existing ? existing.closest('.kanban-items') : null;

    if (previousColumn && previousColumn !== column) {
        /* Moved between columns: move the DOM node, keep focus/scroll */
        column.appendChild(existing);
        existing.outerHTML = buildCardHtml(row);
    } else if (existing) {
        existing.outerHTML = buildCardHtml(row);
    } else {
        column.insertAdjacentHTML('beforeend', buildCardHtml(row));
    }

    if (previousColumn) refreshColumnCount(previousColumn);
    refreshColumnCount(column);
}

/**
 * Re-render one board row — same markup as the blade template so delta rows
 * are indistinguishable from server-rendered ones.
 */
function buildCardHtml(row) {
    const labelsHtml = (row.labels && row.labels.length) ? `
                                    <div class="flex flex-wrap gap-1 mt-2">
                                        ${row.labels.map(label => `
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium"
                                              style="background-color: ${label.color}; color: ${label.text_color || '#ffffff'};">
                                            <i class="fas fa-tag mr-1"></i>${label.name}
                                        </span>
                                        `).join('')}
                                    </div>` : '';

    return `
                                <div class="kanban-item bg-white p-3 rounded-lg shadow cursor-pointer border-l-4 border-indigo-400"
                                     data-item-id="${row.id}"
                                     data-status-id="${row.status_id || ''}"
                                     data-location-id="${row.location_id || ''}"
                                     data-parent-id="${row.parent_id || ''}"
                                     onclick="openItemDetails('${row.id}')">
                                    <div class="font-medium text-gray-800">${escapeHtml(row.title)}</div>
                                    ${labelsHtml}
                                    <div class="flex items-center justify-between mt-2">
                                        <div class="flex flex-col space-y-1">
                                            ${row.status_name ? `
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                <i class="fas fa-tasks mr-1"></i>${escapeHtml(row.status_name)}
                                            </span>` : ''}
                                            ${row.location_name ? `
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                <i class="fas fa-map-marker-alt mr-1"></i>${escapeHtml(row.location_path || row.location_name)}
                                            </span>` : ''}
                                        </div>
                                        <div class="text-xs text-gray-400 flex items-center gap-2">
                                            <button onclick="event.stopPropagation(); showCardQr('${row.id}')" title="Show public share QR" class="hover:text-indigo-600">
                                                <i class="fas fa-qrcode"></i>
                                            </button>
                                            <i class="fas fa-grip-vertical"></i>
                                        </div>
                                    </div>
                                </div>`;
}

function removeCard(itemId) {
    const card = document.querySelector(`.kanban-item[data-item-id="${itemId}"]`);
    if (!card) return;
    const column = card.closest('.kanban-items');
    card.remove();
    if (column) refreshColumnCount(column);
}

function refreshColumnCount(column) {
    const board = column.closest('.kanban-board');
    if (!board) return;
    const countElement = board.querySelector('.bg-indigo-100');
    if (countElement) {
        countElement.textContent = column.querySelectorAll('.kanban-item').length;
    }
}

function updateTotalItems() {
    const total = document.querySelectorAll('.kanban-item').length;
    const totalElement = document.getElementById('total-items');
    if (totalElement) totalElement.textContent = total;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text == null ? '' : String(text);
    return div.innerHTML;
}

// API-036: resolve a serialized location to its full path
// ("Garage > Black shelf") using the server-rendered team map
// (window.KANBAN_LOCATION_PATHS); falls back to the bare name.
function locationDisplay(location) {
    if (!location) return null;
    const paths = window.KANBAN_LOCATION_PATHS || {};
    return paths[location.id] || location.name;
}

function updateLastUpdatedTime() {
    const timeElement = document.getElementById('update-time');
    if (timeElement) {
        timeElement.textContent = new Date().toLocaleTimeString();
    }
}

// API Functions
async function updateItemImmediately(itemId, statusId, locationId) {
    try {
        const data = {};
        if (statusId) data.status_id = statusId;
        if (locationId) data.location_id = locationId;
        
        console.log('Batch update - sending POST to /item/' + itemId, data);
        
        const response = await axios.post('/item/' + itemId, data, {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            }
        });
        
        if (response.data) {
            // Check if item has labels to play warning sound
            let itemHasLabels = false;
            try {
                const itemDetailsResponse = await axios.get('/kanban/item/' + itemId, {
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                        'Accept': 'application/json',
                        'Content-Type': 'application/json'
                    }
                });
                if (itemDetailsResponse.data && itemDetailsResponse.data.item) {
                    const labels = itemDetailsResponse.data.item.labels || [];
                    itemHasLabels = labels.length > 0;
                }
            } catch (labelError) {
                console.error('Error checking item labels:', labelError);
            }
            
            // Play warning sound if item has labels, otherwise success sound
            if (itemHasLabels) {
                playSound('warningSound');
            } else {
                playSound('successSound');
            }
            
            refreshHistory();
            updateLastUpdatedTime();
            return true;
        }
    } catch (error) {
        console.error('Error updating item:', error);
        console.error('Response:', error.response);
        showNotification('Error updating item: ' + itemId + ' - ' + (error.response?.data?.message || error.message), 'error', true);
        return false;
    }
}

async function updateItemByDragDrop(itemId, newBoardId, currentStatusId, currentLocationId) {
    try {
        // Use the same technique as batch update - simpler and more reliable
        const data = {};
        
        // Update the appropriate field based on board type
        if (boardType === 'status') {
            data.status_id = newBoardId;
            // Keep current location if it exists
            if (currentLocationId) {
                data.location_id = currentLocationId;
            }
        } else if (boardType === 'location') {
            data.location_id = newBoardId;
            // Keep current status if it exists
            if (currentStatusId) {
                data.status_id = currentStatusId;
            }
        }
        
        console.log('Drag & Drop - sending POST to /item/' + itemId, data);
        
        const response = await axios.post('/item/' + itemId, data, {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            }
        });
        
        if (response.data) {
            // Check if item has labels to play warning sound
            const itemElement = document.querySelector(`[data-item-id="${itemId}"]`);
            const hasLabels = itemElement && itemElement.querySelector('.fas.fa-tag');
            
            if (hasLabels) {
                showNotification(`Item ${boardType} updated successfully! (Has labels)`, 'success', true, 'warning-label');
            } else {
                showNotification(`Item ${boardType} updated successfully!`, 'success', true);
            }
            
            refreshHistory();
            updateLastUpdatedTime();
            
            // Update the item's data attributes
            if (data.status_id) itemElement.dataset.statusId = data.status_id;
            if (data.location_id) itemElement.dataset.locationId = data.location_id;
        }
    } catch (error) {
        console.error('Error updating item:', error);
        console.error('Response:', error.response);
        showNotification('Error updating item: ' + (error.response?.data?.message || error.message), 'error', true);
        // Revert the move by refreshing the page
        setTimeout(() => location.reload(), 1000);
    }
}

async function updateItemStatus(itemId, newValue) {
    try {
        const data = {};
        data[fieldName] = newValue;
        
        console.log('Legacy update - sending POST to /item/' + itemId, data);
        
        const response = await axios.post('/item/' + itemId, data, {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            }
        });
        
        if (response.data) {
            showNotification(`Item ${fieldName.replace('_', ' ')} updated successfully!`, 'success', true); // Play sound for transport
            refreshHistory();
            updateLastUpdatedTime();
        }
    } catch (error) {
        console.error('Error updating item:', error);
        console.error('Response:', error.response);
        showNotification('Error updating item: ' + (error.response?.data?.message || error.message), 'error', true);
        // Revert the move by refreshing the page
        setTimeout(() => location.reload(), 1000);
    }
}

// Quick scan functionality
async function quickScanItem() {
    const itemId = document.getElementById('quickScanInput').value.trim();
    
    if (!itemId) {
        showNotification('Please enter an item ID', 'error');
        return;
    }

    await openItemDetails(itemId);
    document.getElementById('quickScanInput').value = '';
}

// Enhanced item details
async function openItemDetails(itemId) {
    try {
        await fetchItemDetails(itemId);
        currentItemId = itemId;
        document.getElementById('itemDetailsModal').classList.remove('hidden');
        showNotification('Item details loaded', 'success'); // No sound for details loading
    } catch (error) {
        console.error('Error fetching item details:', error);
        showNotification('Item not found or error loading details', 'error');
    }
}

/* Fetch one item's details and render them into the modal — no toasts, no
   modal-visibility side effects. Throws on fetch errors so callers can
   react (openItemDetails notifies; the delta refresh falls back to
   closing the modal). */
async function fetchItemDetails(itemId) {
    const response = await axios.get('/kanban/item/' + itemId, {
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Accept': 'application/json',
            'Content-Type': 'application/json'
        }
    });

    await loadTeamLabels();
    displayEnhancedItemDetails(response.data);
    return response.data;
}

/* Delta follow-up: the board just moved under an open details modal.
   Refresh the modal when its item is among the changed rows (or one of
   ITS children changed — the Contents list), close it when its item was
   removed (the re-fetch would 404: soft-deleted items are unaddressable).
   An unaffected item's modal is left alone — mid-edit state (label
   select, barcode input) is never wiped without a reason. */
async function refreshOpenItemDetailsIfAffected(changed, deletedIds) {
    if (!currentItemId) return;
    const modal = document.getElementById('itemDetailsModal');
    if (!modal || modal.classList.contains('hidden')) return;

    if (deletedIds.includes(currentItemId)) {
        closeItemDetailsModal();
        return;
    }
    if (changed.some(row => row.id === currentItemId || row.parent_id === currentItemId)) {
        try {
            await fetchItemDetails(currentItemId);
        } catch (error) {
            console.error('Error refreshing item details:', error);
            closeItemDetailsModal();
        }
    }
}

/* The team's label catalogue — cached, refreshed whenever the revision moves */
async function loadTeamLabels() {
    if (teamLabels.length) return;
    try {
        const response = await axios.get('/kanban/labels', {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json'
            }
        });
        teamLabels = response.data || [];
    } catch (error) {
        console.error('Error loading labels:', error);
        teamLabels = [];
    }
}

function displayEnhancedItemDetails(data) {
    const content = document.getElementById('itemDetailsContent');

    /* API-025: remember the active share URL for the QR/copy actions */
    currentShareUrl = (data.share && data.share.share_url) ? data.share.share_url : null;

    content.innerHTML = `
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Item Information -->
            <div class="bg-gradient-to-r from-indigo-50 to-blue-50 rounded-xl p-6">
                <div class="flex items-center justify-between mb-4">
                    <h4 class="text-lg font-bold text-gray-800">
                        <i class="fas fa-info-circle text-indigo-600 mr-2"></i>
                        Item Information
                    </h4>
                    <div class="flex gap-2">
                        <button onclick="openMoveItemModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1 rounded-lg text-sm font-medium transition">
                            <i class="fas fa-arrows-alt mr-1"></i>Move
                        </button>

                    </div>
                </div>
                
                ${data.item.parent ? `
                    <div class="mb-4 p-3 bg-orange-50 border border-orange-200 rounded-lg">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <i class="fas fa-level-up-alt text-orange-600 mr-2"></i>
                                <span class="text-sm font-medium text-orange-800">Parent Container:</span>
                            </div>
                            <button onclick="openItemDetails('${data.item.parent.id}')" class="text-orange-600 hover:text-orange-800 text-sm font-medium">
                                <i class="fas fa-external-link-alt mr-1"></i>
                                ${data.item.parent.name}
                            </button>
                        </div>
                    </div>
                ` : ''}
                
                <div class="space-y-3">
                    <div class="flex justify-between items-center p-3 bg-white rounded-lg">
                        <span class="font-medium text-gray-700">Name:</span>
                        <span class="text-gray-900 font-semibold">${data.item.name}</span>
                    </div>
                    <div class="flex justify-between items-center p-3 bg-white rounded-lg">
                        <span class="font-medium text-gray-700">Status:</span>
                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                            ${data.item.status ? data.item.status.name : 'No status'}
                        </span>
                    </div>
                    <div class="flex justify-between items-center p-3 bg-white rounded-lg">
                        <span class="font-medium text-gray-700">Location:</span>
                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                            ${data.item.location ? locationDisplay(data.item.location) : 'No location'}
                        </span>
                    </div>
                    <div class="flex justify-between items-center p-3 bg-white rounded-lg">
                        <span class="font-medium text-gray-700">ID:</span>
                        <code class="text-sm bg-gray-100 px-2 py-1 rounded">${data.item.id}</code>
                    </div>
                    <div class="flex justify-between items-center p-3 bg-white rounded-lg">
                        <span class="font-medium text-gray-700">Created:</span>
                        <span class="text-gray-900">${new Date(data.item.created_at).toLocaleDateString()}</span>
                    </div>
                    <div class="flex justify-between items-center p-3 bg-white rounded-lg">
                        <span class="font-medium text-gray-700">Last Updated:</span>
                        <span class="text-gray-900">${new Date(data.item.updated_at).toLocaleString()}</span>
                    </div>
                    ${data.item.labels && data.item.labels.length > 0 ? `
                        <div class="p-3 bg-white rounded-lg">
                            <span class="font-medium text-gray-700 block mb-2">Labels:</span>
                            <div class="flex flex-wrap gap-2">
                                ${data.item.labels.map(label => `
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium"
                                          style="background-color: ${label.color}; color: ${getTextColor(label.color)};">
                                        <i class="fas fa-tag mr-1"></i>${label.name}
                                        <button onclick="detachLabelFromCurrentItem('${label.id}')" title="Detach label" class="ml-1 opacity-70 hover:opacity-100">
                                            <i class="fas fa-times-circle"></i>
                                        </button>
                                    </span>
                                `).join('')}
                            </div>
                        </div>
                    ` : ''}
                </div>

                <!-- Identity QR (API-028): the item's own uuid, scannable -->
                <div class="mt-4 p-3 bg-white border border-indigo-100 rounded-lg text-center">
                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">
                        <i class="fas fa-qrcode text-indigo-600 mr-1"></i>Box QR (uuid)
                    </div>
                    <div id="detailsUuidQr" class="inline-block"></div>
                    <div class="mt-2 text-xs text-gray-500 break-all select-all">${data.item.id}</div>
                    <button onclick="copyItemUuid('${data.item.id}')" class="mt-2 bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1 rounded-lg text-xs font-medium transition">
                        <i class="fas fa-copy mr-1"></i>Copy uuid
                    </button>
                </div>
            </div>

            <!-- Contents -->
            <div class="bg-gradient-to-r from-green-50 to-emerald-50 rounded-xl p-6">
                <h4 class="text-lg font-bold text-gray-800 mb-4">
                    <i class="fas fa-boxes text-green-600 mr-2"></i>
                    Contents (${data.children.length} items)
                </h4>
                ${data.children.length > 0 ? `
                    <div class="space-y-2 max-h-64 overflow-y-auto">
                        ${data.children.map(child => `
                            <div class="bg-white border rounded-lg p-3 cursor-pointer hover:bg-gray-50 transition" onclick="openItemDetails('${child.id}')">
                                <div class="flex items-center justify-between">
                                    <div class="font-medium text-gray-800">${child.name}</div>
                                    <i class="fas fa-external-link-alt text-gray-400"></i>
                                </div>
                                <div class="text-xs text-gray-500 mt-1">Click to view details</div>
                            </div>
                        `).join('')}
                    </div>
                ` : `
                    <div class="text-center py-8 text-gray-500">
                        <i class="fas fa-inbox text-4xl mb-3"></i>
                        <p>No contents in this item</p>
                    </div>
                `}
            </div>
        </div>

        <!-- Labels & Barcodes management -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-gradient-to-r from-amber-50 to-yellow-50 rounded-xl p-6">
                <h4 class="text-lg font-bold text-gray-800 mb-4">
                    <i class="fas fa-tags text-amber-600 mr-2"></i>
                    Attach a Label
                </h4>
                <div class="flex gap-2">
                    <select id="detailLabelSelect" class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm">
                        <option value="">Choose a label…</option>
                        ${teamLabels.filter(label => !(data.item.labels || []).some(attached => attached.id === label.id)).map(label => `
                            <option value="${label.id}">${escapeHtml(label.name)}</option>
                        `).join('')}
                    </select>
                    <button onclick="attachLabelToCurrentItem()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
                        <i class="fas fa-plus mr-1"></i>Attach
                    </button>
                </div>
                ${data.item.labels && data.item.labels.length > 0 ? `
                    <div class="mt-3 flex flex-wrap gap-2">
                        ${data.item.labels.map(label => `
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium"
                                  style="background-color: ${label.color}; color: ${getTextColor(label.color)};">
                                <i class="fas fa-tag mr-1"></i>${label.name}
                            </span>
                        `).join('')}
                    </div>
                ` : `
                    <p class="mt-3 text-sm text-gray-500">No labels attached to this item.</p>
                `}
            </div>

            <div class="bg-gradient-to-r from-cyan-50 to-sky-50 rounded-xl p-6">
                <h4 class="text-lg font-bold text-gray-800 mb-4">
                    <i class="fas fa-barcode text-cyan-600 mr-2"></i>
                    Barcodes (${(data.item.barcodes || []).length})
                </h4>
                ${(data.item.barcodes || []).length > 0 ? `
                    <div class="space-y-2 max-h-40 overflow-y-auto mb-3">
                        ${data.item.barcodes.map(barcode => `
                            <div class="flex items-center justify-between bg-white border rounded-lg px-3 py-2">
                                <code class="text-sm font-mono">${escapeHtml(barcode.code)}</code>
                                <button onclick="detachBarcodeFromCurrentItem('${escapeHtml(barcode.code)}')" title="Detach code" class="text-red-500 hover:text-red-700">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        `).join('')}
                    </div>
                ` : `
                    <p class="text-sm text-gray-500 mb-3">No barcodes registered on this item.</p>
                `}
                <div class="flex gap-2">
                    <input type="text" id="detailBarcodeInput" placeholder="Scan or type a code…"
                           class="scan-input flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 text-sm">
                    <button onclick="attachBarcodeToCurrentItem()" class="bg-cyan-600 hover:bg-cyan-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
                        <i class="fas fa-plus mr-1"></i>Attach
                    </button>
                </div>
            </div>
        </div>

        <!-- Share & QR (API-025): public link state + management -->
        <div class="bg-gradient-to-r from-fuchsia-50 to-purple-50 rounded-xl p-6">
            <h4 class="text-lg font-bold text-gray-800 mb-4">
                <i class="fas fa-share-nodes text-fuchsia-600 mr-2"></i>
                Share &amp; QR
            </h4>
            ${data.share && data.share.share_url ? `
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <code class="flex-1 bg-white border rounded-lg px-3 py-2 text-sm text-gray-700 break-all">${escapeHtml(data.share.share_url)}</code>
                    <div class="flex gap-2">
                        <button onclick="showQrFromDetails()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-2 rounded-lg text-sm font-medium transition">
                            <i class="fas fa-qrcode mr-1"></i>Show QR
                        </button>
                        <button onclick="copyShareUrl('${escapeHtml(data.share.share_url)}')" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded-lg text-sm font-medium transition">
                            <i class="fas fa-copy mr-1"></i>Copy
                        </button>
                        <button onclick="deactivateShareFromDetails()" class="bg-red-100 hover:bg-red-200 text-red-700 px-3 py-2 rounded-lg text-sm font-medium transition">
                            <i class="fas fa-link-slash mr-1"></i>Deactivate
                        </button>
                    </div>
                </div>
                <p class="mt-2 text-xs text-gray-500">Anyone with this link (or a scan of its QR) can view this box's contents read-only, without an account. Deactivating kills the URL immediately.</p>
            ` : `
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <p class="flex-1 text-sm text-gray-500">No public link yet — create one to share this box read-only (perfect for a QR sticker).</p>
                    <button onclick="createShareFromDetails()" class="bg-fuchsia-600 hover:bg-fuchsia-700 text-white px-3 py-2 rounded-lg text-sm font-medium transition">
                        <i class="fas fa-plus mr-1"></i>Create link
                    </button>
                </div>
            `}
        </div>

        <!-- History Section -->
        <div class="bg-gradient-to-r from-purple-50 to-pink-50 rounded-xl p-6">
            <h4 class="text-lg font-bold text-gray-800 mb-4">
                <i class="fas fa-history text-purple-600 mr-2"></i>
                Change History
            </h4>
            ${data.history.length > 0 ? `
                <div class="space-y-3 max-h-64 overflow-y-auto">
                    ${data.history.map(change => `
                        <div class="bg-white border-l-4 border-purple-300 rounded-lg p-4">
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <div class="font-medium text-gray-800">
                                        ${change.field_name.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())} Changed
                                    </div>
                                    <div class="text-sm text-gray-600 mt-1">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-red-100 text-red-800 mr-2">
                                            ${change.old_value_name || change.old_value || '(empty)'}
                                        </span>
                                        <i class="fas fa-arrow-right text-gray-400 mx-1"></i>
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-green-100 text-green-800">
                                            ${change.new_value_name || change.new_value || '(empty)'}
                                        </span>
                                    </div>
                                    <div class="text-xs text-gray-500 mt-2">
                                        <i class="fas fa-clock mr-1"></i>
                                        ${new Date(change.changed_at).toLocaleString()}
                                    </div>
                                </div>
                                <div class="text-xs text-gray-400 bg-gray-100 px-2 py-1 rounded">
                                    ${change.user.name}
                                </div>
                            </div>
                        </div>
                    `).join('')}
                </div>
            ` : `
                <div class="text-center py-8 text-gray-500">
                    <i class="fas fa-history text-4xl mb-3"></i>
                    <p>No history available</p>
                </div>
            `}
        </div>
    `;

    /* API-028: draw the identity QR after the markup is in place — a fresh
       DOM node each render, so no stale canvas can accumulate */
    renderDetailsUuidQr(data.item.id);
}

/**
 * Draws the item-details identity QR (API-028): the item's bare uuid —
 * scanning it yields the uuid itself (exact-id search / app resolution).
 * Same vendored qrcodejs as the share QR.
 */
function renderDetailsUuidQr(uuid) {
    const target = document.getElementById('detailsUuidQr');
    if (!target || !uuid || typeof QRCode === 'undefined') return;

    target.innerHTML = '';

    new QRCode(target, {
        text: uuid,
        width: 160,
        height: 160,
        colorDark: '#000000',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
    });
}

// Batch operations
function openBatchModal() {
    document.getElementById('batchModal').classList.remove('hidden');
    document.getElementById('batchScanInput').focus();
}

function closeBatchModal() {
    document.getElementById('batchModal').classList.add('hidden');
    clearBatchItems();
}

async function addToBatch() {
    const itemId = document.getElementById('batchScanInput').value.trim();
    
    if (!itemId) return;
    
    if (batchItems.includes(itemId)) {
        showNotification('Item already in batch', 'already', true); // Play sound for already scanned
        document.getElementById('batchScanInput').value = '';
        return;
    }
    
    // Get selected values
    const statusValue = document.getElementById('batchStatus') ? document.getElementById('batchStatus').value : '';
    const locationValue = document.getElementById('batchLocation') ? document.getElementById('batchLocation').value : '';
    
    // Update item immediately if values are selected
    if (statusValue || locationValue) {
        await updateItemImmediately(itemId, statusValue, locationValue);
    }
    
    batchItems.push(itemId);
    updateBatchDisplay();
    document.getElementById('batchScanInput').value = '';
    showNotification('Item processed', 'success', true); // Play sound for successful scan
}

function updateBatchDisplay() {
    const container = document.getElementById('scannedItems');
    const list = document.getElementById('scannedItemsList');
    
    if (batchItems.length > 0) {
        container.classList.remove('hidden');
        list.innerHTML = batchItems.map((itemId, index) => `
            <div class="flex items-center justify-between bg-white px-3 py-2 rounded border">
                <code class="text-sm">${itemId}</code>
                <button onclick="removeFromBatch(${index})" class="text-red-600 hover:text-red-800">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `).join('');
    } else {
        container.classList.add('hidden');
    }
}

function removeFromBatch(index) {
    batchItems.splice(index, 1);
    updateBatchDisplay();
}

function clearBatchItems() {
    batchItems = [];
    updateBatchDisplay();
    document.getElementById('batch' + boardType.charAt(0).toUpperCase() + boardType.slice(1)).value = '';
}

async function processBatchUpdate() {
    const newValue = document.getElementById('batch' + boardType.charAt(0).toUpperCase() + boardType.slice(1)).value;
    
    if (!newValue || batchItems.length === 0) {
        showNotification('Please select a ' + boardType + ' and scan some items', 'error');
        return;
    }

    let successCount = 0;
    let errorCount = 0;

    for (const itemId of batchItems) {
        try {
            const data = {};
            data[fieldName] = newValue;
            data.item_id = itemId;

            const response = await axios.post('/kanban/update-item', data, {
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                }
            });
            
            if (response.data.success) {
                successCount++;
            } else {
                errorCount++;
            }
        } catch (error) {
            errorCount++;
        }
    }

    if (successCount > 0) {
        showNotification(`${successCount} items updated successfully!`, 'success');
    }
    if (errorCount > 0) {
        showNotification(`${errorCount} items failed to update`, 'error');
    }

    closeBatchModal();
    setTimeout(() => location.reload(), 1000);
}

// Move item functionality
function openMoveItemModal() {
    document.getElementById('moveItemModal').classList.remove('hidden');
    document.getElementById('moveToBoxName').focus();
}

function closeMoveItemModal() {
    document.getElementById('moveItemModal').classList.add('hidden');
    document.getElementById('moveToBoxName').value = '';
    document.getElementById('moveToBoxScan').value = '';
}

async function moveItemToParent() {
    const boxName = document.getElementById('moveToBoxName').value.trim();
    const boxScan = document.getElementById('moveToBoxScan').value.trim();
    const input = boxScan || boxName;

    if (!input || !currentItemId) {
        showNotification('Please specify a parent box', 'error');
        return;
    }

    try {
        /* The web update route expects a parent ITEM ID — resolve a typed
           name through the search endpoint first (a scanned/typed id passes
           through untouched). */
        let parentId = input;
        if (!boxScan) {
            const search = await axios.get('/kanban/search', {
                params: { query: input, filter: 'all' },
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    'Accept': 'application/json'
                }
            });
            const match = (search.data || []).find(item => item.name === input) || (search.data || [])[0];
            if (!match) {
                showNotification('No box found with this name', 'error');
                return;
            }
            parentId = match.id;
        }

        const response = await axios.post('/item/' + currentItemId, {
            parent_id: parentId
        }, {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            }
        });

        if (response.data) {
            showNotification('Item moved successfully!', 'success');
            closeMoveItemModal();
            closeItemDetailsModal();
            setTimeout(() => location.reload(), 1000);
        }
    } catch (error) {
        console.error('Error moving item:', error);
        showNotification('Error moving item: ' + (error.response?.data?.message || 'Parent box not found'), 'error');
    }
}

async function removeFromParent() {
    if (!currentItemId) return;

    try {
        const response = await axios.post('/item/' + currentItemId, {
            parent_id: null
        }, {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            }
        });

        if (response.data) {
            showNotification('Item removed from parent!', 'success');
            closeMoveItemModal();
            closeItemDetailsModal();
            setTimeout(() => location.reload(), 1000);
        }
    } catch (error) {
        console.error('Error removing parent:', error);
        showNotification('Error removing parent: ' + (error.response?.data?.message || error.message), 'error');
    }
}

// Filter functionality
function openFilterModal() {
    document.getElementById('filterModal').classList.remove('hidden');
    loadAllItemsForFilter();
}

function closeFilterModal() {
    document.getElementById('filterModal').classList.add('hidden');
}

async function loadAllItemsForFilter() {
    try {
        // Use the search endpoint to get all items with full data
        const response = await axios.get('/kanban/search', {
            params: {
                query: '', // Empty query to get all items
                filter: 'all'
            },
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            }
        });
        
        window.allItems = response.data;
        applyDetailedFilter();
    } catch (error) {
        console.error('Error loading items:', error);
        // Fallback: extract items from current kanban data
        window.allItems = extractItemsFromKanban();
        applyDetailedFilter();
    }
}

function extractItemsFromKanban() {
    const items = [];
    document.querySelectorAll('.kanban-item').forEach(itemEl => {
        const itemId = itemEl.dataset.itemId;
        const itemName = itemEl.querySelector('.font-medium').textContent;
        const boardEl = itemEl.closest('.kanban-board');
        const boardId = boardEl.dataset.boardId;
        const boardTitle = boardEl.querySelector('h3').textContent;
        
        items.push({
            id: itemId,
            name: itemName,
            [fieldName]: boardId,
            [fieldName.replace('_id', '')]: { name: boardTitle }
        });
    });
    return items;
}

function applyDetailedFilter() {
    const filterValue = document.getElementById('filterSelect').value;
    const container = document.getElementById('filteredItemsContainer');
    const noResults = document.getElementById('noFilterResults');
    
    if (!window.allItems) {
        return;
    }
    
    let filteredItems = window.allItems;
    if (filterValue) {
        filteredItems = window.allItems.filter(item => item[fieldName] === filterValue);
    }
    
    if (filteredItems.length === 0) {
        container.innerHTML = '';
        noResults.classList.remove('hidden');
        return;
    }
    
    noResults.classList.add('hidden');
    container.innerHTML = filteredItems.map(item => `
        <div class="bg-white border border-gray-200 rounded-lg p-4 hover:shadow-md transition cursor-pointer" onclick="openItemDetails('${item.id}')">
            <div class="flex items-start justify-between mb-3">
                <h4 class="font-semibold text-gray-800 truncate">${item.name}</h4>
                <i class="fas fa-external-link-alt text-gray-400 text-sm"></i>
            </div>
            
            <div class="space-y-2">
                <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-600">Status:</span>
                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                        ${item.status ? item.status.name : 'No status'}
                    </span>
                </div>
                <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-600">Location:</span>
                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                        ${item.location ? locationDisplay(item.location) : 'No location'}
                    </span>
                </div>
            </div>
            
            <div class="mt-3 pt-3 border-t border-gray-100">
                <code class="text-xs text-gray-500">${item.id}</code>
            </div>
        </div>
    `).join('');
}

function applyFilter() {
    applyDetailedFilter();
}

function clearFilter() {
    document.getElementById('filterSelect').value = '';
    applyDetailedFilter();
}

// Management functions
function openManagementModal(defaultTab = 'status') {
    document.getElementById('managementModal').classList.remove('hidden');
    // Switch to the specified tab
    switchManagementTab(defaultTab);
}

function closeManagementModal() {
    document.getElementById('managementModal').classList.add('hidden');
    document.getElementById('newStatusName').value = '';
    document.getElementById('newLocationName').value = '';
    document.getElementById('newLabelName').value = '';
}

function switchManagementTab(type) {
    // Update tab buttons
    document.querySelectorAll('.management-tab').forEach(tab => {
        tab.classList.remove('active');
    });
    document.getElementById(type + 'Tab').classList.add('active');
    
    // Update content
    document.querySelectorAll('.management-content').forEach(content => {
        content.classList.add('hidden');
    });
    document.getElementById(type + 'Management').classList.remove('hidden');
    

}

async function createNewStatus() {
    const name = document.getElementById('newStatusName').value.trim();
    if (!name) {
        showNotification('Please enter a status name', 'error');
        return;
    }

    try {
        const response = await axios.post('/kanban/status', { name }, {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            }
        });
        
        showNotification('Status created successfully!', 'success');
        closeManagementModal();
        setTimeout(() => location.reload(), 1000);
    } catch (error) {
        console.error('Error creating status:', error);
        showNotification('Error creating status', 'error');
    }
}

async function createNewLocation() {
    const name = document.getElementById('newLocationName').value.trim();
    if (!name) {
        showNotification('Please enter a location name', 'error');
        return;
    }

    try {
        const response = await axios.post('/kanban/location', { name }, {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            }
        });
        
        showNotification('Location created successfully!', 'success');
        closeManagementModal();
        setTimeout(() => location.reload(), 1000);
    } catch (error) {
        console.error('Error creating location:', error);
        showNotification('Error creating location', 'error');
    }
}

async function createNewLabel() {
    const name = document.getElementById('newLabelName').value.trim();
    const color = document.getElementById('newLabelColor').value;

    if (!name) {
        showNotification('Please enter a label name', 'error');
        return;
    }

    try {
        const response = await axios.post('/kanban/label', {
            name: name,
            color: color
        }, {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        });

        showNotification('Label created successfully!', 'success');
        setTimeout(() => location.reload(), 1000);
    } catch (error) {
        console.error('Error creating label:', error);
        showNotification('Error creating label: ' + (error.response?.data?.message || error.message), 'error');
    }
}

/* Label rows carry a color input + a name input (both locked until edit). */
function editLabel(id) {
    const row = document.querySelector(`[data-id="${id}"][data-type="label"]`);
    if (!row) return;
    const nameInput = row.querySelector('.edit-input');
    const colorInput = row.querySelector('.label-color-input');
    const buttons = row.querySelector('.flex.gap-2');

    nameInput.readOnly = false;
    nameInput.classList.add('border', 'border-gray-300', 'rounded', 'px-2', 'py-1');
    colorInput.disabled = false;
    nameInput.focus();

    buttons.innerHTML = `
        <button onclick="saveLabel('${id}')" class="text-green-600 hover:text-green-800">
            <i class="fas fa-check"></i>
        </button>
        <button onclick="cancelLabelEdit('${id}')" class="text-gray-600 hover:text-gray-800">
            <i class="fas fa-times"></i>
        </button>
    `;
}

async function saveLabel(id) {
    const row = document.querySelector(`[data-id="${id}"][data-type="label"]`);
    if (!row) return;
    const name = row.querySelector('.edit-input').value.trim();
    const color = row.querySelector('.label-color-input').value;

    if (!name) {
        showNotification('Name cannot be empty', 'error');
        return;
    }

    try {
        /* POST — the host load balancer does not support PATCH */
        const response = await axios.post(`/kanban/label/${id}`, { name, color }, {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        });

        showNotification('Label updated successfully!', 'success');
        setTimeout(() => location.reload(), 1000);
    } catch (error) {
        console.error('Error updating label:', error);
        showNotification('Error updating label: ' + (error.response?.data?.message || error.message), 'error');
    }
}

function cancelLabelEdit(id) {
    location.reload(); // Simple way to cancel edit
}

async function deleteLabel(id) {
    if (!confirm('Are you sure you want to delete this label? Items using it keep working, they just lose the tag.')) {
        return;
    }

    try {
        const response = await axios.delete(`/kanban/label/${id}`, {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        });

        showNotification('Label deleted successfully!', 'success');
        setTimeout(() => location.reload(), 1000);
    } catch (error) {
        console.error('Error deleting label:', error);
        showNotification('Error deleting label', 'error');
    }
}











// Edit and delete functions for management
function editItem(id, type) {
    const row = document.querySelector(`[data-id="${id}"][data-type="${type}"]`);
    const input = row.querySelector('.edit-input');
    const buttons = row.querySelector('.flex');
    
    input.readOnly = false;
    input.classList.add('border', 'border-gray-300', 'rounded', 'px-2', 'py-1');
    input.focus();
    
    buttons.innerHTML = `
        <button onclick="saveItem('${id}', '${type}')" class="text-green-600 hover:text-green-800">
            <i class="fas fa-check"></i>
        </button>
        <button onclick="cancelEdit('${id}', '${type}')" class="text-gray-600 hover:text-gray-800">
            <i class="fas fa-times"></i>
        </button>
    `;
}

async function saveItem(id, type) {
    const row = document.querySelector(`[data-id="${id}"][data-type="${type}"]`);
    const input = row.querySelector('.edit-input');
    const newName = input.value.trim();

    if (!newName) {
        showNotification('Name cannot be empty', 'error');
        return;
    }

    try {
        /* POST — the host load balancer does not support PATCH */
        const response = await axios.post(`/kanban/${type}/${id}`, { name: newName }, {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        });

        showNotification(`${type.charAt(0).toUpperCase() + type.slice(1)} updated successfully!`, 'success');
        setTimeout(() => location.reload(), 1000);
    } catch (error) {
        console.error('Error updating item:', error);
        showNotification('Error updating item', 'error');
    }
}

function cancelEdit(id, type) {
    location.reload(); // Simple way to cancel edit
}

async function deleteItem(id, type) {
    if (!confirm(`Are you sure you want to delete this ${type}?`)) {
        return;
    }

    try {
        const response = await axios.delete(`/kanban/${type}/${id}`, {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        });
        
        showNotification(`${type.charAt(0).toUpperCase() + type.slice(1)} deleted successfully!`, 'success');
        setTimeout(() => location.reload(), 1000);
    } catch (error) {
        console.error('Error deleting item:', error);
        showNotification('Error deleting item', 'error');
    }
}

// Utility functions
/**
 * Activity feed sync (API-026): incremental pulls through the `after` cursor.
 * The first pull (no cursor yet) fetches the legacy bare array and seeds the
 * cursor from its newest row; every later pull sends `after=` and merges only
 * rows it doesn't already show (dedupe by id — the inclusive cursor may
 * re-deliver same-second rows). Rows are append-only, so no cursor reset is
 * ever needed (deletes remove cards from the board, not feed entries).
 */
async function refreshHistory() {
    try {
        const response = await axios.get('/kanban/history', {
            params: historyCursor ? { after: historyCursor } : {},
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            }
        });

        // Handle different response formats: bare array (legacy, no cursor)
        // vs {data, cursor} envelope (incremental mode)
        const history = Array.isArray(response.data) ? response.data : (response.data.data || []);
        if (Array.isArray(response.data)) {
            /* Seed the cursor from the newest fetched row (list is newest-first) */
            historyCursor = history.length ? history[0].changed_at : null;
        } else if (response.data.cursor) {
            historyCursor = response.data.cursor;
        }

        /* Merge: dedupe by id, keep newest-first, cap at the endpoint's 50 */
        const knownIds = new Set(currentHistoryData.map(h => h.id));
        const fresh = history.filter(h => !knownIds.has(h.id));
        if (!fresh.length) {
            return; // Nothing new — skip re-render entirely
        }

        currentHistoryData = [...fresh, ...currentHistoryData].slice(0, 50);
        renderHistory(currentHistoryData);
    } catch (error) {
        console.error('Error refreshing history:', error);
    }
}

/**
 * Renders the merged activity list (used by the incremental flow only —
 * the container lives on the activity page, not on the kanban board).
 */
function renderHistory(history) {
    const historyContainer = document.getElementById('history-container');

    // History container only exists on the activity page, not on kanban page anymore
    if (!historyContainer) return;

    historyContainer.innerHTML = history.map(change => `
            <div class="bg-gray-50 rounded-lg p-3 border-l-4 border-indigo-200 transition-all hover:shadow-md" data-change-id="${change.id}">
                <div class="flex items-start justify-between">
                    <div class="flex-1">
                        <div class="text-sm font-medium text-gray-800">
                            ${change.item.name}
                        </div>
                        <div class="text-xs text-gray-600 mt-1">
                            <div class="font-medium mb-2">
                                ${change.field_name.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())} Changed
                            </div>
                            ${change.old_value_name || change.new_value_name ? `
                                <div class="flex items-center flex-wrap gap-1">
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-red-100 text-red-800">
                                        ${change.old_value_name || change.old_value || '(empty)'}
                                    </span>
                                    <i class="fas fa-arrow-right text-gray-400 mx-1"></i>
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-green-100 text-green-800">
                                        ${change.new_value_name || change.new_value || '(empty)'}
                                    </span>
                                </div>
                            ` : `
                                <div class="flex items-center flex-wrap gap-1">
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-red-100 text-red-800">
                                       ${change.old_value || '(empty)'}
                                    </span>
                                    <i class="fas fa-arrow-right text-gray-400 mx-1"></i>
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-green-100 text-green-800">
                                        ${change.new_value || '(empty)'}
                                    </span>
                                </div>
                            `}
                        </div>
                        <div class="text-xs text-gray-500 mt-1">
                            <i class="fas fa-clock mr-1"></i>
                            ${new Date(change.changed_at).toLocaleString()}
                        </div>
                    </div>
                    <div class="text-xs text-gray-400 bg-gray-100 px-2 py-1 rounded">
                        ${change.user.name}
                    </div>
                </div>
            </div>
        `).join('');

    // Update recent changes count
    const recentChangesElement = document.getElementById('recent-changes');
    if (recentChangesElement) {
        recentChangesElement.textContent = history.length;
    }
}

function refreshBoard() {
    location.reload();
}

function closeItemDetailsModal() {
    document.getElementById('itemDetailsModal').classList.add('hidden');
    currentItemId = null;
}

/* ---- Item-details: label + barcode management (POST/DELETE /kanban/…) ---- */

async function attachLabelToCurrentItem() {
    const select = document.getElementById('detailLabelSelect');
    const labelId = select ? select.value : '';
    if (!labelId || !currentItemId) {
        showNotification('Choose a label first', 'error');
        return;
    }

    try {
        await axios.post(`/kanban/item/${currentItemId}/labels`, { label_id: labelId }, {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        });
        showNotification('Label attached!', 'success');
        await openItemDetails(currentItemId);
    } catch (error) {
        const message = error.response?.status === 409
            ? 'Label already attached to item'
            : (error.response?.data?.message || error.message);
        showNotification('Error attaching label: ' + message, 'error');
    }
}

async function detachLabelFromCurrentItem(labelId) {
    if (!currentItemId || !labelId) return;

    try {
        await axios.delete(`/kanban/item/${currentItemId}/labels/${labelId}`, {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        });
        showNotification('Label detached!', 'success');
        await openItemDetails(currentItemId);
    } catch (error) {
        showNotification('Error detaching label: ' + (error.response?.data?.message || error.message), 'error');
    }
}

async function attachBarcodeToCurrentItem() {
    const input = document.getElementById('detailBarcodeInput');
    const code = input ? input.value.trim() : '';
    if (!code || !currentItemId) {
        showNotification('Scan or type a code first', 'error');
        return;
    }

    try {
        await axios.post(`/kanban/item/${currentItemId}/barcodes`, { code }, {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        });
        playSound('successSound');
        showNotification('Barcode attached!', 'success', true);
        await openItemDetails(currentItemId);
    } catch (error) {
        if (error.response?.status === 409) {
            playSound('alreadySound');
            showNotification('Code already registered in this team', 'already', true);
        } else {
            showNotification('Error attaching barcode: ' + (error.response?.data?.message || error.message), 'error');
        }
    }
}

async function detachBarcodeFromCurrentItem(code) {
    if (!currentItemId || !code) return;

    try {
        await axios.delete(`/kanban/item/${currentItemId}/barcodes/${encodeURIComponent(code)}`, {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        });
        showNotification('Barcode detached!', 'success');
        await openItemDetails(currentItemId);
    } catch (error) {
        showNotification('Error detaching barcode: ' + (error.response?.data?.message || error.message), 'error');
    }
}

/* ---- Public share link + QR (API-024/025, session flavor) ---- */

/* The current item's active share URL (null when not shared) — captured
   from getItemDetails and refreshed by the create/deactivate actions. */
let currentShareUrl = null;

/**
 * Card QR affordance (server-rendered cards and buildCardHtml parity):
 * ensures the item has an active public link (creates it on the fly via
 * POST /kanban/item/{id}/share) and opens the QR modal.
 */
async function showCardQr(itemId) {
    if (!itemId) return;

    try {
        const response = await axios.post(`/kanban/item/${itemId}/share`, {}, {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            }
        });

        const shareUrl = response.data.share?.share_url;
        if (!shareUrl) {
            showNotification('Share link is not active', 'error');
            return;
        }

        /* Resolve the card title for the modal caption (children share the
           data-item-id selector only on the board, so scope the lookup) */
        const card = document.querySelector(`.kanban-item[data-item-id="${itemId}"] .font-medium`);
        renderQrModal(itemId, shareUrl, card ? card.textContent.trim() : null);
    } catch (error) {
        if (error.response?.status === 403) {
            showNotification('You are not allowed to share this item', 'error');
        } else if (error.response?.status === 404) {
            showNotification('Item not found', 'error');
        } else {
            showNotification('Error creating share link: ' + (error.response?.data?.message || error.message), 'error');
        }
    }
}

/**
 * Renders the share URL as a QR in the modal (vendored qrcodejs — vanilla,
 * auto-selects the smallest QR version that fits the URL).
 */
function renderQrModal(itemId, shareUrl, itemName) {
    const target = document.getElementById('qrCodeTarget');
    const nameElement = document.getElementById('qrItemName');
    const urlElement = document.getElementById('qrShareUrl');
    if (!target || !nameElement || !urlElement) return;

    target.innerHTML = '';
    nameElement.textContent = itemName || itemId;
    urlElement.textContent = shareUrl;

    new QRCode(target, {
        text: shareUrl,
        width: 220,
        height: 220,
        colorDark: '#000000',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
    });

    document.getElementById('qrModal').classList.remove('hidden');
}

function closeQrModal() {
    document.getElementById('qrModal').classList.add('hidden');
    document.getElementById('qrCodeTarget').innerHTML = '';
    document.getElementById('qrShareUrl').textContent = '';
    document.getElementById('qrItemName').textContent = '';
}

/**
 * Clipboard helper with the LAN/HTTP textarea fallback (API-028: shared by
 * the share-URL copy and the identity-uuid copy, each with its own wording).
 */
function copyText(text, successMessage) {
    if (!text) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(
            () => showNotification(successMessage, 'success'),
            () => showNotification('Could not copy', 'error')
        );
    } else {
        /* LAN/HTTP fallback: select-style copy via a temp textarea */
        const textarea = document.createElement('textarea');
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
            showNotification(successMessage, 'success');
        } catch (e) {
            showNotification('Could not copy', 'error');
        }
        textarea.remove();
    }
}

function copyShareUrl(url) {
    copyText(url, 'Share link copied!');
}

/**
 * Copies the identity uuid from the details modal (API-028).
 */
function copyItemUuid(uuid) {
    copyText(uuid, 'Uuid copied!');
}

function copyQrShareUrl() {
    copyShareUrl(document.getElementById('qrShareUrl')?.textContent);
}

/* Details-modal actions: the share URL is captured on each render */
async function createShareFromDetails() {
    if (!currentItemId) return;

    try {
        const response = await axios.post(`/kanban/item/${currentItemId}/share`, {}, {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json'
            }
        });
        currentShareUrl = response.data.share?.share_url || null;
        showNotification('Public link created!', 'success');
        await openItemDetails(currentItemId);
    } catch (error) {
        showNotification('Error creating share link: ' + (error.response?.data?.message || error.message), 'error');
    }
}

async function deactivateShareFromDetails() {
    if (!currentItemId) return;

    try {
        await axios.delete(`/kanban/item/${currentItemId}/share`, {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json'
            }
        });
        currentShareUrl = null;
        showNotification('Public link deactivated — the URL is dead.', 'success');
        await openItemDetails(currentItemId);
    } catch (error) {
        showNotification('Error deactivating share link: ' + (error.response?.data?.message || error.message), 'error');
    }
}

function showQrFromDetails() {
    if (!currentItemId || !currentShareUrl) {
        showNotification('No active share link', 'error');
        return;
    }
    const nameElement = document.querySelector('#itemDetailsContent .font-semibold');
    renderQrModal(currentItemId, currentShareUrl, nameElement ? nameElement.textContent.trim() : null);
}

// Search functionality
let searchFilter = 'all';
let searchTimeout = null;

function openSearchPage() {
    document.getElementById('searchModal').classList.remove('hidden');
    document.getElementById('searchInput').focus();
}

function closeSearchModal() {
    document.getElementById('searchModal').classList.add('hidden');
    document.getElementById('searchInput').value = '';
    document.getElementById('searchResults').innerHTML = '';
}

function setSearchFilter(filter) {
    searchFilter = filter;
    
    // Update button styles
    document.querySelectorAll('.search-filter-btn').forEach(btn => {
        btn.classList.remove('active', 'bg-indigo-100', 'text-indigo-800');
        btn.classList.add('bg-gray-100', 'text-gray-700');
    });
    
    event.target.classList.add('active', 'bg-indigo-100', 'text-indigo-800');
    event.target.classList.remove('bg-gray-100', 'text-gray-700');
    
    // Re-run search if there's a query
    const query = document.getElementById('searchInput').value.trim();
    if (query) {
        performSearch(query);
    }
}

function performSearch(query) {
    if (!query) {
        document.getElementById('searchResults').innerHTML = '';
        return;
    }
    
    // Show loading
    document.getElementById('searchLoading').classList.remove('hidden');
    document.getElementById('noSearchResults').classList.add('hidden');
    document.getElementById('searchResults').innerHTML = '';
    
    // Clear previous timeout
    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }
    
    // Debounce search
    searchTimeout = setTimeout(async () => {
        try {
            const response = await axios.get('/kanban/search', {
                params: {
                    query: query,
                    filter: searchFilter
                },
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                }
            });
            
            displaySearchResults(response.data, query);
        } catch (error) {
            console.error('Search error:', error);
            document.getElementById('searchLoading').classList.add('hidden');
            document.getElementById('noSearchResults').classList.remove('hidden');
        }
    }, 300);
}

function displaySearchResults(results, query) {
    document.getElementById('searchLoading').classList.add('hidden');
    
    if (results.length === 0) {
        document.getElementById('noSearchResults').classList.remove('hidden');
        return;
    }
    
    const container = document.getElementById('searchResults');
    container.innerHTML = results.map(item => {
        const highlightedName = highlightText(item.name, query);
        const highlightedId = highlightText(item.id, query);
        
        return `
            <div class="bg-white border border-gray-200 rounded-lg p-4 hover:shadow-md transition cursor-pointer" onclick="openItemDetails('${item.id}')">
                <div class="flex items-start justify-between mb-3">
                    <div class="flex-1">
                        <h4 class="font-semibold text-gray-800">${highlightedName}</h4>
                        <code class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded mt-1 inline-block">${highlightedId}</code>
                    </div>
                    <i class="fas fa-external-link-alt text-gray-400 text-sm"></i>
                </div>
                
                <div class="space-y-2">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-600">Status:</span>
                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                            ${item.status ? item.status.name : 'No status'}
                        </span>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-600">Location:</span>
                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                            ${item.location ? locationDisplay(item.location) : 'No location'}
                        </span>
                    </div>
                    ${item.children_count > 0 ? `
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-600">Contents:</span>
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                ${item.children_count} items
                            </span>
                        </div>
                    ` : ''}
                    ${item.parent ? `
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-600">Parent:</span>
                            <span class="text-xs text-gray-600 truncate">${item.parent.name}</span>
                        </div>
                    ` : ''}
                    ${item.labels && item.labels.length > 0 ? `
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-600">Labels:</span>
                            <div class="flex flex-wrap gap-1">
                                ${item.labels.map(label => `
                                    <span class="inline-flex items-center px-1 py-0.5 rounded text-xs font-medium text-white" 
                                          style="background-color: ${label.color};">
                                        ${label.name}
                                    </span>
                                `).join('')}
                            </div>
                        </div>
                    ` : ''}
                </div>
            </div>
        `;
    }).join('');
}

function highlightText(text, query) {
    if (!query) return text;
    
    const regex = new RegExp(`(${query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
    return text.replace(regex, '<mark class="bg-yellow-200 px-1 rounded">$1</mark>');
}

// Close modals when clicking outside
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) {
        if (e.target.id === 'batchModal') closeBatchModal();
        if (e.target.id === 'managementModal') closeManagementModal();
        if (e.target.id === 'itemDetailsModal') closeItemDetailsModal();
        if (e.target.id === 'moveItemModal') closeMoveItemModal();
        if (e.target.id === 'filterModal') closeFilterModal();
        if (e.target.id === 'searchModal') closeSearchModal();
        if (e.target.id === 'qrModal') closeQrModal();
    }
});

// Add search input event listener
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function(e) {
            performSearch(e.target.value.trim());
        });
        
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                performSearch(e.target.value.trim());
            }
        });
    }
});

// Cleanup on page unload
window.addEventListener('beforeunload', function() {
    if (realTimeInterval) {
        clearInterval(realTimeInterval);
    }
});

// Make functions globally available
window.initializeKanban = initializeKanban;
window.openFilterModal = openFilterModal;
window.closeFilterModal = closeFilterModal;
window.openBatchModal = openBatchModal;
window.closeBatchModal = closeBatchModal;
window.openManagementModal = openManagementModal;
window.closeManagementModal = closeManagementModal;
window.quickScanItem = quickScanItem;
window.refreshBoard = refreshBoard;
window.refreshHistory = refreshHistory;
window.clearBatchItems = clearBatchItems;
window.switchManagementTab = switchManagementTab;
window.createNewStatus = createNewStatus;
window.createNewLocation = createNewLocation;
window.createNewLabel = createNewLabel;
window.editItem = editItem;
window.saveItem = saveItem;
window.editLabel = editLabel;
window.saveLabel = saveLabel;
window.cancelLabelEdit = cancelLabelEdit;
window.deleteLabel = deleteLabel;
window.attachLabelToCurrentItem = attachLabelToCurrentItem;
window.detachLabelFromCurrentItem = detachLabelFromCurrentItem;
window.attachBarcodeToCurrentItem = attachBarcodeToCurrentItem;
window.detachBarcodeFromCurrentItem = detachBarcodeFromCurrentItem;
window.openItemDetails = openItemDetails;
window.closeItemDetailsModal = closeItemDetailsModal;
window.openMoveItemModal = openMoveItemModal;
window.closeMoveItemModal = closeMoveItemModal;
window.moveItemToParent = moveItemToParent;
window.removeFromParent = removeFromParent;
window.applyDetailedFilter = applyDetailedFilter;
window.startRealTimeUpdates = startRealTimeUpdates;
window.updateItemByDragDrop = updateItemByDragDrop;
window.openSearchPage = openSearchPage;
window.closeSearchModal = closeSearchModal;
window.setSearchFilter = setSearchFilter;
window.showCardQr = showCardQr;
window.closeQrModal = closeQrModal;
window.copyQrShareUrl = copyQrShareUrl;
window.copyShareUrl = copyShareUrl;
window.copyItemUuid = copyItemUuid;
window.createShareFromDetails = createShareFromDetails;
window.deactivateShareFromDetails = deactivateShareFromDetails;
window.showQrFromDetails = showQrFromDetails;
