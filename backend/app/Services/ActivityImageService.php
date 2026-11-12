<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Group;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Imagick;
use Throwable;

class ActivityImageService
{
    public function __construct(private GroupImageUsage $usage, private ActivityImageDeletionService $deletions) {}

    /** @return array{disk: string, path: string, bytes: int, width: int, height: int, mime: string} */
    public function prepare(UploadedFile $file): array
    {
        if (! extension_loaded('imagick') || ! in_array('WEBP', Imagick::queryFormats('WEBP'), true)) {
            throw ValidationException::withMessages(['image' => [__('messages.image_unavailable')]]);
        }

        $image = new Imagick;
        try {
            $image->readImage($file->getRealPath());
            if ($image->getNumberImages() !== 1 || ! in_array(strtoupper($image->getImageFormat()), ['JPEG', 'PNG', 'WEBP'], true)) {
                throw ValidationException::withMessages(['image' => [__('messages.image_unsupported')]]);
            }
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            if ($width < 1 || $height < 1 || $width * $height > config('famie.image_max_pixels')) {
                throw ValidationException::withMessages(['image' => [__('messages.image_pixels')]]);
            }
            $image->autoOrient();
            if (max($image->getImageWidth(), $image->getImageHeight()) > config('famie.image_long_edge')) {
                $image->thumbnailImage(config('famie.image_long_edge'), config('famie.image_long_edge'), true);
            }
            $image->stripImage();
            $image->setImageFormat('WEBP');
            $image->setImageCompressionQuality(config('famie.image_webp_quality'));
            $blob = $image->getImageBlob();
            $disk = config('famie.image_disk');
            $path = 'activity-images/'.Str::uuid().'.webp';
            if (! Storage::disk($disk)->put($path, $blob)) {
                throw ValidationException::withMessages(['image' => [__('messages.image_save_failed')]]);
            }

            return ['disk' => $disk, 'path' => $path, 'bytes' => strlen($blob), 'width' => $image->getImageWidth(), 'height' => $image->getImageHeight(), 'mime' => 'image/webp'];
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['image' => [__('messages.image_load_failed')]]);
        } finally {
            $image->clear();
        }
    }

    /** @param array{disk: string, path: string, bytes: int, width: int, height: int, mime: string}|null $prepared */
    public function apply(Group $group, ActivityLog $log, ?array $prepared, bool $remove = false): void
    {
        $old = $log->image()->first();
        if ($prepared !== null) {
            $lockedGroup = Group::query()->lockForUpdate()->findOrFail($group->id);
            abort_unless($lockedGroup->images_enabled, 422, __('messages.image_disabled'));
            $used = $this->usage->bytes($lockedGroup);
            $next = $used + $prepared['bytes'];
            abort_if($next > $lockedGroup->image_quota_bytes && $next >= $used, 422, __('messages.image_quota'));
        }
        if ($old && ($prepared !== null || $remove)) {
            $this->deletions->retire($old);
        }
        if ($prepared !== null) {
            $log->image()->create([...$prepared, 'group_id' => $group->id]);
        }
    }

    public function removeForLog(ActivityLog $log): void
    {
        $image = $log->image()->first();
        if ($image) {
            $this->deletions->retire($image);
        }
    }

    public function cleanupPrepared(array $prepared): void
    {
        Storage::disk($prepared['disk'])->delete($prepared['path']);
    }
}
