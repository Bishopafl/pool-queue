<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VisionDevices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/vision/pair: a Pool Vision phone (poolvision.adamlopez.co) trades the
 * pairing password (VISION_PAIR_PASSWORD in .env) for its camera token, so no shell
 * is needed to set up a camera.
 */
class VisionPairController extends Controller
{
    public function __invoke(Request $request, VisionDevices $devices): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'max:200'],
            'name' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9 _.-]+$/'],
        ]);

        $expected = (string) config('services.vision.pair_password');

        if ($expected === '') {
            return response()->json(['message' => 'Pairing is off. Set VISION_PAIR_PASSWORD in the server .env.'], 503);
        }

        if (! hash_equals($expected, $data['password'])) {
            return response()->json(['message' => 'Wrong pairing password.'], 403);
        }

        return response()->json(['token' => $devices->issueToken($data['name']), 'name' => $data['name']]);
    }
}
