<?php

namespace App\Http\Controllers\Api\v1\Chat;

use App\Http\Controllers\Api\v1\ApiController;
use App\Http\Resources\Api\v1\Chat\ChatMessageFileResource;
use App\Models\Chat\ChatMessageFile;
use App\Utility\FileManagerRepo;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ChatMessageFileController extends ApiController
{
    public function uploadFile(Request $request, $message_id)
    {
        $validator = Validator::make($request->all(), [
            "file" => 'file'
        ]);

        if ($validator->fails())
            return  $this->errorResponse($validator->errors(), 403);

        $fileName = Carbon::now()->microsecond.'_'.$request->file->getClientOriginalName();
        $filePath = 'messages'.'/'.$message_id.'/'.'files';
        if ($request->file->storeAs($filePath, $fileName, 'public'))
        {
            $file = new ChatMessageFile();

            $file->path=$filePath.'/'.$fileName;
            $file->extension = $request->file->getClientOriginalExtension();
            $file->file_name = $request->file->getClientOriginalName();
            $file->message_id= $message_id;
            if ($file->save())
                return $this->successResponse(new ChatMessageFileResource($file), 200);
            return $this->errorResponse('save-failed', 500);
        }
        return $this->errorResponse('upload-failed', 500);
    }

    public function getBase64($file_id)
    {
        if (ChatMessageFile::where("id",$file_id)->exists())
        {
            $path = ChatMessageFile::where("id",$file_id)->first()->path;
            $fileManager = new FileManagerRepo();
            if ($path != null)
                return $fileManager->getFileContentAsBase64($path,$disk='public');
            return $this->errorResponse("file-notDownloaded", 500);
        }
        return $this->errorResponse("file-notFound", 404);
    }

    public function get($file_id)
    {
        if (ChatMessageFile::where("id",$file_id)->exists())
        {
            $path = ChatMessageFile::where("id",$file_id)->first()->path;
            $fileManager = new FileManagerRepo();
            if ($path != null)
                return $fileManager->download($path);
            return $this->errorResponse("file-notDownloaded", 500);
        }
        return $this->errorResponse("file-notFound", 404);
    }
    public function searchFile(Request $request)
    {
        if ($request->hasHeader("accept") && $request->header("accept") == "application/json" && $request->ajax())
        {
            $validator = Validator::make($request->all(), [
                "path" => 'nullable',
                "extension" => 'nullable',
                "file_name" => 'nullable',
            ]);
            if ($validator->fails())
                return  response()->json(["status" => "validation-error", "errors" => $validator->errors()]);
            $query = ChatMessageFile::with('message')->select("*");
            if ($request->path != null) $query->where("path", "like", "%".$request->path."%");
            if ($request->extension != null) $query->where("extension", "like", "%".$request->extension."%");
            if ($request->file_name != null) $query->where("file_name", "like", "%".$request->file_name."%");

            return $query->exists() ?
                $this->successResponse(ChatMessageFileResource::collection($query->get()), 200, 'file-found') :
                $this->errorResponse('file-notFound', 404);
        }
        return  $this->errorResponse('refused', 500);
    }
//    public function destroy($file_id)
//    {
//        if (ChatMessageFile::where("id", $file_id)->exists())
//        {
//            $file_path = ChatMessageFile::where("id", $file_id)->first()->path;
//            if (Storage::disk('public')->delete($file_path))
//            {
//                if (ChatMessageFile::where("id", $file_id)->delete())
//                    return $this->successResponse('',200, 'remove-success');
//                return $this->errorResponse('path-delete-failed', 500);
//            }
//            return $this->errorResponse('remove-failed', 500);
//        }
//        return $this->errorResponse("file-notFound", 404);
//    }
}
