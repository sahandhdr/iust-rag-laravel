<?php

namespace App\Http\Controllers\Api\v1\User;

use App\Http\Controllers\Api\v1\ApiController;
use App\Http\Resources\Api\v1\User\UserResource;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class UserController extends ApiController
{
    public function index()
    {
        if (User::all()->count() > 0) {
            $users = User::with('roles', 'positions')->get();
            return $this->successResponse(UserResource::collection($users), 200);
        }
        return $this->errorResponse('user-notFound', 404);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "name" => 'required',
            "surname" => 'required',
            'title' => 'required',
            "password" => 'required',
            "email" => 'required',
            "mobile" => 'required',
            "birthday" => 'nullable',
            "gender" => 'required',
            "ncode" => 'required',
            "bio" => 'nullable',
        ]);
        if ($validator->fails())
            return response()->json(["status" => "validation-error", "errors" => $validator->errors()]);

        if (!(User::where("mobile", $request->mobile)->exists())) {
            $user = new User();
            $user->name = $request->name;
            $user->surname = $request->surname;
            $user->title = $request->title;
            $user->gender = $request->gender;
            $user->birthday = $request->birthday;
            $user->password = Hash::make($request->password);
            $user->email = $request->email;
            $user->mobile = $request->mobile;
            $user->ncode = $request->ncode;
            $user->bio = $request->bio;
            $user->type = "haghighi";


            if ($user->save())
                return $this->successResponse(new UserResource($user->refresh()), 200);
            return $this->errorResponse("save-failed", 500);
        }
        return $this->errorResponse("user-exists", 500);
    }

    /**
     * Display the specified resource.
     */
    public function show($user_id)
    {
        if (User::where("id", $user_id)->exists()) {
            $user = User::where("id", $user_id)->with('roles', 'positions')->first();
            return $this->successResponse(new UserResource($user), 200);
        }
        return $this->errorResponse('user-notFound', 404);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $user_id)
    {
        if (User::where("id", $user_id)->exists()) {
            $validator = Validator::make($request->all(), [
                "name" => 'nullable',
                "surname" => 'nullable',
                'title' => 'nullable',
                "password" => 'nullable',
                "email" => 'nullable',
                "mobile" => 'nullable',
                "birthday" => 'nullable',
                "gender" => 'nullable',
                "ncode" => 'nullable',
                "bio" => 'nullable'
            ]);
            if ($validator->fails())
                return response()->json(["status" => "validation-error", "errors" => $validator->errors()]);

            $user = User::where("id", $user_id)->first();
            if ($request->name != null) $user->name = $request->name;
            if ($request->surname != null) $user->surname = $request->surname;
            if ($request->title != null) $user->title = $request->title;
            if ($request->password != null) $user->password = Hash::make($request->password);
            if ($request->email != null) $user->email = $request->email;
            if ($request->mobile != null) $user->mobile = $request->mobile;
            if ($request->birthday != null) $user->birthday = $request->birthday;
            if ($request->gender != null) $user->gender = $request->gender;
            if ($request->ncode != null) $user->ncode = $request->ncode;
            if ($request->bio != null) $user->bio = $request->bio;
            if ($user->save())
                return $this->successResponse(new UserResource($user), 200);
            return $this->errorResponse('save-failed', 200);
        }
        return $this->errorResponse("user-notExists", 500);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($user_id)
    {
        if (User::where("id", $user_id)->exists())
        {
            if ($this->removeUserPicFromStorage($user_id))
            {
                if (User::where("id", $user_id)->first()->delete())
                    return $this->successResponse('', 200, 'user-deleted');
                return $this->errorResponse('delete-failed', 500);
            }
            return $this->errorResponse('remove-from-storage-failed', 500);
        }
        return $this->errorResponse('user-notFound', 404);
    }


    public function uploadPic(Request $request)
    {
        $user_id = Auth::id();
        if (User::where("id", $user_id)->exists())
        {
            $validator = Validator::make($request->all(), [
                "pic" => 'required|file',

            ]);
            if ($validator->fails())
                return response()->json(["status" => "validation-error", "errors" => $validator->errors()]);

            $id = User::where("id", $user_id)->first()->id;
            $picName = $id.'_'.Carbon::now()->microsecond . '_' . $request->pic->getClientOriginalName();
            $picPath = 'users'.'/'.$id.'/'.'profile_pic';
            if ($request->pic->storeAs($picPath, $picName, 'public'))
            {
                $user = User::where("id", $user_id)->first();
                $this->delete_pic($user->pic_path);
//            if (!is_null($user->pic_path)) Storage::delete('public'.'/'.$user->pic_path);
                $user->pic_path = $picPath . '/' . $picName;
                if ($user->save())
                    return $this->successResponse(new UserResource($user), 200);
                return $this->errorResponse('save-failed', 500);
            }
            return $this->errorResponse('upload-failed', 500);
        }
        return $this->errorResponse('user-notExists', 400);
    }

    public function removeUserPicFromStorage($user_id)
    {
        if (User::where("id", $user_id)->exists())
        {
            $user = User::where("id", $user_id)->first();
            $pic_path = $user->pic_path;
            if ($this->delete_pic($pic_path)['status'] == 'success')
            {
                $user->pic_path = null;
                if ($user->save())
                    return $this->successResponse(new UserResource($user->refresh()), 200);
                return $this->errorResponse('save-failed', 500);
            }
            return $this->errorResponse('remove-failed', 500);
        }
        return $this->errorResponse('user-notFound', 404);
    }

    public function searchUser(Request $request)
    {
        if ($request->hasHeader("accept") && $request->header("accept") == "application/json" && $request->ajax())
        {
            $validator = Validator::make($request->all(), [
                "name" => 'nullable',
                "surname" => 'nullable',
                'title' => 'nullable',
                "gender" => 'nullable',
                "birthday" => 'nullable',
                "email" => 'nullable',
                "email_verified_at" => 'nullable',
                "mobile" => 'nullable',
                "ncode" => 'nullable',
            ]);
            if ($validator->fails())
                return  response()->json(["status" => "validation-error", "errors" => $validator->errors()]);

            $query = User::select("*");
            if ($request->name != null) $query->where("name", "like", "%".$request->name."%");
            if ($request->surname != null) $query->where("surname", "like", "%".$request->surname."%");
            if ($request->title != null) $query->where("title", "like", "%".$request->title."%");
            if ($request->gender != null) $query->where("gender", "like", "%".$request->gender."%");
            if ($request->birthday != null) $query->where("birthday", "like", "%".$request->birthday."%");
            if ($request->email != null) $query->where("email", "like", "%".$request->email."%");
            if ($request->email_verified_at != null) $query->where("email_verified_at", "=", "%".$request->email_verified_at."%");
            if ($request->mobile != null) $query->where("mobile", "like", "%".$request->mobile."%");
            if ($request->ncode != null) $query->where("ncode", "like", "%".$request->ncode."%");

            return $query->exists() ?
                $this->successResponse(UserResource::collection($query->get()), 200, 'user-found') :
                $this->errorResponse('user-notFound', 404);
        }
        return  $this->errorResponse('refused', 500);
    }

    public function delete_pic($pic_path)
    {
        if (!is_null($pic_path)) Storage::delete('public'.'/'.$pic_path);
        return ['status' => 'success'];
    }

    public function changePassword(Request $request, $user_id)
    {
        if(!User::where('id', $user_id)->exists())
            return $this->errorResponse('user-notFound', 404);

        $validator = Validator::make($request->all(), [
            "password" => 'required',
        ]);

        if ($validator->fails())
            return response()->json(["status" => "validation-error", "errors" => $validator->errors()]);

        $user = User::where('id', $user_id)->first();
        $user->password = Hash::make($request->password);
        return $user->save() ?
            $this->successResponse($user, 200, 'password-changed') :
            $this->errorResponse('save-failed', 500);
    }

    public function checkPermissionExists($user_id, $permission)
    {
        $user = User::where('id', $user_id)->first();
        return response()->json(['result' => $user->hasPermission($permission)]);
    }
}
