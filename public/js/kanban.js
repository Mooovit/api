// Global variables
let boardType = '';
let fieldName = '';
let sortableInstances = [];
let batchItems = [];
let currentItemId = null;
let realTimeInterval = null;

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
function initializeKanban(type, field) {
    console.log('Initializing kanban with type:', type, 'field:', field);
    boardType = type;
    fieldName = field;
    
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

// Real-time updates
let lastHistoryUpdate = 0;
let currentHistoryData = [];

function startRealTimeUpdates() {
    // Update every 3 seconds (reduced frequency)
    realTimeInterval = setInterval(() => {
        refreshHistory();
        updateLastUpdatedTime();
        
        // Update board data every 15 seconds to avoid too frequent requests
        if (Date.now() % 15000 < 3000) {
            refreshBoardData();
        }
    }, 3000);
}

// Refresh board data without page reload
async function refreshBoardData() {
    try {
        const response = await axios.get(window.location.pathname, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        });
        
        if (response.data && response.data.jsonData) {
            updateKanbanBoard(JSON.parse(response.data.jsonData));
        }
    } catch (error) {
        console.error('Error refreshing board data:', error);
    }
}

// Update kanban board with new data
function updateKanbanBoard(newData) {
    const container = document.getElementById('kanban-container');
    if (!container) return;
    
    // Store current scroll position
    const scrollLeft = container.scrollLeft;
    
    // Update board content (simplified - you might want to do a more sophisticated diff)
    // For now, we'll just update the item counts
    newData.forEach(board => {
        const boardElement = document.querySelector(`[data-board-id="${board.id}"]`);
        if (boardElement) {
            const countElement = boardElement.querySelector('.bg-indigo-100');
            if (countElement) {
                countElement.textContent = board.item.length;
            }
        }
    });
    
    // Restore scroll position
    container.scrollLeft = scrollLeft;
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
        const response = await axios.get('/kanban/item/' + itemId, {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            }
        });
        
        const data = response.data;
        currentItemId = itemId;
        displayEnhancedItemDetails(data);
        document.getElementById('itemDetailsModal').classList.remove('hidden');
        showNotification('Item details loaded', 'success'); // No sound for details loading
    } catch (error) {
        console.error('Error fetching item details:', error);
        showNotification('Item not found or error loading details', 'error');
    }
}

function displayEnhancedItemDetails(data) {
    const content = document.getElementById('itemDetailsContent');
    
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
                            ${data.item.location ? data.item.location.name : 'No location'}
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
                                    </span>
                                `).join('')}
                            </div>
                        </div>
                    ` : ''}
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
    const parentId = boxScan || boxName;
    
    if (!parentId || !currentItemId) {
        showNotification('Please specify a parent box', 'error');
        return;
    }

    try {
        const response = await axios.patch('/api/item/' + currentItemId, {
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
        showNotification('Error moving item: Parent box not found', 'error');
    }
}

async function removeFromParent() {
    if (!currentItemId) return;

    try {
        const response = await axios.patch('/api/item/' + currentItemId, {
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
        showNotification('Error removing parent', 'error');
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
                        ${item.location ? item.location.name : 'No location'}
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
        document.getElementById('newLabelName').value = '';
        document.getElementById('newLabelColor').value = '#FF5733';

    } catch (error) {
        console.error('Error creating label:', error);
        showNotification('Error creating label', 'error');
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
        const response = await axios.patch(`/kanban/${type}/${id}`, { name: newName }, {
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
async function refreshHistory() {
    try {
        const response = await axios.get('/kanban/history', {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            }
        });
        
        // Handle different response formats
        const history = Array.isArray(response.data) ? response.data : (response.data.data || []);
        
        // Check if history has actually changed to avoid unnecessary re-renders
        const historyHash = JSON.stringify(history.map(h => ({ id: h.id, changed_at: h.changed_at })));
        const currentHash = JSON.stringify(currentHistoryData.map(h => ({ id: h.id, changed_at: h.changed_at })));
        
        if (historyHash === currentHash) {
            return; // No changes, skip re-render
        }
        
        currentHistoryData = history;
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
    } catch (error) {
        console.error('Error refreshing history:', error);
    }
}

function refreshBoard() {
    location.reload();
}

function closeItemDetailsModal() {
    document.getElementById('itemDetailsModal').classList.add('hidden');
    currentItemId = null;
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
                            ${item.location ? item.location.name : 'No location'}
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
        if (e.target.id === 'labelManagementModal') closeLabelManagementModal();
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
window.editItem = editItem;
window.deleteItem = deleteItem;
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
