<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Hall;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;

class HallController extends Controller
{
    /**
     *  
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(): JsonResponse
    {
        $halls = Hall::where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return response()->json([
            'status' => 'success',
            'data' => $halls
        ]);
    }

    /**
     *  
     *
     * @param  \Illuminate\Http\Request   
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user || $user->role !== 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Доступ запрещён'
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'rows' => 'required|integer|min:1|max:50',
            'seats_per_row' => 'required|integer|min:1|max:50',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        $hall = Hall::create($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Зал успешно создан',
            'data' => $hall
        ], 201);
    }

    /**
     *  
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id): JsonResponse
    {
        $hall = Hall::with('sessions')->find($id);
        
        if (!$hall) {
            return response()->json([
                'status' => 'error',
                'message' => 'Зал не найден'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $hall
        ]);
    }

    /**
     *  
     *
     * @param  \Illuminate\Http\Request  
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id): JsonResponse
    {
        $user = Auth::user();
        if (!$user || $user->role !== 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Доступ запрещён'
            ], 403);
        }

        $hall = Hall::find($id);
        if (!$hall) {
            return response()->json([
                'status' => 'error',
                'message' => 'Зал не найден'
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'rows' => 'sometimes|required|integer|min:1|max:50',
            'seats_per_row' => 'sometimes|required|integer|min:1|max:50',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'sometimes|boolean'
        ]);
 
        if (isset($validated['is_active']) && !$validated['is_active']) {
            $activeSessions = $hall->sessions()
                ->where('start_time', '>', now())
                ->where('is_active', true)
                ->exists();

            if ($activeSessions) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Невозможно деактивировать зал с активными сеансами'
                ], 422);
            }
        }

        $hall->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Зал успешно обновлен',
            'data' => $hall
        ]);
    }

    /**
     * 
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id): JsonResponse
    {
        $user = Auth::user();
        if (!$user || $user->role !== 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Доступ запрещён'
            ], 403);
        }

        $hall = Hall::find($id);
        if (!$hall) {
            return response()->json([
                'status' => 'error',
                'message' => 'Зал не найден'
            ], 404);
        }
 
        $activeSessions = $hall->sessions()
            ->where('start_time', '>', now())
            ->where('is_active', true)
            ->exists();

        if ($activeSessions) {
            return response()->json([
                'status' => 'error',
                'message' => 'Невозможно удалить зал с активными сеансами'
            ], 422);
        }
 
        $hall->update(['is_active' => false]);

        return response()->json([
            'status' => 'success',
            'message' => 'Зал успешно деактивирован'
        ]);
    }
}
