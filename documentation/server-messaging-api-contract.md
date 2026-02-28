# Server Messaging API Contract

This document defines the API contract between the central server (`my.tracking202.com`, Laravel) and individual Prosper202 installations for the custom server messaging system.

## Overview

The central server sends targeted messages to individual Prosper202 installations. Each installation polls the central server periodically (every 15 minutes) and caches messages locally. Messages are displayed in a slide-in notification panel accessible via a bell icon in the Prosper202 navbar.

**v2 Features:** Rich HTML bodies, image attachments, message categories with filtering, two-way replies, per-user read/dismissed state.

## API Endpoints

### GET `/api/v1/server-messages/{install_hash}`

Returns all active messages targeted at the specified installation.

#### Request

| Header | Value | Required |
|---|---|---|
| `Accept` | `application/json` | Yes |
| `X-P202-Api-Key` | Customer API key from `202_users.p202_customer_api_key` | No (recommended) |
| `X-P202-Version` | Prosper202 version string (e.g., `1.9.62`) | No |
| `User-Agent` | `Prosper202-ServerMessaging/{version}` | Auto-set by client |

#### Response (200 OK)

```json
{
  "data": [
    {
      "id": "msg_abc123",
      "type": "info",
      "category": "update",
      "title": "New Feature Available",
      "body": "<p>We just released <strong>multi-touch attribution</strong>. Update to try it out.</p><ul><li>Last-touch</li><li>First-touch</li><li>Linear</li></ul>",
      "format": "html",
      "image_url": "https://my.tracking202.com/images/attribution-banner.jpg",
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
      "category": "alert",
      "title": "Security Update Required",
      "body": "A critical security patch is available. Please update your installation as soon as possible.",
      "format": "plain",
      "image_url": null,
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
| `category` | string | No | Message category for filtering: `general`, `update`, `alert`, `news`, `promo`. Default: `general` |
| `title` | string | **Yes** | Short message title (max 500 chars) |
| `body` | string | **Yes** | Message body. Plain text or safe HTML depending on `format`. |
| `format` | string | No | Body format: `plain` (escaped + nl2br) or `html` (sanitized subset). Default: `plain` |
| `image_url` | string\|null | No | Hero image URL displayed above the body. Must be https. |
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

#### Message Categories

| Category | Use Case |
|---|---|
| `general` | Default — general announcements |
| `update` | Software updates, new versions |
| `alert` | Security alerts, critical notices |
| `news` | Blog posts, company news |
| `promo` | Promotions, discounts, offers |

Categories appear as filter tabs in the message panel when more than one category has active messages.

#### Rich HTML Body (format: "html")

When `format` is `html`, the client sanitizes the body to this safe subset of tags:

**Allowed:** `<b>`, `<i>`, `<strong>`, `<em>`, `<a>`, `<br>`, `<p>`, `<ul>`, `<ol>`, `<li>`, `<code>`, `<pre>`, `<h4>`, `<h5>`, `<h6>`, `<blockquote>`, `<hr>`, `<span>`, `<img>`

**Link handling:** All `<a>` tags are forced to `target="_blank" rel="noopener noreferrer"`. Only `http://`, `https://`, and `mailto:` URLs are allowed.

**Image handling:** Inline `<img>` tags must use `https://` src URLs. They are auto-sized with `max-width:100%`.

#### Error Responses

| Status | Body | Meaning |
|---|---|---|
| `404` | `{"error": "Installation not found"}` | Unknown install_hash |
| `401` | `{"error": "Invalid API key"}` | Bad X-P202-Api-Key (if auth required) |
| `429` | `{"error": "Rate limited"}` | Too many requests |
| `500` | `{"error": "Internal server error"}` | Server error |

---

### POST `/api/v1/server-messages/{install_hash}/reply`

Receives a reply from a Prosper202 user to a specific message.

#### Request

```json
{
  "message_id": "msg_abc123",
  "body": "Thanks, we'll update this weekend!",
  "user_id": 1
}
```

| Header | Value | Required |
|---|---|---|
| `Content-Type` | `application/json` | Yes |
| `X-P202-Api-Key` | Customer API key | Recommended |
| `X-P202-Version` | Prosper202 version string | No |

#### Response (200 OK)

```json
{
  "success": true,
  "reply_id": "reply_xyz789"
}
```

The `reply_id` is stored locally so the client can correlate server-side replies.

---

## Targeting Strategy

The central server can target messages to:

1. **All installations** - Broadcast messages (return for every `install_hash`)
2. **Specific installations** - Target by `install_hash`
3. **Version-based** - Filter by `X-P202-Version` header (e.g., only show to versions < 1.9.62)
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
            $table->string('category', 50)->default('general');
            $table->string('title', 500);
            $table->text('body');
            $table->string('format', 10)->default('plain');
            $table->string('image_url', 500)->nullable();
            $table->string('action_url', 500)->nullable();
            $table->string('action_label', 100)->nullable();
            $table->unsignedTinyInteger('priority')->default(0);
            $table->string('icon', 50)->nullable();
            $table->timestamp('published_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'expires_at']);
            $table->index('category');
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

        // Replies received from installations
        Schema::create('server_message_replies', function (Blueprint $table) {
            $table->id();
            $table->string('message_id', 100);
            $table->string('install_hash', 255);
            $table->unsignedInteger('remote_user_id')->nullable();
            $table->text('body');
            $table->timestamps();

            $table->index('message_id');
            $table->index('install_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_message_replies');
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
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServerMessage extends Model
{
    protected $fillable = [
        'message_id', 'type', 'category', 'title', 'body', 'format',
        'image_url', 'action_url', 'action_label', 'priority', 'icon',
        'published_at', 'expires_at', 'is_active',
    ];

    protected $casts = [
        'priority' => 'integer',
        'is_active' => 'boolean',
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function targets(): HasMany
    {
        return $this->hasMany(ServerMessageTarget::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(ServerMessageReply::class, 'message_id', 'message_id');
    }

    public function scopeActive($query)
    {
        return $query
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
    }

    public function scopeForInstallation($query, string $installHash)
    {
        return $query->where(function ($q) use ($installHash) {
            $q->whereDoesntHave('targets')
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
use App\Models\ServerMessageReply;
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
                'category' => $msg->category,
                'title' => $msg->title,
                'body' => $msg->body,
                'format' => $msg->format,
                'image_url' => $msg->image_url,
                'action_url' => $msg->action_url,
                'action_label' => $msg->action_label,
                'priority' => $msg->priority,
                'icon' => $msg->icon,
                'published_at' => $msg->published_at->toIso8601String(),
                'expires_at' => $msg->expires_at?->toIso8601String(),
            ]),
        ]);
    }

    public function reply(Request $request, string $installHash): JsonResponse
    {
        $validated = $request->validate([
            'message_id' => 'required|string|max:100',
            'body' => 'required|string|max:2000',
            'user_id' => 'nullable|integer',
        ]);

        $reply = ServerMessageReply::create([
            'message_id' => $validated['message_id'],
            'install_hash' => $installHash,
            'remote_user_id' => $validated['user_id'] ?? null,
            'body' => $validated['body'],
        ]);

        return response()->json([
            'success' => true,
            'reply_id' => 'reply_' . $reply->id,
        ]);
    }
}
```

### Routes

```php
// routes/api.php
Route::prefix('v1')->group(function () {
    Route::get('server-messages/{installHash}', [ServerMessageController::class, 'index']);
    Route::post('server-messages/{installHash}/reply', [ServerMessageController::class, 'reply']);
});
```

### Creating Messages (Tinker / Nova / Admin)

```php
use App\Models\ServerMessage;
use Illuminate\Support\Str;

// Broadcast rich HTML message with image to all installations
ServerMessage::create([
    'message_id' => 'msg_' . Str::random(12),
    'type' => 'info',
    'category' => 'update',
    'title' => 'New Feature: Multi-Touch Attribution',
    'body' => '<p>We just released <strong>multi-touch attribution</strong>!</p><ul><li>Last-touch</li><li>First-touch</li><li>Linear weighting</li></ul>',
    'format' => 'html',
    'image_url' => 'https://my.tracking202.com/images/attribution-banner.jpg',
    'action_url' => 'https://my.tracking202.com/updates/attribution',
    'action_label' => 'Learn More',
    'priority' => 5,
    'published_at' => now(),
    'expires_at' => now()->addDays(30),
]);

// Target a specific installation with a plain text alert
$msg = ServerMessage::create([
    'message_id' => 'msg_' . Str::random(12),
    'type' => 'warning',
    'category' => 'alert',
    'title' => 'Your license expires soon',
    'body' => "Your Prosper202 license expires in 7 days.\nRenew now to keep your tracking running.",
    'format' => 'plain',
    'action_url' => 'https://my.tracking202.com/renew',
    'action_label' => 'Renew License',
    'priority' => 10,
    'published_at' => now(),
]);
$msg->targets()->create(['install_hash' => 'abc123def456...']);
```

## Client Behavior (Prosper202 Side)

1. **Polling**: Syncs every 15 minutes via `ServerMessaging::syncMessages()`
2. **Dedup**: Messages upserted by `message_id` — content updates but user state preserved
3. **Expiry**: Expired messages auto-cleaned on sync
4. **Per-user state**: Each user sees their own read/dismissed state independently
5. **Categories**: Filter tabs appear when messages span multiple categories
6. **Rich content**: HTML bodies sanitized to safe subset; hero images displayed above body
7. **Replies**: Users can reply inline; replies stored locally and POSTed to central server
8. **Badge**: Unread count badge polls every 5 minutes via AJAX

## Database Tables (Prosper202 Side)

| Table | Purpose |
|---|---|
| `202_server_messages` | Cached messages from central server |
| `202_server_messages_sync` | Sync tracking (last success, errors) |
| `202_server_message_user_state` | Per-user read/dismissed state |
| `202_server_message_replies` | User replies (local + sent-to-server tracking) |
