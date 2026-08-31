<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


//======================================== authentication ========================================

Route::post('/v1/user/register', [\App\Http\Controllers\Api\v1\Auth\AuthController::class, 'register'])->middleware('throttle:login');
Route::post('/v1/user/login', [\App\Http\Controllers\Api\v1\Auth\AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/v1/user/logout', [\App\Http\Controllers\Api\v1\Auth\AuthController::class, 'logout']);
Route::post('/v1/user/change_password/{user_id}', [\App\Http\Controllers\Api\v1\Auth\AuthController::class, 'changeUserPassword']);
Route::match(['get', 'post'], '/v1/auth/verify-token', [\App\Http\Controllers\Api\v1\Auth\AuthController::class, 'verifyToken',])->middleware('auth:sanctum');
//Route::post('/v1/auth/verify-token', [\App\Http\Controllers\Api\v1\Auth\AuthController::class, 'verifyToken',])->middleware('auth:sanctum');

//======================================== users ========================================
//Route::get("/v1/users", [\App\Http\Controllers\Api\v1\User\UserController::class, "index"])->middleware('auth:sanctum')->middleware(['role:admin,developer,public']);
Route::get("/v1/users", [\App\Http\Controllers\Api\v1\User\UserController::class, "index"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::post("/v1/user/store", [\App\Http\Controllers\Api\v1\User\UserController::class, "store"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::post("/v1/upload/profile_pic", [\App\Http\Controllers\Api\v1\User\UserController::class, "uploadPic"])->middleware('auth:sanctum');
Route::get("/v1/user/show/{user_id}", [\App\Http\Controllers\Api\v1\User\UserController::class, "show"])->middleware('auth:sanctum');

Route::post("/v1/user/update/{user_id}", [\App\Http\Controllers\Api\v1\User\UserController::class, "update"])->middleware('auth:sanctum')->middleware(['role:admin,developer,public']);
Route::get("/v1/user/delete/{user_id}", [\App\Http\Controllers\Api\v1\User\UserController::class, "destroy"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::get("/v1/user/restore/{user_id}", [\App\Http\Controllers\Api\v1\User\UserController::class, "restore"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::post("/v1/user/search", [\App\Http\Controllers\Api\v1\User\UserController::class, "searchUser"])->middleware('auth:sanctum');
Route::get("/v1/user/remove_pic/{user_id}", [\App\Http\Controllers\Api\v1\User\UserController::class, "removeUserPicFromStorage"])->middleware('auth:sanctum')->middleware(['role:admin,developer,public']);
Route::post("/v1/user/change/pass/{user_id}", [\App\Http\Controllers\Api\v1\User\UserController::class, "changePassword"])->middleware('auth:sanctum');
Route::get('/v1/user/check_permission/{user_id}/{permission}',[\App\Http\Controllers\Api\v1\User\UserController::class, "checkPermissionExists"]);


/* ------------------------------| roles |------------------------------ */
Route::get("/v1/user/attach_role/{user_id}/{role_id}", [\App\Http\Controllers\Api\v1\User\UserRelationController::class, "attachRoleUserToUser"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::get("/v1/user/detach_role/{user_id}/{role_id}", [\App\Http\Controllers\Api\v1\User\UserRelationController::class, "detachRoleFromUser"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::post("/v1/user/sync_roles/{user_id}", [\App\Http\Controllers\Api\v1\User\UserRelationController::class, "syncRolesToUser"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);

/* ------------------------------| departments |------------------------------ */
Route::get("/v1/user/attach_department/{user_id}/{dept_id}", [\App\Http\Controllers\Api\v1\User\UserRelationController::class, "attachDepartmentUserToUser"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::get("/v1/user/detach_department/{user_id}/{dept_id}", [\App\Http\Controllers\Api\v1\User\UserRelationController::class, "detachDepartmentFromUser"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::post("/v1/user/sync_departments/{user_id}", [\App\Http\Controllers\Api\v1\User\UserRelationController::class, "syncDepartmentsToUser"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);


//======================================== role
Route::get("/v1/roles", [\App\Http\Controllers\Api\v1\Permission\RoleController::class, "index"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::post("/v1/role/store", [\App\Http\Controllers\Api\v1\Permission\RoleController::class, "store"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::get("/v1/role/show/{role_id}", [\App\Http\Controllers\Api\v1\Permission\RoleController::class, "show"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::post("/v1/role/update/{role_id}", [\App\Http\Controllers\Api\v1\Permission\RoleController::class, "update"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::get("/v1/role/delete/{role_id}", [\App\Http\Controllers\Api\v1\Permission\RoleController::class, "destroy"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::get("/v1/role/restore/{role_id}", [\App\Http\Controllers\Api\v1\Permission\RoleController::class, "restore"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);

/* ------------------------------| permissions |------------------------------ */
Route::get("/v1/role/attach_permission/{role_id}/{permission_id}", [\App\Http\Controllers\Api\v1\Permission\RoleRelationController::class, "attachPermissionToRole"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::get("/v1/role/detach_permission/{role_id}/{permission_id}", [\App\Http\Controllers\Api\v1\Permission\RoleRelationController::class, "detachPermissionFromRole"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::post("/v1/role/sync_permissions/{role_id}", [\App\Http\Controllers\Api\v1\Permission\RoleRelationController::class, "syncPermissionsToRole"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);

/* ------------------------------| positions |------------------------------ */
Route::get("/v1/role/attach_position/{role_id}/{position_id}", [\App\Http\Controllers\Api\v1\Permission\RoleRelationController::class, "attachPositionToRole"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::get("/v1/role/detach_position/{role_id}/{position_id}", [\App\Http\Controllers\Api\v1\Permission\RoleRelationController::class, "detachPositionFromRole"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::post("/v1/role/sync_positions/{role_id}", [\App\Http\Controllers\Api\v1\Permission\RoleRelationController::class, "syncPositionsToRole"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);

/* ------------------------------| users |------------------------------ */
Route::get("/v1/role/attach_user/{role_id}/{user_id}", [\App\Http\Controllers\Api\v1\Permission\RoleRelationController::class, "attachUserToRole"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::get("/v1/role/detach_user/{role_id}/{user_id}", [\App\Http\Controllers\Api\v1\Permission\RoleRelationController::class, "detachUserFromRole"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::post("/v1/role/sync_users/{role_id}", [\App\Http\Controllers\Api\v1\Permission\RoleRelationController::class, "syncUsersToRole"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);

/* ------------------------------| documents |------------------------------ */
Route::get("/v1/role/attach_doc/{role_id}/{doc_id}", [\App\Http\Controllers\Api\v1\Permission\RoleRelationController::class, "attachDocumentToRole"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::get("/v1/role/detach_doc/{role_id}/{doc_id}", [\App\Http\Controllers\Api\v1\Permission\RoleRelationController::class, "detachDocumentFromRole"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::post("/v1/role/sync_docs/{role_id}", [\App\Http\Controllers\Api\v1\Permission\RoleRelationController::class, "syncDocumentsToRole"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);


//======================================== permission ========================================
//======================================== permission
Route::get("/v1/permissions", [\App\Http\Controllers\Api\v1\Permission\PermissionController::class, "index"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::post("/v1/permission/store", [\App\Http\Controllers\Api\v1\Permission\PermissionController::class, "store"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::get("/v1/permission/show/{permission_id}", [\App\Http\Controllers\Api\v1\Permission\PermissionController::class, "show"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::post("/v1/permission/update/{permission_id}", [\App\Http\Controllers\Api\v1\Permission\PermissionController::class, "update"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::get("/v1/permission/delete/{permission_id}", [\App\Http\Controllers\Api\v1\Permission\PermissionController::class, "destroy"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::get("/v1/permission/restore/{permission_id}", [\App\Http\Controllers\Api\v1\Permission\PermissionController::class, "restore"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);

/* ------------------------------| roles |------------------------------ */
Route::get("/v1/permission/attach_role/{permission_id}/{role_id}", [\App\Http\Controllers\Api\v1\Permission\PermissionRelationController::class, "attachRoleToPermission"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::get("/v1/permission/detach_role/{permission_id}/{role_id}", [\App\Http\Controllers\Api\v1\Permission\PermissionRelationController::class, "detahcRoleFromPermission"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::post("/v1/permission/sync_roles/{permission_id}", [\App\Http\Controllers\Api\v1\Permission\PermissionRelationController::class, "syncRolesToPermission"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);

/* ------------------------------| positions |------------------------------ */
Route::get("/v1/permission/attach_position/{permission_id}/{position_id}", [\App\Http\Controllers\Api\v1\Permission\PermissionRelationController::class, "attachPositionToPermission"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::get("/v1/permission/detach_position/{permission_id}/{position_id}", [\App\Http\Controllers\Api\v1\Permission\PermissionRelationController::class, "detachPositionFromPermission"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::post("/v1/permission/sync_positions/{permission_id}", [\App\Http\Controllers\Api\v1\Permission\PermissionRelationController::class, "syncPositionsToPermission"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);

/* ------------------------------| documents |------------------------------ */
Route::get("/v1/permission/attach_doc/{permission_id}/{doc_id}", [\App\Http\Controllers\Api\v1\Permission\PermissionRelationController::class, "attachDocumentToPermission"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::get("/v1/permission/detach_doc/{permission_id}/{doc_id}", [\App\Http\Controllers\Api\v1\Permission\PermissionRelationController::class, "detachDocumentFromPermission"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::post("/v1/permission/sync_docs/{permission_id}", [\App\Http\Controllers\Api\v1\Permission\PermissionRelationController::class, "syncDocumentToPermission"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);


//======================================== departments ========================================
Route::get("/v1/depts", [\App\Http\Controllers\Api\v1\Department\DepartmentController::class, "index"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::post("/v1/dept/store", [\App\Http\Controllers\Api\v1\Department\DepartmentController::class, "store"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::get("/v1/dept/show/{dept_id}", [\App\Http\Controllers\Api\v1\Department\DepartmentController::class, "show"])->middleware('auth:sanctum');
Route::get("/v1/dept/get_all", [\App\Http\Controllers\Api\v1\Department\DepartmentController::class, "getAllDepartments"])->middleware('auth:sanctum');
Route::post("/v1/dept/update/{dept_id}", [\App\Http\Controllers\Api\v1\Department\DepartmentController::class, "update"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::get("/v1/dept/delete/{dept_id}", [\App\Http\Controllers\Api\v1\Department\DepartmentController::class, "destroy"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::get("/v1/dept/restore/{dept_id}", [\App\Http\Controllers\Api\v1\Department\DepartmentController::class, "restore"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::post("/v1/dept/search", [\App\Http\Controllers\Api\v1\Department\DepartmentController::class, "search"])->middleware('auth:sanctum')->middleware(['role:admin,developer,public']);

/* ------------------------------| documents |------------------------------ */
Route::get("/v1/dept/attach_doc/{dept_id}/{doc_id}", [\App\Http\Controllers\Api\v1\Department\DepartmentRelationController::class, "attachDocumentToDepartment"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::get("/v1/dept/detach_doc/{dept_id}/{doc_id}", [\App\Http\Controllers\Api\v1\Department\DepartmentRelationController::class, "detachDocumentFromDepartment"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::post("/v1/dept/sync_docs/{dept_id}", [\App\Http\Controllers\Api\v1\Department\DepartmentRelationController::class, "syncDocumentsToDepartment"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);

/* ------------------------------| users |------------------------------ */
Route::get("/v1/dept/attach_user/{dept_id}/{user_id}", [\App\Http\Controllers\Api\v1\Department\DepartmentRelationController::class, "attachUserToDepartment"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::get("/v1/dept/detach_user/{dept_id}/{user_id}", [\App\Http\Controllers\Api\v1\Department\DepartmentRelationController::class, "detachUserFromDepartment"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::post("/v1/dept/sync_users/{dept_id}", [\App\Http\Controllers\Api\v1\Department\DepartmentRelationController::class, "syncUsersToDepartment"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);


//======================================== documents ========================================
Route::get("/v1/docs", [\App\Http\Controllers\Api\v1\Document\DocumentController::class, "index"])->middleware('auth:sanctum')->middleware(['role:admin,developer,public']);
Route::post("/v1/doc/upload", [\App\Http\Controllers\Api\v1\Document\DocumentController::class, "uploadDoc"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::get("/v1/doc/show/{doc_id}", [\App\Http\Controllers\Api\v1\Document\DocumentController::class, "show"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::post("/v1/doc/update/{doc_id}", [\App\Http\Controllers\Api\v1\Document\DocumentController::class, "update"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);

// حذف کامل: Qdrant → disk → MySQL  (متد destroy در DocumentController)
Route::get("/v1/doc/delete/{doc_id}", [\App\Http\Controllers\Api\v1\Document\DocumentController::class, "destroy"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::post("/v1/doc/search", [\App\Http\Controllers\Api\v1\Document\DocumentController::class, "search"])->middleware('auth:sanctum')->middleware(['role:admin,developer,public']);

Route::get("/v1/doc/get/{doc_id}", [\App\Http\Controllers\Api\v1\Document\DocumentController::class, "get"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::get("/v1/doc/get_base64/{doc_id}", [\App\Http\Controllers\Api\v1\Document\DocumentController::class, "getBase64"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);

// انتشار → MySQL published + ingest به Qdrant
Route::get("/v1/doc/publish/{doc_id}", [\App\Http\Controllers\Api\v1\Document\DocumentController::class, "publish"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);

// بایگانی → MySQL archived + حذف از Qdrant (فایل disk می‌ماند)
Route::get("/v1/doc/archive/{doc_id}", [\App\Http\Controllers\Api\v1\Document\DocumentController::class, "archive"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);


/* ------------------------------| roles |------------------------------ */
Route::get("/v1/doc/attach_role/{doc_id}/{role_id}", [\App\Http\Controllers\Api\v1\Document\DocumentRelationController::class, "attachRoleToDocument"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::get("/v1/doc/detach_role/{doc_id}/{role_id}", [\App\Http\Controllers\Api\v1\Document\DocumentRelationController::class, "detachRoleFromDocument"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::post("/v1/doc/sync_roles/{doc_id}", [\App\Http\Controllers\Api\v1\Document\DocumentRelationController::class, "syncRolesToDocument"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);

/* ------------------------------| departments |------------------------------ */
Route::get("/v1/doc/attach_department/{doc_id}/{dept_id}", [\App\Http\Controllers\Api\v1\Document\DocumentRelationController::class, "attachDepartmentToDocument"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::get("/v1/doc/detach_department/{doc_id}/{dept_id}", [\App\Http\Controllers\Api\v1\Document\DocumentRelationController::class, "detachDepartmentFromDocument"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::post("/v1/doc/sync_departments/{doc_id}", [\App\Http\Controllers\Api\v1\Document\DocumentRelationController::class, "syncDepartmentsToDocument"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);

/* ------------------------------| permissions |------------------------------ */
Route::get("/v1/doc/attach_permission/{doc_id}/{permission_id}", [\App\Http\Controllers\Api\v1\Document\DocumentRelationController::class, "attachPermissionToDocument"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::get("/v1/doc/detach_permission/{doc_id}/{permission_id}", [\App\Http\Controllers\Api\v1\Document\DocumentRelationController::class, "detachPermissionFromDocument"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::post("/v1/doc/sync_permissions/{doc_id}", [\App\Http\Controllers\Api\v1\Document\DocumentRelationController::class, "syncPermissionsToDocument"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);



//======================================== chat ========================================
//======================================== session
Route::get("/v1/chat/sessions", [\App\Http\Controllers\Api\v1\Chat\ChatSessionController::class, "index"])->middleware('auth:sanctum')->middleware(['role:admin,developer']);
Route::get("/v1/chat/user_sessions", [\App\Http\Controllers\Api\v1\Chat\ChatSessionController::class, "indexByUser"])->middleware('auth:sanctum');
Route::post("/v1/chat/session/store", [\App\Http\Controllers\Api\v1\Chat\ChatSessionController::class, "store"])->middleware('auth:sanctum');
Route::get("/v1/chat/session/show/{session_id}", [\App\Http\Controllers\Api\v1\Chat\ChatSessionController::class, "show"])->middleware('auth:sanctum');
Route::post("/v1/chat/session/update/{session_id}", [\App\Http\Controllers\Api\v1\Chat\ChatSessionController::class, "updateTitle"])->middleware('auth:sanctum');
Route::get("/v1/chat/session/delete/{session_id}", [\App\Http\Controllers\Api\v1\Chat\ChatSessionController::class, "destroy"])->middleware('auth:sanctum');
Route::post("/v1/chat/session/search", [\App\Http\Controllers\Api\v1\Chat\ChatSessionController::class, "search"])->middleware('auth:sanctum');


//======================================== message
Route::get("/v1/chat/message/{session_id}", [\App\Http\Controllers\Api\v1\Chat\ChatMessageController::class, "indexBySession"])->middleware('auth:sanctum');
Route::post("/v1/chat/message/store", [\App\Http\Controllers\Api\v1\Chat\ChatMessageController::class, "storeMessage"])->middleware('auth:sanctum');
Route::get("/v1/chat/message/show/{msg_id}", [\App\Http\Controllers\Api\v1\Chat\ChatMessageController::class, "show"])->middleware('auth:sanctum');
Route::post("/v1/chat/message/update/{msg_id}", [\App\Http\Controllers\Api\v1\Chat\ChatMessageController::class, "update"])->middleware('auth:sanctum');
Route::get("/v1/chat/message/delete/{msg_id}", [\App\Http\Controllers\Api\v1\Chat\ChatMessageController::class, "destroy"])->middleware('auth:sanctum');
Route::get("/v1/chat/message/feedback/{msg_id}/{feedback}", [\App\Http\Controllers\Api\v1\Chat\ChatMessageController::class, "setFeedbackOnMessage"])->middleware('auth:sanctum');
Route::post("/v1/chat/message/search", [\App\Http\Controllers\Api\v1\Chat\ChatMessageController::class, "search"])->middleware('auth:sanctum');


//======================================== message_files
Route::post("/v1/chat/message/upload/{message_id}", [\App\Http\Controllers\Api\v1\Chat\ChatMessageFileController::class, "uploadFile"])->middleware('auth:sanctum');
Route::get("/v1/chat/message/file/get/{file_id}", [\App\Http\Controllers\Api\v1\Chat\ChatMessageFileController::class, "get"])->middleware('auth:sanctum');
Route::get("/v1/chat/message/file/get_base_64/{file_id}", [\App\Http\Controllers\Api\v1\Chat\ChatMessageFileController::class, "getBase64"])->middleware('auth:sanctum');
Route::post("/v1/chat/message/file/search", [\App\Http\Controllers\Api\v1\Chat\ChatMessageFileController::class, "searchFile"])->middleware('auth:sanctum');


//======================================== rag ========================================
Route::post('/v1/rag/ask', [\App\Http\Controllers\Api\v1\Rag\RagController::class, 'ask'])->middleware('auth:sanctum')->middleware('throttle:rag');
Route::post('/v1/rag/ask-with-file', [\App\Http\Controllers\Api\v1\Rag\RagController::class, 'askWithFile'])->middleware('auth:sanctum')->middleware('throttle:rag');
Route::post('/v1/rag/ask-stream', [\App\Http\Controllers\Api\v1\Rag\RagController::class, 'askStream'])->middleware('auth:sanctum')->middleware('throttle:rag');
