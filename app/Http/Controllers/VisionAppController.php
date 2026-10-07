<?php

namespace App\Http\Controllers;

use App\Services\VisionDevices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The in-browser camera: open /vision on a phone overlooking the table. The page
 * finds the table, watches the balls and posts pots to the API by itself.
 */
class VisionAppController extends Controller
{
    public function show(): View
    {
        return view('vision', [
            'pairingEnabled' => filled(config('services.vision.pair_password')),
        ]);
    }

    /**
     * POST /vision/pair: trade the pairing password (VISION_PAIR_PASSWORD in .env)
     * for this phone's API token, so no shell is needed to set up a camera.
     */
    public function pair(Request $request, VisionDevices $devices): JsonResponse
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
