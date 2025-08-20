<?php

namespace App\Http\Controllers;

use App\Models\History;
use App\Models\Item;
use App\Models\Label;
use App\Models\Location;
use App\Models\Status;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class KanbanController extends Controller
{
    /**
     * Calculate optimal text color based on background color
     */
    private function getTextColor($backgroundColor)
    {
        // Remove # if present
        $hex = str_replace('#', '', $backgroundColor);
        
        // Convert to RGB
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        
        // Calculate luminance using the relative luminance formula
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
        
        // Return white text for dark backgrounds, black text for light backgrounds
        return $luminance > 0.5 ? '#000000' : '#ffffff';
    }

    /**
     * Display the kanban board view
     */
    public function index(Request $request, string $type)
    {
        $user = $request->user();
        $team_id = $user->current_team_id;
        
        switch ($type) {
            default:
            case "status":
                $query = ["model" => Status::class, "field" => "status_id"];
                break;
            case "location":
                $query = ["model" => Location::class, "field" => "location_id"];
                break;
        }

        $jsonData = [];
        foreach($query['model']::where(["team_id" => $team_id])->with('items.status', 'items.location', 'items.labels')->get() as $instance) {
            $boardItem = [];
            foreach($instance->items as $item) {
                if(!$item->parent_id) {
                    array_push($boardItem, [
                        "id" => $item->id,
                        "title" => $item->name,
                        "status_id" => $item->status_id,
                        "location_id" => $item->location_id,
                        "parent_id" => $item->parent_id,
                        "status_name" => $item->status ? $item->status->name : null,
                        "location_name" => $item->location ? $item->location->name : null,
                        "labels" => $item->labels->map(function($label) {
                            return [
                                'id' => $label->id,
                                'name' => $label->name,
                                'color' => $label->color,
                                'text_color' => $this->getTextColor($label->color)
                            ];
                        })->toArray()
                    ]);
                }
            }
            array_push($jsonData, [
                "id" => $instance->id,
                "title" => $instance->name,
                "item" => $boardItem
            ]);
        }

        // Get recent history for the dashboard
        $recentHistory = History::whereHas('item', function($query) use ($team_id) {
            $query->where('team_id', $team_id);
        })
        ->with(['item', 'user'])
        ->orderBy('changed_at', 'desc')
        ->limit(20)
        ->get();

        // Get all statuses and locations for management
        $statuses = Status::where('team_id', $team_id)->get();
        $locations = Location::where('team_id', $team_id)->get();
        $labels = Label::where('team_id', $team_id)->get();

        // Convert jsonData to JSON string for the view
        $jsonData = json_encode($jsonData);
        
        return view('kanban', compact('jsonData', 'query', 'type', 'recentHistory', 'statuses', 'locations', 'labels', 'user'));
    }

    /**
     * Display the live activity page
     */
    public function activity(Request $request)
    {
        $user = $request->user();
        $team_id = $user->current_team_id;
        
        // Get recent history for the activity page (more items than sidebar)
        $recentHistory = History::whereHas('item', function($query) use ($team_id) {
            $query->where('team_id', $team_id);
        })
        ->with(['item', 'user'])
        ->orderBy('changed_at', 'desc')
        ->limit(100)
        ->get();

        // Get all statuses, locations, and labels for name resolution
        $statuses = Status::where('team_id', $team_id)->pluck('name', 'id');
        $locations = Location::where('team_id', $team_id)->pluck('name', 'id');
        $labels = Label::where('team_id', $team_id)->pluck('name', 'id');

        // Enhance history with resolved names
        $enhancedHistory = $recentHistory->map(function($change) use ($statuses, $locations, $labels) {
            $change->old_value_name = $this->resolveValueName($change->field_name, $change->old_value, $statuses, $locations, $labels);
            $change->new_value_name = $this->resolveValueName($change->field_name, $change->new_value, $statuses, $locations, $labels);
            return $change;
        });
        
        return view('activity', compact('enhancedHistory', 'user'));
    }

    /**
     * Get item details by ID or barcode scan
     */
    public function getItemDetails(Request $request, string $itemId): JsonResponse
    {
        $user = $request->user();
        
        $item = Item::with(['histories.user', 'team', 'parent', 'status', 'location', 'labels'])
            ->where('id', $itemId)
            ->where('team_id', $user->current_team_id)
            ->first();

        if (!$item) {
            return response()->json(['error' => 'Item not found'], 404);
        }

        // Check permissions
        if (!$user->hasTeamPermission($item->team, 'item:read') ||
            !$user->tokenCan('item:read')
        ) {
            throw new AuthorizationException();
        }

        // Get children items
        $children = Item::where('parent_id', $item->id)->get();

        // Get item history with resolved names
        $history = $item->histories()
            ->with('user')
            ->orderBy('changed_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($change) {
                // Resolve old and new values to names
                $change->old_value_name = $this->resolveValueToName($change->field_name, $change->old_value);
                $change->new_value_name = $this->resolveValueToName($change->field_name, $change->new_value);
                return $change;
            });

        return response()->json([
            'item' => $item,
            'children' => $children,
            'history' => $history
        ]);
    }

    /**
     * Update item status/location via barcode scanning
     */
    public function updateItemByBarcode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => 'required|string|exists:items,id',
            'status_id' => 'nullable|string|exists:statuses,id',
            'location_id' => 'nullable|string|exists:locations,id',
        ]);

        $user = $request->user();
        $item = Item::findOrFail($data['item_id']);

        // Check permissions
        if (!$user->hasTeamPermission($item->team, 'item:write') ||
            !$user->tokenCan('item:write')
        ) {
            throw new AuthorizationException();
        }

        // Validate that status/location belong to the same team
        if (isset($data['status_id'])) {
            $status = Status::where('id', $data['status_id'])
                ->where('team_id', $item->team_id)
                ->firstOrFail();
        }

        if (isset($data['location_id'])) {
            $location = Location::where('id', $data['location_id'])
                ->where('team_id', $item->team_id)
                ->firstOrFail();
        }

        // Record changes in history
        $trackableFields = ['status_id', 'location_id'];
        foreach ($trackableFields as $field) {
            if (isset($data[$field])) {
                $oldValue = $item->$field;
                $newValue = $data[$field];
                
                if ($oldValue !== $newValue) {
                    History::create([
                        'item_id' => $item->id,
                        'user_id' => $user->id,
                        'field_name' => $field,
                        'old_value' => $oldValue,
                        'new_value' => $newValue,
                        'changed_at' => now(),
                    ]);
                }
            }
        }

        // Update the item
        $item->update(array_filter($data, function($key) {
            return in_array($key, ['status_id', 'location_id']);
        }, ARRAY_FILTER_USE_KEY));

        return response()->json([
            'success' => true,
            'item' => $item->fresh()
        ]);
    }

    /**
     * Get recent history for dashboard
     */
    public function getRecentHistory(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $history = History::whereHas('item', function($query) use ($user) {
            $query->where('team_id', $user->current_team_id);
        })
        ->with(['item', 'user'])
        ->orderBy('changed_at', 'desc')
        ->limit(50)
        ->get();

        // Get all statuses, locations, and labels for name resolution
        $statuses = Status::where('team_id', $user->current_team_id)->pluck('name', 'id');
        $locations = Location::where('team_id', $user->current_team_id)->pluck('name', 'id');
        $labels = Label::where('team_id', $user->current_team_id)->pluck('name', 'id');

        // Enhance history with resolved names
        $enhancedHistory = $history->map(function($change) use ($statuses, $locations, $labels) {
            $change->old_value_name = $this->resolveValueName($change->field_name, $change->old_value, $statuses, $locations, $labels);
            $change->new_value_name = $this->resolveValueName($change->field_name, $change->new_value, $statuses, $locations, $labels);
            return $change;
        });

        return response()->json($enhancedHistory);
    }

    /**
     * Resolve field value ID to human-readable name
     */
    private function resolveValueName(string $fieldName, $value, $statuses, $locations, $labels): ?string
    {
        if (!$value) return null;

        switch ($fieldName) {
            case 'status_id':
                return $statuses->get($value);
            case 'location_id':
                return $locations->get($value);
            case 'label_id':
                return $labels->get($value);
            case 'name':
                return $value; // Name field stores the actual name, not an ID
            default:
                return $value;
        }
    }

    /**
     * Create new status from kanban view
     */
    public function createStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $user = $request->user();

        if (!$user->hasTeamPermission($user->current_team, 'status:write') ||
            !$user->tokenCan('status:write')
        ) {
            throw new AuthorizationException();
        }

        $status = Status::create([
            'name' => $data['name'],
            'team_id' => $user->current_team_id,
        ]);

        return response()->json($status);
    }

    /**
     * Create new location from kanban view
     */
    public function createLocation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $user = $request->user();

        if (!$user->hasTeamPermission($user->current_team, 'location:write') ||
            !$user->tokenCan('location:write')
        ) {
            throw new AuthorizationException();
        }

        $location = Location::create([
            'name' => $data['name'],
            'team_id' => $user->current_team_id,
        ]);

        return response()->json($location);
    }

    /**
     * Update status
     */
    public function updateStatus(Request $request, string $statusId): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $user = $request->user();
        $status = Status::where('id', $statusId)
            ->where('team_id', $user->current_team_id)
            ->firstOrFail();

        if (!$user->hasTeamPermission($user->current_team, 'status:write') ||
            !$user->tokenCan('status:write')
        ) {
            throw new AuthorizationException();
        }

        $status->update($data);
        return response()->json($status);
    }

    /**
     * Delete status
     */
    public function deleteStatus(Request $request, string $statusId): JsonResponse
    {
        $user = $request->user();
        $status = Status::where('id', $statusId)
            ->where('team_id', $user->current_team_id)
            ->firstOrFail();

        if (!$user->hasTeamPermission($user->current_team, 'status:write') ||
            !$user->tokenCan('status:write')
        ) {
            throw new AuthorizationException();
        }

        $status->delete();
        return response()->json(['success' => true]);
    }

    /**
     * Update location
     */
    public function updateLocation(Request $request, string $locationId): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $user = $request->user();
        $location = Location::where('id', $locationId)
            ->where('team_id', $user->current_team_id)
            ->firstOrFail();

        if (!$user->hasTeamPermission($user->current_team, 'location:write') ||
            !$user->tokenCan('location:write')
        ) {
            throw new AuthorizationException();
        }

        $location->update($data);
        return response()->json($location);
    }

    /**
     * Delete location
     */
    public function deleteLocation(Request $request, string $locationId): JsonResponse
    {
        $user = $request->user();
        $location = Location::where('id', $locationId)
            ->where('team_id', $user->current_team_id)
            ->firstOrFail();

        if (!$user->hasTeamPermission($user->current_team, 'location:write') ||
            !$user->tokenCan('location:write')
        ) {
            throw new AuthorizationException();
        }

        $location->delete();
        return response()->json(['success' => true]);
    }

    /**
     * Resolve field values to human-readable names
     */
    private function resolveValueToName(string $fieldName, ?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        switch ($fieldName) {
            case 'status_id':
                $status = Status::find($value);
                return $status ? $status->name : $value;
            
            case 'location_id':
                $location = Location::find($value);
                return $location ? $location->name : $value;
            
            case 'parent_id':
                $parent = Item::find($value);
                return $parent ? $parent->name : $value;
            
            default:
                return $value;
        }
    }

    /**
     * Search for items and boxes
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->get('query', '');
        $filter = $request->get('filter', 'all');
        $user = $request->user();

        if (empty($query)) {
            // If no query, return all items for filter view
            $itemsQuery = Item::with(['status', 'location', 'parent', 'labels'])
                ->where('team_id', $user->current_team_id);
        } else {
            $itemsQuery = Item::with(['status', 'location', 'parent', 'labels'])
                ->where('team_id', $user->current_team_id)
                ->where(function($q) use ($query) {
                    $q->where('name', 'LIKE', "%{$query}%")
                      ->orWhere('id', $query); // Exact match for ID like mobile app
                });
        }

        // Apply filter
        if ($filter === 'boxes') {
            // Items that have children (are containers/boxes)
            $itemsQuery->has('childrens');
        } elseif ($filter === 'items') {
            // Items that don't have children (are individual items)
            $itemsQuery->doesntHave('childrens');
        }

        $items = $itemsQuery->withCount('childrens as children_count')
            ->limit(50)
            ->get();

        return response()->json($items);
    }

    /**
     * Get all labels for the team
     */
    public function getLabels(Request $request): JsonResponse
    {
        $user = $request->user();
        $labels = Label::where('team_id', $user->current_team_id)
            ->orderBy('name')
            ->get();

        return response()->json($labels);
    }

    /**
     * Create a new label
     */
    public function createLabel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        ]);

        $user = $request->user();

        if (!$user->hasTeamPermission($user->current_team, 'label:write') ||
            !$user->tokenCan('label:write')
        ) {
            // For now, allow if user has general write permissions
            if (!$user->hasTeamPermission($user->current_team, 'item:write') ||
                !$user->tokenCan('item:write')
            ) {
                throw new AuthorizationException();
            }
        }

        $label = Label::create([
            'name' => $data['name'],
            'color' => $data['color'],
            'team_id' => $user->current_team_id,
        ]);

        return response()->json($label);
    }

    /**
     * Update label
     */
    public function updateLabel(Request $request, string $labelId): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        ]);

        $user = $request->user();
        $label = Label::where('id', $labelId)
            ->where('team_id', $user->current_team_id)
            ->firstOrFail();

        if (!$user->hasTeamPermission($user->current_team, 'label:write') ||
            !$user->tokenCan('label:write')
        ) {
            // For now, allow if user has general write permissions
            if (!$user->hasTeamPermission($user->current_team, 'item:write') ||
                !$user->tokenCan('item:write')
            ) {
                throw new AuthorizationException();
            }
        }

        $label->update($data);
        return response()->json($label);
    }

    /**
     * Delete label
     */
    public function deleteLabel(Request $request, string $labelId): JsonResponse
    {
        $user = $request->user();
        $label = Label::where('id', $labelId)
            ->where('team_id', $user->current_team_id)
            ->firstOrFail();

        if (!$user->hasTeamPermission($user->current_team, 'label:write') ||
            !$user->tokenCan('label:write')
        ) {
            // For now, allow if user has general write permissions
            if (!$user->hasTeamPermission($user->current_team, 'item:write') ||
                !$user->tokenCan('item:write')
            ) {
                throw new AuthorizationException();
            }
        }

        $label->delete();
        return response()->json(['success' => true]);
    }

    /**
     * Add label to item
     */
    public function addLabelToItem(Request $request, string $itemId): JsonResponse
    {
        $data = $request->validate([
            'label_id' => 'required|string|exists:labels,id',
        ]);

        $user = $request->user();
        $item = Item::where('id', $itemId)
            ->where('team_id', $user->current_team_id)
            ->firstOrFail();

        $label = Label::where('id', $data['label_id'])
            ->where('team_id', $user->current_team_id)
            ->firstOrFail();

        if (!$user->hasTeamPermission($user->current_team, 'item:write') ||
            !$user->tokenCan('item:write')
        ) {
            throw new AuthorizationException();
        }

        // Check if label is already attached
        if ($item->labels()->where('label_id', $data['label_id'])->exists()) {
            return response()->json(['message' => 'Label already attached to item'], 409);
        }

        $item->labels()->attach($data['label_id']);

        return response()->json([
            'success' => true,
            'item' => $item->fresh(['labels'])
        ]);
    }

    /**
     * Remove label from item
     */
    public function removeLabelFromItem(Request $request, string $itemId, string $labelId): JsonResponse
    {
        $user = $request->user();
        $item = Item::where('id', $itemId)
            ->where('team_id', $user->current_team_id)
            ->firstOrFail();

        $label = Label::where('id', $labelId)
            ->where('team_id', $user->current_team_id)
            ->firstOrFail();

        if (!$user->hasTeamPermission($user->current_team, 'item:write') ||
            !$user->tokenCan('item:write')
        ) {
            throw new AuthorizationException();
        }

        $item->labels()->detach($labelId);

        return response()->json([
            'success' => true,
            'item' => $item->fresh(['labels'])
        ]);
    }
}
