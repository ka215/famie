<?php

namespace App\Support;

use JsonException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class ReservedUsernames
{
    public function __construct(private ?string $directory = null) {}

    public function contains(string $username): bool
    {
        $directory = $this->directory ?? resource_path('data');
        try {
            $definition = json_decode($this->read($directory.'/reserved-usernames.json'), true, 512, JSON_THROW_ON_ERROR);
            $raw = $this->read($directory.'/reserved-usernames-base.json');
            $base = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $license = $this->read($directory.'/reserved-usernames-base.LICENSE');
            $this->requireValid(is_array($definition) && ! array_is_list($definition));
            $this->keys($definition, ['schema_version', 'base_source', 'base_exact', 'exact']);
            $this->requireValid($definition['schema_version'] === 1 && is_array($definition['base_source']));
            $source = $definition['base_source'];
            $this->keys($source, ['repository', 'commit', 'file', 'license', 'sha256', 'retrieved_on']);
            $this->requireValid($source['repository'] === 'shouldbee/reserved-usernames'
                && $source['file'] === 'reserved-usernames.json' && $source['license'] === 'MIT'
                && is_string($source['commit']) && preg_match('/^[a-f0-9]{40}$/D', $source['commit']) === 1
                && is_string($source['sha256']) && hash_equals(hash('sha256', $raw), $source['sha256'])
                && is_string($source['retrieved_on']) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $source['retrieved_on']) === 1
                && str_contains($license, 'Permission is hereby granted') && str_contains($license, 'Copyright (c) 2014'));
            $this->words($base, false);
            $this->words($definition['base_exact'], true);
            $this->words($definition['exact'], true);
            $this->requireValid(array_diff($definition['base_exact'], $base) === []);

            return in_array(strtolower($username), array_unique([...$definition['base_exact'], ...$definition['exact']]), true);
        } catch (JsonException $exception) {
            throw new ServiceUnavailableHttpException(null, 'Invalid reserved username definition.', $exception);
        }
    }

    private function read(string $path): string
    {
        $this->requireValid(is_file($path) && is_readable($path));
        $contents = file_get_contents($path);
        $this->requireValid($contents !== false);

        return $contents;
    }

    private function keys(array $value, array $expected): void
    {
        $this->requireValid(count($value) === count($expected) && array_diff($expected, array_keys($value)) === []);
    }

    private function words(mixed $words, bool $validateSyntax): void
    {
        $this->requireValid(is_array($words) && array_is_list($words));
        $normalized = [];
        foreach ($words as $word) {
            $this->requireValid(is_string($word) && $word !== '');
            if ($validateSyntax) {
                $this->requireValid($word === strtolower($word) && preg_match(UsernameRules::PATTERN.'D', $word) === 1);
            }
            $normalized[] = strtolower($word);
        }
        $this->requireValid(count($normalized) === count(array_unique($normalized)));
    }

    private function requireValid(bool $valid): void
    {
        if (! $valid) {
            throw new ServiceUnavailableHttpException(null, 'Invalid reserved username definition.');
        }
    }
}
