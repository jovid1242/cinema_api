<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Movie;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;

class MovieController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = Movie::query();
 
        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where('title', 'like', "%{$search}%");
        }
 
        if ($request->has('genre')) {
            $query->where('genre', $request->get('genre'));
        }
 
        if ($request->has('year')) {
            $query->where('release_year', $request->get('year'));
        }
 
        $query->where('is_active', true);
 
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $movies = $query->paginate(10);

        return response()->json([
            'status' => 'success',
            'data' => $movies
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
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'poster_url' => 'nullable|url|max:255',
            'duration_minutes' => 'required|integer|min:1',
            'director' => 'required|string|max:255',
            'genre' => 'required|string|max:100',
            'release_year' => 'required|integer|min:1900|max:' . (date('Y') + 5),
            'rating' => 'nullable|numeric|min:0|max:10',
        ]);

        $movie = Movie::create($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Фильм успешно создан',
            'data' => $movie
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
        $movie = Movie::with(['sessions' => function ($query) { 
            $query->where('start_time', '>', now())
                  ->where('is_active', true)
                  ->orderBy('start_time');
        }, 'sessions.hall'])->find($id);
        
        if (!$movie) {
            return response()->json([
                'status' => 'error',
                'message' => 'Фильм не найден'
            ], 404);
        }
 
        $groupedSessions = $movie->sessions->groupBy(function ($session) {
            return $session->start_time->format('Y-m-d');
        });

        $movie->grouped_sessions = $groupedSessions;

        return response()->json([
            'status' => 'success',
            'data' => $movie
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

        $movie = Movie::find($id);
        if (!$movie) {
            return response()->json([
                'status' => 'error',
                'message' => 'Фильм не найден'
            ], 404);
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'poster_url' => 'nullable|url|max:255',
            'duration_minutes' => 'sometimes|required|integer|min:1',
            'director' => 'sometimes|required|string|max:255',
            'genre' => 'sometimes|required|string|max:100',
            'release_year' => 'sometimes|required|integer|min:1900|max:' . (date('Y') + 5),
            'rating' => 'nullable|numeric|min:0|max:10',
            'is_active' => 'sometimes|boolean'
        ]);

        $movie->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Фильм успешно обновлен',
            'data' => $movie
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

        $movie = Movie::find($id);
        if (!$movie) {
            return response()->json([
                'status' => 'error',
                'message' => 'Фильм не найден'
            ], 404);
        }

        $movie->update(['is_active' => false]);

        return response()->json([
            'status' => 'success',
            'message' => 'Фильм успешно деактивирован'
        ]);
    }
}
