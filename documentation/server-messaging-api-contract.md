# Server Messaging API Contract

This document defines the API contract between the central server (`my.tracking202.com`, Laravel) and individual Prosper202 installations for the custom server messaging system.

## Overview

The central server sends targeted messages to individual Prosper202 installations. Each installation polls the central server periodically (every 15 minutes) and caches messages locally. Messages are displayed in a slide-in notification panel accessible via a bell icon in the Prosper202 navbar.

## API Endpoint

### GET `/api/v1/server-messages/{install_hash}`

Returns all active messages targeted at the specified installation.

#### Request

| Header | Value | Required |
|---|---|---|
| `Accept` | `application/json` | Yes |
| `X-P202-Api-Key` | Customer API key from `202_users.p202_customer_api_key` | No (recommended) |
| `X-P202-Version` | Prosper202 version string (e.g., `1.9.61`) | No |
| `User-Agent` | `Prosper202-ServerMessaging/{version}` | Auto-set by client |

#### Path Parameters

| Parameter | Type | Description |
|---|---|---|
| `install_hash` | string | Unique installation identifier from `202_users.install_hash` |

#### Response (200 OK)

```json
{
  "data": [
    {
      "id": "msg_abc123",
      "type": "info",
      "title": "New Feature Available",
      "body": "We just released multi-touch attribution. Update to the latest version to try it out.",
      "action_url": "https://my.tracking202.com/updates/attribution",
      "action_label": "Learn More",
      "priority": 1,
      "icon": null,
      "published_at": "2026-02-28T12:00:00Z",
      "expires_at": "2026-03-15T12:00:00Z"
    },
    {
      "id": "msg_def456",
      "type": "warning",
      "title": "Security Update Required",
      "body": "A critical security patch is available. Please update your installation as soon as possible.",
      "action_url": "https://my.tracking202.com/downloads",
      "action_label": "Download Update",
      "priority": 10,
      "icon": null,
      "published_at": "2026-02-27T08:00:00Z",
      "expires_at": null
    }
  ]
}
```

#### Message Object Fields

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | string | **Yes** | Unique message identifier (used for dedup). Must be stable across requests. |
| `type` | string | No | Message type: `info`, `warning`, `success`, `action`. Default: `info` |
| `title` | string | **Yes** | Short message title (max 500 chars) |
| `body` | string | **Yes** | Message body text. Plain text, newlines preserved. (max ~2000 chars) |
| `action_url` | string\|null | No | Optional CTA button URL |
| `action_label` | string\|null | No | Optional CTA button label (default: "Learn More") |
| `priority` | integer | No | Higher = more prominent (0-255). Default: 0 |
| `icon` | string\|null | No | Optional CSS icon class override (e.g., `fui-alert`, `fa fa-gift`) |
| `published_at` | string (ISO 8601) | No | When the message was published. Default: now |
| `expires_at` | string (ISO 8601)\|null | No | When the message should stop showing. null = never expires |

#### Message Types & UI

| Type | Color | Icon | Use Case |
|---|---|---|---|
| `info` | Blue | `fui-info-circle` | Announcements, news, feature highlights |
| `warning` | Orange | `fui-alert` | Security updates, deprecations, important notices |
| `success` | Green | `fui-check-circle` | Congratulations, milestones, positive feedback |
| `action` | Purple | `fui-gear` | Required actions, configuration needed |

#### Error Responses

| Status | Body | Meaning |
|---|---|---|
| `404` | `{"error": "Installation not found"}` | Unknown install_hash |
| `401` | `{"error": "Invalid API key"}` | Bad X-P202-Api-Key (if auth required) |
| `429` | `{"error": "Rate limited"}` | Too many requests |
| `500` | `{"error": "Internal server error"}` | Server error |

## Targeting Strategy

The central server can target messages to:

1. **All installations** - Broadcast messages (return for every `install_hash`)
2. **Specific installations** - Target by `install_hash`
3. **Version-based** - Filter by `X-P202-Version` header (e.g., only show to versions < 1.9.61)
4. **Customer tier** - Filter by `X-P202-Api-Key` (premium vs free)

## Laravel Implementation Example

### Migration

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_messages', function (Blueprint $table) {
            $table->id();
            $table->string('message_id', 100)->unique();
            $table->string('type', 20)->default('info');
            $table->string('title', 500);
            $table->text('body');
            $table->string('action_url', 500)->nullable();
            $table->string('action_label', 100)->nullable();
            $table->unsignedTinyInteger('priority')->default(0);
            $table->string('icon', 50)->nullable();
            $table->timestamp('published_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'expires_at']);
        });

        // Pivot table for targeting specific installations
        Schema::create('server_message_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_message_id')->constrained()->cascadeOnDelete();
            $table->string('install_hash', 255);
            $table->timestamps();

            $table->unique(['server_message_id', 'install_hash']);
            $table->index('install_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_message_targets');
        Schema::dropIfExists('server_messages');
    }
};
```

### Model

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ServerMessage extends Model
{
    protected $fillable = [
        'message_id',
        'type',
        'title',
        'body',
        'action_url',
        'action_label',
        'priority',
        'icon',
        'published_at',
        'expires_at',
        'is_active',
    ];

    protected $casts = [
        'priority' => 'integer',
        'is_active' => 'boolean',
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Targeted installations (empty = broadcast to all).
     */
    public function targets(): BelongsToMany
    {
        return $this->belongsToMany(
            Installation::class,
            'server_message_targets',
            'server_message_id',
            'install_hash',
            'id',
            'install_hash'
        );
    }

    /**
     * Scope to active, non-expired messages.
     */
    public function scopeActive($query)
    {
        return $query
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Scope to messages visible to a specific installation.
     */
    public function scopeForInstallation($query, string $installHash)
    {
        return $query->where(function ($q) use ($installHash) {
            // Broadcast messages (no targets)
            $q->whereDoesntHave('targets')
              // Or specifically targeted
              ->orWhereHas('targets', function ($tq) use ($installHash) {
                  $tq->where('install_hash', $installHash);
              });
        });
    }
}
```

### Controller

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ServerMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServerMessageController extends Controller
{
    public function index(Request $request, string $installHash): JsonResponse
    {
        $messages = ServerMessage::active()
            ->forInstallation($installHash)
            ->orderByDesc('priority')
            ->orderByDesc('published_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $messages->map(fn (ServerMessage $msg) => [
                'id' => $msg->message_id,
                'type' => $msg->type,
                'title' => $msg->title,
                'body' => $msg->body,
                'action_url' => $msg->action_url,
                'action_label' => $msg->action_label,
                'priority' => $msg->priority,
                'icon' => $msg->icon,
                'published_at' => $msg->published_at->toIso8601String(),
                'expires_at' => $msg->expires_at?->toIso8601String(),
            ]),
        ]);
    }
}
```

### Route

```php
// routes/api.php
Route::prefix('v1')->group(function () {
    Route::get('server-messages/{installHash}', [ServerMessageController::class, 'index']);
});
```

### Admin: Create a Message (Tinker / Nova / Custom Admin)

```php
// Broadcast to all installations
ServerMessage::create([
    'message_id' => 'msg_' . Str::random(12),
    'type' => 'info',
    'title' => 'New Feature: Multi-Touch Attribution',
    'body' => 'We just released multi-touch attribution. Update to the latest version to try it out.',
    'action_url' => 'https://my.tracking202.com/updates/attribution',
    'action_label' => 'Learn More',
    'priority' => 5,
    'published_at' => now(),
    'expires_at' => now()->addDays(30),
]);

// Target specific installation
$msg = ServerMessage::create([
    'message_id' => 'msg_' . Str::random(12),
    'type' => 'warning',
    'title' => 'Your license expires soon',
    'body' => 'Your Prosper202 license expires in 7 days. Renew now to keep your tracking running.',
    'action_url' => 'https://my.tracking202.com/renew',
    'action_label' => 'Renew License',
    'priority' => 10,
    'published_at' => now(),
]);
// Attach to specific install_hash
DB::table('server_message_targets')->insert([
    'server_message_id' => $msg->id,
    'install_hash' => 'abc123def456...',
    'created_at' => now(),
    'updated_at' => now(),
]);
```

## Client Behavior (Prosper202 Side)

1. **Polling**: The client checks for new messages every 15 minutes via `ServerMessaging::syncMessages()`
2. **Dedup**: Messages are upserted by `message_id` — updating content but preserving read/dismissed state
3. **Expiry**: Expired messages are automatically cleaned on each sync
4. **Read tracking**: Messages are marked read when clicked in the panel
5. **Dismiss**: Users can dismiss individual messages (permanently hidden)
6. **Badge**: Unread count badge updates every 5 minutes via AJAX
