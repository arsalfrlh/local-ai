<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthApiController extends Controller
{
    public function login(Request $request){
        $validator = Validator::make($request->all(),[
            'email' => 'required|email',
            'password' => 'required'
        ]);

        if($validator->fails()){
            return response()->json(['message' => $validator->errors()->all(), 'success' => false], 422);
        }

        $user = User::where('email', $request->email)->first();
        if(!$user){
            return response()->json(['message' => "Email tidak terdaftar di sistem", 'success' => false], 404);
        }

        if(Hash::check($request->password, $user->password)){
            $data = [
                'name' => $user->name,
                'token' => $user->createToken('auth-token')->plainTextToken
            ];

            return response()->json(['message' => "Login berhasil", 'success' => true, 'data' => $data], 200);
        }else{
            return response()->json(['message' => "Password anda salah", 'success' => false], 200);
        }
    }

    public function register(Request $request){
        $validator = Validator::make($request->all(),[
            'name' => 'required',
            'email' => 'required|email|unique:users',
            'password' => 'required'
        ]);

        if($validator->fails()){
            return response()->json(['message' => $validator->errors()->all(), 'success' => false], 422);
        }

        $user = User::create($request->only(['name','email','password']));
        $data = [
            'name' => $user->name,
            'token' => $user->createToken('auth-token')->plainTextToken
        ];

        return response()->json(['message' => "Register berhasil", 'success' => true, 'data' => $data], 201);
    }
}
