<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LegalAcceptanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LegalAcceptanceController extends Controller
{
    public function __invoke(Request $request, LegalAcceptanceService $acceptances): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['legal']) !== []) {
            throw ValidationException::withMessages(['legal' => __('errors.validation')]);
        }
        $acceptances->accept($request->user(), $request->input('legal'));

        return response()->json(['status' => 'current'], 200, ['Cache-Control' => 'no-store, max-age=0']);
    }
}
