<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|min:2',
            'email' => 'required|email',
            'phone' => 'nullable|string',
            'password' => 'required|string|min:6',
        ]);

        return $this->auth->register($data);
    }

    public function registerAdvertiser(Request $request)
    {
        return $this->auth->register($request->validate([
            'name' => 'required|string',
            'email' => 'required|email',
            'phone' => 'nullable|string',
            'password' => 'required|string|min:6',
        ]), 'ADVERTISER');
    }

    public function registerDriver(Request $request)
    {
        return $this->auth->register($request->validate([
            'name' => 'required|string',
            'email' => 'required|email',
            'phone' => 'nullable|string',
            'password' => 'required|string|min:6',
            'licenseNo' => 'nullable|string',
        ]), 'DRIVER');
    }

    public function login(Request $request)
    {
        return $this->auth->login($request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]));
    }

    public function driverLogin(Request $request)
    {
        return $this->auth->login($request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]), ['DRIVER']);
    }

    public function advertiserLogin(Request $request)
    {
        return $this->auth->login($request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]), ['ADVERTISER']);
    }

    public function operatorLogin(Request $request)
    {
        return $this->auth->login($request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]), ['FLEET_OWNER', 'DISTRICT_HEAD', 'STATE_HEAD', 'FRANCHISE', 'CORPORATE', 'ADMIN', 'SUPER_ADMIN']);
    }

    public function requestOtp(Request $request)
    {
        return $this->auth->requestOtp($request->validate(['phone' => 'required|string'])['phone']);
    }

    public function verifyOtp(Request $request)
    {
        $data = $request->validate(['phone' => 'required|string', 'code' => 'required|string']);

        return $this->auth->verifyOtp($data['phone'], $data['code']);
    }

    public function verifyDriverOtp(Request $request)
    {
        $data = $request->validate(['phone' => 'required|string', 'code' => 'required|string']);

        return $this->auth->verifyOtp($data['phone'], $data['code'], 'DRIVER');
    }

    public function me(Request $request)
    {
        return $this->auth->present($request->user());
    }

    public function profile(Request $request)
    {
        $request->user()->update($request->only('name', 'phone', 'emergency_name', 'emergency_phone'));

        return $this->auth->present($request->user()->fresh());
    }

    public function location(Request $request)
    {
        $data = $request->validate(['lat' => 'required|numeric', 'lng' => 'required|numeric', 'address' => 'nullable|string']);
        $request->user()->update([
            'last_lat' => $data['lat'],
            'last_lng' => $data['lng'],
            'last_address' => $data['address'] ?? null,
            'location_updated_at' => now(),
        ]);

        return ['ok' => true];
    }
}
