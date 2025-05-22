<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\Session;
use App\Models\Hall;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

class TicketController extends Controller
{
    /**
     *  
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();
        $query = Ticket::with(['session.movie', 'session.hall']);
 
        if ($user->role !== 'admin') {
            $query->where('user_id', $user->id);
        }

        $tickets = $query->orderBy('created_at', 'desc')
            ->paginate(10);

        return response()->json([
            'status' => 'success',
            'data' => $tickets
        ]);
    }

    /**
     *  
     * @param  \Illuminate\Http\Request   
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Пользователь не аутентифицирован'
            ], 401);
        }
        
        $validated = $request->validate([
            'session_id' => 'required|exists:sessions,id',
            'row_number' => 'required|integer|min:1',
            'seat_number' => 'required|integer|min:1',
        ]);
 
        $session = Session::with('hall')->findOrFail($validated['session_id']);
        
        if (!$session->is_active) {
            return response()->json([
                'status' => 'error',
                'message' => 'Сеанс неактивен'
            ], 422);
        }

        if ($session->start_time <= now()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Нельзя забронировать билет на начавшийся сеанс'
            ], 422);
        }

        if ($validated['row_number'] > $session->hall->rows || 
            $validated['seat_number'] > $session->hall->seats_per_row) {
            return response()->json([
                'status' => 'error',
                'message' => 'Указанное место не существует в зале'
            ], 422);
        }

        $isSeatOccupied = Ticket::where('session_id', $validated['session_id'])
            ->where('row_number', $validated['row_number'])
            ->where('seat_number', $validated['seat_number'])
            ->where('status', '!=', 'cancelled')
            ->exists();

        if ($isSeatOccupied) {
            return response()->json([
                'status' => 'error',
                'message' => 'Место уже занято'
            ], 422);
        } 

        try {
            DB::beginTransaction();

            // Создаем билет
            $ticket = Ticket::create([
                'session_id' => $validated['session_id'],
                'user_id' => $user->id,
                'row_number' => $validated['row_number'],
                'seat_number' => $validated['seat_number'],
                'price' => $session->price,
                'status' => 'reserved'
            ]);
 
            $ticket->expires_at = now()->addMinutes(30);
            $ticket->save();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Билет успешно забронирован',
                'data' => $ticket->load(['session.movie', 'session.hall'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
             
            \Log::error('Ошибка бронирования билета: ' . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => 'Произошла ошибка при бронировании билета'
            ], 500);
        }
    }

    /**
     *  
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id): JsonResponse
    {
        $user = Auth::user();
        $ticket = Ticket::with(['session.movie', 'session.hall'])
            ->find($id);

        if (!$ticket) {
            return response()->json([
                'status' => 'error',
                'message' => 'Билет не найден'
            ], 404);
        }
 
        if ($user->role !== 'admin' && $ticket->user_id !== $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Доступ запрещён'
            ], 403);
        }

        return response()->json([
            'status' => 'success',
            'data' => $ticket
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
        $ticket = Ticket::find($id);

        if (!$ticket) {
            return response()->json([
                'status' => 'error',
                'message' => 'Билет не найден'
            ], 404);
        }
 
        if ($user->role !== 'admin' && $ticket->user_id !== $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Доступ запрещён'
            ], 403);
        }

        $validated = $request->validate([
            'status' => 'required|in:paid,cancelled'
        ]);
 
        if ($ticket->status === 'paid') {
            return response()->json([
                'status' => 'error',
                'message' => 'Билет уже оплачен'
            ], 422);
        }

        if ($ticket->status === 'cancelled') {
            return response()->json([
                'status' => 'error',
                'message' => 'Билет уже отменен'
            ], 422);
        }
 
        if ($ticket->status === 'reserved' && $ticket->expires_at < now()) {
            $ticket->update(['status' => 'cancelled']);
            return response()->json([
                'status' => 'error',
                'message' => 'Время бронирования истекло'
            ], 422);
        }
 
        if ($ticket->session->start_time <= now()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Нельзя изменить статус билета на начавшийся сеанс'
            ], 422);
        }

        try {
            DB::beginTransaction();

            $ticket->update($validated);
 
            if ($validated['status'] === 'paid') {
                $ticket->expires_at = null;
                $ticket->save();
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Статус билета успешно обновлен',
                'data' => $ticket->load(['session.movie', 'session.hall'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Произошла ошибка при обновлении статуса билета'
            ], 500);
        }
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
        $ticket = Ticket::find($id);

        if (!$ticket) {
            return response()->json([
                'status' => 'error',
                'message' => 'Билет не найден'
            ], 404);
        }

        if ($user->role !== 'admin' && $ticket->user_id !== $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Доступ запрещён'
            ], 403);
        }

        if ($ticket->status === 'paid') {
            return response()->json([
                'status' => 'error',
                'message' => 'Нельзя удалить оплаченный билет'
            ], 422);
        }

        if ($ticket->session->start_time <= now()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Нельзя удалить билет на начавшийся сеанс'
            ], 422);
        }

        try {
            DB::beginTransaction();

            $ticket->update(['status' => 'cancelled']);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Билет успешно отменен'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Произошла ошибка при отмене билета'
            ], 500);
        }
    }

    /**
     *  
     *
     * @param  int  $sessionId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAvailableSeats($sessionId): JsonResponse
    {
        $session = Session::with('hall')->find($sessionId);

        if (!$session) {
            return response()->json([
                'status' => 'error',
                'message' => 'Сеанс не найден'
            ], 404);
        }

        if (!$session->is_active) {
            return response()->json([
                'status' => 'error',
                'message' => 'Сеанс неактивен'
            ], 422);
        }

        if ($session->start_time <= now()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Сеанс уже начался'
            ], 422);
        }
 
        $occupiedSeats = Ticket::where('session_id', $sessionId)
            ->where('status', '!=', 'cancelled')
            ->select('row_number', 'seat_number')
            ->get();
 
        $allSeats = [];
        for ($row = 1; $row <= $session->hall->rows; $row++) {
            for ($seat = 1; $seat <= $session->hall->seats_per_row; $seat++) {
                $allSeats[] = [
                    'row_number' => $row,
                    'seat_number' => $seat,
                    'is_available' => !$occupiedSeats->contains(function ($item) use ($row, $seat) {
                        return $item->row_number === $row && $item->seat_number === $seat;
                    })
                ];
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'session' => $session->load('movie'),
                'hall' => $session->hall,
                'seats' => $allSeats
            ]
        ]);
    }
} 