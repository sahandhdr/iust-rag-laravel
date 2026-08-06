<?php

namespace App\Http\Controllers\Api\v1\Document;

use App\Http\Controllers\Api\v1\ApiController;
use App\Http\Resources\Api\v1\Document\DocumentResource;
use App\Models\Document\Document;
use App\Models\Permission\Role;
use App\Traits\v1\ApiInfo;
use App\Traits\v1\Auditable;
use App\Utility\FileManagerRepo;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class DocumentController extends ApiController
{
    use ApiInfo, Auditable;
    /**
     * Display all docs.
     */
    public function index()
    {
        if (DB::table('documents')->count() > 0)
        {
            if (Auth::check())
            {
                $user = Auth::user();
                if ($user->hasAnyRole(['admin', 'developer']))
                {
                    $documents = Document::withTrashed()->get();
                }
            }
            return $this->successResponse(DocumentResource::collection($documents), 200);
        }
        return $this->errorResponse('document-notFound', 404);
    }

    public function uploadDoc(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "file_name_show" => 'required',
            "file" => 'required|file',
            'status' => 'nullable|in:draft,published,archived',
//            'docs'    => 'nullable|array|min:1',
//            'docs.*'  => 'required|integer|exists:docs,id',
//            'roles'          => 'nullable|array|min:1',
//            'roles.*'        => 'required|integer|exists:roles,id',
        ]);
//
//            ->after(function ($validator) {
//            $docs = $validator->getData()['docs'] ?? [];
//            $roles       = $validator->getData()['roles'] ?? [];
//
//            if (empty($docs) && empty($roles)) {
//                $validator->errors()->add(
//                    'access',
//                    'حداقل یکی از فیلدهای docs یا roles باید مقدار داشته باشد.'
//                );
//            }
//        });


        if ($validator->fails())
            return  $this->errorResponse($validator->errors(), 403);

        $originalName = $request->file->getClientOriginalName();
        $docUuid = (string) Str::uuid();
        $title = $docUuid.'_'.$originalName;
        $fileName = Carbon::now()->microsecond.'_'.$request->file->getClientOriginalName();
        $filePath = 'documents'.'/'.$title.'/'.'files';
        if ($request->file->storeAs($filePath, $fileName, 'public'))
        {
            $document = new Document();
            $document->file_name_show = $request->file_name_show;
            $document->file_name = $fileName;
            $document->path= $filePath.'/'.$fileName;
            $document->extension = $request->file->getClientOriginalExtension();
            $document->uploader_id = Auth::id();
            $document->doc_uuid = $docUuid;
            $document->status = $request->status ?? 'draft';
            $document->version = 1;
            if ($document->save())
            {
                $this->audit('document.upload', 'document', $document->id, [
                    'file_name' => $document->file_name,
                    'doc_uuid' => $document->doc_uuid,
                    'status' => $document->status,
                ]);

                $document->load(['roles', 'departments', 'permissions']);

                return $this->successResponse(new DocumentResource($document), 201, 'document-successfully-saved');
            }
            return $this->errorResponse('save-failed', 500);
        }
        return $this->errorResponse('upload-failed', 500);
    }

    public function getBase64($doc_id)
    {
        if ($this->checkExistsDocumentById($doc_id))
        {
            $user = Auth::user();
            if (!$user->hasAnyRole(['admin', 'developer']))
                return $this->errorResponse('forbidden', 403);

            $path = Document::where("id",$doc_id)->first()->path;
            $fileManager = new FileManagerRepo();
            if ($path != null)
                return $fileManager->getFileContentAsBase64($path,$disk='public');
            return $this->errorResponse("doc-notDownloaded", 500);
        }
        return $this->errorResponse("doc-notFound", 404);
    }

    public function get($doc_id)
    {
        if ($this->checkExistsDocumentById($doc_id))
        {
            $user = Auth::user();
            if (!$user->hasAnyRole(['admin', 'developer']))
                return $this->errorResponse('forbidden', 403);

            $path = Document::where("id",$doc_id)->first()->path;
            $fileManager = new FileManagerRepo();
            if ($path != null)
                return $fileManager->download($path);
            return $this->errorResponse("doc-notDownloaded", 500);
        }
        return $this->errorResponse("doc-notFound", 404);
    }

    public function search(Request $request)
    {
        if ($request->hasHeader("accept") && $request->header("accept") == "application/json" && $request->ajax())
        {
            $validator = Validator::make($request->all(), [
                "extension" => 'nullable',
                "file_name" => 'nullable',
                "file_name_show" => 'nullable',
                "doc_uuid" => 'nullable',
                "status" => 'nullable',
                "version" => 'nullable'
            ]);
            if ($validator->fails())
                return  response()->json(["status" => "validation-error", "errors" => $validator->errors()]);
            $query = Document::with('roles', 'permissions', 'departments')->select("*");
            if ($request->extension != null) $query->where("extension", "like", "%".$request->extension."%");
            if ($request->file_name != null) $query->where("file_name", "like", "%".$request->file_name."%");
            if ($request->file_name_show != null) $query->where("file_name_show", "like", "%".$request->file_name_show."%");
            if ($request->doc_uuid != null) $query->where("doc_uuid", "like", "%".$request->doc_uuid."%");
            if ($request->status != null) $query->where("status", "like", "%".$request->status."%");
            if ($request->version != null) $query->where("version", "like", "%".$request->version."%");

            return $query->exists() ?
                $this->successResponse(DocumentResource::collection($query->get()), 200, 'file-found') :
                $this->errorResponse('file-notFound', 404);
        }
        return  $this->errorResponse('refused', 500);
    }

    public function destroy($doc_id)
    {
        if ($this->checkExistsDocumentById($doc_id))
        {
            $file_path = Document::where("id", $doc_id)->first()->path;
            if (Storage::disk('public')->delete($file_path))
            {
                if (DB::table('documents')->where("id", $doc_id)->delete())
                    return $this->successResponse('',200, 'remove-success');
                return $this->errorResponse('path-delete-failed', 500);
            }
            return $this->errorResponse('remove-failed', 500);
        }
        return $this->errorResponse("doc-notFound", 404);
    }

    /**
     * Display the specified doc.
     */
    public function show(string $id)
    {
        if ($this->checkExistsDocumentById($id))
        {
            $user = Auth::user();
            if ($user->hasAnyRole(['admin', 'developer']))
            {
                $doc = Document::withTrashed()->with('uploader',
                    'roles',
                    'permissions', 'departments')->where('id', $id)->first();
            }
            elseif ($user->hasRole('public'))
            {
                $doc = Document::where('id', $id)->whereNull('deleted_at')->first();
            }
            if (!$doc)
                return $this->errorResponse('doc-notFound', 404);

            if (!$user->canAccessDocument($doc))
                return $this->errorResponse('forbidden', 403);

            return $this->successResponse(new DocumentResource($doc), 200);
        }
        return $this->errorResponse('doc-notFound', 404);
    }

    /**
     * Update the specified doc.
     */
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            "file_name" => 'nullable',
            "file_name_show" => 'nullable',
        ]);

        if ($validator->fails())
            return $this->errorResponse($validator->messages(), 422);

        if ($this->checkExistsDocumentById($id)) {
            $doc = Document::where('id', $id)->whereNull('deleted_at')->first();
            if ($request->file_name) $doc->file_name = $request->file_name;
            if ($request->file_name_show) $doc->file_name_show = $request->file_name_show;

            if($doc->save())
            {
                $this->audit('document.update', 'document', $doc->id, [
                    'doc_uuid' => $doc->doc_uuid,
                    'status' => $doc->status,
                    'version' => $doc->version,
                ]);
                return $this->successResponse(new DocumentResource($doc), 200, 'doc-successfully-updated');
            }
            return $this->errorResponse('save-failed', 500);
        }
        return $this->errorResponse('doc-notFound', 404);
    }

    public function publish($id)
    {
        return $this->changeStatus($id, 'published', 'document.publish');
    }

    public function archive($id)
    {
        return $this->changeStatus($id, 'archived', 'document.archive');
    }

    private function changeStatus($id, $status, $action)
    {
        if (!$this->checkExistsDocumentById($id)) {
            return $this->errorResponse('doc-notFound', 404);
        }

        $document = Document::where('id', $id)->whereNull('deleted_at')->first();
        if (!$document) {
            return $this->errorResponse('doc-notFound', 404);
        }

        $document->status = $status;

        if (!$document->save()) {
            return $this->errorResponse('save-failed', 500);
        }

        // قانون دامنه اختیاری: اگر published شد و هیچ roleای نداشت → public
        if ($status === 'published' && $document->roles()->count() == 0) {
            $publicRoleId = DB::table('roles')->where('title_en', 'public')->value('id');
            if ($publicRoleId) {
                $document->roles()->sync([$publicRoleId]);
            }
        }

        $this->audit($action, 'document', $document->id, [
            'doc_uuid' => $document->doc_uuid,
            'status' => $status,
        ]);

        $document->load(['roles', 'departments', 'permissions']);

        return $this->successResponse(new DocumentResource($document), 200, 'document-status-updated');
    }

    private function accessibleDocumentsQuery($user)
    {
        $roleIds = $user->roles()->pluck('roles.id');
        $deptIds = $user->departments()->pluck('departments.id');

        return Document::query()
            ->whereNull('deleted_at')
            ->where('status', 'published')
            ->where(function ($q) use ($roleIds, $deptIds) {
                $q->whereHas('roles', function ($rq) {
                    $rq->where('title_en', 'public');
                });

                if ($roleIds->count() > 0) {
                    $q->orWhereHas('roles', function ($rq) use ($roleIds) {
                        $rq->whereIn('roles.id', $roleIds);
                    });
                }

                if ($deptIds->count() > 0) {
                    $q->orWhereHas('departments', function ($dq) use ($deptIds) {
                        $dq->whereIn('departments.id', $deptIds);
                    });
                }
            });
    }

    private function checkExistsDocumentById($id)
    {
        return DB::table('documents')->where('id', $id)->exists();
    }
}
