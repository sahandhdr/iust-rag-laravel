<?php

namespace App\Http\Controllers\Api\v1\Auth;

use App\Http\Controllers\Api\v1\ApiController;
use App\Http\Resources\Api\v1\User\UserResource;
use App\Models\User;
use App\Traits\v1\ApiInfo;
use App\Traits\v1\Auditable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends ApiController
{
    use ApiInfo, Auditable;

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required',
            'surname'  => 'required',
            'password' => 'required',
            'email'    => 'required|unique:users',
            'username' => 'required|unique:users',
            'ncode'    => 'required',
            'bio'      => 'nullable',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors(), 422);
        }

        if (
            !User::where('username', $request->username)->exists()
            && !User::where('email', $request->email)->exists()
        ) {
            $user = new User();
            $user->name = $request->name;
            $user->surname = $request->surname;
            $user->username = $request->username;
            $user->password = Hash::make($request->password);
            $user->phone = $request->phone;
            $user->email = $request->email;
            $user->ncode = $request->ncode;
            $user->bio = $request->bio;

            if ($user->save()) {
                $token = $user->createToken('sanctum')->plainTextToken;

                $this->audit(
                    'auth.register',
                    'user',
                    $user->id,
                    [
                        'username' => $user->username,
                        'email'    => $user->email,
                    ],
                    $user->id
                );

                return $this->successResponse([
                    'user'  => new UserResource($user->refresh()),
                    'token' => $token,
                ], 200);
            }

            return $this->errorResponse('save-failed', 500);
        }

        return $this->errorResponse('user-exists', 500);
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->messages(), 422);
        }

        $user = User::with('roles')->where('username', $request->username)->first();

        if (!$user) {
            $this->audit(
                'auth.login_failed',
                'user',
                null,
                [
                    'username' => $request->username,
                    'reason'   => 'user-notFound',
                ],
                null
            );

            return $this->errorResponse('user-notFound', 404);
        }

        if (!Hash::check($request->password, $user->password)) {
            $this->audit(
                'auth.login_failed',
                'user',
                $user->id,
                [
                    'username' => $request->username,
                    'reason'   => 'password-incorrect',
                ],
                $user->id
            );

            return $this->errorResponse('password-incorrect', 401);
        }

        $token = $user->createToken('passport')->plainTextToken;

        if (!isset($token)) {
            $this->audit(
                'auth.login_failed',
                'user',
                $user->id,
                [
                    'username' => $request->username,
                    'reason'   => 'token-notFound',
                ],
                $user->id
            );

            return $this->errorResponse('token-notFound', 401);
        }

        $this->audit(
            'auth.login',
            'user',
            $user->id,
            [
                'username' => $user->username,
            ],
            $user->id
        );

        return $this->successResponse([
            'user'  => $user,
            'token' => $token,
        ], 200);
    }

    public function logout()
    {
        if (!auth()->user()) {
            return $this->errorResponse('Unauthenticated', 401);
        }

        $user = auth()->user();

        if ($user->currentAccessToken()->delete()) {
            $this->audit(
                'auth.logout',
                'user',
                $user->id,
                [
                    'username' => $user->username ?? null,
                ],
                $user->id
            );

            return $this->successResponse('', 200, 'logged-out');
        }

        return $this->errorResponse('logout-failed', 500);
    }

    public function changeUserPassword(Request $request, $user_id)
    {
        if (
            $request->hasHeader('accept')
            && $request->header('accept') == 'application/json'
            && $request->ajax()
        ) {
            $validator = Validator::make($request->all(), [
                'oldPassword'     => 'required',
                'newPassword'     => 'required||min:8',
                'confirmPassword' => 'required||min:8',
            ]);
            if ($validator->fails()) {
                return $this->errorResponse($validator->errors(), 500);
            }

            if ($request->newPassword == $request->confirmPassword) {
                if (strlen($request->newPassword) >= 8) {
                    if (DB::table('users')->where('id', $user_id)->exists()) {
                        $user = User::where('id', $user_id)->first();
                        if (Hash::check($request->oldPassword, $user->password)) {
                            if ($request->newPassword != null) {
                                $user->password = Hash::make($request->newPassword);
                                if ($user->save()) {
                                    $this->audit(
                                        'auth.password_change',
                                        'user',
                                        $user->id,
                                        [],
                                        $user->id
                                    );

                                    return $this->successResponse($user, 200, 'password-changedSuccessfully');
                                }

                                return $this->errorResponse('password-changingFailed', 500);
                            }

                            return $this->errorResponse('password-null', 500);
                        }

                        return $this->errorResponse('password-notmatch', 500);
                    }

                    return $this->errorResponse('user-notFound', 500);
                }

                return $this->errorResponse('newPassword-char-less-than-8', 500);
            }

            return $this->errorResponse('newpassrds-notMatch', 500);
        }

        return $this->errorResponse('refused', 400);
    }

    public function searchUser(Request $request)
    {
        if (
            $request->hasHeader('accept')
            && $request->header('accept') == 'application/json'
            && $request->ajax()
        ) {
            $validator = Validator::make($request->all(), [
                'name'              => 'nullable',
                'surname'           => 'nullable',
                'gender'            => 'nullable',
                'birthday'          => 'nullable',
                'type'              => 'nullable',
                'username'          => 'nullable',
                'email'             => 'nullable',
                'email_verified_at' => 'nullable',
                'mobile'            => 'nullable',
            ]);
            if ($validator->fails()) {
                return response()->json(['status' => 'validation-error', 'errors' => $validator->errors()]);
            }

            $query = User::with(['discounts'])->select('*');
            if ($request->name != null) {
                $query->where('name', 'like', '%' . $request->name . '%');
            }
            if ($request->surname != null) {
                $query->where('surname', 'like', '%' . $request->surname . '%');
            }
            if ($request->gender != null) {
                $query->where('gender', 'like', '%' . $request->gender . '%');
            }
            if ($request->birthday != null) {
                $query->where('birthday', 'like', '%' . $request->birthday . '%');
            }
            if ($request->type != null) {
                $query->where('type', 'like', '%' . $request->type . '%');
            }
            if ($request->username != null) {
                $query->where('username', 'like', '%' . $request->username . '%');
            }
            if ($request->email != null) {
                $query->where('email', 'like', '%' . $request->email . '%');
            }
            if ($request->email_verified_at != null) {
                $query->where('email_verified_at', '=', '%' . $request->email_verified_at . '%');
            }
            if ($request->mobile != null) {
                $query->where('mobile', 'like', '%' . $request->mobile . '%');
            }

            return $query->exists()
                ? $this->successResponse(UserResource::collection($query->get()), 200, 'user(s)-found')
                : $this->errorResponse('user-notFound', 404);
        }

        return $this->errorResponse('refused', 500);
    }

    public function verifyToken(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return $this->errorResponse('unauthenticated', 401);
        }

        $info = $this->getUserAclInfo($user->id);

        if (isset($info['code']) && $info['code'] === 404) {
            return $this->errorResponse('user-notFound', 404);
        }

        if (empty($info['roles'])) {
            return $this->errorResponse('user-has-no-role', 403);
        }

        return $this->successResponse([
            'user_id'     => $info['user_id'],
            'username'    => $info['username'],
            'roles'       => $info['roles'],
            'departments' => $info['departments'],
            'permissions' => $info['permissions'],
        ], 200, 'token-valid');
    }
}
