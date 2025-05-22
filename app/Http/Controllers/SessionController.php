<?php

namespace App\Http\Controllers;

use App\Models\Session;
use App\Models\Movie;
use App\Models\Hall;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;

class SessionController extends Controller
{
    /**
     *  
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(): JsonResponse
    {
        $sessions = Session::with(['movie', 'hall'])
            ->where('is_active', true)
            ->where('start_time', '>', now())
            ->orderBy('start_time', 'asc')
            ->paginate(10);

        return response()->json([
            'status' => 'success',
            'data' => $sessions
        ]);
    }

    /**
     *  
     *
     * @param  \Illuminate\Http\Request  $request
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
            'movie_id' => 'required|exists:movies,id',
            'hall_id' => 'required|exists:halls,id',
            'start_time' => 'required|date|after:now',
            'price' => 'required|numeric|min:0|max:10000',
            'is_active' => 'boolean',
        ]);

         
        $movie = Movie::findOrFail($validated['movie_id']);
        $hall = Hall::findOrFail($validated['hall_id']);

        if (!$movie->is_active) {
            return response()->json([
                'status' => 'error',
                'message' => 'Фильм неактивен'
            ], 422);
        }

        if (!$hall->is_active) {
            return response()->json([
                'status' => 'error',
                'message' => 'Зал неактивен'
            ], 422);
        }

         
        $conflictingSession = Session::where('hall_id', $validated['hall_id'])
            ->where('is_active', true)
            ->where(function ($query) use ($validated, $movie) {
                $sessionEnd = date('Y-m-d H:i:s', strtotime($validated['start_time'] . ' + ' . $movie->duration_minutes . ' minutes'));
                $query->whereBetween('start_time', [$validated['start_time'], $sessionEnd])
                    ->orWhere(function ($q) use ($validated, $movie) {
                        $sessionEnd = date('Y-m-d H:i:s', strtotime($validated['start_time'] . ' + ' . $movie->duration_minutes . ' minutes'));
                        $q->where('start_time', '<', $validated['start_time'])
                            ->whereRaw('DATE_ADD(start_time, INTERVAL (SELECT duration_minutes FROM movies WHERE id = movie_id) MINUTE) > ?', [$validated['start_time']]);
                    });
            })
            ->exists();

        if ($conflictingSession) {
            return response()->json([
                'status' => 'error',
                'message' => 'Время сеанса пересекается с другим сеансом в этом зале'
            ], 422);
        }

        $session = Session::create($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Сеанс успешно создан',
            'data' => $session->load(['movie', 'hall'])
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
        $session = Session::with(['movie', 'hall', 'tickets'])
            ->find($id);

        if (!$session) {
            return response()->json([
                'status' => 'error',
                'message' => 'Сеанс не найден'
            ], 404);
        }
 
        $occupiedSeats = $session->tickets()
            ->where('status', '!=', 'cancelled')
            ->select('row_number', 'seat_number')
            ->get();

        $session->occupied_seats = $occupiedSeats;

        return response()->json([
            'status' => 'success',
            'data' => $session
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

        $session = Session::find($id);
        if (!$session) {
            return response()->json([
                'status' => 'error',
                'message' => 'Сеанс не найден'
            ], 404);
        }
 
        if ($session->start_time <= now()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Нельзя изменить начавшийся сеанс'
            ], 422);
        }

        $validated = $request->validate([
            'movie_id' => 'sometimes|required|exists:movies,id',
            'hall_id' => 'sometimes|required|exists:halls,id',
            'start_time' => 'sometimes|required|date|after:now',
            'price' => 'sometimes|required|numeric|min:0|max:10000',
            'is_active' => 'sometimes|boolean'
        ]);
 
        if (isset($validated['movie_id'])) {
            $movie = Movie::findOrFail($validated['movie_id']);
            if (!$movie->is_active) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Фильм неактивен'
                ], 422);
            }
        }

        if (isset($validated['hall_id'])) {
            $hall = Hall::findOrFail($validated['hall_id']);
            if (!$hall->is_active) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Зал неактивен'
                ], 422);
            }
        }
 
        if (isset($validated['start_time']) || isset($validated['hall_id'])) {
            $movieId = $validated['movie_id'] ?? $session->movie_id;
            $hallId = $validated['hall_id'] ?? $session->hall_id;
            $startTime = $validated['start_time'] ?? $session->start_time;

            $movie = Movie::findOrFail($movieId);
            $conflictingSession = Session::where('hall_id', $hallId)
                ->where('id', '!=', $session->id)
                ->where('is_active', true)
                ->where(function ($query) use ($startTime, $movie) {
                    $sessionEnd = date('Y-m-d H:i:s', strtotime($startTime . ' + ' . $movie->duration_minutes . ' minutes'));
                    $query->whereBetween('start_time', [$startTime, $sessionEnd])
                        ->orWhere(function ($q) use ($startTime, $movie) {
                            $sessionEnd = date('Y-m-d H:i:s', strtotime($startTime . ' + ' . $movie->duration_minutes . ' minutes'));
                            $q->where('start_time', '<', $startTime)
                                ->whereRaw('DATE_ADD(start_time, INTERVAL (SELECT duration_minutes FROM movies WHERE id = movie_id) MINUTE) > ?', [$startTime]);
                        });
                })
                ->exists();

            if ($conflictingSession) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Время сеанса пересекается с другим сеансом в этом зале'
                ], 422);
            }
        }

        $session->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Сеанс успешно обновлен',
            'data' => $session->load(['movie', 'hall'])
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

        $session = Session::find($id);
        if (!$session) {
            return response()->json([
                'status' => 'error',
                'message' => 'Сеанс не найден'
            ], 404);
        }

        if ($session->start_time <= now()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Нельзя удалить начавшийся сеанс'
            ], 422);
        }

        $hasPaidTickets = $session->tickets()
            ->where('status', 'paid')
            ->exists();

        if ($hasPaidTickets) {
            return response()->json([
                'status' => 'error',
                'message' => 'Невозможно удалить сеанс с купленными билетами'
            ], 422);
        }

        $session->update(['is_active' => false]);

        return response()->json([
            'status' => 'success',
            'message' => 'Сеанс успешно деактивирован'
        ]);
    }
}
