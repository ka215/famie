<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityImage;
use App\Models\Group;
use App\Models\GroupMember;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityImageController extends Controller
{
    public function show(Group $group, int $image): StreamedResponse
    {
        $target = ActivityImage::query()->where('group_id', $group->id)->whereKey($image)
            ->whereHas('activityLog', fn ($query) => $query->whereIn('user_id', $group->memberships()->where('status', GroupMember::STATUS_ACTIVE)->select('user_id')))->firstOrFail();
        abort_unless(Storage::disk($target->disk)->exists($target->path), 404);

        return response()->stream(function () use ($target): void {
            $stream = Storage::disk($target->disk)->readStream($target->path);
            if ($stream !== false) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
