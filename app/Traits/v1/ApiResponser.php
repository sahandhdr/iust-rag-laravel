<?php

namespace App\Traits\v1;

trait ApiResponser
{
    protected function successResponse($data, $code, $message=null)
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data
        ], $code);
    }

    protected function errorResponse($message, $code, $data=null)
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
            'data'=> $data
        ], $code);
    }
}
