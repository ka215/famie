<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LegalDocumentCatalog;
use Illuminate\Http\JsonResponse;
use JsonException;
use RuntimeException;

class LegalDocumentController extends Controller
{
    public function __invoke(LegalDocumentCatalog $catalog): JsonResponse
    {
        try {
            $documents = $catalog->documents((string) config('legal.manifest_path'));
        } catch (JsonException|RuntimeException) {
            return response()->json(['code' => 'legal_documents_unavailable', 'message' => __('errors.server')], 503, ['Cache-Control' => 'no-store, max-age=0']);
        }

        return response()->json(['data' => $documents, 'meta' => ['acceptance_enabled' => (bool) config('legal.storage_enabled') && (bool) config('legal.acceptance_enabled')]], 200, ['Cache-Control' => 'no-store, max-age=0']);
    }
}
