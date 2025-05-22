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
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();
        $query = Ticket::with(['session.movie', 'session.hall']);

        // Администраторы видят все билеты, пользователи только свои
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
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        
        $validated = $request->validate([
            'session_id' => 'required|exists:sessions,id',
            'row_number' => 'required|integer|min:1',
            'seat_number' => 'required|integer|min:1',
        ]);

        // Проверяем существование и активность сеанса
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

        // Проверяем существование места в зале
        if ($validated['row_number'] > $session->hall->rows || 
            $validated['seat_number'] > $session->hall->seats_per_row) {
            return response()->json([
                'status' => 'error',
                'message' => 'Указанное место не существует в зале'
            ], 422);
        }

        // Проверяем, не занято ли место
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

        // Проверяем, нет ли у пользователя других активных броней на этот сеанс
        $hasActiveReservation = Ticket::where('session_id', $validated['session_id'])
            ->where('user_id', $user->id)
            ->where('status', 'reserved')
            ->exists();

        if ($hasActiveReservation) {
            return response()->json([
                'status' => 'error',
                'message' => 'У вас уже есть активная бронь на этот сеанс'
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

            // Устанавливаем время жизни брони (30 минут)
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
            return response()->json([
                'status' => 'error',
                'message' => 'Произошла ошибка при бронировании билета'
            ], 500);
        }
    }

    /**
     * Display the specified resource.
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

        // Проверяем права доступа
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
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
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

        // Проверяем права доступа
        if ($user->role !== 'admin' && $ticket->user_id !== $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Доступ запрещён'
            ], 403);
        }

        $validated = $request->validate([
            'status' => 'required|in:paid,cancelled'
        ]);

        // Проверяем текущий статус билета
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

        // Проверяем, не истекло ли время брони
        if ($ticket->status === 'reserved' && $ticket->expires_at < now()) {
            $ticket->update(['status' => 'cancelled']);
            return response()->json([
                'status' => 'error',
                'message' => 'Время бронирования истекло'
            ], 422);
        }

        // Проверяем, не начался ли сеанс
        if ($ticket->session->start_time <= now()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Нельзя изменить статус билета на начавшийся сеанс'
            ], 422);
        }

        try {
            DB::beginTransaction();

            $ticket->update($validated);

            // Если билет оплачен, сбрасываем время истечения
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
     * Remove the specified resource from storage.
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

        // Проверяем права доступа
        if ($user->role !== 'admin' && $ticket->user_id !== $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Доступ запрещён'
            ], 403);
        }

        // Проверяем статус билета
        if ($ticket->status === 'paid') {
            return response()->json([
                'status' => 'error',
                'message' => 'Нельзя удалить оплаченный билет'
            ], 422);
        }

        // Проверяем, не начался ли сеанс
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
     * Получить список свободных мест для сеанса.
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

        // Получаем все занятые места
        $occupiedSeats = Ticket::where('session_id', $sessionId)
            ->where('status', '!=', 'cancelled')
            ->select('row_number', 'seat_number')
            ->get();

        // Формируем массив всех мест в зале
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