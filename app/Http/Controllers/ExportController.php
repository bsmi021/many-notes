<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;
use Exception;
use Illuminate\Support\Str;

class ExportController extends Controller
{
    public function exportAll(Request $request): StreamedResponse|RedirectResponse
    {
        if (!Auth::check()) {
            return redirect()->route('login')->with('error', 'Please login to export your data.');
        }

        try {
            $files = Storage::disk('google')->listContents('/', true)->toArray();

            if (empty($files)) {
                return back()->with('info', 'You have no files to export.');
            }

            $zipFileName = 'export_all_' . date('YmdHis') . '_' . Str::random(8) . '.zip';

            // Using a temporary local path for zip creation
            $tempZipPath = storage_path('app/temp/' . $zipFileName);
            if (!file_exists(dirname($tempZipPath))) {
                mkdir(dirname($tempZipPath), 0755, true);
            }

            $zip = new ZipArchive();
            if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new Exception('Cannot create zip archive.');
            }

            foreach ($files as $file) {
                // Assuming $file is an object/array with 'path' and 'type' (file/dir)
                // And for files, 'path' is the full path to the file.
                // listContents might return FileAttributes objects from Flysystem v2/v3

                $path = $file['path']; // Adjust if the structure from listContents is different

                if ($file['type'] === 'dir') {
                    $zip->addEmptyDir($path);
                } elseif ($file['type'] === 'file') {
                    $content = Storage::disk('google')->get($path);
                    if ($content !== null) {
                        $zip->addFromString($path, $content);
                    } else {
                        // Optionally log this error or add an empty file
                        report(new Exception("Could not read content of file: {$path}"));
                        $zip->addFromString($path, ''); // Add empty file if content is null
                    }
                }
            }

            $zip->close();

            return response()->streamDownload(function () use ($tempZipPath) {
                readfile($tempZipPath);
                unlink($tempZipPath); // Delete the zip file after streaming
            }, $zipFileName);

        } catch (Exception $e) {
            report($e);
            return back()->with('error', 'Could not export files: ' . $e->getMessage());
        }
    }
}
