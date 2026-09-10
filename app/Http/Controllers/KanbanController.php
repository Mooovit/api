<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\History;
use App\Models\Item;
use App\Models\ItemBarcode;
use App\Models\Label;
use App\Models\Location;
use App\Models\Status;
use App\Support\ItemShare;
use App\Support\TeamRevision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

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
     * The board row shape shared by the server-rendered board (index) and
     * the delta feed — the JS applies delta rows with the same renderer.
     */
    private function boardRow(Item $item): array
    {
        return [
            "id" => $item->id,
            "title" => $item->name,
            "status_id" => $item->status_id,
            "location_id" => $item->location_id,
            "parent_id" => $item->parent_id,
            "status_name" => $item->status ? $item->status->name : null,
            "location_name" => $item->location ? $item->location->name : null,
            "labels" => $item->labels->map(function ($label) {
                return [
                    'id' => $label->id,
                    'name' => $label->name,
                    'color' => $label->color,
                    'text_color' => $this->getTextColor($label->color)
                ];
            })->toArray(),
            "updated_at" => $item->updated_at->toISOString(),
        ];
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

        /* API-027: the CARD payloads keep a deleted status/location's name
           (nested trashed-inclusive eager loads); the board's own column
           query above stays default-scoped — a deleted column vanishes. */
        $jsonData = [];
        foreach($query['model']::where(["team_id" => $team_id])->with([
            'items.status' => fn ($q) => $q->withTrashed(),
            'items.location' => fn ($q) => $q->withTrashed(),
            'items.labels',
        ])->get() as $instance) {
            $boardItem = [];
            foreach($instance->items as $item) {
                if(!$item->parent_id) {
                    array_push($boardItem, $this->boardRow($item));
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

        /* API-003: the JS live view polls this counter (see revision/delta) */
        $revision = (int) \App\Models\Team::whereKey($team_id)->value('revision');

        return view('kanban', compact('jsonData', 'query', 'type', 'recentHistory', 'statuses', 'locations', 'labels', 'user', 'revision'))
            ->with('builtAt', now());
    }

    /**
     * GET /kanban/revision — the team's change counter (API-003, session
     * flavor for the kanban live view). The JS polls this every few seconds
     * and only pulls the delta feed when the integer actually moved.
     */
    public function revision(Request $request): JsonResponse
    {
        $revision = (int) \App\Models\Team::whereKey($request->user()->current_team_id)->value('revision');

        return response()->json(['revision' => $revision]);
    }

    /**
     * GET /kanban/delta?since=<ISO-8601> — everything that changed in the
     * team since `since` (API-006, session flavor): `changed` holds full
     * board rows (same shape as the server-rendered board, children
     * included — the JS drops them), `deleted_ids` the team-scoped ids
     * soft-deleted after `since`, plus the current `revision`.
     */
    public function delta(Request $request): JsonResponse
    {
        $data = $request->validate([
            'since' => 'required|date',
        ]);
        $since = $request->date('since');
        $team_id = $request->user()->current_team_id;

        /* API-027: status/location eager loads are trashed-inclusive —
           delta rows keep the deleted row's NAME */
        $changed = Item::with([
            'status' => fn ($q) => $q->withTrashed(),
            'location' => fn ($q) => $q->withTrashed(),
            'labels',
        ])
            ->where('team_id', $team_id)
            ->where('updated_at', '>', $since)
            ->get()
            ->map(fn (Item $item) => $this->boardRow($item))
            ->values();

        return response()->json([
            'changed' => $changed,
            'deleted_ids' => Item::onlyTrashed()
                ->where('team_id', $team_id)
                ->where('deleted_at', '>', $since)
                ->pluck('id'),
            'revision' => (int) \App\Models\Team::whereKey($team_id)->value('revision'),
        ]);
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
        // (API-027: status/location plucks are trashed-inclusive so feed
        // rows referencing a deleted catalogue row keep a NAME)
        $statuses = Status::where('team_id', $team_id)->withTrashed()->pluck('name', 'id');
        $locations = Location::where('team_id', $team_id)->withTrashed()->pluck('name', 'id');
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
        
        /* API-027: status/location eager loads are trashed-inclusive — the
           details payload keeps a deleted row's NAME */
        $item = Item::with(['histories.user', 'team', 'parent',
            'status' => fn ($q) => $q->withTrashed(),
            'location' => fn ($q) => $q->withTrashed(),
            'labels', 'barcodes'])
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

        /* API-013: attachments as metadata only (raw rows carry the
           storage path, which must not be serialized) */
        $item->setRelation('attachments',
            $item->attachments()->get()->map(fn ($attachment) => $attachment->metadata())->values());

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
            'history' => $history,
            /* API-025: current public share-link state (additive field) —
               the details modal renders it directly, no extra round-trip */
            'share' => ItemShare::state($item),
        ]);
    }

    /**
     * Update item status/location via barcode scanning
     */
    public function updateItemByBarcode(Request $request): JsonResponse
    {
        /* API-027: the exists layer is trashed-EXPLICIT — a deleted
           status/location id is a 422 (the validator's plain exists is
           scope-blind and would silently accept tombstoned ids) */
        $data = $request->validate([
            'item_id' => 'required|string|exists:items,id',
            'status_id' => ['nullable', 'string', Rule::exists('statuses', 'id')->whereNull('deleted_at')],
            'location_id' => ['nullable', 'string', Rule::exists('locations', 'id')->whereNull('deleted_at')],
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
     * GET /kanban/history — the Live panel's recent team activity.
     *
     * API-026: optional incremental mode via the `after` datetime cursor.
     * Legacy parity: NO `after` → the bare enhanced-rows array (unchanged
     * shape). WITH `after` → `{data: [...], cursor: "..."}` where `data`
     * holds rows with `changed_at >= after` (INCLUSIVE cursor: rows sharing
     * the cursor's timestamp are re-delivered — bounded, deduped client-side
     * by id; this is the sanctioned simple tie-handling, ticket API-026) and
     * `cursor` is the max `changed_at` of the returned set (or the request
     * cursor when empty) so the client can chain blindly. Team scoping and
     * the 50-row cap are unchanged.
     */
    public function getRecentHistory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'after' => ['nullable', 'date'],
        ]);

        $user = $request->user();

        $history = History::whereHas('item', function($query) use ($user) {
            $query->where('team_id', $user->current_team_id);
        })
        ->when($validated['after'] ?? null, function ($query, $after) {
            /* >= — inclusive cursor: same-timestamp rows are re-delivered
               and deduped by the client (rows are append-only, ids stable) */
            $query->where('changed_at', '>=', Carbon::parse($after));
        })
        ->with(['item', 'user'])
        ->orderBy('changed_at', 'desc')
        ->limit(50)
        ->get();

        // Get all statuses, locations, and labels for name resolution
        // (API-027: status/location plucks are trashed-inclusive so feed
        // rows referencing a deleted catalogue row keep a NAME)
        $statuses = Status::where('team_id', $user->current_team_id)->withTrashed()->pluck('name', 'id');
        $locations = Location::where('team_id', $user->current_team_id)->withTrashed()->pluck('name', 'id');
        $labels = Label::where('team_id', $user->current_team_id)->pluck('name', 'id');

        // Enhance history with resolved names
        $enhancedHistory = $history->map(function($change) use ($statuses, $locations, $labels) {
            $change->old_value_name = $this->resolveValueName($change->field_name, $change->old_value, $statuses, $locations, $labels);
            $change->new_value_name = $this->resolveValueName($change->field_name, $change->new_value, $statuses, $locations, $labels);
            return $change;
        });

        /* Legacy shape stays byte-compatible; the cursor envelope only
           appears in incremental mode */
        if (blank($validated['after'] ?? null)) {
            return response()->json($enhancedHistory);
        }

        $cursor = $enhancedHistory->max('changed_at');

        return response()->json([
            'data' => $enhancedHistory->values(),
            'cursor' => $cursor
                ? $cursor->toISOString()
                : Carbon::parse($validated['after'])->toISOString(),
        ]);
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
     * Resolve field values to human-readable names.
     *
     * API-027: lookups are trashed-INCLUSIVE — a status/location/parent
     * deleted AFTER the history row was written still resolves to its NAME
     * (history must stay readable); the row falls back to the raw value only
     * when nothing was ever found.
     */
    private function resolveValueToName(string $fieldName, ?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        switch ($fieldName) {
            case 'status_id':
                $status = Status::withTrashed()->find($value);
                return $status ? $status->name : $value;

            case 'location_id':
                $location = Location::withTrashed()->find($value);
                return $location ? $location->name : $value;

            case 'parent_id':
                $parent = Item::withTrashed()->find($value);
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
            /* API-027: status/location trashed-inclusive — results keep a
               deleted row's NAME */
            $itemsQuery = Item::with(['status' => fn ($q) => $q->withTrashed(),
                'location' => fn ($q) => $q->withTrashed(), 'parent', 'labels'])
                ->where('team_id', $user->current_team_id);
        } else {
            $itemsQuery = Item::with(['status' => fn ($q) => $q->withTrashed(),
                'location' => fn ($q) => $q->withTrashed(), 'parent', 'labels'])
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

        /* Pivot writes fire no model events — bump the team revision and
           stamp the item (API-003 + API-033) so live boards re-pull the row
           and revision-keyed deltas deliver it */
        TeamRevision::bumpAndStamp($item);

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

        /* Pivot writes fire no model events — bump the team revision and
           stamp the item (API-003 + API-033) so live boards re-pull the row
           and revision-keyed deltas deliver it */
        TeamRevision::bumpAndStamp($item);

        return response()->json([
            'success' => true,
            'item' => $item->fresh(['labels'])
        ]);
    }

    /**
     * POST /kanban/item/{itemId}/barcodes — attach a scanned code (API-011,
     * session flavor for the item-details modal). Codes are stored verbatim,
     * whitespace-trimmed and case-sensitive; uniqueness is team-scoped → 409
     * on duplicate, including codes held by soft-deleted items of the same
     * team. Registry writes bump the team revision once but leave the item
     * row's `updated_at` alone (deltas carry no body for it).
     */
    public function attachBarcode(Request $request, string $itemId): JsonResponse
    {
        $user = $request->user();
        $item = Item::where('id', $itemId)
            ->where('team_id', $user->current_team_id)
            ->firstOrFail();

        if (!$user->hasTeamPermission($item->team, 'item:write') ||
            !$user->tokenCan('item:write')
        ) {
            throw new AuthorizationException();
        }

        $data = $request->validate([
            'code' => 'required|string|max:191',
            'type' => 'nullable|string|max:191',
        ]);

        $code = trim($data['code']);

        if (ItemBarcode::where('team_id', $item->team_id)->where('code', $code)->exists()) {
            return response()->json(['error' => 'Code already registered in this team'], 409);
        }

        $barcode = ItemBarcode::create([
            'item_id' => $item->id,
            'team_id' => $item->team_id,
            'code' => $code,
            'type' => $data['type'] ?? null,
        ]);

        /* Registry rows fire no revision observer — bump explicitly (API-003) */
        TeamRevision::bump($barcode);

        return response()->json([
            'success' => true,
            'item' => $item->fresh(['barcodes']),
        ], 201);
    }

    /**
     * DELETE /kanban/item/{itemId}/barcodes/{barcode} — detach a code
     * (API-011, session flavor). `{barcode}` is the barcode row id; a raw
     * code is also accepted. The row must belong to the item, else 404.
     */
    public function detachBarcode(Request $request, string $itemId, string $barcode): JsonResponse
    {
        $user = $request->user();
        $item = Item::where('id', $itemId)
            ->where('team_id', $user->current_team_id)
            ->firstOrFail();

        if (!$user->hasTeamPermission($item->team, 'item:write') ||
            !$user->tokenCan('item:write')
        ) {
            throw new AuthorizationException();
        }

        $row = $item->barcodes()->whereKey($barcode)->first()
            ?? $item->barcodes()->where('code', $barcode)->firstOrFail();

        $row->delete();

        /* Registry rows fire no revision observer — bump explicitly (API-003) */
        TeamRevision::bump($item);

        return response()->json([
            'success' => true,
            'item' => $item->fresh(['barcodes']),
        ]);
    }

    /**
     * POST /kanban/item/{itemId}/share — activate (or re-activate) the
     * item's public share link (API-024, session flavor for the kanban page —
     * it cannot call /api/*, which has no session-cookie stateful group).
     * Team-scoped (404 on foreign items), item:write-gated; semantics
     * (idempotency, fresh token on re-issue, revision bumps) live in
     * App\Support\ItemShare, shared with the API flavor. 201 on the first
     * activation, 200 on re-activation.
     */
    public function shareItem(Request $request, string $itemId): JsonResponse
    {
        $user = $request->user();
        $item = Item::where('id', $itemId)
            ->where('team_id', $user->current_team_id)
            ->firstOrFail();

        if (!$user->hasTeamPermission($item->team, 'item:write') ||
            !$user->tokenCan('item:write')
        ) {
            throw new AuthorizationException();
        }

        $result = ItemShare::activate($item);

        return response()->json([
            'success' => true,
            'share' => $result['payload'],
        ], $result['created'] ? 201 : 200);
    }

    /**
     * DELETE /kanban/item/{itemId}/share — revoke the item's public share
     * link (API-024/025, session flavor). Idempotent; the public URL dies
     * immediately. Team-scoped + item:write-gated like shareItem.
     */
    public function unshareItem(Request $request, string $itemId): JsonResponse
    {
        $user = $request->user();
        $item = Item::where('id', $itemId)
            ->where('team_id', $user->current_team_id)
            ->firstOrFail();

        if (!$user->hasTeamPermission($item->team, 'item:write') ||
            !$user->tokenCan('item:write')
        ) {
            throw new AuthorizationException();
        }

        ItemShare::revoke($item);

        return response()->json(['success' => true]);
    }
}
