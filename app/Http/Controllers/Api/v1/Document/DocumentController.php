<?php

namespace App\Http\Controllers\Api\v1\Document;

use App\Http\Controllers\Api\v1\ApiController;
use App\Http\Resources\Api\v1\Document\DocumentResource;
use App\Models\Document\Document;
use App\Traits\v1\ApiInfo;
use App\Traits\v1\Auditable;
use App\Utility\FileManagerRepo;
use App\Utility\PythonDocumentSync;
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

    public function index()
    {
        if (!Auth::check()) {
            return $this->errorResponse('unauthenticated', 401);
        }

        $user = Auth::user();

        if ($user->hasAnyRole(['admin', 'developer'])) {
            $documents = Document::withTrashed()
                ->with(['roles', 'departments', 'permissions'])
                ->get();

            if ($documents->isEmpty()) {
                return $this->errorResponse('document-notFound', 404);
            }

            return $this->successResponse(DocumentResource::collection($documents), 200);
        }

        // public / staff: فقط اسناد قابل‌دسترسی
        $documents = $this->accessibleDocumentsQuery($user)
            ->with(['roles', 'departments', 'permissions'])
            ->get();

        if ($documents->isEmpty()) {
            return $this->errorResponse('document-notFound', 404);
        }

        return $this->successResponse(DocumentResource::collection($documents), 200);
    }

    public function uploadDoc(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file_name_show' => 'required',
            'file'           => 'required|file',
            'status'         => 'nullable|in:draft,published,archived',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors(), 403);
        }

        $originalName = $request->file->getClientOriginalName();
        $docUuid = (string) Str::uuid();
        $title = $docUuid . '_' . $originalName;
        $fileName = Carbon::now()->microsecond . '_' . $request->file->getClientOriginalName();
        $filePath = 'documents/' . $title . '/files';

        if (!$request->file->storeAs($filePath, $fileName, 'public')) {
            return $this->errorResponse('upload-failed', 500);
        }

        $document = new Document();
        $document->file_name_show = $request->file_name_show;
        $document->file_name = $fileName;
        $document->path = $filePath . '/' . $fileName;
        $document->extension = $request->file->getClientOriginalExtension();
        $document->uploader_id = Auth::id();
        $document->doc_uuid = $docUuid;
        $document->status = $request->status ?? 'draft';
        $document->version = 1;

        if (!$document->save()) {
            return $this->errorResponse('save-failed', 500);
        }

        $this->audit('document.upload', 'document', $document->id, [
            'file_name' => $document->file_name,
            'doc_uuid'  => $document->doc_uuid,
            'status'    => $document->status,
        ]);

        $document->load(['roles', 'departments', 'permissions']);

        return $this->successResponse(new DocumentResource($document), 201, 'document-successfully-saved');
    }

    public function getBase64($doc_id)
    {
        if (!$this->checkExistsDocumentById($doc_id)) {
            return $this->errorResponse('doc-notFound', 404);
        }

        $user = Auth::user();
        if (!$user->hasAnyRole(['admin', 'developer'])) {
            return $this->errorResponse('forbidden', 403);
        }

        $path = Document::where('id', $doc_id)->first()->path;
        $fileManager = new FileManagerRepo();
        if ($path != null) {
            return $fileManager->getFileContentAsBase64($path, $disk = 'public');
        }

        return $this->errorResponse('doc-notDownloaded', 500);
    }

    public function get($doc_id)
    {
        if (!$this->checkExistsDocumentById($doc_id)) {
            return $this->errorResponse('doc-notFound', 404);
        }

        $user = Auth::user();
        if (!$user->hasAnyRole(['admin', 'developer'])) {
            return $this->errorResponse('forbidden', 403);
        }

        $path = Document::where('id', $doc_id)->first()->path;
        $fileManager = new FileManagerRepo();
        if ($path != null) {
            return $fileManager->download($path);
        }

        return $this->errorResponse('doc-notDownloaded', 500);
    }

    public function search(Request $request)
    {
        if ($request->hasHeader('accept') && $request->header('accept') == 'application/json' && $request->ajax()) {
            $validator = Validator::make($request->all(), [
                'extension'      => 'nullable',
                'file_name'      => 'nullable',
                'file_name_show' => 'nullable',
                'doc_uuid'       => 'nullable',
                'status'         => 'nullable',
                'version'        => 'nullable',
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => 'validation-error', 'errors' => $validator->errors()]);
            }

            $query = Document::with('roles', 'permissions', 'departments')->select('*');
            if ($request->extension != null) {
                $query->where('extension', 'like', '%' . $request->extension . '%');
            }
            if ($request->file_name != null) {
                $query->where('file_name', 'like', '%' . $request->file_name . '%');
            }
            if ($request->file_name_show != null) {
                $query->where('file_name_show', 'like', '%' . $request->file_name_show . '%');
            }
            if ($request->doc_uuid != null) {
                $query->where('doc_uuid', 'like', '%' . $request->doc_uuid . '%');
            }
            if ($request->status != null) {
                $query->where('status', 'like', '%' . $request->status . '%');
            }
            if ($request->version != null) {
                $query->where('version', 'like', '%' . $request->version . '%');
            }

            return $query->exists()
                ? $this->successResponse(DocumentResource::collection($query->get()), 200, 'file-found')
                : $this->errorResponse('file-notFound', 404);
        }

        return $this->errorResponse('refused', 500);
    }

    public function destroy($doc_id)
    {
        if (!$this->checkExistsDocumentById($doc_id)) {
            return $this->errorResponse('doc-notFound', 404);
        }

        $document = Document::where('id', $doc_id)->first();
        if (!$document) {
            return $this->errorResponse('doc-notFound', 404);
        }

        $token = request()->bearerToken();
        $sync = new PythonDocumentSync();
        $qdrant = $sync->deleteFromQdrant((string) $document->doc_uuid, $token);

        if (!$qdrant['ok']) {
            $this->audit('document.destroy', 'document', $document->id, [
                'doc_uuid'      => $document->doc_uuid,
                'status'        => 'failed',
                'reason'        => 'qdrant-delete-failed',
                'python_status' => $qdrant['status'] ?? null,
                'error'         => $qdrant['error'] ?? null,
            ]);
            return $this->errorResponse('qdrant-delete-failed', 500, $qdrant);
        }

        $file_path = $document->path;
        if ($file_path && !Storage::disk('public')->delete($file_path)) {
            $this->audit('document.destroy', 'document', $document->id, [
                'doc_uuid' => $document->doc_uuid,
                'status'   => 'partial',
                'reason'   => 'disk-delete-failed',
                'qdrant'   => ($qdrant['skipped'] ?? false) ? 'already-absent' : 'deleted',
            ]);
            return $this->errorResponse('remove-failed', 500);
        }

        if (!DB::table('documents')->where('id', $doc_id)->delete()) {
            return $this->errorResponse('path-delete-failed', 500);
        }

        $this->audit('document.destroy', 'document', $doc_id, [
            'doc_uuid' => $document->doc_uuid,
            'status'   => 'ok',
            'qdrant'   => ($qdrant['skipped'] ?? false) ? 'already-absent' : 'deleted',
        ]);

        return $this->successResponse('', 200, 'remove-success');
    }

    public function show(string $id)
    {
        if (!$this->checkExistsDocumentById($id)) {
            return $this->errorResponse('doc-notFound', 404);
        }

        $user = Auth::user();
        $doc = null;

        if ($user->hasAnyRole(['admin', 'developer'])) {
            $doc = Document::withTrashed()->with('uploader', 'roles', 'permissions', 'departments')
                ->where('id', $id)->first();
        } elseif ($user->hasRole('public')) {
            $doc = Document::where('id', $id)->whereNull('deleted_at')->first();
        }

        if (!$doc) {
            return $this->errorResponse('doc-notFound', 404);
        }

        if (!$user->canAccessDocument($doc)) {
            return $this->errorResponse('forbidden', 403);
        }

        return $this->successResponse(new DocumentResource($doc), 200);
    }

    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'file_name'      => 'nullable',
            'file_name_show' => 'nullable',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->messages(), 422);
        }

        if (!$this->checkExistsDocumentById($id)) {
            return $this->errorResponse('doc-notFound', 404);
        }

        $doc = Document::where('id', $id)->whereNull('deleted_at')->first();
        if (!$doc) {
            return $this->errorResponse('doc-notFound', 404);
        }

        if ($request->file_name) {
            $doc->file_name = $request->file_name;
        }
        if ($request->file_name_show) {
            $doc->file_name_show = $request->file_name_show;
        }

        if (!$doc->save()) {
            return $this->errorResponse('save-failed', 500);
        }

        $this->audit('document.update', 'document', $doc->id, [
            'doc_uuid' => $doc->doc_uuid,
            'status'   => $doc->status,
            'version'  => $doc->version,
        ]);

        return $this->successResponse(new DocumentResource($doc), 200, 'doc-successfully-updated');
    }

    public function publish($id)
    {
        return $this->changeStatus($id, 'published', 'document.publish');
    }

    public function archive($id)
    {
        return $this->changeStatus($id, 'archived', 'document.archive');
    }

    /**
     * Plan A:
     * - publish: bump version if already published, ingest with overwrite=true (same doc_uuid)
     * - archive: delete Qdrant chunks for doc_uuid
     */
    private function changeStatus($id, $status, $action)
    {
        if (!$this->checkExistsDocumentById($id)) {
            return $this->errorResponse('doc-notFound', 404);
        }

        $document = Document::where('id', $id)->whereNull('deleted_at')->first();
        if (!$document) {
            return $this->errorResponse('doc-notFound', 404);
        }

        if ($status === 'published') {
            $wasPublished = ($document->status === 'published');
            $document->version = $wasPublished
                ? ((int) ($document->version ?: 1) + 1)
                : max(1, (int) ($document->version ?: 1));
        }

        $document->status = $status;
        if (!$document->save()) {
            return $this->errorResponse('save-failed', 500);
        }

        if ($status === 'published' && $document->roles()->count() == 0) {
            $publicRoleId = DB::table('roles')->where('title_en', 'public')->value('id');
            if ($publicRoleId) {
                $document->roles()->sync([$publicRoleId]);
            }
        }

        $token = request()->bearerToken();
        $sync = new PythonDocumentSync();
        $syncResult = null;

        if ($status === 'published') {
            $syncResult = $sync->ingest($document, $token, true);
            if (!$syncResult['ok']) {
                $this->audit($action, 'document', $document->id, [
                    'doc_uuid' => $document->doc_uuid,
                    'status'   => $status,
                    'version'  => $document->version,
                    'sync'     => 'failed',
                    'error'    => $syncResult['error'] ?? null,
                    'python'   => $syncResult['status'] ?? null,
                ]);
                $document->load(['roles', 'departments', 'permissions']);
                return $this->successResponse(
                    new DocumentResource($document),
                    200,
                    'document-published-sync-failed'
                );
            }
        }

        if ($status === 'archived') {
            $syncResult = $sync->deleteFromQdrant((string) $document->doc_uuid, $token);
            if (!$syncResult['ok']) {
                $this->audit($action, 'document', $document->id, [
                    'doc_uuid' => $document->doc_uuid,
                    'status'   => $status,
                    'sync'     => 'failed',
                    'error'    => $syncResult['error'] ?? null,
                ]);
                $document->load(['roles', 'departments', 'permissions']);
                return $this->successResponse(
                    new DocumentResource($document),
                    200,
                    'document-archived-sync-failed'
                );
            }
        }

        $this->audit($action, 'document', $document->id, [
            'doc_uuid' => $document->doc_uuid,
            'status'   => $status,
            'version'  => $document->version,
            'sync'     => 'ok',
            'qdrant'   => ($syncResult['skipped'] ?? false) ? 'already-absent' : 'synced',
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
