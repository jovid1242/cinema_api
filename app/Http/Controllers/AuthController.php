<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api', ['except' => ['login', 'register']]);
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        if (!$token = auth('api')->attempt($validator->validated())) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $this->createNewToken($token);
    }

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|between:2,100',
            'email' => 'required|string|email|max:100|unique:users',
            'password' => 'required|string|min:6',
            'role' => 'string|in:user,admin'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role ?? 'user'
        ]);

        $token = auth('api')->attempt([
            'email' => $request->email,
            'password' => $request->password
        ]);

        return $this->createNewToken($token);
    }

    public function logout()
    {
        auth('api')->logout();
        return response()->json(['message' => 'User successfully logged out']);
    }

    public function getMe()
    {
        return response()->json(auth('api')->user());
    }
    
    /**
     * 
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUsers()
    { 
        $currentUser = auth('api')->user();
        if ($currentUser->role !== 'admin') {
            return response()->json(['error' => 'Доступ запрещен. Требуются права администратора.'], 403);
        }
        
        $users = User::all();
        
        return response()->json([
            'status' => 'success',
            'data' => $users
        ]);
    }
    
    /**
     *  
     *
     * @param  Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateUser(Request $request, $id)
    { 
        $currentUser = auth('api')->user();
        if ($currentUser->role !== 'admin') {
            return response()->json(['error' => 'Доступ запрещен. Требуются права администратора.'], 403);
        }
         
        $user = User::find($id);
        if (!$user) {
            return response()->json(['error' => 'Пользователь не найден'], 404);
        }
         
        $validator = Validator::make($request->all(), [
            'name' => 'string|between:2,100',
            'email' => 'string|email|max:100|unique:users,email,'.$id,
            'role' => 'string|in:user,admin'
        ]);
        
        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        } 
        if ($request->has('name')) {
            $user->name = $request->name;
        }
        
        if ($request->has('email')) {
            $user->email = $request->email;
        }
        
        if ($request->has('role')) {
            $user->role = $request->role;
        }
        
        $user->save();
        
        return response()->json([
            'status' => 'success',
            'message' => 'Данные пользователя обновлены',
            'data' => $user
        ]);
    }

    /**
     *  
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteUser($id)
    { 
        $currentUser = auth('api')->user();
        if ($currentUser->role !== 'admin') {
            return response()->json(['error' => 'Доступ запрещен. Требуются права администратора.'], 403);
        }
         
        $user = User::find($id);
        if (!$user) {
            return response()->json(['error' => 'Пользователь не найден'], 404);
        }
         
        if ($currentUser->id == $id) {
            return response()->json(['error' => 'Нельзя удалить собственный аккаунт'], 400);
        }
         
        $user->delete();
        
        return response()->json([
            'status' => 'success',
            'message' => 'Пользователь успешно удален'
        ]);
    }

    protected function createNewToken($token)
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => auth('api')->user()
        ]);
    }
} 