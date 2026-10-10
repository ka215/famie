<?php

namespace App\Console\Commands;

use App\Models\Group;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('famie:group-images {group} {--enable} {--disable} {--quota=}')]
#[Description('Configure image access and quota for one family')]
class ConfigureGroupImages extends Command
{
    public function handle(): int
    {
        $group = Group::query()->findOrFail($this->argument('group'));
        if ($this->option('enable') && $this->option('disable')) {
            $this->error('有効化と無効化を同時に指定できません。');

            return self::FAILURE;
        }
        $quota = $this->option('quota');
        if ($quota !== null && (! ctype_digit((string) $quota) || (int) $quota < 1)) {
            $this->error('容量上限は正のバイト数を指定してください。');

            return self::FAILURE;
        }
        $this->line("家族 {$group->id}: {$group->name}");
        $this->line('現在: '.($group->images_enabled ? 'ON' : 'OFF')." / {$group->image_quota_bytes} bytes");
        $enabled = $this->option('enable') ? true : ($this->option('disable') ? false : $group->images_enabled);
        $nextQuota = $quota === null ? $group->image_quota_bytes : (int) $quota;
        $this->line('変更後: '.($enabled ? 'ON' : 'OFF')." / {$nextQuota} bytes");
        if ($enabled === $group->images_enabled && $nextQuota === $group->image_quota_bytes) {
            return self::SUCCESS;
        }
        if (! $this->confirm('この家族の設定を変更しますか？')) {
            return self::FAILURE;
        }
        $group->forceFill(['images_enabled' => $enabled, 'image_quota_bytes' => $nextQuota])->save();

        return self::SUCCESS;
    }
}
