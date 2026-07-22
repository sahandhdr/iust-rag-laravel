<?php

namespace App\Utility;

use Illuminate\Support\Facades\Storage;

class FileManagerRepo
{
    public function insertFile($file,$path, $disk='public')
    {
        $originalFileName = $file->getClientOriginalName();                       // get image name with extension
        $imageName = pathinfo($originalFileName, PATHINFO_FILENAME);     // get only image name
        $extension = $file->getClientOriginalExtension();                      // get only image extension

        // check if image name is not english, select a random english name for it
        $imageName = strlen($imageName) != strlen(utf8_decode($imageName))
                                            ? substr(md5(rand()),0,10)
                                            : $imageName;

        $imageName = ($this->checkExistFileByFileName($path . "/" . $originalFileName, $disk))
                        ? $imageName . '_' . time() . '.' . $extension
                        : $imageName . '.' . $extension;

        // if insert was successful
        if($file->storeAs($path ,$imageName, $disk)) {

            return [
                "status"   => "ok",
                "path"     => $path,
                "filename" => $imageName,
                "extension" => $extension,
                "originalfilename" => $originalFileName
            ];
        }

        // if insert failed
        return [
            "status"   => "nok",
            "path"     => $path,
            "filename" => null,
            "originalfilename" => $originalFileName
        ];
    }

    public function checkExistFileByFileName($fileFullPath,$disk='public')
    {
        return Storage::disk($disk)->exists($fileFullPath);
    }

    // This Method Remove The File And Change The Pic_path Column To Null But Remove Just Remove The File
    public function deleteFileById($model_namespace, $record_id, $file_column_name, $remove_from_storage_flag=false, $disk="public")
    {
        if(!class_exists($model_namespace)) {
            return "model-notFound";
        }

        // get target record and make file-path column empty
        if($model_namespace::where('id', $record_id)->exists()) {
            $target = $model_namespace::where('id', $record_id)->first();

            // remove file from storage


            // check if model has given column
            if(!is_null($target->$file_column_name)) {
                $storagePath = $target->$file_column_name;
                $target->$file_column_name = null;
                if($remove_from_storage_flag)
                    $this->removeFileFromStorage($storagePath,$disk);

                if($target->save())
                    return "success";

                return "failed";

            }
        }

        //get class name
        $model = $this->getClassNameFromNamespace($model_namespace);
        return "$model-notFound";
    }

    public function removeFileFromStorage($path,$disk='public')
    {
        if(Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
            return "success";
        }

        return "notFound";
    }

    public function download($path)
    {
        if(!is_null($path)) {
            $path = public_path() . '/storage/' . $path;
//            $path = '/storage' . $path;

            return response()->download($path);
        }
        return "failed";
    }

    /////////////////
    public function getClassNameFromNamespace($namespace)
    {
        if(is_null($namespace)){
            return;
        }

        $path = explode('\\', $namespace);
        return strtolower(array_pop($path));
    }

    public function getFileContentAsBase64($file_path,$file_name="",$disk='public')
    {
        // check if file exists, then go to download process
        if(str_starts_with($file_path,'storage/'))
        {
            if(!Storage::disk($disk)->exists(substr($file_path,7)))
                return "file-notFound";
        }

//        $file_full_path = $file_path."/".$file_name;
        $file_full_path = $file_path;

        $file_raw_content = Storage::disk($disk)->get($file_full_path);
        $file_extension = pathinfo($file_full_path, PATHINFO_EXTENSION);
        $mime_type = mime_content_type(public_path('storage/' . $file_full_path));
        $file_content_base64_encoded = base64_encode($file_raw_content);

        return [
            "file_name"              => $file_name,
            "extension"              => $file_extension,
            "file_content"           => $file_content_base64_encoded,
            "file_ready_to_download" => 'data:' .$mime_type . ';base64,' . $file_content_base64_encoded,
        ];
    }
}
