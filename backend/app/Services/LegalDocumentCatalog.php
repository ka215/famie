<?php

namespace App\Services;

use RuntimeException;

class LegalDocumentCatalog
{
    /** @return list<array<string, mixed>> */
    public function documents(string $manifestPath): array
    {
        if (! is_file($manifestPath) || ! is_readable($manifestPath) || filesize($manifestPath) > 1048576) {
            throw new RuntimeException('Legal manifest unavailable.');
        }
        $content = file_get_contents($manifestPath);
        if ($content === false) {
            throw new RuntimeException('Legal manifest unavailable.');
        }
        $manifest = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || ($manifest['schemaVersion'] ?? null) !== 1 || ! is_array($manifest['documents'] ?? null)) {
            throw new RuntimeException('Invalid legal manifest.');
        }
        $documents = [];
        foreach ($manifest['documents'] as $document) {
            if (! is_array($document) || ! is_bool($document['current'] ?? null)) {
                throw new RuntimeException('Invalid legal document.');
            }
            if (! $document['current']) {
                continue;
            }
            $id = $document['id'] ?? null;
            $version = $document['version'] ?? null;
            if (! in_array($id, ['terms', 'privacy', 'cookies'], true) || isset($documents[$id]) || ! is_string($version) || ! preg_match('/\A\d+\.\d+\.\d+\z/', $version)) {
                throw new RuntimeException('Invalid legal identity.');
            }
            $preview = $document['preview'] ?? false;
            $published = $document['publishedOn'] ?? null;
            $effective = $document['effectiveAt'] ?? null;
            $release = $document['effectiveOnRelease'] ?? null;
            if (! is_bool($preview) || ($document['authoritativeLocale'] ?? null) !== 'ja' || ! is_bool($document['requiresAcceptance'] ?? null) || ! $this->validDate($published) || (! $preview && $published === null) || ! $this->validDate($effective) || ($effective === null ? $release !== '1.0.0' : $release !== null)) {
                throw new RuntimeException('Invalid legal metadata.');
            }
            $translations = [];
            foreach (['ja', 'en'] as $locale) {
                $translation = $document['translations'][$locale] ?? null;
                if (! is_array($translation) || ! is_string($translation['title'] ?? null) || trim($translation['title']) === '' || ! is_string($translation['sha256'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', $translation['sha256']) || ($translation['url'] ?? null) !== "/legal/{$id}/{$version}/{$locale}/") {
                    throw new RuntimeException('Invalid legal translation.');
                }
                $translations[$locale] = array_intersect_key($translation, array_flip(['title', 'url', 'sha256']));
            }
            $documents[$id] = [
                'id' => $id, 'version' => $version, 'publishedOn' => $published,
                'effectiveAt' => $effective, 'effectiveOnRelease' => $release,
                'authoritativeLocale' => 'ja', 'current' => true, 'preview' => $preview,
                'requiresAcceptance' => $document['requiresAcceptance'], 'translations' => $translations,
            ];
        }
        if (count($documents) !== 3) {
            throw new RuntimeException('Legal document set incomplete.');
        }

        return array_values($documents);
    }

    private function validDate(mixed $date): bool
    {
        if ($date === null) {
            return true;
        }
        if (! is_string($date) || ! preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $date, $parts)) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
