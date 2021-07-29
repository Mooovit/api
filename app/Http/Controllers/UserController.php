<?php

namespace App\Http\Controllers;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Actions\Fortify\CreateNewUser;

class UserController extends Controller
{
    public function register(Request $request)
    {
        $registerClass = new CreateNewUser();
        $registerClass->create($request->all());
    }

    public function authenticate(Request $request)
    {
        $credentials = $request->only('email', 'password');

        if (Auth::attempt($credentials)) {
            $user = Auth::user();

            if ($user->two_factor_secret) {
                throw new AuthenticationException("User has a two factor enabled");
            }

            $token = $user->createToken("Authentication token")->plainTextToken;
            return [
                "token" => explode('|', $token)[1],
                "user" => $user,
            ];
        }

        throw new AuthenticationException("Authentication error");
    }
}
